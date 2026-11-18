<?php
/**
 * v0.48.85 (plan 2k, planner's gate G3.2) — while an account carries hdlv2_unproven_entry, nothing may move
 * it out of the inbox proof's reach:
 *   - its e-mail cannot change by any route that saves a user (wp_pre_insert_user_data keeps it), and the
 *     owner is not told of a change that did not happen (send_email_change_email);
 *   - no dashboard page prints the account's own Full Report ticket as a ?invite= link;
 *   - the older plugin's "switch practitioner" call is refused before it runs.
 * Against the REAL HDLV2_Unproven_Guard and HDLV2_Client_Dashboard (the invite lookup), with fakes.
 *
 * Run:  php scenario-unproven-guard.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/../full-report/stubs.php';
function wp_salt( $scheme = 'auth' ) { return 'salt-one-for-tests'; }
load_sources();
$ROOT = __DIR__ . '/../../';
foreach ( array( 'includes/security/class-hdlv2-unproven-guard.php', 'includes/sprint-4/class-hdlv2-client-dashboard.php' ) as $f ) {
    if ( file_exists( $ROOT . $f ) ) require_once $ROOT . $f;
}
$LOG = tempnam( sys_get_temp_dir(), 'hdlv2-guard-log' );
ini_set( 'log_errors', '1' );
ini_set( 'error_log', $LOG );

const MARKED = 777;
const PLAIN  = 778;
$GLOBALS['usermeta'] = array( MARKED => array( 'hdlv2_unproven_entry' => '4401' ) );
$HAS = class_exists( 'HDLV2_Unproven_Guard' );
check( 'UG0 HDLV2_Unproven_Guard exists', $HAS );

echo "── UG1. the e-mail stays while the mark is set ──\n";
$data = array( 'user_login' => 'buyer', 'user_email' => 'holder@example.test', 'display_name' => 'B' );
$kept = $HAS ? HDLV2_Unproven_Guard::keep_email( $data, true, MARKED ) : $data;
check( 'UG1.1 a marked account updated with a new e-mail: the stored one is kept', 'prac' . MARKED . '@example.test' === $kept['user_email'] );
check( 'UG1.2 every other field passes through', 'buyer' === $kept['user_login'] && 'B' === $kept['display_name'] );
$plain = $HAS ? HDLV2_Unproven_Guard::keep_email( $data, true, PLAIN ) : null;
check( 'UG1.3 an account without the mark: the new e-mail passes', $data === $plain );
check( 'UG1.4 a new user (no id, not an update): untouched', $HAS && $data === HDLV2_Unproven_Guard::keep_email( $data, false, null ) );
check( 'UG1.5 the mark is read for the account being saved, not the signed-in one', $HAS && ( $GLOBALS['current_user_id'] = MARKED ) && $data === HDLV2_Unproven_Guard::keep_email( $data, true, PLAIN ) );
$GLOBALS['current_user_id'] = 206;

echo "── UG2. no \"your e-mail was changed\" mail for a change that did not happen ──\n";
check( 'UG2.1 a marked account: no mail', $HAS && false === HDLV2_Unproven_Guard::no_change_mail( true, array( 'ID' => MARKED ) ) );
check( 'UG2.2 an account without the mark: unchanged', $HAS && true === HDLV2_Unproven_Guard::no_change_mail( true, array( 'ID' => PLAIN ) ) );

echo "── UG3. the older plugin's switch practitioner is refused for a marked account ──\n";
function switch_as( $uid ) {
    $GLOBALS['current_user_id'] = $uid;
    try { HDLV2_Unproven_Guard::refuse_switch(); return 'ran on'; } catch ( AjaxDone $e ) { return $e; }
}
$r = $HAS ? switch_as( MARKED ) : null;
check( 'UG3.1 marked: an error answer before the switch runs', $r instanceof AjaxDone && false === $r->ok );
check( 'UG3.2 the error reuses the older plugin\'s words (no new reader-visible words)', $r instanceof AjaxDone && 'Security check failed' === ( $r->data['message'] ?? '' ) );
check( 'UG3.3 without the mark: nothing happens, the switch runs as before', $HAS && 'ran on' === switch_as( PLAIN ) );
check( 'UG3.4 signed out: nothing happens', $HAS && 'ran on' === switch_as( 0 ) );
$GLOBALS['current_user_id'] = 206;

echo "── UG4. the dashboard never prints a marked account's own ticket ──\n";
class InviteWpdb extends FakeWpdb {
    public function get_row( $sql ) {
        $this->queries[] = $sql;
        return false !== strpos( $sql, 'hdlv2_widget_invites' ) ? (object) array( 'token' => str_repeat( 'c', 64 ) ) : null;
    }
}
$m = method_exists( 'HDLV2_Client_Dashboard', 'start_invite_token' ) ? new ReflectionMethod( 'HDLV2_Client_Dashboard', 'start_invite_token' ) : null;
if ( $m ) $m->setAccessible( true );
function invite_for( $m, $uid, $email = 'buyer@example.test' ) {
    global $wpdb;
    $wpdb = new InviteWpdb();
    $u = (object) array( 'ID' => $uid, 'user_email' => $email );
    return array( $m ? $m->invoke( null, $u ) : 'NO METHOD', $wpdb );
}
list( $t, $w ) = invite_for( $m, MARKED );
check( 'UG4.1 a marked account: no token, and the invites table is not read', '' === $t && ! $w->sql_matching( 'hdlv2_widget_invites' ) );
list( $t, $w ) = invite_for( $m, PLAIN );
check( 'UG4.2 an account without the mark: its newest usable invite token, as before', str_repeat( 'c', 64 ) === $t );
check( 'UG4.3 the lookup is the base one (pending or opened, not paid_stage1 or challenge, not run out)', 1 === count( $w->sql_matching( "status IN ('pending','opened')" ) ) && 1 === count( $w->sql_matching( "source NOT IN ('paid_stage1','challenge')" ) ) && 1 === count( $w->sql_matching( 'expires_at >' ) ) );
list( $t, $w ) = invite_for( $m, PLAIN, '' );
check( 'UG4.4 no e-mail: no token, no read', '' === $t && ! $w->sql_matching( 'hdlv2_widget_invites' ) );

echo "── UG5. wiring ──\n";
$g = (string) @file_get_contents( $ROOT . 'includes/security/class-hdlv2-unproven-guard.php' );
check( 'UG5.1 the filters and the priority-0 action are registered', false !== strpos( $g, "add_filter( 'wp_pre_insert_user_data', array( __CLASS__, 'keep_email' ), 10, 3 )" )
    && false !== strpos( $g, "add_filter( 'send_email_change_email', array( __CLASS__, 'no_change_mail' ), 10, 2 )" )
    && false !== strpos( $g, "add_action( 'wp_ajax_health_tracker_switch_practitioner', array( __CLASS__, 'refuse_switch' ), 0 )" ) );
$loader = (string) file_get_contents( $ROOT . 'hdl-longevity-v2.php' );
$req = strpos( $loader, "require_once HDLV2_PLUGIN_DIR . 'includes/security/class-hdlv2-unproven-guard.php';" );
check( 'UG5.2 the loader requires the guard after the ticket class and registers its hooks', false !== $req && $req > strpos( $loader, "includes/security/class-hdl-stage1-ticket.php';" ) && false !== strpos( $loader, 'HDLV2_Unproven_Guard::register_hooks();' ) );
$log = (string) file_get_contents( $LOG );
check( 'UG5.3 a refusal logs a reason code only', (bool) preg_match( '/\[HDL full-report-open\] guard switch$/m', $log ) && false === strpos( $log, 'example.test' ) );
@unlink( $LOG );
done();
