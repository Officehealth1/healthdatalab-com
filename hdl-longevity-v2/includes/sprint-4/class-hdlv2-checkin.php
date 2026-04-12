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
        ) );
    }

    // ── REST: Submit check-in for AI processing ──
    public function rest_submit( $request ) {
        $params   = $request->get_json_params();
        $progress = $this->get_progress_from_token( $params['token'] ?? '' );
        if ( is_wp_error( $progress ) ) return $progress;

        $text = sanitize_textarea_field( $params['text'] ?? '' );
        $audio_summary = $params['audio_summary'] ?? '';
        $input = $audio_summary ?: $text;

        if ( ! $input ) {
            return new WP_Error( 'no_input', 'Please provide text or audio.', array( 'status' => 400 ) );
        }

        // Extract via AI
        $summary = HDLV2_Audio_Service::get_instance()->process_text( $input, 'weekly_checkin' );
        if ( is_wp_error( $summary ) ) return $summary;

        // Parse flags and adherence from summary
        $parsed    = $this->parse_summary( $summary );
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
            'raw_input'        => $input,
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
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_checkins',
            array( 'status' => 'confirmed', 'confirmed_at' => current_time( 'mysql' ) ),
            array( 'id' => $checkin_id, 'client_id' => $progress->client_user_id )
        );

        // Add timeline entry
        if ( class_exists( 'HDLV2_Timeline' ) ) {
            HDLV2_Timeline::add_entry(
                $progress->client_user_id, $progress->practitioner_user_id,
                'checkin', 'Weekly check-in confirmed', '', null,
                'hdlv2_checkins', $checkin_id
            );
        }

        return rest_ensure_response( array( 'success' => true ) );
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
            "SELECT DISTINCT client_user_id, client_email, client_name, practitioner_user_id
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

            wp_mail(
                $c->client_email,
                'Time for your weekly check-in — HealthDataLab',
                sprintf( "Hi %s,\n\nHow did this week go? Take a few minutes to reflect on your progress.\n\n— HealthDataLab", $c->client_name ?: 'there' ),
                array( 'Content-Type: text/html; charset=UTF-8' )
            );
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
        $decoded = json_decode( $raw_summary, true );
        if ( ! is_array( $decoded ) ) {
            $decoded = array( 'raw' => $raw_summary );
        }

        $has_flags = ! empty( $decoded['has_flags'] ) || ! empty( $decoded['flags'] );
        $flags     = $decoded['flags'] ?? array();
        if ( is_string( $flags ) ) $flags = array( $flags );

        // Extract comfort zone if present
        $cz = 'about_right';
        if ( isset( $decoded['comfort_zone'] ) ) {
            $czv = strtolower( $decoded['comfort_zone'] );
            if ( strpos( $czv, 'easy' ) !== false ) $cz = 'too_easy';
            elseif ( strpos( $czv, 'hard' ) !== false ) $cz = 'too_hard';
        }

        // Extract adherence estimates
        $adherence = array( 'overall' => 50, 'movement' => 50, 'nutrition' => 50, 'key_actions' => 50 );
        if ( isset( $decoded['fitness'] ) && is_string( $decoded['fitness'] ) ) {
            // Simple heuristic — will be refined by practitioner
            $adherence['movement'] = 50;
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

        // Find clients whose last final report (or form creation) is 3+ months old
        $clients = $wpdb->get_results(
            "SELECT fp.client_user_id, fp.client_name, fp.client_email, fp.practitioner_user_id, fp.created_at,
                    COALESCE(
                        (SELECT MAX(r.created_at) FROM {$wpdb->prefix}hdlv2_reports r WHERE r.client_user_id = fp.client_user_id AND r.report_type IN ('final','quarterly')),
                        fp.stage3_completed_at
                    ) AS last_assessment
             FROM {$wpdb->prefix}hdlv2_form_progress fp
             WHERE fp.stage3_completed_at IS NOT NULL
             AND fp.client_user_id IS NOT NULL
             GROUP BY fp.client_user_id
             HAVING last_assessment < '{$three_months_ago}'"
        );

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

            // Send email
            HDLV2_Email_Templates::quarterly_review_due( array(
                'practitioner_email' => $prac->user_email,
                'client_name'        => $c->client_name,
                'dashboard_url'      => admin_url( 'admin.php?page=hdl-dashboard' ),
            ) );

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
