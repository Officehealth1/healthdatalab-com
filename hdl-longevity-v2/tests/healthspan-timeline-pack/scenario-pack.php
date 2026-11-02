<?php
/** Stories 1, 2: grant, seats at the yes, end. Run: php scenario-pack.php */
require __DIR__ . '/stubs.php';

echo "── P1. grant ──\n";
$w = pack_world();
$r = grant();
$b = body_of( $r );
check( 'P1.1 the practitioner grants: 201, 5 seats, 0 used, active', 201 === status_of( $r ) && 5 === $b['pack']['seats_total'] && 0 === $b['pack']['seats_used'] && 'active' === $b['pack']['status'] );
$row = end( $w->packs );
check( 'P1.2 the row: owner 21, source practitioner, no external_ref', 21 === (int) $row->owner_progress_id && 'practitioner' === $row->source && empty( $row->external_ref ) );
$r = grant();
check( 'P1.3 a second grant: 409 with the plain line', 409 === status_of( $r ) && 'This client already has a family pack' === body_of( $r )['message'] && 1 === count( $w->packs ) );
$r = grant( 21, 123 );
$g = grant( 99999 );
check( 'P1.4 another practitioner\'s client and an unknown id: the same 404', 404 === status_of( $r ) && body_of( $r ) === body_of( $g ) );
$w->reports = array();
$w->progress[23] = (object) array( 'id' => 23, 'token' => str_repeat( 'd4', 32 ), 'client_user_id' => 703, 'practitioner_user_id' => 122, 'client_name' => 'No Report', 'client_email' => 'team+nr@irislab.com', 'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 999 ), 'deleted_at' => null );
$r = grant( 23 );
check( 'P1.5 a client with no report: 409, nothing made', 409 === status_of( $r ) && 1 === count( $w->packs ) );
$GLOBALS['routes'] = array();
HDLV2_Timeline_Links::register_routes();
$cb = $GLOBALS['routes']['hdl-v2/v1/timeline/packs']['permission_callback'] ?? null;
as_user( 0 );   $anon = $cb ? call_user_func( $cb ) : true;
as_user( 701 ); $client = $cb ? call_user_func( $cb ) : true;
as_user( 122 ); $prac = $cb ? call_user_func( $cb ) : false;
as_user( 0 );
check( 'P1.6 the routes: nobody, a client refused; a practitioner in', false === $anon && false === $client && true === $prac );

echo "── P2. two yeses take two seats ──\n";
$w = pack_world();
$pack = body_of( grant() )['pack'];
list( $l1, , , $r1 ) = link_yes( 'team+sam@irislab.com' );
list( $l2, , , $r2 ) = link_yes( 'team+kim@irislab.com', 'Child', 'child' );
$frs = array_values( array_filter( $w->invites, function ( $i ) { return 'full_report' === $i->source; } ) );
check( 'P2.1 seats_used 2', 2 === seats() );
check( 'P2.2 two distinct full_report tickets, fam:<pack>:<link>, on the invitee\'s own email', 2 === count( $frs ) && $frs[0]->token !== $frs[1]->token
    && 'fam:' . $pack['id'] . ':' . $l1 === $frs[0]->external_ref && 'team+kim@irislab.com' === $frs[1]->client_email );
check( 'P2.3 the links: pack_id and seat_invite_id', (int) $w->links[ $l1 ]->pack_id === $pack['id'] && (int) $w->links[ $l1 ]->seat_invite_id === (int) $frs[0]->id );
check( 'P2.4 the pack row is locked FOR UPDATE at the yes', (bool) array_filter( $w->queries, function ( $q ) { return false !== strpos( $q, 'timeline_packs' ) && false !== strpos( $q, 'FOR UPDATE' ); } ) );
check( 'P2.5 the mint runs outside that transaction (its START TRANSACTION would commit ours)', 0 === $w->implicit_commits );
check( 'P2.6 the answer: accepted, numbers shared, state sent', 'accepted' === body_of( $r1 )['status'] && 'sent' === body_of( $r1 )['full_report']['state'] && 40 === (int) $w->links[ $l1 ]->shared_age );

echo "── P3. no seat left ──\n";
$w = pack_world();
grant();
end( $w->packs )->seats_used = 5;
list( $l, , , $r ) = link_yes();
check( 'P3.1 state none, numbers shared, no ticket, seats 5', 'none' === body_of( $r )['full_report']['state'] && 'accepted' === $w->links[ $l ]->status && 5 === seats()
    && ! array_filter( $w->invites, function ( $i ) { return 'full_report' === $i->source; } ) && ! $GLOBALS['confirms'] );
check( 'P3.2 the invitee\'s mail has no Full Report line', false === strpos( $GLOBALS['mails'][1]['body'], 'Full Report' ) );

echo "── P4. end ──\n";
$w = pack_world();
$id = body_of( grant() )['pack']['id'];
list( $l1 ) = link_yes();
$r = end_pack( $id );
check( 'P4.1 end: 200, ended, ended_at', 200 === status_of( $r ) && 'ended' === $w->packs[ $id ]->status && ! empty( $w->packs[ $id ]->ended_at ) );
check( 'P4.2 end twice: the same answer', 200 === status_of( end_pack( $id ) ) );
list( $l2, , , $r2 ) = link_yes( 'team+kim@irislab.com', 'Child', 'child' );
check( 'P4.3 a yes after the end: numbers only, no seat', 'none' === body_of( $r2 )['full_report']['state'] && 1 === seats() && 'accepted' === $w->links[ $l2 ]->status );
check( 'P4.4 the report already given stays', ! empty( $w->links[ $l1 ]->seat_invite_id ) && 'revoked' !== $w->invites[ (int) $w->links[ $l1 ]->seat_invite_id ]->status );
$list = body_of( HDLV2_Timeline_Links::rest_list( owner_req() ) );
check( 'P4.5 GET says ended', 'ended' === $list['pack']['status'] && 1 === $list['pack']['seats_used'] );
check( 'P4.6 another practitioner cannot end it: 404', 404 === status_of( end_pack( $id, 123 ) ) );
$r3 = grant();
check( 'P4.7 after an end a new pack can be granted', 201 === status_of( $r3 ) && 0 === body_of( HDLV2_Timeline_Links::rest_list( owner_req() ) )['pack']['seats_used'] );

echo "── P5. a repeat yes ──\n";
$w = pack_world();
grant();
list( $l, $tok, $key, $r ) = link_yes();
$mails = count( $GLOBALS['mails'] );
$r2 = consent( $tok, $key, 'yes' );
check( 'P5.1 the lead moved to the Full Report ticket, yet the repeat is not a 404', 200 === status_of( $r2 ) && 'accepted' === body_of( $r2 )['status'] );
check( 'P5.2 same state, no second seat, no second email', 'sent' === body_of( $r2 )['full_report']['state'] && 1 === seats() && $mails === count( $GLOBALS['mails'] ) );

echo "── P6. the page\'s list, and the flag off ──\n";
$w = pack_world();
grant();
list( $l, $tok ) = link_yes();
$list = body_of( HDLV2_Timeline_Links::rest_list( owner_req() ) );
check( 'P6.1 GET: pack and the seat\'s mark, no token', 5 === $list['pack']['seats_total'] && 1 === $list['pack']['seats_used'] && true === $list['links'][0]['full_report'] && ! has_token( $list ) );
$w->links[ $l ]->status = 'withdrawn';
check( 'P6.2 withdrawn: the mark goes, the seat stays used', false === body_of( HDLV2_Timeline_Links::rest_list( owner_req() ) )['links'][0]['full_report'] && 1 === seats() );
$w = pack_world();
unset( $GLOBALS['options']['hdlv2_ff_timeline_pack'] );
$GLOBALS['routes'] = array();
HDLV2_Timeline_Links::register_routes();
check( 'P6.3 flag off: no pack routes', ! isset( $GLOBALS['routes']['hdl-v2/v1/timeline/packs'] ) && isset( $GLOBALS['routes']['hdl-v2/v1/timeline/links'] ) );
list( $l, , , $r ) = link_yes();
$list = body_of( HDLV2_Timeline_Links::rest_list( owner_req() ) );
check( 'P6.4 flag off: consent and list exactly as Plan B', ! isset( body_of( $r )['full_report'] ) && ! array_key_exists( 'pack', $list ) && ! array_key_exists( 'full_report', $list['links'][0] ) && ! $GLOBALS['confirms'] );

echo "── P7. the unlock fails ──\n";
$w = pack_world();
grant();
$w->fail_full_report = true;
list( $l, $tok, $key, $r ) = link_yes();
check( 'P7.1 the yes still shares; state failed; the seat is kept', 200 === status_of( $r ) && 'accepted' === $w->links[ $l ]->status && 'failed' === body_of( $r )['full_report']['state'] && 0 === seats() && empty( $w->links[ $l ]->pack_id ) );
check( 'P7.2 the invitee still gets the consent email, no Full Report line', 2 === count( $GLOBALS['mails'] ) && false === strpos( $GLOBALS['mails'][1]['body'], 'Full Report' ) );
$w = pack_world();
grant();
$GLOBALS['options']['hdlv2_full_report_auto_confirm'] = 0;
list( , , , $r ) = link_yes();
check( 'P7.3 auto-confirm switched off: no link to give, state failed, seat kept', 'failed' === body_of( $r )['full_report']['state'] && 0 === seats() );
$GLOBALS['options']['hdlv2_full_report_auto_confirm'] = 1;
$w = pack_world();
grant();
list( $l, $tok, $key ) = link_yes();
$w->links[ $l ]->seat_invite_id = null; // the worker died between the reservation and the ticket
$r = consent( $tok, $key, 'yes' );
check( 'P7.4 a repeat yes after a crash: the seat comes back, state failed', 'failed' === body_of( $r )['full_report']['state'] && 0 === seats() && empty( $w->links[ $l ]->pack_id ) );

echo "── P8. under 18 ──\n";
$w = pack_world();
grant();
list( $l, , , $r ) = link_yes( 'team+kid@irislab.com', 'Child', 'child', 16 );
check( 'P8.1 refused before any seat is touched', 403 === status_of( $r ) && 0 === seats() && ! $GLOBALS['confirms'] );
done();
