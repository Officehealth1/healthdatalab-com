<?php
/**
 * Stage-2 retry cron test suite orchestrator (v0.47.58 fix — dead-row re-fires).
 *
 * Standalone, no WP, no DB, no network. Run from anywhere:
 *   php tests/stage2-retry/test-stage2-retry.php
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/scenario-retry-selection.php' ) . ' 2>/dev/null';
exec( $cmd, $out, $code );
echo implode( "\n", $out ) . "\n";
echo ( 0 === $code ? "SUITE: PASS\n" : "SUITE: FAIL\n" );
exit( $code );
