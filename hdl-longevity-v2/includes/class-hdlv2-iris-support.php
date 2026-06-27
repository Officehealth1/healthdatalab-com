<?php
/**
 * Iridology Phase-2 — pure contract helpers (NO WordPress dependency).
 *
 * Everything here is the part of the embedded-consult contract that is easy to
 * get silently wrong and where a bug is dangerous: the callback HMAC (must match
 * IrisMapper `netlify/functions/utils/hdl-hmac.js` byte-for-byte), jobId
 * validation (a Supabase key segment + Firestore doc id → path-traversal
 * sensitive), the poll-state mapping the browser depends on, and the circuit
 * breaker decisions that protect the OLS LSAPI pool when IrisMapper is down.
 *
 * Kept free of WP calls so it is unit-testable with plain `php` (mirrors how
 * IrisMapper isolated its crypto in a pure module). The WP-coupled wiring in
 * HDLV2_Iris_Consult consumes these; persistence / HTTP / $wpdb live there.
 *
 * @package HDL_Longevity_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
    // Allow direct require from the unit-test harness; no WP needed.
}

class HDLV2_Iris_Support {

    /** ±5 min freshness window — matches IrisMapper DEFAULT_SKEW_MS. */
    const SKEW_MS = 300000;

    /** Circuit-breaker thresholds (§7.6). */
    const BREAKER_THRESHOLD  = 5;     // consecutive failures to trip OPEN
    const BREAKER_COOLDOWN_MS = 60000; // half-open after 60s

    /** Terminal/soft states the poll route may surface to the browser. */
    // 'draft' = a native-capture row that landed via the auto safety-net push but
    // has NOT been finalised by the practitioner yet → the consult shows a
    // "not yet captured" placeholder (the result is held but not surfaced).
    const POLL_PASS_THROUGH = array( 'queued', 'running', 'draft', 'limit', 'unavailable', 'expired', 'archived' );

    // ─────────────────────────────────────────────────────────────────────
    //  jobId — the Supabase key segment + Firestore doc id (IrisMapper JOBID_RE)
    // ─────────────────────────────────────────────────────────────────────

    /** Allow [A-Za-z0-9:_-], 8..200 chars, and explicitly reject traversal. */
    public static function is_valid_job_id( $job_id ) {
        if ( ! is_string( $job_id ) ) {
            return false;
        }
        if ( ! preg_match( '/^[A-Za-z0-9:_-]{8,200}$/', $job_id ) ) {
            return false;
        }
        if ( strpos( $job_id, '..' ) !== false ) {
            return false; // belt-and-braces against path traversal
        }
        return true;
    }

    /**
     * Compose jobId = `${clientId}:${consultationId}:${uuid}` (the idempotency
     * key AND the Supabase key segment). Returns null if any part is missing or
     * the result is not a valid jobId.
     */
    public static function build_job_id( $client_id, $consultation_id, $uuid ) {
        $client_id       = (int) $client_id;
        $consultation_id = (int) $consultation_id;
        $uuid            = is_string( $uuid ) ? $uuid : '';
        if ( $client_id < 1 || $consultation_id < 1 || $uuid === '' ) {
            return null;
        }
        $job_id = $client_id . ':' . $consultation_id . ':' . $uuid;
        return self::is_valid_job_id( $job_id ) ? $job_id : null;
    }

    /** Extract the client id from a jobId (or 0). Used to cross-check ownership. */
    public static function client_id_from_job_id( $job_id ) {
        if ( ! self::is_valid_job_id( $job_id ) ) {
            return 0;
        }
        $parts = explode( ':', $job_id );
        return isset( $parts[0] ) ? (int) $parts[0] : 0;
    }

    /** Extract the consultation id from a jobId (or 0). */
    public static function consultation_id_from_job_id( $job_id ) {
        if ( ! self::is_valid_job_id( $job_id ) ) {
            return 0;
        }
        $parts = explode( ':', $job_id );
        return isset( $parts[1] ) ? (int) $parts[1] : 0;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  captureId — the NATIVE-CAPTURE dedupe key (Phase-2 pivot)
    //
    //  captureId = `${clientId}:${consultationId}:${irisSetHash}[:vN]`, computed
    //  SERVER-SIDE by IrisMapper over STABLE inputs (ordered (eye,mapId,
    //  sourcePhotoId)). Unlike a per-attempt jobId (fresh uuid each run), the
    //  captureId is DETERMINISTIC, so HDL keys its row + dedupe on it: same
    //  client + same iris set ⇒ same key ⇒ one row. A genuine re-shoot or an
    //  explicit ":vN" is a NEW captureId ⇒ a new row (archive prior).
    //
    //  Structurally it shares the jobId char class, so it passes is_valid_job_id
    //  too (lets a native row reuse the NOT-NULL job_id column = captureId). The
    //  dedicated validator additionally asserts the client/consultation segments
    //  are positive ints, which the receiver relies on for insert-on-callback.
    // ─────────────────────────────────────────────────────────────────────

    /** Validate a captureId: jobId char rules + numeric client/consultation head. */
    public static function is_valid_capture_id( $capture_id ) {
        if ( ! self::is_valid_job_id( $capture_id ) ) {
            return false;
        }
        $parts = explode( ':', $capture_id );
        if ( count( $parts ) < 3 ) {
            return false; // need at least client:consultation:hash
        }
        if ( (string) (int) $parts[0] !== $parts[0] || (int) $parts[0] < 1 ) {
            return false; // client segment must be a positive integer
        }
        if ( (string) (int) $parts[1] !== $parts[1] || (int) $parts[1] < 1 ) {
            return false; // consultation segment must be a positive integer
        }
        if ( $parts[2] === '' ) {
            return false; // hash segment must be present
        }
        return true;
    }

    /**
     * Compose captureId = `${clientId}:${consultationId}:${irisSetHash}` (+ `:vN`
     * for an explicit re-version). Returns null if any part is missing/invalid.
     */
    public static function build_capture_id( $client_id, $consultation_id, $iris_set_hash, $version = null ) {
        $client_id       = (int) $client_id;
        $consultation_id = (int) $consultation_id;
        $iris_set_hash   = is_string( $iris_set_hash ) ? $iris_set_hash : '';
        if ( $client_id < 1 || $consultation_id < 1 || $iris_set_hash === '' ) {
            return null;
        }
        $capture_id = $client_id . ':' . $consultation_id . ':' . $iris_set_hash;
        if ( null !== $version && (int) $version > 0 ) {
            $capture_id .= ':v' . (int) $version;
        }
        return self::is_valid_capture_id( $capture_id ) ? $capture_id : null;
    }

    /** Extract the client id from a captureId (or 0). */
    public static function client_id_from_capture_id( $capture_id ) {
        if ( ! self::is_valid_capture_id( $capture_id ) ) {
            return 0;
        }
        $parts = explode( ':', $capture_id );
        return (int) $parts[0];
    }

    /** Extract the consultation id from a captureId (or 0). */
    public static function consultation_id_from_capture_id( $capture_id ) {
        if ( ! self::is_valid_capture_id( $capture_id ) ) {
            return 0;
        }
        $parts = explode( ':', $capture_id );
        return (int) $parts[1];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  HMAC — replay-hardened callback signing (IrisMapper hdl-hmac.js parity)
    // ─────────────────────────────────────────────────────────────────────

    /** HMAC-SHA256 over `${timestamp}.${rawBody}` (hex) — Node computeSignature. */
    public static function compute_signature( $secret, $timestamp, $raw_body ) {
        $msg = (string) $timestamp . '.' . ( $raw_body === null ? '' : (string) $raw_body );
        return hash_hmac( 'sha256', $msg, (string) $secret );
    }

    /**
     * Verify an inbound signed request (the IrisMapper→HDL callback). Fail-closed.
     *
     * @return array { ok:bool, reason?: 'misconfigured'|'stale_timestamp'|'bad_signature' }
     */
    public static function verify_callback( $secret, $timestamp, $signature, $raw_body, $now_ms = null, $skew_ms = self::SKEW_MS ) {
        if ( empty( $secret ) || empty( $timestamp ) || empty( $signature ) ) {
            return array( 'ok' => false, 'reason' => 'misconfigured' );
        }
        $ts = filter_var( $timestamp, FILTER_VALIDATE_INT );
        if ( $ts === false ) {
            return array( 'ok' => false, 'reason' => 'stale_timestamp' );
        }
        $now = ( $now_ms === null ) ? (int) round( microtime( true ) * 1000 ) : (int) $now_ms;
        if ( abs( $now - $ts ) > (int) $skew_ms ) {
            return array( 'ok' => false, 'reason' => 'stale_timestamp' );
        }
        $expected = self::compute_signature( $secret, (string) $ts, $raw_body );
        if ( ! hash_equals( $expected, (string) $signature ) ) {
            return array( 'ok' => false, 'reason' => 'bad_signature' );
        }
        return array( 'ok' => true );
    }

    /** Build x-hdl-timestamp + x-hdl-signature for an outbound signed POST. */
    public static function sign_headers( $secret, $raw_body, $now_ms = null ) {
        $now = ( $now_ms === null ) ? (int) round( microtime( true ) * 1000 ) : (int) $now_ms;
        return array(
            'x-hdl-timestamp' => (string) $now,
            'x-hdl-signature' => self::compute_signature( $secret, (string) $now, $raw_body ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Callback envelope validation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Validate + normalise an inbound callback body. Accepts BOTH wire shapes:
     *
     *   NATIVE-CAPTURE (Phase-2 pivot — preferred): keyed on `captureId`, with a
     *     `finalized` flag distinguishing the auto safety-net DRAFT (finalized
     *     false) from the practitioner's "Send to HealthDataLab" FINAL (true).
     *       done  → { ok, captureId, status:'done', finalized, result, cost?, guardTriggered?, images? }
     *       error → { ok, captureId, status:'error', finalized:true, error, refused }
     *   LEGACY EMBEDDED (back-compat — the dormant HDL-initiated path): keyed on
     *     `jobId`; a bare `done` (no finalized field) means FINAL.
     *       done  → { ok, jobId, status:'done', finalized:true, result, ... }
     *       error → { ok, jobId, status:'error', finalized:true, error, refused }
     *
     * If both keys are present, captureId (native) wins. `status:'pending'` is a
     * draft synonym → normalised to status 'done' + finalized false.
     *
     * @return array { ok:bool, ... }
     */
    public static function parse_callback_body( $arr ) {
        if ( ! is_array( $arr ) ) {
            return array( 'ok' => false );
        }

        // Resolve the key: native captureId is preferred over a legacy jobId.
        $capture_id = isset( $arr['captureId'] ) ? $arr['captureId'] : '';
        $job_id     = isset( $arr['jobId'] ) ? $arr['jobId'] : '';
        $native     = false;
        if ( $capture_id !== '' ) {
            if ( ! self::is_valid_capture_id( $capture_id ) ) {
                return array( 'ok' => false );
            }
            $native = true;
        } elseif ( $job_id !== '' ) {
            if ( ! self::is_valid_job_id( $job_id ) ) {
                return array( 'ok' => false );
            }
        } else {
            return array( 'ok' => false );
        }
        $key = $native ? array( 'captureId' => $capture_id ) : array( 'jobId' => $job_id );

        $status = isset( $arr['status'] ) ? $arr['status'] : '';

        // 'pending' is the auto safety-net draft synonym (a successful analysis
        // copy that the practitioner has not finalised) → treat as done + draft.
        if ( $status === 'done' || $status === 'pending' ) {
            if ( ! isset( $arr['result'] ) || ! is_array( $arr['result'] ) ) {
                return array( 'ok' => false ); // a draft must still carry the durable result
            }
            // finalized: explicit flag wins; else a bare 'done' = final, 'pending' = draft.
            $finalized = array_key_exists( 'finalized', $arr )
                ? (bool) $arr['finalized']
                : ( $status === 'done' );
            return $key + array(
                'ok'             => true,
                'status'         => 'done',
                'finalized'      => $finalized,
                'result'         => $arr['result'],
                'cost'           => isset( $arr['cost'] ) ? $arr['cost'] : null,
                'guardTriggered' => isset( $arr['guardTriggered'] ) ? (bool) $arr['guardTriggered'] : null,
                // OPTIONAL forward-compat: raw photos for private-dir persistence
                // (the real IrisMapper callback does not yet carry these — see
                // the photo-persistence note in HDLV2_Iris_Consult).
                'images'         => ( isset( $arr['images'] ) && is_array( $arr['images'] ) ) ? $arr['images'] : null,
            );
        }
        if ( $status === 'error' ) {
            return $key + array(
                'ok'        => true,
                'status'    => 'error',
                'finalized' => true, // an analysis failure is terminal, never a draft
                'error'     => isset( $arr['error'] ) ? (string) $arr['error'] : 'Analysis failed',
                'refused'   => isset( $arr['refused'] ) ? (bool) $arr['refused'] : false,
            );
        }
        return array( 'ok' => false );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Poll-state mapping — DB row → browser contract (never leaks the raw row)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array $row { status, result_json?, areas_edited_json?, error?, refused? }
     * @return array { state, result?, error?, refused? }
     */
    public static function map_poll_state( $row ) {
        $status = isset( $row['status'] ) ? $row['status'] : 'unavailable';

        if ( $status === 'done' ) {
            // Prefer the practitioner-edited overlay (seed-from-shown pattern);
            // fall back to the AI original. Corrupt JSON degrades to error.
            $edited = isset( $row['areas_edited_json'] ) ? trim( (string) $row['areas_edited_json'] ) : '';
            $raw    = ( $edited !== '' ) ? $edited : ( isset( $row['result_json'] ) ? (string) $row['result_json'] : '' );
            $decoded = json_decode( $raw, true );
            if ( ! is_array( $decoded ) ) {
                return array( 'state' => 'error', 'error' => 'Result could not be read.' );
            }
            return array( 'state' => 'done', 'result' => $decoded );
        }

        if ( $status === 'error' ) {
            return array(
                'state'   => 'error',
                'error'   => isset( $row['error'] ) ? (string) $row['error'] : 'Analysis failed',
                'refused' => isset( $row['refused'] ) ? (bool) $row['refused'] : false,
            );
        }

        if ( in_array( $status, self::POLL_PASS_THROUGH, true ) ) {
            return array( 'state' => $status );
        }
        // Unknown pre-terminal state → queued (mirrors IrisMapper mapJobToStatus).
        return array( 'state' => 'queued' );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Circuit breaker — pure decision + transitions (shared MySQL-row backed)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Should an outbound IrisMapper call be allowed right now?
     * @return array { allow:bool, probe:bool } probe=true ⇒ half-open trial.
     */
    public static function breaker_decide( $state, $now_ms ) {
        $s = isset( $state['state'] ) ? $state['state'] : 'closed';
        if ( $s === 'open' ) {
            $opened = isset( $state['opened_at'] ) ? (int) $state['opened_at'] : 0;
            if ( ( (int) $now_ms - $opened ) >= self::BREAKER_COOLDOWN_MS ) {
                return array( 'allow' => true, 'probe' => true ); // half-open: one trial
            }
            return array( 'allow' => false, 'probe' => false );
        }
        return array( 'allow' => true, 'probe' => false );
    }

    /** A counted failure (timeout/5xx). 401/404/429 must NOT be passed here. */
    public static function breaker_on_failure( $state, $now_ms, $threshold = self::BREAKER_THRESHOLD ) {
        $failures = ( isset( $state['failures'] ) ? (int) $state['failures'] : 0 ) + 1;
        if ( $failures >= (int) $threshold ) {
            return array( 'state' => 'open', 'failures' => $failures, 'opened_at' => (int) $now_ms );
        }
        return array( 'state' => 'closed', 'failures' => $failures, 'opened_at' => isset( $state['opened_at'] ) ? (int) $state['opened_at'] : 0 );
    }

    /** A success closes the breaker and zeroes the counter. */
    public static function breaker_on_success( $state ) {
        return array( 'state' => 'closed', 'failures' => 0, 'opened_at' => 0 );
    }
}
