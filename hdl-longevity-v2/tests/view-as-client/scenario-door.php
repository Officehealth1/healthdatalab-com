<?php
/**
 * Plan E4-b (v0.48.40), Story 1, the server side: /dashboard/clients carries `view_as_url` per
 * client only with hdlv2_ff_view_as_client on (and the one-row page it opens). The real
 * rest_get_clients() with a pinned status (the scenario-roster-resend pattern), the real
 * HDLV2_Client_Dashboard::view_as_url() and HDLV2_Products flags.
 *   php tests/view-as-client/scenario-door.php
 */
error_reporting( E_ALL & ~E_DEPRECATED );
ini_set( 'error_log', '/dev/null' );
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function add_action( ...$a ) {}
function add_shortcode( ...$a ) {}
function register_rest_route( ...$a ) {}
function get_current_user_id() { return 122; }
function absint( $v ) { return abs( (int) $v ); }
function rest_ensure_response( $x ) { return $x; }
function get_user_meta( $id, $k, $single = false ) { return ''; }
function get_userdata( $id ) { return (object) array( 'ID' => $id, 'user_email' => "c$id@example.test", 'user_login' => "u$id", 'display_name' => 'Demo Owner' ); }
function wp_create_nonce( $action = '' ) { return substr( md5( $action . '|' . get_current_user_id() ), 0, 10 ); }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function apply_filters( $tag, $value ) { return $value; }
function add_query_arg( $key, $value, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value; }

class HDLV2_Compatibility {
    public static function get_clients_for_practitioner( $p ) { return array( 508, 600 ); }
    public static function is_practitioner( $u ) { return true; }
    public static function practitioner_owns_client( $p, $c ) { return true; }
}
/** 508 has live row 808; 600 has none (an invited client who never opened the link). */
class DoorWpdb {
    public $prefix = 'wp_';
    private $a = array();
    public function prepare( $q, ...$a ) { $this->a = $a; return $q; }
    public function get_var( $q ) { return null; }
    public function get_results( $q ) { return array(); }
    public function get_row( $q ) {
        if ( false !== strpos( $q, 'hdlv2_form_progress' ) && 508 === (int) ( $this->a[0] ?? 0 ) ) {
            return (object) array( 'id' => 808, 'stage1_completed_at' => '2026-11-01 10:00:00', 'stage2_completed_at' => null, 'stage3_completed_at' => null, 'visitor_country' => '', 'visitor_region' => '' );
        }
        return null;
    }
}
$GLOBALS['wpdb'] = new DoorWpdb();

$I = dirname( __DIR__, 2 ) . '/includes/';
require $I . 'sprint-4/class-hdlv2-products.php';
require $I . 'sprint-4/class-hdlv2-client-dashboard.php';
require $I . 'sprint-4/class-hdlv2-client-status.php';

class Door_Client_Status extends HDLV2_Client_Status {
    public function __construct() {}
    public static function calculate_status( $client_id ) { return array( 'status' => 'active', 'label' => 'L', 'color' => '#000', 'reasons' => array() ); }
    public static function resolve_resend_status( $client_id ) { return array( 'status' => 'active', 'has_report' => false ); }
}

$PASS = 0; $FAIL = 0;
function check( $label, $cond ) { global $PASS, $FAIL; $cond ? $PASS++ : $FAIL++; echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $label . "\n"; }
function rows() { return ( new Door_Client_Status() )->rest_get_clients( array() ); }
function flags( $view_as, $one_row = 1, $products = 1 ) {
    $GLOBALS['options'] = array( 'hdlv2_ff_view_as_client' => $view_as, 'hdlv2_ff_dashboard_one_row' => $one_row, 'hdlv2_ff_dashboard_products' => $products );
}

echo "D1 the clients payload carries the door only with the flag on\n";
flags( 0 );
$off = rows();
check( 'D1.1 flag off: no view_as_url key on any row (the payload is as before)', 2 === count( $off ) && ! array_key_exists( 'view_as_url', $off[0] ) && ! array_key_exists( 'view_as_url', $off[1] ) );
flags( 1 );
$on = rows();
check( 'D1.2 flag on: the client with a live row gets /my-dashboard/?as=808&_wpnonce=<nonce for hdlv2_view_as_808>',
    ( $on[0]['view_as_url'] ?? '' ) === 'https://example.test/my-dashboard/?as=808&_wpnonce=' . wp_create_nonce( 'hdlv2_view_as_808' ) );
check( 'D1.3 a client with no live row: no door', ! array_key_exists( 'view_as_url', $on[1] ) );
flags( 1, 0 );
check( 'D1.4 the one-row page off: no door (the view needs that page)', ! array_key_exists( 'view_as_url', rows()[0] ) );
flags( 1, 1, 0 );
check( 'D1.5 the products flag off: no door', ! array_key_exists( 'view_as_url', rows()[0] ) );
flags( 1 );
check( 'D1.6 view_as_url( 0 ) is empty', '' === HDLV2_Client_Dashboard::view_as_url( 0 ) );

echo "\nPASS=$PASS FAIL=$FAIL\n";
exit( $FAIL ? 1 : 0 );
