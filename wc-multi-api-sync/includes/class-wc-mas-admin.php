<?php
/**
 * Admin UI for providers, mappings, logs, settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_MAS_Admin {
    private static $instance;
    private $db;
    private $logger;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->db = WC_MAS_DB::get_instance();
        $this->logger = WC_MAS_Logger::get_instance();

        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_wc_mas_test_endpoint', array( $this, 'ajax_test_endpoint' ) );
        add_action( 'wp_ajax_wc_mas_preview_mapping', array( $this, 'ajax_preview_mapping' ) );
    }

    public function register_menu() {
        add_menu_page(
            __( 'WC Multi API Sync', 'wc-multi-api-sync' ),
            __( 'WC Multi API Sync', 'wc-multi-api-sync' ),
            'manage_woocommerce',
            'wc-mas-providers',
            array( $this, 'render_providers_page' ),
            'dashicons-update'
        );

        add_submenu_page(
            'wc-mas-providers',
            __( 'Mappings', 'wc-multi-api-sync' ),
            __( 'Mappings', 'wc-multi-api-sync' ),
            'manage_woocommerce',
            'wc-mas-mappings',
            array( $this, 'render_mappings_page' )
        );

        add_submenu_page(
            'wc-mas-providers',
            __( 'Logs', 'wc-multi-api-sync' ),
            __( 'Logs', 'wc-multi-api-sync' ),
            'manage_woocommerce',
            'wc-mas-logs',
            array( $this, 'render_logs_page' )
        );

        add_submenu_page(
            'wc-mas-providers',
            __( 'Settings', 'wc-multi-api-sync' ),
            __( 'Settings', 'wc-multi-api-sync' ),
            'manage_woocommerce',
            'wc-mas-settings',
            array( $this, 'render_settings_page' )
        );
    }

    public function enqueue_assets( $hook ) {
        if ( false === strpos( $hook, 'wc-mas' ) ) {
            return;
        }
        wp_enqueue_style( 'wc-mas-admin', WC_MAS_PLUGIN_URL . 'admin/css/admin.css', array(), WC_MAS_VERSION );
        wp_enqueue_script( 'wc-mas-mapping-ui', WC_MAS_PLUGIN_URL . 'admin/js/mapping-ui.js', array( 'jquery' ), WC_MAS_VERSION, true );
        wp_localize_script(
            'wc-mas-mapping-ui',
            'wcMasAdmin',
            array(
                'nonce' => wp_create_nonce( 'wc_mas_admin_nonce' ),
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            )
        );
    }

    public function render_providers_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $this->handle_provider_form();
        $providers = $this->db->get_providers();
        include WC_MAS_PLUGIN_DIR . 'templates/providers-page.php';
    }

    public function render_mappings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $this->handle_mapping_form();
        $provider_id = isset( $_GET['provider_id'] ) ? (int) $_GET['provider_id'] : 0;
        $providers = $this->db->get_providers();
        $mappings = $provider_id ? $this->db->get_mappings( $provider_id ) : array();
        include WC_MAS_PLUGIN_DIR . 'templates/mappings-page.php';
    }

    public function render_logs_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $provider_id = isset( $_GET['provider_id'] ) ? (int) $_GET['provider_id'] : null;
        $level = isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : null;
        $date = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : null;
        $providers = $this->db->get_providers();
        $logs = $this->db->get_logs( $provider_id, $level, $date );
        include WC_MAS_PLUGIN_DIR . 'templates/logs-page.php';
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        if ( isset( $_POST['wc_mas_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_mas_settings_nonce'] ) ), 'wc_mas_settings' ) ) {
            $settings = array(
                'timeout' => (int) $_POST['timeout'],
                'user_agent' => sanitize_text_field( wp_unslash( $_POST['user_agent'] ) ),
                'retries' => (int) $_POST['retries'],
                'batch_size' => (int) $_POST['batch_size'],
            );
            update_option( 'wc_mas_settings', $settings );
            $this->logger->log( 'info', 'Settings updated.' );
        }

        $settings = get_option( 'wc_mas_settings', array( 'timeout' => 20, 'user_agent' => 'WC-MAS/' . WC_MAS_VERSION, 'retries' => 2, 'batch_size' => 50 ) );
        include WC_MAS_PLUGIN_DIR . 'templates/settings-page.php';
    }

    private function handle_provider_form() {
        if ( ! isset( $_POST['wc_mas_provider_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_mas_provider_nonce'] ) ), 'wc_mas_provider' ) ) {
            return;
        }

        $auth_config = array(
            'api_key' => sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) ),
            'header_name' => sanitize_text_field( wp_unslash( $_POST['header_name'] ?? '' ) ),
            'username' => sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) ),
            'password' => sanitize_text_field( wp_unslash( $_POST['password'] ?? '' ) ),
        );

        $headers = $this->parse_kv_pairs( wp_unslash( $_POST['headers'] ?? '' ) );
        $params = $this->parse_kv_pairs( wp_unslash( $_POST['default_params'] ?? '' ) );

        $provider_id = isset( $_POST['provider_id'] ) ? (int) $_POST['provider_id'] : null;

        $data = array(
            'name' => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
            'base_url' => esc_url_raw( wp_unslash( $_POST['base_url'] ?? '' ) ),
            'products_endpoint' => sanitize_text_field( wp_unslash( $_POST['products_endpoint'] ?? '' ) ),
            'notify_endpoint' => esc_url_raw( wp_unslash( $_POST['notify_endpoint'] ?? '' ) ),
            'auth_type' => sanitize_text_field( wp_unslash( $_POST['auth_type'] ?? 'none' ) ),
            'auth_config' => wp_json_encode( $this->maybe_encrypt_auth( $auth_config ) ),
            'headers' => wp_json_encode( $headers ),
            'default_params' => wp_json_encode( $params ),
            'sync_frequency' => sanitize_text_field( wp_unslash( $_POST['sync_frequency'] ?? 'hourly' ) ),
            'active' => isset( $_POST['active'] ) ? 1 : 0,
        );

        $provider_id = $this->db->upsert_provider( $data, $provider_id );
        WC_MAS_Sync::get_instance()->schedule_provider_sync( $this->db->get_provider( $provider_id ) );
        $this->logger->log( 'info', 'Provider saved.', $provider_id );
    }

    private function handle_mapping_form() {
        if ( isset( $_POST['wc_mas_mapping_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_mas_mapping_nonce'] ) ), 'wc_mas_mapping' ) ) {
            $provider_id = (int) $_POST['provider_id'];
            $mapping = json_decode( wp_unslash( $_POST['mapping_json'] ), true );
            if ( ! is_array( $mapping ) ) {
                $mapping = array();
            }
            $data = array(
                'provider_id' => $provider_id,
                'name' => sanitize_text_field( wp_unslash( $_POST['mapping_name'] ) ),
                'mapping_json' => wp_json_encode( $mapping ),
            );
            $this->db->upsert_mapping( $data, isset( $_POST['mapping_id'] ) ? (int) $_POST['mapping_id'] : null );
            $this->logger->log( 'info', 'Mapping saved.', $provider_id );
        }

        if ( isset( $_POST['wc_mas_import_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_mas_import_nonce'] ) ), 'wc_mas_import' ) ) {
            $provider_id = (int) $_POST['provider_id'];
            $mapping_id = (int) $_POST['mapping_id'];
            WC_MAS_Sync::get_instance()->import_now( $provider_id, $mapping_id );
            $this->logger->log( 'info', 'Manual import queued.', $provider_id );
        }
    }

    public function ajax_test_endpoint() {
        check_ajax_referer( 'wc_mas_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'wc-multi-api-sync' ) ) );
        }

        $provider_id = (int) $_POST['provider_id'];
        $provider = $this->db->get_provider( $provider_id );
        if ( ! $provider ) {
            wp_send_json_error( array( 'message' => __( 'Provider not found.', 'wc-multi-api-sync' ) ) );
        }

        $client = new WC_MAS_API_Client( $provider, get_option( 'wc_mas_settings', array() ) );
        $params = $provider['default_params'] ? json_decode( $provider['default_params'], true ) : array();
        $url = $this->resolve_url( $provider['base_url'], $provider['products_endpoint'] );
        $response = $client->paginate( $url, $params, 1, 1 );
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => $response->get_error_message() ) );
        }

        $body = wp_remote_retrieve_body( $response );
        wp_send_json_success( array( 'body' => $body ) );
    }

    public function ajax_preview_mapping() {
        check_ajax_referer( 'wc_mas_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'wc-multi-api-sync' ) ) );
        }

        $payload = json_decode( wp_unslash( $_POST['payload'] ), true );
        $mapping = json_decode( wp_unslash( $_POST['mapping'] ), true );
        if ( ! $payload || ! $mapping ) {
            wp_send_json_error( array( 'message' => __( 'Invalid data.', 'wc-multi-api-sync' ) ) );
        }

        $mapper = new WC_MAS_Mapper();
        $result = $mapper->map_product( $payload, $mapping, 0 );
        wp_send_json_success( array( 'mapped' => $result ) );
    }

    private function parse_kv_pairs( $text ) {
        $lines = array_filter( array_map( 'trim', explode( "\n", $text ) ) );
        $pairs = array();
        foreach ( $lines as $line ) {
            if ( false === strpos( $line, ':' ) ) {
                continue;
            }
            list( $key, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
            $pairs[ $key ] = $value;
        }
        return $pairs;
    }

    private function maybe_encrypt_auth( $auth_config ) {
        $encrypted = $auth_config;
        $db = WC_MAS_DB::get_instance();
        foreach ( array( 'api_key', 'password' ) as $field ) {
            if ( ! empty( $encrypted[ $field ] ) ) {
                $encrypted[ $field ] = $db->encrypt_secret( $encrypted[ $field ] );
            }
        }
        return $encrypted;
    }

    private function resolve_url( $base_url, $endpoint ) {
        if ( filter_var( $endpoint, FILTER_VALIDATE_URL ) ) {
            return $endpoint;
        }
        return trailingslashit( $base_url ) . ltrim( $endpoint, '/' );
    }
}
