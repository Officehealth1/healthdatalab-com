<?php
/**
 * 30-day challenge — enrolment, the unlock rule (D1), the tick, the timeline
 * entry, delete-with-the-user. Against the REAL HDLV2_Challenge.
 *
 * Run:  php scenario-unlock.php   (self-asserting, exit 0/1)
 */
require __DIR__ . '/stubs.php';

echo "── U1. enrolment ──\n";
$w = fresh();
check( 'U1.1 not enrolled → state() null', null === HDLV2_Challenge::state( 5 ) );
$r = HDLV2_Challenge::tick( 5, 1, '', at( '2026-10-28 10:00:00' ) );
check( 'U1.2 not enrolled → tick() 403 not_enrolled, nothing written', 403 === status_of( $r ) && 'not_enrolled' === code_of( $r ) && ! $w->rows );
$inv = (object) array( 'id' => 11, 'practitioner_id' => 122, 'external_ref' => 'cs_test_abc' );
HDLV2_Challenge::enrol( 5, $inv );
$e = HDLV2_Challenge::enrolment( 5 );
check( 'U1.3 enrol() writes started_at (UTC), source stripe for a cs_ ref, ref, invite id, practitioner', is_array( $e ) && preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $e['started_at'] ) && 'stripe' === $e['source'] && 'cs_test_abc' === $e['external_ref'] && 11 === $e['invite_id'] && 122 === $e['practitioner_id'] );
$GLOBALS['usermeta'][5]['hdlv2_challenge'] = str_replace( $e['started_at'], '2026-01-01 00:00:00', $GLOBALS['usermeta'][5]['hdlv2_challenge'] );
HDLV2_Challenge::enrol( 5, $inv );
check( 'U1.4 a repeat login never resets the start', '2026-01-01 00:00:00' === HDLV2_Challenge::enrolment( 5 )['started_at'] );
HDLV2_Challenge::enrol( 6, (object) array( 'id' => 12, 'practitioner_id' => 122, 'external_ref' => 'walk-1' ) );
check( 'U1.5 any other ref → source granted', 'granted' === HDLV2_Challenge::enrolment( 6 )['source'] );
$GLOBALS['usermeta'][7]['hdlv2_challenge'] = 'not json';
check( 'U1.6 garbage meta → not enrolled', null === HDLV2_Challenge::enrolment( 7 ) );

echo "── U2. the unlock rule (D1: ticked AND a later UTC date) ──\n";
$w = fresh();
enrol_client( 5 );
$s = HDLV2_Challenge::state( 5, at( '2026-10-28 10:00:00' ) );
check( 'U2.1 no ticks → open_day 1, 0 done, not complete', 1 === $s['open_day'] && 0 === $s['done_count'] && false === $s['complete'] && null === $s['next_opens_at'] );
check( 'U2.2 30 days in the state, all undone', 30 === count( $s['days'] ) && 1 === min( array_keys( $s['days'] ) ) && 30 === max( array_keys( $s['days'] ) ) && null === $s['days'][30]['done_at'] );
$r = HDLV2_Challenge::tick( 5, 1, 'hard one', at( '2026-10-28 09:00:00' ) );
check( 'U2.3 tick day 1 → state back, day 1 done at the tick time', is_array( $r ) && '2026-10-28 09:00:00' === $r['days'][1]['done_at'] && 'hard one' === $r['days'][1]['note'] && 1 === $r['done_count'] );
$s = HDLV2_Challenge::state( 5, at( '2026-10-28 23:59:59' ) );
check( 'U2.4 same UTC day 23:59:59 → open_day still 1 (done, nothing to tick)', 1 === $s['open_day'] && '2026-10-29 00:00:00' === $s['next_opens_at'] );
$r = HDLV2_Challenge::tick( 5, 2, '', at( '2026-10-28 23:59:59' ) );
check( 'U2.5 day 2 the same day → 409 day_locked "not open yet"', 409 === status_of( $r ) && 'day_locked' === code_of( $r ) && 'This day is not open yet.' === $r->message );
$s = HDLV2_Challenge::state( 5, at( '2026-10-29 00:00:01' ) );
check( 'U2.6 next UTC day 00:00:01 → open_day 2', 2 === $s['open_day'] && null === $s['next_opens_at'] );
$r = HDLV2_Challenge::tick( 5, 3, '', at( '2026-10-29 08:00:00' ) );
check( 'U2.7 tick day 3 while day 2 is open → 409 day_locked', 409 === status_of( $r ) && 'day_locked' === code_of( $r ) );
$r = HDLV2_Challenge::tick( 5, 1, '', at( '2026-10-29 08:00:00' ) );
check( 'U2.8 tick a done earlier day → 409 "Already done."', 409 === status_of( $r ) && 'Already done.' === $r->message );
$GLOBALS['timeline'] = array();
$r1 = HDLV2_Challenge::tick( 5, 2, 'ok', at( '2026-10-29 08:00:00' ) );
$r2 = HDLV2_Challenge::tick( 5, 2, 'again', at( '2026-10-29 08:00:05' ) );
check( 'U2.9 day 2 twice → one row, same state, the first note kept', 2 === count( $w->rows[5] ) && $r1 == $r2 && 'ok' === $r2['days'][2]['note'] );
check( 'U2.10 …and one timeline entry, not two', 1 === count( $GLOBALS['timeline'] ) );

echo "── U3. input ──\n";
$w = fresh();
enrol_client( 5 );
foreach ( array( 0, 31, -1, 'x' ) as $bad ) {
    $r = HDLV2_Challenge::tick( 5, $bad, '', at( '2026-10-28 10:00:00' ) );
    check( 'U3.1 day ' . var_export( $bad, true ) . ' → 400 invalid_day', 400 === status_of( $r ) && 'invalid_day' === code_of( $r ) );
}
$r = HDLV2_Challenge::tick( 5, 1, str_repeat( 'é', 501 ), at( '2026-10-28 10:00:00' ) );
check( 'U3.2 a 501-character note is cut to 500 characters', 500 === mb_strlen( $r['days'][1]['note'] ) );
$w = fresh();
enrol_client( 5 );
$r = HDLV2_Challenge::tick( 5, 1, "<script>alert(1)</script>Felt <b>tired</b>\nbut did it", at( '2026-10-28 10:00:00' ) );
check( 'U3.3 tags never stored; line breaks kept', is_array( $r ) && false === strpos( $r['days'][1]['note'], '<' ) && false !== strpos( $r['days'][1]['note'], "\n" ) );
$r = HDLV2_Challenge::tick( 5, 2, null, at( '2026-10-29 10:00:00' ) );
check( 'U3.4 no note → stored empty', is_array( $r ) && '' === $r['days'][2]['note'] );

echo "── U4. progress: done count, streak, complete ──\n";
$w = fresh();
enrol_client( 5 );
$w->seed( 5, 30, '2026-11-26' );
$s = HDLV2_Challenge::state( 5, at( '2026-11-27 10:00:00' ) );
check( 'U4.1 30 ticks on 30 dates → done 30, complete, open_day null', 30 === $s['done_count'] && true === $s['complete'] && null === $s['open_day'] && null === $s['next_opens_at'] );
check( 'U4.2 streak 30', 30 === $s['streak'] );
$w = fresh();
enrol_client( 5 );
$w->seed( 5, 5, '2026-11-05' );
$w->rows[5][2]->done_at = '2026-10-30 09:00:00'; // a gap after day 2
$s = HDLV2_Challenge::state( 5, at( '2026-11-05 12:00:00' ) );
check( 'U4.3 streak counts back from the last tick to the first gap (3)', 3 === $s['streak'] && 5 === $s['done_count'] );
$w = fresh();
enrol_client( 5 );
$w->seed( 5, 1, '2026-10-28' );
check( 'U4.4 one tick → streak 1', 1 === HDLV2_Challenge::state( 5, at( '2026-10-28 12:00:00' ) )['streak'] );

echo "── U5. the timeline entry ──\n";
$w = fresh();
enrol_client( 5, 206 );
HDLV2_Challenge::tick( 5, 1, 'walk test', at( '2026-10-28 10:00:00' ) );
$t = $GLOBALS['timeline'][0] ?? array();
check( 'U5.1 one add_entry(client, practitioner, challenge_day, "Day 1 of 30 done", note, null, hdlv2_challenge_days, row id)', 1 === count( $GLOBALS['timeline'] )
    && 5 === $t[0] && 206 === $t[1] && 'challenge_day' === $t[2] && 'Day 1 of 30 done' === $t[3] && 'walk test' === $t[4]
    && null === $t[5] && 'hdlv2_challenge_days' === $t[6] && $w->insert_id === $t[7] );
HDLV2_Challenge::tick( 5, 3, '', at( '2026-10-29 10:00:00' ) );
check( 'U5.2 a refused tick writes no entry', 1 === count( $GLOBALS['timeline'] ) );

echo "── U6. the route ──\n";
$w = fresh();
HDLV2_Challenge::register_hooks();
foreach ( $GLOBALS['actions']['rest_api_init'] ?? array() as $cb ) { call_user_func( $cb ); }
$route = $GLOBALS['routes']['hdl-v2/v1/challenge/tick'] ?? null;
check( 'U6.1 POST hdl-v2/v1/challenge/tick, logged-in only', $route && 'POST' === $route['methods'] && 'is_user_logged_in' === $route['permission_callback'] );
class FakeRequest { private $p; public function __construct( $p ) { $this->p = $p; } public function get_param( $k ) { return $this->p[ $k ] ?? null; } }
enrol_client( 5 );
$GLOBALS['current_user'] = 5;
$res = call_user_func( $route['callback'], new FakeRequest( array( 'day' => 1, 'note' => 'from rest', 'client_id' => 9 ) ) );
check( 'U6.2 the current user is the client (a client_id in the body is ignored)', is_array( $res ) && isset( $w->rows[5][1] ) && ! isset( $w->rows[9] ) );
check( 'U6.3 the answer carries the state and the re-rendered section', 1 === $res['state']['done_count'] && false !== strpos( $res['html'], 'id="challenge"' ) );

echo "── U7. the user is deleted → the rows go ──\n";
$w = fresh();
enrol_client( 5 );
$w->seed( 5, 3, '2026-10-30' );
enrol_client( 6 );
$w->seed( 6, 1, '2026-10-30' );
HDLV2_Challenge::register_hooks();
$cb = $GLOBALS['actions']['deleted_user'][0] ?? null;
check( 'U7.1 hooked on deleted_user', is_callable( $cb ) );
call_user_func( $cb, 5 );
check( 'U7.2 the days of that client only are deleted', false !== strpos( $w->deletes[0], 'client_user_id = 5' ) && ! isset( $w->rows[5] ) && isset( $w->rows[6] ) );
check( 'U7.3 …and the timeline copies of their notes', 2 === count( $w->deletes ) && false !== strpos( $w->deletes[1], 'DELETE FROM wp_hdlv2_timeline' ) && false !== strpos( $w->deletes[1], 'client_id = 5' ) && false !== strpos( $w->deletes[1], "source_table = 'hdlv2_challenge_days'" ) );

echo "── U8. a failed save is said, never announced as saved ──\n";
$w = fresh();
enrol_client( 5 );
$w->fail_insert = true;
$GLOBALS['timeline'] = array();
$r = HDLV2_Challenge::tick( 5, 1, 'x', at( '2026-10-28 10:00:00' ) );
check( 'U8.1 INSERT error → 500 save_failed, no timeline entry', 500 === status_of( $r ) && 'save_failed' === code_of( $r ) && ! $GLOBALS['timeline'] );

echo "── U9. opening the challenge link ──\n";
$inv = (object) array( 'id' => 11, 'practitioner_id' => 122, 'external_ref' => 'cs_test_abc', 'client_email' => 'Buyer@Example.test' );
$w = fresh();
$GLOBALS['users'][5] = 'buyer@example.test';
check( 'U9.1 nothing on file → enrolled, no landing (the widget page renders)', null === HDLV2_Challenge::open_link( 5, $inv ) && null !== HDLV2_Challenge::enrolment( 5 ) );
$w->leads = array( '122|buyer@example.test' );
check( 'U9.2 a Stage 1 lead for this email → /my-dashboard/#challenge', 'https://stby.test/my-dashboard/#challenge' === HDLV2_Challenge::open_link( 5, $inv ) );
$w = fresh();
$GLOBALS['users'][5] = 'buyer@example.test';
$w->stage1_progress = array( 5 );
check( 'U9.3 a client record with Stage 1 → the dashboard', 'https://stby.test/my-dashboard/#challenge' === HDLV2_Challenge::open_link( 5, $inv ) );
$w = fresh();
$GLOBALS['users'][5] = 'buyer@example.test';
$w->leads = array( '206|buyer@example.test' );
check( 'U9.4 a lead with another practitioner does not count', null === HDLV2_Challenge::open_link( 5, $inv ) );

echo "── U10. the link opened in a browser that is already signed in ──\n";
$hex = str_repeat( 'ab', 32 );
$land = function () {
    $flag = new ReflectionProperty( 'HDLV2_Challenge', 'opened' ); // one request per call
    $flag->setAccessible( true );
    $flag->setValue( null, false );
    try { HDLV2_Challenge::signed_in_link(); } catch ( Redirected $e ) { return $e->getMessage(); }
    return null;
};
$w = fresh();
$GLOBALS['users'][5] = 'buyer@example.test';
$GLOBALS['current_user'] = 5;
$w->invites[ $hex ] = $inv;
$w->leads = array( '122|buyer@example.test' );
$_GET['invite'] = $hex;
check( 'U10.1 same email (any case) → enrolled and sent to the dashboard', 'https://stby.test/my-dashboard/#challenge' === $land() && null !== HDLV2_Challenge::enrolment( 5 ) );
$q = implode( "\n", $w->queries );
check( 'U10.2 only a live challenge link counts', false !== strpos( $q, "source = 'challenge'" ) && false !== strpos( $q, "status IN ('pending','opened')" ) && false !== strpos( $q, 'expires_at > UTC_TIMESTAMP()' ) );
$GLOBALS['users'][5] = 'someone.else@example.test';
$GLOBALS['usermeta'] = array();
check( 'U10.3 signed in as another account → nothing (no enrolment, no redirect)', null === $land() && null === HDLV2_Challenge::enrolment( 5 ) );
$GLOBALS['users'][5] = 'buyer@example.test';
$GLOBALS['staff'] = array( 5 );
check( 'U10.4 a staff account → nothing', null === $land() && null === HDLV2_Challenge::enrolment( 5 ) );
$GLOBALS['staff'] = array();
$_GET['invite'] = 'not-a-token';
check( 'U10.5 a malformed token → nothing', null === $land() );
$_GET['invite'] = $hex;
$GLOBALS['current_user'] = 0;
check( 'U10.6 signed out → left to the ?invite= login', null === $land() );
$GLOBALS['current_user'] = 5;
$_GET['invite'] = str_repeat( 'cd', 32 );
check( 'U10.7 not a challenge link → nothing', null === $land() );
HDLV2_Challenge::register_hooks();
check( 'U10.8 hooked on init', ! empty( $GLOBALS['actions']['init'] ) );

done();
