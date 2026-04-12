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
        add_shortcode( 'hdlv2_flight_plan', array( $this, 'render_shortcode' ) );
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
    }

    // ── REST: Current plan ──
    public function rest_current( $request ) {
        $client_id = absint( $request['client_id'] );
        global $wpdb;
        $plan = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_flight_plans WHERE client_id = %d ORDER BY week_start DESC LIMIT 1",
            $client_id
        ) );
        if ( ! $plan ) {
            return rest_ensure_response( array( 'plan' => null ) );
        }

        $ticks = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_flight_plan_ticks WHERE flight_plan_id = %d ORDER BY day_of_week, time_slot",
            $plan->id
        ) );

        return rest_ensure_response( array(
            'plan' => array(
                'id'                  => (int) $plan->id,
                'week_number'         => (int) $plan->week_number,
                'week_start'          => $plan->week_start,
                'plan_data'           => json_decode( $plan->plan_data, true ),
                'shopping_list'       => json_decode( $plan->shopping_list, true ),
                'identity_statement'  => $plan->identity_statement,
                'weekly_targets'      => json_decode( $plan->weekly_targets, true ),
                'journey_assistance'  => $plan->journey_assistance,
                'status'              => $plan->status,
            ),
            'ticks' => array_map( function ( $t ) {
                return array(
                    'id'          => (int) $t->id,
                    'day_of_week' => (int) $t->day_of_week,
                    'time_slot'   => $t->time_slot,
                    'category'    => $t->category,
                    'action_text' => $t->action_text,
                    'ticked'      => (bool) $t->ticked,
                );
            }, $ticks ),
        ) );
    }

    // ── REST: Generate ──
    public function rest_generate( $request ) {
        $client_id = absint( $request['client_id'] );
        $prac_id   = get_current_user_id();
        $result    = $this->generate( $client_id, $prac_id );
        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( array( 'success' => true, 'plan_id' => $result ) );
    }

    // ── REST: Tick ──
    public function rest_tick( $request ) {
        $params = $request->get_json_params();
        $tick_id = absint( $params['tick_id'] ?? 0 );
        $ticked  = ! empty( $params['ticked'] );
        if ( ! $tick_id ) return new WP_Error( 'missing', 'Tick ID required.', array( 'status' => 400 ) );

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_flight_plan_ticks',
            array( 'ticked' => $ticked ? 1 : 0, 'ticked_at' => $ticked ? current_time( 'mysql' ) : null ),
            array( 'id' => $tick_id )
        );
        return rest_ensure_response( array( 'success' => true ) );
    }

    // ── REST: Tick all ──
    public function rest_tick_all( $request ) {
        $params  = $request->get_json_params();
        $plan_id = absint( $params['flight_plan_id'] ?? 0 );
        $ticked  = ! empty( $params['ticked'] );
        if ( ! $plan_id ) return new WP_Error( 'missing', 'Plan ID required.', array( 'status' => 400 ) );

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_flight_plan_ticks',
            array( 'ticked' => $ticked ? 1 : 0, 'ticked_at' => $ticked ? current_time( 'mysql' ) : null ),
            array( 'flight_plan_id' => $plan_id )
        );
        return rest_ensure_response( array( 'success' => true ) );
    }

    // ── REST: Adherence ──
    public function rest_adherence( $request ) {
        $client_id = absint( $request['client_id'] );
        global $wpdb;
        $plans = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, week_number, week_start FROM {$wpdb->prefix}hdlv2_flight_plans WHERE client_id = %d ORDER BY week_start DESC LIMIT 8",
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
        $page = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
        $offset = ( $page - 1 ) * 10;

        global $wpdb;
        $plans = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, week_number, week_start, identity_statement, status, created_at FROM {$wpdb->prefix}hdlv2_flight_plans WHERE client_id = %d ORDER BY week_start DESC LIMIT 10 OFFSET %d",
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

    // ── Core: Generate flight plan ──
    public function generate( $client_id, $practitioner_id ) {
        $api_key = defined( 'HDLV2_ANTHROPIC_API_KEY' ) ? HDLV2_ANTHROPIC_API_KEY : '';
        if ( ! $api_key ) return new WP_Error( 'no_key', 'Anthropic API key not configured.' );

        // Gather context
        $context = $this->build_context( $client_id );
        $week_start = date( 'Y-m-d', strtotime( 'monday this week' ) );
        $week_num = $context['week_number'];

        $prompt = $this->build_prompt( $context );

        // Call Claude
        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'x-api-key' => $api_key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model' => 'claude-sonnet-4-20250514', 'max_tokens' => 4000,
                'system' => $prompt['system'], 'messages' => array( array( 'role' => 'user', 'content' => $prompt['user'] ) ),
            ) ),
            'timeout' => 120,
        ) );

        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 ) return new WP_Error( 'api_error', $body['error']['message'] ?? "HTTP $code" );

        $content = $body['content'][0]['text'] ?? '';
        $plan = json_decode( $content, true );
        if ( ! $plan ) $plan = array( 'raw' => $content );

        // Store
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'hdlv2_flight_plans', array(
            'client_id'           => $client_id,
            'practitioner_id'     => $practitioner_id,
            'week_number'         => $week_num,
            'week_start'          => $week_start,
            'plan_data'           => wp_json_encode( $plan['daily_plan'] ?? $plan ),
            'shopping_list'       => wp_json_encode( $plan['shopping_list'] ?? array() ),
            'identity_statement'  => sanitize_text_field( $plan['identity_statement'] ?? '' ),
            'weekly_targets'      => wp_json_encode( $plan['weekly_targets'] ?? array() ),
            'journey_assistance'  => sanitize_textarea_field( $plan['journey_assistance'] ?? '' ),
            'status'              => 'generated',
        ) );
        $plan_id = (int) $wpdb->insert_id;

        // Create tick rows
        $this->create_tick_rows( $plan_id, $client_id, $plan );

        // Timeline entry
        if ( class_exists( 'HDLV2_Timeline' ) ) {
            HDLV2_Timeline::add_entry( $client_id, $practitioner_id, 'flight_plan', "Week $week_num Flight Plan generated", '', null, 'hdlv2_flight_plans', $plan_id );
        }

        return $plan_id;
    }

    private function create_tick_rows( $plan_id, $client_id, $plan ) {
        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_flight_plan_ticks';
        $daily = $plan['daily_plan'] ?? $plan;
        if ( ! is_array( $daily ) ) return;

        $days = array( 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7 );
        foreach ( $daily as $day_name => $slots ) {
            $dow = $days[ strtolower( $day_name ) ] ?? 0;
            if ( ! $dow || ! is_array( $slots ) ) continue;
            foreach ( $slots as $slot => $actions ) {
                if ( ! is_array( $actions ) ) continue;
                foreach ( $actions as $action ) {
                    if ( ! is_array( $action ) ) continue;
                    $cat = $action['category'] ?? 'key_action';
                    if ( ! in_array( $cat, array( 'movement', 'nutrition', 'key_action' ), true ) ) $cat = 'key_action';
                    $wpdb->insert( $table, array(
                        'flight_plan_id' => $plan_id,
                        'client_id'      => $client_id,
                        'day_of_week'    => $dow,
                        'time_slot'      => sanitize_text_field( $slot ),
                        'category'       => $cat,
                        'action_text'    => sanitize_text_field( $action['text'] ?? '' ),
                    ) );
                }
            }
        }
    }

    public function calculate_adherence( $plan_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_flight_plan_ticks';
        $cats = array( 'movement', 'nutrition', 'key_action' );
        $scores = array();

        foreach ( $cats as $cat ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE flight_plan_id = %d AND category = %s", $plan_id, $cat ) );
            $done  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE flight_plan_id = %d AND category = %s AND ticked = 1", $plan_id, $cat ) );
            $scores[ $cat ] = $total > 0 ? round( $done / $total * 100 ) : 0;
        }

        $total_all = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE flight_plan_id = %d", $plan_id ) );
        $done_all  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE flight_plan_id = %d AND ticked = 1", $plan_id ) );
        $scores['overall'] = $total_all > 0 ? round( $done_all / $total_all * 100 ) : 0;

        return $scores;
    }

    // ── Context builder (simplified — Phase 6 adds full tiered version) ──
    private function build_context( $client_id ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}hdlv2_form_progress WHERE client_user_id = %d ORDER BY id DESC LIMIT 1", $client_id
        ) );

        $why = $wpdb->get_row( $wpdb->prepare(
            "SELECT distilled_why, key_people, motivations, fears FROM {$p}hdlv2_why_profiles WHERE client_user_id = %d ORDER BY id DESC LIMIT 1", $client_id
        ) );

        $report = $wpdb->get_row( $wpdb->prepare(
            "SELECT report_content, milestones FROM {$p}hdlv2_reports WHERE client_user_id = %d AND report_type = 'final' ORDER BY id DESC LIMIT 1", $client_id
        ) );

        $checkins = $wpdb->get_results( $wpdb->prepare(
            "SELECT summary, adherence_scores, comfort_zone FROM {$p}hdlv2_checkins WHERE client_id = %d AND status = 'confirmed' ORDER BY week_start DESC LIMIT 4", $client_id
        ) );

        $prev_plan = $wpdb->get_row( $wpdb->prepare(
            "SELECT shopping_list FROM {$p}hdlv2_flight_plans WHERE client_id = %d ORDER BY week_start DESC LIMIT 1", $client_id
        ) );

        $notes = $wpdb->get_results( $wpdb->prepare(
            "SELECT typed_notes, recommendations FROM {$p}hdlv2_consultation_notes WHERE client_user_id = %d ORDER BY id DESC LIMIT 3", $client_id
        ) );

        $week_num = 1;
        if ( $progress ) {
            $week_num = max( 1, (int) ceil( ( time() - strtotime( $progress->created_at ) ) / ( 7 * DAY_IN_SECONDS ) ) );
        }

        return array(
            'week_number'     => $week_num,
            'client_name'     => $progress->client_name ?? '',
            'why_profile'     => $why,
            'report'          => $report,
            'checkins'        => $checkins,
            'prev_shopping'   => $prev_plan ? json_decode( $prev_plan->shopping_list, true ) : array(),
            'notes'           => $notes,
        );
    }

    private function build_prompt( $context ) {
        $checkin_text = '';
        foreach ( $context['checkins'] as $i => $ci ) {
            $checkin_text .= "Week -" . ( $i + 1 ) . ": " . ( $ci->summary ?: '(none)' ) . "\n";
        }

        $notes_text = '';
        foreach ( $context['notes'] as $n ) {
            $notes_text .= $n->typed_notes . "\n";
        }

        return array(
            'system' => 'You are generating a personalised Weekly Flight Plan for a longevity client. Return valid JSON with keys: daily_plan (object with monday-sunday, each containing time_slot objects with actions), identity_statement, weekly_targets (array), shopping_list (array), journey_assistance (string).',
            'user' => "Generate Week {$context['week_number']} flight plan for {$context['client_name']}.

PRACTITIONER NOTES (HIGHEST PRIORITY):
{$notes_text}

WHY PROFILE:
" . ( $context['why_profile'] ? $context['why_profile']->distilled_why : '(not available)' ) . "

REPORT DATA:
" . ( $context['report'] ? substr( $context['report']->report_content, 0, 1000 ) : '(not available)' ) . "

MILESTONES:
" . ( $context['report'] ? $context['report']->milestones : '(not set)' ) . "

RECENT CHECK-INS:
{$checkin_text}

PREVIOUS SHOPPING LIST:
" . wp_json_encode( $context['prev_shopping'] ) . "

WEEK NUMBER: {$context['week_number']}

Rules: practitioner notes highest priority. Progressive challenge. Shopping list consistent with daily nutrition. All actions have category (movement/nutrition/key_action) and text. WHY anchors personalised. If bad week, reduce targets + stronger WHY anchor.",
        );
    }

    // ── Cron: Generate for all active clients ──
    public function cron_generate_all() {
        global $wpdb;
        $clients = $wpdb->get_results(
            "SELECT DISTINCT fp.client_user_id, fp.practitioner_user_id
             FROM {$wpdb->prefix}hdlv2_form_progress fp
             WHERE fp.stage3_completed_at IS NOT NULL AND fp.client_user_id IS NOT NULL"
        );

        $count = 0;
        foreach ( $clients as $c ) {
            $result = $this->generate( $c->client_user_id, $c->practitioner_user_id );
            if ( ! is_wp_error( $result ) ) $count++;
        }
        error_log( "[HDLV2 FlightPlan] Cron generated {$count} flight plans." );
    }

    // ── Shortcode ──
    public function render_shortcode( $atts ) {
        wp_enqueue_script( 'hdlv2-flight-plan', HDLV2_PLUGIN_URL . 'assets/js/hdlv2-flight-plan.js', array(), HDLV2_VERSION, true );
        wp_enqueue_style( 'hdlv2-form', HDLV2_PLUGIN_URL . 'assets/css/hdlv2-form.css', array(), HDLV2_VERSION );
        wp_localize_script( 'hdlv2-flight-plan', 'hdlv2_flight_plan', array(
            'api_base' => rest_url( 'hdl-v2/v1/flight-plan' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
        ) );
        return '<div id="hdlv2-flight-plan" class="hdlv2-assessment-root"></div>';
    }
}
