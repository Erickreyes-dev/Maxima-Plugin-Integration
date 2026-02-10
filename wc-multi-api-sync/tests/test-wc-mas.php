<?php
/**
 * Basic unit tests for WC Multi API Sync.
 */

class WC_MAS_Tests extends WP_UnitTestCase {
    public function test_get_value_from_path() {
        $mapper = new WC_MAS_Mapper();
        $data = array( 'images' => array( array( 'url' => 'https://example.com/img.jpg' ) ) );
        $this->assertSame( 'https://example.com/img.jpg', $mapper->get_value_from_path( $data, 'images.0.url' ) );
    }

    public function test_map_product() {
        $mapper = new WC_MAS_Mapper();
        $payload = array( 'title' => 'Camiseta', 'sku' => 'ABC', 'price' => '10.5' );
        $mapping = array(
            'title' => array( 'path' => 'title' ),
            'sku' => array( 'path' => 'sku' ),
            'regular_price' => array( 'path' => 'price', 'transform' => array( 'float' => true ) ),
        );
        $result = $mapper->map_product( $payload, $mapping, 1 );
        $this->assertSame( 'Camiseta', $result['title'] );
        $this->assertSame( 'ABC', $result['sku'] );
        $this->assertSame( 10.5, $result['regular_price'] );
    }

    public function test_create_or_update_product_by_sku() {
        if ( ! class_exists( 'WC_Product' ) ) {
            $this->markTestSkipped( 'WooCommerce not available.' );
        }
        $adapter = new WC_MAS_Woo_Adapter();
        $mapped = array( 'title' => 'Test Product', 'sku' => 'SKU-1', 'regular_price' => '12.00' );
        $adapter->create_or_update_product_by_sku( $mapped, array(), 1 );
        $product_id = wc_get_product_id_by_sku( 'SKU-1' );
        $this->assertNotEmpty( $product_id );
    }

    public function test_import_without_sku_creates_products_with_external_meta() {
        if ( ! class_exists( 'WC_Product' ) ) {
            $this->markTestSkipped( 'WooCommerce not available.' );
        }
        $adapter = new WC_MAS_Woo_Adapter();
        $provider_id = 99;
        for ( $i = 1; $i <= 3; $i++ ) {
            $mapped = array(
                'title' => 'Producto ' . $i,
                'regular_price' => '10.00',
            );
            $payload = array( 'id' => 'ext-' . $i );
            $adapter->create_or_update_product( $mapped, $payload, $provider_id );
        }
        $products = get_posts(
            array(
                'post_type' => 'product',
                'meta_query' => array(
                    array(
                        'key' => '_external_provider_id',
                        'value' => $provider_id,
                    ),
                ),
                'fields' => 'ids',
            )
        );
        $this->assertCount( 3, $products );
    }

    public function test_reimport_updates_only_price() {
        if ( ! class_exists( 'WC_Product' ) ) {
            $this->markTestSkipped( 'WooCommerce not available.' );
        }
        $adapter = new WC_MAS_Woo_Adapter();
        $provider_id = 77;
        $payload = array( 'id' => 'A-1' );
        $mapped = array(
            'title' => 'Producto Precio',
            'regular_price' => '10.00',
            'description' => 'Original',
        );
        $adapter->create_or_update_product( $mapped, $payload, $provider_id );

        $mapped_updated = array(
            'title' => 'Producto Precio',
            'regular_price' => '12.00',
            'description' => 'Original',
        );
        $result = $adapter->create_or_update_product( $mapped_updated, $payload, $provider_id );
        $product = wc_get_product( $result['product_id'] );

        $this->assertSame( '12', (string) $product->get_regular_price() );
        $this->assertSame( 'Original', $product->get_description() );
    }

    public function test_image_dedupe_and_update() {
        if ( ! class_exists( 'WC_Product' ) ) {
            $this->markTestSkipped( 'WooCommerce not available.' );
        }

        add_filter(
            'wc_mas_media_sideload',
            function ( $preloaded, $image_url, $product_id ) {
                $attachment_id = wp_insert_attachment(
                    array(
                        'post_title' => 'Test Attachment',
                        'post_type' => 'attachment',
                        'post_status' => 'inherit',
                        'guid' => $image_url,
                    ),
                    null,
                    $product_id
                );
                update_post_meta( $attachment_id, '_external_image_url', $image_url );
                update_post_meta( $attachment_id, '_external_image_hash', md5( $image_url ) );
                return $attachment_id;
            },
            10,
            3
        );

        $adapter = new WC_MAS_Woo_Adapter();
        $provider_id = 55;
        $payload = array( 'id' => 'IMG-1' );
        $mapped = array(
            'title' => 'Producto Imagen',
            'regular_price' => '8.00',
            'images' => array( 'https://example.com/image-1.jpg' ),
        );

        $first = $adapter->create_or_update_product( $mapped, $payload, $provider_id );
        $first_thumb = get_post_thumbnail_id( $first['product_id'] );
        $initial_attachments = get_posts( array( 'post_type' => 'attachment', 'fields' => 'ids' ) );

        $second = $adapter->create_or_update_product( $mapped, $payload, $provider_id );
        $second_thumb = get_post_thumbnail_id( $second['product_id'] );
        $after_same = get_posts( array( 'post_type' => 'attachment', 'fields' => 'ids' ) );

        $this->assertSame( $first_thumb, $second_thumb );
        $this->assertCount( count( $initial_attachments ), $after_same );

        $mapped_changed = array(
            'title' => 'Producto Imagen',
            'regular_price' => '8.00',
            'images' => array( 'https://example.com/image-2.jpg' ),
        );
        $third = $adapter->create_or_update_product( $mapped_changed, $payload, $provider_id );
        $third_thumb = get_post_thumbnail_id( $third['product_id'] );
        $after_changed = get_posts( array( 'post_type' => 'attachment', 'fields' => 'ids' ) );

        $this->assertNotSame( $first_thumb, $third_thumb );
        $this->assertGreaterThan( count( $after_same ), count( $after_changed ) );

        remove_all_filters( 'wc_mas_media_sideload' );
    }

    public function test_external_map_race_condition() {
        $db = WC_MAS_DB::get_instance();
        if ( ! $db->external_map_table_exists() ) {
            $this->markTestSkipped( 'External map table not available.' );
        }

        $provider_id = 11;
        $external_id = 'race-1';
        $result_one = $db->upsert_external_map( $provider_id, $external_id, 100 );
        $result_two = $db->upsert_external_map( $provider_id, $external_id, 101 );

        $this->assertNotEmpty( $result_one['status'] );
        $this->assertNotEmpty( $result_two['status'] );

        $product_id = $db->get_external_product_id( $provider_id, $external_id );
        $this->assertNotEmpty( $product_id );
    }

    public function test_enqueue_notification_on_order_complete() {
        if ( ! class_exists( 'ActionScheduler' ) || ! class_exists( 'WC_Product' ) ) {
            $this->markTestSkipped( 'Action Scheduler or WooCommerce not available.' );
        }

        $provider_id = WC_MAS_DB::get_instance()->upsert_provider(
            array(
                'name' => 'Provider Notify',
                'base_url' => 'https://example.com',
                'products_endpoint' => '/products',
                'notify_endpoint' => 'https://example.com/notify',
                'notify_status' => 'completed',
                'auth_type' => 'none',
                'auth_config' => wp_json_encode( array() ),
                'headers' => wp_json_encode( array() ),
                'default_params' => wp_json_encode( array() ),
                'sync_frequency' => 'hourly',
                'active' => 1,
            )
        );

        $product = new WC_Product();
        $product->set_name( 'Notify Product' );
        $product->set_sku( 'SKU-2' );
        $product_id = $product->save();
        update_post_meta( $product_id, '_external_provider_id', $provider_id );
        update_post_meta( $product_id, '_external_product_id', 'ext-1' );

        $order = wc_create_order();
        $order->add_product( wc_get_product( $product_id ), 1 );
        $order->calculate_totals();
        $order->save();

        WC_MAS_Order_Hooks::get_instance()->handle_order_completed( $order->get_id() );
        $this->assertTrue( as_has_scheduled_action( WC_MAS_Sync::ACTION_NOTIFY ) );
    }

    public function test_enqueue_notification_on_processing_with_provider_prefixed_sku_fallback() {
        if ( ! function_exists( 'as_has_scheduled_action' ) || ! class_exists( 'WC_Product' ) ) {
            $this->markTestSkipped( 'Action Scheduler or WooCommerce not available.' );
        }

        $provider_id = WC_MAS_DB::get_instance()->upsert_provider(
            array(
                'name' => 'Provider Processing SKU Fallback',
                'base_url' => 'https://example.com',
                'products_endpoint' => '/products',
                'notify_endpoint' => 'https://example.com/notify',
                'notify_status' => 'processing',
                'auth_type' => 'none',
                'auth_config' => wp_json_encode( array() ),
                'headers' => wp_json_encode( array() ),
                'default_params' => wp_json_encode( array() ),
                'sync_frequency' => 'hourly',
                'active' => 1,
            )
        );

        $product = new WC_Product();
        $product->set_name( 'Notify Processing SKU Fallback Product' );
        $product->set_sku( 'SKU-4' );
        $product_id = $product->save();
        update_post_meta( $product_id, '_external_provider_id', $provider_id );
        update_post_meta( $product_id, '_external_provider_sku', $provider_id . '-ext-fallback-1' );
        delete_post_meta( $product_id, '_external_product_id' );

        $order = wc_create_order();
        $order->add_product( wc_get_product( $product_id ), 1 );
        $order->calculate_totals();
        $order->save();

        WC_MAS_Order_Hooks::get_instance()->handle_order_processing( $order->get_id() );
        $this->assertTrue( as_has_scheduled_action( WC_MAS_Sync::ACTION_NOTIFY ) );
    }

    public function test_enqueue_notification_on_processing_with_wc_status_prefix() {
        if ( ! function_exists( 'as_has_scheduled_action' ) || ! class_exists( 'WC_Product' ) ) {
            $this->markTestSkipped( 'Action Scheduler or WooCommerce not available.' );
        }

        $provider_id = WC_MAS_DB::get_instance()->upsert_provider(
            array(
                'name' => 'Provider Processing Notify',
                'base_url' => 'https://example.com',
                'products_endpoint' => '/products',
                'notify_endpoint' => 'https://example.com/notify',
                'notify_status' => 'wc-processing',
                'auth_type' => 'none',
                'auth_config' => wp_json_encode( array() ),
                'headers' => wp_json_encode( array() ),
                'default_params' => wp_json_encode( array() ),
                'sync_frequency' => 'hourly',
                'active' => 1,
            )
        );

        $product = new WC_Product();
        $product->set_name( 'Notify Processing Product' );
        $product->set_sku( 'SKU-3' );
        $product_id = $product->save();
        update_post_meta( $product_id, '_external_provider_id', $provider_id );
        update_post_meta( $product_id, '_external_product_id', 'ext-2' );

        $order = wc_create_order();
        $order->add_product( wc_get_product( $product_id ), 1 );
        $order->calculate_totals();
        $order->save();

        WC_MAS_Order_Hooks::get_instance()->handle_order_processing( $order->get_id() );
        $this->assertTrue( as_has_scheduled_action( WC_MAS_Sync::ACTION_NOTIFY ) );
    }

    public function test_retry_logic_for_failed_notify() {
        if ( ! class_exists( 'ActionScheduler' ) ) {
            $this->markTestSkipped( 'Action Scheduler not available.' );
        }
        $sync = WC_MAS_Sync::get_instance();
        $reflection = new ReflectionClass( $sync );
        $method = $reflection->getMethod( 'schedule_notify_retry' );
        $method->setAccessible( true );
        $method->invoke( $sync, 1, array( 'order_id' => 1 ), 1, 'error' );
        $this->assertTrue( as_has_scheduled_action( WC_MAS_Sync::ACTION_NOTIFY ) );
    }
}
