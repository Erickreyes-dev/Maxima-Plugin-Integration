<?php
/**
 * Order hooks to notify providers on completion.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_MAS_Order_Hooks {
    private static $instance;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_completed' ), 10, 1 );
        add_action( 'woocommerce_payment_complete', array( $this, 'handle_order_completed' ), 10, 1 );
    }

    public function handle_order_completed( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();
            $provider_id = (int) get_post_meta( $product_id, '_wcmas_provider_id', true );
            if ( ! $provider_id ) {
                continue;
            }

            $payload = array(
                'order_id' => $order_id,
                'order_key' => $order->get_order_key(),
                'sku' => $item->get_product()->get_sku(),
                'quantity' => $item->get_quantity(),
                'price' => $order->get_item_total( $item, false ),
                'currency' => $order->get_currency(),
                'customer' => array(
                    'name' => $order->get_formatted_billing_full_name(),
                    'email' => $order->get_billing_email(),
                ),
                'timestamp' => gmdate( 'c' ),
            );

            if ( class_exists( 'ActionScheduler' ) ) {
                as_enqueue_async_action( WC_MAS_Sync::ACTION_NOTIFY, array( $provider_id, $payload, 1 ), 'wc-mas' );
            }
        }
    }
}
