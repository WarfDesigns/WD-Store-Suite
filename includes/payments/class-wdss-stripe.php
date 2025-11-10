<?php
/**
 * Stripe gateway — webhook: verify signature, mark paid, emit emails (idempotent)
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
        add_action( 'rest_api_init', array( $this, 'register_rest' ) );
    }

    public function register_rest() {
        register_rest_route( 'wdss29/v1', '/stripe/webhook', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'handle_webhook' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /** Safe getter for nested arrays. */
    private function ap( $arr, $path, $default = null ) {
        $cur = $arr;
        foreach ( (array) $path as $k ) {
            if ( ! is_array( $cur ) || ! array_key_exists( $k, $cur ) ) return $default;
            $cur = $cur[ $k ];
        }
        return $cur;
    }

    /** Extract useful customer hints from PI / Charge / Session. */
    private function extract_stripe_hints( $obj ) {
        $hints = array();

        // PaymentIntent -> charges[0].billing_details
        $bd = $this->ap( $obj, array('charges','data',0,'billing_details'), array() );
        if ( ! empty( $bd ) ) {
            if ( ! empty( $bd['email'] ) ) $hints['customer_email'] = sanitize_email( $bd['email'] );
            if ( ! empty( $bd['name'] ) )  $hints['customer_name']  = sanitize_text_field( $bd['name'] );
        }

        // Charge directly
        $bd2 = $this->ap( $obj, array('billing_details'), array() );
        if ( ! empty( $bd2 ) ) {
            if ( empty( $hints['customer_email'] ) && ! empty( $bd2['email'] ) ) $hints['customer_email'] = sanitize_email( $bd2['email'] );
            if ( empty( $hints['customer_name'] )  && ! empty( $bd2['name'] ) )  $hints['customer_name']  = sanitize_text_field( $bd2['name'] );
        }

        // Checkout Session
        if ( empty( $hints['customer_email'] ) ) {
            $session_email = $this->ap( $obj, array('customer_details','email') );
            if ( $session_email ) $hints['customer_email'] = sanitize_email( $session_email );
        }
        if ( empty( $hints['customer_name'] ) ) {
            $session_name = $this->ap( $obj, array('customer_details','name') );
            if ( $session_name ) $hints['customer_name'] = sanitize_text_field( $session_name );
        }

        // Top level fallback
        if ( empty( $hints['customer_email'] ) ) {
            $receipt = $this->ap( $obj, array('receipt_email') );
            if ( $receipt ) $hints['customer_email'] = sanitize_email( $receipt );
        }

        return $hints;
    }

    /** Resolve local order id from Stripe payload */
    private function resolve_order_id( $payload_obj ) {
        $meta_key = apply_filters( 'wdss29_stripe_order_meta_key', 'order_id' );

        // PaymentIntent.metadata.order_id
        $oid = $this->ap( $payload_obj, array('metadata', $meta_key) );
        if ( $oid ) return (int) $oid;

        // Charge.metadata.order_id
        $oid = $this->ap( $payload_obj, array('charges','data',0,'metadata', $meta_key) );
        if ( $oid ) return (int) $oid;

        // Checkout Session.metadata.order_id
        $oid = $this->ap( $payload_obj, array('metadata', $meta_key) );
        if ( $oid ) return (int) $oid;

        // Checkout Session.client_reference_id
        $cri = $this->ap( $payload_obj, array('client_reference_id') );
        if ( $cri ) return (int) $cri;

        return 0;
    }

    /** Build normalized email payload from DB + hints */
    private function build_email_payload( $order_id, $hints = array() ) {
        $payload = array(
            'order_id'       => (int) $order_id,
            'order_number'   => (string) $order_id,
            'order_status'   => 'paid',
            'order_subtotal' => 0.0,
            'order_tax'      => 0.0,
            'order_shipping' => 0.0,
            'order_total'    => 0.0,
            'currency'       => get_option('wdss_currency','USD'),
            'payment_method' => 'stripe',
            'customer_email' => isset($hints['customer_email']) ? sanitize_email($hints['customer_email']) : '',
            'customer_name'  => isset($hints['customer_name']) ? sanitize_text_field($hints['customer_name']) : '',
            'items'          => array(),
            'created_at'     => current_time('mysql'),
            '_idem_key'      => 'order.paid|' . (int)$order_id,
        );

        if ( function_exists('wdss29_get_order') ) {
            $o = wdss29_get_order( (int)$order_id );
            if ( is_array($o) ) {
                $payload['order_number']   = !empty($o['number']) ? $o['number'] : (string)$order_id;
                $payload['order_subtotal'] = isset($o['subtotal']) ? (float)$o['subtotal'] : 0.0;
                $payload['order_tax']      = isset($o['tax']) ? (float)$o['tax'] : 0.0;
                $payload['order_shipping'] = isset($o['shipping']) ? (float)$o['shipping'] : 0.0;
                $payload['order_total']    = isset($o['total']) ? (float)$o['total'] : 0.0;
                $payload['currency']       = !empty($o['currency']) ? $o['currency'] : $payload['currency'];
                $payload['payment_method'] = !empty($o['payment_method']) ? $o['payment_method'] : $payload['payment_method'];
                $payload['created_at']     = !empty($o['created_at']) ? $o['created_at'] : $payload['created_at'];
                $items                     = isset($o['items']) ? maybe_unserialize($o['items']) : array();
                $payload['items']          = is_array($items) ? $items : array();

                if ( empty($payload['customer_email']) && !empty($o['customer_email']) ) {
                    $payload['customer_email'] = sanitize_email($o['customer_email']);
                }
                if ( empty($payload['customer_name']) && !empty($o['customer_name']) ) {
                    $payload['customer_name'] = sanitize_text_field($o['customer_name']);
                }
            }
        }

        return $payload;
    }

    /** REST webhook handler (with signature verification) */
    public function handle_webhook( $request ) {
        // ── 1) Try to verify the Stripe signature if a secret is configured ─────────
        $data = null;

        // Option 1: define in wp-config.php → define('WDSS29_STRIPE_WHSEC', 'whsec_...');
        $secret = defined('WDSS29_STRIPE_WHSEC') ? WDSS29_STRIPE_WHSEC : '';

        // Option 2: store in settings (wdss29_webhook_secret)
        if ( empty( $secret ) ) {
            $opt = get_option( 'wdss29_webhook_secret', '' );
            if ( is_string( $opt ) && $opt !== '' ) {
                $secret = $opt;
            }
        }

        if ( ! empty( $secret ) && class_exists( '\Stripe\Webhook' ) ) {
            $payload    = $request->get_body();
            $sig_header = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';

            try {
                $event = \Stripe\Webhook::constructEvent( $payload, $sig_header, $secret );
                // Convert \Stripe\Event to array for downstream code
                if ( method_exists( $event, 'toJSON' ) ) {
                    $data = json_decode( $event->toJSON(), true );
                } else {
                    $data = json_decode( json_encode( $event ), true );
                }
            } catch ( \Exception $e ) {
                return new WP_REST_Response( array(
                    'ok'    => false,
                    'error' => 'bad_signature',
                    'msg'   => $e->getMessage(),
                ), 400 );
            }
        }

        // Fallback if secret not set / Stripe SDK not loaded
        if ( ! is_array( $data ) ) {
            $data = json_decode( $request->get_body(), true );
        }

        if ( ! is_array( $data ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_payload' ), 400 );
        }

        $type = $data['type'] ?? '';
        $obj  = $this->ap( $data, array('data','object'), array() );

        // ── 2) Accept multiple "paid" signals ───────────────────────────────────────
        $supported = array(
            'payment_intent.succeeded',
            'checkout.session.completed',
            'charge.succeeded',
        );
        if ( ! in_array( $type, $supported, true ) ) {
            do_action( 'wdss_email_trigger', 'order.debug', array(
                'note' => 'ignored_type', 'type' => $type
            ));
            return new WP_REST_Response( array( 'ok' => true, 'note' => 'ignored_type' ), 200 );
        }

        // Trace webhook hit (helps diagnose)
        do_action( 'wdss_email_trigger', 'order.debug', array(
            'note' => 'stripe_webhook_hit', 'type' => $type, 'obj_id' => $this->ap($obj, array('id'), '')
        ) );

        // ── 3) Resolve local order ─────────────────────────────────────────────────
        $order_id = $this->resolve_order_id( $obj );
        if ( $order_id <= 0 ) {
            do_action( 'wdss_email_trigger', 'order.debug', array(
                'note'     => 'stripe_no_order_link',
                'type'     => $type,
                'has_meta' => ! empty( $obj['metadata'] ),
                'obj_id'   => $this->ap($obj, array('id'), ''),
                'email'    => $this->ap($obj, array('customer_details','email'), $this->ap($obj, array('receipt_email'), '')),
            ));
            return new WP_REST_Response( array( 'ok' => true, 'note' => 'no_order_link' ), 200 );
        }

        // ── 4) Build hints (email/name) and mark paid ──────────────────────────────
        $hints = $this->extract_stripe_hints( $obj );

        if ( function_exists( 'wdss29_set_order_status' ) ) {
            wdss29_set_order_status( $order_id, 'paid', $hints );
        } else {
            do_action( 'wdss29_order_paid', $order_id, $hints );
        }

        // ── 5) Safety net: emit order.paid exactly once (correct 3-arg call) ──────
        $idem_key = 'wdss_paid_sent_' . (int) $order_id;
        if ( ! get_transient( $idem_key ) ) {
            $payload = $this->build_email_payload( $order_id, $hints );
            do_action( 'wdss_email_trigger', 'order.paid', (int) $order_id, $payload );

            do_action( 'wdss_email_trigger', 'order.debug', array(
                'note'      => 'order_paid_emitted_direct',
                'order_id'  => (int) $order_id,
                'has_email' => ! empty( $payload['customer_email'] ),
            ));

            set_transient( $idem_key, 1, 10 * MINUTE_IN_SECONDS );
        }

        // ── 6) Wake the poller so queued sends go out right away ───────────────────
        if ( function_exists( 'wp_schedule_single_event' ) ) {
            wp_schedule_single_event( time() + 5, 'wdss_email_order_poller_tick' );
        }

        return new WP_REST_Response( array( 'ok' => true ), 200 );
    }
}

endif;

// Bootstrap
WDSS29_Stripe::instance();
