<?php
/**
 * Dashboard version-digest fix — test suite orchestrator.
 *
 * Standalone, no WP, no DB, no network. Run from anywhere:
 *   php tests/dashboard-digest/test-dashboard-digest.php
 */

$DIR  = __DIR__;
$fail = 0;

foreach ( array( 'scenario-digest.php' ) as $scenario ) {
    echo "═══ $scenario ═══\n";
    $cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $DIR . '/' . $scenario ) . ' 2>/dev/null';
    exec( $cmd, $out, $code );
    echo implode( "\n", $out ) . "\n\n";
    $out = array();
    if ( 0 !== $code ) {
        $fail++;
    }
}

echo $fail ? "SUITE: FAIL\n" : "SUITE: PASS\n";
exit( $fail ? 1 : 0 );
