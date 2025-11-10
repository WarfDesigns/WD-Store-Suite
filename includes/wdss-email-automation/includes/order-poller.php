<?php
/**
 * WDSS Email Order Poller — processes queued emails
 * Author: Warf Designs LLC
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists('wdss29_email_order_poller_run') ) {
    function wdss29_email_order_poller_run() {
        // Heartbeat/debug so you can see ticks in your diagnostics (optional).
        do_action( 'wdss_email_trigger', 'order.debug', array(
            'note' => 'poller_tick',
            'time' => current_time( 'mysql' ),
        ) );

        // If your queue processor exists, run it; otherwise no-op.
        if ( function_exists( 'wdss29_process_email_queue' ) ) {
            wdss29_process_email_queue();
        }
    }
}
add_action( 'wdss_email_order_poller_tick', 'wdss29_email_order_poller_run' );
