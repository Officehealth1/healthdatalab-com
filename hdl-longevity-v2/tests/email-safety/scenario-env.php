<?php
/**
 * HDLV2_Env tests — server-identity discriminator + side-effect gate.
 *
 * Contract under test (drives the STBY email-safety braces):
 *  - is_live() true ONLY for env=production AND host healthdatalab.net
 *    (or www.). A fresh LIVE re-clone that loses WP_ENVIRONMENT_TYPE
 *    reads env=production but host=stby.healthdatalab.net → NOT live
 *    (fail-CLOSED, the belt-and-braces requirement).
 *  - side_effects_allowed(): live → always true; non-live → false unless
 *    the manual-test override (filter hdlv2_allow_staging_side_effects
 *    or constant HDLV2_STAGING_SIDE_EFFECTS) is on.
 *  - gate($context): allowed → true, silent; gated → false + one
 *    "[HDLV2-ENV]" error_log line carrying $context.
 *
 * Run:  php scenario-env.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

define( 'ABSPATH', __DIR__ . '/' );

// ── WP stubs — env switchable per case ──
$GLOBALS['env_type']           = 'staging';
$GLOBALS['home']               = 'https://stby.healthdatalab.net';
$GLOBALS['allow_side_effects'] = false;

function wp_get_environment_type() { return $GLOBALS['env_type']; }
function home_url( $p = '' ) { return $GLOBALS['home'] . $p; }
function apply_filters( $tag, $value ) {
    if ( 'hdlv2_allow_staging_side_effects' === $tag && $GLOBALS['allow_side_effects'] ) {
        return true;
    }
    return $value;
}

// ── Load the real class ──
require __DIR__ . '/../../includes/class-hdlv2-env.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) {
    global $pass, $fail;
    echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n";
    $ok ? $pass++ : $fail++;
}

// ── is_live() truth table ──
$GLOBALS['env_type'] = 'production'; $GLOBALS['home'] = 'https://healthdatalab.net';
check( 'LIVE: production + healthdatalab.net → is_live TRUE', true === HDLV2_Env::is_live() );

$GLOBALS['home'] = 'https://www.healthdatalab.net';
check( 'LIVE: production + www.healthdatalab.net → is_live TRUE', true === HDLV2_Env::is_live() );

$GLOBALS['env_type'] = 'staging'; $GLOBALS['home'] = 'https://healthdatalab.net';
check( 'staging env wins even on live host → is_live FALSE', false === HDLV2_Env::is_live() );

$GLOBALS['env_type'] = 'production'; $GLOBALS['home'] = 'https://stby.healthdatalab.net';
check( 'RE-CLONE belt: production env but stby host → is_live FALSE (fail-closed)', false === HDLV2_Env::is_live() );

$GLOBALS['env_type'] = 'staging'; $GLOBALS['home'] = 'https://stby.healthdatalab.net';
check( 'STBY: staging + stby host → is_live FALSE', false === HDLV2_Env::is_live() );

$GLOBALS['env_type'] = 'production'; $GLOBALS['home'] = 'https://evil.example.com';
check( 'unknown host → is_live FALSE (fail-closed)', false === HDLV2_Env::is_live() );

// ── side_effects_allowed() ──
$GLOBALS['env_type'] = 'production'; $GLOBALS['home'] = 'https://healthdatalab.net';
check( 'LIVE → side effects allowed', true === HDLV2_Env::side_effects_allowed() );

$GLOBALS['env_type'] = 'staging'; $GLOBALS['home'] = 'https://stby.healthdatalab.net';
check( 'STBY default → side effects NOT allowed', false === HDLV2_Env::side_effects_allowed() );

$GLOBALS['allow_side_effects'] = true;
check( 'STBY + filter override → allowed (manual-test path)', true === HDLV2_Env::side_effects_allowed() );
$GLOBALS['allow_side_effects'] = false;

// ── gate() — return value + log line ──
$log = tempnam( sys_get_temp_dir(), 'hdlv2-env-log' );
$prev = ini_set( 'error_log', $log );

check( 'gate() on STBY returns FALSE', false === HDLV2_Env::gate( 'unit-test-context client:42' ) );
$logged = (string) @file_get_contents( $log );
check( 'gate() logged [HDLV2-ENV] with the context', false !== strpos( $logged, '[HDLV2-ENV]' ) && false !== strpos( $logged, 'unit-test-context client:42' ) );

file_put_contents( $log, '' );
$GLOBALS['allow_side_effects'] = true;
check( 'gate() with override returns TRUE', true === HDLV2_Env::gate( 'unit-test-context-2' ) );
check( 'gate() when allowed logs NOTHING', '' === (string) @file_get_contents( $log ) );
$GLOBALS['allow_side_effects'] = false;

ini_set( 'error_log', $prev );
@unlink( $log );

// ── constant override (defined once, must hold) ──
define( 'HDLV2_STAGING_SIDE_EFFECTS', true );
check( 'STBY + HDLV2_STAGING_SIDE_EFFECTS constant → allowed', true === HDLV2_Env::side_effects_allowed() );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
