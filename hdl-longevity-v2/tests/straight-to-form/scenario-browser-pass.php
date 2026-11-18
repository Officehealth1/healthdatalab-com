<?php
/**
 * v0.48.85 (plan 2k) — the browser pass: what our server hands the browser that sent the nine answers,
 * so a buyer from the result page can go straight in. "b<time>.<64 hex>", signed over the practitioner,
 * the e-mail, the lead id and the digest of that lead row's stored answers; the lead id is read on the
 * server and never shown (review F4: a visible id told a prober about other people's leads). 60 minutes.
 * It is never an inbox pass and an inbox pass is never one. Against the REAL HDLV2_Offer and
 * HDLV2_Widget_Config::lead_digest().
 *
 * Run:  php scenario-browser-pass.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/../full-report/stubs.php';
function wp_salt( $scheme = 'auth' ) { return 'salt-one-for-tests'; }
load_sources();

const EMAIL = 'buyer+2k@example.test';
const PRAC  = 122;
const LEAD  = 2401;

$has = method_exists( 'HDLV2_Offer', 'browser_pass' ) && method_exists( 'HDLV2_Offer', 'browser_pass_ok' ) && method_exists( 'HDLV2_Offer', 'is_browser_pass' ) && method_exists( 'HDLV2_Widget_Config', 'lead_digest' );
check( 'BP0 HDLV2_Offer::browser_pass(), browser_pass_ok(), is_browser_pass() and HDLV2_Widget_Config::lead_digest() exist', $has );
if ( ! $has ) done();

function row( $over = array() ) {
    $answers = array( 'q1_age' => 44, 'q1_sex' => 'male', 'q2a' => 3, 'q2b' => 'even', 'q3' => 'b', 'q4' => 'b', 'q5' => 'b', 'q6' => 'b', 'q7' => 'a', 'q8' => 'b', 'q9' => 'b' );
    return (object) array_merge( array( 'id' => LEAD, 'visitor_name' => 'Test Lead', 'visitor_phone' => '', 'stage1_data' => json_encode( $answers ) ), $over );
}
$D = HDLV2_Widget_Config::lead_digest( row() );

echo "── BP1. the digest of a lead row ──\n";
check( 'BP1.1 64 hex', (bool) preg_match( '/^[a-f0-9]{64}$/', $D ) );
check( 'BP1.2 the same row gives the same digest', $D === HDLV2_Widget_Config::lead_digest( row() ) );
$changed = json_decode( row()->stage1_data, true ); $changed['q5'] = 'd';
check( 'BP1.3 one answer changed: another digest', $D !== HDLV2_Widget_Config::lead_digest( row( array( 'stage1_data' => json_encode( $changed ) ) ) ) );
check( 'BP1.4 another name: another digest', $D !== HDLV2_Widget_Config::lead_digest( row( array( 'visitor_name' => 'Someone Else' ) ) ) );
check( 'BP1.5 another phone: another digest', $D !== HDLV2_Widget_Config::lead_digest( row( array( 'visitor_phone' => '+44 7700 900123' ) ) ) );
$flags = json_decode( row()->stage1_data, true ); $flags['_safety_flags'] = array( array( 'k' => 'x' ) ); $flags['_safety'] = array( 'chest' );
check( 'BP1.6 the safety keys the server adds do not change it (answers only)', $D === HDLV2_Widget_Config::lead_digest( row( array( 'stage1_data' => json_encode( $flags ) ) ) ) );
check( 'BP1.7 a missing phone column reads as empty', $D === HDLV2_Widget_Config::lead_digest( (object) array( 'id' => LEAD, 'visitor_name' => 'Test Lead', 'stage1_data' => row()->stage1_data ) ) );

echo "── BP2. made and checked ──\n";
$p = HDLV2_Offer::browser_pass( EMAIL, PRAC, LEAD, $D );
check( 'BP2.1 shape b<10 digits>.<64 hex>, no lead id shown', (bool) preg_match( '/^b\d{10}\.[a-f0-9]{64}$/', $p ) && false === strpos( $p, '.' . LEAD . '.' ) );
check( 'BP2.2 it checks', HDLV2_Offer::browser_pass_ok( $p, EMAIL, PRAC, LEAD, $D ) );
check( 'BP2.3 the e-mail in other letter case still checks', HDLV2_Offer::browser_pass_ok( $p, strtoupper( EMAIL ), PRAC, LEAD, $D ) );
check( 'BP2.4 it is known as a browser pass by its shape', HDLV2_Offer::is_browser_pass( $p ) );
check( 'BP2.5 nothing stored (no option, no transient written)', empty( $GLOBALS['transients'] ) );

echo "── BP3. refused ──\n";
check( 'BP3.1 another e-mail', ! HDLV2_Offer::browser_pass_ok( $p, 'someone@example.test', PRAC, LEAD, $D ) );
check( 'BP3.2 another practitioner', ! HDLV2_Offer::browser_pass_ok( $p, EMAIL, 206, LEAD, $D ) );
check( 'BP3.3 another lead', ! HDLV2_Offer::browser_pass_ok( $p, EMAIL, PRAC, LEAD + 1, $D ) );
check( 'BP3.5 changed answers (the digest of the row now)', ! HDLV2_Offer::browser_pass_ok( $p, EMAIL, PRAC, LEAD, HDLV2_Widget_Config::lead_digest( row( array( 'stage1_data' => json_encode( $changed ) ) ) ) ) );
$old = HDLV2_Offer::browser_pass( EMAIL, PRAC, LEAD, $D, time() - 61 * MINUTE_IN_SECONDS );
check( 'BP3.6 61 minutes old', ! HDLV2_Offer::browser_pass_ok( $old, EMAIL, PRAC, LEAD, $D ) );
$fresh = HDLV2_Offer::browser_pass( EMAIL, PRAC, LEAD, $D, time() - 59 * MINUTE_IN_SECONDS );
check( 'BP3.7 59 minutes old still checks', HDLV2_Offer::browser_pass_ok( $fresh, EMAIL, PRAC, LEAD, $D ) );
$future = HDLV2_Offer::browser_pass( EMAIL, PRAC, LEAD, $D, time() + 5 * MINUTE_IN_SECONDS );
check( 'BP3.8 from the future (5 minutes)', ! HDLV2_Offer::browser_pass_ok( $future, EMAIL, PRAC, LEAD, $D ) );
check( 'BP3.9 one hex character changed', ! HDLV2_Offer::browser_pass_ok( substr( $p, 0, -1 ) . ( 'a' === substr( $p, -1 ) ? 'b' : 'a' ), EMAIL, PRAC, LEAD, $D ) );
check( 'BP3.10 an empty digest never checks', ! HDLV2_Offer::browser_pass_ok( HDLV2_Offer::browser_pass( EMAIL, PRAC, LEAD, '' ), EMAIL, PRAC, LEAD, '' ) );
foreach ( array( 'empty' => '', 'no b' => substr( $p, 1 ), 'upper hex' => strtoupper( $p ), 'the old shape with a lead id' => 'b' . time() . '.' . LEAD . '.' . str_repeat( 'a', 64 ),
                  'nine digits' => 'b' . substr( (string) time(), 1 ) . '.' . str_repeat( 'a', 64 ), 'a list' => array( $p ), 'null' => null, 'trailing space' => $p . ' ' ) as $label => $bad ) {
    check( "BP3.11 bad shape ($label)", ! HDLV2_Offer::browser_pass_ok( $bad, EMAIL, PRAC, LEAD, $D ) && ! HDLV2_Offer::is_browser_pass( $bad ) );
}

echo "── BP4. never the other kind ──\n";
$inbox = HDLV2_Offer::inbox_pass( EMAIL, PRAC );
check( 'BP4.1 an inbox pass is not a browser pass', ! HDLV2_Offer::browser_pass_ok( $inbox, EMAIL, PRAC, LEAD, $D ) && ! HDLV2_Offer::is_browser_pass( $inbox ) );
check( 'BP4.2 a browser pass is not an inbox pass', ! HDLV2_Offer::inbox_pass_ok( $p, EMAIL, PRAC ) );
check( 'BP4.3 a browser pass with its b cut off has the inbox shape and still is not one (another message is signed)', (bool) preg_match( '/^\d{10}\.[a-f0-9]{64}$/', substr( $p, 1 ) ) && ! HDLV2_Offer::inbox_pass_ok( substr( $p, 1 ), EMAIL, PRAC ) );
check( 'BP4.4 the inbox pass still checks as before', HDLV2_Offer::inbox_pass_ok( $inbox, EMAIL, PRAC ) );
check( 'BP4.5 one place for the life: 60 minutes', 60 === HDLV2_Offer::BROWSER_PASS_MINUTES );
done();
