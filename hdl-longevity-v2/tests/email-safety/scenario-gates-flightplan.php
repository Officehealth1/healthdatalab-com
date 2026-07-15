<?php
/**
 * Cron side-effect gate — weekly flight-plan generation dispatch.
 *
 * Contract: on a non-live box cron_generate_all() still selects
 * candidates and evaluates the zero-engagement gate, but the generate()
 * dispatch (Claude call + Make PDF + client email) is SKIPPED. With the
 * manual-test override on, generate() dispatches exactly as before.
 * Real HDLV2_Flight_Plan source; generate() itself spied via subclass
 * (its Claude/Make/email internals have their own coverage).
 *
 * Run:  php scenario-gates-flightplan.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['env_type']           = 'staging';
$GLOBALS['home']               = 'https://stby.healthdatalab.net';
$GLOBALS['allow_side_effects'] = false;

function wp_get_environment_type() { return $GLOBALS['env_type']; }
function home_url( $p = '' ) { return $GLOBALS['home'] . $p; }
function site_url( $p = '' ) { return $GLOBALS['home'] . $p; }
function apply_filters( $tag, $value ) {
    if ( 'hdlv2_allow_staging_side_effects' === $tag && $GLOBALS['allow_side_effects'] ) return true;
    return $value;
}
function add_action() {}
function add_shortcode() {}
function register_rest_route() {}
function rest_ensure_response( $x ) { return $x; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $ttl = 0 ) { return true; }
// Generation day defaults to Saturday; return today so the candidate is due.
function get_user_meta( $uid, $key, $single = false ) {
    return 'hdlv2_flight_plan_day' === $key ? strtolower( date( 'l' ) ) : '';
}
function update_user_meta( $uid, $key, $v ) { return true; }
function get_userdata( $id ) { return (object) array( 'ID' => $id, 'user_email' => 'office+matthew@healthdatalab.com', 'display_name' => 'P' ); }
function current_time( $fmt ) { return gmdate( 'mysql' === $fmt ? 'Y-m-d H:i:s' : 'c' ); }
function wp_json_encode( $x ) { return json_encode( $x ); }
function is_wp_error( $x ) { return false; }

class HDLV2_Context_Builder {
    public static $builds = array();
    public static function build_context( $client_id, $tier = 2 ) {
        self::$builds[] = (int) $client_id;
        return array( 'engagement_signal' => 'medium' );
    }
}

class FakeWpdb {
    public $prefix = 'wp_';
    public $queries = array();
    public function prepare( $sql, ...$args ) {
        foreach ( $args as $a ) {
            $sql = preg_replace( '/%d/', (string) (int) $a, $sql, 1 );
            $sql = preg_replace( '/%s/', "'" . $a . "'", $sql, 1 );
        }
        return $sql;
    }
    public function get_results( $sql ) {
        $this->queries[] = $sql;
        return array( (object) array( 'client_user_id' => 901, 'practitioner_user_id' => 122 ) );
    }
    public function get_var( $sql ) { $this->queries[] = $sql; return null; } // no existing plan
}

require __DIR__ . '/../../includes/class-hdlv2-env.php';
require __DIR__ . '/../../includes/sprint-5/class-hdlv2-flight-plan.php';

class SpyFlightPlan extends HDLV2_Flight_Plan {
    public $gen_calls = array();
    public function generate( $client_id, $practitioner_id, $trigger = 'auto', $send_email = true, $week_start = null, $force = false, $prebuilt_context = null ) {
        $this->gen_calls[] = array( 'client' => (int) $client_id, 'trigger' => $trigger );
        return array( 'plan_id' => 1 );
    }
}

$pass = 0; $fail = 0;
function check( $label, $ok ) {
    global $pass, $fail;
    echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n";
    $ok ? $pass++ : $fail++;
}

$GLOBALS['wpdb'] = new FakeWpdb();
$fp = ( new ReflectionClass( 'SpyFlightPlan' ) )->newInstanceWithoutConstructor();

$log  = tempnam( sys_get_temp_dir(), 'hdlv2-fp-log' );
$prev = ini_set( 'error_log', $log );

// ── GATED ──
$GLOBALS['allow_side_effects'] = false;
$fp->cron_generate_all();
check( 'GATED: candidate query + engagement gate still ran', count( $GLOBALS['wpdb']->queries ) >= 2 && in_array( 901, HDLV2_Context_Builder::$builds, true ) );
check( 'GATED: generate() NOT dispatched (no Claude/Make/email)', 0 === count( $fp->gen_calls ) );
check( 'GATED: skip logged [HDLV2-ENV]', false !== strpos( (string) @file_get_contents( $log ), '[HDLV2-ENV]' ) );

// ── OVERRIDE ──
$GLOBALS['allow_side_effects'] = true;
HDLV2_Context_Builder::$builds = array();
$GLOBALS['wpdb']->queries = array();
$fp->cron_generate_all();
check( 'OVERRIDE: generate() dispatched for the candidate', array( array( 'client' => 901, 'trigger' => 'auto' ) ) === $fp->gen_calls );

ini_set( 'error_log', $prev );
@unlink( $log );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
