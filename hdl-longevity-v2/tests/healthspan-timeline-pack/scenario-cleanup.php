<?php
/** forget_client ends the owner's pack with their links. Run: php scenario-cleanup.php */
require __DIR__ . '/stubs.php';
$w = pack_world();
$id = body_of( grant() )['pack']['id'];
list( $l ) = link_yes();
$GLOBALS['forgotten_owners'] = array( 21 );
HDLV2_Timeline_Links::forget_client( 122, 701, 'team+owner@irislab.com' );
check( 'C.1 the owner deleted: pack ended, links gone', 'ended' === $w->packs[ $id ]->status && ! isset( $w->links[ $l ] ) );
check( 'C.2 the report given stays with its person', (bool) array_filter( $w->invites, function ( $i ) { return 'full_report' === $i->source; } ) );
$GLOBALS['options']['hdlv2_db_version'] = '3.32';
$w = pack_world();
$GLOBALS['options']['hdlv2_db_version'] = '3.32';
$before = count( $w->queries );
HDLV2_Timeline_Links::forget_client( 122, 701, 'team+owner@irislab.com' );
check( 'C.3 before Phase AN: the pack table is not touched', ! array_filter( array_slice( $w->queries, $before ), function ( $q ) { return false !== strpos( $q, 'timeline_packs' ); } ) );
done();
