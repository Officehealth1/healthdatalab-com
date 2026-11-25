<?php
/**
 * Plan 15 T1: POST /consultation/live-questions/auto, the questions asked by themselves while listening.
 *   A.1 the same callback as the manual route (no second handler), and it answers as the manual one does.
 *   A.2 tiers: auto on listen-ask, the piece on listen, the manual route still on AI-burn.
 *   A.3 the middleware: 40 automatic asks an hour per practitioner, the 41st refused with Retry-After; the
 *       manual route is not touched by them (its own bucket).
 *   A.4 the piece route: 240 an hour per practitioner, the 241st refused.
 *   A.5 the two numbers are named constants in the policy.
 * Run: php tests/live-listen/scenario-auto-route.php   Exit: 0 all pass, 1 any fail
 */
require __DIR__ . '/ll-world.php';
const R_PIECE = '/hdl-v2/v1/audio/listen-piece';
const R_AUTO  = '/hdl-v2/v1/consultation/live-questions/auto';
const R_LQ    = '/hdl-v2/v1/consultation/live-questions';
$have = class_exists( 'HDLV2_Live_Listen' ) && defined( 'HDLV2_Rate_Limit_Policy::TIER_LISTEN' );
check( 'the class and the listen tiers exist', $have );
if ( ! $have ) done();
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
ha_world(); lq_switch( '1' ); ll_switch( '1' ); ll_boot();

echo "── A.1 one handler ──\n";
check( 'A.1a the auto route runs HDLV2_Live_Questions::rest_suggest, as the manual one', array( 'HDLV2_Live_Questions', 'rest_suggest' ) === ( $GLOBALS['routes'][ R_AUTO ]['callback'] ?? null ) && $GLOBALS['routes'][ R_AUTO ]['callback'] === $GLOBALS['routes'][ R_LQ ]['callback'] );
$GLOBALS['ai_replies'] = array( "Q: How do you sleep? | Why: wakes at 3 am" );
list( $s, $d ) = ll_call( R_AUTO, 3, array( 'progress_id' => 10, 'heard' => 'She wakes at 3 am.' ) );
check( 'A.1b it answers with the questions', 200 === $s && 1 === count( $d['questions'] ?? array() ) );

echo "── A.2 tiers ──\n";
check( 'A.2 auto: listen-ask; piece: listen; manual: ai-burn', HDLV2_Rate_Limit_Policy::TIER_LISTEN_ASK === HDLV2_Rate_Limit_Policy::tier_for_request( 'POST', R_AUTO )
    && HDLV2_Rate_Limit_Policy::TIER_LISTEN === HDLV2_Rate_Limit_Policy::tier_for_request( 'POST', R_PIECE )
    && HDLV2_Rate_Limit_Policy::TIER_AI_BURN === HDLV2_Rate_Limit_Policy::tier_for_request( 'POST', R_LQ ) );

function mw( $route, $uid ) { $GLOBALS['current_user'] = $uid; return HDLV2_Rate_Limit_Middleware::check_request( null, null, new LL_Request( array(), array(), array(), $route ) ); }
echo "── A.3 forty an hour ──\n";
$GLOBALS['transients'] = array();
$ok = true;
for ( $i = 1; $i <= 40; $i++ ) $ok = $ok && null === mw( R_AUTO, 3 );
$r41 = mw( R_AUTO, 3 );
check( 'A.3a forty pass', $ok );
check( 'A.3b the 41st: 429 with Retry-After, tier listen-ask', $r41 instanceof WP_REST_Response && 429 === $r41->get_status() && (int) ( $r41->headers['Retry-After'] ?? 0 ) > 0 && 'listen-ask' === ( $r41->headers['X-RateLimit-Tier'] ?? '' ) );
check( 'A.3c the manual route still passes (its own bucket)', null === mw( R_LQ, 3 ) );
check( 'A.3d another practitioner has their own forty', null === mw( R_AUTO, 4 ) );

echo "── A.4 two hundred and forty an hour ──\n";
$GLOBALS['transients'] = array();
$ok = true;
for ( $i = 1; $i <= 240; $i++ ) $ok = $ok && null === mw( R_PIECE, 3 );
$r = mw( R_PIECE, 3 );
check( 'A.4 240 pass, the 241st is refused', $ok && $r instanceof WP_REST_Response && 429 === $r->get_status() && 'listen' === ( $r->headers['X-RateLimit-Tier'] ?? '' ) );

echo "── A.5 named numbers ──\n";
check( 'A.5 LISTEN_PER_HOUR = 240, LISTEN_ASK_PER_HOUR = 40', 240 === HDLV2_Rate_Limit_Policy::LISTEN_PER_HOUR && 40 === HDLV2_Rate_Limit_Policy::LISTEN_ASK_PER_HOUR );
done();
