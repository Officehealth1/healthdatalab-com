<?php
/**
 * V2 Client Dashboard — /my-dashboard/.
 *
 * Renders the client-facing dashboard: 4 empty/waiting states + a populated
 * placeholder. Mirrors HDLV2_Practitioner_Dashboard's role on /clients/, but
 * for the client side of the platform.
 *
 * Also owns:
 *   - login_redirect filter — practitioner → /clients/, client → /my-dashboard/
 *   - template_redirect guards — practitioner-on-/my-dashboard/ → /clients/,
 *     client-on-/clients/ → /my-dashboard/. Closes the bug where a client with
 *     full V2 data hit V1's stale "Welcome! Please complete your first
 *     health assessment…" placeholder on /clients/.
 *
 * Phase A (v0.32.1): scaffolding + redirects + 4 empty-state renders.
 *   - Empty markup mirrors mockups/client-empty-mockup.html (which mirrors
 *     practitioner-empty.html via the shared _shell.css design language).
 *   - Populated state shows a 3-CTA placeholder pointing at the existing
 *     /my-report/, /my-flight-plan/, /check-in/ shortcodes — so an existing
 *     fully-populated client immediately gets a working landing page on
 *     /my-dashboard/, rather than nothing.
 *
 * Phase B (next): replace populated placeholder with the 3-tab UI from
 * mockups/client-dashboard-mockup.html, hydrated by a new aggregator REST
 * endpoint /hdl-v2/v1/client-dashboard/{client_id}.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  Role mapping (per stby UM role list, 2026-05-05)
 * ─────────────────────────────────────────────────────────────────────
 *  Practitioner side (lands on /clients/, blocked from /my-dashboard/):
 *    - administrator                              (3 users)
 *    - um_practitioner                            (8 users)
 *
 *  Client side (lands on /my-dashboard/, blocked from /clients/):
 *    - um_client                                  (32 users)
 *    - um_practitioner-invite (magic-link clients) (38 users)
 *    - um_consumer                                (2 users)
 *    - um_sprout / um_annual_subscriber / subscriber / customer / etc.
 *    - Any other logged-in role that is NOT admin or um_practitioner.
 *
 *  Logout: lands on https://healthdatalab.com/ (filterable via
 *    `hdlv2_client_signout_url`). Filterable so we can swap to wp-login
 *    for QA without code changes.
 * ─────────────────────────────────────────────────────────────────────
 *
 * Usage: [hdlv2_my_dashboard]
 *
 * @package HDL_Longevity_V2
 * @since 0.32.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class HDLV2_Client_Dashboard {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {}

    public function register_hooks() {
        add_shortcode( 'hdlv2_my_dashboard', array( $this, 'render_shortcode' ) );

        // Role-based login destination (only fires on form-login, not magic-link).
        add_filter( 'login_redirect', array( $this, 'filter_login_redirect' ), 10, 3 );

        // Wrong-role page guards. Priority 6 = after the magic-link auto-login at
        // init priority 1, and before any content rendering or sub-page logic.
        add_action( 'template_redirect', array( $this, 'maybe_redirect_wrong_role' ), 6 );

        // v0.32.6 — Tell LiteSpeed / Cloudflare / WP supercache to never cache
        // /my-dashboard/. The page bakes per-user data into HTML (tick_ids,
        // wp_rest nonce, client first name, flight-plan rows). A cached copy
        // would serve user A's nonce + tick IDs to user B → silent JS failure
        // on tick (the older bug) and worse, IDOR if tick_id from cached HTML
        // belongs to a different client. Same approach as the existing
        // ?token=… pages in hdl-longevity-v2.php:340-352.
        add_action( 'template_redirect', array( $this, 'no_cache_dashboard_page' ), 2 );

        // Always-available CSS handle for shortcode + theme reuse.
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 5 );

        // v0.46.23 — Client chrome cleanup. The Divi header shows V2 clients WP
        // menu 66 ("Invited Client"), whose items still pointed at V1 forms /
        // the practitioner roster (Longevity Report → /longevity-form-rate-of-
        // aging/, Health Report → /health-report/, Dashboard → /clients/). Re-
        // point them to the client's V2 surfaces and drop the V1 health form.
        // Scoped to genuine V2 clients (is_v2_client) so V1 consumers who share
        // the um_client / um_consumer role — and practitioners on menu 31 — are
        // never touched.
        add_filter( 'wp_nav_menu_objects', array( $this, 'filter_client_nav' ), 20, 2 );

        // The Divi footer (post 1065) is hardcoded HTML with no menu hook, shown
        // to every role. Hide its V1 / practitioner links for V2 clients only.
        add_action( 'wp_head', array( $this, 'inject_client_footer_css' ), 99 );
    }

    public function no_cache_dashboard_page() {
        if ( is_admin() || wp_doing_ajax() ) return;
        if ( ! is_page( $this->dashboard_slug() ) ) return;
        nocache_headers();
        if ( ! headers_sent() ) {
            header( 'X-LiteSpeed-Cache-Control: no-cache, esi=on' );
            header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        }
    }

    public function register_assets() {
        wp_register_style(
            'hdlv2-client-dashboard',
            HDLV2_PLUGIN_URL . 'assets/css/hdlv2-client-dashboard.css',
            array(),
            HDLV2_VERSION
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Client chrome (v0.46.23) — nav + footer cleanup, V2-client-scoped
    // ──────────────────────────────────────────────────────────────────────

    /**
     * True only for a genuine V2 client — someone who has a V2 form_progress
     * row, a V2 widget invite, or a V2-origin user_meta stamp (hdl_source
     * 'assessment_link' / hdlv2_tier / hdlv2_purchased_via). This deliberately
     * does NOT key off WP role:
     * `um_client` / `um_consumer` are shared with V1 free-report + consumer
     * users who legitimately use the V1 longevity / health forms, and the Divi
     * header shows them the same "Invited Client" menu. Practitioners and
     * admins are always excluded. Result cached per request (the nav + footer
     * filters both call this on every page render).
     */
    private function is_v2_client( $user_id ) {
        static $cache = array();
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) return false;
        if ( isset( $cache[ $user_id ] ) ) return $cache[ $user_id ];

        if ( user_can( $user_id, 'manage_options' )
             || ( class_exists( 'HDLV2_Compatibility' ) && HDLV2_Compatibility::is_practitioner( $user_id ) ) ) {
            return $cache[ $user_id ] = false;
        }

        global $wpdb;
        $has_progress = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE client_user_id = %d AND deleted_at IS NULL LIMIT 1",
            $user_id
        ) );
        if ( $has_progress ) return $cache[ $user_id ] = true;

        $user  = get_userdata( $user_id );
        $email = $user ? (string) $user->user_email : '';
        $has_invite = 0;
        if ( $email !== '' ) {
            $has_invite = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM {$wpdb->prefix}hdlv2_widget_invites
                 WHERE client_email = %s LIMIT 1",
                $email
            ) );
        }
        if ( $has_invite ) return $cache[ $user_id ] = true;

        // Fallback for genuine V2 clients who are stranded with neither a
        // form_progress row nor a widget_invites row yet — e.g. a magic-link
        // invite that was later deleted, or a client provisioned just before
        // Stage 1. Key off the V2-origin user_meta stamps that ONLY the V2
        // creation paths write, never V1's practitioner-invite flow (which
        // stamps hdl_source='practitioner_invite'). This recovers real V2
        // clients without catching the V1 free-report / consumer / invite
        // users who share the um_client / um_consumer / um_practitioner-invite
        // roles (the whole reason this check is data/meta-driven, not role-driven):
        //   - hdl_source='assessment_link'        : V2 auto-login provisioning (hdl-longevity-v2.php)
        //   - hdlv2_tier / hdlv2_purchased_via    : V2 paid-report provisioner (class-hdl-paid-report-provisioner.php)
        if ( get_user_meta( $user_id, 'hdl_source', true ) === 'assessment_link' ) {
            return $cache[ $user_id ] = true;
        }
        if ( (string) get_user_meta( $user_id, 'hdlv2_tier', true ) !== ''
             || (string) get_user_meta( $user_id, 'hdlv2_purchased_via', true ) !== '' ) {
            return $cache[ $user_id ] = true;
        }

        return $cache[ $user_id ] = false;
    }

    /**
     * Repoint / prune the client header menu (WP menu 66) for V2 clients.
     * Matched by URL path so it survives menu-id changes between STBY ↔ LIVE.
     */
    public function filter_client_nav( $items, $args ) {
        if ( is_admin() || ! is_user_logged_in() || empty( $items ) ) return $items;
        if ( ! $this->is_v2_client( get_current_user_id() ) ) return $items;

        foreach ( $items as $key => $item ) {
            if ( ! is_object( $item ) || empty( $item->url ) ) continue;
            $path = wp_parse_url( $item->url, PHP_URL_PATH );
            $path = $path ? trailingslashit( $path ) : '';

            if ( $path === '/health-report/' ) {
                // V1 health form — not part of the V2 client journey (and it
                // throws a JS "Unexpected token '<'" on load). Drop it.
                unset( $items[ $key ] );
                continue;
            }
            if ( $path === '/longevity-form-rate-of-aging/' ) {
                // Old V1 longevity form → the client's own V2 report.
                $item->url   = home_url( '/my-report/' );
                $item->title = 'My Report';
                continue;
            }
            if ( $path === '/clients/' ) {
                // Practitioner roster page (clients only land here via a
                // redirect back to /my-dashboard/). Point straight there.
                $item->url   = home_url( '/my-dashboard/' );
                $item->title = 'Dashboard';
            }
        }
        return array_values( $items );
    }

    /**
     * Hide the V1 / practitioner links baked into the static Divi footer
     * (post 1065, class .hdl-footer) for V2 clients only. Targeted by href so
     * no other role's footer is affected. The wrong-domain "Contact Us" mailto
     * is corrected in the footer module itself, not here.
     */
    public function inject_client_footer_css() {
        if ( is_admin() || ! is_user_logged_in() ) return;
        if ( ! $this->is_v2_client( get_current_user_id() ) ) return;
        echo '<style id="hdlv2-client-footer-fix">'
           . '.hdl-footer a[href*="/client-tracker/"],'
           . '.hdl-footer a[href*="/health-report/"],'
           . '.hdl-footer a[href*="/clients/"],'
           . '.hdl-footer a[href*="/longevity-form-rate-of-aging/"]'
           . '{display:none !important;}'
           . '</style>' . "\n";
    }

    /**
     * After form login, send practitioners → /clients/, clients → /my-dashboard/.
     * Honour any explicit redirect_to that's not the wp-admin default (so magic
     * links and bookmarks keep working).
     */
    public function filter_login_redirect( $redirect_to, $request, $user ) {
        if ( ! ( $user instanceof WP_User ) ) return $redirect_to;

        // If a non-admin redirect_to was explicitly requested, honour it.
        $admin = admin_url();
        if ( $request && $request !== $admin && $request !== home_url( '/wp-admin/' ) ) {
            return $redirect_to;
        }

        if ( HDLV2_Compatibility::is_practitioner( $user->ID ) ) {
            return home_url( '/clients/' );
        }
        return $this->dashboard_url();
    }

    /**
     * Bounce practitioners off /my-dashboard/ → /clients/, and clients off
     * /clients/ → /my-dashboard/. No-op for logged-out users (those pages
     * have their own login gates from the parent shortcodes).
     */
    public function maybe_redirect_wrong_role() {
        if ( is_admin() || wp_doing_ajax() ) return;
        if ( ! is_user_logged_in() ) return;

        $user_id = get_current_user_id();

        // v0.36.7 — Admin bypass. HDLV2_Compatibility::is_practitioner()
        // returns true for admins (the IDOR escape hatch — administrator
        // role gets practitioner-level access for support / debugging).
        // That made admins visiting /my-dashboard/ get auto-bounced to
        // /clients/ via the practitioner branch below, so an admin could
        // never view the client UI for inspection / theme work / template
        // testing. Skip the wrong-role redirect entirely for admins;
        // their /clients/ access is preserved (admin still counts as
        // practitioner inside is_practitioner for IDOR purposes).
        // Reported by Quim 2026-05-10 ("can't edit /my-dashboard/ as admin").
        if ( user_can( $user_id, 'manage_options' ) ) return;

        $is_practitioner = HDLV2_Compatibility::is_practitioner( $user_id );

        $dashboard_slug = $this->dashboard_slug();
        $clients_slug   = trim( apply_filters( 'hdlv2_clients_page_slug', 'clients' ), '/' );

        // Practitioner on /my-dashboard/ → /clients/.
        if ( $is_practitioner && is_page( $dashboard_slug ) ) {
            wp_safe_redirect( home_url( '/' . $clients_slug . '/' ), 302 );
            exit;
        }

        // Client on /clients/ → /my-dashboard/.
        if ( ! $is_practitioner && is_page( $clients_slug ) ) {
            wp_safe_redirect( $this->dashboard_url(), 302 );
            exit;
        }
    }

    private function dashboard_slug() {
        return trim( apply_filters( 'hdlv2_client_dashboard_slug', 'my-dashboard' ), '/' );
    }

    private function dashboard_url() {
        return home_url( '/' . $this->dashboard_slug() . '/' );
    }

    public function render_shortcode( $atts ) {
        wp_enqueue_style( 'hdlv2-client-dashboard' );

        if ( ! is_user_logged_in() ) {
            return $this->render_login_prompt();
        }

        $user_id = get_current_user_id();

        // v0.36.7 — Admin bypass paired with the maybe_redirect_wrong_role
        // bypass above. Admins skip the practitioner-redirect-card so they
        // see the actual client UI for layout/theme inspection. um_practitioner
        // users still get the friendly redirect card.
        $is_admin_only = user_can( $user_id, 'manage_options' );

        // Defensive: if a practitioner somehow renders this shortcode (e.g.
        // template_redirect didn't fire because the page lacks the slug),
        // show a redirect card rather than the client UI.
        if ( ! $is_admin_only && HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            return $this->render_practitioner_redirect();
        }

        $state = HDLV2_Compatibility::get_client_journey_state( $user_id );

        if ( $state === 'populated' ) {
            // Enqueue populated CSS + JS only when needed (saves a request on empty states).
            wp_enqueue_style(
                'hdlv2-client-dashboard-populated',
                HDLV2_PLUGIN_URL . 'assets/css/hdlv2-client-dashboard-populated.css',
                array(),
                HDLV2_VERSION
            );
            wp_enqueue_script(
                'hdlv2-client-dashboard',
                HDLV2_PLUGIN_URL . 'assets/js/hdlv2-client-dashboard.js',
                array(),
                HDLV2_VERSION,
                true
            );
            wp_localize_script( 'hdlv2-client-dashboard', 'HDLV2_CD_POP', array(
                'restUrl'   => esc_url_raw( rest_url( 'hdl-v2/v1' ) ),
                'nonce'     => wp_create_nonce( 'wp_rest' ),
                'clientId'  => (int) $user_id,
                'todayKey'  => strtolower( date_i18n( 'l' ) ),
            ) );
            return $this->render_populated( $user_id );
        }

        return $this->render_empty_state( $user_id, $state );
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Renderers
    // ──────────────────────────────────────────────────────────────────────

    private function render_login_prompt() {
        return $this->card(
            'Sign in to see your dashboard',
            'Use the link in your invitation email or sign in below.',
            'Sign in',
            wp_login_url( $this->dashboard_url() )
        );
    }

    private function render_practitioner_redirect() {
        return $this->card(
            'This page is for clients',
            'You\'re signed in as a practitioner. Open the Clients dashboard to see your roster.',
            'Go to Clients',
            home_url( '/clients/' )
        );
    }

    /**
     * v0.32.4 — Full populated 3-tab dashboard for clients whose journey is
     * complete (final report exists OR consultation_notes status='report_generated'
     * OR a flight plan has been delivered).
     *
     * Server-rendered. JS handles only tab switching — no AJAX, no race
     * conditions, no double-fetch. Day picker + ticking still live in
     * /my-flight-plan/ ([hdlv2_flight_plan]) — Today tab links there rather
     * than rebuild the well-tested existing UI.
     */
    private function render_populated( $user_id ) {
        $ctx = $this->load_populated_context( $user_id );

        $h  = '<div class="hdlv2-cd-pop">';
        $h .= $this->pop_tabs_strip();
        $h .= '<main class="hdlv2-cd-pop-page">';
        $h .= $this->pop_today_panel( $ctx );
        $h .= $this->pop_progress_panel( $ctx );
        $h .= $this->pop_report_panel( $ctx );
        $h .= '</main>';
        $h .= '</div>';
        return $h;
    }

    private function pop_tabs_strip() {
        $h  = '<nav class="cdp-tabs" role="tablist" aria-label="Dashboard sections">';
        $h .= '<div class="cdp-tabs-inner">';
        $h .= '<button type="button" class="cdp-tab active" data-tab="today" role="tab" aria-selected="true">Today</button>';
        $h .= '<button type="button" class="cdp-tab"        data-tab="progress" role="tab" aria-selected="false">Progress</button>';
        $h .= '<button type="button" class="cdp-tab"        data-tab="report"   role="tab" aria-selected="false">My Report</button>';
        $h .= '</div></nav>';
        return $h;
    }

    private function pop_today_panel( $ctx ) {
        $first        = esc_html( $ctx['first_name'] );
        $weekday      = date_i18n( 'l' );
        $today_str    = date_i18n( 'j M' );
        $week_no      = $ctx['flight_plan']['week_number'] ?? null;
        // v0.46.66 (#2) — was '/check-in/' (a 404: the page slug is
        // 'weekly-check-in'). The check-in shortcode now cookie-resolves the
        // logged-in client's token, so the CTA needs no ?token in the URL.
        $checkin_url  = home_url( '/' . trim( apply_filters( 'hdlv2_checkin_slug', 'weekly-check-in' ), '/' ) . '/' );
        $plan_url     = home_url( '/my-flight-plan/' );

        $weeks_text = $week_no
            ? sprintf( "It&rsquo;s %s &mdash; you&rsquo;re %d&nbsp;%s into your plan.", esc_html( $today_str ), (int) $week_no, $week_no === 1 ? 'week' : 'weeks' )
            : sprintf( "It&rsquo;s %s. Welcome back.", esc_html( $today_str ) );

        // Check-in due date: 6 days after week_start
        $checkin_due_str = '';
        if ( ! empty( $ctx['flight_plan']['week_start'] ) ) {
            $due_ts = strtotime( $ctx['flight_plan']['week_start'] . ' +6 days' );
            if ( $due_ts ) $checkin_due_str = date_i18n( 'l, j M', $due_ts );
        }

        $h  = '<section data-tab-panel="today" class="cdp-panel cdp-panel-active">';

        // Greeting
        $h .= '<div class="cdp-hero-greet">';
        $h .= '<h1 class="cdp-greeting">Good ' . esc_html( $weekday ) . ', ' . $first . '.</h1>';
        $h .= '<p class="cdp-lede">' . $weeks_text . '</p>';
        $h .= '</div>';

        // Check-in nudge
        $h .= '<div class="cdp-checkin-nudge">';
        $h .= '<div class="cdp-icon" aria-hidden="true">' . $this->icon( 'mic' ) . '</div>';
        $h .= '<div class="cdp-body">';
        $h .= '<h2>Time for your weekly check-in</h2>';
        // v0.36.3 — copy clarifies the Sunday cadence (the cron fires Sunday
        // morning + reminder Tuesday). Button stays clickable any day; the
        // sub-line just sets the expectation so clients understand the
        // weekly rhythm of "check in Sunday → fresh Flight Plan Monday".
        $h .= '<p>Two minutes. Tell us how the week went &mdash; speak it or type it. <strong>Best done on Sundays</strong>, when next week\'s Flight Plan is built from your reply.</p>';
        if ( $checkin_due_str ) {
            $h .= '<div class="cdp-due">Due ' . esc_html( $checkin_due_str ) . '</div>';
        }
        $h .= '</div>';
        $h .= '<a href="' . esc_url( $checkin_url ) . '" class="cdp-btn cdp-btn-primary cdp-btn-large">Begin &rarr;</a>';
        $h .= '</div>';

        // This week summary card → linked to /my-flight-plan/ for the actual day picker
        if ( $ctx['flight_plan'] ) {
            $h .= $this->pop_week_card( $ctx, $plan_url );
        } else {
            $h .= '<div class="cdp-card cdp-card-quiet">';
            $h .= '<p>Your weekly Flight Plan will appear here as soon as your practitioner generates it.</p>';
            $h .= '</div>';
        }

        // WHY anchor
        if ( $ctx['why_text'] ) {
            $h .= '<div class="cdp-why-card">';
            $h .= '<div class="cdp-why-eyebrow">Your reason</div>';
            $h .= '<p class="cdp-why-quote">' . esc_html( $ctx['why_text'] ) . '</p>';
            if ( $ctx['why_captured_at'] ) {
                $h .= '<div class="cdp-why-attrib">Captured ' . esc_html( $this->fmt_date( $ctx['why_captured_at'] ) ) . '</div>';
            }
            $h .= '</div>';
        }

        $h .= '</section>';
        return $h;
    }

    private function pop_week_card( $ctx, $plan_url ) {
        $fp = $ctx['flight_plan'];
        $week_start = $fp['week_start'] ? date_i18n( 'j M', strtotime( $fp['week_start'] ) ) : '';
        $week_end   = $fp['week_start'] ? date_i18n( 'j M', strtotime( $fp['week_start'] . ' +6 days' ) ) : '';
        $week_no    = (int) ( $fp['week_number'] ?? 0 );
        $identity   = $fp['identity_statement'] ?? '';

        $today_key = strtolower( date_i18n( 'l' ) );
        $by_day    = $ctx['ticks_by_day'] ?? array();

        // If today falls outside this plan's week (e.g. plan still active for
        // late catch-up tasks), default visible day to the first day with
        // actions so the client always sees something to tick.
        $visible_day = $today_key;
        if ( empty( $by_day[ $visible_day ]['actions'] ) ) {
            foreach ( array( 'monday','tuesday','wednesday','thursday','friday','saturday','sunday' ) as $d ) {
                if ( ! empty( $by_day[ $d ]['actions'] ) ) { $visible_day = $d; break; }
            }
        }

        $days = array(
            'monday'    => array( 'short' => 'Mon', 'idx' => 0 ),
            'tuesday'   => array( 'short' => 'Tue', 'idx' => 1 ),
            'wednesday' => array( 'short' => 'Wed', 'idx' => 2 ),
            'thursday'  => array( 'short' => 'Thu', 'idx' => 3 ),
            'friday'    => array( 'short' => 'Fri', 'idx' => 4 ),
            'saturday'  => array( 'short' => 'Sat', 'idx' => 5 ),
            'sunday'    => array( 'short' => 'Sun', 'idx' => 6 ),
        );
        $week_start_ts = $fp['week_start'] ? strtotime( $fp['week_start'] ) : null;

        $h  = '<div class="cdp-card cdp-week-card" data-flight-plan-id="' . (int) $fp['id'] . '">';
        $h .= '<div class="cdp-week-head">';
        $h .= '<h2>This week</h2>';
        $h .= '<span class="cdp-week-sub">' . esc_html( $week_start ) . ' &ndash; ' . esc_html( $week_end ) . ' &middot; Week ' . $week_no . '</span>';
        $h .= '</div>';

        if ( $identity ) {
            $h .= '<div class="cdp-identity"><span class="cdp-identity-mark">&ldquo;</span>' . esc_html( $identity ) . '<span class="cdp-identity-mark">&rdquo;</span></div>';
        }

        // ── Day picker — clickable; wires to JS to swap visible day ──
        $h .= '<div class="cdp-day-picker" role="tablist" aria-label="Day picker">';
        foreach ( $days as $key => $meta ) {
            $stats    = $by_day[ $key ] ?? array( 'total' => 0, 'done' => 0 );
            $pct      = $stats['total'] > 0 ? round( $stats['done'] * 100 / $stats['total'] ) : 0;
            $is_today = ( $key === $today_key );
            $is_visible = ( $key === $visible_day );
            $cls = 'cdp-day-pill';
            if ( $is_today ) $cls .= ' cdp-day-today';
            if ( $is_visible ) $cls .= ' cdp-day-active';
            $date_label = '';
            if ( $week_start_ts ) {
                $date_label = date_i18n( 'j', strtotime( '+' . $meta['idx'] . ' days', $week_start_ts ) );
            }
            $h .= '<button type="button" class="' . $cls . '" data-day="' . $key . '" role="tab" aria-selected="' . ( $is_visible ? 'true' : 'false' ) . '">';
            $h .= '<div class="cdp-d-letter">' . esc_html( $meta['short'] ) . '</div>';
            $h .= '<div class="cdp-d-num">' . esc_html( $date_label ) . '</div>';
            $h .= '<div class="cdp-d-progress" data-day-progress="' . $key . '"><span style="width:' . $pct . '%"></span></div>';
            $h .= '</button>';
        }
        $h .= '</div>';

        // ── One UL per day with the FULL action list and tick_id data attrs ──
        // JS toggles .cdp-actions-active to swap which day is visible.
        // Actions render under a category heading (FOOD / FITNESS / LIFESTYLE)
        // matching the practitioner Flight Plan layout — so client and
        // practitioner read the same Tuesday in the same order.
        foreach ( $days as $key => $meta ) {
            $actions = $by_day[ $key ]['actions'] ?? array();
            $is_visible = ( $key === $visible_day );
            $ul_cls = 'cdp-actions' . ( $is_visible ? ' cdp-actions-active' : '' );
            $h .= '<ul class="' . $ul_cls . '" data-day-actions="' . $key . '">';
            if ( empty( $actions ) ) {
                $h .= '<li class="cdp-action-empty">No actions for ' . esc_html( ucfirst( $key ) ) . '.</li>';
            }

            // Group consecutive actions by category, emit a heading row before each new group.
            $current_cat = null;
            foreach ( $actions as $a ) {
                if ( $a['category'] !== $current_cat ) {
                    $current_cat = $a['category'];
                    $cat_class = $this->category_class( $current_cat );
                    $cat_label = $this->category_label( $current_cat );
                    $h .= '<li class="cdp-action-group" data-cat="' . esc_attr( $cat_class ) . '">';
                    $h .= '<span class="cdp-action-dot cdp-action-dot-' . $cat_class . '"></span>';
                    $h .= '<span class="cdp-action-group-label">' . esc_html( strtoupper( $cat_label ) ) . '</span>';
                    $h .= '</li>';
                }
                $cat_class = $this->category_class( $a['category'] );
                $done      = ! empty( $a['ticked'] );
                $cls       = 'cdp-action' . ( $done ? ' cdp-action-done' : '' );
                $h .= '<li class="' . $cls . '" data-tick-id="' . (int) $a['tick_id'] . '" data-day="' . $key . '" data-ticked="' . ( $done ? '1' : '0' ) . '">';
                $h .= '<button type="button" class="cdp-action-check" aria-label="' . ( $done ? 'Mark not done' : 'Mark done' ) . '"></button>';
                $h .= '<div class="cdp-action-body">';
                $h .= '<div class="cdp-action-ttl">' . esc_html( $a['action_text'] ) . '</div>';
                $h .= '</div>';
                $h .= '</li>';
            }
            $h .= '</ul>';
        }

        // ── Footer with live "X of Y done" counter (updated by JS on tick) ──
        $done_today = $by_day[ $visible_day ]['done'] ?? 0;
        $tot_today  = $by_day[ $visible_day ]['total'] ?? 0;
        $h .= '<div class="cdp-week-foot">';
        $h .= '<span class="cdp-progress-text" data-day-counter>';
        if ( $tot_today > 0 ) {
            $h .= '<span data-done-count>' . (int) $done_today . '</span> of <span data-total-count>' . (int) $tot_today . '</span> done <span data-day-label>' . esc_html( $visible_day === $today_key ? 'today' : ucfirst( $visible_day ) ) . '</span>.';
        } else {
            $h .= 'No actions for this day yet.';
        }
        $h .= '</span>';
        $h .= '<a href="' . esc_url( $plan_url ) . '">Open the whole week &rarr;</a>';
        $h .= '</div>';

        $h .= '</div>';
        return $h;
    }

    private function pop_progress_panel( $ctx ) {
        $first = esc_html( $ctx['first_name'] );

        $h  = '<section data-tab-panel="progress" class="cdp-panel">';

        // ── Personalised greeting + lede ──
        $weeks = (int) ( $ctx['weeks_tracked'] ?? 0 );
        $weeks_label = $weeks <= 0 ? 'Just starting' : $weeks . '&nbsp;week' . ( $weeks === 1 ? '' : 's' ) . ' of work';
        $h .= '<div class="cdp-hero-greet cdp-reveal cdp-reveal-1">';
        $h .= '<h1 class="cdp-greeting">Where you stand, ' . $first . '.</h1>';
        $h .= '<p class="cdp-lede">' . $weeks_label . ', three numbers, one story.</p>';
        $h .= '</div>';

        // ── Hero phrase (replaces the 3 stat cards). ──
        $h .= '<p class="cdp-hero-phrase cdp-reveal cdp-reveal-2">' . $this->build_hero_phrase( $ctx ) . '</p>';

        // ── Single charts card holding gauge / radar / trajectory / effort ──
        $h .= $this->pop_charts_card( $ctx );

        // ── Countdown anticipation card ──
        $h .= $this->pop_countdown_card( $ctx );

        // Past check-ins (collapsed)
        if ( $ctx['checkins'] ) {
            $h .= '<details class="cdp-collapse">';
            $h .= '<summary><span>Past check-ins <span class="cdp-meta">&middot; ' . count( $ctx['checkins'] ) . ' weekly entries</span></span><span class="cdp-chev">&#9662;</span></summary>';
            $h .= '<div>';
            foreach ( $ctx['checkins'] as $ci ) {
                $h .= '<div class="cdp-ci-row' . ( ! empty( $ci['has_flags'] ) ? ' cdp-ci-flag' : '' ) . '">';
                $h .= '<div class="cdp-ci-when">' . esc_html( $ci['when_label'] ) . '</div>';
                $h .= '<div class="cdp-ci-body">';
                $h .= '<div class="cdp-ci-ttl">' . esc_html( $ci['title'] ) . '</div>';
                if ( ! empty( $ci['summary'] ) ) {
                    $h .= '<p class="cdp-ci-summary">' . esc_html( $ci['summary'] ) . '</p>';
                }
                if ( ! empty( $ci['wins'] ) || ! empty( $ci['obstacles'] ) ) {
                    $h .= '<ul class="cdp-ci-chips">';
                    foreach ( $ci['wins'] as $w ) {
                        $h .= '<li class="cdp-ci-chip cdp-ci-chip-win"><span class="cdp-ci-chip-dot" aria-hidden="true">&check;</span>' . esc_html( $w ) . '</li>';
                    }
                    foreach ( $ci['obstacles'] as $o ) {
                        $h .= '<li class="cdp-ci-chip cdp-ci-chip-block"><span class="cdp-ci-chip-dot" aria-hidden="true">!</span>' . esc_html( $o ) . '</li>';
                    }
                    $h .= '</ul>';
                }
                $h .= '</div>';
                $h .= '<div class="cdp-ci-pct">' . (int) $ci['pct'] . '%<small>complete</small></div>';
                $h .= '</div>';
            }
            $h .= '</div></details>';
        }

        // Recent activity (collapsed)
        if ( $ctx['timeline'] ) {
            $h .= '<details class="cdp-collapse">';
            $h .= '<summary><span>Recent activity <span class="cdp-meta">&middot; ' . count( $ctx['timeline'] ) . ' entries</span></span><span class="cdp-chev">&#9662;</span></summary>';
            $h .= '<div>';
            foreach ( $ctx['timeline'] as $t ) {
                $h .= '<div class="cdp-act-row">';
                $h .= '<div class="cdp-ci-when">' . esc_html( $t['when_label'] ) . '</div>';
                $h .= '<div class="cdp-ci-body">';
                $h .= '<div class="cdp-ci-ttl">' . esc_html( $t['title'] ) . '</div>';
                if ( ! empty( $t['summary'] ) ) {
                    $h .= '<p class="cdp-ci-summary">' . esc_html( $t['summary'] ) . '</p>';
                }
                $h .= '</div>';
                $h .= '</div>';
            }
            $h .= '</div></details>';
        }

        $h .= '</section>';
        return $h;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Charts card — single container with 4 panels
    //  Each panel pulls from the canonical chart-URL builders that the
    //  Final Report PDF already uses. Same source = practitioner and client
    //  always see the same chart.
    // ──────────────────────────────────────────────────────────────────────

    private function pop_charts_card( $ctx ) {
        $h  = '<div class="cdp-card cdp-charts-card cdp-reveal cdp-reveal-3">';
        $h .= '<div class="cdp-charts-head">';
        $h .= '<div class="cdp-eyebrow">The science</div>';
        $h .= '<h2 class="cdp-section-title">Where you stand</h2>';
        $h .= '<p class="cdp-chart-headline">The same charts your practitioner sees, every week. <strong>Pace</strong> tells you the speed; <strong>health profile</strong> tells you the shape; <strong>trajectory</strong> tells you where you&rsquo;re heading; <strong>effort</strong> tells you what you&rsquo;ve done.</p>';
        $h .= '</div>';

        $h .= '<div class="cdp-charts-grid">';
        $h .= $this->chart_panel_gauge( $ctx );
        $h .= $this->chart_panel_radar( $ctx );
        $h .= $this->chart_panel_trajectory( $ctx );
        $h .= $this->chart_panel_effort( $ctx );
        $h .= '</div>';

        $h .= '</div>';
        return $h;
    }

    private function chart_panel_gauge( $ctx ) {
        $rate = $ctx['stage3_rate'] ?? null;
        if ( $rate === null ) return '';
        $url = $this->chart_url_gauge( $rate );

        list( $tag, $tag_class, $insight ) = $this->pace_insight( $rate );

        $h  = '<div class="cdp-chart-panel">';
        $h .= '<div class="cdp-chart-panel-head">';
        $h .= '<span class="cdp-chart-panel-label">Pace of ageing</span>';
        $h .= '<span class="cdp-chart-panel-value">' . esc_html( number_format( (float) $rate, 2 ) ) . '&times;</span>';
        $h .= '</div>';
        if ( $url ) {
            $h .= '<div class="cdp-chart-panel-img"><img src="' . esc_url( $url ) . '" alt="Pace of ageing gauge — ' . esc_attr( number_format( (float) $rate, 2 ) ) . '×" loading="lazy"></div>';
        }
        $h .= '<p class="cdp-chart-panel-cap cdp-chart-insight">';
        $h .= '<span class="cdp-chart-insight-tag cdp-chart-insight-tag-' . esc_attr( $tag_class ) . '">' . esc_html( $tag ) . '</span>';
        $h .= '<span>' . $insight . '</span>';
        $h .= '</p>';
        $h .= '</div>';
        return $h;
    }

    private function chart_panel_radar( $ctx ) {
        $scores = $ctx['stage3_scores'] ?? array();
        if ( empty( $scores ) ) return '';
        $url = $this->chart_url_radar( $scores );
        if ( ! $url ) return '';

        // Count only the spokes the radar actually plots (skinElasticity was
        // dropped by Matthew in v0.32.0 — it's in the score JSON but not the
        // radar). Mirror the same `$ordered` list HDLV2_Final_Report uses.
        $plotted_keys = array(
            'physicalActivity', 'sitToStand', 'breathHold', 'balance',
            'sleepDuration', 'sleepQuality', 'stressLevels', 'socialConnections',
            'dietQuality', 'alcoholConsumption', 'smokingStatus', 'cognitiveActivity',
            'sunlightExposure', 'supplementIntake', 'dailyHydration',
            'bmiScore', 'whrScore', 'whtrScore', 'bloodPressureScore', 'heartRateScore',
        );
        $plotted_count = 0;
        foreach ( $plotted_keys as $k ) if ( isset( $scores[ $k ] ) ) $plotted_count++;

        list( $tag, $tag_class, $insight ) = $this->radar_insight( $scores );
        $report_url = home_url( '/my-report/' );

        $h  = '<div class="cdp-chart-panel">';
        $h .= '<div class="cdp-chart-panel-head">';
        $h .= '<span class="cdp-chart-panel-label">Health profile</span>';
        $h .= '<span class="cdp-chart-panel-value cdp-chart-panel-value-sm">' . $plotted_count . ' metrics</span>';
        $h .= '</div>';
        $h .= '<div class="cdp-chart-panel-img"><img src="' . esc_url( $url ) . '" alt="Health profile radar — ' . $plotted_count . ' metrics on a 0-5 scale" loading="lazy"></div>';
        $h .= '<p class="cdp-chart-panel-cap cdp-chart-insight">';
        $h .= '<span class="cdp-chart-insight-tag cdp-chart-insight-tag-' . esc_attr( $tag_class ) . '">' . esc_html( $tag ) . '</span>';
        $h .= '<span>' . $insight . ' <a href="' . esc_url( $report_url ) . '">Detail in your report &rarr;</a></span>';
        $h .= '</p>';
        $h .= '</div>';
        return $h;
    }

    private function chart_panel_trajectory( $ctx ) {
        $chrono = $ctx['chrono_age'] ?? null;
        $rate   = $ctx['stage3_rate'] ?? $ctx['stage1_rate'] ?? null;
        if ( ! $chrono || ! $rate ) return '';
        $url = $this->chart_url_trajectory( $chrono, $rate );
        if ( ! $url ) return '';

        list( $tag, $tag_class, $insight ) = $this->trajectory_insight( $chrono, $rate );

        $h  = '<div class="cdp-chart-panel cdp-chart-panel-wide">';
        $h .= '<div class="cdp-chart-panel-head">';
        $h .= '<span class="cdp-chart-panel-label">Trajectory</span>';
        $h .= '<span class="cdp-chart-panel-value cdp-chart-panel-value-sm">Optimistic vs pessimistic</span>';
        $h .= '</div>';
        $h .= '<div class="cdp-chart-panel-img"><img src="' . esc_url( $url ) . '" alt="Biological age trajectory — chronological vs optimistic vs pessimistic" loading="lazy"></div>';
        $h .= '<p class="cdp-chart-panel-cap cdp-chart-insight">';
        $h .= '<span class="cdp-chart-insight-tag cdp-chart-insight-tag-' . esc_attr( $tag_class ) . '">' . esc_html( $tag ) . '</span>';
        $h .= '<span>' . $insight . '</span>';
        $h .= '<span class="cdp-chart-insight-meta"><span class="cdp-sw" style="background:#3d8da0"></span> With changes &middot; <span class="cdp-sw" style="background:#dc2626"></span> Without &middot; Re-projected each quarterly retake</span>';
        $h .= '</p>';
        $h .= '</div>';
        return $h;
    }

    private function chart_panel_effort( $ctx ) {
        $weekly = $ctx['weekly_completion'] ?? array();
        list( $tag, $tag_class, $insight ) = $this->effort_insight( $weekly );

        $h  = '<div class="cdp-chart-panel cdp-chart-panel-wide">';
        $h .= '<div class="cdp-chart-panel-head">';
        $h .= '<span class="cdp-chart-panel-label">Your effort</span>';
        $h .= '<span class="cdp-chart-panel-value cdp-chart-panel-value-sm">Plan completion week by week</span>';
        $h .= '</div>';
        $h .= '<div class="cdp-chart-panel-img">';
        $h .= $this->effort_svg( $weekly );
        $h .= '</div>';
        $h .= '<p class="cdp-chart-panel-cap cdp-chart-insight">';
        $h .= '<span class="cdp-chart-insight-tag cdp-chart-insight-tag-' . esc_attr( $tag_class ) . '">' . esc_html( $tag ) . '</span>';
        $h .= '<span>' . $insight . '</span>';
        $h .= '</p>';
        $h .= '</div>';
        return $h;
    }

    /**
     * Inline SVG line chart for plan completion (no external dep).
     * Empty placeholder if too few data points.
     */
    private function effort_svg( $weekly ) {
        if ( empty( $weekly ) ) {
            return '<div class="cdp-chart-empty">Your first check-in unlocks the trend.</div>';
        }
        $w = 600; $hgt = 220;
        $left = 44; $right = 580; $top = 20; $bot = 200;
        $count = count( $weekly );
        $points = array();
        $i = 0;
        foreach ( $weekly as $week_no => $pct ) {
            $x = $left + ( $count > 1 ? ( $right - $left ) * $i / ( $count - 1 ) : 0 );
            $y = $bot - ( ( $bot - $top ) * ( $pct / 100 ) );
            $points[] = array( 'x' => $x, 'y' => $y, 'wk' => $week_no, 'pct' => $pct );
            $i++;
        }
        $line_d = '';
        foreach ( $points as $p ) {
            $line_d .= ( $line_d ? ' L ' : 'M ' ) . round( $p['x'] ) . ' ' . round( $p['y'] );
        }
        $area_d = $line_d . ' L ' . round( end( $points )['x'] ) . ' ' . $bot . ' L ' . round( reset( $points )['x'] ) . ' ' . $bot . ' Z';

        $h  = '<svg viewBox="0 0 ' . $w . ' ' . $hgt . '" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:auto;display:block;" aria-label="Plan completion trend">';
        $h .= '<g stroke="#ebeef2" stroke-width="1">';
        for ( $g = 0; $g <= 4; $g++ ) {
            $gy = $top + ( $bot - $top ) * $g / 4;
            $h .= '<line x1="' . $left . '" y1="' . $gy . '" x2="' . $right . '" y2="' . $gy . '"/>';
        }
        $h .= '</g>';
        $h .= '<g font-family="Inter" font-size="10" fill="#3d8da0">';
        for ( $g = 0; $g <= 4; $g++ ) {
            $gy = $top + ( $bot - $top ) * $g / 4 + 4;
            $h .= '<text x="38" y="' . $gy . '" text-anchor="end">' . ( ( 4 - $g ) * 25 ) . '%</text>';
        }
        $h .= '</g>';
        $h .= '<path d="' . esc_attr( $area_d ) . '" fill="rgba(61,141,160,0.08)"/>';
        $h .= '<path d="' . esc_attr( $line_d ) . '" fill="none" stroke="#3d8da0" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>';
        $h .= '<g fill="#3d8da0" stroke="#fff" stroke-width="2">';
        foreach ( $points as $p ) {
            $h .= '<circle cx="' . round( $p['x'] ) . '" cy="' . round( $p['y'] ) . '" r="5"/>';
        }
        $h .= '</g>';
        $h .= '<g font-family="Inter" font-size="10" fill="#9ba0a8" text-anchor="middle">';
        foreach ( $points as $p ) {
            $h .= '<text x="' . round( $p['x'] ) . '" y="216">Wk ' . (int) $p['wk'] . '</text>';
        }
        $h .= '</g>';
        $h .= '</svg>';
        return $h;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Hero phrase + insights — computed from real data
    //  All return safe HTML (own escaping). Insight returns are
    //  [tag_text, tag_class('good'|'watch'|'meta'), one-sentence_html].
    // ──────────────────────────────────────────────────────────────────────

    private function build_hero_phrase( $ctx ) {
        $rate     = $ctx['stage3_rate'] ?? $ctx['stage1_rate'] ?? null;
        $bio_age  = $ctx['stage3_bio_age'] ?? null;
        $chrono   = $ctx['chrono_age'] ?? null;
        $pct      = (int) ( $ctx['plan_completion_pct'] ?? 0 );
        $checkins = (int) ( $ctx['checkins_count'] ?? 0 );

        $band = '';
        if ( $rate !== null ) {
            if ( $rate <= 0.95 )      $band = 'below the average band';
            elseif ( $rate <= 1.05 )  $band = 'inside the average band';
            elseif ( $rate <= 1.15 )  $band = 'just outside the average band';
            else                      $band = 'in the elevated band';
        }

        $parts = array();
        if ( $rate !== null ) {
            $parts[] = 'Your pace sits at <strong>' . esc_html( number_format( (float) $rate, 2 ) ) . '&times;</strong>'
                     . ( $band ? ' &mdash; already ' . $band : '' );
        }
        if ( $bio_age !== null && $chrono ) {
            $parts[] = 'Biological age <strong>' . esc_html( number_format( (float) $bio_age, 1 ) ) . '</strong>'
                     . ' against chronological <strong>' . (int) $chrono . '</strong>';
        }
        if ( $checkins > 0 ) {
            // "Latest check-in" wording is honest regardless of which week the
            // check-in covers — older code said "last week's plan" which read
            // wrong when the row was for the current week.
            $parts[] = 'Your latest check-in scored <strong>' . $pct . '%</strong>';
        } elseif ( $pct > 0 ) {
            $parts[] = 'And you&rsquo;re at <strong>' . $pct . '%</strong> of this week&rsquo;s plan so far';
        }
        $sentence = implode( '. ', $parts ) . '.';

        // Tail varies by data depth — gives a closing nudge.
        if ( $checkins >= 4 && $pct >= 60 ) {
            $tail = 'The work is doing what it&rsquo;s supposed to.';
        } elseif ( $checkins >= 2 ) {
            $tail = 'Two weeks of signal &mdash; keep going.';
        } else {
            $tail = 'Early days. Your first check-ins shape what comes next.';
        }

        return $sentence . ' <span class="cdp-hero-phrase-tail">' . $tail . '</span>';
    }

    private function pop_countdown_card( $ctx ) {
        $stage3_at = $ctx['stage3_completed_at'] ?? null;
        if ( ! $stage3_at ) return '';

        $start_ts = strtotime( $stage3_at . ' UTC' );
        if ( ! $start_ts ) return '';
        $retake_ts = $start_ts + ( 12 * 7 * DAY_IN_SECONDS ); // 12 weeks
        $now       = current_time( 'timestamp' );

        $days_total = 12 * 7;
        $days_done  = max( 0, min( $days_total, (int) floor( ( $now - $start_ts ) / DAY_IN_SECONDS ) ) );
        $days_left  = max( 0, (int) ceil( ( $retake_ts - $now ) / DAY_IN_SECONDS ) );

        // Ring math: circumference = 2 * π * 34 ≈ 213.6
        $circumference = 213.6;
        $offset = $circumference * ( 1 - $days_done / $days_total );

        $retake_label = date_i18n( 'j F Y', $retake_ts );
        $week_no = max( 1, (int) ceil( $days_done / 7 ) );

        $h  = '<div class="cdp-countdown-card cdp-reveal cdp-reveal-4">';
        $h .= '<div class="cdp-countdown-ring" aria-hidden="true">';
        $h .= '<svg viewBox="0 0 80 80">';
        $h .= '<circle cx="40" cy="40" r="34" fill="none" stroke="#ebeef2" stroke-width="6"/>';
        $h .= '<circle cx="40" cy="40" r="34" fill="none" stroke="#3d8da0" stroke-width="6" stroke-dasharray="' . $circumference . '" stroke-dashoffset="' . esc_attr( number_format( $offset, 2, '.', '' ) ) . '" transform="rotate(-90 40 40)" stroke-linecap="round"/>';
        $h .= '</svg>';
        $h .= '<div class="cdp-countdown-num">' . (int) $days_left . '</div>';
        $h .= '</div>';
        $h .= '<div class="cdp-countdown-body">';
        $h .= '<div class="cdp-countdown-eyebrow">Next pace check</div>';
        $h .= '<h3>Week&nbsp;12 retake &middot; ' . esc_html( $retake_label ) . '</h3>';
        $h .= '<p>You&rsquo;re ' . ( $days_done > 0 ? sprintf( '%d&nbsp;day%s into', $days_done, $days_done === 1 ? '' : 's' ) : 'just starting' )
            . ' your quarterly cycle. The next re-measurement is what proves the trend on the gauge above.</p>';
        $h .= '</div>';
        $h .= '<a href="#tab-today" class="cdp-countdown-cta">What changes between now and then &rarr;</a>';
        $h .= '</div>';
        return $h;
    }

    /** @return array{0:string,1:string,2:string} [tag, tag_class, insight_html] */
    private function pace_insight( $rate ) {
        $r = (float) $rate;
        if ( $r <= 0.95 ) {
            return array( 'Optimal pace', 'good',
                'Your body is ageing slower than its chronological years. Sustained habits are working in your favour.' );
        }
        if ( $r <= 1.05 ) {
            return array( 'On track', 'good',
                'Your work is showing &mdash; pace is already inside the average band, off the elevated zone.' );
        }
        if ( $r <= 1.15 ) {
            return array( 'Slightly elevated', 'watch',
                'Above the average band but workable. Sustained effort across the next 12 weeks will pull this down.' );
        }
        return array( 'Focus area', 'watch',
            'Above the average band. Your weekly plan is targeted at this number &mdash; the Week&nbsp;12 retake will show progress.' );
    }

    /** @return array{0:string,1:string,2:string} [tag, tag_class, insight_html] */
    private function radar_insight( $scores ) {
        // Group the 21-ish per-metric scores into 5 readable domains.
        $domains = array(
            'Body composition' => array( 'bmiScore', 'whrScore', 'whtrScore' ),
            'Cardiovascular'   => array( 'bloodPressureScore', 'heartRateScore' ),
            'Fitness'          => array( 'physicalActivity', 'sitToStand', 'breathHold', 'balance' ),
            'Sleep'            => array( 'sleepDuration', 'sleepQuality' ),
            'Lifestyle'        => array( 'dietQuality', 'alcoholConsumption', 'smokingStatus', 'sunlightExposure', 'supplementIntake', 'dailyHydration' ),
        );
        $avg = array();
        foreach ( $domains as $name => $keys ) {
            $vals = array();
            foreach ( $keys as $k ) {
                if ( isset( $scores[ $k ] ) && is_numeric( $scores[ $k ] ) ) {
                    $vals[] = (float) $scores[ $k ];
                }
            }
            if ( $vals ) $avg[ $name ] = array_sum( $vals ) / count( $vals );
        }
        if ( empty( $avg ) ) {
            return array( 'Snapshot', 'meta', 'Captured from your Stage&nbsp;3 measurements.' );
        }
        arsort( $avg );
        $domain_keys = array_keys( $avg );
        $weakest     = end( $domain_keys );
        $top1        = $domain_keys[0];
        $top2        = $domain_keys[1] ?? null;
        $top1_score  = $avg[ $top1 ];
        $top2_score  = $top2 ? $avg[ $top2 ] : 0;

        $tag       = $top1_score >= 4.0 ? 'Strong' : ( $top1_score >= 3.0 ? 'Solid' : 'Lifting' );
        $tag_class = $top1_score >= 3.0 ? 'good'   : 'watch';

        // Pair the top two strongest if both are ≥ 4 (matches mockup phrasing
        // for clients with 2+ strong domains).
        if ( $top2 && $top2_score >= 4.0 ) {
            $insight = '<strong>' . esc_html( $top1 ) . '</strong> + <strong>' . esc_html( $top2 ) . '</strong> are your strongest spokes. <strong>' . esc_html( $weakest ) . '</strong> is the area we&rsquo;re lifting next.';
        } else {
            $insight = '<strong>' . esc_html( $top1 ) . '</strong> is your strongest spoke. <strong>' . esc_html( $weakest ) . '</strong> is the area we&rsquo;re lifting next.';
        }
        return array( $tag, $tag_class, $insight );
    }

    /** @return array{0:string,1:string,2:string} [tag, tag_class, insight_html] */
    private function trajectory_insight( $chrono, $rate ) {
        $chrono = (int) $chrono;
        $rate   = (float) $rate;
        // Crude forward projection: bio age grows at $rate per chrono year.
        // Optimistic: rate trends to 1.0 (population avg). Pessimistic: rate stays.
        $years_ahead = 20;
        $target_chrono = $chrono + $years_ahead;
        // Pessimistic: bio age = chrono + (rate-1)*years_into_future, anchored at current
        // Optimistic: assume rate decays linearly to 1.0 by year 12 then holds at 1.0.
        $pess = $chrono + $rate * $years_ahead;
        $opt  = $chrono;
        for ( $y = 1; $y <= $years_ahead; $y++ ) {
            $r = $y <= 3 ? max( 1.0, $rate - ( $rate - 1.0 ) * ( $y / 3 ) ) : 1.0;
            $opt += $r;
        }
        $diff = max( 0, round( $pess - $opt, 1 ) );

        if ( $diff >= 3 ) {
            $tag = $diff >= 5 ? sprintf( '%g years back', $diff ) : 'Material gain';
            $tag_class = 'good';
            $insight = sprintf(
                'Sustained at this effort, your projection lands at biological age <strong>%s</strong> by chronological %d &mdash; %s years recovered versus the no-changes line.',
                esc_html( number_format( $opt, 1 ) ), $target_chrono, esc_html( $diff )
            );
        } elseif ( $diff > 0 ) {
            $tag = 'Modest gain';
            $tag_class = 'good';
            $insight = sprintf(
                'Sustained effort projects to bio age <strong>%s</strong> at chrono %d &mdash; small but real distance from the no-changes line.',
                esc_html( number_format( $opt, 1 ) ), $target_chrono
            );
        } else {
            $tag = 'Hold the line';
            $tag_class = 'meta';
            $insight = sprintf(
                'Your pace is already near average. The trajectory line tracks closely with chronological &mdash; the goal is to keep it that way.'
            );
        }
        return array( $tag, $tag_class, $insight );
    }

    /** @return array{0:string,1:string,2:string} [tag, tag_class, insight_html] */
    private function effort_insight( $weekly ) {
        $n = count( $weekly );
        if ( $n === 0 ) {
            return array( 'Coming soon', 'meta', 'Your first check-in unlocks the trend. A tick on Today rolls up here weekly.' );
        }
        if ( $n === 1 ) {
            $only = (int) reset( $weekly );
            return array( 'Week 1 in', 'meta',
                sprintf( 'You hit <strong>%d%%</strong> in your first week. Two weeks unlocks the trend line.', $only ) );
        }
        $first = (int) reset( $weekly );
        $last  = (int) end( $weekly );
        $delta = $last - $first;
        if ( $delta >= 5 ) {
            return array( sprintf( 'Up %d points', $delta ), 'good',
                sprintf( 'Plan completion climbed from <strong>%d%%</strong> in Week&nbsp;1 to <strong>%d%%</strong> last week. A tick on Today rolls up here every Sunday.', $first, $last ) );
        }
        if ( $delta <= -5 ) {
            return array( sprintf( 'Down %d points', abs( $delta ) ), 'watch',
                sprintf( 'Plan completion dipped from <strong>%d%%</strong> to <strong>%d%%</strong>. A tick on Today rolls up here every Sunday.', $first, $last ) );
        }
        return array( 'Steady', 'good',
            sprintf( 'Plan completion holding around <strong>%d%%</strong>. Consistency over peaks &mdash; this is the goal.', $last ) );
    }

    // ── Chart URL builders — delegate to the canonical sources ────────────

    /**
     * Dashboard gauge — uses the Stage-1 / report-cover config (OG green/blue/orange
     * palette + valueLabel 1.07 centred + Slower/Average/Faster subtitle), NOT the
     * stage-3 stripped-down version Matthew tuned for the PDF (status palette,
     * no value label). The dashboard wants the more visually rich gauge so the
     * client immediately sees the band + the number without reading the panel
     * header. Same Chart.js gauge plugin, same QuickChart endpoint — just a
     * different config block.
     */
    private function chart_url_gauge( $rate ) {
        if ( ! $rate ) return '';
        $r = max( 0.8, min( 1.4, round( (float) $rate, 2 ) ) );

        if ( $r <= 0.9 )      { $sub_text = 'Slower';  $sub_color = 'rgba(67, 191, 85, 1)'; }
        elseif ( $r <= 1.1 )  { $sub_text = 'Average'; $sub_color = 'rgba(65, 165, 238, 1)'; }
        else                  { $sub_text = 'Faster';  $sub_color = 'rgba(255, 111, 75, 1)'; }

        $cfg = array(
            'type' => 'gauge',
            'data' => array(
                'labels' => array( 'Slower', 'Average', 'Faster' ),
                'datasets' => array( array(
                    'data' => array( 0.9, 1.1, 1.4 ),
                    'value' => $r,
                    'minValue' => 0.8, 'maxValue' => 1.4,
                    'backgroundColor' => array(
                        'rgba(67,191,85,0.95)',   // green — slower (good)
                        'rgba(65,165,238,0.95)',  // blue  — average
                        'rgba(255,111,75,0.95)',  // orange — faster
                    ),
                    'borderWidth' => 1,
                    'borderColor' => 'rgba(255,255,255,0.8)',
                    'borderRadius' => 5,
                ) ),
            ),
            'options' => array(
                'layout' => array( 'padding' => array( 'top' => 30, 'bottom' => 15, 'left' => 15, 'right' => 15 ) ),
                'plugins' => array( 'datalabels' => array( 'display' => false ) ),
                'needle' => array(
                    'radiusPercentage' => 2.5, 'widthPercentage' => 4, 'lengthPercentage' => 68,
                    'color' => '#004F59', 'shadowColor' => 'rgba(0,79,89,0.4)', 'shadowBlur' => 8,
                    'shadowOffsetY' => 4, 'borderWidth' => 2, 'borderColor' => 'rgba(255,255,255,1)',
                ),
                'valueLabel' => array(
                    'display' => true,
                    'fontSize' => 36,
                    'fontFamily' => "'Inter',sans-serif",
                    'fontWeight' => 'bold',
                    'color' => '#004F59',
                    'backgroundColor' => 'transparent',
                    'bottomMarginPercentage' => -10,
                    'padding' => 8,
                ),
                'centerArea' => array( 'displayText' => false, 'backgroundColor' => 'transparent' ),
                'arc' => array( 'borderWidth' => 0, 'padding' => 2, 'margin' => 3, 'roundedCorners' => true ),
                'subtitle' => array(
                    'display' => true,
                    'text' => $sub_text,
                    'color' => $sub_color,
                    'font' => array( 'size' => 20, 'weight' => 'bold', 'family' => "'Inter',sans-serif" ),
                    'padding' => array( 'top' => 8 ),
                ),
            ),
        );
        return 'https://quickchart.io/chart?c=' . rawurlencode( wp_json_encode( $cfg ) ) . '&w=380&h=340&bkg=white';
    }
    private function chart_url_radar( $scores ) {
        if ( empty( $scores ) || ! class_exists( 'HDLV2_Final_Report' ) || ! method_exists( 'HDLV2_Final_Report', 'build_radar_chart_url' ) ) return '';
        return HDLV2_Final_Report::build_radar_chart_url( $scores );
    }
    private function chart_url_trajectory( $chrono, $rate ) {
        if ( ! $chrono || ! $rate || ! class_exists( 'HDLV2_Trajectory_SVG' ) || ! method_exists( 'HDLV2_Trajectory_SVG', 'url_for' ) ) return '';
        return HDLV2_Trajectory_SVG::url_for( (int) $chrono, (float) $rate );
    }

    // pop_completion_chart removed in v0.32.9 — superseded by effort_svg()
    // which lives inside the new charts card. The SVG-building math is the
    // same; only the wrapping (no separate card chrome) changed.

    private function pop_report_panel( $ctx ) {
        $report_url   = home_url( '/my-report/' );
        $issued       = $ctx['report_issued_at'] ? $this->fmt_date( $ctx['report_issued_at'] ) : '';
        $reviewer     = esc_html( $ctx['practitioner_name'] ?: 'Your practitioner' );
        $reviewer_raw = $ctx['report_finalised_by'] ?: ( $ctx['practitioner_name'] ?: '' );
        $pdf_url      = $ctx['report_pdf_url'] ?? '';
        $report_type  = $ctx['report_type'] ?? '';
        $is_final     = ( $report_type === 'final' );
        $status_label = $is_final ? 'Final' : 'Draft';
        $display_name = esc_html( $ctx['display_name'] ?? $ctx['first_name'] );

        $h = '<section data-tab-panel="report" class="cdp-panel">';

        // ── Cover ──────────────────────────────────────────────────────────
        $h .= '<div class="cdp-report-cover cdp-reveal cdp-reveal-1">';
        $h .= '<div class="cdp-report-meta">Your Trajectory Plan &middot; <span class="cdp-report-badge cdp-report-badge-' . ( $is_final ? 'final' : 'draft' ) . '">' . esc_html( $status_label ) . '</span></div>';
        $h .= '<h1>' . $display_name . '</h1>';
        $h .= '<p class="cdp-report-subtitle">';
        if ( $is_final && $reviewer_raw && $issued ) {
            $h .= 'A personalised plan, reviewed and finalised by ' . esc_html( $reviewer_raw ) . ' on ' . esc_html( $issued ) . '.';
        } elseif ( $reviewer_raw && $issued ) {
            $h .= 'Draft prepared by ' . esc_html( $reviewer_raw ) . ' on ' . esc_html( $issued ) . '. Final consultation pending.';
        } elseif ( $issued ) {
            $h .= 'Your personalised longevity plan. Issued ' . esc_html( $issued ) . '.';
        } else {
            $h .= 'Your personalised longevity plan.';
        }
        $h .= '</p>';
        $h .= '<div class="cdp-cover-actions">';
        $h .= '<a href="' . esc_url( $report_url ) . '" class="cdp-btn cdp-btn-light cdp-btn-large">Open my report &rarr;</a>';
        if ( $pdf_url ) {
            $h .= '<a href="' . esc_url( $pdf_url ) . '" target="_blank" rel="noopener noreferrer" class="cdp-btn cdp-btn-line cdp-btn-large">Download PDF</a>';
        }
        $h .= '</div>';
        $h .= '</div>';

        // ── At-a-glance meta strip — 5 stats ───────────────────────────────
        $rate = $ctx['stage3_rate'] ?? $ctx['stage1_rate'] ?? null;
        $h .= '<div class="cdp-report-meta-strip cdp-reveal cdp-reveal-2">';
        if ( $rate !== null ) {
            $h .= '<div class="cdp-mi"><div class="cdp-mi-label">Ageing pace</div><div class="cdp-mi-val">' . esc_html( number_format( (float) $rate, 2 ) ) . '&times;</div></div>';
        }
        if ( ! empty( $ctx['stage3_bio_age'] ) ) {
            $h .= '<div class="cdp-mi"><div class="cdp-mi-label">Biological age</div><div class="cdp-mi-val">' . esc_html( number_format( (float) $ctx['stage3_bio_age'], 1 ) ) . '&nbsp;yrs</div></div>';
        }
        if ( ! empty( $ctx['stage3_bmi'] ) ) {
            $h .= '<div class="cdp-mi"><div class="cdp-mi-label">Body mass</div><div class="cdp-mi-val">' . esc_html( number_format( (float) $ctx['stage3_bmi'], 1 ) ) . '&nbsp;BMI</div></div>';
        }
        if ( $issued ) {
            $h .= '<div class="cdp-mi"><div class="cdp-mi-label">Issued</div><div class="cdp-mi-val">' . esc_html( $issued ) . '</div></div>';
        }
        $h .= '<div class="cdp-mi"><div class="cdp-mi-label">Reviewed by</div><div class="cdp-mi-val">' . $reviewer . '</div></div>';
        $h .= '</div>';

        // ── 3 priorities (AWAKEN · LIFT · THRIVE) ──────────────────────────
        if ( ! empty( $ctx['priorities'] ) ) {
            $h .= '<div class="cdp-recos-section cdp-reveal cdp-reveal-3">';
            $h .= '<div class="cdp-eyebrow">Your priorities for the next 12 weeks</div>';
            $h .= '<h2 class="cdp-section-title">What ' . $reviewer . ' recommends</h2>';
            $h .= '<ol class="cdp-recos">';
            $i = 1;
            foreach ( $ctx['priorities'] as $p ) {
                $h .= '<li>';
                $h .= '<span class="cdp-num">' . $i . '</span>';
                $h .= '<div class="cdp-reco-body">';
                $h .= '<div class="cdp-reco-eyebrow">Priority&nbsp;' . $i . '</div>';
                $h .= '<h3>' . esc_html( $p['title'] ) . '</h3>';
                if ( ! empty( $p['lede'] ) ) {
                    $h .= '<p class="cdp-reco-lede">' . $p['lede'] . '</p>';
                }
                if ( ! empty( $p['body'] ) ) {
                    // The body is plain text with possible \n\n paragraphs.
                    $paragraphs = array_filter( array_map( 'trim', explode( "\n\n", $p['body'] ) ) );
                    foreach ( $paragraphs as $para ) {
                        $h .= '<p>' . esc_html( $para ) . '</p>';
                    }
                }
                $h .= '</div></li>';
                $i++;
            }
            $h .= '</ol>';

            // CTA below the priorities — drives back to /my-report/
            $h .= '<div class="cdp-recos-foot">';
            $h .= '<a href="' . esc_url( $report_url ) . '" class="cdp-btn cdp-btn-primary">Open the full report &rarr;</a>';
            if ( $pdf_url ) {
                $h .= '<a href="' . esc_url( $pdf_url ) . '" target="_blank" rel="noopener noreferrer" class="cdp-btn cdp-btn-line">Download PDF</a>';
            }
            $h .= '</div>';

            $h .= '</div>';
        } else {
            $h .= '<div class="cdp-card cdp-card-quiet cdp-reveal cdp-reveal-3">';
            $h .= '<p>The full report contains your practitioner&rsquo;s recommendations and personalised plan. <a href="' . esc_url( $report_url ) . '">Open the full report &rarr;</a></p>';
            $h .= '</div>';
        }

        $h .= '</section>';
        return $h;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Populated context loader — pulls everything for all 3 tabs.
    // ──────────────────────────────────────────────────────────────────────

    private function load_populated_context( $user_id ) {
        global $wpdb;
        $base = $this->load_context( $user_id );
        $progress_id = null;

        // v0.32.9 — pull the full progress row so we can read stage1+stage3
        // JSON in one shot. stage3_data.server_result is the canonical source
        // for the validated rate, biological age, BMI, and per-metric scores
        // that drive the gauge / radar / trajectory on the Progress tab.
        // v0.33.x — also pulls stage3_completed_at so the Week-12 countdown
        // card on Progress can anchor to the actual retake horizon.
        // v0.41.17 — `AND deleted_at IS NULL`. /my-dashboard/ must show
        // the active lifecycle, not an archived one.
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, stage1_data, stage3_data, stage3_completed_at
             FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE client_user_id = %d AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1",
            $user_id
        ) );
        if ( $progress ) $progress_id = (int) $progress->id;
        $stage3_completed_at = $progress ? $progress->stage3_completed_at : null;

        $chrono_age = null;
        $stage3_rate = null;
        $stage3_bio_age = null;
        $stage3_scores = array();
        if ( $progress && $progress->stage1_data ) {
            $s1 = json_decode( $progress->stage1_data, true );
            if ( is_array( $s1 ) ) {
                if ( isset( $s1['q1_age'] ) ) $chrono_age = (int) $s1['q1_age'];
                elseif ( isset( $s1['server_result']['q1_age'] ) ) $chrono_age = (int) $s1['server_result']['q1_age'];
            }
        }
        $stage3_bmi  = null;
        $stage3_whr  = null;
        $stage3_whtr = null;
        if ( $progress && $progress->stage3_data ) {
            $s3 = json_decode( $progress->stage3_data, true );
            if ( is_array( $s3 ) && isset( $s3['server_result'] ) && is_array( $s3['server_result'] ) ) {
                $sr = $s3['server_result'];
                if ( isset( $sr['rate'] ) )    $stage3_rate    = (float) $sr['rate'];
                if ( isset( $sr['bio_age'] ) ) $stage3_bio_age = (float) $sr['bio_age'];
                if ( isset( $sr['bmi'] ) )     $stage3_bmi     = (float) $sr['bmi'];
                if ( isset( $sr['whr'] ) )     $stage3_whr     = (float) $sr['whr'];
                if ( isset( $sr['whtr'] ) )    $stage3_whtr    = (float) $sr['whtr'];
                if ( isset( $sr['scores'] ) && is_array( $sr['scores'] ) ) $stage3_scores = $sr['scores'];
            }
        }

        // v0.32.5 — Mirror rest_current() in class-hdlv2-flight-plan.php exactly:
        // the canonical "current week" is the most recent plan whose week_start
        // is in the past or today. Future-dated plans are filtered out so a
        // pre-staged Week N+1 doesn't pre-empt the active week. Same query the
        // practitioner side uses, so practitioner + client always agree on which
        // plan is "current" (and therefore on which ticks the client edits).
        // v0.41.17 — `AND deleted_at IS NULL`.
        $plan = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, week_number, week_start, status, identity_statement
             FROM {$wpdb->prefix}hdlv2_flight_plans
             WHERE client_id = %d AND week_start <= %s AND deleted_at IS NULL
             ORDER BY week_start DESC, id DESC LIMIT 1",
            $user_id,
            current_time( 'Y-m-d' )
        ) );

        $flight_plan = null;
        $ticks_by_day = array();
        if ( $plan ) {
            $flight_plan = array(
                'id'                 => (int) $plan->id,
                'week_number'        => (int) $plan->week_number,
                'week_start'         => $plan->week_start,
                'status'             => $plan->status,
                'identity_statement' => $plan->identity_statement,
            );
            // Pull tick_id too — Today tab needs it for POST /flight-plan/tick.
            // Order: by day, then by category in the same FOOD → FITNESS →
            // LIFESTYLE sequence the practitioner Flight Plan uses (so client
            // and practitioner read in identical order), then id for stability.
            // v0.41.17 — `AND deleted_at IS NULL`.
            $ticks = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, day, time_slot, category, action_text, ticked
                 FROM {$wpdb->prefix}hdlv2_flight_plan_ticks
                 WHERE flight_plan_id = %d AND deleted_at IS NULL
                 ORDER BY FIELD(day,'monday','tuesday','wednesday','thursday','friday','saturday','sunday'),
                          FIELD(category,'nutrition','movement','key_action'),
                          id ASC",
                (int) $plan->id
            ) );
            foreach ( $ticks as $t ) {
                $key = strtolower( $t->day );
                if ( ! isset( $ticks_by_day[ $key ] ) ) {
                    $ticks_by_day[ $key ] = array( 'total' => 0, 'done' => 0, 'actions' => array() );
                }
                $ticks_by_day[ $key ]['total']++;
                if ( (int) $t->ticked ) $ticks_by_day[ $key ]['done']++;
                $ticks_by_day[ $key ]['actions'][] = array(
                    'tick_id'     => (int) $t->id,
                    'category'    => $t->category,
                    'action_text' => $t->action_text,
                    'ticked'      => (int) $t->ticked,
                    'time_slot'   => $t->time_slot,
                );
            }
        }

        // Recent check-ins (last 5 confirmed).
        // v0.41.17 — `AND deleted_at IS NULL`.
        $checkins = array();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, week_start, summary, adherence_scores, has_flags, status, confirmed_at
             FROM {$wpdb->prefix}hdlv2_checkins
             WHERE client_id = %d AND deleted_at IS NULL
             ORDER BY week_start DESC, id DESC LIMIT 5",
            $user_id
        ) );
        foreach ( $rows as $r ) {
            $scores = $r->adherence_scores ? json_decode( $r->adherence_scores, true ) : array();
            $pct    = is_array( $scores ) && isset( $scores['overall'] ) ? (int) $scores['overall'] : 0;
            $when   = $r->week_start ? date_i18n( 'D j M', strtotime( $r->week_start ) ) : '';

            // checkins.summary is stored as a JSON blob from the AI summariser
            // (fields: check_in_summary, wins[], obstacles[], flags[], etc.).
            // Earlier dashboards rendered the raw JSON. Extract the readable
            // paragraph; surface up to 2 wins + 2 obstacles as chips. Fall back
            // to the raw string for legacy plain-text rows.
            $summary_text = '';
            $wins         = array();
            $obstacles    = array();
            $raw          = trim( (string) $r->summary );
            if ( $raw !== '' && $raw[0] === '{' ) {
                $decoded = json_decode( $raw, true );
                if ( is_array( $decoded ) ) {
                    $summary_text = (string) ( $decoded['check_in_summary'] ?? '' );
                    if ( isset( $decoded['wins'] ) && is_array( $decoded['wins'] ) ) {
                        $wins = array_values( array_filter( $decoded['wins'], 'is_string' ) );
                    }
                    if ( isset( $decoded['obstacles'] ) && is_array( $decoded['obstacles'] ) ) {
                        $obstacles = array_values( array_filter( $decoded['obstacles'], 'is_string' ) );
                    }
                }
            }
            if ( $summary_text === '' ) {
                $summary_text = $raw; // legacy plain-text fallback
            }

            $checkins[] = array(
                'when_label' => $when,
                'title'      => $r->week_start ? 'Week ending ' . date_i18n( 'j M', strtotime( $r->week_start ) ) : 'Check-in',
                'summary'    => $summary_text,
                'wins'       => array_slice( $wins, 0, 2 ),
                'obstacles'  => array_slice( $obstacles, 0, 2 ),
                'pct'        => $pct,
                'has_flags'  => (int) $r->has_flags,
            );
        }

        // Timeline (last 5 client-visible entries).
        // v0.41.17 — `AND deleted_at IS NULL`. E2 (v0.46.46) — FIXED the
        // pre-existing always-empty bug: the table's column is `client_id`
        // (what HDLV2_Timeline::add_entry writes), not `client_user_id`;
        // the nonexistent column made the query error → zero rows forever.
        $timeline = array();
        $tl_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, entry_type, title, summary, date
             FROM {$wpdb->prefix}hdlv2_timeline
             WHERE client_id = %d AND ( is_private = 0 OR is_private IS NULL )
               AND deleted_at IS NULL
             ORDER BY date DESC, id DESC LIMIT 5",
            $user_id
        ) );
        foreach ( $tl_rows as $r ) {
            $when = $r->date ? date_i18n( 'D j M', strtotime( $r->date ) ) : '';
            $timeline[] = array(
                'when_label' => $when,
                'title'      => (string) $r->title,
                'summary'    => (string) $r->summary,
                'entry_type' => $r->entry_type,
            );
        }

        // Final report meta.
        $report_pdf_url      = '';
        $report_issued_at    = '';
        $report_finalised_by = '';
        $report_type         = '';
        $priorities          = array();
        if ( $progress_id ) {
            $report = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, report_type, pdf_url, report_content, updated_at, created_at, practitioner_user_id
                 FROM {$wpdb->prefix}hdlv2_reports
                 WHERE form_progress_id = %d AND report_type = 'final'
                 ORDER BY id DESC LIMIT 1",
                $progress_id
            ) );
            if ( ! $report ) {
                // No final yet — fall back to draft (the "consultation completed" path).
                $report = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id, report_type, pdf_url, report_content, updated_at, created_at, practitioner_user_id
                     FROM {$wpdb->prefix}hdlv2_reports
                     WHERE form_progress_id = %d AND report_type = 'draft'
                     ORDER BY id DESC LIMIT 1",
                    $progress_id
                ) );
            }
            if ( $report ) {
                $report_pdf_url   = (string) $report->pdf_url;
                $report_issued_at = $report->updated_at ?: $report->created_at;
                $report_type      = (string) $report->report_type;
                if ( $report->practitioner_user_id ) {
                    $rev = get_userdata( (int) $report->practitioner_user_id );
                    if ( $rev ) $report_finalised_by = $rev->display_name;
                }
                // The HDL coaching framework: AWAKEN — LIFT — THRIVE. Each is a
                // ~120-word HTML paragraph in report_content. We surface them as
                // the three priorities on the dashboard My Report tab. Source of
                // truth is the same JSON the PDF renders from.
                $priorities = $this->extract_priorities( (string) $report->report_content );
            }
        }

        // Stats.
        $weekly_completion = array();
        // v0.41.17 — `AND deleted_at IS NULL` so the weeks-tracked stat
        // doesn't include archived plans.
        $cnt = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hdlv2_flight_plans
             WHERE client_id = %d AND deleted_at IS NULL",
            $user_id
        ) );
        $weeks_tracked = (int) $cnt;

        // Plan completion: prefer last week's adherence, fall back to current ticks ratio.
        $last_pct = 0;
        $last_label = 'No data yet';
        if ( $checkins ) {
            $last_pct = (int) $checkins[0]['pct'];
            $last_label = 'Latest check-in';
        } elseif ( $ticks_by_day ) {
            $tot = 0; $done = 0;
            foreach ( $ticks_by_day as $d ) { $tot += $d['total']; $done += $d['done']; }
            if ( $tot > 0 ) {
                $last_pct = (int) round( $done * 100 / $tot );
                $last_label = 'This week so far';
            }
        }

        // Weekly completion series for the chart (one point per check-in).
        if ( $rows ) {
            // $rows is DESC; chart wants ASC.
            $reversed = array_reverse( $rows );
            $week_no = 1;
            foreach ( $reversed as $r ) {
                $sc = $r->adherence_scores ? json_decode( $r->adherence_scores, true ) : array();
                $weekly_completion[ $week_no ] = is_array( $sc ) && isset( $sc['overall'] ) ? (int) $sc['overall'] : 0;
                $week_no++;
            }
        }

        return $base + array(
            'flight_plan'            => $flight_plan,
            'ticks_by_day'           => $ticks_by_day,
            'checkins'               => $checkins,
            'checkins_count'         => count( $checkins ),
            'timeline'               => $timeline,
            'report_pdf_url'         => $report_pdf_url,
            'report_issued_at'       => $report_issued_at,
            'report_finalised_by'    => $report_finalised_by,
            'report_type'            => $report_type,
            'priorities'             => $priorities,
            'weeks_tracked'          => $weeks_tracked,
            'plan_completion_pct'    => $last_pct,
            'plan_completion_label'  => $last_label,
            'weekly_completion'      => $weekly_completion,
            // Stage 3 hero numbers (drive the Progress tab charts)
            'chrono_age'             => $chrono_age,
            'stage3_rate'            => $stage3_rate,
            'stage3_bio_age'         => $stage3_bio_age,
            'stage3_scores'          => $stage3_scores,
            'stage3_completed_at'    => $stage3_completed_at,
            'stage3_bmi'             => $stage3_bmi,
            'stage3_whr'             => $stage3_whr,
            'stage3_whtr'            => $stage3_whtr,
        );
    }

    /**
     * Extract the three AWAKEN-LIFT-THRIVE priorities from a final/draft report.
     *
     * `report_content` is a JSON blob with `awaken_content`, `lift_content`,
     * `thrive_content` keys, each containing 1-3 HTML paragraphs (~120 words
     * cap per Matthew's prompt directive). We keep the same paragraph block
     * structure but split into bullet points where natural.
     *
     * Returns array of [{title, body}, ...] in canonical AWAKEN → LIFT → THRIVE
     * order. Empty array on parse failure / missing keys.
     */
    private function extract_priorities( $report_content ) {
        if ( ! $report_content ) return array();
        $rc = json_decode( $report_content, true );
        if ( ! is_array( $rc ) ) return array();

        $sections = array(
            array(
                'key'   => 'awaken_content',
                'title' => 'Awaken',
                'lede'  => 'Where you are now &mdash; the foundation everything else builds on.',
            ),
            array(
                'key'   => 'lift_content',
                'title' => 'Lift',
                'lede'  => 'The areas with the biggest leverage. Lift these first.',
            ),
            array(
                'key'   => 'thrive_content',
                'title' => 'Thrive',
                'lede'  => 'Protect what works. Compound the gains over time.',
            ),
        );

        $out = array();
        foreach ( $sections as $s ) {
            if ( empty( $rc[ $s['key'] ] ) ) continue;
            $html = (string) $rc[ $s['key'] ];

            // Strip control HTML, keep paragraph structure as plain text.
            $text = preg_replace( '/<\/p>\s*<p[^>]*>/i', "\n\n", $html );
            $text = wp_strip_all_tags( $text );
            $text = preg_replace( "/\n{3,}/", "\n\n", $text );
            $text = trim( $text );
            if ( $text === '' ) continue;

            $out[] = array(
                'title' => $s['title'],
                'lede'  => $s['lede'],
                'body'  => $text,
            );
        }
        return $out;
    }

    private function category_label( $cat ) {
        switch ( $cat ) {
            case 'movement':   return 'Fitness';
            case 'nutrition':  return 'Food';
            case 'key_action': return 'Lifestyle';
        }
        return ucfirst( (string) $cat );
    }

    private function category_class( $cat ) {
        switch ( $cat ) {
            case 'movement':   return 'fitness';
            case 'nutrition':  return 'food';
            case 'key_action': return 'lifestyle';
        }
        return 'food';
    }

    private function render_empty_state( $user_id, $state ) {
        $ctx = $this->load_context( $user_id );

        // No custom site chrome — Divi already renders the page header / nav / footer.
        $h  = '<main class="hdlv2-cd shell" data-state="' . esc_attr( $state ) . '">';

        $h .= $this->hero_for_state( $ctx, $state );
        $h .= $this->path_for_state( $ctx, $state );
        $h .= $this->preview_for_state( $ctx, $state );
        $h .= '<h2 class="cd-section-title">How the assessment unfolds</h2>';
        $h .= $this->steps_grid( $state );
        $h .= $this->help_footer();

        $h .= '</main>';
        return $h;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Per-state fragments
    // ──────────────────────────────────────────────────────────────────────

    private function hero_for_state( $ctx, $state ) {
        $first = esc_html( $ctx['first_name'] );

        $titles = array(
            'brand-new'   => "Hi {$first} &mdash; let&rsquo;s start with the quick gauge.",
            'stage1-done' => "Hi {$first} &mdash; your gauge is in.",
            'stage2-done' => "Hi {$first} &mdash; your WHY is captured.",
            'stage3-done' => "Hi {$first} &mdash; your full assessment is in.",
        );

        $subs = array(
            'brand-new'   => 'Your practitioner has invited you in. Start with a 9-question snapshot &mdash; about three minutes &mdash; to see your pace of ageing.',
            'stage1-done' => 'Your practitioner is reviewing your Stage 1 result. They&rsquo;ll email you when the next step is ready.',
            'stage2-done' => 'Your practitioner is preparing your full assessment. They&rsquo;ll email you when it&rsquo;s ready to start.',
            'stage3-done' => 'Your practitioner is reviewing everything to write your personalised report and Flight Plan.',
        );

        $title = isset( $titles[ $state ] ) ? $titles[ $state ] : $titles['brand-new'];
        $sub   = isset( $subs[ $state ] )   ? $subs[ $state ]   : $subs['brand-new'];

        $h  = '<section class="cd-hero">';
        $h .= '<div class="cd-hero-row"><div>';
        $h .= '<p class="cd-hero-greeting">Welcome to HealthDataLab</p>';
        // Title strings already have escaped first_name + safe entities only.
        $h .= '<h1 class="cd-hero-title">' . $title . '</h1>';
        $h .= '<p class="cd-hero-sub">' . $sub . '</p>';
        $h .= '</div></div></section>';
        return $h;
    }

    private function path_for_state( $ctx, $state ) {
        $practitioner = esc_html( $ctx['practitioner_name'] ?: 'Your practitioner' );

        $h = '<div class="cd-paths">';

        if ( $state === 'brand-new' ) {
            // v0.46.23 — Send the client to the V2 widget (the 9-question gauge
            // this card actually describes), NOT the old V1 longevity form.
            // Carry their assessment-link invite token so the lead attributes
            // to the inviting practitioner + their own form_progress — the
            // widget page's bare practitioner_id default (122 on STBY) would
            // otherwise mis-file the submission.
            $invite_token = isset( $ctx['invite_token'] ) ? (string) $ctx['invite_token'] : '';
            $h .= '<div class="cd-path cd-path-cta">';
            $h .= '<div class="cd-path-icon" aria-hidden="true">' . $this->icon( 'play' ) . '</div>';
            $h .= '<h3>Take the quick gauge</h3>';
            $h .= '<p>9 questions, ~3 minutes. You&rsquo;ll see your pace-of-ageing result straight after, and your practitioner will be notified.</p>';
            if ( $invite_token !== '' ) {
                $cta_url = add_query_arg( 'invite', $invite_token, home_url( '/rate-of-ageing-widget/' ) );
                $h .= '<a href="' . esc_url( $cta_url ) . '" class="cd-btn">Start now &rarr;</a>';
            } else {
                // No live invite link to attribute the submission against —
                // sending them to the bare widget would mis-file the lead under
                // the page's default practitioner. Ask for a fresh link instead
                // of handing them a broken Start button.
                $h .= '<p class="cd-note" style="margin:6px 0 0;color:#888;font-size:14px;">Your assessment link isn&rsquo;t active yet &mdash; please ask your practitioner for your invitation link to begin.</p>';
            }
            $h .= '</div>';
        } elseif ( $state === 'stage1-done' ) {
            $h .= '<div class="cd-path">';
            $h .= '<div class="cd-path-icon" aria-hidden="true">' . $this->icon( 'clock' ) . '</div>';
            $h .= '<h3>Step 1 done &mdash; waiting on your practitioner</h3>';
            $h .= '<p>' . $practitioner . ' is reviewing your gauge result. They&rsquo;ll release the next step (Your WHY) when the moment is right &mdash; usually within a few days. You&rsquo;ll get an email.</p>';
            $h .= '</div>';
        } elseif ( $state === 'stage2-done' ) {
            $h .= '<div class="cd-path">';
            $h .= '<div class="cd-path-icon" aria-hidden="true">' . $this->icon( 'clock' ) . '</div>';
            $h .= '<h3>Step 2 done &mdash; waiting on your practitioner</h3>';
            $h .= '<p>' . $practitioner . ' is reviewing your WHY. They&rsquo;ll release the full assessment when it&rsquo;s the right moment for you. You&rsquo;ll get an email.</p>';
            $h .= '</div>';
        } else { // stage3-done
            $h .= '<div class="cd-path">';
            $h .= '<div class="cd-path-icon" aria-hidden="true">' . $this->icon( 'doc' ) . '</div>';
            $h .= '<h3>Step 3 done &mdash; your practitioner is drafting your report</h3>';
            $h .= '<p>' . $practitioner . ' is reviewing your assessment and writing your personalised report. You&rsquo;ll be invited to a consultation when it&rsquo;s ready.</p>';
            $h .= '</div>';
        }

        $h .= '</div>';
        return $h;
    }

    private function preview_for_state( $ctx, $state ) {
        // brand-new: no captured data yet, nothing to surface.
        if ( $state === 'brand-new' ) return '';

        $h = '';

        // v0.32.2 — Stage 1 gauge surfaces on every state from stage1-done onward
        // so the client always sees their captured pace-of-ageing while waiting.
        if ( in_array( $state, array( 'stage1-done', 'stage2-done', 'stage3-done' ), true ) ) {
            $h .= $this->preview_gauge( $ctx );
        }

        // v0.32.2 — WHY pull-quote surfaces on every state from stage2-done onward
        // for the same reason: data they handed over should never be invisible.
        if ( in_array( $state, array( 'stage2-done', 'stage3-done' ), true ) ) {
            $h .= $this->preview_why( $ctx );
        }

        return $h;
    }

    private function preview_gauge( $ctx ) {
        $rate = isset( $ctx['stage1_rate'] ) ? (float) $ctx['stage1_rate'] : null;
        if ( ! $rate ) return '';
        $captured = $ctx['stage1_captured_at'] ? $this->fmt_date( $ctx['stage1_captured_at'] ) : '';

        $h  = '<div class="cd-preview">';
        // v0.46.25 — show the REAL value-driven speedometer (the same QuickChart
        // gauge used on the Progress tab + the Stage-1 widget), replacing the old
        // decorative static arc that drew the identical shape for every rate.
        $gauge_url = $this->chart_url_gauge( $rate );
        if ( $gauge_url ) {
            $h .= '<img class="cd-gauge-real" src="' . esc_url( $gauge_url ) . '" width="380" height="340" alt="' . esc_attr( 'Pace of ageing gauge — ' . number_format( $rate, 2 ) . '×' ) . '" loading="lazy">';
        }
        $h .= '<div class="cd-preview-meta">';
        $h .= '<span class="big">' . esc_html( number_format( $rate, 2 ) ) . '&times;</span>';
        $h .= 'Your pace of ageing &mdash; the starting point for everything that comes next.';
        $h .= '</div>';
        if ( $captured ) {
            $h .= '<div class="cd-preview-foot"><span>Captured ' . esc_html( $captured ) . '</span></div>';
        }
        $h .= '</div>';
        return $h;
    }

    private function preview_why( $ctx ) {
        $why = $ctx['why_text'];
        if ( ! $why ) return '';
        $captured = $ctx['why_captured_at'] ? $this->fmt_date( $ctx['why_captured_at'] ) : '';

        $h  = '<div class="cd-preview">';
        $h .= '<p class="cd-preview-quote">' . esc_html( $why ) . '</p>';
        if ( $captured ) {
            $h .= '<div class="cd-preview-foot"><span>Captured ' . esc_html( $captured ) . '</span></div>';
        }
        $h .= '</div>';
        return $h;
    }

    private function steps_grid( $state ) {
        $map = array(
            'brand-new'   => array( 'current' => 1, 'done' => array(),         'tags' => array( 1 => 'In progress',              2 => 'Up next',                  3 => 'Up next', 4 => 'Up next' ) ),
            'stage1-done' => array( 'current' => 2, 'done' => array(1),        'tags' => array( 1 => 'Done',                    2 => 'Waiting on practitioner', 3 => 'Up next', 4 => 'Up next' ) ),
            'stage2-done' => array( 'current' => 3, 'done' => array(1, 2),     'tags' => array( 1 => 'Done',                    2 => 'Done',                     3 => 'Waiting on practitioner', 4 => 'Up next' ) ),
            'stage3-done' => array( 'current' => 4, 'done' => array(1, 2, 3),  'tags' => array( 1 => 'Done',                    2 => 'Done',                     3 => 'Done',                     4 => 'Practitioner reviewing' ) ),
        );
        $info = isset( $map[ $state ] ) ? $map[ $state ] : $map['brand-new'];

        $bodies = array(
            1 => array( 'Stage 1 &middot; Quick insight',     '9 questions, ~3 minutes. You see your pace-of-ageing gauge straight away.' ),
            2 => array( 'Stage 2 &middot; Your WHY',          'Your practitioner releases this when ready. You record what matters most &mdash; speak it or type it.' ),
            3 => array( 'Stage 3 &middot; Full detail',       '21 measurements across 5 sections. Auto-drafts your Trajectory Plan for your practitioner&rsquo;s review.' ),
            4 => array( 'Consultation &middot; Trajectory Plan', 'Your practitioner edits, adds notes, finalises. PDF + Flight Plan delivered to you.' ),
        );

        $h = '<ol class="cd-steps">';
        for ( $i = 1; $i <= 4; $i++ ) {
            $cls = '';
            if ( in_array( $i, $info['done'], true ) ) $cls .= ' done';
            if ( $info['current'] === $i ) $cls .= ' current';

            list( $title, $body ) = $bodies[ $i ];
            $tag = isset( $info['tags'][ $i ] ) ? $info['tags'][ $i ] : '';

            $h .= '<li class="' . esc_attr( trim( $cls ) ) . '">';
            $h .= '<strong>' . $title . '<span class="cd-step-tag">' . esc_html( $tag ) . '</span></strong>';
            $h .= $body;
            $h .= '</li>';
        }
        $h .= '</ol>';
        return $h;
    }

    private function help_footer() {
        $help_url = apply_filters( 'hdlv2_client_help_url', 'https://healthdatalab.com/help-centre/' );
        $h  = '<div class="cd-help">';
        $h .= '<span><strong>Need a hand?</strong> Reply to any of your practitioner&rsquo;s emails &mdash; they&rsquo;re your first stop. For platform questions, the help centre has step-by-step guides.</span>';
        $h .= '<a href="' . esc_url( $help_url ) . '" target="_blank" rel="noopener noreferrer">Read guide &rarr;</a>';
        $h .= '</div>';
        return $h;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Site chrome (custom header + sub-bar; theme footer is whatever the
    //  page template provides). Distinct from the public-site nav.
    // ──────────────────────────────────────────────────────────────────────

    private function open_chrome( $crumb ) {
        $user = wp_get_current_user();
        $name = $user && $user->ID ? $user->display_name : '';
        $signout_url = wp_logout_url( apply_filters( 'hdlv2_client_signout_url', 'https://healthdatalab.com/' ) );

        $h  = '<header class="hdlv2-cd-sitehead">';
        $h .= '<div class="hdlv2-cd-sitehead-inner">';
        $h .= '<div class="hdlv2-cd-sitehead-logo">';
        $h .= '<svg viewBox="0 0 32 32" fill="none" aria-hidden="true"><circle cx="16" cy="16" r="14" stroke="#3d8da0" stroke-width="2"/><path d="M10 16 q3 -7 6 0 q3 7 6 0" stroke="#3d8da0" stroke-width="2" fill="none" stroke-linecap="round"/></svg>';
        $h .= 'HealthDataLab';
        $h .= '</div>';
        $h .= '<nav><span class="cd-nav-active">My dashboard</span><a href="https://healthdatalab.com/help-centre/" target="_blank" rel="noopener noreferrer">Resources</a></nav>';
        $h .= '</div>';
        $h .= '</header>';

        $h .= '<nav class="hdlv2-cd-topnav"><div class="hdlv2-cd-topnav-inner">';
        $h .= '<span><a href="' . esc_url( $signout_url ) . '">&larr; Sign out</a></span>';
        $h .= '<span class="cd-crumb">My dashboard &rsaquo; <strong>' . esc_html( $crumb ) . '</strong></span>';
        $h .= '<span>' . esc_html( $name ) . '</span>';
        $h .= '</div></nav>';

        return $h;
    }

    private function close_chrome() {
        return '';
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function load_context( $user_id ) {
        global $wpdb;

        $user  = get_userdata( $user_id );
        $first = $user && $user->first_name ? $user->first_name : ( $user ? $user->display_name : 'there' );
        $first = trim( explode( ' ', (string) $first )[0] );
        // Always present the first name with a capital first letter so a
        // stored display_name like "kim" reads as "Kim" in the dashboard.
        if ( $first !== '' ) $first = function_exists( 'mb_convert_case' )
            ? mb_convert_case( mb_strtolower( $first, 'UTF-8' ), MB_CASE_TITLE, 'UTF-8' )
            : ucfirst( strtolower( $first ) );

        // v0.41.17 — `AND deleted_at IS NULL`. /my-dashboard/ greeting +
        // practitioner name + Stage 1 rate must come from the active
        // lifecycle, not an archived one.
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, practitioner_user_id, stage1_data, stage1_completed_at
             FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE client_user_id = %d AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1",
            $user_id
        ) );

        $practitioner_name = '';
        $stage1_rate = null;
        $stage1_captured_at = null;
        $why_text = '';
        $why_captured_at = null;

        if ( $progress ) {
            if ( $progress->practitioner_user_id ) {
                $prac = get_userdata( (int) $progress->practitioner_user_id );
                if ( $prac ) {
                    $practitioner_name = $prac->display_name;
                }
            }

            if ( $progress->stage1_data ) {
                $stage1 = json_decode( $progress->stage1_data, true );
                if ( is_array( $stage1 ) ) {
                    // Real STBY shape (verified 2026-05-05): rate lives at
                    // stage1_data.server_result.rate. Older/test rows may use
                    // top-level rate or rate_of_aging — accept either.
                    if ( isset( $stage1['server_result']['rate'] ) ) $stage1_rate = (float) $stage1['server_result']['rate'];
                    elseif ( isset( $stage1['rate'] ) )              $stage1_rate = (float) $stage1['rate'];
                    elseif ( isset( $stage1['rate_of_aging'] ) )     $stage1_rate = (float) $stage1['rate_of_aging'];
                }
            }
            if ( $progress->stage1_completed_at ) $stage1_captured_at = $progress->stage1_completed_at;

            $why = $wpdb->get_row( $wpdb->prepare(
                "SELECT distilled_why, created_at FROM {$wpdb->prefix}hdlv2_why_profiles
                 WHERE form_progress_id = %d ORDER BY id DESC LIMIT 1",
                (int) $progress->id
            ) );
            if ( $why ) {
                $why_text = (string) $why->distilled_why;
                $why_captured_at = $why->created_at;
            }
        }

        // Full display name (capitalise too — covers the "kim" → "Kim" case).
        $display_name = $user ? trim( (string) $user->display_name ) : $first;
        if ( $display_name === '' ) $display_name = $first;
        if ( function_exists( 'mb_convert_case' ) ) {
            $display_name = mb_convert_case( $display_name, MB_CASE_TITLE, 'UTF-8' );
        } else {
            $display_name = ucwords( strtolower( $display_name ) );
        }

        // v0.46.23 — Brand-new clients reach Stage 1 through the V2 widget, not
        // the old V1 longevity form. Surface their still-valid assessment-link
        // invite token so the "Start now" CTA can carry it (mirrors the invite-
        // email URL site_url('/rate-of-ageing-widget/?invite=TOKEN')). The token
        // is what makes the widget resolve the *inviting* practitioner and
        // attribute the lead to this client's own form_progress instead of the
        // widget page's default practitioner_id. Statuses pending/opened are the
        // pre-submit, still-usable states (opened is set on magic-link login);
        // completed/expired/revoked are intentionally excluded.
        $invite_token = '';
        $email = $user ? (string) $user->user_email : '';
        if ( $email !== '' ) {
            $now = current_time( 'mysql', true ); // GMT — matches gmdate()-stored expires_at
            $invite = $wpdb->get_row( $wpdb->prepare(
                "SELECT token FROM {$wpdb->prefix}hdlv2_widget_invites
                 WHERE client_email = %s AND status IN ('pending','opened')
                   AND expires_at > %s
                 ORDER BY id DESC LIMIT 1",
                $email, $now
            ) );
            if ( $invite && $invite->token ) {
                $invite_token = (string) $invite->token;
            }
        }

        return array(
            'first_name'         => $first,
            'display_name'       => $display_name,
            'practitioner_name'  => $practitioner_name,
            'invite_token'       => $invite_token,
            'stage1_rate'        => $stage1_rate,
            'stage1_captured_at' => $stage1_captured_at,
            'why_text'           => $why_text,
            'why_captured_at'    => $why_captured_at,
        );
    }

    private function fmt_date( $mysql_datetime ) {
        $ts = strtotime( $mysql_datetime . ' UTC' );
        if ( ! $ts ) return '';
        return date_i18n( 'j M Y', $ts );
    }

    private function path_card( $title, $body, $cta_label, $cta_url, $icon ) {
        $h  = '<div class="cd-path">';
        $h .= '<div class="cd-path-icon" aria-hidden="true">' . $this->icon( $icon ) . '</div>';
        $h .= '<h3>' . esc_html( $title ) . '</h3>';
        $h .= '<p>' . $body . '</p>';
        $h .= '<a href="' . esc_url( $cta_url ) . '" class="cd-btn">' . $cta_label . '</a>';
        $h .= '</div>';
        return $h;
    }

    private function icon( $name ) {
        switch ( $name ) {
            case 'play':
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
            case 'clock':
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
            case 'doc':
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>';
            case 'plan':
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
            case 'mic':
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/></svg>';
        }
        return '';
    }

    private function card( $title, $body, $cta_label, $cta_url ) {
        return '<div style="max-width:560px;margin:60px auto;padding:40px 32px;background:#fff;border:1px solid #e4e6ea;border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,0.04);text-align:center;font-family:Inter,-apple-system,sans-serif;">'
             . '<h2 style="font-family:Source Serif Pro,Georgia,serif;font-size:24px;font-weight:600;color:#111;margin:0 0 10px;">' . esc_html( $title ) . '</h2>'
             . '<p style="font-size:14px;color:#666;margin:0 0 24px;line-height:1.5;">' . esc_html( $body ) . '</p>'
             . '<a href="' . esc_url( $cta_url ) . '" style="display:inline-block;background:#3d8da0;color:#fff;padding:11px 24px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;font-family:Inter,sans-serif;">' . esc_html( $cta_label ) . ' &rarr;</a>'
             . '</div>';
    }
}
