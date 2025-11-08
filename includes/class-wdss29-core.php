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
        // Admin UI
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );

        // Ensure our custom cron schedule exists
        add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );

        // Make sure the poller is scheduled (no activation hook from include files)
        add_action( 'init', array( $this, 'maybe_schedule_poller' ) );

        // Poller callback (defensive: only runs if something registered it)
        add_action( 'wdss_email_order_poller_tick', array( $this, 'order_poller_tick' ) );
    }

    /** Admin Menu */
    public function admin_menu() {
        if ( ! defined( 'WDSS29_PARENT_MENU' ) ) define( 'WDSS29_PARENT_MENU', 'wd-store-suite' );

        add_menu_page(
            'WD Store Suite',
            'WD Store Suite',
            'manage_options',
            WDSS29_PARENT_MENU,
            array( $this, 'render_dashboard' ),
            'dashicons-store',
            58
        );
    }

    public function render_dashboard() {
        echo '<div class="wrap"><h1>WD Store Suite</h1><p>Welcome to WD Store Suite.</p></div>';
    }

    /** Register custom schedules (fixes invalid_schedule) */
    public function register_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['wdss_minutely'] ) ) {
            $schedules['wdss_minutely'] = array(
                'interval' => 60,
                'display'  => __( 'Every Minute (WDSS)', 'wdss' ),
            );
        }
        return $schedules;
    }

    /** Schedule the poller if not present (safe to run on every init) */
    public function maybe_schedule_poller() {
        if ( ! wp_next_scheduled( 'wdss_email_order_poller_tick' ) ) {
            // Make sure our schedule exists before scheduling
            $schedules = wp_get_schedules();
            $recurrence = isset( $schedules['wdss_minutely'] ) ? 'wdss_minutely' : 'minute'; // fallback if needed
            if ( $recurrence === 'wdss_minutely' ) {
                wp_schedule_event( time() + 60, 'wdss_minutely', 'wdss_email_order_poller_tick' );
            }
        }
    }

    /** Optional: poller to catch delayed emails (defensive no-ops) */
    public function order_poller_tick() {
        // If you have a queue/backfill system, call it here. Keep it no-op if not used.
        // This prevents cron from rescheduling with a missing schedule.
        do_action( 'wdss_email_order_poller_tick_run' );
    }
}

endif;

// Bootstrap
WDSS29_Core::instance();

/* --------------------------------------------------------------------------
 * Email payload + normalized dispatcher helpers
 * -------------------------------------------------------------------------- */

if ( ! function_exists('wdss29_get_meta') ) {
    function wdss29_get_meta( $post_id, $key, $default = '' ) {
        $val = get_post_meta( (int)$post_id, $key, true );
        if ( $val === '' || $val === null ) return $default;
        return maybe_unserialize($val);
    }
}

if ( ! function_exists('wdss29_build_email_payload') ) {
    function wdss29_build_email_payload( $order_id, $hints = array() ) {
        $order_id = (int) $order_id;

        $map = array(
            'number'          => '_wdss_number',
            'status'          => '_wdss_status',
            'subtotal'        => '_wdss_subtotal',
            'tax'             => '_wdss_tax',
            'shipping'        => '_wdss_shipping',
            'total'           => '_wdss_total',
            'currency'        => '_wdss_currency',
            'payment_method'  => '_wdss_payment_method',

            'customer_email'  => '_wdss_customer_email',
            'customer_name'   => '_wdss_customer_name',

            'billing_first'   => '_wdss_billing_first_name',
            'billing_last'    => '_wdss_billing_last_name',
            'billing_email'   => '_wdss_customer_email',
            'billing_phone'   => '_wdss_billing_phone',
            'billing_line1'   => '_wdss_billing_line1',
            'billing_line2'   => '_wdss_billing_line2',
            'billing_city'    => '_wdss_billing_city',
            'billing_state'   => '_wdss_billing_state',
            'billing_postcode'=> '_wdss_billing_postcode',
            'billing_country' => '_wdss_billing_country',

            'ship_first'      => '_wdss_shipping_first_name',
            'ship_last'       => '_wdss_shipping_last_name',
            'ship_line1'      => '_wdss_shipping_line1',
            'ship_line2'      => '_wdss_shipping_line2',
            'ship_city'       => '_wdss_shipping_city',
            'ship_state'      => '_wdss_shipping_state',
            'ship_postcode'   => '_wdss_shipping_postcode',
            'ship_country'    => '_wdss_shipping_country',

            'items'           => '_wdss_items',
            'created_at'      => '_wdss_created_at',
        );
        $map = apply_filters('wdss29_email_meta_map', $map, $order_id);

        $items = wdss29_get_meta($order_id, $map['items'], array());
        if ( ! is_array($items) ) $items = array();

        $customer_email = sanitize_email( wdss29_get_meta($order_id, $map['customer_email'], '') );
        $customer_name  = sanitize_text_field( wdss29_get_meta($order_id, $map['customer_name'], '') );

        // Merge hints (e.g., Stripe webhook)
        if ( empty($customer_email) && ! empty($hints['customer_email']) ) {
            $customer_email = sanitize_email($hints['customer_email']);
        }
        if ( empty($customer_name) && ! empty($hints['customer_name']) ) {
            $customer_name = sanitize_text_field($hints['customer_name']);
        }
        if ( empty($customer_name) ) {
            $maybe = trim(
                wdss29_get_meta($order_id, $map['billing_first'], '') . ' ' .
                wdss29_get_meta($order_id, $map['billing_last'], '')
            );
            if ( $maybe ) $customer_name = $maybe;
        }

        return array(
            'order_id'       => $order_id,
            'order_number'   => wdss29_get_meta($order_id, $map['number'], (string)$order_id),
            'order_status'   => wdss29_get_meta($order_id, $map['status'], 'paid'),
            'order_subtotal' => (float) wdss29_get_meta($order_id, $map['subtotal'], 0),
            'order_tax'      => (float) wdss29_get_meta($order_id, $map['tax'], 0),
            'order_shipping' => (float) wdss29_get_meta($order_id, $map['shipping'], 0),
            'order_total'    => (float) wdss29_get_meta($order_id, $map['total'], 0),
            'currency'       => wdss29_get_meta($order_id, $map['currency'], get_option('wdss_currency', 'USD')),
            'payment_method' => wdss29_get_meta($order_id, $map['payment_method'], 'stripe'),
            'customer_email' => $customer_email,
            'customer_name'  => $customer_name,
            'billing'        => array(
                'first_name' => sanitize_text_field( wdss29_get_meta($order_id, $map['billing_first'], '') ),
                'last_name'  => sanitize_text_field( wdss29_get_meta($order_id, $map['billing_last'], '') ),
                'email'      => sanitize_email( wdss29_get_meta($order_id, $map['billing_email'], '') ),
                'phone'      => sanitize_text_field( wdss29_get_meta($order_id, $map['billing_phone'], '') ),
                'line1'      => sanitize_text_field( wdss29_get_meta($order_id, $map['billing_line1'], '') ),
                'line2'      => sanitize_text_field( wdss29_get_meta($order_id, $map['billing_line2'], '') ),
                'city'       => sanitize_text_field( wdss29_get_meta($order_id, $map['billing_city'], '') ),
                'state'      => sanitize_text_field( wdss29_get_meta($order_id, $map['billing_state'], '') ),
                'postcode'   => sanitize_text_field( wdss29_get_meta($order_id, $map['billing_postcode'], '') ),
                'country'    => sanitize_text_field( wdss29_get_meta($order_id, $map['billing_country'], '') ),
            ),
            'shipping'       => array(
                'first_name' => sanitize_text_field( wdss29_get_meta($order_id, $map['ship_first'], '') ),
                'last_name'  => sanitize_text_field( wdss29_get_meta($order_id, $map['ship_last'], '') ),
                'line1'      => sanitize_text_field( wdss29_get_meta($order_id, $map['ship_line1'], '') ),
                'line2'      => sanitize_text_field( wdss29_get_meta($order_id, $map['ship_line2'], '') ),
                'city'       => sanitize_text_field( wdss29_get_meta($order_id, $map['ship_city'], '') ),
                'state'      => sanitize_text_field( wdss29_get_meta($order_id, $map['ship_state'], '') ),
                'postcode'   => sanitize_text_field( wdss29_get_meta($order_id, $map['ship_postcode'], '') ),
                'country'    => sanitize_text_field( wdss29_get_meta($order_id, $map['ship_country'], '') ),
            ),
            'items'          => $items,
            'created_at'     => wdss29_get_meta($order_id, $map['created_at'], current_time('mysql')),
            '_idem_key'      => 'order.paid|' . $order_id,
        );
    }
}

if ( ! function_exists('wdss29_emit_order_paid') ) {
    function wdss29_emit_order_paid( $order_id, $hints = array() ) {
        $payload = wdss29_build_email_payload( $order_id, (array)$hints );

        // Email Automations bus (correct 2-arg signature)
        do_action( 'wdss_email_trigger', 'order.paid', $payload );

        // Optional back-compat
        do_action( 'wdss29_order_paid_normalized', (int)$order_id, $payload );
    }
}
if ( ! function_exists('wdss29_emit_order_status_changed') ) {
    function wdss29_emit_order_status_changed( $order_id, $new_status, $hints = array() ) {
        $payload = wdss29_build_email_payload( $order_id, (array)$hints );
        $payload['order_status'] = (string) $new_status;
        $payload['_idem_key'] = 'order.status_changed|' . $order_id . '|' . $new_status;

        // Email Automations bus (correct 2-arg signature)
        do_action( 'wdss_email_trigger', 'order.status_changed', $payload );

        // Optional back-compat
        do_action( 'wdss29_order_status_changed_normalized', (int)$order_id, (string)$new_status, $payload );
    }
}