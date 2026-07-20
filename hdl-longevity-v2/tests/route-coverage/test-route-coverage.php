<?php
/**
 * Route-coverage guard (2026-07-19) — LIVE verification finding #3.
 *
 * Asserts that EVERY registered hdl-v2/v1 REST route resolves to a non-null
 * rate-limit tier via HDLV2_Rate_Limit_Policy::tier_for_request(). A null tier
 * means NO per-caller limit for an identified (logged-in / token) caller — the
 * IP backstop only covers anonymous traffic — which is exactly how 14 routes
 * went unlimited on LIVE.
 *
 * This turns the policy's "single source of truth" comment into something the
 * build enforces: add a route without a tier (or an explicit bypass) and this
 * test fails. Standalone — no WP, no DB.
 *
 *   Run:  php tests/route-coverage/test-route-coverage.php
 *   Exit: 0 = every route covered · 1 = at least one unmapped route
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

if ( ! defined( 'ABSPATH' ) )            define( 'ABSPATH', '/tmp/' );
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }

$PLUGIN = dirname( __DIR__, 2 );
require_once $PLUGIN . '/includes/security/class-hdlv2-rate-limit-policy.php';

/*
 * Documented allow-list — hdl-v2/v1 routes intentionally NOT tier-mapped.
 * Every entry needs a reason. Everything else must resolve to a tier
 * (including an explicit TIER_BYPASS for deliberate high-frequency polls).
 */
$ALLOW = array(
    // Iridology add-on: gated behind get_option('hdlv2_ff_iris_addon', false).
    // The routes are not even registered at runtime while the flag is off, so
    // they cannot be reached. Map them when the add-on goes GA.
    // (/iris/clients is already mapped READ and is not in this list.)
    '#^/hdl-v2/v1/iris/(checkout|status|login|analysis-status|areas-edit|analyse/callback)$#',
);

// ── 1. Discover every register_rest_route() in the plugin source ──
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator( $PLUGIN . '/includes', FilesystemIterator::SKIP_DOTS )
);
$routes = array();
foreach ( $it as $f ) {
    if ( strtolower( $f->getExtension() ) !== 'php' ) continue;
    $src = file_get_contents( $f->getPathname() );
    if ( strpos( $src, 'register_rest_route' ) === false ) continue;

    // namespace can be a literal ('hdl-v2/v1' / 'hdl/v1') OR a variable ($ns).
    if ( ! preg_match_all( "/register_rest_route\\(\\s*([^,]+),\\s*'([^']+)'\\s*,(.*?)\\);/s", $src, $m, PREG_SET_ORDER ) ) continue;

    foreach ( $m as $mm ) {
        $ns_raw = trim( $mm[1] );
        $route  = $mm[2];
        $body   = $mm[3];

        // If the namespace is a quoted literal, honour it. hdl/v1 (the
        // consumer provisioner) is a different namespace the V2 middleware
        // does NOT govern — skip it. A variable ($ns) is only ever hdl-v2/v1
        // in this codebase, so treat it as in-scope.
        if ( $ns_raw !== '' && ( $ns_raw[0] === "'" || $ns_raw[0] === '"' ) ) {
            $ns = trim( $ns_raw, "'\"" );
            if ( $ns !== 'hdl-v2/v1' ) continue;
        }

        // methods — string literals and WP_REST_Server constants
        $methods = array();
        if ( preg_match_all( "/'methods'\\s*=>\\s*([^,\\n\\)]+)/", $body, $mem ) ) {
            foreach ( $mem[1] as $raw ) {
                $raw = str_replace( array( 'WP_REST_Server::', 'METHOD_' ), '', $raw );
                foreach ( explode( '|', $raw ) as $tok ) {
                    $tok = strtoupper( trim( $tok, " '\"\t" ) );
                    $const = array( 'READABLE' => 'GET', 'CREATABLE' => 'POST', 'EDITABLE' => 'POST', 'DELETABLE' => 'DELETE' );
                    if ( isset( $const[ $tok ] ) ) $tok = $const[ $tok ];
                    if ( in_array( $tok, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) $methods[] = $tok;
                }
            }
        }
        if ( ! $methods ) $methods = array( 'GET' );

        // normalise named groups to a numeric sample the policy regexes accept
        $concrete = '/hdl-v2/v1/' . ltrim( preg_replace( '/\\(\\?P<[^>]+>[^)]*\\)/', '1', $route ), '/' );

        foreach ( array_unique( $methods ) as $method ) {
            $routes[ $method . ' ' . $concrete ] = array( $method, $concrete );
        }
    }
}
ksort( $routes );

// ── 2. Assert each resolves to a tier (or is explicitly allow-listed) ──
$ok = 0; $skip = 0; $fail = 0;
foreach ( $routes as $key => $pair ) {
    list( $method, $route ) = $pair;
    $tier = HDLV2_Rate_Limit_Policy::tier_for_request( $method, $route );
    if ( $tier !== null ) { echo "PASS  $key -> $tier\n"; $ok++; continue; }

    $allowed = false;
    foreach ( $ALLOW as $rx ) { if ( preg_match( $rx, $route ) ) { $allowed = true; break; } }
    if ( $allowed ) { echo "SKIP  $key (allow-listed: flag-gated)\n"; $skip++; continue; }

    echo "FAIL  $key -> NULL TIER (unmapped — no per-caller limit)\n"; $fail++;
}

echo "\n" . count( $routes ) . " hdl-v2/v1 routes · $ok mapped · $skip allow-listed · $fail unmapped\n";
echo ( $fail ? "ROUTE COVERAGE: FAIL\n" : "ROUTE COVERAGE: PASS\n" );
exit( $fail ? 1 : 0 );
