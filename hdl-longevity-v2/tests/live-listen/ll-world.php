<?php
/**
 * Plan 15 ("Listen"): the shared fake world. Plan 14 part B's world (plan 13's WP stubs, fake $wpdb, stubbed
 * HTTP) plus the Deepgram service, the listen class, the consultation class and the rate-limit classes, all REAL.
 * A Deepgram answer is queued as array( 'raw' => array( code, body ) ). Not a test command.
 */
require dirname( __DIR__ ) . '/live-questions/lq-world.php';
if ( ! defined( 'HOUR_IN_SECONDS' ) ) define( 'HOUR_IN_SECONDS', 3600 );
if ( ! defined( 'DAY_IN_SECONDS' ) ) define( 'DAY_IN_SECONDS', 86400 );
if ( ! defined( 'UPLOAD_ERR_OK' ) ) define( 'UPLOAD_ERR_OK', 0 );
define( implode( '_', array( 'HDLV2', 'DEEPGRAM', 'API', 'KEY' ) ), 'stub' );
$GLOBALS['mails']    = array();
$GLOBALS['enqueued'] = array();
function wp_mail( $to, $subject, $body ) { $GLOBALS['mails'][] = array( $to, $subject, $body ); return true; }
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $foot = false ) { $GLOBALS['enqueued'][ $handle ] = $deps; }
function plugins_url( $p = '' ) { return 'https://example.test/p/' . $p; }
if ( ! defined( 'HDLV2_PLUGIN_URL' ) ) define( 'HDLV2_PLUGIN_URL', 'https://example.test/p/' );
if ( ! defined( 'HDLV2_VERSION' ) ) define( 'HDLV2_VERSION', 'test' );
ha_load( array(
    'sprint-3/class-hdlv2-deepgram-service.php', 'sprint-2c/class-hdlv2-live-listen.php', 'sprint-2c/class-hdlv2-consultation.php',
    'security/class-hdlv2-rate-limit-policy.php', 'security/class-hdlv2-rate-limiter.php', 'security/class-hdlv2-rate-limit-middleware.php',
    'privacy/class-hdlv2-privacy-eraser.php', 'privacy/class-hdlv2-privacy-requests.php',
) );
function ll_switch( $v ) { if ( null === $v ) unset( $GLOBALS['options']['hdlv2_ff_live_listen'] ); else $GLOBALS['options']['hdlv2_ff_live_listen'] = $v; }
function ll_boot() { $GLOBALS['routes'] = array(); if ( class_exists( 'HDLV2_Consultation' ) ) ( new HDLV2_Consultation() )->register_rest_routes(); }

/** A request with a file, as WordPress hands a multipart upload to a route. */
class LL_Request extends FakeRequest {
    private $files; private $route; private $method;
    public function __construct( $body = array(), $headers = array(), $files = array(), $route = '', $method = 'POST' ) { parent::__construct( $body, $headers ); $this->files = $files; $this->route = $route; $this->method = $method; }
    public function get_file_params() { return $this->files; }
    public function get_route() { return $this->route; }
    public function get_method() { return $this->method; }
}
/** A real file on disk: a WebM head (finfo reads it as WebM) padded to $bytes, or plain text. */
function ll_file( $bytes = 20000, $kind = 'webm' ) {
    $p = tempnam( sys_get_temp_dir(), 'llp' );
    $head = 'webm' === $kind ? "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x04\x42\x85\x81\x02" : "hello, this is text\n";
    file_put_contents( $p, $head . str_repeat( "\0", max( 0, $bytes - strlen( $head ) ) ) );
    return array( 'name' => 'piece.webm', 'type' => 'audio/webm', 'tmp_name' => $p, 'error' => 0, 'size' => filesize( $p ) );
}
function ll_dg( $text = 'She takes metformin every morning.', $sec = 6.4 ) {
    return array( 'raw' => array( 200, json_encode( array( 'metadata' => array( 'duration' => $sec, 'request_id' => 'r1' ), 'results' => array( 'channels' => array( array( 'alternatives' => array( array( 'transcript' => $text, 'confidence' => 0.93 ) ) ) ) ) ) ) ) );
}
/** Call a registered route the way the REST server does: permission first, then the callback. */
function ll_call( $route, $uid, $body = array(), $headers = array(), $files = array() ) {
    $GLOBALS['current_user'] = $uid;
    $args = $GLOBALS['routes'][ $route ] ?? null;
    if ( ! $args ) return array( 404, null );
    if ( ! call_user_func( $args['permission_callback'] ) ) return array( $uid ? 403 : 401, null );
    $r = call_user_func( $args['callback'], new LL_Request( $body, $headers, $files, $route ) );
    if ( $r instanceof WP_Error ) return array( (int) ( $r->get_error_data()['status'] ?? 500 ), array( 'code' => $r->get_error_code(), 'message' => $r->get_error_message() ) );
    return array( $r->get_status(), $r->get_data() );
}
function ll_day_key() { return 'hdlv2_listen_s_' . gmdate( 'Ymd' ); }
