<?php
/**
 * JSON resolver utilities for product payloads.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_MAS_JSON_Resolver {
    /**
     * Decode a JSON response body.
     */
    public function decode_body( $body ) {
        $decoded = json_decode( $body, true );
        return is_array( $decoded ) ? $decoded : null;
    }

    /**
     * Extract products array from a payload.
     */
    public function extract_products_array( $payload ) {
        if ( ! is_array( $payload ) ) {
            return array();
        }

        if ( $this->is_list( $payload ) ) {
            return $payload;
        }

        $found = $this->find_first_list( $payload );
        return $found ? $found : array();
    }

    /**
     * Extract a sample product from a payload.
     */
    public function extract_sample_product( $payload ) {
        $products = $this->extract_products_array( $payload );
        if ( ! $products ) {
            return null;
        }
        return $products[0];
    }

    /**
     * Flatten available dot-notation paths from a product payload.
     */
    public function flatten_paths( $data, $prefix = '' ) {
        $paths = array();
        if ( is_array( $data ) ) {
            if ( $this->is_list( $data ) ) {
                if ( '' !== $prefix ) {
                    $paths[] = $prefix;
                }
                if ( ! empty( $data ) ) {
                    $child_prefix = '' === $prefix ? '[0]' : $prefix . '[0]';
                    $paths = array_merge( $paths, $this->flatten_paths( $data[0], $child_prefix ) );
                }
            } else {
                foreach ( $data as $key => $value ) {
                    $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
                    if ( is_array( $value ) ) {
                        $paths[] = $path;
                        $paths = array_merge( $paths, $this->flatten_paths( $value, $path ) );
                    } else {
                        $paths[] = $path;
                    }
                }
            }
        } elseif ( '' !== $prefix ) {
            $paths[] = $prefix;
        }

        return array_values( array_unique( $paths ) );
    }

    private function find_first_list( $payload ) {
        foreach ( $payload as $value ) {
            if ( is_array( $value ) ) {
                if ( $this->is_list( $value ) ) {
                    return $value;
                }
                $found = $this->find_first_list( $value );
                if ( $found ) {
                    return $found;
                }
            }
        }
        return null;
    }

    private function is_list( $array ) {
        if ( ! is_array( $array ) ) {
            return false;
        }
        $keys = array_keys( $array );
        return $keys === range( 0, count( $array ) - 1 );
    }
}
