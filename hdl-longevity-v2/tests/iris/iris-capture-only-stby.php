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

// ─────────────────────────────────────────────────────────────────────────
//  Image persistence — the callback `images` object (signed download URLs)
//  is fetched server-side into the private dir. Mock pre_http_request so no
//  real network is hit; example.com resolves public (passes the SSRF guard),
//  a link-local URL is refused by url_targets_reserved_ip BEFORE any fetch.
// ─────────────────────────────────────────────────────────────────────────
$CB = defined( 'HDL_CALLBACK_SECRET' ) ? HDL_CALLBACK_SECRET : '';
$JPEG = "\xFF\xD8\xFF\xE0" . str_repeat( "\x7f", 256 ) . "\xFF\xD9"; // valid JPEG magic, >64 bytes

add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $JPEG, $MARK ) {
    if ( strpos( (string) $url, $MARK ) === false ) { return $pre; } // only OUR image URLs
    return array(
        'headers'  => array( 'content-type' => 'image/jpeg' ),
        'body'     => $JPEG,
        'response' => array( 'code' => 200, 'message' => 'OK' ),
        'cookies'  => array(), 'filename' => null,
    );
}, 10, 3 );

function co_iris_dir() { return ( defined( 'HDLV2_PRIVATE_DIR' ) ? rtrim( HDLV2_PRIVATE_DIR, '/' ) : dirname( rtrim( ABSPATH, '/' ) ) . '/hdlv2-private' ) . '/hdlv2-iris/'; }
function co_row( $cap ) { global $wpdb; $t = $wpdb->prefix . 'hdlv2_iris_results'; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE capture_id = %s", $cap ) ); }
function dispatch_cap( $body, $secret ) {
    $raw = wp_json_encode( $body );
    $h = HDLV2_Iris_Support::sign_headers( $secret, $raw );
    $req = new WP_REST_Request( 'POST', '/hdl-v2/v1/iris/analyse/callback' );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_header( 'x-hdl-callback-secret', $secret );
    $req->set_header( 'x-hdl-timestamp', $h['x-hdl-timestamp'] );
    $req->set_header( 'x-hdl-signature', $h['x-hdl-signature'] );
    $req->set_body( $raw );
    return rest_do_request( $req );
}
function co_result( $tag ) {
    return array(
        'photo_quality' => array( 'usable' => true, 'issues' => array(), 'suggestion' => '' ),
        'eyes' => array(
            array( 'eye' => 'L', 'constitution_summary' => array( 'constitution' => 'lymphatic', 'colour_type' => 'blue', 'structure_grade' => '3', 'note' => $tag ), 'visible_observations' => array(), 'map_zone_notes' => array(), 'suggested_questions' => array(), 'low_confidence_or_not_visible' => array(), 'overall_confidence' => 'medium' ),
            array( 'eye' => 'R', 'constitution_summary' => array( 'constitution' => 'lymphatic', 'colour_type' => 'blue', 'structure_grade' => '3', 'note' => $tag ), 'visible_observations' => array(), 'map_zone_notes' => array(), 'suggested_questions' => array(), 'low_confidence_or_not_visible' => array(), 'overall_confidence' => 'low' ),
        ),
        'bilateral_notes' => array(),
    );
}

sec( 'capture WITH signed-URL images → all 4 (iris L/R + map L/R) stored + served' );
ok( $CB !== '', 'HDL_CALLBACK_SECRET configured (callback auth)' );
$IMG = 'https://example.com/' . $MARK . '-';
$cap1 = HDLV2_Iris_Support::build_capture_id( $a_cli1, $pA1, hash( 'sha256', $MARK . '-set1' ) );
$res = dispatch_cap( array(
    'captureId' => $cap1, 'status' => 'done', 'finalized' => true, 'result' => co_result( 's1' ),
    'images' => array( 'iris' => array( 'L' => $IMG . 'il.jpg', 'R' => $IMG . 'ir.jpg' ),
                       'map'  => array( 'L' => $IMG . 'ml.jpg', 'R' => $IMG . 'mr.jpg' ) ),
), $CB );
ok( $res->get_status() === 200, 'callback acks 200' );
$r1 = co_row( $cap1 );
ok( $r1 && $r1->image_l_path && $r1->image_r_path && $r1->map_l_path && $r1->map_r_path, 'all 4 image path columns recorded (iris L/R + map L/R)' );
$dir = co_iris_dir();
ok( $r1 && is_file( $dir . $r1->image_l_path ) && is_file( $dir . $r1->map_r_path ), 'downloaded files exist on disk in the private dir' );
ok( $r1 && strpos( $r1->image_l_path, 'iris-l' ) !== false && strpos( $r1->map_r_path, 'map-r' ) !== false, 'filenames tag iris-l / map-r' );

sec( 'SSRF — a link-local image URL is refused (column stays NULL), callback still 200' );
$cap2 = HDLV2_Iris_Support::build_capture_id( $a_cli1, $pA1, hash( 'sha256', $MARK . '-set2' ) );
$res = dispatch_cap( array(
    'captureId' => $cap2, 'status' => 'done', 'finalized' => true, 'result' => co_result( 's2' ),
    'images' => array( 'iris' => array( 'L' => $IMG . 'safe.jpg' ),
                       'map'  => array( 'L' => 'http://169.254.169.254/' . $MARK . '-evil.jpg' ) ),
), $CB );
ok( $res->get_status() === 200, 'callback with a reserved-IP image still acks 200' );
$r2 = co_row( $cap2 );
ok( $r2 && $r2->image_l_path, 'the safe iris image stored' );
ok( $r2 && $r2->map_l_path === null, 'the reserved-IP (metadata) map URL refused → column NULL (SSRF blocked)' );

sec( 'back-compat — legacy flat { L, R } base64 stores iris L/R' );
$png_b64 = 'data:image/png;base64,' . base64_encode( "\x89PNG\r\n\x1a\n" . str_repeat( "\x00", 120 ) );
$cap3 = HDLV2_Iris_Support::build_capture_id( $a_cli1, $pA1, hash( 'sha256', $MARK . '-set3' ) );
$res = dispatch_cap( array(
    'captureId' => $cap3, 'status' => 'done', 'finalized' => true, 'result' => co_result( 's3' ),
    'images' => array( 'L' => $png_b64, 'R' => $png_b64 ),
), $CB );
$r3 = co_row( $cap3 );
ok( $res->get_status() === 200 && $r3 && $r3->image_l_path && $r3->image_r_path, 'flat base64 {L,R} stored as iris L/R' );
ok( $r3 && substr( $r3->image_l_path, -4 ) === '.png', 'png magic bytes → .png extension' );

sec( 'no images — row byte-identical to a no-image capture (back-compat)' );
$cap4 = HDLV2_Iris_Support::build_capture_id( $a_cli1, $pA1, hash( 'sha256', $MARK . '-set4' ) );
$res = dispatch_cap( array( 'captureId' => $cap4, 'status' => 'done', 'finalized' => true, 'result' => co_result( 's4' ) ), $CB );
$r4 = co_row( $cap4 );
ok( $res->get_status() === 200 && $r4 && $r4->image_l_path === null && $r4->map_l_path === null, 'no images → all image columns NULL (unchanged)' );

sec( 'Rule-0 — flag OFF ⇒ /iris/clients 404s' );
update_option( 'hdlv2_ff_iris_consult', 0 );
rebuild_rest_server();
$routes = rest_get_server()->get_routes();
ok( ! isset( $routes['/hdl-v2/v1/iris/clients'] ), '/iris/clients NOT registered with the consult flag off' );
$res = dispatch_clients( array( 'email' => $emailA, 'header_secret' => $SHARED ) );
ok( $res->get_status() === 404, 'request 404s with the flag off (zero trace)' );

// ── cleanup ──
sec( 'cleanup' );
$IRIS_TBL = $wpdb->prefix . 'hdlv2_iris_results';
$imgs = $wpdb->get_results( $wpdb->prepare( "SELECT image_l_path,image_r_path,map_l_path,map_r_path FROM $IRIS_TBL WHERE client_user_id = %d", $a_cli1 ) );
foreach ( (array) $imgs as $im ) {
    foreach ( array( $im->image_l_path, $im->image_r_path, $im->map_l_path, $im->map_r_path ) as $f ) {
        if ( $f && is_file( co_iris_dir() . $f ) ) { @unlink( co_iris_dir() . $f ); }
    }
}
$wpdb->query( $wpdb->prepare( "DELETE FROM $IRIS_TBL WHERE client_user_id IN (%d,%d,%d)", $a_cli1, $a_cli2, $b_cli1 ) );
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
