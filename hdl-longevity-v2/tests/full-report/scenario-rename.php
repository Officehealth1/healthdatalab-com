<?php
/**
 * H1 — "Stage 2" / "Stage 3" become "the Full Report" wherever a client reads
 * them. Sweeps the customer-facing files with comments and log lines removed
 * and allows only the practitioner-only strings listed below (by exact text,
 * not line number). Then checks each renamed spot now says "Full Report".
 *
 * Run:  php scenario-rename.php   (self-asserting, exit 0/1)
 */
$ROOT = __DIR__ . '/../../';
$PASS = 0; $FAIL = 0;
function check( $label, $cond ) {
    global $PASS, $FAIL;
    if ( $cond ) { $PASS++; echo "  PASS  $label\n"; } else { $FAIL++; echo "  FAIL  $label\n"; }
}

$FILES = array(
    'widget/hdl-lead-magnet.js',
    'assets/js/hdlv2-staged-form.js',
    'assets/js/hdlv2-draft-report.js',
    'includes/sprint-4/class-hdlv2-client-dashboard.php',
    'includes/sprint-2/class-hdlv2-stage2-insight.php',
    'includes/sprint-2/class-hdlv2-stage1-commentary.php',
    'includes/sprint-2/class-hdlv2-staged-form.php',
    'includes/sprint-2/class-hdlv2-email-templates.php',
    'includes/sprint-1/class-hdlv2-widget-config.php',
    'includes/class-hdlv2-compatibility.php',
);

// Practitioner-only strings (their emails, their notifications, the V1 clients
// page labels). These keep the stage words on purpose.
$ALLOW = array(
    'includes/sprint-2/class-hdlv2-staged-form.php'   => array( 'is ready to invite to Stage 3' ),
    'includes/sprint-2/class-hdlv2-email-templates.php' => array(
        'Stage 3 Form Completed', 'Ready to invite your client to', 'has submitted their Stage 2 W',
        'invitation to Stage 3.', 'Review and invite to Stage 3', 'Stage 2 — Ready to Release',
    ),
    'includes/class-hdlv2-compatibility.php'          => array( "2 => 'Stage 2', 3 => 'Stage 3'" ),
);

function customer_lines( $path ) {
    $out = array();
    foreach ( file( $path ) as $n => $l ) {
        $t = ltrim( $l );
        if ( '' === $t || 0 === strpos( $t, '//' ) || 0 === strpos( $t, '*' ) || 0 === strpos( $t, '/*' ) || 0 === strpos( $t, '#' ) ) continue;
        $l = preg_replace( '#\s//\s.*$#', '', $l );     // trailing comment
        if ( false !== strpos( $l, '[HDLV2]' ) ) continue; // log line
        $out[ $n + 1 ] = $l;
    }
    return $out;
}

echo "── H1 sweep: no Stage 2/3 a client can read ──\n";
$left = array();
foreach ( $FILES as $f ) {
    foreach ( customer_lines( $ROOT . $f ) as $n => $l ) {
        if ( ! preg_match( '/\bStage ?(2|3|two|three)\b/', $l ) ) continue;
        $ok = false;
        foreach ( $ALLOW[ $f ] ?? array() as $a ) {
            if ( false !== strpos( $l, $a ) ) { $ok = true; break; }
        }
        if ( ! $ok ) $left[] = $f . ':' . $n . '  ' . trim( substr( trim( $l ), 0, 110 ) );
    }
}
foreach ( $left as $l ) echo "        left: $l\n";
check( 'R.1 zero client-facing "Stage 2/3" (' . count( $left ) . ' left)', 0 === count( $left ) );

echo "── H1 each renamed spot says the Full Report ──\n";
$src = function ( $f ) use ( $ROOT ) { return file_get_contents( $ROOT . $f ); };
$want = array(
    'widget/hdl-lead-magnet.js' => array(
        'The Full Report shows what matters most to you and what your real measurements say, so your practitioner can plan with you.',
        '<li><strong>The Full Report.</strong> What matters most to you, then your real measurements: the full picture, reviewed by your practitioner.</li>',
        'Continue to your Full Report \u2192',
    ),
    'assets/js/hdlv2-staged-form.js' => array(
        '<li><strong>The Full Report.</strong> What matters most to you, then your real measurements: the full picture, reviewed by your practitioner.</li>',
        'Continue to your Full Report \u2192',
        'The Full Report captures what matters to you, then turns your real measurements into your plan.',
        'Your Full Report',
    ),
    'assets/js/hdlv2-draft-report.js' => array(
        'From what you shared in your Full Report',
        'the choices you made in your Full Report',
    ),
    'includes/sprint-4/class-hdlv2-client-dashboard.php' => array(
        'Full Report &middot; What matters most', 'You pick what matters most to you in your last decade.',
        'Full Report &middot; Your measurements', 'Your real measurements, 5 short sections. Your practitioner reviews them with you.',
    ),
    'includes/sprint-2/class-hdlv2-stage2-insight.php' => array(
        'to shape your Full Report and the longevity plan that comes out of it',
        'Until your practitioner opens the next part of your Full Report, your WHY sits on their queue.',
    ),
    'includes/sprint-2/class-hdlv2-stage1-commentary.php' => array(
        'The Full Report captures your <em>why</em>',
        'worth attention with your practitioner in your Full Report.',
    ),
    'includes/sprint-2/class-hdlv2-staged-form.php' => array(
        'your Full Report measurements put your rate of ageing at',
        'The Full Report measurements must be completed first.',
        'No WHY text found for this token. Complete the first part of your Full Report first.',
    ),
    'includes/class-hdlv2-compatibility.php' => array( 'The Full Report has not been started yet.' ),
    'includes/sprint-1/class-hdlv2-widget-config.php' => array( '%s has opened your Full Report — HealthDataLab', 'has opened the Full Report of your Longevity assessment.', "'Your Full Report'" ),
    'includes/sprint-2/class-hdlv2-email-templates.php' => array(
        'Continue to your Full Report', 'in your Full Report (your Why)', 'Continue your Full Report',
        'Your Full Report is open — %s — %s', 'Your practitioner has reviewed your WHY. Your Full Report continues.', "'Your Full Report is open'",
    ),
);
foreach ( $want as $f => $strings ) {
    $s = $src( $f );
    $miss = array_values( array_filter( $strings, function ( $x ) use ( $s ) { return false === strpos( $s, $x ); } ) );
    foreach ( $miss as $m ) echo "        missing in $f: $m\n";
    check( 'R.2 ' . basename( $f ) . ' carries its ' . count( $strings ) . ' new strings', ! $miss );
}
// The client dashboard keeps four cards so its state map is untouched.
check( 'R.3 client dashboard still has cards 1–4', 1 === preg_match( '/1 => array\(.*\n\s*2 => array\(.*\n\s*3 => array\(.*\n\s*4 => array\(/', $src( 'includes/sprint-4/class-hdlv2-client-dashboard.php' ) ) );

echo "\nPASS=$PASS FAIL=$FAIL\n";
exit( $FAIL === 0 ? 0 : 1 );
