<?php
/**
 * Orders — persistence + status transitions
 * Author: Warf Designs LLC
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Table name */
function wdss29_get_orders_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'wdss29_orders';
}

/** Install/upgrade table safely */
function wdss29_maybe_install_or_upgrade_orders_table() {
    global $wpdb;
    $table = wdss29_get_orders_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        number VARCHAR(64) DEFAULT '',
        status VARCHAR(20) DEFAULT 'pending',
        currency VARCHAR(10) DEFAULT 'USD',
        subtotal DECIMAL(12,2) DEFAULT 0,
        tax DECIMAL(12,2) DEFAULT 0,
        shipping DECIMAL(12,2) DEFAULT 0,
        total DECIMAL(12,2) DEFAULT 0,
        customer_email VARCHAR(200) DEFAULT '',
        customer_name  VARCHAR(200) DEFAULT '',
        items LONGTEXT NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY  (id),
        KEY status (status),
        KEY user_id (user_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/** Insert order row */
function wdss29_insert_order( $data ) {
    global $wpdb;
    $table = wdss29_get_orders_table_name();
    $defaults = array(
        'user_id'        => get_current_user_id(),
        'number'         => '',
        'status'         => 'pending',
        'currency'       => 'USD',
        'subtotal'       => 0,
        'tax'            => 0,
        'shipping'       => 0,
        'total'          => 0,
        'customer_email' => '',
        'customer_name'  => '',
        'items'          => '',
        'created_at'     => current_time( 'mysql' ),
        'updated_at'     => current_time( 'mysql' ),
    );
    $row = wp_parse_args( $data, $defaults );
    $wpdb->insert( $table, $row );
    $order_id = (int) $wpdb->insert_id;

    // Emit create events (correct argument count)
    $payload = array_merge( $row, array( 'order_id' => $order_id ) );
    do_action( 'wdss_emit_order_event', 'created', $order_id, $payload );
    do_action( 'wdss_email_trigger', 'order.created', (int) $order_id, $payload );

    return $order_id;
}

/** Get order row (array) */
function wdss29_get_order( $order_id ) {
    global $wpdb;
    $table = wdss29_get_orders_table_name();
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $order_id ), ARRAY_A );
    if ( ! $row ) return null;
    $row['order_id'] = (int) $order_id;
    return $row;
}

/** Update order fields */
function wdss29_update_order( $order_id, $data ) {
    global $wpdb;
    $table = wdss29_get_orders_table_name();
    $data['updated_at'] = current_time( 'mysql' );
    $wpdb->update( $table, $data, array( 'id' => (int) $order_id ) );
    return true;
}

/** Set order status + fire canonical events */
function wdss29_set_order_status( $order_id, $new_status, $payload = array() ) {
    global $wpdb;
    $table = wdss29_get_orders_table_name();

    $wpdb->update( $table, array(
        'status'     => $new_status,
        'updated_at' => current_time( 'mysql' ),
    ), array( 'id' => (int) $order_id ) );

    if ( ! is_array( $payload ) ) $payload = array();
    $payload = array_merge( array( 'order_id' => (int) $order_id, 'order_status' => $new_status ), $payload );

    do_action( 'wdss29_order_status_changed', $order_id, $new_status, $payload );
    do_action( 'wdss_emit_order_event', 'status_changed', $order_id, $payload );

    // Correct 3-argument email bus calls
    do_action( 'wdss_email_trigger', 'order.status_changed', (int) $order_id, $payload );

    if ( $new_status === 'paid' ) {
        do_action( 'wdss29_order_paid', $order_id, $payload );
        do_action( 'wdss_emit_order_event', 'paid', $order_id, $payload );
        do_action( 'wdss_email_trigger', 'order.paid', (int) $order_id, $payload );
    }

    return true;
}
