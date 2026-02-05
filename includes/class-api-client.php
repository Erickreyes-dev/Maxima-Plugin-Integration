<?php
/**
 * Cliente API para tiendas externas.
 *
 * @package Maxima_Integrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Maxima_External_API_Client {
	/**
	 * TTL por defecto del cache.
	 *
	 * @var int
	 */
	private $cache_ttl = 120;

	/**
	 * Obtiene disponibilidad de un producto externo.
	 *
	 * @param int    $store_id ID de la tienda.
	 * @param string $external_product_id ID externo del producto.
	 * @return array|WP_Error|null
	 */
	public function get_product_availability( $store_id, $external_product_id ) {
		$endpoint = $this->get_endpoint( $store_id, 'product' );
		if ( is_wp_error( $endpoint ) ) {
			$endpoint = $this->get_endpoint( $store_id, 'stock' );
		}

		if ( is_wp_error( $endpoint ) ) {
			return $endpoint;
		}

		$url = str_replace(
			array( '{id}', '{sku}' ),
			array(
				rawurlencode( (string) $external_product_id ),
				rawurlencode( (string) $external_product_id ),
			),
			$endpoint
		);

		$response = $this->request( $store_id, 'GET', $url, array() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$stock = isset( $response['stock'] ) ? $response['stock'] : null;
		$price = isset( $response['price'] ) ? $response['price'] : null;

		if ( null === $stock && null === $price ) {
			return new WP_Error( 'maxima_invalid_response', __( 'Respuesta inválida desde la API.', 'maxima-integrations' ) );
		}

		return array(
			'stock' => null === $stock ? null : (int) $stock,
			'price' => null === $price ? null : (float) $price,
		);
	}

	/**
	 * Obtiene listado de productos externos.
	 *
	 * @param int   $store_id ID de la tienda.
	 * @param array $params Parámetros de consulta.
	 * @return array|WP_Error
	 */
	public function get_products( $store_id, $params = array() ) {
		$endpoint = $this->get_endpoint( $store_id, 'products' );
		if ( is_wp_error( $endpoint ) ) {
			return $endpoint;
		}

		return $this->request( $store_id, 'GET', $endpoint, $params );
	}

	/**
	 * Obtiene el endpoint configurado para una tienda.
	 *
	 * @param int    $store_id ID de la tienda.
	 * @param string $key Clave del endpoint.
	 * @return string|WP_Error
	 */
	protected function get_endpoint( $store_id, $key ) {
		$endpoints_raw = get_post_meta( $store_id, '_maxima_api_endpoints', true );
		if ( ! $endpoints_raw ) {
			return new WP_Error( 'maxima_missing_endpoints', __( 'La tienda no tiene endpoints configurados.', 'maxima-integrations' ) );
		}

		$endpoints = json_decode( $endpoints_raw, true );
		if ( null === $endpoints && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'maxima_invalid_endpoints_json', __( 'JSON de endpoints inválido.', 'maxima-integrations' ) );
		}

		if ( ! is_array( $endpoints ) || empty( $endpoints[ $key ] ) ) {
			return new WP_Error( 'maxima_missing_endpoint', __( 'Endpoint solicitado no configurado.', 'maxima-integrations' ) );
		}

		$endpoint = trim( (string) $endpoints[ $key ] );
		if ( '' === $endpoint ) {
			return new WP_Error( 'maxima_invalid_endpoint', __( 'Endpoint inválido.', 'maxima-integrations' ) );
		}

		$store_data = $this->get_store_data( $store_id );
		if ( is_wp_error( $store_data ) ) {
			return $store_data;
		}

		return trailingslashit( $store_data['api_base_url'] ) . ltrim( $endpoint, '/' );
	}

	/**
	 * Ejecuta una petición HTTP con cache transparente.
	 *
	 * @param int    $store_id ID de la tienda.
	 * @param string $method Método HTTP.
	 * @param string $url URL completa.
	 * @param array  $params Parámetros.
	 * @return array|WP_Error
	 */
	private function request( $store_id, $method, $url, $params ) {
		$cache_key = $this->get_cache_key( $store_id, $method, $url, $params );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$store_data = $this->get_store_data( $store_id );
		if ( is_wp_error( $store_data ) ) {
			return $store_data;
		}

		$headers = $this->build_headers( $store_data['auth_type'], $store_data['api_key'] );
		$args    = array(
			'timeout' => 15,
			'headers' => $headers,
		);

		$method = strtoupper( $method );
		if ( 'GET' === $method ) {
			if ( ! empty( $params ) ) {
				$url = add_query_arg( $params, $url );
			}
			$response = wp_remote_get( $url, $args );
		} else {
			$args['body'] = $params;
			$response    = wp_remote_post( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			return new WP_Error( 'maxima_http_error', __( 'Error HTTP en la API externa.', 'maxima-integrations' ), array( 'status' => $status_code ) );
		}

		$data = json_decode( $body, true );
		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'maxima_invalid_json', __( 'Respuesta JSON inválida.', 'maxima-integrations' ) );
		}

		set_transient( $cache_key, $data, $this->cache_ttl );

		return $data;
	}

	/**
	 * Obtiene datos de configuración de una tienda.
	 *
	 * @param int $store_id ID de la tienda.
	 * @return array|WP_Error
	 */
	private function get_store_data( $store_id ) {
		$api_base_url = get_post_meta( $store_id, '_maxima_api_base_url', true );
		$auth_type    = get_post_meta( $store_id, '_maxima_auth_type', true );
		$encrypted    = get_post_meta( $store_id, '_maxima_api_key', true );

		if ( ! $api_base_url ) {
			return new WP_Error( 'maxima_missing_base_url', __( 'La tienda no tiene URL base configurada.', 'maxima-integrations' ) );
		}

		$api_key = $encrypted ? Maxima_Integrations_Crypto::decrypt( $encrypted ) : '';

		return array(
			'api_base_url' => $api_base_url,
			'auth_type'    => $auth_type ? $auth_type : 'none',
			'api_key'      => $api_key,
		);
	}

	/**
	 * Construye los headers HTTP según el tipo de autenticación.
	 *
	 * @param string $auth_type Tipo de autenticación.
	 * @param string $api_key API Key desencriptada.
	 * @return array
	 */
	private function build_headers( $auth_type, $api_key ) {
		$headers = array(
			'Accept' => 'application/json',
		);

		switch ( $auth_type ) {
			case 'bearer':
				if ( $api_key ) {
					$headers['Authorization'] = 'Bearer ' . $api_key;
				}
				break;
			case 'basic':
				if ( $api_key ) {
					$headers['Authorization'] = 'Basic ' . base64_encode( $api_key . ':' );
				}
				break;
			case 'api_key':
				if ( $api_key ) {
					$headers['X-API-Key'] = $api_key;
				}
				break;
			case 'none':
			default:
				break;
		}

		return $headers;
	}

	/**
	 * Genera una cache key para transients.
	 *
	 * @param int    $store_id ID de la tienda.
	 * @param string $method Método HTTP.
	 * @param string $url URL completa.
	 * @param array  $params Parámetros.
	 * @return string
	 */
	private function get_cache_key( $store_id, $method, $url, $params ) {
		$payload = array(
			'store_id' => (int) $store_id,
			'method'   => strtoupper( $method ),
			'endpoint' => $url,
			'params'   => $params,
		);

		return 'maxima_ext_' . md5( wp_json_encode( $payload ) );
	}
}
