<?php
/**
 * v0.48.80 (plan 2f) — the widget's public settings answer says whether a visitor may continue where they
 * stopped (resume_enabled, from hdlv2_ff_stage1_resume). The invite answer never carries it: an invited
 * person's answers are not kept in the browser.
 * Drives the REAL rest_get_public_config and rest_verify_invite.
 *
 * Run:  php scenario-public-config.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/../full-report/stubs.php';
load_sources();

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
$BASE = array( 'hdlv2_db_version' => '3.29' );

echo "── A. switch absent ──\n";
fresh_wpdb();
$GLOBALS['options'] = $BASE;
$c = public_cfg();
check( 'A.1 public config answers', is_array( $c ) );
check( 'A.2 resume_enabled is there and false', is_array( $c ) && array_key_exists( 'resume_enabled', $c ) && false === $c['resume_enabled'] );

echo "── B. switch on ──\n";
fresh_wpdb();
$GLOBALS['options'] = $BASE + array( 'hdlv2_ff_stage1_resume' => '1' );
$c = public_cfg();
check( 'B.1 resume_enabled is true', is_array( $c ) && true === ( $c['resume_enabled'] ?? null ) );
foreach ( array( 'paid_stage1', 'practitioner', 'challenge' ) as $source ) {
    $i = invite_cfg( $source );
    check( "B.2 the invite answer ($source) never carries it", is_array( $i ) && true === ( $i['valid'] ?? null ) && ! array_key_exists( 'resume_enabled', $i ) );
}

echo "── C. switch set to 0 ──\n";
fresh_wpdb();
$GLOBALS['options'] = $BASE + array( 'hdlv2_ff_stage1_resume' => '0' );
$c = public_cfg();
check( 'C.1 resume_enabled is false', is_array( $c ) && false === ( $c['resume_enabled'] ?? null ) );
done();
