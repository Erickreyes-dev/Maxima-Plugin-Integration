<?php
/**
 * Product mapping utilities.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_MAS_Mapper {
    /**
     * Get value from array/object using dot notation.
     */
    public function get_value_from_path( $data, $path ) {
        if ( '' === $path || null === $path ) {
            return null;
        }

        $segments = preg_split( '/\.(?![^\[]*\])/', $path );
        $current  = $data;

        foreach ( $segments as $segment ) {
            if ( '' === $segment ) {
                continue;
            }

            if ( preg_match_all( '/([^[\]]+)|\[(\d+)\]/', $segment, $matches, PREG_SET_ORDER ) ) {
                foreach ( $matches as $match ) {
                    $key = isset( $match[1] ) ? $match[1] : $match[2];
                    if ( is_array( $current ) && array_key_exists( $key, $current ) ) {
                        $current = $current[ $key ];
                    } elseif ( is_object( $current ) && isset( $current->{$key} ) ) {
                        $current = $current->{$key};
                    } else {
                        return null;
                    }
                }
            }
        }

        return $current;
    }

    /**
     * Apply transformations to mapped value.
     */
    private function apply_transformations( $value, $mapping, $provider_id ) {
        $transform = $mapping['transform'] ?? array();
        if ( ! $transform ) {
            return apply_filters( 'wc_mas_map_value', $value, $mapping, $provider_id );
        }

        if ( isset( $transform['trim'] ) && $transform['trim'] ) {
            $value = is_string( $value ) ? trim( $value ) : $value;
        }

        if ( isset( $transform['default'] ) && ( null === $value || '' === $value ) ) {
            $value = $transform['default'];
        }

        if ( isset( $transform['int'] ) && $transform['int'] ) {
            $value = (int) $value;
        }

        if ( isset( $transform['float'] ) && $transform['float'] ) {
            $value = (float) $value;
        }

        if ( isset( $transform['multiply'] ) ) {
            $value = (float) $value * (float) $transform['multiply'];
        }

        if ( isset( $transform['prefix'] ) ) {
            $value = $transform['prefix'] . $value;
        }

        if ( isset( $transform['suffix'] ) ) {
            $value = $value . $transform['suffix'];
        }

        if ( isset( $transform['currency_convert'] ) ) {
            $value = apply_filters( 'wc_mas_currency_convert', $value, $transform['currency_convert'], $provider_id );
        }

        return apply_filters( 'wc_mas_map_value', $value, $mapping, $provider_id );
    }

    /**
     * Map a single product payload to WooCommerce data array.
     */
    public function map_product( $payload, $mapping, $provider_id ) {
        $mapped = array();

        $mapping = apply_filters( 'wc_mas_pre_map_product', $mapping, $provider_id );

        foreach ( $mapping as $key => $config ) {
            $path = null;
            if ( is_string( $config ) ) {
                $path = $config;
                $config = array( 'path' => $config );
            } elseif ( is_array( $config ) && isset( $config['path'] ) ) {
                $path = $config['path'];
            }

            if ( ! $path ) {
                continue;
            }

            $value = $this->get_value_from_path( $payload, $path );
            $value = $this->apply_transformations( $value, $config, $provider_id );
            if ( null === $value ) {
                continue;
            }
            $mapped[ $key ] = $value;
        }

        return $mapped;
    }
}
