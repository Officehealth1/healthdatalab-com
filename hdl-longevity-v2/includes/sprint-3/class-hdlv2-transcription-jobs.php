<?php
/**
 * Transcription Job Handlers.
 *
 * Registers two handlers with HDLV2_Job_Queue:
 *
 *   transcribe_audio
 *     Payload: { file_path, context_type, reference_id, delete_after? }
 *     Calls Deepgram, writes transcript to the right table based on
 *     context_type, and for 'consultation_notes' chains an
 *     organise_consultation job. Returns { transcript, chain_job_id? }.
 *
 *   organise_consultation
 *     Payload: { consultation_id }
 *     Runs the existing HDLV2_AI_Service::organise_consultation_notes
 *     prompt against the raw_notes stored in the consultation row and
 *     saves ai_organised_notes. Returns { ai_organised_notes }.
 *
 * Both handlers are idempotent: if the target column is already populated
 * with matching data, they short-circuit and return the existing value
 * without re-calling the API. This matters for retries (cost control).
 *
 * @package HDL_Longevity_V2
 * @since 0.17.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Transcription_Jobs {

    const JOB_TRANSCRIBE = 'transcribe_audio';
    const JOB_ORGANISE   = 'organise_consultation';

    public static function register() {
        HDLV2_Job_Queue::register_handler( self::JOB_TRANSCRIBE, array( __CLASS__, 'handle_transcribe' ) );
        HDLV2_Job_Queue::register_handler( self::JOB_ORGANISE,   array( __CLASS__, 'handle_organise' ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  HANDLER: transcribe_audio
    // ──────────────────────────────────────────────────────────────

    /**
     * Transcribe audio via Deepgram and route the transcript.
     *
     * Context types:
     *   - consultation_notes → writes to hdlv2_consultation_notes.raw_notes,
     *     chains organise_consultation so the review panel is ready when
     *     the practitioner clicks Generate Final Report.
     *   - why_collection / weekly_checkin → transcript returned via the
     *     job's result field ONLY. No DB write here — the client's form
     *     reads the transcript, pushes it into its own state, and the
     *     existing submit flow persists + runs the Claude extraction.
     *
     * reference_id is required only for consultation_notes.
     */
    public static function handle_transcribe( $payload, $job ) {
        $file_path    = isset( $payload['file_path'] ) ? (string) $payload['file_path'] : '';
        $context_type = isset( $payload['context_type'] ) ? (string) $payload['context_type'] : '';
        $reference_id = isset( $payload['reference_id'] ) ? (int) $payload['reference_id'] : 0;

        if ( ! $file_path || ! $context_type ) {
            return new WP_Error( 'bad_payload', 'Missing file_path or context_type.' );
        }
        if ( $context_type === 'consultation_notes' && ! $reference_id ) {
            return new WP_Error( 'bad_payload', 'reference_id required for consultation_notes.' );
        }

        // v0.20.12 — removed the "skip Deepgram if raw_notes already has
        // content" short-circuit. It was meant as retry protection but was
        // checking the wrong signal: raw_notes accumulates across every
        // recording + typing, so on the 2nd recording it saw the 1st's text,
        // short-circuited, and returned that OLD text as the "new transcript"
        // — producing duplicate appends in the textarea and dropping every
        // new recording's audio on the floor.
        //
        // Retry idempotency is already handled at the job-queue layer via
        // idem_key (sha1 of file_path). The same physical audio file can't
        // be re-enqueued, and run_job never re-runs a completed row. So no
        // guard is needed here — each job runs Deepgram once against its
        // own file, and different recordings = different files = different
        // transcripts.
        if ( ! file_exists( $file_path ) ) {
            return new WP_Error( 'file_missing', 'Audio file missing on disk: ' . basename( $file_path ) );
        }

        $result = HDLV2_Deepgram_Service::transcribe_file( $file_path );
        if ( is_wp_error( $result ) ) {
            // v0.36.10 — empty_transcript is deterministic. The same silent
            // audio always returns no speech; retrying 4x with 30s/2m/8m
            // backoff burns 10+ minutes of "Retrying transcription." UX
            // before permanent fail. Convert to a successful empty-transcript
            // result so the frontend's existing "No speech detected. Try again
            // or type your notes." branch fires immediately
            // (audio-component.js line ~1146). Network / auth / parse errors
            // still propagate as WP_Error and retry as before.
            if ( $result->get_error_code() === 'empty_transcript' ) {
                return array(
                    'transcript'   => '',
                    'duration_sec' => 0,
                    'confidence'   => null,
                    'request_id'   => '',
                    'context_type' => $context_type,
                    'reference_id' => $reference_id,
                );
            }
            return $result;
        }
        $transcript = $result['transcript'];
        $duration   = $result['duration_sec'];
        $confidence = $result['confidence'];
        $request_id = $result['request_id'];

        // Persist ONLY for consultation_notes. Client contexts return the
        // transcript via the job's result field and the form flow handles
        // persistence itself.
        if ( $context_type === 'consultation_notes' ) {
            $write = self::store_transcript( $context_type, $reference_id, $transcript, $file_path );
            if ( is_wp_error( $write ) ) return $write;
        }

        $out = array(
            'transcript'   => $transcript,
            'duration_sec' => $duration,
            'confidence'   => $confidence,
            'request_id'   => $request_id,
            'context_type' => $context_type,
            'reference_id' => $reference_id,
        );

        // Chain into AI organise for consultation. Returns chain_job_id in
        // the result so the frontend polling loop can follow it.
        //
        // max_attempts=2: organise failures tend to be content-shape issues
        // (Claude returning non-JSON for unexpected input) rather than
        // transient network errors. Retrying 4× burns Claude tokens without
        // improving the outcome. One retry covers a true network blip; after
        // that the practitioner clicks Generate Final Report manually to
        // force a fresh attempt.
        if ( $context_type === 'consultation_notes' ) {
            $chain = HDLV2_Job_Queue::enqueue(
                self::JOB_ORGANISE,
                array( 'consultation_id' => $reference_id ),
                array(
                    'reference_id' => $reference_id,
                    'priority'     => 90,
                    // v0.47.2 (RF-6) — idem_key varies with the SOURCE FILE
                    // (each upload gets a unique random path). The old constant
                    // 'organise:<id>' key + enqueue() honouring a 'completed' row
                    // meant organise ran ONLY for the first recording — every
                    // re-record nulled ai_organised_notes but the re-enqueue
                    // returned the stale completed job. Keying on the file path
                    // re-organises on EVERY new clip (even two distinct takes that
                    // transcribe to identical text — which a transcript-hash key
                    // would wrongly de-dup) while still de-duplicating a true
                    // re-run of the SAME transcribe job (same path) on retry.
                    'idem_key'     => 'organise:' . $reference_id . ':' . substr( sha1( (string) $file_path ), 0, 12 ),
                    'max_attempts' => 2,
                )
            );
            if ( ! is_wp_error( $chain ) ) {
                $out['chain_job_id'] = (int) $chain;
            } else {
                error_log( '[HDLV2 transcribe] chain enqueue failed: ' . $chain->get_error_message() );
            }
        }

        return $out;
    }

    /**
     * Read whatever transcript already lives in the target table for this
     * context + reference. Returns '' if nothing stored yet.
     */
    private static function load_existing_transcript( $context_type, $reference_id ) {
        global $wpdb;
        switch ( $context_type ) {
            case 'consultation_notes':
                return (string) $wpdb->get_var( $wpdb->prepare(
                    "SELECT raw_notes FROM {$wpdb->prefix}hdlv2_consultation_notes WHERE id = %d LIMIT 1",
                    $reference_id
                ) );
            case 'why_collection':
                return (string) $wpdb->get_var( $wpdb->prepare(
                    "SELECT raw_input FROM {$wpdb->prefix}hdlv2_why_profiles WHERE id = %d LIMIT 1",
                    $reference_id
                ) );
            case 'weekly_checkin':
                return (string) $wpdb->get_var( $wpdb->prepare(
                    "SELECT raw_transcript FROM {$wpdb->prefix}hdlv2_checkins WHERE id = %d LIMIT 1",
                    $reference_id
                ) );
            default:
                return '';
        }
    }

    /**
     * Write the transcript to the right table based on context.
     * For consultation_notes: merges with existing typed notes so the
     * practitioner's prior typing isn't lost when they then upload audio.
     */
    private static function store_transcript( $context_type, $reference_id, $transcript, $file_path ) {
        global $wpdb;

        switch ( $context_type ) {
            case 'consultation_notes':
                // v0.47.2 (RF-8) — ATOMIC append. The old read-then-update
                // (SELECT typed_notes ... then $wpdb->update) was a lost-update
                // race: with MAX_CONCURRENT=3, two transcribe jobs for the same
                // consultation could both read the same typed_notes before either
                // wrote, dropping one transcript. A single CONCAT UPDATE appends
                // under the row lock so concurrent clips both land. typed_notes is
                // appended first (using its OLD value), then raw_notes mirrors the
                // just-updated typed_notes (MySQL evaluates SET assignments
                // left-to-right, so raw_notes = typed_notes sees the new value).
                $ok = $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}hdlv2_consultation_notes
                        SET typed_notes = TRIM(CONCAT(COALESCE(typed_notes,''), IF(TRIM(COALESCE(typed_notes,''))='', '', CHAR(10,10)), %s)),
                            raw_notes = typed_notes,
                            raw_audio_url = %s,
                            ai_organised_notes = NULL,
                            practitioner_approved = 0,
                            approved_at = NULL
                      WHERE id = %d",
                    $transcript,
                    $file_path,
                    $reference_id
                ) );
                if ( $ok === false ) {
                    return new WP_Error( 'db_error', 'Could not persist transcript: ' . $wpdb->last_error );
                }
                return true;

            case 'why_collection':
                $ok = $wpdb->update(
                    $wpdb->prefix . 'hdlv2_why_profiles',
                    array( 'raw_input' => $transcript ),
                    array( 'id' => $reference_id )
                );
                if ( $ok === false ) {
                    return new WP_Error( 'db_error', 'Could not persist WHY transcript.' );
                }
                return true;

            case 'weekly_checkin':
                $ok = $wpdb->update(
                    $wpdb->prefix . 'hdlv2_checkins',
                    array( 'raw_transcript' => $transcript, 'input_method' => 'audio' ),
                    array( 'id' => $reference_id )
                );
                if ( $ok === false ) {
                    return new WP_Error( 'db_error', 'Could not persist check-in transcript.' );
                }
                return true;

            default:
                return new WP_Error( 'unknown_context', 'Unknown context_type: ' . $context_type );
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  HANDLER: organise_consultation
    // ──────────────────────────────────────────────────────────────

    /**
     * Runs the Claude organise prompt against the consultation's raw_notes
     * and stores the resulting structured JSON.
     *
     * Idempotent: if ai_organised_notes is already populated AND
     * practitioner hasn't reset it via a fresh transcribe (which clears it),
     * we skip the Claude call and return the stored output.
     */
    public static function handle_organise( $payload, $job ) {
        global $wpdb;

        $consult_id = isset( $payload['consultation_id'] ) ? (int) $payload['consultation_id'] : 0;
        if ( ! $consult_id ) {
            return new WP_Error( 'bad_payload', 'Missing consultation_id.' );
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, raw_notes, typed_notes, ai_organised_notes
             FROM {$wpdb->prefix}hdlv2_consultation_notes WHERE id = %d LIMIT 1",
            $consult_id
        ) );
        if ( ! $row ) {
            return new WP_Error( 'not_found', 'Consultation not found: ' . $consult_id );
        }

        // Idempotency — return existing organised output if present
        if ( ! empty( $row->ai_organised_notes ) ) {
            $existing = json_decode( $row->ai_organised_notes, true );
            if ( is_array( $existing ) ) {
                return array(
                    'ai_organised_notes' => $existing,
                    'consultation_id'    => $consult_id,
                    'skipped'            => true,
                );
            }
        }

        $raw = trim( (string) ( $row->raw_notes ?: $row->typed_notes ) );
        if ( $raw === '' ) {
            return new WP_Error( 'empty_notes', 'No notes to organise for consultation ' . $consult_id );
        }

        if ( ! class_exists( 'HDLV2_AI_Service' ) ) {
            return new WP_Error( 'missing_dep', 'HDLV2_AI_Service unavailable.' );
        }

        $organised = HDLV2_AI_Service::organise_consultation_notes( $raw );
        if ( is_wp_error( $organised ) ) {
            return $organised;
        }

        $ok = $wpdb->update(
            $wpdb->prefix . 'hdlv2_consultation_notes',
            array(
                'ai_organised_notes'    => wp_json_encode( $organised ),
                'practitioner_approved' => 0,
                'approved_at'           => null,
            ),
            array( 'id' => $consult_id )
        );
        if ( $ok === false ) {
            return new WP_Error( 'db_error', 'Could not persist organised notes: ' . $wpdb->last_error );
        }

        return array(
            'ai_organised_notes' => $organised,
            'consultation_id'    => $consult_id,
        );
    }
}
