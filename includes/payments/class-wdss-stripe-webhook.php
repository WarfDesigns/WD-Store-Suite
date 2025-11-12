<?php
/**
 * WDSS — Stripe Webhook Endpoint
 * URL: /wp-json/wdss/v1/stripe
 *
 * Handles:
 *  - checkout.session.completed
 *  - payment_intent.succeeded
 *  - charge.succeeded   (fallback)
 *
 * It:
 *  - Resolves order_id from metadata/client_reference_id
 *  - Marks order PAID
 *  - Fires wdss_email_trigger('order.paid', $payload)
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! class_exists('WDSS29_Stripe_Webhook') ) :

class WDSS29_Stripe_Webhook {

    public static function boot() {
        add_action('rest_api_init', [__CLASS__, 'register_route']);
    }

    public static function register_route() {
        register_rest_route(
            'wdss/v1',
            '/stripe',
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public static function handle( WP_REST_Request $req ) {
        $settings = get_option('wdss29_settings', ['']);
        $sk       = isset($settings['stripe_sk'])   ? trim($settings['stripe_sk'])   : '';
        $whsec    = isset($settings['stripe_whsec'])? trim($settings['stripe_whsec']): '';

        // Read payload & signature
        $payload    = $req->get_body();
        $sig_header = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';

        // If Stripe SDK isn’t loaded, fail gracefully
        if ( ! class_exists('\Stripe\Stripe') ) {
            error_log('[WDSS] Webhook error: Stripe SDK not loaded.');
            return self::ok('sdk_missing');
        }

        \Stripe\Stripe::setApiKey($sk);

        $event = null;

        // Verify signature if secret provided; otherwise attempt to decode (dev mode).
        try {
            if ( $whsec ) {
                $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $whsec);
            } else {
                $event = json_decode($payload);
                if ( ! $event ) throw new \Exception('Invalid JSON and no whsec');
            }
        } catch ( \Exception $e ) {
            error_log('[WDSS] Webhook signature/parse failed: ' . $e->getMessage());
            return new WP_REST_Response(['ok'=>false,'reason'=>'signature_failed'], 400);
        }

        $type = is_object($event) && isset($event->type) ? $event->type : '';
        $obj  = is_object($event) && isset($event->data->object) ? $event->data->object : null;

        // Try to derive order_id and customer email from the object
        $order_id = 0;
        $email    = '';

        if ( $obj ) {
            // Common places Stripe stores metadata/order ids
            if ( ! $order_id && isset($obj->metadata->order_id) ) {
                $order_id = (int) $obj->metadata->order_id;
            }
            if ( ! $order_id && isset($obj->client_reference_id) ) {
                $order_id = (int) $obj->client_reference_id;
            }
            if ( ! $email && isset($obj->customer_details->email) ) {
                $email = sanitize_email($obj->customer_details->email);
            }
            if ( ! $email && isset($obj->receipt_email) ) {
                $email = sanitize_email($obj->receipt_email);
            }
            // payment_intent expanded later if needed
        }

        // For payment_intent.succeeded / charge.succeeded, try to expand metadata
        if ( ! $order_id && $type !== 'checkout.session.completed' && isset($obj->payment_intent) ) {
            try {
                $pi = \Stripe\PaymentIntent::retrieve($obj->payment_intent);
                if ( isset($pi->metadata->order_id) ) {
                    $order_id = (int) $pi->metadata->order_id;
                }
                if ( ! $email && isset($pi->receipt_email) ) {
                    $email = sanitize_email($pi->receipt_email);
                }
            } catch ( \Exception $e ) {
                error_log('[WDSS] Webhook: PI retrieve failed: ' . $e->getMessage());
            }
        }

        if ( ! $order_id ) {
            // As a last resort, look at expanded session if available
            if ( $type === 'checkout.session.completed' && isset($obj->payment_intent) ) {
                try {
                    $session = \Stripe\Checkout\Session::retrieve($obj->id, ['expand'=>['payment_intent']]);
                    if ( isset($session->metadata->order_id) ) {
                        $order_id = (int) $session->metadata->order_id;
                    } elseif ( isset($session->client_reference_id) ) {
                        $order_id = (int) $session->client_reference_id;
                    } elseif ( isset($session->payment_intent->metadata->order_id) ) {
                        $order_id = (int) $session->payment_intent->metadata->order_id;
                    }
                    if ( ! $email && isset($session->customer_details->email) ) {
                        $email = sanitize_email($session->customer_details->email);
                    }
                } catch ( \Exception $e ) {
                    error_log('[WDSS] Webhook: session expand failed: ' . $e->getMessage());
                }
            }
        }

        if ( ! $order_id ) {
            do_action('wdss_email_trigger', 'order.debug', [
                'note' => 'webhook_no_order_id',
                'type' => $type
            ]);
            return self::ok('no_order');
        }

        // Mark PAID and emit emails
        $hints = [
            'source'      => 'webhook',
            'event_type'  => $type,
            'customer_email' => $email,
        ];

        if ( function_exists('wdss29_set_order_status') ) {
            wdss29_set_order_status($order_id, 'paid', $hints);
        } else {
            update_post_meta((int)$order_id, '_wdss_status', 'paid');
        }

        if ( function_exists('wdss29_emit_order_paid') ) {
            wdss29_emit_order_paid($order_id, $hints);
        } else {
            $payload = [
                'order_id'       => (int)$order_id,
                'order_number'   => (string)$order_id,
                'order_status'   => 'paid',
                'order_total'    => (float) get_post_meta((int)$order_id, '_wdss_total', true),
                'currency'       => get_option('wdss_currency','USD'),
                'customer_email' => $email,
                '_idem_key'      => 'order.paid|' . (int)$order_id,
            ];
            do_action('wdss_email_trigger', 'order.paid', $payload);
        }

        return self::ok('processed');
    }

    private static function ok( $reason ) {
        return new WP_REST_Response(['ok'=>true,'reason'=>$reason], 200);
    }
}

endif;

WDSS29_Stripe_Webhook::boot();
