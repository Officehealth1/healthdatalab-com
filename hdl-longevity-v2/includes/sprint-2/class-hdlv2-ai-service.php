<?php
/**
 * AI Service — Claude Sonnet 4 API wrapper.
 *
 * Handles WHY extraction (Stage 2) and draft report generation (Stage 3).
 * API key: define HDLV2_ANTHROPIC_API_KEY in wp-config.php.
 *
 * @package HDL_Longevity_V2
 * @since 0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_AI_Service {

    const API_URL    = 'https://api.anthropic.com/v1/messages';
    // v0.36.1 — unified to Sonnet 4.6 across every AI surface (Stage 2 WHY,
    // draft report, final report, consultation organise/refresh/regenerate,
    // Stage 3 commentary helper). MODEL_HAIKU kept as a separate const so the
    // Stage 3 commentary surface can be flipped back to Haiku independently
    // (cost optimisation) without touching every other caller of MODEL.
    const MODEL       = 'claude-sonnet-4-6';
    const MODEL_HAIKU = 'claude-sonnet-4-6';

    // ──────────────────────────────────────────────────────────────
    //  PUBLIC: WHY EXTRACTION
    // ──────────────────────────────────────────────────────────────

    /**
     * Extract a structured WHY profile from Stage 2 data.
     *
     * @param array $stage2_data { key_people, motivations, vision_text }
     * @return array { distilled_why, ai_reformulation } or placeholder on failure.
     */
    public static function extract_why( $stage2_data ) {
        $key = self::get_api_key();
        if ( ! $key ) {
            return self::why_placeholder();
        }

        $vision = $stage2_data['vision_text'] ?? '';

        $user_prompt = sprintf(
            "The client shared the following about their health and longevity motivations:\n\n"
            . "\"%s\"\n\n"
            . "Your job is to extract EVERYTHING they said, not to summarise. Preserve specific names,\n"
            . "ages, dates, places, relationships, exact phrases with emotional weight. Nothing gets dropped.\n\n"
            . "Return ONLY valid JSON matching this exact schema:\n"
            . "{\n"
            . "  \"distilled_why\": \"One sentence in their own words that captures their deepest motivation.\",\n"
            . "  \"ai_reformulation\": \"3-5 paragraphs of rich second-person prose reflecting their vision. Use <p> HTML tags. Name every person they named. Keep every specific age, date, place. Do NOT flatten — aim for 60-70%% of their input length.\",\n"
            . "  \"key_people\": [ { \"name\": \"...\", \"relationship\": \"...\", \"age\": \"... or null\", \"note\": \"why they matter\" } ],\n"
            . "  \"motivations\": [\"every specific motivation they raised, separate entries, preserving exact phrasing\"],\n"
            . "  \"fears\": [\"every fear or future they want to avoid — separate entries, exact phrasing where it has weight\"],\n"
            . "  \"verbatim_quotes\": [\"3-10 direct quotes that carry particular emotional weight or specific detail\"],\n"
            . "  \"life_context\": [\"job, working hours, travel pattern, family situation, recent events — short phrases\"]\n"
            . "}\n\n"
            . "CRITICAL:\n"
            . "- If they named Sophie, Mia, Leo, dad, mum — include ALL names.\n"
            . "- If they said 'I don't want to end up like my mum Joan, stuck at home at 79 with a broken hip' — keep \"Does not want a life like their mother [mother: Joan, 79, broken hip, housebound]\". Do NOT flatten to \"wants to avoid dependency.\"\n"
            . "- Brevity is a bug. Longer input deserves longer extraction.\n"
            . "- If a field has genuinely no content, return an empty array/string. Do not invent.\n"
            . "- Return JSON only. No markdown. No code fences.",
            $vision ?: '(none provided)'
        );

        $system = 'You are a health coaching assistant for HealthDataLab, a longevity platform. '
            . 'You are a careful and exhaustive archivist, with experience in the health and wellbeing field. '
            . 'Your job is to capture EVERYTHING the client said, preserving names, ages, places, exact phrases. '
            . 'You never compress for length. You never invent facts nor modify inputs. '
            . 'Always return valid JSON only — no markdown, no code fences.';

        $response = self::call_claude( $key, $system, $user_prompt, 2500 );
        if ( is_wp_error( $response ) ) {
            error_log( '[HDLV2 AI] WHY extraction failed: ' . $response->get_error_message() );
            return self::why_placeholder();
        }

        // Strip ```json fences if Claude wrapped them
        $clean = trim( $response );
        $clean = preg_replace( '/^```(?:json)?\s*/', '', $clean );
        $clean = preg_replace( '/\s*```$/', '', $clean );

        $parsed = json_decode( $clean, true );
        if ( ! $parsed || empty( $parsed['distilled_why'] ) ) {
            error_log( '[HDLV2 AI] WHY extraction: invalid JSON response — ' . substr( $response, 0, 300 ) );
            return self::why_placeholder();
        }

        return array(
            'distilled_why'    => sanitize_text_field( $parsed['distilled_why'] ),
            'ai_reformulation' => wp_kses_post( $parsed['ai_reformulation'] ?? '' ),
            'key_people'       => is_array( $parsed['key_people'] ?? null ) ? $parsed['key_people'] : array(),
            'motivations'      => is_array( $parsed['motivations'] ?? null ) ? array_values( array_map( 'sanitize_text_field', $parsed['motivations'] ) ) : array(),
            'fears'            => is_array( $parsed['fears'] ?? null ) ? array_values( array_map( 'sanitize_text_field', $parsed['fears'] ) ) : array(),
            'verbatim_quotes'  => is_array( $parsed['verbatim_quotes'] ?? null ) ? array_values( array_map( 'sanitize_text_field', $parsed['verbatim_quotes'] ) ) : array(),
            'life_context'     => is_array( $parsed['life_context'] ?? null ) ? array_values( array_map( 'sanitize_text_field', $parsed['life_context'] ) ) : array(),
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  PUBLIC: DRAFT REPORT GENERATION
    // ──────────────────────────────────────────────────────────────

    /**
     * Generate AWAKEN / LIFT / THRIVE draft report sections.
     *
     * @param array  $calc_result  { rate, bio_age, bmi, whr, whtr, scores }
     * @param array  $stage1_data  { age, gender, height, weight, waist, activity }
     * @param array  $why_profile  { distilled_why, ai_reformulation }
     * @param string $client_name
     * @param array  $stage3_data  Optional Stage 3 raw data — used to extract
     *                             family_history / medications / existing_conditions
     *                             (v0.38.0 Health Background section) into the
     *                             prompt. Defaults to empty for back-compat.
     * @return array { awaken_content, lift_content, thrive_content } or placeholder.
     */
    public static function generate_draft_report( $calc_result, $stage1_data, $why_profile, $client_name = '', $stage3_data = array() ) {
        $key = self::get_api_key();
        if ( ! $key ) {
            return self::report_placeholder();
        }

        $rate    = $calc_result['rate'] ?? 1.0;
        $bio_age = $calc_result['bio_age'] ?? '?';
        $age     = $stage1_data['age'] ?? '?';
        $gender  = $stage1_data['gender'] ?? 'unknown';
        $scores  = $calc_result['scores'] ?? array();
        $why     = $why_profile['distilled_why'] ?? 'Not provided';

        // Identify top positive and negative factors
        $positives = array();
        $negatives = array();
        foreach ( $scores as $name => $score ) {
            if ( ! is_numeric( $score ) ) continue;
            if ( $score >= 4 ) $positives[] = "$name ($score/5)";
            if ( $score <= 2 ) $negatives[] = "$name ($score/5)";
        }

        $scores_text = '';
        foreach ( $scores as $name => $score ) {
            $scores_text .= "- $name: " . ( is_numeric( $score ) ? "$score/5" : 'skipped' ) . "\n";
        }

        // v0.38.0 — Stage 3 Section 6 (Health Background) into the prompt.
        // Three optional long-form text fields the client typed about their
        // family history, current medications, and existing conditions.
        // Empty fields are omitted so Claude doesn't pad with placeholders.
        // The DATA WHITELIST constraint above keeps Claude from inventing
        // markers — these inputs are surfaced as raw client-typed context,
        // NOT as scored metrics.
        $background_block = '';
        $fh   = is_array( $stage3_data ) ? trim( (string) ( $stage3_data['family_history']      ?? '' ) ) : '';
        $meds = is_array( $stage3_data ) ? trim( (string) ( $stage3_data['medications']         ?? '' ) ) : '';
        $cond = is_array( $stage3_data ) ? trim( (string) ( $stage3_data['existing_conditions'] ?? '' ) ) : '';
        if ( $fh !== '' || $meds !== '' || $cond !== '' ) {
            $background_block  = "\n=== HEALTH BACKGROUND (client-typed at Stage 3, NOT scored) ===\n";
            $background_block .= "These are short, off-the-top-of-head notes from the client. Reference them gently where they\n";
            $background_block .= "inform AWAKEN context or LIFT priorities (e.g. genetic predisposition raising priority of\n";
            $background_block .= "a focus area; current medications shaping what to recommend). Do NOT diagnose, contradict\n";
            $background_block .= "medical advice, or invent risks not stated. Practitioner will confirm at consultation.\n";
            if ( $fh   !== '' ) $background_block .= 'Family history: '       . $fh   . "\n";
            if ( $meds !== '' ) $background_block .= 'Current medications: '  . $meds . "\n";
            if ( $cond !== '' ) $background_block .= 'Existing conditions: '  . $cond . "\n";
            $background_block .= "\n";
        }

        $user_prompt = sprintf(
            "Generate a personalised longevity draft report.\n\n"
            . "=== HARD CONSTRAINTS ===\n"
            . "1. DATA WHITELIST. The only scored metrics that exist in this client's data are the 21 keys\n"
            . "   listed in Health Scores below. Do NOT mention any metric outside that list — we do NOT\n"
            . "   measure HRV / heart rate variability, cortisol, VO2max, insulin, microbiome, or any other\n"
            . "   clinical marker. Referring to them invents data we do not have.\n"
            . "2. SEVERITY BANDS:\n"
            . "   5/5 = excellent   4/5 = strong   3/5 = average   2/5 = below average (a focus area)   1/5 = critical\n"
            . "   Do NOT describe 2/5 scores as 'severe' or 'accelerating damage'. Reserve that language for 1/5.\n"
            . "3. LENGTH CAP. Each of awaken_content, lift_content, thrive_content MUST be 100-130 words MAX.\n"
            . "   Use 2-3 short paragraphs per section. Be punchy and specific, not exhaustive — every sentence\n"
            . "   earns its place. This is a fixed-layout PDF; verbose prose overflows the page boundary and\n"
            . "   loses charts. Premium reports are concise.\n\n"
            . "=== CLIENT DATA ===\n"
            . "Client: %s, age %s, gender %s\n"
            . "Rate of Ageing: %s (biological age %s vs chronological %s)\n"
            . "WHY: %s\n\n"
            . "Health Scores (0-5, higher = better) — complete list, no other scores exist:\n%s\n"
            . "Top positive factors (score >= 4): %s\n"
            . "Top negative factors (score <= 2): %s\n"
            . "%s"
            . "\nGenerate three sections as HTML paragraphs (use <p>, <strong>, <ul>, <li> tags):\n\n"
            . "AWAKEN: Current state assessment. Where are they now? What does their biological age mean? "
            . "What is their trajectory if nothing changes? Be honest but compassionate. Only name metrics from the whitelist above.\n\n"
            . "LIFT: Action plan. Based on their actual weakest scores (from the list above), what are the top 3-5 changes that would "
            . "have the most impact? Be specific and practical. Every bullet must reference a specific score by name + number. Use bullet points.\n\n"
            . "THRIVE: Long-term vision. Connect to their WHY. Paint a picture of what their life looks like "
            . "at 70, 80, 90 if they commit to these changes. Be inspiring.\n\n"
            . "Return ONLY valid JSON: { \"awaken_content\": \"...\", \"lift_content\": \"...\", \"thrive_content\": \"...\" }",
            $client_name ?: 'Client',
            $age, $gender, $rate, $bio_age, $age, $why,
            $scores_text,
            implode( ', ', $positives ) ?: 'none identified',
            implode( ', ', $negatives ) ?: 'none identified',
            $background_block
        );

        $system = 'You are a longevity report writer for HealthDataLab. You ONLY reference the 21 whitelisted metrics in the input — you never invent clinical markers like HRV or cortisol. You respect severity bands (2/5 is a focus area, not an emergency). Generate personalised, evidence-informed '
            . 'health reports using the AWAKEN/LIFT/THRIVE framework. Write in second person ("you"). '
            . 'Be warm but professional. Use HTML formatting (p, strong, ul, li). '
            . 'Always return valid JSON only — no markdown, no code fences.';

        $response = self::call_claude( $key, $system, $user_prompt, 2000 );
        if ( is_wp_error( $response ) ) {
            error_log( '[HDLV2 AI] Draft report failed: ' . $response->get_error_message() );
            return self::report_placeholder();
        }

        $parsed = json_decode( $response, true );
        if ( ! $parsed || empty( $parsed['awaken_content'] ) ) {
            error_log( '[HDLV2 AI] Draft report: invalid JSON response' );
            return self::report_placeholder();
        }

        return array(
            'awaken_content' => wp_kses_post( $parsed['awaken_content'] ),
            'lift_content'   => wp_kses_post( $parsed['lift_content'] ?? '' ),
            'thrive_content' => wp_kses_post( $parsed['thrive_content'] ?? '' ),
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  PUBLIC: CLIENT DRAFT NARRATIVE (Phase 5 — interactive view)
    // ──────────────────────────────────────────────────────────────

    /**
     * Generate the 5-section interactive narrative shown on the client's
     * draft report portal. Separate from generate_draft_report() which
     * produces awaken/lift/thrive HTML for the Make.com PDF. Same inputs,
     * different output shape — runs alongside the PDF call at Stage 3.
     *
     * Non-blocking: returns null on any failure so the existing PDF
     * pipeline is never broken by client-narrative issues.
     *
     * @param array  $calc_result  { rate, bio_age, scores }
     * @param array  $stage1_data  { age, gender, ... }
     * @param array  $why_profile  { distilled_why, ai_reformulation }
     * @param string $client_name
     * @return array|null Structured narrative or null on failure.
     */
    public static function generate_client_draft_narrative( $calc_result, $stage1_data, $why_profile, $client_name = '', $practitioner_updates_text = '', $apply_draft_cap = true ) {
        // v0.36.3 — Optional 5th param `$practitioner_updates_text` lets the
        // FINAL regenerate path inject addenda context so the client-facing
        // narrative panels (Your Analysis, What the curve is telling us, Top
        // Strengths/Focus Areas, Tying back to your goals, Draft Recs cards)
        // refresh with the practitioner's post-consultation updates instead
        // of staying frozen at the DRAFT snapshot. Empty string = legacy
        // DRAFT-time behaviour, fully backward-compatible.
        //
        // v0.40.0 — Optional 6th param `$apply_draft_cap` decouples the
        // 240-char Draft teaser cap from the Final web view. Final passes
        // false → Claude writes a fuller opening (2-4 sentences, ~600 char
        // ceiling in prompt only, no server-side cap) for the `/my-report/`
        // surface. Draft default stays the strict 2-sentence/220 char
        // teaser plus 240-char hard cap. See call site in
        // class-hdlv2-final-report.php (post-consultation refresh).
        $key = self::get_api_key();
        if ( ! $key ) {
            return null;
        }

        $rate     = $calc_result['rate']    ?? 1.0;
        $bio_age  = $calc_result['bio_age'] ?? null;
        $age      = $stage1_data['age']     ?? $stage1_data['q1_age'] ?? null;
        $gender   = $stage1_data['gender']  ?? $stage1_data['q1_sex'] ?? 'unspecified';
        $scores   = $calc_result['scores']  ?? array();
        $why      = $why_profile['distilled_why']    ?? '';
        $why_ref  = $why_profile['ai_reformulation'] ?? '';

        // v0.19.0 — richer WHY fields (when available from extract_why upgrade)
        $key_people      = is_array( $why_profile['key_people']      ?? null ) ? $why_profile['key_people']      : array();
        $motivations     = is_array( $why_profile['motivations']     ?? null ) ? $why_profile['motivations']     : array();
        $fears           = is_array( $why_profile['fears']           ?? null ) ? $why_profile['fears']           : array();
        $verbatim_quotes = is_array( $why_profile['verbatim_quotes'] ?? null ) ? $why_profile['verbatim_quotes'] : array();
        $life_context    = is_array( $why_profile['life_context']    ?? null ) ? $why_profile['life_context']    : array();

        $people_text    = '';
        foreach ( $key_people as $p ) {
            if ( is_array( $p ) ) {
                $bits = array();
                if ( ! empty( $p['name'] ) )         $bits[] = $p['name'];
                if ( ! empty( $p['relationship'] ) ) $bits[] = '(' . $p['relationship'] . ( ! empty( $p['age'] ) ? ', ' . $p['age'] : '' ) . ')';
                if ( ! empty( $p['note'] ) )         $bits[] = '— ' . $p['note'];
                $people_text .= '  - ' . implode( ' ', $bits ) . "\n";
            } elseif ( is_string( $p ) ) {
                $people_text .= '  - ' . $p . "\n";
            }
        }
        $motivations_text = $motivations ? '  - ' . implode( "\n  - ", $motivations ) : '';
        $fears_text       = $fears       ? '  - ' . implode( "\n  - ", $fears )       : '';
        $quotes_text      = $verbatim_quotes ? '  - "' . implode( "\"\n  - \"", $verbatim_quotes ) . '"' : '';
        $life_text        = $life_context    ? '  - ' . implode( "\n  - ", $life_context )    : '';

        // Top strengths + focus areas (used to seed Claude's attention)
        $strengths   = array();
        $focus_areas = array();
        foreach ( $scores as $metric => $score ) {
            if ( ! is_numeric( $score ) ) continue;
            if ( $score >= 4 ) $strengths[]   = array( 'metric' => $metric, 'score' => (int) $score );
            if ( $score <= 2 ) $focus_areas[] = array( 'metric' => $metric, 'score' => (int) $score );
        }

        $scores_text = '';
        foreach ( $scores as $metric => $score ) {
            $scores_text .= "- {$metric}: " . ( is_numeric( $score ) ? "{$score}/5" : 'skipped' ) . "\n";
        }

        // Trajectory status from rate (matches the chart labels the client sees)
        $rate_num = (float) $rate;
        if ( $rate_num >= 1.25 )     { $traj_status = 'Accelerated Aging'; }
        elseif ( $rate_num >= 1.05 ) { $traj_status = 'Slightly Accelerated'; }
        elseif ( $rate_num >= 0.95 ) { $traj_status = 'On Pace with Population'; }
        elseif ( $rate_num >= 0.80 ) { $traj_status = 'Slower than Average'; }
        else                         { $traj_status = 'Well Below Population Pace'; }

        $user_prompt =
              "You are writing the interactive DRAFT report for a longevity client.\n"
            . "This is shown on their web portal BEFORE their practitioner consultation.\n"
            . "Tone: warm, direct, data-grounded. Second person (\"you\"). No generic advice.\n\n"

            . "=== HARD CONSTRAINTS — BREAK THESE AND THE REPORT IS WRONG ===\n"
            . "1. DATA WHITELIST. The client data consists of EXACTLY the fields below plus their WHY.\n"
            . "   Allowed 21 score keys: physicalActivity, sitToStand, breathHold, balance, sleepDuration,\n"
            . "   sleepQuality, stressLevels, socialConnections, dietQuality, alcoholConsumption,\n"
            . "   smokingStatus, cognitiveActivity, sunlightExposure, supplementIntake, dailyHydration,\n"
            . "   skinElasticity, bmiScore, whrScore, whtrScore, bloodPressureScore, heartRateScore.\n"
            . "   Do NOT mention any metric outside this list. In particular: no HRV / heart rate variability,\n"
            . "   no cortisol, no VO2max, no insulin, no microbiome, no 'cellular damage markers' — we do not\n"
            . "   measure these. Referring to them invents clinical data we do not have.\n\n"

            . "2. SEVERITY BANDS — map scores to language accurately:\n"
            . "   5/5  = excellent / top tier / a real strength\n"
            . "   4/5  = strong / good foundation\n"
            . "   3/5  = average / adequate\n"
            . "   2/5  = below average / a focus area (NOT 'dangerous', NOT 'accelerating damage')\n"
            . "   1/5  = critical / urgent / highest-impact lever\n"
            . "   A 2/5 score is a room-to-improve area, not an emergency. Reserve dramatic language for 1/5.\n\n"

            . "3. CHART GROUNDING. The client sees two charts: a trajectory curve and a 22-point radar.\n"
            . "   Your trajectory_commentary MUST reference the actual rate number and the status shown on\n"
            . "   the chart. It may also reference the gap between 'With Changes' and 'Without Changes'\n"
            . "   projections. Do NOT drift into generic lifestyle advice disconnected from the chart.\n\n"

            . "=== CLIENT DATA ===\n"
            . "Client: " . ( $client_name ?: 'Client' ) . ", age " . ( $age ?? '?' ) . ", gender {$gender}\n"
            . "Rate of Ageing: " . number_format( $rate_num, 2 ) . "x  (trajectory status: {$traj_status})\n"
            . "Biological age: {$bio_age}   Chronological age: {$age}\n"
            . "\nWHY (distilled, one sentence): " . ( $why ?: '(not provided)' ) . "\n"
            . ( $why_ref ? "\nWHY reformulation (full prose):\n{$why_ref}\n" : '' )
            . ( $people_text ? "\nKey people they named (use real names in goals_linkage):\n{$people_text}" : '' )
            . ( $motivations_text ? "\nSpecific motivations they raised:\n{$motivations_text}\n" : '' )
            . ( $fears_text ? "\nSpecific fears they raised:\n{$fears_text}\n" : '' )
            . ( $quotes_text ? "\nVerbatim quotes with emotional weight (use their words where you can):\n{$quotes_text}\n" : '' )
            . ( $life_text ? "\nLife context (job, schedule, family situation, etc.):\n{$life_text}\n" : '' )
            . "\nHealth Scores (0 low - 5 excellent) — THIS IS THE COMPLETE LIST, no other scores exist:\n{$scores_text}\n"
            . 'Top strengths (score >= 4): ' . ( empty( $strengths )   ? 'none' : wp_json_encode( $strengths ) ) . "\n"
            . 'Top focus areas (score <= 2): ' . ( empty( $focus_areas ) ? 'none' : wp_json_encode( $focus_areas ) ) . "\n\n"

            . ( $practitioner_updates_text !== ''
                ? "=== RECENT PRACTITIONER UPDATES (post-consultation, highest-priority intelligence) ===\n"
                  . "These addenda were added by the practitioner AFTER the original consultation. They override and refine the picture across every section. The client will SEE these in the report; surface their substance explicitly in opening, trajectory_commentary, and recs_preview where they connect to specific scores or goals. Do not treat them as silent background.\n\n"
                  . $practitioner_updates_text
                  . "\n\n"
                : '' )

            . "=== OUTPUT SCHEMA — strict JSON ===\n"
            . "{\n"
            // v0.39.1 — Draft teaser, not a final-report narrative. The PDF
            // is 2 pages and the draft is meant to land a headline finding,
            // not deliver the full analysis (that comes after consultation).
            // 2 sentences max, 220 characters absolute ceiling — the Draft
            // Report PDF layout breaks at ~280 chars. Server-side mb_substr
            // cap below enforces this even if Claude over-writes.
            //
            // v0.40.0 — Final web-view opening is decoupled. When
            // $apply_draft_cap=false (Final post-consultation refresh)
            // Claude writes a fuller 2-4 sentence opening up to ~600 chars
            // so the /my-report/ page has substance, not a teaser. The
            // server-side cap is also skipped in the Final path.
            . "  \"opening\": \"" . ( $apply_draft_cap
                ? "DRAFT TEASER. 2 sentences MAX, 220 characters MAX. Sentence 1: name the rate (X.XXx) + bio vs chrono age — the headline finding only. Sentence 2: point to the consultation as where you and they will unpack what it means. DO NOT enumerate scores, name specific metrics beyond the rate, list strengths/focus areas, or quote goals — that detail lives elsewhere in this JSON (radar_commentary, goals_linkage) and in the final report. Use <strong> for the rate number only."
                : "FINAL ANALYSIS OPENING. 2 to 4 sentences, up to 600 characters. This is the finalised, post-consultation report on the client's portal — the client deserves substance, not a teaser. Sentence 1: name the rate (X.XXx) + biological vs chronological age as the headline finding. Sentence 2-3: bridge to ONE specific lowest-scoring whitelist metric (name it + score) and how it ties to their distilled WHY or a named person from their key_people list. Sentence 4 (optional): point to the recommendations cards on this page as the actionable response. Use <strong> for the rate number and key score values only. Do not enumerate all scores — that detail lives in radar_commentary and the recommendations section."
            ) . "\",\n"
            . "  \"trajectory_commentary\": [\"bullet 1\", \"bullet 2\", \"bullet 3\"],\n"
            . "  \"radar_commentary\": {\n"
            . "    \"strengths\":   [ { \"metric\": \"<human name from whitelist>\", \"score\": 5, \"note\": \"one short sentence\" } ],\n"
            . "    \"focus_areas\": [ { \"metric\": \"<human name from whitelist>\", \"score\": 1, \"note\": \"one short actionable sentence\" } ]\n"
            . "  },\n"
            . "  \"goals_linkage\": [\n"
            . "    { \"goal_quote\": \"<a real verbatim quote from the client, or a motivation/fear they actually raised — name the person they named>\", \"insight\": \"how their data maps to this specific goal or fear\", \"mapped_metrics\": [\"<whitelist key>\"] }\n"
            . "  ]   // 2-3 entries. Each goal_quote MUST draw from the Verbatim quotes, Motivations, Fears, or Key people lists above. Do not synthesise generic goals when specific ones are provided.\n"
            . "  \"recs_preview\": [\n"
            . "    { \"title\": \"Verb-first title\", \"body\": \"1-2 sentences of rationale tied to specific scores\" }\n"
            . "  ]\n"
            . "}\n\n"

            . "=== TRAJECTORY_COMMENTARY — 3 bullet rules ===\n"
            . "Bullet 1 MUST: state where their curve is right now using the rate number and status (\"Your {$traj_status} status at " . number_format( $rate_num, 2 ) . "x pace...\").\n"
            . "Bullet 2 MUST: identify the 1-2 LOWEST-scoring whitelist metrics as the biggest driver of the pace. Name them by whitelist key.\n"
            . "Bullet 3 MUST: contrast 'With Changes' vs 'Without Changes' — what the green curve could look like if their lowest metrics moved from 1/5 to 3/5, and the risk if they stay flat.\n"
            . "GOOD EXAMPLE (for rate 1.32x, physical activity 1/5, sit-to-stand 1/5):\n"
            . "  [\"At 1.32x your curve is tracking the 'Accelerated Aging' band — roughly 10 years ahead of your chronological age.\",\n"
            . "   \"Physical Activity (1/5) and Sit to Stand (1/5) are the two lowest scores pulling the slope steeper.\",\n"
            . "   \"If those two move from 1/5 to 3/5 over 12 weeks, the green 'With Changes' curve projects a meaningfully later decline — that's the upside the chart is showing.\"]\n\n"

            . "=== OTHER RULES ===\n"
            . "- 3 bullets in trajectory_commentary. 3 items each in strengths and focus_areas. 2-3 goals_linkage. 3-5 recs_preview.\n"
            . "- Use <strong> only inside the \"opening\" string, not elsewhere.\n"
            . "- Keep notes and bodies tight — one sentence where possible.\n"
            . "- Human metric names: physicalActivity -> Physical Activity, bmiScore -> BMI, whrScore -> WHR, whtrScore -> WHtR, sitToStand -> Sit to Stand, heartRateScore -> Heart Rate, bloodPressureScore -> Blood Pressure.\n"
            . "- If a score is missing or skipped, do not mention it.\n"
            . "- Every recs_preview item MUST reference at least one specific score by name + number (e.g. \"given your sleepQuality 2/5...\").\n"
            . "- Return JSON only. No markdown. No code fences.";

        $system = 'You are a longevity coach writing a personalised interactive draft report. You ONLY reference the 21 whitelisted metrics in the input — you never invent clinical markers. You respect severity bands (2/5 is a focus area, not an emergency). You ground trajectory commentary in the actual rate + chart status. Return JSON only — no prose outside the JSON object.';

        $response = self::call_claude( $key, $system, $user_prompt, 2200 );
        if ( is_wp_error( $response ) ) {
            error_log( '[HDLV2 AI] Client draft narrative failed: ' . $response->get_error_message() );
            return null;
        }

        // Claude occasionally wraps JSON in ```json fences despite instructions
        $clean = trim( $response );
        $clean = preg_replace( '/^```(?:json)?\s*/', '', $clean );
        $clean = preg_replace( '/\s*```$/', '', $clean );

        $parsed = json_decode( $clean, true );
        if ( ! is_array( $parsed ) || empty( $parsed['opening'] ) ) {
            error_log( '[HDLV2 AI] Client draft narrative: invalid JSON — ' . substr( (string) $response, 0, 300 ) );
            return null;
        }

        // v0.39.1 — Hard cap on the Draft opening text. The Draft Report PDF
        // template uses a strict 297mm A4-clipped layout (overflow:hidden),
        // so prose that exceeds ~280 chars overflows past the footer and gets
        // silently clipped. Belt-and-braces alongside the prompt directive.
        //
        // v0.40.0 — Skip the cap for Final-context calls. The /my-report/
        // page is HTML-fluid, not a fixed-height PDF, so the opening can
        // breathe to 2-4 sentences. cap_draft_opening would otherwise truncate
        // mid-thought + add an ellipsis, which reads worse on the Final
        // surface than letting Claude's intended length stand.
        $opening_raw = wp_kses_post( (string) $parsed['opening'] );
        $opening     = $apply_draft_cap
            ? self::cap_draft_opening( $opening_raw, 240 )
            : $opening_raw;

        return array(
            'opening'               => $opening,
            'trajectory_commentary' => array_map( 'wp_kses_post', (array) ( $parsed['trajectory_commentary'] ?? array() ) ),
            'radar_commentary'      => array(
                'strengths'   => self::sanitise_radar_notes( $parsed['radar_commentary']['strengths']   ?? array() ),
                'focus_areas' => self::sanitise_radar_notes( $parsed['radar_commentary']['focus_areas'] ?? array() ),
            ),
            'goals_linkage'         => self::sanitise_goals( $parsed['goals_linkage'] ?? array() ),
            'recs_preview'          => self::sanitise_recs(  $parsed['recs_preview']  ?? array() ),
            'generated_at'          => gmdate( 'c' ),
            'model'                 => self::MODEL,
        );
    }

    /**
     * v0.39.1 — Cap the Draft opening text to keep the 2-page PDF layout
     * intact even when Claude over-writes past the prompt's length directive.
     *
     * Strategy:
     *   1. Strip HTML to count visible chars (the <strong> wrapper doesn't
     *      take page space, but we measure the prose itself).
     *   2. If under the cap → return original (HTML preserved).
     *   3. Over the cap → walk back to the last sentence boundary ("." or
     *      ". ") before the cap and trim there. No partial-sentence cuts.
     *   4. Fallback: hard cap at character boundary with ellipsis if no
     *      sentence boundary exists in the window.
     *
     * @param string $html Raw HTML from Claude (already wp_kses_post-cleaned).
     * @param int    $max_chars Visible-text cap (default 240).
     * @return string Cap-safe HTML.
     */
    private static function cap_draft_opening( $html, $max_chars = 240 ) {
        $plain = wp_strip_all_tags( (string) $html );
        if ( mb_strlen( $plain ) <= $max_chars ) {
            return $html;
        }
        // Trim to last sentence boundary within the cap window.
        $window = mb_substr( $plain, 0, $max_chars );
        $last_period = max( mb_strrpos( $window, '. ' ), mb_strrpos( $window, '.' ) );
        if ( $last_period !== false && $last_period > 60 ) {
            $trimmed = mb_substr( $plain, 0, $last_period + 1 );
        } else {
            $trimmed = rtrim( $window ) . '…';
        }
        // Re-wrap the rate number in <strong> if Claude used it on the
        // headline (cheap heuristic — looks for "X.XXx" pattern).
        $trimmed = preg_replace( '/\b(\d+\.\d{1,2}x)\b/u', '<strong>$1</strong>', $trimmed, 1 );
        return wp_kses_post( $trimmed );
    }

    /**
     * v0.40.0 — Light punctuation polish for raw transcripts.
     *
     * Matthew's spec (2026-05-08, Item 9 PDF #5): "Add commas and full
     * stops only — no rewording, preserve voice exactly. The current wall
     * of unpunctuated text is hard to read."
     *
     * Applied in /form/stage2-callback to `vision_text` before writing
     * to why_profiles. The Stage 2 WHY PDFMonkey template reads this
     * column directly so the polished version shows in the rendered PDF.
     *
     * Conservative ruleset — never deletes content, never rewords:
     *   1. Collapse multiple spaces / tabs to one; cap consecutive
     *      newlines at two.
     *   2. Standalone "i" → "I" (speech-to-text artefact).
     *   3. Standalone "im" → "I'm" (most common speech contraction).
     *   4. Capitalise first letter of the text, each paragraph, and
     *      every character following sentence-end punctuation.
     *   5. Append a single period if no terminal punctuation exists.
     *
     * Deliberately omits:
     *   - Conjunctive-comma injection (too many false positives on lists
     *     like "rice and beans").
     *   - Sentence-boundary insertion on long unpunctuated runs (no
     *     reliable signal without pause data).
     *   - "id" → "I'd" / "ive" → "I've" (collide with non-pronoun
     *     usages like record IDs in clinical text).
     *
     * @param string $text Raw transcript (typed or speech-to-text).
     * @return string Lightly-polished transcript. If empty input → empty output.
     */
    public static function polish_transcript( $text ) {
        $text = (string) $text;
        if ( trim( $text ) === '' ) {
            return $text;
        }

        // 1. Normalise whitespace
        $text = preg_replace( '/[ \t]+/', ' ', $text );
        $text = preg_replace( '/\n{3,}/', "\n\n", $text );
        $text = trim( $text );

        // 2. Speech-to-text capitalisation fixes
        $text = preg_replace( '/\bi\b/', 'I', $text );
        $text = preg_replace( '/\bim\b/i', "I'm", $text );

        // 3a. Capitalise first letter at start of text or paragraph
        $text = preg_replace_callback(
            '/(^|\n\s*)([a-z])/',
            function ( $m ) { return $m[1] . strtoupper( $m[2] ); },
            $text
        );

        // 3b. Capitalise first letter after sentence-end punctuation
        $text = preg_replace_callback(
            '/([.!?]\s+)([a-z])/',
            function ( $m ) { return $m[1] . strtoupper( $m[2] ); },
            $text
        );

        // 4. Ensure trailing period
        if ( ! preg_match( '/[.!?…]$/', $text ) ) {
            $text .= '.';
        }

        return $text;
    }

    private static function sanitise_radar_notes( $items ) {
        $out = array();
        foreach ( (array) $items as $item ) {
            if ( ! is_array( $item ) ) continue;
            $out[] = array(
                'metric' => sanitize_text_field( $item['metric'] ?? '' ),
                'score'  => isset( $item['score'] ) && is_numeric( $item['score'] ) ? (float) $item['score'] : null,
                'note'   => wp_kses_post( $item['note'] ?? '' ),
            );
        }
        return $out;
    }

    private static function sanitise_goals( $items ) {
        $out = array();
        foreach ( (array) $items as $item ) {
            if ( ! is_array( $item ) ) continue;
            $out[] = array(
                'goal_quote'     => sanitize_text_field( $item['goal_quote'] ?? '' ),
                'insight'        => wp_kses_post( $item['insight'] ?? '' ),
                'mapped_metrics' => array_map( 'sanitize_text_field', (array) ( $item['mapped_metrics'] ?? array() ) ),
            );
        }
        return $out;
    }

    private static function sanitise_recs( $items ) {
        $out = array();
        foreach ( (array) $items as $item ) {
            if ( ! is_array( $item ) ) continue;
            $out[] = array(
                'title' => sanitize_text_field( $item['title'] ?? '' ),
                'body'  => wp_kses_post( $item['body'] ?? '' ),
            );
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────────
    //  PUBLIC: FINAL REPORT GENERATION (Sprint 2C)
    // ──────────────────────────────────────────────────────────────

    /**
     * Generate FINAL report with practitioner-verified data and consultation context.
     *
     * @param array  $calc_result      Recalculated rate/scores (practitioner-verified).
     * @param array  $draft_report     { awaken_content, lift_content, thrive_content }
     * @param array  $health_changes   [ { field, original, new_value, reason } ]
     * @param string $consultation_notes Practitioner's typed notes.
     * @param array  $recommendations  [ { category, text, priority, frequency } ]
     * @param array  $why_profile      { distilled_why, key_people, motivations, fears }
     * @param string $client_name
     * @param int    $age
     * @return array { awaken_content, lift_content, thrive_content }
     */
    public static function generate_final_report( $calc_result, $draft_report, $health_changes, $consultation_notes, $recommendations, $why_profile, $client_name = '', $age = 0 ) {
        $key = self::get_api_key();
        if ( ! $key ) {
            return self::report_placeholder();
        }

        $rate    = $calc_result['rate'] ?? 1.0;
        $bio_age = $calc_result['bio_age'] ?? '?';

        // Format health data changes
        $changes_text = '';
        if ( ! empty( $health_changes ) ) {
            foreach ( $health_changes as $change ) {
                $changes_text .= sprintf( "- %s: %s → %s (%s)\n", $change['field'] ?? '', $change['original'] ?? '', $change['new_value'] ?? '', $change['reason'] ?? '' );
            }
        }

        // Format recommendations
        $recs_text = '';
        if ( ! empty( $recommendations ) ) {
            foreach ( $recommendations as $rec ) {
                $recs_text .= sprintf( "- [%s] %s (Priority: %s, Frequency: %s)\n", $rec['category'] ?? '', $rec['text'] ?? '', $rec['priority'] ?? '', $rec['frequency'] ?? '' );
            }
        }

        $why_text = $why_profile['distilled_why'] ?? 'Not provided';
        $fears    = '';
        if ( ! empty( $why_profile['fears'] ) && is_array( $why_profile['fears'] ) ) {
            $fears = implode( ', ', $why_profile['fears'] );
        }

        // Build scores text for whitelist grounding
        $scores = $calc_result['scores'] ?? array();
        $scores_text = '';
        foreach ( $scores as $metric => $score ) {
            $scores_text .= "- {$metric}: " . ( is_numeric( $score ) ? "{$score}/5" : 'skipped' ) . "\n";
        }

        $user_prompt = sprintf(
            "Generate the FINAL personalised longevity report.\n\n"
            . "=== HARD CONSTRAINTS ===\n"
            . "1. DATA WHITELIST. The only scored metrics we measure are the 20 keys listed below. Do NOT\n"
            . "   mention HRV / heart rate variability, cortisol, VO2max, insulin, microbiome, skin elasticity,\n"
            . "   or any other unmeasured marker. Name metrics as they appear in the Health Scores list.\n"
            . "2. SEVERITY BANDS (canonical, Page 17 methodology):\n"
            . "   5/5 = STRONG  4/5 = SOLID  3/5 = MODERATE  2/5 = LIFT  1/5 = URGENT\n"
            . "   Reserve dramatic language for 1/5 only. A 2/5 is a focus area, not a crisis.\n"
            . "3. TRAJECTORY GROUNDING. When describing trajectory in AWAKEN, reference the actual rate\n"
            . "   number and the band it falls into (slow / average / fast / very-fast).\n"
            . "4. LENGTH CAP. Each of awaken_content, lift_content, thrive_content MUST be 100-130 words MAX.\n"
            . "   2-3 short paragraphs per section. Every sentence earns its place. This is a fixed-layout\n"
            . "   PDF; overflow loses charts.\n"
            . "5. v0.34.0 — PAGE OWNERSHIP IS STRICT.\n"
            . "   awaken_content lives on Page 3 (intro narrative).\n"
            . "   lift_content lives on Page 11 (Analysis: WHY THIS CLIENT — pattern interpretation only).\n"
            . "   thrive_content lives on Page 10 (Analysis: WHAT THIS MEANS FOR THE NEXT DECADE).\n"
            . "   Page 13 (Recommendations) renders the practitioner's prescriptions as cards — the AI\n"
            . "   prose on Page 11 MUST NOT prescribe specific time-blocked actions, calendar reminders,\n"
            . "   step counts, walk durations, water millilitres, or behaviour scripts. Those belong on\n"
            . "   Page 13 only. Page 11 explains the cluster pattern; Page 13 prescribes; never both.\n\n"
            . "=== CLIENT DATA ===\n"
            . "CLIENT: %s, age %s\n"
            . "Rate of ageing (practitioner-verified): %s\n"
            . "Biological age: %s\n\n"
            . "Health Scores (0-5, higher = better) — complete list, no other scores exist:\n%s\n"
            . "DRAFT REPORT (as-written by the practitioner-assisted first pass):\n"
            . "AWAKEN: %s\n"
            . "LIFT: %s\n"
            . "THRIVE: %s\n\n"
            . "HEALTH DATA CHANGES (practitioner corrections):\n%s\n"
            . "CONSULTATION NOTES:\n%s\n\n"
            . "PRACTITIONER RECOMMENDATIONS (context only — these render on Page 13, do NOT restate in prose):\n%s\n"
            . "CLIENT'S WHY: %s\n"
            . "CLIENT'S FEARS: %s\n\n"
            . "=== OUTPUT INSTRUCTIONS ===\n"
            . "Three sections, three pages:\n\n"
            . "AWAKEN — Page 3 intro narrative\n"
            . "- Where the client stands today, grounded in actual rate + bio age + specific scores.\n"
            . "- Name the strongest 1-2 protective factors and the priority gap. Concrete, warm.\n"
            . "- Note any fields corrected during consultation and why this matters.\n"
            . "- One paragraph or two. NO action prescriptions.\n\n"
            . "LIFT — Page 11 Analysis prose (PATTERN INTERPRETATION ONLY)\n"
            . "- Explain the cluster pattern: which scores cluster together, what the radar shape says,\n"
            . "  what compounding looks like over the next decade given the current rate.\n"
            . "- Reference the actual rate number and project ONE numeric scenario\n"
            . "  (e.g. 'at 1.07× sustained, biological age at 70 ≈ 77.4').\n"
            . "- Surface the SINGLE most-impactful insight — do not enumerate.\n"
            . "- ABSOLUTELY FORBIDDEN: 'Start with X-minute walks', 'Block your calendar', 'Drink Y ml of\n"
            . "  water before each meal', 'Brush teeth at Z pm', 'Add 3 sets of 10 chair squats',\n"
            . "  '%s'. Any time-blocked, frequency-prefixed, or specific-action phrasing is a Page 13\n"
            . "  prescription and MUST be excluded here. Page 11 is analysis, not prescription.\n"
            . "- 100-130 words. HTML p tags.\n\n"
            . "THRIVE — Page 10 cluster-specific decade-impact analysis\n"
            . "- Engage with THIS client's weakest cluster (the lowest 2-3 scores from the list above).\n"
            . "- Mechanism-level explanation: what these metrics mean for the next decade if unaddressed,\n"
            . "  drawing on modern research (Framingham, ARIC) without being clinical-cold.\n"
            . "- Frame healthspan vs lifespan in terms of what THIS client can still do at 70/80 if the\n"
            . "  weak cluster lifts to a 4/5 average.\n"
            . "- 3 short paragraphs (~40 words each). HTML p tags. NO action prescriptions.\n"
            . "- NO generic 'cutting-edge research aligns with ancient wisdom' filler.\n\n"
            . "Return ONLY valid JSON: { \"awaken_content\": \"...\", \"lift_content\": \"...\", \"thrive_content\": \"...\" }\n"
            . "Use HTML formatting (p, strong, ul, li). British English. No markdown fences.",
            $client_name ?: 'Client', $age, $rate, $bio_age,
            $scores_text ?: '(not available)',
            strip_tags( $draft_report['awaken_content'] ?? '' ),
            strip_tags( $draft_report['lift_content'] ?? '' ),
            strip_tags( $draft_report['thrive_content'] ?? '' ),
            $changes_text ?: '(none)',
            $consultation_notes ?: '(none)',
            $recs_text ?: '(none)',
            $why_text,
            $fears ?: '(none identified)',
            'kitchen-close protocol'
        );

        $system = 'You are a longevity report writer for HealthDataLab generating the FINAL personalised report. You ONLY reference the 21 whitelisted metrics we measure — you never invent clinical markers like HRV or cortisol. You respect severity bands (2/5 is a focus area, not an emergency). You integrate the practitioner\'s recommendations as the authoritative voice. '
            . 'The practitioner has reviewed the draft with the client. Remove all "draft" language. '
            . 'Incorporate consultation insights. Personalise using the client\'s own words from their WHY. '
            . 'Be specific, actionable, warm, empowering. Do NOT make medical diagnoses. British English. '
            . 'Always return valid JSON only — no markdown, no code fences.';

        $response = self::call_claude( $key, $system, $user_prompt, 2500 );
        if ( is_wp_error( $response ) ) {
            error_log( '[HDLV2 AI] Final report failed: ' . $response->get_error_message() );
            return self::report_placeholder();
        }

        $parsed = json_decode( $response, true );
        if ( ! $parsed || empty( $parsed['awaken_content'] ) ) {
            error_log( '[HDLV2 AI] Final report: invalid JSON response' );
            return self::report_placeholder();
        }

        return array(
            'awaken_content' => wp_kses_post( $parsed['awaken_content'] ),
            'lift_content'   => wp_kses_post( $parsed['lift_content'] ?? '' ),
            'thrive_content' => wp_kses_post( $parsed['thrive_content'] ?? '' ),
        );
    }

    /**
     * Generate personalised milestones (6mo, 2yr, 5yr, 10yr).
     *
     * @param array  $calc_result { rate, bio_age, scores }
     * @param array  $why_profile { distilled_why, key_people, motivations }
     * @param array  $recommendations [ { category, text, priority } ]
     * @param int    $age
     * @return array { six_months, two_years, five_years, ten_plus_years }
     */
    public static function generate_milestones( $calc_result, $why_profile, $recommendations, $age = 0 ) {
        $key = self::get_api_key();
        if ( ! $key ) {
            return self::milestones_placeholder();
        }

        $rate   = $calc_result['rate'] ?? 1.0;
        $scores = $calc_result['scores'] ?? array();

        // Find weakest areas (score <= 2)
        $weak = array();
        foreach ( $scores as $name => $score ) {
            if ( is_numeric( $score ) && $score <= 2 ) $weak[] = "$name ($score/5)";
        }

        $rec_cats = array();
        foreach ( $recommendations as $rec ) {
            $rec_cats[] = $rec['category'] ?? '';
        }

        $user_prompt = sprintf(
            "Generate personalised health milestones at 4 intervals: 6 months, 2 years, 5 years, 10+ years.\n\n"
            . "v0.34.0 — MEASURABILITY RULE (Matthew Pass-3 brief — every milestone is testable):\n"
            . "- EVERY milestone MUST contain a measurable target (number + unit).\n"
            . "  GOOD: 'Walk 30 minutes daily without fatigue, tracking 8,000+ steps per day'\n"
            . "  GOOD: 'Hike 8km with 500m total ascent, no stops, maintaining conversation'\n"
            . "  GOOD: 'Maintain RHR <60 bpm and BP <120/80'\n"
            . "  BAD:  'Maintain consistent supplement routine with 95%% adherence' (meta-goal, not health outcome)\n"
            . "  BAD:  'Teach and demonstrate healthy habits to family for 12 months' (vague, unassessable)\n"
            . "  BAD:  'Hike 8km on varied terrain' (terrain undefined — could be flat or 1000m climb)\n"
            . "- 10+ year goal: ALSO measurable. Replace narrative paragraphs with 3-4 measurable\n"
            . "  targets that capture the WHY. NOT 'be the energetic active presence who can keep up\n"
            . "  with grandchildren on adventures' — instead 'At 65: complete 20km hike with 800m\n"
            . "  ascent · perform 30 grandchild-pickup squats · maintain RHR <60 bpm · sleep 7.5+\n"
            . "  hours nightly without alarm'.\n"
            . "- NO meta-goals (adherence percentages, teaching others, demonstrating habits).\n"
            . "- NO vague qualifiers (varied terrain, regularly, consistent — define the number).\n"
            . "- Earlier milestones build toward later ones; mix physical capability, health markers,\n"
            . "  and lifestyle habits.\n\n"
            . "Client: Age %s, Rate of ageing: %s\n"
            . "Key weak areas: %s\n"
            . "WHY: %s\n"
            . "Practitioner priority areas: %s\n\n"
            . "Return ONLY valid JSON:\n"
            . "{\n"
            . "  \"six_months\": [{ \"milestone\": \"...\", \"category\": \"...\", \"measurable\": true }],\n"
            . "  \"two_years\": [...],\n"
            . "  \"five_years\": [...],\n"
            . "  \"ten_plus_years\": [{ \"milestone\": \"...\", \"category\": \"...\", \"measurable\": true }]\n"
            . "}\n"
            . "3-4 milestones per interval (10+ years: 3-4 measurable targets, NOT a single narrative\n"
            . "paragraph). Every measurable=true. No markdown fences.",
            $age, $rate,
            implode( ', ', $weak ) ?: 'none identified',
            $why_profile['distilled_why'] ?? 'Not provided',
            implode( ', ', array_unique( $rec_cats ) ) ?: 'general health'
        );

        $system = 'You are generating personalised health milestones for a longevity client. '
            . 'v0.34.0 RULE: every milestone — including the 10+ year goal — MUST contain a '
            . 'measurable target with a number and a unit. No meta-goals. No vague qualifiers. '
            . 'No narrative paragraphs as milestones. The WHY shapes WHICH metrics to target, not '
            . 'whether to be measurable. If they mention grandchildren, the milestone might be '
            . '"perform 30 grandchild-pickup squats at 65" — the grandchild reference comes through '
            . 'in the metric choice, never as the entire milestone. '
            . 'Always return valid JSON only — no markdown, no code fences.';

        $response = self::call_claude( $key, $system, $user_prompt, 1200 );
        if ( is_wp_error( $response ) ) {
            error_log( '[HDLV2 AI] Milestones failed: ' . $response->get_error_message() );
            return self::milestones_placeholder();
        }

        $parsed = json_decode( $response, true );
        if ( ! $parsed || empty( $parsed['six_months'] ) ) {
            error_log( '[HDLV2 AI] Milestones: invalid JSON response' );
            return self::milestones_placeholder();
        }

        return $parsed;
    }

    // ──────────────────────────────────────────────────────────────
    //  PUBLIC: ORGANISE RAW CONSULTATION NOTES (free-form text + transcription)
    //  → structured JSON. Per Matthew's spec, April 2026.
    //
    //  Replaces the old per-recommendation category dropdown UI. The
    //  practitioner writes everything in one place; AI splits it into
    //  health_summary / health_history / recommendations[] /
    //  follow_up_actions / additional_notes, inferring category /
    //  priority / frequency for each recommendation.
    // ──────────────────────────────────────────────────────────────

    public static function organise_consultation_notes( $raw_notes ) {
        $api_key = self::get_api_key();
        if ( ! $api_key ) {
            return new WP_Error( 'no_api_key', 'HDLV2_ANTHROPIC_API_KEY not configured.' );
        }

        $raw_notes = trim( (string) $raw_notes );
        if ( $raw_notes === '' ) {
            return new WP_Error( 'empty_input', 'No consultation notes to organise.' );
        }

        $system = "You are a clinical consultation notes organiser for a holistic health practitioner platform.\n\n"
            . "You will receive raw, unstructured consultation notes — these may be a mix of typed notes and transcribed audio from a practitioner's session with their client. The notes may be messy, conversational, and jump between topics. Your job is to organise them into clean, structured sections WITHOUT losing any detail or nuance.\n\n"
            . "RULES:\n"
            . "- Preserve the practitioner's exact meaning. Do not reinterpret, soften, or generalise.\n"
            . "- Do not invent information that isn't in the notes.\n"
            . "- If something is ambiguous or unclear, include it with a [UNCLEAR] flag rather than omitting it.\n"
            . "- Use plain, professional English. British English spelling.\n"
            . "- Every piece of information from the input must appear in exactly one section of the output. Nothing gets dropped.\n\n"
            . "Organise the notes into these sections:\n\n"
            . "## Client Health Summary\n"
            . "Current health status, symptoms, complaints, and observations noted during the consultation. Include any measurements, test results, or physical observations mentioned.\n\n"
            . "## Health History\n"
            . "Any historical health information discussed — past conditions, family history, previous treatments, lifestyle history, relevant life events.\n\n"
            . "## Recommendations\n"
            . "**MANDATORY: You MUST return at least ONE recommendation. Empty arrays are forbidden.** If the practitioner's notes contain no explicit action items (e.g. just praise, observations, or status updates), infer the single most-likely action from the lowest-scoring lifestyle metrics in their notes and emit it with category='Inferred (review)'. Never return `recommendations: []`.\n\n"
            . "Every recommendation, suggestion, or instruction given to the client. Write each as a CONCRETE NEXT-DAY ACTION the client could do tomorrow morning — not a strategy headline. For each recommendation, infer and add:\n"
            . "- **Category**: One of [Nutrition, Movement, Supplements, Lifestyle, Mental/Emotional, Sleep, Testing, Referral, Other]\n"
            . "- **Priority**: High / Medium / Low (infer from the practitioner's emphasis and language)\n"
            . "- **Frequency**: How often (daily, weekly, one-off, ongoing, etc.) — write \"Not specified\" if unclear\n\n"
            . "CONCRETE-ACTION RULE (HARD): each rec text must pass the \"could the client do this tomorrow morning?\" test.\n"
            . "- GOOD: \"After dinner, brush your teeth — kitchen closes for the night.\"\n"
            . "- GOOD: \"Drink 2 L of water spaced across the day. Refill a 500 mL bottle four times.\"\n"
            . "- GOOD: \"Try sigh-breathing twice a day — two short inhales through the nose, then a long exhale, for 90 seconds.\"\n"
            . "- BAD: \"Focus on body composition management through targeted behaviour change.\" (slogan, no concrete action)\n"
            . "- BAD: \"Address sleep hygiene.\" (vague, no specific behaviour)\n"
            . "- BAD: \"Improve nutrition consistency.\" (no instruction)\n"
            . "Each rec must include: (1) a specific behaviour the client can perform, (2) a target or frequency embedded in the sentence, (3) optionally one short sentence of WHY (which score it lifts) at the end. Keep under 35 words. No metaphors. No \"work on\". No \"manage\". Use imperative voice.\n\n"
            . "IMPORTANT: The practitioner did NOT select these categories themselves — you are inferring them from the content. If a recommendation spans multiple categories, assign the primary one and note the secondary in brackets, e.g. \"Category: Nutrition (also Movement)\".\n\n"
            . "## Follow-Up Actions\n"
            . "Any next steps, follow-up appointments, tests to book, referrals to make, or things the practitioner needs to do after the consultation.\n\n"
            . "## Additional Notes\n"
            . "Anything that doesn't fit neatly into the above sections but was mentioned during the consultation and should be recorded.\n\n"
            . "If a section would be empty, omit it entirely.\n\n"
            . "TEXT FORMATTING for health_summary, health_history, and additional_notes (the three free-text sections):\n"
            . "- Write in short paragraphs separated by a blank line. Inside a JSON string, that means embedding two newline characters between paragraphs.\n"
            . "- When listing observations, symptoms, measurements, or items of any kind, write each one on its own line and prefix each with `- ` (a hyphen and a single space). Group related bullets under a one-line introductory sentence.\n"
            . "- Use line breaks generously. The practitioner reads this on screen and needs to scan it quickly. Walls of unbroken text are hard to read.\n\n"
            . "EXAMPLE of a well-formatted health_summary value (note the embedded \\n\\n paragraph breaks and `- ` bullet prefixes):\n"
            . '"The client reports persistent fatigue and difficulty sleeping over the past three months.\n\nKey observations during this consultation:\n- Resting heart rate elevated at 78 bpm\n- Visible signs of stress (jaw tension, shallow breathing)\n- BMI within healthy range at 23.4\n\nThe client mentioned recent work stress as a likely contributing factor."'
            . "\n\nIf a section would be empty, omit it entirely.\n\n"
            . "Return your response as a single JSON object with this exact shape (omit any section whose value would be empty):\n"
            . '{"health_summary":"plain text","health_history":"plain text","recommendations":[{"text":"...","category":"Nutrition","secondary_category":"Movement or empty","priority":"High|Medium|Low","frequency":"Daily|Weekly|...|Not specified"}],"follow_up_actions":["...","..."],"additional_notes":"plain text"}'
            . "\n\nReturn JSON only — no preamble, no markdown fences.";

        $user = "Raw consultation notes to organise:\n\n" . $raw_notes;

        $raw = self::call_claude( $api_key, $system, $user, 4000 );
        if ( is_wp_error( $raw ) ) {
            return $raw;
        }

        $parsed = json_decode( $raw, true );
        if ( ! is_array( $parsed ) ) {
            error_log( '[HDLV2] organise_consultation_notes: Claude returned non-JSON. Raw: ' . substr( $raw, 0, 500 ) );
            return new WP_Error( 'parse_failed', 'AI returned an unparseable response. Please try again.' );
        }

        // Light normalisation — guarantee the keys the consumer expects.
        $parsed['health_summary']     = isset( $parsed['health_summary'] )     ? (string) $parsed['health_summary']     : '';
        $parsed['health_history']     = isset( $parsed['health_history'] )     ? (string) $parsed['health_history']     : '';
        $parsed['recommendations']    = isset( $parsed['recommendations'] )    && is_array( $parsed['recommendations'] )    ? array_values( $parsed['recommendations'] ) : array();
        $parsed['follow_up_actions']  = isset( $parsed['follow_up_actions'] )  && is_array( $parsed['follow_up_actions'] )  ? array_values( $parsed['follow_up_actions'] ) : array();
        $parsed['additional_notes']   = isset( $parsed['additional_notes'] )   ? (string) $parsed['additional_notes']   : '';

        // v0.33.5 — Empty-recs retry. The system prompt forbids empty
        // recommendations[], but Claude occasionally still returns [] on
        // sparse input (praise-only notes, very short text). Retry once
        // with a stricter top-up message that explicitly forbids the empty
        // result. This catches the "Kim 2026-05-05 empty Page 13" failure
        // mode at the AI layer instead of at the Final Report guard.
        if ( empty( $parsed['recommendations'] ) ) {
            error_log( '[HDLV2 organise] empty recommendations[] on first attempt — retrying with stricter prompt' );
            $stricter_system = $system
                . "\n\n=== RETRY DIRECTIVE ===\n"
                . "Your previous response returned an empty recommendations array. This is forbidden. "
                . "Re-read the notes and emit at least ONE concrete action. If the notes contain only "
                . "observations, status updates, or praise, infer the single most-likely action from "
                . "the lifestyle metrics implied (e.g. 'physical activity below target' → recommend a "
                . "20-minute daily walk; 'evening grazing' → kitchen-closes-after-dinner habit). Mark "
                . "any inferred recommendation with category='Inferred (review)' so the practitioner "
                . "can edit or remove it. The recommendations array must have length >= 1.";
            $retry_raw = self::call_claude( $api_key, $stricter_system, $user, 4000 );
            if ( ! is_wp_error( $retry_raw ) ) {
                $retry_parsed = json_decode( $retry_raw, true );
                if ( is_array( $retry_parsed ) && ! empty( $retry_parsed['recommendations'] ) && is_array( $retry_parsed['recommendations'] ) ) {
                    $parsed['recommendations'] = array_values( $retry_parsed['recommendations'] );
                    error_log( sprintf( '[HDLV2 organise] retry succeeded with %d recs', count( $parsed['recommendations'] ) ) );
                } else {
                    error_log( '[HDLV2 organise] retry also returned empty/invalid — practitioner will need to add manually' );
                }
            }
        }

        return $parsed;
    }

    // ──────────────────────────────────────────────────────────────
    //  PUBLIC: MERGE CONSULTATION + ADDENDA (v0.28.0)
    //
    //  Pure transform — no Claude call. Builds an augmented consultation
    //  notes string that prepends the existing typed/organised consultation
    //  with a chronologically-ordered block of practitioner Addenda. The
    //  augmented string is fed to generate_final_report() as $consultation_notes.
    //
    //  Why this exists: each Addendum is a timestamped clinical observation
    //  the practitioner adds between the original consultation and the next
    //  quarterly Part (e.g. "client called, allergic to tomatoes — pulled
    //  from diet recommendations"). Claude needs to see these in chronological
    //  order with priority markers so the latest addendum can override or
    //  refine recommendations from the original. Older addenda are
    //  background context; the newest is the highest-priority intelligence.
    //
    //  Returns the original notes unchanged when the addenda array is empty —
    //  so callers can always pipe through this method without branching.
    //
    //  See: hdl-longevity-v2/CONSULTATION-ADDENDA-DESIGN.md.
    // ──────────────────────────────────────────────────────────────

    public static function merge_consultation_with_addenda( $original_notes, $addenda ) {
        $original_notes = is_string( $original_notes ) ? trim( $original_notes ) : '';
        if ( ! is_array( $addenda ) || empty( $addenda ) ) {
            return $original_notes;
        }

        $blocks = array();
        if ( $original_notes !== '' ) {
            $blocks[] = "## Original Consultation Notes\n" . $original_notes;
        }

        $addenda_lines = array();
        $addenda_lines[] = "## Practitioner Addenda — chronological (oldest first; latest is highest-priority intelligence)";
        $addenda_lines[] = '';
        $i = 0;
        foreach ( $addenda as $a ) {
            $note = trim( wp_strip_all_tags( $a['note_text'] ?? '' ) );
            if ( $note === '' ) continue;
            $i++;
            $occurred = ! empty( $a['occurred_at'] ) ? strtotime( $a['occurred_at'] ) : false;
            $when = $occurred ? gmdate( 'j M Y · H:i', $occurred ) : 'unknown date';
            $priority = strtoupper( in_array( $a['priority'] ?? 'medium', array( 'low', 'medium', 'high' ), true ) ? $a['priority'] : 'medium' );
            $addenda_lines[] = sprintf( '[%d] %s · %s priority', $i, $when, $priority );
            $addenda_lines[] = $note;
            $addenda_lines[] = '';
        }
        if ( $i > 0 ) {
            $blocks[] = trim( implode( "\n", $addenda_lines ) );
        }

        // v0.36.2 — Re-issue Instructions rewritten so addenda affect ALL
        // sections of the downstream output (health summary, follow-up
        // actions, recommendations, awaken/lift/thrive narrative), not just
        // recommendations. Prior wording explicitly told Claude "addenda
        // override or refine specific recommendations" + "older addenda are
        // background; do not enumerate them" — that biased the output so
        // the client's "From your practitioner" health_summary stayed frozen
        // at the original consultation, and the practitioner's clinical
        // updates were invisible to the client. Now: addenda are top
        // priority across the whole report, surfaced explicitly with date
        // markers so the client sees them.
        $blocks[] = "## Re-issue Instructions\n"
            . "- Treat the LATEST addendum as the highest-priority intelligence. The original consultation establishes the baseline; addenda are post-consultation clinical updates that override AND refine the report across ALL sections — health summary, follow-up actions, recommendations, and the awaken/lift/thrive narrative.\n"
            . "- The client will SEE addendum content in their report's practitioner-curated summary. Surface every addendum's substantive content explicitly — do not treat any addendum as silent background. Each addendum's observations should be visible to the client somewhere they actually look (health_summary, follow_up_actions, or recommendations).\n"
            . "- When an addendum says to remove or replace something (e.g. \"pulled tomatoes from diet recommendations\"), drop the removed item from recommendations AND note the change explicitly as a recent update so the client understands what shifted.\n"
            . "- Multiple addenda integrate chronologically. The latest wins where they conflict; earlier addenda inform context.\n"
            . "- Preserve the practitioner's wording from the original consultation — the report should READ as the same voice, with timestamped updates layered on top.\n"
            . "- Do not invent numeric values from addenda text. The numeric calc is unchanged from the original Trajectory Plan.";

        return implode( "\n\n", $blocks );
    }

    // ──────────────────────────────────────────────────────────────
    //  PUBLIC: INTEGRATE ADDENDA INTO ORGANISED NOTES (v0.36.2)
    //
    //  Additive integration of un-superseded practitioner addenda into an
    //  EXISTING curated organised-notes JSON structure. Used by the
    //  /save-and-update-plan regenerate path.
    //
    //  Distinct from organise_consultation_notes() — which re-runs from
    //  scratch on raw text and WIPES practitioner inline edits — this method
    //  treats existing_organised as the source of truth and layers the new
    //  addenda on top:
    //
    //    - health_summary gets one new "**Update DD MMM YYYY** — ..." paragraph
    //      appended per addendum so the client sees the chronology of
    //      practitioner observations after the original consultation.
    //    - follow_up_actions gets new entries for any addendum that implies
    //      a next session step.
    //    - recommendations gets new entries for any addendum that contains
    //      a concrete client-facing action; existing recs the addendum says
    //      to remove are dropped.
    //    - health_history, additional_notes are preserved verbatim unless
    //      the addendum explicitly corrects historical fact.
    //
    //  Caller is expected to pass ONLY un-integrated addenda (those with
    //  superseded_by_report_id IS NULL) — passing already-integrated addenda
    //  would duplicate "Update" paragraphs in health_summary. The current
    //  caller (HDLV2_Final_Report::regenerate) filters by that column.
    //
    //  Returns the integrated array on success, or WP_Error on Claude
    //  failure. Caller is responsible for the fallback path (typically:
    //  fall back to organise_consultation_notes(raw + addenda) so the
    //  addendum at least reaches the recommendations section).
    //
    //  Why a separate method instead of extending organise_consultation_notes:
    //  organise_consultation_notes is the "fresh organisation" entry point
    //  used by the practitioner's "Organise these notes" button and the
    //  initial finalise path — both want a from-scratch pass over raw text.
    //  Integration has different invariants (preserve practitioner edits,
    //  surface addenda chronologically, no destructive rewrite) and a
    //  separate prompt makes those invariants explicit.
    // ──────────────────────────────────────────────────────────────

    public static function integrate_addenda_into_organised( $existing_organised, $new_addenda ) {
        $api_key = self::get_api_key();
        if ( ! $api_key ) {
            return new WP_Error( 'no_api_key', 'HDLV2_ANTHROPIC_API_KEY not configured.' );
        }
        if ( ! is_array( $existing_organised ) ) {
            return new WP_Error( 'invalid_input', 'existing_organised must be an array.' );
        }
        if ( ! is_array( $new_addenda ) || empty( $new_addenda ) ) {
            return new WP_Error( 'no_addenda', 'No new addenda to integrate.' );
        }

        // Build the addenda block with explicit Update headings so Claude
        // can surface them verbatim in health_summary if it chooses.
        $addenda_text_blocks = array();
        $i = 0;
        foreach ( $new_addenda as $a ) {
            $note = trim( wp_strip_all_tags( $a['note_text'] ?? '' ) );
            if ( $note === '' ) continue;
            $i++;
            $occurred = ! empty( $a['occurred_at'] ) ? strtotime( $a['occurred_at'] ) : false;
            $when     = $occurred ? gmdate( 'j M Y', $occurred ) : 'unknown date';
            $priority = strtoupper( in_array( $a['priority'] ?? 'medium', array( 'low', 'medium', 'high' ), true ) ? $a['priority'] : 'medium' );
            $addenda_text_blocks[] = sprintf( "Addendum %d · %s · %s priority\n%s", $i, $when, $priority, $note );
        }
        if ( empty( $addenda_text_blocks ) ) {
            return new WP_Error( 'empty_addenda', 'All addenda were empty after sanitisation.' );
        }

        $existing_json = wp_json_encode( $existing_organised, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $addenda_block = implode( "\n\n", $addenda_text_blocks );

        $system = "You integrate new practitioner Addenda (timestamped clinical updates added AFTER the original consultation) into an existing curated consultation summary. The client will SEE the integrated summary on their Trajectory Plan's \"From your practitioner\" section, so addenda must be visible to the client — never silent background.\n\n"
            . "PRINCIPLES:\n"
            . "1. ADDITIVE — never delete or rewrite the practitioner's existing wording in health_summary, health_history, follow_up_actions, recommendations, or additional_notes. The original is preserved verbatim.\n"
            . "2. PRIORITISE THE ADDENDUM — addenda are the highest-priority intelligence. The latest addendum wins where it conflicts with earlier addenda or the original consultation.\n"
            . "3. SURFACE TO THE CLIENT — every addendum's substantive content must appear somewhere the client looks: health_summary, follow_up_actions, or recommendations. Do not bury an addendum in additional_notes alone.\n"
            . "4. TIMESTAMP IN health_summary — for each addendum, append ONE new paragraph at the END of health_summary in this exact format: \"**Update DD MMM YYYY** — <one or two sentences summarising the addendum in the practitioner's voice>\". Use the addendum's date as printed in the input. The client should see a clear chronology of post-consultation updates.\n"
            . "5. ACTIONS GO INTO follow_up_actions AND/OR recommendations — if the addendum implies a next step the practitioner needs to take, add to follow_up_actions. If it implies a client-facing action, add to recommendations. An addendum can populate both.\n"
            . "6. REMOVALS — when an addendum says to remove/replace something (e.g. \"pulled tomatoes from diet recommendations\"), drop the matching item from recommendations AND mention the removal in the Update paragraph in health_summary so the client understands what changed.\n"
            . "7. RECOMMENDATION FORMAT — any new recommendation entry must be a concrete tomorrow-morning action under 35 words, imperative voice, with category from [Nutrition, Movement, Supplements, Lifestyle, Mental/Emotional, Sleep, Testing, Referral, Other], priority High|Medium|Low (mirror the addendum's priority where reasonable), and frequency (\"Daily|Weekly|Ongoing|One-off|Not specified\"). Same rules as organise_consultation_notes.\n"
            . "8. NEW MEDICAL FACTS go into health_summary as Update paragraphs, NOT health_history. health_history is the historical context discussed in the original consultation; addenda are present-tense clinical updates.\n"
            . "9. PRESERVE PARAGRAPH BREAKS in existing free-text fields. Embed `\\n\\n` between paragraphs in JSON strings.\n"
            . "10. British English spelling. Plain, professional tone matching the existing summary.\n\n"
            . "OUTPUT — strict JSON, same shape as input existing_organised:\n"
            . '{"health_summary":"<original text + new Update paragraphs>","health_history":"<original, unchanged unless an addendum corrects it>","recommendations":[<original recs minus removals + new from addenda>],"follow_up_actions":[<original + new from addenda>],"additional_notes":"<original, plus any addendum content that genuinely fits nowhere else>"}'
            . "\n\nReturn JSON only — no preamble, no markdown fences.";

        $user = "EXISTING CURATED CONSULTATION SUMMARY (preserve verbatim, then layer addenda on top):\n\n"
            . $existing_json
            . "\n\n"
            . "NEW ADDENDA TO INTEGRATE (chronological, oldest first; latest is highest-priority intelligence):\n\n"
            . $addenda_block
            . "\n\nIntegrate the addenda into the existing summary per the principles. Return the merged JSON.";

        $raw = self::call_claude( $api_key, $system, $user, 4500 );
        if ( is_wp_error( $raw ) ) {
            return $raw;
        }

        $parsed = json_decode( $raw, true );
        if ( ! is_array( $parsed ) ) {
            error_log( '[HDLV2] integrate_addenda_into_organised: Claude returned non-JSON. Raw: ' . substr( $raw, 0, 500 ) );
            return new WP_Error( 'parse_failed', 'AI returned an unparseable response. Please try again.' );
        }

        // Normalise — guarantee the keys the consumer expects, fall back
        // to the existing organised value if Claude dropped a section.
        $parsed['health_summary']    = isset( $parsed['health_summary'] ) && is_string( $parsed['health_summary'] )
            ? $parsed['health_summary']
            : (string) ( $existing_organised['health_summary'] ?? '' );
        $parsed['health_history']    = isset( $parsed['health_history'] ) && is_string( $parsed['health_history'] )
            ? $parsed['health_history']
            : (string) ( $existing_organised['health_history'] ?? '' );
        $parsed['recommendations']   = isset( $parsed['recommendations'] ) && is_array( $parsed['recommendations'] )
            ? array_values( $parsed['recommendations'] )
            : ( is_array( $existing_organised['recommendations'] ?? null ) ? array_values( $existing_organised['recommendations'] ) : array() );
        $parsed['follow_up_actions'] = isset( $parsed['follow_up_actions'] ) && is_array( $parsed['follow_up_actions'] )
            ? array_values( $parsed['follow_up_actions'] )
            : ( is_array( $existing_organised['follow_up_actions'] ?? null ) ? array_values( $existing_organised['follow_up_actions'] ) : array() );
        $parsed['additional_notes']  = isset( $parsed['additional_notes'] ) && is_string( $parsed['additional_notes'] )
            ? $parsed['additional_notes']
            : (string) ( $existing_organised['additional_notes'] ?? '' );

        // Safety: if Claude returned empty recommendations but the existing
        // had some, keep the existing (additive principle — never lose
        // practitioner-curated recs through an integration step).
        if ( empty( $parsed['recommendations'] ) && ! empty( $existing_organised['recommendations'] ) && is_array( $existing_organised['recommendations'] ) ) {
            error_log( '[HDLV2 integrate] Claude dropped recommendations to []; restoring from existing organised to honour additive principle' );
            $parsed['recommendations'] = array_values( $existing_organised['recommendations'] );
        }

        return $parsed;
    }

    // ──────────────────────────────────────────────────────────────
    //  PUBLIC: PRE-CONSULTATION CLIENT BRIEF (v0.15.3)
    //  Practitioner-facing snapshot generated from form data + scores BEFORE
    //  the consultation. Same JSON shape as organise_consultation_notes so
    //  the review-panel renderer can reuse. British English, clinical,
    //  factual — no invented information.
    // ──────────────────────────────────────────────────────────────
    public static function generate_pre_consultation_summary( $stage1_data, $stage3_data, $calc_result, $why_profile, $addenda = array() ) {
        $api_key = self::get_api_key();
        if ( ! $api_key ) {
            return new WP_Error( 'no_api_key', 'HDLV2_ANTHROPIC_API_KEY not configured.' );
        }

        $age    = $stage1_data['q1_age'] ?? $stage1_data['age'] ?? 'unknown';
        $sex    = $stage1_data['q1_sex'] ?? $stage1_data['gender'] ?? 'unknown';
        $rate   = isset( $calc_result['rate'] ) ? $calc_result['rate'] : null;
        $bioage = isset( $calc_result['bio_age'] ) ? $calc_result['bio_age'] : null;
        $scores = isset( $calc_result['scores'] ) && is_array( $calc_result['scores'] ) ? $calc_result['scores'] : array();
        $why    = is_array( $why_profile ) ? ( $why_profile['distilled_why'] ?? '' ) : '';

        $facts = "CLIENT DATA\nAge: $age\nSex: $sex\n";
        if ( $rate !== null )   $facts .= "Rate of ageing: $rate\n";
        if ( $bioage !== null ) $facts .= "Biological age: $bioage\n";
        if ( $why )             $facts .= "Client WHY: $why\n";
        if ( ! empty( $stage3_data ) ) {
            $facts .= "\nHEALTH INPUTS\n";
            foreach ( $stage3_data as $k => $v ) {
                if ( $v === '' || $v === null || $v === 'skip' || $k === 'server_result' ) continue;
                $facts .= "- $k: $v\n";
            }
        }
        if ( ! empty( $scores ) ) {
            $facts .= "\nDERIVED SCORES (0-5 scale, higher is better)\n";
            foreach ( $scores as $k => $v ) {
                if ( ! is_numeric( $v ) ) continue;
                $facts .= "- $k: $v\n";
            }
        }

        // v0.33.0 — Append practitioner addenda (post-Final clinical
        // observations) so the Brief stays in sync with the latest plan
        // state. Without this, the Brief snapshots only the pre-consultation
        // form data and goes stale after the first addendum lands. Each
        // addendum carries note_text + priority + occurred_at; we surface
        // them chronologically (oldest first; latest is highest-priority
        // intelligence) — same convention as merge_consultation_with_addenda().
        $has_addenda = false;
        if ( is_array( $addenda ) && ! empty( $addenda ) ) {
            $lines = array();
            $i = 0;
            foreach ( $addenda as $a ) {
                $note = trim( wp_strip_all_tags( $a['note_text'] ?? '' ) );
                if ( $note === '' ) continue;
                $i++;
                $when_ts = ! empty( $a['occurred_at'] ) ? strtotime( $a['occurred_at'] ) : false;
                $when    = $when_ts ? gmdate( 'j M Y · H:i', $when_ts ) : 'unknown date';
                $pri     = strtoupper( in_array( $a['priority'] ?? 'medium', array( 'low', 'medium', 'high' ), true ) ? $a['priority'] : 'medium' );
                $lines[] = sprintf( '[%d] %s · %s priority — %s', $i, $when, $pri, $note );
            }
            if ( ! empty( $lines ) ) {
                $facts .= "\nPRACTITIONER ADDENDA — chronological clinical observations added since the consultation. Latest is highest-priority intelligence; treat older entries as context.\n";
                $facts .= implode( "\n", $lines ) . "\n";
                $has_addenda = true;
            }
        }

        $system = "You are briefing a holistic health practitioner BEFORE their consultation with a client. The practitioner has not yet met the client — they only have the client's self-reported health form data and derived scores. Your job is to give the practitioner a clear, factual, clinically-useful snapshot so they walk into the consultation with context.\n\n"
            . "RULES:\n"
            . "- Work only from the data provided. Do not invent facts.\n"
            . "- British English. Professional, non-alarmist tone.\n"
            . "- Flag concerning scores (<=2 on the 0-5 scale) and praise strong ones (>=4).\n"
            . "- Recommendations are STARTING POINTS the practitioner may accept, modify, or reject. Keep them general, not prescriptive. Do not diagnose.\n"
            . "- CONCRETE-ACTION RULE (HARD): each recommendation MUST pass the \"could the client do this tomorrow morning?\" test. Specific behaviour + target/frequency + optional one-line WHY. Under 35 words. Imperative voice. Examples — GOOD: \"Drink 2 L of water spaced across the day. Refill a 500 mL bottle four times.\" BAD: \"Address hydration.\" Slogans, vague verbs (work on / focus on / manage), and strategy headlines are forbidden.\n"
            . "- Follow-up actions are data gaps or concerning scores that warrant investigation or rechecking.\n"
            . ( $has_addenda
                ? "- ADDENDA INTEGRATION: when PRACTITIONER ADDENDA are present, this is no longer a pre-consultation brief — it is a current snapshot. Treat addenda as the most authoritative signal: if an addendum says the client is allergic to something, do NOT recommend that food. If an addendum updates a behaviour, do NOT contradict it. Surface the LATEST addendum's content prominently in health_summary. Synthesize recommendations + follow-ups across the original form data AND the addendum chain.\n"
                : ""
              )
            . "\n"
            . "TEXT FORMATTING for health_summary, health_history, and additional_notes:\n"
            . "- Write short paragraphs separated by a blank line (two newline characters embedded inside the JSON string value).\n"
            . "- When listing observations, scores, or items, put each on its own line prefixed with `- `.\n"
            . "- Use line breaks generously so the practitioner can scan the brief quickly.\n\n"
            . "EXAMPLE of a well-formatted health_summary value:\n"
            . '"This is a 52-year-old male presenting a rate of ageing of 1.18 (slightly accelerated).\n\nStrong areas:\n- Movement and physical activity (score 4)\n- Sleep quality (score 4)\n\nAreas of concern:\n- Stress management (score 1)\n- Nutrition consistency (score 2)\n\nClient WHY: wants to be present for grandchildren in twenty years."'
            . "\n\nReturn a single JSON object with this exact shape (omit any key whose value is empty):\n"
            . '{"health_summary":"plain text","health_history":"plain text","recommendations":[{"text":"...","category":"Nutrition|Movement|Supplements|Lifestyle|Mental/Emotional|Sleep|Testing|Referral|Other","secondary_category":"optional","priority":"High|Medium|Low","frequency":"Daily|Weekly|One-off|Ongoing|Not specified"}],"follow_up_actions":["...","..."],"additional_notes":"plain text"}'
            . "\n\nReturn JSON only — no preamble, no markdown fences.";

        $raw = self::call_claude( $api_key, $system, $facts, 3000 );
        if ( is_wp_error( $raw ) ) return $raw;

        $parsed = json_decode( $raw, true );
        if ( ! is_array( $parsed ) ) {
            error_log( '[HDLV2] generate_pre_consultation_summary: non-JSON. Raw: ' . substr( $raw, 0, 300 ) );
            return new WP_Error( 'parse_failed', 'Could not parse the client brief.' );
        }
        $parsed['health_summary']    = isset( $parsed['health_summary'] )    ? (string) $parsed['health_summary']    : '';
        $parsed['health_history']    = isset( $parsed['health_history'] )    ? (string) $parsed['health_history']    : '';
        $parsed['recommendations']   = isset( $parsed['recommendations'] )   && is_array( $parsed['recommendations'] )   ? array_values( $parsed['recommendations'] ) : array();
        $parsed['follow_up_actions'] = isset( $parsed['follow_up_actions'] ) && is_array( $parsed['follow_up_actions'] ) ? array_values( $parsed['follow_up_actions'] ) : array();
        $parsed['additional_notes']  = isset( $parsed['additional_notes'] )  ? (string) $parsed['additional_notes']  : '';
        return $parsed;
    }

    // ──────────────────────────────────────────────────────────────
    //  PUBLIC: STAGE 3 "WHAT THIS TELLS US" COMMENTARY  (v0.22.4)
    //
    //  Bridges all three stages: contrasts Stage 1 estimated rate vs
    //  Stage 3 measured rate, names actual top strengths + focus areas
    //  from the 21-metric panel, and ties back to the Stage 2 WHY when
    //  available. Returned as HTML and cached in stage3_data.commentary_html.
    //  Make.com/Sonnet still generates the deeper awaken/lift/thrive for
    //  the full Longevity Report — this is the immediate post-submit read.
    // ──────────────────────────────────────────────────────────────

    /**
     * @param array  $stage1_data  q1_age, q1_sex, q3..q9 etc.
     * @param array  $stage2_data  distilled_why, vision_text, motivations
     * @param array  $stage3_data  full health detail (height, weight, scores, etc.)
     * @param array  $result       Output of HDLV2_Rate_Calculator::calculate_full
     * @param string $client_name  First name (or empty).
     * @return string|null         HTML string of <p> paragraphs, or null on failure.
     */
    public static function generate_stage3_commentary( $stage1_data, $stage2_data, $stage3_data, $result, $client_name = '' ) {
        $key = self::get_api_key();
        if ( ! $key ) {
            return null;
        }

        $age           = (int) ( $stage1_data['q1_age'] ?? 0 );
        $sex           = $stage1_data['q1_sex'] ?? '';
        $rate_now      = (float) ( $result['rate'] ?? 1.0 );
        $bio_age       = (float) ( $result['bio_age'] ?? ( $age * $rate_now ) );
        $diff          = $age > 0 ? round( $bio_age - $age, 1 ) : null;

        $stage1_result = $stage1_data['server_result'] ?? array();
        $rate_stage1   = isset( $stage1_result['rate'] ) ? (float) $stage1_result['rate'] : null;

        if ( $rate_now <= 0.95 )      { $band = 'Slower than average'; }
        elseif ( $rate_now <= 1.05 )  { $band = 'On pace with average'; }
        else                           { $band = 'Faster than average'; }

        $why = trim( (string) ( $stage2_data['distilled_why'] ?? '' ) );

        // Sort scores into strengths (>=4) + focus areas (<=2). Names get
        // humanised so Haiku doesn't write things like "physicalActivity".
        $human_names = array(
            'physicalActivity'   => 'Physical Activity',
            'sitToStand'         => 'Sit-to-Stand',
            'breathHold'         => 'Breath Hold',
            'balance'            => 'Balance',
            'sleepDuration'      => 'Sleep Duration',
            'sleepQuality'       => 'Sleep Quality',
            'stressLevels'       => 'Stress Levels',
            'socialConnections'  => 'Social Connections',
            'dietQuality'        => 'Diet Quality',
            'alcoholConsumption' => 'Alcohol',
            'smokingStatus'      => 'Smoking',
            'cognitiveActivity'  => 'Cognitive Activity',
            'sunlightExposure'   => 'Sunlight Exposure',
            'supplementIntake'   => 'Supplements',
            'dailyHydration'     => 'Hydration',
            'skinElasticity'     => 'Skin Elasticity',
            'bmiScore'           => 'BMI',
            'whrScore'           => 'Waist-Hip Ratio',
            'whtrScore'          => 'Waist-Height Ratio',
            'bloodPressureScore' => 'Blood Pressure',
            'heartRateScore'     => 'Resting Heart Rate',
            // v0.23.1 — overallHealthScore removed (Matthew 2026-04-28).
        );

        $strengths = array();
        $focus     = array();
        foreach ( ( $result['scores'] ?? array() ) as $k => $v ) {
            if ( ! is_numeric( $v ) ) continue;
            $label = $human_names[ $k ] ?? $k;
            if ( $v >= 4 ) $strengths[] = "$label ($v/5)";
            if ( $v <= 2 ) $focus[]     = "$label ($v/5)";
        }
        $strengths_text = $strengths ? implode( ', ', array_slice( $strengths, 0, 5 ) ) : 'none clearly above-average';
        $focus_text     = $focus     ? implode( ', ', array_slice( $focus,     0, 5 ) ) : 'none in the focus band';

        $name        = trim( (string) $client_name );
        $name_first  = $name ? strtok( $name, ' ' ) : '';

        $system = 'You are a longevity coach writing a personalised "What This Tells Us" commentary for a client who has just completed Stage 3 (the full measurement layer). '
            . 'Tone: warm, direct, evidence-grounded, brutally honest but encouraging. Never alarmist. Second person ("you"). British English. '
            . 'Reference actual scores by name and value. A 2/5 is a focus area, not an emergency. 1/5 is critical. '
            . 'Always return valid JSON only — no markdown, no code fences.';

        $user_prompt = sprintf(
              "A client just completed Stage 3 — the full health-detail measurement panel. "
            . "Stage 1 was a 9-question estimate. Stage 2 captured their WHY. Stage 3 produced real numbers for the 21-metric panel.\n\n"
            . "=== CLIENT ===\n"
            . "First name: %s\n"
            . "Chronological age: %d\n"
            . "Sex: %s\n\n"
            . "=== RATE OF AGEING ===\n"
            . "Stage 1 estimate: %s\n"
            . "Stage 3 measured: %s× (%s)\n"
            . "Biological age: %s years (%s vs chronological)\n\n"
            . "=== STAGE 2 WHY ===\n"
            . "%s\n\n"
            . "=== STAGE 3 SCORES (out of 5) ===\n"
            . "Top strengths (>=4): %s\n"
            . "Focus areas (<=2):    %s\n\n"
            . "=== WRITING RULES ===\n"
            . "- Write 3 short paragraphs as ONE HTML string with <p> tags.\n"
            . "- Para 1: open with <strong>...</strong> sentence naming %s and stating the rate finding (note Stage 1 vs Stage 3 contrast if interesting).\n"
            . "- Para 2: honest read of THEIR scores. Pick 1-2 specific strengths and 1-2 focus areas BY NAME + VALUE from the lists above. Use prose, not bullets.\n"
            . "- Para 3: bridge to their WHY (use it if non-empty, otherwise to next steps). Mention practitioner consultation + Trajectory Plan.\n"
            . "- Total 130-200 words.\n"
            . "- British English. Never \"everyone is different\" / \"small changes can make a big difference\" / generic filler.\n"
            . "- No lists or headings inside the paragraphs.\n\n"
            . "Return ONLY: { \"commentary_html\": \"<p>...</p><p>...</p><p>...</p>\" }",
            $name_first ?: '(unknown)',
            $age,
            $sex ?: 'unspecified',
            $rate_stage1 !== null ? number_format( $rate_stage1, 2 ) . '×' : '(not available)',
            number_format( $rate_now, 2 ),
            $band,
            number_format( $bio_age, 1 ),
            $diff === null ? '?' : ( $diff > 0 ? '+' . $diff . ' yrs' : $diff . ' yrs' ),
            $why ?: '(client did not complete Stage 2)',
            $strengths_text,
            $focus_text,
            $name_first ?: 'the client'
        );

        $response = self::call_claude( $key, $system, $user_prompt, 800, self::MODEL_HAIKU );
        if ( is_wp_error( $response ) ) {
            error_log( '[HDLV2 AI] Stage 3 commentary failed: ' . $response->get_error_message() );
            return null;
        }

        $clean  = trim( $response );
        $clean  = preg_replace( '/^```(?:json)?\s*/', '', $clean );
        $clean  = preg_replace( '/\s*```$/', '', $clean );
        $parsed = json_decode( $clean, true );

        if ( ! is_array( $parsed ) || empty( $parsed['commentary_html'] ) ) {
            error_log( '[HDLV2 AI] Stage 3 commentary: invalid JSON — ' . substr( (string) $response, 0, 300 ) );
            return null;
        }

        return wp_kses_post( (string) $parsed['commentary_html'] );
    }

    // ──────────────────────────────────────────────────────────────
    //  PRIVATE: API CALL
    // ──────────────────────────────────────────────────────────────

    private static function call_claude( $api_key, $system, $user_prompt, $max_tokens = 1000, $model = null ) {
        $body = array(
            'model'      => $model ?: self::MODEL,
            'max_tokens' => $max_tokens,
            'system'     => $system,
            'messages'   => array(
                array( 'role' => 'user', 'content' => $user_prompt ),
            ),
        );

        $response = wp_remote_post( self::API_URL, array(
            'headers' => array(
                'x-api-key'         => $api_key,
                'anthropic-version'  => '2023-06-01',
                'content-type'       => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 60,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $err_msg = $body['error']['message'] ?? "HTTP $code";
            return new WP_Error( 'claude_api_error', $err_msg );
        }

        $content = $body['content'][0]['text'] ?? '';
        if ( ! $content ) {
            return new WP_Error( 'claude_empty', 'Empty response from Claude API' );
        }

        // Strip markdown code fences if present (```json ... ```)
        $content = preg_replace( '/^\s*```(?:json)?\s*/i', '', $content );
        $content = preg_replace( '/\s*```\s*$/', '', $content );

        return $content;
    }

    // ──────────────────────────────────────────────────────────────
    //  PRIVATE: HELPERS
    // ──────────────────────────────────────────────────────────────

    private static function get_api_key() {
        return defined( 'HDLV2_ANTHROPIC_API_KEY' ) ? HDLV2_ANTHROPIC_API_KEY : '';
    }

    private static function format_key_people( $kp ) {
        if ( ! is_array( $kp ) || empty( $kp ) ) return '(none provided)';

        $lines = array();
        $groups = array(
            'children'      => 'Children',
            'grandchildren' => 'Grandchildren',
            'partner'       => 'Partner',
            'parents_alive' => 'Parents',
        );

        foreach ( $groups as $key => $label ) {
            if ( ! isset( $kp[ $key ] ) ) continue;
            $item = $kp[ $key ];
            if ( is_array( $item ) && ! empty( $item['has'] ) ) {
                $detail = $label;
                if ( ! empty( $item['count'] ) ) $detail .= ": {$item['count']}";
                if ( ! empty( $item['ages'] ) )  $detail .= " (ages: {$item['ages']})";
                $lines[] = $detail;
            }
        }

        if ( ! empty( $kp['other'] ) ) {
            $lines[] = "Other: " . $kp['other'];
        }

        return $lines ? implode( "\n", $lines ) : '(none provided)';
    }

    private static function why_placeholder() {
        return array(
            'distilled_why'    => 'WHY extraction pending — configure HDLV2_ANTHROPIC_API_KEY in wp-config.php',
            'ai_reformulation' => '<p>Your WHY profile has been saved. AI reformulation will be available once the API is configured.</p>',
        );
    }

    private static function milestones_placeholder() {
        return array(
            'six_months'     => array( array( 'milestone' => 'Milestone generation pending — configure API key', 'category' => 'general', 'measurable' => false ) ),
            'two_years'      => array( array( 'milestone' => 'Milestone generation pending', 'category' => 'general', 'measurable' => false ) ),
            'five_years'     => array( array( 'milestone' => 'Milestone generation pending', 'category' => 'general', 'measurable' => false ) ),
            'ten_plus_years' => array( array( 'milestone' => 'Milestone generation pending', 'category' => 'personal', 'measurable' => false ) ),
        );
    }

    private static function report_placeholder() {
        return array(
            'awaken_content' => '<p>Your current health snapshot has been captured. A detailed AWAKEN analysis will be generated once the AI service is configured.</p>',
            'lift_content'   => '<p>Your personalised action plan will appear here. Your practitioner will review your data and provide targeted recommendations.</p>',
            'thrive_content' => '<p>Your long-term longevity vision will be crafted based on your WHY and health data. Stay tuned.</p>',
        );
    }
}
