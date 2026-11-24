<?php
/**
 * HDLV2_Flight_Cycle — the six-week Flight Plan (v0.49.01, plan 14 part C).
 *
 * Matthew, 2026-11-24 (H9): the plan "just automatically goes through for maybe like six weeks" and the
 * practitioner does nothing "unless there's a like a red flag". Quim: record done or missed per day, no
 * going back unless the client forgot, and build each week on what the client really did.
 *
 * - Days close (day_state): today and later open; yesterday open, stored as late; older locked, on the
 *   site's clock, as dates (a clock-change day is one day). An empty box on a locked day is "missed":
 *   computed, never stored. The client's two tick routes refuse a locked day; the owning practitioner and
 *   an admin may still correct it.
 * - The block (before_plan, called by every plan generate() makes): starts with the first plan after a
 *   final report; the AI writes a six-line outline; each week's prompt gets the done and missed lists of
 *   the week before (and the missed lists of the two weeks before that) and this week's line of the
 *   outline. Week 7 is never a plan of the block: a plan asked for it waits. v0.49.02 (plan 14 fixes):
 *   no block closes before the site's date is past its last day; the daily pass (roll_due) then writes
 *   the review from all six weeks and the next block starts by itself, or waits (review_due) for the
 *   practitioner's "Start next six weeks" when a check-in of the block holds a flag from the client's own
 *   words, the client had zero engagement, or the review could not be written. A paused block makes no
 *   automatic plan. A block belongs to the client's live form row.
 *
 * Switch hdlv2_ff_flight_cycle, absent = off = today's behaviour everywhere and nothing read. Table
 * hdlv2_flight_cycles; flight_plans.cycle_id/cycle_week; ticks.ticked_late/ticked_by (Phase AR, DB 3.37).
 *
 * @package HDL_Longevity_V2
 * @since   0.49.01
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Flight_Cycle {

    const OPTION     = 'hdlv2_ff_flight_cycle';
    const WEEKS      = 6;
    const MAX_LINES  = 40;
    const DAYS       = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
    const CATEGORIES = array( 'movement', 'nutrition', 'key_action' );

    /** Triggers that are the practitioner's own act: they still make a plan while a block is paused or waits. */
    const STAFF_TRIGGERS = array( 'manual', 'final_report', 'auto_repair' );

    /** Plan 14 § C4, word for word (pinned by tests/flight-cycle/). */
    const LINE_LISTS = 'Use the lists of done and missed actions: keep what the client does, make what they miss smaller or easier, and do not repeat an action missed three weeks in a row without changing it.';
    const LINE_WEEK  = "This is week {{CYCLE_WEEK}} of 6. This week's aim from the six-week outline: {{OUTLINE_LINE}}.";

    /** Plan 14 fix C8: the one line the client's page shows (draft words for Matthew). */
    const LINE_ENDED_PASS         = 'Your six weeks are complete. Your next plan is being prepared.';
    const LINE_ENDED_PRACTITIONER = 'Your six weeks are complete. Your practitioner starts your next six weeks.';
    const LINE_PAUSED             = 'Your plan is paused by your practitioner.';
    const LINE_NO_REVIEW          = 'The six-week review could not be written. The next six weeks wait for you.';

    /** Plan 14 fix C10: the older missed lists, at most this many lines each. */
    const MAX_OLDER_LINES = 15;

    /** Plan 14 § C5, word for word (pinned by tests/flight-cycle/). */
    const PROMPT_OUTLINE = <<<'TXT'
From the client's report, their WHY and their last check-ins, write a six-week outline: one line per week, each a single aim that builds on the week before. Use only the information provided. No diagnoses, no medication advice. Six lines, 'Week n: aim'.
TXT;
    const PROMPT_REVIEW = <<<'TXT'
Write a six-week review for the client and their practitioner from the lists provided: what was done most, what was missed most, what the check-ins said, and three aims for the next six weeks. Use only the information provided. No diagnoses, no medication advice. Put any red flag from the check-ins first. 180 words at most.
TXT;

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function on() {
        return (bool) get_option( self::OPTION, false );
    }

    // ── Pure: weeks, days, lists ─────────────────────────────────────────────

    private static function day( $ymd ) {
        return new DateTimeImmutable( $ymd, new DateTimeZone( 'UTC' ) );
    }

    /** Which week of the block a plan week is: 1 for the first; 0 or less before it, 7 after it. */
    public static function week_of( $start_week, $week_start ) {
        $days = (int) self::day( $start_week )->diff( self::day( $week_start ) )->format( '%r%a' );
        return (int) floor( $days / 7 ) + 1;
    }

    /** The block's last day: the Sunday of week 6. */
    public static function end_date( $start_week ) {
        return self::day( $start_week )->modify( '+' . ( self::WEEKS * 7 - 1 ) . ' days' )->format( 'Y-m-d' );
    }

    public static function day_date( $week_start, $weekday ) {
        return self::day( $week_start )->modify( '+' . (int) array_search( $weekday, self::DAYS, true ) . ' days' )->format( 'Y-m-d' );
    }

    /** open | late | locked. Ceiling: the site's midnight, not the client's; the one-day grace covers most of the world. */
    public static function day_state( $date, $today ) {
        if ( $date >= $today ) {
            return 'open';
        }
        return self::day( $today )->modify( '-1 day' )->format( 'Y-m-d' ) === $date ? 'late' : 'locked';
    }

    public static function today() {
        return current_time( 'Y-m-d' );
    }

    private static function first_day( $effective_start_day ) {
        return (int) array_search( strtolower( (string) $effective_start_day ), self::DAYS, true );
    }

    /** Per day from the plan's first day: its state, the boxes done, the boxes missed (empty on a locked day). */
    public static function day_summary( $ticks, $week_start, $effective_start_day, $today ) {
        $out = array();
        foreach ( array_slice( self::DAYS, self::first_day( $effective_start_day ) ) as $d ) {
            $out[ $d ] = array( 'state' => self::day_state( self::day_date( $week_start, $d ), $today ), 'done' => 0, 'missed' => 0 );
        }
        foreach ( $ticks as $t ) {
            if ( ! isset( $out[ $t->day ] ) ) {
                continue;
            }
            if ( (int) $t->ticked ) {
                $out[ $t->day ]['done']++;
            } elseif ( 'locked' === $out[ $t->day ]['state'] ) {
                $out[ $t->day ]['missed']++;
            }
        }
        return $out;
    }

    /**
     * Done and missed actions, each once with its number of days, by category. Each tick carries
     * week_start, effective_start_day, day, category, action_text, ticked. 40 lines at most: twenty
     * each when both are long, a short list giving its room to the other.
     */
    public static function lists( $ticks, $today ) {
        $seen = array( 'done' => array(), 'missed' => array() );
        foreach ( $ticks as $t ) {
            $idx = array_search( $t->day, self::DAYS, true );
            if ( false === $idx || $idx < self::first_day( $t->effective_start_day ?? 'monday' ) ) {
                continue;
            }
            if ( (int) $t->ticked ) {
                $kind = 'done';
            } elseif ( 'locked' === self::day_state( self::day_date( $t->week_start, $t->day ), $today ) ) {
                $kind = 'missed';
            } else {
                continue;
            }
            $cat = in_array( $t->category, self::CATEGORIES, true ) ? $t->category : 'key_action';
            $seen[ $kind ][ $cat . '|' . trim( (string) $t->action_text ) ][ $t->week_start . ' ' . $t->day ] = true;
        }
        $lines = array();
        foreach ( $seen as $kind => $actions ) {
            $rows = array();
            foreach ( $actions as $key => $days ) {
                list( $cat, $text ) = explode( '|', $key, 2 );
                $rows[] = array( array_search( $cat, self::CATEGORIES, true ), -count( $days ), $text, $cat );
            }
            sort( $rows );
            $lines[ $kind ] = array_map( function ( $r ) {
                return sprintf( '- %s: %s (%d %s)', $r[3], $r[2], -$r[1], -1 === $r[1] ? 'day' : 'days' );
            }, $rows );
        }
        $missed = min( count( $lines['missed'] ), max( self::MAX_LINES / 2, self::MAX_LINES - count( $lines['done'] ) ) );
        $done   = min( count( $lines['done'] ), self::MAX_LINES - $missed );
        return array( 'done' => array_slice( $lines['done'], 0, $done ), 'missed' => array_slice( $lines['missed'], 0, $missed ) );
    }

    public static function lists_text( $lists, $per_day ) {
        $out = "Done:\n" . ( $lists['done'] ? implode( "\n", $lists['done'] ) : '(none)' )
            . "\nMissed:\n" . ( $lists['missed'] ? implode( "\n", $lists['missed'] ) : '(none)' );
        if ( $per_day ) {
            $parts = array();
            foreach ( $per_day as $d => $s ) {
                $parts[] = 'locked' === $s['state']
                    ? sprintf( '%s done %d, missed %d', $d, $s['done'], $s['missed'] )
                    : sprintf( '%s done %d, not closed yet', $d, $s['done'] );
            }
            $out .= "\nPer day: " . implode( '; ', $parts );
        }
        return $out;
    }

    /** The lines added to the weekly prompt after its check-in lines. One pass: nothing typed is filled again. */
    public static function prompt_block( $lists_text, $week, $outline_line ) {
        $line = strtr( self::LINE_WEEK, array( '{{CYCLE_WEEK}}' => (string) (int) $week, '{{OUTLINE_LINE}}' => (string) $outline_line ) );
        return "\n\nDONE AND MISSED ACTIONS, THE PLAN'S LAST WEEK:\n" . $lists_text . "\n" . self::LINE_LISTS . "\n" . $line;
    }

    /** This week's aim from the outline, its "Week n:" taken off; a plain marker when there is none. */
    public static function outline_line( $outline, $week ) {
        foreach ( preg_split( '/\R/', (string) $outline ) as $line ) {
            if ( preg_match( '/^[\s*#\-]*week\s*' . (int) $week . '\s*[:.\-]\**\s*(.+?)\s*\.?\s*$/i', $line, $m ) ) {
                return mb_substr( $m[1], 0, 200 ); // one aim, never a page of text carried into every later prompt
            }
        }
        return '(no outline yet)';
    }

    // ── The block ────────────────────────────────────────────────────────────

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'hdlv2_flight_cycles';
    }

    /** The client's live form row (newest, not removed): a block belongs to it (plan 14 fix C3). 0 = none. */
    private static function live_form( $client_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}hdlv2_form_progress WHERE client_user_id = %d AND deleted_at IS NULL ORDER BY id DESC LIMIT 1",
            (int) $client_id
        ) );
    }

    /** The client's block not yet closed (active, paused or review_due) on their live form row, newest first. */
    public static function current( $client_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE client_id = %d AND form_progress_id = %d AND closed_at IS NULL ORDER BY id DESC LIMIT 1',
            (int) $client_id, self::live_form( $client_id )
        ) );
    }

    private static function block( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) );
    }

    private static function waits() {
        return new WP_Error( 'cycle_waits', 'The six-week plan waits for the practitioner.', array( 'status' => 409 ) );
    }

    /**
     * Called by HDLV2_Flight_Plan::generate() before the duplicate-replacing delete and the AI call.
     * null: switch off or no block (the plan is made as today). WP_Error cycle_waits: no plan now.
     * Else array( cycle_id, week, block ): the plan's place in the block and its prompt lines.
     */
    public static function before_plan( $client_id, $practitioner_id, $week_start, $trigger, $context ) {
        return self::on() ? self::place( $client_id, $practitioner_id, $week_start, $trigger, $context ) : null;
    }

    private static function lock( $client_id ) {
        global $wpdb;
        return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 30)', 'hdlv2_fc_' . (int) $client_id ) );
    }

    private static function unlock( $client_id ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'hdlv2_fc_' . (int) $client_id ) );
    }

    private static function place( $client_id, $practitioner_id, $week_start, $trigger, $context ) {
        $staff = in_array( $trigger, self::STAFF_TRIGGERS, true );
        $c     = self::current( $client_id );
        // Plan 14 fix C2: no block closes before the site's date is past its last day; the daily pass
        // (roll_due) closes it. Until then a plan for a week after its end waits, for every trigger: the
        // cron on the client's day in week 6 and a week-6 check-in make nothing, pay nothing, alert nobody.
        if ( $c && $week_start > $c->end_date ) {
            return self::waits();
        }
        if ( $c && 'paused' === $c->status && ! $staff ) {
            return self::waits();
        }
        if ( ! $c ) {
            if ( 'auto_repair' === $trigger ) {
                return null; // a repair redoes an old plan; it is not "the next plan" a block starts with
            }
            $c = self::start_block( $client_id, $practitioner_id, $week_start, $staff );
            if ( ! $c ) {
                return null;
            }
        }
        $week = self::week_of( $c->start_week, $week_start );
        if ( $week < 1 ) {
            return null; // a week before the block (an old plan repaired): made as today
        }
        if ( ! $c->outline ) {
            $c = self::write_outline( $c, $context );
        }
        return array(
            'cycle_id' => (int) $c->id,
            'week'     => $week,
            'block'    => self::prompt_block( self::last_week_text( $client_id, $week_start ), $week, self::outline_line( $c->outline, $week ) ),
        );
    }

    /**
     * Plan 14 fix C2: the daily pass, once per run of the daily Flight Plan cron. Blocks still active whose
     * last day is before today, oldest first, 50 a run. Each: the test-site guard, the access door, the
     * client's lock (busy = next run), the block read again inside it, then the review from all six weeks.
     * A block that closes starts the next one from this week and queues its plan (the existing job); a
     * block that waits stays review_due. Returns how many blocks were reviewed.
     */
    public static function roll_due() {
        if ( ! self::on() ) {
            return 0;
        }
        global $wpdb;
        $due = $wpdb->get_results( $wpdb->prepare(
            'SELECT id, client_id FROM ' . self::table() . " WHERE status = 'active' AND closed_at IS NULL AND end_date < %s ORDER BY end_date LIMIT 50",
            self::today()
        ) );
        $n = 0;
        foreach ( (array) $due as $d ) {
            $cid = (int) $d->client_id;
            if ( ! HDLV2_Env::gate( 'flight_cycle_review client:' . $cid ) ) {
                continue; // not the live site: no AI call, no e-mail
            }
            if ( class_exists( 'HDLV2_Access' ) && HDLV2_Access::client_door( $cid, 'flight_plan' ) ) {
                continue;
            }
            if ( ! self::lock( $cid ) ) {
                continue;
            }
            try {
                $c = self::block( (int) $d->id );
                if ( ! $c || 'active' !== $c->status || $c->end_date >= self::today() ) {
                    continue; // another pass got here first
                }
                $c = self::close_block( $c, HDLV2_Context_Builder::build_context( $cid, 2 ) );
                if ( 'closed' === $c->status ) {
                    // The next block starts here, by itself, so the link is not lengthened (the queued job runs
                    // with the final report's trigger, which would lengthen it); the job then makes its week 1.
                    self::start_block( $cid, (int) $c->practitioner_id, date( 'Y-m-d', strtotime( 'monday this week' ) ), false );
                    if ( class_exists( 'HDLV2_Flight_Plan_Auto_Jobs' ) ) {
                        HDLV2_Flight_Plan_Auto_Jobs::enqueue_for( $cid, 'current' );
                    }
                }
                $n++;
            } finally {
                self::unlock( $cid );
            }
        }
        return $n;
    }

    /**
     * A new active block from $week_start, only after a final report; null when none. $extend_link only for a
     * practitioner's act (the final report, their regenerate, "Start next six weeks"): a block that starts by
     * itself never lengthens the link, so the fixed 90-day window of 2026-07-10 (B4) cannot slide forever.
     */
    public static function start_block( $client_id, $practitioner_id, $week_start, $extend_link = false ) {
        global $wpdb;
        $fp = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_form_progress WHERE client_user_id = %d AND deleted_at IS NULL ORDER BY id DESC LIMIT 1",
            (int) $client_id
        ) );
        if ( ! $fp || ! $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}hdlv2_reports WHERE form_progress_id = %d AND report_type = 'final' LIMIT 1",
            (int) $fp->id
        ) ) ) {
            return null;
        }
        $last = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT cycle_number FROM ' . self::table() . ' WHERE client_id = %d ORDER BY cycle_number DESC LIMIT 1',
            (int) $client_id
        ) );
        $end = self::end_date( $week_start );
        $ok  = $wpdb->insert( self::table(), array(
            'client_id'        => (int) $client_id,
            'practitioner_id'  => (int) ( $practitioner_id ?: $fp->practitioner_user_id ),
            'form_progress_id' => (int) $fp->id,
            'cycle_number'     => $last + 1,
            'start_week'       => $week_start,
            'end_date'         => $end,
            'status'           => 'active',
            'created_at'       => current_time( 'mysql' ),
        ) );
        if ( ! $ok ) {
            return self::current( $client_id ); // another request made this number a moment ago (unique per client)
        }
        if ( ! $extend_link ) {
            return self::current( $client_id );
        }
        // C6: a live link lasts at least to the block's end plus 14 days; GREATEST never shortens it. A link
        // that has already ended is not brought back (it still needs the practitioner's re-issue).
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}hdlv2_form_progress SET token_expires_at = GREATEST(token_expires_at, %s) WHERE id = %d AND deleted_at IS NULL AND token_expires_at > UTC_TIMESTAMP()",
            self::day( $end )->modify( '+14 days' )->format( 'Y-m-d 23:59:59' ),
            (int) $fp->id
        ) );
        return self::current( $client_id );
    }

    private static function set( $id, array $data ) {
        global $wpdb;
        $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
    }

    /** One AI call; the block id, the kind and the token counts go to the log (plan 14 fix C12), never the text. */
    private static function ai( $prompt, $max_tokens, $block_id, $kind ) {
        if ( ! class_exists( 'HDLV2_AI_Service' ) ) {
            return '';
        }
        $usage = null;
        $r     = HDLV2_AI_Service::run_prompt( $prompt, $max_tokens, $usage );
        if ( is_wp_error( $r ) ) {
            error_log( '[HDLV2 FlightCycle] AI call failed: ' . $r->get_error_code() );
            return '';
        }
        error_log( sprintf( '[HDLV2 FlightCycle] block %d %s: tokens in %d, out %d', (int) $block_id, $kind, (int) ( $usage['input_tokens'] ?? 0 ), (int) ( $usage['output_tokens'] ?? 0 ) ) );
        return trim( strip_tags( (string) $r ) );
    }

    private static function checkin_lines( $rows ) {
        $out = array();
        foreach ( (array) $rows as $ci ) {
            $s     = json_decode( (string) $ci->summary, true );
            $text  = is_array( $s ) ? (string) ( $s['check_in_summary'] ?? wp_json_encode( $s ) ) : (string) $ci->summary;
            $out[] = sprintf( 'Week %d: %s', (int) ( $ci->week_number ?? 0 ), $text ) . ( ! empty( $ci->has_flags ) ? ' | Flags: ' . (string) $ci->flags : '' );
        }
        return $out ? implode( "\n", $out ) : '(none)';
    }

    /** Plan 14 fix C7: under the client's lock, the block read again inside it, so two first plans pay once. */
    private static function write_outline( $c, $context ) {
        if ( ! self::lock( $c->client_id ) ) {
            return $c; // held for 30 s by another request writing it: this plan goes on without the line
        }
        try {
            $fresh = self::block( $c->id );
            if ( $fresh && $fresh->outline ) {
                $c->outline = $fresh->outline;
                return $c;
            }
            $text = self::ai(
                self::PROMPT_OUTLINE
                . "\n\nREPORT:\n" . ( $context['report_summary'] ?? '(not available)' )
                . "\n\nWHY:\n" . HDLV2_Flight_Plan::why_text( $context['why_profile'] ?? null )
                . "\n\nLAST CHECK-INS:\n" . self::checkin_lines( $context['recent_checkins'] ?? array() ),
                600,
                $c->id,
                'outline'
            );
            if ( '' !== $text ) {
                self::set( $c->id, array( 'outline' => $text, 'outline_at' => current_time( 'mysql' ) ) );
                $c->outline = $text;
            }
            return $c;
        } finally {
            self::unlock( $c->client_id );
        }
    }

    private static function plan_ticks( $plan ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT day, category, action_text, ticked FROM {$wpdb->prefix}hdlv2_flight_plan_ticks WHERE flight_plan_id = %d AND deleted_at IS NULL",
            (int) $plan->id
        ) );
        foreach ( $rows as $r ) {
            $r->week_start          = $plan->week_start;
            $r->effective_start_day = $plan->effective_start_day;
        }
        return $rows;
    }

    /**
     * The lists and the count per day of the newest plan before $week_start, then (plan 14 fix C10) the
     * missed actions of the plans two and three weeks before $week_start, 15 lines each; a week without a
     * plan is left out. One plan per week: the newest.
     */
    private static function last_week_text( $client_id, $week_start ) {
        global $wpdb;
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, week_start, effective_start_day FROM {$wpdb->prefix}hdlv2_flight_plans WHERE client_id = %d AND deleted_at IS NULL ORDER BY week_start DESC, id DESC LIMIT 8",
            (int) $client_id
        ) );
        $weeks = array();
        foreach ( $rows as $p ) {
            if ( $p->week_start < $week_start && ! isset( $weeks[ $p->week_start ] ) ) {
                $weeks[ $p->week_start ] = $p;
            }
        }
        if ( ! $weeks ) {
            return '(no earlier week in the plan)';
        }
        $today = self::today();
        $last  = reset( $weeks );
        $ticks = self::plan_ticks( $last );
        $out   = self::lists_text( self::lists( $ticks, $today ), self::day_summary( $ticks, $last->week_start, $last->effective_start_day, $today ) );
        foreach ( array( 14 => 'Missed, two weeks ago:', 21 => 'Missed, three weeks ago:' ) as $days => $head ) {
            $ws = self::day( $week_start )->modify( '-' . $days . ' days' )->format( 'Y-m-d' );
            if ( isset( $weeks[ $ws ] ) ) {
                $missed = array_slice( self::lists( self::plan_ticks( $weeks[ $ws ] ), $today )['missed'], 0, self::MAX_OLDER_LINES );
                $out   .= "\n" . $head . "\n" . ( $missed ? implode( "\n", $missed ) : '(none)' );
            }
        }
        return $out;
    }

    private static function block_checkins( $c ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT week_number, week_start, summary, has_flags, flags FROM {$wpdb->prefix}hdlv2_checkins WHERE client_id = %d AND status = 'confirmed' AND deleted_at IS NULL ORDER BY week_start",
            (int) $c->client_id
        ) );
        return array_values( array_filter( $rows, function ( $r ) use ( $c ) {
            return $r->week_start >= $c->start_week && $r->week_start <= $c->end_date;
        } ) );
    }

    private static function block_ticks( $c ) {
        global $wpdb;
        $plans = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, week_start, effective_start_day FROM {$wpdb->prefix}hdlv2_flight_plans WHERE cycle_id = %d AND deleted_at IS NULL",
            (int) $c->id
        ) );
        $ticks = array();
        foreach ( $plans as $p ) {
            // Plan 14 fix C5: archived plans count too (a final report's regenerate archives every live plan).
            $ticks = array_merge( $ticks, self::plan_ticks( $p ) );
        }
        return $ticks;
    }

    /** The review, then closed, or review_due with the reason; the practitioner is told only when it waits. */
    private static function close_block( $c, $context ) {
        $checkins = self::block_checkins( $c );
        $review   = $c->review ? $c->review : self::ai(
            self::PROMPT_REVIEW
            . "\n\nDONE AND MISSED ACTIONS, THE SIX WEEKS:\n" . self::lists_text( self::lists( self::block_ticks( $c ), self::today() ), array() )
            . "\n\nCHECK-INS OF THE SIX WEEKS:\n" . self::checkin_lines( $checkins ),
            700,
            $c->id,
            'review'
        );
        $why = self::wait_reason( $review, $checkins, $context['engagement_signal'] ?? '' );
        $now = current_time( 'mysql' );
        $set = array( 'status' => $why ? 'review_due' : 'closed', 'closed_at' => $why ? null : $now );
        if ( '' !== $review && ! $c->review ) {
            $set['review']    = $review;
            $set['review_at'] = $now;
        }
        self::set( $c->id, $set );
        if ( $why ) {
            self::tell_practitioner( $c, $why ); // plan 14 fix C8: no e-mail when the next block starts by itself
        }
        return (object) array_merge( (array) $c, $set );
    }

    /**
     * Why the next block waits, '' when it starts by itself. Plan 14 fix C6: only a flag from the client's own
     * words holds (the two the system writes, low adherence and missed check-ins, do not), named by its own text.
     */
    private static function wait_reason( $review, $checkins, $engagement ) {
        if ( '' === (string) $review ) {
            return self::LINE_NO_REVIEW;
        }
        $flag = self::client_flag( $checkins );
        if ( '' !== $flag ) {
            return $flag;
        }
        return 'zero' === $engagement ? 'no ticks and no check-in for 14 days' : '';
    }

    /** The first flag of the block's check-ins that came from the client's words; '' when none. */
    private static function client_flag( $checkins ) {
        $system = class_exists( 'HDLV2_Checkin' ) ? HDLV2_Checkin::SYSTEM_FLAGS : array();
        foreach ( $checkins as $ci ) {
            foreach ( (array) json_decode( (string) $ci->flags, true ) as $f ) {
                $text = trim( (string) ( is_array( $f ) ? ( $f['trigger'] ?? $f['detail'] ?? '' ) : $f ) );
                if ( '' !== $text && ! in_array( $text, $system, true ) ) {
                    return $text;
                }
            }
        }
        return '';
    }

    private static function tell_practitioner( $c, $why ) {
        $prac = get_userdata( (int) $c->practitioner_id );
        if ( ! class_exists( 'HDLV2_Email_Templates' ) || ! $prac || ! $prac->user_email ) {
            return;
        }
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT client_name FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d",
            (int) $c->form_progress_id
        ) );
        HDLV2_Email_Templates::client_needs_attention( array(
            'practitioner_email' => $prac->user_email,
            'client_name'        => $name ? $name : 'Your client',
            'reasons'            => array( self::LINE_NO_REVIEW === $why ? $why : 'Six-week review written. The next six weeks wait for you: ' . $why . '.' ),
            'timeline_url'       => home_url( '/' . trim( apply_filters( 'hdlv2_practitioner_dashboard_slug', 'clients' ), '/' ) . '/' ),
            'practitioner_id'    => (int) $c->practitioner_id,
        ) );
    }

    // ── Ticks ────────────────────────────────────────────────────────────────

    private static function plan_row( $plan_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT week_start, effective_start_day FROM {$wpdb->prefix}hdlv2_flight_plans WHERE id = %d",
            (int) $plan_id
        ) );
    }

    /** One day's write fields, or 409 day_closed for a client on a locked day. The route has already checked ownership. */
    private static function fields( $plan, $weekday, $ticked, $client_id ) {
        $state = self::day_state( self::day_date( $plan->week_start, $weekday ), self::today() );
        $actor = get_current_user_id();
        if ( 'locked' === $state && ! ( $actor && HDLV2_Compatibility::is_practitioner( $actor ) ) ) {
            return new WP_Error( 'day_closed', 'This day is closed.', array( 'status' => 409 ) );
        }
        return array( 'ticked_late' => ( $ticked && 'open' !== $state ) ? 1 : 0, 'ticked_by' => $actor ? $actor : (int) $client_id );
    }

    public static function tick_fields( $plan_id, $tick_id, $ticked, $client_id ) {
        global $wpdb;
        $plan    = self::plan_row( $plan_id );
        $weekday = $wpdb->get_var( $wpdb->prepare( "SELECT day FROM {$wpdb->prefix}hdlv2_flight_plan_ticks WHERE id = %d", (int) $tick_id ) );
        if ( ! $plan || ! in_array( $weekday, self::DAYS, true ) ) {
            return new WP_Error( 'not_found', 'Tick not found.', array( 'status' => 404 ) );
        }
        return self::fields( $plan, $weekday, $ticked, $client_id );
    }

    /** day => fields for "tick all" (one day, or every day from the plan's first); locked days left out for a client. */
    public static function tick_all_fields( $plan_id, $weekday, $ticked, $client_id ) {
        $plan = self::plan_row( $plan_id );
        if ( ! $plan ) {
            return new WP_Error( 'not_found', 'Plan not found.', array( 'status' => 404 ) );
        }
        $out = array();
        foreach ( $weekday ? array( $weekday ) : array_slice( self::DAYS, self::first_day( $plan->effective_start_day ) ) as $d ) {
            $f = self::fields( $plan, $d, $ticked, $client_id );
            if ( ! is_wp_error( $f ) ) {
                $out[ $d ] = $f;
            }
        }
        return $out ? $out : new WP_Error( 'day_closed', 'This day is closed.', array( 'status' => 409 ) );
    }

    /**
     * The Flight Plan page's extra: this plan's place in the block; the review of a closed block of the same
     * form row (plan 14 fixes C3, C4: never while a block waits for the practitioner); the one line when the
     * six weeks have ended or are paused (C8).
     */
    public static function plan_view( $plan ) {
        if ( empty( $plan->cycle_id ) ) {
            return null;
        }
        global $wpdb;
        $own  = self::block( (int) $plan->cycle_id );
        $last = $own ? $wpdb->get_row( $wpdb->prepare(
            'SELECT id, review FROM ' . self::table() . ' WHERE client_id = %d AND form_progress_id = %d AND id <= %d AND review IS NOT NULL AND closed_at IS NOT NULL ORDER BY id DESC LIMIT 1',
            (int) $plan->client_id, (int) $own->form_progress_id, (int) $plan->cycle_id
        ) ) : null;
        $show = $last && ( (int) $last->id === (int) $plan->cycle_id || 1 === (int) $plan->cycle_week );
        return array( 'week' => (int) $plan->cycle_week, 'of' => self::WEEKS, 'review' => $show ? (string) $last->review : null, 'notice' => self::notice( $plan->client_id ) );
    }

    /** Plan 14 fix C8: the client's line when the block has ended (or is paused); '' otherwise. */
    private static function notice( $client_id ) {
        $c = self::current( $client_id );
        if ( ! $c ) {
            return '';
        }
        $ended = self::today() > $c->end_date;
        if ( ! $ended ) {
            return 'paused' === $c->status ? self::LINE_PAUSED : '';
        }
        return 'active' === $c->status ? self::LINE_ENDED_PASS : self::LINE_ENDED_PRACTITIONER;
    }

    /** Plan 14 fix C8: does this client's block wait for the practitioner? (the "What needs you today" item) */
    public static function review_due( $client_id ) {
        if ( ! self::on() ) {
            return false;
        }
        $c = self::current( $client_id );
        return $c && 'review_due' === $c->status;
    }

    // ── C6: a confirmed check-in is sent once ───────────────────────────────

    public static function checkin_sent( $client_id, $week_start ) {
        if ( ! self::on() ) {
            return false;
        }
        global $wpdb;
        return 'confirmed' === $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}hdlv2_checkins WHERE client_id = %d AND week_start = %s AND deleted_at IS NULL LIMIT 1",
            (int) $client_id, $week_start
        ) );
    }

    // ── The practitioner's panel and routes ────────────────────────────────

    /** Server-built, under the client's tiles (switch on only): the block's state, a review, one button. */
    public static function panel_control( $client_id ) {
        if ( ! self::on() ) {
            return '';
        }
        global $wpdb;
        $c = self::current( $client_id );
        if ( $c && 'review_due' === $c->status ) {
            $review = (string) $c->review; // this block's own: none when it could not be written (plan 14 fix C8)
        } else {
            $review = (string) $wpdb->get_var( $wpdb->prepare(
                'SELECT review FROM ' . self::table() . ' WHERE client_id = %d AND form_progress_id = %d AND review IS NOT NULL AND closed_at IS NOT NULL ORDER BY id DESC LIMIT 1',
                (int) $client_id, self::live_form( $client_id )
            ) );
        }
        $action  = 'start';
        $label   = 'Start next six weeks';
        $confirm = '';
        if ( ! $c ) {
            if ( '' === $review && ! self::start_possible( $client_id ) ) {
                return '';
            }
            $line = 'Six-week Flight Plan: not running.';
        } elseif ( 'active' === $c->status ) {
            $week   = max( 1, min( self::WEEKS, self::week_of( $c->start_week, date( 'Y-m-d', strtotime( 'monday this week' ) ) ) ) );
            $line   = sprintf( 'Six-week Flight Plan: week %d of %d.', $week, self::WEEKS );
            $action = 'pause';
            $label  = 'Pause the six weeks';
        } elseif ( 'paused' === $c->status ) {
            $line    = 'Six-week Flight Plan: paused.';
            $label   = 'Start a new six weeks'; // plan 14 fix C11: Start opens a new block at week 1
            $confirm = 'This ends the paused six weeks and starts week 1.';
        } else {
            $why  = self::wait_reason( $review, self::block_checkins( $c ), 'zero' );
            $line = self::LINE_NO_REVIEW === $why ? $why : 'Six-week Flight Plan: the review is written; the next six weeks wait for you: ' . $why . '.';
        }
        return '<div class="hdlv2-tl-grant hdlv2-fc-panel" data-client-id="' . (int) $client_id . '">'
            . '<p class="hdlv2-tl-grant-line" role="status">' . esc_html( $line ) . '</p>'
            . ( '' !== $review ? '<details class="hdlv2-fc-review"><summary>' . ( $c && 'review_due' === $c->status ? 'Six-week review' : 'Last six-week review' ) . '</summary><p>' . nl2br( esc_html( $review ) ) . '</p></details>' : '' )
            . '<button type="button" class="hdlv2-tl-grant-btn" data-fc-action="' . $action . '"' . ( '' !== $confirm ? ' data-fc-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>'
            . esc_html( $label ) . '</button>'
            . '</div>';
    }

    private static function start_possible( $client_id ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT r.id FROM {$wpdb->prefix}hdlv2_reports r INNER JOIN {$wpdb->prefix}hdlv2_form_progress fp ON fp.id = r.form_progress_id
             WHERE fp.client_user_id = %d AND fp.deleted_at IS NULL AND r.report_type = 'final' LIMIT 1",
            (int) $client_id
        ) );
    }

    public static function register_routes() {
        if ( ! self::on() ) {
            return;
        }
        register_rest_route( 'hdl-v2/v1', '/flight-cycle/pause', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_pause' ),
            'permission_callback' => array( __CLASS__, 'may_steer' ),
        ) );
        register_rest_route( 'hdl-v2/v1', '/flight-cycle/start', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_start' ),
            'permission_callback' => array( __CLASS__, 'may_steer' ),
        ) );
    }

    public static function may_steer() {
        return HDLV2_Compatibility::is_practitioner( get_current_user_id() );
    }

    /** The client id from the request when the signed-in practitioner owns the client (an admin passes), else 0. */
    private static function owned_client( $request ) {
        $client = absint( $request->get_param( 'client_id' ) );
        return $client && HDLV2_Compatibility::practitioner_owns_client( get_current_user_id(), $client ) ? $client : 0;
    }

    private static function not_found() {
        return new WP_Error( 'not_found', 'No six-week plan for this client.', array( 'status' => 404 ) );
    }

    public static function rest_pause( $request ) {
        $client = self::owned_client( $request );
        $c      = $client ? self::current( $client ) : null;
        if ( ! $c ) {
            return self::not_found();
        }
        if ( 'active' !== $c->status ) {
            return new WP_Error( 'not_running', 'The six weeks are not running.', array( 'status' => 409 ) );
        }
        self::set( $c->id, array( 'status' => 'paused' ) );
        return rest_ensure_response( array( 'success' => true, 'status' => 'paused' ) );
    }

    /** "Start next six weeks": a new block from this week (no live plan yet) or next week, and its plan queued. */
    public static function rest_start( $request ) {
        $client = self::owned_client( $request );
        if ( ! $client ) {
            return self::not_found();
        }
        if ( ! self::lock( $client ) ) {
            return new WP_Error( 'cycle_busy', 'The six-week plan is being updated. Try again.', array( 'status' => 409 ) );
        }
        try {
            return self::start_next( $client );
        } finally {
            self::unlock( $client );
        }
    }

    private static function start_next( $client ) {
        $old = self::current( $client );
        if ( $old && 'active' === $old->status ) {
            return new WP_Error( 'cycle_running', 'The six weeks are already running.', array( 'status' => 409 ) );
        }
        global $wpdb;
        $this_week = date( 'Y-m-d', strtotime( 'monday this week' ) );
        $live      = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}hdlv2_flight_plans WHERE client_id = %d AND week_start = %s AND deleted_at IS NULL AND status IN ('generated','delivered','active') LIMIT 1",
            $client, $this_week
        ) );
        $new = self::start_block( $client, 0, $live ? date( 'Y-m-d', strtotime( 'next monday' ) ) : $this_week, true );
        if ( ! $new || ( $old && (int) $new->id === (int) $old->id ) ) {
            return new WP_Error( 'no_final_report', 'The six weeks start after the final report.', array( 'status' => 409 ) );
        }
        if ( $old ) {
            self::set( $old->id, array( 'status' => 'closed', 'closed_at' => current_time( 'mysql' ) ) );
        }
        if ( class_exists( 'HDLV2_Flight_Plan_Auto_Jobs' ) ) {
            HDLV2_Flight_Plan_Auto_Jobs::enqueue_for( $client, $live ? 'next' : 'current' );
        }
        return rest_ensure_response( array( 'success' => true, 'status' => 'active' ) );
    }
}

HDLV2_Flight_Cycle::init();
