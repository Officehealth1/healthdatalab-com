<?php
/**
 * Slice C — session-mode inbox panel in [hdlv2_my_dashboard].
 *
 * The logged-in render mode: the dashboard grows a Messages panel (empty
 * states get an inbox card; the populated view gets a 4th "Messages" tab —
 * tab strip asserted here via reflection, full populated render proven in the
 * STBY browser E2E). Config goes out via wp_localize_script as HDLV2_INBOX.
 *
 * Proves:
 *  (D1) logged-in client on an EMPTY state (stage1-done) gets the inbox shell
 *       (thread container + reply box) — the 12 mid-funnel betas must see it
 *  (D2) session config: mode=session, health_tracker nonce, clientHash =
 *       sha256(own email), practitionerId from form_progress (206), autoOpen
 *       false by default — and NO token key EVER in session mode
 *  (D3) ?open_thread=1 -> autoOpen true
 *  (D4) the populated tab strip contains the Messages tab + unread badge
 *  (D5) anon visitors still get the sign-in card and NO inbox markup
 *  (D6) practitioners still get the redirect card and NO inbox markup
 *  (D7) a brand-new client (no practitioner resolved yet) still renders the
 *       shell (practitionerId 0 — JS falls back to the conversation's
 *       resolved practitioner)
 *
 * Run:  php scenario-dashboard-panel.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ . '/' );
define( 'HDLV2_PLUGIN_URL', 'https://stby.healthdatalab.net/wp-content/plugins/hdl-longevity-v2/' );
define( 'HDLV2_VERSION', 'test-ver' );

$GLOBALS['t'] = array(
    'logged_in' => true,
    'uid'       => 390,
    'email'     => 'client@example.com',
    'is_prac'   => false,
    'is_admin'  => false,
    'state'     => 'stage1-done',
    'prac_id'   => 206,
);
$GLOBALS['enqueued']  = array();
$GLOBALS['localized'] = array();

// ── WP stubs ──
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function apply_filters( $tag, $value ) { return $value; }
function is_user_logged_in() { return (bool) $GLOBALS['t']['logged_in']; }
function get_current_user_id() { return (int) $GLOBALS['t']['uid']; }
function user_can( $u, $cap ) { return (bool) $GLOBALS['t']['is_admin']; }
function get_userdata( $id ) {
    if ( (int) $id === 206 ) return (object) array( 'ID' => 206, 'display_name' => 'Prac Person', 'first_name' => 'Prac', 'user_email' => 'prac@example.com' );
    return (object) array( 'ID' => $id, 'display_name' => 'Kim Client', 'first_name' => 'Kim', 'user_email' => $GLOBALS['t']['email'] );
}
function home_url( $p = '' ) { return 'https://stby.healthdatalab.net' . $p; }
function admin_url( $p = '' ) { return 'https://stby.healthdatalab.net/wp-admin/' . $p; }
function rest_url( $p = '' ) { return 'https://stby.healthdatalab.net/wp-json/' . $p; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $u ) { return $u; }
function esc_url_raw( $u ) { return $u; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
function wp_login_url( $r = '' ) { return 'https://stby.healthdatalab.net/wp-login.php'; }
function wp_create_nonce( $a = '' ) { return 'nonce-' . $a; }
function date_i18n( $f, $ts = null ) { return 'Monday'; }
function current_time( $f, $gmt = 0 ) { return $f === 'mysql' ? gmdate( 'Y-m-d H:i:s' ) : gmdate( $f ); }
function wp_enqueue_style( $h ) { $GLOBALS['enqueued'][] = $h; }
function wp_register_style( $h ) {}
function wp_enqueue_script( $h ) { $GLOBALS['enqueued'][] = $h; }
function wp_localize_script( $h, $name, $data ) { $GLOBALS['localized'][ $name ] = $data; }
function add_query_arg( $k, $v, $url ) { return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . $k . '=' . rawurlencode( $v ); }
function number_format_i18n( $n ) { return (string) $n; }

class HDLV2_Compatibility {
    public static function is_practitioner( $uid ) { return (bool) $GLOBALS['t']['is_prac']; }
    public static function get_client_journey_state( $uid ) { return $GLOBALS['t']['state']; }
}

// ── Fake wpdb — serves load_context's three lookups ──
class FakeWpdb {
    public $prefix = 'wp_';
    public function prepare( $q, ...$a ) { return $q; }
    public function get_row( $q ) {
        if ( strpos( $q, 'hdlv2_form_progress' ) !== false ) {
            if ( ! $GLOBALS['t']['prac_id'] ) return null; // brand-new: no progress row at all
            return (object) array(
                'id' => 11, 'practitioner_user_id' => $GLOBALS['t']['prac_id'],
                'stage1_data' => null, 'stage1_completed_at' => null,
                'stage3_data' => null, 'stage3_completed_at' => null,
            );
        }
        return null; // why_profiles, widget_invites, flight_plans, ...
    }
    public function get_results( $q ) { return array(); }
    public function get_var( $q ) {
        if ( strpos( $q, 'practitioner_user_id' ) !== false && strpos( $q, 'hdlv2_form_progress' ) !== false ) {
            return $GLOBALS['t']['prac_id'] ?: null;
        }
        return null;
    }
}
$GLOBALS['wpdb'] = new FakeWpdb();

require __DIR__ . '/../../includes/sprint-4/class-hdlv2-message-thread-view.php';
require __DIR__ . '/../../includes/sprint-4/class-hdlv2-client-dashboard.php';

$pass = 0; $fail = 0;
function ok( $name, $cond ) { global $pass, $fail; echo ( $cond ? 'PASS' : 'FAIL' ) . " | $name\n"; $cond ? $pass++ : $fail++; }

$dash = HDLV2_Client_Dashboard::get_instance();

// D1 — logged-in mid-funnel client sees the inbox shell
$_GET = array();
$GLOBALS['localized'] = array();
$html = $dash->render_shortcode( array() );
ok( '(D1) empty-state dashboard contains the inbox shell (thread + reply box)',
    strpos( $html, 'data-inbox' ) !== false
    && strpos( $html, 'data-inbox-thread' ) !== false
    && strpos( $html, 'data-inbox-text' ) !== false );

// D2 — session config shape + hygiene
$cfg = $GLOBALS['localized']['HDLV2_INBOX'] ?? null;
ok( '(D2a) HDLV2_INBOX localized with mode=session + health_tracker nonce',
    is_array( $cfg ) && $cfg['mode'] === 'session' && $cfg['nonce'] === 'nonce-health_tracker_nonce' );
ok( '(D2b) clientHash = sha256 of the LOGGED-IN user email (self-binding, matches is_self_as_client)',
    is_array( $cfg ) && $cfg['clientHash'] === hash( 'sha256', 'client@example.com' ) );
ok( '(D2c) practitionerId resolved from form_progress.practitioner_user_id',
    is_array( $cfg ) && (int) $cfg['practitionerId'] === 206 );
ok( '(D2d) session config NEVER carries a token', is_array( $cfg ) && ! array_key_exists( 'token', $cfg ) );
ok( '(D2e) autoOpen defaults to false', is_array( $cfg ) && $cfg['autoOpen'] === false );

// D3 — ?open_thread auto-open
$_GET = array( 'open_thread' => '1' );
$GLOBALS['localized'] = array();
$dash->render_shortcode( array() );
$cfg = $GLOBALS['localized']['HDLV2_INBOX'] ?? null;
ok( '(D3) ?open_thread=1 -> autoOpen true', is_array( $cfg ) && $cfg['autoOpen'] === true );
$_GET = array();

// D4 — populated tab strip gains the Messages tab + badge (reflection)
$ref = new ReflectionClass( 'HDLV2_Client_Dashboard' );
$m = $ref->getMethod( 'pop_tabs_strip' );
$m->setAccessible( true );
$strip = $m->invoke( $dash );
ok( '(D4) populated tab strip has a Messages tab with an unread badge slot',
    strpos( $strip, 'data-tab="messages"' ) !== false && strpos( $strip, 'data-inbox-badge' ) !== false );

// D5 — anon regression: sign-in card, no inbox
$GLOBALS['t']['logged_in'] = false;
$html = $dash->render_shortcode( array() );
ok( '(D5) anon -> sign-in card, NO inbox markup',
    strpos( $html, 'Sign in to see your dashboard' ) !== false && strpos( $html, 'data-inbox' ) === false );
$GLOBALS['t']['logged_in'] = true;

// D6 — practitioner regression: redirect card, no inbox
$GLOBALS['t']['is_prac'] = true;
$html = $dash->render_shortcode( array() );
ok( '(D6) practitioner -> redirect card, NO inbox markup',
    strpos( $html, 'This page is for clients' ) !== false && strpos( $html, 'data-inbox' ) === false );
$GLOBALS['t']['is_prac'] = false;

// D7 — brand-new client (no form_progress row -> practitionerId 0) still gets the shell
$GLOBALS['t']['state'] = 'brand-new';
$GLOBALS['t']['prac_id'] = 0;
$GLOBALS['localized'] = array();
$html = $dash->render_shortcode( array() );
$cfg = $GLOBALS['localized']['HDLV2_INBOX'] ?? null;
ok( '(D7) brand-new client still renders the shell with practitionerId 0',
    strpos( $html, 'data-inbox' ) !== false && is_array( $cfg ) && (int) $cfg['practitionerId'] === 0 );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
