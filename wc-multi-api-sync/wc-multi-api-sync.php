<?php
/**
 * Plugin Name: WC Multi API Sync
 * Description: Sync WooCommerce products with multiple external providers using API endpoints.
 * Version: 1.0.0
 * Author: OpenAI
 * Text Domain: wc-multi-api-sync
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 3.9
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WC_MAS_VERSION', '1.0.0' );
define( 'WC_MAS_PLUGIN_FILE', __FILE__ );
define( 'WC_MAS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_MAS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WC_MAS_PLUGIN_DIR . 'includes/class-wc-mas-db.php';
require_once WC_MAS_PLUGIN_DIR . 'includes/class-wc-mas-logger.php';
require_once WC_MAS_PLUGIN_DIR . 'includes/class-wc-mas-api-client.php';
require_once WC_MAS_PLUGIN_DIR . 'includes/class-wc-mas-json-resolver.php';
require_once WC_MAS_PLUGIN_DIR . 'includes/class-wc-mas-mapper.php';
require_once WC_MAS_PLUGIN_DIR . 'includes/class-wc-mas-mapping-storage.php';
require_once WC_MAS_PLUGIN_DIR . 'includes/class-wc-mas-media.php';
require_once WC_MAS_PLUGIN_DIR . 'includes/class-wc-mas-woo-adapter.php';
require_once WC_MAS_PLUGIN_DIR . 'includes/class-wc-mas-sync.php';
require_once WC_MAS_PLUGIN_DIR . 'includes/class-wc-mas-admin.php';
require_once WC_MAS_PLUGIN_DIR . 'includes/class-wc-mas-order-hooks.php';
require_once WC_MAS_PLUGIN_DIR . 'admin/migrations.php';

/**
 * Initialize plugin classes.
 */
function wc_mas_init_plugin() {
    load_plugin_textdomain( 'wc-multi-api-sync', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    $db = WC_MAS_DB::get_instance();
    $db->ensure_schema_updates();
    WC_MAS_Logger::get_instance();
    WC_MAS_Sync::get_instance();
    WC_MAS_Order_Hooks::get_instance();

    if ( is_admin() ) {
        WC_MAS_Admin::get_instance();
    }
}
add_action( 'plugins_loaded', 'wc_mas_init_plugin' );

/**
 * Activation hook: create tables and schedules.
 */
function wc_mas_activate() {
    WC_MAS_DB::get_instance()->create_tables();
    WC_MAS_Sync::get_instance()->register_schedules();
}
register_activation_hook( __FILE__, 'wc_mas_activate' );

/**
 * Deactivation hook: clear schedules.
 */
function wc_mas_deactivate() {
    WC_MAS_Sync::get_instance()->clear_schedules();
}
register_deactivation_hook( __FILE__, 'wc_mas_deactivate' );
