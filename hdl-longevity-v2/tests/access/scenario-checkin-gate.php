<?php
/**
 * Plan 14, A1/A2: the weekly check-in's doors (the four routes and the weekly-check-in page) with the
 * REAL HDLV2_Checkin. Switch on: a client with no final report and no plan gets 403 `locked` on every
 * route and nothing is stored or started; the page shows the greyed card instead of the form. A client
 * with a final report, or with a plan already running, goes through. Switch absent: today's behaviour,
 * and the gate reads nothing from the database. The weekly reminder skips a locked client (switch on only).
 *
 * Run: php tests/access/scenario-checkin-gate.php   Exit: 0 all pass, 1 any fail
 */
require __DIR__ . '/stubs.php';
ac_load( array( 'security/class-hdlv2-access.php', 'sprint-4/class-hdlv2-checkin.php' ) );
if ( ! class_exists( 'HDLV2_Access' ) ) { check( 'HDLV2_Access exists', false ); done(); }
$ci = HDLV2_Checkin::get_instance();
$tok = array( 'ivy' => str_repeat( 'b', 64 ), 'finn' => str_repeat( 'c', 64 ), 'rosa' => str_repeat( 'd', 64 ) );
$ROOT = '<div id="hdlv2-checkin" class="hdlv2-assessment-root"></div>';

// ── Switch on ──
$w = ac_world(); ac_switch( '1' );
check( 'C.1 Ivy (no final report, no plan): load → 403 locked', ac_locked( $ci->rest_load( new FakeRequest( array( 'token' => $tok['ivy'] ) ) ) ) );
check( 'C.2 Ivy: submit → 403 locked, nothing written, no AI call', ac_locked( $ci->rest_submit( new FakeRequest( array( 'token' => $tok['ivy'], 'text' => 'Good week.' ) ) ) ) && ! $w->writes && ! $GLOBALS['calls'] );
check( 'C.3 Ivy: confirm → 403 locked, nothing written, no plan started', ac_locked( $ci->rest_confirm( new FakeRequest( array( 'token' => $tok['ivy'], 'checkin_id' => 5 ) ) ) ) && ! $w->writes && ! $GLOBALS['calls'] );
check( 'C.4 Ivy: history → 403 locked', ac_locked( $ci->rest_history( new FakeRequest( array( 'token' => $tok['ivy'] ) ) ) ) );
$r = $ci->rest_load( new FakeRequest( array( 'token' => $tok['ivy'] ) ) );
check( 'C.5 the locked answer carries the plain line, never a stage or a price', HDLV2_Access::LOCKED_LINE === $r->get_error_message() && 'Your practitioner opens this after your consultation.' === HDLV2_Access::LOCKED_LINE );
check( 'C.6 Finn (final report): history goes through', ! is_wp_error( $ci->rest_history( new FakeRequest( array( 'token' => $tok['finn'] ) ) ) ) );
check( 'C.7 Rosa (a plan already running, no final report): history goes through', ! is_wp_error( $ci->rest_history( new FakeRequest( array( 'token' => $tok['rosa'] ) ) ) ) );
check( 'C.8 a bad token still answers as today (401), before any gate', 'invalid_token' === $ci->rest_history( new FakeRequest( array( 'token' => 'nope' ) ) )->get_error_code() );

$_GET = array( 'token' => $tok['ivy'] );
$page = $ci->render_shortcode( array() );
check( 'C.9 page, Ivy by her link: the greyed card, not the form', false === strpos( $page, 'id="hdlv2-checkin"' ) && false !== strpos( $page, 'hdlv2-locked-card' ) );
check( 'C.10 the card: section name, the line, and a link to Messages naming her practitioner',
    false !== strpos( $page, 'Weekly check-in' ) && false !== strpos( $page, HDLV2_Access::LOCKED_LINE )
    && false !== strpos( $page, 'https://example.test/my-dashboard/#tab-messages' ) && false !== strpos( $page, 'Ask Ada Lark' ) );
$_GET = array(); $GLOBALS['current_user'] = 9;
check( 'C.11 page, Ivy signed in with no link: the card too', false !== strpos( $ci->render_shortcode( array() ), 'hdlv2-locked-card' ) );
$GLOBALS['current_user'] = 0; $_GET = array( 'token' => $tok['finn'] );
check( 'C.12 page, Finn: the form as today', $ROOT === $ci->render_shortcode( array() ) );
$_GET = array( 'token' => $tok['rosa'] );
check( 'C.13 page, Rosa: the form as today', $ROOT === $ci->render_shortcode( array() ) );
$GLOBALS['current_user'] = 3; $_GET = array( 'token' => $tok['ivy'] );
check( 'C.14 page, her practitioner opening her link: the form (staff are always let in)', $ROOT === $ci->render_shortcode( array() ) );
$GLOBALS['current_user'] = 4;
check( 'C.15 page, another practitioner opening her link: the card (not her practitioner)', false !== strpos( $ci->render_shortcode( array() ), 'hdlv2-locked-card' ) );

$GLOBALS['calls'] = array(); $GLOBALS['current_user'] = 0; $_GET = array();
$ci->send_reminders();
check( 'C.19 the weekly reminder skips Ivy (it would only lead to the card)', ! in_array( 'campaign:checkin_reminder client:9', $GLOBALS['calls'], true ) );
check( 'C.20 the weekly reminder still reaches Finn and Rosa', in_array( 'campaign:checkin_reminder client:11', $GLOBALS['calls'], true ) && in_array( 'campaign:checkin_reminder client:13', $GLOBALS['calls'], true ) );

// ── Switch absent: today ──
$w = ac_world(); ac_switch( null );
check( 'C.16 switch absent, Ivy: history goes through (today)', ! is_wp_error( $ci->rest_history( new FakeRequest( array( 'token' => $tok['ivy'] ) ) ) ) );
$_GET = array( 'token' => $tok['ivy'] );
check( 'C.17 switch absent, Ivy: the page is the form, byte for byte', $ROOT === $ci->render_shortcode( array() ) );
check( 'C.18 switch absent: the gate read nothing (no report or plan query)', ! preg_grep( '/hdlv2_(reports|flight_plans)/', $w->queries ) );
$GLOBALS['calls'] = array(); $_GET = array();
$ci->send_reminders();
check( 'C.21 switch absent: the weekly reminder reaches Ivy as today', in_array( 'campaign:checkin_reminder client:9', $GLOBALS['calls'], true ) );

done();
