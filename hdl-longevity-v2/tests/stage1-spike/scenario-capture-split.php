<?php
/**
 * The lead route becomes insert-and-reply (v0.48.16): the row is written with
 * the phone and stage1_fired_at NULL, and NOTHING leaves the server during the
 * request (no QuickChart, no Make). The drain fires it later. Real
 * HDLV2_Widget_Config::rest_capture_lead().
 *
 * Run:  php scenario-capture-split.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
ini_set( 'error_log', '/dev/null' );
require __DIR__ . '/stubs.php';
load_sources();
$GLOBALS['options'] = array( 'hdlv2_db_version' => '3.30' );

/** One stored lead row per (practitioner, email); the belt can make writes with the new columns fail. */
class LeadWpdb extends FakeWpdb {
    public $lead = null;          // stdClass of the stored row, or null
    public $missing_cols = false; // a write that names visitor_phone / stage1_fired_at fails
    public function get_var( $sql ) {
        if ( false !== strpos( $sql, 'hdlv2_widget_leads' ) && false === strpos( $sql, 'FOR UPDATE' ) ) {
            $this->queries[] = $sql;
            if ( ! $this->lead ) return null;
            return false !== strpos( $sql, 'SELECT stage1_data' ) ? $this->lead->stage1_data : $this->lead->id;
        }
        return parent::get_var( $sql );
    }
    private function bad( $data ) {
        return $this->missing_cols && ( array_key_exists( 'visitor_phone', $data ) || array_key_exists( 'stage1_fired_at', $data ) );
    }
    public function insert( $table, $data, $format = null ) {
        if ( substr( $table, -18 ) === 'hdlv2_widget_leads' && $this->bad( $data ) ) { $this->last_error = "Unknown column 'visitor_phone'"; return false; }
        return parent::insert( $table, $data, $format );
    }
    public function update( $table, $data, $where, $format = null, $where_format = null ) {
        if ( substr( $table, -18 ) === 'hdlv2_widget_leads' && $this->bad( $data ) ) { $this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where, 'failed' => true ); return false; }
        return parent::update( $table, $data, $where, $format, $where_format );
    }
    public function lead_writes() {
        $out = array();
        foreach ( $this->inserts as $i ) if ( substr( $i['table'], -18 ) === 'hdlv2_widget_leads' ) $out[] = array( 'insert', $i['data'] );
        foreach ( $this->updates as $u ) if ( substr( $u['table'], -18 ) === 'hdlv2_widget_leads' && empty( $u['failed'] ) ) $out[] = array( 'update', $u['data'] );
        return $out;
    }
}
function setup( $existing = null ) {
    global $wpdb;
    fresh_wpdb( 'open' );
    $w = new LeadWpdb();
    $w->config = $wpdb->config;
    $w->config->practitioner_user_id = 122;
    $w->lead = $existing;
    $wpdb = $w;
    return $w;
}
function params122( $email, $over = array() ) {
    $p = lead_params( $email );
    $p['practitioner_id'] = 122;
    $p['phone'] = '07700 900000';
    return array_merge( $p, $over );
}
function answers_json( $p ) {
    $a = array();
    foreach ( array( 'q1_age', 'q1_sex', 'q2a', 'q2b', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8', 'q9' ) as $k ) $a[ $k ] = $p[ $k ];
    return json_encode( $a );
}

echo "── S1. a public lead: written, nothing sent ──\n";
$w = setup();
$r = post_lead( params122( 'new@example.test' ) );
$ins = $w->inserts_into( 'hdlv2_widget_leads' );
check( 'S1.1 reply { success, rate }', is_array( $r ) && true === $r['success'] && isset( $r['rate'] ) && ! isset( $r['form_token'] ) );
check( 'S1.2 one lead row with the phone', 1 === count( $ins ) && '07700 900000' === ( $ins[0]['data']['visitor_phone'] ?? null ) );
check( 'S1.3 stage1_fired_at not set on the new row (NULL)', 1 === count( $ins ) && empty( $ins[0]['data']['stage1_fired_at'] ) );
check( 'S1.4 the cache token is stored on the row', 1 === count( array_filter( $w->updates, function ( $u ) { return isset( $u['data']['stage1_cache_token'] ) && 64 === strlen( $u['data']['stage1_cache_token'] ); } ) ) );
check( 'S1.5 ZERO outbound calls during the request (no QuickChart, no Make)', 0 === count( http_calls() ) );
check( 'S1.6 no mail', 0 === count( $GLOBALS['mails'] ) );

echo "── S2. the same email again ──\n";
$p   = params122( 'again@example.test' );
$old = (object) array( 'id' => 900, 'stage1_data' => answers_json( $p ) );
$w   = setup( $old );
post_lead( $p );
$up  = array_values( array_filter( $w->lead_writes(), function ( $x ) { return 'update' === $x[0] && isset( $x[1]['stage1_data'] ); } ) );
check( 'S2.1 same answers → row updated, stage1_fired_at left as it was', 1 === count( $up ) && ! array_key_exists( 'stage1_fired_at', $up[0][1] ) );
$w = setup( $old );
post_lead( array_merge( $p, array( 'q9' => 'e' ) ) );
$up = array_values( array_filter( $w->lead_writes(), function ( $x ) { return 'update' === $x[0] && isset( $x[1]['stage1_data'] ); } ) );
check( 'S2.2 changed answers → stage1_fired_at reset to NULL (fires again)', 1 === count( $up ) && array_key_exists( 'stage1_fired_at', $up[0][1] ) && null === $up[0][1]['stage1_fired_at'] );
check( 'S2.3 still nothing sent during the request', 0 === count( http_calls() ) );

echo "── S3. the invite fast path is unchanged ──\n";
$w = setup();
$w->invite = invite_row( array( 'practitioner_id' => 122, 'source' => 'practitioner', 'client_email' => 'inv@example.test' ) );
$r = post_lead( array_merge( params122( 'inv@example.test' ), array( 'invite_token' => TOKEN ) ) );
check( 'S3.1 complete_signup still runs in the request (form_token back, client record made)', is_array( $r ) && ! empty( $r['form_token'] ) && 1 === count( $w->inserts_into( 'hdlv2_form_progress' ) ) );
check( 'S3.2 the invite still fires Make synchronously', 1 === count( array_filter( $GLOBALS['captured_posts'], function ( $c ) { return false !== strpos( $c['url'], 'hook.example.test/stage1' ); } ) ) );
$lw = $w->inserts_into( 'hdlv2_widget_leads' );
check( 'S3.3 the confirmed lead row it writes is stamped fired (the drain never refires it)', 1 === count( $lw ) && ! empty( $lw[0]['data']['stage1_fired_at'] ) );

echo "── S4. honeypot ──\n";
$w = setup();
$r = post_lead( array_merge( params122( 'bot@example.test' ), array( 'website' => 'spam' ) ) );
check( 'S4.1 { success, pending }, nothing written, nothing sent', is_array( $r ) && ! empty( $r['pending'] ) && nothing_recorded( $w ) && 0 === count( $w->updates ) );

echo "── S5. missing-column belt ──\n";
$w = setup();
$w->missing_cols = true;
$r = post_lead( params122( 'belt@example.test', array( 'visitor_country' => 'US', 'visitor_region' => 'Texas' ) ) );
$ins = $w->inserts_into( 'hdlv2_widget_leads' );
check( 'S5.1 INSERT retried without the new columns, lead saved', is_array( $r ) && ! empty( $r['success'] ) && 1 === count( $ins ) && ! array_key_exists( 'visitor_phone', $ins[0]['data'] ) );
$p = params122( 'beltup@example.test' );
$w = setup( (object) array( 'id' => 901, 'stage1_data' => '{"q9":"a"}' ) );
$w->missing_cols = true;
post_lead( $p );
$up = array_values( array_filter( $w->lead_writes(), function ( $x ) { return 'update' === $x[0] && isset( $x[1]['stage1_data'] ); } ) );
check( 'S5.2 UPDATE retried without the new columns', 1 === count( $up ) && ! array_key_exists( 'visitor_phone', $up[0][1] ) && ! array_key_exists( 'stage1_fired_at', $up[0][1] ) );

echo "── S6. the INSERT stamps created_at on the site clock (the alert window reads it) ──\n";
$w = setup();
post_lead( params122( 'clock@example.test' ) );
$ins = $w->inserts_into( 'hdlv2_widget_leads' );
check( 'S6.1 created_at = current_time(mysql)', 1 === count( $ins ) && current_time( 'mysql' ) === ( $ins[0]['data']['created_at'] ?? '' ) );
done();
