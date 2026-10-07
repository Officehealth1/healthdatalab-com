<?php
/**
 * WHY everywhere (v0.48.8) — wiring, against the shipped source.
 *
 * The final-report and flight-notes payload builders need a full WordPress;
 * what they add is HDLV2_Why_Picks::payload_fields() (behaviour checked in
 * scenario-why-sources.php), so here the checks are that each builder calls
 * it in the right place, and that each prompt caller passes the right block.
 *
 * Run:  php scenario-why-wiring.php   (self-asserting, exit 0/1)
 */

$root = dirname( __DIR__, 2 );
$pass = 0; $fail = 0;
function check( $label, $ok ) {
    global $pass, $fail;
    echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n";
    $ok ? $pass++ : $fail++;
}
function src( $f ) {
    $out = '';
    foreach ( token_get_all( file_get_contents( $GLOBALS['root'] . '/' . $f ) ) as $t ) {
        if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) continue;
        $out .= is_array( $t ) ? $t[1] : $t;
    }
    return $out;
}
/** Body of a method, by name. */
function method( $code, $name ) {
    $at = strpos( $code, 'function ' . $name . '(' );
    if ( false === $at ) return '';
    $open = strpos( $code, '{', $at ); $depth = 0;
    for ( $i = $open; $i < strlen( $code ); $i++ ) {
        if ( '{' === $code[ $i ] ) $depth++;
        if ( '}' === $code[ $i ] && 0 === --$depth ) return substr( $code, $open, $i - $open + 1 );
    }
    return '';
}

$fr = src( 'includes/sprint-2c/class-hdlv2-final-report.php' );
$gen = method( $fr, 'generate' );
check( 'F1 final report prompt gets the draft-use block (LIFT/THRIVE instructions fit it)',
    1 === preg_match( '/generate_final_report\(\s*\$calc_result,.*?array\( \'picks_block\' => HDLV2_Why_Picks::block_for_row\( \$progress, \$calc_result\[\'scores\'\] \?\? array\(\) \) \) \+ \$why_profile/s', $gen ) );
check( 'F2 PDF sections prompt gets the facts block',
    1 === preg_match( '/generate_pdf_sections\(\s*\$calc_result,\s*array\( \'picks_block\' => HDLV2_Why_Picks::block_for_row\( \$progress, \$calc_result\[\'scores\'\] \?\? array\(\), \'facts\' \) \) \+ \$why_profile/s', $gen ) );
$bp = method( $fr, 'build_pdf_payload' );
$merge = strpos( $bp, 'HDLV2_Why_Picks::payload_fields( $progress, $calc_result[\'scores\'] ?? array() )' );
check( 'F3 final payload adds payload_fields() (all five keys, empty for the old flow)', false !== $merge );
check( 'F4 ...before the quote-safe text pass, so the Make JSON template stays valid', false !== $merge && $merge < strpos( $bp, '$html_safe_fields = array(' ) );

$fn = method( src( 'includes/sprint-2c/class-hdlv2-flight-notes.php' ), 'build_flight_notes_payload' );
check( 'N1 flight notes payload: why_picks + why_focus_areas from payload_fields(), single rows only',
    1 === preg_match( '/if \( HDLV2_Why_Picks::is_single\(.*?\) \) \{.*?payload_fields\(.*?\'why_picks\'.*?\'why_focus_areas\'/s', $fn ) );
$ai = method( src( 'includes/sprint-2c/class-hdlv2-flight-notes-service.php' ), 'build_ai_inputs' );
check( 'N2 consultation-notes inputs carry the facts block, only when non-empty (old-flow cache hash unchanged)',
    1 === preg_match( '/block_for_row\( \$row, \$calc\[\'scores\'\] \?\? array\(\), \'facts\' \).*?if \( \'\' !== \$picks \) \$why\[\'picks_block\'\] = \$picks;/s', $ai ) );

// v0.48.10 — the draft payload and the practitioner's brief.
$dw = method( src( 'includes/sprint-2/class-hdlv2-staged-form.php' ), 'fire_make_webhook' );
$dm = strpos( $dw, '$payload += HDLV2_Why_Picks::payload_fields( $progress, $scores );' );
check( 'Y4 draft payload adds payload_fields() (eight keys, empty for the old flow)', false !== $dm );
check( 'Y4b ...before the quote-safe text pass', false !== $dm && $dm < strpos( $dw, '$html_safe_fields = array(' ) );
check( 'Y5 final: Make and the direct PDF service share build_pdf_payload (same eight keys by construction)',
    false !== strpos( src( 'includes/sprint-2c/class-hdlv2-final-report-pdf-service.php' ), 'HDLV2_Final_Report::build_pdf_payload(' )
    && false !== strpos( method( $fr, 'fire_webhook' ), 'self::build_pdf_payload(' ) );
$br = method( src( 'includes/sprint-2c/class-hdlv2-consultation.php' ), 'get_or_build_pre_consult_summary' );
check( 'R3 brief caller: facts block for the row, set only when non-empty (old-flow facts unchanged)',
    1 === preg_match( '/\$picks = HDLV2_Why_Picks::block_for_row\( \$progress, \$calc\['scores'\] \?\? array\(\), 'facts' \);\s*if \( '' !== \$picks \) \$why\['picks_block'\] = \$picks;.*?generate_pre_consultation_summary\(/s', $br ) );

$main = file_get_contents( $root . '/hdl-longevity-v2.php' );
check( 'V1 version 0.48.10 in header and constant', false !== strpos( $main, '* Version: 0.48.10' ) && false !== strpos( $main, "define( 'HDLV2_VERSION', '0.48.10' );" ) );

echo "\n" . ( $fail ? "WHY WIRING: FAIL ($fail)\n" : "WHY WIRING: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );
