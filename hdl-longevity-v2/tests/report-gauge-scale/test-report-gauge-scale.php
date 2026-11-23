<?php
/**
 * The Full Report PDF's gauge prints its scale (plan 10 of the $27 path, rows K26 and K42;
 * switch hdlv2_ff_report_gauge_scale). Standalone, no WP, no network:
 *   php tests/report-gauge-scale/test-report-gauge-scale.php
 *
 * A: switch absent, the draft webhook payload (the real fire_make_webhook(), sender stubbed)
 *    carries today's gauge address, character for character; switch on, the scaled one.
 * B: build_gauge_url() with 'scale' and the stage3 bounds: 0.5 and 2.0 from a part of no width
 *    under each end, 1.0 just outside the curve at the middle of the average band, printed from
 *    the amber part (a part of no width at 1.0 would cut the amber band in two: a seam on the
 *    picture, measured 2026-11-23). The three coloured parts, their edges, the needle and every
 *    other setting are today's.
 * C: switch on, every other gauge address stays today's.
 * D: the switch is read in one place, inside fire_make_webhook(); the builder never reads it.
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require dirname( __DIR__ ) . '/dashboard-products/stubs.php'; // the real Staged_Form and Final_Report

$PASS = 0;
$FAIL = 0;
function cfg_of( $url ) {
    parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
    $c = (string) ( $q['c'] ?? '' );
    // The one function in the config (datalabels' formatter) is not JSON: lift it out to read the rest.
    $GLOBALS['formatter'] = preg_match( '/"formatter":(\(v,c\)=>\[[^\]]*\]\[c\.dataIndex\])/', $c, $m ) ? $m[1] : '';
    return json_decode( str_replace( (string) $GLOBALS['formatter'], '"F"', $c ), true );
}
function sw( $v ) { $GLOBALS['options'] = null === $v ? array() : array( 'hdlv2_ff_report_gauge_scale' => $v ); }

// The sender and the parts of the payload not under test.
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t ) { return true; }
function rest_url( $p = '' ) { return 'https://stby.test/wp-json/' . $p; }
class HDLV2_Webhook_Monitor { public static $sent = array(); public static function fire( $url, $args, $kind ) { self::$sent[] = $args; } }
$GLOBALS['wpdb'] = new ProductsWpdb();

function payload_gauge( $rate ) {
    HDLV2_Webhook_Monitor::$sent = array();
    $form = ( new ReflectionClass( 'HDLV2_Staged_Form' ) )->newInstanceWithoutConstructor();
    $fire = new ReflectionMethod( $form, 'fire_make_webhook' );
    $fire->setAccessible( true );
    $progress = (object) array( 'id' => 7, 'practitioner_user_id' => 0, 'client_name' => 'Ivy Test', 'client_email' => 'ivy@example.test', 'stage3_data' => null );
    $fire->invoke( $form, $progress, array( 'rate' => $rate, 'bio_age' => 49.9, 'scores' => array() ), array( 'q1_age' => 45 ), array(), array() );
    $body = json_decode( HDLV2_Webhook_Monitor::$sent[0]['body'] ?? '{}', true );
    return (string) ( $body['gauge_url'] ?? '' );
}

echo "── A. the draft webhook payload ──\n";
// md5 of build_gauge_url( 1.11, array( 'stage3' => true ) ) at 39641ae: what the payload carries today.
$golden = '15fda7d2821706fe7d8fbd36e15c095a';
sw( null );
check( 'A1 switch absent: the payload\'s gauge address is byte-equal to 39641ae\'s', $golden === md5( payload_gauge( 1.11 ) ) );
sw( '0' );
check( 'A2 switch "0": still today\'s address', $golden === md5( payload_gauge( 1.11 ) ) );
sw( '1' );
$on = payload_gauge( 1.11 );
check( 'A3 switch on: the payload carries the scaled gauge', '' !== $on && $on === HDLV2_Staged_Form::build_gauge_url( 1.11, array( 'stage3' => true, 'scale' => true ) ) && md5( $on ) !== $golden );
sw( null );

echo "── B. the scaled picture ──\n";
$off = HDLV2_Staged_Form::build_gauge_url( 1.11, array( 'stage3' => true ) );
check( 'B0 without the option the builder\'s address is today\'s (scale false too)', $golden === md5( $off ) && $off === HDLV2_Staged_Form::build_gauge_url( 1.11, array( 'stage3' => true, 'scale' => false ) ) );
$scaled = HDLV2_Staged_Form::build_gauge_url( 1.11, array( 'stage3' => true, 'scale' => true ) );
$c   = cfg_of( $scaled );
$fmt = $GLOBALS['formatter'];
$b   = cfg_of( $off );
$ds  = $c['data']['datasets'][0] ?? array();
$bds = $b['data']['datasets'][0];
check( 'B1 same host and size as today', 0 === strpos( $scaled, 'https://quickchart.io/chart?w=380&h=340&bkg=white&c=' ) && is_array( $c ) );
check( 'B2 a part of no width under each end: 0.5 first, 2.0 last; the coloured edges 0.95, 1.05, 2.0 between', array( 0.5, 0.95, 1.05, 2.0, 2.0 ) == ( $ds['data'] ?? null ) );
$t = 'rgba(0,0,0,0)';
list( $g, $a, $r ) = $bds['backgroundColor'];
check( 'B3 the three colours unchanged, in order; the no-width parts clear', array( $t, $g, $a, $r, $t ) === ( $ds['backgroundColor'] ?? null ) );
check( 'B4 the borders between colours unchanged; none on the no-width parts', array( 0, 1, 1, 1, 0 ) === ( $ds['borderWidth'] ?? null ) && $bds['borderColor'] === $ds['borderColor'] && $bds['borderRadius'] === $ds['borderRadius'] );
$dl = $ds['datalabels'] ?? array();
check( 'B5 labels shown on three parts only: the two ends and the amber part', array( true, false, true, false, true ) === ( $dl['display'] ?? null ) );
check( 'B6 the printed texts are 0.5, 1.0 and 2.0, one decimal', '(v,c)=>["0.5","","1.0","","2.0"][c.dataIndex]' === $fmt && 'F' === ( $dl['formatter'] ?? '' ) );
check( 'B7 1.0 is the middle of the average band', abs( ( $bds['data'][0] + $bds['data'][1] ) / 2 - 1.0 ) < 1e-9 );
check( 'B8 0.5 and 2.0 under the two ends; 1.0 just outside the curve', 'center' === $dl['anchor'][0] && 'bottom' === $dl['align'][0] && 'center' === $dl['anchor'][4] && 'bottom' === $dl['align'][4]
    && 'end' === $dl['anchor'][2] && 'end' === $dl['align'][2] );
check( 'B9 the numbers in the brand\'s dark navy, bold', '#2c3e50' === ( $dl['color'] ?? '' ) && 'bold' === ( $dl['font']['weight'] ?? '' ) && ( $dl['font']['size'] ?? 0 ) >= 20 );
check( 'B10 datalabels switched on for the chart (the parts choose which show)', true === ( $c['options']['plugins']['datalabels']['display'] ?? null ) );
$c2 = $c;
unset( $c2['data']['datasets'][0]['data'], $c2['data']['datasets'][0]['backgroundColor'], $c2['data']['datasets'][0]['borderWidth'], $c2['data']['datasets'][0]['datalabels'], $c2['options']['plugins'] );
$b2 = $b;
unset( $b2['data']['datasets'][0]['data'], $b2['data']['datasets'][0]['backgroundColor'], $b2['data']['datasets'][0]['borderWidth'] );
check( 'B11 nothing else moves: needle, range, value, padding, every other option equal', $b2 === $c2 && ! isset( $b['options']['plugins'] ) );
foreach ( array( array( 0.3, 0.5 ), array( 2.4, 2.0 ), array( 1.11, 1.11 ), array( 0.951, 0.95 ) ) as $p ) {
    $v = cfg_of( HDLV2_Staged_Form::build_gauge_url( $p[0], array( 'stage3' => true, 'scale' => true ) ) )['data']['datasets'][0]['value'] ?? null;
    check( "B12 rate {$p[0]}: the needle at {$p[1]}, as today", $p[1] == $v );
}
check( 'B13 the address holds no raw quote or backslash (the payload\'s Make-safe pass would change it)', false === strpbrk( $scaled, "\"\\" ) );

echo "── C. switch on, the other gauges ──\n";
sw( '1' );
check( 'C1 the practitioner\'s report, HDLV2_Final_Report::gauge_url(): today\'s', $golden === md5( HDLV2_Final_Report::gauge_url( 1.11 ) ) );
check( 'C2 the two plain calls (Stage 1 e-mails): today\'s', 'b14d85d519e2cc98ad017c716af1a58d' === md5( HDLV2_Staged_Form::build_gauge_url( 1.11 ) ) );
check( 'C3 the practitioner\'s panel (show_value): today\'s', '94a98bb1f746d11194294ce436d4a2a9' === md5( HDLV2_Staged_Form::build_gauge_url( 1.11, array( 'show_value' => true ) ) ) );
sw( null );

echo "── D. one place reads the switch ──\n";
$root = dirname( __DIR__, 2 ) . '/includes/';
$src  = file_get_contents( $root . 'sprint-2/class-hdlv2-staged-form.php' );
$read = "get_option( 'hdlv2_ff_report_gauge_scale'";
$fire_at  = strpos( $src, 'function fire_make_webhook(' );
$next_at  = strpos( $src, "\n    /**", $fire_at );
$build_at = strpos( $src, 'function build_gauge_url(' );
$hits = 0;
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
    if ( 'php' === $f->getExtension() ) $hits += substr_count( file_get_contents( $f->getPathname() ), "'hdlv2_ff_report_gauge_scale'" );
}
check( 'D1 read once, inside fire_make_webhook(), and nowhere else in includes/', 1 === substr_count( $src, $read ) && 1 === $hits && strpos( $src, $read ) > $fire_at && strpos( $src, $read ) < $next_at );
check( 'D2 build_gauge_url() takes the scale as an option and never reads an option itself', false === strpos( substr( $src, $build_at, strpos( $src, "\n    }\n", $build_at ) - $build_at ), 'get_option' ) );

echo "── E. no dead gauge builder in the practitioner's report (amendment 2026-11-23, task 7a) ──\n";
check( 'E1 HDLV2_Final_Report has no build_gauge_url() of its own (it had no caller)', ! ( new ReflectionClass( 'HDLV2_Final_Report' ) )->hasMethod( 'build_gauge_url' ) );
check( 'E2 HDLV2_Final_Report::gauge_url() still returns today\'s address, switch absent', $golden === md5( HDLV2_Final_Report::gauge_url( 1.11 ) ) );
$fr = file_get_contents( $root . 'sprint-2c/class-hdlv2-final-report.php' );
check( 'E3 no comment points at gauge lines that are not there', false === strpos( $fr, 'lines 1245/1259' ) && false === strpos( $fr, 'see line 470' ) );

done();
