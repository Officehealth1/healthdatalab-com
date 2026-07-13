<?php
/**
 * Slice C — fail-closed when the V1 validator is unavailable.
 *
 * Separate process from scenario-thread-view.php because a class definition
 * cannot be undone: here HDL_Messaging_Service exists WITHOUT the public
 * static (models an outdated V1 file on a server). The interceptor must treat
 * every token as invalid (fail closed): anon gets the graceful expired card
 * (still better than the /login/ dead-end), logged-in users get their
 * dashboard. It must NEVER fatal and NEVER render a thread.
 *
 * Run:  php scenario-thread-view-noservice.php
 */

error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ . '/' );
define( 'HDLV2_PLUGIN_URL', 'https://stby.healthdatalab.net/wp-content/plugins/hdl-longevity-v2/' );
define( 'HDLV2_VERSION', 'test-ver' );

function add_action() {}
function add_filter() {}
function apply_filters( $tag, $value ) { return $value; }
function home_url( $p = '' ) { return 'https://stby.healthdatalab.net' . $p; }
function admin_url( $p = '' ) { return 'https://stby.healthdatalab.net/wp-admin/' . $p; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $u ) { return $u; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }

// Old V1 file: the class exists but the Slice C static does NOT.
class HDL_Messaging_Service {}

require __DIR__ . '/../../includes/sprint-4/class-hdlv2-message-thread-view.php';

$pass = 0; $fail = 0;
function ok( $name, $cond ) { global $pass, $fail; echo ( $cond ? 'PASS' : 'FAIL' ) . " | $name\n"; $cond ? $pass++ : $fail++; }

$r = HDLV2_Message_Thread_View::evaluate_request( array( 'msg' => 'm1.VALID-A' ), '/my-dashboard', null, true );
ok( '(HN1) validator method missing + anon -> fail-closed expired card (no thread, no fatal)',
    $r['action'] === 'render_expired' && strpos( $r['html'], 'hdlv2-inbox-config' ) === false );

$r = HDLV2_Message_Thread_View::evaluate_request( array( 'msg' => 'm1.VALID-A' ), '/my-dashboard', str_repeat( 'a', 64 ), true );
ok( '(HN2) validator method missing + logged-in -> dashboard redirect',
    $r['action'] === 'redirect' && strpos( $r['url'], 'open_thread=1' ) !== false );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
