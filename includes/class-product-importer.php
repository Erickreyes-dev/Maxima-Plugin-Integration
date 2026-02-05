<?php
/**
 * Importador de productos externos.
 *
 * @package Maxima_Integrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Maxima_Product_Importer {
	/**
	 * Cliente API.
	 *
	 * @var Maxima_External_API_Client
	 */
	private $api_client;

	/**
	 * Constructor.
	 *
	 * @param Maxima_External_API_Client $api_client Cliente API.
	 */
	public function __construct( Maxima_External_API_Client $api_client ) {
		$this->api_client = $api_client;

		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
	}

	/**
	 * Maneja la importación server-side.
	 */
	public function handle_import_request() {
		$this->log_debug( 'Iniciando importación de productos.' );
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$this->log_debug( sprintf( 'URL llamada: %s', wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
		}

		$store_id = isset( $_POST['store_id'] ) ? absint( wp_unslash( $_POST['store_id'] ) ) : 0;

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->log_debug( 'Permiso denegado para importar productos.' );
			$this->store_notice(
				array(
					'errors'   => array( __( 'No autorizado.', 'maxima-integrations' ) ),
					'store_id' => $store_id,
				)
			);
			$this->redirect_back( $store_id );
		}

		if ( ! $this->verify_import_nonce( $store_id ) ) {
			$this->log_debug( 'Nonce inválido en la importación.' );
			$this->store_notice(
				array(
					'errors'   => array( __( 'Nonce inválido.', 'maxima-integrations' ) ),
					'store_id' => $store_id,
				)
			);
			$this->redirect_back( $store_id );
		}

		if ( ! $store_id ) {
			$this->log_debug( 'Store ID inválido en la importación.' );
			$this->store_notice(
				array(
					'errors'   => array( __( 'Tienda inválida.', 'maxima-integrations' ) ),
					'store_id' => $store_id,
				)
			);
			$this->redirect_back( $store_id );
		}

		$store_status = get_post_meta( $store_id, '_maxima_store_status', true );
		if ( 'active' !== $store_status ) {
			$this->log_debug( 'Tienda no activa en la importación.' );
			$this->store_notice(
				array(
					'errors'   => array( __( 'La tienda no está activa.', 'maxima-integrations' ) ),
					'store_id' => $store_id,
				)
			);
			$this->redirect_back( $store_id );
		}

		$this->log_debug( sprintf( 'Validando configuración de tienda %d.', $store_id ) );
		$config = $this->validate_store_config( $store_id );
		if ( is_wp_error( $config ) ) {
			$this->log_debug( $config->get_error_message() );
			$this->store_notice(
				array(
					'errors'   => array( $config->get_error_message() ),
					'store_id' => $store_id,
				)
			);
			$this->redirect_back( $store_id );
		}

		$this->log_debug( sprintf( 'Probando endpoint de productos para tienda %d.', $store_id ) );
		$response = $this->test_products_endpoint( $store_id, $config );
		if ( is_wp_error( $response ) ) {
			$this->log_debug( $response->get_error_message() );
			$this->store_notice(
				array(
					'errors'   => array( $response->get_error_message() ),
					'store_id' => $store_id,
				)
			);
			$this->redirect_back( $store_id );
		}

		$products = $this->normalize_products_response( $response );
		if ( is_wp_error( $products ) ) {
			$this->log_debug( $products->get_error_message() );
			$this->store_notice(
				array(
					'errors'   => array( $products->get_error_message() ),
					'store_id' => $store_id,
				)
			);
			$this->redirect_back( $store_id );
		}

		$products_count = is_array( $products ) ? count( $products ) : 0;
		$this->log_debug( sprintf( 'Cantidad de productos recibidos: %d', $products_count ) );

		if ( 0 === $products_count ) {
			$message = __( 'La API respondió, pero no se detectaron productos', 'maxima-integrations' );
			$this->log_debug( $message );
			$this->store_notice(
				array(
					'errors'              => array( $message ),
					'error_notice'        => $message,
					'debug_products_count' => $products_count,
					'store_id'            => $store_id,
				)
			);
			$this->redirect_back( $store_id );
		}

		$first_product = reset( $products );
		if ( is_array( $first_product ) && isset( $first_product['id'] ) ) {
			$this->log_debug( sprintf( 'ID externo del primer producto: %s', (string) $first_product['id'] ) );
		} else {
			$this->log_debug( 'No se pudo determinar el ID externo del primer producto.' );
		}

		$this->log_debug( sprintf( 'Importando %d productos para tienda %d.', $products_count, $store_id ) );
		$result = $this->import_products_list( $store_id, $products );
		if ( is_wp_error( $result ) ) {
			$this->log_debug( $result->get_error_message() );
			$this->store_notice(
				array(
					'errors'   => array( $result->get_error_message() ),
					'store_id' => $store_id,
				)
			);
			$this->redirect_back( $store_id );
		}

		$this->log_debug( sprintf( 'Importación completada para tienda %d. Resultado: %s', $store_id, wp_json_encode( $result ) ) );
		$result['debug_products_count'] = $products_count;
		$this->store_notice( $result );
		$this->redirect_back( $store_id );
	}

	/**
	 * Renderiza notices de importación.
	 */
	public function render_admin_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$allowed_screens = array( 'maxima_page_maxima_tiendas' );
		if ( ! $screen || ! in_array( $screen->id, $allowed_screens, true ) ) {
			return;
		}

		$notice = $this->get_stored_notice();
		if ( ! $notice ) {
			return;
		}

		$errors  = ! empty( $notice['errors'] ) ? (array) $notice['errors'] : array();
		$error_count = isset( $notice['errors_count'] ) ? (int) $notice['errors_count'] : count( $errors );
		$imported = isset( $notice['imported'] ) ? (int) $notice['imported'] : 0;
		$skipped  = isset( $notice['skipped'] ) ? (int) $notice['skipped'] : 0;
		$error_notice = isset( $notice['error_notice'] ) ? (string) $notice['error_notice'] : '';
		$debug_products_count = isset( $notice['debug_products_count'] ) ? (int) $notice['debug_products_count'] : null;

		if ( $error_notice ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html( $error_notice ); ?></p>
			</div>
			<?php
		} elseif ( 0 === $imported && 0 === $skipped && 0 === $error_count ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p><?php esc_html_e( 'No se importaron productos.', 'maxima-integrations' ); ?></p>
			</div>
			<?php
		} else {
			if ( $imported > 0 ) {
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( sprintf( __( '%d productos importados correctamente', 'maxima-integrations' ), $imported ) ); ?></p>
				</div>
				<?php
			}

			if ( $skipped > 0 ) {
				?>
				<div class="notice notice-warning is-dismissible">
					<p><?php echo esc_html( sprintf( __( '%d productos omitidos', 'maxima-integrations' ), $skipped ) ); ?></p>
				</div>
				<?php
			}

			if ( $error_count > 0 ) {
				?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'Ocurrieron errores durante la importación.', 'maxima-integrations' ); ?></p>
					<?php if ( $errors ) : ?>
						<ul>
							<?php foreach ( $errors as $error ) : ?>
								<li><?php echo esc_html( $error ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
				<?php
			}
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && null !== $debug_products_count ) {
			?>
			<div class="notice notice-info is-dismissible">
				<p><?php echo esc_html( sprintf( __( 'Productos detectados: %d', 'maxima-integrations' ), $debug_products_count ) ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Valida configuración de la tienda.
	 *
	 * @param int $store_id ID de la tienda.
	 * @return array|WP_Error
	 */
	private function validate_store_config( $store_id ) {
		$api_base_url = get_post_meta( $store_id, '_maxima_api_base_url', true );
		if ( ! $api_base_url ) {
			return new WP_Error( 'maxima_missing_base_url', __( 'La tienda no tiene URL base configurada.', 'maxima-integrations' ) );
		}

		$endpoints_raw = get_post_meta( $store_id, '_maxima_api_endpoints', true );
		if ( ! $endpoints_raw ) {
			return new WP_Error( 'maxima_missing_endpoints', __( 'La tienda no tiene endpoints configurados.', 'maxima-integrations' ) );
		}

		$endpoints = json_decode( $endpoints_raw, true );
		if ( null === $endpoints && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'maxima_invalid_endpoints_json', __( 'JSON de endpoints inválido.', 'maxima-integrations' ) );
		}

		if ( ! is_array( $endpoints ) || empty( $endpoints['products'] ) ) {
			return new WP_Error( 'maxima_missing_products_endpoint', __( 'El endpoint "products" no está configurado.', 'maxima-integrations' ) );
		}

		return array(
			'api_base_url' => $api_base_url,
			'products'     => (string) $endpoints['products'],
		);
	}

	/**
	 * Prueba la conexión a la API de productos.
	 *
	 * @param int   $store_id ID de la tienda.
	 * @param array $config Configuración validada.
	 * @return array|WP_Error
	 */
	private function test_products_endpoint( $store_id, $config ) {
		$endpoint_url = trailingslashit( $config['api_base_url'] ) . ltrim( $config['products'], '/' );
		$headers      = $this->api_client->get_request_headers( $store_id );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$response = wp_remote_get(
			$endpoint_url,
			array(
				'timeout' => 20,
				'headers' => $headers,
			)
		);

		$this->log_debug( sprintf( 'URL final llamada: %s', $endpoint_url ) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'maxima_api_unreachable',
				sprintf(
					__( 'No se pudo conectar con la API externa: %s', 'maxima-integrations' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$this->log_debug( sprintf( 'HTTP status code: %d', (int) $status_code ) );
		if ( $status_code < 200 || $status_code >= 300 ) {
			return new WP_Error(
				'maxima_api_http_error',
				sprintf(
					__( 'La API externa devolvió un error HTTP (%d).', 'maxima-integrations' ),
					(int) $status_code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$this->log_debug( sprintf( 'Raw body de la respuesta: %s', $body ) );
		if ( '' === $body ) {
			return new WP_Error( 'maxima_api_empty_response', __( 'La API externa devolvió una respuesta vacía.', 'maxima-integrations' ) );
		}

		$data = json_decode( $body, true );
		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'maxima_invalid_products_json', __( 'La respuesta de productos no es JSON válido.', 'maxima-integrations' ) );
		}

		return $data;
	}

	/**
	 * Normaliza la respuesta de productos.
	 *
	 * @param mixed $response Respuesta JSON ya decodificada.
	 * @return array|WP_Error
	 */
	private function normalize_products_response( $response ) {
		if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
			$response = $response['data'];
		} elseif ( isset( $response['items'] ) && is_array( $response['items'] ) ) {
			$response = $response['items'];
		} elseif ( isset( $response['products'] ) && is_array( $response['products'] ) ) {
			$response = $response['products'];
		}

		if ( ! is_array( $response ) ) {
			return new WP_Error( 'maxima_invalid_products', __( 'Respuesta inválida de productos.', 'maxima-integrations' ) );
		}

		return $response;
	}

	/**
	 * Importa un listado completo de productos.
	 *
	 * @param int   $store_id ID de la tienda.
	 * @param array $products Productos externos.
	 * @return array|WP_Error
	 */
	private function import_products_list( $store_id, $products ) {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 30 );
		}

		$products = apply_filters( 'maxima_external_products_list', $products, $store_id );
		if ( ! is_array( $products ) ) {
			return new WP_Error( 'maxima_invalid_products', __( 'Respuesta inválida de productos.', 'maxima-integrations' ) );
		}

		$result = array(
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => array(),
			'store_id' => (int) $store_id,
		);

		foreach ( $products as $product_data ) {
			if ( ! is_array( $product_data ) ) {
				$result['skipped']++;
				$result['errors'][] = __( 'Producto inválido en la respuesta.', 'maxima-integrations' );
				continue;
			}

			$import = $this->import_single_product( $store_id, $product_data );
			if ( is_wp_error( $import ) ) {
				$result['skipped']++;
				$result['errors'][] = $import->get_error_message();
				$this->log_debug( $import->get_error_message() );
				continue;
			}

			if ( 'created' === $import || 'updated' === $import ) {
				$result['imported']++;
			} else {
				$result['skipped']++;
			}
		}

		$result['errors_count'] = count( $result['errors'] );
		$result['timestamp']    = current_time( 'timestamp' );

		return $result;
	}

	/**
	 * Importa o actualiza un producto individual.
	 *
	 * @param int   $store_id ID de la tienda.
	 * @param array $product_data Datos del producto externo.
	 * @return string|WP_Error
	 */
	private function import_single_product( $store_id, $product_data ) {
		$mapping = apply_filters(
			'maxima_external_product_mapping',
			array(
				'id'                => 'id',
				'name'              => 'name',
				'description'       => 'description',
				'short_description' => 'short_description',
				'price'             => 'price',
				'image'             => 'image',
			),
			$store_id
		);

		$external_id = (string) $this->get_mapped_value( $product_data, $mapping['id'] );
		$name        = (string) $this->get_mapped_value( $product_data, $mapping['name'] );
		$description = (string) $this->get_mapped_value( $product_data, $mapping['description'] );
		$short_desc  = (string) $this->get_mapped_value( $product_data, $mapping['short_description'] );
		$image_url   = (string) $this->get_mapped_value( $product_data, $mapping['image'] );

		if ( '' === $external_id || '' === $name ) {
			return new WP_Error( 'maxima_missing_required_data', __( 'Faltan datos obligatorios del producto externo.', 'maxima-integrations' ) );
		}

		if ( '' === $short_desc && '' !== $description ) {
			$short_desc = wp_trim_words( wp_strip_all_tags( $description ), 30 );
		}

		$product_id = $this->find_existing_product_id( $store_id, $external_id );

		if ( $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return new WP_Error( 'maxima_invalid_product', __( 'No se pudo cargar el producto existente.', 'maxima-integrations' ) );
			}

			$product->set_name( $name );
			$product->set_slug( sanitize_title( $name ) );
			$product->set_description( $description );
			$product->set_short_description( $short_desc );
			$product->update_meta_data( '_maxima_is_external', true );
			$product->update_meta_data( '_maxima_external_id', $external_id );
			$product->update_meta_data( '_maxima_store_id', (int) $store_id );
			$product->update_meta_data( '_maxima_external_store_id', (int) $store_id );
			$product->update_meta_data( '_maxima_external_product_id', $external_id );
			$product->save();

			if ( $image_url ) {
				$this->maybe_set_product_image( $product_id, $image_url );
			}

			return 'updated';
		}

		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_slug( sanitize_title( $name ) ? sanitize_title( $name ) : sanitize_title( $external_id ) );
		$product->set_description( $description );
		$product->set_short_description( $short_desc );
		$product->set_status( 'publish' );
		$product->update_meta_data( '_maxima_is_external', true );
		$product->update_meta_data( '_maxima_external_id', $external_id );
		$product->update_meta_data( '_maxima_store_id', (int) $store_id );
		$product->update_meta_data( '_maxima_external_store_id', (int) $store_id );
		$product->update_meta_data( '_maxima_external_product_id', $external_id );

		$product_id = $product->save();
		if ( ! $product_id ) {
			return new WP_Error( 'maxima_product_save_failed', __( 'No se pudo crear el producto.', 'maxima-integrations' ) );
		}

		if ( $image_url ) {
			$this->maybe_set_product_image( $product_id, $image_url );
		}

		return 'created';
	}

	/**
	 * Almacena un notice en transient.
	 *
	 * @param array $data Datos del notice.
	 */
	private function store_notice( $data ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$defaults = array(
			'errors'               => array(),
			'imported'             => 0,
			'skipped'              => 0,
			'store_id'             => 0,
			'error_notice'         => '',
			'debug_products_count' => null,
			'timestamp'            => current_time( 'timestamp' ),
		);

		$notice = wp_parse_args( $data, $defaults );
		$notice['errors_count'] = isset( $notice['errors_count'] ) ? (int) $notice['errors_count'] : count( (array) $notice['errors'] );
		$store_id = isset( $notice['store_id'] ) ? (int) $notice['store_id'] : 0;
		set_transient( $this->get_notice_key( $user_id, $store_id ), $notice, MINUTE_IN_SECONDS * 5 );
	}

	/**
	 * Obtiene el notice almacenado.
	 *
	 * @return array|null
	 */
	private function get_stored_notice() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return null;
		}

		$store_id = isset( $_GET['store_id'] ) ? absint( wp_unslash( $_GET['store_id'] ) ) : 0;
		if ( ! $store_id ) {
			return null;
		}

		$key    = $this->get_notice_key( $user_id, $store_id );
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
			return $notice;
		}

		return null;
	}

	/**
	 * Genera la clave del transient del notice.
	 *
	 * @param int $user_id ID de usuario.
	 * @return string
	 */
	private function get_notice_key( $user_id, $store_id ) {
		return sprintf( 'maxima_import_notice_%d_%d', (int) $user_id, (int) $store_id );
	}

	/**
	 * Redirige de vuelta al formulario de edición.
	 *
	 * @param int $store_id ID de la tienda.
	 */
	private function redirect_back( $store_id ) {
		$location = add_query_arg( 'store_id', (int) $store_id, admin_url( 'admin.php?page=maxima_tiendas' ) );
		wp_redirect( $location );
		exit;
	}

	/**
	 * Registra errores si WP_DEBUG está activo.
	 *
	 * @param string $message Mensaje.
	 */
	private function log_debug( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Maxima Integrations: ' . $message );
		}
	}

	/**
	 * Valida el nonce de importación evitando wp_die silencioso.
	 *
	 * @param int $store_id ID de la tienda para la redirección.
	 * @return bool
	 */
	private function verify_import_nonce( $store_id ) {
		add_filter( 'wp_die_handler', array( $this, 'get_wp_die_handler' ) );
		$result = check_admin_referer( 'maxima_import_products_action', 'maxima_import_products_nonce' );
		remove_filter( 'wp_die_handler', array( $this, 'get_wp_die_handler' ) );

		if ( ! $result ) {
			$this->store_notice(
				array(
					'errors'   => array( __( 'Nonce inválido.', 'maxima-integrations' ) ),
					'store_id' => $store_id,
				)
			);
			$this->redirect_back( $store_id );
		}

		return (bool) $result;
	}

	/**
	 * Obtiene el handler para interceptar wp_die.
	 *
	 * @return callable
	 */
	public function get_wp_die_handler() {
		return array( $this, 'handle_wp_die' );
	}

	/**
	 * Intercepta wp_die para evitar pantallas silenciosas.
	 *
	 * @param string|WP_Error $message Mensaje recibido.
	 * @param string          $title   Título.
	 * @param array           $args    Argumentos.
	 */
	public function handle_wp_die( $message, $title = '', $args = array() ) {
		$store_id = isset( $_POST['store_id'] ) ? absint( wp_unslash( $_POST['store_id'] ) ) : 0;
		$error    = __( 'No se pudo validar la solicitud.', 'maxima-integrations' );
		if ( $message instanceof WP_Error ) {
			$error = $message->get_error_message();
		} elseif ( is_string( $message ) && '' !== $message ) {
			$error = wp_strip_all_tags( $message );
		}

		$this->log_debug( sprintf( 'Interceptado wp_die: %s', $error ) );
		$this->store_notice(
			array(
				'errors'   => array( $error ),
				'store_id' => $store_id,
			)
		);
		$this->redirect_back( $store_id );
	}

	/**
	 * Busca un producto existente por metadatos externos.
	 *
	 * @param int    $store_id ID de la tienda.
	 * @param string $external_id ID externo del producto.
	 * @return int
	 */
	private function find_existing_product_id( $store_id, $external_id ) {
		$posts = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'   => '_maxima_external_store_id',
						'value' => (int) $store_id,
					),
					array(
						'key'   => '_maxima_external_product_id',
						'value' => $external_id,
					),
				),
			)
		);

		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}

	/**
	 * Asigna una imagen destacada evitando duplicados.
	 *
	 * @param int    $product_id ID del producto.
	 * @param string $image_url URL de la imagen.
	 */
	private function maybe_set_product_image( $product_id, $image_url ) {
		if ( has_post_thumbnail( $product_id ) ) {
			return;
		}

		$attachment_id = $this->find_existing_attachment( $image_url );
		if ( $attachment_id ) {
			set_post_thumbnail( $product_id, $attachment_id );
			return;
		}

		$attachment_id = $this->download_image( $image_url, $product_id );
		if ( $attachment_id ) {
			set_post_thumbnail( $product_id, $attachment_id );
		}
	}

	/**
	 * Busca una imagen existente por URL externa.
	 *
	 * @param string $image_url URL remota.
	 * @return int
	 */
	private function find_existing_attachment( $image_url ) {
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'   => '_maxima_external_image_url',
						'value' => $image_url,
					),
				),
			)
		);

		return ! empty( $attachments ) ? (int) $attachments[0] : 0;
	}

	/**
	 * Descarga y registra una imagen remota.
	 *
	 * @param string $image_url URL remota.
	 * @param int    $product_id ID del producto.
	 * @return int
	 */
	private function download_image( $image_url, $product_id ) {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$tmp = download_url( $image_url, 15 );
		if ( is_wp_error( $tmp ) ) {
			error_log( 'Maxima Integrations: ' . $tmp->get_error_message() );
			return 0;
		}

		$file = array(
			'name'     => wp_basename( $image_url ),
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file, $product_id );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			error_log( 'Maxima Integrations: ' . $attachment_id->get_error_message() );
			return 0;
		}

		update_post_meta( $attachment_id, '_maxima_external_image_url', esc_url_raw( $image_url ) );

		return (int) $attachment_id;
	}

	/**
	 * Obtiene un valor mapeado de los datos externos.
	 *
	 * @param array              $product_data Datos externos.
	 * @param string|array|callable $mapping Mapeo.
	 * @return mixed|null
	 */
	private function get_mapped_value( $product_data, $mapping ) {
		if ( is_callable( $mapping ) ) {
			return call_user_func( $mapping, $product_data );
		}

		if ( is_array( $mapping ) ) {
			foreach ( $mapping as $path ) {
				$value = $this->get_value_by_path( $product_data, $path );
				if ( null !== $value ) {
					return $value;
				}
			}

			return null;
		}

		return $this->get_value_by_path( $product_data, $mapping );
	}

	/**
	 * Obtiene un valor usando notación de puntos.
	 *
	 * @param array  $data Datos.
	 * @param string $path Ruta.
	 * @return mixed|null
	 */
	private function get_value_by_path( $data, $path ) {
		if ( ! is_array( $data ) || ! is_string( $path ) || '' === $path ) {
			return null;
		}

		$segments = explode( '.', $path );
		foreach ( $segments as $segment ) {
			if ( ! is_array( $data ) || ! array_key_exists( $segment, $data ) ) {
				return null;
			}
			$data = $data[ $segment ];
		}

		return $data;
	}
}
