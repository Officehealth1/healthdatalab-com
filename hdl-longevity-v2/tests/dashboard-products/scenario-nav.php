<?php
/**
 * Story 3: one door. The header's Dashboard item, the signed-out card, and the button in the
 * three client emails.
 *   php tests/dashboard-products/scenario-nav.php
 */
require __DIR__ . '/stubs.php';

$P = dirname( __DIR__, 2 ) . '/includes/';
require_once $P . 'sprint-4/class-hdlv2-client-dashboard.php';
require_once $P . 'sprint-2/class-hdlv2-email-templates.php';

/** Menu 66 as the theme hands it over. */
function menu() {
    return array(
        (object) array( 'url' => 'https://stby.test/clients/', 'title' => 'Dashboard' ),
        (object) array( 'url' => 'https://stby.test/longevity-form-rate-of-aging/', 'title' => 'Longevity Report' ),
        (object) array( 'url' => 'https://stby.test/health-report/', 'title' => 'Health Report' ),
        (object) array( 'url' => 'https://stby.test/account/', 'title' => 'My Profile' ),
    );
}
function dashboard_item_url( $uid ) {
    $GLOBALS['current_user'] = $uid;
    foreach ( HDLV2_Client_Dashboard::get_instance()->filter_client_nav( menu(), null ) as $i ) {
        if ( 'Dashboard' === $i->title ) return $i->url;
    }
    return null;
}

$wpdb = fresh();
$roles = array( 701 => 'um_client', 702 => 'um_consumer', 703 => 'um_annual_subscriber', 704 => 'um_practitioner-invite' );
foreach ( $roles as $uid => $role ) {
    $GLOBALS['users'][ $uid ] = array( "v1-$uid@stby.test", "V1 $uid", array( $role ) ); // no V2 record, no V2 stamp
}
$GLOBALS['users'][705] = array( 'p705@stby.test', 'Prac 705', array( 'um_practitioner' ) );

echo "N1 flag on: every signed-in non-practitioner's Dashboard item opens /my-dashboard/\n";
foreach ( $roles as $uid => $role ) {
    check( "N1 $role", 'https://stby.test/my-dashboard/' === dashboard_item_url( $uid ) );
}
check( 'N1.5 um_practitioner: untouched', 'https://stby.test/clients/' === dashboard_item_url( 705 ) );
check( 'N1.6 administrator: untouched', 'https://stby.test/clients/' === dashboard_item_url( 1 ) );
$GLOBALS['current_user'] = 701;
$items = HDLV2_Client_Dashboard::get_instance()->filter_client_nav( menu(), null );
check( 'N1.7 a V1 client keeps their other items (only Dashboard moves)', 4 === count( $items ) && 'https://stby.test/health-report/' === $items[2]->url );

echo "N2 flag off: a V1 client's menu is unchanged\n";
$GLOBALS['options']['hdlv2_ff_dashboard_products'] = 0;
check( 'N2.1 um_client keeps /clients/', 'https://stby.test/clients/' === dashboard_item_url( 701 ) );
$GLOBALS['options']['hdlv2_ff_dashboard_products'] = 1;

echo "N3 signed out on /my-dashboard/\n";
$GLOBALS['current_user'] = 0;
$card = HDLV2_Client_Dashboard::get_instance()->render_shortcode( array() );
check( 'N3.1 the one sentence', false !== strpos( $card, 'Sign in to see your dashboard' ) );
check( 'N3.2 no second sentence', false === strpos( $card, 'Use the link in your invitation email' ) && false === strpos( $card, '<p' ) );
check( 'N3.3 one button, to the sign-in page, back to the dashboard', 1 === substr_count( $card, '<a ' ) && false !== strpos( $card, 'href="https://stby.test/login/?redirect_to=https%3A%2F%2Fstby.test%2Fmy-dashboard%2F"' ) );
check( 'N3.4 never wp-login.php', false === strpos( $card, 'wp-login.php' ) );
$GLOBALS['options']['hdlv2_ff_dashboard_products'] = 0;
$old = HDLV2_Client_Dashboard::get_instance()->render_shortcode( array() );
check( 'N3.5 flag off: the card as before', false !== strpos( $old, 'Use the link in your invitation email' ) );
$GLOBALS['options']['hdlv2_ff_dashboard_products'] = 1;

echo "N4 the three client emails carry Open my dashboard\n";
$draft = HDLV2_Email_Templates::stage3_complete( array( 'client_name' => 'Jo Test', 'client_email' => 'team+dash@irislab.com', 'draft_url' => 'https://stby.test/longevity-draft-report/?t=x' ) );
$final = HDLV2_Email_Templates::final_report_ready_client( array( 'client_name' => 'Jo Test', 'report_url' => 'https://stby.test/longevity-draft-report/?t=x' ) );
HDLV2_Email_Templates::flight_plan_ready( array( 'client_name' => 'Jo Test', 'client_email' => 'team+dash@irislab.com', 'plan_url' => 'https://stby.test/my-flight-plan/', 'pdf_url' => 'https://stby.test/x.pdf', 'week_number' => 2 ) );
HDLV2_Email_Templates::flight_plan_ready( array( 'client_name' => 'Jo Test', 'client_email' => 'team+dash@irislab.com', 'plan_url' => 'https://stby.test/my-flight-plan/', 'pdf_url' => '', 'week_number' => 2 ) );
$button = "href='https://stby.test/my-dashboard/'";
foreach ( array( 'draft ready' => $draft, 'final report ready' => $final, 'flight plan ready (PDF)' => $GLOBALS['mails'][0] ?? '', 'flight plan on the way' => $GLOBALS['mails'][1] ?? '' ) as $name => $html ) {
    check( "N4 $name: one Open my dashboard button to /my-dashboard/", 1 === substr_count( $html, 'Open my dashboard' ) && false !== strpos( $html, $button ) );
    check( "N4 $name: after the primary button", strpos( $html, 'Open my dashboard' ) > strpos( $html, '&rarr;' ) );
}
$GLOBALS['options']['hdlv2_ff_dashboard_products'] = 0;
$GLOBALS['mails'] = array();
HDLV2_Email_Templates::flight_plan_ready( array( 'client_name' => 'Jo', 'client_email' => 'team+dash@irislab.com', 'plan_url' => 'u', 'pdf_url' => '', 'week_number' => 1 ) );
check( 'N5 flag off: no button in any of the three',
    false === strpos( HDLV2_Email_Templates::stage3_complete( array( 'client_name' => 'Jo', 'draft_url' => 'x' ) ), 'Open my dashboard' )
    && false === strpos( HDLV2_Email_Templates::final_report_ready_client( array( 'client_name' => 'Jo', 'report_url' => 'x' ) ), 'Open my dashboard' )
    && false === strpos( $GLOBALS['mails'][0], 'Open my dashboard' ) );
$GLOBALS['options']['hdlv2_ff_dashboard_products'] = 1;
check( 'N6 the practitioner draft email gets no client button', false === strpos( HDLV2_Email_Templates::stage3_draft_ready( array( 'client_name' => 'Jo', 'dashboard_url' => 'https://stby.test/clients/' ) ), 'Open my dashboard' ) );

done();
