<?php
/**
 * Plan 14 fix rows A1 and C13: check-in sound by link asks the check-in door, with the REAL
 * HDLV2_Audio_Service. Access switch on: a client the door locks gets 403 `locked` on
 * /audio/transcribe-async for `weekly_checkin`, before anything is stored, queued or paid. Other
 * contexts and the practitioner's own calls are not asked. Six-week switch on: sound for a week whose
 * check-in is confirmed answers 409 `checkin_sent` (after the door). Both absent: today.
 * /audio/extract never takes a link today (its callback is not handed the link), so every client by link
 * gets 403 there and nothing is paid; E.1 to E.3 pin that, so a later change that hands it the link fails
 * here until the door is added there too.
 *
 * Run: php tests/access/scenario-audio-door.php   Exit: 0 all pass, 1 any fail
 */
require __DIR__ . '/stubs.php';
function sanitize_textarea_field( $x ) { return trim( (string) $x ); }
class AudioRequest extends FakeRequest {
    public function get_file_params() { return array(); } // no file: a request the door lets through stops at "no audio"
}
ac_load( array( 'security/class-hdlv2-access.php', 'sprint-3/class-hdlv2-audio-service.php', 'sprint-5/class-hdlv2-flight-cycle.php' ) );
$au   = HDLV2_Audio_Service::get_instance();
$tok  = array( 'ivy' => str_repeat( 'b', 64 ), 'finn' => str_repeat( 'c', 64 ), 'rosa' => str_repeat( 'd', 64 ) );
$week = date( 'Y-m-d', strtotime( 'monday this week' ) ); // the check-in's own week rule

$send = function ( $who, $ctx = 'weekly_checkin' ) use ( $au, $tok ) {
    return $au->rest_transcribe_async( new AudioRequest( array( 'token' => $who ? $tok[ $who ] : null, 'context_type' => $ctx ) ) );
};
$extract = function ( $who, $ctx = 'weekly_checkin' ) use ( $au, $tok ) {
    return $au->rest_extract( new AudioRequest( array( 'token' => $who ? $tok[ $who ] : null, 'context_type' => $ctx, 'text' => 'A good week.' ) ) );
};
function ad_code( $r ) { return $r instanceof WP_Error ? $r->get_error_code() : 'ok'; }
function ad_passed( $r ) { return ! in_array( ad_code( $r ), array( 'locked', 'checkin_sent', 'forbidden' ), true ); }
function ad_paid() { return in_array( 'remote_post', $GLOBALS['calls'], true ); }

// ── Access switch on ──
$w = ac_world(); ac_switch( '1' );
$r = $send( 'ivy' );
check( 'D.1 Ivy (locked) by link: check-in sound → 403 locked', ac_locked( $r ) );
check( 'D.2 Ivy: nothing stored, queued or paid', ! $w->writes && ! $GLOBALS['calls'] );
check( 'D.4 Finn (final report): check-in sound goes through the door (stops at "no audio" here)', 'no_audio' === ad_code( $send( 'finn' ) ) );
check( 'D.6 Rosa (a plan running): check-in sound goes through', 'no_audio' === ad_code( $send( 'rosa' ) ) );
$w->queries = array();
check( 'D.7 Ivy, why_collection: not asked (stops at "no audio" as today)', 'no_audio' === ad_code( $send( 'ivy', 'why_collection' ) ) );
check( 'D.9 other contexts read no report or plan', ! preg_grep( '/hdlv2_(reports|flight_plans)/', $w->queries ) );
$GLOBALS['current_user'] = 3;
check( 'D.10 the practitioner signed in, check-in sound without a link: not asked', 'no_audio' === ad_code( $send( null ) ) );
$GLOBALS['current_user'] = 0;
check( 'D.11 a bad link still fails as today (no door answer)', 'locked' !== ad_code( $au->rest_transcribe_async( new AudioRequest( array( 'token' => 'nope', 'context_type' => 'weekly_checkin' ) ) ) ) );

// ── C13: a confirmed week takes no more sound (six-week switch on) ──
$GLOBALS['options']['hdlv2_ff_flight_cycle'] = 1;
$w = ac_world(); ac_switch( '1' );
$w->t['checkins'][] = array( 'id' => 40, 'client_id' => 11, 'week_start' => $week, 'status' => 'confirmed', 'deleted_at' => null );
$r = $send( 'finn' );
check( 'C13.1 Finn, week confirmed: check-in sound → 409 checkin_sent', 'checkin_sent' === ad_code( $r ) && 409 === ( $r->get_error_data()['status'] ?? 0 ) );
check( 'C13.2 Finn, week confirmed: refused before anything is stored or queued', ! $w->writes && ! $GLOBALS['calls'] );
check( 'C13.3 the door answers first: Ivy (locked) gets 403, not 409', ac_locked( $send( 'ivy' ) ) );
$w->t['checkins'][0]['status'] = 'draft';
check( 'C13.4 a draft week still takes sound', 'no_audio' === ad_code( $send( 'finn' ) ) );
$w->t['checkins'][0]['status'] = 'confirmed'; ac_switch( null );
check( 'C13.5 access switch absent, six-week on: the confirmed week is still refused', 'checkin_sent' === ad_code( $send( 'finn' ) ) );
check( 'C13.6 why_collection is never refused for a confirmed check-in', 'no_audio' === ad_code( $send( 'finn', 'why_collection' ) ) );
unset( $GLOBALS['options']['hdlv2_ff_flight_cycle'] );
check( 'C13.7 six-week switch absent: sound for the confirmed week goes on as today', 'no_audio' === ad_code( $send( 'finn' ) ) );

// ── Both switches absent: today ──
$w = ac_world(); ac_switch( null );
check( 'D.12 switch absent, Ivy: check-in sound goes on as today', 'no_audio' === ad_code( $send( 'ivy' ) ) );
check( 'D.14 switch absent: nothing extra read (no report, plan or check-in query)', ! preg_grep( '/hdlv2_(reports|flight_plans|checkins)/', $w->queries ) );

// ── /audio/extract by link: refused as today, switch on or absent, nothing paid ──
foreach ( array( '1' => 'on', '' => 'absent' ) as $sw => $label ) {
    $w = ac_world(); ac_switch( '' === $sw ? null : $sw );
    $ok = true;
    foreach ( array( 'ivy', 'finn', 'rosa' ) as $who ) $ok = $ok && 'forbidden' === ad_code( $extract( $who ) );
    check( "E.1 switch $label: extract by link → 403 for every client (locked or open)", $ok );
    check( "E.2 switch $label: no AI call, nothing written", ! ad_paid() && ! $w->writes );
}
$w = ac_world(); ac_switch( '1' ); $GLOBALS['current_user'] = 3;
check( 'E.3 the practitioner signed in: extract goes on to the AI step as today', ad_passed( $extract( null ) ) );
$GLOBALS['current_user'] = 0;
done();
