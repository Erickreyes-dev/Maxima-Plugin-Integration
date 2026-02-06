<?php
/**
 * Sync engine for providers.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_MAS_Sync {
    private static $instance;
    private $db;
    private $logger;

    const ACTION_SYNC = 'wc_mas_sync_provider';
    const ACTION_NOTIFY = 'wc_mas_notify_provider';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->db = WC_MAS_DB::get_instance();
        $this->logger = WC_MAS_Logger::get_instance();

        add_action( self::ACTION_SYNC, array( $this, 'handle_sync_job' ), 10, 2 );
        add_action( self::ACTION_NOTIFY, array( $this, 'handle_notify_job' ), 10, 3 );
    }

    /**
     * Register schedules per provider.
     */
    public function register_schedules() {
        if ( ! class_exists( 'ActionScheduler' ) ) {
            return;
        }
        $providers = $this->db->get_providers( true );
        foreach ( $providers as $provider ) {
            $this->schedule_provider_sync( $provider );
        }
    }

    public function schedule_provider_sync( $provider ) {
        if ( ! class_exists( 'ActionScheduler' ) ) {
            return;
        }
        $frequency = $provider['sync_frequency'] ?? 'hourly';
        if ( ! as_next_scheduled_action( self::ACTION_SYNC, array( $provider['id'], 1 ) ) ) {
            as_schedule_recurring_action( time() + 60, $this->frequency_to_seconds( $frequency ), self::ACTION_SYNC, array( $provider['id'], 1 ), 'wc-mas' );
        }
    }

    public function clear_schedules() {
        if ( ! class_exists( 'ActionScheduler' ) ) {
            return;
        }
        as_unschedule_all_actions( self::ACTION_SYNC );
        as_unschedule_all_actions( self::ACTION_NOTIFY );
    }

    private function frequency_to_seconds( $frequency ) {
        switch ( $frequency ) {
            case 'twice_daily':
                return 12 * HOUR_IN_SECONDS;
            case 'daily':
                return DAY_IN_SECONDS;
            default:
                return HOUR_IN_SECONDS;
        }
    }

    /**
     * Manual import trigger.
     */
    public function import_now( $provider_id, $mapping_id ) {
        if ( ! class_exists( 'ActionScheduler' ) ) {
            return;
        }
        as_enqueue_async_action( self::ACTION_SYNC, array( $provider_id, $mapping_id ), 'wc-mas' );
    }

    /**
     * Sync job handler.
     */
    public function handle_sync_job( $provider_id, $mapping_id ) {
        $provider = $this->db->get_provider( $provider_id );
        if ( ! $provider || ! $provider['active'] ) {
            return;
        }

        $mapping_row = $this->db->get_mapping( $mapping_id );
        if ( ! $mapping_row ) {
            $this->logger->log( 'error', 'Missing mapping for provider.', $provider_id );
            return;
        }

        $mapping = json_decode( $mapping_row['mapping_json'], true );
        $settings = get_option( 'wc_mas_settings', array() );
        $client = new WC_MAS_API_Client( $provider, $settings );
        $mapper = new WC_MAS_Mapper();

        $params = $provider['default_params'] ? json_decode( $provider['default_params'], true ) : array();
        $page = 1;
        $per_page = ! empty( $settings['batch_size'] ) ? (int) $settings['batch_size'] : 50;
        $total_processed = 0;

        do {
            $response = $client->paginate( $this->resolve_url( $provider['base_url'], $provider['products_endpoint'] ), $params, $page, $per_page );

            if ( is_wp_error( $response ) ) {
                $this->logger->log( 'error', $response->get_error_message(), $provider_id );
                return;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! is_array( $body ) ) {
                $this->logger->log( 'error', 'Invalid JSON response from provider.', $provider_id );
                return;
            }

            $products = $body['products'] ?? array();
            foreach ( $products as $payload ) {
                $mapped = $mapper->map_product( $payload, $mapping, $provider_id );
                $this->create_or_update_product_by_sku( $mapped, $payload, $provider_id );
                $total_processed++;
            }

            $total_pages = isset( $body['total'] ) ? ceil( (int) $body['total'] / $per_page ) : $page;
            $page++;
        } while ( $page <= $total_pages );

        $this->logger->log( 'info', 'Sync completed.', $provider_id, array( 'processed' => $total_processed ) );
    }

    private function resolve_url( $base_url, $endpoint ) {
        if ( filter_var( $endpoint, FILTER_VALIDATE_URL ) ) {
            return $endpoint;
        }
        return trailingslashit( $base_url ) . ltrim( $endpoint, '/' );
    }

    /**
     * Create or update WooCommerce product by SKU.
     */
    public function create_or_update_product_by_sku( $mapped, $payload, $provider_id ) {
        if ( empty( $mapped['sku'] ) ) {
            return;
        }

        $product_id = wc_get_product_id_by_sku( $mapped['sku'] );
        $has_variations = ! empty( $mapped['variations'] ) && is_array( $mapped['variations'] );
        $product = $product_id ? wc_get_product( $product_id ) : ( $has_variations ? new WC_Product_Variable() : new WC_Product() );

        $product->set_name( $mapped['title'] ?? '' );
        $product->set_short_description( $mapped['short_description'] ?? '' );
        $product->set_description( $mapped['description'] ?? '' );
        $product->set_sku( $mapped['sku'] );
        $product->set_regular_price( $mapped['regular_price'] ?? '' );
        $product->set_sale_price( $mapped['sale_price'] ?? '' );
        if ( isset( $mapped['stock'] ) ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( (int) $mapped['stock'] );
        }

        if ( isset( $mapped['weight'] ) ) {
            $product->set_weight( $mapped['weight'] );
        }

        if ( isset( $mapped['dimensions'] ) && is_array( $mapped['dimensions'] ) ) {
            $product->set_dimensions( $mapped['dimensions'] );
        }

        $product_id = $product->save();
        update_post_meta( $product_id, '_wcmas_provider_id', $provider_id );
        if ( isset( $payload['id'] ) ) {
            update_post_meta( $product_id, '_wcmas_external_id', sanitize_text_field( $payload['id'] ) );
        }

        if ( ! empty( $mapped['images'] ) && is_array( $mapped['images'] ) ) {
            $this->attach_images( $product_id, $mapped['images'] );
        }

        if ( ! empty( $mapped['categories'] ) && is_array( $mapped['categories'] ) ) {
            wp_set_object_terms( $product_id, $mapped['categories'], 'product_cat', false );
        }

        if ( ! empty( $mapped['attributes'] ) && is_array( $mapped['attributes'] ) ) {
            $this->set_product_attributes( $product_id, $mapped['attributes'] );
        }

        if ( $has_variations ) {
            $this->set_variable_product( $product_id, $mapped['variations'], $mapped['attributes'] ?? array() );
        }

        do_action( 'wc_mas_post_product_save', $product_id, $mapped, $payload, $provider_id );
    }

    private function attach_images( $product_id, $images ) {
        $attachment_ids = array();
        foreach ( $images as $image_url ) {
            $existing = $this->get_attachment_by_url( $image_url );
            if ( $existing ) {
                $attachment_ids[] = $existing;
                continue;
            }
            $attachment_id = $this->sideload_image( $image_url, $product_id );
            if ( $attachment_id ) {
                $attachment_ids[] = $attachment_id;
            }
        }

        if ( $attachment_ids ) {
            set_post_thumbnail( $product_id, $attachment_ids[0] );
            update_post_meta( $product_id, '_product_image_gallery', implode( ',', array_slice( $attachment_ids, 1 ) ) );
        }
    }

    private function get_attachment_by_url( $url ) {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wcmas_source_url' AND meta_value = %s LIMIT 1", $url ) );
    }

    private function sideload_image( $url, $product_id ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_sideload_image( $url, $product_id, null, 'id' );
        if ( is_wp_error( $attachment_id ) ) {
            return null;
        }
        update_post_meta( $attachment_id, '_wcmas_source_url', esc_url_raw( $url ) );
        return $attachment_id;
    }

    private function set_product_attributes( $product_id, $attributes ) {
        $product_attributes = array();
        foreach ( $attributes as $name => $options ) {
            $taxonomy = wc_sanitize_taxonomy_name( $name );
            $attribute_id = wc_attribute_taxonomy_id_by_name( $taxonomy );
            if ( ! $attribute_id ) {
                wc_create_attribute(
                    array(
                        'name' => $name,
                        'slug' => $taxonomy,
                        'type' => 'select',
                        'order_by' => 'menu_order',
                        'has_archives' => false,
                    )
                );
                $attribute_id = wc_attribute_taxonomy_id_by_name( $taxonomy );
            }

            $options = is_array( $options ) ? $options : array( $options );
            $terms = array();
            foreach ( $options as $option ) {
                if ( ! term_exists( $option, $taxonomy ) ) {
                    wp_insert_term( $option, $taxonomy );
                }
                $terms[] = $option;
            }

            wp_set_object_terms( $product_id, $terms, $taxonomy );
            $product_attributes[ $taxonomy ] = array(
                'name' => $taxonomy,
                'value' => implode( ' | ', $terms ),
                'position' => 0,
                'is_visible' => 1,
                'is_variation' => 1,
                'is_taxonomy' => 1,
            );
        }
        update_post_meta( $product_id, '_product_attributes', $product_attributes );
    }

    private function set_variable_product( $product_id, $variations, $attributes ) {
        wp_set_object_terms( $product_id, 'variable', 'product_type' );
        $parent = wc_get_product( $product_id );
        if ( ! $parent || ! $parent instanceof WC_Product_Variable ) {
            $parent = new WC_Product_Variable( $product_id );
        }

        foreach ( $variations as $variation_data ) {
            if ( empty( $variation_data['sku'] ) ) {
                continue;
            }
            $variation_id = wc_get_product_id_by_sku( $variation_data['sku'] );
            $variation = $variation_id ? new WC_Product_Variation( $variation_id ) : new WC_Product_Variation();
            $variation->set_parent_id( $product_id );
            $variation->set_sku( $variation_data['sku'] );
            if ( isset( $variation_data['price'] ) ) {
                $variation->set_regular_price( $variation_data['price'] );
            }
            if ( isset( $variation_data['stock'] ) ) {
                $variation->set_manage_stock( true );
                $variation->set_stock_quantity( (int) $variation_data['stock'] );
            }

            $attrs = array();
            if ( ! empty( $variation_data['attributes'] ) && is_array( $variation_data['attributes'] ) ) {
                foreach ( $variation_data['attributes'] as $name => $value ) {
                    $taxonomy = wc_sanitize_taxonomy_name( $name );
                    $attrs[ $taxonomy ] = $value;
                }
            }
            $variation->set_attributes( $attrs );
            $variation->save();
        }
    }

    /**
     * Notification job handler with retries.
     */
    public function handle_notify_job( $provider_id, $payload, $attempt ) {
        $provider = $this->db->get_provider( $provider_id );
        if ( ! $provider ) {
            return;
        }

        $settings = get_option( 'wc_mas_settings', array() );
        $client = new WC_MAS_API_Client( $provider, $settings );
        $payload = apply_filters( 'wc_mas_before_notify_provider', $payload, $provider_id );

        $response = $client->post( $provider['notify_endpoint'], $payload );
        if ( is_wp_error( $response ) ) {
            $this->schedule_notify_retry( $provider_id, $payload, $attempt, $response->get_error_message() );
            return;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code >= 400 ) {
            $this->schedule_notify_retry( $provider_id, $payload, $attempt, 'HTTP ' . $code );
            return;
        }

        $this->logger->log( 'info', 'Notification sent.', $provider_id, array( 'payload' => $payload ) );
    }

    private function schedule_notify_retry( $provider_id, $payload, $attempt, $error ) {
        $max_retries = 3;
        $this->logger->log( 'error', 'Notification failed: ' . $error, $provider_id, array( 'attempt' => $attempt ) );

        if ( $attempt >= $max_retries ) {
            return;
        }

        $delay = pow( 2, $attempt ) * MINUTE_IN_SECONDS;
        if ( class_exists( 'ActionScheduler' ) ) {
            as_schedule_single_action( time() + $delay, self::ACTION_NOTIFY, array( $provider_id, $payload, $attempt + 1 ), 'wc-mas' );
        }
    }
}
