<?php
/**
 * Iris contract-change INTEGRATION test (STBY) — two new IrisMapper contracts:
 *   1. create-checkout-simple may answer { alreadySubscribed:true,
 *      code:'ALREADY_SUBSCRIBED' } (200) instead of a checkout url → HDL's
 *      "Add it" must surface the already-subscribed state, never a 502.
 *   2. iris-analyse-status now requires the owning practitioner email → the
 *      reconcile cron must pass the email it knows per job.
 *
 * Run on STBY:  wp eval-file iris-contract-changes-stby.php   (as www-data)
 *
 * Mocks the IrisMapper boundary with `pre_http_request` (no network). Real WP
 * REST stack + real $wpdb + real Phase-2 classes. Self-cleaning: temp users +
 * form_progress + iris row, all tagged, deleted at the end; flags restored.
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "run via wp eval-file\n" ); exit( 1 ); }
global $wpdb;

$GLOBALS['T'] = 0; $GLOBALS['F'] = 0;
function ok( $c, $m ) { $GLOBALS['T']++; echo ( $c ? "  ok   - " : "  FAIL - " ) . $m . "\n"; if ( ! $c ) { $GLOBALS['F']++; } }
function sec( $t ) { echo "\n# $t\n"; }

$MARK     = 'ircc_' . substr( md5( (string) microtime( true ) ), 0, 8 );
$IRIS_TBL = $wpdb->prefix . 'hdlv2_iris_results';
$BRK_TBL  = $wpdb->prefix . 'hdlv2_iris_breaker';

$snap_addon   = get_option( 'hdlv2_ff_iris_addon' );
$snap_consult = get_option( 'hdlv2_ff_iris_consult' );
update_option( 'hdlv2_ff_iris_addon', 1 );
update_option( 'hdlv2_ff_iris_consult', 1 );

// ── HTTP mock — record every IrisMapper call; reply per the scripted modes ──
$GLOBALS['ircc_calls']         = array();
$GLOBALS['ircc_checkout_mode'] = 'url';        // url | subscribed
$GLOBALS['ircc_job']           = '__none__';   // colon-free token of the reconcile job we return 'done' for
                                               // (jobId rides the URL as %3A-encoded, so match a colon-free tail)
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
    if ( strpos( $url, '/.netlify/functions/' ) === false ) { return $pre; }
    $GLOBALS['ircc_calls'][] = $url;
    if ( strpos( $url, 'create-checkout-simple' ) !== false ) {
        if ( $GLOBALS['ircc_checkout_mode'] === 'subscribed' ) {
            return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'alreadySubscribed' => true, 'code' => 'ALREADY_SUBSCRIBED' ) ) );
        }
        return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'url' => 'https://checkout.stripe.com/c/test_session' ) ) );
    }
    if ( strpos( $url, 'iris-analyse-status' ) !== false ) {
        // Return a terminal 'done' ONLY for our own job — never mutate another
        // session's queued row that the same reconcile sweep might pick up.
        if ( strpos( $url, $GLOBALS['ircc_job'] ) !== false ) {
            return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'status' => 'done', 'result' => array( 'eyes' => array( array( 'eye' => 'L' ), array( 'eye' => 'R' ) ) ), 'cost' => 0.01 ) ) );
        }
        return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'status' => 'running' ) ) );
    }
    return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
}, 10, 3 );

echo "=== Iris contract-change integration test ($MARK) ===\n";

// ── fixtures ──
sec( 'fixtures' );
$prac = wp_insert_user( array( 'user_login' => $MARK . '_p', 'user_email' => $MARK . '_p@example.test', 'user_pass' => wp_generate_password(), 'role' => 'um_practitioner' ) );
$cli  = wp_insert_user( array( 'user_login' => $MARK . '_c', 'user_email' => $MARK . '_c@example.test', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
ok( ! is_wp_error( $prac ) && ! is_wp_error( $cli ), 'temp users created' );
( new WP_User( $prac ) )->set_role( 'um_practitioner' );
$prac_email = ( new WP_User( $prac ) )->user_email;
set_transient( 'hdlv2_irido_addon_' . $prac, array( 'found' => true, 'iridologyAddon' => true, 'hasReportAccess' => true, 'subscriptionTier' => 'practitioner', 'subscriptionStatus' => 'active' ), 300 );
$wpdb->insert( $wpdb->prefix . 'hdlv2_form_progress', array( 'practitioner_user_id' => $prac, 'client_user_id' => $cli, 'current_stage' => 3 ), array( '%d', '%d', '%d' ) );
$progress = (int) $wpdb->insert_id;
ok( $progress > 0, "form_progress link #$progress (prac=$prac cli=$cli)" );

// ─────────────────────────────────────────────────────────────────────────
sec( 'Task 1 — create_checkout() branches on ALREADY_SUBSCRIBED (no 502)' );
$dash = home_url( '/clients/' );
$GLOBALS['ircc_checkout_mode'] = 'subscribed';
$r = HDLV2_Iris_Addon::create_checkout( $prac_email, $dash, $dash );
ok( ! is_wp_error( $r ) && is_array( $r ) && ! empty( $r['alreadySubscribed'] ) && empty( $r['url'] ), 'create_checkout(subscribed) → array alreadySubscribed=true, no url, NOT WP_Error' );
$GLOBALS['ircc_checkout_mode'] = 'url';
$r = HDLV2_Iris_Addon::create_checkout( $prac_email, $dash, $dash );
ok( ! is_wp_error( $r ) && is_array( $r ) && empty( $r['alreadySubscribed'] ) && isset( $r['url'] ) && strpos( $r['url'], 'checkout.stripe.com' ) !== false, 'create_checkout(new buyer) → array with url, alreadySubscribed falsey' );

sec( 'Task 1 — POST /iris/checkout end-to-end (logged-in practitioner)' );
wp_set_current_user( $prac );
$GLOBALS['wp_rest_server'] = null; rest_get_server(); // register routes with flags on
$GLOBALS['ircc_checkout_mode'] = 'subscribed';
$req = new WP_REST_Request( 'POST', '/hdl-v2/v1/iris/checkout' ); $req->set_body_params( array() );
$res = rest_do_request( $req ); $d = $res->get_data();
ok( $res->get_status() === 200 && ! empty( $d['alreadySubscribed'] ) && empty( $d['url'] ), 'POST /iris/checkout (subscribed) → 200 {alreadySubscribed:true}, no url' );
$GLOBALS['ircc_checkout_mode'] = 'url';
$req = new WP_REST_Request( 'POST', '/hdl-v2/v1/iris/checkout' ); $req->set_body_params( array() );
$res = rest_do_request( $req ); $d = $res->get_data();
ok( $res->get_status() === 200 && isset( $d['url'] ) && strpos( $d['url'], 'checkout.stripe.com' ) !== false, 'POST /iris/checkout (new buyer) → 200 {url} (regression guard)' );

// ─────────────────────────────────────────────────────────────────────────
sec( 'Task 2 — reconcile cron passes the owner email to iris-analyse-status' );
$job = $cli . ':' . $progress . ':reconcile' . $MARK; // valid job_id (client:consult:hash)
$GLOBALS['ircc_job'] = 'reconcile' . $MARK;            // colon-free → survives %3A URL-encoding in the mock match
$old = '2020-01-01 00:00:00'; // far past the RECONCILE_GRACE_S cutoff → eligible + sorts first
$wpdb->insert( $IRIS_TBL, array(
    'client_user_id' => $cli, 'practitioner_user_id' => $prac, 'form_progress_id' => $progress,
    'job_id' => $job, 'idempotency_key' => $job, 'status' => 'queued', 'map_name' => 'Jensen',
    'eyes_label' => 'Left + Right', 'updated_at' => $old,
), array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );
ok( $wpdb->insert_id > 0, "queued reconcile row inserted (job=$job, updated_at=$old)" );
$wpdb->query( "UPDATE $BRK_TBL SET state='closed', failures=0, opened_at=0 WHERE id=1" ); // breaker closed → call allowed
$GLOBALS['ircc_calls'] = array();
HDLV2_Iris_Consult::get_instance()->cron_reconcile();
$mine = '';
foreach ( $GLOBALS['ircc_calls'] as $u ) {
    if ( strpos( $u, 'iris-analyse-status' ) !== false && strpos( $u, 'reconcile' . $MARK ) !== false ) { $mine = $u; break; }
}
ok( $mine !== '', 'reconcile made an iris-analyse-status call for our job' );
ok( $mine !== '' && strpos( $mine, 'jobId=' ) !== false, '...the call carries jobId' );
ok( $mine !== '' && strpos( $mine, 'email=' . urlencode( $prac_email ) ) !== false, '...the call carries the owner email (NEW contract)' );
$st = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $IRIS_TBL WHERE job_id=%s", $job ) );
ok( $st === 'done', 'reconcile STILL WORKS — our job applied terminal (queued → done)' );

// ─────────────────────────────────────────────────────────────────────────
sec( 'cleanup' );
$wpdb->query( $wpdb->prepare( "DELETE FROM $IRIS_TBL WHERE client_user_id=%d", $cli ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}hdlv2_form_progress WHERE id=%d", $progress ) );
delete_transient( 'hdlv2_irido_addon_' . $prac );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $prac ); wp_delete_user( $cli );
$wpdb->query( "UPDATE $BRK_TBL SET state='closed', failures=0, opened_at=0 WHERE id=1" );
update_option( 'hdlv2_ff_iris_addon', $snap_addon !== false ? $snap_addon : 0 );
update_option( 'hdlv2_ff_iris_consult', $snap_consult !== false ? $snap_consult : 0 );
ok( true, 'temp users/rows deleted; flags restored' );

echo "\n────────────────────────────────────\n";
echo "  tests: {$GLOBALS['T']}   failures: {$GLOBALS['F']}\n";
echo ( $GLOBALS['F'] === 0 ? "  RESULT: PASS\n" : "  RESULT: FAIL\n" );
