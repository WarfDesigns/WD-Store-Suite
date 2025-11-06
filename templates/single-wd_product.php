<?php
/** Template loaded by WD Store Suite for wd_product single pages */
if ( ! defined('ABSPATH') ) exit;

get_header();

echo '<main id="primary" class="site-main" style="max-width:1000px;margin:24px auto;padding:0 16px;">';

if ( have_posts() ) {
    while ( have_posts() ) {
        the_post();
        // Render your product detail view
        echo do_shortcode('[wd_product_single]');
    }
} else {
    echo '<p>No product found.</p>';
}

echo '</main>';

get_footer();
