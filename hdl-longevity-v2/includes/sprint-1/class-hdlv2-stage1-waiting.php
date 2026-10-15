<?php
/**
 * Stage 1 holding list replay (v0.48.16).
 *
 * When the server was busy, the widget kept the visitor's answers as a Brevo
 * contact on the "Stage 1 waiting" list (netlify/functions/stage1-hold.js).
 * Every five minutes, when not busy, this pulls up to 50 of them, posts each
 * through the real lead handler in-process (so the lead is written exactly as
 * a direct POST writes it, and the drain sends the PDF), then takes the
 * contact off the list and blanks the stored answers.
 *
 * Off unless wp-config defines HDLV2_BREVO_API_KEY and
 * HDLV2_BREVO_WAITING_LIST_ID. The key is never logged.
 *
 * @package HDL_Longevity_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Stage1_Waiting {

    const API      = 'https://api.brevo.com/v3';
    const MAX_DAYS = 14; // answers held longer than this are dropped, not replayed

    public static function register_hooks() {
        add_action( 'hdlv2_stage1_waiting_replay', array( __CLASS__, 'run_replay' ) );
    }

    public static function configured() {
        return defined( 'HDLV2_BREVO_API_KEY' ) && '' !== (string) HDLV2_BREVO_API_KEY
            && defined( 'HDLV2_BREVO_WAITING_LIST_ID' ) && '' !== (string) HDLV2_BREVO_WAITING_LIST_ID;
    }

    public static function run_replay() {
        if ( ! self::configured() || HDLV2_Stage1_Drain::is_busy() ) {
            return;
        }
        $list = (int) HDLV2_BREVO_WAITING_LIST_ID;
        $res  = self::brevo( 'GET', '/contacts/lists/' . $list . '/contacts?limit=50&offset=0&sort=asc' );
        $code = wp_remote_retrieve_response_code( $res );
        if ( 200 !== $code ) {
            error_log( '[HDLV2 stage1-waiting] list read failed: HTTP ' . (int) $code . ' — next tick retries.' );
            return;
        }
        $body = json_decode( wp_remote_retrieve_body( $res ), true );
        $handler = new HDLV2_Widget_Config();

        foreach ( (array) ( $body['contacts'] ?? array() ) as $contact ) {
            $email  = (string) ( $contact['email'] ?? '' );
            $attrs  = (array) ( $contact['attributes'] ?? array() );
            $masked = HDLV2_Widget_Config::mask_email( $email );
            if ( '' === $email ) {
                continue;
            }

            $held_at = strtotime( (string) ( $attrs['HDL_STAGE1_HELD_AT'] ?? '' ) );
            if ( $held_at && $held_at < time() - self::MAX_DAYS * DAY_IN_SECONDS ) {
                error_log( '[HDLV2 stage1-waiting] held more than ' . self::MAX_DAYS . ' days — dropped ' . $masked );
                if ( ! self::release( $list, $email ) ) return;
                continue;
            }

            $payload = json_decode( (string) ( $attrs['HDL_STAGE1_PAYLOAD'] ?? '' ), true );
            if ( ! is_array( $payload ) ) {
                error_log( '[HDLV2 stage1-waiting] unreadable answers — dropped ' . $masked );
                if ( ! self::release( $list, $email ) ) return;
                continue;
            }
            // A personal link is never replayed: the holding function strips it, and so do we.
            unset( $payload['invite_token'] );

            $req = new WP_REST_Request( 'POST', '/hdl-v2/v1/widget/lead' );
            $req->set_header( 'Content-Type', 'application/json' );
            $req->set_body( wp_json_encode( $payload ) );
            try {
                $status = self::status_of( $handler->rest_capture_lead( $req ) );
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2 stage1-waiting] handler threw for ' . $masked . ' — kept for the next tick.' );
                continue;
            }
            if ( 429 === $status || $status >= 500 ) {
                continue; // busy or a fault: the next tick tries again
            }
            if ( $status >= 400 ) {
                error_log( '[HDLV2 stage1-waiting] answers refused (HTTP ' . $status . ') — dropped ' . $masked );
            } else {
                HDLV2_Stage1_Drain::bump( 'hdlv2_stage1_replayed_' );
            }
            if ( ! self::release( $list, $email ) ) {
                return;
            }
        }
    }

    /** Off the list, answers blanked. False on a Brevo 429 (the tick stops). */
    private static function release( $list, $email ) {
        $r = self::brevo( 'POST', '/contacts/lists/' . (int) $list . '/contacts/remove', array( 'emails' => array( $email ) ) );
        if ( 429 === wp_remote_retrieve_response_code( $r ) ) {
            error_log( '[HDLV2 stage1-waiting] Brevo 429 — tick stopped.' );
            return false;
        }
        self::brevo( 'PUT', '/contacts/' . rawurlencode( $email ), array( 'attributes' => array( 'HDL_STAGE1_PAYLOAD' => '' ) ) );
        return true;
    }

    private static function api_key() {
        return defined( 'HDLV2_BREVO_API_KEY' ) ? (string) HDLV2_BREVO_API_KEY : '';
    }

    private static function brevo( $method, $path, $body = null ) {
        $key  = self::api_key();
        $args = array(
            'method'  => $method,
            'timeout' => 15,
            'headers' => array( 'api-key' => $key, 'accept' => 'application/json', 'content-type' => 'application/json' ),
        );
        if ( null !== $body ) {
            $args['body'] = wp_json_encode( $body );
        }
        return 'GET' === $method ? wp_remote_get( self::API . $path, $args ) : wp_remote_request( self::API . $path, $args );
    }

    /** HTTP status of whatever the lead handler returned. */
    private static function status_of( $r ) {
        if ( $r instanceof WP_Error ) {
            return (int) ( $r->get_error_data()['status'] ?? 500 );
        }
        return $r instanceof WP_REST_Response ? (int) $r->get_status() : 200;
    }
}
