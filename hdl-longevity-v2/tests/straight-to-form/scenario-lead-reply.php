<?php
/**
 * v0.48.85 (plan 2k) — the lead reply carries buy_pass, only to the browser that sent the answers:
 * when hdlv2_ff_straight_in_result_page, hdlv2_ff_straight_to_form and hdlv2_ff_result_offer are all
 * on and the offer applies to the practitioner. Never cached, so a repeat post inside the 60-second
 * window gets none (the link_key pattern). With the new switch absent the reply equals the base.
 * Against the REAL HDLV2_Widget_Config::rest_capture_lead().
 *
 * Run:  php scenario-lead-reply.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/../full-report/stubs.php';
function wp_salt( $scheme = 'auth' ) { return 'salt-one-for-tests'; }
load_sources();

/** Answers the re-read of the lead row by id from what the request wrote. */
class ReplyWpdb extends FakeWpdb {
    public function get_row( $sql ) {
        if ( false !== strpos( $sql, 'hdlv2_widget_leads' ) && preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
            $this->queries[] = $sql;
            $ins = $this->inserts_into( 'hdlv2_widget_leads' );
            if ( ! $ins ) return null;
            $row = (object) end( $ins )['data'];
            $row->id = (int) $m[1];
            foreach ( $this->updates as $u ) {
                if ( false !== strpos( (string) ( $u['table'] ?? '' ), 'hdlv2_widget_leads' ) && (int) ( $u['where']['id'] ?? 0 ) === (int) $m[1] ) {
                    foreach ( $u['data'] as $k => $v ) { $row->$k = $v; }
                }
            }
            return $row;
        }
        return parent::get_row( $sql );
    }
}
function fresh( $switches = array( 'hdlv2_ff_straight_in_result_page', 'hdlv2_ff_straight_to_form', 'hdlv2_ff_result_offer' ), $offer_prac = 206 ) {
    global $wpdb;
    $base = fresh_wpdb();
    $wpdb = new ReplyWpdb();
    $wpdb->config = $base->config;
    $GLOBALS['options'] = array( 'hdlv2_db_version' => '3.34', 'hdlv2_stage1_offer' => array( 'practitioner_id' => $offer_prac ) );
    foreach ( $switches as $s ) { $GLOBALS['options'][ $s ] = '1'; }
    $_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand_ip();
    return $wpdb;
}
function wp_rand_ip() { static $n = 20; return ++$n; }
$has = method_exists( 'HDLV2_Offer', 'browser_pass_ok' ) && method_exists( 'HDLV2_Widget_Config', 'lead_digest' );
check( 'LR0 the browser pass functions exist', $has );

echo "── LR1. all three switches on, the offer's practitioner ──\n";
$w = fresh();
$r = post_lead( lead_params( 'reply+1@example.test' ) );
$lead_id = (int) $w->insert_id;
check( 'LR1.1 the reply says success', is_array( $r ) && true === ( $r['success'] ?? null ) );
check( 'LR1.2 the reply carries buy_pass', is_array( $r ) && isset( $r['buy_pass'] ) && is_string( $r['buy_pass'] ) );
$row = $w->get_row( "SELECT * FROM wp_hdlv2_widget_leads WHERE id = $lead_id" );
check( 'LR1.3 it is a browser pass for this e-mail, practitioner, lead and the stored answers',
    $has && isset( $r['buy_pass'] ) && HDLV2_Offer::browser_pass_ok( $r['buy_pass'], 'reply+1@example.test', 206, $lead_id, HDLV2_Widget_Config::lead_digest( $row ) ) );
check( 'LR1.4 nothing about the pass is cached for a repeat', ! preg_grep( '/^b\d{10}\./', array_map( 'strval', array_merge( ...array_map( function ( $v ) { return is_array( $v ) ? array_values( $v ) : array( $v ); }, array_values( $GLOBALS['transients'] ) ) ) ) ) );
$again = post_lead( lead_params( 'reply+1@example.test' ) );
check( 'LR1.5 a repeat post inside 60 seconds: the cached reply, no buy_pass', is_array( $again ) && ! isset( $again['buy_pass'] ) && true === ( $again['success'] ?? null ) );

echo "── LR2. any switch off, or another practitioner: no buy_pass ──\n";
foreach ( array(
    'result-page switch absent' => array( array( 'hdlv2_ff_straight_to_form', 'hdlv2_ff_result_offer' ), 206 ),
    'straight-to-form absent'   => array( array( 'hdlv2_ff_straight_in_result_page', 'hdlv2_ff_result_offer' ), 206 ),
    'result offer absent'       => array( array( 'hdlv2_ff_straight_in_result_page', 'hdlv2_ff_straight_to_form' ), 206 ),
    'the offer is for 122'      => array( array( 'hdlv2_ff_straight_in_result_page', 'hdlv2_ff_straight_to_form', 'hdlv2_ff_result_offer' ), 122 ),
) as $label => $c ) {
    fresh( $c[0], $c[1] );
    $r = post_lead( lead_params( 'reply+2@example.test' ) );
    check( "LR2 $label: no buy_pass", is_array( $r ) && ! isset( $r['buy_pass'] ) && true === ( $r['success'] ?? null ) );
}

echo "── LR3. the new switch absent: the reply equals the base ──\n";
fresh( array( 'hdlv2_ff_straight_to_form', 'hdlv2_ff_result_offer' ) );
$r = post_lead( lead_params( 'reply+3@example.test' ) );
check( 'LR3.1 exactly { success, rate }', is_array( $r ) && array( 'success', 'rate' ) === array_keys( $r ) );
$w = fresh( array( 'hdlv2_ff_straight_to_form', 'hdlv2_ff_result_offer' ) );
post_lead( lead_params( 'reply+4@example.test' ) );
check( 'LR3.2 and the lead row is not read again', ! $w->sql_matching( 'hdlv2_widget_leads WHERE id =' ) );
done();
