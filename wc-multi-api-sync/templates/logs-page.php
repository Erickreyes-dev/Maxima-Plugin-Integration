<?php
/**
 * Logs admin page template.
 */
?>
<div class="wrap wc-mas-admin">
    <h1><?php esc_html_e( 'Logs', 'wc-multi-api-sync' ); ?></h1>
    <form method="get" class="wc-mas-form">
        <input type="hidden" name="page" value="wc-mas-logs" />
        <label for="provider_id"><?php esc_html_e( 'Provider', 'wc-multi-api-sync' ); ?></label>
        <select name="provider_id" id="provider_id">
            <option value=""><?php esc_html_e( 'All', 'wc-multi-api-sync' ); ?></option>
            <?php foreach ( $providers as $provider ) : ?>
                <option value="<?php echo esc_attr( $provider['id'] ); ?>" <?php selected( $provider_id, $provider['id'] ); ?>><?php echo esc_html( $provider['name'] ); ?></option>
            <?php endforeach; ?>
        </select>
        <label for="level"><?php esc_html_e( 'Level', 'wc-multi-api-sync' ); ?></label>
        <select name="level" id="level">
            <option value=""><?php esc_html_e( 'All', 'wc-multi-api-sync' ); ?></option>
            <option value="info" <?php selected( $level, 'info' ); ?>><?php esc_html_e( 'Info', 'wc-multi-api-sync' ); ?></option>
            <option value="error" <?php selected( $level, 'error' ); ?>><?php esc_html_e( 'Error', 'wc-multi-api-sync' ); ?></option>
        </select>
        <label for="date"><?php esc_html_e( 'Date', 'wc-multi-api-sync' ); ?></label>
        <input type="date" name="date" id="date" value="<?php echo esc_attr( $date ); ?>" />
        <?php submit_button( __( 'Filter', 'wc-multi-api-sync' ), 'secondary', '', false ); ?>
    </form>

    <table class="widefat">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Date', 'wc-multi-api-sync' ); ?></th>
                <th><?php esc_html_e( 'Provider', 'wc-multi-api-sync' ); ?></th>
                <th><?php esc_html_e( 'Level', 'wc-multi-api-sync' ); ?></th>
                <th><?php esc_html_e( 'Message', 'wc-multi-api-sync' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $logs as $log ) : ?>
                <tr>
                    <td><?php echo esc_html( $log['created_at'] ); ?></td>
                    <td><?php echo esc_html( $log['provider_id'] ); ?></td>
                    <td><?php echo esc_html( $log['level'] ); ?></td>
                    <td>
                        <?php echo esc_html( $log['message'] ); ?>
                        <?php if ( $log['context_json'] ) : ?>
                            <pre><?php echo esc_html( $log['context_json'] ); ?></pre>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
