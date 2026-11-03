<?php
/**
 * View as client (v0.48.40): the products stubs (fake $wpdb, WP functions, the real Offer,
 * Challenge, Timeline and Products classes) plus what the real dashboard, its page guard and
 * HDLV2_Client_Status need. Nonces are bound to the signed-in user, as WordPress binds them to
 * the session; a redirect stops the request the way exit does.
 */
require dirname( __DIR__ ) . '/dashboard-products/stubs.php';

define( 'HDLV2_PLUGIN_URL', 'https://example.test/wp-content/plugins/hdl-longevity-v2/' );
define( 'HDLV2_VERSION', 'test' );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['enq'] = array();
$GLOBALS['localized'] = array();
$GLOBALS['page'] = 'my-dashboard';
function wp_register_style( ...$a ) {}
function wp_enqueue_script( $h ) { $GLOBALS['enq'][] = $h; }
function wp_localize_script( $h, $name, $data ) { $GLOBALS['localized'][ $name ] = $data; }
function wp_script_is( $h, $l ) { return in_array( $h, $GLOBALS['enq'], true ); }
function wp_create_nonce( $action = '' ) { return substr( md5( $action . '|' . get_current_user_id() ), 0, 10 ); }
function wp_verify_nonce( $nonce, $action ) { return is_string( $nonce ) && '' !== $nonce && $nonce === wp_create_nonce( $action ) ? 1 : false; }
function wp_unslash( $x ) { return $x; }
function wp_doing_ajax() { return false; }
function is_page( $slug ) { return $GLOBALS['page'] === $slug; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . $p; }
function number_format_i18n( $n ) { return (string) $n; }

class Redirected extends Exception {}
function wp_safe_redirect( $to, $status = 302 ) { throw new Redirected( $to ); }

/** Progress rows by id as well as by client (the view-as link names a row). */
class ViewAsWpdb extends ProductsWpdb {
    public $older = array(); // rows that are not a client's newest live one: deleted, or older
    private $va = array();
    public function prepare( $sql, ...$args ) { $this->va = $args; return parent::prepare( $sql, ...$args ); }
    private function rows() {
        return array_merge( array_values( $this->progress ), $this->older );
    }
    private function live_by_id( $id ) {
        foreach ( $this->rows() as $r ) {
            if ( (int) $r->id === (int) $id && empty( $r->deleted_at ) ) return $r;
        }
        return null;
    }
    public function get_var( $sql ) {
        if ( false !== strpos( $sql, 'hdlv2_form_progress' ) && preg_match( '/WHERE\s+id = %d AND deleted_at IS NULL/', $sql ) ) {
            $this->calls[] = $sql;
            $r = $this->live_by_id( $this->va[0] ?? 0 );
            if ( ! $r ) return null;
            return false !== strpos( $sql, 'SELECT client_user_id' ) ? (string) $r->client_user_id : (string) $r->practitioner_user_id;
        }
        if ( false !== strpos( $sql, 'hdlv2_form_progress' ) && false !== strpos( $sql, 'MAX(id)' ) ) {
            $this->calls[] = $sql;
            $max = 0;
            foreach ( $this->rows() as $r ) {
                if ( (int) $r->client_user_id === (int) ( $this->va[0] ?? 0 ) && empty( $r->deleted_at ) ) $max = max( $max, (int) $r->id );
            }
            return $max ? (string) $max : null;
        }
        if ( false !== strpos( $sql, 'SELECT practitioner_user_id' ) && false !== strpos( $sql, 'WHERE client_user_id = %d' ) ) {
            $this->calls[] = $sql;
            $r = $this->progress[ (int) ( $this->va[0] ?? 0 ) ] ?? null;
            return $r ? (string) $r->practitioner_user_id : null;
        }
        return parent::get_var( $sql );
    }
}

$R = dirname( __DIR__, 2 ) . '/';
require_once $R . 'includes/sprint-2/class-hdlv2-stage1-commentary.php';
require_once $R . 'includes/sprint-4/class-hdlv2-message-thread-view.php';
require_once $R . 'includes/sprint-4/class-hdlv2-client-status.php';
require_once $R . 'includes/sprint-4/class-hdlv2-client-dashboard.php';

/** One panel's markup: from its data-tab-panel to the next one. */
function panel( $html, $key ) {
    $at = strpos( $html, 'data-tab-panel="' . $key . '"' );
    if ( false === $at ) return '';
    $next = strpos( $html, 'data-tab-panel="', $at + 1 );
    return substr( $html, $at, false === $next ? null : $next - $at );
}
function call_private( $obj, $name, ...$args ) {
    if ( ! method_exists( $obj, $name ) ) return null;
    $m = new ReflectionMethod( $obj, $name );
    $m->setAccessible( true );
    return $m->invoke( $obj, ...$args );
}
/** Sets a private property of the dashboard (the singleton keeps state between scenarios). */
function set_private( $obj, $name, $value ) {
    if ( ! property_exists( $obj, $name ) ) return;
    $p = new ReflectionProperty( $obj, $name );
    $p->setAccessible( true );
    $p->setValue( $obj, $value );
}
