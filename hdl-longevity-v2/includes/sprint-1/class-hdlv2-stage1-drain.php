<?php
/**
 * Stage 1 under load (v0.48.16) — the busy gate, the PDF drain and the
 * volume alert. v0.48.17: the alert mails at most once a UTC day, and one
 * summary goes out on the 1st of each month, quiet month or not.
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
 * State it keeps: hdlv2_stage1_alert_last (Y-m-d), hdlv2_stage1_monthly_sent
 * (Y-m), hdlv2_stage1_rolled_hour (YmdH) and hdlv2_stage1_daily_Ymd (JSON
 * {busy, replayed, failures}, kept 40 days).
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

    /** The clock the alert reads. A test swaps it; nothing else does. */
    public static $clock = 'time';

    const COUNTERS = array(
        'busy'     => 'hdlv2_stage1_busy_',
        'replayed' => 'hdlv2_stage1_replayed_',
        'failures' => 'hdlv2_stage1_drain_fail_',
    );

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
        $busy     = self::last_two_hours( self::COUNTERS['busy'] );
        $replayed = self::last_two_hours( self::COUNTERS['replayed'] );
        $failures = self::last_two_hours( self::COUNTERS['failures'] );
        error_log( sprintf( '[HDLV2 stage1-alert] leads=%d busy=%d replayed=%d drain_failures=%d', $leads, $busy, $replayed, $failures ) );

        self::roll_daily();
        self::run_monthly_summary();

        if ( $leads < (int) self::knob( 'alert_threshold' ) && $busy <= 20 ) {
            return;
        }
        $today = gmdate( 'Y-m-d', self::now() );
        if ( get_option( 'hdlv2_stage1_alert_last' ) === $today ) {
            error_log( '[HDLV2 stage1-alert] alert already sent today — no mail.' );
            return;
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
        wp_mail( self::recipients(), sprintf( 'Stage 1 volume: %d leads in the last hour', $leads ), $body );
        update_option( 'hdlv2_stage1_alert_last', $today, false );
    }

    /**
     * One mail on the 1st: last calendar month's leads, busiest day and the
     * three counters. $force skips the day-of-month check (manual test only);
     * the once-a-month marker still holds.
     */
    public static function run_monthly_summary( $force = false ) {
        global $wpdb;
        $now   = self::now();
        $month = gmdate( 'Y-m', $now );
        if ( ( ! $force && '1' !== gmdate( 'j', $now ) ) || get_option( 'hdlv2_stage1_monthly_sent' ) === $month ) {
            return;
        }
        $end   = strtotime( $month . '-01 00:00:00 UTC' );
        $start = strtotime( '-1 month', $end );
        $days  = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM {$wpdb->prefix}hdlv2_widget_leads WHERE created_at >= %s AND created_at < %s GROUP BY d",
            gmdate( 'Y-m-d H:i:s', $start ), gmdate( 'Y-m-d H:i:s', $end )
        ) );
        $leads = 0;
        $top   = null;
        foreach ( $days as $row ) {
            $leads += (int) $row->c;
            if ( ! $top || (int) $row->c > (int) $top->c ) {
                $top = $row;
            }
        }
        $sum = array( 'busy' => 0, 'replayed' => 0, 'failures' => 0 );
        for ( $t = $start; $t < $end; $t += DAY_IN_SECONDS ) {
            $day = json_decode( (string) get_option( 'hdlv2_stage1_daily_' . gmdate( 'Ymd', $t ), '' ), true );
            foreach ( $sum as $k => $v ) {
                $sum[ $k ] += (int) ( $day[ $k ] ?? 0 );
            }
        }
        $body = implode( "\n", array(
            'Leads in ' . gmdate( 'F Y', $start ) . ': ' . $leads,
            'Busiest day: ' . ( $top ? gmdate( 'j F', strtotime( $top->d . ' UTC' ) ) . ' (' . (int) $top->c . ' leads)' : 'none' ),
            'Busy refusals: ' . $sum['busy'],
            'Replayed from the waiting list: ' . $sum['replayed'],
            'Drain failures: ' . $sum['failures'],
        ) );
        wp_mail( self::recipients(), sprintf( 'Stage 1, last month: %d leads', $leads ), $body );
        update_option( 'hdlv2_stage1_monthly_sent', $month, false );
    }

    /**
     * Adds each finished hour's counters to its day's option, once. Looks two
     * hours back (the counters live two hours), so one missed run is caught up.
     */
    private static function roll_daily() {
        $now  = self::now();
        $last = (string) get_option( 'hdlv2_stage1_rolled_hour', '' );
        foreach ( array( 2, 1 ) as $back ) {
            $t    = $now - $back * HOUR_IN_SECONDS;
            $hour = gmdate( 'YmdH', $t );
            if ( strcmp( $hour, $last ) <= 0 ) {
                continue;
            }
            $key = 'hdlv2_stage1_daily_' . gmdate( 'Ymd', $t );
            $day = json_decode( (string) get_option( $key, '' ), true );
            $day = is_array( $day ) ? $day : array();
            foreach ( self::COUNTERS as $k => $prefix ) {
                $day[ $k ] = (int) ( $day[ $k ] ?? 0 ) + (int) get_transient( $prefix . $hour );
            }
            update_option( $key, wp_json_encode( $day ), false );
            $last = $hour;
        }
        update_option( 'hdlv2_stage1_rolled_hour', $last, false );
        // ponytail: deletes days 41–47 back each run; a cron outage longer than a week leaves older days behind.
        for ( $back = 41; $back <= 47; $back++ ) {
            delete_option( 'hdlv2_stage1_daily_' . gmdate( 'Ymd', $now - $back * DAY_IN_SECONDS ) );
        }
    }

    private static function recipients() {
        $to = array_filter( array_map( 'trim', explode( ',', (string) get_option( 'hdlv2_stage1_alert_to', '' ) ) ) );
        return $to ? array_values( $to ) : array( (string) get_option( 'admin_email' ) );
    }

    private static function now() {
        return (int) call_user_func( self::$clock );
    }

    /** This hour's counter plus the last hour's (the alert runs once an hour). */
    private static function last_two_hours( $prefix ) {
        $now = self::now();
        return (int) get_transient( $prefix . gmdate( 'YmdH', $now ) ) + (int) get_transient( $prefix . gmdate( 'YmdH', $now - HOUR_IN_SECONDS ) );
    }
}
