<?php
/**
 * Plan 14, A4: Phase AQ (DB v3.36). The REAL activator against a fake $wpdb, from 3.35: three nullable
 * columns on form_progress (who gave the Timeline, when, when it was taken back), each added and
 * verified; no old row written; a second run does nothing; a failed ADD returns false (retried next
 * boot); fresh installs get the columns from the CREATE TABLE text; the loader's header and constant
 * move together and the loader loads the access class.
 *
 * Run: php tests/access/scenario-migration.php   Exit: 0 all pass, 1 any fail
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
ini_set( 'error_log', '/dev/null' );
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'HDLV2_DB_VERSION', '3.36' );
define( 'HDLV2_VERSION', '0.49.00' );
$GLOBALS['db_from'] = '3.35';
function get_option( $k, $d = false ) { return 'hdlv2_db_version' === $k ? $GLOBALS['db_from'] : $d; }
function update_option( $k, $v ) { return true; }
function add_action() {}
function add_filter() {}
function current_time( $t = 'mysql', $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
class FakeWpdb {
    public $prefix = 'wp_'; public $last_error = ''; public $alters = array(); public $writes = array(); public $fail_on = '';
    public $cols = array();
    public function prepare( $q, ...$args ) { foreach ( $args as $a ) { $q = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $q, 1 ); } return $q; }
    public function get_charset_collate() { return ''; }
    public function get_var( $q ) {
        if ( preg_match( "/COLUMN_NAME = '(cycle_id|cycle_week|ticked_late|ticked_by)'/", $q ) || ( false !== strpos( $q, 'INFORMATION_SCHEMA.TABLES' ) && false !== strpos( $q, 'hdlv2_flight_cycles' ) ) ) return '1'; // Phase AR (v3.37): modelled as done; its own tests are in tests/flight-cycle/
        if ( false !== strpos( $q, 'INFORMATION_SCHEMA.COLUMNS' ) && preg_match( "/COLUMN_NAME = '(\w+)'/", $q, $m ) ) return in_array( $m[1], $this->cols, true ) ? '1' : '0';
        return '0';
    }
    public function query( $q ) {
        $this->last_error = '';
        if ( preg_match( '/^\s*(UPDATE|DELETE|INSERT)/i', $q ) ) $this->writes[] = $q;
        if ( 0 !== stripos( ltrim( $q ), 'ALTER TABLE' ) ) return 1;
        $this->alters[] = $q;
        if ( $this->fail_on && false !== strpos( $q, $this->fail_on ) ) { $this->last_error = 'Lock wait timeout (simulated)'; return false; }
        if ( preg_match( '/ADD COLUMN (\w+)/', $q, $m ) ) $this->cols[] = $m[1];
        return 1;
    }
    public function get_results( $q ) { return array(); } public function get_col( $q ) { return array(); } public function get_row( $q ) { return null; }
    public function insert() { return 1; } public function update() { return 1; }
}
require dirname( __DIR__, 2 ) . '/includes/class-hdlv2-activator.php';
$pass = 0; $fail = 0;
function ok( $name, $cond ) { global $pass, $fail; echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . "$name\n"; $cond ? $pass++ : $fail++; }
function migrate() { $m = new ReflectionMethod( 'HDLV2_Activator', 'run_migrations' ); $m->setAccessible( true ); return $m->invoke( null ); }

$want = array(
    'timeline_granted_by'       => 'BIGINT(20) UNSIGNED DEFAULT NULL',
    'timeline_granted_at'       => 'DATETIME DEFAULT NULL',
    'timeline_grant_revoked_at' => 'DATETIME DEFAULT NULL',
);

$wpdb = new FakeWpdb();
$ret = migrate();
$all = implode( "\n", $wpdb->alters );
ok( 'M.1 from 3.35: success, three ALTERs, all on form_progress', true === $ret && 3 === count( $wpdb->alters ) && 3 === substr_count( $all, 'ALTER TABLE `wp_hdlv2_form_progress` ADD COLUMN' ) );
$defs = true;
foreach ( $want as $c => $d ) if ( false === strpos( $all, "ADD COLUMN $c $d" ) ) $defs = false;
ok( 'M.2 each column nullable, of its type, added (never MODIFY, DROP or RENAME)', $defs && ! preg_match( '/\b(MODIFY|DROP|RENAME|CHANGE)\b/i', $all ) );
ok( 'M.3 no old row written', 0 === count( $wpdb->writes ) );
$wpdb->alters = array();
ok( 'M.4 a second run does nothing', true === migrate() && 0 === count( $wpdb->alters ) );
$wpdb = new FakeWpdb(); $wpdb->fail_on = 'ADD COLUMN timeline_granted_at';
ok( 'M.5 a failed ADD → false (retried next boot), the column after it not tried', false === migrate() && false === strpos( implode( ' ', $wpdb->alters ), 'timeline_grant_revoked_at' ) );
$wpdb = new FakeWpdb(); $wpdb->cols = array( 'timeline_granted_by' );
ok( 'M.6 half done before (a retry): only the two missing are added', true === migrate() && 2 === count( $wpdb->alters ) );
$GLOBALS['db_from'] = '3.34'; $wpdb = new FakeWpdb();
$ret = migrate(); $all = implode( "\n", $wpdb->alters );
ok( 'M.7 from 3.34: Phase AP (5) and Phase AQ (3) both run, AP first', true === $ret && 8 === count( $wpdb->alters ) && strpos( $all, 'health_analysis ' ) < strpos( $all, 'timeline_granted_by' ) );
$GLOBALS['db_from'] = '3.35';

$src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-hdlv2-activator.php' );
preg_match( '/CREATE TABLE \$table_progress \((.*?)\) \$charset_collate/s', $src, $m );
$ddl = $m[1] ?? '';
$fresh = true;
foreach ( $want as $c => $d ) if ( false === strpos( $ddl, "$c $d," ) ) $fresh = false;
ok( 'M.8 fresh installs: the CREATE TABLE text carries the three columns', $ddl && $fresh );

$loader = file_get_contents( dirname( __DIR__, 2 ) . '/hdl-longevity-v2.php' );
preg_match( "/define\\( 'HDLV2_VERSION', '([0-9.]+)' \\);/", $loader, $v ); preg_match( '/\\* Version: ([0-9.]+)/', $loader, $h ); preg_match( "/define\\( 'HDLV2_DB_VERSION', '([0-9.]+)' \\);/", $loader, $d );
ok( 'M.9 loader: header == constant, 0.49.00 or later, DB 3.36 or later (0.49.01 moved it to 3.37, plan 14 C)', ( $v[1] ?? '' ) === ( $h[1] ?? '-' ) && version_compare( $v[1] ?? '0', '0.49.00', '>=' ) && version_compare( $d[1] ?? '0', '3.36', '>=' ) );
ok( 'M.10 the loader loads the access class', false !== strpos( $loader, "require_once HDLV2_PLUGIN_DIR . 'includes/security/class-hdlv2-access.php';" ) );

echo "\nPASS=$pass FAIL=$fail\n";
exit( $fail ? 1 : 0 );
