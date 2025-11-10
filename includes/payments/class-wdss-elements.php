<?php
/**
 * WDSS — Stripe Elements on-site checkout
 * Shortcode: [wdss_stripe_elements_checkout order_id="123"]
 *
 * Flow:
 *  - Renders a card form (Stripe Elements) on your site
 *  - Calls REST to create PaymentIntent with metadata.order_id + receipt_email
 *  - Client confirms PI; on success we redirect to signed success URL
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WDSS29_Elements_Checkout' ) ) :

class WDSS29_Elements_Checkout {

    /** @var self */
    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_rest' ) );
        add_shortcode( 'wdss_stripe_elements_checkout', array( $this, 'shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /** Load JS + expose settings */
    public function enqueue_assets() {
        if ( ! is_singular() ) return;
        global $post;
        if ( ! $post ) return;
        // Only enqueue when shortcode is on the page
        if ( has_shortcode( (string) $post->post_content, 'wdss_stripe_elements_checkout' ) ) {
            // Stripe.js
            wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true );

            // Our handler
            wp_enqueue_script(
                'wdss-elements',
                plugins_url( '../../assets/js/wdss-elements.js', __FILE__ ),
                array( 'stripe-js' ),
                '2.9',
                true
            );

            $settings = get_option( 'wdss29_settings', array() );
            $pk = isset( $settings['stripe_pk'] ) ? trim( $settings['stripe_pk'] ) : '';
            $thankyou_page_id = isset($settings['thankyou_page_id']) ? (int)$settings['thankyou_page_id'] : 0;
            $base_success = $thankyou_page_id ? get_permalink($thankyou_page_id) : home_url('/thank-you/');

            wp_localize_script( 'wdss-elements', 'WDSS_ELEMENTS', array(
                'pk'          => $pk,
                'rest'        => array(
                    'root'  => esc_url_raw( rest_url( 'wdss29/v1/' ) ),
                    'nonce' => wp_create_nonce( 'wp_rest' ),
                ),
                'successBase' => $base_success,
                'loggedIn'    => is_user_logged_in(),
                'user'        => array(
                    'email' => is_user_logged_in() ? wp_get_current_user()->user_email : '',
                    'id'    => get_current_user_id(),
                ),
            ) );
        }
    }

    /** Shortcode renderer */
    public function shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'order_id' => 0,
        ), $atts, 'wdss_stripe_elements_checkout' );

        $order_id = (int) $atts['order_id'];
        if ( ! $order_id ) {
            return '<div class="wdss-alert wdss-alert-error">Order ID missing.</div>';
        }

        if ( ! function_exists('wdss29_get_order') ) {
            return '<div class="wdss-alert wdss-alert-error">Orders API not available.</div>';
        }

        $order = wdss29_get_order( $order_id );
        if ( ! is_array( $order ) ) {
            return '<div class="wdss-alert wdss-alert-error">Order not found.</div>';
        }

        $currency = ! empty($order['currency']) ? strtoupper($order['currency']) : 'USD';
        $total    = isset($order['total']) ? (float) $order['total'] : 0.00;

        ob_start();
        ?>
        <div class="wdss-elements-checkout" data-order="<?php echo esc_attr($order_id); ?>">
            <h3>Pay for Order #<?php echo esc_html( !empty($order['number']) ? $order['number'] : $order_id ); ?></h3>
            <p>Total: <strong><?php echo esc_html( $currency . ' ' . number_format( $total, 2 ) ); ?></strong></p>

            <form id="wdss-elements-form" onsubmit="return false;">
                <div class="wdss-field">
                    <label for="wdss_name">Name on card</label>
                    <input id="wdss_name" name="name" type="text" placeholder="Jane Doe" required>
                </div>

                <div class="wdss-field">
                    <label for="wdss_email">Email</label>
                    <input id="wdss_email" name="email" type="email" placeholder="you@example.com" required value="<?php echo is_user_logged_in() ? esc_attr(wp_get_current_user()->user_email) : ''; ?>">
                </div>

                <div class="wdss-field">
                    <label>Card</label>
                    <div id="wdss-card-element" class="wdss-card-element"></div>
                </div>

                <button id="wdss_pay_btn" type="submit">Pay Now</button>
                <div id="wdss_error" class="wdss-error" role="alert" style="margin-top:10px;"></div>
            </form>

            <style>
                .wdss-field{ margin-bottom:12px; }
                .wdss-card-element{ padding:10px; border:1px solid #ddd; border-radius:6px; }
                #wdss_pay_btn{ padding:10px 16px; border:0; border-radius:8px; cursor:pointer; }
                .wdss-error{ color:#b00020; }
            </style>

            <script>
                window.WDSS_ORDER_ID = <?php echo (int) $order_id; ?>;
                window.WDSS_CURRENCY = <?php echo json_encode( $currency ); ?>;
            </script>
        </div>
        <?php
        return ob_get_clean();
    }

    /** REST endpoints */
    public function register_rest() {
        register_rest_route( 'wdss29/v1', '/stripe/pi/create', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'rest_create_pi' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /** Create PaymentIntent with metadata.order_id + receipt_email */
    public function rest_create_pi( WP_REST_Request $req ) {
        $order_id = (int) $req->get_param('order_id');
        $email    = sanitize_email( $req->get_param('email') );
        if ( ! $order_id ) return new WP_REST_Response( array('ok'=>false,'error'=>'missing_order_id'), 400 );
        if ( ! is_email( $email ) ) return new WP_REST_Response( array('ok'=>false,'error'=>'invalid_email'), 400 );

        if ( ! function_exists('wdss29_get_order') ) {
            return new WP_REST_Response( array('ok'=>false,'error'=>'orders_api_unavailable'), 400 );
        }
        $order = wdss29_get_order( $order_id );
        if ( ! is_array($order) ) {
            return new WP_REST_Response( array('ok'=>false,'error'=>'order_not_found'), 404 );
        }

        $settings = get_option('wdss29_settings', array());
        $sk = isset($settings['stripe_sk']) ? trim($settings['stripe_sk']) : '';
        if ( empty($sk) || ! class_exists('\Stripe\Stripe') ) {
            return new WP_REST_Response( array('ok'=>false,'error'=>'stripe_not_configured'), 400 );
        }

        $amount   = (int) round( (float) ($order['total'] ?? 0) * 100 );
        $currency = ! empty($order['currency']) ? strtolower($order['currency']) : 'usd';
        if ( $amount <= 0 ) {
            return new WP_REST_Response( array('ok'=>false,'error'=>'zero_amount'), 400 );
        }

        // Boot Stripe and create PI
        \Stripe\Stripe::setApiKey( $sk );

        try {
            $pi = \Stripe\PaymentIntent::create(array(
                'amount'                     => $amount,
                'currency'                   => $currency,
                'automatic_payment_methods'  => array('enabled' => true),
                'metadata'                   => array( 'order_id' => (string) $order_id ),
                'receipt_email'              => $email,
            ));

            // Save email to order if missing (helps downstream automations)
            if ( empty($order['customer_email']) ) {
                wdss29_update_order( $order_id, array( 'customer_email' => $email ) );
            }

            return new WP_REST_Response( array(
                'ok'            => true,
                'client_secret' => $pi->client_secret,
            ), 200 );
        } catch ( \Exception $e ) {
            return new WP_REST_Response( array(
                'ok'    => false,
                'error' => 'stripe_error',
                'msg'   => $e->getMessage(),
            ), 400 );
        }
    }
}

endif;

// Bootstrap
WDSS29_Elements_Checkout::instance();
