<?php
/**
 * Mappings admin page template.
 */
$editing_mapping = isset( $editing_mapping ) && is_array( $editing_mapping ) ? $editing_mapping : null;
$editing_mapping_json = $editing_mapping && ! empty( $editing_mapping['mapping'] ) ? wp_json_encode( $editing_mapping['mapping'] ) : '{}';
?>
<div class="wrap wc-mas-admin">
    <h1><?php esc_html_e( 'Mappings', 'wc-multi-api-sync' ); ?></h1>

    <form method="get" class="wc-mas-form">
        <input type="hidden" name="page" value="wc-mas-mappings" />
        <label for="provider_id"><?php esc_html_e( 'Provider', 'wc-multi-api-sync' ); ?></label>
        <select name="provider_id" id="provider_id">
            <option value="0"><?php esc_html_e( 'Select provider', 'wc-multi-api-sync' ); ?></option>
            <?php foreach ( $providers as $provider ) : ?>
                <option value="<?php echo esc_attr( $provider['id'] ); ?>" <?php selected( $provider_id, $provider['id'] ); ?>><?php echo esc_html( $provider['name'] ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php submit_button( __( 'Load', 'wc-multi-api-sync' ), 'secondary', '', false ); ?>
    </form>

    <?php if ( $provider_id ) : ?>
        <h2><?php echo $editing_mapping ? esc_html__( 'Edit Mapping', 'wc-multi-api-sync' ) : esc_html__( 'Create Mapping', 'wc-multi-api-sync' ); ?></h2>
        <p><?php esc_html_e( 'Detect available JSON fields from the provider and map them manually to WooCommerce fields.', 'wc-multi-api-sync' ); ?></p>
        <button class="button" id="wc-mas-detect-fields" data-provider="<?php echo esc_attr( $provider_id ); ?>"><?php esc_html_e( 'Detect JSON Fields', 'wc-multi-api-sync' ); ?></button>
        <div class="wc-mas-json-paths" id="wc-mas-json-paths"></div>
        <form method="post" class="wc-mas-form" id="wc-mas-mapping-form" data-provider="<?php echo esc_attr( $provider_id ); ?>" data-editing-mapping='<?php echo esc_attr( $editing_mapping_json ); ?>'>
            <?php wp_nonce_field( 'wc_mas_mapping', 'wc_mas_mapping_nonce' ); ?>
            <input type="hidden" name="provider_id" value="<?php echo esc_attr( $provider_id ); ?>" />
            <input type="hidden" name="mapping_id" value="<?php echo esc_attr( $editing_mapping['id'] ?? 0 ); ?>" />
            <input type="hidden" name="mapping_json" id="wc-mas-mapping-json" value="" />
            <table class="form-table">
                <tr>
                    <th><label for="mapping_name"><?php esc_html_e( 'Mapping Name', 'wc-multi-api-sync' ); ?></label></th>
                    <td><input name="mapping_name" id="mapping_name" type="text" class="regular-text" value="<?php echo esc_attr( $editing_mapping['name'] ?? '' ); ?>" required></td>
                </tr>
            </table>
            <table class="widefat wc-mas-mapping-table" id="wc-mas-mapping-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'WooCommerce Field', 'wc-multi-api-sync' ); ?></th>
                        <th><?php esc_html_e( 'JSON Field', 'wc-multi-api-sync' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'wc-multi-api-sync' ); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
            <p>
                <button class="button" id="wc-mas-add-mapping-row"><?php esc_html_e( '➕ Add Mapping', 'wc-multi-api-sync' ); ?></button>
            </p>
            <?php submit_button( $editing_mapping ? __( 'Update Mapping', 'wc-multi-api-sync' ) : __( 'Save Mapping', 'wc-multi-api-sync' ) ); ?>
        </form>

        <h2><?php esc_html_e( 'Test Mapping', 'wc-multi-api-sync' ); ?></h2>
        <p><?php esc_html_e( 'Paste a sample JSON product payload and preview mapping without creating products.', 'wc-multi-api-sync' ); ?></p>
        <textarea id="wc-mas-sample-payload" class="large-text" rows="6" placeholder="<?php esc_attr_e( 'JSON of a single product for preview...', 'wc-multi-api-sync' ); ?>"></textarea>
        <button class="button" id="wc-mas-preview-mapping" data-provider="<?php echo esc_attr( $provider_id ); ?>"><?php esc_html_e( 'Test Mapping', 'wc-multi-api-sync' ); ?></button>
        <pre id="wc-mas-preview-output"></pre>

        <h2><?php esc_html_e( 'Existing Mappings', 'wc-multi-api-sync' ); ?></h2>
        <table class="widefat">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'ID', 'wc-multi-api-sync' ); ?></th>
                    <th><?php esc_html_e( 'Name', 'wc-multi-api-sync' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'wc-multi-api-sync' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $mappings as $mapping ) : ?>
                    <tr>
                        <td><?php echo esc_html( $mapping['id'] ); ?></td>
                        <td><?php echo esc_html( $mapping['name'] ); ?></td>
                        <td>
                            <form method="post" style="display:inline-block;">
                                <?php wp_nonce_field( 'wc_mas_import', 'wc_mas_import_nonce' ); ?>
                                <input type="hidden" name="provider_id" value="<?php echo esc_attr( $provider_id ); ?>" />
                                <input type="hidden" name="mapping_id" value="<?php echo esc_attr( $mapping['id'] ); ?>" />
                                <button class="button"><?php esc_html_e( 'Import Now', 'wc-multi-api-sync' ); ?></button>
                            </form>
                            <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'wc-mas-mappings', 'provider_id' => $provider_id, 'edit_mapping_id' => $mapping['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'wc-multi-api-sync' ); ?></a>
                            <button class="button wc-mas-delete-mapping" data-mapping-id="<?php echo esc_attr( $mapping['id'] ); ?>"><?php esc_html_e( 'Delete', 'wc-multi-api-sync' ); ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
