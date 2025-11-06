<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * WD Store Suite — Admin Orders (Compact list + editable detail tracking)
 *
 * - List view shows: checkbox, Order #, Date, Customer, Email, Total, Status, Actions.
 * - Detail view allows editing Status and Tracking ID and shows items.
 * - Bulk actions: status change + delete.
 * - Safe creation of tracking_id column if missing.
 * - PHP 8.1+ hardened (null-safe casts).
 */

add_action('admin_menu', function () {
    add_menu_page(
        'WD Orders',
        'WD Orders',
        'manage_options',
        'wd-orders',
        'wdss29_admin_orders_page',
        'dashicons-cart',
        56
    );
});

/* ========= Utilities ========= */

function wdss29_admin_table_exists( $table ) {
    global $wpdb;
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
        $table
    ) );
    return (bool) $exists;
}

function wdss29_admin_get_columns_map( $table ) {
    global $wpdb;
    $cols = array();
    if ( ! wdss29_admin_table_exists($table) ) return $cols;
    $desc = $wpdb->get_results( "DESCRIBE {$table}", ARRAY_A );
    if ( is_array($desc) ) {
        foreach ( $desc as $col ) {
            if ( isset($col['Field']) ) $cols[ $col['Field'] ] = true;
        }
    }
    return $cols;
}

/** Ensure tracking_id exists on wp_wd_orders (VARCHAR(191)) */
function wdss29_admin_maybe_add_tracking_column() {
    global $wpdb;
    $orders_table = $wpdb->prefix . 'wd_orders';
    if ( ! wdss29_admin_table_exists($orders_table) ) return;
    $cols = wdss29_admin_get_columns_map($orders_table);
    if ( isset($cols['tracking_id']) ) return;

    // Add column and a simple index; ignore failures silently
    $wpdb->query("ALTER TABLE {$orders_table} ADD COLUMN tracking_id VARCHAR(191) NOT NULL DEFAULT ''");
    $wpdb->query("CREATE INDEX idx_tracking_id ON {$orders_table} (tracking_id)");
}

/* ========= Action handling ========= */

function wdss29_admin_handle_actions() {
    if ( ! current_user_can('manage_options') ) return;
    if ( empty($_POST) ) return;

    $orders_table = $GLOBALS['wpdb']->prefix . 'wd_orders';
    if ( ! wdss29_admin_table_exists($orders_table) ) return;

    // Determine which nonce to verify (bulk, row, or detail forms)
    $bulk_nonce_ok   = ( ! empty($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'wdss29_admin_orders') );
    $row_nonce_ok    = ( ! empty($_POST['_wpnonce_row']) && wp_verify_nonce($_POST['_wpnonce_row'], 'wdss29_admin_row') );
    $detail_nonce_ok = ( ! empty($_POST['_wpnonce_detail']) && wp_verify_nonce($_POST['_wpnonce_detail'], 'wdss29_admin_detail') );

    global $wpdb;

    // -------- Detail view save --------
    if ( $detail_nonce_ok && isset($_POST['detail_action']) && $_POST['detail_action'] === 'save_detail' ) {
        $oid = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        if ( $oid > 0 ) {
            $status      = sanitize_text_field( (string) ($_POST['status'] ?? '') );
            $tracking_id = sanitize_text_field( (string) ($_POST['tracking_id'] ?? '') );

            // Make sure tracking column exists before updating it
            wdss29_admin_maybe_add_tracking_column();
            $cols = wdss29_admin_get_columns_map($orders_table);

            $data = array(); $fmt = array();
            if ( $status !== '' ) { $data['status'] = $status; $fmt[] = '%s'; }
            if ( isset($cols['tracking_id']) ) { $data['tracking_id'] = $tracking_id; $fmt[] = '%s'; }

            if ( ! empty($data) ) {
                $wpdb->update( $orders_table, $data, array('id'=>$oid), $fmt, array('%d') );
                add_settings_error('wdss29_admin', 'wdss29_detail_saved', 'Order details saved.', 'updated');
            }
        }
        return; // We let the page render again with notices
    }

    // -------- Bulk actions (outer list form) --------
    if ( $bulk_nonce_ok && isset($_POST['bulk_action']) && ! empty($_POST['order_ids']) && is_array($_POST['order_ids']) ) {
        $ids = array_map('intval', $_POST['order_ids']);
        $ids = array_values( array_filter($ids, fn($v)=>$v>0) );

        if ( ! empty($ids) ) {
            $action = (string) ($_POST['bulk_action'] ?? '');
            if ( $action === 'delete' ) {
                $items_table = $wpdb->prefix . 'wd_order_items';
                if ( wdss29_admin_table_exists($items_table) ) {
                    $in = '(' . implode(',', array_fill(0, count($ids), '%d')) . ')';
                    $wpdb->query( $wpdb->prepare("DELETE FROM {$items_table} WHERE order_id IN {$in}", ...$ids) );
                }
                $in = '(' . implode(',', array_fill(0, count($ids), '%d')) . ')';
                $wpdb->query( $wpdb->prepare("DELETE FROM {$orders_table} WHERE id IN {$in}", ...$ids) );
                add_settings_error('wdss29_admin', 'wdss29_deleted', 'Selected orders deleted.', 'updated');

            } elseif ( $action === 'status' ) {
                $new_status = sanitize_text_field( (string) ($_POST['bulk_status'] ?? '') );
                if ( $new_status !== '' ) {
                    $in = '(' . implode(',', array_fill(0, count($ids), '%d')) . ')';
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$orders_table} SET status=%s WHERE id IN {$in}",
                        array_merge( array($new_status), $ids )
                    ) );
                    add_settings_error('wdss29_admin', 'wdss29_bulk_status', 'Status updated for selected orders.', 'updated');
                } else {
                    add_settings_error('wdss29_admin', 'wdss29_bulk_status_missing', 'Please select a valid status.', 'error');
                }
            }
        }
    }

    // -------- Row actions (hidden forms in list) --------
    if ( $row_nonce_ok && isset($_POST['row_action']) ) {
        $oid = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        if ( $oid <= 0 ) return;

        $row_action = (string) ($_POST['row_action'] ?? '');

        if ( $row_action === 'save_row' ) {
            $status = sanitize_text_field( (string) ($_POST['status'] ?? '') );
            if ( $status !== '' ) {
                $wpdb->update( $orders_table, array('status' => $status), array('id' => $oid), array('%s'), array('%d') );
                add_settings_error('wdss29_admin', 'wdss29_row_saved', 'Order updated.', 'updated');
            }
        }

        if ( $row_action === 'delete_row' ) {
            $items_table = $wpdb->prefix . 'wd_order_items';
            if ( wdss29_admin_table_exists($items_table) ) {
                $wpdb->delete( $items_table, array('order_id'=>$oid), array('%d') );
            }
            $wpdb->delete( $orders_table, array('id'=>$oid), array('%d') );
            add_settings_error('wdss29_admin', 'wdss29_row_deleted', 'Order deleted.', 'updated');
        }
    }
}

/* ========= Page ========= */

function wdss29_admin_orders_page() {
    if ( ! current_user_can('manage_options') ) return;

    // Detail view (editable tracking + status + items)
    if ( isset($_GET['view']) && ($oid = (int) $_GET['view']) > 0 ) {
        wdss29_admin_handle_actions();
        wdss29_admin_render_order_detail($oid);
        return;
    }

    wdss29_admin_handle_actions();

    global $wpdb;
    $orders_table = $wpdb->prefix . 'wd_orders';

    echo '<div class="wrap wdss29-orders-wrap">';
    echo '<h1 class="wp-heading-inline">WD Orders</h1>';
    settings_errors('wdss29_admin');

    if ( ! wdss29_admin_table_exists($orders_table) ) {
        echo '<p style="color:#b00;margin-top:12px;">Orders table not found: <code>' . esc_html($orders_table) . '</code></p>';
        echo '</div>';
        return;
    }

    $orders = $wpdb->get_results("
        SELECT id, customer_name, customer_email, total, status, created_at
        FROM {$orders_table}
        ORDER BY id DESC
        LIMIT 200
    ", ARRAY_A);
    if ( ! is_array($orders) ) $orders = array();

    $statuses = array('pending','paid','processing','shipped','completed','refunded','cancelled');

    // --- BULK FORM (wraps toolbar + table + checkboxes only) ---
    echo '<form method="post" id="wdss29-bulk-form" style="margin-top:12px;">';
    wp_nonce_field('wdss29_admin_orders');

    echo '<div class="wdss29-toolbar" style="display:flex;gap:10px;align-items:center;margin:10px 0 14px;flex-wrap:wrap;">';
    echo '<select name="bulk_action">';
    echo '  <option value="">Bulk actions</option>';
    echo '  <option value="status">Change Status</option>';
    echo '  <option value="delete">Delete</option>';
    echo '</select>';

    echo '<select name="bulk_status">';
    echo '  <option value="">— Select status —</option>';
    foreach ($statuses as $st) {
        echo '<option value="'. esc_attr($st) .'">'. esc_html(ucfirst($st)) .'</option>';
    }
    echo '</select>';

    echo '<button type="submit" class="button action">Apply</button>';
    echo '</div>';

    echo '<div class="wdss29-table-wrap" style="overflow:auto;">';
    echo '<table class="wp-list-table widefat fixed striped wdss29-orders-table">';
    echo '<thead><tr>';
    echo '<td class="manage-column column-cb check-column"><input type="checkbox" onclick="jQuery(\'.wdss29-cb\').prop(\'checked\', this.checked);" /></td>';
    echo '<th class="col-narrow">Order #</th>';
    echo '<th class="col-date">Date</th>';
    echo '<th class="col-customer">Customer</th>';
    echo '<th class="col-email">Email</th>';
    echo '<th class="col-total" style="text-align:right;">Total</th>';
    echo '<th class="col-status">Status</th>';
    echo '<th class="col-actions">Actions</th>';
    echo '</tr></thead>';

    echo '<tbody>';

    // collect hidden per-row forms to print after the table (avoid nested forms)
    $row_forms = array();

    if ( empty($orders) ) {
        echo '<tr><td colspan="8">No orders found.</td></tr>';
    } else {
        foreach ($orders as $o) {
            $oid   = (int) ($o['id'] ?? 0);
            $total = is_numeric($o['total'] ?? null) ? (float) $o['total'] : 0.0;

            // Date (only if created_at is a non-empty string)
            $created_raw = (string) ($o['created_at'] ?? '');
            if ($created_raw !== '') {
                $date_val = mysql2date( get_option('date_format') . ' ' . get_option('time_format'), $created_raw, true );
                $date  = esc_html( (string) $date_val );
            } else {
                $date = '';
            }

            // Hidden SAVE/DELETE forms
            ob_start();
            echo '<form method="post" id="wdss29-row-'. esc_attr($oid) .'">';
            wp_nonce_field('wdss29_admin_row', '_wpnonce_row');
            echo '<input type="hidden" name="row_action" value="save_row">';
            echo '<input type="hidden" name="order_id" value="'. esc_attr($oid) .'">';
            echo '</form>';

            echo '<form method="post" id="wdss29-del-'. esc_attr($oid) .'">';
            wp_nonce_field('wdss29_admin_row', '_wpnonce_row');
            echo '<input type="hidden" name="row_action" value="delete_row">';
            echo '<input type="hidden" name="order_id" value="'. esc_attr($oid) .'">';
            echo '</form>';
            $row_forms[] = ob_get_clean();

            // Row display (compact)
            echo '<tr>';
            echo '<th scope="row" class="check-column"><input type="checkbox" class="wdss29-cb" name="order_ids[]" value="'. esc_attr($oid) .'"></th>';
            echo '<td>#' . $oid . '</td>';
            echo '<td>' . $date . '</td>';
            echo '<td>' . esc_html( (string) ($o['customer_name'] ?? '') ?: '—' ) . '</td>';
            echo '<td>' . esc_html( (string) ($o['customer_email'] ?? '') ?: '—' ) . '</td>';
            echo '<td style="text-align:right;">$' . number_format($total, 2) . '</td>';

            $cur_status = (string) ($o['status'] ?? '');
            echo '<td>';
            echo '<select name="status" form="wdss29-row-'. esc_attr($oid) .'">';
            foreach (array('pending','paid','processing','shipped','completed','refunded','cancelled') as $st) {
                $sel = ( $cur_status === $st ) ? 'selected' : '';
                echo '<option value="'. esc_attr($st) .'" '. $sel .'>'. esc_html(ucfirst($st)) .'</option>';
            }
            echo '</select>';
            echo '</td>';

            echo '<td class="wdss29-actions-cell" style="white-space:nowrap;">';
            echo '<a class="button button-secondary" href="'. esc_url( admin_url('admin.php?page=wd-orders&view=' . $oid) ) .'">View</a> ';
            echo '<button type="submit" class="button button-primary" form="wdss29-row-'. esc_attr($oid) .'">Save</button> ';
            echo '<button type="submit" class="button button-link-delete" form="wdss29-del-'. esc_attr($oid) .'" onclick="return confirm(\'Delete order #'. esc_js($oid) .'?\');">Delete</button>';
            echo '</td>';

            echo '</tr>';
        }
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>'; // .wdss29-table-wrap

    echo '</form>'; // end bulk form

    // print the hidden per-row forms AFTER the bulk form to avoid nesting
    if ( ! empty($row_forms) ) {
        echo implode('', $row_forms);
    }

    echo '</div>';
}

/**
 * Per-order detail view (editable Status + Tracking ID, and items list)
 */
function wdss29_admin_render_order_detail( $oid ) {
    if ( ! current_user_can('manage_options') ) return;
    global $wpdb;

    $orders_table = $wpdb->prefix . 'wd_orders';
    $items_table  = $wpdb->prefix . 'wd_order_items';

    echo '<div class="wrap"><h1>Order #'. esc_html($oid) .'</h1>';
    echo '<p><a class="button" href="'. esc_url( admin_url('admin.php?page=wd-orders') ) .'">&larr; Back to orders</a></p>';

    settings_errors('wdss29_admin');

    if ( ! wdss29_admin_table_exists($orders_table) ) {
        echo '<p>Orders table not found.</p></div>';
        return;
    }

    // Make sure tracking column exists so the form can save it
    wdss29_admin_maybe_add_tracking_column();
    $order_cols = wdss29_admin_get_columns_map($orders_table);

    $sel_tracking = isset($order_cols['tracking_id']) ? 'tracking_id' : "'' AS tracking_id";
    $sel_payment  = isset($order_cols['payment_id']) ? 'payment_id' : "'' AS payment_id";

    $order = $wpdb->get_row(
        $wpdb->prepare("SELECT id, customer_name, customer_email, total, status, {$sel_tracking}, {$sel_payment}, created_at FROM {$orders_table} WHERE id=%d", $oid),
        ARRAY_A
    );

    if ( ! $order ) {
        echo '<p>Order not found.</p></div>';
        return;
    }

    // Editable detail form: Status + Tracking ID
    $statuses = array('pending','paid','processing','shipped','completed','refunded','cancelled');

    echo '<form method="post" style="margin-bottom:16px;">';
    wp_nonce_field('wdss29_admin_detail', '_wpnonce_detail');
    echo '<input type="hidden" name="detail_action" value="save_detail">';
    echo '<input type="hidden" name="order_id" value="'. esc_attr($oid) .'">';

    echo '<table class="widefat striped" style="max-width:1000px">';
    echo '<tbody>';

    $customer_name  = (string) ($order['customer_name'] ?? '');
    $customer_email = (string) ($order['customer_email'] ?? '');
    echo '<tr><th style="width:180px">Customer</th><td>'. esc_html( $customer_name ?: '—' ) .' &lt;'. esc_html( $customer_email ?: '—' ) .'&gt;</td></tr>';

    $created_raw = (string) ($order['created_at'] ?? '');
    $date = $created_raw ? mysql2date( get_option('date_format').' '.get_option('time_format'), $created_raw, true ) : '';
    echo '<tr><th>Date</th><td>'. esc_html( (string) $date ) .'</td></tr>';

    // Status (editable)
    echo '<tr><th>Status</th><td>';
    echo '<select name="status">';
    $cur_status = (string) ($order['status'] ?? '');
    foreach ($statuses as $st) {
        $sel = ($cur_status === $st) ? 'selected' : '';
        echo '<option value="'. esc_attr($st) .'" '. $sel .'>'. esc_html(ucfirst($st)) .'</option>';
    }
    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th>Total</th><td>$'. number_format( (float) ($order['total'] ?? 0), 2 ) .'</td></tr>';

    // Tracking ID (editable if column present; the column is ensured above)
    $tracking_val = (string) ($order['tracking_id'] ?? '');
    echo '<tr><th>Tracking ID</th><td>';
    if ( isset($order_cols['tracking_id']) ) {
        echo '<input type="text" name="tracking_id" value="'. esc_attr($tracking_val) .'" style="min-width:260px;">';
    } else {
        echo '—';
    }
    echo '</td></tr>';

    // Payment ID (read-only)
    if ( isset($order_cols['payment_id']) ) {
        echo '<tr><th>Payment ID</th><td>'. esc_html( (string) ($order['payment_id'] ?? '') ?: '—' ) .'</td></tr>';
    }

    echo '</tbody>';
    echo '</table>';

    echo '<p><button type="submit" class="button button-primary">Save Changes</button></p>';
    echo '</form>';

    // Items (flexible select)
    $items = array();
    if ( wdss29_admin_table_exists($items_table) ) {
        $cols = wdss29_admin_get_columns_map( $items_table );
        $sel_pid = isset($cols['product_id']) ? 'i.product_id' : ( isset($cols['pid']) ? 'i.pid' : ( isset($cols['post_id']) ? 'i.post_id' : 'NULL' ) );
        $sel_qty = isset($cols['quantity']) ? 'i.quantity' : ( isset($cols['qty']) ? 'i.qty' : ( isset($cols['amount']) ? 'i.amount' : '1' ) );
        $sel_add = isset($cols['addons']) ? 'i.addons' : ( isset($cols['options']) ? 'i.options' : ( isset($cols['opts']) ? 'i.opts' : ( isset($cols['meta']) ? 'i.meta' : "''" ) ) );
        $sel_tot = isset($cols['line_total']) ? 'i.line_total' : ( isset($cols['total']) ? 'i.total' : ( isset($cols['subtotal']) ? 'i.subtotal' : ( isset($cols['amount_total']) ? 'i.amount_total' : ( isset($cols['price']) ? 'i.price' : '0' ) ) ) );

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT {$sel_pid} AS product_id, {$sel_qty} AS quantity, {$sel_add} AS addons, {$sel_tot} AS line_total, p.post_title
                FROM {$items_table} i
                LEFT JOIN {$wpdb->posts} p ON p.ID = {$sel_pid}
                WHERE i.order_id = %d
                ORDER BY i.id ASC
                ",
                $oid
            ),
            ARRAY_A
        );
        if ( ! is_array($items) ) $items = array();
    }

    echo '<h2 style="margin-top:20px;">Items</h2>';
    if ( ! empty($items) ) {
        echo '<table class="widefat striped" style="max-width:1000px">';
        echo '<thead><tr><th>Product</th><th style="width:80px;">Qty</th><th>Add-ons</th><th style="width:120px;text-align:right;">Line Total</th></tr></thead><tbody>';
        foreach ($items as $it) {
            $title = (string) ($it['post_title'] ?? '');
            if ($title === '') $title = 'Unknown Product';
            $qty = is_numeric($it['quantity'] ?? null) ? (int) $it['quantity'] : 1;

            // compact addons display
            $addons_label = '';
            $raw = $it['addons'] ?? '';
            if ( is_string($raw) && $raw !== '' ) {
                $decoded = json_decode($raw, true);
                if ( is_array($decoded) ) {
                    $parts = array();
                    foreach ($decoded as $k=>$v) {
                        if ($v === '' || $v === null) continue;
                        $label = ucfirst( str_replace(array('_','-'), ' ', (string) $k) );
                        $parts[] = $label . ': ' . (string) $v;
                    }
                    $addons_label = implode('; ', $parts);
                } else {
                    $addons_label = $raw;
                }
            }

            $lt = is_numeric($it['line_total'] ?? null) ? (float) $it['line_total'] : 0.0;
            echo '<tr>';
            echo '<td>'. esc_html($title) .'</td>';
            echo '<td>'. $qty .'</td>';
            echo '<td>'. esc_html($addons_label ?: '—') .'</td>';
            echo '<td style="text-align:right;">$'. number_format($lt, 2) .'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>No line items were recorded for this order.</p>';
    }

    echo '</div>';
}

/* Back-compat shim */
if ( ! function_exists('wdss29_admin_render_orders_list') ) {
    function wdss29_admin_render_orders_list() {
        wdss29_admin_orders_page();
    }
}

/* ========= Admin table CSS (compact) ========= */
add_action('admin_head', function () {
    if ( empty($_GET['page']) || (string) $_GET['page'] !== 'wd-orders' ) return;
    ?>
    <style>
      .wdss29-orders-wrap .wdss29-orders-table th,
      .wdss29-orders-wrap .wdss29-orders-table td { vertical-align: top; }
      .wdss29-orders-wrap .wdss29-orders-table th.col-actions,
      .wdss29-orders-wrap .wdss29-orders-table td.wdss29-actions-cell { white-space: nowrap; }
      @media (max-width: 1200px) {
        .wdss29-orders-wrap .wdss29-table-wrap { overflow-x: auto; }
        .wdss29-orders-wrap .wdss29-orders-table th,
        .wdss29-orders-wrap .wdss29-orders-table td { white-space: nowrap; }
      }
    </style>
    <?php
});
