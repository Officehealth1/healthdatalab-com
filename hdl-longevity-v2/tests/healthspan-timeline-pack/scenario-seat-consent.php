<?php
/** Task 3 and the rule that a sign-in link only ever reaches the inbox. Run: php scenario-seat-consent.php */
require __DIR__ . '/stubs.php';

echo "── S1. mint_family_seat ──\n";
$w = pack_world();
$m = method_exists( 'HDL_Stage1_Ticket', 'mint_family_seat' );
check( 'S1.0 the wrapper exists', $m );
if ( ! $m ) { done(); }
$t = HDL_Stage1_Ticket::mint_family_seat( 122, 'team+sam@irislab.com', 'Sam', 'fam:1:2', 90 );
$row = end( $w->invites );
check( 'S1.1 a full_report ticket on that email, 90 days', 'full_report' === $row->source && 'team+sam@irislab.com' === $row->client_email && 'fam:1:2' === $row->external_ref );
check( 'S1.2 returns id, token, open_url, stage1_found, auto_confirmed', (int) $row->id === $t['id'] && $row->token === $t['token'] && 'https://stby.test/rate-of-ageing-widget/?invite=' . $row->token === $t['open_url'] && true === $t['stage1_found'] && true === $t['auto_confirmed'] );
check( 'S1.3 the lead is confirmed once', 1 === count( $GLOBALS['confirms'] ) );
$t2 = HDL_Stage1_Ticket::mint_family_seat( 122, 'team+sam@irislab.com', 'Sam', 'fam:1:2', 90 );
check( 'S1.4 idempotent: the same token, one row', $t2['token'] === $t['token'] && 1 === count( $w->invites ) );

echo "── S2. an email that already has an account ──\n";
$w = pack_world();
$GLOBALS['wp_users']['team+victim@irislab.com'] = 555;
$t = HDL_Stage1_Ticket::mint_family_seat( 122, 'team+victim@irislab.com', 'Mallory', 'fam:1:3', 90 );
check( 'S2.1 no confirm: typed answers never overwrite an existing client from an unverified email', ! $GLOBALS['confirms'] );
check( 'S2.2 the ticket and its link still exist, for the inbox', ! empty( $t['open_url'] ) && false === $t['auto_confirmed'] );

$w = pack_world();
$w->progress[24] = (object) array( 'id' => 24, 'token' => str_repeat( 'f5', 32 ), 'client_user_id' => null, 'practitioner_user_id' => 122, 'client_name' => 'Old Record', 'client_email' => 'team+old@irislab.com', 'token_expires_at' => null, 'deleted_at' => null );
$t = HDL_Stage1_Ticket::mint_family_seat( 122, 'team+old@irislab.com', 'Mallory', 'fam:1:4', 90 );
check( 'S2.3 a client record with no WP user: no confirm either (complete_signup would overwrite it)', ! $GLOBALS['confirms'] && ! empty( $t['open_url'] ) && false === $t['auto_confirmed'] );

echo "── S3. the sign-in link goes to the inbox and nowhere else ──\n";
$w = pack_world();
grant();
list( $l, , , $r ) = link_yes();
$fr = end( $w->invites );
$fr = array_values( array_filter( $w->invites, function ( $i ) { return 'full_report' === $i->source; } ) )[0];
check( 'S3.1 the response carries no link and no token', ! has_token( body_of( $r ) ) && ! isset( body_of( $r )['full_report']['open_url'] ) && false === strpos( json_encode( body_of( $r ) ), 'invite=' ) );
$inv = $GLOBALS['mails'][1];
$own = $GLOBALS['mails'][0];
check( 'S3.2 the invitee\'s mail: to their address, the sentence and the link', 'team+sam@irislab.com' === $inv['to'] && false !== strpos( $inv['body'], 'Your Full Report is unlocked' ) && false !== strpos( $inv['body'], 'rate-of-ageing-widget/?invite=' . $fr->token ) );
check( 'S3.3 the withdraw link is still there', false !== strpos( $inv['body'], '?withdraw=' ) );
check( 'S3.4 the owner\'s mail: the line, no ticket, no numbers', false !== strpos( $own['body'], 'Spouse now has their Full Report through your family pack' ) && false === strpos( $own['body'], $fr->token ) && false === strpos( $own['body'], '0.96' ) );
done();
