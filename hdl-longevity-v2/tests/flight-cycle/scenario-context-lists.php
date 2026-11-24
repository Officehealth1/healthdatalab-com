<?php
/**
 * Plan 14, C1/C4: what the AI is given about the last week. Done and missed actions by category (each
 * action once, with its number of days), 40 lines at most with room for both lists, the count per day,
 * and the block's two sentences. Built only from what the client really ticked.
 *
 * Run: php tests/flight-cycle/scenario-context-lists.php   Exit: 0 all pass, 1 any fail
 */
require dirname( __DIR__ ) . '/access/stubs.php';
ac_load( array( 'sprint-5/class-hdlv2-flight-cycle.php' ) );
$C = 'HDLV2_Flight_Cycle';
if ( ! class_exists( $C ) ) { check( 'class exists', false ); done(); }

function tk( $day, $ticked, $cat, $text ) { return (object) array( 'week_start' => '2026-11-16', 'effective_start_day' => 'monday', 'day' => $day, 'ticked' => $ticked ? 1 : 0, 'category' => $cat, 'action_text' => $text ); }
$days  = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
$ticks = array();
foreach ( $days as $i => $d ) {
    $ticks[] = tk( $d, $i < 5, 'movement', 'Walk 20 minutes after lunch' );     // done 5, missed 2
    $ticks[] = tk( $d, $i < 2, 'nutrition', 'No snacks after 8pm' );            // done 2, missed 5
    $ticks[] = tk( $d, false, 'key_action', 'Lights out by 23:00' );            // missed 7
}
$today = '2026-11-24'; // Tuesday: the whole week of 16 Nov is locked
$l = $C::lists( $ticks, $today );
check( 'L.1 done list: each action once, with its days', in_array( '- movement: Walk 20 minutes after lunch (5 days)', $l['done'], true ) && in_array( '- nutrition: No snacks after 8pm (2 days)', $l['done'], true ) );
check( 'L.2 missed list: each action once, with its days', in_array( '- nutrition: No snacks after 8pm (5 days)', $l['missed'], true ) && in_array( '- key_action: Lights out by 23:00 (7 days)', $l['missed'], true ) && in_array( '- movement: Walk 20 minutes after lunch (2 days)', $l['missed'], true ) );
check( 'L.3 an action never ticked is not in the done list', 0 === count( preg_grep( '/Lights out/', $l['done'] ) ) );
check( 'L.4 by category: movement, nutrition, key_action', 0 === strpos( $l['missed'][0], '- movement:' ) && 0 === strpos( end( $l['missed'] ), '- key_action:' ) );
check( 'L.5 a single day says "1 day"', in_array( '- movement: Stretch (1 day)', $C::lists( array( tk( 'monday', 1, 'movement', 'Stretch' ) ), $today )['done'], true ) );

$late = array( tk( 'monday', 0, 'movement', 'Walk' ) );
foreach ( $late as $x ) { $x->week_start = '2026-11-23'; }
check( 'L.6 an empty box on a day not closed yet is not missed', array() === $C::lists( $late, $today )['missed'] );
$pre = tk( 'monday', 0, 'movement', 'Walk' ); $pre->effective_start_day = 'wednesday';
check( 'L.7 a box before the plan\'s first day is never missed', array() === $C::lists( array( $pre ), $today )['missed'] );

$many = array();
for ( $i = 0; $i < 30; $i++ ) { $many[] = tk( 'monday', 1, 'movement', "Done thing $i" ); $many[] = tk( 'monday', 0, 'nutrition', "Missed thing $i" ); }
$cap = $C::lists( $many, $today );
check( 'L.8 forty lines at most, twenty each when both are long', 20 === count( $cap['done'] ) && 20 === count( $cap['missed'] ) );
$few = array_merge( array_slice( $many, 0, 10 ), array_filter( $many, function ( $t ) { return ! $t->ticked; } ) );
$cap2 = $C::lists( $few, $today );
check( 'L.9 a short list gives its room to the other (5 done, 35 missed at most)', 5 === count( $cap2['done'] ) && 30 === count( $cap2['missed'] ) && 40 >= count( $cap2['done'] ) + count( $cap2['missed'] ) );

$per = $C::day_summary( array_slice( $ticks, 0, 3 ), '2026-11-16', 'monday', $today );
$text = $C::lists_text( $l, $per );
check( 'L.10 the text holds both lists under their headings', false !== strpos( $text, "Done:\n- movement: Walk 20 minutes after lunch (5 days)" ) && false !== strpos( $text, "\nMissed:\n- movement:" ) );
check( 'L.11 the count per day', false !== strpos( $text, 'Per day: monday done 2, missed 1' ) );
$open = $C::day_summary( array( tk( 'monday', 1, 'movement', 'Walk' ) ), '2026-11-23', 'monday', $today );
check( 'L.12 a day not closed yet says so', false !== strpos( $C::lists_text( $C::lists( array(), $today ), $open ), 'tuesday done 0, not closed yet' ) );
check( 'L.13 nothing to list: says so under each heading, never an empty heading', "Done:\n(none)\nMissed:\n(none)" === $C::lists_text( array( 'done' => array(), 'missed' => array() ), array() ) );

$block = $C::prompt_block( 'LISTS', 2, 'Drink water' );
check( 'L.14 the block: lists, then the two sentences, week and aim filled', false !== strpos( $block, "LISTS\nUse the lists of done and missed actions: keep what the client does, make what they miss smaller or easier, and do not repeat an action missed three weeks in a row without changing it.\nThis is week 2 of 6. This week's aim from the six-week outline: Drink water." ) );
check( 'L.15 a {{…}} typed by the client into an action is not filled again', false === strpos( $C::prompt_block( '- movement: {{CYCLE_WEEK}}', 4, 'x' ), '- movement: 4' ) );
done();
