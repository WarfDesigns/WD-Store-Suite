<?php
/**
 * WD Store Suite - Automated Emails (Per-Template Automations + Idempotency)
 * Author: Warf Designs LLC
 * Version: 1.5.2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WDSS_Emailer' ) ) :

class WDSS_Emailer {

    private static $instance = null;

    const VERSION        = '1.5.2';
    const RULES_OPTION   = 'wdss_email_rules_v1';
    const LOG_OPTION     = 'wdss_email_log_v1';
    const IDEM_OPTION    = 'wdss_email_idem_v1';
    const CPT_TEMPLATE   = 'wdss_email_template';

    // Template meta keys
    const META_SUBJECT         = '_wdss_subject';
    const META_HEADERS         = '_wdss_headers';

    // Delivery
    const META_RECIP_SEND_TO   = '_wdss_send_to';          // array: customer, admin, custom
    const META_RECIP_CUSTOM    = '_wdss_recipient_custom'; // comma/semicolon list
    const META_CC              = '_wdss_cc';
    const META_BCC             = '_wdss_bcc';
    const META_REPLYTO         = '_wdss_reply_to';

    // Automations (per-template)
    const META_AUTO_ENABLE     = '_wdss_auto_enable';      // 1/0
    const META_AUTO_TRIGGERS   = '_wdss_auto_triggers';    // array of keys
    const META_AUTO_DELAY      = '_wdss_auto_delay';       // minutes int
    const META_AUTO_CONDITIONS = '_wdss_auto_conditions';  // string

    // Nonces/actions
    const NONCE_KEY            = 'wdss_email_tpl_nonce';
    const ACTION_SEND_TEST     = 'wdss_send_test_email';
    const ACTION_PREVIEW       = 'wdss_preview_template';
    const ACTION_TEST_RULE     = 'wdss_test_rule';         // NEW

    /** Singleton */
    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // CPT
        add_action( 'init', array( $this, 'register_template_cpt' ) );

        // Admin UI
        add_action( 'admin_menu', array( $this, 'register_admin_pages' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_template_metaboxes' ) );
        add_action( 'save_post_' . self::CPT_TEMPLATE, array( $this, 'save_template_meta' ), 10, 2 );

        // Automation bus
        add_action( 'wdss_email_trigger', array( $this, 'handle_trigger_event' ), 10, 3 );

        // Scheduled queue
        add_action( 'wdss_send_scheduled_email', array( $this, 'send_scheduled_email' ), 10, 1 );

        // Debug failures
        add_action( 'wp_mail_failed', array( $this, 'capture_mail_failure' ), 10, 1 );

        // Test & Preview handlers
        add_action( 'admin_post_' . self::ACTION_SEND_TEST, array( $this, 'handle_send_test' ) );
        add_action( 'admin_post_' . self::ACTION_PREVIEW,   array( $this, 'handle_preview' ) );
        add_action( 'admin_post_' . self::ACTION_TEST_RULE, array( $this, 'handle_test_rule' ) ); // NEW

        // Bridge canonical hooks → email bus
        add_action( 'wdss29_order_created',        function( $order_id, $payload = array() ) { $this->trigger('order.created', $order_id, (array)$payload); }, 10, 2 );
        add_action( 'wdss29_order_paid',           function( $order_id, $payload = array() ) { $this->trigger('order.paid',    $order_id, (array)$payload); }, 10, 2 );
        add_action( 'wdss29_order_status_changed', function( $order_id, $status, $payload = array() ) {
            $pl = (array) $payload; $pl['order_status'] = $status; $this->trigger('order.status_changed', $order_id, $pl);
        }, 10, 3 );

        if ( ! get_option( self::LOG_OPTION ) )  add_option( self::LOG_OPTION,  array(), '', false );
        if ( ! get_option( self::IDEM_OPTION ) ) add_option( self::IDEM_OPTION, array(), '', false );
    }

    /* ======================= Helpers ======================== */

    /** Triggers supported by the UI */
    private function get_supported_triggers() {
        return array(
            'order.created'        => __( 'Order Created (when order is made)', 'wdss' ),
            'order.paid'           => __( 'Order Paid (after purchase)', 'wdss' ),
            'order.status_changed' => __( 'Order Status Changed', 'wdss' ),
        );
    }

    /** Placeholder keys shown in the template sidebar */
    private function get_supported_placeholders() {
        return array(
            'order_id'       => __( 'Order ID', 'wdss' ),
            'order_total'    => __( 'Order total (number)', 'wdss' ),
            'order_status'   => __( 'Order status (string)', 'wdss' ),
            'customer_name'  => __( 'Customer full name', 'wdss' ),
            'customer_email' => __( 'Customer email', 'wdss' ),
            'site_name'      => __( 'Your site name', 'wdss' ),
            'site_url'       => __( 'Your site homepage URL', 'wdss' ),
        );
    }

    public function trigger( $event_key, $object_id = 0, $payload = array() ) {
        do_action( 'wdss_email_trigger', $event_key, $object_id, $payload );
    }

    /* ================= CPT & Menus / Settings ================ */

    public function register_template_cpt() {
        $labels = array(
            'name'               => __( 'Email Templates', 'wdss' ),
            'singular_name'      => __( 'Email Template', 'wdss' ),
            'add_new'            => __( 'Add New', 'wdss' ),
            'add_new_item'       => __( 'Add New Email Template', 'wdss' ),
            'edit_item'          => __( 'Edit Email Template', 'wdss' ),
            'new_item'           => __( 'New Email Template', 'wdss' ),
            'view_item'          => __( 'View Email Template', 'wdss' ),
            'search_items'       => __( 'Search Email Templates', 'wdss' ),
            'not_found'          => __( 'No templates found', 'wdss' ),
            'not_found_in_trash' => __( 'No templates found in Trash', 'wdss' ),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => false,
            'capability_type'    => 'post',
            'map_meta_cap'       => true,
            'supports'           => array( 'title', 'editor' ),
            'menu_icon'          => 'dashicons-email',
        );

        register_post_type( self::CPT_TEMPLATE, $args );
    }

    public function register_settings() {
        register_setting( 'wdss_email_rules_group', self::RULES_OPTION );
        add_option( self::RULES_OPTION, array(), '', false );
    }

    public function register_admin_pages() {
        $parent = $this->detect_wdss_parent_slug();
        if ( empty( $parent ) ) $parent = 'options-general.php';

        add_submenu_page(
            $parent,
            __( 'Email Automations', 'wdss' ),
            __( 'Email Automations', 'wdss' ),
            'manage_options',
            'wdss-email-automations',
            array( $this, 'render_rules_page' )
        );

        add_submenu_page(
            $parent,
            __( 'Email Templates', 'wdss' ),
            __( 'Email Templates', 'wdss' ),
            'manage_options',
            'edit.php?post_type=' . self::CPT_TEMPLATE
        );
    }

    private function detect_wdss_parent_slug() {
        global $menu;
        if ( ! is_array( $menu ) || empty( $menu ) ) return '';
        foreach ( $menu as $item ) {
            $label = strip_tags( $item[0] ?? '' );
            $slug  = $item[2] ?? '';
            if ( stripos( $label, 'WD Store Suite' ) !== false || stripos( $slug, 'wd-' ) !== false ) return $slug;
        }
        return '';
    }

    /* ================= Template Metaboxes ==================== */

    public function add_template_metaboxes() {
        add_meta_box(
            'wdss_template_settings',
            __( 'Template Settings', 'wdss' ),
            array( $this, 'render_template_settings_metabox' ),
            self::CPT_TEMPLATE, 'side', 'default'
        );

        add_meta_box(
            'wdss_template_delivery',
            __( 'Delivery (Where it goes)', 'wdss' ),
            array( $this, 'render_delivery_metabox' ),
            self::CPT_TEMPLATE, 'side', 'default'
        );

        add_meta_box(
            'wdss_template_automation',
            __( 'Automation (When to send)', 'wdss' ),
            array( $this, 'render_automation_metabox' ),
            self::CPT_TEMPLATE, 'side', 'default'
        );

        add_meta_box(
            'wdss_template_placeholders',
            __( 'Available Placeholders', 'wdss' ),
            array( $this, 'render_placeholders_metabox' ),
            self::CPT_TEMPLATE, 'side', 'low'
        );

        add_meta_box(
            'wdss_template_test',
            __( 'Preview & Send Test', 'wdss' ),
            array( $this, 'render_test_metabox' ),
            self::CPT_TEMPLATE, 'normal', 'default'
        );
    }

    public function render_template_settings_metabox( $post ) {
        $subject = get_post_meta( $post->ID, self::META_SUBJECT, true );
        $headers = get_post_meta( $post->ID, self::META_HEADERS, true );
        if ( ! is_array( $headers ) ) $headers = array();

        echo '<p><label>' . esc_html__('Subject','wdss') . '</label><br/>';
        echo '<input type="text" class="widefat" name="_wdss_subject" value="' . esc_attr( $subject ) . '" /></p>';

        echo '<p><label>' . esc_html__('Additional Headers (one per line)','wdss') . '</label><br/>';
        echo '<textarea name="_wdss_headers" class="widefat" rows="4" placeholder="From: Your Site &lt;no-reply@example.com&gt;&#10;Reply-To: support@example.com">';
        if ( ! empty( $headers ) ) echo esc_textarea( implode( "\n", $headers ) );
        echo '</textarea></p>';
    }

    public function render_delivery_metabox( $post ) {
        $send_to  = get_post_meta( $post->ID, self::META_RECIP_SEND_TO, true );
        if ( ! is_array( $send_to ) ) $send_to = array( 'customer' );
        $rcustom  = get_post_meta( $post->ID, self::META_RECIP_CUSTOM, true );
        $cc       = get_post_meta( $post->ID, self::META_CC, true );
        $bcc      = get_post_meta( $post->ID, self::META_BCC, true );
        $replyto  = get_post_meta( $post->ID, self::META_REPLYTO, true );

        $is = function($key) use($send_to){ return in_array($key, $send_to, true); };
        ?>
        <p><strong><?php _e('Send To','wdss'); ?></strong><br/>
            <label><input type="checkbox" name="_wdss_send_to[]" value="customer" <?php checked($is('customer')); ?>> <?php _e('Customer','wdss'); ?></label><br/>
            <label><input type="checkbox" name="_wdss_send_to[]" value="admin" <?php checked($is('admin')); ?>> <?php _e('Site Admin','wdss'); ?></label><br/>
            <label><input type="checkbox" name="_wdss_send_to[]" value="custom" <?php checked($is('custom')); ?>> <?php _e('Custom Emails','wdss'); ?></label>
        </p>
        <p><label><?php _e('Custom Emails (comma or semicolon separated)','wdss'); ?></label><br/>
            <input type="text" class="widefat" name="_wdss_recipient_custom" value="<?php echo esc_attr($rcustom); ?>" placeholder="one@example.com, two@example.com; three@example.com" />
        </p>
        <p><label><?php _e('CC (comma separated)','wdss'); ?></label><br/>
            <input type="text" class="widefat" name="_wdss_cc" value="<?php echo esc_attr($cc); ?>" placeholder="cc1@example.com, cc2@example.com" />
        </p>
        <p><label><?php _e('BCC (comma separated)','wdss'); ?></label><br/>
            <input type="text" class="widefat" name="_wdss_bcc" value="<?php echo esc_attr($bcc); ?>" placeholder="bcc1@example.com, bcc2@example.com" />
        </p>
        <p><label><?php _e('Reply-To','wdss'); ?></label><br/>
            <input type="email" class="widefat" name="_wdss_reply_to" value="<?php echo esc_attr($replyto); ?>" placeholder="support@example.com" />
        </p>
        <?php
    }

    public function render_automation_metabox( $post ) {
        $enabled   = (int) get_post_meta( $post->ID, self::META_AUTO_ENABLE, true );
        $triggers  = get_post_meta( $post->ID, self::META_AUTO_TRIGGERS, true );
        if ( ! is_array( $triggers ) ) $triggers = array( 'order.paid' );
        $delay     = max( 0, (int) get_post_meta( $post->ID, self::META_AUTO_DELAY, true ) );
        $conds     = (string) get_post_meta( $post->ID, self::META_AUTO_CONDITIONS, true );

        $supported = $this->get_supported_triggers();
        ?>
        <p><label><input type="checkbox" name="_wdss_auto_enable" value="1" <?php checked( $enabled, 1 ); ?> />
            <?php _e('Enable automation for this template','wdss'); ?></label></p>

        <p><strong><?php _e('Triggers','wdss'); ?></strong><br/>
        <?php foreach( $supported as $key => $label ): ?>
            <label style="display:block;margin:2px 0;">
                <input type="checkbox" name="_wdss_auto_triggers[]" value="<?php echo esc_attr($key); ?>" <?php checked( in_array($key,$triggers,true) ); ?>>
                <?php echo esc_html($label); ?>
            </label>
        <?php endforeach; ?>
        <em style="display:block;margin-top:4px;color:#666"><?php _e('Tip: choose “Order Paid (after purchase)” to send after checkout completes.','wdss'); ?></em>
        </p>

        <p><label><?php _e('Delay (minutes)','wdss'); ?></label><br/>
            <input type="number" min="0" class="widefat" name="_wdss_auto_delay" value="<?php echo esc_attr( $delay ); ?>" />
        </p>

        <p><label><?php _e('Conditions (optional)','wdss'); ?></label><br/>
            <input type="text" class="widefat" name="_wdss_auto_conditions" value="<?php echo esc_attr( $conds ); ?>" placeholder="status=paid,min_total:50" />
            <small class="description" style="display:block;color:#666">
                <?php _e('Format: key=value pairs and helpers like min_total:50, separated by commas.','wdss'); ?>
            </small>
        </p>

        <p class="description"><?php _e('Saving the template will (re)generate rules pointing to this template for the selected triggers, delay, recipients, and conditions.','wdss'); ?></p>
        <?php
    }

    public function render_placeholders_metabox( $post ) {
        echo '<ul style="margin:0 0 0 1em;list-style:disc">';
        foreach ( $this->get_supported_placeholders() as $key => $label ) {
            echo '<li><code>{' . esc_html( $key ) . '}</code> or <code>{{' . esc_html( $key ) . '}}</code> — ' . esc_html( $label ) . '</li>';
        }
        echo '</ul>';
    }

    public function render_test_metabox( $post ) {
        $nonce = wp_create_nonce( self::NONCE_KEY );
        $preview_url = admin_url( 'admin-post.php?action=' . self::ACTION_PREVIEW . '&post_id=' . intval($post->ID) . '&_wpnonce=' . $nonce );
        ?>
        <p><?php _e('Your template body (Editor) supports full custom HTML. Use Preview to render with sample data; Send Test to email it.','wdss'); ?></p>
        <p>
            <a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" class="button"><?php _e('Preview Template','wdss'); ?></a>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_SEND_TEST); ?>">
            <input type="hidden" name="post_id" value="<?php echo esc_attr( $post->ID ); ?>">
            <?php wp_nonce_field( self::NONCE_KEY ); ?>
            <p><label><?php _e('Send test to','wdss'); ?></label><br/>
                <input type="email" name="test_email" class="regular-text" placeholder="name@example.com" required>
            </p>
            <p><button class="button button-primary"><?php _e('Send Test Email','wdss'); ?></button></p>
        </form>
        <?php
    }

    /* ======================= Save Template ==================== */

    public function save_template_meta( $post_id, $post ) {
        if ( $post->post_type !== self::CPT_TEMPLATE ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Settings
        $subject = isset( $_POST['_wdss_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['_wdss_subject'] ) ) : '';
        $headers = isset( $_POST['_wdss_headers'] ) ? wp_unslash( $_POST['_wdss_headers'] ) : '';
        $lines   = array_filter( array_map( 'trim', explode( "\n", (string) $headers ) ) );
        update_post_meta( $post_id, self::META_SUBJECT, $subject );
        update_post_meta( $post_id, self::META_HEADERS, $lines );

        // Delivery
        $send_to = isset($_POST['_wdss_send_to']) && is_array($_POST['_wdss_send_to']) ? array_values(array_intersect(array('customer','admin','custom'), array_map('sanitize_text_field', $_POST['_wdss_send_to']))) : array('customer');
        $rcustom = isset($_POST['_wdss_recipient_custom']) ? sanitize_text_field( wp_unslash($_POST['_wdss_recipient_custom']) ) : '';
        $cc      = isset($_POST['_wdss_cc']) ? sanitize_text_field( wp_unslash($_POST['_wdss_cc']) ) : '';
        $bcc     = isset($_POST['_wdss_bcc']) ? sanitize_text_field( wp_unslash($_POST['_wdss_bcc']) ) : '';
        $replyto = isset($_POST['_wdss_reply_to']) ? sanitize_email( wp_unslash($_POST['_wdss_reply_to']) ) : '';
        update_post_meta( $post_id, self::META_RECIP_SEND_TO, $send_to );
        update_post_meta( $post_id, self::META_RECIP_CUSTOM,  $rcustom );
        update_post_meta( $post_id, self::META_CC,            $cc );
        update_post_meta( $post_id, self::META_BCC,           $bcc );
        update_post_meta( $post_id, self::META_REPLYTO,       $replyto );

        // Automation
        $auto_enable = ! empty( $_POST['_wdss_auto_enable'] ) ? 1 : 0;
        $auto_trigs  = isset($_POST['_wdss_auto_triggers']) && is_array($_POST['_wdss_auto_triggers']) ? array_values(array_intersect(array_keys($this->get_supported_triggers()), array_map('sanitize_text_field', $_POST['_wdss_auto_triggers']))) : array();
        $auto_delay  = isset($_POST['_wdss_auto_delay']) ? max(0, intval($_POST['_wdss_auto_delay'])) : 0;
        $auto_conds  = isset($_POST['_wdss_auto_conditions']) ? sanitize_text_field( wp_unslash($_POST['_wdss_auto_conditions']) ) : '';
        update_post_meta( $post_id, self::META_AUTO_ENABLE,     $auto_enable );
        update_post_meta( $post_id, self::META_AUTO_TRIGGERS,   $auto_trigs );
        update_post_meta( $post_id, self::META_AUTO_DELAY,      $auto_delay );
        update_post_meta( $post_id, self::META_AUTO_CONDITIONS, $auto_conds );

        // Generate rules from this template’s selections
        $rules = get_option( self::RULES_OPTION, array() );
        // Remove old template-driven rules for this post (name starts with TPL:)
        $rules = array_values( array_filter( $rules, function($r) use ($post_id){
            if ( empty($r['template_id']) ) return true;
            if ( intval($r['template_id']) !== intval($post_id) ) return true;
            return (strpos($r['name'] ?? '', 'TPL:') !== 0); // keep manual rules
        }));

        if ( $auto_enable && ! empty($auto_trigs) ) {
            foreach ( $auto_trigs as $tkey ) {
                $rule = array(
                    'enabled'         => 1,
                    'name'            => 'TPL:' . $post_id . ' → ' . $tkey,
                    'trigger'         => $tkey,
                    'delay_minutes'   => $auto_delay,
                    'template_id'     => $post_id,
                    'recipient'       => 'set', // defer to template “Send To”
                    'recipient_email' => '',
                    'conditions'      => $auto_conds,
                );
                $rules[] = $rule;
            }
        }
        update_option( self::RULES_OPTION, $rules, false );
    }

    /* ======================== Rules UI ======================= */

    public function render_rules_page() {
        $rules = get_option( self::RULES_OPTION, array() );
        $templates = get_posts( array(
            'post_type'   => self::CPT_TEMPLATE,
            'numberposts' => -1,
            'post_status' => 'any',
            'orderby'     => 'title',
            'order'       => 'ASC'
        ) );
        $nonce = wp_create_nonce( self::NONCE_KEY );
        ?>
        <div class="wrap">
            <h1><?php _e( 'Email Automations', 'wdss' ); ?></h1>
            <p><?php _e( 'These rules are generated by templates (prefixed with TPL:) or created manually.', 'wdss' ); ?></p>

            <form method="post" action="options.php" id="wdss-email-rules-form">
                <?php settings_fields( 'wdss_email_rules_group' ); ?>
                <table class="widefat striped" id="wdss-rules-table">
                    <thead>
                        <tr>
                            <th><?php _e( 'Enabled', 'wdss' ); ?></th>
                            <th><?php _e( 'Name', 'wdss' ); ?></th>
                            <th><?php _e( 'Trigger', 'wdss' ); ?></th>
                            <th><?php _e( 'Delay (min)', 'wdss' ); ?></th>
                            <th><?php _e( 'Template', 'wdss' ); ?></th>
                            <th><?php _e( 'Recipient/Set', 'wdss' ); ?></th>
                            <th><?php _e( 'Conditions', 'wdss' ); ?></th>
                            <th><?php _e( 'Actions', 'wdss' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $rules ) ) : ?>
                            <?php $this->render_rule_row( 0, $this->default_rule(), $templates, $nonce ); ?>
                        <?php else: foreach ( $rules as $i => $rule ) : ?>
                            <?php $this->render_rule_row( $i, wp_parse_args( $rule, $this->default_rule() ), $templates, $nonce ); ?>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
                <p style="margin-top:10px;">
                    <button type="button" class="button" id="wdss-add-rule"><?php _e( 'Add Manual Rule', 'wdss' ); ?></button>
                    <input type="submit" class="button button-primary" value="<?php esc_attr_e('Save Rules','wdss'); ?>" />
                </p>
            </form>

            <script>
            (function($){
                function rowTemplate(i, nonce){
                    return `
<tr>
  <td><input type="checkbox" name="<?php echo self::RULES_OPTION; ?>[${i}][enabled]" value="1" checked /></td>
  <td><input type="text" class="regular-text" name="<?php echo self::RULES_OPTION; ?>[${i}][name]" value="" placeholder="<?php echo esc_js(__('Rule name','wdss')); ?>" /></td>
  <td>
    <select name="<?php echo self::RULES_OPTION; ?>[${i}][trigger]">
      <?php foreach ( $this->get_supported_triggers() as $key => $label ) : ?>
      <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html( $label ); ?></option>
      <?php endforeach; ?>
    </select>
  </td>
  <td><input type="number" min="0" name="<?php echo self::RULES_OPTION; ?>[${i}][delay_minutes]" value="0" /></td>
  <td>
    <select name="<?php echo self::RULES_OPTION; ?>[${i}][template_id]">
      <option value="0"><?php echo esc_js( __('— Select —','wdss') ); ?></option>
      <?php foreach ( $templates as $tpl ) : ?>
      <option value="<?php echo esc_attr( $tpl->ID ); ?>"><?php echo esc_html( $tpl->post_title ); ?></option>
      <?php endforeach; ?>
    </select>
  </td>
  <td>
    <select name="<?php echo self::RULES_OPTION; ?>[${i}][recipient]" class="wdss-recipient">
      <option value="set"><?php echo esc_js(__('Template “Send To” set','wdss')); ?></option>
      <option value="customer"><?php echo esc_js(__('Customer','wdss')); ?></option>
      <option value="admin"><?php echo esc_js(__('Site Admin','wdss')); ?></option>
      <option value="custom"><?php echo esc_js(__('Custom Emails','wdss')); ?></option>
    </select>
    <input type="text" placeholder="<?php echo esc_js(__('custom1@example.com, custom2@example.com','wdss')); ?>" class="wdss-recipient-email" name="<?php echo self::RULES_OPTION; ?>[${i}][recipient_email]" value="" style="display:none" />
  </td>
  <td><input type="text" class="regular-text" name="<?php echo self::RULES_OPTION; ?>[${i}][conditions]" value="" placeholder="status=paid,min_total:50" /></td>
  <td>
    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline;">
      <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_TEST_RULE ); ?>">
      <input type="hidden" name="rule_index" value="${i}">
      <input type="hidden" name="_wpnonce" value="${nonce}">
      <button class="button"><?php echo esc_js(__('Test','wdss')); ?></button>
    </form>
    <button type="button" class="button link-delete wdss-remove-rule"><?php echo esc_js(__('Remove','wdss')); ?></button>
  </td>
</tr>`;
                }

                $('#wdss-add-rule').on('click', function(){
                    var i = $('#wdss-rules-table tbody tr').length;
                    $('#wdss-rules-table tbody').append( rowTemplate(i, '<?php echo esc_js( $nonce ); ?>') );
                });

                $(document).on('click','.wdss-remove-rule', function(){
                    $(this).closest('tr').remove();
                });

                $(document).on('change','.wdss-recipient', function(){
                    var $row = $(this).closest('tr');
                    if ( $(this).val()==='custom' ) $row.find('.wdss-recipient-email').show();
                    else $row.find('.wdss-recipient-email').hide();
                });
            })(jQuery);
            </script>
        </div>
        <?php
    }

    private function default_rule() {
        return array(
            'enabled'        => 1,
            'name'           => '',
            'trigger'        => 'order.paid',
            'delay_minutes'  => 0,
            'template_id'    => 0,
            'recipient'      => 'set',
            'recipient_email'=> '',
            'conditions'     => '',
        );
    }

    private function render_rule_row( $i, $rule, $templates, $nonce ) {
        ?>
        <tr>
            <td><input type="checkbox" name="<?php echo self::RULES_OPTION; ?>[<?php echo esc_attr($i); ?>][enabled]" value="1" <?php checked( ! empty( $rule['enabled'] ) ); ?> /></td>
            <td><input type="text" class="regular-text" name="<?php echo self::RULES_OPTION; ?>[<?php echo esc_attr($i); ?>][name]" value="<?php echo esc_attr( $rule['name'] ); ?>" placeholder="<?php esc_attr_e('Rule name','wdss'); ?>" /></td>
            <td>
                <select name="<?php echo self::RULES_OPTION; ?>[<?php echo esc_attr($i); ?>][trigger]">
                    <?php foreach ( $this->get_supported_triggers() as $key => $label ) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected( $rule['trigger'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="number" min="0" name="<?php echo self::RULES_OPTION; ?>[<?php echo esc_attr($i); ?>][delay_minutes]" value="<?php echo esc_attr( $rule['delay_minutes'] ); ?>" /></td>
            <td>
                <select name="<?php echo self::RULES_OPTION; ?>[<?php echo esc_attr($i); ?>][template_id]">
                    <option value="0"><?php _e( '— Select —', 'wdss' ); ?></option>
                    <?php foreach ( $templates as $tpl ) : ?>
                        <option value="<?php echo esc_attr( $tpl->ID ); ?>" <?php selected( $rule['template_id'], $tpl->ID ); ?>><?php echo esc_html( $tpl->post_title ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <select name="<?php echo self::RULES_OPTION; ?>[<?php echo esc_attr($i); ?>][recipient]" class="wdss-recipient">
                    <option value="set" <?php selected( $rule['recipient'], 'set' ); ?>><?php _e( 'Template “Send To” set', 'wdss' ); ?></option>
                    <option value="customer" <?php selected( $rule['recipient'], 'customer' ); ?>><?php _e( 'Customer', 'wdss' ); ?></option>
                    <option value="admin" <?php selected( $rule['recipient'], 'admin' ); ?>><?php _e( 'Site Admin', 'wdss' ); ?></option>
                    <option value="custom" <?php selected( $rule['recipient'], 'custom' ); ?>><?php _e( 'Custom Emails', 'wdss' ); ?></option>
                </select>
                <input type="text" placeholder="<?php esc_attr_e('custom1@example.com, custom2@example.com','wdss'); ?>" class="wdss-recipient-email" name="<?php echo self::RULES_OPTION; ?>[<?php echo esc_attr($i); ?>][recipient_email]" value="<?php echo esc_attr( $rule['recipient_email'] ); ?>" <?php echo $rule['recipient']==='custom'?'':'style="display:none"'; ?> />
            </td>
            <td><input type="text" class="regular-text" name="<?php echo self::RULES_OPTION; ?>[<?php echo esc_attr($i); ?>][conditions]" value="<?php
                $cond_string = is_array( $rule['conditions'] ?? '' ) ? implode(',', $rule['conditions']) : ( $rule['conditions'] ?? '' );
                echo esc_attr( $cond_string ); ?>" placeholder="status=paid,min_total:50" /></td>
            <td>
                <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline;">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_TEST_RULE ); ?>">
                    <input type="hidden" name="rule_index" value="<?php echo esc_attr( $i ); ?>">
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
                    <button class="button"><?php _e('Test','wdss'); ?></button>
                </form>
                <button type="button" class="button link-delete wdss-remove-rule"><?php _e( 'Remove', 'wdss' ); ?></button>
            </td>
        </tr>
        <?php
    }

    /* =================== Trigger Handling ==================== */

    public function handle_trigger_event( $event_key, $object_id = 0, $payload = array() ) {
        $this->log_event( 'received-event', array( 'event' => $event_key, 'obj' => $object_id ) );

        $rules = get_option( self::RULES_OPTION, array() );
        if ( empty( $rules ) ) { $this->log_event('no-rule',''); return; }

        $matched_any = false;

        foreach ( $rules as $idx => $rule ) {
            if ( empty( $rule['enabled'] ) ) continue;
            if ( ($rule['trigger'] ?? '') !== $event_key ) continue;

            if ( ! $this->check_conditions( $rule, $payload, $object_id ) ) {
                $this->log_event( 'skip-conditions', array( 'rule' => $rule['name'] ?? '' ) );
                continue;
            }

            $matched_any = true;
            $recipients = $this->resolve_recipient_set( $rule, $object_id, $payload );
            $this->log_event( 'recipients', array( 'to' => implode(',', $recipients), 'rule_name' => $rule['name'] ?? '', 'event' => $event_key, 'template_id' => intval($rule['template_id']) ) );

            if ( empty( $recipients ) ) {
                $this->log_event( 'skip-no-recipient', array( 'rule' => $rule['name'] ?? '', 'tpl' => $rule['template_id'] ?? 0 ) );
                continue;
            }

            $idem_key = $this->idem_key( $rule['template_id'] ?? 0, $payload, $event_key );
            if ( $this->idem_seen_recently( $idem_key ) ) {
                $this->log_event( 'skip-duplicate', array( 'tpl'  => intval($rule['template_id']), 'rule' => $rule['name'] ?? '', 'ev' => $event_key ) );
                continue;
            }

            $job_base = array(
                'template_id' => intval( $rule['template_id'] ),
                'payload'     => $this->augment_payload( $payload, $object_id ),
                'rule_name'   => $rule['name'],
                'event'       => $event_key,
            );

            $delay = isset( $rule['delay_minutes'] ) ? max( 0, intval( $rule['delay_minutes'] ) ) : 0;
            foreach ( $recipients as $to ) {
                $job = $job_base; $job['to'] = $to;
                if ( $delay > 0 ) {
                    $timestamp = time() + ( $delay * 60 );
                    wp_schedule_single_event( $timestamp, 'wdss_send_scheduled_email', array( $job ) );
                    $this->log_event( 'scheduled', $job );
                } else {
                    $this->send_scheduled_email( $job );
                }
            }
            $this->idem_mark( $idem_key );
        }

        if ( ! $matched_any ) $this->log_event( 'no-rule', array( 'event' => $event_key ) );
    }

    public function send_scheduled_email( $job ) {
        $tpl_id  = intval( $job['template_id'] ?? 0 );
        $to      = sanitize_email( $job['to'] ?? '' );
        $payload = is_array( $job['payload'] ?? null ) ? $job['payload'] : array();
        if ( $tpl_id < 1 || empty( $to ) ) return false;

        $sent = $this->send_email_from_template( $tpl_id, $to, $payload );
        $this->log_event( $sent ? 'sent' : 'failed', $job );
        return $sent;
    }

    private function send_email_from_template( $template_id, $to_email, $payload ) {
        $post = get_post( $template_id );
        if ( ! $post || $post->post_type !== self::CPT_TEMPLATE ) return false;

        $subject = get_post_meta( $template_id, self::META_SUBJECT, true );
        $headers = get_post_meta( $template_id, self::META_HEADERS, true );
        if ( ! is_array( $headers ) ) $headers = array();

        // Ensure From
        $has_from = false;
        foreach ( $headers as $h ) { if ( stripos($h, 'from:') === 0 ) { $has_from = true; break; } }
        if ( ! $has_from ) {
            $domain = parse_url( home_url(), PHP_URL_HOST );
            $from   = 'no-reply@' . ( $domain ?: 'localhost' );
            $headers[] = 'From: ' . get_bloginfo('name') . ' <' . $from . '>';
        }

        // Per-template delivery headers
        $cc      = trim( (string) get_post_meta( $template_id, self::META_CC, true ) );
        $bcc     = trim( (string) get_post_meta( $template_id, self::META_BCC, true ) );
        $replyto = trim( (string) get_post_meta( $template_id, self::META_REPLYTO, true ) );
        if ( $cc !== '' )         $headers[] = 'Cc: ' . $cc;
        if ( $bcc !== '' )        $headers[] = 'Bcc: ' . $bcc;
        if ( is_email($replyto) ) $headers[] = 'Reply-To: ' . $replyto;

        $headers[] = 'Content-Type: text/html; charset=UTF-8';

        // Body = custom HTML from editor, with placeholders
        $body_raw = apply_filters( 'the_content', $post->post_content );

        $subject  = $this->replace_placeholders( $subject, $payload );
        $body     = $this->replace_placeholders( $body_raw, $payload );

        return wp_mail( $to_email, $subject, $this->wrap_html_email( $body ), $headers );
    }

    private function resolve_recipient_set( $rule, $object_id, $payload ) {
        // Manual override on rule
        if ( isset($rule['recipient']) && $rule['recipient'] !== 'set' ) {
            switch ( $rule['recipient'] ) {
                case 'admin':  return array( get_option('admin_email') );
                case 'custom':
                    $raw = (string) ($rule['recipient_email'] ?? '');
                    $raw = str_replace(';', ',', $raw);
                    $list = array_map( 'trim', explode(',', $raw) );
                    return array_filter( array_map( 'sanitize_email', $list ) );
                case 'customer':
                default:
                    $email = sanitize_email( $payload['customer_email'] ?? '' );
                    return $email ? array($email) : array();
            }
        }

        // Defer to template “Send To”
        $tpl_id   = intval( $rule['template_id'] ?? 0 );
        $send_to  = get_post_meta( $tpl_id, self::META_RECIP_SEND_TO, true );
        if ( ! is_array( $send_to ) ) $send_to = array('customer');

        $targets = array();
        foreach ( $send_to as $who ) {
            if ( $who === 'customer' ) {
                $email = sanitize_email( $payload['customer_email'] ?? '' );
                if ( ! $email ) { $email = get_option('admin_email'); } // fallback for testing
                if ( $email ) $targets[] = $email;
            } elseif ( $who === 'admin' ) {
                $targets[] = get_option('admin_email');
            } elseif ( $who === 'custom' ) {
                $raw = (string) get_post_meta( $tpl_id, self::META_RECIP_CUSTOM, true );
                $raw = str_replace(';', ',', $raw);
                $list = array_filter( array_map( 'sanitize_email', array_map('trim', explode(',', $raw)) ) );
                $targets = array_merge( $targets, $list );
            }
        }

        return array_values( array_unique( array_filter( $targets ) ) );
    }

    private function wrap_html_email( $content ) {
        $styles = 'font-family: Arial, Helvetica, sans-serif; font-size:14px; color:#222;';
        return '<!doctype html><html><body style="'.$styles.'">' . $content . '<hr style="margin-top:24px;border:0;border-top:1px solid #eee;"><p style="font-size:12px;color:#666;">Sent by WD Store Suite</p></body></html>';
    }

    /** Supports both {{token}} and {token} syntaxes */
    private function replace_placeholders( $text, $payload ) {
        if ( ! is_string( $text ) ) return $text;
        foreach ( (array)$payload as $key => $val ) {
            $rep = is_scalar($val) ? (string)$val : wp_json_encode($val);
            $text = str_replace( '{{'.$key.'}}', $rep, $text );
            $text = str_replace( '{'.$key.'}',  $rep, $text );
        }
        return $text;
    }

    private function check_conditions( $rule, $payload, $object_id ) {
        $conds = $rule['conditions'] ?? array();
        if ( empty( $conds ) ) return true;
        if ( is_string( $conds ) ) $conds = array_map( 'trim', explode( ',', $conds ) );
        foreach ( $conds as $cond ) {
            $cond = trim( $cond );
            if ( $cond === '' ) continue;

            if ( strpos( $cond, '=' ) !== false ) {
                list( $key, $val ) = array_map( 'trim', explode( '=', $cond, 2 ) );
                $in = isset( $payload[ $key ] ) ? (string) $payload[ $key ] : '';
                if ( strval( $in ) !== $val ) return false;
            } elseif ( strpos( $cond, 'min_total:' ) === 0 ) {
                $min = (float) trim( substr( $cond, strlen('min_total:') ) );
                $tot = isset( $payload['order_total'] ) ? (float) $payload['order_total'] : 0;
                if ( $tot < $min ) return false;
            }
        }
        return true;
    }

    private function augment_payload( $payload, $object_id ) {
        if ( empty( $payload['site_name'] ) ) $payload['site_name'] = get_bloginfo('name');
        if ( empty( $payload['site_url'] ) )  $payload['site_url']  = home_url('/');

        if ( empty( $payload['customer_email'] ) && $object_id ) {
            $email = get_post_meta( $object_id, '_customer_email', true );
            if ( is_email( $email ) ) $payload['customer_email'] = $email;
        }
        if ( empty( $payload['customer_name'] ) && $object_id ) {
            $name = get_post_meta( $object_id, '_customer_name', true );
            if ( $name ) $payload['customer_name'] = $name;
        }
        if ( empty( $payload['order_total'] ) && $object_id ) {
            $total = get_post_meta( $object_id, '_order_total', true );
            if ( $total ) $payload['order_total'] = $total;
        }
        if ( empty( $payload['order_status'] ) && $object_id ) {
            $status = get_post_meta( $object_id, '_order_status', true );
            if ( $status ) $payload['order_status'] = $status;
        }

        return $payload;
    }

    /* =================== Test & Preview ====================== */

    public function handle_send_test() {
        if ( ! current_user_can('manage_options') ) wp_die('Denied.');
        check_admin_referer( self::NONCE_KEY );

        $post_id   = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $test_mail = isset($_POST['test_email']) ? sanitize_email( wp_unslash($_POST['test_email']) ) : '';

        if ( $post_id < 1 || ! is_email( $test_mail ) ) {
            wp_safe_redirect( wp_get_referer() ?: admin_url() ); exit;
        }

        $payload = array(
            'order_id'       => 4321,
            'order_total'    => 149.00,
            'order_status'   => 'paid',
            'customer_name'  => 'John Sample',
            'customer_email' => $test_mail,
            'site_name'      => get_bloginfo('name'),
            'site_url'       => home_url('/'),
        );

        $sent = $this->send_email_from_template( $post_id, $test_mail, $payload );

        $q = array('post' => $post_id, 'sent' => $sent ? '1' : '0', 'time' => rawurlencode( current_time('mysql') ));
        $url = add_query_arg( $q, get_edit_post_link( $post_id, 'url' ) );
        wp_safe_redirect( $url ); exit;
    }

    public function handle_preview() {
        if ( ! current_user_can('manage_options') ) wp_die('Denied.');
        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
        $ok = wp_verify_nonce( $_GET['_wpnonce'] ?? '', self::NONCE_KEY );
        if ( ! $ok || $post_id < 1 ) wp_die('Invalid nonce.');

        $payload = array(
            'order_id'       => 4321,
            'order_total'    => 149.00,
            'order_status'   => 'paid',
            'customer_name'  => 'John Sample',
            'customer_email' => 'john.sample@example.com',
            'site_name'      => get_bloginfo('name'),
            'site_url'       => home_url('/'),
        );

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== self::CPT_TEMPLATE ) wp_die('Invalid template.');

        $subject = get_post_meta( $post_id, self::META_SUBJECT, true );
        $subject = $this->replace_placeholders( $subject, $payload );
        $body    = apply_filters( 'the_content', $post->post_content );
        $body    = $this->replace_placeholders( $body, $payload );

        $html = $this->wrap_html_email( $body );
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!-- Subject: ' . esc_html( $subject ) . " -->\n";
        echo $html;
        exit;
    }

    /** Test a single RULE row directly from UI (bypasses external events) */
    public function handle_test_rule() {
        if ( ! current_user_can('manage_options') ) wp_die('Denied.');
        $ok = wp_verify_nonce( $_POST['_wpnonce'] ?? '', self::NONCE_KEY );
        if ( ! $ok ) wp_die('Invalid nonce.');

        $idx   = isset($_POST['rule_index']) ? intval($_POST['rule_index']) : -1;
        $rules = get_option( self::RULES_OPTION, array() );
        if ( $idx < 0 || ! isset( $rules[$idx] ) ) {
            wp_safe_redirect( wp_get_referer() ?: admin_url() ); exit;
        }
        $rule = $rules[$idx];

        // Sample payload for testing rule resolution & sending
        $payload = array(
            'order_id'       => 9876,
            'order_total'    => 199.50,
            'order_status'   => 'paid',
            'customer_name'  => 'Rule Test',
            'customer_email' => get_option('admin_email'),
            'site_name'      => get_bloginfo('name'),
            'site_url'       => home_url('/'),
        );

        $recips = $this->resolve_recipient_set( $rule, 0, $payload );
        $this->log_event( 'recipients', array( 'to' => implode(',', $recips), 'rule_name' => $rule['name'] ?? '', 'event' => '[TEST]', 'template_id' => intval($rule['template_id']) ) );

        $sent_any = false;
        foreach ( $recips as $to ) {
            $sent = $this->send_email_from_template( intval($rule['template_id']), $to, $payload );
            $this->log_event( $sent ? 'sent' : 'failed', array(
                'to' => $to, 'template_id' => intval($rule['template_id']),
                'rule_name' => $rule['name'] ?? '', 'event' => '[TEST]'
            ) );
            $sent_any = $sent_any || $sent;
        }

        wp_safe_redirect( wp_get_referer() ?: admin_url() ); exit;
    }

    /* ===================== Idempotency/Logs ================== */

    private function idem_key( $template_id, $payload, $event ) {
        $order_id = isset($payload['order_id']) ? (string) $payload['order_id'] : 'no-order';
        return md5( implode('|', array( (int)$template_id, (string)$event, $order_id ) ) );
    }

    private function idem_seen_recently( $key ) {
        $map = get_option( self::IDEM_OPTION, array() );
        if ( ! is_array( $map ) ) $map = array();
        if ( empty( $map[$key] ) ) return false;
        return ( time() - (int)$map[$key] ) < DAY_IN_SECONDS;
    }

    private function idem_mark( $key ) {
        $map = get_option( self::IDEM_OPTION, array() );
        if ( ! is_array( $map ) ) $map = array();
        $map[$key] = time();
        if ( count( $map ) > 1000 ) {
            $map = array_slice( $map, -1000, null, true );
        }
        update_option( self::IDEM_OPTION, $map, false );
    }

    private function log_event( $type, $job_or_meta, $meta = array() ) {
        $log = get_option( self::LOG_OPTION, array() );
        if ( ! is_array( $log ) ) $log = array();

        if ( is_array($job_or_meta) ) {
            $log[] = array(
                'time'   => current_time('mysql', 1),
                'type'   => $type,
                'to'     => $job_or_meta['to'] ?? '',
                'tpl'    => $job_or_meta['template_id'] ?? 0,
                'rule'   => $job_or_meta['rule_name'] ?? '',
                'event'  => $job_or_meta['event'] ?? '',
            );
        } else {
            $log[] = array(
                'time'   => current_time('mysql', 1),
                'type'   => $type,
                'meta'   => $job_or_meta,
                'extra'  => $meta,
            );
        }

        if ( count( $log ) > 500 ) $log = array_slice( $log, -500 );
        update_option( self::LOG_OPTION, $log, false );
    }

    public function capture_mail_failure( $wp_error ) {
        $data = method_exists($wp_error, 'get_error_data') ? $wp_error->get_error_data() : array();
        $this->log_event( 'wp_mail_failed', array(
            'to'    => is_array($data) && isset($data['to']) ? $data['to'] : '',
            'event' => 'mail_failed',
        ));
    }
}

endif;

// Bootstrap
WDSS_Emailer::instance();
