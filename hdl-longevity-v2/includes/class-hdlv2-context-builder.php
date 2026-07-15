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

        // v0.41.17 — Every read below filters by `deleted_at IS NULL` (either
        // directly on tables that have the column, or via a JOIN to form_progress
        // for derived tables). Without this, after a re-invite the context
        // builder would pull last-lifecycle data into the Claude prompt and
        // contaminate the new Flight Plan / Final Report.

        // Tier 1 — Always included
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}hdlv2_form_progress
             WHERE client_user_id = %d AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1",
            $client_id
        ) );

        $s1 = $progress ? json_decode( $progress->stage1_data, true ) : array();
        $context['client_name'] = $progress->client_name ?? '';
        $context['age']         = $s1['q1_age'] ?? $s1['age'] ?? null;
        $context['sex']         = $s1['q1_sex'] ?? $s1['gender'] ?? null;
        $context['week_number'] = $progress ? max( 1, (int) ceil( ( time() - strtotime( $progress->created_at ) ) / ( 7 * DAY_IN_SECONDS ) ) ) : 1;

        // WHY profile — JOIN form_progress to filter archived lifecycles
        // (why_profiles itself doesn't carry deleted_at; scoping via FK).
        $why = $wpdb->get_row( $wpdb->prepare(
            "SELECT w.distilled_why, w.key_people, w.motivations, w.fears
             FROM {$p}hdlv2_why_profiles w
             INNER JOIN {$p}hdlv2_form_progress fp ON fp.id = w.form_progress_id
             WHERE w.client_user_id = %d AND w.released = 1 AND fp.deleted_at IS NULL
             ORDER BY w.id DESC LIMIT 1",
            $client_id
        ) );
        $context['why_profile'] = $why ? array(
            'distilled_why' => $why->distilled_why,
            'key_people'    => json_decode( $why->key_people, true ),
            'motivations'   => json_decode( $why->motivations, true ),
            'fears'         => json_decode( $why->fears, true ),
        ) : null;

        // Latest report + milestones — JOIN form_progress.
        $report = $wpdb->get_row( $wpdb->prepare(
            "SELECT r.report_content, r.milestones
             FROM {$p}hdlv2_reports r
             INNER JOIN {$p}hdlv2_form_progress fp ON fp.id = r.form_progress_id
             WHERE r.client_user_id = %d AND r.report_type = 'final' AND r.status = 'ready'
               AND fp.deleted_at IS NULL
             ORDER BY r.id DESC LIMIT 1",
            $client_id
        ) );
        $context['report_summary'] = $report ? substr( $report->report_content, 0, 800 ) : null;
        $context['milestones']     = $report ? json_decode( $report->milestones, true ) : null;

        // Latest practitioner note — JOIN form_progress.
        $latest_note = $wpdb->get_var( $wpdb->prepare(
            "SELECT cn.typed_notes
             FROM {$p}hdlv2_consultation_notes cn
             INNER JOIN {$p}hdlv2_form_progress fp ON fp.id = cn.form_progress_id
             WHERE cn.client_user_id = %d AND fp.deleted_at IS NULL
             ORDER BY cn.id DESC LIMIT 1",
            $client_id
        ) );
        $context['latest_note'] = $latest_note ? substr( $latest_note, 0, 500 ) : null;

        // Latest check-in
        $latest_ci = $wpdb->get_row( $wpdb->prepare(
            "SELECT summary, adherence_scores, comfort_zone
             FROM {$p}hdlv2_checkins
             WHERE client_id = %d AND status = 'confirmed' AND deleted_at IS NULL
             ORDER BY week_start DESC LIMIT 1",
            $client_id
        ) );
        $context['latest_checkin']  = $latest_ci ? json_decode( $latest_ci->summary, true ) : null;
        $context['latest_adherence'] = $latest_ci ? json_decode( $latest_ci->adherence_scores, true ) : null;

        // Previous shopping list — only from plans that have started, so a future-dated
        // plan generated ahead doesn't get fed back as "previous" input to itself.
        $prev_shop = $wpdb->get_var( $wpdb->prepare(
            "SELECT shopping_list FROM {$p}hdlv2_flight_plans
             WHERE client_id = %d AND week_start <= %s AND deleted_at IS NULL
             ORDER BY week_start DESC LIMIT 1",
            $client_id,
            current_time( 'Y-m-d' )
        ) );
        $context['previous_shopping'] = $prev_shop ? json_decode( $prev_shop, true ) : array();

        if ( $tier < 2 ) return $context;

        // Tier 2 — Rolling window
        $context['recent_checkins'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT week_number, summary, adherence_scores, comfort_zone
             FROM {$p}hdlv2_checkins
             WHERE client_id = %d AND status = 'confirmed' AND deleted_at IS NULL
             ORDER BY week_start DESC LIMIT 4",
            $client_id
        ) );
        // v0.31.0 — surface check-in IDs to the prompt's audit trail (R8).
        $context['checkin_ids'] = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$p}hdlv2_checkins
             WHERE client_id = %d AND status = 'confirmed' AND deleted_at IS NULL
             ORDER BY week_start DESC LIMIT 4",
            $client_id
        ) );

        $context['recent_notes'] = $wpdb->get_col( $wpdb->prepare(
            "SELECT cn.typed_notes
             FROM {$p}hdlv2_consultation_notes cn
             INNER JOIN {$p}hdlv2_form_progress fp ON fp.id = cn.form_progress_id
             WHERE cn.client_user_id = %d AND fp.deleted_at IS NULL
             ORDER BY cn.id DESC LIMIT 3",
            $client_id
        ) );

        $context['recent_adherence'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT fp.week_number, fp.week_start FROM {$p}hdlv2_flight_plans fp
             WHERE fp.client_id = %d AND fp.deleted_at IS NULL
             ORDER BY fp.week_start DESC LIMIT 4",
            $client_id
        ) );

        // Comfort zone data
        $comfort = array();
        foreach ( $context['recent_checkins'] as $ci ) {
            $comfort[] = $ci->comfort_zone;
        }
        $context['comfort_zone_trend'] = $comfort;

        // v0.31.0 — R5: tick-derived adherence. Pulls last 4 weeks of plan
        // tick rows and computes a per-week completion rate. Lets the AI see
        // engagement even when the client skipped check-ins. Combined with
        // recent_checkins (self-reported), the prompt now has both signals.
        $context['tick_adherence'] = self::compute_tick_adherence( $client_id );

        // v0.31.0 — R7 engagement signal. Coarse "zero / low / medium / high"
        // banding consumed by:
        //   - Saturday cron → skips auto-generation when 'zero' (notifies
        //     practitioner instead of producing a stale plan)
        //   - Build prompt → tone calibration ("client may need re-engagement")
        $context['engagement_signal'] = self::compute_engagement_signal( $client_id, $context['tick_adherence'] );

        // v0.31.0 — R8 audit trail: addenda tied to current consultation.
        // v0.41.17 — JOIN form_progress for active-lifecycle scope.
        $context['addenda_ids'] = $wpdb->get_col( $wpdb->prepare(
            "SELECT a.id FROM {$p}hdlv2_consultation_addenda a
             INNER JOIN {$p}hdlv2_form_progress fp ON fp.id = a.form_progress_id
             WHERE a.client_user_id = %d AND fp.deleted_at IS NULL
             ORDER BY a.id DESC LIMIT 10",
            $client_id
        ) );

        // Adherence summary block (concise — 4 weeks worth) for audit JSON.
        $context['adherence_summary'] = $context['tick_adherence'];

        if ( $tier < 3 ) return $context;

        // Tier 3 — Monthly summaries
        $context['monthly_summaries'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT month_start, month_end, summary FROM {$p}hdlv2_monthly_summaries
             WHERE client_id = %d AND deleted_at IS NULL
             ORDER BY month_start DESC LIMIT 6",
            $client_id
        ) );

        return $context;
    }

    /**
     * v0.31.0 — R5: Compute per-week adherence directly from tick rows.
     *
     * Returns the last 4 weeks (most recent first) as:
     *   [
     *     [ 'week_start' => '2026-05-04', 'rate' => 0.62, 'ticked' => 18, 'total' => 29,
     *       'days_active' => 4, 'category_breakdown' => [...] ],
     *     ...
     *   ]
     *
     * Distinct from check-in self-reported adherence: this measures actual
     * tick-button engagement on the plan rows. A client who never opens the
     * page produces all-zero adherence here even if they "felt good" in a
     * verbal check-in. Both signals feed the next plan's prompt — see
     * hdlv2-flight-plan.php build_prompt().
     */
    private static function compute_tick_adherence( $client_id ) {
        global $wpdb;
        $p = $wpdb->prefix;

        // v0.41.17 — `AND deleted_at IS NULL` on plans + ticks subqueries.
        $plans = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, week_start FROM {$p}hdlv2_flight_plans
             WHERE client_id = %d AND week_start <= %s AND deleted_at IS NULL
             ORDER BY week_start DESC LIMIT 4",
            $client_id, current_time( 'Y-m-d' )
        ) );

        $out = array();
        foreach ( $plans as $plan ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT
                    COUNT(*)                                          AS total,
                    SUM(CASE WHEN ticked = 1 THEN 1 ELSE 0 END)       AS ticked,
                    COUNT(DISTINCT CASE WHEN ticked = 1 THEN day END) AS days_active
                 FROM {$p}hdlv2_flight_plan_ticks
                 WHERE flight_plan_id = %d AND deleted_at IS NULL",
                $plan->id
            ) );
            $total  = (int) ( $row->total  ?? 0 );
            $ticked = (int) ( $row->ticked ?? 0 );
            $days_a = (int) ( $row->days_active ?? 0 );
            $rate   = $total > 0 ? round( $ticked / $total, 3 ) : 0.0;

            // Per-category breakdown so the AI can see WHICH category dropped
            // (e.g. nutrition strong, movement weak → reduce movement targets
            // not nutrition).
            $cats = $wpdb->get_results( $wpdb->prepare(
                "SELECT category,
                        COUNT(*) AS total,
                        SUM(CASE WHEN ticked = 1 THEN 1 ELSE 0 END) AS ticked
                 FROM {$p}hdlv2_flight_plan_ticks
                 WHERE flight_plan_id = %d AND deleted_at IS NULL
                 GROUP BY category",
                $plan->id
            ) );
            $cat_breakdown = array();
            foreach ( $cats as $c ) {
                $cat_total  = (int) $c->total;
                $cat_ticked = (int) $c->ticked;
                $cat_breakdown[ $c->category ] = array(
                    'total'  => $cat_total,
                    'ticked' => $cat_ticked,
                    'rate'   => $cat_total > 0 ? round( $cat_ticked / $cat_total, 3 ) : 0.0,
                );
            }

            $out[] = array(
                'week_start'         => $plan->week_start,
                'rate'               => $rate,
                'ticked'             => $ticked,
                'total'              => $total,
                'days_active'        => $days_a,
                'category_breakdown' => $cat_breakdown,
            );
        }
        return $out;
    }

    /**
     * v0.31.0 — R7: Coarse engagement signal for cron + prompt-tone use.
     *
     * Returns 'zero' | 'low' | 'medium' | 'high':
     *   zero   — no check-ins AND no ticks in the last 14 days
     *   low    — <30% tick rate AND no recent check-in
     *   medium — 30-70% tick rate OR check-in present
     *   high   — ≥70% tick rate AND recent check-in
     *
     * Saturday cron uses 'zero' to skip auto-generation; flight-plan prompt
     * uses any signal to calibrate tone (high engagement → push harder, low
     * → reduce + reinforce identity).
     */
    private static function compute_engagement_signal( $client_id, $tick_adherence ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $latest_checkin_at = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(week_start) FROM {$p}hdlv2_checkins
             WHERE client_id = %d AND status = 'confirmed' AND deleted_at IS NULL",
            $client_id
        ) );
        $checkin_recent = $latest_checkin_at && ( strtotime( $latest_checkin_at ) > strtotime( '-14 days' ) );

        // Use the most recent week's tick rate for the headline signal.
        $latest_rate     = is_array( $tick_adherence ) && ! empty( $tick_adherence ) ? ( $tick_adherence[0]['rate']  ?? 0 ) : 0;
        $latest_total    = is_array( $tick_adherence ) && ! empty( $tick_adherence ) ? ( $tick_adherence[0]['total'] ?? 0 ) : 0;
        $latest_ticked   = is_array( $tick_adherence ) && ! empty( $tick_adherence ) ? ( $tick_adherence[0]['ticked'] ?? 0 ) : 0;

        if ( ! $checkin_recent && $latest_ticked === 0 && $latest_total > 0 ) {
            return 'zero';
        }
        if ( $latest_total === 0 && ! $checkin_recent ) {
            return 'zero'; // brand-new client with no plan history yet → treat as zero so cron skips
        }
        if ( $latest_rate < 0.3 && ! $checkin_recent ) return 'low';
        if ( $latest_rate >= 0.7 && $checkin_recent )  return 'high';
        return 'medium';
    }

    /**
     * Generate monthly summaries for all active clients.
     * Cron: hdlv2_monthly_summary
     */
    public function generate_monthly_summaries() {
        global $wpdb;
        $p = $wpdb->prefix;

        // Find clients with 4+ confirmed check-ins that haven't been summarised.
        // v0.41.17 — `AND deleted_at IS NULL` so we don't try to summarise
        // archived lifecycles (their data would already be excluded from the
        // monthly summary writes anyway, but skip the cron work entirely).
        $clients = $wpdb->get_col(
            "SELECT DISTINCT client_id FROM {$p}hdlv2_checkins
             WHERE status = 'confirmed' AND deleted_at IS NULL
             GROUP BY client_id HAVING COUNT(*) >= 4"
        );

        $api_key = defined( 'HDLV2_ANTHROPIC_API_KEY' ) ? HDLV2_ANTHROPIC_API_KEY : '';
        if ( ! $api_key ) return;

        $count = 0;
        foreach ( $clients as $client_id ) {
            // Get the last 4 unsummarised check-ins
            $last_summary = $wpdb->get_var( $wpdb->prepare(
                "SELECT MAX(month_end) FROM {$p}hdlv2_monthly_summaries
                 WHERE client_id = %d AND deleted_at IS NULL",
                $client_id
            ) );

            $where_date = $last_summary
                ? $wpdb->prepare( " AND week_start > %s", $last_summary )
                : '';

            $checkins = $wpdb->get_results( $wpdb->prepare(
                "SELECT week_start, summary, adherence_scores, comfort_zone FROM {$p}hdlv2_checkins
                 WHERE client_id = %d AND status = 'confirmed' AND deleted_at IS NULL{$where_date}
                 ORDER BY week_start ASC LIMIT 4",
                $client_id
            ) );

            if ( count( $checkins ) < 4 ) continue;

            // v0.47.74 — the Anthropic call (and the summary row it would
            // write) auto-fires on LIVE only; real billing must not burn from
            // a staging box's cron (override: hdlv2_allow_staging_side_effects).
            if ( ! HDLV2_Env::gate( 'monthly_summary client:' . (int) $client_id ) ) continue;

            // Build input
            $input = '';
            foreach ( $checkins as $ci ) {
                $input .= "Week of {$ci->week_start}: " . $ci->summary . " | Adherence: " . $ci->adherence_scores . " | Comfort: {$ci->comfort_zone}\n\n";
            }

            // v0.46.24 — Opus 4.8 (claude-opus-4-8) (was Sonnet 4.6, was Haiku 4.5).
            // Promoted per "all AI analyzation on one model" directive.
            $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
                'headers' => array( 'x-api-key' => $api_key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json' ),
                'body'    => wp_json_encode( array(
                    'model'      => 'claude-opus-4-8',
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

            // Get practitioner_id (current lifecycle only).
            $prac_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT practitioner_id FROM {$p}hdlv2_checkins
                 WHERE client_id = %d AND deleted_at IS NULL
                 LIMIT 1",
                $client_id
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
