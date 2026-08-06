<?php
/**
 * Stage-1 lead location tests — v0.47.83.
 *
 * Feature under test: the widget submits optional visitor_country /
 * visitor_region (state) resolved by the healthdatalab.com geo edge
 * function. The server validates hard (ISO-3166 alpha-2 country, plain-text
 * region <= 64 chars, invalid input discarded WITHOUT rejecting the lead),
 * stores the pair on wp_hdlv2_widget_leads (public path) and
 * wp_hdlv2_form_progress (invite fast-path), and carries it across on
 * practitioner Confirm. Display strings are built server-side by
 * HDLV2_Widget_Config::format_visitor_location() ("Texas, US" / "US" / "").
 *
 * Contract guards: the Make.com Stage-1 payload stays EXACTLY 24 keys and
 * stage1_data never contains location keys (location lives in columns only).
 *
 * Run:  php scenario-lead-location.php   (self-asserting, exit 0/1)
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
function get_option( $k, $d = false ) { return $d; }
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
function esc_url( $x ) { return (string) $x; }
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
function wp_mail() { return true; }
function wp_upload_dir() {
    $d = sys_get_temp_dir() . '/hdlv2-test-uploads';
    if ( ! is_dir( $d ) ) { mkdir( $d, 0777, true ); }
    return array( 'basedir' => $d, 'baseurl' => 'https://stby.example.test/uploads' );
}
function wp_remote_get( $url, $a = array() ) { return new WP_Error( 'test_offline', 'offline' ); }
function wp_remote_post( $url, $a = array() ) {
    $GLOBALS['captured_posts'][] = array( 'url' => $url, 'args' => $a );
    return array( 'response' => array( 'code' => 200 ) );
}
function wp_safe_remote_post( $url, $a = array() ) { return wp_remote_post( $url, $a ); }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return ''; }
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
    private $json;
    public function __construct( $json ) { $this->json = $json; }
    public function get_json_params() { return $this->json; }
}

// ── Fake wpdb — routes by table + captures writes ─────────────────────
class FakeWpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $inserts = array();  // list of [table, data]
    public $updates = array();  // list of [table, data, where]
    public $rows    = array();  // canned get_row results keyed by table
    public $vars    = array();  // canned get_var results keyed by table
    public $results = array();  // canned get_results keyed by table
    private $next_insert_id = 500;

    public function prepare( $sql, ...$args ) {
        if ( count( $args ) === 1 && is_array( $args[0] ) ) { $args = $args[0]; }
        foreach ( $args as $a ) {
            $sql = preg_replace( '/%[dsf]/', is_numeric( $a ) ? $a : "'" . $a . "'", $sql, 1 );
        }
        return $sql;
    }
    private function table_of( $sql ) {
        foreach ( array( 'hdlv2_widget_config', 'hdlv2_widget_invites', 'hdlv2_widget_leads', 'hdlv2_form_progress', 'hdlv2_pending_leads' ) as $t ) {
            if ( strpos( $sql, $t ) !== false ) return $t;
        }
        return '';
    }
    public function get_row( $sql ) {
        $t = $this->table_of( $sql );
        // form_progress PDF-migration lookup inside complete_signup must miss
        // even when a canned widget_leads row exists (it filters on
        // stage1_pdf_stored_path).
        if ( 'hdlv2_widget_leads' === $t && strpos( $sql, 'stage1_pdf_stored_path' ) !== false ) return null;
        return $this->rows[ $t ] ?? null;
    }
    public function get_var( $sql ) {
        $t = $this->table_of( $sql );
        return $this->vars[ $t ] ?? null;
    }
    public function get_results( $sql ) {
        $t = $this->table_of( $sql );
        return $this->results[ $t ] ?? array();
    }
    public function insert( $table, $data, $format = null ) {
        $this->inserts[] = array( 'table' => $table, 'data' => $data );
        $this->insert_id = ++$this->next_insert_id;
        return 1;
    }
    public function update( $table, $data, $where, $format = null, $where_format = null ) {
        $this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
        return 1;
    }
    public function query( $sql ) { return 1; }

    public function last_insert_of( $table_suffix ) {
        for ( $i = count( $this->inserts ) - 1; $i >= 0; $i-- ) {
            if ( substr( $this->inserts[ $i ]['table'], -strlen( $table_suffix ) ) === $table_suffix ) {
                return $this->inserts[ $i ]['data'];
            }
        }
        return null;
    }
    public function last_update_of( $table_suffix ) {
        for ( $i = count( $this->updates ) - 1; $i >= 0; $i-- ) {
            if ( substr( $this->updates[ $i ]['table'], -strlen( $table_suffix ) ) === $table_suffix ) {
                return $this->updates[ $i ]['data'];
            }
        }
        return null;
    }
}

// ── Load real sources ─────────────────────────────────────────────────
require __DIR__ . '/../../includes/sprint-2/class-hdlv2-rate-calculator.php';
require __DIR__ . '/../../includes/sprint-2/class-hdlv2-stage1-commentary.php';
require __DIR__ . '/../../includes/sprint-1/class-hdlv2-widget-config.php';

// ── Assertion plumbing ────────────────────────────────────────────────
$PASS = 0; $FAIL = 0;
function check( $label, $cond ) {
    global $PASS, $FAIL;
    if ( $cond ) { $PASS++; echo "  PASS  $label\n"; }
    else         { $FAIL++; echo "  FAIL  $label\n"; }
}
function fresh_wpdb() {
    global $wpdb;
    $wpdb = new FakeWpdb();
    // Practitioner 206 widget config — webhook_url EMPTY so the optional
    // config-webhook branch (and HDLV2_Report_PDF dependency) is skipped.
    $cfg = new stdClass();
    $cfg->practitioner_user_id = 206;
    $cfg->webhook_url          = '';
    $cfg->notification_email   = '';
    $cfg->logo_url             = '';
    $cfg->logo_shape           = 'round';
    $wpdb->rows['hdlv2_widget_config'] = $cfg;
    return $wpdb;
}
function widget_instance() {
    $rc = new ReflectionClass( 'HDLV2_Widget_Config' );
    return $rc->newInstanceWithoutConstructor();
}
function call_private_static( $method, $args ) {
    $m = new ReflectionMethod( 'HDLV2_Widget_Config', $method );
    $m->setAccessible( true );
    return $m->invokeArgs( null, $args );
}
function base_lead_params( $email ) {
    return array(
        'practitioner_id'       => 206,
        'name'                  => 'Test Lead',
        'email'                 => $email,
        'phone'                 => '',
        'q1_age'                => 44,
        'q1_sex'                => 'male',
        'q2a'                   => 3,
        'q2b'                   => 'even',
        'q3'                    => 'b', 'q4' => 'b', 'q5' => 'b',
        'q6'                    => 'b', 'q7' => 'a', 'q8' => 'b', 'q9' => 'b',
        'rate_of_ageing_result' => 1.02,
    );
}

echo "── A. sanitize_visitor_location() validation matrix ──\n";
check( 'A0 method exists', method_exists( 'HDLV2_Widget_Config', 'sanitize_visitor_location' ) );
if ( method_exists( 'HDLV2_Widget_Config', 'sanitize_visitor_location' ) ) {
    $f = function ( $c, $r ) { return HDLV2_Widget_Config::sanitize_visitor_location( $c, $r ); };
    $v = $f( 'us', 'Texas' );
    check( 'A1 lowercase country upcased + region kept', $v['country'] === 'US' && $v['region'] === 'Texas' );
    $v = $f( 'USA', 'Texas' );
    check( 'A2 3-letter country → both discarded', $v['country'] === '' && $v['region'] === '' );
    $v = $f( '', 'Texas' );
    check( 'A3 empty country → region discarded too', $v['country'] === '' && $v['region'] === '' );
    $v = $f( 'DE', '' );
    check( 'A4 country without region kept', $v['country'] === 'DE' && $v['region'] === '' );
    $v = $f( 'gb', '  Greater London  ' );
    check( 'A5 region trimmed', $v['country'] === 'GB' && $v['region'] === 'Greater London' );
    $v = $f( 'FR', 'Île-de-France' );
    check( 'A6 unicode region preserved', $v['country'] === 'FR' && $v['region'] === 'Île-de-France' );
    $v = $f( 'AU', str_repeat( 'x', 80 ) );
    check( 'A7 oversize region truncated to 64', mb_strlen( $v['region'] ) === 64 );
    $v = $f( 'US', '<script>alert(1)</script>Texas' );
    check( 'A8 tags stripped from region', strpos( $v['region'], '<' ) === false && strpos( $v['region'], 'Texas' ) !== false );
    $v = $f( array( 'US' ), array( 'Texas' ) );
    check( 'A9 non-scalar input safe-discarded', $v['country'] === '' && $v['region'] === '' );
    $v = $f( 'U1', 'Texas' );
    check( 'A10 digit in country rejected', $v['country'] === '' && $v['region'] === '' );
} else {
    for ( $i = 1; $i <= 10; $i++ ) { check( "A$i (skipped — method missing)", false ); }
}

echo "── B. format_visitor_location() display helper ──\n";
check( 'B0 method exists', method_exists( 'HDLV2_Widget_Config', 'format_visitor_location' ) );
if ( method_exists( 'HDLV2_Widget_Config', 'format_visitor_location' ) ) {
    check( 'B1 region + country', HDLV2_Widget_Config::format_visitor_location( 'US', 'Texas' ) === 'Texas, US' );
    check( 'B2 country only', HDLV2_Widget_Config::format_visitor_location( 'US', '' ) === 'US' );
    check( 'B3 empty → empty string', HDLV2_Widget_Config::format_visitor_location( '', '' ) === '' );
    check( 'B4 null-safe', HDLV2_Widget_Config::format_visitor_location( null, null ) === '' );
} else {
    for ( $i = 1; $i <= 4; $i++ ) { check( "B$i (skipped — method missing)", false ); }
}

echo "── C. record_widget_lead() storage ──\n";
$wpdb = fresh_wpdb();
call_private_static( 'record_widget_lead', array( array(
    'practitioner_id' => 206, 'visitor_name' => 'C1', 'visitor_email' => 'c1@example.test',
    'visitor_age' => 44, 'rate' => 1.02, 'stage1_data' => array( 'q1_age' => 44 ),
    'visitor_country' => 'US', 'visitor_region' => 'Texas',
) ) );
$row = $wpdb->last_insert_of( 'hdlv2_widget_leads' );
check( 'C1 insert carries visitor_country', is_array( $row ) && ( $row['visitor_country'] ?? '' ) === 'US' );
check( 'C2 insert carries visitor_region', is_array( $row ) && ( $row['visitor_region'] ?? '' ) === 'Texas' );

$wpdb = fresh_wpdb();
call_private_static( 'record_widget_lead', array( array(
    'practitioner_id' => 206, 'visitor_name' => 'C3', 'visitor_email' => 'c3@example.test',
    'visitor_age' => 44, 'rate' => 1.02, 'stage1_data' => array(),
) ) );
$row = $wpdb->last_insert_of( 'hdlv2_widget_leads' );
check( 'C3 insert without location omits the columns', is_array( $row ) && ! array_key_exists( 'visitor_country', $row ) );

$wpdb = fresh_wpdb();
$wpdb->vars['hdlv2_widget_leads'] = 321; // existing row → UPDATE branch
call_private_static( 'record_widget_lead', array( array(
    'practitioner_id' => 206, 'visitor_name' => 'C4', 'visitor_email' => 'c4@example.test',
    'visitor_age' => 44, 'rate' => 1.02, 'stage1_data' => array(),
    'visitor_country' => 'GB', 'visitor_region' => 'Kent',
) ) );
$upd = $wpdb->last_update_of( 'hdlv2_widget_leads' );
check( 'C4 resubmission update refreshes location', is_array( $upd ) && ( $upd['visitor_country'] ?? '' ) === 'GB' && ( $upd['visitor_region'] ?? '' ) === 'Kent' );

$wpdb = fresh_wpdb();
$wpdb->vars['hdlv2_widget_leads'] = 321;
call_private_static( 'record_widget_lead', array( array(
    'practitioner_id' => 206, 'visitor_name' => 'C5', 'visitor_email' => 'c5@example.test',
    'visitor_age' => 44, 'rate' => 1.02, 'stage1_data' => array(),
) ) );
$upd = $wpdb->last_update_of( 'hdlv2_widget_leads' );
check( 'C5 geo-blocked resubmission never clobbers stored location', is_array( $upd ) && ! array_key_exists( 'visitor_country', $upd ) );

// C6/C7 — missing-column resilience: if the INSERT/UPDATE fails while the
// location columns are present (deploy window before the Phase AG migration
// ran, or a failed migration), the write must retry WITHOUT the location
// keys so the lead itself is never lost. Modeled by a wpdb whose writes
// fail whenever the row contains visitor_country.
class NoLocColumnWpdb extends FakeWpdb {
    public function insert( $table, $data, $format = null ) {
        if ( array_key_exists( 'visitor_country', $data ) ) {
            $this->last_error = "Unknown column 'visitor_country' in 'field list'";
            return false;
        }
        return parent::insert( $table, $data, $format );
    }
    public function update( $table, $data, $where, $format = null, $where_format = null ) {
        if ( array_key_exists( 'visitor_country', $data ) ) {
            $this->last_error = "Unknown column 'visitor_country' in 'field list'";
            return false;
        }
        return parent::update( $table, $data, $where, $format, $where_format );
    }
}

echo "── C6/C7. missing-column resilience (pre-migration window) ──\n";
$wpdb = new NoLocColumnWpdb();
$cfg = new stdClass();
$cfg->practitioner_user_id = 206; $cfg->webhook_url = ''; $cfg->notification_email = '';
$cfg->logo_url = ''; $cfg->logo_shape = 'round';
$wpdb->rows['hdlv2_widget_config'] = $cfg;
$id = call_private_static( 'record_widget_lead', array( array(
    'practitioner_id' => 206, 'visitor_name' => 'C6', 'visitor_email' => 'c6@example.test',
    'visitor_age' => 44, 'rate' => 1.02, 'stage1_data' => array(),
    'visitor_country' => 'US', 'visitor_region' => 'Texas',
) ) );
$row = $wpdb->last_insert_of( 'hdlv2_widget_leads' );
check( 'C6 lead INSERT retried without location when columns missing', $id > 0 && is_array( $row ) && ! array_key_exists( 'visitor_country', $row ) && $row['visitor_email'] === 'c6@example.test' );

$wpdb = new NoLocColumnWpdb();
$wpdb->rows['hdlv2_widget_config'] = $cfg;
call_private_static( 'complete_signup', array( array(
    'practitioner_id' => 206, 'visitor_name' => 'C7', 'visitor_email' => 'c7@example.test',
    'visitor_phone' => '', 'visitor_age' => 40, 'rate' => 1.0,
    'stage1_data' => array( 'q1_age' => 40 ), 'config' => $cfg,
    'visitor_country' => 'US', 'visitor_region' => 'Texas',
    'send_practitioner_notify' => false, 'send_make_pdf' => false,
) ) );
$fp = $wpdb->last_insert_of( 'hdlv2_form_progress' );
check( 'C7 form_progress INSERT retried without location when columns missing', is_array( $fp ) && ! array_key_exists( 'visitor_country', $fp ) && $fp['client_email'] === 'c7@example.test' );

echo "── D. rest_capture_lead() public path glue ──\n";
$wpdb = fresh_wpdb();
$inst = widget_instance();
$params = base_lead_params( 'd1@example.test' );
$params['visitor_country'] = 'us';
$params['visitor_region']  = 'Texas';
$resp = $inst->rest_capture_lead( new FakeRequest( $params ) );
check( 'D1 submission succeeds', is_array( $resp ) && ! empty( $resp['success'] ) );
$row = $wpdb->last_insert_of( 'hdlv2_widget_leads' );
check( 'D2 lead row stores US/Texas', is_array( $row ) && ( $row['visitor_country'] ?? '' ) === 'US' && ( $row['visitor_region'] ?? '' ) === 'Texas' );

$wpdb = fresh_wpdb();
$inst = widget_instance();
$params = base_lead_params( 'd3@example.test' );
$params['visitor_country'] = 'INVALID';
$params['visitor_region']  = str_repeat( 'y', 200 );
$resp = $inst->rest_capture_lead( new FakeRequest( $params ) );
check( 'D3 junk location NEVER rejects the lead', is_array( $resp ) && ! empty( $resp['success'] ) );
$row = $wpdb->last_insert_of( 'hdlv2_widget_leads' );
check( 'D4 junk location discarded (no columns written)', is_array( $row ) && ! array_key_exists( 'visitor_country', $row ) );

echo "── E. invite fast-path → form_progress ──\n";
$wpdb = fresh_wpdb();
$invite = new stdClass();
$invite->id = 77; $invite->status = 'pending'; $invite->expires_at = '2030-01-01 00:00:00';
$wpdb->rows['hdlv2_widget_invites'] = $invite;
$inst = widget_instance();
$params = base_lead_params( 'e1@example.test' );
$params['invite_token']    = str_repeat( 'ab', 32 ); // 64-hex
$params['visitor_country'] = 'US';
$params['visitor_region']  = 'Texas';
$resp = $inst->rest_capture_lead( new FakeRequest( $params ) );
check( 'E1 invite path succeeds', is_array( $resp ) && ! empty( $resp['form_token'] ) );
$fp = $wpdb->last_insert_of( 'hdlv2_form_progress' );
check( 'E2 form_progress row carries visitor_country', is_array( $fp ) && ( $fp['visitor_country'] ?? '' ) === 'US' );
check( 'E3 form_progress row carries visitor_region', is_array( $fp ) && ( $fp['visitor_region'] ?? '' ) === 'Texas' );
$lead = $wpdb->last_insert_of( 'hdlv2_widget_leads' );
check( 'E4 complete_signup widget_leads upsert carries location', is_array( $lead ) && ( $lead['visitor_country'] ?? '' ) === 'US' );

echo "── F. practitioner Confirm carry-over ──\n";
$wpdb = fresh_wpdb();
$lead_row = new stdClass();
$lead_row->id = 501; $lead_row->practitioner_user_id = 206;
$lead_row->visitor_name = 'F Lead'; $lead_row->visitor_email = 'f1@example.test';
$lead_row->visitor_age = 51; $lead_row->rate_of_ageing = 1.10;
$lead_row->stage1_data = json_encode( array( 'q1_age' => 51, 'q9' => 'b' ) );
$lead_row->status = 'pending';
$lead_row->visitor_country = 'CA'; $lead_row->visitor_region = 'British Columbia';
$wpdb->rows['hdlv2_widget_leads'] = $lead_row;
$inst = widget_instance();
$resp = $inst->rest_confirm_lead( new FakeRequest( array( 'lead_id' => 501 ) ) );
check( 'F1 confirm succeeds', is_array( $resp ) && ! empty( $resp['success'] ) && ! empty( $resp['form_token'] ) );
$fp = $wpdb->last_insert_of( 'hdlv2_form_progress' );
check( 'F2 client record inherits lead country', is_array( $fp ) && ( $fp['visitor_country'] ?? '' ) === 'CA' );
check( 'F3 client record inherits lead region', is_array( $fp ) && ( $fp['visitor_region'] ?? '' ) === 'British Columbia' );

// F4 — existing form_progress row (returning client): UPDATE must refresh location.
$wpdb = fresh_wpdb();
$existing_fp = new stdClass();
$existing_fp->id = 88; $existing_fp->token = str_repeat( 'cd', 32 );
$wpdb->rows['hdlv2_form_progress'] = $existing_fp;
call_private_static( 'complete_signup', array( array(
    'practitioner_id' => 206, 'visitor_name' => 'F4', 'visitor_email' => 'f4@example.test',
    'visitor_phone' => '', 'visitor_age' => 40, 'rate' => 1.0,
    'stage1_data' => array( 'q1_age' => 40 ), 'config' => $wpdb->rows['hdlv2_widget_config'],
    'visitor_country' => 'IE', 'visitor_region' => 'Leinster',
    'send_practitioner_notify' => false, 'send_make_pdf' => false,
) ) );
$upd = $wpdb->last_update_of( 'hdlv2_form_progress' );
check( 'F4 returning client update refreshes location', is_array( $upd ) && ( $upd['visitor_country'] ?? '' ) === 'IE' );

echo "── G. Make.com Stage-1 payload contract (24 keys, no location) ──\n";
$GLOBALS['captured_posts'] = array();
$wpdb = fresh_wpdb();
$inst = widget_instance();
$params = base_lead_params( 'g1@example.test' );
$params['visitor_country'] = 'US';
$params['visitor_region']  = 'Texas';
$inst->rest_capture_lead( new FakeRequest( $params ) );
$make = null;
foreach ( $GLOBALS['captured_posts'] as $p ) {
    if ( strpos( $p['url'], 'hook.example.test/stage1' ) !== false ) { $make = json_decode( $p['args']['body'], true ); }
}
check( 'G1 Make Stage-1 webhook fired', is_array( $make ) );
check( 'G2 payload has EXACTLY 24 keys', is_array( $make ) && count( $make ) === 24 );
check( 'G3 no top-level location keys', is_array( $make ) && ! array_key_exists( 'visitor_country', $make ) && ! array_key_exists( 'visitor_region', $make ) );
check( 'G4 stage1_data sub-object clean of location', is_array( $make ) && is_array( $make['stage1_data'] ?? null ) && ! array_key_exists( 'visitor_country', $make['stage1_data'] ) && ! array_key_exists( 'visitor_region', $make['stage1_data'] ) );

echo "── H. pending-leads list response ──\n";
$wpdb = fresh_wpdb();
$r1 = new stdClass();
$r1->id = 601; $r1->visitor_name = 'H One'; $r1->visitor_email = 'h1@example.test';
$r1->visitor_age = 39; $r1->rate_of_ageing = 0.97; $r1->created_at = '2026-08-06 10:00:00';
$r1->stage1_data = json_encode( array( 'q1_age' => 39 ) );
$r1->visitor_country = 'US'; $r1->visitor_region = 'Texas';
$r2 = new stdClass(); // legacy pre-migration row — location columns NULL
$r2->id = 602; $r2->visitor_name = 'H Two'; $r2->visitor_email = 'h2@example.test';
$r2->visitor_age = 61; $r2->rate_of_ageing = 1.21; $r2->created_at = '2026-08-05 10:00:00';
$r2->stage1_data = null;
$r2->visitor_country = null; $r2->visitor_region = null;
$wpdb->results['hdlv2_widget_leads'] = array( $r1, $r2 );
$inst = widget_instance();
$resp = $inst->rest_list_pending_leads( new FakeRequest( array() ) );
$leads = is_array( $resp ) && isset( $resp['leads'] ) ? $resp['leads'] : array();
check( 'H1 lead with location exposes display string', isset( $leads[0]['location'] ) && $leads[0]['location'] === 'Texas, US' );
check( 'H2 legacy lead exposes empty location (renders nothing)', isset( $leads[1] ) && array_key_exists( 'location', $leads[1] ) && $leads[1]['location'] === '' );

echo "\nPASS=$PASS FAIL=$FAIL\n";
exit( $FAIL === 0 ? 0 : 1 );
