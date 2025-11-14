<?php
/**
 * Quick diagnostic page to view WDSS logs
 */
if ( ! defined('ABSPATH') ) exit;

add_action('admin_menu', function() {
    add_submenu_page(
        'wd-orders',
        'Debug Logs',
        'Debug Logs',
        'manage_options',
        'wdss-debug-logs',
        'wdss29_render_debug_logs_page'
    );
});

function wdss29_render_debug_logs_page() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('Access denied');
    }
    
    ?>
    <div class="wrap">
        <h1>WD Store Suite - Debug Logs</h1>
        
        <h2>Transient Log (Last 200 entries, 2 hours)</h2>
        <?php
        $log = get_transient('wdss29_log');
        if ( is_array($log) && !empty($log) ) {
            echo '<div style="background:#f5f5f5; padding:15px; font-family:monospace; font-size:12px; max-height:600px; overflow-y:scroll;">';
            foreach ( array_reverse($log) as $entry ) {
                echo esc_html($entry) . "<br>\n";
            }
            echo '</div>';
        } else {
            echo '<p>No log entries found. Logs are stored for 2 hours.</p>';
        }
        ?>
        
        <h2>Recent Orders (Last 10)</h2>
        <?php
        global $wpdb;
        $table = $wpdb->prefix . 'wd_orders';
        $orders = $wpdb->get_results(
            "SELECT id, customer_email, total, status, payment_id, created_at, meta 
             FROM {$table} 
             ORDER BY id DESC 
             LIMIT 10",
            ARRAY_A
        );
        
        if ( $orders ) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>ID</th><th>Email</th><th>Total</th><th>Status</th><th>Payment ID</th><th>Created</th><th>Meta (excerpt)</th>';
            echo '</tr></thead><tbody>';
            
            foreach ( $orders as $order ) {
                $meta_excerpt = '';
                if ( !empty($order['meta']) ) {
                    $meta = json_decode($order['meta'], true);
                    if ( is_array($meta) ) {
                        $meta_excerpt = 'client_ref: ' . ($meta['client_reference_id'] ?? 'none');
                    }
                }
                
                echo '<tr>';
                echo '<td>' . esc_html($order['id']) . '</td>';
                echo '<td>' . esc_html($order['customer_email']) . '</td>';
                echo '<td>$' . number_format($order['total'], 2) . '</td>';
                echo '<td><strong>' . esc_html($order['status']) . '</strong></td>';
                echo '<td>' . esc_html($order['payment_id'] ?: '(none)') . '</td>';
                echo '<td>' . esc_html($order['created_at']) . '</td>';
                echo '<td style="font-size:11px; color:#666;">' . esc_html($meta_excerpt) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No orders found.</p>';
        }
        ?>
        
        <h2>Email Automation Status</h2>
        <?php
        // Check if email templates exist
        $templates = get_posts(array(
            'post_type' => 'wdss_email_template',
            'post_status' => 'publish',
            'numberposts' => -1
        ));
        
        // Check if rules exist
        $rules = get_option('wdss_email_rules_v1', array());
        $enabled_rules = array_filter($rules, function($r) { return !empty($r['enabled']); });
        $paid_rules = array_filter($rules, function($r) { 
            return !empty($r['enabled']) && ($r['trigger'] ?? '') === 'order.paid'; 
        });
        
        // Check if handler is hooked
        $handler_hooked = has_action('wdss_email_trigger', array('WDSS_Emailer', 'handle_trigger_event'));
        if ( ! $handler_hooked && class_exists('WDSS_Emailer') ) {
            $instance = WDSS_Emailer::instance();
            $handler_hooked = has_action('wdss_email_trigger');
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <tbody>
                <tr>
                    <th>Email Templates</th>
                    <td><?php echo count($templates); ?> template(s) found
                        <?php if ( empty($templates) ): ?>
                            <strong style="color:red;"> ⚠️ No templates! Create one in Email Templates.</strong>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Email Rules</th>
                    <td><?php echo count($rules); ?> total, <?php echo count($enabled_rules); ?> enabled
                        <?php if ( empty($rules) ): ?>
                            <strong style="color:red;"> ⚠️ No rules! Create templates with automation enabled.</strong>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Order Paid Rules</th>
                    <td><?php echo count($paid_rules); ?> rule(s) for "order.paid" trigger
                        <?php if ( empty($paid_rules) ): ?>
                            <strong style="color:red;"> ⚠️ No rules for "order.paid"! Enable automation on a template.</strong>
                        <?php else: ?>
                            <ul style="margin:5px 0 0 20px;">
                                <?php foreach($paid_rules as $idx => $r): ?>
                                    <li>
                                        <strong>Rule <?php echo $idx + 1; ?>:</strong> <?php echo esc_html($r['name'] ?? 'unnamed'); ?><br>
                                        &nbsp;&nbsp;Template ID: <?php echo esc_html($r['template_id'] ?? 0); ?><br>
                                        &nbsp;&nbsp;Conditions: <code><?php echo esc_html(is_array($r['conditions'] ?? '') ? implode(', ', $r['conditions']) : ($r['conditions'] ?? '(none)')); ?></code><br>
                                        &nbsp;&nbsp;Recipient: <?php echo esc_html($r['recipient'] ?? 'set'); ?><br>
                                        &nbsp;&nbsp;Delay: <?php echo esc_html($r['delay_minutes'] ?? 0); ?> minutes
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Handler Hooked</th>
                    <td><?php echo $handler_hooked ? '<span style="color:green;">✅ Yes</span>' : '<span style="color:red;">❌ No</span>'; ?></td>
                </tr>
                <tr>
                    <th>Emailer Class Loaded</th>
                    <td><?php echo class_exists('WDSS_Emailer') ? '<span style="color:green;">✅ Yes</span>' : '<span style="color:red;">❌ No</span>'; ?></td>
                </tr>
            </tbody>
        </table>
        
        <h2>Email Log (Last 50)</h2>
        <?php
        $email_log = get_option('wdss_email_log_v1', array());
        if ( is_array($email_log) && !empty($email_log) ) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>Time</th><th>Type</th><th>To</th><th>Event</th><th>Template</th><th>Rule</th>';
            echo '</tr></thead><tbody>';
            
            foreach ( array_slice(array_reverse($email_log), 0, 50) as $entry ) {
                $type = $entry['type'] ?? '';
                $color = '';
                if ( $type === 'sent' ) $color = 'color:green;';
                elseif ( $type === 'failed' ) $color = 'color:red;';
                elseif ( $type === 'no-rule' ) $color = 'color:orange;';
                elseif ( $type === 'received-event' ) $color = 'color:blue;';
                
                echo '<tr>';
                echo '<td>' . esc_html($entry['time'] ?? '') . '</td>';
                echo '<td><strong style="' . $color . '">' . esc_html($type) . '</strong></td>';
                echo '<td>' . esc_html($entry['to'] ?? '') . '</td>';
                echo '<td>' . esc_html($entry['event'] ?? '') . '</td>';
                echo '<td>' . esc_html($entry['tpl'] ?? '') . '</td>';
                echo '<td>' . esc_html($entry['rule'] ?? '') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p><strong style="color:orange;">No email log entries found.</strong> This means the email handler may not be receiving triggers.</p>';
        }
        ?>
        
        <p style="margin-top:20px;">
            <a href="<?php echo admin_url('admin.php?page=wdss-debug-logs'); ?>" class="button">Refresh</a>
        </p>
    </div>
    <?php
}

