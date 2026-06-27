<?php
/**
 * V1 Compatibility Bridge.
 *
 * This is the ONLY class that reads from V1 tables.
 * All V1 data access goes through here — no other V2 class should query V1 tables directly.
 *
 * Read-only except for one write path: inserting into wp_health_tracker_progress
 * when a Final Report is generated (so V1 trajectory charts keep working).
 *
 * @package HDL_Longevity_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Compatibility {

    /**
     * Get the practitioner linked to a client.
     *
     * @param int $client_user_id WordPress user ID of the client.
     * @return int|null Practitioner user ID or null.
     */
    public static function get_practitioner_for_client( $client_user_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'health_tracker_practitioner_clients';

        if ( ! self::table_exists( $table ) ) {
            return null;
        }

        return $wpdb->get_var( $wpdb->prepare(
            "SELECT practitioner_user_id FROM $table WHERE client_user_id = %d AND deleted_at IS NULL LIMIT 1",
            $client_user_id
        ) );
    }

    /**
     * Get all clients for a practitioner.
     *
     * @param int $practitioner_user_id WordPress user ID of the practitioner.
     * @return array Array of client user IDs.
     */
    public static function get_clients_for_practitioner( $practitioner_user_id ) {
        global $wpdb;

        // V2-first: the authoritative source of a practitioner's V2 clients is
        // hdlv2_form_progress. The V1 link table doesn't carry a client_user_id
        // column (it keys on email-hash), so querying it for user IDs returned
        // nothing — which is what made the practitioner dashboard look empty.
        $table = $wpdb->prefix . 'hdlv2_form_progress';
        if ( ! self::table_exists( $table ) ) {
            return array();
        }

        // v0.41.15 — soft-delete filter. When a practitioner removes a V2
        // client via the dashboard trash icon, form_progress.deleted_at is
        // stamped (see HDLV2_Client_Status::rest_delete_client). Excluding
        // those rows here hides the client from the practitioner's list
        // without destroying assessment data — re-invite (which restores
        // deleted_at = NULL in rest_create_form / dispatch_post_signup_artifacts)
        // brings the client back with full history.
        // ONLY_FULL_GROUP_BY safe: `SELECT DISTINCT ... ORDER BY id` is illegal
        // when sql_mode contains ONLY_FULL_GROUP_BY (MySQL 5.7.5+/8.0/9.x default),
        // because the ORDER BY column (id) is not in the SELECT list — MySQL raises
        // error 3065 and the whole dashboard returns zero clients. Grouping by
        // client_user_id and ordering by MAX(id) DESC preserves the same distinct
        // rows in the same newest-first order while staying strict-mode valid.
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT client_user_id FROM $table
             WHERE practitioner_user_id = %d
               AND client_user_id IS NOT NULL
               AND deleted_at IS NULL
             GROUP BY client_user_id
             ORDER BY MAX(id) DESC",
            $practitioner_user_id
        ) ) );
    }

    /**
     * Check if a user is a practitioner.
     *
     * @param int $user_id WordPress user ID.
     * @return bool
     */
    public static function is_practitioner( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return false;
        }
        return in_array( 'um_practitioner', (array) $user->roles, true )
            || in_array( 'practitioner', (array) $user->roles, true )
            || in_array( 'administrator', (array) $user->roles, true );
    }

    /**
     * Iridology add-on (IrisMapper) — does this practitioner hold the add-on?
     *
     * The dashboard's public predicate. Resolves from the PRACTITIONER's WP
     * email via the cached, fail-closed entitlement struct below.
     *
     * @param int $practitioner_id
     * @return bool
     */
    public static function practitioner_has_iridology_addon( $practitioner_id ) {
        return self::iris_entitlement( $practitioner_id )['iridologyAddon'] === true;
    }

    /**
     * Cached, fail-closed IrisMapper entitlement struct for a practitioner.
     *
     * Master-flag guarded (Rule-0). The raw HTTP lives in HDLV2_Iris_Addon —
     * this class never calls wp_remote_* directly; it only adds a brief
     * transient cache around the delegated lookup. Errors/timeouts return the
     * fail-closed default and are NOT cached, so a transient blip self-heals
     * on the next load. The post-checkout poll busts the transient (?fresh=1)
     * so the "ready" state appears immediately.
     *
     * @param int $practitioner_id
     * @return array { found, iridologyAddon, hasReportAccess, subscriptionTier, subscriptionStatus }
     */
    public static function iris_entitlement( $practitioner_id ) {
        $fail = array(
            'found'              => false,
            'iridologyAddon'     => false,
            'hasReportAccess'    => false,
            'subscriptionTier'   => null,
            'subscriptionStatus' => null,
        );
        if ( ! get_option( 'hdlv2_ff_iris_addon', false ) ) {
            return $fail; // master guard — feature dark
        }
        $user = get_userdata( (int) $practitioner_id );
        if ( ! $user || ! is_email( $user->user_email ) ) {
            return $fail;
        }

        $key    = 'hdlv2_irido_addon_' . (int) $practitioner_id;
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $res = HDLV2_Iris_Addon::check_entitlement( $user->user_email );
        if ( is_wp_error( $res ) ) {
            return $fail; // FAIL-CLOSED — never cache an error
        }
        $out = wp_parse_args( $res, $fail );
        set_transient( $key, $out, 5 * MINUTE_IN_SECONDS );
        return $out;
    }

    /**
     * v0.35.4 (Quim 2026-05-07) — Page-level access gate for practitioner-only
     * surfaces (/clients/, /consultation/, /practitioner-dashboard/).
     *
     * Three-way redirect:
     *   • Logged-out                 → /login/?redirect_to=<current URL>
     *   • Logged-in non-practitioner → /my-dashboard/ (their own surface)
     *   • Practitioner / admin       → return (page renders)
     *
     * Replaces the previous hard-404 in maybe_404_unauthorised(), which broke
     * the email-link-while-logged-out case and stranded clients on a 404
     * instead of routing them to their own dashboard.
     *
     * Hooked at template_redirect priority 5 so it fires BEFORE Ultimate
     * Member's content-restriction filter (priority 10 default). Without
     * this priority, UM 404s any non-allowed visitor before our redirect
     * logic gets a chance to run.
     *
     * Safe to call on every dashboard render — no-op for the allowed roles.
     * Calls exit on redirect, so subsequent code never runs.
     *
     * @return void
     */
    public static function enforce_practitioner_only_page() {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }

        $user_id = get_current_user_id();

        // Logged out → kick to login with redirect_to back to where they
        // were trying to go (so the "Review in your dashboard" notify-email
        // CTA still lands them on /clients/ after they sign in).
        if ( ! $user_id ) {
            $current_url = ( is_ssl() ? 'https://' : 'http://' )
                . ( isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '' )
                . ( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/' );
            wp_safe_redirect( wp_login_url( $current_url ) );
            exit;
        }

        // Practitioner or administrator → render the page (no redirect).
        if ( self::is_practitioner( $user_id ) ) {
            return;
        }

        // Logged-in non-practitioner (um_client, um_consumer, subscriber,
        // editor, author, contributor, …) → send them to their own
        // dashboard. Filter so individual deployments can override the
        // destination if they ever ship a different non-practitioner home.
        $non_practitioner_url = apply_filters(
            'hdlv2_non_practitioner_redirect',
            home_url( '/my-dashboard/' )
        );
        wp_safe_redirect( $non_practitioner_url );
        exit;
    }

    /**
     * Check whether a practitioner owns a given client.
     *
     * "Owns" = there is at least one hdlv2_form_progress row linking the
     * two. Mirrors get_clients_for_practitioner() so a practitioner can
     * always reach any client that appears in their dashboard, but never
     * reach one that does not.
     *
     * Admins (manage_options) bypass — same precedent as
     * class-hdlv2-staged-form.php::rest_release_why so support flows
     * still work.
     *
     * Closes the IDOR family found in the 2026-04-26 audit, where
     * Timeline / FlightPlan / Final_Report routes treated "is a
     * practitioner" as sufficient and let practitioner A reach
     * practitioner B's client by URL.
     *
     * @param int $practitioner_id WordPress user ID of the caller.
     * @param int $client_id       WordPress user ID of the target client.
     * @return bool
     */
    public static function practitioner_owns_client( $practitioner_id, $client_id ) {
        $practitioner_id = (int) $practitioner_id;
        $client_id       = (int) $client_id;
        if ( ! $practitioner_id || ! $client_id ) {
            return false;
        }

        // Admin escape hatch — matches existing precedent.
        if ( user_can( $practitioner_id, 'manage_options' ) ) {
            return true;
        }

        global $wpdb;
        // v0.41.15 — soft-delete filter. Once a practitioner has removed a
        // client (form_progress.deleted_at stamped), this helper denies all
        // their per-client IDOR-gated endpoints (timeline, flight plan,
        // effort-outcomes, etc.) so the deleted client is consistently
        // invisible from the practitioner surface, not just from the list.
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE practitioner_user_id = %d
               AND client_user_id = %d
               AND deleted_at IS NULL
             LIMIT 1",
            $practitioner_id,
            $client_id
        ) );
        return (bool) $exists;
    }

    /**
     * Like practitioner_owns_client() but also returns true when the row is
     * soft-deleted. ONLY use for endpoints that must access deleted rows —
     * the delete endpoint itself (so a practitioner can re-delete after a
     * race) and the restore-on-create lookups.
     *
     * @since v0.41.15
     * @param int $practitioner_id
     * @param int $client_id
     * @return bool
     */
    public static function practitioner_owns_client_including_deleted( $practitioner_id, $client_id ) {
        $practitioner_id = (int) $practitioner_id;
        $client_id       = (int) $client_id;
        if ( ! $practitioner_id || ! $client_id ) {
            return false;
        }
        if ( user_can( $practitioner_id, 'manage_options' ) ) {
            return true;
        }
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE practitioner_user_id = %d AND client_user_id = %d
             LIMIT 1",
            $practitioner_id,
            $client_id
        ) );
        return (bool) $exists;
    }

    /**
     * Get latest V1 form submission for a client (for Stage 3 data import).
     *
     * @param int $user_id WordPress user ID.
     * @param string $form_type 'longevity' or 'health'.
     * @return object|null Submission row or null.
     */
    public static function get_latest_submission( $user_id, $form_type = 'longevity' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'health_tracker_submissions';

        if ( ! self::table_exists( $table ) ) {
            return null;
        }

        $user_hash = self::get_user_hash( $user_id );
        if ( ! $user_hash ) {
            return null;
        }

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE user_hash = %s AND form_type = %s ORDER BY created_at DESC LIMIT 1",
            $user_hash,
            $form_type
        ) );
    }

    /**
     * Create practitioner-client relationship in V1 table.
     *
     * This write makes widget leads visible in the V1 practitioner dashboard.
     * If an ACTIVE relationship already exists, it refreshes the fields.
     * If a SOFT-DELETED relationship exists, it refuses to restore — admin
     * recovery (Tools → HDL Deleted Data, paid) is the only path back.
     *
     * @param int $practitioner_id Practitioner user ID.
     * @param int $client_id       Client user ID.
     * @return bool True on insert/update of an active row, false otherwise
     *              (no V1 table, no user lookup, OR refused soft-delete restore).
     */
    public static function create_practitioner_client_link( $practitioner_id, $client_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'health_tracker_practitioner_clients';

        if ( ! self::table_exists( $table ) ) {
            return false;
        }

        // Derive email hashes and name from user IDs for V1 dashboard compatibility
        $client_user       = get_userdata( $client_id );
        $practitioner_user = get_userdata( $practitioner_id );

        $client_email_hash       = $client_user ? hash( 'sha256', strtolower( trim( $client_user->user_email ) ) ) : null;
        $practitioner_email_hash = $practitioner_user ? hash( 'sha256', strtolower( trim( $practitioner_user->user_email ) ) ) : null;
        $client_name             = $client_user ? $client_user->display_name : null;

        // Check if relationship exists (including soft-deleted) — match by user ID or email hash
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, deleted_at FROM $table WHERE practitioner_user_id = %d AND (client_user_id = %d OR client_email_hash = %s) LIMIT 1",
            $practitioner_id, $client_id, $client_email_hash ?? ''
        ) );

        if ( $existing ) {
            // v0.41.17 — soft-deleted V1 links stay deleted. Auto-clearing
            // deleted_at here was a silent restore path that defeated the
            // admin-only Tools → HDL Deleted Data ($89) recovery policy.
            // Triggered by every magic-link consumption and every widget
            // signup. Now refused; caller proceeds without a V1 link, and
            // the V2 splicer surfaces the new form_progress as a V2-only row.
            if ( $existing->deleted_at ) {
                error_log( sprintf(
                    '[HDLV2] Refused to auto-restore soft-deleted V1 link (id=%d, prac=%d, client=%d). Admin restore required.',
                    $existing->id, $practitioner_id, $client_id
                ) );
                return false;
            }

            // Active row exists — refresh fields. Do NOT touch deleted_at.
            $update_data = array( 'client_user_id' => $client_id );
            if ( $client_name ) {
                $update_data['client_name'] = $client_name;
            }
            if ( $client_email_hash ) {
                $update_data['client_email_hash'] = $client_email_hash;
            }
            $wpdb->update( $table, $update_data, array( 'id' => $existing->id ) );
            return true;
        }

        // Create new relationship with all V1-required fields
        $insert_data = array(
            'practitioner_user_id'   => $practitioner_id,
            'client_user_id'         => $client_id,
            'linked_date'            => current_time( 'mysql' ),
            'submission_count'       => 0,
        );
        if ( $practitioner_email_hash ) {
            $insert_data['practitioner_email_hash'] = $practitioner_email_hash;
        }
        if ( $client_email_hash ) {
            $insert_data['client_email_hash'] = $client_email_hash;
        }
        if ( $client_name ) {
            $insert_data['client_name'] = $client_name;
        }

        $wpdb->insert( $table, $insert_data );

        // Set user meta so V1 forms lock the practitioner email field
        update_user_meta( $client_id, 'hdl_invited_by_practitioner', $practitioner_id );

        return true;
    }

    /**
     * Write a progress data point (Final Report → V1 progress tracker).
     *
     * This is the ONLY write to a V1 table. It uses the exact format that
     * V1's trajectory charts expect so existing progress tracking keeps working.
     *
     * @param int    $user_id     WordPress user ID.
     * @param array  $metrics     Array of metric_name => metric_value.
     * @param string $date        Date string (Y-m-d).
     * @return bool Success.
     */
    public static function write_progress_point( $user_id, $metrics, $date = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'health_tracker_progress';

        if ( ! self::table_exists( $table ) ) {
            return false;
        }

        $user_hash = self::get_user_hash( $user_id );
        if ( ! $user_hash ) {
            return false;
        }

        $date = $date ?: current_time( 'Y-m-d' );

        foreach ( $metrics as $metric_name => $metric_value ) {
            $wpdb->insert( $table, array(
                'user_hash'        => $user_hash,
                'measurement_date' => $date,
                'metric_name'      => $metric_name,
                'metric_value'     => $metric_value,
            ), array( '%s', '%s', '%s', '%s' ) );
        }

        return true;
    }

    /**
     * Get user hash from V1 system.
     *
     * @param int $user_id WordPress user ID.
     * @return string|null
     */
    private static function get_user_hash( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return null;
        }
        return wp_hash( strtolower( trim( $user->user_email ) ) );
    }

    /**
     * Check if a V1 table exists (safety check).
     *
     * @param string $table Full table name.
     * @return bool
     */
    private static function table_exists( $table ) {
        global $wpdb;
        $result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        return $result === $table;
    }

    /**
     * v0.25.0 — Single source of truth for the practitioner gate that
     * advances a client from Stage 2 → Stage 3 (formerly "Release WHY").
     * Called by BOTH `rest_release_why` (REST) and `ajax_release_why`
     * (AJAX) handlers — see B7 in CLAUDE-CHANGELOG. Make.com integration
     * is untouched: Make.com posts to /form/stage2-callback (creates
     * why_profiles row with released=0); this helper handles the manual
     * practitioner gate that flips released=1 + advances current_stage.
     *
     * @param int $progress_id     wp_hdlv2_form_progress.id
     * @param int $practitioner_id WP user ID of the calling practitioner
     * @return array{ ok: bool, code?: string, message?: string, status?: int, already_released?: bool }
     */
    public static function advance_to_stage_3( $progress_id, $practitioner_id ) {
        global $wpdb;

        // v0.41.17 — `AND deleted_at IS NULL`. Stage 2 → Stage 3 advance must
        // not target a soft-deleted assessment.
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE id = %d AND deleted_at IS NULL",
            (int) $progress_id
        ) );
        if ( ! $progress ) {
            return array( 'ok' => false, 'code' => 'not_found', 'message' => 'Assessment not found.', 'status' => 404 );
        }
        if ( (int) $progress->practitioner_user_id !== (int) $practitioner_id && ! user_can( (int) $practitioner_id, 'manage_options' ) ) {
            return array( 'ok' => false, 'code' => 'forbidden', 'message' => 'You do not have access to this assessment.', 'status' => 403 );
        }

        $why = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, released FROM {$wpdb->prefix}hdlv2_why_profiles WHERE form_progress_id = %d LIMIT 1",
            (int) $progress->id
        ) );
        if ( ! $why ) {
            return array( 'ok' => false, 'code' => 'no_why', 'message' => 'Stage 2 has not been completed yet.', 'status' => 400 );
        }
        if ( $why->released ) {
            return array( 'ok' => true, 'already_released' => true );
        }

        $wpdb->update(
            $wpdb->prefix . 'hdlv2_why_profiles',
            array( 'released' => 1, 'released_at' => current_time( 'mysql' ) ),
            array( 'id' => (int) $why->id )
        );
        // The ONLY place current_stage goes 2 → 3.
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_form_progress',
            array( 'current_stage' => 3 ),
            array( 'id' => (int) $progress->id ),
            array( '%d' ),
            array( '%d' )
        );

        if ( ! empty( $progress->client_email ) && class_exists( 'HDLV2_Email_Templates' ) ) {
            $form_url = site_url( '/assessment/?token=' . $progress->token );
            HDLV2_Email_Templates::why_gate_released( array(
                'client_name'     => $progress->client_name,
                'client_email'    => $progress->client_email,
                'form_url'        => $form_url,
                'practitioner_id' => $progress->practitioner_user_id ?? null,
            ) );
        }

        return array( 'ok' => true );
    }

    /**
     * v0.32.1 — Is this a client-only role (not practitioner, not admin)?
     *
     * Used by the new /my-dashboard/ page guards so practitioners are
     * silently redirected to /clients/ instead of seeing the client UI.
     * Anyone logged-in who is NOT a practitioner counts as a client here
     * (um_client, um_consumer, um_practitioner-invite, subscriber, etc.).
     *
     * @param int $user_id WordPress user ID.
     * @return bool
     */
    public static function is_client_only( $user_id ) {
        $user_id = (int) $user_id;
        if ( ! $user_id ) return false;
        if ( self::is_practitioner( $user_id ) ) return false;
        $user = get_userdata( $user_id );
        return $user ? true : false;
    }

    /**
     * v0.32.1 — Resolve which journey state to render on /my-dashboard/.
     *
     * Returns one of:
     *   'brand-new'   — no form_progress OR Stage 1 not finished
     *   'stage1-done' — Stage 1 finished, no Stage 2 (no why_profiles row)
     *   'stage2-done' — Stage 2 captured (why_profiles row exists), no draft report
     *   'stage3-done' — draft report exists, final not yet finalised
     *   'populated'   — final report exists with status='ready'
     *
     * Reads only V2 tables; cheap (4 single-row lookups, all indexed by
     * client_user_id / form_progress_id). Safe to call on every page render.
     *
     * @param int $user_id WordPress user ID of the client.
     * @return string One of the five state strings above.
     */
    public static function get_client_journey_state( $user_id ) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) return 'brand-new';

        // v0.41.17 — `AND deleted_at IS NULL`. /my-dashboard/ journey state
        // for a client whose latest lifecycle was soft-deleted should reset
        // to 'brand-new' (until they re-engage on a fresh form_progress).
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, current_stage, stage1_completed_at, stage2_completed_at, stage3_completed_at
             FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE client_user_id = %d AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1",
            $user_id
        ) );
        if ( ! $progress ) return 'brand-new';

        $progress_id = (int) $progress->id;

        // ─── populated: the journey is "complete enough" to surface the 3-tab dashboard.
        //
        // Trigger on ANY of three signals. The practitioner-side
        // (HDLV2_Client_Status::calculate_status) considers the journey
        // complete when consultation_notes.status='report_generated', so we
        // mirror that as the canonical signal — anything later is icing.
        // Also triggers on a generated Flight Plan (the actual deliverable
        // clients engage with weekly) to handle older clients whose
        // consultation row was migrated/imported without status update.
        // The status='ready' check on the reports row remains as the cleanest
        // explicit signal for new finalisations.
        //
        // 2026-05-05: previously only the reports.status='ready' check fired,
        // which missed kim_4052 (final row exists with status='') and any
        // client whose final write was interrupted before status flip.
        $consult_done = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}hdlv2_consultation_notes
             WHERE form_progress_id = %d AND status = 'report_generated'
             LIMIT 1",
            $progress_id
        ) );
        $final_ready = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}hdlv2_reports
             WHERE form_progress_id = %d AND report_type = 'final' AND status = 'ready'
             LIMIT 1",
            $progress_id
        ) );
        // v0.41.17 — `AND deleted_at IS NULL`. Archived plans must not
        // flip a fresh-lifecycle client to 'populated'.
        $has_plan = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}hdlv2_flight_plans
             WHERE client_id = %d AND deleted_at IS NULL
             LIMIT 1",
            $user_id
        ) );
        if ( $consult_done || $final_ready || $has_plan ) return 'populated';

        // Draft report exists → practitioner is finalising.
        $draft_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}hdlv2_reports
             WHERE form_progress_id = %d AND report_type = 'draft'
             LIMIT 1",
            $progress_id
        ) );
        if ( $draft_exists || ! empty( $progress->stage3_completed_at ) ) return 'stage3-done';

        // Stage 2 (WHY) captured — released or not, the client has handed it over.
        $why_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}hdlv2_why_profiles
             WHERE form_progress_id = %d
             LIMIT 1",
            $progress_id
        ) );
        if ( $why_exists || ! empty( $progress->stage2_completed_at ) ) return 'stage2-done';

        // Stage 1 finished, no Stage 2 yet.
        if ( ! empty( $progress->stage1_completed_at ) ) return 'stage1-done';

        return 'brand-new';
    }
}
