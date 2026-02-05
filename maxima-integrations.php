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
