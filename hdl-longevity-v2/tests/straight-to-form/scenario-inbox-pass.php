<?php
/**
 * v0.48.81 (plan 2h) — the pass in the Stage 1 results e-mail and PDF (behind hdlv2_ff_straight_to_form).
 * HDLV2_Offer::personal() adds &own=<time>.<64 hex> to the offer link's fragment only with the switch on,
 * a good e-mail and a practitioner; inbox_pass_ok() checks it (own e-mail, practitioner, 3 days, not from
 * the future, the site's own salt). Switch off: the link is today's, byte for byte. Drives the REAL
 * HDLV2_Offer, the real rest_capture_lead + drain (36 keys) and the real public config answers.
 *
 * Run:  php scenario-inbox-pass.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/../full-report/stubs.php';
function wp_salt( $scheme = 'auth' ) { return $GLOBALS['salt'] ?? 'salt-one-for-tests'; }
load_sources();
$ROOT = __DIR__ . '/../../';
require_once $ROOT . 'includes/sprint-2c/class-hdlv2-healthspan-timeline.php';
$GLOBALS['options'] = array( 'hdlv2_db_version' => '3.29' );

$has_pass = method_exists( 'HDLV2_Offer', 'inbox_pass' ) && method_exists( 'HDLV2_Offer', 'inbox_pass_ok' );
$pass     = function ( $e, $p, $t = null ) use ( $has_pass ) { return $has_pass ? HDLV2_Offer::inbox_pass( $e, $p, $t ) : ''; };
$ok       = function ( $x, $e, $p, $now = null ) use ( $has_pass ) { return $has_pass && HDLV2_Offer::inbox_pass_ok( $x, $e, $p, $now ); };
$TODAY    = 'https://altituding.com/full-report-faster#email=' . rawurlencode( 'ivy+x@example.test' );
$SHAPE    = '/^\d{10}\.[a-f0-9]{64}$/';

echo "── IP1. switch absent: today's link, byte for byte ──\n";
check( 'IP1.1 with an e-mail and a practitioner', $TODAY === HDLV2_Offer::personal( 'ivy+x@example.test', 'red', 122 )['offer_url'] );
check( 'IP1.2 without an e-mail', 'https://altituding.com/full-report-faster' === HDLV2_Offer::personal( '', 'red', 122 )['offer_url'] );
check( 'IP1.3 the two-argument call (older callers)', $TODAY === HDLV2_Offer::personal( 'ivy+x@example.test', 'red' )['offer_url'] );
$GLOBALS['options']['hdlv2_ff_straight_to_form'] = '0';
check( 'IP1.4 switch set to 0: today\'s link', $TODAY === HDLV2_Offer::personal( 'ivy+x@example.test', 'red', 122 )['offer_url'] );

echo "── IP2. switch on: the pass rides after #email= ──\n";
$GLOBALS['options']['hdlv2_ff_straight_to_form'] = '1';
$url = HDLV2_Offer::personal( 'ivy+x@example.test', 'red', 122 )['offer_url'];
$own = preg_match( '/&own=([^&]*)$/', $url, $m ) ? $m[1] : '';
check( 'IP2.1 the link is today\'s plus &own=<10 digits>.<64 hex>', 0 === strpos( $url, $TODAY . '&own=' ) && preg_match( $SHAPE, $own ) );
check( 'IP2.2 the pass time is now', abs( (int) $own - time() ) < 5 );
check( 'IP2.3 no pass without a good e-mail', 'https://altituding.com/full-report-faster' === HDLV2_Offer::personal( 'not an email', 'red', 122 )['offer_url'] );
check( 'IP2.4 no pass without a practitioner', $TODAY === HDLV2_Offer::personal( 'ivy+x@example.test', 'red', 0 )['offer_url']
    && $TODAY === HDLV2_Offer::personal( 'ivy+x@example.test', 'red' )['offer_url'] );
check( 'IP2.5 still the three keys, no code', array( 'offer_code', 'offer_url', 'offer_code_ends' ) === array_keys( HDLV2_Offer::personal( 'ivy+x@example.test', 'red', 122 ) ) );

echo "── IP3. the pass checks true only as made ──\n";
$t = time() - 3600;
$p = $pass( 'ivy+x@example.test', 122, $t );
check( 'IP3.1 its own e-mail, practitioner and time: true', $ok( $p, 'ivy+x@example.test', 122 ) );
check( 'IP3.2 the e-mail\'s own pass from personal(): true', $ok( $own, 'ivy+x@example.test', 122 ) );
$flip = substr( $p, 0, -1 ) . ( 'a' === substr( $p, -1 ) ? 'b' : 'a' );
check( 'IP3.3 one changed character: false', ! $ok( $flip, 'ivy+x@example.test', 122 ) );
$later = ( $t + 1 ) . substr( $p, 10 );
check( 'IP3.4 the time changed, the hash kept: false', ! $ok( $later, 'ivy+x@example.test', 122 ) );
$GLOBALS['salt'] = 'another-site-salt';
check( 'IP3.5 another salt (another site, or a changed secret): false', ! $ok( $p, 'ivy+x@example.test', 122 ) );
unset( $GLOBALS['salt'] );
foreach ( array( '', 'x', $t . '.' . str_repeat( 'g', 64 ), $t . '.' . strtoupper( substr( $p, 11 ) ), ' ' . $p, $p . "\n", array( $p ), null, 12 ) as $i => $bad ) {
    check( "IP3.6.$i a badly shaped pass: false, no error", ! $ok( $bad, 'ivy+x@example.test', 122 ) );
}

echo "── IP4. 3 days, never from the future ──\n";
$now = time();
check( 'IP4.1 made 2 days 23 hours ago: true', $ok( $pass( 'ivy@example.test', 122, $now - 3 * DAY_IN_SECONDS + 3600 ), 'ivy@example.test', 122, $now ) );
check( 'IP4.2 made 3 days and a minute ago: false', ! $ok( $pass( 'ivy@example.test', 122, $now - 3 * DAY_IN_SECONDS - 60 ), 'ivy@example.test', 122, $now ) );
check( 'IP4.3 made 30 seconds ahead (clock skew): true', $ok( $pass( 'ivy@example.test', 122, $now + 30 ), 'ivy@example.test', 122, $now ) );
check( 'IP4.4 made more than a minute ahead: false', ! $ok( $pass( 'ivy@example.test', 122, $now + 120 ), 'ivy@example.test', 122, $now ) );
check( 'IP4.5 the constant says 3 days', defined( 'HDLV2_Offer::INBOX_PASS_DAYS' ) && 3 === HDLV2_Offer::INBOX_PASS_DAYS );

echo "── IP5. letter case of the e-mail ──\n";
check( 'IP5.1 made for Ivy@Example.TEST, checked for ivy@example.test (Stripe gives it lower-cased): true',
    $ok( $pass( 'Ivy@Example.TEST', 122 ), 'ivy@example.test', 122 ) );

echo "── IP6. another person or practitioner ──\n";
check( 'IP6.1 another e-mail: false', ! $ok( $p, 'ivy+y@example.test', 122 ) );
check( 'IP6.2 another practitioner: false', ! $ok( $p, 'ivy+x@example.test', 206 ) );

echo "── IP7. a real lead's Stage 1 payload, switch on ──\n";
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'practitioner_id' => 206 ); // the harness practitioner
$make = function () {
    $make = null;
    foreach ( $GLOBALS['captured_posts'] as $c ) {
        if ( false !== strpos( $c['url'], 'hook.example.test/stage1' ) ) $make = json_decode( $c['args']['body'], true );
    }
    return $make;
};
$wpdb = fresh_wpdb( 'open' );
post_lead( lead_params( 'band@example.test' ) );
drain_last_lead( $wpdb );
$on = $make();
$lead_own = is_array( $on ) && preg_match( '/&own=([^&]*)$/', $on['offer_url'], $mm ) ? $mm[1] : '';
check( 'IP7.1 still 36 keys', is_array( $on ) && 36 === count( $on ) );
check( 'IP7.2 offer_url = the band page, #email=, &own= for this lead and practitioner',
    is_array( $on ) && 0 === strpos( $on['offer_url'], 'https://altituding.com/full-report-faster#email=' . rawurlencode( 'band@example.test' ) . '&own=' )
    && $ok( $lead_own, 'band@example.test', 206 ) );
check( 'IP7.3 the pass is in offer_url only (no other key carries it)', is_array( $on ) && 1 === count( array_filter( $on, function ( $v ) use ( $lead_own ) { return '' !== $lead_own && is_string( $v ) && false !== strpos( $v, $lead_own ); } ) ) );
unset( $GLOBALS['options']['hdlv2_ff_straight_to_form'] );
$wpdb = fresh_wpdb( 'open' );
post_lead( lead_params( 'band@example.test' ) );
drain_last_lead( $wpdb );
$off = $make();
check( 'IP7.4 switch off: 36 keys and offer_url is today\'s', is_array( $off ) && 36 === count( $off )
    && 'https://altituding.com/full-report-faster#email=' . rawurlencode( 'band@example.test' ) === $off['offer_url'] );

echo "── IP8. the public config answers never carry a pass ──\n";
function public_cfg_json() {
    $r = widget_instance()->rest_get_public_config( new FakeRequest( array(), array(), 'GET', '', array( 'practitioner_id' => 206 ) ) );
    return json_encode( $r instanceof WP_REST_Response ? $r->get_data() : $r );
}
function invite_cfg_json() {
    $GLOBALS['wpdb']->invite = invite_row( array( 'source' => 'paid_stage1' ) );
    $link = TOKEN; // the harness's fake invite link
    $r    = widget_instance()->rest_verify_invite( new FakeRequest( array( 'token' => $link ) ) );
    return json_encode( $r instanceof WP_REST_Response ? $r->get_data() : $r );
}
foreach ( array( 'off' => null, 'on' => '1' ) as $label => $flag ) {
    $GLOBALS['options'] = array( 'hdlv2_db_version' => '3.29', 'hdlv2_ff_result_offer' => '1', 'hdlv2_stage1_offer' => array( 'practitioner_id' => 206 ) );
    if ( $flag ) $GLOBALS['options']['hdlv2_ff_straight_to_form'] = $flag;
    fresh_wpdb( 'paid' );
    $pc = public_cfg_json();
    $ic = invite_cfg_json();
    check( "IP8.1 switch $label: the public config has result_offer and no own=", false !== strpos( $pc, 'band_urls' ) && false === strpos( $pc, 'own=' ) );
    check( "IP8.2 switch $label: the invite answer has no own=", false === strpos( $ic, 'own=' ) );
}
done();
