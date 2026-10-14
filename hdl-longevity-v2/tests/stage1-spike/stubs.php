<?php
/**
 * Shared WP stubs + fake wpdb for tests/stage1-spike/ (copied from
 * tests/full-report/stubs.php so the old suite stays untouched). Extended:
 * every wp_remote_* call is recorded in $GLOBALS['http_log'] and answered by
 * $GLOBALS['http'] (a callable) when set; options are writable; a minimal
 * WP_REST_Request for the replay.
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
function update_option( $k, $v ) { $GLOBALS['options'][ $k ] = $v; return true; }
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
function http_call( $method, $url, $a ) {
    $GLOBALS['http_log'][] = array( 'method' => $method, 'url' => $url, 'args' => $a );
    if ( 'POST' === $method ) $GLOBALS['captured_posts'][] = array( 'url' => $url, 'args' => $a );
    if ( ! empty( $GLOBALS['http'] ) ) return call_user_func( $GLOBALS['http'], $method, $url, $a );
    return 'GET' === $method ? new WP_Error( 'test_offline', 'offline' ) : array( 'response' => array( 'code' => 200 ), 'body' => '' );
}
function wp_remote_get( $url, $a = array() ) { return http_call( 'GET', $url, $a ); }
function wp_remote_post( $url, $a = array() ) { return http_call( 'POST', $url, $a ); }
function wp_remote_request( $url, $a = array() ) { return http_call( strtoupper( $a['method'] ?? 'GET' ), $url, $a ); }
function wp_safe_remote_post( $url, $a = array() ) { return wp_remote_post( $url, $a ); }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }
function wp_remote_retrieve_header( $r, $h ) { return is_array( $r ) ? ( $r['headers'][ $h ] ?? '' ) : ''; }
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
// AJAX: the real wp_send_json_* and a failed check_ajax_referer end the request.
class AjaxDone extends Exception { public $ok; public $data; public function __construct( $ok, $data ) { $this->ok = $ok; $this->data = $data; } }
function check_ajax_referer( $action, $field ) { if ( ( $_POST[ $field ] ?? '' ) !== 'good-nonce' ) throw new AjaxDone( false, -1 ); return 1; }
function wp_send_json_success( $d = null ) { throw new AjaxDone( true, $d ); }
function wp_send_json_error( $d = null ) { throw new AjaxDone( false, $d ); }
function wp_unslash( $x ) { return is_string( $x ) ? stripslashes( $x ) : $x; }

class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code = '', $message = '', $data = null ) {
        $this->code = $code; $this->message = $message; $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

class WP_REST_Request {
    private $method; private $route; private $headers = array(); private $body = '';
    public function __construct( $method = 'GET', $route = '' ) { $this->method = $method; $this->route = $route; }
    public function set_header( $k, $v ) { $this->headers[ strtolower( $k ) ] = $v; }
    public function get_header( $k ) { return $this->headers[ strtolower( $k ) ] ?? null; }
    public function set_body( $b ) { $this->body = $b; }
    public function get_json_params() { $j = json_decode( $this->body, true ); return is_array( $j ) ? $j : array(); }
    public function get_param( $k ) { return $this->get_json_params()[ $k ] ?? null; }
    public function get_method() { return $this->method; }
    public function get_route() { return $this->route; }
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
    public $fail_fp_insert = false;
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
    public $lock_status = 'pending'; // answer to confirm_lead_row()'s SELECT … FOR UPDATE
    public function get_var( $sql ) {
        $this->queries[] = $sql;
        if ( false !== strpos( $sql, 'FOR UPDATE' ) ) return $this->lock_status;
        return strpos( $sql, 'hdlv2_widget_leads' ) !== false ? $this->existing_lead_id : null;
    }
    public function get_results( $sql ) { $this->queries[] = $sql; return array(); }
    public function insert( $table, $data, $format = null ) {
        $this->last_error = '';
        if ( ! empty( $this->fail_fp_insert ) && substr( $table, -19 ) === 'hdlv2_form_progress' ) { $this->insert_id = 0; return false; }
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
    public static function is_practitioner( $u ) { return $GLOBALS['is_prac'] ?? true; }
    public static function practitioner_owns_client( $p, $c ) { return true; }
    public static function create_practitioner_client_link( $p, $c ) { return true; }
}


// ── helpers (same as tests/paid-stage1) ───────────────────────────────
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
    $GLOBALS['http_log']       = array();
    $GLOBALS['http']           = null;
    $GLOBALS['mails']          = array();
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

/** Load the real sources the scenarios drive. */
function load_sources() {
    $ROOT = __DIR__ . '/../../';
    foreach ( array(
        'includes/sprint-1/class-hdlv2-offer.php',
        'includes/sprint-2/class-hdlv2-email-templates.php',
        'includes/sprint-2/class-hdlv2-rate-calculator.php',
        'includes/sprint-2/class-hdlv2-stage1-commentary.php',
        'includes/security/class-hdlv2-rate-limiter.php',
        'includes/security/class-hdlv2-rate-limit-policy.php',
        'includes/security/class-hdlv2-rate-limit-middleware.php',
        'includes/sprint-1/class-hdlv2-widget-config.php',
        'includes/sprint-1/class-hdlv2-widget-renderer.php',
        'includes/security/class-hdl-stage1-ticket.php',
        'includes/sprint-1/class-hdlv2-stage1-drain.php',
        'includes/sprint-1/class-hdlv2-stage1-waiting.php',
    ) as $f ) {
        if ( file_exists( $ROOT . $f ) ) require_once $ROOT . $f;
    }
}
function http_calls( $method = null ) {
    return array_values( array_filter( $GLOBALS['http_log'] ?? array(), function ( $c ) use ( $method ) { return ! $method || $c['method'] === $method; } ) );
}
function done() {
    global $PASS, $FAIL;
    echo "\nPASS=$PASS FAIL=$FAIL\n";
    exit( $FAIL === 0 ? 0 : 1 );
}
