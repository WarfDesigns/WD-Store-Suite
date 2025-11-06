<?php
/**
 * WD Store Suite — Email Diagnostics (Rules, Logs, Live Tests)
 * Author: Warf Designs LLC
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WDSS29_Email_Diagnostics' ) ) :

class WDSS29_Email_Diagnostics {

    const PAGE_SLUG = 'wdss-email-diagnostics';

    public static function instance() { static $i=null; if(!$i){$i=new self;} return $i; }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'register_page' ) );
        add_action( 'admin_post_wdss_email_diag_send_test', array( $this, 'handle_send_test' ) );
        add_action( 'admin_post_wdss_email_diag_fire_event', array( $this, 'handle_fire_event' ) );
    }

    public function register_page() {
        // Try to attach under WD Store Suite parent; fallback to Settings
        $parent = 'options-general.php';
        global $menu;
        if ( is_array( $menu ) ) {
            foreach ( $menu as $m ) {
                $label = strip_tags( $m[0] ?? '' );
                $slug  = $m[2] ?? '';
                if ( stripos( $label, 'WD Store Suite' ) !== false || stripos( $slug, 'wd-' ) !== false ) {
                    $parent = $slug; break;
                }
            }
        }

        add_submenu_page(
            $parent,
            __( 'Email Diagnostics', 'wdss' ),
            __( 'Email Diagnostics', 'wdss' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render' )
        );
    }

    public function render() {
        if ( ! current_user_can('manage_options') ) return;

        $rules = get_option( 'wdss_email_rules_v1', array() );
        $log   = get_option( 'wdss_email_log_v1', array() );
        $idem  = get_option( 'wdss_email_idem_v1', array() );

        $templates = get_posts( array(
            'post_type'   => 'wdss_email_template',
            'numberposts' => -1,
            'post_status' => 'any',
            'orderby'     => 'title',
            'order'       => 'ASC'
        ) );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('WDSS Email Diagnostics','wdss'); ?></h1>

            <h2 style="margin-top:24px;"><?php esc_html_e('1) Quick Send Test','wdss'); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="max-width:720px">
                <input type="hidden" name="action" value="wdss_email_diag_send_test">
                <?php wp_nonce_field('wdss_email_diag'); ?>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Template','wdss'); ?></th>
                        <td>
                            <select name="tpl_id" required>
                                <option value=""><?php esc_html_e('— Select —','wdss'); ?></option>
                                <?php foreach( $templates as $tpl ): ?>
                                    <option value="<?php echo esc_attr($tpl->ID); ?>"><?php echo esc_html($tpl->post_title.' (#'.$tpl->ID.')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Send To (email)','wdss'); ?></th>
                        <td><input type="email" name="to" class="regular-text" value="<?php echo esc_attr( get_option('admin_email') ); ?>" required></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Sample Payload (JSON)','wdss'); ?></th>
                        <td>
<textarea name="payload" rows="6" class="large-text">{
  "order_id": 9999,
  "customer_email": "<?php echo esc_attr( get_option('admin_email') ); ?>",
  "customer_name": "Diagnostics User",
  "order_total": 123.45,
  "event": "manual"
}</textarea>
                        </td>
                    </tr>
                </table>
                <p><button class="button button-primary"><?php esc_html_e('Send Test Now','wdss'); ?></button></p>
            </form>

            <h2 style="margin-top:24px;"><?php esc_html_e('2) Fire Automation Event','wdss'); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="max-width:720px">
                <input type="hidden" name="action" value="wdss_email_diag_fire_event">
                <?php wp_nonce_field('wdss_email_diag'); ?>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Event Key','wdss'); ?></th>
                        <td>
                            <select name="event" required>
                                <option value="order.created">order.created</option>
                                <option value="order.paid" selected>order.paid</option>
                                <option value="order.status_changed">order.status_changed</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Order ID (object_id)','wdss'); ?></th>
                        <td><input type="number" name="order_id" class="small-text" value="9999"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Payload (JSON)','wdss'); ?></th>
                        <td>
<textarea name="payload" rows="6" class="large-text">{
  "order_id": 9999,
  "customer_email": "<?php echo esc_attr( get_option('admin_email') ); ?>",
  "customer_name": "Diagnostics User",
  "order_total": 123.45,
  "order_status": "paid"
}</textarea>
                        </td>
                    </tr>
                </table>
                <p><button class="button"><?php esc_html_e('Fire Event','wdss'); ?></button></p>
                <p class="description"><?php esc_html_e('This calls the same bus your rules listen to (wdss_email_trigger). If rules and recipients are correct, an email should send immediately when delay=0.','wdss'); ?></p>
            </form>

            <h2 style="margin-top:24px;"><?php esc_html_e('3) Current Rules','wdss'); ?></h2>
            <p class="description"><?php esc_html_e('These are the saved automation rules. Template-driven rules are prefixed with TPL:.','wdss'); ?></p>
            <table class="widefat striped">
                <thead><tr>
                    <th>#</th><th><?php esc_html_e('Enabled','wdss'); ?></th>
                    <th><?php esc_html_e('Name','wdss'); ?></th>
                    <th><?php esc_html_e('Trigger','wdss'); ?></th>
                    <th><?php esc_html_e('Delay','wdss'); ?></th>
                    <th><?php esc_html_e('Template','wdss'); ?></th>
                    <th><?php esc_html_e('Recipient','wdss'); ?></th>
                    <th><?php esc_html_e('Conditions','wdss'); ?></th>
                </tr></thead>
                <tbody>
                <?php if (empty($rules)): ?>
                    <tr><td colspan="8"><?php esc_html_e('No rules saved. Edit your Email Templates and Save to auto-generate rules.','wdss'); ?></td></tr>
                <?php else: foreach ($rules as $i=>$r): ?>
                    <tr>
                        <td><?php echo intval($i)+1; ?></td>
                        <td><?php echo !empty($r['enabled']) ? '✓' : '—'; ?></td>
                        <td><?php echo esc_html($r['name'] ?? ''); ?></td>
                        <td><?php echo esc_html($r['trigger'] ?? ''); ?></td>
                        <td><?php echo esc_html($r['delay_minutes'] ?? 0); ?></td>
                        <td><?php echo esc_html($r['template_id'] ?? 0); ?></td>
                        <td><?php echo esc_html($r['recipient'] ?? 'set'); ?></td>
                        <td><?php
                            $c = $r['conditions'] ?? '';
                            echo is_array( $c ) ? esc_html( implode(',', $c) ) : esc_html( (string) $c );
                        ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <h2 style="margin-top:24px;"><?php esc_html_e('4) Recent Email Log','wdss'); ?></h2>
            <p class="description"><?php esc_html_e('Shows sent / scheduled / failed / skip-duplicate entries (last 500).','wdss'); ?></p>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Time (UTC)','wdss'); ?></th>
                    <th><?php esc_html_e('Type','wdss'); ?></th>
                    <th><?php esc_html_e('To','wdss'); ?></th>
                    <th><?php esc_html_e('Template','wdss'); ?></th>
                    <th><?php esc_html_e('Rule','wdss'); ?></th>
                    <th><?php esc_html_e('Event','wdss'); ?></th>
                </tr></thead>
                <tbody>
                <?php if (empty($log)): ?>
                    <tr><td colspan="6"><?php esc_html_e('No log entries yet.','wdss'); ?></td></tr>
                <?php else:
                    $log = array_slice( array_reverse($log), 0, 100 );
                    foreach ($log as $row): ?>
                    <tr>
                        <td><?php echo esc_html($row['time'] ?? ''); ?></td>
                        <td><?php echo esc_html($row['type'] ?? ''); ?></td>
                        <td><?php echo esc_html($row['to'] ?? ''); ?></td>
                        <td><?php echo esc_html($row['tpl'] ?? ''); ?></td>
                        <td><?php echo esc_html($row['rule'] ?? ''); ?></td>
                        <td><?php echo esc_html($row['event'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <h2 style="margin-top:24px;"><?php esc_html_e('5) Idempotency Keys (recent)','wdss'); ?></h2>
            <p class="description"><?php esc_html_e('Prevents duplicate sends within 24h.','wdss'); ?></p>
            <pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:220px;overflow:auto;"><?php echo esc_html( print_r( $idem, true ) ); ?></pre>
        </div>
        <?php
    }

    public function handle_send_test() {
        if ( ! current_user_can('manage_options') ) wp_die('Denied');
        check_admin_referer('wdss_email_diag');

        $tpl_id  = isset($_POST['tpl_id']) ? intval($_POST['tpl_id']) : 0;
        $to      = isset($_POST['to']) ? sanitize_email( wp_unslash($_POST['to']) ) : '';
        $payload = isset($_POST['payload']) ? json_decode( stripslashes( (string) $_POST['payload'] ), true ) : array();
        if ( ! is_array( $payload ) ) $payload = array();

        $ok = false;
        if ( $tpl_id && is_email($to) && class_exists('WDSS29_Email_Diagnostics') ) {
            $ok = WDSS29_Email_Diagnostics::send_direct_template( $tpl_id, $to, $payload );
        }

        $dest = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
        $dest = add_query_arg( array( 'sent' => $ok ? '1' : '0' ), $dest ); // FIX
        wp_safe_redirect( $dest );
        exit;
    }

    public function handle_fire_event() {
        if ( ! current_user_can('manage_options') ) wp_die('Denied');
        check_admin_referer('wdss_email_diag');

        $event    = sanitize_text_field( $_POST['event'] ?? '' );
        $order_id = intval( $_POST['order_id'] ?? 0 );
        $payload  = isset($_POST['payload']) ? json_decode( stripslashes( (string) $_POST['payload'] ), true ) : array();
        if ( ! is_array( $payload ) ) $payload = array();

        do_action( 'wdss_email_trigger', $event, $order_id, $payload );

        $dest = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
        $dest = add_query_arg( array( 'fired' => '1' ), $dest ); // FIX
        wp_safe_redirect( $dest );
        exit;
    }

    /** Use the WDSS_Emailer pipeline to send a template, but directly. */
    public static function send_direct_template( $tpl_id, $to, $payload = array() ) {
        $post = get_post( $tpl_id );
        if ( ! $post || $post->post_type !== 'wdss_email_template' ) return false;

        $subject = get_post_meta( $tpl_id, '_wdss_subject', true );
        $headers = get_post_meta( $tpl_id, '_wdss_headers', true );
        if ( ! is_array( $headers ) ) $headers = array();

        $has_from = false;
        foreach ( $headers as $h ) { if ( stripos($h, 'from:') === 0 ) { $has_from = true; break; } }
        if ( ! $has_from ) {
            $domain = parse_url( home_url(), PHP_URL_HOST );
            $from   = 'no-reply@' . ( $domain ?: 'localhost' );
            $headers[] = 'From: ' . get_bloginfo('name') . ' <' . $from . '>';
        }

        $cc      = trim( (string) get_post_meta( $tpl_id, '_wdss_cc', true ) );
        $bcc     = trim( (string) get_post_meta( $tpl_id, '_wdss_bcc', true ) );
        $replyto = trim( (string) get_post_meta( $tpl_id, '_wdss_reply_to', true ) );
        if ( $cc !== '' )         $headers[] = 'Cc: ' . $cc;
        if ( $bcc !== '' )        $headers[] = 'Bcc: ' . $bcc;
        if ( is_email($replyto) ) $headers[] = 'Reply-To: ' . $replyto;

        $headers[] = 'Content-Type: text/html; charset=UTF-8';

        foreach ( (array)$payload as $k=>$v ) {
            $subject = str_replace('{{'.$k.'}}', is_scalar($v)?(string)$v:wp_json_encode($v), $subject);
        }
        $body = apply_filters( 'the_content', $post->post_content );
        foreach ( (array)$payload as $k=>$v ) {
            $body = str_replace('{{'.$k.'}}', is_scalar($v)?(string)$v:wp_json_encode($v), $body);
        }

        $html = '<!doctype html><html><body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;">'.$body.'</body></html>';
        return wp_mail( $to, $subject, $html, $headers );
    }
}

endif;

WDSS29_Email_Diagnostics::instance();
