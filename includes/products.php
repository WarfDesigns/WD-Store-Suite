<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'init', function() {
    register_post_type( 'wd_product', array(
        'labels' => array(
            'name'          => 'Products',
            'singular_name' => 'Product',
            'add_new_item'  => 'Add New Product',
            'edit_item'     => 'Edit Product',
        ),
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'show_in_rest'       => true,              // Gutenberg/REST
        'query_var'          => true,
        'has_archive'        => true,
        'rewrite'            => array(
            'slug'       => 'products',            // your single URL = /products/sample-product/
            'with_front' => false,
        ),
        'supports'           => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
        'menu_icon'          => 'dashicons-cart',
    ) );
});
