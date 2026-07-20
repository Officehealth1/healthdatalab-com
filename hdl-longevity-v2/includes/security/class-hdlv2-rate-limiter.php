<?php
/**
 * V2 Rate Limiter — transient-backed sliding-window counter.
 *
 * Single responsibility: given a bucket key, a limit, and a window in seconds,
 * either consume one slot (and return state) or peek at current state.
 *
 * Fail-open by design: if the transient store misbehaves, log and allow.
 *
 * @package HDL_Longevity_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Rate_Limiter {

    const KEY_PREFIX = 'hdlv2_rl_';

    /**
     * Build a stable bucket key from arbitrary parts.
     */
    public static function bucket_key( array $parts ) {
        return self::KEY_PREFIX . md5( implode( '|', array_map( 'strval', $parts ) ) );
    }

    /**
     * Consume one slot in the bucket.
     *
     * @return array {
     *   bool  $allowed
     *   int   $limit
     *   int   $remaining
     *   int   $reset       Unix timestamp when window resets
     *   int   $retry_after Seconds until next allowed call (0 if allowed)
     * }
     */
    public static function consume( $bucket_key, $limit, $window_seconds ) {
        try {
            $now   = time();
            $state = get_transient( $bucket_key );

            if ( ! is_array( $state ) || ! isset( $state['count'], $state['reset'] ) ) {
                $state = array( 'count' => 0, 'reset' => $now + $window_seconds );
            }

            if ( $state['reset'] <= $now ) {
                $state = array( 'count' => 0, 'reset' => $now + $window_seconds );
            }

            $state['count']++;
            $allowed = ( $state['count'] <= $limit );

            $ttl = max( 1, $state['reset'] - $now );
            set_transient( $bucket_key, $state, $ttl );

            return array(
                'allowed'     => $allowed,
                'limit'       => (int) $limit,
                'remaining'   => max( 0, $limit - $state['count'] ),
                'reset'       => (int) $state['reset'],
                'retry_after' => $allowed ? 0 : $ttl,
            );
        } catch ( \Throwable $e ) {
            error_log( '[HDL-RL FAIL-OPEN consume] ' . $e->getMessage() );
            return array(
                'allowed'     => true,
                'limit'       => (int) $limit,
                'remaining'   => (int) $limit,
                'reset'       => time() + (int) $window_seconds,
                'retry_after' => 0,
            );
        }
    }

    /**
     * Peek without consuming.
     */
    public static function peek( $bucket_key, $limit, $window_seconds ) {
        try {
            $now   = time();
            $state = get_transient( $bucket_key );

            if ( ! is_array( $state ) || ! isset( $state['count'], $state['reset'] ) || $state['reset'] <= $now ) {
                return array(
                    'allowed'     => true,
                    'limit'       => (int) $limit,
                    'remaining'   => (int) $limit,
                    'reset'       => $now + (int) $window_seconds,
                    'retry_after' => 0,
                );
            }

            $allowed = ( $state['count'] < $limit );
            return array(
                'allowed'     => $allowed,
                'limit'       => (int) $limit,
                'remaining'   => max( 0, $limit - $state['count'] ),
                'reset'       => (int) $state['reset'],
                'retry_after' => $allowed ? 0 : max( 1, $state['reset'] - $now ),
            );
        } catch ( \Throwable $e ) {
            error_log( '[HDL-RL FAIL-OPEN peek] ' . $e->getMessage() );
            return array(
                'allowed'     => true,
                'limit'       => (int) $limit,
                'remaining'   => (int) $limit,
                'reset'       => time() + (int) $window_seconds,
                'retry_after' => 0,
            );
        }
    }

    /**
     * Test helper — wipes a bucket. Used only from verification scripts.
     */
    public static function reset( $bucket_key ) {
        delete_transient( $bucket_key );
    }
}
