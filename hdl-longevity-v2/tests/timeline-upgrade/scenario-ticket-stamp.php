<?php
/**
 * Plan 3 (v0.48.82), P2 — a Full Report ticket records whether the Timeline came with it.
 * The REAL route, mint(), mint_full_report() and mint_family_seat(), on the plan's stamp table:
 *   switch absent → 'included'; switch on, the offer's practitioner: a Stripe checkout (cs_…)
 *   without the upgrade → empty, with it → the reference; a pack seat (fam:…) → 'included';
 *   another practitioner → 'included'. A repeat changes nothing, except that a repeat which now
 *   says "upgrade" fills an empty stamp once. The answer says timeline_granted.
 *
 * Run:  php scenario-ticket-stamp.php   (self-asserting, exit 0/1)
 */
require __DIR__ . '/stubs-tl.php';
tl_load();

function sale( $over = array() ) {
    $body = array_merge( array( 'practitioner_id' => 122, 'email' => 'fr1@example.test', 'name' => 'Fr One', 'external_ref' => 'cs_test_fr1', 'product' => 'full_report' ), $over );
    $body = array_filter( $body, function ( $v ) { return null !== $v; } );
    return HDL_Stage1_Ticket::handle( new FakeRequest( $body, array( 'X-HDL-Stage1-Ticket-Key' => 'k-test-key' ) ) );
}
function stamp( $w, $ref = 'cs_test_fr1' ) { return isset( $w->tickets[ $ref ] ) ? $w->tickets[ $ref ]->timeline_paid_ref : 'NO TICKET'; }
function inserted( $w ) { return $w->inserts_into( 'hdlv2_widget_invites' ); }

echo "── S1. switch absent ──\n";
$w = tl_fresh( false );
$r = sale();
check( 'S1.1 a Stripe Full Report without the upgrade is stamped included', 'included' === stamp( $w ) );
check( 'S1.2 the answer says timeline_granted true', is_array( $r ) && true === ( $r['timeline_granted'] ?? null ) );
$w = tl_fresh( false );
$r = sale( array( 'timeline' => true ) );
check( 'S1.3 with the upgrade bought: the reference (a payment is recorded whatever the switch)', 'cs_test_fr1' === stamp( $w ) && true === ( $r['timeline_granted'] ?? null ) );

echo "── S2. switch on, the offer's practitioner ──\n";
$w = tl_fresh();
$r = sale();
check( 'S2.1 cs_… without the upgrade → empty stamp', null === stamp( $w ) || '' === stamp( $w ) );
check( 'S2.2 timeline_granted false', is_array( $r ) && false === ( $r['timeline_granted'] ?? null ) );
check( 'S2.3 every key of the old answer is still there, timeline_granted added last', is_array( $r ) && array_keys( $r ) === array( 'token', 'expires_at', 'idempotent', 'open_url', 'stage1_found', 'auto_confirmed', 'timeline_granted' ) );
$w = tl_fresh();
$r = sale( array( 'timeline' => true ) );
check( 'S2.4 cs_… with the upgrade → the reference itself', 'cs_test_fr1' === stamp( $w ) );
check( 'S2.5 timeline_granted true', true === ( $r['timeline_granted'] ?? null ) );
$w = tl_fresh();
$r = sale( array( 'timeline' => 'yes please' ) );
check( 'S2.6 a timeline value that is not a boolean is refused (400 naming timeline)', 400 === status_of( $r ) && in_array( 'timeline', $r->data['invalid_fields'] ?? array(), true ) && ! inserted( $w ) );
$w = tl_fresh();
$r = sale( array( 'timeline' => false ) );
check( 'S2.7 timeline false → as absent: empty stamp', ( null === stamp( $w ) || '' === stamp( $w ) ) && false === ( $r['timeline_granted'] ?? null ) );

echo "── S3. pack seat, dashboard, another practitioner ──\n";
$w = tl_fresh();
$t = HDL_Stage1_Ticket::mint( 122, 'seat@example.test', 'Seat', 'fam:4:1', 90, 'full_report' );
check( 'S3.1 a family pack seat (fam:…) → included', 'included' === stamp( $w, 'fam:4:1' ) );
$w = tl_fresh();
HDL_Stage1_Ticket::mint( 122, 'd@example.test', 'D', 'dash:7:abc', 90, 'full_report' );
check( 'S3.2 a reference that is not a Stripe checkout → included', 'included' === stamp( $w, 'dash:7:abc' ) );
$w = tl_fresh();
$w->config->practitioner_user_id = 206;
$r = sale( array( 'practitioner_id' => 206 ) );
check( 'S3.3 another practitioner (nothing is sold there) → included, granted true', 'included' === stamp( $w ) && true === ( $r['timeline_granted'] ?? null ) );

echo "── S4. repeats ──\n";
$w = tl_fresh();
sale();
$n_ins = count( $w->inserts );
$n_q   = count( $w->stamps );
$r = sale();
check( 'S4.1 the same reference again: nothing new, stamp still empty, idempotent', count( $w->inserts ) === $n_ins && count( $w->stamps ) === $n_q && ( null === stamp( $w ) || '' === stamp( $w ) ) && true === ( $r['idempotent'] ?? null ) );
$r = sale( array( 'timeline' => true ) );
check( 'S4.2 a repeat that now says "upgrade" fills the empty stamp once', 'cs_test_fr1' === stamp( $w ) && 1 === count( $w->stamps ) && true === ( $r['timeline_granted'] ?? null ) );
$r = sale( array( 'timeline' => true ) );
check( 'S4.3 a third delivery writes nothing more', 1 === count( $w->stamps ) && true === ( $r['timeline_granted'] ?? null ) );
$w = tl_fresh();
sale( array( 'timeline' => true ) );
$r = sale();
check( 'S4.4 a repeat without "upgrade" never empties a filled stamp', 'cs_test_fr1' === stamp( $w ) && true === ( $r['timeline_granted'] ?? null ) );

echo "── S5. the column is not there yet (DB before 3.34) ──\n";
$w = tl_fresh( true, '3.33' );
$r = sale( array( 'timeline' => true ) );
check( 'S5.1 an upgrade cannot be recorded yet → 503 not_ready (Stripe sends again later)', 503 === status_of( $r ) && 'not_ready' === code_of( $r ) && ! inserted( $w ) );
$w = tl_fresh( true, '3.33' );
$r = sale();
$row = inserted( $w )[0]['data'] ?? array();
check( 'S5.2 without the upgrade the sale goes through as today, without the new column', is_array( $r ) && ! empty( $r['token'] ) && ! array_key_exists( 'timeline_paid_ref', $row ) );

echo "── S6. other kinds of ticket never carry the column ──\n";
$w = tl_fresh();
$w->config->access_mode = 'paid';
HDL_Stage1_Ticket::mint( 122, 's1@example.test', 'S1', 'cs_test_s1', 90, 'paid_stage1' );
HDL_Stage1_Ticket::mint( 122, 'ch@example.test', 'Ch', 'cs_test_ch', 90, 'challenge' );
$rows = inserted( $w );
check( 'S6.1 a Stage 1 and a challenge ticket: no timeline_paid_ref in the insert', 2 === count( $rows ) && ! array_key_exists( 'timeline_paid_ref', $rows[0]['data'] ) && ! array_key_exists( 'timeline_paid_ref', $rows[1]['data'] ) );

echo "── S7. Phase AO (DB 3.34) in the activator ──\n";
$act = file_get_contents( __DIR__ . '/../../includes/class-hdlv2-activator.php' );
$main = file_get_contents( __DIR__ . '/../../hdl-longevity-v2.php' );
check( 'S7.1 the table definition has timeline_paid_ref VARCHAR(128) NULL', (bool) preg_match( '/external_ref VARCHAR\(128\) DEFAULT NULL,\s*timeline_paid_ref VARCHAR\(128\) DEFAULT NULL,/', $act ) );
check( 'S7.2 Phase AO adds the column behind an INFORMATION_SCHEMA check, verifies it, then stamps the old Full Report rows included',
    (bool) preg_match( "/version_compare\( \\\$current_db_version, '3\.34', '<' \)/", $act )
    && false !== strpos( $act, 'ADD COLUMN timeline_paid_ref VARCHAR(128) DEFAULT NULL' )
    && (bool) preg_match( "/SET timeline_paid_ref = 'included'\s+WHERE source = 'full_report' AND \(timeline_paid_ref IS NULL OR timeline_paid_ref = ''\)/", $act ) );
check( 'S7.3 HDLV2_DB_VERSION is 3.34', false !== strpos( $main, "define( 'HDLV2_DB_VERSION', '3.34' );" ) );
done();
