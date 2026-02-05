<?php
/**
 * Metabox de configuración para tiendas externas.
 *
 * @package Maxima_Integrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Maxima_External_Store_Metabox {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		add_action( 'save_post_external_store', array( $this, 'save_meta' ) );
	}

	/**
	 * Registra el metabox de configuración.
	 */
	public function register_metabox() {
		add_meta_box(
			'maxima_external_store_config',
			__( 'Configuración de Integración', 'maxima-integrations' ),
			array( $this, 'render_metabox' ),
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
	public function render_metabox( $post ) {
		wp_nonce_field( 'maxima_external_store_meta', 'maxima_external_store_meta_nonce' );

		$store_status  = get_post_meta( $post->ID, '_maxima_store_status', true );
		$api_base_url  = get_post_meta( $post->ID, '_maxima_api_base_url', true );
		$auth_type     = get_post_meta( $post->ID, '_maxima_auth_type', true );
		$notes         = get_post_meta( $post->ID, '_maxima_notes', true );
		$api_endpoints = get_post_meta( $post->ID, '_maxima_api_endpoints', true );
		$encrypted     = get_post_meta( $post->ID, '_maxima_api_key', true );
		$api_key       = $encrypted ? Maxima_Integrations_Crypto::decrypt( $encrypted ) : '';

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
		<?php if ( 'active' === $store_status ) : ?>
			<div class="maxima-import-products">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="maxima-import-products-form">
					<?php wp_nonce_field( 'maxima_import_products_action', 'maxima_import_products_nonce' ); ?>
					<input type="hidden" name="action" value="maxima_import_products" />
					<input type="hidden" name="store_id" value="<?php echo esc_attr( $post->ID ); ?>" />
					<p>
						<button type="submit" class="button button-primary" id="maxima-import-products-button">
							<?php esc_html_e( 'Importar productos', 'maxima-integrations' ); ?>
						</button>
					</p>
					<div id="maxima-import-products-results" class="notice notice-info" style="display:none;"></div>
				</form>
			</div>
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
		<?php else : ?>
			<p class="description">
				<?php esc_html_e( 'Activa la tienda para habilitar la importación de productos.', 'maxima-integrations' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Guarda los metadatos de configuración de la tienda externa.
	 *
	 * @param int $post_id ID del post.
	 */
	public function save_meta( $post_id ) {
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

		$store_status  = isset( $_POST['maxima_store_status'] ) ? sanitize_text_field( wp_unslash( $_POST['maxima_store_status'] ) ) : 'inactive';
		$auth_type     = isset( $_POST['maxima_auth_type'] ) ? sanitize_text_field( wp_unslash( $_POST['maxima_auth_type'] ) ) : 'none';
		$api_base_url  = isset( $_POST['maxima_api_base_url'] ) ? esc_url_raw( wp_unslash( $_POST['maxima_api_base_url'] ) ) : '';
		$notes         = isset( $_POST['maxima_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['maxima_notes'] ) ) : '';
		$endpoints_raw = isset( $_POST['maxima_api_endpoints'] ) ? wp_unslash( $_POST['maxima_api_endpoints'] ) : '';
		$api_key_raw   = isset( $_POST['maxima_api_key'] ) ? wp_unslash( $_POST['maxima_api_key'] ) : '';

		update_post_meta( $post_id, '_maxima_store_status', $store_status );
		update_post_meta( $post_id, '_maxima_api_base_url', $api_base_url );
		update_post_meta( $post_id, '_maxima_auth_type', $auth_type );
		update_post_meta( $post_id, '_maxima_notes', $notes );

		if ( '' !== trim( $endpoints_raw ) ) {
			$sanitized_endpoints = trim( sanitize_textarea_field( $endpoints_raw ) );
			$decoded_endpoints   = json_decode( $sanitized_endpoints, true );
			if ( null === $decoded_endpoints && JSON_ERROR_NONE !== json_last_error() ) {
				error_log( 'Maxima Integrations: JSON inválido para endpoints en store ID ' . (int) $post_id );
			} else {
				update_post_meta( $post_id, '_maxima_api_endpoints', wp_json_encode( $decoded_endpoints ) );
			}
		} else {
			delete_post_meta( $post_id, '_maxima_api_endpoints' );
		}

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
