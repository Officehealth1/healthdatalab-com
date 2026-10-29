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
function wp_json_encode( $x, $flags = 0 ) { return json_encode( $x, $flags ); }
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
function get_userdata( $u ) { return isset( $GLOBALS['users'][ $u ] ) ? (object) array( 'ID' => $u, 'user_email' => $GLOBALS['users'][ $u ], 'first_name' => '', 'display_name' => strstr( $GLOBALS['users'][ $u ], '@', true ) ) : false; }
function wp_get_current_user() { return get_userdata( get_current_user_id() ) ?: (object) array( 'ID' => 0, 'user_email' => '' ); }
function hdlv2_is_staff_account( $u ) { return in_array( (int) $u, $GLOBALS['staff'] ?? array(), true ); }
function is_admin() { return false; }
function wp_unslash( $x ) { return $x; }
function apply_filters( $tag, $value ) { return $value; }
function home_url( $p = '' ) { return 'https://stby.test' . $p; }
class Redirected extends Exception {}
function wp_safe_redirect( $url ) { throw new Redirected( $url ); }
function add_user_meta( $u, $k, $v, $unique = false ) {
    if ( $unique && isset( $GLOBALS['usermeta'][ $u ][ $k ] ) ) return false;
    $GLOBALS['usermeta'][ $u ][ $k ] = $v;
    return 1;
}
function update_user_meta( $u, $k, $v ) { $GLOBALS['usermeta'][ $u ][ $k ] = $v; return true; }

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
    public $fail_insert = false;
    public $stage1_progress = array(); // client ids with a client record whose Stage 1 is done
    public $leads = array();           // "practitioner|email" of Stage 1 leads on file
    public $rejected = array();        // …of those, the ones the practitioner rejected
    public $invites = array();         // challenge invites by token (pending/opened, unexpired)
    public $practitioner_of = array(); // client id => practitioner_user_id on the live form_progress row
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
    public function get_var( $sql ) {
        $this->queries[] = $sql;
        if ( false !== strpos( $sql, 'SELECT practitioner_user_id FROM wp_hdlv2_form_progress' ) ) return $this->practitioner_of[ $this->args[0] ] ?? null;
        if ( false !== strpos( $sql, 'hdlv2_form_progress' ) ) return in_array( (int) $this->args[0], $this->stage1_progress, true ) ? '1' : null;
        if ( false !== strpos( $sql, 'hdlv2_widget_leads' ) ) {
            $key = $this->args[0] . '|' . strtolower( $this->args[1] );
            $out = in_array( $key, $this->leads, true ) && ! ( in_array( $key, $this->rejected, true ) && false !== strpos( $sql, "status <> 'rejected'" ) );
            return $out ? '1' : null;
        }
        return null;
    }
    public function get_row( $sql ) {
        $this->queries[] = $sql;
        if ( false !== strpos( $sql, 'hdlv2_widget_invites' ) && false !== strpos( $sql, "source = 'challenge'" ) ) return $this->invites[ $this->args[0] ] ?? null;
        return null;
    }
    public function query( $sql ) {
        $this->queries[] = $sql;
        if ( 0 === strpos( $sql, 'INSERT IGNORE INTO wp_hdlv2_challenge_days' ) && $this->fail_insert ) {
            $this->last_error = 'Table is full (simulated)';
            return false;
        }
        if ( 0 === strpos( $sql, 'DELETE FROM wp_hdlv2_timeline' ) ) {
            $this->deletes[] = $sql;
            return 1;
        }
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
/** v0.48.27 (D7) — a free enrolment, as the first tick of a client record writes it. */
function enrol_free_client( $uid, $prac = 122, $started = '2026-10-28 08:00:00' ) {
    $GLOBALS['usermeta'][ $uid ]['hdlv2_challenge'] = json_encode( array(
        'started_at' => $started, 'source' => 'free', 'external_ref' => '', 'invite_id' => 0, 'practitioner_id' => $prac,
    ) );
}
function fresh() {
    global $wpdb;
    $wpdb = new ChallengeWpdb();
    $GLOBALS['usermeta'] = array();
    $GLOBALS['timeline'] = array();
    $GLOBALS['options']  = array();
    $GLOBALS['state']    = 'brand-new';
    $GLOBALS['users']    = array();
    $GLOBALS['staff']    = array();
    $GLOBALS['current_user'] = 0;
    $_GET = array();
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
