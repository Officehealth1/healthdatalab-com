<?php
/**
 * Plan 15 T1: POST /audio/listen-piece on the REAL classes, Deepgram stubbed (no network).
 *   P.1  who: signed out 401, a client 403, another practitioner 404 (plan 13's owned(), called: the same answer
 *        "Suggest questions" gives), an admin passes. A missing nonce: WordPress drops the cookie session, so the
 *        call arrives signed out and gets the 401 of P.1a; the route's permission is check_practitioner_auth.
 *  P.2  the boundary: no file 400; seq not a whole number 400; a type that is not audio 415; over 2 MB 413.
 *  P.3  a good piece: { seq, text }, one Deepgram call, nova-3, a 15-second limit, the client's own medicine and
 *       condition names as hint words (cleaned, capped).
 *  P.4  the same Idempotency-Key twice: Deepgram once.
 *  P.5  Deepgram fails: 502 with our own sentence, none of its body.
 *  P.6  a piece with no speech: 200 with empty text.
 *  P.7  the log holds numbers only; no table written (only the day's counter, a transient).
 *  P.8  the day's counter adds the seconds Deepgram reports; at the daily limit: 429 "Listening is resting for
 *       today." and no Deepgram call.
 *  P.9  Deepgram says the credit is used up (402 ASR_PAYMENT_REQUIRED, T0): our own sentence, and one alert e-mail
 *       that day to the plugin's alert setting, not one per piece.
 *  P.10 no speech recognition in the browser's sense and no sound kept: the class never writes a file.
 * Run: php tests/live-listen/scenario-piece-route.php   Exit: 0 all pass, 1 any fail
 */
require __DIR__ . '/ll-world.php';
$log = tempnam( sys_get_temp_dir(), 'll-log' );
ini_set( 'error_log', $log );
const R_PIECE = '/hdl-v2/v1/audio/listen-piece';
$have = class_exists( 'HDLV2_Live_Listen' );
check( 'the class HDLV2_Live_Listen exists', $have );
if ( ! $have ) done();
$w = ha_world();
// The client's own words in the form: medicines and conditions, with junk a hint word must never carry.
$s3 = json_decode( $w->t['form_progress'][0]['stage3_data'], true );
$s3['medications'] = "Metformin 500mg, ramipril; <script>x</script>\nvitamin D3";
$s3['existing_conditions'] = "type 2 diabetes\nhigh blood pressure, I was told years ago that my thyroid is a bit slow";
$w->t['form_progress'][0]['stage3_data'] = json_encode( $s3 );
$GLOBALS['options']['hdlv2_stage1_alert_to'] = 'alerts@example.test, ops@example.test';
lq_switch( '1' ); ll_switch( '1' ); ll_boot();
$good = array( 'progress_id' => 10, 'seq' => 3 );

echo "── P.1 who ──\n";
check( 'P.1a signed out: 401', 401 === ll_call( R_PIECE, 0, $good, array(), array( 'audio' => ll_file() ) )[0] );
check( 'P.1b a client: 403', 403 === ll_call( R_PIECE, 9, $good, array(), array( 'audio' => ll_file() ) )[0] );
check( 'P.1c another practitioner: 404, no Deepgram call', 404 === ll_call( R_PIECE, 4, $good, array(), array( 'audio' => ll_file() ) )[0] && empty( $GLOBALS['http_urls'] ) );
check( 'P.1d the permission is the screen\'s practitioner check', array( 'HDLV2_Consultation', 'check_practitioner_auth' ) === array( get_class( $GLOBALS['routes'][ R_PIECE ]['permission_callback'][0] ), $GLOBALS['routes'][ R_PIECE ]['permission_callback'][1] ) );
$GLOBALS['ai_replies'] = array( ll_dg() );
check( 'P.1e an admin: 200', 200 === ll_call( R_PIECE, 1, $good, array(), array( 'audio' => ll_file() ) )[0] );

echo "── P.2 the boundary ──\n";
$GLOBALS['http_urls'] = array();
check( 'P.2a no file: 400', 400 === ll_call( R_PIECE, 3, $good )[0] );
check( 'P.2b seq not a whole number: 400', 400 === ll_call( R_PIECE, 3, array( 'progress_id' => 10, 'seq' => '1; DROP' ), array(), array( 'audio' => ll_file() ) )[0]
    && 400 === ll_call( R_PIECE, 3, array( 'progress_id' => 10, 'seq' => -1 ), array(), array( 'audio' => ll_file() ) )[0] );
check( 'P.2c not audio: 415', 415 === ll_call( R_PIECE, 3, $good, array(), array( 'audio' => ll_file( 3000, 'text' ) ) )[0] );
check( 'P.2d over 2 MB: 413', 413 === ll_call( R_PIECE, 3, $good, array(), array( 'audio' => ll_file( 2 * 1024 * 1024 + 1 ) ) )[0] );
check( 'P.2e none of these reached Deepgram', empty( $GLOBALS['http_urls'] ) );

echo "── P.3 a good piece ──\n";
$GLOBALS['transients'] = array();
$GLOBALS['ai_replies'] = array( ll_dg( 'She takes metformin every morning.', 6.4 ) );
list( $s, $d ) = ll_call( R_PIECE, 3, $good, array( 'Idempotency-Key' => 'p-3-a' ), array( 'audio' => ll_file() ) );
check( 'P.3a 200 { seq: 3, text }', 200 === $s && 3 === ( $d['seq'] ?? null ) && 'She takes metformin every morning.' === ( $d['text'] ?? '' ) );
$url  = (string) end( $GLOBALS['http_urls'] );
$args = end( $GLOBALS['http_args'] );
check( 'P.3b one call to Deepgram, nova-3, a 15-second limit', 1 === count( $GLOBALS['http_urls'] ) && 0 === strpos( $url, 'https://api.deepgram.com/v1/listen?' ) && false !== strpos( $url, 'model=nova-3' ) && 15 === (int) ( $args['timeout'] ?? 0 ) );
parse_str( str_replace( 'keyterm=', 'keyterm[]=', (string) parse_url( $url, PHP_URL_QUERY ) ), $q );
$terms = $q['keyterm'] ?? array();
check( 'P.3c hint words from the form: the client\'s own medicine and condition names', in_array( 'Metformin 500mg', $terms, true ) && in_array( 'ramipril', $terms, true ) && in_array( 'type 2 diabetes', $terms, true ) && in_array( 'high blood pressure', $terms, true ) );
$clean = true;
foreach ( $terms as $t ) $clean = $clean && preg_match( '/^[\p{L}\p{N} \-]{1,40}$/u', $t );
check( 'P.3d each hint word is letters, digits, spaces and hyphens, at most 40 characters; nothing of the script tag', $clean && ! in_array( 'script', array_map( 'strtolower', $terms ), true ) && count( $terms ) <= HDLV2_Live_Listen::KEYTERMS );
check( 'P.3e the sound goes as sent, with its own type', 'audio/webm' === ( $args['headers']['Content-Type'] ?? '' ) );

echo "── P.4 one key twice ──\n";
$GLOBALS['ai_replies'] = array( ll_dg(), ll_dg() );
$n = count( $GLOBALS['http_urls'] );
ll_call( R_PIECE, 3, $good, array( 'Idempotency-Key' => 'piece-same-key' ), array( 'audio' => ll_file() ) );
ll_call( R_PIECE, 3, $good, array( 'Idempotency-Key' => 'piece-same-key' ), array( 'audio' => ll_file() ) );
check( 'P.4 Deepgram once', $n + 1 === count( $GLOBALS['http_urls'] ) );

echo "── P.5 Deepgram fails ──\n";
$GLOBALS['ai_replies'] = array( array( 'raw' => array( 500, json_encode( array( 'err_msg' => 'INTERNALBODYWORDS' ) ) ) ) );
list( $s, $d ) = ll_call( R_PIECE, 3, $good, array(), array( 'audio' => ll_file() ) );
check( 'P.5 502, our own sentence, none of its body', 502 === $s && 'listen_failed' === ( $d['code'] ?? '' ) && 'A few seconds were not heard.' === ( $d['message'] ?? '' ) && false === strpos( json_encode( $d ), 'INTERNALBODY' ) );

echo "── P.6 no speech ──\n";
$GLOBALS['ai_replies'] = array( ll_dg( '', 6.0 ) );
list( $s, $d ) = ll_call( R_PIECE, 3, array( 'progress_id' => 10, 'seq' => 4 ), array(), array( 'audio' => ll_file() ) );
check( 'P.6 200 with empty text', 200 === $s && 4 === $d['seq'] && '' === $d['text'] );

echo "── P.7 log and storage ──\n";
$logged = (string) file_get_contents( $log );
check( 'P.7a the log names the consultation and numbers, never the words', false !== strpos( $logged, '[HDLV2 listen] consult=55' ) && false === strpos( $logged, 'metformin' ) && false === strpos( $logged, 'Metformin' ) );
check( 'P.7b no table written', array() === $w->updates && ! preg_grep( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)/i', $w->queries ) );

echo "── P.8 the day's counter ──\n";
$GLOBALS['transients'] = array();
$GLOBALS['ai_replies'] = array( ll_dg( 'one', 6.4 ), ll_dg( 'two', 10.0 ) );
ll_call( R_PIECE, 3, $good, array(), array( 'audio' => ll_file() ) );
ll_call( R_PIECE, 3, $good, array(), array( 'audio' => ll_file() ) );
check( 'P.8a the counter holds the seconds Deepgram reported (16.4)', abs( 16.4 - (float) get_transient( ll_day_key() ) ) < 0.01 );
check( 'P.8b the limit is a named number: 600 minutes a day', 600 === HDLV2_Live_Listen::DAY_MINUTES );
set_transient( ll_day_key(), HDLV2_Live_Listen::DAY_MINUTES * 60 );
$n = count( $GLOBALS['http_urls'] );
list( $s, $d ) = ll_call( R_PIECE, 3, $good, array(), array( 'audio' => ll_file() ) );
check( 'P.8c at the limit: 429 "Listening is resting for today.", no Deepgram call', 429 === $s && 'listen_resting' === ( $d['code'] ?? '' ) && 'Listening is resting for today.' === ( $d['message'] ?? '' ) && $n === count( $GLOBALS['http_urls'] ) );

echo "── P.9 the credit is used up ──\n";
$GLOBALS['transients'] = array(); $GLOBALS['mails'] = array();
$no_credit = array( 'raw' => array( 402, json_encode( array( 'err_code' => 'ASR_PAYMENT_REQUIRED', 'err_msg' => 'Project does not have enough credits for an ASR request and does not have an overage agreement.', 'request_id' => 'u' ) ) ) );
$GLOBALS['ai_replies'] = array( $no_credit, $no_credit );
list( $s, $d ) = ll_call( R_PIECE, 3, $good, array(), array( 'audio' => ll_file() ) );
ll_call( R_PIECE, 3, $good, array(), array( 'audio' => ll_file() ) );
check( 'P.9a our own sentence, none of Deepgram\'s', 503 === $s && 'listen_no_credit' === ( $d['code'] ?? '' ) && 'Listening is not available right now.' === ( $d['message'] ?? '' ) && false === strpos( json_encode( $d ), 'overage' ) );
check( 'P.9b one alert e-mail that day, to the alert setting\'s addresses', 1 === count( $GLOBALS['mails'] ) && 'alerts@example.test,ops@example.test' === $GLOBALS['mails'][0][0] );
check( 'P.9c the e-mail carries no client words', false === strpos( json_encode( $GLOBALS['mails'] ), 'Ivy' ) && false === strpos( json_encode( $GLOBALS['mails'] ), 'metformin' ) );

echo "── P.10 nothing kept ──\n";
$src = (string) file_get_contents( ha_root() . '/includes/sprint-2c/class-hdlv2-live-listen.php' );
$no = true;
foreach ( array( 'file_put_contents', 'move_uploaded_file', 'wp_handle_upload', 'store_audio_file', '$wpdb->insert', '$wpdb->update' ) as $word ) $no = $no && false === strpos( $src, $word );
check( 'P.10 the class writes no file and no table row', '' !== $src && $no );
@unlink( $log );
done();
