<?php
/**
 * V2 Rate-Limit Status REST endpoint.
 *
 * GET /wp-json/hdl-v2/v1/rate-limit/status
 *   → { ai_burn: {...}, write: {...}, read: {...}, public: {...} }
 *
 * Uses peek() so calling this endpoint does not itself burn a slot
 * against the displayed quota. (It is still counted as a 'read' tier
 * call by the middleware itself, so abusive polling is bounded.)
 *
 * @package HDL_Longevity_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Rate_Limit_Status {

    public function register_hooks() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route( 'hdl-v2/v1', '/rate-limit/status', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_status' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public function rest_status( $request ) {
        $ip    = HDLV2_Rate_Limit_Middleware::get_ip();
        $token = HDLV2_Rate_Limit_Middleware::get_token( $request );

        $tiers = array(
            HDLV2_Rate_Limit_Policy::TIER_AI_BURN,
            HDLV2_Rate_Limit_Policy::TIER_WRITE,
            HDLV2_Rate_Limit_Policy::TIER_READ,
        );

        $out = array();
        foreach ( $tiers as $tier ) {
            $cfg = HDLV2_Rate_Limit_Policy::tier_config( $tier );
            if ( ! $cfg ) continue;

            $identity = $token ? array( 'token', $token ) : array( 'ip-anon', $ip );
            $bk       = HDLV2_Rate_Limiter::bucket_key( array_merge( array( $tier ), $identity ) );
            $state    = HDLV2_Rate_Limiter::peek( $bk, $cfg['per_token_limit'], $cfg['window'] );

            $out[ str_replace( '-', '_', $tier ) ] = array(
                'limit'     => $state['limit'],
                'remaining' => $state['remaining'],
                'reset'     => $state['reset'],
            );
        }

        $pcfg = HDLV2_Rate_Limit_Policy::tier_config( HDLV2_Rate_Limit_Policy::TIER_PUBLIC );
        if ( $pcfg ) {
            $bk    = HDLV2_Rate_Limiter::bucket_key( array( HDLV2_Rate_Limit_Policy::TIER_PUBLIC, 'ip', $ip ) );
            $state = HDLV2_Rate_Limiter::peek( $bk, $pcfg['per_ip_limit'], $pcfg['window'] );
            $out['public'] = array(
                'limit'     => $state['limit'],
                'remaining' => $state['remaining'],
                'reset'     => $state['reset'],
            );
        }

        return rest_ensure_response( $out );
    }
}
