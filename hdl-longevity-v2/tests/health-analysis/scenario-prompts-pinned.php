<?php
/**
 * Plan 13, rule 4: Matthew's two health analysis prompts, word for word. Each constant hashes to the
 * value taken from his file (lines joined by one line feed, no final line feed), holds exactly its four
 * placeholders, and its text sits in the plugin once. A change to either prompt needs Matthew's
 * recorded yes and a new pin here, in its own pull request.
 *
 * Run: php tests/health-analysis/scenario-prompts-pinned.php   Exit: 0 all pass, 1 any fail
 */
require __DIR__ . '/stubs.php';
ha_load( array( 'sprint-2c/class-hdlv2-health-analysis.php' ) );

const PIN_PRACTITIONER = array( 3481, '496d6078e8b872694eda338c72452906a0c8d0043643952ddef03fdd519707a5' );
const PIN_CLIENT       = array( 2771, '203bc33ccf4f7c6f7598986be8d96373c947f043a358b2f01be1fc8aa5262fd3' );

$have = class_exists( 'HDLV2_Health_Analysis' );
check( 'the class HDLV2_Health_Analysis exists', $have );
$p1 = $have ? HDLV2_Health_Analysis::PROMPT_PRACTITIONER : '';
$p2 = $have ? HDLV2_Health_Analysis::PROMPT_CLIENT : '';

check( 'prompt 1 (practitioner): 3,481 characters', PIN_PRACTITIONER[0] === strlen( $p1 ) );
check( 'prompt 1: sha256 of Matthew\'s lines 2 to 67', PIN_PRACTITIONER[1] === hash( 'sha256', $p1 ) );
check( 'prompt 2 (client): 2,771 characters', PIN_CLIENT[0] === strlen( $p2 ) );
check( 'prompt 2: sha256 of Matthew\'s lines 79 to 137', PIN_CLIENT[1] === hash( 'sha256', $p2 ) );

function placeholders( $s ) { preg_match_all( '/\{\{[A-Z_]+\}\}/', $s, $m ); $u = array_unique( $m[0] ); sort( $u ); return $u; }
$want1 = array( '{{CONSULTATION_NOTES}}', '{{DRAFT_REPORT}}', '{{FORM_DATA}}', '{{PRACTITIONER_NOTES}}' );
$want2 = array( '{{CLIENT_NAME}}', '{{CONSULTATION_NOTES}}', '{{FORM_DATA}}', '{{PRACTITIONER_ANALYSIS}}' );
check( 'prompt 1 holds exactly its four placeholders', $want1 === placeholders( $p1 ) );
check( 'prompt 2 holds exactly its four placeholders', $want2 === placeholders( $p2 ) );

// Each prompt's text sits in the plugin once: a line from each, searched in every PHP file.
$lines = array( 'writing a short case analysis for another practitioner', 'writing a short, clear note for a client' );
$hits  = array_fill_keys( $lines, 0 );
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( ha_root(), FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
    $path = $f->getPathname();
    if ( 'php' !== strtolower( $f->getExtension() ) || false !== strpos( $path, '/tests/' ) || false !== strpos( $path, '/docs/' ) ) continue;
    $src = file_get_contents( $path );
    foreach ( $lines as $l ) $hits[ $l ] += substr_count( $src, $l );
}
check( 'prompt 1\'s text is in the plugin once', 1 === $hits[ $lines[0] ] );
check( 'prompt 2\'s text is in the plugin once', 1 === $hits[ $lines[1] ] );

done();
