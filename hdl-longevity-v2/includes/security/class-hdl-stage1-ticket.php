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
 * 'challenge'; v0.48.82: 'timeline').
 * Response: { token, expires_at, idempotent }; a 'full_report' ticket adds
 * { open_url, stage1_found, auto_confirmed } (v0.48.15, see mint_full_report())
 * and { timeline_granted } (v0.48.82; optional body field `timeline`, a boolean:
 * the paid session held the Timeline upgrade), a 'challenge' ticket adds
 * { open_url, already_enrolled } (v0.48.23/24, see mint_challenge()).
 * 'timeline' (v0.48.82, plan 3) makes no ticket: see grant_timeline().
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
        $needs_db    = array( 'full_report' => '3.29', 'challenge' => '3.31', 'timeline' => '3.34' )[ $product ] ?? '3.27';
        // v0.48.82 — an upgrade needs Phase AO's column to be recorded: until then the payment is
        // refused here and Stripe sends it again.
        $timeline = $request->get_param( 'timeline' );
        if ( true === $timeline && 'full_report' === $product ) {
            $needs_db = '3.34';
        }
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
        if ( ! in_array( $product, array( 'stage1', 'full_report', 'challenge', 'timeline' ), true ) ) {
            $invalid[] = 'product';
        }
        if ( null !== $timeline && ! is_bool( $timeline ) ) {
            $invalid[] = 'timeline';
        }
        if ( $invalid ) {
            return self::refuse( 400, 'invalid_body', 'One or more fields failed validation.', $invalid );
        }

        if ( 'full_report' === $product ) {
            return self::mint_full_report( $practitioner_id, $email, $name, $external_ref, $days, true === $timeline );
        }
        if ( 'timeline' === $product ) {
            return self::grant_timeline( $email, $external_ref );
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
    private static function mint_full_report( $practitioner_id, $email, $name, $external_ref, $days, $timeline = false ) {
        $ticket = self::mint( $practitioner_id, $email, $name, $external_ref, $days, 'full_report', $timeline );
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
            // v0.48.82 — whether this ticket carries the Timeline (a stamp, see mint()).
            'timeline_granted' => self::stamp_ready() && '' !== (string) self::stamp_of( $ticket['id'] ),
        ) );
    }

    /**
     * v0.48.82 (plan 3) — the Timeline paid on its own ($10 for a week after the form, then $27).
     * No ticket is made: the buyer's newest live Full Report ticket gets this payment's reference.
     * The switch decides what pages show, never what was paid, so this runs whatever it says.
     * Already owned (another payment's stamp, 'included', or a client with a report and no Stripe
     * ticket): nothing is written and the seller is told, so the team can refund. open_url signs
     * into the Timeline: it goes to this keyed caller and the buyer's inbox only.
     *
     * @return array { granted, idempotent, already_owned, has_full_report, late, ends_on, open_url }
     */
    private static function grant_timeline( $email, $external_ref ) {
        global $wpdb;
        $tickets = HDLV2_Timeline_Upgrade::tickets( $email );
        $row     = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, token, practitioner_user_id, client_email, stage3_completed_at FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE LOWER(client_email) = %s AND deleted_at IS NULL ORDER BY id DESC LIMIT 1",
            strtolower( $email )
        ) );
        $report  = $row ? HDLV2_Healthspan_Timeline::report_row( (int) $row->id ) : null;
        $ends    = $row && ! empty( $row->stage3_completed_at )
            ? HDLV2_Timeline_Upgrade::ends_at( $row->stage3_completed_at, HDLV2_Offer::get()['timeline_offer_days'] ) : 0;
        $answer  = array(
            'granted'         => false,
            'idempotent'      => false,
            'already_owned'   => false,
            'has_full_report' => (bool) ( $tickets || $report ),
            'late'            => $ends && time() > $ends,
            'ends_on'         => $ends ? HDLV2_Timeline_Upgrade::day( $ends, 'Y-m-d' ) : null,
            'open_url'        => $row && ! empty( $row->token ) ? HDLV2_Healthspan_Timeline::report_url( $row->token ) : null,
        );
        $code = 'granted';
        if ( ! $answer['has_full_report'] ) {
            $code = 'no_full_report';
        } else {
            $answer['granted'] = true;
            $stamps = array_map( function ( $t ) { return (string) $t->timeline_paid_ref; }, $tickets );
            if ( in_array( $external_ref, $stamps, true ) ) {
                $answer['idempotent'] = true;
                $code = 'idempotent';
            } elseif ( ! $tickets || array_filter( $stamps, 'strlen' ) ) {
                $answer['already_owned'] = true;
                $code = 'already_owned';
            } elseif ( ! $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}hdlv2_widget_invites SET timeline_paid_ref = %s WHERE id = %d AND (timeline_paid_ref IS NULL OR timeline_paid_ref = '')",
                $external_ref, (int) $tickets[0]->id
            ) ) ) {
                // Another delivery stamped it between the read and the write.
                $now_ref = (string) self::stamp_of( (int) $tickets[0]->id );
                $answer['idempotent']    = $now_ref === $external_ref;
                $answer['already_owned'] = $now_ref !== $external_ref;
                $code = $answer['idempotent'] ? 'idempotent' : 'already_owned';
            }
        }
        error_log( '[HDL stage1-ticket] timeline ' . $code . ( $answer['late'] ? ' late' : '' ) );
        return rest_ensure_response( $answer );
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
     * @param bool   $timeline v0.48.82 — a full_report ticket's sale held the Timeline upgrade.
     * @return array|WP_Error { id, token, expires_at (stored UTC), idempotent,
     *                         email, name } — email/name are the ticket's own.
     */
    public static function mint( $practitioner_id, $email, $name, $external_ref, $days, $source = 'paid_stage1', $timeline = false ) {
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
        $data   = array(
            'practitioner_id' => $practitioner_id,
            'token'           => $token,
            'client_name'     => $name,
            'client_email'    => $email,
            'status'          => 'pending',
            'expires_at'      => $expires_at,
            'source'          => $source,
            'external_ref'    => $external_ref,
        );
        $format = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
        $stamps = 'full_report' === $source && self::stamp_ready();
        if ( $stamps ) {
            $data['timeline_paid_ref'] = self::stamp( $practitioner_id, $external_ref, $timeline ); // null = NULL
            $format[] = '%s';
        }
        $was_suppressed = $wpdb->suppress_errors( true );
        $inserted       = $wpdb->insert( $table, $data, $format );
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
        // v0.48.82 — a repeat that now says the upgrade was bought fills an empty stamp, once.
        if ( $stamps && $timeline ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE $table SET timeline_paid_ref = %s WHERE id = %d AND (timeline_paid_ref IS NULL OR timeline_paid_ref = '')",
                $external_ref, (int) $existing->id
            ) );
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

    /**
     * v0.48.82 (plan 3) — a new Full Report ticket's Timeline stamp. Bought → this payment's reference
     * (whatever the switch: it decides what is shown, never what was paid). A Stripe checkout (cs_…)
     * for the offer's practitioner with the switch on → empty: the Timeline is an upgrade there.
     * Everything else (switch off, a pack seat, another practitioner) → 'included', as before.
     */
    private static function stamp( $practitioner_id, $external_ref, $timeline ) {
        if ( $timeline ) {
            return $external_ref;
        }
        $sold = class_exists( 'HDLV2_Timeline_Upgrade' ) && HDLV2_Timeline_Upgrade::enabled()
            && HDLV2_Offer::applies_to( $practitioner_id ) && 0 === strpos( $external_ref, 'cs_' );
        return $sold ? null : 'included';
    }

    /** Phase AO (DB 3.34) has added timeline_paid_ref. */
    private static function stamp_ready() {
        return version_compare( (string) get_option( 'hdlv2_db_version', '0' ), '3.34', '>=' );
    }

    private static function stamp_of( $ticket_id ) {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT timeline_paid_ref FROM {$wpdb->prefix}hdlv2_widget_invites WHERE id = %d",
            (int) $ticket_id
        ) );
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
