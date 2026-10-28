<?php
/**
 * Shared WP stubs + fake $wpdb for the 30-day challenge scenarios (v0.48.23).
 * Standalone: no WordPress, no DB. The fake models exactly what
 * HDLV2_Challenge reads and writes: wp_hdlv2_challenge_days with its UNIQUE
 * (client_user_id, day_no), user meta, two options.
 */
error_reporting( E_ALL & ~E_DEPRECATED );
ini_set( 'error_log', '/dev/null' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['options']  = array();
$GLOBALS['usermeta'] = array();
$GLOBALS['timeline'] = array();
$GLOBALS['actions']  = array();
$GLOBALS['routes']   = array();
$GLOBALS['state']    = 'brand-new'; // what get_client_journey_state() answers

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['actions'][ $hook ][] = $cb; }
function register_rest_route( $ns, $route, $args ) { $GLOBALS['routes'][ $ns . $route ] = $args; }
function get_current_user_id() { return $GLOBALS['current_user'] ?? 0; }
function is_user_logged_in() { return get_current_user_id() > 0; }
function rest_ensure_response( $x ) { return $x; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_json_encode( $x ) { return json_encode( $x ); }
function absint( $x ) { return abs( (int) $x ); }
function sanitize_text_field( $x ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $x ) ) ); }
function sanitize_textarea_field( $x ) { return trim( strip_tags( (string) $x ) ); }
function esc_html( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES, 'UTF-8' ); }
function esc_url_raw( $x ) { return (string) $x; }
function wp_date( $fmt, $ts = null ) { return gmdate( $fmt, $ts ?? time() ); }
function add_query_arg( $key, $value, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value; }
function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['usermeta'][ $u ][ $k ] ?? ''; }
function add_user_meta( $u, $k, $v, $unique = false ) {
    if ( $unique && isset( $GLOBALS['usermeta'][ $u ][ $k ] ) ) return false;
    $GLOBALS['usermeta'][ $u ][ $k ] = $v;
    return 1;
}

class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

class HDLV2_Timeline {
    public static function add_entry( ...$args ) { $GLOBALS['timeline'][] = $args; return 900 + count( $GLOBALS['timeline'] ); }
}
class HDLV2_Compatibility {
    public static function get_client_journey_state( $u ) { return $GLOBALS['state']; }
}

class ChallengeWpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $rows = array();     // client_user_id => day_no => (object) row
    public $queries = array();
    public $deletes = array();
    private $args = array();
    private $next_id = 70;

    public function prepare( $sql, ...$args ) {
        $this->args = $args;
        foreach ( $args as $a ) {
            $sql = preg_replace( '/%[dsf]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $sql, 1 );
        }
        return $sql;
    }
    public function get_results( $sql ) {
        $this->queries[] = $sql;
        if ( false === strpos( $sql, 'hdlv2_challenge_days' ) || ! preg_match( '/client_user_id = (\d+)/', $sql, $m ) ) return array();
        $rows = $this->rows[ (int) $m[1] ] ?? array();
        ksort( $rows );
        return array_values( $rows );
    }
    public function query( $sql ) {
        $this->queries[] = $sql;
        if ( 0 === strpos( $sql, 'INSERT IGNORE INTO wp_hdlv2_challenge_days' ) ) {
            list( $uid, $day, $done_at, $note ) = $this->args;
            if ( isset( $this->rows[ $uid ][ $day ] ) ) return 0; // the UNIQUE key
            $this->insert_id = ++$this->next_id;
            $this->rows[ $uid ][ $day ] = (object) array( 'day_no' => $day, 'done_at' => $done_at, 'note' => $note );
            return 1;
        }
        if ( 0 === strpos( $sql, 'DELETE FROM wp_hdlv2_challenge_days' ) ) {
            $this->deletes[] = $sql;
            $uid = (int) $this->args[0];
            $n   = count( $this->rows[ $uid ] ?? array() );
            unset( $this->rows[ $uid ] );
            return $n;
        }
        return 0;
    }
    /** Seed $n done days for $uid, one a day ending on $last_date (UTC Y-m-d). */
    public function seed( $uid, $n, $last_date, $hour = '09:00:00' ) {
        for ( $d = 1; $d <= $n; $d++ ) {
            $date = gmdate( 'Y-m-d', strtotime( $last_date . ' UTC' ) - ( $n - $d ) * DAY_IN_SECONDS );
            $this->rows[ $uid ][ $d ] = (object) array( 'day_no' => $d, 'done_at' => $date . ' ' . $hour, 'note' => '' );
        }
    }
}

require_once dirname( __DIR__, 2 ) . '/includes/sprint-1/class-hdlv2-offer.php';
$challenge_src = dirname( __DIR__, 2 ) . '/includes/sprint-4/class-hdlv2-challenge.php';
if ( file_exists( $challenge_src ) ) {
    require_once $challenge_src;
}

$PASS = 0; $FAIL = 0;
function check( $label, $cond ) {
    global $PASS, $FAIL;
    if ( $cond ) { $PASS++; echo "  PASS  $label\n"; }
    else         { $FAIL++; echo "  FAIL  $label\n"; }
}
function status_of( $r ) { return $r instanceof WP_Error ? (int) ( $r->data['status'] ?? 0 ) : 200; }
function code_of( $r )   { return $r instanceof WP_Error ? $r->code : ''; }
function at( $utc )      { return strtotime( $utc . ' UTC' ); }
/** An enrolled client: user meta as HDLV2_Challenge::enrol() writes it. */
function enrol_client( $uid, $prac = 122, $started = '2026-10-28 08:00:00' ) {
    $GLOBALS['usermeta'][ $uid ]['hdlv2_challenge'] = json_encode( array(
        'started_at' => $started, 'source' => 'stripe', 'external_ref' => 'cs_test_1', 'invite_id' => 11, 'practitioner_id' => $prac,
    ) );
}
function fresh() {
    global $wpdb;
    $wpdb = new ChallengeWpdb();
    $GLOBALS['usermeta'] = array();
    $GLOBALS['timeline'] = array();
    $GLOBALS['options']  = array();
    $GLOBALS['state']    = 'brand-new';
    return $wpdb;
}
function done() {
    global $PASS, $FAIL;
    echo "\nPASS=$PASS FAIL=$FAIL\n";
    exit( 0 === $FAIL ? 0 : 1 );
}
if ( ! class_exists( 'HDLV2_Challenge' ) ) {
    check( 'includes/sprint-4/class-hdlv2-challenge.php exists', false );
    done();
}
