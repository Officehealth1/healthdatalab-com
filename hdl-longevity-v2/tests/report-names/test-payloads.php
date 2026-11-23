<?php
/**
 * Plan 12 of the $27 path: the buyer's route (draft) and the WHY route (stage 2) payloads to Make.
 * Switch hdlv2_ff_report_names absent: byte for byte the payloads of 46eda9b (md5, the clock-dependent
 * fields set aside). On: report_name ("Longevity Report") is the only key added. Real classes
 * (tests/dashboard-products/stubs.php), the sender stubbed. The names in this file are made up.
 *
 * Run:  php tests/report-names/test-payloads.php [plugin-root] [golden]
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
$root = rtrim( $argv[1] ?? dirname( __DIR__, 2 ), '/' );
require $root . '/tests/dashboard-products/stubs.php';
if ( ! class_exists( 'HDLV2_Report_Names' ) && is_readable( $root . '/includes/class-hdlv2-report-names.php' ) ) require_once $root . '/includes/class-hdlv2-report-names.php';
$stage2_hook = 'HDLV2_MAKE_STAGE2_WHY';
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) define( 'MINUTE_IN_SECONDS', 60 );
define( $stage2_hook, 'https://hook.example.test/stage2' );
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t ) { return true; }
function rest_url( $p = '' ) { return 'https://stby.test/wp-json/' . $p; }
class HDLV2_Webhook_Monitor { public static $sent = array(); public static function fire( $url, $args, $kind ) { self::$sent[] = $args; } }
$GLOBALS['wpdb'] = new ProductsWpdb();
if ( ! class_exists( 'HDLV2_Practitioner' ) ) { class HDLV2_Practitioner { public static function get_logo_url( $id, $fallback = false ) { return 'https://example.test/logo.png'; } } }
$GLOBALS['users'][3] = array( 'ada@example.test', 'Ada Lark', array( 'um_practitioner' ) );

function sw( $v ) { if ( null === $v ) unset( $GLOBALS['options']['hdlv2_ff_report_names'] ); else $GLOBALS['options']['hdlv2_ff_report_names'] = $v; }
// Values that follow the clock (dates the stubs take from today) or a random code, set aside before the md5.
const CLOCKED = array( 'generated_at', 'submitted_at', 'report_date', 'timestamp', 'practitioner_dashboard_url' ); // the last carries a one-time sign-in code
function sent_body() {
    $b = json_decode( HDLV2_Webhook_Monitor::$sent[0]['body'] ?? '{}', true );
    return is_array( $b ) ? $b : array();
}
function steady( $b ) { foreach ( CLOCKED as $k ) unset( $b[ $k ] ); return md5( json_encode( $b ) ); }
function buyer() {
    HDLV2_Webhook_Monitor::$sent = array();
    $form = ( new ReflectionClass( 'HDLV2_Staged_Form' ) )->newInstanceWithoutConstructor();
    $fire = new ReflectionMethod( $form, 'fire_make_webhook' );
    $fire->setAccessible( true );
    $progress = (object) array( 'id' => 7, 'practitioner_user_id' => 3, 'client_name' => 'Ivy Test', 'client_email' => 'ivy@example.test', 'stage3_data' => null );
    $fire->invoke( $form, $progress, array( 'rate' => 1.11, 'bio_age' => 49.9, 'scores' => array() ), array( 'q1_age' => 45 ), array(), array() );
    return sent_body();
}
function why() {
    HDLV2_Webhook_Monitor::$sent = array();
    $progress = (object) array( 'id' => 7, 'practitioner_user_id' => 3, 'client_name' => 'Ivy Test', 'client_email' => 'ivy@example.test', 'stage1_data' => json_encode( array( 'q1_age' => 45 ) ), 'token' => str_repeat( 'a', 64 ) );
    HDLV2_Staged_Form::fire_stage2_webhook( $progress, array( 'vision_text' => 'To keep up with my grandchildren.' ) );
    return sent_body();
}

const GOLDEN_BUYER = '6214b8dd7d7329d50da9720683ccb9f6';
const GOLDEN_WHY   = '5f8eae71a9f58febe0daf3be641fbff0';
if ( 'dump' === ( $argv[2] ?? '' ) ) { sw( null ); print_r( array_map( function ( $v ) { return is_string( $v ) ? substr( $v, 0, 70 ) : $v; }, why() ) ); exit( 0 ); }
if ( 'golden' === ( $argv[2] ?? '' ) ) { sw( null ); $b = buyer(); $w = why(); echo steady( $b ), ' ', count( $b ), ' keys; ', steady( $w ), ' ', count( $w ), " keys\n"; exit( 0 ); }

// check() and the PASS / FAIL counts come from the stubs.
foreach ( array( 'buyer' => array( 'the buyer\'s route (draft)', GOLDEN_BUYER ), 'why' => array( 'the WHY route (stage 2)', GOLDEN_WHY ) ) as $fn => list( $name, $golden ) ) {
    echo "── $name ──\n";
    sw( null );
    $off = $fn();
    check( "$name, switch absent: byte for byte the payload of 46eda9b", count( $off ) > 10 && $golden === steady( $off ) );
    check( "$name, switch absent: no report_name", ! array_key_exists( 'report_name', $off ) );
    sw( '0' );
    check( "$name, switch \"0\": the same payload", $golden === steady( $fn() ) );
    sw( '1' );
    $on = $fn();
    check( "$name, switch on: report_name is \"Longevity Report\"", 'Longevity Report' === ( $on['report_name'] ?? null ) );
    unset( $on['report_name'] );
    check( "$name, switch on: no other key added or changed", steady( $on ) === steady( $off ) && array_keys( $on ) === array_keys( $off ) );
}
sw( null );
echo "\nPASS=$PASS FAIL=$FAIL\n";
exit( $FAIL === 0 ? 0 : 1 );
