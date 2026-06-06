<?php
/**
 * Practitioner Consultation Interface.
 *
 * Split-screen workspace: draft report (left) + editing tools (right).
 * Practitioner edits health data, adds notes/recommendations, then
 * triggers Final Report generation.
 *
 * @package HDL_Longevity_V2
 * @since 0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Consultation {

    public function register_hooks() {
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_shortcode( 'hdlv2_consultation', array( $this, 'render_shortcode' ) );
        add_filter( 'body_class', array( $this, 'maybe_add_wide_body_class' ) );
        // v0.46.7 — surface a "Consultations" link in the practitioner top menu
        // so the (already-built) /consultation/ hub is reachable without typing
        // the URL by hand. Role-gated to practitioners/admins inside the method.
        add_filter( 'wp_nav_menu_items', array( $this, 'inject_consultations_menu_item' ), 10, 2 );
        // v0.46.7 — the extra menu item widens the Divi header menu past its
        // fixed module width, wrapping "More" onto a second row. Free the
        // module to size to its content (centred, as before) and stop the
        // menu list wrapping. Practitioner-only (the only audience with the
        // extra item); desktop-only (Divi swaps to its hamburger < 980px).
        add_action( 'wp_head', array( $this, 'print_practitioner_menu_layout_css' ), 99 );
        // v0.25.2 — 404 unauthorised users at the page level (template_redirect
        // fires before the_posts/shortcode rendering). Practitioner +
        // administrator are allowed (HDLV2_Compatibility::is_practitioner()
        // returns true for both roles). Anyone else — logged-out, um_client,
        // um_consumer, subscriber, contributor — gets a real WP 404.
        add_action( 'template_redirect', array( $this, 'maybe_404_unauthorised' ), 5 );
        // v0.24.3 — the v0.21.2 `maybe_redirect_to_final_report` hook is
        // intentionally NOT registered. Practitioner must be able to re-enter
        // the consultation editor post-Final to edit health data and
        // re-generate the Final report + reset the Flight Plan to Week 1
        // (Quim's edit-as-reset rule, 2026-04-29). The legacy method body
        // remains below for git-blame archaeology but is unreachable.
        // v0.22.7 — wpdatatables registers Chart.js 3.9.1 globally with handle
        // `chartjs`, which clobbers our Chart.js 4.4.0 (handle `chart-js`).
        // Dequeue the conflict on the consultation page so the radar renders.
        add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_conflicting_chartjs' ), 999 );
    }

    /**
     * v0.22.7 — same defense as HDLV2_Client_Draft_View::dequeue_conflicting_chartjs
     * but scoped to pages containing the consultation shortcode (which now
     * embeds the same draft-report renderer in its left panel).
     */
    public function dequeue_conflicting_chartjs() {
        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'hdlv2_consultation' ) ) {
            return;
        }
        wp_dequeue_script( 'chartjs' );
        wp_deregister_script( 'chartjs' );
    }

    /**
     * Redirect practitioners to the canonical report page when a final report
     * exists for the requested progress row. Runs on the /consultation/ page
     * only (detected via has_shortcode). Clients never reach this path
     * because the shortcode rejects non-practitioners.
     */
    public function maybe_redirect_to_final_report() {
        if ( is_admin() || wp_doing_ajax() ) {
            return;
        }
        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'hdlv2_consultation' ) ) {
            return;
        }
        if ( ! is_user_logged_in() ) {
            return;
        }
        if ( ! HDLV2_Compatibility::is_practitioner( get_current_user_id() ) ) {
            return;
        }
        $progress_id = isset( $_GET['progress_id'] ) ? absint( $_GET['progress_id'] ) : 0;
        if ( ! $progress_id ) {
            return;
        }

        global $wpdb;
        // v0.41.17 — `AND deleted_at IS NULL`. Don't surface practitioner_user_id
        // for archived assessments via the consultation shortcode.
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT token, practitioner_user_id FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE id = %d AND deleted_at IS NULL LIMIT 1",
            $progress_id
        ) );
        // Silent bail if not owner — let the shortcode's existing 403 handle it.
        if ( ! $progress || (int) $progress->practitioner_user_id !== get_current_user_id() ) {
            return;
        }
        if ( empty( $progress->token ) ) {
            return;
        }

        $has_final = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}hdlv2_reports WHERE form_progress_id = %d AND report_type = 'final' LIMIT 1",
            $progress_id
        ) );
        if ( ! $has_final ) {
            return;
        }

        $report_slug = apply_filters( 'hdlv2_final_report_slug', 'longevity-draft-report' );
        $target      = home_url( '/' . trim( $report_slug, '/' ) . '/?t=' . rawurlencode( $progress->token ) );
        wp_safe_redirect( $target, 302 );
        exit;
    }

    /**
     * Tag pages containing the consultation shortcode with a body class so
     * our CSS can override Divi's narrow .et_pb_row width scope-safely.
     */
    public function maybe_add_wide_body_class( $classes ) {
        global $post;
        if ( $post && has_shortcode( $post->post_content, 'hdlv2_consultation' ) ) {
            $classes[] = 'hdlv2-wide-page';
        }
        return $classes;
    }

    /**
     * v0.46.7 — Inject a "Consultations" item into the practitioner top menu.
     *
     * Why: the /consultation/ list view (the "Consultations" hub — ready /
     * in-progress / delivered, with Open & Resume buttons) was fully built but
     * had no link pointing at it. Practitioners could only reach a single
     * client's consultation via the per-row "Record consultation" button on
     * /clients/. This adds one top-level menu door to the hub.
     *
     * Scope + safety:
     *   • Role-gated: only practitioners/administrators (the same audience the
     *     /consultation/ page itself allows) ever see the item. Clients,
     *     consumers, and logged-out visitors never get it. Belt-and-braces:
     *     enforce_practitioner_only_page() still guards the URL itself.
     *   • Menu-scoped: only injects into the menu that already renders the
     *     practitioner "Clients" link (string match on the /clients/ href), so
     *     the item never lands in the footer menu, the client menu, or any
     *     secondary nav that runs the same filter.
     *   • Idempotent: bails if a /consultation/ link is already present, so a
     *     menu that legitimately contains one (or a double filter pass) never
     *     double-renders.
     *
     * @param string   $items Serialized <li> menu items HTML.
     * @param stdClass $args  wp_nav_menu args (unused; signature completeness).
     * @return string
     */
    public function inject_consultations_menu_item( $items, $args ) {
        if ( ! is_user_logged_in()
             || ! HDLV2_Compatibility::is_practitioner( get_current_user_id() ) ) {
            return $items;
        }
        // Only the menu that holds the practitioner "Clients" link.
        $clients_pos = strpos( $items, '/clients/' );
        if ( false === $clients_pos ) {
            return $items;
        }
        // Already linked somewhere in this menu — don't duplicate.
        if ( false !== strpos( $items, '/consultation/' ) ) {
            return $items;
        }

        $slug   = apply_filters( 'hdlv2_consultation_slug', 'consultation' );
        $url    = esc_url( home_url( '/' . trim( $slug, '/' ) . '/' ) );
        $req    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        $is_cur = ( 0 === strpos( $req, '/' . trim( $slug, '/' ) . '/' ) );

        $classes = 'menu-item menu-item-type-custom menu-item-object-custom hdlv2-menu-consultations';
        if ( $is_cur ) {
            $classes .= ' current-menu-item current_page_item';
        }

        $new_item = '<li class="' . esc_attr( $classes ) . '">'
                  . '<a href="' . $url . '">' . esc_html__( 'Consultations', 'hdl-longevity-v2' ) . '</a>'
                  . '</li>';

        // Insert right after the first "Clients" <li> so the order reads
        // Clients → Consultations → …. The Clients link is a plain top-level
        // item (no submenu), so the first </li> after its href closes it.
        // Fallback: append to the end if the boundary can't be located.
        $li_end = strpos( $items, '</li>', $clients_pos );
        if ( false !== $li_end ) {
            $li_end += strlen( '</li>' );
            return substr( $items, 0, $li_end ) . $new_item . substr( $items, $li_end );
        }
        return $items . $new_item;
    }

    /**
     * v0.46.7 — One-line guarantee for the practitioner header menu.
     *
     * The Divi Theme Builder header menu module ships with a fixed inner
     * width (~360px) and the menu <ul> uses `flex-wrap: wrap`. Adding the
     * "Consultations" item pushes the row past that width, so "More" wraps
     * onto a second line. This frees the module to size to its content
     * (`max-content`, still centred in its column via auto margins) and
     * forces the menu list onto a single line.
     *
     * Scope:
     *   • Practitioner/admin only — the only audience that gets the extra
     *     item, so non-practitioner headers are never touched.
     *   • Desktop only in effect — Divi swaps to its hamburger menu below
     *     980px, where `.et-menu` is hidden, so `nowrap` is moot there.
     *   • Targets the specific TB header module class, not all Divi menus,
     *     so footer/secondary menus are unaffected. Verified across 1024 /
     *     1280 / 1920px: one line, within viewport, no logo overlap.
     */
    public function print_practitioner_menu_layout_css() {
        if ( is_admin() ) {
            return;
        }
        if ( ! is_user_logged_in()
             || ! HDLV2_Compatibility::is_practitioner( get_current_user_id() ) ) {
            return;
        }
        // Desktop only (min-width 981px). Divi shows the mobile hamburger at
        // <=980px, and THIS module wraps the mobile dropdown too. Without the
        // media query, `width:max-content` shrinks the whole module to the
        // hamburger's width (~44px) on phones/tablets, collapsing the mobile
        // menu into a broken one-word-per-line column. The query confines the
        // override to the desktop menu only.
        echo '<style id="hdlv2-consultations-menu-fix">'
           . '@media (min-width:981px){'
           . '.et_pb_menu_0_tb_header{width:max-content!important;max-width:100%!important;margin-left:auto!important;margin-right:auto!important}'
           . '.et_pb_menu_0_tb_header ul.et-menu{flex-wrap:nowrap!important}'
           . '}'
           . '</style>' . "\n";
    }

    // ──────────────────────────────────────────────────────────────
    //  REST ROUTES
    // ──────────────────────────────────────────────────────────────

    public function register_rest_routes() {
        $ns = 'hdl-v2/v1';

        // v0.25.2 — list view for /consultation/ (no progress_id). Returns
        // ready[] (Stage 3 done, no consultation_notes yet) + existing[]
        // (latest consultation_notes per progress, with status field). Scoped
        // to current practitioner; admin sees all rows with practitioner_name
        // included.
        register_rest_route( $ns, '/consultations/list', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_list_consultations' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );

        // Load consultation data for a client
        register_rest_route( $ns, '/consultation/(?P<progress_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_load_consultation' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );

        // Auto-save practitioner notes
        register_rest_route( $ns, '/consultation/save-notes', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_save_notes' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );

        // Add a structured recommendation
        register_rest_route( $ns, '/consultation/add-recommendation', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_add_recommendation' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );

        // Remove a recommendation by index
        register_rest_route( $ns, '/consultation/remove-recommendation', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_remove_recommendation' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );

        // Edit a health data field (logs change)
        register_rest_route( $ns, '/consultation/edit-field', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_edit_field' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );

        // Trigger Final Report generation
        // v0.24.3 — practitioner edit-as-reset. Re-fires Final report
        // generation AND archives prior Flight Plan rows so cron/regen
        // produces a fresh Week 1. See HDLV2_Final_Report::regenerate().
        register_rest_route( $ns, '/consultation/save-and-regenerate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_save_and_regenerate' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );

        register_rest_route( $ns, '/consultation/finalise', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_finalise' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );

        // 2026-04-21 (v0.15.0) — new free-form / AI-organise flow.
        // Replaces the structured per-recommendation entry UI. Practitioner
        // writes raw notes (text + transcribed audio) into one textarea and
        // clicks "Process & Organise" → AI splits into structured sections.
        register_rest_route( $ns, '/consultation/organise', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_organise_notes' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );

        // Practitioner approves the AI-organised output → unlocks
        // Generate Final Report. After approval the organised view becomes
        // read-only (with inline Edit) and the report can be generated.
        register_rest_route( $ns, '/consultation/approve', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_approve_notes' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );

        // v0.24.8 — silent auto-save of inline edits to ai_organised_notes.
        // Lets the practitioner step away mid-edit and resume without burning
        // Claude tokens or firing the final report. No approval flag change.
        register_rest_route( $ns, '/consultation/save-organised', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_save_organised_notes' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );

        // v0.32.5 — Reset the AI organisation back to NULL so the UI drops
        // out of the review-stage trap and the practitioner can re-paste raw
        // notes. Used when /organise produced a poor result (e.g. praise-only
        // notes that yielded an empty recommendations[] array). Confirms via
        // the practitioner-auth permission check; no AI burn, no idempotency
        // key needed.
        register_rest_route( $ns, '/consultation/reset-organised', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_reset_organised_notes' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );

        // v0.15.3 — force-regenerate the pre-consultation client brief
        // (e.g. after practitioner has edited health data fields).
        register_rest_route( $ns, '/consultation/refresh-brief', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_refresh_brief' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );

        // v0.33.1 — silent auto-save of inline edits to the Client Brief
        // (pre_consult_summary). Mirrors /save-organised but writes to
        // wp_hdlv2_form_progress instead of wp_hdlv2_consultation_notes.
        // No AI burn — just persistence.
        register_rest_route( $ns, '/consultation/save-brief', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_save_brief' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );

        // v0.28.0 — Consultation Addenda. Practitioner-appended, timestamped
        // clinical observations between the original consultation and the
        // next quarterly Part. Each Addendum is read by Claude during
        // /save-and-update-plan to re-issue the awaken/lift/thrive
        // narrative + recommendations + milestones + Flight Plan. History
        // is never deleted — the never-delete-history rule (Matthew
        // transcript 2026-04-30). See CONSULTATION-ADDENDA-DESIGN.md.
        register_rest_route( $ns, '/consultation/addendum', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_create_addendum' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );

        // Save & Update Plan — the new post-Final action button. Same
        // backend mechanic as /save-and-regenerate (archive + regenerate)
        // but with a distinct idempotency scope so the rate-limit and
        // dedup buckets don't clash with the legacy edit-as-reset banner.
        register_rest_route( $ns, '/consultation/save-and-update-plan', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_save_and_update_plan' ),
            'permission_callback' => array( $this, 'check_practitioner_auth' ),
        ) );
    }

    /**
     * Return the pre-consultation brief for a form_progress. Generates on
     * first call, caches in form_progress.pre_consult_summary, returns
     * cached on subsequent calls. $force=true bypasses cache.
     *
     * v0.33.0 — public + static so HDLV2_Final_Report::regenerate() can
     * force-refresh the Brief after a Save & Update Plan cycle without
     * needing a class instance. No instance state used internally — it
     * only touches $wpdb + the static AI service.
     */
    public static function get_or_build_pre_consult_summary( $progress, $force = false ) {
        if ( ! $force && ! empty( $progress->pre_consult_summary ) ) {
            $decoded = json_decode( $progress->pre_consult_summary, true );
            if ( is_array( $decoded ) ) return $decoded;
        }
        $s1 = json_decode( $progress->stage1_data, true ) ?: array();
        $s3 = json_decode( $progress->stage3_data, true ) ?: array();
        $calc = $s3['server_result'] ?? array();
        global $wpdb;
        $why = $wpdb->get_row( $wpdb->prepare(
            "SELECT distilled_why, ai_reformulation FROM {$wpdb->prefix}hdlv2_why_profiles WHERE form_progress_id = %d LIMIT 1",
            $progress->id
        ), ARRAY_A );

        // v0.33.0 — Fetch all addenda for this client's active consultation
        // so the Brief reflects post-Final clinical observations, not just
        // the pre-consultation form data. Without this, the Brief stayed
        // frozen at the original consultation snapshot — practitioners
        // returning a quarter later saw stale recommendations that didn't
        // factor in any addenda. The chronological pass-through matches the
        // pattern used by merge_consultation_with_addenda() in the Final
        // Report flow, so both surfaces tell the same story.
        $addenda = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.note_text, a.occurred_at, a.priority, a.source
             FROM {$wpdb->prefix}hdlv2_consultation_addenda a
             INNER JOIN {$wpdb->prefix}hdlv2_consultation_notes c ON c.id = a.consultation_id
             WHERE c.form_progress_id = %d
             ORDER BY a.occurred_at ASC, a.id ASC",
            $progress->id
        ), ARRAY_A );

        $summary = HDLV2_AI_Service::generate_pre_consultation_summary(
            $s1,
            $s3,
            $calc,
            $why ?: array(),
            is_array( $addenda ) ? $addenda : array()
        );
        if ( is_wp_error( $summary ) ) return null;
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_form_progress',
            array(
                'pre_consult_summary'    => wp_json_encode( $summary ),
                'pre_consult_summary_at' => current_time( 'mysql' ),
            ),
            array( 'id' => $progress->id )
        );
        return $summary;
    }

    public function rest_refresh_brief( $request ) {
        $params     = $request->get_json_params();
        $progress_id = (int) ( $params['progress_id'] ?? 0 );
        if ( ! $progress_id ) {
            return new WP_Error( 'missing_id', 'Progress ID required.', array( 'status' => 400 ) );
        }
        global $wpdb;
        // v0.41.17 — `AND deleted_at IS NULL`.
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE id = %d AND practitioner_user_id = %d AND deleted_at IS NULL LIMIT 1",
            $progress_id, get_current_user_id()
        ) );
        if ( ! $progress ) {
            return new WP_Error( 'not_found', 'Assessment not found.', array( 'status' => 404 ) );
        }
        $summary = self::get_or_build_pre_consult_summary( $progress, true );
        if ( ! $summary ) {
            return new WP_Error( 'gen_failed', 'Could not generate the client brief.', array( 'status' => 500 ) );
        }
        return rest_ensure_response( array(
            'success'                => true,
            'pre_consult_summary'    => $summary,
            'pre_consult_summary_at' => current_time( 'mysql' ),
        ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: SAVE BRIEF (silent auto-save of inline practitioner edits)
    //  v0.33.1 — Mirrors /save-organised but writes to
    //  wp_hdlv2_form_progress.pre_consult_summary instead of
    //  wp_hdlv2_consultation_notes.ai_organised_notes. Used for the Client
    //  Brief contenteditable fields. No AI burn — pure persistence.
    //
    //  Body: { progress_id, brief: {...edited summary object...} }
    // ──────────────────────────────────────────────────────────────

    public function rest_save_brief( $request ) {
        $params      = $request->get_json_params();
        $progress_id = (int) ( $params['progress_id'] ?? 0 );
        $edited      = isset( $params['brief'] ) && is_array( $params['brief'] )
            ? $params['brief']
            : null;

        if ( ! $progress_id || $edited === null ) {
            return new WP_Error( 'invalid', 'Progress ID and edited brief required.', array( 'status' => 400 ) );
        }

        global $wpdb;
        // Ownership check — prevents practitioner A overwriting practitioner B's row.
        $owner = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT practitioner_user_id FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d",
            $progress_id
        ) );
        if ( ! $owner ) {
            return new WP_Error( 'not_found', 'Assessment not found.', array( 'status' => 404 ) );
        }
        if ( $owner !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', 'You do not have access to this assessment.', array( 'status' => 403 ) );
        }

        // Light shape normalisation: ensure all 5 keys exist and array-typed
        // ones are arrays. Defensive against the JS sending a partial payload
        // (e.g. when one section is missing because it never had content).
        $shape = array(
            'health_summary'    => isset( $edited['health_summary'] ) ? (string) $edited['health_summary'] : '',
            'health_history'    => isset( $edited['health_history'] ) ? (string) $edited['health_history'] : '',
            'recommendations'   => isset( $edited['recommendations'] ) && is_array( $edited['recommendations'] ) ? array_values( $edited['recommendations'] ) : array(),
            'follow_up_actions' => isset( $edited['follow_up_actions'] ) && is_array( $edited['follow_up_actions'] ) ? array_values( $edited['follow_up_actions'] ) : array(),
            'additional_notes'  => isset( $edited['additional_notes'] ) ? (string) $edited['additional_notes'] : '',
        );

        $now = current_time( 'mysql' );
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_form_progress',
            array(
                'pre_consult_summary'    => wp_json_encode( $shape ),
                'pre_consult_summary_at' => $now,
            ),
            array( 'id' => $progress_id )
        );

        return rest_ensure_response( array(
            'success'                => true,
            'saved_at'               => $now,
            'pre_consult_summary_at' => $now,
        ) );
    }

    public function check_practitioner_auth() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) return false;
        return HDLV2_Compatibility::is_practitioner( $user_id );
    }

    /**
     * Validate a practitioner-submitted field edit.
     *
     * v0.23.0 — Returns WP_Error on invalid input, true on valid. Numeric
     * fields must parse and fall in physiologically plausible bounds. Score
     * dropdowns must match the 0-5 / 'skip' contract used by S3_OPTIONS.
     * Stage 1 letter answers (q3-q9) must be a-e. q1_sex must be male/female/other.
     *
     * Empty string is allowed (clears the value). 'skip' is allowed everywhere
     * (practitioner mark-as-unverified). Unknown fields are accepted to avoid
     * blocking edits to fields we haven't catalogued — falls back to passthrough.
     */
    private static function validate_field_value( $field, $value ) {
        // v0.23.9 — body-composition guard. Blanking height / weight / waist /
        // hip / BP / HR silently nulls BMI / WHR / WHtR / blood-pressure-score
        // / heart-rate-score in the radar chart, with no UI cue. Reject blank
        // / skip on these inputs so a practitioner mis-click on the
        // consultation page can't quietly destroy data the way it did to
        // Matt2704_01's report 25 (2026-04-28). To genuinely mark a body-comp
        // measurement as unknown, the practitioner should leave the original
        // Stage 3 value unchanged (i.e. not edit it at all).
        $body_inputs = array( 'height', 'weight', 'waist', 'hip', 'bpSystolic', 'bpDiastolic', 'restingHeartRate' );
        if ( ( $value === '' || $value === null || $value === 'skip' ) && in_array( $field, $body_inputs, true ) ) {
            return new WP_Error(
                'body_comp_blank',
                sprintf( 'Field "%s" cannot be blank or skipped — leaving it empty would silently remove the BMI / WHR / WHtR / BP / HR score from the report. Enter a numeric value or leave the original Stage 3 measurement unchanged.', $field ),
                array( 'status' => 400 )
            );
        }

        // Empty / skip always allowed (for lifestyle / score / Stage 1 fields)
        if ( $value === '' || $value === null || $value === 'skip' ) return true;

        // Numeric fields with physiological bounds
        $numeric_ranges = array(
            'q1_age'           => array( 1,   120 ),
            'height'           => array( 50,  250 ),  // cm
            'weight'           => array( 20,  300 ),  // kg
            'waist'            => array( 40,  200 ),  // cm
            'hip'              => array( 40,  200 ),  // cm
            'restingHeartRate' => array( 30,  220 ),  // bpm
            'bpSystolic'       => array( 60,  250 ),  // mmHg
            'bpDiastolic'      => array( 30,  150 ),  // mmHg
            // v0.23.1 — overallHealthPercent removed (Matthew 2026-04-28).
            'q2a'              => array( 1,   5   ),  // silhouette
        );
        if ( isset( $numeric_ranges[ $field ] ) ) {
            if ( ! is_numeric( $value ) ) {
                return new WP_Error( 'invalid_value', sprintf( 'Field "%s" must be numeric.', $field ), array( 'status' => 400 ) );
            }
            $n = (float) $value;
            list( $min, $max ) = $numeric_ranges[ $field ];
            if ( $n < $min || $n > $max ) {
                return new WP_Error( 'out_of_range', sprintf( 'Field "%s" must be between %s and %s.', $field, $min, $max ), array( 'status' => 400 ) );
            }
            return true;
        }

        // 0-5 score dropdowns (Stage 3 lifestyle / fitness / sleep / mental)
        $score_fields = array(
            'physicalActivity', 'sitToStand', 'breathHold', 'balance', 'skinElasticity',
            'sleepDuration', 'sleepQuality', 'stressLevels', 'socialConnections',
            'cognitiveActivity', 'dietQuality', 'alcoholConsumption', 'smokingStatus',
            'supplementIntake', 'sunlightExposure', 'dailyHydration',
        );
        if ( in_array( $field, $score_fields, true ) ) {
            if ( ! is_numeric( $value ) ) {
                return new WP_Error( 'invalid_value', sprintf( 'Field "%s" must be a 0-5 score.', $field ), array( 'status' => 400 ) );
            }
            $n = (int) $value;
            if ( $n < 0 || $n > 5 ) {
                return new WP_Error( 'out_of_range', sprintf( 'Field "%s" must be between 0 and 5.', $field ), array( 'status' => 400 ) );
            }
            return true;
        }

        // Stage 1 letter answers q3-q9 → a/b/c/d/e
        if ( in_array( $field, array( 'q3', 'q4', 'q5', 'q6', 'q7', 'q8', 'q9' ), true ) ) {
            if ( ! in_array( strtolower( (string) $value ), array( 'a', 'b', 'c', 'd', 'e' ), true ) ) {
                return new WP_Error( 'invalid_value', sprintf( 'Field "%s" must be a-e.', $field ), array( 'status' => 400 ) );
            }
            return true;
        }

        // q1_sex
        if ( $field === 'q1_sex' ) {
            if ( ! in_array( strtolower( (string) $value ), array( 'male', 'female', 'other' ), true ) ) {
                return new WP_Error( 'invalid_value', 'q1_sex must be male, female, or other.', array( 'status' => 400 ) );
            }
            return true;
        }

        // q2b fat distribution
        if ( $field === 'q2b' ) {
            if ( ! in_array( strtolower( (string) $value ), array( 'apple', 'pear', 'even' ), true ) ) {
                return new WP_Error( 'invalid_value', 'q2b must be apple, pear, or even.', array( 'status' => 400 ) );
            }
            return true;
        }

        // Unknown field — passthrough (don't block legitimate edits to fields
        // we haven't catalogued; sanitize_text_field already stripped tags).
        return true;
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: LOAD CONSULTATION
    // ──────────────────────────────────────────────────────────────

    public function rest_load_consultation( $request ) {
        $progress_id = (int) $request->get_param( 'progress_id' );

        global $wpdb;
        // v0.41.17 — `AND deleted_at IS NULL`. The consultation UI must not
        // load Stage 1/2/3 data for an archived assessment, even if the
        // practitioner has a stale tab open.
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE id = %d AND deleted_at IS NULL LIMIT 1",
            $progress_id
        ) );

        if ( ! $progress ) {
            return new WP_Error( 'not_found', 'Assessment not found.', array( 'status' => 404 ) );
        }

        // Verify this practitioner owns this client.
        // v0.36.9 — admin read-only escape hatch. Lets administrators (cloudworks)
        // load any practitioner's consultation page for support / debugging /
        // testing without triggering a 403 spinner-stuck UX. Write paths remain
        // strict (admin still cannot save notes, fire AI, finalise, etc.) — the
        // SQL filters on practitioner_user_id = get_current_user_id() in those
        // handlers naturally fence admin out. Same pattern as v0.36.7
        // /my-dashboard/ admin bypass + the existing edit-field / save-and-
        // regenerate handlers (lines 1107, 1270).
        if ( (int) $progress->practitioner_user_id !== get_current_user_id()
             && ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', 'You do not have access to this client.', array( 'status' => 403 ) );
        }

        // v0.36.9 — when an admin views another practitioner's consultation,
        // sub-queries that filter by practitioner_user_id must look up the
        // ACTUAL practitioner who owns the row, not the admin themselves —
        // otherwise notes / addenda / recommendations stay invisible.
        $is_admin_viewer = current_user_can( 'manage_options' )
                           && (int) $progress->practitioner_user_id !== get_current_user_id();
        $lookup_practitioner_id = $is_admin_viewer
            ? (int) $progress->practitioner_user_id
            : get_current_user_id();

        // Load draft report
        $draft = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_reports WHERE form_progress_id = %d AND report_type = 'draft' ORDER BY id DESC LIMIT 1",
            $progress_id
        ) );

        // Load final report if one exists — drives the view-only branch in JS.
        $final = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_reports WHERE form_progress_id = %d AND report_type = 'final' ORDER BY id DESC LIMIT 1",
            $progress_id
        ) );

        // Load WHY profile
        $why = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_why_profiles WHERE form_progress_id = %d LIMIT 1",
            $progress_id
        ) );

        // Load consultation notes. If a final report exists, prefer the
        // 'report_generated' row that produced it — keeps the practitioner's
        // notes and recommendations visible in the read-only view instead of
        // silently spawning a fresh empty consultation row.
        $consult = null;
        if ( $final ) {
            $consult = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hdlv2_consultation_notes
                 WHERE form_progress_id = %d AND practitioner_user_id = %d AND status = 'report_generated'
                 ORDER BY id DESC LIMIT 1",
                $progress_id, $lookup_practitioner_id
            ) );
        }
        if ( ! $consult ) {
            $consult = $this->get_or_create_consultation( $progress, $lookup_practitioner_id );
        }
        // v0.36.9 — defensive stub for admin-viewer with no consultation row yet.
        // get_or_create_consultation returns null in that case (rather than
        // creating a stray admin-owned row). Render with empty sections so the
        // page still mounts.
        if ( ! $consult ) {
            $consult = (object) array(
                'id'                    => 0,
                'raw_notes'             => '',
                'typed_notes'           => '',
                'ai_organised_notes'    => '',
                'practitioner_approved' => 0,
                'approved_at'           => null,
                'recommendations'       => '',
                'health_data_changes'   => '',
                'status'                => 'in_progress',
            );
        }

        $s1_data = json_decode( $progress->stage1_data, true ) ?: array();
        $s3_data = json_decode( $progress->stage3_data, true ) ?: array();
        $draft_content = $draft ? json_decode( $draft->report_content, true ) : null;
        // If report_content is a string (HTML), it might be the calc result — try both
        if ( ! $draft_content && $draft ) {
            $draft_content = array(
                'awaken_content' => $draft->report_content ?? '',
                'lift_content'   => '',
                'thrive_content' => '',
            );
        }

        // Build final_report payload if present
        $final_payload = null;
        if ( $final ) {
            $fc = json_decode( $final->report_content, true ) ?: array();
            $final_payload = array(
                'id'              => (int) $final->id,
                'awaken_content'  => $fc['awaken_content'] ?? '',
                'lift_content'    => $fc['lift_content'] ?? '',
                'thrive_content'  => $fc['thrive_content'] ?? '',
                'milestones'      => json_decode( $final->milestones, true ) ?: array(),
                'generated_at'    => $final->created_at,
            );
        }

        // Resolve calc_result with two-source fallback. Primary: stage3_data.server_result
        // (set by /complete-stage). Fallback: pull rate/bio_age/scores out of
        // the draft report's report_content (which create_draft_report seeds
        // with the calc result). The fallback shields the consultation header
        // from a "Rate: ?  Bio Age: ?" render if the stage3_data persistence
        // step ever drops the server_result key again.
        $calc_result = $s3_data['server_result'] ?? array();
        if ( empty( $calc_result ) && is_array( $draft_content ) ) {
            $maybe = array();
            foreach ( array( 'rate', 'bio_age', 'bmi', 'whr', 'whtr', 'scores' ) as $k ) {
                if ( isset( $draft_content[ $k ] ) ) $maybe[ $k ] = $draft_content[ $k ];
            }
            if ( ! empty( $maybe ) ) $calc_result = $maybe;
        }

        return rest_ensure_response( array(
            'progress_id'        => $progress_id,
            // v0.20.7 — expose the client token so the practitioner UI can
            // iframe the client's /longevity-draft-report/?t=<token> page
            // (the new Final Report layout) instead of rendering its own
            // out-of-date inline preview.
            'token'              => $progress->token,
            'client_name'        => $progress->client_name ?: '',
            'client_email'       => $progress->client_email ?: '',
            'stage1_data'        => $s1_data,
            'stage3_data'        => $s3_data,
            'calc_result'        => $calc_result,
            'pre_consult_summary'    => self::get_or_build_pre_consult_summary( $progress ),
            // v0.33.0 — surface the cache timestamp so the Brief panel can show
            // freshness ("Updated 5 mins ago" / "Updated yesterday") and signal
            // when an addendum has landed since the last refresh.
            'pre_consult_summary_at' => isset( $progress->pre_consult_summary_at ) ? $progress->pre_consult_summary_at : null,
            'why_profile'        => $why ? array(
                'distilled_why'    => $why->distilled_why,
                'ai_reformulation' => $why->ai_reformulation,
                'key_people'       => json_decode( $why->key_people, true ),
                'motivations'      => json_decode( $why->motivations, true ),
                'fears'            => json_decode( $why->fears, true ),
            ) : null,
            'draft_report'       => $draft_content,
            'draft_status'       => $draft ? $draft->status : null,
            'final_report'       => $final_payload,
            'consultation'       => array(
                'id'                     => (int) $consult->id,
                'typed_notes'            => $consult->typed_notes ?: '',
                // raw_notes / ai_organised_notes / approval flag drive the new
                // free-form + organise UI. Empty / null for fresh consultations.
                'raw_notes'              => isset( $consult->raw_notes ) ? ( $consult->raw_notes ?: '' ) : '',
                'ai_organised_notes'     => isset( $consult->ai_organised_notes ) && $consult->ai_organised_notes
                    ? ( json_decode( $consult->ai_organised_notes, true ) ?: null )
                    : null,
                'practitioner_approved'  => isset( $consult->practitioner_approved ) ? (int) $consult->practitioner_approved : 0,
                'approved_at'            => isset( $consult->approved_at ) ? ( $consult->approved_at ?: null ) : null,
                'recommendations'        => json_decode( $consult->recommendations, true ) ?: array(),
                'health_data_changes'    => json_decode( $consult->health_data_changes, true ) ?: array(),
                'status'                 => $consult->status,
            ),
            // v0.28.0 — Addenda timeline. Chronological (oldest first); the
            // frontend renders newest-on-top by reversing client-side. Each
            // row carries occurred_at (editable timestamp set by practitioner)
            // and created_at (server-side audit). Returns [] when empty so
            // the frontend can iterate without null-checks.
            'addenda'            => $wpdb->get_results( $wpdb->prepare(
                "SELECT id, note_text, occurred_at, priority, source, created_at,
                        practitioner_user_id, superseded_by_report_id
                 FROM {$wpdb->prefix}hdlv2_consultation_addenda
                 WHERE consultation_id = %d
                 ORDER BY occurred_at ASC, id ASC",
                (int) $consult->id
            ), ARRAY_A ) ?: array(),
            // v0.34.3 — Phase N recovery banner. Set by the Phase N migration
            // for any progress whose Final row was healed from a corrupted
            // status. The frontend renders an inline blue banner above the
            // addenda section: "This consultation's Final Report was
            // recovered from a corrupted state. Click Save & Update Plan
            // once to publish the latest content to your client."
            // The transient is deleted by ::regenerate() on successful regen,
            // so the banner self-clears once the practitioner refreshes.
            // Returns null on clean installs (no Phase N damage).
            'phase_n_recovered_at' => get_transient( 'hdlv2_phase_n_recovered_' . (int) $progress_id ) ?: null,
        ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: SAVE NOTES (auto-save)
    // ──────────────────────────────────────────────────────────────

    public function rest_save_notes( $request ) {
        $params      = $request->get_json_params();
        $consult_id  = (int) ( $params['consultation_id'] ?? 0 );
        $typed_notes = sanitize_textarea_field( $params['typed_notes'] ?? '' );

        if ( ! $consult_id ) {
            return new WP_Error( 'missing_id', 'Consultation ID required.', array( 'status' => 400 ) );
        }

        global $wpdb;
        // Write to BOTH typed_notes (legacy column, still read by some paths)
        // AND raw_notes (the new field consumed by the AI organiser). Keeping
        // typed_notes in sync avoids breaking the existing /finalise fallback.
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_consultation_notes',
            array(
                'typed_notes' => $typed_notes,
                'raw_notes'   => $typed_notes,
            ),
            array( 'id' => $consult_id, 'practitioner_user_id' => get_current_user_id() )
        );

        return rest_ensure_response( array( 'success' => true ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: ORGANISE NOTES (new free-form / AI flow — v0.15.0)
    // ──────────────────────────────────────────────────────────────

    /**
     * Take the raw consultation notes (free-form + any appended transcription),
     * run them through Claude with Matthew's organiser prompt, persist the
     * resulting structured JSON to consultation_notes.ai_organised_notes, and
     * return it for the practitioner to review/edit.
     *
     * Body: { consultation_id }
     */
    public function rest_organise_notes( $request ) {
        $params     = $request->get_json_params();
        $consult_id = (int) ( $params['consultation_id'] ?? 0 );

        if ( ! $consult_id ) {
            return new WP_Error( 'missing_id', 'Consultation ID required.', array( 'status' => 400 ) );
        }

        // AI-burn idempotency: this endpoint calls Claude (Sonnet 4) to
        // organise free-form practitioner notes. A double-click without
        // wrap_ai charges Claude twice. Scope is per consultation row.
        $idem_scope = 'org:' . $consult_id . ':' . get_current_user_id();
        return HDLV2_Idempotency::wrap_ai( $request, $idem_scope, function () use ( $consult_id ) {

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, raw_notes, typed_notes FROM {$wpdb->prefix}hdlv2_consultation_notes
             WHERE id = %d AND practitioner_user_id = %d LIMIT 1",
            $consult_id, get_current_user_id()
        ) );

        if ( ! $row ) {
            return new WP_Error( 'not_found', 'Consultation not found.', array( 'status' => 404 ) );
        }

        // Prefer raw_notes; fall back to typed_notes for in-flight rows that
        // pre-date the v0.15.0 cutover (Phase F backfill should have copied
        // these but we double-belt this for safety).
        $raw = trim( (string) ( $row->raw_notes ?: $row->typed_notes ) );
        if ( $raw === '' ) {
            return new WP_Error( 'empty_notes', 'Add some notes before processing.', array( 'status' => 400 ) );
        }

        $organised = HDLV2_AI_Service::organise_consultation_notes( $raw );
        if ( is_wp_error( $organised ) ) {
            return $organised;
        }

        // Persist + reset approval flag so any prior approval doesn't carry
        // over to a freshly-organised version.
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_consultation_notes',
            array(
                'ai_organised_notes'    => wp_json_encode( $organised ),
                'practitioner_approved' => 0,
                'approved_at'           => null,
            ),
            array( 'id' => $consult_id )
        );

        return rest_ensure_response( array(
            'success'              => true,
            'ai_organised_notes'   => $organised,
            'practitioner_approved' => 0,
        ) );
        } );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: APPROVE ORGANISED NOTES (locks the version, unlocks Final Report)
    // ──────────────────────────────────────────────────────────────

    /**
     * Body: { consultation_id, ai_organised_notes (optional — if practitioner
     * inline-edited, the edited JSON is sent here and saved before approval) }
     */
    public function rest_approve_notes( $request ) {
        $params     = $request->get_json_params();
        $consult_id = (int) ( $params['consultation_id'] ?? 0 );
        $edited     = isset( $params['ai_organised_notes'] ) && is_array( $params['ai_organised_notes'] )
            ? $params['ai_organised_notes']
            : null;

        if ( ! $consult_id ) {
            return new WP_Error( 'missing_id', 'Consultation ID required.', array( 'status' => 400 ) );
        }

        global $wpdb;
        $update = array(
            'practitioner_approved' => 1,
            'approved_at'           => current_time( 'mysql' ),
        );
        if ( $edited !== null ) {
            $update['ai_organised_notes'] = wp_json_encode( $edited );
        }

        $wpdb->update(
            $wpdb->prefix . 'hdlv2_consultation_notes',
            $update,
            array( 'id' => $consult_id, 'practitioner_user_id' => get_current_user_id() )
        );

        return rest_ensure_response( array(
            'success'               => true,
            'practitioner_approved' => 1,
            'approved_at'           => $update['approved_at'],
        ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: SAVE ORGANISED NOTES (v0.24.8)
    // ──────────────────────────────────────────────────────────────

    /**
     * Silent persist of inline edits to ai_organised_notes. Used by the
     * auto-save loop on the consultation review panel — lets the practitioner
     * step away mid-edit and return without burning Claude or firing the
     * final report. Does NOT touch practitioner_approved.
     *
     * Body: { consultation_id, ai_organised_notes }
     */
    public function rest_save_organised_notes( $request ) {
        $params     = $request->get_json_params();
        $consult_id = (int) ( $params['consultation_id'] ?? 0 );
        $edited     = isset( $params['ai_organised_notes'] ) && is_array( $params['ai_organised_notes'] )
            ? $params['ai_organised_notes']
            : null;

        if ( ! $consult_id || $edited === null ) {
            return new WP_Error( 'invalid', 'Consultation ID and edited notes required.', array( 'status' => 400 ) );
        }

        global $wpdb;
        // Ownership check — prevents practitioner A overwriting practitioner B's row.
        $owner = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT practitioner_user_id FROM {$wpdb->prefix}hdlv2_consultation_notes WHERE id = %d",
            $consult_id
        ) );
        if ( ! $owner ) {
            return new WP_Error( 'not_found', 'Consultation not found.', array( 'status' => 404 ) );
        }
        if ( $owner !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', 'You do not have access to this consultation.', array( 'status' => 403 ) );
        }

        $wpdb->update(
            $wpdb->prefix . 'hdlv2_consultation_notes',
            array( 'ai_organised_notes' => wp_json_encode( $edited ) ),
            array( 'id' => $consult_id )
        );

        return rest_ensure_response( array(
            'success'  => true,
            'saved_at' => current_time( 'mysql' ),
        ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: RESET AI ORGANISED NOTES (v0.32.5)
    //
    //  Nukes ai_organised_notes + practitioner_approved on the consultation
    //  row so the consultation UI drops back into the EDIT stage on next
    //  render (because orgIsMeaningful evaluates to false). The raw_notes /
    //  typed_notes columns are preserved untouched — the practitioner can
    //  see their original raw text in the textarea, edit/replace it, and
    //  click "Generate Final Report" to re-run /organise on the new content.
    //
    //  Recovery path for the "praise-only first organise" trap (Kim
    //  2026-05-05): when Claude's first /organise produced an empty
    //  recommendations[] array but the UI auto-resumed to the review stage,
    //  the practitioner had no in-product way to redo the organisation.
    //  This endpoint is that escape hatch.
    //
    //  Body: { consultation_id }
    // ──────────────────────────────────────────────────────────────

    public function rest_reset_organised_notes( $request ) {
        $params     = $request->get_json_params();
        $consult_id = (int) ( $params['consultation_id'] ?? 0 );

        if ( ! $consult_id ) {
            return new WP_Error( 'invalid', 'Consultation ID required.', array( 'status' => 400 ) );
        }

        global $wpdb;
        $owner = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT practitioner_user_id FROM {$wpdb->prefix}hdlv2_consultation_notes WHERE id = %d",
            $consult_id
        ) );
        if ( ! $owner ) {
            return new WP_Error( 'not_found', 'Consultation not found.', array( 'status' => 404 ) );
        }
        if ( $owner !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', 'You do not have access to this consultation.', array( 'status' => 403 ) );
        }

        $wpdb->update(
            $wpdb->prefix . 'hdlv2_consultation_notes',
            array(
                'ai_organised_notes'    => null,
                'practitioner_approved' => 0,
                'approved_at'           => null,
            ),
            array( 'id' => $consult_id )
        );

        return rest_ensure_response( array(
            'success'  => true,
            'reset_at' => current_time( 'mysql' ),
        ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: ADD RECOMMENDATION
    // ──────────────────────────────────────────────────────────────

    public function rest_add_recommendation( $request ) {
        $params     = $request->get_json_params();
        $consult_id = (int) ( $params['consultation_id'] ?? 0 );

        // v0.32.5 — Normalise priority to the canonical High/Medium/Low set
        // that Module 200 + the PDFMonkey template's Liquid pill mapping
        // expect. Default Medium when input is anything else.
        $pri_raw = strtolower( (string) ( $params['priority'] ?? 'medium' ) );
        $pri     = in_array( $pri_raw, array( 'low', 'medium', 'high' ), true ) ? ucfirst( $pri_raw ) : 'Medium';

        $rec = array(
            'category'  => sanitize_text_field( $params['category'] ?? '' ),
            'text'      => sanitize_textarea_field( $params['text'] ?? '' ),
            'priority'  => $pri,
            'frequency' => sanitize_text_field( $params['frequency'] ?? 'As needed' ),
        );

        if ( ! $rec['text'] || ! $consult_id ) {
            return new WP_Error( 'invalid', 'Recommendation text and consultation ID required.', array( 'status' => 400 ) );
        }

        global $wpdb;
        // v0.32.5 — write into ai_organised_notes['recommendations'] so the
        // Final Report generator's primary read path picks them up. The
        // legacy `recommendations` column is no longer the source of truth;
        // it remains read-only fallback for ancient pre-v0.15.0 rows.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT ai_organised_notes FROM {$wpdb->prefix}hdlv2_consultation_notes WHERE id = %d AND practitioner_user_id = %d",
            $consult_id, get_current_user_id()
        ) );

        if ( ! $row ) {
            return new WP_Error( 'not_found', 'Consultation not found.', array( 'status' => 404 ) );
        }

        $organised = json_decode( $row->ai_organised_notes ?: '', true );
        if ( ! is_array( $organised ) ) {
            // First manual rec on a consultation that hasn't been AI-organised
            // yet. Build a minimal stub so the review panel renders correctly.
            $organised = array(
                'health_summary'    => '',
                'health_history'    => '',
                'recommendations'   => array(),
                'follow_up_actions' => array(),
                'additional_notes'  => '',
            );
        }
        if ( ! isset( $organised['recommendations'] ) || ! is_array( $organised['recommendations'] ) ) {
            $organised['recommendations'] = array();
        }
        $organised['recommendations'][] = $rec;

        $wpdb->update(
            $wpdb->prefix . 'hdlv2_consultation_notes',
            array(
                'ai_organised_notes'    => wp_json_encode( $organised ),
                'practitioner_approved' => 0,
                'approved_at'           => null,
            ),
            array( 'id' => $consult_id, 'practitioner_user_id' => get_current_user_id() )
        );

        return rest_ensure_response( array(
            'success'             => true,
            'ai_organised_notes'  => $organised,
            'recommendations'     => $organised['recommendations'],
        ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: REMOVE RECOMMENDATION
    // ──────────────────────────────────────────────────────────────

    public function rest_remove_recommendation( $request ) {
        $params     = $request->get_json_params();
        $consult_id = (int) ( $params['consultation_id'] ?? 0 );
        $index      = (int) ( $params['index'] ?? -1 );

        global $wpdb;
        // v0.32.5 — operate on ai_organised_notes['recommendations'] (matches
        // /add-recommendation's new write path). Returns 404 only when the
        // consultation row itself is missing; out-of-range index is a no-op
        // so double-clicks on the remove button stay idempotent.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT ai_organised_notes FROM {$wpdb->prefix}hdlv2_consultation_notes WHERE id = %d AND practitioner_user_id = %d",
            $consult_id, get_current_user_id()
        ) );

        if ( ! $row ) {
            return new WP_Error( 'not_found', 'Consultation not found.', array( 'status' => 404 ) );
        }

        $organised = json_decode( $row->ai_organised_notes ?: '', true );
        if ( ! is_array( $organised ) ) {
            $organised = array(
                'health_summary'    => '',
                'health_history'    => '',
                'recommendations'   => array(),
                'follow_up_actions' => array(),
                'additional_notes'  => '',
            );
        }
        $recs = isset( $organised['recommendations'] ) && is_array( $organised['recommendations'] )
            ? $organised['recommendations']
            : array();

        if ( $index >= 0 && $index < count( $recs ) ) {
            array_splice( $recs, $index, 1 );
            $organised['recommendations'] = $recs;
            $wpdb->update(
                $wpdb->prefix . 'hdlv2_consultation_notes',
                array( 'ai_organised_notes' => wp_json_encode( $organised ) ),
                array( 'id' => $consult_id, 'practitioner_user_id' => get_current_user_id() )
            );
        }

        return rest_ensure_response( array(
            'success'             => true,
            'ai_organised_notes'  => $organised,
            'recommendations'     => $recs,
        ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: EDIT HEALTH DATA FIELD
    // ──────────────────────────────────────────────────────────────

    public function rest_edit_field( $request ) {
        $params      = $request->get_json_params();
        $consult_id  = (int) ( $params['consultation_id'] ?? 0 );
        $progress_id = (int) ( $params['progress_id'] ?? 0 );
        $field       = sanitize_text_field( $params['field'] ?? '' );
        $new_value   = sanitize_text_field( $params['new_value'] ?? '' );
        $reason      = sanitize_text_field( $params['reason'] ?? '' );

        if ( ! $field || ! $consult_id || ! $progress_id ) {
            return new WP_Error( 'invalid', 'Field, consultation ID, and progress ID required.', array( 'status' => 400 ) );
        }

        // v0.23.0 — per-field type / range validation. Old behaviour relied on
        // sanitize_text_field alone, which let typos like "12O" pass through
        // and silently score as 0 (worst-case BP, +3.5yr penalty). Validation
        // mirrors the bounds the wizard JS enforces on the client side, plus
        // explicit "skip" passthrough so practitioners can mark a value as
        // unverified.
        $validation_error = self::validate_field_value( $field, $new_value );
        if ( is_wp_error( $validation_error ) ) {
            return $validation_error;
        }

        global $wpdb;

        // Get current value from form progress (Stage 1 or Stage 3).
        // practitioner_user_id pulled into the SELECT so we can verify
        // ownership before any read or write — closes the IDOR where a
        // valid consult_id (owned) + a foreign progress_id (not owned)
        // would silently mutate another practitioner's client data.
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT stage1_data, stage3_data, practitioner_user_id FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d",
            $progress_id
        ) );

        if ( ! $progress ) {
            return new WP_Error( 'not_found', 'Assessment not found.', array( 'status' => 404 ) );
        }

        if ( (int) $progress->practitioner_user_id !== get_current_user_id()
             && ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', 'You do not have access to this assessment.', array( 'status' => 403 ) );
        }

        $s1 = json_decode( $progress->stage1_data, true ) ?: array();
        $s3 = json_decode( $progress->stage3_data, true ) ?: array();
        $original = $s3[ $field ] ?? $s1[ $field ] ?? '';

        // Log the change in consultation notes
        $consult_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT health_data_changes FROM {$wpdb->prefix}hdlv2_consultation_notes WHERE id = %d AND practitioner_user_id = %d",
            $consult_id, get_current_user_id()
        ) );

        if ( ! $consult_row ) {
            return new WP_Error( 'not_found', 'Consultation not found.', array( 'status' => 404 ) );
        }

        $changes = json_decode( $consult_row->health_data_changes, true ) ?: array();
        $changes[] = array(
            'field'      => $field,
            'original'   => $original,
            'new_value'  => $new_value,
            'reason'     => $reason,
            'changed_by' => get_current_user_id(),
            'timestamp'  => current_time( 'mysql' ),
        );

        $wpdb->update(
            $wpdb->prefix . 'hdlv2_consultation_notes',
            array( 'health_data_changes' => wp_json_encode( $changes ) ),
            array( 'id' => $consult_id )
        );

        // Also update the actual stage data so recalculation uses new values
        // Determine which stage the field belongs to
        // V2 Stage 1 fields: q1_age, q1_sex, q2a, q2b, q3-q9
        $s1_fields = array( 'q1_age', 'q1_sex', 'q2a', 'q2b', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8', 'q9' );
        if ( in_array( $field, $s1_fields, true ) ) {
            $s1[ $field ] = $new_value;
            $wpdb->update(
                $wpdb->prefix . 'hdlv2_form_progress',
                array( 'stage1_data' => wp_json_encode( $s1 ) ),
                array( 'id' => $progress_id )
            );
        } else {
            $s3[ $field ] = $new_value;
        }

        // v0.23.0 — recompute server_result with the edited values applied so
        // any subsequent read (consultation reload, client refreshing the
        // /longevity-draft-report/ page during the edit window) sees calc that
        // is consistent with the displayed field values. Without this, edits
        // moved the field but server_result stayed pinned to the pre-edit
        // numbers, so the practitioner UI showed "BP 120/80 → Rate 1.32" when
        // the new BP should have driven the rate down.
        //
        // Mirrors the same preparation pipeline final-report::generate uses
        // (skip → null, q1_age → age alias, q1_sex → gender alias, activity
        // → physicalActivity alias).
        $calc_data = array_merge( $s1, $s3 );
        if ( isset( $calc_data['q1_age'] ) && ! isset( $calc_data['age'] ) ) {
            $calc_data['age'] = $calc_data['q1_age'];
        }
        if ( isset( $calc_data['q1_sex'] ) && ! isset( $calc_data['gender'] ) ) {
            $calc_data['gender'] = $calc_data['q1_sex'];
        }
        if ( isset( $calc_data['activity'] ) && ! isset( $calc_data['physicalActivity'] ) ) {
            $calc_data['physicalActivity'] = $calc_data['activity'];
        }
        foreach ( $calc_data as $k => $v ) {
            if ( $v === 'skip' ) $calc_data[ $k ] = null;
        }
        $age    = (int) ( $calc_data['age'] ?? 0 );
        $gender = $calc_data['gender'] ?? 'other';
        $fresh_result = HDLV2_Rate_Calculator::calculate_full( $age, $calc_data, $gender );

        $s3['server_result'] = $fresh_result;
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_form_progress',
            array( 'stage3_data' => wp_json_encode( $s3 ) ),
            array( 'id' => $progress_id )
        );

        return rest_ensure_response( array(
            'success'    => true,
            'field'      => $field,
            'original'   => $original,
            'new_value'  => $new_value,
            'changes'    => $changes,
            'calc_result' => $fresh_result,
        ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: FINALISE (trigger Final Report)
    // ──────────────────────────────────────────────────────────────

    /**
     * v0.24.3 — Edit-as-reset. Practitioner has edited consultation data
     * post-Final and wants to re-fire the report + reset the Flight Plan
     * to Week 1. Idempotency-wrapped so accidental double-clicks dedupe.
     */
    public function rest_save_and_regenerate( $request ) {
        // v0.46.0 — async via HDLV2_Job_Queue (see rest_finalise + enqueue_report_job).
        $params      = $request->get_json_params();
        $progress_id = (int) ( $params['progress_id'] ?? 0 );
        $consult_id  = (int) ( $params['consultation_id'] ?? 0 );
        return $this->enqueue_report_job(
            HDLV2_Report_Jobs::JOB_REGEN,
            $progress_id,
            $consult_id,
            null
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: CREATE ADDENDUM (v0.28.0)
    // ──────────────────────────────────────────────────────────────

    /**
     * Insert a new consultation addendum. Practitioner-only, IDOR-checked
     * against the consultation row. Validates priority + source enums and
     * clamps occurred_at into a sane range so the Update Plan flow can't
     * be polluted by far-future or far-past timestamps.
     *
     * Body: { consultation_id, note_text, occurred_at?, priority?, source?, audio_extraction_id? }
     */
    public function rest_create_addendum( $request ) {
        $params      = $request->get_json_params();
        $consult_id  = (int) ( $params['consultation_id'] ?? 0 );
        $note_text   = trim( wp_kses_post( $params['note_text'] ?? '' ) );
        $occurred    = sanitize_text_field( $params['occurred_at'] ?? '' );
        $priority    = strtolower( sanitize_text_field( $params['priority'] ?? 'medium' ) );
        $source      = strtolower( sanitize_text_field( $params['source'] ?? 'typed' ) );
        $audio_eid   = (int) ( $params['audio_extraction_id'] ?? 0 );
        $audio_eid   = $audio_eid > 0 ? $audio_eid : null;

        if ( ! $consult_id || $note_text === '' ) {
            return new WP_Error( 'invalid', 'Consultation ID and note text are required.', array( 'status' => 400 ) );
        }
        if ( ! in_array( $priority, array( 'low', 'medium', 'high' ), true ) ) $priority = 'medium';
        if ( ! in_array( $source,   array( 'typed', 'voice' ), true ) ) $source = 'typed';

        // Clamp occurred_at to [now − 2 years, now + 1 day].
        $occurred_ts = $occurred ? strtotime( $occurred ) : time();
        if ( ! $occurred_ts || $occurred_ts > time() + 86400 || $occurred_ts < strtotime( '-2 years' ) ) {
            $occurred_ts = time();
        }
        $occurred_at_mysql = gmdate( 'Y-m-d H:i:s', $occurred_ts );

        global $wpdb;
        $consult = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, practitioner_user_id, client_user_id, form_progress_id
             FROM {$wpdb->prefix}hdlv2_consultation_notes WHERE id = %d",
            $consult_id
        ) );
        if ( ! $consult ) {
            return new WP_Error( 'not_found', 'Consultation not found.', array( 'status' => 404 ) );
        }
        if ( (int) $consult->practitioner_user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', 'You do not have access to this consultation.', array( 'status' => 403 ) );
        }

        $insert = $wpdb->insert(
            $wpdb->prefix . 'hdlv2_consultation_addenda',
            array(
                'consultation_id'      => $consult_id,
                'practitioner_user_id' => (int) $consult->practitioner_user_id,
                'client_user_id'       => (int) $consult->client_user_id,
                'form_progress_id'     => (int) $consult->form_progress_id,
                'note_text'            => $note_text,
                'occurred_at'          => $occurred_at_mysql,
                'priority'             => $priority,
                'source'               => $source,
                'audio_extraction_id'  => $audio_eid,
            )
        );
        if ( ! $insert ) {
            return new WP_Error( 'db_error', 'Failed to save the addendum.', array( 'status' => 500 ) );
        }
        $addendum_id = (int) $wpdb->insert_id;

        // v0.31.0 — R6: auto-flag addendum for next flight plan.
        //
        // Without this, an addendum is invisible to the next auto-generated
        // plan unless the practitioner ALSO clicks "Save & Update Plan"
        // (which regenerates the Final Report + Flight Plan immediately).
        // For lower-priority addenda the practitioner may choose not to
        // regenerate now — but the next Saturday cron should still see the
        // new clinical observation.
        //
        // We append a short pointer to hdlv2_next_plan_priorities (the same
        // user_meta the practitioner-dashboard's quick-priority field uses).
        // The next generation reads + clears it, so this never accumulates
        // across plans — each plan sees exactly the addenda added since the
        // last plan was generated. We use a short pointer rather than the
        // full note text because the Final Report path already loads the
        // full addenda body via merge_consultation_with_addenda(); duplication
        // here would just inflate the prompt.
        if ( ! empty( $consult->client_user_id ) ) {
            $existing = (string) get_user_meta( (int) $consult->client_user_id, 'hdlv2_next_plan_priorities', true );
            $pointer  = sprintf(
                'New %s-priority addendum logged %s: "%s%s"',
                $priority,
                $occurred_at_mysql,
                substr( wp_strip_all_tags( $note_text ), 0, 240 ),
                strlen( $note_text ) > 240 ? '…' : ''
            );
            $combined = $existing
                ? rtrim( $existing ) . "\n" . $pointer
                : $pointer;
            update_user_meta( (int) $consult->client_user_id, 'hdlv2_next_plan_priorities', $combined );
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_consultation_addenda WHERE id = %d",
            $addendum_id
        ), ARRAY_A );

        return rest_ensure_response( array(
            'success'  => true,
            'addendum' => $row,
        ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: SAVE & UPDATE PLAN (v0.28.0)
    // ──────────────────────────────────────────────────────────────

    /**
     * Practitioner has just submitted a new addendum (via /addendum) and
     * now wants to re-issue the Final Report + Flight Plan with the new
     * addendum incorporated. Same backend mechanic as /save-and-regenerate
     * (delegates to HDLV2_Final_Report::regenerate, which calls generate,
     * which now loads addenda + merges via merge_consultation_with_addenda).
     *
     * Distinct idempotency scope so the dedup bucket doesn't clash with
     * the legacy edit-as-reset banner button. Stable key per
     * progress + consult pair so a double-click within the cache window
     * replays the cached response without re-burning Claude.
     *
     * Body: { progress_id, consultation_id }
     */
    public function rest_save_and_update_plan( $request ) {
        // v0.46.0 — async via HDLV2_Job_Queue. null idem_key → a fresh unique
        // job per deliberate click, so each "Save & Update Plan" produces a new
        // report. Rapid double-clicks are absorbed by the in-flight-job guard
        // in enqueue_report_job() (+ the disabled button), so this cannot
        // double-fire the Claude / PDF webhook / client email side effects.
        $params      = $request->get_json_params();
        $progress_id = (int) ( $params['progress_id'] ?? 0 );
        $consult_id  = (int) ( $params['consultation_id'] ?? 0 );
        return $this->enqueue_report_job(
            HDLV2_Report_Jobs::JOB_REGEN,
            $progress_id,
            $consult_id,
            null
        );
    }

    /**
     * v0.46.0 — Final Report generation now runs ASYNC on HDLV2_Job_Queue.
     *
     * Previously this called HDLV2_Final_Report::generate() inline, holding a
     * PHP worker for the full ~1-3 min of Claude work. With only ~10 workers,
     * a few practitioners finalising at once could exhaust the pool and freeze
     * every page on the site. Now we validate + ownership-check, enqueue a
     * generate_final_report job, and return a job_id immediately. The
     * front-end shows a "preparing" state and polls /jobs/{id}/status. The
     * heavy work runs in the background at a capped concurrency.
     */
    public function rest_finalise( $request ) {
        $params      = $request->get_json_params();
        $progress_id = (int) ( $params['progress_id'] ?? 0 );
        $consult_id  = (int) ( $params['consultation_id'] ?? 0 );
        // Stable idem_key: only ever ONE first-finalise per assessment. A
        // double-click returns the same job; a completed job is returned as-is
        // (the generator's own duplicate guard prevents a second report); a
        // FAILED job is allowed to re-enqueue so "Try again" works.
        return $this->enqueue_report_job(
            HDLV2_Report_Jobs::JOB_FINAL,
            $progress_id,
            $consult_id,
            'genfinal:' . $progress_id . ':' . $consult_id
        );
    }

    /**
     * Shared enqueue path for the async Final Report jobs (v0.46.0).
     *
     * Validates input + ownership (so a non-owner is rejected at the click,
     * not after a queued run), refuses to start a second job while one is
     * already in flight for this assessment, then enqueues and returns the
     * job_id + status_endpoint the front-end polls.
     *
     * @param string      $job_type    HDLV2_Report_Jobs::JOB_FINAL | JOB_REGEN
     * @param int         $progress_id
     * @param int         $consult_id
     * @param string|null $stable_idem Stable idem_key for the idempotent first
     *                                 finalise; null for regenerate (unique key
     *                                 per deliberate click).
     * @return WP_REST_Response|WP_Error
     */
    private function enqueue_report_job( $job_type, $progress_id, $consult_id, $stable_idem ) {
        if ( ! $progress_id || ! $consult_id ) {
            return new WP_Error( 'invalid', 'Progress ID and consultation ID required.', array( 'status' => 400 ) );
        }
        if ( ! class_exists( 'HDLV2_Job_Queue' ) || ! class_exists( 'HDLV2_Report_Jobs' ) ) {
            return new WP_Error( 'queue_unavailable', 'Background processing is unavailable. Please try again shortly.', array( 'status' => 503 ) );
        }

        $practitioner_id = get_current_user_id();

        // Ownership — the same rule the generator enforces, applied up-front.
        global $wpdb;
        $owner = $wpdb->get_var( $wpdb->prepare(
            "SELECT practitioner_user_id FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE id = %d AND deleted_at IS NULL",
            $progress_id
        ) );
        if ( $owner === null ) {
            return new WP_Error( 'not_found', 'Assessment not found.', array( 'status' => 404 ) );
        }
        if ( (int) $owner !== (int) $practitioner_id && ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', 'You do not have access to this assessment.', array( 'status' => 403 ) );
        }

        // Serialise the in-flight check + enqueue with a short per-assessment
        // DB lock. The regenerate path uses a unique idem_key (so the queue's
        // own (job_type, idem_key) dedup can't catch a concurrent double-click),
        // and regenerate() runs in UPDATE mode (no duplicate guard, no UNIQUE
        // report key), so without this two near-simultaneous clicks could both
        // pass the find_latest guard and double-fire the external side effects
        // (Make.com PDF webhook + client email + Flight-Plan reset). The lock
        // makes the second request wait, then see the first's pending row.
        // Fail-open if the lock can't be taken — the disabled button + the
        // in-flight check are still in play.
        $lock_name = substr( 'hdlv2_repjob_' . $progress_id, 0, 64 );
        $got_lock  = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );

        // In-flight guard — if a job of this type for this assessment is
        // already pending/running, return it rather than starting a second.
        $existing = HDLV2_Job_Queue::find_latest( $job_type, $progress_id );
        if ( $existing && in_array( $existing->status, array( 'pending', 'running' ), true ) ) {
            if ( $got_lock ) {
                $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
            }
            return rest_ensure_response( $this->queued_response( (int) $existing->id ) );
        }

        $idem_key = $stable_idem
            ? $stable_idem
            : 'regen:' . $progress_id . ':' . $consult_id . ':' . substr( md5( uniqid( '', true ) ), 0, 12 );

        $job_id = HDLV2_Job_Queue::enqueue(
            $job_type,
            array(
                'progress_id'     => $progress_id,
                'consultation_id' => $consult_id,
                'practitioner_id' => $practitioner_id,
            ),
            array(
                'reference_id' => $progress_id,
                'idem_key'     => $idem_key,
                'priority'     => 85,
                // No auto-retry: report generation has external side effects
                // (Make.com PDF webhook + client email + Flight Plan reset). A
                // retry of a half-completed job could double-fire them. A
                // failed report surfaces "Try again" to the practitioner.
                'max_attempts' => 1,
            )
        );

        if ( $got_lock ) {
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        }

        if ( is_wp_error( $job_id ) ) {
            return new WP_Error( 'enqueue_failed', 'Could not start report generation. Please try again.', array( 'status' => 503 ) );
        }
        return rest_ensure_response( $this->queued_response( (int) $job_id ) );
    }

    private function queued_response( $job_id ) {
        return array(
            'success'         => true,
            'state'           => 'queued',
            'job_id'          => (int) $job_id,
            'status_endpoint' => esc_url_raw( rest_url( 'hdl-v2/v1/jobs/' . (int) $job_id . '/status' ) ),
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  SHORTCODE
    // ──────────────────────────────────────────────────────────────

    public function render_shortcode( $atts ) {
        if ( ! is_user_logged_in() || ! HDLV2_Compatibility::is_practitioner( get_current_user_id() ) ) {
            return '<p>You must be logged in as a practitioner to access the consultation interface.</p>';
        }

        // v0.22.7 — Shared Client Draft renderer assets. The consultation
        // left panel now mounts the SAME premium layout the client sees on
        // /longevity-draft-report/?t=<token>. Practitioner and client now
        // see identical hero / stat strip / trajectory chart / 21-metric
        // radar / awaken-lift-thrive narrative, removing the long-standing
        // divergence where the practitioner saw a stat-only summary.
        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            array(),
            '4.4.0',
            true
        );
        wp_enqueue_script(
            'hdl-trajectory-chart',
            HDLV2_PLUGIN_URL . 'assets/js/hdl-trajectory-chart-hero.js',
            array(),
            HDLV2_VERSION,
            true
        );
        wp_enqueue_script(
            'hdlv2-draft-report',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-draft-report.js',
            array( 'chart-js', 'hdl-trajectory-chart' ),
            HDLV2_VERSION,
            true
        );
        wp_localize_script( 'hdlv2-draft-report', 'HDLV2_DRAFT_REPORT', array(
            'rest_url' => esc_url_raw( rest_url( 'hdl-v2/v1/reports/draft' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
        ) );
        wp_enqueue_style(
            'hdlv2-draft-report',
            HDLV2_PLUGIN_URL . 'assets/css/hdlv2-draft-report.css',
            array(),
            HDLV2_VERSION
        );
        // Inter + Poppins web fonts — match the client report exactly.
        wp_enqueue_style(
            'hdlv2-draft-fonts',
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap',
            array(),
            null
        );

        // Speedometer is declared as a dependency below. It is otherwise only
        // registered inside the staged-form shortcode, which isn't present on
        // the consultation page — so enqueue it here explicitly or WordPress
        // silently drops the consultation script (and the page shows blank).
        wp_enqueue_script(
            'hdlv2-speedometer',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-speedometer.js',
            array(),
            HDLV2_VERSION,
            true
        );

        // Audio component — the consultation JS calls HDLAudioComponent.create()
        // to render the "Record Consultation" section. Without this enqueue the
        // section appears blank because window.HDLAudioComponent is undefined.
        wp_enqueue_script(
            'hdlv2-audio-component',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-audio-component.js',
            array( 'hdlv2-transcriber' ),
            HDLV2_VERSION,
            true
        );

        // v0.20.10 — shared themed confirm dialog (Generate Final Report prompt
        // previously used browser-native confirm() — "backend vibe" per Matthew).
        wp_enqueue_script(
            'hdlv2-ui-modal',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-ui-modal.js',
            array(),
            HDLV2_VERSION,
            true
        );

        wp_enqueue_script(
            'hdlv2-consultation',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-consultation.js',
            array( 'hdlv2-speedometer', 'hdlv2-audio-component', 'hdlv2-ui-modal', 'hdlv2-draft-report', 'hdlv2-loading' ),
            HDLV2_VERSION,
            true
        );

        wp_enqueue_style(
            'hdlv2-consultation',
            HDLV2_PLUGIN_URL . 'assets/css/hdlv2-consultation.css',
            array( 'hdlv2-loading-css' ),
            HDLV2_VERSION
        );

        wp_localize_script( 'hdlv2-consultation', 'hdlv2_consult', array(
            'api_base'    => rest_url( 'hdl-v2/v1/consultation' ),
            // v0.25.2 — list endpoint for the new /consultation/ landing
            // page (no ?progress_id). Branched in init() of the JS.
            'list_url'    => rest_url( 'hdl-v2/v1/consultations/list' ),
            'clients_url' => home_url( '/clients/' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
        ) );

        // v0.21.0 — Practitioner nav spine: sticky top bar with
        // "← Back to clients" + breadcrumb + freshness. Resolves the client
        // name from the ?progress_id param so the breadcrumb reads
        // "Clients › Consultation › Kim" instead of leaving the practitioner
        // with no context.
        $client_name = '';
        $progress_id = isset( $_GET['progress_id'] ) ? absint( $_GET['progress_id'] ) : 0;
        if ( $progress_id ) {
            global $wpdb;
            // v0.41.17 — `AND deleted_at IS NULL`. Breadcrumb name lookup
            // must not surface client identity for archived assessments.
            $client_user_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT client_user_id FROM {$wpdb->prefix}hdlv2_form_progress
                 WHERE id = %d AND deleted_at IS NULL LIMIT 1",
                $progress_id
            ) );
            if ( $client_user_id ) {
                $user = get_userdata( $client_user_id );
                if ( $user ) $client_name = $user->display_name ?: $user->user_login;
            }
        }

        // v0.25.2 — only enqueue the editor-flavoured nav bar in editor mode
        // (?progress_id present). The list view renders its own page header
        // with a single "Back to clients" link inside the JS shell, so a
        // second nav strip would be redundant.
        if ( $progress_id ) {
            wp_enqueue_script(
                'hdlv2-client-nav-bar',
                HDLV2_PLUGIN_URL . 'assets/js/hdlv2-client-nav-bar.js',
                array(),
                HDLV2_VERSION,
                true
            );
            wp_localize_script( 'hdlv2-client-nav-bar', 'hdlv2_nav_bar', array(
                'clients_url' => home_url( '/clients/' ),
                'client_name' => $client_name,
                'page_label'  => 'Consultation',
                'api_base'    => rest_url( 'hdl-v2/v1' ),
                'nonce'       => wp_create_nonce( 'wp_rest' ),
            ) );
        }

        // v0.24.9 — D1: editor-mode page heading per Matthew's transcript
        // ("on this page actually put a title that says Consultation").
        // Heading sits outside the JS hydration root so it survives every
        // re-render of the consultation panel.
        // v0.25.2 — list mode renders its own h1 ("Consultations") inside
        // the JS shell, so the static h1 is suppressed when no progress_id.
        $h1 = $progress_id
            ? '<h1 class="hdlv2-consultation-page-title">Consultation</h1>'
            : '';
        return $h1 . '<div id="hdlv2-consultation" class="hdlv2-consultation-root"></div>';
    }

    // ──────────────────────────────────────────────────────────────
    //  PAGE-LEVEL 404 (v0.25.2)
    // ──────────────────────────────────────────────────────────────

    /**
     * Page-level access gate for /consultation/.
     *
     * v0.35.4 (Quim 2026-05-07) — replaces the previous hard-404 path with
     * the three-way redirect from
     * HDLV2_Compatibility::enforce_practitioner_only_page():
     *
     *   • Logged-out                 → /login/?redirect_to=/consultation/?progress_id=N
     *   • Logged-in non-practitioner → /my-dashboard/
     *   • Practitioner / admin       → page renders normally
     *
     * Why the change: a practitioner who clicked "Record consultation"
     * from a notify email while logged out used to see a 404 instead of
     * being routed back through login (UM never got a chance to fire
     * because this hook ran at template_redirect priority 5). The new
     * helper centralises the three-way logic so every practitioner-only
     * page (this one, /clients/, /practitioner-dashboard/) behaves
     * identically.
     *
     * Method name preserved so the existing register_hooks() registration
     * keeps working without a hook re-bind.
     */
    public function maybe_404_unauthorised() {
        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'hdlv2_consultation' ) ) {
            return;
        }
        HDLV2_Compatibility::enforce_practitioner_only_page();
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: LIST CONSULTATIONS (v0.25.2)
    // ──────────────────────────────────────────────────────────────

    /**
     * GET /hdl-v2/v1/consultations/list
     *
     * Returns:
     *   is_admin: bool
     *   ready[]:    Stage 3 complete, NO consultation_notes row yet
     *   existing[]: latest consultation_notes per progress (status field
     *               either 'in_progress' or 'report_generated')
     *   counts: { ready, in_progress, delivered }
     *
     * Practitioner sees only their own clients (WHERE practitioner_user_id =
     * current_user_id). Admin sees all rows; each row carries a
     * practitioner_name field for the admin column in the UI.
     */
    public function rest_list_consultations( $request ) {
        global $wpdb;

        $uid      = get_current_user_id();
        $is_admin = current_user_can( 'manage_options' );

        $progress_table = $wpdb->prefix . 'hdlv2_form_progress';
        $cn_table       = $wpdb->prefix . 'hdlv2_consultation_notes';

        // ── READY: Stage 3 done, no consultation_notes row ─────────────
        // v0.46.7 — `AND fp.deleted_at IS NULL`. An archived assessment must
        // never appear as "ready to start": rest_load_consultation() rejects
        // deleted rows, so an "Open consultation" button on one would dead-end
        // at "Assessment not found." Keep the list and the loader in lockstep.
        $ready_where = " WHERE fp.stage3_completed_at IS NOT NULL
                         AND fp.deleted_at IS NULL
                         AND NOT EXISTS (
                             SELECT 1 FROM $cn_table cn WHERE cn.form_progress_id = fp.id
                         )";
        $ready_args  = array();
        if ( ! $is_admin ) {
            $ready_where .= ' AND fp.practitioner_user_id = %d';
            $ready_args[] = $uid;
        }

        $ready_sql = "SELECT fp.id AS progress_id,
                             fp.client_user_id,
                             fp.practitioner_user_id,
                             fp.client_name,
                             fp.stage1_data,
                             fp.stage3_data,
                             fp.stage3_completed_at
                      FROM $progress_table fp
                      $ready_where
                      ORDER BY fp.stage3_completed_at DESC";

        $ready_rows = $ready_args
            ? $wpdb->get_results( $wpdb->prepare( $ready_sql, $ready_args ) )
            : $wpdb->get_results( $ready_sql );

        // ── EXISTING: latest consultation_notes per progress ───────────
        // INNER JOIN against MAX(id) per form_progress_id so each progress
        // contributes exactly one row (the most recent consultation).
        // v0.46.7 — always exclude archived (soft-deleted) assessments, same as
        // the READY query and rest_load_consultation(). Without this, a deleted
        // row that still has consultation_notes leaks into "in progress /
        // delivered" and its Resume / Open & re-edit button dead-ends at
        // "Assessment not found."
        $existing_where = ' WHERE fp.deleted_at IS NULL';
        $existing_args  = array();
        if ( ! $is_admin ) {
            $existing_where .= ' AND fp.practitioner_user_id = %d';
            $existing_args[] = $uid;
        }

        $existing_sql = "SELECT fp.id AS progress_id,
                                fp.client_user_id,
                                fp.practitioner_user_id,
                                fp.client_name,
                                fp.stage1_data,
                                fp.stage3_data,
                                fp.stage3_completed_at,
                                latest.id            AS consult_id,
                                latest.status        AS consult_status,
                                latest.started_at    AS consult_started_at,
                                latest.created_at    AS consult_created_at,
                                latest.approved_at   AS consult_approved_at
                         FROM $progress_table fp
                         INNER JOIN (
                             SELECT form_progress_id, MAX(id) AS max_id
                             FROM $cn_table
                             GROUP BY form_progress_id
                         ) m ON m.form_progress_id = fp.id
                         INNER JOIN $cn_table latest ON latest.id = m.max_id
                         $existing_where
                         ORDER BY latest.created_at DESC";

        $existing_rows = $existing_args
            ? $wpdb->get_results( $wpdb->prepare( $existing_sql, $existing_args ) )
            : $wpdb->get_results( $existing_sql );

        // ── Shape rows for the UI ──────────────────────────────────────
        $ready    = array();
        $existing = array();
        foreach ( (array) $ready_rows as $r ) {
            $ready[] = $this->shape_list_row( $r, $is_admin, false );
        }
        foreach ( (array) $existing_rows as $r ) {
            $existing[] = $this->shape_list_row( $r, $is_admin, true );
        }

        // ── Counts ─────────────────────────────────────────────────────
        $in_progress_count = 0;
        $delivered_count   = 0;
        foreach ( $existing as $row ) {
            if ( ( $row['status'] ?? '' ) === 'report_generated' ) {
                $delivered_count++;
            } else {
                $in_progress_count++;
            }
        }

        return rest_ensure_response( array(
            'is_admin' => (bool) $is_admin,
            'ready'    => $ready,
            'existing' => $existing,
            'counts'   => array(
                'ready'       => count( $ready ),
                'in_progress' => $in_progress_count,
                'delivered'   => $delivered_count,
            ),
        ) );
    }

    /**
     * Shape a raw form_progress (+ optional consultation_notes JOIN) row into
     * the JSON shape consumed by hdlv2-consultation.js renderListRow().
     *
     * @param object $row             Raw $wpdb row.
     * @param bool   $is_admin        Whether the caller is admin (decides
     *                                whether to attach practitioner_name).
     * @param bool   $has_consult     True for existing[] rows that JOIN
     *                                consultation_notes.
     */
    private function shape_list_row( $row, $is_admin, $has_consult ) {
        $stage1 = ! empty( $row->stage1_data ) ? ( json_decode( $row->stage1_data, true ) ?: array() ) : array();
        $stage3 = ! empty( $row->stage3_data ) ? ( json_decode( $row->stage3_data, true ) ?: array() ) : array();

        // Client name resolution: form_progress.client_name → user display →
        // user_login → "Client #ID".
        $name = ! empty( $row->client_name ) ? $row->client_name : '';
        if ( ! $name && $row->client_user_id ) {
            $u = get_userdata( $row->client_user_id );
            if ( $u ) {
                $name = $u->display_name ?: $u->user_login;
            }
        }
        if ( ! $name ) {
            $name = $row->client_user_id ? ( 'Client #' . (int) $row->client_user_id ) : 'Unknown client';
        }

        $age  = isset( $stage1['q1_age'] ) ? (int) $stage1['q1_age'] : null;
        $sex_raw = isset( $stage1['q1_sex'] ) ? (string) $stage1['q1_sex'] : '';
        $sex_initial = $sex_raw ? strtoupper( substr( $sex_raw, 0, 1 ) ) : '';

        // Pace-of-ageing rate: prefer Stage 3 server_result (more accurate),
        // fall back to Stage 1 quick estimate.
        $rate = null;
        if ( isset( $stage3['server_result']['rate'] ) ) {
            $rate = (float) $stage3['server_result']['rate'];
        } elseif ( isset( $stage1['rate'] ) ) {
            $rate = (float) $stage1['rate'];
        } elseif ( isset( $stage1['server_result']['rate'] ) ) {
            $rate = (float) $stage1['server_result']['rate'];
        }

        $shaped = array(
            'progress_id'         => (int) $row->progress_id,
            'client_user_id'      => (int) $row->client_user_id,
            'client_name'         => $name,
            'client_initials'     => $this->compute_initials( $name ),
            'age'                 => $age,
            'sex'                 => $sex_initial,
            'rate'                => $rate,
            'stage3_completed_at' => $row->stage3_completed_at,
        );

        if ( $has_consult ) {
            $shaped['status']               = isset( $row->consult_status ) ? $row->consult_status : '';
            $shaped['consult_id']           = isset( $row->consult_id ) ? (int) $row->consult_id : 0;
            $shaped['consult_started_at']   = isset( $row->consult_started_at ) ? $row->consult_started_at : null;
            $shaped['consult_created_at']   = isset( $row->consult_created_at ) ? $row->consult_created_at : null;
            $shaped['consult_approved_at']  = isset( $row->consult_approved_at ) ? $row->consult_approved_at : null;
        }

        if ( $is_admin && ! empty( $row->practitioner_user_id ) ) {
            $pu = get_userdata( (int) $row->practitioner_user_id );
            $shaped['practitioner_name'] = $pu ? ( $pu->display_name ?: $pu->user_login ) : '';
        }

        return $shaped;
    }

    private function compute_initials( $name ) {
        $name = trim( (string) $name );
        if ( ! $name ) {
            return '?';
        }
        $parts = preg_split( '/\s+/', $name );
        $i     = strtoupper( mb_substr( $parts[0], 0, 1 ) );
        if ( count( $parts ) > 1 ) {
            $last = end( $parts );
            $i   .= strtoupper( mb_substr( $last, 0, 1 ) );
        }
        return $i;
    }

    // ──────────────────────────────────────────────────────────────
    //  HELPERS
    // ──────────────────────────────────────────────────────────────

    /**
     * Find or create the active consultation row for a progress.
     *
     * @param object   $progress                Progress row from hdlv2_form_progress.
     * @param int|null $lookup_practitioner_id  Optional. v0.36.9 — when supplied
     *     and != current_user_id (admin viewer), the SELECT pivots on this id
     *     so the actual practitioner's row is found, and a missing row returns
     *     null instead of inserting a stray admin-owned row. Defaults to the
     *     current user for all existing callers — backward compatible.
     */
    private function get_or_create_consultation( $progress, $lookup_practitioner_id = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_consultation_notes';

        $lookup_id       = $lookup_practitioner_id ? (int) $lookup_practitioner_id : get_current_user_id();
        $is_admin_viewer = current_user_can( 'manage_options' ) && $lookup_id !== get_current_user_id();

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE form_progress_id = %d AND practitioner_user_id = %d AND status != 'report_generated' ORDER BY id DESC LIMIT 1",
            $progress->id,
            $lookup_id
        ) );

        if ( $existing ) return $existing;

        // v0.36.9 — admin viewing a consultation that hasn't been started yet
        // returns null rather than inserting a stray admin-owned row. The
        // caller (rest_load_consultation) renders an empty stub so the page
        // still mounts. Real practitioners continue to auto-create on first
        // visit (existing behaviour preserved).
        if ( $is_admin_viewer ) {
            return null;
        }

        $wpdb->insert( $table, array(
            'client_user_id'      => $progress->client_user_id ?: 0,
            'practitioner_user_id'=> get_current_user_id(),
            'form_progress_id'    => $progress->id,
            'started_at'          => current_time( 'mysql' ),
            'status'              => 'in_progress',
        ) );

        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $wpdb->insert_id ) );
    }
}
