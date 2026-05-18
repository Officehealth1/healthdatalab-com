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
                            <th>Archived for</th>
                            <th style="width:110px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="hdlv2-restore-rows">
                        <?php foreach ( $rows as $row ) :
                            $days = max( 0, (int) floor( ( time() - strtotime( $row->deleted_at ) ) / 86400 ) );
                            $stage1 = $row->stage1_completed_at ? esc_html( substr( $row->stage1_completed_at, 0, 10 ) ) : '—';
                            $stage3 = $row->stage3_completed_at ? esc_html( substr( $row->stage3_completed_at, 0, 10 ) ) : '—';
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
                                <?php if ( $days < 7 ) : ?>
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
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <script type="text/javascript">
        (function() {
            document.querySelectorAll('.hdlv2-restore-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id     = this.getAttribute('data-progress-id');
                    var button = this;
                    if (!window.confirm('Restore form_progress #' + id + '? This will make the client reappear on the practitioner\'s dashboard on next page load.')) return;

                    button.disabled = true;
                    button.textContent = 'Restoring…';

                    var fd = new FormData();
                    fd.append('action', 'hdlv2_restore_form_progress');
                    fd.append('nonce', <?php echo wp_json_encode( $nonce ); ?>);
                    fd.append('progress_id', id);

                    fetch(ajaxurl, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(resp) {
                            if (resp.success) {
                                var row = document.getElementById('hdlv2-restore-row-' + id);
                                if (row) {
                                    row.style.transition = 'opacity 0.3s';
                                    row.style.opacity = '0';
                                    setTimeout(function() { row.remove(); }, 300);
                                }
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
        })();
        </script>
        <?php
    }
}
