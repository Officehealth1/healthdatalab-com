<?php
/**
 * The two gates' fixes (v0.48.38): the client panel fits the roster's box, the videos button is
 * readable, no rails on the KPI cards, one syncPack per new row, case-blind held-report lookups.
 * Source assertions, no WordPress.
 *   php tests/dashboard-products/scenario-fixes.php
 */
$PASS = 0;
$FAIL = 0;
function check( $label, $cond ) {
    global $PASS, $FAIL;
    if ( $cond ) { $PASS++; echo "  PASS  $label\n"; }
    else         { $FAIL++; echo "  FAIL  $label\n"; }
}

$root = dirname( __DIR__, 2 ) . '/';
$js   = file_get_contents( $root . 'assets/js/hdlv2-client-list-enhance.js' );
$pop  = file_get_contents( $root . 'assets/css/hdlv2-client-dashboard-populated.css' );
$tl   = file_get_contents( $root . 'includes/sprint-2c/class-hdlv2-timeline-links.php' );
$pr   = file_get_contents( $root . 'includes/sprint-4/class-hdlv2-products.php' );
$main = file_get_contents( $root . 'hdl-longevity-v2.php' );

/** The body of a named JS function, up to the next top-level "  function ". */
function js_fn( $src, $name ) {
    $at = strpos( $src, 'function ' . $name . '(' );
    if ( false === $at ) return '';
    $end = strpos( $src, "\n  function ", $at + 10 );
    return substr( $src, $at, false === $end ? 4000 : $end - $at );
}
/** Every injected CSS line (the enhancer's style strings) that mentions a selector. */
function css_lines( $src, $needle ) {
    return array_values( array_filter( explode( "\n", $src ), function ( $l ) use ( $needle ) {
        return false !== strpos( $l, $needle ) && 1 === preg_match( "/^\s*'/", $l );
    } ) );
}

echo "A1 the detail panel is sized to the roster's scroll box and pinned to its left edge (H1)\n";
$fit = js_fn( $js, 'fitDetailPanel' );
check( 'A1.1 fitDetailPanel reads .clients-table-container (else the table\'s parent)',
    false !== strpos( $fit, "closest('.clients-table-container')" ) && false !== strpos( $fit, 'parentNode' ) );
check( 'A1.2 it sets the panel width from the box\'s clientWidth', 1 === preg_match( '/style\.width\s*=\s*[^;]*clientWidth/', $fit ) );
check( 'A1.3 toggleDetail fits the panel it inserts', false !== strpos( js_fn( $js, 'toggleDetail' ), 'fitDetailPanel(' ) );
$panel_css = implode( "\n", css_lines( $js, '.hdlv2-detail-panel {' ) );
check( 'A1.4 injected CSS: .hdlv2-detail-panel { position: sticky; left: 0; box-sizing: border-box }',
    false !== strpos( $panel_css, 'position: sticky' ) && false !== strpos( $panel_css, 'left: 0' ) && false !== strpos( $panel_css, 'box-sizing: border-box' ) );

echo "A2 open panels follow the window width\n";
check( 'A2.1 one resize listener re-fits every open .hdlv2-detail-panel',
    1 === substr_count( $js, "addEventListener('resize'" ) && 1 === preg_match( "/addEventListener\('resize'[\s\S]{0,400}querySelectorAll\('\.hdlv2-detail-panel'\)[\s\S]{0,200}fitDetailPanel/", $js ) );
check( 'A2.2 the re-fit waits for the drag to settle (one timer, cleared each time)', 1 === preg_match( "/addEventListener\('resize'[\s\S]{0,200}clearTimeout/", $js ) );

echo "A3 the videos button reads white on teal on the populated dashboard (H2)\n";
$link_rule = strpos( $pop, '.hdlv2-cd-pop a:hover' );
$btn_rule  = strpos( $pop, '.hdlv2-cd-pop .cd30-offer-btn' );
check( 'A3.1 .hdlv2-cd-pop .cd30-offer-btn sits after the sheet\'s own link rules', false !== $link_rule && false !== $btn_rule && $btn_rule > $link_rule );
check( 'A3.2 it and its hover carry color: #fff',
    1 === preg_match( '/\.hdlv2-cd-pop \.cd30-offer-btn,\s*\.hdlv2-cd-pop \.cd30-offer-btn:hover\s*\{[^}]*color:\s*#fff/', $pop ) );

echo "A4 no accent rails on the KPI cards (H3)\n";
$snap = css_lines( $js, '.hdlv2-snap-tile' );
check( 'A4.1 the KPI card rules are still there', count( $snap ) >= 6 );
check( 'A4.2 none of them draws a border-left', ! preg_grep( '/border-left/', $snap ) );
check( 'A4.3 the accent-* classes are gone from the markup too', false === strpos( $js, "'accent-" ) );

echo "A5 one syncPack per new row (G1)\n";
check( 'A5.1 exactly one syncPack(newRow, c)', 1 === substr_count( $js, 'syncPack(newRow, c)' ) );

echo "A6 a held Full Report is found whatever the email's case (G2)\n";
check( 'A6.1 take_seat(): LOWER(client_email) = %s with strtolower( $email )',
    false !== strpos( $tl, 'AND LOWER(client_email) = %s AND source = \'full_report\'' ) && 1 === preg_match( '/\(int\) \$practitioner_id, strtolower\( \$email \)/', $tl ) );
check( 'A6.2 for_client(): LOWER(client_email) = %s with strtolower( $email )',
    false !== strpos( $pr, 'WHERE LOWER(client_email) = %s AND source = \'full_report\'' ) && false !== strpos( $pr, 'strtolower( $email )' ) );
check( 'A6.3 no exact client_email = %s left in either', false === strpos( $tl, ' client_email = %s' ) && false === strpos( $pr, ' client_email = %s' ) );

echo "A7 version\n";
check( 'A7.1 plugin header 0.48.55', 1 === preg_match( '/\* Version:\s+0\.48\.55\b/', $main ) );
check( 'A7.2 HDLV2_VERSION 0.48.55', false !== strpos( $main, "define( 'HDLV2_VERSION', '0.48.55' )" ) );
check( 'A7.3 HDLV2_DB_VERSION stays 3.33', false !== strpos( $main, "define( 'HDLV2_DB_VERSION', '3.33' )" ) );

echo "\nPASS=$PASS FAIL=$FAIL\n";
exit( 0 === $FAIL ? 0 : 1 );
