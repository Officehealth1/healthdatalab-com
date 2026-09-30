<?php
/**
 * Phase AH (DB v3.27) migration — paid Stage 1 widget mode.
 *
 * Includes the REAL activator with a stateful fake $wpdb at db_version 3.26
 * (so only the new phase runs) and asserts: the two widget_config columns,
 * widget_invites.external_ref with its UNIQUE key and the 'paid_stage1'
 * source value are added once; a second run changes nothing; a failed ALTER
 * makes run_migrations() return false so the version does not bump.
 *
 * Run:  php scenario-migration.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
ini_set( 'error_log', '/dev/null' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HDLV2_DB_VERSION', '3.27' );
define( 'HDLV2_VERSION', '0.47.85' );

function get_option( $k, $d = false ) { return 'hdlv2_db_version' === $k ? '3.26' : $d; }
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
    public function query( $q ) {
        $this->last_error = '';
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

echo "── 14. Phase AH (v3.27) migration ──\n";
$wpdb = new FakeWpdb();
$ret  = migrate();
ok( '14.1 first run reports success', true === $ret );
ok( '14.2 access_mode added to widget_config, default open', 1 === count_like( $wpdb->alters, "ADD COLUMN access_mode ENUM('open','paid') NOT NULL DEFAULT 'open'" ) && isset( $wpdb->columns['hdlv2_widget_config.access_mode'] ) );
ok( '14.3 buy_url added to widget_config', 1 === count_like( $wpdb->alters, 'ADD COLUMN buy_url VARCHAR(500)' ) && isset( $wpdb->columns['hdlv2_widget_config.buy_url'] ) );
ok( '14.4 external_ref added to widget_invites, nullable', 1 === count_like( $wpdb->alters, 'ADD COLUMN external_ref VARCHAR(128) DEFAULT NULL' ) && isset( $wpdb->columns['hdlv2_widget_invites.external_ref'] ) );
ok( '14.5 UNIQUE key on external_ref', 1 === count_like( $wpdb->alters, 'ADD UNIQUE KEY external_ref (external_ref)' ) );
ok( '14.6 source ENUM gains paid_stage1 and keeps the old values + default', 1 === count_like( $wpdb->alters, "MODIFY COLUMN source ENUM('practitioner','automation','paid_stage1') NOT NULL DEFAULT 'practitioner'" ) );
ok( '14.7 exactly five ALTERs', 5 === count( $wpdb->alters ) );

$wpdb->alters = array();
$ret = migrate();
ok( '14.8 second run changes nothing and reports success', true === $ret && 0 === count( $wpdb->alters ) );

foreach ( array( 'ADD COLUMN access_mode', 'ADD COLUMN external_ref', 'MODIFY COLUMN source', 'ADD UNIQUE KEY' ) as $i => $needle ) {
    $wpdb = new FakeWpdb();
    $wpdb->fail_on = $needle;
    ok( '14.' . ( 9 + $i ) . " failed \"$needle\" → run_migrations returns false (retries next boot)", false === migrate() );
}

echo "\nPASS=$pass FAIL=$fail\n";
exit( $fail ? 1 : 0 );
