<?php
/** Dashboard products suite (v0.48.36). Standalone, no WP, no DB. php tests/dashboard-products/test-products.php */
$php  = escapeshellarg( PHP_BINARY );
$fail = 0;
foreach ( array( 'scenario-products.php', 'scenario-route.php', 'scenario-nav.php', '../route-coverage/test-route-coverage.php' ) as $f ) {
    $out = array();
    exec( $php . ' ' . escapeshellarg( __DIR__ . '/' . $f ) . ' 2>&1', $out, $code );
    echo '── ' . basename( $f ) . ': ' . end( $out ) . "\n";
    if ( 0 !== $code ) { $fail = 1; echo implode( "\n", preg_grep( '/FAIL|Fatal|Error/', $out ) ) . "\n"; }
}
echo 0 === $fail ? "SUITE: PASS\n" : "SUITE: FAIL\n";
exit( $fail );
