<?php
/**
 * WD Store Suite — Order Events Bridge (hardened)
 * Fires wdss_email_trigger reliably for order create / status changes
 * no matter whether checkout saves posts, only meta, or edits in wp-admin.
 *
 * Author: Warf Designs LLC
 * Version: 1.0.2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WDSS_Order_Events_Bridge' ) ) :

class WDSS_Order_Events_Bridge {

    private static $instance = null;

    /**
     * If your orders are a custom post type, set it here (e.g. 'wdss_order').
     * Leave empty to detect by presence of order meta keys.
     */
    const ORDER_CPT = '';

    // Known meta keys used by WD Store Suite orders
    const META_STATUS = '_order_status';
    const META_TOTAL  = '_order_total';
    const META_EMAIL  = '_customer_email';
    const META_NAME   = '_customer_name';

    /** Cache of status before save so we can compare after */
    private $pre_save_status = array(); // [post_id => status]

    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Capture status before save (admin edits)
        add_action( 'edit_post', array( $this, 'snapshot_pre_save_status' ), 9, 2 );

        // New inserts & general saves (covers most creation/checkout flows)
        add_action( 'wp_insert_post', array( $this, 'on_wp_insert_post' ), 20, 3 );
        add_action( 'save_post',      array( $this, 'maybe_fire_events_on_save' ), 20, 3 );

        // CPT status transitions (if orders use a CPT post_status)
        add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );

        // Direct meta changes (front-end checkout or programmatic updates)
        add_action( 'updated_post_meta', array( $this, 'on_updated_post_meta' ), 10, 4 );
        add_action( 'added_post_meta',   array( $this, 'on_added_post_meta' ),   10, 4 );

        // Public helper so your checkout can explicitly emit paid/status events if needed:
        add_action( 'wdss_emit_order_event', array( $this, 'manual_emit' ), 10, 3 );
    }

    /** Heuristic: does this post look like one of our orders? */
    private function is_order_post( $post_id, $post_type = '' ) {
        if ( self::ORDER_CPT !== '' && $post_type === self::ORDER_CPT ) return true;
        // If it has our known meta, we treat it as an order.
        $has_total  = get_post_meta( $post_id, self::META_TOTAL, true );
        $has_status = get_post_meta( $post_id, self::META_STATUS, true );
        return ( $has_total !== '' || $has_status !== '' );
    }

    /** Build a consistent payload */
    private function build_payload( $post_id, $status_hint = '' ) {
        $status = ( $status_hint !== '' ) ? $status_hint : get_post_meta( $post_id, self::META_STATUS, true );
        return array(
            'order_id'       => $post_id,
            'order_status'   => $status,
            'order_total'    => get_post_meta( $post_id, self::META_TOTAL, true ),
            'customer_name'  => get_post_meta( $post_id, self::META_NAME,  true ),
            'customer_email' => get_post_meta( $post_id, self::META_EMAIL, true ),
            'site_name'      => get_bloginfo('name'),
            'site_url'       => home_url('/'),
        );
    }

    /** Log into the WDSS email log so we can see the bridge firing */
    private function trace_log( $type, $meta = array() ) {
        do_action( 'wdss_email_trigger', 'bridge.trace', 0, array(
            'trace' => $type,
            'meta'  => $meta,
            'time'  => current_time('mysql'),
        ) );
    }

    /** Snapshot current status before save */
    public function snapshot_pre_save_status( $post_id, $post = null ) {
        if ( ! $post_id || wp_is_post_revision( $post_id ) ) return;
        $this->pre_save_status[ $post_id ] = get_post_meta( $post_id, self::META_STATUS, true );
    }

    /** When a post is inserted for the first time */
    public function on_wp_insert_post( $post_id, $post, $update ) {
        if ( $update || ! $post_id ) return; // only brand-new
        if ( ! $this->is_order_post( $post_id, $post->post_type ) ) return;

        $payload = $this->build_payload( $post_id );
        $this->trace_log( 'created', array( 'post' => $post_id ) );
        do_action( 'wdss_email_trigger', 'order.created', $post_id, $payload );

        // If status already set (e.g., checkout wrote meta before insert complete)
        $status = get_post_meta( $post_id, self::META_STATUS, true );
        if ( $status !== '' ) {
            $payload['order_status'] = $status;
            do_action( 'wdss_email_trigger', 'order.status_changed', $post_id, $payload );
            if ( in_array( strtolower( $status ), array( 'paid', 'completed' ), true ) ) {
                do_action( 'wdss_email_trigger', 'order.paid', $post_id, $payload );
            }
        }
    }

    /** On any save (covers admin edits and many programmatic writes) */
    public function maybe_fire_events_on_save( $post_id, $post, $update ) {
        if ( ! $post_id || wp_is_post_revision( $post_id ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! $this->is_order_post( $post_id, $post->post_type ) ) return;

        if ( ! $update ) {
            // creation already handled in on_wp_insert_post, but safe to double-check
            $payload = $this->build_payload( $post_id );
            $this->trace_log( 'created(save_post)', array( 'post' => $post_id ) );
            do_action( 'wdss_email_trigger', 'order.created', $post_id, $payload );
        }

        // Compare status before/after to detect changes that bypass meta hooks
        $before = isset( $this->pre_save_status[ $post_id ] ) ? $this->pre_save_status[ $post_id ] : '';
        $after  = get_post_meta( $post_id, self::META_STATUS, true );

        if ( $after !== '' && $after !== $before ) {
            $payload = $this->build_payload( $post_id, $after );
            $this->trace_log( 'status_changed(save_post)', array( 'post' => $post_id, 'before' => $before, 'after' => $after ) );
            do_action( 'wdss_email_trigger', 'order.status_changed', $post_id, $payload );

            $norm = strtolower( (string) $after );
            if ( in_array( $norm, array( 'paid', 'completed' ), true ) ) {
                do_action( 'wdss_email_trigger', 'order.paid', $post_id, $payload );
            }
        }
    }

    /** If the CPT uses post_status to represent order state */
    public function on_transition_post_status( $new_status, $old_status, $post ) {
        if ( ! $post || $new_status === $old_status ) return;
        if ( ! $this->is_order_post( $post->ID, $post->post_type ) ) return;

        // Map WP status to our order_status only if meta not set
        $meta_status = get_post_meta( $post->ID, self::META_STATUS, true );
        $status = $meta_status !== '' ? $meta_status : $new_status;
        $payload = $this->build_payload( $post->ID, $status );

        $this->trace_log( 'status_changed(transition)', array( 'post' => $post->ID, 'old' => $old_status, 'new' => $new_status, 'meta' => $meta_status ) );
        do_action( 'wdss_email_trigger', 'order.status_changed', $post->ID, $payload );

        $norm = strtolower( (string) $status );
        if ( in_array( $norm, array( 'paid', 'completed' ), true ) ) {
            do_action( 'wdss_email_trigger', 'order.paid', $post->ID, $payload );
        }
    }

    /** Meta updated directly (front-end checkout, programmatic writes) */
    public function on_updated_post_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
        if ( $meta_key !== self::META_STATUS ) return;
        if ( ! $this->is_order_post( $post_id ) ) return;

        $status  = is_string( $meta_value ) ? $meta_value : get_post_meta( $post_id, self::META_STATUS, true );
        $payload = $this->build_payload( $post_id, $status );

        $this->trace_log( 'status_changed(updated_meta)', array( 'post' => $post_id, 'status' => $status ) );
        do_action( 'wdss_email_trigger', 'order.status_changed', $post_id, $payload );

        $norm = strtolower( (string) $status );
        if ( in_array( $norm, array( 'paid', 'completed' ), true ) ) {
            do_action( 'wdss_email_trigger', 'order.paid', $post_id, $payload );
        }
    }

    /** Meta added for the first time */
    public function on_added_post_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
        if ( $meta_key !== self::META_STATUS ) return;
        if ( ! $this->is_order_post( $post_id ) ) return;

        $status  = is_string( $meta_value ) ? $meta_value : get_post_meta( $post_id, self::META_STATUS, true );
        $payload = $this->build_payload( $post_id, $status );

        $this->trace_log( 'status_changed(added_meta)', array( 'post' => $post_id, 'status' => $status ) );
        do_action( 'wdss_email_trigger', 'order.status_changed', $post_id, $payload );
    }

    /** Allow explicit emits from checkout/custom code: do_action('wdss_emit_order_event', 'paid', $order_id, $payload ) */
    public function manual_emit( $event, $order_id = 0, $payload = array() ) {
        $order_id = intval( $order_id );
        if ( $order_id > 0 && empty( $payload['order_id'] ) ) {
            $payload = array_merge( $this->build_payload( $order_id ), (array) $payload );
        }
        $map = array(
            'created'        => 'order.created',
            'paid'           => 'order.paid',
            'status_changed' => 'order.status_changed',
        );
        $key = isset( $map[ $event ] ) ? $map[ $event ] : $event;
        $this->trace_log( 'manual_emit', array( 'key' => $key, 'order' => $order_id ) );
        do_action( 'wdss_email_trigger', $key, $order_id, $payload );
    }
}

endif;

// Bootstrap
WDSS_Order_Events_Bridge::instance();
