<?php
/**
 * Audio Service — Shared record/transcribe/extract component.
 *
 * Used by Stage 2 WHY, consultation notes, and weekly check-in.
 * Whisper API for transcription, Claude API for structured extraction.
 *
 * Requires: HDLV2_OPENAI_API_KEY in wp-config.php for Whisper.
 * Claude calls route through HDLV2_AI_Service.
 *
 * @package HDL_Longevity_V2
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Audio_Service {

    const WHISPER_URL = 'https://api.openai.com/v1/audio/transcriptions';
    const WHISPER_MODEL = 'whisper-1';

    const ALLOWED_MIMES = array(
        'mp3'  => 'audio/mpeg',
        'm4a'  => 'audio/mp4',
        'wav'  => 'audio/wav',
        'ogg'  => 'audio/ogg',
        'webm' => 'audio/webm',
    );

    const MAX_FILE_SIZE = 25 * 1024 * 1024; // 25MB Whisper limit

    /** Claude model per context type. */
    const MODELS = array(
        'why_collection'     => 'claude-sonnet-4-20250514',
        'consultation_notes' => 'claude-sonnet-4-20250514',
        'weekly_checkin'     => 'claude-haiku-4-5-20251001',
    );

    /** Max tokens per context type. */
    const MAX_TOKENS = array(
        'why_collection'     => 1500,
        'consultation_notes' => 1500,
        'weekly_checkin'     => 1000,
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
    }

    // ──────────────────────────────────────────────────────────────
    //  REST ROUTES
    // ──────────────────────────────────────────────────────────────

    public function register_rest_routes() {
        // Transcribe uploaded audio file
        register_rest_route( 'hdl-v2/v1', '/audio/transcribe', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_transcribe' ),
            'permission_callback' => array( $this, 'check_audio_permission' ),
        ) );

        // Extract structured summary from text
        register_rest_route( 'hdl-v2/v1', '/audio/extract', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_extract' ),
            'permission_callback' => array( $this, 'check_audio_permission' ),
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

        // Token auth — check token param or body
        $token = $request->get_param( 'token' ) ?: '';
        if ( $token && preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            global $wpdb;
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}hdlv2_form_progress WHERE token = %s",
                $token
            ) );
            return (bool) $exists;
        }

        return false;
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: TRANSCRIBE
    // ──────────────────────────────────────────────────────────────

    /**
     * Upload audio file → Whisper transcription → return transcript.
     * Expects multipart form with 'audio' file field and optional 'context_type'.
     */
    public function rest_transcribe( $request ) {
        $files = $request->get_file_params();
        if ( empty( $files['audio'] ) ) {
            return new WP_Error( 'no_audio', 'Audio file is required.', array( 'status' => 400 ) );
        }

        $file = $files['audio'];

        // Validate file
        $validation = $this->validate_audio_file( $file );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        // Store the file
        $stored_path = $this->store_audio_file( $file );
        if ( is_wp_error( $stored_path ) ) {
            return $stored_path;
        }

        // Transcribe via Whisper
        $transcript = $this->transcribe_audio( $stored_path );
        if ( is_wp_error( $transcript ) ) {
            return $transcript;
        }

        // Optionally extract immediately if context_type is provided
        $context_type = sanitize_text_field( $request->get_param( 'context_type' ) ?: '' );
        $summary = null;
        if ( $context_type && isset( self::MODELS[ $context_type ] ) ) {
            $summary = $this->extract_summary( $transcript, $context_type );
            if ( is_wp_error( $summary ) ) {
                // Return transcript even if extraction fails
                return rest_ensure_response( array(
                    'success'    => true,
                    'transcript' => $transcript,
                    'audio_path' => $stored_path,
                    'extract_error' => $summary->get_error_message(),
                ) );
            }
        }

        $response = array(
            'success'    => true,
            'transcript' => $transcript,
            'audio_path' => $stored_path,
        );
        if ( $summary ) {
            $response['summary'] = $summary;
        }

        return rest_ensure_response( $response );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: EXTRACT
    // ──────────────────────────────────────────────────────────────

    /**
     * Extract structured summary from text input.
     * Body: { text, context_type, previous_summary? }
     */
    public function rest_extract( $request ) {
        $params       = $request->get_json_params();
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

        $summary = $this->extract_summary( $text, $context_type );
        if ( is_wp_error( $summary ) ) {
            return $summary;
        }

        return rest_ensure_response( array(
            'success' => true,
            'summary' => $summary,
        ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  PUBLIC API
    // ──────────────────────────────────────────────────────────────

    /**
     * Transcribe an audio file via OpenAI Whisper API.
     *
     * @param string $file_path Absolute path to audio file.
     * @return string|WP_Error Transcript text.
     */
    public function transcribe_audio( $file_path ) {
        $api_key = $this->get_openai_key();
        if ( ! $api_key ) {
            return new WP_Error( 'no_api_key', 'HDLV2_OPENAI_API_KEY not configured in wp-config.php.' );
        }

        if ( ! file_exists( $file_path ) ) {
            return new WP_Error( 'file_not_found', 'Audio file not found.' );
        }

        // Build multipart request
        $boundary = wp_generate_password( 24, false );
        $body = '';

        // File field
        $body .= "--{$boundary}\r\n";
        $body .= 'Content-Disposition: form-data; name="file"; filename="' . basename( $file_path ) . "\"\r\n";
        $body .= "Content-Type: application/octet-stream\r\n\r\n";
        $body .= file_get_contents( $file_path ) . "\r\n";

        // Model field
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"model\"\r\n\r\n";
        $body .= self::WHISPER_MODEL . "\r\n";

        // Language hint
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"language\"\r\n\r\n";
        $body .= "en\r\n";

        $body .= "--{$boundary}--\r\n";

        $response = wp_remote_post( self::WHISPER_URL, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
            ),
            'body'    => $body,
            'timeout' => 120,
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( '[HDLV2 Audio] Whisper API error: ' . $response->get_error_message() );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $err = $data['error']['message'] ?? "HTTP {$code}";
            error_log( "[HDLV2 Audio] Whisper API HTTP {$code}: {$err}" );
            return new WP_Error( 'whisper_error', $err );
        }

        $transcript = trim( $data['text'] ?? '' );
        if ( ! $transcript ) {
            return new WP_Error( 'empty_transcript', 'Whisper returned empty transcript.' );
        }

        return $transcript;
    }

    /**
     * Extract structured summary from text via Claude.
     *
     * @param string $text         Input text (transcript or typed).
     * @param string $context_type One of: why_collection, consultation_notes, weekly_checkin.
     * @return string|WP_Error JSON summary string.
     */
    public function extract_summary( $text, $context_type ) {
        $api_key = defined( 'HDLV2_ANTHROPIC_API_KEY' ) ? HDLV2_ANTHROPIC_API_KEY : '';
        if ( ! $api_key ) {
            return new WP_Error( 'no_api_key', 'HDLV2_ANTHROPIC_API_KEY not configured.' );
        }

        $prompt = $this->get_extraction_prompt( $context_type );
        if ( ! $prompt ) {
            return new WP_Error( 'invalid_context', "Unknown context type: {$context_type}" );
        }

        $model      = self::MODELS[ $context_type ] ?? 'claude-sonnet-4-20250514';
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
            'timeout' => 60,
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

        return $content;
    }

    /**
     * Process audio: transcribe + extract in one call.
     *
     * @param string $file_path    Absolute path to audio file.
     * @param string $context_type Extraction context.
     * @return array|WP_Error { transcript, summary }
     */
    public function process_audio( $file_path, $context_type ) {
        $transcript = $this->transcribe_audio( $file_path );
        if ( is_wp_error( $transcript ) ) {
            return $transcript;
        }

        $summary = $this->extract_summary( $transcript, $context_type );
        if ( is_wp_error( $summary ) ) {
            return $summary;
        }

        return array(
            'transcript' => $transcript,
            'summary'    => $summary,
        );
    }

    /**
     * Process text: extract only (no transcription needed).
     *
     * @param string $text         Input text.
     * @param string $context_type Extraction context.
     * @return string|WP_Error Summary.
     */
    public function process_text( $text, $context_type ) {
        return $this->extract_summary( $text, $context_type );
    }

    // ──────────────────────────────────────────────────────────────
    //  EXTRACTION PROMPTS
    // ──────────────────────────────────────────────────────────────

    private function get_extraction_prompt( $context_type ) {
        $prompts = array(
            'why_collection' => array(
                'system' => 'You are processing a client\'s response about why they are pursuing longevity and health changes. Return a structured JSON response.',
                'user'   => 'Extract and structure the following from this client input:

1. KEY_PEOPLE: Who matters most to them? Names, relationships, ages if mentioned.
2. KEY_MOTIVATIONS: What drives them? Be specific — not "wants to be healthy" but "wants to be able to play on the floor with grandchildren at 75."
3. KEY_FEARS: What are they afraid of? What future do they want to avoid?
4. DISTILLED_WHY: One sentence that captures their deepest motivation. Use their own words where possible.

IMPORTANT: Preserve their exact language and specific details. Do NOT generalise.
If they said "I don\'t want to end up like my mum Joan, stuck at home at 79" — keep that, don\'t flatten it to "wants to avoid dependency."

Return valid JSON with keys: key_people, motivations, fears, distilled_why

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
                'system' => 'Extract a structured weekly summary from a client check-in. Return valid JSON. Preserve specific details.',
                'user'   => 'Structure this client check-in:

1. FITNESS_ADHERENCE: What movement was done? What was missed? Why?
2. NUTRITION_ADHERENCE: What dietary changes worked? What was hard?
3. COMFORT_ZONE: What felt too easy? What was a real stretch?
4. OBSTACLES: What got in the way this week?
5. SOCIAL_ENVIRONMENT: People who helped or hindered.
6. ENERGY_MOOD: Overall state.
7. WINS: What went well.
8. NEXT_WEEK: What the client wants to change.
9. FLAGS: Tag anything concerning with [CRITICAL] — emotional distress, worsening symptoms, very low adherence (3/10 or below), request to speak to practitioner.

Return valid JSON with keys: fitness, nutrition, comfort_zone, obstacles, social, energy_mood, wins, next_week, flags, has_flags (boolean)

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
            return new WP_Error( 'file_too_large', 'Audio file must be under 25MB.', array( 'status' => 400 ) );
        }

        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( ! isset( self::ALLOWED_MIMES[ $ext ] ) ) {
            return new WP_Error( 'invalid_type', 'Accepted formats: mp3, m4a, wav, ogg, webm.', array( 'status' => 400 ) );
        }

        return true;
    }

    private function store_audio_file( $file ) {
        $upload_dir = wp_upload_dir();
        $audio_dir  = $upload_dir['basedir'] . '/hdlv2-audio/' . date( 'Y' ) . '/' . date( 'm' );

        if ( ! wp_mkdir_p( $audio_dir ) ) {
            return new WP_Error( 'dir_error', 'Could not create audio storage directory.' );
        }

        $ext      = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        $filename = 'hdlv2_' . bin2hex( random_bytes( 8 ) ) . '_' . time() . '.' . $ext;
        $dest     = $audio_dir . '/' . $filename;

        if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
            return new WP_Error( 'move_error', 'Could not save audio file.' );
        }

        return $dest;
    }

    /**
     * Delete audio files older than 90 days.
     * Hooked to hdlv2_audio_cleanup cron.
     */
    public function cleanup_old_audio() {
        $upload_dir = wp_upload_dir();
        $audio_base = $upload_dir['basedir'] . '/hdlv2-audio';

        if ( ! is_dir( $audio_base ) ) {
            return;
        }

        $cutoff = time() - ( 90 * DAY_IN_SECONDS );
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $audio_base, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        $deleted = 0;
        foreach ( $iterator as $item ) {
            if ( $item->isFile() && $item->getMTime() < $cutoff ) {
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

        if ( $deleted > 0 ) {
            error_log( "[HDLV2 Audio] Cleanup: deleted {$deleted} files older than 90 days." );
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  HELPERS
    // ──────────────────────────────────────────────────────────────

    private function get_openai_key() {
        return defined( 'HDLV2_OPENAI_API_KEY' ) ? HDLV2_OPENAI_API_KEY : '';
    }
}
