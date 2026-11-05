<?php
/**
 * Plan E3 fix v1 (v0.48.39), Stories 1, 2, 3, 4 and 5: the one-row dashboard after the walk.
 *   php tests/dashboard-one-row-fix/test-one-row-fix.php
 */
require __DIR__ . '/stubs.php';

$D    = HDLV2_Client_Dashboard::get_instance();
$css  = file_get_contents( $R . 'assets/css/hdlv2-client-dashboard-populated.css' );
$pcss = file_get_contents( $R . 'assets/css/hdlv2-products.css' );
$block = (string) strstr( $css, '/* ── One row' );
function s1( $rate ) { return json_encode( array( 'q1_age' => 50, 'q1_sex' => 'female', 'server_result' => array( 'rate' => $rate ) ) ); }
function on() { fresh(); $GLOBALS['options'][ HDLV2_Products::FLAG_ONE_ROW ] = 1; }
$PDF = array( 'stage1_pdf_url' => 'https://stby.test/?hdlv2_stage1_pdf=802', 'stage1_pdf_stored_path' => 'stage1/802.pdf' );
/** Kim: Stage 1 only, a PDF on file. */
function kim( $extra = array() ) {
    on();
    client( 502, 122, 1, array_merge( array( 'stage1_data' => s1( 1.05 ), 'stage3_data' => null, 'created_at' => '2026-11-02 03:00:00', 'client_name' => 'Kim Test' ), $extra ) );
    $GLOBALS['current_user'] = 502; $GLOBALS['journey_state'] = 'stage1-done';
}
/** The Demo Owner without a plan or a 30 days start: final report, family pack. */
function owner() {
    global $wpdb;
    on();
    client( 508, 122, 3, array( 'stage1_data' => s1( 1.05 ), 'stage3_data' => json_encode( array( 'server_result' => array( 'rate' => 1.2 ) ) ) ) );
    $wpdb->reports[808] = (object) array( 'id' => 78, 'report_type' => 'final', 'report_content' => '{}', 'pdf_url' => '', 'updated_at' => '2026-11-02 04:00:00', 'created_at' => '2026-11-02 04:00:00', 'practitioner_user_id' => 122 );
    $wpdb->packs[808]   = (object) array( 'id' => 5, 'seats_total' => 5, 'seats_used' => 0, 'status' => 'active' );
    $GLOBALS['current_user'] = 508; $GLOBALS['journey_state'] = 'populated';
}

echo "S1 the Stage 1 tab is one card\n";
kim( $PDF );
$k  = $D->render_shortcode( array() );
$s1 = panel( $k, 'stage1' );
check( 'S1.1 one card in the Stage 1 panel: "Stage 1", "Done 2 Nov", no pill',
    1 === substr_count( $s1, 'class="cdp-product-card' ) && false !== strpos( $s1, '<h2 class="cdp-product-title">Stage 1</h2>' )
    && false !== strpos( $s1, '<p class="cdp-product-note">Done 2 Nov</p>' ) && false === strpos( $s1, 'cdp-tab-state' ) );
// S1.2–S1.4 and S1.8–S1.10 pinned the drawn gauge, which v0.48.41 replaced with the Stage 1 report's
// own picture: tests/dashboard-stage1-visuals/ holds the card now.
check( 'S1.2 v0.48.41: the gauge is the Stage 1 report\'s image, no drawn SVG', false !== strpos( $s1, 'class="cdp-report-gauge"' ) && false === strpos( $s1, '<svg' ) );
check( 'S1.5 the PDF button with a stored path, and the first look at the timeline (the Timeline is not Kim\'s)',
    false !== strpos( $s1, '>Open the Stage 1 report (PDF)</a>' ) && false !== strpos( $s1, 'See a first look at your timeline &rarr;' ) );
check( 'S1.6 the Timeline panel keeps its own first look', false !== strpos( panel( $k, 'timeline' ), 'See a first look from your Stage 1 &rarr;' ) );
kim();
$s1b = panel( $D->render_shortcode( array() ), 'stage1' );
check( 'S1.7 no stored PDF: no PDF button', false === strpos( $s1b, 'Open the Stage 1 report (PDF)' ) && false !== strpos( $s1b, 'cdp-report-gauge' ) );

owner();
$o  = $D->render_shortcode( array() );
$fr = panel( $o, 'report' );
check( 'S1.11 v0.48.41: the Full Report\'s gauge is the final report\'s image (stage 3 dial), not drawn',
    false !== strpos( $fr, '<img class="cdp-report-gauge"' ) && false !== strpos( $fr, '%22minValue%22%3A0.5' ) && false === strpos( $fr, 'aria-label="Pace of ageing 1.20 times' ) );
check( 'S1.12 the owner\'s Stage 1 card: no first look (the Timeline is theirs)', false === strpos( panel( $o, 'stage1' ), 'See a first look' ) );
$GLOBALS['options'][ HDLV2_Products::FLAG_ONE_ROW ] = 0;
$off = $D->render_shortcode( array() );
check( 'S1.13 flag off: the Progress tab keeps the QuickChart gauge image, no drawn gauge',
    false !== strpos( $off, '%22gauge%22' ) && false === strpos( $off, 'Pace of ageing 1.20 times' ) );
kim();
$GLOBALS['options'][ HDLV2_Products::FLAG_ONE_ROW ] = 0;
$koff = $D->render_shortcode( array() );
check( 'S1.14 flag off: the waiting page keeps its gauge image', false !== strpos( $koff, 'cd-gauge-real' ) && false === strpos( $koff, 'role="img" aria-label="Pace of ageing' ) );

echo "S2 the row says only what helps\n";
fresh();
$GLOBALS['options'][ HDLV2_Products::FLAG_ONE_ROW ] = 1;
client( 508, 122, 3 );
$wpdb->reports[808] = (object) array( 'id' => 78, 'report_type' => 'final', 'report_content' => '{}' );
$wpdb->packs[808]   = (object) array( 'id' => 5, 'seats_total' => 5, 'seats_used' => 0, 'status' => 'active' );
$wpdb->plans[508]   = (object) array( 'id' => 41, 'week_number' => 3, 'week_start' => '2026-10-26' );
$GLOBALS['usermeta'][508]['hdlv2_challenge'] = json_encode( array( 'started_at' => '2026-10-28 08:00:00', 'source' => 'free', 'external_ref' => '', 'invite_id' => 0, 'practitioner_id' => 122 ) );
$all = HDLV2_Products::render_tabs( HDLV2_Products::for_client( 508 ) );
check( 'S2.1 a client with everything: no pill anywhere in the row', false === strpos( $all, 'cdp-tab-state' ) && false === strpos( $all, 'Yours' ) );
check( 'S2.2 the panel head of a product they have: no pill', false === strpos( HDLV2_Products::product_head( by_key( HDLV2_Products::for_client( 508 ) )['flight_plan'] ), 'cdp-tab-state' ) );

kim();
$ki   = by_key( HDLV2_Products::for_client( 502 ) );
$row  = HDLV2_Products::render_tabs( array_values( $ki ) );
$tab  = function ( $key ) use ( $row ) { return preg_match( '#<button[^>]*data-tab="' . $key . '"[^>]*>(.*?)</button>#s', $row, $m ) ? $m[1] : null; };
$LOCK = '#^<span class="cdp-tab-label">[^<]+</span><span class="cdp-tab-state is-not-yet" title="Not yet"><svg [^>]*aria-hidden="true"[^>]*>.*?</svg><span class="cdp-sr">Not yet</span></span>$#s';
check( 'S2.3 Kim: Full Report, Timeline and Flight Plan carry the lock with the hidden words "Not yet"',
    1 === preg_match( $LOCK, (string) $tab( 'report' ) ) && 1 === preg_match( $LOCK, (string) $tab( 'timeline' ) ) && 1 === preg_match( $LOCK, (string) $tab( 'flight_plan' ) ) );
check( 'S2.4 Kim: Stage 1 and 30 days carry nothing (hers; open to her)', false === strpos( (string) $tab( 'stage1' ), 'cdp-tab-state' ) && false === strpos( (string) $tab( 'challenge' ), 'cdp-tab-state' ) );
check( 'S2.5 the 30 days with a record and no start is "open", "Free to start"', 'open' === $ki['challenge']['state'] && 'Free to start' === $ki['challenge']['note'] );
$strip = HDLV2_Products::render_strip( array_values( $ki ), true );
check( 'S2.6 the practitioner\'s strip keeps a word on every tile: "Free to start" for the open 30 days',
    1 === preg_match( '#<span class="cdp-tile is-open"><span class="cdp-tile-label">30 days</span><span class="cdp-tile-state">Free to start</span>#', $strip )
    && 3 === substr_count( $strip, '<span class="cdp-tile-state">Not yet</span>' ) && 1 === substr_count( $strip, '<span class="cdp-tile-state">Yours</span>' )
    && false === strpos( $strip, '<span class="cdp-tile-note">Free to start</span>' ) ); // the word once, not twice
check( 'S2.7 the locked panel head carries the lock too', 1 === preg_match( '#cdp-tab-state is-not-yet" title="Not yet"><svg#', HDLV2_Products::render_locked( $ki['flight_plan'], $ki['full_report'], "Matthew D'haemer" ) ) );
$GLOBALS['options'][ HDLV2_Products::FLAG_ONE_ROW ] = 0;
check( 'S2.8 flag off: the 30 days stays "not-yet" (the strip on the old page is unchanged)', 'not-yet' === by_key( HDLV2_Products::for_client( 502 ) )['challenge']['state'] );
fresh();
$GLOBALS['options'][ HDLV2_Products::FLAG_ONE_ROW ] = 1;
client( 516, 122, 2 );
$op = HDLV2_Products::render_locked( array( 'key' => 'challenge', 'label' => '30 days', 'state' => 'open', 'url' => '', 'note' => 'Free to start', 'price' => null ), array( 'state' => 'not-yet', 'price' => null, 'url' => '' ), 'Pat Other' );
check( 'S2.8b an open 30 days that reaches the locked panel asks (no dead end), with no lock', false !== strpos( $op, 'data-open-tab="messages">Ask Pat Other</button>' ) && false === strpos( $op, 'cdp-tab-state' ) );
check( 'S2.9 in progress keeps its words', false !== strpos( HDLV2_Products::render_tabs( HDLV2_Products::for_client( 516 ) ), '<span class="cdp-tab-state is-in-progress">In progress</span>' ) );
check( 'S2.10 CSS: .is-open in the success palette, .cdp-sr hidden text, the lock sized under the tab row only',
    1 === preg_match( '/\.cdp-products \.is-open \.cdp-tile-state[^{]*\{[^}]*#10b981/', $pcss )
    && 1 === preg_match( '/\.cdp-tabs-products \.cdp-sr,\s*\.cdp-product-head \.cdp-sr \{[^}]*clip: rect\(0, 0, 0, 0\)/', $pcss )
    && 1 === preg_match( '/\.cdp-tabs-products \.cdp-tab-state\.is-not-yet svg,\s*\.cdp-product-head \.cdp-tab-state\.is-not-yet svg \{[^}]*width: 12px/', $pcss ) );

echo "S3 the Flight Plan line is true for a client whose consultation is done\n";
owner();
$oi = by_key( HDLV2_Products::for_client( 508 ) );
check( 'S3.1 final report, no plan: not-yet, "Matthew D\'haemer is preparing it" (finalise queues the first plan), no link',
    'not-yet' === $oi['flight_plan']['state'] && "Matthew D'haemer is preparing it" === $oi['flight_plan']['note'] && '' === $oi['flight_plan']['url'] );
$fp = HDLV2_Products::render_locked( $oi['flight_plan'], $oi['full_report'], "Matthew D'haemer" );
check( 'S3.2 its one button asks the practitioner; no check-in door, no link',
    1 === substr_count( $fp, 'data-open-tab="messages">Ask Matthew D&#039;haemer</button>' ) && false === strpos( $fp, 'Begin your check-in' ) && false === strpos( $fp, 'data-open-tab="today"' )
    && false === strpos( $fp, '<a ' ) && false !== strpos( $fp, '>Matthew D&#039;haemer is preparing it<' ) );
check( 'S3.3 the rendered Flight Plan tab says so', false !== strpos( panel( $o, 'flight_plan' ), 'Matthew D&#039;haemer is preparing it' ) );
kim();
$kf = by_key( HDLV2_Products::for_client( 502 ) )['flight_plan'];
check( 'S3.4 no report (Kim): "After your consultation" and Ask, as before',
    'After your consultation' === $kf['note'] && false !== strpos( HDLV2_Products::render_locked( $kf, $ki['full_report'], "Matthew D'haemer" ), 'data-open-tab="messages">Ask Matthew D&#039;haemer</button>' ) );
fresh();
$GLOBALS['options'][ HDLV2_Products::FLAG_ONE_ROW ] = 1;
client( 521, 122, 3 );
$wpdb->reports[821] = (object) array( 'id' => 91, 'report_type' => 'draft', 'report_content' => '{}' );
check( 'S3.5 a draft report (consultation not done): "After your consultation"', 'After your consultation' === by_key( HDLV2_Products::for_client( 521 ) )['flight_plan']['note'] );
owner();
$GLOBALS['options'][ HDLV2_Products::FLAG_ONE_ROW ] = 0;
check( 'S3.6 flag off: the final-report client\'s note is unchanged', 'After your consultation' === by_key( HDLV2_Products::for_client( 508 ) )['flight_plan']['note'] );

echo "S4 one type system, no rails, on the one-row page\n";
check( 'S4.1 the one-row page swaps the display serif for Poppins (one token, under .hdlv2-cd-one-row)',
    1 === preg_match( "/\.hdlv2-cd-one-row \{[^}]*--hdlv2-cd-serif: 'Poppins', 'Inter', sans-serif;/", $block ) );
check( 'S4.2 the block: no serif but sans-serif, no border-left but 0, no inset bar, no ::before',
    ! preg_match( '/(?<!sans-)serif(?!-)/', str_replace( '--hdlv2-cd-serif', '', $block ) )
    && ! preg_match( '/border-left:\s*+(?!0;)/', $block ) && false === strpos( $block, 'inset 3px' ) && false === strpos( $block, '::before' ) );
check( 'S4.3 .cdp-identity and .cdp-why-card lose their rail on the one-row page (the WHY card keeps its hairline all round)',
    1 === preg_match( '/\.hdlv2-cd-one-row \.cdp-identity \{[^}]*border-left: 0;[^}]*border-radius: 10px/', $block )
    && 1 === preg_match( '/\.hdlv2-cd-one-row \.cdp-why-card \{[^}]*border: 1px solid var\(--line\);[^}]*border-radius: 18px/', $block ) );
check( 'S4.4 the Stage 1 card: gauge | words grid, one column on a phone (v0.48.41: the report\'s two columns)',
    1 === preg_match( '/\.hdlv2-cd-one-row \.cdp-stage1-body \{[^}]*grid-template-columns: minmax\(0, 280px\) 1fr/', $css )
    && 1 === preg_match( '/@media \(max-width: 640px\) \{.*\.hdlv2-cd-one-row \.cdp-stage1-body \{[^}]*grid-template-columns: 1fr/s', $css ) );

echo "S5 the two gate warnings\n";
check( 'S5.1 the 30 days line names its condition (W-b)', 'Free once your Stage 1 is in.' === HDLV2_Products::ABOUT['challenge'][1] );

done();
