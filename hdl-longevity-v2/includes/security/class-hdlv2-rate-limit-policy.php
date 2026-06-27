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
            // ── AI-burn (Claude/Deepgram) ───────────────────────────────
            array( 'POST', '#^/hdl-v2/v1/audio/transcribe-async$#',             self::TIER_AI_BURN ),
            array( 'POST', '#^/hdl-v2/v1/audio/extract$#',                      self::TIER_AI_BURN ),
            array( 'POST', '#^/hdl-v2/v1/form/generate-report$#',               self::TIER_AI_BURN ),
            array( 'POST', '#^/hdl-v2/v1/checkin/confirm$#',                    self::TIER_AI_BURN ),
            array( 'POST', '#^/hdl-v2/v1/consultation/finalise$#',              self::TIER_AI_BURN ),
            // v0.26.2 — fix #4 (/ultrareview): three Claude-firing endpoints
            // were falling through to TIER_WRITE (60/hour). A retry loop
            // could fire 60 of these per hour ≈ $4–6/hour Claude burn before
            // the IP backstop trips. Now capped at the AI-burn 8/hour per
            // token + 30/hour per practitioner.
            array( 'POST', '#^/hdl-v2/v1/consultation/organise$#',              self::TIER_AI_BURN ),
            // v0.46.62 — pre-send milestone generation is a Claude call.
            array( 'POST', '#^/hdl-v2/v1/consultation/milestones-generate$#',   self::TIER_AI_BURN ),
            array( 'POST', '#^/hdl-v2/v1/consultation/refresh-brief$#',         self::TIER_AI_BURN ),
            array( 'POST', '#^/hdl-v2/v1/consultation/save-and-regenerate$#',   self::TIER_AI_BURN ),
            // v0.28.0 — Consultation Addenda flow. /save-and-update-plan is
            // the new post-Final action button (same backend as
            // save-and-regenerate but distinct idempotency scope so the
            // dedup buckets don't clash). AI-burn tier because it re-fires
            // generate_final_report + generate_milestones (~$0.05/run).
            array( 'POST', '#^/hdl-v2/v1/consultation/save-and-update-plan$#',  self::TIER_AI_BURN ),
            array( 'POST', '#^/hdl-v2/v1/flight-plan/[0-9]+/generate$#',        self::TIER_AI_BURN ),
            array( 'GET',  '#^/hdl-v2/v1/flight-notes/pdf$#',                   self::TIER_AI_BURN ),

            // W9 (v0.41.31) — automation-tier self-reported consultation
            // submit. Runs ONE Claude Haiku call + fires Make.com Route 1
            // webhook + marks the user's automation token completed.
            // TIER_AI_BURN so a malicious double-tap can't cost burn.
            array( 'POST', '#^/hdl-v2/v1/auto-consultation/submit$#',           self::TIER_AI_BURN ),

            // v0.35.0 (Phase O) — practitioner Confirm a widget lead. Calls
            // complete_signup() which fires Make.com Stage 1 PDF webhook and
            // sends the magic-link email; both have non-zero cost. AI_BURN
            // tier (10/hour) prevents accidental floods if a practitioner
            // bulk-confirms a spammed inbox.
            array( 'POST', '#^/hdl-v2/v1/widget/leads/confirm$#',               self::TIER_AI_BURN ),

            // ── Bypass (Make.com bearer-authed callbacks + internal loopback) ───
            array( 'POST', '#^/hdl-v2/v1/form/stage2-callback$#',               self::TIER_BYPASS ),
            array( 'POST', '#^/hdl-v2/v1/flight-plan/pdf-callback$#',           self::TIER_BYPASS ),
            // v0.46.57 (P4) — D-2 final/draft/WHY PDF callback from Make.com.
            // hash_equals bearer-authed like the flight-plan callback above;
            // was unmapped → null tier left only the IP backstop between a
            // legitimate Make POST burst and a 429 on a stored-PDF write.
            array( 'POST', '#^/hdl-v2/v1/reports/pdf-callback$#',               self::TIER_BYPASS ),
            // v0.30.0 — internal job-queue worker kick. Fired by
            // HDLV2_Job_Queue::trigger_worker_async() over loopback HTTP so
            // a freshly-enqueued audio-transcription job runs in seconds
            // instead of waiting for the next cron tick. HMAC-gated via
            // wp_salt('auth') so only an in-process WP request can reach it.
            // Was previously unmapped → tier_for_request returned null, leaving
            // the middleware free to throttle; bypass is the correct tier.
            array( 'POST', '#^/hdl-v2/v1/internal/worker-tick$#',               self::TIER_BYPASS ),

            // ── Public (open lead capture + resend + reject) ────────────
            array( 'POST', '#^/hdl-v2/v1/widget/lead$#',                        self::TIER_PUBLIC ),
            array( 'POST', '#^/hdl-v2/v1/widget/resend$#',                      self::TIER_PUBLIC ),
            array( 'POST', '#^/hdl-v2/v1/widget/reject$#',                      self::TIER_PUBLIC ),
            // v0.35.0 (Phase O) — public widget pulls fresh display config
            // (cta_link, theme_color, etc.) on mount so practitioners can
            // update their booking link in Widget Settings without re-pasting
            // embed code. Read-only safe-subset; no PII beyond display fields
            // already shown publicly.
            array( 'GET',  '#^/hdl-v2/v1/widget/public-config$#',               self::TIER_PUBLIC ),

            // ── Write (mutating but cheap) ──────────────────────────────
            array( 'POST', '#^/hdl-v2/v1/form/(save|complete-stage|create|release-why)$#', self::TIER_WRITE ),
            array( 'POST', '#^/hdl-v2/v1/widget/invite$#',                      self::TIER_WRITE ),
            array( 'POST', '#^/hdl-v2/v1/flight-plan/(tick|tick-all)$#',        self::TIER_WRITE ),
            array( 'POST', '#^/hdl-v2/v1/flight-plan/[0-9]+/(settings|priorities)$#', self::TIER_WRITE ),
            array( 'POST', '#^/hdl-v2/v1/consultation/(save-notes|add-recommendation|remove-recommendation|edit-field|addendum)$#', self::TIER_WRITE ),
            array( 'POST', '#^/hdl-v2/v1/timeline/add-note$#',                  self::TIER_WRITE ),
            // v0.46.58 — practitioner flight-plan editor. Cheap DB write; the
            // PDF re-render it queues is governed by the render service's own
            // cooldown + daily cap.
            // v0.46.62 — pre-send staged milestone edit; cheap DB write, no AI.
            array( 'POST', '#^/hdl-v2/v1/consultation/milestones-stage-edit$#', self::TIER_WRITE ),
            array( 'POST', '#^/hdl-v2/v1/flight-plan/[0-9]+/edit$#',            self::TIER_WRITE ),
            array( 'POST', '#^/hdl-v2/v1/checkin/submit$#',                     self::TIER_WRITE ),
            array( 'POST', '#^/hdl-v2/v1/audio/client-error$#',                 self::TIER_WRITE ),
            // v0.35.0 (Phase O) — practitioner rejects a widget lead. Silent
            // (no email, no Make.com call), so TIER_WRITE rather than AI_BURN.
            array( 'POST', '#^/hdl-v2/v1/widget/leads/reject$#',                self::TIER_WRITE ),

            // ── Read (lightweight) ──────────────────────────────────────
            array( 'GET',  '#^/hdl-v2/v1/form/load$#',                          self::TIER_READ ),
            array( 'GET',  '#^/hdl-v2/v1/widget/(config|invites|verify-invite)$#', self::TIER_READ ),
            // v0.35.0 (Phase O) — practitioner inbox feed: pending widget leads.
            array( 'GET',  '#^/hdl-v2/v1/widget/leads/pending$#',               self::TIER_READ ),
            array( 'GET',  '#^/hdl-v2/v1/flight-plan/[0-9]+/(current|history|adherence|preview)$#', self::TIER_READ ),
            array( 'GET',  '#^/hdl-v2/v1/timeline/[0-9]+(/client|/export)?$#',  self::TIER_READ ),
            array( 'GET',  '#^/hdl-v2/v1/dashboard/clients$#',                  self::TIER_READ ),
            array( 'GET',  '#^/hdl-v2/v1/dashboard/client/[0-9]+/effort-outcomes$#', self::TIER_READ ),
            // v0.25.2 — practitioner consultations index (/consultation/ landing).
            array( 'GET',  '#^/hdl-v2/v1/consultations/list$#',                  self::TIER_READ ),
            array( 'GET',  '#^/hdl-v2/v1/health$#',                             self::TIER_READ ),
            array( 'GET',  '#^/hdl-v2/v1/checkin/(load|history)$#',             self::TIER_READ ),

            // v0.19.2 — job-status polling for async transcription. Previously
            // unmapped → only the IP backstop applied (500/hr/IP), which was
            // blowing up when clients polled every 3s for 30-90s transcripts
            // across multiple audio uploads in a session. TIER_READ is
            // per-token (200/hr) so clients share a bucket with their own
            // form/load reads, and don't collide with other callers on the
            // same NAT'd IP.
            array( 'GET',  '#^/hdl-v2/v1/jobs/[0-9]+/status$#',                 self::TIER_READ ),

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
