<?php
/**
 * Final Report — direct PDFMonkey re-render service (no Make.com, no Claude,
 * no email). Fired when a practitioner edits milestone text post-finalise:
 * rebuilds the EXACT Make-module-[3] payload from STORED content via
 * HDLV2_Final_Report::build_pdf_payload() (the same builder fire_webhook
 * uses, including its v0.27.2 strtr text pass → character parity with a
 * Make render), POSTs it straight to the Final Report template, self-hosts
 * the result via HDLV2_Report_PDF::fetch_and_store(), and advances the
 * report row's pdf_* pointers. The finalise flow (Make route + client
 * emails) is untouched and remains the ONLY thing that emails clients.
 *
 * Cost per run: one PDFMonkey render. Zero Claude (pdf_sections /
 * awaken/lift/thrive / milestones are all read from storage), zero emails
 * (no webhook fire, no wp_mail anywhere in this file).
 *
 * @package HDL_Longevity_V2
 * @since   0.46.58 (editable milestones + weekly FP, direct-to-PDFMonkey)
 */

defined( 'ABSPATH' ) || exit;

class HDLV2_Final_Report_PDF_Service {

	const PDFMONKEY_BASE = 'https://api.pdfmonkey.io/api/v1';
	const POLL_TRIES     = 20;   // the 20pp report renders slower than the FP
	const POLL_WAIT      = 2;
	const JOB            = 'render_final_report_pdf';
	const COOLDOWN       = 120;

	/** Final Report template — overridable via wp-config. */
	private static function template_id() {
		return defined( 'HDLV2_FINAL_REPORT_TEMPLATE_ID' ) && HDLV2_FINAL_REPORT_TEMPLATE_ID
			? HDLV2_FINAL_REPORT_TEMPLATE_ID
			: '1c9f06c5-ca6d-4264-9993-33f3531f9f89';
	}

	public static function register() {
		if ( class_exists( 'HDLV2_Job_Queue' ) ) {
			HDLV2_Job_Queue::register_handler( self::JOB, array( __CLASS__, 'handle_job' ) );
		}
	}

	public static function enqueue_render( $report_id ) {
		$report_id = absint( $report_id );
		if ( ! $report_id || ! defined( 'HDLV2_PDFMONKEY_API_KEY' ) || ! HDLV2_PDFMONKEY_API_KEY ) {
			return false;
		}
		if ( class_exists( 'HDLV2_Job_Queue' ) ) {
			$existing = HDLV2_Job_Queue::find_latest( self::JOB, $report_id );
			// Pending-only dedupe — a RUNNING job snapshotted pre-edit state
			// (same race fix as the flight-plan renderer).
			if ( $existing && 'pending' === $existing->status ) {
				return (int) $existing->id;
			}
			$job_id = HDLV2_Job_Queue::enqueue(
				self::JOB,
				array( 'report_id' => $report_id ),
				array(
					'reference_id' => $report_id,
					'idem_key'     => 'frpdf:' . $report_id . ':' . substr( md5( uniqid( '', true ) ), 0, 12 ),
					'priority'     => 90,
					'max_attempts' => 1,
				)
			);
			return is_wp_error( $job_id ) ? false : (int) $job_id;
		}
		$res = self::render( $report_id );
		return is_wp_error( $res ) ? false : true;
	}

	public static function handle_job( $payload ) {
		$res = self::render( absint( $payload['report_id'] ?? 0 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'stored' => $res['relpath'] ?? '' );
	}

	/**
	 * Rebuild the [3] payload from stored data and render. All inputs are
	 * reads: calc = report_content['calc_snapshot'] (the A2 frozen copy the
	 * original PDF used), narrative + pdf_sections = report_content,
	 * milestones = reports.milestones (the CURRENT copy — i.e. including
	 * practitioner edits), recommendations/notes/health-changes = the same
	 * collect_consult_inputs() merge generate() uses (so consultation edits
	 * legitimately flow into a re-render).
	 */
	public static function render( $report_id ) {
		global $wpdb;
		$key = defined( 'HDLV2_PDFMONKEY_API_KEY' ) ? HDLV2_PDFMONKEY_API_KEY : '';
		if ( ! $key ) {
			return new WP_Error( 'hdlv2_frpdf_unconfigured', 'PDFMonkey is not configured on this server.', array( 'status' => 503 ) );
		}
		$report = $wpdb->get_row( $wpdb->prepare(
			"SELECT r.* FROM {$wpdb->prefix}hdlv2_reports r
			   JOIN {$wpdb->prefix}hdlv2_form_progress fp ON fp.id = r.form_progress_id
			  WHERE r.id = %d AND r.report_type = 'final' AND fp.deleted_at IS NULL LIMIT 1",
			absint( $report_id )
		) );
		if ( ! $report ) {
			return new WP_Error( 'hdlv2_frpdf_not_found', 'Final report not found.', array( 'status' => 404 ) );
		}
		$progress = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d", (int) $report->form_progress_id
		) );

		$content    = json_decode( (string) $report->report_content, true ) ?: array();
		$milestones = json_decode( (string) $report->milestones, true ) ?: array();
		$s1_data    = json_decode( (string) $progress->stage1_data, true ) ?: array();

		// Calc: the frozen snapshot the original PDF rendered from (A2).
		// Legacy rows without one re-derive exactly as generate() would.
		$calc_result = ( isset( $content['calc_snapshot'] ) && is_array( $content['calc_snapshot'] ) )
			? $content['calc_snapshot'] : array();
		if ( empty( $calc_result['scores'] ) ) {
			$s3_data   = json_decode( (string) $progress->stage3_data, true ) ?: array();
			$calc_data = array_merge( $s1_data, $s3_data );
			if ( isset( $calc_data['activity'] ) && ! isset( $calc_data['physicalActivity'] ) ) {
				$calc_data['physicalActivity'] = $calc_data['activity'];
			}
			foreach ( $calc_data as $k => $v ) {
				if ( 'skip' === $v ) {
					$calc_data[ $k ] = null;
				}
			}
			$age         = (int) ( $calc_data['q1_age'] ?? $calc_data['age'] ?? 0 );
			$gender      = $calc_data['q1_sex'] ?? $calc_data['gender'] ?? 'other';
			$calc_result = HDLV2_Rate_Calculator::calculate_full( $age, $calc_data, $gender );
		}

		// WHY profile — same read generate() does.
		$why_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT distilled_why, ai_reformulation, key_people, motivations, fears
			 FROM {$wpdb->prefix}hdlv2_why_profiles WHERE form_progress_id = %d LIMIT 1",
			(int) $progress->id
		), ARRAY_A );
		$why_profile = $why_row ?: array();
		foreach ( array( 'key_people', 'motivations', 'fears' ) as $jk ) {
			if ( ! empty( $why_profile[ $jk ] ) ) {
				$why_profile[ $jk ] = json_decode( $why_profile[ $jk ], true );
			}
		}

		// Consultation inputs — the same merge generate() uses (extracted).
		$consult = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}hdlv2_consultation_notes
			 WHERE form_progress_id = %d AND status = 'report_generated'
			 ORDER BY id DESC LIMIT 1",
			(int) $progress->id
		) );
		$recommendations = array();
		$health_changes  = json_decode( (string) $report->health_data_changes, true ) ?: array();
		$typed_notes     = '';
		if ( $consult ) {
			$ci              = HDLV2_Final_Report::collect_consult_inputs( $consult, (int) $consult->id );
			$recommendations = $ci['recommendations'];
			$typed_notes     = $ci['typed_notes'];
			if ( empty( $health_changes ) ) {
				$health_changes = $ci['health_changes'];
			}
		}

		$payload = HDLV2_Final_Report::build_pdf_payload(
			$progress, $calc_result, $s1_data, $content, $milestones, $why_profile,
			$recommendations, $health_changes, $typed_notes, (int) $progress->practitioner_user_id
		);
		// Direct render: PDFMonkey never needs the Make callback credentials.
		unset( $payload['callback_url'], $payload['callback_secret'] );

		// Cooldown keyed by CONTENT hash — identical content re-renders are
		// blocked inside the window; a genuine edit renders immediately.
		$hash = md5( wp_json_encode( $payload ) );
		if ( get_transient( 'hdlv2_frpdf_cool_' . $report_id . '_' . $hash ) ) {
			return new WP_Error( 'hdlv2_frpdf_cooldown', 'This exact report content was just rendered — try again in a couple of minutes.', array( 'status' => 429 ) );
		}

		$client_user = get_userdata( (int) $report->client_user_id );
		$fname       = sanitize_file_name( ( $client_user ? $client_user->display_name : 'client' )
			. ' — Final longevity report — ' . current_time( 'j F Y' ) . '.pdf' );

		$doc = self::api_post( '/documents', array( 'document' => array(
			'document_template_id' => self::template_id(),
			'status'               => 'pending',
			'payload'              => $payload,
			'meta'                 => wp_json_encode( array( '_filename' => $fname ) ),
		) ), $key );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		$doc_id = $doc['document']['id'] ?? '';
		if ( ! $doc_id ) {
			return new WP_Error( 'hdlv2_frpdf_no_doc', 'PDFMonkey did not return a document id.', array( 'status' => 502 ) );
		}
		set_transient( 'hdlv2_frpdf_cool_' . $report_id . '_' . $hash, time(), self::COOLDOWN );

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
				return new WP_Error( 'hdlv2_frpdf_failed', 'PDF render failed: ' . ( $card['document_card']['failure_cause'] ?? 'unknown' ), array( 'status' => 502 ) );
			}
		}
		if ( ! $download_url ) {
			return new WP_Error( 'hdlv2_frpdf_timeout', 'PDF render timed out.', array( 'status' => 504 ) );
		}

		$stored = HDLV2_Report_PDF::fetch_and_store( $download_url, 'report-final-' . $report_id );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		// Same pointer columns the Make callback writes — additive file set.
		$wpdb->update(
			$wpdb->prefix . 'hdlv2_reports',
			array(
				'pdf_stored_path'  => $stored['relpath'],
				'pdf_url'          => home_url( '/?hdlv2_report_pdf=' . $report_id ),
				'pdf_generated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $report_id ),
			array( '%s', '%s', '%s' ), array( '%d' )
		);
		return array( 'relpath' => $stored['relpath'] );
	}

	// ── PDFMonkey HTTP (native JSON) ──
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
			return new WP_Error( 'hdlv2_frpdf_http', $r->get_error_message(), array( 'status' => 502 ) );
		}
		$code = wp_remote_retrieve_response_code( $r );
		$data = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && isset( $data['errors'] ) ? wp_json_encode( $data['errors'] ) : "HTTP $code";
			return new WP_Error( 'hdlv2_frpdf_api', 'PDFMonkey: ' . $msg, array( 'status' => 502 ) );
		}
		return is_array( $data ) ? $data : array();
	}
}
