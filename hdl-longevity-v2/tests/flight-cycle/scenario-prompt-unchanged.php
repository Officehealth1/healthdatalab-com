<?php
/**
 * Plan 14, C1/C4: the weekly Flight Plan prompt. Switch hdlv2_ff_flight_cycle absent: build_prompt()
 * answers byte for byte what it answered at e80a129 (md5 pinned below, the moving TODAY line left out).
 * Switch on: the block's lines sit right after the check-in lines, the two sentences word for word,
 * and everything around them unchanged. Fix row C10: under the last week's lists, the missed actions of the
 * plans two and three weeks before (15 lines each); a week without a plan is left out. Pinned word for word.
 *
 * Run: php tests/flight-cycle/scenario-prompt-unchanged.php   Exit: 0 all pass, 1 any fail
 */
require dirname( __DIR__ ) . '/access/stubs.php';
ac_load( array( 'sprint-5/class-hdlv2-flight-plan.php', 'sprint-5/class-hdlv2-flight-cycle.php' ) );
ac_world();

const BASELINE_MD5 = 'd6fb29ff7786b3a7ee8527e3fbedbe3d'; // e80a129, taken before the build

function fc_context() {
    return array(
        'client_name' => 'Rosa Vale', 'week_number' => 3, 'week_start_date' => '2026-11-30', 'week_end_date' => '2026-12-06',
        'start_day_of_week' => 'monday', 'priority_notes' => 'Keep walks short.', 'recent_notes' => array( 'Sleeps badly.' ),
        'why_profile' => array( 'distilled_why' => 'To keep up with her grandchildren.' ), 'report_summary' => 'Pace of ageing 1.1.',
        'milestones' => array( 'Walk 5 km' ), 'engagement_signal' => 'medium', 'previous_shopping' => array( 'oats' ),
        'recent_checkins' => array( (object) array( 'week_number' => 2, 'summary' => '{"check_in_summary":"Tired."}', 'adherence_scores' => '{"overall":60}', 'comfort_zone' => 'in' ) ),
        'tick_adherence' => array( array( 'week_start' => '2026-11-23', 'rate' => 0.5, 'ticked' => 10, 'total' => 20, 'days_active' => 4, 'category_breakdown' => array( 'movement' => array( 'rate' => 0.5 ) ) ) ),
        'comfort_zone_trend' => array( 'in' ), 'monthly_summaries' => array(),
    );
}
function fc_prompt( $context ) {
    $m = new ReflectionMethod( 'HDLV2_Flight_Plan', 'build_prompt' ); $m->setAccessible( true );
    $p = $m->invoke( HDLV2_Flight_Plan::get_instance(), $context );
    $p['user'] = preg_replace( '/\nTODAY: \d{4}-\d{2}-\d{2}$/', '', $p['user'] );
    return $p;
}

$off = fc_prompt( fc_context() );
$md5 = md5( $off['system'] . "\n=====\n" . $off['user'] );
if ( 'PIN_ME' === BASELINE_MD5 ) { echo "baseline md5: $md5\n"; exit( 2 ); }
check( 'P.1 switch absent: system + user prompt == e80a129 (md5 pinned)', BASELINE_MD5 === $md5 );

$GLOBALS['options']['hdlv2_ff_flight_cycle'] = 1;
$on_no_block = fc_prompt( fc_context() );
check( 'P.2 switch on, no block for this plan: still byte-identical', BASELINE_MD5 === md5( $on_no_block['system'] . "\n=====\n" . $on_no_block['user'] ) );

$ctx = fc_context();
$ctx['cycle_block'] = class_exists( 'HDLV2_Flight_Cycle' ) ? HDLV2_Flight_Cycle::prompt_block( "Done:\n- movement: Walk 20 minutes (5 days)", 3, 'Walk every day' ) : '';
$on = fc_prompt( $ctx );
$lists = 'Use the lists of done and missed actions: keep what the client does, make what they miss smaller or easier, and do not repeat an action missed three weeks in a row without changing it.';
$week  = "This is week 3 of 6. This week's aim from the six-week outline: Walk every day.";
check( 'P.3 switch on with a block: both sentences word for word', false !== strpos( $on['user'], $lists ) && false !== strpos( $on['user'], $week ) );
$ci = strpos( $on['user'], 'Week 2: {"check_in_summary":"Tired."}' );
$adh = strpos( $on['user'], 'PREVIOUS ADHERENCE SCORES' );
check( 'P.4 the block sits after the check-in lines and before the adherence scores', $ci && $adh && $ci < strpos( $on['user'], $lists ) && strpos( $on['user'], $week ) < $adh );
check( 'P.5 removing the block gives back the switch-absent prompt exactly', str_replace( $ctx['cycle_block'], '', $on['user'] ) === $off['user'] && $on['system'] === $off['system'] );

// C10: the block's text with the older missed lists (the real private builder, pinned word for word).
$w = $GLOBALS['wpdb'];
$w->t['flight_plans'] = array(); $w->t['flight_plan_ticks'] = array();
foreach ( array( array( 701, '2026-11-16' ), array( 702, '2026-11-09' ), array( 703, '2026-11-02' ) ) as $pl ) {
    $w->t['flight_plans'][] = array( 'id' => $pl[0], 'client_id' => 13, 'week_start' => $pl[1], 'effective_start_day' => 'monday', 'deleted_at' => null );
}
foreach ( array( array( 701, 'monday', 'movement', 'Walk 20 minutes', 1 ), array( 701, 'tuesday', 'nutrition', 'Water at breakfast', 0 ),
    array( 702, 'monday', 'movement', 'Walk', 1 ), array( 702, 'wednesday', 'nutrition', 'No late snacks', 0 ), array( 703, 'friday', 'key_action', 'Journal', 0 ) ) as $i => $t ) {
    $w->t['flight_plan_ticks'][] = array( 'id' => 800 + $i, 'flight_plan_id' => $t[0], 'day' => $t[1], 'category' => $t[2], 'action_text' => $t[3], 'ticked' => $t[4], 'deleted_at' => null );
}
$lw = new ReflectionMethod( 'HDLV2_Flight_Cycle', 'last_week_text' ); $lw->setAccessible( true );
$block = HDLV2_Flight_Cycle::prompt_block( $lw->invoke( null, 13, '2026-11-23' ), 2, 'Water at breakfast' );
$want  = "\n\nDONE AND MISSED ACTIONS, THE PLAN'S LAST WEEK:\nDone:\n- movement: Walk 20 minutes (1 day)\nMissed:\n- nutrition: Water at breakfast (1 day)\n"
    . "Per day: monday done 1, missed 0; tuesday done 0, missed 1; wednesday done 0, missed 0; thursday done 0, missed 0; friday done 0, missed 0; saturday done 0, missed 0; sunday done 0, missed 0\n"
    . "Missed, two weeks ago:\n- nutrition: No late snacks (1 day)\nMissed, three weeks ago:\n- key_action: Journal (1 day)\n"
    . $lists . "\nThis is week 2 of 6. This week's aim from the six-week outline: Water at breakfast.";
check( 'P.6 C10: the block with the missed lists of two and three weeks ago, word for word', $want === $block );
$w->t['flight_plans'] = array_values( array_filter( $w->t['flight_plans'], function ( $r ) { return 702 !== $r['id']; } ) );
$block = HDLV2_Flight_Cycle::prompt_block( $lw->invoke( null, 13, '2026-11-23' ), 2, 'Water at breakfast' );
check( 'P.7 C10: a week without a plan is left out (no "two weeks ago" heading), the other stays', false === strpos( $block, 'Missed, two weeks ago:' ) && false !== strpos( $block, "Missed, three weeks ago:\n- key_action: Journal (1 day)" ) );
$w->t['flight_plan_ticks'] = array();
for ( $i = 0; $i < 20; $i++ ) $w->t['flight_plan_ticks'][] = array( 'id' => 900 + $i, 'flight_plan_id' => 703, 'day' => 'friday', 'category' => 'movement', 'action_text' => "Step $i", 'ticked' => 0, 'deleted_at' => null );
$block = HDLV2_Flight_Cycle::prompt_block( $lw->invoke( null, 13, '2026-11-23' ), 2, 'x' );
check( 'P.8 C10: an older list holds at most 15 lines', 15 === substr_count( substr( $block, strpos( $block, 'Missed, three weeks ago:' ) ), '- movement: Step' ) );
done();
