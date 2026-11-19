<?php
/**
 * Plan 3 (v0.48.82), § 6.6 — the Timeline page's lock lines, switch on and absent, through the REAL
 * page_data(). An owner gets no price. The dashboard tile: scenario-surfaces-dashboard.php.
 *
 * Run:  php scenario-surfaces.php   (self-asserting, exit 0/1)
 */
require __DIR__ . '/stubs-tl.php';
tl_load();

function page( $w, $sent, $stamp = false, $report = true ) {
    $p = tl_progress( 41, 'fr1@example.test', $sent );
    $w->progress_rows[] = $p;
    if ( $report ) {
        $w->report_rows[41] = array( (object) array( 'id' => 7, 'report_type' => 'draft', 'report_content' => '{}' ) );
    }
    if ( false !== $stamp ) {
        $w->seed_ticket( array( 'client_email' => 'fr1@example.test', 'timeline_paid_ref' => $stamp ) );
    }
    return HDLV2_Healthspan_Timeline::page_data( $p->token );
}
function recent() { return ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-2 days' )->format( 'Y-m-d H:i:s' ); }
function old()    { return ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-9 days' )->format( 'Y-m-d H:i:s' ); }
function ends_label( $sent ) { return ( new DateTimeImmutable( $sent, wp_timezone() ) )->modify( '+7 days' )->format( 'j F Y' ); }
$UP = 'https://buy.stripe.example.test/upgrade?locked_prefilled_email=fr1%40example.test';

echo "── P1. a buyer with a report and no Timeline ──\n";
$w = tl_fresh();
$sent = recent();
$d = page( $w, $sent, null );
check( 'P1.1 inside the days: the upgrade line with the date', 'Your loved ones unlock with the Timeline upgrade: $10 until ' . ends_label( $sent ) . '.' === ( $d['offer']['line'] ?? '' ) );
check( 'P1.2 button "Upgrade for $10" to the upgrade link, e-mail locked', 'Upgrade for $10' === ( $d['offer']['button'] ?? '' ) && $UP === ( $d['offer']['url'] ?? '' ) );
check( 'P1.3 no waiting line, and the sample note names the upgrade', empty( $d['offer']['wait'] ) && ! isset( $d['wait_line'] ) && 'Sample people. Your own unlock with the Timeline upgrade.' === ( $d['lock_note'] ?? '' ) );
$w = tl_fresh();
$d = page( $w, old(), null );
check( 'P1.4 after the date: the Healthspan Timeline at $27, button "Get the Timeline", the alone link',
    'Your loved ones unlock with the Healthspan Timeline ($27).' === ( $d['offer']['line'] ?? '' ) && 'Get the Timeline' === ( $d['offer']['button'] ?? '' ) && 0 === strpos( $d['offer']['url'] ?? '', 'https://buy.stripe.example.test/alone?' ) );
$w = tl_fresh();
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'timeline_upgrade_url' => 'https://buy.stripe.example.test/upgrade' );
$d = page( $w, old(), null );
check( 'P1.5 after the date with no alone link: the line without a price or button', 'Your loved ones unlock with the Healthspan Timeline.' === ( $d['offer']['line'] ?? '' ) && empty( $d['offer']['button'] ) );

echo "── P2. the form not sent yet (a ticket with an empty stamp) ──\n";
$w = tl_fresh();
$d = page( $w, null, null, false );
check( 'P2.1 "($10)" line, button "Upgrade for $10", in place of the waiting line',
    'Your loved ones unlock with the Timeline upgrade ($10).' === ( $d['offer']['line'] ?? '' ) && 'Upgrade for $10' === ( $d['offer']['button'] ?? '' ) && empty( $d['offer']['wait'] ) );

echo "── P3. no Full Report: the offer's practitioner, and the open page ──\n";
$w = tl_fresh();
$p = tl_progress( 41, 'fr1@example.test', null );
$w->progress_rows[] = $p;
$d = HDLV2_Healthspan_Timeline::page_data( $p->token );
check( 'P3.1 a client with no Full Report → "$37 together", the button unchanged',
    'Your loved ones unlock with the Full Report and the Timeline upgrade ($37 together).' === ( $d['offer']['line'] ?? '' ) && 'Get the full report' === ( $d['offer']['button'] ?? '' ) );
$d = HDLV2_Healthspan_Timeline::page_data( '' );
check( 'P3.2 the open page (no token) → the same line', 'Your loved ones unlock with the Full Report and the Timeline upgrade ($37 together).' === ( $d['offer']['line'] ?? '' ) );
check( 'P3.3 no lock_note for someone who has not bought (the script keeps its own line)', ! isset( $d['lock_note'] ) );

echo "── P4. an owner and the others: no price ──\n";
foreach ( array( 'upgrade' => 'cs_test_paid', 'included' => 'included' ) as $what => $stamp ) {
    $w = tl_fresh();
    $d = page( $w, recent(), $stamp );
    check( "P4 ($what) full page, no lock_note, no price in the lock line", 'full' === $d['mode'] && ! isset( $d['lock_note'] ) && false === strpos( json_encode( $d['offer'] ), '$10' ) );
}
$w = tl_fresh();
$d = page( $w, recent() );
check( 'P4 (no Stripe ticket) full page, no upgrade words', 'full' === $d['mode'] && false === strpos( json_encode( $d ), 'upgrade' ) );

echo "── P5. switch absent: today's words ──\n";
$w = tl_fresh( false );
$d = page( $w, null, null, false );
check( 'P5.1 a buyer whose form is not sent reads the waiting line', HDLV2_Healthspan_Timeline::WAIT_LINES['bought'] === ( $d['offer']['line'] ?? '' ) && ! empty( $d['offer']['wait'] ) );
$w = tl_fresh( false );
$d = HDLV2_Healthspan_Timeline::page_data( '' );
check( 'P5.2 the open page reads "the full report (today $27)"', 'Your loved ones unlock with the full report (today $27).' === ( $d['offer']['line'] ?? '' ) && ! isset( $d['lock_note'] ) );
$w = tl_fresh( false );
$d = page( $w, recent(), null );
check( 'P5.3 a buyer with a report: full, as today', 'full' === $d['mode'] );

echo "── P6. the page script ──\n";
$js = file_get_contents( __DIR__ . '/../../assets/js/hdlv2-healthspan-timeline.js' );
check( 'P6.1 the sample note takes the server\'s lock_note in preview, its own line otherwise',
    false !== strpos( $js, "(mode === 'preview' ? (S.lockNote || 'Sample people. Your own unlock with the Full Report.')" ) );
done();
