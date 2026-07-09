<?php
/**
 * Expiry-gate tests for HDLV2_Client_Draft_View::rest_get_draft().
 *
 * The REST route is token-authenticated (permission_callback __return_true),
 * so init auto-login never protects it — the handler itself must reject an
 * expired token, EXCEPT for a cookie-authenticated caller who is the client
 * themself or their owning practitioner (so practitioners can still open a
 * dormant client's report). Progress rows are scripted with
 * stage3_completed_at empty so a passed gate early-returns the cheap
 * 'incomplete' response — proving gate passage without the report pipeline.
 *
 * Run:  php scenario-draft-view.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
date_default_timezone_set( 'Etc/GMT-1' ); // UTC+1 year-round — catches missing " UTC"

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['t'] = array( 'uid' => 0 );

// ── WP stubs ──
function add_action() {}
function add_shortcode() {}
function register_rest_route() {}
function get_current_user_id() { return (int) $GLOBALS['t']['uid']; }
function is_user_logged_in() { return ( (int) $GLOBALS['t']['uid'] ) > 0; }
function rest_ensure_response( $x ) { return $x; }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : $s; }
function wp_unslash( $s ) { return $s; }
function esc_html( $s ) { return $s; }
function get_userdata( $id ) { return (object) array( 'ID' => $id, 'display_name' => 'P' ); }
function get_user_meta() { return ''; }
function get_option( $k, $d = false ) { return $d; }
function apply_filters( $t, $v ) { return $v; }
function wp_enqueue_script() {}
function wp_enqueue_style() {}
function wp_dequeue_script() {}
function wp_deregister_script() {}
function is_page() { return false; }

class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code = '', $message = '', $data = null ) {
        $this->code = $code; $this->message = $message; $this->data = $data;
    }
}

// Ownership: practitioner 3 owns client 42; nobody else owns anything.
class HDLV2_Compatibility {
    public static function is_practitioner( $uid ) { return in_array( (int) $uid, array( 3, 4 ), true ); }
    public static function practitioner_owns_client( $p, $c ) { return 3 === (int) $p && 42 === (int) $c; }
}

class FakeWpdb {
    public $prefix = 'wp_';
    public function prepare( $q, ...$args ) {
        foreach ( $args as $a ) {
            $q = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $q, 1 );
        }
        return $q;
    }
    public function get_row( $q ) {
        if ( false !== strpos( $q, 'hdlv2_form_progress' ) ) return $GLOBALS['t']['progress'] ?? null;
        return null;
    }
    public function get_var( $q ) { return null; }
    public function get_results( $q ) { return array(); }
}

class FakeRequest {
    private $params;
    public function __construct( $params ) { $this->params = $params; }
    public function get_param( $k ) { return $this->params[ $k ] ?? null; }
    public function get_json_params() { return $this->params; }
}

require dirname( __DIR__, 2 ) . '/includes/sprint-2c/class-hdlv2-client-draft-view.php';

global $wpdb;
$wpdb = new FakeWpdb();
$view = HDLV2_Client_Draft_View::get_instance();

$pass = 0; $fail = 0;
function ok( $name, $cond ) {
    global $pass, $fail;
    echo ( $cond ? 'PASS' : 'FAIL' ) . " | $name\n";
    $cond ? $pass++ : $fail++;
}

$hex = str_repeat( 'cd', 32 );
function progress_row( $expires_at ) {
    return (object) array(
        'id' => 55, 'client_user_id' => 42, 'practitioner_user_id' => 3,
        'client_name' => 'C', 'stage1_data' => null, 'stage3_data' => null,
        'stage3_completed_at' => null, // gate-passed ⇒ cheap 'incomplete' response
        'token_expires_at' => $expires_at,
    );
}
function run_case( $view, $hex, $uid, $expires_at ) {
    $GLOBALS['t']['uid']      = $uid;
    $GLOBALS['t']['progress'] = progress_row( $expires_at );
    return $view->rest_get_draft( new FakeRequest( array( 'token' => $hex ) ) );
}
function is_denied( $r ) { return $r instanceof WP_Error; }
function is_incomplete( $r ) { return is_array( $r ) && ( $r['status'] ?? '' ) === 'incomplete'; }

$past   = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
$nearok = gmdate( 'Y-m-d H:i:s', time() + 30 * MINUTE_IN_SECONDS ); // valid; catches missing " UTC"
$future = gmdate( 'Y-m-d H:i:s', time() + 80 * DAY_IN_SECONDS );

ok( 'expired + anonymous → denied',                       is_denied( run_case( $view, $hex, 0, $past ) ) );
ok( 'NULL expiry + anonymous → denied (fail closed)',     is_denied( run_case( $view, $hex, 0, null ) ) );
ok( 'expired + non-owning practitioner (uid 4) → denied', is_denied( run_case( $view, $hex, 4, $past ) ) );
ok( 'expired + the client themself (uid 42) → allowed',   is_incomplete( run_case( $view, $hex, 42, $past ) ) );
ok( 'expired + owning practitioner (uid 3) → allowed',    is_incomplete( run_case( $view, $hex, 3, $past ) ) );
ok( 'valid + anonymous → allowed',                        is_incomplete( run_case( $view, $hex, 0, $future ) ) );
ok( 'valid-for-30-more-min + anonymous → allowed (UTC parse)', is_incomplete( run_case( $view, $hex, 0, $nearok ) ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
