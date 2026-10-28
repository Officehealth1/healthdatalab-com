<?php
/**
 * 30-day challenge — POST /hdl/v1/stage1-ticket with product "challenge",
 * and Stage 1 answers sent with a challenge link. Against the REAL route,
 * mint() and rest_capture_lead(), on the full-report harness (its stubs and
 * fake $wpdb).
 *
 * Run:  php scenario-ticket.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require dirname( __DIR__ ) . '/full-report/stubs.php';
define( 'HDL_STAGE1_TICKET_KEY', 'k-test-key' );
load_sources();

function setup( $mode = 'open', $db = '3.31' ) {
    global $wpdb;
    fresh_wpdb( $mode );
    $wpdb->config->practitioner_user_id = 122;
    $GLOBALS['options'] = array( 'hdlv2_db_version' => $db );
    return $wpdb;
}
function mint_req( $over = array() ) {
    $body = array_merge( array( 'practitioner_id' => 122, 'email' => 'ch1@example.test', 'name' => 'Ch One', 'external_ref' => 'cs_test_ch1', 'product' => 'challenge' ), $over );
    $body = array_filter( $body, function ( $v ) { return null !== $v; } );
    return HDL_Stage1_Ticket::handle( new FakeRequest( $body, array( 'X-HDL-Stage1-Ticket-Key' => 'k-test-key' ) ) );
}
function ticket_rows( $w ) { return $w->inserts_into( 'hdlv2_widget_invites' ); }

echo "── C1. product challenge ──\n";
$w = setup();
$r = mint_req();
$row = ticket_rows( $w )[0]['data'] ?? array();
check( 'C1.1 200 with exactly { token, expires_at, idempotent, open_url }', is_array( $r ) && array_keys( $r ) === array( 'token', 'expires_at', 'idempotent', 'open_url' ) && false === $r['idempotent'] );
check( 'C1.2 open_url opens the widget page with the ticket', is_array( $r ) && 'https://stby.example.test/rate-of-ageing-widget/?invite=' . $r['token'] === $r['open_url'] );
check( 'C1.3 the row: source challenge, the ref, 90 days, pending', 'challenge' === ( $row['source'] ?? '' ) && 'cs_test_ch1' === ( $row['external_ref'] ?? '' ) && 'pending' === ( $row['status'] ?? '' )
    && abs( strtotime( $row['expires_at'] . ' UTC' ) - ( time() + 90 * DAY_IN_SECONDS ) ) < 60 );
check( 'C1.4 no lead confirmed, no client record, no email, no webhook', 1 === count( $w->inserts ) && ! $w->updates && ! $GLOBALS['mails'] && ! $GLOBALS['captured_posts'] );
$r2 = mint_req();
check( 'C1.5 the same external_ref again → same token, idempotent true', is_array( $r2 ) && $r['token'] === $r2['token'] && true === $r2['idempotent'] && 1 === count( ticket_rows( $w ) ) );
$w = setup( 'paid' );
$r = mint_req();
check( 'C1.6 a paid-mode practitioner can sell a challenge too', is_array( $r ) && ! empty( $r['token'] ) );
$w = setup( 'open', '3.30' );
$r = mint_req();
check( 'C1.7 before Phase AL (DB < 3.31) → 503 not_ready, nothing written', 503 === status_of( $r ) && 'not_ready' === code_of( $r ) && ! $w->inserts );
$w = setup();
$r = mint_req( array( 'product' => 'nonsense' ) );
check( 'C1.8 product nonsense → 400 invalid_body naming product', 400 === status_of( $r ) && in_array( 'product', $r->data['invalid_fields'] ?? array(), true ) );
$w = setup();
$w->tickets['cs_test_ch1'] = (object) array( 'id' => 9, 'token' => str_repeat( 'cd', 32 ), 'expires_at' => '2030-01-01 00:00:00', 'practitioner_id' => 122, 'source' => 'full_report', 'client_email' => 'ch1@example.test', 'client_name' => 'Ch One' );
$r = mint_req();
check( 'C1.9 a Full Report ref reused for a challenge → 409 external_ref_conflict', 409 === status_of( $r ) && 'external_ref_conflict' === code_of( $r ) );

echo "── C2. stage1 and full_report answers unchanged ──\n";
$w = setup( 'paid' );
$r = mint_req( array( 'product' => null ) );
check( 'C2.1 product absent → { token, expires_at, idempotent }, source paid_stage1', is_array( $r ) && array_keys( $r ) === array( 'token', 'expires_at', 'idempotent' ) && 'paid_stage1' === ticket_rows( $w )[0]['data']['source'] );
$w = setup();
$r = mint_req( array( 'product' => 'full_report' ) );
check( 'C2.2 full_report → its six keys, source full_report', is_array( $r ) && array_keys( $r ) === array( 'token', 'expires_at', 'idempotent', 'open_url', 'stage1_found', 'auto_confirmed' ) && 'full_report' === ticket_rows( $w )[0]['data']['source'] );

echo "── C3. Stage 1 answers sent with a challenge link ──\n";
foreach ( array( 122 => 'its own practitioner', 206 => 'another practitioner' ) as $prac => $who ) {
    $w = setup();
    $w->invite = invite_row( array( 'id' => 91, 'practitioner_id' => $prac, 'source' => 'challenge', 'client_email' => 'ch1@example.test' ) );
    $p = lead_params( 'ch1@example.test', TOKEN );
    $p['practitioner_id'] = 122;
    $r = post_lead( $p );
    check( "C3.1 ($who) public path: a pending lead, no form_token, no client record", is_array( $r ) && empty( $r['form_token'] ) && 1 === count( $w->inserts_into( 'hdlv2_widget_leads' ) ) && 0 === count( $w->inserts_into( 'hdlv2_form_progress' ) ) );
    check( "C3.2 ($who) the challenge link is not used up", 'pending' === $w->invite->status && ! array_filter( $w->updates, function ( $u ) { return 'completed' === ( $u['data']['status'] ?? '' ); } ) );
}

done();
