<?php
/**
 * "Listen" on the consultation screen (v0.49.04, plan 15; switch hdlv2_ff_live_listen, absent = off, and only
 * with hdlv2_ff_live_questions on as well).
 *
 * While "Listen" is on, the browser records the consultation in pieces cut at pauses (6 to 30 seconds each) and
 * posts each piece here. This class sends the piece straight to Deepgram (nova-3, the service the recorder already
 * uses), with the client's own medicine and condition names as hint words, and hands the words back. The page
 * puts them at the end of the notes box, which saves itself as typing does. The sound is not kept: the upload's
 * temporary file is the only copy and PHP removes it when the request ends. The questions themselves come from
 * "Suggest questions" (HDLV2_Live_Questions, unchanged), asked by the page on its own path
 * /consultation/live-questions/auto.
 *
 * Money: each practitioner has 600 pieces an hour (rate-limit group `listen`: the most the page can cut, so an
 * hour of talk is never stopped by it); what holds the money is the day guard: the whole site has DAY_MINUTES of
 * sound a day (about $3.36 at Deepgram's pay-as-you-go price with hint words, read 2026-11-25). When Deepgram
 * says the credit is used up, the route says so in our own words and one alert e-mail goes that day.
 *
 * The log holds the consultation id and numbers only, never a client's words.
 *
 * @package HDL_Longevity_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Live_Listen {

    const OPTION      = 'hdlv2_ff_live_listen';
    const PIECE_MAX   = 262144;  // 256 KB: 30 s at 48 kbps (Safari's mp4, 180,000 bytes) with room; a piece is cut at 30 s
    const EST_BPS     = 750;     // bytes a second at Opus's lowest rate (6 kbps): the estimate counted before Deepgram
                                 // answers can only be too high, never too low; the answer corrects it
    const TIMEOUT     = 15;      // seconds; a piece must come back while the talk goes on, so not the queue
    const DAY_MINUTES = 600;     // minutes of sound a UTC day for the whole site
    const KEYTERMS    = 50;      // Deepgram: "focus on the most important 20-50 terms" (500 tokens at most)
    const KEYTERM_LEN = 40;      // characters of each
    const SEQ_MAX     = 100000;
    // What finfo may call a recorder's piece: WebM and Ogg from Chrome and Firefox, MP4 from Safari.
    const TYPES = array( 'audio/webm' => 'audio/webm', 'video/webm' => 'audio/webm', 'audio/ogg' => 'audio/ogg', 'audio/mp4' => 'audio/mp4', 'video/mp4' => 'audio/mp4', 'audio/x-m4a' => 'audio/mp4' );

    const MISSED    = 'A few seconds were not heard.';
    const RESTING   = 'Listening is resting for today.';
    const NO_CREDIT = 'Listening is not available right now.';

    public static function on() {
        return HDLV2_Live_Questions::on() && (bool) get_option( self::OPTION, false );
    }

    /** The consultation screen's settings with live_listen and the piece address added when on; untouched when off. */
    public static function config( array $cfg ) {
        return self::on() ? $cfg + array( 'live_listen' => 1, 'listen_url' => rest_url( 'hdl-v2/v1/audio/listen-piece' ) ) : $cfg;
    }

    /** Enqueues the listening script when on (it borrows the audio component's type pick and meter); nothing when off. */
    public static function enqueue() {
        if ( self::on() ) {
            wp_enqueue_script( 'hdlv2-live-listen', HDLV2_PLUGIN_URL . 'assets/js/hdlv2-live-listen.js', array( 'hdlv2-audio-component' ), HDLV2_VERSION, true );
        }
    }

    /** Seconds of sound sent to Deepgram today (UTC), whole site. */
    private static function day_key() {
        return 'hdlv2_listen_s_' . gmdate( 'Ymd' );
    }

    /**
     * Hint words: the client's own medicine and condition names from the form (stage 3 `medications`,
     * `existing_conditions`), split at commas, semicolons and lines, cleaned to letters, digits, spaces and hyphens.
     * A part of up to four words is kept whole ("type 2 diabetes"). Every word of seven letters or more is also a
     * hint by itself (v0.49.05: "atorvastatin 20mg" alone did not teach "atorvastatin"), and a longer part gives
     * only those. Single words come first, so they are the ones kept when the list is cut at KEYTERMS.
     * ponytail: a guess at names in free text; a list of known medicines would be sharper, add one if the run shows misses.
     */
    public static function keyterms( $progress ) {
        $s3    = json_decode( (string) ( $progress->stage3_data ?? '' ), true );
        $s3    = is_array( $s3 ) ? $s3 : array();
        $text  = (string) ( $s3['medications'] ?? '' ) . "\n" . (string) ( $s3['existing_conditions'] ?? '' );
        $text  = preg_replace( '/<[^>]*>[^<]*<\/[^>]*>|<[^>]*>/u', ' ', $text ); // a tag and what it wraps are not names
        $singles = array();
        $phrases = array();
        foreach ( preg_split( '/[,;\/\n\r]+/u', $text ) as $part ) {
            $part  = trim( preg_replace( '/\s+/u', ' ', preg_replace( '/[^\p{L}\p{N} \-]+/u', ' ', $part ) ) );
            $words = '' === $part ? array() : explode( ' ', $part );
            foreach ( $words as $w ) {
                if ( mb_strlen( $w ) >= 7 ) {
                    $singles[] = $w;
                }
            }
            if ( count( $words ) <= 4 ) {
                $phrases[] = $part;
            }
        }
        $terms = array();
        foreach ( array_merge( $singles, $phrases ) as $t ) {
            $t = trim( mb_substr( $t, 0, self::KEYTERM_LEN ) );
            if ( mb_strlen( $t ) >= 3 && ! isset( $terms[ mb_strtolower( $t ) ] ) ) {
                $terms[ mb_strtolower( $t ) ] = $t;
            }
        }
        return array_slice( array_values( $terms ), 0, self::KEYTERMS );
    }

    /**
     * POST /audio/listen-piece { progress_id, seq, audio } — registered by HDLV2_Consultation only when on, behind
     * check_practitioner_auth; ownership by plan 13's owned() (owner or admin, anyone else 404, as "Suggest
     * questions"). One Idempotency-Key per attempt: a lost answer retried with the same key is charged once.
     */
    public static function rest_piece( $request ) {
        $found = HDLV2_Health_Analysis::owned( (int) $request->get_param( 'progress_id' ) );
        if ( is_wp_error( $found ) ) {
            return $found;
        }
        list( $progress, $consult ) = $found;

        $seq = $request->get_param( 'seq' );
        if ( ! ( is_int( $seq ) || ( is_string( $seq ) && ctype_digit( $seq ) ) ) || (int) $seq < 0 || (int) $seq > self::SEQ_MAX ) {
            return new WP_Error( 'bad_seq', 'The piece number could not be read.', array( 'status' => 400 ) );
        }
        $seq   = (int) $seq;
        $files = $request->get_file_params();
        $file  = $files['audio'] ?? null;
        if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? -1 ) || empty( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
            return new WP_Error( 'no_audio', 'No sound came with the piece.', array( 'status' => 400 ) );
        }
        $bytes = (int) filesize( $file['tmp_name'] );
        if ( $bytes > self::PIECE_MAX ) {
            return new WP_Error( 'piece_too_large', 'The piece is too long.', array( 'status' => 413 ) );
        }
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $found_type = (string) finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );
        if ( ! isset( self::TYPES[ $found_type ] ) ) {
            return new WP_Error( 'piece_not_audio', 'The piece is not sound in a type we take.', array( 'status' => 415 ) );
        }
        $mime = self::TYPES[ $found_type ];

        $scope = 'listen:' . (int) $consult->id . ':' . get_current_user_id();
        return HDLV2_Idempotency::wrap_ai( $request, $scope, function () use ( $progress, $consult, $seq, $file, $bytes, $mime ) {
            // Two pieces at the same moment may both pass this check and overshoot the day by a piece or two: accepted.
            // ponytail: the day's count is read here and written below, so two listeners at one moment can lose a
            // piece's seconds. Fine for one listener at a time; an atomic count before several listen at once.
            $day = (float) get_transient( self::day_key() );
            if ( $day >= self::DAY_MINUTES * 60 ) {
                self::log( $consult, $bytes, 0, 0, 'resting' );
                return new WP_Error( 'listen_resting', self::RESTING, array( 'status' => 429 ) );
            }
            // The piece is counted before Deepgram is asked (an estimate from its size), then corrected to the seconds
            // Deepgram reports, silent pieces included; a failed call keeps the estimate (it may have been billed).
            $est = $bytes / self::EST_BPS;
            set_transient( self::day_key(), $day + $est, 2 * DAY_IN_SECONDS );
            $out = HDLV2_Deepgram_Service::transcribe_file( $file['tmp_name'], array(
                'mime'     => $mime,
                'timeout'  => self::TIMEOUT,
                'keyterms' => self::keyterms( $progress ),
            ) );
            if ( is_wp_error( $out ) && 'empty_transcript' === $out->get_error_code() ) {
                // A piece with no speech is not a failure.
                $out = array( 'transcript' => '', 'duration_sec' => $out->get_error_data()['duration_sec'] ?? null );
            }
            if ( is_wp_error( $out ) ) {
                $status = (int) ( $out->get_error_data()['status'] ?? 0 );
                self::log( $consult, $bytes, 0, 0, 402 === $status ? 'no_credit' : 'failed:' . $out->get_error_code() );
                if ( 402 === $status ) {
                    self::alert_no_credit();
                    return new WP_Error( 'listen_no_credit', self::NO_CREDIT, array( 'status' => 503 ) );
                }
                return new WP_Error( 'listen_failed', self::MISSED, array( 'status' => 502 ) );
            }
            $sec = null === $out['duration_sec'] ? $est : (float) $out['duration_sec'];
            set_transient( self::day_key(), (float) get_transient( self::day_key() ) - $est + $sec, 2 * DAY_IN_SECONDS );
            $text = (string) $out['transcript'];
            self::log( $consult, $bytes, $sec, mb_strlen( $text ), 'ok' );
            return rest_ensure_response( array( 'seq' => $seq, 'text' => $text ) );
        } );
    }

    /** One e-mail a UTC day to the plugin's alert setting when Deepgram's credit is used up. No client data in it. */
    private static function alert_no_credit() {
        $flag = 'hdlv2_listen_credit_mail_' . gmdate( 'Ymd' );
        if ( get_transient( $flag ) ) {
            return;
        }
        set_transient( $flag, 1, 2 * DAY_IN_SECONDS );
        wp_mail(
            implode( ',', HDLV2_Privacy_Requests::alert_to() ),
            'HealthDataLab: Deepgram credit is used up',
            "Deepgram refused a piece of a consultation because the account has no credit left (402).\n\n"
            . "Listen on the consultation screen has stopped. Recording with the mic and Suggest questions still work.\n"
            . "Top up in Deepgram's console under Billing. This e-mail is sent once a day.\n"
        );
    }

    private static function log( $consult, $bytes, $sec, $chars, $result ) {
        error_log( sprintf( '[HDLV2 listen] consult=%d bytes=%d sec=%.1f chars=%d result=%s', (int) $consult->id, (int) $bytes, (float) $sec, (int) $chars, $result ) );
    }
}
