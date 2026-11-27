<?php
/**
 * v0.48.77 (plan 2c) — the Full Report link opens the buying page for the person's band.
 * HDLV2_Offer::band_url() (green → -slower, blue → -average, red → -faster, anything else the
 * plain url) and personal() using it, so the Stage 1 e-mail's picture and button and the PDF's
 * page-2 card open the band page with no Make or PDF change. Drives the REAL HDLV2_Offer and the
 * real rest_capture_lead + drain (37 keys since v0.49.08: + band_video_thumb_url).
 *
 * Run:  php scenario-band-url.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/../full-report/stubs.php';
load_sources();
$GLOBALS['options'] = array( 'hdlv2_db_version' => '3.29' );

echo "── B. band_url() ──\n";
$has = method_exists( 'HDLV2_Offer', 'band_url' );
check( 'B.0 HDLV2_Offer::band_url exists', $has );
$band = function ( $b ) use ( $has ) { return $has ? HDLV2_Offer::band_url( $b ) : null; };
check( 'B.1 green → /full-report-slower', 'https://altituding.com/full-report-slower' === $band( 'green' ) );
check( 'B.2 blue → /full-report-average', 'https://altituding.com/full-report-average' === $band( 'blue' ) );
check( 'B.3 red → /full-report-faster', 'https://altituding.com/full-report-faster' === $band( 'red' ) );
check( 'B.4 no band → the plain url', 'https://altituding.com/full-report' === $band( '' ) );
check( 'B.5 an unknown band → the plain url', 'https://altituding.com/full-report' === $band( 'purple' ) );
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'url' => 'https://preview.example.test/full-report/' );
check( 'B.6 a saved url with a trailing slash still gives a working band address', 'https://preview.example.test/full-report-faster' === $band( 'red' ) );
unset( $GLOBALS['options']['hdlv2_stage1_offer'] );

echo "── P. personal() opens the band page ──\n";
$p = HDLV2_Offer::personal( 'ivy+x@example.test', 'red' );
check( 'P.1 offer_url = band address + #email= for a good e-mail', 'https://altituding.com/full-report-faster#email=' . rawurlencode( 'ivy+x@example.test' ) === $p['offer_url'] );
check( 'P.2 a bad e-mail: the band address alone', 'https://altituding.com/full-report-slower' === HDLV2_Offer::personal( 'not an email', 'green' )['offer_url'] );
check( 'P.3 no band: the plain url with the e-mail', 'https://altituding.com/full-report#email=ivy%40example.test' === HDLV2_Offer::personal( 'ivy@example.test' )['offer_url'] );
check( 'P.4 still the three keys, no code', array( 'offer_code', 'offer_url', 'offer_code_ends' ) === array_keys( $p ) && '' === $p['offer_code'] && '' === $p['offer_code_ends'] );

echo "── L. a real lead's payload ──\n";
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'practitioner_id' => 206 ); // the harness practitioner
$wpdb = fresh_wpdb( 'open' );
post_lead( lead_params( 'band@example.test' ) ); // the server's own rate for these answers: 1.26 → red
drain_last_lead( $wpdb );
$make = null;
foreach ( $GLOBALS['captured_posts'] as $c ) {
    if ( false !== strpos( $c['url'], 'hook.example.test/stage1' ) ) $make = json_decode( $c['args']['body'], true );
}
check( 'L.1 the webhook fired with 37 keys (count unchanged)', is_array( $make ) && 37 === count( $make ) );
check( 'L.2 band red, offer_url the faster page with the e-mail', is_array( $make ) && 'red' === $make['band']
    && 'https://altituding.com/full-report-faster#email=' . rawurlencode( 'band@example.test' ) === $make['offer_url'] );
$posts = array_filter( $GLOBALS['captured_posts'], function ( $c ) { return false === strpos( $c['url'], 'hook.example.test' ); } );
check( 'L.3 no other HTTP call leaves', array() === array_values( $posts ) );
done();
