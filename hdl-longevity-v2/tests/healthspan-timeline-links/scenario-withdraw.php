<?php
/**
 * POST /timeline/withdraw — the invitee takes their numbers back, against the REAL class.
 *
 * Run:  php scenario-withdraw.php   (self-asserting, exit 0/1)
 */
require __DIR__ . '/stubs.php';

function withdraw( $t ) { return HDLV2_Timeline_Links::rest_withdraw( new FakeRequest( array( 'withdraw_token' => $t ) ) ); }
function accepted_link() {
    global $wpdb;
    $a = body_of( create_link() );
    list( $tok, $key ) = invitee_did_check( $a['id'] );
    consent( $tok, $key, 'yes' );
    preg_match( '/\?withdraw=([a-f0-9]{64})/', end( $GLOBALS['mails'] )['body'], $m );
    return array( $wpdb->links[ $a['id'] ], $m[1] ?? '' );
}

echo "── W1. withdraw ──\n";
$w = world();
list( $l, $wt ) = accepted_link();
$sent = count( $GLOBALS['mails'] );
$r = withdraw( $wt );
check( 'W1.1 a valid withdraw token → 200 withdrawn with the inviter\'s first name', 200 === status_of( $r ) && 'withdrawn' === body_of( $r )['status'] && 'Quim' === body_of( $r )['link_from'] );
check( 'W1.2 the row: withdrawn, shared_* null, the hash gone, withdrawn_at set', 'withdrawn' === $l->status && null === $l->shared_rate && null === $l->shared_age
    && null === $l->shared_sex && null === $l->withdraw_token_hash && ! empty( $l->withdrawn_at ) );
check( 'W1.3 no mail on withdraw', $sent === count( $GLOBALS['mails'] ) );
$list = body_of( HDLV2_Timeline_Links::rest_list( owner_req() ) )['links'];
check( 'W1.4 the inviter\'s list: the chip and no numbers', 'withdrawn' === $list[0]['status'] && null === $list[0]['shared'] );
check( 'W1.5 the same token again → 404 link_gone', 404 === status_of( withdraw( $wt ) ) && 'link_gone' === body_of( withdraw( $wt ) )['code'] );

echo "── W2. what is not there ──\n";
$w = world();
check( 'W2.1 a malformed token → 404', 404 === status_of( withdraw( 'abc' ) ) );
check( 'W2.2 an unknown 64-hex token → 404', 404 === status_of( withdraw( str_repeat( 'd', 64 ) ) ) );
list( $l, $wt ) = accepted_link();
HDLV2_Timeline_Links::rest_revoke( owner_req( array( 'id' => $l->id ) ) );
check( 'W2.3 a link the inviter ended → its withdraw token answers 404', 404 === status_of( withdraw( $wt ) ) && 'revoked' === $l->status );

done();
