<?php
/**
 * v0.48.77 (plan 2c) — what the widget's two config answers carry for the result page.
 * hdlv2_ff_result_offer absent → no result_offer; on and the offer applying → result_offer
 * { today, band_urls { green, blue, red }, video_url }; on but another practitioner → absent.
 * No timeline_url in either answer, whatever the switches (the teaser left the result page).
 * Drives the REAL rest_get_public_config and rest_verify_invite.
 *
 * Run:  php scenario-public-config.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/../full-report/stubs.php';
load_sources();
$ROOT = __DIR__ . '/../../';
if ( ! function_exists( 'home_url' ) ) {
    function home_url( $p = '' ) { return 'https://stby.test' . $p; }
}
require_once $ROOT . 'includes/sprint-2c/class-hdlv2-healthspan-timeline.php';

function public_cfg() {
    $r = widget_instance()->rest_get_public_config( new FakeRequest( array(), array(), 'GET', '', array( 'practitioner_id' => 206 ) ) );
    $d = $r instanceof WP_REST_Response ? $r->get_data() : $r;
    return $d['config'] ?? null;
}
function invite_cfg( $source = 'paid_stage1' ) {
    $GLOBALS['wpdb']->invite = invite_row( array( 'source' => $source ) );
    $link = TOKEN; // the harness's fake invite link
    $r    = widget_instance()->rest_verify_invite( new FakeRequest( array( 'token' => $link ) ) );
    return $r instanceof WP_REST_Response ? $r->get_data() : $r;
}
$BASE = array( 'hdlv2_db_version' => '3.29', 'hdlv2_stage1_offer' => array( 'practitioner_id' => 206, 'video_url' => 'https://youtu.be/iiPyoM0unbY' ) );

echo "── S. switch absent ──\n";
fresh_wpdb();
$GLOBALS['options'] = $BASE;
$c = public_cfg();
$i = invite_cfg();
check( 'S.1 public config answers', is_array( $c ) );
check( 'S.2 no result_offer in the public config', is_array( $c ) && ! array_key_exists( 'result_offer', $c ) );
check( 'S.3 no result_offer in the invite answer', is_array( $i ) && true === $i['valid'] && ! array_key_exists( 'result_offer', $i ) );

echo "── O. switch on, the offer applying ──\n";
fresh_wpdb();
$GLOBALS['options'] = $BASE + array( 'hdlv2_ff_result_offer' => '1' );
$want = array(
    'today'     => '$27',
    'band_urls' => array(
        'green' => 'https://altituding.com/full-report-slower',
        'blue'  => 'https://altituding.com/full-report-average',
        'red'   => 'https://altituding.com/full-report-faster',
    ),
    'video_url' => 'https://youtu.be/iiPyoM0unbY',
);
$c = public_cfg();
check( 'O.1 public config: result_offer = { today, band_urls, video_url }', is_array( $c ) && ( $c['result_offer'] ?? null ) === $want );
$i = invite_cfg();
check( 'O.2 invite answer: the same result_offer', is_array( $i ) && ( $i['result_offer'] ?? null ) === $want );
check( 'O.3 no e-mail, rate or code in result_offer', false === strpos( json_encode( $c['result_offer'] ?? array() ), '@' ) && false === strpos( json_encode( $c['result_offer'] ?? array() ), 'code' ) );

echo "── N. switch on, another practitioner ──\n";
fresh_wpdb();
$GLOBALS['options'] = array( 'hdlv2_db_version' => '3.29', 'hdlv2_ff_result_offer' => '1', 'hdlv2_stage1_offer' => array( 'practitioner_id' => 122 ) );
$c = public_cfg();
$i = invite_cfg();
check( 'N.1 public config: absent', is_array( $c ) && ! array_key_exists( 'result_offer', $c ) );
check( 'N.2 invite answer: absent', is_array( $i ) && ! array_key_exists( 'result_offer', $i ) );

echo "── T. no timeline_url anywhere ──\n";
$seen = array();
foreach ( array( '0', '1' ) as $tl ) {
    foreach ( array( '0', '1' ) as $ro ) {
        fresh_wpdb();
        $GLOBALS['options'] = $BASE + array( 'hdlv2_ff_healthspan_timeline' => $tl, 'hdlv2_ff_result_offer' => $ro );
        $c = public_cfg();
        $i = invite_cfg();
        $seen[] = is_array( $c ) && ! array_key_exists( 'timeline_url', $c ) && is_array( $i ) && ! array_key_exists( 'timeline_url', $i );
    }
}
check( 'T.1 four switch states × two answers: no timeline_url', array( true, true, true, true ) === $seen );
check( 'T.2 the Timeline no longer offers a widget config field', ! method_exists( 'HDLV2_Healthspan_Timeline', 'public_config_fields' ) );
done();
