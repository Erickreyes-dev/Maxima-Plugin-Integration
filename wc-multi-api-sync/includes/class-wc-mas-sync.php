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
    private $resolver;
    private $woo_adapter;

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
        $this->resolver = new WC_MAS_JSON_Resolver();
        $this->woo_adapter = new WC_MAS_Woo_Adapter();

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

            $body = $this->resolver->decode_body( wp_remote_retrieve_body( $response ) );
            if ( ! is_array( $body ) ) {
                $this->logger->log( 'error', 'Invalid JSON response from provider.', $provider_id );
                return;
            }

            $products = $this->resolver->extract_products_array( $body );
            if ( ! $products ) {
                $this->logger->log( 'error', 'No product list found in provider response.', $provider_id );
                return;
            }
            foreach ( $products as $payload ) {
                $mapped = $mapper->map_product( $payload, $mapping, $provider_id );
                $this->woo_adapter->create_or_update_product_by_sku( $mapped, $payload, $provider_id );
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
