<?php
/**
 * H7, revised 2026-10-14: bulk Reject beside bulk Confirm, both capped at 20
 * ids a call, and the pending list tells the dashboard from how many leads the
 * bulk controls show (bulk_min). Real HDLV2_Widget_Config.
 *
 * Run:  php scenario-bulk-actions.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
ini_set( 'error_log', '/dev/null' );
require __DIR__ . '/stubs.php';
load_sources();
$GLOBALS['options'] = array( 'hdlv2_db_version' => '3.30' );

class RejectWpdb extends FakeWpdb {
    public $leads = array();
    public $pending_rows = array();
    public function get_results( $sql ) {
        $this->queries[] = $sql;
        if ( false !== strpos( $sql, "status = 'pending'" ) && false !== strpos( $sql, 'LIMIT 50' ) ) return $this->pending_rows;
        if ( false !== strpos( $sql, 'hdlv2_widget_leads' ) && preg_match( '/id IN \(([\d,]+)\)/', $sql, $m ) && preg_match( '/practitioner_user_id = (\d+)/', $sql, $p ) ) {
            $out = array();
            foreach ( explode( ',', $m[1] ) as $id ) {
                $r = $this->leads[ (int) $id ] ?? null;
                if ( $r && (int) $r->practitioner_user_id === (int) $p[1] ) $out[] = clone $r;
            }
            return $out;
        }
        return array();
    }
    public function update( $table, $data, $where, $format = null, $where_format = null ) {
        if ( substr( $table, -18 ) === 'hdlv2_widget_leads' && isset( $where['status'] ) ) {
            $r = $this->leads[ (int) $where['id'] ] ?? null;
            if ( ! $r || (int) $r->practitioner_user_id !== (int) $where['practitioner_user_id'] || $r->status !== $where['status'] ) return 0;
            $this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
            $r->status = $data['status'];
            return 1;
        }
        return parent::update( $table, $data, $where, $format, $where_format );
    }
}
function mk( $id, $status, $prac = 122 ) {
    return (object) array( 'id' => $id, 'practitioner_user_id' => $prac, 'visitor_name' => "L$id", 'visitor_email' => "l$id@example.test", 'status' => $status,
        'visitor_age' => 50, 'rate_of_ageing' => 1.0, 'stage1_data' => '{}', 'created_at' => '2026-10-14 08:00:00', 'visitor_country' => '', 'visitor_region' => '' );
}
function setup( $leads ) {
    global $wpdb;
    fresh_wpdb( 'open' );
    $w = new RejectWpdb();
    $w->config = $wpdb->config;
    foreach ( $leads as $l ) $w->leads[ $l->id ] = $l;
    $wpdb = $w;
    $GLOBALS['current_user_id'] = 122;
    return $w;
}
function rbulk( $ids ) {
    $i = widget_instance();
    if ( ! method_exists( $i, 'rest_reject_leads_bulk' ) ) return new WP_Error( 'missing', 'no route', array( 'status' => 0 ) );
    return $i->rest_reject_leads_bulk( new FakeRequest( array( 'lead_ids' => $ids ) ) );
}
function cbulk( $ids ) { return widget_instance()->rest_confirm_leads_bulk( new FakeRequest( array( 'lead_ids' => $ids ) ) ); }

echo "── X1. three pending leads ──\n";
$w = setup( array( mk( 1, 'pending' ), mk( 2, 'pending' ), mk( 3, 'pending' ) ) );
$r = rbulk( array( 1, 2, 3 ) );
check( 'X1.1 { rejected: [1,2,3], already: [], failed: [] }', is_array( $r ) && array( 1, 2, 3 ) === $r['rejected'] && array() === $r['already'] && array() === $r['failed'] );
check( 'X1.2 each one the single Reject UPDATE (status rejected, rejected_at, WHERE status pending + owner)', 3 === count( $w->updates ) && 'rejected' === $w->updates[0]['data']['status'] && ! empty( $w->updates[0]['data']['rejected_at'] ) && array( 'id' => 1, 'practitioner_user_id' => 122, 'status' => 'pending' ) === $w->updates[0]['where'] );
check( 'X1.3 no email, no client record', 0 === count( $GLOBALS['mails'] ) && 0 === count( $w->inserts ) );

echo "── X2. ownership ──\n";
$w = setup( array( mk( 1, 'pending' ), mk( 2, 'pending', 206 ) ) );
$r = rbulk( array( 1, 2 ) );
check( 'X2.1 403 lead_not_yours, nothing written', 403 === status_of( $r ) && 'lead_not_yours' === code_of( $r ) && 0 === count( $w->updates ) );

echo "── X3. terminal states ──\n";
$w = setup( array( mk( 1, 'confirmed' ), mk( 2, 'rejected' ), mk( 3, 'pending' ) ) );
$r = rbulk( array( 1, 2, 3 ) );
check( 'X3.1 confirmed + rejected come back under already, pending rejected', is_array( $r ) && array( 3 ) === $r['rejected'] && array( 1, 2 ) === $r['already'] && 1 === count( $w->updates ) );

echo "── X4. the batch cap and bad input, both routes ──\n";
$w = setup( array( mk( 1, 'pending' ) ) );
$r = rbulk( range( 1, 21 ) );
check( 'X4.1 reject: 21 ids → 400 too_many, nothing written', 400 === status_of( $r ) && 'too_many' === code_of( $r ) && 0 === count( $w->updates ) );
$r = cbulk( range( 1, 21 ) );
check( 'X4.2 confirm: 21 ids → 400 too_many', 400 === status_of( $r ) && 'too_many' === code_of( $r ) );
check( 'X4.3 reject: empty → 400 missing_lead_ids', 'missing_lead_ids' === code_of( rbulk( array() ) ) );
check( 'X4.4 reject: non-integer → 400 invalid_lead_ids', 'invalid_lead_ids' === code_of( rbulk( array( 1, 'x' ) ) ) );

echo "── X5. tier + route ──\n";
check( 'X5.1 reject-bulk is TIER_WRITE', HDLV2_Rate_Limit_Policy::TIER_WRITE === HDLV2_Rate_Limit_Policy::tier_for_request( 'POST', '/hdl-v2/v1/widget/leads/reject-bulk' ) );
$src = file_get_contents( __DIR__ . '/../../includes/sprint-1/class-hdlv2-widget-config.php' );
check( 'X5.2 the route is registered with practitioner auth', 1 === preg_match( "#'/widget/leads/reject-bulk', array\(\s*'methods'\s*=> 'POST',\s*'callback'\s*=> array\( \\\$this, 'rest_reject_leads_bulk' \),\s*'permission_callback' => array\( \\\$this, 'check_practitioner_permission' \)#", $src ) );

echo "── X6. bulk_min on the pending list ──\n";
$w = setup( array() );
$w->pending_rows = array( mk( 7, 'pending' ) );
$r = widget_instance()->rest_list_pending_leads( new FakeRequest( array() ) );
check( 'X6.1 BULK_MIN_PENDING is 10', defined( 'HDLV2_Widget_Config::BULK_MIN_PENDING' ) && 10 === HDLV2_Widget_Config::BULK_MIN_PENDING );
check( 'X6.2 the reply carries bulk_min: 10 beside the leads', is_array( $r ) && 10 === ( $r['bulk_min'] ?? null ) && 1 === count( $r['leads'] ) );
done();
