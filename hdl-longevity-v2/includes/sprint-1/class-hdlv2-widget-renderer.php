<?php
/**
 * Lead Magnet Widget — Embed Code Renderer.
 *
 * Generates the self-contained HTML/JS/CSS embed that practitioners paste
 * onto their own websites. The widget:
 *   1. Collects basic data (name, email, age, height, weight, waist, activity)
 *   2. Calculates rate of ageing client-side (simplified algorithm)
 *   3. Renders an SVG speedometer showing the result
 *   4. POSTs contact details to HDL REST API (and optionally a practitioner webhook)
 *
 * Wired into the HDL pipeline: submissions flow through complete_signup()
 * into form_progress + the Stage-1 emails (E4 — the old "NOT connected /
 * fully standalone" header predated that wiring and was wrong).
 *
 * @package HDL_Longevity_V2
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Widget_Renderer {

    /**
     * Generate the full embed code for a practitioner.
     *
     * @param int   $practitioner_id Practitioner user ID.
     * @param array $config          Widget config (name, logo, cta, etc.).
     * @return string HTML embed code.
     */
    public static function generate_embed_code( $practitioner_id, $config ) {
        $api_url           = rest_url( 'hdl-v2/v1/widget/lead' );
        $practitioner_name = esc_attr( $config['practitioner_name'] ?? '' );
        $logo_url          = esc_url( $config['logo_url'] ?? '' );
        $logo_shape        = esc_attr( $config['logo_shape'] ?? 'square' );
        $cta_text          = esc_attr( $config['cta_text'] ?? 'Book a session' );
        $cta_link          = esc_url( $config['cta_link'] ?? '#' );
        $theme_color       = esc_attr( $config['theme_color'] ?? '#3d8da0' );

        // v0.35.0 (Phase O) — script URL gets ?ver=HDLV2_VERSION cache-buster
        // so when we ship a new widget JS version (the public-path thank-you
        // wall, the public-config fetch, etc.), browsers on practitioner host
        // sites pick up the new file on the next page load instead of serving
        // the stale cached copy keyed on the bare URL. Existing embeds without
        // the ?ver= keep working — the new JS still falls back to data-*
        // attributes if the public-config fetch fails — but they may serve
        // cached code until cache headers expire or the visitor force-
        // refreshes. New copy-paste of the embed picks up the buster.
        $script_url = HDLV2_PLUGIN_URL . 'widget/hdl-lead-magnet.js?ver=' . rawurlencode( defined( 'HDLV2_VERSION' ) ? HDLV2_VERSION : '0' );

        // The embed is a single <div> with a <script> that builds everything.
        // This approach means one paste = working widget on any platform.
        // Webhook fires server-side on lead capture — not from client JS.
        //
        // v0.40.12:
        //   • Outer div carries style="max-width:480px;margin:0 auto;" so the
        //     card centres correctly inside any host column without relying
        //     on the inner .hdlw-shell max-width alone (some host themes
        //     wrap content in a narrower column than 480px).
        //   • <noscript> fallback gives no-JS visitors + SEO crawlers a
        //     meaningful message instead of a blank rectangle. Minimal
        //     inline styling — matches the cream-card aesthetic of the
        //     real widget without pulling any external CSS.
        return sprintf(
            '<div id="hdl-widget-%d" class="hdl-rate-widget" '
            . 'style="max-width:480px;margin:0 auto;" '
            . 'data-practitioner-id="%d" '
            . 'data-practitioner-name="%s" '
            . 'data-logo="%s" '
            . 'data-logo-shape="%s" '
            . 'data-cta-text="%s" '
            . 'data-cta-link="%s" '
            . 'data-api="%s" '
            . 'data-color="%s">'
            . '</div>'
            . '<noscript>'
            . '<div style="max-width:480px;margin:0 auto;padding:24px;text-align:center;'
            . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;'
            . 'font-size:14px;line-height:1.55;color:#374151;background:#faf8f5;'
            . 'border:1px solid #e4e6ea;border-radius:4px;">'
            . 'This free health assessment needs JavaScript to load. '
            . 'Please enable JavaScript in your browser and refresh the page.'
            . '</div>'
            . '</noscript>'
            . '<script src="%s" defer></script>',
            $practitioner_id,
            $practitioner_id,
            $practitioner_name,
            $logo_url,
            $logo_shape,
            $cta_text,
            $cta_link,
            $api_url,
            $theme_color,
            esc_url( $script_url )
        );
    }
}
