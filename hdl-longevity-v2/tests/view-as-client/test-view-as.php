<?php
/** View as client suite (v0.48.40, Plan E4-b). Standalone, no WP, no DB. php tests/view-as-client/test-view-as.php */
$php  = escapeshellarg( PHP_BINARY );
$fail = 0;
foreach ( array( 'scenario-view.php', 'scenario-writes.php', 'scenario-door.php', '../route-coverage/test-route-coverage.php' ) as $f ) {
    $out = array();
    exec( $php . ' ' . escapeshellarg( __DIR__ . '/' . $f ) . ' 2>&1', $out, $code );
    echo '── ' . basename( $f ) . ': ' . end( $out ) . "\n";
    if ( 0 !== $code ) { $fail = 1; echo implode( "\n", preg_grep( '/FAIL|Fatal|Error|Warning/', $out ) ) . "\n"; }
}
echo 0 === $fail ? "SUITE: PASS\n" : "SUITE: FAIL\n";
exit( $fail );
