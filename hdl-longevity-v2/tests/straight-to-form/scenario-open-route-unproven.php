<?php
/**
 * v0.48.85 (plan 2k) — POST /hdl/v1/full-report-open with a BROWSER pass (a buyer from the result page),
 * against the REAL HDL_Stage1_Ticket::handle_open(). After the shared checks (key, switch, limit, shape,
 * ticket, staff): hdlv2_ff_straight_in_result_page on; the newest lead for the ticket's practitioner and
 * e-mail is the pass's lead; the pass checks against the digest of that row now and is under 60 minutes;
 * the lead is confirmed by THIS ticket; an account exists and was made by this purchase; a live form
 * record for it; the one-row claim; the mark hdlv2_unproven_entry. The yes answers the form's own address.
 * The inbox-pass path is unchanged. The fake answers each query by the clauses the code writes.
 *
 * Run:  php scenario-open-route-unproven.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/../full-report/stubs.php';
function wp_salt( $scheme = 'auth' ) { return 'salt-one-for-tests'; }
function hdlv2_is_staff_account( $user_id ) { return in_array( (int) $user_id, $GLOBALS['staff_ids'] ?? array(), true ); }
load_sources();
$LOG = tempnam( sys_get_temp_dir(), 'hdlv2-unproven-log' );
ini_set( 'log_errors', '1' );
ini_set( 'error_log', $LOG );
define( 'HDL_STAGE1_TICKET_KEY', 'k-test-key' );

const REF   = 'cs_test_unproven0000000000001';
const EMAIL = 'buyer+2k@example.test';
const PRAC  = 206;
const LEAD  = 2401;
const UID   = 777;
const FTOK  = 'dededededededededededededededededededededededededededededededede';

class UnprovenWpdb extends FakeWpdb {
    public $users = 'wp_users';
    public $registered_ago = array();
    public $leads = array(); // id → row
    public $forms = array(); // rows: client_user_id, practitioner_user_id, token, expires_in, deleted
    public $marks_at_claim = null; // F3: how many mark writes had happened when the claim UPDATE ran
    public function get_row( $sql ) {
        if ( false !== strpos( $sql, 'hdlv2_widget_leads' ) ) {
            $this->queries[] = $sql;
            if ( ! preg_match( '/practitioner_user_id = (\d+)/', $sql, $p ) || ! preg_match( "/visitor_email = '([^']*)'/", $sql, $e ) || false === strpos( $sql, 'ORDER BY id DESC' ) ) return null;
            $best = null;
            foreach ( $this->leads as $l ) {
                if ( (int) $l->practitioner_user_id === (int) $p[1] && 0 === strcasecmp( $l->visitor_email, $e[1] ) && ( ! $best || $l->id > $best->id ) ) $best = $l;
            }
            return $best ? clone $best : null;
        }
        if ( false !== strpos( $sql, 'hdlv2_form_progress' ) ) {
            $this->queries[] = $sql;
            foreach ( array_reverse( $this->forms ) as $f ) {
                $holds = preg_match( '/client_user_id = (\d+)/', $sql, $c ) && (int) $c[1] === $f['client_user_id']
                    && preg_match( '/practitioner_user_id = (\d+)/', $sql, $p ) && (int) $p[1] === $f['practitioner_user_id']
                    && false !== strpos( $sql, 'deleted_at IS NULL' ) && ! $f['deleted']
                    && false !== strpos( $sql, 'token_expires_at > UTC_TIMESTAMP()' ) && $f['expires_in'] > 0;
                if ( $holds ) return (object) array( 'token' => $f['token'] );
            }
            return null;
        }
        return parent::get_row( $sql );
    }
    public function get_var( $sql ) {
        $this->queries[] = $sql;
        if ( false !== strpos( $sql, 'FROM wp_users u' ) && preg_match( '/u\.ID = (\d+)/', $sql, $m ) && preg_match( '/t\.id = (\d+)/', $sql, $t ) ) {
            $ago = $this->registered_ago[ (int) $m[1] ] ?? null;
            $row = null;
            foreach ( $this->tickets as $x ) { if ( (int) $x->id === (int) $t[1] ) $row = $x; }
            if ( null === $ago || ! $row ) return null;
            $ok = false !== strpos( $sql, 'TIMESTAMPDIFF(SECOND, u.user_registered, UTC_TIMESTAMP()) <= TIMESTAMPDIFF(SECOND, t.created_at, NOW()) + 5' ) && $ago <= $row->made_ago + 5
               && false !== strpos( $sql, 'u.user_registered >= UTC_TIMESTAMP() - INTERVAL 20 MINUTE' ) && $ago <= 20 * 60;
            return $ok ? '1' : null;
        }
        return null;
    }
    public function query( $sql ) {
        $this->queries[] = $sql;
        if ( 0 !== strpos( ltrim( $sql ), 'UPDATE wp_hdlv2_widget_invites' ) ) return parent::query( $sql );
        $this->marks_at_claim = count( marks() );
        $row = null;
        if ( preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) { foreach ( $this->tickets as $t ) { if ( (int) $t->id === (int) $m[1] ) $row = $t; } }
        if ( ! $row ) return 0;
        $holds = false !== strpos( $sql, "status = 'pending'" ) && 'pending' === $row->status
            && false !== strpos( $sql, "source = 'full_report'" ) && 'full_report' === $row->source
            && false !== strpos( $sql, 'created_at >= NOW() - INTERVAL 15 MINUTE' ) && $row->made_ago <= 15 * 60;
        if ( ! $holds ) return 0;
        $row->status = 'opened';
        return 1;
    }
}
function answers( $q5 = 'b' ) { return json_encode( array( 'q1_age' => 44, 'q1_sex' => 'male', 'q2a' => 3, 'q2b' => 'even', 'q3' => 'b', 'q4' => 'b', 'q5' => $q5, 'q6' => 'b', 'q7' => 'a', 'q8' => 'b', 'q9' => 'b' ) ); }
function lead_row( $over = array() ) {
    return (object) array_merge( array( 'id' => LEAD, 'practitioner_user_id' => PRAC, 'visitor_email' => EMAIL, 'visitor_name' => 'Buyer', 'visitor_phone' => '',
        'stage1_data' => answers(), 'status' => 'confirmed', 'invite_id' => 4401 ), $over );
}
/** A paid result-page buyer: ticket 4401 (1 minute old), lead 2401 confirmed by it, account 777 made with it, a live form record. */
function setup( $t = array(), $l = array(), $acct = array( 'id' => UID, 'ago' => 60 ), $forms = null ) {
    global $wpdb;
    $GLOBALS['transients'] = array();
    $GLOBALS['options'] = array( 'hdlv2_db_version' => '3.34', 'hdlv2_ff_straight_to_form' => '1', 'hdlv2_ff_straight_in_result_page' => '1' );
    $GLOBALS['email_users'] = array();
    $GLOBALS['staff_ids'] = array();
    $GLOBALS['meta_writes'] = array();
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $wpdb = new UnprovenWpdb();
    $wpdb->tickets[ REF ] = (object) array_merge( array( 'id' => 4401, 'practitioner_id' => PRAC, 'client_email' => EMAIL, 'client_name' => 'Buyer', 'token' => str_repeat( 'c', 64 ),
        'source' => 'full_report', 'status' => 'pending', 'external_ref' => REF, 'made_ago' => 60, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 90 * DAY_IN_SECONDS ) ), $t );
    $wpdb->leads[ LEAD ] = lead_row( $l );
    if ( $acct ) { $GLOBALS['email_users'][ strtolower( EMAIL ) ] = $acct['id']; $wpdb->registered_ago[ $acct['id'] ] = $acct['ago']; }
    $wpdb->forms = null !== $forms ? $forms : array( array( 'client_user_id' => UID, 'practitioner_user_id' => PRAC, 'token' => FTOK, 'expires_in' => 90, 'deleted' => false ) );
    return $wpdb;
}
$CAN = method_exists( 'HDLV2_Offer', 'browser_pass' ) && method_exists( 'HDLV2_Widget_Config', 'lead_digest' );
function bpass( $over = array(), $time = null ) {
    global $CAN;
    if ( ! $CAN ) return 'b' . time() . '.' . str_repeat( 'a', 64 );
    $o = array_merge( array( 'email' => EMAIL, 'prac' => PRAC, 'lead' => LEAD, 'answers' => answers() ), $over );
    return HDLV2_Offer::browser_pass( $o['email'], $o['prac'], $o['lead'], HDLV2_Widget_Config::lead_digest( lead_row( array( 'id' => $o['lead'], 'stage1_data' => $o['answers'] ) ) ), $time );
}
function ask( $own = null ) {
    return HDL_Stage1_Ticket::handle_open( new FakeRequest( array( 'external_ref' => REF, 'own' => null === $own ? bpass() : $own ), array( 'X-HDL-Stage1-Ticket-Key' => 'k-test-key' ) ) );
}
function st( $w ) { return $w->tickets[ REF ]->status; }
function marks() { return array_values( array_filter( $GLOBALS['meta_writes'] ?? array(), function ( $m ) { return 'hdlv2_unproven_entry' === ( $m[1][1] ?? '' ); } ) ); }
$NO = array( 'open' => false );
/** The refusal's code is on the newest log line (the log file holds every case before it). */
function log_has( $code ) { global $LOG; $lines = array_values( array_filter( explode( "\n", (string) file_get_contents( $LOG ) ) ) ); return (bool) preg_match( '/\[HDL full-report-open\] no ' . $code . '$/', (string) end( $lines ) ); }
function no_with( $label, $code, $w, $r ) { global $NO; check( "UO $label: no ($code), ticket pending, no mark", $NO === $r && 'pending' === st( $w ) && ! marks() && log_has( $code ) ); }

echo "── UO1. the yes ──\n";
$w = setup();
$r = ask();
check( 'UO1.1 open with the form\'s own address', is_array( $r ) && true === ( $r['open'] ?? null ) && 'https://stby.example.test/assessment/?token=' . FTOK === ( $r['url'] ?? '' ) );
check( 'UO1.2 the answer holds exactly open and url', is_array( $r ) && array( 'open', 'url' ) === array_keys( $r ) );
check( 'UO1.3 the ticket is claimed (opened)', 'opened' === st( $w ) );
$m = marks();
check( 'UO1.4 the mark hdlv2_unproven_entry = this ticket, on the buyer\'s account', 1 === count( $m ) && 'update' === $m[0][0] && UID === (int) $m[0][1][0] && 4401 === (int) $m[0][1][2] );
check( 'UO1.5 never the e-mail\'s ?invite= link', is_array( $r ) && false === strpos( (string) ( $r['url'] ?? '' ), 'invite=' ) );
check( 'UO1.6 the one UPDATE ran once', 1 === count( $w->sql_matching( 'UPDATE wp_hdlv2_widget_invites' ) ) );
check( 'UO1.7 a second ask: no (claimed once), and the first ask\'s mark is never deleted', $NO === ask() && ! array_filter( marks(), function ( $m ) { return 'delete' === $m[0]; } ) );
$w = setup();
ask();
check( 'UO1.8 the mark is written before the claim (F3)', 1 === $w->marks_at_claim );
$w = setup();
$GLOBALS['usermeta'] = array( UID => array( 'hdlv2_unproven_entry' => '4400' ) );
check( 'UO1.9 an account already marked: the yes, the mark not rewritten', true === ( ask()['open'] ?? null ) && ! marks() );
$GLOBALS['usermeta'] = array();

echo "── UO2. refusals, each with its code ──\n";
$w = setup(); unset( $GLOBALS['options']['hdlv2_ff_straight_in_result_page'] ); no_with( '2.1 result-page switch absent', 'flag_off', $w, ask() );
$w = setup(); unset( $GLOBALS['options']['hdlv2_ff_straight_to_form'] ); no_with( '2.2 straight-to-form switch absent', 'flag_off', $w, ask() );
$w = setup(); no_with( '2.3 a browser pass in a bad shape', 'shape', $w, ask( 'b123.' . str_repeat( 'a', 64 ) ) );
$w = setup(); no_with( '2.3b the old shape with a lead id', 'shape', $w, ask( 'b' . time() . '.' . LEAD . '.' . str_repeat( 'a', 64 ) ) );
$w = setup(); no_with( '2.4 a pass for another e-mail', 'pass', $w, ask( bpass( array( 'email' => 'other@example.test' ) ) ) );
$w = setup(); no_with( '2.5 a pass for another practitioner', 'pass', $w, ask( bpass( array( 'prac' => 122 ) ) ) );
$w = setup(); no_with( '2.6 another browser re-sent the answers (the row changed after the pass)', 'pass', $w, ask( bpass( array( 'answers' => answers( 'd' ) ) ) ) );
$w = setup(); no_with( '2.7 a pass 61 minutes old', 'pass', $w, ask( bpass( array(), time() - 61 * MINUTE_IN_SECONDS ) ) );
$w = setup(); no_with( '2.8 a pass from the future', 'pass', $w, ask( bpass( array(), time() + 5 * MINUTE_IN_SECONDS ) ) );
$w = setup(); $w->leads[ LEAD + 5 ] = lead_row( array( 'id' => LEAD + 5 ) ); no_with( '2.9 the pass\'s lead is not the newest for this e-mail', 'pass', $w, ask() );
$w = setup(); no_with( '2.10 a pass made over another lead id', 'pass', $w, ask( bpass( array( 'lead' => LEAD + 1 ) ) ) );
$w = setup(); $w->leads = array(); no_with( '2.10b no lead on file for this e-mail', 'lead', $w, ask() );
$w = setup( array(), array( 'status' => 'pending' ) ); no_with( '2.11 the lead is still pending', 'lead', $w, ask() );
$w = setup( array(), array( 'invite_id' => 4400 ) ); no_with( '2.12 the lead was confirmed by another ticket', 'lead', $w, ask() );
$w = setup( array(), array( 'invite_id' => null ) ); no_with( '2.13 the lead was confirmed by the practitioner (no ticket)', 'lead', $w, ask() );
$w = setup( array(), array(), null ); no_with( '2.14 no account yet (never "none yet" for a browser pass)', 'account', $w, ask() );
$w = setup( array(), array(), array( 'id' => UID, 'ago' => 10 * 60 ) ); no_with( '2.15 the account is older than the ticket', 'account', $w, ask() );
$w = setup( array( 'made_ago' => 25 * 60 ), array(), array( 'id' => UID, 'ago' => 25 * 60 ) ); no_with( '2.16 an account 25 minutes old', 'account', $w, ask() );
$w = setup(); $GLOBALS['staff_ids'] = array( UID ); no_with( '2.17 a staff account', 'staff', $w, ask() );
$w = setup( array(), array(), array( 'id' => UID, 'ago' => 60 ), array() ); no_with( '2.18 no form record', 'form', $w, ask() );
$w = setup( array(), array(), array( 'id' => UID, 'ago' => 60 ), array( array( 'client_user_id' => UID, 'practitioner_user_id' => PRAC, 'token' => FTOK, 'expires_in' => 0, 'deleted' => false ) ) ); no_with( '2.19 the form token ran out', 'form', $w, ask() );
$w = setup( array(), array(), array( 'id' => UID, 'ago' => 60 ), array( array( 'client_user_id' => UID, 'practitioner_user_id' => 122, 'token' => FTOK, 'expires_in' => 90, 'deleted' => false ) ) ); no_with( '2.20 the only form record is another practitioner\'s', 'form', $w, ask() );
$w = setup( array(), array(), array( 'id' => UID, 'ago' => 60 ), array( array( 'client_user_id' => UID, 'practitioner_user_id' => PRAC, 'token' => FTOK, 'expires_in' => 90, 'deleted' => true ) ) ); no_with( '2.21 the form record is deleted', 'form', $w, ask() );
// F3 (review): the mark is written before the claim and stays when the claim fails. Deleting it would let
// a second ask from the browser that went in remove the first ask's mark; a mark left on an account that
// never went in costs the owner one reset at their next purchase-link opening.
function marked_kept() { $m = marks(); return 1 === count( $m ) && 'update' === $m[0][0] && UID === (int) $m[0][1][0]; }
$w = setup( array( 'status' => 'opened' ) ); check( 'UO 2.22 the ticket already opened: no (claim), the mark written first is kept', $NO === ask() && 'opened' === st( $w ) && marked_kept() && log_has( 'claim' ) );
$w = setup( array( 'made_ago' => 16 * 60 ), array(), array( 'id' => UID, 'ago' => 16 * 60 ) ); check( 'UO 2.23 a ticket made 16 minutes ago: no (claim), ticket pending, the mark kept', $NO === ask() && 'pending' === st( $w ) && marked_kept() && log_has( 'claim' ) );
$w = setup( array( 'source' => 'challenge' ) ); no_with( '2.24 a challenge ticket', 'no_ticket', $w, ask() );

echo "── UO3. the inbox pass is unchanged ──\n";
$w = setup( array(), array(), null );
$r = ask( HDLV2_Offer::inbox_pass( EMAIL, PRAC ) );
check( 'UO3.1 an inbox pass, no account yet: exactly { open: true }, no url, no mark', array( 'open' => true ) === $r && ! marks() );
check( 'UO3.2 its log line is the base one', (bool) preg_match( '/\[HDL full-report-open\] yes invite=4401$/m', (string) file_get_contents( $LOG ) ) );
$w = setup( array(), array(), null ); unset( $GLOBALS['options']['hdlv2_ff_straight_in_result_page'] );
check( 'UO3.3 the inbox pass works with the result-page switch absent', array( 'open' => true ) === ask( HDLV2_Offer::inbox_pass( EMAIL, PRAC ) ) );
$w = setup( array(), array(), array( 'id' => UID, 'ago' => 60 ) );
check( 'UO3.4 an inbox pass into the account this purchase made: still { open: true } alone', array( 'open' => true ) === ask( HDLV2_Offer::inbox_pass( EMAIL, PRAC ) ) );

echo "── UO4. the log ──\n";
$log = (string) file_get_contents( $LOG );
check( 'UO4.1 a browser yes line names the ticket row and the kind only', (bool) preg_match( '/\[HDL full-report-open\] yes invite=4401 browser$/m', $log ) );
$private = array( EMAIL, 'buyer+2k', REF, 'cs_test', FTOK, str_repeat( 'c', 64 ), 'k-test-key', substr( bpass(), 12, 20 ), 'example.test', 'assessment' );
$leak = array_filter( $private, function ( $s ) use ( $log ) { return false !== stripos( $log, $s ); } );
check( 'UO4.2 no line holds the e-mail, the reference, a token, the key, a pass or the address (' . implode( ',', $leak ) . ')', ! $leak );
@unlink( $LOG );

echo "── UO5. plan 2h's runner still passes ──\n";
$out = array();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/scenario-open-route.php' ) . ' 2>/dev/null', $out, $code );
check( 'UO5.1 scenario-open-route.php: ' . end( $out ), 0 === $code );
done();
