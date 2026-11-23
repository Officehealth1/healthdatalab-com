<?php
/**
 * Plan 12 of the $27 path: the report names live in one place (includes/class-hdlv2-report-names.php,
 * switch hdlv2_ff_report_names). No surface types a new name itself, and every report name of today
 * that is left on a screen, an e-mail or a message is the switch-off value of a call to the class:
 * HDLV2_Report_Names::say( key, today ) in PHP, rn( key, today ) in a script.
 *
 *   O.1  "Longevity Report" / "Comprehensive Report" appear in no surface but the class.
 *   O.2  every line that names a report today calls say() or rn() on that same line.
 *   O.3  each script that calls rn() reads report_names from its own localized config, and the PHP
 *        that localizes it passes that config through HDLV2_Report_Names::config().
 *
 * Left as they are, by the plan: the AI prompts (pinned, changed only with a recorded approval), the
 * free report's widget (its names are a later plan, with the Altituding side), comments, log lines,
 * identifiers (full_report, report_type 'final').
 *   O.4  the PHP constants that keep today's words are named where they are read.
 *
 * Run:  php scenario-names-one-place.php [plugin-root]   (self-asserting, exit 0/1)
 */
$ROOT = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) . '/' : __DIR__ . '/../../';
$PASS = 0; $FAIL = 0;
function check( $label, $cond ) {
    global $PASS, $FAIL;
    if ( $cond ) { $PASS++; echo "  PASS  $label\n"; } else { $FAIL++; echo "  FAIL  $label\n"; }
}

$CLASS = 'includes/class-hdlv2-report-names.php';
// script => array( its localized config, the PHP files that localize it )
$SCRIPTS = array(
    'assets/js/hdlv2-staged-form.js'           => array( 'hdlv2_form', array( 'includes/sprint-2/class-hdlv2-staged-form.php' ) ),
    'assets/js/hdlv2-draft-report.js'          => array( 'HDLV2_DRAFT_REPORT', array( 'includes/sprint-2c/class-hdlv2-client-draft-view.php', 'includes/sprint-2c/class-hdlv2-consultation.php' ) ),
    'assets/js/hdlv2-consultation.js'          => array( 'hdlv2_consult', array( 'includes/sprint-2c/class-hdlv2-consultation.php' ) ),
    'assets/js/hdlv2-client-list-enhance.js'   => array( 'hdlv2_client_enhance', array( 'includes/sprint-4/class-hdlv2-practitioner-dashboard.php' ) ),
    'assets/js/hdlv2-iris-consult.js'          => array( 'hdlv2_client_enhance', array( 'includes/sprint-4/class-hdlv2-practitioner-dashboard.php' ) ),
    'assets/js/hdlv2-practitioner-dashboard.js' => array( 'hdlv2_prac_dashboard', array( 'includes/sprint-4/class-hdlv2-practitioner-dashboard.php' ) ),
    'assets/js/hdlv2-healthspan-timeline.js'   => array( 'HDLV2_HT', array( 'includes/sprint-2c/class-hdlv2-healthspan-timeline.php' ) ),
);
$PHP = array(
    'includes/class-hdlv2-admin-automation-tier.php',
    'includes/class-hdlv2-compatibility.php',
    'includes/security/class-hdlv2-webhook-monitor.php',
    'includes/sprint-1/class-hdlv2-widget-config.php',
    'includes/sprint-2/class-hdlv2-email-templates.php',
    'includes/sprint-2/class-hdlv2-stage1-commentary.php',
    'includes/sprint-2/class-hdlv2-stage2-insight.php',
    'includes/sprint-2/class-hdlv2-staged-form.php',
    'includes/sprint-2c/class-hdlv2-auto-consultation.php',
    'includes/sprint-2c/class-hdlv2-client-draft-view.php',
    'includes/sprint-2c/class-hdlv2-final-report-pdf-service.php',
    'includes/sprint-2c/class-hdlv2-final-report.php',
    'includes/sprint-2c/class-hdlv2-flight-notes-service.php',
    'includes/sprint-2c/class-hdlv2-healthspan-timeline.php',
    'includes/sprint-2c/class-hdlv2-timeline-links.php',
    'includes/sprint-4/class-hdlv2-admin-restore.php',
    'includes/sprint-4/class-hdlv2-challenge.php',
    'includes/sprint-4/class-hdlv2-checkin.php',
    'includes/sprint-4/class-hdlv2-client-dashboard.php',
    'includes/sprint-4/class-hdlv2-client-status.php',
    'includes/sprint-4/class-hdlv2-practitioner-dashboard.php',
    'includes/sprint-4/class-hdlv2-products.php',
);
// Lines kept as they are, each with its reason. A PHP constant cannot call the class: those keep today's
// words and are named where they are read (O.4 checks the readers).
$KEPT = array(
    'includes/sprint-2c/class-hdlv2-auto-consultation.php' => array( 'Full Report form (deep assessment):' ), // feeds the AI
    'includes/sprint-2c/class-hdlv2-final-report.php'      => array( 'link-only Final Report email suppressed' ), // a log line
    'includes/sprint-2c/class-hdlv2-healthspan-timeline.php' => array( "'written' => 'Your Full Report is being written.", "'bought'  => 'Your Full Report is bought." ),
    'includes/sprint-4/class-hdlv2-products.php'           => array(
        "'Your personalised Trajectory Plan, as a PDF and on this page.'", "'It comes with your Full Report. A family pack",
        "const TL_UPGRADE = 'An upgrade to your Full Report'", "const TL_ABOUT   = 'An upgrade to your Full Report.",
        "array( 'Full Report', 'Trajectory Plan' ),", // what named() looks for
    ),
);

// A space, &nbsp; or \u00a0 between the words (the finish banner keeps its words on one line with &nbsp;).
$SP    = '(?:\s|&nbsp;|\\\\u00a0)+';
$TODAY = "/full{$SP}(?:longevity{$SP})?report|trajectory{$SP}plan|final{$SP}(?:longevity{$SP})?report|finali[sz]ed{$SP}longevity{$SP}report/i";
$NEW   = "/Longevity{$SP}Report|Comprehensive{$SP}Report/";

function readable( $root, $file ) {
    $out = array();
    if ( ! is_readable( $root . $file ) ) return null;
    foreach ( file( $root . $file ) as $n => $l ) {
        $t = ltrim( $l );
        if ( '' === $t || 0 === strpos( $t, '//' ) || 0 === strpos( $t, '*' ) || 0 === strpos( $t, '/*' ) || 0 === strpos( $t, '#' ) ) continue;
        if ( false !== strpos( $l, 'error_log(' ) || false !== strpos( $l, 'console.' ) ) continue;
        $l = preg_replace( '#\s//\s.*$#', '', $l );
        $l = preg_replace( '#/\*.*?\*/#', '', $l );
        $out[ $n + 1 ] = $l;
    }
    return $out;
}

$surfaces = array_merge( $PHP, array_keys( $SCRIPTS ) );
$new_left = array(); $today_left = array();
foreach ( $surfaces as $f ) {
    $lines = readable( $ROOT, $f );
    if ( null === $lines ) { $today_left[] = "$f  (file missing)"; continue; }
    foreach ( $lines as $n => $l ) {
        if ( preg_match( $NEW, $l ) ) $new_left[] = "$f:$n  " . substr( trim( $l ), 0, 110 );
        if ( ! preg_match( $TODAY, $l ) ) continue;
        foreach ( $KEPT[ $f ] ?? array() as $ok ) if ( false !== strpos( $l, $ok ) ) continue 2;
        $call = '.js' === substr( $f, -3 ) ? '/\brn\(/' : '/HDLV2_Report_Names::say\(/';
        if ( ! preg_match( $call, $l ) ) $today_left[] = "$f:$n  " . substr( trim( $l ), 0, 110 );
    }
}
echo "── O.1 a new name typed outside the class ──\n";
foreach ( $new_left as $h ) echo "        left: $h\n";
check( 'O.1 "Longevity Report" / "Comprehensive Report" only in the class (' . count( $new_left ) . ' left)', 0 === count( $new_left ) );
$cls = is_readable( $ROOT . $CLASS ) ? file_get_contents( $ROOT . $CLASS ) : '';
check( 'O.1 the class holds both names', 1 === substr_count( $cls, "'Longevity Report'" ) && 1 === substr_count( $cls, "'Comprehensive Report'" ) );

echo "── O.2 a report named today without the class ──\n";
foreach ( $today_left as $h ) echo "        left: $h\n";
check( 'O.2 every report name of today is a switch-off value of say() / rn() (' . count( $today_left ) . ' left)', 0 === count( $today_left ) );

echo "── O.3 the scripts read the names from their own config ──\n";
foreach ( $SCRIPTS as $js => list( $cfg, $phps ) ) {
    $src = (string) @file_get_contents( $ROOT . $js );
    $helper = preg_match( '/function rn\(\s*key,\s*today\s*\)\s*\{[^}]*' . preg_quote( $cfg, '/' ) . '[^}]*report_names/', $src );
    check( "O.3 $js: rn() reads $cfg.report_names", (bool) $helper );
    foreach ( $phps as $php ) {
        $p = (string) @file_get_contents( $ROOT . $php );
        $via = 'HDLV2_HT' === $cfg ? (bool) preg_match( '/\$data = HDLV2_Report_Names::config\( \$data \);/', $p )
            : (bool) preg_match( "/wp_localize_script\\( '[\\w-]+', '" . $cfg . "', HDLV2_Report_Names::config\\( array\\(/", $p );
        check( "O.3   $php passes $cfg through HDLV2_Report_Names::config()", $via );
    }
}

echo "── O.4 the constants are named where they are read ──\n";
$prod = (string) @file_get_contents( $ROOT . 'includes/sprint-4/class-hdlv2-products.php' );
check( 'O.4 products: the locked panel\'s lines and the Timeline upgrade note go through named()', false !== strpos( $prod, "array_map( array( __CLASS__, 'named' ), \$about )" ) && 2 === substr_count( $prod, 'self::named( self::TL_UPGRADE )' ) );
$ht = (string) @file_get_contents( $ROOT . 'includes/sprint-2c/class-hdlv2-healthspan-timeline.php' );
check( 'O.4 Healthspan Timeline: the waiting line is read through wait_line(), never straight from WAIT_LINES', 2 === substr_count( $ht, 'self::wait_line( $paid )' ) && 1 === substr_count( $ht, 'self::WAIT_LINES[' ) );

echo "\nPASS=$PASS FAIL=$FAIL\n";
exit( $FAIL === 0 ? 0 : 1 );
