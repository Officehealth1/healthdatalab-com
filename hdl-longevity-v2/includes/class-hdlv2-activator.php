<?php
/**
 * Plugin activator — creates V2 tables on activation.
 *
 * Tables are prefixed wp_hdlv2_* to avoid any collision with V1's wp_health_tracker_*.
 * Sprint tables are created incrementally — only Sprint 1 tables exist now.
 * Future sprints add their tables via version-gated upgrade logic.
 *
 * @package HDL_Longevity_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Activator {

    /**
     * WP activation hook target. Fires once when the plugin is activated.
     */
    public static function activate() {
        self::upgrade();
    }

    /**
     * Runtime upgrade entry point. Idempotent — safe to call on every
     * boot when hdlv2_db_version < HDLV2_DB_VERSION. Closes the gap
     * when we deploy via scp (no Deactivate/Reactivate step).
     */
    public static function upgrade() {
        self::create_tables();
        self::run_migrations();
        self::schedule_crons();
        update_option( 'hdlv2_db_version', HDLV2_DB_VERSION );
        update_option( 'hdlv2_version', HDLV2_VERSION );
    }

    private static function schedule_crons() {
        $crons = array(
            'hdlv2_audio_cleanup'           => 'daily',
            'hdlv2_checkin_reminder'        => 'daily',
            'hdlv2_weekly_flight_plan'      => 'daily',
            'hdlv2_monthly_summary'         => 'daily',
            'hdlv2_quarterly_review'        => 'daily',
            'hdlv2_pending_leads_cleanup'   => 'daily',
            'hdlv2_inactivity_sweep'        => 'daily',
            // v0.40.17 — nudge practitioner about Stage 2 clients stuck > 3 days.
            'hdlv2_stuck_release_reminder'  => 'daily',
            // v0.40.19 — retry stuck Stage 2 WHY extractions (Make.com → local fallback).
            'hdlv2_stage2_extraction_retry' => 'daily',
            // v0.41.8 (Bug-3) — daily digest email for practitioners' clients in
            // needs_attention state. Without this, practitioners only see the
            // red badge if they happen to load /clients/.
            'hdlv2_attention_email_cron'    => 'daily',
            // v0.30.0 — bumped from hdlv2_five_minutes to hdlv2_one_minute.
            // 5-min safety-net wait was visible to practitioners as a 1-3 min
            // "Transcribing your audio..." stall whenever the loopback kick
            // (trigger_worker_async) failed silently on the small-VPS SSL
            // handshake (timeout 0.1s, blocking false). Real-time wakeups via
            // the loopback are still primary; the cron is the safety net.
            'hdlv2_job_queue_worker'        => 'hdlv2_one_minute',
            // Iridology Phase 2 (v3.19) — off-request-path reconcile: pulls any
            // result whose callback was lost, off any browser thread, on a real
            // OS-cron timer (DISABLE_WP_CRON is set + /etc/cron.d/hdlv2-wp-cron),
            // so durability never depends on traffic. Self-guards on the flag.
            'hdlv2_iris_reconcile'          => 'hdlv2_one_minute',
        );
        foreach ( $crons as $hook => $recurrence ) {
            if ( ! wp_next_scheduled( $hook ) ) {
                wp_schedule_event( time(), $recurrence, $hook );
            }
        }
        // Always reconcile the worker schedule (independent of DB-version
        // upgrade flow) so existing installs migrate off hdlv2_five_minutes.
        self::ensure_worker_schedule_current();
    }

    /**
     * Idempotent runtime check that the job-queue worker is on the current
     * recurrence (`hdlv2_one_minute` as of v0.30.0). Cheap — one option read
     * + one scheduled-event lookup. Safe to call on every boot.
     *
     * Runs independently of `maybe_upgrade_db()` because that hook is gated
     * on DB-schema version, and we may need to reschedule cron without
     * bumping the DB version (this v0.30.0 release adjusts cron only).
     *
     * @since 0.30.0
     */
    public static function ensure_worker_schedule_current() {
        $hook        = 'hdlv2_job_queue_worker';
        $target      = 'hdlv2_one_minute';
        $next        = wp_next_scheduled( $hook );

        if ( ! $next ) {
            wp_schedule_event( time(), $target, $hook );
            return;
        }

        $existing = wp_get_scheduled_event( $hook );
        if ( $existing && isset( $existing->schedule ) && $existing->schedule !== $target ) {
            wp_unschedule_event( $next, $hook );
            wp_schedule_event( time(), $target, $hook );
        }
    }

    /**
     * Idempotent runtime check for the v0.40.17 stuck-release nudge cron.
     *
     * Mirrors ensure_worker_schedule_current(): runs on every boot from
     * maybe_upgrade_db() so existing installs that don't trigger an
     * HDLV2_DB_VERSION upgrade still pick up the new daily nudge schedule.
     * Without this, the cron would only register on a fresh plugin
     * activation — but our deploy mechanism is file-only scp.
     *
     * Cheap — one scheduled-event lookup. Idempotent.
     *
     * @since 0.40.17
     */
    public static function ensure_stuck_release_reminder_scheduled() {
        if ( ! wp_next_scheduled( 'hdlv2_stuck_release_reminder' ) ) {
            wp_schedule_event( time(), 'daily', 'hdlv2_stuck_release_reminder' );
        }
    }

    /**
     * Idempotent runtime check for the v0.40.19 Stage 2 extraction retry cron.
     *
     * Same pattern as ensure_stuck_release_reminder_scheduled — runs on
     * every boot from maybe_upgrade_db() so existing installs pick up the
     * new cron without a DB-version bump.
     *
     * @since 0.40.19
     */
    public static function ensure_stage2_extraction_retry_scheduled() {
        if ( ! wp_next_scheduled( 'hdlv2_stage2_extraction_retry' ) ) {
            wp_schedule_event( time(), 'daily', 'hdlv2_stage2_extraction_retry' );
        }
    }

    /**
     * Idempotent runtime check for the v0.41.8 attention-digest cron.
     *
     * Same pattern as ensure_stuck_release_reminder_scheduled — runs on
     * every boot from maybe_upgrade_db() so existing installs pick up the
     * new cron without a DB-version bump.
     *
     * @since 0.41.8
     */
    public static function ensure_attention_email_cron_scheduled() {
        if ( ! wp_next_scheduled( 'hdlv2_attention_email_cron' ) ) {
            wp_schedule_event( time(), 'daily', 'hdlv2_attention_email_cron' );
        }
    }

    /**
     * Idempotent runtime check for the weekly flight-plan cron.
     *
     * v0.41.0 — Mirrors the v0.40.17 / v0.40.19 pattern. Without this, an
     * install that activated the plugin BEFORE the hdlv2_weekly_flight_plan
     * cron existed (or that lost its schedule for any reason — e.g. WP-Cron
     * pruning, manual `wp_unschedule_event`) silently lacks the Saturday
     * auto-generation. The LIVE plugin has been activated for months and
     * almost certainly falls into this category — confirmed risk per the
     * 2026-05-13 audit. Cheap — one scheduled-event lookup per boot.
     *
     * @since 0.41.0
     */
    public static function ensure_weekly_flight_plan_scheduled() {
        if ( ! wp_next_scheduled( 'hdlv2_weekly_flight_plan' ) ) {
            wp_schedule_event( time(), 'daily', 'hdlv2_weekly_flight_plan' );
        }
    }

    private static function run_migrations() {
        $current_db_version = get_option( 'hdlv2_db_version', '0' );

        global $wpdb;
        $p = $wpdb->prefix;

        // Phases A-D — original 2.0 migration. Phase-gated so a fresh install at
        // 2.1+ skips it; a stale install at < 2.0 still picks it up.
        if ( version_compare( $current_db_version, '2.0', '<' ) ) {
            // Phase A: ENUM to VARCHAR for timeline entry_type
            // dbDelta cannot modify column types, so use ALTER TABLE
            $col_type = $wpdb->get_var( "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}hdlv2_timeline' AND COLUMN_NAME = 'entry_type'" );
            if ( $col_type && strpos( $col_type, 'enum' ) !== false ) {
                $wpdb->query( "ALTER TABLE {$p}hdlv2_timeline MODIFY COLUMN entry_type VARCHAR(50) NOT NULL" );
            }

            // Phase B: Backfill timeline date/temporal_type/category/source from existing rows
            $has_date_col = $wpdb->get_var( "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}hdlv2_timeline' AND COLUMN_NAME = 'date'" );
            if ( $has_date_col ) {
                $wpdb->query( "UPDATE {$p}hdlv2_timeline SET date = created_at, temporal_type = 'instant', category = 'system', source = 'system' WHERE date IS NULL" );
            }

            // Phase C: Backfill checkins input_method based on raw_transcript presence
            $has_input_method = $wpdb->get_var( "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}hdlv2_checkins' AND COLUMN_NAME = 'input_method'" );
            if ( $has_input_method ) {
                $wpdb->query( "UPDATE {$p}hdlv2_checkins SET input_method = CASE WHEN raw_transcript IS NOT NULL AND raw_transcript != '' THEN 'audio' ELSE 'text' END WHERE input_method = 'text'" );
            }

            // Phase D: Migrate flight_plan_ticks day_of_week (TINYINT) to day (VARCHAR)
            $has_day_of_week = $wpdb->get_var( "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}hdlv2_flight_plan_ticks' AND COLUMN_NAME = 'day_of_week'" );
            $has_day = $wpdb->get_var( "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}hdlv2_flight_plan_ticks' AND COLUMN_NAME = 'day'" );

            if ( $has_day_of_week && $has_day ) {
                // Migrate integer values to string day names
                $wpdb->query( "UPDATE {$p}hdlv2_flight_plan_ticks SET day = CASE day_of_week WHEN 1 THEN 'monday' WHEN 2 THEN 'tuesday' WHEN 3 THEN 'wednesday' WHEN 4 THEN 'thursday' WHEN 5 THEN 'friday' WHEN 6 THEN 'saturday' WHEN 7 THEN 'sunday' WHEN 0 THEN 'sunday' ELSE 'monday' END WHERE day IS NULL OR day = ''" );
                // Drop the old column
                $wpdb->query( "ALTER TABLE {$p}hdlv2_flight_plan_ticks DROP COLUMN day_of_week" );
            } elseif ( $has_day_of_week && ! $has_day ) {
                // Add day column, migrate, drop old
                $wpdb->query( "ALTER TABLE {$p}hdlv2_flight_plan_ticks ADD COLUMN day VARCHAR(10) NOT NULL DEFAULT 'monday' AFTER client_id" );
                $wpdb->query( "UPDATE {$p}hdlv2_flight_plan_ticks SET day = CASE day_of_week WHEN 1 THEN 'monday' WHEN 2 THEN 'tuesday' WHEN 3 THEN 'wednesday' WHEN 4 THEN 'thursday' WHEN 5 THEN 'friday' WHEN 6 THEN 'saturday' WHEN 7 THEN 'sunday' WHEN 0 THEN 'sunday' ELSE 'monday' END" );
                $wpdb->query( "ALTER TABLE {$p}hdlv2_flight_plan_ticks DROP COLUMN day_of_week" );
            }

            error_log( '[HDLV2] Database migration to v2.0 completed.' );
        }

        // Phase F (DB v2.3): backfill consultation_notes.raw_notes from typed_notes
        // for existing rows. New code writes raw_notes directly; this keeps any
        // in-flight consultation rows readable by the new free-form/organise UI.
        if ( version_compare( $current_db_version, '2.3', '<' ) ) {
            $consult_table = $wpdb->prefix . 'hdlv2_consultation_notes';
            $rows = $wpdb->query(
                "UPDATE $consult_table
                 SET raw_notes = typed_notes
                 WHERE raw_notes IS NULL AND typed_notes IS NOT NULL"
            );
            error_log( sprintf( '[HDLV2] Phase F (v2.3) migration: copied typed_notes -> raw_notes on %d row(s).', (int) $rows ) );
        }

        // Phase E (DB v2.2): backfill widget_config for existing um_practitioner
        // users. Closes the bug where a brand-new practitioner's widget POST
        // returned 400 invalid_practitioner because no config row existed.
        // Going forward, the set_user_role hook in HDLV2_Widget_Config seeds
        // the row at the moment a user is promoted to um_practitioner.
        //
        // Implementation note: SQL is inlined (rather than calling
        // HDLV2_Widget_Config::ensure_widget_config_for_user) so the migration
        // is robust against opcache lag — on a hot upgrade, the cached old
        // class definition may not yet have the new helper method when this
        // runs on the very first request after deploy.
        if ( version_compare( $current_db_version, '2.2', '<' ) ) {
            $config_table  = $wpdb->prefix . 'hdlv2_widget_config';
            $practitioners = get_users( array(
                'role'   => 'um_practitioner',
                'fields' => array( 'ID', 'display_name', 'user_login', 'user_email' ),
            ) );
            $seeded = 0;
            foreach ( $practitioners as $u ) {
                $exists = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM $config_table WHERE practitioner_user_id = %d LIMIT 1",
                    $u->ID
                ) );
                if ( $exists ) {
                    continue;
                }
                $wpdb->insert( $config_table, array(
                    'practitioner_user_id' => $u->ID,
                    'practitioner_name'    => $u->display_name ?: $u->user_login,
                    'notification_email'   => $u->user_email,
                ) );
                if ( $wpdb->insert_id ) {
                    $seeded++;
                }
            }
            error_log( sprintf( '[HDLV2] Phase E (v2.2) migration: seeded widget_config for %d practitioner(s).', $seeded ) );
        }

        // Phase G (DB v2.7): composite indexes for /dashboard/version MAX subqueries,
        // Phase A tick aggregation, and Phase D adherence-row lookups.
        //
        // /dashboard/version polls every 4 seconds per practitioner. Without these
        // indexes the six MAX() subqueries full-scan their tables — at 50 practitioners
        // × 30 clients each, p99 jumps from < 50ms to 500-2000ms. Phase A's
        // calculate_adherence() does 4 COUNT(*) queries on flight_plan_ticks per tick;
        // Phase D's adherence read joins timeline by (client_id, entry_type, date).
        //
        // ALTER TABLE ... ADD KEY is online for InnoDB on MySQL 5.6+; no table lock.
        if ( version_compare( $current_db_version, '2.7', '<' ) ) {
            $indexes = array(
                array( 'hdlv2_form_progress',      'idx_client_updated',   '(client_user_id, updated_at)' ),
                array( 'hdlv2_why_profiles',       'idx_progress_updated', '(form_progress_id, updated_at)' ),
                array( 'hdlv2_checkins',           'idx_client_confirmed', '(client_id, confirmed_at)' ),
                array( 'hdlv2_reports',            'idx_progress_updated', '(form_progress_id, updated_at)' ),
                array( 'hdlv2_flight_plans',       'idx_client_created',   '(client_id, created_at)' ),
                array( 'hdlv2_consultation_notes', 'idx_client_started',   '(client_user_id, started_at)' ),
                array( 'hdlv2_flight_plan_ticks',  'idx_plan_category',    '(flight_plan_id, category)' ),
                array( 'hdlv2_timeline',           'idx_client_type_date', '(client_id, entry_type, date)' ),
            );
            $added = 0;
            foreach ( $indexes as $row ) {
                list( $table_suffix, $index_name, $columns ) = $row;
                $table_full = $p . $table_suffix;
                $exists = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
                    $table_full, $index_name
                ) );
                if ( $exists > 0 ) continue;

                // Skip silently if the table itself is missing (defensive — fresh
                // install creates tables in create_tables() above run_migrations).
                $table_present = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                    $table_full
                ) );
                if ( $table_present === 0 ) continue;

                $wpdb->query( "ALTER TABLE $table_full ADD KEY $index_name $columns" );
                if ( ! $wpdb->last_error ) {
                    $added++;
                }
            }
            error_log( sprintf( '[HDLV2] Phase G (v2.7) migration: added %d/%d composite indexes.', $added, count( $indexes ) ) );
        }

        // Phase H (DB v2.8): rate_snapshot column on hdlv2_reports.
        //
        // The Effort vs Outcomes chart re-derives rate from form_progress for
        // each report, but in V2 today every report points to the same
        // form_progress row — so all reports return the same rate, producing
        // a misleadingly flat outcome line once a client has multiple reports
        // (e.g. final + quarterlies). Snapshotting the rate at report-write
        // time gives each report its own canonical value, so the chart shows
        // true measurement points. Old rows keep NULL and the endpoint falls
        // back to re-derive for backwards compatibility.
        if ( version_compare( $current_db_version, '2.8', '<' ) ) {
            $reports_table = $p . 'hdlv2_reports';
            $has_snapshot = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'rate_snapshot'",
                $reports_table
            ) );
            if ( ! $has_snapshot ) {
                $wpdb->query( "ALTER TABLE $reports_table ADD COLUMN rate_snapshot DECIMAL(4,2) DEFAULT NULL AFTER milestones" );
                error_log( '[HDLV2] Phase H (v2.8) migration: added rate_snapshot column to hdlv2_reports.' );
            }
        }

        // Phase I (v2.9) — Flight Plan dedupe + unique constraint.
        //
        // The Final Report's auto-schedule and the practitioner's manual
        // /flight-plan/{id}/generate trigger could race and produce two
        // flight_plans rows for the same (client_id, week_start) — observed
        // during 2026-04-27 E2E. The outer guard inside generate_for_client()
        // mitigates the typical case but doesn't protect against concurrent
        // fires (TOCTOU). The inner generate() didn't guard at all, so any
        // race or any caller bypassing the wrapper produces a duplicate.
        //
        // The fix is two-layered:
        //   1. Code: add a duplicate guard inside generate() with a $force
        //      override for explicit practitioner regeneration.
        //   2. DB: add a UNIQUE KEY on (client_id, week_start) so even if
        //      a future callsite races past the code guard, the DB rejects
        //      the second insert.
        //
        // Adding a unique key on a table that already has duplicates would
        // fail outright, so this migration first dedupes existing rows
        // (keeping MAX(id) per group — empirically the latest generation
        // is the richest, with all ticks populated). Orphaned ticks (whose
        // parent plan we just deleted) are then dropped. Finally the
        // unique index is added.
        if ( version_compare( $current_db_version, '2.9', '<' ) ) {
            try {
                $plans_table = $p . 'hdlv2_flight_plans';
                $ticks_table = $p . 'hdlv2_flight_plan_ticks';

                // 1. Drop duplicate plans, keeping MAX(id) per (client_id, week_start).
                //    Self-join pattern: any row that has another row with same
                //    (client_id, week_start) and a higher id is a duplicate.
                $deleted_dupes = $wpdb->query(
                    "DELETE fp1 FROM $plans_table fp1
                     INNER JOIN $plans_table fp2
                       ON fp1.client_id = fp2.client_id
                      AND fp1.week_start = fp2.week_start
                      AND fp1.id < fp2.id"
                );

                // 2. Drop orphaned tick rows (parent plan was just deleted).
                $deleted_orphans = $wpdb->query(
                    "DELETE t FROM $ticks_table t
                     LEFT JOIN $plans_table fp ON fp.id = t.flight_plan_id
                     WHERE fp.id IS NULL"
                );

                // 3. Add the unique composite index — only if it doesn't
                //    already exist (idempotent re-runs).
                $has_unique = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = %s
                       AND INDEX_NAME = 'idx_unique_client_week'",
                    $plans_table
                ) );
                if ( ! $has_unique ) {
                    $wpdb->query(
                        "ALTER TABLE $plans_table
                         ADD UNIQUE KEY idx_unique_client_week (client_id, week_start)"
                    );
                }

                error_log(
                    sprintf(
                        '[HDLV2] Phase I (v2.9) migration complete: dropped %d duplicate flight_plans, %d orphaned ticks; unique index %s.',
                        (int) $deleted_dupes,
                        (int) $deleted_orphans,
                        $has_unique ? 'already present' : 'created'
                    )
                );
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2] Phase I migration error: ' . $e->getMessage() . ' — boot continues; verify manually.' );
            }
        }

        // Phase J (DB v3.0) — Add 'archived' to status enums.
        //
        // The consultation reset path tries to archive prior Final reports +
        // Flight Plans by setting status = 'archived'. Both target enums lacked
        // that value, so the UPDATE silently failed (MySQL non-strict) or
        // errored (strict). Reset has been broken since v0.24.8. /ultrareview
        // (2026-04-30) caught it. This migration extends both enums in place.
        // Online ALTER MODIFY for InnoDB on MySQL 5.6+ when only ADDING values
        // at the end — no table lock, no rebuild.
        if ( version_compare( $current_db_version, '3.0', '<' ) ) {
            try {
                $reports_table = $p . 'hdlv2_reports';
                $plans_table   = $p . 'hdlv2_flight_plans';

                $reports_has_archived = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
                       AND COLUMN_NAME = 'status' AND COLUMN_TYPE LIKE %s",
                    $reports_table, '%archived%'
                ) );
                if ( ! $reports_has_archived ) {
                    $wpdb->query( "ALTER TABLE $reports_table MODIFY status ENUM('generating','ready','error','archived') DEFAULT 'generating'" );
                    if ( ! $wpdb->last_error ) {
                        error_log( '[HDLV2] Phase J (v3.0) migration: extended hdlv2_reports.status enum with archived.' );
                    }
                }

                $plans_has_archived = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
                       AND COLUMN_NAME = 'status' AND COLUMN_TYPE LIKE %s",
                    $plans_table, '%archived%'
                ) );
                if ( ! $plans_has_archived ) {
                    $wpdb->query( "ALTER TABLE $plans_table MODIFY status ENUM('generated','delivered','active','completed','archived') DEFAULT 'generated'" );
                    if ( ! $wpdb->last_error ) {
                        error_log( '[HDLV2] Phase J (v3.0) migration: extended hdlv2_flight_plans.status enum with archived.' );
                    }
                }
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2] Phase J migration error: ' . $e->getMessage() . ' — boot continues; verify manually.' );
            }
        }

        // Phase L (DB v3.2) — Widget vs Invite split.
        //
        // Widget public path becomes "lead capture only": every successful
        // submission writes its 9 answers as JSON onto widget_leads.stage1_data
        // and skips the verification email entirely. Send-Invites then snaps
        // those answers into widget_invites.prefill_stage1 at invite-creation
        // time so the recipient lands on a pre-filled (editable) widget and
        // can continue to Stage 2. dbDelta in create_tables() handles fresh
        // installs; this block adds the columns to existing installs and adds
        // the (practitioner_user_id, visitor_email) lookup index that the new
        // record_widget_lead() upsert needs to be cheap.
        if ( version_compare( $current_db_version, '3.2', '<' ) ) {
            try {
                $leads_table   = $p . 'hdlv2_widget_leads';
                $invites_table = $p . 'hdlv2_widget_invites';

                $leads_has_stage1 = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'stage1_data'",
                    $leads_table
                ) );
                if ( ! $leads_has_stage1 ) {
                    $wpdb->query( "ALTER TABLE $leads_table ADD COLUMN stage1_data JSON DEFAULT NULL AFTER rate_of_ageing" );
                    if ( ! $wpdb->last_error ) {
                        error_log( '[HDLV2] Phase L (v3.2) migration: added stage1_data to hdlv2_widget_leads.' );
                    }
                }

                $leads_has_email_idx = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'practitioner_email'",
                    $leads_table
                ) );
                if ( ! $leads_has_email_idx ) {
                    $wpdb->query( "ALTER TABLE $leads_table ADD KEY practitioner_email (practitioner_user_id, visitor_email)" );
                    if ( ! $wpdb->last_error ) {
                        error_log( '[HDLV2] Phase L (v3.2) migration: added practitioner_email index to hdlv2_widget_leads.' );
                    }
                }

                $invites_has_prefill = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'prefill_stage1'",
                    $invites_table
                ) );
                if ( ! $invites_has_prefill ) {
                    $wpdb->query( "ALTER TABLE $invites_table ADD COLUMN prefill_stage1 JSON DEFAULT NULL AFTER completed_at" );
                    if ( ! $wpdb->last_error ) {
                        error_log( '[HDLV2] Phase L (v3.2) migration: added prefill_stage1 to hdlv2_widget_invites.' );
                    }
                }
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2] Phase L migration error: ' . $e->getMessage() . ' — boot continues; verify manually.' );
            }
        }

        // Phase M (DB v3.3) — UNIQUE constraint on hdlv2_reports (form_progress_id, report_type).
        //
        // Root cause for the 2026-05-05 duplicate-draft-email incident: create_draft_report
        // used a plain INSERT with no uniqueness check. Two concurrent /complete-stage requests
        // could both pass the read-then-write guard on form_progress.stage3_completed_at, both
        // INSERT a draft row, and each kick off its own /generate-report worker that claimed
        // a different row → 2 webhook fires → 2 client emails.
        //
        // Fix layer A1: enforce one-row-per-(progress_id, report_type) at the schema level so
        // INSERT … ON DUPLICATE KEY UPDATE in create_draft_report can't create siblings, and
        // the atomic claim in rest_generate_report always sees exactly one row to claim.
        //
        // Pre-step dedups any existing duplicates (keeps highest id per pair) before the
        // ALTER ADD UNIQUE — otherwise the index addition fails on tables with duplicates.
        if ( version_compare( $current_db_version, '3.3', '<' ) ) {
            try {
                $reports_table = $p . 'hdlv2_reports';

                $uniq_present = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'uniq_progress_report'",
                    $reports_table
                ) );
                if ( ! $uniq_present ) {
                    // Dedup: keep the highest-id row per (form_progress_id, report_type) pair.
                    // Older rows are stale draft remnants from the pre-fix race.
                    $deleted = $wpdb->query(
                        "DELETE r1 FROM {$reports_table} r1
                         INNER JOIN {$reports_table} r2
                            ON r1.form_progress_id = r2.form_progress_id
                           AND r1.report_type = r2.report_type
                           AND r1.id < r2.id"
                    );
                    if ( $deleted ) {
                        error_log( sprintf( '[HDLV2] Phase M (v3.3) dedup: removed %d duplicate report rows before adding UNIQUE index.', (int) $deleted ) );
                    }

                    $wpdb->query( "ALTER TABLE {$reports_table} ADD UNIQUE KEY uniq_progress_report (form_progress_id, report_type)" );
                    if ( ! $wpdb->last_error ) {
                        error_log( '[HDLV2] Phase M (v3.3) migration: added UNIQUE KEY uniq_progress_report on hdlv2_reports.' );
                    } else {
                        error_log( '[HDLV2] Phase M (v3.3) ALTER failed: ' . $wpdb->last_error );
                    }
                }
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2] Phase M migration error: ' . $e->getMessage() . ' — boot continues; verify manually.' );
            }
        }

        // Phase N (DB v3.5) — Recovery sweep + regenerate-strategy switch.
        //
        // Background: Phase J (DB v3.0) was supposed to add 'archived' to
        // hdlv2_reports.status enum so ::regenerate() could archive the prior
        // Final before INSERTing a fresh one. On STBY the ALTER TABLE
        // silently failed for the reports table (still ENUM('generating',
        // 'ready','error')), so every status='archived' UPDATE truncated the
        // value to '' (MySQL non-strict). Phase M (DB v3.3) then added
        // UNIQUE KEY uniq_progress_report (form_progress_id, report_type) to
        // fix the duplicate-draft-email race. The combination broke
        // /save-and-update-plan: regenerate's archive UPDATE corrupted the
        // existing row, the unique key blocked the new INSERT, $wpdb->insert
        // returned false silently, and the client_draft_view fell back to
        // the draft because the page logic requires status='ready'. Six
        // failed clicks on kim's consultation produced six bogus addenda
        // (superseded_by_report_id=0), one orphan consult (report_id=0), and
        // 6 PDFs/emails sent without DB record.
        //
        // The fix replaces the regenerate strategy with UPDATE-in-place
        // (commit v0.34.3), so 'archived' is no longer needed in the enum.
        // This Phase N migration heals the existing damage and also writes
        // a 90-day transient per recovered progress so the consultation UI
        // can render a one-time blue banner: "This consultation's Final
        // Report was recovered from a corrupted state. Click Save & Update
        // Plan once to publish the latest content to your client."
        //
        // F1: corrupted-status finals → 'ready' (only rows with non-trivial
        //     report_content; empty rows would mean the original generation
        //     never completed and shouldn't be marked ready).
        // F2: orphan addenda superseded_by_report_id=0 → re-stamp to the
        //     now-recovered Final id for the same form_progress.
        // F3: orphan consultation_notes report_id=0 with status='report_
        //     generated' → re-stamp to the recovered Final id.
        // F4: per recovered progress, set 90-day transient
        //     hdlv2_phase_n_recovered_{progress_id} = current timestamp.
        //
        // Runs AFTER Phase M so the unique key is in place — the JOINs in
        // F2/F3 then pick exactly one Final row per progress.
        // No-op on LIVE (no rows match WHERE clauses on a clean install).
        // Idempotent — re-running matches 0 rows after first success.
        if ( version_compare( $current_db_version, '3.5', '<' ) ) {
            try {
                $reports_table  = $p . 'hdlv2_reports';
                $addenda_table  = $p . 'hdlv2_consultation_addenda';
                $consult_table  = $p . 'hdlv2_consultation_notes';

                // Capture form_progress_ids of corrupted rows BEFORE F1 so we
                // can later identify which entries were recovered (for F4's
                // transient writes). A row is "corrupt" if its status is not
                // in the canonical ENUM set AND its report_content is
                // non-trivial.
                $corrupt_rows = $wpdb->get_results(
                    "SELECT id, form_progress_id FROM $reports_table
                     WHERE report_type = 'final'
                       AND status NOT IN ('generating','ready','error')
                       AND LENGTH(report_content) > 100",
                    ARRAY_A
                );
                $corrupt_progress = array_unique( array_map( 'intval', wp_list_pluck( $corrupt_rows ?: array(), 'form_progress_id' ) ) );

                // F1 — restore corrupt finals to status='ready'.
                $f1_count = $wpdb->query(
                    "UPDATE $reports_table SET status = 'ready'
                     WHERE report_type = 'final'
                       AND status NOT IN ('generating','ready','error')
                       AND LENGTH(report_content) > 100"
                );

                // F2 — re-stamp orphan addenda (superseded_by_report_id = 0)
                // to the now-recovered Final id for the addendum's progress.
                // After Phase M's unique key, exactly one Final row per
                // progress, so the JOIN picks the right id deterministically.
                $f2_count = $wpdb->query(
                    "UPDATE $addenda_table a
                     INNER JOIN $consult_table c ON c.id = a.consultation_id
                     INNER JOIN $reports_table r ON r.form_progress_id = c.form_progress_id
                                                 AND r.report_type = 'final'
                                                 AND r.status = 'ready'
                     SET a.superseded_by_report_id = r.id
                     WHERE a.superseded_by_report_id = 0"
                );

                // F3 — re-stamp orphan consultation_notes (report_id = 0
                // with status = 'report_generated', meaning the regen
                // pipeline marked it generated but never wrote the real id).
                $f3_count = $wpdb->query(
                    "UPDATE $consult_table c
                     INNER JOIN $reports_table r ON r.form_progress_id = c.form_progress_id
                                                 AND r.report_type = 'final'
                                                 AND r.status = 'ready'
                     SET c.report_id = r.id
                     WHERE c.report_id = 0 AND c.status = 'report_generated'"
                );

                // F4 — write a 90-day transient per recovered progress so
                // the consultation page can show a one-time recovery banner
                // until the practitioner clicks Save & Update Plan to refresh
                // the report content. The regenerate() success path
                // delete_transient()s, so the banner self-clears on use.
                $f4_count = 0;
                if ( ! empty( $corrupt_progress ) ) {
                    $now = current_time( 'mysql' );
                    foreach ( $corrupt_progress as $pid ) {
                        if ( $pid > 0 ) {
                            set_transient( 'hdlv2_phase_n_recovered_' . $pid, $now, 90 * DAY_IN_SECONDS );
                            $f4_count++;
                        }
                    }
                }

                error_log( sprintf(
                    '[HDLV2 Phase N] (v3.5) recovery sweep: F1 reports=%d, F2 addenda=%d, F3 consults=%d, F4 banners=%d',
                    (int) $f1_count, (int) $f2_count, (int) $f3_count, (int) $f4_count
                ) );
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2] Phase N migration error: ' . $e->getMessage() . ' — boot continues; verify manually.' );
            }
        }

        // Phase O (DB v3.6) — Practitioner-Confirm widget flow.
        //
        // The widget public path used to be a dead end for the visitor: they
        // submitted, saw a gauge, and waited for a practitioner-issued invite
        // they were never told would arrive. v0.35.0 introduces an inbox model
        // — every public submission lands as status='pending' on widget_leads,
        // the practitioner reviews + Confirms in the Client Tools modal, and
        // only then does the client get a magic-link email to Stage 2.
        //
        // Added columns:
        //   widget_leads.status (pending|confirmed|rejected), confirmed_at, rejected_at
        //   widget_config.show_book_button_after_widget (TINYINT bool, default 0)
        //
        // Existing public widget submissions made before v0.35.0 default to
        // 'pending' — they appear in the practitioner's new inbox the next
        // time they open Client Tools. This is intentional: those leads were
        // previously orphaned (no email to client, no path forward); the
        // practitioner can finally action them.
        if ( version_compare( $current_db_version, '3.6', '<' ) ) {
            try {
                $leads_table  = $p . 'hdlv2_widget_leads';
                $config_table = $p . 'hdlv2_widget_config';

                // 1. widget_leads.status (ENUM)
                $leads_has_status = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'status'",
                    $leads_table
                ) );
                if ( ! $leads_has_status ) {
                    $wpdb->query( "ALTER TABLE $leads_table ADD COLUMN status ENUM('pending','confirmed','rejected') DEFAULT 'pending' AFTER invite_id" );
                    if ( ! $wpdb->last_error ) {
                        error_log( '[HDLV2] Phase O (v3.6) migration: added status to hdlv2_widget_leads.' );
                    }
                }

                // 2. widget_leads.confirmed_at
                $leads_has_confirmed = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'confirmed_at'",
                    $leads_table
                ) );
                if ( ! $leads_has_confirmed ) {
                    $wpdb->query( "ALTER TABLE $leads_table ADD COLUMN confirmed_at DATETIME DEFAULT NULL AFTER status" );
                }

                // 3. widget_leads.rejected_at
                $leads_has_rejected = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'rejected_at'",
                    $leads_table
                ) );
                if ( ! $leads_has_rejected ) {
                    $wpdb->query( "ALTER TABLE $leads_table ADD COLUMN rejected_at DATETIME DEFAULT NULL AFTER confirmed_at" );
                }

                // 4. widget_leads compound index for the inbox feed query
                $leads_has_status_idx = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'practitioner_status'",
                    $leads_table
                ) );
                if ( ! $leads_has_status_idx ) {
                    $wpdb->query( "ALTER TABLE $leads_table ADD KEY practitioner_status (practitioner_user_id, status, created_at)" );
                }

                // 5. widget_config.show_book_button_after_widget — per-practitioner
                //    toggle. Off by default per Matthew's "no button on public-path
                //    result page" rule (transcript 2026-05-?). Turning it on
                //    re-renders the Book-a-Session secondary button on the public
                //    widget result page (invite path always shows it when set).
                $cfg_has_book = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'show_book_button_after_widget'",
                    $config_table
                ) );
                if ( ! $cfg_has_book ) {
                    $wpdb->query( "ALTER TABLE $config_table ADD COLUMN show_book_button_after_widget TINYINT(1) DEFAULT 0 AFTER theme_color" );
                    if ( ! $wpdb->last_error ) {
                        error_log( '[HDLV2] Phase O (v3.6) migration: added show_book_button_after_widget to hdlv2_widget_config.' );
                    }
                }
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2] Phase O migration error: ' . $e->getMessage() . ' — boot continues; verify manually.' );
            }
        }

        // Phase P (DB v3.7) — Stage 1 editorial redesign.
        //
        // Adds `logo_shape` to hdlv2_widget_config. Server detects logo
        // aspect ratio at upload via getimagesize() and stamps one of:
        //
        //   square   — aspect ratio between 0.7:1 and 1.4:1 (renders as a
        //              white circle on every surface)
        //   wordmark — aspect ratio > 1.4:1 (renders as a letterbox pill)
        //   tall     — aspect ratio < 0.7:1 (renders as a portrait pill)
        //
        // Backfill: for existing rows with a non-empty logo_url, attempt
        // getimagesize() on the file IF it lives in our isolated upload
        // directory (wp-content/uploads/hdlv2-logos/). Logos hosted off-
        // site (rare; pre-v0.29 path used wp media library) keep the
        // 'square' default — they'll re-detect correctly on next upload.
        if ( version_compare( $current_db_version, '3.7', '<' ) ) {
            try {
                $config_table = $p . 'hdlv2_widget_config';

                // 1. Add logo_shape column if missing.
                $cfg_has_shape = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'logo_shape'",
                    $config_table
                ) );
                if ( ! $cfg_has_shape ) {
                    $wpdb->query( "ALTER TABLE $config_table ADD COLUMN logo_shape ENUM('square','wordmark','tall') DEFAULT 'square' AFTER logo_url" );
                    if ( ! $wpdb->last_error ) {
                        error_log( '[HDLV2] Phase P (v3.7) migration: added logo_shape to hdlv2_widget_config.' );
                    }
                }

                // 2. Backfill shape for existing logo_url rows. Loop is
                //    intentionally bounded — typical install has <100
                //    practitioners, so this is fine on a boot hook. If a
                //    site grows, this whole block is one-shot (column-
                //    add gate skips on second run).
                $upload_dir = wp_upload_dir();
                $logos_root = isset( $upload_dir['basedir'] ) ? $upload_dir['basedir'] . '/hdlv2-logos/' : '';
                $logos_url  = isset( $upload_dir['baseurl'] ) ? $upload_dir['baseurl'] . '/hdlv2-logos/' : '';

                $rows = $wpdb->get_results(
                    "SELECT practitioner_user_id, logo_url FROM $config_table WHERE logo_url IS NOT NULL AND logo_url != ''"
                );
                $backfilled = 0;
                if ( is_array( $rows ) && $logos_root && $logos_url ) {
                    foreach ( $rows as $row ) {
                        $url = (string) $row->logo_url;
                        if ( strpos( $url, $logos_url ) !== 0 ) {
                            continue; // off-site logo — skip, default 'square'
                        }
                        $rel  = substr( $url, strlen( $logos_url ) );
                        $path = $logos_root . $rel;
                        if ( ! is_file( $path ) ) {
                            continue;
                        }
                        $size = @getimagesize( $path );
                        if ( ! $size || empty( $size[0] ) || empty( $size[1] ) ) {
                            continue;
                        }
                        $shape = self::classify_logo_shape( (int) $size[0], (int) $size[1] );
                        $wpdb->update(
                            $config_table,
                            array( 'logo_shape' => $shape ),
                            array( 'practitioner_user_id' => (int) $row->practitioner_user_id ),
                            array( '%s' ),
                            array( '%d' )
                        );
                        $backfilled++;
                    }
                }
                if ( $backfilled > 0 ) {
                    error_log( sprintf( '[HDLV2] Phase P (v3.7) migration: backfilled logo_shape for %d practitioner(s).', $backfilled ) );
                }
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2] Phase P migration error: ' . $e->getMessage() . ' — boot continues; verify manually.' );
            }
        }

        // Phase Q (DB v3.8) — form_progress token TTL.
        //
        // Closes a production-readiness gap surfaced by the v0.40.20 audit:
        // form_progress.token magic links worked indefinitely. A leaked
        // email gave permanent access; stale clients re-clicking 6-month-old
        // emails landed on a schema/copy that had drifted; the absence of
        // expiry weakened the bearer-credential model the rest of the
        // plugin depends on (widget_invites.expires_at is 72h max).
        //
        // Adds the column nullable so legacy rows (NULL) stay valid — old
        // links keep working until a practitioner explicitly regenerates
        // them. New rows get a 90-day TTL by default, filterable via
        // `hdlv2_progress_token_ttl_days`. Validation lives in the
        // ?token= magic-link handler + validate_token_param/from_body.
        //
        // No backfill is performed: NULL = "no expiry set" = legacy bridge.
        // Practitioners get a "Regenerate Link" REST endpoint
        // (/form/regenerate-token) to issue a fresh 90-day token on demand.
        if ( version_compare( $current_db_version, '3.8', '<' ) ) {
            try {
                $progress_table = $p . 'hdlv2_form_progress';

                $progress_has_expiry = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'token_expires_at'",
                    $progress_table
                ) );
                if ( ! $progress_has_expiry ) {
                    $wpdb->query( "ALTER TABLE $progress_table ADD COLUMN token_expires_at DATETIME DEFAULT NULL AFTER token" );
                    if ( ! $wpdb->last_error ) {
                        error_log( '[HDLV2] Phase Q (v3.8) migration: added token_expires_at to hdlv2_form_progress.' );
                    }
                }
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2] Phase Q migration error: ' . $e->getMessage() . ' — boot continues; verify manually.' );
            }
        }

        // Phase R (DB v3.9) — Backfill is_private on system-generated
        // timeline rows that leaked to clients.
        //
        // Two entry types were being written without is_private=1:
        //   - 'adherence'       — duplicates the Effort chart on the client
        //                          Progress tab; redundant in /my-dashboard/.
        //   - 'engagement_skip' — internal coaching-ops messaging; the
        //                          summary says "Practitioner outreach
        //                          recommended" about the client. Was
        //                          masked in practice by the pre-existing
        //                          dashboard column-name bug (Recent
        //                          Activity query used 'client_user_id'
        //                          which doesn't exist on hdlv2_timeline,
        //                          so the section silently returned zero
        //                          rows for every client). v0.41.6 fixes
        //                          the column name — without this Phase R
        //                          backfill, every populated client would
        //                          immediately see legacy adherence +
        //                          engagement_skip rows in their feed on
        //                          their next dashboard render.
        //
        // The writes in class-hdlv2-flight-plan.php (lines 1494 and 1809)
        // now stamp is_private=1 at insert time. This migration covers
        // the pre-v0.41.6 backlog. Idempotent: only flips rows still at 0.
        //
        // Practitioner-side timeline view is unaffected — query_timeline()
        // in class-hdlv2-timeline.php only adds the is_private=0 filter
        // when called from rest_load_client; rest_load_practitioner passes
        // exclude_private=false.
        if ( version_compare( $current_db_version, '3.9', '<' ) ) {
            try {
                $timeline_table = $p . 'hdlv2_timeline';
                $rows           = $wpdb->query(
                    "UPDATE $timeline_table
                     SET is_private = 1
                     WHERE entry_type IN ('engagement_skip','adherence')
                       AND ( is_private = 0 OR is_private IS NULL )"
                );
                if ( false === $rows ) {
                    error_log( '[HDLV2] Phase R (v3.9) migration FAILED: ' . $wpdb->last_error );
                } else {
                    error_log( sprintf(
                        '[HDLV2] Phase R (v3.9) migration: marked %d timeline row(s) as private (adherence + engagement_skip).',
                        (int) $rows
                    ) );
                }
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2] Phase R migration error: ' . $e->getMessage() . ' — boot continues; verify manually.' );
            }
        }

        // Phase S (DB v3.11) — Practitioner-side soft-delete for V2 clients.
        //
        // Adds deleted_at + deleted_by columns to hdlv2_form_progress so the
        // new /dashboard/client/{id}/delete REST endpoint can hide a V2-only
        // client from the practitioner's list without touching the client's
        // assessment data (Matthew's "never delete history" rule).
        //
        // v0.41.17 — restoration is admin-only (Tools → V2 Restore, $89 fee).
        // Re-inviting / re-signing-up the same email creates a FRESH form_progress
        // row beside the archived one — it does NOT clear deleted_at. The original
        // rest_create_form + dispatch_post_signup_artifacts auto-restore was a
        // soft-delete bypass and has been removed (see filter in those funcs).
        if ( version_compare( $current_db_version, '3.11', '<' ) ) {
            try {
                $fp_table = $p . 'hdlv2_form_progress';

                $has_deleted_at = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'deleted_at'",
                    $fp_table
                ) );
                if ( ! $has_deleted_at ) {
                    $wpdb->query( "ALTER TABLE $fp_table ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER updated_at" );
                    if ( $wpdb->last_error ) {
                        error_log( '[HDLV2] Phase S (v3.11) migration FAILED on deleted_at: ' . $wpdb->last_error );
                    }
                }

                $has_deleted_by = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'deleted_by'",
                    $fp_table
                ) );
                if ( ! $has_deleted_by ) {
                    $wpdb->query( "ALTER TABLE $fp_table ADD COLUMN deleted_by BIGINT(20) UNSIGNED DEFAULT NULL AFTER deleted_at" );
                    if ( $wpdb->last_error ) {
                        error_log( '[HDLV2] Phase S (v3.11) migration FAILED on deleted_by: ' . $wpdb->last_error );
                    }
                }

                // Index on deleted_at so the get_clients_for_practitioner +
                // practitioner_owns_client queries (added filter is
                // AND deleted_at IS NULL) stay sargable as form_progress
                // grows past a few thousand rows.
                $has_idx = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'idx_deleted_at'",
                    $fp_table
                ) );
                if ( ! $has_idx ) {
                    $wpdb->query( "ALTER TABLE $fp_table ADD INDEX idx_deleted_at (deleted_at)" );
                    if ( $wpdb->last_error ) {
                        error_log( '[HDLV2] Phase S (v3.11) migration FAILED on index: ' . $wpdb->last_error );
                    }
                }

                if ( ! $has_deleted_at || ! $has_deleted_by || ! $has_idx ) {
                    error_log( '[HDLV2] Phase S (v3.11) migration: added soft-delete columns + index to hdlv2_form_progress.' );
                }
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2] Phase S migration error: ' . $e->getMessage() . ' — boot continues; verify manually.' );
            }
        }

        // Phase T (DB v3.12) — Cascade soft-delete to client_id-keyed data tables.
        //
        // Phase S stopped the practitioner dashboard from listing a soft-deleted
        // client (form_progress filter). But the V2 splicer uses the same WP user
        // for re-invites of the same email — so any data table keyed by `client_id`
        // (not by form_progress_id) would still expose the previous lifecycle's
        // rows in the new lifecycle. Concretely: timeline entries, weekly check-ins,
        // Flight Plans, Flight-Plan ticks, and monthly summaries.
        //
        // The fix is to add deleted_at / deleted_by + idx_deleted_at to each of
        // those five tables and to cascade the stamp in rest_delete_client. The
        // admin restore page (Tools → V2 Restore) unwinds the stamp across the
        // same set. Without this migration the bypass remained even with the
        // Phase S form_progress filter in place.
        if ( version_compare( $current_db_version, '3.12', '<' ) ) {
            try {
                $cascade_tables = array(
                    'hdlv2_checkins',
                    'hdlv2_timeline',
                    'hdlv2_flight_plans',
                    'hdlv2_flight_plan_ticks',
                    'hdlv2_monthly_summaries',
                );

                foreach ( $cascade_tables as $bare ) {
                    $tbl = $p . $bare;

                    $has_deleted_at = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'deleted_at'",
                        $tbl
                    ) );
                    if ( ! $has_deleted_at ) {
                        $wpdb->query( "ALTER TABLE $tbl ADD COLUMN deleted_at DATETIME DEFAULT NULL" );
                        if ( $wpdb->last_error ) {
                            error_log( "[HDLV2] Phase T (v3.12) FAILED on $tbl.deleted_at: " . $wpdb->last_error );
                        }
                    }

                    $has_deleted_by = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'deleted_by'",
                        $tbl
                    ) );
                    if ( ! $has_deleted_by ) {
                        $wpdb->query( "ALTER TABLE $tbl ADD COLUMN deleted_by BIGINT(20) UNSIGNED DEFAULT NULL" );
                        if ( $wpdb->last_error ) {
                            error_log( "[HDLV2] Phase T (v3.12) FAILED on $tbl.deleted_by: " . $wpdb->last_error );
                        }
                    }

                    $has_idx = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'idx_deleted_at'",
                        $tbl
                    ) );
                    if ( ! $has_idx ) {
                        $wpdb->query( "ALTER TABLE $tbl ADD INDEX idx_deleted_at (deleted_at)" );
                        if ( $wpdb->last_error ) {
                            error_log( "[HDLV2] Phase T (v3.12) FAILED on $tbl.idx_deleted_at: " . $wpdb->last_error );
                        }
                    }
                }

                error_log( '[HDLV2] Phase T (v3.12) migration: cascaded soft-delete columns to ' . count( $cascade_tables ) . ' tables.' );
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2] Phase T migration error: ' . $e->getMessage() . ' — boot continues; verify manually.' );
            }
        }

        // Phase U (DB v3.13) — Automation tier schema additions.
        //
        // Three additive column additions + one new option:
        //   - submitter ENUM('practitioner','client_automation') on
        //     hdlv2_consultation_addenda. The existing `source` column on
        //     this table is ENUM('typed','voice') (input method), so it
        //     cannot carry the practitioner-vs-automation distinction the
        //     dashboard badge needs. Separate column avoids overloading.
        //   - source ENUM('practitioner','automation') on hdlv2_form_progress.
        //   - source ENUM('practitioner','automation') on hdlv2_widget_invites.
        //   - hdlv2_automation_tier_enabled option (default false).
        //     Feature-flag gate for all W4+ automation-tier code paths.
        //
        // Existing rows on all three tables default to 'practitioner' for
        // the new column, preserving current dashboard rendering behaviour.
        // All paths are idempotent — re-running the migration is a no-op.
        if ( version_compare( $current_db_version, '3.13', '<' ) ) {
            try {
                $addenda_table  = $p . 'hdlv2_consultation_addenda';
                $progress_table = $p . 'hdlv2_form_progress';
                $invites_table  = $p . 'hdlv2_widget_invites';

                $has_submitter = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'submitter'",
                    $addenda_table
                ) );
                if ( ! $has_submitter ) {
                    $wpdb->query( "ALTER TABLE $addenda_table ADD COLUMN submitter ENUM('practitioner','client_automation') NOT NULL DEFAULT 'practitioner'" );
                    if ( $wpdb->last_error ) {
                        error_log( '[HDLV2] Phase U (v3.13) FAILED on consultation_addenda.submitter: ' . $wpdb->last_error );
                    } else {
                        error_log( '[HDLV2] Phase U (v3.13) migration: added submitter to hdlv2_consultation_addenda.' );
                    }
                }

                $has_progress_source = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'source'",
                    $progress_table
                ) );
                if ( ! $has_progress_source ) {
                    $wpdb->query( "ALTER TABLE $progress_table ADD COLUMN source ENUM('practitioner','automation') NOT NULL DEFAULT 'practitioner'" );
                    if ( $wpdb->last_error ) {
                        error_log( '[HDLV2] Phase U (v3.13) FAILED on form_progress.source: ' . $wpdb->last_error );
                    } else {
                        error_log( '[HDLV2] Phase U (v3.13) migration: added source to hdlv2_form_progress.' );
                    }
                }

                $has_invites_source = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'source'",
                    $invites_table
                ) );
                if ( ! $has_invites_source ) {
                    $wpdb->query( "ALTER TABLE $invites_table ADD COLUMN source ENUM('practitioner','automation') NOT NULL DEFAULT 'practitioner'" );
                    if ( $wpdb->last_error ) {
                        error_log( '[HDLV2] Phase U (v3.13) FAILED on widget_invites.source: ' . $wpdb->last_error );
                    } else {
                        error_log( '[HDLV2] Phase U (v3.13) migration: added source to hdlv2_widget_invites.' );
                    }
                }

                // Feature flag (default disabled). add_option is a no-op if
                // already set — safe to re-run.
                add_option( 'hdlv2_automation_tier_enabled', false );

                error_log( '[HDLV2] Phase U (v3.13) migration completed.' );
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2] Phase U migration error: ' . $e->getMessage() . ' — boot continues; verify manually.' );
            }
        }

        // Phase V (DB v3.14) — intake red-flag scan columns on form_progress.
        if ( version_compare( $current_db_version, '3.14', '<' ) ) {
            try {
                $progress_table = $p . 'hdlv2_form_progress';
                $cols = array(
                    'has_flags'         => "ADD COLUMN has_flags BOOLEAN DEFAULT FALSE",
                    'flags'             => "ADD COLUMN flags JSON DEFAULT NULL",
                    'flags_scanned_at'  => "ADD COLUMN flags_scanned_at DATETIME DEFAULT NULL",
                    'flags_scan_status' => "ADD COLUMN flags_scan_status VARCHAR(20) DEFAULT NULL",
                );
                foreach ( $cols as $col => $ddl ) {
                    $exists = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                        $progress_table, $col
                    ) );
                    if ( ! $exists ) {
                        $wpdb->query( "ALTER TABLE $progress_table $ddl" );
                        if ( $wpdb->last_error ) {
                            error_log( "[HDLV2] Phase V (v3.14) FAILED on form_progress.$col: " . $wpdb->last_error );
                        } else {
                            error_log( "[HDLV2] Phase V (v3.14) migration: added $col to hdlv2_form_progress." );
                        }
                    }
                }
                // Feature flag, default OFF. add_option is a no-op if already set.
                add_option( 'hdlv2_ff_redflag_scan', false );
                error_log( '[HDLV2] Phase V (v3.14) migration completed.' );
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2] Phase V migration error: ' . $e->getMessage() . ' — boot continues; verify manually.' );
            }
        }

        // Phase W (DB v3.15) — per-practitioner clinic / practice name, set in
        // Widget Settings (e.g. "Altituding"). Shown on the FLIGHT Consultation
        // Notes cover; empty hides the Clinic cell (no clinic concept otherwise).
        if ( version_compare( $current_db_version, '3.15', '<' ) ) {
            try {
                $config_table = $p . 'hdlv2_widget_config';
                $has_clinic = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'clinic_name'",
                    $config_table
                ) );
                if ( ! $has_clinic ) {
                    $wpdb->query( "ALTER TABLE $config_table ADD COLUMN clinic_name VARCHAR(200) DEFAULT '' AFTER practitioner_name" );
                    if ( $wpdb->last_error ) {
                        error_log( '[HDLV2] Phase W (v3.15) FAILED on widget_config.clinic_name: ' . $wpdb->last_error );
                    } else {
                        error_log( '[HDLV2] Phase W (v3.15) migration: added clinic_name to hdlv2_widget_config.' );
                    }
                }
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2] Phase W migration error: ' . $e->getMessage() . ' — boot continues; verify manually.' );
            }
        }

        // Phase X (DB v3.16) — report/why PDF storage (D-2). PDFMonkey download
        // URLs expire ~1h, so the report-PDF callback downloads + self-hosts the
        // file; these columns hold the stored path + a stamp so dashboard
        // "Download PDF" buttons serve a cached, access-controlled file. Additive,
        // idempotent (column-exists guarded).
        if ( version_compare( $current_db_version, '3.16', '<' ) ) {
            try {
                $reports_table = $p . 'hdlv2_reports';
                $why_table     = $p . 'hdlv2_why_profiles';
                $add_col = function ( $table, $col, $ddl ) use ( $wpdb ) {
                    $has = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                        $table, $col
                    ) );
                    if ( ! $has ) {
                        $wpdb->query( "ALTER TABLE $table ADD COLUMN $ddl" );
                        if ( $wpdb->last_error ) {
                            error_log( "[HDLV2] Phase X (v3.16) FAILED on $table.$col: " . $wpdb->last_error );
                        } else {
                            error_log( "[HDLV2] Phase X (v3.16) migration: added $col to $table." );
                        }
                    }
                };
                $add_col( $reports_table, 'pdf_stored_path',  'pdf_stored_path VARCHAR(255) DEFAULT NULL AFTER pdf_url' );
                $add_col( $reports_table, 'pdf_generated_at', 'pdf_generated_at DATETIME DEFAULT NULL AFTER pdf_stored_path' );
                $add_col( $why_table,     'pdf_url',          'pdf_url VARCHAR(500) DEFAULT NULL' );
                $add_col( $why_table,     'pdf_stored_path',  'pdf_stored_path VARCHAR(255) DEFAULT NULL' );
                $add_col( $why_table,     'pdf_generated_at', 'pdf_generated_at DATETIME DEFAULT NULL' );
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2] Phase X migration error: ' . $e->getMessage() . ' — boot continues; verify manually.' );
            }
        }

        // Phase Y (DB v3.17) — weekly Flight Plan PDF self-hosting (direct
        // WP→PDFMonkey renderer). Mirrors Phase X's columns on the
        // flight_plans table: stored path + render stamp alongside the
        // existing pdf_url/delivered_at the retired Make callback used.
        // Additive, idempotent (column-exists guarded).
        if ( version_compare( $current_db_version, '3.17', '<' ) ) {
            try {
                $fp_table = $p . 'hdlv2_flight_plans';
                $add_col = function ( $table, $col, $ddl ) use ( $wpdb ) {
                    $has = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                        $table, $col
                    ) );
                    if ( ! $has ) {
                        $wpdb->query( "ALTER TABLE $table ADD COLUMN $ddl" );
                        if ( $wpdb->last_error ) {
                            error_log( "[HDLV2] Phase Y (v3.17) FAILED on $table.$col: " . $wpdb->last_error );
                        } else {
                            error_log( "[HDLV2] Phase Y (v3.17) migration: added $col to $table." );
                        }
                    }
                };
                $add_col( $fp_table, 'pdf_stored_path',  'pdf_stored_path VARCHAR(255) DEFAULT NULL AFTER pdf_url' );
                $add_col( $fp_table, 'pdf_generated_at', 'pdf_generated_at DATETIME DEFAULT NULL AFTER pdf_stored_path' );
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2] Phase Y migration error: ' . $e->getMessage() . ' — boot continues; verify manually.' );
            }
        }

        // Phase Z (DB v3.18) — pre-send milestone editing on the consultation
        // page. staged_milestones holds the practitioner-reviewed four-horizon
        // milestones BEFORE finalise; HDLV2_Final_Report::generate() consumes it
        // verbatim (flag HDLV2_FF_MILESTONE_PREVIEW / option
        // hdlv2_ff_milestone_preview) so the client's first PDF/email already
        // carries the edited text. Additive, idempotent (column-exists guarded).
        if ( version_compare( $current_db_version, '3.18', '<' ) ) {
            try {
                $cn_table = $p . 'hdlv2_consultation_notes';
                $has = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'staged_milestones'",
                    $cn_table
                ) );
                if ( ! $has ) {
                    $wpdb->query( "ALTER TABLE $cn_table ADD COLUMN staged_milestones LONGTEXT DEFAULT NULL AFTER ai_organised_notes" );
                    if ( $wpdb->last_error ) {
                        error_log( '[HDLV2] Phase Z (v3.18) FAILED on ' . $cn_table . '.staged_milestones: ' . $wpdb->last_error );
                    } else {
                        error_log( '[HDLV2] Phase Z (v3.18) migration: added staged_milestones to ' . $cn_table . '.' );
                    }
                }
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2] Phase Z migration error: ' . $e->getMessage() . ' — boot continues; verify manually.' );
            }
        }

        // Phase AA (v3.20) — Iridology native-capture re-key.
        //
        // The receiver/table pivot from a per-attempt job_id to the
        // deterministic capture_id (clientId:consultId:irisSetHash). Adds the
        // capture_id dedupe key (UNIQUE), the finalized draft/final flag, a
        // source tag, captured_at, and the 'draft' status enum value. dbDelta
        // cannot reliably modify an ENUM or add a UNIQUE key, so do it here with
        // information_schema guards (idempotent). The table is empty/dark on
        // STBY, so this is a pure additive schema bump — no data backfill.
        if ( version_compare( $current_db_version, '3.20', '<' ) ) {
            try {
                $iris_table = $p . 'hdlv2_iris_results';
                $table_present = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s", $iris_table ) );
                if ( $table_present ) {
                    // New columns (each column-exists guarded).
                    $add_cols = array(
                        'capture_id'  => "ADD COLUMN capture_id VARCHAR(200) DEFAULT NULL AFTER job_id",
                        'source'      => "ADD COLUMN source VARCHAR(16) NOT NULL DEFAULT 'embedded' AFTER idempotency_key",
                        'finalized'   => "ADD COLUMN finalized TINYINT(1) NOT NULL DEFAULT 0 AFTER status",
                        'captured_at' => "ADD COLUMN captured_at DATETIME DEFAULT NULL AFTER analysed_at",
                    );
                    foreach ( $add_cols as $col => $ddl ) {
                        $has = (int) $wpdb->get_var( $wpdb->prepare(
                            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                            $iris_table, $col ) );
                        if ( ! $has ) {
                            $wpdb->query( "ALTER TABLE $iris_table $ddl" );
                            if ( $wpdb->last_error ) {
                                error_log( "[HDLV2] Phase AA (v3.20) FAILED adding $col to $iris_table: " . $wpdb->last_error );
                            }
                        }
                    }
                    // 'draft' status enum value (column-type guarded).
                    $col_type = (string) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'status'",
                        $iris_table ) );
                    if ( $col_type && strpos( $col_type, "'draft'" ) === false ) {
                        $wpdb->query( "ALTER TABLE $iris_table MODIFY COLUMN status ENUM('queued','running','draft','done','error','limit','unavailable','archived') NOT NULL DEFAULT 'queued'" );
                        if ( $wpdb->last_error ) {
                            error_log( '[HDLV2] Phase AA (v3.20) FAILED adding draft enum: ' . $wpdb->last_error );
                        }
                    }
                    // UNIQUE(capture_id) dedupe key (index-exists guarded).
                    $has_idx = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'uniq_capture'",
                        $iris_table ) );
                    if ( ! $has_idx ) {
                        $wpdb->query( "ALTER TABLE $iris_table ADD UNIQUE KEY uniq_capture (capture_id)" );
                        if ( $wpdb->last_error ) {
                            error_log( '[HDLV2] Phase AA (v3.20) FAILED adding uniq_capture: ' . $wpdb->last_error );
                        }
                    }
                    error_log( '[HDLV2] Phase AA (v3.20) migration: iris native-capture re-key applied to ' . $iris_table . '.' );
                }
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2] Phase AA migration error: ' . $e->getMessage() . ' — boot continues; verify manually.' );
            }
        }

        // Phase AB (DB v3.21) — Stage 1 PDF self-hosting. Mirrors Phase X/Y's
        // pattern (PDFMonkey download URLs expire ~1h, so the stage1-callback
        // downloads + self-hosts the file). These columns on form_progress hold
        // the PDFMonkey source URL, the stored path, and a render stamp so the
        // dashboard serves a cached, access-controlled Stage 1 PDF via
        // ?hdlv2_stage1_pdf=<form_progress_id>. Additive, idempotent
        // (column-exists guarded).
        if ( version_compare( $current_db_version, '3.21', '<' ) ) {
            try {
                $progress_table = $p . 'hdlv2_form_progress';
                $add_col = function ( $table, $col, $ddl ) use ( $wpdb ) {
                    $has = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                        $table, $col
                    ) );
                    if ( ! $has ) {
                        $wpdb->query( "ALTER TABLE $table ADD COLUMN $ddl" );
                        if ( $wpdb->last_error ) {
                            error_log( "[HDLV2] Phase AB (v3.21) FAILED on $table.$col: " . $wpdb->last_error );
                        } else {
                            error_log( "[HDLV2] Phase AB (v3.21) migration: added $col to $table." );
                        }
                    }
                };
                $add_col( $progress_table, 'stage1_pdf_url',          'stage1_pdf_url VARCHAR(500) DEFAULT NULL AFTER stage1_completed_at' );
                $add_col( $progress_table, 'stage1_pdf_stored_path',  'stage1_pdf_stored_path VARCHAR(255) DEFAULT NULL AFTER stage1_pdf_url' );
                $add_col( $progress_table, 'stage1_pdf_generated_at', 'stage1_pdf_generated_at DATETIME DEFAULT NULL AFTER stage1_pdf_stored_path' );
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2] Phase AB migration error: ' . $e->getMessage() . ' — boot continues; verify manually.' );
            }
        }

        // Phase AC (DB v3.22) — backfill form_progress.client_user_id so a
        // client whose wp_user already exists becomes visible in the
        // practitioner main list. Only the widget-Confirm path (complete_signup)
        // ever wrote client_user_id; the direct rest_create_form path left it
        // NULL and nothing healed it, so such clients were permanently invisible
        // (HDLV2_Compatibility roster gates on client_user_id IS NOT NULL).
        // user_email is unique in wp_users so the JOIN is 1:1; fills only NULLs
        // on non-deleted rows — never overwrites an existing link, never touches
        // soft-deleted rows. Pairs with the forward stamp in
        // HDLV2_Staged_Form::complete_stage. Pre-migration STBY audit (2026-06-27):
        // 0 rows with stage3_completed_at would link → triggers no check-in /
        // flight-plan / attention automation.
        if ( version_compare( $current_db_version, '3.22', '<' ) ) {
            try {
                $fp_table = $p . 'hdlv2_form_progress';
                $users    = $wpdb->users;
                $n = $wpdb->query(
                    "UPDATE $fp_table fp
                     JOIN $users u ON u.user_email = fp.client_email
                     SET fp.client_user_id = u.ID
                     WHERE fp.client_user_id IS NULL
                       AND fp.client_email IS NOT NULL
                       AND fp.client_email <> ''
                       AND fp.deleted_at IS NULL"
                );
                if ( $wpdb->last_error ) {
                    error_log( '[HDLV2] Phase AC (v3.22) client_user_id backfill FAILED: ' . $wpdb->last_error );
                } else {
                    error_log( '[HDLV2] Phase AC (v3.22) migration: backfilled client_user_id on ' . (int) $n . ' form_progress row(s).' );
                }
                // B.9 (§7.2/§7.6) — removed the stray add_option('hdlv2_ff_pending_in_list')
                // seed: the pending-leads-in-list feature ships unconditionally and
                // nothing ever read the flag (project-wide grep = 0 readers).
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2] Phase AC migration error: ' . $e->getMessage() . ' — boot continues; verify manually.' );
            }
        }

        // Phase AD (DB v3.23) — Stage 1 PDF capture on the public widget path.
        // The public widget fires the Stage 1 PDF at SUBMIT with a synthetic
        // cache token before any form_progress row exists (created later on
        // Confirm), so the stage1-callback can't resolve form_progress and 404s
        // for public signups. These columns let the callback capture the stored
        // PDF onto the widget_lead at submit; the file is migrated to
        // form_progress on Confirm (same physical file — stored once, never
        // re-fetched). Additive, idempotent (column-exists guarded).
        if ( version_compare( $current_db_version, '3.23', '<' ) ) {
            try {
                $leads_table = $p . 'hdlv2_widget_leads';
                $add_col = function ( $table, $col, $ddl ) use ( $wpdb ) {
                    $has = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                        $table, $col
                    ) );
                    if ( ! $has ) {
                        $wpdb->query( "ALTER TABLE $table ADD COLUMN $ddl" );
                        if ( $wpdb->last_error ) {
                            error_log( "[HDLV2] Phase AD (v3.23) FAILED on $table.$col: " . $wpdb->last_error );
                        } else {
                            error_log( "[HDLV2] Phase AD (v3.23) migration: added $col to $table." );
                        }
                    }
                };
                $add_col( $leads_table, 'stage1_pdf_stored_path',  'stage1_pdf_stored_path VARCHAR(255) DEFAULT NULL AFTER rejected_at' );
                $add_col( $leads_table, 'stage1_pdf_generated_at', 'stage1_pdf_generated_at DATETIME DEFAULT NULL AFTER stage1_pdf_stored_path' );
                $add_col( $leads_table, 'stage1_cache_token',      'stage1_cache_token VARCHAR(64) DEFAULT NULL AFTER stage1_pdf_generated_at' );
            } catch ( \Throwable $e ) {
                error_log( '[HDLV2] Phase AD migration error: ' . $e->getMessage() . ' — boot continues; verify manually.' );
            }
        }
    }

    /**
     * Classify a logo's aspect ratio into one of three render modes.
     *
     * Same thresholds as the .hdlw-logo[data-shape] CSS:
     *   - square   ratio in [0.71, 1.40]  → white 36–42px circle
     *   - wordmark ratio > 1.40           → letterbox pill, 28–32px tall
     *   - tall     ratio < 0.71           → portrait pill, 36–42px tall
     *
     * Public so ajax_upload_logo() and the migration backfill share one
     * source of truth.
     */
    public static function classify_logo_shape( $width, $height ) {
        $width  = (int) $width;
        $height = (int) $height;
        if ( $width <= 0 || $height <= 0 ) {
            return 'square';
        }
        $ratio = $width / $height;
        if ( $ratio > 1.40 ) {
            return 'wordmark';
        }
        if ( $ratio < 0.71 ) {
            return 'tall';
        }
        return 'square';
    }

    private static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Sprint 1: Widget configuration per practitioner
        // logo_shape (DB v3.7) — server-detected at upload via getimagesize().
        // Drives the shape-aware .hdlw-logo / .prac-logo CSS that renders
        // square logos as circles, wide logos as letterbox pills, tall logos
        // as portrait pills. Same logo_url stamps the right shape on every
        // surface (widget, email, PDF) without re-detecting on each render.
        $table_widget = $wpdb->prefix . 'hdlv2_widget_config';
        $sql_widget = "CREATE TABLE $table_widget (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            practitioner_user_id BIGINT(20) UNSIGNED NOT NULL,
            practitioner_name VARCHAR(200) DEFAULT '',
            clinic_name VARCHAR(200) DEFAULT '',
            logo_url VARCHAR(500) DEFAULT '',
            logo_shape ENUM('square','wordmark','tall') DEFAULT 'square',
            cta_text VARCHAR(300) DEFAULT 'Book a session',
            cta_link VARCHAR(500) DEFAULT '',
            webhook_url VARCHAR(500) DEFAULT '',
            notification_email VARCHAR(200) DEFAULT '',
            theme_color VARCHAR(7) DEFAULT '#3d8da0',
            show_book_button_after_widget TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY practitioner_user_id (practitioner_user_id)
        ) $charset_collate;";

        // Sprint 1: Lead captures (tracks widget submissions)
        // v0.29.0 (DB v3.2) — added stage1_data JSON so widget_leads is the
        // canonical permanent home for widget answers. Public-path submissions
        // write here every time (no longer behind verification). Used to
        // pre-fill the 9 answers on a future Send-Invites link for the same
        // (practitioner_user_id, visitor_email) pair.
        $table_leads = $wpdb->prefix . 'hdlv2_widget_leads';
        $sql_leads = "CREATE TABLE $table_leads (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            practitioner_user_id BIGINT(20) UNSIGNED NOT NULL,
            visitor_name VARCHAR(200) DEFAULT '',
            visitor_email VARCHAR(200) DEFAULT '',
            visitor_age INT DEFAULT NULL,
            rate_of_ageing DECIMAL(4,2) DEFAULT NULL,
            stage1_data JSON DEFAULT NULL,
            invite_id BIGINT(20) UNSIGNED DEFAULT NULL,
            status ENUM('pending','confirmed','rejected') DEFAULT 'pending',
            confirmed_at DATETIME DEFAULT NULL,
            rejected_at DATETIME DEFAULT NULL,
            stage1_pdf_stored_path VARCHAR(255) DEFAULT NULL,
            stage1_pdf_generated_at DATETIME DEFAULT NULL,
            stage1_cache_token VARCHAR(64) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY practitioner_user_id (practitioner_user_id),
            KEY practitioner_email (practitioner_user_id, visitor_email),
            KEY practitioner_status (practitioner_user_id, status, created_at)
        ) $charset_collate;";

        // Sprint 1: Widget invite tokens (direct links to specific clients)
        // v0.29.0 (DB v3.2) — added prefill_stage1 JSON. Snapshotted from the
        // matching widget_leads row at invite-creation time so the invite link
        // is stable: the visitor opens it later and their prior answers are
        // pre-selected (still editable) before they continue to Stage 2.
        $table_invites = $wpdb->prefix . 'hdlv2_widget_invites';
        $sql_invites = "CREATE TABLE $table_invites (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            practitioner_id BIGINT(20) UNSIGNED NOT NULL,
            token VARCHAR(64) NOT NULL,
            client_name VARCHAR(200) DEFAULT '',
            client_email VARCHAR(200) NOT NULL,
            status ENUM('pending','opened','completed','expired','revoked') DEFAULT 'pending',
            expires_at DATETIME NOT NULL,
            opened_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            prefill_stage1 JSON DEFAULT NULL,
            source ENUM('practitioner','automation') NOT NULL DEFAULT 'practitioner',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY practitioner_status (practitioner_id, status)
        ) $charset_collate;";

        // Sprint 2: Staged form progress (one row per client, JSON per stage)
        $table_progress = $wpdb->prefix . 'hdlv2_form_progress';
        $sql_progress = "CREATE TABLE $table_progress (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            practitioner_user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            client_name VARCHAR(200) DEFAULT '',
            client_email VARCHAR(200) DEFAULT '',
            widget_invite_id BIGINT(20) UNSIGNED DEFAULT NULL,
            token VARCHAR(64) NOT NULL,
            current_stage TINYINT(1) UNSIGNED DEFAULT 1,
            token_expires_at DATETIME DEFAULT NULL,
            stage1_data JSON DEFAULT NULL,
            stage1_completed_at DATETIME DEFAULT NULL,
            stage1_pdf_url VARCHAR(500) DEFAULT NULL,
            stage1_pdf_stored_path VARCHAR(255) DEFAULT NULL,
            stage1_pdf_generated_at DATETIME DEFAULT NULL,
            stage2_data JSON DEFAULT NULL,
            stage2_completed_at DATETIME DEFAULT NULL,
            stage2_webhook_fired_at DATETIME DEFAULT NULL,
            stage2_text_hash VARCHAR(32) DEFAULT NULL,
            stage3_data JSON DEFAULT NULL,
            stage3_completed_at DATETIME DEFAULT NULL,
            pre_consult_summary LONGTEXT DEFAULT NULL,
            pre_consult_summary_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME DEFAULT NULL,
            deleted_by BIGINT(20) UNSIGNED DEFAULT NULL,
            has_flags BOOLEAN DEFAULT FALSE,
            flags JSON DEFAULT NULL,
            flags_scanned_at DATETIME DEFAULT NULL,
            flags_scan_status VARCHAR(20) DEFAULT NULL,
            source ENUM('practitioner','automation') NOT NULL DEFAULT 'practitioner',
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY client_user_id (client_user_id),
            KEY practitioner_user_id (practitioner_user_id),
            KEY idx_deleted_at (deleted_at)
        ) $charset_collate;";

        // Sprint 2: WHY profiles (AI-extracted motivational profile)
        $table_why = $wpdb->prefix . 'hdlv2_why_profiles';
        $sql_why = "CREATE TABLE $table_why (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            form_progress_id BIGINT(20) UNSIGNED NOT NULL,
            key_people JSON DEFAULT NULL,
            motivations JSON DEFAULT NULL,
            fears JSON DEFAULT NULL,
            vision_text LONGTEXT DEFAULT NULL,
            distilled_why TEXT DEFAULT NULL,
            ai_reformulation LONGTEXT DEFAULT NULL,
            raw_input LONGTEXT DEFAULT NULL,
            audio_id BIGINT(20) UNSIGNED DEFAULT NULL,
            released BOOLEAN DEFAULT FALSE,
            released_at DATETIME DEFAULT NULL,
            released_by_practitioner_id BIGINT(20) UNSIGNED DEFAULT NULL,
            last_resent_at DATETIME DEFAULT NULL,
            resend_count INT UNSIGNED DEFAULT 0,
            pdf_url VARCHAR(500) DEFAULT NULL,
            pdf_stored_path VARCHAR(255) DEFAULT NULL,
            pdf_generated_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_progress_id (form_progress_id)
        ) $charset_collate;";

        // Sprint 2: Reports (draft, final, quarterly)
        $table_reports = $wpdb->prefix . 'hdlv2_reports';
        $sql_reports = "CREATE TABLE $table_reports (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            practitioner_user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            form_progress_id BIGINT(20) UNSIGNED NOT NULL,
            report_type ENUM('draft','final','quarterly') NOT NULL,
            report_content LONGTEXT DEFAULT NULL,
            milestones JSON DEFAULT NULL,
            rate_snapshot DECIMAL(4,2) DEFAULT NULL,
            consultation_notes LONGTEXT DEFAULT NULL,
            health_data_changes JSON DEFAULT NULL,
            pdf_url VARCHAR(500) DEFAULT NULL,
            pdf_stored_path VARCHAR(255) DEFAULT NULL,
            pdf_generated_at DATETIME DEFAULT NULL,
            status ENUM('generating','ready','error') DEFAULT 'generating',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_progress_report (form_progress_id, report_type),
            KEY client_report (client_user_id, report_type)
        ) $charset_collate;";

        // Sprint 2C: Consultation notes (practitioner consultation workspace)
        // 2026-04-21 (DB v2.3): added raw_notes / raw_audio_url / ai_organised_notes
        // / practitioner_approved / approved_at to support the new free-form +
        // AI-organise flow. The legacy `recommendations` and `ai_digest` columns
        // are kept (dbDelta won't drop them) but new code stops writing to them.
        $table_consult = $wpdb->prefix . 'hdlv2_consultation_notes';
        $sql_consult = "CREATE TABLE $table_consult (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_user_id BIGINT(20) UNSIGNED NOT NULL,
            practitioner_user_id BIGINT(20) UNSIGNED NOT NULL,
            form_progress_id BIGINT(20) UNSIGNED NOT NULL,
            report_id BIGINT(20) UNSIGNED DEFAULT NULL,
            audio_id BIGINT(20) UNSIGNED DEFAULT NULL,
            typed_notes LONGTEXT DEFAULT NULL,
            ai_digest LONGTEXT DEFAULT NULL,
            recommendations JSON DEFAULT NULL,
            health_data_changes JSON DEFAULT NULL,
            raw_notes LONGTEXT DEFAULT NULL,
            raw_audio_url VARCHAR(500) DEFAULT NULL,
            ai_organised_notes LONGTEXT DEFAULT NULL,
            staged_milestones LONGTEXT DEFAULT NULL,
            practitioner_approved TINYINT(1) DEFAULT 0,
            approved_at DATETIME DEFAULT NULL,
            started_at DATETIME DEFAULT NULL,
            ended_at DATETIME DEFAULT NULL,
            status ENUM('in_progress','completed','report_generated') DEFAULT 'in_progress',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_progress_id (form_progress_id),
            KEY client_practitioner (client_user_id, practitioner_user_id)
        ) $charset_collate;";

        // Sprint 4: Weekly check-ins
        $table_checkins = $wpdb->prefix . 'hdlv2_checkins';
        $sql_checkins = "CREATE TABLE $table_checkins (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT(20) UNSIGNED NOT NULL,
            practitioner_id BIGINT(20) UNSIGNED NOT NULL,
            week_number INT UNSIGNED NOT NULL,
            week_start DATE NOT NULL,
            raw_input LONGTEXT DEFAULT NULL,
            raw_transcript LONGTEXT DEFAULT NULL,
            audio_id BIGINT(20) UNSIGNED DEFAULT NULL,
            input_method VARCHAR(10) DEFAULT 'text',
            summary JSON DEFAULT NULL,
            adherence_scores JSON DEFAULT NULL,
            comfort_zone VARCHAR(20) DEFAULT 'about_right',
            has_flags BOOLEAN DEFAULT FALSE,
            flags JSON DEFAULT NULL,
            status ENUM('draft','confirmed') DEFAULT 'draft',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            confirmed_at DATETIME DEFAULT NULL,
            deleted_at DATETIME DEFAULT NULL,
            deleted_by BIGINT(20) UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_client_week (client_id, week_number),
            KEY idx_practitioner (practitioner_id),
            KEY idx_flags (has_flags),
            KEY idx_deleted_at (deleted_at)
        ) $charset_collate;";

        // Sprint 4: Client timeline
        $table_timeline = $wpdb->prefix . 'hdlv2_timeline';
        $sql_timeline = "CREATE TABLE $table_timeline (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT(20) UNSIGNED NOT NULL,
            practitioner_id BIGINT(20) UNSIGNED NOT NULL,
            entry_type VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            date DATETIME DEFAULT NULL,
            temporal_type ENUM('instant','interval') DEFAULT 'instant',
            end_date DATETIME DEFAULT NULL,
            summary TEXT DEFAULT NULL,
            category VARCHAR(50) DEFAULT 'system',
            severity TINYINT DEFAULT NULL,
            source VARCHAR(50) DEFAULT 'system',
            detail JSON DEFAULT NULL,
            linked_entries JSON DEFAULT NULL,
            subjective_hypothesis TEXT DEFAULT NULL,
            attachments JSON DEFAULT NULL,
            metadata JSON DEFAULT NULL,
            source_table VARCHAR(100) DEFAULT NULL,
            source_id BIGINT(20) UNSIGNED DEFAULT NULL,
            has_flags BOOLEAN DEFAULT FALSE,
            flags JSON DEFAULT NULL,
            is_private BOOLEAN DEFAULT FALSE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME DEFAULT NULL,
            deleted_by BIGINT(20) UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_client_date (client_id, created_at),
            KEY idx_practitioner (practitioner_id),
            KEY idx_type (entry_type),
            KEY idx_flags (has_flags),
            KEY idx_client_category_date (client_id, category, date),
            KEY idx_deleted_at (deleted_at)
        ) $charset_collate;";

        // Sprint 5: Flight plans
        $table_fp = $wpdb->prefix . 'hdlv2_flight_plans';
        $sql_fp = "CREATE TABLE $table_fp (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT(20) UNSIGNED NOT NULL,
            practitioner_id BIGINT(20) UNSIGNED NOT NULL,
            week_number INT UNSIGNED NOT NULL,
            week_start DATE NOT NULL,
            date_range_end DATE DEFAULT NULL,
            effective_start_day VARCHAR(10) NOT NULL DEFAULT 'monday',
            plan_data JSON NOT NULL,
            shopping_list JSON DEFAULT NULL,
            identity_statement TEXT DEFAULT NULL,
            weekly_targets JSON DEFAULT NULL,
            journey_assistance TEXT DEFAULT NULL,
            pdf_url VARCHAR(500) DEFAULT NULL,
            pdf_stored_path VARCHAR(255) DEFAULT NULL,
            pdf_generated_at DATETIME DEFAULT NULL,
            adherence_summary JSON DEFAULT NULL,
            status ENUM('generated','delivered','active','completed','archived') DEFAULT 'generated',
            generation_trigger VARCHAR(20) DEFAULT 'auto',
            practitioner_notes TEXT DEFAULT NULL,
            priority_notes_used LONGTEXT DEFAULT NULL,
            ai_model VARCHAR(50) DEFAULT NULL,
            token_usage JSON DEFAULT NULL,
            generation_attempts TINYINT UNSIGNED DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            delivered_at DATETIME DEFAULT NULL,
            deleted_at DATETIME DEFAULT NULL,
            deleted_by BIGINT(20) UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_client_week (client_id, week_number),
            KEY idx_practitioner (practitioner_id),
            KEY idx_deleted_at (deleted_at)
        ) $charset_collate;";

        // Sprint 5: Flight plan tick tracking
        $table_ticks = $wpdb->prefix . 'hdlv2_flight_plan_ticks';
        $sql_ticks = "CREATE TABLE $table_ticks (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            flight_plan_id BIGINT(20) UNSIGNED NOT NULL,
            client_id BIGINT(20) UNSIGNED NOT NULL,
            day VARCHAR(10) NOT NULL,
            time_slot VARCHAR(30) NOT NULL,
            category ENUM('movement','nutrition','key_action') NOT NULL,
            action_text VARCHAR(500) NOT NULL DEFAULT '',
            action_index INT UNSIGNED DEFAULT 0,
            ticked BOOLEAN DEFAULT FALSE,
            ticked_at DATETIME DEFAULT NULL,
            deleted_at DATETIME DEFAULT NULL,
            deleted_by BIGINT(20) UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_plan (flight_plan_id),
            KEY idx_client (client_id),
            KEY idx_deleted_at (deleted_at)
        ) $charset_collate;";

        // Sprint 1 (verification flow): Pending leads — submissions awaiting email confirmation.
        // No real WP user is created until the visitor clicks the verification link.
        // Cleaned up daily via hdlv2_pending_leads_cleanup cron.
        $table_pending = $wpdb->prefix . 'hdlv2_pending_leads';
        $sql_pending = "CREATE TABLE $table_pending (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            practitioner_id BIGINT(20) UNSIGNED NOT NULL,
            visitor_name VARCHAR(200) DEFAULT '',
            visitor_email VARCHAR(200) NOT NULL,
            visitor_phone VARCHAR(50) DEFAULT '',
            stage1_data JSON DEFAULT NULL,
            rate_of_ageing DECIMAL(4,2) DEFAULT NULL,
            verify_token VARCHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            ip_address VARCHAR(45) DEFAULT '',
            user_agent VARCHAR(500) DEFAULT '',
            reminder_sent_at DATETIME DEFAULT NULL,
            verified_at DATETIME DEFAULT NULL,
            rejected_at DATETIME DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY verify_token (verify_token),
            KEY practitioner_email (practitioner_id, visitor_email),
            KEY status_expires (status, expires_at),
            KEY created_at (created_at)
        ) $charset_collate;";

        // Phase 6: Monthly summaries
        $table_monthly = $wpdb->prefix . 'hdlv2_monthly_summaries';
        $sql_monthly = "CREATE TABLE $table_monthly (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT(20) UNSIGNED NOT NULL,
            practitioner_id BIGINT(20) UNSIGNED NOT NULL,
            month_start DATE NOT NULL,
            month_end DATE NOT NULL,
            summary TEXT NOT NULL,
            checkin_ids JSON DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME DEFAULT NULL,
            deleted_by BIGINT(20) UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_client (client_id, month_start),
            KEY idx_deleted_at (deleted_at)
        ) $charset_collate;";

        // v0.17.0 (DB v2.5) — Background job queue.
        // Universal async work table. Used by transcribe_audio,
        // organise_consultation, and future heavy jobs (draft reports,
        // final reports, check-in extraction) to keep HTTP requests fast
        // and insulate external API failures from end users.
        $table_jobs = $wpdb->prefix . 'hdlv2_jobs';
        $sql_jobs = "CREATE TABLE $table_jobs (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            job_type VARCHAR(64) NOT NULL,
            reference_id BIGINT(20) UNSIGNED DEFAULT NULL,
            idem_key VARCHAR(191) NOT NULL,
            payload LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts INT UNSIGNED NOT NULL DEFAULT 4,
            priority INT NOT NULL DEFAULT 100,
            result LONGTEXT DEFAULT NULL,
            error TEXT DEFAULT NULL,
            available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_type_idem (job_type, idem_key),
            KEY idx_status_available (status, available_at),
            KEY idx_type_reference (job_type, reference_id)
        ) $charset_collate;";

        // v0.19.0 (DB v2.6) — Audio extractions (raw transcripts + structured output).
        // Addresses the compression-loss problem: previously the 10-min audio
        // → 1-sentence distilled_why flow discarded 80%+ of client content.
        // Now we persist the full Deepgram transcript AND the full Claude
        // structured extraction for 90 days, so the client's actual words
        // survive and can be audited / re-processed if prompts change.
        $table_audio_extractions = $wpdb->prefix . 'hdlv2_audio_extractions';
        $sql_audio_extractions = "CREATE TABLE $table_audio_extractions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            form_progress_id BIGINT(20) UNSIGNED DEFAULT NULL,
            reference_id BIGINT(20) UNSIGNED DEFAULT NULL,
            context_type VARCHAR(32) NOT NULL,
            input_method VARCHAR(16) DEFAULT 'audio',
            raw_transcript LONGTEXT DEFAULT NULL,
            structured_json LONGTEXT DEFAULT NULL,
            model VARCHAR(64) DEFAULT NULL,
            transcript_chars INT UNSIGNED DEFAULT 0,
            structured_chars INT UNSIGNED DEFAULT 0,
            confirmed_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_form_progress (form_progress_id, context_type),
            KEY idx_client_context (client_user_id, context_type),
            KEY idx_created (created_at)
        ) $charset_collate;";

        // v0.28.0 (DB v3.1) — Consultation Addenda. Practitioner-appended,
        // timestamped clinical observations between the original consultation
        // and the next quarterly Part. Replaces the deleted "Reset & start
        // fresh" pattern (Matthew transcript 2026-04-30: "never change past
        // information, you can only add to it"). Each Addendum is read by
        // Claude during regenerate_with_addenda() to re-issue the awaken/
        // lift/thrive narrative + recommendations + milestones + Flight Plan.
        // History is never deleted; superseded_by_report_id ties an Addendum
        // to the Final Report version that incorporated it (audit trail).
        $table_addenda = $wpdb->prefix . 'hdlv2_consultation_addenda';
        $sql_addenda = "CREATE TABLE $table_addenda (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            consultation_id BIGINT(20) UNSIGNED NOT NULL,
            practitioner_user_id BIGINT(20) UNSIGNED NOT NULL,
            client_user_id BIGINT(20) UNSIGNED NOT NULL,
            form_progress_id BIGINT(20) UNSIGNED NOT NULL,
            note_text LONGTEXT NOT NULL,
            occurred_at DATETIME NOT NULL,
            priority ENUM('low','medium','high') DEFAULT 'medium',
            source ENUM('typed','voice') DEFAULT 'typed',
            audio_extraction_id BIGINT(20) UNSIGNED DEFAULT NULL,
            superseded_by_report_id BIGINT(20) UNSIGNED DEFAULT NULL,
            submitter ENUM('practitioner','client_automation') NOT NULL DEFAULT 'practitioner',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_consult_occurred (consultation_id, occurred_at),
            KEY idx_client_created (client_user_id, created_at),
            KEY idx_progress (form_progress_id),
            KEY idx_superseded (superseded_by_report_id)
        ) $charset_collate;";

        // DB v3.13 — Automation tier tokens. Paid-flow magic-link tokens
        // issued by /paid-report-provision (W4) when Altituding's Stripe
        // webhook fires for a completed automation-tier checkout. The
        // token is the bearer credential the client uses to land on
        // /assessment/?invite=<TOKEN>. status tracks lifecycle
        // (issued → started → completed | revoked). stripe_session_id
        // provides idempotency for retried webhook calls. Defaults preserve
        // existing-row behaviour (no existing rows on first migration).
        $table_tokens = $wpdb->prefix . 'hdlv2_automation_tokens';
        $sql_tokens = "CREATE TABLE $table_tokens (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            token VARCHAR(64) NOT NULL,
            client_email VARCHAR(255) NOT NULL,
            client_name VARCHAR(255) DEFAULT NULL,
            programme VARCHAR(64) DEFAULT NULL,
            tier ENUM('automation','practitioner') NOT NULL DEFAULT 'practitioner',
            stripe_session_id VARCHAR(128) DEFAULT NULL,
            status ENUM('issued','started','completed','revoked') NOT NULL DEFAULT 'issued',
            issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            revoked_at DATETIME DEFAULT NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'altituding_paid',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_token (token),
            KEY idx_client_email (client_email),
            KEY idx_stripe_session (stripe_session_id),
            KEY idx_status (status)
        ) $charset_collate;";

        // ── Iridology Phase 2 (embedded consult + native-capture) — DB v3.20 ──
        // Dark behind hdlv2_ff_iris_consult. The heavy clinical record
        // (NEVER-DELETE): structured AI result + practitioner-edited overlay +
        // private-dir image refs (never image bytes in the row).
        //
        // Two delivery models share this table:
        //  • EMBEDDED (legacy, dormant): HDL mints job_id; UNIQUE(job_id) is the
        //    idempotent callback's UPSERT key. source='embedded', capture_id NULL.
        //  • NATIVE-CAPTURE (Phase-2 pivot): IrisMapper runs the analysis and
        //    PUSHES a result HDL never minted → INSERT-ON-CALLBACK. HDL keys its
        //    row + dedupe on the DETERMINISTIC capture_id (clientId:consultId:
        //    irisSetHash[:vN]); the auto safety-net DRAFT (finalized=0,
        //    status='draft') and the "Send to HealthDataLab" FINAL (finalized=1,
        //    status='done') collapse to ONE row (terminal-wins). source='native'.
        //    job_id is set = capture_id so the NOT-NULL column + the browser
        //    routes (which key on job_id) keep working unchanged.
        //
        // A NEW capture_id (genuine re-shoot / explicit :vN) is a NEW row and
        // archives the prior (status); the SAME capture_id is an idempotent
        // in-place UPSERT. Covering KEY (job_id,status) keeps the poll an
        // index-only read that never touches the LONGTEXT (a separate hot table
        // is deferred — over-engineering at single-practitioner scale).
        $table_iris_results = $wpdb->prefix . 'hdlv2_iris_results';
        $sql_iris_results = "CREATE TABLE $table_iris_results (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_user_id BIGINT(20) UNSIGNED NOT NULL,
            practitioner_user_id BIGINT(20) UNSIGNED NOT NULL,
            form_progress_id BIGINT(20) UNSIGNED NOT NULL,
            job_id VARCHAR(200) NOT NULL,
            capture_id VARCHAR(200) DEFAULT NULL,
            idempotency_key VARCHAR(200) NOT NULL,
            source VARCHAR(16) NOT NULL DEFAULT 'embedded',
            status ENUM('queued','running','draft','done','error','limit','unavailable','archived') NOT NULL DEFAULT 'queued',
            finalized TINYINT(1) NOT NULL DEFAULT 0,
            map_name VARCHAR(64) DEFAULT NULL,
            eyes_label VARCHAR(32) DEFAULT NULL,
            supabase_key_l VARCHAR(255) DEFAULT NULL,
            supabase_key_r VARCHAR(255) DEFAULT NULL,
            image_l_path VARCHAR(255) DEFAULT NULL,
            image_r_path VARCHAR(255) DEFAULT NULL,
            result_json LONGTEXT DEFAULT NULL,
            areas_edited_json LONGTEXT DEFAULT NULL,
            triangulated_overview LONGTEXT DEFAULT NULL,
            include_in_pdf TINYINT(1) NOT NULL DEFAULT 0,
            refused TINYINT(1) NOT NULL DEFAULT 0,
            error_message VARCHAR(500) DEFAULT NULL,
            cost DECIMAL(10,4) DEFAULT NULL,
            _revisions LONGTEXT DEFAULT NULL,
            analysed_at DATETIME DEFAULT NULL,
            captured_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_job (job_id),
            UNIQUE KEY uniq_capture (capture_id),
            KEY job_status (job_id, status),
            KEY client_status (client_user_id, status),
            KEY progress (form_progress_id)
        ) $charset_collate;";

        // Circuit-breaker state — a SHARED, atomic, single-row store so the trip
        // holds across all OLS LSAPI workers (a WP transient under no object
        // cache is DB-backed and would also be shared, but a dedicated row gives
        // true atomic UPDATE…WHERE compare-and-set with no autoload ambiguity).
        // MySQL is sufficient at current scale — NO Redis needed (the IrisMapper
        // side needed none either); revisit Redis at 20-30 concurrent (Decision 5).
        $table_iris_breaker = $wpdb->prefix . 'hdlv2_iris_breaker';
        $sql_iris_breaker = "CREATE TABLE $table_iris_breaker (
            id TINYINT(3) UNSIGNED NOT NULL,
            state VARCHAR(16) NOT NULL DEFAULT 'closed',
            failures INT(10) UNSIGNED NOT NULL DEFAULT 0,
            opened_at BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_widget );
        dbDelta( $sql_leads );
        dbDelta( $sql_invites );
        dbDelta( $sql_progress );
        dbDelta( $sql_why );
        dbDelta( $sql_reports );
        dbDelta( $sql_consult );
        dbDelta( $sql_checkins );
        dbDelta( $sql_timeline );
        dbDelta( $sql_fp );
        dbDelta( $sql_ticks );
        dbDelta( $sql_monthly );
        dbDelta( $sql_pending );
        dbDelta( $sql_jobs );
        dbDelta( $sql_audio_extractions );
        dbDelta( $sql_addenda );
        dbDelta( $sql_tokens );
        dbDelta( $sql_iris_results );
        dbDelta( $sql_iris_breaker );
    }
}
