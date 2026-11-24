<?php
/**
 * Plan 14, C1/C3: the two tick routes with the real HDLV2_Flight_Plan. Seen on Tuesday 24 Nov 2026
 * (the stubs' clock). Switch on: a client's tick on a locked day answers 409 `day_closed` and stores
 * nothing; yesterday is stored with ticked_late = 1; today with 0; who ticked is stored; "tick all"
 * leaves locked days alone; the practitioner and an admin may still correct a locked day.
 * Switch absent: every write exactly as before (no new field, no refusal).
 *
 * Run: php tests/flight-cycle/scenario-tick-route.php   Exit: 0 all pass, 1 any fail
 */
require dirname( __DIR__ ) . '/access/stubs.php';
ac_load( array( 'sprint-5/class-hdlv2-flight-plan.php', 'sprint-5/class-hdlv2-flight-cycle.php' ) );
function fc_world() {
    $w = ac_world();
    // Rosa (13, token d…): plan 80 this week (Mon 23 Nov), plan 82 last week (Mon 16 Nov).
    foreach ( $w->t['flight_plans'] as $i => $p ) if ( 80 === $p['id'] ) $w->t['flight_plans'][ $i ]['effective_start_day'] = 'monday';
    $w->t['flight_plans'][] = array( 'id' => 82, 'client_id' => 13, 'week_number' => 2, 'week_start' => '2026-11-16', 'effective_start_day' => 'monday', 'deleted_at' => null, 'status' => 'active' );
    $w->t['flight_plan_ticks'] = array(
        array( 'id' => 900, 'flight_plan_id' => 80, 'client_id' => 13, 'day' => 'tuesday',   'category' => 'movement', 'ticked' => 0, 'deleted_at' => null ),
        array( 'id' => 901, 'flight_plan_id' => 80, 'client_id' => 13, 'day' => 'monday',    'category' => 'movement', 'ticked' => 0, 'deleted_at' => null ),
        array( 'id' => 902, 'flight_plan_id' => 82, 'client_id' => 13, 'day' => 'sunday',    'category' => 'movement', 'ticked' => 0, 'deleted_at' => null ),
        array( 'id' => 903, 'flight_plan_id' => 80, 'client_id' => 13, 'day' => 'wednesday', 'category' => 'movement', 'ticked' => 0, 'deleted_at' => null ),
    );
    return $w;
}
function fc_tick_row( $w, $id ) { foreach ( $w->t['flight_plan_ticks'] as $r ) if ( (int) $r['id'] === $id ) return $r; }
function fc_updates( $w ) { return array_values( array_filter( $w->writes, function ( $x ) { return is_array( $x ) && 'update' === $x[0] && false !== strpos( $x[1], 'flight_plan_ticks' ); } ) ); }
$TOK = str_repeat( 'd', 64 );
$fp  = HDLV2_Flight_Plan::get_instance();
$sw  = function ( $v ) { if ( null === $v ) unset( $GLOBALS['options']['hdlv2_ff_flight_cycle'] ); else $GLOBALS['options']['hdlv2_ff_flight_cycle'] = $v; };

// ── Switch absent: today's behaviour.
$sw( null ); $w = fc_world();
$r = $fp->rest_tick( new FakeRequest( array( 'tick_id' => 902, 'ticked' => true, 'token' => $TOK ) ) );
$u = fc_updates( $w );
check( 'R.1 absent: last week\'s box is accepted (today\'s behaviour)', ! is_wp_error( $r ) && 1 === count( $u ) );
check( 'R.2 absent: the write carries only ticked and ticked_at', array( 'ticked', 'ticked_at' ) === array_keys( $u[0][2] ) );
$w = fc_world();
$fp->rest_tick_all( new FakeRequest( array( 'flight_plan_id' => 82, 'ticked' => true, 'token' => $TOK ) ) );
$u = fc_updates( $w );
check( 'R.3 absent: tick all = one write on the whole plan, as before', 1 === count( $u ) && array( 'flight_plan_id' => 82 ) === $u[0][3] && array( 'ticked', 'ticked_at' ) === array_keys( $u[0][2] ) );

// ── Switch on, the client by link.
$sw( 1 ); $w = fc_world();
$r = $fp->rest_tick( new FakeRequest( array( 'tick_id' => 902, 'ticked' => true, 'token' => $TOK ) ) );
check( 'R.4 on: a locked day (Sunday last week) → 409 day_closed', $r instanceof WP_Error && 'day_closed' === $r->get_error_code() && 409 === ( $r->get_error_data()['status'] ?? 0 ) );
check( 'R.5 on: nothing stored on a refusal', 0 === count( fc_updates( $w ) ) && 0 === (int) fc_tick_row( $w, 902 )['ticked'] );
$r = $fp->rest_tick( new FakeRequest( array( 'tick_id' => 901, 'ticked' => true, 'token' => $TOK ) ) );
$row = fc_tick_row( $w, 901 );
check( 'R.6 on: yesterday is stored, ticked_late = 1, ticked_by = the client', ! is_wp_error( $r ) && 1 === (int) $row['ticked'] && 1 === $row['ticked_late'] && 13 === $row['ticked_by'] );
$fp->rest_tick( new FakeRequest( array( 'tick_id' => 900, 'ticked' => true, 'token' => $TOK ) ) );
check( 'R.7 on: today is stored, ticked_late = 0', 1 === (int) fc_tick_row( $w, 900 )['ticked'] && 0 === fc_tick_row( $w, 900 )['ticked_late'] );
$fp->rest_tick( new FakeRequest( array( 'tick_id' => 901, 'ticked' => false, 'token' => $TOK ) ) );
check( 'R.8 on: unticking yesterday clears the late mark', 0 === (int) fc_tick_row( $w, 901 )['ticked'] && 0 === fc_tick_row( $w, 901 )['ticked_late'] );
$GLOBALS['current_user'] = 13;
$r = $fp->rest_tick( new FakeRequest( array( 'tick_id' => 902, 'ticked' => true ) ) );
check( 'R.9 on: signed in as herself, a locked day is refused too', $r instanceof WP_Error && 'day_closed' === $r->get_error_code() );
$GLOBALS['current_user'] = 0;

// tick all
$w = fc_world();
$r = $fp->rest_tick_all( new FakeRequest( array( 'flight_plan_id' => 82, 'ticked' => true, 'token' => $TOK ) ) );
check( 'R.10 on: tick all on last week (every day locked) → 409, nothing stored', $r instanceof WP_Error && 'day_closed' === $r->get_error_code() && 0 === count( fc_updates( $w ) ) );
$r = $fp->rest_tick_all( new FakeRequest( array( 'flight_plan_id' => 80, 'ticked' => true, 'token' => $TOK, 'day' => 'monday' ) ) );
check( 'R.11 on: tick all on yesterday → stored late', ! is_wp_error( $r ) && 1 === (int) fc_tick_row( $w, 901 )['ticked'] && 1 === fc_tick_row( $w, 901 )['ticked_late'] );
$w = fc_world();
$fp->rest_tick_all( new FakeRequest( array( 'flight_plan_id' => 80, 'ticked' => true, 'token' => $TOK ) ) );
$days = array_map( function ( $x ) { return $x[3]['day'] ?? 'ALL'; }, fc_updates( $w ) );
check( 'R.12 on: tick all on this week writes day by day, each open day once', array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ) === $days );
$w = fc_world();
foreach ( $w->t['flight_plans'] as $i => $p ) if ( 80 === $p['id'] ) $w->t['flight_plans'][ $i ]['effective_start_day'] = 'wednesday';
$GLOBALS['wpdb'] = $w;
$fp->rest_tick_all( new FakeRequest( array( 'flight_plan_id' => 80, 'ticked' => true, 'token' => $TOK ) ) );
$days = array_map( function ( $x ) { return $x[3]['day'] ?? 'ALL'; }, fc_updates( $w ) );
check( 'R.13 on: tick all never writes a day before the plan\'s first day', array( 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ) === $days );

// staff may correct
foreach ( array( 3 => 'R.14 on: the owning practitioner may correct a locked day (stored late, by her)', 1 => 'R.15 on: an admin may correct a locked day' ) as $uid => $label ) {
    $w = fc_world(); $GLOBALS['current_user'] = $uid;
    $r = $fp->rest_tick( new FakeRequest( array( 'tick_id' => 902, 'ticked' => true ) ) );
    check( $label, ! is_wp_error( $r ) && 1 === (int) fc_tick_row( $w, 902 )['ticked'] && 1 === fc_tick_row( $w, 902 )['ticked_late'] && $uid === fc_tick_row( $w, 902 )['ticked_by'] );
}
$w = fc_world(); $GLOBALS['current_user'] = 4;
$r = $fp->rest_tick( new FakeRequest( array( 'tick_id' => 902, 'ticked' => true ) ) );
check( 'R.16 on: another practitioner is still refused (ownership, unchanged)', $r instanceof WP_Error && 'forbidden' === $r->get_error_code() );
$GLOBALS['current_user'] = 0;

// rest_current: the page's extra only with the switch on.
$sw( null ); $w = fc_world();
$r = $fp->rest_current( new FakeRequest( array( 'client_id' => 13, 'token' => $TOK ) ) )->get_data();
check( 'R.17 absent: the plan answer has no days or cycle key (as before)', ! array_key_exists( 'days', $r['plan'] ) && ! array_key_exists( 'cycle', $r['plan'] ) );
$sw( 1 ); $w = fc_world();
$r = $fp->rest_current( new FakeRequest( array( 'client_id' => 13, 'token' => $TOK ) ) )->get_data();
check( 'R.18 on: each day\'s state and counts (Monday late, Tuesday open); no block → cycle null', 'late' === $r['plan']['days']['monday']['state'] && 'open' === $r['plan']['days']['tuesday']['state'] && null === $r['plan']['cycle'] );
done();
