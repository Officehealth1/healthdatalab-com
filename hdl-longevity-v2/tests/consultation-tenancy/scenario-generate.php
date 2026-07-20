<?php
/**
 * Cross-tenant guard tests for HDLV2_Final_Report::generate() and
 * ::regenerate() — defense-in-depth below the REST layer (the job queue
 * replays whatever pair was enqueued, so the generator must bind the
 * consultation to the ownership-checked progress row itself).
 *
 * World: practitioner A (uid 100) owns form_progress 10; consultation 55
 * belongs to progress 10 (A's own), consultation 77 belongs to progress 20
 * (practitioner B's — the victim, holding clinical notes).
 *
 * The fake wpdb enforces real predicate semantics: a consultation query
 * carrying `form_progress_id = N` only matches when the row's tenancy
 * agrees — exactly what the fixed `WHERE id = %d AND form_progress_id = %d`
 * produces against the real table.
 *
 * A SentinelReached exception is thrown when the pipeline reaches the
 * draft-report SELECT (the first query after the consultation gate) so the
 * legit flow is proven WITHOUT running the Claude/PDF/email pipeline.
 *
 * Proves:
 *  (a) generate()/regenerate() with the victim's consultation_id fail
 *      closed — WP_Error, pipeline never entered, ZERO writes to the
 *      victim's consultation_notes row;
 *  (b) generate()/regenerate() with A's own matching pair pass the gate
 *      and enter the report pipeline (legit finalise intact).
 *
 * Run:  php scenario-generate.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

define( 'ABSPATH', __DIR__ . '/' );

// ── WP stubs ──
function add_action() {}
function user_can() { return false; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function current_time() { return gmdate( 'Y-m-d H:i:s' ); }
function wp_json_encode( $x ) { return json_encode( $x ); }
function get_option( $k, $d = false ) { return $d; }
function apply_filters( $t, $v ) { return $v; }

class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code = '', $message = '', $data = null ) {
        $this->code = $code; $this->message = $message; $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}

// PATH C in regenerate() (existing organised + no new addenda) never calls
// these, but class_exists('HDLV2_AI_Service') must be true to enter the
// consultation-load branch at all.
class HDLV2_AI_Service {
    public static function integrate_addenda_into_organised( $o, $a ) { return new WP_Error( 'unexpected', 'should not be called' ); }
    public static function merge_consultation_with_addenda( $t, $a ) { return $t; }
    public static function organise_consultation_notes( $t ) { return new WP_Error( 'unexpected', 'should not be called' ); }
}

class SentinelReached extends Exception {}

// ── Fake wpdb — predicate-matching consultation store + write recorder ──
class FakeWpdb {
    public $prefix = 'wp_';
    public $updates = array();          // every ->update() call, recorded
    public $consults = array();         // id → row object (with form_progress_id)
    public $progress = array();         // id → row object
    public $last_error = '';
    public $insert_id = 0;

    public function prepare( $q, ...$args ) {
        foreach ( $args as $a ) {
            $q = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $q, 1 );
        }
        return $q;
    }

    private function match_consult( $q ) {
        if ( ! preg_match( '/\bid = (\d+)/', $q, $m ) ) return null;
        $row = $this->consults[ (int) $m[1] ] ?? null;
        if ( ! $row ) return null;
        // Real predicate semantics: if the query binds form_progress_id,
        // a mismatched tenancy finds no row.
        if ( preg_match( '/form_progress_id = (\d+)/', $q, $m2 )
             && (int) $row->form_progress_id !== (int) $m2[1] ) {
            return null;
        }
        return $row;
    }

    public function get_row( $q, $output = OBJECT ) {
        if ( false !== strpos( $q, 'hdlv2_form_progress' ) ) {
            if ( preg_match( '/id = (\d+)/', $q, $m ) ) {
                return $this->progress[ (int) $m[1] ] ?? null;
            }
            return null;
        }
        if ( false !== strpos( $q, "report_type = 'draft'" ) ) {
            // First query past the consultation gate — legit flow proven.
            throw new SentinelReached( 'draft SELECT reached' );
        }
        if ( false !== strpos( $q, 'hdlv2_consultation_notes' ) ) {
            return $this->match_consult( $q );
        }
        return null; // duplicate guard on reports, why_profiles, …
    }

    public function get_var( $q ) {
        if ( false !== strpos( $q, 'hdlv2_reports' ) && false !== strpos( $q, "'final'" ) ) {
            return '900'; // regenerate(): the existing Final row to UPDATE
        }
        if ( false !== strpos( $q, 'hdlv2_consultation_notes' ) ) {
            return $this->match_consult( $q ) ? '1' : '0';
        }
        return null;
    }

    public function get_results( $q, $output = OBJECT ) { return array(); } // no addenda

    public function update( $table, $data, $where, $format = null, $where_format = null ) {
        $this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
        return 1;
    }

    public function insert( $table, $data, $format = null ) { $this->insert_id = 901; return 1; }
    public function query( $q ) { return 0; }
}

if ( ! defined( 'OBJECT' ) ) define( 'OBJECT', 'OBJECT' );
if ( ! defined( 'ARRAY_A' ) ) define( 'ARRAY_A', 'ARRAY_A' );

require dirname( __DIR__, 2 ) . '/includes/sprint-2c/class-hdlv2-final-report.php';

$pass = 0; $fail = 0;
function ok( $name, $cond ) {
    global $pass, $fail;
    echo ( $cond ? 'PASS' : 'FAIL' ) . " | $name\n";
    $cond ? $pass++ : $fail++;
}

function fresh_world() {
    global $wpdb;
    $wpdb = new FakeWpdb();
    $wpdb->progress[10] = (object) array(
        'id' => 10, 'client_user_id' => 42, 'practitioner_user_id' => 100,
        'client_name' => 'Own Client', 'client_email' => 'own@example.test',
        'stage1_data' => '{}', 'stage3_data' => '{}', 'deleted_at' => null,
    );
    $organised = json_encode( array( 'recommendations' => array( array( 'text' => 'Walk daily' ) ) ) );
    $wpdb->consults[55] = (object) array(
        'id' => 55, 'form_progress_id' => 10, 'practitioner_user_id' => 100,
        'raw_notes' => 'own notes', 'typed_notes' => 'own notes',
        'ai_organised_notes' => $organised, 'recommendations' => null,
        'staged_milestones' => null,
    );
    $wpdb->consults[77] = (object) array(
        'id' => 77, 'form_progress_id' => 20, 'practitioner_user_id' => 200,
        'raw_notes' => 'VICTIM CLINICAL NOTES', 'typed_notes' => 'VICTIM CLINICAL NOTES',
        'ai_organised_notes' => $organised, 'recommendations' => null,
        'staged_milestones' => null,
    );
    return $wpdb;
}
function consult_writes( $wpdb ) {
    return array_values( array_filter( $wpdb->updates, function ( $u ) {
        return false !== strpos( $u['table'], 'consultation_notes' );
    } ) );
}

// ═══ (a) ATTACK — generate() with the victim's consultation ═══

$wpdb = fresh_world();
$sentinel = false;
try {
    $r = HDLV2_Final_Report::generate( 10, 77, 100 );
} catch ( SentinelReached $e ) {
    $sentinel = true; $r = null;
}
ok( 'generate: foreign consultation_id → WP_Error, pipeline never entered',
    ! $sentinel && $r instanceof WP_Error );
ok( 'generate: foreign consultation_id → zero writes to consultation_notes',
    0 === count( consult_writes( $wpdb ) ) );

// ═══ (a) ATTACK — regenerate() with the victim's consultation ═══

$wpdb = fresh_world();
$sentinel = false;
try {
    $r = HDLV2_Final_Report::regenerate( 10, 77, 100 );
} catch ( SentinelReached $e ) {
    $sentinel = true; $r = null;
}
ok( 'regenerate: foreign consultation_id → WP_Error, pipeline never entered',
    ! $sentinel && $r instanceof WP_Error );
ok( 'regenerate: foreign consultation_id → zero writes to consultation_notes',
    0 === count( consult_writes( $wpdb ) ) );

// ═══ (b) LEGIT — matching pair passes the gate into the pipeline ═══

$wpdb = fresh_world();
$sentinel = false;
try {
    HDLV2_Final_Report::generate( 10, 55, 100 );
} catch ( SentinelReached $e ) {
    $sentinel = true;
}
ok( 'generate: own matching pair → passes consultation gate into pipeline', $sentinel );

$wpdb = fresh_world();
$sentinel = false;
try {
    HDLV2_Final_Report::regenerate( 10, 55, 100 );
} catch ( SentinelReached $e ) {
    $sentinel = true;
}
ok( 'regenerate: own matching pair → passes consultation gate into pipeline', $sentinel );
// PATH C promise: regenerate must not rewrite ai_organised_notes when there
// are no new addenda (only the status reset touches the row, bound to the
// caller's own practitioner_user_id).
$w = consult_writes( $wpdb );
$rewrote_organised = false;
foreach ( $w as $u ) {
    if ( array_key_exists( 'ai_organised_notes', $u['data'] ) ) $rewrote_organised = true;
}
ok( 'regenerate: own pair, no addenda → ai_organised_notes untouched (PATH C)', ! $rewrote_organised );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
