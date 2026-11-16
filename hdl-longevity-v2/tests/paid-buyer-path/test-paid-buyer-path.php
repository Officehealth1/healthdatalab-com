<?php
/**
 * The paid buyer's way in (plan 2026-11-17, rows M42 and M44). Standalone, no WP,
 * no DB, no network:
 *   php tests/paid-buyer-path/test-paid-buyer-path.php
 *
 * (a) a used Full Report link says it was used, offers sign-in, signs nobody in;
 * (b) an unknown link keeps the old card;
 * (c) a used paid Stage 1 ticket and a used timeline link behave as before;
 * (d) walk case C4: a live Full Report link opened in a browser signed in as
 *     another client ends that session and signs the ticket's account in;
 *     staff sessions, other links, dead tickets and REST calls are untouched.
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

// (a)
$o = run_case( 'used-full-report' );
ok( 'used Full Report link: "This link has already been used"', has( $o, '<title>This link has already been used</title>' ) );
ok( 'used Full Report link: says where the report is and how to get in', has( $o, 'Your Full Report is in your account. Sign in with the e-mail you paid with.' ) );
ok( 'used Full Report link: a Sign in button to the sign-in page', has( $o, 'href="https://site.test/wp-login.php"' ) && has( $o, 'Sign in &rarr;' ) );
ok( 'used Full Report link: nobody is signed in', ! has( $o, 'AUTH_COOKIE_SET' ) && ! has( $o, 'HANDLER_RETURNED' ) );
ok( 'used Full Report link: not the old dead-end card', ! has( $o, 'no longer valid' ) && ! has( $o, 'ask your practitioner' ) );

// (b)
$o = run_case( 'unknown-token' );
ok( 'unknown link: the old "no longer valid" card, nobody signed in', has( $o, 'This invitation is no longer valid' ) && ! has( $o, 'AUTH_COOKIE_SET' ) && ! has( $o, 'This link has already been used' ) );

// (c)
foreach ( array( 'used-paid-stage1', 'used-timeline-link' ) as $c ) {
    $o = run_case( $c );
    ok( "$c: the page renders for the widget (no card, no sign-in)", has( $o, 'HANDLER_RETURNED' ) && ! has( $o, 'STATUS:' ) && ! has( $o, 'AUTH_COOKIE_SET' ) );
}

// (d) C4
$o = run_case( 'signed-in-other-client' );
ok( 'C4: another client\'s session is ended first', has( $o, 'SESSION_ENDED' ) && has( $o, 'COOKIE_CLEARED' ) );
ok( 'C4: the ticket\'s account is signed in, not the old one', has( $o, 'AUTH_COOKIE_SET:7' ) && ! has( $o, 'AUTH_COOKIE_SET:50' ) );
ok( 'C4: Stage 1 on file → straight to the form', has( $o, 'REDIRECT:https://site.test/assessment/?token=fptoken' ) );
ok( 'C4: the session ends before the new sign-in', strpos( $o, 'SESSION_ENDED' ) < strpos( $o, 'AUTH_COOKIE_SET:7' ) );

$o = run_case( 'signed-in-same-client' );
ok( 'signed in as the buyer already: no session ended, straight to the form', ! has( $o, 'SESSION_ENDED' ) && has( $o, 'REDIRECT:https://site.test/assessment/?token=fptoken' ) );

foreach ( array( 'signed-in-staff', 'signed-in-other-link', 'signed-in-dead-ticket', 'signed-in-rest', 'signed-in-bad-token' ) as $c ) {
    $o = run_case( $c );
    ok( "$c: unchanged (session kept, no sign-in, no redirect)", has( $o, 'HANDLER_RETURNED' ) && ! has( $o, 'SESSION_ENDED' ) && ! has( $o, 'AUTH_COOKIE_SET' ) && ! has( $o, 'REDIRECT:' ) );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
