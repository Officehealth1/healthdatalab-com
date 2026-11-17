<?php
/**
 * Plan 3 (v0.48.82), § 6.6 — the dashboard's Timeline tile and its panel, switch on and absent,
 * through the REAL HDLV2_Products::for_client() and render_locked() (the dashboard-products harness).
 *
 * Run:  php scenario-surfaces-dashboard.php   (self-asserting, exit 0/1)
 */
date_default_timezone_set( 'UTC' );
require __DIR__ . '/../dashboard-products/stubs.php';
function wp_timezone() { return new DateTimeZone( 'Europe/London' ); }
function is_email( $x ) { return is_string( $x ) && filter_var( $x, FILTER_VALIDATE_EMAIL ) ? $x : false; }
if ( file_exists( $P . 'sprint-2c/class-hdlv2-timeline-upgrade.php' ) ) {
    require_once $P . 'sprint-2c/class-hdlv2-timeline-upgrade.php';
}

/** The products harness plus the tickets' Timeline stamps (lower e-mail => list of rows). */
class TlProductsWpdb extends ProductsWpdb {
    public $tl = array();
    private $tl_args = array();
    public function prepare( $sql, ...$args ) { $this->tl_args = $args; return parent::prepare( $sql, ...$args ); }
    public function get_results( $sql ) {
        if ( false !== strpos( $sql, 'timeline_paid_ref' ) && false !== strpos( $sql, "status <> 'revoked'" ) ) {
            return $this->tl[ strtolower( (string) ( $this->tl_args[0] ?? '' ) ) ] ?? array();
        }
        return parent::get_results( $sql );
    }
}
function tl_dash( $switch = true ) {
    global $wpdb;
    fresh();
    $wpdb = new TlProductsWpdb();
    if ( $switch ) {
        $GLOBALS['options']['hdlv2_ff_timeline_upgrade'] = 1;
    }
    $GLOBALS['options']['hdlv2_stage1_offer'] = array(
        'timeline_upgrade_url' => 'https://buy.stripe.example.test/upgrade',
        'timeline_alone_url'   => 'https://buy.stripe.example.test/alone',
    );
    return $wpdb;
}
/** A Stripe buyer: client $uid at practitioner $prac, the form sent $days ago (null = not sent), a report when sent. */
function buyer( $w, $uid, $days, $stamp = null, $prac = 122 ) {
    $sent = null === $days ? null : ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( "-$days days" )->setTime( 10, 0 )->format( 'Y-m-d H:i:s' );
    client( $uid, $prac, null === $sent ? 2 : 3, array( 'stage3_completed_at' => $sent ) );
    if ( null !== $sent ) {
        $w->reports[ 300 + $uid ] = (object) array( 'id' => 9, 'report_type' => 'draft' );
    }
    $w->tickets[ "c$uid@stby.test" ] = (object) array( 'practitioner_id' => $prac );
    $w->tl[ "c$uid@stby.test" ] = array( (object) array( 'id' => 70 + $uid, 'practitioner_id' => $prac, 'external_ref' => 'cs_test_' . $uid, 'timeline_paid_ref' => $stamp ) );
    return $sent;
}
function short_end( $sent ) { return ( new DateTimeImmutable( $sent, wp_timezone() ) )->modify( '+7 days' )->format( 'j M' ); }
$NEW_LINE = 'An upgrade to your Full Report. A family pack adds seats for the people you choose.';

echo "── D1. switch on, a buyer with a report and no Timeline ──\n";
$w = tl_dash();
$sent = buyer( $w, 601, 2 );
$items = by_key( HDLV2_Products::for_client( 601 ) );
$tl = $items['timeline'];
check( 'D1.1 tile: not yet, "Upgrade · $10 until <j M>", priced $10', 'not-yet' === $tl['state'] && 'Upgrade · $10 until ' . short_end( $sent ) === $tl['note'] && '$10' === $tl['price'] );
check( 'D1.2 the tile\'s address is the upgrade link with the e-mail locked', 'https://buy.stripe.example.test/upgrade?locked_prefilled_email=c601%40stby.test' === $tl['url'] );
$h = HDLV2_Products::render_locked( $tl, $items['full_report'], 'Pat' );
check( 'D1.3 panel button "Upgrade to the Timeline · $10 until <j M>", to the upgrade link', false !== strpos( $h, '>Upgrade to the Timeline · $10 until ' . short_end( $sent ) . '</a>' ) && false !== strpos( $h, 'href="https://buy.stripe.example.test/upgrade?locked_prefilled_email=c601%40stby.test"' ) );
check( 'D1.4 panel second line: "An upgrade to your Full Report. …"', false !== strpos( $h, esc_html( $NEW_LINE ) ) && false === strpos( $h, 'It comes with your Full Report' ) );
check( 'D1.5 the Full Report tile is untouched (yours)', 'yours' === $items['full_report']['state'] );

echo "── D2. after the date ──\n";
$w = tl_dash();
buyer( $w, 602, 9 );
$items = by_key( HDLV2_Products::for_client( 602 ) );
$tl = $items['timeline'];
check( 'D2.1 tile note "$27", priced $27, the alone link', '$27' === $tl['note'] && '$27' === $tl['price'] && 0 === strpos( $tl['url'], 'https://buy.stripe.example.test/alone?' ) );
check( 'D2.2 panel button "Get the Timeline · $27"', false !== strpos( HDLV2_Products::render_locked( $tl, $items['full_report'], 'Pat' ), '>Get the Timeline · $27</a>' ) );

echo "── D3. no report yet ──\n";
$w = tl_dash();
buyer( $w, 603, null );
$tl = by_key( HDLV2_Products::for_client( 603 ) )['timeline'];
check( 'D3.1 "An upgrade to your Full Report", no price', 'not-yet' === $tl['state'] && 'An upgrade to your Full Report' === $tl['note'] && null === $tl['price'] );

echo "── D4. owners, other practitioners, switch absent ──\n";
$w = tl_dash();
buyer( $w, 604, 2, 'cs_test_paid' );
$tl = by_key( HDLV2_Products::for_client( 604 ) )['timeline'];
check( 'D4.1 an owner: yours, as today, no price', 'yours' === $tl['state'] && null === $tl['price'] && 'Ready' === $tl['note'] );
$w = tl_dash();
buyer( $w, 605, null, null, 130 );
$tl = by_key( HDLV2_Products::for_client( 605 ) )['timeline'];
check( 'D4.2 another practitioner\'s client, no report: today\'s "Comes with your Full Report"', 'Comes with your Full Report' === $tl['note'] );
$w = tl_dash( false );
buyer( $w, 606, null );
$items = by_key( HDLV2_Products::for_client( 606 ) );
check( 'D4.3 switch absent, no report: "Comes with your Full Report"', 'Comes with your Full Report' === $items['timeline']['note'] );
check( 'D4.4 switch absent: the panel keeps "It comes with your Full Report."', false !== strpos( HDLV2_Products::render_locked( $items['timeline'], $items['full_report'], 'Pat' ), 'It comes with your Full Report.' ) );
$w = tl_dash( false );
buyer( $w, 607, 2 );
check( 'D4.5 switch absent, report, empty stamp: yours, as today', 'yours' === by_key( HDLV2_Products::for_client( 607 ) )['timeline']['state'] );
done();
