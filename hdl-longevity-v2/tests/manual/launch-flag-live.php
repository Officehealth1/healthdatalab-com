<?php
/**
 * STBY live proof — scheduled-client-campaign launch flag (v0.47.75).
 *
 * Run ON STBY:  wp eval-file tests/manual/launch-flag-live.php --allow-root
 *
 * Simulates the post-deploy LIVE state by holding the environment gate open
 * (the staging manual-test override stands in for is_live) and driving the
 * REAL send_reminders cron + REAL mail guard + REAL Post SMTP pipeline:
 *
 *   L1  flag OFF (the deploy default) -> cron runs, ZERO client email;
 *   L2  flag OFF -> no cooldown written (flipping ON must not land the
 *       client inside a 3-day suppression window);
 *   L3  flag ON  -> the SAME cron now delivers to the client;
 *   L4  the delivered mail is untagged (a whitelisted test address, so the
 *       guard passed it through as-is — proves a real send, not a redirect);
 *   L5  flag ON -> cooldown written.
 *
 * Safety: every genuine candidate is cooldown-masked for the run, so only
 * the disposable fixture can send; its address is whitelisted, so even the
 * ON phase cannot reach a real client. Deletes its fixtures + masks and
 * restores the flag option to ABSENT (the LIVE default) on the way out.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$pass = 0; $fail = 0;
$check = function ( $label, $ok ) use ( &$pass, &$fail ) {
    echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n";
    $ok ? $pass++ : $fail++;
};

$FIXTURE_EMAIL = 'team+campaignflag@irislab.com'; // whitelisted -> real delivery
$UID           = 999911;
$P             = $wpdb->prefix;
$FLAG          = 'hdlv2_ff_client_campaigns';

$flag_was_present = ( null !== $wpdb->get_var( $wpdb->prepare(
    "SELECT option_id FROM {$P}options WHERE option_name = %s", $FLAG
) ) );
echo 'INFO  flag option present before run: ' . ( $flag_was_present ? 'YES' : 'no (absent = OFF)' ) . "\n";

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
        set_transient( $key, 1, 900 );
        $masked[] = $key;
    }
}
echo 'INFO  genuine candidates=' . count( $candidates ) . ' newly-masked=' . count( $masked ) . "\n";

// ── Phase B: disposable fixture (whitelisted recipient) ──
$wpdb->insert( $P . 'hdlv2_form_progress', array(
    'client_user_id'       => $UID,
    'practitioner_user_id' => 206,
    'client_name'          => 'Campaign Flag Test',
    'client_email'         => $FIXTURE_EMAIL,
    'token'                => hash( 'sha256', 'launch-flag-live-' . wp_rand() ),
    'current_stage'        => 3,
    'stage3_completed_at'  => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
    'created_at'           => gmdate( 'Y-m-d H:i:s' ),
    'token_expires_at'     => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ),
    'source'               => 'practitioner',
) );
$row_id = (int) $wpdb->insert_id;
$check( 'fixture inserted', $row_id > 0 );

// Hold the environment gate open for the whole run — this is what LIVE looks
// like to the campaign gate. The launch flag is the ONLY variable below.
add_filter( 'hdlv2_allow_staging_side_effects', '__return_true' );

// ── Phase C: flag OFF (post-deploy default) ──
delete_option( $FLAG );
$check( 'flag reads OFF with the option absent', false === HDLV2_Env::client_campaigns_enabled() );

HDLV2_Checkin::get_instance()->send_reminders();

$after_off = (int) $wpdb->get_var( $wpdb->prepare(
    'SELECT COUNT(*) FROM wp_post_smtp_logs WHERE id > %d', $mail_max_before
) );
$check( 'L1 flag OFF: cron ran, ZERO client email sent', 0 === $after_off );
$check( 'L2 flag OFF: no cooldown written (clean slate for launch day)',
    false === get_transient( 'hdlv2_checkin_remind_' . $UID ) );

// ── Phase D: flip ON — the single launch action ──
update_option( $FLAG, 1 );
$check( 'flag reads ON after the flip', true === HDLV2_Env::client_campaigns_enabled() );

HDLV2_Checkin::get_instance()->send_reminders();

$new_rows = $wpdb->get_results( $wpdb->prepare(
    'SELECT original_to, original_subject FROM wp_post_smtp_logs WHERE id > %d ORDER BY id',
    $mail_max_before
) );
$check( 'L3 flag ON: the SAME cron now sends exactly ONE client email', 1 === count( $new_rows ) );
$sent = $new_rows[0] ?? null;
$check( 'L3b delivered to the fixture client', $sent && $FIXTURE_EMAIL === trim( $sent->original_to ) );
$check( 'L4 delivered untagged (guard passed a whitelisted address through)',
    $sent && 0 === strpos( $sent->original_subject, 'Time for your weekly check-in' ) );
$check( 'L5 flag ON: 3-day cooldown written', false !== get_transient( 'hdlv2_checkin_remind_' . $UID ) );

// ── Phase E: cleanup — restore the LIVE default (absent) ──
remove_filter( 'hdlv2_allow_staging_side_effects', '__return_true' );
delete_option( $FLAG );
$wpdb->delete( $P . 'hdlv2_form_progress', array( 'id' => $row_id ) );
delete_transient( 'hdlv2_checkin_remind_' . $UID );
foreach ( $masked as $key ) { delete_transient( $key ); }

$check( 'cleanup: flag option restored to ABSENT (= OFF, mirrors LIVE)',
    null === $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$P}options WHERE option_name = %s", $FLAG ) ) );
$check( 'cleanup: form_progress count restored',
    $fp_count_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$P}hdlv2_form_progress" ) );
$residual = 0;
foreach ( $masked as $key ) { if ( false !== get_transient( $key ) ) $residual++; }
$check( 'cleanup: zero residual mask transients', 0 === $residual );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
