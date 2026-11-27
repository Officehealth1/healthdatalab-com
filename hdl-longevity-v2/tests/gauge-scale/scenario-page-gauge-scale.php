<?php
/**
 * v0.49.09 (plan 18, row J2; hdlv2_ff_gauge_scale) — the scale on the result PAGE's gauge.
 * The page's gauge is drawn by the widget, so the four marks reach it in the widget's two settings
 * answers, from the same function the PDF's gauge takes them from: one place knows where they sit.
 *   - switch absent: neither answer gains a key, and the PDF gauge reads no scale.
 *   - switch on: both answers carry gauge_scale, equal to the parts the PDF's gauge is drawn with.
 *   - the PDF gauge's address, switch on, is 835cba8's character for character (nothing moved there).
 * Drives the REAL rest_get_public_config, rest_verify_invite and stage1_report_gauge_url.
 *
 * Run:  php scenario-page-gauge-scale.php          (self-asserting, exit 0/1)
 *       php scenario-page-gauge-scale.php --emit   (prints the switch-on gauge_scale as the server sends it;
 *                                                    test-page-gauge.mjs feeds it to the widget's own gaugeUrl)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/../full-report/stubs.php';
load_sources();

function answers() {
    fresh_wpdb();
    $pub = widget_instance()->rest_get_public_config( new FakeRequest( array( 'practitioner_id' => 206 ) ) )->get_data()['config'];
    $GLOBALS['wpdb']->invite = invite_row();
    $link = TOKEN; // the harness's fake invite link
    $inv  = widget_instance()->rest_verify_invite( new FakeRequest( array( 'token' => $link ) ) );
    return array( $pub, $inv instanceof WP_REST_Response ? $inv->get_data() : $inv );
}
function pdf_parts( $rate = 1.11 ) {
    parse_str( (string) parse_url( HDLV2_Widget_Config::stage1_report_gauge_url( $rate ), PHP_URL_QUERY ), $q );
    return json_decode( $q['c'], true )['data']['datasets'][0];
}

$GLOBALS['options'] = array( 'hdlv2_db_version' => '3.37', 'hdlv2_ff_gauge_scale' => '1' );
if ( in_array( '--emit', $argv, true ) ) {
    echo json_encode( answers()[0]['gauge_scale'] ?? null );
    exit( 0 );
}

echo "── S. switch absent ──\n";
$GLOBALS['options'] = array( 'hdlv2_db_version' => '3.37' );
list( $pub, $inv ) = answers();
check( 'S.1 both answers come back', is_array( $pub ) && is_array( $inv ) && true === $inv['valid'] );
check( 'S.2 no gauge_scale in the public settings', is_array( $pub ) && ! array_key_exists( 'gauge_scale', $pub ) );
check( 'S.3 no gauge_scale in the invite answer', is_array( $inv ) && ! array_key_exists( 'gauge_scale', $inv ) );
$GLOBALS['options']['hdlv2_ff_gauge_scale'] = '0';
list( $pub0, $inv0 ) = answers();
check( 'S.4 switch "0": the same', $pub0 == $pub && $inv0 == $inv );

echo "── O. switch on ──\n";
$GLOBALS['options']['hdlv2_ff_gauge_scale'] = '1';
list( $pub1, $inv1 ) = answers();
$scale = $pub1['gauge_scale'] ?? null;
check( 'O.1 the public settings carry gauge_scale', is_array( $scale ) && array( 'data', 'backgroundColor', 'borderWidth', 'datalabels' ) === array_keys( $scale ) );
check( 'O.2 the invite answer carries the same', is_array( $scale ) && $scale === ( $inv1['gauge_scale'] ?? null ) );
check( 'O.3 it is the one new key in each answer', array( 'gauge_scale' ) === array_keys( array_diff_key( $pub1, $pub ) ) && array( 'gauge_scale' ) === array_keys( array_diff_key( $inv1, $inv ) ) );
$pdf = pdf_parts();
check( 'O.4 ONE place: the page\'s scale is the PDF gauge\'s parts, value for value',
    is_array( $scale ) && $scale == array_intersect_key( $pdf, $scale ) && json_encode( $scale ) === json_encode( array_intersect_key( $pdf, $scale ) ) );
check( 'O.5 parts at 0.8, 0.9, 0.9, 1.1, 1.1, 1.4, 1.4', array( 0.8, 0.9, 0.9, 1.1, 1.1, 1.4, 1.4 ) === ( $scale['data'] ?? null ) );

echo "── P. the PDF's gauge did not move ──\n";
// md5 of stage1_report_gauge_url( rate ) with the switch on, at 835cba8.
foreach ( array( '0.86' => 'a51e961b1ca181d979664d5ce76dac29', '1.11' => 'ca970fb75f978d8877d83961663351a8', '1.25' => 'deb9571b3305d645d725c0e36398f3af' ) as $rate => $md5 ) {
    check( "P.1 switch on, rate $rate: the address is 835cba8's, character for character", $md5 === md5( HDLV2_Widget_Config::stage1_report_gauge_url( (float) $rate ) ) );
}

echo "\nPASS=$PASS FAIL=$FAIL\n";
exit( $FAIL ? 1 : 0 );
