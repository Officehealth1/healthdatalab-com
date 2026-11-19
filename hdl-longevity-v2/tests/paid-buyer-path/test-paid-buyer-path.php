<?php
/**
 * The paid buyer's way in (plan 2026-11-17, rows M42 and M44; gate fixes G1 and
 * G2 the same day). Standalone, no WP, no DB, no network:
 *   php tests/paid-buyer-path/test-paid-buyer-path.php
 *
 * (a) G1: a used Full Report link still in date signs its owner in and opens the
 *     form, as the e-mail's "works until" says; out of date, the expired card;
 * (b) an unknown link keeps the old card;
 * (c) a used paid Stage 1 ticket and a used timeline link behave as before;
 * (d) G2: a Full Report link opened in a browser signed in to another account,
 *     client or practitioner, shows a card whose only way on is WordPress's
 *     sign-out (nonce) back to the same link on our host; no GET ends a
 *     session. The ticket's own account goes to the form; dead tickets, other
 *     links, REST calls and bad tokens are untouched.
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

$pass = 0;
$fail = 0;
function ok( $name, $cond ) {
    global $pass, $fail;
    echo ( $cond ? 'PASS' : 'FAIL' ) . " | $name\n";
    $cond ? $pass++ : $fail++;
}
function run_case( $case ) {
    exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/scenario-invite.php' ) . ' ' . escapeshellarg( $case ) . ' 2>&1', $out );
    return implode( "\n", $out );
}
function has( $o, $s ) { return false !== strpos( $o, $s ); }

// (a) G1
$o = run_case( 'used-full-report' );
ok( 'used link in date: the ticket\'s account is signed in', has( $o, 'AUTH_COOKIE_SET:7' ) );
ok( 'used link in date: straight to the form', has( $o, 'REDIRECT:https://site.test/assessment/?token=fptoken' ) );
ok( 'used link in date: no card at all', ! has( $o, 'STATUS:' ) && ! has( $o, '<title>' ) );
ok( 'used link in date: the ticket is not written back', ! has( $o, 'DB_UPDATE' ) );

$o = run_case( 'used-full-report-expired' );
ok( 'used link out of date: the expired card', has( $o, '<title>This invitation has expired</title>' ) && has( $o, 'STATUS:410' ) );
ok( 'used link out of date: nobody signed in', ! has( $o, 'AUTH_COOKIE_SET' ) && ! has( $o, 'REDIRECT:' ) );

foreach ( array( 'used-full-report', 'used-full-report-expired', 'unknown-token' ) as $c ) {
    ok( "$c: the old \"already used\" card is gone", ! has( run_case( $c ), 'This link has already been used' ) );
}

// (b)
$o = run_case( 'unknown-token' );
ok( 'unknown link: the old "no longer valid" card, nobody signed in', has( $o, 'This invitation is no longer valid' ) && ! has( $o, 'AUTH_COOKIE_SET' ) );

// (c)
foreach ( array( 'used-paid-stage1', 'used-timeline-link' ) as $c ) {
    $o = run_case( $c );
    ok( "$c: the page renders for the widget (no card, no sign-in)", has( $o, 'HANDLER_RETURNED' ) && ! has( $o, 'STATUS:' ) && ! has( $o, 'AUTH_COOKIE_SET' ) );
}

// (d) G2
$back = 'https://site.test/wp-login.php?action=logout&redirect_to=' . urlencode( 'https://site.test/rate-of-ageing-widget/?invite=' . str_repeat( 'cd', 32 ) ) . '&_wpnonce=LOGOUTNONCE';
foreach ( array( 'signed-in-other-client', 'signed-in-staff', 'signed-in-other-used' ) as $c ) {
    $o = run_case( $c );
    ok( "$c: the card \"You are signed in to another account\"", has( $o, '<title>You are signed in to another account</title>' ) && has( $o, 'This link belongs to a different account. Sign out to open it.' ) );
    ok( "$c: one button, WordPress's sign-out with its nonce, back to the same link", has( $o, 'href="' . $back . '"' ) && has( $o, 'Sign out and open the link &rarr;' ) );
    ok( "$c: no session ended, no cookie cleared or set, no redirect", ! has( $o, 'SESSION_ENDED' ) && ! has( $o, 'COOKIE_CLEARED' ) && ! has( $o, 'AUTH_COOKIE_SET' ) && ! has( $o, 'REDIRECT:' ) );
}

$o = run_case( 'signed-in-other-odd-path' );
ok( 'crafted path: the button returns to our host only', has( $o, 'redirect_to=' . urlencode( 'https://site.test/x/?invite=' . str_repeat( 'cd', 32 ) ) . '&' ) && ! has( $o, 'evil.test' ) );

foreach ( array( 'signed-in-same-client', 'signed-in-same-used' ) as $c ) {
    $o = run_case( $c );
    ok( "$c: the ticket's own account goes straight to the form, no card", has( $o, 'REDIRECT:https://site.test/assessment/?token=fptoken' ) && ! has( $o, 'STATUS:' ) && ! has( $o, 'SESSION_ENDED' ) );
}

foreach ( array( 'signed-in-other-link', 'signed-in-dead-ticket', 'signed-in-used-expired', 'signed-in-revoked', 'signed-in-rest', 'signed-in-bad-token' ) as $c ) {
    $o = run_case( $c );
    ok( "$c: unchanged (session kept, no card, no sign-in, no redirect)", has( $o, 'HANDLER_RETURNED' ) && ! has( $o, 'STATUS:' ) && ! has( $o, 'SESSION_ENDED' ) && ! has( $o, 'AUTH_COOKIE_SET' ) && ! has( $o, 'REDIRECT:' ) );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
