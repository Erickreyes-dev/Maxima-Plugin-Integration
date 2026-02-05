<?php
/**
 * Plugin Name: Máxima – Integraciones de Tiendas
 * Description: Base del plugin para integrar tiendas externas con WooCommerce.
 * Version: 0.1.0
 * Author: Equipo Máxima
 * Text Domain: maxima-integrations
 *
 * @package Maxima_Integrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-maxima-integrations.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-external-store-admin.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-external-store-cpt.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-external-store-metabox.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-encryption.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-api-client.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-woocommerce-integration.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-product-importer.php';

Maxima_Integrations::get_instance();

add_action( 'admin_post_maxima_import_products', 'maxima_handle_import_products' );

/**
 * Maneja la importación de productos externos.
 */
function maxima_handle_import_products() {
	$plugin = Maxima_Integrations::get_instance();
	$importer = method_exists( $plugin, 'get_product_importer' ) ? $plugin->get_product_importer() : null;

	if ( $importer && method_exists( $importer, 'handle_import_request' ) ) {
		$importer->handle_import_request();
		return;
	}

	$store_id = isset( $_POST['store_id'] ) ? absint( wp_unslash( $_POST['store_id'] ) ) : 0;
	$user_id  = get_current_user_id();
	if ( $user_id ) {
		$notice = array(
			'type'   => 'error',
			'errors' => array( __( 'No se pudo inicializar el importador de productos.', 'maxima-integrations' ) ),
		);
		set_transient( 'maxima_import_notice_' . (int) $user_id, $notice, MINUTE_IN_SECONDS * 5 );
	}

	$location = add_query_arg( 'store_id', (int) $store_id, admin_url( 'admin.php?page=maxima_tiendas' ) );
	wp_redirect( $location );
	exit;
}
