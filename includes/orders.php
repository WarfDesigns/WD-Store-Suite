<?php
/**
 * Orders — persistence + canonical status transitions
 * Author: Warf Designs LLC
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Get table name */
function wdss29_get_orders_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'wdss29_orders';
}

/**
 * Ensure table exists (dbDelta-safe)
 */
function wdss29_maybe_install_or_upgrade_orders_table() {
    global $wpdb;
    $table = wdss29_get_orders_table_name();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        customer_email VARCHAR(190) NULL,
        customer_name VARCHAR(190) NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'created',
        total DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        meta LONGTEXT NULL,
        PRIMARY KEY  (id),
        KEY status (status),
        KEY created_at (created_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/** Fetch a single order row as array */
function wdss29_get_order( $order_id ) {
    global $wpdb;
    $table = wdss29_get_orders_table_name();
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $order_id ), ARRAY_A );
    if ( $row && ! empty( $row['meta'] ) ) {
        $meta = json_decode( $row['meta'], true );
        if ( is_array( $meta ) ) $row['meta'] = $meta;
    }
    return $row;
}

/** Insert a new order — returns order_id */
function wdss29_insert_order( $args ) {
    global $wpdb;
    $table = wdss29_get_orders_table_name();

    $now = current_time( 'mysql' );
    $data = array(
        'created_at'     => $now,
        'updated_at'     => $now,
        'customer_email' => sanitize_email( $args['customer_email'] ?? '' ),
        'customer_name'  => sanitize_text_field( $args['customer_name'] ?? '' ),
        'status'         => sanitize_text_field( $args['status'] ?? 'created' ),
        'total'          => floatval( $args['total'] ?? 0 ),
        'meta'           => wp_json_encode( $args['meta'] ?? array() ),
    );

    $wpdb->insert( $table, $data, array( '%s','%s','%s','%s','%s','%f','%s' ) );
    $order_id = (int) $wpdb->insert_id;

    // Fire canonical event (emails use idempotency)
    $payload = array(
        'customer_email' => $data['customer_email'],
        'customer_name'  => $data['customer_name'],
        'order_total'    => $data['total'],
    );
    do_action( 'wdss29_order_created', $order_id, $payload );

    return $order_id;
}

/** Update arbitrary order columns (safe) */
function wdss29_update_order( $order_id, $args ) {
    global $wpdb;
    $table = wdss29_get_orders_table_name();

    $data = array( 'updated_at' => current_time( 'mysql' ) );
    $fmt  = array( '%s' );

    if ( array_key_exists( 'customer_email', $args ) ) {
        $data['customer_email'] = sanitize_email( $args['customer_email'] );
        $fmt[] = '%s';
    }
    if ( array_key_exists( 'customer_name', $args ) ) {
        $data['customer_name'] = sanitize_text_field( $args['customer_name'] );
        $fmt[] = '%s';
    }
    if ( array_key_exists( 'total', $args ) ) {
        $data['total'] = floatval( $args['total'] );
        $fmt[] = '%f';
    }
    if ( array_key_exists( 'meta', $args ) ) {
        $meta = is_array( $args['meta'] ) ? $args['meta'] : array();
        $data['meta'] = wp_json_encode( $meta );
        $fmt[] = '%s';
    }

    $wpdb->update( $table, $data, array( 'id' => (int) $order_id ), $fmt, array( '%d' ) );
    return true;
}

/**
 * Canonical status transition (fires hooks exactly once)
 *
 * @param int    $order_id
 * @param string $new_status  e.g., 'paid', 'processing', 'completed', 'cancelled'
 * @param array  $payload     used for email placeholders
 */
function wdss29_set_order_status( $order_id, $new_status, $payload = array() ) {
    global $wpdb;
    $order_id   = (int) $order_id;
    $new_status = sanitize_text_field( $new_status );

    $row = wdss29_get_order( $order_id );
    if ( ! $row ) return false;

    $old_status = (string) $row['status'];
    if ( $old_status === $new_status ) {
        // Nothing to do.
        return true;
    }

    // Write status
    $table = wdss29_get_orders_table_name();
    $wpdb->update(
        $table,
        array( 'status' => $new_status, 'updated_at' => current_time('mysql') ),
        array( 'id' => $order_id ),
        array( '%s','%s' ),
        array( '%d' )
    );

    // Build payload (fill gaps from order row)
    $payload = array_merge(
        array(
            'customer_email' => $row['customer_email'] ?? '',
            'customer_name'  => $row['customer_name'] ?? '',
            'order_total'    => $row['total'] ?? 0,
        ),
        (array) $payload
    );

    // Fire the single canonical hook; email dispatcher is idempotent.
    do_action( 'wdss29_order_status_changed', $order_id, $new_status, $payload );

    // Common case: when a payment succeeds, set to 'paid' and fire wdss29_order_paid.
    if ( $new_status === 'paid' ) {
        do_action( 'wdss29_order_paid', $order_id, $payload );
    }

    return true;
}
