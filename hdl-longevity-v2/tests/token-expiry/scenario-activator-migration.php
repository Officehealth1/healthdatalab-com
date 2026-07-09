<?php
/**
 * Migration tests for the DB v3.25 token-expiry backfill (Phase AF).
 *
 * Includes the REAL activator with a query-recording fake $wpdb at
 * db_version 3.24 (so ONLY the new 3.25 phase runs) and asserts:
 *   1. Backup table wp_hdlv2_form_progress_bak_v325 is created + populated
 *      BEFORE the backfill UPDATE runs, under a MySQL GET_LOCK (concurrent
 *      first-boot requests cannot backfill past an unpopulated backup).
 *   2. The backfill UPDATE targets only NULL rows (idempotent), uses
 *      GREATEST(created_at + 90 DAY, UTC_TIMESTAMP() + 14 DAY).
 *   3. Second run with the backup already present does NOT re-create or
 *      re-populate the backup (no clobber).
 *   4. A post-backfill NULL-count verification query runs.
 *   5. FAILURE HANDLING (review W1): upgrade() must NOT bump
 *      hdlv2_db_version when Phase AF fails (backup error / lock denied),
 *      so the migration retries on the next request instead of marking
 *      itself done and permanently locking legacy tokens out.
 *
 * Run:  php scenario-activator-migration.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
ini_set( 'error_log', '/dev/null' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HDLV2_DB_VERSION', '3.25' );
define( 'HDLV2_VERSION', '0.47.53' );

// ── WP stubs ──
$GLOBALS['options_set'] = array();
function get_option( $k, $d = false ) { return 'hdlv2_db_version' === $k ? '3.24' : $d; }
function update_option( $k, $v ) { $GLOBALS['options_set'][ $k ] = $v; return true; }
function add_action() {}
function add_filter() {}
function current_time( $t = 'mysql', $gmt = 0 ) { return $gmt ? gmdate( 'Y-m-d H:i:s' ) : date( 'Y-m-d H:i:s' ); }
function get_users( $a = array() ) { return array(); }
function wp_next_scheduled( $h ) { return time(); }
function wp_schedule_event() { return true; }
function wp_get_schedule( $h ) { return 'daily'; }
function wp_clear_scheduled_hook() {}
function wp_unschedule_event() {}
function wp_get_scheduled_event( $h ) { return (object) array( 'schedule' => 'daily', 'timestamp' => time() ); }

class FakeWpdb {
    public $prefix = 'wp_';
    public $queries = array();
    public $backup_exists = 0;
    public $lock_granted = true;
    public $fail_backup_create = false;
    public $last_error = '';
    public function prepare( $q, ...$args ) {
        foreach ( $args as $a ) {
            $q = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $q, 1 );
        }
        return $q;
    }
    public function get_charset_collate() { return ''; }
    public function get_var( $q ) {
        $this->queries[] = $q;
        if ( false !== stripos( $q, 'GET_LOCK' ) ) {
            return $this->lock_granted ? '1' : '0';
        }
        if ( false !== stripos( $q, 'RELEASE_LOCK' ) ) return '1';
        if ( false !== strpos( $q, 'form_progress_bak_v325' ) && false !== stripos( $q, 'INFORMATION_SCHEMA.TABLES' ) ) {
            return (string) $this->backup_exists;
        }
        if ( false !== strpos( $q, 'token_expires_at IS NULL' ) && false !== stripos( $q, 'COUNT' ) ) {
            return '0'; // post-backfill verification: nothing left NULL
        }
        return '0';
    }
    public function get_results( $q ) { $this->queries[] = $q; return array(); }
    public function get_col( $q ) { $this->queries[] = $q; return array(); }
    public function get_row( $q ) { $this->queries[] = $q; return null; }
    public function query( $q ) {
        $this->queries[] = $q;
        $this->last_error = '';
        if ( $this->fail_backup_create
             && false !== stripos( $q, 'CREATE TABLE' )
             && false !== strpos( $q, 'form_progress_bak_v325' ) ) {
            $this->last_error = 'Disk full (simulated)';
            return false;
        }
        return 3;
    }
    public function insert() { return 1; }
    public function update() { return 1; }
}

require dirname( __DIR__, 2 ) . '/includes/class-hdlv2-activator.php';

$pass = 0; $fail = 0;
function ok( $name, $cond ) {
    global $pass, $fail;
    echo ( $cond ? 'PASS' : 'FAIL' ) . " | $name\n";
    $cond ? $pass++ : $fail++;
}
function find_idx( $queries, $needles ) {
    foreach ( $queries as $i => $q ) {
        $hit = true;
        foreach ( (array) $needles as $n ) {
            if ( false === stripos( $q, $n ) ) { $hit = false; break; }
        }
        if ( $hit ) return $i;
    }
    return -1;
}

$run_migrations = function ( $configure ) {
    global $wpdb;
    $wpdb = new FakeWpdb();
    $configure( $wpdb );
    $m = new ReflectionMethod( 'HDLV2_Activator', 'run_migrations' );
    $m->setAccessible( true );
    $ret = $m->invoke( null );
    return array( $wpdb->queries, $ret );
};
$run_upgrade = function ( $configure ) {
    global $wpdb;
    $wpdb = new FakeWpdb();
    $configure( $wpdb );
    $GLOBALS['options_set'] = array();
    HDLV2_Activator::upgrade();
    return array( $wpdb->queries, $GLOBALS['options_set'] );
};

// ── Run 1: fresh migration (no backup table yet, lock granted) ──
list( $q1, $ret1 ) = $run_migrations( function ( $w ) { $w->backup_exists = 0; } );

$i_lock    = find_idx( $q1, array( 'GET_LOCK' ) );
$i_create  = find_idx( $q1, array( 'CREATE TABLE', 'form_progress_bak_v325' ) );
$i_fill    = find_idx( $q1, array( 'INSERT INTO', 'form_progress_bak_v325', 'SELECT' ) );
$i_update  = find_idx( $q1, array( 'UPDATE', 'hdlv2_form_progress', 'SET token_expires_at' ) );
$i_release = find_idx( $q1, array( 'RELEASE_LOCK' ) );
$upd       = $i_update >= 0 ? $q1[ $i_update ] : '';

ok( 'backup table created', $i_create >= 0 );
ok( 'backup table populated from live table', $i_fill >= 0 );
ok( 'backfill UPDATE runs', $i_update >= 0 );
ok( 'backup created BEFORE backfill', $i_create >= 0 && $i_update >= 0 && $i_create < $i_update );
ok( 'backup populated BEFORE backfill', $i_fill >= 0 && $i_update >= 0 && $i_fill < $i_update );
ok( 'GET_LOCK acquired BEFORE backup (concurrency guard)', $i_lock >= 0 && $i_lock < $i_create );
ok( 'lock released AFTER backfill', $i_release >= 0 && $i_release > $i_update );
ok( 'backfill only touches NULL rows (idempotent)', false !== stripos( $upd, 'WHERE token_expires_at IS NULL' ) );
ok( 'backfill uses created_at + 90-day window', false !== stripos( $upd, 'INTERVAL 90 DAY' ) );
ok( 'backfill has 14-day deploy-grace floor', false !== stripos( $upd, 'INTERVAL 14 DAY' ) );
ok( 'backfill takes the LATER of the two (GREATEST)', false !== stripos( $upd, 'GREATEST' ) );
ok( 'post-backfill NULL-count verification runs', find_idx( $q1, array( 'COUNT', 'token_expires_at IS NULL' ) ) > $i_update );
ok( 'run_migrations reports success', true === $ret1 );

// ── Run 2: backup already present — must not re-create/re-populate ──
list( $q2, $ret2 ) = $run_migrations( function ( $w ) { $w->backup_exists = 1; } );
ok( 'second run does not re-create backup', find_idx( $q2, array( 'CREATE TABLE', 'form_progress_bak_v325' ) ) < 0 );
ok( 'second run does not re-populate backup', find_idx( $q2, array( 'INSERT INTO', 'form_progress_bak_v325' ) ) < 0 );
ok( 'second run reports success', true === $ret2 );

// ── Run 3 (W1): backup CREATE fails → no backfill, failure reported ──
list( $q3, $ret3 ) = $run_migrations( function ( $w ) { $w->fail_backup_create = true; } );
ok( 'failed backup → backfill UPDATE never runs', find_idx( $q3, array( 'UPDATE', 'SET token_expires_at' ) ) < 0 );
ok( 'failed backup → run_migrations reports FAILURE', false === $ret3 );
ok( 'failed backup → lock still released', find_idx( $q3, array( 'RELEASE_LOCK' ) ) >= 0 );

// ── Run 4 (W2): lock denied (another request migrating) → skip, report failure ──
list( $q4, $ret4 ) = $run_migrations( function ( $w ) { $w->lock_granted = false; } );
ok( 'lock denied → no backup queries', find_idx( $q4, array( 'form_progress_bak_v325', 'CREATE' ) ) < 0 );
ok( 'lock denied → no backfill', find_idx( $q4, array( 'SET token_expires_at' ) ) < 0 );
ok( 'lock denied → run_migrations reports FAILURE (retry next boot)', false === $ret4 );

// ── Run 5 (W1): upgrade() gates the version bump on migration success ──
list( , $opts_ok ) = $run_upgrade( function ( $w ) {} );
ok( 'upgrade() success → hdlv2_db_version bumped to 3.25', ( $opts_ok['hdlv2_db_version'] ?? '' ) === '3.25' );

list( , $opts_fail ) = $run_upgrade( function ( $w ) { $w->fail_backup_create = true; } );
ok( 'upgrade() after Phase AF failure → hdlv2_db_version NOT bumped (retries next request)',
    ! isset( $opts_fail['hdlv2_db_version'] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
