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
		add_action( 'init', array( $this, 'register_external_store_cpt' ) );
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

	/**
	 * Registra el Custom Post Type para Tiendas Externas.
	 *
	 * Este CPT sirve como contenedor de datos para integraciones por API.
	 * Se mantiene privado (solo admin) y preparado para metadatos.
	 */
	public function register_external_store_cpt() {
		$labels = array(
			'name'                  => __( 'Tiendas Externas', 'maxima-integrations' ),
			'singular_name'         => __( 'Tienda Externa', 'maxima-integrations' ),
			'menu_name'             => __( 'Tiendas Externas', 'maxima-integrations' ),
			'name_admin_bar'        => __( 'Tienda Externa', 'maxima-integrations' ),
			'add_new'               => __( 'Añadir nueva', 'maxima-integrations' ),
			'add_new_item'          => __( 'Añadir nueva tienda externa', 'maxima-integrations' ),
			'new_item'              => __( 'Nueva tienda externa', 'maxima-integrations' ),
			'edit_item'             => __( 'Editar tienda externa', 'maxima-integrations' ),
			'view_item'             => __( 'Ver tienda externa', 'maxima-integrations' ),
			'all_items'             => __( 'Todas las tiendas externas', 'maxima-integrations' ),
			'search_items'          => __( 'Buscar tiendas externas', 'maxima-integrations' ),
			'not_found'             => __( 'No se encontraron tiendas externas.', 'maxima-integrations' ),
			'not_found_in_trash'    => __( 'No hay tiendas externas en la papelera.', 'maxima-integrations' ),
			'archives'              => __( 'Archivo de tiendas externas', 'maxima-integrations' ),
			'attributes'            => __( 'Atributos de tienda externa', 'maxima-integrations' ),
			'insert_into_item'      => __( 'Insertar en tienda externa', 'maxima-integrations' ),
			'uploaded_to_this_item' => __( 'Subido a esta tienda externa', 'maxima-integrations' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'exclude_from_search'=> true,
			'publicly_queryable' => false,
			'has_archive'        => false,
			'rewrite'            => false,
			'query_var'          => false,
			'show_in_rest'       => false,
			'supports'           => array( 'title', 'editor' ),
			'capability_type'    => 'post',
		);

		register_post_type( 'external_store', $args );
	}
}

// Bootstrap del plugin.
Maxima_Integrations::get_instance();
