<?php
/**
 * Mappings admin page template.
 */
$example_mapping = array(
    'title' => array( 'path' => 'title', 'transform' => array( 'trim' => true ) ),
    'description' => array( 'path' => 'description' ),
    'short_description' => array( 'path' => 'description' ),
    'sku' => array( 'path' => 'sku' ),
    'regular_price' => array( 'path' => 'price', 'transform' => array( 'float' => true ) ),
    'stock' => array( 'path' => 'stock', 'transform' => array( 'int' => true ) ),
    'images' => array( 'path' => 'images' ),
    'categories' => array( 'path' => 'categories' ),
);
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
        <h2><?php esc_html_e( 'Create Mapping', 'wc-multi-api-sync' ); ?></h2>
        <p><?php esc_html_e( 'Use dot notation paths like images.0.url or attributes.color[0].', 'wc-multi-api-sync' ); ?></p>
        <form method="post" class="wc-mas-form">
            <?php wp_nonce_field( 'wc_mas_mapping', 'wc_mas_mapping_nonce' ); ?>
            <input type="hidden" name="provider_id" value="<?php echo esc_attr( $provider_id ); ?>" />
            <table class="form-table">
                <tr>
                    <th><label for="mapping_name"><?php esc_html_e( 'Mapping Name', 'wc-multi-api-sync' ); ?></label></th>
                    <td><input name="mapping_name" id="mapping_name" type="text" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="mapping_json"><?php esc_html_e( 'Mapping JSON', 'wc-multi-api-sync' ); ?></label></th>
                    <td>
                        <textarea name="mapping_json" id="mapping_json" class="large-text" rows="10"><?php echo esc_textarea( wp_json_encode( $example_mapping, JSON_PRETTY_PRINT ) ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Use transform options like trim, int, float, default, multiply, prefix, suffix, currency_convert.', 'wc-multi-api-sync' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Save Mapping', 'wc-multi-api-sync' ) ); ?>
        </form>

        <h2><?php esc_html_e( 'Test Mapping', 'wc-multi-api-sync' ); ?></h2>
        <p><?php esc_html_e( 'Paste a sample JSON product payload and preview mapping without creating products.', 'wc-multi-api-sync' ); ?></p>
        <button class="button" id="wc-mas-test-endpoint" data-provider="<?php echo esc_attr( $provider_id ); ?>"><?php esc_html_e( 'Test Endpoint', 'wc-multi-api-sync' ); ?></button>
        <textarea id="wc-mas-sample-payload" class="large-text" rows="6"></textarea>
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
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
