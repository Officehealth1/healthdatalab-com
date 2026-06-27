<?php
/**
 * Webhook Monitor — observability for Make.com outbound calls.
 *
 * V2 fires five Make.com webhooks (Stage 1 PDF, Stage 2 WHY, Draft Report,
 * Final Report, Flight Plan PDF). Before this class, every fire was a
 * raw `wp_remote_post` with no logging or failure tracking — Make.com
 * could be returning 500s for hours and nobody would know until a client
 * complained their PDF never arrived.
 *
 * This wrapper does three things:
 *   1. Logs every outbound call to error_log with structured prefix so
 *      ops can grep `[HDLV2 Webhook]` to see traffic.
 *   2. On non-2xx response, increments a rolling failure counter stored
 *      in `hdlv2_webhook_failures` option (last 24 hours).
 *   3. When recent_failures > 0 within the last hour, an admin notice
 *      surfaces on every wp-admin page so the practitioner-team owner
 *      sees the signal without having to go hunting in logs.
 *
 * @package HDL_Longevity_V2
 * @since 0.22.18
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Webhook_Monitor {

    const OPTION_KEY    = 'hdlv2_webhook_failures';
    const RETENTION_SEC = 86400;
    const NOTICE_WINDOW = 3600;

    // v0.40.19 — Rolling event log capturing EVERY webhook fire (success or
    // failure), including the response body excerpt. The existing failure
    // log only catches transport-level or non-2xx failures, but Make.com
    // always returns 200 "Accepted" even when the scenario internally
    // errors — so failures were invisible without WP_DEBUG_LOG.
    // This log preserves the response body so ops can see what Make.com
    // actually said. Capped at 100 entries to bound option size.
    const LOG_OPTION_KEY  = 'hdlv2_webhook_log';
    const LOG_MAX_ENTRIES = 100;

    // v0.46.19 (W3-3 / BACK-05) — "stuck PDF" watchdog. Make.com returns 200
    // "Accepted" even when its scenario silently drops the job, so a Final
    // Report / Flight Plan can sit with pdf_url NULL forever and nobody knows.
    // fire() catches synchronous failures; this catches the never-arriving
    // callback. STUCK_OPTION_KEY tracks already-alerted rows so the operator
    // isn't re-pinged every scan (re-alerts at most every STUCK_REALERT_SEC).
    const STUCK_OPTION_KEY  = 'hdlv2_stuck_pdf_alerted';
    const STUCK_THROTTLE_SEC = 900;   // run the scan at most every 15 min
    const STUCK_MIN_AGE_MIN  = 15;    // pdf_url still empty this long after gen = stuck
    const STUCK_MAX_AGE_HOURS = 24;   // ignore ancient backlog (don't alert forever)
    const STUCK_REALERT_SEC  = 21600; // re-surface a still-stuck row at most every 6h

    /**
     * Fire a Make.com webhook with structured logging + failure tracking.
     *
     * Behaviour matches `wp_remote_post` for callers — same URL+args
     * shape, same return value (WP_Error or response array). The only
     * additions are observability side effects.
     *
     * @param string $url     Webhook URL.
     * @param array  $args    `wp_remote_post` args (body / timeout / etc).
     * @param string $context Short label for the call (e.g. 'stage1_pdf').
     * @return array|WP_Error
     */
    public static function fire( $url, $args, $context = 'unknown' ) {
        $started = microtime( true );
        $resp    = wp_remote_post( $url, $args );
        $elapsed = round( ( microtime( true ) - $started ) * 1000 );

        if ( is_wp_error( $resp ) ) {
            $msg = $resp->get_error_message();
            error_log( sprintf(
                '[HDLV2 Webhook][%s] FAIL after %dms — transport error: %s',
                $context, $elapsed, $msg
            ) );
            self::record_failure( $context, 0, $msg, $elapsed );
            self::record_event( $context, 0, $msg, $elapsed, 'transport_error' );
            return $resp;
        }

        $code = (int) wp_remote_retrieve_response_code( $resp );
        // v0.40.19 — capture the response body on success too, not just on
        // failure. Make.com returns 200 "Accepted" even when its scenario
        // internally errors; logging the body lets ops see at-a-glance
        // whether the downstream actually processed the call.
        $body_excerpt = substr( (string) wp_remote_retrieve_body( $resp ), 0, 200 );

        if ( $code >= 200 && $code < 300 ) {
            error_log( sprintf(
                '[HDLV2 Webhook][%s] OK %d in %dms — body: %s',
                $context, $code, $elapsed, $body_excerpt
            ) );
            self::record_event( $context, $code, $body_excerpt, $elapsed, 'success' );
            return $resp;
        }

        error_log( sprintf(
            '[HDLV2 Webhook][%s] FAIL %d after %dms — body: %s',
            $context, $code, $elapsed, $body_excerpt
        ) );
        self::record_failure( $context, $code, $body_excerpt, $elapsed );
        self::record_event( $context, $code, $body_excerpt, $elapsed, 'http_error' );
        return $resp;
    }

    /**
     * Append every webhook fire (success or failure) to the rolling log.
     *
     * Distinct from record_failure(): that's the admin-notice trigger and
     * only fires on non-2xx. This catches success too, so ops can answer
     * "did Make.com just say 'Accepted' to my Stage 2 webhook?" by reading
     * one option instead of digging through debug.log.
     *
     * @since 0.40.19
     */
    private static function record_event( $context, $http_code, $body_excerpt, $elapsed_ms, $outcome ) {
        $events = get_option( self::LOG_OPTION_KEY, array() );
        if ( ! is_array( $events ) ) $events = array();

        $events[] = array(
            'context'    => (string) $context,
            'http_code'  => (int) $http_code,
            'body'       => substr( (string) $body_excerpt, 0, 200 ),
            'elapsed_ms' => (int) $elapsed_ms,
            'outcome'    => (string) $outcome, // success | http_error | transport_error
            'time'       => time(),
        );

        if ( count( $events ) > self::LOG_MAX_ENTRIES ) {
            $events = array_slice( $events, -self::LOG_MAX_ENTRIES );
        }

        update_option( self::LOG_OPTION_KEY, $events, false );
    }

    /**
     * Public reader for the last N webhook events. Useful for wp-cli debug
     * and for the admin notice's "details" link.
     *
     * @param int $limit Default 20, max LOG_MAX_ENTRIES.
     * @since 0.40.19
     */
    public static function recent_events( $limit = 20 ) {
        $events = get_option( self::LOG_OPTION_KEY, array() );
        if ( ! is_array( $events ) ) return array();
        $limit = max( 1, min( self::LOG_MAX_ENTRIES, (int) $limit ) );
        return array_slice( $events, -$limit );
    }

    /**
     * Append a failure event to the rolling 24-hour log stored in options.
     */
    private static function record_failure( $context, $http_code, $message, $elapsed_ms ) {
        $events = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $events ) ) $events = array();

        $events[] = array(
            'context'    => (string) $context,
            'http_code'  => (int) $http_code,
            'message'    => substr( (string) $message, 0, 500 ),
            'elapsed_ms' => (int) $elapsed_ms,
            'time'       => time(),
        );

        $cutoff = time() - self::RETENTION_SEC;
        $events = array_values( array_filter( $events, function ( $e ) use ( $cutoff ) {
            return is_array( $e ) && ! empty( $e['time'] ) && $e['time'] >= $cutoff;
        } ) );

        // Hard cap so the option doesn't grow without bound under sustained
        // failure. Keep last 200 events; older ones drop.
        if ( count( $events ) > 200 ) {
            $events = array_slice( $events, -200 );
        }

        update_option( self::OPTION_KEY, $events, false );
    }

    public static function recent_failures( $within_seconds = self::NOTICE_WINDOW ) {
        $events = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $events ) ) return array();
        $cutoff = time() - $within_seconds;
        return array_values( array_filter( $events, function ( $e ) use ( $cutoff ) {
            return is_array( $e ) && ! empty( $e['time'] ) && $e['time'] >= $cutoff;
        } ) );
    }

    public static function clear_failures() {
        delete_option( self::OPTION_KEY );
    }

    /**
     * Throttled entry point for the stuck-PDF watchdog. Hooked onto the
     * existing per-minute job-queue cron (no new schedule). A transient lock
     * limits the actual scan to once per STUCK_THROTTLE_SEC.
     *
     * @since 0.46.19
     */
    public static function maybe_scan_stuck_pdfs() {
        if ( get_transient( 'hdlv2_stuck_pdf_scan_lock' ) ) {
            return;
        }
        set_transient( 'hdlv2_stuck_pdf_scan_lock', 1, self::STUCK_THROTTLE_SEC );
        self::scan_stuck_pdfs();
    }

    /**
     * Find Final Report / Flight Plan rows whose PDF callback never arrived
     * (pdf_url still empty long after generation) and raise a deduped operator
     * alert via the same admin-notice channel as webhook failures.
     *
     * Guards (no false alerts):
     *  - Only checks a surface when its Make pipeline is configured — so on a
     *    box with no Make.com (e.g. STBY) it is a pure no-op; an empty pdf_url
     *    there is expected, not a failure. (Overridable via the
     *    hdlv2_stuck_pdf_check_{reports,flight} filters — used by tests.)
     *  - Lower age bound (STUCK_MIN_AGE_MIN) so a PDF that's merely still
     *    rendering isn't flagged; upper bound (STUCK_MAX_AGE_HOURS) so old
     *    backlog isn't alerted forever.
     *  - Per-row dedupe (STUCK_OPTION_KEY) re-surfaces a still-stuck row at
     *    most every STUCK_REALERT_SEC.
     *  - Read-only: never mutates the report/plan row (no schema change, no
     *    risk of corrupting state). Timestamps compared with NOW() because the
     *    columns are written server-local (CURRENT_TIMESTAMP / current_time).
     *
     * @return array { scanned:bool, stuck:int, new_alerts:int }
     * @since 0.46.19
     */
    /**
     * Has this surface ever produced a PDF here? Used to self-calibrate the
     * stuck-PDF watchdog so it only fires where the Make→PDFMonkey pipeline is
     * genuinely functional. $table is built from $wpdb->prefix + a literal, so
     * it is not user input.
     */
    private static function pipeline_has_produced( $table ) {
        global $wpdb;
        return 1 === (int) $wpdb->get_var( "SELECT EXISTS( SELECT 1 FROM `{$table}` WHERE pdf_url IS NOT NULL AND pdf_url <> '' )" );
    }

    public static function scan_stuck_pdfs() {
        global $wpdb;

        // A surface is watched only when its Make pipeline is configured AND it
        // has actually produced at least one PDF here. The second clause
        // self-calibrates: on a box where the webhook URL is defined but Make
        // is non-functional (e.g. STBY), no row ever gets a pdf_url, so the
        // watchdog stays silent instead of flagging every plan as "stuck". On
        // LIVE it activates automatically once the first real PDF lands. Zero
        // config, no false positives. (Filters let tests force a surface on.)
        $reports_pipeline = defined( 'HDLV2_MAKE_FINAL_REPORT' ) && (string) HDLV2_MAKE_FINAL_REPORT !== '';
        $flight_pipeline  = defined( 'HDLV2_MAKE_FLIGHT_PDF' )  && (string) HDLV2_MAKE_FLIGHT_PDF  !== '';
        $check_reports = apply_filters( 'hdlv2_stuck_pdf_check_reports', $reports_pipeline && self::pipeline_has_produced( $wpdb->prefix . 'hdlv2_reports' ) );
        $check_flight  = apply_filters( 'hdlv2_stuck_pdf_check_flight',  $flight_pipeline  && self::pipeline_has_produced( $wpdb->prefix . 'hdlv2_flight_plans' ) );
        if ( ! $check_reports && ! $check_flight ) {
            return array( 'scanned' => false, 'stuck' => 0, 'new_alerts' => 0 );
        }

        $now     = time();
        $alerted = get_option( self::STUCK_OPTION_KEY, array() );
        if ( ! is_array( $alerted ) ) {
            $alerted = array();
        }
        // Prune entries older than a day so the option stays small.
        foreach ( $alerted as $k => $ts ) {
            if ( ! is_int( $ts ) || ( $now - $ts ) > DAY_IN_SECONDS ) {
                unset( $alerted[ $k ] );
            }
        }

        $min   = (int) self::STUCK_MIN_AGE_MIN;
        $max   = (int) self::STUCK_MAX_AGE_HOURS;
        $stuck = array();

        if ( $check_reports ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, client_user_id FROM {$wpdb->prefix}hdlv2_reports
                 WHERE report_type = 'final' AND status = 'ready'
                   AND ( pdf_url IS NULL OR pdf_url = '' )
                   AND updated_at < ( NOW() - INTERVAL %d MINUTE )
                   AND updated_at > ( NOW() - INTERVAL %d HOUR )",
                $min, $max
            ) );
            foreach ( (array) $rows as $r ) {
                $stuck[] = array(
                    'key' => 'report:' . (int) $r->id,
                    'msg' => sprintf( 'Final Report #%d (client %d): PDF never arrived from Make.com/PDFMonkey (>%d min). The web report is fine — only the downloadable PDF is missing; re-fire the Make scenario or re-finalise.', (int) $r->id, (int) $r->client_user_id, $min ),
                );
            }
        }

        if ( $check_flight ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, client_id FROM {$wpdb->prefix}hdlv2_flight_plans
                 WHERE status IN ( 'generated', 'delivered', 'active' ) AND deleted_at IS NULL
                   AND ( pdf_url IS NULL OR pdf_url = '' )
                   AND created_at < ( NOW() - INTERVAL %d MINUTE )
                   AND created_at > ( NOW() - INTERVAL %d HOUR )",
                $min, $max
            ) );
            foreach ( (array) $rows as $r ) {
                $stuck[] = array(
                    'key' => 'flight:' . (int) $r->id,
                    'msg' => sprintf( 'Flight Plan #%d (client %d): PDF never arrived (>%d min). The plan is usable on the web; only the PDF is missing.', (int) $r->id, (int) $r->client_id, $min ),
                );
            }
        }

        $new = 0;
        foreach ( $stuck as $s ) {
            $last = isset( $alerted[ $s['key'] ] ) ? (int) $alerted[ $s['key'] ] : 0;
            if ( $last && ( $now - $last ) < self::STUCK_REALERT_SEC ) {
                continue; // already alerted recently — don't re-ping
            }
            self::record_failure( 'stuck_pdf', 0, $s['msg'], 0 );
            error_log( '[HDLV2 Webhook][stuck_pdf] ' . $s['msg'] );
            $alerted[ $s['key'] ] = $now;
            $new++;
        }
        update_option( self::STUCK_OPTION_KEY, $alerted, false );

        return array( 'scanned' => true, 'stuck' => count( $stuck ), 'new_alerts' => $new );
    }

    /**
     * Wire the admin notice so failures surface to the practitioner-team
     * owner without requiring log access.
     */
    public static function register_hooks() {
        add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
        // v0.46.19 — ride the existing per-minute job-queue cron (no new
        // schedule); the scan self-throttles to once per STUCK_THROTTLE_SEC.
        add_action( 'hdlv2_job_queue_worker', array( __CLASS__, 'maybe_scan_stuck_pdfs' ) );
    }

    public static function render_admin_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $recent = self::recent_failures();
        if ( empty( $recent ) ) return;

        $by_context = array();
        foreach ( $recent as $e ) {
            $ctx = $e['context'] ?? 'unknown';
            $by_context[ $ctx ] = ( $by_context[ $ctx ] ?? 0 ) + 1;
        }

        $lines = array();
        foreach ( $by_context as $ctx => $count ) {
            $lines[] = sprintf( '%s × %d', esc_html( $ctx ), (int) $count );
        }

        printf(
            '<div class="notice notice-error is-dismissible"><p><strong>HDL V2: Make.com webhook failures in last hour:</strong> %s. Check error_log for details.</p></div>',
            esc_html( implode( ' · ', $lines ) )
        );
    }
}
