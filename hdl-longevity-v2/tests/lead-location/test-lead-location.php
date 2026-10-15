<?php
/**
 * Stage-1 lead location test suite orchestrator (v0.47.83).
 *
 * Standalone, no WP, no DB, no network. Run from anywhere:
 *   php tests/lead-location/test-lead-location.php
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/scenario-lead-location.php' ) . ' 2>/dev/null';
exec( $cmd, $out, $code );
echo implode( "\n", $out ) . "\n";
echo ( 0 === $code ? "SUITE: PASS\n" : "SUITE: FAIL\n" );
exit( $code );
