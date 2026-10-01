<?php
/**
 * Paid Stage 1 widget mode test suite orchestrator (v0.47.85).
 *
 * Standalone, no WP, no DB, no network. Run from anywhere:
 *   php tests/paid-stage1/test-paid-stage1.php
 * The widget script has its own runner:
 *   node tests/paid-stage1/test-widget-access.js
 *   node tests/paid-stage1/test-send-stage1-link.js
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

$php  = escapeshellarg( PHP_BINARY );
$fail = 0;
$runs = array(
    'scenario-paid-stage1.php'       => '',
    'scenario-paid-stage1.php '      => 'nokey', // mint route with no key constant
    'scenario-migration.php'         => '',
);
foreach ( $runs as $file => $arg ) {
    $out = array();
    exec( $php . ' ' . escapeshellarg( __DIR__ . '/' . trim( $file ) ) . ' ' . $arg . ' 2>/dev/null', $out, $code );
    echo implode( "\n", $out ) . "\n";
    if ( 0 !== $code ) $fail = 1;
}

// 13.6/13.7 — the ?invite= auto-login on the WordPress host. Reuses the
// token-expiry harness, which runs the REAL init handler one case per process.
echo "── 13b. ?invite= auto-login ignores a paid ticket ──\n";
$out = array();
exec( $php . ' ' . escapeshellarg( dirname( __DIR__ ) . '/token-expiry/scenario-token-login.php' ) . ' invite-paid-ticket 2>/dev/null', $out, $code );
$o = implode( "\n", $out );
foreach ( array(
    '13.6 no login for a paid ticket'            => 0 === $code && false === strpos( $o, 'AUTH_COOKIE_SET' ),
    '13.7 handler returns, page renders as usual' => false !== strpos( $o, 'HANDLER_RETURNED' ) && false === strpos( $o, 'DB_UPDATE' ) && false === strpos( $o, 'USER_CREATED' ),
) as $label => $cond ) {
    echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $label . "\n";
    if ( ! $cond ) $fail = 1;
}

$out = array();
exec( $php . ' ' . escapeshellarg( dirname( __DIR__ ) . '/token-expiry/scenario-token-login.php' ) . ' invite-paid-ticket-used 2>/dev/null', $out, $code );
$o    = implode( "\n", $out );
$cond = false !== strpos( $o, 'HANDLER_RETURNED' ) && false === strpos( $o, 'no longer valid' ) && false === strpos( $o, 'AUTH_COOKIE_SET' );
echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . "13.8 a used paid ticket gets no invitation card and no login (the widget explains it)\n";
if ( ! $cond ) $fail = 1;

echo ( 0 === $fail ? "SUITE: PASS\n" : "SUITE: FAIL\n" );
exit( $fail );
