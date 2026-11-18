<?php
/**
 * v0.48.85 (plan 2k) — inbox proof. When a Full Report ticket's ?invite= link is opened and the account
 * carries hdlv2_unproven_entry (it went straight in on a browser pass), the inbox has now proved itself:
 *   - opened in the browser already signed in as that account: the mark goes, nothing else;
 *   - opened anywhere else: every session of the account ends, every form record gets a new token (end
 *     dates, answers and everything else untouched), then the mark goes. The old address is refused.
 * With the switch on or off. Against the REAL HDL_Stage1_Ticket::inbox_proof(), and the ?invite= handler
 * in hdl-longevity-v2.php is read to prove it calls it before anyone is signed in.
 *
 * Run:  php scenario-inbox-proof.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/../full-report/stubs.php';
function wp_salt( $scheme = 'auth' ) { return 'salt-one-for-tests'; }
class WP_Session_Tokens {
    public static $ended = array();
    private $uid;
    public static function get_instance( $uid ) { $o = new self(); $o->uid = (int) $uid; return $o; }
    public function destroy_all() { self::$ended[] = $this->uid; }
}
load_sources();
$LOG = tempnam( sys_get_temp_dir(), 'hdlv2-proof-log' );
ini_set( 'log_errors', '1' );
ini_set( 'error_log', $LOG );

const UID = 777;
const OLD = 'dededededededededededededededededededededededededededededededede';

class ProofWpdb extends FakeWpdb {
    public $forms = array(); // id → array( client_user_id, token, token_expires_at, stage2_data )
    public $fail_update = false;
    public function get_col( $sql ) {
        $this->queries[] = $sql;
        if ( false === strpos( $sql, 'hdlv2_form_progress' ) || ! preg_match( '/client_user_id = (\d+)/', $sql, $m ) ) return array();
        return array_keys( array_filter( $this->forms, function ( $f ) use ( $m ) { return (int) $f['client_user_id'] === (int) $m[1]; } ) );
    }
    public function update( $table, $data, $where, $format = null, $where_format = null ) {
        $this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
        if ( false === strpos( $table, 'hdlv2_form_progress' ) ) return 1;
        if ( $this->fail_update ) return false;
        foreach ( $data as $k => $v ) { $this->forms[ (int) $where['id'] ][ $k ] = $v; }
        return 1;
    }
}
function setup( $marked = true ) {
    global $wpdb;
    $GLOBALS['options'] = array();
    $GLOBALS['meta_writes'] = array();
    $GLOBALS['usermeta'] = $marked ? array( UID => array( 'hdlv2_unproven_entry' => '4401' ) ) : array();
    WP_Session_Tokens::$ended = array();
    $wpdb = new ProofWpdb();
    $wpdb->forms = array(
        371 => array( 'client_user_id' => UID, 'token' => OLD, 'token_expires_at' => '2027-02-16 10:00:00', 'stage2_data' => '{"saved":"answers"}' ),
        372 => array( 'client_user_id' => UID, 'token' => str_repeat( 'e', 64 ), 'token_expires_at' => '2027-03-01 10:00:00', 'stage2_data' => '' ),
        900 => array( 'client_user_id' => 12, 'token' => str_repeat( 'f', 64 ), 'token_expires_at' => '2027-01-01 10:00:00', 'stage2_data' => 'other' ),
    );
    return $wpdb;
}
function proof( $current ) { return method_exists( 'HDL_Stage1_Ticket', 'inbox_proof' ) ? HDL_Stage1_Ticket::inbox_proof( UID, $current ) : 'NO METHOD'; }
function deleted_mark() { return (bool) array_filter( $GLOBALS['meta_writes'], function ( $m ) { return 'delete' === $m[0] && UID === (int) $m[1][0] && 'hdlv2_unproven_entry' === $m[1][1]; } ); }
function token_updates( $w ) { return array_values( array_filter( $w->updates, function ( $u ) { return false !== strpos( $u['table'], 'hdlv2_form_progress' ); } ) ); }

echo "── IP1. another browser opens the e-mail's link ──\n";
$w = setup();
$r = proof( 0 );
check( 'IP1.1 answer: cleared', 'cleared' === $r );
check( 'IP1.2 every session of the account ended', array( UID ) === WP_Session_Tokens::$ended );
$t = token_updates( $w );
check( 'IP1.3 both of the account\'s form records got a new token', 2 === count( $t ) && OLD !== $w->forms[371]['token'] && str_repeat( 'e', 64 ) !== $w->forms[372]['token'] );
check( 'IP1.4 each new token is 64 hex and they differ', preg_match( '/^[a-f0-9]{64}$/', $w->forms[371]['token'] ) && preg_match( '/^[a-f0-9]{64}$/', $w->forms[372]['token'] ) && $w->forms[371]['token'] !== $w->forms[372]['token'] );
check( 'IP1.5 only the token was written (end dates and answers unchanged)', array( array( 'token' ), array( 'token' ) ) === array_map( function ( $u ) { return array_keys( $u['data'] ); }, $t )
    && '2027-02-16 10:00:00' === $w->forms[371]['token_expires_at'] && '{"saved":"answers"}' === $w->forms[371]['stage2_data'] );
check( 'IP1.6 another account\'s record untouched', str_repeat( 'f', 64 ) === $w->forms[900]['token'] );
check( 'IP1.7 the mark is gone', deleted_mark() );
check( 'IP1.8 the old address no longer matches any record (refused by the ?token= handler)', ! in_array( OLD, array_column( $w->forms, 'token' ), true ) );
$w = setup();
proof( 55 );
check( 'IP1.9 signed in as someone else counts as another browser', array( UID ) === WP_Session_Tokens::$ended && deleted_mark() );

echo "── IP2. the same browser opens it ──\n";
$w = setup();
check( 'IP2.1 answer: same', 'same' === proof( UID ) );
check( 'IP2.2 the mark is gone and nothing else changed', deleted_mark() && ! WP_Session_Tokens::$ended && ! token_updates( $w ) && OLD === $w->forms[371]['token'] );

echo "── IP3. no mark: equal to the base ──\n";
$w = setup( false );
check( 'IP3.1 answer: nothing; no session ended, no token, no meta write', '' === proof( 0 ) && ! WP_Session_Tokens::$ended && ! token_updates( $w ) && ! $GLOBALS['meta_writes'] );
check( 'IP3.2 user 0: nothing', '' === ( method_exists( 'HDL_Stage1_Ticket', 'inbox_proof' ) ? HDL_Stage1_Ticket::inbox_proof( 0, 0 ) : 'x' ) );

echo "── IP4. the switch off: the mark is still cleared ──\n";
$w = setup();
$GLOBALS['options'] = array( 'hdlv2_ff_straight_in_result_page' => '0', 'hdlv2_ff_straight_to_form' => '0' );
check( 'IP4.1 cleared with both switches off', 'cleared' === proof( 0 ) && deleted_mark() && array( UID ) === WP_Session_Tokens::$ended );

echo "── IP5. a token write fails: the mark stays, so the next opening tries again ──\n";
$w = setup();
$w->fail_update = true;
check( 'IP5.1 answer: failed, the mark kept', 'failed' === proof( 0 ) && ! deleted_mark() );

echo "── IP6. the log ──\n";
$log = (string) file_get_contents( $LOG );
foreach ( array( 'cleared', 'same', 'failed' ) as $code ) { check( "IP6.1 a proof line with code $code", (bool) preg_match( '/\[HDL full-report-open\] proof ' . $code . '$/m', $log ) ); }
check( 'IP6.2 no token in the log', false === strpos( $log, OLD ) && ! preg_match( '/[a-f0-9]{64}/', $log ) );
@unlink( $LOG );

echo "── IP7. the ?invite= handler calls it before anyone is signed in ──\n";
$src = (string) file_get_contents( __DIR__ . '/../../hdl-longevity-v2.php' );
$start = strpos( $src, "if ( ! empty( \$_GET['invite'] ) ) {" );
$end = strpos( $src, '// ── ?prac_login=TOKEN', (int) $start );
$block = $start && $end ? substr( $src, $start, $end - $start ) : '';
$call = strpos( $block, 'HDL_Stage1_Ticket::inbox_proof( $user_id, get_current_user_id() )' );
$staff = strpos( $block, 'hdlv2_render_staff_sign_in_card();' );
$cookie = strpos( $block, 'wp_set_auth_cookie( $user_id, false );' );
check( 'IP7.1 the call is in the ?invite= block', false !== $call );
check( 'IP7.2 after the staff check and before the sign-in cookie', false !== $call && false !== $staff && false !== $cookie && $staff < $call && $call < $cookie );
$line = $call ? substr( $block, strrpos( substr( $block, 0, $call ), "\n" ), 200 ) : '';
check( 'IP7.3 only for a full_report ticket, and no switch around it', false !== strpos( $line, "'full_report' === \$invite->source" ) && false === strpos( $line, 'get_option' ) );
done();
