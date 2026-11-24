<?php
/**
 * Plan 14, C2: Phase AR (DB v3.37). The REAL activator against a fake $wpdb, from 3.36: the new table
 * hdlv2_flight_cycles (made by create_tables() before the migrations) must be there; flight_plans gains
 * cycle_id and cycle_week, the ticks gain ticked_late and ticked_by: four nullable columns, each added
 * and verified; no old row written, nothing dropped or renamed; a second run does nothing; a failed ADD
 * or a missing table returns false (retried next boot); fresh installs get everything from the CREATE
 * TABLE text; the loader's header and constant move together and the loader loads the class.
 *
 * Run: php tests/flight-cycle/scenario-migration.php   Exit: 0 all pass, 1 any fail
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
ini_set( 'error_log', '/dev/null' );
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'HDLV2_DB_VERSION', '3.37' );
define( 'HDLV2_VERSION', '0.49.01' );
$GLOBALS['db_from'] = '3.36';
function get_option( $k, $d = false ) { return 'hdlv2_db_version' === $k ? $GLOBALS['db_from'] : $d; }
function update_option( $k, $v ) { return true; }
function add_action() {}
function add_filter() {}
function current_time( $t = 'mysql', $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
class FakeWpdb {
    public $prefix = 'wp_'; public $last_error = ''; public $alters = array(); public $writes = array(); public $fail_on = '';
    public $cols = array(); public $has_table = true;
    public function prepare( $q, ...$args ) { foreach ( $args as $a ) { $q = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $q, 1 ); } return $q; }
    public function get_charset_collate() { return ''; }
    public function get_var( $q ) {
        if ( false !== strpos( $q, 'INFORMATION_SCHEMA.TABLES' ) ) return $this->has_table ? '1' : '0';
        if ( false !== strpos( $q, 'INFORMATION_SCHEMA.COLUMNS' ) && preg_match( "/TABLE_NAME = '(\w+)' AND COLUMN_NAME = '(\w+)'/", $q, $m ) ) {
            if ( 'wp_hdlv2_form_progress' === $m[1] ) return '1'; // Phase AQ: done (its own test is tests/access/)
            return in_array( $m[1] . '.' . $m[2], $this->cols, true ) ? '1' : '0';
        }
        return '0';
    }
    public function query( $q ) {
        $this->last_error = '';
        if ( preg_match( '/^\s*(UPDATE|DELETE|INSERT)/i', $q ) ) $this->writes[] = $q;
        if ( 0 !== stripos( ltrim( $q ), 'ALTER TABLE' ) ) return 1;
        $this->alters[] = $q;
        if ( $this->fail_on && false !== strpos( $q, $this->fail_on ) ) { $this->last_error = 'Lock wait timeout (simulated)'; return false; }
        if ( preg_match( '/ALTER TABLE `(\w+)` ADD COLUMN (\w+)/', $q, $m ) ) $this->cols[] = $m[1] . '.' . $m[2];
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
    'wp_hdlv2_flight_plans'      => array( 'cycle_id' => 'BIGINT(20) UNSIGNED DEFAULT NULL', 'cycle_week' => 'TINYINT UNSIGNED DEFAULT NULL' ),
    'wp_hdlv2_flight_plan_ticks' => array( 'ticked_late' => 'TINYINT(1) DEFAULT NULL', 'ticked_by' => 'BIGINT(20) UNSIGNED DEFAULT NULL' ),
);

$wpdb = new FakeWpdb();
$ret = migrate();
$all = implode( "\n", $wpdb->alters );
ok( 'M.1 from 3.36: success, four ALTERs (two on plans, two on ticks)', true === $ret && 4 === count( $wpdb->alters ) && 2 === substr_count( $all, 'ALTER TABLE `wp_hdlv2_flight_plans` ADD COLUMN' ) && 2 === substr_count( $all, 'ALTER TABLE `wp_hdlv2_flight_plan_ticks` ADD COLUMN' ) );
$defs = true;
foreach ( $want as $t => $cols ) foreach ( $cols as $c => $d ) if ( false === strpos( $all, "ALTER TABLE `$t` ADD COLUMN $c $d" ) ) $defs = false;
ok( 'M.2 each column nullable, of its type, added (never MODIFY, DROP or RENAME)', $defs && ! preg_match( '/\b(MODIFY|DROP|RENAME|CHANGE)\b/i', $all ) );
ok( 'M.3 no old row written', 0 === count( $wpdb->writes ) );
$wpdb->alters = array();
ok( 'M.4 a second run does nothing', true === migrate() && 0 === count( $wpdb->alters ) );
$wpdb = new FakeWpdb(); $wpdb->fail_on = 'ADD COLUMN cycle_week';
ok( 'M.5 a failed ADD → false (retried next boot), the columns after it not tried', false === migrate() && false === strpos( implode( ' ', $wpdb->alters ), 'ticked_late' ) );
$wpdb = new FakeWpdb(); $wpdb->has_table = false;
ok( 'M.6 the new table missing → false, nothing altered', false === migrate() && 0 === count( $wpdb->alters ) );
$wpdb = new FakeWpdb(); $wpdb->cols = array( 'wp_hdlv2_flight_plans.cycle_id', 'wp_hdlv2_flight_plans.cycle_week' );
ok( 'M.7 half done before (a retry): only the two missing are added', true === migrate() && 2 === count( $wpdb->alters ) );

$src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-hdlv2-activator.php' );
preg_match( '/CREATE TABLE \$table_cycles \((.*?)\) \$charset_collate/s', $src, $m );
$ddl = $m[1] ?? '';
$table_ok = $ddl;
foreach ( array( 'client_id BIGINT(20) UNSIGNED NOT NULL', 'practitioner_id BIGINT(20) UNSIGNED NOT NULL', 'form_progress_id BIGINT(20) UNSIGNED NOT NULL', 'cycle_number SMALLINT UNSIGNED NOT NULL', 'start_week DATE NOT NULL', 'end_date DATE NOT NULL', 'outline TEXT DEFAULT NULL', 'outline_at DATETIME DEFAULT NULL', 'review TEXT DEFAULT NULL', 'review_at DATETIME DEFAULT NULL', "status ENUM('active','review_due','paused','closed') NOT NULL DEFAULT 'active'", 'created_at DATETIME DEFAULT CURRENT_TIMESTAMP', 'closed_at DATETIME DEFAULT NULL', 'UNIQUE KEY client_cycle (client_id, cycle_number)' ) as $piece ) {
    if ( false === strpos( $ddl, $piece ) ) $table_ok = false;
}
ok( 'M.8 the new table\'s CREATE TABLE: every column of plan § C2, one block number per client', (bool) $table_ok );
ok( 'M.9 create_tables() hands it to dbDelta', false !== strpos( $src, 'dbDelta( $sql_cycles );' ) );
preg_match( '/CREATE TABLE \$table_fp \((.*?)\) \$charset_collate/s', $src, $m1 ); preg_match( '/CREATE TABLE \$table_ticks \((.*?)\) \$charset_collate/s', $src, $m2 );
$fresh = true;
foreach ( $want['wp_hdlv2_flight_plans'] as $c => $d ) if ( false === strpos( $m1[1] ?? '', "$c $d," ) ) $fresh = false;
foreach ( $want['wp_hdlv2_flight_plan_ticks'] as $c => $d ) if ( false === strpos( $m2[1] ?? '', "$c $d," ) ) $fresh = false;
ok( 'M.10 fresh installs: the plans and ticks CREATE TABLE texts carry the four columns', $fresh );

$loader = file_get_contents( dirname( __DIR__, 2 ) . '/hdl-longevity-v2.php' );
preg_match( "/define\\( 'HDLV2_VERSION', '([0-9.]+)' \\);/", $loader, $v ); preg_match( '/\\* Version: ([0-9.]+)/', $loader, $h ); preg_match( "/define\\( 'HDLV2_DB_VERSION', '([0-9.]+)' \\);/", $loader, $d );
ok( 'M.11 loader: header == constant == 0.49.01, DB 3.37', ( $v[1] ?? '' ) === '0.49.01' && ( $h[1] ?? '' ) === '0.49.01' && ( $d[1] ?? '' ) === '3.37' );
ok( 'M.12 the loader loads the flight cycle class', false !== strpos( $loader, "require_once HDLV2_PLUGIN_DIR . 'includes/sprint-5/class-hdlv2-flight-cycle.php';" ) );

echo "\nPASS=$pass FAIL=$fail\n";
exit( $fail ? 1 : 0 );
