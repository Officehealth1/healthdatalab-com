<?php
/**
 * Practitioner-scale read budget + log_block identity tests (2026-07-14 fix).
 *
 * The bug (idle-dashboard 429 RCA, 2026-07-13): the TIER_READ quota — 200
 * requests/hour keyed per WP user — is shared by every open tab and device
 * of the same practitioner. The client-list 60s fallback poll consumes
 * 120 reads/hour PER TAB on /dashboard/clients + /widget/leads/pending, so
 * >=2 idle tabs exhaust the practitioner's own budget with zero user
 * actions and the dashboard self-429s near the tail of each hourly window
 * (LIVE 2026-07-13: 114 blocks, all tier=read, exactly those two routes).
 *
 * The fix under test (middleware + policy, REAL classes loaded below):
 *   - An AUTHENTICATED PRACTITIONER (no magic-link token) making GET
 *     requests to /dashboard/clients or /widget/leads/pending consumes a
 *     dedicated 'prac-read' bucket (default 1200/hour per user), NOT the
 *     consumer-scale 200/hour read bucket.
 *   - Anonymous and token-credential traffic on those routes is UNTOUCHED
 *     (ip-anon read 200/hr + IP backstop 500/hr; token 200/hr).
 *   - Other read routes stay at 200/hr even for practitioners.
 *   - log_block() logs 'user:<id>' for logged-in users and a TRUNCATED
 *     token (never the full 64-hex credential) for token identities.
 *
 * Proves:
 *  (P1) prac GET /dashboard/clients x250 → all allowed; read|user bucket
 *       untouched; prac-read|user bucket carries the count;
 *  (P2) /widget/leads/pending shares the same prac-read bucket;
 *  (P3) the prac-read cap still exists: request 1201 in the window → 429
 *       with tier 'prac-read' and a Retry-After header;
 *  (P4) anonymous on /dashboard/clients unchanged: 200 allowed, 201st →
 *       429 tier=read; IP backstop consumed alongside;
 *  (P5) token identity on /form/load unchanged: 200 allowed, 201st → 429;
 *  (P6) token present + logged-in prac on /dashboard/clients → token
 *       precedence (plain read token bucket, prac-read NOT consumed);
 *  (P7) prac on /consultations/list (read, not a dashboard roster route)
 *       still capped at 200/hr;
 *  (P8) log_block identity: user block logs 'user:122'; token block logs
 *       a truncated token, never the full credential;
 *  (P9) no [HDL-RL FAIL-OPEN] lines — the middleware ran its real path,
 *       not the exception fallback.
 *
 * Run:  php tests/rl-prac-read/scenario-prac-read.php   (exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

// ── error_log capture (log_block + FAIL-OPEN lines land here) ──
$GLOBALS['errlog_file'] = tempnam( sys_get_temp_dir(), 'hdlrl' );
ini_set( 'error_log', $GLOBALS['errlog_file'] );

// ── WP stubs ──
$GLOBALS['transients']      = array();
$GLOBALS['options']         = array( 'hdlv2_rate_limit_disabled_tiers' => '' );
$GLOBALS['current_user_id'] = 0;
$GLOBALS['user_roles']      = array();

function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function get_option( $k, $default = false ) { return $GLOBALS['options'][ $k ] ?? $default; }
function apply_filters( $tag, $value ) { return $value; }
function add_filter() {}
function is_user_logged_in() { return $GLOBALS['current_user_id'] > 0; }
function get_current_user_id() { return $GLOBALS['current_user_id']; }
function get_userdata( $id ) {
    if ( $id !== $GLOBALS['current_user_id'] || $id <= 0 ) return false;
    return (object) array( 'ID' => $id, 'roles' => $GLOBALS['user_roles'] );
}
function user_can( $id, $cap ) { return false; }

class WP_REST_Response {
    public $data; public $status; public $headers = array();
    public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
    public function header( $k, $v ) { $this->headers[ $k ] = $v; }
    public function get_status() { return $this->status; }
    public function get_data() { return $this->data; }
}

class FakeRequest {
    private $method; private $route; private $token;
    public function __construct( $method, $route, $token = null ) {
        $this->method = $method; $this->route = $route; $this->token = $token;
    }
    public function get_route() { return $this->route; }
    public function get_method() { return $this->method; }
    public function get_header( $name ) { return ( 'x_hdlv2_token' === $name ) ? $this->token : null; }
    public function get_param( $name ) { return null; }
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_GET = array();

// ── Load the REAL classes under test ──
$base = dirname( __DIR__, 2 ) . '/includes/security/';
require $base . 'class-hdlv2-rate-limiter.php';
require $base . 'class-hdlv2-rate-limit-policy.php';
require $base . 'class-hdlv2-rate-limit-middleware.php';

// ── Assertion plumbing ──
$pass = 0; $fail = 0;
function ok( $name, $cond, $detail = '' ) {
    global $pass, $fail;
    echo ( $cond ? 'PASS' : 'FAIL' ) . ' | ' . $name . ( $cond ? '' : ' | ' . $detail ) . "\n";
    $cond ? $pass++ : $fail++;
}
function drive( $method, $route, $n, $token = null ) {
    $allowed = 0; $blocked = 0; $last = null;
    for ( $i = 0; $i < $n; $i++ ) {
        $req = new FakeRequest( $method, $route, $token );
        $r   = HDLV2_Rate_Limit_Middleware::check_request( null, null, $req );
        if ( null === $r ) { $allowed++; } else { $blocked++; $last = $r; }
    }
    return array( $allowed, $blocked, $last );
}
function bucket_count( array $parts ) {
    $key = HDLV2_Rate_Limiter::bucket_key( $parts );
    $st  = $GLOBALS['transients'][ $key ] ?? false;
    return is_array( $st ) ? (int) $st['count'] : 0;
}
function reset_world( $uid = 0, $roles = array() ) {
    $GLOBALS['transients']      = array();
    $GLOBALS['current_user_id'] = $uid;
    $GLOBALS['user_roles']      = $roles;
}

// ═══ P1/P2/P3 — practitioner-scale budget on the two roster routes ═══
reset_world( 122, array( 'um_practitioner' ) );

list( $a, $b ) = drive( 'GET', '/hdl-v2/v1/dashboard/clients', 250 );
ok( '(P1a) prac 250x /dashboard/clients all allowed (>200 = past the old cap)', 250 === $a && 0 === $b, "allowed=$a blocked=$b" );
ok( '(P1b) consumer read|user bucket NOT consumed', 0 === bucket_count( array( 'read', 'user', 122 ) ), 'count=' . bucket_count( array( 'read', 'user', 122 ) ) );
ok( '(P1c) prac-read|user bucket carries the 250', 250 === bucket_count( array( 'prac-read', 'user', 122 ) ), 'count=' . bucket_count( array( 'prac-read', 'user', 122 ) ) );

list( $a, $b ) = drive( 'GET', '/hdl-v2/v1/widget/leads/pending', 10 );
ok( '(P2) /widget/leads/pending shares the prac-read bucket (250+10)', 10 === $a && 260 === bucket_count( array( 'prac-read', 'user', 122 ) ), 'allowed=' . $a . ' bucket=' . bucket_count( array( 'prac-read', 'user', 122 ) ) );

list( $a, $b, $last ) = drive( 'GET', '/hdl-v2/v1/dashboard/clients', 941 );
ok( '(P3a) prac-read cap still enforced at 1200/window', 940 === $a && 1 === $b, "allowed=$a blocked=$b" );
ok( '(P3b) 429 carries tier prac-read + Retry-After', $last instanceof WP_REST_Response && 429 === $last->get_status() && 'prac-read' === ( $last->get_data()['tier'] ?? '' ) && isset( $last->headers['Retry-After'] ), $last ? json_encode( $last->get_data() ) : 'null' );

// ═══ P4 — anonymous traffic on the same route is UNTOUCHED ═══
reset_world( 0 );
list( $a, $b, $last ) = drive( 'GET', '/hdl-v2/v1/dashboard/clients', 201 );
ok( '(P4a) anon: 200 allowed, 201st blocked (unchanged consumer cap)', 200 === $a && 1 === $b, "allowed=$a blocked=$b" );
ok( '(P4b) anon block is tier=read (not prac-read)', $last && 'read' === ( $last->get_data()['tier'] ?? '' ), $last ? json_encode( $last->get_data() ) : 'null' );
ok( '(P4c) IP backstop consumed alongside (201)', 201 === bucket_count( array( 'ip-backstop', '203.0.113.9' ) ), 'count=' . bucket_count( array( 'ip-backstop', '203.0.113.9' ) ) );

// ═══ P5 — token credential unchanged ═══
reset_world( 0 );
$tok = str_repeat( 'ab12cd34', 8 ); // 64 hex chars
list( $a, $b, $last ) = drive( 'GET', '/hdl-v2/v1/form/load', 201, $tok );
ok( '(P5) token identity: 200 allowed, 201st blocked (unchanged)', 200 === $a && 1 === $b && $last && 'read' === ( $last->get_data()['tier'] ?? '' ), "allowed=$a blocked=$b" );

// ═══ P6 — token precedence beats prac-read (no token → prac path only) ═══
reset_world( 122, array( 'um_practitioner' ) );
list( $a, $b ) = drive( 'GET', '/hdl-v2/v1/dashboard/clients', 201, $tok );
ok( '(P6) token present → plain read token bucket, prac-read untouched', 200 === $a && 1 === $b && 0 === bucket_count( array( 'prac-read', 'user', 122 ) ), "allowed=$a blocked=$b pracbucket=" . bucket_count( array( 'prac-read', 'user', 122 ) ) );

// ═══ P7 — other read routes keep 200/hr for practitioners ═══
reset_world( 122, array( 'um_practitioner' ) );
list( $a, $b, $last ) = drive( 'GET', '/hdl-v2/v1/consultations/list', 201 );
ok( '(P7) prac on /consultations/list still 200/hr (scope is the two roster routes only)', 200 === $a && 1 === $b, "allowed=$a blocked=$b" );

// ═══ P8 — log_block identity format ═══
$log = file_get_contents( $GLOBALS['errlog_file'] );
ok( '(P8a) user-identity block logged as user:122', false !== strpos( $log, 'id=user:122' ), 'no id=user:122 in log' );
$full_token_logged = ( false !== strpos( $log, $tok ) );
$trunc_logged      = ( false !== strpos( $log, 'token:' . substr( $tok, 0, 8 ) ) );
ok( '(P8b) token block logs truncated token, never the full credential', $trunc_logged && ! $full_token_logged, 'trunc=' . var_export( $trunc_logged, true ) . ' full=' . var_export( $full_token_logged, true ) );

// ═══ P9 — the real code path ran (no exception fail-open) ═══
ok( '(P9) zero FAIL-OPEN lines (stubs exercised the real path)', false === strpos( $log, 'FAIL-OPEN' ), 'FAIL-OPEN found' );

unlink( $GLOBALS['errlog_file'] );
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
