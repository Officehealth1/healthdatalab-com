<?php
/**
 * Flight Notes PDF Job Handler (v0.46.x — prod-readiness CL-01 / FN-01).
 *
 * Moves the F·L·I·G·H·T Consultation Notes render OFF the web request onto
 * HDLV2_Job_Queue — the same async pattern as HDLV2_Report_Jobs — so the
 * ~30-90s Claude + PDFMonkey render never holds one of the box's ~10 PHP
 * workers. Without this a few concurrent "Flight Notes" clicks could exhaust
 * the worker pool and freeze every page on the site.
 *
 * The handler is a thin wrapper around the EXISTING, unchanged
 * HDLV2_Flight_Notes_Service::generate_pdf(); we only change WHEN it runs.
 *
 * max_attempts = 1 (set by the enqueuer): generate_pdf() creates a PDFMonkey
 * document (a paid render) and burns Claude, so it must never auto-retry. A
 * failed job surfaces "try again" to the practitioner.
 *
 * The job RESULT carries NO pdf_url. The /jobs/{id}/status endpoint is
 * readable by any practitioner (tracked IDOR — BACK-08), so the signed PDF URL
 * is never put there. The browser re-fetches /flight-notes/pdf (which is
 * ownership-checked), and that hits the now-warm cache and returns the URL for
 * $0 — no second Claude/PDFMonkey burn.
 *
 * The practitioner_id is carried IN THE PAYLOAD because get_current_user_id()
 * is 0 inside the cron worker — without it the per-practitioner daily-cap key
 * would collapse to one shared bucket (prod-readiness FATAL-01 correction).
 *
 * @package HDL_Longevity_V2
 * @since 0.46.x
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HDLV2_Flight_Notes_Jobs {

	const JOB = 'generate_flight_notes_pdf';

	public static function register() {
		if ( ! class_exists( 'HDLV2_Job_Queue' ) ) {
			return;
		}
		HDLV2_Job_Queue::register_handler( self::JOB, array( __CLASS__, 'handle' ) );
	}

	/**
	 * @param array  $payload { progress_id, practitioner_id }
	 * @param object $job      the job row (unused)
	 * @return array|WP_Error  minimal result (no pdf_url) or WP_Error to fail.
	 */
	public static function handle( $payload, $job ) {
		$pid  = isset( $payload['progress_id'] ) ? (int) $payload['progress_id'] : 0;
		$prac = isset( $payload['practitioner_id'] ) ? (int) $payload['practitioner_id'] : 0;

		if ( ! $pid ) {
			return new WP_Error( 'bad_payload', 'Missing progress_id.' );
		}
		if ( ! class_exists( 'HDLV2_Flight_Notes_Service' ) ) {
			return new WP_Error( 'missing_dep', 'HDLV2_Flight_Notes_Service is not available.' );
		}

		$res = HDLV2_Flight_Notes_Service::generate_pdf( $pid, $prac );
		if ( is_wp_error( $res ) ) {
			return $res; // queue marks the job failed; FE shows "try again"
		}

		// IDOR-safe: no pdf_url in the result. The render is now cached; the FE
		// re-fetches /flight-notes/pdf (ownership-checked) to get the URL.
		return array( 'success' => true, 'ready' => true );
	}
}
