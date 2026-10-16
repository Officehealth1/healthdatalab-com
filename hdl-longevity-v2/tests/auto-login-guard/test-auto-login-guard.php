<?php
/**
 * 0.48.20 — no emailed link signs anyone into a staff account (launch gate
 * line 6, CRITICAL 1). Covers the three V2 email-derived logins:
 *   - HDLV2_Widget_Config::auto_login_by_email() (verify click),
 *   - ?invite= (any source, not only full_report),
 *   - ?token= (form_progress.client_user_id, set by email in complete_signup).
 * The real HDLV2_Compatibility::is_staff() decides; WP is stubbed.
 *
 * Run:  php tests/auto-login-guard/test-auto-login-guard.php   (exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
define( 'ABSPATH', __DIR__ . '/' );

$pass = 0; $fail = 0;
function ok( $name, $cond ) {
    global $pass, $fail;
    echo ( $cond ? 'PASS' : 'FAIL' ) . " | $name\n";
    $cond ? $pass++ : $fail++;
}

// ── users: email → [id, roles, edit_posts] ──
$GLOBALS['users'] = array(
    'admin@x.test'  => array( 1, array( 'administrator' ), true ),
    'editor@x.test' => array( 2, array( 'editor' ), true ),
    'prac@x.test'   => array( 3, array( 'um_practitioner' ), false ),
    'writer@x.test' => array( 4, array( 'contributor' ), true ),
    'client@x.test' => array( 5, array( 'um_client' ), false ),
    'cons@x.test'   => array( 6, array( 'um_consumer' ), false ),
    'sub@x.test'    => array( 8, array( 'subscriber' ), false ),
);
function hdlv2_test_user( $id ) {
    foreach ( $GLOBALS['users'] as $email => $u ) {
        if ( $u[0] === (int) $id ) return (object) array( 'ID' => $u[0], 'roles' => $u[1], 'user_email' => $email, 'can' => $u[2] );
    }
    return false;
}
function get_user_by( $f, $email ) { return isset( $GLOBALS['users'][ $email ] ) ? hdlv2_test_user( $GLOBALS['users'][ $email ][0] ) : false; }
function get_userdata( $id ) { return hdlv2_test_user( $id ); }
function user_can( $id, $cap ) { $u = hdlv2_test_user( $id ); return $u && $u->can; }
function wp_set_current_user( $id ) { $GLOBALS['current'] = $id; }
function wp_set_auth_cookie( $id, $r = false ) { $GLOBALS['cookie'] = $id; }

require dirname( __DIR__, 2 ) . '/includes/class-hdlv2-compatibility.php';
require dirname( __DIR__, 2 ) . '/includes/sprint-1/class-hdlv2-widget-config.php';

$log = tempnam( sys_get_temp_dir(), 'hdlv2-log' );
ini_set( 'error_log', $log );
$m = new ReflectionMethod( 'HDLV2_Widget_Config', 'auto_login_by_email' );
$m->setAccessible( true );
function login_as( $m, $email ) {
    $GLOBALS['cookie'] = $GLOBALS['current'] = null;
    $m->invoke( null, $email );
    return $GLOBALS['cookie'];
}

echo "── verify-click login (auto_login_by_email) ──\n";
foreach ( array( 'admin', 'editor', 'prac', 'writer' ) as $who ) {
    file_put_contents( $log, '' );
    ok( "$who account → no cookie, no current user", null === login_as( $m, "$who@x.test" ) && null === $GLOBALS['current'] );
    ok( "$who account → one refusal line in the log", 1 === substr_count( file_get_contents( $log ), '[HDLV2 auto-login] refused: privileged account' ) );
}
foreach ( array( 'client' => 5, 'cons' => 6, 'sub' => 8 ) as $who => $id ) {
    ok( "$who account → cookie for $id as before", $id === login_as( $m, "$who@x.test" ) );
}
ok( 'unknown email → no-op as before', null === login_as( $m, 'nobody@x.test' ) );

echo "── ?invite= and ?token= (init handler) ──\n";
$sc = dirname( __DIR__ ) . '/token-expiry/scenario-token-login.php';
function scenario( $sc, $case ) {
    exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $sc ) . ' ' . escapeshellarg( $case ) . ' 2>/dev/null', $out );
    return implode( "\n", $out );
}
foreach ( array( 'invite-plain-prac', 'invite-plain-admin', 'token-prac', 'token-admin' ) as $case ) {
    $o = scenario( $sc, $case );
    ok( "$case → no cookie, sign-in card", false === strpos( $o, 'AUTH_COOKIE_SET' ) && false !== strpos( $o, 'Please sign in' ) );
}
ok( 'invite-fresh (client) → still logs in', false !== strpos( scenario( $sc, 'invite-fresh' ), 'AUTH_COOKIE_SET:7' ) );
ok( 'fresh ?token= (client) → still logs in', false !== strpos( scenario( $sc, 'fresh' ), 'AUTH_COOKIE_SET:42' ) );

@unlink( $log );
echo "\nTOTAL: $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
