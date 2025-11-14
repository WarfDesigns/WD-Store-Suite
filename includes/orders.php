<?php
/**
 * Orders — persistence + status transitions
 * Author: Warf Designs LLC
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Get table name */
function wdss29_get_orders_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'wdss29_orders';
}

/** Create/upgrade table */
function wdss29_maybe_install_or_upgrade_orders_table() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table   = wdss29_get_orders_table_name();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        number VARCHAR(64) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'created',
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
        tax DECIMAL(12,2) NOT NULL DEFAULT 0,
        shipping DECIMAL(12,2) NOT NULL DEFAULT 0,
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        currency VARCHAR(8) NOT NULL DEFAULT 'USD',
        payment_method VARCHAR(32) NOT NULL DEFAULT '',
        customer_email VARCHAR(190) NOT NULL DEFAULT '',
        customer_name VARCHAR(190) NOT NULL DEFAULT '',
        items LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY status (status),
        KEY email (customer_email)
    ) {$charset};";

    dbDelta( $sql );
}

/** Create order row + emit 'order.created' */
function wdss29_create_order( $args ) {
    global $wpdb;
    $table = wdss29_get_orders_table_name();

    $defaults = array(
        'number'         => '',
        'status'         => 'created',
        'subtotal'       => 0,
        'tax'            => 0,
        'shipping'       => 0,
        'total'          => 0,
        'currency'       => get_option('wdss_currency','USD'),
        'payment_method' => '',
        'customer_email' => '',
        'customer_name'  => '',
        'items'          => array(),
    );
    $r = wp_parse_args( $args, $defaults );

    $data = array(
        'number'         => sanitize_text_field( $r['number'] ),
        'status'         => sanitize_text_field( $r['status'] ),
        'subtotal'       => (float) $r['subtotal'],
        'tax'            => (float) $r['tax'],
        'shipping'       => (float) $r['shipping'],
        'total'          => (float) $r['total'],
        'currency'       => sanitize_text_field( $r['currency'] ),
        'payment_method' => sanitize_text_field( $r['payment_method'] ),
        'customer_email' => sanitize_email( $r['customer_email'] ),
        'customer_name'  => sanitize_text_field( $r['customer_name'] ),
        'items'          => maybe_serialize( is_array($r['items']) ? $r['items'] : array() ),
        'created_at'     => current_time( 'mysql' ),
        'updated_at'     => current_time( 'mysql' ),
    );
    $fmt = array( '%s','%s','%f','%f','%f','%f','%s','%s','%s','%s','%s','%s','%s' );

    $ok = $wpdb->insert( $table, $data, $fmt );
    if ( ! $ok ) return false;

    $order_id = (int) $wpdb->insert_id;

    $payload = array(
        'order_id'       => $order_id,
        'order_number'   => $data['number'] ?: (string)$order_id,
        'order_status'   => $data['status'],
        'order_subtotal' => (float)$data['subtotal'],
        'order_tax'      => (float)$data['tax'],
        'order_shipping' => (float)$data['shipping'],
        'order_total'    => (float)$data['total'],
        'currency'       => $data['currency'],
        'payment_method' => $data['payment_method'],
        'customer_email' => $data['customer_email'],
        'customer_name'  => $data['customer_name'],
        'items'          => is_array($r['items']) ? $r['items'] : array(),
        'created_at'     => $data['created_at'],
        '_idem_key'      => 'order.created|' . $order_id,
    );

    do_action( 'wdss29_order_created', $order_id, $payload );
    do_action( 'wdss_emit_order_event', 'created', $order_id, $payload );

    // FIXED: Email Automations bus wants (event_key, object_id, payload)
    do_action( 'wdss_email_trigger', 'order.created', $order_id, $payload );

    return $order_id;
}

/** Get order row */
function wdss29_get_order( $order_id ) {
    global $wpdb;
    $table = wdss29_get_orders_table_name();
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int)$order_id ), ARRAY_A );
    if ( ! $row ) return false;

    $row['items'] = maybe_unserialize( $row['items'] );
    if ( ! is_array( $row['items'] ) ) $row['items'] = array();

    return $row;
}

/** Update + emit status events */
function wdss29_set_order_status( $order_id, $new_status, $payload = array() ) {
    global $wpdb;
    $order_id   = (int) $order_id;
    $new_status = sanitize_text_field( $new_status );

    $row = wdss29_get_order( $order_id );
    if ( ! $row ) return false;

    $old_status = (string) $row['status'];
    if ( $old_status === $new_status ) return true;

    $table = wdss29_get_orders_table_name();
    $wpdb->update(
        $table,
        array( 'status' => $new_status, 'updated_at' => current_time( 'mysql' ) ),
        array( 'id' => $order_id ),
        array( '%s','%s' ),
        array( '%d' )
    );

    $payload = wp_parse_args( (array)$payload, array(
        'order_id'       => $order_id,
        'order_number'   => $row['number'] ?: (string)$order_id,
        'order_status'   => $new_status,
        'order_subtotal' => (float)$row['subtotal'],
        'order_tax'      => (float)$row['tax'],
        'order_shipping' => (float)$row['shipping'],
        'order_total'    => (float)$row['total'],
        'currency'       => $row['currency'],
        'payment_method' => $row['payment_method'],
        'customer_email' => $row['customer_email'],
        'customer_name'  => $row['customer_name'],
        'items'          => $row['items'],
        'created_at'     => $row['created_at'],
        '_idem_key'      => 'order.status_changed|' . $order_id . '|' . $new_status,
    ) );

    do_action( 'wdss29_order_status_changed', $order_id, $new_status, $payload );
    do_action( 'wdss_emit_order_event', 'status_changed', $order_id, $payload );

    // FIXED: Email Automations bus wants (event_key, object_id, payload)
    do_action( 'wdss_email_trigger', 'order.status_changed', $order_id, $payload );

    if ( $new_status === 'paid' ) {
        do_action( 'wdss29_order_paid', $order_id, $payload );
        do_action( 'wdss_emit_order_event', 'paid', $order_id, $payload );
        do_action( 'wdss_email_trigger', 'order.paid', $order_id, $payload );
    }

    return true;
}
