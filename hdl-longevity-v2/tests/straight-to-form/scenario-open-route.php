<?php
/**
 * v0.48.81 (plan 2h) — POST /hdl/v1/full-report-open against the REAL HDL_Stage1_Ticket::handle_open().
 * Altituding asks it after a buyer paid in the tab that holds the pass from their results e-mail. Yes only
 * when all hold, in order: the key; the switch; the limit; a Full Report ticket for external_ref; no staff
 * account; the pass matches the ticket's own e-mail and practitioner; no account, or one made in the last
 * 20 minutes; and ONE UPDATE that moves the ticket from pending to opened, within 15 minutes of the ticket
 * and before its end date. The fake answers the UPDATE and the account read by the clauses the code writes.
 *
 * Run:  php scenario-open-route.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/../full-report/stubs.php';
function wp_salt( $scheme = 'auth' ) { return 'salt-one-for-tests'; }
function hdlv2_is_staff_account( $user_id ) { return in_array( (int) $user_id, $GLOBALS['staff_ids'] ?? array(), true ); }
load_sources();
$LOG = tempnam( sys_get_temp_dir(), 'hdlv2-open-log' );
ini_set( 'log_errors', '1' );
ini_set( 'error_log', $LOG );

const REF   = 'cs_test_openroute000000000001';
const EMAIL = 'buyer+2h@example.test';
const PRAC  = 122;

/** Tickets by external_ref; an account per e-mail with its made-at age; the clauses decide. */
class OpenWpdb extends FakeWpdb {
    public $users = 'wp_users';
    public $registered_ago = array(); // user id → seconds since user_registered
    public function get_var( $sql ) {
        $this->queries[] = $sql;
        if ( preg_match( '/FROM wp_users WHERE ID = (\d+) AND user_registered >= UTC_TIMESTAMP\(\) - INTERVAL 20 MINUTE/', $sql, $m ) ) {
            $ago = $this->registered_ago[ (int) $m[1] ] ?? null;
            return null !== $ago && $ago <= 20 * 60 ? '1' : null;
        }
        return null;
    }
    public function query( $sql ) {
        $this->queries[] = $sql;
        if ( 0 !== strpos( ltrim( $sql ), 'UPDATE wp_hdlv2_widget_invites' ) ) return parent::query( $sql );
        $row = null;
        if ( preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
            foreach ( $this->tickets as $t ) { if ( (int) $t->id === (int) $m[1] ) $row = $t; }
        }
        if ( ! $row ) return 0;
        $holds = true;
        if ( false !== strpos( $sql, "source = 'full_report'" ) )                    $holds = $holds && 'full_report' === $row->source;
        if ( false !== strpos( $sql, "status = 'pending'" ) )                        $holds = $holds && 'pending' === $row->status;
        if ( false !== strpos( $sql, 'created_at >= NOW() - INTERVAL 15 MINUTE' ) )  $holds = $holds && $row->made_ago <= 15 * 60;
        if ( false !== strpos( $sql, 'expires_at > UTC_TIMESTAMP()' ) )             $holds = $holds && strtotime( $row->expires_at . ' UTC' ) > time();
        if ( ! $holds ) return 0;
        if ( preg_match( "/SET status = '(\w+)'/", $sql, $s ) ) $row->status = $s[1];
        return 1;
    }
}

function setup( $over = array(), $user = null ) {
    global $wpdb;
    $GLOBALS['transients'] = array();
    $GLOBALS['options']    = array( 'hdlv2_db_version' => '3.33', 'hdlv2_ff_straight_to_form' => '1' );
    $GLOBALS['email_users'] = array();
    $GLOBALS['staff_ids']   = array();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $wpdb = new OpenWpdb();
    $wpdb->tickets[ REF ] = (object) array_merge( array(
        'id' => 4401, 'practitioner_id' => PRAC, 'client_email' => EMAIL, 'client_name' => 'Buyer', 'token' => str_repeat( 'c', 64 ),
        'source' => 'full_report', 'status' => 'pending', 'external_ref' => REF, 'made_ago' => 60,
        'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 90 * DAY_IN_SECONDS ),
    ), $over );
    if ( $user ) {
        $GLOBALS['email_users'][ strtolower( EMAIL ) ] = $user['id'];
        $wpdb->registered_ago[ $user['id'] ] = $user['ago'];
    }
    return $wpdb;
}
function own_for( $email = EMAIL, $prac = PRAC ) { return method_exists( 'HDLV2_Offer', 'inbox_pass' ) ? HDLV2_Offer::inbox_pass( $email, $prac ) : time() . '.' . str_repeat( 'a', 64 ); }
function ask( $body = array(), $key = 'k-test-key' ) {
    if ( ! method_exists( 'HDL_Stage1_Ticket', 'handle_open' ) ) return 'NO ROUTE';
    $body = array_merge( array( 'external_ref' => REF, 'own' => own_for() ), $body );
    $body = array_filter( $body, function ( $v ) { return null !== $v; } );
    $headers = null === $key ? array() : array( 'X-HDL-Stage1-Ticket-Key' => $key );
    return HDL_Stage1_Ticket::handle_open( new FakeRequest( $body, $headers ) );
}
$YES = array( 'open' => true );
$NO  = array( 'open' => false );
function status_after( $w ) { return $w->tickets[ REF ]->status; }

echo "── OR1. the key ──\n";
setup();
check( 'OR1.1 no constant: 503', 503 === status_of( ask() ) );
define( 'HDL_STAGE1_TICKET_KEY', 'k-test-key' );
check( 'OR1.2 wrong key: 401', 401 === status_of( ask( array(), 'wrong' ) ) );
check( 'OR1.3 no key header: 401', 401 === status_of( ask( array(), null ) ) );

echo "── OR2. the switch ──\n";
$w = setup();
unset( $GLOBALS['options']['hdlv2_ff_straight_to_form'] );
check( 'OR2.1 switch absent: no, and the ticket stays pending', $NO === ask() && 'pending' === status_after( $w ) );
$w = setup();
$GLOBALS['options']['hdlv2_ff_straight_to_form'] = '0';
check( 'OR2.2 switch 0: no', $NO === ask() && 'pending' === status_after( $w ) );

echo "── OR3. shapes ──\n";
foreach ( array(
    'ref missing'      => array( 'external_ref' => null ),
    'ref with a quote' => array( 'external_ref' => "cs_test_x' OR 1=1" ),
    'ref too long'     => array( 'external_ref' => str_repeat( 'a', 129 ) ),
    'own missing'      => array( 'own' => null ),
    'own short'        => array( 'own' => '123.abc' ),
    'own upper-case'   => array( 'own' => time() . '.' . str_repeat( 'A', 64 ) ),
    'own as a list'    => array( 'own' => array( own_for() ) ),
) as $label => $body ) {
    $w = setup();
    check( "OR3 $label: no, ticket pending", $NO === ask( $body ) && 'pending' === status_after( $w ) );
}

echo "── OR4. the ticket ──\n";
$w = setup();
check( 'OR4.1 an unknown reference: no', $NO === ask( array( 'external_ref' => 'cs_test_unknown00000000000000' ) ) );
foreach ( array( 'paid_stage1', 'challenge', 'practitioner', 'timeline_link' ) as $src ) {
    $w = setup( array( 'source' => $src ) );
    check( "OR4.2 a $src ticket: no", $NO === ask() && 'pending' === status_after( $w ) );
}

echo "── OR5. a staff account ──\n";
$w = setup( array(), array( 'id' => 31, 'ago' => 60 ) );
$GLOBALS['staff_ids'] = array( 31 );
check( 'OR5.1 the ticket\'s e-mail is a staff account (even made a minute ago): no', $NO === ask() && 'pending' === status_after( $w ) );

echo "── OR6. the pass ──\n";
$w = setup();
check( 'OR6.1 a pass for another e-mail: no', $NO === ask( array( 'own' => own_for( 'someone+else@example.test' ) ) ) && 'pending' === status_after( $w ) );
check( 'OR6.2 a pass for another practitioner: no', $NO === ask( array( 'own' => own_for( EMAIL, 206 ) ) ) && 'pending' === status_after( $w ) );
$o = own_for();
check( 'OR6.3 one character changed: no', $NO === ask( array( 'own' => substr( $o, 0, -1 ) . ( 'a' === substr( $o, -1 ) ? 'b' : 'a' ) ) ) && 'pending' === status_after( $w ) );
check( 'OR6.4 out of date (4 days): no', $NO === ask( array( 'own' => method_exists( 'HDLV2_Offer', 'inbox_pass' ) ? HDLV2_Offer::inbox_pass( EMAIL, PRAC, time() - 4 * DAY_IN_SECONDS ) : 'x' ) ) );
$w = setup( array( 'client_email' => 'Buyer+2H@Example.TEST' ) );
check( 'OR6.5 the ticket\'s e-mail in other letter case: the pass still matches (yes)', $YES === ask() );

echo "── OR7 / OR8. the account ──\n";
$w = setup();
check( 'OR7.1 no account yet: yes', $YES === ask() );
check( 'OR7.2 the ticket is now opened', 'opened' === status_after( $w ) );
$w = setup( array(), array( 'id' => 52, 'ago' => 5 * 60 ) );
check( 'OR8.1 an account made 5 minutes ago (by this purchase): yes', $YES === ask() );
$w = setup( array(), array( 'id' => 53, 'ago' => 25 * 60 ) );
check( 'OR8.2 an account made 25 minutes ago: no, ticket pending', $NO === ask() && 'pending' === status_after( $w ) );
$w = setup( array(), array( 'id' => 54, 'ago' => 400 * DAY_IN_SECONDS ) );
check( 'OR8.3 an account with a year of history: no', $NO === ask() && 'pending' === status_after( $w ) );

echo "── OR9. once ──\n";
$w = setup();
$first  = ask();
$second = ask();
check( 'OR9.1 first ask yes, second ask no', $YES === $first && $NO === $second );
check( 'OR9.2 the second ask changed nothing', 'opened' === status_after( $w ) );

echo "── OR10. the one UPDATE ──\n";
$w = setup();
ask();
$upd = $w->sql_matching( 'UPDATE wp_hdlv2_widget_invites' );
$sql = $upd[0] ?? '';
check( 'OR10.1 exactly one UPDATE', 1 === count( $upd ) );
foreach ( array( "SET status = 'opened'", 'opened_at = ', 'WHERE id = 4401', "source = 'full_report'", "status = 'pending'", 'created_at >= NOW() - INTERVAL 15 MINUTE', 'expires_at > UTC_TIMESTAMP()' ) as $clause ) {
    check( "OR10.2 the UPDATE holds: $clause", false !== strpos( $sql, $clause ) );
}
foreach ( array( 'opened' => array( 'status' => 'opened' ), 'completed' => array( 'status' => 'completed' ), 'revoked' => array( 'status' => 'revoked' ),
                 'expired status' => array( 'status' => 'expired' ), 'made 16 minutes ago' => array( 'made_ago' => 16 * 60 ),
                 'past its end date' => array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ) as $label => $over ) {
    $w = setup( $over );
    $before = status_after( $w );
    check( "OR10.3 a ticket $label: no, unchanged", $NO === ask() && $before === status_after( $w ) );
}
$w = setup( array( 'made_ago' => 14 * 60 ) );
check( 'OR10.4 a ticket made 14 minutes ago: yes', $YES === ask() );

echo "── OR11. the answer's shape ──\n";
$w = setup();
$y = ask();
$w = setup();
$n = ask( array( 'own' => own_for( 'x@example.test' ) ) );
check( 'OR11.1 a yes is exactly { open: true } (no link, no token, no e-mail)', is_array( $y ) && array( 'open' ) === array_keys( $y ) && true === $y['open'] );
check( 'OR11.2 a no is exactly { open: false }', is_array( $n ) && array( 'open' ) === array_keys( $n ) && false === $n['open'] );

echo "── OR12. the limit ──\n";
$w = setup();
for ( $i = 1; $i <= 120; $i++ ) { ask( array( 'external_ref' => 'cs_test_unknown00000000000000' ) ); }
$r = ask();
check( 'OR12.1 the 121st ask in an hour from one caller: 429 with Retry-After', 429 === status_of( $r ) && $r instanceof WP_REST_Response && (int) ( $r->headers['Retry-After'] ?? 0 ) > 0 );
check( 'OR12.2 and the ticket is not touched', 'pending' === status_after( $w ) );
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
check( 'OR12.3 another caller is not limited', $YES === ask() );

echo "── OR13. the log ──\n";
$log = (string) file_get_contents( $LOG );
$lines = array_filter( explode( "\n", $log ), function ( $l ) { return false !== strpos( $l, 'full-report-open' ); } );
check( 'OR13.1 the route logged its asks', count( $lines ) > 20 );
check( 'OR13.2 a yes line names the ticket row only', (bool) preg_grep( '/\[HDL full-report-open\] yes invite=4401$/', $lines ) );
foreach ( array( 'flag_off', 'shape', 'no_ticket', 'staff', 'pass', 'account', 'claim', 'limit' ) as $code ) {
    check( "OR13.3 a no line with code $code", (bool) preg_grep( '/\[HDL full-report-open\] no ' . $code . '$/', $lines ) );
}
$private = array( EMAIL, strtolower( EMAIL ), 'buyer', REF, 'cs_test', str_repeat( 'c', 64 ), 'k-test-key', substr( own_for(), 11, 20 ), 'example.test' );
$leak = array_filter( $private, function ( $s ) use ( $log ) { return false !== stripos( $log, $s ); } );
check( 'OR13.4 no line holds the e-mail, the reference, the ticket, the key or a pass (' . implode( ',', $leak ) . ')', ! $leak );
@unlink( $LOG );

echo "── OR14. the ticket route is untouched ──\n";
$out = array();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/../full-report/scenario-ticket-full-report.php' ) . ' 2>/dev/null', $out, $code );
check( 'OR14.1 tests/full-report/scenario-ticket-full-report.php still passes: ' . end( $out ), 0 === $code );
done();
