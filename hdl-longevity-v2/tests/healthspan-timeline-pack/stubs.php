<?php
/**
 * Family pack scenarios (v0.48.35): the links harness plus the pack table, a Widget_Config
 * stand-in that behaves as the real confirm does (its own transaction, the lead's invite_id
 * moved to the Full Report ticket) and a watch on nested transactions.
 */
require dirname( __DIR__ ) . '/healthspan-timeline-links/stubs.php';

$GLOBALS['confirms']  = array();
$GLOBALS['wp_users']  = array();
function email_exists( $e ) { return $GLOBALS['wp_users'][ strtolower( (string) $e ) ] ?? false; }
function current_user_can( $c ) { return ! empty( $GLOBALS['is_admin'] ); }

class HDLV2_Compatibility {
    public static function is_practitioner( $id ) { return in_array( (int) $id, array( 122, 123 ), true ); }
}
class HDLV2_Widget_Config {
    public static function confirm_lead_for_purchase( $p, $e, $t ) {
        global $wpdb;
        $GLOBALS['confirms'][] = array( $p, $e, $t );
        $wpdb->query( 'START TRANSACTION' ); // as confirm_lead_row(): MySQL commits any open transaction here
        foreach ( $wpdb->leads as $l ) {
            if ( $l->visitor_email === $e ) { $l->status = 'confirmed'; $l->invite_id = $t; }
        }
        $wpdb->query( 'COMMIT' );
        return array( 'found' => true, 'confirmed' => true );
    }
    public static function purchase_lead_state( $p, $e, $t ) { return array( 'found' => true, 'confirmed' => false ); }
}

class PackWpdb extends TlWpdb {
    public $packs = array();
    public $implicit_commits = 0;
    public $fail_full_report = false;
    public $fail_pack_update = false;
    private $pargs = array();
    private $pack_next = 40;
    private $snap = null;

    public function prepare( $sql, ...$args ) { $this->pargs = $args; return parent::prepare( $sql, ...$args ); }
    private function b( $i ) { return $this->pargs[ $i ] ?? null; }

    public function get_row( $sql ) {
        if ( false !== strpos( $sql, 'FROM wp_hdlv2_timeline_packs' ) ) {
            $this->queries[] = $sql;
            if ( false !== strpos( $sql, 'WHERE id =' ) ) return $this->packs[ (int) $this->b( 0 ) ] ?? null;
            $active = false !== strpos( $sql, "status = 'active'" );
            foreach ( array_reverse( $this->packs, true ) as $p ) {
                if ( (int) $p->owner_progress_id === (int) $this->b( 0 ) && ( ! $active || 'active' === $p->status ) ) return $p;
            }
            return null;
        }
        if ( false !== strpos( $sql, "source = 'full_report'" ) ) {
            $this->queries[] = $sql;
            foreach ( array_reverse( $this->invites, true ) as $i ) {
                if ( 'full_report' === $i->source && (int) $i->practitioner_id === (int) $this->b( 0 ) && strtolower( $i->client_email ) === strtolower( $this->b( 1 ) ) && 'revoked' !== $i->status ) return $i;
            }
            return null;
        }
        if ( false !== strpos( $sql, 'FROM wp_hdlv2_widget_leads' ) && false !== strpos( $sql, 'WHERE id =' ) ) {
            $this->queries[] = $sql;
            return $this->leads[ (int) $this->b( 0 ) ] ?? null;
        }
        if ( false !== strpos( $sql, 'FROM wp_hdlv2_widget_invites' ) && false !== strpos( $sql, 'WHERE id =' ) ) {
            $this->queries[] = $sql;
            return $this->invites[ (int) $this->b( 0 ) ] ?? null;
        }
        return parent::get_row( $sql );
    }
    public function get_var( $sql ) {
        if ( false !== strpos( $sql, 'SELECT id FROM wp_hdlv2_widget_invites WHERE token =' ) ) {
            foreach ( $this->invites as $i ) { if ( $i->token === $this->b( 0 ) ) return (string) $i->id; }
            return null;
        }
        if ( false !== strpos( $sql, 'SELECT seats_used FROM wp_hdlv2_timeline_packs' ) ) {
            return isset( $this->packs[ (int) $this->b( 0 ) ] ) ? (string) $this->packs[ (int) $this->b( 0 ) ]->seats_used : null;
        }
        if ( false !== strpos( $sql, 'FROM wp_hdlv2_form_progress WHERE client_email =' ) ) {
            foreach ( $this->progress as $r ) { if ( $r->client_email === $this->b( 0 ) && (int) $r->practitioner_user_id === (int) $this->b( 1 ) && empty( $r->deleted_at ) ) return (string) $r->id; }
            return null;
        }
        if ( false !== strpos( $sql, 'FROM wp_hdlv2_reports' ) ) {
            $this->queries[] = $sql;
            return isset( $this->reports[ (int) $this->b( 0 ) ] ) ? (string) $this->reports[ (int) $this->b( 0 ) ]->id : null;
        }
        return parent::get_var( $sql );
    }
    public function insert( $table, $data, $format = null ) {
        if ( false !== strpos( $table, 'timeline_packs' ) ) {
            $id = ++$this->pack_next;
            $this->packs[ $id ] = (object) ( array( 'id' => $id, 'ended_at' => null ) + $data );
            $this->insert_id = $id;
            return 1;
        }
        if ( $this->fail_full_report && 'full_report' === ( $data['source'] ?? '' ) ) return false;
        return parent::insert( $table, $data, $format );
    }
    public function update( $table, $data, $where, $format = null, $wf = null ) {
        if ( false === strpos( $table, 'timeline_packs' ) ) return parent::update( $table, $data, $where, $format, $wf );
        if ( $this->fail_pack_update ) return false;
        $n = 0;
        foreach ( $this->packs as $r ) {
            foreach ( $where as $k => $v ) { if ( (string) $r->$k !== (string) $v ) continue 2; }
            foreach ( $data as $k => $v ) { $r->$k = $v; }
            $n++;
        }
        return $n;
    }
    public function query( $sql ) {
        if ( 'START TRANSACTION' === $sql ) {
            if ( $this->txn ) $this->implicit_commits++;
            $this->snap = serialize( $this->packs );
        }
        if ( 'ROLLBACK' === $sql && $this->snap ) $this->packs = unserialize( $this->snap );
        if ( 0 === strpos( ltrim( $sql ), 'UPDATE wp_hdlv2_timeline_packs' ) ) {
            $this->queries[] = $sql;
            $n = 0;
            foreach ( $this->packs as $p ) {
                if ( in_array( (int) $p->owner_progress_id, $GLOBALS['forgotten_owners'] ?? array(), true ) && 'active' === $p->status ) { $p->status = 'ended'; $n++; }
            }
            return $n;
        }
        return parent::query( $sql );
    }
}

/** world() with the pack table and flag: owner 21 (client 701, final report via world's draft), practitioner 122. */
function pack_world() {
    global $wpdb;
    $old = world();
    $wpdb = new PackWpdb();
    foreach ( array( 'progress', 'reports', 'config' ) as $k ) { $wpdb->$k = $old->$k; }
    $GLOBALS['options']['hdlv2_ff_timeline_pack'] = '1';
    $GLOBALS['options']['hdlv2_db_version'] = '3.33';
    $GLOBALS['confirms'] = array(); $GLOBALS['wp_users'] = array(); $GLOBALS['is_admin'] = false;
    return $wpdb;
}
function as_user( $id ) { $GLOBALS['current_user'] = $id; }
function grant( $progress_id = 21, $user = 122 ) {
    as_user( $user );
    $r = HDLV2_Timeline_Links::rest_grant_pack( new FakeRequest( array( 'progress_id' => $progress_id ) ) );
    as_user( 0 );
    return $r;
}
function end_pack( $id, $user = 122 ) {
    as_user( $user );
    $r = HDLV2_Timeline_Links::rest_end_pack( new FakeRequest( array( 'id' => $id ) ) );
    as_user( 0 );
    return $r;
}
/** A link made by the owner, the check done with this email, then yes. */
function link_yes( $email = 'team+sam@irislab.com', $label = 'Spouse', $rel = 'spouse', $age = 40 ) {
    global $wpdb;
    $a = body_of( create_link( array( 'label' => $label, 'relationship' => $rel ) ) );
    list( $tok, $key ) = invitee_did_check( $a['id'], $age );
    $wpdb->leads[ 900 + $a['id'] ]->visitor_email = $email;
    $wpdb->leads[ 900 + $a['id'] ]->status = 'pending';
    return array( $a['id'], $tok, $key, consent( $tok, $key, 'yes' ) );
}
function seats() { global $wpdb; $p = end( $wpdb->packs ); return $p ? (int) $p->seats_used : null; }
function has_token( $x ) { return 1 === preg_match( '/[a-f0-9]{64}/', json_encode( $x ) ); }
if ( ! method_exists( 'HDLV2_Timeline_Links', 'rest_grant_pack' ) ) {
    check( 'HDLV2_Timeline_Links has the pack routes', false );
    done();
}
