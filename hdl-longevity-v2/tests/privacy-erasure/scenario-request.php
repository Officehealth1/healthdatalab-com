<?php
/**
 * The request flow around the eraser (E7 off, S1 the control on My account → Privacy only, E8 route,
 * E11 warning, E12 practitioner notice, WordPress's two emails reworded, the 21-day reminder).
 *   php tests/privacy-erasure/scenario-request.php
 */
require __DIR__ . '/stubs.php';
$PLUGIN = dirname( __DIR__, 2 );
require $PLUGIN . '/includes/privacy/class-hdlv2-privacy-eraser.php';
require $PLUGIN . '/includes/privacy/class-hdlv2-privacy-requests.php';

$db = new SqliteWpdb();
$GLOBALS['wpdb'] = $db;
hdl_erase_schema( $db );
$GLOBALS['users'][1]  = (object) array( 'ID' => 1, 'user_email' => 'boss@example.test', 'roles' => array( 'administrator' ) );
$GLOBALS['users'][5]  = (object) array( 'ID' => 5, 'user_email' => 'prac@example.test', 'roles' => array( 'um_practitioner' ) );
$GLOBALS['users'][6]  = (object) array( 'ID' => 6, 'user_email' => 'prac2@example.test', 'roles' => array( 'um_practitioner' ) );
$GLOBALS['users'][10] = (object) array( 'ID' => 10, 'user_email' => 'alice@example.test', 'roles' => array( 'um_client' ) );
$GLOBALS['options']['admin_email']           = 'site-admin@example.test';
$GLOBALS['options']['hdlv2_stage1_alert_to'] = 'one@example.test, two@example.test';
$route = 'hdl-v2/v1/privacy/erase-request';
$dash  = '<div class="dashboard">…</div>';

// The membership plugin's own Privacy-tab blocks and their request handler, as Ultimate Member adds them.
function um_after_account_privacy( $args ) { echo '<div class="um-field um-field-export_data">Download your data</div><div class="um-field">Erase of your data</div>'; }
function um_request_user_data() {}
function um_is_core_page( $page ) { return 'account' === $page && ! empty( $GLOBALS['on_account'] ); }
add_action( 'um_after_account_privacy', 'um_after_account_privacy' );
add_action( 'wp_ajax_um_request_user_data', 'um_request_user_data' );
$um_only = $GLOBALS['filters'];

/** The Privacy tab's hook as Ultimate Member runs it, captured. */
function privacy_tab() { ob_start(); do_action_all( 'um_after_account_privacy', array() ); return ob_get_clean(); }
/** wp_enqueue_scripts for the current page and user; returns what was queued. */
function queued() { $GLOBALS['scripts'] = array(); do_action_all( 'wp_enqueue_scripts' ); return $GLOBALS['scripts']; }

echo "── E7. switch off: nothing registered, nothing taken away ──\n";
HDLV2_Privacy_Requests::init();
do_action_all( 'rest_api_init' );
do_action_all( 'init' );
$GLOBALS['current_user'] = 10;
$GLOBALS['on_account']   = true;
ok( 'E7.3 off: no route', ! isset( $GLOBALS['routes'][ $route ] ) );
ok( 'E7.4 off: no hook of ours, the membership plugin\'s own blocks and handler stay', $um_only === $GLOBALS['filters'] && false !== strpos( privacy_tab(), 'Erase of your data' ) && ! queued() );

$GLOBALS['options'][ HDLV2_Privacy_Eraser::FLAG ] = '1';
HDLV2_Privacy_Requests::init();
do_action_all( 'rest_api_init' );
do_action_all( 'init' );

echo "── S1. the control: My account → Privacy, and nowhere else ──\n";
$tab = privacy_tab();
ok( 'S1.1 a client\'s Privacy tab: "Your data" and "Delete my data", type=button inside the plugin\'s form', false !== strpos( $tab, 'class="hdlv2-your-data"' ) && false !== strpos( $tab, '>Your data<' ) && false !== strpos( $tab, 'type="button" class="hdlv2-your-data-link" data-erase="ask">Delete my data<' ) );
ok( 'S1.2 the membership plugin\'s own two blocks are gone, and its request handler', false === strpos( $tab, 'Download your data' ) && false === strpos( $tab, 'Erase of your data' ) && ! has_action( 'um_after_account_privacy', 'um_after_account_privacy' ) && ! has_action( 'wp_ajax_um_request_user_data', 'um_request_user_data' ) );
ok( 'S1.3 the dashboard and every other shortcode are untouched (no shortcode hook at all)', $dash === apply_filters( 'do_shortcode_tag', $dash, 'hdlv2_my_dashboard', array(), array() ) && empty( $GLOBALS['filters']['do_shortcode_tag'] ) );
$q = queued();
ok( 'S1.4 on the account page: the script with the route and a REST nonce, nothing else', true === ( $q['hdlv2-privacy-erase'] ?? null ) && array( 'url' => 'https://stby.test/wp-json/hdl-v2/v1/privacy/erase-request', 'nonce' => 'nonce-wp_rest' ) === ( $q['hdlv2-privacy-erase:HDLV2_ERASE'] ?? null ) );
ok( 'S1.5 the styles go in the head on their own handle (no file), sized for the tab', false === ( $q['hdlv2-privacy-erase:style'] ?? null ) && true === ( $q['hdlv2-privacy-erase:style-on'] ?? null ) && false !== strpos( $q['hdlv2-privacy-erase:css'] ?? '', '.hdlv2-your-data' ) && false === strpos( $q['hdlv2-privacy-erase:css'], '1024px' ) );
$GLOBALS['on_account'] = false;
ok( 'S1.6 on any other page: nothing queued', ! queued() );
$GLOBALS['on_account'] = true;
foreach ( array( 5 => 'a practitioner', 1 => 'an administrator', 0 => 'signed out' ) as $uid => $who ) {
    $GLOBALS['current_user'] = $uid;
    ok( "S1.7 $who: no control on the tab, nothing queued", '' === privacy_tab() && ! queued() );
}

echo "── E8. the route ──\n";
$r = $GLOBALS['routes'][ $route ] ?? array();
ok( 'E8.1 POST only, takes no arguments', 'POST' === ( $r['methods'] ?? '' ) && empty( $r['args'] ) );
ok( 'E8.2 signed out refused', false === call_user_func( $r['permission_callback'] ) );
$GLOBALS['current_user'] = 5;
ok( 'E8.3 a practitioner refused (handled by hand)', false === call_user_func( $r['permission_callback'] ) );
$GLOBALS['current_user'] = 10;
ok( 'E8.4 a client allowed', true === call_user_func( $r['permission_callback'] ) );
$req = new class { public function get_param( $k ) { return 'bob@example.test'; } public function get_json_params() { return array( 'email' => 'bob@example.test' ); } };
$a1 = call_user_func( $r['callback'], $req );
ok( 'E8.5 the request is made for her own address, the body\'s ignored', 1 === count( $GLOBALS['requests'] ) && 'alice@example.test' === reset( $GLOBALS['requests'] )->email && 'remove_personal_data' === reset( $GLOBALS['requests'] )->action_name );
ok( 'E8.6 the confirmation email goes', array( 100 ) === $GLOBALS['sent_links'] );
$a2 = call_user_func( $r['callback'], $req );
ok( 'E8.7 a repeat: no second request, a fresh link for the waiting one, the same sentence', 1 === count( $GLOBALS['requests'] ) && array( 100, 100 ) === $GLOBALS['sent_links'] && $a1 === $a2 && HDLV2_Privacy_Requests::SENT === $a1['message'] );
$GLOBALS['requests'][100]->status = 'request-confirmed';
$a3 = call_user_func( $r['callback'], $req );
ok( 'E8.8 already confirmed: nothing sent, and told the truth (no inbox promised)', 2 === count( $GLOBALS['sent_links'] ) && array( 'message' => 'We have your confirmed request. We will email you when your data is deleted.' ) === $a3 && false === stripos( $a3['message'], 'inbox' ) );
$GLOBALS['users'][11] = (object) array( 'ID' => 11, 'user_email' => 'fay@example.test', 'roles' => array( 'um_client' ) );
$GLOBALS['current_user'] = 11;
$GLOBALS['send_fail'] = true;
$a4 = call_user_func( $r['callback'], $req );
$GLOBALS['send_fail'] = false;
ok( 'E8.9 the email fails: an error to try again, no address in it', is_wp_error( $a4 ) && 500 === ( $a4->data['status'] ?? 0 ) && false === strpos( $a4->get_error_message(), '@' ) );
$policy = file_get_contents( $PLUGIN . '/includes/security/class-hdlv2-rate-limit-policy.php' );
ok( 'E8.10 mapped in the rate-limit policy at the strictest tier', 1 === preg_match( "~array\( 'POST', '#\^/hdl-v2/v1/privacy/erase-request\\$#',\s+self::TIER_AI_BURN \)~", $policy ) );

echo "── WordPress's two emails to the person, ours only ──\n";
$erase  = (object) array( 'ID' => 100, 'email' => 'alice@example.test', 'action_name' => 'remove_personal_data', 'status' => 'request-pending', 'user_id' => 10 );
$export = (object) array( 'ID' => 101, 'email' => 'alice@example.test', 'action_name' => 'export_personal_data', 'status' => 'request-pending', 'user_id' => 10 );
$ed     = array( 'request' => $erase, 'confirm_url' => 'https://stby.test/wp-login.php?action=confirmaction&request_id=100&confirm_key=k' );
ok( 'M.1 confirm: our subject, our HTML with WordPress\'s link, HTML header', false !== strpos( apply_filters( 'user_request_action_email_subject', 'x', 'HDL', $ed ), 'HealthDataLab' ) && '<html>confirm [' . $ed['confirm_url'] . ']</html>' === apply_filters( 'user_request_action_email_content', 'x', $ed ) && false !== stripos( apply_filters( 'user_request_action_email_headers', '', 's', 'c', 100, $ed ), 'text/html' ) );
$xd = array( 'request' => $export, 'confirm_url' => 'u' );
ok( 'M.2 an export request keeps WordPress\'s own words', 'x' === apply_filters( 'user_request_action_email_subject', 'x', 'HDL', $xd ) && 'x' === apply_filters( 'user_request_action_email_content', 'x', $xd ) && '' === apply_filters( 'user_request_action_email_headers', '', 's', 'c', 101, $xd ) );
$GLOBALS['requests'][100] = $erase;
$GLOBALS['requests'][101] = $export;
ok( 'M.3 the confirmed page, in plain words', false !== strpos( apply_filters( 'user_request_action_confirmed_message', 'core', 100 ), 'within one month' ) && 'core' === apply_filters( 'user_request_action_confirmed_message', 'core', 101 ) );
$dd = array( 'request' => $erase );
$gone = (object) array( 'ID' => 104, 'email' => 'gone@example.test', 'action_name' => 'remove_personal_data', 'status' => 'request-completed', 'user_id' => 99 );
// Alice (user 10) still exists here, so her done email is the "account kept" one; user 99 is gone.
ok( 'M.4 done: our subject, our HTML, HTML header', false !== strpos( apply_filters( 'user_erasure_fulfillment_email_subject', 'x', 'HDL', $dd ), 'deleted' ) && '<html>done kept</html>' === apply_filters( 'user_erasure_fulfillment_email_content', 'x', $dd ) && '<html>done</html>' === apply_filters( 'user_erasure_fulfillment_email_content', 'x', array( 'request' => $gone ) ) && false !== stripos( apply_filters( 'user_erasure_fulfillment_email_headers', '', 's', 'c', 100, $dd ), 'text/html' ) );

echo "── E11. the warning when the person confirms ──\n";
$cd = array( 'request' => $erase, 'user_email' => 'alice@example.test', 'manage_url' => 'https://stby.test/wp-admin/erase-personal-data.php' );
$to = apply_filters( 'user_request_confirmed_email_to', 'site-admin@example.test', $erase );
ok( 'E11.1 to the alert option\'s addresses', 'one@example.test,two@example.test' === $to );
$GLOBALS['options']['hdlv2_stage1_alert_to'] = '';
ok( 'E11.2 the site admin address when the option is empty', 'site-admin@example.test' === apply_filters( 'user_request_confirmed_email_to', 'x', $erase ) );
ok( 'E11.3 an export request keeps WordPress\'s recipient', 'x' === apply_filters( 'user_request_confirmed_email_to', 'x', $export ) );
$subject = apply_filters( 'user_request_confirmed_email_subject', 'x', 'HDL', $cd );
$body    = apply_filters( 'user_request_confirmed_email_content', 'x', $cd );
$due     = HDLV2_Privacy_Requests::due_date( time() );
ok( 'E11.4 subject: the request number and the date to finish by', false !== strpos( $subject, '#100' ) && false !== strpos( $subject, $due ) );
ok( 'E11.5 body: the number, the date, WordPress\'s placeholders for the address and the screen', false !== strpos( $body, '#100' ) && false !== strpos( $body, $due ) && false !== strpos( $body, '###USER_EMAIL###' ) && false !== strpos( $body, '###MANAGE_URL###' ) );
ok( 'E11.6 body: the hand list', 5 === count( array_filter( array( 'Brevo', 'Make', 'PDFMonkey', 'Stripe', 'Anthropic' ), function ( $w ) use ( $body ) { return false !== strpos( $body, $w ); } ) ) && false !== strpos( $body, 'not checked' ) );
ok( 'E11.7 one month on, the ICO way: 31 January → 28 February; 15 March → 15 April', '28 February 2027' === HDLV2_Privacy_Requests::due_date( strtotime( '2027-01-31 12:00 UTC' ) ) && '15 April 2027' === HDLV2_Privacy_Requests::due_date( strtotime( '2027-03-15 12:00 UTC' ) ) );

echo "── E12. the practitioner notice and the log line when the erase finishes ──\n";
$db->insert( 'wp_hdlv2_form_progress', array( 'client_user_id' => 10, 'practitioner_user_id' => 5, 'client_email' => 'alice@example.test', 'token' => 't1' ) );
$db->insert( 'wp_hdlv2_widget_leads', array( 'practitioner_user_id' => 6, 'visitor_email' => 'alice@example.test' ) );
$GLOBALS['mails'] = array();
// W1: page 3 fails after page 2 committed; the Tools screen's Retry starts again at page 1.
HDLV2_Privacy_Eraser::erase( 'alice@example.test', 1 );
HDLV2_Privacy_Eraser::erase( 'alice@example.test', 2 );
hdl_erase_all( 'alice@example.test' );
$erase->status = 'request-completed';
do_action_all( 'wp_privacy_personal_data_erased', 100 );
$to_pracs = array_column( $GLOBALS['mails'], 'to' );
ok( 'E12.1 each practitioner gets one notice, after a retry from page 1 too', array( 'prac@example.test', 'prac2@example.test' ) === $to_pracs );
ok( 'E12.2 it gives the address and nothing else of theirs', '<html>practitioner [alice@example.test]</html>' === $GLOBALS['mails'][0]['body'] && in_array( 'Content-Type: text/html; charset=UTF-8', (array) $GLOBALS['mails'][0]['headers'], true ) );
$log = (string) @file_get_contents( $GLOBALS['log_file'] );
ok( 'E12.3 one log line: the request number and counts, no address', 1 === preg_match( '/\[HDLV2 erase\] request 100 done: \{.*"hdlv2_form_progress":1/', $log ) && ! preg_match( '/@/', $log ) );
$GLOBALS['mails'] = array();
do_action_all( 'wp_privacy_personal_data_erased', 100 );
ok( 'E12.4 the hook again (a second Erase): no second notice', ! $GLOBALS['mails'] );
do_action_all( 'wp_privacy_personal_data_erased', 101 );
ok( 'E12.5 an export request: nothing', ! $GLOBALS['mails'] );

echo "── the 21-day reminder ──\n";
$GLOBALS['options']['hdlv2_stage1_alert_to'] = 'one@example.test';
$GLOBALS['requests'][102] = (object) array( 'ID' => 102, 'email' => 'old@example.test', 'action_name' => 'remove_personal_data', 'status' => 'request-confirmed', 'user_id' => 0 );
$GLOBALS['requests'][103] = (object) array( 'ID' => 103, 'email' => 'new@example.test', 'action_name' => 'remove_personal_data', 'status' => 'request-confirmed', 'user_id' => 0 );
update_post_meta( 102, '_wp_user_request_confirmed_timestamp', time() - 22 * DAY_IN_SECONDS );
update_post_meta( 103, '_wp_user_request_confirmed_timestamp', time() - 2 * DAY_IN_SECONDS );
$GLOBALS['mails'] = array();
do_action_all( 'hdlv2_pending_leads_cleanup' );
ok( 'D.1 one reminder, for the request open 22 days, to the alert address, with its number', 1 === count( $GLOBALS['mails'] ) && 'one@example.test' === $GLOBALS['mails'][0]['to'] && false !== strpos( $GLOBALS['mails'][0]['subject'], '#102' ) && false === strpos( $GLOBALS['mails'][0]['subject'] . $GLOBALS['mails'][0]['body'], '@' ) );
do_action_all( 'hdlv2_pending_leads_cleanup' );
ok( 'D.2 the next day: not again', 1 === count( $GLOBALS['mails'] ) );
$GLOBALS['gated'] = true;
update_post_meta( 103, '_wp_user_request_confirmed_timestamp', time() - 30 * DAY_IN_SECONDS );
do_action_all( 'hdlv2_pending_leads_cleanup' );
ok( 'D.3 off LIVE (gated): nothing sent', 1 === count( $GLOBALS['mails'] ) );

done_report();

/** Runs every callback on a hook (add_action stores them beside the filters). */
function do_action_all( $tag, ...$args ) {
    foreach ( $GLOBALS['filters'][ $tag ] ?? array() as $cb ) { call_user_func( $cb, ...$args ); }
}
