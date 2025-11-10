<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists('wdss29_render_checkout_session_page') ) {
    function wdss29_render_checkout_session_page() {
        if ( ! is_user_logged_in() ) wp_die( 'Please log in to continue.' );

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        if ( ! $order_id ) wp_die( 'Missing order_id.' );
        if ( ! function_exists('wdss29_get_order') ) wp_die( 'Orders API not available.' );

        $order = wdss29_get_order( $order_id );
        if ( ! is_array($order) ) wp_die( 'Order not found.' );

        $settings = get_option('wdss29_settings', array());
        $stripe_sk = isset($settings['stripe_sk']) ? trim($settings['stripe_sk']) : '';
        if ( empty($stripe_sk) ) wp_die( 'Stripe secret key missing in settings.' );

        $currency = ! empty($order['currency']) ? strtolower($order['currency']) : 'usd';

        $thankyou_page_id = isset($settings['thankyou_page_id']) ? absint($settings['thankyou_page_id']) : 0;
        $cancel_page_id   = isset($settings['cancel_page_id'])   ? absint($settings['cancel_page_id'])   : 0;

        $base_success = $thankyou_page_id ? get_permalink($thankyou_page_id) : home_url('/thank-you/');
        $cancel_url   = $cancel_page_id   ? get_permalink($cancel_page_id)   : home_url('/cart/');

        $user_id     = get_current_user_id();
        $success_url = function_exists('wdss29_build_success_url')
            ? wdss29_build_success_url( $base_success, $order_id, $user_id )
            : add_query_arg( array( 'wdss_success' => 1, 'order_id' => (int)$order_id ), $base_success );

        $items = array();
        $raw_items = isset($order['items']) ? (is_array($order['items']) ? $order['items'] : maybe_unserialize($order['items'])) : array();
        if ( is_array($raw_items) && ! empty($raw_items) ) {
            foreach ( $raw_items as $it ) {
                $name  = isset($it['title']) ? sanitize_text_field($it['title']) : 'Item';
                $price = isset($it['price']) ? (float)$it['price'] : 0.0;
                $qty   = isset($it['qty'])   ? max(1, (int)$it['qty']) : 1;
                $unit_amount = (int) round( $price * 100 );
                if ( $unit_amount <= 0 ) continue;

                $items[] = array(
                    'price_data' => array(
                        'currency'     => $currency,
                        'product_data' => array('name' => $name),
                        'unit_amount'  => $unit_amount,
                    ),
                    'quantity'   => $qty,
                );
            }
        }
        if ( empty($items) ) {
            $total_cents = (int) round( (float)$order['total'] * 100 );
            if ( $total_cents <= 0 ) wp_die( 'Order total is zero; nothing to charge.' );
            $items[] = array(
                'price_data' => array(
                    'currency'     => $currency,
                    'product_data' => array('name' => sprintf( 'Order #%s', ! empty($order['number']) ? $order['number'] : $order_id )),
                    'unit_amount'  => $total_cents,
                ),
                'quantity' => 1,
            );
        }

        $customer_email = ! empty($order['customer_email']) ? sanitize_email($order['customer_email']) : '';
        if ( ! $customer_email && is_email( wp_get_current_user()->user_email ) ) {
            $customer_email = wp_get_current_user()->user_email;
        }

        if ( ! class_exists('\Stripe\Stripe') ) wp_die( 'Stripe SDK not loaded.' );
        \Stripe\Stripe::setApiKey( $stripe_sk );

        try {
            $session = \Stripe\Checkout\Session::create(array(
                'mode'        => 'payment',
                'line_items'  => $items,
                'success_url' => $success_url, // signed URL
                'cancel_url'  => $cancel_url,
                'metadata' => array('order_id' => (string) $order_id),
                'client_reference_id' => (string) $order_id,
                'payment_intent_data' => array(
                    'metadata'      => array( 'order_id' => (string) $order_id ),
                    'receipt_email' => $customer_email,
                ),
                'customer_email' => $customer_email ?: null,
            ));

            if ( ! headers_sent() ) {
                wp_redirect( $session->url, 302 );
                exit;
            } else {
                echo '<script>window.location.href='.json_encode($session->url).';</script>';
                exit;
            }
        } catch ( \Exception $e ) {
            wp_die( 'Stripe error: ' . esc_html( $e->getMessage() ) );
        }
    }
}

if ( ! shortcode_exists('wdss_stripe_checkout') ) {
    add_shortcode( 'wdss_stripe_checkout', function(){
        ob_start();
        wdss29_render_checkout_session_page();
        return ob_get_clean();
    } );
}
