<?php
/**
 * Dashboard products (v0.48.36): WP stubs + a fake $wpdb that answers the five lookups
 * HDLV2_Products makes. Standalone, no WordPress, no DB. The real HDLV2_Offer, HDLV2_Challenge,
 * HDLV2_Healthspan_Timeline and HDLV2_Timeline_Links are loaded, so their rules are the ones tested.
 */
error_reporting( E_ALL & ~E_DEPRECATED );
ini_set( 'error_log', '/dev/null' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['options'] = array();
$GLOBALS['usermeta'] = array();
$GLOBALS['users'] = array();   // id => [ email, display_name, roles ]
$GLOBALS['routes'] = array();
$GLOBALS['owns'] = array();    // "practitioner|client" pairs
$GLOBALS['mails'] = array();
$GLOBALS['current_user'] = 0;

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function add_shortcode( ...$a ) {}
function register_rest_route( $ns, $route, $args ) { $GLOBALS['routes'][ $ns . $route ] = $args; }
function get_current_user_id() { return $GLOBALS['current_user']; }
function is_user_logged_in() { return get_current_user_id() > 0; }
function is_admin() { return false; }
function current_user_can( $cap ) { return in_array( 'administrator', $GLOBALS['users'][ get_current_user_id() ]['roles'] ?? array(), true ); }
function user_can( $u, $cap ) { return in_array( 'administrator', $GLOBALS['users'][ (int) $u ]['roles'] ?? array(), true ); }
function rest_ensure_response( $x ) { return $x; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_json_encode( $x, $f = 0 ) { return json_encode( $x, $f ); }
function absint( $x ) { return abs( (int) $x ); }
function sanitize_text_field( $x ) { return trim( strip_tags( (string) $x ) ); }
function sanitize_textarea_field( $x ) { return trim( strip_tags( (string) $x ) ); }
function esc_html( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES, 'UTF-8' ); }
function esc_url_raw( $x ) { return (string) $x; }
function wp_date( $fmt, $ts = null ) { return gmdate( $fmt, $ts ?? time() ); }
function date_i18n( $fmt, $ts = null ) { return gmdate( $fmt, $ts ?? time() ); }
function add_query_arg( $key, $value, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value; }
function apply_filters( $tag, $value ) { return $value; }
function home_url( $p = '' ) { return 'https://stby.test' . $p; }
function site_url( $p = '' ) { return 'https://stby.test' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function wp_enqueue_style( ...$a ) {}
function wp_login_url( $r = '' ) { return 'https://stby.test/wp-login.php?redirect_to=' . rawurlencode( $r ); }
function um_get_core_page( $p ) { return 'https://stby.test/' . $p . '/'; }
function wp_mail( $to, $subject, $html ) { $GLOBALS['mails'][] = $html; return true; }
function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['usermeta'][ $u ][ $k ] ?? ''; }
function get_userdata( $u ) {
    $x = $GLOBALS['users'][ (int) $u ] ?? null;
    return $x ? (object) array( 'ID' => (int) $u, 'user_email' => $x[0], 'display_name' => $x[1], 'first_name' => '', 'roles' => $x[2] ) : false;
}

class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
}

class HDLV2_Compatibility {
    public static function is_practitioner( $u ) {
        $roles = $GLOBALS['users'][ (int) $u ]['roles'] ?? array();
        return (bool) array_intersect( array( 'um_practitioner', 'practitioner', 'administrator' ), $roles );
    }
    public static function practitioner_owns_client( $p, $c ) { return user_can( $p, 'manage_options' ) || in_array( (int) $p . '|' . (int) $c, $GLOBALS['owns'], true ); }
    public static function get_client_journey_state( $u ) { return 'brand-new'; }
}

/** Rows by the key each lookup uses; every call is counted. */
class ProductsWpdb {
    public $prefix = 'wp_';
    public $calls = array();
    public $progress = array(); // client id => row
    public $reports = array();  // progress id => row (report_type)
    public $tickets = array();  // lower email => row (practitioner_id)
    public $plans = array();    // client id => row (id, week_number)
    public $packs = array();    // progress id => row
    public $leads = array();    // "practitioner|email"
    private $args = array();

    public function prepare( $sql, ...$args ) { $this->args = $args; return $sql; }
    private function a( $i ) { return $this->args[ $i ] ?? null; }
    public function get_row( $sql ) {
        $this->calls[] = $sql;
        if ( false !== strpos( $sql, 'hdlv2_form_progress' ) ) return $this->progress[ (int) $this->a( 0 ) ] ?? null;
        if ( false !== strpos( $sql, 'hdlv2_reports' ) ) return $this->reports[ (int) $this->a( 0 ) ] ?? null;
        if ( false !== strpos( $sql, 'hdlv2_widget_invites' ) && false !== strpos( $sql, "'full_report'" ) ) return $this->tickets[ strtolower( (string) $this->a( 0 ) ) ] ?? null;
        if ( false !== strpos( $sql, 'hdlv2_flight_plans' ) ) return $this->plans[ (int) $this->a( 0 ) ] ?? null;
        if ( false !== strpos( $sql, 'hdlv2_timeline_packs' ) ) return $this->packs[ (int) $this->a( 0 ) ] ?? null;
        return null;
    }
    public function get_var( $sql ) {
        $this->calls[] = $sql;
        if ( false !== strpos( $sql, 'hdlv2_reports' ) ) return isset( $this->reports[ (int) $this->a( 0 ) ] ) ? '1' : null;
        if ( false !== strpos( $sql, 'hdlv2_form_progress' ) && false !== strpos( $sql, 'stage1_completed_at IS NOT NULL' ) ) {
            $r = $this->progress[ (int) $this->a( 0 ) ] ?? null;
            return $r && $r->stage1_completed_at ? '1' : null;
        }
        if ( false !== strpos( $sql, 'hdlv2_widget_leads' ) ) return in_array( $this->a( 0 ) . '|' . strtolower( (string) $this->a( 1 ) ), $this->leads, true ) ? '1' : null;
        return null;
    }
    public function get_results( $sql ) { $this->calls[] = $sql; return array(); }
}

$P = dirname( __DIR__, 2 ) . '/includes/';
require_once $P . 'sprint-1/class-hdlv2-offer.php';
require_once $P . 'sprint-4/class-hdlv2-challenge.php';
require_once $P . 'sprint-2c/class-hdlv2-healthspan-timeline.php';
require_once $P . 'sprint-2c/class-hdlv2-timeline-links.php';
if ( file_exists( $P . 'sprint-4/class-hdlv2-products.php' ) ) {
    require_once $P . 'sprint-4/class-hdlv2-products.php';
}

$PASS = 0; $FAIL = 0;
function check( $label, $cond ) {
    global $PASS, $FAIL;
    if ( $cond ) { $PASS++; echo "  PASS  $label\n"; }
    else         { $FAIL++; echo "  FAIL  $label\n"; }
}
function done() {
    global $PASS, $FAIL;
    echo "\nPASS=$PASS FAIL=$FAIL\n";
    exit( 0 === $FAIL ? 0 : 1 );
}

// People: 122 = the practitioner whose clients see the offer; 130 = another practitioner; 1 = an admin.
function fresh() {
    global $wpdb;
    $wpdb = new ProductsWpdb();
    $GLOBALS['options'] = array(
        'hdlv2_ff_dashboard_products'  => 1,
        'hdlv2_ff_healthspan_timeline' => 1,
        'hdlv2_ff_timeline_links'      => 1,
        'hdlv2_ff_timeline_pack'       => 1,
        'hdlv2_db_version'             => '3.33',
    );
    $GLOBALS['usermeta'] = array();
    $GLOBALS['owns'] = array();
    $GLOBALS['mails'] = array();
    $GLOBALS['current_user'] = 0;
    $GLOBALS['users'] = array(
        1   => array( 'admin@stby.test', 'Site Admin', array( 'administrator' ) ),
        122 => array( 'prac@stby.test', "Matthew D'haemer", array( 'um_practitioner' ) ),
        130 => array( 'other@stby.test', 'Pat Other', array( 'um_practitioner' ) ),
    );
    return $wpdb;
}
/** A client user and, when $stage is given, their newest live form_progress row. */
function client( $uid, $prac = 122, $stage = null, $extra = array() ) {
    global $wpdb;
    $GLOBALS['users'][ $uid ] = array( "c$uid@stby.test", "Client $uid", array( 'um_client' ) );
    if ( null === $stage ) return;
    $wpdb->progress[ $uid ] = (object) array_merge( array(
        'id' => 300 + $uid, 'practitioner_user_id' => $prac, 'token' => str_repeat( 'a', 64 ),
        'stage1_completed_at' => $stage >= 1 ? '2026-11-02 03:58:43' : null,
        'stage1_pdf_url' => '', 'stage1_pdf_stored_path' => '',
        'stage2_completed_at' => $stage >= 2 ? '2026-11-02 04:00:00' : null,
        'stage3_completed_at' => $stage >= 3 ? '2026-11-02 04:10:00' : null,
    ), $extra );
}
function by_key( $items ) { $o = array(); foreach ( $items as $i ) { $o[ $i['key'] ] = $i; } return $o; }
