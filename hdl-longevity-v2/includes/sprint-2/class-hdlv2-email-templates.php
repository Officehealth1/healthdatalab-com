<?php
/**
 * Branded HTML Email Templates for V2 staged assessment.
 *
 * Three stage-specific templates using HDL brand tokens:
 * Poppins headings, Inter body, #3d8da0 teal, white cards.
 *
 * @package HDL_Longevity_V2
 * @since 0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Email_Templates {

    const TEAL      = '#3d8da0';
    const DARK_TEAL = '#004F59';
    const GREEN     = '#10b981';
    const CORAL     = '#f59e0b';
    const DARK      = '#111111';
    const DARK_TEXT = '#1a1a1a';
    const MUTED     = '#888888';
    const BORDER    = '#e4e6ea';
    const SOFT_FILL = '#fafbfc';
    const BG        = '#f4f5f7';
    const CARD      = '#ffffff';
    const HDL_LOGO  = 'https://healthdatalab.net/wp-content/uploads/2023/09/HDL-Logo-2309-4-d-sss.png';
    const TERMS_URL = 'https://healthdatalab.com/terms';
    const PRIVACY_URL = 'https://healthdatalab.com/privacy';

    // ──────────────────────────────────────────────────────────────
    //  PUBLIC HELPERS — name handling + practitioner resolver
    //
    //  v0.36.23 — both helpers are public static so Make.com webhook
    //  payload builders (sprint-2/class-hdlv2-staged-form.php,
    //  sprint-1/class-hdlv2-widget-config.php) can pre-compute
    //  client_first_name and practitioner_logo_url with the SAME
    //  rules WP uses, avoiding "Dear matthewdhaemer+test080526@…"
    //  style bleed-through in Make.com-rendered greetings.
    // ──────────────────────────────────────────────────────────────

    /**
     * Derive a safe first-name greeting from a possibly-dirty client name.
     *
     * Returns 'there' when:
     *   • the name is empty or whitespace-only
     *   • the name contains '@' (someone typed their email into the name field)
     *   • the name matches the email exactly (case-insensitive)
     *
     * Strips trailing digit-only tokens (e.g. "Matthew D'haemer 01" → "Matthew")
     * to absorb test artefacts before returning the first whitespace-separated
     * token.
     */
    public static function derive_first_name( $client_name, $client_email = '' ) {
        $name = trim( (string) $client_name );
        if ( $name === '' ) {
            return 'there';
        }
        if ( strpos( $name, '@' ) !== false ) {
            return 'there';
        }
        if ( $client_email !== '' && strcasecmp( $name, trim( (string) $client_email ) ) === 0 ) {
            return 'there';
        }
        $tokens = preg_split( '/\s+/', $name );
        while ( is_array( $tokens ) && count( $tokens ) > 1 && preg_match( '/^\d+$/', end( $tokens ) ) ) {
            array_pop( $tokens );
        }
        $first = isset( $tokens[0] ) ? trim( $tokens[0] ) : '';
        return $first !== '' ? $first : 'there';
    }

    /**
     * Resolve practitioner branding for the header right slot.
     *
     * Returns ['name'=>string, 'logo_url'=>string, 'logo_shape'=>string, 'email'=>string].
     *
     * IMPORTANT: logo_url returns '' when the practitioner has no configured
     * logo — NOT the HDL fallback. The header renderer interprets '' as
     * "name-only on the right" so the HDL brand never gets double-stamped
     * (HDL left + HDL right looks redundant).
     */
    private static function resolve_practitioner_block( $practitioner_id ) {
        $out = array( 'name' => '', 'logo_url' => '', 'logo_shape' => 'wordmark', 'email' => '' );
        if ( ! $practitioner_id ) {
            return $out;
        }
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT practitioner_name, logo_url, logo_shape FROM {$wpdb->prefix}hdlv2_widget_config WHERE practitioner_user_id = %d LIMIT 1",
            (int) $practitioner_id
        ) );
        $user = get_userdata( (int) $practitioner_id );

        if ( $row && ! empty( $row->practitioner_name ) ) {
            $out['name'] = (string) $row->practitioner_name;
        } elseif ( $user && ! empty( $user->display_name ) ) {
            $out['name'] = (string) $user->display_name;
        }

        if ( $row && ! empty( $row->logo_url ) && filter_var( $row->logo_url, FILTER_VALIDATE_URL ) ) {
            $out['logo_url']   = (string) $row->logo_url;
            $shape             = $row->logo_shape ?: 'square';
            $out['logo_shape'] = in_array( $shape, array( 'square', 'wordmark', 'tall' ), true ) ? $shape : 'square';
        }

        if ( $user && ! empty( $user->user_email ) ) {
            $out['email'] = (string) $user->user_email;
        }
        return $out;
    }

    /**
     * Legacy alias — returns practitioner logo URL OR the HDL wordmark fallback.
     *
     * Pre-v0.36.23 callers (Make.com payload builders, the practitioner
     * dashboard avatar resolver) rely on the HDL-fallback contract. The new
     * header renderer uses resolve_practitioner_block() directly and treats
     * a missing logo as "name-only on the right" instead of double-stamping
     * HDL on both sides. Keep both behaviours, route them through the same
     * single DB read.
     */
    public static function resolve_logo( $practitioner_id = null ) {
        $block = self::resolve_practitioner_block( $practitioner_id );
        return $block['logo_url'] !== '' ? $block['logo_url'] : apply_filters( "hdlv2_email_logo_url", self::HDL_LOGO );
    }

    public static function resolve_logo_shape( $practitioner_id = null ) {
        $block = self::resolve_practitioner_block( $practitioner_id );
        return $block['logo_url'] !== '' ? $block['logo_shape'] : 'wordmark';
    }

    // ──────────────────────────────────────────────────────────────
    //  STAGE 1: Quick Insight Results
    // ──────────────────────────────────────────────────────────────

    /**
     * @param array $data { client_name, rate, bio_age, age, gauge_url, token_url, rate_message }
     */
    public static function stage1_results( $data ) {
        $first    = self::derive_first_name( $data['client_name'] ?? '', $data['client_email'] ?? '' );
        $rate     = esc_html( ( $data['rate'] !== '' && $data['rate'] !== null ) ? number_format( (float) $data['rate'], 2 ) : '' );
        // v0.47.17 parity — pad whole-number bios to 1dp ("46.0") so the email
        // matches the screen/dashboard/PDF (all 1dp). Non-numeric (e.g. "?") passes through.
        $bio_age  = esc_html( is_numeric( $data['bio_age'] ) ? number_format( (float) $data['bio_age'], 1 ) : (string) $data['bio_age'] );
        $age      = esc_html( $data['age'] );
        $gauge    = esc_url( $data['gauge_url'] );
        $url      = esc_url( $data['token_url'] );
        $msg      = esc_html( $data['rate_message'] );

        $content = "
            <h2 style='margin:0 0 8px;color:" . self::DARK . ";font-family:Poppins,Inter,sans-serif;font-size:22px;font-weight:700;'>Hi " . esc_html( $first ) . ",</h2>
            <p style='color:" . self::MUTED . ";margin:0 0 24px;'>Thanks for completing your quick health insight!</p>

            <div style='text-align:center;margin:0 0 24px;'>
                <img src='{$gauge}' alt='Rate of ageing gauge' width='340' style='max-width:100%;height:auto;' />
            </div>

            <table cellpadding='0' cellspacing='0' border='0' width='100%' style='margin:0 0 20px;'>
                <tr>
                    <td style='padding:12px 16px;background:#f8f9fb;border-radius:8px 0 0 8px;text-align:center;'>
                        <div style='font-size:11px;color:" . self::MUTED . ";margin:0 0 4px;'>Rate of Ageing</div>
                        <div style='font-size:22px;font-weight:700;color:" . self::TEAL . ";'>{$rate}</div>
                    </td>
                    <td style='padding:12px 16px;background:#f8f9fb;text-align:center;'>
                        <div style='font-size:11px;color:" . self::MUTED . ";margin:0 0 4px;'>Biological Age</div>
                        <div style='font-size:22px;font-weight:700;color:" . self::DARK . ";'>{$bio_age}</div>
                    </td>
                    <td style='padding:12px 16px;background:#f8f9fb;border-radius:0 8px 8px 0;text-align:center;'>
                        <div style='font-size:11px;color:" . self::MUTED . ";margin:0 0 4px;'>Chronological</div>
                        <div style='font-size:22px;font-weight:700;color:" . self::DARK . ";'>{$age}</div>
                    </td>
                </tr>
            </table>

            <p style='color:#444;line-height:1.6;margin:0 0 24px;'>{$msg}</p>

            <p style='color:#444;line-height:1.6;margin:0 0 24px;'>
                This is a preliminary estimate based on 6 data points.
                <strong>It would be a lot more accurate if we knew a little bit more about you.</strong>
            </p>"
            . self::cta_button( $url, 'Continue to Stage 2 — Your WHY' );

        return self::base_layout( $content, $data['practitioner_id'] ?? null, 'Your Stage 1 Result' );
    }

    // ──────────────────────────────────────────────────────────────
    //  STAGE 2: WHY Reformulation — REMOVED v0.36.23
    //
    //  Previous WP `stage2_why_response()` template was orphaned in
    //  v0.36.22 when the duplicate `stage2_saved()` callsite was
    //  removed from staged-form.php. Make.com Module 84 (Sub-route 1
    //  of the Stage 2 router branch) now sends the branded "Thank you
    //  for sharing your WHY" template directly. No PHP equivalent
    //  needed.
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  STAGE 3: Draft Report Ready (to practitioner)
    // ──────────────────────────────────────────────────────────────

    /**
     * @param array $data {
     *   client_name, client_email, rate, bio_age, age,
     *   radar_chart_url, trajectory_chart_url, dashboard_url
     * }
     *
     * v0.23.5 — Rebuilt per Matthew 2026-04-29 (B3 from MEETING-ACTION-POINTS).
     * Replaced the dev-output score-key text lists ("whtrScore, heartRateScore,
     * physicalActivity…") with the same 21-metric radar + trajectory charts
     * the practitioner sees on the consultation page. Heading + subhead +
     * reminder copy updated to Matthew's annotated wording.
     */
    public static function stage3_draft_ready( $data ) {
        $cname    = esc_html( $data['client_name'] ?: ( $data['client_email'] ?? '' ) );
        // v0.40.16 — Filter-honouring fallback. Default is /clients/ (V2's
        // practitioner dashboard slug). Was /practitioner-dashboard/ — a
        // legacy slug that may not exist on LIVE.
        $url      = esc_url( ( $data['dashboard_url'] ?? '' ) ?: home_url( '/' . trim( apply_filters( 'hdlv2_practitioner_dashboard_slug', 'clients' ), '/' ) . '/' ) );

        // A3 (v0.46.30) — notification only. The numbers + radar/trajectory
        // charts live in the draft report behind the CTA, not inlined here.
        // $data['rate']/['bio_age']/['age']/['radar_chart_url']/['trajectory_chart_url'] unused.
        $content = "
            <h2 style='margin:0 0 8px;color:" . self::DARK . ";'>Stage 3 Form Completed</h2>
            <p style='color:" . self::MUTED . ";margin:0 0 24px;line-height:1.5;'>Draft Trajectory Plan is ready. Your client <strong>{$cname}</strong> has completed all three stages. Please review before the consultation.</p>

            <p style='color:#444;line-height:1.6;margin:0 0 24px;'>
                Remember this report is a draft and requires you to adjust and check the data provided by your client during the consultation in order to generate the final report that will be based on their data, your edits where needed and your consultation information.
            </p>"
            . self::cta_button( $url, 'Review Draft Trajectory Plan' );

        return self::base_layout( $content, $data['practitioner_id'] ?? null, 'Draft Trajectory Plan — Review Required' );
    }

    // ──────────────────────────────────────────────────────────────
    //  STAGE 3: Assessment Complete (to client)
    // ──────────────────────────────────────────────────────────────

    public static function stage3_complete( $data ) {
        $first     = self::derive_first_name( $data['client_name'] ?? '', $data['client_email'] ?? '' );
        $draft_url = esc_url( $data['draft_url'] ?? '' );

        $cta_block = $draft_url
            ? "<p style='font-size:15px;color:#2c3e50;line-height:1.7;margin:0 0 20px;'>Your draft report is ready for a first look. It includes your trajectory chart, your 21-metric health profile, and a first pass of what your data is telling us.</p>"
              . self::cta_button( $draft_url, 'View your draft report' )
              . "<p style='font-size:12px;color:" . self::MUTED . ";line-height:1.6;margin:20px 0 0;'>This is a <strong>draft</strong> &mdash; your practitioner will fine-tune it with you during your consultation, then send you the final plan.</p>"
            : "<p style='font-size:15px;color:#2c3e50;line-height:1.7;margin:0;'>Your practitioner is now reviewing your results and will send you your personalised report soon.</p>";

        $content = "
            <h2 style='font-family:Poppins,Inter,sans-serif;font-size:22px;font-weight:700;color:" . self::DARK . ";margin:0 0 14px;'>Hi " . esc_html( $first ) . ", your assessment is complete</h2>
            <p style='font-size:15px;color:#2c3e50;line-height:1.7;margin:0 0 18px;'>You've finished all three stages of your longevity assessment. Here's what's next.</p>
            {$cta_block}";

        return self::base_layout( $content, $data['practitioner_id'] ?? null, 'Your Draft Report' );
    }

    // ──────────────────────────────────────────────────────────────
    //  PRIVATE: LAYOUT + COMPONENTS
    // ──────────────────────────────────────────────────────────────

    /**
     * Canonical email shell used by every V2 email.
     *
     * v0.36.23 — was ($content, $logo_url, $logo_shape). Now
     * ($content, $practitioner_id, $banner_title). The old positional
     * args are silently tolerated so any deprecated caller that hasn't
     * been migrated yet still renders without a fatal — see the
     * back-compat sanitiser at the top of the body.
     *
     * v0.37.2 — added optional $preheader (4th arg). When non-empty,
     * injects a hidden <div> at the top of <body> so Gmail/Outlook
     * inbox previews show the intentional hook string instead of body
     * bleed-through. Back-compat preserved: existing callers pass 3
     * args and the preheader stays empty.
     *
     * Renders:
     *   • Hidden preheader (if supplied)
     *   • White strip with HDL wordmark (left) + practitioner logo+name (right)
     *   • Optional teal banner with email-specific editorial title
     *   • White content card
     *   • Light-grey footer with "Powered by HealthDataLab · Terms · Privacy"
     *
     * @param string   $content         Inner HTML to render inside the card.
     * @param int|null $practitioner_id Practitioner user ID (for header logo + name).
     * @param string   $banner_title    Optional editorial title for the teal banner.
     * @param string   $preheader       Optional hidden inbox-preview text.
     */
    /**
     * v0.40.1 — Visibility changed `private static` → `public static`.
     *
     * `class-hdlv2-widget-config.php::send_invite_email()` (line 2132)
     * has called this method externally since v0.36.23 (`v0.36.23 —
     * outer shell + header + footer now come from the shared
     * HDLV2_Email_Templates::base_layout()`). The method was left
     * private then, which meant every Generate-Link click in Client
     * Tools fatal'd ("Call to private method ... from scope
     * HDLV2_Widget_Config"). Caught 2026-05-12 by Quim's
     * regression report ("practitioner can't send invites... Network
     * error"). Reproduced server-side via `wp eval-file` (instance →
     * ajax_create_invite). The fix is the minimal one — opening the
     * visibility so the existing call site works. No call sites change.
     *
     * Why missed by v0.40.0 E2E: the smoke fire inserts form_progress
     * + why_profiles rows directly to mimic Stage 1 → Stage 3 → Final
     * webhook delivery. It never exercises ajax_create_invite (that's
     * the practitioner-side invite-create flow, not the client-side
     * stage flow). Future E2E rounds need a send-invite step too.
     */
    public static function base_layout( $content, $practitioner_id = null, $banner_title = '', $preheader = '' ) {
        // Back-compat: an old caller may still pass ($logo_url_string, $logo_shape).
        // If the 2nd arg looks like a URL or an empty string we treat it as legacy
        // and drop the practitioner lookup. If the 3rd arg matches a shape token
        // we treat it as legacy too — no banner gets rendered for legacy calls.
        if ( is_string( $practitioner_id ) ) {
            $practitioner_id = null;
        }
        if ( in_array( $banner_title, array( 'wordmark', 'square', 'tall' ), true ) ) {
            $banner_title = '';
        }

        $header = self::email_header( $practitioner_id, $banner_title );
        $footer = self::email_footer();

        // v0.37.2 — Hidden preheader/preview text. Renders into Gmail's
        // inbox preview line so the recipient sees an intentional hook
        // ("Your practitioner has reviewed your WHY. Time for Stage 3.")
        // instead of bleed-through from the first visible body sentence.
        // Hidden via display:none + zero dimensions + transparent color
        // so it never paints on screen. Must come BEFORE the visible
        // <table> so Gmail's scanner picks it up first.
        $preheader_html = '';
        if ( $preheader !== '' ) {
            $preheader_html = '<div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;color:transparent;font-size:1px;line-height:1px;">' . esc_html( $preheader ) . '</div>';
        }

        return "<!DOCTYPE html>
<html lang='en'>
<head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1.0'>
<title>HealthDataLab</title></head>
<body style='margin:0;padding:0;background:" . self::BG . ";font-family:Inter,-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;'>
{$preheader_html}
<table role='presentation' cellpadding='0' cellspacing='0' border='0' width='100%' style='background:" . self::BG . ";'>
<tr><td align='center' style='padding:32px 16px;'>
<table role='presentation' cellpadding='0' cellspacing='0' border='0' width='600' style='max-width:600px;width:100%;background:" . self::CARD . ";border-radius:14px;overflow:hidden;border:1px solid " . self::BORDER . ";'>
{$header}
<tr><td style='padding:32px;'>
{$content}
</td></tr>
{$footer}
</table>
</td></tr>
</table>
</body></html>";
    }

    /**
     * Thin alias for the few legacy callers that used wrap(). Forwards
     * to base_layout() so there's a single shell renderer.
     */
    private static function wrap( $content, $practitioner_id = null, $banner_title = '', $preheader = '' ) {
        return self::base_layout( $content, $practitioner_id, $banner_title, $preheader );
    }

    // ──────────────────────────────────────────────────────────────
    //  CANONICAL HEADER + FOOTER
    //
    //  Single source of truth for the two-row HDL email shell. Every
    //  email — client OR practitioner-facing — uses these. The teal
    //  banner is optional; pass '' to render header-only (white strip
    //  with logos but no editorial title bar).
    //
    //  v0.36.23 — extracted from sprint-1/widget-config.php's
    //  send_invite_email() so the invitation, thank-you, and Stage 3
    //  invite (all client-touching) read as ONE brand system. Same
    //  pattern shipped in v0.36.19 for invites, now applied
    //  everywhere.
    // ──────────────────────────────────────────────────────────────

    /**
     * Build the two-row email header.
     *
     * Row 1 (white strip): HDL wordmark left + practitioner logo/name right.
     * Row 2 (teal banner): banner title in serif display type.
     *
     * When the practitioner has NO configured logo, the right cell
     * renders name-only — we never double-stamp HDL on both sides.
     */
    private static function email_header( $practitioner_id, $banner_title ) {
        $prac = self::resolve_practitioner_block( $practitioner_id );

        // Right cell: either practitioner logo+name, or name-only.
        $right_cell = '';
        if ( $prac['logo_url'] !== '' ) {
            $shape   = $prac['logo_shape'];
            $img_h   = '28';
            $img_max = '160';
            $img_extra = '';
            if ( 'square' === $shape ) {
                $img_h     = '28';
                $img_max   = '28';
                $img_extra = 'border-radius:50%;';
            } elseif ( 'tall' === $shape ) {
                $img_h   = '36';
                $img_max = '50';
            }
            $right_cell .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="right" style="display:inline-table;">';
            $right_cell .= '<tr><td style="text-align:center;line-height:0;padding:0;">';
            $right_cell .= '<img src="' . esc_url( $prac['logo_url'] ) . '" alt="' . esc_attr( $prac['name'] ?: 'Practitioner' ) . '" height="' . esc_attr( $img_h ) . '" style="height:' . esc_attr( $img_h ) . 'px;max-height:' . esc_attr( $img_h ) . 'px;width:auto;max-width:' . esc_attr( $img_max ) . 'px;display:block;border:0;outline:none;text-decoration:none;' . $img_extra . '">';
            $right_cell .= '</td></tr>';
            if ( $prac['name'] !== '' ) {
                $right_cell .= '<tr><td style="text-align:right;padding-top:6px;line-height:1.4;">';
                $right_cell .= '<span style="font-size:13px;color:' . self::DARK_TEXT . ';font-weight:600;font-family:Inter,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">' . esc_html( $prac['name'] ) . '</span>';
                $right_cell .= '</td></tr>';
            }
            $right_cell .= '</table>';
        } elseif ( $prac['name'] !== '' ) {
            $right_cell .= '<span style="font-size:14px;color:' . self::DARK_TEXT . ';font-weight:600;font-family:Inter,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">' . esc_html( $prac['name'] ) . '</span>';
        }

        // Row 1: white strip.
        $out  = '<tr><td style="background:' . self::CARD . ';padding:22px 28px;border-bottom:1px solid ' . self::BORDER . ';">';
        $out .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">';
        $out .= '<tr>';
        $out .= '<td valign="middle" style="text-align:left;">';
        $out .= '<img src="' . esc_url( apply_filters( "hdlv2_email_logo_url", self::HDL_LOGO ) ) . '" alt="Health Data Lab" width="265" height="25" style="width:265px;height:25px;max-width:265px;display:block;border:0;outline:none;text-decoration:none;">';
        $out .= '</td>';
        $out .= '<td valign="middle" align="right" style="text-align:right;">' . $right_cell . '</td>';
        $out .= '</tr></table>';
        $out .= '</td></tr>';

        // Row 2: teal banner (only when a title is supplied).
        if ( $banner_title !== '' ) {
            $out .= '<tr><td style="background:' . self::TEAL . ';padding:32px 30px;color:#ffffff;">';
            $out .= '<p style="margin:0;text-align:center;font-size:26px;font-weight:600;line-height:1.25;letter-spacing:-0.01em;color:#ffffff;font-family:\'Source Serif Pro\',Georgia,serif;">' . esc_html( $banner_title ) . '</p>';
            $out .= '</td></tr>';
        }

        return $out;
    }

    /**
     * Build the canonical footer: "Powered by HealthDataLab · Terms · Privacy".
     */
    private static function email_footer() {
        $out  = '<tr><td style="background:' . self::SOFT_FILL . ';border-top:1px solid ' . self::BORDER . ';padding:16px 28px;text-align:center;font-size:11px;color:#999999;line-height:1.5;font-family:Inter,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">';
        $out .= 'Powered by <strong style="color:' . self::TEAL . ';">HealthDataLab</strong> &nbsp;&middot;&nbsp; ';
        $out .= '<a href="' . esc_url( apply_filters( "hdlv2_email_terms_url", self::TERMS_URL ) ) . '" style="color:#999999;text-decoration:underline;">Terms</a> &nbsp;&middot;&nbsp; ';
        $out .= '<a href="' . esc_url( apply_filters( "hdlv2_email_privacy_url", self::PRIVACY_URL ) ) . '" style="color:#999999;text-decoration:underline;">Privacy</a>';
        // v0.46.19 — port of the legal disclaimer (per HANDOFF-2026-05-28). Built
        // on the 0.41 line but never ported to the 0.46/safety line that became
        // STBY, so V2 emails were missing the NOT-MEDICAL-ADVICE / IRISLAB fine
        // print. Wiring it into the shared footer means every base_layout email
        // inherits it from one place.
        $out .= self::legal_disclaimer_html();
        $out .= '</td></tr>';
        return $out;
    }

    /**
     * Confidential / NOT-MEDICAL-ADVICE / IRISLAB registered-entity fine print.
     * Public so non-base_layout senders (e.g. bespoke provisioning emails) can
     * reuse the identical wording. "NOT MEDICAL ADVICE" is the only emphasised
     * phrase so it reads as a disclaimer, not marketing.
     */
    public static function legal_disclaimer_html() {
        return '<div style="margin:12px auto 0;max-width:460px;padding-top:12px;border-top:1px solid #eef0f3;font-size:10px;line-height:1.6;color:#aaaaaa;font-family:Inter,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">'
            . 'Confidential &mdash; recipient only. <span style="color:#8a9098;font-weight:600;letter-spacing:0.02em;">NOT MEDICAL ADVICE.</span> '
            . 'Operated by IRISLAB LIMITED, England &amp; Wales Co. No. 8260301, Sussex Innovation Centre, Science Park Square, Brighton BN1 9SB.'
            . '</div>';
    }

    private static function cta_button( $url, $text ) {
        // v0.36.0 — editorial CTA matches .hdlw-cta (deep teal, 2px
        // radius, Inter weight 600 letter-spaced) so the email button
        // and the widget button read as one design system.
        return "
        <div style='text-align:center;margin:28px 0 8px;'>
            <table border='0' cellpadding='0' cellspacing='0' role='presentation' style='margin:0 auto;'>
                <tr>
                    <td style='border-radius:2px;background:" . self::DARK_TEAL . ";'>
                        <a href='" . esc_url( $url ) . "' style='display:inline-block;background:" . self::DARK_TEAL . ";color:#ffffff;text-decoration:none;padding:14px 30px;border-radius:2px;font-size:13.5px;font-weight:600;letter-spacing:0.04em;font-family:Inter,-apple-system,sans-serif;'>
                            {$text} &rarr;
                        </a>
                    </td>
                </tr>
            </table>
        </div>";
    }

    // ──────────────────────────────────────────────────────────────
    //  STAGE 2 AWAITING RELEASE — practitioner notification (HTML + CTA)
    // ──────────────────────────────────────────────────────────────

    /**
     * @param array $data { client_name, client_email, distilled_why, dashboard_url }
     */
    public static function stage2_awaiting_release( $data ) {
        // Use the raw stored name (no first-name derivation here — the
        // practitioner needs to see the full name they typed, not the
        // truncated first-token).
        $cname   = esc_html( $data['client_name'] ?: $data['client_email'] );
        $cemail  = esc_html( $data['client_email'] ?? '' );
        // A3 (v0.46.30) — the distilled WHY is no longer inlined in this
        // notification. It lives in the Stage-2 WHY PDF (Make module 81) and on
        // the dashboard the CTA opens. $data['distilled_why'] intentionally unused.
        // v0.40.16 — This email is sent to the PRACTITIONER ("Ready to invite
        // your client to Stage 3"), so the dashboard fallback should point at
        // the practitioner dashboard, not /client-dashboard/. The previous
        // fallback was doubly wrong: wrong audience AND wrong slug (V2's
        // client dashboard is /my-dashboard/, not /client-dashboard/).
        $url     = esc_url( $data['dashboard_url'] ?: home_url( '/' . trim( apply_filters( 'hdlv2_practitioner_dashboard_slug', 'clients' ), '/' ) . '/' ) );

        $content = "
            <h2 style='margin:0 0 12px;color:" . self::DARK . ";font-family:Poppins,Inter,sans-serif;font-size:20px;font-weight:700;'>Ready to invite your client to Stage 3</h2>
            <p style='color:" . self::MUTED . ";margin:0 0 20px;font-size:14px;line-height:1.6;'>Your client <strong>{$cname}</strong> has submitted their Stage 2 WHY. Review and release the gate to unlock Stage 3.</p>

            <p style='font-size:13px;color:#444;line-height:1.6;margin:0 0 24px;'>
                Clicking below opens your dashboard with this client highlighted &mdash; you can review the full WHY, add
                a note, and release the gate with one click. The client will be emailed an invitation to Stage 3.
            </p>"
            . self::cta_button( $url, 'Review and invite to Stage 3' )
            . "<p style='font-size:11px;color:" . self::MUTED . ";line-height:1.5;margin:18px 0 0;text-align:center;'>{$cemail}</p>";

        return self::base_layout( $content, $data['practitioner_id'] ?? null, 'Stage 2 — Ready to Release' );
    }

    // ──────────────────────────────────────────────────────────────
    //  WHY GATE RELEASED — Stage 3 invitation
    // ──────────────────────────────────────────────────────────────

    /**
     * Stage 3 invitation — client email fired when the practitioner
     * releases the WHY gate. v0.37.2 rewritten to match spec:
     *   - First-person voice (reads AS the practitioner speaking)
     *   - 10–15 min duration + physical-checks heads-up
     *   - "When you're ready" CTA lead-in
     *   - Help line with practitioner email + send-only disclaimer
     *   - Warm regards sign-off with practitioner first name
     *   - Three-line signature card (name · title · email)
     *   - Hidden preheader so inbox preview reads as intentional hook
     *   - Subject includes client full name + DD/MM/YYYY HH:mm
     *
     * Send-only from address (noreply@healthdatalab.net via WP default).
     * Reply-To intentionally NOT set — the help line tells the client
     * to compose a fresh message to the practitioner's address.
     */
    public static function why_gate_released( $data ) {
        $first       = self::derive_first_name( $data['client_name'] ?? '', $data['client_email'] ?? '' );
        $client_name = (string) ( $data['client_name'] ?? '' );
        $email       = $data['client_email'] ?? '';
        $form_url    = $data['form_url'] ?? '#';
        $prac        = self::resolve_practitioner_block( $data['practitioner_id'] ?? null );
        $prac_first  = $prac['name'] !== '' ? strtok( $prac['name'], ' ' ) : 'your practitioner';
        $prac_email  = $prac['email'] !== '' ? $prac['email'] : '';

        // Build the inline help line — practitioner email link only renders
        // when we actually resolved one. The send-only disclaimer is the
        // pre-emptive nudge against hitting Reply (From is noreply@).
        $help_line = '';
        if ( $prac_email !== '' ) {
            $help_line = "<p style='font-size:14px;color:#2c3e50;line-height:1.7;margin:24px 0 24px;'>If anything's unclear or you get stuck, drop me a note at <a href='mailto:" . esc_attr( $prac_email ) . "' style='color:" . self::TEAL . ";text-decoration:underline;font-weight:500;'>" . esc_html( $prac_email ) . "</a> &mdash; we can sort it out at your consultation. <em style='color:" . self::MUTED . ";font-style:italic;'>(This email address is send-only, so please don't click reply.)</em></p>";
        }

        // Signature card — practitioner full name + title · practice + email.
        // Title/practice hardcoded per established pattern (Module 84 in
        // Make.com uses the same default). Future enhancement: pull from
        // widget_config when columns are added.
        $sig_card = "<table role='presentation' cellpadding='0' cellspacing='0' border='0' width='100%' style='background:" . self::SOFT_FILL . ";border-radius:8px;border:1px solid " . self::BORDER . ";margin:0 0 8px;'>
            <tr><td style='padding:18px 22px;font-family:Inter,-apple-system,BlinkMacSystemFont,\"Segoe UI\",sans-serif;font-size:13px;line-height:1.7;color:#2c3e50;'>
                <strong style='color:" . self::DARK_TEAL . ";font-size:14px;'>" . esc_html( $prac['name'] ?: 'Your practitioner' ) . "</strong><br />
                <span style='color:#666666;'>Longevity Practitioner &middot; Health Data Lab</span>"
                . ( $prac_email !== '' ? "<br /><a href='mailto:" . esc_attr( $prac_email ) . "' style='color:" . self::TEAL . ";text-decoration:none;font-weight:500;'>" . esc_html( $prac_email ) . "</a>" : '' ) . "
            </td></tr>
        </table>";

        $content = "<p style='font-size:16px;color:" . self::DARK_TEXT . ";line-height:1.7;margin:0 0 18px;'>Dear " . esc_html( $first ) . ",</p>"
            . "<p style='font-size:15px;color:#2c3e50;line-height:1.7;margin:0 0 18px;'>I've reviewed the answers you shared in your &ldquo;Your Why&rdquo; form (Stage 2). Stage 3 is the next piece &mdash; it collects detailed health measurements that, combined with everything you've already shared, will form the basis of your personalised Trajectory Plan.</p>"
            . "<p style='font-size:15px;color:#2c3e50;line-height:1.7;margin:0 0 18px;'>It takes around <strong>10&ndash;15 minutes</strong>. A few of the questions involve simple physical checks (a sit-to-stand test, a breath hold, a balance test) and a tape measure for waist and hip. If anything isn't possible right now, leave it blank &mdash; we'll cover it in your first consultation. Best to do your honest best rather than guess.</p>"
            . "<p style='font-size:15px;color:#2c3e50;line-height:1.7;margin:0 0 8px;'>When you're ready:</p>"
            . self::cta_button( $form_url, 'Continue to Stage 3' )
            . $help_line
            . "<p style='font-size:15px;color:#2c3e50;line-height:1.7;margin:24px 0 4px;'>Warm regards,</p>"
            . "<p style='font-size:15px;color:" . self::DARK_TEAL . ";font-weight:600;line-height:1.7;margin:0 0 22px;'>" . esc_html( $prac_first ) . "</p>"
            . $sig_card;

        // v0.37.2 — subject + preheader from spec.
        // Subject format: "Your Stage 3 invitation — {full name} — {DD/MM/YYYY HH:mm}"
        // (current_time respects the WP site timezone; safe across STBY + LIVE)
        $subject_name = $client_name !== '' ? $client_name : 'your client';
        $subject      = sprintf(
            'Your Stage 3 invitation — %s — %s',
            $subject_name,
            current_time( 'd/m/Y H:i' )
        );

        $preheader = "Your practitioner has reviewed your WHY. Time for Stage 3.";

        $html = self::base_layout(
            $content,
            $data['practitioner_id'] ?? null,
            'Your Stage 3 is ready',
            $preheader
        );

        wp_mail( $email, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  WEEKLY CHECK-IN REMINDER
    // ──────────────────────────────────────────────────────────────

    public static function checkin_reminder( $data ) {
        $first = self::derive_first_name( $data['client_name'] ?? '', $data['client_email'] ?? '' );
        $email = $data['client_email'] ?? '';
        $url   = $data['checkin_url'] ?? '#';

        $content = "<h2 style='font-family:Poppins,Inter,sans-serif;font-size:22px;font-weight:700;color:" . self::DARK . ";margin:0 0 14px;'>Hi " . esc_html( $first ) . ", how did this week go?</h2>"
            . "<p style='font-size:15px;color:#2c3e50;line-height:1.7;margin:0 0 24px;'>Take a few minutes to reflect on your progress. Your practitioner uses your check-ins to fine-tune your plan.</p>"
            . self::cta_button( $url, 'Start Check-in' );

        $html = self::base_layout( $content, $data['practitioner_id'] ?? null, 'Time for Your Check-in' );

        wp_mail( $email, 'Time for your weekly check-in — HealthDataLab', $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  FLIGHT PLAN READY
    // ──────────────────────────────────────────────────────────────

    public static function flight_plan_ready( $data ) {
        $first   = self::derive_first_name( $data['client_name'] ?? '', $data['client_email'] ?? '' );
        $email   = $data['client_email'] ?? '';
        $url     = $data['plan_url'] ?? '#';
        $pdf_url = $data['pdf_url'] ?? '';
        $week    = (int) ( $data['week_number'] ?? 0 );

        // v0.25.1 — Two-state copy keyed off $pdf_url (Matthew B4 from
        // MEETING-ACTION-POINTS-2026-04-29). Plugin fires the email AFTER
        // the plan_data row is written, but the printable PDF is rendered
        // asynchronously by Make.com → PDFMonkey, which can take ~1 hour.
        // Until v0.25.0 the email said "is Ready" regardless, so clients
        // clicking through during the gap saw an empty PDF link.
        if ( $pdf_url ) {
            $heading = "Hi " . esc_html( $first ) . ", your Week {$week} plan is ready";
            $body    = "Your new weekly plan includes your daily actions, shopping list, and this week's focus areas.";
            $banner  = "Week {$week} Flight Plan";
            $subject = "Your Week {$week} Flight Plan — HealthDataLab";
            $buttons = self::cta_button( $pdf_url, 'Download Your Flight Plan (PDF)' )
                     . "<p style='text-align:center;font-size:13px;color:#666;margin:12px 0 0;'>Or <a href='" . esc_url( $url ) . "' style='color:" . self::TEAL . ";'>view online</a> to tick off items as you go.</p>";
        } else {
            $heading = "Hi " . esc_html( $first ) . ", your Week {$week} plan is on the way";
            $body    = "Your new weekly plan is being created and will be completed within the next hour. In the meantime you can tick off items as you go using the online version below.";
            $banner  = "Week {$week} — Generating";
            $subject = "Your Week {$week} Flight Plan is being created — HealthDataLab";
            $buttons = self::cta_button( $url, 'View Your Flight Plan' );
        }

        $content = "<h2 style='font-family:Poppins,Inter,sans-serif;font-size:22px;font-weight:700;color:" . self::DARK . ";margin:0 0 14px;'>{$heading}</h2>"
            . "<p style='font-size:15px;color:#2c3e50;line-height:1.7;margin:0 0 24px;'>{$body}</p>"
            . $buttons;

        $html = self::base_layout( $content, $data['practitioner_id'] ?? null, $banner );

        wp_mail( $email, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  QUARTERLY REVIEW DUE (to practitioner)
    // ──────────────────────────────────────────────────────────────

    public static function quarterly_review_due( $data ) {
        $prac_email  = $data['practitioner_email'] ?? '';
        $client_name = esc_html( $data['client_name'] ?? 'Unknown' );
        // v0.40.16 — Filter-honouring fallback (was '#'). Quarterly review is
        // a practitioner email, so default to /clients/.
        $dash_url    = $data['dashboard_url'] ?: home_url( '/' . trim( apply_filters( 'hdlv2_practitioner_dashboard_slug', 'clients' ), '/' ) . '/' );

        $content = "<h2 style='font-family:Poppins,Inter,sans-serif;font-size:20px;font-weight:700;color:" . self::DARK . ";margin:0 0 14px;'>Time for {$client_name}'s quarterly review</h2>"
            . "<p style='font-size:14px;color:#444;line-height:1.7;margin:0 0 20px;'>Three months have elapsed since their last assessment. A reassessment will update their trajectory and refresh their milestones.</p>"
            . self::cta_button( $dash_url, 'View Client' );

        $html = self::base_layout( $content, $data['practitioner_id'] ?? null, 'Quarterly Review Due' );

        wp_mail( $prac_email, "Quarterly Review Due: {$client_name} — HealthDataLab", $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  QUARTERLY REVIEW DUE (to client)
    // ──────────────────────────────────────────────────────────────

    public static function quarterly_review_client( $data ) {
        $first = self::derive_first_name( $data['client_name'] ?? '', $data['client_email'] ?? '' );
        $email = $data['client_email'] ?? '';
        $url   = $data['review_url'] ?? '#';

        $content = "<h2 style='font-family:Poppins,Inter,sans-serif;font-size:22px;font-weight:700;color:" . self::DARK . ";margin:0 0 14px;'>Hi " . esc_html( $first ) . ", it's quarterly review time</h2>"
            . "<p style='font-size:15px;color:#2c3e50;line-height:1.7;margin:0 0 16px;'>It's been three months since your last assessment. A quarterly review updates your progress, refreshes your milestones, and helps your practitioner fine-tune your plan.</p>"
            . "<p style='font-size:15px;color:#2c3e50;line-height:1.7;margin:0 0 24px;'>Your practitioner will be in touch to arrange your review.</p>"
            . self::cta_button( $url, 'View Your Progress' );

        $html = self::base_layout( $content, $data['practitioner_id'] ?? null, 'Quarterly Review' );

        wp_mail( $email, 'Quarterly Review Due — HealthDataLab', $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  CLIENT NEEDS ATTENTION (to practitioner)
    // ──────────────────────────────────────────────────────────────

    public static function client_needs_attention( $data ) {
        $prac_email   = $data['practitioner_email'] ?? '';
        $client_name  = esc_html( $data['client_name'] ?? 'Unknown' );
        $reasons      = $data['reasons'] ?? array();
        $timeline_url = $data['timeline_url'] ?? '#';

        $reasons_html = '';
        foreach ( $reasons as $r ) {
            $reasons_html .= '<li style="margin-bottom:4px;">' . esc_html( $r ) . '</li>';
        }

        $content = "<h2 style='font-family:Poppins,Inter,sans-serif;font-size:20px;font-weight:700;color:#dc2626;margin:0 0 14px;'>{$client_name} needs your attention</h2>"
            . "<p style='font-size:14px;color:#444;line-height:1.7;margin:0 0 12px;'>The following has been flagged:</p>"
            . "<ul style='font-size:14px;color:#444;line-height:1.7;margin:0 0 20px;padding-left:20px;'>{$reasons_html}</ul>"
            . self::cta_button( $timeline_url, 'View Timeline' );

        $html = self::base_layout( $content, $data['practitioner_id'] ?? null, 'Client Needs Attention' );

        wp_mail( $prac_email, "Attention Required: {$client_name} — HealthDataLab", $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  RED-FLAG EMAILS — client-facing, non-diagnostic
    // ──────────────────────────────────────────────────────────────

    /**
     * ONE combined client safety email (v0.45.3). Replaces the former separate
     * client_redflag_alert (GP nudge) + client_crisis_resources (crisis) sends,
     * so a flagged client never receives more than one safety email.
     *
     * Layout (see mockups/safety-flag-emails-preview.html):
     *   - Crisis support block FIRST when a self-harm flag is present (the
     *     support lines are the priority; the self-harm tick is never echoed).
     *   - A premium "card" per symptom/mood finding: urgency chip + what they
     *     flagged + why we flagged it + the caring next step.
     *   - Banner + subject lead on the crisis tone when crisis is present.
     *
     * $data: client_email, client_name, practitioner_id,
     *        crisis (bool — any self-harm flag present),
     *        findings (array of flag objects: label, concern, urgency,
     *                  client_facing_wording).
     */
    public static function client_safety_alert( $data ) {
        $email = $data['client_email'] ?? '';
        if ( ! $email ) { return; }
        $crisis   = ! empty( $data['crisis'] );
        $findings = ( isset( $data['findings'] ) && is_array( $data['findings'] ) ) ? $data['findings'] : array();
        // Nothing to say → send nothing (notify() already guards; belt-and-braces).
        if ( ! $crisis && empty( $findings ) ) { return; }
        $first = esc_html( self::derive_first_name( $data['client_name'] ?? '', $email ) );

        $content = '';

        if ( $crisis ) {
            $content .= "<h2 style='font-family:Poppins,Inter,sans-serif;font-size:21px;font-weight:700;color:" . self::DARK . ";margin:0 0 14px;line-height:1.3;'>Hi {$first}, thank you for being honest</h2>"
                . "<p style='font-size:15px;line-height:1.7;color:#2c3e50;margin:0 0 22px;'>Thank you for completing your assessment &mdash; your results are on their way. A couple of things you shared are worth acting on, starting with the most important.</p>"
                . "<table role='presentation' cellpadding='0' cellspacing='0' border='0' width='100%' style='margin:0 0 26px;'><tr><td style='background:#f1f6f7;border:1px solid #cfe0e3;border-left:4px solid " . self::DARK_TEAL . ";border-radius:0 10px 10px 0;padding:18px 20px;'>"
                . "<div style='font-size:12px;text-transform:uppercase;letter-spacing:0.06em;color:" . self::DARK_TEAL . ";font-weight:700;margin-bottom:8px;'>If you're struggling right now</div>"
                . "<p style='font-size:14px;line-height:1.7;color:#2c3e50;margin:0 0 12px;'>What you shared about how you've been feeling matters, and it's not something to face on your own. These lines are free, confidential, and open right now:</p>"
                . "<ul style='font-size:14px;line-height:1.9;color:#2c3e50;margin:0;padding-left:20px;'>"
                . "<li>If you feel unsafe right now: call <a href='tel:999' style='color:" . self::TEAL . ";'><strong>999</strong></a> or go to A&amp;E.</li>"
                . "<li><a href='https://111.nhs.uk' style='color:" . self::TEAL . ";'><strong>NHS 111</strong></a> &mdash; select the mental health option, 24/7.</li>"
                . "<li><a href='https://www.samaritans.org' style='color:" . self::TEAL . ";'><strong>Samaritans</strong></a> &mdash; call <a href='tel:116123' style='color:" . self::TEAL . ";'><strong>116 123</strong></a>, any time.</li>"
                . "<li><a href='https://giveusashout.org' style='color:" . self::TEAL . ";'><strong>Shout</strong></a> &mdash; text <strong>SHOUT</strong> to <strong>85258</strong> if you'd rather text than talk.</li>"
                . "<li><a href='https://www.papyrus-uk.org/papyrus-hopeline247/' style='color:" . self::TEAL . ";'><strong>Papyrus HOPELINE247</strong></a> (for people under 35) &mdash; call <a href='tel:08000684141' style='color:" . self::TEAL . ";'><strong>0800 068 4141</strong></a> or text <strong>88247</strong>.</li>"
                . "</ul></td></tr></table>";
            if ( ! empty( $findings ) ) {
                $content .= "<h3 style='font-family:Poppins,Inter,sans-serif;font-size:16px;font-weight:700;color:" . self::DARK . ";margin:0 0 14px;'>Also worth a word with your GP</h3>";
            }
        } else {
            $content .= "<h2 style='font-family:Poppins,Inter,sans-serif;font-size:21px;font-weight:700;color:" . self::DARK . ";margin:0 0 14px;line-height:1.3;'>Hi {$first}, a couple of things worth following up</h2>"
                . "<p style='font-size:15px;line-height:1.7;color:#2c3e50;margin:0 0 24px;'>Thank you for completing your assessment &mdash; your results are on their way. A few of your answers stood out as worth a proper look. Here's what we noticed, and why:</p>";
        }

        foreach ( $findings as $f ) {
            $content .= self::safety_finding_card( $f );
        }

        $closing_margin = empty( $findings ) ? '0' : '4px 0 0';
        $content .= "<p style='font-size:13px;line-height:1.7;color:#666;margin:{$closing_margin};'>This isn't a diagnosis and it isn't medical advice &mdash; we're simply flagging what we noticed so you can mention it to a professional. HealthDataLab is not a medical or crisis service.</p>";

        $banner  = $crisis ? "You don't have to face this alone" : 'A note about your assessment';
        $subject = $crisis ? 'Support is available — HealthDataLab' : 'A note about your HealthDataLab assessment';

        $html = self::base_layout( $content, $data['practitioner_id'] ?? null, $banner );
        // v0.46.21 (QA F3) — return wp_mail()'s boolean so the caller can detect
        // a failed crisis/GP-nudge send and avoid stamping messaged_at on it.
        return wp_mail( $email, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    /**
     * Render one premium "caring card" for a non-crisis flag. Reads label
     * (what they ticked), concern (why), urgency (chip) and
     * client_facing_wording (the caring next step). Degrades gracefully when a
     * flag (e.g. an AI-scan flag) lacks a clean label.
     */
    private static function safety_finding_card( $flag ) {
        if ( ! is_array( $flag ) ) { return ''; }

        $label = '';
        if ( ! empty( $flag['label'] ) ) {
            $label = (string) $flag['label'];
        } elseif ( ! empty( $flag['trigger'] ) ) {
            // "Safety screen — ticked: <label>" → recover the label.
            $label = trim( preg_replace( '/^.*?ticked:\s*/i', '', (string) $flag['trigger'] ) );
        } elseif ( ! empty( $flag['concern'] ) ) {
            $label = (string) $flag['concern'];
        }
        $concern = trim( (string) ( $flag['concern'] ?? '' ) );
        $action  = trim( (string) ( $flag['client_facing_wording'] ?? '' ) );
        $urgency = strtoupper( (string) ( $flag['urgency'] ?? '' ) );

        // Urgency → chip label + status-palette colour + matching left accent.
        $chip = 'Worth a GP conversation';
        $ct = '#3b82f6'; $cb = '#eff6ff'; $cd = '#bfdbfe'; $accent = '#3b82f6';
        if ( 'TODAY' === $urgency ) {
            $chip = 'Worth checking today';
            $ct = '#dc2626'; $cb = '#fef2f2'; $cd = '#fecaca'; $accent = '#dc2626';
        } elseif ( 'THIS_WEEK' === $urgency ) {
            $chip = 'Worth a GP visit this week';
            $ct = '#d97706'; $cb = '#fffbeb'; $cd = '#fde68a'; $accent = '#d97706';
        } elseif ( 'WITHIN_WEEKS' === $urgency ) {
            $chip = 'Worth raising with your GP'; // keeps the blue (info) palette
        }

        $out  = "<table role='presentation' cellpadding='0' cellspacing='0' border='0' width='100%' style='margin:0 0 16px;'><tr><td style='background:#ffffff;border:1px solid " . self::BORDER . ";border-left:3px solid {$accent};border-radius:0 12px 12px 0;padding:18px 20px;'>";
        $out .= "<span style='display:inline-block;background:{$cb};color:{$ct};border:1px solid {$cd};border-radius:999px;padding:3px 11px;font-size:11px;font-weight:700;letter-spacing:0.03em;text-transform:uppercase;'>" . esc_html( $chip ) . "</span>";
        if ( $label !== '' ) {
            $out .= "<h3 style='font-family:Poppins,Inter,sans-serif;font-size:17px;font-weight:700;color:" . self::DARK . ";margin:12px 0 8px;line-height:1.35;'>" . esc_html( $label ) . "</h3>";
        }
        if ( $concern !== '' ) {
            $out .= "<p style='font-size:14px;line-height:1.65;color:#444;margin:0 0 8px;'><strong style='color:#2c3e50;'>Why we flagged this:</strong> " . esc_html( rtrim( $concern, '.' ) ) . ".</p>";
        }
        if ( $action !== '' ) {
            $out .= "<p style='font-size:14px;line-height:1.65;color:#2c3e50;margin:0;'>" . esc_html( $action ) . "</p>";
        }
        $out .= "</td></tr></table>";
        return $out;
    }

    /**
     * Practitioner-facing red-flag alert (action B). Sent when a client is flagged
     * by the safety screen or AI scan, in addition to the dashboard "Needs attention"
     * badge. $data: practitioner_email, practitioner_id, client_name, dashboard_url,
     * flags_html (pre-built <tr> rows from HDLV2_Flags_Store::practitioner_flags_html()).
     */
    public static function practitioner_redflag_alert( $data ) {
        $email = $data['practitioner_email'] ?? '';
        if ( ! $email ) { return; }
        $client = esc_html( $data['client_name'] ?? 'A client' );
        $first  = esc_html( self::derive_first_name( $data['client_name'] ?? '', '' ) );
        $rows   = $data['flags_html'] ?? '';
        $url    = $data['dashboard_url'] ?? '#';

        $content = "<div style='background:#fef2f2;border:1px solid #fecaca;border-left:3px solid #dc2626;border-radius:0 6px 6px 0;padding:14px 16px;margin:0 0 18px;'>"
            . "<p style='margin:0;font-family:Poppins,Inter,sans-serif;font-size:15px;font-weight:700;color:#dc2626;'>{$client} flagged something on the safety check</p>"
            . "<p style='margin:6px 0 0;font-size:13px;color:#7f1d1d;line-height:1.6;'>Self-reported tick-box answers from the Stage 1 safety check &mdash; <strong>not a diagnosis</strong>.</p>"
            . "</div>"
            . "<table role='presentation' cellpadding='0' cellspacing='0' border='0' width='100%' style='margin:0 0 22px;'>{$rows}</table>"
            . self::cta_button( $url, "Open {$first}'s record" );

        $html = self::base_layout( $content, $data['practitioner_id'] ?? null, 'Needs attention' );
        // v0.46.21 (QA F3) — return wp_mail()'s boolean so the caller can detect
        // a failed practitioner red-flag alert and avoid stamping messaged_at.
        return wp_mail( $email, "Needs attention: {$client} — HealthDataLab", $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  WIDGET VERIFICATION — sent after widget submission, before
    //  any account is created. Click confirms ownership of the
    //  email and unlocks Stage 2.
    //
    //  Returns the rendered HTML so the caller controls the actual
    //  wp_mail() send (lets us set per-recipient cooldown transients
    //  before/after dispatch in one place).
    //
    //  $data { client_name, rate, gauge_url, rate_message,
    //          practitioner_name, verify_url, reject_url,
    //          is_returning_user (bool — for "Welcome back" copy) }
    // ──────────────────────────────────────────────────────────────

    public static function widget_verification( $data ) {
        $first     = self::derive_first_name( $data['client_name'] ?? '', $data['client_email'] ?? '' );
        $rate      = esc_html( $data['rate'] ?? '' );
        $gauge     = esc_url( $data['gauge_url'] ?? '' );
        $msg       = esc_html( $data['rate_message'] ?? '' );
        $prac_name = esc_html( $data['practitioner_name'] ?? 'your practitioner' );
        $verify    = esc_url( $data['verify_url'] );
        $reject    = esc_url( $data['reject_url'] ?? '' );
        $returning = ! empty( $data['is_returning_user'] );

        $headline = $returning
            ? "Welcome back, " . esc_html( $first ) . " &mdash; one click to add {$prac_name}"
            : "Hi " . esc_html( $first ) . " &mdash; confirm to save your ageing score";

        $intro = $returning
            ? "We noticed you already have an HDL account. Click below to add {$prac_name} as your practitioner and continue your assessment."
            : "Someone (we hope you!) just completed the quick ageing assessment on {$prac_name}'s site. Click the button to save your score and unlock the full assessment.";

        $content = "
            <h2 style='margin:0 0 12px;color:" . self::DARK . ";font-family:Poppins,Inter,sans-serif;font-size:22px;font-weight:700;'>{$headline}</h2>
            <p style='color:" . self::MUTED . ";margin:0 0 24px;font-size:14px;line-height:1.6;'>{$intro}</p>"
            . ( $gauge ? "<div style='text-align:center;margin:0 0 20px;'><img src='{$gauge}' alt='Rate of ageing gauge' width='320' style='max-width:100%;height:auto;display:block;margin:0 auto;'/></div>" : '' )
            . ( $rate ? "<div style='background:#f8f9fb;border-radius:10px;padding:16px;text-align:center;margin:0 0 20px;'>
                    <div style='font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:" . self::MUTED . ";margin:0 0 6px;font-weight:600;'>Your Pace of Ageing</div>
                    <div style='font-size:32px;font-weight:700;color:" . self::TEAL . ";'>{$rate}&times;</div>
                </div>" : '' )
            . ( $msg ? "<p style='color:#444;line-height:1.6;margin:0 0 20px;font-size:14px;'>{$msg}</p>" : '' )
            . self::cta_button( $verify, 'Confirm and Continue' )
            . "<p style='font-size:11px;color:" . self::MUTED . ";text-align:center;margin:16px 0 0;line-height:1.5;'>This link is unique to you and expires in 48 hours. No password needed &mdash; just click to continue.</p>"
            . ( $reject ? "<div style='border-top:1px solid #eef0f3;padding-top:20px;margin-top:24px;text-align:center;'>
                    <p style='font-size:12px;color:" . self::MUTED . ";margin:0 0 8px;line-height:1.5;'>Didn't fill out this form? Someone may have used your email by mistake.</p>
                    <a href='{$reject}' style='font-size:12px;color:" . self::MUTED . ";text-decoration:underline;'>This wasn't me &mdash; remove my email</a>
                </div>" : '' );

        return self::base_layout( $content, $data['practitioner_id'] ?? null, 'Confirm to Save Your Score' );
    }

    // ──────────────────────────────────────────────────────────────
    //  SCORE KEY → HUMAN LABEL
    //
    //  HDLV2_Rate_Calculator emits camelCase keys (whrScore, sitToStand,
    //  physicalActivity…). Raw keys in a practitioner-facing email look
    //  unfinished. This map is the single source of truth for the human
    //  label we show in emails; unknown keys de-camelise as a safety net.
    // ──────────────────────────────────────────────────────────────

    private static function score_key_to_label( $key ) {
        static $map = array(
            // v0.23.1 — overallHealthScore removed (Matthew 2026-04-28).
            'bmiScore'           => 'BMI',
            'bloodPressureScore' => 'Blood pressure',
            'heartRateScore'     => 'Heart rate',
            'whrScore'           => 'Waist-to-hip ratio',
            'whtrScore'          => 'Waist-to-height ratio',
            'physicalActivity'   => 'Physical activity',
            'sleepDuration'      => 'Sleep duration',
            'sleepQuality'       => 'Sleep quality',
            'stressLevels'       => 'Stress',
            'socialConnections'  => 'Social connections',
            'dietQuality'        => 'Diet quality',
            'alcoholConsumption' => 'Alcohol',
            'smokingStatus'      => 'Smoking',
            'cognitiveActivity'  => 'Cognitive activity',
            'supplementIntake'   => 'Supplements',
            'sunlightExposure'   => 'Sunlight',
            'dailyHydration'     => 'Hydration',
            'sitToStand'         => 'Sit-to-stand',
            'breathHold'         => 'Breath hold',
            'balance'            => 'Balance',
            'skinElasticity'     => 'Skin elasticity',
        );
        if ( isset( $map[ $key ] ) ) {
            return $map[ $key ];
        }
        $clean  = preg_replace( '/Score$/', '', (string) $key );
        $spaced = preg_replace( '/([a-z])([A-Z])/', '$1 $2', $clean );
        return ucfirst( strtolower( trim( $spaced ) ) );
    }

    private static function format_score_list( $keys ) {
        if ( ! is_array( $keys ) || empty( $keys ) ) {
            return 'None identified';
        }
        $labels = array_map( array( __CLASS__, 'score_key_to_label' ), $keys );
        return implode( ', ', $labels );
    }

    // ──────────────────────────────────────────────────────────────
    //  FINAL REPORT DELIVERED — practitioner confirmation
    //
    //  Fires from HDLV2_Final_Report::send_emails() after the
    //  practitioner clicks Generate Final Report. Replaces the
    //  stage3_draft_ready reuse — that template says "Review Draft
    //  Report" which is the step the practitioner just completed.
    //
    //  Design contract:
    //  - HDL logo (practitioner is inside HDL's system — no client branding)
    //  - "Final Report Delivered" header using Poppins 22px
    //  - Rate / Bio / Chrono stats block (same pattern as final_report_ready_client)
    //  - Strengths + Focus areas, translated from raw score keys to labels
    //  - CTA opens the client-facing final report URL (token auth) so the
    //    practitioner previews exactly what the client sees
    //  - Mentions PDF + Flight Plan delivery so practitioner knows the
    //    downstream fan-out happened
    // ──────────────────────────────────────────────────────────────

    /**
     * @param array $data {
     *     @type string $client_name  Client display name (falls back to email)
     *     @type string $client_email Client email
     *     @type string $rate         Rate of ageing (e.g. "1.17")
     *     @type string $bio_age      Biological age
     *     @type string $age          Chronological age
     *     @type array  $positives    Raw score keys with score >= 4
     *     @type array  $negatives    Raw score keys with score <= 2
     *     @type string $report_url   Client-facing final report URL (token auth)
     * }
     */
    public static function final_report_generated_practitioner( $data ) {
        $cname = esc_html( $data['client_name'] ?: ( $data['client_email'] ?? 'your client' ) );
        $email = esc_html( $data['client_email'] ?? '' );
        // v0.40.16 — Filter-honouring fallback. Default is /clients/ (V2's
        // practitioner dashboard).
        $url   = esc_url( $data['report_url'] ?? home_url( '/' . trim( apply_filters( 'hdlv2_practitioner_dashboard_slug', 'clients' ), '/' ) . '/' ) );

        // A3 (v0.46.30) — notification only. The numbers + strengths/focus
        // lists live in the final report behind the CTA (and the client's PDF),
        // not inlined here. $data rate/bio_age/age/positives/negatives unused.
        $content = "
            <h2 style='margin:0 0 12px;color:" . self::DARK . ";font-family:Poppins,Inter,sans-serif;font-size:22px;font-weight:700;'>Final report delivered to {$cname}</h2>
            <p style='color:" . self::MUTED . ";margin:0 0 24px;font-size:14px;line-height:1.6;'>You&rsquo;ve finalised the report. A branded PDF has been emailed to your client, and their Week 1 Flight Plan is being prepared.</p>
            <p style='color:#444;line-height:1.7;margin:0 0 8px;font-size:14px;'>Open the final report to preview exactly what {$cname} sees:</p>"
            . self::cta_button( $url, 'Open Trajectory Plan' )
            . "<p style='font-size:12px;color:" . self::MUTED . ";line-height:1.5;margin:20px 0 0;text-align:center;'>Delivered to {$email} &middot; Flight Plan follows shortly</p>";

        return self::base_layout( $content, $data['practitioner_id'] ?? null, 'Trajectory Plan Delivered' );
    }

    // ──────────────────────────────────────────────────────────────
    //  FINAL REPORT READY — client notification (HTML; was plain-text)
    // ──────────────────────────────────────────────────────────────

    /**
     * @param array $data { client_name, rate, bio_age, practitioner_id, report_url }
     */
    public static function final_report_ready_client( $data ) {
        $first = self::derive_first_name( $data['client_name'] ?? '', $data['client_email'] ?? '' );
        $url   = esc_url( $data['report_url'] ?: site_url( '/my-flight-plan/' ) );

        // A3 (v0.46.30) — cover note only. The report numbers (rate / bio-age
        // stat table) live in the PDF + the online report behind the CTA, not
        // inlined in the email body. $data['rate']/['bio_age'] intentionally
        // unused now.
        $content = "
            <h2 style='margin:0 0 12px;color:" . self::DARK . ";font-family:Poppins,Inter,sans-serif;font-size:22px;font-weight:700;'>Hi " . esc_html( $first ) . ", your Trajectory Plan is ready</h2>
            <p style='color:#2c3e50;line-height:1.7;margin:0 0 20px;font-size:15px;'>Your personalised longevity report has been finalised by your practitioner. Your PDF copy will arrive in a separate email shortly &mdash; or review your full report online now:</p>"
            . self::cta_button( $url, 'View Your Trajectory Plan' )
            . "<p style='font-size:12px;color:" . self::MUTED . ";line-height:1.5;margin:20px 0 0;text-align:center;'>Questions? Just reply to this email — your practitioner will see it.</p>";

        return self::base_layout( $content, $data['practitioner_id'] ?? null, 'Your Trajectory Plan' );
    }
}
