<?php
/**
 * Paid Stage 1 widget mode — v0.47.85 / DB 3.27.
 *
 * A practitioner's widget can be switched to paid (widget_config.access_mode).
 * Then the answers endpoint needs a valid invite of that practitioner, a
 * one-time paid ticket (widget_invites.source = 'paid_stage1') takes the
 * PUBLIC path and is used up, and POST /hdl/v1/stage1-ticket mints tickets
 * for a keyed caller. Open mode must behave exactly as before.
 *
 * Run:  php scenario-paid-stage1.php [nokey]   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HDLV2_SKIP_MX_CHECK', true );
define( 'HDLV2_CLIENT_TOKEN_TTL_DAYS', 90 );
define( 'HDLV2_MAKE_STAGE1_PDF', 'https://hook.example.test/stage1' );
define( 'HDLV2_MAKE_CALLBACK_SECRET', 'test-callback-secret' );
define( 'HDLV2_PLUGIN_URL', 'https://stby.example.test/wp-content/plugins/hdl-longevity-v2/' );
define( 'HDLV2_PLUGIN_DIR', __DIR__ . '/../../' );
define( 'HDLV2_VERSION', 'test' );

// ── WP stubs ──────────────────────────────────────────────────────────
$GLOBALS['transients']      = array();
$GLOBALS['captured_posts']  = array(); // wp_remote_post fires (url, payload)
$GLOBALS['current_user_id'] = 206;

function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v ) { return true; }
function apply_filters( $tag, $value ) { return $value; }
function do_action() {}
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function register_rest_route() {}
function current_time( $fmt, $gmt = 0 ) {
    if ( 'mysql' === $fmt ) return gmdate( 'Y-m-d H:i:s' );
    if ( 'c' === $fmt )     return gmdate( 'c' );
    return gmdate( $fmt );
}
function wp_json_encode( $x ) { return json_encode( $x ); }
function sanitize_text_field( $x ) {
    if ( ! is_scalar( $x ) ) return '';
    $x = (string) $x;
    $x = strip_tags( $x );
    $x = preg_replace( '/[\r\n\t ]+/', ' ', $x );
    return trim( $x );
}
function sanitize_email( $x ) { return is_string( $x ) ? trim( $x ) : ''; }
function is_email( $x ) { return is_string( $x ) && filter_var( $x, FILTER_VALIDATE_EMAIL ) !== false; }
function sanitize_user( $x, $strict = false ) { return preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $x ) ); }
function absint( $x ) { return abs( (int) $x ); }
function esc_html( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES ); }
function esc_attr( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES ); }
function esc_url( $x ) { return str_replace( array( '&', '"' ), array( '&amp;', '&quot;' ), (string) $x ); }
function esc_url_raw( $x ) { return (string) $x; }
function wp_kses_post( $x ) { return (string) $x; }
function rest_ensure_response( $x ) { return $x; }
function rest_url( $p = '' ) { return 'https://stby.example.test/wp-json/' . ltrim( $p, '/' ); }
function home_url( $p = '' ) { return 'https://stby.example.test' . $p; }
function site_url( $p = '' ) { return 'https://stby.example.test' . $p; }
function get_current_user_id() { return $GLOBALS['current_user_id']; }
function email_exists( $e ) { return false; }
function wp_insert_user( $a ) { return 901; }
function update_user_meta() { return true; }
function get_user_meta( $uid, $k = '', $single = false ) { return ''; }
function delete_user_meta() { return true; }
function get_userdata( $id ) {
    $u = new stdClass();
    $u->ID = $id; $u->display_name = 'Prac ' . $id; $u->user_email = 'prac' . $id . '@example.test';
    return $u;
}
function wp_generate_password( $len = 12, $s = false, $x = false ) { return 'pw-test-123456'; }
function wp_rand( $a = 0, $b = 9999 ) { return 4242; }
function wp_mail( ...$a ) { $GLOBALS["mails"][] = $a; return true; }
function wp_upload_dir() {
    $d = sys_get_temp_dir() . '/hdlv2-test-uploads';
    if ( ! is_dir( $d ) ) { mkdir( $d, 0777, true ); }
    return array( 'basedir' => $d, 'baseurl' => 'https://stby.example.test/uploads' );
}
function wp_remote_get( $url, $a = array() ) { return new WP_Error( 'test_offline', 'offline' ); }
function wp_remote_post( $url, $a = array() ) {
    $GLOBALS['captured_posts'][] = array( 'url' => $url, 'args' => $a );
    if ( ! empty( $GLOBALS['during_dispatch'] ) ) {
        $fn = $GLOBALS['during_dispatch'];
        $GLOBALS['during_dispatch'] = null;
        $fn();
    }
    return array( 'response' => array( 'code' => 200 ) );
}
function wp_safe_remote_post( $url, $a = array() ) { return wp_remote_post( $url, $a ); }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return ''; }
function is_user_logged_in() { return false; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function wp_date( $fmt, $ts = null ) { return gmdate( $fmt, $ts ?: time() ); }
function date_i18n( $fmt, $ts = null ) { return gmdate( $fmt, $ts ?: time() ); }
function get_bloginfo( $k = '' ) { return 'Test Site'; }
function wp_specialchars_decode( $x ) { return (string) $x; }
function checkdnsrr_disabled() {} // real checkdnsrr never reached: HDLV2_SKIP_MX_CHECK
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_tempnam( $f = '' ) { return tempnam( sys_get_temp_dir(), 'hdlv2' ); }
function wp_delete_file( $f ) { @unlink( $f ); return true; }
function wp_generate_uuid4() { return 'test-uuid-4'; }
function wp_strip_all_tags( $x, $remove_breaks = false ) {
    $x = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $x );
    $x = strip_tags( $x );
    if ( $remove_breaks ) { $x = preg_replace( '/[\r\n\t ]+/', ' ', $x ); }
    return trim( $x );
}
function html_entity_decode_stub() {}

class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code = '', $message = '', $data = null ) {
        $this->code = $code; $this->message = $message; $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

class WP_REST_Response {
    public $data; public $status; public $headers = array();
    public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
    public function header( $k, $v ) { $this->headers[ $k ] = $v; }
    public function get_status() { return $this->status; }
    public function get_data() { return $this->data; }
}

// dispatch_post_signup_artifacts() resolves the practitioner logo via the
// real sprint-1 helper class; a stub keeps the harness offline + WP-free.
class HDLV2_Practitioner {
    public static function get_logo_url( $id, $fallback = false ) { return ''; }
    public static function get_logo_shape( $id, $fallback = false ) { return 'round'; }
}

// send_invite_email() renders through the real template class; the stub
// returns inert strings (email content is not under test here).
class HDLV2_Email_Templates {
    public static function base_layout( ...$a ) { return '<html>stub</html>'; }
    public static function derive_first_name( $name, $email = '' ) { return (string) $name; }
    public static function widget_verification( ...$a ) { return '<html>stub</html>'; }
    public static function __callStatic( $m, $a ) { return ''; }
}


class FakeRequest {
    private $json; private $headers; private $method; private $route; private $query;
    public function __construct( $json, $headers = array(), $method = 'POST', $route = '', $query = array() ) {
        $this->json = $json; $this->headers = $headers; $this->method = $method; $this->route = $route; $this->query = $query;
    }
    public function get_method() { return $this->method; }
    public function get_route() { return $this->route; }
    public function get_json_params() { return $this->json; }
    // WordPress order: JSON body first, then the query string.
    public function get_param( $k ) { return $this->json[ $k ] ?? $this->query[ $k ] ?? null; }
    public function get_header( $k ) { return $this->headers[ $k ] ?? null; }
}

// ── Fake wpdb — one config row, one invite row, minted tickets ────────
class FakeWpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $inserts = array();
    public $updates = array();
    public $queries = array();
    public $config  = null;      // hdlv2_widget_config row
    public $invite  = null;      // hdlv2_widget_invites row served for token lookups
    public $tickets = array();   // minted rows keyed by external_ref (UNIQUE)
    public $invites = array();   // section 14: several invite rows keyed by token (replaces $invite when set)
    public $fail_lead_insert = false;
    public $stale_ticket_read = false; // token lookups see the ticket as it was before another post claimed it
    private $next_insert_id = 500;
    private $txn = null;               // state to put back on ROLLBACK

    public function prepare( $sql, ...$args ) {
        if ( count( $args ) === 1 && is_array( $args[0] ) ) { $args = $args[0]; }
        foreach ( $args as $a ) {
            $sql = preg_replace( '/%[dsf]/', is_numeric( $a ) ? $a : "'" . $a . "'", $sql, 1 );
        }
        return $sql;
    }
    public function suppress_errors( $s = true ) { return false; }
    private function table_of( $sql ) {
        foreach ( array( 'hdlv2_widget_config', 'hdlv2_widget_invites', 'hdlv2_widget_leads', 'hdlv2_form_progress' ) as $t ) {
            if ( strpos( $sql, $t ) !== false ) return $t;
        }
        return '';
    }
    public function get_row( $sql ) {
        $this->queries[] = $sql;
        $t = $this->table_of( $sql );
        if ( 'hdlv2_widget_config' === $t ) {
            if ( ! $this->config ) return null;
            return preg_match( '/practitioner_user_id = (\d+)/', $sql, $m ) && (int) $m[1] !== (int) $this->config->practitioner_user_id ? null : $this->config;
        }
        // The lead a used paid ticket bought (invites JOIN leads).
        if ( strpos( $sql, 'JOIN' ) !== false && strpos( $sql, 'hdlv2_widget_leads' ) !== false ) {
            if ( ! preg_match( "/i\.token = '([a-f0-9]{64})'/", $sql, $m ) || ! preg_match( '/i\.practitioner_id = (\d+)/', $sql, $p ) ) return null;
            $inv = $this->invites ? ( $this->invites[ $m[1] ] ?? null ) : ( $this->invite && $this->invite->token === $m[1] ? $this->invite : null );
            if ( ! $inv || 'completed' !== $inv->status || 'paid_stage1' !== $inv->source || (int) $inv->practitioner_id !== (int) $p[1] ) return null;
            foreach ( array_merge( $this->inserts_into( 'hdlv2_widget_leads' ), $this->updates ) as $w ) {
                if ( (int) ( $w['data']['invite_id'] ?? 0 ) === (int) $inv->id ) {
                    return (object) array( 'rate_of_ageing' => $w['data']['rate_of_ageing'] ?? null );
                }
            }
            return null;
        }
        if ( 'hdlv2_widget_invites' === $t ) {
            if ( preg_match( "/external_ref = '([^']*)'/", $sql, $m ) ) return $this->tickets[ $m[1] ] ?? null;
            if ( $this->stale_ticket_read && $this->invite ) {
                $old = clone $this->invite;
                $old->status = 'pending';
                return $old;
            }
            if ( $this->invites ) {
                return preg_match( "/token = '([a-f0-9]{64})'/", $sql, $m ) ? ( $this->invites[ $m[1] ] ?? null ) : null;
            }
            return $this->invite;
        }
        return null;
    }
    private function invite_by_id( $id ) {
        foreach ( $this->invites as $row ) {
            if ( (int) $row->id === (int) $id ) return $row;
        }
        return $this->invite && (int) $this->invite->id === (int) $id ? $this->invite : null;
    }
    public $existing_lead_id = null; // a lead already on file for this practitioner + email
    public function get_var( $sql ) {
        $this->queries[] = $sql;
        return strpos( $sql, 'hdlv2_widget_leads' ) !== false ? $this->existing_lead_id : null;
    }
    public function get_results( $sql ) { $this->queries[] = $sql; return array(); }
    public function insert( $table, $data, $format = null ) {
        $this->last_error = '';
        if ( $this->fail_lead_insert && substr( $table, -18 ) === 'hdlv2_widget_leads' ) {
            $this->last_error = 'simulated failure';
            $this->insert_id  = 0;
            return false;
        }
        if ( isset( $data['external_ref'] ) ) {
            if ( isset( $this->tickets[ $data['external_ref'] ] ) ) {
                $this->last_error = "Duplicate entry '" . $data['external_ref'] . "' for key 'external_ref'";
                return false;
            }
            $this->tickets[ $data['external_ref'] ] = (object) $data;
        }
        $this->inserts[] = array( 'table' => $table, 'data' => $data );
        $this->insert_id = ++$this->next_insert_id;
        return 1;
    }
    public function update( $table, $data, $where, $format = null, $where_format = null ) {
        $this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
        $row = substr( $table, -20 ) === 'hdlv2_widget_invites' ? $this->invite_by_id( $where['id'] ?? 0 ) : null;
        if ( $row ) {
            foreach ( $data as $k => $v ) { $row->$k = $v; }
        }
        return 1;
    }
    public function query( $sql ) {
        $this->queries[] = $sql;
        if ( 'START TRANSACTION' === $sql ) {
            $rows = $this->invites ? array_values( $this->invites ) : ( $this->invite ? array( $this->invite ) : array() );
            $this->txn = array( 'inserts' => count( $this->inserts ), 'rows' => array_map( function ( $r ) { return array( $r, $r->status ); }, $rows ) );
            return 1;
        }
        if ( 'COMMIT' === $sql ) { $this->txn = null; return 1; }
        if ( 'ROLLBACK' === $sql ) {
            if ( $this->txn ) {
                foreach ( $this->txn['rows'] as $r ) { $r[0]->status = $r[1]; }
                $this->inserts = array_slice( $this->inserts, 0, $this->txn['inserts'] );
            }
            $this->txn = null;
            return 1;
        }
        // The one-time claim: only a pending/opened invite can be taken.
        if ( strpos( $sql, 'hdlv2_widget_invites' ) !== false && stripos( $sql, "SET status = 'completed'" ) !== false ) {
            $row = preg_match( '/WHERE id = (\d+)/', $sql, $m ) ? $this->invite_by_id( $m[1] ) : null;
            if ( ! $row || ! in_array( $row->status, array( 'pending', 'opened' ), true ) ) return 0;
            $row->status = 'completed';
            return 1;
        }
        return 1;
    }
    public function inserts_into( $suffix ) {
        return array_values( array_filter( $this->inserts, function ( $i ) use ( $suffix ) {
            return substr( $i['table'], -strlen( $suffix ) ) === $suffix;
        } ) );
    }
    public function sql_matching( $needle ) {
        return array_values( array_filter( $this->queries, function ( $q ) use ( $needle ) { return strpos( $q, $needle ) !== false; } ) );
    }
}

class HDLV2_Compatibility {
    public static function is_practitioner( $u ) { return true; }
    public static function practitioner_owns_client( $p, $c ) { return true; }
    public static function create_practitioner_client_link( $p, $c ) { return true; }
}

// ── Load real sources ─────────────────────────────────────────────────
$ROOT = __DIR__ . '/../../';
require $ROOT . 'includes/sprint-2/class-hdlv2-rate-calculator.php';
require $ROOT . 'includes/sprint-2/class-hdlv2-stage1-commentary.php';
require $ROOT . 'includes/security/class-hdlv2-rate-limiter.php';
require $ROOT . 'includes/security/class-hdlv2-rate-limit-policy.php';
require $ROOT . 'includes/security/class-hdlv2-rate-limit-middleware.php';
require $ROOT . 'includes/sprint-1/class-hdlv2-widget-config.php';
require $ROOT . 'includes/sprint-1/class-hdlv2-widget-renderer.php';
if ( file_exists( $ROOT . 'includes/security/class-hdl-stage1-ticket.php' ) ) {
    require $ROOT . 'includes/security/class-hdl-stage1-ticket.php';
}

$GLOBALS['options'] = array( 'hdlv2_db_version' => '3.27' );

$PASS = 0; $FAIL = 0;
function check( $label, $cond ) {
    global $PASS, $FAIL;
    if ( $cond ) { $PASS++; echo "  PASS  $label\n"; }
    else         { $FAIL++; echo "  FAIL  $label\n"; }
}
function status_of( $r ) {
    if ( $r instanceof WP_REST_Response ) return $r->get_status();
    return $r instanceof WP_Error ? (int) ( $r->data['status'] ?? 0 ) : 200;
}
function code_of( $r )   { return $r instanceof WP_Error ? $r->code : ''; }

const TOKEN = 'abababababababababababababababababababababababababababababababab';

function fresh_wpdb( $mode = 'open' ) {
    global $wpdb;
    $GLOBALS['transients']     = array();
    $GLOBALS['captured_posts'] = array();
    $GLOBALS['mails']          = array();
    $GLOBALS['during_dispatch'] = null;
    $wpdb = new FakeWpdb();
    $cfg  = new stdClass();
    $cfg->practitioner_user_id = 206;
    $cfg->practitioner_name    = 'Prac 206';
    $cfg->webhook_url          = '';
    $cfg->notification_email   = '';
    $cfg->logo_url             = '';
    $cfg->logo_shape           = 'round';
    $cfg->cta_link             = '';
    $cfg->cta_text             = 'Book a session';
    $cfg->theme_color          = '#3d8da0';
    $cfg->show_book_button_after_widget = 0;
    $cfg->access_mode          = $mode;
    $cfg->buy_url              = 'paid' === $mode ? 'https://altituding.example.test/buy' : '';
    $wpdb->config = $cfg;
    return $wpdb;
}
function invite_row( $over = array() ) {
    return (object) array_merge( array(
        'id' => 77, 'practitioner_id' => 206, 'token' => TOKEN, 'status' => 'pending',
        'client_name' => 'Buyer', 'client_email' => 'buyer@example.test',
        'expires_at' => '2030-01-01 00:00:00', 'prefill_stage1' => null, 'source' => 'paid_stage1',
    ), $over );
}
function widget_instance() {
    return ( new ReflectionClass( 'HDLV2_Widget_Config' ) )->newInstanceWithoutConstructor();
}
function lead_params( $email, $token = '' ) {
    $p = array(
        'practitioner_id' => 206, 'name' => 'Test Lead', 'email' => $email, 'phone' => '',
        'q1_age' => 44, 'q1_sex' => 'male', 'q2a' => 3, 'q2b' => 'even',
        'q3' => 'b', 'q4' => 'b', 'q5' => 'b', 'q6' => 'b', 'q7' => 'a', 'q8' => 'b', 'q9' => 'b',
        'rate_of_ageing_result' => 1.02,
    );
    if ( $token ) $p['invite_token'] = $token;
    return $p;
}
function post_lead( $params ) { return widget_instance()->rest_capture_lead( new FakeRequest( $params ) ); }
function nothing_recorded( $wpdb ) {
    return count( $wpdb->inserts ) === 0 && count( $GLOBALS['captured_posts'] ) === 0 && count( $GLOBALS['mails'] ) === 0;
}

// Mint-route 503 runs in its own process: a PHP constant cannot be undefined.
if ( 'nokey' === ( $argv[1] ?? '' ) ) {
    echo "── 12a. mint route with no key constant ──\n";
    fresh_wpdb( 'paid' );
    $r = class_exists( 'HDL_Stage1_Ticket' )
        ? HDL_Stage1_Ticket::handle( new FakeRequest( array(), array( 'X-HDL-Stage1-Ticket-Key' => 'anything' ) ) )
        : null;
    check( '12.1 no HDL_STAGE1_TICKET_KEY constant → 503 (route is dark)', 503 === status_of( $r ) && $r instanceof WP_Error );
    echo "\nPASS=$PASS FAIL=$FAIL\n";
    exit( $FAIL === 0 ? 0 : 1 );
}
define( 'HDL_STAGE1_TICKET_KEY', 'k-test-key' );

echo "── 1. open mode, no invite: public path as today ──\n";
$wpdb = fresh_wpdb( 'open' );
$r    = post_lead( lead_params( 'one@example.test' ) );
check( '1.1 response is exactly { success, rate }', is_array( $r ) && array_keys( $r ) === array( 'success', 'rate' ) && true === $r['success'] );
$lead = $wpdb->inserts_into( 'hdlv2_widget_leads' );
check( '1.2 one pending lead, no invite_id', count( $lead ) === 1 && $lead[0]['data']['status'] === 'pending' && ! array_key_exists( 'invite_id', $lead[0]['data'] ) );
check( '1.3 Stage-1 Make webhook fired once', count( $GLOBALS['captured_posts'] ) === 1 );
check( '1.4 no account created', count( $wpdb->inserts_into( 'hdlv2_form_progress' ) ) === 0 );

echo "── 2. paid mode, no invite: refused ──\n";
$wpdb = fresh_wpdb( 'paid' );
$r    = post_lead( lead_params( 'two@example.test' ) );
check( '2.1 403 ticket_required', 403 === status_of( $r ) && 'ticket_required' === code_of( $r ) );
check( '2.2 plain message for the visitor', $r instanceof WP_Error && strlen( (string) $r->message ) > 20 );
check( '2.3 nothing recorded, no email, no webhook', nothing_recorded( $wpdb ) );

echo "── 3. paid mode, valid paid ticket: public path ──\n";
$wpdb = fresh_wpdb( 'paid' );
$wpdb->invite = invite_row();
$r    = post_lead( lead_params( 'buyer@example.test', TOKEN ) );
check( '3.1 success', is_array( $r ) && ! empty( $r['success'] ) );
check( '3.2 no form_token (no fast path)', is_array( $r ) && ! array_key_exists( 'form_token', $r ) );
$lead = $wpdb->inserts_into( 'hdlv2_widget_leads' );
check( '3.3 pending lead carries invite_id', count( $lead ) === 1 && $lead[0]['data']['status'] === 'pending' && (int) ( $lead[0]['data']['invite_id'] ?? 0 ) === 77 );
check( '3.4 complete_signup not called (no form_progress row)', count( $wpdb->inserts_into( 'hdlv2_form_progress' ) ) === 0 );
check( '3.5 ticket completed', 'completed' === $wpdb->invite->status );
check( '3.6 Stage-1 Make webhook fired once', count( $GLOBALS['captured_posts'] ) === 1 );

$wpdb = fresh_wpdb( 'paid' );
$wpdb->invite = invite_row();
$wpdb->fail_lead_insert = true;
$r    = post_lead( lead_params( 'buyer@example.test', TOKEN ) );
check( '3.7 lead not saved → error, ticket still usable, no webhook', $r instanceof WP_Error && 'completed' !== $wpdb->invite->status && count( $GLOBALS['captured_posts'] ) === 0 );

$wpdb = fresh_wpdb( 'open' ); // practitioner switched back to open with tickets outstanding
$wpdb->invite = invite_row();
$r    = post_lead( lead_params( 'buyer@example.test', TOKEN ) );
check( '3.8 paid ticket in open mode still takes the public path and is used up', is_array( $r ) && ! array_key_exists( 'form_token', $r ) && 'completed' === $wpdb->invite->status && count( $wpdb->inserts_into( 'hdlv2_form_progress' ) ) === 0 );

$wpdb = fresh_wpdb( 'paid' );
$wpdb->invite = invite_row();
$wpdb->existing_lead_id = 321; // this email sent a free report earlier
$r    = post_lead( lead_params( 'buyer@example.test', TOKEN ) );
$upd  = array_values( array_filter( $wpdb->updates, function ( $u ) { return isset( $u['data']['stage1_data'] ) && isset( $u['data']['visitor_name'] ); } ) );
check( '3.9 returning email: lead row updated with invite_id', is_array( $r ) && count( $upd ) === 1 && (int) ( $upd[0]['data']['invite_id'] ?? 0 ) === 77 );
check( '3.10 …and a rejected row goes back to pending (only a rejected one)', count( $wpdb->sql_matching( "SET status = 'pending', rejected_at = NULL WHERE id = 321 AND status = 'rejected'" ) ) === 1 );
$wpdb = fresh_wpdb( 'open' );
$wpdb->existing_lead_id = 321;
post_lead( lead_params( 'free@example.test' ) );
check( '3.11 open-mode resubmission never touches status (as today)', count( $wpdb->sql_matching( "SET status = 'pending'" ) ) === 0 );

echo "── 4. paid mode, unusable tickets: refused ──\n";
$bad = array(
    'another practitioner\'s ticket' => array( 'practitioner_id' => 999 ),
    'revoked'                        => array( 'status' => 'revoked' ),
    'expired'                        => array( 'expires_at' => '2020-01-01 00:00:00' ),
    'already completed'              => array( 'status' => 'completed' ),
);
$n = 0;
foreach ( $bad as $label => $over ) {
    $n++;
    $wpdb = fresh_wpdb( 'paid' );
    $wpdb->invite = invite_row( $over );
    $before = $wpdb->invite->status;
    $r = post_lead( lead_params( 'four@example.test', TOKEN ) );
    check( "4.$n $label → 403, nothing recorded, ticket untouched", 403 === status_of( $r ) && 'ticket_required' === code_of( $r ) && nothing_recorded( $wpdb ) && $before === $wpdb->invite->status );
}
$wpdb = fresh_wpdb( 'paid' );
$r = post_lead( lead_params( 'four@example.test', 'not-a-token' ) );
check( '4.5 malformed token → 403', 403 === status_of( $r ) && nothing_recorded( $wpdb ) );

echo "── 5. paid mode, valid ticket, invalid email ──\n";
$wpdb = fresh_wpdb( 'paid' );
$wpdb->invite = invite_row();
$r = post_lead( lead_params( 'not-an-email', TOKEN ) );
check( '5.1 usual email error', 'invalid_email' === code_of( $r ) && 400 === status_of( $r ) );
check( '5.2 ticket still usable', 'pending' === $wpdb->invite->status && nothing_recorded( $wpdb ) );

echo "── 6. paid mode, the same post twice inside the dedupe window ──\n";
$wpdb = fresh_wpdb( 'paid' );
$wpdb->invite = invite_row();
$first  = post_lead( lead_params( 'buyer@example.test', TOKEN ) );
$second = post_lead( lead_params( 'buyer@example.test', TOKEN ) );
check( '6.1 second post returns the first response', is_array( $first ) && $first === $second );
check( '6.2 one lead, one webhook', count( $wpdb->inserts_into( 'hdlv2_widget_leads' ) ) === 1 && count( $GLOBALS['captured_posts'] ) === 1 );

echo "── 7. paid mode, practitioner-made invite: today's fast path ──\n";
$wpdb = fresh_wpdb( 'paid' );
$wpdb->invite = invite_row( array( 'source' => 'practitioner' ) );
$r = post_lead( lead_params( 'seven@example.test', TOKEN ) );
check( '7.1 form_token returned', is_array( $r ) && ! empty( $r['form_token'] ) );
check( '7.2 client record created', count( $wpdb->inserts_into( 'hdlv2_form_progress' ) ) === 1 );
check( '7.3 invite completed', 'completed' === $wpdb->invite->status );
$wpdb = fresh_wpdb( 'paid' );
$wpdb->invite = invite_row( array( 'source' => 'practitioner', 'practitioner_id' => 999 ) );
$r = post_lead( lead_params( 'seven@example.test', TOKEN ) );
check( '7.4 another practitioner\'s invite does not open a paid widget', 403 === status_of( $r ) && nothing_recorded( $wpdb ) && 'pending' === $wpdb->invite->status );

echo "── 8. verify-invite ──\n";
$wpdb = fresh_wpdb( 'paid' );
$wpdb->invite = invite_row();
$r = widget_instance()->rest_verify_invite( new FakeRequest( array( 'token' => TOKEN ) ) );
check( '8.1 paid ticket verifies with source paid_stage1', is_array( $r ) && true === $r['valid'] && 'paid_stage1' === ( $r['source'] ?? null ) );
$wpdb = fresh_wpdb( 'open' );
$wpdb->invite = invite_row( array( 'source' => 'practitioner' ) );
$r = widget_instance()->rest_verify_invite( new FakeRequest( array( 'token' => TOKEN ) ) );
$today = array( 'valid', 'practitioner_id', 'client_name', 'client_email', 'practitioner_name', 'logo_url', 'logo_shape', 'cta_text', 'cta_link', 'theme_color', 'show_book_button_after_widget', 'safety_screen_enabled', 'api_url', 'prefill_stage1' );
check( '8.2 practitioner invite answers as today, plus source', is_array( $r ) && true === $r['valid'] && array_diff( $today, array_keys( $r ) ) === array() && 'practitioner' === ( $r['source'] ?? null ) );
$wpdb->invite = invite_row( array( 'status' => 'completed' ) );
$r = widget_instance()->rest_verify_invite( new FakeRequest( array( 'token' => TOKEN ) ) );
check( '8.3 used ticket → valid:false, reason completed', is_array( $r ) && false === $r['valid'] && 'completed' === $r['reason'] );

echo "── 9. public-config ──\n";
$wpdb = fresh_wpdb( 'paid' );
$r = widget_instance()->rest_get_public_config( new FakeRequest( array( 'practitioner_id' => 206 ) ) );
check( '9.1 returns access_mode + buy_url', is_array( $r['config'] ?? null ) && 'paid' === ( $r['config']['access_mode'] ?? null ) && 'https://altituding.example.test/buy' === ( $r['config']['buy_url'] ?? null ) );
$wpdb = fresh_wpdb( 'open' );
$r = widget_instance()->rest_get_public_config( new FakeRequest( array( 'practitioner_id' => 206 ) ) );
check( '9.2 open practitioner → access_mode open', 'open' === ( $r['config']['access_mode'] ?? null ) );
$r = widget_instance()->rest_get_public_config( new FakeRequest( array( 'practitioner_id' => 4040 ) ) );
check( '9.3 unknown id still returns config: null', is_array( $r ) && array_key_exists( 'config', $r ) && null === $r['config'] );

echo "── 10. saving Widget Settings leaves the paid setting alone ──\n";
$wpdb = fresh_wpdb( 'paid' );
$m = new ReflectionMethod( 'HDLV2_Widget_Config', 'save_config' );
$m->setAccessible( true );
$m->invoke( widget_instance(), 206, array( 'practitioner_name' => 'New Name', 'cta_link' => 'https://x.test/b', 'notification_email' => 'p@example.test' ) );
$upd = $wpdb->updates[0]['data'] ?? null;
check( '10.1 update writes neither access_mode nor buy_url', is_array( $upd ) && ! array_key_exists( 'access_mode', $upd ) && ! array_key_exists( 'buy_url', $upd ) );

echo "── 11. embed snippet ──\n";
$embed_cfg = array( 'practitioner_name' => 'Dr "A"', 'logo_url' => 'https://x.test/l.png', 'logo_shape' => 'square', 'cta_text' => 'Book', 'cta_link' => 'https://x.test/b', 'theme_color' => '#3d8da0' );
$wpdb = fresh_wpdb( 'open' );
$open = HDLV2_Widget_Renderer::generate_embed_code( 206, $embed_cfg );
// md5 of the snippet produced by the 0.47.84 renderer for this exact input.
check( '11.1 open mode: today\'s snippet byte for byte', '030df362720d1b03aba0ac2b37aee4ce' === md5( $open ) );
check( '11.2 open mode with access_mode=open set: same bytes', $open === HDLV2_Widget_Renderer::generate_embed_code( 206, $embed_cfg + array( 'access_mode' => 'open', 'buy_url' => 'https://ignored.test/' ) ) );
$paid = HDLV2_Widget_Renderer::generate_embed_code( 206, $embed_cfg + array( 'access_mode' => 'paid', 'buy_url' => 'https://shop.test/buy?a=1&b="x"' ) );
check( '11.3 paid mode adds data-access="paid"', strpos( $paid, ' data-access="paid"' ) !== false );
check( '11.4 paid mode adds the escaped buy url', strpos( $paid, 'data-buy-url="https://shop.test/buy?a=1&amp;b=&quot;x&quot;"' ) !== false );
check( '11.5 paid snippet minus the two attributes is the open snippet', $open === preg_replace( '/ data-access="paid" data-buy-url="[^"]*"/', '', $paid ) );

$wpdb = fresh_wpdb( 'paid' ); // the dashboard callers pass no access_mode: the stored row decides
check( '11.6 paid practitioner, caller passes no access_mode → paid snippet from the stored row', strpos( HDLV2_Widget_Renderer::generate_embed_code( 206, $embed_cfg ), ' data-access="paid" data-buy-url="https://altituding.example.test/buy"' ) !== false );

echo "── 12. mint route POST /hdl/v1/stage1-ticket ──\n";
$has_mint = class_exists( 'HDL_Stage1_Ticket' );
function mint( $body, $key = 'k-test-key' ) {
    $headers = null === $key ? array() : array( 'X-HDL-Stage1-Ticket-Key' => $key );
    return HDL_Stage1_Ticket::handle( new FakeRequest( $body, $headers ) );
}
$good = array( 'practitioner_id' => 206, 'email' => 'buyer@example.test', 'name' => 'Buyer', 'external_ref' => 'cs_test_a1B2c3' );
check( '12.0 route class exists', $has_mint );
if ( $has_mint ) {
    $wpdb = fresh_wpdb( 'paid' );
    check( '12.2 wrong key → 401', 401 === status_of( mint( $good, 'wrong' ) ) );
    check( '12.3 missing key → 401', 401 === status_of( mint( $good, null ) ) );
    check( '12.4 nothing minted on 401', count( $wpdb->inserts ) === 0 );

    $wpdb = fresh_wpdb( 'open' );
    $r = mint( $good );
    check( '12.5 open-mode practitioner → refused, nothing minted', $r instanceof WP_Error && status_of( $r ) >= 400 && count( $wpdb->inserts ) === 0 );
    $wpdb = fresh_wpdb( 'paid' );
    $r = mint( array( 'practitioner_id' => 4040 ) + $good );
    check( '12.6 unknown practitioner → refused', $r instanceof WP_Error && count( $wpdb->inserts ) === 0 );

    $n = 6;
    foreach ( array(
        'bad email'               => array( 'email' => 'nope' ),
        'missing name'            => array( 'name' => '' ),
        'missing external_ref'    => array( 'external_ref' => '' ),
        'external_ref with junk'  => array( 'external_ref' => "cs_<script>" ),
        'external_ref too long'   => array( 'external_ref' => str_repeat( 'a', 129 ) ),
        'expires_days 0'          => array( 'expires_days' => 0 ),
        'expires_days 366'        => array( 'expires_days' => 366 ),
        'expires_days not a number' => array( 'expires_days' => 'soon' ),
    ) as $label => $over ) {
        $n++;
        $wpdb = fresh_wpdb( 'paid' );
        check( "12.$n $label → 400, nothing minted", 400 === status_of( mint( $over + $good ) ) && count( $wpdb->inserts ) === 0 );
    }

    $wpdb = fresh_wpdb( 'paid' );
    $r    = mint( $good + array( 'expires_days' => 30 ) );
    $row  = $wpdb->inserts_into( 'hdlv2_widget_invites' )[0]['data'] ?? array();
    check( '12.15 success → 64-hex token, idempotent false', is_array( $r ) && preg_match( '/^[a-f0-9]{64}$/', (string) ( $r['token'] ?? '' ) ) && false === $r['idempotent'] );
    check( '12.16 response is exactly { token, expires_at, idempotent }', is_array( $r ) && array_keys( $r ) === array( 'token', 'expires_at', 'idempotent' ) );
    check( '12.17 row: source paid_stage1, pending, practitioner, ref', ( $row['source'] ?? '' ) === 'paid_stage1' && ( $row['status'] ?? '' ) === 'pending' && (int) ( $row['practitioner_id'] ?? 0 ) === 206 && ( $row['external_ref'] ?? '' ) === 'cs_test_a1B2c3' );
    $days = ( strtotime( ( $row['expires_at'] ?? '' ) . ' UTC' ) - time() ) / DAY_IN_SECONDS;
    check( '12.18 expiry as asked (30 days, stored UTC)', $days > 29.9 && $days < 30.1 );
    check( '12.19 no email, no webhook, no account', count( $GLOBALS['mails'] ) === 0 && count( $GLOBALS['captured_posts'] ) === 0 && count( $wpdb->inserts ) === 1 );

    $again = mint( array( 'email' => 'other@example.test' ) + $good );
    check( '12.20 same external_ref → same token, idempotent true', is_array( $again ) && $again['token'] === $r['token'] && true === $again['idempotent'] );
    check( '12.21 still one row', count( $wpdb->inserts_into( 'hdlv2_widget_invites' ) ) === 1 );
    check( '12.22 insert came first (UNIQUE key decides, not a prior read)', count( $wpdb->sql_matching( 'external_ref =' ) ) === 1 );

    $wpdb = fresh_wpdb( 'paid' );
    $r    = mint( $good );
    $row  = $wpdb->inserts_into( 'hdlv2_widget_invites' )[0]['data'] ?? array();
    $days = ( strtotime( ( $row['expires_at'] ?? '' ) . ' UTC' ) - time() ) / DAY_IN_SECONDS;
    check( '12.23 default expiry 90 days', $days > 89.9 && $days < 90.1 );

    $wpdb = fresh_wpdb( 'paid' );
    $last = null;
    for ( $i = 0; $i <= HDL_Stage1_Ticket::RATE_LIMIT; $i++ ) {
        $last = mint( array( 'external_ref' => 'cs_burst_' . $i ) + $good );
    }
    check( '12.24 limiter answers 429 past its cap', 429 === status_of( $last ) && count( $wpdb->inserts ) === HDL_Stage1_Ticket::RATE_LIMIT );

    check( '12.26 the 429 carries Retry-After (seconds left in the hour) and the same body', $last instanceof WP_REST_Response && 'rate_limited' === ( $last->get_data()['code'] ?? '' ) && (int) ( $last->headers['Retry-After'] ?? 0 ) > 3590 && (int) $last->headers['Retry-After'] <= 3600 );
    // A fixed hour: move the stored window so it ends in 100 s, as if the
    // first mint was 58 minutes ago. Calls must not push that end back.
    $bk = HDLV2_Rate_Limiter::bucket_key( array( 'stage1-ticket', 'unknown' ) );
    $st = get_transient( $bk );
    $end = time() + 100;
    set_transient( $bk, array( 'count' => is_array( $st ) ? $st['count'] : 0, 'reset' => $end ) );
    $r  = mint( array( 'external_ref' => 'cs_late_1' ) + $good );
    $st = get_transient( $bk );
    check( '12.27 a refused call does not restart the hour (Retry-After counts down to the same end)', 429 === status_of( $r ) && $r instanceof WP_REST_Response && (int) $r->headers['Retry-After'] <= 100 && $end === ( $st['reset'] ?? 0 ) );
    set_transient( $bk, array( 'count' => 5, 'reset' => $end ) );
    mint( array( 'external_ref' => 'cs_late_2' ) + $good );
    $st = get_transient( $bk );
    check( '12.28 an allowed call does not restart the hour either', 6 === ( $st['count'] ?? 0 ) && $end === ( $st['reset'] ?? 0 ) );
    set_transient( $bk, array( 'count' => 999, 'reset' => time() - 1 ) );
    $r  = mint( array( 'external_ref' => 'cs_next_hour' ) + $good );
    $st = get_transient( $bk );
    check( '12.29 when the hour ends the count starts again', is_array( $r ) && 1 === ( $st['count'] ?? 0 ) );

    $wpdb = fresh_wpdb( 'paid' );
    $GLOBALS['options']['hdlv2_db_version'] = '3.26';
    check( '12.25 schema not yet verified (db 3.26) → 503, nothing minted', 503 === status_of( mint( $good ) ) && count( $wpdb->inserts ) === 0 );
    $GLOBALS['options']['hdlv2_db_version'] = '3.27';
} else {
    for ( $i = 2; $i <= 29; $i++ ) { check( "12.$i (skipped — route class missing)", false ); }
}

echo "── 13. other readers of the invites table ignore paid tickets ──\n";
$GLOBALS['current_user_id'] = 206;
$wpdb = fresh_wpdb( 'paid' );
widget_instance()->rest_list_invites( new FakeRequest( array() ) );
$sel = $wpdb->sql_matching( 'SELECT id, client_name' );
check( '13.1 Sent Invites (REST) excludes paid tickets', count( $sel ) === 1 && strpos( $sel[0], "source <> 'paid_stage1'" ) !== false );
$src = file_get_contents( $ROOT . 'includes/sprint-1/class-hdlv2-widget-config.php' );
check( '13.2 both invite lists + the delete carry the filter', substr_count( $src, "source <> 'paid_stage1'" ) >= 3 );
foreach ( array(
    '13.3 Stage-1 safety-net completion'   => 'includes/sprint-2/class-hdlv2-staged-form.php',
    '13.4 client dashboard (2 lookups)'    => 'includes/sprint-4/class-hdlv2-client-dashboard.php',
    '13.5 client delete → revoke invites'  => 'includes/sprint-4/class-hdlv2-client-status.php',
) as $label => $file ) {
    $want = strpos( $label, '2 lookups' ) ? 2 : 1;
    check( $label . ' ignores paid tickets', substr_count( file_get_contents( $ROOT . $file ), "source <> 'paid_stage1'" ) >= $want );
}

echo "── 14. answers post: a confirmed paid ticket is counted per ticket, anything else by address ──\n";
// Every post here goes through the REAL limiter middleware, then the REAL handler.
const IP = '198.51.100.7';
function tok( $n ) { return str_pad( dechex( $n ), 64, 'c', STR_PAD_LEFT ); }
function limited_post( $params, $query = array() ) {
    $_SERVER['REMOTE_ADDR'] = IP;
    $req = new FakeRequest( $params, array(), 'POST', '/hdl-v2/v1/widget/lead', $query );
    $r   = HDLV2_Rate_Limit_Middleware::check_request( null, null, $req );
    return null !== $r ? $r : widget_instance()->rest_capture_lead( $req );
}
function bucket( ...$parts ) {
    $st = get_transient( HDLV2_Rate_Limiter::bucket_key( $parts ) );
    return is_array( $st ) ? (int) $st['count'] : 0;
}
function old_cap() { return (int) get_transient( 'hdlv2_lead_' . md5( IP ) ); }
function limiter_headers() {
    $resp = new WP_REST_Response( array() );
    HDLV2_Rate_Limit_Middleware::add_headers( $resp, null, new FakeRequest( array(), array(), 'POST', '/hdl-v2/v1/widget/lead' ) );
    return $resp->headers;
}

$wpdb = fresh_wpdb( 'paid' );
$wpdb->invite = invite_row();
$r = limited_post( lead_params( 'buyer@example.test', TOKEN ) );
check( '14.1 valid ticket: saved, counted in the ticket\'s own bucket', is_array( $r ) && ! empty( $r['success'] ) && 1 === bucket( 'public', 'ticket', 77 ) );
check( '14.2 …the address\'s public bucket and the older address cap are untouched', 0 === bucket( 'public', 'ip', IP ) && 0 === old_cap() );
check( '14.3 …the 500 backstop still counts it', 1 === bucket( 'ip-backstop', IP ) );
check( '14.4 …one read of the ticket row for limiter and handler together', 1 === count( $wpdb->sql_matching( 'hdlv2_widget_invites WHERE token' ) ) );
$h = limiter_headers();
check( '14.5 …headers: limit 5, tier public', '5' === ( $h['X-RateLimit-Limit'] ?? '' ) && 'public' === ( $h['X-RateLimit-Tier'] ?? '' ) && '4' === ( $h['X-RateLimit-Remaining'] ?? '' ) );

$wpdb = fresh_wpdb( 'paid' );
$wpdb->invite = invite_row();
$codes = array();
for ( $i = 0; $i < 6; $i++ ) { $last = limited_post( lead_params( 'not-an-email', TOKEN ) ); $codes[] = status_of( $last ); }
check( '14.6 one ticket, six posts: five reach the handler, the sixth is 429', array( 400, 400, 400, 400, 400, 429 ) === $codes );
check( '14.7 …429 is the public tier with Retry-After, address bucket still empty', $last instanceof WP_REST_Response && 'public' === ( $last->get_data()['tier'] ?? '' ) && isset( $last->headers['Retry-After'] ) && 0 === bucket( 'public', 'ip', IP ) );

$wpdb = fresh_wpdb( 'paid' );
for ( $i = 1; $i <= 11; $i++ ) { $wpdb->invites[ tok( $i ) ] = invite_row( array( 'id' => 100 + $i, 'token' => tok( $i ) ) ); }
$saved = 0;
for ( $i = 1; $i <= 11; $i++ ) {
    $r = limited_post( lead_params( "buyer$i@example.test", tok( $i ) ) );
    if ( is_array( $r ) && ! empty( $r['success'] ) ) $saved++;
}
check( '14.8 eleven buyers behind one address, a ticket each: all eleven saved', 11 === $saved );
check( '14.9 …neither address count moved; backstop counted all eleven', 0 === bucket( 'public', 'ip', IP ) && 0 === old_cap() && 11 === bucket( 'ip-backstop', IP ) );

$wpdb = fresh_wpdb( 'paid' );
$wpdb->invite = invite_row();
set_transient( 'hdlv2_lead_' . md5( IP ), 10 ); // REMOTE_ADDR is still IP from the posts above
check( '14.10 handler alone (limiter off): a confirmed ticket still skips the older cap', is_array( post_lead( lead_params( 'buyer@example.test', TOKEN ) ) ) );

$n = 10;
foreach ( array(
    'made-up token'                 => array( null, 403 ),
    'used ticket'                   => array( array( 'status' => 'completed' ), 403 ),
    'revoked ticket'                => array( array( 'status' => 'revoked' ), 403 ),
    'expired ticket'                => array( array( 'expires_at' => '2020-01-01 00:00:00' ), 403 ),
    'another practitioner\'s ticket' => array( array( 'practitioner_id' => 999 ), 403 ),
    'practitioner-made invite'      => array( array( 'source' => 'practitioner' ), 200 ),
) as $label => $case ) {
    $n++;
    $wpdb = fresh_wpdb( 'paid' );
    $wpdb->invites[ tok( 1 ) ] = invite_row( array( 'id' => 101, 'token' => tok( 1 ) ) ); // a real ticket exists, but is not the one sent
    if ( $case[0] ) { $wpdb->invites[ TOKEN ] = invite_row( $case[0] ); }
    $r = limited_post( lead_params( "case$n@example.test", TOKEN ) );
    check( "14.$n $label → counted by address as today", $case[1] === status_of( $r ) && 1 === bucket( 'public', 'ip', IP ) && 1 === old_cap() && 1 === bucket( 'ip-backstop', IP ) && 0 === bucket( 'public', 'ticket', 77 ) );
}
$wpdb = fresh_wpdb( 'paid' );
$r = limited_post( lead_params( 'none@example.test' ) );
check( '14.17 no token → counted by address as today', 403 === status_of( $r ) && 1 === bucket( 'public', 'ip', IP ) && 1 === old_cap() && 1 === bucket( 'ip-backstop', IP ) );

$wpdb = fresh_wpdb( 'paid' );
$codes = array();
for ( $i = 0; $i < 6; $i++ ) { $last = limited_post( lead_params( 'x@example.test', tok( 900 + $i ) ) ); $codes[] = status_of( $last ); }
check( '14.18 six made-up tokens from one address: the sixth is 429 (public, 5 an hour)', array( 403, 403, 403, 403, 403, 429 ) === $codes && 'public' === ( $last->get_data()['tier'] ?? '' ) && 6 === bucket( 'ip-backstop', IP ) );
$wpdb = fresh_wpdb( 'paid' );
set_transient( 'hdlv2_lead_' . md5( IP ), 10 );
$r = limited_post( lead_params( 'x@example.test', tok( 900 ) ) );
check( '14.19 made-up token with the older cap already full → its 429, as today', $r instanceof WP_Error && 'rate_limited' === code_of( $r ) && 429 === status_of( $r ) );

$wpdb = fresh_wpdb( 'open' );
$r = limited_post( lead_params( 'open@example.test' ) );
$h = limiter_headers();
check( '14.20 open mode, no token: reply and limiter headers unchanged', is_array( $r ) && array_keys( $r ) === array( 'success', 'rate' ) && '5' === ( $h['X-RateLimit-Limit'] ?? '' ) && '4' === ( $h['X-RateLimit-Remaining'] ?? '' ) && 'public' === ( $h['X-RateLimit-Tier'] ?? '' ) && 1 === bucket( 'public', 'ip', IP ) && 1 === old_cap() );
unset( $_SERVER['REMOTE_ADDR'] );

echo "── 15. limiter and handler read the ticket from the same place (the JSON body) ──\n";
$wpdb = fresh_wpdb( 'paid' );
$wpdb->invite = invite_row();
$r = limited_post( lead_params( 'q@example.test' ), array( 'invite_token' => TOKEN, 'practitioner_id' => 206 ) );
check( '15.1 ticket in the query string, none in the body → refused and counted by address', 403 === status_of( $r ) && 1 === bucket( 'public', 'ip', IP ) && 0 === bucket( 'public', 'ticket', 77 ) && 1 === old_cap() );
check( '15.2 …and the ticket is not used up', 'pending' === $wpdb->invite->status && 0 === count( $wpdb->inserts ) );
$wpdb = fresh_wpdb( 'paid' );
$wpdb->invites[ TOKEN ]    = invite_row();
$wpdb->invites[ tok( 1 ) ] = invite_row( array( 'id' => 101, 'token' => tok( 1 ) ) );
$r = limited_post( lead_params( 'q@example.test', TOKEN ), array( 'invite_token' => tok( 1 ) ) );
check( '15.3 one ticket in the body, another in the query string → the body\'s ticket is counted and used', is_array( $r ) && 1 === bucket( 'public', 'ticket', 77 ) && 0 === bucket( 'public', 'ticket', 101 ) && 'completed' === $wpdb->invites[ TOKEN ]->status && 'pending' === $wpdb->invites[ tok( 1 ) ]->status );
unset( $_SERVER['REMOTE_ADDR'] );

echo "── 16. a repeat post for a ticket whose lead is saved gets the success body ──\n";
$wpdb = fresh_wpdb( 'paid' );
$wpdb->invite = invite_row();
$first = post_lead( lead_params( 'buyer@example.test', TOKEN ) );
$GLOBALS['transients'] = array(); // well past 60 s: every cache is gone
$late  = post_lead( lead_params( 'buyer@example.test', TOKEN ) );
check( '16.1 after the 60 s window: same body as the first post', is_array( $first ) && $first === $late );
check( '16.2 …one lead, one webhook', 1 === count( $wpdb->inserts_into( 'hdlv2_widget_leads' ) ) && 1 === count( $GLOBALS['captured_posts'] ) );

$wpdb = fresh_wpdb( 'paid' );
$wpdb->invite = invite_row();
$mid = null;
$GLOBALS['during_dispatch'] = function () use ( &$mid ) { $mid = post_lead( lead_params( 'buyer@example.test', TOKEN ) ); };
$first = post_lead( lead_params( 'buyer@example.test', TOKEN ) );
check( '16.3 while the first post is still dispatching: same body, not a refusal', is_array( $mid ) && $first === $mid );
check( '16.4 …one lead, one webhook', 1 === count( $wpdb->inserts_into( 'hdlv2_widget_leads' ) ) && 1 === count( $GLOBALS['captured_posts'] ) );

$wpdb = fresh_wpdb( 'paid' );
$wpdb->invite = invite_row();
$first = post_lead( lead_params( 'buyer@example.test', TOKEN ) );
$GLOBALS['transients']   = array();
$wpdb->stale_ticket_read = true; // two posts both read the ticket before either claimed it
$lost = post_lead( lead_params( 'buyer@example.test', TOKEN ) );
check( '16.5 the post that loses the claim to its twin gets the success body', is_array( $lost ) && $first === $lost && 1 === count( $wpdb->inserts_into( 'hdlv2_widget_leads' ) ) && 1 === count( $GLOBALS['captured_posts'] ) );

$wpdb = fresh_wpdb( 'paid' );
$wpdb->invite = invite_row( array( 'status' => 'completed' ) );
$r = post_lead( lead_params( 'buyer@example.test', TOKEN ) );
check( '16.6 used ticket with no lead on file → refused, nothing recorded (no second go)', 403 === status_of( $r ) && 'ticket_required' === code_of( $r ) && nothing_recorded( $wpdb ) );

$wpdb = fresh_wpdb( 'paid' );
$wpdb->invite = invite_row();
post_lead( lead_params( 'buyer@example.test', TOKEN ) );
$GLOBALS['transients'] = array();
$wpdb->invite->practitioner_id = 999; // the same saved lead, but the ticket is another practitioner's
$r = post_lead( lead_params( 'buyer@example.test', TOKEN ) );
check( '16.7 another practitioner\'s used ticket → refused', 403 === status_of( $r ) && 1 === count( $GLOBALS['captured_posts'] ) );

echo "── 17. the claim and the lead are saved together or not at all ──\n";
function sql_pos( $wpdb, $needle ) {
    foreach ( $wpdb->queries as $i => $q ) { if ( strpos( $q, $needle ) !== false ) return $i; }
    return -1;
}
$wpdb = fresh_wpdb( 'paid' );
$wpdb->invite = invite_row();
post_lead( lead_params( 'buyer@example.test', TOKEN ) );
$a = sql_pos( $wpdb, 'START TRANSACTION' ); $b = sql_pos( $wpdb, "SET status = 'completed'" ); $c = sql_pos( $wpdb, 'COMMIT' );
check( '17.1 saved ticket post: START TRANSACTION, claim, lead, COMMIT in that order', $a >= 0 && $a < $b && $b < $c && -1 === sql_pos( $wpdb, 'ROLLBACK' ) );
$wpdb = fresh_wpdb( 'paid' );
$wpdb->invite = invite_row();
$wpdb->fail_lead_insert = true;
$r = post_lead( lead_params( 'buyer@example.test', TOKEN ) );
check( '17.2 lead not saved: ROLLBACK, no COMMIT, ticket open again', $r instanceof WP_Error && sql_pos( $wpdb, 'ROLLBACK' ) > sql_pos( $wpdb, 'START TRANSACTION' ) && -1 === sql_pos( $wpdb, 'COMMIT' ) && 'completed' !== $wpdb->invite->status );
$wpdb = fresh_wpdb( 'open' );
post_lead( lead_params( 'free@example.test' ) );
check( '17.3 open-mode post opens no transaction (as today)', -1 === sql_pos( $wpdb, 'START TRANSACTION' ) && -1 === sql_pos( $wpdb, 'COMMIT' ) );
$wpdb = fresh_wpdb( 'paid' );
$wpdb->invite = invite_row( array( 'source' => 'practitioner' ) );
post_lead( lead_params( 'seven@example.test', TOKEN ) );
check( '17.4 practitioner invite opens no transaction (as today)', -1 === sql_pos( $wpdb, 'START TRANSACTION' ) );

echo "\nPASS=$PASS FAIL=$FAIL\n";
exit( $FAIL === 0 ? 0 : 1 );
