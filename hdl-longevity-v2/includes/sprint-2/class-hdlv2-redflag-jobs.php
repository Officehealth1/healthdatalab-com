<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Red-flag scan job: scan a client's intake, persist flags (dedup), message new
 * client-relevant flags. Runs via the async job queue (LIVE cron) or wp-cli (STBY testing).
 */
class HDLV2_Redflag_Jobs {

    const JOB_SCAN = 'redflag_scan';

    public static function register() {
        HDLV2_Job_Queue::register_handler( self::JOB_SCAN, array( __CLASS__, 'handle_scan' ) );
    }

    /** Queue handler. Respects the feature flag so stale jobs don't fire after it's turned off. */
    public static function handle_scan( $payload, $job ) {
        if ( ! (bool) get_option( 'hdlv2_ff_redflag_scan', false ) ) {
            return array( 'skipped' => 'flag_off' );
        }
        $progress_id = isset( $payload['progress_id'] ) ? (int) $payload['progress_id'] : 0;
        $stage       = isset( $payload['stage'] ) ? (int) $payload['stage'] : 0;
        if ( ! $progress_id ) {
            return new WP_Error( 'bad_payload', 'Missing progress_id.' );
        }
        return self::run_now( $progress_id, $stage );
    }

    /**
     * Core scan. Used by the queue handler AND the wp-cli trigger. Does NOT check the
     * feature flag (wp-cli is an explicit manual override for a chosen test client).
     *
     * @return array|WP_Error
     */
    public static function run_now( $progress_id, $stage = 0 ) {
        global $wpdb;
        $t = $wpdb->prefix . 'hdlv2_form_progress';

        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, client_user_id, practitioner_user_id, client_email, client_name,
                    stage1_data, stage3_data, flags
             FROM $t WHERE id = %d AND deleted_at IS NULL", $progress_id
        ) );
        if ( ! $progress ) {
            return new WP_Error( 'not_found', 'Form progress row not found.' );
        }

        $stage1 = json_decode( (string) $progress->stage1_data, true ) ?: array();
        // v0.43.0 — the front-door safety screen stores its ticks under
        // stage1_data._safety and flags them deterministically at signup.
        // Strip the key before the AI scan so the same symptoms aren't
        // re-flagged (which would create near-duplicate flags).
        unset( $stage1['_safety'] );
        $stage3 = json_decode( (string) $progress->stage3_data, true ) ?: array();
        $why    = $wpdb->get_row( $wpdb->prepare(
            "SELECT key_people, motivations, fears, distilled_why, ai_reformulation
             FROM {$wpdb->prefix}hdlv2_why_profiles
             WHERE form_progress_id = %d ORDER BY id DESC LIMIT 1", $progress_id
        ), ARRAY_A ) ?: array();

        $result = HDLV2_AI_Service::scan_for_flags( $stage1, $why, $stage3, (string) $progress->client_name );

        // FAIL-SAFE: a failed scan is NOT "no flags". Record failed, message no one, signal retry.
        if ( is_wp_error( $result ) ) {
            $wpdb->update( $t,
                array( 'flags_scan_status' => 'failed', 'flags_scanned_at' => current_time( 'mysql', true ) ),
                array( 'id' => $progress_id )
            );
            return $result; // WP_Error → the queue retries (up to max_attempts)
        }

        // Merge with existing flags, dedup by signature (preserve prior messaged_at).
        $existing = json_decode( (string) $progress->flags, true );
        $existing = is_array( $existing ) ? $existing : array();
        $by_sig = array();
        foreach ( $existing as $e ) { if ( ! empty( $e['signature'] ) ) { $by_sig[ $e['signature'] ] = $e; } }

        $to_message = array();
        foreach ( $result as $flag ) {
            $sig = $flag['signature'];
            if ( isset( $by_sig[ $sig ] ) ) { continue; } // already known — don't re-message
            $by_sig[ $sig ] = $flag;
            if ( strtoupper( (string) $flag['category'] ) !== 'CONTEXT' ) {
                $to_message[] = $flag; // CONTEXT never messages the client
            }
        }
        $merged = array_values( $by_sig );

        if ( ! empty( $to_message ) && ! empty( $progress->client_email ) ) {
            // v0.44.0 — single notification path (Make-or-wp_mail) shared with the safety screen.
            if ( class_exists( 'HDLV2_Flags_Store' ) ) {
                HDLV2_Flags_Store::notify( $progress, $to_message, $merged );
            }
        }

        $wpdb->update( $t,
            array(
                'has_flags'         => empty( $merged ) ? 0 : 1,
                'flags'             => wp_json_encode( $merged ),
                'flags_scanned_at'  => current_time( 'mysql', true ),
                'flags_scan_status' => 'ok',
            ),
            array( 'id' => $progress_id )
        );

        return array( 'flags_total' => count( $merged ), 'messaged' => count( $to_message ) );
    }

}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'hdlv2 redflag-scan', function ( $args ) {
        $progress_id = isset( $args[0] ) ? (int) $args[0] : 0;
        $stage       = isset( $args[1] ) ? (int) $args[1] : 0;
        if ( ! $progress_id ) {
            WP_CLI::error( 'Usage: wp hdlv2 redflag-scan <progress_id> [stage]' );
        }
        $res = HDLV2_Redflag_Jobs::run_now( $progress_id, $stage );
        if ( is_wp_error( $res ) ) {
            WP_CLI::error( $res->get_error_code() . ': ' . $res->get_error_message() );
        }
        WP_CLI::success( 'Scan complete: ' . wp_json_encode( $res ) );
    } );
}
