<?php
/**
 * Plan E4-b (v0.48.40), Story 3, the server side: what each write route the client page (and the
 * two client-only pages it links to) can reach does when the caller is the practitioner, with the
 * Demo Owner's ids. The real handlers, a fake $wpdb that records every write.
 *
 *   /flight-plan/tick, /flight-plan/tick-all  the owning practitioner may tick (their own Flight Plan
 *                                             tab on /clients/ does); anyone else: 403, nothing written
 *   /challenge/tick                           the caller's own 30 days only: a practitioner is not enrolled (403)
 *   /checkin/submit, /checkin/confirm         the client's 64-hex token only: 401, nothing written
 *   admin-ajax hdl_post_message, hdl_mark_read  the V1 plugin (another repository): see the report
 *
 *   php tests/view-as-client/scenario-writes.php
 */
require dirname( __DIR__ ) . '/dashboard-products/stubs.php';

class WP_REST_Request {
    private $p;
    public function __construct( array $p ) { $this->p = $p; }
    public function get_json_params() { return $this->p; }
    public function get_param( $k ) { return $this->p[ $k ] ?? null; }
}
class HDLV2_Idempotency {
    public static function wrap( $req, $scope, $fn ) { return $fn(); }
    public static function wrap_ai( $req, $scope, $fn ) { return $fn(); }
}
class HDLV2_Timeline { public static function add_entry( ...$a ) { $GLOBALS['wpdb']->writes[] = 'timeline'; } }

/** The Demo Owner's plan 41 with tick 9; every write is recorded. */
class WritesWpdb extends ProductsWpdb {
    public $writes = array();
    public $insert_id = 0;
    public function get_row( $sql ) {
        if ( false !== strpos( $sql, 'hdlv2_flight_plan_ticks t' ) ) return (object) array( 'flight_plan_id' => 41, 'client_id' => 508 );
        return parent::get_row( $sql );
    }
    public function get_var( $sql ) {
        if ( false !== strpos( $sql, 'SELECT client_id FROM' ) && false !== strpos( $sql, 'hdlv2_flight_plans' ) ) return '508';
        return parent::get_var( $sql );
    }
    public function update( $table, $data, $where ) { $this->writes[] = 'update ' . $table; return 1; }
    public function insert( $table, $data ) { $this->writes[] = 'insert ' . $table; return 1; }
    public function query( $sql ) {
        if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)/i', $sql ) ) $this->writes[] = $sql;
        return 1;
    }
}

$P = dirname( __DIR__, 2 ) . '/includes/';
require_once $P . 'sprint-5/class-hdlv2-flight-plan.php';
require_once $P . 'sprint-4/class-hdlv2-checkin.php';

function practitioner_world() {
    global $wpdb;
    fresh();
    $wpdb = new WritesWpdb();
    client( 508, 122, 3 );
    $GLOBALS['owns'] = array( '122|508' );
    $GLOBALS['usermeta'][508]['hdlv2_challenge'] = json_encode( array( 'started_at' => '2026-10-28 08:00:00', 'source' => 'free', 'external_ref' => '', 'invite_id' => 0, 'practitioner_id' => 122 ) );
    $GLOBALS['journey_state'] = 'brand-new'; // the stub answers for any user: a practitioner has no client record
    return $wpdb;
}
function err_status( $r ) { return $r instanceof WP_Error ? ( $r->data['status'] ?? 0 ) . ' ' . $r->code : 'not an error'; }

$fp = HDLV2_Flight_Plan::get_instance();
$ci = HDLV2_Checkin::get_instance();
$va = new ReflectionMethod( $fp, 'validate_client_access' );
$va->setAccessible( true );

echo "W1 /flight-plan/tick and /tick-all\n";
$w = practitioner_world();
$GLOBALS['current_user'] = 130;
$r1 = $fp->rest_tick( new WP_REST_Request( array( 'tick_id' => 9, 'ticked' => true ) ) );
$r2 = $fp->rest_tick_all( new WP_REST_Request( array( 'flight_plan_id' => 41, 'ticked' => true ) ) );
check( 'W1.1 a practitioner who does not own the client: 403 forbidden on both, nothing written ("' . err_status( $r1 ) . '")',
    '403 forbidden' === err_status( $r1 ) && '403 forbidden' === err_status( $r2 ) && array() === $w->writes );
$w = practitioner_world();
$GLOBALS['current_user'] = 122;
check( 'W1.2 the owning practitioner passes the same check (validate_client_access :137-141, the right their Flight Plan tab on /clients/ uses)',
    true === $va->invoke( $fp, new WP_REST_Request( array() ), 508 ) );
$src = file_get_contents( $P . 'sprint-5/class-hdlv2-flight-plan.php' );
$tick = substr( $src, strpos( $src, 'public function rest_tick(' ), 1600 );
check( 'W1.3 in rest_tick the access check comes before the write', strpos( $tick, 'validate_client_access' ) < strpos( $tick, '$wpdb->update(' ) );

echo "W2 /challenge/tick\n";
$w = practitioner_world();
$GLOBALS['current_user'] = 122;
$before = $GLOBALS['usermeta'][508];
$r = HDLV2_Challenge::rest_tick( new WP_REST_Request( array( 'day' => 1, 'note' => 'x' ) ) );
check( 'W2.1 a practitioner caller: 403 not_enrolled ("' . err_status( $r ) . '"), nothing written, the client\'s enrolment untouched',
    '403 not_enrolled' === err_status( $r ) && array() === $w->writes && $before === $GLOBALS['usermeta'][508] && ! isset( $GLOBALS['usermeta'][122] ) );

echo "W3 /checkin/submit and /checkin/confirm\n";
$w = practitioner_world();
$GLOBALS['current_user'] = 122;
$s = $ci->rest_submit( new WP_REST_Request( array( 'response_text' => 'x' ) ) );
$c = $ci->rest_confirm( new WP_REST_Request( array( 'checkin_id' => 3 ) ) );
check( 'W3.1 without the client\'s token (the view never prints it): 401 invalid_token on both, nothing written',
    '401 invalid_token' === err_status( $s ) && '401 invalid_token' === err_status( $c ) && array() === $w->writes );

done();
