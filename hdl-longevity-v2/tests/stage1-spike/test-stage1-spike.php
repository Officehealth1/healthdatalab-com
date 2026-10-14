<?php
/**
 * Stage 1 under load suite orchestrator (v0.48.16). Standalone, no WP, no DB.
 *   php tests/stage1-spike/test-stage1-spike.php
 * The three node scripts run here too when node is on the PATH.
 */
$php  = escapeshellarg( PHP_BINARY );
$fail = 0;
foreach ( array( 'scenario-migration.php', 'scenario-capture-split.php', 'scenario-drain.php', 'scenario-busy-and-alert.php',
                 'scenario-replay.php', 'scenario-rate-limit.php', 'scenario-bulk-actions.php' ) as $f ) {
    $out = array();
    exec( $php . ' ' . escapeshellarg( __DIR__ . '/' . $f ) . ' 2>&1', $out, $code );
    echo "── $f: " . end( $out ) . "\n";
    if ( 0 !== $code ) { $fail = 1; echo implode( "\n", preg_grep( '/FAIL|Fatal/', $out ) ) . "\n"; }
}
foreach ( array( 'test-widget-hold.js', 'test-hold-fn.js', 'test-bulk-ui.js' ) as $f ) {
    $out = array();
    exec( 'node ' . escapeshellarg( __DIR__ . '/' . $f ) . ' 2>&1', $out, $code );
    echo "── $f: " . end( $out ) . "\n";
    if ( 0 !== $code ) { $fail = 1; echo implode( "\n", preg_grep( '/FAIL/', $out ) ) . "\n"; }
}
echo ( 0 === $fail ? "SUITE: PASS\n" : "SUITE: FAIL\n" );
exit( $fail );
