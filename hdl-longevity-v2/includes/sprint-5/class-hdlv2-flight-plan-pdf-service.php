<?php
/**
 * Weekly Flight Plan — direct PDFMonkey render service (no Make.com).
 *
 * Clones the Flight-Notes direct pattern (class-hdlv2-flight-notes-service)
 * with the D-2 upgrade: the rendered PDF is DOWNLOADED + SELF-HOSTED via
 * HDLV2_Report_PDF::fetch_and_store() in the private dir outside the web
 * root, served through the ownership-checked ?hdlv2_fp_pdf=<plan_id> route —
 * so download links never expire (the Flight-Notes transient/presigned-URL
 * weakness does not apply here).
 *
 * Render triggers (both queue this service; ZERO emails by construction —
 * this file contains no mail call and fires no webhook):
 *   1. plan generation (HDLV2_Flight_Plan::generate, replacing the retired
 *      Make HDLV2_MAKE_FLIGHT_PDF webhook whose scenario branch never existed)
 *   2. every practitioner edit-save (/flight-plan/{client_id}/edit)
 *
 * Requires wp-config constants:
 *   HDLV2_PDFMONKEY_API_KEY          — read at call time (key-rotation safe)
 *   HDLV2_FLIGHT_PLAN_TEMPLATE_ID    — Weekly Flight Plan v1 template
 *
 * @package HDL_Longevity_V2
 * @since   0.46.58 (editable milestones + weekly FP, direct-to-PDFMonkey)
 */

defined( 'ABSPATH' ) || exit;

class HDLV2_Flight_Plan_PDF_Service {

	const PDFMONKEY_BASE = 'https://api.pdfmonkey.io/api/v1';
	const POLL_TRIES     = 14;
	const POLL_WAIT      = 2;
	const JOB            = 'render_flight_plan_pdf';
	const COOLDOWN       = 120;  // shorter than Flight Notes — edit-save needs quick re-render
	const DAILY_CAP      = 50;

	/** Wire the job-queue handler. Called from the main loader. */
	public static function register() {
		if ( class_exists( 'HDLV2_Job_Queue' ) ) {
			HDLV2_Job_Queue::register_handler( self::JOB, array( __CLASS__, 'handle_job' ) );
		}
	}

	public static function configured() {
		return defined( 'HDLV2_PDFMONKEY_API_KEY' ) && HDLV2_PDFMONKEY_API_KEY
			&& defined( 'HDLV2_FLIGHT_PLAN_TEMPLATE_ID' ) && HDLV2_FLIGHT_PLAN_TEMPLATE_ID;
	}

	/**
	 * Queue a render (preferred), falling back to inline when the queue is
	 * unavailable. Dedupes against an already pending/running job for the
	 * same plan so generation + a fast follow-up edit can't double-render.
	 */
	public static function enqueue_render( $plan_id ) {
		$plan_id = absint( $plan_id );
		if ( ! $plan_id || ! self::configured() ) {
			return false;
		}
		if ( class_exists( 'HDLV2_Job_Queue' ) ) {
			// Dedupe against PENDING only. A RUNNING job snapshotted the row
			// when it started — an edit saved after that must get its own
			// render or the PDF ships the pre-edit state (race found in the
			// Part-5 verification). The hash-keyed cooldown below makes the
			// extra job a $0 no-op when content hasn't actually changed.
			$existing = HDLV2_Job_Queue::find_latest( self::JOB, $plan_id );
			if ( $existing && 'pending' === $existing->status ) {
				return (int) $existing->id;
			}
			$job_id = HDLV2_Job_Queue::enqueue(
				self::JOB,
				array( 'plan_id' => $plan_id ),
				array(
					'reference_id' => $plan_id,
					'idem_key'     => 'fppdf:' . $plan_id . ':' . substr( md5( uniqid( '', true ) ), 0, 12 ),
					'priority'     => 90,
					'max_attempts' => 1, // a render is a paid side effect
				)
			);
			return is_wp_error( $job_id ) ? false : (int) $job_id;
		}
		$res = self::render( $plan_id );
		return is_wp_error( $res ) ? false : true;
	}

	/** Job-queue entry point. */
	public static function handle_job( $payload ) {
		$res = self::render( absint( $payload['plan_id'] ?? 0 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'stored' => $res['relpath'] ?? '', 'cached' => ! empty( $res['cached'] ) );
	}

	/**
	 * Render the plan's PDF and self-host it. Hash-cached: unchanged plan
	 * content with an already-stored file is a $0 no-op.
	 *
	 * @return array|WP_Error { relpath, cached }
	 */
	public static function render( $plan_id ) {
		global $wpdb;
		$key = defined( 'HDLV2_PDFMONKEY_API_KEY' ) ? HDLV2_PDFMONKEY_API_KEY : '';
		$tid = defined( 'HDLV2_FLIGHT_PLAN_TEMPLATE_ID' ) ? HDLV2_FLIGHT_PLAN_TEMPLATE_ID : '';
		if ( ! $key || ! $tid ) {
			return new WP_Error( 'hdlv2_fppdf_unconfigured', 'Flight Plan PDF is not configured on this server.', array( 'status' => 503 ) );
		}

		$plan = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}hdlv2_flight_plans WHERE id = %d AND deleted_at IS NULL",
			$plan_id
		) );
		if ( ! $plan ) {
			return new WP_Error( 'hdlv2_fppdf_not_found', 'Flight plan not found.', array( 'status' => 404 ) );
		}

		$payload = self::build_payload( $plan );

		// Unchanged content + a file already on disk → $0 no-op.
		$hash = md5( wp_json_encode( $payload ) );
		if ( $plan->pdf_stored_path && get_transient( 'hdlv2_fppdf_hash_' . $plan_id ) === $hash ) {
			return array( 'relpath' => $plan->pdf_stored_path, 'cached' => true );
		}

		// Cooldown keyed by CONTENT hash: blocks only an identical-content
		// re-spend inside the window — a genuine edit (new hash) renders
		// immediately.
		if ( get_transient( 'hdlv2_fppdf_cool_' . $plan_id . '_' . $hash ) ) {
			return new WP_Error( 'hdlv2_fppdf_cooldown', 'This exact plan content was just rendered — try again in a couple of minutes.', array( 'status' => 429 ) );
		}
		$daily_key = 'hdlv2_fppdf_daily_' . ( (int) $plan->practitioner_id ?: 'sys' );
		if ( (int) get_transient( $daily_key ) >= self::DAILY_CAP ) {
			return new WP_Error( 'hdlv2_fppdf_daily', 'Daily Flight Plan render limit reached.', array( 'status' => 429 ) );
		}

		$doc = self::api_post( '/documents', array( 'document' => array(
			'document_template_id' => $tid,
			'status'               => 'pending',
			'payload'              => $payload,
			'meta'                 => wp_json_encode( array( '_filename' => self::filename( $plan ) ) ),
		) ), $key );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		$doc_id = $doc['document']['id'] ?? '';
		if ( ! $doc_id ) {
			return new WP_Error( 'hdlv2_fppdf_no_doc', 'PDFMonkey did not return a document id.', array( 'status' => 502 ) );
		}
		set_transient( 'hdlv2_fppdf_cool_' . $plan_id . '_' . $hash, time(), self::COOLDOWN );
		set_transient( $daily_key, (int) get_transient( $daily_key ) + 1, DAY_IN_SECONDS );

		$download_url = '';
		for ( $i = 0; $i < self::POLL_TRIES; $i++ ) {
			sleep( self::POLL_WAIT );
			$card = self::api_get( '/document_cards/' . $doc_id, $key );
			if ( is_wp_error( $card ) ) {
				continue;
			}
			$status = $card['document_card']['status'] ?? '';
			if ( 'success' === $status ) {
				$download_url = $card['document_card']['download_url'] ?? '';
				break;
			}
			if ( 'failure' === $status ) {
				return new WP_Error( 'hdlv2_fppdf_failed', 'PDF render failed: ' . ( $card['document_card']['failure_cause'] ?? 'unknown' ), array( 'status' => 502 ) );
			}
		}
		if ( ! $download_url ) {
			return new WP_Error( 'hdlv2_fppdf_timeout', 'PDF render timed out.', array( 'status' => 504 ) );
		}

		// D-2 upgrade: self-host (additive, timestamped — never overwrites).
		$stored = HDLV2_Report_PDF::fetch_and_store( $download_url, 'fp-' . $plan_id );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		// Pointer advance — pdf_* columns only (deleted_at re-guarded like the
		// old Make callback so a race with a cascade soft-delete can't stamp
		// an archived plan).
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}hdlv2_flight_plans
			 SET pdf_stored_path = %s, pdf_url = %s, pdf_generated_at = %s, delivered_at = COALESCE(delivered_at, %s)
			 WHERE id = %d AND deleted_at IS NULL",
			$stored['relpath'],
			home_url( '/?hdlv2_fp_pdf=' . $plan_id ),
			current_time( 'mysql' ),
			current_time( 'mysql' ),
			$plan_id
		) );
		set_transient( 'hdlv2_fppdf_hash_' . $plan_id, $hash, WEEK_IN_SECONDS );
		return array( 'relpath' => $stored['relpath'], 'cached' => false );
	}

	/**
	 * Template payload: the retired Make webhook's field mapping (via
	 * HDLV2_Flight_Plan::build_pdf_payload) + the pre-rendered HTML content
	 * blocks the chrome-only template interpolates + practitioner_initials
	 * from the SAME derive the Final Report payload uses.
	 */
	public static function build_payload( $plan ) {
		$flat = HDLV2_Flight_Plan::get_instance()->build_pdf_payload( $plan );
		$plan_data = json_decode( $plan->plan_data, true ) ?: array();

		$flat['practitioner_initials'] = HDLV2_Final_Report::derive_initials( $flat['practitioner_name'] );
		$flat['days_html']     = self::days_html( $plan_data['daily_plan'] ?? array(), $plan->week_start );
		$flat['targets_html']  = self::checklist_html( array_map( static function ( $t ) {
			return is_array( $t ) ? ( $t['target'] ?? '' ) : (string) $t;
		}, json_decode( $plan->weekly_targets, true ) ?: array() ) );
		$flat['shopping_html'] = self::checklist_html( json_decode( $plan->shopping_list, true ) ?: array() );
		$flat['review_html']   = self::items_html( $plan_data['review_prompts'] ?? array() );
		unset( $flat['callback_url'], $flat['callback_secret'], $flat['client_email'], $flat['practitioner_email'] );
		return $flat;
	}

	/**
	 * v2 (0.46.59, Matthew-approved mockups) — day cards mirror the DASHBOARD
	 * content model: items grouped Food / Fitness / Lifestyle (the renderer's
	 * BANDS map + order), time slot as small secondary metadata per item, the
	 * why-anchor as one consistent rail at the day-card FOOT. Cards flow in
	 * STRICT calendar order; ≤3 active days render one column per day, 4+
	 * days split into two chronological columns balanced by item count — no
	 * orphan column. KEY/FUEL/MOVE vocabulary is gone (screen and paper now
	 * speak one language).
	 */
	private static function days_html( $daily, $week_start ) {
		$day_order  = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
		// v0.46.65 — full canonical slot vocabulary. The generator/prompt
		// (flight-plan.php:1912) and the rest-day backfill emit mid_morning /
		// lunchtime / late_evening / night; the old 5-key allowlist silently
		// dropped those from the PDF (~45% of stored actions) while the
		// on-screen view — which buckets by CATEGORY, not slot — showed them.
		// Slot is only a secondary label here, so widening the set is purely
		// additive: it surfaces already-stored actions and cannot duplicate or
		// reorder ticks. Legacy keys (midday/evening) kept for old plan_data.
		$slot_order = array(
			'morning'       => 'morning',
			'mid_morning'   => 'mid-morning',
			'midday'        => 'midday',
			'lunchtime'     => 'lunchtime',
			'afternoon'     => 'afternoon',
			'early_evening' => 'early evening',
			'late_evening'  => 'late evening',
			'evening'       => 'evening',
			'night'         => 'night',
		);
		// Same triple as hdlv2-flight-plan.js BANDS — keys, labels, ORDER.
		$bands = array(
			array( 'nutrition',  'Food',      'food' ),
			array( 'movement',   'Fitness',   'fitness' ),
			array( 'key_action', 'Lifestyle', 'lifestyle' ),
		);
		$base_ts  = strtotime( $week_start );
		$base_dow = (int) date( 'N', $base_ts ) - 1; // 0 = Monday

		$cards = array(); // [ [html, item_count], … ] in calendar order
		foreach ( $day_order as $idx => $day ) {
			if ( empty( $daily[ $day ] ) || ! is_array( $daily[ $day ] ) ) {
				continue;
			}
			$groups = array( 'nutrition' => array(), 'movement' => array(), 'key_action' => array() );
			$why    = '';
			foreach ( $slot_order as $slot => $slot_label ) {
				if ( empty( $daily[ $day ][ $slot ] ) || ! is_array( $daily[ $day ][ $slot ] ) ) {
					continue;
				}
				foreach ( $daily[ $day ][ $slot ] as $a ) {
					if ( ! is_array( $a ) ) {
						continue;
					}
					$type = (string) ( $a['type'] ?? $a['category'] ?? 'key_action' );
					if ( 'why_anchor' === $type ) {
						if ( '' === $why ) {
							$why = (string) ( $a['action'] ?? $a['text'] ?? '' );
						}
						continue;
					}
					$bucket = in_array( $type, array( 'nutrition', 'movement' ), true ) ? $type : 'key_action';
					$groups[ $bucket ][] = array( $slot_label, (string) ( $a['action'] ?? $a['text'] ?? '' ) );
				}
			}
			$rows = '';
			$n    = 0;
			foreach ( $bands as $b ) {
				$items = $groups[ $b[0] ];
				if ( empty( $items ) ) {
					continue;
				}
				$rows .= '<div class="grp grp--' . $b[2] . '"><div class="grp__head">' . $b[1] . '</div>';
				foreach ( $items as $it ) {
					$rows .= '<div class="item"><span class="box"></span><span class="item__txt">' . esc_html( $it[1] )
						. ' <span class="slot">' . esc_html( $it[0] ) . '</span></span></div>';
					$n++;
				}
				$rows .= '</div>';
			}
			$foot = '' !== $why ? '<div class="why">&ldquo;' . esc_html( $why ) . '&rdquo;</div>' : '';
			$dt   = date( 'j M', strtotime( '+' . ( ( $idx - $base_dow + 7 ) % 7 ) . ' days', $base_ts ) );
			$cards[] = array(
				'<div class="day"><div class="day__head"><span class="day__name">' . esc_html( ucfirst( $day ) ) . '</span>'
					. '<span class="day__date">' . esc_html( $dt ) . '</span></div>' . $rows . $foot . '</div>',
				$n + 3, // +3 ≈ head + why overhead for balancing
			);
		}
		if ( empty( $cards ) ) {
			return '';
		}
		if ( count( $cards ) <= 3 ) {
			$cols = array_map( static function ( $c ) { return array( $c[0] ); }, $cards );
		} else {
			$heights = array_map( static function ( $c ) { return $c[1]; }, $cards );
			$best_k  = 1;
			$best_d  = PHP_INT_MAX;
			$total_n = count( $cards );
			for ( $k = 1; $k < $total_n; $k++ ) {
				$d = abs( array_sum( array_slice( $heights, 0, $k ) ) - array_sum( array_slice( $heights, $k ) ) );
				if ( $d <= $best_d ) {
					$best_k = $k;
					$best_d = $d;
				}
			}
			$cols = array(
				array_map( static function ( $c ) { return $c[0]; }, array_slice( $cards, 0, $best_k ) ),
				array_map( static function ( $c ) { return $c[0]; }, array_slice( $cards, $best_k ) ),
			);
		}
		$out = '<div class="days days--' . count( $cols ) . '">';
		foreach ( $cols as $col ) {
			$out .= '<div class="dcol">' . implode( '', $col ) . '</div>';
		}
		return $out . '</div>';
	}

	private static function checklist_html( $items ) {
		$out = '';
		foreach ( (array) $items as $it ) {
			$it = trim( (string) $it );
			if ( '' !== $it ) {
				$out .= '<li><span class="box"></span>' . esc_html( $it ) . '</li>';
			}
		}
		return $out;
	}

	private static function items_html( $items ) {
		$out = '';
		foreach ( (array) $items as $it ) {
			$it = trim( (string) $it );
			if ( '' !== $it ) {
				$out .= '<li>' . esc_html( $it ) . '</li>';
			}
		}
		return $out;
	}

	private static function filename( $plan ) {
		$client = get_userdata( (int) $plan->client_id );
		$name   = $client ? sanitize_file_name( $client->display_name ) : 'client';
		return 'Flight-Plan-' . ( $name ?: 'client' ) . '-week-' . (int) $plan->week_number . '.pdf';
	}

	// ── PDFMonkey HTTP (native JSON — no escaping chains on a direct call) ──
	private static function api_post( $path, $body, $key ) {
		return self::handle( wp_remote_post( self::PDFMONKEY_BASE . $path, array(
			'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
			'timeout' => 20,
		) ) );
	}

	private static function api_get( $path, $key ) {
		return self::handle( wp_remote_get( self::PDFMONKEY_BASE . $path, array(
			'headers' => array( 'Authorization' => 'Bearer ' . $key ),
			'timeout' => 20,
		) ) );
	}

	private static function handle( $r ) {
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'hdlv2_fppdf_http', $r->get_error_message(), array( 'status' => 502 ) );
		}
		$code = wp_remote_retrieve_response_code( $r );
		$data = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && isset( $data['errors'] ) ? wp_json_encode( $data['errors'] ) : "HTTP $code";
			return new WP_Error( 'hdlv2_fppdf_api', 'PDFMonkey: ' . $msg, array( 'status' => 502 ) );
		}
		return is_array( $data ) ? $data : array();
	}
}
