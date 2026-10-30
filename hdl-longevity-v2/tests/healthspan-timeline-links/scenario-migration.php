<?php
/**
 * Phase AM (DB v3.32) migration — 'timeline_link' joins widget_invites.source and
 * wp_hdlv2_timeline_links must exist with every column. The REAL activator against a fake
 * $wpdb (the challenge suite's shape), starting at 3.31 so only the new phase runs.
 *
 * Run:  php scenario-migration.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
ini_set( 'error_log', '/dev/null' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'HDLV2_DB_VERSION', '3.32' );
define( 'HDLV2_VERSION', '0.48.34' );

$GLOBALS['db_from'] = '3.31';
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
    public $source_type = "enum('practitioner','automation','paid_stage1','full_report','challenge')";
    public $tables = array( 'wp_hdlv2_challenge_days' => true, 'wp_hdlv2_timeline_links' => true ); // create_tables() ran first in upgrade()
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

echo "── M. Phase AM (v3.32) migration ──\n";
$wpdb = new FakeWpdb();
$ret  = migrate();
ok( 'M.1 from 3.31: success, one ALTER', true === $ret && 1 === count( $wpdb->alters ) );
ok( 'M.2 source ENUM gains timeline_link, keeps the old values + default', false !== strpos( $wpdb->alters[0] ?? '', "MODIFY COLUMN source ENUM('practitioner','automation','paid_stage1','full_report','challenge','timeline_link') NOT NULL DEFAULT 'practitioner'" ) );
ok( 'M.3 existing rows untouched (no UPDATE / DELETE / INSERT)', 0 === count( $wpdb->writes ) );
$wpdb->alters = array();
ok( 'M.4 second run is a no-op', true === migrate() && 0 === count( $wpdb->alters ) );
$wpdb = new FakeWpdb();
$wpdb->fail_on = 'MODIFY COLUMN source';
ok( 'M.5 failed ALTER → false (the version stays, retried next boot)', false === migrate() );
$wpdb = new FakeWpdb();
unset( $wpdb->tables['wp_hdlv2_timeline_links'] );
ok( 'M.6 timeline_links table missing → false', false === migrate() );

$src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-hdlv2-activator.php' );
ok( 'M.7 fresh installs: invites ENUM carries timeline_link', false !== strpos( $src, "source ENUM('practitioner','automation','paid_stage1','full_report','challenge','timeline_link') NOT NULL DEFAULT 'practitioner'," ) );
preg_match( '/CREATE TABLE \$table_links \((.*?)\) \$charset_collate/s', $src, $m );
$ddl = $m[1] ?? '';
$cols = array(
    'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', 'owner_progress_id BIGINT(20) UNSIGNED NOT NULL', 'invite_id BIGINT(20) UNSIGNED DEFAULT NULL',
    'label VARCHAR(80) NOT NULL', "relationship ENUM('spouse','child','grandchild','parent','sibling','friend','other') NOT NULL",
    'born_guess SMALLINT UNSIGNED DEFAULT NULL', "sex_guess ENUM('F','M') DEFAULT NULL",
    "status ENUM('invited','accepted','declined','withdrawn','revoked','expired') NOT NULL DEFAULT 'invited'",
    'lead_id BIGINT(20) UNSIGNED DEFAULT NULL', 'shared_rate DECIMAL(4,2) DEFAULT NULL', 'shared_age SMALLINT UNSIGNED DEFAULT NULL',
    "shared_sex ENUM('F','M') DEFAULT NULL", 'withdraw_token_hash CHAR(64) DEFAULT NULL', 'consent_at DATETIME DEFAULT NULL',
    'withdrawn_at DATETIME DEFAULT NULL', 'revoked_at DATETIME DEFAULT NULL', 'created_at DATETIME', 'updated_at DATETIME',
    'UNIQUE KEY invite_id (invite_id)', 'KEY owner_progress_id (owner_progress_id)', 'KEY lead_id (lead_id)', 'KEY withdraw_token_hash (withdraw_token_hash)',
);
$missing = array_filter( $cols, function ( $c ) use ( $ddl ) { return false === strpos( $ddl, $c ); } );
ok( 'M.8 CREATE TABLE timeline_links: every column and key (missing: ' . implode( ' | ', $missing ) . ')', $ddl && ! $missing );
ok( 'M.9 dbDelta runs it', false !== strpos( $src, 'dbDelta( $sql_links );' ) );
$loader = file_get_contents( dirname( __DIR__, 2 ) . '/hdl-longevity-v2.php' );
ok( 'M.10 loader: header + constant 0.48.34, DB 3.32, the class loaded', false !== strpos( $loader, ' * Version: 0.48.34' ) && false !== strpos( $loader, "define( 'HDLV2_VERSION', '0.48.34' );" )
    && false !== strpos( $loader, "define( 'HDLV2_DB_VERSION', '3.32' );" ) && false !== strpos( $loader, 'class-hdlv2-timeline-links.php' ) );

echo "\nPASS=$pass FAIL=$fail\n";
exit( $fail ? 1 : 0 );
