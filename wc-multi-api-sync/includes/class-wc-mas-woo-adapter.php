<?php
/**
 * WooCommerce adapter for product creation/update.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_MAS_Woo_Adapter {
    private $logger;

    public function __construct() {
        $this->logger = WC_MAS_Logger::get_instance();
    }

    /**
     * Create or update WooCommerce product by SKU.
     */
    public function create_or_update_product_by_sku( $mapped, $payload, $provider_id ) {
        $context = array(
            'provider_id' => $provider_id,
            'sku' => $mapped['sku'] ?? null,
            'external_id' => $payload['id'] ?? null,
        );

        if ( empty( $mapped['sku'] ) ) {
            $this->logger->warning( 'Skipping product: SKU empty', $provider_id, $context );
            return array(
                'action' => 'skipped',
                'product_id' => null,
            );
        }

        if ( empty( $mapped['title'] ) ) {
            $this->logger->warning( 'Skipping product: title empty', $provider_id, $context );
            return array(
                'action' => 'skipped',
                'product_id' => null,
            );
        }

        $product_id = wc_get_product_id_by_sku( $mapped['sku'] );
        $has_variations = ! empty( $mapped['variations'] ) && is_array( $mapped['variations'] );
        $is_update = (bool) $product_id;
        $product = $product_id ? wc_get_product( $product_id ) : ( $has_variations ? new WC_Product_Variable() : new WC_Product_Simple() );
        if ( ! $product ) {
            $this->logger->error( 'Product load failed before save', $provider_id, $context );
            $this->logger->warning( 'Skipping product: unable to initialize product object', $provider_id, $context );
            return array(
                'action' => 'error',
                'product_id' => null,
            );
        }

        if ( isset( $mapped['title'] ) ) {
            $product->set_name( $mapped['title'] );
        }
        if ( isset( $mapped['short_description'] ) ) {
            $product->set_short_description( $mapped['short_description'] );
        }
        if ( isset( $mapped['description'] ) ) {
            $product->set_description( $mapped['description'] );
        }
        $product->set_sku( $mapped['sku'] );

        if ( isset( $mapped['regular_price'] ) ) {
            $product->set_regular_price( $mapped['regular_price'] );
        }
        if ( isset( $mapped['sale_price'] ) ) {
            $product->set_sale_price( $mapped['sale_price'] );
        }
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

        $product->set_status( 'publish' );
        $product->set_catalog_visibility( 'visible' );

        $product_id = $product->save();
        if ( ! $product_id ) {
            $this->logger->error( 'Product save returned empty ID', $provider_id, $context );
            $this->logger->warning( 'Skipping product: save did not return ID', $provider_id, $context );
            return array(
                'action' => 'error',
                'product_id' => null,
            );
        }

        $product = wc_get_product( $product_id );
        if ( $product ) {
            $this->logger->info(
                'Product status set',
                $provider_id,
                array(
                    'product_id' => $product_id,
                    'status' => $product->get_status(),
                )
            );
        }

        if ( ! $has_variations ) {
            $result = wp_set_object_terms( $product_id, 'simple', 'product_type' );
            if ( is_wp_error( $result ) ) {
                $this->logger->error( 'Failed to set product type', $provider_id, array_merge( $context, array( 'error' => $result->get_error_message() ) ) );
            }
        }

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
            $this->set_variable_product( $product_id, $mapped['variations'], $mapped['attributes'] ?? array(), $context );
        }

        do_action( 'wc_mas_post_product_save', $product_id, $mapped, $payload, $provider_id );

        return array(
            'action' => $is_update ? 'updated' : 'created',
            'product_id' => $product_id,
        );
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

    private function set_variable_product( $product_id, $variations, $attributes, $context = array() ) {
        $result = wp_set_object_terms( $product_id, 'variable', 'product_type' );
        if ( is_wp_error( $result ) ) {
            $this->logger->error( 'Failed to set product type', $context['provider_id'] ?? null, array_merge( $context, array( 'error' => $result->get_error_message() ) ) );
        }
        $parent = wc_get_product( $product_id );
        if ( ! $parent || ! $parent instanceof WC_Product_Variable ) {
            $parent = new WC_Product_Variable( $product_id );
        }

        foreach ( $variations as $variation_data ) {
            if ( empty( $variation_data['sku'] ) ) {
                $this->logger->warning( 'Skipping variation: SKU empty', $context['provider_id'] ?? null, $context );
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
}
