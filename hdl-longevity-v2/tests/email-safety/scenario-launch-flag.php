<?php
/**
 * Launch flag — scheduled CLIENT campaigns (v0.47.75).
 *
 * Contract: the v0.47.74 stack must deploy to LIVE SILENT. A launch flag
 * (option `hdlv2_ff_client_campaigns`, ABSENT = OFF) gates the outbound
 * email leg of the three scheduled crons that email a real CLIENT:
 *
 *   hdlv2_checkin_reminder    -> checkin_reminder
 *   hdlv2_quarterly_review    -> quarterly_review_client   (prac leg NOT gated)
 *   hdlv2_weekly_flight_plan  -> flight_plan_ready         (plan still generates)
 *
 * Send fires only when side-effects are allowed (is_live, or the staging
 * manual-test override) AND the flag is ON. Practitioner nudges to
 * Matthew's own inbox are NEVER flag-gated. Bookkeeping coupled to a send
 * that did not happen (cooldowns) must NOT be written, so flipping the
 * flag ON does not start life inside a suppression window.
 *
 * Real sources under test; spies for the template/mail layer.
 *
 * Run:  php scenario-launch-flag.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

// ── Env stubs — default to a LIVE identity (this flag's whole point is
//    LIVE behaviour); the flag itself lives in the options stub. ──
$GLOBALS['env_type']           = 'production';
$GLOBALS['home']               = 'https://healthdatalab.net';
$GLOBALS['allow_side_effects'] = false;
$GLOBALS['options']            = array();

function wp_get_environment_type() { return $GLOBALS['env_type']; }
function home_url( $p = '' ) { return $GLOBALS['home'] . $p; }
function site_url( $p = '' ) { return $GLOBALS['home'] . $p; }
function admin_url( $p = '' ) { return $GLOBALS['home'] . '/wp-admin/' . $p; }
function apply_filters( $tag, $value ) {
    if ( 'hdlv2_allow_staging_side_effects' === $tag && $GLOBALS['allow_side_effects'] ) return true;
    return $value;
}
function get_option( $k, $default = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $default; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['options'][ $k ] = $v; return true; }

$GLOBALS['transients'] = array();
$GLOBALS['user_meta']  = array();
$GLOBALS['mail']       = array();

function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function get_user_meta( $uid, $key, $single = false ) {
    if ( 'hdlv2_flight_plan_day' === $key ) return strtolower( date( 'l' ) ); // due today
    return $GLOBALS['user_meta'][ "$uid:$key" ] ?? '';
}
function update_user_meta( $uid, $key, $v ) { $GLOBALS['user_meta'][ "$uid:$key" ] = $v; return true; }
function delete_user_meta( $uid, $key ) { unset( $GLOBALS['user_meta'][ "$uid:$key" ] ); return true; }
function get_userdata( $id ) {
    return (object) array( 'ID' => $id, 'user_email' => 'office+matthew@healthdatalab.com', 'display_name' => 'Prac ' . $id );
}
function get_users( $args = array() ) { return array( 122 ); }
function wp_mail( $to, $subject, $body, $headers = array() ) {
    $GLOBALS['mail'][] = array( 'to' => $to, 'subject' => $subject );
    return true;
}
function current_time( $fmt ) { return gmdate( 'mysql' === $fmt ? 'Y-m-d H:i:s' : 'c' ); }
function wp_json_encode( $x ) { return json_encode( $x ); }
function is_wp_error( $x ) { return false; }
function add_action() {}
function add_shortcode() {}
function register_rest_route() {}
function rest_ensure_response( $x ) { return $x; }

// ── Spies ──
class HDLV2_Email_Templates {
    public static $calls = array();
    public static function __callStatic( $name, $args ) {
        self::$calls[] = array( 'template' => $name, 'args' => $args[0] ?? array() );
        return true;
    }
}
class HDLV2_Client_Status {
    public static $status = 'needs_attention';
    public static function calculate_status( $client_id ) {
        return array( 'status' => self::$status, 'reasons' => array( 'QA reason' ) );
    }
}
class HDLV2_Timeline {
    public static $entries = array();
    public static function add_entry( ...$args ) { self::$entries[] = $args; return 1; }
}
class HDLV2_Compatibility {
    public static function get_clients_for_practitioner( $prac_id ) { return array( 901 ); }
}
class HDLV2_Context_Builder {
    public static function build_context( $client_id, $tier = 2 ) { return array( 'engagement_signal' => 'medium' ); }
}

class FakeWpdb {
    public $prefix = 'wp_';
    public function prepare( $sql, ...$args ) {
        foreach ( $args as $a ) {
            $sql = preg_replace( '/%d/', (string) (int) $a, $sql, 1 );
            $sql = preg_replace( '/%s/', "'" . $a . "'", $sql, 1 );
        }
        return $sql;
    }
    public function get_results( $sql ) {
        if ( false !== strpos( $sql, "client_email != ''" ) ) {
            return array( (object) array( 'client_user_id' => 901, 'client_email' => 'amanda.fixture@gmail.com', 'client_name' => 'Amanda F', 'practitioner_user_id' => 122, 'token' => str_repeat( 'a', 64 ) ) );
        }
        if ( false !== strpos( $sql, 'current_stage = 2' ) ) {
            return array( (object) array( 'client_user_id' => 903, 'progress_id' => 77, 'client_name' => 'Stuck C', 'client_email' => 'stuck.fixture@gmail.com', 'practitioner_user_id' => 122, 'stage2_completed_at' => gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ) ) );
        }
        if ( false !== strpos( $sql, 'last_assessment' ) ) {
            return array( (object) array( 'client_user_id' => 904, 'client_name' => 'Quarterly C', 'client_email' => 'quarterly.fixture@gmail.com', 'practitioner_user_id' => 122, 'token' => str_repeat( 'c', 64 ), 'created_at' => '2026-01-01 00:00:00', 'last_assessment' => '2026-03-01 00:00:00' ) );
        }
        if ( false !== strpos( $sql, 'fp.stage3_completed_at IS NOT NULL' ) ) {
            return array( (object) array( 'client_user_id' => 906, 'practitioner_user_id' => 122 ) ); // flight-plan cron
        }
        if ( false !== strpos( $sql, 'practitioner_user_id IS NOT NULL' ) ) {
            return array( (object) array( 'client_user_id' => 905, 'client_name' => 'Inactive C', 'client_email' => 'inactive.fixture@gmail.com', 'practitioner_user_id' => 122, 'token' => str_repeat( 'd', 64 ) ) );
        }
        return array();
    }
    public function get_var( $sql ) { return null; }
}

require __DIR__ . '/../../includes/class-hdlv2-env.php';
require __DIR__ . '/../../includes/sprint-4/class-hdlv2-checkin.php';
require __DIR__ . '/../../includes/sprint-4/class-hdlv2-attention-cron.php';
require __DIR__ . '/../../includes/sprint-5/class-hdlv2-flight-plan.php';

class SpyFlightPlan extends HDLV2_Flight_Plan {
    public $gen_calls = array();
    public function generate( $client_id, $practitioner_id, $trigger = 'auto', $send_email = true, $week_start = null, $force = false, $prebuilt_context = null ) {
        $this->gen_calls[] = array( 'client' => (int) $client_id, 'send_email' => $send_email );
        return 1;
    }
}

$pass = 0; $fail = 0;
function check( $label, $ok ) {
    global $pass, $fail;
    echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n";
    $ok ? $pass++ : $fail++;
}
function reset_spies() {
    HDLV2_Email_Templates::$calls = array();
    HDLV2_Timeline::$entries      = array();
    $GLOBALS['mail']              = array();
    $GLOBALS['transients']        = array();
    $GLOBALS['user_meta']         = array();
}
function templates_sent() {
    return array_map( function ( $c ) { return $c['template']; }, HDLV2_Email_Templates::$calls );
}

$GLOBALS['wpdb'] = new FakeWpdb();
$checkin = HDLV2_Checkin::get_instance();
$attn    = HDLV2_Attention_Cron::get_instance();
$fp      = ( new ReflectionClass( 'SpyFlightPlan' ) )->newInstanceWithoutConstructor();

$log  = tempnam( sys_get_temp_dir(), 'hdlv2-flag-log' );
$prev = ini_set( 'error_log', $log );

// ════ the flag itself ════
unset( $GLOBALS['options']['hdlv2_ff_client_campaigns'] );
check( 'ABSENT option → campaigns OFF (safe default)', false === HDLV2_Env::client_campaigns_enabled() );
update_option( 'hdlv2_ff_client_campaigns', '1' );
check( "option '1' → campaigns ON", true === HDLV2_Env::client_campaigns_enabled() );
update_option( 'hdlv2_ff_client_campaigns', '0' );
check( "option '0' → campaigns OFF", false === HDLV2_Env::client_campaigns_enabled() );
update_option( 'hdlv2_ff_client_campaigns', 1 );
check( 'option int 1 → campaigns ON', true === HDLV2_Env::client_campaigns_enabled() );

// ════ client_campaign_gate() = side-effects allowed AND flag on ════
$GLOBALS['env_type'] = 'production'; $GLOBALS['home'] = 'https://healthdatalab.net';
update_option( 'hdlv2_ff_client_campaigns', 1 );
check( 'LIVE + flag ON → campaign gate OPEN', true === HDLV2_Env::client_campaign_gate( 'unit ctx' ) );
update_option( 'hdlv2_ff_client_campaigns', 0 );
check( 'LIVE + flag OFF → campaign gate CLOSED', false === HDLV2_Env::client_campaign_gate( 'unit ctx' ) );
file_put_contents( $log, '' );
HDLV2_Env::client_campaign_gate( 'unit-ctx-flagoff client:7' );
$logged = (string) @file_get_contents( $log );
check( 'flag-OFF suppression is logged with its context', false !== strpos( $logged, 'unit-ctx-flagoff client:7' ) );

$GLOBALS['env_type'] = 'staging'; $GLOBALS['home'] = 'https://stby.healthdatalab.net';
update_option( 'hdlv2_ff_client_campaigns', 1 );
check( 'STBY + flag ON but no override → still CLOSED (env wins)', false === HDLV2_Env::client_campaign_gate( 'unit ctx' ) );
$GLOBALS['allow_side_effects'] = true;
check( 'STBY + override + flag ON → OPEN (testable on staging)', true === HDLV2_Env::client_campaign_gate( 'unit ctx' ) );
update_option( 'hdlv2_ff_client_campaigns', 0 );
check( 'STBY + override + flag OFF → CLOSED', false === HDLV2_Env::client_campaign_gate( 'unit ctx' ) );
$GLOBALS['allow_side_effects'] = false;

// ════ LIVE + flag OFF = the post-deploy state → ZERO client email ════
$GLOBALS['env_type'] = 'production'; $GLOBALS['home'] = 'https://healthdatalab.net';
update_option( 'hdlv2_ff_client_campaigns', 0 );

reset_spies();
$checkin->send_reminders();
check( 'LIVE flag OFF — check-in reminder: ZERO client email', array() === templates_sent() );
check( 'LIVE flag OFF — check-in reminder: cooldown NOT set (no false suppression window on flip)', ! isset( $GLOBALS['transients']['hdlv2_checkin_remind_901'] ) );

reset_spies();
$checkin->check_quarterly_reviews();
check( 'LIVE flag OFF — quarterly: client email SUPPRESSED', ! in_array( 'quarterly_review_client', templates_sent(), true ) );
check( 'LIVE flag OFF — quarterly: PRACTITIONER nudge still sent', in_array( 'quarterly_review_due', templates_sent(), true ) );

reset_spies();
$fp->gen_calls = array();
$fp->cron_generate_all();
check( 'LIVE flag OFF — flight plan: plan STILL generated', 1 === count( $fp->gen_calls ) );
check( 'LIVE flag OFF — flight plan: generated with send_email=false', false === ( $fp->gen_calls[0]['send_email'] ?? null ) );

// practitioner-only crons must be untouched by this flag
reset_spies();
$checkin->run_inactivity_sweep();
check( 'LIVE flag OFF — inactivity sweep: practitioner email UNAFFECTED', array( 'client_needs_attention' ) === templates_sent() );
reset_spies();
$checkin->run_stuck_release_reminder();
check( 'LIVE flag OFF — stuck-release: practitioner email UNAFFECTED', array( 'client_needs_attention' ) === templates_sent() );
reset_spies();
$sent = $attn->process_practitioner( 122 );
check( 'LIVE flag OFF — attention digest: practitioner email UNAFFECTED', true === $sent && 1 === count( $GLOBALS['mail'] ) );

// ════ flip ON → campaigns activate ════
update_option( 'hdlv2_ff_client_campaigns', 1 );

reset_spies();
$checkin->send_reminders();
check( 'LIVE flag ON — check-in reminder SENDS to the client', array( 'checkin_reminder' ) === templates_sent() );
check( 'LIVE flag ON — check-in reminder sets its 3-day cooldown', isset( $GLOBALS['transients']['hdlv2_checkin_remind_901'] ) );
check( 'LIVE flag ON — check-in reminder addressed to the client email', 'amanda.fixture@gmail.com' === ( HDLV2_Email_Templates::$calls[0]['args']['client_email'] ?? '' ) );

reset_spies();
$checkin->check_quarterly_reviews();
check( 'LIVE flag ON — quarterly sends BOTH prac + client', array( 'quarterly_review_due', 'quarterly_review_client' ) === templates_sent() );

reset_spies();
$fp->gen_calls = array();
$fp->cron_generate_all();
check( 'LIVE flag ON — flight plan generated with send_email=true', true === ( $fp->gen_calls[0]['send_email'] ?? null ) );

// ════ flag ON but NOT live → still nothing (email-safety braces hold) ════
$GLOBALS['env_type'] = 'staging'; $GLOBALS['home'] = 'https://stby.healthdatalab.net';
reset_spies();
$checkin->send_reminders();
check( 'STBY flag ON (no override) — still ZERO client email (0.47.74 gate holds)', array() === templates_sent() );

ini_set( 'error_log', $prev );
@unlink( $log );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
