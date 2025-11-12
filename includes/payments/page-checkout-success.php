<?php
/**
 * WDSS — Checkout Success handler
 * - Resolves the order when the buyer lands on /checkout-success/
 * - Marks it PAID
 * - Emits the normalized "order.paid" email trigger
 *
 * Works for:
 *   • Signed redirect params (?wdss_success=1&order_id=&uid=&key=)
 *   • Cookie fallback (wdss_last_order)
 *   • Logged-in user fallback (_wdss_last_order user meta)
 *   • Optional Stripe Session lookup when session_id is REAL
 */

if ( ! defined('ABSPATH') ) exit;

/** Small helpers */
if ( ! function_exists('wdss29__ap') ) {
    function wdss29__ap( $arr, $path, $default = null ) {
        $cur = $arr;
        foreach ( (array) $path as $k ) {
            if ( ! is_array($cur) || ! array_key_exists($k, $cur) ) return $default;
            $cur = $cur[$k];
        }
        return $cur;
    }
}

/** Build + verify the signed success key (same as in your core) */
if ( ! function_exists('wdss29_build_success_key') ) {
    function wdss29_build_success_key( $order_id, $user_id = 0 ) {
        $data = (int) $order_id . '|' . (int) $user_id;
        return hash_hmac('sha256', $data, wp_salt('auth'));
    }
}

/**
 * Main handler: resolve order, mark paid, emit email trigger.
 * Runs on checkout-success page load.
 */
function wdss29_on_checkout_success() {
    // Only run on our success flag or on the /checkout-success/ page
    $is_success_flag = ( isset($_GET['wdss29']) && $_GET['wdss29'] === 'success' );
    if ( ! $is_success_flag ) return;

    $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
    $uid      = isset($_GET['uid']) ? absint($_GET['uid']) : 0;
    $key      = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
    $sid      = isset($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : '';

    $user_id  = get_current_user_id();

    // (A) Signed guard validation (best path when present)
    $guard_ok = false;
    if ( $order_id && $key ) {
        $expected = wdss29_build_success_key( $order_id, $uid );
        $guard_ok = hash_equals( $expected, $key );
    }

    // (B) Cookie fallback (works for guests and logged-in)
    if ( ! $guard_ok || ! $order_id ) {
        if ( ! empty($_COOKIE['wdss_last_order']) ) {
            $cookie_order = absint( $_COOKIE['wdss_last_order'] );
            if ( $cookie_order > 0 ) {
                $order_id = $cookie_order;
                $guard_ok = true;
            }
        }
    }

    // (C) Logged-in fallback via user meta
    if ( ! $guard_ok || ! $order_id ) {
        if ( is_user_logged_in() ) {
            $meta_order = (int) get_user_meta( $user_id, '_wdss_last_order', true );
            if ( $meta_order > 0 ) {
                $order_id = $meta_order;
                $guard_ok = true;
            }
        }
    }

    // (D) Optional: Stripe Session lookup when a REAL session id is present
    if ( ! $guard_ok || ! $order_id ) {
        if ( $sid && $sid !== 'CHECKOUT_SESSION_ID' && class_exists('\Stripe\Stripe') ) {
            $settings = get_option('wdss29_settings', array());
            $sk = isset($settings['stripe_sk']) ? trim($settings['stripe_sk']) : '';
            if ( $sk ) {
                try {
                    \Stripe\Stripe::setApiKey($sk);
                    $session = \Stripe\Checkout\Session::retrieve($sid, ['expand' => ['payment_intent']]);
                    if ( ! empty($session->metadata->order_id) ) {
                        $order_id = (int) $session->metadata->order_id;
                        $guard_ok = true;
                    } elseif ( ! empty($session->client_reference_id) ) {
                        $order_id = (int) $session->client_reference_id;
                        $guard_ok = true;
                    } elseif ( ! empty($session->payment_intent->metadata->order_id) ) {
                        $order_id = (int) $session->payment_intent->metadata->order_id;
                        $guard_ok = true;
                    }
                } catch ( \Exception $e ) {
                    error_log('[WDSS] Success Stripe lookup failed: ' . $e->getMessage());
                }
            }
        }
    }

    if ( ! $guard_ok || ! $order_id ) {
        // Breadcrumb for diagnostics
        do_action('wdss_email_trigger', 'order.debug', array(
            'note'       => 'success_no_order_resolved',
            'uid'        => (int) $user_id,
            'sid'        => $sid,
            'has_cookie' => ! empty($_COOKIE['wdss_last_order']),
        ));
        return;
    }

    // Idempotency (avoid double fire on refresh)
    $idem = 'wdss_success_emit_' . (int) $order_id;
    if ( get_transient( $idem ) ) return;

    // ---- Mark order PAID
    $hints = array(
        'source'     => ( $sid && $sid !== 'CHECKOUT_SESSION_ID' ) ? 'success_session' : 'success_fallback',
        'session_id' => $sid,
    );

    if ( function_exists('wdss29_set_order_status') ) {
        wdss29_set_order_status( $order_id, 'paid', $hints );
    } else {
        // Minimal fallback for CPT storage
        update_post_meta( (int)$order_id, '_wdss_status', 'paid' );
    }

    // ---- Fire the normalized email trigger
    if ( function_exists('wdss29_emit_order_paid') ) {
        wdss29_emit_order_paid( $order_id, $hints );
    } else {
        // Minimal payload if helper missing
        $payload = array(
            'order_id'       => (int) $order_id,
            'order_number'   => (string) $order_id,
            'order_status'   => 'paid',
            'order_total'    => (float) get_post_meta( (int)$order_id, '_wdss_total', true ),
            'currency'       => get_option('wdss_currency','USD'),
            '_idem_key'      => 'order.paid|' . (int)$order_id,
        );
        do_action('wdss_email_trigger', 'order.paid', $payload);
    }

    // Nudge the mailer poller if present
    if ( function_exists('wp_schedule_single_event') ) {
        wp_schedule_single_event( time() + 5, 'wdss_email_order_poller_tick' );
    }

    // Cleanup: cookie + user meta + set idempotency
    if ( isset($_COOKIE['wdss_last_order']) ) {
        setcookie('wdss_last_order', '', time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '', is_ssl(), true);
    }
    if ( is_user_logged_in() ) {
        delete_user_meta( $user_id, '_wdss_last_order' );
    }
    set_transient( $idem, 1, 10 * MINUTE_IN_SECONDS );
}
// Run early so builders/themes don’t swallow it
add_action('template_redirect', 'wdss29_on_checkout_success', 1);
