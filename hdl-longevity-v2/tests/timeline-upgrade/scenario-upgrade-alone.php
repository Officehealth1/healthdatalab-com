<?php
/**
 * Plan 3 (v0.48.82), P2/P7 — POST /hdl/v1/stage1-ticket with product "timeline": the $10 (or $27)
 * Timeline payment. No ticket is made: the buyer's newest live Full Report ticket gets the
 * payment's reference. Answer { granted, idempotent, already_owned, has_full_report, late,
 * ends_on, open_url }. The REAL route on the fake database.
 *
 * Run:  php scenario-upgrade-alone.php   (self-asserting, exit 0/1)
 */
require __DIR__ . '/stubs-tl.php';
tl_load();

const KEYS = array( 'granted', 'idempotent', 'already_owned', 'has_full_report', 'late', 'ends_on', 'open_url' );
function pay( $over = array(), $key = 'k-test-key' ) {
    $body = array_merge( array( 'practitioner_id' => 122, 'email' => 'fr1@example.test', 'name' => 'Fr One', 'external_ref' => 'cs_test_tl1', 'product' => 'timeline' ), $over );
    return HDL_Stage1_Ticket::handle( new FakeRequest( $body, array( 'X-HDL-Stage1-Ticket-Key' => $key ) ) );
}
/** Sent the form $days ago, London time, at 10:00; a draft report row. */
function buyer( $w, $days_ago, $stamp = null, $with_report = true ) {
    $sent = null === $days_ago ? null : ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( "-$days_ago days" )->setTime( 10, 0 )->format( 'Y-m-d H:i:s' );
    $p = tl_progress( 41, 'fr1@example.test', $sent );
    $w->progress_rows[] = $p;
    if ( $with_report && null !== $sent ) {
        $w->report_rows[41] = array( (object) array( 'id' => 7, 'report_type' => 'draft', 'report_content' => '{}' ) );
    }
    return array( $p, $w->seed_ticket( array( 'client_email' => 'fr1@example.test', 'timeline_paid_ref' => $stamp ) ) );
}
$LOG = tempnam( sys_get_temp_dir(), 'tl' );
ini_set( 'error_log', $LOG );

echo "── U1. a buyer with an empty stamp ──\n";
$w = tl_fresh();
list( $p, $t ) = buyer( $w, 2 );
$r = pay();
check( 'U1.1 200 with exactly the seven keys', is_array( $r ) && array_keys( $r ) === KEYS );
check( 'U1.2 granted, not idempotent, not already owned, has a Full Report, not late', true === $r['granted'] && false === $r['idempotent'] && false === $r['already_owned'] && true === $r['has_full_report'] && false === $r['late'] );
check( 'U1.3 the stamp is this payment\'s reference, on that ticket, once', 'cs_test_tl1' === $t->timeline_paid_ref && array( $t->id . '=cs_test_tl1' ) === $w->stamps );
check( 'U1.4 open_url is the Timeline address of their row', 'https://stby.example.test/healthspan-timeline/?t=' . $p->token === $r['open_url'] );
$end = ( new DateTimeImmutable( $p->stage3_completed_at, wp_timezone() ) )->modify( '+7 days' )->format( 'Y-m-d' );
check( 'U1.5 ends_on is the offer\'s last day', $end === $r['ends_on'] );
check( 'U1.6 no ticket made', ! $w->inserts_into( 'hdlv2_widget_invites' ) );

echo "── U2. the same payment again ──\n";
$r = pay();
check( 'U2.1 granted, idempotent, nothing written', true === $r['granted'] && true === $r['idempotent'] && false === $r['already_owned'] && 1 === count( $w->stamps ) );

echo "── U3. already owned ──\n";
$w = tl_fresh();
list( $p, $t ) = buyer( $w, 2, 'cs_test_other' );
$r = pay();
check( 'U3.1 stamped by another payment → already_owned, granted (it is open), nothing written', true === $r['already_owned'] && true === $r['granted'] && false === $r['idempotent'] && ! $w->stamps && 'cs_test_other' === $t->timeline_paid_ref );
$w = tl_fresh();
list( $p, $t ) = buyer( $w, 2, 'included' );
$r = pay();
check( 'U3.2 included with the Full Report → already_owned, nothing written', true === $r['already_owned'] && ! $w->stamps );
$w = tl_fresh();
$w->progress_rows[] = tl_progress( 41, 'fr1@example.test', '2026-11-01 10:00:00' );
$w->report_rows[41] = array( (object) array( 'id' => 7, 'report_type' => 'draft', 'report_content' => '{}' ) );
$r = pay();
check( 'U3.3 a client with a report and no Stripe ticket → already_owned, has_full_report, nothing written', true === $r['already_owned'] && true === $r['granted'] && true === $r['has_full_report'] && ! $w->stamps );
$w = tl_fresh();
$w->seed_ticket( array( 'client_email' => 'fr1@example.test', 'timeline_paid_ref' => 'cs_test_old', 'status' => 'revoked' ) );
list( $p, $t ) = buyer( $w, 2 );
$r = pay();
check( 'U3.4 a revoked ticket\'s stamp counts for nothing: the live ticket is stamped', true === $r['granted'] && false === $r['already_owned'] && 'cs_test_tl1' === $t->timeline_paid_ref );

echo "── U4. nobody on file ──\n";
$w = tl_fresh();
$r = pay();
check( 'U4.1 200, granted false, has_full_report false, open_url null', is_array( $r ) && false === $r['granted'] && false === $r['has_full_report'] && null === $r['open_url'] && ! $w->stamps );
$w = tl_fresh();
$w->seed_ticket( array( 'client_email' => 'fr1@example.test', 'status' => 'revoked' ) );
$r = pay();
check( 'U4.2 only a revoked ticket → nobody on file', false === $r['granted'] && false === $r['has_full_report'] );

echo "── U5. the form not sent yet ──\n";
$w = tl_fresh();
list( $p, $t ) = buyer( $w, null );
$r = pay();
check( 'U5.1 granted, has_full_report, not late, no end date', true === $r['granted'] && true === $r['has_full_report'] && false === $r['late'] && null === $r['ends_on'] && 'cs_test_tl1' === $t->timeline_paid_ref );

echo "── U6. after the buyer's date ──\n";
$w = tl_fresh();
list( $p, $t ) = buyer( $w, 9 );
$r = pay();
check( 'U6.1 late true and still granted', true === $r['late'] && true === $r['granted'] && 'cs_test_tl1' === $t->timeline_paid_ref );
$w = tl_fresh();
list( $p, $t ) = buyer( $w, 7 );
$r = pay();
check( 'U6.2 day 7 (inside the last day) → not late', false === $r['late'] );

echo "── U7. the switch does not decide what was paid ──\n";
$w = tl_fresh( false );
list( $p, $t ) = buyer( $w, 2 );
$r = pay();
check( 'U7.1 switch absent: the payment is still recorded', true === $r['granted'] && 'cs_test_tl1' === $t->timeline_paid_ref );

echo "── U8. refusals, as the route answers today ──\n";
$w = tl_fresh();
check( 'U8.1 bad key → 401', 401 === status_of( pay( array(), 'wrong' ) ) );
check( 'U8.2 bad e-mail → 400 naming email', in_array( 'email', pay( array( 'email' => 'nope' ) )->data['invalid_fields'] ?? array(), true ) );
check( 'U8.3 bad reference → 400 naming external_ref', in_array( 'external_ref', pay( array( 'external_ref' => 'cs test' ) )->data['invalid_fields'] ?? array(), true ) );
$w = tl_fresh( true, '3.33' );
$r = pay();
check( 'U8.4 before DB 3.34 → 503 not_ready', 503 === status_of( $r ) && 'not_ready' === code_of( $r ) );

echo "── U9. the log ──\n";
$log = (string) file_get_contents( $LOG );
check( 'U9.1 one line per answer, codes only: no e-mail address, no sign-in address', false !== strpos( $log, 'timeline' ) && false === strpos( $log, '@' ) && false === strpos( $log, '?t=' ) );
@unlink( $LOG );
done();
