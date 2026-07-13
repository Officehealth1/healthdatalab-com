<?php
/**
 * Client Status Calculator — V2 status labels for practitioner dashboard.
 *
 * @package HDL_Longevity_V2
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class HDLV2_Client_Status {

    const NOT_STARTED           = 'not_started';
    const LOW_DATA              = 'low_data';
    const AWAITING_WHY_RELEASE  = 'awaiting_why_release';
    const STAGE3_IN_PROGRESS    = 'stage3_in_progress';
    const AWAITING_CONSULT      = 'awaiting_consult';
    const ACTIVE                = 'active';
    const PROGRESS_NORMAL       = 'progress_normal';
    const NEEDS_ATTENTION       = 'needs_attention';
    const INACTIVE              = 'inactive';

    const LABELS = array(
        'not_started'          => 'Not Started',
        'low_data'             => 'Low Data',
        'awaiting_why_release' => 'Ready to invite',
        'stage3_in_progress'   => 'Stage 3 In Progress',
        'awaiting_consult'     => 'Awaiting Consult',
        'active'               => 'Active',
        'progress_normal'      => 'Progress Normal',
        'needs_attention'      => 'Client Needs Attention',
        'inactive'             => 'Inactive',
    );

    const COLORS = array(
        'not_started'          => '#94a3b8',
        'low_data'             => '#f59e0b',
        'awaiting_why_release' => '#d97706',
        'stage3_in_progress'   => '#0ea5e9',
        'awaiting_consult'     => '#3b82f6',
        'active'               => '#10b981',
        'progress_normal'      => '#10b981',
        'needs_attention'      => '#dc2626',
        'inactive'             => '#6b7280',
    );

    private static $instance = null;
    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {}

    /**
     * P1b (2026-07-13, action-button unify) — pure status → resend-link behaviour.
     *
     * The stage-aware core of the "my link expired / I lost the email" resend
     * action. Keeps the branching side-effect-free and exhaustively testable;
     * the REST handler wires DB + email + audit around it.
     *
     * Buckets (per Quim's signed-off spec):
     *   - Assessment-continuable (not_started / low_data / stage3_in_progress):
     *     ENABLED, one URL (/assessment/?token=, routes by current_stage), the
     *     stage drives the email copy + label. D5: Stage 3 uses "Stage-3 link",
     *     never flight-plan wording.
     *   - Blocked on the practitioner (awaiting_why_release / awaiting_consult):
     *     DISABLED with a reason — the client has nothing to continue.
     *   - COMPLETE (active / progress_normal / needs_attention / inactive):
     *     ENABLED, a READ-ONLY report/plan link (must not reopen the funnel).
     *     D2: the label names the ACTUAL artefact — "Resend flight plan" when a
     *     plan exists, else "Resend report". Never a generic "Resend plan".
     *   - Anything else fails closed (disabled).
     *
     * @param string $status          One of the self::* status constants.
     * @param bool   $has_active_plan Whether the client has a live flight plan.
     * @return array{enabled:bool,stage:?int,link_kind:string,label:string,tooltip:string}
     */
    public static function resend_link_descriptor( $status, $has_active_plan = false ) {
        $off = function ( $tooltip ) {
            return array( 'enabled' => false, 'stage' => null, 'link_kind' => '', 'label' => '', 'tooltip' => $tooltip );
        };
        switch ( $status ) {
            case self::NOT_STARTED:
                return array( 'enabled' => true, 'stage' => 1, 'link_kind' => 'assessment', 'label' => 'Send assessment link', 'tooltip' => '' );
            case self::LOW_DATA:
                return array( 'enabled' => true, 'stage' => 2, 'link_kind' => 'assessment', 'label' => 'Send Stage-2 link', 'tooltip' => '' );
            case self::STAGE3_IN_PROGRESS:
                return array( 'enabled' => true, 'stage' => 3, 'link_kind' => 'assessment', 'label' => 'Send Stage-3 link', 'tooltip' => '' );
            case self::AWAITING_WHY_RELEASE:
                return $off( 'Waiting on you to release Stage 3 — nothing to send' );
            case self::AWAITING_CONSULT:
                return $off( 'Waiting on your consultation — nothing to send' );
            case self::ACTIVE:
            case self::PROGRESS_NORMAL:
            case self::NEEDS_ATTENTION:
            case self::INACTIVE:
                return $has_active_plan
                    ? array( 'enabled' => true, 'stage' => null, 'link_kind' => 'plan',   'label' => 'Resend flight plan', 'tooltip' => '' )
                    : array( 'enabled' => true, 'stage' => null, 'link_kind' => 'report', 'label' => 'Resend report',      'tooltip' => '' );
            default:
                return $off( 'Nothing to send for this client yet' );
        }
    }

    public function register_hooks() {
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
    }

    public function register_rest_routes() {
        register_rest_route( 'hdl-v2/v1', '/dashboard/clients', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'rest_get_clients' ),
            'permission_callback' => function () { return HDLV2_Compatibility::is_practitioner( get_current_user_id() ); },
        ) );
        // v0.21.0 — lightweight digest endpoint the dashboard JS polls every 4s
        // to decide whether to pull the full /dashboard/clients payload. Returns
        // a single integer epoch = max(updated_at) across this practitioner's
        // clients. Keeps realtime latency < 5s without hammering the full query.
        register_rest_route( 'hdl-v2/v1', '/dashboard/version', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'rest_get_version' ),
            'permission_callback' => function () { return HDLV2_Compatibility::is_practitioner( get_current_user_id() ); },
        ) );

        // Effort vs Outcomes — the "money chart" from the brief. Returns
        // adherence series (from hdlv2_timeline 'adherence' rows written
        // by Phase A on every tick) + outcome series (rate of ageing
        // re-derived from form_progress at each report's created_at).
        register_rest_route( 'hdl-v2/v1', '/dashboard/client/(?P<client_id>\d+)/effort-outcomes', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'rest_get_effort_outcomes' ),
            'permission_callback' => function ( $req ) {
                $client_id = absint( $req['client_id'] );
                return HDLV2_Compatibility::practitioner_owns_client( get_current_user_id(), $client_id );
            },
        ) );

        // v0.24.0 — per-client batch record. Returns Stage 1 / Stage 2 /
        // Stage 3 / Final report data in a single call so the dashboard's
        // expand-panel tabs (Stage 1, Stage 2, Stage 3, Final) can render
        // from one cached fetch. Matches Matthew's E1 spec: practitioner
        // sees the client journey in tab order Stage 1 → Stage 2 → Stage 3
        // → Consultation → Final → Flight Plan.
        register_rest_route( 'hdl-v2/v1', '/dashboard/client-record/(?P<progress_id>\d+)', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'rest_get_client_record' ),
            'permission_callback' => function ( $req ) {
                $progress_id = absint( $req['progress_id'] );
                if ( ! $progress_id ) return false;
                global $wpdb;
                // v0.41.17 — `AND deleted_at IS NULL`. Without this, after
                // a re-invite the practitioner still owns the new
                // form_progress for this client_user_id, and the IDOR
                // check (owner-by-progress_id) would pass for the OLD
                // archived progress_id — leaking Stage 1/2/3/Final data.
                $owner = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT practitioner_user_id FROM {$wpdb->prefix}hdlv2_form_progress
                     WHERE id = %d AND deleted_at IS NULL",
                    $progress_id
                ) );
                if ( ! $owner ) return false;
                return $owner === get_current_user_id() || current_user_can( 'manage_options' );
            },
        ) );

        // v0.41.15 — Practitioner-side soft-delete for a V2 client.
        // Closes the gap left by the v0.41.14 trash icon, which reused V1's
        // wp_ajax_health_tracker_delete_client handler. That handler queries
        // wp_health_tracker_practitioner_clients, which V2-only clients do
        // not populate — every click returned "Client relationship not found
        // or already deleted." This endpoint stamps hdlv2_form_progress's
        // new deleted_at/deleted_by (Phase S, DB v3.11) so the practitioner
        // dashboard hides the client without destroying assessment data.
        //
        // v0.41.17 — restoration is admin-only (Tools → V2 Restore, $89 fee).
        // Re-invite creates a NEW form_progress; old data stays archived.
        register_rest_route( 'hdl-v2/v1', '/dashboard/client/(?P<client_id>\d+)/delete', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'rest_delete_client' ),
            'permission_callback' => function ( $req ) {
                $client_id = absint( $req['client_id'] );
                // Use the _including_deleted helper so a race (two tabs
                // double-clicking) returns a clean idempotent response
                // instead of a 403 on the second call. The handler itself
                // is the source of truth on whether the row needs an update.
                return HDLV2_Compatibility::practitioner_owns_client_including_deleted( get_current_user_id(), $client_id );
            },
        ) );

        // P1b (2026-07-13) — stage-aware "resend the client's continue link".
        // Purpose: "my link expired / I lost the email — send it again." What
        // is sent is decided by the client's CURRENT STAGE, never chosen by the
        // practitioner (this is NOT V1's Health/Longevity Send Form). Owner-gated
        // by the same IDOR helper Phase 0 reused. Frontend confirm dialog +
        // dynamic label land in Phase 2; this route must NOT reach LIVE without
        // them (STBY-only until they ship together).
        register_rest_route( 'hdl-v2/v1', '/dashboard/client/(?P<client_id>\d+)/resend-link', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'rest_resend_link' ),
            'permission_callback' => function ( $req ) {
                $client_id = absint( $req['client_id'] );
                // Standard (non-deleted) ownership — an archived client is not
                // resendable, so we deliberately do NOT use the _including_deleted
                // variant the delete route needs.
                return HDLV2_Compatibility::practitioner_owns_client( get_current_user_id(), $client_id );
            },
        ) );

        // Public health probe — for CI/CD smoke gate after STBY → LIVE
        // deploys. Returns plugin version + DB version + V1 presence +
        // table count. No client data, no secrets. Status is "ok" when
        // every check passes, "degraded" when any signal is wrong.
        register_rest_route( 'hdl-v2/v1', '/health', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'rest_health' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * v0.41.15 — Soft-delete a V2 client from this practitioner's dashboard.
     *
     * v0.41.17 — cascades the deleted_at stamp across every (practitioner,
     * client)-scoped table. Without the cascade, re-inviting the same email
     * re-used the same WP user → OLD client_id-keyed rows in
     * checkins / timeline / flight_plans / monthly_summaries surfaced again
     * in the new lifecycle, defeating the soft-delete model.
     *
     * Cascade order (newest tables first; all idempotent):
     *   - hdlv2_form_progress       (Phase S column)
     *   - V1 health_tracker_practitioner_clients (V1's deleted_at column)
     *   - hdlv2_checkins            (Phase T)
     *   - hdlv2_timeline            (Phase T)
     *   - hdlv2_flight_plans        (Phase T)
     *   - hdlv2_flight_plan_ticks   (Phase T, scoped via JOIN to flight_plans)
     *   - hdlv2_monthly_summaries   (Phase T)
     *   - hdlv2_widget_invites      (pending/opened → 'revoked', stops
     *                                stale email links from re-triggering
     *                                magic-link auto-login on the deleted
     *                                user; the V1-link auto-restore was
     *                                already disabled in compatibility.php)
     *
     * Idempotent: existing soft-deleted rows are skipped via the
     * `AND deleted_at IS NULL` guard in each WHERE. Duplicate API calls
     * succeed with rows_deleted counts of 0.
     *
     * IDOR is enforced in permission_callback. Every cascade UPDATE binds
     * by `practitioner_id` / `practitioner_user_id` as defence-in-depth
     * even though the permission callback already verified ownership.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function rest_delete_client( $request ) {
        $client_id       = absint( $request['client_id'] );
        $practitioner_id = get_current_user_id();
        if ( ! $client_id || ! $practitioner_id ) {
            return new WP_Error( 'invalid', 'Invalid client or practitioner ID', array( 'status' => 400 ) );
        }

        global $wpdb;
        $now    = current_time( 'mysql' );
        $prefix = $wpdb->prefix;

        // 1) form_progress — the source-of-truth row(s) for this client.
        $updated_progress = (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$prefix}hdlv2_form_progress
             SET deleted_at = %s, deleted_by = %d
             WHERE practitioner_user_id = %d
               AND client_user_id = %d
               AND deleted_at IS NULL",
            $now, $practitioner_id, $practitioner_id, $client_id
        ) );

        // 2) V1 health_tracker_practitioner_clients — keeps the V1 dashboard
        // consistent. Email-hash join (no client_user_id column on the V1 row).
        $client_user       = get_userdata( $client_id );
        $client_email_hash = $client_user ? hash( 'sha256', strtolower( trim( $client_user->user_email ) ) ) : null;
        $v1_table          = $prefix . 'health_tracker_practitioner_clients';
        $v1_exists         = $wpdb->get_var( "SHOW TABLES LIKE '" . esc_sql( $v1_table ) . "'" );
        if ( $client_email_hash && $v1_exists === $v1_table ) {
            $wpdb->update(
                $v1_table,
                array( 'deleted_at' => $now, 'deleted_by' => $practitioner_id ),
                array( 'practitioner_user_id' => $practitioner_id, 'client_email_hash' => $client_email_hash, 'deleted_at' => null ),
                array( '%s', '%d' ),
                array( '%d', '%s', '%s' )
            );
        }

        // 3–7) Cascade to client_id-keyed data tables. Each is scoped by
        // (practitioner_id, client_id) so a multi-practitioner client (rare
        // but supported) only loses access for the practitioner who deleted.
        $cascade = array(
            'hdlv2_checkins'           => 'practitioner_id',
            'hdlv2_timeline'           => 'practitioner_id',
            'hdlv2_flight_plans'       => 'practitioner_id',
            'hdlv2_monthly_summaries'  => 'practitioner_id',
        );
        $cascade_counts = array();
        foreach ( $cascade as $bare => $prac_col ) {
            $tbl = $prefix . $bare;
            $cascade_counts[ $bare ] = (int) $wpdb->query( $wpdb->prepare(
                "UPDATE $tbl
                 SET deleted_at = %s, deleted_by = %d
                 WHERE $prac_col = %d
                   AND client_id = %d
                   AND deleted_at IS NULL",
                $now, $practitioner_id, $practitioner_id, $client_id
            ) );
        }

        // 6b) flight_plan_ticks — no practitioner_id column, scope via JOIN
        // to flight_plans for IDOR safety.
        $ticks_table = $prefix . 'hdlv2_flight_plan_ticks';
        $fp_table    = $prefix . 'hdlv2_flight_plans';
        $cascade_counts['hdlv2_flight_plan_ticks'] = (int) $wpdb->query( $wpdb->prepare(
            "UPDATE $ticks_table t
             INNER JOIN $fp_table p ON p.id = t.flight_plan_id
             SET t.deleted_at = %s, t.deleted_by = %d
             WHERE p.practitioner_id = %d
               AND t.client_id = %d
               AND t.deleted_at IS NULL",
            $now, $practitioner_id, $practitioner_id, $client_id
        ) );

        // 8) Revoke pending/opened widget_invites for this client. Stops a
        // stale invite email from triggering auto-login on the deleted user
        // (closes the loop with the V1-link auto-restore disable). Uses the
        // 'revoked' enum value (widget_invites.status ENUM does not include
        // 'cancelled'; 'revoked' is the canonical "intentionally invalidated"
        // state for this table).
        $client_email = $client_user ? strtolower( trim( $client_user->user_email ) ) : null;
        $invites_cancelled = 0;
        if ( $client_email ) {
            $invites_cancelled = (int) $wpdb->query( $wpdb->prepare(
                "UPDATE {$prefix}hdlv2_widget_invites
                 SET status = 'revoked'
                 WHERE practitioner_id = %d
                   AND LOWER(client_email) = %s
                   AND status IN ('pending','opened')",
                $practitioner_id, $client_email
            ) );
        }

        return rest_ensure_response( array(
            'success'           => true,
            'rows_deleted'      => $updated_progress,
            'cascade'           => $cascade_counts,
            'invites_cancelled' => $invites_cancelled,
            'client_id'         => $client_id,
        ) );
    }

    /**
     * v0.24.0 — Aggregate client record for the practitioner dashboard's
     * stage-tab navigation. Single round-trip returns Stage 1, Stage 2,
     * Stage 3, and Final report data shaped for the JS render functions
     * in hdlv2-client-list-enhance.js (loadStage1, loadStage2, loadStage3,
     * loadFinal). IDOR enforced in permission_callback above.
     */
    public function rest_get_client_record( $request ) {
        $progress_id = absint( $request['progress_id'] );
        if ( ! $progress_id ) {
            return new WP_Error( 'invalid', 'Progress ID required', array( 'status' => 400 ) );
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        // v0.41.17 — `AND deleted_at IS NULL` (matches the permission_callback
        // filter; defense-in-depth in case the route is called from a different
        // code path with a stale ID).
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, token, client_user_id, practitioner_user_id, client_email, client_name,
                    stage1_data, stage3_data, stage1_completed_at, stage3_completed_at, created_at,
                    stage1_pdf_url,
                    has_flags, flags, flags_scan_status
             FROM {$prefix}hdlv2_form_progress
             WHERE id = %d AND deleted_at IS NULL",
            $progress_id
        ) );
        if ( ! $progress ) {
            return new WP_Error( 'not_found', 'Client record not found', array( 'status' => 404 ) );
        }

        $s1      = json_decode( $progress->stage1_data, true ) ?: array();
        $s3      = json_decode( $progress->stage3_data, true ) ?: array();
        $s3_calc = isset( $s3['server_result'] ) && is_array( $s3['server_result'] ) ? $s3['server_result'] : array();

        // ── Stage 1 ─────────────────────────────────────────────
        $s1_rate = isset( $s1['server_result']['rate'] ) ? (float) $s1['server_result']['rate'] : null;
        if ( $s1_rate === null && class_exists( 'HDLV2_Rate_Calculator' ) && ! empty( $s1 ) && isset( $s1['q1_age'] ) ) {
            $tmp     = HDLV2_Rate_Calculator::calculate_quick( $s1 );
            $s1_rate = isset( $tmp['rate'] ) ? (float) $tmp['rate'] : null;
        }

        // v0.41.20 — Stage 1 gauge uses the practitioner-panel variant with
        // the centered value label + slower/average/faster subtitle visible.
        // The PDFMonkey/email path is unaffected (default show_value=false
        // keeps that gauge text-free; the template prints the value below).
        // HDLV2_Widget_Config::build_gauge_url is private (the reflection
        // gate below never picked it up); the canonical builder is the
        // public HDLV2_Staged_Form::build_gauge_url, so we call it directly.
        $s1_gauge_url = '';
        if ( $s1_rate !== null && class_exists( 'HDLV2_Staged_Form' ) ) {
            $s1_gauge_url = (string) HDLV2_Staged_Form::build_gauge_url(
                $s1_rate,
                array( 'show_value' => true )
            );
        }

        // v0.41.23 — Stage 1 per-question scores. The calculator persists
        // both 'raw' (letter→1-5 with Q6 'e'=2 special case) and 'scores'
        // (post age-norm, includes q2_body combined silhouette+modifier).
        // We surface the raw 1-5 integer to the panel because that's what
        // the client most directly chose; the age-norm adjustment is an
        // internal weight for the rate calculation.
        $s1_raw    = isset( $s1['server_result']['raw'] )    && is_array( $s1['server_result']['raw'] )    ? $s1['server_result']['raw']    : array();
        $s1_scores = isset( $s1['server_result']['scores'] ) && is_array( $s1['server_result']['scores'] ) ? $s1['server_result']['scores'] : array();

        // v0.41.23 / F1 0.47.43 — Stage 1 biological-age estimate. Stage 1's
        // calculate_quick() returns rate only, not bio_age (Stage 3's
        // calculate_full() owns that), so we derive it from the identity
        // inverse bio_age = round(rate × chrono_age, 1). Delegated to the
        // canonical HDLV2_Stage1_Commentary::biological_age() helper — the
        // SINGLE source of truth shared with the client result page and the
        // Stage-1 webhook payload (email + PDF) — so the practitioner panel
        // figure (1 dp) can never drift from what the client sees. (v0.47.15
        // moved this off integer rounding; F1 centralises the maths.)
        $s1_age = isset( $s1['q1_age'] ) ? (int) $s1['q1_age'] : null;
        $s1_bio_age_est = ( $s1_rate !== null && $s1_age && $s1_age > 0 )
            ? HDLV2_Stage1_Commentary::biological_age( $s1_rate, $s1_age )
            : null;

        $stage1 = array(
            'completed_at'   => $progress->stage1_completed_at ?: $progress->created_at,
            'pdf_url'        => (string) ( $progress->stage1_pdf_url ?? '' ),
            'rate'           => $s1_rate !== null ? round( $s1_rate, 2 ) : null,
            'bio_age_est'    => $s1_bio_age_est,
            'gauge_url'      => $s1_gauge_url,
            'age'            => $s1_age,
            'sex'            => isset( $s1['q1_sex'] ) ? (string) $s1['q1_sex'] : '',
            'q2_silhouette'  => isset( $s1['q2a'] ) ? (string) $s1['q2a'] : '',
            'q2_fat_dist'    => isset( $s1['q2b'] ) ? (string) $s1['q2b'] : '',
            // v0.41.23 — Q2b internal code (apple/pear/even) → questionnaire
            // wording (Mostly middle / Hips & thighs / Evenly spread) mapped
            // by s1_q2b_label() below. Mirrors widget/hdl-lead-magnet.js
            // line 752-754 fatOpt() labels — single source of truth.
            'q2b_label'      => self::s1_q2b_label( $s1['q2b'] ?? '' ),
            // v0.41.20 — Q3-Q9 used to round-trip as raw upper-case letters
            // (A/B/C/D/E) — useless to the practitioner reading the panel.
            // Translate to the canonical answer text from S1_QUESTIONS in
            // hdlv2-staged-form.js. Map below mirrors that JS table line-by-
            // line. The only consumer of these keys is the splicer JS
            // (hdlv2-client-list-enhance.js loadStage1), grep-verified.
            'q3_zone2'       => self::s1_option_label( 'q3', $s1['q3'] ?? '' ),
            'q4_vo2'         => self::s1_option_label( 'q4', $s1['q4'] ?? '' ),
            'q5_sts'         => self::s1_option_label( 'q5', $s1['q5'] ?? '' ),
            'q6_sleep'       => self::s1_option_label( 'q6', $s1['q6'] ?? '' ),
            'q7_smoking'     => self::s1_option_label( 'q7', $s1['q7'] ?? '' ),
            'q8_social'      => self::s1_option_label( 'q8', $s1['q8'] ?? '' ),
            'q9_diet'        => self::s1_option_label( 'q9', $s1['q9'] ?? '' ),
            // v0.41.23 — per-question 1-5 score for the panel pill. Reads
            // server_result.raw (Q3-Q9, integer, Q6 'e'=2 already applied)
            // and server_result.scores.q2_body (combined Q2a silhouette +
            // Q2b modifier, float — round to int for display). null when
            // a legacy row predates persistence of server_result.raw.
            'q2_score'       => isset( $s1_scores['q2_body'] )  ? (int) round( (float) $s1_scores['q2_body'] )  : null,
            'q3_score'       => isset( $s1_raw['q3_zone2'] )    ? (int) $s1_raw['q3_zone2']                     : null,
            'q4_score'       => isset( $s1_raw['q4_vo2'] )      ? (int) $s1_raw['q4_vo2']                       : null,
            'q5_score'       => isset( $s1_raw['q5_sts'] )      ? (int) $s1_raw['q5_sts']                       : null,
            'q6_score'       => isset( $s1_raw['q6_sleep'] )    ? (int) $s1_raw['q6_sleep']                     : null,
            'q7_score'       => isset( $s1_raw['q7_smoking'] )  ? (int) $s1_raw['q7_smoking']                   : null,
            'q8_score'       => isset( $s1_raw['q8_social'] )   ? (int) $s1_raw['q8_social']                    : null,
            'q9_score'       => isset( $s1_raw['q9_diet'] )     ? (int) $s1_raw['q9_diet']                      : null,
        );

        // ── Stage 2 ─────────────────────────────────────────────
        // v0.24.1 — corrected schema. The why_profiles table doesn't have
        // an `audio_summary` column (that was an assumption on my part);
        // actual columns surface vision_text + fears as free-text fields.
        $why = $wpdb->get_row( $wpdb->prepare(
            "SELECT distilled_why, key_people, motivations, fears, vision_text, ai_reformulation, released, created_at, pdf_url
             FROM {$prefix}hdlv2_why_profiles WHERE form_progress_id = %d ORDER BY id DESC LIMIT 1",
            $progress_id
        ) );
        $stage2 = $why ? array(
            'completed_at'  => (string) ( $why->created_at ?? '' ),
            'distilled_why' => (string) ( $why->distilled_why ?? '' ),
            'key_people'    => json_decode( $why->key_people, true ) ?: array(),
            'motivations'   => json_decode( $why->motivations, true ) ?: array(),
            'fears'         => json_decode( $why->fears, true ) ?: array(),
            'vision_text'   => (string) ( $why->vision_text ?? '' ),
            'ai_reformulation' => (string) ( $why->ai_reformulation ?? '' ), // B4 — second WHY summary (already generated; no new AI)
            'released'      => (int) ( $why->released ?? 0 ) === 1,
            'pdf_url'       => (string) ( $why->pdf_url ?? '' ), // D-2 — practitioner-only WHY PDF
        ) : null;

        // ── Stage 3 ─────────────────────────────────────────────
        // D-2 — draft-report PDF for the practitioner Stage-3 tab download.
        $draft_pdf = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT pdf_url FROM {$prefix}hdlv2_reports WHERE form_progress_id = %d AND report_type = 'draft' ORDER BY id DESC LIMIT 1",
            $progress_id
        ) );
        $stage3 = array(
            'completed_at'   => $progress->stage3_completed_at,
            'pdf_url'        => $draft_pdf,
            'rate'           => isset( $s3_calc['rate'] )    ? round( (float) $s3_calc['rate'], 2 )    : null,
            'bio_age'        => isset( $s3_calc['bio_age'] ) ? round( (float) $s3_calc['bio_age'], 1 ) : null,
            'bmi'            => isset( $s3_calc['bmi'] )     ? round( (float) $s3_calc['bmi'], 1 )     : null,
            'whr'            => isset( $s3_calc['whr'] )     ? round( (float) $s3_calc['whr'], 2 )     : null,
            'whtr'           => isset( $s3_calc['whtr'] )    ? round( (float) $s3_calc['whtr'], 2 )    : null,
            'height'         => isset( $s3['height'] ) && is_numeric( $s3['height'] ) ? (float) $s3['height'] : null,
            'weight'         => isset( $s3['weight'] ) && is_numeric( $s3['weight'] ) ? (float) $s3['weight'] : null,
            'waist'          => isset( $s3['waist'] )  && is_numeric( $s3['waist'] )  ? (float) $s3['waist']  : null,
            'hip'            => isset( $s3['hip'] )    && is_numeric( $s3['hip'] )    ? (float) $s3['hip']    : null,
            'bp_systolic'    => isset( $s3['bpSystolic'] )       && is_numeric( $s3['bpSystolic'] )       ? (int) $s3['bpSystolic']       : null,
            'bp_diastolic'   => isset( $s3['bpDiastolic'] )      && is_numeric( $s3['bpDiastolic'] )      ? (int) $s3['bpDiastolic']      : null,
            'resting_hr'     => isset( $s3['restingHeartRate'] ) && is_numeric( $s3['restingHeartRate'] ) ? (int) $s3['restingHeartRate'] : null,
            'scores'         => isset( $s3_calc['scores'] ) && is_array( $s3_calc['scores'] ) ? $s3_calc['scores'] : array(),
            // v0.46.49 — the client's ACTUAL answer wording per scored field,
            // so the practitioner panel can show what was chosen ("7–8 hours")
            // rather than just the 0-5 code. See build_score_words() below.
            'score_words'    => self::build_score_words( $s3 ),
            // v0.41.19 — Stage 3 Section 6 (Health Background, v0.38.0).
            // Three optional client free-text fields. Not consumed by the
            // rate calculator (zero impact on scores). Already fed into the
            // Claude draft prompt + Final PDF template; this exposes them
            // to the practitioner expand-panel's Stage 3 tab so the same
            // context is visible without opening the consultation page.
            // Strings only (cast in PHP, esc in JS render).
            'family_history'       => isset( $s3['family_history'] )      ? (string) $s3['family_history']      : '',
            'medications'          => isset( $s3['medications'] )         ? (string) $s3['medications']         : '',
            'existing_conditions'  => isset( $s3['existing_conditions'] ) ? (string) $s3['existing_conditions'] : '',
        );

        // ── Final report ────────────────────────────────────────
        $final = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, created_at, status, pdf_url, milestones FROM {$prefix}hdlv2_reports
             WHERE form_progress_id = %d AND report_type = 'final' AND status = 'ready'
             ORDER BY id DESC LIMIT 1",
            $progress_id
        ) );

        $report_slug = apply_filters( 'hdlv2_final_report_slug', 'longevity-draft-report' );
        $stage_final = $final
            ? array(
                'has_report'   => true,
                'generated_at' => (string) $final->created_at,
                'view_url'     => $progress->token ? home_url( '/' . trim( $report_slug, '/' ) . '/?t=' . rawurlencode( $progress->token ) ) : '',
                'pdf_url'      => (string) ( $final->pdf_url ?? '' ), // D-2 — serves the cached final PDF
                // v0.46.58 — current milestones (the single stored copy) for
                // the Final-tab editor's initial values.
                'report_id'    => (int) $final->id,
                'milestones'   => json_decode( (string) ( $final->milestones ?? '' ), true ) ?: array(),
            )
            : array( 'has_report' => false );

        return rest_ensure_response( array(
            'progress_id'       => (int) $progress->id,
            'client_name'       => (string) $progress->client_name,
            'stage1'            => $stage1,
            'stage2'            => $stage2,
            'stage3'            => $stage3,
            'final'             => $stage_final,
            'flags'             => json_decode( (string) $progress->flags, true ) ?: array(),
            'flags_scan_status' => (string) $progress->flags_scan_status,
        ) );
    }

    /**
     * v0.41.20 — Resolve a Stage 1 question option letter (a-e) to its
     * canonical answer text. Mirrors S1_QUESTIONS in assets/js/hdlv2-staged-
     * form.js line-by-line so the practitioner panel reads the same prose
     * the client saw when answering. Returns '' when the letter is missing
     * or unknown (caller suppresses the row).
     *
     * Used by rest_get_client_record() only. If the labels in the JS form
     * ever change, update both sides — there's no shared JSON source.
     *
     * @param string $q_key  One of q3, q4, q5, q6, q7, q8, q9.
     * @param string $letter Answer letter (case-insensitive, a-e).
     * @return string Canonical answer text, or '' if no match.
     */
    private static function s1_option_label( $q_key, $letter ) {
        $letter = strtolower( trim( (string) $letter ) );
        if ( $letter === '' ) return '';

        static $table = null;
        if ( $table === null ) {
            // Note: literal UTF-8 em-dash (—) and en-dash (–) inline.
            // PHP single-quoted strings don't interpret \xNN — we lean on
            // the file being saved as UTF-8 (matches the rest of the V2
            // codebase). JSON_UNESCAPED_UNICODE in wp_json_encode keeps
            // the bytes as-is on the wire.
            $table = array(
                'q3' => array(
                    'a' => "Almost none — I'm mostly sedentary",
                    'b' => 'About 30–60 minutes per week',
                    'c' => 'About 1–2 hours per week',
                    'd' => 'About 2–4 hours per week',
                    'e' => 'More than 4 hours per week',
                ),
                'q4' => array(
                    'a' => "One flight is difficult — I'd need to stop and rest",
                    'b' => "I can walk up 2–3 flights but I'd be noticeably out of breath",
                    'c' => 'I can walk up 4–5 flights at a steady pace without stopping',
                    'd' => 'I can walk up 5+ flights comfortably, or jog up 3–4 flights',
                    'e' => 'I could run up 4–5 flights without significant difficulty',
                ),
                'q5' => array(
                    'a' => "I couldn't get down, or I'd need someone to help me back up",
                    'b' => "I'd need furniture or both hands and a knee on the ground",
                    'c' => "I'd use one hand or one knee for a bit of support",
                    'd' => 'I could do it without support, but it takes effort',
                    'e' => 'I can sit down and stand back up smoothly, no hands',
                ),
                'q6' => array(
                    'a' => 'Fewer than 5 hours, or I struggle most nights',
                    'b' => "About 5–6 hours, or I wake frequently and don't feel rested",
                    'c' => 'About 6–7 hours, reasonable quality',
                    'd' => 'About 7–8 hours, good quality — I usually wake feeling rested',
                    'e' => 'I sleep 9+ hours but still feel tired',
                ),
                'q7' => array(
                    'a' => 'I smoke daily',
                    'b' => 'I smoke occasionally or socially',
                    'c' => 'I quit within the last 5 years',
                    'd' => 'I quit more than 5 years ago',
                    'e' => "I've never smoked (in last 10 years)",
                ),
                'q8' => array(
                    'a' => 'Rarely — I feel isolated most of the time',
                    'b' => 'Once or twice a month',
                    'c' => 'About once a week',
                    'd' => 'Several times a week',
                    'e' => 'Daily — strong, regular connections',
                ),
                'q9' => array(
                    'a' => 'Mostly processed or fast food, very few vegetables',
                    'b' => 'Mixed — some healthy meals, a lot of convenience food',
                    'c' => 'Reasonably healthy — I cook most meals',
                    'd' => 'Mostly whole foods, plenty of vegetables',
                    'e' => 'Very clean — whole foods, diverse vegetables, minimal processed',
                ),
            );
        }

        return isset( $table[ $q_key ][ $letter ] ) ? $table[ $q_key ][ $letter ] : '';
    }

    /**
     * 0.47.50 — Ordered { label, value } pairs for the pending-lead
     * "View details" panel in the practitioner action queue. Built from the
     * SAME wording sources as the confirmed-client Stage-1 tab —
     * s1_option_label() for the Q3–Q9 prose, s1_q2b_label() for the Q2b
     * code, and the tab's exact label nouns — so the two practitioner
     * surfaces cannot drift. (The 2026-07-03 smoke test found the panel's
     * old JS-side map labelled 6 of 9 answers with the wrong question —
     * q4 "Sitting", q9 "Social" etc. — and rendered raw a–e letters for
     * real widget leads.)
     *
     * @param array $s1 Whitelisted stage1_data (q1_age..q9) as emitted by
     *                  rest_list_pending_leads — never contains
     *                  server_result/_safety.
     * @return array[] Ordered list of array( 'label' => …, 'value' => … );
     *                 empty array when nothing displayable.
     */
    public static function s1_pending_display_pairs( $s1 ) {
        if ( ! is_array( $s1 ) || empty( $s1 ) ) {
            return array();
        }
        $pairs = array();

        // Q2 — "silhouette #N · Mostly middle", matching the client tab.
        $q2b_label = self::s1_q2b_label( $s1['q2b'] ?? '' );
        if ( $q2b_label === '' && ! empty( $s1['q2b'] ) ) {
            $q2b_label = (string) $s1['q2b']; // unknown code — show raw rather than hide
        }
        if ( isset( $s1['q2a'] ) && $s1['q2a'] !== '' ) {
            $body = 'silhouette #' . $s1['q2a'];
            if ( $q2b_label !== '' ) {
                $body .= ' · ' . $q2b_label;
            }
            $pairs[] = array( 'label' => 'Body shape (Q2)', 'value' => $body );
        } elseif ( $q2b_label !== '' ) {
            $pairs[] = array( 'label' => 'Body shape (Q2)', 'value' => $q2b_label );
        }

        // Q3–Q9 — canonical answer prose; fall back to the raw stored value
        // (upper-cased letter or legacy free text) so captured data is never
        // silently hidden from the practitioner reviewing the lead.
        $labels = array(
            'q3' => 'Zone-2 cardio (Q3)',
            'q4' => 'VO2max (Q4)',
            'q5' => 'Sit-to-stand (Q5)',
            'q6' => 'Sleep (Q6)',
            'q7' => 'Smoking (Q7)',
            'q8' => 'Social connection (Q8)',
            'q9' => 'Diet (Q9)',
        );
        foreach ( $labels as $q => $label ) {
            if ( ! isset( $s1[ $q ] ) || $s1[ $q ] === '' ) {
                continue;
            }
            $text = self::s1_option_label( $q, (string) $s1[ $q ] );
            if ( $text === '' ) {
                $raw  = (string) $s1[ $q ];
                $text = ( strlen( $raw ) === 1 ) ? strtoupper( $raw ) : $raw;
            }
            $pairs[] = array( 'label' => $label, 'value' => $text );
        }

        return $pairs;
    }

    /**
     * v0.46.49 — { field: answer-word } for the 16 scored Stage-3 dropdown
     * questions, resolved from the client's raw stage3_data answer (what they
     * actually chose, including any consultation-editor correction — those
     * saves write the same 0-5 codes back to stage3_data). Single wording
     * source: HDLV2_Flight_Notes::s3_label — the same S3_OPTIONS mirror the
     * consultation Health Data editor (B6) and the Flight Notes snapshot use,
     * so the three surfaces can never drift. A skipped/legacy answer (not
     * numeric) gets no entry — the panel then renders the score pill alone
     * rather than implying the client answered. Returns array() when either
     * class is unavailable; the JS falls back gracefully the same way.
     */
    private static function build_score_words( $s3 ) {
        if ( ! is_array( $s3 ) || ! class_exists( 'HDLV2_Flight_Notes' ) || ! class_exists( 'HDLV2_Consultation' ) ) {
            return array();
        }
        $words = array();
        foreach ( HDLV2_Consultation::SCORE_FIELDS as $field ) {
            if ( ! isset( $s3[ $field ] ) || ! is_numeric( $s3[ $field ] ) ) {
                continue;
            }
            $label = HDLV2_Flight_Notes::s3_label( $field, $s3[ $field ] );
            if ( '' !== $label ) {
                $words[ $field ] = $label;
            }
        }
        return $words;
    }

    /**
     * v0.41.23 — Resolve a Q2b body-shape code (apple/pear/even) to the
     * canonical option text the client saw in the widget. Source-of-truth
     * labels live in widget/hdl-lead-magnet.js line 752-754 (the fatOpt
     * calls inside renderS1_Q2b). Keep both in sync if either changes.
     *
     * Returns '' for unknown / empty codes — caller renders just the
     * silhouette index in that case (back-compat with pre-Q2b rows).
     *
     * @param string $code apple | pear | even (case-insensitive).
     * @return string Questionnaire label or ''.
     */
    private static function s1_q2b_label( $code ) {
        $code = strtolower( trim( (string) $code ) );
        switch ( $code ) {
            case 'apple': return 'Mostly middle';
            case 'pear':  return 'Hips & thighs';
            case 'even':  return 'Evenly spread';
            default:      return '';
        }
    }

    // ── Main calculation ──
    public static function calculate_status( $client_user_id ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        // Get latest form progress.
        // v0.41.17 — `AND deleted_at IS NULL`. After a re-invite, the OLD
        // form_progress should not be the basis for client status (which
        // drives the dashboard badge + Flight Plan generation gate).
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$prefix}hdlv2_form_progress
             WHERE client_user_id = %d AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1",
            $client_user_id
        ) );

        if ( ! $progress || empty( $progress->stage1_completed_at ) ) {
            return self::build_result( self::NOT_STARTED );
        }

        // A medical red flag outranks workflow state — surface it immediately.
        if ( ! empty( $progress->has_flags ) ) {
            return self::build_result( self::NEEDS_ATTENTION, array( 'Red flag detected in assessment' ) );
        }

        // Pre-Stage-3 window: distinguish practitioner-blocked vs client-in-progress.
        // Both used to collapse into LOW_DATA, which hid the WHY-release queue.
        if ( empty( $progress->stage3_completed_at ) ) {
            $why = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, released FROM {$prefix}hdlv2_why_profiles WHERE form_progress_id = %d LIMIT 1",
                $progress->id
            ) );

            if ( $why && (int) $why->released === 0 ) {
                // Client submitted Stage 2; practitioner hasn't released the WHY gate yet.
                return self::build_result( self::AWAITING_WHY_RELEASE, array( 'Review WHY profile and release Stage 3' ) );
            }

            if ( $why && (int) $why->released === 1 ) {
                // WHY released, client is working Stage 3.
                return self::build_result( self::STAGE3_IN_PROGRESS );
            }

            // No WHY row yet — still in Stage 1/2 data collection.
            return self::build_result( self::LOW_DATA );
        }

        // Check for consultation completion
        $has_report = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$prefix}hdlv2_consultation_notes WHERE form_progress_id = %d AND status = 'report_generated' LIMIT 1",
            $progress->id
        ) );

        if ( ! $has_report ) {
            return self::build_result( self::AWAITING_CONSULT );
        }

        // Check recent check-ins for attention triggers.
        // v0.41.17 — `AND deleted_at IS NULL` so archived check-ins from a
        // prior lifecycle don't flip a fresh client into needs_attention.
        $reasons = array();
        $recent_checkins = $wpdb->get_results( $wpdb->prepare(
            "SELECT has_flags, adherence_scores, week_start, status FROM {$prefix}hdlv2_checkins
             WHERE client_id = %d AND status = 'confirmed' AND deleted_at IS NULL
             ORDER BY week_start DESC LIMIT 4",
            $client_user_id
        ) );

        if ( ! empty( $recent_checkins ) ) {
            // Latest check-in has flags
            if ( $recent_checkins[0]->has_flags ) {
                $reasons[] = 'CRITICAL flag in latest check-in';
            }

            // Low adherence for 2+ consecutive weeks
            $low_streak = 0;
            foreach ( $recent_checkins as $ci ) {
                $scores = json_decode( $ci->adherence_scores, true );
                $overall = $scores['overall'] ?? 50;
                if ( $overall <= 30 ) $low_streak++;
                else break;
            }
            if ( $low_streak >= 2 ) {
                $reasons[] = "Low adherence ({$low_streak} consecutive weeks)";
            }
        }

        // Missed check-ins (2+ weeks with no confirmed check-in).
        // v0.41.17 — `AND deleted_at IS NULL`.
        $last_confirmed = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(week_start) FROM {$prefix}hdlv2_checkins
             WHERE client_id = %d AND status = 'confirmed' AND deleted_at IS NULL",
            $client_user_id
        ) );
        if ( $last_confirmed ) {
            $weeks_since = floor( ( time() - strtotime( $last_confirmed ) ) / ( 7 * DAY_IN_SECONDS ) );
            if ( $weeks_since >= 2 ) {
                $reasons[] = "{$weeks_since} weeks since last check-in";
            }
        }

        if ( ! empty( $reasons ) ) {
            return self::build_result( self::NEEDS_ATTENTION, $reasons );
        }

        // INACTIVE: no check-in for 4+ weeks
        if ( $last_confirmed ) {
            $weeks_since = floor( ( time() - strtotime( $last_confirmed ) ) / ( 7 * DAY_IN_SECONDS ) );
            if ( $weeks_since >= 4 ) {
                return self::build_result( self::INACTIVE, array( sprintf( 'No check-in for %d weeks', $weeks_since ) ) );
            }
        }

        return self::build_result( self::PROGRESS_NORMAL );
    }

    private static function build_result( $status, $reasons = array() ) {
        return array(
            'status'  => $status,
            'label'   => self::LABELS[ $status ] ?? $status,
            'color'   => self::COLORS[ $status ] ?? '#94a3b8',
            'reasons' => $reasons,
        );
    }

    // ── Get attention clients ──
    public static function get_attention_clients( $practitioner_id ) {
        $clients = HDLV2_Compatibility::get_clients_for_practitioner( $practitioner_id );
        $attention = array();
        foreach ( $clients as $client_id ) {
            $status = self::calculate_status( $client_id );
            if ( $status['status'] === self::NEEDS_ATTENTION ) {
                $user = get_userdata( $client_id );
                $attention[] = array(
                    'user_id' => $client_id,
                    'name'    => $user ? $user->display_name : 'Unknown',
                    'email'   => $user ? $user->user_email : '',
                    'status'  => $status,
                );
            }
        }
        return $attention;
    }

    // ── REST: Resend the client's stage-appropriate continue link (P1b) ──
    //
    // Owner-gated in permission_callback. Order matters: rate-limit and the
    // cheap "is there anything to send" checks run BEFORE calculate_status so a
    // throttled or non-sendable client costs nothing. Every enabled send:
    //   (rule 2) regenerates form_progress.token — invalidating the old link —
    //            and refreshes the fixed 90-day window so the fresh link is live;
    //   (rule 4) writes an audit trail (timeline entry + error_log);
    //   (rule 7) returns what was sent + to whom.
    public function rest_resend_link( $request ) {
        global $wpdb;
        $client_id = absint( $request['client_id'] );
        $prac_id   = get_current_user_id();

        // Rule 3 — rate limit, mirroring V1's invite-resend (10/hr/practitioner).
        // V1 is always active (V2 refuses to boot without it), but guard anyway.
        if ( class_exists( 'HDL_Rate_Limiter' ) ) {
            $rl = new HDL_Rate_Limiter();
            if ( ! $rl->check_limit( 'hdlv2_resend_link', 'user_' . $prac_id, 10, HOUR_IN_SECONDS ) ) {
                return new WP_Error( 'rate_limited', 'You have sent too many links this hour. Please try again later.', array( 'status' => 429 ) );
            }
        }

        // Latest live assessment row for this client.
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, token, client_email, client_name, current_stage
             FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE client_user_id = %d AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1",
            $client_id
        ) );
        if ( ! $progress ) {
            return new WP_Error( 'no_assessment', 'This client has no active assessment to send a link for.', array( 'status' => 422 ) );
        }

        // Resolve recipient: the assessment row's email, else the WP account.
        $recipient = '';
        if ( ! empty( $progress->client_email ) && is_email( $progress->client_email ) ) {
            $recipient = $progress->client_email;
        } else {
            $u = get_userdata( $client_id );
            if ( $u && is_email( $u->user_email ) ) {
                $recipient = $u->user_email;
            }
        }
        if ( ! $recipient ) {
            return new WP_Error( 'no_email', 'No email address on file for this client.', array( 'status' => 422 ) );
        }

        // Stage-aware behaviour (static:: for late static binding — the pure
        // descriptor is unit-tested; this layer just wires DB + email around it).
        $status_arr = static::calculate_status( $client_id );
        $status     = is_array( $status_arr ) && isset( $status_arr['status'] ) ? $status_arr['status'] : '';
        $has_plan   = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}hdlv2_flight_plans WHERE client_id = %d AND deleted_at IS NULL LIMIT 1",
            $client_id
        ) );
        $d = self::resend_link_descriptor( $status, $has_plan );
        if ( empty( $d['enabled'] ) ) {
            // Defence-in-depth: the frontend disables these, but the route must
            // refuse a blocked-on-practitioner / unknown state too.
            return new WP_Error( 'not_sendable', $d['tooltip'] ? $d['tooltip'] : 'There is nothing to send for this client.', array( 'status' => 422 ) );
        }

        // Rule 2 — new token + refreshed expiry; the old link stops working.
        $new_token = bin2hex( random_bytes( 32 ) );
        $ttl_days  = defined( 'HDLV2_CLIENT_TOKEN_TTL_DAYS' ) ? (int) HDLV2_CLIENT_TOKEN_TTL_DAYS : 90;
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_form_progress',
            array( 'token' => $new_token, 'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl_days * DAY_IN_SECONDS ) ),
            array( 'id' => (int) $progress->id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        // Stage-appropriate URL. COMPLETE uses a READ-ONLY view (never reopens
        // the funnel); the assessment stages share one URL that routes by stage.
        switch ( $d['link_kind'] ) {
            case 'plan':
                $url = site_url( '/my-flight-plan/?token=' . $new_token );
                break;
            case 'report':
                $url = site_url( '/longevity-draft-report/?t=' . $new_token );
                break;
            case 'assessment':
            default:
                $url = site_url( '/assessment/?token=' . $new_token );
                break;
        }

        $first = class_exists( 'HDLV2_Email_Templates' )
            ? HDLV2_Email_Templates::derive_first_name( $progress->client_name, $recipient )
            : '';
        list( $subject, $html ) = $this->build_resend_email( $d, $first, $url, $prac_id );
        $sent = wp_mail( $recipient, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );

        // Rule 4 — audit trail: sender, recipient, stage, timestamp.
        if ( class_exists( 'HDLV2_Timeline' ) ) {
            HDLV2_Timeline::add_entry(
                $client_id, $prac_id, 'link_resent',
                $d['label'],
                sprintf( 'Practitioner re-sent the %s to %s.', $d['label'], $recipient ),
                null, 'hdlv2_form_progress', (int) $progress->id, false, true
            );
        }
        error_log( sprintf(
            '[HDLV2 resend-link] prac=%d client=%d status=%s kind=%s to=%s sent=%s',
            $prac_id, $client_id, $status, $d['link_kind'], $recipient, $sent ? '1' : '0'
        ) );

        // Rule 7 — state what was sent and to whom (not a bare tick).
        return rest_ensure_response( array(
            'success'         => true,
            'stage'           => $d['stage'],
            'stage_label'     => $d['label'],
            'artefact'        => $d['link_kind'],
            'recipient_email' => $recipient,
            'invalidated_old' => true,
            'email_sent'      => (bool) $sent,
        ) );
    }

    /**
     * Compose the resend email (subject + branded HTML) for a descriptor.
     * Copy is stage-derived; every variant states that the link replaces any
     * previous one (mirrors the confirm dialog's promise). Kept small + pure so
     * the wording is reviewable in one place.
     *
     * @return array{0:string,1:string} [ subject, html ]
     */
    private function build_resend_email( $descriptor, $first, $url, $practitioner_id ) {
        $greeting = $first ? ( 'Hi ' . $first . ',' ) : 'Hi,';
        switch ( $descriptor['link_kind'] ) {
            case 'plan':
                $subject = 'Your flight plan link';
                $lead    = 'Here is a fresh link to view your weekly flight plan.';
                $cta     = 'View my flight plan';
                $banner  = 'Your flight plan';
                break;
            case 'report':
                $subject = 'Your report link';
                $lead    = 'Here is a fresh link to view your report.';
                $cta     = 'View my report';
                $banner  = 'Your report';
                break;
            case 'assessment':
            default:
                $stage   = (int) $descriptor['stage'];
                $subject = $stage <= 1 ? 'Your longevity assessment link' : ( 'Continue your assessment — Stage ' . $stage );
                $lead    = $stage <= 1
                    ? 'Here is your link to start your longevity assessment.'
                    : 'Here is a fresh link to pick up your assessment where you left off.';
                $cta     = $stage <= 1 ? 'Start assessment' : 'Continue assessment';
                $banner  = 'Your assessment link';
                break;
        }
        $note    = 'For your security this replaces any previous link we sent you.';
        $content = '<p>' . esc_html( $greeting ) . '</p>'
            . '<p>' . esc_html( $lead ) . '</p>'
            . '<p style="text-align:center;margin:28px 0;">'
            . '<a href="' . esc_url( $url ) . '" style="display:inline-block;background:#3d8da0;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;font-family:Inter,-apple-system,sans-serif;">' . esc_html( $cta ) . '</a></p>'
            . '<p style="color:#888888;font-size:13px;">' . esc_html( $note ) . '</p>';
        $html = class_exists( 'HDLV2_Email_Templates' )
            ? HDLV2_Email_Templates::base_layout( $content, $practitioner_id, $banner, $lead )
            : '<!doctype html><html><body>' . $content . '</body></html>';
        return array( $subject, $html );
    }

    // ── REST: Dashboard clients ──
    public function rest_get_clients( $request ) {
        $prac_id = get_current_user_id();
        $clients = HDLV2_Compatibility::get_clients_for_practitioner( $prac_id );

        global $wpdb;
        $result = array();
        foreach ( $clients as $client_id ) {
            $user   = get_userdata( $client_id );
            $status = self::calculate_status( $client_id );

            // v0.41.17 — `AND deleted_at IS NULL` (both queries).
            $last_checkin = $wpdb->get_var( $wpdb->prepare(
                "SELECT MAX(confirmed_at) FROM {$wpdb->prefix}hdlv2_checkins
                 WHERE client_id = %d AND status = 'confirmed' AND deleted_at IS NULL",
                $client_id
            ) );

            // Latest form progress row — used by the practitioner dashboard
            // to deep-link into the consultation UI (/consultation/?progress_id=X).
            // v0.41.7 — also pull stage1_completed_at so the dashboard's V1
            // table cells (First Entry / Total) can be populated for V2-only
            // clients (Bug-1: previously hardcoded em-dash).
            // v0.41.17 — filter soft-deleted rows.
            $progress = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, stage1_completed_at, stage2_completed_at, stage3_completed_at
                 FROM {$wpdb->prefix}hdlv2_form_progress
                 WHERE client_user_id = %d AND deleted_at IS NULL
                 ORDER BY id DESC LIMIT 1",
                $client_id
            ) );
            $progress_id = $progress ? (int) $progress->id : 0;

            // WHY release state — surfaced so the dashboard can render an
            // inline Release button on awaiting_why_release rows.
            $why = $progress_id ? $wpdb->get_row( $wpdb->prepare(
                "SELECT id, released FROM {$wpdb->prefix}hdlv2_why_profiles WHERE form_progress_id = %d LIMIT 1",
                $progress_id
            ) ) : null;

            // v0.20.0 — latest_event_at drives the dashboard notification banner's
            // "reappear on new activity" logic. Represents the most recent event
            // on this client that would change what the practitioner needs to do.
            // Includes: latest confirmed check-in, latest report (draft/final),
            // latest consultation_notes activity, stage3/stage2 completion.
            $latest_ts = array( $last_checkin );
            if ( $progress_id ) {
                $latest_ts[] = $progress->stage3_completed_at;
                $latest_ts[] = $progress->stage2_completed_at;
                $latest_ts[] = $wpdb->get_var( $wpdb->prepare(
                    "SELECT MAX(created_at) FROM {$wpdb->prefix}hdlv2_reports WHERE form_progress_id = %d",
                    $progress_id
                ) );
                $latest_ts[] = $wpdb->get_var( $wpdb->prepare(
                    "SELECT MAX(created_at) FROM {$wpdb->prefix}hdlv2_consultation_notes WHERE form_progress_id = %d",
                    $progress_id
                ) );
            }
            $latest_ts = array_filter( $latest_ts, function ( $v ) { return ! empty( $v ); } );
            $latest_event_at = $latest_ts ? max( $latest_ts ) : null;

            // Email hash matches V1's dashboard row key (data-client-hash)
            // so the client-list JS enhancement can splice V2 data into
            // the right row without scanning by email.
            $email      = $user ? $user->user_email : '';
            $email_hash = $email ? hash( 'sha256', strtolower( trim( $email ) ) ) : '';

            // v0.21.2 — expose final-report presence so the client-list JS
            // can flip the Consultation tab CTA from "Record consultation"
            // to "View final report" when a final already exists.
            $has_final_report = $progress_id ? (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}hdlv2_reports WHERE form_progress_id = %d AND report_type = 'final' LIMIT 1",
                $progress_id
            ) ) : false;

            // v0.41.7 — V2 stage roll-up exposed so the client-list JS can
            // populate First Entry / Last Entry / Total cells for V2-only
            // rows. "Total" semantic = stages completed (1-3); see Bug-1
            // in PRACTITIONER-DASHBOARD-V2-BRAINSTORM-2026-05-15.md.
            $stage_dates = array_filter( array(
                $progress && ! empty( $progress->stage1_completed_at ) ? $progress->stage1_completed_at : null,
                $progress && ! empty( $progress->stage2_completed_at ) ? $progress->stage2_completed_at : null,
                $progress && ! empty( $progress->stage3_completed_at ) ? $progress->stage3_completed_at : null,
            ) );
            $v2_first_event_at = $stage_dates ? min( $stage_dates ) : null;
            $v2_total_stages   = count( $stage_dates );

            // W11 (v0.41.32) — automation-tier discriminator. user_meta is set
            // at provision time (W4) so this works for both pre-submission
            // (token issued, not yet completed Stage 3) and post-submission
            // automation clients with a single check. Skips the addendum
            // query for the practitioner-led path (the common case) so the
            // dashboard endpoint cost is unchanged for existing rows.
            $tier = (string) get_user_meta( $client_id, 'hdlv2_tier', true );
            $auto_consultation = null;
            if ( $tier === 'automation' && $progress_id ) {
                $addendum_row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id, occurred_at, note_text
                     FROM {$wpdb->prefix}hdlv2_consultation_addenda
                     WHERE client_user_id = %d
                       AND form_progress_id = %d
                       AND submitter = 'client_automation'
                     ORDER BY occurred_at DESC LIMIT 1",
                    $client_id,
                    $progress_id
                ) );
                if ( $addendum_row ) {
                    $auto_consultation = array(
                        'addendum_id'  => (int) $addendum_row->id,
                        'submitted_at' => (string) $addendum_row->occurred_at,
                        // Full body_text — practitioner detail view renders
                        // the complete block. ~10KB ceiling enforced upstream
                        // at submit time (W9 sanitisation).
                        'body_text'    => (string) $addendum_row->note_text,
                    );
                }
            }

            $result[] = array(
                'user_id'          => (int) $client_id,
                'progress_id'      => $progress_id,
                'why_id'           => $why ? (int) $why->id : null,
                'why_released'     => $why ? (int) $why->released : null,
                'name'             => $user ? $user->display_name : 'Unknown',
                // P1a (2026-07-13, action-button unify) — WP user_login so the
                // dashboard's "View Profile" action can resolve /user/{login}/
                // for V2-only rows (V1 rows already carry data-client-login from
                // PHP). Sourced from the $user already fetched above; '' when the
                // user record is missing so the JS can hide the affordance.
                'user_login'       => $user ? $user->user_login : '',
                'email'            => $email,
                'email_hash'       => $email_hash,
                'status'           => $status['status'],
                'label'            => $status['label'],
                'color'            => $status['color'],
                'reasons'          => $status['reasons'],
                'last_checkin_date' => $last_checkin,
                'latest_event_at'  => $latest_event_at,
                'v2_first_event_at' => $v2_first_event_at,
                'v2_total_stages'   => $v2_total_stages,
                'has_final_report' => $has_final_report,
                // W11 (v0.41.32) — automation-tier fields. Default 'practitioner'
                // (empty user_meta → cast to '' → JS treats as practitioner) so
                // every existing client renders identically when flag is off.
                'tier'             => $tier !== '' ? $tier : 'practitioner',
                'auto_consultation' => $auto_consultation,
            );
        }

        return rest_ensure_response( $result );
    }

    // ── REST: Dashboard state-version digest (v0.21.0) ──
    //
    // Returns {v: <epoch>} where v is max(updated_at) across this
    // practitioner's clients' form progress / WHY profiles / check-ins /
    // reports / flight plans / consultation notes. The dashboard polls
    // this every 4s and only fetches the full roster when v changes —
    // trading ~12 req/s at 50 concurrent users for near-realtime status
    // without WebSockets or SSE.
    public function rest_get_version( $request ) {
        $prac_id = get_current_user_id();
        $client_ids = HDLV2_Compatibility::get_clients_for_practitioner( $prac_id );
        if ( empty( $client_ids ) ) {
            $response = rest_ensure_response( array( 'v' => 0 ) );
            $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
            return $response;
        }

        global $wpdb;
        $ids = implode( ',', array_map( 'intval', $client_ids ) );
        $p   = $wpdb->prefix;

        // Seven GREATEST() subqueries, each an indexed MAX on a timestamp
        // column. Single round-trip. Silent fallback to '1970-01-01' so
        // NULL from an empty table doesn't null the whole expression.
        //
        // v0.35.4 (2026-05-07) — added MAX(flight_plan_ticks.ticked_at)
        // so practitioners see client tick activity in realtime (within
        // the 4s digest poll window) instead of waiting 60s for the
        // fallback poll AND only then refreshing on tab re-open.
        // ticked_at is now stamped on every action (tick OR untick) per
        // the rest_tick change in this same release, so this MAX
        // advances on adherence change, not just on the very first tick.
        // v0.41.17 — every subquery filters by `deleted_at IS NULL` on the
        // table that has the column. The digest must not advance when only
        // archived data ticks (e.g., admin restores an old plan that has
        // ticked_at in the past). The JOIN-on-form_progress subqueries get
        // a `fp.deleted_at IS NULL` filter; the standalone tables get
        // their own filter.
        $sql = "SELECT UNIX_TIMESTAMP(GREATEST(
            COALESCE((SELECT MAX(updated_at) FROM {$p}hdlv2_form_progress WHERE client_user_id IN ($ids) AND deleted_at IS NULL), '1970-01-01 00:00:01'),
            COALESCE((SELECT MAX(wp.updated_at) FROM {$p}hdlv2_why_profiles wp INNER JOIN {$p}hdlv2_form_progress fp ON fp.id = wp.form_progress_id WHERE fp.client_user_id IN ($ids) AND fp.deleted_at IS NULL), '1970-01-01 00:00:01'),
            COALESCE((SELECT MAX(confirmed_at) FROM {$p}hdlv2_checkins WHERE client_id IN ($ids) AND deleted_at IS NULL), '1970-01-01 00:00:01'),
            COALESCE((SELECT MAX(rep.updated_at) FROM {$p}hdlv2_reports rep INNER JOIN {$p}hdlv2_form_progress fp ON fp.id = rep.form_progress_id WHERE fp.client_user_id IN ($ids) AND fp.deleted_at IS NULL), '1970-01-01 00:00:01'),
            COALESCE((SELECT MAX(created_at) FROM {$p}hdlv2_flight_plans WHERE client_id IN ($ids) AND deleted_at IS NULL), '1970-01-01 00:00:01'),
            COALESCE((SELECT MAX(COALESCE(cn.approved_at, cn.started_at, cn.created_at)) FROM {$p}hdlv2_consultation_notes cn INNER JOIN {$p}hdlv2_form_progress fp ON fp.id = cn.form_progress_id WHERE cn.client_user_id IN ($ids) AND fp.deleted_at IS NULL), '1970-01-01 00:00:01'),
            COALESCE((SELECT MAX(ticked_at) FROM {$p}hdlv2_flight_plan_ticks WHERE client_id IN ($ids) AND deleted_at IS NULL), '1970-01-01 00:00:01')
        )) as v";

        $v = (int) $wpdb->get_var( $sql );

        $response = rest_ensure_response( array( 'v' => $v ) );
        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
        $response->header( 'Pragma', 'no-cache' );
        return $response;
    }

    // ── REST: Effort vs Outcomes (the money chart) ──
    //
    // Returns the data shape the practitioner-dashboard "Progress" tab needs
    // to render the dual-axis chart: adherence trend (from ticks) alongside
    // rate of ageing trend (from quarterly reports).
    //
    // Adherence: one row per (client, week) from hdlv2_timeline where
    //   entry_type = 'adherence' (Phase A populates these on every tick).
    //   Joined with hdlv2_flight_plans for the canonical week_number.
    //
    // Outcomes: one row per Final / Quarterly report. Rate is re-derived
    //   from form_progress data using HDLV2_Rate_Calculator::calculate_full
    //   so the chart matches what the report itself says, even if no rate
    //   snapshot column existed at the time the report was written.
    public function rest_get_effort_outcomes( $request ) {
        $client_id = absint( $request['client_id'] );
        global $wpdb;
        $p = $wpdb->prefix;

        // Adherence series. Capped at 52 weeks (one year) — the practitioner
        // chart shows trajectory, not full audit history. Older rows remain
        // queryable via /timeline/{id}/export.
        // v0.41.17 — `AND t.deleted_at IS NULL` (Flight Plan JOIN already
        // returns NULL for archived plans via LEFT JOIN, but we also need
        // to hide soft-deleted timeline rows).
        $adherence_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.date AS week_start, t.detail, fp.week_number
             FROM {$p}hdlv2_timeline t
             LEFT JOIN {$p}hdlv2_flight_plans fp
               ON fp.client_id = t.client_id AND DATE(fp.week_start) = DATE(t.date) AND fp.deleted_at IS NULL
             WHERE t.client_id = %d AND t.entry_type = 'adherence' AND t.deleted_at IS NULL
             ORDER BY t.date DESC
             LIMIT 52",
            $client_id
        ) );
        // We selected DESC for the LIMIT, but the chart wants chronological
        // order. Reverse here so the rest of the function sees ASC as before.
        $adherence_rows = is_array( $adherence_rows ) ? array_reverse( $adherence_rows ) : array();
        $adherence = array();
        foreach ( $adherence_rows as $r ) {
            $detail = json_decode( (string) $r->detail, true );
            if ( ! is_array( $detail ) ) $detail = array();
            $adherence[] = array(
                'week_start'  => substr( (string) $r->week_start, 0, 10 ),
                'week_number' => (int) ( $r->week_number ?: ( count( $adherence ) + 1 ) ),
                'overall'     => isset( $detail['overall'] )    ? (int) $detail['overall']    : 0,
                'movement'    => isset( $detail['movement'] )   ? (int) $detail['movement']   : 0,
                'nutrition'   => isset( $detail['nutrition'] )  ? (int) $detail['nutrition']  : 0,
                'key_action'  => isset( $detail['key_action'] ) ? (int) $detail['key_action'] : 0,
            );
        }

        // Outcome series — prefer the rate_snapshot stored on the report row
        // at write-time (v0.22.22+). Falls back to re-deriving from form_progress
        // for legacy rows where the column is NULL. Capped at 12 reports.
        // v0.41.17 — `AND p.deleted_at IS NULL` so outcomes from archived
        // form_progress rows don't surface in the effort/outcomes chart.
        $report_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.id, r.created_at, r.report_type, r.rate_snapshot, p.stage1_data, p.stage3_data
             FROM {$p}hdlv2_reports r
             INNER JOIN {$p}hdlv2_form_progress p ON p.id = r.form_progress_id AND p.deleted_at IS NULL
             WHERE r.client_user_id = %d
               AND r.status = 'ready'
               AND r.report_type IN ('final','quarterly')
             ORDER BY r.created_at DESC
             LIMIT 12",
            $client_id
        ) );
        $report_rows = is_array( $report_rows ) ? array_reverse( $report_rows ) : array();
        $outcomes = array();
        foreach ( $report_rows as $r ) {
            $rate = null;
            if ( $r->rate_snapshot !== null && $r->rate_snapshot !== '' ) {
                $rate = round( (float) $r->rate_snapshot, 2 );
            } elseif ( class_exists( 'HDLV2_Rate_Calculator' ) ) {
                // Legacy fallback for rows written before v0.22.22.
                $s1 = json_decode( (string) $r->stage1_data, true ) ?: array();
                $s3 = json_decode( (string) $r->stage3_data, true ) ?: array();
                $merged = array_merge( $s1, $s3 );
                foreach ( $merged as $k => $v ) {
                    if ( $v === 'skip' ) $merged[ $k ] = null;
                }
                $age    = (int) ( $merged['q1_age'] ?? $merged['age'] ?? 0 );
                $gender = (string) ( $merged['q1_sex'] ?? $merged['gender'] ?? 'other' );
                if ( $age > 0 ) {
                    $calc = HDLV2_Rate_Calculator::calculate_full( $age, $merged, $gender );
                    if ( is_array( $calc ) && isset( $calc['rate'] ) ) {
                        $rate = round( (float) $calc['rate'], 2 );
                    }
                }
            }
            if ( $rate === null ) continue;
            $outcomes[] = array(
                'date'        => substr( (string) $r->created_at, 0, 10 ),
                'rate'        => $rate,
                'report_type' => (string) $r->report_type,
            );
        }
        $baseline_rate = ! empty( $outcomes ) ? $outcomes[0]['rate'] : null;

        $user = get_userdata( $client_id );

        return rest_ensure_response( array(
            'adherence'     => $adherence,
            'outcomes'      => $outcomes,
            'baseline_rate' => $baseline_rate,
            'client_name'   => $user ? $user->display_name : '',
        ) );
    }

    // ── REST: Health probe (public) ──
    //
    // Lightweight system-alive check for CI/CD smoke gates. Verifies the
    // plugin is loaded, V1 dependency is active, all 16 V2 tables exist,
    // and the DB schema version matches the compiled constant. Returns
    // status='ok' when everything checks out; 'degraded' otherwise.
    //
    // Does NOT verify external dependencies (Anthropic, Make.com) — those
    // belong on a separate /health/external probe so a slow third-party
    // doesn't gate plugin smoke tests.
    public function rest_health( $request ) {
        global $wpdb;

        $expected_tables = array(
            'hdlv2_widget_config', 'hdlv2_widget_leads', 'hdlv2_widget_invites',
            'hdlv2_pending_leads', 'hdlv2_form_progress', 'hdlv2_why_profiles',
            'hdlv2_reports', 'hdlv2_consultation_notes', 'hdlv2_checkins',
            'hdlv2_timeline', 'hdlv2_flight_plans', 'hdlv2_flight_plan_ticks',
            'hdlv2_monthly_summaries', 'hdlv2_jobs', 'hdlv2_audio_extractions',
            // v0.28.0 — practitioner-appended consultation addenda (DB v3.1).
            'hdlv2_consultation_addenda',
        );

        $tables_present = 0;
        foreach ( $expected_tables as $t ) {
            $full = $wpdb->prefix . $t;
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                $full
            ) );
            if ( $exists ) $tables_present++;
        }

        $v1_active     = defined( 'HDL_VERSION' );
        $db_version    = (string) get_option( 'hdlv2_db_version', '0' );
        $db_in_sync    = version_compare( $db_version, HDLV2_DB_VERSION, '>=' );
        $tables_ok     = ( $tables_present === count( $expected_tables ) );

        $status = ( $v1_active && $db_in_sync && $tables_ok ) ? 'ok' : 'degraded';

        $resp = rest_ensure_response( array(
            'status'             => $status,
            'plugin_version'     => HDLV2_VERSION,
            'db_version_actual'  => $db_version,
            'db_version_expected' => HDLV2_DB_VERSION,
            'db_in_sync'         => $db_in_sync,
            'v1_active'          => $v1_active,
            'tables_present'     => $tables_present,
            'tables_expected'    => count( $expected_tables ),
            'time'               => current_time( 'c' ),
        ) );
        $resp->set_status( $status === 'ok' ? 200 : 503 );
        return $resp;
    }
}
