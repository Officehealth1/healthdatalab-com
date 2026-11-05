<?php
/** Family pack suite (v0.48.35). Standalone, no WP, no DB. php tests/healthspan-timeline-pack/test-pack.php */
$php  = escapeshellarg( PHP_BINARY );
$fail = 0;
$files = array( 'scenario-migration.php', 'scenario-pack.php', 'scenario-seat-consent.php', 'scenario-existing-report.php', 'scenario-cleanup.php', '../healthspan-timeline-links/scenario-enumeration.php' );
foreach ( $files as $f ) {
    $out = array();
    exec( $php . ' ' . escapeshellarg( __DIR__ . '/' . $f ) . ' 2>&1', $out, $code );
    echo '── ' . basename( $f ) . ': ' . end( $out ) . "\n";
    if ( 0 !== $code ) { $fail = 1; echo implode( "\n", preg_grep( '/FAIL|Fatal|Error/', $out ) ) . "\n"; }
}
$out = array();
exec( 'node ' . escapeshellarg( __DIR__ . '/test-ui.js' ) . ' 2>&1', $out, $code );
echo '── test-ui.js: ' . ( $out ? end( $out ) : 'node did not run' ) . "\n";
if ( 0 !== $code ) { $fail = 1; echo implode( "\n", preg_grep( '/FAIL|Error/', $out ) ) . "\n"; }
echo 0 === $fail ? "SUITE: PASS\n" : "SUITE: FAIL\n";
exit( $fail );
