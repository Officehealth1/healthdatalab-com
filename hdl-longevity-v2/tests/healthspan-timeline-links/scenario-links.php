<?php
/**
 * GET / POST / DELETE /timeline/links — the inviter's side, against the REAL class and the REAL
 * HDL_Stage1_Ticket::mint().
 *
 * Run:  php scenario-links.php   (self-asserting, exit 0/1)
 */
require __DIR__ . '/stubs.php';

echo "── L1. make a link ──\n";
$w = world();
$r = create_link();
$b = body_of( $r );
$ticket = $w->invites ? array_values( $w->invites )[0] : null;
check( 'L1.1 201 with id and a url on the practitioner\'s ticket page ending ?invite=<64 hex>', 201 === status_of( $r ) && is_int( $b['id'] ?? null )
    && 1 === preg_match( '#^' . preg_quote( PAGE, '#' ) . '\?invite=[a-f0-9]{64}$#', (string) ( $b['url'] ?? '' ) ) );
check( 'L1.2 the ticket: source timeline_link, client_email \'\', client_name = label, external_ref tl:<id>, pending',
    $ticket && 'timeline_link' === $ticket->source && '' === $ticket->client_email && 'Spouse' === $ticket->client_name
    && 'tl:' . $b['id'] === $ticket->external_ref && 'pending' === $ticket->status && 122 === (int) $ticket->practitioner_id );
check( 'L1.3 the ticket lasts 90 days', $ticket && abs( strtotime( $ticket->expires_at . ' UTC' ) - ( time() + 90 * DAY_IN_SECONDS ) ) < 60 );
$link = $w->links[ $b['id'] ] ?? null;
check( 'L1.4 the link row: owner 21, the ticket id, invited, the guesses kept, nothing shared', $link && 21 === (int) $link->owner_progress_id
    && (int) $link->invite_id === (int) $ticket->id && 'invited' === $link->status && 1975 === (int) $link->born_guess && 'F' === $link->sex_guess
    && null === $link->shared_rate && null === $link->lead_id );
check( 'L1.5 no email to anyone', ! $GLOBALS['mails'] );
check( 'L1.6 the answer carries the link\'s shape, no token beside the url', isset( $b['link']['status'] ) && 'invited' === $b['link']['status'] && false === strpos( json_encode( $b['link'] ), $ticket->token ) );

echo "── L2. who may make one ──\n";
$w = world();
$r = HDLV2_Timeline_Links::rest_create( new FakeRequest( array( 'label' => 'Spouse', 'relationship' => 'spouse' ) ) );
check( 'L2.1 no token → 401 with the report page\'s words', 401 === status_of( $r ) && HDLV2_Healthspan_Timeline::EXPIRED === body_of( $r )['message'] && ! $w->invites );
$w->progress[21]->token_expires_at = gmdate( 'Y-m-d H:i:s', time() - 60 );
$r = create_link();
check( 'L2.2 an expired token → 401 with the same words, nothing minted', 401 === status_of( $r ) && HDLV2_Healthspan_Timeline::EXPIRED === body_of( $r )['message'] && ! $w->invites );
$w = world();
$w->progress[21]->deleted_at = '2026-10-30 00:00:00';
check( 'L2.3 a deleted record → 401', 401 === status_of( create_link() ) );
$w = world();
$GLOBALS['current_user'] = 702;
$r = create_link();
check( 'L2.4 another client signed in, with this client\'s token → 403, nothing minted', 403 === status_of( $r ) && ! $w->invites );
$GLOBALS['current_user'] = 122;
check( 'L2.5 the practitioner signed in → 403', 403 === status_of( create_link() ) && ! $w->invites );
$GLOBALS['current_user'] = 701;
check( 'L2.6 the client themself signed in → 201', 201 === status_of( create_link() ) );
$w = world();
unset( $w->reports[21] );
$r = create_link();
check( 'L2.7 no Full Report on file → 403, nothing minted', 403 === status_of( $r ) && ! $w->invites );
$w = world();
$w->config[122] = '';
$r = create_link();
check( 'L2.8 no ticket_page_url → 409 "Ask your practitioner to set the report page", nothing written', 409 === status_of( $r )
    && 'Ask your practitioner to set the report page.' === body_of( $r )['message'] && ! $w->invites && ! $w->links );
$w->config[122] = 'http://example.test/page';
check( 'L2.9 an http page → 409', 409 === status_of( create_link() ) );
$w->config[122] = 'not a url';
check( 'L2.10 garbage → 409', 409 === status_of( create_link() ) );
$w = world();
check( 'L2.11 no label → 400', 400 === status_of( create_link( array( 'label' => '  ' ) ) ) && ! $w->links );
check( 'L2.12 an unknown relationship → 400', 400 === status_of( create_link( array( 'relationship' => 'boss' ) ) ) && ! $w->links );
$r = create_link( array( 'label' => str_repeat( 'x', 120 ), 'born_guess' => 99999, 'sex_guess' => 'Q' ) );
$l = $w->links[ body_of( $r )['id'] ?? 0 ] ?? null;
check( 'L2.13 label cut to 80, a silly year and sex stored as unknown', $l && 80 === strlen( $l->label ) && null === $l->born_guess && null === $l->sex_guess );

echo "── L3. caps ──\n";
$w = world();
$GLOBALS['options']['hdlv2_ff_healthspan_timeline'] = '1';
for ( $i = 0; $i < 10; $i++ ) { create_link( array( 'label' => 'P' . $i ) ); }
$r = create_link( array( 'label' => 'P11' ) );
check( 'L3.1 the 11th in a day → 429 with a plain line, nothing minted', 429 === status_of( $r ) && 10 === count( $w->links ) && 10 === count( $w->invites )
    && false !== stripos( body_of( $r )['message'], 'tomorrow' ) );
foreach ( $w->links as $l ) { $l->created_at = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ); }
for ( $i = 0; $i < 10; $i++ ) { create_link( array( 'label' => 'Q' . $i ) ); }
foreach ( $w->links as $l ) { $l->created_at = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ); }
for ( $i = 0; $i < 5; $i++ ) { create_link( array( 'label' => 'R' . $i ) ); }
check( 'L3.2 25 made over three days', 25 === count( $w->links ) );
$r = create_link( array( 'label' => 'S26' ) );
check( 'L3.3 the 26th → 429 "You have reached the limit of 25 links."', 429 === status_of( $r ) && 'You have reached the limit of 25 links.' === body_of( $r )['message'] && 25 === count( $w->links ) );
$r = HDLV2_Timeline_Links::rest_create( owner_req( array( 'label' => 'Mine', 'relationship' => 'friend' ), OTHER_T ) );
check( 'L3.4 the caps are per inviter: another client still makes one', 201 === status_of( $r ) );

echo "── L4. list ──\n";
$w = world();
$a = body_of( create_link() );
$b2 = body_of( create_link( array( 'label' => 'Mum', 'relationship' => 'parent' ) ) );
list( $tok ) = invitee_did_check( $a['id'], 40, 'female', 0.96 );
consent( $tok, HDLV2_Timeline_Links::consent_key( $w->links[ $a['id'] ]->invite_id, 900 + $a['id'] ), 'yes' );
$r = HDLV2_Timeline_Links::rest_list( owner_req() );
$list = body_of( $r )['links'] ?? array();
check( 'L4.1 two links, in order, each with label, relationship, status', 2 === count( $list ) && 'Spouse' === $list[0]['label'] && 'parent' === $list[1]['relationship'] );
check( 'L4.2 the accepted one carries the three values', 'accepted' === $list[0]['status'] && array( 'rate' => 0.96, 'age' => 40, 'sex' => 'F' ) == $list[0]['shared'] );
check( 'L4.3 the invited one carries none', 'invited' === $list[1]['status'] && null === $list[1]['shared'] );
$json = json_encode( $list );
$leak = false;
foreach ( $w->invites as $t ) { if ( false !== strpos( $json, $t->token ) ) $leak = true; }
check( 'L4.4 no token anywhere in the list, no email, no lead id', ! $leak && false === strpos( $json, '@' ) && false === strpos( $json, 'lead' ) );
check( 'L4.5 another client\'s token lists only theirs (none)', array() === ( body_of( HDLV2_Timeline_Links::rest_list( owner_req( array(), OTHER_T ) ) )['links'] ?? null ) );
$w->invites[ (int) $w->links[ $b2['id'] ]->invite_id ]->expires_at = gmdate( 'Y-m-d H:i:s', time() - 60 );
$list = body_of( HDLV2_Timeline_Links::rest_list( owner_req() ) )['links'];
check( 'L4.6 an invited link whose ticket ran out reads expired (and is stored so)', 'expired' === $list[1]['status'] && 'expired' === $w->links[ $b2['id'] ]->status );

echo "── L5. end a link ──\n";
$w = world();
$a = body_of( create_link() );
list( $tok, $key ) = invitee_did_check( $a['id'] );
consent( $tok, $key, 'yes' );
$l = $w->links[ $a['id'] ];
check( 'L5.0 (setup) accepted with values and a withdraw hash', 'accepted' === $l->status && null !== $l->shared_rate && null !== $l->withdraw_token_hash );
$r = HDLV2_Timeline_Links::rest_revoke( owner_req( array( 'id' => $a['id'] ), OTHER_T ) );
check( 'L5.1 another client\'s token → 404, nothing changed', 404 === status_of( $r ) && 'accepted' === $l->status );
$r = HDLV2_Timeline_Links::rest_revoke( owner_req( array( 'id' => $a['id'] ) ) );
check( 'L5.2 DELETE → revoked, the ticket revoked, shared_* and the withdraw hash null', 200 === status_of( $r ) && 'revoked' === $l->status
    && 'revoked' === $w->invites[ (int) $l->invite_id ]->status && null === $l->shared_rate && null === $l->shared_age && null === $l->shared_sex
    && null === $l->withdraw_token_hash && ! empty( $l->revoked_at ) );
check( 'L5.3 again → 200, still revoked', 200 === status_of( HDLV2_Timeline_Links::rest_revoke( owner_req( array( 'id' => $a['id'] ) ) ) ) && 'revoked' === $l->status );
check( 'L5.4 an unknown id → 404', 404 === status_of( HDLV2_Timeline_Links::rest_revoke( owner_req( array( 'id' => 999 ) ) ) ) );
$list = body_of( HDLV2_Timeline_Links::rest_list( owner_req() ) )['links'];
check( 'L5.5 the list shows it revoked with no values', 'revoked' === $list[0]['status'] && null === $list[0]['shared'] );

echo "── L6. flags and routes ──\n";
$w = world();
$GLOBALS['routes'] = array();
HDLV2_Timeline_Links::register_routes();
check( 'L6.1 both flags on: the four paths registered', isset( $GLOBALS['routes']['hdl-v2/v1/timeline/links'], $GLOBALS['routes']['hdl-v2/v1/timeline/links/(?P<id>\d+)'], $GLOBALS['routes']['hdl-v2/v1/timeline/consent'], $GLOBALS['routes']['hdl-v2/v1/timeline/withdraw'] ) );
$GLOBALS['routes'] = array();
unset( $GLOBALS['options']['hdlv2_ff_timeline_links'] );
HDLV2_Timeline_Links::register_routes();
check( 'L6.2 links flag off → no route (404)', ! $GLOBALS['routes'] );
$GLOBALS['options']['hdlv2_ff_timeline_links'] = '1';
unset( $GLOBALS['options']['hdlv2_ff_healthspan_timeline'] );
HDLV2_Timeline_Links::register_routes();
check( 'L6.3 timeline flag off → no route', ! $GLOBALS['routes'] );

echo "── L7. the page and the dashboard ──\n";
$w = world();
$d = HDLV2_Healthspan_Timeline::page_data( OWNER_T );
check( 'L7.1 full mode, both flags on → HDLV2_HT.links {url, nonce}', 'full' === $d['mode'] && 'https://stby.test/wp-json/hdl-v2/v1/timeline/links' === ( $d['links']['url'] ?? '' ) && ! empty( $d['links']['nonce'] ) );
unset( $GLOBALS['options']['hdlv2_ff_timeline_links'] );
check( 'L7.2 links flag off → no links key (the page is Plan A\'s, unchanged)', ! isset( HDLV2_Healthspan_Timeline::page_data( OWNER_T )['links'] ) );
$GLOBALS['options']['hdlv2_ff_timeline_links'] = '1';
$GLOBALS['current_user'] = 122;
check( 'L7.3 the practitioner viewing the page → no links key', ! isset( HDLV2_Healthspan_Timeline::page_data( OWNER_T )['links'] ) );
$GLOBALS['current_user'] = 0;
unset( $w->reports[21] );
check( 'L7.4 preview mode → no links key', ! isset( HDLV2_Healthspan_Timeline::page_data( OWNER_T )['links'] ) );
$w = world();
$a = body_of( create_link() );
check( 'L7.5 dashboard count: nobody yet → 0', 0 === HDLV2_Timeline_Links::joined_count( 701 ) );
list( $tok, $key ) = invitee_did_check( $a['id'] );
consent( $tok, $key, 'yes' );
check( 'L7.6 one accepted → 1', 1 === HDLV2_Timeline_Links::joined_count( 701 ) );
unset( $GLOBALS['options']['hdlv2_ff_timeline_links'] );
check( 'L7.7 flag off → 0', 0 === HDLV2_Timeline_Links::joined_count( 701 ) );

done();
