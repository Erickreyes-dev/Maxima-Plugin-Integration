<?php
/**
 * Order hooks to notify providers on payment completion.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_MAS_Order_Hooks {
    private static $instance;
    private $logger;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->logger = WC_MAS_Logger::get_instance();

        // Hook al pago completado (automático) en lugar de completed manual
        add_action( 'woocommerce_payment_complete', array( $this, 'handle_order_payment_complete' ), 10, 1 );
    }

    /**
     * Maneja el pago completado y notifica a los proveedores
     */
    public function handle_order_payment_complete( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $grouped = array();

        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();
            $provider_id = (int) get_post_meta( $product_id, '_external_provider_id', true );

            if ( ! $provider_id ) {
                continue;
            }

            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }

            // SKU original que entiende la API del proveedor
            $external_product_id = get_post_meta( $product_id, '_external_product_id', true );
            if ( empty( $external_product_id ) ) {
                $this->logger->warning(
                    'Order item missing external product id',
                    $provider_id,
                    array(
                        'order_id' => $order_id,
                        'product_id' => $product_id,
                    )
                );
                continue;
            }

            // Agrupar por proveedor
            $grouped[ $provider_id ][] = array(
                'product_id' => $external_product_id,
                'qty'        => $item->get_quantity(),
                'price'      => $item->get_total(),
            );
        }

        // Enviar payload a cada proveedor
        foreach ( $grouped as $provider_id => $items ) {
            $payload = array(
                'order_id'   => $order_id,
                'order_key'  => $order->get_order_key(),
                'currency'   => $order->get_currency(),
                'customer'   => array(
                    'name'  => $order->get_formatted_billing_full_name(),
                    'email' => $order->get_billing_email(),
                ),
                'items'      => $items,
                'timestamp'  => gmdate( 'c' ),
            );

            if ( class_exists( 'ActionScheduler' ) ) {
                as_enqueue_async_action( WC_MAS_Sync::ACTION_NOTIFY, array( $provider_id, $payload, 1 ), 'wc-mas' );
            }
        }
    }
}
