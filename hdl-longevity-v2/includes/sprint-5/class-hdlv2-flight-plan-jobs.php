<?php
/**
 * Manual Flight Plan Job Handler (v0.46.x — prod-readiness CL-03).
 *
 * Moves the practitioner "Regenerate Flight Plan" click OFF the web request
 * onto HDLV2_Job_Queue — the same async pattern as HDLV2_Report_Jobs — so the
 * ~1-4 minute Claude generation (a single call at timeout 120, with one retry)
 * never holds one of the box's ~10 PHP workers. Without this a practitioner
 * iterating on priority notes mid-week could pin a worker for minutes; a few
 * of these plus a report/Flight-Notes render at clinic scale could exhaust the
 * pool and freeze the site.
 *
 * The WEEKLY cron path (cron_generate_all) and the post-finalise / post-checkin
 * scheduled single generations already run off-request via wp-cron — they are
 * NOT touched. Only the manual dashboard button changes.
 *
 * max_attempts = 1 (set by the enqueuer): generate() fires the Make.com PDF
 * webhook (a paid side effect), so it must never auto-retry. A failed job
 * surfaces "try again" to the practitioner.
 *
 * The job RESULT carries only the plan_id (a row id, not sensitive) so the
 * dashboard can confirm "Flight Plan regenerated (ID: N)".
 *
 * @package HDL_Longevity_V2
 * @since 0.46.x
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HDLV2_Flight_Plan_Jobs {

	const JOB = 'generate_flight_plan_manual';

	public static function register() {
		if ( ! class_exists( 'HDLV2_Job_Queue' ) ) {
			return;
		}
		HDLV2_Job_Queue::register_handler( self::JOB, array( __CLASS__, 'handle' ) );
	}

	/**
	 * @param array  $payload { client_id, practitioner_id }
	 * @param object $job
	 * @return array|WP_Error
	 */
	public static function handle( $payload, $job ) {
		$cid  = isset( $payload['client_id'] ) ? (int) $payload['client_id'] : 0;
		$prac = isset( $payload['practitioner_id'] ) ? (int) $payload['practitioner_id'] : 0;

		if ( ! $cid || ! $prac ) {
			return new WP_Error( 'bad_payload', 'Missing client_id or practitioner_id.' );
		}
		if ( ! class_exists( 'HDLV2_Flight_Plan' ) ) {
			return new WP_Error( 'missing_dep', 'HDLV2_Flight_Plan is not available.' );
		}

		// Manual practitioner regenerate: force=true replaces the current-week
		// plan atomically; send_email=false (no client spam on iteration).
		$result = HDLV2_Flight_Plan::get_instance()->generate( $cid, $prac, 'manual', false, null, true );
		if ( is_wp_error( $result ) ) {
			return $result; // queue marks the job failed; FE shows "try again"
		}
		return array( 'success' => true, 'plan_id' => (int) $result );
	}
}
