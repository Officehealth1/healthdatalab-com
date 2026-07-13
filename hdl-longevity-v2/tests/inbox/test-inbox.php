<?php
/**
 * Slice C — V2 client inbox test suite orchestrator.
 *
 * Standalone, no WP, no DB, no network. Run from anywhere:
 *   php tests/inbox/test-inbox.php
 *
 * Scenarios (each its own process — class stubs differ):
 *   scenario-thread-view.php            token-mode interceptor (pre-UM init surface)
 *   scenario-thread-view-noservice.php  fail-closed when the V1 validator is absent
 *   scenario-dashboard-panel.php        session-mode panel in [hdlv2_my_dashboard]
 */

$scenarios = array(
    'scenario-thread-view.php',
    'scenario-thread-view-noservice.php',
    'scenario-dashboard-panel.php',
);

$fail = 0;
foreach ( $scenarios as $s ) {
    echo "── {$s} ──\n";
    passthru( PHP_BINARY . ' ' . escapeshellarg( __DIR__ . '/' . $s ), $code );
    if ( $code !== 0 ) $fail = 1;
    echo "\n";
}

echo $fail ? "SUITE: FAIL\n" : "SUITE: PASS\n";
exit( $fail );
