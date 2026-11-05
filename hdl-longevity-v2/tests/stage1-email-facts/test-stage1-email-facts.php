<?php
/**
 * Stage 1 email facts suite (v0.48.50). Standalone, no WP, no DB.
 *   php tests/stage1-email-facts/test-stage1-email-facts.php
 */
$php  = escapeshellarg( PHP_BINARY );
$fail = 0;
foreach ( array( 'scenario-stage1-email-facts.php' ) as $f ) {
    $out = array();
    exec( $php . ' ' . escapeshellarg( __DIR__ . '/' . $f ) . ' 2>/dev/null', $out, $code );
    echo "── $f: " . end( $out ) . "\n";
    if ( 0 !== $code ) { $fail = 1; echo implode( "\n", preg_grep( '/FAIL/', $out ) ) . "\n"; }
}
echo ( 0 === $fail ? "SUITE: PASS\n" : "SUITE: FAIL\n" );
exit( $fail );
