<?php
/**
 * Rate limits for a spike (v0.48.16): the config call is uncounted and
 * cacheable for five minutes, the lead tier is 60 an hour per real address,
 * and the ticket route keys on the real address. Real policy, middleware,
 * limiter and widget config.
 *
 * Run:  php scenario-rate-limit.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
ini_set( 'error_log', '/dev/null' );
require __DIR__ . '/stubs.php';
load_sources();
$GLOBALS['options'] = array( 'hdlv2_db_version' => '3.30', 'hdlv2_rate_limit_disabled_tiers' => '' );
$P = 'HDLV2_Rate_Limit_Policy';

echo "── L1. tiers ──\n";
check( 'L1.1 GET /widget/public-config is TIER_BYPASS', $P::TIER_BYPASS === $P::tier_for_request( 'GET', '/hdl-v2/v1/widget/public-config' ) );
check( 'L1.2 POST /widget/lead stays TIER_PUBLIC', $P::TIER_PUBLIC === $P::tier_for_request( 'POST', '/hdl-v2/v1/widget/lead' ) );
check( 'L1.3 TIER_PUBLIC is 60 an hour per address', 60 === $P::tier_config( $P::TIER_PUBLIC )['per_ip_limit'] );

echo "── L2. the 61st lead from one address in an hour ──\n";
fresh_wpdb( 'open' );
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.9';
$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
$last = null; $allowed = 0;
for ( $i = 1; $i <= 61; $i++ ) {
    $req  = new FakeRequest( lead_params( "rl$i@example.test" ), array(), 'POST', '/hdl-v2/v1/widget/lead' );
    $last = HDLV2_Rate_Limit_Middleware::check_request( null, null, $req );
    if ( null === $last ) $allowed++;
}
check( 'L2.1 60 allowed, the 61st refused', 60 === $allowed && null !== $last && 429 === status_of( $last ) );
$req = new FakeRequest( array(), array(), 'GET', '/hdl-v2/v1/widget/public-config', array( 'practitioner_id' => 206 ) );
check( 'L2.2 the config call is still let through after that (only the 500/hr backstop counts it)', null === HDLV2_Rate_Limit_Middleware::check_request( null, null, $req ) );

echo "── L3. route coverage ──\n";
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/../route-coverage/test-route-coverage.php' ) . ' 2>/dev/null', $out, $code );
check( 'L3.1 the route-coverage guard passes', 0 === $code );

echo "── L4. the ticket route keys on the real address ──\n";
$src = file_get_contents( __DIR__ . '/../../includes/security/class-hdl-stage1-ticket.php' );
check( 'L4.1 bucket uses HDLV2_Rate_Limit_Middleware::get_ip()', false !== strpos( $src, '$ip = HDLV2_Rate_Limit_Middleware::get_ip();' ) && false === strpos( $src, "\$_SERVER['REMOTE_ADDR']" ) );
check( 'L4.2 get_ip() prefers CF-Connecting-IP over REMOTE_ADDR', '203.0.113.9' === HDLV2_Rate_Limit_Middleware::get_ip() );
$cfg = file_get_contents( __DIR__ . '/../../includes/sprint-1/class-hdlv2-widget-config.php' );
check( 'L4.3 the lead route keeps no edge-keyed counter of its own', false === strpos( $cfg, "'hdlv2_lead_' . md5(" ) );

echo "── L5. the config reply is cacheable ──\n";
$wpdb = fresh_wpdb( 'open' );
$r = widget_instance()->rest_get_public_config( new FakeRequest( array(), array(), 'GET', '/hdl-v2/v1/widget/public-config', array( 'practitioner_id' => 206 ) ) );
check( 'L5.1 config → WP_REST_Response with Cache-Control: public, max-age=300, s-maxage=300', $r instanceof WP_REST_Response && 'public, max-age=300, s-maxage=300' === ( $r->headers['Cache-Control'] ?? '' ) && is_array( $r->get_data()['config'] ) );
$r = widget_instance()->rest_get_public_config( new FakeRequest( array(), array(), 'GET', '/hdl-v2/v1/widget/public-config', array( 'practitioner_id' => 999 ) ) );
check( 'L5.2 config: null → same header', $r instanceof WP_REST_Response && null === $r->get_data()['config'] && 'public, max-age=300, s-maxage=300' === ( $r->headers['Cache-Control'] ?? '' ) );
$loader = file_get_contents( __DIR__ . '/../../hdl-longevity-v2.php' );
$filter = substr( $loader, strpos( $loader, "add_filter( 'rest_post_dispatch'" ), 900 );
check( 'L5.3 the no-store filter skips only /widget/public-config, before writing no-store',
    false !== strpos( $filter, "if ( '/hdl-v2/v1/widget/public-config' === \$route ) {" )
    && strpos( $filter, "'/hdl-v2/v1/widget/public-config' === \$route" ) < strpos( $filter, 'no-store' ) );
done();
