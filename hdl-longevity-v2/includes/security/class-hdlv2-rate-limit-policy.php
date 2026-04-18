<?php
/**
 * V2 Rate-Limit Policy — single source of truth for tier mapping.
 *
 * Adding a new V2 endpoint? Add one line in route_patterns().
 *
 * @package HDL_Longevity_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Rate_Limit_Policy {

    const TIER_AI_BURN = 'ai-burn';
    const TIER_WRITE   = 'write';
    const TIER_READ    = 'read';
    const TIER_PUBLIC  = 'public';
    const TIER_BYPASS  = 'bypass';

    /**
     * Tier configuration. All windows are 1 hour (3600s).
     *
     * Filterable via apply_filters('hdlv2_rate_limit_tier_config', $config, $tier).
     */
    private static function tier_config_raw() {
        return array(
            self::TIER_AI_BURN => array(
                'per_token_limit'        => 8,
                'per_practitioner_limit' => 30,
                'window'                 => 3600,
            ),
            self::TIER_WRITE => array(
                'per_token_limit' => 60,
                'window'          => 3600,
            ),
            self::TIER_READ => array(
                'per_token_limit' => 200,
                'window'          => 3600,
            ),
            self::TIER_PUBLIC => array(
                'per_ip_limit' => 5,
                'window'       => 3600,
            ),
            self::TIER_BYPASS => null,
        );
    }

    /**
     * IP backstop applied to every V2 request regardless of tier.
     */
    public static function ip_backstop_config() {
        return apply_filters( 'hdlv2_rate_limit_ip_backstop', array(
            'per_ip_limit' => 500,
            'window'       => 3600,
        ) );
    }

    public static function tier_config( $tier ) {
        $all = self::tier_config_raw();
        $cfg = isset( $all[ $tier ] ) ? $all[ $tier ] : null;
        return apply_filters( 'hdlv2_rate_limit_tier_config', $cfg, $tier );
    }

    /**
     * Route patterns. Order matters — first match wins.
     * Each entry: array( method, route_regex, tier ).
     */
    private static function route_patterns() {
        return array(
            // ── AI-burn (Claude/Whisper) ────────────────────────────────
            array( 'POST', '#^/hdl-v2/v1/audio/transcribe$#',                   self::TIER_AI_BURN ),
            array( 'POST', '#^/hdl-v2/v1/audio/extract$#',                      self::TIER_AI_BURN ),
            array( 'POST', '#^/hdl-v2/v1/form/generate-report$#',               self::TIER_AI_BURN ),
            array( 'POST', '#^/hdl-v2/v1/checkin/confirm$#',                    self::TIER_AI_BURN ),
            array( 'POST', '#^/hdl-v2/v1/consultation/finalise$#',              self::TIER_AI_BURN ),
            array( 'POST', '#^/hdl-v2/v1/flight-plan/[0-9]+/generate$#',        self::TIER_AI_BURN ),

            // ── Bypass (Make.com bearer-authed callbacks) ───────────────
            array( 'POST', '#^/hdl-v2/v1/form/stage2-callback$#',               self::TIER_BYPASS ),
            array( 'POST', '#^/hdl-v2/v1/flight-plan/pdf-callback$#',           self::TIER_BYPASS ),

            // ── Public (open lead capture) ──────────────────────────────
            array( 'POST', '#^/hdl-v2/v1/widget/lead$#',                        self::TIER_PUBLIC ),

            // ── Write (mutating but cheap) ──────────────────────────────
            array( 'POST', '#^/hdl-v2/v1/form/(save|complete-stage|create|release-why)$#', self::TIER_WRITE ),
            array( 'POST', '#^/hdl-v2/v1/widget/invite$#',                      self::TIER_WRITE ),
            array( 'POST', '#^/hdl-v2/v1/flight-plan/(tick|tick-all)$#',        self::TIER_WRITE ),
            array( 'POST', '#^/hdl-v2/v1/flight-plan/[0-9]+/(settings|priorities)$#', self::TIER_WRITE ),
            array( 'POST', '#^/hdl-v2/v1/consultation/(save-notes|add-recommendation|remove-recommendation|edit-field)$#', self::TIER_WRITE ),
            array( 'POST', '#^/hdl-v2/v1/timeline/add-note$#',                  self::TIER_WRITE ),
            array( 'POST', '#^/hdl-v2/v1/checkin/submit$#',                     self::TIER_WRITE ),

            // ── Read (lightweight) ──────────────────────────────────────
            array( 'GET',  '#^/hdl-v2/v1/form/load$#',                          self::TIER_READ ),
            array( 'GET',  '#^/hdl-v2/v1/widget/(config|invites|verify-invite)$#', self::TIER_READ ),
            array( 'GET',  '#^/hdl-v2/v1/flight-plan/[0-9]+/(current|history|adherence|preview)$#', self::TIER_READ ),
            array( 'GET',  '#^/hdl-v2/v1/timeline/[0-9]+(/client|/export)?$#',  self::TIER_READ ),
            array( 'GET',  '#^/hdl-v2/v1/dashboard/clients$#',                  self::TIER_READ ),
            array( 'GET',  '#^/hdl-v2/v1/checkin/(load|history)$#',             self::TIER_READ ),

            // ── Self-status (always allowed but counted as read) ────────
            array( 'GET',  '#^/hdl-v2/v1/rate-limit/status$#',                  self::TIER_READ ),
        );
    }

    /**
     * Resolve the tier for a given (method, route) pair.
     * Returns one of the TIER_* constants, or null if unmapped.
     */
    public static function tier_for_request( $method, $route ) {
        $method = strtoupper( $method );
        foreach ( self::route_patterns() as $entry ) {
            list( $m, $rx, $tier ) = $entry;
            if ( $m === $method && preg_match( $rx, $route ) ) {
                return $tier;
            }
        }
        return null;
    }
}
