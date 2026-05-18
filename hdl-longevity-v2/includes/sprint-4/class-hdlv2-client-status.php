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
                    stage1_data, stage3_data, stage1_completed_at, stage3_completed_at, created_at
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

        $s1_gauge_url = '';
        if ( $s1_rate !== null && class_exists( 'HDLV2_Widget_Config' ) ) {
            $reflect = new ReflectionClass( 'HDLV2_Widget_Config' );
            if ( $reflect->hasMethod( 'build_gauge_url' ) ) {
                $m = $reflect->getMethod( 'build_gauge_url' );
                if ( $m->isPublic() && $m->isStatic() ) {
                    $s1_gauge_url = (string) HDLV2_Widget_Config::build_gauge_url( $s1_rate );
                }
            }
        }
        // Fallback — staged-form's public build_gauge_url, default Stage 1 bounds.
        if ( ! $s1_gauge_url && $s1_rate !== null && class_exists( 'HDLV2_Staged_Form' ) ) {
            $reflect = new ReflectionClass( 'HDLV2_Staged_Form' );
            if ( $reflect->hasMethod( 'build_gauge_url' ) ) {
                $m = $reflect->getMethod( 'build_gauge_url' );
                if ( $m->isPublic() && $m->isStatic() ) {
                    $s1_gauge_url = (string) HDLV2_Staged_Form::build_gauge_url( $s1_rate, array() );
                }
            }
        }

        $stage1 = array(
            'completed_at'   => $progress->stage1_completed_at ?: $progress->created_at,
            'rate'           => $s1_rate !== null ? round( $s1_rate, 2 ) : null,
            'gauge_url'      => $s1_gauge_url,
            'age'            => isset( $s1['q1_age'] ) ? (int) $s1['q1_age'] : null,
            'sex'            => isset( $s1['q1_sex'] ) ? (string) $s1['q1_sex'] : '',
            'q2_silhouette'  => isset( $s1['q2a'] ) ? (string) $s1['q2a'] : '',
            'q2_fat_dist'    => isset( $s1['q2b'] ) ? (string) $s1['q2b'] : '',
            'q3_zone2'       => isset( $s1['q3'] ) ? strtoupper( (string) $s1['q3'] ) : '',
            'q4_vo2'         => isset( $s1['q4'] ) ? strtoupper( (string) $s1['q4'] ) : '',
            'q5_sts'         => isset( $s1['q5'] ) ? strtoupper( (string) $s1['q5'] ) : '',
            'q6_sleep'       => isset( $s1['q6'] ) ? strtoupper( (string) $s1['q6'] ) : '',
            'q7_smoking'     => isset( $s1['q7'] ) ? strtoupper( (string) $s1['q7'] ) : '',
            'q8_social'      => isset( $s1['q8'] ) ? strtoupper( (string) $s1['q8'] ) : '',
            'q9_diet'        => isset( $s1['q9'] ) ? strtoupper( (string) $s1['q9'] ) : '',
        );

        // ── Stage 2 ─────────────────────────────────────────────
        // v0.24.1 — corrected schema. The why_profiles table doesn't have
        // an `audio_summary` column (that was an assumption on my part);
        // actual columns surface vision_text + fears as free-text fields.
        $why = $wpdb->get_row( $wpdb->prepare(
            "SELECT distilled_why, key_people, motivations, fears, vision_text, ai_reformulation, released, created_at
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
            'released'      => (int) ( $why->released ?? 0 ) === 1,
        ) : null;

        // ── Stage 3 ─────────────────────────────────────────────
        $stage3 = array(
            'completed_at'   => $progress->stage3_completed_at,
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
        );

        // ── Final report ────────────────────────────────────────
        $final = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, created_at, status FROM {$prefix}hdlv2_reports
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
            )
            : array( 'has_report' => false );

        return rest_ensure_response( array(
            'progress_id' => (int) $progress->id,
            'client_name' => (string) $progress->client_name,
            'stage1'      => $stage1,
            'stage2'      => $stage2,
            'stage3'      => $stage3,
            'final'       => $stage_final,
        ) );
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

            $result[] = array(
                'user_id'          => (int) $client_id,
                'progress_id'      => $progress_id,
                'why_id'           => $why ? (int) $why->id : null,
                'why_released'     => $why ? (int) $why->released : null,
                'name'             => $user ? $user->display_name : 'Unknown',
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
            COALESCE((SELECT MAX(COALESCE(approved_at, started_at, created_at)) FROM {$p}hdlv2_consultation_notes cn INNER JOIN {$p}hdlv2_form_progress fp ON fp.id = cn.form_progress_id WHERE cn.client_user_id IN ($ids) AND fp.deleted_at IS NULL), '1970-01-01 00:00:01'),
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
