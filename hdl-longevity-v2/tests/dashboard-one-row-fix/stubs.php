<?php
/**
 * One-row fix (v0.48.39): the products stubs (fake $wpdb, WP functions, the real Offer, Challenge
 * and Timeline classes) plus what rendering the real HDLV2_Client_Dashboard needs.
 */
require dirname( __DIR__ ) . '/dashboard-products/stubs.php';

define( 'HDLV2_PLUGIN_URL', 'https://stby.test/wp-content/plugins/hdl-longevity-v2/' );
define( 'HDLV2_VERSION', 'test' );
$GLOBALS['enq'] = array();
function wp_register_style( ...$a ) {}
function wp_enqueue_script( $h ) { $GLOBALS['enq'][] = $h; }
function wp_localize_script( ...$a ) {}
function wp_script_is( $h, $l ) { return in_array( $h, $GLOBALS['enq'], true ); }
function wp_create_nonce( $a = '' ) { return 'n'; }
function admin_url( $p = '' ) { return 'https://stby.test/wp-admin/' . $p; }
function rest_url( $p = '' ) { return 'https://stby.test/wp-json/' . $p; }
function number_format_i18n( $n ) { return (string) $n; }

$R = dirname( __DIR__, 2 ) . '/';
require_once $R . 'includes/sprint-2/class-hdlv2-stage1-commentary.php'; // the timeline's first look reads the Stage 1 bio age
require_once $R . 'includes/sprint-4/class-hdlv2-message-thread-view.php';
require_once $R . 'includes/sprint-4/class-hdlv2-client-dashboard.php';

/** One panel's markup: from its data-tab-panel to the next one (panels may hold their own sections). */
function panel( $html, $key ) {
    $at = strpos( $html, 'data-tab-panel="' . $key . '"' );
    if ( false === $at ) return '';
    $next = strpos( $html, 'data-tab-panel="', $at + 1 );
    return substr( $html, $at, false === $next ? null : $next - $at );
}
/** A private method of the dashboard, called; null when it does not exist yet. */
function call_private( $obj, $name, ...$args ) {
    if ( ! method_exists( $obj, $name ) ) return null;
    $m = new ReflectionMethod( $obj, $name );
    $m->setAccessible( true );
    return $m->invoke( $obj, ...$args );
}
