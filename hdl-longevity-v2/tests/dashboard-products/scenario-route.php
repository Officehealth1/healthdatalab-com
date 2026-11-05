<?php
/**
 * Story 2: GET /dashboard/client/{id}/products — the practitioner's copy of the client's strip.
 *   php tests/dashboard-products/scenario-route.php
 */
require __DIR__ . '/stubs.php';

if ( ! class_exists( 'HDLV2_Products' ) ) {
    check( 'includes/sprint-4/class-hdlv2-products.php exists', false );
    done();
}

$ROUTE = 'hdl-v2/v1/dashboard/client/(?P<id>\d+)/products';

/** As WordPress: a false permission is 401 signed out, 403 signed in; then the handler. */
function call_route( $id ) {
    global $ROUTE;
    $r = $GLOBALS['routes'][ $ROUTE ] ?? null;
    if ( ! $r ) return 404;
    if ( ! call_user_func( $r['permission_callback'] ) ) return is_user_logged_in() ? 403 : 401;
    return call_user_func( $r['callback'], array( 'id' => (string) $id ) );
}
function status( $res ) { return is_int( $res ) ? $res : ( $res instanceof WP_Error ? (int) $res->data['status'] : 200 ); }

$wpdb = fresh();
client( 601, 122, 3 );
$wpdb->reports[901] = (object) array( 'id' => 90, 'report_type' => 'final', 'report_content' => '{}' );
$GLOBALS['owns'][] = '122|601';

echo "T1 flag off: the route is not registered\n";
$GLOBALS['options']['hdlv2_ff_dashboard_products'] = 0;
$GLOBALS['routes'] = array();
HDLV2_Products::register_routes();
check( 'T1.1 not registered', ! isset( $GLOBALS['routes'][ $ROUTE ] ) );
$GLOBALS['current_user'] = 122;
check( 'T1.2 the owner gets 404', 404 === status( call_route( 601 ) ) );

echo "T2 flag on\n";
$GLOBALS['options']['hdlv2_ff_dashboard_products'] = 1;
HDLV2_Products::register_routes();
check( 'T2.1 registered, GET', 'GET' === ( $GLOBALS['routes'][ $ROUTE ]['methods'] ?? '' ) );

$GLOBALS['current_user'] = 122;
$res = call_route( 601 );
check( 'T2.2 owner: 200', 200 === status( $res ) );
$html = is_array( $res ) ? ( $res['products_html'] ?? '' ) : '';
check( 'T2.3 owner: products_html is the practitioner strip', false !== strpos( $html, 'aria-label="What this client has"' ) && 5 === substr_count( $html, 'class="cdp-tile ' ) );
check( 'T2.4 owner: the same words the client sees', false !== strpos( $html, '>Final<' ) && false !== strpos( $html, 'After your consultation' ) );
check( 'T2.5 no link and no client token in the response', false === strpos( $html, 'href=' ) && ! preg_match( '/[a-f0-9]{64}/', json_encode( $res ) ) );
check( 'T2.6 only products_html in the body', is_array( $res ) && array( 'products_html' ) === array_keys( $res ) );

$GLOBALS['current_user'] = 130;
check( 'T3 another practitioner: 404', 404 === status( call_route( 601 ) ) );
check( 'T3.1 an unknown client id: 404', 404 === status( call_route( 999 ) ) );
check( 'T3.2 id 0: 404', 404 === status( call_route( 0 ) ) );

$GLOBALS['current_user'] = 601;
check( 'T4 the client themself (not staff): 403', 403 === status( call_route( 601 ) ) );

$GLOBALS['current_user'] = 0;
check( 'T5 signed out: 401', 401 === status( call_route( 601 ) ) );

$GLOBALS['current_user'] = 1;
check( 'T6 an administrator: 200', 200 === status( call_route( 601 ) ) );

require_once dirname( __DIR__, 2 ) . '/includes/security/class-hdlv2-rate-limit-policy.php';
check( 'T7 practitioner-read budget (as the roster), not the shared read one', HDLV2_Rate_Limit_Policy::is_prac_read_route( 'GET', '/hdl-v2/v1/dashboard/client/601/products' ) );
check( 'T7.1 a POST is not', ! HDLV2_Rate_Limit_Policy::is_prac_read_route( 'POST', '/hdl-v2/v1/dashboard/client/601/products' ) );

done();
