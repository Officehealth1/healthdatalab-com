<?php
/**
 * v0.48.97 (plan 11; hdlv2_ff_input_checks) — what the switch hands to the two scripts, and that the form's
 * hard limits are the calculator's own. Drives the REAL HDLV2_Staged_Form::render_shortcode(),
 * HDLV2_Staged_Form::input_checks(), HDLV2_Widget_Config::rest_get_public_config() / rest_verify_invite()
 * and HDLV2_Rate_Calculator::calculate_full().
 *   A: the form's script config: switch absent or "0", no new key and every key as today; on, one key
 *      input_checks with the hard limits, the usual ranges and the labels of the 21 scored answers.
 *   B: the widget's public answer: absent or "0", no new key; on, age_typed; the invite answer never.
 *   C: the hard limits agree with calculate_full(): systolic 249 scored, 250 not; diastolic 149, 150.
 *   D: the calculator file is byte for byte the one the audit ran.
 * Run:  php tests/input-checks/scenario-limits.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/../full-report/stubs.php';
load_sources();
$GLOBALS['localized'] = array();
function wp_enqueue_script() {}
function wp_enqueue_style() {}
function wp_localize_script( $handle, $name, $data ) { $GLOBALS['localized'][ $handle . '/' . $name ] = $data; }
function wp_create_nonce() { return 'nonce'; }
require_once __DIR__ . '/../../includes/sprint-2/class-hdlv2-staged-form.php';

$SW = 'hdlv2_ff_input_checks';
function form_cfg( $opts ) {
    $GLOBALS['options']   = $opts;
    $GLOBALS['localized'] = array();
    ( new ReflectionClass( 'HDLV2_Staged_Form' ) )->newInstanceWithoutConstructor()->render_shortcode( array() );
    return $GLOBALS['localized']['hdlv2-staged-form/hdlv2_form'] ?? null;
}
function public_cfg( $opts ) {
    fresh_wpdb();
    $GLOBALS['options'] = $opts;
    $r = widget_instance()->rest_get_public_config( new FakeRequest( array(), array(), 'GET', '', array( 'practitioner_id' => 206 ) ) );
    $d = $r instanceof WP_REST_Response ? $r->get_data() : $r;
    return $d['config'] ?? null;
}
$BASE = array( 'hdlv2_db_version' => '3.29' );

echo "── A. the form's script config ──\n";
$absent = form_cfg( $BASE );
$zero   = form_cfg( $BASE + array( $SW => '0' ) );
$on     = form_cfg( $BASE + array( $SW => '1' ) );
check( 'A1 switch absent: no input_checks key', is_array( $absent ) && ! array_key_exists( 'input_checks', $absent ) );
check( 'A2 switch "0": no input_checks key', is_array( $zero ) && ! array_key_exists( 'input_checks', $zero ) );
check( 'A3 switch absent and "0" give the same config', $absent === $zero );
$ic = is_array( $on ) ? ( $on['input_checks'] ?? null ) : null;
check( 'A4 switch on: input_checks is there', is_array( $ic ) );
$rest = $on;
unset( $rest['input_checks'] );
check( 'A5 switch on: every other key is as with the switch absent', is_array( $on ) && $rest === $absent );
check( 'A6 the hard limits: systolic 250, diastolic 150 (a number at or above is never scored)', is_array( $ic ) && array( 'bpSystolic' => 250, 'bpDiastolic' => 150 ) === ( $ic['hard'] ?? null ) );
$usual = array( 'height' => array( 120, 220 ), 'weight' => array( 35, 250 ), 'waist' => array( 50, 180 ), 'hip' => array( 60, 180 ),
    'restingHeartRate' => array( 35, 130 ), 'bpSystolic' => array( 80, 200 ), 'bpDiastolic' => array( 50, 120 ) );
check( 'A7 the usual ranges, metric, the seven boxes (draft values of plan 11 § 5)', is_array( $ic ) && $usual === ( $ic['usual'] ?? null ) );
$want = array();
foreach ( array_keys( HDLV2_Rate_Calculator::FULL_WEIGHTS ) as $k ) $want[ $k ] = HDLV2_Email_Templates::score_key_to_label( $k );
check( 'A8 the labels: one per scored answer (21), from the one map', is_array( $ic ) && 21 === count( $want ) && $want === ( $ic['labels'] ?? null ) );
check( 'A9 the labels are words, never the raw keys', is_array( $ic ) && 'Blood pressure' === ( $ic['labels']['bloodPressureScore'] ?? '' ) && 'Balance' === ( $ic['labels']['balance'] ?? '' ) );
check( 'A10 input_checks() itself is the same array', is_array( $ic ) && method_exists( 'HDLV2_Staged_Form', 'input_checks' ) && HDLV2_Staged_Form::input_checks() === $ic );

echo "── B. the widget's public answer ──\n";
$pa = public_cfg( $BASE );
$pz = public_cfg( $BASE + array( $SW => '0' ) );
$po = public_cfg( $BASE + array( $SW => '1' ) );
check( 'B1 switch absent: no age_typed key', is_array( $pa ) && ! array_key_exists( 'age_typed', $pa ) );
check( 'B2 switch "0": no age_typed key, the answer as with it absent', is_array( $pz ) && wp_json_encode( $pz ) === wp_json_encode( $pa ) );
check( 'B3 switch on: age_typed is true', is_array( $po ) && true === ( $po['age_typed'] ?? null ) );
$po2 = $po;
unset( $po2['age_typed'] );
check( 'B4 switch on: one new key only', is_array( $po ) && wp_json_encode( $po2 ) === wp_json_encode( $pa ) ); // band_videos is an object: compare the JSON
$GLOBALS['wpdb']->invite = invite_row();
$link = $GLOBALS['wpdb']->invite->token; // the harness's fake invite link
$inv  = widget_instance()->rest_verify_invite( new FakeRequest( array( 'token' => $link ) ) );
$inv  = $inv instanceof WP_REST_Response ? $inv->get_data() : $inv;
check( 'B5 switch on: the invite answer does not carry it', is_array( $inv ) && true === ( $inv['valid'] ?? null ) && ! array_key_exists( 'age_typed', $inv ) );

echo "── C. the hard limits are the calculator's own ──\n";
function bp_score( $sys, $dia ) {
    $r = HDLV2_Rate_Calculator::calculate_full( 50, array( 'bpSystolic' => $sys, 'bpDiastolic' => $dia ), 'female' );
    return $r['scores']['bloodPressureScore'];
}
$h = is_array( $ic ) ? $ic['hard'] : array( 'bpSystolic' => 0, 'bpDiastolic' => 0 );
check( 'C1 systolic one under the limit (249) is scored', null !== bp_score( $h['bpSystolic'] - 1, '' ) );
check( 'C2 systolic at the limit (250) is not scored', null === bp_score( $h['bpSystolic'], '' ) );
check( 'C3 diastolic one under the limit (149) is scored', null !== bp_score( '', $h['bpDiastolic'] - 1 ) );
check( 'C4 diastolic at the limit (150) is not scored', null === bp_score( '', $h['bpDiastolic'] ) );
check( 'C5 0 is never scored either (the script refuses 0 and below)', null === bp_score( 0, 0 ) );

echo "── D. the calculation is not touched ──\n";
check( 'D1 class-hdlv2-rate-calculator.php md5 6a21550b3406c1207026c82c3fbf4be4', '6a21550b3406c1207026c82c3fbf4be4' === md5_file( __DIR__ . '/../../includes/sprint-2/class-hdlv2-rate-calculator.php' ) );
done();
