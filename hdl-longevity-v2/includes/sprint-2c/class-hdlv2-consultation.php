<?php
/**
 * Practitioner Consultation Interface.
 *
 * Split-screen workspace: draft report (left) + editing tools (right).
 * Practitioner edits health data, adds notes/recommendations, then
 * triggers Final Report generation.
 *
 * @package HDL_Longevity_V2
 * @since 0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Consultation {

    public function register_hooks() {
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_shortcode( 'hdlv2_consultation', array( $this, 'render_shortcode' ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST ROUTES
    // ──────────────────────────────────────────────────────────────

    public function register_rest_routes() {
        $ns = 'hdl-v2/v1';

        // Load consultation data for a client
        register_rest_route( $ns, '/consultation/(?P<progress_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_load_consultation' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );

        // Auto-save practitioner notes
        register_rest_route( $ns, '/consultation/save-notes', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_save_notes' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );

        // Add a structured recommendation
        register_rest_route( $ns, '/consultation/add-recommendation', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_add_recommendation' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );

        // Remove a recommendation by index
        register_rest_route( $ns, '/consultation/remove-recommendation', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_remove_recommendation' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );

        // Edit a health data field (logs change)
        register_rest_route( $ns, '/consultation/edit-field', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_edit_field' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );

        // Trigger Final Report generation
        register_rest_route( $ns, '/consultation/finalise', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_finalise' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );
    }

    public function check_practitioner_auth() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) return false;
        return HDLV2_Compatibility::is_practitioner( $user_id );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: LOAD CONSULTATION
    // ──────────────────────────────────────────────────────────────

    public function rest_load_consultation( $request ) {
        $progress_id = (int) $request->get_param( 'progress_id' );

        global $wpdb;
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d LIMIT 1",
            $progress_id
        ) );

        if ( ! $progress ) {
            return new WP_Error( 'not_found', 'Assessment not found.', array( 'status' => 404 ) );
        }

        // Verify this practitioner owns this client
        if ( (int) $progress->practitioner_user_id !== get_current_user_id() ) {
            return new WP_Error( 'forbidden', 'You do not have access to this client.', array( 'status' => 403 ) );
        }

        // Load draft report
        $draft = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_reports WHERE form_progress_id = %d AND report_type = 'draft' ORDER BY id DESC LIMIT 1",
            $progress_id
        ) );

        // Load WHY profile
        $why = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_why_profiles WHERE form_progress_id = %d LIMIT 1",
            $progress_id
        ) );

        // Load or create consultation notes
        $consult = $this->get_or_create_consultation( $progress );

        $s1_data = json_decode( $progress->stage1_data, true ) ?: array();
        $s3_data = json_decode( $progress->stage3_data, true ) ?: array();
        $draft_content = $draft ? json_decode( $draft->report_content, true ) : null;
        // If report_content is a string (HTML), it might be the calc result — try both
        if ( ! $draft_content && $draft ) {
            $draft_content = array(
                'awaken_content' => $draft->report_content ?? '',
                'lift_content'   => '',
                'thrive_content' => '',
            );
        }

        return rest_ensure_response( array(
            'progress_id'        => $progress_id,
            'client_name'        => $progress->client_name ?: '',
            'client_email'       => $progress->client_email ?: '',
            'stage1_data'        => $s1_data,
            'stage3_data'        => $s3_data,
            'calc_result'        => $s3_data['server_result'] ?? array(),
            'why_profile'        => $why ? array(
                'distilled_why'    => $why->distilled_why,
                'ai_reformulation' => $why->ai_reformulation,
                'key_people'       => json_decode( $why->key_people, true ),
                'motivations'      => json_decode( $why->motivations, true ),
                'fears'            => json_decode( $why->fears, true ),
            ) : null,
            'draft_report'       => $draft_content,
            'draft_status'       => $draft ? $draft->status : null,
            'consultation'       => array(
                'id'                  => (int) $consult->id,
                'typed_notes'         => $consult->typed_notes ?: '',
                'recommendations'     => json_decode( $consult->recommendations, true ) ?: array(),
                'health_data_changes' => json_decode( $consult->health_data_changes, true ) ?: array(),
                'status'              => $consult->status,
            ),
        ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: SAVE NOTES (auto-save)
    // ──────────────────────────────────────────────────────────────

    public function rest_save_notes( $request ) {
        $params      = $request->get_json_params();
        $consult_id  = (int) ( $params['consultation_id'] ?? 0 );
        $typed_notes = sanitize_textarea_field( $params['typed_notes'] ?? '' );

        if ( ! $consult_id ) {
            return new WP_Error( 'missing_id', 'Consultation ID required.', array( 'status' => 400 ) );
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_consultation_notes',
            array( 'typed_notes' => $typed_notes ),
            array( 'id' => $consult_id, 'practitioner_user_id' => get_current_user_id() )
        );

        return rest_ensure_response( array( 'success' => true ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: ADD RECOMMENDATION
    // ──────────────────────────────────────────────────────────────

    public function rest_add_recommendation( $request ) {
        $params     = $request->get_json_params();
        $consult_id = (int) ( $params['consultation_id'] ?? 0 );

        $rec = array(
            'category'  => sanitize_text_field( $params['category'] ?? '' ),
            'text'      => sanitize_textarea_field( $params['text'] ?? '' ),
            'priority'  => sanitize_text_field( $params['priority'] ?? 'Medium' ),
            'frequency' => sanitize_text_field( $params['frequency'] ?? 'As needed' ),
        );

        if ( ! $rec['text'] || ! $consult_id ) {
            return new WP_Error( 'invalid', 'Recommendation text and consultation ID required.', array( 'status' => 400 ) );
        }

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT recommendations FROM {$wpdb->prefix}hdlv2_consultation_notes WHERE id = %d AND practitioner_user_id = %d",
            $consult_id, get_current_user_id()
        ) );

        if ( ! $row ) {
            return new WP_Error( 'not_found', 'Consultation not found.', array( 'status' => 404 ) );
        }

        $recs = json_decode( $row->recommendations, true ) ?: array();
        $recs[] = $rec;

        $wpdb->update(
            $wpdb->prefix . 'hdlv2_consultation_notes',
            array( 'recommendations' => wp_json_encode( $recs ) ),
            array( 'id' => $consult_id )
        );

        return rest_ensure_response( array( 'success' => true, 'recommendations' => $recs ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: REMOVE RECOMMENDATION
    // ──────────────────────────────────────────────────────────────

    public function rest_remove_recommendation( $request ) {
        $params     = $request->get_json_params();
        $consult_id = (int) ( $params['consultation_id'] ?? 0 );
        $index      = (int) ( $params['index'] ?? -1 );

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT recommendations FROM {$wpdb->prefix}hdlv2_consultation_notes WHERE id = %d AND practitioner_user_id = %d",
            $consult_id, get_current_user_id()
        ) );

        if ( ! $row ) {
            return new WP_Error( 'not_found', 'Consultation not found.', array( 'status' => 404 ) );
        }

        $recs = json_decode( $row->recommendations, true ) ?: array();
        if ( $index >= 0 && $index < count( $recs ) ) {
            array_splice( $recs, $index, 1 );
        }

        $wpdb->update(
            $wpdb->prefix . 'hdlv2_consultation_notes',
            array( 'recommendations' => wp_json_encode( $recs ) ),
            array( 'id' => $consult_id )
        );

        return rest_ensure_response( array( 'success' => true, 'recommendations' => $recs ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: EDIT HEALTH DATA FIELD
    // ──────────────────────────────────────────────────────────────

    public function rest_edit_field( $request ) {
        $params      = $request->get_json_params();
        $consult_id  = (int) ( $params['consultation_id'] ?? 0 );
        $progress_id = (int) ( $params['progress_id'] ?? 0 );
        $field       = sanitize_text_field( $params['field'] ?? '' );
        $new_value   = sanitize_text_field( $params['new_value'] ?? '' );
        $reason      = sanitize_text_field( $params['reason'] ?? '' );

        if ( ! $field || ! $consult_id || ! $progress_id ) {
            return new WP_Error( 'invalid', 'Field, consultation ID, and progress ID required.', array( 'status' => 400 ) );
        }

        global $wpdb;

        // Get current value from form progress (Stage 1 or Stage 3)
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT stage1_data, stage3_data FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d",
            $progress_id
        ) );

        if ( ! $progress ) {
            return new WP_Error( 'not_found', 'Assessment not found.', array( 'status' => 404 ) );
        }

        $s1 = json_decode( $progress->stage1_data, true ) ?: array();
        $s3 = json_decode( $progress->stage3_data, true ) ?: array();
        $original = $s3[ $field ] ?? $s1[ $field ] ?? '';

        // Log the change in consultation notes
        $consult_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT health_data_changes FROM {$wpdb->prefix}hdlv2_consultation_notes WHERE id = %d AND practitioner_user_id = %d",
            $consult_id, get_current_user_id()
        ) );

        if ( ! $consult_row ) {
            return new WP_Error( 'not_found', 'Consultation not found.', array( 'status' => 404 ) );
        }

        $changes = json_decode( $consult_row->health_data_changes, true ) ?: array();
        $changes[] = array(
            'field'      => $field,
            'original'   => $original,
            'new_value'  => $new_value,
            'reason'     => $reason,
            'changed_by' => get_current_user_id(),
            'timestamp'  => current_time( 'mysql' ),
        );

        $wpdb->update(
            $wpdb->prefix . 'hdlv2_consultation_notes',
            array( 'health_data_changes' => wp_json_encode( $changes ) ),
            array( 'id' => $consult_id )
        );

        // Also update the actual stage data so recalculation uses new values
        // Determine which stage the field belongs to
        // V2 Stage 1 fields: q1_age, q1_sex, q2a, q2b, q3-q9
        $s1_fields = array( 'q1_age', 'q1_sex', 'q2a', 'q2b', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8', 'q9' );
        if ( in_array( $field, $s1_fields, true ) ) {
            $s1[ $field ] = $new_value;
            $wpdb->update(
                $wpdb->prefix . 'hdlv2_form_progress',
                array( 'stage1_data' => wp_json_encode( $s1 ) ),
                array( 'id' => $progress_id )
            );
        } else {
            $s3[ $field ] = $new_value;
            $wpdb->update(
                $wpdb->prefix . 'hdlv2_form_progress',
                array( 'stage3_data' => wp_json_encode( $s3 ) ),
                array( 'id' => $progress_id )
            );
        }

        return rest_ensure_response( array(
            'success'  => true,
            'field'    => $field,
            'original' => $original,
            'new_value' => $new_value,
            'changes'  => $changes,
        ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: FINALISE (trigger Final Report)
    // ──────────────────────────────────────────────────────────────

    public function rest_finalise( $request ) {
        $params      = $request->get_json_params();
        $progress_id = (int) ( $params['progress_id'] ?? 0 );
        $consult_id  = (int) ( $params['consultation_id'] ?? 0 );

        if ( ! $progress_id || ! $consult_id ) {
            return new WP_Error( 'invalid', 'Progress ID and consultation ID required.', array( 'status' => 400 ) );
        }

        // Delegate to the Final Report generator
        $result = HDLV2_Final_Report::generate( $progress_id, $consult_id, get_current_user_id() );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    // ──────────────────────────────────────────────────────────────
    //  SHORTCODE
    // ──────────────────────────────────────────────────────────────

    public function render_shortcode( $atts ) {
        if ( ! is_user_logged_in() || ! HDLV2_Compatibility::is_practitioner( get_current_user_id() ) ) {
            return '<p>You must be logged in as a practitioner to access the consultation interface.</p>';
        }

        wp_enqueue_script(
            'hdlv2-consultation',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-consultation.js',
            array( 'hdlv2-speedometer' ),
            HDLV2_VERSION,
            true
        );

        wp_enqueue_style(
            'hdlv2-consultation',
            HDLV2_PLUGIN_URL . 'assets/css/hdlv2-consultation.css',
            array(),
            HDLV2_VERSION
        );

        wp_localize_script( 'hdlv2-consultation', 'hdlv2_consult', array(
            'api_base' => rest_url( 'hdl-v2/v1/consultation' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
        ) );

        return '<div id="hdlv2-consultation" class="hdlv2-consultation-root"></div>';
    }

    // ──────────────────────────────────────────────────────────────
    //  HELPERS
    // ──────────────────────────────────────────────────────────────

    private function get_or_create_consultation( $progress ) {
        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_consultation_notes';

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE form_progress_id = %d AND practitioner_user_id = %d AND status != 'report_generated' ORDER BY id DESC LIMIT 1",
            $progress->id,
            get_current_user_id()
        ) );

        if ( $existing ) return $existing;

        $wpdb->insert( $table, array(
            'client_user_id'      => $progress->client_user_id ?: 0,
            'practitioner_user_id'=> get_current_user_id(),
            'form_progress_id'    => $progress->id,
            'started_at'          => current_time( 'mysql' ),
            'status'              => 'in_progress',
        ) );

        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $wpdb->insert_id ) );
    }
}
