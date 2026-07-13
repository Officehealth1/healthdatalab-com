<?php
/**
 * GAP-1 (2026-07-13) — send behaviour must be DECOUPLED from needs_attention.
 *
 * The bug: HDLV2_Client_Status::calculate_status() returns NEEDS_ATTENTION from
 * a has_flags short-circuit (:790) BEFORE the report-exists check (:822), so a
 * red-flagged client mid-funnel (Stage 2/3, ZERO reports) was mapped by
 * resend_link_descriptor() into the post-report bucket → "Resend report",
 * enabled. Firing it rotated form_progress.token (killing the client's live
 * link) and emailed a dead /longevity-draft-report/?t= link — stranding
 * exactly the clients who most need attention.
 *
 * The fix (per Quim's decision — derive-from-real-state, needs_attention is
 * DISPLAY-ONLY):
 *   1. resolve_resend_status($client_user_id): funnel/completion position,
 *      FLAG-BLIND (no attention overlay). Returns the true stage so a red-flag
 *      mid-assessment client gets the stage-appropriate CONTINUE link; a
 *      red-flag client whose report exists gets the report; a red-flag client
 *      Stage-3-done-but-no-report lands on AWAITING_CONSULT (disabled — there
 *      is genuinely nothing to send).
 *   2. resend_link_descriptor() gains a $has_report gate (defence-in-depth):
 *      the post-report bucket NEVER offers a report/plan when no report exists,
 *      regardless of which status is passed in.
 *
 * Fake wpdb feeds resolve_resend_status the same 3 lookups calculate_status
 * uses (form_progress, why_profiles, consultation_notes), so the test is
 * behavioural: the resolver must ignore has_flags entirely.
 *
 * Run:  php tests/resend-link/scenario-gap1-attention-decouple.php   (exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'DAY_IN_SECONDS' ) ) define( 'DAY_IN_SECONDS', 86400 );

function add_action() {}
function get_current_user_id() { return 0; }
function rest_ensure_response( $x ) { return $x; }
class HDLV2_Compatibility {
    public static function is_practitioner( $u ) { return true; }
    public static function practitioner_owns_client( $p, $c ) { return true; }
}

// ── Fake wpdb — serves resolve_resend_status()'s three funnel lookups from a
//    per-scenario fixture set via $GLOBALS['fx']. has_flags is INCLUDED in the
//    form_progress row precisely so the test proves the resolver ignores it. ──
class FakeWpdb {
    public $prefix = 'wp_';
    public function prepare( $q, ...$a ) {
        // crude but faithful: inline the args so the fixture switch can read ids
        foreach ( $a as $v ) { $q = preg_replace( '/%d|%s/', is_int( $v ) ? (string) $v : "'" . $v . "'", $q, 1 ); }
        return $q;
    }
    public function get_row( $q ) {
        if ( strpos( $q, 'hdlv2_form_progress' ) !== false ) return $GLOBALS['fx']['progress'];
        if ( strpos( $q, 'hdlv2_why_profiles' )  !== false ) return $GLOBALS['fx']['why'];
        return null;
    }
    public function get_var( $q ) {
        if ( strpos( $q, 'hdlv2_consultation_notes' ) !== false ) return $GLOBALS['fx']['report_id'];
        if ( strpos( $q, 'hdlv2_flight_plans' )       !== false ) return $GLOBALS['fx']['plan_id'];
        return null;
    }
    public function get_results( $q ) { return array(); }
}
$GLOBALS['wpdb'] = new FakeWpdb();

require __DIR__ . '/../../includes/sprint-4/class-hdlv2-client-status.php';

$pass = 0; $fail = 0;
function eq( $name, $expected, $actual ) {
    global $pass, $fail;
    $ok = ( $expected === $actual );
    echo ( $ok ? 'PASS' : 'FAIL' ) . " | $name" . ( $ok ? '' : ' | expected ' . json_encode( $expected ) . ' got ' . json_encode( $actual ) ) . "\n";
    $ok ? $pass++ : $fail++;
}

// Fixture helper: a red-flag (has_flags=1) client at a given funnel position.
function fx( $stage1, $stage3, $why_released, $report ) {
    $GLOBALS['fx'] = array(
        'progress'  => (object) array( 'id' => 11, 'has_flags' => 1, 'stage1_completed_at' => $stage1, 'stage3_completed_at' => $stage3 ),
        'why'       => $why_released === null ? null : (object) array( 'id' => 5, 'released' => $why_released ),
        'report_id' => $report ? 99 : null,
        'plan_id'   => null,
    );
}

$RS = function () { return HDLV2_Client_Status::resolve_resend_status( 250 ); };
$D  = function ( $status, $has_plan = false, $has_report = true ) {
    return HDLV2_Client_Status::resend_link_descriptor( $status, $has_plan, $has_report );
};

// ── resolve_resend_status(): FLAG-BLIND funnel resolution ────────────────────
// (A) red-flag, Stage 2 (stage1 done, no stage3, no WHY row) → LOW_DATA
fx( '2026-01-01 00:00:00', null, null, false );
$r = $RS();
eq( '(G1a) red-flag Stage-2 → funnel LOW_DATA (ignores has_flags)', 'low_data', $r['status'] );
eq( '(G1a) …no report',                                             false,      $r['has_report'] );

// (B) red-flag, Stage 3 in progress (WHY released, stage3 not done) → STAGE3_IN_PROGRESS
fx( '2026-01-01 00:00:00', null, 1, false );
$r = $RS();
eq( '(G1b) red-flag Stage-3-in-progress → funnel STAGE3_IN_PROGRESS', 'stage3_in_progress', $r['status'] );

// (C) red-flag, WHY submitted not released → AWAITING_WHY_RELEASE (blocked on prac)
fx( '2026-01-01 00:00:00', null, 0, false );
$r = $RS();
eq( '(G1c) red-flag WHY-not-released → funnel AWAITING_WHY_RELEASE', 'awaiting_why_release', $r['status'] );

// (D) red-flag, Stage 3 DONE but NO report (the Margaret Hughes case) → AWAITING_CONSULT
fx( '2026-01-01 00:00:00', '2026-02-01 00:00:00', 1, false );
$r = $RS();
eq( '(G1d) red-flag Stage-3-done, 0 reports → funnel AWAITING_CONSULT', 'awaiting_consult', $r['status'] );
eq( '(G1d) …no report',                                               false,             $r['has_report'] );

// (E) red-flag, report EXISTS → PROGRESS_NORMAL + has_report (send the report)
fx( '2026-01-01 00:00:00', '2026-02-01 00:00:00', 1, true );
$r = $RS();
eq( '(G1e) red-flag with a real report → funnel PROGRESS_NORMAL', 'progress_normal', $r['status'] );
eq( '(G1e) …has report',                                          true,             $r['has_report'] );

// (F) not started (no stage1) → NOT_STARTED
fx( null, null, null, false );
$r = $RS();
eq( '(G1f) no stage1 → NOT_STARTED', 'not_started', $r['status'] );

// ── descriptor $has_report gate (defence-in-depth) ───────────────────────────
// A display status in the post-report bucket must NOT offer a report when none exists.
foreach ( array( 'needs_attention', 'active', 'progress_normal', 'inactive' ) as $st ) {
    $d = $D( $st, false, false ); // no plan, NO report
    eq( "(G1g) $st + has_report=false → DISABLED (no phantom resend)", false, $d['enabled'] );
    eq( "(G1g) $st + has_report=false → no link_kind",                 '',    $d['link_kind'] );
}
// With a real report the post-report bucket still works (regression).
$d = $D( 'needs_attention', false, true );
eq( '(G1h) needs_attention + has_report=true → Resend report', 'report', $d['link_kind'] );
$d = $D( 'progress_normal', true, true );
eq( '(G1h) progress_normal + plan + has_report=true → Resend flight plan', 'plan', $d['link_kind'] );

// Full continue-link mapping via the resolver → descriptor, for the red-flag stages:
fx( '2026-01-01 00:00:00', null, null, false ); $r = $RS(); $d = $D( $r['status'], false, $r['has_report'] );
eq( '(G1i) red-flag Stage-2 END-TO-END → Send Stage-2 link (continue, NOT report)', 'Send Stage-2 link', $d['label'] );
eq( '(G1i) …link_kind assessment',                                                  'assessment',        $d['link_kind'] );
fx( '2026-01-01 00:00:00', '2026-02-01 00:00:00', 1, false ); $r = $RS(); $d = $D( $r['status'], false, $r['has_report'] );
eq( '(G1j) red-flag Stage-3-done-no-report END-TO-END → DISABLED (nothing to send)', false, $d['enabled'] );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
