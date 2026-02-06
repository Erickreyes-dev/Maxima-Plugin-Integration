<?php
/**
 * Generic API client for provider requests.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_MAS_API_Client {
    private $provider;
    private $settings;

    public function __construct( $provider, $settings = array() ) {
        $this->provider = $provider;
        $this->settings = wp_parse_args(
            $settings,
            array(
                'timeout'    => 20,
                'user_agent' => 'WC-MAS/' . WC_MAS_VERSION,
                'retries'    => 2,
            )
        );
    }

    /**
     * Build headers with auth settings.
     */
    public function build_headers() {
        $headers = array();
        $auth_type = $this->provider['auth_type'] ?? 'none';
        $auth_config = $this->provider['auth_config'] ? json_decode( $this->provider['auth_config'], true ) : array();
        $auth_config = $this->decrypt_auth( $auth_config );
        $extra_headers = $this->provider['headers'] ? json_decode( $this->provider['headers'], true ) : array();

        if ( 'api_key_header' === $auth_type && ! empty( $auth_config['api_key'] ) ) {
            $header_name = $auth_config['header_name'] ?? 'X-API-Key';
            $headers[ $header_name ] = $auth_config['api_key'];
        }

        if ( 'bearer' === $auth_type && ! empty( $auth_config['api_key'] ) ) {
            $headers['Authorization'] = 'Bearer ' . $auth_config['api_key'];
        }

        if ( $extra_headers && is_array( $extra_headers ) ) {
            foreach ( $extra_headers as $key => $value ) {
                $headers[ $key ] = $value;
            }
        }

        $headers['User-Agent'] = $this->settings['user_agent'];

        return $headers;
    }

    /**
     * Build request args.
     */
    private function build_args( $method, $body = null ) {
        $args = array(
            'method'  => strtoupper( $method ),
            'timeout' => (int) $this->settings['timeout'],
            'headers' => $this->build_headers(),
        );

        $auth_type = $this->provider['auth_type'] ?? 'none';
        $auth_config = $this->provider['auth_config'] ? json_decode( $this->provider['auth_config'], true ) : array();
        $auth_config = $this->decrypt_auth( $auth_config );

        if ( 'basic' === $auth_type && ! empty( $auth_config['username'] ) ) {
            $args['headers']['Authorization'] = 'Basic ' . base64_encode( $auth_config['username'] . ':' . ( $auth_config['password'] ?? '' ) );
        }

        if ( null !== $body ) {
            $args['body'] = wp_json_encode( $body );
            $args['headers']['Content-Type'] = 'application/json';
        }

        return $args;
    }

    /**
     * Get a JSON response with retries.
     */
    public function get( $url, $params = array() ) {
        $url = add_query_arg( $params, $url );
        return $this->request_with_retries( 'GET', $url );
    }

    /**
     * Post JSON payload with retries.
     */
    public function post( $url, $payload ) {
        return $this->request_with_retries( 'POST', $url, $payload );
    }

    private function request_with_retries( $method, $url, $payload = null ) {
        $attempts = 0;
        $max = (int) $this->settings['retries'];
        do {
            $attempts++;
            $response = wp_remote_request( $url, $this->build_args( $method, $payload ) );
            if ( ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) < 500 ) {
                return $response;
            }
        } while ( $attempts <= $max );

        return $response;
    }

    private function decrypt_auth( $auth_config ) {
        $db = WC_MAS_DB::get_instance();
        foreach ( array( 'api_key', 'password' ) as $field ) {
            if ( ! empty( $auth_config[ $field ] ) ) {
                $auth_config[ $field ] = $db->decrypt_secret( $auth_config[ $field ] );
            }
        }
        return $auth_config;
    }

    /**
     * Paginate product endpoint by page & per_page.
     */
    public function paginate( $url, $params = array(), $page = 1, $per_page = 50 ) {
        $params = array_merge( $params, array( 'page' => $page, 'per_page' => $per_page ) );
        return $this->get( $url, $params );
    }
}
