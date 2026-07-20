<?php
/**
 * Dashboard version-digest tests — rest_get_version() must survive the
 * consultation_notes ⋈ form_progress subquery.
 *
 * The bug: `MAX(COALESCE(approved_at, started_at, created_at))` inside the
 * `cn INNER JOIN fp` subquery references `created_at` bare, and BOTH joined
 * tables have a `created_at` column (verified against the live schema), so
 * MariaDB raises error 1054 "Column 'created_at' in SELECT is ambiguous".
 * $wpdb->get_var() then returns null → the endpoint answers {"v":0} forever
 * → the dashboard's 4-second realtime refresh never fires and every open
 * dashboard logs one DB error per poll.
 *
 * The fake wpdb below implements exactly that MariaDB rule: if the digest
 * SQL's cn-join subquery contains a bare `created_at`, the query "errors"
 * (null + last_error), otherwise it returns a real epoch. So the test is
 * behavioural: RED against the unqualified query, GREEN once every shared
 * column in that subquery is table-qualified.
 *
 * Proves:
 *  (a) rest_get_version() returns the real max-timestamp epoch, not 0;
 *  (b) the digest query produces no DB error;
 *  (c) the cn subquery carries no bare shared-column reference (belt-and-
 *      braces, so a refactor reintroducing the ambiguity fails loudly);
 *  (d) the empty-roster early path still returns {"v":0} without touching
 *      the DB.
 *
 * Run:  php scenario-digest.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

define( 'ABSPATH', __DIR__ . '/' );

// ── WP stubs ──
function add_action() {}
function get_current_user_id() { return 122; }

class FakeRestResponse {
    public $data;
    public $headers = array();
    public function __construct( $data ) { $this->data = $data; }
    public function header( $k, $v ) { $this->headers[ $k ] = $v; }
    public function get_data() { return $this->data; }
}
function rest_ensure_response( $x ) { return new FakeRestResponse( $x ); }

// Roster stub — swapped per scenario via $GLOBALS.
class HDLV2_Compatibility {
    public static function get_clients_for_practitioner( $prac_id ) {
        return $GLOBALS['test_roster'];
    }
}

// ── Fake wpdb — implements MariaDB error-1054 semantics for the digest ──
class FakeWpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $captured_sql = null;
    public $queries_run = 0;

    // Real column sets (verified via SHOW COLUMNS on STBY, 2026-07-10).
    // Only columns present in BOTH tables can be ambiguous inside the join.
    const CN_COLS     = array( 'id', 'client_user_id', 'practitioner_user_id', 'form_progress_id', 'started_at', 'created_at', 'approved_at' );
    const FP_COLS     = array( 'id', 'client_user_id', 'practitioner_user_id', 'created_at', 'updated_at', 'deleted_at' );
    const SHARED_COLS = array( 'id', 'client_user_id', 'practitioner_user_id', 'created_at' );

    public function get_var( $q ) {
        $this->queries_run++;
        $this->captured_sql = $q;
        $bare = $this->bare_shared_columns_in_cn_join( $q );
        if ( $bare ) {
            // MariaDB: ERROR 1054 — whole statement fails, wpdb returns null.
            $this->last_error = "Column '" . $bare[0] . "' in SELECT is ambiguous";
            return null;
        }
        return '1783700000'; // healthy digest: real UNIX_TIMESTAMP result
    }

    /**
     * Return every bare (unqualified) shared-column token inside the
     * subquery that joins hdlv2_consultation_notes with hdlv2_form_progress.
     */
    public function bare_shared_columns_in_cn_join( $sql ) {
        $sub = $this->extract_cn_join_subquery( $sql );
        if ( $sub === null ) return array();
        $bare = array();
        foreach ( self::SHARED_COLS as $col ) {
            // bare = not preceded by "<alias>." (no dot right before the token)
            if ( preg_match( '/(?<![\w.])' . preg_quote( $col, '/' ) . '\b/', $sub ) ) {
                $bare[] = $col;
            }
        }
        return $bare;
    }

    /** Slice out the (SELECT ...) subquery that mentions consultation_notes. */
    private function extract_cn_join_subquery( $sql ) {
        $pos = strpos( $sql, 'hdlv2_consultation_notes' );
        if ( $pos === false ) return null;
        $start = strrpos( substr( $sql, 0, $pos ), '(SELECT' );
        if ( $start === false ) return null;
        // Walk forward to the matching close-paren.
        $depth = 0;
        for ( $i = $start, $n = strlen( $sql ); $i < $n; $i++ ) {
            if ( $sql[ $i ] === '(' ) $depth++;
            if ( $sql[ $i ] === ')' && --$depth === 0 ) {
                return substr( $sql, $start, $i - $start + 1 );
            }
        }
        return null;
    }
}

// ── Load the real class ──
require __DIR__ . '/../../includes/sprint-4/class-hdlv2-client-status.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) {
    global $pass, $fail;
    echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n";
    $ok ? $pass++ : $fail++;
}

$status = HDLV2_Client_Status::get_instance();

// ── Scenario 1: practitioner with clients — digest must return real epoch ──
$GLOBALS['test_roster'] = array( 180, 181 );
$wpdb = new FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;

$res  = $status->rest_get_version( null );
$data = $res->get_data();

check( 'digest returns the real epoch (not 0)', ( $data['v'] ?? -1 ) === 1783700000 );
check( 'digest query produced no DB error', $wpdb->last_error === '' );
check(
    'cn-join subquery has no bare shared column (' . implode( ',', $wpdb->captured_sql !== null ? $wpdb->bare_shared_columns_in_cn_join( $wpdb->captured_sql ) : array( 'no sql captured' ) ) . ')',
    $wpdb->captured_sql !== null && array() === $wpdb->bare_shared_columns_in_cn_join( $wpdb->captured_sql )
);
check( 'no-store cache header set', isset( $res->headers['Cache-Control'] ) && false !== strpos( $res->headers['Cache-Control'], 'no-store' ) );

// ── Scenario 2: empty roster — early {"v":0} without any DB query ──
$GLOBALS['test_roster'] = array();
$wpdb = new FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;

$res  = $status->rest_get_version( null );
$data = $res->get_data();

check( 'empty roster returns v=0', ( $data['v'] ?? -1 ) === 0 );
check( 'empty roster runs zero digest queries', $wpdb->queries_run === 0 );

echo "\n" . ( $fail ? "SCENARIO: FAIL ($fail)\n" : "SCENARIO: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );
