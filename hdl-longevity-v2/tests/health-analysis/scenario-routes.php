<?php
/**
 * Plan 13, § 6.4 and rule 5: the two routes and the consultation load, REAL classes, AI stubbed.
 *   R1  switch off: neither route exists (404), the load carries no new key, no AI call.
 *   R2  owner writes: both texts stored and returned; another practitioner 404; a client 403; an admin
 *       passes (the save-brief rule); a double click with one Idempotency-Key is charged once.
 *   R3  the stamp: approve stamps who and when; writing again clears it; saving without approving
 *       clears it; an empty text with approve is a 400; more than 4,000 characters is a 400; tags are
 *       taken out (plain text).
 *   R4  failures: prompt 1 fails → nothing changed; prompt 2 fails → the new analysis stays, the old
 *       note stays unapproved, the answer says the note was not written. The log holds no client words.
 *   R5  the load: with the switch on, one block (analysis as safe HTML, its time, the note, the stamp).
 *
 * Run: php tests/health-analysis/scenario-routes.php   Exit: 0 all pass, 1 any fail
 */
require __DIR__ . '/stubs.php';
$log = tempnam( sys_get_temp_dir(), 'ha-log' );
ini_set( 'error_log', $log );
ha_load( array(
    'security/class-hdlv2-idempotency.php', 'sprint-2/class-hdlv2-ai-service.php', 'sprint-2/class-hdlv2-email-templates.php',
    'class-hdlv2-report-names.php', 'sprint-2c/class-hdlv2-health-analysis.php', 'sprint-2c/class-hdlv2-consultation.php',
) );
$have = class_exists( 'HDLV2_Health_Analysis' );
check( 'the class HDLV2_Health_Analysis exists', $have );

const R_WRITE = '/hdl-v2/v1/consultation/health-analysis';
const R_NOTE  = '/hdl-v2/v1/consultation/health-note';
const ANALYSIS = "Red flags: none identified.\n\n1. Bottom line\nBloating after meals with afternoon tiredness. **Late eating may be the main driver.**";
const NOTE     = "The short version\n**Eating late may be behind the bloating.**";

/** Register the routes as rest_api_init would, then dispatch one request the way the REST server does. */
function boot() { $GLOBALS['routes'] = array(); ( new HDLV2_Consultation() )->register_rest_routes(); }
function call( $route, $uid, $body = array(), $headers = array() ) {
    $GLOBALS['current_user'] = $uid;
    $args = $GLOBALS['routes'][ $route ] ?? null;
    if ( ! $args ) return array( 404, null );
    if ( ! call_user_func( $args['permission_callback'] ) ) return array( $uid ? 403 : 401, null );
    $r = call_user_func( $args['callback'], new FakeRequest( $body, $headers ) );
    if ( $r instanceof WP_Error ) return array( (int) ( $r->get_error_data()['status'] ?? 500 ), array( 'code' => $r->get_error_code(), 'message' => $r->get_error_message() ) );
    return array( $r->get_status(), $r->get_data() );
}
function row( $w ) { return ha_consult( $w ); }
function load( $uid ) {
    $GLOBALS['current_user'] = $uid;
    $r = ( new HDLV2_Consultation() )->rest_load_consultation( new FakeRequest( array( 'progress_id' => 10 ) ) );
    return $r instanceof WP_REST_Response ? $r->get_data() : $r;
}

echo "── R1 switch off ──\n";
$w = ha_world();
$w->t['form_progress'][0]['pre_consult_summary'] = json_encode( array( 'health_summary' => 'cached' ) );
ha_switch( null );
boot();
list( $s ) = call( R_WRITE, 3, array( 'progress_id' => 10 ) );
check( 'R1.1 /consultation/health-analysis: 404', 404 === $s );
list( $s ) = call( R_NOTE, 3, array( 'progress_id' => 10, 'note' => 'x', 'approve' => true ) );
check( 'R1.2 /consultation/health-note: 404', 404 === $s );
check( 'R1.3 no AI call', 0 === count( $GLOBALS['ai_calls'] ) );
$d = load( 3 );
check( 'R1.4 the load carries no health_analysis key', is_array( $d ) && isset( $d['consultation'] ) && ! array_key_exists( 'health_analysis', $d ) );
ha_switch( '0' );
boot();
check( 'R1.5 switch "0": still no route', ! isset( $GLOBALS['routes'][ R_WRITE ] ) && ! isset( $GLOBALS['routes'][ R_NOTE ] ) );
if ( ! $have ) done();
check( 'R1.6 the screen\'s settings carry no new key', array( 'api_base' => 'x' ) === HDLV2_Health_Analysis::config( array( 'api_base' => 'x' ) ) );
ha_switch( '1' );
check( 'R1.7 on: the settings say health_analysis, nothing else changes', array( 'api_base' => 'x', 'health_analysis' => 1 ) === HDLV2_Health_Analysis::config( array( 'api_base' => 'x' ) ) );
ha_switch( null );

echo "── R2 who may write ──\n";
ha_switch( '1' );
boot();
$cons = new HDLV2_Consultation();
check( 'R2.1 both routes: POST, signed-in practitioner check', 'POST' === ( $GLOBALS['routes'][ R_WRITE ]['methods'] ?? '' ) && 'POST' === ( $GLOBALS['routes'][ R_NOTE ]['methods'] ?? '' )
    && 'check_practitioner_auth' === ( $GLOBALS['routes'][ R_WRITE ]['permission_callback'][1] ?? '' ) && 'check_practitioner_auth' === ( $GLOBALS['routes'][ R_NOTE ]['permission_callback'][1] ?? '' ) );
list( $s ) = call( R_WRITE, 9, array( 'progress_id' => 10 ) );
check( 'R2.2 a signed-in client: 403', 403 === $s );
list( $s ) = call( R_WRITE, 0, array( 'progress_id' => 10 ) );
check( 'R2.3 nobody signed in: 401', 401 === $s );
list( $s ) = call( R_WRITE, 4, array( 'progress_id' => 10 ) );
check( 'R2.4 another practitioner: 404 (the form is not shown to exist)', 404 === $s );
list( $s ) = call( R_NOTE, 4, array( 'progress_id' => 10, 'note' => 'x', 'approve' => true ) );
check( 'R2.5 another practitioner cannot approve: 404', 404 === $s && null === row( $w )->health_note );
check( 'R2.6 none of these reached the AI', 0 === count( $GLOBALS['ai_calls'] ) );
list( $s ) = call( R_WRITE, 3, array() );
check( 'R2.7 no progress_id: 400', 400 === $s );

$GLOBALS['ai_replies'] = array( ANALYSIS, NOTE );
list( $s, $d ) = call( R_WRITE, 3, array( 'progress_id' => 10 ), array( 'Idempotency-Key' => 'ha-test-key-0001' ) );
$r = row( $w );
check( 'R2.8 the owner: 200, two AI calls', 200 === $s && 2 === count( $GLOBALS['ai_calls'] ) );
check( 'R2.9 stored: the analysis as written, its time, the note; no stamp', ANALYSIS === $r->health_analysis && '2026-11-24 09:14:05' === $r->health_analysis_at && NOTE === $r->health_note && null === $r->health_note_approved_at && null === $r->health_note_approved_by );
check( 'R2.10 answered: the analysis as safe HTML, its time, the note as plain text, no stamp',
    is_array( $d ) && HDLV2_Health_Analysis::to_html( ANALYSIS ) === ( $d['analysis_html'] ?? '' ) && '24 Nov 2026, 09:14' === ( $d['written'] ?? '' ) && NOTE === ( $d['note'] ?? '' ) && '' === ( $d['approved'] ?? 'x' ) && true === ( $d['note_written'] ?? null ) );
list( $s ) = call( R_WRITE, 3, array( 'progress_id' => 10 ), array( 'Idempotency-Key' => 'ha-test-key-0001' ) );
check( 'R2.11 the same press sent twice: replayed, charged once', 200 === $s && 2 === count( $GLOBALS['ai_calls'] ) );

echo "── R3 the stamp ──\n";
list( $s, $d ) = call( R_NOTE, 3, array( 'progress_id' => 10, 'note' => "Edited note.\n<b>Bold?</b>", 'approve' => true ) );
$r = row( $w );
check( 'R3.1 approve: 200, who and when stamped', 200 === $s && '2026-11-24 09:14:05' === $r->health_note_approved_at && 3 === (int) $r->health_note_approved_by );
check( 'R3.2 the text as it stood is saved, tags taken out', "Edited note.\nBold?" === $r->health_note && "Edited note.\nBold?" === ( $d['note'] ?? '' ) && '24 Nov 2026' === ( $d['approved'] ?? '' ) );
list( $s ) = call( R_NOTE, 3, array( 'progress_id' => 10, 'note' => "  \n ", 'approve' => true ) );
check( 'R3.3 an empty text with approve: 400, the stamp kept', 400 === $s && null !== row( $w )->health_note_approved_at && "Edited note.\nBold?" === row( $w )->health_note );
list( $s ) = call( R_NOTE, 3, array( 'progress_id' => 10, 'note' => str_repeat( 'x', 4001 ), 'approve' => true ) );
check( 'R3.4 more than 4,000 characters: 400, nothing saved', 400 === $s && "Edited note.\nBold?" === row( $w )->health_note );
list( $s ) = call( R_NOTE, 3, array( 'progress_id' => 10, 'note' => 'Changed text, not approved.', 'approve' => false ) );
$r = row( $w );
check( 'R3.5 saving without approving: saved, the stamp cleared', 200 === $s && 'Changed text, not approved.' === $r->health_note && null === $r->health_note_approved_at && null === $r->health_note_approved_by );
call( R_NOTE, 3, array( 'progress_id' => 10, 'note' => 'Approved again.', 'approve' => 'yes' ) );
check( 'R3.6 approve must be the boolean true ("yes" is not an approval)', null === row( $w )->health_note_approved_at );
call( R_NOTE, 3, array( 'progress_id' => 10, 'note' => 'Approved again.', 'approve' => true ) );
$GLOBALS['ai_replies'] = array( ANALYSIS . ' v2', NOTE . ' v2' );
call( R_WRITE, 3, array( 'progress_id' => 10 ), array( 'Idempotency-Key' => 'ha-test-key-0002' ) );
$r = row( $w );
check( 'R3.7 writing again replaces both texts and clears the stamp', NOTE . ' v2' === $r->health_note && null === $r->health_note_approved_at && null === $r->health_note_approved_by );
$GLOBALS['current_user'] = 1;
list( $s ) = call( R_NOTE, 1, array( 'progress_id' => 10, 'note' => 'Admin approved.', 'approve' => true ) );
check( 'R3.8 an admin may approve (stamped with the admin\'s id)', 200 === $s && 1 === (int) row( $w )->health_note_approved_by );

echo "── R4 failures ──\n";
$before = (array) row( $w );
$GLOBALS['ai_replies'] = array( array( 'code' => 500 ) );
list( $s, $d ) = call( R_WRITE, 3, array( 'progress_id' => 10 ), array( 'Idempotency-Key' => 'ha-test-key-0003' ) );
check( 'R4.1 prompt 1 fails: an error, every column as before (the stamp too)', $s >= 500 && $before === (array) row( $w ) );
$GLOBALS['ai_replies'] = array( ANALYSIS . ' v3', array( 'code' => 500 ) );
list( $s, $d ) = call( R_WRITE, 3, array( 'progress_id' => 10 ), array( 'Idempotency-Key' => 'ha-test-key-0004' ) );
$r = row( $w );
check( 'R4.2 prompt 2 fails: 200, the new analysis stays', 200 === $s && ANALYSIS . ' v3' === $r->health_analysis );
check( 'R4.3 the old note stays, unapproved; the answer says it was not written', 'Admin approved.' === $r->health_note && null === $r->health_note_approved_at && false === ( $d['note_written'] ?? null ) && 'Admin approved.' === ( $d['note'] ?? '' ) && '' === ( $d['approved'] ?? 'x' ) );
$logged = (string) file_get_contents( $log );
check( 'R4.4 the failures were logged, with no client words in the log', '' !== $logged && false === strpos( $logged, 'Bloating' ) && false === strpos( $logged, 'Admin approved' ) && false === strpos( $logged, 'bloating' ) );

echo "── R5 the load ──\n";
$d = load( 3 );
$b = $d['health_analysis'] ?? null;
check( 'R5.1 switch on: one block on the load', is_array( $b ) );
check( 'R5.2 the block: the analysis as safe HTML, its time, the note, the stamp', is_array( $b ) && HDLV2_Health_Analysis::to_html( ANALYSIS . ' v3' ) === ( $b['analysis_html'] ?? '' ) && '24 Nov 2026, 09:14' === ( $b['written'] ?? '' ) && 'Admin approved.' === ( $b['note'] ?? '' ) && '' === ( $b['approved'] ?? 'x' ) );
check( 'R5.3 the stored raw analysis is not sent (only its safe HTML)', is_array( $b ) && ! array_key_exists( 'analysis', $b ) && false === strpos( json_encode( $d ), '**Late eating' ) );
$w2 = ha_world(); $w2->t['form_progress'][0]['pre_consult_summary'] = json_encode( array( 'health_summary' => 'cached' ) );
$b = load( 3 )['health_analysis'] ?? null;
check( 'R5.4 nothing written yet: an empty block', is_array( $b ) && '' === ( $b['analysis_html'] ?? 'x' ) && '' === ( $b['note'] ?? 'x' ) && '' === ( $b['written'] ?? 'x' ) );

ha_switch( null );
@unlink( $log );
done();
