<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ===== Shared Settings Helpers =====
 */
if ( ! function_exists('wdss29_default_settings') ) {
    function wdss29_default_settings() {
        return array(
            'stripe_sk'       => '',
            'stripe_pk'       => '',
            'sales_tax_rate'  => 6,
            'card_fee_rate'   => 3,
            'addon_prices'    => array(
                'back_zip_up'   => 0.00,
                'back_lace_up'  => 0.00,
                'length3_yes'   => 0.00,
                'length3_no'    => 0.00,
                'train12_yes'   => 0.00,
                'train12_no'    => 0.00,
            ),
        );
    }
}
if ( ! function_exists('wdss29_get_settings') ) {
    function wdss29_get_settings() {
        $saved    = get_option('wdss29_settings', array());
        $defaults = wdss29_default_settings();
        $merged   = wp_parse_args( $saved, $defaults );

        // ensure addon_prices has all keys
        if ( empty($merged['addon_prices']) || ! is_array($merged['addon_prices']) ) {
            $merged['addon_prices'] = $defaults['addon_prices'];
        } else {
            $merged['addon_prices'] = array_merge($defaults['addon_prices'], $merged['addon_prices']);
        }
        return $merged;
    }
}

/** =====================================================================
 *  Utilities / Logger
 * ===================================================================== */
if ( ! function_exists('wdss29_log') ) {
    function wdss29_log( $msg, $ctx = array() ) {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . (is_string($msg) ? $msg : wp_json_encode($msg));
        if ( ! empty($ctx) ) $line .= ' | ' . wp_json_encode($ctx);
        if ( defined('WP_DEBUG') && WP_DEBUG ) error_log('[WDSS] ' . $line);
        $key = 'wdss29_log';
        $buf = get_transient($key);
        if ( ! is_array($buf) ) $buf = array();
        $buf[] = $line;
        if ( count($buf) > 200 ) $buf = array_slice($buf, -200);
        set_transient($key, $buf, 2 * HOUR_IN_SECONDS);
    }
}

/** =====================================================================
 *  Orders + Items Tables: schema + helpers
 * ===================================================================== */

if ( ! function_exists('wdss29_get_orders_table_columns') ) {
    function wdss29_get_orders_table_columns() {
        global $wpdb;
        $table = $wpdb->prefix . 'wd_orders';
        $cols = array();
        $desc = $wpdb->get_results( "DESCRIBE {$table}", ARRAY_A );
        if ( is_array($desc) ) {
            foreach ( $desc as $col ) {
                if ( isset($col['Field']) ) $cols[ $col['Field'] ] = true;
            }
        }
        return $cols;
    }
}

/** Items table columns */
if ( ! function_exists('wdss29_get_order_items_table_columns') ) {
    function wdss29_get_order_items_table_columns() {
        global $wpdb;
        $table = $wpdb->prefix . 'wd_order_items';
        $cols = array();
        $desc = $wpdb->get_results( "DESCRIBE {$table}", ARRAY_A );
        if ( is_array($desc) ) {
            foreach ( $desc as $col ) {
                if ( isset($col['Field']) ) $cols[ $col['Field'] ] = true;
            }
        }
        return $cols;
    }
}

/** Create/upgrade wd_orders table (adds tracking_id and meta)
 * NOTE: This is for wp_wd_orders table used by checkout flow
 * Different from wp_wdss29_orders table in orders.php
 */
if ( ! function_exists('wdss29_maybe_install_or_upgrade_wd_orders_table') ) {
    function wdss29_maybe_install_or_upgrade_wd_orders_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'wd_orders';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            customer_name VARCHAR(200) NOT NULL DEFAULT '',
            customer_email VARCHAR(190) NOT NULL DEFAULT '',
            total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(50) NOT NULL DEFAULT 'pending',
            payment_id VARCHAR(191) NOT NULL DEFAULT '',
            tracking_id VARCHAR(191) NOT NULL DEFAULT '',
            meta LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY idx_customer_id (customer_id),
            KEY idx_email (customer_email),
            KEY idx_status (status),
            KEY idx_payment (payment_id),
            KEY idx_created (created_at)
        ) {$charset_collate};";

        dbDelta($sql);
        
        // dbDelta() is unreliable for adding columns to existing tables
        // Manually check and add missing columns
        $cols = wdss29_get_orders_table_columns();
        
        // Check for tracking_id column
        if ( ! isset($cols['tracking_id']) ) {
            $result = $wpdb->query("ALTER TABLE {$table} ADD COLUMN tracking_id VARCHAR(191) NOT NULL DEFAULT ''");
            if ( $result !== false ) {
                $wpdb->query("CREATE INDEX idx_tracking_id ON {$table} (tracking_id)");
                wdss29_log('orders_table_upgrade', array('added' => 'tracking_id'));
            }
        }
        
        // Check for meta column (CRITICAL for order lookup)
        if ( ! isset($cols['meta']) ) {
            $result = $wpdb->query("ALTER TABLE {$table} ADD COLUMN meta LONGTEXT NULL");
            if ( $result !== false ) {
                wdss29_log('orders_table_upgrade', array('added' => 'meta'));
            } else {
                wdss29_log('orders_table_upgrade', array('error' => 'failed_to_add_meta', 'mysql_error' => $wpdb->last_error));
            }
        }
    }
}

/** Create/upgrade order items table */
if ( ! function_exists('wdss29_maybe_install_or_upgrade_order_items_table') ) {
    function wdss29_maybe_install_or_upgrade_order_items_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'wd_order_items';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            quantity INT UNSIGNED NOT NULL DEFAULT 1,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            addon_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            addons LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_order (order_id),
            KEY idx_product (product_id)
        ) {$charset_collate};";

        dbDelta($sql);
    }
}

/** Insert (or re-save) items for an order id */
if ( ! function_exists('wdss29_save_order_items') ) {
    function wdss29_save_order_items( $order_id, $items ) {
        global $wpdb;
        if ( ! $order_id || empty($items) || ! is_array($items) ) return;

        wdss29_maybe_install_or_upgrade_order_items_table();
        $items_table = $wpdb->prefix . 'wd_order_items';

        // Clear existing for idempotency
        $wpdb->delete( $items_table, array('order_id' => (int) $order_id), array('%d') );

        foreach ( $items as $row ) {
            $pid   = intval($row['product_id'] ?? 0);
            $qty   = max(1, intval($row['quantity'] ?? 1));
            $u     = floatval($row['unit_price'] ?? 0);
            $ap    = floatval($row['addon_price'] ?? 0);
            $lt    = floatval($row['line_total'] ?? (($u + $ap) * $qty));
            $adds  = $row['addons'] ?? array();
            if (!is_array($adds) && !is_string($adds)) $adds = array();
            $adds_json = is_string($adds) ? $adds : wp_json_encode($adds);

            if ( $pid > 0 ) {
                $wpdb->insert( $items_table, array(
                    'order_id'   => (int) $order_id,
                    'product_id' => $pid,
                    'quantity'   => $qty,
                    'unit_price' => $u,
                    'addon_price'=> $ap,
                    'line_total' => $lt,
                    'addons'     => $adds_json,
                ), array('%d','%d','%d','%f','%f','%f','%s') );
            }
        }
    }
}

/** Safe order insert */
if ( ! function_exists('wdss29_save_order') ) {
    /**
     * $args keys: customer_id, customer_name, customer_email, total, status, payment_id, tracking_id, meta (array|string), created_at
     * Returns insert_id on success or 0 on failure.
     */
    function wdss29_save_order( $args ) {
        global $wpdb;

        wdss29_maybe_install_or_upgrade_wd_orders_table();

        $cols       = wdss29_get_orders_table_columns();
        $table      = $wpdb->prefix . 'wd_orders';

        $customer_id    = isset($args['customer_id'])    ? (int) $args['customer_id'] : 0;
        $customer_name  = isset($args['customer_name'])  ? (string) $args['customer_name'] : '';
        $customer_email = isset($args['customer_email']) ? (string) $args['customer_email'] : '';
        $total          = isset($args['total'])          ? (float)  $args['total'] : 0.0;
        $status         = isset($args['status'])         ? (string) $args['status'] : 'pending';
        $payment_id     = isset($args['payment_id'])     ? (string) $args['payment_id'] : '';
        $tracking_id    = isset($args['tracking_id'])    ? (string) $args['tracking_id'] : '';
        $meta           = isset($args['meta'])           ? $args['meta'] : null;
        $created_at     = isset($args['created_at'])     ? (string) $args['created_at'] : current_time('mysql', 1);

        if ( is_array($meta) ) $meta = wp_json_encode($meta);

        $data  = array(
            'total'      => $total,
            'status'     => $status,
            'payment_id' => $payment_id,
            'created_at' => $created_at,
        );
        $types = array('%f','%s','%s','%s');

        if ( isset($cols['customer_id']) )    { $data['customer_id']    = $customer_id;    $types[] = '%d'; }
        if ( isset($cols['customer_name']) )  { $data['customer_name']  = $customer_name;  $types[] = '%s'; }
        if ( isset($cols['customer_email']) ) { $data['customer_email'] = $customer_email; $types[] = '%s'; }
        if ( isset($cols['tracking_id']) )    { $data['tracking_id']    = $tracking_id;    $types[] = '%s'; }
        if ( isset($cols['meta']) && $meta !== null )   { $data['meta'] = $meta; $types[] = '%s'; }

        $ok = $wpdb->insert( $table, $data, $types );
        if ( $ok === false ) {
            wdss29_log('save_order:insert_failed', array('err' => $wpdb->last_error, 'data' => $data));
            return 0;
        }

        return (int) $wpdb->insert_id;
    }
}

/** =====================================================================
 *  Add-on choices & pricing
 * ===================================================================== */
function wdss29_addon_choices() {
    return array(
        'back'     => array('Zip Up','Lace Up'),
        'length3'  => array('Yes','No'),    // Need an Additional 3" Front Length?
        'train12'  => array('Yes','No'),    // Additional 12" Train Length?
    );
}
function wdss29_sanitize_addons_from_post( $src ) {
    $defs = wdss29_addon_choices();
    $out  = array();
    $back = isset($src['addon_back']) ? trim( wp_unslash($src['addon_back']) ) : '';
    $out['back'] = in_array($back, $defs['back'], true) ? $back : '';
    $len3 = isset($src['addon_length3']) ? trim( wp_unslash($src['addon_length3']) ) : '';
    $out['length3'] = in_array($len3, $defs['length3'], true) ? $len3 : '';
    $tr12 = isset($src['addon_train12']) ? trim( wp_unslash($src['addon_train12']) ) : '';
    $out['train12'] = in_array($tr12, $defs['train12'], true) ? $tr12 : '';
    return $out;
}
function wdss29_format_addons_label( $opts ) {
    $parts = array();
    if ( ! empty($opts['back']) )    $parts[] = 'Back: ' . $opts['back'];
    if ( ! empty($opts['length3']) ) $parts[] = '3" Front: ' . $opts['length3'];
    if ( ! empty($opts['train12']) ) $parts[] = '12" Train: ' . $opts['train12'];
    return $parts ? ' (' . implode('; ', $parts) . ')' : '';
}

function wdss29_get_addon_prices() {
    $s = wdss29_get_settings();
    return isset($s['addon_prices']) && is_array($s['addon_prices']) ? $s['addon_prices'] : wdss29_default_settings()['addon_prices'];
}
function wdss29_calc_addon_unit_price( $opts ) {
    $ap    = wdss29_get_addon_prices();
    $total = 0.0;
    if ( ! empty($opts['back']) ) {
        if ( $opts['back'] === 'Zip Up' ) $total += (float)($ap['back_zip_up'] ?? 0);
        if ( $opts['back'] === 'Lace Up') $total += (float)($ap['back_lace_up'] ?? 0);
    }
    if ( ! empty($opts['length3']) ) {
        if ( $opts['length3'] === 'Yes' ) $total += (float)($ap['length3_yes'] ?? 0);
        if ( $opts['length3'] === 'No'  ) $total += (float)($ap['length3_no']  ?? 0);
    }
    if ( ! empty($opts['train12']) ) {
        if ( $opts['train12'] === 'Yes' ) $total += (float)($ap['train12_yes'] ?? 0);
        if ( $opts['train12'] === 'No'  ) $total += (float)($ap['train12_no']  ?? 0);
    }
    return round($total, 2);
}

/** =====================================================================
 *  Cart helpers
 * ===================================================================== */
if ( ! function_exists('wdss29_init_cart') ) {
    function wdss29_init_cart() {
        if ( ! session_id() ) @session_start();
        if ( empty($_SESSION['wdss29_cart']) ) $_SESSION['wdss29_cart'] = array();
    }
}
function wdss29_cart_is_new_format($cart) {
    if ( ! is_array($cart) ) return false;
    if ( empty($cart) ) return true;
    $first = reset($cart);
    return is_array($first) && isset($first['pid']) && isset($first['qty']);
}
function wdss29_convert_cart_old_to_new($old) {
    $new = array();
    if ( is_array($old) ) {
        foreach ($old as $pid => $qty) {
            $pid = intval($pid); $qty = intval($qty);
            if ( $pid && $qty > 0 ) {
                $new[] = array('pid'=>$pid, 'qty'=>$qty, 'opts'=>array());
            }
        }
    }
    return $new;
}
function wdss29_get_cart() {
    wdss29_init_cart();
    $cart = isset($_SESSION['wdss29_cart']) ? $_SESSION['wdss29_cart'] : array();
    if ( ! wdss29_cart_is_new_format($cart) ) {
        $cart = wdss29_convert_cart_old_to_new($cart);
        $_SESSION['wdss29_cart'] = $cart;
    }
    return $cart;
}
function wdss29_set_cart($cart) {
    $_SESSION['wdss29_cart'] = is_array($cart) ? array_values($cart) : array();
}
function wdss29_empty_cart() {
    unset($_SESSION['wdss29_cart']);
}

function wdss29_get_product_price( $product_id ) {
    $p = get_post_meta($product_id, 'wd_price', true);
    if ($p === '' || $p === false) $p = 0;
    if ( is_string($p) ) {
        $p = preg_replace('/[^\d\.\-]/', '', str_replace(',', '.', $p));
    }
    return floatval($p);
}
function wdss29_get_product_title( $product_id ) {
    $post = get_post($product_id);
    return $post ? $post->post_title : __('Unknown Product','wdss29');
}

/** Normalize cart to [ ['pid'=>, 'qty'=>, 'opts'=>[]], ... ] */
if ( ! function_exists('wdss29_normalize_cart_items') ) {
    function wdss29_normalize_cart_items( $raw ) {
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $raw = $decoded;
            } else {
                $tmp = @unserialize( $raw );
                if ( is_array( $tmp ) ) $raw = $tmp;
            }
        }
        $out = array();

        // assoc map product_id => qty
        if ( is_array($raw) && ! empty($raw) && array_values($raw) !== $raw ) {
            foreach ( $raw as $k => $v ) {
                $pid = intval($k);
                $qty = intval($v);
                if ( $pid > 0 && $qty > 0 ) {
                    $out[] = array( 'pid' => $pid, 'qty' => $qty, 'opts' => array() );
                }
            }
            if ( ! empty($out) ) return $out;
        }

        // list
        if ( is_array($raw) ) {
            foreach ( $raw as $line ) {
                if ( is_numeric($line) || (is_string($line) && ctype_digit($line)) ) {
                    $pid = intval($line);
                    if ( $pid > 0 ) $out[] = array( 'pid' => $pid, 'qty' => 1, 'opts' => array() );
                    continue;
                }
                if ( is_string($line) ) {
                    $d = json_decode($line, true);
                    if ( is_array($d) ) $line = $d; else continue;
                }
                if ( is_array($line) ) {
                    $pid  = isset($line['pid']) ? $line['pid'] : ( $line['product_id'] ?? ( $line['id'] ?? 0 ) );
                    $qty  = isset($line['qty']) ? $line['qty'] : ( $line['quantity'] ?? 1 );
                    $opts = isset($line['opts']) ? $line['opts'] : ( $line['options'] ?? array() );

                    $pid  = intval($pid);
                    $qty  = max(1, intval($qty));
                    $opts = is_array($opts) ? $opts : array();

                    if ( $pid > 0 ) {
                        $out[] = array( 'pid' => $pid, 'qty' => $qty, 'opts' => $opts );
                    }
                }
            }
        }
        return $out;
    }
}

/** Totals calculation with addons */
function wdss29_calc_totals($cart) {
    $items    = wdss29_normalize_cart_items( $cart );
    $subtotal = 0.0;

    foreach ($items as $line) {
        $pid  = intval($line['pid']);
        $qty  = max(1, intval($line['qty']));
        $opts = isset($line['opts']) ? $line['opts'] : array();

        $base  = wdss29_get_product_price($pid);
        $addon = wdss29_calc_addon_unit_price($opts);
        $subtotal += ($base + $addon) * $qty;
    }

    $s          = wdss29_get_settings();
    $sales_rate = floatval($s['sales_tax_rate']);
    $fee_rate   = floatval($s['card_fee_rate']);

    $sales_tax = round($subtotal * ($sales_rate / 100), 2);
    $card_fee  = round( ($subtotal + $sales_tax) * ($fee_rate / 100), 2 );
    $grand     = round( $subtotal + $sales_tax + $card_fee, 2 );

    return array(
        'subtotal'   => $subtotal,
        'sales_rate' => $sales_rate,
        'card_rate'  => $fee_rate,
        'sales_tax'  => $sales_tax,
        'card_fee'   => $card_fee,
        'grand'      => $grand,
    );
}

/** =====================================================================
 *  Gallery & Attributes Renderers
 * ===================================================================== */
function wdss29_get_gallery_attachment_ids( $pid ) {
    $ids = array();
    $attachments = get_children( array(
        'post_parent'    => $pid,
        'post_status'    => 'inherit',
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'orderby'        => 'menu_order ID',
        'order'          => 'ASC',
        'fields'         => 'ids',
    ) );
    if ( ! empty( $attachments ) ) {
        $featured_id = get_post_thumbnail_id( $pid );
        foreach ( $attachments as $aid ) {
            if ( $aid && $aid != $featured_id ) $ids[] = $aid;
        }
    }
    return $ids;
}

function wdss29_render_product_gallery( $pid ) {
    $ids = wdss29_get_gallery_attachment_ids( $pid );
    if ( empty( $ids ) ) return '';
    $group = 'wdss29-' . $pid;
    $html = '<div class="wdss29-single-gallery">';
    foreach ( $ids as $aid ) {
        $thumb = wp_get_attachment_image( $aid, 'thumbnail', false, array( 'class'=>'wdss29-single-gallery-thumb' ) );
        $full  = wp_get_attachment_url( $aid );
        if ( $thumb && $full ) {
            $html .= '<a href="'. esc_url($full) .'" class="wdss29-lightbox" data-group="'. esc_attr($group) .'">'
                . '<div class="wdss29-single-gallery-item">' . $thumb . '</div>'
                . '</a>';
        }
    }
    $html .= '</div>';
    return $html;
}

function wdss29_render_product_attributes( $pid ) {
    $color = get_post_meta($pid, 'wd_color', true);
    $size  = get_post_meta($pid, 'wd_size',  true);
    $back  = get_post_meta($pid, 'wd_back',  true);

    $parts = array();
    if ( $color !== '' ) $parts[] = '<span class="wdss29-attr"><strong>Color:</strong> ' . esc_html($color) . '</span>';
    if ( $size  !== '' ) $parts[] = '<span class="wdss29-attr"><strong>Size:</strong> '  . esc_html($size)  . '</span>';
    if ( $back  !== '' ) $parts[] = '<span class="wdss29-attr"><strong>Back:</strong> '  . esc_html($back)  . '</span>';

    if ( empty($parts) ) return '';
    return '<div class="wdss29-attrs">'. implode(' &middot; ', $parts) .'</div>';
}

/** =====================================================================
 *  Unified Cart Actions
 * ===================================================================== */
add_action('init', function() {
    // ADD to cart
    if ( ! empty($_POST['wdss29_cart_action']) && $_POST['wdss29_cart_action'] === 'add' ) {
        if ( ! isset($_POST['_wpnonce']) || ! wp_verify_nonce($_POST['_wpnonce'], 'wdss29_cart') ) return;

        $pid   = intval($_POST['product_id'] ?? 0);
        $qty   = max(1, intval($_POST['qty'] ?? 1));
        $opts  = wdss29_sanitize_addons_from_post($_POST);

        if ( $pid ) {
            $cart = wdss29_get_cart();

            // Merge same pid + same options
            $merged = false;
            foreach ($cart as &$line) {
                if ( intval($line['pid']) === $pid
                     && isset($line['opts']) && $line['opts'] == $opts ) {
                    $line['qty'] = max(1, intval($line['qty'])) + $qty;
                    $merged = true;
                    break;
                }
            }
            unset($line);

            if ( ! $merged ) {
                $cart[] = array(
                    'pid'  => $pid,
                    'qty'  => $qty,
                    'opts' => $opts,
                );
            }

            wdss29_set_cart($cart);
        }

        wp_safe_redirect( wp_get_referer() ?: home_url('/') );
        exit;
    }

    // CART bulk actions (update qty / remove line / clear)
    if ( ! empty($_POST['wdss29_cart_submit']) ) {
        if ( ! isset($_POST['_wpnonce']) || ! wp_verify_nonce($_POST['_wpnonce'], 'wdss29_cart') ) return;

        $cart = wdss29_get_cart();

        // Remove a line by index
        if ( isset($_POST['wdss29_remove']) ) {
            $idx = intval($_POST['wdss29_remove']);
            if ( isset($cart[$idx]) ) {
                unset($cart[$idx]);
                $cart = array_values($cart);
                wdss29_set_cart($cart);
            }
            wp_safe_redirect( wp_get_referer() ?: home_url('/') );
            exit;
        }

        // Clear all
        if ( isset($_POST['wdss29_clear']) ) {
            wdss29_empty_cart();
            wp_safe_redirect( wp_get_referer() ?: home_url('/') );
            exit;
        }

        // Update quantities
        if ( ! empty($_POST['qty']) && is_array($_POST['qty']) ) {
            foreach ($_POST['qty'] as $i => $q) {
                $q = max(0, intval($q));
                if ( isset($cart[$i]) ) {
                    if ( $q === 0 ) {
                        unset($cart[$i]);
                    } else {
                        $cart[$i]['qty'] = $q;
                    }
                }
            }
            $cart = array_values($cart);
            wdss29_set_cart($cart);
        }

        wp_safe_redirect( wp_get_referer() ?: home_url('/') );
        exit;
    }
});

/** =====================================================================
 *  Stripe Checkout — Original (kept for backward compat)
 * ===================================================================== */
add_action('init', function() {
    if ( empty($_POST['wdss29_checkout_action']) || $_POST['wdss29_checkout_action'] !== 'create_session' ) {
        return;
    }

    $is_admin = current_user_can('manage_options');
    $friendly = 'A checkout error occurred. Please refresh and try again, or contact support.';

    $fail = function( $title, $detail = '', $http = 500 ) use ( $is_admin, $friendly ) {
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log('[WDSS Checkout] ' . $title . ( $detail ? ' | ' . $detail : '' ));
        }
        set_transient( 'wdss_last_checkout_error', $title . ( $detail ? ' | ' . $detail : '' ), 15 * MINUTE_IN_SECONDS );
        $msg = $is_admin ? ( $title . ( $detail ? '<br><code>' . esc_html($detail) . '</code>' : '' ) ) : $friendly;
        wp_die( $msg, 'Checkout Error', array('response' => $http) );
    };

    // Early nonce
    if ( ! isset($_POST['_wpnonce']) || ! wp_verify_nonce($_POST['_wpnonce'], 'wdss29_checkout') ) {
        $fail('Security check failed (nonce).', 'Form missing wp_nonce_field("wdss29_checkout") or session expired.', 400);
    }

    try {
        $s = wdss29_get_settings();
        $secret = isset($s['stripe_sk']) ? trim($s['stripe_sk']) : '';
        if (!$secret) $fail('Stripe Secret Key not configured.', 'Add sk_test_… or sk_live_… in WD Store settings.', 400);
        if (strpos($secret, 'sk_') !== 0) $fail('Stripe Secret Key looks invalid.', 'Key must start with sk_test_ or sk_live_.', 400);

        $cart = wdss29_get_cart();
        if ( empty($cart) ) $fail('Cart is empty.', '', 400);

        $totals = wdss29_calc_totals($cart);
        $items  = wdss29_normalize_cart_items($cart);
        if ( empty($items) ) $fail('Normalized cart is empty.');

        $line_items = array();
        foreach ($items as $it) {
            $pid  = intval($it['pid'] ?? 0);
            $qty  = max(1, intval($it['qty'] ?? 1));
            $opts = is_array($it['opts'] ?? null) ? $it['opts'] : array();

            $base  = floatval(wdss29_get_product_price($pid));
            $addon = floatval(wdss29_calc_addon_unit_price($opts));
            $unit  = $base + $addon;

            $cents = intval(round($unit*100));
            if ($cents < 50) $fail('Line item below Stripe minimum.', 'pid='.$pid.' cents='.$cents, 400);

            $title  = (string) wdss29_get_product_title($pid);
            $suffix = (string) wdss29_format_addons_label($opts);

            $line_items[] = array(
                'price_data' => array(
                    'currency'     => 'usd',
                    'product_data' => array('name' => $title.$suffix),
                    'unit_amount'  => $cents,
                ),
                'quantity' => $qty,
            );
        }
        if (!empty($totals['sales_tax'])) {
            $line_items[] = array(
                'price_data' => array(
                    'currency'     => 'usd',
                    'product_data' => array('name' => 'Sales Tax (' . rtrim(rtrim(number_format(floatval($totals['sales_rate'] ?? 0),2), '0'), '.') . '%)'),
                    'unit_amount'  => intval(round(floatval($totals['sales_tax'])*100)),
                ),
                'quantity' => 1,
            );
        }
        if (!empty($totals['card_fee'])) {
            $line_items[] = array(
                'price_data' => array(
                    'currency'     => 'usd',
                    'product_data' => array('name' => 'Card Processing Fee (' . rtrim(rtrim(number_format(floatval($totals['card_rate'] ?? 0),2), '0'), '.') . '%)'),
                    'unit_amount'  => intval(round(floatval($totals['card_fee'])*100)),
                ),
                'quantity' => 1,
            );
        }

        $success_url = add_query_arg(
            array('wdss29' => 'success', 'session_id' => '{CHECKOUT_SESSION_ID}'),
            home_url('/checkout-success/')
        );
        $cancel_url  = add_query_arg(array('wdss29' => 'cancel'), wp_get_referer() ?: home_url('/'));

        $flatten = array(
            'mode'        => 'payment',
            'success_url' => esc_url_raw($success_url),
            'cancel_url'  => esc_url_raw($cancel_url),
            'line_items'  => array(),
        );
        foreach ($line_items as $li) {
            $flatten['line_items'][] = array(
                'quantity'   => max(1, intval($li['quantity'])),
                'price_data' => array(
                    'currency'     => (string)$li['price_data']['currency'],
                    'unit_amount'  => (int)$li['price_data']['unit_amount'],
                    'product_data' => array('name' => (string)$li['price_data']['product_data']['name']),
                ),
            );
        }

        $resp = wp_remote_post('https://api.stripe.com/v1/checkout/sessions', array(
            'headers' => array( 'Authorization' => 'Bearer ' . $secret ),
            'body'    => $flatten,
            'timeout' => 30,
        ));
        if ( is_wp_error($resp) ) {
            $fail('Could not reach Stripe (WP_Error).', $resp->get_error_message(), 502);
        }

        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);
        $json = json_decode($raw, true);

        if ( $code >= 200 && $code < 300 && ! empty($json['url']) ) {
            wp_redirect( $json['url'] );
            exit;
        }

        if ( ! empty($json['error']['message']) ) {
            $fail('Stripe API error.', $json['error']['message'] . ' (HTTP ' . $code . ')', 500);
        }

        $fail('Stripe returned an unexpected response.', 'HTTP ' . $code . ' body=' . substr($raw,0,500), 500);

    } catch ( \Throwable $e ) {
        $fail('Unhandled exception.', $e->getMessage(), 500);
    }
});

/** =====================================================================
 *  V2 Checkout (pending row first + item persistence)
 * ===================================================================== */
add_action('init', function() {
    if ( empty($_POST['wdss29_checkout_action']) || $_POST['wdss29_checkout_action'] !== 'create_session' ) return;
    if ( ! isset($_POST['_wpnonce']) || ! wp_verify_nonce($_POST['_wpnonce'], 'wdss29_checkout') ) return;

    wdss29_log('checkout_v2:start');

    // Ensure tables
    wdss29_maybe_install_or_upgrade_wd_orders_table();
    wdss29_maybe_install_or_upgrade_order_items_table();

    $s = wdss29_get_settings();
    $secret = isset($s['stripe_sk']) ? trim($s['stripe_sk']) : '';
    if ( ! $secret || strpos($secret, 'sk_') !== 0 ) {
        wdss29_log('checkout_v2:missing_or_bad_secret');
        wp_die('Stripe is not configured. Please contact support.', 'Checkout', array('response' => 500));
    }

    $cart = wdss29_get_cart();
    if ( empty($cart) ) {
        wdss29_log('checkout_v2:empty_cart');
        wp_die('Your cart is empty.', 'Checkout', array('response' => 400));
    }
    $totals = wdss29_calc_totals($cart);

    // Create a pending order row now
    $u = wp_get_current_user();
    $uid   = $u && $u->ID ? (int) $u->ID : 0;
    $email = $u && $u->ID ? $u->user_email : '';

    $client_ref = 'wd-' . wp_generate_password(12, false);
    $pending_meta = array(
        'pending'             => true,
        'client_reference_id' => $client_ref,
        'cart'                => $cart,
        'totals'              => $totals,
    );

    $pending_id = wdss29_save_order(array(
        'customer_id'    => $uid,
        'customer_name'  => $u && $u->ID ? ($u->display_name ?: $u->user_login) : '',
        'customer_email' => $email,
        'total'          => floatval($totals['grand']),
        'status'         => 'pending',
        'payment_id'     => '',
        'meta'           => $pending_meta,
    ));

    wdss29_log('checkout_v2:pending_order_created', array('pending_id' => $pending_id, 'client_ref' => $client_ref));

    // Persist items now (pending)
    $norm_items = wdss29_normalize_cart_items($cart);
    $persist_rows = array();
    foreach ($norm_items as $it) {
        $pid  = intval($it['pid'] ?? 0);
        $qty  = max(1, intval($it['qty'] ?? 1));
        $opts = is_array($it['opts'] ?? null) ? $it['opts'] : array();
        $base  = floatval(wdss29_get_product_price($pid));
        $addon = floatval(wdss29_calc_addon_unit_price($opts));
        $persist_rows[] = array(
            'product_id'  => $pid,
            'quantity'    => $qty,
            'unit_price'  => $base,
            'addon_price' => $addon,
            'line_total'  => ($base + $addon) * $qty,
            'addons'      => $opts,
        );
    }
    if ( $pending_id ) {
        wdss29_save_order_items( $pending_id, $persist_rows );
    }

    // Build Stripe body
    $line_items = array();
    foreach ($persist_rows as $row) {
        $pid   = (int) $row['product_id'];
        $qty   = (int) $row['quantity'];
        $unit  = (float) $row['unit_price'] + (float) $row['addon_price'];
        $cents = max(50, intval(round($unit*100)));
        $title = (string) wdss29_get_product_title($pid);
        $suffix = (string) wdss29_format_addons_label( is_array($row['addons']) ? $row['addons'] : array() );
        $line_items[] = array(
            'price_data' => array(
                'currency'     => 'usd',
                'product_data' => array('name' => $title.$suffix),
                'unit_amount'  => $cents,
            ),
            'quantity' => $qty,
        );
    }
    if (!empty($totals['sales_tax'])) {
        $line_items[] = array(
            'price_data' => array(
                'currency'     => 'usd',
                'product_data' => array('name' => 'Sales Tax (' . rtrim(rtrim(number_format(floatval($totals['sales_rate'] ?? 0),2), '0'), '.') . '%)'),
                'unit_amount'  => intval(round(floatval($totals['sales_tax'])*100)),
            ),
            'quantity' => 1,
        );
    }
    if (!empty($totals['card_fee'])) {
        $line_items[] = array(
            'price_data' => array(
                'currency'     => 'usd',
                'product_data' => array('name' => 'Card Processing Fee (' . rtrim(rtrim(number_format(floatval($totals['card_rate'] ?? 0),2), '0'), '.') . '%)'),
                'unit_amount'  => intval(round(floatval($totals['card_fee'])*100)),
            ),
            'quantity' => 1,
        );
    }

    // CRITICAL: Build success_url manually to preserve {CHECKOUT_SESSION_ID} placeholder
    // Do NOT use esc_url_raw() as it will encode the braces and Stripe won't replace it!
    $success_base = home_url('/checkout-success/');
    $success_url = rtrim($success_base, '/') . '/?wdss29=success&session_id={CHECKOUT_SESSION_ID}';
    
    $cancel_url  = add_query_arg(array('wdss29' => 'cancel'), wp_get_referer() ?: home_url('/'));

    $body = array(
        'mode'                 => 'payment',
        'success_url'          => $success_url,  // Already a valid URL, don't escape
        'cancel_url'           => esc_url_raw($cancel_url),
        'client_reference_id'  => $client_ref,
        'metadata'             => array(
            'wd_pending_order_id' => (string) $pending_id,
            'site'                => home_url('/'),
        ),
        'line_items'           => array(),
    );
    foreach ($line_items as $li) {
        $body['line_items'][] = array(
            'quantity'   => max(1, (int)$li['quantity']),
            'price_data' => array(
                'currency'     => (string)$li['price_data']['currency'],
                'unit_amount'  => (int)$li['price_data']['unit_amount'],
                'product_data' => array( 'name' => (string)$li['price_data']['product_data']['name'] ),
            ),
        );
    }

    $resp = wp_remote_post('https://api.stripe.com/v1/checkout/sessions', array(
        'headers' => array( 'Authorization' => 'Bearer ' . $secret ),
        'body'    => $body,
        'timeout' => 30,
    ));
    if ( is_wp_error($resp) ) {
        wdss29_log('checkout_v2:stripe_unreachable', array('err' => $resp->get_error_message()));
        wp_die('Could not reach payment provider. Please try again.', 'Checkout', array('response' => 502));
    }

    $code = wp_remote_retrieve_response_code($resp);
    $raw  = wp_remote_retrieve_body($resp);
    $json = json_decode($raw, true);
    wdss29_log('checkout_v2:stripe_response', array('http' => $code, 'body_snippet' => substr($raw,0,300)));

    if ( $code >= 200 && $code < 300 && ! empty($json['url']) ) {
        wp_redirect( $json['url'] );
        exit;
    }

    if ( ! empty($json['error']['message']) ) {
        wdss29_log('checkout_v2:stripe_api_error', array('message' => $json['error']['message']));
        wp_die( 'Payment error: ' . esc_html($json['error']['message']), 'Checkout Error', array('response' => 500) );
    }

    wp_die('Unexpected payment response. Please contact support.', 'Checkout Error', array('response' => 500));
}, 9);

/** =====================================================================
 *  V2 Success (promote pending->paid + ensure items)
 *  FIXED: Now uses correct wdss29_orders table and triggers email automation
 * ===================================================================== */
add_action('init', function () {
    if ( empty($_GET['wdss29']) || $_GET['wdss29'] !== 'success' ) return;

    $session_id = isset($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : '';
    if ( $session_id === '' ) { wdss29_log('success_v2:missing_session_id'); return; }

    $s = wdss29_get_settings();
    $secret = isset($s['stripe_sk']) ? trim($s['stripe_sk']) : '';
    if ( ! $secret || strpos($secret, 'sk_') !== 0 ) { wdss29_log('success_v2:bad_secret'); return; }

    // Stripe session fetch
    $resp = wp_remote_get(
        'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($session_id),
        array('headers' => array('Authorization' => 'Bearer ' . $secret), 'timeout' => 20)
    );
    if ( is_wp_error($resp) ) { wdss29_log('success_v2:stripe_get_error', array('err'=>$resp->get_error_message())); return; }

    $code = wp_remote_retrieve_response_code($resp);
    $raw  = wp_remote_retrieve_body($resp);
    $json = json_decode($raw, true);

    if ( $code !== 200 || ! is_array($json) ) { wdss29_log('success_v2:bad_response', array('http'=>$code, 'raw'=>substr($raw,0,200))); return; }
    if ( ($json['payment_status'] ?? '') !== 'paid' ) { wdss29_log('success_v2:not_paid', array('status'=>$json['payment_status'] ?? '')); return; }

    $client_ref   = (string) ($json['client_reference_id'] ?? '');
    $amount_total = isset($json['amount_total']) && is_numeric($json['amount_total']) ? round(((int)$json['amount_total'])/100, 2) : 0.0;

    // Get customer details from Stripe
    $cust_email = '';
    $cust_name  = '';
    if (!empty($json['customer_details']) && is_array($json['customer_details'])) {
        $cust_email = sanitize_email($json['customer_details']['email'] ?? '');
        $cust_name  = sanitize_text_field($json['customer_details']['name'] ?? '');
    }

    $uid = 0;
    $u   = wp_get_current_user();
    if ($u && $u->ID) $uid = (int) $u->ID;

    // NOTE: checkout flow uses wd_orders table, not wdss29_orders
    global $wpdb;
    $table = $wpdb->prefix . 'wd_orders';
    $cols  = wdss29_get_orders_table_columns();
    
    wdss29_log('success_v2:table_columns_detected', array(
        'table' => $table,
        'has_meta_column' => isset($cols['meta']),
        'all_columns' => array_keys($cols)
    ));

    // Check for duplicate payment
    if ( ! empty($session_id) ) {
        $dup = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE payment_id=%s AND status='paid' LIMIT 1",
            $session_id
        ) );
        if ( $dup > 0 ) {
            wdss29_log('success_v2:already_paid_duplicate');
            if ( function_exists('wdss29_empty_cart') ) wdss29_empty_cart();
            wp_safe_redirect( home_url('/checkout-success/') );
            exit;
        }
    }

    // Find pending order by client_reference_id in meta
    $order_row = null;
    if ( $client_ref !== '' && isset($cols['meta']) ) {
        // Debug: Check what pending orders exist
        $pending_orders = $wpdb->get_results(
            "SELECT id, customer_id, total, meta, created_at FROM {$table} WHERE status = 'pending' ORDER BY id DESC LIMIT 5",
            ARRAY_A
        );
        wdss29_log('success_v2:pending_orders_check', array(
            'client_ref_searching_for' => $client_ref,
            'pending_count' => count($pending_orders),
            'recent_orders' => array_map(function($o) { 
                return array(
                    'id' => $o['id'], 
                    'customer_id' => $o['customer_id'],
                    'total' => $o['total'],
                    'meta_snippet' => substr($o['meta'] ?? '', 0, 200)
                ); 
            }, $pending_orders)
        ));
        
        $search_pattern = '%' . $wpdb->esc_like( '"client_reference_id":"' . $client_ref . '"' ) . '%';
        wdss29_log('success_v2:search_pattern', array('pattern' => $search_pattern));
        
        $order_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE status = 'pending'
                 AND meta LIKE %s
                 ORDER BY id DESC
                 LIMIT 1",
                $search_pattern
            ),
            ARRAY_A
        );
        
        wdss29_log('success_v2:meta_search_result', array('found' => !empty($order_row), 'order_id' => $order_row['id'] ?? null));
    } else {
        wdss29_log('success_v2:meta_search_skipped', array(
            'reason' => !isset($cols['meta']) ? 'meta_column_missing' : 'no_client_ref',
            'client_ref' => $client_ref
        ));
    }
    
    // Fallback: If meta search failed, try to find most recent pending order for this user
    if ( ! $order_row && $uid > 0 ) {
        wdss29_log('success_v2:trying_fallback_by_user', array('user_id' => $uid));
        
        $order_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE status = 'pending'
                 AND customer_id = %d
                 AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                 ORDER BY id DESC
                 LIMIT 1",
                $uid
            ),
            ARRAY_A
        );
        
        if ( $order_row ) {
            wdss29_log('success_v2:fallback_found_order', array(
                'order_id' => $order_row['id'],
                'note' => 'Found by customer_id + recent timestamp'
            ));
        }
    }

    if ( $order_row ) {
        $order_id = (int) $order_row['id'];

        wdss29_log('success_v2:order_found', array(
            'order_id' => $order_id,
            'current_status' => $order_row['status'] ?? 'unknown',
            'client_ref' => $client_ref,
            'table' => $table
        ));

        // Update order to paid status in wd_orders table
        $update_result = $wpdb->update(
            $table,
            array(
                'status'     => 'paid',
                'payment_id' => $session_id,
                'total'      => $amount_total > 0 ? $amount_total : floatval($order_row['total']),
                'customer_id'=> $uid ?: intval($order_row['customer_id']),
            ),
            array( 'id' => $order_id ),
            array('%s','%s','%f','%d'),
            array('%d')
        );

        if ( $update_result === false ) {
            wdss29_log('success_v2:update_failed', array(
                'error' => $wpdb->last_error,
                'order_id' => $order_id
            ));
        } else {
            wdss29_log('success_v2:update_success', array(
                'order_id' => $order_id,
                'rows_affected' => $update_result
            ));
        }

        // Verify the update worked
        $verify = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$table} WHERE id = %d",
            $order_id
        ) );
        wdss29_log('success_v2:status_after_update', array(
            'order_id' => $order_id,
            'status' => $verify
        ));

        // Re-persist items (idempotent - ensures they're saved)
        $meta = array();
        if ( isset($order_row['meta']) && $order_row['meta'] !== '' ) {
            $meta = json_decode($order_row['meta'], true);
        }
        if ( is_array($meta) && ! empty($meta['cart']) && is_array($meta['cart']) ) {
            $cart = $meta['cart'];
            $norm = wdss29_normalize_cart_items($cart);
            $rows = array();
            foreach ($norm as $it) {
                $pid  = intval($it['pid'] ?? 0);
                $qty  = max(1, intval($it['qty'] ?? 1));
                $opts = is_array($it['opts'] ?? null) ? $it['opts'] : array();
                $base  = floatval(wdss29_get_product_price($pid));
                $addon = floatval(wdss29_calc_addon_unit_price($opts));
                $rows[] = array(
                    'product_id'  => $pid,
                    'quantity'    => $qty,
                    'unit_price'  => $base,
                    'addon_price' => $addon,
                    'line_total'  => ($base + $addon) * $qty,
                    'addons'      => $opts,
                );
            }
            if ( function_exists('wdss29_save_order_items') ) {
                wdss29_save_order_items( $order_id, $rows );
            }
        }

        // Build payload for email automation
        $hints = array(
            'customer_email' => $cust_email ?: ($order_row['customer_email'] ?? ''),
            'customer_name'  => $cust_name ?: ($order_row['customer_name'] ?? ''),
            'payment_method' => 'stripe',
            'stripe_session_id' => $session_id,
        );

        $payload = array(
            'order_id'       => $order_id,
            'order_number'   => (string) $order_id,
            'order_status'   => 'paid',
            'status'         => 'paid',  // Alias for backward compatibility with conditions
            'order_total'    => floatval($order_row['total']),
            'currency'       => get_option('wdss_currency','USD'),
            'customer_email' => $hints['customer_email'],
            'customer_name'  => $hints['customer_name'],
            'payment_method' => 'stripe',
            '_idem_key'      => 'order.paid|' . $order_id,
        );

        // CRITICAL FIX: Fire email automation trigger with correct parameters
        wdss29_log('success_v2:about_to_fire_email_trigger', array(
            'event' => 'order.paid',
            'order_id' => $order_id,
            'customer_email' => $payload['customer_email'],
            'has_customer_email' => !empty($payload['customer_email'])
        ));
        
        do_action( 'wdss_email_trigger', 'order.paid', $order_id, $payload );

        // Wake the email poller
        if ( function_exists('wp_schedule_single_event') ) {
            wp_schedule_single_event( time() + 5, 'wdss_email_order_poller_tick' );
            wdss29_log('success_v2:email_poller_scheduled', array('timestamp' => time() + 5));
        }

        wdss29_log('success_v2:order_marked_paid', array(
            'order_id' => $order_id,
            'email' => $hints['customer_email'],
            'session_id' => $session_id,
            'client_ref' => $client_ref
        ));
    } else {
        // Fallback: create new paid order if no pending order found
        wdss29_log('success_v2:no_pending_order', array(
            'client_ref' => $client_ref,
            'searched_table' => $table,
            'note' => 'No pending order found with this client_reference_id - creating new order'
        ));

        $meta = array(
            'stripe_session_id'   => $session_id,
            'client_reference_id' => $client_ref,
            'from'                => 'success_fallback',
        );
        $new_id = wdss29_save_order(array(
            'customer_id'    => $uid,
            'customer_name'  => $cust_name,
            'customer_email' => $cust_email,
            'total'          => $amount_total,
            'status'         => 'paid',
            'payment_id'     => $session_id,
            'meta'           => $meta,
        ));

        if ( $new_id ) {
            $payload = array(
                'order_id'       => $new_id,
                'order_number'   => (string) $new_id,
                'order_status'   => 'paid',
                'status'         => 'paid',  // Alias for backward compatibility with conditions
                'order_total'    => $amount_total,
                'currency'       => get_option('wdss_currency','USD'),
                'customer_email' => $cust_email,
                'customer_name'  => $cust_name,
                'payment_method' => 'stripe',
                '_idem_key'      => 'order.paid|' . $new_id,
            );

            wdss29_log('success_v2:firing_email_trigger', array(
                'event' => 'order.paid',
                'order_id' => $new_id,
                'customer_email' => $cust_email,
                'payload_keys' => array_keys($payload)
            ));
            
            do_action( 'wdss_email_trigger', 'order.paid', $new_id, $payload );

            if ( function_exists('wp_schedule_single_event') ) {
                wp_schedule_single_event( time() + 5, 'wdss_email_order_poller_tick' );
            }

            wdss29_log('success_v2:fallback_order_created', array(
                'id' => $new_id,
                'email' => $cust_email,
                'total' => $amount_total
            ));
        }
    }

    if ( function_exists('wdss29_empty_cart') ) wdss29_empty_cart();

    // Set last order transient
    if ( $uid > 0 ) {
        $final_order_id = isset($order_id) ? $order_id : (isset($new_id) ? $new_id : 0);
        if ( $final_order_id > 0 ) {
            set_transient('wdss29_last_order_user_' . $uid, $final_order_id, 2 * HOUR_IN_SECONDS);
        }
    }

    wp_safe_redirect( home_url('/checkout-success/') );
    exit;
}, 8);

/** De-dupe by payment_id (early) - DISABLED: Now handled in main success handler above */
// This function is no longer needed as duplicate checking is done in the main success handler
// Left here as a comment for reference

/** Ensure success page exists */
add_action('admin_init', function () {
    $slug = 'checkout-success';
    $p = get_page_by_path($slug);
    if (!$p) {
        $id = wp_insert_post(array(
            'post_title'   => 'Checkout Success',
            'post_name'    => $slug,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[wd_checkout_success]',
        ));
        if (!is_wp_error($id)) {
            // page created
        }
    }
});

/** =====================================================================
 *  Shortcodes
 * ===================================================================== */

/** [wd_cart] */
function wdss29_shortcode_cart() {
    $cart   = wdss29_get_cart();
    $totals = wdss29_calc_totals($cart);

    ob_start(); ?>
    <div class="wdss29-cart">
      <h2>Your Cart</h2>
      <?php if ( empty($cart) ) : ?>
          <p>Your cart is empty.</p>
      <?php else: ?>
        <form method="post">
            <?php wp_nonce_field('wdss29_cart'); ?>
            <input type="hidden" name="wdss29_cart_submit" value="1">

            <table class="wdss29-table" style="width:100%;border-collapse:collapse">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">Product</th>
                        <th style="text-align:right;padding:8px;border-bottom:1px solid #ddd;">Unit Price</th>
                        <th style="text-align:center;padding:8px;border-bottom:1px solid #ddd;">Qty</th>
                        <th style="text-align:right;padding:8px;border-bottom:1px solid #ddd;">Line Total</th>
                        <th style="padding:8px;border-bottom:1px solid #ddd;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($cart as $i => $line):
                    $pid   = intval($line['pid']);
                    $qty   = max(1, intval($line['qty']));
                    $opts  = isset($line['opts']) ? $line['opts'] : array();

                    $title = esc_html( wdss29_get_product_title($pid) );
                    $base  = wdss29_get_product_price($pid);
                    $addon = wdss29_calc_addon_unit_price($opts);
                    $unit  = $base + $addon;
                    $line_total = $unit * $qty;

                    $addon_label = wdss29_format_addons_label($opts);
                ?>
                    <tr>
                        <td style="padding:8px;border-bottom:1px solid #eee;">
                            <?php echo $title; ?>
                            <?php if ($addon_label || $addon > 0): ?>
                                <div style="color:#555;font-size:12px;margin-top:2px;">
                                    <?php echo esc_html( trim($addon_label, '()') ); ?>
                                    <?php if ($addon > 0): ?>
                                        <?php echo ($addon_label ? ' · ' : ''); ?>Add-ons: $<?php echo number_format($addon,2); ?> / ea
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="padding:8px;border-bottom:1px solid #eee;text-align:right;">
                            <?php echo '$' . number_format($unit,2); ?>
                        </td>
                        <td style="padding:8px;border-bottom:1px solid #eee;text-align:center;">
                            <input type="number" min="0" name="qty[<?php echo intval($i); ?>]" value="<?php echo intval($qty); ?>" style="width:70px;">
                        </td>
                        <td style="padding:8px;border-bottom:1px solid #eee;text-align:right;">
                            <?php echo '$' . number_format($line_total,2); ?>
                        </td>
                        <td style="padding:8px;border-bottom:1px solid #eee;text-align:center;">
                            <button type="submit" name="wdss29_remove" value="<?php echo intval($i); ?>" class="wdss29-btn" style="background:#999;">Remove</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="3" style="padding:8px;text-align:right;">Subtotal:</th>
                        <th style="padding:8px;text-align:right;"><?php echo '$' . number_format($totals['subtotal'],2); ?></th>
                        <th></th>
                    </tr>
                    <tr>
                        <th colspan="3" style="padding:8px;text-align:right;">Sales Tax (<?php echo rtrim(rtrim(number_format($totals['sales_rate'],2), '0'), '.'); ?>%):</th>
                        <th style="padding:8px;text-align:right;"><?php echo '$' . number_format($totals['sales_tax'],2); ?></th>
                        <th></th>
                    </tr>
                    <tr>
                        <th colspan="3" style="padding:8px;text-align:right;">Card Processing Fee (<?php echo rtrim(rtrim(number_format($totals['card_rate'],2), '0'), '.'); ?>%):</th>
                        <th style="padding:8px;text-align:right;"><?php echo '$' . number_format($totals['card_fee'],2); ?></th>
                        <th></th>
                    </tr>
                    <tr>
                        <th colspan="3" style="padding:8px;text-align:right;">Total:</th>
                        <th style="padding:8px;text-align:right;"><?php echo '$' . number_format($totals['grand'],2); ?></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>

            <p style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;">
                <button type="submit" name="wdss29_update" class="wdss29-btn">Update Cart</button>
                <button type="submit" name="wdss29_clear" class="wdss29-btn" style="background:#b10;">Clear Cart</button>
            </p>
        </form>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('wd_cart', 'wdss29_shortcode_cart');

/** [wd_checkout] */
function wdss29_shortcode_checkout() {
    $cart = wdss29_get_cart();
    if ( empty($cart) ) {
        return '<p>Your cart is empty.</p>';
    }

    $totals = wdss29_calc_totals($cart);

    ob_start(); ?>
    <div class="wdss29-checkout">
        <h2>Checkout</h2>
        <ul style="list-style:none;padding:0;margin:0 0 12px;">
            <?php foreach (wdss29_normalize_cart_items($cart) as $line):
                $title = wdss29_get_product_title($line['pid']);
                $qty   = intval($line['qty']);
                $opts  = isset($line['opts']) ? $line['opts'] : array();
                $lbl   = wdss29_format_addons_label($opts);
                $addon = wdss29_calc_addon_unit_price($opts);
                $base  = wdss29_get_product_price($line['pid']);
                $unit  = $base + $addon;
            ?>
            <li style="margin:2px 0;">
                <?php echo esc_html($title); ?> × <?php echo intval($qty); ?>
                <?php echo esc_html($lbl); ?>
                — $<?php echo number_format($unit,2); ?> / ea
            </li>
            <?php endforeach; ?>
        </ul>

        <p>Subtotal: <strong><?php echo '$' . number_format($totals['subtotal'],2); ?></strong></p>
        <p>Sales Tax (<?php echo rtrim(rtrim(number_format($totals['sales_rate'],2), '0'), '.'); ?>%): <strong><?php echo '$' . number_format($totals['sales_tax'],2); ?></strong></p>
        <p>Card Processing Fee (<?php echo rtrim(rtrim(number_format($totals['card_rate'],2), '0'), '.'); ?>%): <strong><?php echo '$' . number_format($totals['card_fee'],2); ?></strong></p>
        <p>Order Total: <strong><?php echo '$' . number_format($totals['grand'],2); ?></strong></p>

        <form method="post">
            <?php wp_nonce_field('wdss29_checkout'); ?>
            <input type="hidden" name="wdss29_checkout_action" value="create_session">
            <button type="submit">Proceed to Secure Payment</button>
        </form>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('wd_checkout', 'wdss29_shortcode_checkout');

/** [wd_add_to_cart id="123" qty="1"] */
function wdss29_shortcode_add_to_cart( $atts ) {
    $a = shortcode_atts(array(
        'id'  => 0,
        'qty' => 1,
        'label' => 'Add to Cart'
    ), $atts);

    $pid = intval($a['id']);
    if ( ! $pid || get_post_type($pid) !== 'wd_product' ) return '';

    ob_start(); ?>
    <form method="post" class="wdss29-add-to-cart">
        <?php wp_nonce_field('wdss29_cart'); ?>
        <input type="hidden" name="wdss29_cart_action" value="add">
        <input type="hidden" name="product_id" value="<?php echo $pid; ?>">
        <input type="hidden" name="qty" value="<?php echo intval($a['qty']); ?>">
        <button type="submit"><?php echo esc_html($a['label']); ?></button>
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('wd_add_to_cart', 'wdss29_shortcode_add_to_cart');

/** [wd_my_orders] — shows tracking id when available */
function wdss29_shortcode_my_orders( $atts = array(), $content = null ) {
    if ( ! is_user_logged_in() ) {
        $login_url = wp_login_url( get_permalink() );
        return '<p>You need to <a href="' . esc_url( $login_url ) . '">log in</a> to view your orders.</p>';
    }

    wdss29_maybe_install_or_upgrade_wd_orders_table();

    $user  = wp_get_current_user();
    $uid   = intval( $user->ID );
    $email = isset( $user->user_email ) ? trim( $user->user_email ) : '';

    global $wpdb;
    $table = $wpdb->prefix . 'wd_orders';
    $cols  = wdss29_get_orders_table_columns();

    $select_tracking = isset($cols['tracking_id']) ? 'tracking_id' : "'' AS tracking_id";

    if ( isset($cols['customer_id']) && $uid > 0 ) {
        $orders = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, total, status, payment_id, {$select_tracking}, created_at
                 FROM {$table}
                 WHERE customer_id = %d
                 ORDER BY created_at DESC
                 LIMIT 100",
                $uid
            ),
            ARRAY_A
        );
    } elseif ( isset($cols['customer_email']) && $email !== '' ) {
        $orders = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, total, status, payment_id, {$select_tracking}, created_at
                 FROM {$table}
                 WHERE customer_email = %s
                 ORDER BY created_at DESC
                 LIMIT 100",
                $email
            ),
            ARRAY_A
        );
    } else {
        $orders = $wpdb->get_results(
            "SELECT id, total, status, payment_id, {$select_tracking}, created_at
             FROM {$table}
             ORDER BY created_at DESC
             LIMIT 50",
            ARRAY_A
        );
    }

    ob_start();

    echo '<div class="wdss29-my-orders">';
    echo '<h2>My Orders</h2>';

    if ( empty( $orders ) ) {
        echo '<p>You have no past orders yet.</p>';
        echo '</div>';
        return ob_get_clean();
    }

    echo '<table class="wdss29-table" style="width:100%;border-collapse:collapse;">';
    echo '<thead>
            <tr>
              <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">Order #</th>
              <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">Date</th>
              <th style="text-align:right;padding:8px;border-bottom:1px solid #ddd;">Total</th>
              <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;">Status</th>
              <th style="padding:8px;border-bottom:1px solid #ddd;">Payment ID</th>
              <th style="padding:8px;border-bottom:1px solid #ddd;">Tracking ID</th>
            </tr>
          </thead>
          <tbody>';

    foreach ( $orders as $o ) {
        $order_id  = intval( $o['id'] );
        $total     = is_numeric( $o['total'] ) ? floatval( $o['total'] ) : 0.0;
        $status    = ! empty($o['status']) ? esc_html( $o['status'] ) : 'pending';
        $payment   = ! empty($o['payment_id']) ? esc_html( $o['payment_id'] ) : '&mdash;';
        $track     = isset($o['tracking_id']) && $o['tracking_id'] !== '' ? esc_html($o['tracking_id']) : '&mdash;';
        $ts        = ! empty($o['created_at']) ? strtotime( $o['created_at'] ) : false;

        $date_str = $ts
            ? esc_html( date_i18n( get_option('date_format'), $ts ) . ' ' . date_i18n( get_option('time_format'), $ts ) )
            : esc_html( $o['created_at'] ?? '' );

        echo '<tr>';
        echo '<td style="padding:8px;border-bottom:1px solid #eee;">#' . $order_id . '</td>';
        echo '<td style="padding:8px;border-bottom:1px solid #eee;">' . $date_str . '</td>';
        echo '<td style="padding:8px;border-bottom:1px solid #eee;text-align:right;">$' . number_format( $total, 2 ) . '</td>';
        echo '<td style="padding:8px;border-bottom:1px solid #eee;">' . $status . '</td>';
        echo '<td style="padding:8px;border-bottom:1px solid #eee;">' . $payment . '</td>';
        echo '<td style="padding:8px;border-bottom:1px solid #eee;">' . $track . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';

    return ob_get_clean();
}
add_shortcode( 'wd_my_orders', 'wdss29_shortcode_my_orders' );

/** [wd_product_single id=""] */
function wdss29_shortcode_product_single( $atts = array(), $content = null ) {
    $a = shortcode_atts( array(
        'id' => 0,
    ), $atts );

    $pid = intval( $a['id'] );

    if ( ! $pid ) {
        $q = get_queried_object();
        if ( $q && isset($q->ID) && isset($q->post_type) && $q->post_type === 'wd_product' ) {
            $pid = intval( $q->ID );
        }
    }
    if ( ! $pid && ! empty($_GET['product_id']) ) {
        $pid = intval( $_GET['product_id'] );
    }
    if ( ! $pid || get_post_type( $pid ) !== 'wd_product' ) {
        return '<p>Product not found.</p>';
    }

    $title   = get_the_title( $pid );
    $price   = wdss29_get_product_price( $pid );
    $content = apply_filters( 'the_content', get_post_field( 'post_content', $pid ) );

    $thumb   = get_the_post_thumbnail( $pid, 'large', array( 'class' => 'wdss29-single-thumb' ) );
    if ( ! $thumb ) $thumb = '<div class="wdss29-single-thumb-fallback"></div>';

    $gallery_ids = wdss29_get_gallery_attachment_ids( $pid );
    if ( ! has_post_thumbnail( $pid ) && ! empty( $gallery_ids ) ) {
        $first = array_shift( $gallery_ids );
        if ( $first ) set_post_thumbnail( $pid, $first );
    }
    $full_main = get_the_post_thumbnail_url( $pid, 'full' );
    $group = 'wdss29-' . $pid;

    $defs = wdss29_addon_choices();
    $ap   = wdss29_get_addon_prices();

    $fmt_delta = function( $v ) {
        $v = (float)$v;
        if ( $v > 0 )  return ' (+' . number_format($v, 2) . ')';
        if ( $v < 0 )  return ' (-' . number_format(abs($v), 2) . ')';
        return '';
    };
    $labels = array(
        'back' => array(
            'Zip Up'  => 'Zip Up'  . $fmt_delta( $ap['back_zip_up']  ?? 0 ),
            'Lace Up' => 'Lace Up' . $fmt_delta( $ap['back_lace_up'] ?? 0 ),
        ),
        'length3' => array(
            'Yes' => 'Yes' . $fmt_delta( $ap['length3_yes'] ?? 0 ),
            'No'  => 'No'  . $fmt_delta( $ap['length3_no']  ?? 0 ),
        ),
        'train12' => array(
            'Yes' => 'Yes' . $fmt_delta( $ap['train12_yes'] ?? 0 ),
            'No'  => 'No'  . $fmt_delta( $ap['train12_no']  ?? 0 ),
        ),
    );

    ob_start(); ?>
    <div class="wdss29-product-single">
        <div class="wdss29-single-grid">
            <div class="wdss29-single-media">
                <div class="wdss29-single-thumb-wrap">
                    <?php if ( $full_main ) : ?>
                        <a href="<?php echo esc_url( $full_main ); ?>" class="wdss29-lightbox" data-group="<?php echo esc_attr($group); ?>">
                            <?php echo $thumb; ?>
                        </a>
                    <?php else: ?>
                        <?php echo $thumb; ?>
                    <?php endif; ?>
                </div>
                <?php echo wdss29_render_product_gallery( $pid ); ?>
            </div>
            <div class="wdss29-single-info">
                <h1 class="wdss29-single-title"><?php echo esc_html( $title ); ?></h1>
                <div class="wdss29-single-price"><?php echo '$' . number_format( $price, 2 ); ?></div>

                <?php echo wdss29_render_product_attributes( $pid ); ?>

                <div class="wdss29-single-actions">
                    <form method="post" class="wdss29-add-to-cart">
                        <?php wp_nonce_field('wdss29_cart'); ?>
                        <input type="hidden" name="wdss29_cart_action" value="add">
                        <input type="hidden" name="product_id" value="<?php echo intval($pid); ?>">

                        <div class="wdss29-addon-group" style="display:grid;gap:8px;margin:10px 0;">
                            <label>
                                <span style="display:block;margin-bottom:4px;">Back</span>
                                <select name="addon_back" required>
                                    <option value="">Select Back</option>
                                    <?php foreach ($defs['back'] as $opt): ?>
                                        <option value="<?php echo esc_attr($opt); ?>">
                                            <?php echo esc_html( $labels['back'][$opt] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label>
                                <span style="display:block;margin-bottom:4px;">Need an Additional 3&quot; Length in Front of Gown?</span>
                                <select name="addon_length3" required>
                                    <option value="">Select Option</option>
                                    <?php foreach ($defs['length3'] as $opt): ?>
                                        <option value="<?php echo esc_attr($opt); ?>">
                                            <?php echo esc_html( $labels['length3'][$opt] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label>
                                <span style="display:block;margin-bottom:4px;">Additional 12&quot; Train Length?</span>
                                <select name="addon_train12" required>
                                    <option value="">Select Option</option>
                                    <?php foreach ($defs['train12'] as $opt): ?>
                                        <option value="<?php echo esc_attr($opt); ?>">
                                            <?php echo esc_html( $labels['train12'][$opt] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <small style="color:#666;">Add-on prices are per item and included at checkout.</small>
                        </div>

                        <label style="display:inline-block;margin-right:8px;">
                            Qty:
                            <input type="number" name="qty" value="1" min="1" style="width:70px;margin-left:6px;">
                        </label>
                        <button type="submit" class="wdss29-btn">Add to Cart</button>
                    </form>
                </div>

                <div class="wdss29-single-content">
                    <?php echo $content; ?>
                </div>
            </div>
        </div>
        <div class="wdss29-single-back">
            <a href="<?php echo esc_url( wp_get_referer() ?: home_url('/') ); ?>">&larr; Back</a>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'wd_product_single', 'wdss29_shortcode_product_single' );

/** [wd_checkout_success] */
function wdss29_shortcode_checkout_success() {
    return '<h2>Thank you!</h2><p>If your payment completed, you will receive a confirmation email shortly.</p>';
}
add_shortcode('wd_checkout_success', 'wdss29_shortcode_checkout_success');

/** [wd_products per_page="12" columns="3" orderby="date" order="DESC"] */
function wdss29_shortcode_products( $atts ) {
    $a = shortcode_atts( array(
        'per_page' => 12,
        'columns'  => 3,
        'orderby'  => 'date',
        'order'    => 'DESC',
    ), $atts );

    $per_page = max(1, intval($a['per_page']));
    $columns  = max(1, min(6, intval($a['columns'])));
    $orderby  = in_array($a['orderby'], array('date','title','menu_order','rand')) ? $a['orderby'] : 'date';
    $order    = (strtoupper($a['order']) === 'ASC') ? 'ASC' : 'DESC';

    $paged = isset($_GET['wdpg']) ? max(1, intval($_GET['wdpg'])) : max( 1, get_query_var('paged'), get_query_var('page') );

    $q = new WP_Query( array(
        'post_type'      => 'wd_product',
        'posts_per_page' => $per_page,
        'orderby'        => $orderby,
        'order'          => $order,
        'paged'          => $paged,
    ) );

    ob_start();

    echo '<div class="wdss29-products-grid wdss29-cols-' . esc_attr($columns) . '">';

    if ( $q->have_posts() ) {
        while ( $q->have_posts() ) {
            $q->the_post();
            $pid   = get_the_ID();
            $title = get_the_title();
            $price = wdss29_get_product_price($pid);
            $perma = get_permalink($pid);
            $thumb = get_the_post_thumbnail( $pid, 'medium', array('class'=>'wdss29-product-thumb') );
            if ( ! $thumb ) $thumb = '<div class="wdss29-thumb-fallback"></div>';

            echo '<div class="wdss29-product-card">';
                echo '<a href="' . esc_url( $perma ) . '" class="wdss29-thumb-wrap">' . $thumb . '</a>';
                echo '<div class="wdss29-product-body">';
                    echo '<h3 class="wdss29-product-title"><a href="' . esc_url( $perma ) . '">'. esc_html($title) .'</a></h3>';
                    echo '<div class="wdss29-product-price">$' . number_format($price, 2) . '</div>';
                    echo wdss29_render_product_attributes( $pid );

                    echo '<div class="wdss29-card-actions" style="display:flex;gap:8px;flex-wrap:wrap;">';
                        echo '<a href="' . esc_url($perma) . '" class="wdss29-btn wdss29-btn-light" role="button">View Details</a>';
                        echo '<form method="post" class="wdss29-add-to-cart" style="margin:0;">';
                            wp_nonce_field('wdss29_cart');
                            echo '<input type="hidden" name="wdss29_cart_action" value="add">';
                            echo '<input type="hidden" name="product_id" value="' . intval($pid) . '">';
                            echo '<input type="hidden" name="qty" value="1">';
                            echo '<button type="submit" class="wdss29-btn">Add to Cart</button>';
                        echo '</form>';
                    echo '</div>';

                echo '</div>';
            echo '</div>';
        }
        wp_reset_postdata();
    } else {
        echo '<p>No products found.</p>';
    }

    echo '</div>';

    if ( $q->max_num_pages > 1 ) {
        $current  = isset($_GET['wdpg']) ? max(1, intval($_GET['wdpg'])) : max( 1, get_query_var('paged'), get_query_var('page') );
        $base_url = remove_query_arg( 'wdpg' );
        $links = paginate_links( array(
            'base'      => add_query_arg( 'wdpg', '%#%', $base_url ),
            'format'    => '',
            'current'   => $current,
            'total'     => $q->max_num_pages,
            'type'      => 'list',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'end_size'  => 1,
            'mid_size'  => 1,
        ) );
        if ( $links ) {
            echo '<nav class="wdss29-pagination">' . $links . '</nav>';
        }
    }

    return ob_get_clean();
}
add_shortcode('wd_products', 'wdss29_shortcode_products');

/** =====================================================================
 *  Admin log + probe
 * ===================================================================== */
/** [wd_orders_log] — admins only */
if ( ! function_exists('wdss29_shortcode_orders_log') ) {
    function wdss29_shortcode_orders_log() {
        if ( ! current_user_can('manage_options') ) return '';
        $buf = get_transient('wdss29_log');
        if ( ! is_array($buf) || empty($buf) ) return '<p>No log entries.</p>';
        $html = '<div class="wdss29-log" style="max-height:400px;overflow:auto;background:#111;color:#eee;padding:10px;border-radius:6px;">';
        foreach ( $buf as $ln ) {
            $html .= '<div style="font:12px/1.4 monospace;white-space:pre-wrap;">' . esc_html($ln) . '</div>';
        }
        $html .= '</div>';
        return $html;
    }
    add_shortcode('wd_orders_log', 'wdss29_shortcode_orders_log');
}

/** [wd_orders_probe] — verify tables + optional test insert */
if ( ! function_exists('wdss29_shortcode_orders_probe') ) {
    function wdss29_shortcode_orders_probe( $atts = array() ) {
        if ( ! current_user_can('manage_options') ) return '';
        global $wpdb;

        wdss29_maybe_install_or_upgrade_wd_orders_table();
        wdss29_maybe_install_or_upgrade_order_items_table();

        $orders_table = $wpdb->prefix . 'wd_orders';
        $items_table  = $wpdb->prefix . 'wd_order_items';

        $exists_orders = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $orders_table
        ) );

        $exists_items = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $items_table
        ) );

        ob_start();
        echo '<div class="wrap"><h3>WD Orders Probe</h3>';
        echo '<p><strong>Orders Table:</strong> ' . esc_html($orders_table) . ' — ' . ( $exists_orders ? 'Yes' : 'No' ) . '</p>';
        echo '<p><strong>Items Table:</strong> ' . esc_html($items_table) . ' — ' . ( $exists_items ? 'Yes' : 'No' ) . '</p>';

        if ( $exists_orders ) {
            $cols = wdss29_get_orders_table_columns();
            if ( $cols ) {
                echo '<h4>Orders Columns</h4><ul>';
                foreach ( array_keys($cols) as $c ) echo '<li>'. esc_html($c) .'</li>';
                echo '</ul>';
            }

            if ( isset($_GET['wdss_probe_insert']) ) {
                $data = array(
                    'customer_id'    => get_current_user_id(),
                    'customer_name'  => 'Probe User',
                    'customer_email' => wp_get_current_user()->user_email,
                    'total'          => 1.23,
                    'status'         => 'paid',
                    'payment_id'     => 'probe-' . wp_generate_password(8, false),
                    'tracking_id'    => 'TRACK-' . wp_generate_password(6, false),
                    'created_at'     => current_time('mysql', 1),
                );
                $types = array('%d','%s','%s','%f','%s','%s','%s','%s');

                // Only include meta if column exists
                if ( isset($cols['meta']) ) {
                    $data['meta'] = wp_json_encode(array('probe' => true));
                    $types[] = '%s';
                }

                $ok = $wpdb->insert( $orders_table, $data, $types );
                echo $ok !== false
                    ? '<p style="color:#2e7d32;">Inserted test row: ID '. intval($wpdb->insert_id) .'</p>'
                    : '<p style="color:#b71c1c;">Insert failed: '. esc_html($wpdb->last_error) .'</p>';
            }

            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$orders_table}");
            echo '<p><strong>Total orders:</strong> ' . $count . '</p>';
            echo '<p><a class="button button-primary" href="'. esc_url( add_query_arg('wdss_probe_insert','1') ) .'">Insert Test Row</a></p>';
        }

        if ( $exists_items ) {
            $cols = wdss29_get_order_items_table_columns();
            if ( $cols ) {
                echo '<h4>Items Columns</h4><ul>';
                foreach ( array_keys($cols) as $c ) echo '<li>'. esc_html($c) .'</li>';
                echo '</ul>';
            }
        }

        echo '</div>';
        return ob_get_clean();
    }
    add_shortcode('wd_orders_probe', 'wdss29_shortcode_orders_probe');
}
