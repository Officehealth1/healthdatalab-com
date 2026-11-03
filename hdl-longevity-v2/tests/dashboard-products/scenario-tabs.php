<?php
/**
 * Plan E3 Stories 1 and 2 (v0.48.38): the one row of seven tabs and the panel a locked tab opens.
 *   php tests/dashboard-products/scenario-tabs.php
 */
require __DIR__ . '/stubs.php';

if ( ! class_exists( 'HDLV2_Products' ) || ! method_exists( 'HDLV2_Products', 'render_tabs' ) ) {
    check( 'HDLV2_Products::render_tabs() exists', false );
    done();
}

$ORDER = array( 'today', 'stage1', 'report', 'timeline', 'flight_plan', 'challenge', 'messages' );
$OFFER = 'https://altituding.com/full-report?code=YOURCODE';

/** The pill text of one tab ('' when the tab has none). */
function pill( $html, $key ) {
    if ( ! preg_match( '#<button[^>]*data-tab="' . $key . '"[^>]*>(.*?)</button>#s', $html, $m ) ) return null;
    return preg_match( '#<span class="cdp-tab-state is-([a-z-]+)">([^<]*)</span>#', $m[1], $p ) ? $p[1] . ':' . $p[2] : '';
}

echo "T1 render_tabs: one row of seven buttons\n";
$wpdb = fresh();
client( 502, 122, 1, array( 'stage1_pdf_url' => 'https://stby.test/?hdlv2_stage1_pdf=802', 'stage1_pdf_stored_path' => 'stage1/802.pdf' ) );
$kim = HDLV2_Products::for_client( 502 );
$h   = HDLV2_Products::render_tabs( $kim );
preg_match_all( '#<button type="button" class="cdp-tab[^"]*" data-tab="([a-z0-9_]+)" role="tab" aria-selected="(true|false)"#', $h, $m );
check( 'T1.1 seven buttons, keys in TAB_ORDER', $m[1] === $ORDER && HDLV2_Products::TAB_ORDER === $ORDER );
check( 'T1.2 exactly one aria-selected="true", on Today by default', 1 === substr_count( $h, 'aria-selected="true"' ) && 'true' === $m[2][0] && false !== strpos( $h, 'class="cdp-tab active" data-tab="today"' ) );
$r = HDLV2_Products::render_tabs( $kim, 'report' );
check( 'T1.3 $active moves the selection (one only)', 1 === substr_count( $r, 'aria-selected="true"' ) && false !== strpos( $r, 'class="cdp-tab active" data-tab="report"' ) );
check( 'T1.4 Kim (Stage 1 only): Stage 1 Yours, the other four Not yet',
    'yours:Yours' === pill( $h, 'stage1' ) && 'not-yet:Not yet' === pill( $h, 'report' ) && 'not-yet:Not yet' === pill( $h, 'timeline' )
    && 'not-yet:Not yet' === pill( $h, 'flight_plan' ) && 'not-yet:Not yet' === pill( $h, 'challenge' ) );
check( 'T1.5 Today and Messages carry no pill', '' === pill( $h, 'today' ) && '' === pill( $h, 'messages' ) );
check( 'T1.6 the row is buttons only: no link, no href, no target', false === strpos( $h, '<a ' ) && false === strpos( $h, 'href=' ) && false === strpos( $h, 'target=' ) );
check( 'T1.7 Messages keeps the unread badge (hidden at zero)', 1 === preg_match( '#data-tab="messages"[^>]*>.*<span class="hdlv2-inbox-badge cdp-tab-badge" data-inbox-badge hidden>0</span>#s', $h ) );
check( 'T1.8 the nav is the tab list, labelled, with the product-row class',
    0 === strpos( $h, '<nav class="cdp-tabs cdp-tabs-products" role="tablist" aria-label="Dashboard sections"><div class="cdp-tabs-inner">' ) );
check( 'T1.9 labels: Today, the five product labels, Messages',
    false !== strpos( $h, '>Today<' ) && false !== strpos( $h, '>Stage 1<' ) && false !== strpos( $h, '>Full Report<' ) && false !== strpos( $h, '>Timeline<' )
    && false !== strpos( $h, '>Flight Plan<' ) && false !== strpos( $h, '>30 days<' ) && false !== strpos( $h, '>Messages ' ) );

$wpdb = fresh();
client( 505, 122, 1 );
$wpdb->tickets['c505@stby.test'] = (object) array( 'practitioner_id' => 122 );
$t5 = HDLV2_Products::render_tabs( HDLV2_Products::for_client( 505 ) );
check( 'T1.10 a bought Full Report (ticket): In progress', 'in-progress:In progress' === pill( $t5, 'report' ) );

$wpdb = fresh();
client( 508, 122, 3 );
$wpdb->reports[808] = (object) array( 'id' => 78, 'report_type' => 'final', 'report_content' => '{}' );
$wpdb->packs[808]   = (object) array( 'id' => 5, 'seats_total' => 5, 'seats_used' => 0, 'status' => 'active' );
$wpdb->plans[508]   = (object) array( 'id' => 41, 'week_number' => 3, 'week_start' => '2026-10-26' );
$GLOBALS['usermeta'][508]['hdlv2_challenge'] = json_encode( array( 'started_at' => '2026-10-28 08:00:00', 'source' => 'free', 'external_ref' => '', 'invite_id' => 0, 'practitioner_id' => 122 ) );
$t8 = HDLV2_Products::render_tabs( HDLV2_Products::for_client( 508 ) );
check( 'T1.11 everything: all five product tabs Yours',
    5 === substr_count( $t8, 'class="cdp-tab-state is-yours">Yours<' ) && false === strpos( $t8, 'is-not-yet' ) );

echo "T1f the flag\n";
$GLOBALS['options'] = array();
check( 'T1f.1 hdlv2_ff_dashboard_one_row absent: one_row_enabled() false', false === HDLV2_Products::one_row_enabled() && 'hdlv2_ff_dashboard_one_row' === HDLV2_Products::FLAG_ONE_ROW );
$GLOBALS['options'][ HDLV2_Products::FLAG_ONE_ROW ] = '0';
check( 'T1f.2 set to 0: false', false === HDLV2_Products::one_row_enabled() );
$GLOBALS['options'][ HDLV2_Products::FLAG_ONE_ROW ] = '1';
check( 'T1f.3 set to 1: true', true === HDLV2_Products::one_row_enabled() );

echo "T2 render_locked: a locked tab sells or asks\n";
$wpdb = fresh();
client( 502, 122, 1 );
$k    = by_key( HDLV2_Products::for_client( 502 ) );
$full = HDLV2_Products::render_locked( $k['full_report'], $k['full_report'], "Matthew D'haemer" );
check( 'T2.1 Kim\'s Full Report: one primary link to the offer, its text from the item ("Today $27")',
    1 === substr_count( $full, '<a class="cdp-btn cdp-btn-primary" href="' . $OFFER . '">' )
    && 1 === preg_match( '#<a class="cdp-btn cdp-btn-primary" href="[^"]+">Get the Full Report · Today \$27</a>#u', $full )
    && 1 === substr_count( $full, '<a ' ) && false === strpos( $full, '<button' ) );
check( 'T2.2 the card: product name, grey Not yet pill, the two lines about it',
    0 === strpos( $full, '<div class="cdp-product-card cdp-locked">' ) && false !== strpos( $full, '<h2 class="cdp-product-title">Full Report</h2>' )
    && false !== strpos( $full, '<span class="cdp-tab-state is-not-yet">Not yet</span>' )
    && false !== strpos( $full, esc_html( HDLV2_Products::ABOUT['full_report'][0] ) ) && false !== strpos( $full, esc_html( HDLV2_Products::ABOUT['full_report'][1] ) ) );
$tl = HDLV2_Products::render_locked( $k['timeline'], $k['full_report'], "Matthew D'haemer" );
check( 'T2.3 Timeline locked: its line, and the Full Report\'s action (that is how it is obtained)',
    false !== strpos( $tl, 'Comes with your Full Report' ) && 1 === substr_count( $tl, 'href="' . $OFFER . '"' ) && false !== strpos( $tl, 'Get the Full Report · Today $27' ) );
$fp = HDLV2_Products::render_locked( $k['flight_plan'], $k['full_report'], "Matthew D'haemer" );
check( 'T2.4 Flight Plan locked: its line and one Ask button that opens Messages, no link',
    false !== strpos( $fp, 'After your consultation' )
    && 1 === substr_count( $fp, '<button type="button" class="cdp-btn cdp-btn-secondary" data-open-tab="messages">Ask Matthew D&#039;haemer</button>' )
    && false === strpos( $fp, '<a ' ) );

$wpdb = fresh();
client( 504, 130, 1 );
$o  = by_key( HDLV2_Products::for_client( 504 ) );
$of = HDLV2_Products::render_locked( $o['full_report'], $o['full_report'], 'Pat Other' );
check( 'T2.5 another practitioner\'s client: Full Report asks by name, no price, no link',
    1 === substr_count( $of, 'data-open-tab="messages">Ask Pat Other</button>' ) && false === strpos( $of, '<a ' ) && false === strpos( $of, '$' ) );
$ot = HDLV2_Products::render_locked( $o['timeline'], $o['full_report'], 'Pat Other' );
check( 'T2.6 ...and their Timeline asks too (the Full Report\'s action)', 1 === substr_count( $ot, 'data-open-tab="messages">Ask Pat Other</button>' ) );
check( 'T2.6b no name on file: "Ask your practitioner"', false !== strpos( HDLV2_Products::render_locked( $o['flight_plan'], $o['full_report'], '' ), '>Ask your practitioner</button>' ) );

$wpdb = fresh();
client( 516, 122, 2 );
$a  = by_key( HDLV2_Products::for_client( 516 ) );
$ip = HDLV2_Products::render_locked( $a['full_report'], $a['full_report'], "Matthew D'haemer" );
check( 'T2.7 in progress: blue pill, its line, no button and no link',
    false !== strpos( $ip, '<span class="cdp-tab-state is-in-progress">In progress</span>' ) && false !== strpos( $ip, 'Questions under way' )
    && false === strpos( $ip, '<button' ) && false === strpos( $ip, '<a ' ) );
$at = HDLV2_Products::render_locked( $a['timeline'], $a['full_report'], "Matthew D'haemer" );
check( 'T2.8 Timeline while the Full Report is under way: no action (nothing to buy or ask)', false === strpos( $at, '<button' ) && false === strpos( $at, '<a ' ) );

$wpdb = fresh();
client( 521, 122, 3 );
$w = HDLV2_Products::render_locked( by_key( HDLV2_Products::for_client( 521 ) )['full_report'], by_key( HDLV2_Products::for_client( 521 ) )['full_report'], "Matthew D'haemer" );
check( 'T2.9 words escaped', false !== strpos( $w, 'Matthew D&#039;haemer is writing it' ) && false === strpos( $w, "D'haemer" ) );

echo "T3 the copy (ABOUT, Matthew's to replace)\n";
$about = HDLV2_Products::ABOUT;
check( 'T3.1 five products, two lines each', array( 'stage1', 'full_report', 'timeline', 'flight_plan', 'challenge' ) === array_keys( $about )
    && 5 === count( array_filter( $about, function ( $l ) { return 2 === count( $l ); } ) ) );
$lines = array_merge( ...array_values( $about ) );
check( 'T3.2 every line under 90 characters', ! array_filter( $lines, function ( $l ) { return mb_strlen( $l ) >= 90; } ) );
check( 'T3.3 no medical claim (risk, disease, diagnos)', ! preg_grep( '/risk|disease|diagnos/i', $lines ) );
check( 'T3.4 no price in the copy', ! preg_grep( '/\$|£|€|\d+\s*(usd|gbp|eur)/i', $lines ) );
$src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/sprint-4/class-hdlv2-products.php' );
check( 'T3.5 no $ literal before a digit anywhere in the class', ! preg_match( '/\$\d/', $src ) );

done();
