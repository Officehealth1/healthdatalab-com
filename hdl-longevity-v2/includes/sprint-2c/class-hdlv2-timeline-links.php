<?php
/**
 * HDL V2 — Healthspan Timeline links (v0.48.34, DB 3.32).
 *
 * A client invites a loved one by a link they pass on themselves. The loved one does the free
 * Stage 1 through it (a 'timeline_link' ticket on the practitioner's ticket page), sees who
 * invited them and says yes or no to sharing three numbers. Yes draws their lane, marked "from
 * their report", on the inviter's timeline. One hop: a link belongs to one inviter's
 * form_progress row and nothing is followed further. Either side ends it.
 *
 * The legal line (programme § 3a), as built:
 *   1. nothing is sent to an invitee before their yes; the inviter shares the link;
 *   2. exactly three values cross: pace of ageing, age, sex, copied at the yes and removed on
 *      a withdraw or an end;
 *   3. under 18: no consent;
 *   4. the yes needs the key the lead POST handed to the browser that sent the answers, so the
 *      inviter, who holds the link too, cannot say yes for them;
 *   5. a link that is unknown, not yet used, out of date, already decided or keyed wrongly gets
 *      one 404 body, and nothing looks up a person or an email;
 *   6. caps: 25 links per inviter in total, 10 made per UTC day.
 *
 * Routes (hdl-v2/v1), registered only with both flags on (hdlv2_ff_healthspan_timeline and
 * hdlv2_ff_timeline_links, absent = off):
 *   GET    /timeline/links       the inviter's links (header X-HDLV2-Token = their ?t= token)
 *   POST   /timeline/links       make one: {label, relationship, born_guess, sex_guess}
 *   DELETE /timeline/links/<id>  end one
 *   POST   /timeline/consent     the invitee: {invite_token, link_key, decision yes|no}
 *   POST   /timeline/withdraw    the invitee: {withdraw_token} from their confirmation email
 *
 * @package HDL_Longevity_V2
 * @since   0.48.34
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Timeline_Links {

    const FLAG        = 'hdlv2_ff_timeline_links';
    const MAX_TOTAL   = 25;
    const MAX_PER_DAY = 10;
    const DAYS        = 90;
    const REL         = array( 'spouse', 'child', 'grandchild', 'parent', 'sibling', 'friend', 'other' );
    const GONE        = 'This link is no longer valid.';
    const ADULTS      = 'This link is for adults. Your report is yours; nothing is shared.';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function enabled() {
        return HDLV2_Healthspan_Timeline::enabled() && (bool) get_option( self::FLAG, false );
    }

    public static function register_routes() {
        if ( ! self::enabled() ) {
            return;
        }
        // The owner token, the ticket token or the withdraw token is the access; each handler checks its own.
        $open = '__return_true';
        register_rest_route( 'hdl-v2/v1', '/timeline/links', array(
            array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_list' ), 'permission_callback' => $open ),
            array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_create' ), 'permission_callback' => $open ),
        ) );
        register_rest_route( 'hdl-v2/v1', '/timeline/links/(?P<id>\d+)', array(
            'methods' => 'DELETE', 'callback' => array( __CLASS__, 'rest_revoke' ), 'permission_callback' => $open,
        ) );
        register_rest_route( 'hdl-v2/v1', '/timeline/consent', array(
            'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_consent' ), 'permission_callback' => $open,
        ) );
        register_rest_route( 'hdl-v2/v1', '/timeline/withdraw', array(
            'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_withdraw' ), 'permission_callback' => $open,
        ) );
    }

    // ── the inviter ────────────────────────────────────────────────

    public static function rest_list( $request ) {
        $owner = self::owner( $request );
        if ( is_wp_error( $owner ) ) {
            return $owner;
        }
        return rest_ensure_response( array( 'links' => self::links_of( (int) $owner->id ) ) );
    }

    public static function rest_create( $request ) {
        $owner = self::owner( $request );
        if ( is_wp_error( $owner ) ) {
            return $owner;
        }
        $label = substr( sanitize_text_field( (string) $request->get_param( 'label' ) ), 0, 80 );
        $rel   = (string) $request->get_param( 'relationship' );
        if ( '' === $label || ! in_array( $rel, self::REL, true ) ) {
            return new WP_Error( 'invalid_body', 'A name and a relationship are needed.', array( 'status' => 400 ) );
        }
        $born = absint( $request->get_param( 'born_guess' ) );
        $born = $born > 1850 && $born < 2200 ? $born : null;
        $sex  = in_array( $request->get_param( 'sex_guess' ), array( 'F', 'M' ), true ) ? $request->get_param( 'sex_guess' ) : null;

        $page = self::link_page( (int) $owner->practitioner_user_id );
        if ( '' === $page ) {
            return new WP_Error( 'no_link_page', 'Ask your practitioner to set the report page.', array( 'status' => 409 ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_timeline_links';
        // The caps, the link row, its ticket (external_ref tl:<link id>) and the tie between them: all or
        // nothing, and one inviter's posts in turn (the lock on their record), so two at once cannot pass a cap.
        $wpdb->query( 'START TRANSACTION' );
        $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d FOR UPDATE", (int) $owner->id ) );
        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE owner_progress_id = %d", (int) $owner->id ) );
        $today = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE owner_progress_id = %d AND created_at >= %s",
            (int) $owner->id, gmdate( 'Y-m-d 00:00:00' )
        ) );
        if ( $total >= self::MAX_TOTAL || $today >= self::MAX_PER_DAY ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'link_limit', $total >= self::MAX_TOTAL ? 'You have reached the limit of 25 links.' : 'You have made 10 links today. You can make more tomorrow.', array( 'status' => 429 ) );
        }
        $now = gmdate( 'Y-m-d H:i:s' );
        $id = $wpdb->insert( $table, array(
            'owner_progress_id' => (int) $owner->id,
            'label'             => $label,
            'relationship'      => $rel,
            'born_guess'        => $born,
            'sex_guess'         => $sex,
            'status'            => 'invited',
            'created_at'        => $now,
            'updated_at'        => $now,
        ) ) ? (int) $wpdb->insert_id : 0;
        $ticket = $id ? HDL_Stage1_Ticket::mint_timeline_link( (int) $owner->practitioner_user_id, $label, 'tl:' . $id, self::DAYS ) : null;
        if ( ! is_array( $ticket ) || ! $wpdb->update( $table, array( 'invite_id' => (int) $ticket['id'] ), array( 'id' => $id ) ) ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( '[HDLV2 timeline-links] link not saved for progress ' . (int) $owner->id );
            return new WP_Error( 'link_not_saved', 'The link could not be made. Please try again.', array( 'status' => 500 ) );
        }
        $wpdb->query( 'COMMIT' );

        return new WP_REST_Response( array(
            'id'   => $id,
            'url'  => add_query_arg( 'invite', $ticket['token'], $page ),
            'link' => self::shape( (object) array(
                'id' => $id, 'label' => $label, 'relationship' => $rel, 'born_guess' => $born, 'sex_guess' => $sex,
                'status' => 'invited', 'shared_rate' => null, 'shared_age' => null, 'shared_sex' => null,
            ) ),
        ), 201 );
    }

    public static function rest_revoke( $request ) {
        $owner = self::owner( $request );
        if ( is_wp_error( $owner ) ) {
            return $owner;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_timeline_links';
        $link  = $wpdb->get_row( $wpdb->prepare( "SELECT id, owner_progress_id, invite_id, status FROM $table WHERE id = %d LIMIT 1", absint( $request['id'] ) ) );
        if ( ! $link || (int) $link->owner_progress_id !== (int) $owner->id ) {
            return self::gone();
        }
        if ( 'revoked' !== $link->status ) {
            $now = gmdate( 'Y-m-d H:i:s' );
            $wpdb->update( $table, self::cleared( array( 'status' => 'revoked', 'revoked_at' => $now, 'updated_at' => $now ) ), array( 'id' => (int) $link->id ) );
            // The link itself stops working too: the widget shows its "not valid" card.
            if ( $link->invite_id ) {
                $wpdb->update( $wpdb->prefix . 'hdlv2_widget_invites', array( 'status' => 'revoked' ), array( 'id' => (int) $link->invite_id ) );
            }
        }
        return rest_ensure_response( array( 'id' => (int) $link->id, 'status' => 'revoked' ) );
    }

    // ── the invitee ────────────────────────────────────────────────

    public static function rest_consent( $request ) {
        $decision = $request->get_param( 'decision' );
        if ( ! in_array( $decision, array( 'yes', 'no' ), true ) ) {
            return new WP_Error( 'invalid_body', 'Answer yes or no.', array( 'status' => 400 ) );
        }
        $ctx = self::consent_context( (string) $request->get_param( 'invite_token' ), (string) $request->get_param( 'link_key' ) );
        if ( ! $ctx ) {
            return self::gone();
        }
        list( $ticket, $link, $lead ) = $ctx;
        if ( (int) $lead->visitor_age < 18 ) {
            return new WP_Error( 'adults_only', self::ADULTS, array( 'status' => 403 ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_timeline_links';
        $now   = gmdate( 'Y-m-d H:i:s' );

        if ( 'no' === $decision ) {
            if ( 'invited' === $link->status ) {
                $wpdb->update( $table, array( 'status' => 'declined', 'updated_at' => $now ), array( 'id' => (int) $link->id, 'status' => 'invited' ) );
                $link->status = 'declined';
            }
            return 'declined' === $link->status ? rest_ensure_response( array( 'status' => 'declined' ) ) : self::gone();
        }

        $owner = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, client_name, client_email, token FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d AND deleted_at IS NULL LIMIT 1",
            (int) $link->owner_progress_id
        ) );
        if ( ! $owner ) {
            return self::gone();
        }
        $answer = array( 'status' => 'accepted', 'link_from' => self::first_name( $owner->client_name, $owner->client_email ), 'label' => (string) $link->label );
        if ( 'accepted' === $link->status ) {
            return rest_ensure_response( $answer ); // a second yes: same state, no second email
        }
        if ( 'invited' !== $link->status ) {
            return self::gone();
        }

        // DB5 — the snapshot at the yes. Sex from the answers stored with the lead, never from the request.
        $answers  = json_decode( (string) $lead->stage1_data, true );
        $sex      = strtolower( (string) ( $answers['q1_sex'] ?? '' ) );
        $withdraw = bin2hex( random_bytes( 32 ) );
        $done     = $wpdb->update( $table, array(
            'status'              => 'accepted',
            'lead_id'             => (int) $lead->id,
            'shared_rate'         => null === $lead->rate_of_ageing ? null : round( (float) $lead->rate_of_ageing, 2 ),
            'shared_age'          => (int) $lead->visitor_age,
            'shared_sex'          => 'male' === $sex ? 'M' : ( 'female' === $sex ? 'F' : null ),
            'withdraw_token_hash' => hash( 'sha256', $withdraw ),
            'consent_at'          => $now,
            'updated_at'          => $now,
        ), array( 'id' => (int) $link->id, 'status' => 'invited' ) );
        if ( ! $done ) {
            // A twin of this request said yes first: its answer stands and it sent the emails.
            $status = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM $table WHERE id = %d LIMIT 1", (int) $link->id ) );
            return $status && 'accepted' === $status->status ? rest_ensure_response( $answer ) : self::gone();
        }
        error_log( '[HDLV2 timeline-links] link ' . (int) $link->id . ' accepted' );

        $page = self::link_page( (int) $ticket->practitioner_id );
        self::send_consent_emails( $owner, $link, $lead, add_query_arg( 'withdraw', $withdraw, '' !== $page ? $page : site_url( '/rate-of-ageing-widget/' ) ) );
        return rest_ensure_response( $answer );
    }

    public static function rest_withdraw( $request ) {
        $token = (string) $request->get_param( 'withdraw_token' );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            return self::gone();
        }
        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_timeline_links';
        $link  = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, owner_progress_id FROM $table WHERE withdraw_token_hash = %s AND status = 'accepted' LIMIT 1",
            hash( 'sha256', $token )
        ) );
        if ( ! $link ) {
            return self::gone();
        }
        $now = gmdate( 'Y-m-d H:i:s' );
        if ( ! $wpdb->update( $table, self::cleared( array( 'status' => 'withdrawn', 'withdrawn_at' => $now, 'updated_at' => $now ) ), array( 'id' => (int) $link->id, 'status' => 'accepted' ) ) ) {
            return self::gone();
        }
        error_log( '[HDLV2 timeline-links] link ' . (int) $link->id . ' withdrawn' );
        $owner = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, client_name, client_email FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d AND deleted_at IS NULL LIMIT 1",
            (int) $link->owner_progress_id
        ) );
        return rest_ensure_response( array( 'status' => 'withdrawn', 'link_from' => $owner ? self::first_name( $owner->client_name, $owner->client_email ) : 'Someone' ) );
    }

    // ── for other classes ──────────────────────────────────────────

    /**
     * The consent key: handed only to the browser that posted the answers (the lead POST reply
     * of a timeline_link ticket), checked at the yes. Server-keyed, so the ticket token alone
     * (which the inviter holds) cannot produce it.
     */
    public static function consent_key( $invite_id, $lead_id ) {
        return hash_hmac( 'sha256', 'hdlv2-timeline-consent|' . (int) $invite_id . '|' . (int) $lead_id, wp_salt( 'auth' ) );
    }

    /** /widget/verify-invite: the inviter's first name for the widget's line and card; '' without a live link. */
    public static function link_from( $invite_id ) {
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT fp.client_name FROM {$wpdb->prefix}hdlv2_timeline_links l
             JOIN {$wpdb->prefix}hdlv2_form_progress fp ON fp.id = l.owner_progress_id
             WHERE l.invite_id = %d AND fp.deleted_at IS NULL LIMIT 1",
            (int) $invite_id
        ) );
        return null === $name ? '' : self::first_name( $name );
    }

    /** The timeline page's HDLV2_HT.links: full mode only, for the client (or a visitor with no cookie), with a live token. */
    public static function page_config( $row ) {
        $uid = get_current_user_id();
        if ( ! self::enabled() || ! self::live( $row ) || ( $uid && $uid !== (int) $row->client_user_id ) ) {
            return array();
        }
        return array( 'url' => rest_url( 'hdl-v2/v1/timeline/links' ), 'nonce' => wp_create_nonce( 'wp_rest' ) );
    }

    /** The dashboard's "<n> people joined through you": links accepted on the client's live records. */
    public static function joined_count( $user_id ) {
        if ( ! self::enabled() ) {
            return 0;
        }
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hdlv2_timeline_links l JOIN {$wpdb->prefix}hdlv2_form_progress fp ON fp.id = l.owner_progress_id
             WHERE fp.client_user_id = %d AND fp.deleted_at IS NULL AND l.status = 'accepted'",
            (int) $user_id
        ) );
    }

    /**
     * DB6 — a client deleted (the practitioner's delete, the admin purge): the links they made and
     * the links they consented to as an invitee go. Runs whatever the flags say.
     *
     * @return int rows deleted.
     */
    public static function forget_client( $practitioner_id, $client_id, $client_email ) {
        if ( version_compare( (string) get_option( 'hdlv2_db_version', '0' ), '3.32', '<' ) ) {
            return 0;
        }
        global $wpdb;
        $p = $wpdb->prefix;
        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$p}hdlv2_timeline_links
             WHERE owner_progress_id IN ( SELECT id FROM {$p}hdlv2_form_progress WHERE practitioner_user_id = %d AND client_user_id = %d )
                OR lead_id IN ( SELECT id FROM {$p}hdlv2_widget_leads WHERE practitioner_user_id = %d AND LOWER(visitor_email) = %s )",
            (int) $practitioner_id, (int) $client_id, (int) $practitioner_id, strtolower( trim( (string) $client_email ) )
        ) );
    }

    // ── internals ──────────────────────────────────────────────────

    /** The inviter's record from X-HDLV2-Token, resolved as the timeline page resolves ?t=, and the Full Report gate. */
    private static function owner( $request ) {
        $token = (string) $request->get_header( 'x_hdlv2_token' );
        $row   = null;
        if ( preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            global $wpdb;
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, client_user_id, practitioner_user_id, client_name, client_email, token_expires_at
                 FROM {$wpdb->prefix}hdlv2_form_progress WHERE token = %s AND deleted_at IS NULL LIMIT 1",
                $token
            ) );
        }
        if ( ! $row || ! self::live( $row ) ) {
            return new WP_Error( 'link_expired', HDLV2_Healthspan_Timeline::EXPIRED, array( 'status' => 401 ) );
        }
        // Consent and invitations are the client's own: a practitioner or another signed-in client never acts for them.
        $uid = get_current_user_id();
        if ( $uid && $uid !== (int) $row->client_user_id ) {
            return new WP_Error( 'not_owner', 'Only the client can invite people to their timeline.', array( 'status' => 403 ) );
        }
        if ( ! HDLV2_Healthspan_Timeline::report_row( (int) $row->id ) ) {
            return new WP_Error( 'full_report_needed', 'Linking people comes with the full report.', array( 'status' => 403 ) );
        }
        return $row;
    }

    /** As the report page: a missing, garbage or past expiry is expired (fail closed). */
    private static function live( $row ) {
        $ts = empty( $row->token_expires_at ) ? false : strtotime( $row->token_expires_at . ' UTC' );
        return false !== $ts && $ts >= time();
    }

    /** The ticket, its link and the lead it bought, or null for anything that is not a claimed, current link with the right key. */
    private static function consent_context( $token, $key ) {
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) || ! preg_match( '/^[a-f0-9]{64}$/', $key ) ) {
            return null;
        }
        global $wpdb;
        $p      = $wpdb->prefix;
        $ticket = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, practitioner_id, status, expires_at FROM {$p}hdlv2_widget_invites WHERE token = %s AND source = 'timeline_link' LIMIT 1",
            $token
        ) );
        if ( ! $ticket || 'completed' !== $ticket->status || strtotime( $ticket->expires_at . ' UTC' ) < time() ) {
            return null;
        }
        $link = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}hdlv2_timeline_links WHERE invite_id = %d LIMIT 1", (int) $ticket->id ) );
        $lead = $link ? $wpdb->get_row( $wpdb->prepare(
            "SELECT id, visitor_name, visitor_email, visitor_age, rate_of_ageing, stage1_data FROM {$p}hdlv2_widget_leads
             WHERE invite_id = %d AND practitioner_user_id = %d ORDER BY id DESC LIMIT 1",
            (int) $ticket->id, (int) $ticket->practitioner_id
        ) ) : null;
        if ( ! $lead || ! hash_equals( self::consent_key( $ticket->id, $lead->id ), $key ) ) {
            return null;
        }
        return array( $ticket, $link, $lead );
    }

    private static function links_of( $owner_id ) {
        global $wpdb;
        $p    = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT l.id, l.label, l.relationship, l.born_guess, l.sex_guess, l.status, l.shared_rate, l.shared_age, l.shared_sex, i.expires_at
             FROM {$p}hdlv2_timeline_links l LEFT JOIN {$p}hdlv2_widget_invites i ON i.id = l.invite_id
             WHERE l.owner_progress_id = %d ORDER BY l.id",
            (int) $owner_id
        ) );
        $out = array();
        foreach ( (array) $rows as $r ) {
            // A link nobody answered ends with its ticket (90 days).
            if ( 'invited' === $r->status && ( empty( $r->expires_at ) || strtotime( $r->expires_at . ' UTC' ) < time() ) ) {
                $wpdb->update( $p . 'hdlv2_timeline_links', array( 'status' => 'expired', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $r->id, 'status' => 'invited' ) );
                $r->status = 'expired';
            }
            $out[] = self::shape( $r );
        }
        return $out;
    }

    /** What the inviter's page gets for one link: never a token, an email or a lead. */
    private static function shape( $r ) {
        return array(
            'id'           => (int) $r->id,
            'label'        => (string) $r->label,
            'relationship' => (string) $r->relationship,
            'born_guess'   => $r->born_guess ? (int) $r->born_guess : null,
            'sex_guess'    => $r->sex_guess ? (string) $r->sex_guess : null,
            'status'       => (string) $r->status,
            'shared'       => 'accepted' === $r->status && null !== $r->shared_rate
                ? array( 'rate' => (float) $r->shared_rate, 'age' => (int) $r->shared_age, 'sex' => $r->shared_sex ? (string) $r->shared_sex : null )
                : null,
        );
    }

    /** A withdraw or an end: the shared values and the withdraw token go with it. */
    private static function cleared( $set ) {
        return $set + array( 'shared_rate' => null, 'shared_age' => null, 'shared_sex' => null, 'withdraw_token_hash' => null );
    }

    /** DB10 — the practitioner's ticket page as stored, in any access mode: https with a host, else ''. */
    private static function link_page( $practitioner_id ) {
        global $wpdb;
        $url = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT ticket_page_url FROM {$wpdb->prefix}hdlv2_widget_config WHERE practitioner_user_id = %d LIMIT 1",
            (int) $practitioner_id
        ) );
        $parts = wp_parse_url( $url );
        return is_array( $parts ) && ! empty( $parts['host'] ) && 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) ? $url : '';
    }

    private static function first_name( $name, $email = '' ) {
        $first = HDLV2_Email_Templates::derive_first_name( $name, $email );
        return 'there' === $first ? 'Someone' : substr( $first, 0, 40 );
    }

    private static function gone() {
        return new WP_Error( 'link_gone', self::GONE, array( 'status' => 404 ) );
    }

    private static function send_consent_emails( $owner, $link, $lead, $withdraw_url ) {
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        $data    = array(
            'owner_first' => self::first_name( $owner->client_name, $owner->client_email ),
            'label'       => (string) $link->label,
            'page_url'    => HDLV2_Healthspan_Timeline::report_url( $owner->token ),
            'withdraw'    => $withdraw_url,
        );
        if ( is_email( $owner->client_email ) ) {
            wp_mail( $owner->client_email, $data['label'] . ' shared their pace of ageing with you', HDLV2_Email_Templates::timeline_consent_owner( $data ), $headers );
        }
        if ( is_email( $lead->visitor_email ) ) {
            wp_mail( $lead->visitor_email, 'You are on ' . $data['owner_first'] . '\'s healthspan timeline', HDLV2_Email_Templates::timeline_consent_invitee( $data ), $headers );
        }
    }
}

HDLV2_Timeline_Links::init();
