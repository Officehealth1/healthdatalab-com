<?php
/**
 * Plan 3 (v0.48.82), P8 — "upgrade", never "add-on" or "upsell" (Matthew, M3), in any string this
 * branch adds. With the base commit at hand the added lines of the branch are read; without it
 * (a shallow CI checkout) the files this plan adds are read whole.
 *
 * Run:  php scenario-words.php   (self-asserting, exit 0/1)
 */
$PASS = 0; $FAIL = 0;
function check( $label, $cond ) {
    global $PASS, $FAIL;
    if ( $cond ) { $PASS++; echo "  PASS  $label\n"; } else { $FAIL++; echo "  FAIL  $label\n"; }
}
$ROOT = dirname( __DIR__, 2 );
$BAD  = '/\b(add[\s-]?ons?|up[\s-]?sells?|up[\s-]?selling)\b/i';
$new  = $ROOT . '/includes/sprint-2c/class-hdlv2-timeline-upgrade.php';
check( 'W1 the upgrade\'s one home exists', file_exists( $new ) );

$added = '';
$base  = '8b64a250e555e73a2f130289a8e4e88d13dd86e5';
exec( 'git -C ' . escapeshellarg( $ROOT ) . ' cat-file -e ' . $base . ' 2>/dev/null', $o, $rc );
if ( 0 === $rc ) {
    exec( 'git -C ' . escapeshellarg( $ROOT ) . ' diff ' . $base . ' -- . ' . escapeshellarg( ':!tests' ), $diff );
    foreach ( $diff as $line ) {
        if ( '+' === ( $line[0] ?? '' ) && 0 !== strpos( $line, '+++' ) ) $added .= $line . "\n";
    }
    echo "  (read the branch's added lines against the base)\n";
} else {
    foreach ( array( $new ) as $f ) $added .= (string) @file_get_contents( $f );
    echo "  (no base commit here: read the new files whole)\n";
}
check( 'W2 the branch adds the customer words ("Yes, upgrade me to the Timeline")', false !== strpos( $added, 'Yes, upgrade me to the Timeline' ) );
preg_match_all( $BAD, $added, $m );
check( 'W3 no "add-on" or "upsell" in anything added (' . count( $m[0] ) . ' found)', 0 === count( $m[0] ) );
echo "\nPASS=$PASS FAIL=$FAIL\n";
exit( $FAIL ? 1 : 0 );
