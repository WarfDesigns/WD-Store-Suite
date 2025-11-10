<?php
/**
 * WDSS Order Poller
 * Guarantees email automations run even if the checkout path
 * doesn't call wdss29_insert_order / wdss29_set_order_status.
 *
 * Scans the wdss29_orders table periodically and emits:
 *   - order.created  (for newly discovered IDs)
 *   - order.status_changed / order.paid (when status changes)
 *
 * Author: Warf Designs LLC
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'wdss29_get_orders_table_name' ) ) {
    // Safety: if orders.php isn't loaded yet.
    function wdss29_get_orders_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'wdss29_orders';
    }
}

class WDSS_Order_Poller {
    const OPTION_MAP      = 'wdss_email_seen_orders_v1'; // id => status
    const HOOK_TICK       = 'wdss_email_order_poller_tick';
    const SCHEDULE_SLUG   = 'wdss_minutely';

    public static function boot() {
        add_filter( 'cron_schedules', [ __CLASS__, 'register_minutely' ] );
        add_action( 'init', [ __CLASS__, 'ensure_cron' ] );

        // Recurring tick
        add_action( self::HOOK_TICK, [ __CLASS__, 'scan_and_emit' ] );

        // One-click manual run (useful while testing)
        add_action( 'admin_post_wdss_run_order_poller', [ __CLASS__, 'manual_run' ] );
    }

    public static function register_minutely( $schedules ) {
        if ( empty( $schedules[ self::SCHEDULE_SLUG ] ) ) {
            $schedules[ self::SCHEDULE_SLUG ] = [
                'interval' => 60,
                'display'  => __( 'Every minute (WDSS)', 'wdss' ),
            ];
        }
        return $schedules;
    }

    public static function ensure_cron() {
        if ( ! wp_next_scheduled( self::HOOK_TICK ) ) {
            wp_schedule_event( time() + 30, self::SCHEDULE_SLUG, self::HOOK_TICK );
        }
    }

    public static function manual_run() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Denied.' );
        self::scan_and_emit();
        wp_safe_redirect( wp_get_referer() ?: admin_url() );
        exit;
    }

    public static function scan_and_emit() {
        global $wpdb;

        $table = wdss29_get_orders_table_name();
        if ( empty( $table ) ) return;

        // Pull the last 200 by updated_at (covers new + changed)
        $rows = $wpdb->get_results(
            "SELECT id, status, total, customer_email, customer_name, updated_at
             FROM {$table}
             ORDER BY updated_at DESC
             LIMIT 200",
            ARRAY_A
        );
        if ( empty( $rows ) ) return;

        $seen = get_option( self::OPTION_MAP, [] );
        if ( ! is_array( $seen ) ) $seen = [];

        foreach ( $rows as $row ) {
            $id     = (int) ($row['id'] ?? 0);
            if ( $id <= 0 ) continue;

            $status = (string) ($row['status'] ?? '');
            $payload = [
                'order_id'       => $id,
                'order_status'   => $status,
                'order_total'    => (float) ($row['total'] ?? 0),
                'customer_email' => (string) ($row['customer_email'] ?? ''),
                'customer_name'  => (string) ($row['customer_name'] ?? ''),
                'site_name'      => get_bloginfo('name'),
                'site_url'       => home_url('/'),
            ];

            if ( ! array_key_exists( $id, $seen ) ) {
                // New order discovered
                self::log('poller.created', ['id' => $id, 'status' => $status]);
                do_action( 'wdss_email_trigger', 'order.created', $id, $payload );
                $seen[ $id ] = $status;
            } elseif ( $seen[ $id ] !== $status ) {
                // Status changed
                self::log('poller.status_changed', ['id' => $id, 'from' => $seen[$id], 'to' => $status]);
                do_action( 'wdss_email_trigger', 'order.status_changed', $id, $payload );

                if ( in_array( strtolower($status), ['paid','completed'], true ) ) {
                    do_action( 'wdss_email_trigger', 'order.paid', $id, $payload );
                }
                $seen[ $id ] = $status;
            }
        }

        // Keep map reasonably small
        if ( count( $seen ) > 2000 ) {
            $seen = array_slice( $seen, 0, 2000, true );
        }
        update_option( self::OPTION_MAP, $seen, false );
    }

    private static function log( $type, $meta = [] ) {
        $log = get_option( 'wdss_email_log_v1', [] );
        if ( ! is_array( $log ) ) $log = [];
        $log[] = [
            'time' => current_time('mysql', 1),
            'type' => $type,
            'meta' => $meta,
        ];
        if ( count( $log ) > 500 ) $log = array_slice( $log, -500 );
        update_option( 'wdss_email_log_v1', $log, false );
    }
}

WDSS_Order_Poller::boot();
