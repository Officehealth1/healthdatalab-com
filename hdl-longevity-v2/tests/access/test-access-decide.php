<?php
/**
 * Plan 14, A1: HDLV2_Access::decide( $facts, $section ) is a pure table. Switch on:
 *   full_report  open when a form row exists (a purchase or the practitioner's Confirm made it), else locked_buy;
 *   flight_plan, checkin  open when a final report exists for the form or a plan already exists, else locked_practitioner;
 *   timeline     open by today's rule, or when a grant is stored and not taken back; else locked_buy;
 *   the owning practitioner and an admin: always open.
 * Switch absent: every answer equals today's behaviour. Also: lead_scope() (an admin may Confirm any lead,
 * switch on only) and an unknown section fails closed.
 *
 * Run: php tests/access/test-access-decide.php   Exit: 0 all pass, 1 any fail
 */
require __DIR__ . '/stubs.php';
ac_load( array( 'security/class-hdlv2-access.php' ) );
if ( ! class_exists( 'HDLV2_Access' ) ) { check( 'HDLV2_Access exists', false ); done(); }

$d = function ( array $facts, $section ) { return HDLV2_Access::decide( $facts, $section ); };
$O = HDLV2_Access::OPEN; $B = HDLV2_Access::LOCKED_BUY; $P = HDLV2_Access::LOCKED_PRACTITIONER;
check( 'D.0 the three answers are the plan\'s words', 'open' === $O && 'locked_buy' === $B && 'locked_practitioner' === $P );

foreach ( array( true, false ) as $gate ) {
    $g = $gate ? 'on' : 'absent';
    // full_report: the same both ways (the form needs its row today too).
    check( "D.1 [$g] full_report with a form row → open", $O === $d( array( 'gate' => $gate, 'form_row' => true ), 'full_report' ) );
    check( "D.2 [$g] full_report with no form row → locked_buy", $B === $d( array( 'gate' => $gate, 'form_row' => false ), 'full_report' ) );
    // timeline: today's rule both ways; the grant counts only with the switch on.
    check( "D.3 [$g] timeline owned by today's rule → open", $O === $d( array( 'gate' => $gate, 'timeline_today' => true ), 'timeline' ) );
    check( "D.4 [$g] timeline: a buyer without the \$10 and no grant → locked_buy", $B === $d( array( 'gate' => $gate, 'timeline_today' => false, 'timeline_grant' => false ), 'timeline' ) );
    check( "D.5 [$g] timeline: a buyer without the \$10 but a grant → " . ( $gate ? 'open' : 'locked_buy (today)' ),
        ( $gate ? $O : $B ) === $d( array( 'gate' => $gate, 'timeline_today' => false, 'timeline_grant' => true ), 'timeline' ) );
    foreach ( array( 'flight_plan', 'checkin' ) as $s ) {
        check( "D.6 [$g] $s with a final report → open", $O === $d( array( 'gate' => $gate, 'form_row' => true, 'final_report' => true ), $s ) );
        check( "D.7 [$g] $s with a plan already (a client already running) → open", $O === $d( array( 'gate' => $gate, 'form_row' => true, 'final_report' => false, 'plan' => true ), $s ) );
        check( "D.8 [$g] $s with neither → " . ( $gate ? 'locked_practitioner' : 'open (today)' ),
            ( $gate ? $P : $O ) === $d( array( 'gate' => $gate, 'form_row' => true, 'final_report' => false, 'plan' => false ), $s ) );
    }
    // The owning practitioner and an admin are always open, every section.
    foreach ( array( 'full_report', 'flight_plan', 'checkin', 'timeline' ) as $s ) {
        check( "D.9 [$g] staff on $s → open", $O === $d( array( 'gate' => $gate, 'staff' => true ), $s ) );
    }
}
check( 'D.10 an unknown section fails closed (locked_practitioner), switch on', $P === $d( array( 'gate' => true, 'form_row' => true, 'final_report' => true ), 'consultation' ) );
check( 'D.11 missing facts read as "no": checkin with only the switch → locked_practitioner', $P === $d( array( 'gate' => true ), 'checkin' ) );

// lead_scope: the signed-in id scopes the lead list and Confirm; null = any lead (an admin, switch on).
ac_world();
ac_switch( null );
check( 'L.1 switch absent: an admin is scoped to their own leads (today)', 1 === HDLV2_Access::lead_scope( 1 ) );
check( 'L.2 switch absent: a practitioner is scoped to their own leads', 3 === HDLV2_Access::lead_scope( 3 ) );
ac_switch( '1' );
check( 'L.3 switch on: an admin may Confirm any lead (scope null)', null === HDLV2_Access::lead_scope( 1 ) );
check( 'L.4 switch on: a practitioner stays scoped to their own leads', 3 === HDLV2_Access::lead_scope( 3 ) );
check( 'L.5 switch on: a signed-out caller is scoped to user 0, never "any"', 0 === HDLV2_Access::lead_scope( 0 ) );
$src = file_get_contents( ac_root() . '/includes/sprint-1/class-hdlv2-widget-config.php' );
check( 'L.6 the pending list, the single Confirm, the single Reject and the bulk actions all ask lead_scope()', 4 === substr_count( $src, 'HDLV2_Access::lead_scope( $user_id )' ) );
check( 'L.7 an admin\'s Reject and bulk act for the lead\'s own practitioner, never the admin', false !== strpos( $src, 'self::reject_lead_row( $id, (int) $lead->practitioner_user_id )' ) && false !== strpos( $src, 'self::confirm_lead_row( $lead, (int) $lead->practitioner_user_id, true )' ) );

done();
