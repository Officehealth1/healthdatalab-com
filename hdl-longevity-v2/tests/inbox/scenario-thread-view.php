<?php
/**
 * Slice C — token-mode thread view (the pre-UM init interceptor).
 *
 * Why this surface exists: UM global access (accessible=2) 302s EVERY
 * anonymous page request to /login/ BEFORE any shortcode renders, so the
 * emailed scoped link (?msg=m1...) could never reach an inbox behind the
 * [hdlv2_my_dashboard] login gate. The V2 plugin's own proven pattern for
 * beating that wall is the init-priority-1 funnel auto-login; Slice C hooks
 * init priority 2 and, for a valid scoped token, renders a THREAD-ONLY page
 * and exits before UM runs. No session is ever created.
 *
 * HDLV2_Message_Thread_View::evaluate_request( $get, $path, $session_hash,
 * $flag_enabled ) is PURE (no exit/header side effects) so every path is
 * testable; the thin init wrapper does the echo/redirect/exit.
 *
 * Proves:
 *  (H1)  flag OFF -> pass (LIVE stays inert even with files deployed)
 *  (H2)  no ?msg -> pass
 *  (H3)  wrong path -> pass (interceptor is scoped to /my-dashboard/)
 *  (H4)  anon + VALID token -> thread-only page: shell + JSON config
 *        (mode=token, token, clientHash, practitionerId, ajaxUrl), and the
 *        page contains NO dashboard tabs and NO nonce
 *  (H5)  anon + garbage token -> graceful expired card (reply-by-email copy,
 *        NO config, NO thread shell) — never a /login/ dead-end
 *  (H6)  anon + EXPIRED token -> same expired card
 *  (H7)  logged-in + token for OWN hash -> redirect to dashboard ?open_thread=1
 *  (H8)  logged-in + INVALID token -> same dashboard redirect (never a wall)
 *  (H9)  logged-in + VALID FOREIGN token -> thread-only page scoped to the
 *        TOKEN's hash (bearer semantics; session identity untouched)
 *  (H10) render actions carry no-store / LiteSpeed no-cache / no-referrer /
 *        noindex headers (token URLs must never be cached or leak via referrer)
 *  (H11) token-mode config JSON is the ONLY inline script and is
 *        type="application/json" (non-executable -> CSP-safe)
 *
 * Run:  php scenario-thread-view.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ . '/' );
define( 'HDLV2_PLUGIN_URL', 'https://stby.healthdatalab.net/wp-content/plugins/hdl-longevity-v2/' );
define( 'HDLV2_VERSION', 'test-ver' );

// ── WP stubs ──
function add_action() {}
function add_filter() {}
function apply_filters( $tag, $value ) { return $value; }
function home_url( $p = '' ) { return 'https://stby.healthdatalab.net' . $p; }
function admin_url( $p = '' ) { return 'https://stby.healthdatalab.net/wp-admin/' . $p; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $u ) { return $u; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }

// ── Validator boundary stub — the REAL HMAC is proven in the V1 suite
//    (health-data-lab-plugin/tests/test-messaging-token.php BTS1-6). ──
$GLOBALS['hash_a'] = str_repeat( 'a', 64 );
$GLOBALS['hash_b'] = str_repeat( 'b', 64 );
class HDL_Messaging_Service {
    public static function message_token_scope( $token ) {
        if ( $token === 'm1.VALID-A' ) return array( 'client_hash' => $GLOBALS['hash_a'], 'practitioner_id' => 206 );
        if ( $token === 'm1.VALID-B' ) return array( 'client_hash' => $GLOBALS['hash_b'], 'practitioner_id' => 206 );
        return false; // expired / tampered / garbage all land here
    }
}

require __DIR__ . '/../../includes/sprint-4/class-hdlv2-message-thread-view.php';

$pass = 0; $fail = 0;
function ok( $name, $cond ) { global $pass, $fail; echo ( $cond ? 'PASS' : 'FAIL' ) . " | $name\n"; $cond ? $pass++ : $fail++; }

$A    = $GLOBALS['hash_a'];
$B    = $GLOBALS['hash_b'];
$path = '/my-dashboard';

function evalreq( $msg, $path, $session_hash, $flag ) {
    $get = $msg === null ? array() : array( 'msg' => $msg );
    return HDLV2_Message_Thread_View::evaluate_request( $get, $path, $session_hash, $flag );
}

// H1 flag OFF -> pass even with a valid token (LIVE inert until the flag flips)
$r = evalreq( 'm1.VALID-A', $path, null, false );
ok( '(H1) flag OFF + valid token -> pass', $r['action'] === 'pass' );

// H2 no msg -> pass
$r = evalreq( null, $path, null, true );
ok( '(H2) flag ON + no ?msg -> pass', $r['action'] === 'pass' );

// H3 wrong path -> pass
$r = evalreq( 'm1.VALID-A', '/some-other-page', null, true );
ok( '(H3) flag ON + valid token on a NON-dashboard path -> pass', $r['action'] === 'pass' );

// H4 anon + valid -> thread-only render
$r = evalreq( 'm1.VALID-A', $path, null, true );
$html = $r['action'] === 'render_thread' ? $r['html'] : '';
ok( '(H4a) anon + valid token -> render_thread', $r['action'] === 'render_thread' );
ok( '(H4b) thread page contains the inbox shell', strpos( $html, 'data-inbox' ) !== false && strpos( $html, 'hdlv2-inbox' ) !== false );
$cfg = null;
if ( preg_match( '/<script type="application\/json" id="hdlv2-inbox-config">(.*?)<\/script>/s', $html, $m ) ) {
    $cfg = json_decode( $m[1], true );
}
ok( '(H4c) config JSON: mode=token, token echoed, scope hash + practitioner from the VALIDATED token',
    is_array( $cfg ) && $cfg['mode'] === 'token' && $cfg['token'] === 'm1.VALID-A'
    && $cfg['clientHash'] === $A && (int) $cfg['practitionerId'] === 206
    && strpos( (string) $cfg['ajaxUrl'], 'admin-ajax.php' ) !== false );
ok( '(H4d) token mode carries NO nonce anywhere', stripos( $html, 'nonce' ) === false );
ok( '(H4e) thread-only: NO dashboard tabs / login card on the page',
    strpos( $html, 'cdp-tab' ) === false && stripos( $html, 'Sign in to see your dashboard' ) === false );
ok( '(H4f) inbox JS + CSS loaded with version-busted plugin URLs',
    strpos( $html, 'assets/js/hdlv2-inbox.js?ver=test-ver' ) !== false
    && strpos( $html, 'assets/css/hdlv2-client-dashboard.css?ver=test-ver' ) !== false );

// H5 anon + garbage -> expired card (graceful degradation, never /login/)
$r = evalreq( 'm1.garbage', $path, null, true );
$html5 = $r['action'] === 'render_expired' ? $r['html'] : '';
ok( '(H5a) anon + garbage token -> render_expired', $r['action'] === 'render_expired' );
ok( '(H5b) expired card says reply-by-email, has NO config and NO thread shell',
    stripos( $html5, 'repl' ) !== false && stripos( $html5, 'email' ) !== false
    && strpos( $html5, 'hdlv2-inbox-config' ) === false && strpos( $html5, 'data-inbox-thread' ) === false );

// H6 anon + expired -> same card (validator returns false for expired)
$r = evalreq( 'm1.EXPIRED', $path, null, true );
ok( '(H6) anon + expired token -> render_expired', $r['action'] === 'render_expired' );

// H7 logged-in + own token -> redirect into the full dashboard, token dropped
$r = evalreq( 'm1.VALID-A', $path, $A, true );
ok( '(H7) logged-in own token -> redirect to dashboard ?open_thread=1 (no token in URL)',
    $r['action'] === 'redirect'
    && strpos( $r['url'], '/my-dashboard/' ) !== false
    && strpos( $r['url'], 'open_thread=1' ) !== false
    && strpos( $r['url'], 'msg=' ) === false );

// H8 logged-in + invalid token -> same redirect (their own dashboard has the inbox)
$r = evalreq( 'm1.garbage', $path, $A, true );
ok( '(H8) logged-in invalid token -> redirect to own dashboard', $r['action'] === 'redirect' && strpos( $r['url'], 'open_thread=1' ) !== false );

// H9 logged-in as B + valid token for A -> the TOKEN wins: thread-only for A
$r = evalreq( 'm1.VALID-A', $path, $B, true );
$cfg9 = null;
if ( $r['action'] === 'render_thread' && preg_match( '/<script type="application\/json" id="hdlv2-inbox-config">(.*?)<\/script>/s', $r['html'], $m ) ) {
    $cfg9 = json_decode( $m[1], true );
}
ok( '(H9) logged-in FOREIGN user + valid token -> thread-only scoped to the TOKEN hash',
    $r['action'] === 'render_thread' && is_array( $cfg9 ) && $cfg9['clientHash'] === $A );

// H10 cache/referrer hygiene headers on BOTH render actions
$rt = evalreq( 'm1.VALID-A', $path, null, true );
$re = evalreq( 'm1.garbage', $path, null, true );
function has_header( $r, $needle ) {
    foreach ( (array) ( $r['headers'] ?? array() ) as $h ) {
        if ( stripos( $h, $needle ) !== false ) return true;
    }
    return false;
}
ok( '(H10) no-store + LiteSpeed no-cache + no-referrer + noindex on render actions',
    has_header( $rt, 'no-store' ) && has_header( $rt, 'X-LiteSpeed-Cache-Control' )
    && has_header( $rt, 'Referrer-Policy: no-referrer' ) && has_header( $rt, 'noindex' )
    && has_header( $re, 'no-store' ) );

// H11 the ONLY inline <script> without src is the non-executable JSON config (CSP-safe)
preg_match_all( '/<script\b(?![^>]*\bsrc=)[^>]*>/i', $rt['html'], $scripts );
$all_json = true;
foreach ( $scripts[0] as $tag ) {
    if ( stripos( $tag, 'type="application/json"' ) === false ) $all_json = false;
}
ok( '(H11) no executable inline scripts in token mode (only the application/json config)',
    count( $scripts[0] ) >= 1 && $all_json );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
