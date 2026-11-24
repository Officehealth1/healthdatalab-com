<?php
/**
 * Plan 14 fix rows A2 and A3: who may list, confirm, reject and bulk-act on a pending Stage 1 lead, with
 * the REAL HDLV2_Widget_Config (replaces the source counts L.6 and L.7). Access switch on: a practitioner
 * acts only on their own leads; an admin lists every lead, each carrying `own` and, when not own, the
 * owning practitioner's name, and may Confirm or Reject any lead one at a time, for that lead's own
 * practitioner. Bulk Confirm and bulk Reject stay on the caller's own leads for everyone, an admin
 * included. Switch absent: today's answers, keys and scope.
 *
 * Run: php tests/access/scenario-lead-scope.php   Exit: 0 all pass, 1 any fail
 */
require __DIR__ . '/stubs.php';
ac_load( array( 'security/class-hdlv2-access.php', 'sprint-1/class-hdlv2-widget-config.php' ) );
$wc = new HDLV2_Widget_Config();

/** Leads: 50 Ada's (3) pending, 51 Nora's (4) pending, 52 the admin's own pending, 53 Nora's already confirmed. */
function ls_world() {
    $w = ac_world();
    $lead = function ( $id, $prac, $name, $status ) {
        return array( 'id' => $id, 'practitioner_user_id' => $prac, 'visitor_name' => $name, 'visitor_email' => strtolower( strtok( $name, ' ' ) ) . '@example.test',
            'visitor_age' => 44, 'rate_of_ageing' => 1.1, 'stage1_data' => null, 'created_at' => '2026-11-24 08:00:00', 'visitor_country' => null, 'visitor_region' => null, 'status' => $status );
    };
    $w->t['widget_leads'] = array( $lead( 50, 3, 'Lena Fox', 'pending' ), $lead( 51, 4, 'Omar Pike', 'pending' ), $lead( 52, 1, 'Tess Hale', 'pending' ), $lead( 53, 4, 'Uma Rook', 'confirmed' ) );
    $w->t['form_progress'][] = array( 'id' => 20, 'client_user_id' => 21, 'practitioner_user_id' => 4, 'token' => str_repeat( 'e', 64 ), 'client_email' => 'uma@example.test', 'deleted_at' => null );
    return $w;
}
function ls_ids( $r ) { return array_map( function ( $l ) { return $l['id']; }, $r->get_data()['leads'] ); }
function ls_lead( $r, $id ) { foreach ( $r->get_data()['leads'] as $l ) if ( $id === $l['id'] ) return $l; return null; }
function ls_status( $w, $id ) { foreach ( $w->t['widget_leads'] as $l ) if ( $id === $l['id'] ) return $l['status']; return null; }
function ls_code( $r ) { return $r instanceof WP_Error ? $r->get_error_code() : 'ok'; }
$req = function ( $body ) { return new FakeRequest( $body ); };

// ── Switch on, practitioner Nora (4) ──
$w = ls_world(); ac_switch( '1' ); $GLOBALS['current_user'] = 4;
$r = $wc->rest_list_pending_leads( $req( array() ) );
check( 'S.1 Nora lists her own pending lead only, not Ada\'s', array( 51 ) === ls_ids( $r ) );
check( 'S.2 a practitioner\'s list carries no own / owner_name keys (as today)', ! array_key_exists( 'own', ls_lead( $r, 51 ) ) && ! array_key_exists( 'owner_name', ls_lead( $r, 51 ) ) );
check( 'S.3 Nora cannot Confirm Ada\'s lead (404)', 'lead_not_found' === ls_code( $wc->rest_confirm_lead( $req( array( 'lead_id' => 50 ) ) ) ) );
check( 'S.4 Nora cannot Reject Ada\'s lead (404), it stays pending', 'lead_not_found' === ls_code( $wc->rest_reject_lead( $req( array( 'lead_id' => 50 ) ) ) ) && 'pending' === ls_status( $w, 50 ) );
$w->writes = array(); // the fake records S.4's refused update (it matched 0 rows)
check( 'S.5 Nora\'s bulk Confirm and bulk Reject of Ada\'s lead: 403, nothing written',
    'lead_not_yours' === ls_code( $wc->rest_confirm_leads_bulk( $req( array( 'lead_ids' => array( 50 ) ) ) ) )
    && 'lead_not_yours' === ls_code( $wc->rest_reject_leads_bulk( $req( array( 'lead_ids' => array( 50 ) ) ) ) ) && ! $w->writes );

// ── Switch on, admin (1) ──
$w = ls_world(); ac_switch( '1' ); $GLOBALS['current_user'] = 1;
$r = $wc->rest_list_pending_leads( $req( array() ) );
$ids = ls_ids( $r ); sort( $ids );
check( 'S.6 the admin lists every practitioner\'s pending leads', array( 50, 51, 52 ) === $ids );
check( 'S.7 Ada\'s lead carries own = false and her name', false === ls_lead( $r, 50 )['own'] && 'Ada Lark' === ls_lead( $r, 50 )['owner_name'] );
check( 'S.8 the admin\'s own lead carries own = true and no name', true === ls_lead( $r, 52 )['own'] && ! array_key_exists( 'owner_name', ls_lead( $r, 52 ) ) );
check( 'S.9 the admin Rejects Ada\'s lead one at a time, for Ada (the write matches her id)',
    'ok' === ls_code( $wc->rest_reject_lead( $req( array( 'lead_id' => 50 ) ) ) ) && 'rejected' === ls_status( $w, 50 )
    && 3 === $w->writes[0][3]['practitioner_user_id'] );
$r = $wc->rest_confirm_lead( $req( array( 'lead_id' => 53 ) ) );
check( 'S.10 the admin\'s single Confirm acts for the lead\'s own practitioner (Nora\'s client link comes back)', 'ok' === ls_code( $r ) && str_repeat( 'e', 64 ) === $r->get_data()['form_token'] );
$w->writes = array();
check( 'S.11 the admin\'s bulk Reject of Nora\'s lead: 403, it stays pending', 'lead_not_yours' === ls_code( $wc->rest_reject_leads_bulk( $req( array( 'lead_ids' => array( 51 ) ) ) ) ) && 'pending' === ls_status( $w, 51 ) && ! $w->writes );
check( 'S.12 the admin\'s bulk Confirm of Nora\'s lead: 403, nothing written', 'lead_not_yours' === ls_code( $wc->rest_confirm_leads_bulk( $req( array( 'lead_ids' => array( 51 ) ) ) ) ) && ! $w->writes );
check( 'S.13 the admin\'s bulk Reject of a list with one foreign lead: 403, the own lead untouched too', 'lead_not_yours' === ls_code( $wc->rest_reject_leads_bulk( $req( array( 'lead_ids' => array( 52, 51 ) ) ) ) ) && 'pending' === ls_status( $w, 52 ) );
$r = $wc->rest_reject_leads_bulk( $req( array( 'lead_ids' => array( 52 ) ) ) );
check( 'S.14 the admin\'s bulk Reject of their own lead works', 'ok' === ls_code( $r ) && array( 52 ) === $r->get_data()['rejected'] && 'rejected' === ls_status( $w, 52 ) );

// ── Switch absent: today ──
$w = ls_world(); ac_switch( null ); $GLOBALS['current_user'] = 1;
$r = $wc->rest_list_pending_leads( $req( array() ) );
check( 'S.15 switch absent: the admin lists only their own lead', array( 52 ) === ls_ids( $r ) );
check( 'S.16 switch absent: no own / owner_name keys', ! array_key_exists( 'own', ls_lead( $r, 52 ) ) && ! array_key_exists( 'owner_name', ls_lead( $r, 52 ) ) );
check( 'S.17 switch absent: the admin cannot Confirm or Reject Ada\'s lead (404)',
    'lead_not_found' === ls_code( $wc->rest_confirm_lead( $req( array( 'lead_id' => 50 ) ) ) ) && 'lead_not_found' === ls_code( $wc->rest_reject_lead( $req( array( 'lead_id' => 50 ) ) ) ) );
check( 'S.18 switch absent: the admin\'s bulk on Nora\'s lead: 403', 'lead_not_yours' === ls_code( $wc->rest_reject_leads_bulk( $req( array( 'lead_ids' => array( 51 ) ) ) ) ) );
done();
