<?php
/**
 * Weekly Check-in — Client submits weekly reflection via text/audio.
 *
 * @package HDL_Longevity_V2
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class HDLV2_Checkin {

    private static $instance = null;
    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {}

    public function register_hooks() {
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'hdlv2_checkin_reminder', array( $this, 'send_reminders' ) );
        add_action( 'hdlv2_quarterly_review', array( $this, 'check_quarterly_reviews' ) );
        add_shortcode( 'hdlv2_checkin', array( $this, 'render_shortcode' ) );
    }

    public function register_rest_routes() {
        register_rest_route( 'hdl-v2/v1', '/checkin/load', array(
            'methods' => 'GET', 'callback' => array( $this, 'rest_load' ), 'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'hdl-v2/v1', '/checkin/submit', array(
            'methods' => 'POST', 'callback' => array( $this, 'rest_submit' ), 'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'hdl-v2/v1', '/checkin/confirm', array(
            'methods' => 'POST', 'callback' => array( $this, 'rest_confirm' ), 'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'hdl-v2/v1', '/checkin/history', array(
            'methods' => 'GET', 'callback' => array( $this, 'rest_history' ), 'permission_callback' => '__return_true',
        ) );
    }

    // ── REST: Load current week ──
    public function rest_load( $request ) {
        $progress = $this->get_progress_from_token( $request->get_param( 'token' ) );
        if ( is_wp_error( $progress ) ) return $progress;

        $week_start = $this->get_week_start();
        global $wpdb;
        $checkin = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_checkins WHERE client_id = %d AND week_start = %s LIMIT 1",
            $progress->client_user_id, $week_start
        ) );

        // Fetch current week's flight plan context for the check-in page
        $flight_plan = null;
        $fp_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, week_number, identity_statement, weekly_targets, adherence_summary, week_start FROM {$wpdb->prefix}hdlv2_flight_plans WHERE client_id = %d ORDER BY week_start DESC LIMIT 1",
            $progress->client_user_id
        ) );
        if ( $fp_row ) {
            $flight_plan = array(
                'id'                 => (int) $fp_row->id,
                'week_number'        => (int) $fp_row->week_number,
                'week_start'         => $fp_row->week_start,
                'identity_statement' => $fp_row->identity_statement,
                'weekly_targets'     => json_decode( $fp_row->weekly_targets, true ),
                'adherence_summary'  => $fp_row->adherence_summary ? json_decode( $fp_row->adherence_summary, true ) : null,
            );
        }

        return rest_ensure_response( array(
            'week_start'   => $week_start,
            'week_number'  => $this->get_week_number( $progress ),
            'checkin'      => $checkin ? array(
                'id'       => (int) $checkin->id,
                'summary'  => json_decode( $checkin->summary, true ),
                'status'   => $checkin->status,
                'has_flags' => (bool) $checkin->has_flags,
                'flags'    => json_decode( $checkin->flags, true ),
            ) : null,
            'flight_plan'  => $flight_plan,
        ) );
    }

    // ── REST: Submit check-in for AI processing ──
    public function rest_submit( $request ) {
        $params   = $request->get_json_params();
        $progress = $this->get_progress_from_token( $params['token'] ?? '' );
        if ( is_wp_error( $progress ) ) return $progress;

        $text = sanitize_textarea_field( $params['text'] ?? '' );
        $audio_summary = $params['audio_summary'] ?? '';

        // Determine input method
        $input_method = 'text';
        if ( ! empty( $params['audio_id'] ) || ! empty( $params['raw_transcript'] ) ) {
            $input_method = 'audio';
        }

        // Check if audio_summary is already structured JSON from the audio component
        $already_extracted = false;
        $summary = null;
        if ( $audio_summary ) {
            $decoded = is_string( $audio_summary ) ? json_decode( $audio_summary, true ) : $audio_summary;
            if ( is_array( $decoded ) && isset( $decoded['check_in_summary'] ) ) {
                // Already extracted by the audio component — use directly, skip second Claude call
                $summary = is_string( $audio_summary ) ? $audio_summary : wp_json_encode( $audio_summary );
                $already_extracted = true;
            }
        }

        $raw_input = $audio_summary ?: $text;
        if ( ! $raw_input ) {
            return new WP_Error( 'no_input', 'Please provide text or audio.', array( 'status' => 400 ) );
        }

        // "Add more" mode — append new input to existing draft and re-extract
        if ( ! empty( $params['append_mode'] ) ) {
            global $wpdb;
            $existing_raw = $wpdb->get_var( $wpdb->prepare(
                "SELECT raw_input FROM {$wpdb->prefix}hdlv2_checkins WHERE client_id = %d AND week_start = %s AND status = 'draft' LIMIT 1",
                $progress->client_user_id, $this->get_week_start()
            ) );
            if ( $existing_raw ) {
                $raw_input = $existing_raw . "\n\n--- Additional input ---\n\n" . $raw_input;
            }
            $already_extracted = false; // Force re-extraction on combined input
        }

        // "Not quite right" mode — prepend correction context
        if ( ! empty( $params['correction_mode'] ) && ! empty( $params['correction'] ) ) {
            $correction = sanitize_textarea_field( $params['correction'] );
            $raw_input = "CORRECTION FROM CLIENT: The client reviewed the previous AI summary and says: \"{$correction}\". Please regenerate the summary incorporating this feedback.\n\nORIGINAL INPUT:\n" . $raw_input;
            $already_extracted = false; // Force re-extraction with correction
        }

        // Only extract via AI if not already structured
        if ( ! $already_extracted ) {
            $summary = HDLV2_Audio_Service::get_instance()->process_text( $raw_input, 'weekly_checkin' );
            if ( is_wp_error( $summary ) ) return $summary;
        }

        // Parse flags and adherence from summary
        $parsed     = $this->parse_summary( $summary );
        $week_start = $this->get_week_start();
        $week_num   = $this->get_week_number( $progress );

        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_checkins';

        // Upsert — one check-in per week
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE client_id = %d AND week_start = %s",
            $progress->client_user_id, $week_start
        ) );

        $data = array(
            'client_id'        => $progress->client_user_id,
            'practitioner_id'  => $progress->practitioner_user_id,
            'week_number'      => $week_num,
            'week_start'       => $week_start,
            'raw_input'        => $raw_input,
            'input_method'     => $input_method,
            'summary'          => wp_json_encode( $parsed['summary'] ),
            'adherence_scores' => wp_json_encode( $parsed['adherence'] ),
            'comfort_zone'     => $parsed['comfort_zone'],
            'has_flags'        => $parsed['has_flags'] ? 1 : 0,
            'flags'            => wp_json_encode( $parsed['flags'] ),
            'status'           => 'draft',
        );

        if ( $existing ) {
            $wpdb->update( $table, $data, array( 'id' => $existing ) );
            $checkin_id = (int) $existing;
        } else {
            $wpdb->insert( $table, $data );
            $checkin_id = (int) $wpdb->insert_id;
        }

        return rest_ensure_response( array(
            'success'    => true,
            'checkin_id' => $checkin_id,
            'summary'    => $parsed['summary'],
            'has_flags'  => $parsed['has_flags'],
            'flags'      => $parsed['flags'],
        ) );
    }

    // ── REST: Confirm check-in ──
    public function rest_confirm( $request ) {
        $params   = $request->get_json_params();
        $progress = $this->get_progress_from_token( $params['token'] ?? '' );
        if ( is_wp_error( $progress ) ) return $progress;

        $checkin_id = absint( $params['checkin_id'] ?? 0 );
        if ( ! $checkin_id ) {
            return new WP_Error( 'missing_id', 'Check-in ID required.', array( 'status' => 400 ) );
        }

        global $wpdb;
        $checkin_table = $wpdb->prefix . 'hdlv2_checkins';

        // Set confirmed
        $wpdb->update(
            $checkin_table,
            array( 'status' => 'confirmed', 'confirmed_at' => current_time( 'mysql' ) ),
            array( 'id' => $checkin_id, 'client_id' => $progress->client_user_id )
        );

        // Reload the confirmed checkin
        $checkin = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $checkin_table WHERE id = %d", $checkin_id
        ) );
        if ( ! $checkin ) {
            return new WP_Error( 'not_found', 'Check-in not found.', array( 'status' => 404 ) );
        }

        $summary_data = json_decode( $checkin->summary, true ) ?: array();

        // 4.1 — Enriched timeline entry
        $wpdb->insert( $wpdb->prefix . 'hdlv2_timeline', array(
            'client_id'      => $checkin->client_id,
            'practitioner_id' => $checkin->practitioner_id,
            'entry_type'     => 'checkin_summary',
            'title'          => sprintf( 'Week %d check-in', $checkin->week_number ),
            'date'           => $checkin->week_start,
            'end_date'       => date( 'Y-m-d', strtotime( $checkin->week_start . ' +6 days' ) ),
            'temporal_type'  => 'interval',
            'category'       => 'system',
            'source'         => $checkin->input_method ?: 'system',
            'summary'        => $summary_data['check_in_summary'] ?? '',
            'detail'         => $checkin->summary,
            'has_flags'      => $checkin->has_flags ? 1 : 0,
            'flags'          => $checkin->flags,
            'source_table'   => 'hdlv2_checkins',
            'source_id'      => $checkin_id,
            'created_at'     => current_time( 'mysql' ),
        ) );

        // 4.2 — Re-evaluate flags on confirmed check-in
        $this->evaluate_flags( $checkin );

        // 4.3 — Trigger flight plan generation (async, 30s delay)
        if ( ! wp_next_scheduled( 'hdlv2_generate_single_flight_plan', array( (int) $checkin->client_id ) ) ) {
            wp_schedule_single_event(
                time() + 30,
                'hdlv2_generate_single_flight_plan',
                array( (int) $checkin->client_id )
            );
        }

        return rest_ensure_response( array( 'success' => true ) );
    }

    /**
     * Evaluate flags on a confirmed check-in.
     * Updates the checkin record and notifies practitioner if flags detected.
     */
    private function evaluate_flags( $checkin ) {
        global $wpdb;
        $summary_data = json_decode( $checkin->summary, true ) ?: array();
        $flags = json_decode( $checkin->flags, true ) ?: array();

        // Use AI-extracted flags as primary source
        if ( ! empty( $summary_data['flags'] ) && is_array( $summary_data['flags'] ) ) {
            $flags = $summary_data['flags'];
        }

        // Check adherence trend — ≤3/10 for 2+ consecutive weeks
        $prev_checkin = $wpdb->get_row( $wpdb->prepare(
            "SELECT adherence_scores FROM {$wpdb->prefix}hdlv2_checkins
             WHERE client_id = %d AND status = 'confirmed' AND id != %d
             ORDER BY week_start DESC LIMIT 1",
            $checkin->client_id, $checkin->id
        ) );

        if ( $prev_checkin ) {
            $prev_scores = json_decode( $prev_checkin->adherence_scores, true ) ?: array();
            $curr_scores = json_decode( $checkin->adherence_scores, true ) ?: array();
            $prev_overall = ( $prev_scores['overall'] ?? 50 );
            $curr_overall = ( $curr_scores['overall'] ?? 50 );

            // Both weeks ≤30% (equivalent to ≤3/10 scaled)
            if ( $prev_overall <= 30 && $curr_overall <= 30 ) {
                $flags[] = array( 'trigger' => 'Low adherence for 2+ consecutive weeks', 'severity' => 'medium', 'detail' => sprintf( 'Current: %d%%, Previous: %d%%', $curr_overall, $prev_overall ) );
            }
        }

        // Check for missed check-ins (2+ weeks gap)
        $last_confirmed = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(week_start) FROM {$wpdb->prefix}hdlv2_checkins
             WHERE client_id = %d AND status = 'confirmed' AND id != %d",
            $checkin->client_id, $checkin->id
        ) );
        if ( $last_confirmed ) {
            $gap_weeks = floor( ( strtotime( $checkin->week_start ) - strtotime( $last_confirmed ) ) / ( 7 * DAY_IN_SECONDS ) );
            if ( $gap_weeks >= 3 ) {
                $flags[] = array( 'trigger' => 'Missed check-ins', 'severity' => 'medium', 'detail' => sprintf( '%d weeks since last check-in', $gap_weeks ) );
            }
        }

        $has_flags = ! empty( $flags );

        // Update the checkin record
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_checkins',
            array( 'has_flags' => $has_flags ? 1 : 0, 'flags' => wp_json_encode( $flags ) ),
            array( 'id' => $checkin->id )
        );

        // 4.4 — Notify practitioner if flags detected
        if ( $has_flags && $checkin->practitioner_id ) {
            $client_name = $wpdb->get_var( $wpdb->prepare(
                "SELECT client_name FROM {$wpdb->prefix}hdlv2_form_progress WHERE client_user_id = %d ORDER BY id DESC LIMIT 1",
                $checkin->client_id
            ) ) ?: 'A client';

            $flag_reasons = array();
            foreach ( $flags as $f ) {
                $flag_reasons[] = ( is_array( $f ) ? ( $f['trigger'] ?? $f['detail'] ?? '' ) : $f );
            }

            $prac = get_userdata( $checkin->practitioner_id );
            if ( $prac && $prac->user_email ) {
                HDLV2_Email_Templates::client_needs_attention( array(
                    'practitioner_email' => $prac->user_email,
                    'client_name'        => $client_name,
                    'reasons'            => $flag_reasons,
                    'timeline_url'       => site_url( '/client-tracker/?client_id=' . $checkin->client_id ),
                ) );
            }

            // Update client status
            if ( class_exists( 'HDLV2_Client_Status' ) ) {
                // Status is calculated on-the-fly — the flag data in the DB drives it
            }
        }
    }

    // ── REST: History ──
    public function rest_history( $request ) {
        $progress = $this->get_progress_from_token( $request->get_param( 'token' ) );
        if ( is_wp_error( $progress ) ) return $progress;

        $page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
        $per_page = min( 50, max( 1, (int) $request->get_param( 'per_page' ) ?: 10 ) );
        $offset   = ( $page - 1 ) * $per_page;

        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_checkins';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, week_number, week_start, summary, adherence_scores, comfort_zone, has_flags, flags, status, confirmed_at
             FROM $table WHERE client_id = %d AND status = 'confirmed' ORDER BY week_start DESC LIMIT %d OFFSET %d",
            $progress->client_user_id, $per_page, $offset
        ) );

        $items = array();
        foreach ( $rows as $r ) {
            $items[] = array(
                'id'          => (int) $r->id,
                'week_number' => (int) $r->week_number,
                'week_start'  => $r->week_start,
                'summary'     => json_decode( $r->summary, true ),
                'adherence'   => json_decode( $r->adherence_scores, true ),
                'comfort_zone' => $r->comfort_zone,
                'has_flags'   => (bool) $r->has_flags,
                'confirmed_at' => $r->confirmed_at,
            );
        }

        return rest_ensure_response( $items );
    }

    // ── Shortcode ──
    public function render_shortcode( $atts ) {
        wp_enqueue_script( 'hdlv2-audio-component', HDLV2_PLUGIN_URL . 'assets/js/hdlv2-audio-component.js', array(), HDLV2_VERSION, true );
        wp_enqueue_script( 'hdlv2-checkin', HDLV2_PLUGIN_URL . 'assets/js/hdlv2-checkin.js', array( 'hdlv2-audio-component' ), HDLV2_VERSION, true );
        wp_enqueue_style( 'hdlv2-form', HDLV2_PLUGIN_URL . 'assets/css/hdlv2-form.css', array(), HDLV2_VERSION );
        wp_localize_script( 'hdlv2-checkin', 'hdlv2_checkin', array(
            'api_base'   => rest_url( 'hdl-v2/v1/checkin' ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
        ) );
        return '<div id="hdlv2-checkin" class="hdlv2-assessment-root"></div>';
    }

    // ── Cron: Send weekly reminders ──
    public function send_reminders() {
        global $wpdb;
        // Get all active clients with completed stages
        $clients = $wpdb->get_results(
            "SELECT DISTINCT client_user_id, client_email, client_name, practitioner_user_id, token
             FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE stage3_completed_at IS NOT NULL AND client_email != ''"
        );

        $week_start = $this->get_week_start();
        foreach ( $clients as $c ) {
            // Skip if already checked in this week
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}hdlv2_checkins WHERE client_id = %d AND week_start = %s AND status = 'confirmed'",
                $c->client_user_id, $week_start
            ) );
            if ( $exists ) continue;

            HDLV2_Email_Templates::checkin_reminder( array(
                'client_name'  => $c->client_name ?: 'there',
                'client_email' => $c->client_email,
                'checkin_url'  => site_url( '/weekly-check-in/?token=' . $c->token ),
            ) );
        }
    }

    // ── Helpers ──
    private function get_progress_from_token( $token ) {
        if ( ! $token || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            return new WP_Error( 'invalid_token', 'Invalid token.', array( 'status' => 401 ) );
        }
        global $wpdb;
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_form_progress WHERE token = %s", $token
        ) );
        if ( ! $progress ) {
            return new WP_Error( 'not_found', 'Assessment not found.', array( 'status' => 404 ) );
        }
        return $progress;
    }

    private function get_week_start() {
        return date( 'Y-m-d', strtotime( 'monday this week' ) );
    }

    private function get_week_number( $progress ) {
        $start = strtotime( $progress->created_at );
        $now   = time();
        return max( 1, (int) ceil( ( $now - $start ) / ( 7 * DAY_IN_SECONDS ) ) );
    }

    private function parse_summary( $raw_summary ) {
        if ( is_string( $raw_summary ) ) {
            // Strip markdown code fences if present (```json ... ```)
            $raw_summary = preg_replace( '/^\s*```(?:json)?\s*/i', '', $raw_summary );
            $raw_summary = preg_replace( '/\s*```\s*$/', '', $raw_summary );
        }
        $decoded = is_array( $raw_summary ) ? $raw_summary : json_decode( $raw_summary, true );
        if ( ! is_array( $decoded ) ) {
            $decoded = array( 'raw' => $raw_summary );
        }

        // Extract flags
        $flags = $decoded['flags'] ?? array();
        if ( is_string( $flags ) ) $flags = array( $flags );
        $has_flags = ! empty( $flags );

        // Extract adherence scores from new format (1-10 scale → percentages)
        $fitness_score   = $decoded['fitness_adherence']['score'] ?? null;
        $nutrition_score = $decoded['nutrition_adherence']['score'] ?? null;

        $movement  = $fitness_score !== null ? (int) $fitness_score * 10 : 50;
        $nutrition = $nutrition_score !== null ? (int) $nutrition_score * 10 : 50;
        $key_actions = (int) round( ( $movement + $nutrition ) / 2 ); // Average until tick data exists
        $overall     = (int) round( ( $movement + $nutrition + $key_actions ) / 3 );

        $adherence = array(
            'overall'     => $overall,
            'movement'    => $movement,
            'nutrition'   => $nutrition,
            'key_actions' => $key_actions,
        );

        // Extract comfort zone from structured object
        $cz = 'about_right';
        if ( isset( $decoded['comfort_zone'] ) ) {
            if ( is_array( $decoded['comfort_zone'] ) ) {
                // New structured format: determine overall from array lengths
                $too_easy = count( $decoded['comfort_zone']['too_easy'] ?? array() );
                $too_hard = count( $decoded['comfort_zone']['too_hard'] ?? array() );
                $about_right = count( $decoded['comfort_zone']['about_right'] ?? array() );
                if ( $too_hard > $too_easy && $too_hard > $about_right ) $cz = 'too_hard';
                elseif ( $too_easy > $too_hard && $too_easy > $about_right ) $cz = 'too_easy';
            } elseif ( is_string( $decoded['comfort_zone'] ) ) {
                // Legacy flat format
                $czv = strtolower( $decoded['comfort_zone'] );
                if ( strpos( $czv, 'easy' ) !== false ) $cz = 'too_easy';
                elseif ( strpos( $czv, 'hard' ) !== false ) $cz = 'too_hard';
            }
        }

        return array(
            'summary'      => $decoded,
            'has_flags'    => $has_flags,
            'flags'        => $flags,
            'comfort_zone' => $cz,
            'adherence'    => $adherence,
        );
    }

    // ── Cron: Quarterly review check (Phase 8) ──
    public function check_quarterly_reviews() {
        global $wpdb;
        $three_months_ago = date( 'Y-m-d H:i:s', strtotime( '-3 months' ) );

        // Find clients whose last final report (or form creation) is 3+ months old.
        // A client can have multiple form_progress rows (multi-practitioner case),
        // so an inner subquery picks the single latest row per client_user_id before
        // joining — guarantees token and practitioner_user_id come from the same row
        // (otherwise GROUP BY would return indeterminate values across columns).
        $clients = $wpdb->get_results( $wpdb->prepare(
            "SELECT fp.client_user_id, fp.client_name, fp.client_email, fp.practitioner_user_id, fp.token, fp.created_at,
                    COALESCE(
                        (SELECT MAX(r.created_at) FROM {$wpdb->prefix}hdlv2_reports r WHERE r.client_user_id = fp.client_user_id AND r.report_type IN ('final','quarterly')),
                        fp.stage3_completed_at
                    ) AS last_assessment
             FROM {$wpdb->prefix}hdlv2_form_progress fp
             INNER JOIN (
                 SELECT client_user_id, MAX(id) AS latest_id
                 FROM {$wpdb->prefix}hdlv2_form_progress
                 WHERE stage3_completed_at IS NOT NULL AND client_user_id IS NOT NULL
                 GROUP BY client_user_id
             ) latest ON latest.client_user_id = fp.client_user_id AND latest.latest_id = fp.id
             HAVING last_assessment < %s",
            $three_months_ago
        ) );

        foreach ( $clients as $c ) {
            // Check we haven't already sent a reminder recently
            $recent_reminder = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}hdlv2_timeline WHERE client_id = %d AND entry_type = 'milestone' AND title LIKE '%%Quarterly review due%%' AND created_at > %s LIMIT 1",
                $c->client_user_id, date( 'Y-m-d', strtotime( '-7 days' ) )
            ) );
            if ( $recent_reminder ) continue;

            // Get practitioner email
            $prac = get_userdata( $c->practitioner_user_id );
            if ( ! $prac ) continue;

            // Send email to practitioner
            HDLV2_Email_Templates::quarterly_review_due( array(
                'practitioner_email' => $prac->user_email,
                'client_name'        => $c->client_name,
                'dashboard_url'      => admin_url( 'admin.php?page=hdl-dashboard' ),
            ) );

            // Send email to client
            if ( $c->client_email ) {
                HDLV2_Email_Templates::quarterly_review_client( array(
                    'client_name'  => $c->client_name ?: 'there',
                    'client_email' => $c->client_email,
                    'review_url'   => site_url( '/my-flight-plan/?token=' . $c->token ),
                ) );
            }

            // Timeline entry
            if ( class_exists( 'HDLV2_Timeline' ) ) {
                HDLV2_Timeline::add_entry(
                    $c->client_user_id, $c->practitioner_user_id,
                    'milestone', 'Quarterly review due',
                    'Three months since last assessment. Time to schedule a review.'
                );
            }
        }
    }
}
