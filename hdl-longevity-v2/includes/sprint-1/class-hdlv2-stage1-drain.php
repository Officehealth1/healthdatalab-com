<?php
/**
 * Stage 1 under load (v0.48.16) — the busy gate, the PDF drain and the
 * volume alert.
 *
 * The lead route only writes the row and replies. This drain runs every
 * minute from WP-Cron, claims up to N leads whose Stage 1 PDF has not been
 * sent (stage1_fired_at IS NULL), and sends each one exactly as the request
 * used to (gauge PNG + Make.com). One UPDATE per row claims it, so two
 * overlapping ticks can never send the same lead twice.
 *
 * Knobs (options, read through knob()):
 *   hdlv2_stage1_drain_per_minute  20   0 pauses the drain
 *   hdlv2_stage1_busy_load         6    1-minute load average; 0 = gate off
 *   hdlv2_stage1_alert_threshold   500  leads in the last hour
 *   hdlv2_stage1_alert_to          admin_email (comma-separated)
 *
 * @package HDL_Longevity_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Stage1_Drain {

    const DEFAULTS = array(
        'drain_per_minute' => 20,
        'busy_load'        => 6,
        'alert_threshold'  => 500,
    );

    /** Where the load average comes from. A test swaps it; nothing else does. */
    public static $load_source = 'sys_getloadavg';

    public static function register_hooks() {
        add_action( 'hdlv2_stage1_drain', array( __CLASS__, 'run_drain' ) );
        add_action( 'hdlv2_stage1_alert', array( __CLASS__, 'run_alert' ) );
    }

    public static function knob( $name ) {
        return get_option( 'hdlv2_stage1_' . $name, self::DEFAULTS[ $name ] );
    }

    /** True when the 1-minute load average is at or above the knob. Fails open. */
    public static function is_busy() {
        $limit = (float) self::knob( 'busy_load' );
        if ( $limit <= 0 || ! is_callable( self::$load_source ) ) {
            return false;
        }
        $load = call_user_func( self::$load_source );
        return is_array( $load ) && isset( $load[0] ) && (float) $load[0] >= $limit;
    }

    /** One more busy refusal this hour (read by the alert). */
    public static function note_busy() {
        self::bump( 'hdlv2_stage1_busy_' );
    }

    /** Hourly counters: busy refusals, replayed waiting contacts, drain failures. */
    public static function bump( $prefix ) {
        $key = $prefix . gmdate( 'YmdH' );
        set_transient( $key, (int) get_transient( $key ) + 1, 2 * HOUR_IN_SECONDS );
    }

    public static function run_drain() {
        global $wpdb;
        // Before Phase AK has verified, history is not yet marked as sent:
        // draining then would send every old lead its PDF again.
        if ( version_compare( (string) get_option( 'hdlv2_db_version', '0' ), '3.30', '<' ) ) {
            return;
        }
        if ( self::is_busy() ) {
            if ( ! get_transient( 'hdlv2_stage1_drain_busy_logged' ) ) {
                set_transient( 'hdlv2_stage1_drain_busy_logged', 1, 10 * MINUTE_IN_SECONDS );
                error_log( '[HDLV2 stage1-drain] server busy — drain skipped (logged at most once per 10 minutes).' );
            }
            return;
        }
        $limit = (int) self::knob( 'drain_per_minute' );
        if ( $limit <= 0 ) {
            return;
        }
        $table = $wpdb->prefix . 'hdlv2_widget_leads';
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE stage1_fired_at IS NULL AND status <> 'rejected' ORDER BY id ASC LIMIT %d",
            $limit
        ) );
        foreach ( (array) $rows as $row ) {
            $claimed = $wpdb->query( $wpdb->prepare(
                "UPDATE $table SET stage1_fired_at = %s WHERE id = %d AND stage1_fired_at IS NULL",
                current_time( 'mysql' ), (int) $row->id
            ) );
            if ( 1 !== (int) $claimed ) {
                continue; // another tick took it
            }
            try {
                HDLV2_Widget_Config::fire_stage1_for_lead( $row );
            } catch ( \Throwable $e ) {
                // Stays claimed: a lead that throws is never retried in a loop.
                error_log( '[HDLV2 stage1-drain] fire failed lead=' . (int) $row->id . ': ' . $e->getMessage() );
                self::bump( 'hdlv2_stage1_drain_fail_' );
            }
        }
    }

    public static function run_alert() {
        global $wpdb;
        $since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - HOUR_IN_SECONDS );
        $leads = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hdlv2_widget_leads WHERE created_at >= %s",
            $since
        ) );
        $busy     = self::last_two_hours( 'hdlv2_stage1_busy_' );
        $replayed = self::last_two_hours( 'hdlv2_stage1_replayed_' );
        $failures = self::last_two_hours( 'hdlv2_stage1_drain_fail_' );
        error_log( sprintf( '[HDLV2 stage1-alert] leads=%d busy=%d replayed=%d drain_failures=%d', $leads, $busy, $replayed, $failures ) );

        if ( $leads < (int) self::knob( 'alert_threshold' ) && $busy <= 20 ) {
            return;
        }
        $to = array_filter( array_map( 'trim', explode( ',', (string) get_option( 'hdlv2_stage1_alert_to', '' ) ) ) );
        if ( ! $to ) {
            $to = array( (string) get_option( 'admin_email' ) );
        }
        $body = implode( "\n", array(
            'Stage 1 leads in the last hour: ' . $leads,
            'Busy refusals: ' . $busy,
            'Replayed from the waiting list: ' . $replayed,
            'Drain failures: ' . $failures,
            '',
            'Runbook:',
            '1. Raise the drain: wp option update hdlv2_stage1_drain_per_minute 40',
            '2. If the box copes, lift the gate: wp option update hdlv2_stage1_busy_load 0',
            '3. Bigger box (Vultr console, 2 CPU / 4 GB, about $24 a month); Cloudflare Under Attack mode if the traffic is bots. Check Make credits and PDFMonkey.',
        ) );
        wp_mail( array_values( $to ), sprintf( 'Stage 1 volume: %d leads in the last hour', $leads ), $body );
    }

    /** This hour's counter plus the last hour's (the alert runs once an hour). */
    private static function last_two_hours( $prefix ) {
        return (int) get_transient( $prefix . gmdate( 'YmdH' ) ) + (int) get_transient( $prefix . gmdate( 'YmdH', time() - HOUR_IN_SECONDS ) );
    }
}
