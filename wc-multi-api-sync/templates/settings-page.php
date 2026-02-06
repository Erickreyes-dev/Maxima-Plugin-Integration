<?php
/**
 * Settings admin page template.
 */
?>
<div class="wrap wc-mas-admin">
    <h1><?php esc_html_e( 'Settings', 'wc-multi-api-sync' ); ?></h1>
    <form method="post">
        <?php wp_nonce_field( 'wc_mas_settings', 'wc_mas_settings_nonce' ); ?>
        <table class="form-table">
            <tr>
                <th><label for="timeout"><?php esc_html_e( 'Timeout (seconds)', 'wc-multi-api-sync' ); ?></label></th>
                <td><input type="number" name="timeout" id="timeout" value="<?php echo esc_attr( $settings['timeout'] ); ?>"></td>
            </tr>
            <tr>
                <th><label for="user_agent"><?php esc_html_e( 'User Agent', 'wc-multi-api-sync' ); ?></label></th>
                <td><input type="text" name="user_agent" id="user_agent" class="regular-text" value="<?php echo esc_attr( $settings['user_agent'] ); ?>"></td>
            </tr>
            <tr>
                <th><label for="retries"><?php esc_html_e( 'API Retries', 'wc-multi-api-sync' ); ?></label></th>
                <td><input type="number" name="retries" id="retries" value="<?php echo esc_attr( $settings['retries'] ); ?>"></td>
            </tr>
            <tr>
                <th><label for="batch_size"><?php esc_html_e( 'Batch Size', 'wc-multi-api-sync' ); ?></label></th>
                <td><input type="number" name="batch_size" id="batch_size" value="<?php echo esc_attr( $settings['batch_size'] ); ?>"></td>
            </tr>
        </table>
        <?php submit_button( __( 'Save Settings', 'wc-multi-api-sync' ) ); ?>
    </form>
</div>
