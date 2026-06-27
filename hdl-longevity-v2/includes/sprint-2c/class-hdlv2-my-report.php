<?php
/**
 * HDL V2 — My Report resolver.
 *
 * Renders on the /my-report/ page via [hdlv2_my_report]. Looks up the
 * logged-in client's latest hdlv2_form_progress.token and redirects to
 * /longevity-draft-report/?t=<token>. This lets a single static menu
 * item serve every client (menu hrefs can't interpolate per-user tokens).
 *
 * Roles handled:
 *   - um_consumer / um_annual_subscriber / um_sprout / um_client /
 *     um_practitioner-invite → redirect to their report if token exists
 *   - administrator / um_practitioner → defensive message (menu should hide
 *     this item for them, but we don't assume)
 *   - logged-out → log-in prompt
 *
 * @package HDL_Longevity_V2
 * @since 0.21.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_My_Report {

    public function register_hooks() {
        add_shortcode( 'hdlv2_my_report', array( $this, 'render_shortcode' ) );
        // Redirect BEFORE output so there's no "headers already sent" risk.
        add_action( 'template_redirect', array( $this, 'maybe_redirect' ) );
    }

    /**
     * Fires on template_redirect. If the current user is a client with a
     * resolvable report token, send them to the canonical report page.
     */
    public function maybe_redirect() {
        if ( is_admin() || wp_doing_ajax() ) {
            return;
        }
        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'hdlv2_my_report' ) ) {
            return;
        }
        if ( ! is_user_logged_in() ) {
            return; // shortcode renders login prompt
        }
        if ( $this->is_practitioner_or_admin() ) {
            return; // shortcode renders defensive message
        }

        $token = $this->get_user_token( get_current_user_id() );
        if ( ! $token ) {
            return; // shortcode renders "complete your assessment" prompt
        }

        $slug   = apply_filters( 'hdlv2_final_report_slug', 'longevity-draft-report' );
        $target = home_url( '/' . trim( $slug, '/' ) . '/?t=' . rawurlencode( $token ) );
        wp_safe_redirect( $target, 302 );
        exit;
    }

    public function render_shortcode( $atts ) {
        if ( ! is_user_logged_in() ) {
            return $this->card(
                'Log in to see your report',
                'Your longevity report is ready once you sign in.',
                'Log in',
                wp_login_url( home_url( '/my-report/' ) )
            );
        }
        if ( $this->is_practitioner_or_admin() ) {
            return $this->card(
                'This page is for clients',
                'As a practitioner, use the Clients dashboard to view your clients\' reports.',
                'Go to Clients',
                home_url( '/clients/' )
            );
        }
        $token = $this->get_user_token( get_current_user_id() );
        if ( ! $token ) {
            // v0.46.23 — Route to the state-aware client dashboard, NOT the old
            // V1 longevity form. The dashboard shows the right next step for
            // their stage (the V2 widget "Start now" for brand-new clients,
            // carrying their invite token; a waiting message otherwise).
            return $this->card(
                'No report yet',
                'Your personalised report appears here once your assessment is complete. Head to your dashboard for your next step.',
                'Go to my dashboard',
                home_url( '/my-dashboard/' )
            );
        }
        // Redirect should already have fired at template_redirect. This is a
        // race-condition fallback (e.g. caching plugins that bypass the hook).
        $slug   = apply_filters( 'hdlv2_final_report_slug', 'longevity-draft-report' );
        $target = home_url( '/' . trim( $slug, '/' ) . '/?t=' . rawurlencode( $token ) );
        return '<div style="max-width:560px;margin:60px auto;padding:40px 32px;text-align:center;font-family:Inter,-apple-system,sans-serif;">'
             . '<p style="color:#666;">Loading your report…</p>'
             . '<p><a href="' . esc_url( $target ) . '" style="color:#3d8da0;font-weight:500;">Click here if you are not redirected</a></p>'
             . '</div>';
    }

    private function is_practitioner_or_admin() {
        $user = wp_get_current_user();
        if ( ! $user || empty( $user->ID ) ) {
            return false;
        }
        $roles = (array) $user->roles;
        if ( in_array( 'administrator', $roles, true ) ) {
            return true;
        }
        if ( in_array( 'um_practitioner', $roles, true ) ) {
            return true;
        }
        // Also honour the compatibility helper if available
        if ( class_exists( 'HDLV2_Compatibility' ) && method_exists( 'HDLV2_Compatibility', 'is_practitioner' ) ) {
            return HDLV2_Compatibility::is_practitioner( $user->ID );
        }
        return false;
    }

    private function get_user_token( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return null;
        }
        global $wpdb;
        // v0.41.17 — `AND deleted_at IS NULL`. Soft-deleted clients whose
        // browser session is still alive must not be able to navigate to
        // their archived final report via /my-report/.
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT token FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE client_user_id = %d AND token IS NOT NULL AND token != ''
               AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1",
            $user_id
        ) );
    }

    private function card( $title, $body, $cta_label, $cta_url ) {
        return '<div style="max-width:560px;margin:60px auto;padding:40px 32px;background:#fff;border:1px solid #e4e6ea;border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,0.04);text-align:center;font-family:Inter,-apple-system,sans-serif;">'
             . '<h2 style="font-family:Poppins,Inter,sans-serif;font-size:22px;font-weight:600;color:#111;margin:0 0 10px;">' . esc_html( $title ) . '</h2>'
             . '<p style="font-size:14px;color:#666;margin:0 0 24px;line-height:1.5;">' . esc_html( $body ) . '</p>'
             . '<a href="' . esc_url( $cta_url ) . '" style="display:inline-block;background:#3d8da0;color:#fff;padding:11px 24px;border-radius:48px;font-size:14px;font-weight:600;text-decoration:none;font-family:Poppins,Inter,sans-serif;">' . esc_html( $cta_label ) . ' &rarr;</a>'
             . '</div>';
    }
}
