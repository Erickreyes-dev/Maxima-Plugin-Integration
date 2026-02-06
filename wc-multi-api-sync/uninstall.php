<?php
/**
 * Uninstall cleanup.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$remove_tables = get_option( 'wc_mas_remove_tables', false );
if ( ! $remove_tables ) {
    return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcmas_providers" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcmas_mappings" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcmas_logs" );
