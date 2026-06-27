<?php
/**
 * F·L·I·G·H·T Consultation Notes — render service (Phase 3, direct PDFMonkey).
 *
 * Orchestrates the practitioner "Download Flight Notes" action:
 *   build snapshot (Phase 1) + AI text (Phase 2) → render via the PDFMonkey
 *   REST API DIRECTLY (no Make.com) → return a downloadable PDF URL.
 *
 * Why direct-PDFMonkey (not the Flight-Plan Make pipeline): Flight Notes has no
 * Claude-in-Make step (WP already has the AI text) and no email — Make would be
 * a pure relay. Direct keeps it self-contained and testable on STBY (which has
 * no Make.com). The PDFMonkey key lives in wp-config, same trust level as the
 * Anthropic key.
 *
 * Requires wp-config constants:
 *   HDLV2_PDFMONKEY_API_KEY        — PDFMonkey private API token
 *   HDLV2_FLIGHT_NOTES_TEMPLATE_ID — the flight_consultation_notes template id
 *
 * REST: GET hdl-v2/v1/flight-notes/pdf?progress_id=N  (practitioner-owned, AI-burn)
 *
 * @package HDL_Longevity_V2
 * @since   0.46.x (Flight Consultation Notes — Phase 3)
 */

defined( 'ABSPATH' ) || exit;

class HDLV2_Flight_Notes_Service {

	const PDFMONKEY_BASE = 'https://api.pdfmonkey.io/api/v1';
	const POLL_TRIES     = 14;   // × ~2s ≈ 28s budget (PDFMonkey renders in ~3-8s)
	const POLL_WAIT      = 2;    // seconds between polls
	const CACHE_TTL      = DAY_IN_SECONDS;
	const COOLDOWN       = 600;  // per-client: max 1 FRESH render / 10 min
	const DAILY_CAP      = 50;   // per-practitioner: absolute daily render ceiling

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'hdl-v2/v1',
			'/flight-notes/pdf',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_generate_pdf' ),
				'permission_callback' => array( $this, 'permission_owns_client' ),
				'args'                => array(
					'client_id'   => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $v ) { return absint( $v ) > 0; },
					),
					'progress_id' => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * IDOR guard: the caller must be the practitioner linked to this client
	 * (or an admin). Mirrors the project-wide ownership rule.
	 */
	public function permission_owns_client( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'hdlv2_not_logged_in', 'Authentication required.', array( 'status' => 401 ) );
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$client = absint( $request['client_id'] );
		$prac   = get_current_user_id();
		if ( class_exists( 'HDLV2_Compatibility' ) && method_exists( 'HDLV2_Compatibility', 'practitioner_owns_client' )
			&& HDLV2_Compatibility::practitioner_owns_client( $prac, $client ) ) {
			return true;
		}
		global $wpdb;
		// `deleted_at IS NULL` (prod-readiness SD-2): the raw fallback must not
		// re-grant access to an archived client that practitioner_owns_client
		// would deny.
		$n = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}hdlv2_form_progress WHERE client_user_id = %d AND practitioner_user_id = %d AND deleted_at IS NULL",
			$client, $prac
		) );
		return $n > 0 ? true : new WP_Error( 'hdlv2_forbidden', 'You are not linked to this client.', array( 'status' => 403 ) );
	}

	public function rest_generate_pdf( $request ) {
		global $wpdb;
		$client   = absint( $request['client_id'] );
		$is_admin = current_user_can( 'manage_options' );
		$prac     = get_current_user_id();
		$pid      = absint( $request['progress_id'] );

		// Resolve $pid to a NON-archived assessment that genuinely belongs to
		// the validated client (and to this practitioner, unless admin).
		if ( $pid ) {
			// IDOR-02: a caller-supplied progress_id is otherwise unbound to the
			// authorised client_id — a practitioner could pass their own
			// client_id (passes permission) but another client's progress_id.
			$bind = $is_admin
				? $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d AND client_user_id = %d AND deleted_at IS NULL", $pid, $client )
				: $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d AND client_user_id = %d AND practitioner_user_id = %d AND deleted_at IS NULL", $pid, $client, $prac );
			$pid = (int) $wpdb->get_var( $bind );
		} else {
			// Latest non-archived assessment for this client.
			$sql = $is_admin
				? $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}hdlv2_form_progress WHERE client_user_id = %d AND deleted_at IS NULL ORDER BY id DESC LIMIT 1", $client )
				: $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}hdlv2_form_progress WHERE client_user_id = %d AND practitioner_user_id = %d AND deleted_at IS NULL ORDER BY id DESC LIMIT 1", $client, $prac );
			$pid = (int) $wpdb->get_var( $sql );
		}
		if ( ! $pid ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'No assessment found for this client yet.' ), 404 );
		}

		// FAST PATH ($0): if a rendered PDF for the current data already exists,
		// return its fresh download URL inline. peek_cached() does only cheap
		// reads + the config + Stage-3 gate (no Claude, no fresh render).
		$cached = self::peek_cached( $pid );
		if ( is_wp_error( $cached ) ) {
			$status = $cached->get_error_data();
			$status = is_array( $status ) && isset( $status['status'] ) ? $status['status'] : 502;
			return new WP_REST_Response( array( 'ok' => false, 'message' => $cached->get_error_message() ), $status );
		}
		if ( is_array( $cached ) ) {
			return new WP_REST_Response( array( 'ok' => true ) + $cached, 200 );
		}

		// A FRESH render is needed. Move the ~30-90s Claude + PDFMonkey work OFF
		// the request onto the job queue (prod-readiness CL-01) so it never holds
		// a PHP worker. The browser polls /jobs/{id}/status and re-fetches this
		// endpoint (now a cache hit) when the job completes.
		if ( ! class_exists( 'HDLV2_Job_Queue' ) || ! class_exists( 'HDLV2_Flight_Notes_Jobs' ) ) {
			// Degraded fallback (holds the worker) — better than a hard failure.
			$res = self::generate_pdf( $pid, $prac );
			if ( is_wp_error( $res ) ) {
				$status = $res->get_error_data();
				$status = is_array( $status ) && isset( $status['status'] ) ? $status['status'] : 502;
				return new WP_REST_Response( array( 'ok' => false, 'message' => $res->get_error_message() ), $status );
			}
			return new WP_REST_Response( array( 'ok' => true ) + $res, 200 );
		}

		// Per-client cooldown surfaced at the click so the practitioner sees the
		// clear "just generated" message immediately, not after a queued run.
		if ( get_transient( 'hdlv2_fn_cool_' . $pid ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'These notes were just generated — please wait a couple of minutes before regenerating with the latest data.' ), 429 );
		}

		// Serialise the in-flight check + enqueue with a short per-assessment DB
		// lock (mirrors enqueue_report_job) so two near-simultaneous clicks can't
		// both enqueue a render and double-charge Claude/PDFMonkey. Fail-open —
		// the disabled button + the in-flight guard are still in play.
		$lock_name = substr( 'hdlv2_fnjob_' . $pid, 0, 64 );
		$got_lock  = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );

		$existing = HDLV2_Job_Queue::find_latest( HDLV2_Flight_Notes_Jobs::JOB, $pid );
		if ( $existing && in_array( $existing->status, array( 'pending', 'running' ), true ) ) {
			if ( $got_lock ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			}
			return new WP_REST_Response( self::queued_body( (int) $existing->id ), 200 );
		}

		// Unique idem_key per fresh render (the GET_LOCK + the find_latest
		// pending/running guard above are the concurrency dedup, NOT the idem
		// key — a stable key would make the queue return an OLD completed job
		// after the data changed and never regenerate). reference_id ties the
		// job to the assessment for find_latest.
		$idem_key = 'fnpdf:' . $pid . ':' . substr( md5( uniqid( '', true ) ), 0, 12 );
		$job_id   = HDLV2_Job_Queue::enqueue(
			HDLV2_Flight_Notes_Jobs::JOB,
			array( 'progress_id' => $pid, 'practitioner_id' => $prac ),
			array(
				'reference_id' => $pid,
				'idem_key'     => $idem_key,
				'priority'     => 90,
				// No auto-retry: a PDFMonkey render + Claude burn are paid side
				// effects. A failed job surfaces "try again" to the practitioner.
				'max_attempts' => 1,
			)
		);

		if ( $got_lock ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}

		if ( is_wp_error( $job_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'Could not start the flight notes. Please try again.' ), 503 );
		}
		return new WP_REST_Response( self::queued_body( (int) $job_id ), 200 );
	}

	/** The queued-response body the front-end polls on. */
	private static function queued_body( $job_id ) {
		return array(
			'ok'              => true,
			'queued'          => true,
			'job_id'          => (int) $job_id,
			'status_endpoint' => rest_url( 'hdl-v2/v1/jobs/' . (int) $job_id . '/status' ),
		);
	}

	/**
	 * Cheap fast-path: does a rendered PDF for the CURRENT data already exist?
	 * Does only DB reads + config/Stage-3 gates + (on a hit) one light
	 * PDFMonkey card fetch for a fresh presigned URL. NO Claude, NO fresh render.
	 *
	 * @param int $pid
	 * @return array|WP_Error|null  array = cache hit; WP_Error = config/gate fail;
	 *                              null = a fresh render is needed.
	 */
	private static function peek_cached( $pid ) {
		$key = defined( 'HDLV2_PDFMONKEY_API_KEY' ) ? HDLV2_PDFMONKEY_API_KEY : '';
		$tid = defined( 'HDLV2_FLIGHT_NOTES_TEMPLATE_ID' ) ? HDLV2_FLIGHT_NOTES_TEMPLATE_ID : '';
		if ( ! $key || ! $tid ) {
			return new WP_Error( 'hdlv2_fn_unconfigured', 'Flight Notes PDF is not configured on this server.', array( 'status' => 503 ) );
		}
		$in = self::build_ai_inputs( $pid );
		if ( empty( $in['s3'] ) ) {
			return new WP_Error( 'hdlv2_fn_not_ready', 'Flight Notes are available once the client has completed Stage 3.', array( 'status' => 409 ) );
		}
		$flat = HDLV2_Flight_Notes::build_flight_notes_payload( $pid );
		if ( is_wp_error( $flat ) ) {
			return $flat;
		}
		$hash  = md5( wp_json_encode( $flat ) . '|' . wp_json_encode( $in ) );
		$cache = get_transient( 'hdlv2_fn_pdf_' . $pid );
		if ( is_array( $cache ) && ! empty( $cache['hash'] ) && $cache['hash'] === $hash && ! empty( $cache['doc_id'] ) ) {
			$card = self::pdfmonkey_get( '/document_cards/' . $cache['doc_id'], $key );
			if ( ! is_wp_error( $card ) && ( $card['document_card']['status'] ?? '' ) === 'success' && ! empty( $card['document_card']['download_url'] ) ) {
				return array( 'pdf_url' => $card['document_card']['download_url'], 'filename' => $cache['filename'] ?? '', 'cached' => true );
			}
		}
		return null; // a fresh render is needed
	}

	/**
	 * Build the full payload (snapshot + AI), render via PDFMonkey, return the
	 * PDF URL. Caches per (progress_id, payload-hash) so a repeat click on
	 * unchanged data reuses the rendered PDF instead of re-burning Claude+render.
	 *
	 * @param int $pid             form_progress id
	 * @param int $practitioner_id owning practitioner (passed by the async job
	 *                             handler — get_current_user_id() is 0 in the
	 *                             cron worker, so without this the per-practitioner
	 *                             daily cap collapses to one shared bucket).
	 * @return array|WP_Error { pdf_url, filename, cached }
	 */
	public static function generate_pdf( $pid, $practitioner_id = 0 ) {
		$key = defined( 'HDLV2_PDFMONKEY_API_KEY' ) ? HDLV2_PDFMONKEY_API_KEY : '';
		$tid = defined( 'HDLV2_FLIGHT_NOTES_TEMPLATE_ID' ) ? HDLV2_FLIGHT_NOTES_TEMPLATE_ID : '';
		if ( ! $key || ! $tid ) {
			return new WP_Error( 'hdlv2_fn_unconfigured', 'Flight Notes PDF is not configured on this server.', array( 'status' => 503 ) );
		}

		// AI INPUTS first (cheap read, NO Claude) — also the Stage-3 server gate.
		$in = self::build_ai_inputs( $pid );
		if ( empty( $in['s3'] ) ) {
			return new WP_Error( 'hdlv2_fn_not_ready', 'Flight Notes are available once the client has completed Stage 3.', array( 'status' => 409 ) );
		}

		// Snapshot (Phase 1) — cheap, NO Claude.
		$flat = HDLV2_Flight_Notes::build_flight_notes_payload( $pid );
		if ( is_wp_error( $flat ) ) {
			return $flat;
		}

		// Cache key = the INPUT (snapshot + AI inputs), computed BEFORE any Claude
		// call. An unchanged-data repeat is therefore FREE ($0): no Claude, no
		// PDFMonkey render — just a fresh download URL for the already-rendered
		// doc. $in captures s1/s3/calc/why/addenda so any data edit busts it.
		$hash  = md5( wp_json_encode( $flat ) . '|' . wp_json_encode( $in ) );
		$cache = get_transient( 'hdlv2_fn_pdf_' . $pid );
		if ( is_array( $cache ) && ! empty( $cache['hash'] ) && $cache['hash'] === $hash && ! empty( $cache['doc_id'] ) ) {
			$card = self::pdfmonkey_get( '/document_cards/' . $cache['doc_id'], $key );
			if ( ! is_wp_error( $card ) && ( $card['document_card']['status'] ?? '' ) === 'success' && ! empty( $card['document_card']['download_url'] ) ) {
				return array( 'pdf_url' => $card['document_card']['download_url'], 'filename' => $cache['filename'] ?? '', 'cached' => true );
			}
		}

		// ── A FRESH render is needed (first time, or the data changed). Apply the
		//    spend guards BEFORE burning Claude + a PDFMonkey render. ──

		// Per-client cooldown: at most one fresh render per client per window.
		$cooldown_key = 'hdlv2_fn_cool_' . $pid;
		if ( get_transient( $cooldown_key ) ) {
			return new WP_Error( 'hdlv2_fn_cooldown', 'These notes were just generated — please wait a couple of minutes before regenerating with the latest data.', array( 'status' => 429 ) );
		}

		// Per-practitioner daily cap (absolute ceiling a runaway loop can't beat).
		// Prefer the passed practitioner_id — get_current_user_id() is 0 inside
		// the async worker, which would collapse every practitioner to one shared
		// 'sys' bucket (prod-readiness FATAL-01 correction).
		$uid       = $practitioner_id ?: ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
		$daily_key = 'hdlv2_fn_daily_' . ( $uid ?: 'sys' );
		$daily     = (int) get_transient( $daily_key );
		if ( $daily >= self::DAILY_CAP ) {
			return new WP_Error( 'hdlv2_fn_daily', 'Daily Flight Notes limit reached — please try again tomorrow.', array( 'status' => 429 ) );
		}

		// F19 — Claude is the paid side effect. Cache the parsed AI output keyed
		// by the SAME input hash so that if the downstream PDFMonkey POST/render
		// fails and the practitioner retries, the retry reuses the already-paid
		// Claude text instead of re-charging. The cache busts automatically when
		// the underlying data changes (the hash changes). Cooldown alone wouldn't
		// cover this: the document-create POST step runs BEFORE the cooldown is
		// stamped, so a POST failure there left no protection.
		$ai_cache_key = 'hdlv2_fn_ai_' . $pid;
		$ai_cached    = get_transient( $ai_cache_key );
		if ( is_array( $ai_cached ) && ! empty( $ai_cached['hash'] ) && $ai_cached['hash'] === $hash && array_key_exists( 'ai', $ai_cached ) ) {
			$ai = $ai_cached['ai'];
		} else {
			// AI text (Phase 2) — the expensive call, ONLY on a genuine fresh render.
			$ai = HDLV2_AI_Service::generate_flight_consult_notes( $in['s1'], $in['s3'], $in['calc'], $in['why'], $in['addenda'] );
			// Stamp the AI cache right after a successful Claude call (before the
			// PDFMonkey POST) so a render-side failure + retry doesn't re-charge.
			if ( ! is_wp_error( $ai ) ) {
				set_transient( $ai_cache_key, array( 'hash' => $hash, 'ai' => $ai ), self::CACHE_TTL );
			}
		}
		$payload = array_merge( $flat, self::render_ai_fragments( $ai ) );

		// Create the PDFMonkey document (queued render).
		$doc = self::pdfmonkey_post(
			'/documents',
			array( 'document' => array(
				'document_template_id' => $tid,
				'status'               => 'pending',
				'payload'              => $payload,
				'meta'                 => wp_json_encode( array( '_filename' => self::filename( $flat ) ) ),
			) ),
			$key
		);
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		$doc_id = $doc['document']['id'] ?? '';
		if ( ! $doc_id ) {
			return new WP_Error( 'hdlv2_fn_no_doc', 'PDFMonkey did not return a document id.', array( 'status' => 502 ) );
		}

		// Stamp the spend guards immediately (before the poll) so a concurrent /
		// duplicate fire can't also slip a second render through.
		set_transient( $cooldown_key, time(), self::COOLDOWN );
		// F20 — atomic per-practitioner daily increment (see below).
		self::bump_daily_cap( $daily_key );

		// Poll the lightweight card endpoint (avoids the heavy document JSON).
		for ( $i = 0; $i < self::POLL_TRIES; $i++ ) {
			sleep( self::POLL_WAIT );
			$card = self::pdfmonkey_get( '/document_cards/' . $doc_id, $key );
			if ( is_wp_error( $card ) ) {
				continue;
			}
			$status = $card['document_card']['status'] ?? '';
			if ( 'success' === $status ) {
				$url      = $card['document_card']['download_url'] ?? '';
				$filename = $card['document_card']['filename'] ?? self::filename( $flat );
				if ( ! $url ) {
					return new WP_Error( 'hdlv2_fn_no_url', 'PDF rendered but no download URL.', array( 'status' => 502 ) );
				}
				set_transient( 'hdlv2_fn_pdf_' . $pid, array( 'hash' => $hash, 'doc_id' => $doc_id, 'filename' => $filename ), self::CACHE_TTL );
				return array( 'pdf_url' => $url, 'filename' => $filename, 'cached' => false );
			}
			if ( 'failure' === $status ) {
				$why = $card['document_card']['failure_cause'] ?? 'unknown';
				return new WP_Error( 'hdlv2_fn_render_failed', 'PDF render failed: ' . $why, array( 'status' => 502 ) );
			}
		}
		return new WP_Error( 'hdlv2_fn_timeout', 'PDF is taking longer than expected — try again in a moment.', array( 'status' => 504 ) );
	}

	/**
	 * F20 — atomically increment the per-practitioner daily render counter.
	 *
	 * The bare get_transient()→set_transient(+1) pattern is a non-atomic
	 * read-modify-write: two concurrent flight-notes worker ticks (job-queue
	 * MAX_CONCURRENT=3) for two different clients of the SAME practitioner can
	 * both read the same value and both write value+1, losing one increment and
	 * letting the 50/day spend ceiling drift over.
	 *
	 * Serialise the read+write with the same short DB GET_LOCK pattern used for
	 * enqueue (rest_generate_pdf) and the flight-plan force path. Fail-open: if
	 * the lock can't be taken we still increment (the cap is a soft money-safety
	 * ceiling, not a correctness boundary — a lost increment under contention is
	 * a far smaller risk than blocking a legitimate render).
	 *
	 * @param string $daily_key transient key 'hdlv2_fn_daily_<uid>'
	 */
	private static function bump_daily_cap( $daily_key ) {
		global $wpdb;
		$lock_name = substr( 'hdlv2_fndaily_' . $daily_key, 0, 64 );
		$got_lock  = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		$current   = (int) get_transient( $daily_key );
		set_transient( $daily_key, $current + 1, DAY_IN_SECONDS );
		if ( $got_lock ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Read s1/s3/calc/why for the AI call. Calc derivation mirrors the Phase-1
	 * data layer (the canonical version is HDLV2_Flight_Notes::build_flight_notes_payload).
	 */
	private static function build_ai_inputs( $pid ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d AND deleted_at IS NULL", $pid )
		);
		$s1 = $row ? ( json_decode( (string) $row->stage1_data, true ) ?: array() ) : array();
		$s3 = $row ? ( json_decode( (string) $row->stage3_data, true ) ?: array() ) : array();

		$calc = ( isset( $s3['server_result'] ) && is_array( $s3['server_result'] ) ) ? $s3['server_result'] : array();
		if ( empty( $calc ) || empty( $calc['scores'] ) ) {
			$cd = array_merge( $s1, $s3 );
			if ( isset( $cd['activity'] ) && ! isset( $cd['physicalActivity'] ) ) {
				$cd['physicalActivity'] = $cd['activity'];
			}
			foreach ( array( 'height_cm' => 'height', 'weight_kg' => 'weight', 'waist_cm' => 'waist', 'hip_cm' => 'hip' ) as $f => $t ) {
				if ( isset( $cd[ $f ] ) && ! isset( $cd[ $t ] ) ) {
					$cd[ $t ] = $cd[ $f ];
				}
			}
			foreach ( $cd as $k => $v ) {
				if ( 'skip' === $v ) {
					$cd[ $k ] = null;
				}
			}
			$age    = (int) ( $cd['q1_age'] ?? $cd['age'] ?? 0 );
			$gender = $cd['q1_sex'] ?? $cd['gender'] ?? 'other';
			$calc   = HDLV2_Rate_Calculator::calculate_full( $age, $cd, $gender );
		}

		$why_row = $wpdb->get_row(
			$wpdb->prepare( "SELECT distilled_why FROM {$wpdb->prefix}hdlv2_why_profiles WHERE form_progress_id = %d LIMIT 1", $pid )
		);
		$why = array( 'distilled_why' => $why_row ? (string) $why_row->distilled_why : '' );

		// Consultation addenda (chronological) so the aid reflects the latest plan.
		$addenda = array();
		$at = $wpdb->prefix . 'hdlv2_consultation_addenda';
		if ( $row && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $at ) ) === $at ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT note_text, priority, occurred_at FROM {$at} WHERE form_progress_id = %d ORDER BY occurred_at ASC", $pid ),
				ARRAY_A
			);
			if ( is_array( $rows ) ) {
				$addenda = $rows;
			}
		}

		return array( 's1' => $s1, 's3' => $s3, 'calc' => $calc, 'why' => $why, 'addenda' => $addenda );
	}

	/**
	 * Turn the AI shape into the template's flat fields: three pre-rendered grey
	 * HTML lists + six tailored scalars (the contract in
	 * PDFMONKEY-FLIGHT-NOTES-INTEGRATION-NOTES.md). Empty → '' so the template's
	 * {% if %} guards hide the slot and the static chrome still prints.
	 */
	private static function render_ai_fragments( $ai ) {
		$ai = is_array( $ai ) ? $ai : array();

		$flags_html = '';
		$flag_n     = 0;
		foreach ( ( $ai['flags'] ?? array() ) as $f ) {
			if ( ! is_array( $f ) || $flag_n >= 4 ) {
				continue; // 4 flags max — page-1 space is fixed
			}
			$lbl = trim( (string) ( $f['label'] ?? '' ) );
			$det = trim( (string) ( $f['detail'] ?? '' ) );
			if ( '' === $lbl && '' === $det ) {
				continue;
			}
			$line = ( '' !== $lbl && '' !== $det ) ? $lbl . ' — ' . $det : ( $lbl . $det );
			// Hard cap so a verbose flag can never overflow page 1 and push the
			// Session Outcomes / Pre-consult boxes off the sheet.
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $line ) : strlen( $line );
			if ( $len > 165 ) {
				$line = rtrim( function_exists( 'mb_substr' ) ? mb_substr( $line, 0, 162 ) : substr( $line, 0, 162 ) ) . '…';
			}
			$flags_html .= '<div class="fn-ai-line">– ' . esc_html( $line ) . '</div>';
			$flag_n++;
		}

		$list_html = function ( $items ) {
			$out = '';
			$n   = 0;
			foreach ( (array) $items as $it ) {
				$it = trim( (string) $it );
				if ( '' === $it || $n >= 3 ) {
					continue; // 3 bullets max — page-1 space is fixed
				}
				$len = function_exists( 'mb_strlen' ) ? mb_strlen( $it ) : strlen( $it );
				if ( $len > 120 ) {
					$it = rtrim( function_exists( 'mb_substr' ) ? mb_substr( $it, 0, 117 ) : substr( $it, 0, 117 ) ) . '…';
				}
				$out .= '<div class="fn-ai-line">– ' . esc_html( $it ) . '</div>';
				$n++;
			}
			return $out;
		};

		$t = isset( $ai['tailored'] ) && is_array( $ai['tailored'] ) ? $ai['tailored'] : array();

		return array(
			'flags_html'             => $flags_html,
			'session_outcomes_html'  => $list_html( $ai['session_outcomes'] ?? array() ),
			'pre_consult_notes_html' => $list_html( $ai['pre_consult_notes'] ?? array() ),
			'tailored_frame'         => (string) ( $t['frame'] ?? '' ),
			'tailored_listen'        => (string) ( $t['listen'] ?? '' ),
			'tailored_inspect'       => (string) ( $t['inspect'] ?? '' ),
			'tailored_goals'         => (string) ( $t['goals'] ?? '' ),
			'tailored_health'        => (string) ( $t['health'] ?? '' ),
			'tailored_trajectory'    => (string) ( $t['trajectory'] ?? '' ),
		);
	}

	private static function filename( $flat ) {
		$name = isset( $flat['client_name'] ) ? sanitize_file_name( $flat['client_name'] ) : 'client';
		$name = $name ?: 'client';
		return 'FLIGHT-Consultation-Notes-' . $name . '.pdf';
	}

	// ── PDFMonkey HTTP ──────────────────────────────────────────────
	private static function pdfmonkey_post( $path, $body, $key ) {
		$r = wp_remote_post( self::PDFMONKEY_BASE . $path, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 20,
		) );
		return self::handle( $r );
	}

	private static function pdfmonkey_get( $path, $key ) {
		$r = wp_remote_get( self::PDFMONKEY_BASE . $path, array(
			'headers' => array( 'Authorization' => 'Bearer ' . $key ),
			'timeout' => 20,
		) );
		return self::handle( $r );
	}

	private static function handle( $r ) {
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'hdlv2_fn_http', $r->get_error_message(), array( 'status' => 502 ) );
		}
		$code = wp_remote_retrieve_response_code( $r );
		$data = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && isset( $data['errors'] ) ? wp_json_encode( $data['errors'] ) : "HTTP $code";
			return new WP_Error( 'hdlv2_fn_api', 'PDFMonkey: ' . $msg, array( 'status' => 502 ) );
		}
		return is_array( $data ) ? $data : array();
	}
}
