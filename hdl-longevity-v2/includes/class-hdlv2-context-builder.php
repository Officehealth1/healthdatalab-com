<?php
/**
 * AI Context Builder — Tiered context assembly for AI prompts.
 *
 * Tier 1 (always): profile, WHY, milestones, latest note/checkin, adherence, week number
 * Tier 2 (rolling): last 4 checkins, last 4 adherence scores, last 3 notes, comfort zone
 * Tier 3 (periodic): monthly summaries only
 *
 * @package HDL_Longevity_V2
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class HDLV2_Context_Builder {

    private static $instance = null;
    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {}

    public function register_hooks() {
        add_action( 'hdlv2_monthly_summary', array( $this, 'generate_monthly_summaries' ) );
    }

    /**
     * Build context for a client at a given tier level.
     *
     * @param int $client_id  Client user ID.
     * @param int $tier       1, 2, or 3.
     * @return array Structured context.
     */
    public static function build_context( $client_id, $tier = 2 ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $context = array();

        // Tier 1 — Always included
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}hdlv2_form_progress WHERE client_user_id = %d ORDER BY id DESC LIMIT 1", $client_id
        ) );

        $s1 = $progress ? json_decode( $progress->stage1_data, true ) : array();
        $context['client_name'] = $progress->client_name ?? '';
        $context['age']         = $s1['q1_age'] ?? $s1['age'] ?? null;
        $context['sex']         = $s1['q1_sex'] ?? $s1['gender'] ?? null;
        $context['week_number'] = $progress ? max( 1, (int) ceil( ( time() - strtotime( $progress->created_at ) ) / ( 7 * DAY_IN_SECONDS ) ) ) : 1;

        // WHY profile
        $why = $wpdb->get_row( $wpdb->prepare(
            "SELECT distilled_why, key_people, motivations, fears FROM {$p}hdlv2_why_profiles WHERE client_user_id = %d AND released = 1 ORDER BY id DESC LIMIT 1", $client_id
        ) );
        $context['why_profile'] = $why ? array(
            'distilled_why' => $why->distilled_why,
            'key_people'    => json_decode( $why->key_people, true ),
            'motivations'   => json_decode( $why->motivations, true ),
            'fears'         => json_decode( $why->fears, true ),
        ) : null;

        // Latest report + milestones
        $report = $wpdb->get_row( $wpdb->prepare(
            "SELECT report_content, milestones FROM {$p}hdlv2_reports WHERE client_user_id = %d AND report_type = 'final' AND status = 'ready' ORDER BY id DESC LIMIT 1", $client_id
        ) );
        $context['report_summary'] = $report ? substr( $report->report_content, 0, 800 ) : null;
        $context['milestones']     = $report ? json_decode( $report->milestones, true ) : null;

        // Latest practitioner note
        $latest_note = $wpdb->get_var( $wpdb->prepare(
            "SELECT typed_notes FROM {$p}hdlv2_consultation_notes WHERE client_user_id = %d ORDER BY id DESC LIMIT 1", $client_id
        ) );
        $context['latest_note'] = $latest_note ? substr( $latest_note, 0, 500 ) : null;

        // Latest check-in
        $latest_ci = $wpdb->get_row( $wpdb->prepare(
            "SELECT summary, adherence_scores, comfort_zone FROM {$p}hdlv2_checkins WHERE client_id = %d AND status = 'confirmed' ORDER BY week_start DESC LIMIT 1", $client_id
        ) );
        $context['latest_checkin']  = $latest_ci ? json_decode( $latest_ci->summary, true ) : null;
        $context['latest_adherence'] = $latest_ci ? json_decode( $latest_ci->adherence_scores, true ) : null;

        // Previous shopping list
        $prev_shop = $wpdb->get_var( $wpdb->prepare(
            "SELECT shopping_list FROM {$p}hdlv2_flight_plans WHERE client_id = %d ORDER BY week_start DESC LIMIT 1", $client_id
        ) );
        $context['previous_shopping'] = $prev_shop ? json_decode( $prev_shop, true ) : array();

        if ( $tier < 2 ) return $context;

        // Tier 2 — Rolling window
        $context['recent_checkins'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT week_number, summary, adherence_scores, comfort_zone FROM {$p}hdlv2_checkins WHERE client_id = %d AND status = 'confirmed' ORDER BY week_start DESC LIMIT 4", $client_id
        ) );

        $context['recent_notes'] = $wpdb->get_col( $wpdb->prepare(
            "SELECT typed_notes FROM {$p}hdlv2_consultation_notes WHERE client_user_id = %d ORDER BY id DESC LIMIT 3", $client_id
        ) );

        $context['recent_adherence'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT fp.week_number, fp.week_start FROM {$p}hdlv2_flight_plans fp WHERE fp.client_id = %d ORDER BY fp.week_start DESC LIMIT 4", $client_id
        ) );

        // Comfort zone data
        $comfort = array();
        foreach ( $context['recent_checkins'] as $ci ) {
            $comfort[] = $ci->comfort_zone;
        }
        $context['comfort_zone_trend'] = $comfort;

        if ( $tier < 3 ) return $context;

        // Tier 3 — Monthly summaries
        $context['monthly_summaries'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT month_start, month_end, summary FROM {$p}hdlv2_monthly_summaries WHERE client_id = %d ORDER BY month_start DESC LIMIT 6", $client_id
        ) );

        return $context;
    }

    /**
     * Generate monthly summaries for all active clients.
     * Cron: hdlv2_monthly_summary
     */
    public function generate_monthly_summaries() {
        global $wpdb;
        $p = $wpdb->prefix;

        // Find clients with 4+ confirmed check-ins that haven't been summarised
        $clients = $wpdb->get_col(
            "SELECT DISTINCT client_id FROM {$p}hdlv2_checkins WHERE status = 'confirmed' GROUP BY client_id HAVING COUNT(*) >= 4"
        );

        $api_key = defined( 'HDLV2_ANTHROPIC_API_KEY' ) ? HDLV2_ANTHROPIC_API_KEY : '';
        if ( ! $api_key ) return;

        $count = 0;
        foreach ( $clients as $client_id ) {
            // Get the last 4 unsummarised check-ins
            $last_summary = $wpdb->get_var( $wpdb->prepare(
                "SELECT MAX(month_end) FROM {$p}hdlv2_monthly_summaries WHERE client_id = %d", $client_id
            ) );

            $where_date = $last_summary
                ? $wpdb->prepare( " AND week_start > %s", $last_summary )
                : '';

            $checkins = $wpdb->get_results( $wpdb->prepare(
                "SELECT week_start, summary, adherence_scores, comfort_zone FROM {$p}hdlv2_checkins WHERE client_id = %d AND status = 'confirmed'{$where_date} ORDER BY week_start ASC LIMIT 4",
                $client_id
            ) );

            if ( count( $checkins ) < 4 ) continue;

            // Build input
            $input = '';
            foreach ( $checkins as $ci ) {
                $input .= "Week of {$ci->week_start}: " . $ci->summary . " | Adherence: " . $ci->adherence_scores . " | Comfort: {$ci->comfort_zone}\n\n";
            }

            // Call Haiku 4.5
            $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
                'headers' => array( 'x-api-key' => $api_key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json' ),
                'body'    => wp_json_encode( array(
                    'model'      => 'claude-haiku-4-5-20251001',
                    'max_tokens' => 500,
                    'system'     => 'Summarise this client\'s last 4 weekly check-ins into a single monthly summary. Preserve: key trends, comfort zone shifts, major obstacles, wins, flag history, adherence pattern. Output: 200-300 words max.',
                    'messages'   => array( array( 'role' => 'user', 'content' => $input ) ),
                ) ),
                'timeout' => 60,
            ) );

            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) continue;

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $summary = $body['content'][0]['text'] ?? '';
            if ( ! $summary ) continue;

            $month_start = $checkins[0]->week_start;
            $month_end   = $checkins[ count( $checkins ) - 1 ]->week_start;
            $checkin_ids = array_map( function ( $c ) { return $c->week_start; }, $checkins );

            // Get practitioner_id
            $prac_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT practitioner_id FROM {$p}hdlv2_checkins WHERE client_id = %d LIMIT 1", $client_id
            ) );

            $wpdb->insert( $p . 'hdlv2_monthly_summaries', array(
                'client_id'       => $client_id,
                'practitioner_id' => $prac_id ?: 0,
                'month_start'     => $month_start,
                'month_end'       => $month_end,
                'summary'         => $summary,
                'checkin_ids'     => wp_json_encode( $checkin_ids ),
            ) );

            // Timeline entry
            if ( class_exists( 'HDLV2_Timeline' ) ) {
                HDLV2_Timeline::add_entry( $client_id, $prac_id ?: 0, 'monthly_summary', 'Monthly Summary', $summary );
            }

            $count++;
        }

        if ( $count > 0 ) {
            error_log( "[HDLV2 Context] Generated {$count} monthly summaries." );
        }
    }
}
