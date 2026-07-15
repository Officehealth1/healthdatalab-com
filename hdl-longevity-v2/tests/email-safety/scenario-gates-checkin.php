<?php
/**
 * Cron side-effect gates — check-in family + attention digest.
 *
 * Contract (the BRACES): on a non-live box the FIVE recurring handlers
 * (send_reminders, run_inactivity_sweep, run_stuck_release_reminder,
 * check_quarterly_reviews, attention digest) still run their candidate
 * evaluation — queries fire, statuses are computed — but the outbound
 * leg (email + its coupled bookkeeping: cooldown transients, last-sent
 * meta, quarterly timeline milestone) is SKIPPED. With the manual-test
 * override filter on, the same call sends exactly as before. Real
 * sources under test, spies for the template/mail layer.
 *
 * Run:  php scenario-gates-checkin.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

// ── Env stubs — staging identity, override switchable ──
$GLOBALS['env_type']           = 'staging';
$GLOBALS['home']               = 'https://stby.healthdatalab.net';
$GLOBALS['allow_side_effects'] = false;

function wp_get_environment_type() { return $GLOBALS['env_type']; }
function home_url( $p = '' ) { return $GLOBALS['home'] . $p; }
function site_url( $p = '' ) { return $GLOBALS['home'] . $p; }
function admin_url( $p = '' ) { return $GLOBALS['home'] . '/wp-admin/' . $p; }
function apply_filters( $tag, $value ) {
    if ( 'hdlv2_allow_staging_side_effects' === $tag && $GLOBALS['allow_side_effects'] ) return true;
    return $value;
}

// ── WP stubs ──
$GLOBALS['transients'] = array();
$GLOBALS['user_meta']  = array();
$GLOBALS['mail']       = array();

function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['user_meta'][ "$uid:$key" ] ?? ''; }
function update_user_meta( $uid, $key, $v ) { $GLOBALS['user_meta'][ "$uid:$key" ] = $v; return true; }
function get_userdata( $id ) {
    return (object) array( 'ID' => $id, 'user_email' => 'office+matthew@healthdatalab.com', 'display_name' => 'Prac ' . $id );
}
function get_users( $args = array() ) { return array( 122 ); }
function wp_mail( $to, $subject, $body, $headers = array() ) {
    $GLOBALS['mail'][] = array( 'to' => $to, 'subject' => $subject );
    return true;
}
function current_time( $fmt ) { return gmdate( 'mysql' === $fmt ? 'Y-m-d H:i:s' : 'c' ); }
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
    public static $checked = array();
    public static $status  = 'needs_attention';
    public static function calculate_status( $client_id ) {
        self::$checked[] = (int) $client_id;
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

// ── Fake wpdb — routes on distinctive SQL substrings ──
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
        if ( false !== strpos( $sql, "client_email != ''" ) ) {
            // send_reminders candidates — real-shaped addresses.
            return array(
                (object) array( 'client_user_id' => 901, 'client_email' => 'amanda.fixture@gmail.com', 'client_name' => 'Amanda F', 'practitioner_user_id' => 122, 'token' => str_repeat( 'a', 64 ) ),
                (object) array( 'client_user_id' => 902, 'client_email' => 'lucas.fixture@gmail.com', 'client_name' => 'Lucas F', 'practitioner_user_id' => 122, 'token' => str_repeat( 'b', 64 ) ),
            );
        }
        if ( false !== strpos( $sql, 'current_stage = 2' ) ) {
            // run_stuck_release_reminder candidates.
            return array(
                (object) array( 'client_user_id' => 903, 'progress_id' => 77, 'client_name' => 'Stuck C', 'client_email' => 'stuck.fixture@gmail.com', 'practitioner_user_id' => 122, 'stage2_completed_at' => gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ) ),
            );
        }
        if ( false !== strpos( $sql, 'last_assessment' ) ) {
            // check_quarterly_reviews candidates.
            return array(
                (object) array( 'client_user_id' => 904, 'client_name' => 'Quarterly C', 'client_email' => 'quarterly.fixture@gmail.com', 'practitioner_user_id' => 122, 'token' => str_repeat( 'c', 64 ), 'created_at' => '2026-01-01 00:00:00', 'last_assessment' => '2026-03-01 00:00:00' ),
            );
        }
        if ( false !== strpos( $sql, 'practitioner_user_id IS NOT NULL' ) ) {
            // run_inactivity_sweep candidates.
            return array(
                (object) array( 'client_user_id' => 905, 'client_name' => 'Inactive C', 'client_email' => 'inactive.fixture@gmail.com', 'practitioner_user_id' => 122, 'token' => str_repeat( 'd', 64 ) ),
            );
        }
        return array();
    }
    public function get_var( $sql ) {
        $this->queries[] = $sql;
        return null; // no existing check-in this week / no recent quarterly milestone
    }
}

// ── Load real sources ──
require __DIR__ . '/../../includes/class-hdlv2-env.php';
require __DIR__ . '/../../includes/sprint-4/class-hdlv2-checkin.php';
require __DIR__ . '/../../includes/sprint-4/class-hdlv2-attention-cron.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) {
    global $pass, $fail;
    echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n";
    $ok ? $pass++ : $fail++;
}
function reset_spies() {
    HDLV2_Email_Templates::$calls  = array();
    HDLV2_Client_Status::$checked  = array();
    HDLV2_Timeline::$entries       = array();
    $GLOBALS['mail']               = array();
    $GLOBALS['transients']         = array();
    $GLOBALS['user_meta']          = array();
    $GLOBALS['wpdb']->queries      = array();
}

$GLOBALS['wpdb'] = new FakeWpdb();
$wpdb    = $GLOBALS['wpdb'];
$checkin = HDLV2_Checkin::get_instance();
$attn    = HDLV2_Attention_Cron::get_instance();

$log  = tempnam( sys_get_temp_dir(), 'hdlv2-gates-log' );
$prev = ini_set( 'error_log', $log );

// ════ GATED MODE (staging, no override) ════
$GLOBALS['allow_side_effects'] = false;

reset_spies();
$checkin->send_reminders();
check( 'GATED send_reminders: candidates evaluated (query ran)', count( $wpdb->queries ) >= 1 );
check( 'GATED send_reminders: ZERO reminder emails', 0 === count( HDLV2_Email_Templates::$calls ) );
check( 'GATED send_reminders: cooldown transients NOT set', array() === $GLOBALS['transients'] );

reset_spies();
$checkin->run_inactivity_sweep();
check( 'GATED inactivity_sweep: statuses still computed (body ran)', in_array( 905, HDLV2_Client_Status::$checked, true ) );
check( 'GATED inactivity_sweep: ZERO practitioner emails', 0 === count( HDLV2_Email_Templates::$calls ) );
check( 'GATED inactivity_sweep: 7-day dedupe transient NOT set', array() === $GLOBALS['transients'] );

reset_spies();
$checkin->run_stuck_release_reminder();
check( 'GATED stuck_release: ZERO practitioner emails', 0 === count( HDLV2_Email_Templates::$calls ) );
check( 'GATED stuck_release: throttle transient NOT set', array() === $GLOBALS['transients'] );

reset_spies();
$checkin->check_quarterly_reviews();
check( 'GATED quarterly: ZERO emails (client AND practitioner)', 0 === count( HDLV2_Email_Templates::$calls ) );
check( 'GATED quarterly: timeline milestone NOT written', 0 === count( HDLV2_Timeline::$entries ) );

reset_spies();
$sent = $attn->process_practitioner( 122 );
check( 'GATED attention digest: status still computed (body ran)', in_array( 901, HDLV2_Client_Status::$checked, true ) );
check( 'GATED attention digest: wp_mail NOT called, returns false', false === $sent && 0 === count( $GLOBALS['mail'] ) );
check( 'GATED attention digest: last-sent meta NOT stamped', ! isset( $GLOBALS['user_meta']['122:hdlv2_attention_last_sent'] ) );

$gate_log = (string) @file_get_contents( $log );
check( 'GATED runs logged [HDLV2-ENV] skips', substr_count( $gate_log, '[HDLV2-ENV]' ) >= 5 );

// ════ OVERRIDE MODE (manual-test path) ════
$GLOBALS['allow_side_effects'] = true;

reset_spies();
$checkin->send_reminders();
check( 'OVERRIDE send_reminders: both candidates emailed', 2 === count( HDLV2_Email_Templates::$calls ) && 'checkin_reminder' === HDLV2_Email_Templates::$calls[0]['template'] );
check( 'OVERRIDE send_reminders: cooldown transients set', isset( $GLOBALS['transients']['hdlv2_checkin_remind_901'], $GLOBALS['transients']['hdlv2_checkin_remind_902'] ) );

reset_spies();
$checkin->run_inactivity_sweep();
check( 'OVERRIDE inactivity_sweep: practitioner emailed + dedupe set', 1 === count( HDLV2_Email_Templates::$calls ) && isset( $GLOBALS['transients']['hdlv2_attn_905_needs_attention'] ) );

reset_spies();
$checkin->run_stuck_release_reminder();
check( 'OVERRIDE stuck_release: practitioner emailed + throttle set', 1 === count( HDLV2_Email_Templates::$calls ) && isset( $GLOBALS['transients']['hdlv2_stuck_release_77'] ) );

reset_spies();
$checkin->check_quarterly_reviews();
$templates = array_map( function ( $c ) { return $c['template']; }, HDLV2_Email_Templates::$calls );
check( 'OVERRIDE quarterly: prac + client emails + milestone written', array( 'quarterly_review_due', 'quarterly_review_client' ) === $templates && 1 === count( HDLV2_Timeline::$entries ) );

reset_spies();
$sent = $attn->process_practitioner( 122 );
check( 'OVERRIDE attention digest: wp_mail sent + meta stamped', true === $sent && 1 === count( $GLOBALS['mail'] ) && isset( $GLOBALS['user_meta']['122:hdlv2_attention_last_sent'] ) );

ini_set( 'error_log', $prev );
@unlink( $log );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
