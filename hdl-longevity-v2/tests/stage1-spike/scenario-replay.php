<?php
/**
 * The holding list replay (v0.48.16): WordPress pulls waiting contacts from the
 * Brevo list, re-posts each through the REAL lead handler in-process, then
 * takes it off the list. Real HDLV2_Stage1_Waiting + HDLV2_Widget_Config.
 *
 * Run:  php scenario-replay.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
$GLOBALS['logfile'] = tempnam( sys_get_temp_dir(), 'replay' );
ini_set( 'error_log', $GLOBALS['logfile'] );
require __DIR__ . '/stubs.php';
load_sources();
if ( ! class_exists( 'HDLV2_Stage1_Waiting' ) ) { check( 'HDLV2_Stage1_Waiting exists', false ); done(); }
$GLOBALS['options'] = array( 'hdlv2_db_version' => '3.30' );
HDLV2_Stage1_Drain::$load_source = function () { return array( 0.3, 0.3, 0.3 ); };

function payload( $email, $over = array() ) {
    $p = lead_params( $email );
    unset( $p['phone'] );
    return array_merge( $p, $over );
}
function contact( $email, $payload, $held_at = null ) {
    return array( 'email' => $email, 'attributes' => array(
        'HDL_STAGE1_PAYLOAD' => is_string( $payload ) ? $payload : json_encode( $payload ),
        'HDL_STAGE1_HELD_AT' => $held_at ?: gmdate( 'Y-m-d' ),
    ) );
}
/** Brevo fake: serves the list, records removes + blanks; $fail = list|remove → status. */
function brevo( $contacts, $fail = array() ) {
    $GLOBALS['http'] = function ( $m, $url, $a ) use ( $contacts, $fail ) {
        $h = $a['headers'] ?? array();
        if ( ( $h['api-key'] ?? '' ) !== 'test-brevo-key' ) return array( 'response' => array( 'code' => 401 ), 'body' => '{}' );
        if ( 'GET' === $m && preg_match( '#/v3/contacts/lists/77/contacts\?limit=50#', $url ) ) {
            if ( isset( $fail['list'] ) ) return array( 'response' => array( 'code' => $fail['list'] ), 'body' => '{}', 'headers' => array( 'x-sib-ratelimit-reset' => '3' ) );
            return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'contacts' => $contacts, 'count' => count( $contacts ) ) ) );
        }
        if ( 'POST' === $m && false !== strpos( $url, '/v3/contacts/lists/77/contacts/remove' ) ) {
            return array( 'response' => array( 'code' => $fail['remove'] ?? 201 ), 'body' => '{}' );
        }
        if ( 'PUT' === $m && false !== strpos( $url, '/v3/contacts/' ) ) return array( 'response' => array( 'code' => 204 ), 'body' => '' );
        return array( 'response' => array( 'code' => 404 ), 'body' => '' );
    };
}
function removed() {
    $out = array();
    foreach ( http_calls( 'POST' ) as $c ) if ( false !== strpos( $c['url'], '/contacts/remove' ) ) $out = array_merge( $out, json_decode( $c['args']['body'], true )['emails'] );
    return $out;
}
function blanked() {
    $out = array();
    foreach ( http_calls( 'PUT' ) as $c ) {
        $b = json_decode( $c['args']['body'], true );
        if ( '' === ( $b['attributes']['HDL_STAGE1_PAYLOAD'] ?? null ) ) $out[] = urldecode( substr( $c['url'], strrpos( $c['url'], '/' ) + 1 ) );
    }
    return $out;
}
function logged( $needle ) { return substr_count( (string) file_get_contents( $GLOBALS['logfile'] ), $needle ); }
function fresh() { file_put_contents( $GLOBALS['logfile'], '' ); return fresh_wpdb( 'open' ); }

echo "── R0. not configured ──\n";
$w = fresh();
brevo( array( contact( 'a@example.test', payload( 'a@example.test' ) ) ) );
HDLV2_Stage1_Waiting::run_replay();
check( 'R0.1 no constants → no call at all', false === HDLV2_Stage1_Waiting::configured() && 0 === count( http_calls() ) );

define( 'HDLV2_BREVO_API_KEY', 'test-brevo-key' );
define( 'HDLV2_BREVO_WAITING_LIST_ID', '77' );

echo "── R1. busy → no call ──\n";
$w = fresh();
HDLV2_Stage1_Drain::$load_source = function () { return array( 9, 9, 9 ); };
HDLV2_Stage1_Waiting::run_replay();
check( 'R1.1 configured but busy → no call', true === HDLV2_Stage1_Waiting::configured() && 0 === count( http_calls() ) );
HDLV2_Stage1_Drain::$load_source = function () { return array( 0.3, 0.3, 0.3 ); };

echo "── R2. two waiting contacts ──\n";
$w = fresh();
brevo( array( contact( 'a@example.test', payload( 'a@example.test' ) ), contact( 'b@example.test', payload( 'b@example.test', array( 'invite_token' => TOKEN ) ) ) ) );
HDLV2_Stage1_Waiting::run_replay();
$leads = $w->inserts_into( 'hdlv2_widget_leads' );
check( 'R2.1 the list is read with the key header, 50 at a time, oldest first', 1 === count( array_filter( http_calls( 'GET' ), function ( $c ) { return false !== strpos( $c['url'], 'limit=50' ) && false !== strpos( $c['url'], 'sort=asc' ); } ) ) );
check( 'R2.2 two lead rows written as a direct POST writes them', 2 === count( $leads ) && 'a@example.test' === $leads[0]['data']['visitor_email'] && 'pending' === $leads[0]['data']['status'] && 'b@example.test' === $leads[1]['data']['visitor_email'] );
check( 'R2.3 the invite_token in a stored payload is ignored (public path, no fast path)', 0 === count( $w->inserts_into( 'hdlv2_form_progress' ) ) );
check( 'R2.4 both taken off the list, one email per call', array( 'a@example.test', 'b@example.test' ) === removed() && 2 === count( array_filter( http_calls( 'POST' ), function ( $c ) { return false !== strpos( $c['url'], '/contacts/remove' ); } ) ) );
check( 'R2.5 both payload attributes blanked', array( 'a@example.test', 'b@example.test' ) === blanked() );
check( 'R2.6 replayed count kept for the alert', 2 === (int) get_transient( 'hdlv2_stage1_replayed_' . gmdate( 'YmdH' ) ) );
check( 'R2.7 nothing sent to Make in the replay (the drain does that)', 0 === count( array_filter( http_calls( 'POST' ), function ( $c ) { return false !== strpos( $c['url'], 'hook.example.test' ); } ) ) );
check( 'R2.8 the key never reaches the log', 0 === logged( 'test-brevo-key' ) );

echo "── R3. a payload the handler refuses ──\n";
$w = fresh();
brevo( array( contact( 'bad@example.test', payload( 'not-an-email' ) ), contact( 'junk@example.test', '{nope' ) ) );
HDLV2_Stage1_Waiting::run_replay();
check( 'R3.1 4xx / unreadable → released, nothing written', array( 'bad@example.test', 'junk@example.test' ) === removed() && 0 === count( $w->inserts_into( 'hdlv2_widget_leads' ) ) );
check( 'R3.2 logged once each, email masked', 2 === logged( 'stage1-waiting' ) && 0 === logged( 'bad@example.test' ) && 1 === logged( 'b***@example.test' ) );

echo "── R4. a 5xx or exception → stays for the next tick ──\n";
$w = fresh();
$w->fail_lead_insert = true;
brevo( array( contact( 'keep@example.test', payload( 'keep@example.test' ) ) ) );
HDLV2_Stage1_Waiting::run_replay();
check( 'R4.1 lead not saved (500) → left on the list', array() === removed() && array() === blanked() );

echo "── R5. older than 14 days ──\n";
$w = fresh();
brevo( array( contact( 'old@example.test', payload( 'old@example.test' ), gmdate( 'Y-m-d', time() - 15 * DAY_IN_SECONDS ) ) ) );
HDLV2_Stage1_Waiting::run_replay();
check( 'R5.1 released without a lead, logged masked', array( 'old@example.test' ) === removed() && 0 === count( $w->inserts_into( 'hdlv2_widget_leads' ) ) && 1 === logged( 'o***@example.test' ) );

echo "── R6. Brevo 429 ──\n";
$w = fresh();
brevo( array(), array( 'list' => 429 ) );
HDLV2_Stage1_Waiting::run_replay();
check( 'R6.1 list 429 → stop, log, nothing released', array() === removed() && 1 === logged( '429' ) );
$w = fresh();
brevo( array( contact( 'r1@example.test', payload( 'r1@example.test' ) ), contact( 'r2@example.test', payload( 'r2@example.test' ) ) ), array( 'remove' => 429 ) );
HDLV2_Stage1_Waiting::run_replay();
check( 'R6.2 remove 429 → the tick stops after the first contact', 1 === count( $w->inserts_into( 'hdlv2_widget_leads' ) ) );

echo "── R7. busy mid-tick (the handler says 503) ──\n";
$w = fresh();
brevo( array( contact( 'mid@example.test', payload( 'mid@example.test' ) ) ) );
$n = 0;
HDLV2_Stage1_Drain::$load_source = function () use ( &$n ) { return array( $n++ ? 9 : 0.1, 1, 1 ); };
HDLV2_Stage1_Waiting::run_replay();
check( 'R7.1 server_busy → left on the list', array() === removed() );

@unlink( $GLOBALS['logfile'] );
done();
