<?php
/**
 * Plugin Name: HDL STBY Mail Guard (whitelist)
 * Description: Staging outbound-mail guard. Delivers wp_mail() only to whitelisted team/QA inboxes; everything else is redirected to the catcher inbox and logged. Inert on LIVE.
 *
 * DEPLOY TARGET: /var/www/html/wp-content/mu-plugins/ on STBY ONLY.
 * This repo copy (hdl-longevity-v2/mu-plugins/) is the tracked source —
 * WordPress never auto-loads it from here. Replaces the 2026-04-21
 * hdlv2-stby-mail-redirect.php toggle plugin (whose pass-through mode is
 * how STBY emailed two real clients on 2026-07-14).
 *
 * Design (belt — the paired brace is HDLV2_Env::gate() in the V2 plugin):
 *  - ACTIVE unless the box is provably LIVE: WP_ENVIRONMENT_TYPE resolves
 *    to 'production' AND home host is healthdatalab.net. A re-clone that
 *    loses the staging constant keeps its stby.* host → stays guarded
 *    (fail-CLOSED). There is deliberately NO pass-through toggle.
 *  - To: recipients not on the whitelist are dropped. If any survive, the
 *    mail delivers to them with a "[STBY-FILTERED -> dropped]" subject tag;
 *    if none survive it goes to the catcher with "[STBY-BLOCKED -> originals]".
 *  - Cc/Bcc are stripped whenever the guard is active (a real address in
 *    Cc must not ride a whitelisted To).
 *  - Every drop is error_log'd ([HDL-STBY-MAIL-GUARD]) and kept in the
 *    hdl_stby_mail_guard_log option (last 200) for quick inspection:
 *      wp option get hdl_stby_mail_guard_log --format=json
 *
 * Whitelist override: define HDL_STBY_MAIL_WHITELIST as a comma-separated
 * list in wp-config.php (replaces the default), or filter
 * 'hdl_stby_mail_guard_whitelist'. Catcher override: HDL_STBY_MAIL_CATCHER.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'HDL_STBY_MAIL_CATCHER' ) ) {
    define( 'HDL_STBY_MAIL_CATCHER', 'team+stby@irislab.com' );
}

/**
 * Provably LIVE? Both signals must hold — mirrors HDLV2_Env::is_live()
 * but self-contained (mu-plugins load before, and independent of, the
 * V2 plugin).
 */
function hdl_stby_mail_guard_is_live() {
    $env  = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
    $host = strtolower( (string) parse_url( home_url(), PHP_URL_HOST ) );
    return 'production' === $env
        && in_array( $host, array( 'healthdatalab.net', 'www.healthdatalab.net' ), true );
}

/**
 * Deliverable inboxes on staging: the team's own + QA identities.
 * Real client addresses must never match anything here.
 */
function hdl_stby_mail_guard_whitelist() {
    if ( defined( 'HDL_STBY_MAIL_WHITELIST' ) && HDL_STBY_MAIL_WHITELIST ) {
        $list = array_filter( array_map( 'trim', explode( ',', HDL_STBY_MAIL_WHITELIST ) ) );
    } else {
        $list = array(
            'team@irislab.com',            'team+*@irislab.com',
            'office@healthdatalab.com',    'office+*@healthdatalab.com',
            '260128vm@gmail.com',          '260128vm+*@gmail.com',
            'gemmier21@gmail.com',         'gemmier21+*@gmail.com',
            'matthewdhaemer@gmail.com',    'matthewdhaemer+*@gmail.com',
        );
    }
    if ( function_exists( 'apply_filters' ) ) {
        $list = apply_filters( 'hdl_stby_mail_guard_whitelist', $list );
    }
    return $list;
}

/**
 * Pull the bare address out of "Display Name <addr>" / bare forms.
 * Unparseable input returns '' — which never matches a whitelist entry
 * (fail-closed).
 */
function hdl_stby_mail_guard_extract_email( $recipient ) {
    $recipient = trim( (string) $recipient );
    if ( preg_match( '/<([^<>]+)>\s*$/', $recipient, $m ) ) {
        $recipient = trim( $m[1] );
    }
    if ( ! filter_var( $recipient, FILTER_VALIDATE_EMAIL ) ) {
        return '';
    }
    return strtolower( $recipient );
}

/**
 * Case-insensitive match with '*' as the only wildcard (everything else
 * is literal — no regex smuggling).
 */
function hdl_stby_mail_guard_match( $email, $pattern ) {
    $email = strtolower( trim( (string) $email ) );
    if ( '' === $email ) {
        return false;
    }
    $regex = '/^' . str_replace( '\*', '.*', preg_quote( strtolower( trim( (string) $pattern ) ), '/' ) ) . '$/';
    return 1 === preg_match( $regex, $email );
}

/**
 * Split a To value (array or comma string) into keep/dropped against the
 * whitelist. Kept entries preserve their original form (display names
 * survive); dropped entries are reported as bare addresses where
 * parseable, original strings otherwise.
 *
 * @return array{keep: array, dropped: array}
 */
function hdl_stby_mail_guard_decide( $to, $whitelist ) {
    $entries = is_array( $to ) ? $to : explode( ',', (string) $to );
    $keep    = array();
    $dropped = array();

    foreach ( $entries as $entry ) {
        $entry = trim( (string) $entry );
        if ( '' === $entry ) {
            continue;
        }
        $email = hdl_stby_mail_guard_extract_email( $entry );
        $ok    = false;
        foreach ( $whitelist as $pattern ) {
            if ( hdl_stby_mail_guard_match( $email, $pattern ) ) {
                $ok = true;
                break;
            }
        }
        if ( $ok ) {
            $keep[] = $entry;
        } else {
            $dropped[] = '' !== $email ? $email : $entry;
        }
    }

    return array( 'keep' => $keep, 'dropped' => $dropped );
}

/**
 * Record a guard intervention: PHP error log + capped option ring buffer.
 */
function hdl_stby_mail_guard_log( $event ) {
    error_log( '[HDL-STBY-MAIL-GUARD] ' . wp_json_encode_compat( $event ) );

    $buf = get_option( 'hdl_stby_mail_guard_log', array() );
    if ( ! is_array( $buf ) ) {
        $buf = array();
    }
    $event['t'] = gmdate( 'Y-m-d H:i:s' );
    $buf[]      = $event;
    if ( count( $buf ) > 200 ) {
        $buf = array_slice( $buf, -200 );
    }
    update_option( 'hdl_stby_mail_guard_log', $buf, false );
}

/** json_encode via WP when available (mu-plugin may run in bare tests). */
function wp_json_encode_compat( $data ) {
    return function_exists( 'wp_json_encode' ) ? wp_json_encode( $data ) : json_encode( $data );
}

/**
 * The wp_mail filter. Priority PHP_INT_MAX — the guard runs LAST so no
 * later filter can re-add a real recipient after the whitelist decision;
 * wp_mail() consumes the filtered args directly after this.
 */
function hdl_stby_mail_guard_filter( $args ) {
    if ( ! is_array( $args ) ) {
        return $args;
    }
    if ( hdl_stby_mail_guard_is_live() ) {
        return $args;
    }

    $subject  = isset( $args['subject'] ) ? (string) $args['subject'] : '';
    $decision = hdl_stby_mail_guard_decide( $args['to'] ?? '', hdl_stby_mail_guard_whitelist() );

    // Cc/Bcc could smuggle a real address past the To check — strip them
    // whenever the guard is active, whitelisted To or not.
    $stripped_headers = array();
    if ( ! empty( $args['headers'] ) ) {
        $headers = is_array( $args['headers'] )
            ? $args['headers']
            : preg_split( '/\r?\n/', (string) $args['headers'] );
        $kept_headers = array();
        foreach ( (array) $headers as $h ) {
            if ( preg_match( '/^\s*(cc|bcc)\s*:/i', (string) $h ) ) {
                $stripped_headers[] = trim( (string) $h );
            } else {
                $kept_headers[] = $h;
            }
        }
        $args['headers'] = array_values( $kept_headers );
    }

    if ( empty( $decision['dropped'] ) ) {
        if ( $stripped_headers ) {
            hdl_stby_mail_guard_log( array(
                'action'  => 'cc-bcc-stripped',
                'to'      => $decision['keep'],
                'headers' => $stripped_headers,
                'subject' => $subject,
            ) );
        }
        return $args; // Every recipient whitelisted — deliver as-is.
    }

    $dropped_list = implode( ', ', $decision['dropped'] );

    if ( ! empty( $decision['keep'] ) ) {
        $args['to']      = $decision['keep'];
        $args['subject'] = '[STBY-FILTERED -> ' . $dropped_list . '] ' . $subject;
        $action          = 'filtered';
    } else {
        $args['to']      = HDL_STBY_MAIL_CATCHER;
        $args['subject'] = '[STBY-BLOCKED -> ' . $dropped_list . '] ' . $subject;
        $action          = 'blocked';
    }

    hdl_stby_mail_guard_log( array(
        'action'  => $action,
        'dropped' => $decision['dropped'],
        'kept'    => $decision['keep'],
        'headers' => $stripped_headers,
        'subject' => $subject,
    ) );

    return $args;
}

add_filter( 'wp_mail', 'hdl_stby_mail_guard_filter', PHP_INT_MAX );
