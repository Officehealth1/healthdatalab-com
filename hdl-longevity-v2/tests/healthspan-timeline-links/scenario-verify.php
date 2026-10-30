<?php
/**
 * The ticket branch (Task 2) on the full-report harness: HDL_Stage1_Ticket::mint_timeline_link(),
 * /widget/verify-invite for a timeline_link ticket, and the lead POST with one (the paid_stage1
 * public path: the full nine questions, the claim, the lead tied to the ticket, plus the consent
 * key for the browser that sent the answers). Against the REAL widget-config and ticket classes.
 *
 * Run:  php scenario-verify.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require dirname( __DIR__ ) . '/full-report/stubs.php';
load_sources();
function wp_salt( $s = '' ) { return 'test-salt-' . $s; }
$src = dirname( __DIR__, 2 ) . '/includes/sprint-2c/class-hdlv2-timeline-links.php';
if ( ! file_exists( $src ) ) { check( 'class-hdlv2-timeline-links.php exists', false ); done(); }
require_once $src;

/** The full-report fake, plus the one read link_from() makes. */
class VerifyWpdb extends FakeWpdb {
    public $owner_name = 'Quim Test';
    public function get_var( $sql ) {
        if ( false !== strpos( $sql, 'SELECT fp.client_name' ) ) return $this->owner_name;
        return parent::get_var( $sql );
    }
}
function setup() {
    global $wpdb;
    fresh_wpdb( 'open' );
    $old  = $wpdb;
    $wpdb = new VerifyWpdb();
    foreach ( get_object_vars( $old ) as $k => $v ) { $wpdb->$k = $v; }
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    return $wpdb;
}

echo "── T1. mint_timeline_link ──\n";
$w = setup();
$t = HDL_Stage1_Ticket::mint_timeline_link( 206, 'Spouse', 'tl:7', 90 );
$row = $w->inserts_into( 'hdlv2_widget_invites' )[0]['data'] ?? array();
check( 'T1.1 a ticket: source timeline_link, client_email \'\', client_name = label, the ref, 90 days, pending', is_array( $t ) && 'timeline_link' === $row['source']
    && '' === $row['client_email'] && 'Spouse' === $row['client_name'] && 'tl:7' === $row['external_ref'] && 'pending' === $row['status']
    && abs( strtotime( $row['expires_at'] . ' UTC' ) - ( time() + 90 * DAY_IN_SECONDS ) ) < 60 );
$w = setup();
$w->config->access_mode = 'open';
check( 'T1.2 an open-mode practitioner can mint one (not paid-only)', is_array( HDL_Stage1_Ticket::mint_timeline_link( 206, 'Mum', 'tl:8', 90 ) ) );
$r = HDL_Stage1_Ticket::handle( new FakeRequest( array( 'practitioner_id' => 206, 'email' => 'a@b.test', 'name' => 'X', 'external_ref' => 'tl:9', 'product' => 'timeline_link' ), array( 'X-HDL-Stage1-Ticket-Key' => 'k' ) ) );
check( 'T1.3 the keyed seller route cannot mint one', is_wp_error( $r ) );

echo "── T2. /widget/verify-invite ──\n";
$w = setup();
$w->invite = invite_row( array( 'source' => 'timeline_link', 'client_email' => '', 'client_name' => 'Spouse' ) );
$r = widget_instance()->rest_verify_invite( new FakeRequest( array( 'token' => TOKEN ) ) );
check( 'T2.1 valid, source timeline_link, link_from = the inviter\'s first name', is_array( $r ) && true === $r['valid'] && 'timeline_link' === $r['source'] && 'Quim' === ( $r['link_from'] ?? null ) );
check( 'T2.2 the inviter\'s label is not handed over as the visitor\'s name; no email', '' === $r['client_name'] && '' === $r['client_email'] );
check( 'T2.3 nothing else about the inviter: no email, no surname, no ids', false === strpos( json_encode( $r ), 'Test' ) && ! isset( $r['owner'] ) );
$w = setup();
$w->invite = invite_row( array( 'source' => 'paid_stage1' ) );
$r = widget_instance()->rest_verify_invite( new FakeRequest( array( 'token' => TOKEN ) ) );
check( 'T2.4 a paid_stage1 ticket answers as before (no link_from, its name kept)', ! array_key_exists( 'link_from', $r ) && 'Buyer' === $r['client_name'] );
$w = setup();
$w->invite = invite_row( array( 'source' => 'challenge' ) );
$r = widget_instance()->rest_verify_invite( new FakeRequest( array( 'token' => TOKEN ) ) );
check( 'T2.5 a challenge ticket answers as before', ! array_key_exists( 'link_from', $r ) && 'challenge' === $r['source'] );

echo "── T3. the lead POST with a timeline_link ticket ──\n";
$w = setup();
$w->invite = invite_row( array( 'id' => 88, 'source' => 'timeline_link', 'client_email' => '', 'client_name' => 'Spouse' ) );
$r = post_lead( lead_params( 'sam@example.test', TOKEN ) );
$leads = $w->inserts_into( 'hdlv2_widget_leads' );
check( 'T3.1 the public path: a pending lead, no form_token, no client record', is_array( $r ) && empty( $r['form_token'] ) && 1 === count( $leads ) && 0 === count( $w->inserts_into( 'hdlv2_form_progress' ) ) );
check( 'T3.2 the ticket is claimed and the lead carries its id', 'completed' === $w->invite->status && 88 === (int) ( $leads[0]['data']['invite_id'] ?? 0 ) );
check( 'T3.3 the reply carries the consent key for this lead', isset( $r['link_key'] ) && HDLV2_Timeline_Links::consent_key( 88, $w->insert_id ) === $r['link_key'] );
$cached = $GLOBALS['transients'] ? array_values( $GLOBALS['transients'] ) : array();
$in_cache = false;
foreach ( $cached as $c ) { if ( is_array( $c ) && isset( $c['link_key'] ) ) $in_cache = true; }
check( 'T3.4 the key is not in the 60-second repeat cache (a second post with the same email gets no key)', ! $in_cache );
$w->invites = array(
    TOKEN => $w->invite,
    str_repeat( 'cd', 32 ) => invite_row( array( 'id' => 90, 'token' => str_repeat( 'cd', 32 ), 'source' => 'timeline_link', 'client_email' => '', 'client_name' => 'Mum' ) ),
);
$r2 = post_lead( lead_params( 'sam@example.test', str_repeat( 'cd', 32 ) ) );
check( 'T3.4b the same email through a second link within the minute claims that link and gets its own key', is_array( $r2 ) && 'completed' === $w->invites[ str_repeat( 'cd', 32 ) ]->status
    && isset( $r2['link_key'] ) && $r2['link_key'] !== $r['link_key'] );
$w = setup();
$w->invite = invite_row( array( 'id' => 89, 'source' => 'timeline_link', 'practitioner_id' => 122, 'client_email' => '' ) );
$r = post_lead( lead_params( 'sam@example.test', TOKEN ) );
check( 'T3.5 another practitioner\'s link ticket is not honoured here (plain public lead, no key)', is_array( $r ) && ! isset( $r['link_key'] ) && 'pending' === $w->invite->status );
$w = setup();
$w->invite = invite_row( array( 'source' => 'paid_stage1' ) );
$r = post_lead( lead_params( 'buyer@example.test', TOKEN ) );
check( 'T3.6 a paid_stage1 ticket\'s reply is unchanged (no key)', is_array( $r ) && ! isset( $r['link_key'] ) );

done();
