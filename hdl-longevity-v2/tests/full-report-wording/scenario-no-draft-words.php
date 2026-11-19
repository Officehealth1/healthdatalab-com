<?php
/**
 * "Draft" gone from every screen and email the client reads: what was the
 * draft report is "the Full Report" (the owner, 2026-11-16: "that word draft
 * has to be removed because it makes it feel like it's incomplete … what we
 * were calling the draft report is just called the full report"). The
 * practitioner's screens and email call it "Full Report" too; the editable
 * "Draft consultation summary" box is the one line allowed to keep the word.
 *
 * Decided 2026-11-17: the lines that promise a $27 buyer a consultation, or say
 * the report is unfinished, go for everyone. P.3 checks the client files for
 * them.
 *
 * Sweeps the sources with comments, log lines and identifiers removed
 * (draft_*, *-draft-*, $draft, 'draft', HDLV2_* names, camelCase: renaming
 * them is a logic change). Every hit is printed file:line.
 *
 * Run:  php scenario-no-draft-words.php [plugin-root]   (self-asserting, exit 0/1)
 *       plugin-root defaults to this checkout; pass another copy of the plugin
 *       (a server tree pulled down) to sweep that one instead.
 */
$ROOT = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) . '/' : __DIR__ . '/../../';
$PASS = 0; $FAIL = 0;
function check( $label, $cond ) {
    global $PASS, $FAIL;
    if ( $cond ) { $PASS++; echo "  PASS  $label\n"; } else { $FAIL++; echo "  FAIL  $label\n"; }
}

$CLIENT = array(
    'assets/js/hdlv2-draft-report.js',
    'assets/js/hdlv2-staged-form.js',
    'assets/js/hdlv2-checkin.js',
    'includes/sprint-2c/class-hdlv2-client-draft-view.php',
    'includes/sprint-4/class-hdlv2-client-dashboard.php',
    'includes/sprint-4/class-hdlv2-products.php',
    'includes/sprint-2/class-hdlv2-staged-form.php',
    'includes/sprint-2/class-hdlv2-email-templates.php',
    'widget/hdl-lead-magnet.js',
);
$PRACTITIONER = array(
    'assets/js/hdlv2-consultation.js',
    'assets/js/hdlv2-client-list-enhance.js',
    'includes/sprint-4/class-hdlv2-practitioner-dashboard.php',
);
// The editable working copy only the practitioner sees keeps its name.
$ALLOWED = array( 'assets/js/hdlv2-consultation.js' => array( 'Draft consultation summary' ) );
// The weekly check-in and quarterly review emails speak of the weekly plan,
// not the Full Report; they are true for a client on check-ins.
$PROMISE_ALLOWED = array( 'includes/sprint-2/class-hdlv2-email-templates.php' => array(
    'Your practitioner uses your check-ins to fine-tune your plan.',
    'helps your practitioner fine-tune your plan.',
) );

$DRAFT   = '/\bdraft(s|ed|ing)?\b/i';
$PROMISE = '/fine-tune|refine|book .*consultation|consultation pending|after that consultation/i';

function readable_lines( $root, $file ) {
    $out = array();
    foreach ( file( $root . $file ) as $n => $l ) {
        $t = ltrim( $l );
        if ( '' === $t || 0 === strpos( $t, '//' ) || 0 === strpos( $t, '*' ) || 0 === strpos( $t, '/*' ) || 0 === strpos( $t, '#' ) ) continue;
        if ( false !== strpos( $l, 'error_log(' ) || false !== strpos( $l, 'console.' ) ) continue; // log lines
        $l = preg_replace( '#\s//\s.*$#', '', $l );                                   // trailing comment
        $l = preg_replace( '#/\*.*?\*/#', '', $l );                                   // inline block comment
        $l = preg_replace( '/\bHDLV2\w*/', '', $l );                                  // class and constant names
        $l = preg_replace( '#[\w$./-]*(?:[_$./-]draft|draft[_-])[\w-]*#', '', $l );   // draft_x, x-draft-y, $draft, .draft, /draft
        $l = preg_replace( '/\bdraft(?:[A-Z]\w*|gen)\b/', '', $l );                   // draftRoot, draftPdf, draftgen
        $l = preg_replace( '/([\'"])draft\1/', '', $l );                              // 'draft' as a stored value
        $out[ $n + 1 ] = $l;
    }
    return $out;
}

function hits( $root, $files, $pattern, $allowed = array() ) {
    $hits = array();
    foreach ( $files as $f ) {
        if ( ! is_readable( $root . $f ) ) { $hits[] = "$f  (file missing)"; continue; }
        foreach ( readable_lines( $root, $f ) as $n => $l ) {
            foreach ( $allowed[ $f ] ?? array() as $ok ) if ( false !== strpos( $l, $ok ) ) continue 2;
            if ( preg_match( $pattern, $l ) ) $hits[] = $f . ':' . $n . '  ' . substr( trim( $l ), 0, 120 );
        }
    }
    return $hits;
}

$runs = array(
    'P.1 client screens and emails: no "draft"'            => hits( $ROOT, $CLIENT, $DRAFT ),
    'P.5 practitioner screens and email: no "draft"'       => hits( $ROOT, $PRACTITIONER, $DRAFT, $ALLOWED ),
    'P.3 client screens and emails: no consultation promise' => hits( $ROOT, $CLIENT, $PROMISE, $PROMISE_ALLOWED ),
);
foreach ( $runs as $name => $left ) {
    echo "── $name ──\n";
    foreach ( $left as $h ) echo "        left: $h\n";
    check( "$name (" . count( $left ) . ' left)', 0 === count( $left ) );
}

// The allowed line must still be there, or the allowance is hiding nothing.
$kept = hits( $ROOT, array( 'assets/js/hdlv2-consultation.js' ), '/Draft consultation summary/' );
check( 'the practitioner\'s "Draft consultation summary" box keeps its name', 1 === count( $kept ) );

echo "\nPASS=$PASS FAIL=$FAIL\n";
exit( $FAIL === 0 ? 0 : 1 );
