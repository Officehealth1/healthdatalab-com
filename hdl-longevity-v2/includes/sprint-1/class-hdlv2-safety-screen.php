<?php
/**
 * HDL V2 — Front-door Safety Screen (deterministic red-flag mapper).
 *
 * Turns the two Stage-1 safety questions (a critical-symptom checklist + an
 * optional mental-health question) into structured medical flags WITHOUT an
 * AI call. A ticked box is a certain signal; we never depend on a model to
 * catch a stroke from free text.
 *
 * Flags produced here use the SAME object shape + signature formula as the
 * AI red-flag scan (HDLV2_AI_Service::scan_for_flags), so they merge cleanly
 * into wp_hdlv2_form_progress.flags and light up the existing dashboard
 * "Needs attention" status + client emails with no extra plumbing.
 *
 * ── CLINICAL SIGN-OFF REQUIRED BEFORE LIVE ────────────────────────────────
 * The symptom list and the 999 / NHS 111 / GP routing below are derived from
 * the v3 intake doc (NICE NG12 + NHS urgent-care). They are a starting point.
 * Matthew (clinical lead) MUST review SYMPTOM_FLAGS + MH_FLAGS wording and
 * urgency before this is enabled on LIVE. Do not ship medical routing on the
 * engineer's judgement alone.
 *
 * @package HDL_Longevity_V2
 * @since 0.43.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HDLV2_Safety_Screen {

	/**
	 * Critical physical-symptom checklist (Question A).
	 *
	 * key => [ category, urgency, concern, client_facing_wording, practitioner_note ]
	 * All are HARD (time-critical) — the front-door screen only carries the
	 * "could-this-be-serious-soon" items; softer amber signals belong in the
	 * deeper intake. client_facing_wording NEVER names a disease (detect, don't
	 * diagnose) and always states the exact channel to use.
	 */
	const SYMPTOM_FLAGS = array(
		// ── Heart & circulation ──
		'chest_pain' => array(
			'label'     => 'Chest pain or pressure, especially on effort',
			'category'  => 'HARD',
			'urgency'   => 'THIS_WEEK',
			'concern'   => 'Chest pain on exertion needs a cardiac check',
			'client'    => 'You mentioned chest pain or pressure when you exert yourself. Please have this looked at by a doctor before starting any new exercise — contact NHS 111 or your GP in the next day or two. If it is ever severe, lasts more than 15 minutes, or comes with sweating, nausea, or pain spreading to your arm or jaw, call 999.',
			'prac'      => 'Reports exertional chest pain/pressure. Exclude a cardiac cause before any exercise clearance.',
		),
		'breathless' => array(
			'label'     => 'Unusual breathlessness (at rest, lying flat, or with light effort)',
			'category'  => 'HARD',
			'urgency'   => 'THIS_WEEK',
			'concern'   => 'New or unusual breathlessness warrants assessment',
			'client'    => 'You mentioned unusual breathlessness. This is worth discussing with your GP or NHS 111 soon. If it comes on suddenly or severely, call 999.',
			'prac'      => 'Reports unusual breathlessness (rest / lying flat / minimal exertion). Consider cardiac / respiratory review.',
		),
		'fainting' => array(
			'label'     => 'Fainting, blackouts, or near-blackouts',
			'category'  => 'HARD',
			'urgency'   => 'THIS_WEEK',
			'concern'   => 'Fainting episodes need a medical look',
			'client'    => 'You mentioned fainting or near-blackouts. Please discuss this with your GP or NHS 111 soon, and avoid driving until you have been seen if it happens without warning.',
			'prac'      => 'Reports syncope / near-syncope. Consider cardiac (arrhythmia / structural) review before exercise.',
		),
		// ── Brain & nerves (stroke pattern) ──
		'stroke_weakness' => array(
			'label'     => 'Sudden weakness, numbness, or tingling — especially on one side',
			'category'  => 'HARD',
			'urgency'   => 'TODAY',
			'concern'   => 'Sudden one-sided weakness can be time-critical',
			'client'    => 'You mentioned sudden weakness or numbness, especially on one side. Symptoms like this can be time-critical. If they are happening now or are new, call 999. Otherwise contact NHS 111 or your GP today.',
			'prac'      => 'Reports sudden focal weakness/numbness. Stroke/TIA pattern — urgent assessment.',
		),
		'stroke_speech' => array(
			'label'     => 'Sudden trouble speaking or finding words',
			'category'  => 'HARD',
			'urgency'   => 'TODAY',
			'concern'   => 'Sudden speech difficulty can be time-critical',
			'client'    => 'You mentioned sudden difficulty speaking or finding words. This can be time-critical. If it is happening now or is new, call 999. Otherwise contact NHS 111 or your GP today.',
			'prac'      => 'Reports sudden speech disturbance. Stroke/TIA pattern — urgent assessment.',
		),
		'stroke_vision' => array(
			'label'     => 'Sudden vision loss or double vision',
			'category'  => 'HARD',
			'urgency'   => 'TODAY',
			'concern'   => 'Sudden vision change can be time-critical',
			'client'    => 'You mentioned a sudden change in your vision. This can be time-critical. If it is happening now or is new, call 999. Otherwise contact NHS 111 or your GP today.',
			'prac'      => 'Reports sudden visual loss / diplopia. Stroke/TIA or ocular emergency — urgent assessment.',
		),
		'worst_headache' => array(
			'label'     => 'A sudden "worst headache of my life"',
			'category'  => 'HARD',
			'urgency'   => 'TODAY',
			'concern'   => 'A sudden severe headache needs urgent assessment',
			'client'    => 'You mentioned a sudden, very severe headache. If it came on like a thunderclap or is the worst you have ever had, call 999. Otherwise contact NHS 111 today.',
			'prac'      => 'Reports sudden severe / thunderclap headache. Exclude subarachnoid haemorrhage — urgent.',
		),
		// ── Possible cancer signals (NICE NG12) ──
		'weight_loss' => array(
			'label'     => 'Unexplained weight loss without trying',
			'category'  => 'HARD',
			'urgency'   => 'THIS_WEEK',
			'concern'   => 'Unexplained weight loss warrants a GP review',
			'client'    => 'You mentioned losing weight without trying. It is worth booking a GP appointment this week to look into it.',
			'prac'      => 'Reports unintended weight loss. NICE NG12 — GP review this week.',
		),
		'new_lump' => array(
			'label'     => 'A new lump (breast, testicle, neck, armpit, groin, or abdomen)',
			'category'  => 'HARD',
			'urgency'   => 'THIS_WEEK',
			'concern'   => 'A new lump should be examined',
			'client'    => 'You mentioned a new lump. Please book a GP appointment this week so it can be examined.',
			'prac'      => 'Reports a new lump. NICE NG12 — GP examination this week.',
		),
		'bleeding' => array(
			'label'     => 'Blood in your stool, urine, or when you cough',
			'category'  => 'HARD',
			'urgency'   => 'THIS_WEEK',
			'concern'   => 'Unexplained bleeding warrants a GP review',
			'client'    => 'You mentioned noticing blood (in your stool, urine, or when coughing). Please book a GP appointment this week to have it checked.',
			'prac'      => 'Reports blood in stool / urine / haemoptysis. NICE NG12 — GP review this week.',
		),
		'swallowing' => array(
			'label'     => 'Difficulty or pain swallowing',
			'category'  => 'HARD',
			'urgency'   => 'THIS_WEEK',
			'concern'   => 'Persistent swallowing difficulty warrants review',
			'client'    => 'You mentioned difficulty or pain when swallowing. If it has lasted more than a couple of weeks, please book a GP appointment this week.',
			'prac'      => 'Reports dysphagia/odynophagia. NICE NG12 — GP review this week.',
		),
		'persistent_cough' => array(
			'label'     => 'A cough lasting more than three weeks',
			'category'  => 'HARD',
			'urgency'   => 'THIS_WEEK',
			'concern'   => 'A persistent cough warrants a GP review',
			'client'    => 'You mentioned a cough that has lasted more than three weeks. Please book a GP appointment this week to look into it.',
			'prac'      => 'Reports cough > 3 weeks. NICE NG12 (esp. if smoker/ex-smoker) — GP review this week.',
		),
		'changing_mole' => array(
			'label'     => 'A mole or skin patch changing in size, shape, or colour',
			'category'  => 'HARD',
			'urgency'   => 'THIS_WEEK',
			'concern'   => 'A changing skin lesion should be examined',
			'client'    => 'You mentioned a mole or skin patch that is changing. Please book a GP appointment this week so it can be examined.',
			'prac'      => 'Reports changing/bleeding skin lesion. NICE NG12 — GP examination this week.',
		),
		'abnormal_bleeding' => array(
			'label'     => 'Bleeding after the menopause, or unusually heavy or irregular bleeding',
			'category'  => 'HARD',
			'urgency'   => 'THIS_WEEK',
			'concern'   => 'Abnormal bleeding warrants a GP review',
			'client'    => 'You mentioned bleeding that is unusual for you. Please book a GP appointment this week to have it looked into.',
			'prac'      => 'Reports postmenopausal / abnormal vaginal bleeding. NICE NG12 — GP review this week.',
		),
	);

	/**
	 * Mental-health question (Question B). Only the two self-harm items are
	 * crisis (is_crisis => routes to the crisis-resources email, not a GP
	 * nudge). Low mood / anxiety are amber GP conversations.
	 */
	const MH_FLAGS = array(
		'low_mood' => array(
			'label'     => 'Persistent low mood for weeks at a time',
			'category'  => 'AMBER',
			'urgency'   => 'WITHIN_WEEKS',
			'concern'   => 'Persistent low mood is worth a GP conversation',
			'client'    => 'From what you shared, it is worth talking to your GP about how your mood has been lately. Support helps, and you do not have to manage it on your own.',
			'prac'      => 'Reports persistent low mood (weeks). Consider mood review at consult.',
			'is_crisis' => false,
		),
		'anxiety' => array(
			'label'     => 'Severe anxiety affecting sleep, work, or relationships',
			'category'  => 'AMBER',
			'urgency'   => 'WITHIN_WEEKS',
			'concern'   => 'Severe anxiety is worth a GP conversation',
			'client'    => 'From what you shared, it is worth talking to your GP about the anxiety you have been experiencing. There is effective support available.',
			'prac'      => 'Reports severe anxiety affecting function. Consider review at consult.',
			'is_crisis' => false,
		),
		'self_harm' => array(
			'label'     => 'Thoughts of harming yourself',
			'category'  => 'HARD',
			'urgency'   => 'TODAY',
			'concern'   => 'Disclosed thoughts of self-harm',
			'client'    => '', // no per-item echo — crisis support is sent via HDLV2_Flags_Store::notify()
			'prac'      => 'Disclosed thoughts of self-harm on the safety screen. Crisis resources emailed. Follow up sensitively.',
			'is_crisis' => true,
		),
		'life_not_worth' => array(
			'label'     => 'Thoughts that life might not be worth living',
			'category'  => 'HARD',
			'urgency'   => 'TODAY',
			'concern'   => 'Disclosed thoughts that life may not be worth living',
			'client'    => '', // no per-item echo — crisis support is sent via HDLV2_Flags_Store::notify()
			'prac'      => 'Disclosed thoughts that life may not be worth living. Crisis resources emailed. Follow up sensitively.',
			'is_crisis' => true,
		),
	);

	/** Symptom keys that are valid input (allowlist for sanitisation). */
	public static function allowed_symptom_keys() {
		return array_keys( self::SYMPTOM_FLAGS );
	}

	/** Mental-health keys that are valid input (allowlist for sanitisation). */
	public static function allowed_mh_keys() {
		return array_keys( self::MH_FLAGS );
	}

	/**
	 * Sanitise the raw `safety` payload from the widget into a clean shape:
	 *   [ 'symptoms' => [ allowed keys… ], 'mh' => [ allowed keys… ] ]
	 * Anything not on the allowlist is dropped (defends against an embedded
	 * widget on a third-party site posting arbitrary keys). Returns array()
	 * when nothing valid was ticked — callers store nothing in that case.
	 */
	public static function sanitize_input( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();

		$symptoms = isset( $raw['symptoms'] ) && is_array( $raw['symptoms'] ) ? $raw['symptoms'] : array();
		$clean_symptoms = array_values( array_intersect(
			array_map( 'sanitize_key', $symptoms ),
			self::allowed_symptom_keys()
		) );
		if ( ! empty( $clean_symptoms ) ) {
			$out['symptoms'] = $clean_symptoms;
		}

		$mh = isset( $raw['mh'] ) && is_array( $raw['mh'] ) ? $raw['mh'] : array();
		$clean_mh = array_values( array_intersect(
			array_map( 'sanitize_key', $mh ),
			self::allowed_mh_keys()
		) );
		if ( ! empty( $clean_mh ) ) {
			$out['mh'] = $clean_mh;
		}

		return $out;
	}

	/**
	 * Map clean safety answers to flag objects (same shape as the AI scan).
	 *
	 * @param array $safety [ 'symptoms' => [keys], 'mh' => [keys] ]
	 * @return array list of flag objects (may be empty)
	 */
	public static function map_flags( $safety ) {
		$flags = array();

		$symptoms = isset( $safety['symptoms'] ) && is_array( $safety['symptoms'] ) ? $safety['symptoms'] : array();
		foreach ( $symptoms as $key ) {
			if ( ! isset( self::SYMPTOM_FLAGS[ $key ] ) ) {
				continue;
			}
			$def = self::SYMPTOM_FLAGS[ $key ];
			$flags[] = self::build_flag( 'sym_' . $key, $def, false );
		}

		$mh = isset( $safety['mh'] ) && is_array( $safety['mh'] ) ? $safety['mh'] : array();
		foreach ( $mh as $key ) {
			if ( ! isset( self::MH_FLAGS[ $key ] ) ) {
				continue;
			}
			$def = self::MH_FLAGS[ $key ];
			$flags[] = self::build_flag( 'mh_' . $key, $def, ! empty( $def['is_crisis'] ) );
		}

		return $flags;
	}

	/** Assemble a single flag object with a stable signature. */
	private static function build_flag( $id, $def, $is_crisis ) {
		$flag = array(
			'id'                    => $id,
			'label'                 => $def['label'],
			'category'              => $def['category'],
			'trigger'               => 'Safety screen — ticked: ' . $def['label'],
			'concern'               => $def['concern'],
			'urgency'               => $def['urgency'],
			'client_facing_wording' => $def['client'],
			'practitioner_note'     => $def['prac'],
			'is_crisis'             => (bool) $is_crisis,
			'source'                => 'safety_screen',
			'messaged_at'           => null,
		);
		$flag['signature'] = self::signature( $flag );
		return $flag;
	}

	/**
	 * Stable per-flag signature for dedup across re-scans. MUST match
	 * HDLV2_AI_Service::redflag_signature() so a deterministic flag and an
	 * AI flag describing the same thing collapse to one.
	 */
	public static function signature( $flag ) {
		$basis = ( $flag['category'] ?? '' ) . '|' . ( $flag['trigger'] ?? '' ) . '|' . ( $flag['concern'] ?? '' );
		return substr( hash( 'sha256', $basis ), 0, 16 );
	}

	/**
	 * Full pipeline: map → merge into form_progress.flags (dedup, preserve
	 * messaged_at) → write flag columns → email the client for new
	 * client-relevant flags. Deterministic sibling of HDLV2_Redflag_Jobs::run_now.
	 *
	 * @param int   $form_progress_id
	 * @param array $safety clean answers (already sanitised)
	 * @return array|WP_Error summary on success
	 */
	public static function process( $form_progress_id, $safety ) {
		global $wpdb;
		$form_progress_id = (int) $form_progress_id;
		if ( ! $form_progress_id ) {
			return new WP_Error( 'bad_id', 'Missing form_progress id.' );
		}

		$new_flags = self::map_flags( $safety );
		if ( empty( $new_flags ) ) {
			return array( 'flags_total' => 0, 'messaged' => 0 ); // nothing ticked that flags
		}

		$t   = $wpdb->prefix . 'hdlv2_form_progress';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, client_user_id, client_email, client_name, practitioner_user_id, flags
			 FROM $t WHERE id = %d AND deleted_at IS NULL",
			$form_progress_id
		) );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Form progress row not found.' );
		}

		// Merge with any existing flags (e.g. a later AI scan), dedup by
		// signature, preserve prior messaged_at so we never double-email.
		$existing = json_decode( (string) $row->flags, true );
		$existing = is_array( $existing ) ? $existing : array();
		$by_sig   = array();
		foreach ( $existing as $e ) {
			if ( ! empty( $e['signature'] ) ) {
				$by_sig[ $e['signature'] ] = $e;
			}
		}

		$to_message = array();
		foreach ( $new_flags as $flag ) {
			$sig = $flag['signature'];
			if ( isset( $by_sig[ $sig ] ) ) {
				continue; // already known — don't re-message
			}
			$by_sig[ $sig ] = $flag;
			if ( strtoupper( (string) $flag['category'] ) !== 'CONTEXT' ) {
				$to_message[] = $flag;
			}
		}
		$merged = array_values( $by_sig );

		if ( ! empty( $to_message ) && ! empty( $row->client_email ) ) {
			// v0.44.0 — single notification path (Make-or-wp_mail) shared with the AI scan.
			if ( class_exists( 'HDLV2_Flags_Store' ) ) {
				HDLV2_Flags_Store::notify( $row, $to_message, $merged );
			}
		}

		$wpdb->update( $t,
			array(
				'has_flags'         => empty( $merged ) ? 0 : 1,
				'flags'             => wp_json_encode( $merged ),
				'flags_scanned_at'  => current_time( 'mysql', true ),
				'flags_scan_status' => 'ok',
			),
			array( 'id' => $form_progress_id )
		);

		return array( 'flags_total' => count( $merged ), 'messaged' => count( $to_message ) );
	}

	/**
	 * Submit-time notifier for the PUBLIC widget path (no form_progress yet).
	 *
	 * The public path records a pending lead and defers user/form_progress
	 * creation to practitioner Confirm — too slow for a self-harm or HARD
	 * symptom disclosure. Map flags here, fire the same client + practitioner
	 * emails via the shared notify() path, and RETURN the messaged-stamped flag
	 * objects so the caller can carry them on the lead
	 * (stage1_data._safety_flags). At Confirm, complete_signup() seeds
	 * form_progress.flags from that carrier so the existing process() call
	 * dedups and never re-sends.
	 *
	 * @param array $args   { client_email, client_name, practitioner_user_id }
	 * @param array $safety clean answers (already sanitised): [ 'symptoms'=>[], 'mh'=>[] ]
	 * @return array stamped flag objects (messaged_at set), or [] if nothing flagged.
	 */
	public static function process_lead( $args, $safety ) {
		$new_flags = self::map_flags( $safety );
		if ( empty( $new_flags ) ) {
			return array();
		}
		if ( empty( $args['client_email'] ) || ! class_exists( 'HDLV2_Flags_Store' ) ) {
			return array();
		}

		// Safety flags are HARD/AMBER (never CONTEXT) — all are messageable.
		$to_message = array();
		foreach ( $new_flags as $f ) {
			if ( strtoupper( (string) ( $f['category'] ?? '' ) ) !== 'CONTEXT' ) {
				$to_message[] = $f;
			}
		}
		if ( empty( $to_message ) ) {
			return $new_flags;
		}

		// Synthetic progress — no client_user_id / form_progress exists yet, so
		// the practitioner alert links to the Pending Leads inbox.
		$progress = (object) array(
			'client_email'         => (string) $args['client_email'],
			'client_name'          => (string) ( $args['client_name'] ?? '' ),
			'client_user_id'       => 0,
			'practitioner_user_id' => (int) ( $args['practitioner_user_id'] ?? 0 ),
		);
		$merged = $new_flags;
		// dest=pending_leads → the practitioner CTA becomes a one-shot
		// auto-login link to the Pending Leads inbox (no client record yet).
		HDLV2_Flags_Store::notify(
			$progress,
			$to_message,
			$merged,
			array( 'dest' => 'pending_leads' )
		);

		return $merged; // messaged_at now stamped on the messaged flags
	}

}
