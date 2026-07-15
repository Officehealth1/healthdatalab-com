<?php
/**
 * Weekly Flight Plan — AI-generated personalised action plans.
 *
 * @package HDL_Longevity_V2
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class HDLV2_Flight_Plan {

    private static $instance = null;
    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {}

    public function register_hooks() {
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'hdlv2_weekly_flight_plan', array( $this, 'cron_generate_all' ) );
        // Accept 2 args so callers can distinguish 'current' (final-report → first
        // flight plan) from 'next' (check-in confirm → following week's plan).
        add_action( 'hdlv2_generate_single_flight_plan', array( $this, 'generate_for_client' ), 10, 2 );
        // v0.31.1 — auto-repair scheduled when a sparse legacy plan is read.
        add_action( 'hdlv2_repair_sparse_flight_plan', array( $this, 'repair_sparse_plan' ), 10, 3 );
        add_shortcode( 'hdlv2_flight_plan', array( $this, 'render_shortcode' ) );
    }

    /**
     * v0.31.1 — Async repair handler. Triggered from rest_current when a
     * sparse plan is detected. Replaces the existing plan in place with a
     * fresh generation under the v0.31.0 strict prompt + validator.
     *
     * Called via wp_schedule_single_event so it runs out-of-band from the
     * client's GET — they get the broken plan + flag immediately, then
     * subsequent polls pick up the new plan.
     *
     * @param int $client_id      Owner of the plan.
     * @param int $practitioner_id Practitioner who owns the assessment.
     * @param int $sparse_plan_id The plan we're replacing (we resolve the
     *                            week_start from it so we replace exactly
     *                            that week's row).
     */
    public function repair_sparse_plan( $client_id, $practitioner_id, $sparse_plan_id ) {
        global $wpdb;
        // v0.41.17 — `AND deleted_at IS NULL`. Auto-repair must not target
        // a soft-deleted plan (would otherwise overwrite an archived row).
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT week_start FROM {$wpdb->prefix}hdlv2_flight_plans
             WHERE id = %d AND deleted_at IS NULL LIMIT 1",
            (int) $sparse_plan_id
        ) );
        if ( ! $row ) {
            error_log( '[HDLV2 FlightPlan] Repair: source plan ' . (int) $sparse_plan_id . ' vanished, aborting.' );
            return;
        }
        // force=true → generate() drops the existing row + ticks atomically
        // and replaces them. send_email=false → no inbox spam from the
        // self-heal; the client is already on the page.
        $result = $this->generate(
            (int) $client_id,
            (int) $practitioner_id,
            'auto_repair',
            false, // skip email
            $row->week_start,
            true   // force
        );
        if ( is_wp_error( $result ) ) {
            error_log( sprintf( '[HDLV2 FlightPlan] Auto-repair FAILED: client=%d plan=%d week=%s err=%s',
                (int) $client_id, (int) $sparse_plan_id, $row->week_start, $result->get_error_message() ) );
        } else {
            error_log( sprintf( '[HDLV2 FlightPlan] Auto-repair OK: client=%d week=%s new_plan=%d',
                (int) $client_id, $row->week_start, is_int( $result ) ? $result : 0 ) );
        }
    }

    public function register_rest_routes() {
        register_rest_route( 'hdl-v2/v1', '/flight-plan/(?P<client_id>\d+)/current', array(
            'methods' => 'GET', 'callback' => array( $this, 'rest_current' ), 'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'hdl-v2/v1', '/flight-plan/(?P<client_id>\d+)/history', array(
            'methods' => 'GET', 'callback' => array( $this, 'rest_history' ), 'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'hdl-v2/v1', '/flight-plan/(?P<client_id>\d+)/generate', array(
            'methods' => 'POST', 'callback' => array( $this, 'rest_generate' ),
            'permission_callback' => function () { return HDLV2_Compatibility::is_practitioner( get_current_user_id() ); },
        ) );
        register_rest_route( 'hdl-v2/v1', '/flight-plan/tick', array(
            'methods' => 'POST', 'callback' => array( $this, 'rest_tick' ), 'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'hdl-v2/v1', '/flight-plan/tick-all', array(
            'methods' => 'POST', 'callback' => array( $this, 'rest_tick_all' ), 'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'hdl-v2/v1', '/flight-plan/(?P<client_id>\d+)/adherence', array(
            'methods' => 'GET', 'callback' => array( $this, 'rest_adherence' ),
            'permission_callback' => function () { return HDLV2_Compatibility::is_practitioner( get_current_user_id() ); },
        ) );
        register_rest_route( 'hdl-v2/v1', '/flight-plan/(?P<client_id>\d+)/settings', array(
            'methods' => 'POST', 'callback' => array( $this, 'rest_settings' ),
            'permission_callback' => function () { return HDLV2_Compatibility::is_practitioner( get_current_user_id() ); },
        ) );
        // Practitioner preview — same data as client /current but practitioner-only auth
        register_rest_route( 'hdl-v2/v1', '/flight-plan/(?P<client_id>\d+)/preview', array(
            'methods' => 'GET', 'callback' => array( $this, 'rest_current' ),
            'permission_callback' => function () { return HDLV2_Compatibility::is_practitioner( get_current_user_id() ); },
        ) );
        // Practitioner priority notes for next flight plan
        register_rest_route( 'hdl-v2/v1', '/flight-plan/(?P<client_id>\d+)/priorities', array(
            'methods' => 'POST', 'callback' => array( $this, 'rest_priorities' ),
            'permission_callback' => function () { return HDLV2_Compatibility::is_practitioner( get_current_user_id() ); },
        ) );
        // v0.46.58 — Practitioner edits the CURRENT week's stored plan
        // (per-section or per-day-action). Additive revisions; re-renders the
        // direct PDF; zero emails.
        register_rest_route( 'hdl-v2/v1', '/flight-plan/(?P<client_id>\d+)/edit', array(
            'methods' => 'POST', 'callback' => array( $this, 'rest_edit_plan' ),
            'permission_callback' => function () { return HDLV2_Compatibility::is_practitioner( get_current_user_id() ); },
        ) );
        // Callback from Make.com / PDFMonkey — writes the rendered PDF URL
        // back into the flight plan row. Auth: Bearer HDLV2_MAKE_CALLBACK_SECRET.
        register_rest_route( 'hdl-v2/v1', '/flight-plan/pdf-callback', array(
            'methods' => 'POST', 'callback' => array( $this, 'rest_pdf_callback' ),
            'permission_callback' => '__return_true',
        ) );
    }

    // ── Auth: Validate client access via token or practitioner session ──
    //
    // Practitioner branch now requires ownership of the requested
    // client_id — was previously a blanket override that let any
    // practitioner read/write any client's flight plan, ticks, or settings.
    private function validate_client_access( $request, $client_id = 0 ) {
        // Practitioner: must own this client.
        $user_id = get_current_user_id();
        if ( $user_id && HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            if ( $client_id && HDLV2_Compatibility::practitioner_owns_client( $user_id, $client_id ) ) {
                return true;
            }
            return new WP_Error( 'forbidden', 'You do not have access to this client.', array( 'status' => 403 ) );
        }

        // v0.32.5 — Cookie-authenticated client (their own data).
        // Lets the new /my-dashboard/ Today tab POST ticks via the WP cookie
        // without needing a magic-link token in the URL. Restricts to the
        // logged-in user's own client_id; practitioner ownership is the
        // separate path above. Same row, same source of truth — practitioner
        // sees ticks the moment the client sets them, and vice versa.
        if ( $user_id && $client_id && (int) $user_id === (int) $client_id ) {
            return true;
        }

        // Token-based client auth
        $token = $request->get_param( 'token' ) ?: '';
        if ( ! $token ) {
            $params = $request->get_json_params();
            $token = $params['token'] ?? '';
        }
        if ( ! $token || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            return new WP_Error( 'unauthorized', 'Authentication required.', array( 'status' => 403 ) );
        }

        global $wpdb;
        // v0.41.17 — `AND deleted_at IS NULL`. Stale tokens for soft-deleted
        // assessments must not grant access to flight-plan endpoints.
        // v0.47.53 (B4) — `AND token_expires_at > UTC_TIMESTAMP()`: expired
        // tokens no longer authenticate (practitioner + cookie-client paths
        // are handled above this token fallback).
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT client_user_id FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE token = %s AND deleted_at IS NULL AND token_expires_at > UTC_TIMESTAMP() LIMIT 1",
            $token
        ) );
        if ( ! $progress ) {
            return new WP_Error( 'unauthorized', 'Invalid token.', array( 'status' => 403 ) );
        }

        // If client_id provided, verify ownership
        if ( $client_id && (int) $progress->client_user_id !== (int) $client_id ) {
            return new WP_Error( 'forbidden', 'Access denied.', array( 'status' => 403 ) );
        }

        return $progress;
    }

    // ── REST: Current plan ──
    public function rest_current( $request ) {
        $client_id = absint( $request['client_id'] );
        $auth = $this->validate_client_access( $request, $client_id );
        if ( is_wp_error( $auth ) ) return $auth;

        global $wpdb;
        // Skip future-dated plans so a plan scheduled ahead for next week
        // doesn't pre-empt the current week's plan in the client's view.
        // Phase 15 (v0.22.24) — added `id DESC` tiebreaker so when legacy
        // pre-Phase-15 duplicate rows exist for the same week, the latest
        // generation (consistently the richest, with all ticks) wins. New
        // rows post-Phase-I migration can't duplicate (DB unique index),
        // but this protects clients still on STBY with pre-migration data.
        // v0.41.17 — `AND deleted_at IS NULL`. Plans from a prior lifecycle
        // (Phase T cascade soft-delete) must not surface on the client's
        // current Flight Plan view.
        $plan = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_flight_plans
             WHERE client_id = %d AND week_start <= %s AND deleted_at IS NULL
             ORDER BY week_start DESC, id DESC LIMIT 1",
            $client_id,
            current_time( 'Y-m-d' )
        ) );
        if ( ! $plan ) {
            return rest_ensure_response( array( 'plan' => null ) );
        }

        // v0.41.17 — ticks for an active plan can themselves be archived
        // (admin restored the plan but kept ticks soft-deleted in extreme
        // edge cases). Filter for safety.
        $ticks = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_flight_plan_ticks
             WHERE flight_plan_id = %d AND deleted_at IS NULL
             ORDER BY day, time_slot",
            $plan->id
        ) );

        // Three-state decision for the client's Print control:
        //   pdf_url present                     → live link
        //   no pdf_url, pipeline wired, fresh   → poll ("PDF preparing…")
        //   anything else                       → window.print() fallback
        // Plans generated before HDLV2_MAKE_FLIGHT_PDF was configured never
        // receive a callback, so the 10-minute grace window stops old plans
        // from getting stuck on the preparing state forever.
        // v0.46.58 — the direct WP→PDFMonkey renderer supersedes the Make
        // pipeline (whose scenario branch never existed); either counts.
        $pipeline_configured = ( class_exists( 'HDLV2_Flight_Plan_PDF_Service' ) && HDLV2_Flight_Plan_PDF_Service::configured() )
            || ( defined( 'HDLV2_MAKE_FLIGHT_PDF' ) && HDLV2_MAKE_FLIGHT_PDF );
        $plan_age_sec        = $plan->created_at ? ( time() - strtotime( $plan->created_at ) ) : PHP_INT_MAX;
        $pdf_expected        = ( ! $plan->pdf_url ) && $pipeline_configured && ( $plan_age_sec < 600 );

        // v0.31.1 — Auto-repair sparse legacy plans.
        //
        // Plans generated before v0.31.0 (and any plans where Claude returned
        // a partial daily_plan despite the new validator) end up with ticks
        // for fewer than 4 of the 7 expected days. The client opens the page
        // and sees a near-empty week — the bug Quim reported.
        //
        // We detect this on read and schedule an async regeneration with the
        // current strict prompt + validator. The frontend gets a flag
        // (`is_regenerating: true`) and shows a "Updating your plan…" banner;
        // it polls every 10s until the new plan replaces this one.
        //
        // Guards (cost control):
        //   1. Skip if already a v0.31.0+ plan (effective_start_day was set
        //      explicitly during generation, AND priority_notes_used is
        //      populated → we know it went through the new path).
        //   2. Skip if a regen was scheduled in the last 5 minutes (prevents
        //      tight refresh loops from stacking Claude calls).
        //   3. Only fire on actual sparseness: distinct ticked-day count < 4
        //      with a started week (today >= week_start).
        $is_sparse_plan   = false;
        $is_regenerating  = false;
        $today_in_window  = strtotime( current_time( 'Y-m-d' ) ) >= strtotime( $plan->week_start );
        if ( $today_in_window ) {
            $distinct_days = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT day) FROM {$wpdb->prefix}hdlv2_flight_plan_ticks
                 WHERE flight_plan_id = %d AND deleted_at IS NULL",
                $plan->id
            ) );
            // Effective start day determines how many days we EXPECT.
            $eff_start = strtolower( $plan->effective_start_day ?? 'monday' );
            $valid     = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
            $start_idx = array_search( $eff_start, $valid, true );
            $expected_count = $start_idx === false ? 7 : ( 7 - (int) $start_idx );
            // Sparse if the plan has fewer than HALF of the days it should.
            // 7-day plan → <4 days = sparse. 6-day plan (mid-week start) → <3
            // days = sparse. Tighter floor catches the partial-Monday case
            // without false-flagging legitimately short Week 1 plans.
            $sparse_floor = max( 2, (int) ceil( $expected_count / 2 ) );
            if ( $distinct_days < $sparse_floor ) {
                $is_sparse_plan = true;

                // Was this plan already produced under v0.31.0+? If so we
                // probably can't auto-repair (Claude itself is misbehaving)
                // but we still surface the flag so the frontend can show a
                // "this plan needs practitioner attention" notice.
                $produced_with_new_path = ! empty( $plan->priority_notes_used );

                // Schedule regen unless one is already in flight.
                $repair_lock = (int) get_user_meta( $client_id, 'hdlv2_plan_repair_at', true );
                $stale_lock  = ( time() - $repair_lock ) > 300; // 5 min
                if ( ! $produced_with_new_path && $stale_lock ) {
                    update_user_meta( $client_id, 'hdlv2_plan_repair_at', time() );
                    // Find the practitioner so we can pass it through.
                    // v0.41.17 — `AND deleted_at IS NULL`.
                    $prac_id = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT practitioner_user_id FROM {$wpdb->prefix}hdlv2_form_progress
                         WHERE client_user_id = %d AND deleted_at IS NULL
                         ORDER BY id DESC LIMIT 1",
                        $client_id
                    ) );
                    if ( $prac_id ) {
                        // Schedule async regen via the existing single-event hook,
                        // BUT use force=true semantics by going through a dedicated
                        // hook so the duplicate-guard inside generate() doesn't
                        // short-circuit. We use wp_schedule_single_event with a
                        // unique args triple (client, 'repair', plan->id) so we
                        // can't accidentally collide with the post-finalise event.
                        $args = array( (int) $client_id, (int) $prac_id, (int) $plan->id );
                        if ( ! wp_next_scheduled( 'hdlv2_repair_sparse_flight_plan', $args ) ) {
                            wp_schedule_single_event( time() + 5, 'hdlv2_repair_sparse_flight_plan', $args );
                            // Kick wp-cron via internal loopback (same pattern
                            // as the job queue, just lighter).
                            wp_remote_post( site_url( '/wp-cron.php?doing_wp_cron=' . microtime( true ) ), array(
                                'timeout'   => 0.5,
                                'blocking'  => false,
                                'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
                            ) );
                            $is_regenerating = true;
                            error_log( sprintf( '[HDLV2 FlightPlan] Auto-repair queued: client=%d plan=%d distinct_days=%d expected=%d', $client_id, $plan->id, $distinct_days, $expected_count ) );
                        }
                    }
                }
            }
        }

        return rest_ensure_response( array(
            'plan' => array(
                'id'                  => (int) $plan->id,
                'week_number'         => (int) $plan->week_number,
                'week_start'          => $plan->week_start,
                'date_range_end'      => $plan->date_range_end ?? null,
                // v0.31.0 — surface the effective start day so the frontend
                // can render Mon-Sun consistently while greying out the days
                // that fell BEFORE the plan started (mid-week Final Report
                // finalisation). Defaults to 'monday' for legacy rows.
                'effective_start_day' => $plan->effective_start_day ?? 'monday',
                'created_at'          => $plan->created_at ?? null,
                'plan_data'           => json_decode( $plan->plan_data, true ),
                'shopping_list'       => json_decode( $plan->shopping_list, true ),
                'identity_statement'  => $plan->identity_statement,
                'weekly_targets'      => json_decode( $plan->weekly_targets, true ),
                'journey_assistance'  => $plan->journey_assistance,
                'adherence_summary'   => $plan->adherence_summary ? json_decode( $plan->adherence_summary, true ) : null,
                'pdf_url'             => $plan->pdf_url ?: null,
                'pdf_expected'        => (bool) $pdf_expected,
                'status'              => $plan->status,
                // v0.31.1 — repair-flow flags for the frontend.
                'is_sparse'           => $is_sparse_plan,
                'is_regenerating'     => $is_regenerating,
            ),
            'ticks' => array_map( function ( $t ) {
                return array(
                    'id'          => (int) $t->id,
                    'day'         => $t->day,
                    'day_of_week' => array_search( $t->day, array( 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' ) ),
                    'time_slot'   => $t->time_slot,
                    'category'    => $t->category,
                    'action_text' => $t->action_text,
                    'ticked'      => (bool) $t->ticked,
                );
            }, $ticks ),
        ) );
    }

    // ── REST: Generate ──
    // Manual regenerates (dashboard "Regenerate Flight Plan" button) skip the client
    // email to prevent spamming when practitioners iterate on priority notes mid-week.
    public function rest_generate( $request ) {
        $client_id  = absint( $request['client_id'] );
        $prac_id    = get_current_user_id();

        // Ownership: closes the highest-cost IDOR (Claude burn + Make.com
        // fan-out + email storm to a non-owned client).
        if ( ! HDLV2_Compatibility::practitioner_owns_client( $prac_id, $client_id ) ) {
            return new WP_Error( 'forbidden', 'You do not have access to this client.', array( 'status' => 403 ) );
        }

        // v0.46.x (prod-readiness CL-03) — move the ~1-4min Claude generation
        // OFF the request onto the job queue so the manual regenerate never
        // holds a PHP worker. The dashboard polls /jobs/{id}/status. The weekly
        // cron + post-finalise/post-checkin scheduled generations are untouched
        // (already off-request). force=true replaces the current-week plan.
        if ( ! class_exists( 'HDLV2_Job_Queue' ) || ! class_exists( 'HDLV2_Flight_Plan_Jobs' ) ) {
            // Degraded inline fallback (holds the worker) — better than failing.
            $result = $this->generate( $client_id, $prac_id, 'manual', false, null, true );
            if ( is_wp_error( $result ) ) return $result;
            return rest_ensure_response( array( 'success' => true, 'plan_id' => $result ) );
        }

        global $wpdb;
        // Serialise the in-flight check + enqueue with a short per-client DB lock
        // (mirrors enqueue_report_job) so two near-simultaneous clicks can't both
        // enqueue a generation (which would double-fire the Make.com PDF). The
        // queue's find_latest pending/running guard + this lock are the dedup;
        // a deliberate re-click AFTER completion still regenerates (the documented
        // "iterate on priority notes mid-week" flow). Fail-open.
        $lock_name = substr( 'hdlv2_fpgen_' . $client_id, 0, 64 );
        $got_lock  = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );

        $existing = HDLV2_Job_Queue::find_latest( HDLV2_Flight_Plan_Jobs::JOB, $client_id );
        if ( $existing && in_array( $existing->status, array( 'pending', 'running' ), true ) ) {
            if ( $got_lock ) {
                $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
            }
            return rest_ensure_response( array(
                'success'         => true,
                'queued'          => true,
                'job_id'          => (int) $existing->id,
                'status_endpoint' => rest_url( 'hdl-v2/v1/jobs/' . (int) $existing->id . '/status' ),
            ) );
        }

        $idem_key = 'fpman:' . $client_id . ':' . substr( md5( uniqid( '', true ) ), 0, 12 );
        $job_id   = HDLV2_Job_Queue::enqueue(
            HDLV2_Flight_Plan_Jobs::JOB,
            array( 'client_id' => $client_id, 'practitioner_id' => $prac_id ),
            array(
                'reference_id' => $client_id,
                'idem_key'     => $idem_key,
                'priority'     => 88,
                // No auto-retry: generate() fires the Make.com PDF webhook.
                'max_attempts' => 1,
            )
        );

        if ( $got_lock ) {
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        }

        if ( is_wp_error( $job_id ) ) {
            return new WP_Error( 'enqueue_failed', 'Could not start flight plan generation. Please try again.', array( 'status' => 503 ) );
        }
        return rest_ensure_response( array(
            'success'         => true,
            'queued'          => true,
            'job_id'          => (int) $job_id,
            'status_endpoint' => rest_url( 'hdl-v2/v1/jobs/' . (int) $job_id . '/status' ),
        ) );
    }

    // ── REST: Tick ──
    public function rest_tick( $request ) {
        $params = $request->get_json_params();
        $tick_id = absint( $params['tick_id'] ?? 0 );
        $ticked  = ! empty( $params['ticked'] );
        if ( ! $tick_id ) return new WP_Error( 'missing', 'Tick ID required.', array( 'status' => 400 ) );

        // Look up the tick's plan + client via the join, used both for access
        // verification and for the post-update adherence recompute.
        // v0.41.17 — exclude archived ticks/plans from this lookup so a tick
        // POST against a soft-deleted plan returns 404 (not silently updates).
        global $wpdb;
        $tick_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT t.flight_plan_id, fp.client_id FROM {$wpdb->prefix}hdlv2_flight_plan_ticks t
             JOIN {$wpdb->prefix}hdlv2_flight_plans fp ON fp.id = t.flight_plan_id
             WHERE t.id = %d AND t.deleted_at IS NULL AND fp.deleted_at IS NULL",
            $tick_id
        ) );
        if ( ! $tick_row ) return new WP_Error( 'not_found', 'Tick not found.', array( 'status' => 404 ) );

        $auth = $this->validate_client_access( $request, (int) $tick_row->client_id );
        if ( is_wp_error( $auth ) ) return $auth;

        $wpdb->update(
            $wpdb->prefix . 'hdlv2_flight_plan_ticks',
            // v0.35.4 — Always stamp ticked_at, not just on tick=1. The
            // /dashboard/version digest reads MAX(ticked_at) to detect
            // realtime tick changes for the practitioner dashboard;
            // unticking with ticked_at=NULL would not bump the digest and
            // the practitioner would see stale tick state. Storing the
            // last-action timestamp (whether tick or untick) means the
            // digest advances on every action. No callers depend on
            // ticked_at being NULL — verified by grep across plugin tree.
            array( 'ticked' => $ticked ? 1 : 0, 'ticked_at' => current_time( 'mysql' ) ),
            array( 'id' => $tick_id )
        );

        $this->calculate_adherence( (int) $tick_row->flight_plan_id, true );

        return rest_ensure_response( array( 'success' => true ) );
    }

    // ── REST: Tick all ──
    public function rest_tick_all( $request ) {
        $params  = $request->get_json_params();
        $plan_id = absint( $params['flight_plan_id'] ?? 0 );
        $ticked  = ! empty( $params['ticked'] );
        if ( ! $plan_id ) return new WP_Error( 'missing', 'Plan ID required.', array( 'status' => 400 ) );

        // Verify the plan's client_id matches the requester's token.
        // v0.41.17 — soft-deleted plans must not accept tick-all writes.
        global $wpdb;
        $plan_client = $wpdb->get_var( $wpdb->prepare(
            "SELECT client_id FROM {$wpdb->prefix}hdlv2_flight_plans
             WHERE id = %d AND deleted_at IS NULL",
            $plan_id
        ) );
        if ( ! $plan_client ) return new WP_Error( 'not_found', 'Plan not found.', array( 'status' => 404 ) );

        $auth = $this->validate_client_access( $request, (int) $plan_client );
        if ( is_wp_error( $auth ) ) return $auth;

        // Support per-day filtering
        $where = array( 'flight_plan_id' => $plan_id );
        $day = sanitize_text_field( $params['day'] ?? '' );
        $valid_days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
        if ( $day && in_array( $day, $valid_days, true ) ) {
            $where['day'] = $day;
        }

        $wpdb->update(
            $wpdb->prefix . 'hdlv2_flight_plan_ticks',
            // v0.35.4 — Always stamp ticked_at, not just on tick=1. The
            // /dashboard/version digest reads MAX(ticked_at) to detect
            // realtime tick changes for the practitioner dashboard;
            // unticking with ticked_at=NULL would not bump the digest and
            // the practitioner would see stale tick state. Storing the
            // last-action timestamp (whether tick or untick) means the
            // digest advances on every action. No callers depend on
            // ticked_at being NULL — verified by grep across plugin tree.
            array( 'ticked' => $ticked ? 1 : 0, 'ticked_at' => current_time( 'mysql' ) ),
            $where
        );

        $this->calculate_adherence( $plan_id, true );

        return rest_ensure_response( array( 'success' => true ) );
    }

    // ── REST: Adherence ──
    public function rest_adherence( $request ) {
        $client_id = absint( $request['client_id'] );

        // Ownership.
        if ( ! HDLV2_Compatibility::practitioner_owns_client( get_current_user_id(), $client_id ) ) {
            return new WP_Error( 'forbidden', 'You do not have access to this client.', array( 'status' => 403 ) );
        }

        global $wpdb;
        // v0.41.17 — `AND deleted_at IS NULL`. Adherence chart must skip
        // archived plans.
        $plans = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, week_number, week_start FROM {$wpdb->prefix}hdlv2_flight_plans
             WHERE client_id = %d AND deleted_at IS NULL
             ORDER BY week_start DESC LIMIT 8",
            $client_id
        ) );

        $result = array();
        foreach ( $plans as $p ) {
            $result[] = array_merge( array( 'week_number' => (int) $p->week_number, 'week_start' => $p->week_start ), $this->calculate_adherence( $p->id ) );
        }
        return rest_ensure_response( $result );
    }

    // ── REST: History ──
    public function rest_history( $request ) {
        $client_id = absint( $request['client_id'] );
        $auth = $this->validate_client_access( $request, $client_id );
        if ( is_wp_error( $auth ) ) return $auth;

        $page = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
        $offset = ( $page - 1 ) * 10;

        global $wpdb;
        // v0.41.17 — `AND deleted_at IS NULL`.
        $plans = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, week_number, week_start, identity_statement, status, created_at
             FROM {$wpdb->prefix}hdlv2_flight_plans
             WHERE client_id = %d AND deleted_at IS NULL
             ORDER BY week_start DESC LIMIT 10 OFFSET %d",
            $client_id, $offset
        ) );

        return rest_ensure_response( array_map( function ( $p ) {
            return array(
                'id' => (int) $p->id, 'week_number' => (int) $p->week_number,
                'week_start' => $p->week_start, 'identity_statement' => $p->identity_statement,
                'status' => $p->status,
            );
        }, $plans ) );
    }

    // ── REST: Settings (per-client generation day) ──
    public function rest_settings( $request ) {
        $client_id = absint( $request['client_id'] );

        // Ownership: was writing user_meta on any user_id.
        if ( ! HDLV2_Compatibility::practitioner_owns_client( get_current_user_id(), $client_id ) ) {
            return new WP_Error( 'forbidden', 'You do not have access to this client.', array( 'status' => 403 ) );
        }

        $params = $request->get_json_params();
        $gen_day = sanitize_text_field( $params['generation_day'] ?? '' );
        $valid_days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
        if ( ! in_array( $gen_day, $valid_days, true ) ) {
            return new WP_Error( 'invalid_day', 'Must be monday-sunday.', array( 'status' => 400 ) );
        }
        update_user_meta( $client_id, 'hdlv2_flight_plan_day', $gen_day );
        return rest_ensure_response( array( 'success' => true, 'generation_day' => $gen_day ) );
    }

    // ── REST: Store practitioner priority notes for next flight plan ──
    public function rest_priorities( $request ) {
        $client_id = absint( $request['client_id'] );

        // Ownership: was writing user_meta (priority notes that influence
        // the next AI-generated plan) on any user_id.
        if ( ! HDLV2_Compatibility::practitioner_owns_client( get_current_user_id(), $client_id ) ) {
            return new WP_Error( 'forbidden', 'You do not have access to this client.', array( 'status' => 403 ) );
        }

        $params = $request->get_json_params();
        $notes = sanitize_textarea_field( $params['notes'] ?? '' );
        if ( ! $notes ) {
            return new WP_Error( 'empty', 'Notes text is required.', array( 'status' => 400 ) );
        }
        update_user_meta( $client_id, 'hdlv2_next_plan_priorities', $notes );
        return rest_ensure_response( array( 'success' => true ) );
    }

    // ── Core: Generate flight plan ──
    public function generate( $client_id, $practitioner_id, $trigger = 'auto', $send_email = true, $week_start = null, $force = false, $prebuilt_context = null ) {
        $api_key = defined( 'HDLV2_ANTHROPIC_API_KEY' ) ? HDLV2_ANTHROPIC_API_KEY : '';
        if ( ! $api_key ) return new WP_Error( 'no_key', 'Anthropic API key not configured.' );

        // 5.1 — Use shared Context Builder (tier 2). Callers that have already
        // built the identical tier-2 context for this client (e.g. the weekly
        // cron's zero-engagement gate) may pass it in to avoid rebuilding the
        // full ~12-query context twice in one tick. Default null = build fresh
        // (unchanged behaviour for every other caller).
        $context = is_array( $prebuilt_context )
            ? $prebuilt_context
            : HDLV2_Context_Builder::build_context( $client_id, 2 );
        if ( ! $week_start ) {
            $week_start = date( 'Y-m-d', strtotime( 'monday this week' ) );
        }
        $week_num   = $context['week_number'] ?? 1;

        // ── Phase 15 (v0.22.24) — duplicate guard for the inner Claude path.
        //
        // generate_for_client() already guards but only the outer wrapper.
        // rest_generate() (manual practitioner click) and the bulk weekly
        // cron call generate() directly, bypassing that guard. Two concurrent
        // fires can also race past the outer SELECT and both INSERT (TOCTOU).
        // The DB unique key in Phase I rejects the second insert at the
        // database layer; this guard avoids the wasted Claude burn in the
        // first place and gives a friendly return value rather than a SQL
        // error.
        //
        // $force = true (set by rest_generate when a practitioner explicitly
        // clicks regenerate) replaces the existing plan + ticks atomically.
        // We delete inside a transaction so a Claude-side failure won't leave
        // the client without a plan.
        global $wpdb;
        $plans_table = $wpdb->prefix . 'hdlv2_flight_plans';
        $ticks_table = $wpdb->prefix . 'hdlv2_flight_plan_ticks';

        // v0.41.17 — `AND deleted_at IS NULL`. A soft-deleted "existing"
        // plan must not block a fresh lifecycle's plan generation. Without
        // this, re-inviting a client whose old Flight Plan was cascade-
        // soft-deleted would silently return the archived plan_id.
        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $plans_table
             WHERE client_id = %d AND week_start = %s AND deleted_at IS NULL
             LIMIT 1",
            $client_id, $week_start
        ) );

        if ( $existing_id && ! $force ) {
            // Same week, same client, $force=false — return the existing
            // plan_id without burning Claude. Auto-schedule fires on every
            // checkin/final-report, so this is the common path.
            return (int) $existing_id;
        }

        if ( $existing_id && $force ) {
            // Practitioner explicitly clicked regenerate. Drop the existing
            // plan + its ticks atomically so the new generation fully
            // replaces it. If the Claude call later fails, the client
            // momentarily has no plan for this week, but the next auto-fire
            // (checkin) will recreate it. The ROLLBACK path covers
            // catastrophic delete failures only.
            $wpdb->query( 'START TRANSACTION' );
            try {
                $wpdb->delete( $ticks_table, array( 'flight_plan_id' => (int) $existing_id ), array( '%d' ) );
                $wpdb->delete( $plans_table, array( 'id' => (int) $existing_id ), array( '%d' ) );
                $wpdb->query( 'COMMIT' );
            } catch ( \Throwable $e ) {
                $wpdb->query( 'ROLLBACK' );
                error_log( '[HDLV2] Flight Plan regenerate-force delete failed for client ' . (int) $client_id . ': ' . $e->getMessage() );
                return new WP_Error( 'regen_failed', 'Could not replace existing plan. Try again.' );
            }
        }

        // Read practitioner priority notes (set from dashboard, cleared after use)
        $priority_notes = get_user_meta( $client_id, 'hdlv2_next_plan_priorities', true );
        if ( $priority_notes ) {
            $context['priority_notes'] = $priority_notes;
        }

        // Pass actual dates to AI for temporal context
        $context['week_start_date'] = $week_start;
        $context['week_end_date']   = date( 'Y-m-d', strtotime( $week_start . ' +6 days' ) );

        // 5.2 — Build expanded prompt
        // v0.31.0 — compute the actual first-action day. For Week 1 plans
        // generated mid-week (Final Report finalised on a Tue/Wed/etc.), the
        // plan should NOT pretend to cover Mon — that day is already past.
        // We thread $start_day through build_prompt + create_tick_rows so the
        // AI generates fewer days for Week 1 and we don't insert empty Monday
        // ticks the frontend would render as "No actions".
        $start_day = $this->compute_start_day( $week_start );
        $context['start_day_of_week'] = $start_day;

        $prompt = $this->build_prompt( $context );
        // v0.46.24 — Opus 4.8 (was Sonnet 4.6). Same Messages API, same
        // anthropic-version header (2023-06-01), same max_tokens semantics.
        // No temperature/top_p/top_k sent here, so Opus-safe. POST timeout is
        // already 120s below — adequate for Opus on the 12k-token plan.
        $model  = 'claude-opus-4-8';

        // v0.31.0 — wrap the Claude call in a validated try/retry loop.
        //   1st attempt: standard prompt.
        //   If daily_plan is missing days (or any day has zero actions), we
        //   retry ONCE with an appended "STRICT" instruction reiterating the
        //   exact shape requirement. Most failures are first-attempt only —
        //   Opus 4.8 honours the explicit shape on the second pass.
        // max_tokens bumped 8000 → 12000 — heavier WHY/Addenda contexts
        // were observed clipping at 8000. 12000 still well under Opus 4.8's
        // hard cap (16384 implicit) and adds <0.5s latency in practice.
        $token_usage = array( 'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0 );
        $plan        = null;
        $attempts    = 0;
        $last_error  = null;
        $expected_days = $this->expected_days_for_start( $start_day );

        while ( $attempts < 2 && $plan === null ) {
            $attempts++;
            $system_prompt = $prompt['system'];
            $user_prompt   = $prompt['user'];
            if ( $attempts > 1 ) {
                // Retry — append a strict reminder. Don't rebuild the whole
                // prompt; just amplify the JSON-shape requirement.
                $user_prompt .= "\n\nSTRICT RETRY: The previous response was incomplete. The daily_plan object MUST include exactly these keys (lowercase) and each MUST have at least 3 actions across at least 2 time slots:\n" . wp_json_encode( $expected_days );
            }

            $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
                'headers' => array(
                    'x-api-key' => $api_key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json',
                ),
                'body' => wp_json_encode( array(
                    'model'      => $model,
                    'max_tokens' => 12000,
                    'system'     => $system_prompt,
                    'messages'   => array( array( 'role' => 'user', 'content' => $user_prompt ) ),
                ) ),
                'timeout' => 120,
            ) );

            if ( is_wp_error( $response ) ) {
                $last_error = $response;
                break; // network errors don't benefit from retry within the same request — caller's retry path covers it
            }
            $code = wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( $code !== 200 ) {
                $last_error = new WP_Error( 'api_error', $body['error']['message'] ?? "HTTP $code" );
                break;
            }

            $content = $body['content'][0]['text'] ?? '';
            // Strip markdown code fences if present (```json ... ```)
            $content = preg_replace( '/^\s*```(?:json)?\s*/i', '', $content );
            $content = preg_replace( '/\s*```\s*$/', '', $content );
            $candidate = json_decode( $content, true );

            // Aggregate token usage across attempts so we record the real cost.
            $usage = $body['usage'] ?? array();
            $token_usage['prompt_tokens']     += $usage['input_tokens']  ?? 0;
            $token_usage['completion_tokens'] += $usage['output_tokens'] ?? 0;
            $token_usage['total_tokens']      += ( $usage['input_tokens']  ?? 0 ) + ( $usage['output_tokens'] ?? 0 );

            // Validate the candidate. On success, accept and break.
            $validation = $this->validate_plan_shape( $candidate, $expected_days );
            if ( $validation === true ) {
                $plan = $candidate;
                break;
            }

            // Validation failed — log the reason and either retry or give up.
            error_log( sprintf(
                '[HDLV2 FlightPlan] Validation failed (attempt %d) for client %d week %s: %s',
                $attempts, (int) $client_id, $week_start, $validation
            ) );
            if ( $attempts >= 2 ) {
                // Out of retries. Accept the candidate IF it parsed at all so
                // create_tick_rows backfills with rest-day fallback; only fail
                // hard if Claude returned non-JSON garbage twice in a row.
                if ( is_array( $candidate ) ) {
                    $plan = $candidate;
                } else {
                    $last_error = new WP_Error( 'plan_invalid', 'Claude returned an unparseable plan after retry: ' . $validation );
                }
                break;
            }
        }

        if ( $plan === null ) {
            return $last_error ?: new WP_Error( 'plan_failed', 'Plan generation failed without a recoverable response.' );
        }

        // v0.31.0 — fill any missing-or-sparse days with a templated rest-day
        // card so the frontend never renders "No actions". Idempotent: days
        // already valid pass through unchanged. See R9 in the v0.31.0 plan.
        $plan = $this->backfill_sparse_days( $plan, $expected_days );

        // Calculate date_range_end from week_start (never trust AI for dates)
        $date_range_end = date( 'Y-m-d', strtotime( $week_start . ' +6 days' ) );

        // v0.31.0 — R8 audit trail: capture exactly what fed the AI so we
        // can later answer "why was this in the plan". Refs only — never the
        // raw text of consultation notes (PII duplication, storage bloat).
        $priority_notes_used = wp_json_encode( array(
            'priority_notes'     => $context['priority_notes']     ?? null,
            'addenda_ids'        => $context['addenda_ids']        ?? array(),
            'checkin_ids'        => $context['checkin_ids']        ?? array(),
            'adherence_used'     => $context['adherence_summary']  ?? array(),
            'expected_days'      => $expected_days,
            'start_day'          => $start_day,
            'engagement_signal'  => $context['engagement_signal']  ?? null,
        ) );

        // Store with new metadata columns
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'hdlv2_flight_plans', array(
            'client_id'             => $client_id,
            'practitioner_id'       => $practitioner_id,
            'week_number'           => $week_num,
            'week_start'            => $week_start,
            'date_range_end'        => $date_range_end,
            'effective_start_day'   => $start_day,
            'plan_data'             => wp_json_encode( $plan ),
            'shopping_list'         => wp_json_encode( $plan['shopping_list'] ?? array() ),
            'identity_statement'    => sanitize_text_field( $plan['identity_statement'] ?? '' ),
            'weekly_targets'        => wp_json_encode( $plan['weekly_targets'] ?? array() ),
            'journey_assistance'    => sanitize_textarea_field( $plan['journey_assistance'] ?? '' ),
            'status'                => 'generated',
            'generation_trigger'    => $trigger,
            'priority_notes_used'   => $priority_notes_used,
            'ai_model'              => $model,
            'token_usage'           => wp_json_encode( $token_usage ),
            'generation_attempts'   => $attempts,
        ) );
        $plan_id = (int) $wpdb->insert_id;

        // Create tick rows
        $this->create_tick_rows( $plan_id, $client_id, $plan );

        // Timeline entry — direct insert to include date fields
        $wpdb->insert( $wpdb->prefix . 'hdlv2_timeline', array(
            'client_id'       => $client_id,
            'practitioner_id' => $practitioner_id,
            'entry_type'      => 'flight_plan',
            'title'           => sprintf( 'Week %d Flight Plan generated', $week_num ),
            'date'            => $week_start,
            'end_date'        => date( 'Y-m-d', strtotime( $week_start . ' +6 days' ) ),
            'temporal_type'   => 'interval',
            'category'        => 'system',
            'source'          => 'system',
            'summary'         => sprintf( 'Weekly Flight Plan for %s – %s', date( 'j M', strtotime( $week_start ) ), date( 'j M Y', strtotime( $week_start . ' +6 days' ) ) ),
            'source_table'    => 'hdlv2_flight_plans',
            'source_id'       => $plan_id,
        ) );

        // Fire Make.com webhook (non-blocking) so PDFMonkey can render the
        // printable landscape A4. The callback at /flight-plan/pdf-callback
        // will write the rendered pdf_url back into this row when ready.
        // v0.46.58 — direct WP→PDFMonkey render replaces the Make webhook
        // (HDLV2_MAKE_FLIGHT_PDF fired into a scenario with no matching
        // branch — the PDF never rendered). The renderer self-hosts the file
        // and stamps pdf_* on this row; legacy fallback keeps the old fire
        // if the service is unavailable.
        if ( class_exists( 'HDLV2_Flight_Plan_PDF_Service' ) && HDLV2_Flight_Plan_PDF_Service::configured() ) {
            HDLV2_Flight_Plan_PDF_Service::enqueue_render( $plan_id );
        } else {
            $this->fire_flight_plan_webhook( $plan_id );
        }

        // Notify client that their flight plan is ready (skip on manual regenerate to avoid inbox spam).
        // PDF URL may already be populated if the Make scenario responded
        // synchronously — otherwise the email carries only the online link
        // and the client will see the Download PDF button next time they
        // visit the plan page.
        if ( $send_email && class_exists( 'HDLV2_Email_Templates' ) ) {
            // v0.41.17 — `AND deleted_at IS NULL`. Don't email an archived
            // client's old token (which is now invalid anyway via P0-2).
            $fp_progress = $wpdb->get_row( $wpdb->prepare(
                "SELECT client_name, client_email, token, practitioner_user_id
                 FROM {$wpdb->prefix}hdlv2_form_progress
                 WHERE client_user_id = %d AND deleted_at IS NULL
                 ORDER BY id DESC LIMIT 1",
                $client_id
            ) );
            if ( $fp_progress && $fp_progress->client_email ) {
                $plan_url = site_url( '/my-flight-plan/?token=' . $fp_progress->token );
                $current_pdf = $wpdb->get_var( $wpdb->prepare(
                    "SELECT pdf_url FROM {$wpdb->prefix}hdlv2_flight_plans
                     WHERE id = %d AND deleted_at IS NULL",
                    $plan_id
                ) );
                // v0.36.23 — drop inline 'there' fallback; derive_first_name()
                // resolves first names centrally now.
                HDLV2_Email_Templates::flight_plan_ready( array(
                    'client_name'     => $fp_progress->client_name,
                    'client_email'    => $fp_progress->client_email,
                    'plan_url'        => $plan_url,
                    'pdf_url'         => $current_pdf ?: '',
                    'week_number'     => $week_num,
                    'practitioner_id' => $fp_progress->practitioner_user_id ?? null,
                ) );
            }
        }

        // Clear practitioner priority notes after use
        delete_user_meta( $client_id, 'hdlv2_next_plan_priorities' );

        return $plan_id;
    }

    /**
     * Generate for a single client (called from check-in confirm trigger, cron,
     * or first-plan trigger after final report).
     *
     * @param int    $client_id    Client WP user ID.
     * @param string $target_week  'current' (this Monday — first flight plan
     *                             after final report) or 'next' (next Monday —
     *                             the plan the client uses next week, after
     *                             submitting this week's check-in).
     */
    public function generate_for_client( $client_id, $target_week = 'current' ) {
        global $wpdb;

        $week_start = ( $target_week === 'next' )
            ? date( 'Y-m-d', strtotime( 'next monday' ) )
            : date( 'Y-m-d', strtotime( 'monday this week' ) );

        // Duplicate prevention — one plan per client per target week.
        // v0.34.3 — check status, not just existence. Final Report regen
        // archives prior live plans (status='archived') before scheduling
        // this hook. Without status awareness, we'd find the archived row
        // and silently return — leaving the client with no live plan after
        // every Save & Update Plan. Now: live row = no-op (auto-fire path);
        // archived/completed = replace via $force=true.
        // v0.41.17 — `AND deleted_at IS NULL`. A soft-deleted plan for the
        // same week must not block a fresh lifecycle's plan generation.
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status, generation_trigger FROM {$wpdb->prefix}hdlv2_flight_plans
             WHERE client_id = %d AND week_start = %s AND deleted_at IS NULL
             LIMIT 1",
            $client_id, $week_start
        ) );
        $force      = false;
        $send_email = true;
        if ( $existing ) {
            if ( in_array( $existing->status, array( 'generated', 'delivered', 'active' ), true ) ) {
                // v0.46.66 (#5) — a check-in must SUPERSEDE a plan the weekly
                // cron pre-built before the client's reply landed, otherwise
                // the reply never shapes next week (the default Saturday cron
                // builds next-Monday's plan; a Sat-night/Sun check-in would
                // hit this no-op and be silently dropped). Only supersede an
                // 'auto' (cron) plan — never loop on a checkin/manual one —
                // and suppress the duplicate "ready" email (the cron already
                // emailed when it built the plan; the client is mid check-in
                // and will see the refreshed plan next week).
                if ( 'next' === $target_week && 'auto' === $existing->generation_trigger ) {
                    $force      = true;
                    $send_email = false;
                } else {
                    return null; // already have a live plan for this week (no-op = success)
                }
            } else {
                // archived / completed — replace with a fresh plan via $force=true
                // (delete-then-INSERT path inside ::generate()).
                $force = true;
            }
        }

        // Get practitioner. v0.41.17 — `AND deleted_at IS NULL`.
        $prac_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT practitioner_user_id FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE client_user_id = %d AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1",
            $client_id
        ) );

        // v0.46.66 — RETURN the result (int plan_id | null no-op | WP_Error) so
        // the queue handler can detect a transient failure and retry/alert.
        // do_action() callers (the legacy hdlv2_generate_single_flight_plan
        // hook) harmlessly ignore the return.
        $trigger = ( $target_week === 'next' ) ? 'checkin' : 'final_report';
        return $this->generate( (int) $client_id, (int) $prac_id, $trigger, $send_email, $week_start, $force );
    }

    /**
     * v0.46.66 (#3/#4) — record a permanently-failed auto/check-in generation
     * so the practitioner is alerted instead of the client silently ending up
     * plan-less. Called by HDLV2_Flight_Plan_Auto_Jobs::handle() on the final
     * (non-retryable) attempt. Mirrors log_zero_engagement_skip(): a timeline
     * row (visible in the client's Timeline tab) + a user_meta flag the
     * dashboard can surface + an error_log line. Idempotent per (client, week).
     *
     * @param int    $client_id
     * @param string $target_week   'next' | 'current'
     * @param string $error_message
     */
    public function record_generation_failure( $client_id, $target_week = 'current', $error_message = '' ) {
        global $wpdb;
        $client_id  = (int) $client_id;
        $week_start = ( 'next' === $target_week )
            ? date( 'Y-m-d', strtotime( 'next monday' ) )
            : date( 'Y-m-d', strtotime( 'monday this week' ) );

        $prac_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT practitioner_user_id FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE client_user_id = %d AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1",
            $client_id
        ) );

        // Idempotent per (client, week): don't stack rows if both attempts fail.
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}hdlv2_timeline
             WHERE client_id = %d AND entry_type = 'flight_plan_failed' AND date = %s
               AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1",
            $client_id, $week_start
        ) );
        if ( ! $existing ) {
            $wpdb->insert( $wpdb->prefix . 'hdlv2_timeline', array(
                'client_id'       => $client_id,
                'practitioner_id' => $prac_id,
                'entry_type'      => 'flight_plan_failed',
                'title'           => 'Flight Plan generation failed',
                'date'            => $week_start,
                'temporal_type'   => 'instant',
                'category'        => 'system',
                'source'          => 'system',
                'summary'         => 'Automatic generation of the ' . ( 'next' === $target_week ? 'upcoming' : 'current' )
                    . " week's Flight Plan failed after an automatic retry. Use \"Regenerate Flight Plan\" on the dashboard to create it manually."
                    . ( $error_message ? ' (' . substr( (string) $error_message, 0, 160 ) . ')' : '' ),
                'created_at'      => current_time( 'mysql' ),
            ) );
        }

        update_user_meta( $client_id, 'hdlv2_flight_plan_gen_failed', $week_start );
        update_user_meta( $client_id, 'hdlv2_flight_plan_gen_failed_at', current_time( 'mysql' ) );
        error_log( sprintf(
            '[HDLV2 FlightPlan] generation PERMANENTLY FAILED for client %d week %s (%s): %s',
            $client_id, $week_start, $target_week, $error_message
        ) );
    }

    /**
     * The Claude prompt asks the AI to produce actions as objects with a
     * separate `checkbox` boolean, but in practice it keeps prefixing the
     * action string with a literal ballot-box glyph (☐ / ☑ / ✓ / ✗). We
     * draw our own tick circles on-screen and PDFMonkey draws its own on
     * paper, so the embedded glyph always reads as noise. Strip it from
     * every string that enters the webhook payload or the ticks table.
     */
    private function clean_action_prefix( $text ) {
        return preg_replace( '/^[\x{2610}\x{2611}\x{2713}\x{2714}\x{2717}\x{2718}\s]+/u', '', (string) $text );
    }

    /**
     * Walk the daily_plan tree and scrub the ☐ prefix from every action /
     * why_anchor string before we ship it to Make.com. Keeps the rest of
     * the structure (type, checkbox boolean, time slots) untouched so the
     * PDFMonkey template can render it verbatim.
     */
    private function clean_daily_plan_actions( $daily_plan ) {
        if ( ! is_array( $daily_plan ) ) return array();
        foreach ( $daily_plan as $day => $slots ) {
            if ( ! is_array( $slots ) ) continue;
            foreach ( $slots as $slot => $items ) {
                if ( ! is_array( $items ) ) continue;
                foreach ( $items as $idx => $item ) {
                    if ( ! is_array( $item ) ) continue;
                    if ( isset( $item['action'] ) ) {
                        $daily_plan[ $day ][ $slot ][ $idx ]['action'] = $this->clean_action_prefix( $item['action'] );
                    }
                    if ( isset( $item['text'] ) ) {
                        $daily_plan[ $day ][ $slot ][ $idx ]['text'] = $this->clean_action_prefix( $item['text'] );
                    }
                }
            }
        }
        return $daily_plan;
    }

    /**
     * Fire Make.com webhook with the full flight plan payload so the
     * PDFMonkey scenario can render a landscape A4 printable and post the
     * URL back via rest_pdf_callback. Non-blocking — the initial "flight
     * plan ready" email goes out whether the PDF is ready or not; the
     * download button shows up on the plan page once the callback lands.
     */
    /**
     * v0.46.58 — The flight-plan PDF field mapping (the retired Make
     * webhook's payload), extracted so the direct renderer ships the
     * identical field set. Caller passes the (non-deleted) plan row.
     */
    public function build_pdf_payload( $plan ) {
        global $wpdb;
        $client_user   = get_userdata( $plan->client_id );
        $prac_user     = get_userdata( $plan->practitioner_id );

        // Practitioner logo — canonical helper (v0.29.4). Same source as
        // Final Report and Stage 2 with file-existence validation built in.
        $prac_logo = HDLV2_Practitioner::get_logo_url( (int) $plan->practitioner_id );

        // v0.41.17 — `AND deleted_at IS NULL`. Skip stale tokens from
        // archived lifecycles.
        $fp_token = $wpdb->get_var( $wpdb->prepare(
            "SELECT token FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE client_user_id = %d AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1",
            $plan->client_id
        ) );

        $plan_data = json_decode( $plan->plan_data, true ) ?: array();

        $payload = array(
            'report_type'           => 'weekly_flight_plan',
            'plan_id'               => (int) $plan->id,
            'client_user_id'        => (int) $plan->client_id,
            'practitioner_id'       => (int) $plan->practitioner_id,
            'client_name'           => $client_user ? $client_user->display_name : '',
            'client_email'          => $client_user ? $client_user->user_email : '',
            'practitioner_name'     => $prac_user ? $prac_user->display_name : '',
            'practitioner_email'    => $prac_user ? $prac_user->user_email : '',
            'practitioner_logo_url' => $prac_logo,
            'week_number'           => (int) $plan->week_number,
            'week_start'            => $plan->week_start,
            'week_end'              => $plan->date_range_end ?: date( 'Y-m-d', strtotime( $plan->week_start . ' +6 days' ) ),
            'week_label'            => date( 'j M', strtotime( $plan->week_start ) ) . ' – ' . date( 'j M Y', strtotime( ( $plan->date_range_end ?: $plan->week_start . ' +6 days' ) ) ),
            'identity_statement'    => preg_replace( '/^[\x{201c}\x{201d}"\s]+|[\x{201c}\x{201d}"\s]+$/u', '', (string) $plan->identity_statement ),
            'flexibility_note'      => $plan_data['flexibility_note'] ?? '',
            'journey_assistance'    => $plan->journey_assistance ?: '',
            'weekly_targets'        => json_decode( $plan->weekly_targets, true ) ?: array(),
            'shopping_list'         => json_decode( $plan->shopping_list, true ) ?: array(),
            'review_prompts'        => $plan_data['review_prompts'] ?? array(),
            'daily_plan'            => $this->clean_daily_plan_actions( $plan_data['daily_plan'] ?? array() ),
            'plan_url'              => $fp_token ? site_url( '/my-flight-plan/?token=' . $fp_token ) : site_url( '/my-flight-plan/' ),
            'generated_at'          => current_time( 'c' ),
        );

        return $payload;
    }

    /**
     * v0.46.58 — legacy Make webhook fire, now a thin wrapper over
     * build_pdf_payload(). Kept for rollback; the live path is
     * HDLV2_Flight_Plan_PDF_Service (direct render, no Make).
     */
    public function fire_flight_plan_webhook( $plan_id ) {
        $webhook_url = defined( 'HDLV2_MAKE_FLIGHT_PDF' ) ? HDLV2_MAKE_FLIGHT_PDF : '';
        if ( empty( $webhook_url ) ) {
            error_log( '[HDLV2] Flight Plan PDF webhook skipped — HDLV2_MAKE_FLIGHT_PDF not configured.' );
            return;
        }
        global $wpdb;
        $plan = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_flight_plans WHERE id = %d AND deleted_at IS NULL",
            $plan_id
        ) );
        if ( ! $plan ) return;
        $payload = $this->build_pdf_payload( $plan );
        $payload['callback_url']    = rest_url( 'hdl-v2/v1/flight-plan/pdf-callback' );
        $payload['callback_secret'] = defined( 'HDLV2_MAKE_CALLBACK_SECRET' ) ? HDLV2_MAKE_CALLBACK_SECRET : '';

        HDLV2_Webhook_Monitor::fire(
            $webhook_url,
            array(
                'body'     => wp_json_encode( $payload ),
                'headers'  => array( 'Content-Type' => 'application/json' ),
                'timeout'  => 10,
                'blocking' => true,
            ),
            'flight_plan_pdf'
        );
    }

    /**
     * Receive the rendered PDF URL from Make.com and persist it against the
     * flight plan row. Auth: Authorization: Bearer <HDLV2_MAKE_CALLBACK_SECRET>
     * (hash_equals so timing differences don't leak the secret).
     *
     * Body: { plan_id, pdf_url }
     */
    public function rest_pdf_callback( $request ) {
        $expected = defined( 'HDLV2_MAKE_CALLBACK_SECRET' ) ? HDLV2_MAKE_CALLBACK_SECRET : '';
        $provided = '';
        $auth = $request->get_header( 'authorization' );
        if ( $auth && stripos( $auth, 'Bearer ' ) === 0 ) {
            $provided = trim( substr( $auth, 7 ) );
        }
        if ( empty( $expected ) || ! hash_equals( $expected, $provided ) ) {
            return new WP_Error( 'forbidden', 'Invalid callback secret.', array( 'status' => 403 ) );
        }

        $params  = $request->get_json_params();
        $plan_id = absint( $params['plan_id'] ?? 0 );
        $pdf_url = esc_url_raw( $params['pdf_url'] ?? '' );

        if ( ! $plan_id || ! $pdf_url ) {
            return new WP_Error( 'invalid', 'plan_id and pdf_url are required.', array( 'status' => 400 ) );
        }

        global $wpdb;
        // v0.41.17 hardening parity — `AND deleted_at IS NULL` so a callback that
        // races a client cascade-soft-delete can't stamp a pdf_url/delivered_at
        // onto an archived/soft-deleted plan. $wpdb->update() can't express the
        // NULL predicate, so use a prepared UPDATE mirroring
        // fire_flight_plan_webhook()'s guarded SELECT.
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}hdlv2_flight_plans
             SET pdf_url = %s, delivered_at = %s
             WHERE id = %d AND deleted_at IS NULL",
            $pdf_url, current_time( 'mysql' ), $plan_id
        ) );

        if ( $updated === false ) {
            return new WP_Error( 'db_error', 'Failed to persist pdf_url.', array( 'status' => 500 ) );
        }

        // 0 rows = the plan doesn't exist or was soft-deleted between the webhook
        // fire and this callback. Don't report a false success; the read paths
        // already filter deleted_at IS NULL so there is nothing to surface.
        if ( $updated === 0 ) {
            return new WP_Error( 'not_found', 'Flight plan not found or no longer active.', array( 'status' => 404 ) );
        }

        return rest_ensure_response( array( 'success' => true, 'plan_id' => $plan_id, 'pdf_url' => $pdf_url ) );
    }

    /**
     * v0.31.0 — Compute the first day of the week the plan should cover.
     *
     * If the plan's $week_start (always a Monday) is in the past or today,
     * AND today falls within that week, the effective first action day is
     * today's day-of-week. Otherwise the plan covers a future week and starts
     * on Monday as normal.
     *
     * Example: Final Report finalised Tuesday → week_start is yesterday's
     * Monday → today's day = 'tuesday' → plan covers Tue-Sun.
     *
     * @param string $week_start Y-m-d of the calendar Monday for this plan.
     * @return string Lowercase day-of-week name ('monday' through 'sunday').
     */
    private function compute_start_day( $week_start ) {
        $valid = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
        $today_ts      = strtotime( date( 'Y-m-d' ) );
        $week_start_ts = strtotime( $week_start );
        if ( $today_ts === false || $week_start_ts === false ) return 'monday';
        $delta_days = (int) floor( ( $today_ts - $week_start_ts ) / 86400 );
        // Within the plan's week: 0..6 → today is the start day.
        // Future week (delta < 0) or past week (delta > 6) → default Monday.
        if ( $delta_days < 0 || $delta_days > 6 ) return 'monday';
        return $valid[ $delta_days ];
    }

    /**
     * v0.31.0 — Given a start day, return the ordered list of day names this
     * plan must cover. Always ends on Sunday.
     */
    private function expected_days_for_start( $start_day ) {
        $valid = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
        $idx = array_search( strtolower( $start_day ), $valid, true );
        if ( $idx === false ) $idx = 0;
        return array_slice( $valid, $idx );
    }

    /**
     * v0.31.0 — Validate that a parsed Claude response has the required
     * top-level keys AND that daily_plan covers every expected day with
     * minimum density. Returns true on success, or a short string explaining
     * the first failure. Used by the retry loop in generate().
     *
     * Density floor: each day must contain at least 3 individual actions
     * across at least 2 distinct time-slot keys. why_anchor entries don't
     * count toward action density (they're not tickable).
     */
    private function validate_plan_shape( $plan, $expected_days ) {
        if ( ! is_array( $plan ) ) {
            return 'plan is not an array (json_decode failed)';
        }
        $required_top = array( 'identity_statement', 'daily_plan', 'weekly_targets' );
        foreach ( $required_top as $key ) {
            if ( ! array_key_exists( $key, $plan ) ) {
                return "missing top-level key '$key'";
            }
        }
        $daily = $plan['daily_plan'];
        if ( ! is_array( $daily ) ) {
            return 'daily_plan is not an object';
        }
        // Normalise keys to lowercase for the check (we still re-validate
        // canonical-keys later in create_tick_rows).
        $present_lc = array();
        foreach ( $daily as $k => $_ ) {
            $present_lc[] = strtolower( (string) $k );
        }
        $missing = array_values( array_diff( $expected_days, $present_lc ) );
        if ( ! empty( $missing ) ) {
            return 'daily_plan missing days: ' . implode( ',', $missing );
        }
        // Density check — count tickable actions per expected day.
        foreach ( $expected_days as $day ) {
            $slots = null;
            foreach ( $daily as $k => $v ) {
                if ( strtolower( (string) $k ) === $day ) { $slots = $v; break; }
            }
            if ( ! is_array( $slots ) ) {
                return "daily_plan[$day] is not an object of time slots";
            }
            $action_count = 0;
            $slot_count   = 0;
            foreach ( $slots as $slot_actions ) {
                if ( ! is_array( $slot_actions ) || empty( $slot_actions ) ) continue;
                $slot_has_action = false;
                foreach ( $slot_actions as $a ) {
                    if ( ! is_array( $a ) ) continue;
                    $type = $a['type'] ?? $a['category'] ?? '';
                    if ( $type === 'why_anchor' ) continue;
                    $action_count++;
                    $slot_has_action = true;
                }
                if ( $slot_has_action ) $slot_count++;
            }
            if ( $action_count < 3 || $slot_count < 2 ) {
                return sprintf(
                    'daily_plan[%s] too sparse: %d actions across %d slots (need ≥3 / ≥2)',
                    $day, $action_count, $slot_count
                );
            }
        }
        return true;
    }

    /**
     * v0.31.0 — R9 — Backfill any missing or sparse days with a templated
     * "Recovery focus" card so the frontend never renders "No actions".
     *
     * Only fires AFTER the retry loop has accepted Claude's response. Its
     * job is purely defensive: if Opus 4.8 still produces a thin day on the
     * second attempt, the client sees three sensible items instead of an
     * empty column. Never overwrites existing actions on populated days.
     */
    private function backfill_sparse_days( $plan, $expected_days ) {
        if ( ! is_array( $plan ) ) return $plan;
        $daily = $plan['daily_plan'] ?? array();
        if ( ! is_array( $daily ) ) $daily = array();

        // Re-key daily_plan to lowercase — the frontend tolerates either
        // ('monday' or 'Monday') but downstream tick storage uses lowercase.
        $normalised = array();
        foreach ( $daily as $k => $v ) {
            $normalised[ strtolower( (string) $k ) ] = $v;
        }

        $rest_template = function ( $day ) {
            return array(
                'morning' => array(
                    array(
                        'type'     => 'nutrition',
                        'action'   => 'Drink 500ml water on waking and aim for 2L through the day.',
                        'checkbox' => true,
                    ),
                ),
                'mid_morning' => array(
                    array(
                        'type'     => 'movement',
                        'action'   => 'Recovery focus: 15-minute walk outdoors, easy pace.',
                        'checkbox' => true,
                    ),
                ),
                'late_evening' => array(
                    array(
                        'type'     => 'key_action',
                        'action'   => 'Mobility wind-down: 5 minutes of hip openers and thoracic rotation before bed.',
                        'checkbox' => true,
                    ),
                ),
            );
        };

        foreach ( $expected_days as $day ) {
            $slots = isset( $normalised[ $day ] ) ? $normalised[ $day ] : null;
            $needs_backfill = false;
            if ( ! is_array( $slots ) || empty( $slots ) ) {
                $needs_backfill = true;
            } else {
                // Count tickable actions for sparseness check.
                $action_count = 0;
                foreach ( $slots as $slot_actions ) {
                    if ( ! is_array( $slot_actions ) ) continue;
                    foreach ( $slot_actions as $a ) {
                        if ( is_array( $a ) && ( $a['type'] ?? '' ) !== 'why_anchor' ) {
                            $action_count++;
                        }
                    }
                }
                if ( $action_count < 3 ) $needs_backfill = true;
            }

            if ( $needs_backfill ) {
                error_log( sprintf( '[HDLV2 FlightPlan] Backfilling sparse day "%s" with rest-day template', $day ) );
                $normalised[ $day ] = $rest_template( $day );
            }
        }

        $plan['daily_plan'] = $normalised;
        return $plan;
    }

    /**
     * v0.46.58 — Practitioner edit of the current week's STORED plan.
     * Additive: every change appends {path, original, new, editor, ts} to
     * plan_data['_revisions'] (AI original never lost), updates the single
     * stored copy (plan_data + the extracted column + the tick row's
     * action_text in lockstep), and queues a direct PDF re-render. The
     * client view reads the DB live, so edits appear on next load. ZERO
     * emails on this path by construction.
     *
     * Body: { plan_id, field: identity_statement|journey_assistance|
     *         weekly_target|daily_action, value, [index], [day], [slot] }
     */
    public function rest_edit_plan( $request ) {
        $client_id = absint( $request['client_id'] );
        $uid       = get_current_user_id();
        if ( ! current_user_can( 'manage_options' )
            && ! HDLV2_Compatibility::practitioner_owns_client( $uid, $client_id ) ) {
            return new WP_Error( 'forbidden', 'You are not linked to this client.', array( 'status' => 403 ) );
        }
        $p       = $request->get_json_params() ?: array();
        $plan_id = absint( $p['plan_id'] ?? 0 );
        $field   = sanitize_key( $p['field'] ?? '' );
        $value   = trim( (string) ( $p['value'] ?? '' ) );
        if ( ! $plan_id || '' === $value ) {
            return new WP_Error( 'invalid', 'plan_id, field and a non-empty value are required.', array( 'status' => 400 ) );
        }

        global $wpdb;
        $plan = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_flight_plans
             WHERE id = %d AND client_id = %d AND deleted_at IS NULL",
            $plan_id, $client_id
        ) );
        if ( ! $plan ) {
            return new WP_Error( 'not_found', 'Flight plan not found.', array( 'status' => 404 ) );
        }

        $plan_data = json_decode( $plan->plan_data, true ) ?: array();
        $cols      = array(); // extracted-column updates applied alongside plan_data
        $path      = $field;
        $original  = '';

        switch ( $field ) {
            case 'identity_statement':
                $original = (string) $plan->identity_statement;
                $clean    = sanitize_text_field( $value );
                $plan_data['identity_statement'] = $clean;
                $cols['identity_statement']      = $clean;
                break;

            case 'journey_assistance':
                $original = (string) $plan->journey_assistance;
                $clean    = sanitize_textarea_field( $value );
                $plan_data['journey_assistance'] = $clean;
                $cols['journey_assistance']      = $clean;
                break;

            case 'weekly_target':
                $i       = absint( $p['index'] ?? -1 );
                $targets = json_decode( $plan->weekly_targets, true ) ?: array();
                if ( ! isset( $targets[ $i ] ) ) {
                    return new WP_Error( 'invalid', 'No such target.', array( 'status' => 400 ) );
                }
                $path     = "weekly_targets[$i]";
                $original = is_array( $targets[ $i ] ) ? (string) ( $targets[ $i ]['target'] ?? '' ) : (string) $targets[ $i ];
                $clean    = sanitize_text_field( $value );
                if ( is_array( $targets[ $i ] ) ) {
                    $targets[ $i ]['target'] = $clean;
                } else {
                    $targets[ $i ] = $clean;
                }
                $plan_data['weekly_targets'] = $targets;
                $cols['weekly_targets']      = wp_json_encode( $targets );
                break;

            case 'daily_action':
                $day  = sanitize_key( $p['day'] ?? '' );
                $slot = sanitize_key( $p['slot'] ?? '' );
                $i    = absint( $p['index'] ?? -1 );
                $valid_days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
                if ( ! in_array( $day, $valid_days, true ) || ! isset( $plan_data['daily_plan'][ $day ][ $slot ][ $i ] )
                    || ! is_array( $plan_data['daily_plan'][ $day ][ $slot ][ $i ] ) ) {
                    return new WP_Error( 'invalid', 'No such action.', array( 'status' => 400 ) );
                }
                $action   =& $plan_data['daily_plan'][ $day ][ $slot ][ $i ];
                $path     = "daily_plan.{$day}.{$slot}[{$i}]";
                $original = (string) ( $action['action'] ?? $action['text'] ?? '' );
                $clean    = sanitize_textarea_field( $value );
                if ( isset( $action['text'] ) && ! isset( $action['action'] ) ) {
                    $action['text'] = $clean; // legacy shape
                } else {
                    $action['action'] = $clean;
                }
                $type = (string) ( $action['type'] ?? $action['category'] ?? 'key_action' );
                unset( $action );
                // Tick lockstep — tick action_index counts only TICKABLE
                // actions (why_anchors are skipped at insert), so map the
                // plan_data index to the tick index by counting non-why
                // actions before this one in the same slot.
                if ( 'why_anchor' !== $type ) {
                    $tick_idx = 0;
                    foreach ( $plan_data['daily_plan'][ $day ][ $slot ] as $k => $a2 ) {
                        if ( $k >= $i ) {
                            break;
                        }
                        $t2 = is_array( $a2 ) ? (string) ( $a2['type'] ?? $a2['category'] ?? 'key_action' ) : 'key_action';
                        if ( 'why_anchor' !== $t2 ) {
                            $tick_idx++;
                        }
                    }
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$wpdb->prefix}hdlv2_flight_plan_ticks
                         SET action_text = %s
                         WHERE flight_plan_id = %d AND day = %s AND time_slot = %s AND action_index = %d
                           AND deleted_at IS NULL",
                        sanitize_text_field( $this->clean_action_prefix( $clean ) ),
                        $plan_id, $day, $slot, $tick_idx
                    ) );
                }
                break;

            case 'daily_action_by_tick':
                // v0.46.59 — editor v2: the grid identifies rows by tick id.
                // Resolve the tick → (day, slot, ordinal) → the plan_data
                // position (why-anchor-aware inverse of create_tick_rows),
                // then identical semantics to 'daily_action'.
                $tick_id = absint( $p['tick_id'] ?? 0 );
                $tick = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}hdlv2_flight_plan_ticks
                     WHERE id = %d AND flight_plan_id = %d AND deleted_at IS NULL",
                    $tick_id, $plan_id
                ) );
                if ( ! $tick ) {
                    return new WP_Error( 'invalid', 'No such action.', array( 'status' => 400 ) );
                }
                $day  = (string) $tick->day;
                $slot = (string) $tick->time_slot;
                $i    = null;
                $ord  = 0;
                foreach ( (array) ( $plan_data['daily_plan'][ $day ][ $slot ] ?? array() ) as $k => $a2 ) {
                    $t2 = is_array( $a2 ) ? (string) ( $a2['type'] ?? $a2['category'] ?? 'key_action' ) : 'key_action';
                    if ( 'why_anchor' === $t2 ) {
                        continue;
                    }
                    if ( $ord === (int) $tick->action_index ) {
                        $i = $k;
                        break;
                    }
                    $ord++;
                }
                if ( null === $i ) {
                    return new WP_Error( 'invalid', 'Plan data and tick rows are out of sync for this action.', array( 'status' => 409 ) );
                }
                $action   =& $plan_data['daily_plan'][ $day ][ $slot ][ $i ];
                $path     = "daily_plan.{$day}.{$slot}[{$i}]";
                $original = (string) ( $action['action'] ?? $action['text'] ?? '' );
                $clean    = sanitize_textarea_field( $value );
                if ( isset( $action['text'] ) && ! isset( $action['action'] ) ) {
                    $action['text'] = $clean;
                } else {
                    $action['action'] = $clean;
                }
                unset( $action );
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}hdlv2_flight_plan_ticks
                     SET action_text = %s WHERE id = %d AND deleted_at IS NULL",
                    sanitize_text_field( $this->clean_action_prefix( $clean ) ), $tick_id
                ) );
                break;

            case 'add_action':
                // v0.46.59 — editor v2 "+": appends a NEW action to a day's
                // category group. Same additive-revision semantics; the new
                // item lands in plan_data AND gets its own tick row so the
                // client view + adherence + the PDF all pick it up.
                $day  = sanitize_key( $p['day'] ?? '' );
                $slot = sanitize_key( $p['slot'] ?? '' );
                $cat  = sanitize_key( $p['category'] ?? '' );
                $valid_days  = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
                $valid_slots = array( 'morning', 'midday', 'afternoon', 'early_evening', 'evening' );
                if ( ! in_array( $day, $valid_days, true ) || ! in_array( $slot, $valid_slots, true )
                    || ! in_array( $cat, array( 'nutrition', 'movement', 'key_action' ), true ) ) {
                    return new WP_Error( 'invalid', 'A valid day, slot and category are required.', array( 'status' => 400 ) );
                }
                $clean = sanitize_textarea_field( $value );
                if ( ! isset( $plan_data['daily_plan'] ) || ! is_array( $plan_data['daily_plan'] ) ) {
                    $plan_data['daily_plan'] = array();
                }
                if ( ! isset( $plan_data['daily_plan'][ $day ] ) || ! is_array( $plan_data['daily_plan'][ $day ] ) ) {
                    $plan_data['daily_plan'][ $day ] = array();
                }
                if ( ! isset( $plan_data['daily_plan'][ $day ][ $slot ] ) || ! is_array( $plan_data['daily_plan'][ $day ][ $slot ] ) ) {
                    $plan_data['daily_plan'][ $day ][ $slot ] = array();
                }
                $plan_data['daily_plan'][ $day ][ $slot ][] = array(
                    'type'     => $cat,
                    'action'   => $clean,
                    'checkbox' => true,
                );
                $new_k    = count( $plan_data['daily_plan'][ $day ][ $slot ] ) - 1;
                $path     = "daily_plan.{$day}.{$slot}[+{$new_k}]";
                $original = '';
                $next_idx = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COALESCE(MAX(action_index) + 1, 0)
                     FROM {$wpdb->prefix}hdlv2_flight_plan_ticks
                     WHERE flight_plan_id = %d AND day = %s AND time_slot = %s AND deleted_at IS NULL",
                    $plan_id, $day, $slot
                ) );
                $wpdb->insert( $wpdb->prefix . 'hdlv2_flight_plan_ticks', array(
                    'flight_plan_id' => $plan_id,
                    'client_id'      => (int) $plan->client_id,
                    'day'            => $day,
                    'time_slot'      => $slot,
                    'category'       => $cat,
                    'action_text'    => sanitize_text_field( $this->clean_action_prefix( $clean ) ),
                    'action_index'   => $next_idx,
                ) );
                break;

            default:
                return new WP_Error( 'invalid', 'Unknown field.', array( 'status' => 400 ) );
        }

        // Additive revision ledger — the AI original is never deleted.
        if ( ! isset( $plan_data['_revisions'] ) || ! is_array( $plan_data['_revisions'] ) ) {
            $plan_data['_revisions'] = array();
        }
        $plan_data['_revisions'][] = array(
            'path'      => $path,
            'original'  => $original,
            'new'       => $clean,
            'editor'    => $uid,
            'timestamp' => current_time( 'mysql' ),
        );

        $cols['plan_data'] = wp_json_encode( $plan_data );
        $updated = $wpdb->update( $wpdb->prefix . 'hdlv2_flight_plans', $cols, array( 'id' => $plan_id ) );
        if ( false === $updated ) {
            return new WP_Error( 'db_error', 'Could not save the edit.', array( 'status' => 500 ) );
        }

        // Fresh PDF with the edited content — direct render, zero emails.
        $pdf_job = class_exists( 'HDLV2_Flight_Plan_PDF_Service' )
            ? HDLV2_Flight_Plan_PDF_Service::enqueue_render( $plan_id )
            : false;

        return rest_ensure_response( array(
            'success'        => true,
            'path'           => $path,
            'revision_count' => count( $plan_data['_revisions'] ),
            'pdf_job'        => $pdf_job,
        ) );
    }

    private function create_tick_rows( $plan_id, $client_id, $plan ) {
        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_flight_plan_ticks';
        $daily = $plan['daily_plan'] ?? $plan;
        if ( ! is_array( $daily ) ) return;

        $valid_days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
        foreach ( $daily as $day_name => $slots ) {
            $day = strtolower( $day_name );
            if ( ! in_array( $day, $valid_days, true ) || ! is_array( $slots ) ) continue;
            foreach ( $slots as $slot => $actions ) {
                if ( ! is_array( $actions ) ) continue;
                $action_idx = 0;
                foreach ( $actions as $action ) {
                    if ( ! is_array( $action ) ) continue;
                    // Support both brief format (type/action) and legacy format (category/text)
                    $type = $action['type'] ?? $action['category'] ?? 'key_action';
                    if ( $type === 'why_anchor' ) continue; // WHY anchors are not tickable
                    $cat = $type;
                    if ( ! in_array( $cat, array( 'movement', 'nutrition', 'key_action' ), true ) ) $cat = 'key_action';
                    $text = $this->clean_action_prefix( $action['action'] ?? $action['text'] ?? '' );
                    $wpdb->insert( $table, array(
                        'flight_plan_id' => $plan_id,
                        'client_id'      => $client_id,
                        'day'            => $day,
                        'time_slot'      => sanitize_text_field( $slot ),
                        'category'       => $cat,
                        'action_text'    => sanitize_text_field( $text ),
                        'action_index'   => $action_idx++,
                    ) );
                }
            }
        }
    }

    public function calculate_adherence( $plan_id, $write_timeline = false ) {
        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_flight_plan_ticks';
        $cats = array( 'movement', 'nutrition', 'key_action' );
        $scores = array();

        // Wrap the COUNT batch + UPDATE + UPSERT in a single transaction so
        // concurrent ticks don't see phantom counts. Without this, if Client A
        // ticks while we're mid-COUNT, totals can drift between subqueries.
        // BEGIN does no harm if a transaction is already open (MySQL nests).
        //
        // The work is wrapped in try/catch so an exception inside the block
        // (DB lock timeout, schema mismatch, etc.) doesn't leave the
        // connection holding row locks until end-of-request. Caller gets
        // partial $scores back; the tick itself already committed in
        // rest_tick() before this runs, so the user-visible action survives
        // even when the adherence recompute fails.
        $wpdb->query( 'START TRANSACTION' );

        try {

        foreach ( $cats as $cat ) {
            // v0.41.17 — `AND deleted_at IS NULL`. Adherence percentages must
            // exclude archived ticks.
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE flight_plan_id = %d AND category = %s AND deleted_at IS NULL", $plan_id, $cat ) );
            $done  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE flight_plan_id = %d AND category = %s AND ticked = 1 AND deleted_at IS NULL", $plan_id, $cat ) );
            $scores[ $cat ] = $total > 0 ? round( $done / $total * 100 ) : 0;
        }

        // v0.41.17 — `AND deleted_at IS NULL`.
        $total_all = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE flight_plan_id = %d AND deleted_at IS NULL", $plan_id ) );
        $done_all  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE flight_plan_id = %d AND ticked = 1 AND deleted_at IS NULL", $plan_id ) );
        $scores['overall'] = $total_all > 0 ? round( $done_all / $total_all * 100 ) : 0;

        // 5.9 — Store adherence summary on the flight plan record
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_flight_plans',
            array( 'adherence_summary' => wp_json_encode( $scores ) ),
            array( 'id' => $plan_id ),
            array( '%s' ),
            array( '%d' )
        );

        // 5.7 — Write adherence timeline entry.
        //
        // Upsert by (client_id, entry_type='adherence', date=week_start) — every
        // tick toggle calls this; a plain INSERT would create N rows per week.
        if ( $write_timeline && class_exists( 'HDLV2_Timeline' ) ) {
            // v0.41.17 — `AND deleted_at IS NULL`. Don't write timeline
            // entries for an archived plan; the cascade would have soft-
            // deleted those too anyway.
            $plan = $wpdb->get_row( $wpdb->prepare(
                "SELECT client_id, practitioner_id, week_number, week_start
                 FROM {$wpdb->prefix}hdlv2_flight_plans
                 WHERE id = %d AND deleted_at IS NULL",
                $plan_id
            ) );
            if ( $plan ) {
                $title    = sprintf( 'Week %d adherence: %d%%', $plan->week_number, $scores['overall'] );
                $summary  = sprintf( 'Week %d adherence: %d%% overall (movement %d%%, nutrition %d%%, key actions %d%%)',
                                $plan->week_number, $scores['overall'], $scores['movement'], $scores['nutrition'], $scores['key_action'] );
                $detail   = wp_json_encode( $scores );
                $end_date = date( 'Y-m-d', strtotime( $plan->week_start . ' +6 days' ) );

                $existing_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}hdlv2_timeline
                     WHERE client_id = %d AND entry_type = 'adherence' AND date = %s
                       AND deleted_at IS NULL
                     ORDER BY id DESC LIMIT 1",
                    $plan->client_id, $plan->week_start
                ) );

                if ( $existing_id ) {
                    $wpdb->update( $wpdb->prefix . 'hdlv2_timeline',
                        array(
                            'title'   => $title,
                            'summary' => $summary,
                            'detail'  => $detail,
                            'end_date' => $end_date,
                        ),
                        array( 'id' => (int) $existing_id )
                    );
                } else {
                    $wpdb->insert( $wpdb->prefix . 'hdlv2_timeline', array(
                        'client_id'       => $plan->client_id,
                        'practitioner_id' => $plan->practitioner_id,
                        'entry_type'      => 'adherence',
                        'title'           => $title,
                        'date'            => $plan->week_start,
                        'end_date'        => $end_date,
                        'temporal_type'   => 'interval',
                        'category'        => 'system',
                        'source'          => 'system',
                        'summary'         => $summary,
                        'detail'          => $detail,
                        'created_at'      => current_time( 'mysql' ),
                    ) );
                }
            }
        }

        $wpdb->query( 'COMMIT' );

        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( sprintf( '[HDLV2] calculate_adherence(plan=%d) failed: %s', $plan_id, $e->getMessage() ) );
        }

        return $scores;
    }

    // ── 5.2: Build expanded prompt from Context Builder data ──
    private function build_prompt( $context ) {
        // Format check-in summaries
        $checkin_text = '';
        if ( ! empty( $context['recent_checkins'] ) ) {
            foreach ( $context['recent_checkins'] as $i => $ci ) {
                $summary = is_string( $ci->summary ) ? $ci->summary : wp_json_encode( json_decode( $ci->summary, true ) );
                $scores  = json_decode( $ci->adherence_scores, true ) ?: array();
                $checkin_text .= sprintf( "Week %d: %s | Adherence: %s | Comfort: %s\n",
                    $ci->week_number, $summary,
                    wp_json_encode( $scores ), $ci->comfort_zone
                );
            }
        }

        // Format practitioner notes — priority notes first (from dashboard quick action)
        $notes_text = '';
        if ( ! empty( $context['priority_notes'] ) ) {
            $notes_text .= "*** URGENT PRIORITY FROM PRACTITIONER — THIS OVERRIDES EVERYTHING ELSE ***\n" . $context['priority_notes'] . "\n---\n";
        }
        if ( ! empty( $context['recent_notes'] ) ) {
            foreach ( $context['recent_notes'] as $note ) {
                $notes_text .= $note . "\n---\n";
            }
        } elseif ( ! empty( $context['latest_note'] ) ) {
            $notes_text .= $context['latest_note'];
        }

        // Format WHY profile
        $why_text = '(not available)';
        if ( ! empty( $context['why_profile'] ) ) {
            $wp = $context['why_profile'];
            $why_text = "Distilled WHY: " . ( $wp['distilled_why'] ?? '' ) . "\n";
            if ( ! empty( $wp['key_people'] ) ) $why_text .= "Key people: " . wp_json_encode( $wp['key_people'] ) . "\n";
            if ( ! empty( $wp['motivations'] ) ) $why_text .= "Motivations: " . wp_json_encode( $wp['motivations'] ) . "\n";
            if ( ! empty( $wp['fears'] ) ) $why_text .= "Fears: " . wp_json_encode( $wp['fears'] ) . "\n";
        }

        // Format comfort zone trend
        $comfort_text = '';
        if ( ! empty( $context['comfort_zone_trend'] ) ) {
            $comfort_text = wp_json_encode( $context['comfort_zone_trend'] );
        }

        // Format adherence history from recent check-ins
        $adherence_text = '';
        if ( ! empty( $context['recent_checkins'] ) ) {
            foreach ( $context['recent_checkins'] as $ci ) {
                $scores = json_decode( $ci->adherence_scores, true ) ?: array();
                $adherence_text .= sprintf( "Week %d (self-reported): overall=%s%%, movement=%s%%, nutrition=%s%%, key_actions=%s%%\n",
                    $ci->week_number,
                    $scores['overall'] ?? '?', $scores['movement'] ?? '?',
                    $scores['nutrition'] ?? '?', $scores['key_actions'] ?? '?'
                );
            }
        }

        // v0.31.0 — R5: tick-derived adherence (actual button presses on the
        // plan itself). Distinct from check-in self-report. The AI sees both
        // signals so it can detect "client says they did it but didn't tick"
        // (low engagement) vs "client ticked everything" (high engagement).
        $tick_adherence_text = '';
        if ( ! empty( $context['tick_adherence'] ) && is_array( $context['tick_adherence'] ) ) {
            foreach ( $context['tick_adherence'] as $ta ) {
                $cats = $ta['category_breakdown'] ?? array();
                $cat_summary = array();
                foreach ( $cats as $cat_name => $cat_data ) {
                    $cat_summary[] = sprintf( '%s=%d%%', $cat_name, (int) round( ( $cat_data['rate'] ?? 0 ) * 100 ) );
                }
                $tick_adherence_text .= sprintf(
                    "Week of %s (actual ticks): %d/%d ticked (%d%% overall, active days=%d) — %s\n",
                    $ta['week_start'],
                    (int) ( $ta['ticked'] ?? 0 ),
                    (int) ( $ta['total']  ?? 0 ),
                    (int) round( ( $ta['rate']  ?? 0 ) * 100 ),
                    (int) ( $ta['days_active'] ?? 0 ),
                    implode( ', ', $cat_summary ) ?: 'no category data'
                );
            }
        }
        if ( $tick_adherence_text === '' ) {
            $tick_adherence_text = '(no prior plan tick data — this is the first plan or none have been ticked)';
        }

        // v0.31.0 — R7: engagement banding for tone calibration. The prompt
        // already says "if low adherence, reduce targets". This makes that
        // signal first-class so Claude can be explicit about WHY it's
        // calibrating — and the audit trail records what tone was chosen.
        $engagement_text = $context['engagement_signal'] ?? 'medium';
        $engagement_clause = '';
        switch ( $engagement_text ) {
            case 'zero':
                $engagement_clause = "ENGAGEMENT: ZERO — client has not checked in OR ticked any actions in 14 days. Build a re-engagement plan: minimal targets, strong WHY anchors, one keystone habit per day. Acknowledge a fresh start.";
                break;
            case 'low':
                $engagement_clause = "ENGAGEMENT: LOW — recent tick rate <30% and no recent check-in. Reduce action density. Strengthen WHY anchors. Identify one keystone habit (often hydration, walk, or sleep window) and build the week around that.";
                break;
            case 'high':
                $engagement_clause = "ENGAGEMENT: HIGH — recent tick rate ≥70% and check-in present. Push JUST BEYOND comfort. Add one new challenge layer. Honour the streak in the identity statement.";
                break;
            case 'medium':
            default:
                $engagement_clause = "ENGAGEMENT: MEDIUM — partial engagement. Hold steady on volume; small progressive challenge.";
                break;
        }

        // Monthly summaries
        $monthly_text = '';
        if ( ! empty( $context['monthly_summaries'] ) ) {
            foreach ( $context['monthly_summaries'] as $ms ) {
                $monthly_text .= sprintf( "%s to %s: %s\n", $ms->month_start, $ms->month_end, $ms->summary );
            }
        }

        // v0.31.0 — explicit JSON shape contract. The previous prompt let
        // Claude return partial daily_plan objects (only Monday populated, or
        // creative key names like "Mon" or "Day 1"), which the create_tick_rows
        // loop silently dropped → "No actions" columns Tue-Sun. Three changes:
        //   1. Pin the daily_plan keys to lowercase day names.
        //   2. Specify which days are required for THIS plan (start_day-aware).
        //   3. Require minimum action density per day to prevent "Mon = 1 item".
        $start_day_of_week = isset( $context['start_day_of_week'] ) ? $context['start_day_of_week'] : 'monday';
        $expected_days     = $this->expected_days_for_start( $start_day_of_week );
        $expected_keys_str = implode( ', ', $expected_days );

        $start_day_clause = $start_day_of_week === 'monday'
            ? 'The plan covers Mon-Sun (7 days).'
            : sprintf(
                'IMPORTANT: This Week 1 plan starts mid-week. The client begins on %s, so generate ONLY %d days of actions: %s. Do NOT include earlier days of the week.',
                ucfirst( $start_day_of_week ),
                count( $expected_days ),
                $expected_keys_str
            );

        $system = 'You are generating a personalised Weekly Flight Plan for a longevity client.
The Flight Plan is a printable document with daily actions structured by time of day.

' . $start_day_clause . '

You must generate:
1. DAILY PLAN: For each day, actions placed in time slots (morning, mid_morning, lunchtime, afternoon, early_evening, late_evening, night). Include: movement/fitness (with checkbox), nutrition (with checkbox), key actions (with checkbox), WHY anchor.
2. IDENTITY STATEMENT: "This week you are someone who..." (present tense, identity-framed)
3. WEEKLY TARGETS: 3-5 measurable targets linked to milestones
4. SHOPPING LIST: Specific items based on the week\'s nutrition plan
5. JOURNEY ASSISTANCE: 1-2 paragraphs of relevant support content
6. FLEXIBILITY NOTE: Remind client the plan is a guide, not rigid
7. REVIEW PROMPTS: Questions for the next check-in

RULES:
- PRACTITIONER NOTES TAKE HIGHEST PRIORITY. If the practitioner says "focus on sleep this week," sleep-related actions dominate.
- Progressive challenge: push JUST BEYOND the client\'s current comfort zone. Use the "too easy / too hard" feedback to calibrate.
- Shopping list and daily nutrition must be INTERNALLY CONSISTENT.
- WHY anchors must be personalised — use the client\'s actual WHY statement, their people, their fears, their milestone targets. Never generic quotes.
- Movement is DIRECTIONAL not prescriptive: "Mobility focus: hips and lower back" not "Do 3 sets of 10 hip bridges."
- All actions have checkboxes.
- If the client had a bad week (low adherence), REDUCE targets slightly. Do NOT pile on extra tasks. Add stronger WHY anchor.
- When slips happen: acknowledge without drama. Address root cause. Never punish.
- Flexibility note: remind them the plan is flexible and they can rearrange their day.
- Include environment management (lay out clothes, prep food, handle difficult people) as Key Actions at the relevant time of day.
- Include review prompts for the next check-in.
- NEVER state a numeric clinical figure — biological age, rate of ageing / pace, blood pressure, BMI, weight, or any measurement — unless that EXACT figure appears in the input data below; if you use one, quote it verbatim. If no figure is provided, speak qualitatively ("your pace of ageing", not a number). The same applies to the client\'s name: use only the name given in the input. Inventing a number or a name is a critical failure.

JSON SHAPE — STRICT:
- Return ONLY valid JSON. No markdown fences. No commentary before or after.
- Top-level keys: identity_statement, flexibility_note, daily_plan, weekly_targets, shopping_list, journey_assistance, review_prompts.
- daily_plan MUST be an object with EXACTLY these keys (lowercase, full names): ' . $expected_keys_str . '. No abbreviations, no other keys.
- Each daily_plan day MUST contain at least 3 actions across at least 2 time slots. A "rest day" still has hydration / mobility / WHY-anchor entries — never fewer than 3 items.
- Each action in daily_plan must have: type (one of: movement|nutrition|key_action|why_anchor), action (text — full sentence), checkbox (boolean — false for why_anchor, true otherwise).
- Time slot keys MUST be one of: morning, mid_morning, lunchtime, afternoon, early_evening, late_evening, night.';

        $user = "Generate Week {$context['week_number']} flight plan for {$context['client_name']}.

PRACTITIONER NOTES (HIGHEST PRIORITY):
{$notes_text}

WHY PROFILE:
{$why_text}

REPORT DATA:
" . ( $context['report_summary'] ?? '(not available)' ) . "

MILESTONES:
" . ( $context['milestones'] ? wp_json_encode( $context['milestones'] ) : '(not set)' ) . "

PREVIOUS CHECK-IN SUMMARIES (most recent first, max 4):
{$checkin_text}

PREVIOUS ADHERENCE SCORES (last 4 weeks):
{$adherence_text}

TICK-BASED ADHERENCE (actual button engagement on the plan, last 4 weeks):
{$tick_adherence_text}

{$engagement_clause}

COMFORT ZONE DATA (from check-ins):
{$comfort_text}

MONTHLY SUMMARIES:
{$monthly_text}

PREVIOUS SHOPPING LIST:
" . wp_json_encode( $context['previous_shopping'] ?? array() ) . "

WEEK NUMBER: {$context['week_number']}
WEEK DATES: " . ( $context['week_start_date'] ?? date( 'Y-m-d' ) ) . " to " . ( $context['week_end_date'] ?? date( 'Y-m-d', strtotime( '+6 days' ) ) ) . "
TODAY: " . date( 'Y-m-d' );

        return array( 'system' => $system, 'user' => $user );
    }

    // ── 5.4: Smart cron — per-client day, duplicate prevention ──
    public function cron_generate_all() {
        global $wpdb;
        $today = strtolower( date( 'l' ) );

        // v0.41.17 — `AND fp.deleted_at IS NULL`. Don't generate weekly
        // Flight Plans for soft-deleted clients.
        // GROUP BY both selected columns (instead of SELECT DISTINCT) so the
        // query is safe under MySQL ONLY_FULL_GROUP_BY (the default in 5.7.5+,
        // all 8.0/9.x). Same dedup, same rows; no ORDER BY (the foreach is
        // order-independent), so nothing depends on row order.
        $clients = $wpdb->get_results(
            "SELECT fp.client_user_id, fp.practitioner_user_id
             FROM {$wpdb->prefix}hdlv2_form_progress fp
             WHERE fp.stage3_completed_at IS NOT NULL AND fp.client_user_id IS NOT NULL
               AND fp.deleted_at IS NULL
             GROUP BY fp.client_user_id, fp.practitioner_user_id"
        );

        $count   = 0;
        $skipped = 0;
        foreach ( $clients as $c ) {
            // 5.5 — Per-client generation day (default Saturday)
            $gen_day = get_user_meta( $c->client_user_id, 'hdlv2_flight_plan_day', true );
            if ( empty( $gen_day ) ) $gen_day = 'saturday';
            if ( $today !== $gen_day ) continue;

            // The weekly cron fires on the client's generation day (default
            // Saturday) to produce the plan for the UPCOMING week. Never for
            // the week already in progress — that plan already exists (it was
            // created after the final report or the previous check-in).
            // v0.41.17 — `AND deleted_at IS NULL` so archived plans don't
            // block a fresh-lifecycle weekly generation.
            $week_start = date( 'Y-m-d', strtotime( 'next monday' ) );
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}hdlv2_flight_plans
                 WHERE client_id = %d AND week_start = %s AND deleted_at IS NULL
                 LIMIT 1",
                $c->client_user_id, $week_start
            ) );
            if ( $existing ) continue;

            // v0.31.0 — R7: zero-engagement gating. If the client hasn't
            // ticked anything AND hasn't checked in for 14 days, generating
            // another plan produces "stale shelfware" — the AI has no
            // signal, the plan won't be opened, and we burn Claude tokens
            // for nothing. Skip + flag the practitioner via timeline +
            // user_meta so they can re-engage manually.
            //
            // Final Report and check-in triggers BYPASS this check —
            // engagement is implicit there (a check-in is itself an act
            // of engagement; finalising means the practitioner is steering).
            // Only the unattended Saturday cron applies the gate.
            $context_for_gate = HDLV2_Context_Builder::build_context( $c->client_user_id, 2 );
            $signal           = $context_for_gate['engagement_signal'] ?? 'medium';
            if ( $signal === 'zero' ) {
                $this->log_zero_engagement_skip( $c->client_user_id, $c->practitioner_user_id );
                $skipped++;
                continue;
            }

            // v0.47.74 — the generate() dispatch (Claude call + Make PDF +
            // client email) auto-fires on LIVE only. Candidate selection and
            // the zero-engagement gate above stay live so STBY remains
            // testable (override: hdlv2_allow_staging_side_effects filter).
            if ( ! HDLV2_Env::gate( 'weekly_flight_plan client:' . (int) $c->client_user_id ) ) {
                $skipped++;
                continue;
            }

            // F18 — reuse the context already built for the gate above (same
            // client, same tier 2) instead of rebuilding it inside generate().
            $result = $this->generate( $c->client_user_id, $c->practitioner_user_id, 'auto', true, $week_start, false, $context_for_gate );
            if ( ! is_wp_error( $result ) ) $count++;
        }

        if ( $count > 0 || $skipped > 0 ) {
            error_log( sprintf( '[HDLV2 FlightPlan] Cron tick: generated=%d, skipped_zero_engagement=%d', $count, $skipped ) );
        }
    }

    /**
     * v0.31.0 — R7 helper. Records a zero-engagement skip on the timeline
     * and bumps a user_meta counter so the practitioner dashboard can flag
     * the client for re-engagement outreach.
     *
     * Idempotent per (client_id, week_start) — re-running the cron in the
     * same window won't duplicate the timeline row.
     */
    private function log_zero_engagement_skip( $client_id, $practitioner_id ) {
        global $wpdb;
        $week_start = date( 'Y-m-d', strtotime( 'next monday' ) );
        // v0.41.17 — `AND deleted_at IS NULL` on the dedup lookup so archived
        // timeline entries don't suppress a fresh-lifecycle skip log.
        $existing   = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}hdlv2_timeline
             WHERE client_id = %d AND entry_type = 'engagement_skip' AND date = %s
               AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1",
            (int) $client_id, $week_start
        ) );
        if ( $existing ) return;

        $wpdb->insert( $wpdb->prefix . 'hdlv2_timeline', array(
            'client_id'       => (int) $client_id,
            'practitioner_id' => (int) $practitioner_id,
            'entry_type'      => 'engagement_skip',
            'title'           => 'Flight Plan generation skipped (no engagement)',
            'date'            => $week_start,
            'temporal_type'   => 'instant',
            'category'        => 'system',
            'source'          => 'system',
            'summary'         => 'Client has not ticked any actions or submitted a check-in in 14 days. The Saturday auto-generation was skipped to avoid producing a plan that won\'t be opened. Practitioner outreach recommended.',
            'created_at'      => current_time( 'mysql' ),
        ) );

        // Bump a user-meta counter so dashboards can show "needs re-engagement".
        $count = (int) get_user_meta( (int) $client_id, 'hdlv2_engagement_skips', true );
        update_user_meta( (int) $client_id, 'hdlv2_engagement_skips', $count + 1 );
        update_user_meta( (int) $client_id, 'hdlv2_last_engagement_skip_at', current_time( 'mysql' ) );
    }

    // ── Shortcode ──
    public function render_shortcode( $atts ) {
        // Resolve client_id from token (same pattern as check-in shortcode)
        $client_id = 0;
        $tok = isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';
        if ( $tok && preg_match( '/^[a-f0-9]{64}$/', $tok ) ) {
            global $wpdb;
            // v0.41.17 — `AND deleted_at IS NULL`. Old tokens for archived
            // assessments must not surface a client_id to the shortcode.
            // v0.47.53 (B4) — nor may expired tokens; the anonymous page
            // load is already blocked at init, this covers direct renders.
            $progress = $wpdb->get_row( $wpdb->prepare(
                "SELECT client_user_id FROM {$wpdb->prefix}hdlv2_form_progress
                 WHERE token = %s AND deleted_at IS NULL AND token_expires_at > UTC_TIMESTAMP() LIMIT 1",
                $tok
            ) );
            if ( $progress ) $client_id = (int) $progress->client_user_id;
        }
        // Practitioner viewing a client
        if ( ! $client_id && isset( $_GET['client_id'] ) ) {
            $client_id = absint( $_GET['client_id'] );
        }
        // v0.32.6 — Cookie-authenticated client viewing their own plan.
        // /my-dashboard/ links here as "Open the whole week →" without a
        // ?token URL param (token-based auth is the magic-link flow). Without
        // this fallback the page renders the empty "Your Flight Plan is being
        // prepared" state for any client who reached /my-flight-plan/ via the
        // dashboard or a bookmark instead of a magic link.
        if ( ! $client_id && is_user_logged_in() ) {
            $uid = get_current_user_id();
            if ( $uid && ! HDLV2_Compatibility::is_practitioner( $uid ) ) {
                $client_id = (int) $uid;
            }
        }

        wp_enqueue_script( 'hdlv2-flight-plan', HDLV2_PLUGIN_URL . 'assets/js/hdlv2-flight-plan.js', array( 'hdlv2-loading' ), HDLV2_VERSION, true );
        wp_enqueue_style( 'hdlv2-form', HDLV2_PLUGIN_URL . 'assets/css/hdlv2-form.css', array( 'hdlv2-loading-css' ), HDLV2_VERSION );
        wp_enqueue_style( 'hdlv2-flight-plan', HDLV2_PLUGIN_URL . 'assets/css/hdlv2-flight-plan.css', array(), HDLV2_VERSION );
        wp_localize_script( 'hdlv2-flight-plan', 'hdlv2_flight_plan', array(
            'api_base'  => rest_url( 'hdl-v2/v1/flight-plan' ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'client_id' => $client_id,
        ) );

        // v0.21.0 — only inject the practitioner nav spine when a practitioner
        // is viewing another user's plan (?client_id param). Clients arriving
        // via magic link (?token) don't need — and shouldn't see — the
        // "Back to clients" chrome.
        $is_prac_viewing = (
            $client_id &&
            is_user_logged_in() &&
            class_exists( 'HDLV2_Compatibility' ) &&
            HDLV2_Compatibility::is_practitioner( get_current_user_id() ) &&
            (int) get_current_user_id() !== (int) $client_id
        );
        if ( $is_prac_viewing ) {
            $client_name = '';
            $user = get_userdata( $client_id );
            if ( $user ) $client_name = $user->display_name ?: $user->user_login;

            wp_enqueue_script(
                'hdlv2-client-nav-bar',
                HDLV2_PLUGIN_URL . 'assets/js/hdlv2-client-nav-bar.js',
                array(),
                HDLV2_VERSION,
                true
            );
            wp_localize_script( 'hdlv2-client-nav-bar', 'hdlv2_nav_bar', array(
                'clients_url' => home_url( '/clients/' ),
                'client_name' => $client_name,
                'page_label'  => 'Flight Plan',
                'api_base'    => rest_url( 'hdl-v2/v1' ),
                'nonce'       => wp_create_nonce( 'wp_rest' ),
            ) );
        }

        return '<div id="hdlv2-flight-plan" class="hdlv2-assessment-root"></div>';
    }
}
