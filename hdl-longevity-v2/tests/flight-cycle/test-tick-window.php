<?php
/**
 * Plan 14, C1/C3: a day's state from the site's clock. Today and later: open. Yesterday: open, stored
 * as late. Older: locked. A box left empty on a locked day is missed; days before the plan's first day
 * are never missed. Clock-change days count as one day each.
 *
 * Run: php tests/flight-cycle/test-tick-window.php   Exit: 0 all pass, 1 any fail
 */
require dirname( __DIR__ ) . '/access/stubs.php';
ac_load( array( 'sprint-5/class-hdlv2-flight-cycle.php' ) );
date_default_timezone_set( 'Europe/London' );
$C = 'HDLV2_Flight_Cycle';
if ( ! class_exists( $C ) ) { check( 'class exists', false ); done(); }

check( 'T.1 today is open', 'open' === $C::day_state( '2026-11-24', '2026-11-24' ) );
check( 'T.2 tomorrow is open (today\'s rule, unchanged)', 'open' === $C::day_state( '2026-11-25', '2026-11-24' ) );
check( 'T.3 yesterday is late', 'late' === $C::day_state( '2026-11-23', '2026-11-24' ) );
check( 'T.4 the day before yesterday is locked', 'locked' === $C::day_state( '2026-11-22', '2026-11-24' ) );
check( 'T.5 last month is locked', 'locked' === $C::day_state( '2026-10-01', '2026-11-24' ) );
check( 'T.6 spring clock change: Sunday 29 Mar seen on Monday 30 Mar is late', 'late' === $C::day_state( '2026-03-29', '2026-03-30' ) );
check( 'T.7 spring clock change: Saturday 28 Mar seen on Monday 30 Mar is locked', 'locked' === $C::day_state( '2026-03-28', '2026-03-30' ) );
check( 'T.8 autumn clock change: Sunday 25 Oct seen on Monday 26 Oct is late', 'late' === $C::day_state( '2026-10-25', '2026-10-26' ) );
check( 'T.9 year end: 31 Dec seen on 1 Jan is late', 'late' === $C::day_state( '2026-12-31', '2027-01-01' ) );
check( 'T.10 the date of each weekday in a plan week', '2026-11-23' === $C::day_date( '2026-11-23', 'monday' ) && '2026-11-29' === $C::day_date( '2026-11-23', 'sunday' ) );

// A plan starting Wednesday 18 Nov (the final report on a Wednesday), seen on Tuesday 24 Nov.
function tk( $day, $ticked, $cat = 'movement', $text = 'Walk' ) { return (object) array( 'day' => $day, 'ticked' => $ticked ? 1 : 0, 'category' => $cat, 'action_text' => $text ); }
$ticks = array( tk( 'wednesday', 1 ), tk( 'wednesday', 0 ), tk( 'thursday', 0 ), tk( 'sunday', 1 ), tk( 'sunday', 0 ) );
$s = $C::day_summary( $ticks, '2026-11-16', 'wednesday', '2026-11-24' );
check( 'T.11 days before the plan\'s first day are left out (never missed)', ! isset( $s['monday'] ) && ! isset( $s['tuesday'] ) && isset( $s['wednesday'] ) );
check( 'T.12 a locked day: done and missed counted', array( 'state' => 'locked', 'done' => 1, 'missed' => 1 ) === $s['wednesday'] );
check( 'T.13 a locked day with no tick at all: every box missed', 0 === $s['thursday']['done'] && 1 === $s['thursday']['missed'] );
check( 'T.14 a locked day with no actions: nothing missed', 0 === $s['friday']['missed'] && 'locked' === $s['friday']['state'] );

$this_week = array( tk( 'monday', 0 ), tk( 'monday', 1 ), tk( 'tuesday', 0 ), tk( 'wednesday', 0 ) );
$t = $C::day_summary( $this_week, '2026-11-23', 'monday', '2026-11-24' );
check( 'T.15 yesterday (late): nothing counted missed yet', array( 'state' => 'late', 'done' => 1, 'missed' => 0 ) === $t['monday'] );
check( 'T.16 today and tomorrow open, nothing missed', 'open' === $t['tuesday']['state'] && 0 === $t['tuesday']['missed'] && 'open' === $t['wednesday']['state'] );
check( 'T.17 seven days for a Monday plan', 7 === count( $t ) );
done();
