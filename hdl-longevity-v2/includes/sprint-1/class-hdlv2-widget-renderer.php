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
 * NOT connected to the HDL form/report pipeline. Fully standalone.
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
        $cta_text          = esc_attr( $config['cta_text'] ?? 'Book a session' );
        $cta_link          = esc_url( $config['cta_link'] ?? '#' );
        $theme_color       = esc_attr( $config['theme_color'] ?? '#3d8da0' );

        // The embed is a single <div> with a <script> that builds everything.
        // This approach means one paste = working widget on any platform.
        // Webhook fires server-side on lead capture — not from client JS.
        return sprintf(
            '<div id="hdl-widget-%d" class="hdl-rate-widget" '
            . 'data-practitioner-id="%d" '
            . 'data-practitioner-name="%s" '
            . 'data-logo="%s" '
            . 'data-cta-text="%s" '
            . 'data-cta-link="%s" '
            . 'data-api="%s" '
            . 'data-color="%s">'
            . '</div>'
            . '<script src="%s" defer></script>',
            $practitioner_id,
            $practitioner_id,
            $practitioner_name,
            $logo_url,
            $cta_text,
            $cta_link,
            $api_url,
            $theme_color,
            esc_url( HDLV2_PLUGIN_URL . 'widget/hdl-lead-magnet.js' )
        );
    }
}
