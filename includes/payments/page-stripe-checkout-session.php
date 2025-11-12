<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists('wdss29_render_checkout_session_page') ) {
    function wdss29_render_checkout_session_page() {
        // Ensure no output before headers (important for setcookie + redirects)
        if ( function_exists('ob_get_level') && ob_get_level() > 0 ) {
            while ( ob_get_level() ) { @ob_end_clean(); }
        }
        nocache_headers();

        // Get order
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        if ( ! $order_id ) wp_die( 'Missing order_id.' );
        if ( ! function_exists('wdss29_get_order') ) wp_die( 'Orders API not available.' );

        $order = wdss29_get_order( $order_id );
        if ( ! is_array($order) ) wp_die( 'Order not found.' );

        // Settings / keys
        $settings  = get_option('wdss29_settings', array());
        $stripe_sk = isset($settings['stripe_sk']) ? trim($settings['stripe_sk']) : '';
        if ( empty($stripe_sk) ) wp_die( 'Stripe secret key missing in settings.' );

        // Currency
        $currency = ! empty($order['currency']) ? strtolower($order['currency']) : 'usd';

        // Your pages
        $base_success = home_url('/regiss/checkout-success/');
        $cancel_url   = home_url('/regiss/cart/');

        // Logged-in user id (0 if guest)
        $user_id = get_current_user_id();

        // Signed guard (prevents spoofing if params are used on success)
        if ( function_exists('wdss29_build_success_key') ) {
            $signed_key = wdss29_build_success_key( $order_id, $user_id );
        } else {
            $signed_key = hash_hmac( 'sha256', (int)$order_id . '|' . (int)$user_id, wp_salt('auth') );
        }

        // Build success URL manually so the {CHECKOUT_SESSION_ID} braces remain intact for Stripe
        $success_url = rtrim($base_success, '/') . '/?wdss29=success'
            . '&order_id=' . (int)$order_id
            . '&uid=' . (int)$user_id
            . '&key=' . rawurlencode($signed_key)
            . '&session_id={CHECKOUT_SESSION_ID}';

        // Build line items from order
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

        // Fallback: single line for the order total when itemization isn't available
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

        // Customer email if known (helps Stripe send receipts; optional)
        $customer_email = ! empty($order['customer_email']) ? sanitize_email($order['customer_email']) : '';
        if ( ! $customer_email && is_user_logged_in() && is_email( wp_get_current_user()->user_email ) ) {
            $customer_email = wp_get_current_user()->user_email;
        }

        // Stripe
        if ( ! class_exists('\Stripe\Stripe') ) wp_die( 'Stripe SDK not loaded.' );
        \Stripe\Stripe::setApiKey( $stripe_sk );

        try {
            $params = array(
                'mode'        => 'payment',
                'line_items'  => $items,
                'success_url' => $success_url, // includes {CHECKOUT_SESSION_ID} + wdss29=success guard
                'cancel_url'  => $cancel_url,
                'metadata'    => array('order_id' => (string) $order_id),
                'client_reference_id' => (string) $order_id,
                'payment_intent_data' => array(
                    'metadata' => array( 'order_id' => (string) $order_id ),
                ),
            );
            if ( $customer_email ) {
                $params['customer_email'] = $customer_email;
                $params['payment_intent_data']['receipt_email'] = $customer_email;
            }

            $session = \Stripe\Checkout\Session::create( $params );

            // === Fallback linkages: remember order in multiple places ===

            // (1) Logged-in pointer
            if ( is_user_logged_in() ) {
                update_user_meta( $user_id, '_wdss_last_order', (int)$order_id );
            }

            // (2) Cookie (SameSite=Lax) – good for guests & logged-in users
            @setcookie(
                'wdss_last_order',
                (string) (int) $order_id,
                array(
                    'expires'  => time() + 1800,
                    'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
                    'domain'   => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                )
            );

            // (3) Short-lived transient keyed by the visitor's IP (final safety net)
            $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
            if ( $ip ) {
                set_transient( 'wdss_last_order_ip_' . md5($ip), (int)$order_id, 30 * MINUTE_IN_SECONDS );
            }

            // Helpful breadcrumbs in your PHP error log + your debug bus
            if ( function_exists('error_log') ) {
                error_log('[WDSS] success_url sent to Stripe: ' . $success_url);
                error_log('[WDSS] Stripe Checkout URL: ' . $session->url);
                error_log('[WDSS] saved last order pointers: uid=' . (int)$user_id . ' cookie+ip=' . ($ip ? 'yes' : 'no') );
            }
            do_action( 'wdss_email_trigger', 'order.debug', array(
                'note'         => 'checkout_session_created',
                'order_id'     => (int) $order_id,
                'has_email'    => (bool) $customer_email,
                'success_url'  => $success_url,
            ) );

            // Redirect to Stripe Checkout (Stripe must host the payment page)
            if ( ! headers_sent() ) {
                wp_safe_redirect( $session->url, 302 );
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
