<?php
/**
 * P2 (action-button unify) — /dashboard/clients must carry the P1b resend
 * descriptor per row, so the client-list enhancer can render the stage-aware
 * paper-plane (label, tooltip, disabled state) WITHOUT duplicating the
 * bucketing logic in JS. Single source of truth = resend_link_descriptor().
 *
 * Also pins the Phase-0 identifier contract the chat button depends on:
 * the roster's email_hash MUST equal sha256(strtolower(trim(email))) — the
 * exact expression ajax_send_client_message's mismatch gate recomputes. If
 * these ever diverge, every V2-fed chat send 403s ("Client identifier
 * mismatch").
 *
 * Standalone: fake wpdb + WP stubs, Testable subclass pins calculate_status
 * (rest_get_clients must call it via static:: — same LSB pattern as
 * rest_resend_link).
 *
 * Run:  php tests/action-unify/scenario-roster-resend.php   (exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['scn'] = array(
    'status'   => 'not_started',
    // GAP-1 (2026-07-13) made the SEND descriptor flag-blind: it derives from
    // resolve_resend_status() (real funnel tables), not from the pinned
    // display status. The fake wpdb below is therefore driven by 'funnel':
    //   'not_started'      → no form_progress row
    //   'awaiting_consult' → stage 1+3 complete, NO report row
    //   'complete'         → stage 1+3 complete + report_generated row
    'funnel'   => 'not_started',
    'has_plan' => false,
    // Deliberately unnormalised — the roster must hash the normalised form.
    'email'    => '  Sam.Client@Example.COM ',
);

// ── WP stubs ──
function add_action() {}
function register_rest_route() {}
function get_current_user_id() { return 20; }
function absint( $v ) { return abs( (int) $v ); }
function rest_ensure_response( $x ) { return $x; }
function get_user_meta( $id, $k, $single = false ) { return ''; }
function get_userdata( $id ) {
    return (object) array(
        'ID'           => $id,
        'user_email'   => $GLOBALS['scn']['email'],
        'user_login'   => 'u' . $id,
        'display_name' => 'Sam Client',
    );
}

class HDLV2_Compatibility {
    public static function get_clients_for_practitioner( $p ) { return array( 77 ); }
    public static function is_practitioner( $u ) { return true; }
    public static function practitioner_owns_client( $p, $c ) { return true; }
    public static function practitioner_owns_client_including_deleted( $p, $c ) { return true; }
}

class FakeWpdb {
    public $prefix = 'wp_';
    public function prepare( $q ) { return $q; }
    public function get_var( $q ) {
        if ( strpos( $q, 'hdlv2_flight_plans' ) !== false ) { return $GLOBALS['scn']['has_plan'] ? 5 : null; }
        if ( strpos( $q, 'hdlv2_consultation_notes' ) !== false ) {
            // report_generated row exists only when the funnel is complete
            return ( 'complete' === $GLOBALS['scn']['funnel'] ) ? 7 : null;
        }
        return null; // no check-ins
    }
    public function get_row( $q ) {
        if ( strpos( $q, 'hdlv2_form_progress' ) !== false ) {
            if ( 'not_started' === $GLOBALS['scn']['funnel'] ) {
                return null; // client never opened Stage 1
            }
            // awaiting_consult + complete: Stage 3 finished; report presence
            // differs via the consultation_notes get_var above.
            return (object) array(
                'id'                  => 9,
                'stage1_completed_at' => '2026-07-01 10:00:00',
                'stage2_completed_at' => '2026-07-02 10:00:00',
                'stage3_completed_at' => '2026-07-03 10:00:00',
            );
        }
        return null; // no why_profile row
    }
}
$GLOBALS['wpdb'] = new FakeWpdb();

require __DIR__ . '/../../includes/sprint-4/class-hdlv2-client-status.php';

// Testable: pin calculate_status (rest_get_clients must call it via static::).
class Testable_Client_Status extends HDLV2_Client_Status {
    public function __construct() {}
    public static function calculate_status( $client_id ) {
        return array(
            'status'  => $GLOBALS['scn']['status'],
            'label'   => 'L',
            'color'   => '#000000',
            'reasons' => array(),
        );
    }
}
$svc = new Testable_Client_Status();

$pass = 0; $fail = 0;
function ok( $name, $cond, $detail = '' ) {
    global $pass, $fail;
    echo ( $cond ? 'PASS' : 'FAIL' ) . " | $name" . ( $cond ? '' : " | $detail" ) . "\n";
    $cond ? $pass++ : $fail++;
}
function roster_row( $svc ) {
    $out = $svc->rest_get_clients( array() );
    return is_array( $out ) && isset( $out[0] ) ? $out[0] : null;
}

// ── (1) enabled assessment stage: descriptor present + matches the pure fn ──
$GLOBALS['scn']['status'] = 'not_started'; $GLOBALS['scn']['funnel'] = 'not_started'; $GLOBALS['scn']['has_plan'] = false;
$row = roster_row( $svc );
ok( '(1a) row carries a resend descriptor', is_array( $row ) && isset( $row['resend'] ) && is_array( $row['resend'] ),
    is_array( $row ) ? implode( ',', array_keys( $row ) ) : gettype( $row ) );
ok( '(1b) not_started -> enabled Stage-1 "Send assessment link"',
    isset( $row['resend'] ) && $row['resend']['enabled'] === true && $row['resend']['stage'] === 1
        && $row['resend']['label'] === 'Send assessment link',
    isset( $row['resend'] ) ? json_encode( $row['resend'] ) : 'no descriptor' );

// ── (2) blocked-on-practitioner: disabled + reason tooltip ──
$GLOBALS['scn']['status'] = 'awaiting_consult'; $GLOBALS['scn']['funnel'] = 'awaiting_consult';
$row = roster_row( $svc );
ok( '(2) awaiting_consult -> disabled with the P1b tooltip',
    isset( $row['resend'] ) && $row['resend']['enabled'] === false
        && $row['resend']['tooltip'] === 'Waiting on your consultation — nothing to send',
    isset( $row['resend'] ) ? json_encode( $row['resend'] ) : 'no descriptor' );

// ── (3) COMPLETE names the actual artefact (D2): plan when one exists… ──
$GLOBALS['scn']['status'] = 'active'; $GLOBALS['scn']['funnel'] = 'complete'; $GLOBALS['scn']['has_plan'] = true;
$row = roster_row( $svc );
ok( '(3) COMPLETE + live plan -> "Resend flight plan" (link_kind=plan)',
    isset( $row['resend'] ) && $row['resend']['enabled'] === true
        && $row['resend']['link_kind'] === 'plan' && $row['resend']['label'] === 'Resend flight plan',
    isset( $row['resend'] ) ? json_encode( $row['resend'] ) : 'no descriptor' );

// ── (4) …and report when none does ──
$GLOBALS['scn']['has_plan'] = false;
$row = roster_row( $svc );
ok( '(4) COMPLETE + no plan -> "Resend report" (link_kind=report)',
    isset( $row['resend'] ) && $row['resend']['enabled'] === true
        && $row['resend']['link_kind'] === 'report' && $row['resend']['label'] === 'Resend report',
    isset( $row['resend'] ) ? json_encode( $row['resend'] ) : 'no descriptor' );

// ── (5) Phase-0 identifier contract: email_hash == sha256(lower(trim(email))) ──
// This is the exact expression the messaging mismatch gate recomputes
// (class-messaging-service.php) — the V2-fed chat send 403s if it drifts.
$expected_hash = hash( 'sha256', strtolower( trim( $GLOBALS['scn']['email'] ) ) );
ok( '(5) email_hash equals sha256(strtolower(trim(email)))',
    isset( $row['email_hash'] ) && $row['email_hash'] === $expected_hash
        && isset( $row['email'] ) && $row['email'] === $GLOBALS['scn']['email'],
    isset( $row['email_hash'] ) ? $row['email_hash'] . ' vs ' . $expected_hash : 'no hash' );

// ── (6) P1a regression: user_login still exposed alongside the new key ──
ok( '(6) user_login still present (P1a)', isset( $row['user_login'] ) && $row['user_login'] === 'u77',
    isset( $row['user_login'] ) ? $row['user_login'] : 'missing' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
