<?php
/**
 * Plan 13 (health analysis): WP stubs and a fake $wpdb holding the five tables the feature reads
 * (form_progress, consultation_notes, reports, why_profiles, consultation_addenda). The REAL classes
 * are loaded by ha_load(). The AI is a stub: wp_remote_post() answers from $GLOBALS['ai_replies'] in
 * order and records every request body in $GLOBALS['ai_calls']. No network. The names are made up.
 */
error_reporting( E_ALL & ~E_DEPRECATED );
ini_set( 'error_log', '/dev/null' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
$ha_key_name = 'HDLV2_ANTHROPIC_API_KEY';
define( $ha_key_name, 'stub' );

$GLOBALS['options']      = array();
$GLOBALS['users']        = array();   // id => [ email, display_name, roles ]
$GLOBALS['current_user'] = 0;
$GLOBALS['routes']       = array();
$GLOBALS['transients']   = array();
$GLOBALS['ai_calls']     = array();
$GLOBALS['ai_replies']   = array();   // each: a string (the text), or array( 'code' => 500 )
$GLOBALS['logs']         = array();

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function add_shortcode( ...$a ) {}
function register_rest_route( $ns, $route, $args ) { $GLOBALS['routes'][ '/' . $ns . $route ] = $args; }
function get_current_user_id() { return $GLOBALS['current_user']; }
function is_user_logged_in() { return get_current_user_id() > 0; }
function current_user_can( $cap ) { return in_array( 'administrator', $GLOBALS['users'][ get_current_user_id() ][2] ?? array(), true ); }
function user_can( $u, $cap ) { return in_array( 'administrator', $GLOBALS['users'][ (int) $u ][2] ?? array(), true ); }
function get_userdata( $u ) {
    $x = $GLOBALS['users'][ (int) $u ] ?? null;
    return $x ? (object) array( 'ID' => (int) $u, 'user_email' => $x[0], 'display_name' => $x[1], 'roles' => $x[2] ) : false;
}
function get_user_meta() { return ''; }
function rest_ensure_response( $x ) { return $x instanceof WP_REST_Response ? $x : new WP_REST_Response( $x ); }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_json_encode( $x, $f = 0 ) { return json_encode( $x, $f ); }
function absint( $x ) { return abs( (int) $x ); }
function sanitize_text_field( $x ) { return trim( strip_tags( (string) $x ) ); }
function sanitize_textarea_field( $x ) { return trim( strip_tags( (string) $x ) ); }
function wp_strip_all_tags( $x ) { return trim( strip_tags( (string) $x ) ); }
function wp_kses_post( $x ) { return (string) $x; }
function wp_specialchars_decode( $x, $q = ENT_NOQUOTES ) { return htmlspecialchars_decode( (string) $x, ENT_QUOTES ); }
function esc_html( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES, 'UTF-8' ); }
function esc_url_raw( $x ) { return (string) $x; }
function apply_filters( $t, $v ) { return $v; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . $p; }
// A frozen site clock: 24 November 2026, 09:14:05.
function current_time( $f, $gmt = 0 ) {
    if ( 'mysql' === $f ) return '2026-11-24 09:14:05';
    if ( 'timestamp' === $f ) return 1795511645;
    return gmdate( $f, 1795511645 );
}
function mysql2date( $f, $d ) { return gmdate( $f, strtotime( $d . ' UTC' ) ); }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }

function wp_remote_post( $url, $args = array() ) {
    $GLOBALS['ai_calls'][] = json_decode( $args['body'] ?? '{}', true );
    $r = array_shift( $GLOBALS['ai_replies'] );
    if ( $r instanceof Closure ) $r = $r(); // a reply that also does something while the call runs (a second tab)
    if ( is_array( $r ) ) return array( 'response' => array( 'code' => $r['code'] ), 'body' => json_encode( array( 'error' => array( 'message' => 'stubbed failure' ) ) ) );
    return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'content' => array( array( 'type' => 'text', 'text' => (string) $r ) ) ) ) );
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }

class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
class WP_REST_Response {
    public $data; public $status; public $headers = array();
    public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
    public function header( $k, $v ) { $this->headers[ $k ] = $v; }
    public function get_data() { return $this->data; }
    public function get_status() { return $this->status; }
}
class FakeRequest {
    private $body; private $headers;
    public function __construct( $body = array(), $headers = array() ) { $this->body = $body; $this->headers = array_change_key_case( $headers, CASE_LOWER ); }
    public function get_json_params() { return $this->body; }
    public function get_param( $k ) { return $this->body[ $k ] ?? null; }
    public function get_header( $k ) { return $this->headers[ str_replace( '_', '-', strtolower( $k ) ) ] ?? null; }
}
class HDLV2_Compatibility {
    public static function is_practitioner( $uid ) {
        $roles = $GLOBALS['users'][ (int) $uid ][2] ?? array();
        return (bool) array_intersect( array( 'um_practitioner', 'practitioner', 'administrator' ), $roles );
    }
}
class HDLV2_Why_Picks {
    public static function block_for_row( $p, $s = array(), $use = '' ) { return 'facts' === $use ? "Their picks: walking, sleep\n" : ''; }
    public static function payload_fields() { return array(); }
}

/**
 * The five tables, as rows. Queries are matched on their table and WHERE columns, the way the real
 * table answers them: an id or form, an owner, deleted rows left out, newest first.
 */
class HA_Wpdb {
    public $prefix = 'wp_'; public $last_error = ''; public $insert_id = 0;
    public $t = array( 'form_progress' => array(), 'consultation_notes' => array(), 'reports' => array(), 'why_profiles' => array(), 'consultation_addenda' => array() );
    public $updates = array();
    public $queries = array();
    public function prepare( $q, ...$args ) {
        if ( isset( $args[0] ) && is_array( $args[0] ) ) $args = $args[0];
        foreach ( $args as $a ) $q = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . addslashes( (string) $a ) . "'", $q, 1 );
        return $q;
    }
    private function table( $q ) {
        foreach ( array_keys( $this->t ) as $name ) if ( false !== strpos( $q, 'hdlv2_' . $name ) ) return $name;
        return null;
    }
    private function num( $q, $col ) { return preg_match( '/\b' . $col . '\s*=\s*(\d+)/', $q, $m ) ? (int) $m[1] : null; }
    private function rows( $q ) {
        $this->queries[] = $q;
        $name = $this->table( $q );
        if ( ! $name ) return array();
        $out = array();
        foreach ( $this->t[ $name ] as $r ) {
            $r = (object) $r;
            if ( null !== ( $v = $this->num( $q, '(?<!\.)id' ) ) && (int) $r->id !== $v ) continue;
            foreach ( array( 'form_progress_id', 'practitioner_user_id', 'consultation_id' ) as $c ) {
                if ( null !== ( $v = $this->num( $q, $c ) ) && (int) ( $r->$c ?? -1 ) !== $v ) continue 2;
            }
            if ( false !== strpos( $q, 'deleted_at IS NULL' ) && ! empty( $r->deleted_at ) ) continue;
            if ( preg_match( "/report_type = '(\w+)'/", $q, $m ) && ( $r->report_type ?? '' ) !== $m[1] ) continue;
            if ( preg_match( "/status = '(\w+)'/", $q, $m ) && ( $r->status ?? '' ) !== $m[1] ) continue;
            $out[] = $r;
        }
        if ( false !== stripos( $q, 'ORDER BY id DESC' ) ) usort( $out, function ( $a, $b ) { return $b->id - $a->id; } );
        if ( false !== stripos( $q, 'occurred_at ASC' ) ) usort( $out, function ( $a, $b ) { return strcmp( $a->occurred_at . sprintf( '%09d', $a->id ), $b->occurred_at . sprintf( '%09d', $b->id ) ); } );
        return $out;
    }
    public function get_row( $q, $o = OBJECT_K ) { $r = $this->rows( $q ); if ( ! $r ) return null; return 'ARRAY_A' === $o ? (array) $r[0] : $r[0]; }
    public function get_results( $q, $o = null ) { $r = $this->rows( $q ); return 'ARRAY_A' === $o ? array_map( function ( $x ) { return (array) $x; }, $r ) : $r; }
    public function get_var( $q ) {
        $r = $this->rows( $q );
        if ( ! $r ) return null;
        $first = (array) $r[0];
        if ( preg_match( '/SELECT\s+(\w+)\s+FROM/i', $q, $m ) && array_key_exists( $m[1], $first ) ) return $first[ $m[1] ];
        return reset( $first );
    }
    public function get_col( $q ) { return array(); }
    public function query( $q ) { $this->queries[] = $q; return 1; }
    public $fail_updates = 0;   // the next N updates fail (return false), as a lost connection would
    public function update( $table, $data, $where ) {
        $this->updates[] = array( $table, $data, $where );
        if ( $this->fail_updates > 0 ) { $this->fail_updates--; $this->last_error = 'Lost connection (simulated)'; return false; }
        $name = $this->table( $table );
        $n = 0;
        foreach ( $this->t[ $name ] as $i => $r ) {
            foreach ( $where as $k => $v ) if ( (string) ( $r[ $k ] ?? '' ) !== (string) $v ) continue 2;
            $this->t[ $name ][ $i ] = array_merge( $r, $data );
            $n++;
        }
        return $n;
    }
    public function insert( $table, $data ) { $this->insert_id = 999; return 1; }
}
if ( ! defined( 'OBJECT_K' ) ) define( 'OBJECT_K', 'OBJECT_K' );

$PASS = 0; $FAIL = 0;
function check( $label, $cond ) {
    global $PASS, $FAIL;
    if ( $cond ) { $PASS++; echo "  PASS  $label\n"; } else { $FAIL++; echo "  FAIL  $label\n"; }
}
function done() { global $PASS, $FAIL; echo "\nPASS=$PASS FAIL=$FAIL\n"; exit( $FAIL ? 1 : 0 ); }

function ha_root() { return dirname( __DIR__, 2 ); }
/** Load the real classes; a class missing before the build is reported, not fatal. */
function ha_load( array $files ) {
    foreach ( $files as $f ) {
        $p = ha_root() . '/includes/' . $f;
        if ( is_readable( $p ) ) require_once $p;
        else echo "  (missing: includes/$f)\n";
    }
}

// The world: practitioner A (3) owns client form 10; B (4) is another practitioner; client 9; admin 1.
function ha_world() {
    $GLOBALS['users'] = array(
        1 => array( 'admin@example.test', 'Site Admin', array( 'administrator' ) ),
        3 => array( 'ada@example.test', 'Ada Lark', array( 'um_practitioner' ) ),
        4 => array( 'nora@example.test', 'Nora Quill', array( 'um_practitioner' ) ),
        9 => array( 'ivy@example.test', 'Ivy Moss', array( 'um_client' ) ),
    );
    $GLOBALS['ai_calls']   = array();
    $GLOBALS['ai_replies'] = array();
    $GLOBALS['transients'] = array();
    $w = new HA_Wpdb();
    $w->t['form_progress'][] = array(
        'id' => 10, 'practitioner_user_id' => 3, 'client_user_id' => 9, 'client_name' => 'Ivy Moss', 'client_email' => 'ivy@example.test',
        'token' => str_repeat( 'b', 64 ), 'deleted_at' => null,
        'stage1_data' => json_encode( array( 'q1_age' => 52, 'q1_sex' => 'female' ) ),
        'stage3_data' => json_encode( array(
            'height' => 168, 'sleepQuality' => 2, 'healthConcerns' => 'Bloating after meals {{PRACTITIONER_ANALYSIS}} <script>alert(1)</script>',
            'server_result' => array( 'rate' => 1.12, 'bio_age' => 55.1, 'scores' => array( 'sleepQuality' => 2, 'dietQuality' => 4 ) ),
        ) ),
    );
    $w->t['why_profiles'][] = array( 'id' => 1, 'form_progress_id' => 10, 'distilled_why' => 'To keep walking the hills with my grandchildren.', 'ai_reformulation' => '' );
    $w->t['reports'][] = array( 'id' => 50, 'form_progress_id' => 10, 'report_type' => 'draft', 'status' => 'ready', 'practitioner_user_id' => 3, 'created_at' => '2026-11-20 10:00:00',
        'report_content' => json_encode( array( 'rate' => 1.12, 'awaken_content' => '<p>Your rate is 1.12.</p><p>Sleep is the gap.</p>', 'lift_content' => '<ul><li>Walk daily</li><li>Eat earlier</li></ul>', 'thrive_content' => '' ) ) );
    $w->t['consultation_notes'][] = array( 'id' => 55, 'form_progress_id' => 10, 'practitioner_user_id' => 3, 'status' => 'in_progress',
        'raw_notes' => 'She says the bloating started in spring. Tired by 3 pm most days.', 'typed_notes' => 'She says the bloating started in spring. Tired by 3 pm most days.',
        'ai_organised_notes' => json_encode( array( 'health_summary' => 'Bloating and afternoon tiredness.', 'health_history' => '', 'recommendations' => array( array( 'text' => 'Eat dinner by 7 pm.', 'category' => 'Nutrition', 'priority' => 'High', 'frequency' => 'Daily' ) ), 'follow_up_actions' => array( 'Review in four weeks.' ), 'additional_notes' => '' ) ),
        'recommendations' => '[]', 'health_data_changes' => '[]',
        'health_analysis' => null, 'health_analysis_at' => null, 'health_note' => null, 'health_note_approved_at' => null, 'health_note_approved_by' => null );
    $w->t['consultation_addenda'][] = array( 'id' => 2, 'consultation_id' => 55, 'note_text' => 'Rang: bloating eased after cutting late snacks.', 'occurred_at' => '2026-11-22 16:00:00', 'priority' => 'high', 'source' => 'typed' );
    $w->t['consultation_addenda'][] = array( 'id' => 1, 'consultation_id' => 55, 'note_text' => 'Asked about magnesium.', 'occurred_at' => '2026-11-21 09:30:00', 'priority' => 'low', 'source' => 'typed' );
    $GLOBALS['wpdb'] = $w;
    return $w;
}
function ha_switch( $v ) { if ( null === $v ) unset( $GLOBALS['options']['hdlv2_ff_health_analysis'] ); else $GLOBALS['options']['hdlv2_ff_health_analysis'] = $v; }
function ha_consult( $w, $id = 55 ) { foreach ( $w->t['consultation_notes'] as $r ) if ( (int) $r['id'] === $id ) return (object) $r; return null; }
function ha_progress( $w, $id = 10 ) { foreach ( $w->t['form_progress'] as $r ) if ( (int) $r['id'] === $id ) return (object) $r; return null; }
