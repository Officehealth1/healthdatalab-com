<?php
/**
 * Busy gate (load average ≥ knob → 503 server_busy, nothing written) and the
 * hourly alert mail. Real HDLV2_Stage1_Drain + HDLV2_Widget_Config.
 *
 * Run:  php scenario-busy-and-alert.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
ini_set( 'error_log', '/dev/null' );
require __DIR__ . '/stubs.php';
load_sources();
if ( ! class_exists( 'HDLV2_Stage1_Drain' ) ) { check( 'HDLV2_Stage1_Drain exists', false ); done(); }

class AlertWpdb extends FakeWpdb {
    public $leads_last_hour = 0;
    public function get_var( $sql ) {
        if ( false !== strpos( $sql, 'COUNT(*)' ) && false !== strpos( $sql, 'hdlv2_widget_leads' ) ) { $this->queries[] = $sql; return (string) $this->leads_last_hour; }
        return parent::get_var( $sql );
    }
}
function load_is( $l ) { HDLV2_Stage1_Drain::$load_source = function () use ( $l ) { return array( $l, 1, 1 ); }; }

echo "── G1. is_busy() ──\n";
$GLOBALS['options'] = array();
load_is( 6.0 );
check( 'G1.1 load 6.0 at the default knob 6 → busy', true === HDLV2_Stage1_Drain::is_busy() );
load_is( 5.9 );
check( 'G1.2 load 5.9 → not busy', false === HDLV2_Stage1_Drain::is_busy() );
$GLOBALS['options'] = array( 'hdlv2_stage1_busy_load' => 0 );
load_is( 50 );
check( 'G1.3 knob 0 → never busy', false === HDLV2_Stage1_Drain::is_busy() );
$GLOBALS['options'] = array();
HDLV2_Stage1_Drain::$load_source = 'hdlv2_no_such_function';
check( 'G1.4 no load average available → not busy (fail open)', false === HDLV2_Stage1_Drain::is_busy() );
check( 'G1.5 the real source is sys_getloadavg', false !== strpos( file_get_contents( __DIR__ . '/../../includes/sprint-1/class-hdlv2-stage1-drain.php' ), "public static \$load_source = 'sys_getloadavg';" ) );
check( 'G1.6 knob defaults: 20 a minute, load 6, 500 an hour', 20 === (int) HDLV2_Stage1_Drain::knob( 'drain_per_minute' ) && 6.0 === (float) HDLV2_Stage1_Drain::knob( 'busy_load' ) && 500 === (int) HDLV2_Stage1_Drain::knob( 'alert_threshold' ) );

echo "── G2. the lead route while busy ──\n";
$wpdb = fresh_wpdb( 'open' );
load_is( 9 );
$r = post_lead( lead_params( 'busy@example.test' ) );
check( 'G2.1 503 WP_REST_Response, code server_busy', $r instanceof WP_REST_Response && 503 === $r->get_status() && 'server_busy' === ( $r->get_data()['code'] ?? '' ) );
check( 'G2.2 message is plain', 'We are busy right now. Please try again in a minute.' === ( $r->get_data()['message'] ?? '' ) );
check( 'G2.3 Retry-After: 60', '60' === (string) ( $r->headers['Retry-After'] ?? '' ) );
check( 'G2.4 nothing written, nothing sent', nothing_recorded( $wpdb ) && 0 === count( $wpdb->updates ) );
check( 'G2.5 the hourly busy counter went up', 1 === (int) get_transient( 'hdlv2_stage1_busy_' . gmdate( 'YmdH' ) ) );
$r = post_lead( array_merge( lead_params( 'bot2@example.test' ), array( 'website' => 'x' ) ) );
check( 'G2.6 the honeypot still answers first (busy or not)', is_array( $r ) && ! empty( $r['pending'] ) );
load_is( 0.5 );
$r = post_lead( lead_params( 'calm@example.test' ) );
check( 'G2.7 not busy → saved as usual', is_array( $r ) && ! empty( $r['success'] ) && 1 === count( $wpdb->inserts_into( 'hdlv2_widget_leads' ) ) );

echo "── G3. the alert ──\n";
function alert_run( $leads, $busy, $opts = array() ) {
    global $wpdb;
    fresh_wpdb( 'open' );
    $wpdb = new AlertWpdb();
    $wpdb->leads_last_hour = $leads;
    $GLOBALS['options'] = array_merge( array( 'admin_email' => 'admin@example.test' ), $opts );
    if ( $busy ) set_transient( 'hdlv2_stage1_busy_' . gmdate( 'YmdH' ), $busy, 7200 );
    set_transient( 'hdlv2_stage1_replayed_' . gmdate( 'YmdH' ), 4, 7200 );
    set_transient( 'hdlv2_stage1_drain_fail_' . gmdate( 'YmdH' ), 2, 7200 );
    HDLV2_Stage1_Drain::run_alert();
    return $GLOBALS['mails'];
}
check( 'G3.1 499 leads, 0 busy → no mail', 0 === count( alert_run( 499, 0 ) ) );
$m = alert_run( 500, 0 );
check( 'G3.2 500 leads → one mail to admin_email', 1 === count( $m ) && array( 'admin@example.test' ) === (array) $m[0][0] );
check( 'G3.3 subject "Stage 1 volume: 500 leads in the last hour"', 'Stage 1 volume: 500 leads in the last hour' === $m[0][1] );
$body = $m[0][2] ?? '';
check( 'G3.4 body names leads, busy refusals, replayed, drain failures', false !== strpos( $body, '500' ) && false !== strpos( $body, 'Busy refusals: 0' ) && false !== strpos( $body, 'Replayed from the waiting list: 4' ) && false !== strpos( $body, 'Drain failures: 2' ) );
check( 'G3.5 body carries the three runbook lines', false !== strpos( $body, 'wp option update hdlv2_stage1_drain_per_minute 40' ) && false !== strpos( $body, 'wp option update hdlv2_stage1_busy_load 0' ) && false !== strpos( $body, 'Cloudflare Under Attack' ) );
$m = alert_run( 10, 21 );
check( 'G3.6 21 busy refusals and 10 leads → mail', 1 === count( $m ) );
check( 'G3.7 20 busy refusals → no mail', 0 === count( alert_run( 10, 20 ) ) );
$m = alert_run( 600, 0, array( 'hdlv2_stage1_alert_to' => 'a@example.test, b@example.test' ) );
check( 'G3.8 two addresses → one mail, two recipients', 1 === count( $m ) && array( 'a@example.test', 'b@example.test' ) === array_values( (array) $m[0][0] ) );
$m = alert_run( 2, 0, array( 'hdlv2_stage1_alert_threshold' => 1 ) );
check( 'G3.9 the threshold knob is read', 1 === count( $m ) );
alert_run( 3, 0 );
$sql = implode( "\n", $wpdb->sql_matching( 'COUNT(*)' ) );
check( 'G3.10 the window is computed on the site clock, not UTC_TIMESTAMP()', false === strpos( $sql, 'UTC_TIMESTAMP' ) && false !== strpos( $sql, "created_at >= '" . gmdate( 'Y-m-d H', time() - 3600 ) ) );
done();
