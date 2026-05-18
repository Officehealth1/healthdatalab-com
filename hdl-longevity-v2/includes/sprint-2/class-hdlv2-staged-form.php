<?php
/**
 * Staged Assessment Form — Controller.
 *
 * Token-based 3-stage form: Quick Insight → WHY → Detail.
 * Clients access via URL with token, no WordPress login required.
 * Save-as-you-go via REST API. Server-side rate calculation on completion.
 *
 * @package HDL_Longevity_V2
 * @since 0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Staged_Form {

    public function register_hooks() {
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_shortcode( 'hdlv2_assessment', array( $this, 'render_shortcode' ) );
        // v0.40.19 — Stage 2 WHY extraction retry safety net.
        // Daily cron retries Make.com extraction for stuck rows (webhook
        // fired > 30 min ago, no why_profile written). After 2 retries,
        // falls back to local extract_why() via Anthropic direct.
        add_action( 'hdlv2_stage2_extraction_retry', array( __CLASS__, 'run_stage2_extraction_retry' ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST ROUTES
    // ──────────────────────────────────────────────────────────────

    public function register_rest_routes() {
        // Load form progress by token (public — token is the auth)
        register_rest_route( 'hdl-v2/v1', '/form/load', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_load_form' ),
            'permission_callback' => '__return_true',
        ) );

        // Auto-save stage data (public — token is the auth)
        register_rest_route( 'hdl-v2/v1', '/form/save', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_save_form' ),
            'permission_callback' => '__return_true',
        ) );

        // Complete a stage (public — token is the auth)
        register_rest_route( 'hdl-v2/v1', '/form/complete-stage', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_complete_stage' ),
            'permission_callback' => '__return_true',
        ) );

        // Generate draft report after Stage 3 (public — token is the auth)
        register_rest_route( 'hdl-v2/v1', '/form/generate-report', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_generate_report' ),
            'permission_callback' => '__return_true',
        ) );

        // Create new form progress (practitioner auth)
        register_rest_route( 'hdl-v2/v1', '/form/create', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_create_form' ),
            'permission_callback' => array( $this, 'check_practitioner_permission' ),
        ) );

        // Release WHY gate — practitioner releases processed WHY + sends Stage 3 invite
        register_rest_route( 'hdl-v2/v1', '/form/release-why', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_release_why' ),
            'permission_callback' => array( $this, 'check_practitioner_permission' ),
        ) );

        // Make.com callback — receives extracted WHY profile after Stage 2 processing
        register_rest_route( 'hdl-v2/v1', '/form/stage2-callback', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_stage2_callback' ),
            'permission_callback' => '__return_true',
        ) );

        // Stage 1 "What This Means For You" AI commentary (Haiku, cached) — v0.22.0
        register_rest_route( 'hdl-v2/v1', '/form/stage1-commentary', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_stage1_commentary' ),
            'permission_callback' => '__return_true',
        ) );

        // Stage 2 immediate-insight AI (Haiku, cached) — v0.22.2
        register_rest_route( 'hdl-v2/v1', '/form/stage2-insight', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_stage2_insight' ),
            'permission_callback' => '__return_true',
        ) );

        // Stage 3 immediate-commentary AI (Haiku, cached) — v0.22.4
        register_rest_route( 'hdl-v2/v1', '/form/stage3-commentary', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_stage3_commentary' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public function check_practitioner_permission() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return false;
        }
        return HDLV2_Compatibility::is_practitioner( $user_id );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: LOAD FORM
    // ──────────────────────────────────────────────────────────────

    /**
     * Load form progress by token.
     * Returns current stage, saved data, completion timestamps.
     */
    public function rest_load_form( $request ) {
        $token = $this->validate_token_param( $request );
        if ( is_wp_error( $token ) ) return $token;

        $progress = $this->get_progress_by_token( $token );
        if ( ! $progress ) {
            return new WP_Error( 'invalid_token', 'Assessment not found.', array( 'status' => 404 ) );
        }

        // Phase 17 (v0.22.26) — fetch practitioner CTA + name + logo so the
        // Stage 1 result page can render the "Book a session" button and
        // practitioner footer. Defaults are safe for clients with no linked
        // practitioner: the button is hidden and the footer falls back to
        // generic copy. Single LIMIT 1 lookup; the practitioner_user_id
        // index makes this cheap.
        $practitioner_name     = '';
        $practitioner_email    = '';
        $practitioner_cta_link = '';
        $practitioner_cta_text = 'Book a session';
        $practitioner_logo_url = '';
        $prac_id               = (int) ( $progress->practitioner_user_id ?? 0 );
        if ( $prac_id ) {
            $prac_user = get_userdata( $prac_id );
            if ( $prac_user ) {
                $practitioner_name  = $prac_user->display_name;
                // v0.39.0 — Thank-You page (Stage 3 submit confirmation) needs
                // the practitioner email so the spam-folder footnote can render
                // a working mailto: link. Other surfaces consuming /form/load
                // (footer CTA, prefill chips) ignore the new field.
                $practitioner_email = $prac_user->user_email;
            }
            global $wpdb;
            // CTA fields stay an inline read (no helper for those yet); logo_url
            // goes through HDLV2_Practitioner::get_logo_url() which adds file-
            // existence validation and the legacy user_meta fallback.
            $cfg = $wpdb->get_row( $wpdb->prepare(
                "SELECT cta_link, cta_text FROM {$wpdb->prefix}hdlv2_widget_config WHERE practitioner_user_id = %d LIMIT 1",
                $prac_id
            ) );
            if ( $cfg ) {
                if ( ! empty( $cfg->cta_link ) ) $practitioner_cta_link = $cfg->cta_link;
                if ( ! empty( $cfg->cta_text ) ) $practitioner_cta_text = $cfg->cta_text;
            }
            $practitioner_logo_url = HDLV2_Practitioner::get_logo_url( $prac_id );
        }

        return rest_ensure_response( array(
            'current_stage'         => (int) $progress->current_stage,
            'client_name'           => $progress->client_name,
            'client_email'          => $progress->client_email,
            'stage1_data'           => json_decode( $progress->stage1_data, true ),
            'stage1_completed_at'   => $progress->stage1_completed_at,
            'stage2_data'           => json_decode( $progress->stage2_data, true ),
            'stage2_completed_at'   => $progress->stage2_completed_at,
            'stage3_data'           => json_decode( $progress->stage3_data, true ),
            'stage3_completed_at'   => $progress->stage3_completed_at,
            'practitioner_name'     => $practitioner_name,
            'practitioner_email'    => $practitioner_email,
            'practitioner_cta_link' => $practitioner_cta_link,
            'practitioner_cta_text' => $practitioner_cta_text,
            'practitioner_logo_url' => $practitioner_logo_url,
        ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: SAVE FORM (auto-save)
    // ──────────────────────────────────────────────────────────────

    /**
     * Auto-save stage data. Called on field blur / debounce.
     * Body: { token, stage: 1|2|3, data: { field: value, ... } }
     */
    public function rest_save_form( $request ) {
        $params = $request->get_json_params();
        $token  = $this->validate_token_from_body( $params );
        if ( is_wp_error( $token ) ) return $token;

        $progress = $this->get_progress_by_token( $token );
        if ( ! $progress ) {
            return new WP_Error( 'invalid_token', 'Assessment not found.', array( 'status' => 404 ) );
        }

        $stage = (int) ( $params['stage'] ?? 0 );
        if ( $stage < 1 || $stage > 3 ) {
            return new WP_Error( 'invalid_stage', 'Stage must be 1, 2, or 3.', array( 'status' => 400 ) );
        }

        $data = $params['data'] ?? array();
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'invalid_data', 'Data must be an object.', array( 'status' => 400 ) );
        }

        // Merge with existing data (don't overwrite fields not in this save)
        $column       = 'stage' . $stage . '_data';
        $existing     = json_decode( $progress->$column, true ) ?: array();
        $merged       = array_merge( $existing, $data );

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_form_progress',
            array( $column => wp_json_encode( $merged ) ),
            array( 'id' => $progress->id ),
            array( '%s' ),
            array( '%d' )
        );

        // Fire Stage 2 webhook to Make.com — only on explicit Submit, not auto-save
        // Guard: skip if webhook already fired for identical text (prevents duplicate PDFs/emails)
        $submitted = ! empty( $params['submitted'] );
        if ( $stage === 2 && $submitted && ! empty( $merged['vision_text'] ) ) {
            $text_hash    = md5( $merged['vision_text'] );
            $prev_hash    = $progress->stage2_text_hash ?? '';
            $already_fired = ! empty( $progress->stage2_webhook_fired_at );

            if ( ! $already_fired || $text_hash !== $prev_hash ) {
                self::fire_stage2_webhook( $progress, $merged );
                $wpdb->update(
                    $wpdb->prefix . 'hdlv2_form_progress',
                    array(
                        'stage2_webhook_fired_at' => current_time( 'mysql' ),
                        'stage2_text_hash'        => $text_hash,
                    ),
                    array( 'id' => $progress->id ),
                    array( '%s', '%s' ),
                    array( '%d' )
                );
            }
        }

        return rest_ensure_response( array( 'success' => true, 'saved_fields' => count( $data ) ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: COMPLETE STAGE
    // ──────────────────────────────────────────────────────────────

    /**
     * Mark a stage as complete. Validates required fields, runs server-side
     * calculation for Stage 1, advances current_stage.
     *
     * Body: { token, stage: 1|2|3 }
     */
    public function rest_complete_stage( $request ) {
        $params = $request->get_json_params();
        $token  = $this->validate_token_from_body( $params );
        if ( is_wp_error( $token ) ) return $token;

        $progress = $this->get_progress_by_token( $token );
        if ( ! $progress ) {
            return new WP_Error( 'invalid_token', 'Assessment not found.', array( 'status' => 404 ) );
        }

        $stage = (int) ( $params['stage'] ?? 0 );
        if ( $stage < 1 || $stage > 3 ) {
            return new WP_Error( 'invalid_stage', 'Stage must be 1, 2, or 3.', array( 'status' => 400 ) );
        }

        $completed_col = 'stage' . $stage . '_completed_at';
        $data_col      = 'stage' . $stage . '_data';
        $stage_data    = json_decode( $progress->$data_col, true ) ?: array();

        // Validate required fields per stage (no DB writes yet — safe to fail).
        $validation = $this->validate_stage_data( $stage, $stage_data );
        if ( is_wp_error( $validation ) ) return $validation;

        // v0.30.1 — Atomic completion claim.
        //
        // Pre-fix code: read $progress->$completed_col, return 409 if non-null,
        // else fall through to side effects. The read-then-write gap allowed
        // two concurrent /complete-stage requests to both pass the guard, both
        // run create_draft_report, and both fire the internal /generate-report
        // worker → 2 webhook fires → 2 client emails (root cause of the
        // 2026-05-05 duplicate-draft incident).
        //
        // Fix: single atomic UPDATE with affected-rows check. Whichever request
        // commits first claims the column; the loser sees 0 affected rows and
        // returns 409. The fresh read of $progress reflects pre-claim state, so
        // downstream code (which reads $progress->stage1_data etc.) is correct.
        global $wpdb;
        $now      = current_time( 'mysql' );
        $claimed  = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}hdlv2_form_progress
                SET {$completed_col} = %s
              WHERE id = %d
                AND ({$completed_col} IS NULL OR {$completed_col} = '')",
            $now, (int) $progress->id
        ) );
        if ( $claimed === 0 || $claimed === false ) {
            return new WP_Error( 'already_completed', 'This stage is already complete.', array( 'status' => 409 ) );
        }
        // Reflect the claim in our in-memory copy so subsequent code that
        // reads $progress->$completed_col sees the new value.
        $progress->$completed_col = $now;

        // Stage 1: run server-side rate calculation and store result
        $extra_updates = array();
        $result_data   = null;
        if ( $stage === 1 ) {
            // V2: pass full answers array to new 9-question calculator
            $result_data = HDLV2_Rate_Calculator::calculate_quick( $stage_data );
            $stage_data['server_result'] = $result_data;
            $extra_updates[ $data_col ]  = wp_json_encode( $stage_data );
        }

        // Stage 2: practitioner gate — don't advance current_stage.
        // Make.com callback handles WHY extraction; the practitioner "Release WHY"
        // action is the ONLY thing that advances current_stage from 2 → 3.
        if ( $stage === 2 ) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'hdlv2_form_progress',
                array( $completed_col => current_time( 'mysql' ) ),
                array( 'id' => $progress->id ),
                array( '%s' ),
                array( '%d' )
            );

            // Save WHY profile if stage_data already has AI extraction (backward compat)
            if ( ! empty( $stage_data['distilled_why'] ) ) {
                $this->save_why_profile( $progress, $stage_data, $stage_data );
            }

            $this->send_stage_email( $stage, $progress, $stage_data, null );

            return rest_ensure_response( array( 'success' => true, 'stage' => 2 ) );
        }

        // Stage 3: full rate calculation + draft report row
        if ( $stage === 3 ) {
            $s1_data   = json_decode( $progress->stage1_data, true ) ?: array();
            $calc_data = array_merge( $s1_data, $stage_data );

            // V2 Stage 1 uses q1_age/q1_sex — map to age/gender for calculate_full()
            if ( isset( $calc_data['q1_age'] ) && ! isset( $calc_data['age'] ) ) {
                $calc_data['age'] = $calc_data['q1_age'];
            }
            if ( isset( $calc_data['q1_sex'] ) && ! isset( $calc_data['gender'] ) ) {
                $calc_data['gender'] = $calc_data['q1_sex'];
            }

            // v0.23.0 — Removed server-side q3 → physicalActivity fallback.
            // The old map (a=0, b=1, c=2, d=3, e=5) disagreed with the JS
            // prefill in S1_TO_S3_DEFAULTS (e='4'), creating a 1-point bias
            // on a weight-1.0 metric whenever physicalActivity wasn't stored
            // in stage3_data but q3 was. The JS prefill (s1Prefill in
            // hdlv2-staged-form.js) is canonical and writes the prefilled
            // value into formData on render, so any client who saw the
            // wizard has physicalActivity in their stage3_data. Clients
            // who genuinely skipped will land here with physicalActivity
            // unset → calc treats it as null and omits from the weighted
            // total, same path as any other skipped field.

            // Filter 'skip' values to null so calculator ignores them
            foreach ( $calc_data as $k => $v ) {
                if ( $v === 'skip' ) $calc_data[ $k ] = null;
            }

            $age    = (int) ( $calc_data['age'] ?? 0 );
            $gender = $calc_data['gender'] ?? 'other';

            $result_data = HDLV2_Rate_Calculator::calculate_full( $age, $calc_data, $gender );

            $stage_data['server_result'] = $result_data;
            $extra_updates[ $data_col ]  = wp_json_encode( $stage_data );

            $this->create_draft_report( $progress, $result_data );
        }

        // Update completion + advance stage
        $next_stage = min( $stage + 1, 3 );
        $updates    = array_merge(
            array(
                $completed_col  => current_time( 'mysql' ),
                'current_stage' => max( (int) $progress->current_stage, $next_stage ),
            ),
            $extra_updates
        );

        // Store client email/name from Stage 1 data at the progress level
        if ( $stage === 1 ) {
            if ( ! empty( $stage_data['email'] ) && empty( $progress->client_email ) ) {
                $updates['client_email'] = sanitize_email( $stage_data['email'] );
            }
            if ( ! empty( $stage_data['name'] ) && empty( $progress->client_name ) ) {
                $updates['client_name'] = sanitize_text_field( $stage_data['name'] );
            }
        }

        global $wpdb;

        // Persist JSON stage_data column FIRST with explicit %s format. Doing
        // this in a separate UPDATE (rather than rolling it into the merged
        // $updates below) avoids a known wpdb quirk where the JSON-encoded
        // string for the stage*_data column was silently dropped — which left
        // server_result missing from stage3_data and made the consultation
        // header render "Rate: ?  Bio Age: ?  Age: ?".
        if ( ! empty( $extra_updates[ $data_col ] ) ) {
            $wpdb->update(
                $wpdb->prefix . 'hdlv2_form_progress',
                array( $data_col => $extra_updates[ $data_col ] ),
                array( 'id' => $progress->id ),
                array( '%s' ),
                array( '%d' )
            );
            if ( $wpdb->last_error ) {
                error_log( '[HDLV2] complete_stage stage_data UPDATE failed: ' . $wpdb->last_error );
            }
        }

        // Persist completion timestamp + current_stage advance + any non-JSON
        // extras (like client_email/name copied from Stage 1).
        $completion_updates = array_diff_key( $updates, array( $data_col => true ) );
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_form_progress',
            $completion_updates,
            array( 'id' => $progress->id )
        );
        if ( $wpdb->last_error ) {
            error_log( '[HDLV2] complete_stage completion UPDATE failed: ' . $wpdb->last_error );
        }

        // v0.19.2 — Safety-net: flip a linked widget_invite to 'completed' when
        // Stage 1 completes via this handler. The primary completion path is
        // rest_capture_lead() (widget → /audio/lead), which already calls
        // complete_invite(). This belt-and-braces covers the case where a
        // client came through the autologin redirect (pre-v0.19.2 behaviour)
        // and finished Stage 1 on the /assessment/ page instead of the widget,
        // leaving the invite stuck at 'opened'.
        //
        // Safe on the normal widget path too — by then the invite is already
        // 'completed' so the WHERE clause filters it out.
        if ( $stage === 1 ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}hdlv2_widget_invites
                 SET status = 'completed', completed_at = %s
                 WHERE client_email = %s
                   AND practitioner_id = %d
                   AND status = 'opened'",
                current_time( 'mysql' ),
                $progress->client_email,
                (int) $progress->practitioner_user_id
            ) );
        }

        // Stage 3: fire Claude draft-report generation server-side as a
        // non-blocking belt-and-braces. Front-end JS also calls /generate-report
        // explicitly. Two-layer dedup ensures the worker runs exactly once:
        //   (1) idempotency_key (this body field): both callers send the same
        //       'gen-{token}' key. The 5-minute wrap_ai cache returns the same
        //       response on the slower call without re-running the worker.
        //   (2) atomic claim at the top of rest_generate_report worker (DB-level
        //       UPDATE on the draft row's status column) — protects the ~30-60s
        //       race window during the Claude calls when the cached response
        //       hasn't been stored yet.
        // Together these guarantee single Claude burn + single Make.com webhook
        // fire + single client email per Stage 3 completion. Was double-firing
        // pre-v0.23.4 because no idempotency_key was being supplied.
        if ( $stage === 3 ) {
            wp_remote_post(
                rest_url( 'hdl-v2/v1/form/generate-report' ),
                array(
                    'body'     => wp_json_encode( array(
                        'token'           => $token,
                        'idempotency_key' => 'gen-' . $token,
                    ) ),
                    'headers'  => array( 'Content-Type' => 'application/json' ),
                    'timeout'  => 0.01,
                    'blocking' => false,
                )
            );
        }

        // Send stage completion email
        $this->send_stage_email( $stage, $progress, $stage_data, $result_data );

        $response = array(
            'success'       => true,
            'stage'         => $stage,
            'next_stage'    => $next_stage,
            'current_stage' => max( (int) $progress->current_stage, $next_stage ),
        );

        if ( $result_data ) {
            $response['result'] = $result_data;
        }

        // Include WHY data in Stage 2 response for on-screen display
        if ( $stage === 2 ) {
            $response['why_data'] = array(
                'distilled_why'    => $stage_data['distilled_why'] ?? '',
                'ai_reformulation' => $stage_data['ai_reformulation'] ?? '',
                'motivations'      => $stage_data['motivations'] ?? array(),
                'vision_text'      => $stage_data['vision_text'] ?? '',
            );
        }

        return rest_ensure_response( $response );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: GENERATE REPORT (after Stage 3)
    // ──────────────────────────────────────────────────────────────

    /**
     * Generate draft report via Claude AI + fire Make.com webhook.
     * Called by JS after Stage 3 completion returns the rate calculation.
     *
     * Body: { token }
     */
    public function rest_generate_report( $request ) {
        $params     = $request->get_json_params();
        $idem_scope = ( is_array( $params ) && ! empty( $params['token'] ) )
            ? 'tok:' . substr( hash( 'sha256', (string) $params['token'] ), 0, 16 )
            : 'anon';
        return HDLV2_Idempotency::wrap_ai( $request, $idem_scope, function () use ( $request, $params ) {
        $token  = $this->validate_token_from_body( $params );
        if ( is_wp_error( $token ) ) return $token;

        $progress = $this->get_progress_by_token( $token );
        if ( ! $progress ) {
            return new WP_Error( 'invalid_token', 'Assessment not found.', array( 'status' => 404 ) );
        }

        if ( empty( $progress->stage3_completed_at ) ) {
            return new WP_Error( 'stage3_incomplete', 'Stage 3 must be completed first.', array( 'status' => 400 ) );
        }

        // v0.23.4 — Atomic claim guard. The wrap_ai cache only kicks in AFTER
        // a worker finishes (5-minute TTL on the response). During the ~30-60s
        // Claude burn, a concurrent caller would not see the cache and would
        // start its own worker — firing the Make.com webhook twice and sending
        // duplicate client emails (root cause of the duplicate email Matthew
        // reported 2026-04-29).
        //
        // Fix: a MySQL UPDATE acting as an atomic compare-and-swap on the
        // draft row's status column. create_draft_report inserts the row with
        // status='generating' from rest_complete_stage. The first /generate-
        // report worker to hit this UPDATE flips status to a unique claim
        // token (format: 'claimed-{epoch}-{rand}'); the second one sees 0
        // affected rows and returns either the 'ready' content (if the first
        // worker already finished) or an 'in progress' response (the frontend
        // redirects to the polling page either way, so the in-progress branch
        // is benign).
        global $wpdb;
        $reports_table = $wpdb->prefix . 'hdlv2_reports';
        $claim         = 'claimed-' . time() . '-' . wp_generate_password( 8, false );

        // Stale-claim recovery: if a previous worker died mid-Claude (PHP
        // fatal, OOM, server restart) the row could be stuck on a 'claimed-'
        // status forever, blocking all future /generate-report calls. Reset
        // claims older than 5 minutes back to 'generating' so the atomic
        // claim below can re-acquire. SUBSTRING(status, 9, 10) extracts the
        // 10-char unix timestamp from 'claimed-1714387200-abc123de'.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$reports_table}
                SET status = %s
              WHERE form_progress_id = %d
                AND report_type = %s
                AND status LIKE 'claimed-%%'
                AND CAST(SUBSTRING(status, 9, 10) AS UNSIGNED) < (UNIX_TIMESTAMP() - 300)",
            'generating', (int) $progress->id, 'draft'
        ) );

        $claimed_rows = $wpdb->query( $wpdb->prepare(
            "UPDATE {$reports_table}
                SET status = %s
              WHERE form_progress_id = %d
                AND report_type = %s
                AND status = %s",
            $claim, (int) $progress->id, 'draft', 'generating'
        ) );

        if ( $claimed_rows === 0 ) {
            // Either another worker has the claim, or the row is already
            // 'ready', or no draft row exists yet (rare — create_draft_report
            // would have been called from /complete-stage; the UPSERT below
            // self-heals if so).
            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, status, report_content FROM {$reports_table}
                  WHERE form_progress_id = %d AND report_type = %s
                  ORDER BY id DESC LIMIT 1",
                (int) $progress->id, 'draft'
            ) );

            if ( $existing && $existing->status === 'ready' ) {
                // Already generated by another worker — return cached content
                // without re-firing the webhook or re-charging Claude.
                $cached = json_decode( $existing->report_content, true ) ?: array();
                return rest_ensure_response( array(
                    'success'         => true,
                    'awaken_content'  => $cached['awaken_content']  ?? '',
                    'lift_content'    => $cached['lift_content']    ?? '',
                    'thrive_content'  => $cached['thrive_content']  ?? '',
                    'replayed'        => true,
                ) );
            }

            if ( $existing && strpos( (string) $existing->status, 'claimed-' ) === 0 ) {
                // Another worker is currently generating. The frontend always
                // redirects to /longevity-draft-report/?t=… which polls
                // /reports/draft for status — by the time the polling lands,
                // the other worker will have set status='ready'. Return a
                // benign "in progress" payload.
                return rest_ensure_response( array(
                    'success'         => false,
                    'in_progress'     => true,
                    'message'         => 'Report generation already in progress.',
                ) );
            }
            // Else fall through — the UPSERT at the bottom of this function
            // will create or update the row. No claim was required because
            // there was nothing to claim.
        }

        $s1_data    = json_decode( $progress->stage1_data, true ) ?: array();
        $s3_data    = json_decode( $progress->stage3_data, true ) ?: array();
        $calc_result = $s3_data['server_result'] ?? array();

        // Load WHY profile — includes richer fields stored by extract_why() v0.19.0
        global $wpdb;
        $why_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT distilled_why, ai_reformulation, key_people, motivations, fears, vision_text, raw_input
             FROM {$wpdb->prefix}hdlv2_why_profiles WHERE form_progress_id = %d LIMIT 1",
            $progress->id
        ), ARRAY_A );
        $why_profile = $why_row ?: array( 'distilled_why' => '', 'ai_reformulation' => '' );
        // Decode JSON columns for Claude prompt consumption
        if ( ! empty( $why_profile['key_people'] ) )  $why_profile['key_people']  = json_decode( $why_profile['key_people'],  true ) ?: array();
        if ( ! empty( $why_profile['motivations'] ) ) $why_profile['motivations'] = json_decode( $why_profile['motivations'], true ) ?: array();
        if ( ! empty( $why_profile['fears'] ) )       $why_profile['fears']       = json_decode( $why_profile['fears'],       true ) ?: array();
        // Pull verbatim_quotes + life_context from raw_input bundle (they live there, not in dedicated columns)
        $raw_bundle = ! empty( $why_profile['raw_input'] ) ? ( json_decode( $why_profile['raw_input'], true ) ?: array() ) : array();
        $why_profile['verbatim_quotes'] = $raw_bundle['verbatim_quotes'] ?? array();
        $why_profile['life_context']    = $raw_bundle['life_context']    ?? array();

        // Generate report via Claude AI
        // v0.38.0 — Pass $s3_data so the new Section 6 (family history /
        // medications / existing conditions) flows into the AI prompt.
        $report = HDLV2_AI_Service::generate_draft_report(
            $calc_result,
            $s1_data,
            $why_profile,
            $progress->client_name ?: '',
            $s3_data
        );

        // Generate AI-suggested milestones
        $milestones = HDLV2_AI_Service::generate_milestones(
            $calc_result,
            $why_profile,
            array(), // no practitioner recommendations yet (draft)
            $s1_data['q1_age'] ?? $s1_data['age'] ?? 0
        );

        // Phase 5 — Interactive client draft narrative (5 structured sections)
        // Runs alongside awaken/lift/thrive (which feeds Make.com PDF). Keeps
        // both shapes in report_content. Non-blocking: null on failure so the
        // existing PDF pipeline is never broken by narrative issues.
        $ai_narrative = HDLV2_AI_Service::generate_client_draft_narrative(
            $calc_result,
            $s1_data,
            $why_profile,
            $progress->client_name ?: ''
        );

        // Merge AI sections (awaken/lift/thrive) INTO the existing report_content
        // (which create_draft_report seeded with the calc result — rate, bio_age,
        // scores, raw inputs). Without this merge, the AI UPDATE wiped the calc
        // data and the consultation page rendered "Rate: ?  Bio Age: ?".
        $existing_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT report_content FROM {$wpdb->prefix}hdlv2_reports WHERE form_progress_id = %d AND report_type = %s LIMIT 1",
            $progress->id, 'draft'
        ), ARRAY_A );
        $existing_content = ( $existing_row && ! empty( $existing_row['report_content'] ) )
            ? ( json_decode( $existing_row['report_content'], true ) ?: array() )
            : array();
        $merged_content = array_merge( $existing_content, $report );
        if ( is_array( $ai_narrative ) ) {
            $merged_content['ai_narrative'] = $ai_narrative;
        }

        // v0.22.55 — UPSERT (was UPDATE-only). The original code assumed
        // create_draft_report() had already inserted a row when /complete-stage
        // ran for Stage 3. If that insert failed silently for any reason
        // (observed on Matt2704_01 / progress 40 on STBY 2026-04-27 — Stage 3
        // completed cleanly but no row landed in wp_hdlv2_reports), the UPDATE
        // here matched 0 rows and the AI-generated content was discarded —
        // leaving the practitioner with no draft to consult on, no consultation
        // notes to record, no finalised report, and consequently no Flight
        // Plan generated. The downstream pipeline silently dies because every
        // step assumes the upstream row exists.
        //
        // Fix: INSERT-or-UPDATE. The endpoint now guarantees a row exists
        // after it succeeds, regardless of whether create_draft_report fired.
        // This is a self-heal — clients hitting /generate-report without a
        // pre-existing draft row will now get one created. Logged so we can
        // count silent-recovery cases without users noticing.
        $row_data = array(
            'report_content' => wp_json_encode( $merged_content ),
            'milestones'     => wp_json_encode( $milestones ),
            'status'         => 'ready',
        );

        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}hdlv2_reports WHERE form_progress_id = %d AND report_type = %s LIMIT 1",
            $progress->id, 'draft'
        ) );

        if ( $existing_id ) {
            $wpdb->update(
                $wpdb->prefix . 'hdlv2_reports',
                $row_data,
                array( 'id' => (int) $existing_id )
            );
        } else {
            error_log( sprintf( '[HDLV2] generate-report SELF-HEAL: no draft row for progress %d, inserting fresh.', $progress->id ) );
            $wpdb->insert(
                $wpdb->prefix . 'hdlv2_reports',
                array_merge( $row_data, array(
                    'client_user_id'       => $progress->client_user_id ?: null,
                    'practitioner_user_id' => $progress->practitioner_user_id ?: null,
                    'form_progress_id'     => $progress->id,
                    'report_type'          => 'draft',
                ) )
            );
        }

        // Fire Make.com webhook (non-blocking).
        // v0.23.6 — pass ai_narrative through so the draft PDF template can
        // render the encouraging 1-2 paragraph "Health Profile" blurb without
        // an extra Claude call. ai_narrative was generated above by
        // generate_client_draft_narrative and is already in scope.
        $this->fire_make_webhook( $progress, $calc_result, $s1_data, $report, $why_profile, $milestones, is_array( $ai_narrative ) ? $ai_narrative : array() );

        return rest_ensure_response( array(
            'success'         => true,
            'awaken_content'  => $report['awaken_content'],
            'lift_content'    => $report['lift_content'],
            'thrive_content'  => $report['thrive_content'],
        ) );
        } );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: CREATE FORM (practitioner-initiated)
    // ──────────────────────────────────────────────────────────────

    /**
     * Create a new form_progress entry for a client.
     * Body: { client_email, client_name, widget_invite_id (optional) }
     */
    public function rest_create_form( $request ) {
        $params       = $request->get_json_params();
        $client_email = sanitize_email( $params['client_email'] ?? '' );
        $client_name  = sanitize_text_field( $params['client_name'] ?? '' );
        $invite_id    = absint( $params['widget_invite_id'] ?? 0 ) ?: null;

        if ( ! $client_email || ! is_email( $client_email ) ) {
            return new WP_Error( 'invalid_email', 'A valid client email is required.', array( 'status' => 400 ) );
        }

        $practitioner_id = get_current_user_id();

        // Check if this client already has an active form for this practitioner.
        //
        // v0.41.16 — filter explicitly excludes soft-deleted rows. Re-inviting
        // a removed client must create a FRESH assessment, not silently restore
        // archived data. Per Matthew's policy (mirrors V1): restoration of
        // archived data requires admin contact + $89 fee, surfaced via the
        // Tools → V2 Restore admin page (HDLV2_Admin_Restore). Auto-restoring
        // here would defeat that paid recovery path.
        global $wpdb;
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, token FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE client_email = %s AND practitioner_user_id = %d
               AND deleted_at IS NULL
             LIMIT 1",
            $client_email,
            $practitioner_id
        ) );

        if ( $existing ) {
            return rest_ensure_response( array(
                'success' => true,
                'token'   => $existing->token,
                'url'     => site_url( '/assessment/?token=' . $existing->token ),
                'existing' => true,
            ) );
        }

        $token = bin2hex( random_bytes( 32 ) );

        $insert_data   = array(
            'practitioner_user_id' => $practitioner_id,
            'client_name'          => $client_name,
            'client_email'         => $client_email,
            'token'                => $token,
            'current_stage'        => 1,
        );
        $insert_format = array( '%d', '%s', '%s', '%s', '%d' );

        if ( $invite_id !== null ) {
            $insert_data['widget_invite_id'] = $invite_id;
            $insert_format[]                 = '%d';
        }

        $wpdb->insert( $wpdb->prefix . 'hdlv2_form_progress', $insert_data, $insert_format );

        return rest_ensure_response( array(
            'success' => true,
            'token'   => $token,
            'url'     => site_url( '/assessment/?token=' . $token ),
            'existing' => false,
        ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  SHORTCODE
    // ──────────────────────────────────────────────────────────────

    /**
     * Shortcode: [hdlv2_assessment]
     *
     * Renders the staged assessment form. Reads ?token= from URL.
     * The actual form is loaded via JavaScript to support auto-save and
     * stage transitions without page reloads.
     */
    public function render_shortcode( $atts ) {
        wp_enqueue_script(
            'hdlv2-speedometer',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-speedometer.js',
            array(),
            HDLV2_VERSION,
            true
        );

        wp_enqueue_script(
            'hdlv2-audio-component',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-audio-component.js',
            array( 'hdlv2-transcriber' ),
            HDLV2_VERSION,
            true
        );

        wp_enqueue_script(
            'hdlv2-staged-form',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-staged-form.js',
            array( 'hdlv2-speedometer', 'hdlv2-audio-component', 'hdlv2-loading' ),
            HDLV2_VERSION,
            true
        );

        wp_enqueue_style(
            'hdlv2-form',
            HDLV2_PLUGIN_URL . 'assets/css/hdlv2-form.css',
            array( 'hdlv2-loading-css' ),
            HDLV2_VERSION
        );

        wp_localize_script( 'hdlv2-staged-form', 'hdlv2_form', array(
            'api_base'   => rest_url( 'hdl-v2/v1/form' ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'plugin_url' => HDLV2_PLUGIN_URL,
        ) );

        return '<div id="hdlv2-assessment" class="hdlv2-assessment-root"></div>';
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: RELEASE WHY GATE
    // ──────────────────────────────────────────────────────────────

    /**
     * Practitioner releases WHY profile → triggers Stage 3 invitation.
     *
     * Body: { progress_id: int }
     */
    public function rest_release_why( $request ) {
        $params      = $request->get_json_params();
        $progress_id = absint( $params['progress_id'] ?? 0 );
        if ( ! $progress_id ) {
            return new WP_Error( 'missing_id', 'Progress ID is required.', array( 'status' => 400 ) );
        }
        // v0.25.0 — delegate to shared helper (B7 dedup). REST + AJAX
        // handlers route through HDLV2_Compatibility::advance_to_stage_3.
        $result = HDLV2_Compatibility::advance_to_stage_3( $progress_id, get_current_user_id() );
        if ( ! $result['ok'] ) {
            return new WP_Error( $result['code'], $result['message'], array( 'status' => $result['status'] ?? 400 ) );
        }
        if ( ! empty( $result['already_released'] ) ) {
            return rest_ensure_response( array( 'success' => true, 'already_released' => true ) );
        }
        return rest_ensure_response( array( 'success' => true, 'released' => true ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: STAGE 2 CALLBACK (Make.com → WordPress)
    // ──────────────────────────────────────────────────────────────

    /**
     * Receive extracted WHY profile from Make.com after Stage 2 processing.
     * Creates the WHY profile row and marks stage2_completed_at.
     * Does NOT advance current_stage — that's the practitioner's job via release-why.
     *
     * Body: { token, key_people: [], key_motivations: [], key_fears: [],
     *         distilled_why: string, ai_reformulation: string }
     * Auth: Authorization: Bearer <HDLV2_MAKE_CALLBACK_SECRET>
     */
    public function rest_stage2_callback( $request ) {
        // Verify shared secret
        $secret = defined( 'HDLV2_MAKE_CALLBACK_SECRET' ) ? HDLV2_MAKE_CALLBACK_SECRET : '';
        $auth   = $request->get_header( 'authorization' );
        if ( empty( $secret ) || $auth !== 'Bearer ' . $secret ) {
            return new WP_Error( 'unauthorized', 'Invalid or missing authorization.', array( 'status' => 403 ) );
        }

        $params = $request->get_json_params();
        $token  = $this->validate_token_from_body( $params );
        if ( is_wp_error( $token ) ) return $token;

        $progress = $this->get_progress_by_token( $token );
        if ( ! $progress ) {
            return new WP_Error( 'invalid_token', 'Assessment not found.', array( 'status' => 404 ) );
        }

        // Guard: already past Stage 2
        if ( (int) $progress->current_stage >= 3 ) {
            return rest_ensure_response( array( 'success' => true, 'already_processed' => true ) );
        }

        // Pull existing stage2_data for vision_text and raw_input
        $stage2_data = json_decode( $progress->stage2_data, true ) ?: array();

        // Upsert WHY profile (same pattern as save_why_profile)
        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_why_profiles';

        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE form_progress_id = %d LIMIT 1",
            $progress->id
        ) );

        // v0.40.0 — Light punctuation polish on the raw transcript before
        // it's written to why_profiles. Item 9 PDF #5 (Matthew, 2026-05-08):
        // "Add commas and full stops only — no rewording, preserve voice
        // exactly. The current wall of unpunctuated text is hard to read."
        //
        // Polish only the version stored in why_profiles.vision_text —
        // PDFMonkey reads from here directly. The original raw transcript
        // is preserved in form_progress.stage2_data and in raw_input below.
        $vision_raw      = (string) ( $stage2_data['vision_text'] ?? '' );
        $vision_polished = HDLV2_AI_Service::polish_transcript( $vision_raw );

        $profile_data = array(
            'form_progress_id' => $progress->id,
            'client_user_id'   => $progress->client_user_id ?: null,
            'key_people'       => wp_json_encode( $params['key_people'] ?? array() ),
            'motivations'      => wp_json_encode( $params['key_motivations'] ?? array() ),
            'fears'            => wp_json_encode( $params['key_fears'] ?? array() ),
            'vision_text'      => sanitize_textarea_field( $vision_polished ),
            'distilled_why'    => sanitize_textarea_field( $params['distilled_why'] ?? '' ),
            'ai_reformulation' => sanitize_textarea_field( $params['ai_reformulation'] ?? '' ),
            'raw_input'        => wp_json_encode( $stage2_data ),
            'released'         => 0,
        );

        if ( $existing_id ) {
            $wpdb->update( $table, $profile_data, array( 'id' => $existing_id ) );
        } else {
            $wpdb->insert( $table, $profile_data );
        }

        // Mark Stage 2 as completed — but do NOT advance current_stage
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_form_progress',
            array( 'stage2_completed_at' => current_time( 'mysql' ) ),
            array( 'id' => $progress->id ),
            array( '%s' ),
            array( '%d' )
        );

        return rest_ensure_response( array( 'success' => true ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  VALIDATION
    // ──────────────────────────────────────────────────────────────

    /**
     * Validate required fields per stage.
     *
     * @param int   $stage      Stage number (1, 2, 3).
     * @param array $stage_data The stage's JSON data.
     * @return true|WP_Error
     */
    private function validate_stage_data( $stage, $stage_data ) {
        $missing = array();

        if ( $stage === 1 ) {
            // V2: 9-question format — q1_age, q1_sex, q2a, q2b, q3-q9
            $required = array( 'q1_age', 'q1_sex', 'q2a', 'q2b', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8', 'q9' );
            foreach ( $required as $field ) {
                if ( ! isset( $stage_data[ $field ] ) || $stage_data[ $field ] === '' ) {
                    $missing[] = $field;
                }
            }
        }

        if ( $stage === 2 ) {
            $vision = trim( $stage_data['vision_text'] ?? '' );
            if ( strlen( $vision ) < 10 ) {
                $missing[] = 'vision_text';
            }
        }

        if ( $stage === 3 ) {
            // Stage 3 allows "skip" on all fields — no required fields.
            // Fields with value "skip" or non-empty are considered present.
            // We only reject completely empty submissions (no data at all).
            $has_any_data = false;
            foreach ( $stage_data as $k => $v ) {
                if ( $v !== '' && $v !== null && $k !== 'server_result' ) {
                    $has_any_data = true;
                    break;
                }
            }
            if ( ! $has_any_data ) {
                $missing[] = 'stage3_data';
            }

            // No range checks — skipped fields are allowed.
        }

        if ( ! empty( $missing ) ) {
            return new WP_Error(
                'missing_fields',
                'Required fields missing: ' . implode( ', ', $missing ),
                array( 'status' => 400, 'missing' => $missing )
            );
        }

        return true;
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: STAGE 1 "WHAT THIS MEANS FOR YOU" COMMENTARY  (v0.22.0)
    //
    //  Body: { token: <64-hex> }
    //  Returns: { success: true, commentary_html: "<p>...</p>...", cached: false }
    //
    //  v0.22.10 — flipped from Haiku to deterministic static builder.
    //  Static commentary always succeeds, so `success` is always true and
    //  `cached` is always false (recompute is microsecond, no DB write).
    // ──────────────────────────────────────────────────────────────

    public function rest_stage1_commentary( $request ) {
        $params = $request->get_json_params();
        $token  = $this->validate_token_from_body( $params );
        if ( is_wp_error( $token ) ) return $token;

        $progress = $this->get_progress_by_token( $token );
        if ( ! $progress ) {
            return new WP_Error( 'invalid_token', 'Assessment not found.', array( 'status' => 404 ) );
        }

        if ( empty( $progress->stage1_completed_at ) ) {
            return new WP_Error( 'stage1_incomplete', 'Stage 1 must be completed first.', array( 'status' => 400 ) );
        }

        $stage1_data = json_decode( $progress->stage1_data, true ) ?: array();
        $result      = $stage1_data['server_result'] ?? array();
        $client_name = $progress->client_name ?: ( $stage1_data['name'] ?? '' );

        $html = HDLV2_Stage1_Commentary::build( $stage1_data, $result, $client_name );

        return rest_ensure_response( array(
            'success'         => true,
            'commentary_html' => $html,
            'cached'          => false,
        ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: STAGE 2 IMMEDIATE INSIGHT  (v0.22.2)
    //
    //  Body: { token: <64-hex> }
    //  Returns: { success, distilled_why, ai_reformulation, motivations[], cached }
    //  Read from stage2_data if cached, else generates via Haiku and writes back.
    //  Make.com's later release-WHY extraction may overwrite these fields with
    //  richer data — that's intentional and fine.
    // ──────────────────────────────────────────────────────────────

    public function rest_stage2_insight( $request ) {
        $params = $request->get_json_params();
        $token  = $this->validate_token_from_body( $params );
        if ( is_wp_error( $token ) ) return $token;

        $progress = $this->get_progress_by_token( $token );
        if ( ! $progress ) {
            return new WP_Error( 'invalid_token', 'Assessment not found.', array( 'status' => 404 ) );
        }

        $stage2_data = json_decode( $progress->stage2_data, true ) ?: array();
        $vision      = trim( (string) ( $stage2_data['vision_text'] ?? '' ) );

        if ( strlen( $vision ) < 10 ) {
            return new WP_Error( 'no_vision', 'No WHY text found for this token. Submit Stage 2 first.', array( 'status' => 400 ) );
        }

        $client_name = $progress->client_name ?: ( $stage2_data['name'] ?? '' );

        // v0.22.52 — STATUS-AWARE response. The endpoint now reports an
        // explicit `extraction_status` field so the frontend can tell the
        // difference between (a) real AI distillation has happened and is
        // safe to display, and (b) extraction is still pending and the
        // frontend should render an honest waiting state + poll for updates.
        //
        // Why this changed: the previous behaviour (v0.22.12) silently
        // substituted a static fallback string into `distilled_why` when
        // the why_profiles row was empty. The frontend rendered that
        // fallback as a blockquote, making it visually indistinguishable
        // from a real Make.com / Anthropic extraction. Users saw "your
        // distilled WHY will appear here once your practitioner has
        // reviewed it" displayed as if it were the real distillation —
        // misleading on multiple levels (the practitioner isn't the gate;
        // the AI extraction completing is).
        //
        // Priority chain (unchanged):
        //   1. hdlv2_why_profiles row — canonical extraction written by
        //      Make.com via /form/stage2-callback on Stage 2 submit.
        //   2. stage2_data.audio_summary — client opt-in extraction from
        //      the Stage 2 form's "Extract Themes with AI" button.
        //   3. Status flips to "pending" — frontend handles waiting state.
        //
        // The static text fallback (HDLV2_Stage2_Insight::build) is no
        // longer substituted into the response. The class is preserved
        // for any future caller that wants the deterministic text but
        // the frontend now treats "pending" as a first-class state.

        $distilled_why       = '';
        $ai_reformulation    = '';
        $motivations         = array();
        $has_real_extraction = false;

        global $wpdb;
        $why_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT distilled_why, ai_reformulation, motivations
             FROM {$wpdb->prefix}hdlv2_why_profiles
             WHERE form_progress_id = %d LIMIT 1",
            $progress->id
        ) );

        if ( $why_row ) {
            if ( ! empty( $why_row->distilled_why ) ) {
                $distilled_why       = (string) $why_row->distilled_why;
                $has_real_extraction = true;
            }
            if ( ! empty( $why_row->ai_reformulation ) ) {
                $ai_reformulation    = (string) $why_row->ai_reformulation;
                $has_real_extraction = true;
            }
            if ( ! empty( $why_row->motivations ) ) {
                $decoded = json_decode( (string) $why_row->motivations, true );
                if ( is_array( $decoded ) ) {
                    $motivations = self::normalize_motivations( $decoded );
                    if ( ! empty( $motivations ) ) {
                        $has_real_extraction = true;
                    }
                }
            }
        }

        // Fall back to client opt-in audio_summary for any field still empty.
        if ( ! $distilled_why || empty( $motivations ) ) {
            $audio_summary = $stage2_data['audio_summary'] ?? null;
            if ( is_string( $audio_summary ) ) {
                $audio_summary = json_decode( $audio_summary, true );
            }
            if ( is_array( $audio_summary ) ) {
                if ( ! $distilled_why && ! empty( $audio_summary['distilled_why'] ) ) {
                    $distilled_why       = (string) $audio_summary['distilled_why'];
                    $has_real_extraction = true;
                }
                if ( empty( $motivations ) && ! empty( $audio_summary['motivations'] ) && is_array( $audio_summary['motivations'] ) ) {
                    $motivations = self::normalize_motivations( $audio_summary['motivations'] );
                    if ( ! empty( $motivations ) ) {
                        $has_real_extraction = true;
                    }
                }
            }
        }

        // v0.22.52 — explicit status flag. Frontend uses this to decide
        // whether to render real content OR an honest waiting state with
        // polling. Pending → empty strings + polling. Real → swap in.
        $extraction_status = $has_real_extraction ? 'real' : 'pending';

        return rest_ensure_response( array(
            'success'           => true,
            'extraction_status' => $extraction_status,
            'distilled_why'     => $distilled_why,
            'ai_reformulation'  => $ai_reformulation,
            'motivations'       => $motivations,
            'vision_text'       => $vision,
            'cached'            => false,
        ) );
    }

    /**
     * Coerce motivations into a flat array of short clean strings.
     * Both why_profiles and audio_summary store motivations as an array,
     * but entries may be plain strings OR objects with a "text"/"label"
     * key depending on which extraction path produced them. This helper
     * smooths that out so the JS chip renderer always receives strings.
     */
    private static function normalize_motivations( $list ) {
        $out = array();
        foreach ( (array) $list as $item ) {
            if ( is_string( $item ) ) {
                $clean = sanitize_text_field( $item );
            } elseif ( is_array( $item ) ) {
                $clean = sanitize_text_field( (string) ( $item['text'] ?? $item['label'] ?? $item['motivation'] ?? '' ) );
            } else {
                continue;
            }
            if ( $clean !== '' ) {
                $out[] = $clean;
            }
        }
        return array_values( $out );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: STAGE 3 IMMEDIATE COMMENTARY  (v0.22.4)
    //
    //  Body: { token: <64-hex> }
    //  Returns: { success, commentary_html, cached }
    //  Cached in stage3_data.commentary_html so refreshes are free.
    //  Falls back to a deterministic paragraph if Haiku fails so the
    //  result page never renders blank.
    // ──────────────────────────────────────────────────────────────

    public function rest_stage3_commentary( $request ) {
        $params = $request->get_json_params();
        $token  = $this->validate_token_from_body( $params );
        if ( is_wp_error( $token ) ) return $token;

        $progress = $this->get_progress_by_token( $token );
        if ( ! $progress ) {
            return new WP_Error( 'invalid_token', 'Assessment not found.', array( 'status' => 404 ) );
        }
        if ( empty( $progress->stage3_completed_at ) ) {
            return new WP_Error( 'stage3_incomplete', 'Stage 3 must be completed first.', array( 'status' => 400 ) );
        }

        $stage3_data = json_decode( $progress->stage3_data, true ) ?: array();

        if ( ! empty( $stage3_data['commentary_html'] ) ) {
            return rest_ensure_response( array(
                'success'         => true,
                'commentary_html' => $stage3_data['commentary_html'],
                'cached'          => true,
            ) );
        }

        $stage1_data = json_decode( $progress->stage1_data, true ) ?: array();
        $stage2_data = json_decode( $progress->stage2_data, true ) ?: array();
        $result      = $stage3_data['server_result'] ?? array();
        $client_name = $progress->client_name ?: ( $stage1_data['name'] ?? '' );

        $html = HDLV2_AI_Service::generate_stage3_commentary( $stage1_data, $stage2_data, $stage3_data, $result, $client_name );

        if ( ! $html ) {
            // Deterministic fallback — page never blanks even when API is down.
            $rate = (float) ( $result['rate'] ?? 1.0 );
            $age  = (int)   ( $stage1_data['q1_age'] ?? 0 );
            $bio  = (float) ( $result['bio_age'] ?? ( $age * $rate ) );
            $name = $client_name ? esc_html( strtok( $client_name, ' ' ) ) . ', ' : '';
            $verdict = ( $rate <= 0.95 ) ? 'slower than the average pace'
                     : ( ( $rate <= 1.05 ) ? 'roughly on pace with the population average'
                                            : 'faster than the average pace' );

            $fallback = sprintf(
                '<p><strong>%syour full Stage 3 measurements put your rate of ageing at %s× — %s.</strong> Biological age estimate: %s years against your real age of %d.</p>'
                . '<p>The 21-metric panel gives your practitioner the detail they need to build a precise plan with you.</p>'
                . '<p>Your draft Longevity Report is being generated now. Your practitioner will review it, then walk you through it in your consultation.</p>',
                $name,
                number_format( $rate, 2 ),
                esc_html( $verdict ),
                number_format( $bio, 1 ),
                $age
            );

            return rest_ensure_response( array(
                'success'         => false,
                'commentary_html' => $fallback,
                'cached'          => false,
                'fallback'        => true,
            ) );
        }

        global $wpdb;
        $stage3_data['commentary_html'] = $html;
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_form_progress',
            array( 'stage3_data' => wp_json_encode( $stage3_data ) ),
            array( 'id' => $progress->id ),
            array( '%s' ),
            array( '%d' )
        );

        return rest_ensure_response( array(
            'success'         => true,
            'commentary_html' => $html,
            'cached'          => false,
        ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  EMAIL TRIGGERS
    // ──────────────────────────────────────────────────────────────

    /**
     * Send email on stage completion.
     */
    private function send_stage_email( $stage, $progress, $stage_data, $result_data ) {
        $client_email = $progress->client_email ?: ( $stage_data['email'] ?? '' );
        $client_name  = $progress->client_name ?: ( $stage_data['name'] ?? '' );
        $token_url    = site_url( '/assessment/?token=' . $progress->token );
        $html_headers = array( 'Content-Type: text/html; charset=UTF-8' );

        // ── Stage 1: Quick Insight ──
        if ( $stage === 1 ) {
            $rate    = $result_data['rate'] ?? 1.0;
            $age     = $stage_data['q1_age'] ?? $stage_data['age'] ?? '?';
            $bio_age = $result_data['bio_age'] ?? $age;

            // v0.36.23 — Skip the WP-side Stage 1 client email when the
            // Make.com Stage 1 PDF scenario is configured (LIVE). Make.com
            // Module 41 sends the branded "Your Stage 1 Longevity Report"
            // email with the PDF attached, which is the canonical version.
            // Firing this link-only mail in parallel produces two emails
            // for the same submission (matches the v0.36.22 Stage 2 fix
            // and the existing draft-report gate at line ~1407). STBY
            // doesn't define the constant so STBY still gets *some*
            // notification via WP.
            $skip_client_email = defined( 'HDLV2_MAKE_STAGE1_PDF' )
                                 && (string) HDLV2_MAKE_STAGE1_PDF !== '';
            if ( $client_email && ! $skip_client_email ) {
                $html = HDLV2_Email_Templates::stage1_results( array(
                    'client_name'     => $client_name,
                    'client_email'    => $client_email,
                    'rate'            => $rate,
                    'bio_age'         => $bio_age,
                    'age'             => $age,
                    'gauge_url'       => self::build_gauge_url( $rate ),
                    'token_url'       => $token_url,
                    'rate_message'    => $this->rate_message( $rate, $bio_age, $age ),
                    'practitioner_id' => $progress->practitioner_user_id ?? null,
                ) );
                wp_mail( $client_email, 'Your Quick Health Insight — HealthDataLab', $html, $html_headers );
            }

            // Practitioner notification stays — Make.com Module 40 fires its
            // own "New Lead" copy, but the WP one carries the dashboard
            // deep-link which Module 40 doesn't include. Different purposes.
            $this->notify_practitioner_html( $progress,
                HDLV2_Email_Templates::stage1_results( array(
                    'client_name'     => $client_name,
                    'client_email'    => $client_email,
                    'rate'            => $rate,
                    'bio_age'         => $bio_age,
                    'age'             => $age,
                    'gauge_url'       => self::build_gauge_url( $rate ),
                    'token_url'       => $token_url,
                    'rate_message'    => $this->rate_message( $rate, $bio_age, $age ),
                    'practitioner_id' => $progress->practitioner_user_id ?? null,
                ) ),
                sprintf( 'Stage 1 Complete: %s', $client_name ?: $client_email )
            );
        }

        // ── Stage 2: WHY Profile (gate: practitioner must release before Stage 3) ──
        if ( $stage === 2 ) {
            // v0.36.22 — Client confirmation email is sent by Make.com (Module 8
            // BasicRouter → Sub-route 1) using the branded "Answers Stage 2: Your Why"
            // template. The previous WP `stage2_saved()` fire was a pre-Make.com
            // legacy and produced a duplicate inbox arrival ~45s before the
            // Make.com email. Removed entirely (function deleted in
            // class-hdlv2-email-templates.php) so no dead code remains.

            // Practitioner: HTML email with distilled WHY + one-click CTA into
            // the dashboard at this client's row (release_progress_id deep-link).
            // v0.40.7 — URL now includes prac_login=<one-shot 64-hex token>
            // so the practitioner auto-logs-in regardless of prior session
            // state. The /clients/ slug (was /client-dashboard/ which 404s)
            // is the real practitioner dashboard page on STBY + LIVE. The
            // token + cookie write happens in the init handler at
            // hdl-longevity-v2.php (see ?prac_login= block, after ?invite=).
            $dashboard_url = self::build_practitioner_release_url(
                (int) $progress->practitioner_user_id,
                (int) $progress->id
            );

            $this->notify_practitioner_html( $progress,
                HDLV2_Email_Templates::stage2_awaiting_release( array(
                    'client_name'     => $client_name,
                    'client_email'    => $client_email,
                    'distilled_why'   => $stage_data['distilled_why'] ?? '',
                    'dashboard_url'   => $dashboard_url,
                    'practitioner_id' => $progress->practitioner_user_id ?? null,
                ) ),
                sprintf( '%s is ready to invite to Stage 3', $client_name ?: $client_email )
            );
        }

        // ── Stage 3: Assessment Complete ──
        if ( $stage === 3 ) {
            $rate    = $result_data['rate'] ?? 'N/A';
            $bio_age = $result_data['bio_age'] ?? 'N/A';
            $s1d     = json_decode( $progress->stage1_data, true ) ?: array();
            $age     = $s1d['q1_age'] ?? $s1d['age'] ?? '?';
            $scores  = $result_data['scores'] ?? array();

            // v0.23.5 — replaced positive/negative score-key text lists with
            // the same 21-metric radar + trajectory charts the practitioner
            // sees on the consultation page (Matthew 2026-04-29 B3 spec).
            // Both helpers are public static and already used by Final Report.
            $radar_chart_url      = ( class_exists( 'HDLV2_Final_Report' ) && is_array( $scores ) && ! empty( $scores ) )
                ? HDLV2_Final_Report::build_radar_chart_url( $scores )
                : '';
            $trajectory_chart_url = ( class_exists( 'HDLV2_Trajectory_SVG' ) && is_numeric( $age ) && is_numeric( $rate ) )
                ? HDLV2_Trajectory_SVG::url_for( (int) $age, (float) $rate )
                : '';

            // v0.36.11 — gate the link-only email when the Make.com Draft
            // Report scenario is configured (HDLV2_MAKE_DRAFT_REPORT). In that
            // configuration, Make.com generates a PDF via PDFMonkey and emails
            // it to the client; firing this link-only mail in parallel produced
            // two emails for the same draft (matches the earlier Final Report
            // duplicate-email pattern fixed in v0.34.x). When the constant is
            // absent or empty (e.g. STBY which intentionally has no Make.com),
            // we still fire the link-only email so the client gets *some*
            // notification.
            $skip_link_email = defined( 'HDLV2_MAKE_DRAFT_REPORT' )
                               && (string) HDLV2_MAKE_DRAFT_REPORT !== '';
            if ( $client_email && ! $skip_link_email ) {
                // v0.39.0 — link points back to /assessment/?token= so the
                // Thank-You page renders (replacing the deprecated heavy
                // browser draft view at /longevity-draft-report/). Make.com
                // Module 63 still delivers the canonical email + PDF on LIVE;
                // this WP fallback only fires on STBY where Make.com is absent.
                $draft_url = site_url( '/assessment/?token=' . rawurlencode( (string) $progress->token ) );
                $html = HDLV2_Email_Templates::stage3_complete( array(
                    'client_name'     => $client_name,
                    'client_email'    => $client_email,
                    'practitioner_id' => $progress->practitioner_user_id ?? null,
                    'draft_url'       => $draft_url,
                ) );
                wp_mail( $client_email, 'Your Draft Longevity Report is Ready', $html,
                    array( 'Content-Type: text/html; charset=UTF-8' ) );
            }

            $this->notify_practitioner_html( $progress,
                HDLV2_Email_Templates::stage3_draft_ready( array(
                    'client_name'          => $client_name,
                    'client_email'         => $client_email,
                    'rate'                 => $rate,
                    'bio_age'              => $bio_age,
                    'age'                  => $age,
                    'radar_chart_url'      => $radar_chart_url,
                    'trajectory_chart_url' => $trajectory_chart_url,
                    'practitioner_id'      => $progress->practitioner_user_id ?? null,
                ) ),
                sprintf( 'Draft Report Ready: %s', $client_name ?: $client_email )
            );
        }
    }

    private function notify_practitioner( $progress, $body, $subject ) {
        if ( ! $progress->practitioner_user_id ) return;
        $prac_user  = get_userdata( $progress->practitioner_user_id );
        $prac_email = $prac_user ? $prac_user->user_email : '';
        if ( $prac_email ) {
            wp_mail( $prac_email, $subject, $body );
        }
    }

    private function notify_practitioner_html( $progress, $html, $subject ) {
        if ( ! $progress->practitioner_user_id ) return;
        $prac_user  = get_userdata( $progress->practitioner_user_id );
        $prac_email = $prac_user ? $prac_user->user_email : '';
        if ( $prac_email ) {
            wp_mail( $prac_email, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
        }
    }

    private function summarize_key_people( $kp ) {
        if ( ! is_array( $kp ) ) return '';
        $parts = array();
        foreach ( array( 'children' => 'Children', 'grandchildren' => 'Grandchildren', 'partner' => 'Partner', 'parents_alive' => 'Parents' ) as $key => $label ) {
            if ( isset( $kp[ $key ] ) && is_array( $kp[ $key ] ) && ! empty( $kp[ $key ]['has'] ) ) {
                $parts[] = $label;
            }
        }
        if ( ! empty( $kp['other'] ) ) $parts[] = $kp['other'];
        return implode( ', ', $parts );
    }

    private function rate_message( $rate, $bio_age, $age ) {
        $diff = abs( $bio_age - $age );
        $diff_str = number_format( $diff, 1 );
        if ( $rate <= 0.95 ) return "Your biological age is $diff_str years younger than your chronological age. Your lifestyle factors are working in your favour.";
        if ( $rate <= 1.05 ) return "Your biological age is roughly in line with your chronological age. Small changes could shift this significantly.";
        return "Your biological age is $diff_str years older than your chronological age. Targeted changes can bring this down.";
    }

    /**
     * Build QuickChart gauge URL — matches widget gauge exactly.
     */
    /**
     * Format milestone items (array of objects or strings) into a single text block.
     */
    public static function format_milestones( $items ) {
        if ( ! is_array( $items ) ) return is_string( $items ) ? $items : '';
        $texts = array();
        foreach ( $items as $item ) {
            $texts[] = is_array( $item ) ? ( $item['milestone'] ?? '' ) : $item;
        }
        return implode( "\n", array_filter( $texts ) );
    }

    /**
     * Build a QuickChart.io gauge URL for the rate of ageing.
     *
     * v0.23.0 — accepts $opts to widen bounds for Stage 3 / Final Report
     * contexts where the calc returns 0.5-2.0 instead of Stage 1's 0.8-1.4.
     * Without the wider bounds the needle pinned at 0.8 / 1.4 and silently
     * contradicted the rate text in the email/PDF next to it.
     *
     * @param float $rate Rate of ageing (any range).
     * @param array $opts {
     *   bool  stage3    True → 0.5-2.0 bounds (Final Report path).
     *   float minValue  Explicit override (wins over stage3 flag).
     *   float maxValue  Explicit override.
     * }
     */
    public static function build_gauge_url( $rate, $opts = array() ) {
        $opts     = is_array( $opts ) ? $opts : array();
        $stage3   = ! empty( $opts['stage3'] );
        $minValue = isset( $opts['minValue'] ) ? (float) $opts['minValue'] : ( $stage3 ? 0.5 : 0.8 );
        $maxValue = isset( $opts['maxValue'] ) ? (float) $opts['maxValue'] : ( $stage3 ? 2.0 : 1.4 );

        $clamped = max( $minValue, min( $maxValue, round( (float) $rate, 2 ) ) );

        // Band edges. For the legacy Stage 1 default we keep the historical
        // 0.9 / 1.1 split. For wider bounds the bands sit symmetrically around
        // 1.0 (slower≤0.95, average≤1.05) — same semantic, wider visual range.
        if ( $minValue === 0.8 && $maxValue === 1.4 ) {
            $slower_edge  = 0.9;
            $average_edge = 1.1;
        } else {
            $slower_edge  = max( $minValue + 0.05, 0.95 );
            $average_edge = min( $maxValue - 0.05, 1.05 );
        }

        // v0.32.0 — Three-zone warm-cool gradient aligned with HDL status palette.
        // Was green/blue-teal/orange — Matthew flagged the blue-teal middle band
        // as a graphic artefact that didn't correspond to any zone in the strip
        // below (second-pass review 2026-05-04). Now: green=optimal, amber=watch,
        // red=concern. Matches Page 16 stat pills, body-comp range bars, and
        // status palette in the brand guidelines.
        if ( $clamped <= $slower_edge ) {
            $interp = 'Slower';
            $interp_color = 'rgba(16, 185, 129, 1)';   // optimal
        } elseif ( $clamped <= $average_edge ) {
            $interp = 'Average';
            $interp_color = 'rgba(217, 119, 6, 1)';    // watch (amber)
        } else {
            $interp = 'Faster';
            $interp_color = 'rgba(220, 38, 38, 1)';    // concern (red)
        }

        $cfg = array(
            'type' => 'gauge',
            'data' => array(
                'labels'   => array( 'Slower', 'Average', 'Faster' ),
                'datasets' => array( array(
                    'data'            => array( $slower_edge, $average_edge, $maxValue ),
                    'value'           => $clamped,
                    'minValue'        => $minValue,
                    'maxValue'        => $maxValue,
                    'backgroundColor' => array(
                        'rgba(16,185,129,0.95)',   // optimal green (slower)
                        'rgba(217,119,6,0.95)',    // watch amber (average)
                        'rgba(220,38,38,0.95)',    // concern red (faster)
                    ),
                    'borderWidth'     => 1,
                    'borderColor'     => 'rgba(255,255,255,0.8)',
                    'borderRadius'    => 5,
                ) ),
            ),
            'options' => array(
                'layout'     => array( 'padding' => array( 'top' => 30, 'bottom' => 15, 'left' => 15, 'right' => 15 ) ),
                'needle'     => array( 'radiusPercentage' => 2.5, 'widthPercentage' => 4.0, 'lengthPercentage' => 68, 'color' => '#004F59', 'shadowColor' => 'rgba(0,79,89,0.4)', 'shadowBlur' => 8, 'shadowOffsetY' => 4, 'borderWidth' => 2, 'borderColor' => 'rgba(255,255,255,1.0)' ),
                // v0.28.10 — valueLabel + subtitle disabled. The PDFMonkey
                // template now renders the value below the gauge image (big
                // 0.96×) and the band labels (SLOWER / AVG / FASTER) as a
                // rotated SVG overlay above the dial, so the gauge image
                // itself shouldn't double-print either.
                'valueLabel' => array( 'display' => false, 'fontSize' => 36, 'fontFamily' => "'Inter',sans-serif", 'fontWeight' => 'bold', 'color' => '#004F59', 'backgroundColor' => 'transparent', 'bottomMarginPercentage' => -10, 'padding' => 8 ),
                'centerArea' => array( 'displayText' => false, 'backgroundColor' => 'transparent' ),
                'arc'        => array( 'borderWidth' => 0, 'padding' => 2, 'margin' => 3, 'roundedCorners' => true ),
                'subtitle'   => array( 'display' => false, 'text' => $interp, 'color' => $interp_color, 'font' => array( 'size' => 20, 'weight' => 'bold', 'family' => "'Inter',sans-serif" ), 'padding' => array( 'top' => 8 ) ),
            ),
        );

        return 'https://quickchart.io/chart?w=380&h=340&bkg=white&c=' . urlencode( wp_json_encode( $cfg ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  WHY PROFILE + DRAFT REPORT
    // ──────────────────────────────────────────────────────────────

    /**
     * Create or update the WHY profile from Stage 2 data.
     *
     * Prefers the richer extract_why() output (v0.19.0) which now returns
     * key_people/motivations/fears/verbatim_quotes/life_context arrays in
     * addition to distilled_why + ai_reformulation. Falls back to whatever
     * the widget stored in stage_data if extract_why didn't run or returned
     * a placeholder.
     */
    private function save_why_profile( $progress, $stage_data, $why_result = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_why_profiles';

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE form_progress_id = %d LIMIT 1",
            $progress->id
        ) );

        // Prefer extract_why() output over widget's stage_data (richer + more recent)
        $key_people  = ! empty( $why_result['key_people'] )
            ? $why_result['key_people']
            : ( $stage_data['key_people'] ?? array() );
        $motivations = ! empty( $why_result['motivations'] )
            ? $why_result['motivations']
            : ( $stage_data['motivations'] ?? array() );
        $fears       = ! empty( $why_result['fears'] )
            ? $why_result['fears']
            : array();

        // Stage richer fields under raw_input as JSON so we don't need new DB columns right now
        $raw_bundle = array(
            'stage_data'      => $stage_data,
            'verbatim_quotes' => $why_result['verbatim_quotes'] ?? array(),
            'life_context'    => $why_result['life_context']    ?? array(),
        );

        $profile_data = array(
            'form_progress_id' => $progress->id,
            'client_user_id'   => $progress->client_user_id ?: null,
            'key_people'       => wp_json_encode( $key_people ),
            'motivations'      => wp_json_encode( $motivations ),
            'fears'            => wp_json_encode( $fears ),
            'vision_text'      => sanitize_textarea_field( $stage_data['vision_text'] ?? '' ),
            'distilled_why'    => $why_result['distilled_why'] ?? '',
            'ai_reformulation' => $why_result['ai_reformulation'] ?? '',
            'raw_input'        => wp_json_encode( $raw_bundle ),
            'released'         => 0, // WHY gate: practitioner must release before Stage 3 invitation
        );

        if ( $existing ) {
            $wpdb->update( $table, $profile_data, array( 'id' => $existing ) );
        } else {
            $wpdb->insert( $table, $profile_data );
        }
    }

    /**
     * Create a draft report row after Stage 3 completion.
     *
     * v0.30.1 — idempotent. Backed by the UNIQUE KEY uniq_progress_report
     * (form_progress_id, report_type) added in DB v3.3. INSERT … ON DUPLICATE
     * KEY UPDATE so a second concurrent /complete-stage request can't create
     * a sibling draft row that the atomic claim in rest_generate_report would
     * miss. Together they guarantee one webhook fire per Stage 3 completion.
     *
     * On collision we keep the existing row's status untouched (the first
     * worker may have already claimed/finished it) and only refresh
     * report_content. created_at is preserved by MySQL.
     */
    private function create_draft_report( $progress, $result_data ) {
        global $wpdb;
        // NULLIF(client_user_id, 0) preserves the original NULL-when-empty
        // semantic (client_user_id is nullable; pre-fix code passed `?: null`).
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}hdlv2_reports
                (client_user_id, practitioner_user_id, form_progress_id, report_type, report_content, status)
             VALUES (NULLIF(%d, 0), %d, %d, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                report_content = VALUES(report_content),
                updated_at     = CURRENT_TIMESTAMP",
            (int) ( $progress->client_user_id ?: 0 ),
            (int) $progress->practitioner_user_id,
            (int) $progress->id,
            'draft',
            wp_json_encode( $result_data ),
            'generating'
        ) );
    }

    /**
     * Fire Make.com webhook with draft report payload.
     */
    private function fire_make_webhook( $progress, $calc_result, $s1_data, $report, $why_profile, $milestones = array(), $ai_narrative = array() ) {
        // v0.38.1 — Decode stage3_data once for Section 6 passthrough.
        // family_history / medications / existing_conditions get added to
        // the payload further down so Make.com Module 63 + PDFMonkey draft
        // template can reference them.
        $s3_data_for_payload = is_string( $progress->stage3_data ?? null )
            ? ( json_decode( $progress->stage3_data, true ) ?: array() )
            : ( is_array( $progress->stage3_data ?? null ) ? $progress->stage3_data : array() );
        // v0.30.1 — Belt-and-braces dedup. The atomic claim on the draft row
        // (rest_generate_report) and the unique-keyed draft row (Phase M)
        // already guarantee one fire per Stage 3 completion. This transient
        // is a third layer that catches anything we haven't anticipated:
        // a Make.com retry replaying a failed run, a future code path that
        // calls fire_make_webhook directly, or a manual practitioner
        // regenerate trigger landing within the 60s window.
        $dedup_key = 'hdlv2_draft_fired_' . (int) $progress->id;
        if ( get_transient( $dedup_key ) ) {
            error_log( sprintf( '[HDLV2] fire_make_webhook draft skipped — already fired within 60s for progress %d.', (int) $progress->id ) );
            return;
        }
        set_transient( $dedup_key, time(), 60 );

        $webhook_url = defined( 'HDLV2_MAKE_DRAFT_REPORT' ) ? HDLV2_MAKE_DRAFT_REPORT : '';

        $prac_user = $progress->practitioner_user_id ? get_userdata( $progress->practitioner_user_id ) : null;

        // v0.23.6 — derive new payload fields for the redesigned 2-page draft
        // PDF (Matthew C2 spec). The PDFMonkey draft template consumes these
        // four new fields to render the chart-text rows + page-2 footer card.
        $age_int     = (int) ( $s1_data['q1_age'] ?? $s1_data['age'] ?? 0 );
        $rate_num    = (float) ( $calc_result['rate'] ?? 1.0 );
        $bio_age_num = (float) ( $calc_result['bio_age'] ?? $age_int );
        $scores      = $calc_result['scores'] ?? array();

        // Charts — same renderers Final Report uses (single source of truth)
        $radar_chart_url      = ( class_exists( 'HDLV2_Final_Report' ) && ! empty( $scores ) )
            ? HDLV2_Final_Report::build_radar_chart_url( $scores )
            : '';
        $trajectory_chart_url = ( class_exists( 'HDLV2_Trajectory_SVG' ) && $age_int > 0 )
            ? HDLV2_Trajectory_SVG::url_for( $age_int, $rate_num )
            : '';

        // Rate-of-ageing blurb — reuse the same line from the Stage 1 email
        // per Matthew's spec: "It could be even be the same text that we had
        // in the very first email". rate_message() is the existing helper.
        $rate_message = $this->rate_message( $rate_num, $bio_age_num, $age_int );

        // Bio-vs-chrono diff text for the small line under "Your Pace of Ageing"
        $diff = round( (float) $age_int - $bio_age_num, 1 );
        if ( $diff > 0 ) {
            $bio_chrono_advantage = sprintf( '%s year advantage', $diff );
        } elseif ( $diff < 0 ) {
            $bio_chrono_advantage = sprintf( '%s years older than chronological', abs( $diff ) );
        } else {
            $bio_chrono_advantage = 'In line with chronological age';
        }

        // AI opening — encouraging 1-2 paragraph blurb. Already generated by
        // generate_client_draft_narrative during /generate-report (PHASE 5
        // v0.18.0); zero extra Claude burn — we just pass it through.
        $ai_opening = is_array( $ai_narrative ) && ! empty( $ai_narrative['opening'] )
            ? (string) $ai_narrative['opening']
            : '';

        // Practitioner initials for the page-2 footer avatar (e.g. "Bob 9000" → "B9").
        $practitioner_initials = 'HD';
        if ( $prac_user && $prac_user->display_name ) {
            $parts = preg_split( '/\s+/', trim( $prac_user->display_name ) );
            $letters = array();
            foreach ( $parts as $p ) {
                if ( $p !== '' ) $letters[] = strtoupper( mb_substr( $p, 0, 1 ) );
                if ( count( $letters ) === 2 ) break;
            }
            if ( ! empty( $letters ) ) $practitioner_initials = implode( '', $letters );
        }

        // v0.30.2 — Practitioner logo with HDL fallback baked in. Same helper +
        // pattern as Stage 1 PDF (widget-config.php:899). Premium draft template
        // renders <img src="{{ practitioner_logo_url }}"> for the page-2 footer
        // card; the helper guarantees a non-empty URL so an unset practitioner
        // logo doesn't show a broken-image icon.
        $prac_logo_url = ( $progress->practitioner_user_id && class_exists( 'HDLV2_Practitioner' ) )
            ? HDLV2_Practitioner::get_logo_url( (int) $progress->practitioner_user_id, true )
            : '';

        $payload = array(
            'client_name'              => $progress->client_name ?: '',
            'client_email'             => $progress->client_email ?: '',
            'practitioner_name'        => $prac_user ? $prac_user->display_name : '',
            'practitioner_email'       => $prac_user ? $prac_user->user_email : '',
            'practitioner_initials'    => $practitioner_initials,                        // v0.23.6
            'practitioner_logo_url'    => $prac_logo_url,                                // v0.30.2
            'rate_of_ageing'           => $calc_result['rate'] ?? null,
            'biological_age'           => $calc_result['bio_age'] ?? null,
            'chronological_age'        => $s1_data['q1_age'] ?? $s1_data['age'] ?? null,
            'bio_chrono_advantage'     => $bio_chrono_advantage,                         // v0.23.6
            // v0.23.6 — gauge bounds widened to 0.5-2.0 to match calculate_full's
            // range. Default 0.8-1.4 silently clamped extreme rates.
            'gauge_url'                => self::build_gauge_url( $rate_num, array( 'stage3' => true ) ),
            'radar_chart_url'          => $radar_chart_url,                              // v0.23.6
            'trajectory_chart_url'     => $trajectory_chart_url,                         // v0.23.6
            'rate_message'             => $rate_message,                                 // v0.23.6
            'ai_opening'               => $ai_opening,                                   // v0.23.6
            'report_date'              => current_time( 'j F Y' ),
            'report_type'              => 'draft',
            'awaken_content'           => $report['awaken_content'] ?? '',
            'lift_content'             => $report['lift_content'] ?? '',
            'thrive_content'           => $report['thrive_content'] ?? '',
            'why_profile'              => $why_profile['distilled_why'] ?? '',
            'health_scores'            => $calc_result['scores'] ?? array(),
            'score_bmi'         => $calc_result['scores']['bmiScore'] ?? '',
            'score_whr'         => $calc_result['scores']['whrScore'] ?? '',
            'score_whtr'        => $calc_result['scores']['whtrScore'] ?? '',
            // v0.23.1 — score_overall removed (Matthew 2026-04-28).
            'score_bp'          => $calc_result['scores']['bloodPressureScore'] ?? '',
            'score_hr'          => $calc_result['scores']['heartRateScore'] ?? '',
            // v0.32.0 — score_skin removed (Matthew second-pass 2026-05-05). Calculator still
            // computes skinElasticity but it's no longer rendered on the Draft/Final reports.
            'score_activity'    => $calc_result['scores']['physicalActivity'] ?? '',
            'score_sleep_dur'   => $calc_result['scores']['sleepDuration'] ?? '',
            'score_sleep_qual'  => $calc_result['scores']['sleepQuality'] ?? '',
            'score_stress'      => $calc_result['scores']['stressLevels'] ?? '',
            'score_social'      => $calc_result['scores']['socialConnections'] ?? '',
            'score_diet'        => $calc_result['scores']['dietQuality'] ?? '',
            'score_alcohol'     => $calc_result['scores']['alcoholConsumption'] ?? '',
            'score_smoking'     => $calc_result['scores']['smokingStatus'] ?? '',
            'score_cognitive'   => $calc_result['scores']['cognitiveActivity'] ?? '',
            'score_supplements' => $calc_result['scores']['supplementIntake'] ?? '',
            'score_sunlight'    => $calc_result['scores']['sunlightExposure'] ?? '',
            'score_hydration'   => $calc_result['scores']['dailyHydration'] ?? '',
            'score_sit_stand'   => $calc_result['scores']['sitToStand'] ?? '',
            'score_breath'      => $calc_result['scores']['breathHold'] ?? '',
            'score_balance'     => $calc_result['scores']['balance'] ?? '',
            'bmi'               => $calc_result['bmi'] ?? null,
            'whr'               => $calc_result['whr'] ?? null,
            'whtr'              => $calc_result['whtr'] ?? null,
            'generated_at'      => current_time( 'c' ),
            'milestones'        => $milestones,
            'ms_6mo'            => self::format_milestones( $milestones['six_months'] ?? array() ),
            'ms_2yr'            => self::format_milestones( $milestones['two_years'] ?? array() ),
            'ms_5yr'            => self::format_milestones( $milestones['five_years'] ?? array() ),
            'ms_10yr'           => self::format_milestones( $milestones['ten_plus_years'] ?? array() ),
            'recommendations'   => '',
            // v0.38.1 — Stage 3 Section 6 "Health Background" passthrough.
            // Three optional long-form text fields from $s3_data. Available
            // to Make.com Module 63 (Draft client email) + PDFMonkey draft
            // template via {{1.family_history}} etc. Empty when the client
            // skipped Section 6.
            'family_history'      => (string) ( $s3_data_for_payload['family_history']      ?? '' ),
            'medications'         => (string) ( $s3_data_for_payload['medications']         ?? '' ),
            'existing_conditions' => (string) ( $s3_data_for_payload['existing_conditions'] ?? '' ),
        );

        // v0.27.2 — Sanitise text fields for Make.com raw JSON template.
        // See class-hdlv2-final-report.php fire_webhook for full rationale.
        $html_safe_fields = array( 'awaken_content', 'lift_content', 'thrive_content' );
        array_walk_recursive( $payload, function ( &$v, $k ) use ( $html_safe_fields ) {
            if ( is_string( $v ) && ! in_array( $k, $html_safe_fields, true ) ) {
                $v = strtr( $v, array( '"' => "'", '\\' => '/', "\r" => '', "\t" => '    ' ) );
            }
        } );

        HDLV2_Webhook_Monitor::fire(
            $webhook_url,
            array(
                'body'     => wp_json_encode( $payload ),
                'headers'  => array( 'Content-Type' => 'application/json' ),
                'timeout'  => 10,
                'blocking' => true,
            ),
            'draft_report'
        );
    }

    /**
     * v0.40.7 — Mint a one-shot practitioner magic-link URL for the
     * Stage 2 "Release Stage 3" email CTA (Make.com Module 81 + the
     * WP-side fallback `stage2_awaiting_release` template).
     *
     * Why a separate helper:
     *   Both callsites (notify_practitioner_html branch at ~line 1399
     *   and fire_stage2_webhook at ~line 1933) need the same URL shape.
     *   Inlining at both risked drift — easier to verify one helper.
     *
     * Token mechanics:
     *   - 64-hex random token (`bin2hex(random_bytes(32))` — same shape
     *     as client tokens and the regex used by the init handler).
     *   - Stored in transient `hdlv2_prac_login_<token>` with a 30-min
     *     TTL. Payload: {practitioner_id, progress_id, created_at}.
     *   - Consumed (deleted) by the `?prac_login=` init handler in
     *     hdl-longevity-v2.php on first visit. One-shot, replay-safe.
     *
     * URL shape:
     *   https://<site>/<slug>/?prac_login=<64hex>&release_progress_id=N
     *
     * The slug defaults to 'clients' (the real practitioner dashboard
     * page) — was 'client-dashboard' which 404'd. Still filterable via
     * `hdlv2_practitioner_dashboard_slug` for future overrides.
     *
     * After auto-login the init handler strips ?prac_login= via
     * wp_safe_redirect, so the consumed token never lingers in the
     * URL bar / browser history / referrer headers.
     *
     * @param int $practitioner_id WP user ID of the practitioner.
     * @param int $progress_id     hdlv2_form_progress.id this CTA targets.
     * @return string Absolute URL.
     */
    private static function build_practitioner_release_url( $practitioner_id, $progress_id ) {
        $token = bin2hex( random_bytes( 32 ) );

        set_transient(
            'hdlv2_prac_login_' . $token,
            array(
                'practitioner_id' => (int) $practitioner_id,
                'progress_id'     => (int) $progress_id,
                'created_at'      => time(),
            ),
            30 * MINUTE_IN_SECONDS
        );

        $slug = apply_filters( 'hdlv2_practitioner_dashboard_slug', 'clients' );

        return home_url(
            '/' . trim( $slug, '/' ) . '/'
            . '?prac_login=' . $token
            . '&release_progress_id=' . (int) $progress_id
        );
    }

    /**
     * Fire Make.com webhook after Stage 2 WHY data is saved.
     * Make.com handles AI extraction (distilled WHY, key people, motivations).
     * Payload matches the pattern used by fire_make_webhook() and Final Report.
     */
    /**
     * v0.40.19 — Static so the stage2 extraction retry cron
     * (HDLV2_Checkin::run_stage2_extraction_retry) can re-fire on a stuck
     * progress row without instantiating HDLV2_Staged_Form. No $this access
     * inside the body, so this conversion is safe.
     */
    public static function fire_stage2_webhook( $progress, $stage2_data ) {
        $webhook_url = defined( 'HDLV2_MAKE_STAGE2_WHY' ) ? HDLV2_MAKE_STAGE2_WHY : '';
        if ( empty( $webhook_url ) ) {
            error_log( '[HDLV2] Stage 2 webhook skipped — HDLV2_MAKE_STAGE2_WHY not configured.' );
            return;
        }

        $prac_user = $progress->practitioner_user_id ? get_userdata( $progress->practitioner_user_id ) : null;

        // Practitioner logo with HDL fallback baked in (v0.31.1) — same
        // pattern as Stage 1 + Draft. The `true` flag guarantees a non-empty
        // URL so the WHY PDF's footer card never renders a broken-image icon
        // when a practitioner hasn't uploaded their own logo.
        $prac_logo = HDLV2_Practitioner::get_logo_url( (int) $progress->practitioner_user_id, true );

        // v0.36.21 — Enrich Stage 2 webhook with Stage 1 quantitative context so
        // Claude can ground the WHY analysis in pace-of-ageing, biological age,
        // strongest factor and top-priority factor. Without these the prompt only
        // saw vision_text and the practitioner_brief couldn't tie the qualitative
        // motivations to the quantitative starting picture. Same fields the
        // Draft Report fire pulls (see ~line 1758) — read from server_result
        // which was already computed and stored when Stage 1 completed.
        $stage1_data   = json_decode( $progress->stage1_data, true ) ?: array();
        $server_result = $stage1_data['server_result'] ?? array();
        $structured    = ( class_exists( 'HDLV2_Stage1_Commentary' ) )
            ? HDLV2_Stage1_Commentary::build_structured( $stage1_data, $server_result, $progress->client_name ?: '' )
            : array();

        $client_age = (string) ( $stage1_data['q1_age'] ?? '' );
        $client_sex = (string) ( $stage1_data['q1_sex'] ?? '' );

        // v0.37.1 — Practitioner dashboard deep-link for the Stage 2 WHY
        // Make.com email module (Module 81). Same URL pattern the WP-fired
        // `stage2_awaiting_release` email uses (send_stage_email line 1381)
        // so both emails land the practitioner on the same scroll-and-pulse
        // highlighted row. Front-end consumers (verified before shipping):
        //   - hdlv2-practitioner-dashboard.js:187 applyReleaseDeepLink()
        //   - hdlv2-client-list-enhance.js:846   (client list parallel handler)
        // v0.40.7 — One-shot practitioner magic-link. See helper comment +
        // hdl-longevity-v2.php `?prac_login=` init handler for the full
        // auto-login chain. Replaces the previous bare /client-dashboard/
        // URL that 404'd AND required prior login.
        $practitioner_dashboard_url = self::build_practitioner_release_url(
            (int) $progress->practitioner_user_id,
            (int) $progress->id
        );

        $payload = array(
            'report_type'                => 'stage2_why',
            'event'                      => 'stage2_submitted',
            'token'                      => $progress->token,
            'vision_text'                => $stage2_data['vision_text'] ?? '',
            'client_name'                => $progress->client_name ?: '',
            'client_email'               => $progress->client_email ?: '',
            'client_age'                 => $client_age,
            'client_sex'                 => $client_sex,
            'chronological_age'          => $client_age,
            'biological_age'             => (string) ( $server_result['bio_age'] ?? $structured['biological_age'] ?? '' ),
            'rate_of_ageing'             => (string) ( $server_result['rate'] ?? '' ),
            'strongest_topic'            => (string) ( $structured['strongest_topic'] ?? '' ),
            'priority_topic'             => (string) ( $structured['priority_topic'] ?? '' ),
            'practitioner_name'          => $prac_user ? $prac_user->display_name : '',
            'practitioner_email'         => $prac_user ? $prac_user->user_email : '',
            'practitioner_logo_url'      => $prac_logo,
            'practitioner_dashboard_url' => $practitioner_dashboard_url, // v0.37.1 — Module 81 "Release Stage 3" CTA
            'report_date'                => current_time( 'j F Y' ),
            'submitted_at'               => current_time( 'c' ),
            // v0.36.8 — dynamic callback URL so ONE Make.com scenario can
            // serve both STBY and LIVE without a Router. rest_url() resolves
            // per environment (STBY domain on STBY, LIVE domain on LIVE).
            // Make.com's HTTP (legacy) callback URL field changes from a
            // hardcoded STBY URL to {{1.callback_url}}; each env tells
            // Make.com exactly where to post back. Same architectural
            // pattern Flight Plan webhook has used since v0.15.4.
            //
            // Without this, the Make.com scenario's hardcoded STBY callback
            // meant LIVE submissions stalled on the result page — Make.com
            // POSTed the why_profile back to STBY, so the LIVE row was
            // never written. Reported by Quim 2026-05-10 (3-minute spinner
            // on LIVE Stage 2 result page).
            'callback_url'          => rest_url( 'hdl-v2/v1/form/stage2-callback' ),
            'callback_secret'       => defined( 'HDLV2_MAKE_CALLBACK_SECRET' ) ? HDLV2_MAKE_CALLBACK_SECRET : '',
        );

        HDLV2_Webhook_Monitor::fire(
            $webhook_url,
            array(
                'body'     => wp_json_encode( $payload ),
                'headers'  => array( 'Content-Type' => 'application/json' ),
                'timeout'  => 10,
                'blocking' => true,
            ),
            'stage2_why'
        );
    }

    /**
     * Daily cron — retry stuck Stage 2 WHY extractions.
     *
     * Closes a real production gap exposed during STBY testing 2026-05-13:
     * the Make.com Stage 2 scenario can silently fail (e.g. Claude output
     * exceeded max_tokens → ParseJSON 422 → callback never fires). Without
     * this cron, a single Make.com failure permanently leaves the client
     * with no why_profile row — which then degrades the eventual Final
     * Report and every Flight Plan that reads from why_profiles.
     *
     * Logic:
     *   1. Find form_progress rows where stage2_webhook_fired_at is set,
     *      no matching why_profiles row exists, fired > 30 minutes ago,
     *      and the vision_text is non-trivial (>= 10 chars).
     *   2. For each, check the per-progress retry counter (transient).
     *      • Attempts 1-2 (counter < 2): re-fire Make.com webhook via
     *        self::retry_stage2_webhook(). If Make.com is back, the
     *        callback writes why_profiles and the next cron pass exits
     *        because the LEFT JOIN now matches.
     *      • Attempt 3 (counter == 2): fall back to local extract_why()
     *        via HDLV2_AI_Service. Writes why_profiles directly. Loses
     *        the Stage 2 PDF (Make.com still owns that) but preserves
     *        the data that Final Report + Flight Plan depend on.
     *   3. After 3 attempts, the transient sits at 3 and the row is
     *      skipped — practitioner-level intervention required.
     *
     * Throttle: 7-day transient per progress_id stops infinite loops.
     * Bound: max 50 candidates per run (safety against runaway).
     *
     * @since 0.40.19
     */
    public static function run_stage2_extraction_retry() {
        if ( ! class_exists( 'HDLV2_AI_Service' ) ) {
            error_log( '[HDLV2] Stage 2 extraction retry: HDLV2_AI_Service missing, aborting.' );
            return;
        }

        global $wpdb;
        $threshold = gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS );

        $candidates = $wpdb->get_results( $wpdb->prepare(
            "SELECT fp.id, fp.token, fp.client_user_id, fp.stage2_data, fp.client_name
             FROM {$wpdb->prefix}hdlv2_form_progress fp
             LEFT JOIN {$wpdb->prefix}hdlv2_why_profiles wpr ON wpr.form_progress_id = fp.id
             WHERE fp.stage2_webhook_fired_at IS NOT NULL
               AND fp.stage2_webhook_fired_at < %s
               AND wpr.id IS NULL
             ORDER BY fp.id DESC
             LIMIT 50",
            $threshold
        ) );

        if ( empty( $candidates ) ) return;

        foreach ( $candidates as $row ) {
            $stage2_data = json_decode( $row->stage2_data, true ) ?: array();
            $vision_text = (string) ( $stage2_data['vision_text'] ?? '' );
            if ( strlen( trim( $vision_text ) ) < 10 ) continue;

            $key       = 'hdlv2_stage2_retry_' . (int) $row->id;
            $attempts  = (int) get_transient( $key );
            if ( $attempts >= 3 ) continue; // Exhausted

            $next = $attempts + 1;

            if ( $next <= 2 ) {
                // Attempts 1-2: re-fire Make.com webhook.
                $ok = self::retry_stage2_webhook( (int) $row->id );
                error_log( sprintf(
                    '[HDLV2] Stage 2 extraction retry %d/3 for progress %d via Make.com: %s',
                    $next, (int) $row->id, $ok ? 'webhook re-fired' : 'webhook skipped'
                ) );
            } else {
                // Attempt 3: local fallback via HDLV2_AI_Service::extract_why.
                // Bypasses Make.com entirely so a persistent Make.com outage
                // can't permanently strip a client's downstream personalisation.
                $extracted = HDLV2_AI_Service::extract_why( $stage2_data );
                if ( ! empty( $extracted['distilled_why'] ) ) {
                    $inserted = $wpdb->insert(
                        $wpdb->prefix . 'hdlv2_why_profiles',
                        array(
                            'form_progress_id' => (int) $row->id,
                            'client_user_id'   => $row->client_user_id ? (int) $row->client_user_id : null,
                            'key_people'       => wp_json_encode( $extracted['key_people']  ?? array() ),
                            'motivations'      => wp_json_encode( $extracted['motivations'] ?? array() ),
                            'fears'            => wp_json_encode( $extracted['fears']       ?? array() ),
                            'vision_text'      => sanitize_textarea_field( $vision_text ),
                            'distilled_why'    => sanitize_textarea_field( $extracted['distilled_why'] ),
                            'ai_reformulation' => wp_kses_post( $extracted['ai_reformulation'] ?? '' ),
                            'raw_input'        => wp_json_encode( $stage2_data ),
                            'released'         => 0,
                        ),
                        array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
                    );
                    error_log( sprintf(
                        '[HDLV2] Stage 2 extraction retry %d/3 — local fallback %s for progress %d',
                        $next, $inserted ? 'SUCCESS' : 'DB insert FAILED', (int) $row->id
                    ) );
                } else {
                    error_log( sprintf(
                        '[HDLV2] Stage 2 extraction retry %d/3 — local fallback returned no distilled_why for progress %d',
                        $next, (int) $row->id
                    ) );
                }
            }

            set_transient( $key, $next, 7 * DAY_IN_SECONDS );
        }
    }

    /**
     * Re-fire the Stage 2 webhook for an existing form_progress row.
     *
     * Designed for the v0.40.19 stuck-extraction retry cron AND for any
     * future practitioner-dashboard "Retry WHY" button. Loads the row,
     * validates that vision_text exists, calls fire_stage2_webhook, then
     * stamps stage2_webhook_fired_at + stage2_text_hash so subsequent
     * auto-saves don't re-fire on the same content.
     *
     * Does NOT alter current_stage or stage2_completed_at — those are
     * controlled by /form/complete-stage and the practitioner Release.
     *
     * @param int $progress_id
     * @return bool true if fired, false if not (no row, no vision_text, or no webhook URL)
     * @since 0.40.19
     */
    public static function retry_stage2_webhook( $progress_id ) {
        $progress_id = (int) $progress_id;
        if ( $progress_id <= 0 ) return false;

        global $wpdb;
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d LIMIT 1",
            $progress_id
        ) );
        if ( ! $progress ) return false;

        $stage2_data = json_decode( $progress->stage2_data, true ) ?: array();
        $vision_text = (string) ( $stage2_data['vision_text'] ?? '' );
        if ( strlen( trim( $vision_text ) ) < 10 ) return false;

        self::fire_stage2_webhook( $progress, $stage2_data );

        $wpdb->update(
            $wpdb->prefix . 'hdlv2_form_progress',
            array(
                'stage2_webhook_fired_at' => current_time( 'mysql' ),
                'stage2_text_hash'        => md5( $vision_text ),
            ),
            array( 'id' => $progress_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
        return true;
    }

    // ──────────────────────────────────────────────────────────────
    //  TOKEN HELPERS
    // ──────────────────────────────────────────────────────────────

    private function validate_token_param( $request ) {
        $token = sanitize_text_field( $request->get_param( 'token' ) ?? '' );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            return new WP_Error( 'invalid_token', 'Invalid token format.', array( 'status' => 400 ) );
        }
        return $token;
    }

    private function validate_token_from_body( $params ) {
        $token = sanitize_text_field( $params['token'] ?? '' );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            return new WP_Error( 'invalid_token', 'Invalid token format.', array( 'status' => 400 ) );
        }
        return $token;
    }

    private function get_progress_by_token( $token ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_form_progress WHERE token = %s LIMIT 1",
            $token
        ) );
    }
}
