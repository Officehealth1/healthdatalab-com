<?php
/**
 * Task 2/7 — the one offer setting and the Stage 1 payload that carries it.
 * HDLV2_Offer::get() defaults + partial override, the nine payload keys as
 * strings, money as "$27", and the real rest_capture_lead + drain firing 36 keys (v0.48.50: + band, band_video_url, offer_code_ends).
 * v0.48.73 (flat $27, Matthew 2026-11-16): no code and no list price; offer_code, offer_list and
 * offer_code_ends ride as '' (Make's 36-key contract), offer_url carries no code, and no lead asks
 * Altituding for a code.
 *
 * Run:  php scenario-offer.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/stubs.php';
load_sources();
$GLOBALS['options'] = array( 'hdlv2_db_version' => '3.29' );

echo "── O. the offer setting ──\n";
$o = class_exists( 'HDLV2_Offer' ) ? HDLV2_Offer::get() : array();
check( 'O.1 defaults when the option is absent', $o === ( class_exists( 'HDLV2_Offer' ) ? HDLV2_Offer::DEFAULTS : null )
    && '$' === $o['currency'] && '27' === $o['full_today_price']
    && 'Want to go deeper?' === $o['title'] && false === $o['stage1_free_with_code'] );
check( 'O.1b no code and no list price in the defaults (flat $27)', ! array_key_exists( 'code', $o ) && ! array_key_exists( 'full_list_price', $o ) );
// A saved option from before 0.48.73 still holds code / full_list_price: dropped as unknown keys.
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'code' => 'SUMMERFREE', 'full_list_price' => '129', 'full_today_price' => '19', 'junk' => 'x', 'stage1_free_with_code' => '1' );
$o = HDLV2_Offer::get();
check( 'O.2 a partial option overrides only its keys; an old code or list price is dropped', '19' === $o['full_today_price'] && ! isset( $o['code'] ) && ! isset( $o['full_list_price'] ) && ! isset( $o['junk'] ) && true === $o['stage1_free_with_code'] );
$GLOBALS['options']['hdlv2_stage1_offer'] = '{"currency":"£","full_today_price":"27 dollars","hook":"<b>Hi</b>"}';
$o = HDLV2_Offer::get();
check( 'O.3 JSON string option read; bad price falls back; text cleaned', '£' === $o['currency'] && '27' === $o['full_today_price'] && 'Hi' === $o['hook'] );
unset( $GLOBALS['options']['hdlv2_stage1_offer'] );

$keys = array( 'offer_show', 'offer_title', 'offer_hook', 'offer_today', 'offer_list', 'offer_code', 'offer_url', 'offer_caption', 'offer_video_url', 'offer_code_ends' );
$p    = HDLV2_Offer::payload_keys( true );
check( 'O.4 payload_keys are exactly the ten (v0.48.50: + offer_code_ends)', array_keys( $p ) === $keys );
check( 'O.5 every value a string (Make type cache)', count( array_filter( $p, 'is_string' ) ) === 10 );
check( 'O.6 money is currency + number', '$27' === $p['offer_today'] && '$29' === HDLV2_Offer::money( '29' ) );
check( 'O.6b offer_list, offer_code and offer_code_ends are empty strings; offer_url has no code', '' === $p['offer_list'] && '' === $p['offer_code'] && '' === $p['offer_code_ends'] && 'https://altituding.com/full-report' === $p['offer_url'] );
check( 'O.7 offer_show "1" / "0"', '1' === $p['offer_show'] && '0' === HDLV2_Offer::payload_keys( false )['offer_show'] );
check( 'O.8 public config never carries the code', array_keys( HDLV2_Offer::public_config_fields() ) === array( 'stage1_list_price', 'currency', 'stage1_free_with_code' ) );

$GLOBALS['options']['hdlv2_stage1_offer'] = '{"hook":"What is ageing you fastest?"}';
check( 'O.10 the hook option reaches the payload as offer_hook', 'What is ageing you fastest?' === HDLV2_Offer::payload_keys( true )['offer_hook'] );
unset( $GLOBALS['options']['hdlv2_stage1_offer'] );

check( 'O.9 the offer belongs to one practitioner (122 by default; 0 = all)', HDLV2_Offer::applies_to( 122 ) && ! HDLV2_Offer::applies_to( 206 ) );

echo "── P. the Stage 1 payload (public path) ──\n";
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'practitioner_id' => 206 ); // the harness practitioner
$wpdb = fresh_wpdb( 'open' );
$r    = post_lead( lead_params( 'free@example.test' ) );
drain_last_lead( $wpdb ); // v0.48.16 — the drain sends it
$make = null;
foreach ( $GLOBALS['captured_posts'] as $c ) {
    if ( false !== strpos( $c['url'], 'hook.example.test/stage1' ) ) $make = json_decode( $c['args']['body'], true );
}
check( 'P.1 the webhook fired with 36 keys', is_array( $make ) && 36 === count( $make ) );
check( 'P.2 a free lead gets offer_show "1"', is_array( $make ) && '1' === ( $make['offer_show'] ?? null ) );
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'practitioner_id' => 122 );
$wpdb = fresh_wpdb( 'open' );
post_lead( lead_params( 'other@example.test' ) );
drain_last_lead( $wpdb );
$m2 = null;
foreach ( $GLOBALS['captured_posts'] as $c ) { if ( false !== strpos( $c['url'], 'hook.example.test/stage1' ) ) $m2 = json_decode( $c['args']['body'], true ); }
check( 'P.4 another practitioner\'s lead: still 36 keys, offer_show "0"', is_array( $m2 ) && 36 === count( $m2 ) && '0' === $m2['offer_show'] );
check( 'P.3 the ten keys ride as strings', is_array( $make ) && 10 === count( array_filter( array_intersect_key( $make, array_flip( $keys ) ), 'is_string' ) ) );
check( 'P.5 the offer lead\'s payload: no code, no list price, no end date; the link without code, the e-mail in its fragment', is_array( $make )
    && '' === $make['offer_code'] && '' === $make['offer_list'] && '' === $make['offer_code_ends']
    && 'https://altituding.com/full-report-faster#email=' . rawurlencode( 'free@example.test' ) === $make['offer_url'] ); // v0.48.77: the lead's band (red) page

echo "── Q. personal(): no code, no request, even with the old wp-config constants still set ──\n";
define( 'HDLV2_FULL_CODE_URL', 'https://alt.example.test/.netlify/functions/full-report-code' );
$stub_access = 'stub-full-code-access';
define( 'HDLV2_FULL_CODE_KEY', $stub_access );
$GLOBALS['captured_posts'] = array();
$q = HDLV2_Offer::personal( 'ivy@example.test' );
check( 'Q.1 the three keys: no code, no end date, the offer url with the e-mail in its fragment', array(
    'offer_code' => '', 'offer_url' => 'https://altituding.com/full-report#email=ivy%40example.test', 'offer_code_ends' => '' ) === $q );
check( 'Q.2 no request leaves (Altituding is never asked for a code)', array() === $GLOBALS['captured_posts'] );
check( 'Q.3 a bad e-mail: the plain offer url', 'https://altituding.com/full-report' === HDLV2_Offer::personal( 'not an email' )['offer_url'] );
check( 'Q.4 the code key reader is gone', ! method_exists( 'HDLV2_Offer', 'code_key' ) );
done();
