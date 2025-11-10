<?php
/**
 * WD Store Suite — Core Bootstrap
 * Author: Warf Designs LLC
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WDSS29_Core' ) ) :

class WDSS29_Core {

    const VERSION = '2.9';

    /** @var self */
    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Admin & front init, assets, etc.
        add_action( 'init', array( $this, 'register_shortcodes' ) );
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );

        // Make sure our email automation components load
        require_once __DIR__ . '/wdss-email-automation/includes/class-wdss-emailer.php';
        require_once __DIR__ . '/wdss-email-automation/includes/order-poller.php';
        require_once __DIR__ . '/wdss-email-automation/includes/bridge-forwarders.php';

        // Bridge ensures events always forward to the bus
        require_once __DIR__ . '/class-wdss-order-events-bridge.php';
    }

    public function register_shortcodes() {
        // register your public shortcodes here
    }

    public function register_admin_menu() {
        // add_menu_page + subpages
    }
}

endif;

// Bootstrap
WDSS29_Core::instance();

/* ========================================================================== */
/* === Success redirect helpers: send order.paid when user returns from Stripe */
/* ========================================================================== */

if ( ! function_exists( 'wdss29_build_success_key' ) ) {
    function wdss29_build_success_key( $order_id, $user_id = 0 ) {
        $data = (int) $order_id . '|' . (int) $user_id;
        return hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
    }
}

if ( ! function_exists( 'wdss29_build_success_url' ) ) {
    function wdss29_build_success_url( $base_url, $order_id, $user_id = 0 ) {
        $key = wdss29_build_success_key( $order_id, $user_id );
        return add_query_arg( array(
            'wdss_success' => 1,
            'order_id'     => (int) $order_id,
            'uid'          => (int) $user_id,
            'key'          => $key,
        ), $base_url );
    }
}

if ( ! function_exists( 'wdss29_capture_success_on_redirect' ) ) {
    function wdss29_capture_success_on_redirect() {
        if ( empty( $_GET['wdss_success'] ) ) return;

        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        $uid      = isset( $_GET['uid'] ) ? absint( $_GET['uid'] ) : 0;
        $key      = isset( $_GET['key'] ) ? sanitize_text_field( $_GET['key'] ) : '';

        if ( ! $order_id || ! $key ) return;
        if ( ! is_user_logged_in() ) return;

        $expected = wdss29_build_success_key( $order_id, $uid );
        if ( ! hash_equals( $expected, $key ) ) return;

        $idem = 'wdss_success_emit_' . $order_id;
        if ( get_transient( $idem ) ) {
            wp_safe_redirect( remove_query_arg( array( 'wdss_success','order_id','uid','key' ) ) );
            exit;
        }

        $hints = array( 'source' => 'success_redirect' );

        if ( function_exists( 'wdss29_set_order_status' ) ) {
            wdss29_set_order_status( $order_id, 'paid', $hints );
        }

        if ( function_exists( 'wdss29_emit_order_paid' ) ) {
            wdss29_emit_order_paid( $order_id, $hints );
        } else {
            // Fallback emit — FIXED to pass (event, order_id, payload)
            $payload = array(
                'order_id'       => $order_id,
                'order_number'   => (string) $order_id,
                'order_status'   => 'paid',
                'order_total'    => 0,
                'currency'       => get_option( 'wdss_currency', 'USD' ),
                'customer_email' => '',
                'customer_name'  => '',
                '_idem_key'      => 'order.paid|' . $order_id,
            );
            do_action( 'wdss_email_trigger', 'order.paid', (int) $order_id, $payload );
        }

        if ( function_exists( 'wp_schedule_single_event' ) ) {
            wp_schedule_single_event( time() + 5, 'wdss_email_order_poller_tick' );
        }

        set_transient( $idem, 1, 10 * MINUTE_IN_SECONDS );

        wp_safe_redirect( remove_query_arg( array( 'wdss_success','order_id','uid','key' ) ) );
        exit;
    }
    add_action( 'template_redirect', 'wdss29_capture_success_on_redirect', 1 );
}
