<?php
/**
 * Audio Service — Shared record/transcribe/extract component.
 *
 * Used by Stage 2 WHY, consultation notes, and weekly check-in.
 * Deepgram (via async job queue) for transcription, Claude API for structured extraction.
 *
 * Claude calls route through HDLV2_AI_Service.
 *
 * @package HDL_Longevity_V2
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Audio_Service {

    const ALLOWED_MIMES = array(
        'mp3'  => 'audio/mpeg',
        'm4a'  => 'audio/mp4',
        'mp4'  => 'audio/mp4',  // v0.46.20 — iOS/Safari MediaRecorder emits MP4-container audio.
        'wav'  => 'audio/wav',
        'ogg'  => 'audio/ogg',
        'webm' => 'audio/webm',
    );

    // v0.47.2 (RF-7) — raised 25MB -> 60MB. The async path exists for long
    // (up to ~1-hour) consultation audio; 25MB clipped a real hour even at the
    // browser's old 128kbps. Recordings now capture at 32kbps opus (~14MB/hr)
    // and 60MB stays well under Deepgram's 250MB limit + the 240s HTTP budget.
    // NOTE: also requires php.ini upload_max_filesize/post_max_size >= 60M.
    const MAX_FILE_SIZE = 60 * 1024 * 1024; // 60MB upload cap

    /** Claude model per context type.
     *  v0.46.24 — unified to Opus 4.8 (claude-opus-4-8) (was Sonnet 4.6). weekly_checkin was Haiku
     *  4.5 before the 2026-05-07 promotion; now Opus 4.8 like everything else.
     */
    const MODELS = array(
        'why_collection'     => 'claude-opus-4-8',
        'consultation_notes' => 'claude-opus-4-8',
        'weekly_checkin'     => 'claude-opus-4-8',
    );

    /** Max tokens per context type.
     *  Bumped 2026-04-23 (v0.19.0) — previous caps compressed 10-minute audio
     *  down to ~1000 words of output, losing 80% of client content. See
     *  docs/ai-prompts/FIXES.md and QUIM-BRIEF-Flight-Plan-and-Checkin.md
     *  ("preserve specifics and nuance" rule).
     */
    const MAX_TOKENS = array(
        'why_collection'     => 4000,
        'consultation_notes' => 4000,
        'weekly_checkin'     => 3000,
    );

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function register_hooks() {
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'hdlv2_audio_cleanup', array( $this, 'cleanup_old_audio' ) );
        add_action( 'hdlv2_audio_cleanup', array( $this, 'cleanup_old_extractions' ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST ROUTES
    // ──────────────────────────────────────────────────────────────

    public function register_rest_routes() {
        // v0.17.0 — async upload path. Validates + stores the file, enqueues a
        // transcribe_audio job, and returns a job_id. Frontend polls
        // /jobs/{id}/status. Replaces the blocking /audio/transcribe for the
        // consultation upload flow where 1-hour files need Deepgram (not
        // browser Whisper) and can't sit on an open HTTP request for minutes.
        register_rest_route( 'hdl-v2/v1', '/audio/transcribe-async', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_transcribe_async' ),
            'permission_callback' => array( $this, 'check_audio_permission' ),
        ) );

        // Extract structured summary from text
        register_rest_route( 'hdl-v2/v1', '/audio/extract', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_extract' ),
            'permission_callback' => array( $this, 'check_audio_permission' ),
        ) );

        // Client-side transcriber telemetry (PR 1 — in-browser Whisper).
        // Open permission because errors may fire before a token is available
        // (e.g. worker failed to boot on a Stage-2 page). The writer only
        // touches error_log, so blast radius from noise is zero. Rate-limited
        // via class-hdlv2-rate-limit-policy::TIER_WRITE.
        register_rest_route( 'hdl-v2/v1', '/audio/client-error', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_client_error' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * Allow token-based auth (clients) or logged-in practitioner.
     */
    public function check_audio_permission( $request ) {
        // Practitioner auth
        $user_id = get_current_user_id();
        if ( $user_id && HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            return true;
        }

        // Token auth — check token param or body.
        // v0.46.18 (W3-4 / IDOR-04) — `AND deleted_at IS NULL` so a token from a
        // soft-deleted (archived) assessment can no longer authenticate to audio
        // endpoints, mirroring HDLV2_Job_Queue::permission_read() (v0.41.17).
        $token = $request->get_param( 'token' ) ?: '';
        if ( $token && preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            global $wpdb;
            // v0.47.53 (B4) — `AND token_expires_at > UTC_TIMESTAMP()`:
            // expired tokens no longer authenticate to audio endpoints.
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}hdlv2_form_progress WHERE token = %s AND deleted_at IS NULL AND token_expires_at > UTC_TIMESTAMP()",
                $token
            ) );
            return (bool) $exists;
        }

        return false;
    }

    /**
     * Does the current caller own the given consultation?
     * Used by the W3-4 write-IDOR guard on consultation_notes transcription.
     * Only the practitioner who owns the consultation row (or an admin) may
     * drive a transcription that writes into it. A client-token caller has
     * user id 0 and can never match, so it is always rejected here.
     *
     * @param int $consultation_id hdlv2_consultation_notes.id
     * @return bool
     */
    private function caller_owns_consultation( $consultation_id ) {
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }
        $uid = (int) get_current_user_id();
        if ( ! $uid || ! $consultation_id ) {
            return false;
        }
        global $wpdb;
        $prac = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT practitioner_user_id FROM {$wpdb->prefix}hdlv2_consultation_notes WHERE id = %d LIMIT 1",
            (int) $consultation_id
        ) );
        return ( $prac && $prac === $uid );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: TRANSCRIBE ASYNC  (v0.17.0 — Deepgram + job queue)
    // ──────────────────────────────────────────────────────────────

    /**
     * Upload audio, enqueue a transcribe_audio job, return job_id.
     * The caller polls /jobs/{id}/status for progress/result.
     *
     * Expected multipart body:
     *   - audio: file (required)
     *   - context_type: 'consultation_notes' | 'consultation_addendum' | 'why_collection' | 'weekly_checkin'
     *   - reference_id: int (id of consultation / why / checkin row; ignored for addendum)
     *   - token: client token if unauthenticated
     */
    public function rest_transcribe_async( $request ) {
        $tok        = $request->get_param( 'token' );
        $idem_scope = $tok ? 'tok:' . substr( hash( 'sha256', (string) $tok ), 0, 16 ) : 'anon-' . get_current_user_id();
        return HDLV2_Idempotency::wrap( $request, $idem_scope, function () use ( $request ) {
            // v0.46.18 — W3-4 write-IDOR guard (IDOR-04). A 'consultation_notes'
            // transcription persists into hdlv2_consultation_notes[reference_id]
            // (raw_notes) and chains organise_consultation on it. Without an
            // ownership check, any valid client token or any practitioner could
            // overwrite ANOTHER practitioner's consultation notes by passing a
            // foreign reference_id. Enforce ownership before any file/queue work.
            // (Other contexts — why/checkin/addendum — write to no shared row and
            // are creator-bound at read time by the W3-1 gate, so they're exempt.)
            $ctx = sanitize_text_field( $request->get_param( 'context_type' ) ?: '' );
            if ( $ctx === 'consultation_notes' ) {
                $ref = (int) $request->get_param( 'reference_id' );
                if ( $ref && ! $this->caller_owns_consultation( $ref ) ) {
                    return new WP_Error( 'forbidden', 'You do not have access to this consultation.', array( 'status' => 403 ) );
                }
            }

            $files = $request->get_file_params();
            if ( empty( $files['audio'] ) ) {
                return new WP_Error( 'no_audio', 'Audio file is required.', array( 'status' => 400 ) );
            }

            $context_type = sanitize_text_field( $request->get_param( 'context_type' ) ?: '' );
            $reference_id = (int) $request->get_param( 'reference_id' );

            // v0.33.2 — Added 'consultation_addendum'. The addendum entry
            // form (post-Final practitioner observations) was added in
            // v0.28.0 with the same audio-upload component the consultation
            // notes textarea uses, but the server-side allowlist was never
            // extended — every addendum upload returned 400.
            //
            // Behaviour for consultation_addendum: same as why_collection /
            // weekly_checkin — transcript is returned to the frontend via
            // the job's result field, the JS audio component delivers it
            // through onLiveTranscript() into the addendum textarea, and
            // the practitioner clicks Save & Update Plan to persist via
            // /consultation/addendum. No server-side write happens during
            // transcription (handle_transcribe() persists only when
            // context_type === 'consultation_notes' — verified in
            // class-hdlv2-transcription-jobs.php line 101).
            $allowed_contexts = array( 'consultation_notes', 'consultation_addendum', 'why_collection', 'weekly_checkin' );
            if ( ! in_array( $context_type, $allowed_contexts, true ) ) {
                return new WP_Error( 'bad_context', 'context_type must be one of: ' . implode( ', ', $allowed_contexts ), array( 'status' => 400 ) );
            }
            // reference_id is REQUIRED for consultation_notes (we write the
            // transcript back into the consultation row and chain organise).
            // For why_collection / weekly_checkin / consultation_addendum
            // the transcript comes back via the job's result field and the
            // consumer (form JS) pushes it into its own state — no DB row
            // needs to exist yet.
            if ( $context_type === 'consultation_notes' && ! $reference_id ) {
                return new WP_Error( 'bad_reference', 'reference_id required for consultation_notes.', array( 'status' => 400 ) );
            }

            $file = $files['audio'];

            $validation = $this->validate_audio_file( $file );
            if ( is_wp_error( $validation ) ) {
                return $validation;
            }

            $stored_path = $this->store_audio_file( $file );
            if ( is_wp_error( $stored_path ) ) {
                return $stored_path;
            }

            if ( ! class_exists( 'HDLV2_Job_Queue' ) ) {
                return new WP_Error( 'queue_missing', 'Job queue unavailable.', array( 'status' => 500 ) );
            }

            // Unique per upload — new audio = new transcribe job, even if
            // caller retries. (Organise is deduped separately per consult id.)
            $idem_key = sprintf( 'transcribe:%s:%d:%s', $context_type, $reference_id, sha1( $stored_path ) );

            // v0.46.17 — creator binding for the IDOR gate in
            // HDLV2_Job_Queue::rest_status(). why_collection / weekly_checkin /
            // consultation_addendum transcripts bind to no persisted row
            // (reference_id = 0); the transcript is handed back only to whoever
            // recorded it. Stamp the caller's identity so /jobs/{id}/status can
            // confirm ownership: _owner_user for a logged-in caller (practitioner
            // or auto-logged-in client), _owner_token_hash for a client-token
            // caller. The audio component polls with the same identity it
            // uploaded with, so the owner always matches. (consultation_notes is
            // resolved resource-side via the consultation row, but stamping it
            // too is harmless.)
            $owner_token = $request->get_param( 'token' );
            $owner_token = ( is_string( $owner_token ) && preg_match( '/^[a-f0-9]{64}$/', $owner_token ) ) ? $owner_token : '';

            $job_id = HDLV2_Job_Queue::enqueue(
                'transcribe_audio',
                array(
                    'file_path'         => $stored_path,
                    'context_type'      => $context_type,
                    'reference_id'      => $reference_id,
                    '_owner_user'       => (int) get_current_user_id(),
                    '_owner_token_hash' => $owner_token ? hash_hmac( 'sha256', $owner_token, wp_salt( 'auth' ) ) : '',
                ),
                array(
                    'reference_id' => $reference_id,
                    'priority'     => 80,
                    'idem_key'     => $idem_key,
                )
            );

            if ( is_wp_error( $job_id ) ) {
                return $job_id;
            }

            return rest_ensure_response( array(
                'success'         => true,
                'job_id'          => (int) $job_id,
                'context_type'    => $context_type,
                'reference_id'    => $reference_id,
                'status_endpoint' => rest_url( 'hdl-v2/v1/jobs/' . (int) $job_id . '/status' ),
            ) );
        } );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: EXTRACT
    // ──────────────────────────────────────────────────────────────

    /**
     * Extract structured summary from text input.
     * Body: { text, context_type, previous_summary? }
     */
    public function rest_extract( $request ) {
        $params     = $request->get_json_params();
        $tok        = is_array( $params ) ? ( $params['token'] ?? '' ) : '';
        $idem_scope = $tok ? 'tok:' . substr( hash( 'sha256', (string) $tok ), 0, 16 ) : 'anon-' . get_current_user_id();
        return HDLV2_Idempotency::wrap( $request, $idem_scope, function () use ( $request, $params ) {
        $text         = sanitize_textarea_field( $params['text'] ?? '' );
        $context_type = sanitize_text_field( $params['context_type'] ?? '' );

        if ( ! $text ) {
            return new WP_Error( 'no_text', 'Text input is required.', array( 'status' => 400 ) );
        }

        if ( ! isset( self::MODELS[ $context_type ] ) ) {
            return new WP_Error( 'invalid_context', 'Invalid context_type. Must be: why_collection, consultation_notes, or weekly_checkin.', array( 'status' => 400 ) );
        }

        // If previous_summary provided, merge context for "Add more" flow
        $previous = $params['previous_summary'] ?? '';
        if ( $previous ) {
            $text = "PREVIOUS SUMMARY:\n" . $previous . "\n\nADDITIONAL INPUT:\n" . $text;
        }

        // v0.46.20 (F23) — bind the work to the caller before burning Claude.
        // check_audio_permission() only proves the caller is a practitioner OR
        // holds *a* valid token; it does not bind the token to the work. Resolve
        // the token to its own (non-deleted) form_progress here and require EITHER
        // a practitioner OR a token-bound progress. A token that no longer maps to
        // a live progress (or no caller identity at all) is rejected, so an
        // authenticated-but-unbound caller can't spend the AI budget on junk.
        global $wpdb;
        $is_practitioner = ( ( $uid = (int) get_current_user_id() ) && HDLV2_Compatibility::is_practitioner( $uid ) );
        $progress        = null;
        if ( $tok ) {
            // v0.47.53 (B4) — expired tokens must not bind AI-burn work.
            $progress = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, client_user_id FROM {$wpdb->prefix}hdlv2_form_progress WHERE token = %s AND deleted_at IS NULL AND token_expires_at > UTC_TIMESTAMP() LIMIT 1",
                $tok
            ) );
        }
        if ( ! $is_practitioner && ! $progress ) {
            return new WP_Error( 'forbidden', 'You do not have access to this resource.', array( 'status' => 403 ) );
        }

        // Build persistence metadata so the extraction survives in hdlv2_audio_extractions
        $meta = array( 'input_method' => sanitize_text_field( $params['input_method'] ?? 'audio' ) );
        if ( $progress ) {
            $meta['form_progress_id'] = (int) $progress->id;
            if ( $progress->client_user_id ) $meta['client_user_id'] = (int) $progress->client_user_id;
        }
        // reference_id is attacker-controlled body input and no legitimate caller
        // passes it on /audio/extract (the check-in path resolves its own meta
        // server-side). Only persist a reference_id that provably belongs to the
        // token caller's OWN progress; otherwise drop it rather than store raw input.
        if ( ! empty( $params['reference_id'] ) ) {
            $ref = (int) $params['reference_id'];
            if ( $ref > 0 && $progress && $ref === (int) $progress->id ) {
                $meta['reference_id'] = $ref;
            }
        }

        $summary = $this->extract_summary( $text, $context_type, $meta );
        if ( is_wp_error( $summary ) ) {
            return $summary;
        }

        return rest_ensure_response( array(
            'success' => true,
            'summary' => $summary,
        ) );
        } );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: CLIENT-ERROR (transcriber telemetry)
    // ──────────────────────────────────────────────────────────────

    /**
     * Log a transcriber-side failure so we can track real-world failure rates
     * in the first month post-launch. Intentionally cheap: no DB writes, no
     * heavy sanitisation — just append to error_log with a clear prefix.
     *
     * Body JSON: { source, message, stack?, model?, userAgent?, ts? }
     */
    public function rest_client_error( $request ) {
        $params = $request->get_json_params();
        if ( ! is_array( $params ) ) {
            return new WP_Error( 'bad_payload', 'JSON body required.', array( 'status' => 400 ) );
        }

        $source  = substr( sanitize_text_field( $params['source']  ?? '' ), 0, 100 );
        $message = substr( sanitize_text_field( $params['message'] ?? '' ), 0, 1000 );
        $stack   = substr( sanitize_textarea_field( $params['stack'] ?? '' ), 0, 2000 );
        $model   = substr( sanitize_text_field( $params['model']   ?? '' ), 0, 80 );
        $ua      = substr( sanitize_text_field( $params['userAgent'] ?? '' ), 0, 300 );

        if ( ! $message ) {
            return rest_ensure_response( array( 'success' => true, 'skipped' => true ) );
        }

        error_log( sprintf(
            '[HDLV2 Transcriber] source=%s model=%s ua=%s :: %s',
            $source ?: '(unknown)',
            $model  ?: '(unknown)',
            $ua     ?: '(unknown)',
            $message . ( $stack ? ' | stack: ' . str_replace( "\n", ' \n ', $stack ) : '' )
        ) );

        return rest_ensure_response( array( 'success' => true ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  PUBLIC API
    // ──────────────────────────────────────────────────────────────

    /**
     * Extract structured summary from text via Claude.
     *
     * @param string $text         Input text (transcript or typed).
     * @param string $context_type One of: why_collection, consultation_notes, weekly_checkin.
     * @param array  $meta         Optional persistence metadata:
     *                             { client_user_id, form_progress_id, reference_id, input_method }
     *                             When provided, raw text + structured output is written to
     *                             hdlv2_audio_extractions for audit + future reprocessing.
     * @return string|WP_Error JSON summary string.
     */
    public function extract_summary( $text, $context_type, $meta = array() ) {
        $api_key = defined( 'HDLV2_ANTHROPIC_API_KEY' ) ? HDLV2_ANTHROPIC_API_KEY : '';
        if ( ! $api_key ) {
            return new WP_Error( 'no_api_key', 'HDLV2_ANTHROPIC_API_KEY not configured.' );
        }

        $prompt = $this->get_extraction_prompt( $context_type );
        if ( ! $prompt ) {
            return new WP_Error( 'invalid_context', "Unknown context type: {$context_type}" );
        }

        $model      = self::MODELS[ $context_type ] ?? 'claude-opus-4-8';
        $max_tokens = self::MAX_TOKENS[ $context_type ] ?? 1000;

        $user_prompt = str_replace( '{client_input}', $text, $prompt['user'] );

        $body = array(
            'model'      => $model,
            'max_tokens' => $max_tokens,
            'system'     => $prompt['system'],
            'messages'   => array(
                array( 'role' => 'user', 'content' => $user_prompt ),
            ),
        );

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'x-api-key'        => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 120, // v0.46.24 — bumped 60→120 for Opus 4.8 (up to 4k-token theme extraction).
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( '[HDLV2 Audio] Claude extraction error: ' . $response->get_error_message() );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $err = $data['error']['message'] ?? "HTTP {$code}";
            error_log( "[HDLV2 Audio] Claude API HTTP {$code}: {$err}" );
            return new WP_Error( 'claude_error', $err );
        }

        $content = $data['content'][0]['text'] ?? '';
        if ( ! $content ) {
            return new WP_Error( 'empty_response', 'Claude returned empty response.' );
        }

        // Strip markdown code fences if present (```json ... ```)
        $content = preg_replace( '/^\s*```(?:json)?\s*/i', '', $content );
        $content = preg_replace( '/\s*```\s*$/', '', $content );

        // Persist raw transcript + structured output when caller provides metadata.
        // Addresses the compression-loss problem: previously a 10-min audio was
        // compressed to a distilled summary and the original was discarded.
        // Now the full transcript survives for 90 days for audit + future reprocessing.
        if ( ! empty( $meta ) ) {
            $this->persist_extraction( $text, $content, $context_type, $model, $meta );
        }

        return $content;
    }

    /**
     * Write raw + structured extraction to hdlv2_audio_extractions.
     * Non-blocking: failure to persist does not fail the caller.
     */
    private function persist_extraction( $raw_text, $structured_json, $context_type, $model, $meta ) {
        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_audio_extractions';

        // Guard: table may not exist on older installs — check once per request
        static $table_exists = null;
        if ( $table_exists === null ) {
            $table_exists = (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
                $table
            ) );
        }
        if ( ! $table_exists ) return;

        $wpdb->insert( $table, array(
            'client_user_id'    => isset( $meta['client_user_id'] )   ? (int) $meta['client_user_id']   : null,
            'form_progress_id'  => isset( $meta['form_progress_id'] ) ? (int) $meta['form_progress_id'] : null,
            'reference_id'      => isset( $meta['reference_id'] )     ? (int) $meta['reference_id']     : null,
            'context_type'      => substr( (string) $context_type, 0, 32 ),
            'input_method'      => substr( (string) ( $meta['input_method'] ?? 'audio' ), 0, 16 ),
            'raw_transcript'    => (string) $raw_text,
            'structured_json'   => (string) $structured_json,
            'model'             => substr( (string) $model, 0, 64 ),
            'transcript_chars'  => strlen( (string) $raw_text ),
            'structured_chars'  => strlen( (string) $structured_json ),
            'created_at'        => current_time( 'mysql' ),
        ) );
    }

    /**
     * Retention cleanup — brief spec says raw audio + transcripts kept for 90 days.
     * Called from existing hdlv2_audio_cleanup cron (daily).
     *
     * Concurrency: safe — single DELETE with LIMIT; runs on one cron worker.
     * At 50 users × 4 contexts × 4 weeks = ~800 rows/month, this stays trivial.
     */
    public function cleanup_old_extractions() {
        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_audio_extractions';
        $exists = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $table
        ) );
        if ( ! $exists ) return;
        // Per brief: raw transcripts kept 90 days, then deleted.
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}hdlv2_audio_extractions WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY) LIMIT 1000"
        );
    }

    /**
     * Process text: extract only (no transcription needed).
     *
     * @param string $text         Input text.
     * @param string $context_type Extraction context.
     * @param array  $meta         Optional persistence metadata passed through to extract_summary.
     * @return string|WP_Error Summary.
     */
    public function process_text( $text, $context_type, $meta = array() ) {
        return $this->extract_summary( $text, $context_type, $meta );
    }

    // ──────────────────────────────────────────────────────────────
    //  EXTRACTION PROMPTS
    // ──────────────────────────────────────────────────────────────

    private function get_extraction_prompt( $context_type ) {
        $prompts = array(
            'why_collection' => array(
                'system' => 'You are processing a client\'s response about why they are pursuing longevity and health changes. Return a structured JSON response. You are an exhaustive archivist — your job is to capture EVERYTHING the client said, not to summarise.',
                'user'   => 'Extract and structure the following from this client input. Be EXHAUSTIVE, not brief.

1. KEY_PEOPLE: Every named person the client mentions. Include name, relationship, age if given, and a short note on why they matter to the client.
2. MOTIVATIONS: Every specific motivation they raise — separate array entries. Be specific — not "wants to be healthy" but "wants to be able to play on the floor with grandchildren at 75."
3. FEARS: Every fear / future they want to avoid — separate array entries. Keep the exact phrasing where it has emotional weight.
4. DISTILLED_WHY: One sentence that captures their deepest motivation. Use their own words where possible.
5. VERBATIM_QUOTES: Array of 3-10 direct quotes from their input that carry particular emotional weight, specific detail, or personal colour. These are the phrases that would lose meaning if paraphrased.
6. LIFE_CONTEXT: Any concrete life details they volunteered — job, working hours, travel pattern, family situation, recent events, health incidents, hobbies. Short phrases, one per entry.
7. NARRATIVE_SUMMARY: A 3-5 paragraph narrative re-telling of what they said, in second person, preserving their voice. This is the anti-compression safety net — aim for about 60-70% of the input length, not 10%.

CRITICAL RULES:
- Preserve their EXACT language and specific details. Do NOT generalise.
- If they said "I don\'t want to end up like my mum Joan, stuck at home at 79 with a broken hip" — keep "Does not want a life like their mother [mother: Joan, 79, broken hip, housebound]". Do NOT flatten to "wants to avoid dependency."
- If they gave names — include ALL names. If they mentioned dates/ages/places — include them.
- Do not summarise for length. The extraction must be long if the input is long. Brevity is a bug, not a feature.
- If there is genuinely no data for a field, return an empty array or empty string — do not invent.

Return valid JSON with keys: key_people, motivations, fears, distilled_why, verbatim_quotes, life_context, narrative_summary

Client input:
{client_input}',
            ),

            'consultation_notes' => array(
                'system' => 'You are processing a practitioner\'s consultation notes about a longevity client. Return a structured JSON response.',
                'user'   => 'Extract and structure from this practitioner input:

1. CLINICAL_OBSERVATIONS: What did the practitioner observe or measure?
2. PRIORITY_AREAS: What does the practitioner want to focus on first? In what order?
3. SPECIFIC_RECOMMENDATIONS: Any concrete actions or changes recommended.
4. CONCERNS: Anything to be careful about.
5. CLIENT_CONTEXT: Emotional state, social situation, barriers noted.
6. ACTION_ITEMS: What happens next?

IMPORTANT: Preserve clinical specifics. If the practitioner said "BP measured at 138/88, borderline, lifestyle first before medication" — keep that exact detail.

Return valid JSON with keys: clinical_observations, priority_areas, recommendations, concerns, client_context, action_items

Practitioner input:
{client_input}',
            ),

            'weekly_checkin' => array(
                'system' => 'You are extracting a structured weekly summary from a longevity coaching client\'s check-in. Return ONLY valid JSON. Preserve specifics and nuance — if the client mentions a specific person, event, date, or detail, that exact detail MUST survive extraction. Do NOT flatten or generalise.',
                'user'   => 'Extract and structure this client weekly check-in into the following JSON format. Be thorough and preserve all specific details.

REQUIRED OUTPUT FORMAT:
{
  "check_in_summary": "2-3 paragraph natural language summary of the week — conversational, specific, preserving key details",
  "fitness_adherence": {
    "summary": "text description of what movement was done, missed, and why",
    "score": <1-10 integer, 1=didn\'t follow at all, 10=followed perfectly>
  },
  "nutrition_adherence": {
    "summary": "text description of dietary adherence, what worked, what was hard",
    "score": <1-10 integer>
  },
  "obstacles": ["specific obstacle 1", "specific obstacle 2"],
  "environmental_social": ["specific factor 1 — name people, places, situations"],
  "energy_mood": "text description of overall energy and mood through the week",
  "wins": ["specific win 1", "specific win 2"],
  "client_suggestions": "what the client wants to change or try next week, questions they asked",
  "comfort_zone": {
    "too_easy": ["things that felt too easy"],
    "about_right": ["things that felt about right"],
    "too_hard": ["things that were hard or a real stretch"]
  },
  "flags": [],
  "extracted_events": []
}

FLAGS RULES: Add objects to "flags" array with {trigger, severity, detail} if ANY of these apply:
- Client reports significant emotional distress → severity: "high"
- Client mentions worsening health symptom → severity: "high"
- Client explicitly asks to speak with practitioner → severity: "high"
- Adherence score ≤ 3/10 → severity: "medium"
- Reports a conflict affecting their programme → severity: "low"

EXTRACTED EVENTS: For any specific dated events mentioned, add to "extracted_events":
{
  "description": "what happened",
  "category": "symptom|dietary|supplement|metric|emotional|lifestyle|medical|environmental",
  "date": "YYYY-MM-DD or null if not mentioned",
  "end_date": null,
  "impact": "effect it had",
  "severity": <1-5 or null>,
  "hypothesis": "client\'s theory if stated, else null"
}

Client input:
{client_input}',
            ),
        );

        return $prompts[ $context_type ] ?? null;
    }

    // ──────────────────────────────────────────────────────────────
    //  FILE HANDLING
    // ──────────────────────────────────────────────────────────────

    private function validate_audio_file( $file ) {
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            return new WP_Error( 'upload_error', 'File upload failed.', array( 'status' => 400 ) );
        }

        if ( $file['size'] > self::MAX_FILE_SIZE ) {
            return new WP_Error( 'file_too_large', 'Audio file must be under 60MB.', array( 'status' => 400 ) );
        }

        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( ! isset( self::ALLOWED_MIMES[ $ext ] ) ) {
            return new WP_Error( 'invalid_type', 'Accepted formats: mp3, m4a, mp4, wav, ogg, webm.', array( 'status' => 400 ) );
        }

        // MIME type validation via finfo (not just extension).
        // v0.46.20 — added 'video/mp4' + 'audio/aac'. iOS/Safari (and Chrome)
        // MediaRecorder produce a (fragmented) MP4 container that libmagic keys
        // off the ftyp brand and classifies as video/mp4 — the audio-side
        // equivalent of the video/webm already allowed for Chrome's webm. The
        // extension allowlist above still constrains us to audio extensions, so
        // accepting the mp4 container by content is safe. PROVEN: a real iPhone
        // recording (recording.m4a, fragmented-mp4 bytes) was 400'd before this.
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime  = finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );
        $allowed_mimes = array(
            'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/wav', 'audio/x-wav',
            'audio/ogg', 'audio/webm', 'video/webm', 'video/mp4',
        );
        if ( ! in_array( $mime, $allowed_mimes, true ) ) {
            return new WP_Error( 'invalid_mime', 'File content does not match an allowed audio format.', array( 'status' => 400 ) );
        }

        return true;
    }

    /**
     * Private storage base for voice recordings — OUTSIDE the web root, mirroring
     * the PDF pipeline (class-hdlv2-report-pdf.php::private_dir). Voice recordings
     * are Art. 9 health data; storing them under wp-content/uploads protected only
     * by an unguessable filename is insufficient — OpenLiteSpeed ignores Apache
     * .htaccess deny rules, so the file is URL-downloadable regardless (verified:
     * a real recording returned HTTP 200 at its public URL). There is no audio
     * retrieval route — the file is read only server-side by Deepgram via its
     * absolute path — so no serve route is needed, just out-of-docroot storage +
     * a belt-and-braces deny guard. Override with HDLV2_PRIVATE_DIR in wp-config.
     * Default: a sibling of the WP root (e.g. /var/www/hdlv2-private/hdlv2-audio/).
     */
    private static function private_audio_base() {
        $base = defined( 'HDLV2_PRIVATE_DIR' )
            ? rtrim( HDLV2_PRIVATE_DIR, '/' )
            : dirname( rtrim( ABSPATH, '/' ) ) . '/hdlv2-private';
        return $base . '/hdlv2-audio';
    }

    /** Belt-and-braces deny guard on an audio dir (mirrors report-pdf::protect_dir). */
    private static function protect_audio_dir( $dir ) {
        @chmod( $dir, 0700 );
        @file_put_contents( trailingslashit( $dir ) . '.htaccess',
            "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
        @file_put_contents( trailingslashit( $dir ) . 'index.html', '' );
    }

    private function store_audio_file( $file ) {
        $base      = self::private_audio_base();
        $audio_dir = $base . '/' . date( 'Y' ) . '/' . date( 'm' );

        if ( ! wp_mkdir_p( $audio_dir ) ) {
            return new WP_Error( 'dir_error', 'Could not create audio storage directory.' );
        }
        self::protect_audio_dir( $base ); // idempotent

        $ext      = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        $filename = 'hdlv2_' . bin2hex( random_bytes( 8 ) ) . '_' . time() . '.' . $ext;
        $dest     = $audio_dir . '/' . $filename;

        if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
            return new WP_Error( 'move_error', 'Could not save audio file.' );
        }
        @chmod( $dest, 0600 );

        return $dest; // absolute path — the Deepgram read path is unchanged
    }

    /**
     * Delete audio files older than 90 days.
     * Hooked to hdlv2_audio_cleanup cron.
     */
    public function cleanup_old_audio() {
        $upload_dir = wp_upload_dir();
        // Sweep BOTH the current private base AND the legacy public base, so any
        // pre-relocation files still drain at 90 days even if the one-off
        // relocate_legacy_audio() migration has not been run on this environment.
        $bases = array(
            self::private_audio_base(),
            $upload_dir['basedir'] . '/hdlv2-audio',
        );

        $cutoff  = time() - ( 90 * DAY_IN_SECONDS );
        $deleted = 0;

        foreach ( $bases as $audio_base ) {
            if ( ! is_dir( $audio_base ) ) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $audio_base, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ( $iterator as $item ) {
                if ( $item->isFile() && $item->getMTime() < $cutoff ) {
                    $bn = $item->getFilename();
                    if ( $bn === '.htaccess' || $bn === 'index.html' ) {
                        continue; // never delete our own deny guard
                    }
                    @unlink( $item->getPathname() );
                    $deleted++;
                }
            }

            // Remove empty directories
            foreach ( $iterator as $item ) {
                if ( $item->isDir() ) {
                    @rmdir( $item->getPathname() );
                }
            }
        }

        if ( $deleted > 0 ) {
            error_log( "[HDLV2 Audio] Cleanup: deleted {$deleted} files older than 90 days." );
        }
    }

    /**
     * One-off relocation of legacy recordings from the public uploads dir
     * (wp-content/uploads/hdlv2-audio) into the private base, preserving the Y/m
     * structure, then lock down the old dir as belt-and-braces. Idempotent and
     * safe to re-run (skips files already present at the destination). Invoke via:
     *   wp eval 'echo HDLV2_Audio_Service::relocate_legacy_audio();' --allow-root
     * Returns the number of files moved. No DB change; no schema bump.
     */
    public static function relocate_legacy_audio() {
        $upload_dir = wp_upload_dir();
        $old_base   = $upload_dir['basedir'] . '/hdlv2-audio';
        $new_base   = self::private_audio_base();

        if ( ! is_dir( $old_base ) ) {
            return 0;
        }
        if ( ! wp_mkdir_p( $new_base ) ) {
            return 0;
        }
        self::protect_audio_dir( $new_base );

        $moved    = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $old_base, RecursiveDirectoryIterator::SKIP_DOTS )
        );
        foreach ( $iterator as $item ) {
            if ( ! $item->isFile() ) {
                continue;
            }
            $bn = $item->getFilename();
            if ( $bn === '.htaccess' || $bn === 'index.html' ) {
                continue;
            }
            // Relative path under the old base, e.g. "2026/06/hdlv2_xxx.webm".
            $rel  = ltrim( str_replace( $old_base, '', $item->getPathname() ), '/\\' );
            $dest = $new_base . '/' . $rel;
            if ( file_exists( $dest ) ) {
                continue; // already migrated
            }
            wp_mkdir_p( dirname( $dest ) );
            if ( @rename( $item->getPathname(), $dest ) ) {
                @chmod( $dest, 0600 );
                $moved++;
            }
        }

        // Lock down whatever remains in the old public dir (OLS ignores .htaccess,
        // which is exactly why we moved the files; the guard still helps elsewhere).
        self::protect_audio_dir( $old_base );

        if ( $moved > 0 ) {
            error_log( "[HDLV2 Audio] Relocated {$moved} legacy recordings to the private dir." );
        }
        return $moved;
    }

}
