<?php
/**
 * Bootstrap del plugin.
 *
 * @package Maxima_Integrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Maxima_Integrations {
	/**
	 * Versión actual del plugin.
	 *
	 * @var string
	 */
	const VERSION = '0.1.0';

	/**
	 * Instancia única.
	 *
	 * @var Maxima_Integrations|null
	 */
	private static $instance = null;

	/**
	 * Manejador CPT.
	 *
	 * @var Maxima_External_Store_CPT
	 */
	private $external_store_cpt;

	/**
	 * Manejador de admin para tiendas.
	 *
	 * @var Maxima_External_Store_Admin
	 */
	private $external_store_admin;

	/**
	 * Manejador metabox.
	 *
	 * @var Maxima_External_Store_Metabox
	 */
	private $external_store_metabox;

	/**
	 * Cliente API.
	 *
	 * @var Maxima_External_API_Client
	 */
	private $api_client;

	/**
	 * Integración WooCommerce.
	 *
	 * @var Maxima_WooCommerce_Integration|null
	 */
	private $woocommerce_integration;

	/**
	 * Importador de productos externos.
	 *
	 * @var Maxima_Product_Importer|null
	 */
	private $product_importer;

	/**
	 * Obtiene la instancia única del plugin.
	 *
	 * @return Maxima_Integrations
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor privado para forzar patrón singleton.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		$this->external_store_admin   = new Maxima_External_Store_Admin();
		$this->external_store_cpt     = new Maxima_External_Store_CPT();
		$this->external_store_metabox = new Maxima_External_Store_Metabox();
		$this->api_client             = new Maxima_External_API_Client();

		if ( class_exists( 'WooCommerce' ) ) {
			$this->woocommerce_integration = new Maxima_WooCommerce_Integration( $this->api_client );
			$this->product_importer        = new Maxima_Product_Importer( $this->api_client );
		}
	}

	/**
	 * Evita el clonado de la instancia.
	 */
	private function __clone() {}

	/**
	 * Evita la deserialización de la instancia.
	 */
	public function __wakeup() {}

	/**
	 * Carga las traducciones del plugin.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'maxima-integrations',
			false,
			dirname( plugin_basename( dirname( __DIR__ ) . '/maxima-integrations.php' ) ) . '/languages'
		);
	}
}
