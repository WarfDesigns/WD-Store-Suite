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
        // Backstop: reschedule while in admin too
        add_action( 'admin_init', array( $this, 'maybe_schedule_poller' ) );

        // Poller callback
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
        // Ensure our schedule exists before scheduling
        // (the filter above already added it for this request)
        if ( ! wp_next_scheduled( 'wdss_email_order_poller_tick' ) ) {
            wp_schedule_event( time() + 60, 'wdss_minutely', 'wdss_email_order_poller_tick' );
        }
    }

    /** Poller tick handler */
    public function order_poller_tick() {
        // Optional: log a heartbeat for debugging
        do_action( 'wdss_email_trigger', 'order.debug', array(
            'note' => 'poller_tick',
            'time' => current_time( 'mysql' ),
        ) );

        // If you have a queue/backfill system, run it here
        if ( function_exists( 'wdss29_process_email_queue' ) ) {
            wdss29_process_email_queue();
        }

        // Also allow other listeners to hook a "run" tick if needed
        do_action( 'wdss_email_order_poller_tick_run' );
    }
}

endif;

// Bootstrap
WDSS29_Core::instance();

/**
 * Optional: clear the schedule on plugin deactivation.
 * Keep this here since we're inside /includes/ and not the main file.
 * Adjust the main file name if yours differs.
 */
if ( ! function_exists( 'wdss29_clear_email_poller' ) ) {
    function wdss29_clear_email_poller() {
        $timestamp = wp_next_scheduled( 'wdss_email_order_poller_tick' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'wdss_email_order_poller_tick' );
        }
        wp_clear_scheduled_hook( 'wdss_email_order_poller_tick' );
    }

    // Ensure this path matches your main plugin file exactly:
    register_deactivation_hook(
        plugin_basename( dirname( __FILE__, 2 ) . '/wd-store-suite-v2_9.php' ),
        'wdss29_clear_email_poller'
    );
}

/* --------------------------------------------------------------------------
 * Email payload + normalized dispatcher helpers
 * (LEFT AS-IS from your version)
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

        // Email Automations bus (kept with your 2-arg signature)
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

        // Email Automations bus (kept with your 2-arg signature)
        do_action( 'wdss_email_trigger', 'order.status_changed', $payload );

        // Optional back-compat
        do_action( 'wdss29_order_status_changed_normalized', (int)$order_id, (string)$new_status, $payload );
    }
}

// === WDSS Success Page: emit order.paid when customer returns from Stripe ===

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
            do_action( 'wdss_email_trigger', 'order.pid', $payload ); // keeps your bus alive even without helpers
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

// === WDSS Success Page handler with fallback ===
// Works with either a proper Stripe session_id or (fallback) the last saved order for the logged-in user.

if ( ! function_exists( 'wdss29_handle_checkout_success' ) ) {
    function wdss29_handle_checkout_success() {
        if ( empty($_GET['wdss29']) || $_GET['wdss29'] !== 'success' ) return;

        $user_id = get_current_user_id();
        if ( ! $user_id ) return; // only handling logged-in flow as requested

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key      = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        $sid      = isset($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : '';

        // Validate the signed guard if provided
        $guard_ok = false;
        if ( $order_id && $key ) {
            if ( function_exists('wdss29_build_success_key') ) {
                $expected = wdss29_build_success_key( $order_id, $user_id );
            } else {
                $expected = hash_hmac( 'sha256', (int)$order_id . '|' . (int)$user_id, wp_salt('auth') );
            }
            $guard_ok = hash_equals( $expected, $key );
        }

        // Idempotency: avoid double-firing
        $idem = 'wdss_success_emit_' . ( $order_id ? $order_id : $user_id );
        if ( get_transient( $idem ) ) return;

        // Fallback to last saved order if guard missing/invalid
        if ( ! $guard_ok ) {
            $fallback_order = (int) get_user_meta( $user_id, '_wdss_last_order', true );
            if ( $fallback_order > 0 ) {
                $order_id = $fallback_order;
                $guard_ok = true; // treat as trusted for logged-in user flow
            }
        }

        if ( ! $guard_ok || ! $order_id ) {
            // Optional: debug breadcrumb
            do_action( 'wdss_email_trigger', 'order.debug', array(
                'note' => 'success_no_guard_or_order',
                'uid'  => (int) $user_id,
                'sid'  => $sid,
            ) );
            return;
        }

        // Mark paid and emit emails
        $hints = array( 'source' => ( $sid && $sid !== 'CHECKOUT_SESSION_ID' ) ? 'success_redirect_session' : 'success_redirect_fallback' );

        if ( function_exists( 'wdss29_set_order_status' ) ) {
            wdss29_set_order_status( $order_id, 'paid', $hints );
        }

        if ( function_exists( 'wdss29_emit_order_paid' ) ) {
            wdss29_emit_order_paid( $order_id, $hints );
        } else {
            // minimal payload
            $payload = array(
                'order_id'     => (int) $order_id,
                'order_status' => 'paid',
                '_idem_key'    => 'order.paid|' . (int)$order_id,
            );
            do_action( 'wdss_email_trigger', 'order.paid', $payload );
        }

        if ( function_exists( 'wp_schedule_single_event' ) ) {
            wp_schedule_single_event( time() + 5, 'wdss_email_order_poller_tick' );
        }

        // Clear last-order pointer and set idempotency
        delete_user_meta( $user_id, '_wdss_last_order' );
        set_transient( $idem, 1, 10 * MINUTE_IN_SECONDS );
    }
    add_action( 'template_redirect', 'wdss29_handle_checkout_success', 1 );
}

// === WDSS Success Page finisher (guests + logged-in) ===
// Resolves order_id from signed params, cookie, user meta, or Stripe session_id,
// then marks PAID and emits the email trigger. Adds detailed debug logs.
if ( ! function_exists( 'wdss29_finish_success' ) ) {
    function wdss29_finish_success() {
        if ( empty($_GET['wdss29']) || $_GET['wdss29'] !== 'success' ) return;

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $uid      = isset($_GET['uid']) ? absint($_GET['uid']) : 0;
        $key      = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        $sid      = isset($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : '';
        $user_id  = get_current_user_id();

        $dbg = array(
            'note' => 'success_entry',
            'order_id_qs' => $order_id,
            'uid_qs'      => $uid,
            'has_key'     => $key ? 1 : 0,
            'sid'         => $sid,
            'user_id'     => (int)$user_id,
            'cookie'      => isset($_COOKIE['wdss_last_order']) ? (int)$_COOKIE['wdss_last_order'] : 0,
        );
        error_log('[WDSS] success handler: ' . json_encode($dbg));

        // (A) validate signed guard if present
        $guard_ok = false;
        if ( $order_id && $key ) {
            $expected = function_exists('wdss29_build_success_key')
                ? wdss29_build_success_key( $order_id, $uid )
                : hash_hmac( 'sha256', (int)$order_id . '|' . (int)$uid, wp_salt('auth') );
            $guard_ok = hash_equals( $expected, $key );
            if ( ! $guard_ok ) error_log('[WDSS] success: signed guard mismatch');
        }

        // (B) cookie fallback
        if ( ! $guard_ok || ! $order_id ) {
            if ( ! empty($_COOKIE['wdss_last_order']) ) {
                $cookie_order = absint( $_COOKIE['wdss_last_order'] );
                if ( $cookie_order > 0 ) {
                    $order_id = $cookie_order;
                    $guard_ok = true;
                    error_log('[WDSS] success: resolved via cookie -> ' . $order_id);
                }
            }
        }

        // (C) logged-in fallback via user meta
        if ( ! $guard_ok || ! $order_id ) {
            if ( is_user_logged_in() ) {
                $meta_order = (int) get_user_meta( $user_id, '_wdss_last_order', true );
                if ( $meta_order > 0 ) {
                    $order_id = $meta_order;
                    $guard_ok = true;
                    error_log('[WDSS] success: resolved via user meta -> ' . $order_id);
                }
            }
        }

        // (D) Stripe session lookup when we have a real session id
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
                        } elseif ( ! empty($session->client_reference_id) ) {
                            $order_id = (int) $session->client_reference_id;
                        } elseif ( ! empty($session->payment_intent->metadata->order_id) ) {
                            $order_id = (int) $session->payment_intent->metadata->order_id;
                        }
                        if ( $order_id ) {
                            $guard_ok = true;
                            error_log('[WDSS] success: resolved via Stripe session lookup -> ' . $order_id);
                        }
                    } catch ( \Exception $e ) {
                        error_log('[WDSS] success: Stripe session lookup failed: ' . $e->getMessage());
                    }
                }
            }
        }

        if ( ! $guard_ok || ! $order_id ) {
            error_log('[WDSS] success: unable to resolve order');
            do_action( 'wdss_email_trigger', 'order.debug', array(
                'note' => 'success_no_order_resolved',
                'sid'  => $sid,
            ));
            return;
        }

        // Idempotency: prevent double-firing
        $idem = 'wdss_success_emit_' . (int)$order_id;
        if ( get_transient( $idem ) ) {
            error_log('[WDSS] success: already emitted for ' . (int)$order_id);
            return;
        }

        // Mark PAID
        $source = ($sid && $sid !== 'CHECKOUT_SESSION_ID') ? 'success_session' : 'success_fallback';
        if ( function_exists( 'wdss29_set_order_status' ) ) {
            wdss29_set_order_status( $order_id, 'paid', array('source'=>$source) );
        } else {
            update_post_meta( (int)$order_id, '_wdss_status', 'paid' );
            error_log('[WDSS] success: set _wdss_status=paid (fallback) for ' . (int)$order_id);
            // still emit order.paid for email engine
            do_action( 'wdss_email_trigger', 'order.paid', array(
                'order_id'  => (int)$order_id,
                'order_status'=>'paid',
                '_idem_key' => 'order.paid|' . (int)$order_id,
            ));
        }

        // Kick the mailer poller (if you use it)
        if ( function_exists( 'wp_schedule_single_event' ) ) {
            wp_schedule_single_event( time() + 5, 'wdss_email_order_poller_tick' );
        }

        // Clean pointers + set idempotency
        if ( isset($_COOKIE['wdss_last_order']) ) {
            setcookie('wdss_last_order', '', time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '', is_ssl(), true);
        }
        if ( is_user_logged_in() ) {
            delete_user_meta( $user_id, '_wdss_last_order' );
        }
        set_transient( $idem, 1, 10 * MINUTE_IN_SECONDS );

        error_log('[WDSS] success: finished for order ' . (int)$order_id);
    }
    add_action( 'init', 'wdss29_finish_success', 1 );
    add_action( 'template_redirect', 'wdss29_finish_success', 1 );
}

