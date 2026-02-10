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
        $providers_table    = $this->wpdb->prefix . 'wcmas_providers';
        $mappings_table     = $this->wpdb->prefix . 'wcmas_mappings';
        $logs_table         = $this->wpdb->prefix . 'wcmas_logs';
        $external_map_table = $this->wpdb->prefix . 'wcmas_external_map';

        $sql = "CREATE TABLE {$providers_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(200) NOT NULL,
            base_url TEXT NOT NULL,
            products_endpoint TEXT NOT NULL,
            notify_endpoint TEXT NOT NULL,
            notify_status VARCHAR(50) NOT NULL DEFAULT 'completed',
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
        ) {$charset_collate};

        CREATE TABLE {$external_map_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider_id BIGINT UNSIGNED NOT NULL,
            external_id VARCHAR(191) NOT NULL,
            product_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY provider_external_unique (provider_id, external_id)
        ) {$charset_collate};";

        dbDelta( $sql );
        $this->ensure_schema_updates();
    }

    /**
     * Keep schema compatible for existing installs.
     */
    public function ensure_schema_updates() {
        $providers_table = $this->wpdb->prefix . 'wcmas_providers';
        $table_exists = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $providers_table ) );
        if ( $providers_table !== $table_exists ) {
            return;
        }

        $column = $this->wpdb->get_var( $this->wpdb->prepare( "SHOW COLUMNS FROM {$providers_table} LIKE %s", 'notify_status' ) );

        if ( ! $column ) {
            $this->wpdb->query( "ALTER TABLE {$providers_table} ADD COLUMN notify_status VARCHAR(50) NOT NULL DEFAULT 'completed' AFTER notify_endpoint" );
        }
    }

    public function external_map_table_exists() {
        $table = $this->wpdb->prefix . 'wcmas_external_map';
        $result = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        return $result === $table;
    }

    public function get_external_product_id( $provider_id, $external_id ) {
        if ( ! $this->external_map_table_exists() ) {
            return null;
        }
        $table = $this->wpdb->prefix . 'wcmas_external_map';
        return $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT product_id FROM {$table} WHERE provider_id = %d AND external_id = %s LIMIT 1",
                $provider_id,
                (string) $external_id
            )
        );
    }

    public function upsert_external_map( $provider_id, $external_id, $product_id ) {
        if ( ! $this->external_map_table_exists() ) {
            return array(
                'status' => 'skipped',
            );
        }

        $table = $this->wpdb->prefix . 'wcmas_external_map';
        $inserted = $this->wpdb->insert(
            $table,
            array(
                'provider_id' => $provider_id,
                'external_id' => (string) $external_id,
                'product_id' => $product_id,
            ),
            array( '%d', '%s', '%d' )
        );

        if ( false !== $inserted ) {
            return array(
                'status' => 'inserted',
            );
        }

        if ( $this->wpdb->last_error && false !== stripos( $this->wpdb->last_error, 'duplicate' ) ) {
            $existing_id = $this->get_external_product_id( $provider_id, $external_id );
            if ( $existing_id && (int) $existing_id !== (int) $product_id ) {
                $this->wpdb->update(
                    $table,
                    array( 'product_id' => $product_id ),
                    array(
                        'provider_id' => $provider_id,
                        'external_id' => (string) $external_id,
                    ),
                    array( '%d' ),
                    array( '%d', '%s' )
                );
            }

            return array(
                'status' => 'race',
                'existing_id' => $existing_id,
            );
        }

        return array(
            'status' => 'error',
            'error' => $this->wpdb->last_error,
        );
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

    public function delete_mapping( $mapping_id ) {
        $table = $this->wpdb->prefix . 'wcmas_mappings';
        return $this->wpdb->delete( $table, array( 'id' => $mapping_id ) );
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
