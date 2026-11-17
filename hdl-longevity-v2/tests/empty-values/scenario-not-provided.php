<?php
/**
 * v0.48.79 (M39; hdlv2_ff_empty_values) — the report page names what the client left empty.
 *   L  HDLV2_Final_Report::inputs_not_provided(): the labels of the twenty chart inputs whose score is
 *      not a number, in the chart's order, from the one label map (HDLV2_Email_Templates); a score of 0
 *      is a score; a key outside the twenty is ignored.
 *   R  build_radar_chart_url(): for one fixed set of scores, the same address as 0.48.77.
 *   D  the REAL HDLV2_Client_Draft_View::rest_get_draft(): calc.not_provided only with the switch on.
 * Run:  php scenario-not-provided.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_json_encode( $x ) { return json_encode( $x ); }
function apply_filters( $t, $v ) { return $v; }
function add_action() {}
function add_shortcode() {}
function register_rest_route() {}
function get_current_user_id() { return 0; }
function rest_ensure_response( $x ) { return $x; }
function get_userdata( $id ) { return (object) array( 'ID' => $id, 'display_name' => 'P' ); }
function get_user_meta() { return ''; }
function esc_url_raw( $s ) { return $s; }
class WP_Error { public function __construct() {} }
function is_wp_error( $x ) { return $x instanceof WP_Error; }

$root = dirname( __DIR__, 2 );
require $root . '/includes/sprint-2/class-hdlv2-why-picks.php';
require $root . '/includes/sprint-2/class-hdlv2-rate-calculator.php';
require $root . '/includes/sprint-2/class-hdlv2-email-templates.php';
require $root . '/includes/sprint-1/class-hdlv2-offer.php';
require $root . '/includes/sprint-2c/class-hdlv2-final-report.php';
require $root . '/includes/sprint-2c/class-hdlv2-client-draft-view.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) { global $pass, $fail; echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n"; $ok ? $pass++ : $fail++; }

// The twenty chart inputs, in the chart's order, each with a number.
$SCORES = array( 'physicalActivity' => 3, 'sitToStand' => 2, 'breathHold' => 4, 'balance' => 5, 'sleepDuration' => 4, 'sleepQuality' => 3,
    'stressLevels' => 2, 'socialConnections' => 4, 'dietQuality' => 3, 'alcoholConsumption' => 4, 'smokingStatus' => 5, 'cognitiveActivity' => 3,
    'sunlightExposure' => 1, 'supplementIntake' => 1, 'dailyHydration' => 3, 'bmiScore' => 4, 'whrScore' => 3, 'whtrScore' => 4,
    'bloodPressureScore' => 4, 'heartRateScore' => 4 );

echo "── L: the list ──\n";
$has = method_exists( 'HDLV2_Final_Report', 'inputs_not_provided' );
check( 'L0 HDLV2_Final_Report::inputs_not_provided() exists', $has );
if ( $has ) {
    $L = function ( $s ) { return HDLV2_Final_Report::inputs_not_provided( $s ); };
    check( 'L1 all twenty have a number: an empty list', array() === $L( $SCORES ) );
    check( 'L2 blood pressure null: [ "Blood pressure" ]', array( 'Blood pressure' ) === $L( array_merge( $SCORES, array( 'bloodPressureScore' => null ) ) ) );
    check( 'L3 a score of 0 is a score, not listed', array() === $L( array_merge( $SCORES, array( 'smokingStatus' => 0, 'alcoholConsumption' => '0' ) ) ) );
    $three = $L( array_merge( $SCORES, array( 'heartRateScore' => null, 'bloodPressureScore' => null, 'whrScore' => null, 'whtrScore' => null ) ) );
    check( 'L4 several: in the chart\'s order ("Waist-to-hip ratio, Waist-to-height ratio, Blood pressure, Heart rate")',
        array( 'Waist-to-hip ratio', 'Waist-to-height ratio', 'Blood pressure', 'Heart rate' ) === $three );
    $gone = $SCORES; unset( $gone['bloodPressureScore'] );
    check( 'L5 a key missing from the scores counts as not provided', array( 'Blood pressure' ) === $L( $gone ) );
    check( 'L6 keys outside the twenty are ignored (skin, overall, made-up)', array() === $L( array_merge( $SCORES, array( 'skinElasticity' => null, 'overallHealthScore' => null, 'nope' => null ) ) ) );
    check( "L7 a score that is not a number ('', 'skip') is not provided", array( 'Physical activity', 'Balance' ) === $L( array_merge( $SCORES, array( 'physicalActivity' => '', 'balance' => 'skip' ) ) ) );
    $all = $L( array() );
    check( 'L8 nothing scored: all twenty labels, from the one label map', 20 === count( $all ) && 'Physical activity' === $all[0] && 'Heart rate' === $all[19]
        && $all === array_map( array( 'HDLV2_Email_Templates', 'score_key_to_label' ), array_keys( HDLV2_Final_Report::RADAR_INPUTS ) ) );
    check( 'L9 not an array: an empty list (nothing to name)', array() === $L( null ) );
}

echo "── R: the chart address ──\n";
$fixed = array_merge( $SCORES, array( 'supplementIntake' => 0, 'whrScore' => null, 'whtrScore' => null, 'bloodPressureScore' => null, 'skinElasticity' => 3, 'overallHealthScore' => 2 ) );
check( 'R1 build_radar_chart_url(): the 0.48.77 address for one fixed set of scores',
    '5752002857cbe7f3587c3b513b6c4f61df355a2d8d78ebf0d28aa4b2939d5876' === hash( 'sha256', HDLV2_Final_Report::build_radar_chart_url( $fixed ) ) );

echo "── D: the report page data ──\n";
class FakeWpdb {
    public $prefix = 'wp_'; public $progress; public $draft;
    public function prepare( $q, ...$a ) { return $q; }
    public function get_row( $q ) {
        if ( false !== strpos( $q, 'hdlv2_form_progress' ) ) return $this->progress;
        if ( false !== strpos( $q, "report_type = 'draft'" ) ) return $this->draft;
        return null;
    }
    public function get_var( $q ) { return null; }
    public function get_results( $q ) { return array(); }
}
global $wpdb;
$wpdb = new FakeWpdb();
$wpdb->draft = (object) array( 'id' => 9, 'report_content' => json_encode( array( 'ai_narrative' => array( 'opening' => 'x' ) ) ), 'milestones' => '{}', 'status' => 'ready', 'created_at' => '2026-11-17 00:00:00' );
$wpdb->progress = (object) array( 'id' => 70, 'client_user_id' => 42, 'practitioner_user_id' => 3, 'client_name' => 'A',
    'stage1_data' => json_encode( array( 'q1_age' => '52', 'q1_sex' => 'female' ) ), 'stage2_data' => '{}',
    'stage3_data' => json_encode( array( 'server_result' => array( 'rate' => 0.96, 'bio_age' => 50, 'scores' => array_merge( $SCORES, array( 'bloodPressureScore' => null ) ) ) ) ),
    'stage3_completed_at' => '2026-11-17 00:00:00', 'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
class Req { public function get_param( $k ) { return 'token' === $k ? str_repeat( 'ab', 32 ) : null; } }
$draft = function () { return HDLV2_Client_Draft_View::get_instance()->rest_get_draft( new Req() ); };
$off = $draft();
check( 'D1 switch absent: the page data has no not_provided key', is_array( $off ) && isset( $off['calc'] ) && ! array_key_exists( 'not_provided', $off['calc'] ) );
$GLOBALS['options']['hdlv2_ff_empty_values'] = '1';
$on = $draft();
check( 'D2 switch on: calc.not_provided is [ "Blood pressure" ]', is_array( $on ) && array( 'Blood pressure' ) === ( $on['calc']['not_provided'] ?? null ) );
$c_on = $on['calc'] ?? array(); unset( $c_on['not_provided'] );
check( 'D3 switch on: every other part of calc is the same', is_array( $off ) && $off['calc'] === $c_on );
unset( $GLOBALS['options']['hdlv2_ff_empty_values'] );
echo "\n" . ( $fail ? "SCENARIO: FAIL ($fail)\n" : "SCENARIO: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );
