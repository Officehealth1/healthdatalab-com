<?php
/**
 * H1 — the three client emails the rename touches, rendered with fixed input
 * (render-emails.php). No "Stage 2/3", "Full Report" present, and the hash
 * moved from fixtures/before.json (91ac872) to the pinned fixtures/after.json.
 *
 * Run:  php scenario-email-snapshot.php [--write]   (--write re-pins after.json)
 */
$PASS = 0; $FAIL = 0;
function check( $label, $cond ) {
    global $PASS, $FAIL;
    if ( $cond ) { $PASS++; echo "  PASS  $label\n"; } else { $FAIL++; echo "  FAIL  $label\n"; }
}
$php    = escapeshellarg( PHP_BINARY );
$render = escapeshellarg( __DIR__ . '/render-emails.php' );
$hashes = json_decode( shell_exec( "$php $render 2>/dev/null" ), true );
$docs   = json_decode( shell_exec( "$php $render --html 2>/dev/null" ), true );
if ( in_array( '--write', $argv, true ) ) {
    file_put_contents( __DIR__ . '/fixtures/after.json', json_encode( $hashes, JSON_PRETTY_PRINT ) . "\n" );
    echo "after.json written\n";
}
$before = json_decode( (string) @file_get_contents( __DIR__ . '/fixtures/before.json' ), true ) ?: array();
$after  = json_decode( (string) @file_get_contents( __DIR__ . '/fixtures/after.json' ), true ) ?: array();

echo "── H1 email snapshots ──\n";
foreach ( array( 'invite_email', 'why_released', 'stage1_results' ) as $k ) {
    $text = ( $docs[ $k ]['subject'] ?? '' ) . "\n" . ( $docs[ $k ]['html'] ?? '' );
    check( "E.$k no Stage 2/3", '' !== trim( $text ) && ! preg_match( '/\bStage ?(2|3)\b/', $text ) );
    check( "E.$k says Full Report", false !== strpos( $text, 'Full Report' ) );
    check( "E.$k changed since 91ac872", isset( $before[ $k ] ) && $before[ $k ] !== ( $hashes[ $k ] ?? '' ) );
    check( "E.$k equals the pinned after.json", isset( $after[ $k ] ) && $after[ $k ] === ( $hashes[ $k ] ?? '' ) );
}
foreach ( array( 'widget_lines', 'form_lines' ) as $k ) {
    check( "E.$k changed since 91ac872", isset( $before[ $k ] ) && $before[ $k ] !== ( $hashes[ $k ] ?? '' ) );
}
echo "\nPASS=$PASS FAIL=$FAIL\n";
exit( $FAIL === 0 ? 0 : 1 );
