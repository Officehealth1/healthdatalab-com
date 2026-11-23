<?php
/**
 * Plan 12 of the $27 path: the three report names in one place, and the line "Reviewed with you by
 * {practitioner}, {date}" on the report made after a consultation. Switch hdlv2_ff_report_names,
 * absent = off. Against the REAL classes, no network, frozen clock. The names in this file are made up.
 *
 *   N  HDLV2_Report_Names::say(): today's words with the switch off or "0"; the names on; the free
 *      report keeps today's words either way; for_script() hands a script nothing when off.
 *   L  reviewed_line(): the account that did the consultation, its display name as plain text
 *      (WordPress stores display names HTML-encoded; tags dropped), the date; "Reviewed with your
 *      practitioner" with no usable name; nothing with no practitioner account or the switch off.
 *   F  build_pdf_payload() (the consultation route): switch off, byte for byte the payload of 46eda9b
 *      (md5); on, only report_name, paid_report_name and reviewed_line are added, and the line names
 *      practitioner B when the client belongs to A.
 *
 * Run:  php tests/report-names/test-names.php [plugin-root] [golden]
 *       "golden" prints the switch-off md5 of the plugin at plugin-root (how GOLDEN_FINAL was taken).
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
$ai_key_name = 'HDLV2_ANTHROPIC_API_KEY';
define( $ai_key_name, 'stub' );

$GLOBALS['options'] = array();
$GLOBALS['users']   = array(
    3 => 'Ada Lark',                              // the client's own practitioner (A)
    4 => 'Nora Quill',                            // another account that did the consultation (B)
    5 => '',                                      // no display name
    6 => 'pat@example.test',                      // an e-mail address as the display name
    7 => 'Ann &amp; Bo &lt;b&gt;Clinic&lt;/b&gt;', // as WordPress stores "Ann & Bo <b>Clinic</b>"
    8 => "  Lee\tMoss  ",
);
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function wp_remote_post( $url, $args = array() ) { return array( 'response' => array( 'code' => 200 ), 'body' => '{}' ); }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_json_encode( $x ) { return json_encode( $x ); }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_kses_post( $x ) { return (string) $x; }
function wp_strip_all_tags( $x ) { return trim( strip_tags( (string) $x ) ); }
function wp_specialchars_decode( $x, $q = ENT_NOQUOTES ) { return htmlspecialchars_decode( (string) $x, ENT_QUOTES ); }
function sanitize_text_field( $x ) { return trim( strip_tags( (string) $x ) ); }
function esc_url_raw( $x ) { return (string) $x; }
function current_time( $f ) { return 'j F Y' === $f ? '23 November 2026' : ( 'Y-m-d' === $f ? '2026-11-23' : '2026-11-23T10:00:00+00:00' ); }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . $p; }
function apply_filters( $t, $v ) { return $v; }
function add_action() {}
function get_userdata( $id ) {
    if ( ! array_key_exists( (int) $id, $GLOBALS['users'] ) ) return false;
    return (object) array( 'display_name' => $GLOBALS['users'][ (int) $id ], 'user_email' => 'u' . (int) $id . '@example.test', 'ID' => (int) $id );
}
function get_user_meta() { return ''; }
class WP_Error { public function get_error_message() { return ''; } }
class Fake_WPDB { public $prefix = 'wp_'; public function prepare( $q ) { return $q; } public function get_var() { return null; } public function get_row() { return null; } public function get_results() { return array(); } }
$GLOBALS['wpdb'] = new Fake_WPDB();
class HDLV2_Staged_Form { public static function format_milestones( $m ) { return ''; } public static function build_gauge_url() { return ''; } }
class HDLV2_Why_Picks { public static function payload_fields() { return array(); } public static function block_for_row() { return ''; } }
class HDLV2_Practitioner { public static function get_logo_url() { return ''; } }

$root = rtrim( $argv[1] ?? dirname( __DIR__, 2 ), '/' );
if ( is_readable( $root . '/includes/class-hdlv2-report-names.php' ) ) require $root . '/includes/class-hdlv2-report-names.php';
require $root . '/includes/sprint-2/class-hdlv2-rate-calculator.php';
require $root . '/includes/sprint-2/class-hdlv2-ai-service.php';
require $root . '/includes/sprint-2/class-hdlv2-email-templates.php';
require $root . '/includes/sprint-2c/class-hdlv2-final-report.php';

function sw( $v ) { if ( null === $v ) unset( $GLOBALS['options']['hdlv2_ff_report_names'] ); else $GLOBALS['options']['hdlv2_ff_report_names'] = $v; }
function final_payload( $practitioner_id ) {
    $progress = (object) array( 'id' => 7, 'token' => '', 'client_name' => 'ivy test', 'client_email' => 'ivy@example.test', 'stage3_data' => json_encode( array( 'height' => '170', 'weight' => '70' ) ), 'practitioner_user_id' => 3 );
    return HDLV2_Final_Report::build_pdf_payload( $progress, array( 'rate' => 1.04, 'bio_age' => 51.2, 'scores' => array() ), array( 'q1_age' => 50 ), array(), array(), array( 'distilled_why' => 'To keep up with my grandchildren.' ), array(), array(), 'Typed notes.', $practitioner_id );
}

// md5 of json_encode( final_payload( 3 ) ) with the switch absent, at 46eda9b (taken with "golden").
const GOLDEN_FINAL = 'be761031ea655517108f83b15558264d';
if ( 'golden' === ( $argv[2] ?? '' ) ) { sw( null ); echo md5( json_encode( final_payload( 3 ) ) ), "\n"; exit( 0 ); }

$PASS = 0; $FAIL = 0;
function check( $label, $ok ) { global $PASS, $FAIL; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label . "\n"; $ok ? $PASS++ : $FAIL++; }
$has = class_exists( 'HDLV2_Report_Names' );
check( 'N0 HDLV2_Report_Names exists', $has );
if ( ! $has ) { echo "\nPASS=$PASS FAIL=$FAIL\n"; exit( 1 ); }
$R = 'HDLV2_Report_Names';

echo "── N. the names ──\n";
sw( null );
check( 'N1 switch absent: say() gives back the caller\'s words of today', 'Full Report' === $R::say( 'paid', 'Full Report' ) && 'Trajectory Plan' === $R::say( 'consultation', 'Trajectory Plan' ) );
check( 'N2 switch absent: a script gets no names', array() === $R::for_script() );
sw( '0' );
check( 'N3 switch "0": still today\'s words, still no names for a script', 'Final Report' === $R::say( 'consultation', 'Final Report' ) && array() === $R::for_script() );
sw( '1' );
check( 'N4 switch on: the $27 report is "Longevity Report"', 'Longevity Report' === $R::say( 'paid', 'Full Report' ) );
check( 'N5 switch on: the report made after a consultation is "Comprehensive Report"', 'Comprehensive Report' === $R::say( 'consultation', 'Trajectory Plan' ) );
check( 'N6 switch on: the free report keeps today\'s words (its name is a later plan)', 'Rate of ageing report' === $R::say( 'free', 'Rate of ageing report' ) );
check( 'N7 switch on: a script gets exactly the two names, by key', array( 'paid' => 'Longevity Report', 'consultation' => 'Comprehensive Report' ) === $R::for_script() );

echo "── L. the reviewed line ──\n";
check( 'L1 the account\'s display name and the date', 'Reviewed with you by Nora Quill, 23 November 2026' === $R::reviewed_line( 4, '23 November 2026' ) );
check( 'L2 no display name: "Reviewed with your practitioner"', 'Reviewed with your practitioner, 23 November 2026' === $R::reviewed_line( 5, '23 November 2026' ) );
check( 'L3 an e-mail address as the name: "Reviewed with your practitioner"', 'Reviewed with your practitioner, 23 November 2026' === $R::reviewed_line( 6, '23 November 2026' ) );
check( 'L4 a stored "&" comes out as "&" (plain text, escaped where it is printed), tags dropped', 'Reviewed with you by Ann & Bo Clinic, 23 November 2026' === $R::reviewed_line( 7, '23 November 2026' ) );
check( 'L5 spaces and tabs squeezed', 'Reviewed with you by Lee Moss, 23 November 2026' === $R::reviewed_line( 8, '23 November 2026' ) );
check( 'L6 no practitioner account (the automation tier sends 0) or an unknown id: no line', '' === $R::reviewed_line( 0, '23 November 2026' ) && '' === $R::reviewed_line( 99, '23 November 2026' ) );
sw( null );
check( 'L7 switch off: no line', '' === $R::reviewed_line( 4, '23 November 2026' ) );

echo "── F. the consultation route's payload ──\n";
sw( null );
$off = final_payload( 3 );
check( 'F1 switch absent: byte for byte the payload of 46eda9b', GOLDEN_FINAL === md5( json_encode( $off ) ) );
check( 'F2 switch absent: no new key', ! array_key_exists( 'report_name', $off ) && ! array_key_exists( 'reviewed_line', $off ) && ! array_key_exists( 'paid_report_name', $off ) );
sw( '1' );
$on = final_payload( 4 );
check( 'F3 switch on: report_name is the consultation name', 'Comprehensive Report' === ( $on['report_name'] ?? null ) );
check( 'F4 switch on: paid_report_name names the form the client filled in', 'Longevity Report' === ( $on['paid_report_name'] ?? null ) );
check( 'F5 switch on: the line names B, the account that did the consultation, though the client is A\'s', 'Reviewed with you by Nora Quill, 23 November 2026' === ( $on['reviewed_line'] ?? null ) );
check( 'F6 switch on: practitioner_name (today\'s field) is B too: one account, one name', 'Nora Quill' === ( $on['practitioner_name'] ?? null ) );
$same = final_payload( 3 );
unset( $same['report_name'], $same['paid_report_name'], $same['reviewed_line'] );
sw( null );
check( 'F7 switch on: nothing else in the payload moves', json_encode( $same ) === json_encode( final_payload( 3 ) ) );

echo "\nPASS=$PASS FAIL=$FAIL\n";
exit( $FAIL === 0 ? 0 : 1 );
