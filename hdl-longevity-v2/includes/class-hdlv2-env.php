<?php
/**
 * Server-identity discriminator + production-side-effect gate.
 *
 * STBY holds real client PII (pre-cutover clone), its WP-Cron fires every
 * minute via /etc/cron.d/hdlv2-wp-cron, and its SMTP path really delivers —
 * on 2026-07-14 the revived check-in cron emailed two real clients from
 * staging. Recurring cron handlers therefore ask gate() before their
 * outbound leg (email / Make.com / Anthropic): the candidate evaluation
 * always runs (so STBY stays testable), the side-effect fires only where
 * it is safe.
 *
 * is_live() requires BOTH signals — WP_ENVIRONMENT_TYPE resolving to
 * 'production' AND the home host being healthdatalab.net — so a fresh
 * LIVE re-clone that loses the staging constant still fails CLOSED on
 * its stby.* hostname. On LIVE this class changes nothing: both signals
 * hold, every gate() returns true.
 *
 * Manual STBY testing of a real send path:
 *   add_filter( 'hdlv2_allow_staging_side_effects', '__return_true' );
 * (or define HDLV2_STAGING_SIDE_EFFECTS true in wp-config). Outbound mail
 * then still passes through the mu-plugin whitelist guard
 * (hdl-stby-mail-guard.php), which is the belt to this brace.
 *
 * @since 0.47.73
 */

defined( 'ABSPATH' ) || exit;

class HDLV2_Env {

    const LIVE_HOSTS = array( 'healthdatalab.net', 'www.healthdatalab.net' );

    /**
     * True only when BOTH identity signals say production.
     *
     * @return bool
     */
    public static function is_live() {
        $env  = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
        $host = strtolower( (string) parse_url( home_url(), PHP_URL_HOST ) );
        return 'production' === $env && in_array( $host, self::LIVE_HOSTS, true );
    }

    /**
     * May production side-effects (email, Make.com, Anthropic) fire here?
     *
     * @return bool
     */
    public static function side_effects_allowed() {
        if ( self::is_live() ) {
            return true;
        }
        if ( defined( 'HDLV2_STAGING_SIDE_EFFECTS' ) && HDLV2_STAGING_SIDE_EFFECTS ) {
            return true;
        }
        return true === apply_filters( 'hdlv2_allow_staging_side_effects', false );
    }

    /**
     * Call at the side-effect site: proceed on true, skip on false.
     * Logs each gated skip so a quiet staging cron is auditable.
     *
     * @param string $context e.g. 'checkin_reminder client:42' — IDs only, never emails.
     * @return bool
     */
    public static function gate( $context ) {
        if ( self::side_effects_allowed() ) {
            return true;
        }
        error_log( '[HDLV2-ENV] side-effect GATED (non-live): ' . $context );
        return false;
    }

    /**
     * Launch flag for SCHEDULED client-facing email — the unattended kind the
     * system decides to send on its own timer to a client who did nothing:
     * check-in reminders, the quarterly client nudge, weekly Flight Plan
     * "ready" mail. OFF (absent) by default so the V2 stack deploys silent
     * and the 19 LIVE clients stay dormant until launch day.
     *
     * NOT a kill switch for the whole product: practitioner nudges to
     * Matthew's own inbox, and transactional client mail that answers an
     * action someone just took (finalise → report ready; check-in → flight
     * plan), are deliberately outside this flag.
     *
     * Option, not a constant — matches the existing hdlv2_ff_* convention
     * (iris_addon, milestone_preview) and makes the launch a single
     * reversible command with no file edit and no deploy:
     *
     *     wp option update hdlv2_ff_client_campaigns 1     # launch
     *     wp option update hdlv2_ff_client_campaigns 0     # stop
     *
     * @since 0.47.75
     * @return bool
     */
    public static function client_campaigns_enabled() {
        return (bool) get_option( 'hdlv2_ff_client_campaigns', false );
    }

    /**
     * Gate for a scheduled client campaign: BOTH the environment gate (live,
     * or the staging manual-test override) AND the launch flag must pass.
     * Composes gate() rather than duplicating it, so a staging box can never
     * send a campaign by flipping the flag alone.
     *
     * @param string $context e.g. 'checkin_reminder client:42' — IDs only, never emails.
     * @return bool
     */
    public static function client_campaign_gate( $context ) {
        if ( ! self::gate( $context ) ) {
            return false;
        }
        if ( ! self::client_campaigns_enabled() ) {
            error_log( '[HDLV2-ENV] client campaign SUPPRESSED (hdlv2_ff_client_campaigns off): ' . $context );
            return false;
        }
        return true;
    }
}
