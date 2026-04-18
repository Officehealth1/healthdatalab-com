<?php
/**
 * V2 Rate-Limit Middleware.
 *
 * Hooks rest_pre_dispatch (BEFORE the route runs) to consume slots and
 * short-circuit with 429 if exceeded; hooks rest_post_dispatch to attach
 * standard X-RateLimit-* headers to every V2 response.
 *
 * Identity precedence per request:
 *   - magic-link token   → token key
 *   - logged-in WP user  → user_id key (for per-practitioner cap on AI-burn)
 *   - else               → IP only (backstop)
 *
 * @package HDL_Longevity_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Rate_Limit_Middleware {

    private static $last_consumed = null;
    private static $last_tier     = null;

    public static function init() {
        add_filter( 'rest_pre_dispatch',  array( __CLASS__, 'check_request' ), 10, 3 );
        add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_headers' ),  20, 3 );
    }

    /**
     * Get the client IP, respecting the proxy chain Vultr/CF expose.
     */
    public static function get_ip() {
        $candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
        foreach ( $candidates as $h ) {
            if ( ! empty( $_SERVER[ $h ] ) ) {
                $ip = trim( explode( ',', $_SERVER[ $h ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
            }
        }
        return '0.0.0.0';
    }

    /**
     * Resolve the magic-link token from the request, if any.
     */
    public static function get_token( $request ) {
        $tok = $request->get_header( 'x_hdlv2_token' );
        if ( ! $tok ) $tok = $request->get_param( 'token' );
        if ( ! $tok && ! empty( $_GET['token'] ) ) $tok = $_GET['token'];
        if ( ! $tok ) return null;
        $tok = (string) $tok;
        return preg_match( '/^[a-f0-9]{64}$/', $tok ) ? $tok : null;
    }

    /**
     * Filter callback for rest_pre_dispatch.
     */
    public static function check_request( $result, $server, $request ) {
        if ( $result !== null ) return $result;

        $route = $request->get_route();
        if ( strpos( $route, '/hdl-v2/v1/' ) !== 0 ) return null;

        try {
            $method = strtoupper( $request->get_method() );
            $tier   = HDLV2_Rate_Limit_Policy::tier_for_request( $method, $route );
            $ip     = self::get_ip();
            $token  = self::get_token( $request );

            // 1. Always-on IP backstop
            $bs    = HDLV2_Rate_Limit_Policy::ip_backstop_config();
            $bk_ip = HDLV2_Rate_Limiter::bucket_key( array( 'ip-backstop', $ip ) );
            $r_ip  = HDLV2_Rate_Limiter::consume( $bk_ip, $bs['per_ip_limit'], $bs['window'] );
            if ( ! $r_ip['allowed'] ) {
                self::log_block( 'ip-backstop', $ip, $route );
                return self::make_429( $r_ip, 'ip-backstop' );
            }
            self::$last_consumed = $r_ip;
            self::$last_tier     = 'ip-backstop';

            // 2. Bypass tier and unmapped routes get only the backstop
            if ( $tier === HDLV2_Rate_Limit_Policy::TIER_BYPASS || $tier === null ) {
                return null;
            }

            $cfg = HDLV2_Rate_Limit_Policy::tier_config( $tier );

            // 3. Public tier — per-IP only
            if ( $tier === HDLV2_Rate_Limit_Policy::TIER_PUBLIC ) {
                $bk = HDLV2_Rate_Limiter::bucket_key( array( $tier, 'ip', $ip ) );
                $r  = HDLV2_Rate_Limiter::consume( $bk, $cfg['per_ip_limit'], $cfg['window'] );
                if ( ! $r['allowed'] ) {
                    self::log_block( $tier, $ip, $route );
                    return self::make_429( $r, $tier );
                }
                self::$last_consumed = $r;
                self::$last_tier     = $tier;
                return null;
            }

            // 4. Token-based tiers (ai-burn / write / read)
            $identity = $token ? array( 'token', $token ) : array( 'ip-anon', $ip );
            $bk       = HDLV2_Rate_Limiter::bucket_key( array_merge( array( $tier ), $identity ) );
            $r        = HDLV2_Rate_Limiter::consume( $bk, $cfg['per_token_limit'], $cfg['window'] );
            if ( ! $r['allowed'] ) {
                self::log_block( $tier, $token ? $token : $ip, $route );
                return self::make_429( $r, $tier );
            }
            self::$last_consumed = $r;
            self::$last_tier     = $tier;

            // 5. AI-burn additionally checks per-practitioner cap
            if ( $tier === HDLV2_Rate_Limit_Policy::TIER_AI_BURN && is_user_logged_in() ) {
                $uid = get_current_user_id();
                if ( $uid && self::user_is_practitioner( $uid ) ) {
                    $bk_p = HDLV2_Rate_Limiter::bucket_key( array( $tier, 'prac', $uid ) );
                    $r_p  = HDLV2_Rate_Limiter::consume( $bk_p, $cfg['per_practitioner_limit'], $cfg['window'] );
                    if ( ! $r_p['allowed'] ) {
                        self::log_block( $tier . '-prac', "user:$uid", $route );
                        return self::make_429( $r_p, $tier . '-practitioner' );
                    }
                    if ( $r_p['remaining'] < $r['remaining'] ) {
                        self::$last_consumed = $r_p;
                    }
                }
            }

            return null;

        } catch ( \Throwable $e ) {
            error_log( '[HDL-RL FAIL-OPEN middleware] ' . $e->getMessage() );
            return null;
        }
    }

    /**
     * Returns true if the user has a practitioner role.
     */
    private static function user_is_practitioner( $user_id ) {
        $u = get_userdata( $user_id );
        if ( ! $u ) return false;
        $roles = (array) $u->roles;
        foreach ( $roles as $r ) {
            if ( strpos( $r, 'practitioner' ) !== false ) return true;
        }
        return user_can( $user_id, 'edit_posts' );
    }

    /**
     * Build a standards-compliant 429 response.
     */
    private static function make_429( $state, $tier_label ) {
        $message = sprintf(
            'Too many requests — try again in %d %s.',
            max( 1, (int) ceil( $state['retry_after'] / 60 ) ),
            ( $state['retry_after'] >= 60 ) ? 'minutes' : 'seconds'
        );
        $resp = new WP_REST_Response(
            array(
                'code'        => 'rate_limit_exceeded',
                'message'     => $message,
                'tier'        => $tier_label,
                'retry_after' => (int) $state['retry_after'],
            ),
            429
        );
        $resp->header( 'Retry-After',           (string) (int) $state['retry_after'] );
        $resp->header( 'X-RateLimit-Limit',     (string) (int) $state['limit'] );
        $resp->header( 'X-RateLimit-Remaining', '0' );
        $resp->header( 'X-RateLimit-Reset',     (string) (int) $state['reset'] );
        $resp->header( 'X-RateLimit-Tier',      $tier_label );
        return $resp;
    }

    /**
     * rest_post_dispatch — attach X-RateLimit-* headers to every V2 response.
     */
    public static function add_headers( $response, $server, $request ) {
        $route = $request->get_route();
        if ( strpos( $route, '/hdl-v2/v1/' ) !== 0 ) return $response;
        if ( ! ( $response instanceof WP_REST_Response ) ) return $response;
        if ( self::$last_consumed === null ) return $response;

        $r = self::$last_consumed;
        $response->header( 'X-RateLimit-Limit',     (string) (int) $r['limit'] );
        $response->header( 'X-RateLimit-Remaining', (string) (int) $r['remaining'] );
        $response->header( 'X-RateLimit-Reset',     (string) (int) $r['reset'] );
        if ( self::$last_tier ) {
            $response->header( 'X-RateLimit-Tier', (string) self::$last_tier );
        }
        return $response;
    }

    /**
     * Light-touch logging — error_log only, no DB.
     */
    private static function log_block( $tier, $identity, $route ) {
        error_log( sprintf( '[HDL-RL BLOCK tier=%s id=%s route=%s]', $tier, $identity, $route ) );
        $today = date( 'Ymd' );
        $key   = 'hdlv2_rl_blocks_' . $today;
        $n     = (int) get_transient( $key );
        set_transient( $key, $n + 1, DAY_IN_SECONDS );
    }
}
