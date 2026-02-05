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
	 * Tamaño por lote.
	 *
	 * @var int
	 */
	private $batch_size = 20;

	/**
	 * Constructor.
	 *
	 * @param Maxima_External_API_Client $api_client Cliente API.
	 */
	public function __construct( Maxima_External_API_Client $api_client ) {
		$this->api_client = $api_client;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_maxima_import_products', array( $this, 'handle_ajax_import' ) );
	}

	/**
	 * Encola assets para el admin del CPT.
	 *
	 * @param string $hook Hook actual.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'external_store' !== $screen->post_type ) {
			return;
		}

		wp_register_script(
			'maxima-product-importer',
			'',
			array( 'jquery' ),
			Maxima_Integrations::VERSION,
			true
		);
		wp_enqueue_script( 'maxima-product-importer' );
		wp_localize_script(
			'maxima-product-importer',
			'maximaImporter',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'maxima_import_products' ),
				'batchSize' => $this->batch_size,
				'strings'   => array(
					'importing' => __( 'Importando productos...', 'maxima-integrations' ),
					'complete'  => __( 'Importación completa.', 'maxima-integrations' ),
					'error'     => __( 'Error en la importación.', 'maxima-integrations' ),
				),
			)
		);

		wp_add_inline_script(
			'maxima-product-importer',
			"(function($){
				var isRunning = false;
				function renderResults($box, data, done){
					var html = '';
					if (data.errors && data.errors.length) {
						html += '<p><strong>" . esc_js( __( 'Errores:', 'maxima-integrations' ) ) . "</strong></p><ul>';
						$.each(data.errors, function(i, error){
							html += '<li>' + error + '</li>';
						});
						html += '</ul>';
					}
					html += '<p>' + '" . esc_js( __( 'Importados:', 'maxima-integrations' ) ) . " ' + data.imported + '</p>';
					html += '<p>' + '" . esc_js( __( 'Actualizados:', 'maxima-integrations' ) ) . " ' + data.updated + '</p>';
					html += '<p>' + '" . esc_js( __( 'Omitidos:', 'maxima-integrations' ) ) . " ' + data.skipped + '</p>';
					if (done) {
						html += '<p><strong>' + maximaImporter.strings.complete + '</strong></p>';
					}
					$box.html(html);
				}
				function importBatch(storeId, offset, totals, $box, $button){
					$.post(maximaImporter.ajaxUrl, {
						action: 'maxima_import_products',
						nonce: maximaImporter.nonce,
						store_id: storeId,
						offset: offset,
						limit: maximaImporter.batchSize
					}).done(function(response){
						if (!response || !response.success) {
							$box.html('<p>' + maximaImporter.strings.error + '</p>');
							isRunning = false;
							$button.prop('disabled', false);
							return;
						}
						var data = response.data;
						totals.imported += data.imported;
						totals.updated += data.updated;
						totals.skipped += data.skipped;
						totals.errors = totals.errors.concat(data.errors || []);
						renderResults($box, totals, !data.has_more);
						if (data.has_more) {
							importBatch(storeId, data.next_offset, totals, $box, $button);
						} else {
							isRunning = false;
							$button.prop('disabled', false);
						}
					}).fail(function(){
						$box.html('<p>' + maximaImporter.strings.error + '</p>');
						isRunning = false;
						$button.prop('disabled', false);
					});
				}
				$(document).on('click', '#maxima-import-products-button', function(e){
					e.preventDefault();
					if (isRunning) {
						return;
					}
					isRunning = true;
					var $button = $(this);
					var storeId = $button.data('store-id');
					var $box = $('#maxima-import-products-results');
					$box.show().html('<p>' + maximaImporter.strings.importing + '</p>');
					$button.prop('disabled', true);
					importBatch(storeId, 0, {imported:0, updated:0, skipped:0, errors:[]}, $box, $button);
				});
			})(jQuery);"
		);
	}

	/**
	 * Maneja la importación vía AJAX.
	 */
	public function handle_ajax_import() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'No autorizado.', 'maxima-integrations' ) ), 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'maxima_import_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'maxima-integrations' ) ), 400 );
		}

		$store_id = isset( $_POST['store_id'] ) ? absint( wp_unslash( $_POST['store_id'] ) ) : 0;
		$offset   = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$limit    = isset( $_POST['limit'] ) ? absint( wp_unslash( $_POST['limit'] ) ) : $this->batch_size;

		if ( ! $store_id ) {
			wp_send_json_error( array( 'message' => __( 'Tienda inválida.', 'maxima-integrations' ) ), 400 );
		}

		$store_status = get_post_meta( $store_id, '_maxima_store_status', true );
		if ( 'active' !== $store_status ) {
			wp_send_json_error( array( 'message' => __( 'La tienda no está activa.', 'maxima-integrations' ) ), 400 );
		}

		$result = $this->import_products_batch( $store_id, $offset, $limit );
		if ( is_wp_error( $result ) ) {
			error_log( 'Maxima Integrations: ' . $result->get_error_message() );
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Importa un lote de productos.
	 *
	 * @param int $store_id ID de la tienda.
	 * @param int $offset Offset actual.
	 * @param int $limit Límite por lote.
	 * @return array|WP_Error
	 */
	private function import_products_batch( $store_id, $offset, $limit ) {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 30 );
		}

		$params = apply_filters(
			'maxima_external_products_request_params',
			array(
				'offset' => $offset,
				'limit'  => $limit,
			),
			$store_id
		);

		$products = $this->api_client->get_products( $store_id, $params );
		if ( is_wp_error( $products ) ) {
			return $products;
		}

		if ( isset( $products['data'] ) && is_array( $products['data'] ) ) {
			$products = $products['data'];
		} elseif ( isset( $products['items'] ) && is_array( $products['items'] ) ) {
			$products = $products['items'];
		}

		if ( ! is_array( $products ) ) {
			return new WP_Error( 'maxima_invalid_products', __( 'Respuesta inválida de productos.', 'maxima-integrations' ) );
		}

		$products = apply_filters( 'maxima_external_products_list', $products, $store_id );
		if ( ! is_array( $products ) ) {
			return new WP_Error( 'maxima_invalid_products', __( 'Respuesta inválida de productos.', 'maxima-integrations' ) );
		}

		$total_count = count( $products );
		$batch       = array_slice( $products, $offset, $limit );
		$has_more    = ( $offset + $limit ) < $total_count;
		$has_more    = apply_filters( 'maxima_external_products_has_more', $has_more, $products, $offset, $limit, $store_id );

		$result = array(
			'imported'   => 0,
			'updated'    => 0,
			'skipped'    => 0,
			'errors'     => array(),
			'has_more'   => $has_more,
			'next_offset'=> $offset + $limit,
		);

		foreach ( $batch as $product_data ) {
			if ( ! is_array( $product_data ) ) {
				$result['skipped']++;
				$result['errors'][] = __( 'Producto inválido en la respuesta.', 'maxima-integrations' );
				continue;
			}

			$import = $this->import_single_product( $store_id, $product_data );
			if ( is_wp_error( $import ) ) {
				$result['skipped']++;
				$result['errors'][] = $import->get_error_message();
				error_log( 'Maxima Integrations: ' . $import->get_error_message() );
				continue;
			}

			if ( 'created' === $import ) {
				$result['imported']++;
			} elseif ( 'updated' === $import ) {
				$result['updated']++;
			} else {
				$result['skipped']++;
			}
		}

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
		$product->update_meta_data( '_maxima_is_external', 'yes' );
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
