<?php
/**
 * Plan 14 fix row C3: a block belongs to the client's live form row. A client removed and invited again
 * (a new form row) starts a new block at week 1 and sees no review of the old one; the old block was closed
 * by the practitioner's delete (fix C1), and even an old block left open is not the client's current one.
 *
 * Run: php tests/flight-cycle/scenario-reinvited.php   Exit: 0 all pass, 1 any fail
 */
require __DIR__ . '/fc-world.php';
$C = 'HDLV2_Flight_Cycle';

/** Rosa's first life: form 14, block 1 with a review. Removed (form 14 deleted), invited again: form 15, its own final report. */
function ri_world( $old_status ) {
    $w = sw_world();
    $w->t['flight_cycles'][] = array( 'id' => 80, 'client_id' => 13, 'practitioner_id' => 3, 'form_progress_id' => 14, 'cycle_number' => 1, 'start_week' => '2026-10-12', 'end_date' => '2026-11-22',
        'status' => $old_status, 'outline' => 'Week 1: Old aim.', 'review' => 'Review of the first life.', 'closed_at' => 'closed' === $old_status ? '2026-11-20 09:00:00' : null );
    $w->t['form_progress'][0]['deleted_at'] = '2026-11-20 09:00:00';
    $w->t['form_progress'][] = array( 'id' => 15, 'client_user_id' => 13, 'practitioner_user_id' => 3, 'client_name' => 'Rosa Vale', 'deleted_at' => null, 'token_expires_at' => '2027-02-01 00:00:00' );
    $w->t['reports'][] = array( 'id' => 71, 'form_progress_id' => 15, 'report_type' => 'final', 'status' => 'ready' );
    return $w;
}

foreach ( array( 'closed' => 'closed by the delete', 'active' => 'left open' ) as $st => $label ) {
    $w = ri_world( $st );
    check( "R.1 old block $label: it is not the client's current block", null === $C::current( 13 ) );
    $p = $C::before_plan( 13, 3, '2026-11-23', 'final_report', $ctx );
    $c = cyc( $w );
    check( "R.2 old block $label: the first plan of the new life starts a new block at week 1, on form 15", is_array( $p ) && 1 === $p['week'] && 2 === count( $c ) && 15 === (int) $c[1]['form_progress_id'] && 'active' === $c[1]['status'] );
    check( "R.3 old block $label: a new outline, not the old one", false !== strpos( $p['block'], 'Walk after lunch' ) && false === strpos( $p['block'], 'Old aim' ) );
    $w->t['flight_plans'][] = array( 'id' => 660, 'client_id' => 13, 'cycle_id' => $c[1]['id'], 'cycle_week' => 1, 'week_start' => '2026-11-23', 'effective_start_day' => 'monday', 'status' => 'active', 'deleted_at' => null );
    $v = $C::plan_view( (object) end( $w->t['flight_plans'] ) );
    check( "R.4 old block $label: the week-1 page shows no review of the first life", null === $v['review'] && '' === $v['notice'] );
    check( "R.5 old block $label: the panel shows no review of the first life", false === strpos( $C::panel_control( 13 ), 'Review of the first life.' ) );
}
done();
