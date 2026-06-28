<?php
/**
 * Deepgram Transcription Service.
 *
 * Thin wrapper around Deepgram's /v1/listen pre-recorded transcription
 * endpoint. Handles audio up to Deepgram's native file-size limit
 * (hundreds of MB — far beyond OpenAI Whisper's 25MB).
 *
 * Model: Nova-3 (general). Language: English (configurable).
 * Output: single `transcript` string, plus diagnostic fields.
 *
 * Authentication: `HDLV2_DEEPGRAM_API_KEY` constant in wp-config.php.
 *
 * This service is STATELESS — it reads a file, hits the API, returns text.
 * Persistence / retry / chaining happens at the HDLV2_Job_Queue layer.
 *
 * @package HDL_Longevity_V2
 * @since 0.17.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Deepgram_Service {

    const API_URL = 'https://api.deepgram.com/v1/listen';
    const DEFAULT_MODEL = 'nova-3';
    const DEFAULT_LANGUAGE = 'en';

    /**
     * Nova-3 caps inbound file size at 2 GB and has no hard duration cap
     * beyond that. We enforce a lower app-level cap to protect the VPS
     * disk (audio uploads land on /var/www temp first).
     */
    const MAX_FILE_BYTES = 250 * 1024 * 1024; // 250MB — ~4 hours at 128 kbps

    /** HTTP timeout for the Deepgram call. 4 min covers 1-hour audio uploads. */
    const HTTP_TIMEOUT_SEC = 240;

    /**
     * Transcribe a local audio file.
     *
     * @param string $file_path Absolute path to audio file on disk.
     * @param array  $opts {
     *     @type string $model      Override model (default: nova-3)
     *     @type string $language   BCP-47 language tag (default: en)
     *     @type bool   $diarize    Speaker diarisation (default: false)
     *     @type bool   $punctuate  Punctuation (default: true)
     *     @type bool   $smart_format  Smart formatting: numbers, dates, etc. (default: true)
     * }
     * @return array|WP_Error {
     *     @type string $transcript   Plain text transcript
     *     @type float  $confidence   0-1 model confidence
     *     @type float  $duration_sec Audio length
     *     @type string $model        Model used
     *     @type string $request_id   Deepgram request id for support tickets
     * }
     */
    public static function transcribe_file( $file_path, $opts = array() ) {
        $api_key = self::get_api_key();
        if ( ! $api_key ) {
            return new WP_Error( 'no_api_key', 'HDLV2_DEEPGRAM_API_KEY not configured in wp-config.php.' );
        }

        if ( ! file_exists( $file_path ) ) {
            return new WP_Error( 'file_not_found', 'Audio file not found: ' . basename( $file_path ) );
        }

        $size = filesize( $file_path );
        if ( $size > self::MAX_FILE_BYTES ) {
            return new WP_Error( 'file_too_large', sprintf( 'Audio file %dMB exceeds %dMB limit.', (int) ( $size / 1024 / 1024 ), (int) ( self::MAX_FILE_BYTES / 1024 / 1024 ) ) );
        }

        // Build query params
        $params = array(
            'model'        => isset( $opts['model'] ) ? $opts['model'] : self::DEFAULT_MODEL,
            'language'     => isset( $opts['language'] ) ? $opts['language'] : self::DEFAULT_LANGUAGE,
            'smart_format' => ! empty( $opts['smart_format'] ) || ! isset( $opts['smart_format'] ) ? 'true' : 'false',
            'punctuate'    => ! empty( $opts['punctuate'] ) || ! isset( $opts['punctuate'] ) ? 'true' : 'false',
        );
        if ( ! empty( $opts['diarize'] ) ) {
            $params['diarize'] = 'true';
        }

        $url = self::API_URL . '?' . http_build_query( $params );

        // Derive Content-Type from file extension — Deepgram sniffs the audio
        // format but an accurate content-type speeds detection + reduces ambiguous
        // failures on .m4a (iOS Voice Memos) and .webm (browser MediaRecorder).
        $ext  = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        $mime = self::ext_to_mime( $ext );

        // Read file contents. Deepgram accepts raw binary body.
        $body = file_get_contents( $file_path );
        if ( $body === false ) {
            return new WP_Error( 'read_failed', 'Could not read audio file.' );
        }

        $response = wp_remote_post( $url, array(
            'headers' => array(
                'Authorization' => 'Token ' . $api_key,
                'Content-Type'  => $mime,
            ),
            'body'    => $body,
            'timeout' => self::HTTP_TIMEOUT_SEC,
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( '[HDLV2 Deepgram] HTTP error: ' . $response->get_error_message() );
            return new WP_Error( 'http_error', $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( $code !== 200 ) {
            $err = '';
            if ( is_array( $data ) ) {
                $err = $data['err_msg'] ?? $data['err_code'] ?? $data['message'] ?? '';
            }
            if ( ! $err ) $err = 'HTTP ' . $code;
            error_log( '[HDLV2 Deepgram] API ' . $code . ': ' . $err );
            return new WP_Error( 'deepgram_error', $err, array( 'status' => $code ) );
        }

        // Deepgram response shape:
        //   results.channels[0].alternatives[0].transcript (string)
        //   results.channels[0].alternatives[0].confidence (float)
        //   metadata.duration (float)
        //   metadata.request_id (string)
        $alt = $data['results']['channels'][0]['alternatives'][0] ?? null;
        if ( ! $alt || ! isset( $alt['transcript'] ) ) {
            error_log( '[HDLV2 Deepgram] Unexpected response shape — sha256=' . hash( 'sha256', (string) $raw ) . ' len=' . strlen( (string) $raw ) . ' reason=missing_transcript' ); // B.2 — the raw body embeds the client's verbatim transcript; log a fingerprint only
            return new WP_Error( 'parse_failed', 'Deepgram returned an unexpected response shape.' );
        }

        $transcript = trim( (string) $alt['transcript'] );
        if ( $transcript === '' ) {
            return new WP_Error( 'empty_transcript', 'No speech detected in audio.' );
        }

        return array(
            'transcript'   => $transcript,
            'confidence'   => isset( $alt['confidence'] ) ? (float) $alt['confidence'] : null,
            'duration_sec' => isset( $data['metadata']['duration'] ) ? (float) $data['metadata']['duration'] : null,
            'model'        => $params['model'],
            'request_id'   => $data['metadata']['request_id'] ?? '',
        );
    }

    /**
     * Quick connectivity check. Hits /v1/projects. Used by the smoke test.
     */
    public static function ping() {
        $api_key = self::get_api_key();
        if ( ! $api_key ) {
            return new WP_Error( 'no_api_key', 'HDLV2_DEEPGRAM_API_KEY not configured.' );
        }

        $response = wp_remote_get( 'https://api.deepgram.com/v1/projects', array(
            'headers' => array( 'Authorization' => 'Token ' . $api_key ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $body['projects'] ) ) {
            return new WP_Error( 'ping_failed', 'Deepgram ping failed: HTTP ' . $code );
        }

        return array(
            'ok'         => true,
            'project_id' => $body['projects'][0]['project_id'] ?? '',
            'project'    => $body['projects'][0]['name'] ?? '',
        );
    }

    private static function get_api_key() {
        return defined( 'HDLV2_DEEPGRAM_API_KEY' ) ? HDLV2_DEEPGRAM_API_KEY : '';
    }

    private static function ext_to_mime( $ext ) {
        $map = array(
            'mp3'  => 'audio/mpeg',
            'm4a'  => 'audio/mp4',
            'mp4'  => 'audio/mp4',
            'wav'  => 'audio/wav',
            'ogg'  => 'audio/ogg',
            'opus' => 'audio/ogg',
            'webm' => 'audio/webm',
            'flac' => 'audio/flac',
        );
        return $map[ $ext ] ?? 'application/octet-stream';
    }
}
