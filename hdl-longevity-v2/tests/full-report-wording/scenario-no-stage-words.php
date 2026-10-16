<?php
/**
 * "Stage 2" / "Stage 3" gone from every screen, email, notice and prompt the
 * practitioner (or the model) reads: what was Stage 2 is "Full Report · WHY",
 * what was Stage 3 is "Full Report · form" (Matthew, 2026-10-13: "call it
 * Stage 1 and then the Full Report").
 *
 * Sweeps the sources with comments, log lines and identifiers removed
 * (stage2_*, stage3_*, 'stage2', data-stage, class names stay: renaming them
 * is a logic change). W.1 = labels, emails, notices; W.2 = the AI prompts
 * (decision D3, only with Quim's recorded yes). Every hit is printed file:line.
 *
 * Run:  php scenario-no-stage-words.php   (self-asserting, exit 0/1)
 */
$ROOT = __DIR__ . '/../../';
$PASS = 0; $FAIL = 0;
function check( $label, $cond ) {
    global $PASS, $FAIL;
    if ( $cond ) { $PASS++; echo "  PASS  $label\n"; } else { $FAIL++; echo "  FAIL  $label\n"; }
}

$LABELS = array(
    'assets/js/hdlv2-client-list-enhance.js',
    'assets/js/hdlv2-consultation.js',
    'assets/js/hdlv2-iris-consult.js',
    'assets/js/hdlv2-practitioner-dashboard.js',
    'includes/class-hdlv2-admin-automation-tier.php',
    'includes/class-hdlv2-compatibility.php',
    'includes/sprint-2/class-hdlv2-email-templates.php',
    'includes/sprint-2c/class-hdlv2-consultation.php',
    'includes/sprint-2c/class-hdlv2-flight-notes-service.php',
    'includes/sprint-4/class-hdlv2-admin-restore.php',
    'includes/sprint-4/class-hdlv2-checkin.php',
    'includes/sprint-4/class-hdlv2-client-status.php',
    'includes/sprint-4/class-hdlv2-practitioner-dashboard.php',
);
$PROMPTS = array(
    'includes/sprint-2/class-hdlv2-ai-service.php',
    'includes/sprint-2c/class-hdlv2-auto-consultation.php',
    'includes/sprint-2/class-hdlv2-why-picks.php',
);

function readable_hits( $root, $file ) {
    $hits = array();
    foreach ( file( $root . $file ) as $n => $l ) {
        $t = ltrim( $l );
        if ( '' === $t || 0 === strpos( $t, '//' ) || 0 === strpos( $t, '*' ) || 0 === strpos( $t, '/*' ) || 0 === strpos( $t, '#' ) ) continue;
        if ( false !== strpos( $l, 'error_log(' ) || false !== strpos( $l, 'console.' ) ) continue; // log lines
        $l = preg_replace( '#\s//\s.*$#', '', $l );                          // trailing comment
        $l = preg_replace( '#/\*.*?\*/#', '', $l );                          // inline block comment
        $l = preg_replace( '/[\w-]*stage[_-]?[23][\w-]*/', '', $l );        // identifiers (lower-case only)
        if ( preg_match( '/\bStage ?(2|3|two|three)\b/i', $l ) ) $hits[] = $file . ':' . ( $n + 1 ) . '  ' . substr( trim( $l ), 0, 120 );
    }
    return $hits;
}

foreach ( array( 'W.1 labels, emails, notices' => $LABELS, 'W.2 AI prompts (D3)' => $PROMPTS ) as $name => $files ) {
    $left = array();
    foreach ( $files as $f ) $left = array_merge( $left, readable_hits( $ROOT, $f ) );
    echo "── $name ──\n";
    foreach ( $left as $h ) echo "        left: $h\n";
    check( "$name: zero readable \"Stage 2/3\" (" . count( $left ) . ' left)', 0 === count( $left ) );
}

echo "\nPASS=$PASS FAIL=$FAIL\n";
exit( $FAIL === 0 ? 0 : 1 );
