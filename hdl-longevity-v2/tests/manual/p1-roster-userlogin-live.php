<?php
/**
 * P1a — /dashboard/clients roster payload must expose user_login.
 *
 * LIVE red→green check for the "View Profile" enabler: the V2 roster row needs
 * a WP user_login so the dashboard can resolve /user/{login}/ for V2-only rows.
 * Runs the REAL HDLV2_Client_Status::rest_get_clients() under a practitioner
 * session against the live DB.
 *
 *   RED  (before deploy): every row is MISSING 'user_login'  -> FAIL
 *   GREEN (after deploy) : every row has 'user_login' == get_userdata(uid)->user_login -> PASS
 *
 * Read-only: rest_get_clients performs SELECTs only, writes nothing. No cleanup
 * needed. Safe on a LIVE clone via HDL_TEST_PRAC (default 206 = STBY bob9000);
 * pass a practitioner that owns ≥1 V2 client.
 *
 * Run (STBY):  wp eval-file tests/manual/p1-roster-userlogin-live.php --allow-root
 * Exit 0 = PASS, 1 = FAIL.
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "must run under wp eval-file\n" ); exit( 1 ); }

$prac_id = (int) ( getenv( 'HDL_TEST_PRAC' ) ?: 206 );
wp_set_current_user( $prac_id );

if ( ! class_exists( 'HDLV2_Client_Status' ) ) { echo "FAIL | HDLV2_Client_Status not loaded\n"; exit( 1 ); }

$status = HDLV2_Client_Status::get_instance();
$resp   = $status->rest_get_clients( new WP_REST_Request( 'GET', '/hdl-v2/v1/dashboard/clients' ) );
$rows   = is_object( $resp ) && method_exists( $resp, 'get_data' ) ? $resp->get_data() : $resp;

$pass = 0; $fail = 0;
$check = function ( $name, $ok, $detail ) use ( &$pass, &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . " | $name | $detail\n";
	$ok ? $pass++ : $fail++;
};

$check( 'roster returns at least one client for the test practitioner', is_array( $rows ) && count( $rows ) > 0, 'count=' . ( is_array( $rows ) ? count( $rows ) : 'n/a' ) . " (uid=$prac_id)" );

if ( is_array( $rows ) ) {
	$missing = 0; $mismatch = 0; $sample = '';
	foreach ( $rows as $r ) {
		if ( ! array_key_exists( 'user_login', $r ) ) { $missing++; continue; }
		$u = get_userdata( (int) $r['user_id'] );
		$expected = $u ? $u->user_login : '';
		if ( (string) $r['user_login'] !== (string) $expected ) { $mismatch++; }
		if ( $sample === '' ) { $sample = "uid={$r['user_id']} login=\"{$r['user_login']}\""; }
	}
	$check( 'every roster row exposes user_login', $missing === 0, "missing=$missing of " . count( $rows ) );
	$check( 'user_login matches get_userdata()->user_login', $mismatch === 0, "mismatch=$mismatch; sample: $sample" );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
