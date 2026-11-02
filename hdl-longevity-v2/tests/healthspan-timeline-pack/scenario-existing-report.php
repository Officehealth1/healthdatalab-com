<?php
/** Story 4: an invitee who already holds a Full Report, and that nobody can probe for one. Run: php scenario-existing-report.php */
require __DIR__ . '/stubs.php';
function holds( $w, $email, $over = array() ) {
    $id = 700 + count( $w->invites );
    $w->invites[ $id ] = (object) ( $over + array( 'id' => $id, 'practitioner_id' => 122, 'token' => str_repeat( 'e', 60 ) . sprintf( '%04d', $id ), 'client_name' => 'Sam', 'client_email' => $email, 'status' => 'opened', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 999 ), 'source' => 'full_report', 'external_ref' => 'cs_' . $id ) );
    return $w->invites[ $id ];
}
function shape( $r ) { return body_of( $r ); }

echo "── X1. holds one ──\n";
$w = pack_world();
grant();
$fr = holds( $w, 'team+sam@irislab.com' );
list( $l, $tok, $key, $r ) = link_yes();
$inv = $GLOBALS['mails'][1]; $own = $GLOBALS['mails'][0];
check( 'X1.1 no seat, no confirm, no new ticket', 0 === seats() && ! $GLOBALS['confirms'] && 1 === count( array_filter( $w->invites, function ( $i ) { return 'full_report' === $i->source; } ) ) );
check( 'X1.2 the mail: already have one, their own link', false !== strpos( $inv['body'], 'You already have a Full Report' ) && false !== strpos( $inv['body'], 'invite=' . $fr->token ) );
check( 'X1.3 the owner hears nothing about it, and no chip: three numbers is what was agreed', false === strpos( $own['body'], 'Full Report' ) && false === body_of( HDLV2_Timeline_Links::rest_list( owner_req() ) )['links'][0]['full_report'] );
$nm = count( $GLOBALS['mails'] );
$r2 = consent( $tok, $key, 'yes' );
check( 'X1.4 a repeat yes: the same answer, no mail, no seat', body_of( $r2 ) === body_of( $r ) && $nm === count( $GLOBALS['mails'] ) && 0 === seats() );

echo "── X2. the browser cannot tell the two apart ──\n";
$w = pack_world();
grant();
list( , , , $fresh ) = link_yes( 'team+new@irislab.com' );
$w = pack_world();
grant();
holds( $w, 'team+new@irislab.com' );
list( , , , $held ) = link_yes( 'team+new@irislab.com' );
check( 'X2.1 holder and stranger get one byte-identical answer', json_encode( shape( $fresh ) ) === json_encode( shape( $held ) ) );

echo "── X3. what does not count ──\n";
$w = pack_world();
grant();
holds( $w, 'team+sam@irislab.com', array( 'practitioner_id' => 999 ) );
holds( $w, 'team+sam@irislab.com', array( 'status' => 'revoked' ) );
link_yes();
check( 'X3.1 another practitioner\'s ticket and a revoked one: a seat is used', 1 === seats() );

echo "── X4. a used ticket no longer opens: the mail needs one that does ──\n";
$w = pack_world();
grant();
$fr = holds( $w, 'team+sam@irislab.com', array( 'status' => 'completed' ) );
link_yes();
$inv = $GLOBALS['mails'][1];
check( 'X4.1 no seat; the mail carries a live link, not the used one', 0 === seats() && false === strpos( $inv['body'], 'invite=' . $fr->token ) && 1 === preg_match( '/invite=[a-f0-9]{64}/', $inv['body'] ) && ! $GLOBALS['confirms'] );

echo "── X5. no seat, no probing ──\n";
$w = pack_world();
grant();
end( $w->packs )->seats_used = 5;
holds( $w, 'team+sam@irislab.com' );
list( , , , $r ) = link_yes();
check( 'X5.1 holder with no seat left: as Plan B, no line in the mail', 'none' === body_of( $r )['full_report']['state'] && false === strpos( $GLOBALS['mails'][1]['body'], 'Full Report' ) );

echo "── X6. the same person, typed with capitals ──\n";
$w = pack_world();
grant();
holds( $w, 'team+sam@irislab.com' );
link_yes( 'Team+Sam@irislab.com' );
check( 'X6.1 no seat spent, the mail says they already have one', 0 === seats() && false !== strpos( $GLOBALS['mails'][1]['body'], 'You already have a Full Report' ) && ! $GLOBALS['confirms'] );
done();
