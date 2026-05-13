<?php
/**
 * V2 Practitioner Dashboard — shortcode wrapper.
 *
 * Hydrates the practitioner's V2 client roster via the existing
 * /dashboard/clients REST endpoint. This class only wires the shortcode
 * and asset enqueue; all list/status/sort logic lives in JS + the REST
 * endpoint in class-hdlv2-client-status.php.
 *
 * Tutorial section (always-visible getting-started block):
 *   - Rendered server-side both inside the [hdlv2_practitioner_dashboard]
 *     shortcode AND on V1's /clients/ page (where practitioners actually
 *     spend their time). The tutorial HTML is identical in both contexts;
 *     this class is the single source of truth.
 *   - Per Matthew's design note (April 2026): the tutorial sits below the
 *     live client list and slowly moves off-screen as the practitioner
 *     accumulates clients. New practitioners see it prominently.
 *
 * Usage: [hdlv2_practitioner_dashboard]
 *
 * @package HDL_Longevity_V2
 * @since 0.9.6
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class HDLV2_Practitioner_Dashboard {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {}

    public function register_hooks() {
        add_shortcode( 'hdlv2_practitioner_dashboard', array( $this, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_list_enhancer' ) );
        // v0.35.4 (Quim 2026-05-07) — page-level access gate + parallel-
        // dashboard consolidation. Priority 5 so the gate runs BEFORE UM's
        // content-restriction filter (which would otherwise 404 logged-out
        // users before our redirect logic gets a chance to fire).
        add_action( 'template_redirect', array( $this, 'gate_practitioner_pages' ), 5 );
    }

    /**
     * v0.35.4 — Page-level access gate for the two practitioner surfaces:
     *   • /clients/                  (V1 [health_tracker_dashboard])
     *   • /practitioner-dashboard/   (V2 [hdlv2_practitioner_dashboard])
     *
     * Three-way redirect via HDLV2_Compatibility::enforce_practitioner_only_page():
     *   • Logged-out                 → /login/?redirect_to=<URL>
     *   • Logged-in non-practitioner → /my-dashboard/
     *   • Practitioner / admin       → continue to step 2 below
     *
     * Step 2 — Surface consolidation:
     * /practitioner-dashboard/ is the V2 React-style mount that shipped
     * before the V1 splice was complete. Now that /clients/ carries the
     * full Phase O surface (action queue + Pending Leads + Client Tools
     * modal + status badges + detail panels), maintaining a parallel
     * dashboard creates surface-drift risk: every future practitioner
     * change has to ship twice. Per Quim 2026-05-07: redirect
     * /practitioner-dashboard/ to /clients/ for practitioner / admin
     * users. Bookmarks still resolve, no 404, single canonical UI.
     *
     * Non-practitioner role gating happens in step 1 above so a client
     * who accidentally hits /practitioner-dashboard/ never sees the
     * redirect — they go straight to /my-dashboard/.
     */
    public function gate_practitioner_pages() {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }
        global $post;
        if ( ! $post ) {
            return;
        }

        $has_v2_dashboard = has_shortcode( $post->post_content, 'hdlv2_practitioner_dashboard' );
        $has_v1_dashboard = has_shortcode( $post->post_content, 'health_tracker_dashboard' );
        if ( ! $has_v2_dashboard && ! $has_v1_dashboard ) {
            return;
        }

        // Step 1 — Three-way access gate. Returns void (page renders) for
        // practitioner/admin; redirects + exits for everyone else.
        HDLV2_Compatibility::enforce_practitioner_only_page();

        // Step 2 — At this point the visitor is a practitioner or admin.
        // If they landed on /practitioner-dashboard/ specifically (and not
        // a page that ALSO has the V1 shortcode — defensive), forward
        // them to the canonical V1 surface that carries the full Phase O
        // toolset.
        if ( $has_v2_dashboard && ! $has_v1_dashboard ) {
            wp_safe_redirect( home_url( '/clients/' ), 302 );
            exit;
        }
    }

    /**
     * Enqueue the V1 client-list enhancer on any page that renders V1's
     * [health_tracker_dashboard] shortcode. DOM-injects V2 status badges +
     * a chevron-expand detail panel (flight plan, check-ins, timeline,
     * consultation) per row — without touching V1 code.
     *
     * v0.22.24 — also enqueues the tutorial section (CSS + JS + server-rendered
     * markup) so the empty-state mockup appears on /clients/ pages, where
     * practitioners actually live. Detection switched from has_shortcode()
     * to strpos() because Divi's [et_pb_code] wrapper hides nested shortcodes
     * from has_shortcode()'s flat regex — the V2 splicer was previously a
     * silent no-op on every Divi-built /clients/ page.
     */
    public function maybe_enqueue_list_enhancer() {
        if ( is_admin() ) return;
        if ( ! is_user_logged_in() || ! HDLV2_Compatibility::is_practitioner( get_current_user_id() ) ) return;

        global $post;
        if ( ! $post ) return;
        $content = (string) $post->post_content;
        // strpos catches the shortcode whether it's top-level or nested
        // inside a Divi [et_pb_code] / Beaver Builder / Elementor wrapper.
        // has_shortcode() misses nested shortcodes — that was the silent
        // bug that hid V2 splicer from every Divi /clients/ page.
        if ( strpos( $content, '[health_tracker_dashboard' ) === false ) return;

        $user_id = get_current_user_id();

        // v0.20.10 — shared themed confirm dialog. Replaces browser-native
        // confirm() so the Release WHY prompt matches the HDL theme instead
        // of showing "stby.healthdatalab.net says …".
        wp_enqueue_script(
            'hdlv2-ui-modal',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-ui-modal.js',
            array(),
            HDLV2_VERSION,
            true
        );

        // Chart.js for the Progress tab's Effort vs Outcomes chart. Same
        // CDN + version V1 already loads on the longevity-form results
        // page — keeps the vendor surface to one URL across both plugins.
        wp_enqueue_script(
            'hdlv2-chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js',
            array(),
            '3.9.1',
            true
        );

        // v0.24.5 — embed the client-side flight-plan renderer inside the
        // practitioner expand-panel's Flight Plan tab. Same grid, ticks, and
        // adherence card as /my-flight-plan/. Auth-gated server-side via
        // validate_client_access -> practitioner_owns_client.
        wp_enqueue_style(
            'hdlv2-flight-plan',
            HDLV2_PLUGIN_URL . 'assets/css/hdlv2-flight-plan.css',
            array(),
            HDLV2_VERSION
        );

        wp_enqueue_script(
            'hdlv2-flight-plan',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-flight-plan.js',
            array(),
            HDLV2_VERSION,
            true
        );

        wp_enqueue_script(
            'hdlv2-client-list-enhance',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-client-list-enhance.js',
            array( 'hdlv2-ui-modal', 'hdlv2-chart-js', 'hdlv2-flight-plan' ),
            HDLV2_VERSION,
            true
        );

        $consultation_slug = apply_filters( 'hdlv2_consultation_slug', 'consultation' );
        $flight_plan_slug  = apply_filters( 'hdlv2_flight_plan_slug', 'my-flight-plan' );

        wp_localize_script( 'hdlv2-client-list-enhance', 'hdlv2_client_enhance', array(
            'api_base'         => rest_url( 'hdl-v2/v1' ),
            'nonce'            => wp_create_nonce( 'wp_rest' ),
            'consultation_url' => home_url( '/' . trim( $consultation_slug, '/' ) . '/' ),
            'flight_plan_url'  => home_url( '/' . trim( $flight_plan_slug, '/' ) . '/' ),
        ) );
    }

    public function render_shortcode( $atts ) {
        if ( ! is_user_logged_in() || ! HDLV2_Compatibility::is_practitioner( get_current_user_id() ) ) {
            return '<p style="text-align:center;color:#888;padding:40px;font-family:Inter,sans-serif;">You must be signed in as a practitioner to view this dashboard.</p>';
        }

        $user_id = get_current_user_id();

        // Shared themed confirm dialog (same enqueue as list-enhance path).
        wp_enqueue_script(
            'hdlv2-ui-modal',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-ui-modal.js',
            array(),
            HDLV2_VERSION,
            true
        );

        wp_enqueue_script(
            'hdlv2-practitioner-dashboard',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-practitioner-dashboard.js',
            array( 'hdlv2-ui-modal', 'hdlv2-loading' ),
            HDLV2_VERSION,
            true
        );

        // Skeleton CSS for the initial mount placeholder. The dashboard
        // injects its own JS-built styles for the populated table; this is
        // only for the pre-data skeleton state.
        wp_enqueue_style( 'hdlv2-loading-css' );

        // Per-page slugs — filterable so renamed pages keep working.
        $consultation_slug = apply_filters( 'hdlv2_consultation_slug', 'consultation' );
        $flight_plan_slug  = apply_filters( 'hdlv2_flight_plan_slug', 'my-flight-plan' );

        wp_localize_script( 'hdlv2-practitioner-dashboard', 'hdlv2_prac_dashboard', array(
            'api_base'         => rest_url( 'hdl-v2/v1' ),
            'nonce'            => wp_create_nonce( 'wp_rest' ),
            'consultation_url' => home_url( '/' . trim( $consultation_slug, '/' ) . '/' ),
            'flight_plan_url'  => home_url( '/' . trim( $flight_plan_slug, '/' ) . '/' ),
        ) );

        return '<div id="hdlv2-practitioner-dashboard"></div>';
    }

    /**
     * Enqueue the tutorial section's CSS + JS + per-page localised data.
     * Idempotent — wp_enqueue_* dedup on handle name. Safe to call from
     * both the shortcode and the /clients/ splicer in the same request.
     *
     * @since 0.22.24
     */
    public static function enqueue_tutorial_assets( $user_id ) {
        wp_enqueue_style(
            'hdlv2-tutorial-section',
            HDLV2_PLUGIN_URL . 'assets/css/hdlv2-tutorial-section.css',
            array(),
            HDLV2_VERSION
        );
        wp_enqueue_script(
            'hdlv2-pd-tutorial',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-pd-tutorial.js',
            array(),
            HDLV2_VERSION,
            true
        );

        wp_localize_script( 'hdlv2-pd-tutorial', 'hdlv2_pdt', array(
            'api_base'        => rest_url( 'hdl-v2/v1' ),
            'rest_nonce'      => wp_create_nonce( 'wp_rest' ),
            'practitioner_id' => $user_id,
            'widget_js_url'   => HDLV2_PLUGIN_URL . 'widget/hdl-lead-magnet.js',
        ) );
    }

    /**
     * Build the tutorial section HTML for the given practitioner.
     * Single source of truth for both the shortcode and /clients/ splicer.
     * Concatenated with no blank lines so wpautop() can't wrap our DOM
     * in stray <p> tags.
     *
     * @param int  $user_id     Practitioner user ID.
     * @param bool $has_clients When true, the misleading "first client" framing
     *                          is hidden — only the always-visible reference area
     *                          (4 steps + Read guide) renders. Caller (V1 dashboard)
     *                          knows the count and passes !empty($clients).
     * @since 0.22.24
     */
    public static function build_tutorial_html( $user_id, $has_clients = false ) {
        // Ensure a widget_config row exists. Idempotent.
        if ( class_exists( 'HDLV2_Widget_Config' ) ) {
            HDLV2_Widget_Config::ensure_widget_config_for_user( $user_id );
        }

        // Direct read — HDLV2_Widget_Config has no get_instance() singleton.
        global $wpdb;
        $widget_cfg = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_widget_config WHERE practitioner_user_id = %d",
            $user_id
        ) );
        $logo_url    = $widget_cfg && $widget_cfg->logo_url    ? $widget_cfg->logo_url    : '';
        $theme_color = $widget_cfg && $widget_cfg->theme_color ? $widget_cfg->theme_color : '#3d8da0';
        $cta_text    = $widget_cfg && $widget_cfg->cta_text    ? $widget_cfg->cta_text    : 'Book a session';
        $cta_link    = $widget_cfg && $widget_cfg->cta_link    ? $widget_cfg->cta_link    : '';
        $config_arr  = array(
            'practitioner_name'  => $widget_cfg ? (string) $widget_cfg->practitioner_name  : '',
            'logo_url'           => $logo_url,
            'cta_text'           => $cta_text,
            'cta_link'           => $cta_link,
            'theme_color'        => $theme_color,
            'webhook_url'        => $widget_cfg ? (string) $widget_cfg->webhook_url        : '',
            'notification_email' => $widget_cfg ? (string) $widget_cfg->notification_email : '',
        );
        $embed_code = class_exists( 'HDLV2_Widget_Renderer' )
            ? HDLV2_Widget_Renderer::generate_embed_code( $user_id, $config_arr )
            : '';
        $help_url   = apply_filters( 'hdlv2_practitioner_help_url', 'https://healthdatalab.com/help-centre/' );

        $user       = get_userdata( $user_id );
        $first_name = $user && $user->first_name ? $user->first_name : ( $user ? $user->display_name : 'there' );
        $first_name = trim( explode( ' ', (string) $first_name )[0] );

        $t  = '<section class="hdlv2-pdt">';

        // ── Sections shown ONLY when the practitioner has zero clients ──
        // Hero + two action cards. The "Hi Bob — let's get your first
        // client started" framing reads wrong once they have clients;
        // suppress it and keep only the steps grid + Read guide as the
        // always-available reference area.
        if ( ! $has_clients ) {

        // Hero
        $t .= '<div class="hdlv2-pdt-hero"><div class="hdlv2-pdt-hero-row"><div>';
        $t .= '<p class="hdlv2-pdt-greeting">Welcome to HealthDataLab</p>';
        $t .= '<h1 class="hdlv2-pdt-title">Hi ' . esc_html( $first_name ) . ' &mdash; let&rsquo;s get your first client started.</h1>';
        $t .= '<p class="hdlv2-pdt-sub">You don&rsquo;t have any clients yet. There are two ways to bring them in. Pick whichever fits your practice &mdash; or do both.</p>';
        $t .= '</div></div></div>';

        // Two paths
        $t .= '<div class="hdlv2-pdt-paths">';
        $t .= '<div class="hdlv2-pdt-path">';
        $t .= '<div class="hdlv2-pdt-path-icon" aria-hidden="true">';
        $t .= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>';
        $t .= '</div>';
        $t .= '<h3>Embed the widget</h3>';
        $t .= '<p>Add the 9-question Rate of Ageing widget to your website. Visitors complete it themselves &mdash; they show up here as clients automatically.</p>';
        $t .= '<button type="button" class="hdlv2-pdt-btn" data-hdlv2-pdt-action="get-embed">Get embed code</button>';
        $t .= '</div>';

        $t .= '<div class="hdlv2-pdt-path">';
        $t .= '<div class="hdlv2-pdt-path-icon" aria-hidden="true">';
        $t .= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4z"/><polyline points="4 6 12 13 20 6"/></svg>';
        $t .= '</div>';
        $t .= '<h3>Send a personal invite</h3>';
        $t .= '<p>Email a one-off link to a specific client. They click, register, and start the assessment &mdash; the relationship is linked automatically.</p>';
        $t .= '<button type="button" class="hdlv2-pdt-btn hdlv2-pdt-btn-ghost" data-hdlv2-pdt-action="invite-open">Invite a client</button>';
        $t .= '</div>';
        $t .= '</div>';

        } // end if ( ! $has_clients ) — hero + paths

        // ── Always visible: 4 steps reference grid ──
        // v0.35.3 (Quim 2026-05-07) — switched <ol> to <ul> because some
        // host themes (Divi, UM) override `list-style:none` with higher
        // specificity, leaking the browser's default "1.", "2.", ...
        // markers IN ADDITION to the teal CSS-counter circles we render
        // via ::before. With <ul>, even if the override leaks, the
        // worst-case marker is a bullet (which the bg/padding hides
        // cleanly inside the card). counter-increment works on any list
        // element, so the teal numbered circles still render.
        $t .= '<h2 class="hdlv2-pdt-section-title">How the assessment unfolds</h2>';
        $t .= '<ul class="hdlv2-pdt-steps">';
        $t .= '<li><strong>Stage 1 &middot; Quick insight</strong>9 questions, ~3 minutes. Pace-of-ageing gauge sent by email.</li>';
        $t .= '<li><strong>Stage 2 &middot; Their WHY</strong>You release this stage when ready. Client records their motivation; AI distils it.</li>';
        $t .= '<li><strong>Stage 3 &middot; Full detail</strong>22 measurements, 5 sections. Auto-drafts the Longevity Report for your review.</li>';
        $t .= '<li><strong>Consultation &middot; Final Report</strong>You edit, add notes, finalise. PDF + Flight Plan delivered to the client.</li>';
        $t .= '</ul>';

        // ── Customize collapsible — empty state only ──
        if ( ! $has_clients ) {

        $t .= '<details class="hdlv2-pdt-config" id="hdlv2-pdt-config">';
        $t .= '<summary><span>Customize your widget<span class="hdlv2-pdt-summary-sub">Logo, theme color, and where the &ldquo;Book a session&rdquo; button takes them. Optional &mdash; defaults work fine.</span></span></summary>';
        $t .= '<div class="hdlv2-pdt-config-grid">';
        $t .= '<div><label for="hdlv2-pdt-logo">Logo URL</label><input id="hdlv2-pdt-logo" type="url" placeholder="https://yourpractice.com/logo.png" value="' . esc_attr( $logo_url ) . '"></div>';
        $t .= '<div><label for="hdlv2-pdt-color">Theme color</label><div class="hdlv2-pdt-color-row"><input id="hdlv2-pdt-color" type="color" value="' . esc_attr( $theme_color ) . '"><input id="hdlv2-pdt-color-hex" type="text" value="' . esc_attr( $theme_color ) . '" style="flex:1"></div></div>';
        $t .= '<div><label for="hdlv2-pdt-cta-text">CTA button text</label><input id="hdlv2-pdt-cta-text" type="text" value="' . esc_attr( $cta_text ) . '"></div>';
        $t .= '<div><label for="hdlv2-pdt-cta-link">CTA button link <span style="color:#dc2626;text-transform:none;letter-spacing:0;font-weight:400;">*</span></label><input id="hdlv2-pdt-cta-link" type="url" placeholder="https://calendly.com/your-practice/intro" value="' . esc_attr( $cta_link ) . '"></div>';
        $t .= '</div>';
        $t .= '<div class="hdlv2-pdt-config-foot">';
        $t .= '<span class="hdlv2-pdt-status" id="hdlv2-pdt-save-status" hidden></span>';
        $t .= '<button type="button" class="hdlv2-pdt-btn hdlv2-pdt-btn-ghost" data-hdlv2-pdt-action="preview">Preview</button>';
        $t .= '<button type="button" class="hdlv2-pdt-btn" data-hdlv2-pdt-action="save-config">Save</button>';
        $t .= '</div>';
        $t .= '<pre class="hdlv2-pdt-embed-preview" id="hdlv2-pdt-embed-preview" aria-label="Embed code">' . esc_html( $embed_code ) . '</pre>';
        $t .= '</details>';

        } // end if ( ! $has_clients ) — customize collapsible

        // ── Always visible: Help footer ──
        $t .= '<div class="hdlv2-pdt-help">';
        $t .= '<span>New to the platform? <strong>Open the 5-minute getting-started guide</strong> &mdash; covers the widget, invites, and your first consultation.</span>';
        $t .= '<a href="' . esc_url( $help_url ) . '" target="_blank" rel="noopener noreferrer">Read guide &rarr;</a>';
        $t .= '</div>';

        $t .= '</section>';

        // ── Custom invite modal removed in v0.22.25 ──
        // The "Invite a client" tutorial button now triggers V1's existing
        // Client Tools popup (which V2 already injects 3 tabs into via
        // hdlv2-dashboard.js). Single source of truth for invite UX —
        // matches what active practitioners see when clicking Client Tools.

        return $t;
    }
}
