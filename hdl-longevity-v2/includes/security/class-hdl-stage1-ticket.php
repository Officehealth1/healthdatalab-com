<?php
/**
 * HDL_Stage1_Ticket — POST /wp-json/hdl/v1/stage1-ticket
 *
 * Mints the one-time ticket that opens a practitioner's PAID Stage 1 widget
 * (widget_config.access_mode = 'paid'). The selling site calls this once its
 * payment is confirmed and sends the buyer the link itself:
 *   <page hosting the widget>?invite=<token>
 *
 * Auth: header X-HDL-Stage1-Ticket-Key against the wp-config constant
 * HDL_STAGE1_TICKET_KEY. No constant → 503, the route is dark.
 *
 * Body: practitioner_id, email, name, external_ref (the seller's payment
 * reference, e.g. a Stripe session id), optional expires_days (1–365,
 * default 90), optional product ('stage1' default, 'full_report' or
 * 'challenge').
 * Response: { token, expires_at, idempotent }; a 'full_report' ticket adds
 * { open_url, stage1_found, auto_confirmed } (v0.48.15, see mint_full_report()),
 * a 'challenge' ticket adds { open_url, already_enrolled } (v0.48.23/24,
 * see mint_challenge()).
 *
 * The same external_ref always returns the same token. That is enforced by
 * the UNIQUE key on widget_invites.external_ref: insert first, read the
 * existing row on the duplicate-key error. No user, no email, no Stage 2.
 * The insert lives in mint(), which the dashboard's "Send Stage 1 link"
 * also calls (v0.47.90); that path sends its own email.
 *
 * V1-style class name because the route lives in the hdl/v1 namespace, like
 * HDL_Paid_Report_Provisioner next to it.
 *
 * @package HDL_Longevity_V2
 * @since   0.47.85
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDL_Stage1_Ticket {

    /** Mints per caller IP per hour. Room for a webinar's worth of buyers. */
    const RATE_LIMIT = 300;

    const DEFAULT_DAYS = 90;
    const MAX_DAYS     = 365;

    /** v0.48.81 (plan 2h) — straight-in asks per caller per hour. The caller is the selling site, so this caps the feature. */
    const OPEN_RATE_LIMIT = 120;

    /** v0.48.85 (plan 2k) — user meta on an account a browser pass let in; holds the ticket id until the inbox proves itself. */
    const UNPROVEN_META = 'hdlv2_unproven_entry';

    public static function register_hooks() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route( 'hdl/v1', '/stage1-ticket', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle' ),
            // Auth happens in the handler so a server without the key
            // answers 503 whatever the caller sends.
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'hdl/v1', '/full-report-open', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_open' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * v0.48.81 (plan 2h) — POST /wp-json/hdl/v1/full-report-open, behind hdlv2_ff_straight_to_form.
     * The selling site asks, once, after a buyer paid in the browser tab that holds the inbox pass
     * from their own Stage 1 results e-mail (HDLV2_Offer::inbox_pass). A yes lets that site send the
     * tab to the ticket's own ?invite= link, the link the purchase e-mail carries; nothing else here
     * signs anyone in. Yes only when every check holds, in this order: key, switch, limit, a Full
     * Report ticket for external_ref, no staff account, the pass for the ticket's own e-mail and
     * practitioner, no account or one made by this purchase (20 minutes), and the one UPDATE below.
     *
     * Body: { external_ref, own }. Answer: { open: true } or { open: false } (a refused caller is
     * never told why; the log line carries a short code). 401/503 as the ticket route.
     */
    public static function handle_open( $request ) {
        $expected = defined( 'HDL_STAGE1_TICKET_KEY' ) ? (string) HDL_STAGE1_TICKET_KEY : '';
        if ( '' === $expected ) {
            return self::refuse( 503, 'not_configured', 'Stage 1 tickets are not enabled on this server.' );
        }
        $provided = (string) $request->get_header( 'X-HDL-Stage1-Ticket-Key' );
        if ( '' === $provided || ! hash_equals( $expected, $provided ) ) {
            return self::refuse( 401, 'unauthorized', 'Unauthorized.' );
        }
        if ( ! get_option( 'hdlv2_ff_straight_to_form', false ) ) {
            return self::open_no( 'flag_off' );
        }
        $rl = HDLV2_Rate_Limiter::consume( HDLV2_Rate_Limiter::bucket_key( array( 'full-report-open', HDLV2_Rate_Limit_Middleware::get_ip() ) ), self::OPEN_RATE_LIMIT, HOUR_IN_SECONDS );
        if ( ! $rl['allowed'] ) {
            error_log( '[HDL full-report-open] no limit' );
            $resp = new WP_REST_Response( array( 'code' => 'rate_limited', 'message' => 'Too many requests.', 'data' => array( 'status' => 429 ) ), 429 );
            $resp->header( 'Retry-After', (string) (int) $rl['retry_after'] );
            return $resp;
        }

        $external_ref = $request->get_param( 'external_ref' );
        $own          = $request->get_param( 'own' );
        // v0.48.85 (plan 2k) — two kinds: the inbox pass, or a browser pass from the lead reply.
        $browser = HDLV2_Offer::browser_pass_lead( $own );
        if ( ! is_string( $external_ref ) || ! preg_match( '/^[A-Za-z0-9_.:\-]{1,128}$/', $external_ref )
             || ! is_string( $own ) || ( ! $browser && ! preg_match( '/^\d{10}\.[a-f0-9]{64}$/', $own ) ) ) {
            return self::open_no( 'shape' );
        }

        global $wpdb;
        $table  = $wpdb->prefix . 'hdlv2_widget_invites';
        $ticket = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, practitioner_id, client_email, source FROM $table WHERE external_ref = %s LIMIT 1",
            $external_ref
        ) );
        if ( ! $ticket || 'full_report' !== $ticket->source ) {
            return self::open_no( 'no_ticket' );
        }
        $email   = strtolower( trim( (string) $ticket->client_email ) );
        $user_id = (int) email_exists( $email );
        if ( $user_id && hdlv2_is_staff_account( $user_id ) ) {
            return self::open_no( 'staff' );
        }
        if ( $browser ) {
            return self::open_browser( $own, $browser, $ticket, $email, $user_id );
        }
        if ( ! HDLV2_Offer::inbox_pass_ok( $own, $email, (int) $ticket->practitioner_id ) ) {
            return self::open_no( 'pass' );
        }
        if ( $user_id && ! self::made_by_purchase( $user_id, (int) $ticket->id ) ) {
            return self::open_no( 'account' );
        }
        if ( ! self::claim( (int) $ticket->id ) ) {
            return self::open_no( 'claim' );
        }
        error_log( '[HDL full-report-open] yes invite=' . (int) $ticket->id );
        return rest_ensure_response( array( 'open' => true ) );
    }

    /**
     * v0.48.85 (plan 2k) — a browser pass: a buyer who pressed Buy on their own result page. It proves
     * typing, not owning, so it gets less than the inbox pass: the form's own address (never the
     * purchase e-mail's link), only into the account and record this purchase just made from that
     * lead, and the account is marked so the purchase e-mail's link ends this browser's access
     * (inbox_proof()). Behind hdlv2_ff_straight_in_result_page.
     */
    private static function open_browser( $own, $lead_id, $ticket, $email, $user_id ) {
        global $wpdb;
        if ( ! get_option( 'hdlv2_ff_straight_in_result_page', false ) ) {
            return self::open_no( 'flag_off' );
        }
        $practitioner_id = (int) $ticket->practitioner_id;
        // The newest row for this e-mail is the one the purchase confirmed; a newer submission under
        // the same e-mail (another browser) replaces it and ends the pass.
        $lead = HDLV2_Widget_Config::newest_lead( $practitioner_id, $email );
        if ( ! $lead || (int) $lead->id !== (int) $lead_id ) {
            return self::open_no( 'lead' );
        }
        if ( ! HDLV2_Offer::browser_pass_ok( $own, $email, $practitioner_id, (int) $lead_id, HDLV2_Widget_Config::lead_digest( $lead ) ) ) {
            return self::open_no( 'pass' );
        }
        if ( 'confirmed' !== $lead->status || (int) ( $lead->invite_id ?? 0 ) !== (int) $ticket->id ) {
            return self::open_no( 'lead' );
        }
        // Never "none yet": the purchase's confirm made the account and the record.
        if ( ! $user_id || ! self::made_by_purchase( $user_id, (int) $ticket->id ) ) {
            return self::open_no( 'account' );
        }
        $form = $wpdb->get_row( $wpdb->prepare(
            "SELECT token FROM {$wpdb->prefix}hdlv2_form_progress WHERE client_user_id = %d AND practitioner_user_id = %d AND deleted_at IS NULL AND token_expires_at > UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1",
            $user_id,
            $practitioner_id
        ) );
        if ( ! $form || ! preg_match( '/^[a-f0-9]{64}$/', (string) $form->token ) ) {
            return self::open_no( 'form' );
        }
        // The mark before the claim (review F3): the ?invite= handler moves the ticket out of 'pending'
        // before it reads the mark, so either this claim lands first and the proof sees the mark, or the
        // claim fails. A failed claim keeps the mark: deleting it would let a second ask from the browser
        // that went in remove the first ask's mark; a mark on an account nobody entered costs the owner one
        // reset at their next purchase-link opening.
        if ( ! self::is_unproven( $user_id ) && ! update_user_meta( $user_id, self::UNPROVEN_META, (int) $ticket->id ) ) {
            return self::open_no( 'mark' );
        }
        if ( ! self::claim( (int) $ticket->id ) ) {
            return self::open_no( 'claim' );
        }
        error_log( '[HDL full-report-open] yes invite=' . (int) $ticket->id . ' browser' );
        $slug = trim( apply_filters( 'hdlv2_assessment_slug', 'assessment' ), '/' );
        return rest_ensure_response( array( 'open' => true, 'url' => home_url( '/' . $slug . '/?token=' . $form->token ) ) );
    }

    /**
     * Never into an account that held anything before this purchase: only the one the purchase's own
     * confirm made, so no older than the ticket (5 s for the same-second insert) and never over 20
     * minutes. Ages, not times: user_registered is UTC, created_at the database's own clock.
     */
    private static function made_by_purchase( $user_id, $ticket_id ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->users} u, {$wpdb->prefix}hdlv2_widget_invites t WHERE u.ID = %d AND t.id = %d AND TIMESTAMPDIFF(SECOND, u.user_registered, UTC_TIMESTAMP()) <= TIMESTAMPDIFF(SECOND, t.created_at, NOW()) + 5 AND u.user_registered >= UTC_TIMESTAMP() - INTERVAL 20 MINUTE",
            (int) $user_id,
            (int) $ticket_id
        ) );
    }

    /**
     * The first-time lock and the 15 minutes in one statement: only one request can move a pending
     * ticket, and a ticket the e-mailed link already opened is no longer pending. created_at is the
     * database's own clock (DEFAULT CURRENT_TIMESTAMP), so NOW(); the end date is stored in UTC.
     */
    private static function claim( $ticket_id ) {
        global $wpdb;
        return 1 === (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}hdlv2_widget_invites SET status = 'opened', opened_at = %s WHERE id = %d AND source = 'full_report' AND status = 'pending' AND created_at >= NOW() - INTERVAL 15 MINUTE AND expires_at > UTC_TIMESTAMP()",
            current_time( 'mysql' ),
            (int) $ticket_id
        ) );
    }

    /**
     * v0.48.85 (plan 2k) — inbox proof, called by the ?invite= handler for a full_report ticket before
     * anyone is signed in, with the switches on or off (a mark must never outlive a switch). An account
     * marked by open_browser() went straight in on typing alone; opening the purchase e-mail's link
     * proves the inbox. One path, wherever the link is opened: the account goes back to a clean state,
     * whatever was done with it before. Every session ends; every form record gets a new token (end
     * dates and answers untouched), so the first browser's tab stops at its next save; a new random
     * password (wp_set_password sends no mail; wp_update_user would tell the owner); every application password
     * and any pending e-mail change go; then the mark goes. A browser already signed in as the account
     * loses that session too (the password is in the cookie): the handler signs the opener in again
     * after this. A failed write keeps the mark, so the next opening tries again.
     *
     * @return string '' (no mark), 'cleared' or 'failed'.
     */
    public static function inbox_proof( $user_id ) {
        $user_id = (int) $user_id;
        if ( ! self::is_unproven( $user_id ) ) {
            return '';
        }
        WP_Session_Tokens::get_instance( $user_id )->destroy_all();
        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_form_progress';
        $ok    = true;
        foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE client_user_id = %d", $user_id ) ) as $id ) {
            $ok = false !== $wpdb->update( $table, array( 'token' => bin2hex( random_bytes( 32 ) ) ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) ) && $ok;
        }
        wp_set_password( wp_generate_password( 32, true, true ), $user_id );
        $ok = ! is_wp_error( WP_Application_Passwords::delete_all_application_passwords( $user_id ) ) && $ok;
        delete_user_meta( $user_id, '_new_email' );
        if ( ! $ok ) {
            error_log( '[HDL full-report-open] proof failed' );
            return 'failed';
        }
        delete_user_meta( $user_id, self::UNPROVEN_META );
        error_log( '[HDL full-report-open] proof cleared' );
        return 'cleared';
    }

    /** v0.48.85 (plan 2k) — true while the account carries the mark open_browser() sets. */
    public static function is_unproven( $user_id ) {
        return (int) $user_id > 0 && '' !== (string) get_user_meta( (int) $user_id, self::UNPROVEN_META, true );
    }

    /** A straight-in refusal: one log line with the code, the same answer for every reason. */
    private static function open_no( $code ) {
        error_log( '[HDL full-report-open] no ' . $code );
        return rest_ensure_response( array( 'open' => false ) );
    }

    public static function handle( $request ) {
        $expected = defined( 'HDL_STAGE1_TICKET_KEY' ) ? (string) HDL_STAGE1_TICKET_KEY : '';
        if ( '' === $expected ) {
            return self::refuse( 503, 'not_configured', 'Stage 1 tickets are not enabled on this server.' );
        }

        // hdlv2_db_version only reaches 3.27 once Phase AH has verified the
        // 'paid_stage1' source value and the UNIQUE key (3.29: Phase AJ's
        // 'full_report' value; 3.31: Phase AL's 'challenge'). Without them a
        // ticket would be stored as an ordinary invite, or minted twice.
        $product_raw = $request->get_param( 'product' );
        $product     = null === $product_raw ? 'stage1' : (string) $product_raw;
        $needs_db    = array( 'full_report' => '3.29', 'challenge' => '3.31' )[ $product ] ?? '3.27';
        if ( version_compare( (string) get_option( 'hdlv2_db_version', '0' ), $needs_db, '<' ) ) {
            return self::refuse( 503, 'not_ready', 'Stage 1 tickets are not ready on this server.' );
        }

        $provided = (string) $request->get_header( 'X-HDL-Stage1-Ticket-Key' );
        if ( '' === $provided || ! hash_equals( $expected, $provided ) ) {
            return self::refuse( 401, 'unauthorized', 'Unauthorized.' );
        }

        // A fixed hour from the first counted call (V1's limiter restarts its
        // hour on every call and cannot say how long is left).
        // v0.48.16 — the real address (CF-Connecting-IP first), not the Cloudflare edge.
        $ip = HDLV2_Rate_Limit_Middleware::get_ip();
        $rl = HDLV2_Rate_Limiter::consume( HDLV2_Rate_Limiter::bucket_key( array( 'stage1-ticket', $ip ) ), self::RATE_LIMIT, HOUR_IN_SECONDS );
        if ( ! $rl['allowed'] ) {
            // Same body a WP_Error gives; a response object so it can carry the header.
            $error = self::refuse( 429, 'rate_limited', 'Too many requests.' );
            $resp  = new WP_REST_Response( array(
                'code'    => $error->get_error_code(),
                'message' => $error->get_error_message(),
                'data'    => array( 'status' => 429 ),
            ), 429 );
            $resp->header( 'Retry-After', (string) (int) $rl['retry_after'] );
            return $resp;
        }

        $practitioner_id = absint( $request->get_param( 'practitioner_id' ) );
        $email           = sanitize_email( (string) $request->get_param( 'email' ) );
        $name            = substr( sanitize_text_field( (string) $request->get_param( 'name' ) ), 0, 200 );
        $external_ref    = (string) $request->get_param( 'external_ref' );
        $days_raw        = $request->get_param( 'expires_days' );
        $days            = null === $days_raw ? self::DEFAULT_DAYS : (int) $days_raw;

        $invalid = array();
        if ( ! $practitioner_id ) {
            $invalid[] = 'practitioner_id';
        }
        if ( ! is_email( $email ) ) {
            $invalid[] = 'email';
        }
        if ( '' === $name ) {
            $invalid[] = 'name';
        }
        if ( ! preg_match( '/^[A-Za-z0-9_.:\-]{1,128}$/', $external_ref ) ) {
            $invalid[] = 'external_ref';
        }
        if ( ( null !== $days_raw && ! is_numeric( $days_raw ) ) || $days < 1 || $days > self::MAX_DAYS ) {
            $invalid[] = 'expires_days';
        }
        if ( ! in_array( $product, array( 'stage1', 'full_report', 'challenge' ), true ) ) {
            $invalid[] = 'product';
        }
        if ( $invalid ) {
            return self::refuse( 400, 'invalid_body', 'One or more fields failed validation.', $invalid );
        }

        if ( 'full_report' === $product ) {
            return self::mint_full_report( $practitioner_id, $email, $name, $external_ref, $days );
        }
        if ( 'challenge' === $product ) {
            return self::mint_challenge( $practitioner_id, $email, $name, $external_ref, $days );
        }

        $ticket = self::mint( $practitioner_id, $email, $name, $external_ref, $days );
        if ( is_wp_error( $ticket ) ) {
            return $ticket;
        }
        return rest_ensure_response( array(
            'token'      => $ticket['token'],
            'expires_at' => self::iso( $ticket['expires_at'] ),
            'idempotent' => $ticket['idempotent'],
        ) );
    }

    /**
     * v0.48.15 — the Full Report hand-over (Matthew, 2026-10-13). The buyer
     * paid on the selling site; the ticket is a real invite (source
     * 'full_report'), so its link logs them in and opens the Full Report.
     * The practitioner's widget does not have to be in paid mode.
     *
     * If the buyer already answered Stage 1 (a pending lead with this email)
     * the lead is confirmed here, without the Confirm email, unless the
     * switch `hdlv2_full_report_auto_confirm` is 0: then the lead waits for
     * the practitioner and open_url is null. A rejected lead counts as not
     * found and is never revived. A repeat call with the same external_ref
     * mints nothing and only confirms a lead that is still pending.
     */
    private static function mint_full_report( $practitioner_id, $email, $name, $external_ref, $days ) {
        $ticket = self::mint( $practitioner_id, $email, $name, $external_ref, $days, 'full_report' );
        if ( is_wp_error( $ticket ) ) {
            return $ticket;
        }
        $auto = (bool) get_option( 'hdlv2_full_report_auto_confirm', 1 );
        // A repeat call (same external_ref) confirms a lead the first call
        // could not; confirm_lead_for_purchase() only reports otherwise.
        if ( $auto ) {
            $lead = HDLV2_Widget_Config::confirm_lead_for_purchase( $practitioner_id, $ticket['email'], $ticket['id'] );
        } else {
            $lead = HDLV2_Widget_Config::purchase_lead_state( $practitioner_id, $ticket['email'], $ticket['id'] );
        }
        return rest_ensure_response( array(
            'token'          => $ticket['token'],
            'expires_at'     => self::iso( $ticket['expires_at'] ),
            'idempotent'     => $ticket['idempotent'],
            'open_url'       => $auto ? site_url( '/rate-of-ageing-widget/?invite=' . $ticket['token'] ) : null,
            'stage1_found'   => $lead['found'],
            'auto_confirmed' => $lead['confirmed'],
        ) );
    }

    /**
     * v0.48.23 — the 30-day challenge (Matthew, 2026-10-27). The buyer paid on
     * the selling site; the ticket (source 'challenge') is their sign-in link
     * for the whole 90 days: Stage 1 first if it is not on file (free, the
     * public path), then the challenge on their dashboard. No lead confirm,
     * no email: the selling site emails open_url itself.
     *
     * v0.48.24 — already_enrolled: this email's account is on the challenge
     * already. The link changes nothing for it (the 30 days never restart),
     * so with idempotent false it is a second purchase the selling site can
     * stop or refund (restart vs refund is Matthew's call, DF2).
     * v0.48.27 — the free 30 days (D7) are not a purchase: buying the videos
     * on top is a first sale.
     */
    private static function mint_challenge( $practitioner_id, $email, $name, $external_ref, $days ) {
        $ticket = self::mint( $practitioner_id, $email, $name, $external_ref, $days, 'challenge' );
        if ( is_wp_error( $ticket ) ) {
            return $ticket;
        }
        $user_id  = (int) email_exists( $email );
        $enrolment = $user_id && class_exists( 'HDLV2_Challenge' ) ? HDLV2_Challenge::enrolment( $user_id ) : null;
        $enrolled  = $enrolment && HDLV2_Challenge::is_paid( $enrolment );
        if ( $enrolled ) {
            error_log( sprintf( '[HDLV2] challenge ticket for an enrolled email: user %d, ref %s', $user_id, $external_ref ) );
        }
        return rest_ensure_response( array(
            'token'            => $ticket['token'],
            'expires_at'       => self::iso( $ticket['expires_at'] ),
            'idempotent'       => $ticket['idempotent'],
            'open_url'         => site_url( '/rate-of-ageing-widget/?invite=' . $ticket['token'] ),
            'already_enrolled' => $enrolled,
        ) );
    }

    /**
     * v0.48.34 — a Healthspan Timeline link (Matthew, 2026-10-30): a client passes it to a loved
     * one, who does the free Stage 1 through it and then says yes or no to sharing. No email is
     * known or stored (the client shares the link themself); client_name holds the client's label
     * for the person. Any access mode. Not reachable from the keyed route above.
     *
     * @return array|WP_Error as mint().
     */
    public static function mint_timeline_link( $practitioner_id, $label, $external_ref, $days ) {
        return self::mint( $practitioner_id, '', $label, $external_ref, $days, 'timeline_link' );
    }

    /**
     * v0.48.35 — a family pack seat (Matthew, 2026-10-30): the Full Report ticket for one invitee
     * who said yes. The email is the one they typed, never verified, and this link signs its
     * owner in. So the caller sends open_url to that inbox and never back to the browser, and an
     * email that already has an account or a client record gets the ticket alone: confirming would
     * overwrite that client's Stage 1 with whatever the typist answered. Not reachable from the
     * keyed route.
     *
     * @return array|WP_Error { id, token, expires_at, idempotent, open_url, stage1_found, auto_confirmed }.
     */
    public static function mint_family_seat( $practitioner_id, $email, $name, $external_ref, $days ) {
        global $wpdb;
        $known = email_exists( $email ) || $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}hdlv2_form_progress WHERE client_email = %s AND practitioner_user_id = %d AND deleted_at IS NULL LIMIT 1",
            $email, $practitioner_id
        ) );
        if ( $known ) {
            $ticket = self::mint( $practitioner_id, $email, $name, $external_ref, $days, 'full_report' );
            if ( is_wp_error( $ticket ) ) {
                return $ticket;
            }
            return array(
                'id'             => $ticket['id'],
                'token'          => $ticket['token'],
                'expires_at'     => $ticket['expires_at'],
                'idempotent'     => $ticket['idempotent'],
                'open_url'       => self::open_url( $ticket['token'] ),
                'stage1_found'   => false,
                'auto_confirmed' => false,
            );
        }
        $done = self::mint_full_report( $practitioner_id, $email, $name, $external_ref, $days );
        if ( is_wp_error( $done ) ) {
            return $done;
        }
        $data = $done instanceof WP_REST_Response ? $done->get_data() : $done;
        $data['id'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}hdlv2_widget_invites WHERE token = %s", $data['token'] ) );
        return $data;
    }

    /** Where a full_report ticket opens: it signs in and goes to the form. */
    public static function open_url( $token ) {
        return site_url( '/rate-of-ageing-widget/?invite=' . $token );
    }

    /**
     * Mint the ticket for $external_ref, or return the one it already has.
     * Shared by the keyed route above and the dashboard's "Send Stage 1
     * link" (HDLV2_Widget_Config::ajax_send_stage1_link). Callers validate
     * their own input first.
     *
     * @param string $source 'paid_stage1' (needs paid mode), 'full_report', 'challenge' or 'timeline_link'.
     * @return array|WP_Error { id, token, expires_at (stored UTC), idempotent,
     *                         email, name } — email/name are the ticket's own.
     */
    public static function mint( $practitioner_id, $email, $name, $external_ref, $days, $source = 'paid_stage1' ) {
        global $wpdb;
        $access_mode = $wpdb->get_row( $wpdb->prepare(
            "SELECT access_mode FROM {$wpdb->prefix}hdlv2_widget_config WHERE practitioner_user_id = %d LIMIT 1",
            $practitioner_id
        ) );
        if ( ! $access_mode || ( 'paid_stage1' === $source && 'paid' !== $access_mode->access_mode ) ) {
            return self::refuse( 409, 'not_paid_mode', 'This practitioner\'s widget is not in paid mode.' );
        }

        $table      = $wpdb->prefix . 'hdlv2_widget_invites';
        $token      = bin2hex( random_bytes( 32 ) );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );

        // Insert first: the UNIQUE key on external_ref decides, so two
        // deliveries of the same payment can never mint two tickets.
        $was_suppressed = $wpdb->suppress_errors( true );
        $inserted       = $wpdb->insert( $table, array(
            'practitioner_id' => $practitioner_id,
            'token'           => $token,
            'client_name'     => $name,
            'client_email'    => $email,
            'status'          => 'pending',
            'expires_at'      => $expires_at,
            'source'          => $source,
            'external_ref'    => $external_ref,
        ), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
        $wpdb->suppress_errors( $was_suppressed );

        if ( $inserted ) {
            return array( 'id' => (int) $wpdb->insert_id, 'token' => $token, 'expires_at' => $expires_at, 'idempotent' => false, 'email' => $email, 'name' => $name );
        }

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, token, expires_at, practitioner_id, source, client_email, client_name FROM $table WHERE external_ref = %s LIMIT 1",
            $external_ref
        ) );
        if ( ! $existing ) {
            return self::refuse( 500, 'mint_failed', 'The ticket could not be saved.' );
        }
        if ( (int) $existing->practitioner_id !== (int) $practitioner_id || $source !== $existing->source ) {
            return self::refuse( 409, 'external_ref_conflict', 'This external_ref belongs to another ticket.' );
        }
        return array(
            'id'         => (int) ( $existing->id ?? 0 ),
            'token'      => (string) $existing->token,
            'expires_at' => (string) $existing->expires_at,
            'idempotent' => true,
            'email'      => (string) $existing->client_email,
            'name'       => (string) $existing->client_name,
        );
    }

    /** Stored UTC datetime → ISO 8601. */
    private static function iso( $mysql_utc ) {
        return gmdate( 'c', strtotime( $mysql_utc . ' UTC' ) );
    }

    /** Refusal + one log line: status, code and field NAMES, never values. */
    private static function refuse( $status, $code, $message, $fields = array() ) {
        error_log( '[HDL stage1-ticket] ' . $status . ' ' . $code . ( $fields ? ' fields=' . implode( ',', $fields ) : '' ) );
        $data = array( 'status' => $status );
        if ( $fields ) {
            $data['invalid_fields'] = $fields;
        }
        return new WP_Error( $code, $message, $data );
    }
}
