<?php
/**
 * Stage 1 Result Commentary — deterministic 5-paragraph builder.
 *
 * Replaces HDLV2_AI_Service::generate_stage1_commentary() (Haiku 4.5)
 * with templated static text. Same input contract, same output shape,
 * drop-in at the REST endpoint dispatch site.
 *
 * Why static (v0.22.10):
 *   - $0 cost vs ~$0.0005/run × every Stage 1 submission
 *   - 0ms vs 2-5s — Stage 1 result page renders instantly
 *   - Deterministic: every client with the same 9 answers sees the
 *     exact reviewed copy. Easier QA, easier compliance, easier
 *     practitioner trust.
 *   - No JSON parse failures, no API outages, no prompt-rule drift.
 *
 * Tone: Haiku-style, softer/neutral. Direct but not catastrophic.
 * British English. Second person.
 *
 * Architecture:
 *   - 5 rate bands → P1 (headline)
 *   - Picker scores all 9 answers, selects highest (P2 strength) and
 *     two lowest (P3, P4 priorities). Tie-break by clinical priority.
 *   - Static block libraries for each (q,a) → topic + body + lead variants
 *   - All-strong / all-weak / no-data fallbacks so the page never blanks
 *
 * @package HDL_Longevity_V2
 * @since 0.22.10
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Stage1_Commentary {

    /**
     * Map of question answer (a-e) to strength score (1-5).
     * Higher = better/stronger habit. q6 'e' (9+ hrs but tired) is a
     * paradoxical signal — treated as a weak factor, not strong.
     */
    const SCORES = array(
        'q3' => array( 'a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5 ),
        'q4' => array( 'a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5 ),
        'q5' => array( 'a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5 ),
        'q6' => array( 'a' => 1, 'b' => 2, 'c' => 3, 'd' => 5, 'e' => 2 ),
        'q7' => array( 'a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5 ),
        'q8' => array( 'a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5 ),
        'q9' => array( 'a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5 ),
    );

    /**
     * Tie-break order when multiple answers share the same score.
     * Earlier in the array = picked first. Order roughly tracks
     * effect-size on biological age in the longevity literature.
     */
    const PRIORITY = array( 'q6', 'q7', 'q9', 'q3', 'q2a', 'q4', 'q5', 'q8' );

    /**
     * Build the full 5-paragraph commentary as one HTML string.
     *
     * @param array  $stage1_data  Raw stage1 form data (q1_age, q1_sex, q2a..q9, etc.).
     * @param array  $result       Output of HDLV2_Rate_Calculator::calculate_quick (rate, ...).
     * @param string $client_name  Full name (first name extracted internally) or empty.
     * @return string              HTML string of <p> paragraphs (always non-empty).
     */
    public static function build( $stage1_data, $result, $client_name = '' ) {
        if ( ! is_array( $stage1_data ) ) $stage1_data = array();
        if ( ! is_array( $result ) )      $result      = array();

        $age  = (int) ( $stage1_data['q1_age'] ?? 0 );
        $sex  = strtolower( (string) ( $stage1_data['q1_sex'] ?? '' ) );
        $rate = (float) ( $result['rate'] ?? 1.0 );

        $first_name = '';
        if ( $client_name ) {
            $first_name = strtok( trim( (string) $client_name ), ' ' );
        }

        list( $strongest, $weakest, $second_weak ) = self::pick_factors( $stage1_data );

        $p1 = self::p1_headline( $first_name, $age, $sex, $rate );
        $p2 = self::p2_strength( $strongest );
        $p3 = self::p3_p4_priority( $weakest, 'p3' );
        $p4 = self::p3_p4_priority( $second_weak, 'p4' );
        $p5 = self::p5_whats_next();

        return $p1 . $p2 . $p3 . $p4 . $p5;
    }

    // ──────────────────────────────────────────────────────────────
    //  PARAGRAPH 1 — HEADLINE (rate band)
    // ──────────────────────────────────────────────────────────────

    private static function p1_headline( $first_name, $age, $sex, $rate ) {
        $bio_age = $age > 0 ? round( $rate * $age, 1 ) : null;
        $abs_gap = ( $bio_age !== null ) ? abs( round( $bio_age - $age, 1 ) ) : null;

        $gap_phrase = '';
        if ( $abs_gap !== null && $abs_gap >= 0.5 ) {
            $rounded   = (int) round( $abs_gap );
            $gap_phrase = $rounded === 1 ? 'about a year' : sprintf( 'about %d years', $rounded );
        }

        $name_open = $first_name
            ? sprintf( '%s, your assessment', esc_html( $first_name ) )
            : 'Your assessment';

        $sex_phrase = '';
        if ( $age > 0 && ( $sex === 'male' || $sex === 'female' ) ) {
            $sex_phrase = sprintf( ' for a %d-year-old %s', $age, esc_html( $sex ) );
        } elseif ( $age > 0 ) {
            $sex_phrase = sprintf( ' for someone aged %d', $age );
        }

        $bio_phrase = $bio_age !== null
            ? sprintf( 'biological age around %s versus chronological %d', $bio_age, $age )
            : 'rate-of-ageing in line with the population baseline';

        // 5 rate bands, soft/neutral tone per the brief.
        if ( $rate <= 0.85 ) {
            return sprintf(
                '<p><strong>%s puts you well ahead of the average pace%s — %s%s.</strong> '
                . 'That is a strong starting position. The work from here is more about protecting it than chasing it.</p>',
                $name_open, $sex_phrase, $bio_phrase,
                $gap_phrase ? sprintf( ', a gap of %s in your favour', $gap_phrase ) : ''
            );
        }

        if ( $rate <= 0.95 ) {
            return sprintf(
                '<p><strong>%s puts you a step ahead of the average pace%s — %s%s.</strong> '
                . 'That is a healthy starting position. The focus from here is consistency on what is already working.</p>',
                $name_open, $sex_phrase, $bio_phrase,
                $gap_phrase ? sprintf( ', a gap of %s in your favour', $gap_phrase ) : ''
            );
        }

        if ( $rate <= 1.05 ) {
            return sprintf(
                '<p><strong>%s puts you close to the average pace%s — %s, broadly in line with your chronological age.</strong> '
                . 'Average is not bad — it is the population baseline. It is also the band where small consistent changes shift the trajectory the most over the next decade.</p>',
                $name_open, $sex_phrase, $bio_phrase
            );
        }

        if ( $rate <= 1.15 ) {
            return sprintf(
                '<p><strong>%s puts you just above the average pace%s — %s%s.</strong> '
                . 'It is not catastrophic, but this is the band where the choices made over the next few years compound the most.</p>',
                $name_open, $sex_phrase, $bio_phrase,
                $gap_phrase ? sprintf( ', a gap of %s', $gap_phrase ) : ''
            );
        }

        // > 1.15 — "significantly faster" band
        return sprintf(
            '<p><strong>%s puts you in the higher-pace band%s — %s%s.</strong> '
            . 'The good news in that number is that most of what is driving it is modifiable. This is the kind of profile where committed change tends to show up quickly.</p>',
            $name_open, $sex_phrase, $bio_phrase,
            $gap_phrase ? sprintf( ', a gap of %s', $gap_phrase ) : ''
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  PARAGRAPH 2 — STRONGEST FACTOR
    // ──────────────────────────────────────────────────────────────

    private static function p2_strength( $strongest ) {
        if ( ! $strongest ) {
            return '<p><strong>There is no standout strength in your current 9-answer data.</strong> '
                . 'That is actually useful information — it means every change you make has somewhere to land. '
                . 'This is the kind of starting point where two or three modifiable factors tend to do most of the work.</p>';
        }

        $library = self::strength_library();
        $key     = $strongest['q'] . '_' . $strongest['a'];

        if ( isset( $library[ $key ] ) ) {
            return '<p>' . $library[ $key ] . '</p>';
        }

        // Generic positive framing if no specific template (defensive — shouldn't fire in normal data)
        return '<p><strong>You have at least one factor working in your favour.</strong> '
            . 'That is the foundation the rest of the picture builds from.</p>';
    }

    private static function strength_library() {
        return array(
            'q3_d' => '<strong>Your zone-2 cardio is one of the strongest pieces of your data.</strong> '
                . '2&ndash;4 hours per week is right in the range that drives mitochondrial density, insulin sensitivity, and cardiovascular resilience &mdash; one of the biggest predictors of healthspan in the literature.',
            'q3_e' => '<strong>Your zone-2 cardio is exceptional.</strong> '
                . '4+ hours per week puts you in the top tier of the population. That single habit drives mitochondrial density, insulin sensitivity, and cardiovascular resilience &mdash; and shows up in nearly every healthspan study.',
            'q4_d' => '<strong>Your cardiovascular capacity is excellent.</strong> '
                . 'Comfortable on 5+ flights of stairs, or jogging them, tracks closely with VO&#8322;max &mdash; one of the strongest single predictors of all-cause mortality.',
            'q4_e' => '<strong>Your cardiovascular capacity is in the top tier.</strong> '
                . 'Running up 4&ndash;5 flights without difficulty puts you in the upper percentiles for VO&#8322;max, which is one of the strongest single predictors of all-cause mortality.',
            'q5_d' => '<strong>Your functional strength is solid.</strong> '
                . 'Rising from cross-legged unaided is a meaningful marker &mdash; it correlates with overall mobility, balance, and lower-body strength reserves later in life.',
            'q5_e' => '<strong>Your functional strength is excellent.</strong> '
                . 'Rising smoothly from cross-legged with no support is the highest marker on this scale and correlates with mobility, balance, and fall risk later in life.',
            'q6_d' => '<strong>Your sleep is one of the strongest pieces of your data.</strong> '
                . '7&ndash;8 hours of quality sleep is when your body repairs DNA damage, consolidates memory, and clears metabolic waste. Getting it right is more impactful than nearly any supplement on the market.',
            'q7_d' => '<strong>Your smoking history is in your favour.</strong> '
                . 'Quitting more than 5 years ago has restored most of the cardiovascular risk to baseline. This is one of the strongest reversible factors in the longevity literature, and you have already done the hard work.',
            'q7_e' => '<strong>You have never smoked.</strong> '
                . 'That is the single largest avoided risk factor in the longevity literature &mdash; many of the largest gains in healthspan come from not having to undo this damage in the first place.',
            'q8_d' => '<strong>Your social connection is a real strength.</strong> '
                . 'Several meaningful interactions per week. Loneliness is one of the most underrated drivers of accelerated ageing &mdash; and you are protected from it.',
            'q8_e' => '<strong>Your social connection is exceptional.</strong> '
                . 'Daily strong relationships put you firmly on the right side of one of the most underrated drivers of accelerated ageing.',
            'q9_d' => '<strong>Your diet is one of the strongest pieces of your data.</strong> '
                . 'Mostly whole foods with plenty of vegetables drives nearly every metabolic and inflammatory marker &mdash; yours is built on a solid base.',
            'q9_e' => '<strong>Your diet is excellent.</strong> '
                . 'Very clean, diverse, minimal processed food drives much of what controls inflammation, gut health, and blood sugar stability &mdash; the foundation of nearly everything else.',
            'q2a_1' => '<strong>Your body composition is excellent.</strong> '
                . 'A lean profile puts you on the protective side of one of the most reliable drivers of accelerated ageing &mdash; body fat, particularly visceral fat.',
            'q2a_2' => '<strong>Your body composition is healthy.</strong> '
                . 'A lean-average profile puts you on the protective side of one of the most reliable drivers of accelerated ageing &mdash; body fat distribution.',
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  PARAGRAPH 3 + 4 — WEAKEST AND SECOND-WEAKEST PRIORITY
    // ──────────────────────────────────────────────────────────────

    private static function p3_p4_priority( $factor, $slot ) {
        // Fallback when no factor exists in this slot.
        if ( ! $factor ) {
            if ( $slot === 'p4' ) {
                return '<p><strong>Beyond that, no other factor in your data is actively dragging your rate down.</strong> '
                    . 'The next decade of your trajectory will be shaped by consistency on what you have already noticed and not letting other levers slip.</p>';
            }
            // P3 fallback — no weak factors at all (rare, all-strong profile)
            return '<p><strong>There is no obvious weak link in your 9-answer data.</strong> '
                . 'Your trajectory will be shaped less by any single factor and more by consistency, chronic stress, and environmental load over years. The watch-out is complacency &mdash; slowing down on what is working.</p>';
        }

        $library = self::priority_library();
        $key     = $factor['q'] . '_' . $factor['a'];

        if ( ! isset( $library[ $key ] ) ) {
            // Defensive — shouldn't fire if pick_factors stays in sync with the library
            return '<p>One of your answers points to a focus area worth attention with your practitioner in Stage 3.</p>';
        }

        $entry = $library[ $key ];
        $lead  = $slot === 'p3' ? $entry['p3'] : $entry['p4'];
        return sprintf( '<p><strong>%s %s.</strong> %s</p>', $entry['topic'], $lead, $entry['body'] );
    }

    private static function priority_library() {
        return array(
            'q6_a' => array(
                'topic' => 'Sleep',
                'p3'    => 'is the most damaging single factor in your data',
                'p4'    => 'is also a significant priority',
                'body'  => 'Fewer than 5 hours most nights is when DNA repair, metabolic clearance, and memory consolidation all suffer. Restoring sleep &mdash; even by an hour or two &mdash; has more impact on your trajectory than nearly any supplement or diet shift.',
            ),
            'q6_b' => array(
                'topic' => 'Sleep',
                'p3'    => 'is one of the highest priorities in your data',
                'p4'    => 'is also a meaningful priority',
                'body'  => '5&ndash;6 hours with frequent waking puts your repair systems under chronic strain. Sleep is when DNA damage gets repaired and metabolic waste gets cleared &mdash; even modest improvements compound quickly.',
            ),
            'q6_e' => array(
                'topic' => 'Sleep',
                'p3'    => 'is a flag in your data',
                'p4'    => 'is also worth attention',
                'body'  => '9+ hours but still tired is a paradoxical pattern that often points to fragmented sleep architecture rather than insufficient duration. This is worth investigating with your practitioner &mdash; sleep apnoea, hormonal imbalance, and depression all produce this signature.',
            ),
            'q7_a' => array(
                'topic' => 'Smoking',
                'p3'    => 'is the single largest modifiable risk factor in your assessment',
                'p4'    => 'is also a major priority',
                'body'  => 'Every cardiovascular, lung, metabolic, and skin marker improves measurably within weeks of stopping. Modern cessation support &mdash; NRT, varenicline, structured tapers &mdash; has made this far more tractable than it used to be.',
            ),
            'q7_b' => array(
                'topic' => 'Smoking',
                'p3'    => 'is a priority',
                'p4'    => 'is also a priority',
                'body'  => 'Even occasional smoking causes measurable cardiovascular and lung damage. The good news is that occasional use is closer to stopping than daily use &mdash; and the markers improve quickly once you do.',
            ),
            'q7_c' => array(
                'topic' => 'Recently quitting smoking',
                'p3'    => 'is a watch-out',
                'p4'    => 'is also worth attention',
                'body'  => 'Most of the cardiovascular risk falls to near-baseline within 5 years, and you are on that trajectory. The remaining work is staying clear of relapse triggers &mdash; statistically harder in the first 2 years than after.',
            ),
            'q9_a' => array(
                'topic' => 'Diet',
                'p3'    => 'is the highest-leverage area in your data',
                'p4'    => 'is also a major focus area',
                'body'  => 'Mostly processed and convenience food drives inflammation, blood sugar instability, and gut imbalances &mdash; three of the strongest drivers of biological-age acceleration. The single biggest move is volume of vegetables and minimally-processed protein.',
            ),
            'q9_b' => array(
                'topic' => 'Diet',
                'p3'    => 'is one of the priority areas',
                'p4'    => 'is also a focus area',
                'body'  => 'A mix of healthy and convenience food is common and improvable. The biggest single shift is increasing vegetable volume and reducing processed sugar &mdash; those two move more biomarkers than any specific dietary protocol.',
            ),
            'q3_a' => array(
                'topic' => 'Zone-2 cardio',
                'p3'    => 'is the most actionable lever in your data',
                'p4'    => 'is also a major priority',
                'body'  => 'Zone-2 &mdash; the conversational pulse where you can still talk in full sentences &mdash; is the single most efficient driver of mitochondrial density and metabolic health. Even 2&ndash;3 sessions of 30 minutes per week shifts the picture.',
            ),
            'q3_b' => array(
                'topic' => 'Zone-2 cardio',
                'p3'    => 'is one of the priority areas',
                'p4'    => 'is also a focus area',
                'body'  => '30&ndash;60 minutes per week is below the threshold where it starts driving meaningful change. Aerobic conditioning is the single most efficient lever for metabolic health and mitochondrial density.',
            ),
            'q4_a' => array(
                'topic' => 'Cardiovascular capacity',
                'p3'    => 'is a priority',
                'p4'    => 'is also a focus area',
                'body'  => 'One flight of stairs being difficult tracks with low VO&#8322;max &mdash; one of the strongest single predictors of mortality. Building from here does not take heroics: consistent walking and gradual stair work move the needle within weeks.',
            ),
            'q4_b' => array(
                'topic' => 'Cardiovascular capacity',
                'p3'    => 'is one of the priority areas',
                'p4'    => 'is also a focus area',
                'body'  => 'Getting breathless on 2&ndash;3 flights tracks with below-average VO&#8322;max. The good news: this is one of the most responsive markers &mdash; small consistent additions to walking pace and stair work shift it within weeks.',
            ),
            'q5_a' => array(
                'topic' => 'Functional strength',
                'p3'    => 'is a priority',
                'p4'    => 'is also a focus area',
                'body'  => 'Not being able to rise from cross-legged unaided indicates lower-body strength loss that compounds with age. Reversing it requires deliberate strength work &mdash; bodyweight squats, sit-to-stand reps, glute work &mdash; but the trajectory shifts within weeks.',
            ),
            'q5_b' => array(
                'topic' => 'Functional strength',
                'p3'    => 'is one of the priority areas',
                'p4'    => 'is also a focus area',
                'body'  => 'Needing furniture or both hands to rise indicates lower-body strength reserves that need rebuilding. Bodyweight squats and sit-to-stand reps will shift this within weeks.',
            ),
            'q8_a' => array(
                'topic' => 'Social connection',
                'p3'    => 'is a priority',
                'p4'    => 'is also a focus area',
                'body'  => 'Rarely meaningful contact compounds the stress load on your body and is one of the most underdiscussed accelerators of ageing. Loneliness shows up in inflammation markers, sleep quality, and cognitive decline.',
            ),
            'q8_b' => array(
                'topic' => 'Social connection',
                'p3'    => 'is one of the priority areas',
                'p4'    => 'is also a focus area',
                'body'  => '1&ndash;2 meaningful interactions per month is below the threshold where it starts protecting against the inflammation and stress load that drives accelerated ageing.',
            ),
            'q2a_4' => array(
                'topic' => 'Body composition',
                'p3'    => 'is one of the priority areas',
                'p4'    => 'is also a focus area',
                'body'  => 'Above-average body fat &mdash; particularly around the waist &mdash; is one of the most reliable drivers of metabolic and cardiovascular acceleration. Sleep, cardio, and diet quality all feed into this; it tends to shift as those other levers move.',
            ),
            'q2a_5' => array(
                'topic' => 'Body composition',
                'p3'    => 'is the priority area',
                'p4'    => 'is also a major priority',
                'body'  => 'High body fat is the single most reliable driver of metabolic and cardiovascular acceleration. The good news is that this marker is highly responsive &mdash; small consistent changes to sleep, movement, and diet quality move it more reliably than any specific weight-loss protocol.',
            ),
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  PARAGRAPH 5 — STATIC REASSURANCE / WHAT'S NEXT
    // ──────────────────────────────────────────────────────────────

    private static function p5_whats_next() {
        return '<p>This is a 9-question snapshot, not a verdict. Most of what shapes your rate is within your control, '
            . 'and rates can shift inside months when the right levers move. Stage 2 of your assessment captures '
            . 'your <em>why</em> &mdash; the people, fears, and motivations that turn knowledge into action. Stage 3 turns '
            . 'it into a personalised longevity plan with your practitioner. The number you saw today is where you start.</p>';
    }

    // ──────────────────────────────────────────────────────────────
    //  PICKER — strongest, weakest, second-weakest
    // ──────────────────────────────────────────────────────────────

    /**
     * Score every answered question, return [strongest, weakest, second_weakest].
     * Each return is null if no candidate qualifies for that slot, or
     * { q: 'q3', a: 'b', score: 2, priority: <int> }.
     */
    private static function pick_factors( $stage1_data ) {
        $items = array();

        // a-e questions q3..q9
        foreach ( array( 'q3', 'q4', 'q5', 'q6', 'q7', 'q8', 'q9' ) as $q ) {
            $a = strtolower( (string) ( $stage1_data[ $q ] ?? '' ) );
            if ( $a === '' || ! isset( self::SCORES[ $q ][ $a ] ) ) {
                continue;
            }
            $items[] = array(
                'q'        => $q,
                'a'        => $a,
                'score'    => self::SCORES[ $q ][ $a ],
                'priority' => self::priority_idx( $q ),
            );
        }

        // q2a body silhouette: 1-5 numeric (1=lean best, 5=high body fat worst).
        // Invert to strength scale: 1->5, 2->4, 3->3, 4->2, 5->1.
        $q2a_val = (int) ( $stage1_data['q2a'] ?? 0 );
        if ( $q2a_val >= 1 && $q2a_val <= 5 ) {
            $items[] = array(
                'q'        => 'q2a',
                'a'        => (string) $q2a_val,
                'score'    => 6 - $q2a_val,
                'priority' => self::priority_idx( 'q2a' ),
            );
        }

        // Strongest: highest score, but only if score >= 4.
        // Tie-break by priority (lower idx = higher clinical priority).
        $strongest = null;
        foreach ( $items as $it ) {
            if ( $it['score'] < 4 ) continue;
            if ( ! $strongest
                 || $it['score'] > $strongest['score']
                 || ( $it['score'] === $strongest['score'] && $it['priority'] < $strongest['priority'] ) ) {
                $strongest = $it;
            }
        }

        // Weakest: lowest score, but only if score <= 2.
        $weakest = null;
        foreach ( $items as $it ) {
            if ( $it['score'] > 2 ) continue;
            if ( ! $weakest
                 || $it['score'] < $weakest['score']
                 || ( $it['score'] === $weakest['score'] && $it['priority'] < $weakest['priority'] ) ) {
                $weakest = $it;
            }
        }

        // Second-weakest: same logic but exclude the chosen weakest.
        $second_weak = null;
        foreach ( $items as $it ) {
            if ( $it['score'] > 2 ) continue;
            if ( $weakest && $it['q'] === $weakest['q'] ) continue;
            if ( ! $second_weak
                 || $it['score'] < $second_weak['score']
                 || ( $it['score'] === $second_weak['score'] && $it['priority'] < $second_weak['priority'] ) ) {
                $second_weak = $it;
            }
        }

        return array( $strongest, $weakest, $second_weak );
    }

    /**
     * Lookup priority index for a question key. Returns a high number
     * for unknown keys so they sort last rather than first (false from
     * array_search would compare as 0 and out-rank everything).
     */
    private static function priority_idx( $q ) {
        $idx = array_search( $q, self::PRIORITY, true );
        return $idx === false ? 999 : (int) $idx;
    }

    // ──────────────────────────────────────────────────────────────
    //  STRUCTURED OUTPUT — for the Stage 1 PDF webhook (v0.30.0)
    //
    //  The web result page consumes build() (HTML blob). The PDF
    //  template needs the same data split into discrete fields so it
    //  can place each piece in its own card. Same scoring, same
    //  libraries, no AI — just a different shape.
    // ──────────────────────────────────────────────────────────────

    /**
     * Map of question key → short topic label for card titles. Kept
     * separate from the priority_library entries (which use longer,
     * answer-specific phrasings) because card headers want a clean
     * noun phrase regardless of which answer was picked.
     */
    const TOPIC_LABELS = array(
        'q2a' => 'Body composition',
        'q3'  => 'Zone 2 cardio',
        'q4'  => 'Cardiovascular capacity',
        'q5'  => 'Functional strength',
        'q6'  => 'Sleep',
        'q7'  => 'Smoking',
        'q8'  => 'Social connection',
        'q9'  => 'Diet',
    );

    /**
     * Plain-text structured output for the Stage 1 PDF + similar consumers.
     *
     * Returns a flat array of plain-text fields suitable for inclusion
     * in a Make.com webhook payload. Mirrors the data the web result
     * page renders via build(), but split into discrete fields instead
     * of one concatenated HTML blob.
     *
     * No AI. No external HTTP. Single function over $stage1_data + $rate.
     *
     * @since 0.30.0
     *
     * @param array  $stage1_data Raw form data (q1_age, q1_sex, q2a..q9, etc.).
     * @param array  $result      Output of HDLV2_Rate_Calculator::calculate_quick.
     * @param string $client_name Full name; first name extracted internally.
     * @return array {
     *     @type string $headline_text   Rate-banded personalised paragraph (plain).
     *     @type float  $biological_age  round(rate × age, 1) — 1 dp to match the on-screen figure; 0 when age unknown.
     *     @type string $strongest_topic Short topic label (e.g., "Sleep") or ''.
     *     @type string $strongest_text  Plain-text strength narrative or fallback.
     *     @type string $priority_topic  Short topic label or ''.
     *     @type string $priority_text   Plain-text priority narrative or fallback.
     * }
     */
    public static function build_structured( $stage1_data, $result, $client_name = '' ) {
        if ( ! is_array( $stage1_data ) ) $stage1_data = array();
        if ( ! is_array( $result ) )      $result      = array();

        $age  = (int) ( $stage1_data['q1_age'] ?? 0 );
        $sex  = strtolower( (string) ( $stage1_data['q1_sex'] ?? '' ) );
        $rate = (float) ( $result['rate'] ?? 1.0 );

        $first_name = '';
        if ( $client_name ) {
            $parts      = explode( ' ', trim( (string) $client_name ), 2 );
            $first_name = (string) $parts[0];
        }

        list( $strongest, $weakest, ) = self::pick_factors( $stage1_data );

        // Reuse the existing rate-banded paragraph builder, then strip
        // its HTML wrappers (<p>, <strong>) for plain-text delivery.
        $headline_html = self::p1_headline( $first_name, $age, $sex, $rate );
        $headline_text = self::html_to_plain( $headline_html );

        return array(
            'headline_text'   => $headline_text,
            'biological_age'  => $age > 0 ? round( $rate * $age, 1 ) : 0,
            'strongest_topic' => self::topic_for_factor( $strongest ),
            'strongest_text'  => self::library_text_plain( $strongest, 'strength' ),
            'priority_topic'  => self::topic_for_factor( $weakest ),
            'priority_text'   => self::library_text_plain( $weakest, 'priority' ),
        );
    }

    /**
     * Map a picked factor row (or null) to its short card-title topic.
     */
    private static function topic_for_factor( $factor ) {
        if ( ! is_array( $factor ) || empty( $factor['q'] ) ) {
            return '';
        }
        return self::TOPIC_LABELS[ $factor['q'] ] ?? '';
    }

    /**
     * Resolve a factor to its plain-text narrative from the appropriate
     * library. Honest fallbacks for the all-strong / all-weak / no-data
     * edges so the PDF never renders a blank card.
     */
    private static function library_text_plain( $factor, $kind ) {
        if ( ! is_array( $factor ) || empty( $factor['q'] ) || empty( $factor['a'] ) ) {
            return $kind === 'strength'
                ? 'No standout strength in your nine answers — every change you make from here has somewhere to land. Two or three modifiable factors tend to do most of the work.'
                : 'No major weak link in your data. The trajectory ahead is shaped by consistency over years rather than any single factor.';
        }

        $key = $factor['q'] . '_' . $factor['a'];

        if ( $kind === 'strength' ) {
            $lib = self::strength_library();
            if ( isset( $lib[ $key ] ) ) {
                return self::html_to_plain( $lib[ $key ] );
            }
            return 'You have at least one factor working in your favour — the foundation the rest of the picture builds from.';
        }

        // priority
        $lib = self::priority_library();
        if ( isset( $lib[ $key ] ) ) {
            return self::html_to_plain( $lib[ $key ]['body'] );
        }
        return 'One of your answers points to a focus area worth attention with your practitioner in Stage 3.';
    }

    /**
     * Convert the small HTML fragments used in the libraries to clean
     * plain text suitable for JSON delivery.
     *
     * Strips tags (<strong>, <em>, <p>), decodes the HTML entities the
     * libraries actually contain (&mdash;, &ndash;, &#8322; for VO₂),
     * collapses whitespace.
     */
    private static function html_to_plain( $html ) {
        $text = wp_strip_all_tags( (string) $html );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( '/\s+/u', ' ', $text );
        return trim( (string) $text );
    }
}
