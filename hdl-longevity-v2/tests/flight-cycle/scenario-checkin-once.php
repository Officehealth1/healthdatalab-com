<?php
/**
 * Plan 14, C0/C6: a confirmed check-in cannot be sent again for the same week. C0 proved the double
 * effect by reading: a second send writes the row back to 'draft' (after a second AI summary), and a
 * second confirm runs every effect again (timeline row, flags e-mail, plan job). Switch on: the second
 * send answers 409 `checkin_sent` before the AI call, nothing written. A draft (not yet confirmed) can
 * still be sent again, as today. Switch absent: today's behaviour (the send goes on to the AI).
 *
 * Run: php tests/flight-cycle/scenario-checkin-once.php   Exit: 0 all pass, 1 any fail
 */
error_reporting( E_ALL & ~E_WARNING & ~E_DEPRECATED );
require dirname( __DIR__ ) . '/access/stubs.php';
function sanitize_textarea_field( $x ) { return trim( (string) $x ); }
class HDLV2_Audio_Service {
    public static function get_instance() { return new self(); }
    public function process_text( $raw, $kind, $meta ) { $GLOBALS['calls'][] = 'ai:' . $kind; return '{"check_in_summary":"ok"}'; }
}
ac_load( array( 'sprint-4/class-hdlv2-checkin.php', 'sprint-5/class-hdlv2-flight-cycle.php' ) );
$ci   = HDLV2_Checkin::get_instance();
$tok  = str_repeat( 'd', 64 );          // Rosa (13)
$week = date( 'Y-m-d', strtotime( 'monday this week' ) ); // the check-in's own week rule
function fc_ci_world( $status ) {
    global $week;
    $w = ac_world();
    $w->t['checkins'][] = array( 'id' => 40, 'client_id' => 13, 'week_start' => $week, 'status' => $status, 'deleted_at' => null );
    return $w;
}
function fc_ai() { return count( array_filter( $GLOBALS['calls'], function ( $c ) { return 0 === strpos( $c, 'ai:' ); } ) ); }
$send = function () use ( $ci, $tok ) { return $ci->rest_submit( new FakeRequest( array( 'token' => $tok, 'text' => 'Another go.' ) ) ); };

$GLOBALS['options']['hdlv2_ff_flight_cycle'] = 1;
$w = fc_ci_world( 'confirmed' );
$r = $send();
check( 'O.1 on: a second send of a confirmed week → 409 checkin_sent', $r instanceof WP_Error && 'checkin_sent' === $r->get_error_code() && 409 === ( $r->get_error_data()['status'] ?? 0 ) );
check( 'O.2 on: refused before the AI call, nothing written', 0 === fc_ai() && 0 === count( $w->writes ) );
$w = fc_ci_world( 'draft' );
$r = $send();
check( 'O.3 on: a draft can still be sent again (the AI runs, the row is written)', ! is_wp_error( $r ) && 1 === fc_ai() && count( $w->writes ) > 0 );
$w = ac_world();
check( 'O.4 on: the first send of a week goes through', ! is_wp_error( $send() ) && 1 === fc_ai() );
$w = fc_ci_world( 'confirmed' ); $w->t['checkins'][0]['week_start'] = '2026-01-05';
check( 'O.5 on: a confirmed check-in of another week does not block this week', ! is_wp_error( $send() ) && 1 === fc_ai() );

unset( $GLOBALS['options']['hdlv2_ff_flight_cycle'] );
$w = fc_ci_world( 'confirmed' );
$r = $send();
check( 'O.6 absent: today\'s behaviour, the send goes on to the AI and the row is written', ! is_wp_error( $r ) && 1 === fc_ai() && count( $w->writes ) > 0 );
done();
