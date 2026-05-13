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
     * Wire the admin notice so failures surface to the practitioner-team
     * owner without requiring log access.
     */
    public static function register_hooks() {
        add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
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
