<?php
/**
 * write_progress_point() tests — the V1-compatibility trend write must
 * actually land in wp_health_tracker_progress and be visible to V1.
 *
 * The original bugs (verified against the deployed table + V1 source):
 *  1. Inserted a column `metric_value` that does not exist (real column:
 *     `current_value` decimal(8,2)) — every insert failed with
 *     "Unknown column 'metric_value'".
 *  2. Omitted `form_source`, NOT NULL with no default — even with the
 *     column renamed the insert still fails (strict mode) or writes a row
 *     invisible to the client dashboard's Longevity/Health tabs.
 *  3. Hashed the email with wp_hash() (HMAC-MD5, 32 hex) while every V1
 *     reader keys on hash('sha256', email . health_tracker_salt) (64 hex,
 *     class-health-tracker.php generate_user_hash) — even a structurally
 *     correct row could never be found by V1.
 *  4. Returned true unconditionally, so all of the above was silent.
 *
 * Plus three adversarial-review findings against the first fix attempt:
 *  5. Only the 5 headline metrics (rate_of_ageing, biological_age, bmi,
 *     whr, whtr) may be written. The caller passes ~21 extra 0-5 scores;
 *     V1's Progress Insights renders progress.slice(0,5) of rows ordered
 *     measurement_date DESC, so 26 identically-stamped rows would blank
 *     the section / show 5 arbitrary sub-scores and displace V1's own
 *     insights. V1's writer stores exactly 4 headline metrics — parity.
 *  6. measurement_date must be left to the DB DEFAULT current_timestamp()
 *     — V1's writer omits the column, so stamping WP-local time
 *     (Europe/London, +1h in BST) would interleave two clocks in the one
 *     column every reader sorts by (the BST bug class fixed three times
 *     before: v0.26.2 #5, v0.27.1 #15, B4). Same-day idempotency probes
 *     compare against CURDATE() — the same DB clock.
 *  7. 0 is a legitimate worst-band V2 score (whtrScore=0 at WHtR≥0.70),
 *     not V1's missing-data sentinel — the value guard must not censor it
 *     (moot for headline metrics, kept for correctness).
 *
 * The fake wpdb enforces the REAL table schema (columns + NOT NULL
 * constraints): an insert with an unknown column or a missing required
 * column fails exactly like MariaDB would. RED against the pre-fix code.
 *
 * Run:  php scenario-write.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

define( 'ABSPATH', __DIR__ . '/' );

// ── WP stubs ──
const TEST_EMAIL = 'Casey.Smoketest@Example.com '; // mixed case + trailing space on purpose
const TEST_SALT  = 'testsalt123';

function get_userdata( $uid ) {
    if ( 196 !== $uid ) return false;
    $u = new stdClass();
    $u->user_email = TEST_EMAIL;
    return $u;
}
function get_option( $k, $d = false ) {
    return 'health_tracker_salt' === $k ? TEST_SALT : $d;
}
function wp_hash( $x ) {
    return 'WRONG-WPHASH-' . md5( $x ); // if this ever lands in a row, the fix regressed
}
function current_time( $type ) {
    if ( 'mysql' === $type ) return '2026-07-10 14:30:00';
    if ( 'Y-m-d' === $type ) return '2026-07-10';
    return '2026-07-10 14:30:00';
}

// The hash every V1 reader derives (class-health-tracker.php generate_user_hash).
function v1_hash() {
    return hash( 'sha256', strtolower( trim( TEST_EMAIL ) ) . TEST_SALT );
}

// ── Fake wpdb — real wp_health_tracker_progress schema semantics ──
class FakeWpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $rows = array();            // successfully inserted rows
    public $rejects = array();         // rejected inserts: metric => reason
    public $prior_values = array();    // metric_name => current_value of latest prior row
    public $same_day = array();        // metric_name => true (a row already exists today, DB clock)
    public $force_fail_metrics = array(); // metric_name => simulate engine-level insert failure

    const COLUMNS  = array( 'id', 'user_hash', 'metric_name', 'current_value', 'previous_value', 'change_value', 'change_percent', 'measurement_date', 'form_source' );
    const REQUIRED = array( 'user_hash', 'metric_name', 'form_source' ); // NOT NULL, no default

    public function prepare( $q, ...$args ) {
        foreach ( $args as $a ) {
            $q = preg_replace( '/%[sdf]/', is_int( $a ) || is_float( $a ) ? (string) $a : "'" . $a . "'", $q, 1 );
        }
        return $q;
    }

    public function get_var( $q ) {
        if ( false !== stripos( $q, 'SHOW TABLES' ) ) {
            return 'wp_health_tracker_progress';
        }
        // Same-day idempotency probe — must compare on the DB clock (CURDATE()),
        // never a PHP-derived date, when no explicit $date was passed.
        if ( false !== strpos( $q, 'DATE(measurement_date)' ) ) {
            $metric = $this->extract_metric( $q );
            $GLOBALS['day_probes'][] = $q;
            return ! empty( $this->same_day[ $metric ] ) ? '1' : null;
        }
        // Latest prior point lookup
        if ( false !== strpos( $q, 'current_value' ) && false !== stripos( $q, 'ORDER BY measurement_date DESC' ) ) {
            $metric = $this->extract_metric( $q );
            return isset( $this->prior_values[ $metric ] ) ? (string) $this->prior_values[ $metric ] : null;
        }
        return null;
    }

    private function extract_metric( $q ) {
        return preg_match( "/metric_name\s*=\s*'([^']+)'/", $q, $m ) ? $m[1] : '';
    }

    public function insert( $table, $data, $format = null ) {
        if ( 'wp_health_tracker_progress' !== $table ) {
            $this->last_error = "Table '$table' doesn't exist";
            return false;
        }
        foreach ( array_keys( $data ) as $col ) {
            if ( ! in_array( $col, self::COLUMNS, true ) ) {
                $this->last_error = "Unknown column '$col' in 'INSERT INTO'";
                $this->rejects[ $data['metric_name'] ?? '?' ] = $this->last_error;
                return false;
            }
        }
        foreach ( self::REQUIRED as $col ) {
            if ( ! isset( $data[ $col ] ) || '' === $data[ $col ] ) {
                $this->last_error = "Field '$col' doesn't have a default value";
                $this->rejects[ $data['metric_name'] ?? '?' ] = $this->last_error;
                return false;
            }
        }
        $metric = $data['metric_name'];
        if ( ! empty( $this->force_fail_metrics[ $metric ] ) ) {
            $this->last_error = 'forced engine failure';
            $this->rejects[ $metric ] = $this->last_error;
            return false;
        }
        $this->rows[] = $data;
        $this->last_error = '';
        return 1;
    }
}

// ── Load the real class ──
require __DIR__ . '/../../includes/class-hdlv2-compatibility.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) {
    global $pass, $fail;
    echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n";
    $ok ? $pass++ : $fail++;
}
function row_for( $wpdb, $metric ) {
    foreach ( $wpdb->rows as $r ) if ( $r['metric_name'] === $metric ) return $r;
    return null;
}

// ── S1: headline metrics land; scores are whitelisted OUT; DB clock stamps the row ──
$GLOBALS['day_probes'] = array();
$wpdb = new FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;
$ret = HDLV2_Compatibility::write_progress_point( 196, array(
    'rate_of_ageing' => 1.17,
    'biological_age' => 52.6,
    'heartRateScore' => 5,      // sub-score — must NOT be written (Insights top-5 pollution)
    'sleepQuality'   => 4,      // sub-score — must NOT be written
) );

check( 'S1 exactly the two headline rows land', count( $wpdb->rows ) === 2 );
check( 'S1 sub-scores are whitelisted out', ! row_for( $wpdb, 'heartRateScore' ) && ! row_for( $wpdb, 'sleepQuality' ) );
check( 'S1 no insert was rejected by the schema', empty( $wpdb->rejects ) );
$r = row_for( $wpdb, 'rate_of_ageing' );
check( 'S1 value mapped to current_value (1.17)', $r && isset( $r['current_value'] ) && abs( $r['current_value'] - 1.17 ) < 0.001 );
check( "S1 form_source = 'longevity'", $r && ( $r['form_source'] ?? '' ) === 'longevity' );
check( 'S1 user_hash matches the V1 salted sha256', $r && ( $r['user_hash'] ?? '' ) === v1_hash() );
check( 'S1 measurement_date omitted — DB DEFAULT stamps on the same clock as V1 rows', $r && ! array_key_exists( 'measurement_date', $r ) );
check( 'S1 same-day probe compares against CURDATE() (DB clock), not a PHP date', ! empty( $GLOBALS['day_probes'] ) && false !== strpos( $GLOBALS['day_probes'][0], 'CURDATE()' ) );
check( 'S1 baseline row has no previous/change columns', $r && ! isset( $r['previous_value'] ) && ! isset( $r['change_value'] ) );
check( 'S1 returns true when every insert lands', $ret === true );

// ── S2: prior point exists → V1-parity trend columns computed ──
$wpdb = new FakeWpdb();
$wpdb->prior_values['biological_age'] = '55.00';
$GLOBALS['wpdb'] = $wpdb;
HDLV2_Compatibility::write_progress_point( 196, array( 'biological_age' => 52.6 ) );

$r = row_for( $wpdb, 'biological_age' );
check( 'S2 delta row lands', $r !== null );
check( 'S2 previous_value = 55.00', $r && abs( ( $r['previous_value'] ?? 0 ) - 55.0 ) < 0.001 );
check( 'S2 change_value = -2.40', $r && abs( ( $r['change_value'] ?? 0 ) - ( -2.4 ) ) < 0.001 );
check( 'S2 change_percent = -4.36', $r && abs( ( $r['change_percent'] ?? 0 ) - ( -4.36 ) ) < 0.005 );

// ── S3: same-day row already exists → skip (idempotent regenerate), never delete ──
$wpdb = new FakeWpdb();
$wpdb->same_day['rate_of_ageing'] = true;
$GLOBALS['wpdb'] = $wpdb;
$ret = HDLV2_Compatibility::write_progress_point( 196, array( 'rate_of_ageing' => 1.18, 'biological_age' => 52.6 ) );

check( 'S3 same-day metric skipped, other metric lands', count( $wpdb->rows ) === 1 && row_for( $wpdb, 'biological_age' ) );
check( 'S3 skip is not a failure (returns true)', $ret === true );

// ── S4: value sanitisation for decimal(8,2) — zero is NOT censored ──
$wpdb = new FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;
HDLV2_Compatibility::write_progress_point( 196, array(
    'whtr'           => 0,          // 0 is a legitimate value — must land (review finding)
    'bmi'            => 'abc',      // non-numeric — skipped
    'whr'            => 10000000,   // > 999999.99 decimal(8,2) — skipped
    'biological_age' => -1,         // negative — skipped (no metric is legitimately negative)
) );
check( 'S4 zero-valued metric lands (not censored as missing)', count( $wpdb->rows ) === 1 && row_for( $wpdb, 'whtr' ) );
check( 'S4 nothing was rejected at the schema layer (bad values skipped BEFORE insert)', empty( $wpdb->rejects ) );

// ── S5: change_percent clamped to decimal(5,2); prev=0 → percent omitted, delta kept ──
$wpdb = new FakeWpdb();
$wpdb->prior_values['whr'] = '0.01';
$GLOBALS['wpdb'] = $wpdb;
HDLV2_Compatibility::write_progress_point( 196, array( 'whr' => 500 ) );
$r = row_for( $wpdb, 'whr' );
check( 'S5 change_percent clamped to 999.99', $r && isset( $r['change_percent'] ) && $r['change_percent'] <= 999.99 );

$wpdb = new FakeWpdb();
$wpdb->prior_values['whtr'] = '0.00';
$GLOBALS['wpdb'] = $wpdb;
HDLV2_Compatibility::write_progress_point( 196, array( 'whtr' => 0.55 ) );
$r = row_for( $wpdb, 'whtr' );
check( 'S5 prev=0: change_value written, change_percent omitted (no div-by-zero)', $r && isset( $r['change_value'] ) && ! isset( $r['change_percent'] ) );

// ── S6: engine-level insert failure → honest false return ──
$wpdb = new FakeWpdb();
$wpdb->force_fail_metrics['bmi'] = true;
$GLOBALS['wpdb'] = $wpdb;
$ret = HDLV2_Compatibility::write_progress_point( 196, array( 'rate_of_ageing' => 1.5, 'bmi' => 24.2 ) );
check( 'S6 surviving metric still lands', row_for( $wpdb, 'rate_of_ageing' ) !== null );
check( 'S6 returns false when any insert fails', $ret === false );

// ── S7: unknown user → false, zero writes ──
$wpdb = new FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;
$ret = HDLV2_Compatibility::write_progress_point( 999, array( 'bmi' => 24 ) );
check( 'S7 unknown user: false + no rows', $ret === false && count( $wpdb->rows ) === 0 );

echo "\n" . ( $fail ? "SCENARIO: FAIL ($fail)\n" : "SCENARIO: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );
