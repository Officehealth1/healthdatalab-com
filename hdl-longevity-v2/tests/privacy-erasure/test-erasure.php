<?php
/**
 * Data erasure suite (v0.48.63). Standalone, no WP, no MySQL (needs pdo_sqlite).
 *   php tests/privacy-erasure/test-erasure.php
 */
$php  = escapeshellarg( PHP_BINARY );
$fail = 0;
foreach ( array( 'scenario-erase.php', 'scenario-invited.php' ) as $f ) {
    $out = array();
    exec( $php . ' ' . escapeshellarg( __DIR__ . '/' . $f ) . ' 2>&1', $out, $code );
    echo "── $f: " . end( $out ) . "\n";
    if ( 0 !== $code ) { $fail = 1; echo implode( "\n", preg_grep( '/FAIL|Fatal|Error/', $out ) ) . "\n"; }
}
echo ( 0 === $fail ? "SUITE: PASS\n" : "SUITE: FAIL\n" );
exit( $fail );
