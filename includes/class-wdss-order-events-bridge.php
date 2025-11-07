<?php
/**
 * WD Store Suite — Order Events Bridge
 * Ensures wdss_email_trigger fires when orders are created or their status changes,
 * even when edited in wp-admin or saved via custom flows.
 *
 * Author: Warf Designs LLC
 * Version: 1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WDSS_Order_Events_Bridge' ) ) :

class WDSS_Order_Events_Bridge {

    private static $instance = null;

    /**
     * If you have a dedicated Order CPT, set it (e.g. 'wdss_order').
     * Leave empty to detect by presence of order meta keys.
     */
    const ORDER_CPT = '';

    // Meta keys used by WD Store Suite orders
    const META_STATUS = '_order_status';
    const META_TOTAL  = '_order_total';
    const META_EMAIL  = '_customer_email';
    const META_NAME   = '_customer_name';

    /** Cache status prior to save so we can compare after save */
    private $pre_save_status = array(); // [post_id => status]

    /** Singleton */
    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        /**
         * Hook order lifecycle defensively:
         * - snapshot_pre_save_status must accept 2 args (edit_post passes 2)
         * - save_post with 3 args
         * - meta changes (added/updated) with 4 args
         */
        add_action( 'edit_post', array( $this, 'snapshot_pre_save_status' ), 9, 2 );
        add_action( 'save_post', array( $this, 'maybe_fire_events_on_save' ), 20, 3 );
        add_action( 'updated_post_meta', array( $this, 'on_updated_post_meta' ), 10, 4 );
        add_action( 'added_post_meta',   array( $this, 'on_added_post_meta' ),   10, 4 );
    }

    /** Determine if a post is an order (by CPT or by known meta keys) */
    private function is_order_post( $post_id, $post_type = '' ) {
        if ( self::ORDER_CPT !== '' && $post_type === self::ORDER_CPT ) return true;

        // Heuristic: if it has our order meta, treat as order.
        $has_total  = get_post_meta( $post_id, self::META_TOTAL, true );
        $has_status = get_post_meta( $post_id, self::META_STATUS, true );
        return ( $has_total !== '' || $has_status !== '' );
    }

    /** Standard payload builder */
    private function build_payload( $post_id, $status_hint = '' ) {
        $status = ( $status_hint !== '' ) ? $status_hint : get_post_meta( $post_id, self::META_STATUS, true );

        return array(
            'order_id'       => $post_id,
            'order_status'   => $status,
            'order_total'    => get_post_meta( $post_id, self::META_TOTAL, true ),
            'customer_name'  => get_post_meta( $post_id, self::META_NAME,  true ),
            'customer_email' => get_post_meta( $post_id, self::META_EMAIL, true ),
            'site_name'      => get_bloginfo( 'name' ),
            'site_url'       => home_url( '/' ),
        );
    }

    /**
     * Capture current status *before* save so we can compare after.
     * Signature must accept 2 params because edit_post passes ($post_id, $post).
     */
    public function snapshot_pre_save_status( $post_id, $post = null ) {
        if ( ! $post_id || wp_is_post_revision( $post_id ) ) return;
        $this->pre_save_status[ $post_id ] = get_post_meta( $post_id, self::META_STATUS, true );
    }

    /** On save, emit created and/or status_changed events reliably */
    public function maybe_fire_events_on_save( $post_id, $post, $update ) {
        if ( ! $post_id || wp_is_post_revision( $post_id ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

        if ( ! $this->is_order_post( $post_id, $post->post_type ) ) return;

        // Emit "created" once on initial insert
        if ( ! $update ) {
            $payload = $this->build_payload( $post_id );
            do_action( 'wdss_email_trigger', 'order.created', $post_id, $payload );
        }

        // Compare status before vs after to catch admin edits that bypass meta hooks
        $before = isset( $this->pre_save_status[ $post_id ] ) ? $this->pre_save_status[ $post_id ] : '';
        $after  = get_post_meta( $post_id, self::META_STATUS, true );

        if ( $after !== '' && $after !== $before ) {
            $payload = $this->build_payload( $post_id, $after );
            do_action( 'wdss_email_trigger', 'order.status_changed', $post_id, $payload );

            // Convenience: consider "paid" or "completed" as a paid event
            $normalized = strtolower( (string) $after );
            if ( in_array( $normalized, array( 'paid', 'completed' ), true ) ) {
                do_action( 'wdss_email_trigger', 'order.paid', $post_id, $payload );
            }
        }
    }

    /** Direct meta update (front-end checkout or programmatic) */
    public function on_updated_post_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
        if ( $meta_key !== self::META_STATUS ) return;
        if ( ! $this->is_order_post( $post_id ) ) return;

        $status  = is_string( $meta_value ) ? $meta_value : get_post_meta( $post_id, self::META_STATUS, true );
        $payload = $this->build_payload( $post_id, $status );

        do_action( 'wdss_email_trigger', 'order.status_changed', $post_id, $payload );

        $normalized = strtolower( (string) $status );
        if ( in_array( $normalized, array( 'paid', 'completed' ), true ) ) {
            do_action( 'wdss_email_trigger', 'order.paid', $post_id, $payload );
        }
    }

    /** Meta added for the first time */
    public function on_added_post_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
        if ( $meta_key !== self::META_STATUS ) return;
        if ( ! $this->is_order_post( $post_id ) ) return;

        $status  = is_string( $meta_value ) ? $meta_value : get_post_meta( $post_id, self::META_STATUS, true );
        $payload = $this->build_payload( $post_id, $status );
        do_action( 'wdss_email_trigger', 'order.status_changed', $post_id, $payload );
    }
}

endif;

// Bootstrap (guarded)
if ( class_exists( 'WDSS_Order_Events_Bridge' ) ) {
    WDSS_Order_Events_Bridge::instance();
}
