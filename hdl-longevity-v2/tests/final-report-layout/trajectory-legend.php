<?php
/**
 * Trajectory chart (Final report page 9): the legend and the "Age (years)" axis
 * title each have their own band, and the picture's address changes with the
 * drawing so no cache serves the old one (plan 2026-11-14, D4). Runs the REAL
 * HDLV2_Trajectory_SVG. No WP, no network.
 *
 *   php tests/final-report-layout/trajectory-legend.php
 */

error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ . '/' );
function add_action() {}
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }

require __DIR__ . '/../../includes/sprint-2c/class-hdlv2-trajectory-svg.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}

/** Vertical band a line of text takes: 0.8 em above its baseline (capitals, brackets), 0.25 em below. */
function band( $y, $size ) {
    return array( $y - 0.8 * $size, $y + 0.25 * $size );
}
function apart( $a, $b ) {
    return $a[1] <= $b[0] || $b[1] <= $a[0];
}

$legend_labels = array( 'Your Trajectory', 'Population Avg', 'With Changes', 'Without Changes' );
foreach ( array( array( 35, 0.85 ), array( 50, 1.0 ), array( 72, 1.3 ) ) as $pair ) {
    list( $age, $rate ) = $pair;
    $svg = HDLV2_Trajectory_SVG::build( $age, $rate );
    preg_match( '/viewBox="0 0 [\d.]+ ([\d.]+)"/', $svg, $vb );
    $height = (float) $vb[1];

    preg_match( '/<text[^>]*\by="([\d.]+)"[^>]*font-size="([\d.]+)"[^>]*>Age \(years\)<\/text>/', $svg, $axis );
    $axis_band = band( (float) $axis[1], (float) $axis[2] );

    preg_match( '/<g font-size="([\d.]+)"[^>]*>((?:(?!<\/g>).)*Your Trajectory.*?)<\/g>/s', $svg, $legend );
    $legend_band = null;
    foreach ( $legend_labels as $label ) {
        preg_match( '/<text[^>]*\by="([\d.]+)"[^>]*>' . preg_quote( $label, '/' ) . '<\/text>/', $legend[2], $m );
        $b = band( (float) $m[1], (float) $legend[1] );
        $legend_band = $legend_band ? array( min( $legend_band[0], $b[0] ), max( $legend_band[1], $b[1] ) ) : $b;
    }

    preg_match_all( '/<text[^>]*\by="([\d.]+)"[^>]*font-size="([\d.]+)"[^>]*>\d+<\/text>/', $svg, $ticks, PREG_SET_ORDER );
    $tick_band = null;
    foreach ( $ticks as $t ) {
        if ( (float) $t[1] < $axis[1] - 40 ) continue; // the x-axis ages, not the y-axis percentages
        $b = band( (float) $t[1], (float) $t[2] );
        $tick_band = $tick_band ? array( min( $tick_band[0], $b[0] ), max( $tick_band[1], $b[1] ) ) : $b;
    }

    $tag = "age $age rate $rate";
    check( "$tag: axis title band and legend band do not touch", apart( $axis_band, $legend_band ) );
    check( "$tag: axis title band clear of the age numbers", $tick_band && apart( $axis_band, $tick_band ) );
    check( "$tag: axis title and legend inside the drawing (height $height)",
        $axis_band[0] >= 0 && $axis_band[1] <= $height && $legend_band[0] >= 0 && $legend_band[1] <= $height );
}

$url = HDLV2_Trajectory_SVG::url_for( 50, 1.0 );
check( 'url_for carries a drawing version', 1 === preg_match( '/[?&]v=\d+/', $url ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
