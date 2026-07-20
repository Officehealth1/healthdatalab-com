<?php
/**
 * Cross-tenant guard tests for HDLV2_Consultation::enqueue_report_job()
 * (reached via rest_finalise + rest_save_and_update_plan — the two REST
 * call-sites of the finalise/regenerate chain).
 *
 * World: practitioner A (uid 100) owns form_progress 10; practitioner B
 * (uid 200) owns form_progress 20. Consultation 55 belongs to progress 10
 * (A's own), consultation 77 belongs to progress 20 (B's — the victim).
 *
 * Proves:
 *  (a) A passing B's consultation_id with A's own progress_id is DENIED
 *      (403) and no job is enqueued — B's notes are never read/overwritten;
 *  (b) A finalising/regenerating with A's own matching pair still enqueues
 *      the job normally (legit flow intact).
 *
 * Run:  php scenario-enqueue.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t'] = array( 'uid' => 100 );

// ── WP stubs ──
function add_action() {}
function add_shortcode() {}
function register_rest_route() {}
function get_current_user_id() { return (int) $GLOBALS['t']['uid']; }
function current_user_can() { return false; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function rest_ensure_response( $x ) { return $x; }
function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . $p; }
function esc_url_raw( $u ) { return $u; }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : $s; }
function sanitize_key( $s ) { return $s; }
function absint( $n ) { return abs( (int) $n ); }
function wp_unslash( $s ) { return $s; }
function esc_html( $s ) { return $s; }
function get_option( $k, $d = false ) { return $d; }
function apply_filters( $t, $v ) { return $v; }
function current_time() { return gmdate( 'Y-m-d H:i:s' ); }
function wp_json_encode( $x ) { return json_encode( $x ); }

class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code = '', $message = '', $data = null ) {
        $this->code = $code; $this->message = $message; $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}

// ── Spy job queue — records enqueues, never runs anything ──
class HDLV2_Job_Queue {
    public static $enqueued = array();
    public static function find_latest( $type, $ref ) { return null; }
    public static function enqueue( $type, $payload, $opts = array() ) {
        self::$enqueued[] = array( 'type' => $type, 'payload' => $payload, 'opts' => $opts );
        return count( self::$enqueued );
    }
}
class HDLV2_Report_Jobs {
    const JOB_FINAL = 'generate_final_report';
    const JOB_REGEN = 'regenerate_final_report';
}

// ── Fake wpdb — in-memory truth: progress owners + consultation tenancy ──
class FakeWpdb {
    public $prefix = 'wp_';
    // form_progress id → practitioner owner
    public $progress_owner = array( 10 => 100, 20 => 200 );
    // consultation id → form_progress_id it belongs to
    public $consults = array( 55 => 10, 77 => 20 );

    public function prepare( $q, ...$args ) {
        foreach ( $args as $a ) {
            $q = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $q, 1 );
        }
        return $q;
    }

    public function get_var( $q ) {
        if ( false !== strpos( $q, 'GET_LOCK' ) || false !== strpos( $q, 'RELEASE_LOCK' ) ) {
            return 1;
        }
        if ( false !== strpos( $q, 'SELECT practitioner_user_id FROM' ) ) {
            if ( preg_match( '/id = (\d+)/', $q, $m ) ) {
                $id = (int) $m[1];
                return isset( $this->progress_owner[ $id ] ) ? (string) $this->progress_owner[ $id ] : null;
            }
            return null;
        }
        // Consultation→progress binding check (COUNT or SELECT form_progress_id)
        if ( false !== strpos( $q, 'hdlv2_consultation_notes' ) ) {
            if ( ! preg_match( '/\bid = (\d+)/', $q, $m ) ) return null;
            $cid = (int) $m[1];
            if ( ! isset( $this->consults[ $cid ] ) ) {
                return ( false !== strpos( $q, 'COUNT(' ) ) ? '0' : null;
            }
            $fp = $this->consults[ $cid ];
            if ( preg_match( '/form_progress_id = (\d+)/', $q, $m2 ) && (int) $m2[1] !== $fp ) {
                return ( false !== strpos( $q, 'COUNT(' ) ) ? '0' : null;
            }
            return ( false !== strpos( $q, 'COUNT(' ) ) ? '1' : (string) $fp;
        }
        return null;
    }

    public function get_row( $q ) { return null; }
    public function get_results( $q ) { return array(); }
    public function query( $q ) { return 0; }
}

class FakeRequest {
    private $params;
    public function __construct( $params ) { $this->params = $params; }
    public function get_param( $k ) { return $this->params[ $k ] ?? null; }
    public function get_json_params() { return $this->params; }
    public function get_header( $k ) { return null; }
}

require dirname( __DIR__, 2 ) . '/includes/sprint-2c/class-hdlv2-consultation.php';

global $wpdb;
$wpdb = new FakeWpdb();
$c    = new HDLV2_Consultation();

$pass = 0; $fail = 0;
function ok( $name, $cond ) {
    global $pass, $fail;
    echo ( $cond ? 'PASS' : 'FAIL' ) . " | $name\n";
    $cond ? $pass++ : $fail++;
}
function denied_403( $r ) {
    return $r instanceof WP_Error
        && 403 === (int) ( $r->get_error_data()['status'] ?? 0 );
}

// ═══ (a) ATTACK — A's progress + B's consultation ═══

HDLV2_Job_Queue::$enqueued = array();
$r = $c->rest_finalise( new FakeRequest( array( 'progress_id' => 10, 'consultation_id' => 77 ) ) );
ok( 'finalise: foreign consultation_id → 403', denied_403( $r ) );
ok( 'finalise: foreign consultation_id → no job enqueued', 0 === count( HDLV2_Job_Queue::$enqueued ) );

HDLV2_Job_Queue::$enqueued = array();
$r = $c->rest_save_and_update_plan( new FakeRequest( array( 'progress_id' => 10, 'consultation_id' => 77 ) ) );
ok( 'regenerate: foreign consultation_id → 403', denied_403( $r ) );
ok( 'regenerate: foreign consultation_id → no job enqueued', 0 === count( HDLV2_Job_Queue::$enqueued ) );

HDLV2_Job_Queue::$enqueued = array();
$r = $c->rest_finalise( new FakeRequest( array( 'progress_id' => 10, 'consultation_id' => 999 ) ) );
ok( 'finalise: nonexistent consultation_id → denied, no oracle', denied_403( $r ) );
ok( 'finalise: nonexistent consultation_id → no job enqueued', 0 === count( HDLV2_Job_Queue::$enqueued ) );

// ═══ (b) LEGIT — A's progress + A's own consultation ═══

HDLV2_Job_Queue::$enqueued = array();
$r = $c->rest_finalise( new FakeRequest( array( 'progress_id' => 10, 'consultation_id' => 55 ) ) );
ok( 'finalise: own matching pair → queued response',
    is_array( $r ) && true === ( $r['success'] ?? false ) && 'queued' === ( $r['state'] ?? '' ) );
ok( 'finalise: own matching pair → JOB_FINAL enqueued with correct payload',
    1 === count( HDLV2_Job_Queue::$enqueued )
    && HDLV2_Report_Jobs::JOB_FINAL === HDLV2_Job_Queue::$enqueued[0]['type']
    && 10 === (int) HDLV2_Job_Queue::$enqueued[0]['payload']['progress_id']
    && 55 === (int) HDLV2_Job_Queue::$enqueued[0]['payload']['consultation_id']
    && 100 === (int) HDLV2_Job_Queue::$enqueued[0]['payload']['practitioner_id'] );

HDLV2_Job_Queue::$enqueued = array();
$r = $c->rest_save_and_update_plan( new FakeRequest( array( 'progress_id' => 10, 'consultation_id' => 55 ) ) );
ok( 'regenerate: own matching pair → queued response',
    is_array( $r ) && true === ( $r['success'] ?? false ) && 'queued' === ( $r['state'] ?? '' ) );
ok( 'regenerate: own matching pair → JOB_REGEN enqueued',
    1 === count( HDLV2_Job_Queue::$enqueued )
    && HDLV2_Report_Jobs::JOB_REGEN === HDLV2_Job_Queue::$enqueued[0]['type'] );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
