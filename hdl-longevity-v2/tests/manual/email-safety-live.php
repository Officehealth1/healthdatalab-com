<?php
/**
 * STBY live proof — email-safety belt + braces (v0.47.74).
 *
 * Run ON STBY:  wp eval-file tests/manual/email-safety-live.php --allow-root
 *
 * Proves, through the REAL send_reminders cron handler + the REAL
 * hdl-stby-mail-guard mu-plugin + the REAL Post SMTP → Brevo pipeline:
 *   P1  whitelisted disposable recipient → DELIVERED as-is (untagged);
 *   P2  real-shaped recipient → redirected to the catcher inbox with the
 *       [STBY-BLOCKED -> original] tag — the real address receives NOTHING;
 *   P3  the block is recorded in the hdl_stby_mail_guard_log ring buffer;
 *   P4  exactly the two fixture sends happened (every genuine candidate
 *       was cooldown-masked for the run).
 *
 * Disposable fixtures only; deletes everything it created (fixture rows,
 * its cooldown masks) and reports residuals. The two Post SMTP log rows
 * remain by design — they ARE the delivery evidence (audit trail).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$pass = 0; $fail = 0;
$check = function ( $label, $ok ) use ( &$pass, &$fail ) {
    echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n";
    $ok ? $pass++ : $fail++;
};

$WHITELISTED = 'team+guardtest@irislab.com';
$REAL_SHAPED = 'stby-guard-fixture-do-not-deliver@gmail.com';
$UID_WL      = 999901;
$UID_REAL    = 999902;
$P            = $wpdb->prefix;

// ── Phase A: baseline + mask every genuine candidate ──
$fp_count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$P}hdlv2_form_progress" );
$mail_max_before = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$P}post_smtp_logs" );

$candidates = $wpdb->get_col(
    "SELECT DISTINCT client_user_id FROM {$P}hdlv2_form_progress
     WHERE stage3_completed_at IS NOT NULL AND client_email != '' AND deleted_at IS NULL"
);
$masked = array();
foreach ( $candidates as $uid ) {
    $key = 'hdlv2_checkin_remind_' . (int) $uid;
    if ( false === get_transient( $key ) ) {
        set_transient( $key, 1, 900 ); // 15-min self-expiring mask
        $masked[] = $key;
    }
}
echo 'INFO  candidates=' . count( $candidates ) . ' newly-masked=' . count( $masked ) . "\n";

// ── Phase B: disposable fixture rows ──
$mk_row = function ( $uid, $email, $name ) use ( $wpdb, $P ) {
    $wpdb->insert( $P . 'hdlv2_form_progress', array(
        'client_user_id'       => $uid,
        'practitioner_user_id' => 206,
        'client_name'          => $name,
        'client_email'         => $email,
        'token'                => hash( 'sha256', 'email-safety-live-' . $uid . wp_rand() ),
        'current_stage'        => 3,
        'stage3_completed_at'  => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
        'created_at'           => gmdate( 'Y-m-d H:i:s' ),
        'token_expires_at'     => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ),
        'source'               => 'practitioner',
    ) );
    return (int) $wpdb->insert_id;
};
$row_wl   = $mk_row( $UID_WL, $WHITELISTED, 'Guard Test Whitelisted' );
$row_real = $mk_row( $UID_REAL, $REAL_SHAPED, 'Guard Test RealShaped' );
$check( 'fixtures inserted', $row_wl > 0 && $row_real > 0 );

// ── Phase C: run the REAL cron handler with the manual-test override ──
add_filter( 'hdlv2_allow_staging_side_effects', '__return_true' );
HDLV2_Checkin::get_instance()->send_reminders();
remove_filter( 'hdlv2_allow_staging_side_effects', '__return_true' );

// ── Phase D: assertions against the REAL Post SMTP log ──
$new_rows = $wpdb->get_results( $wpdb->prepare(
    'SELECT original_to, original_subject FROM wp_post_smtp_logs WHERE id > %d ORDER BY id',
    $mail_max_before
) );
$check( 'P4 exactly TWO sends happened', 2 === count( $new_rows ) );

$to_wl = null; $to_catcher = null;
foreach ( $new_rows as $r ) {
    if ( false !== strpos( $r->original_to, 'guardtest' ) ) $to_wl = $r;
    if ( false !== strpos( $r->original_to, 'team+stby@irislab.com' ) ) $to_catcher = $r;
}
$check( 'P1 whitelisted recipient delivered AS-IS', $to_wl && $WHITELISTED === trim( $to_wl->original_to )
    && 0 === strpos( $to_wl->original_subject, 'Time for your weekly check-in' ) );
$check( 'P2 real-shaped recipient redirected to catcher + BLOCKED tag', $to_catcher
    && false !== strpos( $to_catcher->original_subject, '[STBY-BLOCKED -> ' . $REAL_SHAPED . ']' ) );
$direct_real = $wpdb->get_var( $wpdb->prepare(
    'SELECT COUNT(*) FROM wp_post_smtp_logs WHERE id > %d AND original_to LIKE %s',
    $mail_max_before, '%' . $REAL_SHAPED . '%'
) );
$check( 'P2b the real-shaped address itself received NOTHING', 0 === (int) $direct_real );

$buf = get_option( 'hdl_stby_mail_guard_log', array() );
$last = is_array( $buf ) && $buf ? end( $buf ) : array();
$check( 'P3 guard ring buffer recorded the block', 'blocked' === ( $last['action'] ?? '' )
    && in_array( $REAL_SHAPED, (array) ( $last['dropped'] ?? array() ), true ) );

// ── Phase E: cleanup (byte-identical apart from append-only audit logs) ──
$wpdb->delete( $P . 'hdlv2_form_progress', array( 'id' => $row_wl ) );
$wpdb->delete( $P . 'hdlv2_form_progress', array( 'id' => $row_real ) );
foreach ( $masked as $key ) { delete_transient( $key ); }
delete_transient( 'hdlv2_checkin_remind_' . $UID_WL );
delete_transient( 'hdlv2_checkin_remind_' . $UID_REAL );

$fp_count_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$P}hdlv2_form_progress" );
$check( 'cleanup: form_progress count restored', $fp_count_before === $fp_count_after );
$residual_masks = 0;
foreach ( $masked as $key ) { if ( false !== get_transient( $key ) ) $residual_masks++; }
$check( 'cleanup: zero residual mask transients', 0 === $residual_masks );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
