<?php
/**
 * 30-day challenge — POST /hdl/v1/stage1-ticket with product "challenge",
 * and Stage 1 answers sent with a challenge link. Against the REAL route,
 * mint() and rest_capture_lead(), on the full-report harness (its stubs and
 * fake $wpdb).
 *
 * Run:  php scenario-ticket.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require dirname( __DIR__ ) . '/full-report/stubs.php';
define( 'HDL_STAGE1_TICKET_KEY', 'k-test-key' );
load_sources();
require_once dirname( __DIR__, 2 ) . '/includes/sprint-4/class-hdlv2-challenge.php';
$LOG = tempnam( sys_get_temp_dir(), 'hdlv2-ch' );
ini_set( 'error_log', $LOG );
register_shutdown_function( function () use ( $LOG ) { @unlink( $LOG ); } );

function setup( $mode = 'open', $db = '3.31' ) {
    global $wpdb;
    fresh_wpdb( $mode );
    $wpdb->config->practitioner_user_id = 122;
    $GLOBALS['options']     = array( 'hdlv2_db_version' => $db );
    $GLOBALS['email_users'] = array();
    $GLOBALS['usermeta']    = array();
    return $wpdb;
}
function mint_req( $over = array() ) {
    $body = array_merge( array( 'practitioner_id' => 122, 'email' => 'ch1@example.test', 'name' => 'Ch One', 'external_ref' => 'cs_test_ch1', 'product' => 'challenge' ), $over );
    $body = array_filter( $body, function ( $v ) { return null !== $v; } );
    return HDL_Stage1_Ticket::handle( new FakeRequest( $body, array( 'X-HDL-Stage1-Ticket-Key' => 'k-test-key' ) ) );
}
function ticket_rows( $w ) { return $w->inserts_into( 'hdlv2_widget_invites' ); }

echo "── C1. product challenge ──\n";
$w = setup();
$r = mint_req();
$row = ticket_rows( $w )[0]['data'] ?? array();
check( 'C1.1 200 with exactly { token, expires_at, idempotent, open_url, already_enrolled }', is_array( $r ) && array_keys( $r ) === array( 'token', 'expires_at', 'idempotent', 'open_url', 'already_enrolled' ) && false === $r['idempotent'] );
check( 'C1.1b a first purchase: already_enrolled false', is_array( $r ) && false === $r['already_enrolled'] );
check( 'C1.2 open_url opens the widget page with the ticket', is_array( $r ) && 'https://stby.example.test/rate-of-ageing-widget/?invite=' . $r['token'] === $r['open_url'] );
check( 'C1.3 the row: source challenge, the ref, 90 days, pending', 'challenge' === ( $row['source'] ?? '' ) && 'cs_test_ch1' === ( $row['external_ref'] ?? '' ) && 'pending' === ( $row['status'] ?? '' )
    && abs( strtotime( $row['expires_at'] . ' UTC' ) - ( time() + 90 * DAY_IN_SECONDS ) ) < 60 );
check( 'C1.4 no lead confirmed, no client record, no email, no webhook', 1 === count( $w->inserts ) && ! $w->updates && ! $GLOBALS['mails'] && ! $GLOBALS['captured_posts'] );
$r2 = mint_req();
check( 'C1.5 the same external_ref again → same token, idempotent true', is_array( $r2 ) && $r['token'] === $r2['token'] && true === $r2['idempotent'] && 1 === count( ticket_rows( $w ) ) );
$w = setup( 'paid' );
$r = mint_req();
check( 'C1.6 a paid-mode practitioner can sell a challenge too', is_array( $r ) && ! empty( $r['token'] ) );
$w = setup( 'open', '3.30' );
$r = mint_req();
check( 'C1.7 before Phase AL (DB < 3.31) → 503 not_ready, nothing written', 503 === status_of( $r ) && 'not_ready' === code_of( $r ) && ! $w->inserts );
$w = setup();
$r = mint_req( array( 'product' => 'nonsense' ) );
check( 'C1.8 product nonsense → 400 invalid_body naming product', 400 === status_of( $r ) && in_array( 'product', $r->data['invalid_fields'] ?? array(), true ) );
$w = setup();
$w->tickets['cs_test_ch1'] = (object) array( 'id' => 9, 'token' => str_repeat( 'cd', 32 ), 'expires_at' => '2030-01-01 00:00:00', 'practitioner_id' => 122, 'source' => 'full_report', 'client_email' => 'ch1@example.test', 'client_name' => 'Ch One' );
$r = mint_req();
check( 'C1.9 a Full Report ref reused for a challenge → 409 external_ref_conflict', 409 === status_of( $r ) && 'external_ref_conflict' === code_of( $r ) );

// v0.48.24 (F5) — a second purchase by an enrolled email is said, never acted on.
$w = setup();
$GLOBALS['email_users'] = array( 'ch1@example.test' => 505 );
$r = mint_req();
check( 'C1.10 an account that is not on the challenge → already_enrolled false', is_array( $r ) && false === $r['already_enrolled'] );
$GLOBALS['usermeta'][505]['hdlv2_challenge'] = json_encode( array( 'started_at' => '2026-10-01 08:00:00', 'practitioner_id' => 122, 'external_ref' => 'cs_old', 'invite_id' => 3, 'source' => 'stripe' ) );
file_put_contents( $LOG, '' );
$r = mint_req( array( 'external_ref' => 'cs_test_ch2', 'email' => 'CH1@example.test' ) );
$log = (string) file_get_contents( $LOG );
check( 'C1.11 an enrolled email (any case), new ref → a ticket, already_enrolled true', is_array( $r ) && ! empty( $r['token'] ) && true === $r['already_enrolled'] && false === $r['idempotent'] );
check( 'C1.12 …one log line naming the user id and the ref, never the email', false !== strpos( $log, '[HDLV2] challenge ticket for an enrolled email: user 505, ref cs_test_ch2' ) && false === stripos( $log, 'ch1@example.test' ) );
check( 'C1.13 …and nothing else written: the ticket row only, no enrolment change', 2 === count( ticket_rows( $w ) ) && false !== strpos( $GLOBALS['usermeta'][505]['hdlv2_challenge'], 'cs_old' ) && ! $GLOBALS['mails'] );

echo "── C2. stage1 and full_report answers unchanged ──\n";
$w = setup( 'paid' );
$r = mint_req( array( 'product' => null ) );
check( 'C2.1 product absent → { token, expires_at, idempotent }, source paid_stage1', is_array( $r ) && array_keys( $r ) === array( 'token', 'expires_at', 'idempotent' ) && 'paid_stage1' === ticket_rows( $w )[0]['data']['source'] );
$w = setup();
$r = mint_req( array( 'product' => 'full_report' ) );
check( 'C2.2 full_report → its six keys, source full_report', is_array( $r ) && array_keys( $r ) === array( 'token', 'expires_at', 'idempotent', 'open_url', 'stage1_found', 'auto_confirmed' ) && 'full_report' === ticket_rows( $w )[0]['data']['source'] );

echo "── C3. Stage 1 answers sent with a challenge link ──\n";
foreach ( array( 122 => 'its own practitioner', 206 => 'another practitioner' ) as $prac => $who ) {
    $w = setup();
    $w->invite = invite_row( array( 'id' => 91, 'practitioner_id' => $prac, 'source' => 'challenge', 'client_email' => 'ch1@example.test' ) );
    $p = lead_params( 'ch1@example.test', TOKEN );
    $p['practitioner_id'] = 122;
    $r = post_lead( $p );
    check( "C3.1 ($who) public path: a pending lead, no form_token, no client record", is_array( $r ) && empty( $r['form_token'] ) && 1 === count( $w->inserts_into( 'hdlv2_widget_leads' ) ) && 0 === count( $w->inserts_into( 'hdlv2_form_progress' ) ) );
    check( "C3.2 ($who) the challenge link is not used up", 'pending' === $w->invite->status && ! array_filter( $w->updates, function ( $u ) { return 'completed' === ( $u['data']['status'] ?? '' ); } ) );
}

$w = setup( 'paid' );
$w->invite = invite_row( array( 'id' => 92, 'practitioner_id' => 122, 'source' => 'challenge', 'client_email' => 'ch1@example.test' ) );
$p = lead_params( 'ch1@example.test', TOKEN );
$p['practitioner_id'] = 122;
$r = post_lead( $p );
check( 'C3.3 paid-mode seller: its own challenge link still takes Stage 1 (public path)', is_array( $r ) && empty( $r['form_token'] ) && 1 === count( $w->inserts_into( 'hdlv2_widget_leads' ) ) && 'pending' === $w->invite->status );
$w = setup( 'paid' );
$w->invite = invite_row( array( 'id' => 93, 'practitioner_id' => 206, 'source' => 'challenge', 'client_email' => 'ch1@example.test' ) );
$r = post_lead( $p );
check( 'C3.4 paid-mode seller: another practitioner\'s challenge link → 403, nothing saved', 403 === status_of( $r ) && ! $w->inserts );

$w = setup( 'paid' );
$w->invite = invite_row( array( 'id' => 94, 'practitioner_id' => 122, 'source' => 'challenge', 'client_email' => 'ch1@example.test' ) );
$p2 = lead_params( 'someone.else@example.test', TOKEN );
$p2['practitioner_id'] = 122;
$r = post_lead( $p2 );
check( 'C3.5 paid-mode seller: the challenge link only opens Stage 1 for its own email → 403, nothing saved', 403 === status_of( $r ) && 'email_mismatch' === code_of( $r ) && ! $w->inserts );
$p2['email'] = 'CH1@example.test';
$r = post_lead( $p2 );
check( 'C3.6 …the same email in any case is fine', is_array( $r ) && 1 === count( $w->inserts_into( 'hdlv2_widget_leads' ) ) );
$w = setup();
$w->invite = invite_row( array( 'id' => 95, 'practitioner_id' => 122, 'source' => 'challenge', 'client_email' => 'ch1@example.test' ) );
$p2['email'] = 'someone.else@example.test';
$r = post_lead( $p2 );
check( 'C3.7 open-mode seller: any email (the public path is open to all anyway)', is_array( $r ) && 1 === count( $w->inserts_into( 'hdlv2_widget_leads' ) ) );

// v0.48.24 (F8) — an empty or malformed email is a validation error first.
foreach ( array( '' => 'empty', 'not-an-email' => 'malformed' ) as $bad => $what ) {
    $w = setup( 'paid' );
    $w->invite = invite_row( array( 'id' => 96, 'practitioner_id' => 122, 'source' => 'challenge', 'client_email' => 'ch1@example.test' ) );
    $p3 = lead_params( $bad, TOKEN );
    $p3['practitioner_id'] = 122;
    $r = post_lead( $p3 );
    check( "C3.8 paid-mode challenge link, $what email → 400 invalid_email, nothing saved", 400 === status_of( $r ) && 'invalid_email' === code_of( $r ) && ! $w->inserts );
    $w = setup();
    $w->invite = invite_row( array( 'id' => 97, 'practitioner_id' => 122, 'source' => 'full_report', 'client_email' => 'ch1@example.test' ) );
    $r = post_lead( $p3 );
    check( "C3.9 Full Report link, $what email → 400 invalid_email, nothing saved", 400 === status_of( $r ) && 'invalid_email' === code_of( $r ) && ! $w->inserts );
}

echo "── C5. the buyer's rejected lead goes back to pending through the challenge link ──\n";
// The fake records SQL only; this applies the one statement that changes a
// lead's status (the guarded re-queue) to lead 321 as it stood before the post.
function lead_status_after( $w, $status ) {
    $requeue = $w->sql_matching( "hdlv2_widget_leads SET status = 'pending', rejected_at = NULL WHERE id = 321 AND status = 'rejected'" );
    $other   = array_filter( $w->updates, function ( $u ) { return 'wp_hdlv2_widget_leads' === $u['table'] && isset( $u['data']['status'] ); } );
    return $other ? 'changed elsewhere' : ( $requeue && 'rejected' === $status ? 'pending' : $status );
}
function post_lead_on_file( $mode, $status, $invite = null, $email = 'ch1@example.test' ) {
    $w = setup( $mode );
    $w->existing_lead_id = 321;
    $w->invite = $invite;
    $p = lead_params( $email, $invite ? TOKEN : '' );
    $p['practitioner_id'] = 122;
    $r = post_lead( $p );
    return array( $r, lead_status_after( $w, $status ) );
}
$own = function ( $over = array() ) { return invite_row( array_merge( array( 'id' => 98, 'practitioner_id' => 122, 'source' => 'challenge', 'client_email' => 'ch1@example.test' ), $over ) ); };
foreach ( array( 'open', 'paid' ) as $mode ) {
    list( $r, $st ) = post_lead_on_file( $mode, 'rejected', $own() );
    check( "C5.1 ($mode mode) the buyer's rejected lead + their challenge link → pending, rejected_at cleared", is_array( $r ) && 'pending' === $st );
}
list( $r, $st ) = post_lead_on_file( 'open', 'rejected' );
check( 'C5.2 the same answers with no link → stays rejected (a free visitor\'s rule holds)', is_array( $r ) && 'rejected' === $st );
list( $r, $st ) = post_lead_on_file( 'open', 'confirmed', $own() );
check( 'C5.3 a confirmed lead + the challenge link → stays confirmed', is_array( $r ) && 'confirmed' === $st );
list( $r, $st ) = post_lead_on_file( 'open', 'rejected', $own( array( 'practitioner_id' => 206 ) ) );
check( 'C5.4 another practitioner\'s challenge link → stays rejected', is_array( $r ) && 'rejected' === $st );
list( $r, $st ) = post_lead_on_file( 'open', 'rejected', $own(), 'someone.else@example.test' );
check( 'C5.5 open-mode seller, the link with another email → that email\'s rejected lead stays rejected', is_array( $r ) && 'rejected' === $st );
list( $r, $st ) = post_lead_on_file( 'open', 'rejected', $own(), 'CH1@example.test' );
check( 'C5.6 …the buyer\'s email in any case → pending', is_array( $r ) && 'pending' === $st );

echo "── C4. nothing else uses up or lists a challenge link ──\n";
$root = dirname( __DIR__, 2 );
$sf = file_get_contents( $root . '/includes/sprint-2/class-hdlv2-staged-form.php' );
$wc = file_get_contents( $root . '/includes/sprint-1/class-hdlv2-widget-config.php' );
check( 'C4.1 Stage 1 finished on the form does not complete a challenge link', 1 === substr_count( $sf, "AND source NOT IN ('paid_stage1','challenge')" ) );
check( 'C4.2 the practitioner\'s Sent Invites lists leave challenge links out (both)', 2 === substr_count( $wc, "WHERE practitioner_id = %d AND source NOT IN ('paid_stage1','challenge')" ) );
// v0.48.24 (F1, F3) — a challenge link is a sale, not a practitioner invite.
$cs = file_get_contents( $root . '/includes/sprint-4/class-hdlv2-client-status.php' );
$cd = file_get_contents( $root . '/includes/sprint-4/class-hdlv2-client-dashboard.php' );
check( 'C4.3 deleting a client never revokes their challenge link', 1 === substr_count( $cs, "AND status IN ('pending','opened')\n                   AND source NOT IN ('paid_stage1','challenge')" ) && 0 === substr_count( $cs, "<> 'paid_stage1'" ) );
check( 'C4.4 the dashboard "Start now" never carries a challenge link', 1 === substr_count( $cd, "AND source NOT IN ('paid_stage1','challenge')\n                   AND expires_at > %s" ) );
check( 'C4.5 …the "is a V2 client" lookup still counts a challenge buyer (the one bare filter left)', 1 === substr_count( $cd, "<> 'paid_stage1'" ) && 1 === substr_count( $cd, "WHERE client_email = %s AND source <> 'paid_stage1' LIMIT 1" ) );
check( 'C4.6 the practitioner\'s invite delete: same filter as its lists, no bare one left', 3 === substr_count( $wc, "source NOT IN ('paid_stage1','challenge')" ) && 0 === substr_count( $wc, "<> 'paid_stage1'" ) );

done();
