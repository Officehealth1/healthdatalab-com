<?php
/**
 * Who-for suite (v0.48.50). Standalone, no WP, no DB.
 *   php tests/who-for/test-who-for.php
 */
$out = array();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/scenario-who-for.php' ) . ' 2>&1', $out, $code );
echo "── scenario-who-for.php: " . end( $out ) . "\n";
if ( 0 !== $code ) echo implode( "\n", preg_grep( '/FAIL/', $out ) ) . "\n";
echo ( 0 === $code ? "SUITE: PASS\n" : "SUITE: FAIL\n" );
exit( $code ? 1 : 0 );
