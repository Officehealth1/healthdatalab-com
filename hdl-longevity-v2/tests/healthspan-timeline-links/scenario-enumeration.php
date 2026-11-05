<?php
/**
 * Rule 5 — nothing says whether a person or an email exists. POST links never looks anyone up;
 * the ticket the widget reads (/widget/verify-invite) carries the inviter's first name and
 * nothing else about them. The verify half runs the REAL route on the full-report harness.
 *
 * Run:  php scenario-enumeration.php   (self-asserting, exit 0/1)
 */
require __DIR__ . '/stubs.php';

echo "── E1. making a link looks nobody up ──\n";
$w = world();
$w->leads[1] = (object) array( 'id' => 1, 'practitioner_user_id' => 122, 'visitor_name' => 'Sam Example', 'visitor_email' => 'team+sam@irislab.com', 'visitor_age' => 40, 'rate_of_ageing' => 1.0, 'invite_id' => 0, 'stage1_data' => '{}' );
$known   = body_of( create_link( array( 'label' => 'Sam Example' ) ) );
$unknown = body_of( create_link( array( 'label' => 'Nobody Here' ) ) );
$shape = function ( $b ) { $b['id'] = 0; $b['url'] = preg_replace( '/[a-f0-9]{64}/', 'T', $b['url'] ); $b['link']['id'] = 0; $b['link']['label'] = ''; return $b; };
check( 'E1.1 the same shape whether or not a lead with that name exists', $shape( $known ) === $shape( $unknown ) );
check( 'E1.2 no query touched the leads table', ! array_filter( $w->queries, function ( $q ) { return false !== strpos( $q, 'hdlv2_widget_leads' ); } ) );
check( 'E1.3 the answer holds no email, no lead, no other client', false === strpos( json_encode( $known ), '@' ) && false === strpos( json_encode( $known ), 'lead' ) );

echo "── E2. link_from ──\n";
$tid = (int) $w->links[ $known['id'] ]->invite_id;
check( 'E2.1 the inviter\'s first name only', 'Quim' === HDLV2_Timeline_Links::link_from( $tid ) );
$w->progress[21]->client_name = 'quim.test@irislab.com';
check( 'E2.2 an email typed as a name never leaks: "Someone"', 'Someone' === HDLV2_Timeline_Links::link_from( $tid ) );
check( 'E2.3 a ticket with no link → \'\'', '' === HDLV2_Timeline_Links::link_from( 99999 ) );

echo "── E3. /widget/verify-invite for a timeline_link ticket (real route) ──\n";
$php = escapeshellarg( PHP_BINARY );
exec( $php . ' ' . escapeshellarg( __DIR__ . '/scenario-verify.php' ) . ' 2>&1', $out, $code );
echo implode( "\n", preg_grep( '/PASS|FAIL|Fatal/', $out ) ) . "\n";
check( 'E3 scenario-verify.php green', 0 === $code );

done();
