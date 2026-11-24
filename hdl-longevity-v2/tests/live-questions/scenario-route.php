<?php
/**
 * Plan 14 part B, B3: POST /consultation/live-questions on the REAL classes, the AI stubbed.
 *   R.1  switch absent: the route does not exist (404), the screen gets no new setting, no AI call.
 *   R.2  the owner: one AI call with the filled prompt, the parsed questions and red flag back.
 *   R.3  another practitioner 404; a client 403; an admin passes (plan 13's owned()).
 *   R.4  heard over 20,000 characters keeps its end.
 *   R.5  heard: not a string → 400; not valid UTF-8 → 400; control characters out except tab and line feed.
 *   R.6  asked: at most 40 strings (the newest), each cut at 300 characters, anything else dropped.
 *   R.7  one Idempotency-Key pressed twice is charged once.
 *   R.8  the AI fails → a plain error, nothing stored. R.9 an unreadable answer → a plain error.
 *   R.10 success stores nothing. R.11 the log holds ids, lengths and codes only, never the client's words.
 *   R.12 no speech or microphone code in the new class or the new screen code (Matthew's 2026-07-15 sign-off).
 *   R.13 the route is on the AI-burn tier.
 * Run: php tests/live-questions/scenario-route.php   Exit: 0 all pass, 1 any fail
 */
require __DIR__ . '/lq-world.php';
$log = tempnam( sys_get_temp_dir(), 'lq-log' );
ini_set( 'error_log', $log );
ha_load( array( 'sprint-2c/class-hdlv2-consultation.php', 'security/class-hdlv2-rate-limit-policy.php' ) );
$have = class_exists( 'HDLV2_Live_Questions' );
check( 'the class HDLV2_Live_Questions exists', $have );
if ( ! $have ) done();

const R_LQ   = '/hdl-v2/v1/consultation/live-questions';
const ANSWER = "RED FLAG: chest tightness on the stairs. Ask her to see her GP this week.\nQ: How often do you wake in the night? | Why: wakes at 3 am\nQ: What do you eat after 8 pm? | Why: late snacks";

function boot() { $GLOBALS['routes'] = array(); ( new HDLV2_Consultation() )->register_rest_routes(); }
function call( $uid, $body = array(), $headers = array() ) {
    $GLOBALS['current_user'] = $uid;
    $args = $GLOBALS['routes'][ R_LQ ] ?? null;
    if ( ! $args ) return array( 404, null );
    if ( ! call_user_func( $args['permission_callback'] ) ) return array( $uid ? 403 : 401, null );
    $r = call_user_func( $args['callback'], new FakeRequest( $body, $headers ) );
    if ( $r instanceof WP_Error ) return array( (int) ( $r->get_error_data()['status'] ?? 500 ), array( 'code' => $r->get_error_code(), 'message' => $r->get_error_message() ) );
    return array( $r->get_status(), $r->get_data() );
}

echo "── R.1 switch absent ──\n";
$w = ha_world(); lq_switch( null ); boot();
list( $s ) = call( 3, array( 'progress_id' => 10, 'heard' => 'x' ) );
check( 'R.1 404, no AI call, no screen setting', 404 === $s && 0 === count( $GLOBALS['ai_calls'] ) && array() === HDLV2_Live_Questions::config( array() ) );

echo "── R.2 the owner ──\n";
lq_switch( '1' ); boot();
check( 'R.2a the screen setting says live_questions', array( 'live_questions' => 1 ) === HDLV2_Live_Questions::config( array() ) );
$GLOBALS['ai_replies'] = array( ANSWER );
list( $s, $d ) = call( 3, array( 'progress_id' => 10, 'heard' => 'She wakes at 3 am.', 'asked' => array( 'How is your sleep?' ) ), array( 'Idempotency-Key' => 'k-1' ) );
check( 'R.2b 200 with two questions and the red flag', 200 === $s && 2 === count( $d['questions'] ?? array() ) && 'chest tightness on the stairs. Ask her to see her GP this week.' === ( $d['red_flag'] ?? '' ) );
$c = end( $GLOBALS['ai_calls'] );
check( 'R.2c one AI call, the filled prompt as the only message, its own limit', 1 === count( $GLOBALS['ai_calls'] ) && empty( $c['system'] ) && HDLV2_Live_Questions::LIMIT === (int) $c['max_tokens']
    && false !== strpos( lq_sent(), 'Heard or noted so far in this consultation: She wakes at 3 am.' ) && false !== strpos( lq_sent(), '(do not repeat them): How is your sleep?' ) );

echo "── R.3 who may press ──\n";
$GLOBALS['ai_replies'] = array( ANSWER, ANSWER );
list( $s ) = call( 4, array( 'progress_id' => 10, 'heard' => 'x' ) );
check( 'R.3a another practitioner: 404', 404 === $s );
list( $s ) = call( 9, array( 'progress_id' => 10, 'heard' => 'x' ) );
check( 'R.3b a client: 403', 403 === $s );
list( $s ) = call( 1, array( 'progress_id' => 10, 'heard' => 'x' ) );
check( 'R.3c an admin: 200', 200 === $s );

echo "── R.4 to R.6 the boundary ──\n";
$GLOBALS['ai_replies'] = array( ANSWER );
call( 3, array( 'progress_id' => 10, 'heard' => 'START' . str_repeat( 'a', 25000 ) . 'END' ) );
check( 'R.4 over 20,000 characters keeps its end', false !== strpos( lq_sent(), 'aEND' ) && false === strpos( lq_sent(), 'START' ) && false !== strpos( lq_sent(), ': ' . str_repeat( 'a', 19997 ) . 'END' ) );
$n = count( $GLOBALS['ai_calls'] );
list( $s, $d ) = call( 3, array( 'progress_id' => 10, 'heard' => array( 'x' ) ) );
check( 'R.5a heard not a string: 400, no AI call', 400 === $s && $n === count( $GLOBALS['ai_calls'] ) );
list( $s ) = call( 3, array( 'progress_id' => 10, 'heard' => "bad \xC3\x28 bytes" ) );
check( 'R.5b heard not valid UTF-8: 400, no AI call', 400 === $s && $n === count( $GLOBALS['ai_calls'] ) );
$GLOBALS['ai_replies'] = array( ANSWER );
call( 3, array( 'progress_id' => 10, 'heard' => "a\x07b\tc\r\nd\x00e" ) );
check( 'R.5c control characters out, tab and line feed kept', false !== strpos( lq_sent(), "consultation: ab\tc\nde\n" ) );
$asked = array();
for ( $i = 1; $i <= 45; $i++ ) $asked[] = "Question $i?";
$asked[] = str_repeat( 'q', 500 );
$asked[] = array( 'not', 'a', 'string' );
$asked[] = 42;
$GLOBALS['ai_replies'] = array( ANSWER );
call( 3, array( 'progress_id' => 10, 'heard' => 'x', 'asked' => $asked ) );
$block = substr( lq_sent(), strpos( lq_sent(), '(do not repeat them): ' ) + 22 );
$block = substr( $block, 0, strpos( $block, "\n\nRULES" ) );
$lines = explode( "\n", $block );
check( 'R.6 forty at most (the newest), each cut at 300, the rest dropped', 40 === count( $lines ) && 'Question 7?' === $lines[0] && str_repeat( 'q', 300 ) === end( $lines ) && false === strpos( $block, 'Question 6?' ) );

echo "── R.7 to R.11 ──\n";
$GLOBALS['ai_replies'] = array( ANSWER, ANSWER );
$n = count( $GLOBALS['ai_calls'] );
call( 3, array( 'progress_id' => 10, 'heard' => 'twice' ), array( 'Idempotency-Key' => 'same-key' ) );
call( 3, array( 'progress_id' => 10, 'heard' => 'twice' ), array( 'Idempotency-Key' => 'same-key' ) );
check( 'R.7 one key pressed twice: one AI call', $n + 1 === count( $GLOBALS['ai_calls'] ) );
$w->updates = array();
$GLOBALS['ai_replies'] = array( array( 'code' => 500 ) );
list( $s, $d ) = call( 3, array( 'progress_id' => 10, 'heard' => 'The client says MARKERWORD.' ) );
check( 'R.8 the AI fails: 502, the plain line, nothing stored', 502 === $s && 'Could not get questions. Try again.' === ( $d['message'] ?? '' ) && array() === $w->updates );
$GLOBALS['ai_replies'] = array( 'I think the client should rest more.' );
list( $s, $d ) = call( 3, array( 'progress_id' => 10, 'heard' => 'x' ) );
check( 'R.9 an unreadable answer: 502, the plain line, not the model\'s text', 502 === $s && 'Could not get questions. Try again.' === ( $d['message'] ?? '' ) && false === strpos( wp_json_encode( $d ), 'rest more' ) );
$GLOBALS['ai_replies'] = array( ANSWER );
call( 3, array( 'progress_id' => 10, 'heard' => 'The client says MARKERWORD.' ) );
check( 'R.10 success stores nothing', array() === $w->updates );
$logged = (string) file_get_contents( $log );
check( 'R.11 the log names the consultation and lengths, never the client\'s words', false !== strpos( $logged, 'consult=55' ) && false === strpos( $logged, 'MARKERWORD' ) && false === strpos( $logged, 'wakes at 3 am' ) );

echo "── R.12, R.13 ──\n";
$src = (string) file_get_contents( ha_root() . '/includes/sprint-2c/class-hdlv2-live-questions.php' );
$js  = (string) file_get_contents( ha_root() . '/assets/js/hdlv2-consultation.js' );
$at  = strpos( $js, '// ── QUESTIONS TO ASK NOW' );
$card = false === $at ? '' : substr( $js, $at, strpos( $js, '// ── END QUESTIONS TO ASK NOW', $at ) - $at );
$no = true;
foreach ( array( 'SpeechRecognition', 'webkitSpeechRecognition', 'getUserMedia' ) as $word ) $no = $no && false === strpos( $src, $word ) && false === strpos( $card, $word );
check( 'R.12 no speech or microphone code in the class or the card', '' !== $src && '' !== $card && $no );
check( 'R.13 POST /consultation/live-questions is on the AI-burn tier', HDLV2_Rate_Limit_Policy::TIER_AI_BURN === HDLV2_Rate_Limit_Policy::tier_for_request( 'POST', R_LQ ) );
@unlink( $log );
done();
