<?php
/**
 * Stories 1 and 4: the five tiles, their state, link and line, for each journey state.
 *   php tests/dashboard-products/scenario-products.php
 */
require __DIR__ . '/stubs.php';

if ( ! class_exists( 'HDLV2_Products' ) ) {
    check( 'includes/sprint-4/class-hdlv2-products.php exists', false );
    done();
}

$KEYS = array( 'stage1', 'full_report', 'timeline', 'flight_plan', 'challenge' );
$ASK  = "Ask Matthew D'haemer";

echo "S1 brand-new, practitioner 122 (no record, nothing bought)\n";
$wpdb = fresh();
client( 501 );
$items = HDLV2_Products::for_client( 501 );
check( 'S1.1 five tiles in order', array_column( $items, 'key' ) === $KEYS );
check( 'S1.2 labels', array_column( $items, 'label' ) === array( 'Stage 1', 'Full Report', 'Timeline', 'Flight Plan', '30 days' ) );
$t = by_key( $items );
check( 'S1.3 every tile not-yet', array_unique( array_column( $items, 'state' ) ) === array( 'not-yet' ) );
check( 'S1.4 no practitioner on file: Stage 1 says ask your practitioner', 'Ask your practitioner' === $t['stage1']['note'] );
check( 'S1.5 Full Report shows no price without a practitioner the offer applies to', null === $t['full_report']['price'] );

echo "S2 Stage 1 done, practitioner 122, Stage 1 PDF on file\n";
$wpdb = fresh();
client( 502, 122, 1, array( 'stage1_pdf_url' => 'https://stby.test/?hdlv2_stage1_pdf=802', 'stage1_pdf_stored_path' => 'stage1/802.pdf' ) );
$t = by_key( HDLV2_Products::for_client( 502 ) );
check( 'S2.1 Stage 1 yours', 'yours' === $t['stage1']['state'] );
check( 'S2.2 Stage 1 opens the PDF', 'https://stby.test/?hdlv2_stage1_pdf=802' === $t['stage1']['url'] );
check( 'S2.3 Stage 1 line is the day it was done', 'Done 2 Nov' === $t['stage1']['note'] );
check( 'S2.4 Full Report not-yet with the offer price', 'not-yet' === $t['full_report']['state'] && '$27' === $t['full_report']['price'] );
check( 'S2.5 Full Report opens the offer url with the shared code', 'https://altituding.com/full-report?code=YOURCODE' === $t['full_report']['url'] );
check( 'S2.6 Full Report line names the price', 'Today $27' === $t['full_report']['note'] );
check( 'S2.7 Timeline locked: comes with the Full Report', 'not-yet' === $t['timeline']['state'] && 'Comes with your Full Report' === $t['timeline']['note'] && '' === $t['timeline']['url'] );
check( 'S2.8 Flight Plan locked: after the consultation', 'not-yet' === $t['flight_plan']['state'] && 'After your consultation' === $t['flight_plan']['note'] );
check( 'S2.9 30 days free for a client record, link to the section', 'not-yet' === $t['challenge']['state'] && 'Free to start' === $t['challenge']['note'] && 'https://stby.test/my-dashboard/#challenge' === $t['challenge']['url'] );
check( 'S2.10 only the Full Report carries a price', array( null, '$27', null, null, null ) === array_column( HDLV2_Products::for_client( 502 ), 'price' ) );

echo "S3 Stage 1 done without a PDF: yours, no link\n";
$wpdb = fresh();
client( 503, 122, 1 );
$t = by_key( HDLV2_Products::for_client( 503 ) );
check( 'S3.1 yours, url empty', 'yours' === $t['stage1']['state'] && '' === $t['stage1']['url'] );

echo "S4 Story 4: the same client of another practitioner\n";
$wpdb = fresh();
client( 504, 130, 1 );
$t = by_key( HDLV2_Products::for_client( 504 ) );
check( 'S4.1 Full Report: no price, ask by name', null === $t['full_report']['price'] && 'Ask Pat Other' === $t['full_report']['note'] && '' === $t['full_report']['url'] );
check( 'S4.2 Timeline still comes with the Full Report', 'Comes with your Full Report' === $t['timeline']['note'] );

echo "S5 full_report ticket bought, no report yet\n";
$wpdb = fresh();
client( 505, 122, 1 );
$wpdb->tickets['c505@stby.test'] = (object) array( 'practitioner_id' => 122 );
$t = by_key( HDLV2_Products::for_client( 505 ) );
check( 'S5.1 Full Report in progress', 'in-progress' === $t['full_report']['state'] );
check( 'S5.2 says what is under way, no price, no link', 'Questions under way' === $t['full_report']['note'] && null === $t['full_report']['price'] && '' === $t['full_report']['url'] );
check( 'S5.3 the ticket lookup asks for a bought full_report ticket', (bool) preg_grep( "/source = 'full_report'.*external_ref IS NOT NULL/s", $wpdb->calls ) );

echo "S5b ticket bought before any record (practitioner from the ticket)\n";
$wpdb = fresh();
client( 515 );
$wpdb->tickets['c515@stby.test'] = (object) array( 'practitioner_id' => 130 );
$t = by_key( HDLV2_Products::for_client( 515 ) );
check( 'S5b.1 Full Report in progress', 'in-progress' === $t['full_report']['state'] );
check( 'S5b.2 Stage 1 asks the ticket practitioner', 'Ask Pat Other' === $t['stage1']['note'] );

echo "S6 Stage 3 done (no report yet)\n";
$wpdb = fresh();
client( 506, 122, 3 );
$t = by_key( HDLV2_Products::for_client( 506 ) );
check( 'S6.1 Full Report in progress, the practitioner writes it', 'in-progress' === $t['full_report']['state'] && "Matthew D'haemer is writing it" === $t['full_report']['note'] );

echo "S7 draft report\n";
$wpdb = fresh();
client( 507, 122, 3 );
$wpdb->reports[807] = (object) array( 'id' => 77, 'report_type' => 'draft', 'report_content' => '{}' );
$t = by_key( HDLV2_Products::for_client( 507 ) );
check( 'S7.1 Full Report yours, draft line', 'yours' === $t['full_report']['state'] && 'Draft · consultation next' === $t['full_report']['note'] );
check( 'S7.2 Full Report opens /my-report/ (no token in the url)', 'https://stby.test/my-report/' === $t['full_report']['url'] );
check( 'S7.3 Timeline yours, opens the client timeline', 'yours' === $t['timeline']['state'] && 'https://stby.test/healthspan-timeline/?t=' . str_repeat( 'a', 64 ) === $t['timeline']['url'] );

echo "S8 final report + family pack (the demo owner)\n";
$wpdb = fresh();
client( 508, 122, 3 );
$wpdb->reports[808] = (object) array( 'id' => 78, 'report_type' => 'final', 'report_content' => '{}' );
$wpdb->packs[808]   = (object) array( 'id' => 5, 'seats_total' => 5, 'seats_used' => 0, 'status' => 'active' );
$wpdb->calls = array();
$items = HDLV2_Products::for_client( 508 );
$t = by_key( $items );
check( 'S8.1 Stage 1, Full Report, Timeline yours', 'yours' === $t['stage1']['state'] && 'yours' === $t['full_report']['state'] && 'yours' === $t['timeline']['state'] );
check( 'S8.2 Full Report final line', 'Final' === $t['full_report']['note'] );
check( 'S8.3 Timeline carries the pack line', 'Family pack · 5 seats · 5 left' === $t['timeline']['note'] );
check( 'S8.4 Flight Plan not yet', 'not-yet' === $t['flight_plan']['state'] && 'After your consultation' === $t['flight_plan']['note'] );
check( 'S8.5 at most five lookups', count( $wpdb->calls ) <= 5 );
$wpdb->packs[808]->status = 'ended';
$t = by_key( HDLV2_Products::for_client( 508 ) );
check( 'S8.6 an ended pack says so', 'Family pack ended' === $t['timeline']['note'] );
unset( $wpdb->packs[808] );
$t = by_key( HDLV2_Products::for_client( 508 ) );
check( 'S8.7 no pack: plain line', 'Ready' === $t['timeline']['note'] );

echo "S9 flight plan\n";
$wpdb = fresh();
client( 509, 122, 3 );
$wpdb->reports[809] = (object) array( 'id' => 79, 'report_type' => 'final', 'report_content' => '{}' );
$wpdb->plans[509]   = (object) array( 'id' => 41, 'week_number' => 3 );
$t = by_key( HDLV2_Products::for_client( 509 ) );
check( 'S9.1 Flight Plan yours, week line, opens the plan page', 'yours' === $t['flight_plan']['state'] && 'Week 3' === $t['flight_plan']['note'] && 'https://stby.test/my-flight-plan/' === $t['flight_plan']['url'] );
check( 'S9.2 the plan lookup skips archived plans', (bool) preg_grep( '/hdlv2_flight_plans.*deleted_at IS NULL/s', $wpdb->calls ) );

echo "S10 30-day challenge enrolled (paid and free)\n";
$wpdb = fresh();
client( 510, 122, 1 );
$GLOBALS['usermeta'][510]['hdlv2_challenge'] = json_encode( array( 'started_at' => '2026-10-28 08:00:00', 'source' => 'stripe', 'external_ref' => 'cs_1', 'invite_id' => 9, 'practitioner_id' => 122 ) );
$t = by_key( HDLV2_Products::for_client( 510 ) );
check( 'S10.1 30 days yours, start line, opens the section', 'yours' === $t['challenge']['state'] && 'Started 28 Oct' === $t['challenge']['note'] && 'https://stby.test/my-dashboard/#challenge' === $t['challenge']['url'] );
$GLOBALS['usermeta'][510]['hdlv2_challenge'] = json_encode( array( 'started_at' => '2026-10-29 08:00:00', 'source' => 'free', 'external_ref' => '', 'invite_id' => 0, 'practitioner_id' => 122 ) );
$t = by_key( HDLV2_Products::for_client( 510 ) );
check( 'S10.2 a free enrolment is yours too', 'yours' === $t['challenge']['state'] && 'Started 29 Oct' === $t['challenge']['note'] );

echo "S11 challenge buyer whose Stage 1 is a pending lead (no record yet)\n";
$wpdb = fresh();
client( 511 );
$GLOBALS['usermeta'][511]['hdlv2_challenge'] = json_encode( array( 'started_at' => '2026-10-28 08:00:00', 'source' => 'stripe', 'external_ref' => 'cs_2', 'invite_id' => 10, 'practitioner_id' => 122 ) );
$wpdb->leads[] = '122|c511@stby.test';
$t = by_key( HDLV2_Products::for_client( 511 ) );
check( 'S11.1 Stage 1 in progress with the practitioner', 'in-progress' === $t['stage1']['state'] && "Matthew D'haemer is confirming it" === $t['stage1']['note'] );
check( 'S11.2 30 days yours', 'yours' === $t['challenge']['state'] );
check( 'S11.3 Full Report offer price for 122 (from the enrolment practitioner)', '$27' === $t['full_report']['price'] );

echo "S12 hdlv2_ht_gate=addon behaves as full_report (reserved), no price\n";
$wpdb = fresh();
$GLOBALS['options']['hdlv2_ht_gate'] = 'addon';
client( 512, 122, 3 );
$wpdb->reports[812] = (object) array( 'id' => 80, 'report_type' => 'final', 'report_content' => '{}' );
$t = by_key( HDLV2_Products::for_client( 512 ) );
check( 'S12.1 timeline yours with a report', 'yours' === $t['timeline']['state'] && null === $t['timeline']['price'] );
client( 513, 122, 1 );
$t = by_key( HDLV2_Products::for_client( 513 ) );
check( 'S12.2 timeline locked without one, no price', 'not-yet' === $t['timeline']['state'] && null === $t['timeline']['price'] && 'Comes with your Full Report' === $t['timeline']['note'] );

echo "S13 timeline flag off\n";
$wpdb = fresh();
$GLOBALS['options']['hdlv2_ff_healthspan_timeline'] = 0;
client( 514, 122, 3 );
$wpdb->reports[814] = (object) array( 'id' => 81, 'report_type' => 'final', 'report_content' => '{}' );
$t = by_key( HDLV2_Products::for_client( 514 ) );
check( 'S13.1 timeline not yet, coming soon, no link', 'not-yet' === $t['timeline']['state'] && 'Coming soon' === $t['timeline']['note'] && '' === $t['timeline']['url'] );

echo "R render_strip\n";
$wpdb = fresh();
client( 520, 122, 1, array( 'stage1_pdf_url' => 'https://stby.test/?hdlv2_stage1_pdf=820', 'stage1_pdf_stored_path' => 'stage1/820.pdf' ) );
$items = HDLV2_Products::for_client( 520 );
$h = HDLV2_Products::render_strip( $items, false );
check( 'R1 one nav, labelled', 1 === substr_count( $h, '<nav class="cdp-products" aria-label="What you have">' ) );
check( 'R2 five tiles', 5 === substr_count( $h, 'class="cdp-tile ' ) );
check( 'R3 a tile with a url is a link, one without is not', 1 === substr_count( $h, 'href="https://stby.test/?hdlv2_stage1_pdf=820"' ) && false !== strpos( $h, '<span class="cdp-tile is-not-yet"' ) );
check( 'R4 the three pills', false !== strpos( $h, '>Yours<' ) && false !== strpos( $h, '>Not yet<' ) );
check( 'R5 words escaped', false !== strpos( HDLV2_Products::render_strip( HDLV2_Products::for_client( 504 ), false ), 'Pat Other' ) && false === strpos( $h, "D'haemer" ) );
$p = HDLV2_Products::render_strip( $items, true );
check( 'R6 practitioner view: same tiles and words', 5 === substr_count( $p, 'class="cdp-tile ' ) && false !== strpos( $p, 'Today $27' ) && false !== strpos( $p, 'Done 2 Nov' ) );
check( 'R7 practitioner view: no link at all (no buy button, no client token)', false === strpos( $p, 'href=' ) );
check( 'R8 practitioner view label', false !== strpos( $p, 'aria-label="What this client has"' ) );
$GLOBALS['options']['hdlv2_ff_dashboard_products'] = 0;
check( 'R9 flag off: nothing rendered', '' === HDLV2_Products::render_strip( $items, false ) && '' === HDLV2_Products::render_strip( $items, true ) );

echo "P no price is typed into the class\n";
$src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/sprint-4/class-hdlv2-products.php' );
check( 'P1 no $ literal before a digit', ! preg_match( '/\$\d/', $src ) );

done();
