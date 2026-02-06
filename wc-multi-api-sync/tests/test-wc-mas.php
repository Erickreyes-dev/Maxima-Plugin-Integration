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
        $sync = WC_MAS_Sync::get_instance();
        $mapped = array( 'title' => 'Test Product', 'sku' => 'SKU-1', 'regular_price' => '12.00' );
        $sync->create_or_update_product_by_sku( $mapped, array(), 1 );
        $product_id = wc_get_product_id_by_sku( 'SKU-1' );
        $this->assertNotEmpty( $product_id );
    }

    public function test_enqueue_notification_on_order_complete() {
        if ( ! class_exists( 'ActionScheduler' ) || ! class_exists( 'WC_Product' ) ) {
            $this->markTestSkipped( 'Action Scheduler or WooCommerce not available.' );
        }
        $product = new WC_Product();
        $product->set_name( 'Notify Product' );
        $product->set_sku( 'SKU-2' );
        $product_id = $product->save();
        update_post_meta( $product_id, '_wcmas_provider_id', 1 );

        $order = wc_create_order();
        $order->add_product( wc_get_product( $product_id ), 1 );
        $order->calculate_totals();
        $order->save();

        WC_MAS_Order_Hooks::get_instance()->handle_order_completed( $order->get_id() );
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
