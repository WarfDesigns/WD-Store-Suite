<?php
/**
 * WD Store Suite — Core Bootstrap (v2.9)
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
        // Public init
        add_action( 'init',        array( $this, 'init_public' ) );
        add_action( 'admin_init',  array( $this, 'init_admin' ) );
        add_action( 'plugins_loaded', array( $this, 'maybe_install_db' ) );

        // Admin UI, shortcodes, etc.
        add_action( 'init', array( $this, 'register_shortcodes' ) );
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );

        // Load Email Automation (make sure these paths match your plugin)
        $base = __DIR__;
        @include_once $base . '/wdss-email-automation/includes/class-wdss-emailer.php';
        @include_once $base . '/wdss-email-automation/includes/order-poller.php';
        @include_once $base . '/wdss-email-automation/includes/bridge-forwarders.php';

        // (Optional) Bridge ensures events always forward to the bus
        @include_once $base . '/class-wdss-order-events-bridge.php';
    }

    public function init_public() {
        // Ensure custom cron schedule is available before anything schedules it
        add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );

        // Schedule/restore the poller (safe and idempotent)
        $this->schedule_email_poller();

        // Safety fallback: if recurring isn’t set yet, queue a one-off tick
        add_action( 'init', function () {
            if ( ! wp_next_scheduled( 'wdss_email_order_poller_tick' ) ) {
                if ( ! wp_next_scheduled( 'wdss_email_order_poller_tick' ) ) {
                    wp_schedule_single_event( time() + 30, 'wdss_email_order_poller_tick' );
                }
            }
        }, 20 );
    }

    public function init_admin() {
        // Backstop to (re)create schedule when visiting dashboard
        $this->schedule_email_poller();
    }

    public function register_shortcodes() {
        // register public shortcodes as needed
    }

    public function register_admin_menu() {
        // add_menu_page + subpages as needed
    }

    /* =================== DB install/upgrade =================== */

    /** Install/upgrade once per code version or table miss */
    public function maybe_install_db() {
        // Orders table install guard (also called in includes/orders.php if you prefer)
        if ( function_exists( 'wdss29_maybe_install_or_upgrade_orders_table' ) ) {
            wdss29_maybe_install_or_upgrade_orders_table();
        }
    }

    /* =================== Cron schedule & poller =================== */

    /** Add custom every-minute schedule */
    public function register_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['wdss_minutely'] ) ) {
            $schedules['wdss_minutely'] = array(
                'interval' => 60,
                'display'  => __( 'WDSS Every Minute', 'wdss' ),
            );
        }
        return $schedules;
    }

    /** Ensure poller is scheduled */
    private function schedule_email_poller() {
        // Make sure schedule exists (in case this runs before filters)
        add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );
        // Schedule recurring poller if not set
        if ( ! wp_next_scheduled( 'wdss_email_order_poller_tick' ) ) {
            // If schedule hasn’t been added yet by WP, this will retry next request
            wp_schedule_event( time() + 60, 'wdss_minutely', 'wdss_email_order_poller_tick' );
        }
    }
}

endif;

// Bootstrap core
WDSS29_Core::instance();

/* ========================================================================== */
/* === Success redirect helpers: emit order.paid when customer returns ====== */
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

if ( ! function_exists( 'wdss29_emit_order_paid' ) ) {
    /**
     * Helper to emit order.paid with correct argument signature.
     */
    function wdss29_emit_order_paid( $order_id, $hints = array() ) {
        if ( ! is_array( $hints ) ) $hints = array();
        $payload = array_merge( array(
            'order_id'     => (int) $order_id,
            'order_status' => 'paid',
        ), $hints );

        do_action( 'wdss_email_trigger', 'order.paid', (int) $order_id, $payload );
    }
}

if ( ! function_exists( 'wdss29_capture_success_on_redirect' ) ) {
    function wdss29_capture_success_on_redirect() {
        if ( empty( $_GET['wdss_success'] ) ) return;

        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        $uid      = isset( $_GET['uid'] ) ? absint( $_GET['uid'] ) : 0;
        $key      = isset( $_GET['key'] ) ? sanitize_text_field( $_GET['key'] ) : '';

        if ( ! $order_id ) return;

        // If you want to require login + signature, keep these checks:
        if ( ! is_user_logged_in() ) return;
        $expected = wdss29_build_success_key( $order_id, $uid );
        if ( $key && ! hash_equals( $expected, $key ) ) return;

        $idem = 'wdss_success_emit_' . $order_id;
        if ( get_transient( $idem ) ) {
            wp_safe_redirect( remove_query_arg( array( 'wdss_success','order_id','uid','key' ) ) );
            exit;
        }

        $hints = array( 'source' => 'success_redirect' );

        if ( function_exists( 'wdss29_set_order_status' ) ) {
            wdss29_set_order_status( $order_id, 'paid', $hints );
        }

        // Emit canonical bus (correct 3-arg call)
        wdss29_emit_order_paid( $order_id, $hints );

        // Wake the poller right away
        if ( function_exists( 'wp_schedule_single_event' ) ) {
            wp_schedule_single_event( time() + 5, 'wdss_email_order_poller_tick' );
        }

        set_transient( $idem, 1, 10 * MINUTE_IN_SECONDS );

        wp_safe_redirect( remove_query_arg( array( 'wdss_success','order_id','uid','key' ) ) );
        exit;
    }
    add_action( 'template_redirect', 'wdss29_capture_success_on_redirect', 1 );
}
