<?php
/**
 * G6 (2026-11-17): a practitioner's delete ends every link they or a sale
 * e-mailed the client. Since 0.48.71 a used Full Report link keeps signing in
 * until its date, so the delete must revoke it too; paid Stage 1 and challenge
 * links stay live (a sale, not an invite, 0.48.24).
 *
 * Standalone, no WP, no DB: loads the REAL HDLV2_Client_Status, runs
 * rest_delete_client() against a fake $wpdb that answers the ticket UPDATE the
 * way MySQL would for the WHERE clause the code wrote.
 *   php tests/paid-buyer-path/scenario-delete-revokes.php
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
define( 'ABSPATH', __DIR__ . '/' );

function absint( $n ) { return abs( (int) $n ); }
function get_current_user_id() { return 3; }
function current_time( $t = 'mysql', $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_userdata( $id ) { return 7 === $id ? (object) array( 'ID' => 7, 'user_email' => 'C@x.test ' ) : false; }
function esc_sql( $s ) { return $s; }
function rest_ensure_response( $d ) { return $d; }
class WP_Error { public function __construct( ...$a ) {} }

/*
 * The client's tickets. Expected after the delete: 1–3 revoked, the rest kept.
 */
$GLOBALS['tickets'] = array(
    1 => array( 3, 'c@x.test', 'pending',   'widget' ),
    2 => array( 3, 'c@x.test', 'opened',    'full_report' ),
    3 => array( 3, 'c@x.test', 'completed', 'full_report' ), // G6: a used Full Report link
    4 => array( 3, 'c@x.test', 'completed', 'widget' ),      // used invite: dead already
    5 => array( 3, 'c@x.test', 'pending',   'paid_stage1' ),
    6 => array( 3, 'c@x.test', 'completed', 'paid_stage1' ),
    7 => array( 3, 'c@x.test', 'opened',    'challenge' ),
    8 => array( 9, 'c@x.test', 'completed', 'full_report' ), // another practitioner's
    9 => array( 3, 'd@x.test', 'completed', 'full_report' ), // another client's
);

function fake_ticket_hit( $q, $t ) {
    list( $prac, $email, $status, $source ) = $t;
    if ( false === strpos( $q, 'practitioner_id = ' . $prac . ' ' ) ) return false;
    if ( false === strpos( $q, "LOWER(client_email) = '" . $email . "'" ) ) return false;
    if ( false !== strpos( $q, "source NOT IN ('paid_stage1','challenge')" ) && in_array( $source, array( 'paid_stage1', 'challenge' ), true ) ) return false;
    if ( in_array( $status, array( 'pending', 'opened' ), true ) ) return false !== strpos( $q, "status IN ('pending','opened')" );
    return 'completed' === $status && 'full_report' === $source
        && false !== strpos( $q, "OR ( status = 'completed' AND source = 'full_report' )" );
}

class FakeWpdb {
    public $prefix  = 'wp_';
    public $revoked = array();
    public function prepare( $q, ...$args ) {
        foreach ( $args as $a ) { $q = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $q, 1 ); }
        return $q;
    }
    public function query( $q ) {
        if ( false === strpos( $q, 'hdlv2_widget_invites' ) ) return 0;
        $q = preg_replace( '/\s+/', ' ', $q ) . ' ';
        foreach ( $GLOBALS['tickets'] as $id => $t ) {
            if ( fake_ticket_hit( $q, $t ) ) { $this->revoked[] = $id; }
        }
        return count( $this->revoked );
    }
    public function get_var( $q ) { return null; }
    public function update( ...$a ) { return 0; }
}

require dirname( __DIR__, 2 ) . '/includes/sprint-4/class-hdlv2-client-status.php';

global $wpdb;
$wpdb = new FakeWpdb();
$out  = HDLV2_Client_Status::get_instance()->rest_delete_client( array( 'client_id' => 7 ) );

$pass = 0;
$fail = 0;
function ok( $name, $cond ) {
    global $pass, $fail;
    echo ( $cond ? 'PASS' : 'FAIL' ) . " | $name\n";
    $cond ? $pass++ : $fail++;
}
$r = $wpdb->revoked;
ok( 'unused links (pending, opened) revoked', in_array( 1, $r, true ) && in_array( 2, $r, true ) );
ok( 'a used Full Report link revoked (G6)', in_array( 3, $r, true ) );
ok( 'a used invite of another kind left alone', ! in_array( 4, $r, true ) );
ok( 'paid Stage 1 links left alone, used or not', ! in_array( 5, $r, true ) && ! in_array( 6, $r, true ) );
ok( 'a challenge link left alone', ! in_array( 7, $r, true ) );
ok( 'another practitioner\'s ticket untouched', ! in_array( 8, $r, true ) );
ok( 'another client\'s ticket untouched', ! in_array( 9, $r, true ) );
ok( 'the response counts the three revoked links', is_array( $out ) && 3 === ( $out['invites_cancelled'] ?? null ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
