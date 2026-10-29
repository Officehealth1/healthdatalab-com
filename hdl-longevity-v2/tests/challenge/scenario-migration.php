<?php
/**
 * Phase AL (DB v3.31) migration — 'challenge' joins widget_invites.source and
 * wp_hdlv2_challenge_days must exist. The REAL activator against a fake
 * $wpdb (same shape as tests/full-report/scenario-migration.php), starting at
 * 3.30 so only the new phase runs.
 *
 * Run:  php scenario-migration.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
ini_set( 'error_log', '/dev/null' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'HDLV2_DB_VERSION', '3.31' );
define( 'HDLV2_VERSION', '0.48.26' );

$GLOBALS['db_from'] = '3.30';
function get_option( $k, $d = false ) { return 'hdlv2_db_version' === $k ? $GLOBALS['db_from'] : $d; }
function update_option( $k, $v ) { return true; }
function add_action() {}
function add_filter() {}
function current_time( $t = 'mysql', $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }

class FakeWpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $alters = array();
    public $writes = array();
    public $source_type = "enum('practitioner','automation','paid_stage1','full_report')";
    public $tables = array( 'wp_hdlv2_challenge_days' => true ); // create_tables() ran first in upgrade()
    public $fail_on = '';

    public function prepare( $q, ...$args ) {
        foreach ( $args as $a ) {
            $q = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $q, 1 );
        }
        return $q;
    }
    public function get_charset_collate() { return ''; }
    public function get_var( $q ) {
        if ( false !== strpos( $q, 'COLUMN_TYPE' ) ) return $this->source_type;
        if ( false !== strpos( $q, 'INFORMATION_SCHEMA.TABLES' ) && preg_match( "/TABLE_NAME = '(\w+)'/", $q, $m ) ) {
            return isset( $this->tables[ $m[1] ] ) ? '1' : '0';
        }
        return '0';
    }
    public function query( $q ) {
        $this->last_error = '';
        if ( preg_match( '/^\s*(UPDATE|DELETE|INSERT)/i', $q ) ) $this->writes[] = $q;
        if ( 0 !== stripos( ltrim( $q ), 'ALTER TABLE' ) ) return 1;
        $this->alters[] = $q;
        if ( $this->fail_on && false !== strpos( $q, $this->fail_on ) ) {
            $this->last_error = 'Lock wait timeout (simulated)';
            return false;
        }
        if ( false !== strpos( $q, 'MODIFY COLUMN source' ) && preg_match( '/ENUM\([^)]*\)/', $q, $m ) ) $this->source_type = strtolower( $m[0] );
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

echo "── L. Phase AL (v3.31) migration ──\n";
$wpdb = new FakeWpdb();
$ret  = migrate();
ok( 'L.1 from 3.30: success, one ALTER', true === $ret && 1 === count( $wpdb->alters ) );
ok( 'L.2 source ENUM gains challenge, keeps the old values + default', false !== strpos( $wpdb->alters[0] ?? '', "MODIFY COLUMN source ENUM('practitioner','automation','paid_stage1','full_report','challenge') NOT NULL DEFAULT 'practitioner'" ) );
ok( 'L.3 existing rows untouched (no UPDATE / DELETE / INSERT)', 0 === count( $wpdb->writes ) );
$wpdb->alters = array();
ok( 'L.4 second run changes nothing', true === migrate() && 0 === count( $wpdb->alters ) );
$wpdb = new FakeWpdb();
$wpdb->fail_on = 'MODIFY COLUMN source';
ok( 'L.5 failed ALTER → false (the version stays, retried next boot)', false === migrate() );
$wpdb = new FakeWpdb();
$wpdb->tables = array();
ok( 'L.6 challenge_days table missing → false', false === migrate() );

$src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-hdlv2-activator.php' );
ok( 'L.7 fresh installs: invites ENUM carries challenge', false !== strpos( $src, "source ENUM('practitioner','automation','paid_stage1','full_report','challenge') NOT NULL DEFAULT 'practitioner'," ) );
ok( 'L.8 CREATE TABLE challenge_days: UNIQUE (client_user_id, day_no), note VARCHAR(500), dbDelta run', 1 === preg_match( '/CREATE TABLE \$table_challenge \(.*UNIQUE KEY client_day \(client_user_id, day_no\)/s', $src )
    && false !== strpos( $src, 'note VARCHAR(500) DEFAULT NULL' ) && false !== strpos( $src, 'dbDelta( $sql_challenge );' ) );
$loader = file_get_contents( dirname( __DIR__, 2 ) . '/hdl-longevity-v2.php' );
ok( 'L.9 loader: header + constant 0.48.28, DB 3.31', false !== strpos( $loader, ' * Version: 0.48.28' ) && false !== strpos( $loader, "define( 'HDLV2_VERSION', '0.48.28' );" ) && false !== strpos( $loader, "define( 'HDLV2_DB_VERSION', '3.31' );" ) );
$health = file_get_contents( dirname( __DIR__, 2 ) . '/includes/sprint-4/class-hdlv2-client-status.php' );
ok( 'L.10 /health expects the new table (17)', false !== strpos( $health, "'hdlv2_challenge_days'" ) );

echo "\nPASS=$pass FAIL=$fail\n";
exit( $fail ? 1 : 0 );
