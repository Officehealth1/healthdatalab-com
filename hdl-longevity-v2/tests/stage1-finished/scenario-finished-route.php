<?php
/**
 * v0.48.90 (plan 7, Push A) — POST /hdl/v1/stage1-finished against the REAL HDL_Stage1_Ticket::handle_finished().
 * Altituding asks it before it marks its mailing contact as finished, so a stranger who calls Altituding's function
 * with someone else's address marks nothing (gate row F15). Yes only with the key, under the limit, for a lead row
 * of that practitioner and address saved in the last hour. Read-only. The fake answers the lead lookup by the
 * clauses the code writes.
 *
 * Run:  php scenario-finished-route.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/../full-report/stubs.php';
function wp_salt( $scheme = 'auth' ) { return 'salt-one-for-tests'; }
function hdlv2_is_staff_account( $user_id ) { return false; }
load_sources();
$LOG = tempnam( sys_get_temp_dir(), 'hdlv2-finished-log' );
ini_set( 'log_errors', '1' );
ini_set( 'error_log', $LOG );

const EMAIL = 'visitor+r27-a@example.test';
const PRAC  = 122;

/** Lead rows (practitioner, e-mail, saved seconds ago); the SELECT holds only if every clause it writes holds. */
class FinishedWpdb extends FakeWpdb {
    public $leads = array();
    public $writes = 0;
    public function get_var( $sql ) {
        $this->queries[] = $sql;
        if ( false === strpos( $sql, 'hdlv2_widget_leads' ) ) return null;
        if ( ! preg_match( '/practitioner_user_id = (\d+)/', $sql, $p ) ) return null;
        if ( ! preg_match( "/visitor_email = '([^']*)'/", $sql, $e ) ) return null;
        if ( ! preg_match( "/created_at >= '([0-9: -]{19})'/", $sql, $c ) ) return null; // no window clause: never yes
        foreach ( $this->leads as $id => $l ) {
            $saved = gmdate( 'Y-m-d H:i:s', time() - $l['ago'] ); // the stubs' current_time('mysql') is gmdate
            // MySQL's default collation compares e-mails without case.
            if ( (int) $p[1] === $l['prac'] && 0 === strcasecmp( $e[1], $l['email'] ) && $saved >= $c[1] ) return (string) $id;
        }
        return null;
    }
    public function insert( $t, $d, $f = null ) { $this->writes++; return parent::insert( $t, $d, $f ); }
    public function update( $t, $d, $w, $f = null, $wf = null ) { $this->writes++; return 1; }
    public function query( $sql ) { $this->queries[] = $sql; $this->writes++; return 1; }
}

function setup( $leads = array() ) {
    global $wpdb;
    $GLOBALS['transients'] = array();
    $GLOBALS['options']    = array( 'hdlv2_db_version' => '3.34' );
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $wpdb = new FinishedWpdb();
    $wpdb->leads = $leads;
    return $wpdb;
}
function ask( $body = array(), $key = 'k-test-key' ) {
    if ( ! method_exists( 'HDL_Stage1_Ticket', 'handle_finished' ) ) return 'NO ROUTE';
    $body = array_merge( array( 'practitioner_id' => PRAC, 'email' => EMAIL ), $body );
    $body = array_filter( $body, function ( $v ) { return null !== $v; } );
    $headers = null === $key ? array() : array( 'X-HDL-Stage1-Ticket-Key' => $key );
    return HDL_Stage1_Ticket::handle_finished( new FakeRequest( $body, $headers ) );
}
$YES = array( 'finished' => true );
$NO  = array( 'finished' => false );
$JUST = array( 901 => array( 'prac' => PRAC, 'email' => EMAIL, 'ago' => 20 ) );

echo "── F1. the key ──\n";
setup( $JUST );
check( 'F1.1 no constant: 503 (the route is dark)', 503 === status_of( ask() ) );
define( 'HDL_STAGE1_TICKET_KEY', 'k-test-key' );
check( 'F1.2 wrong key: 401', 401 === status_of( ask( array(), 'wrong' ) ) );
check( 'F1.3 no key header: 401', 401 === status_of( ask( array(), null ) ) );

echo "── F2. the proof is our own lead row ──\n";
setup( $JUST );
check( 'F2.1 a lead of this practitioner and address, saved 20 s ago: yes', $YES === ask() );
setup( $JUST );
check( 'F2.2 the address in other capitals: yes (the column compares without case)', $YES === ask( array( 'email' => 'Visitor+R27-A@Example.test' ) ) );
setup();
check( 'F2.3 no lead at all (a stranger\'s guess): no', $NO === ask() );
setup( array( 902 => array( 'prac' => 207, 'email' => EMAIL, 'ago' => 20 ) ) );
check( 'F2.4 the address finished with another practitioner: no', $NO === ask() );
setup( array( 903 => array( 'prac' => PRAC, 'email' => EMAIL, 'ago' => 2 * HOUR_IN_SECONDS ) ) );
check( 'F2.5 a lead saved two hours ago (an old run, not this one): no', $NO === ask() );
setup( array( 904 => array( 'prac' => PRAC, 'email' => EMAIL, 'ago' => 59 * MINUTE_IN_SECONDS ) ) );
check( 'F2.6 a lead saved 59 minutes ago: yes (the window is one hour)', $YES === ask() );
setup( $JUST );
check( 'F2.7 asked again (a replay): the same yes, and nothing is written either time', $YES === ask() && $YES === ask() && 0 === $GLOBALS['wpdb']->writes );

echo "── F3. shapes: a no, never an error that tells more ──\n";
foreach ( array(
    'e-mail missing'        => array( 'email' => null ),
    'e-mail not an address' => array( 'email' => 'not-an-address' ),
    'e-mail as a list'      => array( 'email' => array( EMAIL ) ),
    'e-mail with a quote'   => array( 'email' => "x' OR '1'='1@example.test" ),
    'practitioner missing'  => array( 'practitioner_id' => null ),
    'practitioner zero'     => array( 'practitioner_id' => 0 ),
    'practitioner a list'   => array( 'practitioner_id' => array( PRAC ) ),
) as $label => $body ) {
    $w = setup( $JUST );
    check( "F3 $label: no", $NO === ask( $body ) && 0 === $w->writes );
}

echo "── F4. the limit ──\n";
setup( $JUST );
$last = null;
$limit = defined( 'HDL_Stage1_Ticket::FINISHED_RATE_LIMIT' ) ? HDL_Stage1_Ticket::FINISHED_RATE_LIMIT : 1;
for ( $i = 0; $i < $limit; $i++ ) { $last = ask(); }
check( 'F4.1 up to the limit: still yes', $YES === $last );
check( 'F4.2 one over the limit in the hour: 429 with Retry-After', 429 === status_of( $r = ask() ) && isset( $r->headers['Retry-After'] ) );

echo "── F5. the log ──\n";
$log = (string) file_get_contents( $LOG );
check( 'F5.1 no address is ever written to the log', false === stripos( $log, 'example.test' ) );

done();
