<?php
/**
 * v0.49.10 (plan 19; hdlv2_ff_stage1_result_one_column) — how the switch reaches the result page.
 * The page is drawn by the widget, so the switch rides in the widget's two settings answers:
 *   - absent or "0": neither answer gains a key (today's answers, key for key).
 *   - on: both carry result_one_column = true, and nothing else moves.
 * Drives the REAL rest_get_public_config and rest_verify_invite.
 *
 * Run:  php scenario-one-column-setting.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/../full-report/stubs.php';
load_sources();

function answers( $source ) {
    fresh_wpdb();
    $pub = widget_instance()->rest_get_public_config( new FakeRequest( array( 'practitioner_id' => 206 ) ) )->get_data()['config'];
    $GLOBALS['wpdb']->invite = invite_row( array( 'source' => $source ) );
    $link = TOKEN; // the harness's fake invite link
    $inv  = widget_instance()->rest_verify_invite( new FakeRequest( array( 'token' => $link ) ) );
    return array( $pub, $inv instanceof WP_REST_Response ? $inv->get_data() : $inv );
}

echo "── S. switch absent ──\n";
$GLOBALS['options'] = array( 'hdlv2_db_version' => '3.37' );
list( $pub, $inv ) = answers( 'paid_stage1' );
check( 'S.1 both answers come back', is_array( $pub ) && is_array( $inv ) && true === $inv['valid'] );
check( 'S.2 no result_one_column in either', ! array_key_exists( 'result_one_column', $pub ) && ! array_key_exists( 'result_one_column', $inv ) );
$GLOBALS['options']['hdlv2_ff_stage1_result_one_column'] = '0';
list( $pub0, $inv0 ) = answers( 'paid_stage1' );
check( 'S.3 switch "0": the same answers', $pub0 == $pub && $inv0 == $inv );

echo "── O. switch on ──\n";
$GLOBALS['options']['hdlv2_ff_stage1_result_one_column'] = '1';
list( $pub1, $inv1 ) = answers( 'paid_stage1' );
check( 'O.1 the public settings say so', true === ( $pub1['result_one_column'] ?? null ) );
check( 'O.2 a personal link\'s answer says so (a ticket)', true === ( $inv1['result_one_column'] ?? null ) );
check( 'O.3 it is the one new key in each answer', array( 'result_one_column' ) === array_keys( array_diff_key( $pub1, $pub ) ) && array( 'result_one_column' ) === array_keys( array_diff_key( $inv1, $inv ) ) );
list( , $prac ) = answers( 'practitioner' );
check( 'O.4 and a practitioner\'s invite', true === ( $prac['result_one_column'] ?? null ) );

echo "\nPASS=$PASS FAIL=$FAIL\n";
exit( $FAIL ? 1 : 0 );
