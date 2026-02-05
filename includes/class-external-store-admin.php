<?php
/**
 * Pantalla de administración para tiendas externas.
 *
 * @package Maxima_Integrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Maxima_External_Store_Admin {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'redirect_legacy_screens' ) );
		add_action( 'admin_post_maxima_save_store', array( $this, 'handle_save_store' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
	}

	/**
	 * Registra menú principal y pantalla de tiendas.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Máxima', 'maxima-integrations' ),
			__( 'Máxima', 'maxima-integrations' ),
			'manage_options',
			'maxima',
			array( $this, 'render_page' ),
			'dashicons-store'
		);

		add_submenu_page(
			'maxima',
			__( 'Tiendas', 'maxima-integrations' ),
			__( 'Tiendas', 'maxima-integrations' ),
			'manage_options',
			'maxima_tiendas',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Redirige pantallas legacy del CPT hacia la pantalla custom.
	 */
	public function redirect_legacy_screens() {
		if ( ! is_admin() ) {
			return;
		}

		global $pagenow;

		if ( 'edit.php' === $pagenow && isset( $_GET['post_type'] ) && 'external_store' === $_GET['post_type'] ) {
			wp_safe_redirect( admin_url( 'admin.php?page=maxima_tiendas' ) );
			exit;
		}

		if ( 'post-new.php' === $pagenow && isset( $_GET['post_type'] ) && 'external_store' === $_GET['post_type'] ) {
			wp_safe_redirect( admin_url( 'admin.php?page=maxima_tiendas' ) );
			exit;
		}

		if ( 'post.php' === $pagenow ) {
			$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
			$post    = $post_id ? get_post( $post_id ) : null;
			if ( $post && 'external_store' === $post->post_type ) {
				wp_safe_redirect( admin_url( 'admin.php?page=maxima_tiendas&store_id=' . $post_id ) );
				exit;
			}
		}
	}

	/**
	 * Renderiza la pantalla de tiendas externas.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No autorizado.', 'maxima-integrations' ) );
		}

		$store_id = isset( $_GET['store_id'] ) ? absint( $_GET['store_id'] ) : 0;
		$store    = $store_id ? get_post( $store_id ) : null;
		if ( $store && 'external_store' !== $store->post_type ) {
			$store_id = 0;
			$store    = null;
		}

		$store_status  = $store_id ? get_post_meta( $store_id, '_maxima_store_status', true ) : 'inactive';
		$api_base_url  = $store_id ? get_post_meta( $store_id, '_maxima_api_base_url', true ) : '';
		$auth_type     = $store_id ? get_post_meta( $store_id, '_maxima_auth_type', true ) : 'none';
		$notes         = $store_id ? get_post_meta( $store_id, '_maxima_notes', true ) : '';
		$api_endpoints = $store_id ? get_post_meta( $store_id, '_maxima_api_endpoints', true ) : '';
		$encrypted     = $store_id ? get_post_meta( $store_id, '_maxima_api_key', true ) : '';
		$api_key       = $encrypted ? Maxima_Integrations_Crypto::decrypt( $encrypted ) : '';
		$title         = $store ? $store->post_title : '';

		$stores = get_posts(
			array(
				'post_type'      => 'external_store',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 50,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tiendas', 'maxima-integrations' ); ?></h1>

			<div class="maxima-stores-admin">
				<h2 class="title"><?php esc_html_e( 'Listado', 'maxima-integrations' ); ?></h2>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=maxima_tiendas&store_id=0' ) ); ?>">
						<?php esc_html_e( 'Añadir tienda', 'maxima-integrations' ); ?>
					</a>
				</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Tienda', 'maxima-integrations' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'maxima-integrations' ); ?></th>
							<th><?php esc_html_e( 'Acciones', 'maxima-integrations' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( $stores ) : ?>
							<?php foreach ( $stores as $item ) : ?>
								<?php $status = get_post_meta( $item->ID, '_maxima_store_status', true ); ?>
								<tr>
									<td><?php echo esc_html( $item->post_title ); ?></td>
									<td><?php echo esc_html( $status ? $status : 'inactive' ); ?></td>
									<td>
										<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=maxima_tiendas&store_id=' . $item->ID ) ); ?>">
											<?php esc_html_e( 'Editar', 'maxima-integrations' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr>
								<td colspan="3"><?php esc_html_e( 'No hay tiendas registradas.', 'maxima-integrations' ); ?></td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>

				<h2 class="title"><?php echo $store_id ? esc_html__( 'Editar tienda', 'maxima-integrations' ) : esc_html__( 'Nueva tienda', 'maxima-integrations' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'maxima_save_store', 'maxima_save_store_nonce' ); ?>
					<input type="hidden" name="action" value="maxima_save_store" />
					<input type="hidden" name="store_id" value="<?php echo esc_attr( $store_id ); ?>" />

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="maxima_store_title"><?php esc_html_e( 'Nombre de la tienda', 'maxima-integrations' ); ?></label>
								</th>
								<td>
									<input type="text" class="regular-text" name="maxima_store_title" id="maxima_store_title" value="<?php echo esc_attr( $title ); ?>" required />
								</td>
							</tr>
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
							<tr>
								<th scope="row">
									<label for="maxima_api_endpoints"><?php esc_html_e( 'Endpoints de la API', 'maxima-integrations' ); ?></label>
								</th>
								<td>
									<textarea name="maxima_api_endpoints" id="maxima_api_endpoints" rows="6" class="large-text code"><?php echo esc_textarea( $api_endpoints ); ?></textarea>
									<p class="description">
										<?php esc_html_e( 'Ejemplo de JSON esperado:', 'maxima-integrations' ); ?>
									</p>
									<pre><code>{
  "products": "/products",
  "product": "/products/{id}",
  "stock": "/products/{id}/stock",
  "order": "/orders"
}</code></pre>
								</td>
							</tr>
						</tbody>
					</table>

					<p>
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Guardar tienda', 'maxima-integrations' ); ?>
						</button>
					</p>
				</form>

				<?php if ( $store_id && 'active' === $store_status ) : ?>
					<h2 class="title"><?php esc_html_e( 'Importación de productos', 'maxima-integrations' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="maxima-import-products-form">
						<?php wp_nonce_field( 'maxima_import_products_action', 'maxima_import_products_nonce' ); ?>
						<input type="hidden" name="action" value="maxima_import_products" />
						<input type="hidden" name="store_id" value="<?php echo esc_attr( $store_id ); ?>" />
						<p>
							<button type="submit" class="button button-secondary" id="maxima-import-products-button">
								<?php esc_html_e( 'Importar productos', 'maxima-integrations' ); ?>
							</button>
						</p>
						<div id="maxima-import-products-results" class="notice notice-info" style="display:none;"></div>
					</form>
					<script>
						(function() {
							var form = document.getElementById('maxima-import-products-form');
							if (!form) {
								return;
							}
							form.addEventListener('submit', function() {
								var box = document.getElementById('maxima-import-products-results');
								var button = document.getElementById('maxima-import-products-button');
								if (box) {
									box.style.display = 'block';
									box.innerHTML = '<p><?php echo esc_js( __( 'Importando productos...', 'maxima-integrations' ) ); ?></p>';
								}
								if (button) {
									button.setAttribute('disabled', 'disabled');
								}
							});
						})();
					</script>
				<?php elseif ( $store_id ) : ?>
					<p class="description">
						<?php esc_html_e( 'Activa la tienda para habilitar la importación de productos.', 'maxima-integrations' ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Maneja el guardado de la tienda.
	 */
	public function handle_save_store() {
		if ( ! current_user_can( 'manage_options' ) ) {
			$this->store_notice( array( 'type' => 'error', 'message' => __( 'No autorizado.', 'maxima-integrations' ) ) );
			$this->redirect_back( 0 );
		}

		$nonce = isset( $_POST['maxima_save_store_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['maxima_save_store_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'maxima_save_store' ) ) {
			$this->store_notice( array( 'type' => 'error', 'message' => __( 'Nonce inválido.', 'maxima-integrations' ) ) );
			$this->redirect_back( 0 );
		}

		$store_id   = isset( $_POST['store_id'] ) ? absint( wp_unslash( $_POST['store_id'] ) ) : 0;
		$store_name = isset( $_POST['maxima_store_title'] ) ? sanitize_text_field( wp_unslash( $_POST['maxima_store_title'] ) ) : '';
		if ( '' === $store_name ) {
			$this->store_notice( array( 'type' => 'error', 'message' => __( 'El nombre de la tienda es obligatorio.', 'maxima-integrations' ) ) );
			$this->redirect_back( $store_id );
		}

		$post_data = array(
			'ID'         => $store_id,
			'post_type'  => 'external_store',
			'post_title' => $store_name,
			'post_status'=> 'publish',
		);

		$result = wp_update_post( $post_data, true );
		if ( is_wp_error( $result ) || ! $result ) {
			$this->store_notice(
				array(
					'type'    => 'error',
					'message' => __( 'No se pudo guardar la tienda.', 'maxima-integrations' ),
				)
			);
			$this->redirect_back( $store_id );
		}

		$store_id     = (int) $result;
		$store_status = isset( $_POST['maxima_store_status'] ) ? sanitize_text_field( wp_unslash( $_POST['maxima_store_status'] ) ) : 'inactive';
		$auth_type    = isset( $_POST['maxima_auth_type'] ) ? sanitize_text_field( wp_unslash( $_POST['maxima_auth_type'] ) ) : 'none';
		$api_base_url = isset( $_POST['maxima_api_base_url'] ) ? esc_url_raw( wp_unslash( $_POST['maxima_api_base_url'] ) ) : '';
		$notes        = isset( $_POST['maxima_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['maxima_notes'] ) ) : '';
		$endpoints    = isset( $_POST['maxima_api_endpoints'] ) ? wp_unslash( $_POST['maxima_api_endpoints'] ) : '';
		$api_key_raw  = isset( $_POST['maxima_api_key'] ) ? wp_unslash( $_POST['maxima_api_key'] ) : '';

		update_post_meta( $store_id, '_maxima_store_status', $store_status );
		update_post_meta( $store_id, '_maxima_api_base_url', $api_base_url );
		update_post_meta( $store_id, '_maxima_auth_type', $auth_type );
		update_post_meta( $store_id, '_maxima_notes', $notes );

		if ( '' !== trim( $endpoints ) ) {
			$sanitized_endpoints = trim( sanitize_textarea_field( $endpoints ) );
			$decoded_endpoints   = json_decode( $sanitized_endpoints, true );
			if ( null === $decoded_endpoints && JSON_ERROR_NONE !== json_last_error() ) {
				error_log( 'Maxima Integrations: JSON inválido para endpoints en store ID ' . (int) $store_id );
			} else {
				update_post_meta( $store_id, '_maxima_api_endpoints', wp_json_encode( $decoded_endpoints ) );
			}
		} else {
			delete_post_meta( $store_id, '_maxima_api_endpoints' );
		}

		if ( '' !== $api_key_raw ) {
			$encrypted = Maxima_Integrations_Crypto::encrypt( sanitize_text_field( $api_key_raw ) );
			if ( $encrypted ) {
				update_post_meta( $store_id, '_maxima_api_key', $encrypted );
			}
		} else {
			delete_post_meta( $store_id, '_maxima_api_key' );
		}

		$this->store_notice( array( 'type' => 'success', 'message' => __( 'Tienda guardada correctamente.', 'maxima-integrations' ) ) );
		$this->redirect_back( $store_id );
	}

	/**
	 * Renderiza notices almacenados.
	 */
	public function render_admin_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'maxima_page_maxima_tiendas' !== $screen->id ) {
			return;
		}

		$notice = $this->get_notice();
		if ( ! $notice ) {
			return;
		}

		$type    = ! empty( $notice['type'] ) ? $notice['type'] : 'info';
		$message = ! empty( $notice['message'] ) ? $notice['message'] : '';

		if ( '' === $message ) {
			return;
		}

		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Almacena un notice.
	 *
	 * @param array $data Datos del notice.
	 */
	private function store_notice( $data ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$defaults = array(
			'type'    => 'info',
			'message' => '',
		);

		$notice = wp_parse_args( $data, $defaults );
		set_transient( $this->get_notice_key( $user_id ), $notice, MINUTE_IN_SECONDS * 5 );
	}

	/**
	 * Obtiene notice almacenado.
	 *
	 * @return array|null
	 */
	private function get_notice() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return null;
		}

		$key    = $this->get_notice_key( $user_id );
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
			return $notice;
		}

		return null;
	}

	/**
	 * Genera clave de notice.
	 *
	 * @param int $user_id ID del usuario.
	 * @return string
	 */
	private function get_notice_key( $user_id ) {
		return 'maxima_store_notice_' . (int) $user_id;
	}

	/**
	 * Redirige de vuelta a la pantalla custom.
	 *
	 * @param int $store_id ID de la tienda.
	 */
	private function redirect_back( $store_id ) {
		$location = admin_url( 'admin.php?page=maxima_tiendas' );
		if ( $store_id ) {
			$location = add_query_arg( 'store_id', (int) $store_id, $location );
		}

		wp_safe_redirect( $location );
		exit;
	}
}
