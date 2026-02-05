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
		add_action( 'add_meta_boxes', array( $this, 'register_external_store_metabox' ) );
		add_action( 'save_post_external_store', array( $this, 'save_external_store_meta' ) );
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

	/**
	 * Registra el metabox de configuración para tiendas externas.
	 */
	public function register_external_store_metabox() {
		add_meta_box(
			'maxima_external_store_config',
			__( 'Configuración de Integración', 'maxima-integrations' ),
			array( $this, 'render_external_store_metabox' ),
			'external_store',
			'normal',
			'high'
		);
	}

	/**
	 * Renderiza el metabox de configuración.
	 *
	 * @param WP_Post $post Post actual.
	 */
	public function render_external_store_metabox( $post ) {
		wp_nonce_field( 'maxima_external_store_meta', 'maxima_external_store_meta_nonce' );

		$store_status = get_post_meta( $post->ID, '_maxima_store_status', true );
		$api_base_url = get_post_meta( $post->ID, '_maxima_api_base_url', true );
		$auth_type    = get_post_meta( $post->ID, '_maxima_auth_type', true );
		$notes        = get_post_meta( $post->ID, '_maxima_notes', true );
		$encrypted    = get_post_meta( $post->ID, '_maxima_api_key', true );
		$api_key      = $encrypted ? Maxima_Integrations_Crypto::decrypt( $encrypted ) : '';

		$store_status = $store_status ? $store_status : 'inactive';
		$auth_type    = $auth_type ? $auth_type : 'none';
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="maxima_store_status"><?php esc_html_e( 'Estado', 'maxima-integrations' ); ?></label>
					</th>
					<td>
						<select name="maxima_store_status" id="maxima_store_status">
							<option value="active" <?php selected( $store_status, 'active' ); ?>><?php esc_html_e( 'Activo', 'maxima-integrations' ); ?></option>
							<option value="inactive" <?php selected( $store_status, 'inactive' ); ?>><?php esc_html_e( 'Inactivo', 'maxima-integrations' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="maxima_api_base_url"><?php esc_html_e( 'URL Base de API', 'maxima-integrations' ); ?></label>
					</th>
					<td>
						<input type="text" class="regular-text" name="maxima_api_base_url" id="maxima_api_base_url" value="<?php echo esc_attr( $api_base_url ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="maxima_api_key"><?php esc_html_e( 'API Key', 'maxima-integrations' ); ?></label>
					</th>
					<td>
						<input type="password" class="regular-text" name="maxima_api_key" id="maxima_api_key" value="<?php echo esc_attr( $api_key ); ?>" autocomplete="new-password" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="maxima_auth_type"><?php esc_html_e( 'Tipo de Autenticación', 'maxima-integrations' ); ?></label>
					</th>
					<td>
						<select name="maxima_auth_type" id="maxima_auth_type">
							<option value="none" <?php selected( $auth_type, 'none' ); ?>><?php esc_html_e( 'Ninguna', 'maxima-integrations' ); ?></option>
							<option value="bearer" <?php selected( $auth_type, 'bearer' ); ?>><?php esc_html_e( 'Bearer', 'maxima-integrations' ); ?></option>
							<option value="basic" <?php selected( $auth_type, 'basic' ); ?>><?php esc_html_e( 'Basic', 'maxima-integrations' ); ?></option>
							<option value="api_key" <?php selected( $auth_type, 'api_key' ); ?>><?php esc_html_e( 'API Key', 'maxima-integrations' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="maxima_notes"><?php esc_html_e( 'Notas', 'maxima-integrations' ); ?></label>
					</th>
					<td>
						<textarea name="maxima_notes" id="maxima_notes" rows="4" class="large-text"><?php echo esc_textarea( $notes ); ?></textarea>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Guarda los metadatos de configuración de la tienda externa.
	 *
	 * @param int $post_id ID del post.
	 */
	public function save_external_store_meta( $post_id ) {
		if ( ! isset( $_POST['maxima_external_store_meta_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST['maxima_external_store_meta_nonce'], 'maxima_external_store_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$store_status = isset( $_POST['maxima_store_status'] ) ? sanitize_text_field( wp_unslash( $_POST['maxima_store_status'] ) ) : 'inactive';
		$auth_type    = isset( $_POST['maxima_auth_type'] ) ? sanitize_text_field( wp_unslash( $_POST['maxima_auth_type'] ) ) : 'none';
		$api_base_url = isset( $_POST['maxima_api_base_url'] ) ? esc_url_raw( wp_unslash( $_POST['maxima_api_base_url'] ) ) : '';
		$notes        = isset( $_POST['maxima_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['maxima_notes'] ) ) : '';
		$api_key_raw  = isset( $_POST['maxima_api_key'] ) ? wp_unslash( $_POST['maxima_api_key'] ) : '';

		update_post_meta( $post_id, '_maxima_store_status', $store_status );
		update_post_meta( $post_id, '_maxima_api_base_url', $api_base_url );
		update_post_meta( $post_id, '_maxima_auth_type', $auth_type );
		update_post_meta( $post_id, '_maxima_notes', $notes );

		if ( '' !== $api_key_raw ) {
			$encrypted = Maxima_Integrations_Crypto::encrypt( sanitize_text_field( $api_key_raw ) );
			if ( $encrypted ) {
				update_post_meta( $post_id, '_maxima_api_key', $encrypted );
			}
		} else {
			delete_post_meta( $post_id, '_maxima_api_key' );
		}
	}
}

/**
 * Utilidades de encriptación para credenciales.
 */
final class Maxima_Integrations_Crypto {
	/**
	 * Cifra un valor usando AES-256-CBC.
	 *
	 * @param string $plaintext Valor a cifrar.
	 * @return string|null
	 */
	public static function encrypt( $plaintext ) {
		if ( '' === $plaintext ) {
			return null;
		}

		$key = self::get_encryption_key();
		if ( ! $key ) {
			return null;
		}

		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
		$iv        = random_bytes( $iv_length );
		$cipher    = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return null;
		}

		return base64_encode( $iv . $cipher );
	}

	/**
	 * Descifra un valor cifrado.
	 *
	 * @param string $ciphertext Valor cifrado.
	 * @return string|null
	 */
	public static function decrypt( $ciphertext ) {
		if ( '' === $ciphertext ) {
			return null;
		}

		$key = self::get_encryption_key();
		if ( ! $key ) {
			return null;
		}

		$decoded = base64_decode( $ciphertext, true );
		if ( false === $decoded ) {
			return null;
		}

		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
		$iv        = substr( $decoded, 0, $iv_length );
		$cipher    = substr( $decoded, $iv_length );
		$plain     = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		return false === $plain ? null : $plain;
	}

	/**
	 * Genera la clave de cifrado a partir de constantes de WordPress.
	 *
	 * @return string|null
	 */
	private static function get_encryption_key() {
		$source = AUTH_KEY;
		if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ) {
			$source .= SECURE_AUTH_KEY;
		}

		if ( ! $source ) {
			return null;
		}

		return hash( 'sha256', $source, true );
	}
}

/**
 * Cliente API para tiendas externas.
 */
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
		$endpoint = '/products/' . rawurlencode( (string) $external_product_id );
		$response = $this->request( $store_id, 'GET', $endpoint, array() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$stock = isset( $response['stock'] ) ? $response['stock'] : null;
		$price = isset( $response['price'] ) ? $response['price'] : null;

		if ( null === $stock && null === $price ) {
			return new WP_Error( 'maxima_invalid_response', __( 'Respuesta inválida desde la API.', 'maxima-integrations' ) );
		}

		return array(
			'stock' => $stock,
			'price' => $price,
		);
	}

	/**
	 * Ejecuta una petición HTTP con cache transparente.
	 *
	 * @param int    $store_id ID de la tienda.
	 * @param string $method Método HTTP.
	 * @param string $endpoint Endpoint.
	 * @param array  $params Parámetros.
	 * @return array|WP_Error
	 */
	private function request( $store_id, $method, $endpoint, $params ) {
		$cache_key = $this->get_cache_key( $store_id, $method, $endpoint, $params );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$store_data = $this->get_store_data( $store_id );
		if ( is_wp_error( $store_data ) ) {
			return $store_data;
		}

		$url = trailingslashit( $store_data['api_base_url'] ) . ltrim( $endpoint, '/' );
		$headers = $this->build_headers( $store_data['auth_type'], $store_data['api_key'] );
		$args = array(
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
			$response = wp_remote_post( $url, $args );
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
	 * @param string $endpoint Endpoint.
	 * @param array  $params Parámetros.
	 * @return string
	 */
	private function get_cache_key( $store_id, $method, $endpoint, $params ) {
		$payload = array(
			'store_id' => (int) $store_id,
			'method'   => strtoupper( $method ),
			'endpoint' => $endpoint,
			'params'   => $params,
		);

		return 'maxima_ext_' . md5( wp_json_encode( $payload ) );
	}
}

// Bootstrap del plugin.
Maxima_Integrations::get_instance();
