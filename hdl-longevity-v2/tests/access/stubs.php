<?php
/**
 * Plan 14, Part A (the access gate): WP stubs and a fake $wpdb holding the tables the doors read
 * (form_progress, reports, flight_plans, flight_plan_ticks, checkins, widget_invites, widget_leads). The REAL classes
 * are loaded by ac_load(). Every write is recorded, so "a locked door stores nothing" is checkable.
 * No network. The names are made up.
 */
error_reporting( E_ALL & ~E_DEPRECATED );
ini_set( 'error_log', '/dev/null' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT_K', 'OBJECT_K' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HDLV2_PLUGIN_URL', 'https://example.test/wp-content/plugins/hdl-longevity-v2/' );
define( 'HDLV2_VERSION', 'test' );

$GLOBALS['options']      = array();
$GLOBALS['users']        = array();   // id => [ email, display_name, roles ]
$GLOBALS['current_user'] = 0;
$GLOBALS['routes']       = array();
$GLOBALS['transients']   = array();
$GLOBALS['enqueued']     = array();
$GLOBALS['calls']        = array();   // what the doors let through to (generate, context, env gate)
$_GET                    = array();

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['options'][ $k ] = $v; return true; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function add_shortcode( ...$a ) {}
function register_rest_route( $ns, $route, $args ) { $GLOBALS['routes'][ '/' . $ns . $route ] = $args; }
function get_current_user_id() { return $GLOBALS['current_user']; }
function is_user_logged_in() { return get_current_user_id() > 0; }
function current_user_can( $cap ) { return user_can( get_current_user_id(), $cap ); }
function user_can( $u, $cap ) { return in_array( 'administrator', $GLOBALS['users'][ (int) $u ][2] ?? array(), true ); }
function get_userdata( $u ) {
    $x = $GLOBALS['users'][ (int) $u ] ?? null;
    return $x ? (object) array( 'ID' => (int) $u, 'user_email' => $x[0], 'display_name' => $x[1], 'user_login' => $x[1], 'roles' => $x[2] ) : false;
}
function get_user_meta( $u, $k = '', $s = false ) { return 'hdlv2_flight_plan_day' === $k ? strtolower( date( 'l' ) ) : ''; } // every client's plan day is today
function rest_ensure_response( $x ) { return $x instanceof WP_REST_Response ? $x : new WP_REST_Response( $x ); }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_json_encode( $x, $f = 0 ) { return json_encode( $x, $f ); }
function absint( $x ) { return abs( (int) $x ); }
function sanitize_text_field( $x ) { return trim( strip_tags( (string) $x ) ); }
function wp_unslash( $x ) { return $x; }
function esc_html( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $x ) { return (string) $x; }
function apply_filters( $t, $v ) { return $v; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function site_url( $p = '' ) { return 'https://example.test' . $p; }
function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . $p; }
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function wp_enqueue_script( $h, ...$a ) { $GLOBALS['enqueued'][] = $h; }
function wp_enqueue_style( $h, ...$a ) { $GLOBALS['enqueued'][] = $h; }
function wp_localize_script( ...$a ) {}
function current_time( $f, $gmt = 0 ) { return 'mysql' === $f ? '2026-11-24 09:14:05' : ( 'timestamp' === $f ? 1795511645 : gmdate( $f, 1795511645 ) ); }
function wp_date( $f, $ts = null ) { return gmdate( $f, $ts ?? 1795511645 ); }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function wp_next_scheduled( ...$a ) { return false; }
function wp_schedule_single_event( ...$a ) { $GLOBALS['calls'][] = 'schedule'; return true; }
function wp_remote_post( ...$a ) { $GLOBALS['calls'][] = 'remote_post'; return array(); }

class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
class WP_REST_Response {
    public $data; public $status;
    public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
    public function get_data() { return $this->data; }
    public function get_status() { return $this->status; }
}
class FakeRequest implements ArrayAccess {
    private $body;
    public function __construct( $body = array() ) { $this->body = $body; }
    public function get_json_params() { return $this->body; }
    public function get_param( $k ) { return $this->body[ $k ] ?? null; }
    public function get_header( $k ) { return null; }
    public function offsetExists( $k ): bool { return isset( $this->body[ $k ] ); }
    public function offsetGet( $k ): mixed { return $this->body[ $k ] ?? null; }
    public function offsetSet( $k, $v ): void { $this->body[ $k ] = $v; }
    public function offsetUnset( $k ): void { unset( $this->body[ $k ] ); }
}
class HDLV2_Compatibility {
    public static function is_practitioner( $uid ) {
        $roles = $GLOBALS['users'][ (int) $uid ][2] ?? array();
        return (bool) array_intersect( array( 'um_practitioner', 'practitioner', 'administrator' ), $roles );
    }
    public static function practitioner_owns_client( $p, $c ) {
        if ( user_can( $p, 'manage_options' ) ) return true;
        foreach ( $GLOBALS['wpdb']->t['form_progress'] as $r ) {
            if ( (int) $r['practitioner_user_id'] === (int) $p && (int) $r['client_user_id'] === (int) $c && empty( $r['deleted_at'] ) ) return true;
        }
        return false;
    }
}
// What a door lets through to: recorded, never run.
class HDLV2_Idempotency {
    public static function wrap( $req, $scope, $fn ) { return $fn(); }
    public static function wrap_ai( $req, $scope, $fn ) { return $fn(); }
}
class HDLV2_Env {
    public static function gate( $what ) { $GLOBALS['calls'][] = 'env_gate:' . $what; return false; }
    public static function client_campaign_gate( $what ) { $GLOBALS['calls'][] = 'campaign:' . $what; return false; }
}
class HDLV2_Context_Builder {
    public static function build_context( $client_id, $tier ) { $GLOBALS['calls'][] = 'context:' . (int) $client_id; return array( 'engagement_signal' => 'medium' ); }
}

/** The tables, as rows; queries matched on their table and WHERE columns, the way the real table answers. */
class AC_Wpdb {
    public $prefix = 'wp_'; public $last_error = ''; public $insert_id = 0;
    public $t = array( 'form_progress' => array(), 'reports' => array(), 'flight_plans' => array(), 'flight_plan_ticks' => array(), 'checkins' => array(), 'widget_invites' => array(), 'widget_leads' => array() );
    public $writes = array();
    public $queries = array();
    public function prepare( $q, ...$args ) {
        if ( isset( $args[0] ) && is_array( $args[0] ) ) $args = $args[0];
        foreach ( $args as $a ) $q = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . addslashes( (string) $a ) . "'", $q, 1 );
        return $q;
    }
    private function table( $q ) {
        // The longest name first: flight_plan_ticks before flight_plans.
        foreach ( array( 'flight_plan_ticks', 'flight_plans', 'form_progress', 'reports', 'checkins', 'widget_invites', 'widget_leads' ) as $name ) {
            if ( false !== strpos( $q, 'hdlv2_' . $name ) ) return $name;
        }
        return null;
    }
    private function num( $q, $col ) { return preg_match( '/\b' . $col . '\s*=\s*(\d+)/', $q, $m ) ? (int) $m[1] : null; }
    private function rows( $q ) {
        $this->queries[] = $q;
        $name = $this->table( $q );
        if ( ! $name ) return array();
        if ( 'flight_plan_ticks' === $name && preg_match( '/t\.id = (\d+)/', $q, $m ) ) {   // the tick → plan join
            foreach ( $this->t['flight_plan_ticks'] as $tk ) {
                if ( (int) $tk['id'] !== (int) $m[1] ) continue;
                foreach ( $this->t['flight_plans'] as $p ) {
                    if ( (int) $p['id'] === (int) $tk['flight_plan_id'] && empty( $p['deleted_at'] ) ) return array( (object) array( 'flight_plan_id' => $p['id'], 'client_id' => $p['client_id'] ) );
                }
            }
            return array();
        }
        $out = array();
        foreach ( $this->t[ $name ] as $r ) {
            $r = (object) $r;
            if ( null !== ( $v = $this->num( $q, '(?<![\.\w])id' ) ) && (int) $r->id !== $v ) continue;
            foreach ( array( 'form_progress_id', 'practitioner_user_id', 'client_user_id', 'client_id' ) as $c ) {
                if ( null !== ( $v = $this->num( $q, '(?<![\.\w])' . $c ) ) && (int) ( $r->$c ?? -1 ) !== $v ) continue 2;
            }
            if ( preg_match( "/token = '([a-f0-9]+)'/", $q, $m ) && ( $r->token ?? '' ) !== $m[1] ) continue;
            if ( false !== strpos( $q, 'deleted_at IS NULL' ) && ! empty( $r->deleted_at ) ) continue;
            if ( preg_match( "/report_type = '(\w+)'/", $q, $m ) && ( $r->report_type ?? '' ) !== $m[1] ) continue;
            if ( preg_match( "/client_email\) = '([^']+)'/", $q, $m ) && strtolower( $r->client_email ?? '' ) !== $m[1] ) continue;
            if ( preg_match( "/week_start = '([\d-]+)'/", $q, $m ) && ( $r->week_start ?? '' ) !== $m[1] ) continue;
            if ( 'widget_leads' === $name ) {   // the lead queries also filter on status and on an id list
                if ( preg_match( "/status = '(\w+)'/", $q, $m ) && ( $r->status ?? '' ) !== $m[1] ) continue;
                if ( preg_match( '/\bid IN \(([\d,]+)\)/', $q, $m ) && ! in_array( (int) $r->id, array_map( 'intval', explode( ',', $m[1] ) ), true ) ) continue;
            }
            $out[] = $r;
        }
        if ( false !== stripos( $q, 'ORDER BY id DESC' ) ) usort( $out, function ( $a, $b ) { return $b->id - $a->id; } );
        return $out;
    }
    public function get_row( $q, $o = null ) { $r = $this->rows( $q ); return $r ? ( 'ARRAY_A' === $o ? (array) $r[0] : $r[0] ) : null; }
    public function get_results( $q, $o = null ) { return $this->rows( $q ); }
    public function get_var( $q ) {
        $r = $this->rows( $q );
        if ( ! $r ) return null;
        $first = (array) $r[0];
        if ( preg_match( '/SELECT\s+(\w+)\s+FROM/i', $q, $m ) && array_key_exists( $m[1], $first ) ) return $first[ $m[1] ];
        return reset( $first );
    }
    public function get_col( $q ) { return array(); }
    public function query( $q ) {
        $this->queries[] = $q;
        if ( preg_match( '/^\s*(UPDATE|INSERT|DELETE|REPLACE)/i', $q ) ) $this->writes[] = $q;
        return 1;
    }
    public function update( $table, $data, $where ) {
        $this->writes[] = array( 'update', $table, $data, $where );
        $name = $this->table( $table );
        $n = 0;
        foreach ( $this->t[ $name ] as $i => $r ) {
            foreach ( $where as $k => $v ) if ( (string) ( $r[ $k ] ?? '' ) !== (string) $v ) continue 2;
            $this->t[ $name ][ $i ] = array_merge( $r, $data );
            $n++;
        }
        return $n;
    }
    public function insert( $table, $data ) { $this->writes[] = array( 'insert', $table, $data ); $this->insert_id = 999; return 1; }
}

$PASS = 0; $FAIL = 0;
function check( $label, $cond ) {
    global $PASS, $FAIL;
    if ( $cond ) { $PASS++; echo "  PASS  $label\n"; } else { $FAIL++; echo "  FAIL  $label\n"; }
}
function done() { global $PASS, $FAIL; echo "\nPASS=$PASS FAIL=$FAIL\n"; exit( $FAIL ? 1 : 0 ); }

function ac_root() { return dirname( __DIR__, 2 ); }
/** Load the real classes; a class missing before the build is reported, not fatal. */
function ac_load( array $files ) {
    foreach ( $files as $f ) {
        $p = ac_root() . '/includes/' . $f;
        if ( is_readable( $p ) ) require_once $p;
        else echo "  (missing: includes/$f)\n";
    }
}

/**
 * The world. Practitioner Ada (3) owns three clients; Nora (4) is another practitioner; admin 1.
 *   Ivy (9),  form 10: Stage 1 and the Full Report form, no final report, no plan  → locked (practitioner)
 *   Finn (11), form 12: final report                                                → open
 *   Rosa (13), form 14: a plan already running, no final report (an existing client) → open
 */
function ac_world() {
    $GLOBALS['users'] = array(
        1  => array( 'admin@example.test', 'Site Admin', array( 'administrator' ) ),
        3  => array( 'ada@example.test', 'Ada Lark', array( 'um_practitioner' ) ),
        4  => array( 'nora@example.test', 'Nora Quill', array( 'um_practitioner' ) ),
        9  => array( 'ivy@example.test', 'Ivy Moss', array( 'um_client' ) ),
        11 => array( 'finn@example.test', 'Finn Reed', array( 'um_client' ) ),
        13 => array( 'rosa@example.test', 'Rosa Vale', array( 'um_client' ) ),
    );
    $GLOBALS['current_user'] = 0;
    $GLOBALS['calls']        = array();
    $GLOBALS['enqueued']     = array();
    $_GET                    = array();
    $w = new AC_Wpdb();
    foreach ( array( array( 10, 9, 'b' ), array( 12, 11, 'c' ), array( 14, 13, 'd' ) ) as $f ) {
        $w->t['form_progress'][] = array( 'id' => $f[0], 'client_user_id' => $f[1], 'practitioner_user_id' => 3, 'token' => str_repeat( $f[2], 64 ),
            'client_email' => $GLOBALS['users'][ $f[1] ][0], 'deleted_at' => null, 'stage3_completed_at' => '2026-11-20 10:00:00' );
    }
    $w->t['reports'][]      = array( 'id' => 70, 'form_progress_id' => 12, 'report_type' => 'final', 'status' => 'ready' );
    $w->t['reports'][]      = array( 'id' => 71, 'form_progress_id' => 10, 'report_type' => 'draft', 'status' => 'ready' );
    $w->t['flight_plans'][] = array( 'id' => 80, 'client_id' => 13, 'week_number' => 3, 'week_start' => '2026-11-23', 'deleted_at' => null, 'created_at' => '2026-11-22 18:00:00',
        'pdf_url' => 'https://example.test/plan.pdf', 'plan_data' => '{}', 'shopping_list' => '[]', 'identity_statement' => '', 'weekly_targets' => '[]', 'status' => 'active', 'generation_trigger' => 'auto' );
    $w->t['flight_plans'][] = array( 'id' => 81, 'client_id' => 9, 'week_number' => 1, 'week_start' => '2026-11-16', 'deleted_at' => '2026-11-17 00:00:00' ); // archived: does not count
    $w->t['flight_plan_ticks'][] = array( 'id' => 900, 'flight_plan_id' => 80, 'deleted_at' => null );
    $GLOBALS['wpdb'] = $w;
    return $w;
}
function ac_switch( $v ) { if ( null === $v ) unset( $GLOBALS['options']['hdlv2_ff_access_gate'] ); else $GLOBALS['options']['hdlv2_ff_access_gate'] = $v; }
function ac_locked( $r ) { return $r instanceof WP_Error && 'locked' === $r->get_error_code() && 403 === ( $r->get_error_data()['status'] ?? 0 ); }
function ac_progress( $w, $id ) { foreach ( $w->t['form_progress'] as $r ) if ( (int) $r['id'] === (int) $id ) return (object) $r; return null; }
