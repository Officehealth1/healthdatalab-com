<?php
/**
 * Webhook Retry — automatic re-fire of Make.com webhooks on failure.
 *
 * Closes a production-readiness gap surfaced by the v0.40.20 audit:
 * before this class, three webhook fires (Draft Report, Final Report,
 * Flight Plan PDF) were "fire-and-forget" with no retry path. A 5-minute
 * Make.com outage during one of these stages permanently lost the
 * downstream PDF + email — Webhook Monitor recorded the failure but
 * the manage_options admin notice was invisible to practitioners.
 *
 * Stage 2 already had a sibling-pattern retry cron (v0.40.19); this class
 * generalises that for the remaining three. Design choices:
 *
 *   - wp_schedule_single_event with a 5-minute delay per retry. WP-Cron
 *     handles dispatch + retry-on-fail at the OS layer; this class only
 *     bounds total attempts (max 3) and clears state on success.
 *   - Per-(context, row_id) attempt counter stored as a transient
 *     (24-hour TTL — long enough for an extended Make.com outage, short
 *     enough to auto-recover if the row is regenerated later).
 *   - Each context registers its own re-fire callback via register_handler().
 *     The callback signature is fn(int $row_id): mixed — return WP_Error
 *     or any non-2xx-ish thing to signal failure, anything else = success.
 *
 * Failure detection uses the same shape HDLV2_Webhook_Monitor returns
 * (wp_remote_post result), so callers pass the response directly into
 * maybe_retry() without unwrapping.
 *
 * @package HDL_Longevity_V2
 * @since 0.40.20
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Webhook_Retry {

    /** Max retry attempts per (context, row_id). After this, the row is
     *  left for manual practitioner intervention (logged to error_log). */
    const MAX_ATTEMPTS = 3;

    /** Delay between retries (seconds). Single value — WP_Cron doesn't
     *  natively do exponential backoff for single-event scheduling, and
     *  the per-attempt transient prevents runaway retries anyway. */
    const RETRY_DELAY  = 300; // 5 minutes

    /** Transient TTL — must exceed MAX_ATTEMPTS × RETRY_DELAY plus headroom
     *  so the counter outlives the full retry sequence. 24h is the same
     *  retention window as HDLV2_Webhook_Monitor's failure ledger. */
    const TRANSIENT_TTL = 86400;

    /** Per-context handler registry. Populated at boot by each owning class
     *  (Staged_Form, Final_Report, Flight_Plan) via register_handler(). */
    private static $handlers = array();

    /**
     * Register a re-fire callback for a webhook context. Called once per
     * context at plugins_loaded.
     *
     * @param string   $context  e.g. 'draft', 'final', 'flight_pdf'.
     * @param callable $handler  fn(int $row_id): mixed
     */
    public static function register_handler( $context, $handler ) {
        self::$handlers[ (string) $context ] = $handler;
    }

    /**
     * One-time wp-cron hook bootstrap. Each context's re-fire hook is
     * `hdlv2_webhook_retry_<context>`; this method wires the cron action.
     * Idempotent — safe to call from every plugins_loaded.
     */
    public static function register_cron_hooks() {
        foreach ( array( 'draft', 'final', 'flight_pdf' ) as $context ) {
            $hook = 'hdlv2_webhook_retry_' . $context;
            if ( ! has_action( $hook, array( __CLASS__, 'run_scheduled_retry' ) ) ) {
                add_action( $hook, array( __CLASS__, 'run_scheduled_retry' ), 10, 2 );
            }
        }
    }

    /**
     * Called by callers immediately after HDLV2_Webhook_Monitor::fire().
     * Reads the response shape and either enqueues a retry or clears state.
     *
     * @param array|WP_Error|null $response  Response from Webhook_Monitor::fire.
     * @param string              $context   'draft' | 'final' | 'flight_pdf'.
     * @param int                 $row_id    Owning row id (progress / report / plan).
     */
    public static function maybe_retry( $response, $context, $row_id ) {
        $row_id  = (int) $row_id;
        $context = (string) $context;
        if ( ! $row_id || ! $context ) return;

        if ( self::looks_successful( $response ) ) {
            self::clear_attempts( $context, $row_id );
            return;
        }

        $attempts = self::get_attempts( $context, $row_id );
        if ( $attempts >= self::MAX_ATTEMPTS ) {
            // Exhausted — log for manual recovery. The Webhook Monitor
            // entry already captured the response body excerpt at the
            // failed-fire site, so ops have the diagnostic context.
            error_log( sprintf(
                '[HDLV2 Webhook Retry] %s row_id=%d exhausted after %d attempts; manual intervention required.',
                $context, $row_id, $attempts
            ) );
            return;
        }

        $next_attempt = $attempts + 1;
        self::set_attempts( $context, $row_id, $next_attempt );

        $hook = 'hdlv2_webhook_retry_' . $context;
        // wp_schedule_single_event is idempotent on (hook, args, timestamp);
        // if two failing fires inside a request both call maybe_retry, the
        // second is a no-op. Args are passed through to run_scheduled_retry.
        wp_schedule_single_event(
            time() + self::RETRY_DELAY,
            $hook,
            array( $context, $row_id )
        );

        error_log( sprintf(
            '[HDLV2 Webhook Retry] scheduled retry %d/%d for %s row_id=%d in %ds',
            $next_attempt, self::MAX_ATTEMPTS, $context, $row_id, self::RETRY_DELAY
        ) );
    }

    /**
     * Cron callback. Resolves the registered handler for $context and
     * invokes it with $row_id. The handler's response flows back through
     * maybe_retry() — which lives inside the original fire helper — so a
     * failed retry self-schedules the next attempt up to MAX_ATTEMPTS.
     *
     * @param string $context
     * @param int    $row_id
     */
    public static function run_scheduled_retry( $context, $row_id ) {
        $context = (string) $context;
        $row_id  = (int) $row_id;
        if ( ! $row_id || empty( self::$handlers[ $context ] ) ) {
            error_log( sprintf(
                '[HDLV2 Webhook Retry] no handler registered for context=%s (row_id=%d); skipping.',
                $context, $row_id
            ) );
            return;
        }

        try {
            $handler = self::$handlers[ $context ];
            call_user_func( $handler, $row_id );
        } catch ( \Throwable $e ) {
            error_log( sprintf(
                '[HDLV2 Webhook Retry] handler threw for %s row_id=%d: %s',
                $context, $row_id, $e->getMessage()
            ) );
            // No re-enqueue here — the handler itself calls maybe_retry
            // after firing, so a thrown exception means the handler failed
            // BEFORE firing, which is operator-territory and should not
            // silently keep retrying.
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  ATTEMPT COUNTER (transient-backed)
    // ──────────────────────────────────────────────────────────────

    private static function transient_key( $context, $row_id ) {
        return sprintf( 'hdlv2_wh_retry_%s_%d', sanitize_key( $context ), (int) $row_id );
    }

    private static function get_attempts( $context, $row_id ) {
        return (int) get_transient( self::transient_key( $context, $row_id ) );
    }

    private static function set_attempts( $context, $row_id, $count ) {
        set_transient(
            self::transient_key( $context, $row_id ),
            (int) $count,
            self::TRANSIENT_TTL
        );
    }

    private static function clear_attempts( $context, $row_id ) {
        delete_transient( self::transient_key( $context, $row_id ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  RESPONSE INTERPRETATION
    // ──────────────────────────────────────────────────────────────

    /**
     * Decide whether the response from Webhook_Monitor::fire() represents
     * success. WP_Error = transport failure. Non-array = nothing fired
     * (caller short-circuited). Otherwise: HTTP 2xx = success.
     *
     * Note: this treats Make.com's 200 "Accepted" as success even when the
     * Make.com scenario internally errors. There's no signal at this layer
     * to distinguish a Make.com transport ack from a scenario success —
     * downstream callback presence (e.g. flight_plans.pdf_url being set
     * via /flight-plan/pdf-callback) is the only way to verify scenario
     * completion. The Stage 2 retry cron (v0.40.19) uses that pattern;
     * we deliberately mirror only the transport-level retry here so this
     * fix stays contained.
     */
    private static function looks_successful( $response ) {
        if ( ! is_array( $response ) ) return false; // null = skipped, WP_Error = failed
        $code = (int) wp_remote_retrieve_response_code( $response );
        return $code >= 200 && $code < 300;
    }
}
