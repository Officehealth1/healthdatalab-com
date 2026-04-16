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

        return rest_ensure_response( array(
            'current_stage'       => (int) $progress->current_stage,
            'client_name'         => $progress->client_name,
            'client_email'        => $progress->client_email,
            'stage1_data'         => json_decode( $progress->stage1_data, true ),
            'stage1_completed_at' => $progress->stage1_completed_at,
            'stage2_data'         => json_decode( $progress->stage2_data, true ),
            'stage2_completed_at' => $progress->stage2_completed_at,
            'stage3_data'         => json_decode( $progress->stage3_data, true ),
            'stage3_completed_at' => $progress->stage3_completed_at,
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
                $this->fire_stage2_webhook( $progress, $merged );
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

        // Can't complete a stage that's already completed
        $completed_col = 'stage' . $stage . '_completed_at';
        if ( ! empty( $progress->$completed_col ) ) {
            return new WP_Error( 'already_completed', 'This stage is already complete.', array( 'status' => 409 ) );
        }

        $data_col   = 'stage' . $stage . '_data';
        $stage_data = json_decode( $progress->$data_col, true ) ?: array();

        // Validate required fields per stage
        $validation = $this->validate_stage_data( $stage, $stage_data );
        if ( is_wp_error( $validation ) ) return $validation;

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

            // Map Stage 1 Q3 (zone 2 activity) → physicalActivity score for full calc
            if ( isset( $calc_data['q3'] ) && ! isset( $calc_data['physicalActivity'] ) ) {
                $q3_map = array( 'a' => 0, 'b' => 1, 'c' => 2, 'd' => 3, 'e' => 5 );
                $calc_data['physicalActivity'] = $q3_map[ strtolower( $calc_data['q3'] ) ] ?? 0;
            }

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
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_form_progress',
            $updates,
            array( 'id' => $progress->id )
        );

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

        $s1_data    = json_decode( $progress->stage1_data, true ) ?: array();
        $s3_data    = json_decode( $progress->stage3_data, true ) ?: array();
        $calc_result = $s3_data['server_result'] ?? array();

        // Load WHY profile
        global $wpdb;
        $why_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT distilled_why, ai_reformulation FROM {$wpdb->prefix}hdlv2_why_profiles WHERE form_progress_id = %d LIMIT 1",
            $progress->id
        ), ARRAY_A );
        $why_profile = $why_row ?: array( 'distilled_why' => '', 'ai_reformulation' => '' );

        // Generate report via Claude AI
        $report = HDLV2_AI_Service::generate_draft_report(
            $calc_result,
            $s1_data,
            $why_profile,
            $progress->client_name ?: ''
        );

        // Generate AI-suggested milestones
        $milestones = HDLV2_AI_Service::generate_milestones(
            $calc_result,
            $why_profile,
            array(), // no practitioner recommendations yet (draft)
            $s1_data['q1_age'] ?? $s1_data['age'] ?? 0
        );

        // Update the draft report row
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_reports',
            array(
                'report_content' => wp_json_encode( $report ),
                'milestones'     => wp_json_encode( $milestones ),
                'status'         => 'ready',
            ),
            array(
                'form_progress_id' => $progress->id,
                'report_type'      => 'draft',
            )
        );

        // Fire Make.com webhook (non-blocking)
        $this->fire_make_webhook( $progress, $calc_result, $s1_data, $report, $why_profile, $milestones );

        return rest_ensure_response( array(
            'success'         => true,
            'awaken_content'  => $report['awaken_content'],
            'lift_content'    => $report['lift_content'],
            'thrive_content'  => $report['thrive_content'],
        ) );
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

        // Check if this client already has an active form for this practitioner
        global $wpdb;
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, token FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE client_email = %s AND practitioner_user_id = %d
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
            array(),
            HDLV2_VERSION,
            true
        );

        wp_enqueue_script(
            'hdlv2-staged-form',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-staged-form.js',
            array( 'hdlv2-speedometer', 'hdlv2-audio-component' ),
            HDLV2_VERSION,
            true
        );

        wp_enqueue_style(
            'hdlv2-form',
            HDLV2_PLUGIN_URL . 'assets/css/hdlv2-form.css',
            array(),
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

        global $wpdb;
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d",
            $progress_id
        ) );

        if ( ! $progress ) {
            return new WP_Error( 'not_found', 'Assessment not found.', array( 'status' => 404 ) );
        }

        // Verify practitioner owns this client
        if ( (int) $progress->practitioner_user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', 'You do not have access to this assessment.', array( 'status' => 403 ) );
        }

        // Check WHY profile exists
        $why = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, released FROM {$wpdb->prefix}hdlv2_why_profiles WHERE form_progress_id = %d LIMIT 1",
            $progress_id
        ) );

        if ( ! $why ) {
            return new WP_Error( 'no_why', 'Stage 2 has not been completed yet.', array( 'status' => 400 ) );
        }

        if ( $why->released ) {
            return rest_ensure_response( array( 'success' => true, 'already_released' => true ) );
        }

        // Release the gate
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_why_profiles',
            array( 'released' => 1, 'released_at' => current_time( 'mysql' ) ),
            array( 'id' => $why->id )
        );

        // Advance client to Stage 3 — this is the ONLY place current_stage goes from 2 → 3
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_form_progress',
            array( 'current_stage' => 3 ),
            array( 'id' => $progress->id ),
            array( '%d' ),
            array( '%d' )
        );

        // Send Stage 3 invitation email
        if ( ! empty( $progress->client_email ) ) {
            $form_url = site_url( '/assessment/?token=' . $progress->token );
            HDLV2_Email_Templates::why_gate_released( array(
                'client_name'  => $progress->client_name,
                'client_email' => $progress->client_email,
                'form_url'     => $form_url,
            ) );
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

        $profile_data = array(
            'form_progress_id' => $progress->id,
            'client_user_id'   => $progress->client_user_id ?: null,
            'key_people'       => wp_json_encode( $params['key_people'] ?? array() ),
            'motivations'      => wp_json_encode( $params['key_motivations'] ?? array() ),
            'fears'            => wp_json_encode( $params['key_fears'] ?? array() ),
            'vision_text'      => sanitize_textarea_field( $stage2_data['vision_text'] ?? '' ),
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

            if ( $client_email ) {
                $html = HDLV2_Email_Templates::stage1_results( array(
                    'client_name'  => $client_name,
                    'rate'         => $rate,
                    'bio_age'      => $bio_age,
                    'age'          => $age,
                    'gauge_url'    => self::build_gauge_url( $rate ),
                    'token_url'    => $token_url,
                    'rate_message' => $this->rate_message( $rate, $bio_age, $age ),
                ) );
                wp_mail( $client_email, 'Your Quick Health Insight — HealthDataLab', $html, $html_headers );
            }

            $this->notify_practitioner_html( $progress,
                HDLV2_Email_Templates::stage1_results( array(
                    'client_name'  => $client_name,
                    'rate'         => $rate,
                    'bio_age'      => $bio_age,
                    'age'          => $age,
                    'gauge_url'    => self::build_gauge_url( $rate ),
                    'token_url'    => $token_url,
                    'rate_message' => $this->rate_message( $rate, $bio_age, $age ),
                ) ),
                sprintf( 'Stage 1 Complete: %s', $client_name ?: $client_email )
            );
        }

        // ── Stage 2: WHY Profile (gate: practitioner must release before Stage 3) ──
        if ( $stage === 2 ) {
            // Client email: branded HTML "saved, your practitioner will use these"
            if ( $client_email ) {
                $html = HDLV2_Email_Templates::stage2_saved( array(
                    'client_name' => $client_name,
                ) );
                wp_mail( $client_email, 'Your Responses Have Been Saved — HealthDataLab', $html,
                    array( 'Content-Type: text/html; charset=UTF-8' ) );
            }

            // Practitioner: notified with WHY summary so they can review + release gate
            $this->notify_practitioner( $progress, sprintf(
                "Your client %s has completed Stage 2 (WHY Profile).\n\nDistilled WHY: %s\n\nPlease review and release their WHY profile to unlock Stage 3.",
                $client_name ?: $client_email,
                $stage_data['distilled_why'] ?? '(pending AI extraction)'
            ), sprintf( 'Stage 2 Complete: %s — Action Required', $client_name ?: $client_email ) );
        }

        // ── Stage 3: Assessment Complete ──
        if ( $stage === 3 ) {
            $rate    = $result_data['rate'] ?? 'N/A';
            $bio_age = $result_data['bio_age'] ?? 'N/A';
            $s1d     = json_decode( $progress->stage1_data, true ) ?: array();
            $age     = $s1d['q1_age'] ?? $s1d['age'] ?? '?';
            $scores  = $result_data['scores'] ?? array();

            // Identify positive/negative factors
            $pos = array(); $neg = array();
            foreach ( $scores as $name => $score ) {
                if ( ! is_numeric( $score ) ) continue;
                if ( $score >= 4 ) $pos[] = $name;
                if ( $score <= 2 ) $neg[] = $name;
            }

            if ( $client_email ) {
                $html = HDLV2_Email_Templates::stage3_complete( array(
                    'client_name' => $client_name,
                ) );
                wp_mail( $client_email, 'Assessment Complete — HealthDataLab', $html,
                    array( 'Content-Type: text/html; charset=UTF-8' ) );
            }

            $this->notify_practitioner_html( $progress,
                HDLV2_Email_Templates::stage3_draft_ready( array(
                    'client_name'  => $client_name,
                    'client_email' => $client_email,
                    'rate'         => $rate,
                    'bio_age'      => $bio_age,
                    'age'          => $age,
                    'positives'    => implode( ', ', $pos ),
                    'negatives'    => implode( ', ', $neg ),
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

    public static function build_gauge_url( $rate ) {
        $clamped = max( 0.6, min( 1.4, round( $rate, 2 ) ) );
        if ( $clamped <= 0.9 ) {
            $interp = 'Slower';
            $interp_color = 'rgba(67, 191, 85, 1)';
        } elseif ( $clamped <= 1.1 ) {
            $interp = 'Average';
            $interp_color = 'rgba(65, 165, 238, 1)';
        } else {
            $interp = 'Faster';
            $interp_color = 'rgba(255, 111, 75, 1)';
        }

        $cfg = array(
            'type' => 'gauge',
            'data' => array(
                'labels'   => array( 'Slower', 'Average', 'Faster' ),
                'datasets' => array( array(
                    'data'            => array( 0.9, 1.1, 1.4 ),
                    'value'           => $clamped,
                    'minValue'        => 0.8,
                    'maxValue'        => 1.4,
                    'backgroundColor' => array(
                        'rgba(67,191,85,0.95)',
                        'rgba(65,165,238,0.95)',
                        'rgba(255,111,75,0.95)',
                    ),
                    'borderWidth'     => 1,
                    'borderColor'     => 'rgba(255,255,255,0.8)',
                    'borderRadius'    => 5,
                ) ),
            ),
            'options' => array(
                'layout'     => array( 'padding' => array( 'top' => 30, 'bottom' => 15, 'left' => 15, 'right' => 15 ) ),
                'needle'     => array( 'radiusPercentage' => 2.5, 'widthPercentage' => 4.0, 'lengthPercentage' => 68, 'color' => '#004F59', 'shadowColor' => 'rgba(0,79,89,0.4)', 'shadowBlur' => 8, 'shadowOffsetY' => 4, 'borderWidth' => 2, 'borderColor' => 'rgba(255,255,255,1.0)' ),
                'valueLabel' => array( 'display' => true, 'fontSize' => 36, 'fontFamily' => "'Inter',sans-serif", 'fontWeight' => 'bold', 'color' => '#004F59', 'backgroundColor' => 'transparent', 'bottomMarginPercentage' => -10, 'padding' => 8 ),
                'centerArea' => array( 'displayText' => false, 'backgroundColor' => 'transparent' ),
                'arc'        => array( 'borderWidth' => 0, 'padding' => 2, 'margin' => 3, 'roundedCorners' => true ),
                'subtitle'   => array( 'display' => true, 'text' => $interp, 'color' => $interp_color, 'font' => array( 'size' => 20, 'weight' => 'bold', 'family' => "'Inter',sans-serif" ), 'padding' => array( 'top' => 8 ) ),
            ),
        );

        return 'https://quickchart.io/chart?w=380&h=340&bkg=white&c=' . urlencode( wp_json_encode( $cfg ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  WHY PROFILE + DRAFT REPORT
    // ──────────────────────────────────────────────────────────────

    /**
     * Create or update the WHY profile from Stage 2 data.
     */
    private function save_why_profile( $progress, $stage_data, $why_result = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_why_profiles';

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE form_progress_id = %d LIMIT 1",
            $progress->id
        ) );

        $profile_data = array(
            'form_progress_id' => $progress->id,
            'client_user_id'   => $progress->client_user_id ?: null,
            'key_people'       => wp_json_encode( $stage_data['key_people'] ?? array() ),
            'motivations'      => wp_json_encode( $stage_data['motivations'] ?? array() ),
            'vision_text'      => sanitize_textarea_field( $stage_data['vision_text'] ?? '' ),
            'distilled_why'    => $why_result['distilled_why'] ?? '',
            'ai_reformulation' => $why_result['ai_reformulation'] ?? '',
            'raw_input'        => wp_json_encode( $stage_data ),
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
     */
    private function create_draft_report( $progress, $result_data ) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'hdlv2_reports',
            array(
                'client_user_id'       => $progress->client_user_id ?: null,
                'practitioner_user_id' => $progress->practitioner_user_id,
                'form_progress_id'     => $progress->id,
                'report_type'          => 'draft',
                'report_content'       => wp_json_encode( $result_data ),
                'status'               => 'generating',
            )
        );
    }

    /**
     * Fire Make.com webhook with draft report payload.
     */
    private function fire_make_webhook( $progress, $calc_result, $s1_data, $report, $why_profile, $milestones = array() ) {
        $webhook_url = defined( 'HDLV2_MAKE_DRAFT_REPORT' ) ? HDLV2_MAKE_DRAFT_REPORT : '';

        $prac_user = $progress->practitioner_user_id ? get_userdata( $progress->practitioner_user_id ) : null;

        $payload = array(
            'client_name'       => $progress->client_name ?: '',
            'client_email'      => $progress->client_email ?: '',
            'practitioner_name' => $prac_user ? $prac_user->display_name : '',
            'practitioner_email'=> $prac_user ? $prac_user->user_email : '',
            'rate_of_ageing'    => $calc_result['rate'] ?? null,
            'biological_age'    => $calc_result['bio_age'] ?? null,
            'chronological_age' => $s1_data['q1_age'] ?? $s1_data['age'] ?? null,
            'gauge_url'         => self::build_gauge_url( $calc_result['rate'] ?? 1.0 ),
            'report_date'       => current_time( 'j F Y' ),
            'report_type'       => 'draft',
            'awaken_content'    => $report['awaken_content'] ?? '',
            'lift_content'      => $report['lift_content'] ?? '',
            'thrive_content'    => $report['thrive_content'] ?? '',
            'why_profile'       => $why_profile['distilled_why'] ?? '',
            'health_scores'     => $calc_result['scores'] ?? array(),
            'score_bmi'         => $calc_result['scores']['bmiScore'] ?? '',
            'score_whr'         => $calc_result['scores']['whrScore'] ?? '',
            'score_whtr'        => $calc_result['scores']['whtrScore'] ?? '',
            'score_overall'     => $calc_result['scores']['overallHealthScore'] ?? '',
            'score_bp'          => $calc_result['scores']['bloodPressureScore'] ?? '',
            'score_hr'          => $calc_result['scores']['heartRateScore'] ?? '',
            'score_skin'        => $calc_result['scores']['skinElasticity'] ?? '',
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
        );

        wp_remote_post( $webhook_url, array(
            'body'     => wp_json_encode( $payload ),
            'headers'  => array( 'Content-Type' => 'application/json' ),
            'timeout'  => 30,
            'blocking' => false,
        ) );

        error_log( sprintf( '[HDLV2] Make.com webhook dispatched for progress_id=%d, rate=%s', $progress->id, $calc_result['rate'] ?? 'N/A' ) );
    }

    /**
     * Fire Make.com webhook after Stage 2 WHY data is saved.
     * Make.com handles AI extraction (distilled WHY, key people, motivations).
     * Payload matches the pattern used by fire_make_webhook() and Final Report.
     */
    private function fire_stage2_webhook( $progress, $stage2_data ) {
        $webhook_url = defined( 'HDLV2_MAKE_STAGE2_WHY' ) ? HDLV2_MAKE_STAGE2_WHY : '';
        if ( empty( $webhook_url ) ) {
            error_log( '[HDLV2] Stage 2 webhook skipped — HDLV2_MAKE_STAGE2_WHY not configured.' );
            return;
        }

        $prac_user = $progress->practitioner_user_id ? get_userdata( $progress->practitioner_user_id ) : null;

        // Practitioner logo from widget config — same lookup as Final Report
        $prac_logo = '';
        if ( $progress->practitioner_user_id ) {
            global $wpdb;
            $prac_logo = $wpdb->get_var( $wpdb->prepare(
                "SELECT logo_url FROM {$wpdb->prefix}hdlv2_widget_config WHERE practitioner_user_id = %d LIMIT 1",
                $progress->practitioner_user_id
            ) ) ?: '';
        }

        $payload = array(
            'report_type'           => 'stage2_why',
            'event'                 => 'stage2_submitted',
            'token'                 => $progress->token,
            'vision_text'           => $stage2_data['vision_text'] ?? '',
            'client_name'           => $progress->client_name ?: '',
            'client_email'          => $progress->client_email ?: '',
            'practitioner_name'     => $prac_user ? $prac_user->display_name : '',
            'practitioner_email'    => $prac_user ? $prac_user->user_email : '',
            'practitioner_logo_url' => $prac_logo,
            'report_date'           => current_time( 'j F Y' ),
            'submitted_at'          => current_time( 'c' ),
        );

        wp_remote_post( $webhook_url, array(
            'body'     => wp_json_encode( $payload ),
            'headers'  => array( 'Content-Type' => 'application/json' ),
            'timeout'  => 30,
            'blocking' => false,
        ) );

        error_log( sprintf( '[HDLV2] Stage 2 WHY webhook dispatched for progress_id=%d', $progress->id ) );
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
