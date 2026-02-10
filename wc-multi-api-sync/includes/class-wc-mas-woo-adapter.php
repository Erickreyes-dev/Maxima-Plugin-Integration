<?php
/**
 * WooCommerce adapter for product creation/update.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_MAS_Woo_Adapter {
    private $logger;
    private $db;
    private $media;

    public function __construct() {
        $this->logger = WC_MAS_Logger::get_instance();
        $this->db = WC_MAS_DB::get_instance();
        $this->media = new WC_MAS_Media();
    }

    /**
     * Create or update WooCommerce product by external identifiers.
     */
    public function create_or_update_product_by_sku( $mapped, $payload, $provider_id ) {
        return $this->create_or_update_product( $mapped, $payload, $provider_id );
    }

    public function create_or_update_product( $mapped, $payload, $provider_id, $product_id = null ) {
        $external_id = $payload['id'] ?? ( $mapped['external_id'] ?? null );
        $sku = $mapped['sku'] ?? null;
        if ( empty( $sku ) && ! empty( $external_id ) ) {
           $sku = $provider_id . '-' . $external_id;
            $this->logger->info(
                'Generated SKU from external_id',
                $provider_id,
                array(
                    'sku' => $sku,
                    'external_id' => $external_id,
                )
            );
        }

        $context = array(
            'provider_id' => $provider_id,
            'sku' => $sku,
            'external_id' => $external_id,
        );

        if ( empty( $mapped['title'] ) ) {
            $this->logger->warning( 'Skipping product: title empty', $provider_id, $context );
            return array(
                'action' => 'skipped',
                'product_id' => null,
            );
        }

        $product_id = $product_id ? (int) $product_id : 0;
        if ( ! $product_id && ! empty( $sku ) ) {
            $product_id = wc_get_product_id_by_sku( $sku );
        }
        $has_variations = ! empty( $mapped['variations'] ) && is_array( $mapped['variations'] );
        $is_update = (bool) $product_id;
        $product = $product_id ? wc_get_product( $product_id ) : ( $has_variations ? new WC_Product_Variable() : new WC_Product_Simple() );
        if ( ! $product ) {
            $this->logger->error( 'Product load failed before save', $provider_id, $context );
            $this->logger->warning( 'Skipping product: unable to initialize product object', $provider_id, $context );
            return array(
                'action' => 'error',
                'product_id' => null,
            );
        }

        $changes = array();

        if ( isset( $mapped['title'] ) ) {
            $prefixed_title = $this->prefix_provider_title( $mapped['title'], $provider_id );
            if ( $prefixed_title !== $product->get_name() ) {
                $changes['title'] = array( 'from' => $product->get_name(), 'to' => $prefixed_title );
                $product->set_name( $prefixed_title );
            }
        }
        if ( isset( $mapped['short_description'] ) && $mapped['short_description'] !== $product->get_short_description() ) {
            $changes['short_description'] = array( 'from' => $product->get_short_description(), 'to' => $mapped['short_description'] );
            $product->set_short_description( $mapped['short_description'] );
        }
        if ( isset( $mapped['description'] ) && $mapped['description'] !== $product->get_description() ) {
            $changes['description'] = array( 'from' => $product->get_description(), 'to' => $mapped['description'] );
            $product->set_description( $mapped['description'] );
        }

        if ( ! empty( $sku ) ) {
            $sku = $this->prefix_provider_sku( $sku, $provider_id );
            $sku = $this->resolve_unique_sku( $sku, $product_id, $provider_id, $external_id );
            if ( $sku && $sku !== $product->get_sku() ) {
                $changes['sku'] = array( 'from' => $product->get_sku(), 'to' => $sku );
                $product->set_sku( $sku );
            }
        }

        if ( isset( $mapped['regular_price'] ) && (string) $mapped['regular_price'] !== (string) $product->get_regular_price() ) {
            $changes['regular_price'] = array( 'from' => $product->get_regular_price(), 'to' => (string) $mapped['regular_price'] );
            $product->set_regular_price( (string) $mapped['regular_price'] );
        }
        if ( isset( $mapped['sale_price'] ) && (string) $mapped['sale_price'] !== (string) $product->get_sale_price() ) {
            $changes['sale_price'] = array( 'from' => $product->get_sale_price(), 'to' => (string) $mapped['sale_price'] );
            $product->set_sale_price( (string) $mapped['sale_price'] );
        }
        if ( isset( $mapped['stock'] ) && (int) $mapped['stock'] !== (int) $product->get_stock_quantity() ) {
            $changes['stock'] = array( 'from' => $product->get_stock_quantity(), 'to' => (int) $mapped['stock'] );
            $product->set_manage_stock( true );
            $product->set_stock_quantity( (int) $mapped['stock'] );
        }
        if ( isset( $mapped['weight'] ) && (string) $mapped['weight'] !== (string) $product->get_weight() ) {
            $changes['weight'] = array( 'from' => $product->get_weight(), 'to' => (string) $mapped['weight'] );
            $product->set_weight( $mapped['weight'] );
        }
        if ( isset( $mapped['dimensions'] ) && is_array( $mapped['dimensions'] ) ) {
            $existing_dimensions = $product->get_dimensions( false );
            if ( $existing_dimensions !== $mapped['dimensions'] ) {
                $changes['dimensions'] = array( 'from' => $existing_dimensions, 'to' => $mapped['dimensions'] );
                $product->set_dimensions( $mapped['dimensions'] );
            }
        }

        if ( 'publish' !== $product->get_status() ) {
            $changes['status'] = array( 'from' => $product->get_status(), 'to' => 'publish' );
            $product->set_status( 'publish' );
        }
        if ( 'visible' !== $product->get_catalog_visibility() ) {
            $changes['catalog_visibility'] = array( 'from' => $product->get_catalog_visibility(), 'to' => 'visible' );
            $product->set_catalog_visibility( 'visible' );
        }

        $product_id = $product->save();
        if ( ! $product_id ) {
            $this->logger->error(
                'Product creation failed',
                $provider_id,
                array(
                    'mapped' => $mapped,
                    'external_id' => $external_id,
                )
            );
            return array(
                'action' => 'error',
                'product_id' => null,
            );
        }

        $product = wc_get_product( $product_id );
        if ( $product ) {
            $this->logger->info(
                'Product status set',
                $provider_id,
                array(
                    'product_id' => $product_id,
                    'status' => $product->get_status(),
                )
            );
        }

        if ( ! $has_variations ) {
            $result = wp_set_object_terms( $product_id, 'simple', 'product_type' );
            if ( is_wp_error( $result ) ) {
                $this->logger->error( 'Failed to set product type', $provider_id, array_merge( $context, array( 'error' => $result->get_error_message() ) ) );
            }
        }

        update_post_meta( $product_id, '_external_provider_id', $provider_id );
        update_post_meta( $product_id, '_external_product_id', (string) $external_id );

        if ( ! empty( $external_id ) ) {
            $map_result = $this->db->upsert_external_map( $provider_id, $external_id, $product_id );
            if ( 'race' === $map_result['status'] ) {
                $this->logger->warning(
                    'External map insert race handled',
                    $provider_id,
                    array_merge(
                        $context,
                        array(
                            'product_id' => $product_id,
                            'existing_id' => $map_result['existing_id'] ?? null,
                        )
                    )
                );
            } elseif ( 'error' === $map_result['status'] ) {
                $this->logger->error(
                    'External map update failed',
                    $provider_id,
                    array_merge(
                        $context,
                        array(
                            'product_id' => $product_id,
                            'error' => $map_result['error'] ?? null,
                        )
                    )
                );
            }
        } else {
            $this->logger->warning(
                'External ID missing; external map update skipped',
                $provider_id,
                array_merge( $context, array( 'product_id' => $product_id ) )
            );
        }

        if ( ! empty( $mapped['images'] ) && is_array( $mapped['images'] ) ) {
            $image_result = $this->media->sync_product_images( $product_id, $mapped['images'], $context );
            if ( $image_result['updated'] ) {
                $changes['images'] = array(
                    'count' => count( $image_result['attachment_ids'] ),
                );
            }
        }

        if ( ! empty( $mapped['categories'] ) && is_array( $mapped['categories'] ) ) {
            $existing_terms = wp_get_object_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
            $mapped_categories = array_values( array_unique( $mapped['categories'] ) );
            sort( $existing_terms );
            sort( $mapped_categories );
            if ( $existing_terms !== $mapped_categories ) {
                wp_set_object_terms( $product_id, $mapped['categories'], 'product_cat', false );
                $changes['categories'] = array( 'from' => $existing_terms, 'to' => $mapped_categories );
            }
        }

        if ( ! empty( $mapped['attributes'] ) && is_array( $mapped['attributes'] ) ) {
            $attributes_updated = $this->set_product_attributes( $product_id, $mapped['attributes'] );
            if ( $attributes_updated ) {
                $changes['attributes'] = array( 'updated' => true );
            }
        }

        if ( $has_variations ) {
            $this->set_variable_product( $product_id, $mapped['variations'], $mapped['attributes'] ?? array(), $context );
        }

        do_action( 'wc_mas_post_product_save', $product_id, $mapped, $payload, $provider_id );

        $action = $is_update ? ( empty( $changes ) ? 'skipped' : 'updated' ) : 'created';
        $log_message = 'Product created';
        if ( 'updated' === $action ) {
            $log_message = 'Product updated';
        } elseif ( 'skipped' === $action ) {
            $log_message = 'Product skipped (no changes)';
        }

        $this->logger->info(
            $log_message,
            $provider_id,
            array(
                'product_id' => $product_id,
                'external_id' => $external_id,
                'changes' => $changes,
            )
        );

        return array(
            'action' => $action,
            'product_id' => $product_id,
            'changes' => $changes,
        );
    }

    private function resolve_unique_sku( $sku, $product_id, $provider_id, $external_id ) {
        if ( empty( $sku ) ) {
            return null;
        }

        $existing_id = wc_get_product_id_by_sku( $sku );
        if ( $existing_id && (int) $existing_id !== (int) $product_id ) {
            $existing_provider = get_post_meta( $existing_id, '_external_provider_id', true );
            $existing_external = get_post_meta( $existing_id, '_external_product_id', true );
            $matches_external = (string) $existing_provider === (string) $provider_id && (string) $existing_external === (string) $external_id;

            if ( $matches_external ) {
                return $sku;
            }

            if ( $product_id ) {
                $this->logger->warning(
                    'SKU conflict detected, keeping existing SKU',
                    $provider_id,
                    array(
                        'product_id' => $product_id,
                        'sku' => $sku,
                        'conflict_product_id' => $existing_id,
                    )
                );
                return null;
            }

            $suffix = 0;
            $candidate = $sku;
            do {
                $suffix++;
                $candidate = sprintf( '%s-%s-%s%s', $sku, $provider_id, $external_id, $suffix > 1 ? '-' . $suffix : '' );
            } while ( wc_get_product_id_by_sku( $candidate ) );

            $this->logger->warning(
                'SKU conflict detected, using unique fallback',
                $provider_id,
                array(
                    'original_sku' => $sku,
                    'sku' => $candidate,
                    'conflict_product_id' => $existing_id,
                )
            );
            return $candidate;
        }

        return $sku;
    }

    private function prefix_provider_sku( $sku, $provider_id ) {
        $prefix = $provider_id . '-';
        if ( str_starts_with( $sku, $prefix ) ) {
            return $sku;
        }

        return $prefix . $sku;
    }

    private function prefix_provider_title( $title, $provider_id ) {
        $prefix = $provider_id . '-';
        if ( str_starts_with( $title, $prefix ) ) {
            return $title;
        }

        return $prefix . $title;
    }

    
    private function set_product_attributes( $product_id, $attributes ) {
        $product_attributes = array();
        foreach ( $attributes as $name => $options ) {
            $taxonomy = wc_sanitize_taxonomy_name( $name );
            $attribute_id = wc_attribute_taxonomy_id_by_name( $taxonomy );
            if ( ! $attribute_id ) {
                wc_create_attribute(
                    array(
                        'name' => $name,
                        'slug' => $taxonomy,
                        'type' => 'select',
                        'order_by' => 'menu_order',
                        'has_archives' => false,
                    )
                );
                $attribute_id = wc_attribute_taxonomy_id_by_name( $taxonomy );
            }

            $options = is_array( $options ) ? $options : array( $options );
            $terms = array();
            foreach ( $options as $option ) {
                if ( ! term_exists( $option, $taxonomy ) ) {
                    wp_insert_term( $option, $taxonomy );
                }
                $terms[] = $option;
            }

            wp_set_object_terms( $product_id, $terms, $taxonomy );
            $product_attributes[ $taxonomy ] = array(
                'name' => $taxonomy,
                'value' => implode( ' | ', $terms ),
                'position' => 0,
                'is_visible' => 1,
                'is_variation' => 1,
                'is_taxonomy' => 1,
            );
        }
        $existing_attributes = get_post_meta( $product_id, '_product_attributes', true );
        if ( $existing_attributes !== $product_attributes ) {
            update_post_meta( $product_id, '_product_attributes', $product_attributes );
            return true;
        }
        return false;
    }

    private function set_variable_product( $product_id, $variations, $attributes, $context = array() ) {
        $result = wp_set_object_terms( $product_id, 'variable', 'product_type' );
        if ( is_wp_error( $result ) ) {
            $this->logger->error( 'Failed to set product type', $context['provider_id'] ?? null, array_merge( $context, array( 'error' => $result->get_error_message() ) ) );
        }
        $parent = wc_get_product( $product_id );
        if ( ! $parent || ! $parent instanceof WC_Product_Variable ) {
            $parent = new WC_Product_Variable( $product_id );
        }

        foreach ( $variations as $variation_data ) {
            if ( empty( $variation_data['sku'] ) ) {
                $this->logger->warning( 'Skipping variation: SKU empty', $context['provider_id'] ?? null, $context );
                continue;
            }
            $variation_id = wc_get_product_id_by_sku( $variation_data['sku'] );
            $variation = $variation_id ? new WC_Product_Variation( $variation_id ) : new WC_Product_Variation();
            $variation->set_parent_id( $product_id );
            $variation->set_sku( $variation_data['sku'] );
            if ( isset( $variation_data['price'] ) ) {
                $variation->set_regular_price( $variation_data['price'] );
            }
            if ( isset( $variation_data['stock'] ) ) {
                $variation->set_manage_stock( true );
                $variation->set_stock_quantity( (int) $variation_data['stock'] );
            }

            $attrs = array();
            if ( ! empty( $variation_data['attributes'] ) && is_array( $variation_data['attributes'] ) ) {
                foreach ( $variation_data['attributes'] as $name => $value ) {
                    $taxonomy = wc_sanitize_taxonomy_name( $name );
                    $attrs[ $taxonomy ] = $value;
                }
            }
            $variation->set_attributes( $attrs );
            $variation->save();
        }
    }
}
