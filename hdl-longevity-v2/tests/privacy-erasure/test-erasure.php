<?php
/**
 * Data erasure suite (v0.48.63). Standalone, no WP, no MySQL (needs pdo_sqlite).
 *   php tests/privacy-erasure/test-erasure.php
 */
$php  = escapeshellarg( PHP_BINARY );
$fail = 0;
foreach ( array( 'scenario-erase.php', 'scenario-invited.php', 'scenario-request.php' ) as $f ) {
    $out = array();
    exec( $php . ' ' . escapeshellarg( __DIR__ . '/' . $f ) . ' 2>&1', $out, $code );
    echo "── $f: " . end( $out ) . "\n";
    if ( 0 !== $code ) { $fail = 1; echo implode( "\n", preg_grep( '/FAIL|Fatal|Error/', $out ) ) . "\n"; }
}
$out = array();
exec( 'node ' . escapeshellarg( __DIR__ . '/test-ui.js' ) . ' 2>&1', $out, $code );
echo "── test-ui.js: " . ( $out ? end( $out ) : 'node did not run' ) . "\n";
if ( 0 !== $code ) { $fail = 1; echo implode( "\n", preg_grep( '/FAIL|Error/', $out ) ) . "\n"; }

// The "done" email says what the Privacy Policy says (v0.48.64): backups are not overwritten on a schedule.
$tpl = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/sprint-2/class-hdlv2-email-templates.php' );
$tpl = substr( $tpl, (int) strpos( $tpl, 'function privacy_done' ), 2500 );
foreach ( array(
    'T4.1 done email: the backups sentence of the page' => false !== strpos( $tpl, 'Copies may remain in our backups. We do not use them, and if a backup is ever restored we delete your data from it again.' ) && false === strpos( $tpl, 'overwritten' ),
    'T4.2 done email: "where the law requires us to keep them"' => false !== strpos( $tpl, 'payment records, where the law requires us to keep them,' ),
) as $label => $cond ) {
    echo ( $cond ? '── PASS ' : '── FAIL ' ) . $label . "\n";
    if ( ! $cond ) { $fail = 1; }
}

echo ( 0 === $fail ? "SUITE: PASS\n" : "SUITE: FAIL\n" );
exit( $fail );
