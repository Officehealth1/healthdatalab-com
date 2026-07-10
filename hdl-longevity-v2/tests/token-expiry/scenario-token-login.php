<?php
/**
 * Subprocess scenario runner for the init-priority-1 magic-link handler in
 * hdl-longevity-v2.php. One case per process because hdlv2_render_link_card()
 * and the redirect paths call exit.
 *
 * Standalone — stubs WordPress, fakes $wpdb, includes the REAL plugin main
 * file, captures the init closure via the add_action stub, then invokes it.
 * Prints markers on stdout; the orchestrator asserts on them:
 *   AUTH_COOKIE_SET:<uid>   wp_set_auth_cookie was called
 *   DB_UPDATE:<table>:<json> $wpdb->update was called
 *   SLIDE_OK                form_progress.token_expires_at refreshed to ~now+90d
 *   REDIRECT:<url>          wp_safe_redirect was called
 *   HANDLER_RETURNED        closure returned without exiting
 *   (card paths print the card HTML, which contains the card title)
 *
 * Run:  php scenario-token-login.php <case>
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
// Deterministic non-UTC zone (UTC+1 year-round): catches DATETIME values that
// are stored via gmdate() (UTC) but parsed by strtotime() without ' UTC'.
date_default_timezone_set( 'Etc/GMT-1' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPINC', 'wp-includes' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$CASE = $argv[1] ?? 'fresh';

$GLOBALS['captured_init'] = null;
$GLOBALS['case_rows']     = array();
$GLOBALS['transients']    = array();

// ── WP stubs — file-scope needs of hdl-longevity-v2.php + the init closure ──
function plugin_dir_path( $f ) { return rtrim( dirname( $f ), '/' ) . '/'; }
function plugin_dir_url( $f ) { return 'https://stby.test/wp-content/plugins/hdl-longevity-v2/'; }
function register_activation_hook( $f, $cb ) {}
function register_deactivation_hook( $f, $cb ) {}
function add_filter( $h, $cb = null, $p = 10, $a = 1 ) {}
function add_action( $h, $cb = null, $p = 10, $a = 1 ) {
    if ( 'init' === $h && 1 === $p && ! $GLOBALS['captured_init'] ) { $GLOBALS['captured_init'] = $cb; }
}
function add_shortcode() {}
function is_admin() { return false; }
function is_user_logged_in() { return false; }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : $s; }
function sanitize_email( $s ) { return $s; }
function sanitize_user( $s, $strict = false ) { return $s; }
function email_exists( $e ) { return 7; }
function username_exists( $u ) { return false; }
function wp_insert_user( $a ) { return 7; }
function is_wp_error( $x ) { return false; }
function update_user_meta() {}
function delete_user_meta() {}
function get_user_meta() { return ''; }
function wp_set_current_user( $id ) {}
function wp_set_auth_cookie( $id, $remember = false ) { echo 'AUTH_COOKIE_SET:' . $id . "\n"; }
function wp_safe_redirect( $url ) { echo 'REDIRECT:' . $url . "\n"; }
function wp_rand( $a = 0, $b = 0 ) { return 1234; }
function wp_generate_password( $l = 12, $s = true, $x = false ) { return 'pw'; }
function current_time( $t = 'mysql', $gmt = 0 ) { return $gmt ? gmdate( 'Y-m-d H:i:s' ) : date( 'Y-m-d H:i:s' ); }
function apply_filters( $tag, $value ) { return $value; }
function home_url( $p = '' ) { return 'https://stby.test' . $p; }
function wp_login_url( $r = '' ) { return 'https://stby.test/wp-login.php'; }
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['transients'] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function status_header( $c ) { echo 'STATUS:' . $c . "\n"; }
function nocache_headers() {}
function esc_url( $u ) { return $u; }
function esc_html( $s ) { return $s; }
function get_option( $k, $d = false ) { return $d; }
function add_query_arg( $key, $value, $url ) { return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . $key . '=' . $value; }

// Fake V1↔V2 glue — the closure guards every use behind class_exists().
class HDLV2_Compatibility {
    public static function is_practitioner( $uid ) { return 3 === (int) $uid; }
    public static function create_practitioner_client_link( $p, $c ) { return true; }
    public static function practitioner_owns_client( $p, $c ) { return 3 === (int) $p && 42 === (int) $c; }
}

// ── Fake $wpdb ──
class FakeWpdb {
    public $prefix = 'wp_';
    public function prepare( $q, ...$args ) {
        foreach ( $args as $a ) {
            $q = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $q, 1 );
        }
        return $q;
    }
    public function get_row( $q ) {
        if ( false !== strpos( $q, 'hdlv2_widget_invites' ) ) return $GLOBALS['case_rows']['invite'] ?? null;
        if ( false !== strpos( $q, 'hdlv2_form_progress' ) && false !== strpos( $q, 'client_user_id =' ) ) {
            return $GLOBALS['case_rows']['progress_by_user'] ?? null;
        }
        if ( false !== strpos( $q, 'hdlv2_form_progress' ) ) return $GLOBALS['case_rows']['progress'] ?? null;
        return null;
    }
    public function get_var( $q ) { return null; }
    public function update( $table, $data, $where, $fmt = null, $wfmt = null ) {
        echo 'DB_UPDATE:' . $table . ':' . json_encode( $data ) . "\n";
        return 1;
    }
    public function insert( $t, $d = array(), $f = null ) { return 1; }
    public function query( $q ) { return 0; }
}

// ── Include the REAL plugin main file (captures the init closure) ──
require dirname( __DIR__, 2 ) . '/hdl-longevity-v2.php';

if ( ! is_callable( $GLOBALS['captured_init'] ) ) {
    fwrite( STDERR, "Could not capture the init closure\n" );
    exit( 2 );
}

// ── Case setup ──
$hex = str_repeat( 'ab', 32 ); // 64-hex

switch ( $CASE ) {
    // ?token= (client funnel, form_progress)
    case 'null-expiry':
        $_GET['token'] = $hex;
        $GLOBALS['case_rows']['progress'] = (object) array( 'id' => 55, 'client_user_id' => 42, 'token_expires_at' => null );
        break;
    case 'past-expiry':
        $_GET['token'] = $hex;
        $GLOBALS['case_rows']['progress'] = (object) array( 'id' => 55, 'client_user_id' => 42, 'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS ) );
        break;
    case 'near-expiry-valid': // valid for another 30 min — must log in (catches missing " UTC")
        $_GET['token'] = $hex;
        $GLOBALS['case_rows']['progress'] = (object) array( 'id' => 55, 'client_user_id' => 42, 'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * MINUTE_IN_SECONDS ) );
        break;
    case 'fresh':
        $_GET['token'] = $hex;
        $GLOBALS['case_rows']['progress'] = (object) array( 'id' => 55, 'client_user_id' => 42, 'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 80 * DAY_IN_SECONDS ) );
        break;
    case 'not-found':
        $_GET['token'] = $hex;
        $GLOBALS['case_rows']['progress'] = null;
        break;

    // ?invite= (widget_invites)
    case 'invite-empty-expiry':
        $_GET['invite'] = $hex;
        $GLOBALS['case_rows']['invite'] = (object) array( 'id' => 9, 'practitioner_id' => 3, 'client_email' => 'c@x.test', 'client_name' => 'C', 'status' => 'opened', 'expires_at' => '' );
        break;
    case 'invite-near-valid': // valid for another 30 min — must log in (catches missing " UTC")
        $_GET['invite'] = $hex;
        $GLOBALS['case_rows']['invite'] = (object) array( 'id' => 9, 'practitioner_id' => 3, 'client_email' => 'c@x.test', 'client_name' => 'C', 'status' => 'opened', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * MINUTE_IN_SECONDS ) );
        break;
    case 'invite-fresh':
        $_GET['invite'] = $hex;
        $GLOBALS['case_rows']['invite'] = (object) array( 'id' => 9, 'practitioner_id' => 3, 'client_email' => 'c@x.test', 'client_name' => 'C', 'status' => 'opened', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 6 * HOUR_IN_SECONDS ) );
        break;
    case 'invite-expired':
        $_GET['invite'] = $hex;
        $GLOBALS['case_rows']['invite'] = (object) array( 'id' => 9, 'practitioner_id' => 3, 'client_email' => 'c@x.test', 'client_name' => 'C', 'status' => 'opened', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) );
        break;

    // ?prac_login= (30-min one-shot transient — regression guards, no change expected)
    case 'prac-login-fresh':
        $_GET['prac_login'] = $hex;
        $GLOBALS['transients'][ 'hdlv2_prac_login_' . $hex ] = array( 'practitioner_id' => 3, 'progress_id' => 55, 'created_at' => time() );
        break;
    case 'prac-login-missing':
        $_GET['prac_login'] = $hex;
        break;

    default:
        fwrite( STDERR, "Unknown case: $CASE\n" );
        exit( 2 );
}

global $wpdb;
$wpdb = new FakeWpdb();

call_user_func( $GLOBALS['captured_init'] );
echo "HANDLER_RETURNED\n";
