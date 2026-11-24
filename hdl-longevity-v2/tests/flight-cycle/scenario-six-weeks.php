<?php
/**
 * Plan 14, C1/C5/C6 and the fix rows C2, C5, C6, C7, C12: the six-week block with the real
 * HDLV2_Flight_Cycle against a table fake. Seen on Tuesday 24 Nov 2026 (the stubs' clock). A block starts
 * at the first plan after a final report and writes its outline once; weeks 2 to 6 stay in it; a plan
 * for week 7 waits while the block runs, for every trigger. No block closes before the site's date is past
 * its last day: the daily pass (roll_due) then writes one review from all six weeks (archived plans
 * included) and the next block starts by itself, or waits (a flag from the client's own words, zero
 * engagement, or no review) for the practitioner's "Start next six weeks". The system's own flags do not
 * hold. Paused and waiting blocks refuse the automatic plans. A regenerated week keeps its number. The
 * client's link lasts to the block's end plus 14 days on a practitioner's act only. Token counts logged.
 *
 * Run: php tests/flight-cycle/scenario-six-weeks.php   Exit: 0 all pass, 1 any fail
 */
require __DIR__ . '/fc-world.php';
$C = 'HDLV2_Flight_Cycle';
if ( ! class_exists( $C ) ) { check( 'class exists', false ); done(); }

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

// The block's plans (weeks 1, 2, 3 archived by a report redone in week 3; 3 again and 6 live) and ticks, for the review.
$bid = (int) cyc( $w )[0]['id'];
foreach ( array( array( 601, '2026-11-16', 'active' ), array( 602, '2026-10-12', 'archived' ), array( 603, '2026-10-19', 'archived' ), array( 604, '2026-10-26', 'archived' ), array( 605, '2026-10-26', 'active' ) ) as $pl ) {
    $w->t['flight_plans'][] = array( 'id' => $pl[0], 'client_id' => 13, 'cycle_id' => $bid, 'week_start' => $pl[1], 'effective_start_day' => 'monday', 'status' => $pl[2], 'deleted_at' => null );
}
$w->t['flight_plan_ticks'][] = array( 'id' => 1, 'flight_plan_id' => 601, 'day' => 'monday', 'category' => 'movement', 'action_text' => 'Walk', 'ticked' => 1, 'deleted_at' => null );
$w->t['flight_plan_ticks'][] = array( 'id' => 2, 'flight_plan_id' => 601, 'day' => 'tuesday', 'category' => 'nutrition', 'action_text' => 'No late snacks', 'ticked' => 0, 'deleted_at' => null );
$w->t['flight_plan_ticks'][] = array( 'id' => 3, 'flight_plan_id' => 602, 'day' => 'monday', 'category' => 'movement', 'action_text' => 'Week one stretch', 'ticked' => 1, 'deleted_at' => null );
$w->t['flight_plan_ticks'][] = array( 'id' => 4, 'flight_plan_id' => 603, 'day' => 'monday', 'category' => 'key_action', 'action_text' => 'Week two journal', 'ticked' => 1, 'deleted_at' => null );
$w->t['checkins'][] = array( 'id' => 40, 'client_id' => 13, 'week_number' => 5, 'week_start' => '2026-11-09', 'summary' => '{"check_in_summary":"Better sleep."}', 'has_flags' => 0, 'flags' => '[]', 'status' => 'confirmed', 'deleted_at' => null );
$w->t['checkins'][] = array( 'id' => 43, 'client_id' => 13, 'week_number' => 6, 'week_start' => '2026-11-16', 'summary' => '{"check_in_summary":"Week six went well."}', 'has_flags' => 1, 'flags' => '[{"trigger":"Low adherence for 2+ consecutive weeks","severity":"medium"}]', 'status' => 'confirmed', 'deleted_at' => null );
$w->t['checkins'][] = array( 'id' => 41, 'client_id' => 13, 'week_number' => 1, 'week_start' => '2026-09-28', 'summary' => '{"check_in_summary":"Before the block."}', 'has_flags' => 1, 'flags' => '["chest pain"]', 'status' => 'confirmed', 'deleted_at' => null );

// ── C2: week 7 waits while the block still runs; the daily pass closes it once, after its last day.
$n = count( HDLV2_AI_Service::$prompts );
foreach ( array( 'auto', 'checkin', 'final_report', 'manual' ) as $tr ) {
    $p = $C::before_plan( 13, 3, '2026-11-23', $tr, $ctx );
    check( "S.10 week 7, trigger $tr, block not yet passed: cycle_waits, nothing paid, the block still active", $p instanceof WP_Error && 'cycle_waits' === $p->get_error_code() && $n === count( HDLV2_AI_Service::$prompts ) && 'active' === cyc( $w )[0]['status'] );
}
check( 'S.10b no e-mail while it waits', array() === HDLV2_Email_Templates::$sent );
$log = tempnam( sys_get_temp_dir(), 'hdlv2-fc-' ); ini_set( 'error_log', $log );
$this_week = date( 'Y-m-d', strtotime( 'monday this week' ) ); // the plugin's week rule (PHP clock)
check( 'S.11 the daily pass reviews one block', 1 === $C::roll_due() );
ini_set( 'error_log', '/dev/null' ); $logged = (string) file_get_contents( $log ); @unlink( $log );
$c  = cyc( $w );
$rp = HDLV2_AI_Service::$prompts[ $n ] ?? '';
check( 'S.12 block 1 closed with its review; the review prompt word for word first', 'closed' === $c[0]['status'] && 'Walked most days. Missed the late snacks rule.' === $c[0]['review'] && ! empty( $c[0]['closed_at'] ) && 0 === strpos( $rp, 'Write a six-week review for the client and their practitioner from the lists provided: what was done most, what was missed most, what the check-ins said, and three aims for the next six weeks. Use only the information provided. No diagnoses, no medication advice. Put any red flag from the check-ins first. 180 words at most.' ) );
check( 'S.12b C5: the review reads all six weeks, archived plans included (weeks 1 and 2 survive a report redone in week 3)', false !== strpos( $rp, '- movement: Walk (1 day)' ) && false !== strpos( $rp, '- nutrition: No late snacks (1 day)' ) && false !== strpos( $rp, 'Week one stretch' ) && false !== strpos( $rp, 'Week two journal' ) );
check( 'S.12c the review reads the block\'s check-ins, the week-6 one included, and none before the block', false !== strpos( $rp, 'Better sleep.' ) && false !== strpos( $rp, 'Week six went well.' ) && false === strpos( $rp, 'Before the block.' ) );
check( 'S.13 C6: the system\'s own flag (low adherence) does not hold: the next block starts by itself from this week', 2 === count( $c ) && 2 === (int) $c[1]['cycle_number'] && 'active' === $c[1]['status'] && $this_week === $c[1]['start_week'] );
check( 'S.13b its plan is queued through the existing job (this week)', array( array( 13, 'current' ) ) === HDLV2_Flight_Plan_Auto_Jobs::$jobs );
check( 'S.14 C8: no e-mail when the next block starts by itself', array() === HDLV2_Email_Templates::$sent );
check( 'S.15 C12: the review\'s token counts are logged with the block id, never its text', false !== strpos( $logged, "[HDLV2 FlightCycle] block $bid review: tokens in " ) && false !== strpos( $logged, ', out 90' ) && false === strpos( $logged, 'Walked most days' ) );
check( 'S.15b the next pass reviews nothing (the block is closed)', 0 === $C::roll_due() && $n + 1 === count( HDLV2_AI_Service::$prompts ) );
$p = $C::before_plan( 13, 3, $this_week, 'final_report', $ctx );
check( 'S.15c the queued job\'s plan is week 1 of block 2 and lists last week\'s done and missed actions', is_array( $p ) && 1 === $p['week'] && (int) $c[1]['id'] === $p['cycle_id'] && false !== strpos( $p['block'], '- movement: Walk (1 day)' ) );

// ── C2/C6: a flag from the client's words in week 6 holds the next block.
$w = sw_world();
$C::before_plan( 13, 3, '2026-10-12', 'final_report', $ctx );
$w->t['checkins'][] = array( 'id' => 42, 'client_id' => 13, 'week_number' => 6, 'week_start' => '2026-11-16', 'summary' => '{"check_in_summary":"Chest pain on stairs."}', 'has_flags' => 1, 'flags' => '[{"trigger":"Chest pain on the stairs","severity":"high"}]', 'status' => 'confirmed', 'deleted_at' => null );
$p = $C::before_plan( 13, 3, '2026-11-23', 'checkin', $ctx );
check( 'S.16 the week-6 check-in\'s plan for week 7 waits; nothing reviewed yet', $p instanceof WP_Error && 'cycle_waits' === $p->get_error_code() && 1 === count( HDLV2_AI_Service::$prompts ) && 'active' === cyc( $w )[0]['status'] );
$C::roll_due();
$c = cyc( $w );
check( 'S.17 the pass: review_due, no block 2, no job', 1 === count( $c ) && 'review_due' === $c[0]['status'] && null === $c[0]['closed_at'] && array() === HDLV2_Flight_Plan_Auto_Jobs::$jobs );
check( 'S.17b the review prompt carries the flag', false !== strpos( end( HDLV2_AI_Service::$prompts ), 'Chest pain on the stairs' ) );
$C::roll_due();
check( 'S.18 two passes: one review, one e-mail', 2 === count( HDLV2_AI_Service::$prompts ) && 1 === count( HDLV2_Email_Templates::$sent ) );
$e = HDLV2_Email_Templates::$sent[0]['reasons'][0] ?? '';
check( 'S.19 C6: the e-mail says the next six weeks wait and names the flag\'s own text', 'Six-week review written. The next six weeks wait for you: Chest pain on the stairs.' === $e );
check( 'S.19b the automatic plans stay refused while it waits', $C::before_plan( 13, 3, '2026-11-23', 'auto', $ctx ) instanceof WP_Error && 2 === count( HDLV2_AI_Service::$prompts ) );

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
// ── Zero engagement at the pass: waits.
$w = sw_world();
$C::before_plan( 13, 3, '2026-10-12', 'final_report', $ctx );
$GLOBALS['engagement'] = 'zero';
$C::roll_due();
check( 'S.30 zero engagement at the pass: review_due, no job', 'review_due' === cyc( $w )[0]['status'] && array() === HDLV2_Flight_Plan_Auto_Jobs::$jobs );
check( 'S.30b the e-mail names it', 'Six-week review written. The next six weeks wait for you: no ticks and no check-in for 14 days.' === ( HDLV2_Email_Templates::$sent[0]['reasons'][0] ?? '' ) );
// ── The review fails: the block waits for the practitioner (a person looks when the machine failed).
$w = sw_world();
$C::before_plan( 13, 3, '2026-10-12', 'final_report', $ctx );
HDLV2_AI_Service::$fail = true;
$C::roll_due();
check( 'S.31 review not written: review_due, no review stored', 'review_due' === cyc( $w )[0]['status'] && null === cyc( $w )[0]['review'] );
check( 'S.31b C8: the e-mail says so in true words', 'The six-week review could not be written. The next six weeks wait for you.' === ( HDLV2_Email_Templates::$sent[0]['reasons'][0] ?? '' ) );
// ── A site that is not live, and a locked client: the pass writes nothing and sends nothing.
$w = sw_world(); $C::before_plan( 13, 3, '2026-10-12', 'final_report', $ctx ); $n = count( HDLV2_AI_Service::$prompts );
$GLOBALS['env_live'] = 0;
check( 'S.31c not the live site: no AI call, no e-mail, the block still active', 0 === $C::roll_due() && $n === count( HDLV2_AI_Service::$prompts ) && array() === HDLV2_Email_Templates::$sent && 'active' === cyc( $w )[0]['status'] );
$GLOBALS['env_live'] = 1; $GLOBALS['options']['hdlv2_ff_access_gate'] = '1'; $w->t['reports'] = array();
check( 'S.31d a client the access door locks: skipped', 0 === $C::roll_due() && 'active' === cyc( $w )[0]['status'] );
unset( $GLOBALS['options']['hdlv2_ff_access_gate'] );
// ── A block whose last day is today or later is not touched by the pass.
$w = sw_world(); $C::before_plan( 13, 3, '2026-10-19', 'final_report', $ctx ); // ends Sunday 29 Nov: today is in its week 6
check( 'S.31e in week 6 the pass does nothing; the cron\'s plan for next Monday waits', 0 === $C::roll_due() && $C::before_plan( 13, 3, '2026-11-30', 'auto', $ctx ) instanceof WP_Error && 1 === count( HDLV2_AI_Service::$prompts ) );
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
// Review fixes (5d712f5) and fix rows C2, C7, C12: the link only on a practitioner's act; locks; outline once.
$link_writes = function ( $w ) { return count( preg_grep( '/token_expires_at = GREATEST/', array_filter( $w->writes, 'is_string' ) ) ); };
$w = sw_world();
$C::before_plan( 13, 3, '2026-10-12', 'final_report', $ctx );
$C::roll_due();
check( 'S.35 a block the pass starts never lengthens the link; the first (final report) did', 2 === count( cyc( $w ) ) && 1 === $link_writes( $w ) );
$w = sw_world(); $C::before_plan( 13, 3, '2026-10-12', 'auto', $ctx );
check( 'S.36 a block started by the weekly cron does not lengthen the link', 1 === count( cyc( $w ) ) && 0 === $link_writes( $w ) );
check( 'S.37 C7: the outline is written under the client\'s lock, released after', array( 'hdlv2_fc_13' ) === $w->locks && false !== strpos( implode( ' ', $w->queries ), "RELEASE_LOCK('hdlv2_fc_13')" ) );
$C::before_plan( 13, 3, '2026-10-19', 'auto', $ctx );
check( 'S.37b within a block with its outline no lock is taken', array( 'hdlv2_fc_13' ) === $w->locks );
$w->locks = array();
$C::roll_due();
check( 'S.38 the pass takes the client\'s lock and releases it', in_array( 'hdlv2_fc_13', $w->locks, true ) && substr_count( implode( ' ', $w->queries ), "RELEASE_LOCK('hdlv2_fc_13')" ) >= 2 );
$w = sw_world(); $C::before_plan( 13, 3, '2026-10-12', 'final_report', $ctx ); $GLOBALS['lock_busy'] = 1; $n = count( HDLV2_AI_Service::$prompts );
$r = $C::roll_due(); unset( $GLOBALS['lock_busy'] );
check( 'S.39 lock held by another pass: skipped, no review paid, the block unchanged', 0 === $r && $n === count( HDLV2_AI_Service::$prompts ) && 'active' === cyc( $w )[0]['status'] );
// C7: a first plan that waited for the lock finds the outline the other one wrote, and pays nothing.
$w = sw_world();
$w->t['form_progress'][0]['token_expires_at'] = '2026-12-01 00:00:00';
$w->t['flight_cycles'][] = array( 'id' => 90, 'client_id' => 13, 'form_progress_id' => 14, 'cycle_number' => 1, 'start_week' => '2026-11-23', 'end_date' => '2027-01-03', 'status' => 'active', 'outline' => null, 'review' => null, 'closed_at' => null );
$GLOBALS['on_lock'] = function ( $db ) { $db->t['flight_cycles'][0]['outline'] = "Week 1: Written by the other request.\nWeek 2: Two."; };
$p = $C::before_plan( 13, 3, '2026-11-23', 'checkin', $ctx ); unset( $GLOBALS['on_lock'] );
check( 'S.39b C7: two first plans at once pay for one outline (the second reads it inside the lock)', 0 === count( HDLV2_AI_Service::$prompts ) && is_array( $p ) && false !== strpos( $p['block'], 'Written by the other request' ) );
$log = tempnam( sys_get_temp_dir(), 'hdlv2-fc-' ); ini_set( 'error_log', $log );
$w = sw_world(); $C::before_plan( 13, 3, '2026-11-23', 'final_report', $ctx );
ini_set( 'error_log', '/dev/null' ); $logged = (string) file_get_contents( $log ); @unlink( $log );
check( 'S.39c C12: the outline\'s token counts are logged: block id, "outline", tokens in and out; no text', 1 === preg_match( '/\[HDLV2 FlightCycle\] block \d+ outline: tokens in 1201, out 90/', $logged ) && false === strpos( $logged, 'Walk after lunch' ) );
check( 'S.40 the outline\'s aim is cut at 200 characters', 200 === mb_strlen( $C::outline_line( 'Week 1: ' . str_repeat( 'x', 500 ), 1 ) ) );
$src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/sprint-5/class-hdlv2-flight-plan.php' );
check( 'S.41 generate(): a wait by design answers null (the queue\'s no-op), other refusals stay errors', false !== strpos( $src, "return 'cycle_waits' === \$cycle->get_error_code() ? null : \$cycle;" ) );
check( 'S.42 the cron runs the pass first; end_if_due is gone', false !== strpos( $src, 'HDLV2_Flight_Cycle::roll_due();' ) && false === strpos( $src, 'end_if_due' ) && ! method_exists( $C, 'end_if_due' ) );
done();
