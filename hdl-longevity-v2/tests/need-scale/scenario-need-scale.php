<?php
/**
 * v0.48.50 (W27) — the server's need, both scales: the REAL HDLV2_Why_Picks (tally() through
 * abilities_profile()) held to cases.json, option hdlv2_ff_need_scale_v2 absent and set, plus the
 * loader hands the flag to the page.
 * Run:  php scenario-need-scale.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['options'] = array();
function get_option( $k, $default = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $default; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_json_encode( $x ) { return json_encode( $x ); }
class WP_Error { public $code; public $message; public $data; public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
require __DIR__ . '/../../includes/sprint-2/class-hdlv2-why-picks.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) { global $pass, $fail; echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n"; $ok ? $pass++ : $fail++; }

$fx = json_decode( file_get_contents( __DIR__ . '/cases.json' ), true );
class Need_Fixture extends HDLV2_Why_Picks {
    public static $groups;
    public static function catalogue() {
        return array_map( function ( $g ) {
            $g['short'] = $g['title'];
            $g['items'] = array_map( function ( $it ) { return $it + array( 'retired' => false ); }, $g['items'] );
            return $g;
        }, self::$groups );
    }
}
Need_Fixture::$groups = $fx['groups'];

foreach ( array( false, true ) as $on ) {
    if ( $on ) $GLOBALS['options']['hdlv2_ff_need_scale_v2'] = '1'; else unset( $GLOBALS['options']['hdlv2_ff_need_scale_v2'] );
    foreach ( $fx['cases'] as $c ) {
        $got = array();
        foreach ( Need_Fixture::abilities_profile( $c['picks'] ) as $a ) $got[ $a['id'] ] = $a['need'];
        $want = $on ? $c['need_on'] : $c['need_off'];
        ksort( $got ); ksort( $want );
        $same = array_keys( $got ) === array_keys( $want );
        foreach ( $want as $k => $v ) $same = $same && isset( $got[ $k ] ) && abs( $got[ $k ] - $v ) < 1e-9;
        check( ( $on ? 'on ' : 'off' ) . ' [' . implode( ',', $c['picks'] ) . '] → ' . json_encode( $want ) . ( $same ? '' : ' (got ' . json_encode( $got ) . ')' ), $same );
    }
}
unset( $GLOBALS['options']['hdlv2_ff_need_scale_v2'] );
$sf = file_get_contents( __DIR__ . '/../../includes/sprint-2/class-hdlv2-staged-form.php' );
check( 'L1 the page gets need_scale_v2 from the option', 1 === preg_match( "/'need_scale_v2'\\s*=>\\s*\\(bool\\) get_option\\( 'hdlv2_ff_need_scale_v2', false \\)/", $sf ) );
echo "\n" . ( $fail ? "SCENARIO: FAIL ($fail)\n" : "SCENARIO: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );
