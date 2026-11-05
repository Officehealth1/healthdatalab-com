<?php
/**
 * DB6 — either side's record deleted: its link rows go. HDLV2_Timeline_Links::forget_client()
 * against the fake, and a source check that both delete paths call it (the practitioner's
 * client delete soft-deletes form_progress; the admin purge hard-deletes it and the leads).
 *
 * Run:  php scenario-cleanup.php   (self-asserting, exit 0/1)
 */
require __DIR__ . '/stubs.php';

echo "── D1. forget_client ──\n";
$w = world();
$mine  = body_of( create_link() )['id'];
$other = body_of( HDLV2_Timeline_Links::rest_create( owner_req( array( 'label' => 'Pal', 'relationship' => 'friend' ), OTHER_T ) ) )['id'];
list( $tok, $key ) = invitee_did_check( $other ); // the invitee of client 702's link is team+sam@irislab.com
consent( $tok, $key, 'yes' );
check( 'D1.0 (setup) two links, one accepted', 2 === count( $w->links ) && 'accepted' === $w->links[ $other ]->status );
$n = HDLV2_Timeline_Links::forget_client( 122, 701, 'team+owner@irislab.com' );
check( 'D1.1 the inviter (client 701) deleted → their link row goes, the other stays', 1 === $n && ! isset( $w->links[ $mine ] ) && isset( $w->links[ $other ] ) );
$n = HDLV2_Timeline_Links::forget_client( 122, 555, 'TEAM+SAM@irislab.com' );
check( 'D1.2 the invitee (their lead, any case) deleted → the link they consented to goes', 1 === $n && ! $w->links );
$GLOBALS['options']['hdlv2_db_version'] = '3.31';
check( 'D1.3 before Phase AM (no table yet) → 0, no query', 0 === HDLV2_Timeline_Links::forget_client( 122, 701, 'x@y.z' ) );

echo "── D2. both delete paths call it ──\n";
$root = dirname( __DIR__, 2 );
$status = file_get_contents( $root . '/includes/sprint-4/class-hdlv2-client-status.php' );
$purge  = file_get_contents( $root . '/includes/sprint-4/class-hdlv2-admin-restore.php' );
check( 'D2.1 the practitioner\'s client delete calls forget_client()', 1 === preg_match( '/function rest_delete_client.*HDLV2_Timeline_Links::forget_client\(/s', $status ) );
check( 'D2.2 the admin purge calls forget_client() before the rows are deleted', 1 === preg_match( '/HDLV2_Timeline_Links::forget_client\(.*DELETE FROM \{\$prefix\}hdlv2_form_progress/s', $purge ) );
check( 'D2.3 /health expects the new table', false !== strpos( $status, "'hdlv2_timeline_links'" ) );

done();
