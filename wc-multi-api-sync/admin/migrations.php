<?php
/**
 * Migration utilities for external map table.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function wc_mas_run_external_map_migration( $dry_run = false ) {
    $db = WC_MAS_DB::get_instance();
    $logger = WC_MAS_Logger::get_instance();

    if ( ! $db->external_map_table_exists() ) {
        $logger->warning( 'External map table missing; migration skipped.' );
        return array(
            'processed' => 0,
            'migrated' => 0,
            'skipped' => 0,
        );
    }

    global $wpdb;
    $posts_table = $wpdb->posts;
    $meta_table = $wpdb->postmeta;

    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT p.ID, sku.meta_value AS sku
            FROM {$posts_table} p
            INNER JOIN {$meta_table} sku ON p.ID = sku.post_id AND sku.meta_key = '_sku'
            LEFT JOIN {$meta_table} ext ON p.ID = ext.post_id AND ext.meta_key = '_external_product_id'
            WHERE p.post_type = 'product'
              AND p.post_status IN ('publish','draft','pending','private')
              AND sku.meta_value LIKE %s
              AND (ext.meta_value IS NULL OR ext.meta_value = '')",
            'ext-%'
        ),
        ARRAY_A
    );

    $summary = array(
        'processed' => 0,
        'migrated' => 0,
        'skipped' => 0,
    );

    foreach ( $results as $row ) {
        $summary['processed']++;
        $sku = $row['sku'];
        if ( ! preg_match( '/^ext-(\d+)-(.+)$/', $sku, $matches ) ) {
            $summary['skipped']++;
            continue;
        }

        $provider_id = (int) $matches[1];
        $external_id = (string) $matches[2];
        $product_id = (int) $row['ID'];

        if ( $dry_run ) {
            $summary['migrated']++;
            continue;
        }

        update_post_meta( $product_id, '_external_provider_id', $provider_id );
        update_post_meta( $product_id, '_external_product_id', $external_id );

        $db->upsert_external_map( $provider_id, $external_id, $product_id );
        $summary['migrated']++;
    }

    $logger->info( 'External map migration completed.', null, $summary );

    return $summary;
}
