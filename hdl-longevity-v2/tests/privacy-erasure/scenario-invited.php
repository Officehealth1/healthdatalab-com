<?php
/**
 * E6 — the person is the invited one on someone else's timeline: that row loses the three numbers
 * and the withdraw link, as a withdraw; the inviter's label, their pack and its used seat stay.
 *   php tests/privacy-erasure/scenario-invited.php
 */
require __DIR__ . '/stubs.php';
require dirname( __DIR__, 2 ) . '/includes/privacy/class-hdlv2-privacy-eraser.php';

$db = new SqliteWpdb();
$GLOBALS['wpdb'] = $db;
$tables = hdl_erase_schema( $db );
$GLOBALS['options'][ HDLV2_Privacy_Eraser::FLAG ] = '1';
$GLOBALS['users'][40] = (object) array( 'ID' => 40, 'user_email' => 'dan@example.test', 'roles' => array( 'um_client' ) );

// Dan's timeline: one accepted link to Alice (her lead), one still open to someone else.
$dan  = $db->insert( 'wp_hdlv2_form_progress', array( 'client_user_id' => 40, 'practitioner_user_id' => 5, 'client_email' => 'dan@example.test', 'token' => 'dan' ) );
$pack = $db->insert( 'wp_hdlv2_timeline_packs', array( 'owner_progress_id' => $dan, 'seats_total' => 5, 'seats_used' => 1, 'status' => 'active' ) );
$lead = $db->insert( 'wp_hdlv2_widget_leads', array( 'practitioner_user_id' => 5, 'visitor_name' => 'Alice', 'visitor_email' => 'alice@example.test', 'stage1_data' => '{}' ) );
$link = $db->insert( 'wp_hdlv2_timeline_links', array( 'owner_progress_id' => $dan, 'invite_id' => 900, 'label' => 'Sister', 'status' => 'accepted', 'lead_id' => $lead, 'shared_rate' => 0.95, 'shared_age' => 52, 'shared_sex' => 'F', 'withdraw_token_hash' => str_repeat( 'a', 64 ), 'pack_id' => $pack ) );
$open = $db->insert( 'wp_hdlv2_timeline_links', array( 'owner_progress_id' => $dan, 'invite_id' => 901, 'label' => 'Brother', 'status' => 'invited' ) );
$db->insert( 'wp_hdlv2_widget_invites', array( 'practitioner_id' => 5, 'token' => 'tl-900', 'client_email' => '', 'source' => 'timeline_link', 'external_ref' => "tl:$link" ) );

$answers = hdl_erase_all( 'alice@example.test' );
$row     = $db->get_row( "SELECT * FROM wp_hdlv2_timeline_links WHERE id = $link" );

echo "── E6. invited on Dan's timeline ──\n";
ok( 'E6.1 the row stays, with Dan\'s label', $row && 'Sister' === $row->label );
ok( 'E6.2 her three numbers and the withdraw link are gone', null === $row->shared_rate && null === $row->shared_age && null === $row->shared_sex && null === $row->withdraw_token_hash );
ok( 'E6.3 it reads as a withdraw, and no longer points at her lead', 'withdrawn' === $row->status && ! empty( $row->withdrawn_at ) && null === $row->lead_id );
ok( 'E6.4 her lead is gone', ! $db->get_var( "SELECT COUNT(*) FROM wp_hdlv2_widget_leads WHERE id = $lead" ) );
ok( 'E6.5 Dan\'s pack keeps its used seat, his other link and his ticket stay', '1' === (string) $db->get_var( "SELECT seats_used FROM wp_hdlv2_timeline_packs WHERE id = $pack" ) && 'invited' === $db->get_var( "SELECT status FROM wp_hdlv2_timeline_links WHERE id = $open" ) && $db->get_var( "SELECT COUNT(*) FROM wp_hdlv2_widget_invites WHERE external_ref = 'tl:$link'" ) );
ok( 'E6.6 Dan\'s record and account untouched', $db->get_var( "SELECT COUNT(*) FROM wp_hdlv2_form_progress WHERE id = $dan" ) && isset( $GLOBALS['users'][40] ) );
ok( 'E6.7 counted as withdrawn', (bool) preg_grep( '/hdlv2_timeline_links withdrawn 1/', array_merge( ...array_column( $answers, 'messages' ) ) ) );

done_report();
