<?php
/**
 * F·L·I·G·H·T Consultation Notes — data layer (Phase 1).
 *
 * Builds the flat PDFMonkey payload for the practitioner's Stage 4 print aid
 * from a client's Stage 1–3 data. PURE READ, ZERO side effects: it never writes
 * to any table, never charges credits, never calls HDLV2_Final_Report::generate.
 * Trigger-agnostic — takes only a form_progress id — so a future automated /
 * self-serve path (Phase 2) can reuse it unchanged.
 *
 * Numbers come from HDLV2_Rate_Calculator::calculate_full() — the SAME single
 * source of truth the Longevity Report uses — so ageing / body-comp figures
 * match the report exactly. Missing fields degrade gracefully to "—".
 *
 * NOTE: the PDF render pipeline (webhook + callback + poll), the REST route,
 * and the AI text method are LATER phases. This file only assembles the data.
 *
 * @package HDL_Longevity_V2
 * @since   0.46.x (Flight Consultation Notes — Phase 1)
 */

defined( 'ABSPATH' ) || exit;

class HDLV2_Flight_Notes {

	const DASH = '—';

	/**
	 * Build the flat consultation-notes payload for one client.
	 *
	 * @param int $form_progress_id wp_hdlv2_form_progress.id
	 * @return array|WP_Error Flat scalar payload (PDFMonkey-ready) or WP_Error.
	 */
	public static function build_flight_notes_payload( $form_progress_id ) {
		global $wpdb;

		$form_progress_id = absint( $form_progress_id );
		if ( ! $form_progress_id ) {
			return new WP_Error( 'hdlv2_flight_notes_bad_id', 'Missing form_progress id.' );
		}

		$fp_table = $wpdb->prefix . 'hdlv2_form_progress';
		// `deleted_at IS NULL` (prod-readiness SD-2): never build a print aid for
		// an archived/soft-deleted client. The caller already filters deleted
		// rows at the resolver; this is defence-in-depth for any direct caller.
		$progress = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$fp_table} WHERE id = %d AND deleted_at IS NULL", $form_progress_id )
		);
		if ( ! $progress ) {
			return new WP_Error( 'hdlv2_flight_notes_not_found', 'Client record not found.' );
		}

		$s1 = json_decode( (string) $progress->stage1_data, true ) ?: array();
		$s3 = json_decode( (string) $progress->stage3_data, true ) ?: array();

		// ── Calc — the single source of truth (matches the Longevity Report) ──
		// Prefer the Stage-3 stored snapshot (server_result, == calculate_full
		// output written at Stage 3, exactly what the draft report shows). Fall
		// back to a fresh recompute so the aid always has the latest numbers.
		$calc = ( isset( $s3['server_result'] ) && is_array( $s3['server_result'] ) )
			? $s3['server_result']
			: array();
		if ( empty( $calc ) || empty( $calc['scores'] ) ) {
			$calc_data = array_merge( $s1, $s3 );
			if ( isset( $calc_data['activity'] ) && ! isset( $calc_data['physicalActivity'] ) ) {
				$calc_data['physicalActivity'] = $calc_data['activity']; // V1-style alias
			}
			// Frontend stores body-comp as *_cm / *_kg; calculate_full() reads
			// height/weight/waist/hip. Map so a recompute scores body-comp too.
			foreach ( array( 'height_cm' => 'height', 'weight_kg' => 'weight', 'waist_cm' => 'waist', 'hip_cm' => 'hip' ) as $from => $to ) {
				if ( isset( $calc_data[ $from ] ) && ! isset( $calc_data[ $to ] ) ) {
					$calc_data[ $to ] = $calc_data[ $from ];
				}
			}
			foreach ( $calc_data as $k => $v ) {
				if ( 'skip' === $v ) {
					$calc_data[ $k ] = null;
				}
			}
			$age    = (int) ( $calc_data['q1_age'] ?? $calc_data['age'] ?? 0 );
			$gender = $calc_data['q1_sex'] ?? $calc_data['gender'] ?? 'other';
			$calc   = HDLV2_Rate_Calculator::calculate_full( $age, $calc_data, $gender );
		}
		$scores = ( isset( $calc['scores'] ) && is_array( $calc['scores'] ) ) ? $calc['scores'] : array();

		$chrono_age = $s1['q1_age'] ?? $s1['age'] ?? null;
		$gender     = $s1['q1_sex'] ?? $s1['gender'] ?? '';

		// ── Practitioner identity (name + logo; NO clinic field exists → "—") ──
		$prac_name = '';
		$prac_logo = '';
		if ( $progress->practitioner_user_id ) {
			$u = get_userdata( $progress->practitioner_user_id );
			if ( $u ) {
				$prac_name = (string) $u->display_name;
			}
			if ( class_exists( 'HDLV2_Practitioner' ) ) {
				$prac_logo = (string) HDLV2_Practitioner::get_logo_url( (int) $progress->practitioner_user_id, true );
			}
		}

		// ── WHY quote (Stage 2 extract) ──
		$why_table = $wpdb->prefix . 'hdlv2_why_profiles';
		$why_row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT distilled_why FROM {$why_table} WHERE form_progress_id = %d LIMIT 1", $form_progress_id )
		);
		$why_quote = ( $why_row && ! empty( $why_row->distilled_why ) ) ? trim( (string) $why_row->distilled_why ) : '';

		// ── Metabolic surrogate (SINGLE honest composite — "Inferred · no bloods") ──
		// The sample shows two readouts (insulin / inflammation) but the product
		// measures NO blood and metabolic_signal() returns ONE composite band.
		// We surface the honest single band; a two-band relabel is a Matthew
		// decision (plan §6 / AUDIT-AP1). Do NOT fabricate two readouts here.
		$metabolic = HDLV2_Rate_Calculator::metabolic_signal( $scores, $calc );

		// ── Derived ageing/body-comp numbers ──
		$rate    = isset( $calc['rate'] )    ? (float) $calc['rate']    : null;
		$bio_age = isset( $calc['bio_age'] ) ? (float) $calc['bio_age'] : null;
		// Body-comp numbers: the report's computed value first, else the
		// frontend-stored Stage-3 value (covers rows whose server_result predates
		// the body-comp keys) — the same real number the client's report shows.
		$bmi  = self::first_num( $calc['bmi']  ?? null, $s3['bmi']  ?? null );
		$whr  = self::first_num( $calc['whr']  ?? null, $s3['whr']  ?? null );
		$whtr = self::first_num( $calc['whtr'] ?? null, $s3['whtr'] ?? null );

		$payload = array(
			'report_type'        => 'flight_consultation_notes',
			'form_progress_id'   => $form_progress_id,

			// Cover / header
			'practitioner_name'  => self::dash( $prac_name ),
			// Only pass a well-formed URL; "null"/junk → '' so the template's HDL
			// fallback fires (a broken-but-valid URL is caught by the template's
			// CSS background fallback + img onerror).
			'practitioner_logo_url' => ( $prac_logo && filter_var( $prac_logo, FILTER_VALIDATE_URL ) ) ? $prac_logo : '',
			'clinic_name'        => self::clinic_name( (int) $progress->practitioner_user_id ),
			'client_name'        => self::dash( $progress->client_name ?: '' ),
			'client_age'         => self::dash( $chrono_age ),
			'client_sex'         => self::dash( self::sex_label( $gender ) ),
			'report_date'        => current_time( 'j M Y' ),
			// Planned consultation length (sum of the FLIGHT section times ≈ 55 min).
			// Static default to match the approved sample; a future booking
			// integration can override it. Without this the cover's "Duration"
			// label printed with no value.
			'duration'           => '~55 min',

			// Snapshot — Biometrics (raw Stage-3 clinical inputs)
			'height_cm'          => self::num( $s3['height_cm'] ?? $s3['height'] ?? null ),
			'weight_kg'          => self::num( $s3['weight_kg'] ?? $s3['weight'] ?? null ),
			'bp_systolic'        => self::int( $s3['bpSystolic'] ?? null ),
			'bp_diastolic'       => self::int( $s3['bpDiastolic'] ?? null ),
			'resting_hr_bpm'     => self::int( $s3['restingHeartRate'] ?? null ),

			// Snapshot — Ageing (single source of truth = calculate_full)
			'ageing_rate'        => null !== $rate    ? number_format( $rate, 2 ) . '×' . self::rate_suffix( $rate ) : self::DASH,
			'biological_age'     => null !== $bio_age ? number_format( $bio_age, 1 )   : self::DASH,
			'chronological_age'  => self::dash( $chrono_age ),

			// Snapshot — Body composition (number + provisional band word)
			'bmi'                => null !== $bmi  ? number_format( $bmi, 1 )  : self::DASH,
			'bmi_band'           => self::band_word( $scores['bmiScore']  ?? null ),
			'whr'                => null !== $whr  ? number_format( $whr, 2 )  : self::DASH,
			'whr_band'           => self::band_word( $scores['whrScore']  ?? null ),
			'whtr'               => null !== $whtr ? number_format( $whtr, 2 ) : self::DASH,
			'whtr_band'          => self::band_word( $scores['whtrScore'] ?? null ),

			// Snapshot — Metabolic (single honest composite)
			'metabolic_band'     => ( ! empty( $metabolic['show'] ) ) ? self::dash( $metabolic['band_label'] ?? '' ) : self::DASH,
			'metabolic_score'    => ( ! empty( $metabolic['show'] ) ) ? ( $metabolic['score_display'] ?? '' ) : '',
			'metabolic_caption'  => 'Inferred · no bloods',

			// Snapshot — Sleep / Movement / Lifestyle / Meds. Real clients store
			// these as /5 scores/indices (NOT descriptive text), so render the
			// derived /5 score + band word; only show raw text when it is a
			// genuine answer string (older/hand-entered rows).
			'sleep_text'         => self::sleep_text( $s3, $scores ),
			'movement_text'      => self::lifestyle( $s3, $scores, array( 'physicalActivity', 'activity' ), 'physicalActivity' ),
			'alcohol_text'       => self::lifestyle( $s3, $scores, array( 'alcoholConsumption' ), 'alcoholConsumption' ),
			'smoking_text'       => self::lifestyle( $s3, $scores, array( 'smokingStatus' ), 'smokingStatus' ),
			'stress_text'        => self::lifestyle( $s3, $scores, array( 'stressLevels' ), 'stressLevels' ),
			'hydration_text'     => self::lifestyle( $s3, $scores, array( 'dailyHydration' ), 'dailyHydration' ),
			'medications_text'   => self::dash( self::raw( $s3, array( 'medications' ) ) ),

			// Page 3 (INSPECT) — the client's real Stage-3 activity word, lower-cased,
			// so the "Stage 3 said '___' activity" prompt is never a hard-coded guess.
			'activity_word'      => self::activity_word( $s3 ),

			// Snapshot — WHY quote
			'why_quote'          => self::dash( $why_quote ),
		);

		return $payload;
	}

	/**
	 * Per-practitioner clinic / practice name from widget_config (the brand the
	 * practitioner set in Widget Settings, e.g. "Altituding"). Returns '' when
	 * unset → the PDF template hides the Clinic cell. Deliberately NO "—"
	 * fallback (an empty clinic must hide, not print a dash).
	 */
	private static function clinic_name( $practitioner_id ) {
		if ( $practitioner_id <= 0 ) {
			return '';
		}
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare(
			"SELECT clinic_name FROM {$wpdb->prefix}hdlv2_widget_config WHERE practitioner_user_id = %d LIMIT 1",
			$practitioner_id
		) );
		return trim( (string) $v );
	}

	/** "—" when blank/null, else the trimmed string value. */
	private static function dash( $v ) {
		if ( null === $v ) {
			return self::DASH;
		}
		$v = trim( (string) $v );
		return '' === $v ? self::DASH : $v;
	}

	/** First non-empty raw Stage-3 answer among $keys, else '' (caller dashes). */
	private static function raw( $s3, array $keys ) {
		foreach ( $keys as $k ) {
			if ( isset( $s3[ $k ] ) && '' !== trim( (string) $s3[ $k ] ) && 'skip' !== $s3[ $k ] ) {
				return (string) $s3[ $k ];
			}
		}
		return '';
	}

	/** Numeric → trimmed number string, else "—". */
	private static function num( $v ) {
		return is_numeric( $v ) ? (string) ( 0 + $v ) : self::DASH;
	}

	/** Integer → string, else "—". */
	private static function int( $v ) {
		return is_numeric( $v ) ? (string) (int) $v : self::DASH;
	}

	/** First numeric of the args as float, else null. */
	private static function first_num( ...$vals ) {
		foreach ( $vals as $v ) {
			if ( is_numeric( $v ) ) {
				return (float) $v;
			}
		}
		return null;
	}

	/** Normalise sex to a single letter label (F/M/—). */
	private static function sex_label( $g ) {
		$g = strtolower( trim( (string) $g ) );
		if ( '' === $g ) {
			return '';
		}
		if ( in_array( $g, array( 'f', 'female', 'woman' ), true ) ) {
			return 'F';
		}
		if ( in_array( $g, array( 'm', 'male', 'man' ), true ) ) {
			return 'M';
		}
		return ucfirst( $g );
	}

	/**
	 * Provisional band WORD from a /5 score. Vocabulary is NOT final — the
	 * clinical-vs-/5 word choice is a Matthew decision (plan §8). Mirrors the
	 * existing /5 word map (hdlv2-draft-report.js) for now.
	 */
	private static function band_word( $score ) {
		if ( ! is_numeric( $score ) ) {
			return self::DASH;
		}
		$s = (float) $score;
		if ( $s >= 4.5 ) {
			return 'Excellent';
		}
		if ( $s >= 3.5 ) {
			return 'Good';
		}
		if ( $s >= 2.5 ) {
			return 'Fair';
		}
		if ( $s >= 1.5 ) {
			return 'Watch';
		}
		return 'Needs work';
	}

	/**
	 * Lifestyle snapshot value. Real clients store these as an option value
	 * ('0'–'5'), so render the matching human-readable label (e.g. alcohol '5'
	 * → "None") — the same wording the client saw in the Stage-3 form, like the
	 * approved sample. Resolution order:
	 *   1. a genuine free-text answer (older / hand-entered rows) wins verbatim;
	 *   2. the option value mapped to its label via {@see s3_label()};
	 *   3. last resort — the derived "Band · N/5" score (rows with no raw value).
	 *
	 * @param array  $s3        Stage-3 data.
	 * @param array  $scores    calculate_full scores (0-5 per metric).
	 * @param array  $raw_keys  candidate raw answer keys.
	 * @param string $score_key the /5 score key for this metric (also the label key).
	 * @return string
	 */
	private static function lifestyle( $s3, $scores, array $raw_keys, $score_key ) {
		$raw = self::raw( $s3, $raw_keys );
		if ( '' !== $raw && ! is_numeric( $raw ) ) {
			return $raw; // genuine descriptive answer (e.g. "10–12 units/week")
		}
		if ( '' !== $raw && is_numeric( $raw ) ) {
			$label = self::s3_label( $score_key, $raw );
			if ( '' !== $label ) {
				return $label;
			}
		}
		$score = ( is_array( $scores ) && isset( $scores[ $score_key ] ) ) ? $scores[ $score_key ] : null;
		if ( ! is_numeric( $score ) ) {
			return self::DASH;
		}
		$n = (float) $score;
		return self::band_word( $n ) . ' · ' . ( (int) round( $n ) ) . '/5';
	}

	/**
	 * Sleep snapshot value — combines duration + quality into one readable line
	 * (e.g. "7–8 hours · mostly restful"), mirroring the sample. Sleep is the
	 * only two-field row in the snapshot, so it gets its own builder.
	 */
	private static function sleep_text( $s3, $scores ) {
		$dur = isset( $s3['sleepDuration'] ) ? $s3['sleepDuration'] : '';
		// Genuine free-text duration (older rows) wins verbatim.
		if ( '' !== trim( (string) $dur ) && ! is_numeric( $dur ) ) {
			return (string) $dur;
		}
		$dur_label = self::s3_label( 'sleepDuration', $dur );
		$qual      = self::sleep_quality_short( $s3['sleepQuality'] ?? '' );
		if ( '' !== $dur_label && '' !== $qual ) {
			return $dur_label . ' · ' . $qual;
		}
		if ( '' !== $dur_label ) {
			return $dur_label;
		}
		// Last resort: derived /5 score band.
		$score = ( is_array( $scores ) && isset( $scores['sleepDuration'] ) ) ? $scores['sleepDuration'] : null;
		if ( is_numeric( $score ) ) {
			return self::band_word( (float) $score ) . ' · ' . ( (int) round( $score ) ) . '/5';
		}
		return self::DASH;
	}

	/** Lower-cased Stage-3 activity word for the INSPECT prompt ('moderate'), else ''. */
	private static function activity_word( $s3 ) {
		$v = $s3['physicalActivity'] ?? ( $s3['activity'] ?? '' );
		return strtolower( self::s3_label( 'physicalActivity', $v ) );
	}

	/**
	 * Ageing-rate descriptor suffix, e.g. " (slower)". Mirrors the 4-band
	 * thresholds in class-hdlv2-trajectory-svg.php / derive_rate_band() so the
	 * word matches the client's Longevity Report exactly. Returns '' if unknown.
	 */
	private static function rate_suffix( $rate ) {
		if ( ! is_numeric( $rate ) ) {
			return '';
		}
		$r = (float) $rate;
		if ( $r <= 0.95 ) {
			return ' (slower)';
		}
		if ( $r <= 1.05 ) {
			return ' (average)';
		}
		if ( $r <= 1.15 ) {
			return ' (accelerated)';
		}
		return ' (significantly accelerated)';
	}

	/**
	 * Stage-3 answer value ('0'–'5') → concise human-readable label. MIRRORS the
	 * `S3_OPTIONS` option wording in assets/js/hdlv2-staged-form.js (the form's
	 * single source of truth) — the parenthetical gloss is dropped for the
	 * snapshot. B6: now covers ALL 16 scored fields (was 6) and is public —
	 * the consultation page's Health Data editor labels its 0-5 codes through
	 * this same map (HDLV2_Consultation payload `score_labels`). If the form's
	 * options change, update both. Returns '' for blank / unknown values.
	 * sleepQuality delegates to sleep_quality_short() (the existing map).
	 */
	public static function s3_label( $field, $value ) {
		if ( ! is_numeric( $value ) ) {
			return '';
		}
		if ( 'sleepQuality' === $field ) {
			return ucfirst( self::sleep_quality_short( $value ) );
		}
		static $map = array(
			'sleepDuration'      => array(
				'0' => 'Less than 4 hours', '1' => '4–5 hours', '2' => '5–6 hours',
				'3' => '6–7 hours', '4' => '7–8 hours', '5' => 'More than 8 hours',
			),
			'physicalActivity'   => array(
				'0' => 'Sedentary', '1' => 'Very low', '2' => 'Low',
				'3' => 'Moderate', '4' => 'High', '5' => 'Very high',
			),
			'alcoholConsumption' => array(
				'0' => '15+ drinks/week', '1' => '10–14 drinks/week', '2' => '6–9 drinks/week',
				'3' => '3–5 drinks/week', '4' => '1–2 drinks/week', '5' => 'None',
			),
			'smokingStatus'      => array(
				'0' => 'Current daily smoker', '1' => 'Regular smoker', '2' => 'Occasional smoker',
				'3' => 'Recently quit', '4' => 'Former smoker', '5' => 'Never smoked',
			),
			'stressLevels'       => array(
				'0' => 'Very stressed', '1' => 'Often stressed', '2' => 'Sometimes stressed',
				'3' => 'Manageable', '4' => 'Generally relaxed', '5' => 'Very relaxed',
			),
			'dailyHydration'     => array(
				'0' => 'Less than 1 litre', '1' => '1–1.5 litres', '2' => '1.5–2 litres',
				'3' => '2–2.5 litres', '4' => '2.5–3 litres', '5' => '3+ litres',
			),
			'sitToStand'         => array(
				'0' => '0 reps in 30 seconds', '1' => '1–7 reps in 30 seconds', '2' => '8–12 reps in 30 seconds',
				'3' => '13–17 reps in 30 seconds', '4' => '18–24 reps in 30 seconds', '5' => '25+ reps in 30 seconds',
			),
			'breathHold'         => array(
				'0' => 'Held for less than 15 seconds', '1' => 'Held for 15–29 seconds', '2' => 'Held for 30–45 seconds',
				'3' => 'Held for 46–60 seconds', '4' => 'Held for 61–90 seconds', '5' => 'Held for 90+ seconds',
			),
			'balance'            => array(
				'0' => 'Less than 10 seconds on one leg', '1' => '10–19 seconds on one leg', '2' => '20–29 seconds on one leg',
				'3' => '30–39 seconds on one leg', '4' => '40–59 seconds on one leg', '5' => '60+ seconds on one leg',
			),
			'skinElasticity'     => array(
				'0' => 'Skin takes 30+ seconds to return', '1' => 'Skin takes 16–30 seconds to return', '2' => 'Skin takes 10–15 seconds to return',
				'3' => 'Skin takes 5–9 seconds to return', '4' => 'Skin takes 3–4 seconds to return', '5' => 'Skin takes 1–2 seconds to return',
			),
			'dietQuality'        => array(
				'0' => 'Very poor', '1' => 'Poor', '2' => 'Below average',
				'3' => 'Average', '4' => 'Good', '5' => 'Excellent',
			),
			'supplementIntake'   => array(
				'0' => 'Never', '1' => 'Rarely', '2' => 'Sometimes',
				'3' => 'Regularly', '4' => 'Often', '5' => 'Daily',
			),
			'sunlightExposure'   => array(
				'0' => 'Rarely — mostly indoors', '1' => 'Irregular exposure', '2' => 'Midday only',
				'3' => 'Extended midday sun', '4' => 'Morning or evening', '5' => 'Morning and evening daily',
			),
			'cognitiveActivity'  => array(
				'0' => 'Never — don\'t challenge my brain', '1' => 'Rarely', '2' => 'Sometimes',
				'3' => 'Regularly', '4' => 'Often', '5' => 'Daily — consistent practice',
			),
			'socialConnections'  => array(
				'0' => 'None', '1' => 'Rarely', '2' => 'Occasionally',
				'3' => 'Regularly', '4' => 'Often', '5' => 'Daily',
			),
		);
		if ( ! isset( $map[ $field ] ) ) {
			return '';
		}
		$key = (string) (int) $value;
		return $map[ $field ][ $key ] ?? '';
	}

	/** sleepQuality value ('0'–'5') → short descriptor; mirrors the S3_OPTIONS gloss. */
	private static function sleep_quality_short( $value ) {
		if ( ! is_numeric( $value ) ) {
			return '';
		}
		static $q = array(
			'0' => 'never restful', '1' => 'rarely restful', '2' => 'often disrupted',
			'3' => 'moderate quality', '4' => 'mostly restful', '5' => 'consistently restful',
		);
		return $q[ (string) (int) $value ] ?? '';
	}
}
