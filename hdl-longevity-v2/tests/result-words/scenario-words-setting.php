<?php
/**
 * v0.49.16 (plan 22, row W1; hdlv2_ff_stage1_result_words) — Matthew's own words from his drawing of
 * 26 Nov on the Stage 1 result page. The page is drawn by the widget, so the switch reaches it in the widget's two
 * settings answers, as the film's cover and the gauge's scale do.
 *   - switch absent (or "0"): neither answer gains a key; both are as before, value for value.
 *   - switch on: both answers carry result_words => true, and that is the one new key.
 * Drives the REAL rest_get_public_config and rest_verify_invite.
 *
 * Run:  php scenario-words-setting.php   (self-asserting, exit 0/1)
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
// The plugin's version rides in both answers (asset_ver) and moves with every release: left out of the pin.
function pin( $answer ) {
    unset( $answer['asset_ver'] );
    return md5( json_encode( $answer ) );
}

echo "── S. switch absent ──\n";
$GLOBALS['options'] = array( 'hdlv2_db_version' => '3.37' );
list( $pub, $inv ) = answers();
check( 'S.1 both answers come back', is_array( $pub ) && is_array( $inv ) && true === $inv['valid'] );
check( 'S.2 no result_words in the public settings', is_array( $pub ) && ! array_key_exists( 'result_words', $pub ) );
check( 'S.3 no result_words in the invite answer', is_array( $inv ) && ! array_key_exists( 'result_words', $inv ) );
// md5 of each answer before a line of plan 22 was written (the same as 1f948ac's: plan 21's key only shows with its own switch on).
check( 'S.4 the public settings are as before, value for value', '1ede787e7f5f19168a44cdd75d103a1d' === pin( $pub ) );
check( 'S.5 the invite answer is as before, value for value', 'acb7f8231192f4d061d83836d9d5b563' === pin( $inv ) );
$GLOBALS['options']['hdlv2_ff_stage1_result_words'] = '0';
list( $pub0, $inv0 ) = answers();
check( 'S.6 switch "0": the same', $pub0 == $pub && $inv0 == $inv );

echo "── O. switch on ──\n";
$GLOBALS['options']['hdlv2_ff_stage1_result_words'] = '1';
list( $pub1, $inv1 ) = answers();
check( 'O.1 the public settings carry result_words, true', true === ( $pub1['result_words'] ?? null ) );
check( 'O.2 the invite answer carries the same', true === ( $inv1['result_words'] ?? null ) );
check( 'O.3 it is the one new key in each answer, and nothing else moved',
    array( 'result_words' ) === array_keys( array_diff_key( $pub1, $pub ) ) && array( 'result_words' ) === array_keys( array_diff_key( $inv1, $inv ) )
    && array_diff_key( $pub1, array( 'result_words' => 1 ) ) == $pub && array_diff_key( $inv1, array( 'result_words' => 1 ) ) == $inv );

echo "\nPASS=$PASS FAIL=$FAIL\n";
exit( $FAIL ? 1 : 0 );
