<?php
/**
 * Final Report Job Handlers (v0.46.0).
 *
 * Moves the heavy, multi-call Claude work for the practitioner Final Report
 * OFF the web request and onto HDLV2_Job_Queue, so the request that the
 * practitioner's browser is waiting on returns in well under a second and a
 * PHP worker is never held for the ~1-3 minutes a report takes.
 *
 * Why this matters: the box has ~10 PHP workers. The synchronous path held
 * one worker for the full Claude duration, so a few practitioners generating
 * reports at once could exhaust the pool and freeze every page on the site.
 * The queue caps simultaneous heavy jobs (HDLV2_Job_Queue::MAX_CONCURRENT)
 * so the site stays responsive no matter how many reports are requested.
 *
 * Two handlers, both thin wrappers around the EXISTING, unchanged report
 * generators — we only change WHEN they run, not WHAT they do:
 *
 *   generate_final_report    Payload: { progress_id, consultation_id, practitioner_id }
 *                            Calls HDLV2_Final_Report::generate(). First finalise.
 *   regenerate_final_report  Payload: { progress_id, consultation_id, practitioner_id }
 *                            Calls HDLV2_Final_Report::regenerate(). Save & Update Plan.
 *
 * Idempotency / no double side effects:
 *   - These jobs are enqueued with max_attempts = 1. Report generation has
 *     external side effects (Make.com PDF webhook + client email + Flight
 *     Plan reset); an automatic retry of a half-completed job could fire
 *     those twice. So we never auto-retry — a failed report surfaces a
 *     "Try again" button to the practitioner instead.
 *   - HDLV2_Final_Report::generate() carries its own duplicate guard (returns
 *     the existing 'ready' report without re-calling Claude), and the reports
 *     table has a unique (form_progress_id, report_type) key, so even a rare
 *     concurrent run cannot create two final reports.
 *
 * The job RESULT deliberately carries NO report body — only ids/flags. The
 * /jobs/{id}/status endpoint is readable by any practitioner (documented P2
 * IDOR), so we keep the sensitive awaken/lift/thrive narrative out of it; the
 * front-end re-loads the finished report through the existing ownership-checked
 * consultation / Trajectory Plan endpoints.
 *
 * @package HDL_Longevity_V2
 * @since 0.46.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Report_Jobs {

    const JOB_FINAL = 'generate_final_report';
    const JOB_REGEN = 'regenerate_final_report';

    public static function register() {
        if ( ! class_exists( 'HDLV2_Job_Queue' ) ) {
            return;
        }
        HDLV2_Job_Queue::register_handler( self::JOB_FINAL, array( __CLASS__, 'handle_final' ) );
        HDLV2_Job_Queue::register_handler( self::JOB_REGEN, array( __CLASS__, 'handle_regenerate' ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  HANDLER: generate_final_report (first finalise)
    // ──────────────────────────────────────────────────────────────

    public static function handle_final( $payload, $job ) {
        list( $progress_id, $consult_id, $practitioner_id, $err ) = self::read_payload( $payload );
        if ( $err ) {
            return $err;
        }
        $result = HDLV2_Final_Report::generate( $progress_id, $consult_id, $practitioner_id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return self::minimal_result( $result );
    }

    // ──────────────────────────────────────────────────────────────
    //  HANDLER: regenerate_final_report (Save & Update Plan)
    // ──────────────────────────────────────────────────────────────

    public static function handle_regenerate( $payload, $job ) {
        list( $progress_id, $consult_id, $practitioner_id, $err ) = self::read_payload( $payload );
        if ( $err ) {
            return $err;
        }
        $result = HDLV2_Final_Report::regenerate( $progress_id, $consult_id, $practitioner_id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return self::minimal_result( $result );
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Validate the payload + that the report generator is loaded.
     * Returns [progress_id, consult_id, practitioner_id, WP_Error|null].
     */
    private static function read_payload( $payload ) {
        $progress_id     = isset( $payload['progress_id'] ) ? (int) $payload['progress_id'] : 0;
        $consult_id      = isset( $payload['consultation_id'] ) ? (int) $payload['consultation_id'] : 0;
        $practitioner_id = isset( $payload['practitioner_id'] ) ? (int) $payload['practitioner_id'] : 0;

        if ( ! $progress_id || ! $consult_id || ! $practitioner_id ) {
            return array( 0, 0, 0, new WP_Error( 'bad_payload', 'Missing progress_id, consultation_id or practitioner_id.' ) );
        }
        if ( ! class_exists( 'HDLV2_Final_Report' ) ) {
            return array( 0, 0, 0, new WP_Error( 'missing_dep', 'HDLV2_Final_Report is not available.' ) );
        }
        return array( $progress_id, $consult_id, $practitioner_id, null );
    }

    /**
     * Strip the heavy / sensitive report body from the generator's return
     * array. Only ids + flags are stored in the job result (which is readable
     * via /jobs/{id}/status). The front-end re-loads the full report through
     * the ownership-checked consultation / Trajectory Plan endpoints.
     */
    private static function minimal_result( $result ) {
        $result = is_array( $result ) ? $result : array();
        return array(
            'success'           => true,
            'report_id'         => isset( $result['report_id'] ) ? (int) $result['report_id'] : 0,
            'report_type'       => 'final',
            'updated'           => ! empty( $result['updated'] ),
            'already_generated' => ! empty( $result['already_generated'] ),
            'generated_at'      => isset( $result['generated_at'] ) ? (string) $result['generated_at'] : '',
        );
    }
}
