<?php
/**
 * WD Store Suite — Core Bootstrap
 * Author: Warf Designs LLC
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Load diagnostics UI
require_once __DIR__ . '/admin/class-wdss-email-diagnostics.php';

if ( ! class_exists( 'WDSS29_Core' ) ) :

class WDSS29_Core {

    const VERSION = '2.9.0';

    /** @var self */
    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Ensure DB/table exists
        add_action( 'init', array( $this, 'maybe_install' ) );

        // Prefer HTML mail unless headers override
        add_filter( 'wp_mail_content_type', array( $this, 'force_html_content_type' ) );

        // Canonical, single-fire hooks for emails
        add_action( 'wdss29_order_created',         array( $this, 'on_order_created' ), 10, 2 );
        add_action( 'wdss29_order_paid',            array( $this, 'on_order_paid' ), 10, 2 );
        add_action( 'wdss29_order_status_changed',  array( $this, 'on_order_status_changed' ), 10, 3 );
    }

    public function maybe_install() {
        if ( function_exists( 'wdss29_maybe_install_or_upgrade_orders_table' ) ) {
            wdss29_maybe_install_or_upgrade_orders_table();
        }
    }

    public function force_html_content_type( $type ) {
        return 'text/html';
    }

    public function on_order_created( $order_id, $payload = array() ) {
        // Fallback recipient to admin for diagnostics if customer_email missing
        if ( empty( $payload['customer_email'] ) ) $payload['customer_email'] = get_option('admin_email');
        if ( function_exists( 'wdss29_email_on_order_created' ) ) {
            wdss29_email_on_order_created( (int) $order_id, (array) $payload );
        }
    }

    public function on_order_paid( $order_id, $payload = array() ) {
        if ( empty( $payload['customer_email'] ) ) $payload['customer_email'] = get_option('admin_email');
        if ( function_exists( 'wdss29_email_on_order_paid' ) ) {
            wdss29_email_on_order_paid( (int) $order_id, (array) $payload );
        }
    }

    public function on_order_status_changed( $order_id, $new_status, $payload = array() ) {
        if ( empty( $payload['customer_email'] ) ) $payload['customer_email'] = get_option('admin_email');
        if ( function_exists( 'wdss29_email_on_status_changed' ) ) {
            wdss29_email_on_status_changed( (int) $order_id, (string) $new_status, (array) $payload );
        }
    }
}

endif;

// Bootstrap
WDSS29_Core::instance();
