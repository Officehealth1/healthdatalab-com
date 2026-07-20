<?php
/**
 * P1b — resend_link_descriptor(): pure status → resend-behaviour mapping.
 *
 * The stage-aware branching the whole route hangs on. Standalone, no WP/DB/net:
 * loads HDLV2_Client_Status with the minimal stubs it needs at file scope and
 * exercises the pure static descriptor for every status + the COMPLETE
 * plan-vs-report split.
 *
 * Contract — resend_link_descriptor( string $status, bool $has_active_plan ):
 *   array{ enabled:bool, stage:?int, link_kind:string, label:string, tooltip:string }
 *   - enabled=false rows carry an empty stage/link_kind/label and a human tooltip.
 *   - link_kind ∈ { 'assessment', 'report', 'plan', '' }.
 *   - COMPLETE names the ACTUAL artefact (D2): plan → "Resend flight plan",
 *     no plan → "Resend report". Never a generic "Resend plan".
 *
 * Run:  php tests/resend-link/scenario-descriptor.php   (exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
define( 'ABSPATH', __DIR__ . '/' );

// Minimal stubs so the class file loads (methods aren't called here).
function add_action() {}
function get_current_user_id() { return 0; }
function rest_ensure_response( $x ) { return $x; }
class HDLV2_Compatibility {
    public static function is_practitioner( $u ) { return true; }
    public static function practitioner_owns_client( $p, $c ) { return true; }
}

require __DIR__ . '/../../includes/sprint-4/class-hdlv2-client-status.php';

$pass = 0; $fail = 0;
function eq( $name, $expected, $actual ) {
    global $pass, $fail;
    $ok = ( $expected === $actual );
    echo ( $ok ? 'PASS' : 'FAIL' ) . " | $name" . ( $ok ? '' : " | expected " . json_encode( $expected ) . " got " . json_encode( $actual ) ) . "\n";
    $ok ? $pass++ : $fail++;
}

$D = function ( $status, $has_plan = false ) {
    return HDLV2_Client_Status::resend_link_descriptor( $status, $has_plan );
};

// ── Enabled assessment stages ──
$s1 = $D( 'not_started' );
eq( 'not_started enabled',    true,         $s1['enabled'] );
eq( 'not_started stage',      1,            $s1['stage'] );
eq( 'not_started link_kind',  'assessment', $s1['link_kind'] );
eq( 'not_started label',      'Send assessment link', $s1['label'] );

$s2 = $D( 'low_data' );
eq( 'low_data enabled',   true,          $s2['enabled'] );
eq( 'low_data stage',     2,             $s2['stage'] );
eq( 'low_data link_kind', 'assessment',  $s2['link_kind'] );
eq( 'low_data label',     'Send Stage-2 link', $s2['label'] );

$s3 = $D( 'stage3_in_progress' );
eq( 'stage3 enabled',   true,         $s3['enabled'] );
eq( 'stage3 stage',     3,            $s3['stage'] );
eq( 'stage3 link_kind', 'assessment', $s3['link_kind'] );
eq( 'stage3 label',     'Send Stage-3 link', $s3['label'] );  // D5: never flight-plan wording here

// ── Disabled: blocked on the practitioner ──
$awr = $D( 'awaiting_why_release' );
eq( 'awaiting_why_release disabled', false, $awr['enabled'] );
eq( 'awaiting_why_release tooltip',  'Waiting on you to release Stage 3 — nothing to send', $awr['tooltip'] );
eq( 'awaiting_why_release no link',  '',    $awr['link_kind'] );

$ac = $D( 'awaiting_consult' );
eq( 'awaiting_consult disabled', false, $ac['enabled'] );
eq( 'awaiting_consult tooltip',  'Waiting on your consultation — nothing to send', $ac['tooltip'] );

// ── COMPLETE: names the ACTUAL artefact (D2) ──
foreach ( array( 'active', 'progress_normal', 'needs_attention', 'inactive' ) as $st ) {
    $plan = $D( $st, true );
    eq( "$st + plan enabled",    true,   $plan['enabled'] );
    eq( "$st + plan link_kind",  'plan', $plan['link_kind'] );
    eq( "$st + plan label",      'Resend flight plan', $plan['label'] );

    $rep = $D( $st, false );
    eq( "$st no-plan link_kind", 'report', $rep['link_kind'] );
    eq( "$st no-plan label",     'Resend report', $rep['label'] );
}

// ── Unknown status fails closed (disabled) ──
$u = $D( 'some_future_status' );
eq( 'unknown disabled', false, $u['enabled'] );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
