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

    const API_URL = 'https://api.anthropic.com/v1/messages';
    const MODEL   = 'claude-sonnet-4-20250514';

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
            . "Create:\n"
            . "1. A one-sentence \"distilled WHY\" (e.g., \"To be fully present and active for my grandchildren into my 90s.\")\n"
            . "2. A warm 2-3 paragraph reformulation that:\n"
            . "   - Identifies the key people and relationships they mentioned\n"
            . "   - Reflects their vision back in empowering language\n"
            . "   - Connects their WHY to the longevity journey ahead\n\n"
            . "Return ONLY valid JSON: { \"distilled_why\": \"...\", \"ai_reformulation\": \"...\" }",
            $vision ?: '(none provided)'
        );

        $system = 'You are a health coaching assistant for HealthDataLab, a longevity platform. '
            . 'Extract and structure a client\'s longevity motivations. Be warm, constructive, '
            . 'and empowering. Always return valid JSON only — no markdown, no code fences.';

        $response = self::call_claude( $key, $system, $user_prompt, 800 );
        if ( is_wp_error( $response ) ) {
            error_log( '[HDLV2 AI] WHY extraction failed: ' . $response->get_error_message() );
            return self::why_placeholder();
        }

        $parsed = json_decode( $response, true );
        if ( ! $parsed || empty( $parsed['distilled_why'] ) ) {
            error_log( '[HDLV2 AI] WHY extraction: invalid JSON response' );
            return self::why_placeholder();
        }

        return array(
            'distilled_why'    => sanitize_text_field( $parsed['distilled_why'] ),
            'ai_reformulation' => wp_kses_post( $parsed['ai_reformulation'] ?? '' ),
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
     * @return array { awaken_content, lift_content, thrive_content } or placeholder.
     */
    public static function generate_draft_report( $calc_result, $stage1_data, $why_profile, $client_name = '' ) {
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

        $user_prompt = sprintf(
            "Generate a personalised longevity draft report.\n\n"
            . "Client: %s, age %s, gender %s\n"
            . "Rate of Ageing: %s (biological age %s vs chronological %s)\n"
            . "WHY: %s\n\n"
            . "Health Scores (0-5, higher = better):\n%s\n"
            . "Top positive factors (score >= 4): %s\n"
            . "Top negative factors (score <= 2): %s\n\n"
            . "Generate three sections as HTML paragraphs (use <p>, <strong>, <ul>, <li> tags):\n\n"
            . "AWAKEN: Current state assessment. Where are they now? What does their biological age mean? "
            . "What is their trajectory if nothing changes? Be honest but compassionate.\n\n"
            . "LIFT: Action plan. Based on their weakest scores, what are the top 3-5 changes that would "
            . "have the most impact? Be specific and practical. Use bullet points.\n\n"
            . "THRIVE: Long-term vision. Connect to their WHY. Paint a picture of what their life looks like "
            . "at 70, 80, 90 if they commit to these changes. Be inspiring.\n\n"
            . "Return ONLY valid JSON: { \"awaken_content\": \"...\", \"lift_content\": \"...\", \"thrive_content\": \"...\" }",
            $client_name ?: 'Client',
            $age, $gender, $rate, $bio_age, $age, $why,
            $scores_text,
            implode( ', ', $positives ) ?: 'none identified',
            implode( ', ', $negatives ) ?: 'none identified'
        );

        $system = 'You are a longevity report writer for HealthDataLab. Generate personalised, evidence-informed '
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

        $user_prompt = sprintf(
            "Generate the FINAL personalised longevity report.\n\n"
            . "CLIENT: %s, age %s\n"
            . "Rate of ageing (practitioner-verified): %s\n"
            . "Biological age: %s\n\n"
            . "DRAFT REPORT:\nAWAKEN: %s\nLIFT: %s\nTHRIVE: %s\n\n"
            . "HEALTH DATA CHANGES (practitioner corrections):\n%s\n"
            . "CONSULTATION NOTES:\n%s\n\n"
            . "PRACTITIONER RECOMMENDATIONS:\n%s\n"
            . "CLIENT'S WHY: %s\n"
            . "CLIENT'S FEARS: %s\n\n"
            . "Generate the FINAL report following the ALT protocol:\n\n"
            . "AWAKEN — Where You Are Now\n"
            . "- Comprehensive analysis with practitioner-verified data\n"
            . "- Note any fields corrected during consultation and why this matters\n"
            . "- Current trajectory if nothing changes\n\n"
            . "LIFT — What Needs to Change\n"
            . "- Incorporate practitioner recommendations (use their structured recommendations)\n"
            . "- For each: explain WHY it matters for THIS client (connect to data + WHY)\n"
            . "- Biggest 2-3 levers for rate improvement\n\n"
            . "THRIVE — What's Possible\n"
            . "- Alternative trajectory if recommendations followed\n"
            . "- Connected to their WHY (use their own words, their people, their fears)\n"
            . "- Identity framing: \"You are becoming someone who...\"\n\n"
            . "Return ONLY valid JSON: { \"awaken_content\": \"...\", \"lift_content\": \"...\", \"thrive_content\": \"...\" }\n"
            . "Use HTML formatting (p, strong, ul, li). British English. No markdown fences.",
            $client_name ?: 'Client', $age, $rate, $bio_age,
            strip_tags( $draft_report['awaken_content'] ?? '' ),
            strip_tags( $draft_report['lift_content'] ?? '' ),
            strip_tags( $draft_report['thrive_content'] ?? '' ),
            $changes_text ?: '(none)',
            $consultation_notes ?: '(none)',
            $recs_text ?: '(none)',
            $why_text,
            $fears ?: '(none identified)'
        );

        $system = 'You are a longevity report writer for HealthDataLab generating the FINAL personalised report. '
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
            . "Rules:\n"
            . "- CONCRETE and MEASURABLE (not \"improve fitness\" but \"walk 5km without stopping\")\n"
            . "- 10+ year goal: vivid, personal, connected to their WHY\n"
            . "- Earlier milestones build toward later ones\n"
            . "- Realistic given starting point\n"
            . "- Mix: physical capabilities, health markers, lifestyle habits, personal meaning\n\n"
            . "Client: Age %s, Rate of ageing: %s\n"
            . "Key weak areas: %s\n"
            . "WHY: %s\n"
            . "Practitioner priority areas: %s\n\n"
            . "Return ONLY valid JSON:\n"
            . "{\n"
            . "  \"six_months\": [{ \"milestone\": \"...\", \"category\": \"...\", \"measurable\": true }],\n"
            . "  \"two_years\": [...],\n"
            . "  \"five_years\": [...],\n"
            . "  \"ten_plus_years\": [{ \"milestone\": \"...\", \"category\": \"personal\", \"measurable\": false }]\n"
            . "}\n"
            . "3-4 milestones per interval (except 10+ years: 1 vivid goal). No markdown fences.",
            $age, $rate,
            implode( ', ', $weak ) ?: 'none identified',
            $why_profile['distilled_why'] ?? 'Not provided',
            implode( ', ', array_unique( $rec_cats ) ) ?: 'general health'
        );

        $system = 'You are generating personalised health milestones for a longevity client. '
            . 'Be concrete, measurable, and personally meaningful. The 10+ year goal must be vivid '
            . 'and connected to their WHY — if they mention grandchildren, reference grandchildren. '
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
    //  PRIVATE: API CALL
    // ──────────────────────────────────────────────────────────────

    private static function call_claude( $api_key, $system, $user_prompt, $max_tokens = 1000 ) {
        $body = array(
            'model'      => self::MODEL,
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
