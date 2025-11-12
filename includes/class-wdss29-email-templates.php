<?php
/**
 * WD Store Suite — Email Templates (single CPT; no duplicate metaboxes)
 * Author: Warf Designs LLC
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WDSS29_Email_Templates' ) ) :

class WDSS29_Email_Templates {

    const CPT          = 'wdss_email_template';
    const NONCE        = 'wdss29_tpl_nonce';
    const BOX_ID       = 'wdss29_tpl_test';        // keep THIS one only
    const BOX_SETTINGS = 'wdss29_tpl_settings';
    const BOX_DELIVERY = 'wdss29_tpl_delivery';

    public static function boot() {
        add_action( 'plugins_loaded', [ __CLASS__, 'init' ], 1 );
    }

    public static function init() {
        add_action( 'init',               [ __CLASS__, 'register_cpt' ], 8 );

        // First pass: remove obvious legacy boxes before we add ours
        add_action( 'add_meta_boxes',     [ __CLASS__, 'remove_duplicate_boxes_early' ], 1 );
        add_action( 'add_meta_boxes',     [ __CLASS__, 'add_meta_boxes' ], 10 );

        // Last pass: if anything else added another duplicate, nuke it
        add_action( 'do_meta_boxes',      [ __CLASS__, 'finalize_meta_boxes' ], 9999 );

        // Save / Publish
        add_action( 'save_post_' . self::CPT, [ __CLASS__, 'save_post' ], 10, 2 );
        add_filter( 'wp_insert_post_data',    [ __CLASS__, 'force_publish_when_requested' ], 1000, 2 );
        add_filter( 'redirect_post_location', [ __CLASS__, 'stay_on_editor_redirect' ], 1000, 2 );

        // Prevent HTML5 “required” from blocking Publish
        add_action( 'admin_print_footer_scripts-post.php',     [ __CLASS__, 'footer_no_required' ], 1000 );
        add_action( 'admin_print_footer_scripts-post-new.php', [ __CLASS__, 'footer_no_required' ], 1000 );

        // AJAX test send
        add_action( 'wp_ajax_wdss29_send_tpl_test', [ __CLASS__, 'ajax_send_test' ] );
    }

    /** Register CPT under WD Store Suite */
    public static function register_cpt() {
        $parent_slug = apply_filters( 'wdss_admin_parent_slug', 'wd-store-suite' );
        register_post_type( self::CPT, [
            'labels' => [
                'name'          => 'Email Templates',
                'singular_name' => 'Email Template',
                'menu_name'     => 'Email Templates',
                'add_new_item'  => 'Add New Email Template',
                'edit_item'     => 'Edit Email Template',
                'all_items'     => 'Email Templates',
            ],
            'public'        => false,
            'show_ui'       => true,
            'show_in_menu'  => $parent_slug,
            'show_in_rest'  => false,
            'supports'      => [ 'title', 'editor', 'revisions' ],
            'rewrite'       => false,
            'has_archive'   => false,
            'menu_icon'     => 'dashicons-email',
        ] );
    }

    /* -------------------- Metabox control -------------------- */

    /** Early scrub: remove known legacy boxes by id/title before adding ours */
    public static function remove_duplicate_boxes_early() {
        self::scrub_boxes([ 'Preview & Send Test', 'Template Settings', 'Delivery (Where it goes)' ], [
            'wdss_tpl_test','wdss29_tpl_test','wdss_preview_send_test',
            'wdss_tpl_settings','wdss29_tpl_settings','wdss_tpl_delivery','wdss29_tpl_delivery',
        ]);
    }

    /** Add our ONLY boxes */
    public static function add_meta_boxes() {
        add_meta_box(
            self::BOX_SETTINGS, 'Template Settings',
            [ __CLASS__, 'mb_settings' ], self::CPT, 'side', 'default'
        );
        add_meta_box(
            self::BOX_DELIVERY, 'Delivery (Where it goes)',
            [ __CLASS__, 'mb_delivery' ], self::CPT, 'side', 'default'
        );
        add_meta_box(
            self::BOX_ID, 'Preview & Send Test',
            [ __CLASS__, 'mb_test' ], self::CPT, 'normal', 'default'
        );
    }

    /** Final scrub AFTER everyone added theirs: keep ONLY our BOX_ID/title */
    public static function finalize_meta_boxes() {
        self::scrub_boxes([ 'Preview & Send Test' ], [], true);
    }

    /** Utility: scrub meta boxes by titles/ids, keeping our ids only */
    private static function scrub_boxes( array $titles, array $ids, $final_pass = false ) {
        global $wp_meta_boxes;
        $pt = self::CPT;
        if ( empty( $wp_meta_boxes[ $pt ] ) ) return;

        foreach ( [ 'normal','advanced','side' ] as $ctx ) {
            foreach ( [ 'high','core','default','low' ] as $prio ) {
                if ( empty( $wp_meta_boxes[ $pt ][ $ctx ][ $prio ] ) ) continue;

                foreach ( $wp_meta_boxes[ $pt ][ $ctx ][ $prio ] as $key => $box ) {
                    $id    = isset( $box['id'] ) ? $box['id'] : '';
                    $title = isset( $box['title'] ) ? trim( wp_strip_all_tags( $box['title'] ) ) : '';

                    $matches_title = $title && in_array( $title, $titles, true );
                    $matches_id    = $id && in_array( $id, $ids, true );

                    // In final pass, remove ANY box with the same title that is NOT our id.
                    if ( $final_pass ) {
                        if ( $matches_title && $id !== self::BOX_ID ) unset( $wp_meta_boxes[ $pt ][ $ctx ][ $prio ][ $key ] );
                        continue;
                    }

                    // Early pass: remove legacy ids, and duplicate titles not ours.
                    if ( $matches_id && $id !== self::BOX_ID && $id !== self::BOX_SETTINGS && $id !== self::BOX_DELIVERY ) {
                        unset( $wp_meta_boxes[ $pt ][ $ctx ][ $prio ][ $key ] );
                    } elseif ( $matches_title && ! in_array( $id, [ self::BOX_ID, self::BOX_SETTINGS, self::BOX_DELIVERY ], true ) ) {
                        unset( $wp_meta_boxes[ $pt ][ $ctx ][ $prio ][ $key ] );
                    }
                }
            }
        }
    }

    /* -------------------- Metabox UIs -------------------- */

    private static function gm( $id, $key, $default = '' ) {
        $v = get_post_meta( $id, $key, true );
        return ( $v === '' || $v === null ) ? $default : $v;
    }

    public static function mb_settings( $post ) {
        wp_nonce_field( self::NONCE, self::NONCE );
        $subject = self::gm( $post->ID, '_wdss_tpl_subject', '' );
        $headers = self::gm( $post->ID, '_wdss_tpl_headers', "From: Your Site <no-reply@example.com>\nReply-To: support@example.com" );
        ?>
        <p><label for="wdss_tpl_subject"><strong>Subject</strong></label>
        <input type="text" id="wdss_tpl_subject" name="wdss_tpl_subject" class="widefat"
               value="<?php echo esc_attr( $subject ); ?>"></p>

        <p><label for="wdss_tpl_headers"><strong>Additional Headers (one per line)</strong></label>
        <textarea id="wdss_tpl_headers" name="wdss_tpl_headers" class="widefat" rows="6"><?php
            echo esc_textarea( $headers );
        ?></textarea></p>
        <?php
    }

    public static function mb_delivery( $post ) {
        $send_customer = (bool) self::gm( $post->ID, '_wdss_tpl_send_customer', true );
        $send_admin    = (bool) self::gm( $post->ID, '_wdss_tpl_send_admin', false );
        $send_custom   = (bool) self::gm( $post->ID, '_wdss_tpl_send_custom', false );
        $custom_list   = (string) self::gm( $post->ID, '_wdss_tpl_custom_emails', '' );
        ?>
        <p><label><input type="checkbox" name="wdss_tpl_send_customer" value="1" <?php checked( $send_customer ); ?>> Customer</label></p>
        <p><label><input type="checkbox" name="wdss_tpl_send_admin" value="1" <?php checked( $send_admin ); ?>> Site Admin</label></p>
        <p><label><input type="checkbox" name="wdss_tpl_send_custom" value="1" <?php checked( $send_custom ); ?>> Custom Emails</label></p>
        <p><input type="text" class="widefat" name="wdss_tpl_custom_emails"
                  placeholder="one@example.com, two@example.com"
                  value="<?php echo esc_attr( $custom_list ); ?>"></p>
        <?php
    }

    public static function mb_test( $post ) {
        ?>
        <p>Your template body supports full HTML. Use <strong>Send Test</strong> to email a preview.
           This does <em>not</em> affect Publish/Update and is completely optional.</p>
        <p>
            <label for="wdss_tpl_test_to"><strong>Send test to</strong></label>
            <input type="email" id="wdss_tpl_test_to" class="regular-text" placeholder="name@example.com">
            <button type="button" class="button" id="wdss_tpl_send_test_btn">Send Test</button>
        </p>
        <script>
        (function($){
            $('#wdss_tpl_send_test_btn').on('click', function(e){
                e.preventDefault();
                var to = $('#wdss_tpl_test_to').val();
                if(!to){ alert('Enter an email to send the test (optional for Publish).'); return; }
                $(this).prop('disabled', true);
                $.post(ajaxurl, {
                    action: 'wdss29_send_tpl_test',
                    _ajax_nonce: '<?php echo esc_js( wp_create_nonce( "wdss29_send_tpl_test" ) ); ?>',
                    post_id: <?php echo (int) $post->ID; ?>,
                    to: to
                }, function(resp){
                    alert(resp && resp.data ? resp.data.message : 'Test sent (if no errors).');
                }).fail(function(){
                    alert('Failed to send test. Check SMTP or headers.');
                }).always(function(){
                    $('#wdss_tpl_send_test_btn').prop('disabled', false);
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /* -------------------- Save / Publish -------------------- */

    public static function save_post( $post_id, $post ) {
        if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( $_POST[ self::NONCE ], self::NONCE ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( $post->post_type !== self::CPT ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        update_post_meta( $post_id, '_wdss_tpl_subject',
            isset($_POST['wdss_tpl_subject']) ? sanitize_text_field( $_POST['wdss_tpl_subject'] ) : '' );

        update_post_meta( $post_id, '_wdss_tpl_headers',
            isset($_POST['wdss_tpl_headers']) ? wp_kses_post( wp_unslash( $_POST['wdss_tpl_headers'] ) ) : '' );

        update_post_meta( $post_id, '_wdss_tpl_send_customer', ! empty( $_POST['wdss_tpl_send_customer'] ) ? 1 : 0 );
        update_post_meta( $post_id, '_wdss_tpl_send_admin',    ! empty( $_POST['wdss_tpl_send_admin'] ) ? 1 : 0 );
        update_post_meta( $post_id, '_wdss_tpl_send_custom',   ! empty( $_POST['wdss_tpl_send_custom'] ) ? 1 : 0 );
        update_post_meta( $post_id, '_wdss_tpl_custom_emails',
            isset($_POST['wdss_tpl_custom_emails']) ? sanitize_text_field( $_POST['wdss_tpl_custom_emails'] ) : '' );
    }

    public static function force_publish_when_requested( $data, $postarr ) {
        if ( ( $data['post_type'] ?? '' ) !== self::CPT ) return $data;
        $wants_publish = (
            ( isset($_POST['publish']) && $_POST['publish'] ) ||
            ( isset($_POST['original_publish']) && $_POST['original_publish'] === 'Publish' )
        );
        if ( $wants_publish && current_user_can( 'publish_posts' ) ) {
            $data['post_status'] = 'publish';
            if ( empty( $data['post_date'] ) || $data['post_date'] === '0000-00-00 00:00:00' ) {
                $data['post_date']     = current_time( 'mysql' );
                $data['post_date_gmt'] = current_time( 'mysql', 1 );
            }
        }
        return $data;
    }

    public static function stay_on_editor_redirect( $location, $post_id ) {
        $post = get_post( $post_id );
        if ( $post && $post->post_type === self::CPT ) {
            $location = add_query_arg(
                [ 'post' => $post_id, 'action' => 'edit', 'wdss_saved' => 1 ],
                admin_url( 'post.php' )
            );
        }
        return $location;
    }

    public static function footer_no_required() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== self::CPT ) return; ?>
        <script>
        (function(){
          // make Publish/Save ignore HTML5 validation
          ['publish','save-post'].forEach(function(id){
            var b = document.getElementById(id);
            if (b) b.setAttribute('formnovalidate','formnovalidate');
          });
          // remove any stray required inputs in ALL metaboxes
          document.querySelectorAll('#poststuff .postbox input[required], #poststuff .postbox textarea[required]').forEach(function(el){
            el.removeAttribute('required');
          });
        })();
        </script>
        <?php
    }

    /* -------------------- AJAX -------------------- */

    public static function ajax_send_test() {
        check_ajax_referer( 'wdss29_send_tpl_test' );
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $to      = isset($_POST['to']) ? sanitize_email($_POST['to']) : '';
        if ( ! $post_id || ! $to || ! is_email($to) ) {
            wp_send_json_error( [ 'message' => 'Valid test email required.' ] );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== self::CPT ) {
            wp_send_json_error( [ 'message' => 'Template not found.' ] );
        }

        $subject = get_post_meta( $post_id, '_wdss_tpl_subject', true );
        if ( ! $subject ) $subject = 'Test: ' . $post->post_title;

        $headers_raw = (string) get_post_meta( $post_id, '_wdss_tpl_headers', true );
        $headers = [];
        if ( $headers_raw ) {
            foreach ( preg_split( '/\r\n|\r|\n/', $headers_raw ) as $line ) {
                $line = trim( $line );
                if ( $line ) $headers[] = $line;
            }
        }

        $body = (string) $post->post_content;
        $body = str_replace(
            [ '{order_id}','{{order_id}}','{order_total}','{{order_total}}','{order_status}','{{order_status}}','{customer_name}','{{customer_name}}' ],
            [ '1234','1234','199.00','199.00','paid','paid','Sample Customer','Sample Customer' ],
            $body
        );

        $sent = wp_mail( $to, $subject, $body, $headers );
        if ( $sent ) wp_send_json_success( [ 'message' => 'Test email sent to ' . $to ] );
        wp_send_json_error( [ 'message' => 'wp_mail failed. Check SMTP/headers.' ] );
                $sent = wp_mail( $to, $subject, $body, $headers );
        if ( $sent ) {
            wp_send_json_success( [ 'message' => 'Test email sent to ' . $to ] );
        } else {
            $why = get_transient('wdss_last_mail_error');
            if (!$why) { $why = 'wp_mail failed. Check SMTP credentials, “Force From”, and headers.'; }
            wp_send_json_error( [ 'message' => $why ] );
        }

    }
}

// --- WDSS: capture wp_mail failures for debugging ---
add_action('wp_mail_failed', function($wp_error){
    $msg = '[WDSS][wp_mail_failed] ' . $wp_error->get_error_message();
    $data = $wp_error->get_error_data();
    if ($data) {
        $msg .= ' | DATA: ' . print_r($data, true);
    }
    error_log($msg);
    // Store last mail error for AJAX responses
    set_transient('wdss_last_mail_error', $msg, 5 * MINUTE_IN_SECONDS);
}, 10, 1);



WDSS29_Email_Templates::boot();

endif;
