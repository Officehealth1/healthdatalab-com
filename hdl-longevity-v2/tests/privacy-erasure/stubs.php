<?php
/**
 * Shared stubs for the data-erasure scenarios (v0.48.63). Standalone: no WordPress, no MySQL.
 * $wpdb runs on an in-memory SQLite database holding every store the eraser touches, so the
 * real DELETE and UPDATE statements run against two people and the test compares rows, not
 * SQL strings. Files live in a temporary private folder (HDLV2_PRIVATE_DIR).
 */
error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HDLV2_PLUGIN_URL', 'https://stby.test/wp-content/plugins/hdl-longevity-v2/' );
define( 'HDLV2_VERSION', 'test' );
define( 'HDLV2_PRIVATE_DIR', sys_get_temp_dir() . '/hdlv2-erase-test-' . getmypid() );

$GLOBALS['log_file'] = HDLV2_PRIVATE_DIR . '-error.log';
ini_set( 'error_log', $GLOBALS['log_file'] );
ini_set( 'log_errors', '1' );

$GLOBALS['options']    = array( 'health_tracker_salt' => 'v1-salt', 'hdlv2_db_version' => '3.33' );
$GLOBALS['filters']    = array();
$GLOBALS['transients'] = array();
$GLOBALS['users']      = array(); // id => object( ID, user_email, roles )
$GLOBALS['deleted_users'] = array();
$GLOBALS['mails']      = array();

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['filters'][ $tag ][] = $cb; return true; }
function add_action( $tag, $cb, $prio = 10, $args = 1 ) { return add_filter( $tag, $cb, $prio, $args ); }
function apply_filters( $tag, $v, ...$rest ) {
    foreach ( $GLOBALS['filters'][ $tag ] ?? array() as $cb ) { $v = call_user_func( $cb, $v, ...$rest ); }
    return $v;
}
function is_email( $x ) { return is_string( $x ) && false !== filter_var( $x, FILTER_VALIDATE_EMAIL ); }
function wp_json_encode( $x, $f = 0 ) { return json_encode( $x, $f ); }
function wp_salt( $s = '' ) { return 'test-salt-' . $s; }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function get_user_by( $field, $value ) {
    foreach ( $GLOBALS['users'] as $u ) { if ( 'email' === $field && strtolower( $u->user_email ) === strtolower( trim( $value ) ) ) return $u; }
    return false;
}
function get_userdata( $id ) { return $GLOBALS['users'][ (int) $id ] ?? false; }
function user_can( $user, $cap ) {
    $u = is_object( $user ) ? $user : get_userdata( $user );
    return $u && 'manage_options' === $cap && in_array( 'administrator', (array) $u->roles, true );
}
function wp_delete_user( $id ) {
    if ( ! isset( $GLOBALS['users'][ (int) $id ] ) ) return false;
    unset( $GLOBALS['users'][ (int) $id ] );
    $GLOBALS['deleted_users'][] = (int) $id;
    return true;
}
function wp_mail( $to, $subject, $body, $headers = array() ) { $GLOBALS['mails'][] = compact( 'to', 'subject', 'body', 'headers' ); return true; }

// ── the request flow: WordPress's user_request posts, the REST and script registries ──
$GLOBALS['requests']   = array(); // id => object( ID, email, action_name, status, user_id )
$GLOBALS['sent_links'] = array();
$GLOBALS['routes']     = array();
$GLOBALS['scripts']    = array();
$GLOBALS['post_meta']  = array();
$GLOBALS['current_user'] = 0;
function get_current_user_id() { return $GLOBALS['current_user']; }
function wp_get_current_user() { return get_userdata( $GLOBALS['current_user'] ) ?: (object) array( 'ID' => 0, 'user_email' => '' ); }
function register_rest_route( $ns, $route, $args ) { $GLOBALS['routes'][ $ns . $route ] = $args; }
function rest_ensure_response( $x ) { return $x; }
function rest_url( $p = '' ) { return 'https://stby.test/wp-json/' . ltrim( $p, '/' ); }
function admin_url( $p = '' ) { return 'https://stby.test/wp-admin/' . $p; }
function wp_create_nonce( $a ) { return 'nonce-' . $a; }
function wp_enqueue_script( $h ) { $GLOBALS['scripts'][ $h ] = true; }
function wp_localize_script( $h, $o, $d ) { $GLOBALS['scripts'][ $h . ':' . $o ] = $d; }
function wp_add_inline_style( $h, $css ) { $GLOBALS['scripts'][ $h . ':css' ] = $css; }
function esc_html( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES, 'UTF-8' ); }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['post_meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['post_meta'][ $id ][ $k ] = $v; return true; }
function wp_get_user_request( $id ) { return $GLOBALS['requests'][ (int) $id ] ?? false; }
function wp_create_user_request( $email, $action = '', $data = array(), $status = 'pending' ) {
    if ( ! is_email( $email ) ) return new WP_Error( 'invalid_email', 'Invalid email address.' );
    foreach ( $GLOBALS['requests'] as $r ) {
        if ( $r->email === $email && $r->action_name === $action && in_array( $r->status, array( 'request-pending', 'request-confirmed' ), true ) ) return new WP_Error( 'duplicate_request', 'dup' );
    }
    $id = 100 + count( $GLOBALS['requests'] );
    $u  = get_user_by( 'email', $email );
    $GLOBALS['requests'][ $id ] = (object) array( 'ID' => $id, 'email' => $email, 'action_name' => $action, 'status' => 'request-' . $status, 'user_id' => $u ? $u->ID : 0 );
    return $id;
}
function wp_send_user_request( $id ) { $GLOBALS['sent_links'][] = (int) $id; return empty( $GLOBALS['send_fail'] ) ? true : new WP_Error( 'privacy_email_error', 'x' ); }
/** WP_Query's user_request lookup as core runs it: post_name__in, title, post_status, meta_query (timestamp <, NOT EXISTS). */
function get_posts( $a ) {
    $out = array();
    foreach ( $GLOBALS['requests'] as $r ) {
        if ( isset( $a['post_name__in'] ) && ! in_array( $r->action_name, $a['post_name__in'], true ) ) continue;
        if ( isset( $a['title'] ) && $r->email !== $a['title'] ) continue;
        if ( isset( $a['post_status'] ) && ! in_array( $r->status, (array) $a['post_status'], true ) ) continue;
        foreach ( $a['meta_query'] ?? array() as $m ) {
            if ( ! is_array( $m ) ) continue;
            $v = get_post_meta( $r->ID, $m['key'], true );
            if ( 'NOT EXISTS' === $m['compare'] && '' !== $v ) continue 2;
            if ( '<' === $m['compare'] && ! ( '' !== $v && (int) $v < (int) $m['value'] ) ) continue 2;
        }
        $out[] = $r->ID;
    }
    return $out;
}
class HDLV2_Env { public static function gate( $c ) { return empty( $GLOBALS['gated'] ); } }
class HDLV2_Email_Templates {
    public static function privacy_confirm( $url ) { return "<html>confirm [$url]</html>"; }
    public static function privacy_done( $account_kept = false ) { return $account_kept ? '<html>done kept</html>' : '<html>done</html>'; }
    public static function privacy_practitioner( $email ) { return "<html>practitioner [$email]</html>"; }
}

class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }

class HDLV2_Compatibility {
    public static function is_practitioner( $id ) {
        $u = get_userdata( $id );
        return $u && (bool) array_intersect( array( 'um_practitioner', 'practitioner', 'administrator' ), (array) $u->roles );
    }
}

/** The subset of wpdb the eraser uses, on SQLite. */
class SqliteWpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $pdo;
    public $fail_on = ''; // a table name: any write to it fails, to prove the rollback

    public function __construct() {
        $this->pdo = new PDO( 'sqlite::memory:' );
        $this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT );
    }
    public function esc_like( $t ) { return addcslashes( $t, '_%\\' ); }
    public function prepare( $sql, ...$args ) {
        if ( 1 === count( $args ) && is_array( $args[0] ) ) $args = $args[0];
        $i = 0;
        return preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $args ) {
            $a = $args[ $i++ ] ?? null;
            if ( '%d' === $m[0] ) return (string) (int) $a;
            if ( '%f' === $m[0] ) return (string) (float) $a;
            return $this->pdo->quote( (string) $a );
        }, $sql );
    }
    private function translate( $sql ) {
        if ( preg_match( "/^SHOW TABLES LIKE '(.*)'$/", trim( $sql ), $m ) ) {
            return "SELECT name FROM sqlite_master WHERE type = 'table' AND name = '" . stripslashes( $m[1] ) . "'";
        }
        return 'START TRANSACTION' === trim( $sql ) ? 'BEGIN' : $sql;
    }
    public $log = array();
    public function query( $sql ) {
        $sql = $this->translate( $sql );
        $this->log[] = $sql;
        if ( $this->fail_on && preg_match( '/^\s*(DELETE|UPDATE)\b/i', $sql ) && false !== strpos( $sql, $this->fail_on ) ) {
            $this->last_error = 'forced failure';
            return false;
        }
        $n = $this->pdo->exec( $sql );
        if ( false === $n ) { $this->last_error = implode( ' ', $this->pdo->errorInfo() ); return false; }
        return $n;
    }
    private function select( $sql ) {
        $st = $this->pdo->query( $this->translate( $sql ) );
        if ( ! $st ) { $this->last_error = implode( ' ', $this->pdo->errorInfo() ); return array(); }
        return $st->fetchAll( PDO::FETCH_OBJ );
    }
    public function get_results( $sql ) { return $this->select( $sql ); }
    public function get_row( $sql ) { $r = $this->select( $sql ); return $r ? $r[0] : null; }
    public function get_col( $sql ) { return array_map( function ( $o ) { return array_values( (array) $o )[0]; }, $this->select( $sql ) ); }
    public function get_var( $sql ) { $c = $this->get_col( $sql ); return $c ? $c[0] : null; }
    public function insert( $table, $data ) {
        $cols = array_keys( $data );
        $st   = $this->pdo->prepare( "INSERT INTO $table (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', array_fill( 0, count( $cols ), '?' ) ) . ')' );
        $st->execute( array_values( $data ) );
        return (int) $this->pdo->lastInsertId();
    }
}

/** Every store the eraser reads, with the columns it keys on and one payload column each. */
function hdl_erase_schema( $db ) {
    $t = array(
        'hdlv2_form_progress'       => 'id INTEGER PRIMARY KEY, client_user_id INT, practitioner_user_id INT, client_name TEXT, client_email TEXT, token TEXT, stage1_pdf_stored_path TEXT, stage1_data TEXT',
        'hdlv2_widget_leads'        => 'id INTEGER PRIMARY KEY, practitioner_user_id INT, visitor_name TEXT, visitor_email TEXT, invite_id INT, stage1_pdf_stored_path TEXT, stage1_data TEXT',
        'hdlv2_pending_leads'       => 'id INTEGER PRIMARY KEY, practitioner_id INT, visitor_email TEXT, verify_token TEXT',
        'hdlv2_widget_invites'      => 'id INTEGER PRIMARY KEY, practitioner_id INT, token TEXT, client_name TEXT, client_email TEXT, source TEXT, external_ref TEXT',
        'hdlv2_automation_tokens'   => 'id INTEGER PRIMARY KEY, token TEXT, client_email TEXT, stripe_session_id TEXT',
        'hdlv2_why_profiles'        => 'id INTEGER PRIMARY KEY, client_user_id INT, form_progress_id INT, pdf_stored_path TEXT, distilled_why TEXT',
        'hdlv2_reports'             => 'id INTEGER PRIMARY KEY, client_user_id INT, practitioner_user_id INT, form_progress_id INT, pdf_stored_path TEXT, report_type TEXT',
        'hdlv2_consultation_notes'  => 'id INTEGER PRIMARY KEY, client_user_id INT, practitioner_user_id INT, form_progress_id INT, typed_notes TEXT',
        'hdlv2_consultation_addenda'=> 'id INTEGER PRIMARY KEY, consultation_id INT, practitioner_user_id INT, client_user_id INT, form_progress_id INT, body TEXT',
        'hdlv2_audio_extractions'   => 'id INTEGER PRIMARY KEY, client_user_id INT, form_progress_id INT, reference_id INT, transcript TEXT',
        'hdlv2_iris_results'        => 'id INTEGER PRIMARY KEY, client_user_id INT, practitioner_user_id INT, form_progress_id INT, image_l_path TEXT, image_r_path TEXT, map_l_path TEXT, map_r_path TEXT',
        'hdlv2_checkins'            => 'id INTEGER PRIMARY KEY, client_id INT, practitioner_id INT, notes TEXT',
        'hdlv2_timeline'            => 'id INTEGER PRIMARY KEY, client_id INT, practitioner_id INT, source_table TEXT, body TEXT',
        'hdlv2_flight_plans'        => 'id INTEGER PRIMARY KEY, client_id INT, practitioner_id INT, pdf_stored_path TEXT, plan TEXT',
        'hdlv2_flight_plan_ticks'   => 'id INTEGER PRIMARY KEY, flight_plan_id INT, client_id INT, day TEXT',
        'hdlv2_monthly_summaries'   => 'id INTEGER PRIMARY KEY, client_id INT, practitioner_id INT, summary TEXT',
        'hdlv2_challenge_days'      => 'id INTEGER PRIMARY KEY, client_user_id INT, day_no INT, note TEXT',
        'hdlv2_timeline_links'      => 'id INTEGER PRIMARY KEY, owner_progress_id INT, invite_id INT, label TEXT, status TEXT, lead_id INT, shared_rate REAL, shared_age INT, shared_sex TEXT, withdraw_token_hash TEXT, withdrawn_at TEXT, updated_at TEXT, pack_id INT',
        'hdlv2_timeline_packs'      => 'id INTEGER PRIMARY KEY, owner_progress_id INT, seats_total INT, seats_used INT, status TEXT',
        'hdlv2_jobs'                => 'id INTEGER PRIMARY KEY, job_type TEXT, reference_id INT, payload TEXT, result TEXT',
        'health_tracker_submissions'          => 'id INTEGER PRIMARY KEY, user_hash TEXT, email_hash TEXT, answers TEXT',
        'health_tracker_progress'             => 'id INTEGER PRIMARY KEY, user_hash TEXT, current_value REAL',
        'health_tracker_sessions'             => 'id INTEGER PRIMARY KEY, user_hash TEXT, email_hash TEXT',
        'health_tracker_verification_codes'   => 'id INTEGER PRIMARY KEY, email_hash TEXT, code TEXT',
        'health_tracker_practitioner_clients' => 'id INTEGER PRIMARY KEY, practitioner_user_id INT, client_email_hash TEXT',
        'hdl_messages'              => 'id INTEGER PRIMARY KEY, practitioner_id INT, client_email_hash TEXT, client_email TEXT, body TEXT',
        'hdl_messaging_prefs'       => 'id INTEGER PRIMARY KEY, client_email_hash TEXT, practitioner_id INT',
        'hdl_invites'               => 'id INTEGER PRIMARY KEY, email TEXT',
        'hdl_lab_audit_log'         => 'id INTEGER PRIMARY KEY, email TEXT, action TEXT',
        'hdl_user_credits'          => 'id INTEGER PRIMARY KEY, user_id INT, credits INT',
        'hdl_usage_logs'            => 'id INTEGER PRIMARY KEY, user_id INT, ip TEXT',
        'hdl_credit_purchases'      => 'id INTEGER PRIMARY KEY, user_id INT, stripe_payment_id TEXT',
        'post_smtp_logs'            => 'id INTEGER PRIMARY KEY, original_to TEXT, to_header TEXT, original_message TEXT',
    );
    foreach ( $t as $name => $cols ) { $db->pdo->exec( "CREATE TABLE wp_$name ($cols)" ); }
    return array_keys( $t );
}

/** Every row of every table, for before/after comparison. */
function hdl_erase_dump( $db, $tables ) {
    $out = array();
    foreach ( $tables as $t ) { $out[ $t ] = $db->get_results( "SELECT * FROM wp_$t ORDER BY id" ); }
    return $out;
}

function hdl_erase_file( $rel ) {
    $abs = HDLV2_PRIVATE_DIR . '/' . $rel;
    if ( ! is_dir( dirname( $abs ) ) ) mkdir( dirname( $abs ), 0777, true );
    file_put_contents( $abs, 'x' );
    return $abs;
}

/** Runs every page, as the Tools screen does, and returns the pages' answers. */
function hdl_erase_all( $email ) {
    $answers = array();
    for ( $page = 1; $page <= 10; $page++ ) {
        $r = HDLV2_Privacy_Eraser::erase( $email, $page );
        $answers[] = $r;
        if ( is_wp_error( $r ) || ! empty( $r['done'] ) ) break;
    }
    return $answers;
}

function hdl_erase_cleanup() {
    if ( is_dir( HDLV2_PRIVATE_DIR ) ) {
        $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( HDLV2_PRIVATE_DIR, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); }
        rmdir( HDLV2_PRIVATE_DIR );
    }
    @unlink( $GLOBALS['log_file'] );
}

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;
function ok( $label, $cond ) {
    if ( $cond ) { $GLOBALS['pass']++; echo "  PASS $label\n"; }
    else { $GLOBALS['fail']++; echo "  FAIL $label\n"; }
}
function done_report() {
    hdl_erase_cleanup();
    echo ( $GLOBALS['fail'] ? 'FAIL' : 'PASS' ) . " {$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n";
    exit( $GLOBALS['fail'] ? 1 : 0 );
}
