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

    public static function activate() {
        self::create_tables();
        self::schedule_crons();
        update_option( 'hdlv2_db_version', HDLV2_DB_VERSION );
        update_option( 'hdlv2_version', HDLV2_VERSION );
    }

    private static function schedule_crons() {
        $crons = array(
            'hdlv2_audio_cleanup'      => 'daily',
            'hdlv2_checkin_reminder'   => 'daily',
            'hdlv2_weekly_flight_plan' => 'daily',
            'hdlv2_monthly_summary'    => 'daily',
            'hdlv2_quarterly_review'   => 'daily',
        );
        foreach ( $crons as $hook => $recurrence ) {
            if ( ! wp_next_scheduled( $hook ) ) {
                wp_schedule_event( time(), $recurrence, $hook );
            }
        }
    }

    private static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Sprint 1: Widget configuration per practitioner
        $table_widget = $wpdb->prefix . 'hdlv2_widget_config';
        $sql_widget = "CREATE TABLE $table_widget (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            practitioner_user_id BIGINT(20) UNSIGNED NOT NULL,
            practitioner_name VARCHAR(200) DEFAULT '',
            logo_url VARCHAR(500) DEFAULT '',
            cta_text VARCHAR(300) DEFAULT 'Book a session',
            cta_link VARCHAR(500) DEFAULT '',
            webhook_url VARCHAR(500) DEFAULT '',
            notification_email VARCHAR(200) DEFAULT '',
            theme_color VARCHAR(7) DEFAULT '#3d8da0',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY practitioner_user_id (practitioner_user_id)
        ) $charset_collate;";

        // Sprint 1: Lead captures (tracks widget submissions)
        $table_leads = $wpdb->prefix . 'hdlv2_widget_leads';
        $sql_leads = "CREATE TABLE $table_leads (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            practitioner_user_id BIGINT(20) UNSIGNED NOT NULL,
            visitor_name VARCHAR(200) DEFAULT '',
            visitor_email VARCHAR(200) DEFAULT '',
            visitor_age INT DEFAULT NULL,
            rate_of_ageing DECIMAL(4,2) DEFAULT NULL,
            invite_id BIGINT(20) UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY practitioner_user_id (practitioner_user_id)
        ) $charset_collate;";

        // Sprint 1: Widget invite tokens (direct links to specific clients)
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
            stage1_data JSON DEFAULT NULL,
            stage1_completed_at DATETIME DEFAULT NULL,
            stage2_data JSON DEFAULT NULL,
            stage2_completed_at DATETIME DEFAULT NULL,
            stage3_data JSON DEFAULT NULL,
            stage3_completed_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY client_user_id (client_user_id),
            KEY practitioner_user_id (practitioner_user_id)
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
            consultation_notes LONGTEXT DEFAULT NULL,
            health_data_changes JSON DEFAULT NULL,
            pdf_url VARCHAR(500) DEFAULT NULL,
            status ENUM('generating','ready','error') DEFAULT 'generating',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY client_report (client_user_id, report_type),
            KEY form_progress_id (form_progress_id)
        ) $charset_collate;";

        // Sprint 2C: Consultation notes (practitioner consultation workspace)
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
            raw_input TEXT DEFAULT NULL,
            raw_transcript TEXT DEFAULT NULL,
            audio_id BIGINT(20) UNSIGNED DEFAULT NULL,
            summary JSON DEFAULT NULL,
            adherence_scores JSON DEFAULT NULL,
            comfort_zone VARCHAR(20) DEFAULT 'about_right',
            has_flags BOOLEAN DEFAULT FALSE,
            flags JSON DEFAULT NULL,
            status ENUM('draft','confirmed') DEFAULT 'draft',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            confirmed_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_client_week (client_id, week_number),
            KEY idx_practitioner (practitioner_id),
            KEY idx_flags (has_flags)
        ) $charset_collate;";

        // Sprint 4: Client timeline
        $table_timeline = $wpdb->prefix . 'hdlv2_timeline';
        $sql_timeline = "CREATE TABLE $table_timeline (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT(20) UNSIGNED NOT NULL,
            practitioner_id BIGINT(20) UNSIGNED NOT NULL,
            entry_type ENUM('milestone','document','note','checkin','flight_plan','chat','metric') NOT NULL,
            title VARCHAR(255) NOT NULL,
            summary TEXT DEFAULT NULL,
            detail JSON DEFAULT NULL,
            source_table VARCHAR(100) DEFAULT NULL,
            source_id BIGINT(20) UNSIGNED DEFAULT NULL,
            has_flags BOOLEAN DEFAULT FALSE,
            is_private BOOLEAN DEFAULT FALSE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_client_date (client_id, created_at),
            KEY idx_practitioner (practitioner_id),
            KEY idx_type (entry_type),
            KEY idx_flags (has_flags)
        ) $charset_collate;";

        // Sprint 5: Flight plans
        $table_fp = $wpdb->prefix . 'hdlv2_flight_plans';
        $sql_fp = "CREATE TABLE $table_fp (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT(20) UNSIGNED NOT NULL,
            practitioner_id BIGINT(20) UNSIGNED NOT NULL,
            week_number INT UNSIGNED NOT NULL,
            week_start DATE NOT NULL,
            plan_data JSON NOT NULL,
            shopping_list JSON DEFAULT NULL,
            identity_statement TEXT DEFAULT NULL,
            weekly_targets JSON DEFAULT NULL,
            journey_assistance TEXT DEFAULT NULL,
            pdf_url VARCHAR(500) DEFAULT NULL,
            status ENUM('generated','delivered','active','completed') DEFAULT 'generated',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            delivered_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_client_week (client_id, week_number),
            KEY idx_practitioner (practitioner_id)
        ) $charset_collate;";

        // Sprint 5: Flight plan tick tracking
        $table_ticks = $wpdb->prefix . 'hdlv2_flight_plan_ticks';
        $sql_ticks = "CREATE TABLE $table_ticks (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            flight_plan_id BIGINT(20) UNSIGNED NOT NULL,
            client_id BIGINT(20) UNSIGNED NOT NULL,
            day_of_week TINYINT UNSIGNED NOT NULL,
            time_slot VARCHAR(30) NOT NULL,
            category ENUM('movement','nutrition','key_action') NOT NULL,
            action_text VARCHAR(500) NOT NULL DEFAULT '',
            ticked BOOLEAN DEFAULT FALSE,
            ticked_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_plan (flight_plan_id),
            KEY idx_client (client_id)
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
            PRIMARY KEY (id),
            KEY idx_client (client_id, month_start)
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
    }
}
