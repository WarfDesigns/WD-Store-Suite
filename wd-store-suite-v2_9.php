<?php
/**
 * Plugin Name: WD Store Suite
 * Plugin URI: https://warfdesigns.com/
 * Description: Complete eCommerce system with cart, orders, Stripe checkout, and email automation.
 * Version: 2.9
 * Author: Warf Designs LLC
 * Author URI: https://warfdesigns.com/
 * License: GPL2
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Define constants
define( 'WDSS29_PATH', plugin_dir_path( __FILE__ ) );
define( 'WDSS29_URL',  plugin_dir_url( __FILE__ ) );
define( 'WDSS29_VERSION', '2.9' );

// Core includes
require_once WDSS29_PATH . 'includes/class-wdss29-core.php';
require_once WDSS29_PATH . 'includes/class-wdss29-email-templates.php';

require_once WDSS29_PATH . 'includes/cart.php';
require_once WDSS29_PATH . 'includes/products.php';
require_once WDSS29_PATH . 'includes/orders.php';
require_once WDSS29_PATH . 'includes/admin-settings.php';
require_once WDSS29_PATH . 'includes/shortcodes.php';
require_once WDSS29_PATH . 'includes/admin-import-export.php';
require_once WDSS29_PATH . 'includes/admin-product-editor.php';
require_once WDSS29_PATH . 'includes/payments/class-wdss-stripe.php';
require_once WDSS29_PATH . 'includes/payments/class-wdss-stripe-webhook.php';
require_once WDSS29_PATH . 'includes/payments/page-checkout-success.php';
require_once WDSS29_PATH . 'includes/admin-orders.php';
require_once WDSS29_PATH . 'includes/wdss-email-automation/includes/order-poller.php';

// NO separate page-checkout-success.php needed if core has the handlers.



// Email automation (bridge + forwarders + engine)
$__wdss_bridge = WDSS29_PATH . 'includes/wdss-email-automation/includes/class-wdss-order-events-bridge.php';
if ( file_exists( $__wdss_bridge ) ) {
    require_once $__wdss_bridge;
} else {
    error_log('[WDSS] Bridge file missing: ' . $__wdss_bridge);
}
$__wdss_fwd = WDSS29_PATH . 'includes/wdss-email-automation/includes/bridge-forwarders.php';
if ( file_exists( $__wdss_fwd ) ) {
    require_once $__wdss_fwd;
} else {
    error_log('[WDSS] bridge-forwarders missing: ' . $__wdss_fwd);
}
require_once WDSS29_PATH . 'includes/wdss-email-automation/includes/class-wdss-emailer.php';
unset($__wdss_bridge, $__wdss_fwd);

// Force single template for wd_product
add_filter('template_include', function( $template ) {
    if ( is_singular('wd_product') ) {
        $custom = WDSS29_PATH . 'templates/single-wd_product.php';
        if ( file_exists( $custom ) ) return $custom;
    }
    return $template;
}, 50);

// If this slug is correct for your parent menu, keep it:
add_filter('wdss_admin_parent_slug', function(){ return 'wd-store-suite'; });

// Admin assets
add_action( 'admin_enqueue_scripts', function() {
    wp_enqueue_style( 'wdss29-admin', WDSS29_URL . 'assets/css/admin-styles.css', array(), WDSS29_VERSION );
    wp_enqueue_script( 'wdss29-admin', WDSS29_URL . 'assets/js/admin-scripts.js', array('jquery'), WDSS29_VERSION, true );
});

// Front assets (single registration)
add_action( 'wp_enqueue_scripts', function() {
    wp_enqueue_style( 'wdss29-front', WDSS29_URL . 'assets/css/front-styles.css', array(), WDSS29_VERSION );
    wp_enqueue_script( 'wdss29-lightbox', WDSS29_URL . 'assets/js/front-lightbox.js', array(), WDSS29_VERSION, true );
});

// Activation/Deactivation
register_activation_hook( __FILE__, function() {
    require_once WDSS29_PATH . 'includes/products.php';
    do_action('init');
    flush_rewrite_rules();
});
register_deactivation_hook( __FILE__, function() {
    flush_rewrite_rules();
});

// --- WDSS: safe mail defaults (From/Name + HTML) ---
add_filter('wp_mail_from', function($email){
    // Use a real mailbox on your domain; MUST exist and match your SMTP "from"
    return 'no-reply@warfdesigns.com';
}, 20);

add_filter('wp_mail_from_name', function($name){
    return 'Warf Designs';
}, 20);

add_filter('wp_mail_content_type', function($type){
    // Force HTML so template bodies render correctly
    return 'text/html';
}, 20);
