<?php
/**
 * v0.48.79 (M39; hdlv2_ff_empty_values) — a raw measurement the client left empty is never sent on as 0.
 * Against the REAL classes, no AI call (the request body is captured by a stub):
 *   H  HDLV2_Final_Report::raw_measurement(): the number when numeric and above 0, else null.
 *   P  build_pdf_payload(): the seven raw values for the Final report PDF. Switch absent: today's values
 *      (0 for an empty box). Switch on: null for an empty box, the same number for a real one.
 *   A  generate_pdf_sections(): the prompt the AI reads. Switch on, an empty blood pressure and heart rate
 *      read "n/a", never "0"; every other line is the switch-off text; a full set of values gives the
 *      same prompt either way.
 * Run:  php scenario-raw-values.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
// The AI service only checks that a key is configured; nothing is sent anywhere.
$ai_key_name = 'HDLV2_ANTHROPIC_API_KEY';
define( $ai_key_name, 'stub' );

$GLOBALS['options'] = array();
$GLOBALS['posts']   = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function wp_remote_post( $url, $args = array() ) {
    $GLOBALS['posts'][] = $args;
    return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'content' => array( array( 'type' => 'text', 'text' => '{"awaken_content":"a"}' ) ), 'stop_reason' => 'end_turn' ) ) );
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_json_encode( $x ) { return json_encode( $x ); }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_kses_post( $x ) { return (string) $x; }
function wp_strip_all_tags( $x ) { return trim( strip_tags( (string) $x ) ); }
function sanitize_text_field( $x ) { return trim( strip_tags( (string) $x ) ); }
function esc_url_raw( $x ) { return (string) $x; }
function current_time( $f ) { return 'Y-m-d' === $f ? '2026-11-17' : '2026-11-17 10:00:00'; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . $p; }
function apply_filters( $t, $v ) { return $v; }
function add_action() {}
function get_userdata( $id ) { return (object) array( 'display_name' => 'Practitioner', 'user_email' => 'p@example.test', 'ID' => $id ); }
function get_user_meta() { return ''; }
class WP_Error { public function get_error_message() { return ''; } }
class Fake_WPDB { public $prefix = 'wp_'; public function prepare( $q ) { return $q; } public function get_var() { return null; } public function get_row() { return null; } public function get_results() { return array(); } }
$GLOBALS['wpdb'] = new Fake_WPDB();
// The payload's other parts, not under test here.
class HDLV2_Staged_Form { public static function format_milestones( $m ) { return ''; } public static function build_gauge_url() { return ''; } }
class HDLV2_Why_Picks { public static function payload_fields() { return array(); } public static function block_for_row() { return ''; } }
class HDLV2_Practitioner { public static function get_logo_url() { return ''; } }

$root = dirname( __DIR__, 2 );
require_once dirname( __DIR__, 2 ) . '/includes/class-hdlv2-report-names.php'; // v0.48.96: the report names
require $root . '/includes/sprint-2/class-hdlv2-rate-calculator.php';
require $root . '/includes/sprint-2/class-hdlv2-ai-service.php';
require $root . '/includes/sprint-2/class-hdlv2-email-templates.php';
require $root . '/includes/sprint-2c/class-hdlv2-final-report.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) { global $pass, $fail; echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n"; $ok ? $pass++ : $fail++; }
function flag( $on ) { if ( $on ) $GLOBALS['options']['hdlv2_ff_empty_values'] = '1'; else unset( $GLOBALS['options']['hdlv2_ff_empty_values'] ); }

echo "── H: the helper ──\n";
$has = method_exists( 'HDLV2_Final_Report', 'raw_measurement' );
check( 'H0 HDLV2_Final_Report::raw_measurement() exists', $has );
if ( $has ) {
    $none = array( 'unset' => null, "''" => '', 'spaces' => '   ', "'skip'" => 'skip', "'abc'" => 'abc', "'0'" => '0', '0' => 0, '-5' => -5, "'-120'" => '-120' );
    foreach ( $none as $label => $v ) check( "H1 $label is not provided (null)", null === HDLV2_Final_Report::raw_measurement( $v ) );
    $nums = array( "'120'" => array( '120', 120.0 ), '120' => array( 120, 120.0 ), "' 80 '" => array( ' 80 ', 80.0 ), "'172.5'" => array( '172.5', 172.5 ) );
    foreach ( $nums as $label => $c ) check( "H2 $label is the number {$c[1]}", $c[1] === HDLV2_Final_Report::raw_measurement( $c[0] ) );
}

echo "── P: the Final report PDF payload ──\n";
$full_s3 = array( 'bpSystolic' => '118', 'bpDiastolic' => '76', 'restingHeartRate' => '62', 'height' => '172.5', 'weight' => '70', 'waist' => '84', 'hip' => '98' );
// As the form stores it when a box is clicked and left empty (hdlv2-staged-form.js, blur saves '').
$empty_s3 = array( 'bpSystolic' => '', 'bpDiastolic' => '', 'restingHeartRate' => '62', 'height' => '172.5', 'weight' => '70', 'waist' => '', 'hip' => '' );
$payload = function ( $s3 ) {
    $progress = (object) array( 'id' => 7, 'token' => '', 'client_name' => 'Test Client', 'client_email' => 'client@example.test', 'stage3_data' => json_encode( $s3 ), 'practitioner_user_id' => 3 );
    $calc = array( 'rate' => 1.0, 'bio_age' => 50, 'scores' => array() );
    return HDLV2_Final_Report::build_pdf_payload( $progress, $calc, array( 'q1_age' => 50 ), array(), array(), array(), array(), array(), '', 3 );
};
$KEYS = array( 'bp_systolic', 'bp_diastolic', 'resting_hr_bpm', 'height_cm', 'weight_kg', 'waist_cm', 'hip_cm' );
$pick = function ( $p ) use ( $KEYS ) { $o = array(); foreach ( $KEYS as $k ) $o[ $k ] = array_key_exists( $k, $p ) ? $p[ $k ] : 'MISSING'; return $o; };
flag( false );
$off_empty = $pick( $payload( $empty_s3 ) );
$off_full  = $payload( $full_s3 );
check( 'P1 switch absent: an empty blood pressure goes out as 0 and 0 (today)', 0 === $off_empty['bp_systolic'] && 0 === $off_empty['bp_diastolic'] );
check( 'P2 switch absent: empty waist and hip go out as 0.0 (today)', 0.0 === $off_empty['waist_cm'] && 0.0 === $off_empty['hip_cm'] );
flag( true );
$on_empty = $pick( $payload( $empty_s3 ) );
$on_full  = $payload( $full_s3 );
check( 'P3 switch on: an empty blood pressure goes out as null, never 0', null === $on_empty['bp_systolic'] && null === $on_empty['bp_diastolic'] );
check( 'P4 switch on: empty waist and hip go out as null', null === $on_empty['waist_cm'] && null === $on_empty['hip_cm'] );
check( 'P5 switch on: a given heart rate, height and weight keep their numbers', 62 === $on_empty['resting_hr_bpm'] && 172.5 === $on_empty['height_cm'] && 70.0 === $on_empty['weight_kg'] );
check( 'P6 a full set of values: the whole payload is the same either way', $off_full === $on_full );
check( 'P7 switch on: the raw values are numbers or null, nothing else', array_reduce( $on_empty, function ( $ok, $v ) { return $ok && ( null === $v || is_int( $v ) || is_float( $v ) ); }, true ) );
$z = $empty_s3; $z['bpSystolic'] = '0'; $z['bpDiastolic'] = 'abc';
$on_z = $pick( $payload( $z ) );
check( "P8 switch on: '0' and text are not readings either (null)", null === $on_z['bp_systolic'] && null === $on_z['bp_diastolic'] );
flag( false );

echo "── A: what the AI reads for the Final report PDF ──\n";
$prompt = function ( $s3 ) {
    $GLOBALS['posts'] = array();
    $calc = array( 'rate' => 1.0, 'bio_age' => 50, 'bmi' => 23.5, 'whr' => 0.86, 'whtr' => 0.49, 'scores' => array( 'heartRateScore' => 4, 'bloodPressureScore' => null ) );
    HDLV2_AI_Service::generate_pdf_sections( $calc, array( 'distilled_why' => 'x' ), array(), array(), $s3, array(), '', 'Test', 'female', 50 );
    $p = end( $GLOBALS['posts'] );
    $b = $p ? json_decode( $p['body'], true ) : array();
    return (string) ( $b['messages'][0]['content'] ?? '' );
};
$line = function ( $text, $start ) { foreach ( explode( "\n", $text ) as $l ) if ( 0 === strpos( ltrim( $l, "- " ), $start ) ) return $l; return ''; };
$bp_empty = array( 'bpSystolic' => '', 'bpDiastolic' => '', 'restingHeartRate' => '' );
flag( false );
$a_off = $prompt( $bp_empty );
$a_off_full = $prompt( $full_s3 );
flag( true );
$a_on = $prompt( $bp_empty );
$a_on_full = $prompt( $full_s3 );
flag( false );
// The value part only: the lines also carry the targets "(target <120/80)" and "(range 50-70)".
$bp = function ( $t ) use ( $line ) { return preg_match( '#^Blood pressure: (.*?) mmHg#', $line( $t, 'Blood pressure' ), $m ) ? $m[1] : ''; };
$hr = function ( $t ) use ( $line ) { return preg_match( '#^Resting HR: (.*?) bpm#', $line( $t, 'Resting HR' ), $m ) ? $m[1] : ''; };
check( 'A0 the prompt was built and captured (no AI called)', '' !== $a_on && '' !== $a_off );
check( 'A1 switch absent: an empty blood pressure reads "0/0" in the prompt today', '0/0' === $bp( $a_off ) );
check( 'A2 switch on: the blood pressure reads "n/a/n/a", no 0', 'n/a/n/a' === $bp( $a_on ) );
check( 'A3 switch on: the resting heart rate reads "n/a", no 0', 'n/a' === $hr( $a_on ) && '0' === $hr( $a_off ) );
$strip = function ( $t ) use ( $line ) { return str_replace( array( $line( $t, 'Blood pressure' ), $line( $t, 'Resting HR' ) ), '', $t ); };
check( 'A4 switch on: every other line of the prompt is the switch-off text', $strip( $a_on ) === $strip( $a_off ) );
check( 'A5 a full set of values: the same prompt either way', '' !== $a_on_full && $a_on_full === $a_off_full );
echo "\n" . ( $fail ? "SCENARIO: FAIL ($fail)\n" : "SCENARIO: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );
