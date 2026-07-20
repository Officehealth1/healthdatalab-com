<?php
/**
 * GAP-1 — LIVE-clone (STBY) proof against the REAL schema/queries.
 *
 * The unit tests use a faithful fake wpdb; this proves resolve_resend_status()
 * runs correctly against the actual form_progress / why_profiles /
 * consultation_notes columns, that the DISPLAY status still reads
 * needs_attention (red badge preserved), and that the resend REST handler
 * REFUSES + does NOT rotate the token for a red-flag client with no report.
 *
 * Disposable data (SQL inserts, no wp_mail on setup). Mail held via pre_wp_mail.
 * Byte-identical cleanup.
 *
 * Run (STBY): wp eval-file /root/gap1-live.php --exec 'define("FS_METHOD","direct");' --allow-root --skip-themes
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "wp eval-file only\n" ); exit( 1 ); }
if ( ! defined( 'DOING_AJAX' ) ) define( 'DOING_AJAX', true );

echo "~~GAP1-BEGIN~~\n";
$GLOBALS['g1_mail'] = 0;
add_filter( 'pre_wp_mail', function () { $GLOBALS['g1_mail']++; return true; }, 0 );

global $wpdb;
// NOTE: in `wp eval-file`, top-level vars are LOCALS, not globals — and $wp
// would collide with WordPress core's $wp object. So compute table names inside
// helpers from $GLOBALS['wpdb']->prefix and track fixtures via $GLOBALS['g1_made'].
$GLOBALS['g1_made'] = array();
$rand = substr( md5( uniqid() ), 0, 8 );

$prac = wp_insert_user( array( 'user_login' => "g1p_$rand", 'user_email' => "g1-prac-$rand@example.com", 'user_pass' => wp_generate_password( 20 ), 'role' => 'um_practitioner', 'display_name' => 'G1 Prac' ) );

$GLOBALS['g1_pass'] = 0; $GLOBALS['g1_fail'] = 0;
function ck( $n, $c, $d = '' ) { echo ( $c ? 'PASS' : 'FAIL' ) . " | $n" . ( $d ? " | $d" : '' ) . "\n"; $c ? $GLOBALS['g1_pass']++ : $GLOBALS['g1_fail']++; }

// Build a disposable RED-FLAG client at a chosen funnel position and return its uid.
function make_client( $prac, $rand, $tag, $stage1, $stage3, $why_released, $report ) {
    $wpdb = $GLOBALS['wpdb'];
    $fp_t = $wpdb->prefix . 'hdlv2_form_progress';
    $wp_t = $wpdb->prefix . 'hdlv2_why_profiles';
    $cn_t = $wpdb->prefix . 'hdlv2_consultation_notes';
    $email = "g1-$tag-$rand@example.com";
    $uid = wp_insert_user( array( 'user_login' => "g1c_{$tag}_$rand", 'user_email' => $email, 'user_pass' => wp_generate_password( 20 ), 'role' => 'um_client', 'display_name' => "G1 $tag" ) );
    $wpdb->insert( $fp_t, array(
        'client_user_id' => $uid, 'practitioner_user_id' => $prac, 'client_email' => $email,
        'current_stage' => $stage3 ? 3 : 2, 'has_flags' => 1, // RED FLAG in every case
        'token' => hash( 'sha256', "g1tok-$tag-$rand" ), 'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ),
        'stage1_completed_at' => $stage1, 'stage3_completed_at' => $stage3,
        'created_at' => current_time( 'mysql', true ),
    ) );
    $pid = (int) $wpdb->insert_id;
    if ( $why_released !== null ) {
        $wpdb->insert( $wp_t, array( 'form_progress_id' => $pid, 'released' => (int) $why_released, 'created_at' => current_time( 'mysql', true ) ) );
    }
    if ( $report ) {
        $wpdb->insert( $cn_t, array( 'form_progress_id' => $pid, 'status' => 'report_generated', 'created_at' => current_time( 'mysql', true ) ) );
    }
    $GLOBALS['g1_made'][] = array( 'uid' => $uid, 'pid' => $pid, 'email' => $email );
    return $uid;
}

$fp = $wpdb->prefix . 'hdlv2_form_progress';
$wp_t = $wpdb->prefix . 'hdlv2_why_profiles';
$cn_t = $wpdb->prefix . 'hdlv2_consultation_notes';
try {
    $now = '2026-01-01 00:00:00';

    // (A) red-flag, Stage 2 (stage1 done, no stage3, no WHY)
    $a = make_client( $prac, $rand, 'stage2', $now, null, null, false );
    $disp = HDLV2_Client_Status::calculate_status( $a );
    $send = HDLV2_Client_Status::resolve_resend_status( $a );
    $d    = HDLV2_Client_Status::resend_link_descriptor( $send['status'], false, $send['has_report'] );
    ck( 'A DISPLAY status stays needs_attention (red badge preserved)', $disp['status'] === 'needs_attention', 'display=' . $disp['status'] );
    ck( 'A SEND funnel = low_data (flag-blind), descriptor = Send Stage-2 link (continue, NOT report)',
        $send['status'] === 'low_data' && $d['link_kind'] === 'assessment' && $d['label'] === 'Send Stage-2 link',
        'send=' . $send['status'] . ' kind=' . $d['link_kind'] . ' label=' . $d['label'] );

    // (B) red-flag, Stage 3 done, NO report (the Margaret Hughes case)
    $b = make_client( $prac, $rand, 'noreport', $now, '2026-02-01 00:00:00', 1, false );
    $send = HDLV2_Client_Status::resolve_resend_status( $b );
    $d    = HDLV2_Client_Status::resend_link_descriptor( $send['status'], false, $send['has_report'] );
    ck( 'B red-flag Stage-3-done, 0 reports → funnel awaiting_consult, descriptor DISABLED',
        $send['status'] === 'awaiting_consult' && $d['enabled'] === false && ! $send['has_report'],
        'send=' . $send['status'] . ' enabled=' . var_export( $d['enabled'], true ) );

    // (B2) fire the REST handler for B — must REFUSE (422) and NOT rotate the token.
    $tok_before = $wpdb->get_var( $wpdb->prepare( "SELECT token FROM {$fp} WHERE client_user_id = %d", $b ) );
    wp_set_current_user( $prac );
    $GLOBALS['g1_mail'] = 0;
    $svc = HDLV2_Client_Status::get_instance();
    $resp = $svc->rest_resend_link( array( 'client_id' => $b ) );
    $tok_after = $wpdb->get_var( $wpdb->prepare( "SELECT token FROM {$fp} WHERE client_user_id = %d", $b ) );
    $is_422 = is_wp_error( $resp ) && (int) $resp->get_error_data()['status'] === 422;
    ck( 'B2 REST refuses (422), token NOT rotated, no email — no dead-link strand',
        $is_422 && $tok_before === $tok_after && $GLOBALS['g1_mail'] === 0,
        '422=' . var_export( $is_422, true ) . ' token_unchanged=' . var_export( $tok_before === $tok_after, true ) . ' mails=' . $GLOBALS['g1_mail'] );

    // (C) red-flag WITH a real report → resend the report (send-relevant complete)
    $c = make_client( $prac, $rand, 'withreport', $now, '2026-02-01 00:00:00', 1, true );
    $send = HDLV2_Client_Status::resolve_resend_status( $c );
    $d    = HDLV2_Client_Status::resend_link_descriptor( $send['status'], false, $send['has_report'] );
    ck( 'C red-flag WITH report → funnel progress_normal, descriptor Resend report (enabled)',
        $send['status'] === 'progress_normal' && $send['has_report'] && $d['enabled'] && $d['link_kind'] === 'report',
        'send=' . $send['status'] . ' kind=' . $d['link_kind'] );

} finally {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ( $GLOBALS['g1_made'] as $m ) {
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$fp} WHERE client_user_id = %d", $m['uid'] ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wp_t} WHERE form_progress_id = %d", $m['pid'] ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$cn_t} WHERE form_progress_id = %d", $m['pid'] ) );
        wp_delete_user( $m['uid'] );
    }
    wp_delete_user( $prac );
    echo 'cleanup: ' . count( $GLOBALS['g1_made'] ) . " disposable clients + prac + fp/why/report rows removed\n";
}

echo "\n{$GLOBALS['g1_pass']} passed, {$GLOBALS['g1_fail']} failed\n";
echo "~~GAP1-END~~\n";
exit( $GLOBALS['g1_fail'] ? 1 : 0 );
