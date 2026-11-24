<?php
/**
 * Plan 14 fix rows C4, C8, C11: a waiting or paused block is visible without an e-mail, and the words are
 * true. The client's page line (plan_view notice) once the six weeks have ended or while paused; the
 * review reaches the client only when its block is closed; the practitioner's panel names why a block
 * waits, shows only that block's own review, and on a paused block says what "Start" does; the
 * "What needs you today" item (review_due). Switch absent: nothing.
 *
 * Run: php tests/flight-cycle/scenario-waiting.php   Exit: 0 all pass, 1 any fail
 */
require __DIR__ . '/fc-world.php';
$C = 'HDLV2_Flight_Cycle';

/** A block from 12 Oct (last day Sun 22 Nov; the stubs' today is Tue 24 Nov) and its week-6 plan. */
function wt_world() {
    $w = sw_world();
    HDLV2_Flight_Cycle::before_plan( 13, 3, '2026-10-12', 'final_report', $GLOBALS['ctx'] );
    $w->t['flight_plans'][] = array( 'id' => 650, 'client_id' => 13, 'cycle_id' => cyc( $w )[0]['id'], 'cycle_week' => 6, 'week_start' => '2026-11-16', 'effective_start_day' => 'monday', 'status' => 'active', 'deleted_at' => null );
    return $w;
}
function wt_plan( $w ) { foreach ( $w->t['flight_plans'] as $p ) if ( 650 === $p['id'] ) return (object) $p; return null; }
function wt_flag( $w ) {
    $w->t['checkins'][] = array( 'id' => 44, 'client_id' => 13, 'week_number' => 6, 'week_start' => '2026-11-16', 'summary' => '{"check_in_summary":"Dizzy at work."}', 'has_flags' => 1, 'flags' => '[{"trigger":"Dizzy spells at work","severity":"high"}]', 'status' => 'confirmed', 'deleted_at' => null );
}

// ── The block has ended, the daily pass has not run yet.
$w = wt_world();
$v = $C::plan_view( wt_plan( $w ) );
check( 'W.1 ended, waiting for the pass: "Your six weeks are complete. Your next plan is being prepared."', 'Your six weeks are complete. Your next plan is being prepared.' === $v['notice'] && 6 === $v['week'] && null === $v['review'] );

// ── A flag from the client's words: the block waits for the practitioner.
wt_flag( $w ); $C::roll_due();
$v = $C::plan_view( wt_plan( $w ) );
check( 'W.2 waiting for the practitioner: "Your six weeks are complete. Your practitioner starts your next six weeks."', 'Your six weeks are complete. Your practitioner starts your next six weeks.' === $v['notice'] );
check( 'W.3 C4: the client does not see the review while it waits', null === $v['review'] && '' !== (string) cyc( $w )[0]['review'] );
$panel = $C::panel_control( 13 );
check( 'W.4 the panel names the flag\'s own text', false !== strpos( $panel, 'Six-week Flight Plan: the review is written; the next six weeks wait for you: Dizzy spells at work.' ) );
check( 'W.5 the panel shows this block\'s review, as "Six-week review"', false !== strpos( $panel, '<summary>Six-week review</summary>' ) && false !== strpos( $panel, 'Walked most days.' ) );
check( 'W.6 C11: on a waiting block the button stays "Start next six weeks", with no extra line', false !== strpos( $panel, '>Start next six weeks</button>' ) && false === strpos( $panel, 'data-fc-confirm' ) );
check( 'W.7 C8: the "What needs you today" item: review_due() is true for this client', true === $C::review_due( 13 ) );
check( 'W.8 one e-mail, only because it waits', 1 === count( HDLV2_Email_Templates::$sent ) );
$GLOBALS['current_user'] = 3; $C::rest_start( new FakeRequest( array( 'client_id' => 13 ) ) ); $GLOBALS['current_user'] = 0;
$v = $C::plan_view( wt_plan( $w ) );
check( 'W.9 C4: once the practitioner starts the next six weeks, the client sees the review', 'Walked most days. Missed the late snacks rule.' === $v['review'] && false === $C::review_due( 13 ) );

// ── Paused, before the end and after it.
$w = sw_world();
$C::before_plan( 13, 3, '2026-11-16', 'final_report', $ctx ); // last day 27 Dec: today is in its week 2
$w->t['flight_plans'][] = array( 'id' => 651, 'client_id' => 13, 'cycle_id' => cyc( $w )[0]['id'], 'cycle_week' => 2, 'week_start' => '2026-11-23', 'effective_start_day' => 'monday', 'status' => 'active', 'deleted_at' => null );
$GLOBALS['current_user'] = 3; $C::rest_pause( new FakeRequest( array( 'client_id' => 13 ) ) ); $GLOBALS['current_user'] = 0;
check( 'W.10 paused before the end: "Your plan is paused by your practitioner."', 'Your plan is paused by your practitioner.' === $C::plan_view( (object) $w->t['flight_plans'][0] )['notice'] );
$panel = $C::panel_control( 13 );
check( 'W.11 C11: a paused block\'s button reads "Start a new six weeks" with its line "This ends the paused six weeks and starts week 1."',
    false !== strpos( $panel, '>Start a new six weeks</button>' ) && false !== strpos( $panel, 'data-fc-confirm="This ends the paused six weeks and starts week 1."' ) );
$w->t['flight_cycles'][0]['end_date'] = '2026-11-22';
check( 'W.12 paused and past its end: "… Your practitioner starts your next six weeks."', 'Your six weeks are complete. Your practitioner starts your next six weeks.' === $C::plan_view( (object) $w->t['flight_plans'][0] )['notice'] );
check( 'W.13 a paused block is not a "needs you" item', false === $C::review_due( 13 ) );

// ── The review could not be written: true words, and no older block's review under this block's name.
$w = sw_world();
$w->t['flight_cycles'][] = array( 'id' => 70, 'client_id' => 13, 'form_progress_id' => 14, 'cycle_number' => 1, 'start_week' => '2026-08-31', 'end_date' => '2026-10-11', 'status' => 'closed', 'outline' => 'x', 'review' => 'Old review of block 1.', 'closed_at' => '2026-10-12 08:00:00' );
$C::before_plan( 13, 3, '2026-10-12', 'final_report', $ctx );
HDLV2_AI_Service::$fail = true; $C::roll_due();
$panel = $C::panel_control( 13 );
check( 'W.14 C8: the panel says "The six-week review could not be written. The next six weeks wait for you."', false !== strpos( $panel, '>The six-week review could not be written. The next six weeks wait for you.</p>' ) );
check( 'W.15 C8: no older block\'s review shown under this block', false === strpos( $panel, 'Old review of block 1.' ) && false === strpos( $panel, '<details' ) );
check( 'W.16 the e-mail carries the same true words', 'The six-week review could not be written. The next six weeks wait for you.' === ( HDLV2_Email_Templates::$sent[0]['reasons'][0] ?? '' ) );

// ── A running block shows the last closed review of the same form row as "Last six-week review".
$w = sw_world();
$w->t['flight_cycles'][] = array( 'id' => 70, 'client_id' => 13, 'form_progress_id' => 14, 'cycle_number' => 1, 'start_week' => '2026-08-31', 'end_date' => '2026-10-11', 'status' => 'closed', 'outline' => 'x', 'review' => 'Old review of block 1.', 'closed_at' => '2026-10-12 08:00:00' );
$C::before_plan( 13, 3, '2026-11-16', 'final_report', $ctx );
$panel = $C::panel_control( 13 );
check( 'W.17 a running block: "Last six-week review" is the closed block\'s', false !== strpos( $panel, '<summary>Last six-week review</summary><p>Old review of block 1.</p>' ) && false !== strpos( $panel, '>Pause the six weeks</button>' ) );

// ── The strip draws the item in its own row shape; switch absent: nothing.
$js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/hdlv2-client-list-enhance.js' );
$cs = file_get_contents( dirname( __DIR__, 2 ) . '/includes/sprint-4/class-hdlv2-client-status.php' );
check( 'W.18 the roster marks the client (fc_review_due) from review_due(); the strip groups it with "Six-week review ready" and "Open"',
    false !== strpos( $cs, "HDLV2_Flight_Cycle::review_due( \$client_id )" ) && false !== strpos( $cs, "\$entry['fc_review_due'] = true;" )
    && false !== strpos( $js, "if (c.fc_review_due) groups.fc_review.items.push(c);" ) && false !== strpos( $js, "'Six-week review ready'" ) && false !== strpos( $js, '>Open</button>' ) );
unset( $GLOBALS['options']['hdlv2_ff_flight_cycle'] );
check( 'W.19 switch absent: no panel, no item', '' === $C::panel_control( 13 ) && false === $C::review_due( 13 ) );
done();
