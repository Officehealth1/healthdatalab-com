<?php
/**
 * Lead Magnet Widget — Configuration, Invites & Lead Capture.
 *
 * Manages per-practitioner widget settings, invite tokens for direct
 * client links, and lead capture from both public embeds and invites.
 *
 * @package HDL_Longevity_V2
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Widget_Config {

    /**
     * Register all hooks for widget config + invites.
     */
    public function register_hooks() {
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_filter( 'rest_pre_serve_request', array( $this, 'add_lead_cors_headers' ), 15, 4 );

        // AJAX — config
        add_action( 'wp_ajax_hdlv2_save_widget_config', array( $this, 'ajax_save_config' ) );
        add_action( 'wp_ajax_hdlv2_get_widget_config', array( $this, 'ajax_get_config' ) );

        // AJAX — invites
        add_action( 'wp_ajax_hdlv2_create_invite', array( $this, 'ajax_create_invite' ) );
        add_action( 'wp_ajax_hdlv2_get_invites', array( $this, 'ajax_get_invites' ) );
        // v0.29.0 — debounced lookup so the Send-Invites form can show a
        // "found prior submission" hint before the practitioner clicks
        // Generate Link, surfacing the email-match contract that drives the
        // pre-fill flow. Practitioner-auth, nonce-gated.
        add_action( 'wp_ajax_hdlv2_widget_lead_lookup', array( $this, 'ajax_lead_lookup' ) );

        // AJAX — logo upload
        add_action( 'wp_ajax_hdlv2_upload_logo', array( $this, 'ajax_upload_logo' ) );

        // AJAX — V2 client list + WHY gate release
        add_action( 'wp_ajax_hdlv2_get_v2_clients', array( $this, 'ajax_get_v2_clients' ) );
        add_action( 'wp_ajax_hdlv2_release_why', array( $this, 'ajax_release_why' ) );

        // Cron — daily cleanup of stale pending leads + 24h reminder
        add_action( 'hdlv2_pending_leads_cleanup', array( $this, 'cron_cleanup_pending' ) );

        // Auto-seed widget_config when a user is promoted to um_practitioner.
        // Backfill for existing practitioners runs once via the activator's
        // Phase E migration (DB v2.2).
        add_action( 'set_user_role', array( __CLASS__, 'on_set_user_role' ), 10, 3 );

        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_dashboard_js' ) );
        add_shortcode( 'hdlv2_widget', array( $this, 'render_shortcode' ) );
    }

    /**
     * Ensure a widget_config row exists for the given user. Idempotent.
     *
     * Called from two places: the activator's Phase E backfill (existing
     * practitioners on plugin upgrade) and the set_user_role hook (new
     * practitioners). Returns true on actual insert, false on no-op.
     */
    public static function ensure_widget_config_for_user( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return false;
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_widget_config';

        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE practitioner_user_id = %d LIMIT 1",
            $user_id
        ) );
        if ( $exists ) {
            return false;
        }

        $name = $user->display_name ?: $user->user_login;
        $wpdb->insert( $table, array(
            'practitioner_user_id' => $user_id,
            'practitioner_name'    => $name,
            'notification_email'   => $user->user_email,
        ) );

        return (bool) $wpdb->insert_id;
    }

    /**
     * Hook: when a user's role becomes um_practitioner, seed their widget_config row.
     */
    public static function on_set_user_role( $user_id, $role, $old_roles ) {
        if ( $role !== 'um_practitioner' ) {
            return;
        }
        self::ensure_widget_config_for_user( $user_id );
    }

    // ──────────────────────────────────────────────────────────────
    //  CORS
    // ──────────────────────────────────────────────────────────────

    public function add_lead_cors_headers( $served, $result, $request, $server ) {
        $route = $request->get_route();
        // Cross-origin endpoints called from practitioner-hosted widgets.
        $cors_routes = array(
            '/hdl-v2/v1/widget/lead',
            '/hdl-v2/v1/widget/verify-invite',
            '/hdl-v2/v1/widget/resend',
            // v0.35.0 (Phase O) — public widget pulls fresh display config
            // from this endpoint on every mount. Without CORS the cross-
            // origin fetch from the practitioner's host site is blocked,
            // the 1.5s safety timer kicks in, and the widget falls back to
            // the embed's data-* attributes — defeating the set-and-forget
            // promise. Read-only safe-subset response; CORS '*' is fine.
            '/hdl-v2/v1/widget/public-config',
        );
        if ( in_array( $route, $cors_routes, true ) ) {
            header( 'Access-Control-Allow-Origin: *' );
            header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Content-Type' );
            header( 'Access-Control-Max-Age: 86400' );
            header_remove( 'Access-Control-Allow-Credentials' );
        }
        return $served;
    }

    // ──────────────────────────────────────────────────────────────
    //  SHORTCODE
    // ──────────────────────────────────────────────────────────────

    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'practitioner_id' => get_current_user_id(),
        ), $atts, 'hdlv2_widget' );

        $user_id = absint( $atts['practitioner_id'] );
        $config  = $this->get_config( $user_id );

        $practitioner_name = $config ? $config->practitioner_name : '';
        $logo_url          = $config ? $config->logo_url : '';
        $logo_shape        = $config && ! empty( $config->logo_shape ) ? $config->logo_shape : 'square';
        $cta_text          = $config ? $config->cta_text : 'Book a session';
        $cta_link          = $config ? $config->cta_link : '';
        $theme_color       = $config ? $config->theme_color : '#3d8da0';

        wp_enqueue_script(
            'hdlv2-widget-shortcode',
            HDLV2_PLUGIN_URL . 'widget/hdl-lead-magnet.js',
            array(),
            HDLV2_VERSION . '.' . filemtime( HDLV2_PLUGIN_DIR . 'widget/hdl-lead-magnet.js' ),
            true
        );

        $api_url = rest_url( 'hdl-v2/v1/widget/lead' );

        return sprintf(
            '<div class="hdl-rate-widget" '
            . 'data-practitioner-id="%d" '
            . 'data-practitioner-name="%s" '
            . 'data-logo="%s" '
            . 'data-logo-shape="%s" '
            . 'data-cta-text="%s" '
            . 'data-cta-link="%s" '
            . 'data-api="%s" '
            . 'data-verify-api="%s" '
            . 'data-color="%s">'
            . '</div>',
            $user_id,
            esc_attr( $practitioner_name ),
            esc_url( $logo_url ),
            esc_attr( $logo_shape ),
            esc_attr( $cta_text ),
            esc_url( $cta_link ),
            esc_url( $api_url ),
            esc_url( rest_url( 'hdl-v2/v1/widget/verify-invite' ) ),
            esc_attr( $theme_color )
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  DASHBOARD JS
    // ──────────────────────────────────────────────────────────────

    public function maybe_enqueue_dashboard_js() {
        $user_id = get_current_user_id();
        if ( ! $user_id || ! HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            return;
        }

        wp_enqueue_script(
            'hdlv2-dashboard',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-dashboard.js',
            array(),
            HDLV2_VERSION,
            true
        );

        wp_enqueue_script(
            'hdlv2-widget',
            HDLV2_PLUGIN_URL . 'widget/hdl-lead-magnet.js',
            array(),
            HDLV2_VERSION,
            true
        );

        $config = $this->get_config( $user_id );
        $config_arr = $config ? array(
            'practitioner_name'             => $config->practitioner_name,
            'logo_url'                      => $config->logo_url,
            'logo_shape'                    => ! empty( $config->logo_shape ) ? $config->logo_shape : 'square',
            'cta_text'                      => $config->cta_text,
            'cta_link'                      => $config->cta_link,
            'webhook_url'                   => $config->webhook_url,
            'notification_email'            => $config->notification_email,
            'theme_color'                   => $config->theme_color,
            'show_book_button_after_widget' => ! empty( $config->show_book_button_after_widget ),
        ) : array();

        wp_localize_script( 'hdlv2-dashboard', 'hdlv2_dashboard', array(
            'ajax_url'        => admin_url( 'admin-ajax.php' ),
            'rest_base'       => rest_url(),
            'nonce'           => wp_create_nonce( 'hdlv2_widget_config' ),
            // v0.35.0 (Phase O) — proper REST nonce for X-WP-Nonce header.
            // The pre-existing AJAX nonce above was being mis-passed as a
            // REST nonce on /dashboard/clients (the .catch() swallowed the
            // 403); the new Pending Leads inbox needs auth to succeed
            // reliably for Confirm/Reject, so we expose the right one.
            'rest_nonce'      => wp_create_nonce( 'wp_rest' ),
            'v1_nonce'        => wp_create_nonce( 'health_tracker_nonce' ),
            'practitioner_id' => $user_id,
            'widget_js_url'   => HDLV2_PLUGIN_URL . 'widget/hdl-lead-magnet.js',
            'widget_page_url' => site_url( '/rate-of-ageing-widget/' ),
            // v0.36.6 — clients_url drives the booking-link banner CTA's
            // fallback redirect when the practitioner clicks "Open Widget
            // Settings →" from a page that doesn't host the V1 invite-client
            // popup (e.g. the homepage on LIVE post-deploy 2026-05-10).
            // Without this, the CTA's click handler had no popup/button to
            // attach to and silently no-op'd → dead button reported by
            // Quim. Filterable so any rename of the V1 clients page slug
            // doesn't break the deeplink.
            'clients_url'     => apply_filters( 'hdlv2_clients_dashboard_url', site_url( '/clients/' ) ),
            'config'          => $config_arr,
            'embed_code'      => $config ? HDLV2_Widget_Renderer::generate_embed_code( $user_id, $config_arr ) : '',
        ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST ROUTES
    // ──────────────────────────────────────────────────────────────

    public function register_rest_routes() {
        // Config CRUD (practitioner auth)
        register_rest_route( 'hdl-v2/v1', '/widget/config', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_config' ),
                'permission_callback' => array( $this, 'check_practitioner_permission' ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_save_config' ),
                'permission_callback' => array( $this, 'check_practitioner_permission' ),
            ),
        ) );

        // Lead capture (public)
        register_rest_route( 'hdl-v2/v1', '/widget/lead', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_capture_lead' ),
            'permission_callback' => '__return_true',
        ) );

        // Create invite (practitioner auth)
        register_rest_route( 'hdl-v2/v1', '/widget/invite', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_create_invite' ),
            'permission_callback' => array( $this, 'check_practitioner_permission' ),
        ) );

        // Verify invite (public — called by widget JS)
        register_rest_route( 'hdl-v2/v1', '/widget/verify-invite', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_verify_invite' ),
            'permission_callback' => '__return_true',
        ) );

        // List invites (practitioner auth)
        register_rest_route( 'hdl-v2/v1', '/widget/invites', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_list_invites' ),
            'permission_callback' => array( $this, 'check_practitioner_permission' ),
        ) );

        // Resend the verification email for an existing pending lead.
        // Public + rate-limited via TIER_PUBLIC in rate-limit-policy.
        register_rest_route( 'hdl-v2/v1', '/widget/resend', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_resend_verification' ),
            'permission_callback' => '__return_true',
        ) );

        // ── v0.35.0 (Phase O) Practitioner-Confirm Widget Flow ───────
        // Pending Leads inbox feed (practitioner auth)
        register_rest_route( 'hdl-v2/v1', '/widget/leads/pending', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_list_pending_leads' ),
            'permission_callback' => array( $this, 'check_practitioner_permission' ),
        ) );

        // Confirm a pending lead → magic link to client (practitioner auth)
        register_rest_route( 'hdl-v2/v1', '/widget/leads/confirm', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_confirm_lead' ),
            'permission_callback' => array( $this, 'check_practitioner_permission' ),
        ) );

        // Reject a pending lead → silent shadow-ban (practitioner auth)
        register_rest_route( 'hdl-v2/v1', '/widget/leads/reject', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_reject_lead' ),
            'permission_callback' => array( $this, 'check_practitioner_permission' ),
        ) );

        // Public widget config — read-only safe subset for embedded widgets.
        // Replaces baked-in data-cta-link / data-theme-color so the embed
        // code becomes set-and-forget. Practitioner updates Widget Settings,
        // every embed picks up the new value on next page load.
        register_rest_route( 'hdl-v2/v1', '/widget/public-config', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_get_public_config' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public function check_practitioner_permission() {
        return HDLV2_Compatibility::is_practitioner( get_current_user_id() );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: CONFIG
    // ──────────────────────────────────────────────────────────────

    public function rest_get_config( $request ) {
        $config = $this->get_config( get_current_user_id() );
        return rest_ensure_response( $config ?: array() );
    }

    public function rest_save_config( $request ) {
        $user_id = get_current_user_id();
        $data    = $this->sanitize_config( $request->get_json_params() );

        // v0.29.0 — parity with ajax_save_config(): the public widget result
        // page now demotes Continue-to-Stage-2 to the invite path only, so
        // Book-a-Session is the only post-result CTA on the public page —
        // a missing booking link would leave the result page with no CTA at
        // all. Same enforcement on REST as the AJAX admin form.
        if ( empty( $data['cta_link'] ) ) {
            return new WP_Error( 'cta_link_required', 'Booking Link is required.', array( 'status' => 400 ) );
        }
        // v0.29.1 — the practitioner needs to know about incoming leads.
        if ( empty( $data['notification_email'] ) || ! is_email( $data['notification_email'] ) ) {
            return new WP_Error( 'notification_email_required', 'A valid lead notification email is required.', array( 'status' => 400 ) );
        }

        $result  = $this->save_config( $user_id, $data );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array(
            'success'    => true,
            'embed_code' => HDLV2_Widget_Renderer::generate_embed_code( $user_id, $data ),
        ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: LEAD CAPTURE (with invite completion + email dedup)
    // ──────────────────────────────────────────────────────────────

    /**
     * Lead capture — Phase 1 of the verification flow.
     *
     * Visitor fills the widget on practitioner site → this endpoint:
     *   1. Validates submission (format, MX, honeypot, per-recipient cooldown)
     *   2. Stores a row in wp_hdlv2_pending_leads — NO real account created
     *   3. Sends a verification email with a unique 64-hex token link
     *   4. Returns the (server-calculated) rate so the widget can show it instantly
     *
     * Account creation, practitioner notification, Make.com Stage 1 PDF webhook,
     * and the widget_leads tracking row only fire once the visitor clicks the
     * verification link — see ::handle_verification_click().
     *
     * Backwards-compatible response shape: { success, rate, pending: true }.
     * The widget JS (post-verification update) reads `pending` to switch
     * the on-screen "Continue" button into a "check your email" card.
     */
    public function rest_capture_lead( $request ) {
        $params = $request->get_json_params();

        // ── Honeypot — bots fill every field; silently accept and discard. ──
        // Tier-1 bot defense; real users never see/touch the 'website' field.
        if ( ! empty( $params['website'] ) ) {
            return rest_ensure_response( array( 'success' => true, 'pending' => true ) );
        }

        $practitioner_id = isset( $params['practitioner_id'] ) ? absint( $params['practitioner_id'] ) : 0;
        if ( ! $practitioner_id ) {
            return new WP_Error( 'missing_practitioner', 'Practitioner ID is required.', array( 'status' => 400 ) );
        }

        // Validate practitioner_id exists in widget_config — generic 400 so we
        // don't leak which IDs are real (token-enumeration defense).
        $config = $this->get_config( $practitioner_id );
        if ( ! $config ) {
            return new WP_Error( 'invalid_practitioner', 'Submission could not be processed.', array( 'status' => 400 ) );
        }

        // ── Per-IP cap (legacy backstop, lighter than the rate-limit middleware) ──
        $ip        = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
        $transient = 'hdlv2_lead_' . md5( $ip );
        $count     = (int) get_transient( $transient );
        if ( $count >= 10 ) {
            return new WP_Error( 'rate_limited', 'Too many submissions. Please try again later.', array( 'status' => 429 ) );
        }
        set_transient( $transient, $count + 1, HOUR_IN_SECONDS );

        // Optional invite-token completion — preserves the practitioner-issued
        // direct-link flow. Invite tokens are pre-trusted (the practitioner
        // explicitly created them for a known client) so they SKIP the
        // verification step and fall through to the legacy immediate flow.
        $invite_id    = null;
        $invite_token = isset( $params['invite_token'] ) ? sanitize_text_field( $params['invite_token'] ) : '';
        if ( $invite_token ) {
            $invite = $this->get_valid_invite( $invite_token );
            if ( $invite ) {
                $invite_id = (int) $invite->id;
                $this->complete_invite( $invite_id );
            }
        }

        // Extract lead fields (V2: 9-question format)
        $visitor_name  = sanitize_text_field( $params['name'] ?? '' );
        $visitor_email = sanitize_email( $params['email'] ?? '' );
        $visitor_phone = sanitize_text_field( $params['phone'] ?? '' );
        $visitor_age   = isset( $params['q1_age'] ) ? absint( $params['q1_age'] ) : null;
        $rate          = isset( $params['rate_of_ageing_result'] ) ? floatval( $params['rate_of_ageing_result'] ) : null;

        // ── Email validation — format + MX record (catches typos + dead domains). ──
        if ( ! $visitor_email || ! is_email( $visitor_email ) ) {
            return new WP_Error( 'invalid_email', 'Please enter a valid email address.', array( 'status' => 400 ) );
        }
        $email_check = self::validate_email_deliverable( $visitor_email );
        if ( is_wp_error( $email_check ) ) {
            return $email_check;
        }

        // Build Stage 1 answers from widget payload
        $stage1_data = array();
        foreach ( array( 'q1_age', 'q1_sex', 'q2a', 'q2b', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8', 'q9' ) as $field ) {
            if ( isset( $params[ $field ] ) ) {
                $stage1_data[ $field ] = sanitize_text_field( $params[ $field ] );
            }
        }
        if ( isset( $stage1_data['q1_age'] ) ) {
            $stage1_data['q1_age'] = absint( $stage1_data['q1_age'] );
        }
        if ( isset( $stage1_data['q2a'] ) ) {
            $stage1_data['q2a'] = absint( $stage1_data['q2a'] );
        }

        // Server-side calculation (validate widget result)
        $server_result = null;
        if ( ! empty( $stage1_data['q1_age'] ) && ! empty( $stage1_data['q9'] ) ) {
            $server_result = HDLV2_Rate_Calculator::calculate_quick( $stage1_data );
            $stage1_data['server_result'] = $server_result;
            $rate = $server_result['rate'];
        }

        // v0.29.1 — Submit dedupe. A double-click on "See My Results" or a
        // flaky network → retry would otherwise fire the practitioner-notify
        // email and Make.com Stage 1 PDF webhook twice. record_widget_lead()
        // already upserts the row, but the dispatch helper isn't idempotent.
        // 60s window keyed on (practitioner, email[, invite]); cached payload
        // returned verbatim on repeat. Belt-and-braces: the widget JS also
        // disables the submit button on click.
        $dedupe_key = 'hdlv2_lead_dedup_' . md5( $practitioner_id . '|' . $visitor_email . '|' . ( $invite_id ?: 'public' ) );
        $cached     = get_transient( $dedupe_key );
        if ( is_array( $cached ) ) {
            return rest_ensure_response( $cached );
        }

        // ── INVITE-TOKEN FAST PATH ──
        // Practitioner-issued invite tokens are pre-trusted (the practitioner
        // explicitly created them for a known client). They bypass the
        // verification cycle and create the user/form_progress immediately.
        if ( $invite_id ) {
            $form_token = self::complete_signup( array(
                'practitioner_id' => $practitioner_id,
                'visitor_name'    => $visitor_name,
                'visitor_email'   => $visitor_email,
                'visitor_phone'   => $visitor_phone,
                'visitor_age'     => $visitor_age,
                'rate'            => $rate,
                'stage1_data'     => $stage1_data,
                'invite_id'       => $invite_id,
                'config'          => $config,
                'send_practitioner_notify' => true,
                'send_make_pdf'   => true,
            ) );

            $invite_response = array(
                'success'    => true,
                'form_token' => $form_token,
                'rate'       => $rate,
            );
            set_transient( $dedupe_key, $invite_response, 60 );
            return rest_ensure_response( $invite_response );
        }

        // ── PUBLIC PATH (no invite token) — v0.35.0 (Phase O, revised) ──
        // Lead capture lands in the practitioner's Pending Leads inbox.
        // status='pending', stage1_data persisted (no TTL).
        //
        // Both the Make.com Stage 1 PDF webhook AND the practitioner notify
        // email fire NOW. Per Quim 2026-05-07: the client should still
        // receive their PDF + gauge summary at submission, AND the
        // practitioner should still receive their copy. The "spam-resistant"
        // gate is content-only: the Make.com Stage 1 client email no longer
        // includes the "Continue to the full assessment" button. The
        // visitor reads "your practitioner is reviewing your snapshot" and
        // waits for the magic-link email that fires only when the
        // practitioner explicitly clicks Confirm.
        //
        // The form_token passed to Make.com here is a synthetic cache key
        // (deterministic hash of practitioner_id|email) so the PDFMonkey
        // gauge image filename is stable across re-submissions and
        // legitimate workflows. It's never used as an auth token because
        // there's no form_progress row keyed on it — the post-Confirm magic
        // link uses a real token instead.
        self::record_widget_lead( array(
            'practitioner_id' => $practitioner_id,
            'visitor_name'    => $visitor_name,
            'visitor_email'   => $visitor_email,
            'visitor_age'     => $visitor_age,
            'rate'            => $rate,
            'stage1_data'     => $stage1_data,
        ) );

        // Synthetic 64-hex cache key for prerender_gauge_png() filename only.
        // Stable across repeat submissions for the same (practitioner, email)
        // so the gauge PNG cache hits on resubmission. Never used as an auth
        // token, never exposed in URLs, never stored.
        $cache_token = bin2hex( hash( 'sha256', $practitioner_id . '|' . $visitor_email, true ) );

        self::dispatch_post_signup_artifacts( array(
            'practitioner_id'           => $practitioner_id,
            'visitor_name'              => $visitor_name,
            'visitor_email'             => $visitor_email,
            'visitor_phone'             => $visitor_phone,
            'visitor_age'               => $visitor_age,
            'rate'                      => $rate,
            'stage1_data'               => $stage1_data,
            'form_token'                => $cache_token,
            'config'                    => $config,
            'invite_id'                 => null,
            'send_practitioner_notify'  => true,
            'send_make_pdf'             => true,
        ) );

        $public_response = array(
            'success' => true,
            'rate'    => $rate,
        );
        set_transient( $dedupe_key, $public_response, 60 );
        return rest_ensure_response( $public_response );
    }

    /**
     * Upsert a widget_leads row keyed on (practitioner_user_id, visitor_email).
     *
     * Public-path widget submissions write here every time (since v0.29.0).
     * Used as the prefill source when a practitioner subsequently issues a
     * Send-Invites link for the same email — see lookup_widget_lead().
     *
     * v0.35.0 (Phase O) status semantics:
     *   - New row INSERT: explicit status='pending' so the row appears in the
     *     practitioner's Pending Leads inbox.
     *   - Existing row UPDATE: status is INTENTIONALLY OMITTED from the
     *     update set so a re-submission for an already-confirmed or
     *     already-rejected email does NOT re-queue the row in the inbox.
     *     The latest 9 answers and updated created_at are still persisted
     *     so the prefill source stays fresh, but the practitioner's
     *     decision sticks.
     *
     * @param array $args { practitioner_id, visitor_name, visitor_email,
     *                     visitor_age, rate, stage1_data }
     * @return int Inserted or updated row id (0 on hard failure).
     */
    private static function record_widget_lead( $args ) {
        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_widget_leads';

        $practitioner_id = (int) $args['practitioner_id'];
        $visitor_email   = (string) $args['visitor_email'];

        // Common columns — written by both INSERT and UPDATE.
        $common = array(
            'visitor_name'         => (string) ( $args['visitor_name'] ?? '' ),
            'visitor_age'          => isset( $args['visitor_age'] ) ? (int) $args['visitor_age'] : null,
            'rate_of_ageing'       => isset( $args['rate'] ) ? (float) $args['rate'] : null,
            'stage1_data'          => wp_json_encode( $args['stage1_data'] ?? array() ),
        );

        $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE practitioner_user_id = %d AND visitor_email = %s ORDER BY id DESC LIMIT 1",
            $practitioner_id, $visitor_email
        ) );

        if ( $existing_id ) {
            // UPDATE — bump created_at so the inbox sort surfaces the latest
            // re-submission, but DO NOT touch status. A confirmed or rejected
            // row stays in its terminal state on resubmission.
            $update_row = $common;
            $update_row['created_at'] = current_time( 'mysql' );
            $wpdb->update( $table, $update_row, array( 'id' => $existing_id ) );
            return $existing_id;
        }

        // INSERT — new lead. Explicit status='pending' (also the schema
        // default, but spelled out so the intent is locally readable).
        $insert_row = $common + array(
            'practitioner_user_id' => $practitioner_id,
            'visitor_email'        => $visitor_email,
            'status'               => 'pending',
        );
        $wpdb->insert( $table, $insert_row );
        return (int) $wpdb->insert_id;
    }

    /**
     * Look up the latest widget_leads row for (practitioner_id, email).
     * Returns the row object (with stage1_data still as a JSON string) or null.
     *
     * Used by:
     *   - rest_create_invite() / ajax_create_invite() — to snapshot stage1_data
     *     into widget_invites.prefill_stage1 at invite-creation time.
     *   - ajax_lead_lookup() — to surface a "match found" indicator in the
     *     practitioner's Send-Invites form before they generate the link.
     */
    private static function lookup_widget_lead( $practitioner_id, $email ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, visitor_name, visitor_email, visitor_age, rate_of_ageing, stage1_data, created_at
             FROM {$wpdb->prefix}hdlv2_widget_leads
             WHERE practitioner_user_id = %d AND visitor_email = %s
             ORDER BY id DESC LIMIT 1",
            (int) $practitioner_id, (string) $email
        ) );
        return $row ?: null;
    }

    // ──────────────────────────────────────────────────────────────
    //  HELPERS — verification flow (private static so handle_verification_click
    //  in the init hook can call them without instantiating the class)
    // ──────────────────────────────────────────────────────────────

    /**
     * Format + DNS deliverability check. Returns null on pass, WP_Error on fail.
     * MX lookup uses checkdnsrr; falls back to A record (RFC 5321 implicit MX).
     * Skipped entirely when HDLV2_SKIP_MX_CHECK is defined (useful for local dev
     * with .test domains).
     */
    private static function validate_email_deliverable( $email ) {
        if ( defined( 'HDLV2_SKIP_MX_CHECK' ) && HDLV2_SKIP_MX_CHECK ) {
            return null;
        }
        $at = strrpos( $email, '@' );
        if ( $at === false ) {
            return new WP_Error( 'invalid_email', 'Please enter a valid email address.', array( 'status' => 400 ) );
        }
        $domain = substr( $email, $at + 1 );
        if ( ! $domain || ! function_exists( 'checkdnsrr' ) ) {
            return null; // can't check — fail-open
        }
        if ( checkdnsrr( $domain, 'MX' ) || checkdnsrr( $domain, 'A' ) ) {
            return null;
        }
        return new WP_Error( 'undeliverable_email', 'That email domain doesn\'t accept mail. Please double-check the address.', array( 'status' => 400 ) );
    }

    /**
     * "kim@gmail.com" → "k***@gmail.com" — for the on-screen "we sent it to..." card.
     */
    private static function mask_email( $email ) {
        $at = strrpos( $email, '@' );
        if ( $at === false || $at === 0 ) return $email;
        $local  = substr( $email, 0, $at );
        $domain = substr( $email, $at );
        if ( strlen( $local ) <= 1 ) return $local . '***' . $domain;
        return $local[0] . str_repeat( '*', max( 3, strlen( $local ) - 1 ) ) . $domain;
    }

    /**
     * Build the QuickChart gauge URL for a rate. Centralised so all 3 callers
     * (verification email, practitioner notify, Make.com PDF payload) match.
     */
    private static function build_gauge_url( $rate ) {
        if ( ! $rate ) return '';
        $clamped = max( 0.8, min( 1.4, round( (float) $rate, 2 ) ) );
        if ( $clamped <= 0.9 )      { $sub_text = 'Slower';  $sub_color = 'rgba(67, 191, 85, 1)'; }
        elseif ( $clamped <= 1.1 )  { $sub_text = 'Average'; $sub_color = 'rgba(65, 165, 238, 1)'; }
        else                         { $sub_text = 'Faster';  $sub_color = 'rgba(255, 111, 75, 1)'; }

        $cfg = array(
            'type' => 'gauge',
            'data' => array(
                'labels'   => array( 'Slower', 'Average', 'Faster' ),
                'datasets' => array( array(
                    'data' => array( 0.9, 1.1, 1.4 ), 'value' => $clamped,
                    'minValue' => 0.8, 'maxValue' => 1.4,
                    'backgroundColor' => array( 'rgba(67,191,85,0.95)', 'rgba(65,165,238,0.95)', 'rgba(255,111,75,0.95)' ),
                    'borderWidth' => 1, 'borderColor' => 'rgba(255,255,255,0.8)', 'borderRadius' => 5,
                ) ),
            ),
            'options' => array(
                'layout'  => array( 'padding' => array( 'top'=>30, 'bottom'=>15, 'left'=>15, 'right'=>15 ) ),
                'plugins' => array( 'datalabels' => array( 'display' => false ) ),
                'needle'  => array( 'radiusPercentage'=>2.5, 'widthPercentage'=>4.0, 'lengthPercentage'=>68, 'color'=>'#004F59', 'shadowColor'=>'rgba(0,79,89,0.4)', 'shadowBlur'=>8, 'shadowOffsetY'=>4, 'borderWidth'=>2, 'borderColor'=>'rgba(255,255,255,1.0)' ),
                'valueLabel' => array( 'display'=>true, 'fontSize'=>36, 'fontFamily'=>"'Inter',sans-serif", 'fontWeight'=>'bold', 'color'=>'#004F59', 'backgroundColor'=>'transparent', 'bottomMarginPercentage'=>-10, 'padding'=>8 ),
                'centerArea' => array( 'displayText'=>false, 'backgroundColor'=>'transparent' ),
                'arc'        => array( 'borderWidth'=>0, 'padding'=>2, 'margin'=>3, 'roundedCorners'=>true ),
                'subtitle'   => array( 'display'=>true, 'text'=>$sub_text, 'color'=>$sub_color, 'font'=>array('size'=>20,'weight'=>'bold','family'=>"'Inter',sans-serif"), 'padding'=>array('top'=>8) ),
            ),
        );
        return 'https://quickchart.io/chart?c=' . rawurlencode( wp_json_encode( $cfg ) ) . '&w=380&h=340&bkg=white';
    }

    /**
     * Plain-language interpretation for a rate value.
     */
    private static function interpret_rate( $rate ) {
        if ( ! $rate )              return array( 'label' => '', 'message' => '' );
        if ( $rate <= 0.95 )        return array( 'label' => 'Slower than average', 'message' => 'Your pace of ageing is ' . $rate . '× — you\'re ageing slower than average. Your lifestyle factors are working in your favour.' );
        if ( $rate <= 1.05 )        return array( 'label' => 'Average',             'message' => 'Your pace of ageing is ' . $rate . '× — roughly at the population average. Small changes could shift this significantly.' );
        return array( 'label' => 'Faster than average', 'message' => 'Your pace of ageing is ' . $rate . '× — you\'re ageing faster than average. Targeted changes can bring this down.' );
    }

    /**
     * Render + dispatch the verification email. Returns true on send.
     * The actual HTML lives in HDLV2_Email_Templates::widget_verification.
     */
    private static function send_widget_verification_email( $data, $config ) {
        if ( ! class_exists( 'HDLV2_Email_Templates' ) ) {
            error_log( '[HDLV2 widget] HDLV2_Email_Templates missing — cannot send verification email.' );
            return false;
        }

        $rate_disp = $data['rate'] ? number_format( (float) $data['rate'], 2 ) : '';
        $interp    = self::interpret_rate( $data['rate'] );

        $html = HDLV2_Email_Templates::widget_verification( array(
            'client_name'        => $data['visitor_name'],
            'client_email'       => $data['visitor_email'],
            'rate'               => $rate_disp,
            'gauge_url'          => self::build_gauge_url( $data['rate'] ),
            'rate_message'       => $interp['message'],
            'practitioner_name'  => $data['practitioner_name'] ?: ( $config && $config->practitioner_name ? $config->practitioner_name : 'your practitioner' ),
            'practitioner_id'    => $data['practitioner_user_id'] ?? ( $config->practitioner_user_id ?? null ),
            'verify_url'         => $data['verify_url'],
            'reject_url'         => $data['reject_url'],
            'is_returning_user'  => ! empty( $data['is_returning_user'] ),
        ) );

        $subject = ! empty( $data['is_returning_user'] )
            ? 'Add ' . ( $data['practitioner_name'] ?: 'practitioner' ) . ' to your HDL account'
            : 'Confirm your ageing score — HealthDataLab';

        return wp_mail(
            $data['visitor_email'],
            $subject,
            $html,
            array( 'Content-Type: text/html; charset=UTF-8' )
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  END HELPERS — back to legacy code (left in place but only
    //  reached via the invite-token fast path or explicit calls from
    //  complete_signup() inside ::handle_verification_click)
    // ──────────────────────────────────────────────────────────────

    /**
     * Legacy practitioner notification + Make.com PDF webhook block.
     * Only invoked once a verification has succeeded (or via invite fast path).
     * Preserved verbatim from the pre-verification flow so practitioner emails
     * and Make.com payloads are byte-identical to what they receive today.
     */
    private static function dispatch_post_signup_artifacts( $args ) {
        $practitioner_id = (int) $args['practitioner_id'];
        $visitor_name    = $args['visitor_name'];
        $visitor_email   = $args['visitor_email'];
        $visitor_phone   = $args['visitor_phone'] ?? '';
        $visitor_age     = $args['visitor_age'] ?? null;
        $rate            = $args['rate'];
        $stage1_data     = $args['stage1_data'];
        $form_token      = $args['form_token'];
        $config          = $args['config'];
        $invite_id       = $args['invite_id'] ?? null;
        $send_notify     = ! empty( $args['send_practitioner_notify'] );
        $send_make_pdf   = ! empty( $args['send_make_pdf'] );

        // Optional config webhook
        if ( $config && ! empty( $config->webhook_url ) ) {
            wp_remote_post( $config->webhook_url, array(
                'body'    => wp_json_encode( $stage1_data + array(
                    'name'  => $visitor_name,
                    'email' => $visitor_email,
                    'phone' => $visitor_phone,
                    'rate_of_ageing_result' => $rate,
                ) ),
                'headers' => array( 'Content-Type' => 'application/json' ),
                'timeout' => 5,
            ) );
        }

        // Interpretation text — shared by both client and practitioner emails
        $interp       = 'Faster than average';
        $interp_color = '#FF6F4B';
        if ( $rate && $rate <= 0.95 ) {
            $interp = 'Slower than average'; $interp_color = '#43BF55';
        } elseif ( $rate && $rate <= 1.05 ) {
            $interp = 'Average'; $interp_color = '#41A5EE';
        }
        $gauge_url = self::build_gauge_url( $rate );

        if ( $send_notify && $config && ! empty( $config->notification_email ) ) {
            $subject = sprintf( 'New Lead: %s', $visitor_name ?: 'Unknown' );

            $n  = esc_html( $visitor_name ?: 'Not provided' );
            $e  = esc_html( $visitor_email ?: 'Not provided' );
            $er = esc_attr( $visitor_email );

            // Build QuickChart gauge URL (matches widget JS gaugeUrl())
            $gauge_url = '';
            if ( $rate ) {
                $clamped = max( 0.8, min( 1.4, round( $rate, 2 ) ) );
                $gauge_cfg = array(
                    'type' => 'gauge',
                    'data' => array(
                        'labels'   => array( 'Slower', 'Average', 'Faster' ),
                        'datasets' => array( array(
                            'data'            => array( 0.9, 1.1, 1.4 ),
                            'value'           => $clamped,
                            'minValue'        => 0.8,
                            'maxValue'        => 1.4,
                            'backgroundColor' => array( 'rgba(67,191,85,0.95)', 'rgba(65,165,238,0.95)', 'rgba(255,111,75,0.95)' ),
                            'borderWidth'     => 1,
                            'borderColor'     => 'rgba(255,255,255,0.8)',
                            'borderRadius'    => 5,
                        ) ),
                    ),
                    'options' => array(
                        'layout'  => array( 'padding' => array( 'top' => 30, 'bottom' => 15, 'left' => 15, 'right' => 15 ) ),
                        'plugins' => array( 'datalabels' => array( 'display' => false ) ),
                        'needle'  => array(
                            'radiusPercentage' => 2.5, 'widthPercentage' => 4.0, 'lengthPercentage' => 68,
                            'color' => '#004F59', 'shadowColor' => 'rgba(0,79,89,0.4)', 'shadowBlur' => 8, 'shadowOffsetY' => 4,
                            'borderWidth' => 2, 'borderColor' => 'rgba(255,255,255,1.0)',
                        ),
                        'valueLabel' => array(
                            'display' => true, 'fontSize' => 36, 'fontFamily' => "'Inter',sans-serif", 'fontWeight' => 'bold',
                            'color' => '#004F59', 'backgroundColor' => 'transparent', 'bottomMarginPercentage' => -10, 'padding' => 8,
                        ),
                        'centerArea' => array( 'displayText' => false, 'backgroundColor' => 'transparent' ),
                        'arc'        => array( 'borderWidth' => 0, 'padding' => 2, 'margin' => 3, 'roundedCorners' => true ),
                        'subtitle'   => array(
                            'display' => true, 'text' => $sub_text, 'color' => $sub_color,
                            'font' => array( 'size' => 20, 'weight' => 'bold', 'family' => "'Inter',sans-serif" ),
                            'padding' => array( 'top' => 8 ),
                        ),
                    ),
                );
                $gauge_url = 'https://quickchart.io/chart?c=' . rawurlencode( wp_json_encode( $gauge_cfg ) ) . '&w=380&h=340&bkg=white';
            }

            $body = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:0;background:#f4f5f7;font-family:\'Inter\',-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">'
                . '<div style="max-width:520px;margin:0 auto;padding:24px 16px;">'
                // Header
                . '<div style="text-align:center;padding:20px 0 16px;">'
                . '<span style="font-size:18px;font-weight:700;color:#004F59;letter-spacing:-0.3px;">HealthDataLab</span>'
                . '</div>'
                // Card
                . '<div style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">'
                // Title bar
                . '<div style="background:#004F59;padding:16px 24px;">'
                . '<h2 style="margin:0;color:#fff;font-size:16px;font-weight:600;font-family:\'Poppins\',\'Inter\',-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">New Lead from Your Widget</h2>'
                . '</div>'
                . '<div style="padding:24px;">'
                // Contact
                . '<div style="margin-bottom:20px;">'
                . '<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#888;margin-bottom:8px;font-weight:600;">Contact</div>'
                . '<div style="font-size:15px;font-weight:600;color:#111;">' . $n . '</div>'
                . '<div style="font-size:13px;color:#555;">' . $e . '</div>'
                . '</div>'
                // Gauge chart
                . ( $gauge_url ? '<div style="text-align:center;margin-bottom:16px;">'
                . '<img src="' . esc_url( $gauge_url ) . '" alt="Rate of Ageing Gauge" style="width:100%;max-width:320px;display:block;margin:0 auto;">'
                . '</div>' : '' )
                // Rate of ageing highlight
                . '<div style="background:#f8f9fb;border-radius:10px;padding:16px;margin-bottom:20px;text-align:center;">'
                . '<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#888;margin-bottom:6px;font-weight:600;">Rate of Ageing</div>'
                . '<div style="font-size:32px;font-weight:700;color:#004F59;">' . ( $rate ? number_format( $rate, 2 ) : '—' ) . '</div>'
                . '<div style="font-size:13px;font-weight:500;color:' . $interp_color . ';">' . $interp . '</div>'
                . '</div>'
                // Age summary (only data available from Stage 1)
                . ( $visitor_age ? '<table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:20px;"><tr>'
                . '<td width="50%" style="padding:0 4px 0 0;"><div style="background:#f8f9fb;border:1px solid #eef0f3;border-radius:10px;padding:14px 8px;text-align:center;">'
                . '<div style="font-size:10px;text-transform:uppercase;letter-spacing:0.5px;color:#888;margin-bottom:4px;font-weight:600;">Chrono Age</div>'
                . '<div style="font-size:24px;font-weight:700;color:#004F59;line-height:1.2;">' . $visitor_age . '</div>'
                . '</div></td>'
                . '<td width="50%" style="padding:0 0 0 4px;"><div style="background:#f8f9fb;border:1px solid #eef0f3;border-radius:10px;padding:14px 8px;text-align:center;">'
                . '<div style="font-size:10px;text-transform:uppercase;letter-spacing:0.5px;color:#888;margin-bottom:4px;font-weight:600;">Questions</div>'
                . '<div style="font-size:24px;font-weight:700;color:#004F59;line-height:1.2;">9 of 9</div>'
                . '</div></td>'
                . '</tr></table>' : '' )
                // v0.35.0 (Phase O) — primary CTA: review the lead in the
                // practitioner's dashboard. Hash deeplink #hdlv2-pending-leads
                // is intercepted by hdlv2-dashboard.js to auto-open the
                // Client Tools modal on the Pending Leads tab. The mailto
                // below stays as a secondary action for cases where the
                // practitioner wants to email the lead directly without
                // confirming first.
                . '<div style="text-align:center;margin-bottom:14px;">'
                . '<a href="' . esc_url( site_url( '/clients/#hdlv2-pending-leads' ) ) . '" style="display:inline-block;padding:12px 32px;background:#3d8da0;color:#fff;border-radius:8px;text-decoration:none;font-size:14px;font-weight:600;">Review in your dashboard &rarr;</a>'
                . '<p style="margin:10px 0 0;font-size:11px;color:#888;line-height:1.5;">Confirm to send the client their next-step magic link, or reject if it looks like spam.</p>'
                . '</div>'
                // Secondary CTA — direct reply (legacy, kept for direct-contact use cases)
                . ( $visitor_email ? '<div style="text-align:center;">'
                . '<a href="mailto:' . $er . '?subject=Your%20Rate%20of%20Ageing%20Results" style="display:inline-block;padding:8px 20px;background:#fff;color:#3d8da0;border:1px solid #3d8da0;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;">Reply to ' . $n . ' directly</a>'
                . '</div>' : '' )
                . '</div></div>'
                // Footer
                . '<div style="text-align:center;padding:16px;font-size:11px;color:#aaa;">'
                . 'This lead came from your HealthDataLab embedded widget.'
                . '</div>'
                . '</div></body></html>';

            $headers = array( 'Content-Type: text/html; charset=UTF-8' );
            wp_mail( $config->notification_email, $subject, $body, $headers );
        }

        // (Legacy "client results email + Continue to Stage 2 magic link" block
        //  removed — clients now receive the verification email INSTEAD, with the
        //  results gauge + verify_url that auto-logs them in. Single email path,
        //  no duplicate magic-link surface.)

        if ( $send_make_pdf && $rate ) {
            $practitioner_data = get_userdata( $practitioner_id );
            // Build gauge URL for PDF.
            //
            // v0.30.0 — Premium template renders rate value + interpretation
            // pill in its own typography, so the gauge image is now dial-only:
            // valueLabel.display = false and subtitle.display = false. This
            // strips the "0.96" and "Slower" baked into the QuickChart PNG so
            // the PDF doesn't double-show the same number. Practitioner email
            // gauge (separate config above, ~line 758) keeps its baked-in
            // labels — that surface has minimal typography around it.
            $clamped_rate = max( 0.8, min( 1.4, round( (float) $rate, 2 ) ) );
            $s1_gauge_cfg = array(
                'type' => 'gauge',
                'data' => array( 'labels' => array('Slower','Average','Faster'), 'datasets' => array( array(
                    'data' => array(0.9,1.1,1.4), 'value' => $clamped_rate, 'minValue' => 0.8, 'maxValue' => 1.4,
                    'backgroundColor' => array('rgba(67,191,85,0.95)','rgba(65,165,238,0.95)','rgba(255,111,75,0.95)'),
                    'borderWidth' => 1, 'borderColor' => 'rgba(255,255,255,0.8)', 'borderRadius' => 5,
                ) ) ),
                'options' => array(
                    'layout' => array( 'padding' => array('top'=>30,'bottom'=>15,'left'=>15,'right'=>15) ),
                    'plugins' => array( 'datalabels' => array('display'=>false) ),
                    'needle' => array('radiusPercentage'=>2.5,'widthPercentage'=>4,'lengthPercentage'=>68,'color'=>'#004F59','shadowColor'=>'rgba(0,79,89,0.4)','shadowBlur'=>8,'shadowOffsetY'=>4,'borderWidth'=>2,'borderColor'=>'rgba(255,255,255,1)'),
                    // v0.30.0 — disabled; premium template renders value typographically.
                    'valueLabel' => array('display'=>false),
                    'centerArea' => array('displayText'=>false,'backgroundColor'=>'transparent'),
                    'arc' => array('borderWidth'=>0,'padding'=>2,'margin'=>3,'roundedCorners'=>true),
                    // v0.30.0 — disabled; premium template renders interpretation as a pill.
                    'subtitle' => array('display'=>false),
                ),
            );
            $s1_gauge_url = 'https://quickchart.io/chart?c=' . rawurlencode( wp_json_encode( $s1_gauge_cfg ) ) . '&w=380&h=340&bkg=white';

            // Practitioner logo — canonical helper with HDL fallback because
            // this surface (Stage 1 result page email + PDFMonkey Stage 1
            // template) renders the URL directly into <img src>. An empty
            // string would show a broken-image placeholder. The Final Report
            // cover surface deliberately does NOT use the fallback because
            // its template has a Liquid avatar branch for empty values. (v0.29.4)
            $prac_logo       = HDLV2_Practitioner::get_logo_url( (int) $practitioner_id, true );
            // v0.36.0 (Phase P) — paired shape detection. PDFMonkey Stage 1
            // template can render the logo in the right white pill (square→
            // circle, wordmark→letterbox, tall→portrait) so wordmarks no
            // longer get circle-cropped at the 13mm footer slot.
            $prac_logo_shape = HDLV2_Practitioner::get_logo_shape( (int) $practitioner_id, true );

            // Pre-render the gauge to a short on-domain URL. The full QuickChart
            // URL is ~1,800 chars which breaks Gmail's image proxy (URL-length
            // limit) and PDFMonkey's image fetcher. We download once and serve a
            // tiny ~80-char URL from /wp-content/uploads/hdlv2-gauges/. (v0.22.3)
            $gauge_for_payload = self::prerender_gauge_png( $s1_gauge_url, $form_token, 'stage1' );
            if ( ! $gauge_for_payload ) {
                $gauge_for_payload = $s1_gauge_url;
            }

            // v0.30.0 — Stage 1 PDF premium-template fields.
            // Deterministic copy: rate-banded headline + strongest factor +
            // priority focus, picked by HDLV2_Stage1_Commentary over the
            // user's 9 answers. Same builder the web result page uses.
            // Static libraries, zero AI calls, zero external HTTP.
            $commentary = HDLV2_Stage1_Commentary::build_structured(
                $stage1_data,
                isset( $stage1_data['server_result'] ) && is_array( $stage1_data['server_result'] )
                    ? $stage1_data['server_result']
                    : array( 'rate' => (float) $rate ),
                (string) $visitor_name
            );

            $pdf_payload = array(
                // v0.22.8 — Make.com Final Report scenario now gates each route
                // by report_type (Route 1 = final, Route 2 = stage1, Route 3 =
                // draft, Route 4 = stage2_why). All four V2 webhook constants
                // point to the same scenario URL, so this field is required
                // here or the Stage 1 webhook hits no matching route and
                // silently drops (no PDF, no email).
                'report_type'            => 'stage1',
                'client_name'            => $visitor_name,
                'client_email'           => $visitor_email,
                'client_phone'           => $visitor_phone,
                'practitioner_id'        => $practitioner_id,
                'practitioner_name'      => $practitioner_data ? $practitioner_data->display_name : '',
                'practitioner_email'     => $practitioner_data ? $practitioner_data->user_email : '',
                'practitioner_logo_url'   => $prac_logo,
                'practitioner_logo_shape' => $prac_logo_shape,
                // Cast to clean string — Make.com webhook structure caches type
                // on first received payload; sending a guaranteed string avoids
                // empty renders downstream when float vs null is ambiguous.
                'rate_of_ageing'         => number_format( (float) $rate, 2, '.', '' ),
                'gauge_url'              => $gauge_for_payload,
                'report_date'            => current_time( 'Y-m-d' ),
                'stage1_data'            => $stage1_data,
                'form_token'             => $form_token,
                'timestamp'              => current_time( 'c' ),
                // v0.30.0 — premium template fields. Plain text from
                // HDLV2_Stage1_Commentary::build_structured(). Make.com
                // forwards these unchanged into the PDFMonkey payload.
                'headline_text'          => $commentary['headline_text'],
                'biological_age'         => $commentary['biological_age'],
                'strongest_topic'        => $commentary['strongest_topic'],
                'strongest_text'         => $commentary['strongest_text'],
                'priority_topic'         => $commentary['priority_topic'],
                'priority_text'          => $commentary['priority_text'],
            );

            $stage1_webhook = defined( 'HDLV2_MAKE_STAGE1_PDF' ) ? HDLV2_MAKE_STAGE1_PDF : '';
            if ( ! $stage1_webhook ) {
                error_log( '[HDLV2] Stage 1 PDF webhook not configured — define HDLV2_MAKE_STAGE1_PDF in wp-config.php' );
            }

            wp_remote_post( $stage1_webhook, array(
                'body'      => wp_json_encode( $pdf_payload ),
                'headers'   => array( 'Content-Type' => 'application/json' ),
                'timeout'   => 5,
                'blocking'  => false,
            ) );
        }

        // dispatch helper returns nothing — caller already returned a REST response
    }

    /**
     * v0.22.3 — Pre-render the Stage 1 QuickChart gauge as a local PNG.
     *
     * Long QuickChart URLs (~1,800 chars) break Gmail's image proxy and
     * PDFMonkey's image fetcher, leaving the gauge as a broken image in
     * the result email and PDF. Downloading once and serving from
     * /wp-content/uploads/hdlv2-gauges/<token>.png keeps the URL short
     * (~80 chars), on-domain, and free of third-party fetch dependencies
     * at email-render time.
     *
     * Token is sanitised to hex-only so the filename is always safe for
     * the filesystem and the public URL.
     *
     * @param string $remote_url The QuickChart URL.
     * @param string $token      The form token (used as the filename).
     * @return string|null Public URL of the saved PNG, or null on failure
     *                     (caller falls back to the remote URL).
     */
    /**
     * v0.22.8 — was prerender_stage1_gauge(); now public + suffix-keyed so the
     * Final Report webhook (HDLV2_Final_Report::fire_webhook) can reuse the
     * same logic. Different webhooks may compute different rates for the
     * same client, so we key the cached file by `<token>-<suffix>.png`
     * (e.g. <token>-stage1.png vs <token>-final.png) to avoid stale-rate
     * collisions across stages.
     */
    public static function prerender_gauge_png( $remote_url, $token, $suffix ) {
        $token  = preg_replace( '/[^a-f0-9]/', '', (string) $token );
        $suffix = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $suffix ) );
        if ( strlen( $token ) < 32 || $suffix === '' ) {
            return null;
        }

        $uploads = wp_upload_dir();
        if ( empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
            return null;
        }

        $dir  = trailingslashit( $uploads['basedir'] ) . 'hdlv2-gauges';
        if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return null;
        }

        // v0.28.11 — cache key now includes a config version. Any change
        // to `build_gauge_url()` settings (valueLabel.display, subtitle,
        // colours, etc.) should bump GAUGE_CACHE_VERSION so old PNGs are
        // ignored and fresh ones generated. Without this, my v0.28.10
        // valueLabel.display:false fix had no effect on already-cached
        // PNGs (every existing client kept seeing the duplicate value
        // text baked into the pixels). See class-hdlv2-staged-form.php
        // build_gauge_url for the current settings.
        $cache_version = 'v4';   // bump on any gauge_url config change (v0.32.0: HDL status palette — green/amber/red, was green/blue-teal/orange)
        $filename = $token . '-' . $suffix . '-' . $cache_version . '.png';
        $path = $dir . '/' . $filename;
        $url  = trailingslashit( $uploads['baseurl'] ) . 'hdlv2-gauges/' . $filename;

        // Cache hit — already pre-rendered.
        if ( file_exists( $path ) && filesize( $path ) > 1000 ) {
            return $url;
        }

        $res = wp_remote_get( $remote_url, array( 'timeout' => 15 ) );
        if ( is_wp_error( $res ) ) {
            error_log( '[HDLV2] Gauge prerender (' . $suffix . ') failed: ' . $res->get_error_message() );
            return null;
        }
        if ( wp_remote_retrieve_response_code( $res ) !== 200 ) {
            error_log( '[HDLV2] Gauge prerender (' . $suffix . ') HTTP ' . wp_remote_retrieve_response_code( $res ) );
            return null;
        }

        $body = wp_remote_retrieve_body( $res );
        if ( ! $body || strlen( $body ) < 1000 ) {
            return null;
        }

        if ( false === file_put_contents( $path, $body ) ) {
            error_log( '[HDLV2] Gauge prerender (' . $suffix . '): file_put_contents failed for ' . $path );
            return null;
        }

        return $url;
    }

    /**
     * Promote a verified visitor (or invite-fast-path visitor) into a real
     * account + form_progress + V1 link + widget_leads tracking row.
     * Returns the form_progress token (used to build the magic link).
     *
     * Called from:
     *   - rest_capture_lead() invite-token fast path (pre-trusted)
     *   - handle_verification_click() (post-verification)
     */
    private static function complete_signup( $args ) {
        global $wpdb;

        $practitioner_id = (int) $args['practitioner_id'];
        $visitor_name    = $args['visitor_name'];
        $visitor_email   = $args['visitor_email'];
        $visitor_phone   = $args['visitor_phone'] ?? '';
        $visitor_age     = $args['visitor_age'] ?? null;
        $rate            = $args['rate'];
        $stage1_data     = $args['stage1_data'];
        $invite_id       = $args['invite_id'] ?? null;
        $config          = $args['config'] ?? null;

        if ( ! $config ) {
            $config = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hdlv2_widget_config WHERE practitioner_user_id = %d LIMIT 1",
                $practitioner_id
            ) );
        }

        // Reuse existing form_progress if (email, practitioner) already paired,
        // else create the WP user (or match an existing one by email) and a
        // fresh form_progress row.
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, token FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE client_email = %s AND practitioner_user_id = %d LIMIT 1",
            $visitor_email, $practitioner_id
        ) );

        if ( $existing ) {
            $form_token = $existing->token;
            $wpdb->update(
                $wpdb->prefix . 'hdlv2_form_progress',
                array(
                    'stage1_data'         => wp_json_encode( $stage1_data ),
                    'stage1_completed_at' => current_time( 'mysql' ),
                    'client_name'         => $visitor_name,
                ),
                array( 'id' => $existing->id )
            );
            // Resolve client_user_id for the V1 link (may be null on legacy rows)
            $client_user_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT client_user_id FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d",
                $existing->id
            ) );
        } else {
            $client_user_id = email_exists( $visitor_email );
            if ( ! $client_user_id ) {
                $username = sanitize_user(
                    strtolower( str_replace( ' ', '', $visitor_name ) ) . '_' . wp_rand( 1000, 9999 ),
                    true
                );
                $client_user_id = wp_insert_user( array(
                    'user_login' => $username,
                    'user_email' => $visitor_email,
                    'user_pass'  => wp_generate_password( 24, true, true ),
                    'first_name' => $visitor_name,
                    'role'       => 'um_client',
                ) );
                if ( is_wp_error( $client_user_id ) ) {
                    $client_user_id = null;
                } else {
                    update_user_meta( $client_user_id, 'hdl_source',         'widget' );
                    update_user_meta( $client_user_id, 'hdl_consumer_user',  true );
                    // NUA auto-approve — same fix as the magic-link path. Without
                    // this, the widget-verified user can't log back in after the
                    // first auto-login session expires. NUA's wp_authenticate_user
                    // filter rejects pw_user_status='pending'.
                    if ( class_exists( 'pw_new_user_approve' ) || function_exists( 'pw_new_user_approve' ) ) {
                        update_user_meta( $client_user_id, 'pw_user_status', 'approved' );
                        update_user_meta( $client_user_id, 'pw_user_status_time', gmdate( 'Y-m-d H:i:s' ) );
                        delete_user_meta( $client_user_id, 'pending' );
                    }
                }
            }

            $form_token = bin2hex( random_bytes( 32 ) );
            $wpdb->insert(
                $wpdb->prefix . 'hdlv2_form_progress',
                array(
                    'client_user_id'       => $client_user_id,
                    'practitioner_user_id' => $practitioner_id,
                    'client_name'          => $visitor_name,
                    'client_email'         => $visitor_email,
                    'widget_invite_id'     => $invite_id,
                    'token'                => $form_token,
                    'current_stage'        => 2,
                    'stage1_data'          => wp_json_encode( $stage1_data ),
                    'stage1_completed_at'  => current_time( 'mysql' ),
                )
            );

            if ( $client_user_id && class_exists( 'HDLV2_Compatibility' ) ) {
                HDLV2_Compatibility::create_practitioner_client_link( $practitioner_id, $client_user_id );
            }
        }

        // Always log to widget_leads (upsert). v0.29.0 — also writes stage1_data
        // so a future Send-Invites for the same email reuses the latest answers.
        // Pre-v0.29 this only logged on verified submissions.
        //
        // v0.35.0 (Phase O) — switched from a raw INSERT to an upsert keyed on
        // (practitioner_user_id, visitor_email). Without this, calling
        // complete_signup() on a confirm action (rest_confirm_lead) would
        // INSERT a brand-new widget_leads row which the schema defaults to
        // status='pending' — re-queuing the just-confirmed lead in the inbox
        // immediately. The upsert leaves status untouched on existing rows,
        // matching record_widget_lead()'s contract.
        $existing_lead_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}hdlv2_widget_leads
             WHERE practitioner_user_id = %d AND visitor_email = %s
             ORDER BY id DESC LIMIT 1",
            $practitioner_id, $visitor_email
        ) );
        $lead_payload = array(
            'visitor_name'   => $visitor_name,
            'visitor_age'    => $visitor_age,
            'rate_of_ageing' => $rate,
            'stage1_data'    => wp_json_encode( $stage1_data ),
        );
        // Only write invite_id when we actually have one (invite-fast path).
        // record_widget_lead never writes it, so an UPDATE here without the
        // guard would clobber the legacy invite_id capture.
        if ( $invite_id ) {
            $lead_payload['invite_id'] = $invite_id;
        }
        if ( $existing_lead_id ) {
            // Refresh prefill source + bump created_at for inbox sort, but
            // intentionally do NOT touch status — terminal state (confirmed
            // or rejected) sticks across re-submissions.
            $lead_payload['created_at'] = current_time( 'mysql' );
            $wpdb->update( $wpdb->prefix . 'hdlv2_widget_leads', $lead_payload, array( 'id' => $existing_lead_id ) );
        } else {
            // First time we've seen this (practitioner, email). Insert with
            // explicit status='confirmed' since complete_signup() is only
            // ever called when the practitioner has already approved this
            // client (invite-fast path or rest_confirm_lead). The row
            // exists for prefill + lookup, not for the inbox.
            $lead_payload['practitioner_user_id'] = $practitioner_id;
            $lead_payload['visitor_email']        = $visitor_email;
            $lead_payload['status']               = 'confirmed';
            $lead_payload['confirmed_at']         = current_time( 'mysql' );
            $wpdb->insert( $wpdb->prefix . 'hdlv2_widget_leads', $lead_payload );
        }

        // Notify practitioner + Make.com PDF (uses kept legacy code in the
        // dispatch helper). Both default-on for the verified path.
        self::dispatch_post_signup_artifacts( array(
            'practitioner_id'  => $practitioner_id,
            'visitor_name'     => $visitor_name,
            'visitor_email'    => $visitor_email,
            'visitor_phone'    => $visitor_phone,
            'visitor_age'      => $visitor_age,
            'rate'             => $rate,
            'stage1_data'      => $stage1_data,
            'form_token'       => $form_token,
            'config'           => $config,
            'invite_id'        => $invite_id,
            'send_practitioner_notify' => ! empty( $args['send_practitioner_notify'] ),
            'send_make_pdf'    => ! empty( $args['send_make_pdf'] ),
        ) );

        return $form_token;
    }

    /**
     * Verification-click handler — fired from the init hook in hdl-longevity-v2.php
     * when a visitor clicks the link in their verification email.
     *
     * Promotes a 'pending' row → real account → auto-login → redirect to assessment.
     * On invalid/expired/already-used, returns silently (init hook falls through
     * and the user sees a normal page render with the ?hdlv2_verify= still in URL,
     * which we render below as a friendly status page).
     */
    public static function handle_verification_click( $verify_token ) {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_pending_leads WHERE verify_token = %s LIMIT 1",
            $verify_token
        ) );
        if ( ! $row ) {
            return self::render_verify_status_page( 'invalid' );
        }
        if ( $row->status === 'verified' ) {
            // Already verified — just redirect to assessment using the existing
            // form_progress token (idempotent click; user double-clicked email).
            $form_token = $wpdb->get_var( $wpdb->prepare(
                "SELECT token FROM {$wpdb->prefix}hdlv2_form_progress
                 WHERE client_email = %s AND practitioner_user_id = %d LIMIT 1",
                $row->visitor_email, $row->practitioner_id
            ) );
            if ( $form_token ) {
                self::auto_login_by_email( $row->visitor_email );
                wp_safe_redirect( site_url( '/assessment/?token=' . $form_token ) );
                exit;
            }
            return self::render_verify_status_page( 'already_verified' );
        }
        if ( $row->status === 'rejected' ) {
            return self::render_verify_status_page( 'rejected' );
        }
        // v0.26.2 — fix #5 (/ultrareview): expires_at is stored via gmdate()
        // (UTC) but strtotime() interprets a timezone-less string as server
        // local time. On non-UTC servers (London BST in summer = UTC+1) this
        // is off by hours; for 30-minute invites the 1-hour offset means the
        // invite is born expired. Force UTC interpretation everywhere we read.
        if ( strtotime( $row->expires_at . ' UTC' ) < time() ) {
            $wpdb->update(
                $wpdb->prefix . 'hdlv2_pending_leads',
                array( 'status' => 'expired' ),
                array( 'id' => $row->id )
            );
            return self::render_verify_status_page( 'expired' );
        }

        $stage1 = json_decode( $row->stage1_data, true ) ?: array();
        $config = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_widget_config WHERE practitioner_user_id = %d LIMIT 1",
            $row->practitioner_id
        ) );

        $form_token = self::complete_signup( array(
            'practitioner_id' => (int) $row->practitioner_id,
            'visitor_name'    => $row->visitor_name,
            'visitor_email'   => $row->visitor_email,
            'visitor_phone'   => $row->visitor_phone,
            'visitor_age'     => isset( $stage1['q1_age'] ) ? absint( $stage1['q1_age'] ) : null,
            'rate'            => $row->rate_of_ageing,
            'stage1_data'     => $stage1,
            'config'          => $config,
            'send_practitioner_notify' => true,
            'send_make_pdf'   => true,
        ) );

        // Mark verified
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_pending_leads',
            array( 'status' => 'verified', 'verified_at' => current_time( 'mysql' ) ),
            array( 'id' => $row->id )
        );

        self::auto_login_by_email( $row->visitor_email );
        wp_safe_redirect( site_url( '/assessment/?token=' . $form_token ) );
        exit;
    }

    /**
     * "This wasn't me" click handler — wires off ?hdlv2_reject= to mark a
     * pending row as rejected (no account created, no further emails for
     * this token). Renders a friendly confirmation page and exits.
     */
    public static function handle_rejection_click( $verify_token ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_pending_leads',
            array( 'status' => 'rejected', 'rejected_at' => current_time( 'mysql' ) ),
            array( 'verify_token' => $verify_token, 'status' => 'pending' )
        );
        return self::render_verify_status_page( 'rejected' );
    }

    /**
     * Auto-login a user by email (used after verification click).
     * No-op if the user doesn't exist (the visitor will land on the assessment
     * page and the existing ?token= magic-link handler will pick them up).
     */
    private static function auto_login_by_email( $email ) {
        $user = get_user_by( 'email', $email );
        if ( ! $user ) return;
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, false );
    }

    /**
     * Renders a small standalone HTML page for verification status outcomes.
     * Exits when the status warrants a hard stop (invalid/expired/rejected),
     * otherwise returns so the init hook continues normal page render.
     */
    private static function render_verify_status_page( $status ) {
        $title  = 'HealthDataLab';
        $msg    = '';
        $action = '';

        switch ( $status ) {
            case 'invalid':
                $msg    = 'This verification link is invalid or has been used already.';
                break;
            case 'expired':
                $msg    = 'This verification link has expired (links are valid for 48 hours). Please complete the assessment on your practitioner\'s site again to receive a fresh link.';
                break;
            case 'rejected':
                $msg    = 'Thanks for letting us know. We\'ve removed your details &mdash; you won\'t receive any further emails about this submission.';
                break;
            case 'already_verified':
                $msg    = 'This email has already been verified. Please check your inbox for the link to continue your assessment.';
                break;
            default:
                $msg    = 'Verification status: ' . esc_html( $status );
        }

        // Single-shot HTML output; bypass theme to keep the page light and
        // dependency-free (works even if Divi/UM are misconfigured).
        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=UTF-8' );

        // v0.26.0 — restyled to match the email + my-report aesthetic.
        // Poppins for the wordmark, Inter for body. Soft fill background,
        // bordered card, HDL teal accent. Self-contained (no theme dep) so it
        // still renders if Divi/UM/object-cache misbehaves.
        $hdl_logo = 'https://healthdatalab.net/wp-content/uploads/2023/09/HDL-Logo-2309-4-d-sss.png';

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>' . esc_html( $title ) . '</title>';
        echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@600;700&display=swap" rel="stylesheet">';
        echo '<style>'
            . 'body{font-family:\'Inter\',-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#fafbfc;margin:0;padding:48px 16px;color:#2c3e50;line-height:1.6;}'
            . '.wrap{max-width:560px;margin:0 auto;}'
            . '.brand{text-align:center;margin:0 0 18px;}'
            . '.brand img{height:32px;width:auto;display:inline-block;}'
            . '.card{background:#fff;border:1px solid #e4e6ea;border-radius:14px;padding:40px 36px;box-shadow:0 4px 24px rgba(0,0,0,0.04);text-align:center;}'
            . '.card h1{font-family:\'Poppins\',\'Inter\',sans-serif;font-size:22px;font-weight:600;margin:0 0 12px;color:#004F59;letter-spacing:-0.01em;}'
            . '.card p{font-size:15px;color:#444;margin:0 0 18px;}'
            . '.card a.cta{display:inline-block;margin-top:12px;background:#3d8da0;color:#fff;padding:11px 26px;border-radius:48px;font-size:14px;font-weight:600;text-decoration:none;font-family:\'Poppins\',\'Inter\',sans-serif;}'
            . '.card a.cta:hover{background:#004F59;}'
            . '.footer{text-align:center;font-size:12px;color:#888;margin-top:18px;}'
            . '</style></head><body>';
        echo '<div class="wrap">';
        echo '<div class="brand"><img src="' . esc_url( $hdl_logo ) . '" alt="HealthDataLab"></div>';
        echo '<div class="card"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $msg ) . '</p>';
        echo '<a class="cta" href="' . esc_url( home_url( '/' ) ) . '">Back to HealthDataLab &rarr;</a>';
        echo '</div>';
        echo '<div class="footer">Powered by <strong style="color:#3d8da0;">HealthDataLab</strong></div>';
        echo '</div></body></html>';
        exit;
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: Phase O (v0.35.0) — Practitioner-Confirm Widget Flow
    //
    //  Closes the Stage-1-widget dead-end. Public widget submissions land
    //  as status='pending' on widget_leads (Phase O migration in
    //  class-hdlv2-activator.php). Practitioner reviews them in the new
    //  "Pending Leads" tab of the Client Tools modal and either:
    //    Confirm → complete_signup() creates form_progress (current_stage=2)
    //              with the original 9 widget answers, sends the client a
    //              magic-link email to /assessment/?token=…, marks the
    //              lead status='confirmed'
    //    Reject  → marks the lead status='rejected'; silent (no email,
    //              no Make.com call, no form_progress row)
    //
    //  Plus rest_get_public_config — read-only safe-subset endpoint so the
    //  embed pulls fresh CTA + theme on mount (set-and-forget embed).
    // ──────────────────────────────────────────────────────────────

    /**
     * GET /widget/leads/pending — Practitioner inbox feed.
     * Returns up to 50 pending widget leads, newest-first.
     */
    public function rest_list_pending_leads( $request ) {
        global $wpdb;
        $user_id = get_current_user_id();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, visitor_name, visitor_email, visitor_age, rate_of_ageing,
                    stage1_data, created_at
             FROM {$wpdb->prefix}hdlv2_widget_leads
             WHERE practitioner_user_id = %d AND status = 'pending'
             ORDER BY created_at DESC
             LIMIT 50",
            $user_id
        ) );

        $out = array();
        foreach ( (array) $rows as $r ) {
            $out[] = array(
                'id'             => (int) $r->id,
                'visitor_name'   => (string) $r->visitor_name,
                'visitor_email'  => (string) $r->visitor_email,
                'visitor_age'    => $r->visitor_age !== null ? (int) $r->visitor_age : null,
                'rate_of_ageing' => $r->rate_of_ageing !== null ? (float) $r->rate_of_ageing : null,
                'created_at'     => (string) $r->created_at,
            );
        }
        return rest_ensure_response( array( 'leads' => $out ) );
    }

    /**
     * POST /widget/leads/confirm — Practitioner confirms a pending lead.
     *
     * Body: { lead_id }
     *
     * Flow:
     *   1. Load the widget_leads row (IDOR-protected — must belong to caller)
     *   2. Idempotency: if already confirmed, return existing form_token without
     *      re-sending email or re-firing Make.com. If rejected → 409 conflict.
     *   3. Call complete_signup() — creates user (if new), creates form_progress
     *      with current_stage=2 + stage1_data + stage1_completed_at, links to
     *      practitioner via Compatibility::create_practitioner_client_link, and
     *      fires Make.com Stage 1 PDF webhook. Practitioner notify is
     *      suppressed (they already received the original "New Lead" email).
     *   4. Mark widget_leads row status='confirmed' BEFORE the email send so
     *      a wp_mail timeout doesn't put us in a "send twice on retry" state.
     *   5. Email the client a /assessment/?token=… magic link (reuses the
     *      existing send_invite_email template — same shell, URL goes
     *      straight to Stage 2 instead of back to the widget).
     *
     * Idempotent: re-confirming returns {already: true, form_token}; no
     * second email, no duplicate form_progress row.
     */
    public function rest_confirm_lead( $request ) {
        global $wpdb;
        $user_id = get_current_user_id();

        $params  = $request->get_json_params();
        $lead_id = isset( $params['lead_id'] ) ? absint( $params['lead_id'] ) : 0;
        if ( ! $lead_id ) {
            return new WP_Error( 'missing_lead_id', 'lead_id is required.', array( 'status' => 400 ) );
        }

        // IDOR check baked into the WHERE clause: a different practitioner's
        // lead_id returns null → 404, no information leaked.
        $lead = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_widget_leads
             WHERE id = %d AND practitioner_user_id = %d LIMIT 1",
            $lead_id, $user_id
        ) );
        if ( ! $lead ) {
            return new WP_Error( 'lead_not_found', 'Lead not found.', array( 'status' => 404 ) );
        }

        // Idempotency — already confirmed: return existing token, no side
        // effects. Looking up form_progress by (email, practitioner) avoids
        // depending on a foreign key we don't have.
        if ( $lead->status === 'confirmed' ) {
            $existing_token = $wpdb->get_var( $wpdb->prepare(
                "SELECT token FROM {$wpdb->prefix}hdlv2_form_progress
                 WHERE client_email = %s AND practitioner_user_id = %d
                 ORDER BY id DESC LIMIT 1",
                $lead->visitor_email, $user_id
            ) );
            return rest_ensure_response( array(
                'success'    => true,
                'already'    => true,
                'form_token' => (string) $existing_token,
            ) );
        }
        if ( $lead->status === 'rejected' ) {
            return new WP_Error( 'lead_rejected', 'This lead was previously rejected. To re-action, the visitor must resubmit the widget.', array( 'status' => 409 ) );
        }

        // Decode stage1 answers — schema stores JSON, we need an array for
        // complete_signup().
        $stage1 = array();
        if ( ! empty( $lead->stage1_data ) ) {
            $decoded = json_decode( $lead->stage1_data, true );
            if ( is_array( $decoded ) ) {
                $stage1 = $decoded;
            }
        }

        $config = $this->get_config( $user_id );

        $form_token = self::complete_signup( array(
            'practitioner_id' => $user_id,
            'visitor_name'    => (string) $lead->visitor_name,
            'visitor_email'   => (string) $lead->visitor_email,
            'visitor_phone'   => '', // not captured on widget_leads pre-v0.35.0
            'visitor_age'     => $lead->visitor_age !== null ? (int) $lead->visitor_age : null,
            'rate'            => $lead->rate_of_ageing !== null ? (float) $lead->rate_of_ageing : null,
            'stage1_data'     => $stage1,
            'config'          => $config,
            // Both Make.com fan-out emails (client + practitioner) and the
            // practitioner notify email already fired at widget submission.
            // Don't refire on Confirm — that'd duplicate the PDF email
            // (client) and the New Lead email (practitioner). Confirm's only
            // outbound surface is the magic-link email below.
            'send_practitioner_notify' => false,
            'send_make_pdf'            => false,
        ) );

        if ( ! $form_token ) {
            return new WP_Error( 'confirm_failed', 'Could not create assessment session for this lead.', array( 'status' => 500 ) );
        }

        // Mark confirmed BEFORE sending email so a wp_mail timeout doesn't
        // leave us in a weird "the lead is still pending but the email went
        // out" state on retry.
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_widget_leads',
            array(
                'status'       => 'confirmed',
                'confirmed_at' => current_time( 'mysql' ),
            ),
            array( 'id' => $lead_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        // v0.35.0 (Phase O, revised) — send the post-Confirm magic-link
        // email. The Make.com Stage 1 client email already arrived at submit
        // time but it intentionally has no Continue button (that's the spam
        // gate — visitors wait for the practitioner to look at their data).
        // This send_invite_email is the "your practitioner reviewed your
        // snapshot, here's the next step" email. Reuses the existing
        // "You're Invited!" template, but the URL points at /assessment/
        // rather than back at the widget so the visitor lands directly on
        // Stage 2 with their original 9 answers preserved as Stage 1 data.
        $assessment_url = site_url( '/assessment/?token=' . $form_token );
        $expires_at     = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
        $email_sent     = self::send_invite_email( $user_id, (string) $lead->visitor_name, (string) $lead->visitor_email, $assessment_url, $expires_at );
        if ( ! $email_sent ) {
            error_log( sprintf(
                '[HDL V2] Confirm-lead magic-link email failed — practitioner %d, client %s. Lead is confirmed; client must be re-sent the link manually (Sent Invites tab → Resend).',
                $user_id,
                $lead->visitor_email
            ) );
        }

        return rest_ensure_response( array(
            'success'    => true,
            'form_token' => (string) $form_token,
            'email_sent' => (bool) $email_sent,
        ) );
    }

    /**
     * POST /widget/leads/reject — Practitioner rejects a pending lead.
     *
     * Body: { lead_id }
     *
     * Silent: no email, no Make.com webhook, no form_progress row created.
     * Subsequent re-submissions for the same email keep the row in the
     * 'rejected' state (record_widget_lead's UPDATE branch does not touch
     * status) — effective shadow-ban for spam.
     *
     * Idempotent on already-rejected: the WHERE pins status='pending', so
     * re-rejecting matches 0 rows and returns 404 (caller can interpret as
     * "already in terminal state"). Confirm and Reject collide here: the UI
     * disables the row's buttons after either action so the second click
     * never fires; this is just defense-in-depth.
     */
    public function rest_reject_lead( $request ) {
        global $wpdb;
        $user_id = get_current_user_id();

        $params  = $request->get_json_params();
        $lead_id = isset( $params['lead_id'] ) ? absint( $params['lead_id'] ) : 0;
        if ( ! $lead_id ) {
            return new WP_Error( 'missing_lead_id', 'lead_id is required.', array( 'status' => 400 ) );
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'hdlv2_widget_leads',
            array(
                'status'      => 'rejected',
                'rejected_at' => current_time( 'mysql' ),
            ),
            array(
                'id'                   => $lead_id,
                'practitioner_user_id' => $user_id,
                'status'               => 'pending',
            ),
            array( '%s', '%s' ),
            array( '%d', '%d', '%s' )
        );

        if ( $updated === 0 ) {
            return new WP_Error( 'lead_not_found', 'Lead not found or already actioned.', array( 'status' => 404 ) );
        }

        return rest_ensure_response( array( 'success' => true ) );
    }

    /**
     * GET /widget/public-config?practitioner_id=X — Public widget config.
     *
     * Read-only safe-subset for embedded widgets. Embeds carry only
     * data-practitioner-id; this endpoint serves fresh cta_link, cta_text,
     * theme_color, practitioner_name, logo_url, and the
     * show_book_button_after_widget flag so the widget reflects Widget
     * Settings changes without the practitioner re-pasting embed code on
     * every host site.
     *
     * Token-enumeration defense: invalid practitioner_ids return a 200 with
     * config:null so an attacker can't probe which IDs exist via 404 vs 200
     * differentials. Real configs leak no PII beyond the practitioner's
     * display name + logo (already shown publicly on their /clients/ page
     * and on the widget itself).
     */
    public function rest_get_public_config( $request ) {
        $practitioner_id = absint( $request->get_param( 'practitioner_id' ) );
        if ( ! $practitioner_id ) {
            return rest_ensure_response( array( 'config' => null ) );
        }
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT practitioner_name, logo_url, logo_shape, cta_link, cta_text, theme_color,
                    show_book_button_after_widget
             FROM {$wpdb->prefix}hdlv2_widget_config
             WHERE practitioner_user_id = %d LIMIT 1",
            $practitioner_id
        ) );
        if ( ! $row ) {
            return rest_ensure_response( array( 'config' => null ) );
        }
        return rest_ensure_response( array( 'config' => array(
            'practitioner_name'             => (string) $row->practitioner_name,
            'logo_url'                      => (string) $row->logo_url,
            'logo_shape'                    => (string) ( $row->logo_shape ?: 'square' ),
            'cta_link'                      => (string) $row->cta_link,
            'cta_text'                      => (string) $row->cta_text,
            'theme_color'                   => (string) $row->theme_color,
            'show_book_button_after_widget' => ! empty( $row->show_book_button_after_widget ),
        ) ) );
    }

    /**
     * Resend the verification email for an existing pending lead.
     * Public + rate-limited via TIER_PUBLIC.
     * Body: { practitioner_id, email }
     */
    public function rest_resend_verification( $request ) {
        $params          = $request->get_json_params();
        $practitioner_id = isset( $params['practitioner_id'] ) ? absint( $params['practitioner_id'] ) : 0;
        $email           = sanitize_email( $params['email'] ?? '' );

        if ( ! $practitioner_id || ! $email ) {
            return new WP_Error( 'missing_params', 'Practitioner ID and email are required.', array( 'status' => 400 ) );
        }

        // Every post-validation response looks identical — an attacker probing
        // this endpoint must not be able to distinguish "unknown email",
        // "in-cooldown", or "just re-sent" from one another.
        $generic = rest_ensure_response( array(
            'success' => true,
            'message' => 'If a pending submission exists for this email, the verification link has been re-sent. Please check your inbox.',
        ) );

        // Per-recipient cooldown (same key as initial send so attacker can't bypass via /resend)
        $cool_key = 'hdlv2_pendverif_' . md5( $email . '|' . $practitioner_id );
        if ( get_transient( $cool_key ) ) {
            return $generic;
        }

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_pending_leads
             WHERE practitioner_id = %d AND visitor_email = %s AND status = 'pending'
             ORDER BY id DESC LIMIT 1",
            $practitioner_id, $email
        ) );

        if ( ! $row || strtotime( $row->expires_at . ' UTC' ) < time() ) {
            return $generic;
        }

        $config = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_widget_config WHERE practitioner_user_id = %d LIMIT 1",
            $practitioner_id
        ) );

        $verify_url = add_query_arg( 'hdlv2_verify', $row->verify_token, home_url( '/' ) );
        $reject_url = add_query_arg( 'hdlv2_reject', $row->verify_token, home_url( '/' ) );
        $is_returning = (bool) email_exists( $email );

        $sent = self::send_widget_verification_email( array(
            'visitor_email'     => $row->visitor_email,
            'visitor_name'      => $row->visitor_name,
            'practitioner_name' => $config ? $config->practitioner_name : '',
            'rate'              => $row->rate_of_ageing,
            'verify_url'        => $verify_url,
            'reject_url'        => $reject_url,
            'is_returning_user' => $is_returning,
        ), $config );

        if ( $sent ) {
            set_transient( $cool_key, 1, 5 * MINUTE_IN_SECONDS );
        }

        return $generic;
    }

    /**
     * Daily cron: cleanup expired pending leads + send 24h reminder for
     * unverified leads still in the cooling-off window.
     *
     *   - Pending older than 7 days → delete (forensics window over)
     *   - Pending older than expires_at → mark 'expired' (kept until 7d cleanup)
     *   - Pending 24-26h old, no reminder_sent_at → send reminder, mark sent
     */
    public function cron_cleanup_pending() {
        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_pending_leads';

        // 1. Hard delete anything older than 7 days regardless of status
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM $table WHERE created_at < %s",
            gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS )
        ) );

        // 2. Mark expired pendings (older than expires_at, still 'pending')
        $expired = $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET status = 'expired' WHERE status = 'pending' AND expires_at < %s",
            gmdate( 'Y-m-d H:i:s' )
        ) );

        // 3. Send one-shot 24h reminder
        $candidates = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table
             WHERE status = 'pending'
               AND reminder_sent_at IS NULL
               AND created_at < %s
               AND created_at > %s
             LIMIT 100",
            gmdate( 'Y-m-d H:i:s', time() - 24 * HOUR_IN_SECONDS ),
            gmdate( 'Y-m-d H:i:s', time() - 26 * HOUR_IN_SECONDS )
        ) );

        $reminded = 0;
        foreach ( $candidates as $row ) {
            $config = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hdlv2_widget_config WHERE practitioner_user_id = %d LIMIT 1",
                $row->practitioner_id
            ) );
            $verify_url = add_query_arg( 'hdlv2_verify', $row->verify_token, home_url( '/' ) );
            $reject_url = add_query_arg( 'hdlv2_reject', $row->verify_token, home_url( '/' ) );

            $sent = self::send_widget_verification_email( array(
                'visitor_email'     => $row->visitor_email,
                'visitor_name'      => $row->visitor_name,
                'practitioner_name' => $config ? $config->practitioner_name : '',
                'rate'              => $row->rate_of_ageing,
                'verify_url'        => $verify_url,
                'reject_url'        => $reject_url,
                'is_returning_user' => (bool) email_exists( $row->visitor_email ),
            ), $config );

            if ( $sent ) {
                $wpdb->update(
                    $table,
                    array( 'reminder_sent_at' => current_time( 'mysql' ) ),
                    array( 'id' => $row->id )
                );
                $reminded++;
            }
        }

        if ( $deleted || $expired || $reminded ) {
            error_log( sprintf(
                '[HDLV2 widget] Pending cleanup — deleted=%d expired=%d reminded=%d',
                (int) $deleted, (int) $expired, $reminded
            ) );
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: INVITES
    // ──────────────────────────────────────────────────────────────

    /**
     * Create a new invite token for a specific client.
     */
    public function rest_create_invite( $request ) {
        $user_id = get_current_user_id();

        // Rate limit: 10 invites per hour per practitioner
        $transient = 'hdlv2_invite_' . $user_id;
        $count     = (int) get_transient( $transient );
        if ( $count >= 10 ) {
            return new WP_Error( 'rate_limited', 'Too many invites. Please try again later.', array( 'status' => 429 ) );
        }

        $params       = $request->get_json_params();
        $client_name  = sanitize_text_field( $params['client_name'] ?? '' );
        $client_email = sanitize_email( $params['client_email'] ?? '' );

        if ( ! $client_email || ! is_email( $client_email ) ) {
            return new WP_Error( 'invalid_email', 'A valid client email is required.', array( 'status' => 400 ) );
        }

        // Phase 18D — accept expires_minutes (preferred) with expires_days fallback for back-compat.
        // Whitelist: 30 minutes / 2 hours / 24 hours / 7 days / 30 days. Default 24h.
        $expires_minutes = absint( $params['expires_minutes'] ?? 0 );
        if ( $expires_minutes <= 0 ) {
            $expires_days_in = absint( $params['expires_days'] ?? 0 );
            if ( in_array( $expires_days_in, array( 7, 14, 30 ), true ) ) {
                $expires_minutes = $expires_days_in * 1440;
            }
        }
        if ( ! in_array( $expires_minutes, array( 30, 120, 1440, 10080, 43200 ), true ) ) {
            $expires_minutes = 1440; // 24h default
        }

        $token      = bin2hex( random_bytes( 32 ) );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $expires_minutes * MINUTE_IN_SECONDS ) );

        // v0.29.0 — snapshot prior public-widget answers into the invite if
        // the same email already submitted the public widget for this
        // practitioner. The invite is stable from this point: when the
        // recipient opens the link, they land on the widget with the 9
        // answers pre-selected (still editable), then continue to Stage 2.
        $prefill_lead   = self::lookup_widget_lead( $user_id, $client_email );
        $prefill_json   = $prefill_lead && ! empty( $prefill_lead->stage1_data ) ? $prefill_lead->stage1_data : null;
        $prefill_found  = ! empty( $prefill_json );

        global $wpdb;
        $insert_data = array(
            'practitioner_id' => $user_id,
            'token'           => $token,
            'client_name'     => $client_name,
            'client_email'    => $client_email,
            'status'          => 'pending',
            'expires_at'      => $expires_at,
        );
        $insert_format = array( '%d', '%s', '%s', '%s', '%s', '%s' );
        if ( $prefill_json ) {
            $insert_data['prefill_stage1'] = $prefill_json;
            $insert_format[]               = '%s';
        }
        $wpdb->insert( $wpdb->prefix . 'hdlv2_widget_invites', $insert_data, $insert_format );

        set_transient( $transient, $count + 1, HOUR_IN_SECONDS );

        $url = site_url( '/rate-of-ageing-widget/?invite=' . $token );

        // v0.22.24 — Send invite email server-side. Previously this endpoint
        // only generated a URL and the practitioner had to copy/paste it
        // manually. The new tutorial-section invite modal advertises an emailed
        // link, so we now send via wp_mail. Failure is logged but does not
        // fail the request — the URL is still returned for manual fallback.
        if ( $client_email && is_email( $client_email ) ) {
            $email_sent = self::send_invite_email( $user_id, $client_name, $client_email, $url, $expires_at );
            if ( ! $email_sent ) {
                error_log( sprintf(
                    '[HDL V2] Invite email failed — practitioner %d, client %s',
                    $user_id,
                    $client_email
                ) );
            }
        }

        return rest_ensure_response( array(
            'success'              => true,
            'token'                => $token,
            'url'                  => $url,
            'expires_at'           => $expires_at,
            'prefill_found'        => $prefill_found,
            'prefill_submitted_at' => $prefill_found ? $prefill_lead->created_at : null,
            'prefill_rate'         => $prefill_found ? (float) $prefill_lead->rate_of_ageing : null,
        ) );
    }

    /**
     * Send the personal-invite email. Static helper — single source of truth
     * for both REST (rest_create_invite) and AJAX (ajax_create_invite) paths.
     * Returns true if wp_mail accepted the message.
     *
     * Layout matches Matthew's "HDL Testing 27/04/2026_02" mock-up:
     *   - Teal header with HDL logo (left) + practitioner logo + name (right)
     *   - "You're Invited!" headline + subhead naming the practitioner
     *   - Body: Dear {first_name} + body sentence + CTA button
     *   - Optional video card (renders only if HDLV2_INVITE_VIDEO_URL is set)
     *   - Expiry copy + practitioner contact line + Powered-by footer
     *
     * Practitioner logo gracefully omits when widget_config.logo_url is empty —
     * no broken <img>, no error tile (Quim 2026-04-28).
     *
     * @since 0.22.24 — rewritten 0.22.35 for V2 layout + email-path unification.
     */
    public static function send_invite_email( $practitioner_id, $client_name, $client_email, $invite_url, $expires_at ) {
        $practitioner_user = get_userdata( $practitioner_id );
        if ( ! $practitioner_user ) {
            return false;
        }

        // Prefer widget_config.practitioner_name (the brand the practitioner
        // chose for the widget). Fall back to WP display_name.
        global $wpdb;
        $config = $wpdb->get_row( $wpdb->prepare(
            "SELECT practitioner_name FROM {$wpdb->prefix}hdlv2_widget_config WHERE practitioner_user_id = %d LIMIT 1",
            $practitioner_id
        ) );
        $practitioner_name  = $config && ! empty( $config->practitioner_name )
            ? $config->practitioner_name
            : $practitioner_user->display_name;
        $practitioner_email = $practitioner_user->user_email;

        // v0.36.23 — single derive-first-name helper avoids the previous
        // "Dear matthewdhaemer+test080526@…" bleed-through when an early
        // signup form had empty name and copied the email into client_name.
        $first_name = HDLV2_Email_Templates::derive_first_name( $client_name, $client_email );

        // Phase 18D email formatter — sub-24h windows include time-of-day,
        // longer windows render date-only. wp_date renders in the WP site
        // timezone, not UTC.
        $expires_disp = '';
        if ( $expires_at ) {
            $exp_ts     = strtotime( $expires_at . ' UTC' );
            $hours_left = max( 0, ( $exp_ts - time() ) / HOUR_IN_SECONDS );
            $expires_disp = $hours_left < 24
                ? wp_date( 'F j, Y \a\t g:i a', $exp_ts )
                : wp_date( 'F j, Y', $exp_ts );
        }

        // Optional invite-email video card — opt-in via wp-config constant.
        $video_url   = defined( 'HDLV2_INVITE_VIDEO_URL' ) ? HDLV2_INVITE_VIDEO_URL : '';
        $video_title = defined( 'HDLV2_INVITE_VIDEO_TITLE' ) && HDLV2_INVITE_VIDEO_TITLE
            ? HDLV2_INVITE_VIDEO_TITLE
            : 'Watch: Why getting clarity on how you\'re ageing matters';

        // v0.36.18 — Stage 2 invite subject: standardised "Stage 2" naming
        // and explicit "Practitioner" prefix so the client knows the email
        // is from their practitioner, not a generic marketing blast.
        $subject = sprintf( 'Practitioner %s invites you to complete the Stage 2 form — Why Longevity', $practitioner_name );

        // ── Body ───────────────────────────────────────────────────────────
        $body  = '<p style="margin:0 0 16px;font-size:16px;color:#1a1a1a;font-family:Inter,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">Dear ' . esc_html( $first_name ) . ',</p>';
        $body .= '<p style="margin:0 0 16px;font-size:15px;color:#333333;line-height:1.7;font-family:Inter,-apple-system,sans-serif;">Your practitioner, <strong style="color:#1a1a1a;">' . esc_html( $practitioner_name ) . '</strong>, has invited you to complete Stage 2 of your Longevity assessment.</p>';
        $body .= '<p style="margin:0 0 16px;font-size:15px;color:#333333;line-height:1.7;font-family:Inter,-apple-system,sans-serif;">This is the <em style="color:#004F59;font-style:italic;">Why</em>. In your own words, what matters to you and why is this work worth doing? Your answers will feed into your reports and weekly flight plans, and on days when motivation dips, your reflections will help you find your footing again.</p>';
        $body .= '<p style="margin:0 0 22px;font-size:15px;color:#333333;line-height:1.7;font-family:Inter,-apple-system,sans-serif;">Set aside 5&ndash;20 minutes in a quiet place and complete it in one sitting.</p>';

        // CTA button — editorial deep-teal pattern.
        $body .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:8px auto 24px;"><tr>';
        $body .= '<td align="center" bgcolor="#004F59" style="border-radius:2px;">';
        $body .= '<a href="' . esc_url( $invite_url ) . '" target="_blank" style="display:inline-block;padding:14px 30px;font-size:13.5px;font-weight:600;letter-spacing:0.04em;color:#ffffff;text-decoration:none;font-family:Inter,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">Begin Assessment &rarr;</a>';
        $body .= '</td></tr></table>';

        // Optional video card.
        if ( $video_url ) {
            $body .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:8px 0 24px;"><tr><td>';
            $body .= '<a href="' . esc_url( $video_url ) . '" target="_blank" style="text-decoration:none;color:inherit;display:block;">';
            $body .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#eef7f9;border:1px solid #d0e8ed;border-radius:10px;"><tr>';
            $body .= '<td style="padding:22px 24px;text-align:center;">';
            $body .= '<div style="display:inline-block;width:44px;height:44px;background:#3d8da0;border-radius:50%;text-align:center;line-height:44px;color:#ffffff;font-size:16px;">&#9654;</div>';
            $body .= '<p style="margin:10px 0 0;font-size:14px;color:#1a1a1a;font-weight:500;line-height:1.4;font-family:Inter,-apple-system,sans-serif;">' . esc_html( $video_title ) . '</p>';
            $body .= '</td></tr></table></a></td></tr></table>';
        }

        // Expiry + contact lines grouped inside a single soft-fill card.
        $body .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:6px 0 0;"><tr><td style="background:#f5f6f8;border:1px solid #e4e6ea;border-radius:10px;padding:18px 22px;font-family:Inter,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">';
        if ( $expires_disp ) {
            $body .= '<p style="margin:0 0 12px;font-size:13px;color:#666666;text-align:center;line-height:1.5;">This link is valid until <strong style="color:#1a1a1a;">' . esc_html( $expires_disp ) . '</strong>. If you need more time, just ask <strong style="color:#1a1a1a;">' . esc_html( $practitioner_name ) . '</strong> for a fresh one.</p>';
            $body .= '<p style="margin:0;font-size:13px;color:#666666;text-align:center;line-height:1.5;border-top:1px solid #e4e6ea;padding-top:12px;">Any questions? Please contact <a href="mailto:' . esc_attr( $practitioner_email ) . '" style="color:#3d8da0;text-decoration:underline;font-weight:500;">' . esc_html( $practitioner_name ) . '</a>.</p>';
        } else {
            $body .= '<p style="margin:0;font-size:13px;color:#666666;text-align:center;line-height:1.5;">Any questions? Please contact <a href="mailto:' . esc_attr( $practitioner_email ) . '" style="color:#3d8da0;text-decoration:underline;font-weight:500;">' . esc_html( $practitioner_name ) . '</a>.</p>';
        }
        $body .= '</td></tr></table>';

        // v0.36.23 — outer shell + header + footer now come from the
        // shared HDLV2_Email_Templates::base_layout() so every email
        // reads as one design system. Banner title and practitioner
        // logo/name are resolved inside the shared header renderer.
        $message = HDLV2_Email_Templates::base_layout( $body, (int) $practitioner_id, 'Stage 2 form — Why Longevity' );

        // v0.36.23 — Reply-To display-name now quoted so apostrophes
        // ("Matthew D'haemer") survive RFC 5322 parsing in Gmail /
        // Outlook MUAs. Pre-v0.36.23 the bare display-name lost the
        // apostrophe in some clients ("Matthew Dhaemer"). Same defensive
        // quoting applied to From header for future-proofing.
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: "HealthDataLab" <noreply@healthdatalab.net>',
            'Reply-To: "' . addcslashes( $practitioner_name, '\\"' ) . '" <' . $practitioner_email . '>',
        );

        return wp_mail( $client_email, $subject, $message, $headers );
    }

    /**
     * Verify an invite token (public endpoint, called by widget JS).
     *
     * Rate limited to prevent token enumeration. Failed attempts are logged.
     */
    public function rest_verify_invite( $request ) {
        $token = sanitize_text_field( $request->get_param( 'token' ) ?? '' );

        // Validate token format: exactly 64 hex chars
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            return rest_ensure_response( array( 'valid' => false, 'reason' => 'invalid' ) );
        }

        // Rate limit: 30 verifications per hour per IP
        $ip        = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
        $transient = 'hdlv2_verify_' . md5( $ip );
        $count     = (int) get_transient( $transient );
        if ( $count >= 30 ) {
            error_log( sprintf(
                '[HDL V2 SECURITY] Invite verify rate limit hit — IP: %s, attempts: %d',
                $ip,
                $count
            ) );
            return new WP_Error( 'rate_limited', 'Too many requests.', array( 'status' => 429 ) );
        }
        set_transient( $transient, $count + 1, HOUR_IN_SECONDS );

        global $wpdb;
        $invite = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_widget_invites WHERE token = %s LIMIT 1",
            $token
        ) );

        if ( ! $invite ) {
            error_log( sprintf(
                '[HDL V2 SECURITY] Invalid invite token attempted — IP: %s, token prefix: %s...',
                $ip,
                substr( $token, 0, 8 )
            ) );
            return rest_ensure_response( array( 'valid' => false, 'reason' => 'invalid' ) );
        }

        if ( $invite->status === 'revoked' ) {
            return rest_ensure_response( array( 'valid' => false, 'reason' => 'invalid' ) );
        }

        if ( $invite->status === 'completed' ) {
            return rest_ensure_response( array( 'valid' => false, 'reason' => 'completed' ) );
        }

        if ( strtotime( $invite->expires_at . ' UTC' ) < time() ) {
            // Mark as expired if still pending/opened
            if ( in_array( $invite->status, array( 'pending', 'opened' ), true ) ) {
                $wpdb->update(
                    $wpdb->prefix . 'hdlv2_widget_invites',
                    array( 'status' => 'expired' ),
                    array( 'id' => $invite->id ),
                    array( '%s' ),
                    array( '%d' )
                );
            }
            return rest_ensure_response( array( 'valid' => false, 'reason' => 'expired' ) );
        }

        // Mark as opened on first verification
        if ( $invite->status === 'pending' ) {
            $wpdb->update(
                $wpdb->prefix . 'hdlv2_widget_invites',
                array(
                    'status'    => 'opened',
                    'opened_at' => current_time( 'mysql' ),
                ),
                array( 'id' => $invite->id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
        }

        // Load practitioner widget config
        $config = $this->get_config( $invite->practitioner_id );

        // v0.29.0 — return prior-submission answers so the widget JS can
        // pre-select Q1-Q9 on the invite path. Whitelisted to the 11 known
        // keys to avoid echoing arbitrary stored JSON to a public endpoint
        // (defence in depth — even though the data only got into invites
        // via our own rest_create_invite snapshot path).
        $prefill = null;
        if ( ! empty( $invite->prefill_stage1 ) ) {
            $decoded = json_decode( $invite->prefill_stage1, true );
            if ( is_array( $decoded ) ) {
                $allowed = array( 'q1_age', 'q1_sex', 'q2a', 'q2b', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8', 'q9' );
                $prefill = array();
                foreach ( $allowed as $key ) {
                    if ( ! array_key_exists( $key, $decoded ) ) {
                        continue;
                    }
                    $val = $decoded[ $key ];
                    if ( $key === 'q1_age' || $key === 'q2a' ) {
                        $prefill[ $key ] = (int) $val;
                    } elseif ( $key === 'q1_sex' ) {
                        $sex = strtolower( (string) $val );
                        if ( $sex === 'male' || $sex === 'female' ) {
                            $prefill[ $key ] = $sex;
                        }
                    } else {
                        // q2b ('apple'|'pear'|'even') or q3-q9 letter answers.
                        $prefill[ $key ] = sanitize_text_field( (string) $val );
                    }
                }
                if ( empty( $prefill ) ) {
                    $prefill = null;
                }
            }
        }

        return rest_ensure_response( array(
            'valid'             => true,
            'practitioner_id'                => (int) $invite->practitioner_id,
            'client_name'                    => $invite->client_name,
            'client_email'                   => $invite->client_email,
            'practitioner_name'              => $config ? $config->practitioner_name : '',
            'logo_url'                       => $config ? $config->logo_url : '',
            'logo_shape'                     => $config && ! empty( $config->logo_shape ) ? (string) $config->logo_shape : 'square',
            'cta_text'                       => $config ? $config->cta_text : 'Book a session',
            'cta_link'                       => $config ? $config->cta_link : '',
            'theme_color'                    => $config ? $config->theme_color : '#3d8da0',
            'show_book_button_after_widget'  => $config ? ! empty( $config->show_book_button_after_widget ) : false,
            'api_url'                        => rest_url( 'hdl-v2/v1/widget/lead' ),
            'prefill_stage1'                 => $prefill,
        ) );
    }

    /**
     * List all invites for the current practitioner.
     */
    public function rest_list_invites( $request ) {
        global $wpdb;
        $user_id = get_current_user_id();

        // First, expire any overdue invites
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}hdlv2_widget_invites
             SET status = 'expired'
             WHERE practitioner_id = %d
               AND status IN ('pending', 'opened')
               AND expires_at < %s",
            $user_id,
            current_time( 'mysql' )
        ) );

        $invites = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, client_name, client_email, status, token, expires_at, created_at, opened_at, completed_at
             FROM {$wpdb->prefix}hdlv2_widget_invites
             WHERE practitioner_id = %d
             ORDER BY created_at DESC
             LIMIT 100",
            $user_id
        ) );

        $widget_page = site_url( '/rate-of-ageing-widget/' );
        foreach ( $invites as &$inv ) {
            $inv->url = $widget_page . '?invite=' . $inv->token;
            unset( $inv->token ); // Don't expose raw tokens in list responses
        }

        return rest_ensure_response( $invites );
    }

    // ──────────────────────────────────────────────────────────────
    //  AJAX HANDLERS
    // ──────────────────────────────────────────────────────────────

    public function ajax_save_config() {
        check_ajax_referer( 'hdlv2_widget_config', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            wp_send_json_error( 'Not authorized' );
        }

        $data = $this->sanitize_config( $_POST );

        // v0.29.1 — both fields required server-side. Pre-flight UI in
        // saveConfig() catches the common case; this is the safety-net.
        if ( empty( $data['cta_link'] ) ) {
            wp_send_json_error( 'Booking Link is required' );
        }
        if ( empty( $data['notification_email'] ) || ! is_email( $data['notification_email'] ) ) {
            wp_send_json_error( 'A valid lead notification email is required' );
        }

        $result = $this->save_config( $user_id, $data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'embed_code' => HDLV2_Widget_Renderer::generate_embed_code( $user_id, $data ),
        ) );
    }

    public function ajax_get_config() {
        check_ajax_referer( 'hdlv2_widget_config', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            wp_send_json_error( 'Not authorized' );
        }

        $config = $this->get_config( $user_id );
        wp_send_json_success( $config ?: array() );
    }

    public function ajax_upload_logo() {
        check_ajax_referer( 'hdlv2_widget_config', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            wp_send_json_error( 'Not authorised' );
        }

        if ( empty( $_FILES['logo'] ) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( 'No file uploaded or upload error' );
        }

        $file = $_FILES['logo'];

        // Validate type. v0.29.1 — SVG removed: SVG can carry inline <script>
        // and would execute on the practitioner's host site when rendered as
        // an <img>. Practitioner logos render inside a 36-42px pill on every
        // surface; raster handles that perfectly.
        $allowed = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
        $finfo   = finfo_open( FILEINFO_MIME_TYPE );
        $mime    = finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );

        if ( ! in_array( $mime, $allowed, true ) ) {
            wp_send_json_error( 'Only image files are allowed (JPG, PNG, GIF, WebP)' );
        }

        // Validate size (5MB max — v0.29.1 raised from 2MB per practitioner feedback)
        if ( $file['size'] > 5 * 1024 * 1024 ) {
            wp_send_json_error( 'Logo must be under 5MB' );
        }

        // Upload to isolated directory (NOT wp media library)
        $upload_dir = wp_upload_dir();
        $logo_dir   = $upload_dir['basedir'] . '/hdlv2-logos/' . $user_id;
        wp_mkdir_p( $logo_dir );

        $filename = sanitize_file_name( $file['name'] );
        // Prevent overwrites by prefixing with timestamp
        $filename = time() . '-' . $filename;
        $dest     = $logo_dir . '/' . $filename;

        if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
            wp_send_json_error( 'Failed to save file' );
        }

        // File rotation (v0.29.4) — now that the new logo is safely on disk,
        // delete prior logos in this practitioner's directory so we don't
        // accumulate disk waste over repeated re-uploads. Run AFTER the new
        // file is in place, so a failed move_uploaded_file above can't leave
        // the practitioner with no logo at all. Only deletes regular files;
        // any sub-directories (none expected, but defensive) are left alone.
        $existing = glob( $logo_dir . '/*' );
        if ( is_array( $existing ) ) {
            foreach ( $existing as $old ) {
                if ( $old !== $dest && is_file( $old ) ) {
                    @unlink( $old );
                }
            }
        }

        $url = $upload_dir['baseurl'] . '/hdlv2-logos/' . $user_id . '/' . $filename;

        // v0.36.0 (Phase P) — Server-detect logo aspect ratio at upload
        // and stamp it on widget_config so every render path (widget JS,
        // invite email, result page, PDF) renders the same logo with the
        // same shape-aware container. No client-side shape detection.
        //
        // Reads the just-saved file directly from disk (cheap — file is
        // already verified as a raster image type above; finfo + size
        // checks already passed). Falls back to 'square' on any failure
        // so the widget never renders without a shape attribute.
        $shape = 'square';
        $size  = @getimagesize( $dest );
        if ( is_array( $size ) && ! empty( $size[0] ) && ! empty( $size[1] ) ) {
            $shape = HDLV2_Activator::classify_logo_shape( (int) $size[0], (int) $size[1] );
        }

        // Persist URL + shape together. Done here (not deferred to the
        // separate save_config call) so the shape is server-of-record:
        // the practitioner can never edit it client-side, can never
        // mismatch what the upload actually contained.
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_widget_config',
            array(
                'logo_url'   => $url,
                'logo_shape' => $shape,
            ),
            array( 'practitioner_user_id' => $user_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        wp_send_json_success( array(
            'url'   => $url,
            'shape' => $shape,
        ) );
    }

    public function ajax_create_invite() {
        check_ajax_referer( 'hdlv2_widget_config', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            wp_send_json_error( 'Not authorized' );
        }

        // Rate limit: 10 invites per hour per practitioner
        $transient = 'hdlv2_invite_' . $user_id;
        $count     = (int) get_transient( $transient );
        if ( $count >= 10 ) {
            wp_send_json_error( 'Too many invites this hour. Please try again later.' );
        }

        $client_name  = sanitize_text_field( $_POST['client_name'] ?? '' );
        $client_email = sanitize_email( $_POST['client_email'] ?? '' );

        if ( ! $client_email || ! is_email( $client_email ) ) {
            wp_send_json_error( 'A valid client email is required.' );
        }

        // Phase 18D — accept expires_minutes (preferred) with expires_days fallback for back-compat.
        // Whitelist: 30 minutes / 2 hours / 24 hours / 7 days / 30 days. Default 24h.
        $expires_minutes = absint( $_POST['expires_minutes'] ?? 0 );
        if ( $expires_minutes <= 0 ) {
            $expires_days_in = absint( $_POST['expires_days'] ?? 0 );
            if ( in_array( $expires_days_in, array( 7, 14, 30 ), true ) ) {
                $expires_minutes = $expires_days_in * 1440;
            }
        }
        if ( ! in_array( $expires_minutes, array( 30, 120, 1440, 10080, 43200 ), true ) ) {
            $expires_minutes = 1440; // 24h default
        }

        $token      = bin2hex( random_bytes( 32 ) );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $expires_minutes * MINUTE_IN_SECONDS ) );

        // v0.29.0 — snapshot prior public-widget answers into the invite (see
        // rest_create_invite for the long-form rationale).
        $prefill_lead  = self::lookup_widget_lead( $user_id, $client_email );
        $prefill_json  = $prefill_lead && ! empty( $prefill_lead->stage1_data ) ? $prefill_lead->stage1_data : null;
        $prefill_found = ! empty( $prefill_json );

        global $wpdb;
        $insert_data = array(
            'practitioner_id' => $user_id,
            'token'           => $token,
            'client_name'     => $client_name,
            'client_email'    => $client_email,
            'status'          => 'pending',
            'expires_at'      => $expires_at,
        );
        $insert_format = array( '%d', '%s', '%s', '%s', '%s', '%s' );
        if ( $prefill_json ) {
            $insert_data['prefill_stage1'] = $prefill_json;
            $insert_format[]               = '%s';
        }
        $wpdb->insert( $wpdb->prefix . 'hdlv2_widget_invites', $insert_data, $insert_format );

        set_transient( $transient, $count + 1, HOUR_IN_SECONDS );

        $url = site_url( '/rate-of-ageing-widget/?invite=' . $token );

        // v0.22.35 — single source of truth: both REST and AJAX paths now use
        // the static send_invite_email so practitioners always get the same
        // V2-branded email regardless of which call site fired.
        $email_sent = self::send_invite_email( $user_id, $client_name, $client_email, $url, $expires_at );

        wp_send_json_success( array(
            'url'                  => $url,
            'expires_at'           => $expires_at,
            'email_sent'           => $email_sent,
            'prefill_found'        => $prefill_found,
            'prefill_submitted_at' => $prefill_found ? $prefill_lead->created_at : null,
            'prefill_rate'         => $prefill_found ? (float) $prefill_lead->rate_of_ageing : null,
        ) );
    }

    /**
     * v0.29.0 — Live lookup for the Send-Invites form. Practitioner types an
     * email; we report whether a prior public-widget submission for the same
     * (practitioner, email) pair exists so they know whether the upcoming
     * invite link will arrive pre-filled. Returns submitted_at + rate so the
     * UI can render a meaningful tooltip.
     */
    public function ajax_lead_lookup() {
        check_ajax_referer( 'hdlv2_widget_config', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            wp_send_json_error( 'Not authorized' );
        }

        $email = sanitize_email( $_POST['email'] ?? '' );
        if ( ! $email || ! is_email( $email ) ) {
            wp_send_json_success( array( 'found' => false ) );
        }

        $row = self::lookup_widget_lead( $user_id, $email );
        if ( ! $row || empty( $row->stage1_data ) ) {
            wp_send_json_success( array( 'found' => false ) );
        }

        wp_send_json_success( array(
            'found'         => true,
            'submitted_at'  => $row->created_at,
            'rate'          => isset( $row->rate_of_ageing ) ? (float) $row->rate_of_ageing : null,
            'visitor_name'  => $row->visitor_name ?: '',
        ) );
    }

    public function ajax_get_invites() {
        check_ajax_referer( 'hdlv2_widget_config', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            wp_send_json_error( 'Not authorized' );
        }

        global $wpdb;

        // Expire overdue invites
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}hdlv2_widget_invites
             SET status = 'expired'
             WHERE practitioner_id = %d
               AND status IN ('pending', 'opened')
               AND expires_at < %s",
            $user_id,
            current_time( 'mysql' )
        ) );

        $invites = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, client_name, client_email, status, token, expires_at, created_at, opened_at, completed_at
             FROM {$wpdb->prefix}hdlv2_widget_invites
             WHERE practitioner_id = %d
             ORDER BY created_at DESC
             LIMIT 100",
            $user_id
        ) );

        $widget_page = site_url( '/rate-of-ageing-widget/' );
        foreach ( $invites as &$inv ) {
            $inv->url = $widget_page . '?invite=' . $inv->token;
            unset( $inv->token );
        }

        wp_send_json_success( $invites );
    }

    // ──────────────────────────────────────────────────────────────
    //  INVITE EMAIL
    // ──────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────
    //  INVITE HELPERS
    // ──────────────────────────────────────────────────────────────

    /**
     * Look up an invite by token and verify it's still usable.
     *
     * @param string $token The 64-char hex token.
     * @return object|null The invite row if valid, null otherwise.
     */
    private function get_valid_invite( $token ) {
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            return null;
        }

        global $wpdb;
        $invite = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_widget_invites WHERE token = %s LIMIT 1",
            $token
        ) );

        if ( ! $invite ) {
            return null;
        }

        if ( $invite->status === 'completed' || $invite->status === 'revoked' ) {
            return null;
        }

        if ( strtotime( $invite->expires_at . ' UTC' ) < time() ) {
            return null;
        }

        return $invite;
    }

    /**
     * Mark an invite as completed.
     */
    private function complete_invite( $invite_id ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_widget_invites',
            array(
                'status'       => 'completed',
                'completed_at' => current_time( 'mysql' ),
            ),
            array( 'id' => $invite_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  CONFIG DB HELPERS
    // ──────────────────────────────────────────────────────────────

    public function get_config( $user_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_widget_config WHERE practitioner_user_id = %d",
            $user_id
        ) );
    }

    private function save_config( $user_id, $data ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'hdlv2_widget_config';
        $existing = $this->get_config( $user_id );

        $row = array(
            'practitioner_user_id'          => $user_id,
            'practitioner_name'             => $data['practitioner_name'] ?? '',
            'logo_url'                      => $data['logo_url'] ?? '',
            'cta_text'                      => $data['cta_text'] ?? 'Book a session',
            'cta_link'                      => $data['cta_link'] ?? '',
            'webhook_url'                   => $data['webhook_url'] ?? '',
            'notification_email'            => $data['notification_email'] ?? '',
            'theme_color'                   => $data['theme_color'] ?? '#3d8da0',
            // v0.35.0 (Phase O) — per-practitioner toggle for showing the
            // Book-a-Session secondary button on the public widget result
            // page. OFF by default per Matthew's "no button on public-path
            // result page" rule. Invite path always honours cta_link.
            'show_book_button_after_widget' => ! empty( $data['show_book_button_after_widget'] ) ? 1 : 0,
        );

        if ( $existing ) {
            $wpdb->update( $table, $row, array( 'practitioner_user_id' => $user_id ) );
        } else {
            $wpdb->insert( $table, $row );
        }

        return true;
    }

    private function sanitize_config( $data ) {
        // v0.40.8 — Reject cta_link / webhook_url values that don't carry an
        // http(s) scheme. `esc_url_raw()` strips bad chars but silently
        // accepts schemeless strings like "calendly.com/me" → rendered as
        // a relative URL on the widget result page → 404 on Book CTA click.
        // `wp_http_validate_url()` enforces a real absolute URL.
        $cta_in     = trim( (string) ( $data['cta_link']    ?? '' ) );
        $webhook_in = trim( (string) ( $data['webhook_url'] ?? '' ) );
        $cta_clean     = ( $cta_in     !== '' && ! wp_http_validate_url( $cta_in ) )     ? '' : esc_url_raw( $cta_in );
        $webhook_clean = ( $webhook_in !== '' && ! wp_http_validate_url( $webhook_in ) ) ? '' : esc_url_raw( $webhook_in );

        return array(
            'practitioner_name'             => sanitize_text_field( $data['practitioner_name'] ?? '' ),
            'logo_url'                      => esc_url_raw( $data['logo_url'] ?? '' ),
            'cta_text'                      => sanitize_text_field( $data['cta_text'] ?? 'Book a session' ),
            'cta_link'                      => $cta_clean,
            'webhook_url'                   => $webhook_clean,
            'notification_email'            => sanitize_email( $data['notification_email'] ?? '' ),
            'theme_color'                   => sanitize_hex_color( $data['theme_color'] ?? '#3d8da0' ) ?: '#3d8da0',
            'show_book_button_after_widget' => ! empty( $data['show_book_button_after_widget'] ) ? 1 : 0,
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  V2 CLIENT LIST + WHY GATE RELEASE
    // ──────────────────────────────────────────────────────────────

    public function ajax_get_v2_clients() {
        check_ajax_referer( 'hdlv2_widget_config', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            wp_send_json_error( 'Not authorized' );
        }

        global $wpdb;
        $clients = $wpdb->get_results( $wpdb->prepare(
            "SELECT fp.id, fp.client_name, fp.client_email, fp.current_stage,
                    fp.stage1_completed_at, fp.stage2_completed_at, fp.stage3_completed_at,
                    fp.stage2_webhook_fired_at,
                    wp.id AS why_id, wp.released, wp.released_at, LEFT(wp.distilled_why, 120) AS why_preview
             FROM {$wpdb->prefix}hdlv2_form_progress fp
             LEFT JOIN {$wpdb->prefix}hdlv2_why_profiles wp ON wp.form_progress_id = fp.id
             WHERE fp.practitioner_user_id = %d
             ORDER BY fp.id DESC
             LIMIT 50",
            $user_id
        ) );

        wp_send_json_success( $clients );
    }

    public function ajax_release_why() {
        check_ajax_referer( 'hdlv2_widget_config', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            wp_send_json_error( 'Not authorized' );
        }

        $progress_id = absint( $_POST['progress_id'] ?? 0 );
        if ( ! $progress_id ) {
            wp_send_json_error( 'Missing progress ID' );
        }
        // v0.25.0 — delegate to shared helper (B7 dedup).
        $result = HDLV2_Compatibility::advance_to_stage_3( $progress_id, $user_id );
        if ( ! $result['ok'] ) {
            wp_send_json_error( $result['message'] );
        }
        if ( ! empty( $result['already_released'] ) ) {
            wp_send_json_success( array( 'already_released' => true ) );
            return;
        }
        wp_send_json_success( array( 'released' => true ) );
    }
}
