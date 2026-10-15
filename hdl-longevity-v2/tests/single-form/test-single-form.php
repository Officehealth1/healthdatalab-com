<?php
/**
 * Single-form WHY picks suite orchestrator (v0.47.92).
 *
 * Standalone, no WP, no DB, no network. Run from anywhere:
 *   php tests/single-form/test-single-form.php
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

$code = 0;
foreach ( array( 'scenario-why-picks.php', 'scenario-single-form-flow.php' ) as $f ) {
    $out = array();
    exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/' . $f ) . ' 2>/dev/null', $out, $c );
    echo implode( "\n", $out ) . "\n";
    $code = $code ?: $c;
}
echo ( 0 === $code ? "SUITE: PASS\n" : "SUITE: FAIL\n" );
exit( $code );
