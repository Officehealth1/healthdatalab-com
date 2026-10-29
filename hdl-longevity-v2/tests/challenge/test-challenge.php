<?php
/**
 * 30-day challenge suite orchestrator (v0.48.23). Standalone, no WP, no DB.
 *   php tests/challenge/test-challenge.php
 * Runs the four scenarios and the two node ones, then the challenge cases of the REAL ?invite=
 * handler through tests/token-expiry/scenario-token-login.php.
 */
$php  = escapeshellarg( PHP_BINARY );
$fail = 0;
foreach ( array( 'scenario-unlock.php', 'scenario-render.php', 'scenario-ticket.php', 'scenario-migration.php' ) as $f ) {
    $out = array();
    exec( $php . ' ' . escapeshellarg( __DIR__ . '/' . $f ) . ' 2>&1', $out, $code );
    echo "── $f: " . end( $out ) . "\n";
    if ( 0 !== $code ) { $fail = 1; echo implode( "\n", preg_grep( '/FAIL|Fatal|Error/', $out ) ) . "\n"; }
}
// v0.48.24 — the opening time in the buyer's clock runs the real JS under node;
// v0.48.27 — so does the day view's model.
foreach ( array( 'scenario-opens-at.js', 'scenario-dayview.js' ) as $f ) {
    $out = array();
    exec( 'node ' . escapeshellarg( __DIR__ . '/' . $f ) . ' 2>&1', $out, $code );
    echo "── $f: " . ( $out ? end( $out ) : 'node did not run' ) . "\n";
    if ( 0 !== $code ) { $fail = 1; echo implode( "\n", preg_grep( '/FAIL|Error|not found/', $out ) ) . "\n"; }
}

echo "── ?invite= with a challenge link (real init handler) ──\n";
$n = 0; $bad = 0;
$run = function ( $case ) use ( $php ) {
    $o = array();
    exec( $php . ' ' . escapeshellarg( dirname( __DIR__ ) . '/token-expiry/scenario-token-login.php' ) . ' ' . $case . ' 2>/dev/null', $o );
    return implode( "\n", $o );
};
$check = function ( $label, $cond ) use ( &$n, &$bad ) {
    $n++;
    if ( ! $cond ) $bad++;
    echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $label . "\n";
};
$dash = 'REDIRECT:https://stby.test/my-dashboard/#challenge';
$o = $run( 'invite-challenge-new' );
$check( 'I.1 nothing on file: signs in, opens the challenge, the widget page renders (Stage 1 first)', false !== strpos( $o, 'AUTH_COOKIE_SET:7' ) && false !== strpos( $o, 'OPEN_LINK:7:9' ) && false !== strpos( $o, 'HANDLER_RETURNED' ) && false === strpos( $o, 'REDIRECT' ) );
$check( 'I.2 the invite lookup is unchanged (pending/opened; nothing completes a challenge link)', false !== strpos( $o, "status IN ('pending','opened')" ) );
$check( 'I.3 the first open marks the link opened', false !== strpos( $o, 'DB_UPDATE:wp_hdlv2_widget_invites:{"status":"opened"' ) );
$o = $run( 'invite-challenge-lead' );
$check( 'I.4 Stage 1 on file → the dashboard\'s challenge', false !== strpos( $o, 'OPEN_LINK:7:9' ) && false !== strpos( $o, $dash ) );
$o = $run( 'invite-challenge-client' );
$check( 'I.5 a client record with Stage 1 → the dashboard, never /assessment/', false !== strpos( $o, $dash ) && false === strpos( $o, '/assessment/' ) );
$check( 'I.8 an account that already existed is never linked to the seller\'s practitioner', false === strpos( $o, 'PRAC_LINK' ) );
$o = $run( 'invite-challenge-newuser' );
$check( 'I.9 an account the challenge link made is linked to the seller\'s practitioner', false !== strpos( $o, 'PRAC_LINK:3:7' ) && false !== strpos( $o, 'OPEN_LINK:7:9' ) );
$o = $run( 'invite-full-report' );
$check( 'I.10 other links unchanged: a Full Report link still links its account', false !== strpos( $o, 'PRAC_LINK:3:7' ) );
$o = $run( 'invite-challenge-prac' );
$check( 'I.6 a practitioner\'s email → no login, no enrolment', false === strpos( $o, 'AUTH_COOKIE_SET' ) && false === strpos( $o, 'OPEN_LINK' ) );
$o = $run( 'invite-full-report' );
$check( 'I.7 a Full Report link still goes to /assessment/ and enrols nobody', false !== strpos( $o, 'REDIRECT:https://stby.test/assessment/?token=fptoken' ) && false === strpos( $o, 'OPEN_LINK' ) );
echo "PASS=" . ( $n - $bad ) . " FAIL=$bad\n";
if ( $bad ) $fail = 1;

echo ( 0 === $fail ? "SUITE: PASS\n" : "SUITE: FAIL\n" );
exit( $fail );
