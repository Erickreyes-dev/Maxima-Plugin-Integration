<?php
/**
 * Mapping storage wrapper.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_MAS_Mapping_Storage {
    private $db;

    public function __construct() {
        $this->db = WC_MAS_DB::get_instance();
    }

    public function get_mappings( $provider_id ) {
        return $this->db->get_mappings( $provider_id );
    }

    public function get_mapping( $mapping_id ) {
        return $this->db->get_mapping( $mapping_id );
    }

    public function upsert_mapping( $data, $mapping_id = null ) {
        return $this->db->upsert_mapping( $data, $mapping_id );
    }

    public function delete_mapping( $mapping_id ) {
        return $this->db->delete_mapping( $mapping_id );
    }
}
