<?php
/**
 * Plan 14 part B, B3: what the prompt is filled with, on the REAL classes (plan 13's inputs()).
 *   I.1 FORM_DATA and DRAFT_REPORT are plan 13's own (the same text its health analysis sends).
 *   I.2 HEARD_SO_FAR is `heard` and nothing else: the saved notes are not read for this call.
 *   I.3 ALREADY_ASKED lists the questions already shown, one per line.
 *   I.4 a placeholder typed into the notes stays as typed (one pass: never filled a second time).
 *   I.5 empty heard and no questions yet: both left empty, the prompt still whole.
 * Run: php tests/live-questions/scenario-inputs.php   Exit: 0 all pass, 1 any fail
 */
require __DIR__ . '/lq-world.php';
$have = class_exists( 'HDLV2_Live_Questions' ) && method_exists( 'HDLV2_Live_Questions', 'prompt' );
check( 'HDLV2_Live_Questions::prompt exists', $have );
if ( ! $have ) done();
$w   = ha_world();
$in  = HDLV2_Health_Analysis::inputs( ha_progress( $w ), ha_consult( $w ) );
$txt = HDLV2_Live_Questions::prompt( $in, 'Unsaved: she wakes at 3 am most nights.', array( 'How is your sleep?', 'When did the bloating start?' ) );
check( 'I.1 the form data and the draft report are plan 13\'s', false !== strpos( $txt, 'Client form data: ' . $in['{{FORM_DATA}}'] ) && false !== strpos( $txt, 'Draft longevity report: ' . $in['{{DRAFT_REPORT}}'] ) );
check( 'I.2 heard so far = the text of the notes box only (the saved notes are not in it)', false !== strpos( $txt, 'Heard or noted so far in this consultation: Unsaved: she wakes at 3 am most nights.' ) && false === strpos( $txt, 'bloating started in spring' ) );
check( 'I.3 the questions already shown, one per line', false !== strpos( $txt, "Questions already suggested (do not repeat them): How is your sleep?\nWhen did the bloating start?" ) );
$t2 = HDLV2_Live_Questions::prompt( $in, 'She typed {{FORM_DATA}} here', array() );
check( 'I.4 a placeholder inside the notes is not filled again', false !== strpos( $t2, 'She typed {{FORM_DATA}} here' ) );
$t3 = HDLV2_Live_Questions::prompt( $in, '', array() );
check( 'I.5 empty heard and nothing asked: both empty, every other line kept', false !== strpos( $t3, "Heard or noted so far in this consultation: \nQuestions already suggested (do not repeat them): \n" ) && false !== strpos( $t3, 'LENGTH' ) && false === strpos( $t3, '{{HEARD_SO_FAR}}' ) && false === strpos( $t3, '{{ALREADY_ASKED}}' ) && false === strpos( $t3, '{{DRAFT_REPORT}}' ) );
done();
