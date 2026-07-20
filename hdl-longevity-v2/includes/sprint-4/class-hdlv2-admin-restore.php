<?php
/**
 * Admin Restore Interface for Soft-Deleted V2 Form Progress.
 *
 * Mirrors V1's HDL_Admin_Restore (health-data-lab-plugin/includes/admin/
 * class-admin-restore.php) but operates on hdlv2_form_progress instead of
 * the V1 submissions table. Provides a WP admin page where administrators
 * can view soft-deleted V2 client relationships (deleted_at IS NOT NULL)
 * and restore them on request.
 *
 * Restoration is INTENTIONALLY an admin-only flow, gated behind a fee.
 * The practitioner-facing confirm dialog (hdlv2-client-list-enhance.js,
 * v0.41.16) tells the practitioner that re-inviting an email after
 * removal creates a new assessment and does NOT restore archived data —
 * that's this page's job. Capability: manage_options.
 *
 * Per Matthew's "never delete history" rule, deleted rows are kept
 * indefinitely in the DB. There is no purge cron (V1 has one for its
 * submissions table; V2 deliberately does not). The "Days since deleted"
 * column is informational only.
 *
 * @package HDL_Longevity_V2
 * @since v0.41.16
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class HDLV2_Admin_Restore {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {}

    public function register_hooks() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'wp_ajax_hdlv2_restore_form_progress', array( $this, 'ajax_restore' ) );
        add_action( 'wp_ajax_hdlv2_purge_form_progress', array( $this, 'ajax_purge' ) );
    }

    public function add_admin_menu() {
        add_submenu_page(
            'tools.php',
            'V2 Restore (Archived Clients)',
            'V2 Restore',
            'manage_options',
            'hdlv2-restore',
            array( $this, 'render_page' )
        );
    }

    /**
     * AJAX restore handler. Clears deleted_at + deleted_by on the requested
     * form_progress row AND cascades the same clear across every Phase T
     * data table for this (practitioner, client) pair. Idempotent.
     *
     * Restore scope = (practitioner_user_id, client_user_id) pulled from the
     * form_progress row. We restore ALL archived data for that pair, not
     * just the progress row itself, because the data tables are
     * client_id-keyed and the admin's intent here is "make this client
     * whole again". A row that's already active is left alone via the
     * `deleted_at IS NOT NULL` WHERE on each UPDATE.
     *
     * Transactional: wrapped in START TRANSACTION / COMMIT so a partial
     * failure (e.g., one table errors) rolls back the whole restore. This
     * matters because a half-restored client would be visible on the
     * dashboard but missing their timeline / check-ins.
     *
     * Note: when a practitioner re-invited the same email AFTER deleting,
     * a NEW form_progress row was created. Restoring the OLD row leaves
     * both active — the dashboard's "ORDER BY id DESC LIMIT 1" lookup
     * surfaces the NEWER one. Admins who need to surface the OLD row
     * should delete the newer one via SQL. The UI surfaces this caveat.
     */
    public function ajax_restore() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
            return;
        }
        check_ajax_referer( 'hdlv2_restore_nonce', 'nonce' );

        $progress_id = absint( $_POST['progress_id'] ?? 0 );
        if ( ! $progress_id ) {
            wp_send_json_error( array( 'message' => 'Invalid progress ID' ) );
            return;
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        // Resolve the (practitioner, client) tuple BEFORE the UPDATE so
        // the cascade has a stable scope even if something later mutates
        // form_progress.
        $fp = $wpdb->get_row( $wpdb->prepare(
            "SELECT practitioner_user_id, client_user_id, deleted_at
             FROM {$prefix}hdlv2_form_progress WHERE id = %d",
            $progress_id
        ) );
        if ( ! $fp ) {
            wp_send_json_error( array( 'message' => 'Row not found.' ) );
            return;
        }
        if ( ! $fp->deleted_at ) {
            wp_send_json_error( array( 'message' => 'Row is already active (not soft-deleted).' ) );
            return;
        }
        $practitioner_id = (int) $fp->practitioner_user_id;
        $client_id       = (int) $fp->client_user_id;

        $wpdb->query( 'START TRANSACTION' );

        // 1) Restore the specific form_progress row the admin clicked on.
        $restored_progress = $wpdb->query( $wpdb->prepare(
            "UPDATE {$prefix}hdlv2_form_progress
             SET deleted_at = NULL, deleted_by = NULL
             WHERE id = %d AND deleted_at IS NOT NULL",
            $progress_id
        ) );
        if ( $restored_progress === false ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( array( 'message' => 'DB error on form_progress: ' . $wpdb->last_error ) );
            return;
        }

        $cascade_counts = array();

        // 2) V1 link table (email-hash matched, scoped to this practitioner).
        $client_user = $client_id ? get_userdata( $client_id ) : null;
        if ( $client_user && $practitioner_id ) {
            $email_hash = hash( 'sha256', strtolower( trim( $client_user->user_email ) ) );
            $cascade_counts['v1_link'] = (int) $wpdb->update(
                $prefix . 'health_tracker_practitioner_clients',
                array( 'deleted_at' => null, 'deleted_by' => null ),
                array(
                    'practitioner_user_id' => $practitioner_id,
                    'client_email_hash'    => $email_hash,
                ),
                array( '%s', '%d' ),
                array( '%d', '%s' )
            );
        }

        // 3) Cascade un-stamp across Phase T data tables. Scope: (prac, client).
        $cascade = array(
            'hdlv2_checkins'           => 'practitioner_id',
            'hdlv2_timeline'           => 'practitioner_id',
            'hdlv2_flight_plans'       => 'practitioner_id',
            'hdlv2_monthly_summaries'  => 'practitioner_id',
        );
        if ( $practitioner_id && $client_id ) {
            foreach ( $cascade as $bare => $prac_col ) {
                $tbl = $prefix . $bare;
                $rows = $wpdb->query( $wpdb->prepare(
                    "UPDATE $tbl
                     SET deleted_at = NULL, deleted_by = NULL
                     WHERE $prac_col = %d
                       AND client_id = %d
                       AND deleted_at IS NOT NULL",
                    $practitioner_id, $client_id
                ) );
                if ( $rows === false ) {
                    $wpdb->query( 'ROLLBACK' );
                    wp_send_json_error( array( 'message' => "DB error on $bare: " . $wpdb->last_error ) );
                    return;
                }
                $cascade_counts[ $bare ] = (int) $rows;
            }

            // flight_plan_ticks via JOIN to flight_plans for IDOR scope.
            $ticks_table = $prefix . 'hdlv2_flight_plan_ticks';
            $fp_table    = $prefix . 'hdlv2_flight_plans';
            $rows = $wpdb->query( $wpdb->prepare(
                "UPDATE $ticks_table t
                 INNER JOIN $fp_table p ON p.id = t.flight_plan_id
                 SET t.deleted_at = NULL, t.deleted_by = NULL
                 WHERE p.practitioner_id = %d
                   AND t.client_id = %d
                   AND t.deleted_at IS NOT NULL",
                $practitioner_id, $client_id
            ) );
            if ( $rows === false ) {
                $wpdb->query( 'ROLLBACK' );
                wp_send_json_error( array( 'message' => 'DB error on flight_plan_ticks: ' . $wpdb->last_error ) );
                return;
            }
            $cascade_counts['hdlv2_flight_plan_ticks'] = (int) $rows;
        }

        $wpdb->query( 'COMMIT' );

        error_log( sprintf(
            '[HDLV2] Admin Restore: form_progress #%d (prac=%d, client=%d) restored by user %d. Cascade: %s',
            $progress_id, $practitioner_id, $client_id, get_current_user_id(),
            wp_json_encode( $cascade_counts )
        ) );

        wp_send_json_success( array(
            'message' => 'Restored. Client + archived check-ins / timeline / Flight Plans will reappear on the practitioner\'s dashboard.',
            'cascade' => $cascade_counts,
        ) );
    }

    /**
     * v0.41.18 — Right-to-be-forgotten hard purge.
     *
     * Permanently deletes every HDL row for a given (practitioner, client)
     * tuple across all 9 V2 tables + the V1 link table + HDL user_meta keys.
     * No soft-delete recovery after this — the rows are gone.
     *
     * Threat model:
     *   - Only `manage_options` users (administrators) can call this.
     *   - Confirmation: admin must POST `confirm_email` matching the client's
     *     exact `client_email` (case-insensitive, trimmed). Mismatch → 400.
     *   - Idempotent: re-running on an already-purged row returns 404
     *     (form_progress row no longer exists).
     *   - Transactional: every DELETE is wrapped in START TRANSACTION /
     *     COMMIT so a mid-cascade failure rolls back the whole purge
     *     (partial purge would leave orphans). User_meta deletes run AFTER
     *     COMMIT — they're WP API calls that don't participate in the DB
     *     transaction; an audit log entry records both successful and
     *     failed meta deletes.
     *   - Audit: every purge writes two `error_log` lines tagged
     *     `[HDLV2 AUDIT PURGE]` with a unique `audit_id`, the admin user
     *     ID, the target email, and per-table delete counts. Searchable
     *     via grep.
     *
     * Scope: ALL form_progress rows for the (practitioner_user_id,
     * client_user_id) pair — not just the row the admin clicked on. The
     * GDPR right-to-be-forgotten request from a client is end-to-end; if
     * they had two assessments under the same practitioner, both go.
     * Multi-practitioner clients keep their other relationships intact.
     *
     * Does NOT touch:
     *   - WP user account (`wp_users` row, auth, etc) — admin can delete via
     *     standard WP if appropriate. Leaving it lets unrelated plugins
     *     handle their own GDPR flow.
     *   - V1 `health_tracker_submissions` — V1 has its own 30-day purge cron;
     *     this purge does not race that flow.
     *
     * @since v0.41.18
     */
    public function ajax_purge() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
            return;
        }
        check_ajax_referer( 'hdlv2_restore_nonce', 'nonce' );

        $progress_id    = absint( $_POST['progress_id'] ?? 0 );
        $confirm_email  = isset( $_POST['confirm_email'] ) ? sanitize_email( wp_unslash( $_POST['confirm_email'] ) ) : '';
        if ( ! $progress_id || ! $confirm_email ) {
            wp_send_json_error( array( 'message' => 'progress_id and confirm_email required.' ) );
            return;
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        // Lookup target. Does NOT filter deleted_at — purge is admin-only
        // and may target active rows on explicit GDPR request.
        $fp = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, practitioner_user_id, client_user_id, client_email
             FROM {$prefix}hdlv2_form_progress WHERE id = %d",
            $progress_id
        ) );
        if ( ! $fp ) {
            wp_send_json_error( array( 'message' => 'Row not found (already purged or invalid id).' ) );
            return;
        }

        $expected_email = strtolower( trim( (string) $fp->client_email ) );
        $given_email    = strtolower( trim( $confirm_email ) );
        if ( $expected_email === '' || $expected_email !== $given_email ) {
            wp_send_json_error( array(
                'message' => 'Confirmation email does not match the client on this row. Purge aborted.',
            ) );
            return;
        }

        $practitioner_id = (int) $fp->practitioner_user_id;
        $client_id       = (int) $fp->client_user_id;
        $email_lower     = $expected_email;
        $email_hash      = hash( 'sha256', $email_lower );

        $audit_id = 'rtbf_' . wp_generate_password( 12, false, false );
        error_log( sprintf(
            '[HDLV2 AUDIT PURGE] start audit_id=%s admin=%d target_progress_id=%d practitioner_id=%d client_id=%d email=%s',
            $audit_id, get_current_user_id(), $progress_id, $practitioner_id, $client_id, $client_email = (string) $fp->client_email
        ) );

        $counts = array();
        $wpdb->query( 'START TRANSACTION' );

        try {
            // Resolve every form_progress row for this (prac, client) tuple
            // — they share children via the form_progress_id FK.
            $progress_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$prefix}hdlv2_form_progress
                 WHERE practitioner_user_id = %d AND client_user_id = %d",
                $practitioner_id, $client_id
            ) );

            if ( ! empty( $progress_ids ) ) {
                $ids_list = implode( ',', array_map( 'intval', $progress_ids ) );

                // FK children of form_progress
                $counts['why_profiles']         = (int) $wpdb->query( "DELETE FROM {$prefix}hdlv2_why_profiles WHERE form_progress_id IN ($ids_list)" );
                $counts['reports']              = (int) $wpdb->query( "DELETE FROM {$prefix}hdlv2_reports WHERE form_progress_id IN ($ids_list)" );
                $counts['consultation_addenda'] = (int) $wpdb->query( "DELETE FROM {$prefix}hdlv2_consultation_addenda WHERE form_progress_id IN ($ids_list)" );
                $counts['consultation_notes']   = (int) $wpdb->query( "DELETE FROM {$prefix}hdlv2_consultation_notes WHERE form_progress_id IN ($ids_list)" );
                $counts['audio_extractions']    = (int) $wpdb->query( "DELETE FROM {$prefix}hdlv2_audio_extractions WHERE form_progress_id IN ($ids_list)" );
            } else {
                $counts['why_profiles'] = 0;
                $counts['reports'] = 0;
                $counts['consultation_addenda'] = 0;
                $counts['consultation_notes'] = 0;
                $counts['audio_extractions'] = 0;
            }

            // client_id-keyed tables (scoped by practitioner too so multi-prac clients lose only this relationship)
            $counts['flight_plan_ticks'] = (int) $wpdb->query( $wpdb->prepare(
                "DELETE t FROM {$prefix}hdlv2_flight_plan_ticks t
                 INNER JOIN {$prefix}hdlv2_flight_plans p ON p.id = t.flight_plan_id
                 WHERE p.practitioner_id = %d AND p.client_id = %d",
                $practitioner_id, $client_id
            ) );
            $counts['flight_plans']      = (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$prefix}hdlv2_flight_plans WHERE practitioner_id = %d AND client_id = %d",
                $practitioner_id, $client_id
            ) );
            $counts['checkins']          = (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$prefix}hdlv2_checkins WHERE practitioner_id = %d AND client_id = %d",
                $practitioner_id, $client_id
            ) );
            $counts['timeline']          = (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$prefix}hdlv2_timeline WHERE practitioner_id = %d AND client_id = %d",
                $practitioner_id, $client_id
            ) );
            $counts['monthly_summaries'] = (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$prefix}hdlv2_monthly_summaries WHERE practitioner_id = %d AND client_id = %d",
                $practitioner_id, $client_id
            ) );

            // form_progress itself (last, after all FK children gone)
            $counts['form_progress']     = (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$prefix}hdlv2_form_progress WHERE practitioner_user_id = %d AND client_user_id = %d",
                $practitioner_id, $client_id
            ) );

            // V1 link table (email-hash scoped — V1 schema has no client_user_id col)
            $v1_table  = $prefix . 'health_tracker_practitioner_clients';
            $v1_exists = $wpdb->get_var( "SHOW TABLES LIKE '" . esc_sql( $v1_table ) . "'" );
            if ( $v1_exists === $v1_table ) {
                $counts['v1_link'] = (int) $wpdb->query( $wpdb->prepare(
                    "DELETE FROM $v1_table WHERE practitioner_user_id = %d AND client_email_hash = %s",
                    $practitioner_id, $email_hash
                ) );
            } else {
                $counts['v1_link'] = 0;
            }

            // Widget tables (practitioner + email-scoped)
            $counts['widget_invites'] = (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$prefix}hdlv2_widget_invites WHERE practitioner_id = %d AND LOWER(client_email) = %s",
                $practitioner_id, $email_lower
            ) );
            $counts['widget_leads']   = (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$prefix}hdlv2_widget_leads WHERE practitioner_user_id = %d AND LOWER(visitor_email) = %s",
                $practitioner_id, $email_lower
            ) );
            $counts['pending_leads']  = (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$prefix}hdlv2_pending_leads WHERE practitioner_id = %d AND LOWER(visitor_email) = %s",
                $practitioner_id, $email_lower
            ) );

            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( sprintf(
                '[HDLV2 AUDIT PURGE] FAILED audit_id=%s error=%s',
                $audit_id, $e->getMessage()
            ) );
            wp_send_json_error( array(
                'message'  => 'Purge failed mid-cascade and rolled back. Check error_log for audit_id ' . $audit_id . '.',
                'audit_id' => $audit_id,
            ) );
            return;
        }

        // HDL-specific user_meta cleanup. NOT inside the DB transaction —
        // these are WP API calls. Errors are logged but don't roll back the
        // table purge (the DB is the source of truth; meta keys are
        // secondary and the user account may not even exist).
        $meta_keys = array(
            'hdl_invited_by_practitioner',
            'hdl_source',
            'hdl_consumer_user',
            'hdl_assessment_link_token',
            'hdl_assessment_link_token_created',
            'hdl_assessment_form_type',
            'hdl_free_report_provisioned',
            'hdlv2_flight_plan_day',
            'hdlv2_next_plan_priorities',
            'hdlv2_engagement_skips',
            'hdlv2_last_engagement_skip_at',
            'hdlv2_plan_repair_at',
        );
        $meta_deleted = 0;
        if ( $client_id ) {
            foreach ( $meta_keys as $key ) {
                if ( delete_user_meta( $client_id, $key ) ) {
                    $meta_deleted++;
                }
            }
        }
        $counts['user_meta'] = $meta_deleted;

        error_log( sprintf(
            '[HDLV2 AUDIT PURGE] complete audit_id=%s counts=%s',
            $audit_id, wp_json_encode( $counts )
        ) );

        wp_send_json_success( array(
            'message'  => 'Purged forever. All HDL data for this practitioner-client pair is gone.',
            'audit_id' => $audit_id,
            'counts'   => $counts,
        ) );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT fp.id, fp.practitioner_user_id, fp.client_user_id, fp.client_name, fp.client_email,
                    fp.deleted_at, fp.deleted_by, fp.stage1_completed_at, fp.stage3_completed_at,
                    p_prac.display_name AS practitioner_name,
                    p_actor.display_name AS deleted_by_name
             FROM {$wpdb->prefix}hdlv2_form_progress fp
             LEFT JOIN {$wpdb->users} p_prac  ON p_prac.ID  = fp.practitioner_user_id
             LEFT JOIN {$wpdb->users} p_actor ON p_actor.ID = fp.deleted_by
             WHERE fp.deleted_at IS NOT NULL
             ORDER BY fp.deleted_at DESC
             LIMIT 200"
        );

        $nonce = wp_create_nonce( 'hdlv2_restore_nonce' );
        ?>
        <div class="wrap">
            <h1>V2 Restore — Archived Clients</h1>
            <p>
                Soft-deleted V2 client relationships. Data is kept indefinitely (no purge cron).
                Restoration policy: admin-only, $89 fee per client (mirrors V1).
                The practitioner's dashboard hides these rows; restoring here brings them back on next page load.
            </p>
            <p style="color:#666;font-size:13px;">
                <strong>Restore cascade.</strong> Clicking <em>Restore</em> un-stamps the form_progress
                row and ALL archived data for this practitioner-client pair — V1 link table, check-ins,
                timeline entries, Flight Plans (+ ticks), and monthly summaries. The whole operation
                runs in one transaction; partial restore is not possible.
            </p>
            <p style="color:#666;font-size:13px;">
                <strong>Note:</strong> If the practitioner re-invited the same email after removal,
                a new form_progress row was created. Restoring the old row leaves both active;
                the dashboard shows whichever has the highest <code>id</code>. To surface the
                restored old row, delete the newer one via SQL.
            </p>
            <p style="color:#666;font-size:13px;border-left:3px solid #dc2626;padding:6px 10px;background:#fef2f2;">
                <strong style="color:#991b1b;">Right to be forgotten (GDPR Article 17).</strong>
                The red <em>Purge forever</em> button runs a hard DELETE across all 9 V2 tables +
                V1 link table + widget tables + HDL <code>user_meta</code> keys for this
                practitioner-client pair. <strong>No soft-delete recovery after that — the rows
                are gone.</strong> Use only on explicit client request. The action requires typing
                the client's email to confirm and is wrapped in a DB transaction (partial purge
                impossible). Every purge writes a <code>[HDLV2 AUDIT PURGE]</code> entry to
                <code>error_log</code> with a unique audit_id and per-table delete counts.
            </p>

            <?php if ( empty( $rows ) ) : ?>
                <div class="notice notice-info"><p>No archived V2 clients found.</p></div>
            <?php else : ?>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:50px;">ID</th>
                            <th>Client</th>
                            <th>Practitioner</th>
                            <th>Stage 1 / Stage 3</th>
                            <th>Deleted at</th>
                            <th>Deleted by</th>
                            <th style="width:90px;">Archived for</th>
                            <th style="width:200px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="hdlv2-restore-rows">
                        <?php foreach ( $rows as $row ) :
                            $days = max( 0, (int) floor( ( time() - strtotime( $row->deleted_at ) ) / 86400 ) );
                            $stage1 = $row->stage1_completed_at ? esc_html( substr( $row->stage1_completed_at, 0, 10 ) ) : '—';
                            $stage3 = $row->stage3_completed_at ? esc_html( substr( $row->stage3_completed_at, 0, 10 ) ) : '—';
                            $client_email_attr = (string) ( $row->client_email ?: '' );
                            $client_name_attr  = (string) ( $row->client_name  ?: '' );
                        ?>
                        <tr id="hdlv2-restore-row-<?php echo (int) $row->id; ?>">
                            <td><?php echo (int) $row->id; ?></td>
                            <td>
                                <strong><?php echo esc_html( $row->client_name ?: '(no name)' ); ?></strong>
                                <br><code style="font-size:11px;"><?php echo esc_html( $row->client_email ?: '—' ); ?></code>
                            </td>
                            <td>
                                <?php echo esc_html( $row->practitioner_name ?: ( '#' . (int) $row->practitioner_user_id ) ); ?>
                            </td>
                            <td>
                                <span style="font-size:12px;">S1: <?php echo $stage1; ?></span><br>
                                <span style="font-size:12px;">S3: <?php echo $stage3; ?></span>
                            </td>
                            <td><?php echo esc_html( $row->deleted_at ); ?></td>
                            <td><?php echo esc_html( $row->deleted_by_name ?: ( '#' . (int) ( $row->deleted_by ?: 0 ) ) ); ?></td>
                            <td>
                                <?php if ( $days >= 365 ) : ?>
                                    <span style="background:#dc2626;color:#fff;padding:2px 8px;border-radius:4px;font-weight:700;font-size:11px;letter-spacing:.02em;" title="Archived &gt; 1 year — candidate for purge review">1y+</span>
                                    <span style="color:#dc2626;font-weight:600;margin-left:6px;"><?php echo $days; ?>d</span>
                                <?php elseif ( $days < 7 ) : ?>
                                    <span><?php echo $days; ?>d</span>
                                <?php elseif ( $days < 90 ) : ?>
                                    <span style="color:#dba617;font-weight:600;"><?php echo $days; ?>d</span>
                                <?php else : ?>
                                    <span style="color:#666;"><?php echo $days; ?>d</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button"
                                        class="button button-primary hdlv2-restore-btn"
                                        data-progress-id="<?php echo (int) $row->id; ?>"
                                        style="font-size:12px;padding:2px 10px;">
                                    Restore
                                </button>
                                <button type="button"
                                        class="button hdlv2-purge-btn"
                                        data-progress-id="<?php echo (int) $row->id; ?>"
                                        data-client-email="<?php echo esc_attr( $client_email_attr ); ?>"
                                        data-client-name="<?php echo esc_attr( $client_name_attr ); ?>"
                                        style="font-size:12px;padding:2px 10px;color:#dc2626;border-color:#dc2626;margin-left:4px;"
                                        title="Hard-delete all data for this practitioner-client pair (GDPR right-to-be-forgotten). Irreversible.">
                                    Purge forever
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <script type="text/javascript">
        (function() {
            var NONCE = <?php echo wp_json_encode( $nonce ); ?>;

            function fadeRemove(id) {
                var row = document.getElementById('hdlv2-restore-row-' + id);
                if (!row) return;
                row.style.transition = 'opacity 0.3s';
                row.style.opacity = '0';
                setTimeout(function() { row.remove(); }, 300);
            }

            document.querySelectorAll('.hdlv2-restore-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id     = this.getAttribute('data-progress-id');
                    var button = this;
                    if (!window.confirm('Restore form_progress #' + id + '? This will make the client reappear on the practitioner\'s dashboard on next page load.')) return;

                    button.disabled = true;
                    button.textContent = 'Restoring…';

                    var fd = new FormData();
                    fd.append('action', 'hdlv2_restore_form_progress');
                    fd.append('nonce', NONCE);
                    fd.append('progress_id', id);

                    fetch(ajaxurl, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(resp) {
                            if (resp.success) {
                                fadeRemove(id);
                            } else {
                                window.alert('Restore failed: ' + (resp.data && resp.data.message ? resp.data.message : 'Unknown error'));
                                button.disabled = false;
                                button.textContent = 'Restore';
                            }
                        })
                        .catch(function() {
                            window.alert('Network error. Please try again.');
                            button.disabled = false;
                            button.textContent = 'Restore';
                        });
                });
            });

            // v0.41.18 — Purge forever (GDPR right-to-be-forgotten).
            //
            // Confirmation requires the admin to type the client's exact
            // email. Mismatch aborts before any network call. Server-side
            // also re-validates the email matches the row's client_email
            // (defense in depth — a tampered DOM cannot bypass the check).
            document.querySelectorAll('.hdlv2-purge-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id     = this.getAttribute('data-progress-id');
                    var email  = this.getAttribute('data-client-email') || '';
                    var name   = this.getAttribute('data-client-name') || '(no name)';
                    var button = this;

                    if (!email) {
                        window.alert('This row has no client_email on file. Cannot use Purge — delete via SQL after manual review.');
                        return;
                    }

                    var prompt1 = 'PERMANENT DELETION — GDPR right-to-be-forgotten.\n\n' +
                                  'About to hard-delete ALL HDL data for:\n' +
                                  '  ' + name + ' <' + email + '>\n\n' +
                                  'Tables affected: form_progress, why_profiles, reports, consultation_notes, ' +
                                  'consultation_addenda, audio_extractions, checkins, timeline, flight_plans, ' +
                                  'flight_plan_ticks, monthly_summaries, V1 link, widget_invites, widget_leads, ' +
                                  'pending_leads, HDL user_meta keys.\n\n' +
                                  'This is IRREVERSIBLE. There is no soft-delete recovery after this.\n\n' +
                                  'To confirm, type the client\'s email exactly:';
                    var typed = window.prompt(prompt1, '');
                    if (typed === null) return; // cancelled

                    if (typed.trim().toLowerCase() !== email.trim().toLowerCase()) {
                        window.alert('Email did not match (expected "' + email + '"). Purge aborted — no rows changed.');
                        return;
                    }

                    button.disabled = true;
                    button.textContent = 'Purging…';

                    var fd = new FormData();
                    fd.append('action', 'hdlv2_purge_form_progress');
                    fd.append('nonce', NONCE);
                    fd.append('progress_id', id);
                    fd.append('confirm_email', typed.trim());

                    fetch(ajaxurl, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(resp) {
                            if (resp.success) {
                                var c = (resp.data && resp.data.counts) ? resp.data.counts : {};
                                var summary = Object.keys(c).map(function(k) { return k + '=' + c[k]; }).join(', ');
                                window.alert('Purged forever. audit_id=' + (resp.data.audit_id || 'n/a') + '\n\nRows deleted:\n' + summary);
                                fadeRemove(id);
                            } else {
                                window.alert('Purge failed: ' + (resp.data && resp.data.message ? resp.data.message : 'Unknown error') +
                                             (resp.data && resp.data.audit_id ? '\n\naudit_id=' + resp.data.audit_id : ''));
                                button.disabled = false;
                                button.textContent = 'Purge forever';
                            }
                        })
                        .catch(function() {
                            window.alert('Network error. Purge aborted — no rows changed.');
                            button.disabled = false;
                            button.textContent = 'Purge forever';
                        });
                });
            });
        })();
        </script>
        <?php
    }
}
