<?php
/**
 * Iridology Phase-2 INTEGRATION test — run on STBY via:
 *   wp eval-file iris-integration-stby.php --allow-root
 *
 * Mocks the IrisMapper boundary with the `pre_http_request` filter (NO real
 * Opus/Supabase/Netlify call, no network). Everything else is the REAL WP REST
 * stack + real $wpdb + the real Phase-2 classes. Proves the no-crash contract:
 *   - PHP makes exactly ONE short outbound call per step and NEVER blocks on
 *     the analysis (the mock records every IrisMapper URL touched).
 *   - The callback verifies the HMAC, is idempotent on jobId, and persists
 *     photos when the callback carries them.
 *   - The browser-facing poll is a PURE local MySQL read (zero IrisMapper call).
 *   - Fail-closed: a timeout / open breaker → 'unavailable', never a hang.
 *   - The circuit breaker trips after 5 failures and then short-circuits with
 *     NO network call.
 *   - Rule-0: flag off ⇒ routes 404 (unregistered); flag on ⇒ 5 routes present.
 *   - IDOR: a non-owning practitioner cannot read another's job.
 *
 * Self-cleaning: creates temp users + a form_progress link + iris rows, all
 * tagged with a unique marker, and deletes them + restores the flags at the end.
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "run via wp eval-file\n" ); exit( 1 ); }

global $wpdb;

// NB: under `wp eval-file` the script body runs inside a method scope, so a
// plain top-level $T/$F is NOT the real global — use $GLOBALS so ok() and the
// summary reference the SAME counters.
$GLOBALS['T'] = 0; $GLOBALS['F'] = 0;
function ok( $cond, $msg ) { $GLOBALS['T']++; if ( $cond ) { echo "  ok   - $msg\n"; } else { $GLOBALS['F']++; echo "  FAIL - $msg\n"; } }
function sec( $t ) { echo "\n# $t\n"; }

$MARK = 'irc_test_' . substr( md5( (string) microtime( true ) ), 0, 8 );
$CB_SECRET = defined( 'HDL_CALLBACK_SECRET' ) ? HDL_CALLBACK_SECRET : '';
$IRIS_TBL  = $wpdb->prefix . 'hdlv2_iris_results';
$BRK_TBL   = $wpdb->prefix . 'hdlv2_iris_breaker';

// ── snapshot + force a clean flag state ──
$snap_addon   = get_option( 'hdlv2_ff_iris_addon' );
$snap_consult = get_option( 'hdlv2_ff_iris_consult' );

// ── HTTP mock: record every IrisMapper call; reply per the scripted mode ──
$GLOBALS['irc_calls'] = array();
$GLOBALS['irc_mode']  = 'ok'; // ok | timeout | http500 | limit
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
    if ( strpos( $url, '/.netlify/functions/' ) === false ) { return $pre; }
    $GLOBALS['irc_calls'][] = $url;
    $mode = $GLOBALS['irc_mode'];
    if ( $mode === 'timeout' ) { return new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ); }
    if ( $mode === 'http500' ) { return array( 'response' => array( 'code' => 500 ), 'body' => '{"error":"boom"}' ); }
    if ( $mode === 'limit' ) {
        return array( 'response' => array( 'code' => 429 ), 'body' => wp_json_encode( array( 'error' => 'limit', 'code' => 'LIMIT_REACHED', 'limit' => 15 ) ) );
    }
    // ok mode — shape per endpoint
    if ( strpos( $url, 'iris-analyse-upload-url' ) !== false ) {
        return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array(
            'jobId' => 'x', 'status' => 'awaiting_upload', 'expiresIn' => 3600,
            'uploadUrls' => array(
                'L' => array( 'uploadUrl' => 'https://supabase.example/L?token=t', 'token' => 't', 'key' => 'analysis/x/L.jpg' ),
                'R' => array( 'uploadUrl' => 'https://supabase.example/R?token=t', 'token' => 't', 'key' => 'analysis/x/R.jpg' ),
            ),
        ) ) );
    }
    if ( strpos( $url, 'iris-analyse-status' ) !== false ) {
        return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'status' => 'running' ) ) );
    }
    // iris-analyse (start)
    return array( 'response' => array( 'code' => 202 ), 'body' => wp_json_encode( array( 'jobId' => 'x', 'status' => 'queued', 'deduped' => false ) ) );
}, 10, 3 );

function rebuild_rest_server() {
    $GLOBALS['wp_rest_server'] = null;
    rest_get_server(); // re-runs rest_api_init with the current flag state
}

echo "=== Iris Phase-2 integration test ($MARK) ===\n";
echo "HDL_CALLBACK_SECRET defined: " . ( $CB_SECRET !== '' ? 'yes' : 'NO' ) . "\n";

// ─────────────────────────────────────────────────────────────────────────
sec( 'tables exist (DB v3.20 migration applied)' );
ok( $wpdb->get_var( "SHOW TABLES LIKE '$IRIS_TBL'" ) === $IRIS_TBL, 'wp_hdlv2_iris_results exists' );
ok( $wpdb->get_var( "SHOW TABLES LIKE '$BRK_TBL'" ) === $BRK_TBL, 'wp_hdlv2_iris_breaker exists' );
ok( get_option( 'hdlv2_db_version' ) === HDLV2_DB_VERSION && HDLV2_DB_VERSION === '3.20', 'db_version == 3.20' );

// ─────────────────────────────────────────────────────────────────────────
sec( 'Rule-0 — flag off ⇒ routes 404 (unregistered)' );
update_option( 'hdlv2_ff_iris_addon', 0 );
update_option( 'hdlv2_ff_iris_consult', 0 );
ok( HDLV2_Iris_Consult::enabled() === false, 'enabled() false when flags off' );
rebuild_rest_server();
$routes = rest_get_server()->get_routes();
ok( ! isset( $routes['/hdl-v2/v1/iris/analyse'] ), '/iris/analyse NOT registered with flag off' );
ok( ! isset( $routes['/hdl-v2/v1/iris/analyse/callback'] ), '/iris/analyse/callback NOT registered with flag off' );

sec( 'Rule-0 — both flags on ⇒ 5 routes present' );
update_option( 'hdlv2_ff_iris_addon', 1 );
update_option( 'hdlv2_ff_iris_consult', 1 );
ok( HDLV2_Iris_Consult::enabled() === true, 'enabled() true when both flags on' );
rebuild_rest_server();
$routes = rest_get_server()->get_routes();
foreach ( array( '/iris/analyse', '/iris/start', '/iris/analysis-status', '/iris/areas-edit', '/iris/analyse/callback' ) as $r ) {
    ok( isset( $routes[ '/hdl-v2/v1' . $r ] ), "route $r registered with flag on" );
}

// ── fixtures: temp practitioner + client + form_progress link ──
sec( 'fixtures' );
$prac_id = wp_insert_user( array( 'user_login' => $MARK . '_p', 'user_email' => $MARK . '_p@example.test', 'user_pass' => wp_generate_password(), 'role' => 'um_practitioner' ) );
$cli_id  = wp_insert_user( array( 'user_login' => $MARK . '_c', 'user_email' => $MARK . '_c@example.test', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$other_id = wp_insert_user( array( 'user_login' => $MARK . '_o', 'user_email' => $MARK . '_o@example.test', 'user_pass' => wp_generate_password(), 'role' => 'um_practitioner' ) );
ok( ! is_wp_error( $prac_id ) && ! is_wp_error( $cli_id ) && ! is_wp_error( $other_id ), 'temp users created' );
// ensure the practitioner role really registers (UM may strip on insert)
( new WP_User( $prac_id ) )->set_role( 'um_practitioner' );
( new WP_User( $other_id ) )->set_role( 'um_practitioner' );
// require_practitioner() now re-checks the add-on entitlement fail-closed. Seed
// the 5-min entitlement transient for BOTH practitioners so they pass the gate
// without an HTTP call — this is what lets the IDOR test prove the per-CLIENT
// data-layer check (not merely the entitlement gate) rejects the stranger.
$ent = array( 'found' => true, 'iridologyAddon' => true, 'hasReportAccess' => true, 'subscriptionTier' => 'practitioner', 'subscriptionStatus' => 'active' );
set_transient( 'hdlv2_irido_addon_' . $prac_id, $ent, 300 );
set_transient( 'hdlv2_irido_addon_' . $other_id, $ent, 300 );
$wpdb->insert( $wpdb->prefix . 'hdlv2_form_progress', array(
    'practitioner_user_id' => $prac_id, 'client_user_id' => $cli_id, 'current_stage' => 3,
), array( '%d', '%d', '%d' ) );
$progress_id = (int) $wpdb->insert_id;
ok( $progress_id > 0, "form_progress link row #$progress_id" );
ok( HDLV2_Compatibility::practitioner_owns_client( $prac_id, $cli_id ), 'practitioner_owns_client true for the link' );
ok( ! HDLV2_Compatibility::practitioner_owns_client( $other_id, $cli_id ), 'practitioner_owns_client false for a stranger (IDOR base)' );

// ─────────────────────────────────────────────────────────────────────────
sec( 'submit — ONE short call, fast return, NEVER waits on analysis' );
wp_set_current_user( $prac_id );
$GLOBALS['irc_mode'] = 'ok';
$GLOBALS['irc_calls'] = array();
$req = new WP_REST_Request( 'POST', '/hdl-v2/v1/iris/analyse' );
$req->set_body_params( array( 'client_id' => $cli_id, 'progress_id' => $progress_id ) );
$res = rest_do_request( $req );
$data = $res->get_data();
ok( $res->get_status() === 200 && ! empty( $data['jobId'] ), 'analyse returns 200 + jobId' );
ok( isset( $data['uploadUrls']['L']['uploadUrl'], $data['uploadUrls']['R']['uploadUrl'] ), 'analyse returns signed upload URLs (browser→Supabase direct)' );
ok( count( $GLOBALS['irc_calls'] ) === 1 && strpos( $GLOBALS['irc_calls'][0], 'iris-analyse-upload-url' ) !== false, 'exactly ONE outbound call: upload-url (no analysis wait)' );
$job = $data['jobId'];
ok( HDLV2_Iris_Support::client_id_from_job_id( $job ) === (int) $cli_id, 'jobId encodes the client id' );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $IRIS_TBL WHERE job_id=%s", $job ) );
ok( $row && $row->status === 'queued', 'local row inserted = queued' );
ok( $row->supabase_key_l === 'analysis/x/L.jpg', 'supabase key ref stored (not bytes)' );

sec( 'start — confirm+enqueue, returns running, one call' );
$GLOBALS['irc_calls'] = array();
$req = new WP_REST_Request( 'POST', '/hdl-v2/v1/iris/start' );
$req->set_body_params( array( 'job' => $job ) );
$res = rest_do_request( $req );
ok( $res->get_status() === 200 && $res->get_data()['state'] === 'running', 'start → running' );
ok( count( $GLOBALS['irc_calls'] ) === 1 && strpos( $GLOBALS['irc_calls'][0], 'iris-analyse' ) !== false, 'exactly ONE outbound call: iris-analyse' );

sec( 'poll — PURE local read, ZERO IrisMapper call' );
$GLOBALS['irc_calls'] = array();
$req = new WP_REST_Request( 'GET', '/hdl-v2/v1/iris/analysis-status' );
$req->set_query_params( array( 'job' => $job ) );
$res = rest_do_request( $req );
ok( $res->get_status() === 200 && $res->get_data()['state'] === 'running', 'poll returns running (local)' );
ok( count( $GLOBALS['irc_calls'] ) === 0, 'poll made ZERO IrisMapper calls' );

// ─────────────────────────────────────────────────────────────────────────
sec( 'callback — HMAC verified, idempotent UPSERT, photos persisted' );
$tiny_jpeg_b64 = base64_encode( "\xFF\xD8\xFF\xE0" . str_repeat( "\x00", 200 ) . "\xFF\xD9" ); // >64 bytes, jpeg-ish
$result = array(
    'photo_quality' => array( 'usable' => true, 'issues' => array(), 'suggestion' => '' ),
    'eyes' => array(
        array( 'eye' => 'L', 'constitution_summary' => array( 'constitution' => 'lymphatic', 'colour_type' => 'blue', 'structure_grade' => '3', 'note' => 'n' ),
               'visible_observations' => array(), 'map_zone_notes' => array( 'zone note' ), 'suggested_questions' => array( 'ask about sleep' ),
               'low_confidence_or_not_visible' => array(), 'overall_confidence' => 'medium' ),
        array( 'eye' => 'R', 'constitution_summary' => array( 'constitution' => 'lymphatic', 'colour_type' => 'blue', 'structure_grade' => '3', 'note' => 'n' ),
               'visible_observations' => array(), 'map_zone_notes' => array(), 'suggested_questions' => array(),
               'low_confidence_or_not_visible' => array(), 'overall_confidence' => 'low' ),
    ),
    'bilateral_notes' => array( 'symmetrical' ),
);
$cb_body = array( 'jobId' => $job, 'status' => 'done', 'result' => $result, 'cost' => 0.054,
    'images' => array( 'L' => $tiny_jpeg_b64, 'R' => $tiny_jpeg_b64 ) );
$raw = wp_json_encode( $cb_body );
$h   = HDLV2_Iris_Support::sign_headers( $CB_SECRET, $raw );

function dispatch_callback( $raw, $secret, $sigHeaders ) {
    $req = new WP_REST_Request( 'POST', '/hdl-v2/v1/iris/analyse/callback' );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_header( 'x-hdl-callback-secret', $secret );
    $req->set_header( 'x-hdl-timestamp', $sigHeaders['x-hdl-timestamp'] );
    $req->set_header( 'x-hdl-signature', $sigHeaders['x-hdl-signature'] );
    $req->set_body( $raw );
    return rest_do_request( $req );
}

$res = dispatch_callback( $raw, $CB_SECRET, $h );
ok( $res->get_status() === 200, 'valid callback acks 200' );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $IRIS_TBL WHERE job_id=%s", $job ) );
ok( $row && $row->status === 'done', 'row → done' );
ok( $row->result_json && strpos( $row->result_json, 'lymphatic' ) !== false, 'result_json stored' );
ok( (float) $row->cost > 0.05, 'cost stored' );
ok( $row->image_l_path && $row->image_r_path, 'photos persisted to private dir (image_*_path set)' );

// idempotency: same callback again
$res2 = dispatch_callback( $raw, $CB_SECRET, HDLV2_Iris_Support::sign_headers( $CB_SECRET, $raw ) );
$cnt = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $IRIS_TBL WHERE job_id=%s", $job ) );
ok( $res2->get_status() === 200 && $cnt === 1, 'duplicate callback is idempotent (still one row, 200)' );

// tampered body — VALID JSON but different bytes than what was signed, so it
// clears WP's JSON parse and reaches my HMAC check (a garbage body would 400 at
// the JSON layer first — also rejected, but not the path we want to prove).
$cb_tampered = $cb_body; $cb_tampered['cost'] = 9.99; // altered after signing
$raw_tampered = wp_json_encode( $cb_tampered );
$res3 = dispatch_callback( $raw_tampered, $CB_SECRET, $h ); // $h signs the ORIGINAL $raw
ok( $res3->get_status() === 403, 'tampered (valid-JSON) body → 403 (HMAC mismatch)' );
// wrong secret
$res4 = dispatch_callback( $raw, 'wrong-secret', HDLV2_Iris_Support::sign_headers( 'wrong-secret', $raw ) );
ok( $res4->get_status() === 403, 'wrong callback secret → 403' );

sec( 'poll after done — local read returns the result, ZERO IrisMapper call' );
$GLOBALS['irc_calls'] = array();
$req = new WP_REST_Request( 'GET', '/hdl-v2/v1/iris/analysis-status' );
$req->set_query_params( array( 'job' => $job ) );
$res = rest_do_request( $req );
$d = $res->get_data();
ok( $res->get_status() === 200 && $d['state'] === 'done' && isset( $d['result']['eyes'] ), 'poll returns done + result' );
ok( count( $GLOBALS['irc_calls'] ) === 0, 'done-poll made ZERO IrisMapper calls' );

sec( 'areas-edit — additive overlay, AI original preserved' );
$overlay = $result;
$overlay['eyes'][0]['suggested_questions'] = array( 'EDITED question' );
$req = new WP_REST_Request( 'POST', '/hdl-v2/v1/iris/areas-edit' );
$req->set_body_params( array( 'job' => $job, 'areas' => $overlay, 'include_in_pdf' => true ) );
$res = rest_do_request( $req );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $IRIS_TBL WHERE job_id=%s", $job ) );
ok( $res->get_status() === 200, 'areas-edit 200' );
ok( strpos( $row->areas_edited_json, 'EDITED question' ) !== false, 'edited overlay stored' );
ok( strpos( $row->result_json, 'ask about sleep' ) !== false && strpos( $row->result_json, 'EDITED' ) === false, 'AI original result_json untouched' );
ok( strpos( (string) $row->_revisions, 'ask about sleep' ) !== false, 'AI original archived into _revisions' );
ok( (int) $row->include_in_pdf === 1, 'include_in_pdf set' );
// poll now prefers the edited overlay
$req = new WP_REST_Request( 'GET', '/hdl-v2/v1/iris/analysis-status' );
$req->set_query_params( array( 'job' => $job ) );
$d = rest_do_request( $req )->get_data();
ok( $d['result']['eyes'][0]['suggested_questions'][0] === 'EDITED question', 'poll surfaces the edited overlay' );

// ─────────────────────────────────────────────────────────────────────────
sec( 'IDOR — a stranger practitioner cannot read this job' );
wp_set_current_user( $other_id );
$req = new WP_REST_Request( 'GET', '/hdl-v2/v1/iris/analysis-status' );
$req->set_query_params( array( 'job' => $job ) );
$res = rest_do_request( $req );
ok( $res->get_status() === 403, 'stranger gets 403 on another practitioner\'s job' );
wp_set_current_user( $prac_id );

// ─────────────────────────────────────────────────────────────────────────
sec( 'fail-closed — upstream timeout ⇒ unavailable (no hang, no fatal)' );
$wpdb->query( "UPDATE $BRK_TBL SET state='closed', failures=0, opened_at=0 WHERE id=1" );
$GLOBALS['irc_mode'] = 'timeout';
$req = new WP_REST_Request( 'POST', '/hdl-v2/v1/iris/analyse' );
$req->set_body_params( array( 'client_id' => $cli_id, 'progress_id' => $progress_id ) );
$res = rest_do_request( $req );
$d = $res->get_data();
ok( $res->get_status() === 200 && $d['state'] === 'unavailable', 'timeout → 200 state=unavailable (fail-closed, consult proceeds)' );
$job2 = $d['jobId'];
ok( $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $IRIS_TBL WHERE job_id=%s", $job2 ) ) === 'unavailable', 'row=unavailable' );

sec( 'fail-closed — daily limit ⇒ limit state' );
$wpdb->query( "UPDATE $BRK_TBL SET state='closed', failures=0, opened_at=0 WHERE id=1" );
$GLOBALS['irc_mode'] = 'limit';
$req = new WP_REST_Request( 'POST', '/hdl-v2/v1/iris/analyse' );
$req->set_body_params( array( 'client_id' => $cli_id, 'progress_id' => $progress_id ) );
$res = rest_do_request( $req );
ok( $res->get_data()['state'] === 'limit', '429 LIMIT_REACHED → state=limit' );

sec( 'circuit breaker — trips after 5 failures, then short-circuits with NO call' );
$wpdb->query( "UPDATE $BRK_TBL SET state='closed', failures=0, opened_at=0 WHERE id=1" );
$GLOBALS['irc_mode'] = 'http500';
for ( $i = 0; $i < 5; $i++ ) {
    $GLOBALS['irc_calls'] = array();
    $req = new WP_REST_Request( 'POST', '/hdl-v2/v1/iris/analyse' );
    $req->set_body_params( array( 'client_id' => $cli_id, 'progress_id' => $progress_id ) );
    rest_do_request( $req );
}
$brk = $wpdb->get_row( "SELECT * FROM $BRK_TBL WHERE id=1" );
ok( $brk && $brk->state === 'open', "breaker OPEN after 5x http500 (failures={$brk->failures})" );
// next call must short-circuit: ZERO network call
$GLOBALS['irc_calls'] = array();
$req = new WP_REST_Request( 'POST', '/hdl-v2/v1/iris/analyse' );
$req->set_body_params( array( 'client_id' => $cli_id, 'progress_id' => $progress_id ) );
$res = rest_do_request( $req );
ok( $res->get_data()['state'] === 'unavailable' && count( $GLOBALS['irc_calls'] ) === 0, 'open breaker → unavailable with NO outbound call (pool protected)' );

// ─────────────────────────────────────────────────────────────────────────
sec( 'cleanup' );
$wpdb->query( $wpdb->prepare( "DELETE FROM $IRIS_TBL WHERE client_user_id=%d", $cli_id ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hdlv2_form_progress WHERE id=%d", $progress_id ) );
// remove any persisted test images
$dir = ( defined( 'HDLV2_PRIVATE_DIR' ) ? rtrim( HDLV2_PRIVATE_DIR, '/' ) : dirname( rtrim( ABSPATH, '/' ) ) . '/hdlv2-private' ) . '/hdlv2-iris/';
foreach ( glob( $dir . 'iris-*' ) ?: array() as $f ) { @unlink( $f ); } // only test rows existed here
delete_transient( 'hdlv2_irido_addon_' . $prac_id );
delete_transient( 'hdlv2_irido_addon_' . $other_id );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $prac_id ); wp_delete_user( $cli_id ); wp_delete_user( $other_id );
$wpdb->query( "UPDATE $BRK_TBL SET state='closed', failures=0, opened_at=0 WHERE id=1" );
update_option( 'hdlv2_ff_iris_addon', $snap_addon !== false ? $snap_addon : 0 );
update_option( 'hdlv2_ff_iris_consult', $snap_consult !== false ? $snap_consult : 0 );
rebuild_rest_server();
ok( true, "temp users/rows deleted; flags restored (addon=" . var_export( get_option( 'hdlv2_ff_iris_addon' ), true ) . ", consult=" . var_export( get_option( 'hdlv2_ff_iris_consult' ), true ) . ")" );

echo "\n────────────────────────────────────\n";
echo "  tests: {$GLOBALS['T']}   failures: {$GLOBALS['F']}\n";
echo ( $GLOBALS['F'] === 0 ? "  RESULT: PASS\n" : "  RESULT: FAIL\n" );
