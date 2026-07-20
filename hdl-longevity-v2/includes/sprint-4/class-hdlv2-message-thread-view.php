<?php
/**
 * HDLV2 Message Thread View — the token-mode surface of the client inbox
 * (Slice C, 2026-07-13).
 *
 * WHY THIS RUNS AT init PRIORITY 2, NOT INSIDE THE SHORTCODE:
 * Ultimate Member's GLOBAL access mode (accessible=2) 302-redirects EVERY
 * anonymous page request to /login/ before any shortcode renders, so the
 * emailed scoped link (/my-dashboard/?msg=m1...) could never reach an inbox
 * gated inside [hdlv2_my_dashboard]. (UM's exclude-URI list is no help: UM
 * 2.11.4 compares the full ABSOLUTE URL against the stored entries, which
 * makes exclusions host-specific config per server.) The V2 plugin's proven
 * pattern for beating that wall is the init-priority-1 funnel auto-login in
 * hdl-longevity-v2.php; this class hooks init at priority 2 — after the
 * funnel, before UM can act — and for a valid scoped token renders a
 * THREAD-ONLY page and exits. No UM config, no session created.
 *
 * The scoped token ('m1.' HMAC, ~7-day TTL, minted by the V1 messaging
 * service into the notification email, flag-gated by HDLV2_MSG_LINK_ENABLED)
 * authorises viewing + replying to ONE thread only. Validation is delegated
 * to HDL_Messaging_Service::message_token_scope() — the single source of
 * truth; this class never re-implements the HMAC and fails CLOSED if the V1
 * method is unavailable.
 *
 * Render modes decided by evaluate_request() (pure, fully unit-tested in
 * tests/inbox/):
 *   - anon + valid token       -> standalone thread page (conversation +
 *                                 reply box, nothing else), exit before UM
 *   - anon + invalid/expired   -> graceful "link expired, reply by email"
 *                                 card — NEVER the /login/ dead-end
 *   - logged-in + own/invalid  -> redirect to /my-dashboard/?open_thread=1
 *                                 (the full dashboard has the inbox panel)
 *   - logged-in + valid token
 *     for ANOTHER client       -> thread page scoped to the TOKEN (bearer
 *                                 semantics; the session is untouched)
 *   - flag off / no ?msg /
 *     wrong path               -> pass (request continues untouched, so LIVE
 *                                 behaviour is unchanged until the flag flips)
 *
 * @package HDL_Longevity_V2
 * @since 0.47.67
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class HDLV2_Message_Thread_View {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {}

    public function register_hooks() {
        // Priority 2: after the funnel magic-link auto-login (priority 1) so a
        // link carrying BOTH ?invite and ?msg still logs in first; before UM's
        // the_posts restriction, which never runs because we exit.
        add_action( 'init', array( $this, 'maybe_intercept' ), 2 );
    }

    /**
     * Thin side-effect wrapper — all decisions live in evaluate_request().
     */
    public function maybe_intercept() {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) return;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;
        if ( empty( $_GET['msg'] ) ) return;

        $path = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );

        $session_hash = null;
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user && ! empty( $user->user_email ) ) {
                $session_hash = hash( 'sha256', strtolower( trim( $user->user_email ) ) );
            }
        }

        $flag = defined( 'HDLV2_MSG_LINK_ENABLED' ) && HDLV2_MSG_LINK_ENABLED;
        $r    = self::evaluate_request( $_GET, $path, $session_hash, $flag );

        switch ( $r['action'] ) {
            case 'redirect':
                wp_safe_redirect( $r['url'] );
                exit;
            case 'render_thread':
            case 'render_expired':
                if ( ! headers_sent() ) {
                    foreach ( $r['headers'] as $h ) header( $h );
                }
                echo $r['html'];
                exit;
        }
        // 'pass' -> request continues untouched.
    }

    /**
     * Pure decision + render function. No exit/header/echo side effects so
     * every path is unit-testable (tests/inbox/scenario-thread-view*.php).
     *
     * @param array       $get          Query params ($_GET).
     * @param string      $path         Request path, e.g. '/my-dashboard/'.
     * @param string|null $session_hash sha256 of the logged-in user's email, null when anon.
     * @param bool        $flag_enabled HDLV2_MSG_LINK_ENABLED state.
     * @return array action=pass | redirect(url) | render_thread(html,headers) | render_expired(html,headers)
     */
    public static function evaluate_request( array $get, $path, $session_hash, $flag_enabled ) {
        $pass = array( 'action' => 'pass' );

        if ( ! $flag_enabled ) return $pass;

        $token = isset( $get['msg'] ) && is_string( $get['msg'] ) ? trim( $get['msg'] ) : '';
        if ( $token === '' ) return $pass;

        $slug = self::dashboard_slug();
        if ( rtrim( (string) $path, '/' ) !== '/' . $slug ) return $pass;

        // Single source of truth for the HMAC — V1's static validator. Fail
        // CLOSED (treat as invalid) if the deployed V1 file predates Slice C.
        $scope = false;
        if ( class_exists( 'HDL_Messaging_Service' )
             && method_exists( 'HDL_Messaging_Service', 'message_token_scope' ) ) {
            $scope = HDL_Messaging_Service::message_token_scope( $token );
        }

        if ( $session_hash !== null ) {
            // A logged-in client's own (or a dud) link -> their full dashboard,
            // token dropped from the URL. A VALID token for a DIFFERENT thread
            // is bearer-authoritative: render that one thread, session untouched.
            if ( is_array( $scope ) && ! hash_equals( (string) $scope['client_hash'], (string) $session_hash ) ) {
                return self::render_thread( $scope, $token );
            }
            return array(
                'action' => 'redirect',
                'url'    => home_url( '/' . $slug . '/' ) . '?open_thread=1',
            );
        }

        if ( is_array( $scope ) ) {
            return self::render_thread( $scope, $token );
        }

        return self::render_expired();
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Renderers
    // ──────────────────────────────────────────────────────────────────────

    private static function render_thread( array $scope, $token ) {
        $config = array(
            'mode'           => 'token',
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'token'          => $token,
            'clientHash'     => (string) $scope['client_hash'],
            'practitionerId' => (int) $scope['practitioner_id'],
            'autoOpen'       => true,
        );

        $body  = '<div class="hdlv2-inbox-page">';
        $body .= '<header class="hdlv2-inbox-page-head">';
        $body .= '<span class="hdlv2-inbox-brand">HealthDataLab</span>';
        $body .= '<h1>Your messages</h1>';
        $body .= '<p class="hdlv2-inbox-page-sub">A private conversation between you and your practitioner.</p>';
        $body .= '</header>';
        $body .= self::inbox_shell();
        $body .= '<p class="hdlv2-inbox-page-foot">This link is personal to you &mdash; please don&rsquo;t forward it. You can also reply directly to the email you received.</p>';
        $body .= '</div>';
        // Non-executable JSON config block (CSP-safe: not a script that runs).
        $body .= '<script type="application/json" id="hdlv2-inbox-config">' . wp_json_encode( $config ) . '</script>';
        $body .= '<script src="' . esc_url( HDLV2_PLUGIN_URL . 'assets/js/hdlv2-inbox.js?ver=' . HDLV2_VERSION ) . '"></script>';

        return array(
            'action'  => 'render_thread',
            'html'    => self::page_chrome( 'Your messages', $body ),
            'headers' => self::page_headers(),
        );
    }

    private static function render_expired() {
        $body  = '<div class="hdlv2-inbox-page">';
        $body .= '<header class="hdlv2-inbox-page-head">';
        $body .= '<span class="hdlv2-inbox-brand">HealthDataLab</span>';
        $body .= '<h1>This message link has expired</h1>';
        $body .= '</header>';
        $body .= '<div class="hdlv2-inbox-expired">';
        $body .= '<p>No problem &mdash; nothing is lost. Your practitioner&rsquo;s message is in the email you received: you can <strong>reply directly to that email</strong> and it will reach them.</p>';
        $body .= '<p>If you&rsquo;d like a fresh link to this page, just ask your practitioner to send you a new message.</p>';
        $body .= '</div>';
        $body .= '</div>';

        return array(
            'action'  => 'render_expired',
            'html'    => self::page_chrome( 'Message link expired', $body ),
            'headers' => self::page_headers(),
        );
    }

    /**
     * The shared inbox shell — the SAME markup the dashboard panel embeds
     * (session mode), so hdlv2-inbox.js drives one structure in both modes.
     * Data arrives exclusively via the token/nonce-authorised AJAX calls;
     * nothing personal is baked into this HTML.
     */
    public static function inbox_shell() {
        $h  = '<div class="hdlv2-inbox" data-inbox>';
        $h .= '<div class="hdlv2-inbox-head">';
        $h .= '<h2 class="hdlv2-inbox-title">Messages';
        $h .= ' <span class="hdlv2-inbox-badge" data-inbox-badge hidden>0</span></h2>';
        $h .= '<p class="hdlv2-inbox-sub" data-inbox-prac></p>';
        $h .= '</div>';
        $h .= '<div class="hdlv2-inbox-thread" data-inbox-thread aria-live="polite">';
        $h .= '<p class="hdlv2-inbox-loading">Loading your messages&hellip;</p>';
        $h .= '</div>';
        $h .= '<form class="hdlv2-inbox-reply" data-inbox-reply>';
        $h .= '<label class="hdlv2-inbox-reply-label" for="hdlv2-inbox-text">Reply to your practitioner</label>';
        $h .= '<textarea id="hdlv2-inbox-text" data-inbox-text rows="3" maxlength="5000" placeholder="Write a reply&hellip;"></textarea>';
        $h .= '<div class="hdlv2-inbox-reply-row">';
        $h .= '<span class="hdlv2-inbox-note" data-inbox-note aria-live="polite"></span>';
        $h .= '<button type="submit" class="hdlv2-inbox-send" data-inbox-send>Send</button>';
        $h .= '</div>';
        $h .= '</form>';
        $h .= '</div>';
        return $h;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Page plumbing
    // ──────────────────────────────────────────────────────────────────────

    private static function dashboard_slug() {
        return trim( apply_filters( 'hdlv2_client_dashboard_slug', 'my-dashboard' ), '/' );
    }

    /**
     * Token URLs must never be cached (LSCache on LIVE), indexed, or leak via
     * the Referer header.
     */
    private static function page_headers() {
        return array(
            'Content-Type: text/html; charset=utf-8',
            'Cache-Control: no-store, no-cache, must-revalidate, max-age=0',
            'X-LiteSpeed-Cache-Control: no-cache',
            'Referrer-Policy: no-referrer',
            'X-Robots-Tag: noindex, nofollow',
        );
    }

    /**
     * Minimal self-contained page — we render before the theme loads, so no
     * Divi chrome. Styles come from the same versioned dashboard stylesheet
     * the inbox panel uses (no inline <style>, no external hosts — CSP-safe).
     */
    private static function page_chrome( $title, $body_html ) {
        $css = esc_url( HDLV2_PLUGIN_URL . 'assets/css/hdlv2-client-dashboard.css?ver=' . HDLV2_VERSION );

        $h  = '<!DOCTYPE html><html lang="en"><head>';
        $h .= '<meta charset="utf-8">';
        $h .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $h .= '<meta name="referrer" content="no-referrer">';
        $h .= '<meta name="robots" content="noindex, nofollow">';
        $h .= '<title>' . esc_html( $title ) . ' &mdash; HealthDataLab</title>';
        $h .= '<link rel="stylesheet" href="' . $css . '">';
        $h .= '</head><body class="hdlv2-inbox-standalone">';
        $h .= $body_html;
        $h .= '</body></html>';
        return $h;
    }
}
