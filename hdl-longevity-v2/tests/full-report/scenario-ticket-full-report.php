<?php
/**
 * H6 / Q3 — POST /hdl/v1/stage1-ticket with product "full_report", against
 * the REAL route, mint(), confirm_lead_row() and complete_signup().
 *
 * Run:  php scenario-ticket-full-report.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/stubs.php';
define( 'HDL_STAGE1_TICKET_KEY', 'k-test-key' );
load_sources();

/** The fake wpdb plus one widget_leads row served for (practitioner, email). */
class LeadWpdb extends FakeWpdb {
    public $lead = null;
    public function get_row( $sql ) {
        if ( false !== strpos( $sql, 'hdlv2_widget_leads' ) && false !== strpos( $sql, 'visitor_email' ) && false === strpos( $sql, 'JOIN' ) ) {
            $this->queries[] = $sql;
            return $this->lead;
        }
        return parent::get_row( $sql );
    }
}
function lead_row( $status, $over = array() ) {
    return (object) array_merge( array(
        'id' => 640, 'practitioner_user_id' => 122, 'visitor_name' => 'Fr One', 'visitor_email' => 'fr1@example.test',
        'visitor_age' => 52, 'rate_of_ageing' => 1.04, 'stage1_data' => '{"q1_age":52,"q9":"b"}', 'status' => $status,
        'invite_id' => null, 'visitor_country' => '', 'visitor_region' => '',
    ), $over );
}
function setup( $lead = null, $mode = 'open' ) {
    global $wpdb;
    fresh_wpdb( $mode );
    $w = new LeadWpdb();
    $w->config = $wpdb->config;
    $w->config->practitioner_user_id = 122;
    $w->lead = $lead;
    $wpdb = $w;
    $GLOBALS['options'] = array( 'hdlv2_db_version' => '3.29' );
    return $wpdb;
}
function mint_req( $over = array() ) {
    $body = array_merge( array( 'practitioner_id' => 122, 'email' => 'fr1@example.test', 'name' => 'Fr One', 'external_ref' => 'cs_test_fr1' ), $over );
    $body = array_filter( $body, function ( $v ) { return null !== $v; } );
    return HDL_Stage1_Ticket::handle( new FakeRequest( $body, array( 'X-HDL-Stage1-Ticket-Key' => 'k-test-key' ) ) );
}
function ticket_rows( $w ) { return $w->inserts_into( 'hdlv2_widget_invites' ); }
function signups( $w )     { return count( $w->inserts_into( 'hdlv2_form_progress' ) ); }
function lead_updates( $w ) {
    return array_values( array_filter( $w->updates, function ( $u ) { return substr( $u['table'], -18 ) === 'hdlv2_widget_leads' && isset( $u['data']['status'] ); } ) );
}

echo "── T1. product absent / stage1 / bad ──\n";
$w = setup( null, 'paid' );
$r = mint_req();
check( 'T1.1 product absent → exactly { token, expires_at, idempotent } (as before)', is_array( $r ) && array_keys( $r ) === array( 'token', 'expires_at', 'idempotent' ) && 'paid_stage1' === ticket_rows( $w )[0]['data']['source'] );
$w = setup( null, 'paid' );
$r = mint_req( array( 'product' => 'stage1' ) );
check( 'T1.2 product "stage1" → same', is_array( $r ) && array_keys( $r ) === array( 'token', 'expires_at', 'idempotent' ) );
$w = setup( null, 'paid' );
$r = mint_req( array( 'product' => 'other' ) );
check( 'T1.3 product "other" → 400 invalid_body naming product', 400 === status_of( $r ) && 'invalid_body' === code_of( $r ) && in_array( 'product', $r->data['invalid_fields'] ?? array(), true ) && ! ticket_rows( $w ) );
$w = setup( null, 'open' );
$r = mint_req();
check( 'T1.4 Stage 1 ticket still refused in open mode (not_paid_mode)', 409 === status_of( $r ) && 'not_paid_mode' === code_of( $r ) );
$w = setup( null, 'open' );
$GLOBALS['options']['hdlv2_db_version'] = '3.28';
$r = mint_req( array( 'product' => 'full_report' ) );
check( 'T1.5 full_report before Phase AJ → 503 not_ready', 503 === status_of( $r ) && 'not_ready' === code_of( $r ) );

echo "── T2. full_report, open-mode practitioner, no lead ──\n";
$w = setup( null, 'open' );
$r = mint_req( array( 'product' => 'full_report' ) );
$row = ticket_rows( $w )[0]['data'] ?? array();
check( 'T2.1 200, no not_paid_mode', is_array( $r ) && ! empty( $r['token'] ) );
check( 'T2.2 row source full_report', 'full_report' === ( $row['source'] ?? '' ) && 'cs_test_fr1' === ( $row['external_ref'] ?? '' ) );
check( 'T2.3 expires in 90 days', abs( strtotime( $row['expires_at'] . ' UTC' ) - ( time() + 90 * DAY_IN_SECONDS ) ) < 60 );
check( 'T2.4 answer carries the contract fields', is_array( $r ) && array_keys( $r ) === array( 'token', 'expires_at', 'idempotent', 'open_url', 'stage1_found', 'auto_confirmed' ) );
check( 'T2.5 open_url opens the widget page with the ticket', is_array( $r ) && 'https://stby.example.test/rate-of-ageing-widget/?invite=' . $r['token'] === $r['open_url'] );
check( 'T2.6 no lead → stage1_found false, auto_confirmed false, nothing else written', false === $r['stage1_found'] && false === $r['auto_confirmed'] && 0 === signups( $w ) && ! lead_updates( $w ) );
check( 'T2.7 no email, no webhook', ! $GLOBALS['mails'] && ! $GLOBALS['captured_posts'] );

echo "── T3. a pending lead is confirmed by the purchase ──\n";
$w = setup( lead_row( 'pending' ) );
$r = mint_req( array( 'product' => 'full_report' ) );
$tid = $w->insert_id ? ( function ( $w ) { foreach ( $w->inserts as $i => $x ) { if ( substr( $x['table'], -20 ) === 'hdlv2_widget_invites' ) return $i; } } )( $w ) : null;
$lu = lead_updates( $w );
check( 'T3.1 complete_signup ran once (one client record)', 1 === signups( $w ) );
check( 'T3.2 lead → confirmed, confirmed_at, invite_id = the ticket', 1 === count( $lu ) && 'confirmed' === $lu[0]['data']['status'] && ! empty( $lu[0]['data']['confirmed_at'] ) && (int) ( $lu[0]['data']['invite_id'] ?? 0 ) > 0 && 640 === (int) $lu[0]['where']['id'] );
check( 'T3.3 no email, no webhook (no Stage 1 PDF again, no New Lead)', ! $GLOBALS['mails'] && ! $GLOBALS['captured_posts'] );
check( 'T3.4 stage1_found true, auto_confirmed true', is_array( $r ) && true === $r['stage1_found'] && true === $r['auto_confirmed'] );
$q = implode( "\n", $w->queries );
check( 'T3.5 one transaction around the client record and the lead update', 1 === substr_count( $q, 'START TRANSACTION' ) && 1 === substr_count( $q, 'COMMIT' ) && false === strpos( $q, 'ROLLBACK' ) );

echo "── T4. a confirmed lead: nothing more ──\n";
$w = setup( lead_row( 'confirmed' ) );
$r = mint_req( array( 'product' => 'full_report' ) );
check( 'T4.1 nothing written beyond the ticket', 1 === count( $w->inserts ) && 0 === signups( $w ) && ! lead_updates( $w ) );
check( 'T4.2 stage1_found true, auto_confirmed false', is_array( $r ) && true === $r['stage1_found'] && false === $r['auto_confirmed'] );

echo "── T5. a rejected lead counts as not found ──\n";
$w = setup( lead_row( 'rejected' ) );
$r = mint_req( array( 'product' => 'full_report' ) );
check( 'T5.1 stage1_found false, lead untouched', is_array( $r ) && false === $r['stage1_found'] && 0 === signups( $w ) && ! lead_updates( $w ) );

echo "── T6. switch off: the lead waits for Confirm ──\n";
$w = setup( lead_row( 'pending' ) );
$GLOBALS['options']['hdlv2_full_report_auto_confirm'] = 0;
$r = mint_req( array( 'product' => 'full_report' ) );
check( 'T6.1 ticket minted, lead untouched', 1 === count( ticket_rows( $w ) ) && 0 === signups( $w ) && ! lead_updates( $w ) );
check( 'T6.2 open_url null, auto_confirmed false, stage1_found true', is_array( $r ) && null === $r['open_url'] && false === $r['auto_confirmed'] && true === $r['stage1_found'] );

echo "── T7. the same external_ref twice ──\n";
$w = setup( lead_row( 'pending' ) );
$r1 = mint_req( array( 'product' => 'full_report' ) );
$w->lead = lead_row( 'confirmed', array( 'invite_id' => $w->insert_id ) ); // as the first call left it
$n_ins = count( $w->inserts );
$n_upd = count( $w->updates );
$r2 = mint_req( array( 'product' => 'full_report' ) );
check( 'T7.1 same token, idempotent true', is_array( $r2 ) && $r1['token'] === $r2['token'] && true === $r2['idempotent'] );
check( 'T7.2 nothing new written, complete_signup not called again', count( $w->inserts ) === $n_ins && count( $w->updates ) === $n_upd && 1 === signups( $w ) );
$w2 = setup( null, 'paid' );
$w2->tickets['cs_test_fr1'] = (object) array( 'id' => 9, 'token' => str_repeat( 'cd', 32 ), 'expires_at' => '2030-01-01 00:00:00', 'practitioner_id' => 122, 'source' => 'paid_stage1', 'client_email' => 'x@example.test', 'client_name' => 'X' );
$r = mint_req( array( 'product' => 'full_report' ) );
check( 'T7.3 a Stage 1 ticket\'s ref reused for a Full Report → 409 external_ref_conflict', 409 === status_of( $r ) && 'external_ref_conflict' === code_of( $r ) );

echo "── T8. the answers endpoint with a full_report ticket (no Stage 1 yet) ──\n";
$w = setup( null, 'open' );
$w->invite = invite_row( array( 'id' => 88, 'practitioner_id' => 122, 'source' => 'full_report', 'client_email' => 'fr1@example.test' ) );
$p = lead_params( 'fr1@example.test', TOKEN );
$p['practitioner_id'] = 122;
$r = post_lead( $p );
$make = null;
foreach ( $GLOBALS['captured_posts'] as $c ) {
    if ( false !== strpos( $c['url'], 'hook.example.test/stage1' ) ) $make = json_decode( $c['args']['body'], true );
}
check( 'T8.1 fast path: form_token in the answer', is_array( $r ) && ! empty( $r['form_token'] ) );
check( 'T8.2 Stage 1 PDF fires once, without the sales page (offer_show "0")', is_array( $make ) && '0' === ( $make['offer_show'] ?? null ) );
check( 'T8.3 the ticket is marked completed', count( $w->sql_matching( 'hdlv2_widget_invites' ) ) > 0 && ( 'completed' === $w->invite->status || array_filter( $w->updates, function ( $u ) { return ( $u['data']['status'] ?? '' ) === 'completed'; } ) ) );
$w = setup( null, 'open' );
$w->invite = invite_row( array( 'id' => 88, 'practitioner_id' => 206, 'source' => 'full_report' ) );
$p = lead_params( 'fr1@example.test', TOKEN );
$p['practitioner_id'] = 122;
$r = post_lead( $p );
check( 'T8.4 another practitioner\'s ticket → no fast path (public path, no client record)', is_array( $r ) && empty( $r['form_token'] ) && 0 === signups( $w ) );

echo "── T9. auto-login for a full_report ticket (real init handler) ──\n";
$php = escapeshellarg( PHP_BINARY );
$run = function ( $case ) use ( $php ) {
    $o = array();
    exec( $php . ' ' . escapeshellarg( dirname( __DIR__ ) . '/token-expiry/scenario-token-login.php' ) . ' ' . $case . ' 2>/dev/null', $o, $code );
    return implode( "\n", $o );
};
$o = $run( 'invite-full-report' );
check( 'T9.1 logs in and, with Stage 1 done, goes to /assessment/?token=', false !== strpos( $o, 'AUTH_COOKIE_SET' ) && false !== strpos( $o, 'REDIRECT:https://stby.test/assessment/?token=fptoken' ) );
$o = $run( 'invite-full-report-new' );
check( 'T9.2 without Stage 1: logs in, page renders (the nine questions)', false !== strpos( $o, 'AUTH_COOKIE_SET' ) && false !== strpos( $o, 'HANDLER_RETURNED' ) && false === strpos( $o, 'REDIRECT' ) );
$o = $run( 'invite-full-report-prac' );
check( 'T9.3 never signs in a practitioner\'s account', false === strpos( $o, 'AUTH_COOKIE_SET' ) && false !== strpos( $o, 'Please sign in' ) );
$o = $run( 'invite-full-report-admin' );
check( 'T9.4 never signs in an admin account', false === strpos( $o, 'AUTH_COOKIE_SET' ) && false !== strpos( $o, 'Please sign in' ) );

echo "── T10. Sent Invites lists keep full_report rows ──\n";
$src = file_get_contents( __DIR__ . '/../../includes/sprint-1/class-hdlv2-widget-config.php' );
check( 'T10.1 the lists exclude only paid_stage1', 3 === substr_count( $src, "source <> 'paid_stage1'" ) && false === strpos( $src, "source <> 'full_report'" ) );
done();
