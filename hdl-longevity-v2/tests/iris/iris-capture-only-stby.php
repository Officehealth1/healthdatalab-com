<?php
/**
 * Iridology CAPTURE-ONLY integration test — run on STBY via:
 *   sudo -u www-data wp eval-file wp-content/plugins/hdl-longevity-v2/tests/iris/iris-capture-only-stby.php --allow-root
 *
 * Covers the capture-only additions (Phase 2+):
 *   - GET /iris/clients: signed shared-secret (x-hdl-secret + HMAC over email),
 *     returns ONLY the calling practitioner's own clients; auth matrix; Rule-0.
 *   (image persistence + PDF payload tests are appended in later phases.)
 *
 * Self-cleaning: temp users + form_progress rows tagged with a unique marker,
 * deleted + flags restored at the end.
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "run via wp eval-file\n" ); exit( 1 ); }

global $wpdb;

$GLOBALS['T'] = 0; $GLOBALS['F'] = 0;
function ok( $cond, $msg ) { $GLOBALS['T']++; if ( $cond ) { echo "  ok   - $msg\n"; } else { $GLOBALS['F']++; echo "  FAIL - $msg\n"; } }
function sec( $t ) { echo "\n# $t\n"; }

$MARK    = 'ircco_' . substr( md5( (string) microtime( true ) ), 0, 8 );
$SHARED  = defined( 'HDL_SHARED_SECRET' ) ? HDL_SHARED_SECRET : '';
$FP_TBL  = $wpdb->prefix . 'hdlv2_form_progress';

$snap_addon   = get_option( 'hdlv2_ff_iris_addon' );
$snap_consult = get_option( 'hdlv2_ff_iris_consult' );

function rebuild_rest_server() { $GLOBALS['wp_rest_server'] = null; rest_get_server(); }

echo "=== Iris capture-only integration test ($MARK) ===\n";
echo "HDL_SHARED_SECRET defined: " . ( $SHARED !== '' ? 'yes' : 'NO' ) . "\n";
ok( $SHARED !== '', 'HDL_SHARED_SECRET is configured on STBY' );

// ── dispatch a GET /iris/clients with controllable auth ──
//   $o: email (query), header_secret (x-hdl-secret; null = omit), sign_secret
//   (HMAC key; null = omit sig headers), sign_email (the email signed over).
function dispatch_clients( $o ) {
    $req = new WP_REST_Request( 'GET', '/hdl-v2/v1/iris/clients' );
    $req->set_query_params( array( 'email' => isset( $o['email'] ) ? $o['email'] : '' ) );
    if ( array_key_exists( 'header_secret', $o ) && $o['header_secret'] !== null ) {
        $req->set_header( 'x-hdl-secret', $o['header_secret'] );
    }
    $sign_secret = array_key_exists( 'sign_secret', $o ) ? $o['sign_secret'] : ( isset( $o['header_secret'] ) ? $o['header_secret'] : null );
    if ( $sign_secret !== null ) {
        $sign_email = isset( $o['sign_email'] ) ? $o['sign_email'] : ( isset( $o['email'] ) ? $o['email'] : '' );
        $h = HDLV2_Iris_Support::sign_clients_query( $sign_secret, $sign_email );
        $req->set_header( 'x-hdl-timestamp', $h['x-hdl-timestamp'] );
        $req->set_header( 'x-hdl-signature', $h['x-hdl-signature'] );
    }
    return rest_do_request( $req );
}

// ── fixtures: practitioner A (2 clients), practitioner B (1 client) ──
sec( 'fixtures' );
function mk_user( $login, $email, $role ) { return wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password(), 'role' => $role ) ); }

$pracA  = mk_user( $MARK . '_pa', $MARK . '_pa@example.test', 'um_practitioner' );
$pracB  = mk_user( $MARK . '_pb', $MARK . '_pb@example.test', 'um_practitioner' );
$a_cli1 = mk_user( $MARK . '_a1', $MARK . '_a1@example.test', 'subscriber' );
$a_cli2 = mk_user( $MARK . '_a2', $MARK . '_a2@example.test', 'subscriber' );
$b_cli1 = mk_user( $MARK . '_b1', $MARK . '_b1@example.test', 'subscriber' );
ok( ! is_wp_error( $pracA ) && ! is_wp_error( $pracB ) && ! is_wp_error( $a_cli1 ) && ! is_wp_error( $a_cli2 ) && ! is_wp_error( $b_cli1 ), 'temp users created' );
( new WP_User( $pracA ) )->set_role( 'um_practitioner' );
( new WP_User( $pracB ) )->set_role( 'um_practitioner' );

$emailA = get_userdata( $pracA )->user_email;
$emailB = get_userdata( $pracB )->user_email;

function mk_progress( $prac, $cli, $name, $email, $stage ) {
    global $wpdb;
    // form_progress.token is NOT NULL + UNIQUE — a unique value per row or the
    // 2nd insert collides on the empty-string implicit default.
    $wpdb->insert( $wpdb->prefix . 'hdlv2_form_progress', array(
        'practitioner_user_id' => $prac, 'client_user_id' => $cli,
        'client_name' => $name, 'client_email' => $email, 'current_stage' => $stage,
        'token' => substr( hash( 'sha256', $prac . ':' . $cli . ':' . microtime( true ) ), 0, 48 ),
    ), array( '%d', '%d', '%s', '%s', '%d', '%s' ) );
    return (int) $wpdb->insert_id;
}
$pA1 = mk_progress( $pracA, $a_cli1, 'Alice One',  $MARK . '_a1@example.test', 3 );
$pA2 = mk_progress( $pracA, $a_cli2, 'Bob Two',    '',                          2 ); // no client_email → must fall back to WP user email
$pB1 = mk_progress( $pracB, $b_cli1, 'Carol Three',$MARK . '_b1@example.test', 1 );
ok( $pA1 && $pA2 && $pB1, "form_progress rows created (A:$pA1,$pA2  B:$pB1)" );

update_option( 'hdlv2_ff_iris_addon', 1 );
update_option( 'hdlv2_ff_iris_consult', 1 );
rebuild_rest_server();
ok( HDLV2_Iris_Consult::enabled() === true, 'enabled() true with both flags on' );

// ─────────────────────────────────────────────────────────────────────────
sec( 'A signed request → ONLY A\'s 2 clients, with email + numeric consultationId' );
$res = dispatch_clients( array( 'email' => $emailA, 'header_secret' => $SHARED ) );
ok( $res->get_status() === 200, 'A gets 200' );
$rows = $res->get_data();
ok( is_array( $rows ) && count( $rows ) === 2, 'exactly 2 clients (own-only)' );
$ids = array_map( function ( $r ) { return (int) $r['clientId']; }, $rows );
ok( in_array( (int) $a_cli1, $ids, true ) && in_array( (int) $a_cli2, $ids, true ), 'both of A\'s clients present' );
ok( ! in_array( (int) $b_cli1, $ids, true ), 'B\'s client is NOT in A\'s list (strict practitioner scoping)' );
$all_have_email = true; $all_numeric_consult = true; $has_health = false;
foreach ( $rows as $r ) {
    if ( empty( $r['email'] ) || ! is_string( $r['email'] ) ) { $all_have_email = false; }
    if ( ! isset( $r['consultationId'] ) || ! is_int( $r['consultationId'] ) || $r['consultationId'] < 1 ) { $all_numeric_consult = false; }
    // shape allow-list: ONLY these 5 keys, nothing else (no health data leak).
    $extra = array_diff( array_keys( $r ), array( 'clientId', 'name', 'email', 'consultationId', 'consultationStatus' ) );
    if ( $extra ) { $has_health = true; }
}
ok( $all_have_email, 'every row has a non-empty email (incl. the WP-user fallback when client_email blank)' );
ok( $all_numeric_consult, 'every consultationId is a positive integer (the form_progress.id to attach to)' );
ok( ! $has_health, 'response carries ONLY {clientId,name,email,consultationId,consultationStatus} — no health data' );
// the empty-client_email row falls back to the WP user email
$bob = null; foreach ( $rows as $r ) { if ( (int) $r['clientId'] === (int) $a_cli2 ) { $bob = $r; } }
ok( $bob && $bob['email'] === $MARK . '_a2@example.test', 'blank client_email falls back to the WP user email' );

sec( 'B signed request → ONLY B\'s 1 client (no cross-tenant bleed)' );
$res = dispatch_clients( array( 'email' => $emailB, 'header_secret' => $SHARED ) );
ok( $res->get_status() === 200, 'B gets 200' );
$rowsB = $res->get_data();
ok( is_array( $rowsB ) && count( $rowsB ) === 1 && (int) $rowsB[0]['clientId'] === (int) $b_cli1, 'B sees only their own 1 client' );

sec( 'auth matrix' );
$res = dispatch_clients( array( 'email' => $emailA, 'header_secret' => null ) ); // omit secret
ok( $res->get_status() === 401, 'missing x-hdl-secret → 401' );
$res = dispatch_clients( array( 'email' => $emailA, 'header_secret' => 'wrong-' . $SHARED, 'sign_secret' => 'wrong-' . $SHARED ) );
ok( $res->get_status() === 403, 'wrong shared secret → 403' );
$res = dispatch_clients( array( 'email' => $emailA, 'header_secret' => $SHARED, 'sign_secret' => $SHARED, 'sign_email' => $emailB ) );
ok( $res->get_status() === 403, 'signature over a DIFFERENT email → 403 (no email swap)' );
$res = dispatch_clients( array( 'email' => $MARK . '_a1@example.test', 'header_secret' => $SHARED ) ); // a client, not a practitioner
ok( $res->get_status() === 404, 'a non-practitioner email → 404 (does not leak existence)' );
$res = dispatch_clients( array( 'email' => 'nobody-' . $MARK . '@example.test', 'header_secret' => $SHARED ) );
ok( $res->get_status() === 404, 'an unknown email → 404' );

sec( 'Rule-0 — flag OFF ⇒ /iris/clients 404s' );
update_option( 'hdlv2_ff_iris_consult', 0 );
rebuild_rest_server();
$routes = rest_get_server()->get_routes();
ok( ! isset( $routes['/hdl-v2/v1/iris/clients'] ), '/iris/clients NOT registered with the consult flag off' );
$res = dispatch_clients( array( 'email' => $emailA, 'header_secret' => $SHARED ) );
ok( $res->get_status() === 404, 'request 404s with the flag off (zero trace)' );

// ── cleanup ──
sec( 'cleanup' );
foreach ( array( $pA1, $pA2, $pB1 ) as $pid ) { $wpdb->delete( $FP_TBL, array( 'id' => $pid ), array( '%d' ) ); }
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array( $pracA, $pracB, $a_cli1, $a_cli2, $b_cli1 ) as $uid ) { if ( $uid && ! is_wp_error( $uid ) ) { wp_delete_user( $uid ); } }
if ( $snap_addon === false ) { delete_option( 'hdlv2_ff_iris_addon' ); } else { update_option( 'hdlv2_ff_iris_addon', $snap_addon ); }
if ( $snap_consult === false ) { delete_option( 'hdlv2_ff_iris_consult' ); } else { update_option( 'hdlv2_ff_iris_consult', $snap_consult ); }
rebuild_rest_server();
ok( true, 'temp users/rows deleted; flags restored (addon=' . var_export( get_option( 'hdlv2_ff_iris_addon' ), true ) . ', consult=' . var_export( get_option( 'hdlv2_ff_iris_consult' ), true ) . ')' );

echo "\n────────────────────────────────────\n";
echo "  tests: {$GLOBALS['T']}   failures: {$GLOBALS['F']}\n";
echo ( $GLOBALS['F'] === 0 ? "  RESULT: PASS\n" : "  RESULT: FAIL\n" );
