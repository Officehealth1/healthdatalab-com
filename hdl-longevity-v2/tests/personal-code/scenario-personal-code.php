<?php
/**
 * 0.48.18 — each Stage 1 PDF carries its own one-use Full Report code, asked of
 * Altituding (full-report-code) when the drain sends the PDF. Any failure: the
 * shared code from the offer option, and the PDF still goes out.
 *
 * Run:  php scenario-personal-code.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/../full-report/stubs.php';
load_sources();

$log = tempnam( sys_get_temp_dir(), 'hdlv2-log' );
ini_set( 'error_log', $log );
$GLOBALS['options'] = array( 'hdlv2_db_version' => '3.30' );
const CODE_URL = 'https://alt.example.test/.netlify/functions/full-report-code';

/** One lead through the real capture + drain; returns [ make payload | null, the code call | null ]. */
function run_lead( $email ) {
    global $wpdb;
    $http = $GLOBALS['http'];
    $wpdb = fresh_wpdb( 'open' );
    $GLOBALS['http'] = $http;
    post_lead( lead_params( $email ) );
    drain_last_lead( $wpdb );
    $make = $code = null;
    foreach ( $GLOBALS['captured_posts'] as $c ) {
        if ( false !== strpos( $c['url'], 'hook.example.test/stage1' ) ) $make = json_decode( $c['args']['body'], true );
        if ( CODE_URL === $c['url'] ) $code = $c;
    }
    return array( $make, $code, $wpdb->insert_id );
}
/** 0.48.22 (D3) — the fallback link opens the gate with the shared code; the lead's email rides in the fragment (D7). */
function shared_pair( $make, $email ) {
    return is_array( $make ) && 'SHAREDCODE' === $make['offer_code']
        && 'https://alt.example.test/full-report?code=SHAREDCODE#email=' . rawurlencode( $email ) === $make['offer_url'];
}

// The harness practitioner (206) sees the offer; the shared code + plain URL from the option.
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'practitioner_id' => 206, 'code' => 'SHAREDCODE', 'url' => 'https://alt.example.test/full-report' );

echo "── C. before the constants exist (LIVE today) ──\n";
list( $make, $call ) = run_lead( 'a@example.test' );
check( 'C.5 constants absent: no outbound call, the shared code, the PDF fires', null === $call && shared_pair( $make, 'a@example.test' ) );

define( 'HDLV2_FULL_CODE_URL', CODE_URL );
$stub_access = 'stub-full-code-access';
define( 'HDLV2_FULL_CODE_KEY', $stub_access );

echo "── C. the personal code ──\n";
$GLOBALS['http'][ CODE_URL ] = array( 'response' => array( 'code' => 200 ), 'body' => '{"code":"AB12-CD","expires_at":1790000000}' );
list( $make, $call, $lead_id ) = run_lead( 'b@example.test' );
check( 'C.1 offer_code is the personal code', is_array( $make ) && 'AB12-CD' === $make['offer_code'] );
check( 'C.1 offer_url carries it as ?code= and the email for Stripe', is_array( $make ) && 'https://alt.example.test/full-report?code=AB12-CD#email=b%40example.test' === $make['offer_url'] );
check( 'C.1 still 33 keys, all nine offer keys strings', is_array( $make ) && 33 === count( $make )
    && 9 === count( array_filter( array_intersect_key( $make, array_flip( array_keys( HDLV2_Offer::payload_keys() ) ) ), 'is_string' ) ) );
check( 'C.2 one POST to HDLV2_FULL_CODE_URL', null !== $call );
check( 'C.2 the key rides in X-HDL-Full-Code-Key', $stub_access === ( $call['args']['headers']['X-HDL-Full-Code-Key'] ?? null ) );
check( 'C.2 body is { ref: lead-<id> }, never the email', array( 'ref' => 'lead-' . $lead_id ) === json_decode( $call['args']['body'] ?? '', true ) && false === strpos( $call['args']['body'], 'example.test' ) );
check( 'C.2 timeout at most 3 s and blocking', ( $call['args']['timeout'] ?? 99 ) <= 3 && false !== ( $call['args']['blocking'] ?? true ) );

// The Confirm / invite path: dispatch with an invite id and no lead ref.
$wpdb = fresh_wpdb( 'open' );
$GLOBALS['http'][ CODE_URL ] = array( 'response' => array( 'code' => 200 ), 'body' => '{"code":"INV1CODE"}' );
$m = new ReflectionMethod( 'HDLV2_Widget_Config', 'dispatch_post_signup_artifacts' );
$m->setAccessible( true );
$m->invoke( null, array( 'practitioner_id' => 206, 'visitor_name' => 'Ivy', 'visitor_email' => 'ivy@example.test', 'rate' => 1.02,
    'stage1_data' => lead_params( 'ivy@example.test' ), 'form_token' => str_repeat( 'c', 64 ), 'config' => $wpdb->config,
    'invite_id' => 77, 'send_practitioner_notify' => false, 'send_make_pdf' => true ) );
$inv = null;
foreach ( $GLOBALS['captured_posts'] as $c ) { if ( CODE_URL === $c['url'] ) $inv = $c; }
check( 'C.2 the invite path sends ref invite-<id>', $inv && array( 'ref' => 'invite-77' ) === json_decode( $inv['args']['body'], true ) );
$inv_make = null;
foreach ( $GLOBALS['captured_posts'] as $c ) { if ( false !== strpos( $c['url'], 'hook.example.test/stage1' ) ) $inv_make = json_decode( $c['args']['body'], true ); }
check( 'C.2 the invite path link carries the invite\'s email', is_array( $inv_make ) && 'https://alt.example.test/full-report?code=INV1CODE#email=ivy%40example.test' === $inv_make['offer_url'] );

echo "── C. the email in the link ──\n";
$GLOBALS['http'][ CODE_URL ] = array( 'response' => array( 'code' => 200 ), 'body' => '{"code":"PLUS1"}' );
list( $make ) = run_lead( 'p+q@example.test' );
check( 'C.6 a + in the email encodes as %2B', is_array( $make ) && 'https://alt.example.test/full-report?code=PLUS1#email=p%2Bq%40example.test' === $make['offer_url'] );
$no_email = HDLV2_Offer::personal( 'lead-1', '' );
$bad_email = HDLV2_Offer::personal( 'lead-1', 'not an email' );
check( 'C.7 an empty email: no email key in the link', 'https://alt.example.test/full-report?code=PLUS1' === $no_email['offer_url'] );
check( 'C.7 an invalid email: no email key in the link', 'https://alt.example.test/full-report?code=PLUS1' === $bad_email['offer_url'] );

echo "── C. failures fall back to the shared code ──\n";
$bad = array(
    '503'         => array( 'response' => array( 'code' => 503 ), 'body' => '{"error":"not_configured"}' ),
    '500'         => array( 'response' => array( 'code' => 500 ), 'body' => '' ),
    'timeout'     => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
    'not json'    => array( 'response' => array( 'code' => 200 ), 'body' => '<html>' ),
    'odd code'    => array( 'response' => array( 'code' => 200 ), 'body' => '{"code":"<b>x</b>"}' ),
    'short code'  => array( 'response' => array( 'code' => 200 ), 'body' => '{"code":"AB"}' ),
    'no code'     => array( 'response' => array( 'code' => 200 ), 'body' => '{}' ),
);
foreach ( $bad as $label => $answer ) {
    file_put_contents( $log, '' );
    $GLOBALS['http'][ CODE_URL ] = $answer;
    list( $make, $call ) = run_lead( 'f@example.test' );
    $lines = array_filter( explode( "\n", file_get_contents( $log ) ), function ( $l ) { return false !== strpos( $l, '[HDLV2 offer]' ); } );
    check( "C.3 $label: shared code, PDF fires, one log line", null !== $call && shared_pair( $make, 'f@example.test' ) && 1 === count( $lines )
        && false !== strpos( implode( '', $lines ), 'shared code used' ) );
}

echo "── C. no offer, no call ──\n";
$GLOBALS['http'][ CODE_URL ] = array( 'response' => array( 'code' => 200 ), 'body' => '{"code":"NEVER1"}' );
$GLOBALS['options']['hdlv2_stage1_offer']['practitioner_id'] = 122;
list( $make, $call ) = run_lead( 'g@example.test' );
check( 'C.4 another practitioner\'s lead: no outbound call, offer_show "0"', null === $call && is_array( $make ) && '0' === $make['offer_show'] );
$GLOBALS['options']['hdlv2_stage1_offer']['practitioner_id'] = 206;
$m->invoke( null, array( 'practitioner_id' => 206, 'visitor_name' => 'Ivy', 'visitor_email' => 'ivy@example.test', 'rate' => 1.02,
    'stage1_data' => lead_params( 'ivy@example.test' ), 'form_token' => str_repeat( 'c', 64 ), 'config' => fresh_wpdb( 'open' )->config,
    'invite_id' => 78, 'send_practitioner_notify' => false, 'send_make_pdf' => true, 'offer_show' => false ) );
$none = true;
foreach ( $GLOBALS['captured_posts'] as $c ) { if ( CODE_URL === $c['url'] ) $none = false; }
check( 'C.4 offer_show false (a Full Report buyer): no outbound call', $none );

echo "── C. the key stays out of logs and literals ──\n";
$src = file_get_contents( __DIR__ . '/../../includes/sprint-1/class-hdlv2-offer.php' );
check( 'C.6 the key never reaches a log line', false === strpos( file_get_contents( $log ), $stub_access ) );
check( 'C.6 the header value comes from a local variable', 1 === preg_match( "/'X-HDL-Full-Code-Key'\s*=>\s*\\\$key\b/", $src )
    && 0 === preg_match( "/'X-HDL-Full-Code-Key'\s*=>\s*HDLV2_/", $src ) );
@unlink( $log );
done();
