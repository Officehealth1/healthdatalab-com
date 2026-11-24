<?php
/**
 * Plan 14, C1: which week of six a plan is, the block's last day, and this week's line of the outline.
 * Pure functions of HDLV2_Flight_Cycle; dates as strings, so a clock-change week counts as seven days.
 *
 * Run: php tests/flight-cycle/test-cycle-weeks.php   Exit: 0 all pass, 1 any fail
 */
require dirname( __DIR__ ) . '/access/stubs.php';
ac_load( array( 'sprint-5/class-hdlv2-flight-cycle.php' ) );
date_default_timezone_set( 'Europe/London' );
$C = 'HDLV2_Flight_Cycle';
if ( ! class_exists( $C ) ) { check( 'class exists', false ); done(); }

check( 'W.1 the block starts on week 1', 1 === $C::week_of( '2026-11-23', '2026-11-23' ) );
check( 'W.2 the next Monday is week 2', 2 === $C::week_of( '2026-11-23', '2026-11-30' ) );
check( 'W.3 week 6 starts five weeks on', 6 === $C::week_of( '2026-11-23', '2026-12-28' ) );
check( 'W.4 six weeks on is week 7 (outside the block)', 7 === $C::week_of( '2026-11-23', '2027-01-04' ) );
check( 'W.5 a week before the block is week 0', 0 === $C::week_of( '2026-11-23', '2026-11-16' ) );
check( 'W.6 a Wednesday inside week 2 is week 2', 2 === $C::week_of( '2026-11-23', '2026-12-02' ) );
check( 'W.7 across the spring clock change (29 Mar 2026): still week 2', 2 === $C::week_of( '2026-03-23', '2026-03-30' ) );
check( 'W.8 across the autumn clock change (25 Oct 2026): still week 2', 2 === $C::week_of( '2026-10-19', '2026-10-26' ) );
check( 'W.9 the block\'s last day is the Sunday of week 6 (start + 41 days)', '2027-01-03' === $C::end_date( '2026-11-23' ) );
check( 'W.10 last day across the new year and a clock change', '2026-05-03' === $C::end_date( '2026-03-23' ) );
check( 'W.11 six weeks', 6 === $C::WEEKS );

$outline = "Week 1: Walk ten minutes after lunch.\nWeek 2: Add a glass of water at breakfast\n week 3 : Bed by 23:00.\nWeek 4: x\nWeek 5: y\nWeek 6: z";
check( 'W.12 outline line for week 2, its "Week n:" taken off', 'Add a glass of water at breakfast' === $C::outline_line( $outline, 2 ) );
check( 'W.13 outline line found whatever its spacing and case, end stop dropped', 'Bed by 23:00' === $C::outline_line( $outline, 3 ) );
check( 'W.14 no outline → a plain marker, never empty', '(no outline yet)' === $C::outline_line( '', 1 ) && '(no outline yet)' === $C::outline_line( $outline, 9 ) );
check( 'W.15 week 1 does not match week 11 or 12', 'one' === $C::outline_line( "Week 11: eleven\nWeek 1: one", 1 ) );
done();
