<?php
/**
 * V2 Idempotency Tracker — Stripe-pattern client-supplied keys.
 *
 * Endpoints opt in by calling lookup() at the top and store() at the
 * bottom (or wrap()). If the client supplies an Idempotency-Key header
 * and we've seen it within the TTL, we return the previous response
 * instead of doing the work again.
 *
 * Storage: WP transients keyed on scope+key, 30-second TTL by default.
 *
 * @package HDL_Longevity_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Idempotency {

    const KEY_PREFIX  = 'hdlv2_idem_';
    const DEFAULT_TTL = 30;

    /**
     * Read the Idempotency-Key header from a WP_REST_Request.
     * Accepts both the header and a body field as a fallback.
     */
    public static function key_from_request( $request ) {
        $k = $request->get_header( 'idempotency_key' );
        if ( ! $k ) $k = $request->get_param( 'idempotency_key' );
        if ( ! $k ) return null;
        $k = trim( (string) $k );
        if ( strlen( $k ) < 8 || strlen( $k ) > 128 ) return null;
        if ( ! preg_match( '/^[A-Za-z0-9_\-]+$/', $k ) ) return null;
        return $k;
    }

    private static function storage_key( $scope, $client_key ) {
        return self::KEY_PREFIX . substr( hash( 'sha256', $scope . '|' . $client_key ), 0, 40 );
    }

    public static function lookup( $scope, $client_key ) {
        if ( ! $client_key ) return null;
        try {
            $stored = get_transient( self::storage_key( $scope, $client_key ) );
            if ( ! is_array( $stored ) || ! array_key_exists( 'response', $stored ) ) return null;
            return $stored;
        } catch ( \Throwable $e ) {
            error_log( '[HDL-IDEM FAIL-OPEN lookup] ' . $e->getMessage() );
            return null;
        }
    }

    public static function store( $scope, $client_key, $response, $ttl = self::DEFAULT_TTL ) {
        if ( ! $client_key ) return;
        try {
            set_transient(
                self::storage_key( $scope, $client_key ),
                array(
                    'response' => $response,
                    'stored'   => time(),
                ),
                max( 1, (int) $ttl )
            );
        } catch ( \Throwable $e ) {
            error_log( '[HDL-IDEM FAIL-OPEN store] ' . $e->getMessage() );
        }
    }

    /**
     * Helper: replay-or-execute. Wraps the common pattern.
     */
    public static function wrap( $request, $scope, callable $worker ) {
        $key = self::key_from_request( $request );
        if ( $key ) {
            $hit = self::lookup( $scope, $key );
            if ( $hit !== null ) {
                $resp = $hit['response'];
                if ( $resp instanceof WP_REST_Response ) {
                    $resp->header( 'X-Idempotent-Replay', 'true' );
                }
                return $resp;
            }
        }
        $resp = $worker();
        if ( $key ) {
            self::store( $scope, $key, $resp );
        }
        return $resp;
    }
}
