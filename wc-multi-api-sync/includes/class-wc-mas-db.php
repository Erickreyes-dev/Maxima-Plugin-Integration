<?php
/**
 * Database helper and table creation.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_MAS_DB {
    private static $instance;
    private $wpdb;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Create required custom tables.
     */
    public function create_tables() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $this->wpdb->get_charset_collate();
        $providers_table = $this->wpdb->prefix . 'wcmas_providers';
        $mappings_table  = $this->wpdb->prefix . 'wcmas_mappings';
        $logs_table      = $this->wpdb->prefix . 'wcmas_logs';

        $sql = "CREATE TABLE {$providers_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(200) NOT NULL,
            base_url TEXT NOT NULL,
            products_endpoint TEXT NOT NULL,
            notify_endpoint TEXT NOT NULL,
            auth_type VARCHAR(50) NOT NULL DEFAULT 'none',
            auth_config LONGTEXT NULL,
            headers LONGTEXT NULL,
            default_params LONGTEXT NULL,
            sync_frequency VARCHAR(50) NOT NULL DEFAULT 'hourly',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) {$charset_collate};

        CREATE TABLE {$mappings_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(200) NOT NULL,
            mapping_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY provider_id (provider_id)
        ) {$charset_collate};

        CREATE TABLE {$logs_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider_id BIGINT UNSIGNED NULL,
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            message TEXT NOT NULL,
            context_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY provider_id (provider_id)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    public function get_providers( $only_active = false ) {
        $table = $this->wpdb->prefix . 'wcmas_providers';
        $sql   = "SELECT * FROM {$table}";
        if ( $only_active ) {
            $sql .= " WHERE active = 1";
        }
        return $this->wpdb->get_results( $sql, ARRAY_A );
    }

    public function get_provider( $provider_id ) {
        $table = $this->wpdb->prefix . 'wcmas_providers';
        return $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $provider_id ), ARRAY_A );
    }

    public function upsert_provider( $data, $provider_id = null ) {
        $table = $this->wpdb->prefix . 'wcmas_providers';
        if ( $provider_id ) {
            $this->wpdb->update( $table, $data, array( 'id' => $provider_id ) );
            return $provider_id;
        }
        $this->wpdb->insert( $table, $data );
        return (int) $this->wpdb->insert_id;
    }

    public function delete_provider( $provider_id ) {
        $table = $this->wpdb->prefix . 'wcmas_providers';
        return $this->wpdb->delete( $table, array( 'id' => $provider_id ) );
    }

    public function get_mappings( $provider_id ) {
        $table = $this->wpdb->prefix . 'wcmas_mappings';
        return $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE provider_id = %d", $provider_id ), ARRAY_A );
    }

    public function get_mapping( $mapping_id ) {
        $table = $this->wpdb->prefix . 'wcmas_mappings';
        return $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $mapping_id ), ARRAY_A );
    }

    public function upsert_mapping( $data, $mapping_id = null ) {
        $table = $this->wpdb->prefix . 'wcmas_mappings';
        if ( $mapping_id ) {
            $this->wpdb->update( $table, $data, array( 'id' => $mapping_id ) );
            return $mapping_id;
        }
        $this->wpdb->insert( $table, $data );
        return (int) $this->wpdb->insert_id;
    }

    public function insert_log( $data ) {
        $table = $this->wpdb->prefix . 'wcmas_logs';
        $this->wpdb->insert( $table, $data );
    }

    public function get_logs( $provider_id = null, $level = null, $date = null ) {
        $table = $this->wpdb->prefix . 'wcmas_logs';
        $where = ' WHERE 1=1';
        $args  = array();
        if ( $provider_id ) {
            $where .= ' AND provider_id = %d';
            $args[] = $provider_id;
        }
        if ( $level ) {
            $where .= ' AND level = %s';
            $args[] = $level;
        }
        if ( $date ) {
            $where .= ' AND DATE(created_at) = %s';
            $args[] = $date;
        }
        $sql = "SELECT * FROM {$table}{$where} ORDER BY created_at DESC";
        if ( $args ) {
            $sql = $this->wpdb->prepare( $sql, $args );
        }
        return $this->wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * Encrypt sensitive data using wp_salt().
     */
    public function encrypt_secret( $value ) {
        $salt = wp_salt( 'auth' );
        $key  = hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY . $salt );
        $iv   = substr( hash( 'sha256', LOGGED_IN_KEY ), 0, 16 );
        return base64_encode( openssl_encrypt( $value, 'AES-256-CBC', $key, 0, $iv ) );
    }

    /**
     * Decrypt sensitive data.
     */
    public function decrypt_secret( $value ) {
        $salt = wp_salt( 'auth' );
        $key  = hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY . $salt );
        $iv   = substr( hash( 'sha256', LOGGED_IN_KEY ), 0, 16 );
        $decoded = base64_decode( $value );
        return openssl_decrypt( $decoded, 'AES-256-CBC', $key, 0, $iv );
    }
}
