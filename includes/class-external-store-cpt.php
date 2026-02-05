<?php
/**
 * Registro del CPT de tiendas externas.
 *
 * @package Maxima_Integrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Maxima_External_Store_CPT {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Registra el Custom Post Type para Tiendas Externas.
	 */
	public function register() {
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
			'show_in_menu'       => false,
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
