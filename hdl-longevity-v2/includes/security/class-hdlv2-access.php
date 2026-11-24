<?php
/**
 * HDLV2_Access — one door check for the paid sections (v0.49.00, plan 14 part A).
 *
 * Quim, 2026-11-24: a client opens a paid section only when they bought it or the practitioner (or an
 * admin) let them in. decide() is the whole rule, as a table of facts; the doors ask state() or door():
 * the weekly check-in's routes and page, the client's Flight Plan routes and page, the weekly cron, and
 * the Timeline's owned() (through timeline_granted()). A locked route answers 403 `locked` and stores
 * nothing; a locked page shows card().
 *
 * Switch hdlv2_ff_access_gate, absent = off = today's behaviour on every door, and no fact is read.
 * The Timeline grant: three columns on hdlv2_form_progress (Phase AQ, DB 3.36), two routes registered
 * only with the switch on.
 *
 * @package HDL_Longevity_V2
 * @since   0.49.00
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Access {

    const OPTION = 'hdlv2_ff_access_gate';

    const OPEN                = 'open';
    const LOCKED_BUY          = 'locked_buy';
    const LOCKED_PRACTITIONER = 'locked_practitioner';

    /** Draft words for Matthew (plan 14 § 10). */
    const LOCKED_LINE = 'Your practitioner opens this after your consultation.';
    const NAMES       = array( 'checkin' => 'Weekly check-in', 'flight_plan' => 'Flight Plan' );

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function on() {
        return (bool) get_option( self::OPTION, false );
    }

    /**
     * The rule, pure. $facts (missing = no): gate, staff, form_row, final_report, plan, timeline_today,
     * timeline_grant. Switch off, every answer is today's.
     */
    public static function decide( array $facts, $section ) {
        $f = $facts + array( 'gate' => false, 'staff' => false, 'form_row' => false, 'final_report' => false, 'plan' => false, 'timeline_today' => false, 'timeline_grant' => false );
        if ( $f['staff'] ) {
            return self::OPEN;
        }
        switch ( $section ) {
            case 'full_report':
                return $f['form_row'] ? self::OPEN : self::LOCKED_BUY;
            case 'flight_plan':
            case 'checkin':
                return ! $f['gate'] || $f['final_report'] || $f['plan'] ? self::OPEN : self::LOCKED_PRACTITIONER;
            case 'timeline':
                return $f['timeline_today'] || ( $f['gate'] && $f['timeline_grant'] ) ? self::OPEN : self::LOCKED_BUY;
        }
        return self::LOCKED_PRACTITIONER;
    }

    /**
     * The facts for one form row (an object with id, client_user_id, practitioner_user_id) as $actor_id
     * sees it. Switch off: nothing is read. The Timeline's facts live in HDLV2_Timeline_Upgrade::owned().
     */
    public static function facts( $row, $section, $actor_id = 0 ) {
        $actor = (int) $actor_id;
        $f     = array( 'gate' => self::on(), 'form_row' => (bool) $row, 'staff' => false );
        if ( ! $f['gate'] || ! $row ) {
            return $f;
        }
        $f['staff'] = $actor && ( user_can( $actor, 'manage_options' )
            || ( (int) $row->practitioner_user_id === $actor && HDLV2_Compatibility::is_practitioner( $actor ) ) );
        if ( ! $f['staff'] && in_array( $section, array( 'flight_plan', 'checkin' ), true ) ) {
            global $wpdb;
            $f['final_report'] = (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}hdlv2_reports WHERE form_progress_id = %d AND report_type = 'final' LIMIT 1",
                (int) $row->id
            ) );
            $f['plan'] = ! $f['final_report'] && (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}hdlv2_flight_plans WHERE client_id = %d AND deleted_at IS NULL LIMIT 1",
                (int) $row->client_user_id
            ) );
        }
        return $f;
    }

    public static function state( $row, $section, $actor_id = 0 ) {
        return self::decide( self::facts( $row, $section, $actor_id ), $section );
    }

    /** The client's live form row, newest first (the one the dashboard reads). */
    public static function client_row( $client_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, client_user_id, practitioner_user_id, client_email FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE client_user_id = %d AND deleted_at IS NULL ORDER BY id DESC LIMIT 1",
            (int) $client_id
        ) );
    }

    /** A route's door: null = go on; a 403 `locked` error otherwise. Switch off: null, nothing read. */
    public static function door( $row, $section, $actor_id = 0 ) {
        if ( ! self::on() || self::OPEN === self::state( $row, $section, $actor_id ) ) {
            return null;
        }
        return new WP_Error( 'locked', self::LOCKED_LINE, array( 'status' => 403 ) );
    }

    /** door() for a client id (their live form row). Switch off: null, nothing read. */
    public static function client_door( $client_id, $section, $actor_id = 0 ) {
        return self::on() ? self::door( self::client_row( $client_id ), $section, $actor_id ) : null;
    }

    /**
     * The greyed card a locked page shows: the section's name, the line, a link to Messages. A section
     * a client buys never reaches it: the $27 and the $10 keep their own pages and buttons.
     */
    public static function card( $section, $row ) {
        $prac = $row ? get_userdata( (int) $row->practitioner_user_id ) : null;
        $name = $prac && '' !== (string) $prac->display_name ? (string) $prac->display_name : 'your practitioner';
        $url  = home_url( '/' . trim( apply_filters( 'hdlv2_client_dashboard_slug', 'my-dashboard' ), '/' ) . '/#tab-messages' );
        $lock = class_exists( 'HDLV2_Products' ) ? HDLV2_Products::LOCK : '';
        return '<div class="hdlv2-locked-card">'
            . '<h2 class="hdlv2-locked-head">' . $lock . '<span>' . esc_html( self::NAMES[ $section ] ?? '' ) . '</span></h2>'
            . '<p class="hdlv2-locked-line">' . esc_html( self::LOCKED_LINE ) . '</p>'
            . '<a class="hdlv2-locked-btn" href="' . esc_url( $url ) . '">' . esc_html( 'Ask ' . $name ) . '</a>'
            . '</div>';
    }

    /** An admin may Confirm any practitioner's lead (switch on): null = any lead; else the signed-in id. */
    public static function lead_scope( $user_id ) {
        $user_id = (int) $user_id;
        return $user_id && self::on() && user_can( $user_id, 'manage_options' ) ? null : $user_id;
    }

    // ── The Timeline grant (A4) ──

    /** Did the practitioner (or an admin) give this form row the Timeline, not taken back? Switch off: no. */
    public static function timeline_granted( $row ) {
        if ( ! self::on() || ! $row || empty( $row->id ) ) {
            return false;
        }
        if ( ! property_exists( $row, 'timeline_granted_at' ) ) {
            global $wpdb;
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT timeline_granted_at, timeline_grant_revoked_at FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d AND deleted_at IS NULL",
                (int) $row->id
            ) );
        }
        return $row && ! empty( $row->timeline_granted_at ) && empty( $row->timeline_grant_revoked_at );
    }

    /**
     * The control on the practitioner's client panel: give the Timeline to a buyer who did not take it,
     * or take back a grant. '' when the switch or the upgrade is off, or the client owns it already.
     */
    public static function grant_control( $client_id ) {
        if ( ! self::on() || ! class_exists( 'HDLV2_Timeline_Upgrade' ) || ! HDLV2_Timeline_Upgrade::enabled() ) {
            return '';
        }
        $row = self::client_row( $client_id );
        if ( ! $row ) {
            return '';
        }
        $granted = self::timeline_granted( $row );
        if ( ! $granted && HDLV2_Timeline_Upgrade::owned( $row ) ) {
            return '';
        }
        return '<div class="hdlv2-tl-grant" data-progress-id="' . (int) $row->id . '">'
            . '<p class="hdlv2-tl-grant-line" role="status">' . esc_html( $granted ? 'Timeline: opened by you.' : 'Timeline: not bought.' ) . '</p>'
            . '<button type="button" class="hdlv2-tl-grant-btn" data-tl-grant="' . ( $granted ? 'take-back' : 'give' ) . '">'
            . esc_html( $granted ? 'Take back Timeline access' : 'Give Timeline access' ) . '</button>'
            . '</div>';
    }

    public static function register_routes() {
        if ( ! self::on() ) {
            return;
        }
        register_rest_route( 'hdl-v2/v1', '/timeline-access/give', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_give' ),
            'permission_callback' => array( __CLASS__, 'may_grant' ),
        ) );
        register_rest_route( 'hdl-v2/v1', '/timeline-access/take-back', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_take_back' ),
            'permission_callback' => array( __CLASS__, 'may_grant' ),
        ) );
    }

    public static function may_grant() {
        return HDLV2_Compatibility::is_practitioner( get_current_user_id() );
    }

    public static function rest_give( $request ) {
        return self::set_grant( $request, true );
    }

    public static function rest_take_back( $request ) {
        return self::set_grant( $request, false );
    }

    /** Ownership first (the form's practitioner, or an admin; anyone else gets 404), then one UPDATE. */
    private static function set_grant( $request, $give ) {
        $params = $request->get_json_params();
        $id     = absint( is_array( $params ) ? ( $params['progress_id'] ?? 0 ) : 0 );
        if ( ! $id ) {
            return new WP_Error( 'missing', 'progress_id is required.', array( 'status' => 400 ) );
        }
        global $wpdb;
        $uid = get_current_user_id();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, practitioner_user_id FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d AND deleted_at IS NULL",
            $id
        ) );
        if ( ! $row || ( (int) $row->practitioner_user_id !== $uid && ! user_can( $uid, 'manage_options' ) ) ) {
            return new WP_Error( 'not_found', 'No such client.', array( 'status' => 404 ) );
        }
        $now  = current_time( 'mysql' );
        $data = $give
            ? array( 'timeline_granted_by' => $uid, 'timeline_granted_at' => $now, 'timeline_grant_revoked_at' => null )
            : array( 'timeline_grant_revoked_at' => $now );
        if ( false === $wpdb->update( $wpdb->prefix . 'hdlv2_form_progress', $data, array( 'id' => $id ) ) ) {
            return new WP_Error( 'save_failed', 'Could not save. Try again.', array( 'status' => 500 ) );
        }
        // v0.49.02 (plan 14 fix A5) — a trace of every give and take-back: the form row, who acted, which act.
        // The row keeps only the last give; this line keeps them all. No name, no e-mail.
        error_log( sprintf( '[HDLV2 Access] Timeline %s: form %d, by user %d', $give ? 'give' : 'take-back', $id, $uid ) );
        return rest_ensure_response( array( 'granted' => $give, 'at' => $now ) );
    }
}

HDLV2_Access::init();
