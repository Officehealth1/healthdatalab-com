<?php
/**
 * P1b — rest_resend_link() handler concerns that don't depend on live data:
 * cross-client 403 (permission_callback), 10/hr rate limit (429), the disabled
 * / no-assessment refusals (422), and the enabled happy path (token rotated +
 * invalidated, email sent to the right recipient, audit written, descriptive
 * response). Standalone: fake wpdb + WP stubs, wp_mail CAPTURED not sent.
 *
 * calculate_status is complex + DB-driven, so a Testable subclass overrides it
 * (the handler calls it via static:: precisely so this is possible) to pin the
 * status per scenario; the stage→behaviour mapping itself is covered
 * exhaustively by scenario-descriptor.php.
 *
 * Run:  php tests/resend-link/scenario-handler.php   (exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HDLV2_CLIENT_TOKEN_TTL_DAYS', 90 );

// ── captured side-effects ──
$GLOBALS['cap'] = array( 'mail' => null, 'timeline' => null, 'update' => null, 'routes' => array() );
$GLOBALS['scn'] = array( 'owns' => true, 'rate_ok' => true, 'progress' => null, 'has_plan' => false, 'status' => 'not_started', 'uid' => 20 );

// ── WP stubs ──
function add_action() {}
function register_rest_route( $ns, $route, $args ) { $GLOBALS['cap']['routes'][ $route ] = $args; }
function get_current_user_id() { return (int) $GLOBALS['scn']['uid']; }
function absint( $v ) { return abs( (int) $v ); }
function is_email( $e ) { return is_string( $e ) && strpos( $e, '@' ) !== false; }
function get_userdata( $id ) { return (object) array( 'ID' => $id, 'user_email' => 'acct+' . $id . '@example.com', 'user_login' => 'u' . $id ); }
function site_url( $p = '' ) { return 'https://stby.example' . $p; }
function esc_html( $s ) { return $s; }
function esc_url( $s ) { return $s; }
function wp_mail( $to, $subject, $html, $headers = array() ) { $GLOBALS['cap']['mail'] = compact( 'to', 'subject', 'html', 'headers' ); return true; }
function rest_ensure_response( $x ) { return $x; }

class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function status() { return isset( $this->data['status'] ) ? (int) $this->data['status'] : 0; }
}

class HDL_Rate_Limiter {
    public function check_limit( $action, $id, $max, $window ) { return (bool) $GLOBALS['scn']['rate_ok']; }
}
class HDLV2_Compatibility {
    public static function is_practitioner( $u ) { return true; }
    public static function practitioner_owns_client( $p, $c ) { return (bool) $GLOBALS['scn']['owns']; }
}
class HDLV2_Email_Templates {
    public static function derive_first_name( $n, $e = '' ) { return 'Sam'; }
    public static function base_layout( $c, $p = null, $b = '', $pre = '' ) { return '<html>' . $c . '</html>'; }
}
class HDLV2_Timeline {
    public static function add_entry( $client_id, $prac_id, $type, $title, $summary = '', $detail = null, $st = '', $sid = null, $flags = false, $priv = false ) {
        $GLOBALS['cap']['timeline'] = compact( 'client_id', 'prac_id', 'type', 'title', 'summary' );
    }
}

class FakeWpdb {
    public $prefix = 'wp_';
    public function prepare( $q ) { return $q; }
    public function get_row( $q ) {
        if ( strpos( $q, 'hdlv2_form_progress' ) !== false ) { return $GLOBALS['scn']['progress']; }
        return null;
    }
    public function get_var( $q ) {
        if ( strpos( $q, 'hdlv2_flight_plans' ) !== false ) { return $GLOBALS['scn']['has_plan'] ? 1 : null; }
        return null;
    }
    public function update( $table, $data, $where, $df = null, $wf = null ) { $GLOBALS['cap']['update'] = compact( 'table', 'data', 'where' ); return 1; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

require __DIR__ . '/../../includes/sprint-4/class-hdlv2-client-status.php';

// Testable: pin calculate_status (the handler calls it via static::).
class Testable_Client_Status extends HDLV2_Client_Status {
    public function __construct() {}
    public static function calculate_status( $client_id ) { return array( 'status' => $GLOBALS['scn']['status'] ); }
}

$svc = new Testable_Client_Status();
$svc->register_rest_routes(); // populates $GLOBALS['cap']['routes']

$pass = 0; $fail = 0;
function ok( $name, $cond, $detail = '' ) {
    global $pass, $fail;
    echo ( $cond ? 'PASS' : 'FAIL' ) . " | $name" . ( $cond ? '' : " | $detail" ) . "\n";
    $cond ? $pass++ : $fail++;
}
function reset_caps() { $GLOBALS['cap']['mail'] = $GLOBALS['cap']['timeline'] = $GLOBALS['cap']['update'] = null; }
function progress_row() { return (object) array( 'id' => 777, 'token' => 'OLDOLDOLD', 'client_email' => 'client@example.com', 'client_name' => 'Sam Client', 'current_stage' => 1 ); }

// ── (1) cross-client 403 — permission_callback rejects a non-owner ──
$perm = $GLOBALS['cap']['routes']['/dashboard/client/(?P<client_id>\d+)/resend-link']['permission_callback'];
$GLOBALS['scn']['owns'] = false;
ok( '403: non-owner permission_callback = false', $perm( array( 'client_id' => 55 ) ) === false );
$GLOBALS['scn']['owns'] = true;
ok( '200-gate: owner permission_callback = true', $perm( array( 'client_id' => 55 ) ) === true );

// ── (2) rate limit 429 ──
reset_caps();
$GLOBALS['scn'] = array_merge( $GLOBALS['scn'], array( 'rate_ok' => false, 'progress' => progress_row(), 'status' => 'not_started' ) );
$r = $svc->rest_resend_link( array( 'client_id' => 55 ) );
ok( '429: throttled returns WP_Error 429', $r instanceof WP_Error && $r->status() === 429, is_object( $r ) ? get_class( $r ) : gettype( $r ) );
ok( '429: no token rotation when throttled', $GLOBALS['cap']['update'] === null );
$GLOBALS['scn']['rate_ok'] = true;

// ── (3) 422 no assessment row ──
reset_caps();
$GLOBALS['scn']['progress'] = null;
$r = $svc->rest_resend_link( array( 'client_id' => 55 ) );
ok( '422: no assessment returns WP_Error 422', $r instanceof WP_Error && $r->status() === 422 );

// ── (4) 422 disabled status (blocked on practitioner) ──
reset_caps();
$GLOBALS['scn']['progress'] = progress_row();
$GLOBALS['scn']['status']   = 'awaiting_consult';
$r = $svc->rest_resend_link( array( 'client_id' => 55 ) );
ok( '422: disabled status refused', $r instanceof WP_Error && $r->status() === 422 );
ok( '422: disabled status does NOT rotate the token', $GLOBALS['cap']['update'] === null );
ok( '422: disabled status sends no email', $GLOBALS['cap']['mail'] === null );

// ── (5) happy path — enabled stage rotates + sends + audits ──
reset_caps();
$GLOBALS['scn']['progress'] = progress_row();
$GLOBALS['scn']['status']   = 'not_started';
$r = $svc->rest_resend_link( array( 'client_id' => 55 ) );
ok( 'happy: response success', is_array( $r ) && ! empty( $r['success'] ) );
ok( 'happy: token rotated to a NEW value', $GLOBALS['cap']['update'] && $GLOBALS['cap']['update']['data']['token'] !== 'OLDOLDOLD' && strlen( $GLOBALS['cap']['update']['data']['token'] ) === 64 );
ok( 'happy: expiry refreshed', $GLOBALS['cap']['update'] && ! empty( $GLOBALS['cap']['update']['data']['token_expires_at'] ) );
ok( 'happy: email sent to the assessment-row recipient', $GLOBALS['cap']['mail'] && $GLOBALS['cap']['mail']['to'] === 'client@example.com' );
ok( 'happy: audit timeline entry written', $GLOBALS['cap']['timeline'] && $GLOBALS['cap']['timeline']['type'] === 'link_resent' && (int) $GLOBALS['cap']['timeline']['prac_id'] === 20 );
ok( 'happy: response names recipient + invalidation + label', is_array( $r ) && $r['recipient_email'] === 'client@example.com' && $r['invalidated_old'] === true && $r['stage_label'] === 'Send assessment link' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
