<?php
/**
 * Stripe gateway — capture + post-payment event
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

    /**
     * Safe getter for nested arrays.
     */
    private function ap( $arr, $path, $default = null ) {
        $cur = $arr;
        foreach ( (array) $path as $k ) {
            if ( ! is_array( $cur ) || ! array_key_exists( $k, $cur ) ) return $default;
            $cur = $cur[ $k ];
        }
        return $cur;
    }

    /**
     * Extract useful customer/buyer hints from a Stripe object (PI / Charge / Session).
     */
    private function extract_stripe_hints( $obj ) {
        $hints = array();

        // From PaymentIntent -> charges[0].billing_details
        $bd = $this->ap( $obj, array('charges','data',0,'billing_details'), array() );
        if ( ! empty( $bd ) ) {
            if ( ! empty( $bd['email'] ) ) $hints['customer_email'] = sanitize_email( $bd['email'] );
            if ( ! empty( $bd['name'] ) )  $hints['customer_name']  = sanitize_text_field( $bd['name'] );
        }

        // From Charge directly
        $bd2 = $this->ap( $obj, array('billing_details'), array() );
        if ( ! empty( $bd2 ) ) {
            if ( empty( $hints['customer_email'] ) && ! empty( $bd2['email'] ) ) $hints['customer_email'] = sanitize_email( $bd2['email'] );
            if ( empty( $hints['customer_name'] )  && ! empty( $bd2['name'] ) )  $hints['customer_name']  = sanitize_text_field( $bd2['name'] );
        }

        // From Checkout Session
        if ( empty( $hints['customer_email'] ) ) {
            $session_email = $this->ap( $obj, array('customer_details','email') );
            if ( $session_email ) $hints['customer_email'] = sanitize_email( $session_email );
        }
        if ( empty( $hints['customer_name'] ) ) {
            $session_name = $this->ap( $obj, array('customer_details','name') );
            if ( $session_name ) $hints['customer_name'] = sanitize_text_field( $session_name );
        }

        // Top-level fallbacks
        if ( empty( $hints['customer_email'] ) ) {
            $receipt = $this->ap( $obj, array('receipt_email') );
            if ( $receipt ) $hints['customer_email'] = sanitize_email( $receipt );
        }

        return $hints;
    }

    /**
     * Pull order_id from metadata on PI / Charge / Session or client_reference_id.
     * The metadata key is filterable: wdss29_stripe_order_meta_key (default: 'order_id')
     */
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

        // Checkout Session.client_reference_id (often used to carry local id)
        $cri = $this->ap( $payload_obj, array('client_reference_id') );
        if ( $cri ) return (int) $cri;

        return 0;
    }

    public function handle_webhook( $request ) {
        $data = json_decode( $request->get_body(), true );
        if ( ! is_array( $data ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_payload' ), 400 );
        }

        $type = $data['type'] ?? '';

        // Normalize to a single $obj we can read from
        $obj = $this->ap( $data, array('data','object'), array() );

        // Support PaymentIntent, Checkout Session, and Charge success events
        $supported = array(
            'payment_intent.succeeded',
            'checkout.session.completed',
            'charge.succeeded',
        );
        if ( ! in_array( $type, $supported, true ) ) {
            // Not a type we care about; acknowledge.
            return new WP_REST_Response( array( 'ok' => true, 'note' => 'ignored_type' ), 200 );
        }

        // Resolve local order id from metadata/client_reference_id
        $order_id = $this->resolve_order_id( $obj );
        if ( $order_id <= 0 ) {
            // Emit a debug event you can watch in your diagnostics
            do_action( 'wdss_email_trigger', 'order.debug', array(
                'note'     => 'stripe_no_order_link',
                'type'     => $type,
                'pi_id'    => $this->ap($obj, array('id'), ''),
                'has_meta' => ! empty( $obj['metadata'] ),
                'email'    => $this->ap($obj, array('customer_details','email'), $this->ap($obj, array('receipt_email'), '')),
            ));
            return new WP_REST_Response( array( 'ok' => true, 'note' => 'no_order_link' ), 200 );
        }

        // Build email/name hints so the automation has a recipient
        $hints = $this->extract_stripe_hints( $obj );

        // Merge local order info if available
        if ( function_exists( 'wdss29_get_order' ) ) {
            $order = wdss29_get_order( $order_id );
            if ( is_array( $order ) ) {
                if ( empty( $hints['customer_email'] ) && ! empty( $order['customer_email'] ) ) {
                    $hints['customer_email'] = sanitize_email( $order['customer_email'] );
                }
                if ( empty( $hints['customer_name'] ) && ! empty( $order['customer_name'] ) ) {
                    $hints['customer_name'] = sanitize_text_field( $order['customer_name'] );
                }
                if ( ! isset( $hints['order_total'] ) ) {
                    $hints['order_total'] = isset( $order['total'] ) ? (float) $order['total'] : 0;
                }
            }
        }

        // Canonical: mark as paid (orders.php will emit wdss_email_trigger('order.paid', $payload))
        if ( function_exists( 'wdss29_set_order_status' ) ) {
            wdss29_set_order_status( $order_id, 'paid', $hints );
        } else {
            // Legacy fallback
            do_action( 'wdss29_order_paid', $order_id, $hints );
        }

        // Wake the email poller shortly so queued emails process immediately
        if ( function_exists( 'wp_schedule_single_event' ) ) {
            wp_schedule_single_event( time() + 5, 'wdss_email_order_poller_tick' );
        }

        return new WP_REST_Response( array( 'ok' => true ), 200 );
    }
}

endif;

// Bootstrap
WDSS29_Stripe::instance();
