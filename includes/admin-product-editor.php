<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WD Store Suite — Classic Editor + Meta Boxes for wd_product
 * - Disables block editor for wd_product
 * - Adds "Product Details" meta box (SKU, Price, Color, Size, Back)
 * - Adds "Product Gallery" meta box (media modal, attaches images to product)
 * - Ensures Featured Image box is available
 * - Admin list columns for quick view
 */

/** ---------------------------
 * Disable block editor for wd_product
 * --------------------------- */
add_filter( 'use_block_editor_for_post_type', function( $use_block, $post_type ) {
    if ( $post_type === 'wd_product' ) return false;
    return $use_block;
}, 10, 2 );

// Also hide the classic content editor area; products are managed via fields.
add_action( 'admin_head', function() {
    $screen = get_current_screen();
    if ( $screen && $screen->post_type === 'wd_product' ) {
        echo '<style>#postdivrich{display:none !important;}</style>';
    }
});

/** ---------------------------
 * Ensure Featured Image support is enabled for wd_product
 * --------------------------- */
add_action( 'init', function() {
    // Theme thumbnails generally needed
    add_theme_support('post-thumbnails');
    add_post_type_support('wd_product', 'thumbnail');
}, 20 );

/** ---------------------------
 * Meta boxes
 * --------------------------- */
add_action( 'add_meta_boxes', function() {
    add_meta_box(
        'wdss29_product_details',
        'Product Details',
        'wdss29_render_product_details_metabox',
        'wd_product',
        'normal',
        'high'
    );
    add_meta_box(
        'wdss29_product_gallery',
        'Product Gallery',
        'wdss29_render_product_gallery_metabox',
        'wd_product',
        'normal',
        'default'
    );
});

/** Product Details Metabox */
function wdss29_render_product_details_metabox( $post ) {
    wp_nonce_field( 'wdss29_save_product_details', 'wdss29_product_details_nonce' );

    $sku   = get_post_meta( $post->ID, 'wd_sku',   true );
    $price = get_post_meta( $post->ID, 'wd_price', true );
    $color = get_post_meta( $post->ID, 'wd_color', true );
    $size  = get_post_meta( $post->ID, 'wd_size',  true );
    $back  = get_post_meta( $post->ID, 'wd_back',  true );

    // Back options per your site (Zip Up / Lace Up)
    $back_opts = array( 'Zip Up', 'Lace Up' );
    ?>
    <table class="form-table">
        <tbody>
            <tr>
                <th scope="row"><label for="wd_sku">SKU</label></th>
                <td><input type="text" id="wd_sku" name="wd_sku" value="<?php echo esc_attr($sku); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th scope="row"><label for="wd_price">Price (USD)</label></th>
                <td><input type="number" id="wd_price" name="wd_price" value="<?php echo esc_attr($price); ?>" step="0.01" min="0" style="width:140px;"></td>
            </tr>
            <tr>
                <th scope="row"><label for="wd_color">Color</label></th>
                <td><input type="text" id="wd_color" name="wd_color" value="<?php echo esc_attr($color); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th scope="row"><label for="wd_size">Size</label></th>
                <td><input type="text" id="wd_size" name="wd_size" value="<?php echo esc_attr($size); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th scope="row"><label for="wd_back">Back</label></th>
                <td>
                    <select id="wd_back" name="wd_back">
                        <option value="">— Select —</option>
                        <?php foreach ( $back_opts as $opt ): ?>
                            <option value="<?php echo esc_attr($opt); ?>" <?php selected( $back, $opt ); ?>>
                                <?php echo esc_html($opt); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        </tbody>
    </table>
    <p class="description">Use the “Featured image” box for the main product image.</p>
    <?php
}

/** Product Gallery Metabox */
function wdss29_render_product_gallery_metabox( $post ) {
    wp_nonce_field( 'wdss29_save_product_gallery', 'wdss29_product_gallery_nonce' );

    // We'll store gallery as attachment IDs, but also set their post_parent to this product so your front-end works unchanged.
    $ids = get_post_meta( $post->ID, 'wd_gallery_ids', true );
    $ids = is_array($ids) ? array_filter(array_map('intval', $ids)) : array();

    // Enqueue media + our small admin JS
    wp_enqueue_media();
    add_action('admin_print_footer_scripts', 'wdss29_product_gallery_inline_js');

    echo '<div id="wdss29-gallery-wrap">';
    echo '<p><button type="button" class="button" id="wdss29-add-gallery">Add Images</button> ';
    echo '<button type="button" class="button" id="wdss29-clear-gallery">Clear</button></p>';
    echo '<ul id="wdss29-gallery-list" style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0;padding:0;">';

    if ( $ids ) {
        foreach ( $ids as $aid ) {
            $thumb = wp_get_attachment_image( $aid, 'thumbnail', false, array( 'style'=>'width:80px;height:80px;object-fit:cover;display:block;border:1px solid #ddd;border-radius:4px;' ) );
            echo '<li data-id="'. esc_attr($aid) .'" style="list-style:none;">'
               . '<div class="wdss29-g-thumb">'.$thumb.'</div>'
               . '<button type="button" class="button-link-delete wdss29-remove-item" style="display:block;text-align:center;margin-top:4px;">Remove</button>'
               . '</li>';
        }
    }

    echo '</ul>';
    echo '<input type="hidden" id="wdss29_gallery_ids" name="wdss29_gallery_ids" value="'. esc_attr( implode(',', $ids) ) .'">';
    echo '</div>';
}

/** Inline JS for the gallery box (simple, sortable-lite behavior) */
function wdss29_product_gallery_inline_js(){
    ?>
    <script>
    (function($){
        function refreshHidden(){
            var ids = [];
            $('#wdss29-gallery-list li').each(function(){ ids.push($(this).data('id')); });
            $('#wdss29_gallery_ids').val(ids.join(','));
        }
        $(document).on('click', '#wdss29-add-gallery', function(e){
            e.preventDefault();
            var frame = wp.media({
                title: 'Select Images',
                button: { text: 'Use these images' },
                multiple: true
            });
            frame.on('select', function(){
                var selection = frame.state().get('selection');
                selection.each(function(att){
                    var id = att.get('id');
                    var url = att.get('sizes') && att.get('sizes').thumbnail ? att.get('sizes').thumbnail.url : att.get('url');
                    var li = $('<li/>',{ 'data-id': id, style:'list-style:none;' });
                    var img = $('<img/>',{ src:url, style:'width:80px;height:80px;object-fit:cover;display:block;border:1px solid #ddd;border-radius:4px;' });
                    var btn = $('<button/>',{ type:'button', class:'button-link-delete wdss29-remove-item', text:'Remove', style:'display:block;text-align:center;margin-top:4px;' });
                    li.append($('<div/>',{ class:'wdss29-g-thumb' }).append(img)).append(btn);
                    $('#wdss29-gallery-list').append(li);
                });
                refreshHidden();
            });
            frame.open();
        });
        $(document).on('click', '#wdss29-clear-gallery', function(e){
            e.preventDefault();
            $('#wdss29-gallery-list').empty();
            refreshHidden();
        });
        $(document).on('click', '.wdss29-remove-item', function(e){
            e.preventDefault();
            $(this).closest('li').remove();
            refreshHidden();
        });
        // basic drag sort (no dependency): mouse reorder
        let dragged;
        $(document).on('dragstart', '#wdss29-gallery-list li', function(){
            dragged = this;
        }).on('dragover', '#wdss29-gallery-list li', function(e){
            e.preventDefault();
        }).on('drop', '#wdss29-gallery-list li', function(e){
            e.preventDefault();
            if (dragged && dragged !== this) {
                if ($(dragged).index() < $(this).index()) {
                    $(this).after(dragged);
                } else {
                    $(this).before(dragged);
                }
                refreshHidden();
            }
        }).on('mouseenter', '#wdss29-gallery-list li', function(){
            this.setAttribute('draggable','true');
        });
    })(jQuery);
    </script>
    <?php
}

/** ---------------------------
 * Save handlers
 * --------------------------- */
add_action( 'save_post_wd_product', function( $post_id, $post, $update ){

    // Bail on autosave, revisions, wrong nonce, or no permission
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision($post_id) ) return;
    if ( ! current_user_can('edit_post', $post_id) ) return;

    // DETAILS
    if ( isset($_POST['wdss29_product_details_nonce']) && wp_verify_nonce($_POST['wdss29_product_details_nonce'], 'wdss29_save_product_details') ) {
        $sku   = isset($_POST['wd_sku'])   ? sanitize_text_field( wp_unslash($_POST['wd_sku']) ) : '';
        $price = isset($_POST['wd_price']) ? floatval($_POST['wd_price']) : 0;
        $color = isset($_POST['wd_color']) ? sanitize_text_field( wp_unslash($_POST['wd_color']) ) : '';
        $size  = isset($_POST['wd_size'])  ? sanitize_text_field( wp_unslash($_POST['wd_size']) )  : '';
        $back  = isset($_POST['wd_back'])  ? sanitize_text_field( wp_unslash($_POST['wd_back']) )  : '';

        if ( $sku !== '' ) {
            update_post_meta( $post_id, 'wd_sku', $sku );
        } else {
            delete_post_meta( $post_id, 'wd_sku' );
        }
        update_post_meta( $post_id, 'wd_price', $price );
        update_post_meta( $post_id, 'wd_color', $color );
        update_post_meta( $post_id, 'wd_size',  $size );
        update_post_meta( $post_id, 'wd_back',  $back );
    }

    // GALLERY
    if ( isset($_POST['wdss29_product_gallery_nonce']) && wp_verify_nonce($_POST['wdss29_product_gallery_nonce'], 'wdss29_save_product_gallery') ) {
        $ids_str = isset($_POST['wdss29_gallery_ids']) ? trim( wp_unslash($_POST['wdss29_gallery_ids']) ) : '';
        $ids = array_filter( array_map( 'intval', $ids_str !== '' ? explode(',', $ids_str) : array() ) );

        update_post_meta( $post_id, 'wd_gallery_ids', $ids );

        // Attach selected images to this product (so front-end get_children() finds them)
        if ( $ids ) {
            foreach ( $ids as $aid ) {
                $att = get_post( $aid );
                if ( $att && intval($att->post_parent) !== intval($post_id) ) {
                    wp_update_post( array(
                        'ID'          => $aid,
                        'post_parent' => $post_id,
                    ) );
                }
            }
        }
    }

}, 10, 3 );

/** ---------------------------
 * Admin list columns polish
 * --------------------------- */
add_filter( 'manage_wd_product_posts_columns', function( $cols ){
    // Keep title & date; add SKU/Price/Color/Size/Back
    $new = array();
    foreach ( $cols as $k => $v ) {
        if ( $k === 'cb' ) $new[$k] = $v;
        if ( $k === 'title' ) {
            $new[$k] = $v;
            $new['wd_sku']   = 'SKU';
            $new['wd_price'] = 'Price';
            $new['wd_color'] = 'Color';
            $new['wd_size']  = 'Size';
            $new['wd_back']  = 'Back';
        } elseif ( $k === 'date' ) {
            $new[$k] = $v;
        }
    }
    return $new;
});

add_action( 'manage_wd_product_posts_custom_column', function( $col, $post_id ){
    switch ( $col ) {
        case 'wd_sku':
            echo esc_html( get_post_meta($post_id, 'wd_sku', true) );
            break;
        case 'wd_price':
            $p = get_post_meta($post_id, 'wd_price', true);
            echo is_numeric($p) ? '$' . number_format((float)$p, 2) : '';
            break;
        case 'wd_color':
            echo esc_html( get_post_meta($post_id, 'wd_color', true) );
            break;
        case 'wd_size':
            echo esc_html( get_post_meta($post_id, 'wd_size', true) );
            break;
        case 'wd_back':
            echo esc_html( get_post_meta($post_id, 'wd_back', true) );
            break;
    }
}, 10, 2 );
