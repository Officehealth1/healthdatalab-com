<?php
/**
 * Rate-of-Ageing Calculator.
 *
 * Two modes:
 *   - calculate_quick(): Stage 1 — 9 evidence-based questions (silhouette, activity,
 *     VO2 proxy, sit-to-stand, sleep, smoking, social, diet) with age-normed scoring
 *   - calculate_full():  Stage 3 — all 22+ factors with actual measurements
 *
 * Quick mode range: 0.8 (best) to 1.4 (worst), 1.0 = population average.
 * Full mode range:  0.5 to 2.0 (V1-compatible).
 *
 * @package HDL_Longevity_V2
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Rate_Calculator {

    /** Reference score — scores below this age you faster, above age you slower. */
    const REFERENCE = 3.5;

    /** Minimum aging rate (floor) — full calc. */
    const MIN_RATE = 0.5;

    /** Maximum aging rate (ceiling) — full calc. */
    const MAX_RATE = 2.0;

    /** Quick calc bounds. */
    const QUICK_MIN_RATE = 0.8;
    const QUICK_MAX_RATE = 1.4;

    /**
     * Full weights — Stage 3.
     * Correction 3: de-weighted breathHold, skinElasticity, supplementIntake.
     * v0.23.1 — overallHealthScore removed entirely (Matthew 2026-04-28). The
     * 0-100 self-assessment had weak evidence + lowest weight (0.1) and the
     * client-facing question is gone, so the score has no input source. Total
     * sum-of-weights drops from 12.16 → 12.06; per-metric proportional impact
     * is essentially unchanged. Increased strong evidence-based factors.
     */
    const FULL_WEIGHTS = array(
        // Body measurements
        'bmiScore'           => 0.8,
        'whrScore'           => 0.8,
        'whtrScore'          => 0.8,
        'bloodPressureScore' => 1.0,
        'heartRateScore'     => 0.6,
        'skinElasticity'     => 0.08,  // De-weighted (was 0.4) — weak evidence
        // Lifestyle — strong factors carry bulk of weight
        'physicalActivity'   => 1.0,   // Increased (was 0.4)
        'sleepDuration'      => 0.7,   // Increased (was 0.4)
        'sleepQuality'       => 0.7,   // Increased (was 0.4)
        'stressLevels'       => 0.48,
        'socialConnections'  => 0.7,   // Increased (was 0.48)
        'dietQuality'        => 0.48,
        'alcoholConsumption' => 0.4,
        'smokingStatus'      => 1.2,   // Increased (was 0.56) — strongest lifestyle factor
        'cognitiveActivity'  => 0.4,
        'sunlightExposure'   => 0.32,
        'supplementIntake'   => 0.08,  // De-weighted (was 0.32) — weak evidence
        'dailyHydration'     => 0.24,
        // Physical performance
        'sitToStand'         => 0.8,   // Increased (was 0.4)
        'breathHold'         => 0.08,  // De-weighted (was 0.4) — weak evidence
        'balance'            => 0.4,
    );

    /**
     * Quick weights — Stage 1 (9-question algorithm).
     * Q2=1.5, Q3=2.0, Q4=2.0, Q5=2.0, Q6=1.5, Q7=2.5, Q8=1.5, Q9=1.5
     */
    const QUICK_WEIGHTS = array(
        'q2_body'    => 1.5,
        'q3_zone2'   => 2.0,
        'q4_vo2'     => 2.0,
        'q5_sts'     => 2.0,
        'q6_sleep'   => 1.5,
        'q7_smoking' => 2.5,
        'q8_social'  => 1.5,
        'q9_diet'    => 1.5,
    );

    /** Total quick weights sum (for normalisation). */
    const QUICK_WEIGHT_SUM = 14.5;

    /**
     * Q2a silhouette → BMI proxy score (inverted for risk).
     * Sil 1 (underweight)=2, Sil 2 (healthy)=5, Sil 3 (overweight)=3,
     * Sil 4 (obese I)=2, Sil 5 (obese II+)=1.
     */
    const Q2A_SCORES = array( 1 => 2, 2 => 5, 3 => 3, 4 => 2, 5 => 1 );

    /** Q2b fat distribution modifier (subtracted from risk). */
    const Q2B_MODIFIERS = array( 'apple' => 0.5, 'pear' => -0.3, 'even' => 0.0 );

    /**
     * Age norms for physical questions (Q3, Q4, Q5).
     * Maps age bracket → expected raw score. A person at their age norm
     * scores 3 (average); deviations adjust up or down from 3.
     */
    const AGE_NORMS = array(
        'q3_zone2' => array( 29 => 3.8, 39 => 3.5, 49 => 3.0, 59 => 2.5, 69 => 2.0, 79 => 1.8, 999 => 1.5 ),
        'q4_vo2'   => array( 29 => 4.0, 39 => 3.5, 49 => 3.0, 59 => 2.5, 69 => 2.0, 79 => 1.5, 999 => 1.2 ),
        'q5_sts'   => array( 29 => 4.0, 39 => 3.5, 49 => 3.0, 59 => 2.5, 69 => 2.0, 79 => 1.5, 999 => 1.2 ),
    );

    // ──────────────────────────────────────────────────────────────
    //  PUBLIC API
    // ──────────────────────────────────────────────────────────────

    /**
     * Stage 1 quick calculation — 9 evidence-based questions.
     *
     * @param array $answers {
     *   q1_age: int, q1_sex: 'male'|'female',
     *   q2a: int (1-5 silhouette), q2b: 'apple'|'pear'|'even',
     *   q3: 'a'-'e', q4: 'a'-'e', q5: 'a'-'e', q6: 'a'-'e',
     *   q7: 'a'-'e', q8: 'a'-'e', q9: 'a'-'e'
     * }
     * @return array { rate, scores, q1_age, q1_sex }
     */
    public static function calculate_quick( $answers ) {
        $age = max( 1, (int) ( $answers['q1_age'] ?? 0 ) );
        $sex = strtolower( $answers['q1_sex'] ?? 'male' );

        // Score Q2 (body shape combined)
        $q2a_raw = (int) ( $answers['q2a'] ?? 2 );
        $q2a_score = self::Q2A_SCORES[ $q2a_raw ] ?? 3;
        $q2b_val = strtolower( $answers['q2b'] ?? 'even' );
        $q2b_mod = self::Q2B_MODIFIERS[ $q2b_val ] ?? 0.0;
        $q2_score = max( 1.0, min( 5.0, $q2a_score - $q2b_mod ) );

        // Score Q3-Q9 (A=1, B=2, C=3, D=4, E=5; exception: Q6 E=2)
        $letter_map = array( 'a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5 );

        $raw_scores = array(
            'q3_zone2'   => $letter_map[ strtolower( $answers['q3'] ?? 'c' ) ] ?? 3,
            'q4_vo2'     => $letter_map[ strtolower( $answers['q4'] ?? 'c' ) ] ?? 3,
            'q5_sts'     => $letter_map[ strtolower( $answers['q5'] ?? 'c' ) ] ?? 3,
            'q6_sleep'   => strtolower( $answers['q6'] ?? 'c' ) === 'e' ? 2 : ( $letter_map[ strtolower( $answers['q6'] ?? 'c' ) ] ?? 3 ),
            'q7_smoking' => $letter_map[ strtolower( $answers['q7'] ?? 'c' ) ] ?? 3,
            'q8_social'  => $letter_map[ strtolower( $answers['q8'] ?? 'c' ) ] ?? 3,
            'q9_diet'    => $letter_map[ strtolower( $answers['q9'] ?? 'c' ) ] ?? 3,
        );

        // Apply age norms to physical questions (Q3, Q4, Q5)
        $adjusted_scores = array( 'q2_body' => $q2_score );
        foreach ( $raw_scores as $key => $raw ) {
            if ( isset( self::AGE_NORMS[ $key ] ) ) {
                $adjusted_scores[ $key ] = self::apply_age_norm( $raw, $age, self::AGE_NORMS[ $key ] );
            } else {
                $adjusted_scores[ $key ] = (float) $raw;
            }
        }

        // Weighted total
        $total_weighted = 0.0;
        foreach ( self::QUICK_WEIGHTS as $key => $weight ) {
            $total_weighted += ( $adjusted_scores[ $key ] ?? 3.0 ) * $weight;
        }

        // Normalise and convert to pace of ageing
        $max_possible = 5.0 * self::QUICK_WEIGHT_SUM; // 72.5
        $min_possible = 1.0 * self::QUICK_WEIGHT_SUM;  // 14.5
        $normalised = ( $total_weighted - $min_possible ) / ( $max_possible - $min_possible );
        $normalised = max( 0.0, min( 1.0, $normalised ) );

        $rate = 1.4 - ( $normalised * 0.6 );
        $rate = max( self::QUICK_MIN_RATE, min( self::QUICK_MAX_RATE, round( $rate, 2 ) ) );

        return array(
            'rate'    => $rate,
            'q1_age'  => $age,
            'q1_sex'  => $sex,
            'scores'  => $adjusted_scores,
            'raw'     => $raw_scores,
        );
    }

    /**
     * Stage 3 full calculation — all 22+ factors.
     *
     * @param int    $age    Chronological age.
     * @param array  $data   All health data fields from Stage 1 + 3.
     * @param string $gender 'male', 'female', or 'other'.
     * @return array { rate, bio_age, bmi, whr, whtr, scores }
     */
    public static function calculate_full( $age, $data, $gender = 'other' ) {
        $age    = (int) $age;
        $gender = strtolower( $gender ?: 'other' );

        $height = (float) ( $data['height'] ?? 0 );
        $weight = (float) ( $data['weight'] ?? 0 );
        $waist  = (float) ( $data['waist'] ?? 0 );
        $hip    = (float) ( $data['hip'] ?? 0 );

        $bmi  = self::calculate_bmi( $height, $weight );
        $whr  = ( $waist > 0 && $hip > 0 ) ? $waist / $hip : null;
        $whtr = ( $waist > 0 && $height > 0 ) ? $waist / $height : null;

        $systolic  = (int) ( $data['bpSystolic'] ?? 0 );
        $diastolic = (int) ( $data['bpDiastolic'] ?? 0 );
        $heart_rate = (int) ( $data['restingHeartRate'] ?? 0 );
        // v0.23.1 — overallHealthPercent input removed from Stage 3 wizard.

        $scores = array(
            'bmiScore'           => self::get_bmi_score( $bmi ),
            'whrScore'           => self::get_whr_score( $whr, $gender ),
            'whtrScore'          => self::get_whtr_score( $whtr ),
            'bloodPressureScore' => self::get_bp_score( $systolic, $diastolic, $age ),
            'heartRateScore'     => self::get_heart_rate_score( $heart_rate, $age ),
            'skinElasticity'     => self::clamp_score( $data['skinElasticity'] ?? null ),
            'physicalActivity'   => self::clamp_score( $data['physicalActivity'] ?? null ),
            'sleepDuration'      => self::clamp_score( $data['sleepDuration'] ?? null ),
            'sleepQuality'       => self::clamp_score( $data['sleepQuality'] ?? null ),
            'stressLevels'       => self::clamp_score( $data['stressLevels'] ?? null ),
            'socialConnections'  => self::clamp_score( $data['socialConnections'] ?? null ),
            'dietQuality'        => self::clamp_score( $data['dietQuality'] ?? null ),
            'alcoholConsumption' => self::clamp_score( $data['alcoholConsumption'] ?? null ),
            'smokingStatus'      => self::clamp_score( $data['smokingStatus'] ?? null ),
            'cognitiveActivity'  => self::clamp_score( $data['cognitiveActivity'] ?? null ),
            'sunlightExposure'   => self::clamp_score( $data['sunlightExposure'] ?? null ),
            'supplementIntake'   => self::clamp_score( $data['supplementIntake'] ?? null ),
            'dailyHydration'     => self::clamp_score( $data['dailyHydration'] ?? null ),
            'sitToStand'         => self::clamp_score( $data['sitToStand'] ?? null ),
            'breathHold'         => self::clamp_score( $data['breathHold'] ?? null ),
            'balance'            => self::clamp_score( $data['balance'] ?? null ),
        );

        $shift   = self::calculate_age_shift( $scores, $age, self::FULL_WEIGHTS );
        $bio_age = self::calculate_biological_age( $age, $shift );
        $rate    = self::calculate_aging_rate( $bio_age, $age );

        return array(
            'rate'    => round( $rate, 2 ),
            'bio_age' => round( $bio_age, 1 ),
            'bmi'     => $bmi !== null ? round( $bmi, 1 ) : null,
            'whr'     => $whr !== null ? round( $whr, 2 ) : null,
            'whtr'    => $whtr !== null ? round( $whtr, 2 ) : null,
            'scores'  => $scores,
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  BODY MEASUREMENT SCORING
    // ──────────────────────────────────────────────────────────────

    private static function calculate_bmi( $height_cm, $weight_kg ) {
        if ( $height_cm <= 0 || $weight_kg <= 0 ) {
            return null;
        }
        return $weight_kg / pow( $height_cm / 100, 2 );
    }

    /**
     * V1: longevity-form-raw.php:5816-5825
     *
     * v0.23.0 — returns null (not 0) when bmi is missing so calculate_age_shift's
     * is_numeric guard correctly skips the metric instead of penalising the
     * client for ticking "I don't know" on height or weight. Same change applied
     * to whr, whtr, bp, heart-rate scorers below.
     */
    private static function get_bmi_score( $bmi ) {
        if ( $bmi === null || is_nan( (float) $bmi ) ) return null;
        // v0.34.0 — Canonical WHO/NICE adult bands aligned with Page 17
        // methodology rubric. Replaces V1's narrower 20-22 = STRONG band that
        // labelled 23.1 as 4/5 SOLID despite methodology table saying
        // 18.5-24.9 = STRONG. Source: WHO Technical Report Series 894.
        if ( $bmi < 18.5 )  return 1;
        if ( $bmi <= 24.9 ) return 5;
        if ( $bmi <= 26.9 ) return 4;
        if ( $bmi <= 29.9 ) return 3;
        if ( $bmi <= 34.9 ) return 2;
        return 1;
    }

    /** V1: longevity-form-raw.php:5836-5854 — gender-aware. */
    private static function get_whr_score( $whr, $gender ) {
        if ( $whr === null || is_nan( (float) $whr ) ) return null;

        if ( $gender === 'female' ) {
            if ( $whr <= 0.75 ) return 5;
            if ( $whr <= 0.80 ) return 4;
            if ( $whr <= 0.85 ) return 3;
            if ( $whr <= 0.90 ) return 2;
            return 1;
        }

        // Male + other
        if ( $whr <= 0.85 ) return 5;
        if ( $whr <= 0.90 ) return 4;
        if ( $whr <= 0.95 ) return 3;
        if ( $whr <= 1.00 ) return 2;
        return 1;
    }

    /** V1: longevity-form-raw.php:5864-5875 — universal thresholds. */
    private static function get_whtr_score( $whtr ) {
        if ( $whtr === null || is_nan( (float) $whtr ) ) return null;
        if ( $whtr < 0.40 ) return 4;
        if ( $whtr < 0.50 ) return 5;
        if ( $whtr < 0.55 ) return 4;
        if ( $whtr < 0.60 ) return 3;
        if ( $whtr < 0.65 ) return 2;
        if ( $whtr < 0.70 ) return 1;
        return 0;
    }

    // ──────────────────────────────────────────────────────────────
    //  BLOOD PRESSURE SCORING (age-specific)
    // ──────────────────────────────────────────────────────────────

    /**
     * V1: longevity-form-raw.php:5878-5950
     *
     * v0.23.0 — returns null when both systolic and diastolic are missing /
     * out-of-range. 0 inputs were previously interpreted as worst-case score
     * and shifted bio-age by +3.5 yrs (weight 1.0 × (3.5 - 0)). The 0 sentinel
     * is fine for "value 0" because no real human has 0 systolic/diastolic.
     */
    private static function get_bp_score( $systolic, $diastolic, $age ) {
        $sys_score = self::get_systolic_score( $systolic, $age );
        $dia_score = self::get_diastolic_score( $diastolic, $age );

        if ( $sys_score === 0 && $dia_score === 0 ) return null;
        if ( $sys_score === 0 ) return $dia_score;
        if ( $dia_score === 0 ) return $sys_score;

        return min( $sys_score, $dia_score );
    }

    private static function get_systolic_score( $val, $age ) {
        if ( $val <= 0 || $val >= 250 ) return 0;
        // v0.34.0 — Canonical NICE/AHA fixed thresholds (no age-banding).
        // Aligns Page 16 input pill with Page 17 methodology rubric.
        // Source: NICE NG136 (2019), ACC/AHA Hypertension Guideline (2017).
        if ( $val < 120 ) return 5;
        if ( $val < 130 ) return 4;
        if ( $val < 140 ) return 3;
        if ( $val < 160 ) return 2;
        return 1;
    }

    private static function get_diastolic_score( $val, $age ) {
        if ( $val <= 0 || $val >= 150 ) return 0;
        // v0.34.0 — Canonical NICE/AHA fixed thresholds (no age-banding).
        if ( $val < 80 )  return 5;
        if ( $val < 85 )  return 4;
        if ( $val < 90 )  return 3;
        if ( $val < 100 ) return 2;
        return 1;
    }

    // ──────────────────────────────────────────────────────────────
    //  HEART RATE SCORING (age-specific)
    // ──────────────────────────────────────────────────────────────

    /**
     * v0.34.0 — Canonical thresholds aligned with Page 17 methodology table.
     * Replaces V1's age-banded logic (≥50 + HR ≤75 → 5/5 STRONG) which
     * contradicted the displayed methodology rubric. Research-backed
     * (AHA/NICE/Framingham/ARIC): lower RHR universally protective in adults
     * absent pathological bradycardia. Athletic baselines (40-60 bpm) earn
     * 5/5 STRONG. $age parameter kept for signature stability — unused.
     */
    private static function get_heart_rate_score( $hr, $age ) {
        if ( $hr <= 0 ) return null;
        if ( $hr < 60 )  return 5;
        if ( $hr <= 69 ) return 4;
        if ( $hr <= 79 ) return 3;
        if ( $hr <= 89 ) return 2;
        return 1;
    }

    // ──────────────────────────────────────────────────────────────
    //  CORE CALCULATION
    // ──────────────────────────────────────────────────────────────
    // v0.23.1 — get_overall_health_score() removed (Matthew 2026-04-28).
    // The corresponding Stage 3 question + FULL_WEIGHTS entry + score key
    // were all removed in the same release.

    /**
     * Calculate age shift from scores.
     * V1: longevity-form-raw.php:5992-6054
     *
     * @param array $scores  Metric name => score value.
     * @param int   $age     Chronological age.
     * @param array $weights Which weights to use (QUICK_WEIGHTS or FULL_WEIGHTS).
     * @return float Age shift in years (positive = older, negative = younger).
     */
    private static function calculate_age_shift( $scores, $age, $weights ) {
        $total_shift = 0.0;

        foreach ( $weights as $metric => $weight ) {
            if ( ! isset( $scores[ $metric ] ) ) continue;
            $score = $scores[ $metric ];
            if ( ! is_numeric( $score ) ) continue;

            $total_shift += $weight * ( self::REFERENCE - (float) $score );
        }

        // Age-based modulation — order matters, only ONE branch applies
        if ( $age < 25 && $total_shift < 0 ) {
            $total_shift = max( $total_shift * 0.3, -( $age * 0.2 ) );
        } elseif ( $age < 35 ) {
            $total_shift *= 0.5;
        } elseif ( $age >= 95 ) {
            $total_shift *= 0.2;
        } elseif ( $age >= 90 ) {
            $total_shift *= 0.4;
        } elseif ( $age >= 80 ) {
            $total_shift *= 0.6;
        } elseif ( $age > 65 ) {
            $total_shift *= 0.7;
        }
        // Ages 35-65: no modulation (1.0x)

        return $total_shift;
    }

    /**
     * Calculate bounded biological age.
     * V1: longevity-form-raw.php:6064-6103
     */
    private static function calculate_biological_age( $chrono_age, $shift ) {
        if ( $chrono_age <= 0 ) return $chrono_age;

        $bio_age = $chrono_age + $shift;

        // Minimum bounds
        if ( $chrono_age >= 95 ) {
            $min_age = $chrono_age - 5;
        } elseif ( $chrono_age >= 90 ) {
            $min_age = $chrono_age - 8;
        } elseif ( $chrono_age >= 80 ) {
            $min_age = $chrono_age - 12;
        } elseif ( $chrono_age >= 65 ) {
            $min_age = $chrono_age - 15;
        } else {
            $min_age = 18;
        }

        // Maximum bounds
        $max_age = $chrono_age >= 90 ? $chrono_age + 8 : $chrono_age + 15;

        return max( $min_age, min( $max_age, $bio_age ) );
    }

    /**
     * Calculate clamped aging rate.
     * V1: longevity-form-raw.php:6115-6135
     */
    private static function calculate_aging_rate( $bio_age, $chrono_age ) {
        if ( $chrono_age <= 0 ) return 1.0;

        $rate = $bio_age / $chrono_age;

        return max( self::MIN_RATE, min( self::MAX_RATE, $rate ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  HELPERS
    // ──────────────────────────────────────────────────────────────

    /**
     * Clamp a dropdown score to 0-5 range, or null if not provided.
     *
     * v0.23.0 — defensive: also treat 'skip' as null. Callers (rest_complete_stage,
     * rest_load_consultation, final-report::generate, client-draft-view::rest_get_draft)
     * already normalise 'skip' → null before reaching here, but this is the last
     * line of defence — a future caller that forgets to normalise won't silently
     * score 'skip' as 0 (which (float)'skip' would have produced).
     */
    private static function clamp_score( $value ) {
        if ( $value === null || $value === '' || $value === 'skip' ) return null;
        $v = (float) $value;
        return max( 0, min( 5, $v ) );
    }

    /**
     * Apply age-norming to a raw score for physical questions.
     *
     * The age norm represents the expected score for a person of that age.
     * A person scoring at their age norm gets 3.0 (average).
     * Deviations from the norm shift the adjusted score above or below 3.
     *
     * Example: 70-year-old scores C (3) on stairs. Age norm for 70 = 2.0.
     *   adjusted = 3 + (3 - 2.0) = 4.0 — above age norm, good score.
     * Example: 30-year-old scores C (3) on stairs. Age norm for 30 = 3.5.
     *   adjusted = 3 + (3 - 3.5) = 2.5 — below age norm, below average.
     *
     * @param float $raw_score  Raw answer score (1-5).
     * @param int   $age        Chronological age.
     * @param array $norms      Age bracket => expected score (keys are upper bounds).
     * @return float Adjusted score, clamped to 1-5.
     */
    private static function apply_age_norm( $raw_score, $age, $norms ) {
        $expected = 3.0; // fallback
        foreach ( $norms as $upper_age => $norm_score ) {
            if ( $age <= $upper_age ) {
                $expected = $norm_score;
                break;
            }
        }
        $adjusted = 3.0 + ( (float) $raw_score - $expected );
        return max( 1.0, min( 5.0, $adjusted ) );
    }

    /**
     * v0.41.36 — Metabolic-health signal (DERIVED SURROGATE — AUDIT Action Point 1).
     *
     * DISPLAY-ONLY. This product collects NO blood markers — there is no
     * insulin, glucose, HbA1c, CRP, lipid or HOMA measurement anywhere (see the
     * data whitelist in class-hdlv2-ai-service.php). This index is an ESTIMATE
     * inferred from existing lifestyle + body-shape /5 scores, NEVER a
     * measurement; the UI must label it "Inferred · no bloods". It does NOT feed
     * biological age, the ageing rate, the radar, or FULL_WEIGHTS — it is a
     * separate, read-only composite that alters no existing figure.
     *
     * Forward-compat (AUDIT B.6 — design for, do not build): adding a weighted
     * input later (gum bleeding, post-meal energy/cravings, family T2D history,
     * ethnicity) is a one-line addition to the $weights + $labels config below.
     *
     * @param array $scores HDLV2_Rate_Calculator::calculate_full()['scores']
     *                      (0-5 floats; null = the input was never collected).
     * @param array $calc   The full calc_result, for raw whr/whtr/bmi driver values.
     * @return array {
     *   show: bool, score: float|null, score_display: string ("3.0"),
     *   band: 'good'|'watch'|'elevated',
     *   band_label: string, marker_pct: int, present_count: int,
     *   driver: {key,label,value}|null, driver_note: string,
     *   chips: array<{label,score}>
     * }
     */
    public static function metabolic_signal( $scores, $calc = array() ) {
        // ── CONFIG (PENDING MATTHEW SIGN-OFF — do not treat as final) ──────────
        // Contributing /5 inputs → display weight. NEW and SEPARATE from
        // FULL_WEIGHTS; changing these never affects bio-age or the ageing rate.
        $weights = array(
            'whtrScore'          => 3, // waist-to-height — strongest single IR proxy
            'whrScore'           => 3, // waist-to-hip
            'bloodPressureScore' => 2,
            'dietQuality'        => 2,
            'physicalActivity'   => 2,
            'bmiScore'           => 1,
        );
        // Band thresholds on the 0-5 composite (higher = healthier).
        $band_watch_min = 2.5; // composite < this  → elevated
        $band_good_min  = 3.5; // composite >= this → good
        // Human labels + the lead word used in the one-line driver note.
        $labels = array(
            'whtrScore'          => 'Waist-to-height',
            'whrScore'           => 'Waist-to-hip',
            'bloodPressureScore' => 'Blood pressure',
            'dietQuality'        => 'Diet',
            'physicalActivity'   => 'Activity',
            'bmiScore'           => 'BMI',
        );
        $band_lead   = array( 'good' => 'Solid', 'watch' => 'Moderate', 'elevated' => 'Elevated' );
        $band_labels = array( 'good' => 'Good', 'watch' => 'Watch', 'elevated' => 'Elevated' );
        // Raw anthropometric value to show for these driver keys (else "N/5").
        $raw_map     = array( 'whtrScore' => 'whtr', 'whrScore' => 'whr', 'bmiScore' => 'bmi' );
        $min_inputs  = 3; // fewer present → hide the panel entirely
        // Chip display order (reference design): anthropometrics, then BP, then lifestyle.
        $chip_order  = array( 'whtrScore', 'whrScore', 'bmiScore', 'bloodPressureScore', 'dietQuality', 'physicalActivity' );
        // ──────────────────────────────────────────────────────────────────────

        $hidden = array(
            'show' => false, 'score' => null, 'band' => '', 'band_label' => '',
            'marker_pct' => 0, 'present_count' => 0, 'driver' => null,
            'driver_note' => '', 'chips' => array(),
        );
        if ( ! is_array( $scores ) ) {
            return $hidden;
        }

        $sum_weight = 0.0;
        $sum_score  = 0.0;
        $present    = array();
        $chips      = array();
        foreach ( $weights as $key => $weight ) {
            $value = $scores[ $key ] ?? null;
            if ( ! is_numeric( $value ) ) {
                continue; // null/''/'skip' = never collected — drop it (note: 0 IS valid)
            }
            $value        = (float) $value;
            $sum_weight  += $weight;
            $sum_score   += $value * $weight;
            $present[ $key ] = $value;
        }

        $count = count( $present );
        if ( $count < $min_inputs || $sum_weight <= 0 ) {
            return $hidden;
        }

        // Single source of truth: clamp once, then band, marker AND the printed
        // number all derive from the SAME 1-dp value, so the displayed score can
        // never contradict its own band / gauge colour (and a future weighted
        // input can never print "6 / 5").
        $score      = max( 0.0, min( 5.0, $sum_score / $sum_weight ) ); // 0-5, clamped
        $display    = round( $score, 1 );
        $band       = ( $display >= $band_good_min ) ? 'good' : ( ( $display >= $band_watch_min ) ? 'watch' : 'elevated' );
        // Green-left axis: healthy (high score) sits at the LEFT, so the marker's
        // distance from the left grows as the score drops — (5 - score)/5 * 100.
        $marker_pct = (int) round( ( 5.0 - $display ) / 5 * 100 );

        // Driver = lowest-scoring present input. Tie-break: higher weight wins;
        // on an exact tie, config order wins (first-declared stays — deterministic).
        $driver_key    = null;
        $driver_value  = null;
        $driver_weight = -1;
        foreach ( $weights as $key => $weight ) {
            if ( ! isset( $present[ $key ] ) ) {
                continue;
            }
            $sv = $present[ $key ];
            if ( $driver_key === null || $sv < $driver_value || ( $sv == $driver_value && $weight > $driver_weight ) ) {
                $driver_key    = $key;
                $driver_value  = $sv;
                $driver_weight = $weight;
            }
        }
        if ( isset( $raw_map[ $driver_key ], $calc[ $raw_map[ $driver_key ] ] ) && is_numeric( $calc[ $raw_map[ $driver_key ] ] ) ) {
            $driver_display = (string) $calc[ $raw_map[ $driver_key ] ];
        } else {
            $driver_display = ( (int) round( $driver_value ) ) . '/5';
        }
        $driver      = array( 'key' => $driver_key, 'label' => $labels[ $driver_key ], 'value' => $driver_display );
        // A 'good' result has no drag to call out — saying one contradicts the
        // score. (Copy pending Matthew sign-off; see METABOLIC-AUDIT B4.)
        $driver_note = ( 'good' === $band )
            ? 'Solid — every tracked input is in a healthy range.'
            : $band_lead[ $band ] . ' — ' . strtolower( $labels[ $driver_key ] ) . ' ' . $driver_display . ' is the single drag pulling this down.';

        // Contributing-input chips in the reference display order.
        foreach ( $chip_order as $key ) {
            if ( ! isset( $present[ $key ] ) ) {
                continue;
            }
            $chips[] = array( 'label' => $labels[ $key ], 'score' => round( $present[ $key ], 1 ) );
        }

        return array(
            'show'          => true,
            'score'         => $display,
            'score_display' => number_format( $display, 1 ), // "3.0" — shared by web + PDF so both print the same number
            'band'          => $band,
            'band_label'    => $band_labels[ $band ],
            'marker_pct'    => $marker_pct,
            'present_count' => $count,
            'driver'        => $driver,
            'driver_note'   => $driver_note,
            'chips'         => $chips,
        );
    }
}
