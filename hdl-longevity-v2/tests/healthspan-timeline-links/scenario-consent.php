<?php
/**
 * POST /timeline/consent — the invitee's yes or no, against the REAL class.
 *
 * Run:  php scenario-consent.php   (self-asserting, exit 0/1)
 */
require __DIR__ . '/stubs.php';

function gone( $r ) { return 404 === status_of( $r ) && array( 'code' => 'link_gone', 'message' => HDLV2_Timeline_Links::GONE ) === body_of( $r ); }

echo "── C1. what is not there answers the same ──\n";
$w = world();
$a = body_of( create_link() );
$ticket = $w->invites[ (int) $w->links[ $a['id'] ]->invite_id ];
check( 'C1.1 an unknown token → 404 link_gone', gone( consent( str_repeat( 'c', 64 ), 'x', 'yes' ) ) );
check( 'C1.2 a malformed token → the same 404', gone( consent( 'nope', 'x', 'yes' ) ) );
check( 'C1.3 an unclaimed ticket (the free check not done) → the same 404', gone( consent( $ticket->token, 'x', 'yes' ) ) );
list( $tok, $key ) = invitee_did_check( $a['id'] );
check( 'C1.4 the right ticket, a wrong key (the inviter, who holds the link) → the same 404, nothing shared', gone( consent( $tok, str_repeat( '0', 64 ), 'yes' ) ) && 'invited' === $w->links[ $a['id'] ]->status );
check( 'C1.5 no key → the same 404', gone( consent( $tok, '', 'yes' ) ) );
$ticket->expires_at = gmdate( 'Y-m-d H:i:s', time() - 60 );
check( 'C1.6 an expired ticket → the same 404', gone( consent( $tok, $key, 'yes' ) ) && 'invited' === $w->links[ $a['id'] ]->status );
$ticket->expires_at = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
check( 'C1.7 a decision that is neither yes nor no → 400, nothing stored', 400 === status_of( consent( $tok, $key, 'maybe' ) ) && 'invited' === $w->links[ $a['id'] ]->status );
check( 'C1.8 nothing above sent mail', ! $GLOBALS['mails'] );

echo "── C2. adults only ──\n";
$w = world();
$a = body_of( create_link() );
list( $tok, $key ) = invitee_did_check( $a['id'], 17 );
$r = consent( $tok, $key, 'yes' );
check( 'C2.1 age 17 → 403 "for adults", nothing stored, no mail', 403 === status_of( $r ) && false !== strpos( body_of( $r )['message'], 'for adults' )
    && 'invited' === $w->links[ $a['id'] ]->status && null === $w->links[ $a['id'] ]->shared_rate && ! $GLOBALS['mails'] );
$w->leads[ 900 + $a['id'] ]->visitor_age = null;
check( 'C2.2 no age on file → 403 too', 403 === status_of( consent( $tok, $key, 'yes' ) ) );

echo "── C3. yes ──\n";
$w = world();
$a = body_of( create_link() );
list( $tok, $key ) = invitee_did_check( $a['id'], 40, 'female', 0.96 );
$r = consent( $tok, $key, 'yes' );
$l = $w->links[ $a['id'] ];
$b = body_of( $r );
check( 'C3.1 200 accepted, with the inviter\'s first name and the label', 200 === status_of( $r ) && 'accepted' === ( $b['status'] ?? '' ) && 'Quim' === ( $b['link_from'] ?? '' ) && 'Spouse' === ( $b['label'] ?? '' ) );
check( 'C3.2 the snapshot: shared_rate = the lead\'s rate, shared_age = visitor_age, shared_sex from the stored answers',
    'accepted' === $l->status && 0.96 == $l->shared_rate && 40 === (int) $l->shared_age && 'F' === $l->shared_sex && (int) $l->lead_id === 900 + $a['id'] && ! empty( $l->consent_at ) );
check( 'C3.3 a withdraw hash is stored (64 hex), the raw token is not', 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $l->withdraw_token_hash ) );
check( 'C3.4 two emails: the inviter and the invitee', 2 === count( $GLOBALS['mails'] )
    && 'team+owner@irislab.com' === $GLOBALS['mails'][0]['to'] && 'team+sam@irislab.com' === $GLOBALS['mails'][1]['to'] );
$owner_mail = $GLOBALS['mails'][0]['body'];
$inv_mail   = $GLOBALS['mails'][1]['body'];
check( 'C3.5 the inviter\'s mail: the label, the page link with their own token', false !== strpos( $owner_mail, 'Spouse shared their pace of ageing with you' )
    && false !== strpos( $owner_mail, 'https://stby.test/healthspan-timeline/?t=' . OWNER_T ) );
preg_match( '#' . preg_quote( PAGE, '#' ) . '\?withdraw=([a-f0-9]{64})#', $inv_mail, $m );
check( 'C3.6 the invitee\'s mail: on Quim\'s timeline as Spouse, the three numbers named, a withdraw url on the ticket page whose hash is stored',
    false !== strpos( $inv_mail, 'Quim&#039;s timeline as Spouse' ) && false !== strpos( $inv_mail, 'pace of ageing, age and sex' )
    && ! empty( $m[1] ) && hash( 'sha256', $m[1] ) === $l->withdraw_token_hash );
check( 'C3.7 the invitee\'s mail carries no inviter email and no answers', false === strpos( $inv_mail, 'team+owner@irislab.com' ) && false === strpos( $inv_mail, '0.96' ) );
check( 'C3.8 both mails carry the legal footer', false !== strpos( $owner_mail, 'NOT MEDICAL ADVICE' ) && false !== strpos( $inv_mail, 'NOT MEDICAL ADVICE' ) );
$hash = $l->withdraw_token_hash;
$r2 = consent( $tok, $key, 'yes' );
check( 'C3.9 yes twice → the same answer, one state, no second email, the hash kept', 200 === status_of( $r2 ) && 'accepted' === body_of( $r2 )['status'] && 2 === count( $GLOBALS['mails'] ) && $hash === $l->withdraw_token_hash );
check( 'C3.10 no after yes → 404, still accepted', gone( consent( $tok, $key, 'no' ) ) && 'accepted' === $l->status );

echo "── C4. no ──\n";
$w = world();
$a = body_of( create_link() );
list( $tok, $key ) = invitee_did_check( $a['id'] );
$r = consent( $tok, $key, 'no' );
$l = $w->links[ $a['id'] ];
check( 'C4.1 no → declined, nothing shared, no lead tied, no mail to anyone', 200 === status_of( $r ) && 'declined' === body_of( $r )['status'] && 'declined' === $l->status
    && null === $l->shared_rate && null === $l->lead_id && ! $GLOBALS['mails'] );
check( 'C4.2 no twice → the same answer', 'declined' === body_of( consent( $tok, $key, 'no' ) )['status'] );
check( 'C4.3 yes after no → 404, still declined, no mail', gone( consent( $tok, $key, 'yes' ) ) && 'declined' === $l->status && ! $GLOBALS['mails'] );
check( 'C4.4 the inviter sees "declined" on reload', 'declined' === body_of( HDLV2_Timeline_Links::rest_list( owner_req() ) )['links'][0]['status'] );

echo "── C5. an ended link ──\n";
$w = world();
$a = body_of( create_link() );
list( $tok, $key ) = invitee_did_check( $a['id'] );
HDLV2_Timeline_Links::rest_revoke( owner_req( array( 'id' => $a['id'] ) ) );
check( 'C5.1 the inviter ended it before the yes → 404, nothing shared', gone( consent( $tok, $key, 'yes' ) ) && 'revoked' === $w->links[ $a['id'] ]->status && ! $GLOBALS['mails'] );

done();
