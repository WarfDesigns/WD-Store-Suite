<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function wdss29_init_cart() {
    if ( ! session_id() ) session_start();
    if ( ! isset( $_SESSION['wdss29_cart'] ) ) $_SESSION['wdss29_cart'] = array();
}
add_action( 'init', 'wdss29_init_cart' );

function wdss29_add_to_cart( $product_id, $qty = 1 ) {
    wdss29_init_cart();
    if ( ! isset( $_SESSION['wdss29_cart'][$product_id] ) ) {
        $_SESSION['wdss29_cart'][$product_id] = $qty;
    } else {
        $_SESSION['wdss29_cart'][$product_id] += $qty;
    }
}

function wdss29_clear_cart() {
    if ( isset( $_SESSION['wdss29_cart'] ) ) unset( $_SESSION['wdss29_cart'] );
}

