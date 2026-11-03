<?php
/**
 * One-row dashboard fix v2 (v0.48.41): the page shows the reports' own charts.
 *   php tests/dashboard-stage1-visuals/test-stage1-card.php
 *
 * The Stage 1 card is the Stage 1 report's "Your Results" (its dial, Bio Age · Chrono · gap,
 * Pace of Ageing, the pill, the headline); the Full Report's pace panel is the final report's dial;
 * Today's preview is the Stage 1 report's dial. The drawn gauge (gauge_svg) is gone.
 */
require dirname( __DIR__ ) . '/dashboard-one-row-fix/stubs.php';
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } // the headline builder's plain-text pass
foreach ( array( 'sprint-1/class-hdlv2-widget-config.php', 'sprint-2/class-hdlv2-staged-form.php', 'sprint-2c/class-hdlv2-final-report.php' ) as $f ) {
    require_once $R . 'includes/' . $f;
}

$D   = HDLV2_Client_Dashboard::get_instance();
$css = file_get_contents( $R . 'assets/css/hdlv2-client-dashboard-populated.css' );
$src = file_get_contents( $R . 'includes/sprint-4/class-hdlv2-client-dashboard.php' );
function on() { fresh(); $GLOBALS['options'][ HDLV2_Products::FLAG_ONE_ROW ] = 1; }
function cfg( $url ) { parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q ); return json_decode( $q['c'] ?? 'null', true ); }
function stat_row( $html ) { return preg_match( '#<div class="cdp-stage1-stats">.*?</div></div></div>#s', $html, $m ) ? $m[0] : ''; }
$S1  = array( 'q1_age' => 52, 'q1_sex' => 'male', 'q2a' => 'b', 'q3' => 'c', 'server_result' => array( 'rate' => 1.05 ) );
/** The Demo Owner: Stage 1 at 1.05 aged 52, a final report, no Stage 1 PDF. */
function owner( $s1, $extra = array() ) {
    global $wpdb;
    on();
    client( 652, 122, 3, array_merge( array( 'stage1_data' => json_encode( $s1 ), 'stage3_data' => null, 'client_name' => 'Demo Owner' ), $extra ) );
    $wpdb->reports[952] = (object) array( 'id' => 125, 'report_type' => 'final', 'report_content' => '{"calc_snapshot":{"rate":1.05,"bio_age":54.6,"scores":[]}}', 'pdf_url' => '', 'updated_at' => '2026-11-02 04:00:00', 'created_at' => '2026-11-02 04:00:00', 'practitioner_user_id' => 122 );
    $GLOBALS['users'][652][1]   = 'Demo Owner';
    $GLOBALS['current_user']    = 652; $GLOBALS['journey_state'] = 'populated';
}

echo "S1 one gauge per report, the report's own\n";
check( 'S1.1 gauge_svg(), gauge_band() and GAUGE_BANDS are gone from the dashboard',
    ! method_exists( $D, 'gauge_svg' ) && ! method_exists( $D, 'gauge_band' ) && ! defined( 'HDLV2_Client_Dashboard::GAUGE_BANDS' )
    && 0 === preg_match( '/gauge_svg|gauge_band|cdp-gauge/', $src . $css ) );

$s1url = is_callable( array( 'HDLV2_Widget_Config', 'stage1_report_gauge_url' ) ) ? HDLV2_Widget_Config::stage1_report_gauge_url( 1.05 ) : '';
$c     = cfg( $s1url );
check( 'S1.2 HDLV2_Widget_Config::stage1_report_gauge_url( 1.05 ) is public: the Stage 1 PDF\'s dial (0.8–1.4, 1.05, no number, no word)',
    0 === strpos( $s1url, 'https://quickchart.io/chart?c=' ) && 'gauge' === ( $c['type'] ?? '' )
    && 0.8 === $c['data']['datasets'][0]['minValue'] && 1.4 === $c['data']['datasets'][0]['maxValue'] && 1.05 === $c['data']['datasets'][0]['value']
    && false === $c['options']['valueLabel']['display'] && false === $c['options']['subtitle']['display'] );
// md5 of the Stage 1 PDF's gauge URL at ed7a664, from the inline block in queue_stage1 (evaluated from the base file).
$base = array( '0.85' => '2f7722ea12c8f1b1c76a8707673f78ab', '1.05' => 'cc064ff6b9188976da6a101d04f3f1b9', '1.3' => '4974202efc43dd91a1d50b6fd6f4fa20', '0.7' => 'ee8a81abe09190a82873957b63f4f5bc', '1.6' => '091b81b3f9706897b10d9b89e11104e4' );
$same = '' !== $s1url;
foreach ( $base as $r => $md5 ) { $same = $same && $md5 === md5( HDLV2_Widget_Config::stage1_report_gauge_url( (float) $r ) ); }
check( 'S1.3 the PDF\'s gauge is byte-equal to ed7a664 for 0.7, 0.85, 1.05, 1.3, 1.6, and the PDF path calls the helper', $same
    && 1 === substr_count( file_get_contents( $R . 'includes/sprint-1/class-hdlv2-widget-config.php' ), '$s1_gauge_url = self::stage1_report_gauge_url( $rate );' ) );

$frurl = is_callable( array( 'HDLV2_Final_Report', 'gauge_url' ) ) ? HDLV2_Final_Report::gauge_url( 1.2 ) : '';
check( 'S1.4 HDLV2_Final_Report::gauge_url( 1.2 ) is public and is the final report\'s dial: Staged_Form, stage3 (0.5–2.0)',
    '' !== $frurl && HDLV2_Staged_Form::build_gauge_url( 1.2, array( 'stage3' => true ) ) === $frurl
    && 0.5 === cfg( $frurl )['data']['datasets'][0]['minValue'] && 2.0 === cfg( $frurl )['data']['datasets'][0]['maxValue']
    && 1 === substr_count( file_get_contents( $R . 'includes/sprint-2c/class-hdlv2-final-report.php' ), '$final_gauge_url = self::gauge_url( $calc_result[\'rate\'] ?? 1.0 );' ) );
check( 'S1.5 HDLV2_Widget_Config::interpret_rate() is public, body unchanged (0.95 / 1.05)',
    is_callable( array( 'HDLV2_Widget_Config', 'interpret_rate' ) )
    && 'Slower than average' === HDLV2_Widget_Config::interpret_rate( 0.95 )['label'] && 'Average' === HDLV2_Widget_Config::interpret_rate( 1.05 )['label']
    && 'Faster than average' === HDLV2_Widget_Config::interpret_rate( 1.06 )['label'] );

echo "S2 the Stage 1 card is the Stage 1 report's Your Results\n";
owner( $S1 );
$o  = $D->render_shortcode( array() );
$s1 = panel( $o, 'stage1' );
$headline = HDLV2_Stage1_Commentary::build_structured( $S1, $S1['server_result'], 'Demo Owner' )['headline_text'];
check( 'S2.1 the card shows the Stage 1 report\'s dial as an image, 380×340', '' !== $s1url
    && false !== strpos( $s1, '<img class="cdp-report-gauge" src="' . esc_url( $s1url ) . '" width="380" height="340" loading="lazy" alt="Pace of ageing gauge — 1.05×">' ) );
$st = stat_row( $s1 );
check( 'S2.2 Bio Age 54.6 · Chrono 52 · To Address −2.6 yrs, in the gauge column',
    false !== strpos( $s1, '<div class="cdp-stage1-gauge">' )
    && 1 === preg_match( '#<span class="cdp-stat-label">Bio Age</span><span class="cdp-stat-value">54\.6</span>#', $st )
    && 1 === preg_match( '#<span class="cdp-stat-label">Chrono</span><span class="cdp-stat-value">52</span>#', $st )
    && 1 === preg_match( '#<span class="cdp-stat-label">To Address</span><span class="cdp-stat-value">&minus;2\.6 yrs</span>#', $st ) );
check( 'S2.3 "Pace of Ageing" and 1.05× in the data column',
    1 === preg_match( '#<div class="cdp-stage1-data"><p class="cdp-stage1-label">Pace of Ageing</p><p class="cdp-stage1-value">1\.05<span class="cdp-stage1-unit">&times;</span></p>#', $s1 ) );
check( 'S2.4 the pill reads the report\'s word, info colour for Average',
    false !== strpos( $s1, '<span class="cdp-chart-insight-tag cdp-chart-insight-tag-info">Average</span>' ) );
check( 'S2.5 the report\'s own headline sentence', '' !== $headline
    && false !== strpos( $s1, '<p class="cdp-stage1-headline">' . esc_html( $headline ) . '</p>' ) );
check( 'S2.6 no "Captured", no invented sentence, no band word in colour',
    false === strpos( $s1, 'Captured' ) && false === strpos( $s1, 'starting point for everything' ) && false === strpos( $s1, 'cdp-stage1-band' ) );
check( 'S2.7 no PDF button without a stored PDF, no first look (the Timeline is his)',
    false === strpos( $s1, 'Open the Stage 1 report (PDF)' ) && false === strpos( $s1, 'See a first look' ) );
owner( $S1, array( 'stage1_pdf_url' => 'https://example.test/?hdlv2_stage1_pdf=1', 'stage1_pdf_stored_path' => 'stage1/1.pdf' ) );
check( 'S2.8 a stored PDF: the PDF button', false !== strpos( panel( $D->render_shortcode( array() ), 'stage1' ), '>Open the Stage 1 report (PDF)</a>' ) );

$gap = function ( $rate, $age ) use ( $D ) {
    $s = array( 'q1_age' => $age, 'server_result' => array( 'rate' => $rate ) );
    return stat_row( (string) call_private( $D, 'stage1_body', array( 'stage1_rate' => $rate, 'stage1_data' => $s, 'display_name' => 'A B' ), array( 'url' => '' ), '' ) );
};
check( 'S2.9 the gap as the PDF\'s Liquid: 0.9×50 → In Favour +5 yrs; 1.02×50 → To Address −1 yr; 1×50 → Status On Pace',
    false !== strpos( $gap( 0.9, 50 ), 'In Favour</span><span class="cdp-stat-value">+5 yrs<' )
    && false !== strpos( $gap( 1.02, 50 ), 'To Address</span><span class="cdp-stat-value">&minus;1 yr<' )
    && false !== strpos( $gap( 1.0, 50 ), 'Status</span><span class="cdp-stat-value">On Pace<' ) );
$noage = (string) call_private( $D, 'stage1_body', array( 'stage1_rate' => 1.1, 'stage1_data' => array( 'server_result' => array( 'rate' => 1.1 ) ), 'display_name' => 'A B' ), array( 'url' => '' ), '' );
check( 'S2.10 no age: no stats row; no Stage 1 data: the interpret_rate() message as the sentence',
    '' !== $noage && false === strpos( $noage, 'cdp-stage1-stats' ) && false !== strpos( $noage, 'cdp-chart-insight-tag-watch">Faster than average<' )
    && false !== strpos( (string) call_private( $D, 'stage1_body', array( 'stage1_rate' => 0.9, 'stage1_data' => null, 'display_name' => 'A B' ), array( 'url' => '' ), '' ),
        esc_html( HDLV2_Widget_Config::interpret_rate( 0.9 )['message'] ) ) );

echo "S3 the health profile where the report has one\n";
$scores = array( 'physicalActivity' => 3, 'sleepDuration' => 4, 'bmiScore' => 2, 'skinElasticity' => 5 );
$radar  = (string) call_private( $D, 'chart_panel_radar', array( 'stage3_scores' => $scores ) );
check( 'S3.1 chart_panel_radar(): the panel with the report\'s radar image when scores exist, 3 plotted metrics; nothing when empty',
    false !== strpos( $radar, 'Health profile' ) && false !== strpos( $radar, '>3 metrics<' ) && 'radar' === ( cfg( html_entity_decode( (string) ( preg_match( '#<img src="([^"]+)"#', $radar, $m ) ? $m[1] : '' ) ) )['type'] ?? '' )
    && '' === call_private( $D, 'chart_panel_radar', array( 'stage3_scores' => array() ) ) );

echo "S4 the other two gauges; the flag-off page unchanged\n";
fresh();
$GLOBALS['options'][ HDLV2_Products::FLAG_ONE_ROW ] = 0;
check( 'S4.1 flag off: chart_panel_gauge() and preview_gauge() byte-equal to ed7a664 (md5)',
    '5fec06d4a6885174793bd2bce95e3ae7' === md5( call_private( $D, 'chart_panel_gauge', array( 'stage3_rate' => 1.2 ) ) )
    && 'a847dacd40ad401163b7e5fa1d7cc597' === md5( call_private( $D, 'preview_gauge', array( 'stage1_rate' => 1.05, 'stage1_captured_at' => '2026-11-02 03:58:43' ) ) ) );
on();
$pg = (string) call_private( $D, 'chart_panel_gauge', array( 'stage3_rate' => 1.2 ) );
check( 'S4.2 flag on: the pace panel prints the final report\'s dial image, its head and insight kept', '' !== $frurl
    && false !== strpos( $pg, '<div class="cdp-chart-panel-img"><img class="cdp-report-gauge" src="' . esc_url( $frurl ) . '" width="380" height="340" loading="lazy" alt="Pace of ageing gauge — 1.20×"></div>' )
    && false !== strpos( $pg, 'cdp-chart-panel-value">1.20&times;' ) && false !== strpos( $pg, 'cdp-chart-insight-tag' ) );
$pv = (string) call_private( $D, 'preview_gauge', array( 'stage1_rate' => 1.05, 'stage1_captured_at' => '2026-11-02 03:58:43' ) );
check( 'S4.3 flag on: Today\'s preview prints the Stage 1 report\'s dial image', '' !== $s1url
    && false !== strpos( $pv, '<img class="cdp-report-gauge" src="' . esc_url( $s1url ) . '" width="380" height="340" loading="lazy" alt="Pace of ageing gauge — 1.05×">' )
    && false === strpos( $pv, 'cd-gauge-real' ) );
owner( $S1 );
$o = $D->render_shortcode( array() );
check( 'S4.4 the whole one-row page: two report images (Stage 1, Full Report), no SVG gauge', 2 === substr_count( $o, 'class="cdp-report-gauge"' ) && false === strpos( $o, '<svg class="cdp-gauge' ) );

echo "S5 CSS under the one-row page only\n";
$blk = (string) strstr( $css, '/* v0.48.41' );
check( 'S5.1 one v0.48.41 block, every selector under .hdlv2-cd-one-row, no rail, no colour outside DESIGN.md',
    '' !== $blk && 0 === preg_match( '/border-left\s*:\s*[^0;]/', $blk )
    && 0 === preg_match_all( '/^(?!\s*(\.hdlv2-cd-one-row|@media|\}|\/\*|\*|$|[a-z-]+\s*:))/m', $blk )
    && 0 === preg_match( '/#(?!fff\b|ffffff\b|111\b|888\b|555\b|e4e6ea\b|f8f9fb\b|3d8da0\b|004F59\b|2c3e50\b|3b82f6\b|eff6ff\b|bfdbfe\b)[0-9a-fA-F]{3,6}\b/', $blk ) );
check( 'S5.2 the stats row and the two columns are styled, the image never wider than its box',
    false !== strpos( $blk, '.hdlv2-cd-one-row .cdp-stage1-stats' ) && false !== strpos( $blk, '.hdlv2-cd-one-row .cdp-report-gauge' )
    && false !== strpos( $blk, 'max-width: 100%' ) && false !== strpos( $blk, '.hdlv2-cd-one-row .cdp-chart-insight-tag-info' ) );

done();
