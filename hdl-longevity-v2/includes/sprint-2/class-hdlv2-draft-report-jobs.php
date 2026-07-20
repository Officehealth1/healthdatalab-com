<?php
/**
 * Draft Report Job Handler (v0.46.x — prod-readiness CL-02).
 *
 * Moves the Stage-3 client DRAFT report generation (3 sequential Claude calls,
 * ~30-60s) OFF the web request onto HDLV2_Job_Queue. Previously it ran inline
 * on whichever caller won the atomic claim — the server's non-blocking loopback
 * OR the client's fire-and-forget /generate-report call — holding a PHP worker
 * for the full Claude duration. Neither caller waits on the response (the
 * client sees "Thank You" immediately and the report arrives by Make.com
 * email/PDF), so the only effect of the inline run was a tied-up worker. At
 * clinic scale a cohort finishing Stage 3 together could pin several workers.
 *
 * The handler is a thin wrapper around the EXISTING generation logic, now
 * extracted to HDLV2_Staged_Form::generate_draft_for_progress(); we only change
 * WHEN/WHERE it runs.
 *
 * max_attempts = 1 (set by the enqueuer): generation fires the Make.com PDF
 * webhook + client email (paid/visible side effects) — it must never auto-retry.
 * The internal atomic claim (status='claimed-…') is defence-in-depth against a
 * duplicate run, and the stable idem_key 'draftgen:<pid>' dedups the two callers
 * (loopback + FE) into ONE job.
 *
 * The job RESULT carries only ids/flags — never the awaken/lift/thrive body
 * (the /jobs/{id}/status endpoint is world-readable to practitioners).
 *
 * @package HDL_Longevity_V2
 * @since 0.46.x
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HDLV2_Draft_Report_Jobs {

	const JOB = 'generate_draft_report';

	public static function register() {
		if ( ! class_exists( 'HDLV2_Job_Queue' ) ) {
			return;
		}
		HDLV2_Job_Queue::register_handler( self::JOB, array( __CLASS__, 'handle' ) );
	}

	/**
	 * @param array  $payload { progress_id }
	 * @param object $job
	 * @return array|WP_Error
	 */
	public static function handle( $payload, $job ) {
		global $wpdb;

		$pid = isset( $payload['progress_id'] ) ? (int) $payload['progress_id'] : 0;
		if ( ! $pid ) {
			return new WP_Error( 'bad_payload', 'Missing progress_id.' );
		}
		if ( ! class_exists( 'HDLV2_Staged_Form' ) ) {
			return new WP_Error( 'missing_dep', 'HDLV2_Staged_Form is not available.' );
		}

		// Load the assessment (non-archived). The generation's own atomic claim
		// guards against a duplicate concurrent run.
		$progress = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d AND deleted_at IS NULL",
			$pid
		) );
		if ( ! $progress ) {
			return new WP_Error( 'not_found', 'Assessment not found.' );
		}

		$res = ( new HDLV2_Staged_Form() )->generate_draft_for_progress( $progress );
		if ( is_wp_error( $res ) ) {
			return $res; // queue marks the job failed
		}
		return is_array( $res ) ? $res : array( 'success' => true );
	}
}
