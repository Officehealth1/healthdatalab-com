<?php
/**
 * Cron side-effect gate — Stage-2 extraction retry (Make re-fire + local
 * Claude rescue).
 *
 * Contract: on a non-live box run_stage2_extraction_retry() still selects
 * its stuck candidates, but BOTH outbound legs — the Make.com webhook
 * re-fire and the local Claude extraction — are SKIPPED, and the attempt
 * counters stay untouched (a later manual run starts from a clean ladder).
 * Override restores the exact v0.47.59 Option-B behaviour proven in
 * tests/stage2-retry/. Real HDLV2_Staged_Form source; stub set cloned
 * from that suite.
 *
 * Run:  php scenario-gates-stage2retry.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HDLV2_MAKE_STAGE2_WHY', 'https://hook.example.test/stage2-why' );
define( 'HDLV2_MAKE_CALLBACK_SECRET', 'test-callback-secret' );

$GLOBALS['env_type']           = 'staging';
$GLOBALS['home']               = 'https://stby.healthdatalab.net';
$GLOBALS['allow_side_effects'] = false;

// ── WP stubs (cloned from tests/stage2-retry, env-aware apply_filters) ──
$GLOBALS['transients'] = array();
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function wp_get_environment_type() { return $GLOBALS['env_type']; }
function apply_filters( $tag, $value ) {
    if ( 'hdlv2_allow_staging_side_effects' === $tag && $GLOBALS['allow_side_effects'] ) return true;
    return $value;
}
function home_url( $path = '' ) { return $GLOBALS['home'] . $path; }
function rest_url( $path = '' ) { return $GLOBALS['home'] . '/wp-json/' . ltrim( $path, '/' ); }
function get_userdata( $id ) { return false; }
function current_time( $fmt ) { return 'mysql' === $fmt ? gmdate( 'Y-m-d H:i:s' ) : gmdate( 'c' ); }
function wp_json_encode( $x ) { return json_encode( $x ); }
function sanitize_textarea_field( $x ) { return (string) $x; }
function wp_kses_post( $x ) { return (string) $x; }
function add_action() {}
function add_shortcode() {}

class HDLV2_AI_Service {
    public static $calls = array();
    public static function extract_why( $stage2_data ) {
        self::$calls[] = $stage2_data;
        return array( 'key_people' => array( 'QA' ), 'motivations' => array( 'QA' ), 'fears' => array(), 'distilled_why' => 'QA why', 'ai_reformulation' => 'QA' );
    }
}
class HDLV2_Practitioner {
    public static function get_logo_url( $id, $fallback = false ) { return 'https://stby.example.test/logo.png'; }
}
class HDLV2_Webhook_Monitor {
    public static $fires = array();
    public static function fire( $url, $args, $tag = '' ) {
        self::$fires[] = array( 'url' => $url, 'tag' => $tag );
        return true;
    }
}

class FakeWpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $rows = array();
    public $why_inserts = array();
    public $updates = array();
    public function prepare( $sql, ...$args ) {
        foreach ( $args as $a ) {
            $sql = preg_replace( '/%d/', (string) (int) $a, $sql, 1 );
            $sql = preg_replace( '/%s/', "'" . $a . "'", $sql, 1 );
        }
        return $sql;
    }
    public function get_results( $sql ) {
        $out = array();
        foreach ( $this->rows as $r ) {
            if ( $this->why_exists( $r->id ) ) continue;
            $out[] = (object) array(
                'id'               => $r->id,
                'token'            => $r->token,
                'client_user_id'   => $r->client_user_id,
                'stage2_data'      => $r->stage2_data,
                'client_name'      => $r->client_name,
                'token_expires_at' => $r->token_expires_at,
            );
        }
        usort( $out, function ( $a, $b ) { return $b->id <=> $a->id; } );
        return $out;
    }
    public function get_row( $sql ) {
        if ( preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) return $this->rows[ (int) $m[1] ] ?? null;
        return null;
    }
    public function get_var( $sql ) {
        if ( false !== strpos( $sql, 'GET_LOCK' ) ) return 1;
        if ( preg_match( '/FROM wp_hdlv2_why_profiles WHERE form_progress_id = (\d+)/', $sql, $m ) ) {
            return $this->why_exists( (int) $m[1] ) ? 1 : null;
        }
        return null;
    }
    public function query( $sql ) { return 1; }
    public function insert( $table, $data, $formats = null ) {
        if ( false !== strpos( $table, 'why_profiles' ) ) { $this->why_inserts[] = $data; }
        return 1;
    }
    public function update( $table, $data, $where ) { $this->updates[] = $where; return 1; }
    private function why_exists( $fp_id ) {
        foreach ( $this->why_inserts as $ins ) {
            if ( (int) $ins['form_progress_id'] === (int) $fp_id ) return true;
        }
        return false;
    }
}

function make_row( $id, $token_expires_at ) {
    return (object) array(
        'id'                      => $id,
        'token'                   => str_repeat( dechex( $id % 16 ), 64 ),
        'client_user_id'          => 900 + $id,
        'practitioner_user_id'    => 0,
        'client_name'             => 'QA Row ' . $id,
        'client_email'            => 'qa' . $id . '@example.test',
        'stage1_data'             => '{}',
        'stage2_data'             => json_encode( array( 'vision_text' => 'A long enough vision text for row ' . $id ) ),
        'stage2_webhook_fired_at' => '2020-01-01 00:00:00',
        'deleted_at'              => null,
        'token_expires_at'        => $token_expires_at,
    );
}

require __DIR__ . '/../../includes/class-hdlv2-env.php';
require __DIR__ . '/../../includes/sprint-2/class-hdlv2-staged-form.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) {
    global $pass, $fail;
    echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n";
    $ok ? $pass++ : $fail++;
}

$future = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
$past   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

// ── GATED: valid row would re-fire Make, expired row would burn Claude — both must skip ──
$wpdb = new FakeWpdb();
$wpdb->rows = array( 101 => make_row( 101, $future ), 103 => make_row( 103, $past ) );
$GLOBALS['wpdb'] = $wpdb;
$GLOBALS['allow_side_effects'] = false;

HDLV2_Staged_Form::run_stage2_extraction_retry();
check( 'GATED: ZERO Make re-fires', 0 === count( HDLV2_Webhook_Monitor::$fires ) );
check( 'GATED: ZERO local Claude extractions', 0 === count( HDLV2_AI_Service::$calls ) );
check( 'GATED: attempt counters untouched', ! isset( $GLOBALS['transients']['hdlv2_stage2_retry_101'] ) && ! isset( $GLOBALS['transients']['hdlv2_stage2_retry_103'] ) );
check( 'GATED: no why rows written', 0 === count( $wpdb->why_inserts ) );

// ── OVERRIDE: v0.47.59 Option-B behaviour intact ──
$GLOBALS['allow_side_effects'] = true;
HDLV2_Staged_Form::run_stage2_extraction_retry();
check( 'OVERRIDE: valid row re-fires Make exactly once', 1 === count( HDLV2_Webhook_Monitor::$fires ) );
check( 'OVERRIDE: expired-alive row gets local Claude rescue', 1 === count( HDLV2_AI_Service::$calls ) && 1 === count( $wpdb->why_inserts ) );
check( 'OVERRIDE: attempt counters advanced', 1 === ( $GLOBALS['transients']['hdlv2_stage2_retry_101'] ?? 0 ) && 1 === ( $GLOBALS['transients']['hdlv2_stage2_retry_103'] ?? 0 ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
