<?php
/**
 * Media handling for external images.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_MAS_Media {
    private $logger;

    public function __construct() {
        $this->logger = WC_MAS_Logger::get_instance();
    }

    public function get_or_create_attachment( $image_url, $product_id, $context = array() ) {
        $image_url = esc_url_raw( $image_url );
        if ( empty( $image_url ) ) {
            return null;
        }

        $image_hash = md5( $image_url );
        $existing_id = $this->get_attachment_by_hash( $image_hash );
        if ( ! $existing_id ) {
            $existing_id = $this->get_attachment_by_external_url( $image_url );
        }
        if ( ! $existing_id ) {
            $existing_id = $this->get_attachment_by_guid( $image_url );
        }

        if ( $existing_id ) {
            if ( ! get_post_meta( $existing_id, '_external_image_url', true ) ) {
                update_post_meta( $existing_id, '_external_image_url', $image_url );
            }
            if ( ! get_post_meta( $existing_id, '_external_image_hash', true ) ) {
                update_post_meta( $existing_id, '_external_image_hash', $image_hash );
            }
            return (int) $existing_id;
        }

        return $this->sideload_image( $image_url, $product_id, $context );
    }

    public function sync_product_images( $product_id, $image_urls, $context = array() ) {
        $image_urls = $this->normalize_image_urls( $image_urls );
        if ( empty( $image_urls ) ) {
            return array(
                'updated' => false,
                'attachment_ids' => array(),
            );
        }

        $attachment_ids = array();
        $errors = array();

        foreach ( $image_urls as $image_url ) {
            $attachment_id = $this->get_or_create_attachment( $image_url, $product_id, $context );
            if ( ! $attachment_id ) {
                $errors[] = $image_url;
                continue;
            }
            $attachment_ids[] = (int) $attachment_id;
        }

        if ( $errors ) {
            $this->logger->error(
                'Image sideload failed',
                $context['provider_id'] ?? null,
                array_merge(
                    $context,
                    array(
                        'product_id' => $product_id,
                        'urls' => $errors,
                    )
                )
            );
        }

        if ( ! $attachment_ids ) {
            return array(
                'updated' => false,
                'attachment_ids' => array(),
            );
        }

        $current_thumbnail = (int) get_post_thumbnail_id( $product_id );
        $current_gallery = get_post_meta( $product_id, '_product_image_gallery', true );
        $current_gallery_ids = $current_gallery ? array_map( 'intval', explode( ',', $current_gallery ) ) : array();
        $current_ids = array_filter( array_merge( array( $current_thumbnail ), $current_gallery_ids ) );

        $updated = $current_ids !== $attachment_ids;

        if ( $updated ) {
            set_post_thumbnail( $product_id, $attachment_ids[0] );
            update_post_meta( $product_id, '_product_image_gallery', implode( ',', array_slice( $attachment_ids, 1 ) ) );
            update_post_meta( $product_id, '_external_image_url', $image_urls[0] );
        }

        return array(
            'updated' => $updated,
            'attachment_ids' => $attachment_ids,
        );
    }

    /**
     * Normaliza una lista de imágenes para soportar strings, arrays de objetos y listas separadas por coma.
     */
    private function normalize_image_urls( $image_urls ) {
        $normalized = array();

        foreach ( (array) $image_urls as $image_item ) {
            if ( is_string( $image_item ) ) {
                $parts = preg_split( '/\s*,\s*/', $image_item );
                foreach ( $parts as $part ) {
                    $url = esc_url_raw( trim( $part ) );
                    if ( $url ) {
                        $normalized[] = $url;
                    }
                }
                continue;
            }

            if ( is_array( $image_item ) || is_object( $image_item ) ) {
                $candidate = $this->extract_image_url_from_item( $image_item );
                if ( $candidate ) {
                    $normalized[] = $candidate;
                }
            }
        }

        return array_values( array_unique( $normalized ) );
    }

    /**
     * Extrae URL de imagen desde estructuras comunes de APIs (url, src, image, href, etc).
     */
    private function extract_image_url_from_item( $image_item ) {
        $keys = array( 'url', 'src', 'image', 'href', 'link', 'secure_url' );

        foreach ( $keys as $key ) {
            $value = null;
            if ( is_array( $image_item ) && array_key_exists( $key, $image_item ) ) {
                $value = $image_item[ $key ];
            } elseif ( is_object( $image_item ) && isset( $image_item->{$key} ) ) {
                $value = $image_item->{$key};
            }

            if ( ! is_string( $value ) ) {
                continue;
            }

            $url = esc_url_raw( trim( $value ) );
            if ( $url ) {
                return $url;
            }
        }

        return null;
    }

    private function get_attachment_by_external_url( $image_url ) {
        $existing = get_posts(
            array(
                'post_type' => 'attachment',
                'meta_key' => '_external_image_url',
                'meta_value' => $image_url,
                'posts_per_page' => 1,
                'fields' => 'ids',
            )
        );
        if ( $existing ) {
            return (int) $existing[0];
        }
        return null;
    }

    private function get_attachment_by_hash( $image_hash ) {
        $existing = get_posts(
            array(
                'post_type' => 'attachment',
                'meta_key' => '_external_image_hash',
                'meta_value' => $image_hash,
                'posts_per_page' => 1,
                'fields' => 'ids',
            )
        );
        if ( $existing ) {
            return (int) $existing[0];
        }
        return null;
    }

    private function get_attachment_by_guid( $image_url ) {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE guid = %s AND post_type = 'attachment' LIMIT 1",
                $image_url
            )
        );
    }

    private function sideload_image( $image_url, $product_id, $context = array() ) {
        $preloaded = apply_filters( 'wc_mas_media_sideload', null, $image_url, $product_id, $context );
        if ( null !== $preloaded ) {
            if ( is_wp_error( $preloaded ) ) {
                return null;
            }
            return (int) $preloaded;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_sideload_image( $image_url, $product_id, null, 'id' );
        if ( is_wp_error( $attachment_id ) ) {
            $this->logger->error(
                'Image sideload failed',
                $context['provider_id'] ?? null,
                array_merge(
                    $context,
                    array(
                        'url' => $image_url,
                        'error' => $attachment_id->get_error_message(),
                    )
                )
            );
            return null;
        }

        update_post_meta( $attachment_id, '_external_image_url', $image_url );
        update_post_meta( $attachment_id, '_external_image_hash', md5( $image_url ) );

        $this->logger->info(
            'Image attached',
            $context['provider_id'] ?? null,
            array_merge(
                $context,
                array(
                    'url' => $image_url,
                    'attachment_id' => $attachment_id,
                )
            )
        );

        return (int) $attachment_id;
    }
}
