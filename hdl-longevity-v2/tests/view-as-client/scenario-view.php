<?php
/**
 * Plan E4-b (v0.48.40), Stories 2, 4 and 5: a practitioner opens a client's /my-dashboard/ from a
 * ?as=<progress_id>&_wpnonce= link. The real page guard (maybe_redirect_wrong_role) and the real
 * shortcode, rendered against a fake $wpdb.
 *   php tests/view-as-client/scenario-view.php
 */
require __DIR__ . '/stubs.php';

$D   = HDLV2_Client_Dashboard::get_instance();
$css = file_get_contents( $R . 'assets/css/hdlv2-client-dashboard-populated.css' );
$LOG = tempnam( sys_get_temp_dir(), 'va' );
ini_set( 'error_log', $LOG );
$TOKEN  = str_repeat( 'a', 64 ); // every fixture row's form_progress.token
$INVITE = str_repeat( 'b', 64 ); // a brand-new client's widget invite token

function s1( $rate ) { return json_encode( array( 'q1_age' => 50, 'q1_sex' => 'female', 'server_result' => array( 'rate' => $rate ) ) ); }

/**
 * Practitioner 122 owns the Demo Owner (508, progress 808: final report, a plan, a 30 days start,
 * a family pack), Kim (502 / 802: Stage 1 only) and Bea (503 / 803: brand-new). Practitioner 130
 * owns 540 (840). Client 545 has an older live row 744 of 122's and a newer one 845 of 130's.
 * Row 707 of the Demo Owner was deleted.
 */
function world() {
    global $wpdb;
    fresh();
    $wpdb = new ViewAsWpdb();
    $GLOBALS['options'][ HDLV2_Products::FLAG_ONE_ROW ] = 1;
    $GLOBALS['options']['hdlv2_ff_view_as_client']   = 1;
    $GLOBALS['page'] = 'my-dashboard';
    $GLOBALS['localized'] = array();
    $GLOBALS['enq'] = array();
    $_GET = array();
    client( 508, 122, 3, array( 'client_user_id' => 508, 'stage1_data' => s1( 1.05 ), 'stage3_data' => json_encode( array( 'server_result' => array( 'rate' => 1.2 ) ) ), 'created_at' => '2026-11-01 03:00:00', 'client_name' => 'Demo Owner' ) );
    $GLOBALS['users'][508][1] = 'Demo Owner';
    $wpdb->reports[808] = (object) array( 'id' => 78, 'report_type' => 'final', 'report_content' => '{}', 'pdf_url' => '', 'updated_at' => '2026-11-02 04:00:00', 'created_at' => '2026-11-02 04:00:00', 'practitioner_user_id' => 122 );
    $wpdb->packs[808]   = (object) array( 'id' => 5, 'seats_total' => 5, 'seats_used' => 0, 'status' => 'active' );
    $wpdb->plans[508]   = (object) array( 'id' => 41, 'week_number' => 3, 'week_start' => '2026-10-26', 'status' => 'active', 'identity_statement' => 'I move every day.' );
    $GLOBALS['usermeta'][508]['hdlv2_challenge'] = json_encode( array( 'started_at' => '2026-10-28 08:00:00', 'source' => 'free', 'external_ref' => '', 'invite_id' => 0, 'practitioner_id' => 122 ) );
    $wpdb->older[] = (object) array( 'id' => 707, 'client_user_id' => 508, 'practitioner_user_id' => 122, 'deleted_at' => '2026-10-01 00:00:00' );
    client( 502, 122, 1, array( 'client_user_id' => 502, 'stage1_data' => s1( 1.05 ), 'stage3_data' => null, 'created_at' => '2026-11-02 03:00:00', 'client_name' => 'Kim Test' ) );
    $GLOBALS['users'][502][1] = 'Kim Test';
    client( 503, 122, 0, array( 'client_user_id' => 503, 'stage1_data' => null, 'stage3_data' => null, 'created_at' => '2026-11-02 03:00:00', 'client_name' => 'Bea New' ) );
    $GLOBALS['users'][503][1] = 'Bea New';
    client( 540, 130, 1, array( 'client_user_id' => 540, 'stage1_data' => s1( 0.95 ), 'stage3_data' => null, 'created_at' => '2026-11-02 03:00:00', 'client_name' => 'Olive Elsewhere' ) );
    $GLOBALS['users'][540][1] = 'Olive Elsewhere';
    client( 545, 130, 1, array( 'client_user_id' => 545, 'stage1_data' => s1( 0.95 ), 'stage3_data' => null, 'created_at' => '2026-11-02 03:00:00', 'client_name' => 'Twice Invited' ) );
    $GLOBALS['users'][545][1] = 'Twice Invited';
    $wpdb->older[] = (object) array( 'id' => 744, 'client_user_id' => 545, 'practitioner_user_id' => 122, 'deleted_at' => null );
    set_private( HDLV2_Client_Dashboard::get_instance(), 'view_as', null );
}
/** Signs $user in, asks for /my-dashboard/?as=$id with a nonce minted for $nonce_id (null = none). */
function ask( $user, $id, $nonce_id = 'same', $state = 'populated' ) {
    $GLOBALS['current_user'] = $user;
    $GLOBALS['journey_state'] = $state;
    $_GET = array( 'as' => (string) $id );
    if ( null !== $nonce_id ) {
        $_GET['_wpnonce'] = wp_create_nonce( 'hdlv2_view_as_' . ( 'same' === $nonce_id ? $id : $nonce_id ) );
    }
}
/** The page guard, then the page: [ redirect target or null, html ]. */
function visit() {
    $D = HDLV2_Client_Dashboard::get_instance();
    try {
        $D->maybe_redirect_wrong_role();
    } catch ( Redirected $r ) {
        return array( $r->getMessage(), '' );
    }
    return array( null, $D->render_shortcode( array() ) );
}
function log_lines() {
    global $LOG;
    return array_values( array_filter( explode( "\n", (string) file_get_contents( $LOG ) ), function ( $l ) { return false !== strpos( $l, 'view_as_client' ); } ) );
}
function clear_log() { global $LOG; file_put_contents( $LOG, '' ); }

echo "S4 only the owner, only live rows (the IDOR cases first)\n";
world(); clear_log();
ask( 122, 840 );
list( $to, $h ) = visit();
check( 'S4.1 another practitioner\'s client: the practitioner is sent to Clients, nothing of that client renders',
    home_url( '/clients/' ) === $to && false === strpos( $h, 'Olive' ) );
world();
ask( 122, 707 );
list( $to ) = visit();
check( 'S4.2 a deleted row of their own client: sent to Clients', home_url( '/clients/' ) === $to );
world();
ask( 122, 808, null );
list( $to ) = visit();
check( 'S4.3 no nonce: sent to Clients', home_url( '/clients/' ) === $to );
world();
ask( 122, 808, 802 );
list( $to ) = visit();
check( 'S4.4 a nonce minted for another row: sent to Clients', home_url( '/clients/' ) === $to );
world();
ask( 502, 808, 'same', 'stage1-done' );
list( $to, $h ) = visit();
check( 'S4.5 a client caller (even with a nonce of their own): their own page, nothing of the Demo Owner',
    null === $to && false !== strpos( $h, 'hdlv2-cd-one-row' ) && false === strpos( $h, 'Demo' ) && false === strpos( $h, 'data-view-as' )
    && 502 === ( $GLOBALS['localized']['HDLV2_CD_POP']['clientId'] ?? 0 ) );
world();
ask( 122, 744, 'same', 'stage1-done' );
list( $to, $h ) = visit();
check( 'S4.7 an older live row of theirs whose client has a newer row with someone else: sent to Clients (the page shows the newest row)',
    home_url( '/clients/' ) === $to && false === strpos( $h, 'Twice' ) );
world();
$GLOBALS['options']['hdlv2_ff_view_as_client'] = 0;
ask( 122, 808 );
list( $to ) = visit();
check( 'S4.8 flag off: the as parameter is ignored (sent to Clients as today)', home_url( '/clients/' ) === $to );
world();
$GLOBALS['options'][ HDLV2_Products::FLAG_ONE_ROW ] = 0;
ask( 122, 808 );
list( $to ) = visit();
check( 'S4.9 the one-row page off: ignored too (only that page has a read-only rendering)', home_url( '/clients/' ) === $to );
world();
$_GET = array( 'as' => array( '808' ) );
$GLOBALS['current_user'] = 122;
list( $to ) = visit();
check( 'S4.10 a malformed as (an array): sent to Clients, no warning', home_url( '/clients/' ) === $to );
check( 'S5.2 no record on any refusal', array() === log_lines() );
world();
ask( 1, 840, 'same', 'stage1-done' );
list( $to, $h ) = visit();
check( 'S4.6 an administrator: the view opens', null === $to && false !== strpos( $h, 'data-view-as="1"' ) && false !== strpos( $h, 'Olive Elsewhere' ) );

echo "S2 the same page, read-only\n";
world(); clear_log();
ask( 122, 808 );
list( $to, $v ) = visit();
check( 'S2.1 the owner opens it: no redirect, the one-row page with data-view-as="1"',
    null === $to && 1 === preg_match( '#<div class="hdlv2-cd-pop hdlv2-cd-one-row" data-view-as="1" data-state="populated">#', $v ) );
check( 'S5.1 one record: practitioner 122, progress 808', 1 === count( log_lines() ) && 1 === preg_match( '/practitioner=122 progress=808\b/', log_lines()[0] ?? '' ) );
check( 'S2.2 the banner before the row: whose dashboard, nothing can be changed, one link back to Clients',
    false !== strpos( $v, 'You are looking at Demo Owner&rsquo;s dashboard, as they see it. Nothing can be changed from here.' )
    && 1 === preg_match( '#<a href="https://[^"/]+/clients/">&larr; Back to your clients</a>#', $v )
    && strpos( $v, 'class="cdp-view-as"' ) < strpos( $v, 'class="cdp-tabs cdp-tabs-products"' ) );
check( 'S2.3 the page is the client\'s: rendered for 508 (dashboard script and inbox point at the client)',
    508 === ( $GLOBALS['localized']['HDLV2_CD_POP']['clientId'] ?? 0 )
    && hash( 'sha256', get_userdata( 508 )->user_email ) === ( $GLOBALS['localized']['HDLV2_INBOX']['clientHash'] ?? '' )
    && 122 === ( $GLOBALS['localized']['HDLV2_INBOX']['practitionerId'] ?? 0 ) );
$today = panel( $v, 'today' );
check( 'S2.4 the check-in card without Begin, and "Demo does this on Sundays"',
    false !== strpos( $today, 'Time for your weekly check-in' ) && false === strpos( $today, 'weekly-check-in' ) && false === strpos( $today, 'Begin &rarr;' )
    && false !== strpos( $today, '>Demo does this on Sundays<' ) );
$ch = panel( $v, 'challenge' );
check( 'S2.5 the 30 days without its form, its "Done for today" or a buy card', false !== strpos( $ch, 'Your 30 days' )
    && false === strpos( $ch, '<form' ) && false === strpos( $ch, 'Done for today' ) && false === strpos( $ch, 'cd30-videos' ) && false === strpos( $ch, 'cd30-offer' ) );
$msg = panel( $v, 'messages' );
check( 'S2.6 Messages: the thread, and "Reply from your Clients page" where the reply box was',
    false !== strpos( $msg, 'data-inbox-thread' ) && false === strpos( $msg, '<form' ) && false === strpos( $msg, 'data-inbox-text' )
    && false !== strpos( $msg, '>Reply from your Clients page<' ) );
check( 'S2.7 the Timeline (the Demo Owner\'s): "Opens from the client\'s own sign-in" in place of the button, no ?t= link',
    false !== strpos( panel( $v, 'timeline' ), 'Opens from the client&rsquo;s own sign-in' ) && false === strpos( $v, 'healthspan-timeline/?t=' ) && false === strpos( $v, 'Open your Timeline' ) );
check( 'S2.8 nowhere: the client\'s token, a buy link, an Ask button, a form, "Done for today"',
    '' !== $v && false === strpos( $v, $TOKEN ) && false === strpos( $v, 'token=' ) && false === stripos( $v, 'altituding' ) && false === strpos( $v, 'data-open-tab=' )
    && false === strpos( $v, '<form' ) && false === strpos( $v, 'Done for today' ) );
$week = (string) call_private( $D, 'pop_week_card', array(
    'flight_plan'  => array( 'id' => 41, 'week_number' => 3, 'week_start' => '2026-10-26', 'identity_statement' => '' ),
    'ticks_by_day' => array( 'monday' => array( 'total' => 1, 'done' => 0, 'actions' => array( array( 'tick_id' => 9, 'category' => 'food', 'action_text' => 'Eat a green thing', 'ticked' => 0 ) ) ) ),
), home_url( '/my-flight-plan/' ) );
check( 'S2.9 the flight-plan ticks render disabled', 1 === preg_match( '#<button type="button" class="cdp-action-check" disabled aria-label="Mark done"></button>#', $week ) );

world();
ask( 122, 802, 'same', 'stage1-done' );
list( , $k ) = visit();
check( 'S2.10 Kim (Stage 1 only): the locked Full Report shows its two lines and no button; no buy link anywhere',
    false !== strpos( panel( $k, 'report' ), HDLV2_Products::ABOUT['full_report'][0] ) && false === strpos( panel( $k, 'report' ), 'cdp-btn' )
    && false === stripos( $k, 'altituding' ) && false === strpos( $k, 'Get the Full Report' ) );
check( 'S2.11 Kim: the first look reads "Opens from the client\'s own sign-in" on the Stage 1 and Timeline tabs, no link',
    false !== strpos( panel( $k, 'stage1' ), 'Opens from the client&rsquo;s own sign-in' ) && false !== strpos( panel( $k, 'timeline' ), 'Opens from the client&rsquo;s own sign-in' )
    && false === strpos( $k, 'See a first look' ) && false === strpos( $k, 'healthspan-timeline/#' ) );
check( 'S2.12 Kim: the locked Flight Plan keeps its two lines and loses its Ask button',
    false !== strpos( panel( $k, 'flight_plan' ), HDLV2_Products::ABOUT['flight_plan'][1] ) && false === strpos( $k, 'Ask Matthew' ) );

world();
set_private( $D, 'view_as', array( 'progress_id' => 803, 'client_user_id' => 503 ) );
$ctx = call_private( $D, 'load_context', 503 );
$ctx['invite_token'] = $INVITE; // the fake does not read widget_invites: hand the context its invite
$pf = (string) call_private( $D, 'path_for_state', $ctx, 'brand-new' );
check( 'S2.13 a brand-new client\'s "Start now" (it carries the invite token) becomes the line', '' === $pf ? false : ( false === strpos( $pf, $INVITE ) && false !== strpos( $pf, 'Opens from the client&rsquo;s own sign-in' ) ) );
set_private( $D, 'view_as', null );
$pf0 = (string) call_private( $D, 'path_for_state', $ctx, 'brand-new' );
check( 'S2.14 the client\'s own "Start now" still carries it', false !== strpos( $pf0, 'invite=' . $INVITE ) && false !== strpos( $pf0, 'Start now &rarr;' ) );

echo "S2 the client's own page is unchanged by the view (every change sits behind view_as)\n";
world(); clear_log();
$GLOBALS['current_user'] = 508; $GLOBALS['journey_state'] = 'populated';
list( $to, $own ) = visit();
check( 'S2.15 the Demo Owner\'s own page: no data-view-as, no banner, no view-as line',
    null === $to && false === strpos( $own, 'data-view-as' ) && false === strpos( $own, 'cdp-view-as' ) && false === strpos( $own, 'Opens from the client' ) );
check( 'S2.16 …and every control the view takes away is there: Begin, the 30 days form, the reply form, the Timeline link',
    false !== strpos( $own, 'weekly-check-in' ) && false !== strpos( $own, 'Done for today' ) && false !== strpos( $own, 'data-inbox-reply' )
    && false !== strpos( $own, 'healthspan-timeline/?t=' . $TOKEN ) );
$week0 = (string) call_private( $D, 'pop_week_card', array(
    'flight_plan'  => array( 'id' => 41, 'week_number' => 3, 'week_start' => '2026-10-26', 'identity_statement' => '' ),
    'ticks_by_day' => array( 'monday' => array( 'total' => 1, 'done' => 0, 'actions' => array( array( 'tick_id' => 9, 'category' => 'food', 'action_text' => 'Eat a green thing', 'ticked' => 0 ) ) ) ),
), home_url( '/my-flight-plan/' ) );
check( 'S2.17 …and its ticks are live buttons', false !== strpos( $week0, '<button type="button" class="cdp-action-check" aria-label="Mark done"></button>' ) );
world();
$GLOBALS['current_user'] = 502; $GLOBALS['journey_state'] = 'stage1-done';
list( , $kown ) = visit();
check( 'S2.18 Kim\'s own page keeps her buy link and her first look', false !== stripos( $kown, 'altituding' ) && false !== strpos( $kown, 'See a first look' ) );
check( 'S5.3 no record when a client opens their own page', array() === log_lines() );

echo "S2 CSS: under [data-view-as] only, DESIGN.md (hairline card, soft fill, no rail)\n";
$va = (string) strstr( $css, '/* ── View as client' );
preg_match_all( '/([^{}]+)\{/', preg_replace( '#/\*.*?\*/#s', '', $va ), $sel );
$loose = array_filter( array_map( 'trim', explode( ',', implode( ',', array_filter( $sel[1], function ( $x ) { return 0 !== strpos( trim( $x ), '@media' ); } ) ) ) ), function ( $x ) { return 0 !== strpos( $x, '.hdlv2-cd-pop[data-view-as] ' ); } );
check( 'S2.19 one block, every selector under .hdlv2-cd-pop[data-view-as]' . ( $loose ? ': ' . implode( ' | ', $loose ) : '' ), '' !== $va && ! empty( $sel[1] ) && ! $loose );
check( 'S2.20 the banner: soft fill, 1px border-grey hairline, no border-left, no ::before',
    1 === preg_match( '/\.cdp-view-as \{[^}]*background: #f8f9fb;[^}]*border: 1px solid #e4e6ea;/', $va )
    && ! preg_match( '/border-left|::before|box-shadow/', $va ) );
check( 'S2.21 the back link has a focus ring; disabled ticks read as disabled',
    1 === preg_match( '/\.cdp-view-as a:focus-visible \{[^}]*outline: 2px solid #3d8da0/', $va ) && 1 === preg_match( '/\.cdp-action-check:disabled \{/', $va ) );

@unlink( $LOG );
done();
