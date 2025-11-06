<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WD Store Suite — Import / Export (CSV)
 * Columns (case-insensitive):
 * id, sku, title, content, price, color, size, back, image, gallery
 */

add_action('admin_menu', function () {
    add_submenu_page(
        'wdss29-settings',
        'Import / Export',
        'Import / Export',
        'manage_options',
        'wdss29-import-export',
        'wdss29_render_import_export_page'
    );
});

/** ===========================
 * Utilities
 * =========================== */

function wdss29_csv_find_col( array $headers, array $aliases ) {
    $lookup = array();
    foreach ( $headers as $i => $h ) {
        $lookup[ strtolower( trim( $h ) ) ] = $i;
    }
    foreach ( $aliases as $alias ) {
        $key = strtolower( trim( $alias ) );
        if ( isset( $lookup[ $key ] ) ) return $lookup[ $key ];
    }
    return -1;
}

function wdss29_attach_image_from_url( $url, $post_id ) {
    if ( empty( $url ) ) return 0;
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $tmp = media_sideload_image( $url, $post_id, null, 'id' );
    if ( is_wp_error( $tmp ) ) return 0;
    return intval( $tmp );
}

/** ===========================
 * EXPORT via admin-post
 * =========================== */

add_action('admin_post_wdss29_export_products', 'wdss29_export_products_handler');
function wdss29_export_products_handler() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Insufficient permissions.');
    }
    if ( ! isset($_POST['_wpnonce']) || ! wp_verify_nonce($_POST['_wpnonce'], 'wdss29_export_products') ) {
        wp_die('Invalid request.');
    }

    // Clear all buffers first
    if ( function_exists('ob_get_level') ) {
        while ( ob_get_level() > 0 ) { @ob_end_clean(); }
    }
    // Kill compression/buffering
    if ( function_exists('ini_set') ) {
        @ini_set('zlib.output_compression', 'Off');
        @ini_set('output_buffering', 'Off');
        @ini_set('implicit_flush', 'On');
    }
    if ( function_exists('apache_setenv') ) { @apache_setenv('no-gzip', '1'); }
    @set_time_limit(300);

    // Headers
    nocache_headers();
    $filename = 'wd-products-' . date('Ymd-His') . '.csv';
    header('Content-Description: File Transfer');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Accel-Buffering: no');

    $out = fopen('php://output', 'w');
    if ( ! $out ) wp_die('Failed to open output stream.');

    // UTF-8 BOM for Excel
    fwrite($out, "\xEF\xBB\xBF");

    // CSV header row (same columns/UI as before)
    $headers = array('id','sku','title','content','price','color','size','back','image','gallery');
    fputcsv($out, $headers);

    // Query all wd_product posts
    $q = new WP_Query(array(
        'post_type'      => 'wd_product',
        'posts_per_page' => -1,
        'post_status'    => array('publish','draft','pending','private'),
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ));

    if ( $q->have_posts() ) {
        foreach ( $q->posts as $pid ) {
            $sku     = get_post_meta( $pid, 'wd_sku', true );
            $price   = get_post_meta( $pid, 'wd_price', true );
            $color   = get_post_meta( $pid, 'wd_color', true );
            $size    = get_post_meta( $pid, 'wd_size', true );
            $back    = get_post_meta( $pid, 'wd_back', true );

            $title   = get_the_title($pid);
            $content = get_post_field('post_content', $pid);

            $feat_id  = get_post_thumbnail_id($pid);
            $feat_url = $feat_id ? wp_get_attachment_image_url($feat_id, 'full') : '';

            // Gallery URLs (exclude featured)
            $gallery_urls = array();
            $attachments = get_children(array(
                'post_parent'    => $pid,
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'orderby'        => 'menu_order ID',
                'order'          => 'ASC',
            ));
            if ( ! empty($attachments) ) {
                foreach ( $attachments as $att ) {
                    if ( $feat_id && $att->ID == $feat_id ) continue;
                    $u = wp_get_attachment_image_url($att->ID, 'full');
                    if ( $u ) $gallery_urls[] = $u;
                }
            }

            // Normalize newlines for Windows CSV readers
            $content_norm = is_string($content) ? str_replace(array("\r\n","\r"), "\n", $content) : $content;

            fputcsv($out, array(
                $pid,
                $sku,
                $title,
                $content_norm,
                is_numeric($price) ? $price : '',
                $color,
                $size,
                $back,
                $feat_url,
                implode('|', $gallery_urls),
            ));
        }
    }

    fclose($out);
    exit; // IMPORTANT
}

/** ===========================
 * IMPORT (same behavior/UI)
 * =========================== */

add_action('admin_post_wdss29_import_products', 'wdss29_import_products_handler');
function wdss29_import_products_handler() {
    if ( ! current_user_can('manage_options') ) wp_die('Insufficient permissions.');
    if ( ! isset($_POST['_wpnonce']) || ! wp_verify_nonce($_POST['_wpnonce'], 'wdss29_import_products') ) wp_die('Invalid request.');
    if ( empty($_FILES['wdss29_csv']['tmp_name']) || ! is_uploaded_file($_FILES['wdss29_csv']['tmp_name']) ) {
        wp_redirect( add_query_arg('wdmsg','no-file', admin_url('admin.php?page=wdss29-import-export')) );
        exit;
    }

    @set_time_limit(0);
    @ini_set('auto_detect_line_endings', '1');

    $fh = fopen($_FILES['wdss29_csv']['tmp_name'], 'r');
    if ( ! $fh ) {
        wp_redirect( add_query_arg('wdmsg','open-fail', admin_url('admin.php?page=wdss29-import-export')) );
        exit;
    }

    $header = fgetcsv($fh);
    if ( ! $header ) {
        fclose($fh);
        wp_redirect( add_query_arg('wdmsg','bad-header', admin_url('admin.php?page=wdss29-import-export')) );
        exit;
    }

    $lower = array_map(function($h){ return strtolower(trim($h)); }, $header);
    $idx = array(
        'id'      => wdss29_csv_find_col($lower, array('id')),
        'sku'     => wdss29_csv_find_col($lower, array('sku')),
        'title'   => wdss29_csv_find_col($lower, array('title','name')),
        'content' => wdss29_csv_find_col($lower, array('content','description')),
        'price'   => wdss29_csv_find_col($lower, array('price','cost','amount')),
        'color'   => wdss29_csv_find_col($lower, array('color')),
        'size'    => wdss29_csv_find_col($lower, array('size')),
        'back'    => wdss29_csv_find_col($lower, array('back','back_style','backstyle')),
        'image'   => wdss29_csv_find_col($lower, array('image','featured','featured_image')),
        'gallery' => wdss29_csv_find_col($lower, array('gallery','images')),
    );

    $updated = 0; $created = 0; $errors = 0;

    while ( ($cols = fgetcsv($fh)) !== false ) {
        $get = function($key) use ($idx, $cols) {
            if ( ! isset($idx[$key]) || $idx[$key] === -1 ) return '';
            $i = $idx[$key];
            return isset($cols[$i]) ? trim($cols[$i]) : '';
        };

        $id      = intval( $get('id') );
        $sku     = sanitize_text_field( $get('sku') );
        $title   = $get('title');
        $content = $get('content');
        $price   = is_numeric($get('price')) ? floatval($get('price')) : 0;

        $color   = sanitize_text_field( $get('color') );
        $size    = sanitize_text_field( $get('size') );
        $back    = sanitize_text_field( $get('back') );

        $feat    = esc_url_raw( $get('image') );
        $gallery = $get('gallery');

        // Resolve product (ID > SKU > new)
        $post_id = 0;
        if ( $id > 0 && get_post_type($id) === 'wd_product' ) {
            $post_id = $id;
        } elseif ( $sku ) {
            $found = get_posts(array(
                'post_type'  => 'wd_product',
                'meta_key'   => 'wd_sku',
                'meta_value' => $sku,
                'fields'     => 'ids',
                'numberposts'=> 1,
            ));
            if ( $found ) $post_id = $found[0];
        }

        $postarr = array('post_type'=>'wd_product','post_status'=>'publish');
        if ( $title !== '' )   $postarr['post_title']   = $title;
        if ( $content !== '' ) $postarr['post_content'] = $content;

        if ( $post_id ) {
            $postarr['ID'] = $post_id;
            $new_id = wp_update_post($postarr, true);
            if ( is_wp_error($new_id) ) { $errors++; continue; }
            $post_id = $new_id;
            $updated++;
        } else {
            $new_id = wp_insert_post($postarr, true);
            if ( is_wp_error($new_id) ) { $errors++; continue; }
            $post_id = $new_id;
            $created++;
        }

        update_post_meta($post_id, 'wd_price', $price);
        if ( $sku !== '' ) update_post_meta($post_id, 'wd_sku', $sku);
        update_post_meta($post_id, 'wd_color', $color);
        update_post_meta($post_id, 'wd_size',  $size);
        update_post_meta($post_id, 'wd_back',  $back);

        if ( $feat ) {
            $aid = wdss29_attach_image_from_url($feat, $post_id);
            if ( $aid ) set_post_thumbnail($post_id, $aid);
        }
        if ( ! empty($gallery) ) {
            $urls = array_filter( array_map('trim', explode('|', $gallery)) );
            foreach ($urls as $gu) { wdss29_attach_image_from_url($gu, $post_id); }
        }
        if ( function_exists('clean_post_cache') ) clean_post_cache($post_id);
    }

    fclose($fh);
    $url = add_query_arg(array(
        'page'  => 'wdss29-import-export',
        'wdmsg' => 'import-ok',
        'c'     => $created,
        'u'     => $updated,
        'e'     => $errors,
    ), admin_url('admin.php'));
    wp_redirect($url);
    exit;
}

/** ===========================
 * Page Renderer (same look)
 * =========================== */

function wdss29_render_import_export_page() {
    if ( ! current_user_can('manage_options') ) return;

    // Optional notices
    if ( isset($_GET['wdmsg']) && $_GET['wdmsg'] === 'import-ok' ) {
        $c = intval($_GET['c'] ?? 0); $u = intval($_GET['u'] ?? 0); $e = intval($_GET['e'] ?? 0);
        echo '<div class="updated"><p>Import complete. Created: <strong>'. $c .'</strong>, Updated: <strong>'. $u .'</strong>, Errors: <strong>'. $e .'</strong>.</p></div>';
    } elseif ( isset($_GET['wdmsg']) ) {
        echo '<div class="error"><p>Import failed: '. esc_html($_GET['wdmsg']) .'</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>WD Store Suite — Import / Export</h1>

        <h2 class="title">Export Products (CSV)</h2>
        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
            <?php wp_nonce_field('wdss29_export_products'); ?>
            <input type="hidden" name="action" value="wdss29_export_products">
            <p class="description">Exports all <code>wd_product</code> items including <strong>Color</strong>, <strong>Size</strong>, and <strong>Back</strong>.</p>
            <p><button type="submit" class="button button-primary">Download CSV</button></p>
        </form>

        <hr>

        <h2 class="title">Import Products (CSV)</h2>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
            <?php wp_nonce_field('wdss29_import_products'); ?>
            <input type="hidden" name="action" value="wdss29_import_products">
            <p class="description">CSV header (order is flexible, names are case-insensitive):</p>
            <pre style="background:#f7f7f7;padding:8px;border:1px solid #ddd;overflow:auto;">id,sku,title,content,price,color,size,back,image,gallery</pre>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="wdss29_csv">CSV File</label></th>
                    <td><input type="file" id="wdss29_csv" name="wdss29_csv" accept=".csv" required></td>
                </tr>
            </table>
            <p><button type="submit" class="button button-primary">Import</button></p>
            <p class="description">
                Notes: <br>
                - <strong>image</strong> is a single URL for the featured image.<br>
                - <strong>gallery</strong> is a pipe-separated list of image URLs (e.g. <code>https://…/a.jpg|https://…/b.jpg</code>).<br>
                - If <code>id</code> matches a product, that product is updated. Otherwise we try to match by <code>sku</code>, else a new product is created.
            </p>
        </form>
    </div>
    <?php
}
