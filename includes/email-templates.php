<?php
/**
 * WD Store Suite - Email Templates & Dispatch (stable, idempotent)
 * Author: Warf Designs LLC
 * Version: 2.0.1
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function wdss29_get_email_templates() {
    $templates = get_option( 'wdss29_email_templates', array() );
    return is_array( $templates ) ? $templates : array();
}

function wdss29_update_email_templates( $templates ) {
    if ( ! is_array( $templates ) ) $templates = array();
    update_option( 'wdss29_email_templates', $templates, false );
}

/** Replace {{key}} and {key} with values from $ctx */
function wdss29_render_email_placeholders( $text, $ctx ) {
    if ( ! is_string( $text ) ) return $text;
    if ( ! is_array( $ctx ) ) $ctx = array();
    foreach ( $ctx as $k => $v ) {
        $rep  = is_scalar($v) ? (string) $v : wp_json_encode( $v );
        $text = str_replace( '{{'.$k.'}}', $rep, $text );
        $text = str_replace( '{'.$k.'}',  $rep, $text );
    }
    return $text;
}

function wdss29_build_headers( $template ) {
    $headers = array();

    if ( ! empty( $template['headers'] ) && is_array( $template['headers'] ) ) {
        foreach ( $template['headers'] as $line ) {
            $line = trim( (string) $line );
            if ( $line !== '' ) $headers[] = $line;
        }
    }

    if ( ! empty( $template['reply_to'] ) && is_email( $template['reply_to'] ) ) {
        $headers[] = 'Reply-To: ' . $template['reply_to'];
    }

    foreach ( array('cc' => 'Cc', 'bcc' => 'Bcc') as $k => $label ) {
        if ( ! empty( $template[$k] ) ) {
            $headers[] = $label . ': ' . $template[$k];
        }
    }

    $has_from = false;
    foreach ( $headers as $h ) {
        if ( stripos( $h, 'from:' ) === 0 ) { $has_from = true; break; }
    }
    if ( ! $has_from ) {
        $domain = parse_url( home_url(), PHP_URL_HOST );
        $from   = 'no-reply@' . ( $domain ?: 'localhost' );
        $headers[] = 'From: ' . get_bloginfo('name') . ' <' . $from . '>';
    }

    $headers[] = 'Content-Type: text/html; charset=UTF-8';

    return $headers;
}

function wdss29_resolve_recipient_list( $template, $ctx, $to_override = '' ) {
    $targets = array();

    if ( is_string( $to_override ) && $to_override !== '' ) {
        $targets = array( sanitize_email( $to_override ) );
    } else {
        $who = isset($template['recipient']) ? $template['recipient'] : 'customer';
        switch ( $who ) {
            case 'admin':
                $targets = array( get_option( 'admin_email' ) );
                break;
            case 'custom':
                $raw = (string) ( $template['custom_email'] ?? '' );
                $list = array_map( 'trim', explode( ',', $raw ) );
                $targets = array_map( 'sanitize_email', $list );
                break;
            case 'customer':
            default:
                $email = sanitize_email( $ctx['customer_email'] ?? '' );
                if ( ! $email ) $email = get_option('admin_email'); // fallback for diagnostics
                if ( $email ) $targets = array( $email );
                break;
        }
    }

    $targets = array_values( array_unique( array_filter( $targets, 'is_email' ) ) );
    return $targets;
}

function wdss29_email_sent_flag_key( $template_key, $ctx ) {
    $order_id = isset($ctx['order_id']) ? (string) $ctx['order_id'] : 'no-order';
    $trigger  = isset($ctx['event']) ? (string) $ctx['event'] : 'manual';
    $hash = md5( $template_key . '|' . $order_id . '|' . $trigger );
    return 'wdss29_sent_' . $hash;
}

function wdss29_has_already_sent( $template_key, $ctx, $permanent = false ) {
    $flag = wdss29_email_sent_flag_key( $template_key, $ctx );
    $row  = get_option( $flag, array() );
    if ( empty( $row ) || ! is_array( $row ) ) return false;
    if ( $permanent ) return true;
    $ts = isset($row['ts']) ? intval($row['ts']) : 0;
    return ( $ts && ( time() - $ts ) < DAY_IN_SECONDS );
}

function wdss29_mark_sent( $template_key, $ctx ) {
    $flag = wdss29_email_sent_flag_key( $template_key, $ctx );
    update_option( $flag, array( 'ts' => time(), 'ctx' => $ctx ), false );
}

function wdss29_log_email_event( $type, $template_key, $to, $extra = array() ) {
    $log = get_option( 'wdss29_email_log', array() );
    if ( ! is_array( $log ) ) $log = array();
    $log[] = array(
        'time' => current_time( 'mysql', 1 ),
        'type' => $type,
        'tpl'  => $template_key,
        'to'   => $to,
        'meta' => $extra,
    );
    if ( count( $log ) > 200 ) $log = array_slice( $log, -200 );
    update_option( 'wdss29_email_log', $log, false );
}

function wdss29_send_templated_email( $template_key, $context = array(), $to_override = '', $force_send = false ) {
    $templates = wdss29_get_email_templates();
    if ( empty( $templates[ $template_key ] ) ) {
        return false;
    }
    $template = $templates[ $template_key ];

    if ( ! $force_send && wdss29_has_already_sent( $template_key, $context ) ) {
        wdss29_log_email_event( 'skip-duplicate', $template_key, '', array( 'ctx' => $context ) );
        return false;
    }

    $subject = wdss29_render_email_placeholders( (string) ( $template['subject'] ?? '' ), $context );
    $body    = wdss29_render_email_placeholders( (string) ( $template['body'] ?? '' ),    $context );
    $headers = wdss29_build_headers( $template );
    $to_list = wdss29_resolve_recipient_list( $template, $context, $to_override );

    if ( empty( $to_list ) ) {
        wdss29_log_email_event( 'failed-no-recipient', $template_key, '', array( 'ctx' => $context ) );
        return false;
    }

    $sent_all = true;
    foreach ( $to_list as $to ) {
        $ok = wp_mail( $to, $subject, wdss29_wrap_html_email( $body ), $headers );
        $sent_all = $sent_all && $ok;
        wdss29_log_email_event( $ok ? 'sent' : 'failed', $template_key, $to, array( 'ctx' => $context ) );
    }

    if ( $sent_all ) {
        wdss29_mark_sent( $template_key, $context );
    }

    return $sent_all;
}

function wdss29_wrap_html_email( $content ) {
    $styles = 'font-family: Arial, Helvetica, sans-serif; font-size:14px; color:#222;';
    return '<!doctype html><html><body style="'.$styles.'">' . $content . '</body></html>';
}

function wdss29_email_on_order_created( $order_id, $payload = array() ) {
    $ctx = array_merge( array( 'order_id' => $order_id, 'event' => 'order.created' ), (array) $payload );
    wdss29_send_templated_email( 'order_created_customer', $ctx );
    wdss29_send_templated_email( 'order_created_admin',    $ctx );
}

function wdss29_email_on_order_paid( $order_id, $payload = array() ) {
    $ctx = array_merge( array( 'order_id' => $order_id, 'event' => 'order.paid' ), (array) $payload );
    wdss29_send_templated_email( 'order_paid_customer', $ctx );
    wdss29_send_templated_email( 'order_paid_admin',    $ctx );
}

function wdss29_email_on_status_changed( $order_id, $new_status, $payload = array() ) {
    $ctx = array_merge( array( 'order_id' => $order_id, 'event' => 'order.status_changed', 'order_status' => $new_status ), (array) $payload );
    wdss29_send_templated_email( 'order_status_changed', $ctx );
}

add_action( 'wp_mail_failed', function( $wp_error ) {
    $data = is_object($wp_error) && method_exists($wp_error, 'get_error_data') ? (array) $wp_error->get_error_data() : array();
    $to   = isset($data['to']) ? $data['to'] : '';
    wdss29_log_email_event( 'phpmailer-failed', 'n/a', $to, array( 'err' => is_object($wp_error) ? $wp_error->get_error_message() : 'unknown' ) );
}, 10, 1 );
