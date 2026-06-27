<?php
/**
 * Iridology NATIVE-CAPTURE (Phase-2 pivot) INTEGRATION test — run on STBY via:
 *   wp eval-file tests/iris/iris-native-capture-stby.php --allow-root
 *
 * Proves the captureId receive contract HDL adapted from the embedded receiver:
 *   - The callback verifies the HMAC (good→accept, tampered/wrong-secret→403).
 *   - INSERT-ON-CALLBACK: a captureId HDL never minted creates the row, reading
 *     client/consultation off the deterministic key.
 *   - The auto safety-net DRAFT (finalized=false) and the "Send to
 *     HealthDataLab" FINAL (finalized=true) collapse to ONE row (terminal-wins);
 *     a late DRAFT never downgrades a FINAL; a re-push is idempotent (one row).
 *   - A NEW captureId (genuine re-shoot) archives the prior live row (never
 *     delete) and inserts a new one.
 *   - Display: an entitled practitioner sees the FINAL result; a DRAFT-only row
 *     shows "not yet captured" (state=draft, NO result leaked); a stranger 403s.
 *   - Rule-0: flag off ⇒ the callback route 404s and writes nothing.
 *
 * Mocks NOTHING — there is no outbound call on the native receive path (the
 * push is inbound). Self-cleaning: temp users + form_progress + iris rows tagged
 * with a unique marker, deleted + flags restored at the end.
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "run via wp eval-file\n" ); exit( 1 ); }

global $wpdb;

$GLOBALS['T'] = 0; $GLOBALS['F'] = 0;
function ok( $cond, $msg ) { $GLOBALS['T']++; if ( $cond ) { echo "  ok   - $msg\n"; } else { $GLOBALS['F']++; echo "  FAIL - $msg\n"; } }
function sec( $t ) { echo "\n# $t\n"; }

$MARK      = 'ircnc_' . substr( md5( (string) microtime( true ) ), 0, 8 );
$CB_SECRET = defined( 'HDL_CALLBACK_SECRET' ) ? HDL_CALLBACK_SECRET : '';
$IRIS_TBL  = $wpdb->prefix . 'hdlv2_iris_results';

$snap_addon   = get_option( 'hdlv2_ff_iris_addon' );
$snap_consult = get_option( 'hdlv2_ff_iris_consult' );

function rebuild_rest_server() { $GLOBALS['wp_rest_server'] = null; rest_get_server(); }

echo "=== Iris native-capture integration test ($MARK) ===\n";
echo "HDL_CALLBACK_SECRET defined: " . ( $CB_SECRET !== '' ? 'yes' : 'NO' ) . "\n";

// ── result payload (the deep-scrubbed REPORT_SCHEMA shape) ──
function nc_result( $tag ) {
    return array(
        'photo_quality' => array( 'usable' => true, 'issues' => array(), 'suggestion' => '' ),
        'eyes' => array(
            array( 'eye' => 'L', 'constitution_summary' => array( 'constitution' => 'lymphatic', 'colour_type' => 'blue', 'structure_grade' => '3', 'note' => $tag ),
                   'visible_observations' => array(), 'map_zone_notes' => array( 'zone ' . $tag ), 'suggested_questions' => array( 'ask ' . $tag ),
                   'low_confidence_or_not_visible' => array(), 'overall_confidence' => 'medium' ),
            array( 'eye' => 'R', 'constitution_summary' => array( 'constitution' => 'lymphatic', 'colour_type' => 'blue', 'structure_grade' => '3', 'note' => $tag ),
                   'visible_observations' => array(), 'map_zone_notes' => array(), 'suggested_questions' => array(),
                   'low_confidence_or_not_visible' => array(), 'overall_confidence' => 'low' ),
        ),
        'bilateral_notes' => array( 'symmetrical ' . $tag ),
    );
}

// ── dispatch an inbound IrisMapper→HDL callback (signs with the callback secret) ──
function dispatch_capture( $body, $secret, $sign_secret = null ) {
    $sign_secret = ( $sign_secret === null ) ? $secret : $sign_secret;
    $raw = wp_json_encode( $body );
    $h   = HDLV2_Iris_Support::sign_headers( $sign_secret, $raw );
    $req = new WP_REST_Request( 'POST', '/hdl-v2/v1/iris/analyse/callback' );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_header( 'x-hdl-callback-secret', $secret );
    $req->set_header( 'x-hdl-timestamp', $h['x-hdl-timestamp'] );
    $req->set_header( 'x-hdl-signature', $h['x-hdl-signature'] );
    $req->set_body( $raw );
    return rest_do_request( $req );
}

// ─────────────────────────────────────────────────────────────────────────
sec( 'schema — native-capture columns / enum / unique key present (DB v3.20)' );
ok( get_option( 'hdlv2_db_version' ) === '3.20', 'db_version == 3.20' );
$cols = $wpdb->get_col( "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$IRIS_TBL'" );
ok( in_array( 'capture_id', $cols, true ), 'capture_id column exists' );
ok( in_array( 'finalized', $cols, true ), 'finalized column exists' );
ok( in_array( 'source', $cols, true ), 'source column exists' );
ok( in_array( 'captured_at', $cols, true ), 'captured_at column exists' );
$status_type = (string) $wpdb->get_var( "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$IRIS_TBL' AND COLUMN_NAME = 'status'" );
ok( strpos( $status_type, "'draft'" ) !== false, "status enum includes 'draft'" );
$uniq = (int) $wpdb->get_var( "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$IRIS_TBL' AND INDEX_NAME = 'uniq_capture'" );
ok( $uniq > 0, 'UNIQUE KEY uniq_capture exists' );

// ── fixtures ──
sec( 'fixtures' );
$prac_id  = wp_insert_user( array( 'user_login' => $MARK . '_p', 'user_email' => $MARK . '_p@example.test', 'user_pass' => wp_generate_password(), 'role' => 'um_practitioner' ) );
$cli_id   = wp_insert_user( array( 'user_login' => $MARK . '_c', 'user_email' => $MARK . '_c@example.test', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$other_id = wp_insert_user( array( 'user_login' => $MARK . '_o', 'user_email' => $MARK . '_o@example.test', 'user_pass' => wp_generate_password(), 'role' => 'um_practitioner' ) );
ok( ! is_wp_error( $prac_id ) && ! is_wp_error( $cli_id ) && ! is_wp_error( $other_id ), 'temp users created' );
( new WP_User( $prac_id ) )->set_role( 'um_practitioner' );
( new WP_User( $other_id ) )->set_role( 'um_practitioner' );
$ent = array( 'found' => true, 'iridologyAddon' => true, 'hasReportAccess' => true, 'subscriptionTier' => 'practitioner', 'subscriptionStatus' => 'active' );
set_transient( 'hdlv2_irido_addon_' . $prac_id, $ent, 300 );
set_transient( 'hdlv2_irido_addon_' . $other_id, $ent, 300 );
$wpdb->insert( $wpdb->prefix . 'hdlv2_form_progress', array(
    'practitioner_user_id' => $prac_id, 'client_user_id' => $cli_id, 'current_stage' => 3,
), array( '%d', '%d', '%d' ) );
$progress_id = (int) $wpdb->insert_id;
ok( $progress_id > 0, "form_progress link row #$progress_id" );

// captureId over STABLE inputs (clientId:consultationId:irisSetHash).
$hash1 = hash( 'sha256', $MARK . '-set-1' );
$hash2 = hash( 'sha256', $MARK . '-set-2' );
$cap1  = HDLV2_Iris_Support::build_capture_id( $cli_id, $progress_id, $hash1 );
$cap2  = HDLV2_Iris_Support::build_capture_id( $cli_id, $progress_id, $hash2 );
ok( $cap1 && $cap2 && $cap1 !== $cap2, "built two captureIds ($cap1 / $cap2)" );

// flags ON
update_option( 'hdlv2_ff_iris_addon', 1 );
update_option( 'hdlv2_ff_iris_consult', 1 );
rebuild_rest_server();
ok( HDLV2_Iris_Consult::enabled() === true, 'enabled() true with both flags on' );

// NB: under `wp eval-file` the script body runs in a METHOD scope, so the
// top-level $IRIS_TBL is NOT a real global — compute the table name from the
// (genuinely global) $wpdb inside each helper.
function nc_row( $cap ) { global $wpdb; $t = $wpdb->prefix . 'hdlv2_iris_results'; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE capture_id = %s", $cap ) ); }
function nc_count_consult( $cli, $prog ) { global $wpdb; $t = $wpdb->prefix . 'hdlv2_iris_results'; return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE client_user_id = %d AND form_progress_id = %d", $cli, $prog ) ); }

// ─────────────────────────────────────────────────────────────────────────
sec( 'DRAFT push (auto safety-net) — valid HMAC, INSERT-ON-CALLBACK' );
$draft = array( 'captureId' => $cap1, 'status' => 'done', 'finalized' => false, 'result' => nc_result( 'draft' ), 'cost' => 0.04 );
$res = dispatch_capture( $draft, $CB_SECRET );
ok( $res->get_status() === 200, 'draft callback acks 200' );
$row = nc_row( $cap1 );
ok( $row && $row->status === 'draft', 'row created with status=draft (insert-on-callback)' );
ok( (int) $row->finalized === 0, 'finalized=0 for a draft' );
ok( $row->source === 'native', "source='native'" );
ok( (int) $row->client_user_id === (int) $cli_id && (int) $row->form_progress_id === (int) $progress_id, 'client/consultation read off the captureId' );
ok( (int) $row->practitioner_user_id === (int) $prac_id, 'practitioner resolved from the consultation link' );
ok( $row->job_id === $cap1 && $row->capture_id === $cap1, 'job_id reused = capture_id (NOT-NULL satisfied)' );
ok( $row->result_json && strpos( $row->result_json, 'draft' ) !== false, 'durable result held on the draft row' );
ok( $row->captured_at === null, 'captured_at NULL until finalised' );
ok( nc_count_consult( $cli_id, $progress_id ) === 1, 'exactly one row so far' );

sec( 'display — DRAFT shows "not yet captured" (state=draft, NO result leaked)' );
wp_set_current_user( $prac_id );
$req = new WP_REST_Request( 'GET', '/hdl-v2/v1/iris/analysis-status' );
$req->set_query_params( array( 'progress_id' => $progress_id, 'client_id' => $cli_id ) );
$d = rest_do_request( $req )->get_data();
ok( $d['state'] === 'draft', 'mount discovery returns state=draft' );
ok( ! isset( $d['result'] ), 'draft poll does NOT leak the result' );
ok( isset( $d['captureId'] ) && $d['captureId'] === $cap1, 'poll exposes the captureId' );

sec( 'HMAC — tampered body + wrong secret → 403, no row mutation' );
$raw_ok = wp_json_encode( $draft );
$good_h = HDLV2_Iris_Support::sign_headers( $CB_SECRET, $raw_ok );
$tampered = $draft; $tampered['cost'] = 9.99; // valid JSON, different bytes than signed
$req = new WP_REST_Request( 'POST', '/hdl-v2/v1/iris/analyse/callback' );
$req->set_header( 'content-type', 'application/json' );
$req->set_header( 'x-hdl-callback-secret', $CB_SECRET );
$req->set_header( 'x-hdl-timestamp', $good_h['x-hdl-timestamp'] );
$req->set_header( 'x-hdl-signature', $good_h['x-hdl-signature'] );
$req->set_body( wp_json_encode( $tampered ) );
ok( rest_do_request( $req )->get_status() === 403, 'tampered (valid-JSON) body → 403 (HMAC mismatch)' );
ok( dispatch_capture( $draft, 'wrong-secret' )->get_status() === 403, 'wrong callback secret → 403' );
ok( (float) nc_row( $cap1 )->cost < 1, 'cost unchanged by the rejected pushes (no mutation)' );

// ─────────────────────────────────────────────────────────────────────────
sec( 'FINAL push (same captureId) — DRAFT+FINAL collapse to ONE row (terminal-wins)' );
// Sentinel tags are UPPERCASE so the substring search can't collide with a
// static schema key (e.g. "bilateral_notes" contains a lowercase "late").
$final = array( 'captureId' => $cap1, 'status' => 'done', 'finalized' => true, 'result' => nc_result( 'FINAL9' ), 'cost' => 0.06 );
ok( dispatch_capture( $final, $CB_SECRET )->get_status() === 200, 'final callback acks 200' );
$row = nc_row( $cap1 );
ok( $row && $row->status === 'done', 'row flips draft → done' );
ok( (int) $row->finalized === 1, 'finalized flipped 0 → 1' );
ok( $row->captured_at !== null, 'captured_at stamped on finalise' );
ok( strpos( $row->result_json, 'FINAL9' ) !== false, 'result refreshed to the finalised content' );
ok( nc_count_consult( $cli_id, $progress_id ) === 1, 'STILL one row (collapsed, not a second insert)' );

sec( 'display — FINAL surfaces the editable result' );
$req = new WP_REST_Request( 'GET', '/hdl-v2/v1/iris/analysis-status' );
$req->set_query_params( array( 'progress_id' => $progress_id, 'client_id' => $cli_id ) );
$d = rest_do_request( $req )->get_data();
ok( $d['state'] === 'done' && isset( $d['result']['eyes'] ), 'done poll returns the result' );
ok( $d['captureId'] === $cap1, 'done poll keyed on captureId' );

sec( 'idempotent — re-push of the FINAL is a no-op (one row, 200)' );
ok( dispatch_capture( $final, $CB_SECRET )->get_status() === 200, 're-push acks 200' );
ok( nc_count_consult( $cli_id, $progress_id ) === 1, 're-push did NOT create a second row' );

sec( 'terminal-wins — a LATE DRAFT after FINAL never downgrades the row' );
$late_draft = array( 'captureId' => $cap1, 'status' => 'done', 'finalized' => false, 'result' => nc_result( 'LATE9' ), 'cost' => 0.01 );
ok( dispatch_capture( $late_draft, $CB_SECRET )->get_status() === 200, 'late draft acks 200' );
$row = nc_row( $cap1 );
ok( $row->status === 'done' && (int) $row->finalized === 1, 'row STAYS done/finalized (final wins over a late draft)' );
ok( strpos( $row->result_json, 'FINAL9' ) !== false && strpos( $row->result_json, 'LATE9' ) === false, 'finalised result not clobbered by the late draft' );

// ─────────────────────────────────────────────────────────────────────────
sec( 'NEW captureId (re-shoot) — archives prior live row, inserts a new one (never-delete)' );
$reshoot = array( 'captureId' => $cap2, 'status' => 'done', 'finalized' => false, 'result' => nc_result( 'reshoot' ), 'cost' => 0.05 );
ok( dispatch_capture( $reshoot, $CB_SECRET )->get_status() === 200, 'new-captureId draft acks 200' );
ok( nc_row( $cap1 )->status === 'archived', 'prior (cap1) row archived (history preserved, not deleted)' );
$row2 = nc_row( $cap2 );
ok( $row2 && $row2->status === 'draft', 'new (cap2) row inserted as draft' );
ok( nc_count_consult( $cli_id, $progress_id ) === 2, 'two rows now (one archived, one live)' );
$req = new WP_REST_Request( 'GET', '/hdl-v2/v1/iris/analysis-status' );
$req->set_query_params( array( 'progress_id' => $progress_id, 'client_id' => $cli_id ) );
$d = rest_do_request( $req )->get_data();
ok( $d['state'] === 'draft' && $d['captureId'] === $cap2, 'mount discovery now returns the latest (cap2) row' );

sec( 'first-push-is-FINAL — insert-on-callback with no preceding draft' );
$hash3 = hash( 'sha256', $MARK . '-set-3' );
$cap3  = HDLV2_Iris_Support::build_capture_id( $cli_id, $progress_id, $hash3 );
$final3 = array( 'captureId' => $cap3, 'status' => 'done', 'finalized' => true, 'result' => nc_result( 'final3' ), 'cost' => 0.07 );
ok( dispatch_capture( $final3, $CB_SECRET )->get_status() === 200, 'first-push-final acks 200' );
$row3 = nc_row( $cap3 );
ok( $row3 && $row3->status === 'done' && (int) $row3->finalized === 1, 'created directly as done/finalized (no draft needed)' );
ok( nc_row( $cap2 )->status === 'archived', 'cap2 archived by the cap3 re-analysis' );

// ─────────────────────────────────────────────────────────────────────────
sec( 'terminal-wins across captureIds — a NEW-captureId ERROR never supersedes a captured FINAL' );
// cap3 is the live done/finalized row. A failed re-analysis (new captureId,
// status:error) must NOT archive the captured FINAL or replace it with an error.
$hashE = hash( 'sha256', $MARK . '-set-err' );
$capE  = HDLV2_Iris_Support::build_capture_id( $cli_id, $progress_id, $hashE );
$err_push = array( 'captureId' => $capE, 'status' => 'error', 'error' => 'Opus refused', 'refused' => true );
ok( dispatch_capture( $err_push, $CB_SECRET )->get_status() === 200, 'new-captureId error acks 200 (delivery accepted)' );
ok( nc_row( $capE ) === null, 'the error wrote NO row (dropped — a captured result is protected)' );
$row3 = nc_row( $cap3 );
ok( $row3 && $row3->status === 'done' && (int) $row3->finalized === 1, 'cap3 FINAL stays live done/finalized (not archived by the error)' );
$req = new WP_REST_Request( 'GET', '/hdl-v2/v1/iris/analysis-status' );
$req->set_query_params( array( 'progress_id' => $progress_id, 'client_id' => $cli_id ) );
$d = rest_do_request( $req )->get_data();
ok( $d['state'] === 'done' && $d['captureId'] === $cap3, 'consult still shows the FINAL result, not an error banner' );

// ─────────────────────────────────────────────────────────────────────────
sec( 'IDOR — a stranger practitioner cannot read this consultation' );
wp_set_current_user( $other_id );
$req = new WP_REST_Request( 'GET', '/hdl-v2/v1/iris/analysis-status' );
$req->set_query_params( array( 'progress_id' => $progress_id, 'client_id' => $cli_id ) );
ok( rest_do_request( $req )->get_status() === 403, 'stranger gets 403 on another practitioner\'s consult' );
wp_set_current_user( $prac_id );

// ─────────────────────────────────────────────────────────────────────────
sec( 'Rule-0 — flag OFF ⇒ callback route 404s and writes nothing' );
update_option( 'hdlv2_ff_iris_consult', 0 );
rebuild_rest_server();
$hash4 = hash( 'sha256', $MARK . '-set-4' );
$cap4  = HDLV2_Iris_Support::build_capture_id( $cli_id, $progress_id, $hash4 );
$off = array( 'captureId' => $cap4, 'status' => 'done', 'finalized' => true, 'result' => nc_result( 'off' ), 'cost' => 0.02 );
ok( dispatch_capture( $off, $CB_SECRET )->get_status() === 404, 'callback 404s with the consult flag off' );
ok( nc_row( $cap4 ) === null, 'no row written while the flag is off (zero trace)' );
update_option( 'hdlv2_ff_iris_consult', 1 );
rebuild_rest_server();

// ─────────────────────────────────────────────────────────────────────────
sec( 'cleanup' );
$wpdb->query( $wpdb->prepare( "DELETE FROM $IRIS_TBL WHERE client_user_id = %d", $cli_id ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d", $progress_id ) );
delete_transient( 'hdlv2_irido_addon_' . $prac_id );
delete_transient( 'hdlv2_irido_addon_' . $other_id );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $prac_id ); wp_delete_user( $cli_id ); wp_delete_user( $other_id );
update_option( 'hdlv2_ff_iris_addon', $snap_addon !== false ? $snap_addon : 0 );
update_option( 'hdlv2_ff_iris_consult', $snap_consult !== false ? $snap_consult : 0 );
rebuild_rest_server();
ok( true, 'temp users/rows deleted; flags restored (addon=' . var_export( get_option( 'hdlv2_ff_iris_addon' ), true ) . ', consult=' . var_export( get_option( 'hdlv2_ff_iris_consult' ), true ) . ')' );

echo "\n────────────────────────────────────\n";
echo "  tests: {$GLOBALS['T']}   failures: {$GLOBALS['F']}\n";
echo ( $GLOBALS['F'] === 0 ? "  RESULT: PASS\n" : "  RESULT: FAIL\n" );
