<?php
/**
 * Healthspan Timeline links suite (v0.48.34). Standalone, no WP, no DB.
 *   php tests/healthspan-timeline-links/test-links.php
 */
$php  = escapeshellarg( PHP_BINARY );
$fail = 0;
foreach ( array( 'scenario-migration.php', 'scenario-links.php', 'scenario-consent.php', 'scenario-withdraw.php', 'scenario-cleanup.php', 'scenario-enumeration.php', 'scenario-verify.php' ) as $f ) {
    $out = array();
    exec( $php . ' ' . escapeshellarg( __DIR__ . '/' . $f ) . ' 2>&1', $out, $code );
    echo "── $f: " . end( $out ) . "\n";
    if ( 0 !== $code ) { $fail = 1; echo implode( "\n", preg_grep( '/FAIL|Fatal|Error/', $out ) ) . "\n"; }
}
$out = array();
exec( 'node ' . escapeshellarg( __DIR__ . '/test-ui.js' ) . ' 2>&1', $out, $code );
echo "── test-ui.js: " . ( $out ? end( $out ) : 'node did not run' ) . "\n";
if ( 0 !== $code ) { $fail = 1; echo implode( "\n", preg_grep( '/FAIL|Error/', $out ) ) . "\n"; }

echo ( 0 === $fail ? "SUITE: PASS\n" : "SUITE: FAIL\n" );
exit( $fail );
