<?php
/**
 * HDL V2 — a request to delete one's data (v0.48.63, switch hdlv2_ff_data_erasure, off by default).
 *
 * WordPress's own request flow, worded for HealthDataLab:
 *   1 a signed-in client asks from /my-dashboard/ (POST /privacy/erase-request, their own address only);
 *   2 WordPress emails a link that works for one day (wp-login.php?action=confirmaction);
 *   3 the person confirms: WordPress's admin notice goes to our alert addresses instead, with the
 *     date one month on and the outside copies to remove by hand;
 *   4 an administrator presses Erase on Tools → Erase Personal Data (HDLV2_Privacy_Eraser does it);
 *   5 WordPress emails the person that it is done; each of their practitioners is told here.
 * People without an account email us; an administrator adds the request on the same Tools screen,
 * which sends the same link. A confirmed request still open after 21 days is mailed once more.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Privacy_Requests {

    const ACTION = 'remove_personal_data';
    const SENT   = 'Check your inbox. The link in the email confirms it is you. It works for one day.';
    const HTML   = 'Content-Type: text/html; charset=UTF-8';
    const NUDGE  = '_hdlv2_erase_reminded';

    public static function init() {
        if ( ! HDLV2_Privacy_Eraser::enabled() ) {
            return;
        }
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_filter( 'do_shortcode_tag', array( __CLASS__, 'dashboard_control' ), 10, 2 );
        // WordPress's emails and page for an erase request, in our words.
        add_filter( 'user_request_action_email_subject', array( __CLASS__, 'confirm_subject' ), 10, 3 );
        add_filter( 'user_request_action_email_content', array( __CLASS__, 'confirm_content' ), 10, 2 );
        add_filter( 'user_request_action_email_headers', array( __CLASS__, 'html_headers' ), 10, 5 );
        add_filter( 'user_request_action_confirmed_message', array( __CLASS__, 'confirmed_message' ), 10, 2 );
        add_filter( 'user_erasure_fulfillment_email_subject', array( __CLASS__, 'done_subject' ), 10, 3 );
        add_filter( 'user_erasure_fulfillment_email_content', array( __CLASS__, 'done_content' ), 10, 2 );
        add_filter( 'user_erasure_fulfillment_email_headers', array( __CLASS__, 'html_headers' ), 10, 5 );
        add_filter( 'user_request_confirmed_email_to', array( __CLASS__, 'warn_to' ), 10, 2 );
        add_filter( 'user_request_confirmed_email_subject', array( __CLASS__, 'warn_subject' ), 10, 3 );
        add_filter( 'user_request_confirmed_email_content', array( __CLASS__, 'warn_content' ), 10, 2 );
        add_action( 'wp_privacy_personal_data_erased', array( __CLASS__, 'erased' ) );
        add_action( 'hdlv2_pending_leads_cleanup', array( __CLASS__, 'remind_open' ) ); // a daily cron already scheduled
    }

    // ── 1. the request ──────────────────────────────────────────────

    public static function register_routes() {
        register_rest_route( 'hdl-v2/v1', '/privacy/erase-request', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_request' ),
            'permission_callback' => array( __CLASS__, 'can_request' ),
        ) );
    }

    /** A signed-in client (cookie and REST nonce). A practitioner's or an administrator's data is handled by hand. */
    public static function can_request() {
        $uid = get_current_user_id();
        return $uid > 0 && ! user_can( $uid, 'manage_options' ) && ! HDLV2_Compatibility::is_practitioner( $uid );
    }

    /** For the signed-in user's own address only; the body is never read. Same sentence for a new or a waiting request. */
    public static function rest_request() {
        $email = (string) wp_get_current_user()->user_email;
        $id    = wp_create_user_request( $email, self::ACTION );
        if ( is_wp_error( $id ) && 'duplicate_request' === $id->get_error_code() ) {
            // A request already waits: a pending one gets a fresh link; a confirmed one is with us.
            $id = self::open_request( $email, 'request-pending' );
            if ( ! $id ) {
                return rest_ensure_response( array( 'message' => self::SENT ) );
            }
        }
        if ( is_wp_error( $id ) || is_wp_error( wp_send_user_request( $id ) ) ) {
            return new WP_Error( 'erase_request_failed', 'That did not work. Please try again in a minute.', array( 'status' => 500 ) );
        }
        return rest_ensure_response( array( 'message' => self::SENT ) );
    }

    private static function open_request( $email, $status ) {
        $ids = get_posts( array(
            'post_type'     => 'user_request',
            'post_name__in' => array( self::ACTION ),
            'title'         => $email,
            'post_status'   => $status,
            'fields'        => 'ids',
            'numberposts'   => 1,
        ) );
        return $ids ? (int) $ids[0] : 0;
    }

    /** "Your data" at the foot of /my-dashboard/, after whatever state the dashboard rendered. */
    public static function dashboard_control( $output, $tag ) {
        if ( 'hdlv2_my_dashboard' !== $tag || ! self::can_request() ) {
            return $output;
        }
        wp_enqueue_script( 'hdlv2-privacy-erase', HDLV2_PLUGIN_URL . 'assets/js/hdlv2-privacy-erase.js', array(), HDLV2_VERSION, true );
        wp_localize_script( 'hdlv2-privacy-erase', 'HDLV2_ERASE', array(
            'url'   => rest_url( 'hdl-v2/v1/privacy/erase-request' ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
        ) );
        wp_add_inline_style( 'hdlv2-client-dashboard', self::CSS );
        return $output
            . '<section class="hdlv2-your-data" aria-labelledby="hdlv2-your-data-title">'
            . '<h2 class="hdlv2-your-data-title" id="hdlv2-your-data-title">Your data</h2>'
            . '<div class="hdlv2-your-data-line" data-hdlv2-erase><button type="button" class="hdlv2-your-data-link" data-erase="ask">Delete my data</button></div>'
            . '</section>';
    }

    // DESIGN.md tokens: hairline, teal action, navy text, 40px targets, no rail.
    const CSS = '.hdlv2-your-data{max-width:1100px;margin:48px auto 32px;padding:16px 24px 0;border-top:1px solid #e4e6ea;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#2c3e50}'
        . '.hdlv2-your-data-title{margin:0 0 4px;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:14px;font-weight:600;line-height:1.4;color:#2c3e50}'
        . '.hdlv2-your-data-line{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;font-size:14px;line-height:1.5}'
        . '.hdlv2-your-data-line button{min-height:40px;border-radius:8px;font:500 14px/1.2 Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;cursor:pointer}'
        . '.hdlv2-your-data-line .hdlv2-your-data-link{padding:10px 0;border:0;background:none;color:#3d8da0;text-decoration:underline}'
        . '.hdlv2-your-data-line [data-erase=send]{padding:10px 20px;border:0;background:#3d8da0;color:#fff}'
        . '.hdlv2-your-data-line [data-erase=send]:hover{background:#004F59}'
        . '.hdlv2-your-data-line [data-erase=keep]{padding:10px 20px;border:1px solid #e4e6ea;background:#fff;color:#3d8da0}'
        . '.hdlv2-your-data-line button:focus-visible{outline:2px solid #3d8da0;outline-offset:2px}'
        . '.hdlv2-your-data-line button[disabled]{opacity:.6;cursor:default}'
        . '.hdlv2-your-data-note{flex-basis:100%}.hdlv2-your-data-error{flex-basis:100%;color:#dc2626}'
        . '@media (max-width:640px){.hdlv2-your-data{padding:16px 24px 0}.hdlv2-your-data-line [data-erase=send],.hdlv2-your-data-line [data-erase=keep]{flex:1 1 100%}}';

    // ── 2–5. WordPress's emails and page, for an erase request only ──

    private static function is_erase( $request ) {
        return is_object( $request ) && self::ACTION === ( $request->action_name ?? '' );
    }

    public static function confirm_subject( $subject, $sitename, $data ) {
        return self::is_erase( $data['request'] ?? null ) ? 'Confirm you want your HealthDataLab data deleted' : $subject;
    }

    public static function confirm_content( $content, $data ) {
        return self::is_erase( $data['request'] ?? null ) ? HDLV2_Email_Templates::privacy_confirm( (string) $data['confirm_url'] ) : $content;
    }

    /** Both of WordPress's emails to the person go as HTML (base_layout) when they are ours. */
    public static function html_headers( $headers, $subject, $content, $request_id, $data ) {
        return self::is_erase( $data['request'] ?? null ) ? self::HTML : $headers;
    }

    public static function confirmed_message( $message, $request_id ) {
        return self::is_erase( wp_get_user_request( $request_id ) )
            ? '<p class="success">Thank you, it is confirmed. We will delete your data within one month and email you when it is done.</p>'
            : $message;
    }

    public static function done_subject( $subject, $sitename, $data ) {
        return self::is_erase( $data['request'] ?? null ) ? 'Your HealthDataLab data has been deleted' : $subject;
    }

    public static function done_content( $content, $data ) {
        return self::is_erase( $data['request'] ?? null ) ? HDLV2_Email_Templates::privacy_done() : $content;
    }

    /** The confirm notice goes to the addresses the Stage 1 alert uses (HDLV2_Stage1_Drain::recipients). */
    public static function warn_to( $to, $request ) {
        return self::is_erase( $request ) ? implode( ',', self::alert_to() ) : $to;
    }

    public static function warn_subject( $subject, $sitename, $data ) {
        $r = $data['request'] ?? null;
        return self::is_erase( $r ) ? sprintf( 'Deletion request #%d confirmed: finish by %s', $r->ID, self::due_date( time() ) ) : $subject;
    }

    /** Plain text, as WordPress sends it; it fills ###USER_EMAIL### and ###MANAGE_URL### after this filter. */
    public static function warn_content( $content, $data ) {
        $r = $data['request'] ?? null;
        if ( ! self::is_erase( $r ) ) {
            return $content;
        }
        return sprintf( "A person confirmed a request to delete their data.\n\nRequest: #%d\nPerson: ###USER_EMAIL###\nFinish by: %s (one month from today, the legal limit)\n\n", $r->ID, self::due_date( time() ) )
            . "1. Open Tools > Erase Personal Data and press \"Erase personal data\" on this request:\n###MANAGE_URL###\n\n"
            . "2. Then remove the copies outside our system by hand, searching by the address above:\n"
            . "- Brevo: delete the contact.\n"
            . "- Make: delete the scenario run history that holds this address.\n"
            . "- PDFMonkey: delete the documents made for this person.\n"
            . "- The practitioner is emailed when the erase finishes and deletes their own copies.\n"
            . "- Stripe: payment records are kept. Do not delete them.\n"
            . "- Anthropic and Deepgram: per their terms they keep nothing we can delete by request (not checked).\n";
    }

    /** One calendar month on; with no such day in the next month, its last day (ICO). */
    public static function due_date( $ts ) {
        $d   = new DateTime( '@' . (int) $ts );
        $day = (int) $d->format( 'j' );
        $d->modify( 'first day of next month' );
        $d->setDate( (int) $d->format( 'Y' ), (int) $d->format( 'n' ), min( $day, (int) $d->format( 't' ) ) );
        return $d->format( 'j F Y' );
    }

    /** WordPress has erased every eraser's data: tell each practitioner, log the counts. */
    public static function erased( $request_id ) {
        $r = wp_get_user_request( $request_id );
        if ( ! self::is_erase( $r ) ) {
            return;
        }
        $state = HDLV2_Privacy_Eraser::take_state( $r->email );
        foreach ( $state['practitioners'] as $id ) {
            $u = get_userdata( $id );
            if ( $u && is_email( $u->user_email ) ) {
                wp_mail( $u->user_email, "A client's data has been deleted", HDLV2_Email_Templates::privacy_practitioner( $r->email ), array( self::HTML ) );
            }
        }
        if ( $state['counts'] ) {
            error_log( sprintf( '[HDLV2 erase] request %d done: %s', (int) $request_id, wp_json_encode( $state['counts'] ) ) );
        }
    }

    /** Daily: a confirmed request still open after 21 days is mailed once more, by number. */
    public static function remind_open() {
        $ids = get_posts( array(
            'post_type'     => 'user_request',
            'post_name__in' => array( self::ACTION ),
            'post_status'   => 'request-confirmed',
            'fields'        => 'ids',
            'numberposts'   => 20,
            'meta_query'    => array(
                array( 'key' => '_wp_user_request_confirmed_timestamp', 'value' => time() - 21 * DAY_IN_SECONDS, 'compare' => '<', 'type' => 'NUMERIC' ),
                array( 'key' => self::NUDGE, 'compare' => 'NOT EXISTS' ),
            ),
        ) );
        if ( ! $ids || ! HDLV2_Env::gate( 'erase_reminder ' . implode( ',', $ids ) ) ) {
            return;
        }
        foreach ( $ids as $id ) {
            $due = self::due_date( (int) get_post_meta( $id, '_wp_user_request_confirmed_timestamp', true ) );
            wp_mail( implode( ',', self::alert_to() ), sprintf( 'Deletion request #%d is still open: finish by %s', $id, $due ), sprintf( "Deletion request #%d was confirmed more than 21 days ago and is not finished. The legal limit is %s.\n\nTools > Erase Personal Data:\n%s\n", $id, $due, admin_url( 'erase-personal-data.php' ) ) );
            update_post_meta( $id, self::NUDGE, time() );
        }
    }

    private static function alert_to() {
        $to = array_filter( array_map( 'trim', explode( ',', (string) get_option( 'hdlv2_stage1_alert_to', '' ) ) ) );
        return $to ? array_values( $to ) : array( (string) get_option( 'admin_email' ) );
    }
}

HDLV2_Privacy_Requests::init();
