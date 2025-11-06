<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * WD Store Suite — Admin Order Details
 * - Hidden submenu: admin.php?page=wd-order&order_id={id}
 * - Edit status, tracking_id; view line items; private admin notes.
 */

add_action('admin_menu', function () {
    // Hidden page (no visible menu item); linked from the orders list.
    add_submenu_page(
        null,
        'Order Details',
        'Order Details',
        'manage_options',
        'wd-order',
        'wdss29_admin_order_details_page'
    );
});

/* ==================== Utilities / Schema ==================== */

function wdss29_admin__table_exists($table){
    global $wpdb;
    return (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
        $table
    ) );
}

function wdss29_admin__columns($table){
    global $wpdb;
    $map = array();
    if ( ! wdss29_admin__table_exists($table) ) return $map;
    $rows = $wpdb->get_results("DESCRIBE {$table}", ARRAY_A);
    if ($rows) foreach ($rows as $r){ $map[$r['Field']] = true; }
    return $map;
}

/** Meta table for notes (admin-only). */
function wdss29_admin__maybe_install_order_meta_table(){
    global $wpdb;
    $meta = $wpdb->prefix . 'wd_order_meta';
    if ( wdss29_admin__table_exists($meta) ) return;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$meta}(
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL,
        meta_key VARCHAR(64) NOT NULL,
        meta_value LONGTEXT NULL,
        author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_order (order_id),
        KEY idx_key (meta_key)
    ) {$charset};";
    dbDelta($sql);
}

/** Items select list with schema-flex mapping. */
function wdss29_admin__items_select($alias='i'){
    global $wpdb;
    $items = $wpdb->prefix.'wd_order_items';
    $cols  = wdss29_admin__columns($items);

    $sel_pid = isset($cols['product_id']) ? "{$alias}.product_id" : ( isset($cols['pid']) ? "{$alias}.pid" : (isset($cols['post_id']) ? "{$alias}.post_id" : "NULL") ) . " AS product_id";
    $sel_qty = isset($cols['quantity']) ? "{$alias}.quantity" : ( isset($cols['qty']) ? "{$alias}.qty" : (isset($cols['amount']) ? "{$alias}.amount" : "1") ) . " AS quantity";
    $sel_add = isset($cols['addons']) ? "{$alias}.addons" : ( isset($cols['options']) ? "{$alias}.options" : (isset($cols['opts']) ? "{$alias}.opts" : (isset($cols['meta']) ? "{$alias}.meta" : "''")) ) . " AS addons";
    $sel_tot = isset($cols['line_total']) ? "{$alias}.line_total" : ( isset($cols['total']) ? "{$alias}.total" : (isset($cols['subtotal']) ? "{$alias}.subtotal" : (isset($cols['amount_total']) ? "{$alias}.amount_total" : (isset($cols['price']) ? "{$alias}.price" : "0"))) ) . " AS line_total";
    $sel_id  = isset($cols['id']) ? "{$alias}.id" : "0";
    return "{$sel_pid}, {$sel_qty}, {$sel_add}, {$sel_tot}, {$sel_id} AS id";
}

function wdss29_admin__format_addons($raw){
    $opts = array();
    if (is_array($raw)) $opts=$raw;
    elseif (is_string($raw) && $raw!==''){
        $j=json_decode($raw,true);
        if (is_array($j)) $opts=$j;
        else{
            if (strpos($raw,':')!==false){
                foreach (array_map('trim', explode(';',$raw)) as $p){
                    if($p==='') continue;
                    $kv=array_map('trim', explode(':',$p,2));
                    if(count($kv)===2) $opts[$kv[0]]=$kv[1];
                }
            }
        }
    }
    if (empty($opts)) return '—';
    $out=[];
    foreach($opts as $k=>$v){
        if($v===''||$v===null) continue;
        $label = ucfirst(str_replace(['_','-'],' ',$k));
        $out[] = "{$label}: {$v}";
    }
    return implode('; ', $out);
}

/* ==================== Actions ==================== */

function wdss29_admin__handle_order_details_actions(){
    if ( ! current_user_can('manage_options') ) return;
    if ( empty($_POST) ) return;
    if ( empty($_POST['_wpnonce']) || ! wp_verify_nonce($_POST['_wpnonce'], 'wdss29_order_edit') ) return;

    global $wpdb;
    $orders = $wpdb->prefix.'wd_orders';
    if ( ! wdss29_admin__table_exists($orders) ) return;

    $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
    if ( $order_id <= 0 ) return;

    $cols = wdss29_admin__columns($orders);

    if ( isset($_POST['action_type']) && $_POST['action_type']==='save_main' ) {
        $data = array(); $fmt = array();

        if ( isset($_POST['status']) ) {
            $status = sanitize_text_field($_POST['status']);
            if ( $status!=='' ) { $data['status']=$status; $fmt[]='%s'; }
        }
        if ( isset($_POST['tracking_id']) && isset($cols['tracking_id']) ) {
            $data['tracking_id'] = sanitize_text_field($_POST['tracking_id']);
            $fmt[] = '%s';
        }
        if ( ! empty($data) ) {
            $wpdb->update($orders, $data, array('id'=>$order_id), $fmt, array('%d'));
            add_settings_error('wdss29_order', 'saved', 'Order updated.', 'updated');
        }
    }

    if ( isset($_POST['action_type']) && $_POST['action_type']==='add_note' ) {
        wdss29_admin__maybe_install_order_meta_table();
        $meta = $wpdb->prefix.'wd_order_meta';
        $note = wp_kses_post( wp_unslash( $_POST['note'] ?? '' ) );
        if ( $note !== '' ) {
            $wpdb->insert($meta, array(
                'order_id'  => $order_id,
                'meta_key'  => 'note',
                'meta_value'=> $note,
                'author_id' => get_current_user_id(),
                'created_at'=> current_time('mysql', 1),
            ), array('%d','%s','%s','%d','%s'));
            add_settings_error('wdss29_order', 'note_added', 'Note added.', 'updated');
        }
    }

    if ( isset($_POST['action_type']) && $_POST['action_type']==='delete_note' ) {
        wdss29_admin__maybe_install_order_meta_table();
        $meta = $wpdb->prefix.'wd_order_meta';
        $nid  = isset($_POST['note_id']) ? (int) $_POST['note_id'] : 0;
        if ( $nid > 0 ) {
            $wpdb->delete($meta, array('id'=>$nid, 'order_id'=>$order_id), array('%d','%d'));
            add_settings_error('wdss29_order', 'note_deleted', 'Note deleted.', 'updated');
        }
    }
}

/* ==================== Page Renderer ==================== */

function wdss29_admin_order_details_page(){
    if ( ! current_user_can('manage_options') ) return;

    wdss29_admin__handle_order_details_actions();

    $order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
    if ( $order_id <= 0 ) {
        wp_safe_redirect( admin_url('admin.php?page=wd-orders') );
        exit;
    }

    global $wpdb;
    $orders = $wpdb->prefix.'wd_orders';
    $items  = $wpdb->prefix.'wd_order_items';

    if ( ! wdss29_admin__table_exists($orders) ) {
        wp_die('Orders table missing.');
    }

    $ocols = wdss29_admin__columns($orders);
    $sel_tracking = isset($ocols['tracking_id']) ? 'tracking_id' : "'' AS tracking_id";

    $order = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, customer_id, customer_name, customer_email, total, status, {$sel_tracking}, payment_id, created_at
         FROM {$orders}
         WHERE id=%d",
        $order_id
    ), ARRAY_A );

    if ( ! $order ) {
        wp_die('Order not found.');
    }

    $statuses = array('pending','paid','processing','shipped','completed','refunded','cancelled');

    // Items
    $products_str = '—'; $addons_str='—';
    $line_rows = array();
    if ( wdss29_admin__table_exists($items) ) {
        $sel = wdss29_admin__items_select('i');
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$sel}, p.post_title
                 FROM {$items} i
                 LEFT JOIN {$wpdb->posts} p ON p.ID = i.product_id
                 WHERE i.order_id = %d
                 ORDER BY id ASC",
                $order_id
            ),
            ARRAY_A
        );
        if ($rows){
            $parts_prod = array();
            $parts_add  = array();
            foreach($rows as $r){
                $qty = (isset($r['quantity']) && is_numeric($r['quantity'])) ? (int)$r['quantity'] : 1;
                $title = $r['post_title'] ?: 'Unknown Product';
                $parts_prod[] = esc_html($title) . ' × ' . $qty;

                $al = wdss29_admin__format_addons($r['addons'] ?? '');
                if ($al !== '—') $parts_add[] = esc_html($al) . ' × ' . $qty;

                $line_rows[] = array(
                    'title' => $title,
                    'qty'   => $qty,
                    'addons'=> $al,
                    'total' => is_numeric($r['line_total']) ? (float)$r['line_total'] : 0.0,
                );
            }
            if ($parts_prod) $products_str = implode(', ', $parts_prod);
            if ($parts_add)  $addons_str   = implode(' | ', $parts_add);
        }
    }

    // Notes
    wdss29_admin__maybe_install_order_meta_table();
    $meta = $wpdb->prefix.'wd_order_meta';
    $notes = array();
    if ( wdss29_admin__table_exists($meta) ) {
        $notes = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, meta_value, author_id, created_at
                 FROM {$meta}
                 WHERE order_id=%d AND meta_key='note'
                 ORDER BY id DESC",
                $order_id
            ),
            ARRAY_A
        );
    }

    echo '<div class="wrap">';
    echo '<h1>Order #'.intval($order['id']).' &mdash; Details</h1>';
    echo '<a href="'. esc_url( admin_url('admin.php?page=wd-orders') ) .'" class="button" style="margin-top:8px;">&larr; Back to WD Orders</a>';

    settings_errors('wdss29_order');

    echo '<hr style="margin:16px 0;">';

    echo '<div class="postbox" style="padding:12px;">';
    echo '<h2>Summary</h2>';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th>Customer</th><td>'. esc_html($order['customer_name'] ?: '—') .' &lt;'. esc_html($order['customer_email'] ?: '—') .'&gt;</td></tr>';
    echo '<tr><th>Date</th><td>'. esc_html( mysql2date( get_option('date_format').' '.get_option('time_format'), $order['created_at'], true) ) .'</td></tr>';
    echo '<tr><th>Total</th><td>$'. number_format( (float)$order['total'], 2 ) .'</td></tr>';
    echo '<tr><th>Payment ID</th><td>'. esc_html($order['payment_id'] ?: '—') .'</td></tr>';
    echo '</tbody></table>';

    echo '<form method="post" style="margin-top:10px;">';
    wp_nonce_field('wdss29_order_edit');
    echo '<input type="hidden" name="order_id" value="'. esc_attr($order_id) .'">';
    echo '<input type="hidden" name="action_type" value="save_main">';

    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row"><label for="wdss29_status">Status</label></th><td>';
    echo '<select name="status" id="wdss29_status">';
    foreach ( $statuses as $st ){
        $sel = ( $order['status'] === $st ) ? 'selected' : '';
        echo '<option value="'. esc_attr($st) .'" '. $sel .'>'. esc_html(ucfirst($st)) .'</option>';
    }
    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="wdss29_track">Tracking ID</label></th><td>';
    if ( isset($ocols['tracking_id']) ){
        echo '<input type="text" name="tracking_id" id="wdss29_track" value="'. esc_attr($order['tracking_id']) .'" class="regular-text">';
    } else {
        echo '<em>Tracking column not present in table.</em>';
    }
    echo '</td></tr>';

    echo '</tbody></table>';

    echo '<p><button class="button button-primary">Save Changes</button></p>';
    echo '</form>';
    echo '</div>';

    echo '<div class="postbox" style="padding:12px;">';
    echo '<h2>Items</h2>';
    if ( empty($line_rows) ){
        echo '<p>No items recorded for this order.</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Product</th><th>Qty</th><th>Add-ons</th><th style="text-align:right;">Line Total</th>';
        echo '</tr></thead><tbody>';
        foreach($line_rows as $lr){
            echo '<tr>';
            echo '<td>'. esc_html($lr['title']) .'</td>';
            echo '<td>'. intval($lr['qty']) .'</td>';
            echo '<td>'. esc_html($lr['addons']) .'</td>';
            echo '<td style="text-align:right;">$'. number_format((float)$lr['total'],2) .'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    echo '<div class="postbox" style="padding:12px;">';
    echo '<h2>Admin Notes (private)</h2>';
    echo '<form method="post" style="margin:10px 0;">';
    wp_nonce_field('wdss29_order_edit');
    echo '<input type="hidden" name="order_id" value="'. esc_attr($order_id) .'">';
    echo '<input type="hidden" name="action_type" value="add_note">';
    echo '<textarea name="note" rows="4" class="large-text" placeholder="Add a private note for admins..."></textarea>';
    echo '<p><button class="button button-primary">Add Note</button></p>';
    echo '</form>';

    if ( empty($notes) ) {
        echo '<p>No notes yet.</p>';
    } else {
        echo '<ul>';
        foreach($notes as $n){
            $author = get_user_by('id', (int)$n['author_id']);
            $who = $author ? $author->display_name : 'Admin';
            $when = mysql2date( get_option('date_format').' '.get_option('time_format'), $n['created_at'], true );
            echo '<li style="margin-bottom:8px;">';
            echo '<div style="font-weight:600;">'. esc_html($who) .' <span style="color:#666;font-weight:400;">('. esc_html($when) .')</span></div>';
            echo '<div>'. wp_kses_post( wpautop($n['meta_value']) ) .'</div>';
            echo '<form method="post" style="margin-top:6px;">';
            wp_nonce_field('wdss29_order_edit');
            echo '<input type="hidden" name="order_id" value="'. esc_attr($order_id) .'">';
            echo '<input type="hidden" name="action_type" value="delete_note">';
            echo '<input type="hidden" name="note_id" value="'. (int)$n['id'] .'">';
            echo '<button class="button button-link-delete" onclick="return confirm(\'Delete this note?\')">Delete</button>';
            echo '</form>';
            echo '</li>';
        }
        echo '</ul>';
    }
    echo '</div>';

    echo '</div>';
}
