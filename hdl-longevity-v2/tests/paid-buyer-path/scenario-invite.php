<?php
/**
 * Subprocess scenario runner for the ?invite= login in hdl-longevity-v2.php,
 * the paid buyer's cases (2026-11-17; gate fixes G1 + G2 the same day). One
 * case per process: the card and redirect paths exit.
 *
 * Standalone: stubs WordPress, fakes $wpdb, includes the REAL plugin main
 * file, captures the init closure and runs it. Markers on stdout:
 *   AUTH_COOKIE_SET:<uid>   wp_set_auth_cookie
 *   SESSION_ENDED           the signed-in session was destroyed
 *   COOKIE_CLEARED          the auth cookie was cleared
 *   DB_UPDATE:<json>        a write to the ticket row
 *   REDIRECT:<url>          wp_safe_redirect
 *   STATUS:<code>           status_header (cards)
 *   HANDLER_RETURNED        the closure returned without exiting
 *
 * Run:  php scenario-invite.php <case>
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
date_default_timezone_set( 'Etc/GMT-1' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPINC', 'wp-includes' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$CASE = $argv[1] ?? 'used-full-report'; // CI runs every scenario-* with no argument

$GLOBALS['captured_init'] = null;
$GLOBALS['invite_row']    = null; // the one ticket row this token has, any status
$GLOBALS['vars']          = array(); // get_var answers by a substring of the query
$GLOBALS['progress']      = null;
$GLOBALS['me']            = null; // the signed-in account, or null
$GLOBALS['staff']         = array();

function plugin_dir_path( $f ) { return rtrim( dirname( $f ), '/' ) . '/'; }
function plugin_dir_url( $f ) { return 'https://site.test/wp-content/plugins/hdl-longevity-v2/'; }
function register_activation_hook( $f, $cb ) {}
function register_deactivation_hook( $f, $cb ) {}
function add_filter( $h, $cb = null, $p = 10, $a = 1 ) {}
function add_action( $h, $cb = null, $p = 10, $a = 1 ) {
    if ( 'init' === $h && 1 === $p && ! $GLOBALS['captured_init'] ) { $GLOBALS['captured_init'] = $cb; }
}
function do_action() {}
function add_shortcode() {}
function is_admin() { return false; }
function is_user_logged_in() { return null !== $GLOBALS['me']; }
function wp_get_current_user() { return $GLOBALS['me'] ?: (object) array( 'ID' => 0, 'user_email' => '' ); }
function wp_destroy_current_session() { echo "SESSION_ENDED\n"; }
function wp_clear_auth_cookie() { echo "COOKIE_CLEARED\n"; }
function wp_set_current_user( $id ) { if ( ! $id ) { $GLOBALS['me'] = null; } }
function wp_unslash( $s ) { return $s; }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : $s; }
function sanitize_email( $s ) { return $s; }
function sanitize_user( $s, $strict = false ) { return $s; }
function email_exists( $e ) { return 'c@x.test' === $e ? 7 : ( 'other@x.test' === $e ? 50 : false ); }
function username_exists( $u ) { return false; }
function wp_insert_user( $a ) { return 7; }
function is_wp_error( $x ) { return false; }
function update_user_meta() {}
function delete_user_meta() {}
function wp_set_auth_cookie( $id, $remember = false ) { echo 'AUTH_COOKIE_SET:' . $id . "\n"; }
function wp_safe_redirect( $url ) { echo 'REDIRECT:' . $url . "\n"; }
function wp_rand( $a = 0, $b = 0 ) { return 1234; }
function wp_generate_password( $l = 12, $s = true, $x = false ) { return 'pw'; }
function current_time( $t = 'mysql', $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function apply_filters( $tag, $value ) { return $value; }
function home_url( $p = '' ) { return 'https://site.test' . $p; }
function wp_login_url( $r = '' ) { return 'https://site.test/wp-login.php'; }
function wp_logout_url( $r = '' ) { return 'https://site.test/wp-login.php?action=logout&redirect_to=' . urlencode( $r ) . '&_wpnonce=LOGOUTNONCE'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function status_header( $c ) { echo 'STATUS:' . $c . "\n"; }
function nocache_headers() {}
function esc_url( $u ) { return $u; }
function esc_html( $s ) { return $s; }
function get_option( $k, $d = false ) { return $d; }
function user_can( $uid, $cap ) { return in_array( (int) $uid, $GLOBALS['staff'], true ); }

class HDLV2_Compatibility {
    public static function is_staff( $uid ) { return in_array( (int) $uid, $GLOBALS['staff'], true ); }
    public static function create_practitioner_client_link( $p, $c ) { return true; }
}

/*
 * The fake knows the one ticket row and answers each lookup the way MySQL
 * would for the WHERE clause the code wrote: pending and opened rows match the
 * plain lookup; a used ('completed') Full Report row matches only a lookup
 * that names it; an expiry clause drops an out-of-date row.
 */
function fake_ticket_matches( $q, $r ) {
    if ( ! $r ) return false;
    $q = preg_replace( '/\s+/', ' ', $q );
    if ( false !== strpos( $q, 'expires_at > UTC_TIMESTAMP()' ) && strtotime( $r->expires_at . ' UTC' ) <= time() ) return false;
    if ( false !== strpos( $q, "AND source = 'full_report' AND" ) && 'full_report' !== $r->source ) return false;
    if ( in_array( $r->status, array( 'pending', 'opened' ), true ) ) return true;
    return 'completed' === $r->status && 'full_report' === $r->source
        && ( false !== strpos( $q, "OR ( status = 'completed' AND source = 'full_report' )" )
             || false !== strpos( $q, "AND source = 'full_report' AND status IN ('pending','opened','completed')" ) );
}

class FakeWpdb {
    public $prefix = 'wp_';
    public function prepare( $q, ...$args ) {
        foreach ( $args as $a ) { $q = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $q, 1 ); }
        return $q;
    }
    public function get_row( $q ) {
        if ( false !== strpos( $q, 'hdlv2_widget_invites' ) ) return fake_ticket_matches( $q, $GLOBALS['invite_row'] ) ? $GLOBALS['invite_row'] : null;
        if ( false !== strpos( $q, 'hdlv2_form_progress' ) ) return $GLOBALS['progress'];
        return null;
    }
    public function get_var( $q ) {
        if ( false !== strpos( $q, 'SELECT client_email' ) ) {
            return fake_ticket_matches( $q, $GLOBALS['invite_row'] ) ? $GLOBALS['invite_row']->client_email : null;
        }
        $q = preg_replace( '/\s+/', ' ', $q );
        foreach ( $GLOBALS['vars'] as $needle => $answer ) {
            if ( false !== strpos( $q, $needle ) ) return $answer;
        }
        return null;
    }
    public function update( $t, $d, $w, $f = null, $wf = null ) { echo 'DB_UPDATE:' . json_encode( $d ) . "\n"; return 1; }
}

require dirname( __DIR__, 2 ) . '/hdl-longevity-v2.php';
if ( ! is_callable( $GLOBALS['captured_init'] ) ) { fwrite( STDERR, "Could not capture the init closure\n" ); exit( 2 ); }

$hex  = str_repeat( 'cd', 32 );
$live = gmdate( 'Y-m-d H:i:s', time() + 80 * DAY_IN_SECONDS );
$gone = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
$full = (object) array( 'id' => 9, 'practitioner_id' => 3, 'client_email' => 'c@x.test', 'client_name' => 'C', 'status' => 'pending', 'expires_at' => $live, 'source' => 'full_report' );
$used = (object) array_merge( (array) $full, array( 'status' => 'completed' ) );
$done = (object) array( 'id' => 55, 'token' => 'fptoken', 'stage1_completed_at' => '2026-11-17 10:00:00' );
$other = (object) array( 'ID' => 50, 'user_email' => 'other@x.test' );
$_GET['invite']         = $hex;
$_SERVER['REQUEST_URI'] = '/rate-of-ageing-widget/?invite=' . $hex;

switch ( $CASE ) {
    // G1 — a used Full Report ticket keeps working until its date, signed out.
    case 'used-full-report':
        $GLOBALS['invite_row'] = $used;
        $GLOBALS['progress']   = $done;
        break;
    case 'used-full-report-expired':
        $GLOBALS['invite_row'] = (object) array_merge( (array) $used, array( 'expires_at' => $gone ) );
        break;
    case 'unknown-token':
        break;
    case 'used-paid-stage1':
    case 'used-timeline-link':
        $GLOBALS['vars'] = array( "source IN ('paid_stage1','timeline_link')" => '1' );
        break;

    // G2 — a Full Report ticket opened in a browser signed in as someone else.
    case 'signed-in-other-client':
        $GLOBALS['me']         = $other;
        $GLOBALS['invite_row'] = $full;
        $GLOBALS['progress']   = $done;
        break;
    case 'signed-in-staff':
        $GLOBALS['me']         = (object) array( 'ID' => 3, 'user_email' => 'prac@x.test' );
        $GLOBALS['staff']      = array( 3 );
        $GLOBALS['invite_row'] = $full;
        break;
    case 'signed-in-other-used':
        $GLOBALS['me']         = $other;
        $GLOBALS['invite_row'] = $used;
        break;
    case 'signed-in-other-odd-path': // a crafted path must not move the button's return off our host
        $GLOBALS['me']          = $other;
        $GLOBALS['invite_row']  = $full;
        $_SERVER['REQUEST_URI'] = '//evil.test/x/?invite=' . $hex . '&next=https://evil.test/';
        break;
    case 'signed-in-same-client':
        $GLOBALS['me']         = (object) array( 'ID' => 7, 'user_email' => 'C@x.test' );
        $GLOBALS['invite_row'] = $full;
        $GLOBALS['progress']   = $done;
        break;
    case 'signed-in-same-used':
        $GLOBALS['me']         = (object) array( 'ID' => 7, 'user_email' => 'c@x.test' );
        $GLOBALS['invite_row'] = $used;
        $GLOBALS['progress']   = $done;
        break;
    case 'signed-in-other-link': // an ordinary practitioner invite: unchanged
        $GLOBALS['me']         = $other;
        $GLOBALS['invite_row'] = (object) array_merge( (array) $full, array( 'source' => 'widget' ) );
        break;
    case 'signed-in-dead-ticket': // out of date: unchanged
        $GLOBALS['me']         = $other;
        $GLOBALS['invite_row'] = (object) array_merge( (array) $full, array( 'expires_at' => $gone ) );
        break;
    case 'signed-in-used-expired':
        $GLOBALS['me']         = $other;
        $GLOBALS['invite_row'] = (object) array_merge( (array) $used, array( 'expires_at' => $gone ) );
        break;
    case 'signed-in-revoked':
        $GLOBALS['me']         = $other;
        $GLOBALS['invite_row'] = (object) array_merge( (array) $full, array( 'status' => 'revoked' ) );
        break;
    case 'signed-in-rest':
        $GLOBALS['me']          = $other;
        $GLOBALS['invite_row']  = $full;
        $_SERVER['REQUEST_URI'] = '/wp-json/hdl-v2/v1/widget/lead?invite=' . $hex;
        break;
    case 'signed-in-bad-token':
        $GLOBALS['me']         = $other;
        $GLOBALS['invite_row'] = $full;
        $_GET['invite']        = 'not-a-token';
        break;
    default:
        fwrite( STDERR, "Unknown case: $CASE\n" );
        exit( 2 );
}

global $wpdb;
$wpdb = new FakeWpdb();
call_user_func( $GLOBALS['captured_init'] );
echo "HANDLER_RETURNED\n";
