<?php
/**
 * Stripe gateway — capture + single post-payment event
 * Author: Warf Designs LLC
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WDSS29_Stripe' ) ) :

class WDSS29_Stripe {

    /** @var self */
    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Example: if you have a webhook endpoint, point it here (route to handle_webhook()).
        add_action( 'rest_api_init', array( $this, 'register_rest' ) );
    }

    public function register_rest() {
        register_rest_route( 'wdss29/v1', '/stripe/webhook', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'handle_webhook' ),
            'permission_callback' => '__return_true', // Add your verification if needed.
        ) );
    }

    /**
     * Minimal Stripe webhook: listens for a succeeded payment intent,
     * finds your local order, marks it paid ONCE and fires canonical hook.
     *
     * NOTE: Replace the "order_id" lookups with your actual mapping.
     */
    public function handle_webhook( $request ) {
        $data = json_decode( $request->get_body(), true );
        if ( ! is_array( $data ) ) {
            return new WP_REST_Response( array( 'ok' => false ), 400 );
        }

        $type = $data['type'] ?? '';
        if ( $type === 'payment_intent.succeeded' ) {
            $pi = $data['data']['object'] ?? array();

            // ---- Replace this with your real linkage from Stripe to local order ----
            // For example: metadata.order_id was set when creating the PaymentIntent.
            $order_id = (int) ( $pi['metadata']['order_id'] ?? 0 );
            // -----------------------------------------------------------------------

            if ( $order_id > 0 ) {
                // Build payload used by email placeholders
                $order = function_exists('wdss29_get_order') ? wdss29_get_order( $order_id ) : null;
                $payload = array();
                if ( $order ) {
                    $payload = array(
                        'customer_email' => $order['customer_email'] ?? '',
                        'customer_name'  => $order['customer_name'] ?? '',
                        'order_total'    => $order['total'] ?? 0,
                    );
                }

                // Canonical: set to 'paid' (fires wdss29_order_paid inside, once)
                if ( function_exists( 'wdss29_set_order_status' ) ) {
                    wdss29_set_order_status( $order_id, 'paid', $payload );
                } else {
                    // Fallback: still fire the paid hook once if status helper isn't loaded
                    do_action( 'wdss29_order_paid', $order_id, $payload );
                }
            }
        }

        return new WP_REST_Response( array( 'ok' => true ), 200 );
    }
}

endif;

// Bootstrap
WDSS29_Stripe::instance();
