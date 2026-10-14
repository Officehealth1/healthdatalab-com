<?php
/**
 * The once-a-minute drain (v0.48.16): claims up to N unfired leads with one
 * atomic UPDATE each, then fires the Stage 1 PDF for each exactly as the
 * request did before. Real HDLV2_Stage1_Drain + HDLV2_Widget_Config.
 *
 * Run:  php scenario-drain.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
$GLOBALS['logfile'] = tempnam( sys_get_temp_dir(), 'drain' );
ini_set( 'error_log', $GLOBALS['logfile'] );
require __DIR__ . '/stubs.php';
load_sources();
if ( ! class_exists( 'HDLV2_Stage1_Drain' ) ) { check( 'HDLV2_Stage1_Drain exists', false ); done(); }

class DrainWpdb extends FakeWpdb {
    public $leads = array();   // id => row
    public $claims = array();  // ids claimed, in order
    public $throw_config_for = 0;
    public function get_results( $sql ) {
        $this->queries[] = $sql;
        if ( false !== strpos( $sql, 'hdlv2_widget_leads' ) && false !== strpos( $sql, 'stage1_fired_at IS NULL' ) ) {
            preg_match( '/LIMIT (\d+)/', $sql, $m );
            $out = array();
            foreach ( $this->leads as $r ) {
                if ( null === $r->stage1_fired_at && 'rejected' !== $r->status ) $out[] = clone $r;
            }
            return array_slice( $out, 0, (int) ( $m[1] ?? 0 ) );
        }
        return array();
    }
    public function query( $sql ) {
        if ( preg_match( "/UPDATE \S*hdlv2_widget_leads SET stage1_fired_at = '([^']+)' WHERE id = (\d+) AND stage1_fired_at IS NULL/", $sql, $m ) ) {
            $this->queries[] = $sql;
            $r = $this->leads[ (int) $m[2] ] ?? null;
            if ( ! $r || null !== $r->stage1_fired_at ) return 0;
            $r->stage1_fired_at = $m[1];
            $this->claims[] = (int) $m[2];
            return 1;
        }
        return parent::query( $sql );
    }
    public function get_row( $sql ) {
        if ( false !== strpos( $sql, 'hdlv2_widget_config' ) && preg_match( '/practitioner_user_id = (\d+)/', $sql, $m ) ) {
            if ( (int) $m[1] === $this->throw_config_for ) throw new RuntimeException( 'boom' );
            return 122 === (int) $m[1] ? $this->config : null;
        }
        return parent::get_row( $sql );
    }
}
function lead( $id, $over = array() ) {
    return (object) array_merge( array(
        'id' => $id, 'practitioner_user_id' => 122, 'visitor_name' => "Lead $id", 'visitor_email' => "d$id@example.test",
        'visitor_phone' => '07700 900000', 'visitor_age' => 44, 'rate_of_ageing' => '1.02',
        'stage1_data' => json_encode( array( 'q1_age' => 44, 'q1_sex' => 'male', 'q9' => 'b' ) ),
        'status' => 'pending', 'stage1_fired_at' => null, 'stage1_cache_token' => str_repeat( 'c', 64 ),
        'visitor_country' => null, 'visitor_region' => null, 'invite_id' => null,
    ), $over );
}
function setup( $leads, $opts = array() ) {
    global $wpdb;
    fresh_wpdb( 'open' );
    $w = new DrainWpdb();
    $w->config = $wpdb->config;
    $w->config->practitioner_user_id = 122;
    foreach ( $leads as $l ) $w->leads[ $l->id ] = $l;
    $wpdb = $w;
    $GLOBALS['options'] = array_merge( array( 'hdlv2_db_version' => '3.30' ), $opts );
    HDLV2_Stage1_Drain::$load_source = function () { return array( 0.4, 0.4, 0.4 ); };
    // QuickChart answers with a PNG-sized body so the gauge is saved; Make answers 200.
    $GLOBALS['http'] = function ( $m, $url, $a ) {
        return 'GET' === $m ? array( 'response' => array( 'code' => 200 ), 'body' => str_repeat( 'x', 2000 ) ) : array( 'response' => array( 'code' => 200 ), 'body' => '' );
    };
    file_put_contents( $GLOBALS['logfile'], '' );
    return $w;
}
function make_posts() {
    return array_values( array_filter( http_calls( 'POST' ), function ( $c ) { return false !== strpos( $c['url'], 'hook.example.test/stage1' ); } ) );
}
function gets() { return array_values( array_filter( http_calls( 'GET' ), function ( $c ) { return false !== strpos( $c['url'], 'quickchart.io' ); } ) ); }
function logged( $needle ) { return substr_count( (string) file_get_contents( $GLOBALS['logfile'] ), $needle ); }

$A = lead( 1 ); $B = lead( 2, array( 'stage1_cache_token' => null ) ); $C = lead( 3, array( 'stage1_fired_at' => '2026-10-01 10:00:00' ) );
$D = lead( 4, array( 'status' => 'rejected' ) ); $E = lead( 5, array( 'practitioner_user_id' => 999 ) );

echo "── D1. knob 2 ──\n";
$w = setup( array( $A, $B, $C, $D, $E ), array( 'hdlv2_stage1_drain_per_minute' => 2 ) );
HDLV2_Stage1_Drain::run_drain();
check( 'D1.1 claims A and B only, in id order', array( 1, 2 ) === $w->claims );
check( 'D1.2 every claim is the atomic UPDATE … WHERE id = %d AND stage1_fired_at IS NULL', 2 === count( array_filter( $w->sql_matching( 'AND stage1_fired_at IS NULL' ), function ( $q ) { return 0 === strpos( ltrim( $q ), 'UPDATE' ); } ) ) );
check( 'D1.3 exactly two outbound calls per lead (QuickChart GET + Make POST)', 2 === count( gets() ) && 2 === count( make_posts() ) && 4 === count( http_calls() ) );
$pay = json_decode( make_posts()[0]['args']['body'], true );
check( 'D1.4 the Make payload is the 33-key Stage 1 payload', is_array( $pay ) && 33 === count( $pay ) && 'stage1' === $pay['report_type'] );
check( 'D1.5 payload carries the stored name, email, phone, rate and answers', 'Lead 1' === $pay['client_name'] && 'd1@example.test' === $pay['client_email'] && '07700 900000' === $pay['client_phone'] && '1.02' === $pay['rate_of_ageing'] && 'b' === $pay['stage1_data']['q9'] );
check( 'D1.6 form_token = the stored cache token', str_repeat( 'c', 64 ) === $pay['form_token'] );
$payB = json_decode( make_posts()[1]['args']['body'], true );
check( 'D1.7 no stored token → the same sha256(practitioner|email) the request used', bin2hex( hash( 'sha256', '122|d2@example.test', true ) ) === $payB['form_token'] );
check( 'D1.8 offer_show is left to its default (the public path passed nothing)', '1' === (string) ( $pay['offer_show'] ?? '' ) || '0' === (string) ( $pay['offer_show'] ?? '' ) );

echo "── D2. a second tick never refires ──\n";
$before = count( make_posts() );
HDLV2_Stage1_Drain::run_drain();
check( 'D2.1 next tick claims E only (C fired, D rejected, A/B done)', array( 1, 2, 5 ) === $w->claims );
check( 'D2.2 E has no config: logged "no config" once, nothing sent, left claimed', $before === count( make_posts() ) && 1 === logged( 'no config' ) && null !== $w->leads[5]->stage1_fired_at );
HDLV2_Stage1_Drain::run_drain();
check( 'D2.3 third tick: nothing left, no claim, no log', array( 1, 2, 5 ) === $w->claims && 1 === logged( 'no config' ) );
check( 'D2.4 C and D never claimed', '2026-10-01 10:00:00' === $w->leads[3]->stage1_fired_at && null === $w->leads[4]->stage1_fired_at );

echo "── D3. knob 0 pauses ──\n";
$w = setup( array( lead( 1 ) ), array( 'hdlv2_stage1_drain_per_minute' => 0 ) );
HDLV2_Stage1_Drain::run_drain();
check( 'D3.1 claims nothing, sends nothing', array() === $w->claims && 0 === count( http_calls() ) );

echo "── D4. busy ──\n";
$w = setup( array( lead( 1 ) ) );
HDLV2_Stage1_Drain::$load_source = function () { return array( 7.5, 3, 2 ); };
HDLV2_Stage1_Drain::run_drain();
HDLV2_Stage1_Drain::run_drain();
check( 'D4.1 busy → claims nothing', array() === $w->claims && 0 === count( http_calls() ) );
check( 'D4.2 one log line for two busy ticks (at most once per 10 minutes)', 1 === logged( 'stage1-drain' ) );

echo "── D5. one lead throws ──\n";
$w = setup( array( lead( 1, array( 'practitioner_user_id' => 777 ) ), lead( 2 ) ) );
$w->throw_config_for = 777;
HDLV2_Stage1_Drain::run_drain();
check( 'D5.1 logged with the lead id, the next lead still fires', 1 === logged( 'fire failed lead=1' ) && 1 === count( make_posts() ) && array( 1, 2 ) === $w->claims );
check( 'D5.2 the failed lead stays claimed (never a loop)', null !== $w->leads[1]->stage1_fired_at );
check( 'D5.3 failure counted for the alert', 1 === (int) get_transient( 'hdlv2_stage1_drain_fail_' . gmdate( 'YmdH' ) ) );

echo "── D6. before the Phase AK migration has verified ──\n";
$w = setup( array( lead( 1 ) ), array( 'hdlv2_db_version' => '3.29' ) );
HDLV2_Stage1_Drain::run_drain();
check( 'D6.1 db < 3.30 → the drain does nothing (history is not yet marked fired)', array() === $w->claims && 0 === count( http_calls() ) );

@unlink( $GLOBALS['logfile'] );
done();
