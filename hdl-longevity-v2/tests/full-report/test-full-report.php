<?php
/**
 * Full Report funnel suite orchestrator (v0.48.15). Standalone, no WP, no DB.
 *   php tests/full-report/test-full-report.php
 * The two node scripts run here too when node is on the PATH.
 */
$php  = escapeshellarg( PHP_BINARY );
$fail = 0;
foreach ( array( 'scenario-rename.php', 'scenario-email-snapshot.php', 'scenario-offer.php', 'scenario-ticket-full-report.php',
                 'scenario-bulk-confirm.php', 'scenario-branding.php', 'scenario-migration.php' ) as $f ) {
    $out = array();
    exec( $php . ' ' . escapeshellarg( __DIR__ . '/' . $f ) . ' 2>/dev/null', $out, $code );
    echo "── $f: " . end( $out ) . "\n";
    if ( 0 !== $code ) { $fail = 1; echo implode( "\n", preg_grep( '/FAIL/', $out ) ) . "\n"; }
}
foreach ( array( 'test-widget-copy.js', 'test-form-brand.js' ) as $f ) {
    $out = array();
    exec( 'node ' . escapeshellarg( __DIR__ . '/' . $f ) . ' 2>&1', $out, $code );
    echo "── $f: " . end( $out ) . "\n";
    if ( 0 !== $code ) { $fail = 1; echo implode( "\n", preg_grep( '/FAIL/', $out ) ) . "\n"; }
}
echo ( 0 === $fail ? "SUITE: PASS\n" : "SUITE: FAIL\n" );
exit( $fail );
