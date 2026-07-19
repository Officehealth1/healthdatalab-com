<?php
/**
 * Addenda stamp/filter mechanism guard (P1 fix, 2026-07-19).
 *
 * MECHANISM ONLY — deliberately NOT a "count the Update paragraphs" test.
 * The integrate step's Claude call masks the visible duplication (a paragraph-
 * count assertion FALSE-PASSES on the buggy code), so this guards the actual
 * invariant instead:
 *
 *   after a Save-&-Update-Plan cycle integrates an addendum, that addendum is
 *   stamped superseded_by_report_id, and the NEXT cycle's un-superseded filter
 *   EXCLUDES it — so integrate_addenda_into_organised() only ever receives
 *   genuinely-new addenda (no unbounded re-processing, intact audit trail).
 *
 * Exercises the REAL filter (HDLV2_Final_Report::filter_unsuperseded_addenda)
 * and models the Option-2 stamp UPDATE semantics. Standalone — no WP, no DB.
 *
 *   Run:  php tests/addenda-stamp/test-addenda-stamp.php
 *   Exit: 0 all pass · 1 any fail
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/tmp/' ); }

$PLUGIN = dirname( __DIR__, 2 );
require_once $PLUGIN . '/includes/sprint-2c/class-hdlv2-final-report.php';

$PASS = 0; $FAIL = 0;
function ok( $cond, $label ) { global $PASS, $FAIL; ( $cond ? $PASS++ : $FAIL++ ); echo ( $cond ? 'PASS  ' : 'FAIL  ' ) . $label . "\n"; }

// ── in-memory addenda table ──
function add_addendum( &$addenda, &$next_id, $text ) {
    $addenda[] = array( 'id' => $next_id, 'note_text' => $text, 'superseded_by_report_id' => null );
    return $next_id++;
}
// Mirrors the Option-2 stamp exactly:
//   UPDATE … SET superseded_by_report_id=<rid> WHERE id IN (<ids>) AND superseded_by_report_id IS NULL
function stamp( &$addenda, $ids, $report_id ) {
    foreach ( $addenda as &$a ) {
        if ( in_array( $a['id'], $ids, true ) && empty( $a['superseded_by_report_id'] ) ) {
            $a['superseded_by_report_id'] = $report_id;
        }
    }
}
function ids_of( $rows ) {
    return array_map( static function ( $r ) { return $r['id']; }, $rows );
}

// ─────────────────────────────────────────────────────────────────────
// GREEN — with the Option-2 stamp wired in (the fix)
// ─────────────────────────────────────────────────────────────────────
echo "── GREEN: Option-2 stamp active ──\n";
$addenda = array(); $next = 1;

$A = add_addendum( $addenda, $next, 'started creatine 5g daily' );
$new1 = HDLV2_Final_Report::filter_unsuperseded_addenda( $addenda );
ok( ids_of( $new1 ) === array( $A ), 'cycle 1: filter returns [A] — the only un-superseded addendum' );

stamp( $addenda, ids_of( $new1 ), 101 );  // regenerate() stamps $new_addenda with the report id
ok( $addenda[0]['superseded_by_report_id'] === 101, 'cycle 1: addendum A stamped superseded_by_report_id = 101' );

$B = add_addendum( $addenda, $next, 'vitamin D low — start 2000 IU' );
$new2 = HDLV2_Final_Report::filter_unsuperseded_addenda( $addenda );
ok( ids_of( $new2 ) === array( $B ), 'cycle 2: filter returns [B] ONLY — A excluded (not re-integrated)' );
ok( ! in_array( $A, ids_of( $new2 ), true ), 'cycle 2: A is NOT re-passed to integrate_addenda_into_organised' );

stamp( $addenda, ids_of( $new2 ), 101 );
$new3 = HDLV2_Final_Report::filter_unsuperseded_addenda( $addenda );
ok( $new3 === array(), 'cycle 3 (no new addendum): filter returns [] — nothing re-processed' );

// ─────────────────────────────────────────────────────────────────────
// RED — dead stamp (today's behaviour): the same real filter, but nothing
// ever stamps, so every cycle re-passes ALL addenda.
// ─────────────────────────────────────────────────────────────────────
echo "\n── RED: stamp dead (bug reproduced) ──\n";
$addenda = array(); $next = 1;
$A = add_addendum( $addenda, $next, 'started creatine 5g daily' );
HDLV2_Final_Report::filter_unsuperseded_addenda( $addenda ); // cycle 1 — but NO stamp fires
$B = add_addendum( $addenda, $next, 'vitamin D low — start 2000 IU' );
$new2 = HDLV2_Final_Report::filter_unsuperseded_addenda( $addenda );
ok(
    in_array( $A, ids_of( $new2 ), true ) && in_array( $B, ids_of( $new2 ), true ),
    'RED: without the stamp, cycle 2 re-passes A AND B (A re-integrated every cycle — the bug)'
);

echo "\n" . ( $PASS + $FAIL ) . " assertions · $PASS passed · $FAIL failed\n";
echo ( $FAIL ? "ADDENDA STAMP/FILTER: FAIL\n" : "ADDENDA STAMP/FILTER: PASS\n" );
exit( $FAIL ? 1 : 0 );
