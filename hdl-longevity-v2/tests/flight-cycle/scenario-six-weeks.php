<?php
/**
 * Plan 14, C1/C5/C6: the six-week block with the real HDLV2_Flight_Cycle against a table fake.
 * Seen on Tuesday 24 Nov 2026 (the stubs' clock). A block starts at the first plan after a final
 * report and writes its outline; weeks 2 to 6 stay in it; week 7 is never a plan of that block: the
 * review is written, then the next block starts by itself, or waits (a red flag in its check-ins, or
 * zero engagement) for the practitioner's "Start next six weeks". Paused and waiting blocks refuse the
 * automatic plans. A regenerated week keeps its number. The client's link lasts to the block's end
 * plus 14 days, never shortened. The practitioner's routes: only with the switch on, only the owner.
 *
 * Run: php tests/flight-cycle/scenario-six-weeks.php   Exit: 0 all pass, 1 any fail
 */
require dirname( __DIR__ ) . '/access/stubs.php';
ac_load( array( 'sprint-5/class-hdlv2-flight-plan.php', 'sprint-5/class-hdlv2-flight-cycle.php' ) );
$C = 'HDLV2_Flight_Cycle';
if ( ! class_exists( $C ) ) { check( 'class exists', false ); done(); }

/** Rows by table; WHERE on `col = n`, `col = 'x'`, `col IS [NOT] NULL`; ORDER BY one column; LIMIT. */
class FC_Wpdb {
    public $prefix = 'wp_'; public $last_error = ''; public $insert_id = 0; public $writes = array(); public $queries = array();
    public $t = array( 'flight_cycles' => array(), 'form_progress' => array(), 'reports' => array(), 'flight_plans' => array(), 'flight_plan_ticks' => array(), 'checkins' => array() );
    public function prepare( $q, ...$a ) { foreach ( $a as $x ) $q = preg_replace( '/%[sd]/', is_int( $x ) ? (string) $x : "'" . addslashes( (string) $x ) . "'", $q, 1 ); return $q; }
    private function name( $q ) { foreach ( array_keys( $this->t ) as $n ) if ( preg_match( '/hdlv2_' . $n . '\b/', $q ) ) return $n; return null; }
    private function rows( $q ) {
        $this->queries[] = $q; $n = $this->name( $q ); if ( ! $n ) return array();
        $where = preg_match( '/WHERE (.*?)(ORDER BY|LIMIT|$)/s', $q, $m ) ? $m[1] : '';
        $out = array();
        foreach ( $this->t[ $n ] as $r ) {
            foreach ( preg_split( '/\s+AND\s+/i', trim( $where ) ) as $c ) {
                if ( preg_match( "/^(\w+) = (\d+)$/", trim( $c ), $x ) && (string) ( $r[ $x[1] ] ?? '' ) !== $x[2] ) continue 2;
                if ( preg_match( "/^(\w+) = '([^']*)'$/", trim( $c ), $x ) && (string) ( $r[ $x[1] ] ?? '' ) !== $x[2] ) continue 2;
                if ( preg_match( '/^(\w+) IS NULL$/', trim( $c ), $x ) && null !== ( $r[ $x[1] ] ?? null ) ) continue 2;
                if ( preg_match( '/^(\w+) IS NOT NULL$/', trim( $c ), $x ) && null === ( $r[ $x[1] ] ?? null ) ) continue 2;
            }
            $out[] = (object) $r;
        }
        if ( preg_match( '/ORDER BY (\w+)( DESC)?/', $q, $o ) ) usort( $out, function ( $a, $b ) use ( $o ) { $c = strcmp( (string) $a->{$o[1]}, (string) $b->{$o[1]} ); if ( 0 === $c ) $c = $a->id - $b->id; return empty( $o[2] ) ? $c : -$c; } );
        if ( preg_match( '/LIMIT (\d+)/', $q, $l ) ) $out = array_slice( $out, 0, (int) $l[1] );
        return $out;
    }
    public function get_results( $q ) { return $this->rows( $q ); }
    public function get_row( $q ) { $r = $this->rows( $q ); return $r ? $r[0] : null; }
    public $locks = array();
    public function get_var( $q ) { if ( preg_match( "/GET_LOCK\('([\w]+)', 30\)/", $q, $l ) ) { $this->locks[] = $l[1]; return isset( $GLOBALS['lock_busy'] ) ? '0' : '1'; } $r = $this->rows( $q ); if ( ! $r ) return null; $a = (array) $r[0]; return preg_match( '/SELECT\s+(\w+)\s/i', $q, $m ) && array_key_exists( $m[1], $a ) ? $a[ $m[1] ] : reset( $a ); }
    public function get_col( $q ) { return array(); }
    public function query( $q ) { $this->queries[] = $q; if ( preg_match( '/^\s*(UPDATE|INSERT|DELETE)/i', $q ) ) $this->writes[] = $q; return 1; }
    public function insert( $table, $data ) {
        $n = $this->name( $table ); $this->writes[] = array( 'insert', $n, $data );
        if ( 'flight_cycles' === $n ) foreach ( $this->t[ $n ] as $r ) if ( (int) $r['client_id'] === (int) $data['client_id'] && (int) $r['cycle_number'] === (int) $data['cycle_number'] ) { $this->last_error = 'Duplicate entry'; return false; }
        $this->last_error = ''; $this->insert_id = 500 + count( $this->writes );
        $this->t[ $n ][] = array_merge( array( 'id' => $this->insert_id, 'outline' => null, 'review' => null, 'closed_at' => null ), $data );
        return 1;
    }
    public function update( $table, $data, $where ) {
        $n = $this->name( $table ); $this->writes[] = array( 'update', $n, $data, $where ); $k = 0;
        foreach ( $this->t[ $n ] as $i => $r ) { foreach ( $where as $c => $v ) if ( (string) ( $r[ $c ] ?? '' ) !== (string) $v ) continue 2; $this->t[ $n ][ $i ] = array_merge( $r, $data ); $k++; }
        return $k;
    }
}
class HDLV2_AI_Service {
    public static $prompts = array(); public static $fail = false;
    public static function run_prompt( $p, $max ) {
        self::$prompts[] = $p;
        if ( self::$fail ) return new WP_Error( 'claude_api_error', 'down' );
        return 0 === strpos( $p, 'From the client' ) ? "Week 1: Walk after lunch.\nWeek 2: Water at breakfast.\nWeek 3: Bed by 23:00.\nWeek 4: Two walks.\nWeek 5: Cook twice.\nWeek 6: Keep it up." : 'Walked most days. Missed the late snacks rule.';
    }
}
class HDLV2_Email_Templates { public static $sent = array(); public static function client_needs_attention( $d ) { self::$sent[] = $d; } }
class HDLV2_Flight_Plan_Auto_Jobs { public static $jobs = array(); public static function enqueue_for( $c, $t ) { self::$jobs[] = array( (int) $c, $t ); return 1; } }

function sw_world() {
    ac_world(); // users, options, clock
    $w = new FC_Wpdb();
    $w->t['form_progress'][] = array( 'id' => 14, 'client_user_id' => 13, 'practitioner_user_id' => 3, 'client_name' => 'Rosa Vale', 'deleted_at' => null, 'token_expires_at' => '2026-12-01 00:00:00' );
    $w->t['reports'][]       = array( 'id' => 70, 'form_progress_id' => 14, 'report_type' => 'final', 'status' => 'ready' );
    $GLOBALS['wpdb'] = $w;
    HDLV2_AI_Service::$prompts = array(); HDLV2_AI_Service::$fail = false; HDLV2_Email_Templates::$sent = array(); HDLV2_Flight_Plan_Auto_Jobs::$jobs = array();
    $GLOBALS['options']['hdlv2_ff_flight_cycle'] = 1;
    return $w;
}
function cyc( $w ) { return array_values( $w->t['flight_cycles'] ); }
$ctx = array( 'report_summary' => 'Pace 1.1', 'why_profile' => array( 'distilled_why' => 'Grandchildren' ), 'recent_checkins' => array(), 'engagement_signal' => 'medium' );

// ── Switch absent: nothing happens.
$w = sw_world(); unset( $GLOBALS['options']['hdlv2_ff_flight_cycle'] );
check( 'S.1 absent: before_plan answers null and reads nothing', null === $C::before_plan( 13, 3, '2026-10-12', 'final_report', $ctx ) && 0 === count( $w->queries ) );

// ── Block 1 starts at the first plan after the final report.
$w = sw_world();
$p = $C::before_plan( 13, 3, '2026-10-12', 'final_report', $ctx );
$c = cyc( $w );
check( 'S.2 a block starts: number 1, start week, last day, active', 1 === count( $c ) && 1 === (int) $c[0]['cycle_number'] && '2026-10-12' === $c[0]['start_week'] && '2026-11-22' === $c[0]['end_date'] && 'active' === $c[0]['status'] && 14 === (int) $c[0]['form_progress_id'] );
check( 'S.3 its plan is week 1, with the block\'s id', is_array( $p ) && 1 === $p['week'] && (int) $c[0]['id'] === (int) $p['cycle_id'] );
check( 'S.4 the outline is written once, the prompt word for word first', 1 === count( HDLV2_AI_Service::$prompts ) && 0 === strpos( HDLV2_AI_Service::$prompts[0], "From the client's report, their WHY and their last check-ins, write a six-week outline: one line per week, each a single aim that builds on the week before. Use only the information provided. No diagnoses, no medication advice. Six lines, 'Week n: aim'." ) && false !== strpos( $c[0]['outline'], 'Week 3: Bed by 23:00.' ) );
check( 'S.5 the outline reads the report, the WHY and the check-ins', false !== strpos( HDLV2_AI_Service::$prompts[0], 'Pace 1.1' ) && false !== strpos( HDLV2_AI_Service::$prompts[0], 'Grandchildren' ) );
check( 'S.6 the week line carries the outline\'s aim; no earlier week says so', false !== strpos( $p['block'], "This is week 1 of 6. This week's aim from the six-week outline: Walk after lunch." ) && false !== strpos( $p['block'], '(no earlier week in the plan)' ) );
$link = preg_grep( '/token_expires_at = GREATEST\(token_expires_at, \'2026-12-06 23:59:59\'\) WHERE id = 14 AND deleted_at IS NULL AND token_expires_at > UTC_TIMESTAMP\(\)$/', array_filter( $w->writes, 'is_string' ) );
check( 'S.7 a live link lasts at least to the last day + 14, never shortened (GREATEST); an ended link is not brought back', 1 === count( $link ) );

foreach ( array( '2026-10-19' => 2, '2026-11-02' => 4, '2026-11-16' => 6 ) as $wk => $n ) {
    $p = $C::before_plan( 13, 3, $wk, 'auto', $ctx );
    check( "S.8 $wk is week $n of the same block, no new outline", is_array( $p ) && $n === $p['week'] && 1 === count( cyc( $w ) ) && 1 === count( HDLV2_AI_Service::$prompts ) );
}
$p = $C::before_plan( 13, 3, '2026-11-02', 'final_report', $ctx );
check( 'S.9 a regenerated week (report redone) keeps its number', is_array( $p ) && 4 === $p['week'] && 1 === count( cyc( $w ) ) );

// The block's plans and ticks, for the review.
$w->t['flight_plans'][] = array( 'id' => 601, 'client_id' => 13, 'cycle_id' => cyc( $w )[0]['id'], 'week_start' => '2026-11-16', 'effective_start_day' => 'monday', 'status' => 'active', 'deleted_at' => null );
$w->t['flight_plans'][] = array( 'id' => 602, 'client_id' => 13, 'cycle_id' => cyc( $w )[0]['id'], 'week_start' => '2026-11-09', 'effective_start_day' => 'monday', 'status' => 'archived', 'deleted_at' => null );
$w->t['flight_plan_ticks'][] = array( 'id' => 1, 'flight_plan_id' => 601, 'day' => 'monday', 'category' => 'movement', 'action_text' => 'Walk', 'ticked' => 1, 'deleted_at' => null );
$w->t['flight_plan_ticks'][] = array( 'id' => 2, 'flight_plan_id' => 601, 'day' => 'tuesday', 'category' => 'nutrition', 'action_text' => 'No late snacks', 'ticked' => 0, 'deleted_at' => null );
$w->t['flight_plan_ticks'][] = array( 'id' => 3, 'flight_plan_id' => 602, 'day' => 'monday', 'category' => 'movement', 'action_text' => 'Archived walk', 'ticked' => 1, 'deleted_at' => null );
$w->t['checkins'][] = array( 'id' => 40, 'client_id' => 13, 'week_number' => 5, 'week_start' => '2026-11-09', 'summary' => '{"check_in_summary":"Better sleep."}', 'has_flags' => 0, 'flags' => '[]', 'status' => 'confirmed', 'deleted_at' => null );
$w->t['checkins'][] = array( 'id' => 41, 'client_id' => 13, 'week_number' => 1, 'week_start' => '2026-09-28', 'summary' => '{"check_in_summary":"Before the block."}', 'has_flags' => 1, 'flags' => '["chest pain"]', 'status' => 'confirmed', 'deleted_at' => null );

// ── Week 7: no plan in block 1; review; block 2 starts by itself.
$p = $C::before_plan( 13, 3, '2026-11-23', 'auto', $ctx );
$c = cyc( $w );
check( 'S.10 week 7: block 1 closed with a review', 'closed' === $c[0]['status'] && 'Walked most days. Missed the late snacks rule.' === $c[0]['review'] && ! empty( $c[0]['closed_at'] ) && ! empty( $c[0]['review_at'] ) );
$rp = HDLV2_AI_Service::$prompts[1] ?? '';
check( 'S.11 the review prompt word for word first', 0 === strpos( $rp, 'Write a six-week review for the client and their practitioner from the lists provided: what was done most, what was missed most, what the check-ins said, and three aims for the next six weeks. Use only the information provided. No diagnoses, no medication advice. Put any red flag from the check-ins first. 180 words at most.' ) );
check( 'S.12 the review reads the block\'s done and missed lists and its check-ins only', false !== strpos( $rp, '- movement: Walk (1 day)' ) && false !== strpos( $rp, '- nutrition: No late snacks (1 day)' ) && false !== strpos( $rp, 'Better sleep.' ) && false === strpos( $rp, 'Before the block.' ) && false === strpos( $rp, 'Archived walk' ) );
check( 'S.13 the next block starts by itself: number 2, week 1', 2 === count( $c ) && 2 === (int) $c[1]['cycle_number'] && 'active' === $c[1]['status'] && is_array( $p ) && 1 === $p['week'] && '2026-11-23' === $c[1]['start_week'] );
check( 'S.14 the practitioner gets the existing kind of e-mail, once', 1 === count( HDLV2_Email_Templates::$sent ) && 'ada@example.test' === HDLV2_Email_Templates::$sent[0]['practitioner_email'] );
check( 'S.15 the week-1 block of block 2 lists last week\'s done and missed actions', false !== strpos( $p['block'], '- movement: Walk (1 day)' ) && false !== strpos( $p['block'], "Missed:\n- nutrition: No late snacks (1 day)" ) );

// ── A red flag in the block's check-ins: the next block waits.
$w = sw_world();
$C::before_plan( 13, 3, '2026-10-12', 'final_report', $ctx );
$w->t['checkins'][] = array( 'id' => 42, 'client_id' => 13, 'week_number' => 4, 'week_start' => '2026-11-02', 'summary' => '{"check_in_summary":"Chest pain on stairs."}', 'has_flags' => 1, 'flags' => '["chest pain"]', 'status' => 'confirmed', 'deleted_at' => null );
$p = $C::before_plan( 13, 3, '2026-11-23', 'auto', $ctx );
$c = cyc( $w );
check( 'S.16 red flag: block 1 is review_due, no block 2, the automatic plan refused', 1 === count( $c ) && 'review_due' === $c[0]['status'] && $p instanceof WP_Error && 'cycle_waits' === $p->get_error_code() );
check( 'S.17 red flag: the review prompt carries the flag', false !== strpos( end( HDLV2_AI_Service::$prompts ), 'chest pain' ) );
check( 'S.18 the check-in\'s plan is refused too; the review is not written twice', $C::before_plan( 13, 3, '2026-11-23', 'checkin', $ctx ) instanceof WP_Error && 2 === count( HDLV2_AI_Service::$prompts ) );
$e = HDLV2_Email_Templates::$sent[0]['reasons'][0] ?? '';
check( 'S.19 the practitioner\'s e-mail says the next six weeks wait, and why', false !== stripos( $e, 'wait' ) && false !== stripos( $e, 'red flag' ) );

// ── The practitioner's routes.
$GLOBALS['routes'] = array(); $C::register_routes();
check( 'S.20 routes registered with the switch on: pause and start', isset( $GLOBALS['routes']['/hdl-v2/v1/flight-cycle/pause'], $GLOBALS['routes']['/hdl-v2/v1/flight-cycle/start'] ) );
$GLOBALS['current_user'] = 4;
$r = $C::rest_start( new FakeRequest( array( 'client_id' => 13 ) ) );
check( 'S.21 another practitioner → 404, nothing changes', $r instanceof WP_Error && 404 === ( $r->get_error_data()['status'] ?? 0 ) && 'review_due' === cyc( $w )[0]['status'] );
$GLOBALS['current_user'] = 3;
$r = $C::rest_start( new FakeRequest( array( 'client_id' => 13 ) ) );
$c = cyc( $w );
check( 'S.22 the owner\'s "Start next six weeks": block 1 closed, block 2 active, a plan job queued', ! is_wp_error( $r ) && 'closed' === $c[0]['status'] && 2 === count( $c ) && 'active' === $c[1]['status'] && 1 === count( HDLV2_Flight_Plan_Auto_Jobs::$jobs ) );
$r = $C::rest_start( new FakeRequest( array( 'client_id' => 13 ) ) );
check( 'S.23 start while a block runs → 409, no second block, no second job', $r instanceof WP_Error && 409 === ( $r->get_error_data()['status'] ?? 0 ) && 2 === count( cyc( $w ) ) && 1 === count( HDLV2_Flight_Plan_Auto_Jobs::$jobs ) );
$r = $C::rest_pause( new FakeRequest( array( 'client_id' => 13 ) ) );
check( 'S.24 pause: the running block is paused', ! is_wp_error( $r ) && 'paused' === cyc( $w )[1]['status'] );
check( 'S.25 paused: the automatic and check-in plans are refused', $C::before_plan( 13, 3, '2026-11-30', 'auto', $ctx ) instanceof WP_Error && $C::before_plan( 13, 3, '2026-11-30', 'checkin', $ctx ) instanceof WP_Error );
$p = $C::before_plan( 13, 3, '2026-11-30', 'manual', $ctx );
check( 'S.26 paused: the practitioner\'s own regenerate still makes the plan, in its week', is_array( $p ) && 'paused' === cyc( $w )[1]['status'] );
$GLOBALS['current_user'] = 1;
check( 'S.27 an admin may start a paused block', ! is_wp_error( $C::rest_start( new FakeRequest( array( 'client_id' => 13 ) ) ) ) && 3 === count( cyc( $w ) ) );
$GLOBALS['current_user'] = 0;
unset( $GLOBALS['options']['hdlv2_ff_flight_cycle'] ); $GLOBALS['routes'] = array(); $C::register_routes();
check( 'S.28 switch absent: no route registered', array() === $GLOBALS['routes'] );

// ── No final report: no block (the plan is made as today).
$w = sw_world(); $w->t['reports'] = array();
check( 'S.29 no final report: no block, null, nothing stored', null === $C::before_plan( 13, 3, '2026-10-12', 'auto', $ctx ) && array() === cyc( $w ) );
// ── Zero engagement at week 7: waits.
$w = sw_world();
$C::before_plan( 13, 3, '2026-10-12', 'final_report', $ctx );
$p = $C::before_plan( 13, 3, '2026-11-23', 'auto', array( 'engagement_signal' => 'zero' ) + $ctx );
check( 'S.30 zero engagement at week 7: review_due, the plan refused', $p instanceof WP_Error && 'review_due' === cyc( $w )[0]['status'] );
// ── The review fails: the block waits for the practitioner (a person looks when the machine failed).
$w = sw_world();
$C::before_plan( 13, 3, '2026-10-12', 'final_report', $ctx );
HDLV2_AI_Service::$fail = true;
$p = $C::before_plan( 13, 3, '2026-11-23', 'auto', $ctx );
check( 'S.31 review not written: review_due, the plan refused', $p instanceof WP_Error && 'review_due' === cyc( $w )[0]['status'] && null === cyc( $w )[0]['review'] );
// ── The outline fails: the block runs, the next plan tries again.
$w = sw_world(); HDLV2_AI_Service::$fail = true;
$p = $C::before_plan( 13, 3, '2026-10-12', 'final_report', $ctx );
HDLV2_AI_Service::$fail = false;
$p2 = $C::before_plan( 13, 3, '2026-10-19', 'auto', $ctx );
check( 'S.32 outline failed: the block still runs; written at the next plan', is_array( $p ) && false !== strpos( $p['block'], '(no outline yet)' ) && is_array( $p2 ) && false !== strpos( (string) cyc( $w )[0]['outline'], 'Week 2' ) );
// ── Two plans starting a block at once: one block.
$w = sw_world();
$w->t['flight_cycles'][] = array( 'id' => 77, 'client_id' => 13, 'cycle_number' => 1, 'start_week' => '2026-10-12', 'end_date' => '2026-11-22', 'status' => 'closed', 'outline' => 'x', 'review' => 'old', 'closed_at' => '2026-11-23 00:00:00' );
$p = $C::before_plan( 13, 3, '2026-11-23', 'auto', $ctx );
check( 'S.33 after a closed block, the next is number 2', is_array( $p ) && 2 === (int) cyc( $w )[1]['cycle_number'] );
$w = sw_world();
check( 'S.34 a sparse-plan repair never starts a block', null === $C::before_plan( 13, 3, '2026-11-23', 'auto_repair', $ctx ) && array() === cyc( $w ) );
// Review fixes (5d712f5): the link only on a practitioner's act; one lock at a block's end; waits are no-ops.
$link_writes = function ( $w ) { return count( preg_grep( '/token_expires_at = GREATEST/', array_filter( $w->writes, 'is_string' ) ) ); };
$w = sw_world();
$C::before_plan( 13, 3, '2026-10-12', 'final_report', $ctx );
$C::before_plan( 13, 3, '2026-11-23', 'checkin', $ctx );
check( 'S.35 a block that starts by itself (check-in at week 7) never lengthens the link; the first (final report) did', 2 === count( cyc( $w ) ) && 1 === $link_writes( $w ) );
$w = sw_world(); $C::before_plan( 13, 3, '2026-10-12', 'auto', $ctx );
check( 'S.36 a block started by the weekly cron does not lengthen the link', 1 === count( cyc( $w ) ) && 0 === $link_writes( $w ) );
check( 'S.37 within a block no lock is taken', array() === $w->locks );
$C::before_plan( 13, 3, '2026-11-23', 'auto', $ctx );
check( 'S.38 crossing the block\'s end takes the client\'s lock', array( 'hdlv2_fc_13' ) === $w->locks && false !== strpos( implode( ' ', $w->queries ), "RELEASE_LOCK('hdlv2_fc_13')" ) );
$w = sw_world(); $C::before_plan( 13, 3, '2026-10-12', 'final_report', $ctx ); $GLOBALS['lock_busy'] = 1; $n = count( HDLV2_AI_Service::$prompts );
$r = $C::before_plan( 13, 3, '2026-11-23', 'auto', $ctx ); unset( $GLOBALS['lock_busy'] );
check( 'S.39 lock held elsewhere: cycle_busy (the job retries), no review paid, the block unchanged', $r instanceof WP_Error && 'cycle_busy' === $r->get_error_code() && $n === count( HDLV2_AI_Service::$prompts ) && 'active' === cyc( $w )[0]['status'] );
check( 'S.40 the outline\'s aim is cut at 200 characters', 200 === mb_strlen( $C::outline_line( 'Week 1: ' . str_repeat( 'x', 500 ), 1 ) ) );
$src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/sprint-5/class-hdlv2-flight-plan.php' );
check( 'S.41 generate(): a wait by design answers null (the queue\'s no-op), other refusals stay errors', false !== strpos( $src, "return 'cycle_waits' === \$cycle->get_error_code() ? null : \$cycle;" ) );
done();
