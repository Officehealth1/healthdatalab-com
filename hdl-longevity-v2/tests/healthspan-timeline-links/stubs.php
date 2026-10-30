<?php
/**
 * Shared WP stubs + fake $wpdb for the Healthspan Timeline links scenarios (v0.48.34).
 * Standalone: no WordPress, no DB. The fake models what HDLV2_Timeline_Links reads and
 * writes: form_progress (the inviter), reports (the Full Report gate), widget_config
 * (ticket_page_url), widget_invites (the link tickets), widget_leads (the invitee's answers)
 * and hdlv2_timeline_links.
 */
error_reporting( E_ALL & ~E_DEPRECATED );
ini_set( 'error_log', '/dev/null' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'HDLV2_PLUGIN_URL', 'https://stby.test/wp-content/plugins/hdl-longevity-v2/' );
define( 'HDLV2_VERSION', 'test' );

$GLOBALS['options'] = array();
$GLOBALS['mails']   = array();
$GLOBALS['routes']  = array();
$GLOBALS['current_user'] = 0;

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function register_rest_route( $ns, $route, $args ) { $GLOBALS['routes'][ $ns . $route ] = $args; }
function get_current_user_id() { return $GLOBALS['current_user']; }
function rest_ensure_response( $x ) { return $x; }
function rest_url( $p = '' ) { return 'https://stby.test/wp-json/' . ltrim( $p, '/' ); }
function wp_create_nonce( $a ) { return 'nonce-' . $a; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_json_encode( $x, $f = 0 ) { return json_encode( $x, $f ); }
function absint( $x ) { return abs( (int) $x ); }
function sanitize_text_field( $x ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $x ) ) ); }
function esc_html( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES, 'UTF-8' ); }
function esc_url_raw( $x ) { return (string) $x; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function home_url( $p = '' ) { return 'https://stby.test' . $p; }
function site_url( $p = '' ) { return 'https://stby.test' . $p; }
function add_query_arg( $key, $value, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value; }
function wp_salt( $s = '' ) { return 'test-salt-' . $s; }
function is_email( $x ) { return is_string( $x ) && false !== filter_var( $x, FILTER_VALIDATE_EMAIL ); }
function apply_filters( $t, $v ) { return $v; }
function wp_mail( $to, $subject, $body, $headers = array() ) { $GLOBALS['mails'][] = compact( 'to', 'subject', 'body' ); return true; }

class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
class WP_REST_Response {
    public $data; public $status;
    public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
    public function get_status() { return $this->status; }
    public function get_data() { return $this->data; }
}
/** WordPress keys headers lower-case with underscores (X-HDLV2-Token → x_hdlv2_token). */
class FakeRequest implements ArrayAccess {
    private $p; private $h;
    public function __construct( $params = array(), $headers = array() ) {
        $this->p = $params;
        $this->h = array();
        foreach ( $headers as $k => $v ) { $this->h[ strtolower( str_replace( '-', '_', $k ) ) ] = $v; }
    }
    public function get_param( $k ) { return $this->p[ $k ] ?? null; }
    public function get_header( $k ) { return $this->h[ strtolower( str_replace( '-', '_', $k ) ) ] ?? null; }
    public function offsetExists( $k ): bool { return isset( $this->p[ $k ] ); }
    public function offsetGet( $k ): mixed { return $this->p[ $k ] ?? null; }
    public function offsetSet( $k, $v ): void { $this->p[ $k ] = $v; }
    public function offsetUnset( $k ): void { unset( $this->p[ $k ] ); }
}

class TlWpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $progress = array(); // id => row
    public $reports  = array(); // progress id => row
    public $config   = array(); // practitioner id => ticket_page_url
    public $invites  = array(); // id => row
    public $leads    = array(); // id => row
    public $links    = array(); // id => row
    public $queries  = array();
    public $txn      = null;
    private $args    = array();
    private $next    = array( 'invites' => 300, 'links' => 0 );

    public function prepare( $sql, ...$args ) {
        $this->args = $args;
        foreach ( $args as $a ) {
            $sql = preg_replace( '/%[dsf]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $sql, 1 );
        }
        return $sql;
    }
    public function suppress_errors( $s = true ) { return false; }
    private function a( $i ) { return $this->args[ $i ] ?? null; }

    public function get_row( $sql ) {
        $this->queries[] = $sql;
        if ( false !== strpos( $sql, 'FROM wp_hdlv2_form_progress' ) && false !== strpos( $sql, 'WHERE token =' ) ) {
            foreach ( $this->progress as $r ) { if ( $r->token === $this->a( 0 ) && empty( $r->deleted_at ) ) return $r; }
            return null;
        }
        if ( false !== strpos( $sql, 'FROM wp_hdlv2_form_progress' ) && false !== strpos( $sql, 'WHERE id =' ) ) {
            $r = $this->progress[ (int) $this->a( 0 ) ] ?? null;
            return $r && empty( $r->deleted_at ) ? $r : null;
        }
        if ( false !== strpos( $sql, 'FROM wp_hdlv2_reports' ) ) return $this->reports[ (int) $this->a( 0 ) ] ?? null;
        if ( false !== strpos( $sql, 'SELECT access_mode' ) ) return isset( $this->config[ (int) $this->a( 0 ) ] ) ? (object) array( 'access_mode' => 'open' ) : null;
        if ( false !== strpos( $sql, 'FROM wp_hdlv2_widget_invites' ) && false !== strpos( $sql, 'external_ref =' ) ) {
            foreach ( $this->invites as $r ) { if ( $r->external_ref === $this->a( 0 ) ) return $r; }
            return null;
        }
        if ( false !== strpos( $sql, 'FROM wp_hdlv2_widget_invites' ) && false !== strpos( $sql, 'WHERE token =' ) ) {
            foreach ( $this->invites as $r ) { if ( $r->token === $this->a( 0 ) && 'timeline_link' === $r->source ) return $r; }
            return null;
        }
        if ( false !== strpos( $sql, 'FROM wp_hdlv2_widget_leads' ) ) {
            $hit = null;
            foreach ( $this->leads as $r ) { if ( (int) $r->invite_id === (int) $this->a( 0 ) && (int) $r->practitioner_user_id === (int) $this->a( 1 ) ) $hit = $r; }
            return $hit;
        }
        if ( false !== strpos( $sql, 'FROM wp_hdlv2_timeline_links' ) && false !== strpos( $sql, 'WHERE invite_id =' ) ) {
            foreach ( $this->links as $r ) { if ( (int) $r->invite_id === (int) $this->a( 0 ) ) return $r; }
            return null;
        }
        if ( false !== strpos( $sql, 'FROM wp_hdlv2_timeline_links' ) && false !== strpos( $sql, 'WHERE withdraw_token_hash =' ) ) {
            foreach ( $this->links as $r ) { if ( $r->withdraw_token_hash === $this->a( 0 ) && 'accepted' === $r->status ) return $r; }
            return null;
        }
        if ( false !== strpos( $sql, 'FROM wp_hdlv2_timeline_links' ) && false !== strpos( $sql, 'WHERE id =' ) ) {
            return $this->links[ (int) $this->a( 0 ) ] ?? null;
        }
        return null;
    }
    public function get_var( $sql ) {
        $this->queries[] = $sql;
        if ( false !== strpos( $sql, 'SELECT ticket_page_url' ) ) return $this->config[ (int) $this->a( 0 ) ] ?? null;
        if ( false !== strpos( $sql, 'COUNT(*) FROM wp_hdlv2_timeline_links l JOIN' ) ) {
            $n = 0;
            foreach ( $this->links as $l ) {
                $o = $this->progress[ (int) $l->owner_progress_id ] ?? null;
                if ( $o && (int) $o->client_user_id === (int) $this->a( 0 ) && empty( $o->deleted_at ) && 'accepted' === $l->status ) $n++;
            }
            return (string) $n;
        }
        if ( false !== strpos( $sql, 'COUNT(*) FROM wp_hdlv2_timeline_links' ) ) {
            $n = 0;
            foreach ( $this->links as $l ) {
                if ( (int) $l->owner_progress_id !== (int) $this->a( 0 ) ) continue;
                if ( false !== strpos( $sql, 'created_at >=' ) && $l->created_at < $this->a( 1 ) ) continue;
                $n++;
            }
            return (string) $n;
        }
        if ( false !== strpos( $sql, 'SELECT fp.client_name' ) ) {
            foreach ( $this->links as $l ) {
                if ( (int) $l->invite_id !== (int) $this->a( 0 ) ) continue;
                $o = $this->progress[ (int) $l->owner_progress_id ] ?? null;
                return $o && empty( $o->deleted_at ) ? $o->client_name : null;
            }
            return null;
        }
        return null;
    }
    public function get_results( $sql ) {
        $this->queries[] = $sql;
        if ( false === strpos( $sql, 'FROM wp_hdlv2_timeline_links l' ) ) return array();
        $out = array();
        foreach ( $this->links as $l ) {
            if ( (int) $l->owner_progress_id !== (int) $this->a( 0 ) ) continue;
            $r = clone $l;
            $r->expires_at = isset( $this->invites[ (int) $l->invite_id ] ) ? $this->invites[ (int) $l->invite_id ]->expires_at : null;
            $out[] = $r;
        }
        return $out;
    }
    private function &table( $name ) {
        foreach ( array( 'invites' => 'hdlv2_widget_invites', 'links' => 'hdlv2_timeline_links', 'leads' => 'hdlv2_widget_leads' ) as $k => $t ) {
            if ( substr( $name, -strlen( $t ) ) === $t ) return $this->$k;
        }
        $none = array();
        return $none;
    }
    public function insert( $table, $data, $format = null ) {
        $this->last_error = '';
        if ( isset( $data['external_ref'] ) ) {
            foreach ( $this->invites as $r ) { if ( $r->external_ref === $data['external_ref'] ) { $this->last_error = 'Duplicate'; return false; } }
        }
        $kind = false !== strpos( $table, 'invites' ) ? 'invites' : 'links';
        $id   = ++$this->next[ $kind ];
        $row  = (object) ( array( 'id' => $id ) + $data );
        if ( 'links' === $kind ) {
            $row = (object) ( (array) $row + array( 'invite_id' => null, 'lead_id' => null, 'shared_rate' => null, 'shared_age' => null, 'shared_sex' => null, 'withdraw_token_hash' => null, 'consent_at' => null, 'withdrawn_at' => null, 'revoked_at' => null ) );
        }
        $this->{$kind}[ $id ] = $row;
        $this->insert_id = $id;
        return 1;
    }
    public function update( $table, $data, $where, $format = null, $wf = null ) {
        $rows = &$this->table( $table );
        $n = 0;
        foreach ( $rows as $r ) {
            $match = true;
            foreach ( $where as $k => $v ) { if ( (string) ( $r->$k ?? '' ) !== (string) $v ) { $match = false; break; } }
            if ( ! $match ) continue;
            foreach ( $data as $k => $v ) { $r->$k = $v; }
            $n++;
        }
        return $n;
    }
    public function query( $sql ) {
        $this->queries[] = $sql;
        if ( 'START TRANSACTION' === $sql ) { $this->txn = array( serialize( $this->invites ), serialize( $this->links ) ); return 1; }
        if ( 'COMMIT' === $sql ) { $this->txn = null; return 1; }
        if ( 'ROLLBACK' === $sql ) {
            if ( $this->txn ) { $this->invites = unserialize( $this->txn[0] ); $this->links = unserialize( $this->txn[1] ); }
            $this->txn = null;
            return 1;
        }
        if ( 0 === strpos( ltrim( $sql ), 'DELETE FROM wp_hdlv2_timeline_links' ) ) {
            list( $prac, $client, $prac2, $email ) = $this->args;
            $n = 0;
            foreach ( $this->links as $id => $l ) {
                $o    = $this->progress[ (int) $l->owner_progress_id ] ?? null;
                $lead = $l->lead_id ? ( $this->leads[ (int) $l->lead_id ] ?? null ) : null;
                if ( ( $o && (int) $o->practitioner_user_id === (int) $prac && (int) $o->client_user_id === (int) $client )
                  || ( $lead && (int) $lead->practitioner_user_id === (int) $prac2 && strtolower( $lead->visitor_email ) === $email ) ) {
                    unset( $this->links[ $id ] );
                    $n++;
                }
            }
            return $n;
        }
        return 1;
    }
}

$ROOT = dirname( __DIR__, 2 );
require_once $ROOT . '/includes/sprint-2/class-hdlv2-email-templates.php';
require_once $ROOT . '/includes/sprint-1/class-hdlv2-offer.php';
require_once $ROOT . '/includes/sprint-2c/class-hdlv2-healthspan-timeline.php';
require_once $ROOT . '/includes/security/class-hdl-stage1-ticket.php';
$links_src = $ROOT . '/includes/sprint-2c/class-hdlv2-timeline-links.php';
if ( file_exists( $links_src ) ) {
    require_once $links_src;
}

$PASS = 0; $FAIL = 0;
function check( $label, $cond ) {
    global $PASS, $FAIL;
    if ( $cond ) { $PASS++; echo "  PASS  $label\n"; }
    else         { $FAIL++; echo "  FAIL  $label\n"; }
}
function status_of( $r ) {
    if ( $r instanceof WP_Error ) return (int) ( $r->data['status'] ?? 0 );
    if ( $r instanceof WP_REST_Response ) return $r->get_status();
    return 200;
}
function body_of( $r ) {
    if ( $r instanceof WP_Error ) return array( 'code' => $r->code, 'message' => $r->message );
    return $r instanceof WP_REST_Response ? $r->get_data() : $r;
}
function done() {
    global $PASS, $FAIL;
    echo "\nPASS=$PASS FAIL=$FAIL\n";
    exit( 0 === $FAIL ? 0 : 1 );
}
if ( ! class_exists( 'HDLV2_Timeline_Links' ) ) {
    check( 'includes/sprint-2c/class-hdlv2-timeline-links.php exists', false );
    done();
}

define( 'OWNER_T', str_repeat( 'a1', 32 ) );
define( 'OTHER_T', str_repeat( 'b2', 32 ) );
const PAGE        = 'https://stby-widget--healthdatalab.netlify.app/';

/** A fresh world: owner progress row 21 (client 701, practitioner 122) with a draft report, both flags on. */
function world() {
    global $wpdb;
    $wpdb = new TlWpdb();
    $GLOBALS['options'] = array( 'hdlv2_ff_healthspan_timeline' => '1', 'hdlv2_ff_timeline_links' => '1', 'hdlv2_db_version' => '3.32' );
    $GLOBALS['mails']   = array();
    $GLOBALS['current_user'] = 0;
    $future = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
    $wpdb->progress[21] = (object) array( 'id' => 21, 'token' => OWNER_T, 'client_user_id' => 701, 'practitioner_user_id' => 122, 'client_name' => 'Quim Test', 'client_email' => 'team+owner@irislab.com', 'token_expires_at' => $future, 'deleted_at' => null );
    $wpdb->progress[22] = (object) array( 'id' => 22, 'token' => OTHER_T, 'client_user_id' => 702, 'practitioner_user_id' => 122, 'client_name' => 'Other Client', 'client_email' => 'team+other@irislab.com', 'token_expires_at' => $future, 'deleted_at' => null );
    $wpdb->reports[21] = (object) array( 'id' => 31, 'report_type' => 'draft', 'report_content' => '{}' );
    $wpdb->reports[22] = (object) array( 'id' => 32, 'report_type' => 'final', 'report_content' => '{}' );
    $wpdb->config[122] = PAGE;
    return $wpdb;
}
function owner_req( $params = array(), $token = OWNER_T ) { return new FakeRequest( $params, array( 'X-HDLV2-Token' => $token ) ); }
function create_link( $over = array(), $token = OWNER_T ) {
    return HDLV2_Timeline_Links::rest_create( owner_req( array_merge( array( 'label' => 'Spouse', 'relationship' => 'spouse', 'born_guess' => 1975, 'sex_guess' => 'F' ), $over ), $token ) );
}
/** The invitee did the free check through the link: the ticket is claimed and the lead carries its id. */
function invitee_did_check( $link_id, $age = 40, $sex = 'female', $rate = 0.96 ) {
    global $wpdb;
    $link   = $wpdb->links[ $link_id ];
    $ticket = $wpdb->invites[ (int) $link->invite_id ];
    $ticket->status = 'completed';
    $lead_id = 900 + $link_id;
    $wpdb->leads[ $lead_id ] = (object) array(
        'id' => $lead_id, 'practitioner_user_id' => 122, 'visitor_name' => 'Sam Example', 'visitor_email' => 'team+sam@irislab.com',
        'visitor_age' => $age, 'rate_of_ageing' => $rate, 'invite_id' => $ticket->id,
        'stage1_data' => json_encode( array( 'q1_age' => $age, 'q1_sex' => $sex ) ),
    );
    return array( $ticket->token, HDLV2_Timeline_Links::consent_key( $ticket->id, $lead_id ) );
}
function consent( $token, $key, $decision ) {
    return HDLV2_Timeline_Links::rest_consent( new FakeRequest( array( 'invite_token' => $token, 'link_key' => $key, 'decision' => $decision ) ) );
}
