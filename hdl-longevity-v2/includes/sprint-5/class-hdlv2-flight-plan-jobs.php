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

/**
 * Auto / check-in / finalise Flight Plan generation, routed through the queue
 * (v0.46.66 — prod-readiness #3/#4/#6/#7).
 *
 * Previously the post-finalise (first plan) and post-checkin (next-week) paths
 * used wp_schedule_single_event(+Ns) + a /wp-cron.php loopback. On a box with
 * DISABLE_WP_CRON=true and no system cron those events sat unfired (the loopback
 * fires immediately, before the +Ns event is "due", so it runs nothing). That
 * stranded the client on the "your Flight Plan is being prepared" state with no
 * retry and no alert.
 *
 * The queue fixes all three at once:
 *   - #6: enqueue() kicks /internal/worker-tick (HMAC loopback) which runs
 *         REGARDLESS of DISABLE_WP_CRON, so generation fires in ~1s on STBY
 *         AND LIVE without depending on a server cron file. The 1-min worker
 *         cron is only a backstop.
 *   - #7 + retry half of #3/#4: max_attempts=2 → one backoff retry on a
 *         transient Claude failure (generate() is binary — every WP_Error path
 *         is BEFORE the INSERT/email, so a retry never double-inserts or
 *         double-emails; a succeeded plan returns plan_id and never retries).
 *   - alert half of #3/#4: on the FINAL attempt, record_generation_failure()
 *         writes a practitioner-visible timeline row + user_meta flag.
 *
 * The WEEKLY cron (cron_generate_all) still runs inline — it is itself a cron
 * event, so it cannot run on a cron-less box anyway, and it is the backstop,
 * not the interactive path.
 */
class HDLV2_Flight_Plan_Auto_Jobs {

	const JOB = 'generate_flight_plan_auto';

	public static function register() {
		if ( ! class_exists( 'HDLV2_Job_Queue' ) ) {
			return;
		}
		HDLV2_Job_Queue::register_handler( self::JOB, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Enqueue a generation for the given client + target week. Replaces the old
	 * wp_schedule_single_event/loopback pattern in the check-in and finalise
	 * triggers. Returns the job id (int) or false if the queue is unavailable.
	 *
	 * @param int    $client_id
	 * @param string $target_week 'current' (first plan after final report) or
	 *                            'next' (next week's plan after a check-in).
	 * @return int|false
	 */
	public static function enqueue_for( $client_id, $target_week = 'current' ) {
		$client_id   = (int) $client_id;
		$target_week = ( 'next' === $target_week ) ? 'next' : 'current';
		if ( ! $client_id || ! class_exists( 'HDLV2_Job_Queue' ) ) {
			return false;
		}
		// UNIQUE idem key per enqueue (mirrors the manual path). A stable key
		// would be deduped against a COMPLETED row by enqueue(), which would
		// silently skip a legitimate re-finalise regeneration. Concurrency is
		// handled downstream by generate()'s dup-guard + the (client,week_start)
		// unique index, so a rare double-enqueue just no-ops the second job.
		$idem = 'fpauto:' . $client_id . ':' . $target_week . ':' . substr( md5( uniqid( '', true ) ), 0, 10 );
		$job_id = HDLV2_Job_Queue::enqueue(
			self::JOB,
			array( 'client_id' => $client_id, 'target_week' => $target_week ),
			array(
				'reference_id' => $client_id,
				'idem_key'     => $idem,
				'priority'     => 90, // just behind the manual practitioner click (88)
				'max_attempts' => 2,  // one backoff retry on a transient Claude failure
			)
		);
		return is_wp_error( $job_id ) ? false : (int) $job_id;
	}

	/**
	 * @param array  $payload { client_id, target_week }
	 * @param object $job
	 * @return array|WP_Error
	 */
	public static function handle( $payload, $job ) {
		$cid    = isset( $payload['client_id'] ) ? (int) $payload['client_id'] : 0;
		$target = ( isset( $payload['target_week'] ) && 'next' === $payload['target_week'] ) ? 'next' : 'current';

		if ( ! $cid ) {
			return new WP_Error( 'bad_payload', 'Missing client_id.' );
		}
		if ( ! class_exists( 'HDLV2_Flight_Plan' ) ) {
			return new WP_Error( 'missing_dep', 'HDLV2_Flight_Plan is not available.' );
		}

		// generate_for_client returns: int plan_id (new), null (no-op — a live
		// plan already exists = success), or WP_Error (generation failed).
		$result = HDLV2_Flight_Plan::get_instance()->generate_for_client( $cid, $target );

		if ( is_wp_error( $result ) ) {
			// If this was the final attempt, surface the failure so a silently
			// failed auto/check-in generation doesn't strand the client (#3/#4).
			if ( (int) $job->attempts >= (int) $job->max_attempts ) {
				HDLV2_Flight_Plan::get_instance()->record_generation_failure( $cid, $target, $result->get_error_message() );
			}
			return $result; // queue retries if attempts remain, else marks failed
		}

		return array( 'success' => true, 'plan_id' => is_int( $result ) ? $result : 0 );
	}
}
