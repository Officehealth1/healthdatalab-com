<?php
/**
 * Phase AK (DB v3.30) migration — widget_leads gains stage1_fired_at + visitor_phone,
 * and every existing row is backfilled as already fired (history never refires).
 * The REAL activator against a fake $wpdb (the tests/full-report/scenario-migration.php
 * shape), starting at 3.29 so only the new phase runs.
 *
 * Run:  php scenario-migration.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
$GLOBALS['logs'] = array();
ini_set( 'error_log', '/dev/null' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'HDLV2_DB_VERSION', '3.30' );
define( 'HDLV2_VERSION', '0.48.16' );

$GLOBALS['db_from'] = '3.29';
function get_option( $k, $d = false ) { return 'hdlv2_db_version' === $k ? $GLOBALS['db_from'] : $d; }
function update_option( $k, $v ) { return true; }
function add_action() {}
function add_filter() {}
function current_time( $t = 'mysql', $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }

class FakeWpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $rows_affected = 0;
    public $alters = array();
    public $writes = array();
    public $columns = array();  // "table.column" => true
    public $fail_on = '';       // substring of a query that must fail
    public $unfired = 106;      // rows the backfill finds with stage1_fired_at NULL

    public function prepare( $q, ...$args ) {
        foreach ( $args as $a ) {
            $q = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $q, 1 );
        }
        return $q;
    }
    public function get_charset_collate() { return ''; }
    public function get_var( $q ) {
        // Phase AL (v3.31): modelled as done (dbDelta made the table, the ENUM has 'challenge').
        if ( false !== strpos( $q, 'COLUMN_TYPE' ) ) return "enum('practitioner','automation','paid_stage1','full_report','challenge','timeline_link')";
        if ( false !== strpos( $q, 'INFORMATION_SCHEMA.TABLES' ) ) return '1';
        if ( preg_match( "/TABLE_NAME = 'wp_(\w+)' AND COLUMN_NAME = '(\w+)'/", $q, $m ) ) {
            return isset( $this->columns[ $m[1] . '.' . $m[2] ] ) ? '1' : '0';
        }
        if ( preg_match( "/TABLE_NAME = 'wp_(\w+)'\s+AND COLUMN_NAME IN \('(\w+)','(\w+)'\)/", $q, $m ) ) {
            return (string) ( (int) isset( $this->columns[ $m[1] . '.' . $m[2] ] ) + (int) isset( $this->columns[ $m[1] . '.' . $m[3] ] ) );
        }
        return '0';
    }
    public function query( $q ) {
        $this->last_error = '';
        if ( $this->fail_on && false !== strpos( $q, $this->fail_on ) ) {
            $this->last_error = 'Lock wait timeout (simulated)';
            return false;
        }
        if ( preg_match( '/^\s*(UPDATE|DELETE|INSERT)/i', $q ) ) {
            $this->writes[] = $q;
            if ( false !== strpos( $q, 'stage1_fired_at IS NULL' ) ) { $n = $this->unfired; $this->unfired = 0; $this->rows_affected = $n; return $n; }
            return 0;
        }
        if ( 0 !== stripos( ltrim( $q ), 'ALTER TABLE' ) ) return 1;
        $this->alters[] = $q;
        if ( preg_match_all( '/ADD COLUMN (\w+)/', $q, $all ) && preg_match( '/ALTER TABLE `?wp_(\w+)`?/', $q, $t ) ) {
            foreach ( $all[1] as $c ) $this->columns[ $t[1] . '.' . $c ] = true;
        }
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
    // Capture the log lines the migration writes.
    $tmp = tempnam( sys_get_temp_dir(), 'ak' );
    ini_set( 'error_log', $tmp );
    $r = $m->invoke( null );
    ini_set( 'error_log', '/dev/null' );
    $GLOBALS['logs'] = file( $tmp );
    unlink( $tmp );
    return $r;
}
function count_like( $list, $needle ) {
    return count( array_filter( $list, function ( $q ) use ( $needle ) { return false !== strpos( $q, $needle ); } ) );
}

echo "── K. Phase AK (v3.30) migration ──\n";
$wpdb = new FakeWpdb();
$ret  = migrate();
ok( 'K.1 from 3.29: success', true === $ret );
ok( 'K.2 adds stage1_fired_at DATETIME NULL', 1 === count_like( $wpdb->alters, 'ADD COLUMN stage1_fired_at DATETIME NULL' ) && isset( $wpdb->columns['hdlv2_widget_leads.stage1_fired_at'] ) );
ok( 'K.3 adds visitor_phone VARCHAR(40) NULL', 1 === count_like( $wpdb->alters, 'ADD COLUMN visitor_phone VARCHAR(40) NULL' ) && isset( $wpdb->columns['hdlv2_widget_leads.visitor_phone'] ) );
ok( 'K.4 only widget_leads is altered', count( $wpdb->alters ) === count_like( $wpdb->alters, 'hdlv2_widget_leads' ) );
ok( 'K.5 backfill: COALESCE(stage1_pdf_generated_at, created_at) WHERE stage1_fired_at IS NULL',
    1 === count_like( $wpdb->writes, 'SET stage1_fired_at = COALESCE(stage1_pdf_generated_at, created_at) WHERE stage1_fired_at IS NULL' ) );
ok( 'K.6 log names the phase and the row count', 1 === count_like( $GLOBALS['logs'], 'Phase AK (v3.30)' . ' migration: stage1_fired_at + visitor_phone verified, backfilled 106 rows' ) );

$wpdb->alters = array(); $wpdb->writes = array();
ok( 'K.7 second run: no ALTER, backfill finds nothing', true === migrate() && 0 === count( $wpdb->alters ) && 0 === $wpdb->unfired );

$wpdb = new FakeWpdb();
$wpdb->fail_on = 'ADD COLUMN stage1_fired_at';
ok( 'K.8 failed ALTER → false (version not bumped), no backfill', false === migrate() && 0 === count( $wpdb->writes ) );

$wpdb = new FakeWpdb();
$wpdb->fail_on = 'SET stage1_fired_at = COALESCE';
ok( 'K.9 failed backfill → false (the drain must never see history as unfired)', false === migrate() );

$src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-hdlv2-activator.php' );
ok( 'K.10 fresh installs get both columns (CREATE TABLE)', false !== strpos( $src, 'visitor_phone VARCHAR(40) DEFAULT NULL,' ) && false !== strpos( $src, 'stage1_fired_at DATETIME DEFAULT NULL,' ) );
ok( 'K.11 cron map: drain every minute, replay every five, alert hourly',
    false !== strpos( $src, "'hdlv2_stage1_drain'            => 'hdlv2_one_minute'" )
    && false !== strpos( $src, "'hdlv2_stage1_waiting_replay'   => 'hdlv2_five_minutes'" )
    && false !== strpos( $src, "'hdlv2_stage1_alert'            => 'hourly'" ) );
$loader = file_get_contents( dirname( __DIR__, 2 ) . '/hdl-longevity-v2.php' );
ok( 'K.12 loader constants 0.48.34 / 3.32 (v0.48.34; Phase AK stays in it)', false !== strpos( $loader, "define( 'HDLV2_DB_VERSION', '3.32' );" ) && false !== strpos( $loader, "define( 'HDLV2_VERSION', '0.48.34' );" ) );

echo "\nPASS=$pass FAIL=$fail\n";
exit( $fail ? 1 : 0 );
