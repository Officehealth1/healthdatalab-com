<?php
/**
 * Plan E3 Stories 1, 3, 4 and 5 (v0.48.38): /my-dashboard/ as one row in every state, behind
 * hdlv2_ff_dashboard_one_row. Source assertions (the scenario-fixes.php shape), then the real
 * HDLV2_Client_Dashboard rendered for a Stage-1-only client and a client with a final report.
 *   php tests/dashboard-products/scenario-shell.php
 */
require __DIR__ . '/stubs.php';

$root = dirname( __DIR__, 2 ) . '/';
$dash = file_get_contents( $root . 'includes/sprint-4/class-hdlv2-client-dashboard.php' );
$pop  = file_get_contents( $root . 'assets/css/hdlv2-client-dashboard-populated.css' );
$pcss = file_get_contents( $root . 'assets/css/hdlv2-products.css' );
$js   = file_get_contents( $root . 'assets/js/hdlv2-client-dashboard.js' );

/** A PHP method's source, from its line to the next method of the class. */
function method_src( $src, $name ) {
    $at = strpos( $src, 'function ' . $name . '(' );
    if ( false === $at ) return '';
    $at = strrpos( substr( $src, 0, $at ), "\n" ) + 1;
    return preg_match( '/\n    (private|public|protected) (static )?function /', $src, $m, PREG_OFFSET_CAPTURE, $at + 10 )
        ? substr( $src, $at, $m[0][1] - $at ) : substr( $src, $at );
}
/** Positions of needles in order; true when each is found after the one before. */
function in_order( $src, array $needles ) {
    $at = 0;
    foreach ( $needles as $n ) {
        $p = strpos( $src, $n, $at );
        if ( false === $p ) return false;
        $at = $p + strlen( $n );
    }
    return true;
}

$shortcode = method_src( $dash, 'render_shortcode' );
$one       = method_src( $dash, 'render_one_row' );
$today     = method_src( $dash, 'one_row_today_panel' );
$new_src   = $one . $today . method_src( $dash, 'one_row_product_panel' );

echo "H1 one shell for every state, behind the flag\n";
check( 'H1.1 render_shortcode() branches on products_on() && one_row_enabled() to render_one_row(), before the populated branch',
    1 === preg_match( '/if \( \$this->products_on\(\) && HDLV2_Products::one_row_enabled\(\) \) \{\s*return \$this->render_one_row\( \$user_id, \$state \);\s*\}/', $shortcode )
    && strpos( $shortcode, 'render_one_row(' ) < strpos( $shortcode, "\$state === 'populated'" ) );
check( 'H1.2 render_one_row() has one return (no early exit per state) and serves populated and the rest',
    1 === substr_count( $one, 'return ' ) && false !== strpos( $one, 'pop_today_panel(' ) && false !== strpos( $one, 'one_row_today_panel(' ) );
$base = array( // md5 of each method's source at 4a0fa73: the flag-off page is built by the same code
    'render_populated'    => '17222f96cba794df46aa52df9eb24434',
    'render_empty_state'  => 'c0058ae80a0cded3f76708ddb03c2c0c',
    'pop_tabs_strip'      => 'a81c05e7480ccf8a138c3a18301e289c',
    'pop_today_panel'     => 'a74b1e4f4ffe79d16c475ea85c4f1b8e',
    'products_strip'      => '42ccfaab0c07491a4305ff29862ab3a3',
    'inbox_launcher_host' => '1b1d77e2cc09e9b304a8cb110e45ebb1',
    'hero_for_state'      => '55a43d0082508cff41c5fb6e8f7288c7',
    'path_for_state'      => '9ea8e6dd5be563e687f3b87d9c91dac0',
    'steps_grid'          => 'fab19fd6aa21a898291be590b7b17784',
    'help_footer'         => 'f60134673715ea1b5b7494b9f47c7ba3',
);
$changed = array_keys( array_filter( $base, function ( $md5, $n ) use ( $dash ) { return md5( method_src( $dash, $n ) ) !== $md5; }, ARRAY_FILTER_USE_BOTH ) );
check( 'H1.3 the flag-off path untouched (ten methods byte-equal to the base)' . ( $changed ? ': ' . implode( ', ', $changed ) : '' ), ! $changed );
check( 'H1.4 the report and progress panels call the shared bodies (no copy)',
    false !== strpos( method_src( $dash, 'pop_report_panel' ), '$this->report_body( $ctx )' ) && false !== strpos( method_src( $dash, 'pop_progress_panel' ), '$this->progress_body( $ctx )' )
    && '' !== method_src( $dash, 'report_body' ) && '' !== method_src( $dash, 'progress_body' ) );

echo "H2 the seven panels\n";
check( 'H2.1 render_one_row(): the tab row, then the panels in TAB_ORDER',
    in_order( $one, array( 'HDLV2_Products::render_tabs(', "'today'", "'stage1'", "'report'", "'timeline'", "'flight_plan'", "'challenge'", 'pop_messages_panel()' ) ) );
check( 'H2.2 no strip, no launcher popup, no Progress panel on the one-row path',
    false === strpos( $new_src, 'products_strip(' ) && false === strpos( $new_src, 'inbox_launcher_host(' ) && false === strpos( $new_src, 'pop_progress_panel(' ) );
check( 'H2.3 Full Report yours = the report body, then the progress body (Q3)', false !== strpos( $one, '$this->report_body( $ctx ) . $this->progress_body( $ctx )' ) );
check( 'H2.4 the fragments are called from the new panels: preview_gauge, pop_week_card, steps_grid, help_footer, hero, path, preview',
    false !== strpos( $one, 'preview_gauge(' ) && false !== strpos( $one, 'pop_week_card(' )
    && false !== strpos( $today, 'steps_grid(' ) && false !== strpos( $today, 'help_footer()' )
    && false !== strpos( $today, 'hero_for_state(' ) && false !== strpos( $today, 'path_for_state(' ) && false !== strpos( $today, 'preview_for_state(' ) );
check( 'H2.5 the populated context is loaded in every state (a draft report is not "populated")', false !== strpos( $one, '$this->load_populated_context( $user_id )' ) );
check( 'H2.6 a locked tab is HDLV2_Products::render_locked(); the Timeline borrows the Full Report item',
    false !== strpos( $new_src, 'HDLV2_Products::render_locked( $item, $items[\'full_report\']' ) );
check( 'H2.7 the Stage 1 timeline preview stays reachable from the Timeline tab', false !== strpos( $one, 'HDLV2_Healthspan_Timeline::dashboard_url(' ) );
check( 'H2.8 one-row enqueues the populated sheet, the products sheet and the dashboard script',
    false !== strpos( $one, 'enqueue_populated_assets( $user_id )' ) && false !== strpos( $one, "wp_enqueue_style( 'hdlv2-products' )" ) );

echo "H3 phone grid, no rails, focus ring\n";
$block = (string) strstr( $pop, '/* ── One row' );
$phone = preg_match( '/@media \(max-width: 640px\) \{(.*?)\n\}/s', $block, $pm ) ? $pm[1] : '';
check( 'H3.1 ≤ 640 px: .hdlv2-cd-one-row .cdp-tabs-inner is a two-column grid',
    1 === preg_match( '/\.hdlv2-cd-one-row \.cdp-tabs-inner \{[^}]*display: grid;[^}]*grid-template-columns: repeat\(2, minmax\(0, 1fr\)\)/', $phone ) );
check( 'H3.2 ≤ 640 px: the row is not sticky, and the last tab spans both columns',
    1 === preg_match( '/\.hdlv2-cd-one-row \.cdp-tabs \{[^}]*position: static/', $phone ) && 1 === preg_match( '/\.cdp-tabs-inner > :last-child \{[^}]*grid-column: 1 \/ -1/', $phone ) );
check( 'H3.3 no rails in the new rules (no border-left, no ::before)', '' !== $block && false === strpos( $block, 'border-left' ) && false === strpos( $block, '::before' ) );
check( 'H3.4 a visible 2 px teal focus ring on the tabs and the buttons',
    1 === preg_match( '/\.cdp-tab:focus-visible[^{]*\{[^}]*outline: 2px solid #3d8da0/', $block ) && 1 === preg_match( '/\.cdp-btn:focus-visible[^{]*\{[^}]*outline: 2px solid #3d8da0/', $block ) );
check( 'H3.5 the tab pill shares the tile pill\'s tokens (one source)',
    1 === preg_match( '/\.cdp-products \.cdp-tile-state,\s*\.cdp-tab-state \{/', $pcss ) && 1 === preg_match( '/\.cdp-tab-state\.is-yours \{[^}]*#10b981/', $pcss )
    && 1 === preg_match( '/\.cdp-tab-state\.is-in-progress \{[^}]*#3b82f6/', $pcss ) );
check( 'H3.6 status colours from the palette only (no Flat UI defaults)', ! preg_match( '/#2ecc71|#e67e22|#e74c3c|#c0392b/i', $block ) );

echo "H4 same tab, old links\n";
check( 'H4.1 no target= in the new markup', false === strpos( $new_src, 'target=' ) && false === strpos( method_src( (string) file_get_contents( $root . 'includes/sprint-4/class-hdlv2-products.php' ), 'render_locked' ), 'target=' ) );
check( 'H4.2 JS: a [data-open-tab] button opens its tab without a page load', 1 === preg_match( "/closest\('\[data-open-tab\]'\)[\s\S]{0,400}preventDefault\(\);[\s\S]{0,80}activate\(name\)/", $js ) );
check( 'H4.3 JS: #challenge and #tab-<key> still open their tab', false !== strpos( $js, "replace(/^#(tab-)?/, '')" ) );

echo "R the real dashboard, flag on\n";
define( 'HDLV2_PLUGIN_URL', 'https://stby.test/wp-content/plugins/hdl-longevity-v2/' );
define( 'HDLV2_VERSION', 'test' );
$GLOBALS['enq'] = array();
function wp_register_style( ...$a ) {}
function wp_enqueue_script( $h ) { $GLOBALS['enq'][] = $h; }
function wp_localize_script( ...$a ) {}
function wp_script_is( $h, $l ) { return in_array( $h, $GLOBALS['enq'], true ); }
function wp_create_nonce( $a = '' ) { return 'n'; }
function admin_url( $p = '' ) { return 'https://stby.test/wp-admin/' . $p; }
function rest_url( $p = '' ) { return 'https://stby.test/wp-json/' . $p; }
function number_format_i18n( $n ) { return (string) $n; }
require_once $root . 'includes/sprint-4/class-hdlv2-message-thread-view.php';
require_once $root . 'includes/sprint-4/class-hdlv2-client-dashboard.php';
$D = HDLV2_Client_Dashboard::get_instance();
$S1 = json_encode( array( 'server_result' => array( 'rate' => 1.04, 'q1_age' => 50 ) ) );

$wpdb = fresh();
$GLOBALS['options'][ HDLV2_Products::FLAG_ONE_ROW ] = 1;
client( 502, 122, 1, array( 'stage1_data' => $S1, 'stage3_data' => null ) );
$GLOBALS['current_user'] = 502;
$GLOBALS['journey_state'] = 'stage1-done';
$k = $D->render_shortcode( array() );
preg_match_all( '#data-tab-panel="([a-z0-9_]+)"#', $k, $pm );
check( 'R1 Kim (stage1-done): one row, seven panels in order, Today open', $pm[1] === HDLV2_Products::TAB_ORDER
    && 1 === substr_count( $k, 'class="cdp-tabs cdp-tabs-products"' ) && 1 === substr_count( $k, 'class="cdp-panel cdp-panel-active"' )
    && false !== strpos( $k, '<section data-tab-panel="today" class="cdp-panel cdp-panel-active"><div class="hdlv2-cd">' ) );
check( 'R2 Kim: no tile strip, no second tab row, no launcher popup', false === strpos( $k, 'class="cdp-products"' ) && 1 === substr_count( $k, 'role="tablist" aria-label="Dashboard sections"' ) && false === strpos( $k, 'data-inbox-open' ) );
check( 'R3 Kim: the Full Report tab sells at the offer price, Flight Plan asks, the gauge sits under Stage 1',
    1 === preg_match( '#data-tab-panel="report"[^>]*>.*?href="https://altituding.com/full-report\?code=YOURCODE">Get the Full Report · Today \$27</a>#su', $k )
    && 1 === preg_match( '#data-tab-panel="flight_plan"[^>]*>.*?data-open-tab="messages">Ask Matthew D&\#039;haemer</button>#s', $k )
    && 1 === preg_match( '#data-tab-panel="stage1"[^>]*>.*?cd-gauge-real#s', $k ) );
check( 'R4 Kim: Today keeps the hero, the waiting card and "How the assessment unfolds"', false !== strpos( $k, 'cd-hero-title' ) && false !== strpos( $k, 'waiting on your practitioner' ) && false !== strpos( $k, 'How the assessment unfolds' ) );
check( 'R5 Kim: Messages panel holds the inbox shell', 1 === preg_match( '#data-tab-panel="messages"[^>]*>.*?data-inbox-thread#s', $k ) );

$wpdb = fresh();
$GLOBALS['options'][ HDLV2_Products::FLAG_ONE_ROW ] = 1;
client( 508, 122, 3, array( 'stage1_data' => $S1, 'stage3_data' => null ) );
$wpdb->reports[808] = (object) array( 'id' => 78, 'report_type' => 'final', 'report_content' => '{}', 'pdf_url' => '', 'updated_at' => '2026-11-02 04:00:00', 'created_at' => '2026-11-02 04:00:00', 'practitioner_user_id' => 122 );
$wpdb->packs[808]   = (object) array( 'id' => 5, 'seats_total' => 5, 'seats_used' => 0, 'status' => 'active' );
$GLOBALS['current_user'] = 508;
$GLOBALS['journey_state'] = 'populated';
$d = $D->render_shortcode( array() );
preg_match_all( '#data-tab-panel="([a-z0-9_]+)"#', $d, $pm );
check( 'R6 Demo Owner (populated): seven panels in order, no Progress panel', $pm[1] === HDLV2_Products::TAB_ORDER );
check( 'R7 Demo Owner: the Full Report tab is the report cover then "Where you stand"', 1 === preg_match( '#data-tab-panel="report"[^>]*>.*?cdp-report-cover.*?Where you stand#s', $d ) );
check( 'R8 Demo Owner: the Timeline tab opens the timeline with the pack line',
    1 === preg_match( '#data-tab-panel="timeline"[^>]*>.*?Family pack · 5 seats · 5 left.*?<a class="cdp-btn cdp-btn-primary" href="https://stby.test/healthspan-timeline/\?t=a{64}">Open your Timeline &rarr;</a>#su', $d ) );
check( 'R9 Demo Owner: Today is the greeting and the check-in card', 1 === preg_match( '#data-tab-panel="today" class="cdp-panel cdp-panel-active">.*?cdp-greeting.*?cdp-checkin-nudge#s', $d ) );

$GLOBALS['options'][ HDLV2_Products::FLAG_ONE_ROW ] = 0;
$off = $D->render_shortcode( array() );
check( 'R10 flag off: the 0.48.37 page (tile strip + old tabs, no product row)', false !== strpos( $off, 'class="cdp-products"' ) && false === strpos( $off, 'cdp-tabs-products' ) && false !== strpos( $off, 'data-tab="progress"' ) );

done();
