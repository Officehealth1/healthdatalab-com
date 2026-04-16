<?php
/**
 * Client Status Calculator — V2 status labels for practitioner dashboard.
 *
 * @package HDL_Longevity_V2
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class HDLV2_Client_Status {

    const NOT_STARTED     = 'not_started';
    const LOW_DATA        = 'low_data';
    const AWAITING_CONSULT = 'awaiting_consult';
    const ACTIVE          = 'active';
    const PROGRESS_NORMAL = 'progress_normal';
    const NEEDS_ATTENTION = 'needs_attention';
    const INACTIVE        = 'inactive';

    const LABELS = array(
        'not_started'      => 'Not Started',
        'low_data'         => 'Low Data',
        'awaiting_consult' => 'Awaiting Consult',
        'active'           => 'Active',
        'progress_normal'  => 'Progress Normal',
        'needs_attention'  => 'Client Needs Attention',
        'inactive'         => 'Inactive',
    );

    const COLORS = array(
        'not_started'      => '#94a3b8',
        'low_data'         => '#f59e0b',
        'awaiting_consult' => '#3b82f6',
        'active'           => '#10b981',
        'progress_normal'  => '#10b981',
        'needs_attention'  => '#dc2626',
        'inactive'         => '#6b7280',
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
    }

    // ── Main calculation ──
    public static function calculate_status( $client_user_id ) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        // Get latest form progress
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$prefix}hdlv2_form_progress WHERE client_user_id = %d ORDER BY id DESC LIMIT 1",
            $client_user_id
        ) );

        if ( ! $progress || empty( $progress->stage1_completed_at ) ) {
            return self::build_result( self::NOT_STARTED );
        }

        if ( empty( $progress->stage3_completed_at ) ) {
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

        // Check recent check-ins for attention triggers
        $reasons = array();
        $recent_checkins = $wpdb->get_results( $wpdb->prepare(
            "SELECT has_flags, adherence_scores, week_start, status FROM {$prefix}hdlv2_checkins
             WHERE client_id = %d AND status = 'confirmed' ORDER BY week_start DESC LIMIT 4",
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

        // Missed check-ins (2+ weeks with no confirmed check-in)
        $last_confirmed = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(week_start) FROM {$prefix}hdlv2_checkins WHERE client_id = %d AND status = 'confirmed'",
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

        $result = array();
        foreach ( $clients as $client_id ) {
            $user   = get_userdata( $client_id );
            $status = self::calculate_status( $client_id );

            global $wpdb;
            $last_checkin = $wpdb->get_var( $wpdb->prepare(
                "SELECT MAX(confirmed_at) FROM {$wpdb->prefix}hdlv2_checkins WHERE client_id = %d AND status = 'confirmed'",
                $client_id
            ) );

            $result[] = array(
                'user_id'          => (int) $client_id,
                'name'             => $user ? $user->display_name : 'Unknown',
                'email'            => $user ? $user->user_email : '',
                'status'           => $status['status'],
                'label'            => $status['label'],
                'color'            => $status['color'],
                'reasons'          => $status['reasons'],
                'last_checkin_date' => $last_checkin,
            );
        }

        return rest_ensure_response( $result );
    }
}
