<?php
/**
 * Integración con WooCommerce.
 *
 * @package Maxima_Integrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Maxima_WooCommerce_Integration {
	/**
	 * Cliente API.
	 *
	 * @var Maxima_External_API_Client
	 */
	private $api_client;

	/**
	 * Cache en memoria por request.
	 *
	 * @var array
	 */
	private $availability_cache = array();

	/**
	 * Constructor.
	 *
	 * @param Maxima_External_API_Client $api_client Cliente API.
	 */
	public function __construct( Maxima_External_API_Client $api_client ) {
		$this->api_client = $api_client;

		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_fields' ) );

		add_filter( 'woocommerce_product_get_price', array( $this, 'filter_product_price' ), 10, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( $this, 'filter_product_price' ), 10, 2 );
		add_filter( 'woocommerce_get_price', array( $this, 'filter_legacy_price' ), 10, 2 );
		add_filter( 'woocommerce_is_in_stock', array( $this, 'filter_is_in_stock' ), 10, 2 );
		add_filter( 'woocommerce_get_availability', array( $this, 'filter_availability' ), 10, 2 );
		add_filter( 'woocommerce_is_purchasable', array( $this, 'filter_is_purchasable' ), 10, 2 );

		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_items' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout' ), 10, 2 );
	}

	/**
	 * Renderiza campos en la edición de producto.
	 */
	public function render_product_fields() {
		global $post;

		$store_options = $this->get_active_store_options();

		echo '<div class="options_group show_if_simple">';

		woocommerce_wp_checkbox(
			array(
				'id'          => '_maxima_is_external',
				'label'       => __( 'Producto externo', 'maxima-integrations' ),
				'description' => __( 'Obtiene precio y stock desde una tienda externa.', 'maxima-integrations' ),
			)
		);

		woocommerce_wp_select(
			array(
				'id'          => '_maxima_external_store_id',
				'label'       => __( 'Tienda Externa', 'maxima-integrations' ),
				'options'     => $store_options,
				'value'       => get_post_meta( $post->ID, '_maxima_external_store_id', true ),
				'desc_tip'    => true,
				'description' => __( 'Selecciona la tienda externa activa.', 'maxima-integrations' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => '_maxima_external_product_id',
				'label'       => __( 'External Product ID', 'maxima-integrations' ),
				'value'       => get_post_meta( $post->ID, '_maxima_external_product_id', true ),
				'desc_tip'    => true,
				'description' => __( 'Identificador del producto en la tienda externa.', 'maxima-integrations' ),
			)
		);

		echo '</div>';
	}

	/**
	 * Guarda campos del producto.
	 *
	 * @param WC_Product $product Producto.
	 */
	public function save_product_fields( $product ) {
		$is_external = isset( $_POST['_maxima_is_external'] ) ? 'yes' : 'no';
		$store_id    = isset( $_POST['_maxima_external_store_id'] ) ? absint( wp_unslash( $_POST['_maxima_external_store_id'] ) ) : 0;
		$external_id = isset( $_POST['_maxima_external_product_id'] ) ? sanitize_text_field( wp_unslash( $_POST['_maxima_external_product_id'] ) ) : '';

		$product->update_meta_data( '_maxima_is_external', $is_external );
		$product->update_meta_data( '_maxima_external_store_id', $store_id );
		$product->update_meta_data( '_maxima_external_product_id', $external_id );
	}

	/**
	 * Obtiene opciones de tiendas activas.
	 *
	 * @return array
	 */
	private function get_active_store_options() {
		$options = array( '' => __( 'Seleccionar', 'maxima-integrations' ) );

		$stores = get_posts(
			array(
				'post_type'      => 'external_store',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => '_maxima_store_status',
						'value' => 'active',
					),
				),
			)
		);

		foreach ( $stores as $store ) {
			$options[ (string) $store->ID ] = $store->post_title;
		}

		return $options;
	}

	/**
	 * Filtra el precio de productos externos.
	 *
	 * @param string|float $price Precio.
	 * @param WC_Product   $product Producto.
	 * @return string|float
	 */
	public function filter_product_price( $price, $product ) {
		if ( ! $this->is_external_product( $product ) ) {
			return $price;
		}

		$availability = $this->get_external_availability( $product );
		if ( is_wp_error( $availability ) ) {
			return $price;
		}

		if ( null !== $availability['price'] ) {
			return $availability['price'];
		}

		return $price;
	}

	/**
	 * Compatibilidad con hooks legacy.
	 *
	 * @param string|float $price Precio.
	 * @param WC_Product   $product Producto.
	 * @return string|float
	 */
	public function filter_legacy_price( $price, $product ) {
		return $this->filter_product_price( $price, $product );
	}

	/**
	 * Filtra disponibilidad de stock.
	 *
	 * @param bool       $is_in_stock Estado.
	 * @param WC_Product $product Producto.
	 * @return bool
	 */
	public function filter_is_in_stock( $is_in_stock, $product ) {
		if ( ! $this->is_external_product( $product ) ) {
			return $is_in_stock;
		}

		$availability = $this->get_external_availability( $product );
		if ( is_wp_error( $availability ) ) {
			return false;
		}

		if ( null === $availability['stock'] ) {
			return $is_in_stock;
		}

		return $availability['stock'] > 0;
	}

	/**
	 * Filtra el texto de disponibilidad.
	 *
	 * @param array      $availability_data Datos de disponibilidad.
	 * @param WC_Product $product Producto.
	 * @return array
	 */
	public function filter_availability( $availability_data, $product ) {
		if ( ! $this->is_external_product( $product ) ) {
			return $availability_data;
		}

		$availability = $this->get_external_availability( $product );
		if ( is_wp_error( $availability ) ) {
			return array(
				'availability' => __( 'No disponible', 'maxima-integrations' ),
				'class'        => 'out-of-stock',
			);
		}

		if ( null === $availability['stock'] ) {
			return $availability_data;
		}

		if ( $availability['stock'] > 0 ) {
			return array(
				'availability' => __( 'En stock', 'maxima-integrations' ),
				'class'        => 'in-stock',
			);
		}

		return array(
			'availability' => __( 'Sin stock', 'maxima-integrations' ),
			'class'        => 'out-of-stock',
		);
	}

	/**
	 * Filtra si es comprable.
	 *
	 * @param bool       $is_purchasable Estado.
	 * @param WC_Product $product Producto.
	 * @return bool
	 */
	public function filter_is_purchasable( $is_purchasable, $product ) {
		if ( ! $this->is_external_product( $product ) ) {
			return $is_purchasable;
		}

		$availability = $this->get_external_availability( $product );
		if ( is_wp_error( $availability ) ) {
			return false;
		}

		if ( null !== $availability['stock'] && $availability['stock'] <= 0 ) {
			return false;
		}

		return $is_purchasable;
	}

	/**
	 * Validación en carrito.
	 */
	public function validate_cart_items() {
		$errors = $this->get_cart_validation_errors();
		foreach ( $errors as $message ) {
			wc_add_notice( $message, 'error' );
		}
	}

	/**
	 * Validación en checkout.
	 *
	 * @param array    $data Datos del checkout.
	 * @param WP_Error $errors Errores.
	 */
	public function validate_checkout( $data, $errors ) {
		$messages = $this->get_cart_validation_errors();
		foreach ( $messages as $message ) {
			$errors->add( 'maxima_external_stock', $message );
		}
	}

	/**
	 * Obtiene errores de validación por stock externo.
	 *
	 * @return array
	 */
	private function get_cart_validation_errors() {
		$messages = array();

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $messages;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
				continue;
			}

			$product = $cart_item['data'];
			if ( ! $this->is_external_product( $product ) ) {
				continue;
			}

			$availability = $this->get_external_availability( $product );
			if ( is_wp_error( $availability ) ) {
				$messages[] = sprintf(
					__( 'No pudimos validar el stock remoto para "%s". Intenta nuevamente.', 'maxima-integrations' ),
					$product->get_name()
				);
				continue;
			}

			if ( null !== $availability['stock'] && $availability['stock'] < $cart_item['quantity'] ) {
				$messages[] = sprintf(
					__( 'Stock insuficiente en la tienda externa para "%s".', 'maxima-integrations' ),
					$product->get_name()
				);
			}
		}

		return array_unique( $messages );
	}

	/**
	 * Determina si el producto es externo.
	 *
	 * @param WC_Product $product Producto.
	 * @return bool
	 */
	private function is_external_product( $product ) {
		if ( ! $product || ! $product instanceof WC_Product ) {
			return false;
		}

		if ( ! $product->is_type( 'simple' ) ) {
			return false;
		}

		return 'yes' === $product->get_meta( '_maxima_is_external' );
	}

	/**
	 * Obtiene disponibilidad externa con cache interno.
	 *
	 * @param WC_Product $product Producto.
	 * @return array|WP_Error
	 */
	private function get_external_availability( $product ) {
		$store_id    = (int) $product->get_meta( '_maxima_external_store_id' );
		$external_id = (string) $product->get_meta( '_maxima_external_product_id' );

		if ( ! $store_id || '' === $external_id ) {
			return new WP_Error( 'maxima_missing_external_data', __( 'Faltan datos de integración externa.', 'maxima-integrations' ) );
		}

		$cache_key = $store_id . '|' . $external_id;
		if ( isset( $this->availability_cache[ $cache_key ] ) ) {
			return $this->availability_cache[ $cache_key ];
		}

		$availability = $this->api_client->get_product_availability( $store_id, $external_id );
		$this->availability_cache[ $cache_key ] = $availability;

		return $availability;
	}
}
