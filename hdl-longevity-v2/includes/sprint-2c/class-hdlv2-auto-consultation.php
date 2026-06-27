<?php
/**
 * HDL V2 — Automation-tier self-reported consultation shortcode (W8).
 *
 * Destination of the post-Stage-3 routing branch added in W7
 * (HDLV2_Client_Draft_View::render_shortcode). When the feature flag is on
 * AND the current user is on the automation tier, the client lands here
 * after completing Stage 3 instead of waiting for a practitioner.
 *
 * Render: 6 self-reported prompts + textarea + audio recorder + submit.
 * Submit handler (POST /wp-json/hdl-v2/v1/auto-consultation/submit) is
 * registered separately in W9.
 *
 * @package HDL_Longevity_V2
 * @since 0.41.30
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Auto_Consultation {

    /**
     * Default prompts — editable from W13 admin via the hdlv2_automation_tier
     * option (consultation_questions key). Keep this list in sync with W13.
     */
    const DEFAULT_QUESTIONS = array(
        'What are your top three health goals over the next year?',
        "What's the biggest health-related challenge you're facing right now?",
        'Describe your typical day — sleep, meals, movement, stress.',
        'What habits have you tried to change in the past, and what got in the way?',
        'Is there anything about your medical history we should know?',
        'What would success look like for you twelve months from now?',
    );

    public function register_hooks() {
        add_shortcode( 'hdlv2_auto_consultation', array( $this, 'render_shortcode' ) );
        // W9 — submit handler.
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
    }

    public function register_rest_routes() {
        register_rest_route( 'hdl-v2/v1', '/auto-consultation/submit', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_submit' ),
            // __return_true so we always reach the handler — the spec requires
            // the flag check to fire FIRST, before nonce/auth/tier checks.
            // Returning 503 from a flagged-off endpoint regardless of nonce
            // validity matches the W4/W5/W6 pattern (paid-report-provision,
            // validate-token, revoke-token).
            'permission_callback' => '__return_true',
        ) );
    }

    public function render_shortcode( $atts ) {

        // Defence in depth: W7's branch already guards entry, but a
        // mis-placed shortcode (Divi editor, etc.) could land users here
        // with the flag off. Render nothing public-facing; admins see a note.
        if ( get_option( 'hdlv2_automation_tier_enabled', false ) !== true ) {
            if ( current_user_can( 'manage_options' ) ) {
                return '<div style="padding:1em;border:1px dashed #999;color:#666;font-size:13px;">Automation tier not yet enabled. This shortcode renders only when <code>hdlv2_automation_tier_enabled</code> is true.</div>';
            }
            return '';
        }

        if ( ! is_user_logged_in() ) {
            $login_url = wp_login_url( get_permalink() );
            return '<div class="hdlv2-auto-root"><div class="hdlv2-auto-card"><h2 class="hdlv2-auto-h1">Please sign in to continue</h2><p class="hdlv2-auto-sub">You need to be signed in to share your answers. <a href="' . esc_url( $login_url ) . '">Log in</a> using the link from your welcome email.</p></div></div>';
        }

        $this->enqueue_assets();

        $questions = $this->get_questions();

        ob_start();
        ?>
        <div class="hdlv2-auto-root" id="hdlv2-auto-root">
            <div class="hdlv2-auto-card" id="hdlv2-auto-form">
                <h1 class="hdlv2-auto-h1">Share a bit about yourself</h1>
                <p class="hdlv2-auto-sub">You've completed the assessment. The final step is to share a bit more about yourself in your own words — the things a practitioner would normally ask in a one-on-one consultation. Take your time. Be honest, not perfect.</p>

                <ol class="hdlv2-auto-prompts">
                    <?php foreach ( $questions as $q ) : ?>
                        <li><?php echo esc_html( $q ); ?></li>
                    <?php endforeach; ?>
                </ol>

                <label class="hdlv2-auto-label" for="hdlv2-auto-text">Your answers</label>
                <textarea
                    id="hdlv2-auto-text"
                    class="hdlv2-auto-textarea"
                    placeholder="Type your answers here, or use the audio recorder below."
                    rows="14"></textarea>

                <div class="hdlv2-auto-audio-wrap">
                    <p class="hdlv2-auto-audio-label">Or record your answers</p>
                    <div id="hdlv2-auto-audio"></div>
                </div>

                <div class="hdlv2-auto-error" id="hdlv2-auto-error" hidden></div>

                <button type="button" class="hdlv2-auto-submit" id="hdlv2-auto-submit" disabled>
                    Submit my answers
                </button>
            </div>

            <div class="hdlv2-auto-card hdlv2-auto-success" id="hdlv2-auto-success" hidden>
                <h2 class="hdlv2-auto-h1">Thank you</h2>
                <p class="hdlv2-auto-sub">We've received your answers. Your Trajectory Plan is being prepared and will arrive in your inbox shortly.</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function enqueue_assets() {
        wp_enqueue_script(
            'hdlv2-audio-component',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-audio-component.js',
            array(), // E4 (v0.46.47) — client-Whisper tier removed; no transcriber dep
            HDLV2_VERSION,
            true
        );

        wp_enqueue_script(
            'hdlv2-auto-consultation',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-auto-consultation.js',
            array( 'hdlv2-audio-component' ),
            HDLV2_VERSION,
            true
        );

        wp_enqueue_style(
            'hdlv2-auto-consultation',
            HDLV2_PLUGIN_URL . 'assets/css/hdlv2-auto-consultation.css',
            array(),
            HDLV2_VERSION
        );

        wp_localize_script( 'hdlv2-auto-consultation', 'HDLV2_AUTO', array(
            'submit_url' => esc_url_raw( rest_url( 'hdl-v2/v1/auto-consultation/submit' ) ),
            'audio_base' => esc_url_raw( rest_url( 'hdl-v2/v1/audio' ) ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
        ) );
    }

    private function get_questions() {
        $opt = get_option( 'hdlv2_automation_tier', array() );
        if ( is_array( $opt ) && ! empty( $opt['consultation_questions'] ) && is_array( $opt['consultation_questions'] ) ) {
            $cleaned = array_values( array_filter( array_map( 'sanitize_text_field', $opt['consultation_questions'] ) ) );
            if ( ! empty( $cleaned ) ) {
                return $cleaned;
            }
        }
        return self::DEFAULT_QUESTIONS;
    }

    // ──────────────────────────────────────────────────────────────
    //  W9 — Submit handler
    // ──────────────────────────────────────────────────────────────

    /**
     * POST /wp-json/hdl-v2/v1/auto-consultation/submit
     *
     * Order of gates (each fails fast with the documented status code):
     *   1. Flag check                       → 503 automation_tier_disabled
     *   2. Nonce check                      → 401 nonce_invalid
     *   3. Logged-in check                  → 401 not_logged_in
     *   4. Tier check (user_meta)           → 403 not_automation_tier
     *   5. Body validation                  → 400 body_invalid
     *   6. Form_progress lookup             → 404 form_progress_not_found
     *   7. Token idempotency                → 409 already_completed (re-submit)
     *   8. Persist addendum (always)
     *   9. Safety valve toggle on?          → 200 held_for_review (skip 10–12)
     *  10. Generate AI rec/milestones       → fallback heuristic on failure
     *  11. Build + fire Make.com Route 1 webhook
     *  12. Mark token completed
     *
     * Auth model: WordPress REST nonce + is_user_logged_in. Same shape every
     * V2 client-side shortcode uses for its REST calls. The W8 shortcode
     * minted the nonce via wp_create_nonce( 'wp_rest' ).
     *
     * Rate limit: registered as TIER_AI_BURN in
     * HDLV2_Rate_Limit_Policy::route_patterns(). Middleware wraps the route
     * automatically — no in-handler check.
     */
    public function rest_submit( WP_REST_Request $request ) {

        $request_id = wp_generate_uuid4();

        // ── 1. Flag check.
        if ( get_option( 'hdlv2_automation_tier_enabled', false ) !== true ) {
            $this->log_outcome( $request_id, 'flag_disabled', 0, 0 );
            return new WP_REST_Response( array(
                'error'      => 'automation_tier_disabled',
                'message'    => 'Automation tier is not currently enabled.',
                'request_id' => $request_id,
            ), 503 );
        }

        // ── 2. Nonce check.
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            $this->log_outcome( $request_id, 'nonce_invalid', 0, 0 );
            return new WP_REST_Response( array(
                'error'      => 'nonce_invalid',
                'message'    => 'Session expired. Please refresh the page and try again.',
                'request_id' => $request_id,
            ), 401 );
        }

        // ── 3. Logged-in check.
        if ( ! is_user_logged_in() ) {
            $this->log_outcome( $request_id, 'not_logged_in', 0, 0 );
            return new WP_REST_Response( array(
                'error'      => 'not_logged_in',
                'message'    => 'You must be signed in to submit.',
                'request_id' => $request_id,
            ), 401 );
        }

        $user_id = (int) get_current_user_id();

        // ── 4. Tier check (automation-tier only).
        if ( get_user_meta( $user_id, 'hdlv2_tier', true ) !== 'automation' ) {
            $this->log_outcome( $request_id, 'not_automation_tier', $user_id, 0 );
            return new WP_REST_Response( array(
                'error'      => 'not_automation_tier',
                'message'    => 'This endpoint is for automation-tier clients only.',
                'request_id' => $request_id,
            ), 403 );
        }

        // ── 5. Body validation.
        $params = $request->get_json_params();
        if ( ! is_array( $params ) ) {
            $params = $request->get_params();
        }

        $text_answers    = isset( $params['text_answers'] )    ? sanitize_textarea_field( (string) $params['text_answers'] )    : '';
        $audio_path      = isset( $params['audio_path'] )      ? sanitize_text_field( (string) $params['audio_path'] )           : '';
        $audio_transcript = isset( $params['audio_transcript'] ) ? sanitize_textarea_field( (string) $params['audio_transcript'] ) : '';
        $duration_ms     = isset( $params['duration_ms'] )     ? (int) $params['duration_ms']                                     : 0;

        if ( strlen( $text_answers ) > 10000 )    $text_answers = mb_substr( $text_answers, 0, 10000 );
        if ( strlen( $audio_path ) > 512 )        $audio_path = substr( $audio_path, 0, 512 );
        if ( strlen( $audio_transcript ) > 10000 ) $audio_transcript = mb_substr( $audio_transcript, 0, 10000 );
        if ( $duration_ms < 0 || $duration_ms > 360000 ) $duration_ms = 0;

        if ( $text_answers === '' && $audio_transcript === '' ) {
            $this->log_outcome( $request_id, 'body_invalid', $user_id, 0 );
            return new WP_REST_Response( array(
                'error'      => 'body_invalid',
                'message'    => 'Please provide either text answers or an audio recording.',
                'request_id' => $request_id,
            ), 400 );
        }

        // ── 6. Form progress lookup.
        global $wpdb;
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, token FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE client_user_id = %d AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1",
            $user_id
        ) );
        if ( ! $progress ) {
            $this->log_outcome( $request_id, 'form_progress_not_found', $user_id, 0 );
            return new WP_REST_Response( array(
                'error'      => 'form_progress_not_found',
                'message'    => 'No assessment was found for your account. Please complete the assessment first.',
                'request_id' => $request_id,
            ), 404 );
        }
        $form_progress_id = (int) $progress->id;

        // ── 7. Token idempotency.
        $user        = get_userdata( $user_id );
        $user_email  = $user ? (string) $user->user_email : '';
        $token_row   = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM {$wpdb->prefix}hdlv2_automation_tokens
             WHERE client_email = %s
             ORDER BY issued_at DESC LIMIT 1",
            $user_email
        ) );
        if ( $token_row && $token_row->status === 'completed' ) {
            $this->log_outcome( $request_id, 'already_completed', $user_id, $form_progress_id );
            return new WP_REST_Response( array(
                'status'     => 'already_completed',
                'message'    => 'Your answers have already been submitted. Your Trajectory Plan is on its way.',
                'request_id' => $request_id,
            ), 409 );
        }
        $token_id = $token_row ? (int) $token_row->id : 0;

        // ── 8. Persist addendum (always — even when safety valve is on,
        //      the captured content is the audit trail).
        $body_text = $text_answers;
        if ( $audio_transcript !== '' ) {
            $body_text .= ( $body_text !== '' ? "\n\n" : '' ) . "[AUDIO TRANSCRIPT]\n" . $audio_transcript;
        }

        $addendum_id = $this->persist_addendum( $user_id, $form_progress_id, $body_text, $audio_path !== '' );
        if ( ! $addendum_id ) {
            $this->log_outcome( $request_id, 'internal_error', $user_id, $form_progress_id, 'addendum_insert_failed: ' . $wpdb->last_error );
            return new WP_REST_Response( array(
                'error'      => 'internal_error',
                'message'    => 'Could not save your answers. Please try again in a moment.',
                'request_id' => $request_id,
            ), 500 );
        }

        // ── 9. Safety valve — if on, route to the existing consultation
        //      editor and STOP. No AI, no webhook, no completion mark. The
        //      practitioner finalises manually via the existing UI.
        if ( get_option( 'hdlv2_automation_hold_for_review', false ) === true ) {
            $this->log_outcome( $request_id, 'safety_valve_routed_to_editor', $user_id, $form_progress_id );
            return new WP_REST_Response( array(
                'status'      => 'held_for_review',
                'addendum_id' => $addendum_id,
                'message'     => 'Your answers have been received. A practitioner will review them and your Trajectory Plan will arrive shortly.',
                'request_id'  => $request_id,
            ), 200 );
        }

        // ── 10. Generate AI rec/milestones via Claude (with heuristic fallback).
        $ai_result = $this->generate_ai_inputs( $form_progress_id, $body_text );
        if ( is_wp_error( $ai_result ) || empty( $ai_result['recommendations'] ) || empty( $ai_result['milestones'] ) ) {
            // Fallback path — always returns something usable so the client
            // request never fails on AI flakiness.
            $this->log_outcome( $request_id, 'ai_generation_failed_fallback_used', $user_id, $form_progress_id,
                is_wp_error( $ai_result ) ? $ai_result->get_error_message() : 'empty_or_invalid_response' );
            $ai_result = $this->fallback_ai_inputs( $form_progress_id );
        } else {
            $this->log_outcome( $request_id, 'ai_generation_succeeded', $user_id, $form_progress_id );
        }

        // ── 11. Build + fire Make.com Route 1 webhook.
        $marker_health_summary = "[CLIENT SELF-REPORTED — NO PRACTITIONER CONSULTATION OCCURRED]\n\nClient's self-reported answers to six contextual prompts:\n\n" . $body_text;
        $brief_summary         = mb_substr( $body_text, 0, 500 );

        $webhook_result = HDLV2_Final_Report::fire_for_automation_tier(
            $form_progress_id,
            $marker_health_summary,
            $brief_summary,
            $ai_result['recommendations'],
            $ai_result['milestones']
        );

        $webhook_fired = ! is_wp_error( $webhook_result );
        if ( ! $webhook_fired ) {
            $this->log_outcome( $request_id, 'webhook_failed', $user_id, $form_progress_id, $webhook_result->get_error_message() );
            // Don't 500 the client — the addendum is persisted and the boss
            // can re-fire from the practitioner editor. Mark the token
            // completed so we don't double-fire on retry.
        } else {
            $this->log_outcome( $request_id, 'webhook_fired', $user_id, $form_progress_id );
        }

        // ── 12. Mark token completed.
        if ( $token_id ) {
            $wpdb->update(
                $wpdb->prefix . 'hdlv2_automation_tokens',
                array( 'status' => 'completed', 'completed_at' => current_time( 'mysql' ) ),
                array( 'id' => $token_id )
            );
        }

        return new WP_REST_Response( array(
            'status'        => 'submitted',
            'addendum_id'   => $addendum_id,
            'token_status'  => $token_id ? 'completed' : 'no_token',
            'webhook_fired' => $webhook_fired,
            'request_id'    => $request_id,
        ), 200 );
    }

    /**
     * Insert a self-reported addendum row. Returns the new row id, or 0 on
     * failure ($wpdb->last_error has the SQL detail).
     */
    private function persist_addendum( $user_id, $form_progress_id, $body_text, $had_audio ) {
        global $wpdb;
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'hdlv2_consultation_addenda',
            array(
                'consultation_id'      => 0,       // no consultation_notes row for automation tier
                'practitioner_user_id' => 0,       // no practitioner for automation tier
                'client_user_id'       => (int) $user_id,
                'form_progress_id'     => (int) $form_progress_id,
                'note_text'            => (string) $body_text,
                'occurred_at'          => current_time( 'mysql' ),
                'priority'             => 'medium',
                'source'               => $had_audio ? 'voice' : 'typed',
                'submitter'            => 'client_automation',  // W3 schema discriminator
            ),
            array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
        );
        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Claude Haiku 4.5 call — generates 5 recommendations + 4 milestones.
     * Returns WP_Error on transport / parse failure (caller falls back to
     * heuristic). On success returns array(recommendations[], milestones{}).
     */
    private function generate_ai_inputs( $form_progress_id, $self_reported_text ) {
        if ( ! defined( 'HDLV2_ANTHROPIC_API_KEY' ) || HDLV2_ANTHROPIC_API_KEY === '' ) {
            return new WP_Error( 'no_api_key', 'HDLV2_ANTHROPIC_API_KEY not configured.' );
        }

        global $wpdb;
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT stage1_data, stage3_data FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d",
            $form_progress_id
        ) );
        $why_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT distilled_why, key_people, motivations FROM {$wpdb->prefix}hdlv2_why_profiles WHERE form_progress_id = %d LIMIT 1",
            $form_progress_id
        ), ARRAY_A );

        $s1 = $progress ? ( json_decode( $progress->stage1_data, true ) ?: array() ) : array();
        $s3 = $progress ? ( json_decode( $progress->stage3_data, true ) ?: array() ) : array();
        $distilled_why = $why_row ? (string) ( $why_row['distilled_why'] ?? '' ) : '';

        $user_prompt = "Client data:\n"
            . "Stage 1 (anthropometrics + screening):\n" . wp_json_encode( $s1, JSON_PRETTY_PRINT ) . "\n\n"
            . "Stage 3 (deep assessment):\n" . wp_json_encode( $s3, JSON_PRETTY_PRINT ) . "\n\n"
            . "Distilled WHY (client's stated motivation):\n" . ( $distilled_why !== '' ? $distilled_why : '(none captured)' ) . "\n\n"
            . "Self-reported answers (6 contextual prompts):\n" . $self_reported_text;

        $system = "You are a holistic-naturopath longevity advisor for HealthDataLab. Generate 5 lifestyle recommendations and 4 milestones for ONE specific client based on the data below.\n\n"
            . "OUTPUT CONTRACT — non-negotiable:\n"
            . "1. Output ONLY a single JSON object with exactly the structure below — no preamble, no markdown fence, first char open-brace, last char close-brace.\n"
            . "2. British English. Metric units. Holistic naturopath voice (encouraging, specific, considered — not clinical-cold, not influencer-cheesy).\n"
            . "3. Recommendations are PRACTICAL and SPECIFIC — name the behaviour, not the outcome. \"Add 30 minutes of brisk walking three evenings a week\" not \"improve cardiovascular fitness\". Each recommendation under 28 words.\n"
            . "4. Milestones are aspirational and aligned with the client's stated WHY (use their exact named relationships and goals where possible). Each milestone under 24 words. The 5yr and 10yr+ milestones may reference the client's distilled WHY directly.\n"
            . "5. Categories are one of: nutrition, movement, sleep, stress, social, hydration, supplements, behaviour, environment.\n\n"
            . "{\n"
            . "  \"recommendations\": [\n"
            . "    {\"text\": \"...\", \"category\": \"...\"},\n"
            . "    {\"text\": \"...\", \"category\": \"...\"},\n"
            . "    {\"text\": \"...\", \"category\": \"...\"},\n"
            . "    {\"text\": \"...\", \"category\": \"...\"},\n"
            . "    {\"text\": \"...\", \"category\": \"...\"}\n"
            . "  ],\n"
            . "  \"milestones\": {\n"
            . "    \"6mo\": \"...\",\n"
            . "    \"2yr\": \"...\",\n"
            . "    \"5yr\": \"...\",\n"
            . "    \"10yr\": \"...\"\n"
            . "  }\n"
            . "}";

        $parsed = $this->call_claude_json( HDLV2_ANTHROPIC_API_KEY, $system, $user_prompt, 1500, 0.4, 'claude-opus-4-8' );
        if ( is_wp_error( $parsed ) ) {
            // Retry once.
            $parsed = $this->call_claude_json( HDLV2_ANTHROPIC_API_KEY, $system, $user_prompt, 1500, 0.4, 'claude-opus-4-8' );
        }
        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }

        // Shape-validate.
        if ( ! is_array( $parsed )
             || empty( $parsed['recommendations'] ) || ! is_array( $parsed['recommendations'] )
             || empty( $parsed['milestones'] )      || ! is_array( $parsed['milestones'] ) ) {
            return new WP_Error( 'claude_shape', 'Claude returned an unexpected JSON shape.' );
        }

        return $this->normalise_ai_inputs( $parsed );
    }

    /**
     * Claude API call with JSON parse. Self-contained so we don't have to
     * extend HDLV2_AI_Service::call_claude. 120s timeout matches AI service.
     * v0.46.24 — `temperature` removed from the request body: Opus 4.8 rejects
     * it (HTTP 400 "temperature is deprecated for this model"). The $temperature
     * parameter is retained in the signature for call-site compatibility but is
     * no longer sent. Determinism is now steered by the prompt + low max_tokens.
     */
    private function call_claude_json( $api_key, $system, $user_prompt, $max_tokens, $temperature, $model ) {
        $body = array(
            'model'       => $model,
            'max_tokens'  => $max_tokens,
            'system'      => $system,
            'messages'    => array(
                array( 'role' => 'user', 'content' => $user_prompt ),
            ),
        );

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 120, // v0.46.24 — bumped 60→120 for Opus 4.8.
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code      = wp_remote_retrieve_response_code( $response );
        $resp_body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 ) {
            $msg = isset( $resp_body['error']['message'] ) ? $resp_body['error']['message'] : "HTTP $code";
            return new WP_Error( 'claude_api_error', $msg );
        }

        $text = $resp_body['content'][0]['text'] ?? '';
        if ( $text === '' ) {
            return new WP_Error( 'claude_empty', 'Claude returned empty content.' );
        }
        // Strip stray markdown code fences if present.
        $text = preg_replace( '/^\s*```(?:json)?\s*/i', '', $text );
        $text = preg_replace( '/\s*```\s*$/', '', $text );

        $parsed = json_decode( $text, true );
        if ( ! is_array( $parsed ) ) {
            return new WP_Error( 'claude_json_parse', 'Claude response was not valid JSON.' );
        }
        return $parsed;
    }

    /**
     * Convert the Claude JSON ({recommendations[], milestones{6mo,2yr,5yr,10yr}})
     * into the shape fire_for_automation_tier expects (recommendations[] +
     * milestones with six_months/two_years/five_years/ten_plus_years keys).
     */
    private function normalise_ai_inputs( $parsed ) {
        $recs = array();
        foreach ( (array) $parsed['recommendations'] as $r ) {
            if ( ! is_array( $r ) ) continue;
            $text = isset( $r['text'] )     ? trim( (string) $r['text'] )     : '';
            $cat  = isset( $r['category'] ) ? trim( (string) $r['category'] ) : '';
            if ( $text === '' ) continue;
            $recs[] = array(
                'category'  => $cat,
                'text'      => $text,
                'priority'  => 'Medium',
                'frequency' => 'Ongoing',
            );
        }

        $ms_in = (array) $parsed['milestones'];
        // fire_for_automation_tier passes $milestones to HDLV2_Staged_Form::format_milestones,
        // which expects arrays per bucket.
        $milestones = array(
            'six_months'     => isset( $ms_in['6mo'] )  ? array( (string) $ms_in['6mo'] )  : array(),
            'two_years'      => isset( $ms_in['2yr'] )  ? array( (string) $ms_in['2yr'] )  : array(),
            'five_years'     => isset( $ms_in['5yr'] )  ? array( (string) $ms_in['5yr'] )  : array(),
            'ten_plus_years' => isset( $ms_in['10yr'] ) ? array( (string) $ms_in['10yr'] ) : array(),
        );

        return array(
            'recommendations' => $recs,
            'milestones'      => $milestones,
        );
    }

    /**
     * Deterministic fallback when Claude is unavailable or returns garbage.
     * Picks the 5 lowest scores from the freshest calc_result and produces
     * generic-but-relevant recommendations. Milestones reference distilled_why
     * when present, else stay generic. Guarantees the client request never
     * fails because of AI flakiness.
     */
    private function fallback_ai_inputs( $form_progress_id ) {
        global $wpdb;
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT stage1_data, stage3_data FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d",
            $form_progress_id
        ) );
        $why_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT distilled_why FROM {$wpdb->prefix}hdlv2_why_profiles WHERE form_progress_id = %d LIMIT 1",
            $form_progress_id
        ), ARRAY_A );
        $distilled_why = $why_row ? (string) ( $why_row['distilled_why'] ?? '' ) : '';

        $s1 = $progress ? ( json_decode( $progress->stage1_data, true ) ?: array() ) : array();
        $s3 = $progress ? ( json_decode( $progress->stage3_data, true ) ?: array() ) : array();
        $calc_data = array_merge( $s1, $s3 );
        foreach ( $calc_data as $k => $v ) { if ( $v === 'skip' ) $calc_data[ $k ] = null; }
        $age    = (int) ( $calc_data['q1_age'] ?? $calc_data['age'] ?? 0 );
        $gender = $calc_data['q1_sex'] ?? $calc_data['gender'] ?? 'other';

        $scores = array();
        if ( class_exists( 'HDLV2_Rate_Calculator' ) ) {
            $calc = HDLV2_Rate_Calculator::calculate_full( $age, $calc_data, $gender );
            $scores = isset( $calc['scores'] ) && is_array( $calc['scores'] ) ? $calc['scores'] : array();
        }

        // Pick the 5 lowest numeric scores.
        $numeric = array_filter( $scores, function ( $v ) { return is_numeric( $v ); } );
        asort( $numeric );
        $lowest = array_slice( array_keys( $numeric ), 0, 5 );

        $name_map = array(
            'sleepDuration'      => array( 'category' => 'sleep',       'human' => 'sleep duration' ),
            'sleepQuality'       => array( 'category' => 'sleep',       'human' => 'sleep quality' ),
            'stressLevels'       => array( 'category' => 'stress',      'human' => 'stress regulation' ),
            'physicalActivity'   => array( 'category' => 'movement',    'human' => 'physical activity' ),
            'dietQuality'        => array( 'category' => 'nutrition',   'human' => 'diet quality' ),
            'dailyHydration'     => array( 'category' => 'hydration',   'human' => 'daily hydration' ),
            'socialConnections'  => array( 'category' => 'social',      'human' => 'social connection' ),
            'cognitiveActivity'  => array( 'category' => 'behaviour',   'human' => 'cognitive engagement' ),
            'supplementIntake'   => array( 'category' => 'supplements', 'human' => 'supplement support' ),
            'sunlightExposure'   => array( 'category' => 'environment', 'human' => 'sunlight exposure' ),
            'alcoholConsumption' => array( 'category' => 'behaviour',   'human' => 'alcohol intake' ),
            'smokingStatus'      => array( 'category' => 'behaviour',   'human' => 'smoking habits' ),
        );

        $recs = array();
        foreach ( $lowest as $key ) {
            $info = $name_map[ $key ] ?? array( 'category' => 'behaviour', 'human' => str_replace( '_', ' ', $key ) );
            $recs[] = array(
                'category'  => $info['category'],
                'text'      => 'Work on improving your ' . $info['human'] . ' with small, consistent daily changes — your practitioner or this report can suggest specific actions.',
                'priority'  => 'Medium',
                'frequency' => 'Ongoing',
            );
        }
        // Pad to 5 if fewer scores were available.
        while ( count( $recs ) < 5 ) {
            $recs[] = array(
                'category'  => 'behaviour',
                'text'      => 'Build a daily habit that anchors your day — most people benefit from a consistent wake time and a 10-minute morning movement routine.',
                'priority'  => 'Medium',
                'frequency' => 'Daily',
            );
        }

        $why_tail = $distilled_why !== '' ? ' — anchored to: ' . mb_substr( $distilled_why, 0, 120 ) : '';
        $milestones = array(
            'six_months'     => array( 'Establish your daily anchor habits and notice your first signal of change.' ),
            'two_years'      => array( 'Move with energy through a typical day and feel steady under everyday stress.' ),
            'five_years'     => array( 'Live with the resilience your future self needs' . $why_tail ),
            'ten_plus_years' => array( 'Be the version of yourself who shows up for the people who matter' . $why_tail ),
        );

        return array(
            'recommendations' => $recs,
            'milestones'      => $milestones,
        );
    }

    /**
     * Single-line audit log. Doesn't write to a DB table — error_log() picks
     * it up into /usr/local/lsws/logs/error.log alongside the rest of V2's
     * runtime breadcrumbs. Pre-filter for `[HDLV2 auto-submit]` to read.
     */
    private function log_outcome( $request_id, $outcome, $user_id, $form_progress_id, $detail = '' ) {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '';
        $line = sprintf(
            '[HDLV2 auto-submit] req=%s outcome=%s user=%d progress=%d ip=%s%s',
            $request_id, $outcome, (int) $user_id, (int) $form_progress_id, $ip,
            $detail !== '' ? ' detail=' . substr( $detail, 0, 200 ) : ''
        );
        error_log( $line );
    }
}
