<?php
/**
 * Consultation cross-tenant fix — test suite orchestrator (C4).
 *
 * Standalone, no WP, no DB, no network. Run from anywhere:
 *   php tests/consultation-tenancy/test-consultation-tenancy.php
 *
 * Proves (per the fix spec):
 *  (a) practitioner A calling finalise / regenerate with practitioner B's
 *      consultation_id (but A's own progress_id) is denied at the REST
 *      layer (403, no job enqueued) AND fails closed inside the generator
 *      (defense-in-depth) — B's clinical notes are neither read nor
 *      overwritten;
 *  (b) the owning practitioner's normal finalise / regenerate still works:
 *      job enqueued at REST, consultation gate passed in the generator.
 */

$DIR  = __DIR__;
$fail = 0;

foreach ( array( 'scenario-enqueue.php', 'scenario-generate.php' ) as $scenario ) {
    echo "═══ $scenario ═══\n";
    $cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $DIR . '/' . $scenario ) . ' 2>/dev/null';
    exec( $cmd, $out, $code );
    echo implode( "\n", $out ) . "\n\n";
    $out = array();
    if ( 0 !== $code ) {
        $fail++;
    }
}

echo $fail ? "SUITE: FAIL\n" : "SUITE: PASS\n";
exit( $fail ? 1 : 0 );
