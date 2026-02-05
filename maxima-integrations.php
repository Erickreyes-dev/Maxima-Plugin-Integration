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

/**
 * Clase principal del plugin.
 *
 * Responsable de registrar hooks e inicializar módulos futuros.
 */
final class Maxima_Integrations {
	/**
	 * Versión actual del plugin.
	 *
	 * @var string
	 */
	const VERSION = '0.1.0';

	/**
	 * Instancia única del plugin.
	 *
	 * @var Maxima_Integrations|null
	 */
	private static $instance = null;

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
		$this->init_hooks();
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
	 * Registra hooks de inicialización.
	 *
	 * Aquí se cargarán:
	 * - Integraciones con APIs externas.
	 * - Módulos de sincronización de productos.
	 * - Hooks de checkout y post-compra.
	 * - Cron jobs para tareas programadas.
	 */
	private function init_hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Carga las traducciones del plugin.
	 *
	 * Mantener aquí para centralizar futuras i18n.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'maxima-integrations',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}
}

// Bootstrap del plugin.
Maxima_Integrations::get_instance();
