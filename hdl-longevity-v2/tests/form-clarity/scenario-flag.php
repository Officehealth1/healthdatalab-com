<?php
/**
 * v0.48.78 (M35-M37) — the form's switch reaches the page: the REAL HDLV2_Staged_Form::render_shortcode()
 * hands form_clarity to the script as false with hdlv2_ff_form_clarity absent, true with it set, and
 * every other key of the config is the same either way.
 * Run:  php scenario-flag.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ . '/' );
define( 'HDLV2_PLUGIN_URL', 'https://example.test/wp-content/plugins/hdl-longevity-v2/' );
define( 'HDLV2_VERSION', '0.48.78' );
$GLOBALS['options'] = array();
$GLOBALS['localized'] = array();
function get_option( $k, $default = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $default; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_json_encode( $x ) { return json_encode( $x ); }
function wp_enqueue_script() {}
function wp_enqueue_style() {}
function wp_localize_script( $handle, $name, $data ) { $GLOBALS['localized'][ $handle . '/' . $name ] = $data; }
function rest_url( $p ) { return 'https://example.test/wp-json/' . $p; }
function wp_create_nonce() { return 'nonce'; }
class WP_Error { public function __construct() {} }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
require_once dirname( __DIR__, 2 ) . '/includes/class-hdlv2-report-names.php'; // v0.48.96: the report names
require __DIR__ . '/../../includes/sprint-2/class-hdlv2-staged-form.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) { global $pass, $fail; echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n"; $ok ? $pass++ : $fail++; }

$form = ( new ReflectionClass( 'HDLV2_Staged_Form' ) )->newInstanceWithoutConstructor();
$config = function () use ( $form ) {
    $GLOBALS['localized'] = array();
    $form->render_shortcode( array() );
    return $GLOBALS['localized']['hdlv2-staged-form/hdlv2_form'] ?? null;
};

$absent = $config();
check( 'S1 the form script gets its config', is_array( $absent ) );
check( 'S2 option absent: form_clarity is false', is_array( $absent ) && array_key_exists( 'form_clarity', $absent ) && false === $absent['form_clarity'] );
$GLOBALS['options']['hdlv2_ff_form_clarity'] = '1';
$on = $config();
check( 'S3 option set: form_clarity is true', is_array( $on ) && true === ( $on['form_clarity'] ?? null ) );
$GLOBALS['options']['hdlv2_ff_form_clarity'] = '0';
$zero = $config();
check( 'S4 option set to 0: form_clarity is false', is_array( $zero ) && false === ( $zero['form_clarity'] ?? null ) );
unset( $absent['form_clarity'], $on['form_clarity'] );
check( 'S5 every other key is the same either way', is_array( $absent ) && $absent === $on );
// v0.48.92 (plan 8): hdlv2_ff_form_focus is read the same way.
$GLOBALS['options'] = array();
$fa = $config();
check( 'S6 option absent: form_focus is false', is_array( $fa ) && array_key_exists( 'form_focus', $fa ) && false === $fa['form_focus'] );
$GLOBALS['options']['hdlv2_ff_form_focus'] = '1';
$fo = $config();
check( 'S7 option set: form_focus is true', is_array( $fo ) && true === ( $fo['form_focus'] ?? null ) );
$GLOBALS['options']['hdlv2_ff_form_focus'] = '0';
$fz = $config();
check( 'S8 option set to 0: form_focus is false', is_array( $fz ) && false === ( $fz['form_focus'] ?? null ) );
unset( $fa['form_focus'], $fo['form_focus'] );
check( 'S9 every other key is the same either way', is_array( $fa ) && $fa === $fo );
echo "\n" . ( $fail ? "SCENARIO: FAIL ($fail)\n" : "SCENARIO: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );
