<?php
/**
 * Logger for provider sync actions.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_MAS_Logger {
    private static $instance;
    private $db;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->db = WC_MAS_DB::get_instance();
    }

    public function log( $level, $message, $provider_id = null, $context = array() ) {
        $this->db->insert_log(
            array(
                'provider_id' => $provider_id,
                'level'       => sanitize_text_field( $level ),
                'message'     => wp_kses_post( $message ),
                'context_json'=> $context ? wp_json_encode( $context ) : null,
            )
        );
    }

    public function info( $message, $provider_id = null, $context = array() ) {
        $this->log( 'info', $message, $provider_id, $context );
    }

    public function warning( $message, $provider_id = null, $context = array() ) {
        $this->log( 'warning', $message, $provider_id, $context );
    }

    public function error( $message, $provider_id = null, $context = array() ) {
        $this->log( 'error', $message, $provider_id, $context );
    }

    public function debug( $message, $provider_id = null, $context = array() ) {
        $this->log( 'debug', $message, $provider_id, $context );
    }
}
