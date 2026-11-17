<?php
/**
 * The Stage 1 gauge's scale on its curve (plan 2026-11-17, row M21; flag
 * hdlv2_ff_gauge_scale). Standalone, no WP, no network:
 *   php tests/gauge-scale/test-gauge-scale.php
 *
 * P2: flag absent, the gauge address is today's, character for character.
 * P1: flag on, the picture carries 0.8, 0.9, 1.1 and 1.4, each from a part of
 *     no width at that value; the three coloured parts, the needle and every
 *     other setting are as they were; the rate is still held at 0.8 to 1.4.
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require dirname( __DIR__ ) . '/dashboard-one-row-fix/stubs.php'; // loads the real Widget_Config

$pass = 0;
$fail = 0;
function ok( $name, $cond ) {
    global $pass, $fail;
    echo ( $cond ? 'PASS' : 'FAIL' ) . " | $name\n";
    $cond ? $pass++ : $fail++;
}
function cfg_of( $url ) {
    parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
    return json_decode( $q['c'] ?? 'null', true );
}
function gauge( $rate, $flag ) {
    $GLOBALS['options'] = $flag ? array( 'hdlv2_ff_gauge_scale' => $flag ) : array();
    return HDLV2_Widget_Config::stage1_report_gauge_url( $rate );
}

// P2 — md5 of stage1_report_gauge_url( 1.11 ) at 33a3001.
$off = gauge( 1.11, null );
ok( 'P2 flag absent: the address for 1.11 is byte-equal to 33a3001', 'a0047be86c7d8b86089894b5d8f6019f' === md5( $off ) );
ok( 'P2 flag "0": still today\'s address', $off === gauge( 1.11, '0' ) );

// P1 — flag on.
$on  = gauge( 1.11, '1' );
$c   = cfg_of( $on );
$b   = cfg_of( $off );
$ds  = $c['data']['datasets'][0] ?? array();
$bds = $b['data']['datasets'][0];
ok( 'P1 flag on: a different address, so the saved picture is made again', $on !== $off && 0 === strpos( $on, 'https://quickchart.io/chart?c=' ) && false !== strpos( $on, '&w=380&h=340&bkg=white' ) );
ok( 'P1 a part of no width at each scale value', array( 0.8, 0.9, 0.9, 1.1, 1.1, 1.4, 1.4 ) === ( $ds['data'] ?? null ) );

$t = 'rgba(0,0,0,0)';
list( $g, $bl, $r ) = $bds['backgroundColor'];
ok( 'P1 the three colours unchanged, in order; the no-width parts clear', array( $t, $g, $t, $bl, $t, $r, $t ) === ( $ds['backgroundColor'] ?? null ) );
ok( 'P1 the borders between colours unchanged; none on the no-width parts', array( 0, 1, 0, 1, 0, 1, 0 ) === ( $ds['borderWidth'] ?? null ) && $bds['borderColor'] === $ds['borderColor'] && $bds['borderRadius'] === $ds['borderRadius'] );

$dl = $ds['datalabels'] ?? array();
$shown = array();
foreach ( (array) ( $dl['display'] ?? array() ) as $i => $d ) { if ( true === $d ) $shown[] = $ds['data'][ $i ]; }
ok( 'P1 exactly four numbers printed: 0.8, 0.9, 1.1, 1.4', array( 0.8, 0.9, 1.1, 1.4 ) === $shown && 7 === count( $dl['display'] ) );
ok( 'P1 0.8 and 1.4 under the two ends; 0.9 and 1.1 just outside the curve', 'center' === $dl['anchor'][0] && 'bottom' === $dl['align'][0] && 'center' === $dl['anchor'][6] && 'bottom' === $dl['align'][6]
    && 'end' === $dl['anchor'][2] && 'end' === $dl['align'][2] && 'end' === $dl['anchor'][4] && 'end' === $dl['align'][4] );
ok( 'P1 the numbers in the brand\'s dark navy, bold', '#2c3e50' === ( $dl['color'] ?? '' ) && 'bold' === ( $dl['font']['weight'] ?? '' ) && ( $dl['font']['size'] ?? 0 ) >= 20 );
ok( 'P1 datalabels switched on for the chart (the parts choose which show)', true === ( $c['options']['plugins']['datalabels']['display'] ?? null ) );

$c2 = $c;
unset( $c2['data']['datasets'][0]['data'], $c2['data']['datasets'][0]['backgroundColor'], $c2['data']['datasets'][0]['borderWidth'], $c2['data']['datasets'][0]['datalabels'], $c2['options']['plugins'] );
$b2 = $b;
unset( $b2['data']['datasets'][0]['data'], $b2['data']['datasets'][0]['backgroundColor'], $b2['data']['datasets'][0]['borderWidth'], $b2['options']['plugins'] );
ok( 'P1 nothing else moves: needle, range, value, padding, every other option equal', $b2 === $c2 );

foreach ( array( array( 0.5, 0.8 ), array( 2.0, 1.4 ), array( 1.004, 1.0 ), array( 0.86, 0.86 ) ) as $p ) {
    $v = cfg_of( gauge( $p[0], '1' ) )['data']['datasets'][0]['value'] ?? null;
    ok( "P1 rate {$p[0]} drawn at {$p[1]} (held between 0.8 and 1.4)", $p[1] == $v );
}
ok( 'P1 no rate, no picture, flag on or off', '' === gauge( 0, '1' ) && '' === gauge( 0, null ) );

// P6 (closing rows, 2026-11-17): the saved picture's name. Flag absent, every saved gauge keeps its 33a3001 name
// (cache "v5"), so a merge re-makes none of them; flag on, the address differs, so its fingerprint gives a new file.
$GLOBALS['up'] = sys_get_temp_dir() . '/hdlv2-gauge-test-' . getmypid();
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['up'], 'baseurl' => 'https://stby.test/up' ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0700, true ); }
function wp_remote_get( $u, $a = array() ) { return array( 'body' => str_repeat( 'p', 2000 ) ); }
function wp_remote_retrieve_response_code( $r ) { return 200; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
$tok   = str_repeat( 'ab', 16 );
$saved = function ( $flag ) use ( $tok ) {
    $url = gauge( 1.11, $flag );
    return array( $url, (string) HDLV2_Widget_Config::prerender_gauge_png( $url, $tok, 'stage1' ) );
};
list( $u_off, $n_off ) = $saved( null );
list( $u_on, $n_on )   = $saved( '1' );
ok( 'P6 flag absent: the saved name is 33a3001\'s (cache v5, same fingerprint)', 'https://stby.test/up/hdlv2-gauges/' . $tok . '-stage1-v5-' . substr( hash( 'sha256', $u_off ), 0, 12 ) . '.png' === $n_off );
ok( 'P6 flag on: a new saved file (its own fingerprint)', '' !== $n_on && $n_on !== $n_off && false !== strpos( $n_on, substr( hash( 'sha256', $u_on ), 0, 12 ) ) );
array_map( 'unlink', glob( $GLOBALS['up'] . '/hdlv2-gauges/*' ) ?: array() );
@rmdir( $GLOBALS['up'] . '/hdlv2-gauges' );
@rmdir( $GLOBALS['up'] );
$src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/sprint-1/class-hdlv2-widget-config.php' );
$read = "get_option( 'hdlv2_ff_gauge_scale'";
ok( 'the flag is read once, inside stage1_report_gauge_url(); build_gauge_url() (the practitioner\'s notice) never reads it',
    1 === substr_count( $src, $read ) && strpos( $src, 'function stage1_report_gauge_url' ) < strpos( $src, $read ) && strpos( $src, $read ) < strpos( $src, 'function build_gauge_url' ) );

echo "\nPASS=$pass FAIL=$fail\n";
exit( $fail ? 1 : 0 );
