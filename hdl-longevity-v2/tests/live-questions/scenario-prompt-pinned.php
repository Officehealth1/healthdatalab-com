<?php
/**
 * Plan 14 part B, B2 (rule 6): the "questions to ask now" prompt is the plan's text, byte for byte.
 *   P.1 the constant's sha256 equals the pin taken from plan 14 § 7 B2 (32 lines, no final line break).
 *   P.2 each of the four placeholders is in it exactly once.
 *   P.3 its first and last lines are the plan's.
 * A wording problem is reported to the planner, never fixed here. Changing the text needs a new pin.
 * Run: php tests/live-questions/scenario-prompt-pinned.php   Exit: 0 all pass, 1 any fail
 */
require __DIR__ . '/lq-world.php';
$have = class_exists( 'HDLV2_Live_Questions' );
check( 'the class HDLV2_Live_Questions exists', $have );
if ( ! $have ) done();
const PIN = '58365ca82c3d3845322584e518436dacd8e742225b74a6f9a4980dfb605b1a1c';
$p = HDLV2_Live_Questions::PROMPT;
check( 'P.1 sha256 of the prompt == the pin from plan 14 § 7 B2', PIN === hash( 'sha256', $p ) );
$once = true;
foreach ( array( '{{FORM_DATA}}', '{{DRAFT_REPORT}}', '{{HEARD_SO_FAR}}', '{{ALREADY_ASKED}}' ) as $ph ) $once = $once && 1 === substr_count( $p, $ph );
check( 'P.2 the four placeholders, each exactly once', $once );
$lines = explode( "\n", $p );
check( 'P.3 32 lines; the first and the last are the plan\'s', 32 === count( $lines )
    && 'You are an experienced natural health and longevity practitioner helping another practitioner during a live consultation.' === $lines[0]
    && '150 words at most. A red flag does not count towards the limit.' === end( $lines ) );
done();
