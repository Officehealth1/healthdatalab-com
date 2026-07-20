<?php
/**
 * HDL V2 — Client DRAFT Report view (Phase 5 / P0 #2)
 *
 * Surfaces the Claude-generated draft narrative + V1 trajectory + 21-metric
 * radar on a client-facing WordPress page. Read-only until practitioner
 * finalises (Final Report flow unchanged).
 *
 * Renders via shortcode [hdlv2_draft_report] on a WP page; data fetched
 * client-side from REST endpoint GET /reports/draft?token=<64hex>.
 *
 * Token auth reuses hdlv2_form_progress.token — same token the client
 * already uses for Stage 2/3. No new credentials.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class HDLV2_Client_Draft_View {

    private static $instance = null;

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_shortcode( 'hdlv2_draft_report', array( $this, 'render_shortcode' ) );
        // Defensive: wpdatatables enqueues Chart.js 3.9.1 globally with handle
        // `chartjs`, which clobbers our Chart.js 4.4.0 global. Dequeue it on
        // our page so only our version is loaded.
        add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_conflicting_chartjs' ), 999 );
    }

    public function dequeue_conflicting_chartjs() {
        if ( ! is_page( 'longevity-draft-report' ) ) return;
        wp_dequeue_script( 'chartjs' );
        wp_deregister_script( 'chartjs' );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST ROUTES
    // ──────────────────────────────────────────────────────────────

    public function register_routes() {
        register_rest_route( 'hdl-v2/v1', '/reports/draft', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_get_draft' ),
            'permission_callback' => '__return_true', // token-gated below
            'args'                => array(
                'token' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ) );
    }

    /**
     * GET /reports/draft?token=<64hex>
     *
     * Response:
     *   status: "generating" | "ready" | "error"
     *   client_name, practitioner_name, practitioner_logo_url
     *   calc: { chronoAge, bioAge, rate, bmi, whr, whtr, scores }
     *   ai_narrative: {...} | null   (present when status=ready)
     */
    public function rest_get_draft( $request ) {
        $token = $request->get_param( 'token' );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) $token ) ) {
            return new WP_Error( 'invalid_token', 'Invalid token.', array( 'status' => 404 ) );
        }

        global $wpdb;

        // v0.41.17 — `AND deleted_at IS NULL`. Stale tokens for soft-deleted
        // assessments must not load draft/final report data.
        //
        // v0.47.53 (B4) — token_expires_at also fetched: expiry is checked in
        // PHP (not SQL) because a cookie-authenticated caller who is the
        // client themself or the owning practitioner may still view the
        // report after the token expires — practitioners open dormant
        // clients' reports via this same route.
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, client_user_id, practitioner_user_id, client_name, stage1_data, stage3_data, stage3_completed_at, token_expires_at
             FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE token = %s AND deleted_at IS NULL
             LIMIT 1",
            $token
        ) );

        if ( ! $progress ) {
            return new WP_Error( 'not_found', 'Report not found.', array( 'status' => 404 ) );
        }

        // v0.47.53 (B4) — fail CLOSED on missing/garbage/past expiry unless
        // the caller is cookie-authenticated as the client or their owning
        // practitioner (HDLV2_Compatibility::practitioner_owns_client — the
        // standard IDOR helper; admins pass via its manage_options escape).
        $expired = empty( $progress->token_expires_at )
            || false === strtotime( $progress->token_expires_at . ' UTC' )
            || strtotime( $progress->token_expires_at . ' UTC' ) < time();
        if ( $expired ) {
            $uid      = get_current_user_id();
            $is_self  = $uid && (int) $uid === (int) $progress->client_user_id;
            $is_owner = $uid && class_exists( 'HDLV2_Compatibility' )
                && HDLV2_Compatibility::practitioner_owns_client( $uid, (int) $progress->client_user_id );
            if ( ! $is_self && ! $is_owner ) {
                return new WP_Error(
                    'expired_token',
                    'This link has expired. Please ask your practitioner for a fresh link.',
                    array( 'status' => 410 )
                );
            }
        }

        // Gate: must have completed Stage 3
        if ( empty( $progress->stage3_completed_at ) ) {
            return rest_ensure_response( array(
                'status'  => 'incomplete',
                'type'    => 'draft',
                'message' => 'Complete your full assessment to see your draft report.',
            ) );
        }

        // v0.20.6 — Prefer FINAL report when one exists. Critical integrity
        // rule: the stat-card numbers (chronoAge / bioAge / rate / 22 scores)
        // MUST match the numbers Claude was prompted with when it wrote the
        // awaken/lift/thrive narrative. For final reports, that means we
        // recompute calc with practitioner's health_data_changes applied on
        // top of stage1+3 data, then run the same calculate_full() the
        // finalise pipeline called at generation time. Pure math, no AI burn,
        // no DB writes, always fresh.
        $final_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, report_content, milestones, health_data_changes, status, created_at
             FROM {$wpdb->prefix}hdlv2_reports
             WHERE form_progress_id = %d AND report_type = 'final' AND status = 'ready'
             ORDER BY id DESC LIMIT 1",
            $progress->id
        ) );

        $report_type = $final_row ? 'final' : 'draft';

        if ( $final_row ) {
            $report_row = $final_row;
        } else {
            $report_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, report_content, milestones, status, created_at
                 FROM {$wpdb->prefix}hdlv2_reports
                 WHERE form_progress_id = %d AND report_type = 'draft'
                 ORDER BY id DESC LIMIT 1",
                $progress->id
            ) );
        }

        // Parse report content + milestones blob
        $content = ( $report_row && ! empty( $report_row->report_content ) )
            ? ( json_decode( $report_row->report_content, true ) ?: array() )
            : array();
        $milestones = ( $report_row && ! empty( $report_row->milestones ) )
            ? ( json_decode( $report_row->milestones, true ) ?: array() )
            : array();

        $s1_data    = json_decode( $progress->stage1_data, true ) ?: array();
        $s3_data    = json_decode( $progress->stage3_data, true ) ?: array();
        $chrono_age = $s1_data['q1_age'] ?? $s1_data['age'] ?? null;

        // Build calc. Final: read the frozen generation-time snapshot (matches
        // the PDF exactly); legacy finals without one fall back to a live
        // recompute. Draft: use stage3 server_result.
        if ( $report_type === 'final' && ! empty( $content['calc_snapshot'] ) && is_array( $content['calc_snapshot'] ) ) {
            // v0.46.28 (A2) — Generation-time numbers, byte-identical to what
            // HDLV2_Final_Report::fire_webhook sent to the PDF. No recompute →
            // no screen↔PDF drift even if calculate_full() changes post-generation.
            $calc_source = $content['calc_snapshot'];
        } elseif ( $report_type === 'final' ) {
            $calc_data = array_merge( $s1_data, $s3_data );

            $health_changes = ( ! empty( $final_row->health_data_changes ) )
                ? ( json_decode( $final_row->health_data_changes, true ) ?: array() )
                : array();
            // v0.23.9 — same body-composition guard as final-report.php::generate().
            // Restore from change-log `original` when a body-comp edit blanked
            // the value, so the live view BMI / WHR / WHtR / BP / HR scores
            // recover for legacy corrupted rows.
            $body_inputs = array( 'height', 'weight', 'waist', 'hip', 'bpSystolic', 'bpDiastolic', 'restingHeartRate' );
            foreach ( $health_changes as $change ) {
                if ( ! is_array( $change ) || ! isset( $change['field'] ) ) continue;
                $field    = $change['field'];
                $new      = $change['new_value'] ?? null;
                $is_blank = ( $new === '' || $new === 'skip' || $new === null );
                if ( in_array( $field, $body_inputs, true ) && $is_blank ) {
                    $orig = $change['original'] ?? null;
                    if ( is_numeric( $orig ) ) {
                        $calc_data[ $field ] = $orig;
                        continue;
                    }
                }
                if ( $new !== null ) {
                    $calc_data[ $field ] = $new;
                }
            }

            // V1-style field alias (activity → physicalActivity)
            if ( isset( $calc_data['activity'] ) && ! isset( $calc_data['physicalActivity'] ) ) {
                $calc_data['physicalActivity'] = $calc_data['activity'];
            }
            foreach ( $calc_data as $k => $v ) {
                if ( $v === 'skip' ) $calc_data[ $k ] = null;
            }

            $age    = (int) ( $calc_data['q1_age'] ?? $calc_data['age'] ?? 0 );
            $gender = $calc_data['q1_sex'] ?? $calc_data['gender'] ?? 'other';
            $calc_source = HDLV2_Rate_Calculator::calculate_full( $age, $calc_data, $gender );
        } else {
            $live_calc   = $s3_data['server_result'] ?? array();
            $calc_source = ! empty( $live_calc ) ? $live_calc : $content;
        }

        $calc = array(
            'chronoAge' => $chrono_age !== null ? (int) $chrono_age : null,
            'bioAge'    => isset( $calc_source['bio_age'] ) ? (float) $calc_source['bio_age'] : null,
            'rate'      => isset( $calc_source['rate'] )    ? (float) $calc_source['rate']    : null,
            'bmi'       => isset( $calc_source['bmi'] )     ? (float) $calc_source['bmi']     : null,
            'whr'       => isset( $calc_source['whr'] )     ? (float) $calc_source['whr']     : null,
            'whtr'      => isset( $calc_source['whtr'] )    ? (float) $calc_source['whtr']    : null,
            'scores'    => $calc_source['scores'] ?? array(),
            // v0.41.36 — Derived surrogate metabolic signal (AUDIT Action Point 1).
            // Computed once here from the same calc_source; the JS renders it
            // (renderMetabolicSignal) and never recomputes. Display-only — does
            // not alter any score above. Shared by the client draft view AND the
            // practitioner consultation panel (both read this rest_get_draft calc).
            'metabolic' => HDLV2_Rate_Calculator::metabolic_signal( $calc_source['scores'] ?? array(), $calc_source ),
        );

        // Practitioner info
        $prac_name     = '';
        $prac_logo_url = '';
        if ( $progress->practitioner_user_id ) {
            $prac_user = get_userdata( $progress->practitioner_user_id );
            if ( $prac_user ) {
                $prac_name     = $prac_user->display_name;
                $prac_logo_url = esc_url_raw( (string) get_user_meta( $progress->practitioner_user_id, 'practitioner_logo_url', true ) );
            }
        }

        // Practitioner notes — only on FINAL, only the client-visible bits.
        // health_summary + follow_up_actions are what the practitioner
        // authored for the client. health_history and raw additional_notes
        // are NOT surfaced — those read as internal clinical shorthand.
        $practitioner_notes = null;
        if ( $report_type === 'final' ) {
            $consult = $wpdb->get_row( $wpdb->prepare(
                "SELECT ai_organised_notes FROM {$wpdb->prefix}hdlv2_consultation_notes
                 WHERE report_id = %d LIMIT 1",
                (int) $final_row->id
            ) );
            if ( $consult && ! empty( $consult->ai_organised_notes ) ) {
                $organised = json_decode( $consult->ai_organised_notes, true ) ?: array();
                $notes_summary = isset( $organised['health_summary'] ) ? (string) $organised['health_summary'] : '';
                $notes_follow  = ( isset( $organised['follow_up_actions'] ) && is_array( $organised['follow_up_actions'] ) )
                    ? array_values( array_filter( array_map( 'strval', $organised['follow_up_actions'] ) ) )
                    : array();
                if ( $notes_summary !== '' || ! empty( $notes_follow ) ) {
                    $practitioner_notes = array(
                        'health_summary'    => $notes_summary,
                        'follow_up_actions' => $notes_follow,
                    );
                }
            }
        }

        // Narrative readiness — different shapes for draft vs final.
        //   Draft: has ai_narrative (structured JSON) + awaken/lift/thrive prose.
        //   Final: only has awaken/lift/thrive prose (no ai_narrative — that
        //          field is draft-only per generate_draft_report vs
        //          generate_final_report in HDLV2_AI_Service).
        // v0.20.6 — previously gated everything on ai_narrative, which made
        // every finalised report look "generating" and blanked the prose.
        $ai_narrative     = $content['ai_narrative'] ?? null;
        $has_opening      = is_array( $ai_narrative ) && ! empty( $ai_narrative['opening'] );
        $has_prose        = ! empty( $content['awaken_content'] ) || ! empty( $content['lift_content'] ) || ! empty( $content['thrive_content'] );
        $row_status_ready = ( $report_row && $report_row->status === 'ready' );

        if ( $report_type === 'final' ) {
            $status = ( $row_status_ready && $has_prose ) ? 'ready' : 'generating';
        } else {
            $status = ( $row_status_ready && $has_opening ) ? 'ready' : 'generating';
        }

        $awaken = $status === 'ready' ? ( isset( $content['awaken_content'] ) ? (string) $content['awaken_content'] : '' ) : '';
        $lift   = $status === 'ready' ? ( isset( $content['lift_content'] )   ? (string) $content['lift_content']   : '' ) : '';
        $thrive = $status === 'ready' ? ( isset( $content['thrive_content'] ) ? (string) $content['thrive_content'] : '' ) : '';

        $response = array(
            'status'                 => $status,
            'type'                   => $report_type,
            'client_name'            => $progress->client_name ?: '',
            'practitioner_name'      => $prac_name,
            'practitioner_logo_url'  => $prac_logo_url,
            'stage3_completed_at'    => $progress->stage3_completed_at,
            'calc'                   => $calc,
            'ai_narrative'           => $status === 'ready' ? $ai_narrative : null,
            'awaken_content'         => $awaken,
            'lift_content'           => $lift,
            'thrive_content'         => $thrive,
            'milestones'             => $milestones,
            'practitioner_notes'     => $practitioner_notes,
        );

        if ( $report_type === 'final' && ! empty( $final_row->created_at ) ) {
            $response['finalised_at'] = $final_row->created_at;
        }

        return rest_ensure_response( $response );
    }

    // ──────────────────────────────────────────────────────────────
    //  SHORTCODE [hdlv2_draft_report]
    // ──────────────────────────────────────────────────────────────

    public function render_shortcode( $atts ) {

        // W7: automation-tier branch — dark behind feature flag.
        // Flag check is FIRST so flag=false is byte-identical to pre-W7
        // for every existing user, regardless of user_meta state.
        if ( get_option( 'hdlv2_automation_tier_enabled', false ) === true
             && get_user_meta( get_current_user_id(), 'hdlv2_tier', true ) === 'automation' ) {
            if ( shortcode_exists( 'hdlv2_auto_consultation' ) ) {
                return do_shortcode( '[hdlv2_auto_consultation]' );
            }
            // Placeholder until W8 registers the real shortcode.
            return '<div style="max-width:640px;margin:60px auto;padding:32px 28px;text-align:center;font-family:Inter,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;color:#2c3e50;background:#f8f9fb;border:1px solid #e4e6ea;border-radius:12px;"><h2 style="font-family:Poppins,Inter,sans-serif;color:#004F59;margin:0 0 12px 0;font-size:22px;">Self-reported step coming soon</h2><p style="color:#888;margin:0;font-size:15px;line-height:1.5;">We\'re finalising this step of your assessment. Please check back shortly.</p></div>';
        }

        $plugin_url = defined( 'HDLV2_PLUGIN_URL' ) ? HDLV2_PLUGIN_URL : plugin_dir_url( dirname( __DIR__ ) . '/hdl-longevity-v2.php' );
        $version    = defined( 'HDLV2_VERSION' ) ? HDLV2_VERSION : '0.18.0';

        // v0.34.3 — observability: log when the practitioner arrives via the
        // "View the new Final Report →" link from the consultation success
        // card. Pure logging — no UI change. Helps confirm the post-regen
        // round-trip flow is working in production without instrumenting any
        // client-side analytics.
        if ( isset( $_GET['from'] ) && $_GET['from'] === 'consultation' && is_user_logged_in() ) {
            $viewer_id = get_current_user_id();
            $progress_id = isset( $_GET['progress_id'] ) ? absint( $_GET['progress_id'] ) : 0;
            if ( class_exists( 'HDLV2_Compatibility' ) && HDLV2_Compatibility::is_practitioner( $viewer_id ) ) {
                error_log( sprintf(
                    '[HDLV2 view] practitioner=%d viewing report from consultation, progress=%d',
                    $viewer_id, $progress_id
                ) );
            }
        }

        // Chart.js from CDN — consistent with the mockup
        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            array(),
            '4.4.0',
            true
        );

        // V1 trajectory module — shipped in plugin assets
        wp_enqueue_script(
            'hdl-trajectory-chart',
            $plugin_url . 'assets/js/hdl-trajectory-chart-hero.js',
            array(),
            $version,
            true
        );

        // Speedometer module — same gauge Stage 1 uses (HDLSpeedometer.buildUrl).
        // Powers the Pace-of-Ageing hero on this page. Speedometer is otherwise
        // only registered inside the staged-form/consultation shortcodes — explicit
        // enqueue here mirrors the consultation page's pattern.
        wp_enqueue_script(
            'hdlv2-speedometer',
            $plugin_url . 'assets/js/hdlv2-speedometer.js',
            array(),
            $version,
            true
        );

        wp_enqueue_script(
            'hdlv2-draft-report',
            $plugin_url . 'assets/js/hdlv2-draft-report.js',
            array( 'chart-js', 'hdl-trajectory-chart', 'hdlv2-speedometer', 'hdlv2-loading' ),
            $version,
            true
        );

        wp_localize_script( 'hdlv2-draft-report', 'HDLV2_DRAFT_REPORT', array(
            'rest_url' => esc_url_raw( rest_url( 'hdl-v2/v1/reports/draft' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
        ) );

        wp_enqueue_style(
            'hdlv2-draft-report',
            $plugin_url . 'assets/css/hdlv2-draft-report.css',
            array( 'hdlv2-loading-css' ),
            $version
        );

        // Load Inter + Poppins web fonts (same as mockup)
        wp_enqueue_style(
            'hdlv2-draft-fonts',
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap',
            array(),
            null
        );

        // v0.21.2 — Practitioner nav spine on the Longevity Report page when a
        // practitioner is viewing someone else's report (via ?t= token). Gives
        // them "← Back to clients" + breadcrumb context so they're not stuck
        // on a client-facing page. Clients (unauthenticated via email link)
        // never see this because the is_user_logged_in check fails.
        if ( is_user_logged_in() && class_exists( 'HDLV2_Compatibility' )
             && HDLV2_Compatibility::is_practitioner( get_current_user_id() ) ) {
            $nav_token = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) )
                       : ( isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '' );
            if ( $nav_token && preg_match( '/^[a-f0-9]{64}$/', $nav_token ) ) {
                global $wpdb;
                // v0.41.17 — `AND deleted_at IS NULL`.
                $nav_row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT client_user_id, practitioner_user_id FROM {$wpdb->prefix}hdlv2_form_progress
                     WHERE token = %s AND deleted_at IS NULL LIMIT 1",
                    $nav_token
                ) );
                $current_uid = get_current_user_id();
                if ( $nav_row
                     && (int) $nav_row->practitioner_user_id === $current_uid
                     && (int) $nav_row->client_user_id !== $current_uid ) {
                    $nav_client_name = '';
                    $nav_user = get_userdata( (int) $nav_row->client_user_id );
                    if ( $nav_user ) {
                        $nav_client_name = $nav_user->display_name ?: $nav_user->user_login;
                    }
                    wp_enqueue_script(
                        'hdlv2-client-nav-bar',
                        $plugin_url . 'assets/js/hdlv2-client-nav-bar.js',
                        array(),
                        $version,
                        true
                    );
                    wp_localize_script( 'hdlv2-client-nav-bar', 'hdlv2_nav_bar', array(
                        'clients_url' => home_url( '/clients/' ),
                        'client_name' => $nav_client_name,
                        'page_label'  => 'Trajectory Plan',
                        'api_base'    => rest_url( 'hdl-v2/v1' ),
                        'nonce'       => wp_create_nonce( 'wp_rest' ),
                    ) );
                }
            }
        }

        ob_start();
        ?>
        <div id="hdlv2-draft-report-root" class="hdlv2-dr">
            <div class="hdlv2-dr-loading" data-hdlv2-state="loading">
                <div class="hdlv2-dr-loading-icon"></div>
                <h3>Preparing your draft report</h3>
                <p>We're running your results through our analysis<span class="hdlv2-dr-loading-dots"></span></p>
                <p style="font-size:12px;color:#888;margin-top:16px;">This usually takes 20 – 60 seconds.</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

HDLV2_Client_Draft_View::get_instance();
