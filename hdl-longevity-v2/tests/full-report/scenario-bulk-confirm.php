<?php
/**
 * H7 — POST /widget/leads/confirm-bulk: ownership of every id before any
 * write, then each pending lead confirmed exactly as the single route does
 * (one transaction per lead, email after COMMIT), against the REAL code.
 *
 * Run:  php scenario-bulk-confirm.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/stubs.php';
load_sources();
$GLOBALS['options'] = array( 'hdlv2_db_version' => '3.29' );

/** Serves a set of widget_leads rows by id (ownership in the WHERE). */
class BulkWpdb extends FakeWpdb {
    public $leads = array();       // id => row
    public $fail_signup_for = '';  // email whose form_progress INSERT fails
    public $log = array();         // ordered write log: START/COMMIT/ROLLBACK/fp/lead/mail
    public function get_results( $sql ) {
        $this->queries[] = $sql;
        if ( false !== strpos( $sql, 'hdlv2_widget_leads' ) && preg_match( '/id IN \(([\d,]+)\)/', $sql, $m ) && preg_match( '/practitioner_user_id = (\d+)/', $sql, $p ) ) {
            $out = array();
            foreach ( explode( ',', $m[1] ) as $id ) {
                $r = $this->leads[ (int) $id ] ?? null;
                if ( $r && (int) $r->practitioner_user_id === (int) $p[1] ) $out[] = $r;
            }
            return $out;
        }
        return array();
    }
    public function get_row( $sql ) {
        if ( false !== strpos( $sql, 'hdlv2_widget_leads' ) && preg_match( '/WHERE id = (\d+) AND practitioner_user_id = (\d+)/', $sql, $m ) ) {
            $r = $this->leads[ (int) $m[1] ] ?? null;
            return $r && (int) $r->practitioner_user_id === (int) $m[2] ? $r : null;
        }
        return parent::get_row( $sql );
    }
    public function query( $sql ) {
        if ( in_array( $sql, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true ) ) $this->log[] = $sql;
        return parent::query( $sql );
    }
    public function insert( $table, $data, $format = null ) {
        if ( substr( $table, -19 ) === 'hdlv2_form_progress' ) {
            if ( $this->fail_signup_for && ( $data['client_email'] ?? '' ) === $this->fail_signup_for ) { $this->log[] = 'fp-fail'; $this->insert_id = 0; return false; }
            $this->log[] = 'fp:' . ( $data['client_email'] ?? '' );
        }
        return parent::insert( $table, $data, $format );
    }
    public function update( $table, $data, $where, $format = null, $where_format = null ) {
        if ( substr( $table, -18 ) === 'hdlv2_widget_leads' && isset( $data['status'] ) ) $this->log[] = 'lead:' . $where['id'];
        return parent::update( $table, $data, $where, $format, $where_format );
    }
}
function mk_lead( $id, $status, $prac = 122 ) {
    return (object) array( 'id' => $id, 'practitioner_user_id' => $prac, 'visitor_name' => "Lead $id", 'visitor_email' => "l$id@example.test",
        'visitor_age' => 50, 'rate_of_ageing' => 1.0, 'stage1_data' => '{"q1_age":50}', 'status' => $status, 'invite_id' => null, 'visitor_country' => '', 'visitor_region' => '' );
}
function bulk_setup( $leads ) {
    global $wpdb;
    fresh_wpdb( 'open' );
    $w = new BulkWpdb();
    $w->config = $wpdb->config;
    $w->config->practitioner_user_id = 122;
    foreach ( $leads as $l ) $w->leads[ $l->id ] = $l;
    $wpdb = $w;
    $GLOBALS['current_user_id'] = 122;
    return $w;
}
function bulk( $ids ) {
    $i = widget_instance();
    if ( ! method_exists( $i, 'rest_confirm_leads_bulk' ) ) return new WP_Error( 'missing', 'no route', array( 'status' => 0 ) );
    $r = $i->rest_confirm_leads_bulk( new FakeRequest( array( 'lead_ids' => $ids ) ) );
    return $r instanceof WP_REST_Response ? ( $r->get_status() >= 400 ? new WP_Error( $r->data['code'] ?? '', '', $r->data['data'] ?? array( 'status' => $r->get_status() ) ) : $r->get_data() ) : $r;
}
// The mail stub logs into the same ordered log.
function mails_now() { return count( $GLOBALS['mails'] ); }

echo "── B1. three pending leads ──\n";
$w = bulk_setup( array( mk_lead( 1, 'pending' ), mk_lead( 2, 'pending' ), mk_lead( 3, 'pending' ) ) );
$r = bulk( array( 1, 2, 3 ) );
check( 'B1.1 answer { confirmed: [1,2,3], already: [], failed: [] }', is_array( $r ) && array( 1, 2, 3 ) === $r['confirmed'] && array() === $r['already'] && array() === $r['failed'] );
check( 'B1.2 three client records, three leads confirmed', 3 === count( $w->inserts_into( 'hdlv2_form_progress' ) ) && 3 === count( preg_grep( '/^lead:/', $w->log ) ) );
check( 'B1.3 three emails, each saying Full Report', 3 === mails_now() && 3 === count( array_filter( $GLOBALS['mails'], function ( $m ) { return false !== strpos( $m[1], 'Full Report' ); } ) ) );
check( 'B1.4 per lead: START, client record, lead update, COMMIT, in that order', array( 'START TRANSACTION', 'fp:l1@example.test', 'lead:1', 'COMMIT', 'START TRANSACTION', 'fp:l2@example.test', 'lead:2', 'COMMIT', 'START TRANSACTION', 'fp:l3@example.test', 'lead:3', 'COMMIT' ) === $w->log );

echo "── B2. one lead of another practitioner in the list ──\n";
$w = bulk_setup( array( mk_lead( 1, 'pending' ), mk_lead( 2, 'pending', 206 ) ) );
$r = bulk( array( 1, 2 ) );
check( 'B2.1 403 lead_not_yours', 403 === status_of( $r ) && 'lead_not_yours' === code_of( $r ) );
check( 'B2.2 nothing written, no email', 0 === count( $w->inserts ) && 0 === count( $w->updates ) && 0 === mails_now() && ! $w->log );
$w = bulk_setup( array( mk_lead( 1, 'pending' ) ) );
$r = bulk( array( 1, 99 ) );
check( 'B2.3 an id that does not exist → 403, nothing written', 403 === status_of( $r ) && 0 === count( $w->inserts ) && 0 === mails_now() );

echo "── B3. mixed states ──\n";
$w = bulk_setup( array( mk_lead( 1, 'confirmed' ), mk_lead( 2, 'rejected' ), mk_lead( 3, 'pending' ) ) );
$r = bulk( array( 1, 2, 3 ) );
check( 'B3.1 confirmed → already, rejected → failed lead_rejected, pending → confirmed', is_array( $r ) && array( 3 ) === $r['confirmed'] && array( 1 ) === $r['already']
    && 1 === count( $r['failed'] ) && 2 === $r['failed'][0]['id'] && 'lead_rejected' === $r['failed'][0]['code'] );
check( 'B3.2 one email only', 1 === mails_now() );

echo "── B4. bad input ──\n";
foreach ( array(
    'B4.1 more than 20 → 400 too_many'         => array( range( 1, 21 ), 'too_many' ),
    'B4.2 empty → 400 missing_lead_ids'        => array( array(), 'missing_lead_ids' ),
    'B4.3 not a list → 400 missing_lead_ids'   => array( 'x', 'missing_lead_ids' ),
    'B4.4 a non-integer → 400 invalid_lead_ids' => array( array( 1, 'abc' ), 'invalid_lead_ids' ),
) as $label => $case ) {
    $w = bulk_setup( array( mk_lead( 1, 'pending' ) ) );
    $r = bulk( $case[0] );
    check( $label, 400 === status_of( $r ) && $case[1] === code_of( $r ) && 0 === count( $w->inserts ) );
}

echo "── B5. a client record that fails rolls back, no email ──\n";
$w = bulk_setup( array( mk_lead( 1, 'pending' ), mk_lead( 2, 'pending' ) ) );
$w->fail_signup_for = 'l1@example.test';
$r = bulk( array( 1, 2 ) );
check( 'B5.1 lead 1 failed confirm_failed, lead 2 confirmed', is_array( $r ) && array( 2 ) === $r['confirmed'] && 1 === $r['failed'][0]['id'] && 'confirm_failed' === $r['failed'][0]['code'] );
check( 'B5.2 ROLLBACK for lead 1, no lead update for it, one email (lead 2)', in_array( 'ROLLBACK', $w->log, true ) && ! in_array( 'lead:1', $w->log, true ) && 1 === mails_now() );

echo "── B6. the single route keeps its answer ──\n";
$w = bulk_setup( array( mk_lead( 5, 'pending' ) ) );
$r = widget_instance()->rest_confirm_lead( new FakeRequest( array( 'lead_id' => 5 ) ) );
check( 'B6.1 { success, form_token, email_sent } in one transaction', is_array( $r ) && array_keys( $r ) === array( 'success', 'form_token', 'email_sent' ) && true === $r['email_sent'] && array( 'START TRANSACTION', 'fp:l5@example.test', 'lead:5', 'COMMIT' ) === $w->log );

echo "── B7. tier ──\n";
$tier = HDLV2_Rate_Limit_Policy::tier_for_request( 'POST', '/hdl-v2/v1/widget/leads/confirm-bulk' );
check( 'B7.1 confirm-bulk is TIER_WRITE; single confirm stays AI_BURN', HDLV2_Rate_Limit_Policy::TIER_WRITE === $tier && HDLV2_Rate_Limit_Policy::TIER_AI_BURN === HDLV2_Rate_Limit_Policy::tier_for_request( 'POST', '/hdl-v2/v1/widget/leads/confirm' ) );
done();
