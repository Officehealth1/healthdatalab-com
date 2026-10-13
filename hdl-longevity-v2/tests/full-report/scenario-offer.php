<?php
/**
 * Task 2/7 — the one offer setting and the Stage 1 payload that carries it.
 * HDLV2_Offer::get() defaults + partial override, the nine payload keys as
 * strings, money as "$27", and the real rest_capture_lead firing 33 keys.
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
    && '$' === $o['currency'] && 'YOURCODE' === $o['code'] && '27' === $o['full_today_price'] && '129' === $o['full_list_price']
    && 'Want to go deeper?' === $o['title'] && false === $o['stage1_free_with_code'] );
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'code' => 'SUMMERFREE', 'full_today_price' => '19', 'junk' => 'x', 'stage1_free_with_code' => '1' );
$o = HDLV2_Offer::get();
check( 'O.2 a partial option overrides only its keys', 'SUMMERFREE' === $o['code'] && '19' === $o['full_today_price'] && '129' === $o['full_list_price'] && ! isset( $o['junk'] ) && true === $o['stage1_free_with_code'] );
$GLOBALS['options']['hdlv2_stage1_offer'] = '{"currency":"£","full_today_price":"27 dollars","hook":"<b>Hi</b>"}';
$o = HDLV2_Offer::get();
check( 'O.3 JSON string option read; bad price falls back; text cleaned', '£' === $o['currency'] && '27' === $o['full_today_price'] && 'Hi' === $o['hook'] );
unset( $GLOBALS['options']['hdlv2_stage1_offer'] );

$keys = array( 'offer_show', 'offer_title', 'offer_hook', 'offer_today', 'offer_list', 'offer_code', 'offer_url', 'offer_caption', 'offer_video_url' );
$p    = HDLV2_Offer::payload_keys( true );
check( 'O.4 payload_keys are exactly the nine', array_keys( $p ) === $keys );
check( 'O.5 every value a string (Make type cache)', count( array_filter( $p, 'is_string' ) ) === 9 );
check( 'O.6 money is currency + number', '$27' === $p['offer_today'] && '$129' === $p['offer_list'] && '$29' === HDLV2_Offer::money( '29' ) );
check( 'O.7 offer_show "1" / "0"', '1' === $p['offer_show'] && '0' === HDLV2_Offer::payload_keys( false )['offer_show'] );
check( 'O.8 public config never carries the code', array_keys( HDLV2_Offer::public_config_fields() ) === array( 'stage1_list_price', 'currency', 'stage1_free_with_code' ) );

check( 'O.9 the offer belongs to one practitioner (122 by default; 0 = all)', HDLV2_Offer::applies_to( 122 ) && ! HDLV2_Offer::applies_to( 206 ) );

echo "── P. the Stage 1 payload (public path) ──\n";
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'practitioner_id' => 206 ); // the harness practitioner
$wpdb = fresh_wpdb( 'open' );
$r    = post_lead( lead_params( 'free@example.test' ) );
$make = null;
foreach ( $GLOBALS['captured_posts'] as $c ) {
    if ( false !== strpos( $c['url'], 'hook.example.test/stage1' ) ) $make = json_decode( $c['args']['body'], true );
}
check( 'P.1 the webhook fired with 33 keys', is_array( $make ) && 33 === count( $make ) );
check( 'P.2 a free lead gets offer_show "1"', is_array( $make ) && '1' === ( $make['offer_show'] ?? null ) );
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'practitioner_id' => 122 );
fresh_wpdb( 'open' );
post_lead( lead_params( 'other@example.test' ) );
$m2 = null;
foreach ( $GLOBALS['captured_posts'] as $c ) { if ( false !== strpos( $c['url'], 'hook.example.test/stage1' ) ) $m2 = json_decode( $c['args']['body'], true ); }
check( 'P.4 another practitioner\'s lead: still 33 keys, offer_show "0"', is_array( $m2 ) && 33 === count( $m2 ) && '0' === $m2['offer_show'] );
check( 'P.3 the nine keys ride as strings', is_array( $make ) && 9 === count( array_filter( array_intersect_key( $make, array_flip( $keys ) ), 'is_string' ) ) );
done();
