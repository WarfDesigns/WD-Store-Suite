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
define( 'WDSS29_URL', plugin_dir_url( __FILE__ ) );
define( 'WDSS29_VERSION', '2.9' );

// Includes
require_once WDSS29_PATH . 'includes/class-wdss29-core.php';
require_once WDSS29_PATH . 'includes/cart.php';
require_once WDSS29_PATH . 'includes/products.php';
require_once WDSS29_PATH . 'includes/orders.php';
require_once WDSS29_PATH . 'includes/email-templates.php';
require_once WDSS29_PATH . 'includes/admin-settings.php';
require_once WDSS29_PATH . 'includes/shortcodes.php';
require_once WDSS29_PATH . 'includes/admin-import-export.php';
require_once WDSS29_PATH . 'includes/admin-product-editor.php';
require_once WDSS29_PATH . 'includes/wdss-email-automation/includes/class-wdss-emailer.php';
require_once WDSS29_PATH . 'includes/payments/class-wdss-stripe.php';
require_once WDSS29_PATH . 'includes/admin-orders.php';
// Safe include in main plugin bootstrap
$__wdss_bridge = __DIR__ . '/includes/wdss-email-automation/includes/class-wdss-order-events-bridge.php';
if ( file_exists( $__wdss_bridge ) ) {
    require_once $__wdss_bridge;
} else {
    error_log('[WDSS] Bridge file missing: ' . $__wdss_bridge);
}
unset($__wdss_bridge);





// Force a reliable single template for wd_product that renders our shortcode.
add_filter('template_include', function( $template ) {
    if ( is_singular('wd_product') ) {
        $custom = WDSS29_PATH . 'templates/single-wd_product.php';
        if ( file_exists( $custom ) ) {
            return $custom; // Use our template no matter what the theme does
        }
    }
    return $template;
}, 50);


add_filter('wdss_admin_parent_slug', function(){ return 'wd-store-suite-v4_2'; });


// Enqueue assets
add_action( 'admin_enqueue_scripts', function() {
    wp_enqueue_style( 'wdss29-admin', WDSS29_URL . 'assets/css/admin-styles.css', array(), WDSS29_VERSION );
    wp_enqueue_script( 'wdss29-admin', WDSS29_URL . 'assets/js/admin-scripts.js', array('jquery'), WDSS29_VERSION, true );
});

// Front styles
add_action( 'wp_enqueue_scripts', function() {
    wp_enqueue_style( 'wdss29-front', WDSS29_URL . 'assets/css/front-styles.css', array(), WDSS29_VERSION );
});

// Front styles + scripts
add_action( 'wp_enqueue_scripts', function() {
    wp_enqueue_style( 'wdss29-front', WDSS29_URL . 'assets/css/front-styles.css', array(), WDSS29_VERSION );
    wp_enqueue_script( 'wdss29-lightbox', WDSS29_URL . 'assets/js/front-lightbox.js', array(), WDSS29_VERSION, true );
});


// Activation: register CPT then flush
register_activation_hook( __FILE__, function() {
    // make sure CPT exists before flushing
    require_once WDSS29_PATH . 'includes/products.php';
    do_action('init');
    flush_rewrite_rules();
});

// Deactivation: flush
register_deactivation_hook( __FILE__, function() {
    flush_rewrite_rules();
});
