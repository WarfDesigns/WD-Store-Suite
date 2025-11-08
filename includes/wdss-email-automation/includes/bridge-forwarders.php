<?php
/**
 * WDSS Email Bus Forwarders (minimal, always-on)
 * Listens to WDSS order events and forwards them to the email automation bus.
 * Also writes a breadcrumb into the WDSS email log.
 *
 * Author: Warf Designs LLC
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'wdss_forwarder_log' ) ) {
    function wdss_forwarder_log( $type, $meta = array() ) {
        // Append a simple row into the same email log option the emailer uses.
        $log = get_option( 'wdss_email_log_v1', array() );
        if ( ! is_array( $log ) ) $log = array();
        $log[] = array(
            'time'  => current_time( 'mysql', 1 ),
            'type'  => 'bridge.' . $type,
            'meta'  => $meta,
        );
        if ( count( $log ) > 500 ) $log = array_slice( $log, -500 );
        update_option( 'wdss_email_log_v1', $log, false );
    }
}

/**
 * When your code calls: do_action('wdss29_order_created', $order_id, $payload)
 */
add_action( 'wdss29_order_created', function( $order_id, $payload = array() ) {
    $payload = is_array( $payload ) ? $payload : array();
    $payload = array_merge( array(
        'order_id'       => $order_id,
        'site_name'      => get_bloginfo('name'),
        'site_url'       => home_url('/'),
    ), $payload );

    wdss_forwarder_log( 'created', array( 'order_id' => $order_id ) );
    do_action( 'wdss_email_trigger', 'order.created', $order_id, $payload );
}, 10, 2 );

/**
 * When your code calls: do_action('wdss29_order_status_changed', $order_id, $new_status, $payload)
 */
add_action( 'wdss29_order_status_changed', function( $order_id, $new_status, $payload = array() ) {
    $payload = is_array( $payload ) ? $payload : array();
    $payload['order_id']     = $order_id;
    $payload['order_status'] = $new_status;
    $payload['site_name']    = $payload['site_name'] ?? get_bloginfo('name');
    $payload['site_url']     = $payload['site_url']  ?? home_url('/');

    wdss_forwarder_log( 'status_changed', array( 'order_id' => $order_id, 'status' => $new_status ) );
    do_action( 'wdss_email_trigger', 'order.status_changed', $order_id, $payload );
}, 10, 3 );

/**
 * When your code calls: do_action('wdss29_order_paid', $order_id, $payload)
 */
add_action( 'wdss29_order_paid', function( $order_id, $payload = array() ) {
    $payload = is_array( $payload ) ? $payload : array();
    $payload = array_merge( array(
        'order_id'       => $order_id,
        'order_status'   => 'paid',
        'site_name'      => get_bloginfo('name'),
        'site_url'       => home_url('/'),
    ), $payload );

    wdss_forwarder_log( 'paid', array( 'order_id' => $order_id ) );
    do_action( 'wdss_email_trigger', 'order.paid', $order_id, $payload );
}, 10, 2 );
