<?php
/** Phase AN (DB v3.33). The REAL activator against a fake $wpdb, from 3.32. Run: php scenario-migration.php */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
ini_set( 'error_log', '/dev/null' );
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'HDLV2_DB_VERSION', '3.33' );
define( 'HDLV2_VERSION', '0.48.35' );
$GLOBALS['db_from'] = '3.32';
function get_option( $k, $d = false ) { return 'hdlv2_db_version' === $k ? $GLOBALS['db_from'] : $d; }
function update_option( $k, $v ) { return true; }
function add_action() {}
function add_filter() {}
function current_time( $t = 'mysql', $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
class FakeWpdb {
    public $prefix = 'wp_'; public $last_error = ''; public $alters = array(); public $writes = array(); public $fail_on = '';
    public $source_type = "enum('practitioner','automation','paid_stage1','full_report','challenge','timeline_link')";
    public $tables = array( 'wp_hdlv2_challenge_days' => true, 'wp_hdlv2_timeline_links' => true, 'wp_hdlv2_timeline_packs' => true );
    public $cols = array();
    public function prepare( $q, ...$args ) { foreach ( $args as $a ) { $q = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $q, 1 ); } return $q; }
    public function get_charset_collate() { return ''; }
    public function get_var( $q ) {
        if ( false !== strpos( $q, 'COLUMN_TYPE' ) ) return $this->source_type;
        if ( false !== strpos( $q, 'INFORMATION_SCHEMA.TABLES' ) && preg_match( "/TABLE_NAME = '(\w+)'/", $q, $m ) ) return isset( $this->tables[ $m[1] ] ) ? '1' : '0';
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

$wpdb = new FakeWpdb();
$ret = migrate();
ok( 'M.1 from 3.32: success, two ALTERs on the links table, both additive', true === $ret && 2 === count( $wpdb->alters ) && false !== strpos( implode( ' ', $wpdb->alters ), 'ADD COLUMN pack_id' ) && false !== strpos( implode( ' ', $wpdb->alters ), 'ADD COLUMN seat_invite_id' ) && false !== strpos( $wpdb->alters[0], 'wp_hdlv2_timeline_links' ) );
ok( 'M.2 existing rows untouched', 0 === count( $wpdb->writes ) );
$wpdb->alters = array();
ok( 'M.3 second run is a no-op', true === migrate() && 0 === count( $wpdb->alters ) );
$wpdb = new FakeWpdb(); $wpdb->fail_on = 'ADD COLUMN seat_invite_id';
ok( 'M.4 a failed ALTER → false (retried next boot)', false === migrate() );
$wpdb = new FakeWpdb(); unset( $wpdb->tables['wp_hdlv2_timeline_packs'] );
ok( 'M.5 packs table missing → false', false === migrate() );
$src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-hdlv2-activator.php' );
preg_match( '/CREATE TABLE \$table_packs \((.*?)\) \$charset_collate/s', $src, $m );
$ddl = $m[1] ?? '';
$want = array( 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', 'owner_progress_id BIGINT(20) UNSIGNED NOT NULL', 'seats_total TINYINT UNSIGNED NOT NULL', 'seats_used TINYINT UNSIGNED NOT NULL DEFAULT 0',
    "status ENUM('active','ended') NOT NULL DEFAULT 'active'", "source ENUM('practitioner','purchase') NOT NULL", 'external_ref VARCHAR(80) DEFAULT NULL', 'created_at DATETIME', 'ended_at DATETIME DEFAULT NULL', 'updated_at DATETIME',
    'PRIMARY KEY  (id)', 'UNIQUE KEY external_ref (external_ref)', 'KEY owner_progress_id (owner_progress_id)' );
$missing = array_filter( $want, function ( $c ) use ( $ddl ) { return false === strpos( $ddl, $c ) && false === strpos( $ddl, str_replace( '  ', ' ', $c ) ); } );
ok( 'M.6 CREATE TABLE packs: every column and key (missing: ' . implode( ' | ', $missing ) . ')', $ddl && ! $missing );
preg_match( '/CREATE TABLE \$table_links \((.*?)\) \$charset_collate/s', $src, $l );
ok( 'M.7 fresh installs: the links table carries both columns', false !== strpos( $l[1] ?? '', 'pack_id BIGINT(20) UNSIGNED DEFAULT NULL' ) && false !== strpos( $l[1] ?? '', 'seat_invite_id BIGINT(20) UNSIGNED DEFAULT NULL' ) );
ok( 'M.8 dbDelta runs it', false !== strpos( $src, 'dbDelta( $sql_packs );' ) );
$loader = file_get_contents( dirname( __DIR__, 2 ) . '/hdl-longevity-v2.php' );
ok( 'M.9 loader: 0.48.36 / 3.33', false !== strpos( $loader, ' * Version: 0.48.36' ) && false !== strpos( $loader, "define( 'HDLV2_VERSION', '0.48.36' );" ) && false !== strpos( $loader, "define( 'HDLV2_DB_VERSION', '3.33' );" ) );
$status = file_get_contents( dirname( __DIR__, 2 ) . '/includes/sprint-4/class-hdlv2-client-status.php' );
ok( 'M.10 /health expects the new table', false !== strpos( $status, "'hdlv2_timeline_packs'," ) );
echo "\nPASS=$pass FAIL=$fail\n";
exit( $fail ? 1 : 0 );
