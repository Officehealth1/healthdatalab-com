<?php
/**
 * Token-expiry test suite orchestrator (B4 fix — V2 magic-link tokens).
 *
 * Standalone, no WP, no DB, no network. Run from anywhere:
 *   php tests/token-expiry/test-token-expiry.php
 *
 * Proves (per the fix spec):
 *  (a) an expired / never-expiring-legacy token is rejected and no auth
 *      cookie is set — init auto-login AND the token-authenticated REST
 *      surface (draft view; query-shape guards for the rest);
 *  (b) a fresh token logs in normally and slides its expiry forward;
 *  (c) the v3.25 migration backfills bounded expiries (90d from created_at,
 *      14d deploy-grace floor) behind a full-table backup, idempotently;
 *  (d) the legit flows still work: valid ?token=, valid ?invite=,
 *      ?prac_login= one-shot (unchanged 30-min transient).
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

$DIR    = __DIR__;
$PLUGIN = dirname( __DIR__, 2 );
$pass   = 0;
$fail   = 0;

function ok( $name, $cond ) {
    global $pass, $fail;
    echo ( $cond ? 'PASS' : 'FAIL' ) . " | $name\n";
    $cond ? $pass++ : $fail++;
}

function run_scenario( $file, $case = '' ) {
    $cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ( $case ? ' ' . escapeshellarg( $case ) : '' ) . ' 2>/dev/null';
    exec( $cmd, $out, $code );
    return array( implode( "\n", $out ), $code );
}

// ═══ 1. init auto-login scenarios (subprocess per case — card paths exit) ═══
$sc = $DIR . '/scenario-token-login.php';

// (a) expired / legacy tokens are rejected, no cookie
list( $o ) = run_scenario( $sc, 'null-expiry' );
ok( '?token= NULL expiry (legacy row) → expired card, no cookie',
    false !== strpos( $o, 'link has expired' ) && false === strpos( $o, 'AUTH_COOKIE_SET' ) );

list( $o ) = run_scenario( $sc, 'past-expiry' );
ok( '?token= past expiry → expired card, no cookie',
    false !== strpos( $o, 'link has expired' ) && false === strpos( $o, 'AUTH_COOKIE_SET' ) );

list( $o ) = run_scenario( $sc, 'invite-empty-expiry' );
ok( '?invite= empty expiry (legacy row) → expired card, no cookie',
    false !== strpos( $o, 'invitation has expired' ) && false === strpos( $o, 'AUTH_COOKIE_SET' ) );

list( $o ) = run_scenario( $sc, 'invite-expired' );
ok( '?invite= past expiry → expired card, no cookie',
    false !== strpos( $o, 'invitation has expired' ) && false === strpos( $o, 'AUTH_COOKIE_SET' ) );

// (b) fresh token logs in — and the window is FIXED: re-fetching the link
// must NOT extend it (review W3: an email link-scanner refetching the URL
// would otherwise keep a leaked link alive indefinitely)
list( $o ) = run_scenario( $sc, 'fresh' );
ok( '?token= fresh → auth cookie set for client 42',
    false !== strpos( $o, 'AUTH_COOKIE_SET:42' ) );
ok( '?token= fresh → login does NOT extend expiry (fixed 90d window)',
    false === strpos( $o, 'DB_UPDATE:wp_hdlv2_form_progress' ) );

// UTC-parse correctness: a token valid for 30 more minutes must still work
// on a non-UTC server (scenario pins Etc/GMT-1)
list( $o ) = run_scenario( $sc, 'near-expiry-valid' );
ok( '?token= valid 30 more min on UTC+1 server → still logs in',
    false !== strpos( $o, 'AUTH_COOKIE_SET:42' ) );

list( $o ) = run_scenario( $sc, 'invite-near-valid' );
ok( '?invite= valid 30 more min on UTC+1 server → still logs in',
    false !== strpos( $o, 'AUTH_COOKIE_SET:7' ) );

// (d) legit flows unchanged
list( $o ) = run_scenario( $sc, 'invite-fresh' );
ok( '?invite= fresh → auth cookie set (invite flow intact)',
    false !== strpos( $o, 'AUTH_COOKIE_SET:7' ) );

list( $o ) = run_scenario( $sc, 'not-found' );
ok( '?token= unknown → not-found card, no cookie',
    false !== strpos( $o, 'Link not found' ) && false === strpos( $o, 'AUTH_COOKIE_SET' ) );

list( $o ) = run_scenario( $sc, 'prac-login-fresh' );
ok( '?prac_login= fresh transient → practitioner logged in + redirected',
    false !== strpos( $o, 'AUTH_COOKIE_SET:3' ) && false !== strpos( $o, 'REDIRECT:' ) );

list( $o ) = run_scenario( $sc, 'prac-login-missing' );
ok( '?prac_login= consumed/expired transient → card, no cookie',
    false !== strpos( $o, 'already been used' ) && false === strpos( $o, 'AUTH_COOKIE_SET' ) );

// ═══ 2. Migration (c) ═══
list( $o, $code ) = run_scenario( $DIR . '/scenario-activator-migration.php' );
echo $o . "\n";
ok( 'migration scenario suite green', 0 === $code );

// ═══ 3. REST draft-view gate (a)+(d) with owner bypass ═══
list( $o, $code ) = run_scenario( $DIR . '/scenario-draft-view.php' );
echo $o . "\n";
ok( 'draft-view scenario suite green', 0 === $code );

// ═══ 4. Query-shape guards — token-authenticated REST/lookup surface ═══
// Every client-credential lookup must carry the fail-closed SQL clause;
// the two non-credential lookups must NOT (Make.com secret-authed PDF
// callback would lose late-arriving PDFs; practitioner breadcrumb is
// cookie-authed + ownership-checked).
$CLAUSE = 'token_expires_at > UTC_TIMESTAMP()';
$sites  = array(
    // file (relative to plugin root)                          min occurrences
    'includes/class-hdlv2-job-queue.php'                   => 1,
    'includes/sprint-4/class-hdlv2-timeline.php'           => 1,
    'includes/sprint-3/class-hdlv2-audio-service.php'      => 2,
    'includes/sprint-4/class-hdlv2-checkin.php'            => 1,
    'includes/sprint-2/class-hdlv2-staged-form.php'        => 1,
    'includes/sprint-5/class-hdlv2-flight-plan.php'        => 2,
);
foreach ( $sites as $rel => $min ) {
    $src = file_get_contents( $PLUGIN . '/' . $rel );
    ok( "enforce clause ×{$min} in {$rel}", substr_count( $src, $CLAUSE ) >= $min );
}

$pdf_src = file_get_contents( $PLUGIN . '/includes/sprint-2c/class-hdlv2-report-pdf.php' );
ok( 'report-pdf Make.com callback NOT expiry-gated (late PDFs must land)',
    false === strpos( $pdf_src, 'token_expires_at' ) );

// ═══ 5. Creation sites set the expiry; re-issue refreshes it ═══
$sf_src = file_get_contents( $PLUGIN . '/includes/sprint-2/class-hdlv2-staged-form.php' );
$wc_src = file_get_contents( $PLUGIN . '/includes/sprint-1/class-hdlv2-widget-config.php' );
ok( 'staged-form New Client INSERT sets token_expires_at',
    false !== strpos( $sf_src, "'token_expires_at'" ) );
ok( 'staged-form existing-client re-issue REFRESHES token_expires_at',
    substr_count( $sf_src, "'token_expires_at'" ) >= 2 );
ok( 'widget-config complete_signup INSERT sets token_expires_at',
    false !== strpos( $wc_src, "'token_expires_at'" ) );

// ═══ 6. Window constants + unchanged prac-login TTL ═══
$main_src = file_get_contents( $PLUGIN . '/hdl-longevity-v2.php' );
ok( 'client-token TTL constant defined (90 days)',
    false !== strpos( $main_src, "define( 'HDLV2_CLIENT_TOKEN_TTL_DAYS', 90 )" ) );
ok( '?prac_login= one-shot TTL unchanged (30 min transient)',
    false !== strpos( $sf_src, '30 * MINUTE_IN_SECONDS' ) );

echo "\n══════════════════════════════\nTOTAL: $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
