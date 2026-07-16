<?php
/**
 * Email-safety suite orchestrator (v0.47.74 STBY mail guard + is_live gates;
 * v0.47.75 scheduled-client-campaign launch flag).
 *
 * Standalone, no WP, no DB, no network. Run from anywhere:
 *   php tests/email-safety/test-email-safety.php
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

$scenarios = array(
    'scenario-env.php',
    'scenario-mail-guard.php',
    'scenario-gates-checkin.php',
    'scenario-gates-flightplan.php',
    'scenario-gates-monthly.php',
    'scenario-gates-stage2retry.php',
    'scenario-launch-flag.php',
);

$exit = 0;
foreach ( $scenarios as $s ) {
    echo "── $s ──\n";
    $cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/' . $s ) . ' 2>/dev/null';
    exec( $cmd, $out, $code );
    echo implode( "\n", $out ) . "\n\n";
    $out = array();
    if ( 0 !== $code ) $exit = 1;
}
echo ( 0 === $exit ? "SUITE: PASS\n" : "SUITE: FAIL\n" );
exit( $exit );
