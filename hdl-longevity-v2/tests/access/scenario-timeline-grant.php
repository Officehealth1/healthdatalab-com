<?php
/**
 * Plan 14, A4: the Timeline grant. With hdlv2_ff_access_gate on, the owning practitioner or an admin can
 * give a client the Timeline without the $10 and take it back; HDLV2_Timeline_Upgrade::owned() (the
 * Timeline's one door) then answers as HDLV2_Access::decide() says. Two routes, practitioner sign-in,
 * ownership or admin, registered only with the switch on. The control on the practitioner's client panel
 * is server-built and asks in two steps on the page. Switch absent: no route, no control, and a stored
 * grant counts for nothing.
 *
 * Run: php tests/access/scenario-timeline-grant.php   Exit: 0 all pass, 1 any fail
 */
require __DIR__ . '/stubs.php';
class HDLV2_Healthspan_Timeline { public static function enabled() { return true; } }
ac_load( array( 'security/class-hdlv2-access.php', 'sprint-2c/class-hdlv2-timeline-upgrade.php' ) );
if ( ! class_exists( 'HDLV2_Access' ) ) { check( 'HDLV2_Access exists', false ); done(); }

function tl_world() {
    $w = ac_world();
    $GLOBALS['options']['hdlv2_ff_timeline_upgrade'] = '1';
    // Ivy bought the $27 without the $10; Rosa's ticket carries the Timeline; Finn was confirmed (no ticket).
    $w->t['widget_invites'][] = array( 'id' => 1, 'client_email' => 'ivy@example.test', 'source' => 'full_report', 'external_ref' => 'ref-ivy', 'timeline_paid_ref' => '', 'status' => 'used', 'practitioner_id' => 3 );
    $w->t['widget_invites'][] = array( 'id' => 2, 'client_email' => 'rosa@example.test', 'source' => 'full_report', 'external_ref' => 'ref-rosa', 'timeline_paid_ref' => 'included', 'status' => 'used', 'practitioner_id' => 3 );
    foreach ( $w->t['form_progress'] as $i => $r ) {
        $w->t['form_progress'][ $i ] += array( 'timeline_granted_by' => null, 'timeline_granted_at' => null, 'timeline_grant_revoked_at' => null );
    }
    return $w;
}
$owned = function ( $w, $id ) { return HDLV2_Timeline_Upgrade::owned( ac_progress( $w, $id ) ); };
$call  = function ( $route, $body ) {
    $r = $GLOBALS['routes'][ '/hdl-v2/v1/timeline-access/' . $route ] ?? null;
    if ( ! $r ) return new WP_Error( 'rest_no_route', 'No route.', array( 'status' => 404 ) );
    if ( ! call_user_func( $r['permission_callback'] ) ) return new WP_Error( 'rest_forbidden', 'No.', array( 'status' => 403 ) );
    return call_user_func( $r['callback'], new FakeRequest( $body ) );
};
$code = function ( $r ) { return $r instanceof WP_Error ? $r->get_error_code() : 'ok'; };

// ── Switch absent: today ──
$w = tl_world(); ac_switch( null ); $GLOBALS['routes'] = array();
HDLV2_Access::register_routes();
check( 'T.1 switch absent: Ivy (bought without the $10) does not own the Timeline; Finn and Rosa do', ! $owned( $w, 10 ) && $owned( $w, 12 ) && $owned( $w, 14 ) );
check( 'T.2 switch absent: neither route exists (404)', ! isset( $GLOBALS['routes']['/hdl-v2/v1/timeline-access/give'] ) && ! isset( $GLOBALS['routes']['/hdl-v2/v1/timeline-access/take-back'] ) );
$w->t['form_progress'][0]['timeline_granted_at'] = '2026-11-24 09:00:00'; $w->t['form_progress'][0]['timeline_granted_by'] = 3;
check( 'T.3 switch absent: a stored grant counts for nothing', ! $owned( $w, 10 ) );
check( 'T.4 switch absent: no control on the practitioner\'s panel', '' === HDLV2_Access::grant_control( 9 ) );

// ── Switch on ──
$w = tl_world(); ac_switch( '1' ); $GLOBALS['routes'] = array();
HDLV2_Access::register_routes();
check( 'T.5 switch on: both routes registered, POST only', 'POST' === ( $GLOBALS['routes']['/hdl-v2/v1/timeline-access/give']['methods'] ?? '' ) && 'POST' === ( $GLOBALS['routes']['/hdl-v2/v1/timeline-access/take-back']['methods'] ?? '' ) );
$GLOBALS['current_user'] = 9;
check( 'T.6 a client cannot give it to herself (403 at the permission check)', 'rest_forbidden' === $code( $call( 'give', array( 'progress_id' => 10 ) ) ) && ! $owned( $w, 10 ) );
$GLOBALS['current_user'] = 0;
check( 'T.7 signed out: 403', 'rest_forbidden' === $code( $call( 'give', array( 'progress_id' => 10 ) ) ) );
$GLOBALS['current_user'] = 4; $n = count( $w->writes );
check( 'T.8 another practitioner: 404, nothing written', 'not_found' === $code( $call( 'give', array( 'progress_id' => 10 ) ) ) && count( $w->writes ) === $n );
$GLOBALS['current_user'] = 3;
check( 'T.9 no form id: 400', 'missing' === $code( $call( 'give', array() ) ) );
check( 'T.10 the control offers "Give Timeline access" for Ivy, with her form id', false !== strpos( HDLV2_Access::grant_control( 9 ), 'Give Timeline access' ) && false !== strpos( HDLV2_Access::grant_control( 9 ), 'data-progress-id="10"' ) );
check( 'T.11 no control for Finn or Rosa (they own it already)', '' === HDLV2_Access::grant_control( 11 ) && '' === HDLV2_Access::grant_control( 13 ) );
$r = $call( 'give', array( 'progress_id' => 10 ) );
$row = ac_progress( $w, 10 );
check( 'T.12 her practitioner gives it: stamped by whom and when, not taken back', 'ok' === $code( $r ) && true === $r->data['granted'] && 3 === (int) $row->timeline_granted_by && '2026-11-24 09:14:05' === $row->timeline_granted_at && null === $row->timeline_grant_revoked_at );
check( 'T.13 Ivy now owns the Timeline (the door opens)', $owned( $w, 10 ) );
check( 'T.14 the control now offers "Take back Timeline access"', false !== strpos( HDLV2_Access::grant_control( 9 ), 'Take back Timeline access' ) );
$r = $call( 'take-back', array( 'progress_id' => 10 ) );
$row = ac_progress( $w, 10 );
check( 'T.15 taken back: the time stored, who gave it and when kept', 'ok' === $code( $r ) && false === $r->data['granted'] && '2026-11-24 09:14:05' === $row->timeline_grant_revoked_at && 3 === (int) $row->timeline_granted_by );
check( 'T.16 Ivy no longer owns it', ! $owned( $w, 10 ) );
$GLOBALS['current_user'] = 1;
$r = $call( 'give', array( 'progress_id' => 10 ) );
$row = ac_progress( $w, 10 );
check( 'T.17 an admin may give it again: stamped by the admin, the take-back cleared', 'ok' === $code( $r ) && 1 === (int) $row->timeline_granted_by && null === $row->timeline_grant_revoked_at && $owned( $w, 10 ) );
$w->t['form_progress'][0]['deleted_at'] = '2026-11-24 09:00:00';
check( 'T.18 a removed client: 404', 'not_found' === $code( $call( 'take-back', array( 'progress_id' => 10 ) ) ) );

// owned() and decide() agree on every case.
$agree = true;
foreach ( array( array( 10, false ), array( 10, true ), array( 12, false ), array( 14, false ) ) as $c ) {
    $w = tl_world(); ac_switch( '1' );
    if ( $c[1] ) { $w->t['form_progress'][0]['timeline_granted_at'] = '2026-11-24 09:00:00'; }
    $row   = ac_progress( $w, $c[0] );
    $today = ! HDLV2_Timeline_Upgrade::tickets( $row->client_email ) || (bool) array_filter( HDLV2_Timeline_Upgrade::tickets( $row->client_email ), function ( $t ) { return '' !== (string) $t->timeline_paid_ref; } );
    $want  = HDLV2_Access::OPEN === HDLV2_Access::decide( array( 'gate' => true, 'timeline_today' => $today, 'timeline_grant' => $c[1] ), 'timeline' );
    if ( $want !== HDLV2_Timeline_Upgrade::owned( $row ) ) $agree = false;
}
check( 'T.19 owned() answers as decide() for a buyer, a granted buyer, a confirmed client and an included ticket', $agree );

// The panel: the control rides on the practitioner's product strip; the script asks twice on the page.
$products = file_get_contents( ac_root() . '/includes/sprint-4/class-hdlv2-products.php' );
check( 'T.20 the practitioner\'s product strip carries the control', false !== strpos( $products, 'HDLV2_Access::grant_control( $client_id )' ) );
$js = file_get_contents( ac_root() . '/assets/js/hdlv2-client-list-enhance.js' );
preg_match( '/function bindTimelineGrant\(.*?\n  \}\n/s', $js, $m );
$fn = $m[0] ?? '';
check( 'T.21 the script asks in two steps on the page ("Yes, give access" / "Cancel"), no browser popup',
    '' !== $fn && false !== strpos( $fn, 'Yes, give access' ) && false !== strpos( $fn, 'Yes, take it back' ) && false !== strpos( $fn, 'Cancel' ) && false === strpos( $fn, 'window.confirm' ) && false === strpos( $fn, 'alert(' ) );
check( 'T.22 the script posts with the REST nonce to the two routes', false !== strpos( $fn, "'X-WP-Nonce'" ) && false !== strpos( $fn, "'/timeline-access/'" ) );

done();
