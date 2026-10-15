<?php
/**
 * Alert cadence (v0.48.17): the spike alert mails at most once a UTC day; one
 * summary mail on the 1st of each month, quiet month or not; the hourly run
 * rolls the hour counters into daily options kept 40 days.
 * Real HDLV2_Stage1_Drain, fake clock through $clock.
 *
 * Run:  php scenario-alert-cadence.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
ini_set( 'error_log', '/dev/null' );
require __DIR__ . '/stubs.php';
load_sources();
if ( ! class_exists( 'HDLV2_Stage1_Drain' ) ) { check( 'HDLV2_Stage1_Drain exists', false ); done(); }

class CadenceWpdb extends FakeWpdb {
    public $leads_last_hour = 0;
    public $month_days = array(); // 'Y-m-d' => count, answered to the monthly GROUP BY
    public function get_var( $sql ) {
        if ( false !== strpos( $sql, 'COUNT(*)' ) && false !== strpos( $sql, 'hdlv2_widget_leads' ) ) { $this->queries[] = $sql; return (string) $this->leads_last_hour; }
        return parent::get_var( $sql );
    }
    public function get_results( $sql ) {
        $this->queries[] = $sql;
        if ( false === strpos( $sql, 'GROUP BY' ) ) return array();
        $out = array();
        foreach ( $this->month_days as $d => $c ) $out[] = (object) array( 'd' => $d, 'c' => $c );
        return $out;
    }
}
function at( $when ) { $ts = strtotime( $when . ' UTC' ); HDLV2_Stage1_Drain::$clock = function () use ( $ts ) { return $ts; }; return $ts; }
function fresh( $opts = array() ) {
    global $wpdb;
    fresh_wpdb( 'open' );
    $wpdb = new CadenceWpdb();
    $GLOBALS['options'] = array_merge( array( 'admin_email' => 'admin@example.test' ), $opts );
    return $wpdb;
}
function spike_mails() { return array_values( array_filter( $GLOBALS['mails'], function ( $m ) { return 0 === strpos( $m[1], 'Stage 1 volume:' ); } ) ); }
function month_mails() { return array_values( array_filter( $GLOBALS['mails'], function ( $m ) { return 0 === strpos( $m[1], 'Stage 1, last month:' ); } ) ); }

echo "── C1. the spike alert, at most once a day ──\n";
$wpdb = fresh( array( 'hdlv2_stage1_alert_threshold' => 0, 'hdlv2_stage1_monthly_sent' => '2026-10' ) );
$wpdb->leads_last_hour = 7;
at( '2026-10-15 09:10:00' );
HDLV2_Stage1_Drain::run_alert();
at( '2026-10-15 10:10:00' );
HDLV2_Stage1_Drain::run_alert();
check( 'C1.1 two runs the same day → exactly one mail', 1 === count( spike_mails() ) );
check( 'C1.2 subject unchanged', 'Stage 1 volume: 7 leads in the last hour' === ( spike_mails()[0][1] ?? '' ) );
check( 'C1.3 the marker is today (UTC)', '2026-10-15' === get_option( 'hdlv2_stage1_alert_last' ) );
update_option( 'hdlv2_stage1_alert_last', '2026-10-14' );
HDLV2_Stage1_Drain::run_alert();
check( 'C1.4 marker set to yesterday → a second mail', 2 === count( spike_mails() ) );
at( '2026-10-16 00:05:00' );
HDLV2_Stage1_Drain::run_alert();
check( 'C1.5 the next UTC day → a third mail', 3 === count( spike_mails() ) );
$wpdb = fresh( array( 'hdlv2_stage1_monthly_sent' => '2026-10' ) );
$wpdb->leads_last_hour = 3;
HDLV2_Stage1_Drain::run_alert();
check( 'C1.6 below the threshold → no mail, no marker', 0 === count( $GLOBALS['mails'] ) && false === get_option( 'hdlv2_stage1_alert_last' ) );

echo "── C2. the monthly summary ──\n";
$wpdb = fresh( array(
    'hdlv2_stage1_alert_to'        => 'a@example.test, b@example.test',
    'hdlv2_stage1_daily_20260903'  => '{"busy":5,"replayed":2,"failures":0}',
    'hdlv2_stage1_daily_20260930'  => '{"busy":1,"replayed":0,"failures":3}',
    'hdlv2_stage1_daily_20261001'  => '{"busy":100,"replayed":100,"failures":100}', // this month: not counted
    'hdlv2_stage1_daily_20260831'  => '{"busy":100,"replayed":100,"failures":100}', // month before: not counted
) );
$wpdb->month_days = array( '2026-09-03' => 40, '2026-09-12' => 25, '2026-09-30' => 7 );
at( '2026-10-01 08:00:00' );
HDLV2_Stage1_Drain::run_alert();
$m = month_mails();
check( 'C2.1 the 1st, no marker → one summary mail', 1 === count( $m ) );
check( 'C2.2 subject "Stage 1, last month: 72 leads"', 'Stage 1, last month: 72 leads' === ( $m[0][1] ?? '' ) );
check( 'C2.3 to the alert addresses', array( 'a@example.test', 'b@example.test' ) === array_values( (array) ( $m[0][0] ?? array() ) ) );
$lines = explode( "\n", (string) ( $m[0][2] ?? '' ) );
check( 'C2.4 body is five lines', 5 === count( $lines ) );
check( 'C2.5 leads line', 'Leads in September 2026: 72' === ( $lines[0] ?? '' ) );
check( 'C2.6 busiest day line', 'Busiest day: 3 September (40 leads)' === ( $lines[1] ?? '' ) );
check( 'C2.7 busy refusals summed from the daily options', 'Busy refusals: 6' === ( $lines[2] ?? '' ) );
check( 'C2.8 replayed', 'Replayed from the waiting list: 2' === ( $lines[3] ?? '' ) );
check( 'C2.9 drain failures', 'Drain failures: 3' === ( $lines[4] ?? '' ) );
check( 'C2.10 marker = this month', '2026-10' === get_option( 'hdlv2_stage1_monthly_sent' ) );
$sql = implode( "\n", $wpdb->sql_matching( 'GROUP BY' ) );
check( 'C2.11 leads counted from created_at in the previous calendar month', false !== strpos( $sql, "created_at >= '2026-09-01 00:00:00'" ) && false !== strpos( $sql, "created_at < '2026-10-01 00:00:00'" ) );
at( '2026-10-01 09:00:00' );
HDLV2_Stage1_Drain::run_alert();
check( 'C2.12 run again the same day → no second summary', 1 === count( month_mails() ) );
$wpdb = fresh();
at( '2026-10-02 08:00:00' );
HDLV2_Stage1_Drain::run_alert();
check( 'C2.13 the 2nd, no marker → no summary', 0 === count( month_mails() ) );
$wpdb = fresh();
at( '2026-11-01 00:30:00' );
HDLV2_Stage1_Drain::run_alert();
$m = month_mails();
check( 'C2.14 a quiet month still gets its summary', 1 === count( $m ) && 'Stage 1, last month: 0 leads' === $m[0][1] );
check( 'C2.15 quiet month says no busiest day', 'Busiest day: none' === ( explode( "\n", $m[0][2] )[1] ?? '' ) );
check( 'C2.16 admin_email when no alert addresses', array( 'admin@example.test' ) === array_values( (array) $m[0][0] ) );
$wpdb = fresh( array( 'hdlv2_stage1_monthly_sent' => '2026-09' ) );
at( '2026-10-15 08:00:00' );
HDLV2_Stage1_Drain::run_monthly_summary( true );
check( 'C2.17 forced (test only) off the 1st → one summary, marker 2026-10', 1 === count( month_mails() ) && '2026-10' === get_option( 'hdlv2_stage1_monthly_sent' ) );
HDLV2_Stage1_Drain::run_monthly_summary( true );
check( 'C2.18 forced again the same month → still one', 1 === count( month_mails() ) );

echo "── C3. the daily counters ──\n";
$wpdb = fresh( array( 'hdlv2_stage1_monthly_sent' => '2026-10' ) );
set_transient( 'hdlv2_stage1_busy_2026101508', 3 );
set_transient( 'hdlv2_stage1_replayed_2026101508', 2 );
set_transient( 'hdlv2_stage1_drain_fail_2026101508', 1 );
set_transient( 'hdlv2_stage1_busy_2026101509', 9 ); // the hour still running: not rolled yet
at( '2026-10-15 09:20:00' );
HDLV2_Stage1_Drain::run_alert();
$day = json_decode( (string) get_option( 'hdlv2_stage1_daily_20261015' ), true );
check( 'C3.1 the last full hour is rolled into today', array( 'busy' => 3, 'replayed' => 2, 'failures' => 1 ) === $day );
HDLV2_Stage1_Drain::run_alert();
$day = json_decode( (string) get_option( 'hdlv2_stage1_daily_20261015' ), true );
check( 'C3.2 a second run the same hour does not count it twice', 3 === ( $day['busy'] ?? 0 ) );
at( '2026-10-15 10:20:00' );
HDLV2_Stage1_Drain::run_alert();
$day = json_decode( (string) get_option( 'hdlv2_stage1_daily_20261015' ), true );
check( 'C3.3 next hour adds hour 09', 12 === ( $day['busy'] ?? 0 ) && 2 === ( $day['replayed'] ?? 0 ) );
$wpdb = fresh( array( 'hdlv2_stage1_monthly_sent' => '2026-10', 'hdlv2_stage1_rolled_hour' => '2026101521' ) );
set_transient( 'hdlv2_stage1_busy_2026101522', 4 );
set_transient( 'hdlv2_stage1_busy_2026101523', 5 );
at( '2026-10-16 00:10:00' );
HDLV2_Stage1_Drain::run_alert();
$day = json_decode( (string) get_option( 'hdlv2_stage1_daily_20261015' ), true );
check( 'C3.4 a missed run is caught up (two hours back), both land on their own day', 9 === ( $day['busy'] ?? 0 ) );
$wpdb = fresh( array(
    'hdlv2_stage1_monthly_sent'   => '2026-10',
    'hdlv2_stage1_daily_20260905' => '{"busy":1,"replayed":0,"failures":0}', // 41 days back
    'hdlv2_stage1_daily_20260906' => '{"busy":1,"replayed":0,"failures":0}', // 40 days back: kept
) );
at( '2026-10-16 05:00:00' );
HDLV2_Stage1_Drain::run_alert();
check( 'C3.5 a day older than 40 days is deleted', false === get_option( 'hdlv2_stage1_daily_20260905' ) );
check( 'C3.6 a day 40 days back is kept', false !== get_option( 'hdlv2_stage1_daily_20260906' ) );
done();
