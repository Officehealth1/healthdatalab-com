<?php
/**
 * Cron side-effect gate — monthly-summary Anthropic burn.
 *
 * Contract: on a non-live box generate_monthly_summaries() still finds
 * summarisable clients and assembles their check-in window, but the
 * Anthropic call (and the summary row it would insert) is SKIPPED.
 * Override restores the full path. Real HDLV2_Context_Builder source.
 *
 * Run:  php scenario-gates-monthly.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HDLV2_ANTHROPIC_API_KEY', 'test-key-not-real' );

$GLOBALS['env_type']           = 'staging';
$GLOBALS['home']               = 'https://stby.healthdatalab.net';
$GLOBALS['allow_side_effects'] = false;
$GLOBALS['http_posts']         = array();

function wp_get_environment_type() { return $GLOBALS['env_type']; }
function home_url( $p = '' ) { return $GLOBALS['home'] . $p; }
function apply_filters( $tag, $value ) {
    if ( 'hdlv2_allow_staging_side_effects' === $tag && $GLOBALS['allow_side_effects'] ) return true;
    return $value;
}
function add_action() {}
function wp_json_encode( $x ) { return json_encode( $x ); }
function wp_remote_post( $url, $args = array() ) {
    $GLOBALS['http_posts'][] = $url;
    return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'content' => array( array( 'text' => 'QA summary' ) ) ) ) );
}
function is_wp_error( $x ) { return false; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

class FakeWpdb {
    public $prefix = 'wp_';
    public $inserts = array();
    public function prepare( $sql, ...$args ) {
        foreach ( $args as $a ) {
            $sql = preg_replace( '/%d/', (string) (int) $a, $sql, 1 );
            $sql = preg_replace( '/%s/', "'" . $a . "'", $sql, 1 );
        }
        return $sql;
    }
    public function get_col( $sql ) { return array( 901 ); }
    public function get_var( $sql ) {
        if ( false !== strpos( $sql, 'practitioner_id' ) ) return 122;
        return null; // no prior summary
    }
    public function get_results( $sql ) {
        $rows = array();
        for ( $i = 1; $i <= 4; $i++ ) {
            $rows[] = (object) array(
                'week_start'       => "2026-06-0$i",
                'summary'          => "Week $i summary",
                'adherence_scores' => '{}',
                'comfort_zone'     => 'stretch',
            );
        }
        return $rows;
    }
    public function insert( $table, $data, $formats = null ) {
        $this->inserts[] = array( 'table' => $table, 'data' => $data );
        return 1;
    }
}

require __DIR__ . '/../../includes/class-hdlv2-env.php';
require __DIR__ . '/../../includes/class-hdlv2-context-builder.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) {
    global $pass, $fail;
    echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n";
    $ok ? $pass++ : $fail++;
}

$GLOBALS['wpdb'] = new FakeWpdb();
$cb = HDLV2_Context_Builder::get_instance();

// ── GATED ──
$GLOBALS['allow_side_effects'] = false;
$cb->generate_monthly_summaries();
check( 'GATED: ZERO Anthropic calls', 0 === count( $GLOBALS['http_posts'] ) );
check( 'GATED: ZERO summary rows inserted', 0 === count( $GLOBALS['wpdb']->inserts ) );

// ── OVERRIDE ──
$GLOBALS['allow_side_effects'] = true;
$cb->generate_monthly_summaries();
check( 'OVERRIDE: Anthropic called once', array( 'https://api.anthropic.com/v1/messages' ) === $GLOBALS['http_posts'] );
check( 'OVERRIDE: summary row inserted', 1 === count( $GLOBALS['wpdb']->inserts ) && 'QA summary' === $GLOBALS['wpdb']->inserts[0]['data']['summary'] );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
