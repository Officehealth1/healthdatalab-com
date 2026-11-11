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
 * The family pack (v0.48.35, DB 3.33, also needs hdlv2_ff_timeline_pack): one payer's seats. A
 * seat unlocks one invitee's own Full Report at their yes; their lane stays on the payer's
 * timeline. The email is the one they typed, never verified, and the link signs its owner in:
 * it goes to that inbox only, and the answer to the browser is the same whether or not the email
 * already holds a report.
 *   POST /timeline/packs            the practitioner grants: {progress_id}
 *   POST /timeline/packs/<id>/end   the practitioner ends one; reports already given stay
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
    const PACK_FLAG   = 'hdlv2_ff_timeline_pack';
    const PACK_SEATS  = 5;

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function enabled() {
        return HDLV2_Healthspan_Timeline::enabled() && (bool) get_option( self::FLAG, false );
    }

    public static function pack_enabled() {
        // Phase AN must have landed: with the flag on while it retries, the list's columns would not exist.
        return self::enabled() && (bool) get_option( self::PACK_FLAG, false ) && version_compare( (string) get_option( 'hdlv2_db_version', '0' ), '3.33', '>=' );
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
        if ( self::pack_enabled() ) {
            $staff = function () {
                return HDLV2_Compatibility::is_practitioner( get_current_user_id() ) || current_user_can( 'manage_options' );
            };
            register_rest_route( 'hdl-v2/v1', '/timeline/packs', array(
                'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_grant_pack' ), 'permission_callback' => $staff,
            ) );
            register_rest_route( 'hdl-v2/v1', '/timeline/packs/(?P<id>\d+)/end', array(
                'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_end_pack' ), 'permission_callback' => $staff,
            ) );
        }
    }

    // ── the inviter ────────────────────────────────────────────────

    public static function rest_list( $request ) {
        $owner = self::owner( $request );
        if ( is_wp_error( $owner ) ) {
            return $owner;
        }
        $out = array( 'links' => self::links_of( (int) $owner->id, self::link_page( (int) $owner->practitioner_user_id ) ) );
        if ( self::pack_enabled() ) {
            $out['pack'] = self::pack_of( (int) $owner->id );
        }
        return rest_ensure_response( $out );
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
            return rest_ensure_response( $answer + self::seat_answer( $link ) ); // a second yes: same state, no second email
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
            $status = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d LIMIT 1", (int) $link->id ) );
            return $status && 'accepted' === $status->status ? rest_ensure_response( $answer + self::seat_answer( $status ) ) : self::gone();
        }
        error_log( '[HDLV2 timeline-links] link ' . (int) $link->id . ' accepted' );

        $seat = self::take_seat( $link, $lead, (int) $ticket->practitioner_id );
        $page = self::link_page( (int) $ticket->practitioner_id );
        $sent = self::send_consent_emails( $owner, $link, $lead, add_query_arg( 'withdraw', $withdraw, '' !== $page ? $page : site_url( '/rate-of-ageing-widget/' ) ), $seat );
        if ( 'sent' === $seat['state'] && ! $sent ) {
            // The ticket and the client record exist and the seat is used: the practitioner's Sent Invites can resend it.
            error_log( '[HDLV2 timeline-links] link ' . (int) $link->id . ', lead ' . (int) $lead->id . ': the Full Report email did not go' );
            $seat['state'] = 'unsent';
        }
        return rest_ensure_response( $answer + ( self::pack_enabled() ? array( 'full_report' => array( 'state' => $seat['state'] ) ) : array() ) );
    }

    // ── the family pack ────────────────────────────────────────────

    public static function rest_grant_pack( $request ) {
        $row = self::practitioner_client( absint( $request->get_param( 'progress_id' ) ) );
        if ( ! $row ) {
            return self::gone();
        }
        if ( ! HDLV2_Healthspan_Timeline::report_row( (int) $row->id ) ) {
            return new WP_Error( 'full_report_needed', 'This client needs a Full Report first.', array( 'status' => 409 ) );
        }
        global $wpdb;
        $packs = $wpdb->prefix . 'hdlv2_timeline_packs';
        // One active pack per client: the lock on their record puts two grants in turn.
        $wpdb->query( 'START TRANSACTION' );
        $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d FOR UPDATE", (int) $row->id ) );
        $now_pack = self::pack_of( (int) $row->id );
        if ( $now_pack && 'active' === $now_pack['status'] ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'pack_exists', 'This client already has a family pack', array( 'status' => 409 ) );
        }
        $now = gmdate( 'Y-m-d H:i:s' );
        $ok  = $wpdb->insert( $packs, array(
            'owner_progress_id' => (int) $row->id,
            'seats_total'       => self::PACK_SEATS,
            'seats_used'        => 0,
            'status'            => 'active',
            'source'            => 'practitioner',
            'created_at'        => $now,
            'updated_at'        => $now,
        ) );
        if ( ! $ok ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( '[HDLV2 timeline-links] pack not saved for progress ' . (int) $row->id );
            return new WP_Error( 'pack_not_saved', 'The pack could not be granted. Please try again.', array( 'status' => 500 ) );
        }
        $wpdb->query( 'COMMIT' );
        return new WP_REST_Response( array( 'pack' => self::pack_of( (int) $row->id ) ), 201 );
    }

    /** No new seat can be used; reports already given stay (a report given is not taken back). */
    public static function rest_end_pack( $request ) {
        global $wpdb;
        $packs = $wpdb->prefix . 'hdlv2_timeline_packs';
        $pack  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $packs WHERE id = %d LIMIT 1", absint( $request['id'] ) ) );
        if ( ! $pack || ! self::practitioner_client( (int) $pack->owner_progress_id ) ) {
            return self::gone();
        }
        $now = gmdate( 'Y-m-d H:i:s' );
        $wpdb->update( $packs, array( 'status' => 'ended', 'ended_at' => $now, 'updated_at' => $now ), array( 'id' => (int) $pack->id, 'status' => 'active' ) );
        return rest_ensure_response( array( 'pack' => self::pack_of( (int) $pack->owner_progress_id ) ) );
    }

    /**
     * The seat at a yes, called once per link, after the link is accepted. The lock puts this
     * client's yeses and an End pack in turn. It is committed before the ticket is made, because
     * confirm_lead_row() opens its own transaction and that would silently commit this one; a
     * ticket that fails gives the seat back.
     *
     * The caller turns sent into unsent when the email does not go.
     *
     * @return array { state: sent|none|failed, kind: unlocked|existing, url } kind and url only when sent.
     */
    private static function take_seat( $link, $lead, $practitioner_id ) {
        $none = array( 'state' => 'none' );
        if ( ! self::pack_enabled() || ! is_email( $lead->visitor_email ) ) {
            return $none;
        }
        global $wpdb;
        $links = $wpdb->prefix . 'hdlv2_timeline_links';
        $packs = $wpdb->prefix . 'hdlv2_timeline_packs';
        $email = (string) $lead->visitor_email;
        $now   = gmdate( 'Y-m-d H:i:s' );

        $wpdb->query( 'START TRANSACTION' );
        $pack = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, seats_total, seats_used FROM $packs WHERE owner_progress_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1 FOR UPDATE",
            (int) $link->owner_progress_id
        ) );
        if ( ! $pack || (int) $pack->seats_used >= (int) $pack->seats_total ) {
            $wpdb->query( 'COMMIT' );
            return $none;
        }
        $held = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, token, status, expires_at FROM {$wpdb->prefix}hdlv2_widget_invites
             WHERE practitioner_id = %d AND LOWER(client_email) = %s AND source = 'full_report' AND status <> 'revoked' ORDER BY id DESC LIMIT 1",
            (int) $practitioner_id, strtolower( $email )
        ) );
        if ( ! $held ) {
            if ( ! $wpdb->update( $packs, array( 'seats_used' => (int) $pack->seats_used + 1, 'updated_at' => $now ), array( 'id' => (int) $pack->id ) )
                || ! $wpdb->update( $links, array( 'pack_id' => (int) $pack->id ), array( 'id' => (int) $link->id ) ) ) {
                $wpdb->query( 'ROLLBACK' );
                error_log( '[HDLV2 timeline-links] link ' . (int) $link->id . ': the seat could not be counted' );
                return array( 'state' => 'failed' );
            }
        }
        $wpdb->query( 'COMMIT' );

        $ref = 'fam:' . (int) $pack->id . ':' . (int) $link->id;
        if ( $held ) {
            // Already theirs: no seat. A used or out-of-date ticket no longer opens, so it gets a new one.
            $live = in_array( $held->status, array( 'pending', 'opened' ), true ) && strtotime( $held->expires_at . ' UTC' ) > time();
            $use  = $live ? array( 'id' => $held->id, 'token' => $held->token ) : HDL_Stage1_Ticket::mint( $practitioner_id, $email, (string) $lead->visitor_name, $ref, self::DAYS, 'full_report' );
            if ( is_wp_error( $use ) ) {
                return array( 'state' => 'failed' );
            }
            $wpdb->update( $links, array( 'seat_invite_id' => (int) $use['id'] ), array( 'id' => (int) $link->id ) );
            return array( 'state' => 'sent', 'kind' => 'existing', 'url' => HDL_Stage1_Ticket::open_url( $use['token'] ) );
        }

        $ticket = HDL_Stage1_Ticket::mint_family_seat( $practitioner_id, $email, (string) $lead->visitor_name, $ref, self::DAYS );
        // open_url is null with hdlv2_full_report_auto_confirm off: nothing to send, so no seat either.
        if ( is_wp_error( $ticket ) || empty( $ticket['open_url'] ) ) {
            error_log( '[HDLV2 timeline-links] link ' . (int) $link->id . ': no Full Report ticket, seat given back' );
            self::give_seat_back( (int) $pack->id, (int) $link->id );
            return array( 'state' => 'failed' );
        }
        $wpdb->update( $links, array( 'seat_invite_id' => (int) $ticket['id'] ), array( 'id' => (int) $link->id ) );
        return array( 'state' => 'sent', 'kind' => 'unlocked', 'url' => $ticket['open_url'] );
    }

    private static function give_seat_back( $pack_id, $link_id ) {
        global $wpdb;
        $packs = $wpdb->prefix . 'hdlv2_timeline_packs';
        $wpdb->query( 'START TRANSACTION' );
        $used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT seats_used FROM $packs WHERE id = %d FOR UPDATE", $pack_id ) );
        // Only the request that clears the link's seat counts it back: two repeat yeses at once give back one.
        if ( $wpdb->update( $wpdb->prefix . 'hdlv2_timeline_links', array( 'pack_id' => null ), array( 'id' => $link_id, 'pack_id' => $pack_id ) ) ) {
            $wpdb->update( $packs, array( 'seats_used' => max( 0, $used - 1 ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $pack_id ) );
        }
        $wpdb->query( 'COMMIT' );
    }

    /**
     * The answer to a repeat yes, from what the first one left. A seat with no ticket a minute on
     * means the worker died between the two: the seat comes back. Sooner, the first is still working.
     */
    private static function seat_answer( $link ) {
        if ( ! self::pack_enabled() ) {
            return array();
        }
        $state = 'none';
        if ( ! empty( $link->seat_invite_id ) ) {
            $state = 'sent';
        } elseif ( ! empty( $link->pack_id ) ) {
            $state = 'sent';
            if ( strtotime( (string) $link->consent_at . ' UTC' ) < time() - 60 ) {
                self::give_seat_back( (int) $link->pack_id, (int) $link->id );
                $state = 'failed';
            }
        }
        return array( 'full_report' => array( 'state' => $state ) );
    }

    /** The client's record when the signed-in practitioner owns it (or an admin asks), else null. */
    private static function practitioner_client( $progress_id ) {
        global $wpdb;
        $row = $progress_id ? $wpdb->get_row( $wpdb->prepare(
            "SELECT id, practitioner_user_id FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d AND deleted_at IS NULL LIMIT 1",
            (int) $progress_id
        ) ) : null;
        return $row && ( (int) $row->practitioner_user_id === get_current_user_id() || current_user_can( 'manage_options' ) ) ? $row : null;
    }

    /** The client's newest pack: one active at most, so an active one is always the newest. */
    private static function pack_of( $owner_id ) {
        global $wpdb;
        $r = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, seats_total, seats_used, status FROM {$wpdb->prefix}hdlv2_timeline_packs WHERE owner_progress_id = %d ORDER BY id DESC LIMIT 1",
            (int) $owner_id
        ) );
        return $r ? array( 'id' => (int) $r->id, 'seats_total' => (int) $r->seats_total, 'seats_used' => (int) $r->seats_used, 'status' => (string) $r->status ) : null;
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
        return array( 'url' => rest_url( 'hdl-v2/v1/timeline/links' ), 'nonce' => wp_create_nonce( 'wp_rest' ) )
            + ( self::pack_enabled() ? array( 'family' => true ) : array() );
    }

    /** /dashboard/clients: the row's family pack line; null with the flag off or no record. */
    public static function dashboard_pack( $progress_id ) {
        if ( ! $progress_id || ! self::pack_enabled() ) {
            return null;
        }
        global $wpdb;
        $has_report = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}hdlv2_reports WHERE form_progress_id = %d AND report_type IN ('final','draft') LIMIT 1",
            (int) $progress_id
        ) );
        return array( 'can_grant' => (bool) $has_report, 'pack' => self::pack_of( (int) $progress_id ) );
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
        if ( version_compare( (string) get_option( 'hdlv2_db_version', '0' ), '3.33', '>=' ) ) {
            $now = gmdate( 'Y-m-d H:i:s' );
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$p}hdlv2_timeline_packs SET status = 'ended', ended_at = %s, updated_at = %s
                 WHERE status = 'active' AND owner_progress_id IN ( SELECT id FROM {$p}hdlv2_form_progress WHERE practitioner_user_id = %d AND client_user_id = %d )",
                $now, $now, (int) $practitioner_id, (int) $client_id
            ) );
        }
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
        // A seat's confirm moves the lead's invite_id on to the Full Report ticket: after the yes, the link knows its lead.
        if ( ! $lead && $link && ! empty( $link->lead_id ) ) {
            $lead = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, visitor_name, visitor_email, visitor_age, rate_of_ageing, stage1_data FROM {$p}hdlv2_widget_leads WHERE id = %d AND practitioner_user_id = %d LIMIT 1",
                (int) $link->lead_id, (int) $ticket->practitioner_id
            ) );
        }
        if ( ! $lead || ! hash_equals( self::consent_key( $ticket->id, $lead->id ), $key ) ) {
            return null;
        }
        return array( $ticket, $link, $lead );
    }

    /** @param string $page the practitioner's ticket page, '' when unset: an open link's url is built on it. */
    private static function links_of( $owner_id, $page = '' ) {
        global $wpdb;
        $p    = $wpdb->prefix;
        $seat = self::pack_enabled() ? ', l.pack_id, l.seat_invite_id' : ''; // absent until Phase AN
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT l.id, l.label, l.relationship, l.born_guess, l.sex_guess, l.status, l.shared_rate, l.shared_age, l.shared_sex{$seat}, i.expires_at,
                    i.token AS ticket_token, i.status AS ticket_status
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
            $out[] = self::shape( $r, $page );
        }
        return $out;
    }

    /**
     * What the inviter's page gets for one link: never an email or a lead. A token only inside `url`, and only
     * while the link can still be used: the link is invited and its ticket pending or opened, in date, with a
     * report page to build on (v0.48.62, so the client can copy it again). Never for an accepted, declined,
     * withdrawn, revoked or expired link, nor once the person has used the ticket.
     */
    private static function shape( $r, $page = '' ) {
        // The chip is the seat the payer gave. A report the person already held is not theirs to see: three numbers is what was agreed.
        $seat = self::pack_enabled() ? array( 'full_report' => 'accepted' === $r->status && ! empty( $r->pack_id ) && ! empty( $r->seat_invite_id ) ) : array();
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
        ) + $seat + ( self::open_link( $r ) && '' !== $page ? array( 'url' => add_query_arg( 'invite', $r->ticket_token, $page ) ) : array() );
    }

    private static function open_link( $r ) {
        return 'invited' === $r->status && ! empty( $r->ticket_token ) && in_array( $r->ticket_status ?? '', array( 'pending', 'opened' ), true )
            && ! empty( $r->expires_at ) && strtotime( $r->expires_at . ' UTC' ) >= time();
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

    /** @return bool the invitee's email went. */
    private static function send_consent_emails( $owner, $link, $lead, $withdraw_url, $seat ) {
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        $data    = array(
            'owner_first' => self::first_name( $owner->client_name, $owner->client_email ),
            'label'       => (string) $link->label,
            'page_url'    => HDLV2_Healthspan_Timeline::report_url( $owner->token ),
            'withdraw'    => $withdraw_url,
            'seat_used'   => 'unlocked' === ( $seat['kind'] ?? '' ),
            'report_url'  => $seat['url'] ?? '',
            'report_kind' => $seat['kind'] ?? '',
        );
        if ( is_email( $owner->client_email ) ) {
            wp_mail( $owner->client_email, $data['label'] . ' shared their pace of ageing with you', HDLV2_Email_Templates::timeline_consent_owner( $data ), $headers );
        }
        if ( ! is_email( $lead->visitor_email ) ) {
            return false;
        }
        return (bool) wp_mail( $lead->visitor_email, 'You are on ' . $data['owner_first'] . '\'s healthspan timeline', HDLV2_Email_Templates::timeline_consent_invitee( $data ), $headers );
    }
}

HDLV2_Timeline_Links::init();
