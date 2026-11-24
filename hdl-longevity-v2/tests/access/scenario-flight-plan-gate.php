<?php
/**
 * Plan 14, A1/A2: the Flight Plan's client doors (its routes, the my-flight-plan page) and the places
 * that start a plan for a client (the weekly cron; a check-in confirm is covered by the check-in's own
 * door), with the REAL HDLV2_Flight_Plan. Switch on: a client with no final report and no plan gets
 * 403 `locked` and the greyed card, and the cron starts nothing for them. A client with a final report
 * or a plan already running, the owning practitioner and an admin go through. Switch absent: today.
 *
 * Run: php tests/access/scenario-flight-plan-gate.php   Exit: 0 all pass, 1 any fail
 */
require __DIR__ . '/stubs.php';
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING ); // a running plan's view reads columns the fake row leaves out; only the door is under test
ac_load( array( 'security/class-hdlv2-access.php', 'sprint-5/class-hdlv2-flight-plan.php' ) );
if ( ! class_exists( 'HDLV2_Access' ) ) { check( 'HDLV2_Access exists', false ); done(); }
$fp  = HDLV2_Flight_Plan::get_instance();
$tok = array( 'ivy' => str_repeat( 'b', 64 ), 'finn' => str_repeat( 'c', 64 ), 'rosa' => str_repeat( 'd', 64 ) );
$ROOT = '<div id="hdlv2-flight-plan" class="hdlv2-assessment-root"></div>';
$cur = function ( $client, $token = null ) use ( $fp ) { return $fp->rest_current( new FakeRequest( array_filter( array( 'client_id' => $client, 'token' => $token ) ) ) ); };

// ── Switch on ──
$w = ac_world(); ac_switch( '1' );
check( 'F.1 Ivy by her link: current plan → 403 locked', ac_locked( $cur( 9, $tok['ivy'] ) ) );
check( 'F.2 Ivy by her link: history → 403 locked', ac_locked( $fp->rest_history( new FakeRequest( array( 'client_id' => 9, 'token' => $tok['ivy'] ) ) ) ) );
$GLOBALS['current_user'] = 9;
check( 'F.3 Ivy signed in (cookie): current plan → 403 locked', ac_locked( $cur( 9 ) ) );
$GLOBALS['current_user'] = 0;
check( 'F.4 Finn (final report) by his link: goes through', ! is_wp_error( $cur( 11, $tok['finn'] ) ) );
check( 'F.5 Rosa (a plan already running) by her link: goes through', ! is_wp_error( $cur( 13, $tok['rosa'] ) ) );
$n = count( $w->writes );
$r = $fp->rest_tick( new FakeRequest( array( 'tick_id' => 900, 'ticked' => 1, 'token' => $tok['rosa'] ) ) );
check( 'F.6 Rosa ticks a box on her running plan: stored', ! ac_locked( $r ) && count( $w->writes ) > $n );
$GLOBALS['current_user'] = 3;
check( 'F.7 her practitioner: Ivy\'s current plan goes through (staff)', ! is_wp_error( $cur( 9 ) ) );
$GLOBALS['current_user'] = 1;
check( 'F.8 an admin: Ivy\'s current plan goes through (staff)', ! is_wp_error( $cur( 9 ) ) );
$GLOBALS['current_user'] = 4;
check( 'F.9 another practitioner: refused as today (403 forbidden, not the lock)', 'forbidden' === $cur( 9 )->get_error_code() );
$GLOBALS['current_user'] = 0;
check( 'F.10 a bad token: refused as today, before the gate', 'unauthorized' === $cur( 9, 'nope' )->get_error_code() );

$_GET = array( 'token' => $tok['ivy'] );
$page = $fp->render_shortcode( array() );
check( 'F.11 page, Ivy by her link: the greyed card, not the plan', false === strpos( $page, 'id="hdlv2-flight-plan"' ) && false !== strpos( $page, 'hdlv2-locked-card' ) && false !== strpos( $page, 'Flight Plan' ) );
$_GET = array(); $GLOBALS['current_user'] = 9;
check( 'F.12 page, Ivy signed in: the card', false !== strpos( $fp->render_shortcode( array() ), 'hdlv2-locked-card' ) );
$GLOBALS['current_user'] = 0; $_GET = array( 'token' => $tok['finn'] );
check( 'F.13 page, Finn: the plan page as today', $ROOT === $fp->render_shortcode( array() ) );
$GLOBALS['current_user'] = 3; $_GET = array( 'client_id' => 9 );
check( 'F.14 page, her practitioner viewing Ivy: the plan page (staff), never the card', false === strpos( $fp->render_shortcode( array() ), 'hdlv2-locked-card' ) );
$GLOBALS['current_user'] = 0; $_GET = array();

$GLOBALS['calls'] = array();
$fp->cron_generate_all();
check( 'F.15 the weekly cron: nothing started for Ivy (no context built, no generation)', ! in_array( 'context:9', $GLOBALS['calls'], true ) && ! preg_grep( '/client:9$/', $GLOBALS['calls'] ) );
check( 'F.16 the weekly cron: Finn still reaches generation (his context is built)', in_array( 'context:11', $GLOBALS['calls'], true ) );

// ── Switch absent: today ──
$w = ac_world(); ac_switch( null );
check( 'F.17 switch absent, Ivy by her link: current plan goes through (today)', ! is_wp_error( $cur( 9, $tok['ivy'] ) ) );
$_GET = array( 'token' => $tok['ivy'] );
check( 'F.18 switch absent, Ivy: the page is the plan page, byte for byte', $ROOT === $fp->render_shortcode( array() ) );
$_GET = array();
$GLOBALS['calls'] = array();
$fp->cron_generate_all();
check( 'F.19 switch absent: the cron reaches Ivy as today', in_array( 'context:9', $GLOBALS['calls'], true ) );
check( 'F.20 switch absent: the gate read nothing (no report query)', ! preg_grep( '/hdlv2_reports/', $w->queries ) );

done();
