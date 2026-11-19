<?php
/**
 * Safety notice by country (v0.48.66) — suite orchestrator. Standalone, no WP, no DB, no network.
 *   php tests/safety-notice/test-safety-notice.php
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

$code = 0;
foreach ( array(
    escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/scenario-safety-notice.php' ),
    'node ' . escapeshellarg( __DIR__ . '/test-widget-notice.mjs' ),
) as $cmd ) {
    $out = array();
    exec( $cmd . ' 2>&1', $out, $rc );
    echo implode( "\n", $out ) . "\n";
    $code = $code ?: $rc;
}
echo ( 0 === $code ? "SUITE: PASS\n" : "SUITE: FAIL\n" );
exit( $code ? 1 : 0 );
