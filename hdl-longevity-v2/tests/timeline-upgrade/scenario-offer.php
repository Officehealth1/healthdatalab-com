<?php
/**
 * Plan 3 (v0.48.82), P4/P6 — HDLV2_Timeline_Upgrade::offer_for( $row, $now ): what a buyer without
 * the Timeline is offered, with the clock passed in. Seven days from the form, to the end of that
 * London day; then $27 with the Timeline-alone link, or nothing with no such link.
 *
 * Run:  php scenario-offer.php   (self-asserting, exit 0/1)
 */
require __DIR__ . '/stubs-tl.php';
tl_load();
$U = 'HDLV2_Timeline_Upgrade';

function buyer_row( $w, $sent = '2026-11-17 15:30:00', $stamp = null, $prac = 122, $email = 'fr1+x@example.test' ) {
    $p = tl_progress( 41, $email, $sent, $prac );
    $w->progress_rows[] = $p;
    $w->seed_ticket( array( 'client_email' => $email, 'timeline_paid_ref' => $stamp, 'practitioner_id' => $prac ) );
    return $p;
}
$DAY1  = london( '2026-11-18 09:00:00' );
$LAST  = london( '2026-11-24 23:59:00' );
$DAY8  = london( '2026-11-25 00:00:30' );
$UP    = 'https://buy.stripe.example.test/upgrade?locked_prefilled_email=fr1%2Bx%40example.test';
$ALONE = 'https://buy.stripe.example.test/alone?locked_prefilled_email=fr1%2Bx%40example.test';

echo "── O1. nothing ──\n";
$w = tl_fresh( false );
check( 'O1.1 switch absent → null', null === $U::offer_for( buyer_row( $w ), $DAY1 ) );
$w = tl_fresh();
$w->config->practitioner_user_id = 206;
check( 'O1.2 a practitioner without the offer → null', null === $U::offer_for( buyer_row( $w, '2026-11-17 15:30:00', null, 206 ), $DAY1 ) );
$w = tl_fresh();
check( 'O1.3 an owner (stamped) → null', null === $U::offer_for( buyer_row( $w, '2026-11-17 15:30:00', 'cs_test_paid' ), $DAY1 ) );
$w = tl_fresh();
$p = tl_progress( 41, 'fr1+x@example.test', '2026-11-17 15:30:00' );
check( 'O1.4 no Stripe ticket (keeps the Timeline) → null', null === $U::offer_for( $p, $DAY1 ) );
$w = tl_fresh();
$GLOBALS['options']['hdlv2_stage1_offer'] = array();
check( 'O1.5 no upgrade link set → null', null === $U::offer_for( buyer_row( $w ), $DAY1 ) );

echo "── O2. inside the seven days ──\n";
$w = tl_fresh();
$p = buyer_row( $w );
$o = $U::offer_for( $p, $DAY1 );
check( 'O2.1 day 1 → $10, worth $27, the date, the upgrade link with the e-mail locked and encoded',
    is_array( $o ) && '$10' === $o['price'] && '$27' === $o['worth'] && '24 November 2026' === $o['ends'] && $UP === $o['url'] );
check( 'O2.2 ends_at is the end of 24 November, London time', london( '2026-11-24 23:59:59' ) === ( $o['ends_at'] ?? 0 ) );
$o = $U::offer_for( $p, $LAST );
check( 'O2.3 day 7 at 23:59 → still $10 with the date', is_array( $o ) && '$10' === $o['price'] && '24 November 2026' === $o['ends'] );
$w = tl_fresh();
$p = buyer_row( $w, '2026-07-01 23:30:00' ); // summer: London is UTC+1
$o = $U::offer_for( $p, london( '2026-07-08 23:58:00' ) );
check( 'O2.4 a form sent late on a summer evening runs to the end of the 8 July London day', is_array( $o ) && '8 July 2026' === $o['ends'] );

echo "── O3. the form not sent yet ──\n";
$w = tl_fresh();
$o = $U::offer_for( buyer_row( $w, null ), $DAY8 );
check( 'O3.1 $10, no date, no end', is_array( $o ) && '$10' === $o['price'] && '' === $o['ends'] && 0 === $o['ends_at'] && $UP === $o['url'] );

echo "── O4. day 8 ──\n";
$w = tl_fresh();
$o = $U::offer_for( buyer_row( $w ), $DAY8 );
check( 'O4.1 $27, no date, the Timeline-alone link', is_array( $o ) && '$27' === $o['price'] && '' === $o['ends'] && 0 === $o['ends_at'] && $ALONE === $o['url'] );
$w = tl_fresh();
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'timeline_upgrade_url' => 'https://buy.stripe.example.test/upgrade' );
check( 'O4.2 no Timeline-alone link → null (no surface shows a date that has passed)', null === $U::offer_for( buyer_row( $w ), $DAY8 ) );

echo "── O5. the addresses ──\n";
$w = tl_fresh();
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'timeline_upgrade_url' => 'http://buy.stripe.example.test/upgrade' );
check( 'O5.1 a link that is not https:// is refused', null === $U::offer_for( buyer_row( $w ), $DAY1 ) );
$w = tl_fresh();
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'timeline_upgrade_url' => 'javascript:alert(1)' );
check( 'O5.2 a javascript: link is refused', null === $U::offer_for( buyer_row( $w ), $DAY1 ) );
$w = tl_fresh();
$o = $U::offer_for( buyer_row( $w, '2026-11-17 15:30:00', null, 122, 'not an e-mail' ), $DAY1 );
check( 'O5.3 a row with no good e-mail → the link alone', is_array( $o ) && 'https://buy.stripe.example.test/upgrade' === $o['url'] );

echo "── O6. the offer setting ──\n";
$w = tl_fresh();
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'timeline_upgrade_url' => 'https://buy.stripe.example.test/upgrade', 'timeline_upgrade_price' => '12', 'timeline_alone_price' => 'lots', 'timeline_offer_days' => '3', 'currency' => '£' );
$o = $U::offer_for( buyer_row( $w ), $DAY1 );
check( 'O6.1 the prices, the days and the currency come from the one setting; a bad price falls back', is_array( $o ) && '£12' === $o['price'] && '£27' === $o['worth'] && '20 November 2026' === $o['ends'] );
$d = HDLV2_Offer::DEFAULTS;
check( 'O6.2 the six new defaults', '10' === $d['timeline_upgrade_price'] && '27' === $d['timeline_alone_price'] && '' === $d['timeline_upgrade_url'] && '' === $d['timeline_alone_url'] && '' === $d['timeline_video_url'] && 7 === $d['timeline_offer_days'] );
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'timeline_upgrade_url' => 'https://a.example.test/x?y=1"><script>', 'timeline_video_url' => 'https://youtu.be/abcdefghijk' );
check( 'O6.3 the three new addresses are cleaned as addresses', HDLV2_Offer::get()['timeline_upgrade_url'] === esc_url_raw( 'https://a.example.test/x?y=1"><script>' ) && 'https://youtu.be/abcdefghijk' === HDLV2_Offer::get()['timeline_video_url'] );

echo "── O7. the e-mail's five keys (payload_fields) ──\n";
$w = tl_fresh();
$f = $U::payload_fields( buyer_row( $w ), $DAY1 );
check( 'O7.1 a buyer inside the days: show "1" and the four filled strings', array( 'timeline_offer_show' => '1', 'timeline_offer_price' => '$10', 'timeline_offer_worth' => '$27', 'timeline_offer_url' => $UP, 'timeline_offer_ends' => '24 November 2026' ) === $f );
$empty = array( 'timeline_offer_show' => '0', 'timeline_offer_price' => '', 'timeline_offer_worth' => '', 'timeline_offer_url' => '', 'timeline_offer_ends' => '' );
check( 'O7.2 day 8 ($27, no date) → "0" and empty: the e-mail\'s words say "until"', $empty === $U::payload_fields( buyer_row( tl_fresh() ), $DAY8 ) );
check( 'O7.3 an owner → "0" and empty', $empty === $U::payload_fields( buyer_row( tl_fresh(), '2026-11-17 15:30:00', 'cs_test_paid' ), $DAY1 ) );
$w = tl_fresh( false );
check( 'O7.4 switch absent → "0" and empty, every key a string', $empty === $U::payload_fields( buyer_row( $w ), $DAY1 ) );
echo "── O8. what the card after the form is sent (card_fields) ──\n";
$w = tl_fresh();
$GLOBALS['options']['hdlv2_stage1_offer']['timeline_video_url'] = 'https://youtu.be/abcdefghijk';
$c = $U::card_fields( buyer_row( $w ), $DAY1 );
check( 'O8.1 a buyer without the Timeline: timeline_offer with price, worth, date, link and film, nothing else',
    array( 'timeline_offer' ) === array_keys( $c ) && array( 'price' => '$10', 'worth' => '$27', 'ends' => '24 November 2026', 'url' => $UP, 'video_url' => 'https://youtu.be/abcdefghijk' ) === $c['timeline_offer'] );
check( 'O8.2 no sign-in address and no e-mail other than the one locked in the Stripe link', false === strpos( json_encode( $c ), '?t=' ) && 1 === substr_count( json_encode( $c ), '%40' ) );
check( 'O8.3 an owner → timeline_owned true', array( 'timeline_owned' => true ) === $U::card_fields( buyer_row( tl_fresh(), '2026-11-17 15:30:00', 'cs_test_paid' ), $DAY1 ) );
$w = tl_fresh();
check( 'O8.4 no Stripe ticket (the practitioner\'s own client keeps the Timeline) → nothing (today\'s card, plan § 6.1 / W3 step 6)', array() === $U::card_fields( tl_progress( 41, 'fr1+x@example.test', '2026-11-17 15:30:00' ), $DAY1 ) );
$w = tl_fresh( false );
check( 'O8.5 switch absent → nothing', array() === $U::card_fields( buyer_row( $w ), $DAY1 ) );
$w = tl_fresh();
$w->config->practitioner_user_id = 206;
check( 'O8.6 another practitioner → nothing', array() === $U::card_fields( buyer_row( $w, '2026-11-17 15:30:00', null, 206 ), $DAY1 ) );
$w = tl_fresh();
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'timeline_upgrade_url' => 'https://buy.stripe.example.test/upgrade' );
check( 'O8.7 day 8 with no alone link → nothing (today\'s card)', array() === $U::card_fields( buyer_row( $w ), $DAY8 ) );
done();
