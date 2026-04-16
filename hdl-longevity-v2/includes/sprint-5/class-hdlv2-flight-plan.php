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
        add_action( 'hdlv2_generate_single_flight_plan', array( $this, 'generate_for_client' ) );
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
    }

    // ── Auth: Validate client access via token or practitioner session ──
    private function validate_client_access( $request, $client_id = 0 ) {
        // Practitioner override — full access
        $user_id = get_current_user_id();
        if ( $user_id && HDLV2_Compatibility::is_practitioner( $user_id ) ) {
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
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT client_user_id FROM {$wpdb->prefix}hdlv2_form_progress WHERE token = %s LIMIT 1", $token
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
        $plan = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_flight_plans WHERE client_id = %d ORDER BY week_start DESC LIMIT 1",
            $client_id
        ) );
        if ( ! $plan ) {
            return rest_ensure_response( array( 'plan' => null ) );
        }

        $ticks = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_flight_plan_ticks WHERE flight_plan_id = %d ORDER BY day, time_slot",
            $plan->id
        ) );

        return rest_ensure_response( array(
            'plan' => array(
                'id'                  => (int) $plan->id,
                'week_number'         => (int) $plan->week_number,
                'week_start'          => $plan->week_start,
                'date_range_end'      => $plan->date_range_end ?? null,
                'plan_data'           => json_decode( $plan->plan_data, true ),
                'shopping_list'       => json_decode( $plan->shopping_list, true ),
                'identity_statement'  => $plan->identity_statement,
                'weekly_targets'      => json_decode( $plan->weekly_targets, true ),
                'journey_assistance'  => $plan->journey_assistance,
                'adherence_summary'   => $plan->adherence_summary ? json_decode( $plan->adherence_summary, true ) : null,
                'status'              => $plan->status,
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

        // Look up the tick's client_id via flight_plan_id and verify access
        global $wpdb;
        $tick_client = $wpdb->get_var( $wpdb->prepare(
            "SELECT fp.client_id FROM {$wpdb->prefix}hdlv2_flight_plan_ticks t
             JOIN {$wpdb->prefix}hdlv2_flight_plans fp ON fp.id = t.flight_plan_id
             WHERE t.id = %d", $tick_id
        ) );
        if ( ! $tick_client ) return new WP_Error( 'not_found', 'Tick not found.', array( 'status' => 404 ) );

        $auth = $this->validate_client_access( $request, (int) $tick_client );
        if ( is_wp_error( $auth ) ) return $auth;

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

        // Verify the plan's client_id matches the requester's token
        global $wpdb;
        $plan_client = $wpdb->get_var( $wpdb->prepare(
            "SELECT client_id FROM {$wpdb->prefix}hdlv2_flight_plans WHERE id = %d", $plan_id
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
            array( 'ticked' => $ticked ? 1 : 0, 'ticked_at' => $ticked ? current_time( 'mysql' ) : null ),
            $where
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
        $auth = $this->validate_client_access( $request, $client_id );
        if ( is_wp_error( $auth ) ) return $auth;

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

    // ── REST: Settings (per-client generation day) ──
    public function rest_settings( $request ) {
        $client_id = absint( $request['client_id'] );
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
        $params = $request->get_json_params();
        $notes = sanitize_textarea_field( $params['notes'] ?? '' );
        if ( ! $notes ) {
            return new WP_Error( 'empty', 'Notes text is required.', array( 'status' => 400 ) );
        }
        update_user_meta( $client_id, 'hdlv2_next_plan_priorities', $notes );
        return rest_ensure_response( array( 'success' => true ) );
    }

    // ── Core: Generate flight plan ──
    public function generate( $client_id, $practitioner_id, $trigger = 'auto' ) {
        $api_key = defined( 'HDLV2_ANTHROPIC_API_KEY' ) ? HDLV2_ANTHROPIC_API_KEY : '';
        if ( ! $api_key ) return new WP_Error( 'no_key', 'Anthropic API key not configured.' );

        // 5.1 — Use shared Context Builder (tier 2)
        $context = HDLV2_Context_Builder::build_context( $client_id, 2 );
        $week_start = date( 'Y-m-d', strtotime( 'monday this week' ) );
        $week_num   = $context['week_number'] ?? 1;

        // Read practitioner priority notes (set from dashboard, cleared after use)
        $priority_notes = get_user_meta( $client_id, 'hdlv2_next_plan_priorities', true );
        if ( $priority_notes ) {
            $context['priority_notes'] = $priority_notes;
        }

        // Pass actual dates to AI for temporal context
        $context['week_start_date'] = $week_start;
        $context['week_end_date']   = date( 'Y-m-d', strtotime( $week_start . ' +6 days' ) );

        // 5.2 — Build expanded prompt
        $prompt = $this->build_prompt( $context );
        $model  = 'claude-sonnet-4-20250514';

        // Call Claude
        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'x-api-key' => $api_key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model' => $model, 'max_tokens' => 4000,
                'system' => $prompt['system'], 'messages' => array( array( 'role' => 'user', 'content' => $prompt['user'] ) ),
            ) ),
            'timeout' => 120,
        ) );

        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 ) return new WP_Error( 'api_error', $body['error']['message'] ?? "HTTP $code" );

        $content = $body['content'][0]['text'] ?? '';
        // Strip markdown code fences if present (```json ... ```)
        $content = preg_replace( '/^\s*```(?:json)?\s*/i', '', $content );
        $content = preg_replace( '/\s*```\s*$/', '', $content );
        $plan = json_decode( $content, true );
        if ( ! $plan ) $plan = array( 'raw' => $content );

        // 5.6 — Extract token usage
        $usage = $body['usage'] ?? array();
        $token_usage = array(
            'prompt_tokens'     => $usage['input_tokens'] ?? 0,
            'completion_tokens' => $usage['output_tokens'] ?? 0,
            'total_tokens'      => ( $usage['input_tokens'] ?? 0 ) + ( $usage['output_tokens'] ?? 0 ),
        );

        // Calculate date_range_end from week_start (never trust AI for dates)
        $date_range_end = date( 'Y-m-d', strtotime( $week_start . ' +6 days' ) );

        // Store with new metadata columns
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'hdlv2_flight_plans', array(
            'client_id'           => $client_id,
            'practitioner_id'     => $practitioner_id,
            'week_number'         => $week_num,
            'week_start'          => $week_start,
            'date_range_end'      => $date_range_end,
            'plan_data'           => wp_json_encode( $plan ),
            'shopping_list'       => wp_json_encode( $plan['shopping_list'] ?? array() ),
            'identity_statement'  => sanitize_text_field( $plan['identity_statement'] ?? '' ),
            'weekly_targets'      => wp_json_encode( $plan['weekly_targets'] ?? array() ),
            'journey_assistance'  => sanitize_textarea_field( $plan['journey_assistance'] ?? '' ),
            'status'              => 'generated',
            'generation_trigger'  => $trigger,
            'ai_model'            => $model,
            'token_usage'         => wp_json_encode( $token_usage ),
        ) );
        $plan_id = (int) $wpdb->insert_id;

        // Create tick rows
        $this->create_tick_rows( $plan_id, $client_id, $plan );

        // Timeline entry
        if ( class_exists( 'HDLV2_Timeline' ) ) {
            HDLV2_Timeline::add_entry( $client_id, $practitioner_id, 'flight_plan', "Week $week_num Flight Plan generated", '', null, 'hdlv2_flight_plans', $plan_id );
        }

        // Clear practitioner priority notes after use
        delete_user_meta( $client_id, 'hdlv2_next_plan_priorities' );

        return $plan_id;
    }

    /**
     * Generate for a single client (called from check-in confirm trigger or first plan trigger).
     * Includes duplicate prevention.
     */
    public function generate_for_client( $client_id ) {
        global $wpdb;

        // Duplicate prevention — one plan per client per week
        $week_start = date( 'Y-m-d', strtotime( 'monday this week' ) );
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}hdlv2_flight_plans WHERE client_id = %d AND week_start = %s LIMIT 1",
            $client_id, $week_start
        ) );
        if ( $existing ) return; // Already has a plan for this week

        // Get practitioner
        $prac_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT practitioner_user_id FROM {$wpdb->prefix}hdlv2_form_progress WHERE client_user_id = %d ORDER BY id DESC LIMIT 1",
            $client_id
        ) );

        $this->generate( (int) $client_id, (int) $prac_id, 'checkin' );
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
                    $text = $action['action'] ?? $action['text'] ?? '';
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

        foreach ( $cats as $cat ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE flight_plan_id = %d AND category = %s", $plan_id, $cat ) );
            $done  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE flight_plan_id = %d AND category = %s AND ticked = 1", $plan_id, $cat ) );
            $scores[ $cat ] = $total > 0 ? round( $done / $total * 100 ) : 0;
        }

        $total_all = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE flight_plan_id = %d", $plan_id ) );
        $done_all  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE flight_plan_id = %d AND ticked = 1", $plan_id ) );
        $scores['overall'] = $total_all > 0 ? round( $done_all / $total_all * 100 ) : 0;

        // 5.9 — Store adherence summary on the flight plan record
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_flight_plans',
            array( 'adherence_summary' => wp_json_encode( $scores ) ),
            array( 'id' => $plan_id ),
            array( '%s' ),
            array( '%d' )
        );

        // 5.7 — Write adherence timeline entry
        if ( $write_timeline && class_exists( 'HDLV2_Timeline' ) ) {
            $plan = $wpdb->get_row( $wpdb->prepare(
                "SELECT client_id, practitioner_id, week_number, week_start FROM {$wpdb->prefix}hdlv2_flight_plans WHERE id = %d", $plan_id
            ) );
            if ( $plan ) {
                $wpdb->insert( $wpdb->prefix . 'hdlv2_timeline', array(
                    'client_id'      => $plan->client_id,
                    'practitioner_id' => $plan->practitioner_id,
                    'entry_type'     => 'adherence',
                    'title'          => sprintf( 'Week %d adherence: %d%%', $plan->week_number, $scores['overall'] ),
                    'date'           => $plan->week_start,
                    'end_date'       => date( 'Y-m-d', strtotime( $plan->week_start . ' +6 days' ) ),
                    'temporal_type'  => 'interval',
                    'category'       => 'system',
                    'source'         => 'system',
                    'summary'        => sprintf( 'Week %d adherence: %d%% overall (movement %d%%, nutrition %d%%, key actions %d%%)',
                                            $plan->week_number, $scores['overall'], $scores['movement'], $scores['nutrition'], $scores['key_action'] ),
                    'detail'         => wp_json_encode( $scores ),
                    'created_at'     => current_time( 'mysql' ),
                ) );
            }
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
                $adherence_text .= sprintf( "Week %d: overall=%s%%, movement=%s%%, nutrition=%s%%, key_actions=%s%%\n",
                    $ci->week_number,
                    $scores['overall'] ?? '?', $scores['movement'] ?? '?',
                    $scores['nutrition'] ?? '?', $scores['key_actions'] ?? '?'
                );
            }
        }

        // Monthly summaries
        $monthly_text = '';
        if ( ! empty( $context['monthly_summaries'] ) ) {
            foreach ( $context['monthly_summaries'] as $ms ) {
                $monthly_text .= sprintf( "%s to %s: %s\n", $ms->month_start, $ms->month_end, $ms->summary );
            }
        }

        $system = 'You are generating a personalised Weekly Flight Plan for a longevity client.
The Flight Plan is a printable document with daily actions structured by time of day.

You must generate:
1. DAILY PLAN: For each day (Mon-Sun), actions placed in time slots (morning, mid_morning, lunchtime, afternoon, early_evening, late_evening, night). Include: movement/fitness (with checkbox), nutrition (with checkbox), key actions (with checkbox), WHY anchor.
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

Return ONLY valid JSON with keys: identity_statement, flexibility_note, daily_plan, weekly_targets, shopping_list, journey_assistance, review_prompts.

Each action in daily_plan must have: type (movement|nutrition|key_action|why_anchor), action (text), checkbox (boolean — false for why_anchor).';

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

        $clients = $wpdb->get_results(
            "SELECT DISTINCT fp.client_user_id, fp.practitioner_user_id
             FROM {$wpdb->prefix}hdlv2_form_progress fp
             WHERE fp.stage3_completed_at IS NOT NULL AND fp.client_user_id IS NOT NULL"
        );

        $count = 0;
        foreach ( $clients as $c ) {
            // 5.5 — Per-client generation day (default Saturday)
            $gen_day = get_user_meta( $c->client_user_id, 'hdlv2_flight_plan_day', true );
            if ( empty( $gen_day ) ) $gen_day = 'saturday';
            if ( $today !== $gen_day ) continue;

            // Duplicate prevention — one plan per client per week
            $week_start = date( 'Y-m-d', strtotime( 'monday this week' ) );
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}hdlv2_flight_plans WHERE client_id = %d AND week_start = %s LIMIT 1",
                $c->client_user_id, $week_start
            ) );
            if ( $existing ) continue;

            $result = $this->generate( $c->client_user_id, $c->practitioner_user_id, 'auto' );
            if ( ! is_wp_error( $result ) ) $count++;
        }

        if ( $count > 0 ) {
            error_log( "[HDLV2 FlightPlan] Cron generated {$count} flight plans." );
        }
    }

    // ── Shortcode ──
    public function render_shortcode( $atts ) {
        // Resolve client_id from token (same pattern as check-in shortcode)
        $client_id = 0;
        $tok = isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';
        if ( $tok && preg_match( '/^[a-f0-9]{64}$/', $tok ) ) {
            global $wpdb;
            $progress = $wpdb->get_row( $wpdb->prepare(
                "SELECT client_user_id FROM {$wpdb->prefix}hdlv2_form_progress WHERE token = %s LIMIT 1", $tok
            ) );
            if ( $progress ) $client_id = (int) $progress->client_user_id;
        }
        // Practitioner viewing a client
        if ( ! $client_id && isset( $_GET['client_id'] ) ) {
            $client_id = absint( $_GET['client_id'] );
        }

        wp_enqueue_script( 'hdlv2-flight-plan', HDLV2_PLUGIN_URL . 'assets/js/hdlv2-flight-plan.js', array(), HDLV2_VERSION, true );
        wp_enqueue_style( 'hdlv2-form', HDLV2_PLUGIN_URL . 'assets/css/hdlv2-form.css', array(), HDLV2_VERSION );
        wp_enqueue_style( 'hdlv2-flight-plan', HDLV2_PLUGIN_URL . 'assets/css/hdlv2-flight-plan.css', array(), HDLV2_VERSION );
        wp_localize_script( 'hdlv2-flight-plan', 'hdlv2_flight_plan', array(
            'api_base'  => rest_url( 'hdl-v2/v1/flight-plan' ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'client_id' => $client_id,
        ) );
        return '<div id="hdlv2-flight-plan" class="hdlv2-assessment-root"></div>';
    }
}
