<?php
/**
 * Plan 3 (v0.48.82), P3 — who sees the full Timeline. The REAL HDLV2_Healthspan_Timeline::page_data()
 * and HDLV2_Timeline_Upgrade::owned() on the fake database.
 *   Switch on: a Stripe buyer without the upgrade who has a report → preview (own lane, loved ones
 *   locked); with the upgrade → full. A client with no Stripe ticket, a pack seat, another
 *   practitioner or the switch absent → today's page. A revoked ticket counts for nothing.
 *
 * Run:  php scenario-gate.php   (self-asserting, exit 0/1)
 */
require __DIR__ . '/stubs-tl.php';
tl_load();

/** A client with a report; $ticket = null (none) or the stamp. */
function client( $w, $ticket = false, $over = array() ) {
    $p = tl_progress( 41, 'fr1@example.test', '2026-11-10 10:00:00' );
    $w->progress_rows[] = $p;
    $w->report_rows[41] = array( (object) array( 'id' => 7, 'report_type' => 'draft', 'report_content' => '{}' ) );
    if ( false !== $ticket ) {
        $w->seed_ticket( array_merge( array( 'client_email' => 'fr1@example.test', 'timeline_paid_ref' => $ticket ), $over ) );
    }
    return $p;
}
function mode( $p ) { $d = HDLV2_Healthspan_Timeline::page_data( $p->token ); return $d['mode'] ?? 'NONE'; }

echo "── G1. switch on ──\n";
$w = tl_fresh();
$p = client( $w, null );
$d = HDLV2_Healthspan_Timeline::page_data( $p->token );
check( 'G1.1 Stripe buyer, report, no upgrade → preview, locked', 'preview' === ( $d['mode'] ?? '' ) && true === ( $d['lock'] ?? null ) );
check( 'G1.2 their own lane is drawn from their numbers', 0.94 === ( $d['you']['rate0'] ?? null ) );
check( 'G1.3 owned() false', false === HDLV2_Timeline_Upgrade::owned( $p ) );
$w = tl_fresh();
$p = client( $w, 'cs_test_paid' );
check( 'G1.4 with the upgrade → full', 'full' === mode( $p ) && true === HDLV2_Timeline_Upgrade::owned( $p ) );
$w = tl_fresh();
$p = client( $w, 'included' );
check( 'G1.5 included (bought before the switch, or another practitioner) → full', 'full' === mode( $p ) );
$w = tl_fresh();
$p = client( $w, 'included', array( 'external_ref' => 'fam:4:1' ) );
check( 'G1.6 a pack seat → full', 'full' === mode( $p ) );
$w = tl_fresh();
$p = client( $w );
check( 'G1.7 no Stripe ticket (the practitioner\'s own client) → full, as today', 'full' === mode( $p ) && true === HDLV2_Timeline_Upgrade::owned( $p ) );
$w = tl_fresh();
$p = client( $w, 'cs_test_paid', array( 'status' => 'revoked' ) );
$w->seed_ticket( array( 'client_email' => 'fr1@example.test', 'timeline_paid_ref' => null ) );
check( 'G1.8 a revoked stamped ticket counts for nothing beside a live empty one → preview', 'preview' === mode( $p ) );
$w = tl_fresh();
$p = client( $w, null );
$w->seed_ticket( array( 'client_email' => 'FR1@example.test', 'timeline_paid_ref' => 'cs_test_second' ) );
check( 'G1.9 any live stamped ticket for the e-mail (any case) → full', 'full' === mode( $p ) );
$w = tl_fresh();
$p = tl_progress( 41, 'fr1@example.test', '2026-11-10 10:00:00' );
$w->progress_rows[] = $p;
$w->seed_ticket( array( 'client_email' => 'fr1@example.test', 'timeline_paid_ref' => 'cs_test_paid' ) );
check( 'G1.10 the upgrade but no report yet → preview (the report is still the gate)', 'preview' === mode( $p ) );

echo "── G2. switch absent ──\n";
$w = tl_fresh( false );
$p = client( $w, null );
$n = count( $w->queries );
check( 'G2.1 a Stripe buyer with an empty stamp → full, as today', 'full' === mode( $p ) );
check( 'G2.2 owned() is true without reading the tickets', true === HDLV2_Timeline_Upgrade::owned( $p ) && ! array_filter( array_slice( $w->queries, $n ), function ( $q ) { return false !== strpos( $q, 'timeline_paid_ref' ); } ) );
$w = tl_fresh();
unset( $GLOBALS['options']['hdlv2_ff_healthspan_timeline'] );
$p = client( $w, null );
check( 'G2.3 the Timeline page itself off → the upgrade is off too', false === HDLV2_Timeline_Upgrade::enabled() && true === HDLV2_Timeline_Upgrade::owned( $p ) );
done();
