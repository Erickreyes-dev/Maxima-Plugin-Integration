<?php
/**
 * Providers admin page template.
 */
$editing_provider = isset( $editing_provider ) && is_array( $editing_provider ) ? $editing_provider : null;
$provider_auth = $editing_provider['auth_config'] ?? array();
?>
<div class="wrap wc-mas-admin">
    <h1><?php esc_html_e( 'Providers', 'wc-multi-api-sync' ); ?></h1>

    <form method="post" class="wc-mas-form">
        <?php wp_nonce_field( 'wc_mas_provider', 'wc_mas_provider_nonce' ); ?>
        <input type="hidden" name="provider_id" value="<?php echo esc_attr( $editing_provider['id'] ?? 0 ); ?>" />

        <h2><?php esc_html_e( 'Add / Edit Provider', 'wc-multi-api-sync' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="name"><?php esc_html_e( 'Name', 'wc-multi-api-sync' ); ?></label></th>
                <td><input name="name" id="name" type="text" class="regular-text" value="<?php echo esc_attr( $editing_provider['name'] ?? '' ); ?>" required></td>
            </tr>
            <tr>
                <th><label for="base_url"><?php esc_html_e( 'Base URL', 'wc-multi-api-sync' ); ?></label></th>
                <td><input name="base_url" id="base_url" type="url" class="regular-text" value="<?php echo esc_attr( $editing_provider['base_url'] ?? '' ); ?>" required></td>
            </tr>
            <tr>
                <th><label for="products_endpoint"><?php esc_html_e( 'Products Endpoint', 'wc-multi-api-sync' ); ?></label></th>
                <td><input name="products_endpoint" id="products_endpoint" type="text" class="regular-text" value="<?php echo esc_attr( $editing_provider['products_endpoint'] ?? '' ); ?>" required></td>
            </tr>
            <tr>
                <th><label for="notify_endpoint"><?php esc_html_e( 'Notify Endpoint', 'wc-multi-api-sync' ); ?></label></th>
                <td><input name="notify_endpoint" id="notify_endpoint" type="url" class="regular-text" value="<?php echo esc_attr( $editing_provider['notify_endpoint'] ?? '' ); ?>" required></td>
            </tr>
            <tr>
                <th><label for="auth_type"><?php esc_html_e( 'Auth Type', 'wc-multi-api-sync' ); ?></label></th>
                <td>
                    <select name="auth_type" id="auth_type">
                        <option value="none" <?php selected( $editing_provider['auth_type'] ?? 'none', 'none' ); ?>><?php esc_html_e( 'None', 'wc-multi-api-sync' ); ?></option>
                        <option value="api_key_header" <?php selected( $editing_provider['auth_type'] ?? 'none', 'api_key_header' ); ?>><?php esc_html_e( 'API Key Header', 'wc-multi-api-sync' ); ?></option>
                        <option value="bearer" <?php selected( $editing_provider['auth_type'] ?? 'none', 'bearer' ); ?>><?php esc_html_e( 'Bearer', 'wc-multi-api-sync' ); ?></option>
                        <option value="basic" <?php selected( $editing_provider['auth_type'] ?? 'none', 'basic' ); ?>><?php esc_html_e( 'Basic', 'wc-multi-api-sync' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="api_key"><?php esc_html_e( 'API Key', 'wc-multi-api-sync' ); ?></label></th>
                <td><input name="api_key" id="api_key" type="text" class="regular-text" value=""></td>
            </tr>
            <tr>
                <th><label for="header_name"><?php esc_html_e( 'Header Name', 'wc-multi-api-sync' ); ?></label></th>
                <td><input name="header_name" id="header_name" type="text" class="regular-text" value="<?php echo esc_attr( $provider_auth['header_name'] ?? '' ); ?>" placeholder="X-API-Key"></td>
            </tr>
            <tr>
                <th><label for="username"><?php esc_html_e( 'Username', 'wc-multi-api-sync' ); ?></label></th>
                <td><input name="username" id="username" type="text" class="regular-text" value="<?php echo esc_attr( $provider_auth['username'] ?? '' ); ?>"></td>
            </tr>
            <tr>
                <th><label for="password"><?php esc_html_e( 'Password', 'wc-multi-api-sync' ); ?></label></th>
                <td><input name="password" id="password" type="password" class="regular-text" value=""></td>
            </tr>
            <tr>
                <th><label for="headers"><?php esc_html_e( 'Additional Headers (key:value per line)', 'wc-multi-api-sync' ); ?></label></th>
                <td><textarea name="headers" id="headers" class="large-text" rows="3"><?php echo esc_textarea( $editing_provider['headers_text'] ?? '' ); ?></textarea></td>
            </tr>
            <tr>
                <th><label for="default_params"><?php esc_html_e( 'Default Query Params (key:value per line)', 'wc-multi-api-sync' ); ?></label></th>
                <td><textarea name="default_params" id="default_params" class="large-text" rows="3"><?php echo esc_textarea( $editing_provider['default_params_text'] ?? '' ); ?></textarea></td>
            </tr>
            <tr>
                <th><label for="sync_frequency"><?php esc_html_e( 'Sync Frequency', 'wc-multi-api-sync' ); ?></label></th>
                <td>
                    <select name="sync_frequency" id="sync_frequency">
                        <option value="hourly" <?php selected( $editing_provider['sync_frequency'] ?? 'hourly', 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'wc-multi-api-sync' ); ?></option>
                        <option value="twice_daily" <?php selected( $editing_provider['sync_frequency'] ?? 'hourly', 'twice_daily' ); ?>><?php esc_html_e( 'Twice Daily', 'wc-multi-api-sync' ); ?></option>
                        <option value="daily" <?php selected( $editing_provider['sync_frequency'] ?? 'hourly', 'daily' ); ?>><?php esc_html_e( 'Daily', 'wc-multi-api-sync' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="active"><?php esc_html_e( 'Active', 'wc-multi-api-sync' ); ?></label></th>
                <td><input name="active" id="active" type="checkbox" <?php checked( (int) ( $editing_provider['active'] ?? 1 ), 1 ); ?>></td>
            </tr>
        </table>

        <?php submit_button( $editing_provider ? __( 'Update Provider', 'wc-multi-api-sync' ) : __( 'Save Provider', 'wc-multi-api-sync' ) ); ?>
    </form>

    <h2><?php esc_html_e( 'Existing Providers', 'wc-multi-api-sync' ); ?></h2>
    <table class="widefat">
        <thead>
            <tr>
                <th><?php esc_html_e( 'ID', 'wc-multi-api-sync' ); ?></th>
                <th><?php esc_html_e( 'Name', 'wc-multi-api-sync' ); ?></th>
                <th><?php esc_html_e( 'Base URL', 'wc-multi-api-sync' ); ?></th>
                <th><?php esc_html_e( 'Active', 'wc-multi-api-sync' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'wc-multi-api-sync' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $providers as $provider ) : ?>
                <tr>
                    <td><?php echo esc_html( $provider['id'] ); ?></td>
                    <td><?php echo esc_html( $provider['name'] ); ?></td>
                    <td><?php echo esc_url( $provider['base_url'] ); ?></td>
                    <td><?php echo $provider['active'] ? esc_html__( 'Yes', 'wc-multi-api-sync' ) : esc_html__( 'No', 'wc-multi-api-sync' ); ?></td>
                    <td>
                        <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'wc-mas-providers', 'edit_provider_id' => $provider['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'wc-multi-api-sync' ); ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
