<?php
/**
/**
 * Phase AJ (DB v3.29) migration — 'full_report' joins widget_invites.source.
 * The REAL activator against the fake $wpdb of tests/paid-stage1 (copied),
 * starting at 3.28 so only the new phase runs.
 *
 * Run:  php scenario-migration.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
ini_set( 'error_log', '/dev/null' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'HDLV2_DB_VERSION', '3.29' );
define( 'HDLV2_VERSION', '0.48.15' );

$GLOBALS['db_from'] = '3.28';
function get_option( $k, $d = false ) { return 'hdlv2_db_version' === $k ? $GLOBALS['db_from'] : $d; }
function update_option( $k, $v ) { return true; }
function add_action() {}
function add_filter() {}
function current_time( $t = 'mysql', $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }

// Models exactly the schema facts Phase AH probes and changes.
class FakeWpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $alters = array();
    public $columns = array();  // "table.column" => true
    public $source_type = "enum('practitioner','automation')";
    public $has_ref_key = false;
    public $fail_on = '';       // substring of an ALTER that must fail

    public function prepare( $q, ...$args ) {
        foreach ( $args as $a ) {
            $q = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $q, 1 );
        }
        return $q;
    }
    public function get_charset_collate() { return ''; }
    public function get_var( $q ) {
        if ( false !== strpos( $q, 'COLUMN_TYPE' ) ) return $this->source_type;
        if ( false !== strpos( $q, 'INFORMATION_SCHEMA.STATISTICS' ) ) return $this->has_ref_key ? '1' : '0';
        if ( preg_match( "/TABLE_NAME = 'wp_(\w+)' AND COLUMN_NAME = '(\w+)'/", $q, $m ) ) {
            return isset( $this->columns[ $m[1] . '.' . $m[2] ] ) ? '1' : '0';
        }
        return '0';
    }
    public $writes = array();   // UPDATE / DELETE / INSERT — existing rows must stay as they are
    public function query( $q ) {
        $this->last_error = '';
        if ( preg_match( '/^\s*(UPDATE|DELETE|INSERT)/i', $q ) ) $this->writes[] = $q;
        if ( 0 !== stripos( ltrim( $q ), 'ALTER TABLE' ) ) return 1;
        $this->alters[] = $q;
        if ( $this->fail_on && false !== strpos( $q, $this->fail_on ) ) {
            $this->last_error = 'Lock wait timeout (simulated)';
            return false;
        }
        if ( preg_match( '/ALTER TABLE `?wp_(\w+)`? ADD COLUMN (\w+)/', $q, $m ) ) $this->columns[ $m[1] . '.' . $m[2] ] = true;
        if ( false !== strpos( $q, 'MODIFY COLUMN source' ) && preg_match( '/ENUM\([^)]*\)/', $q, $m ) ) $this->source_type = strtolower( $m[0] );
        if ( false !== strpos( $q, 'ADD UNIQUE KEY external_ref' ) ) $this->has_ref_key = true;
        return 1;
    }
    public function get_results( $q ) { return array(); }
    public function get_col( $q ) { return array(); }
    public function get_row( $q ) { return null; }
    public function insert() { return 1; }
    public function update() { return 1; }
}

require dirname( __DIR__, 2 ) . '/includes/class-hdlv2-activator.php';

$pass = 0; $fail = 0;
function ok( $name, $cond ) {
    global $pass, $fail;
    echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . "$name\n";
    $cond ? $pass++ : $fail++;
}

function migrate() {
    $m = new ReflectionMethod( 'HDLV2_Activator', 'run_migrations' );
    $m->setAccessible( true );
    return $m->invoke( null );
}
function count_like( $alters, $needle ) {
    return count( array_filter( $alters, function ( $q ) use ( $needle ) { return false !== strpos( $q, $needle ); } ) );
}

echo "── J. Phase AJ (v3.29) migration ──\n";
$wpdb = new FakeWpdb();
$wpdb->source_type = "enum('practitioner','automation','paid_stage1')";
$ret = migrate();
ok( 'J.1 from 3.28: success, one ALTER', true === $ret && 1 === count( $wpdb->alters ) );
ok( 'J.2 source ENUM gains full_report, keeps the old values + default', 1 === count_like( $wpdb->alters, "MODIFY COLUMN source ENUM('practitioner','automation','paid_stage1','full_report') NOT NULL DEFAULT 'practitioner'" ) );
ok( 'J.3 existing rows untouched (no UPDATE / DELETE / INSERT)', 0 === count( $wpdb->writes ) );
$wpdb->alters = array();
ok( 'J.4 second run changes nothing', true === migrate() && 0 === count( $wpdb->alters ) );
$wpdb = new FakeWpdb();
$wpdb->source_type = "enum('practitioner','automation','paid_stage1')";
$wpdb->fail_on = 'MODIFY COLUMN source';
ok( 'J.5 failed ALTER → run_migrations returns false (retries next boot)', false === migrate() );
$src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-hdlv2-activator.php' );
ok( 'J.6 fresh installs get the value too (CREATE TABLE)', false !== strpos( $src, "source ENUM('practitioner','automation','paid_stage1','full_report') NOT NULL DEFAULT 'practitioner'," ) );
$loader = file_get_contents( dirname( __DIR__, 2 ) . '/hdl-longevity-v2.php' );
ok( 'J.7 loader constant is 3.29', false !== strpos( $loader, "define( 'HDLV2_DB_VERSION', '3.29' );" ) );

echo "\nPASS=$pass FAIL=$fail\n";
exit( $fail ? 1 : 0 );
