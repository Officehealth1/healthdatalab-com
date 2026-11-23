<?php
/**
 * Plan 13, § 6.7, rules 5 and 6: the Comprehensive Report's payload to Make (build_pdf_payload, the REAL
 * class, the consultation row a fake). health_note is added only with the switch on, a non-empty note
 * and a practitioner's stamp; in every other case the payload is byte for byte 7b7f88f's (md5). The
 * practitioner's analysis is in no payload, and no file a client reaches reads its column.
 *
 * Run: php tests/health-analysis/scenario-payload.php [golden]   Exit: 0 all pass, 1 any fail
 */
require __DIR__ . '/stubs.php';
class HDLV2_Staged_Form { public static function format_milestones( $m ) { return ''; } public static function build_gauge_url( ...$a ) { return 'gauge'; } }
class HDLV2_Practitioner { public static function get_logo_url() { return ''; } }
ha_load( array(
    'class-hdlv2-report-names.php', 'sprint-2/class-hdlv2-rate-calculator.php', 'sprint-2/class-hdlv2-ai-service.php',
    'sprint-2/class-hdlv2-email-templates.php', 'sprint-2c/class-hdlv2-health-analysis.php', 'sprint-2c/class-hdlv2-final-report.php',
) );

const ANALYSIS = 'PRACTITIONER-ONLY analysis: **reflux may fit**.';
const NOTE     = "The short version\n**Eating late may be behind the bloating.**\n\nWhat to do first\n1. Eat by 7 pm \"most days\".";

/** One consultation row, as generate() leaves it ('report_generated'), with the four note columns set. */
function world( $note, $stamp ) {
    $w = ha_world();
    $w->t['consultation_notes'][0]['status'] = 'report_generated';
    $w->t['consultation_notes'][0]['health_analysis'] = ANALYSIS;
    $w->t['consultation_notes'][0]['health_analysis_at'] = '2026-11-24 09:00:00';
    $w->t['consultation_notes'][0]['health_note'] = $note;
    $w->t['consultation_notes'][0]['health_note_approved_at'] = $stamp ? '2026-11-24 09:10:00' : null;
    $w->t['consultation_notes'][0]['health_note_approved_by'] = $stamp ? 3 : null;
    return $w;
}
function payload( $w ) {
    return HDLV2_Final_Report::build_pdf_payload( ha_progress( $w ), array( 'rate' => 1.12, 'bio_age' => 55.1, 'scores' => array() ), array( 'q1_age' => 52 ), array(), array(), array( 'distilled_why' => 'To keep walking the hills.' ), array(), array(), 'Typed notes.', 3 );
}
const GOLDEN_FINAL = '19fe6d52cbb2b4705afa2d56bbbf1f8c'; // md5( json_encode( payload ) ), switch absent, at 7b7f88f
if ( 'golden' === ( $argv[1] ?? '' ) ) { ha_switch( null ); echo md5( json_encode( payload( world( NOTE, true ) ) ) ), "\n"; exit( 0 ); }

$have = class_exists( 'HDLV2_Health_Analysis' );
check( 'the class HDLV2_Health_Analysis exists', $have );

echo "── P1 no health_note: byte for byte 7b7f88f ──\n";
$cases = array(
    'switch absent, an approved note' => array( null, NOTE, true ),
    'switch "0", an approved note'    => array( '0', NOTE, true ),
    'switch on, no note written'      => array( '1', null, false ),
    'switch on, a note not approved'  => array( '1', NOTE, false ),
    'switch on, stamped but empty'    => array( '1', "  \n", true ),
);
foreach ( $cases as $name => list( $sw, $note, $stamp ) ) {
    ha_switch( $sw );
    $p = payload( world( $note, $stamp ) );
    check( "P1 $name: no health_note, md5 == 7b7f88f", ! array_key_exists( 'health_note', $p ) && GOLDEN_FINAL === md5( json_encode( $p ) ) );
}

echo "── P2 switch on, an approved note ──\n";
ha_switch( '1' );
$p = payload( world( NOTE, true ) );
check( 'P2.1 health_note is the note through to_html()', $have && HDLV2_Health_Analysis::to_html( NOTE ) === ( $p['health_note'] ?? null ) );
check( 'P2.2 it survives the payload\'s quote pass (an HTML field): <strong> and &quot; intact', false !== strpos( $p['health_note'] ?? '', '<strong>Eating late may be behind the bloating.</strong>' ) && false !== strpos( $p['health_note'] ?? '', '&quot;most days&quot;' ) );
$rest = $p; unset( $rest['health_note'] );
check( 'P2.3 no other key added or changed', GOLDEN_FINAL === md5( json_encode( $rest ) ) );
check( 'P2.4 the practitioner\'s analysis is in no field', false === strpos( json_encode( $p ), 'PRACTITIONER-ONLY' ) && false === strpos( json_encode( $p ), 'reflux' ) );

echo "── P3 no client surface reads the analysis ──\n";
$readers = array();
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( ha_root() . '/includes', FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
    if ( 'php' !== $f->getExtension() ) continue;
    if ( preg_match( '/\bhealth_analysis\b/', file_get_contents( $f->getPathname() ) ) ) $readers[] = str_replace( ha_root() . '/includes/', '', $f->getPathname() );
}
sort( $readers );
check( 'P3.1 the health_analysis column is named only by the migration, the feature\'s class and the practitioner screen (' . implode( ', ', $readers ) . ')',
    array( 'class-hdlv2-activator.php', 'sprint-2c/class-hdlv2-consultation.php', 'sprint-2c/class-hdlv2-health-analysis.php' ) === $readers );
$cons = (string) @file_get_contents( ha_root() . '/includes/sprint-2c/class-hdlv2-consultation.php' );
preg_match_all( '/health_analysis\b/', $cons, $m, PREG_OFFSET_CAPTURE );
$ok = true;
foreach ( $m[0] as $hit ) {   // every mention sits in the practitioner-only load or the routes registered with check_practitioner_auth
    $before = substr( $cons, 0, $hit[1] );
    $fn = preg_match_all( '/function (\w+)\(/', $before, $fm ) ? end( $fm[1] ) : '';
    if ( ! in_array( $fn, array( 'rest_load_consultation', 'register_rest_routes', 'render_shortcode' ), true ) ) $ok = false;
}
check( 'P3.2 in the consultation class, only the practitioner\'s load, its routes and its settings name it', $ok && count( $m[0] ) > 0 );

ha_switch( null );
done();
