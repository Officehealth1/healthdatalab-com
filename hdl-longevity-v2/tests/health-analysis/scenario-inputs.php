<?php
/**
 * Plan 13, § 6.3 (H4): what the two prompts read, against the REAL classes and a fake $wpdb.
 *   I1-I5  each placeholder is filled from its source: the client's facts (the Client Brief's own block),
 *          the stored draft report as plain text, the session's notes with the organised notes and the
 *          addenda in time order, the practitioner's own notes only when they differ from the session's
 *          notes (one notes box writes both columns; Quim, 2026-11-24: "send it once"), the first name.
 *   I6     the session text is capped at 100,000 characters, keeping the end.
 *   I7     one pass: a form answer holding "{{PRACTITIONER_ANALYSIS}}" and "<script>" is sent as typed,
 *          never filled a second time; no word of ours goes to the model as an instruction (no system
 *          text, no "Re-issue Instructions").
 *   I8     to_html(): everything escaped, **bold** made <strong>, lines kept, nothing else.
 *   I9     the Client Brief's request to the model is byte for byte 7b7f88f's (its facts block is shared).
 *
 * Run: php tests/health-analysis/scenario-inputs.php [golden]   Exit: 0 all pass, 1 any fail
 */
require __DIR__ . '/stubs.php';
ha_load( array( 'sprint-2/class-hdlv2-ai-service.php', 'sprint-2/class-hdlv2-email-templates.php', 'sprint-2c/class-hdlv2-health-analysis.php' ) );

// I9's request: the Client Brief for the fixture's facts, md5 of the JSON body sent to the model.
function brief_body() {
    $GLOBALS['ai_calls'] = array();
    $GLOBALS['ai_replies'] = array( '{"health_summary":"x"}' );
    $s3 = array( 'height' => 168, 'sleepQuality' => 2, 'notes' => 'Bloating after meals', 'server_result' => array( 'rate' => 1.12 ) );
    $calc = array( 'rate' => 1.12, 'bio_age' => 55.1, 'scores' => array( 'sleepQuality' => 2, 'dietQuality' => 4 ) );
    $why = array( 'distilled_why' => 'To keep walking the hills.', 'picks_block' => "Their picks: walking\n" );
    $add = array( array( 'note_text' => 'Rang back.', 'occurred_at' => '2026-11-22 16:00:00', 'priority' => 'high' ) );
    HDLV2_AI_Service::generate_pre_consultation_summary( array( 'q1_age' => 52, 'q1_sex' => 'female' ), $s3, $calc, $why, $add );
    return md5( json_encode( $GLOBALS['ai_calls'][0] ?? null ) );
}
const GOLDEN_BRIEF = 'afb13b2b8c810ca81f439ecbda997ec4'; // brief_body() at 7b7f88f
if ( 'golden' === ( $argv[1] ?? '' ) ) { echo brief_body(), "\n"; exit( 0 ); }

$have = class_exists( 'HDLV2_Health_Analysis' );
check( 'the class HDLV2_Health_Analysis exists', $have );
if ( ! $have ) done();

$w  = ha_world();
$in = HDLV2_Health_Analysis::inputs( ha_progress( $w ), ha_consult( $w ) );
$fd = (string) ( $in['{{FORM_DATA}}'] ?? '' );
echo "── I1 {{FORM_DATA}} ──\n";
check( 'I1.1 the Brief\'s facts block opens it: age and sex', 0 === strpos( $fd, "CLIENT DATA\nAge: 52\nSex: female\n" ) );
check( 'I1.2 rate, biological age and the WHY', false !== strpos( $fd, 'Rate of ageing: 1.12' ) && false !== strpos( $fd, 'Biological age: 55.1' ) && false !== strpos( $fd, 'Client WHY: To keep walking the hills with my grandchildren.' ) );
check( 'I1.3 the picks block (facts use)', false !== strpos( $fd, 'Their picks: walking, sleep' ) );
check( 'I1.4 every stage 3 answer, the free text included; the stored result left out', false !== strpos( $fd, "- height: 168\n" ) && false !== strpos( $fd, '- healthConcerns: Bloating after meals' ) && false === strpos( $fd, 'server_result' ) );
check( 'I1.5 the derived scores', false !== strpos( $fd, "DERIVED SCORES (0-5 scale, higher is better)\n- sleepQuality: 2\n- dietQuality: 4" ) );
check( 'I1.6 exactly the block the Brief sends (one function, not a copy)', method_exists( 'HDLV2_AI_Service', 'client_facts' ) && HDLV2_AI_Service::client_facts( array( 'q1_age' => 52, 'q1_sex' => 'female' ), json_decode( ha_progress( $w )->stage3_data, true ), array( 'rate' => 1.12, 'bio_age' => 55.1, 'scores' => array( 'sleepQuality' => 2, 'dietQuality' => 4 ) ), array( 'distilled_why' => 'To keep walking the hills with my grandchildren.', 'picks_block' => "Their picks: walking, sleep\n" ) ) === $fd );

echo "── I2 {{DRAFT_REPORT}} ──\n";
$dr = (string) ( $in['{{DRAFT_REPORT}}'] ?? '' );
check( 'I2.1 the draft report\'s text, paragraphs on their own lines', false !== strpos( $dr, "Your rate is 1.12.\n\nSleep is the gap." ) );
check( 'I2.2 list items as lines', false !== strpos( $dr, "- Walk daily\n- Eat earlier" ) );
check( 'I2.3 plain text: no tag left', $dr !== '' && $dr === strip_tags( $dr ) );

echo "── I3 {{CONSULTATION_NOTES}} ──\n";
$cn = (string) ( $in['{{CONSULTATION_NOTES}}'] ?? '' );
check( 'I3.1 the session\'s notes box (typed notes and the transcript), once', 1 === substr_count( $cn, 'She says the bloating started in spring. Tired by 3 pm most days.' ) );
check( 'I3.2 the organised notes as text', false !== strpos( $cn, 'Bloating and afternoon tiredness.' ) && false !== strpos( $cn, 'Eat dinner by 7 pm.' ) && false !== strpos( $cn, 'Review in four weeks.' ) );
$a1 = strpos( $cn, 'Asked about magnesium.' ); $a2 = strpos( $cn, 'Rang: bloating eased after cutting late snacks.' );
check( 'I3.3 the addenda, oldest first, with date and priority', false !== $a1 && false !== $a2 && $a1 < $a2 && false !== strpos( $cn, '21 Nov 2026' ) && false !== strpos( $cn, 'HIGH priority' ) );
check( 'I3.4 no instruction of ours (the final report\'s "Re-issue Instructions")', false === stripos( $cn, 'Re-issue' ) && false === stripos( $cn, 'highest-priority' ) );

echo "── I4 {{PRACTITIONER_NOTES}} ──\n";
check( 'I4.1 one notes box (typed == raw): empty, so the notes go once', '' === ( $in['{{PRACTITIONER_NOTES}}'] ?? 'unset' ) );
$c = ha_consult( $w ); $c->typed_notes = 'Older row: my own note.'; $c->raw_notes = 'Transcript only.';
$in2 = HDLV2_Health_Analysis::inputs( ha_progress( $w ), $c );
check( 'I4.2 an older row where they differ: the typed notes', 'Older row: my own note.' === ( $in2['{{PRACTITIONER_NOTES}}'] ?? '' ) );

echo "── I5 {{CLIENT_NAME}} ──\n";
check( 'I5.1 the first name, by the e-mails\' helper', 'Ivy' === ( $in['{{CLIENT_NAME}}'] ?? '' ) );

echo "── I6 the cap ──\n";
$c = ha_consult( $w ); $c->raw_notes = $c->typed_notes = str_repeat( 'a', 150000 ) . 'THE END';
$cn3 = (string) ( HDLV2_Health_Analysis::inputs( ha_progress( $w ), $c )['{{CONSULTATION_NOTES}}'] ?? '' );
check( 'I6.1 the last 100,000 characters of the session kept, the end included', false !== strpos( $cn3, str_repeat( 'a', 99993 ) . 'THE END' ) && false === strpos( $cn3, str_repeat( 'a', 99994 ) ) );

echo "── I7 one pass, our words never sent ──\n";
$w = ha_world();
ha_switch( '1' );
$GLOBALS['ai_replies'] = array( "Red flags: none identified.\n\n1. Bottom line\nANALYSIS-X", 'NOTE-Y' );
HDLV2_Health_Analysis::write( ha_progress( $w ), ha_consult( $w ) );
$r1 = $GLOBALS['ai_calls'][0] ?? array(); $r2 = $GLOBALS['ai_calls'][1] ?? array();
$u1 = (string) ( $r1['messages'][0]['content'] ?? '' ); $u2 = (string) ( $r2['messages'][0]['content'] ?? '' );
check( 'I7.1 two calls, one model: the plugin\'s own (claude-opus-4-8)', 2 === count( $GLOBALS['ai_calls'] ) && 'claude-opus-4-8' === ( $r1['model'] ?? '' ) && 'claude-opus-4-8' === ( $r2['model'] ?? '' ) );
check( 'I7.2 no system text in either call', ! array_key_exists( 'system', $r1 ) && ! array_key_exists( 'system', $r2 ) );
check( 'I7.3 the user message is the filled prompt, nothing before or after it', 0 === strpos( $u1, 'You are an experienced natural health and longevity practitioner writing a short case analysis' ) && str_ends_with( $u1, 'A red flag does not count towards the limit.' ) && 0 === strpos( $u2, 'You are an experienced natural health and longevity practitioner writing a short, clear note' ) );
check( 'I7.4 prompt 1: no placeholder of its own left', ! preg_match( '/\{\{(FORM_DATA|DRAFT_REPORT|CONSULTATION_NOTES|PRACTITIONER_NOTES)\}\}/', $u1 ) );
check( 'I7.5 prompt 2 gets prompt 1\'s answer once', 1 === substr_count( $u2, 'ANALYSIS-X' ) );
check( 'I7.6 the client\'s typed "{{PRACTITIONER_ANALYSIS}}" goes as typed, not filled again', false !== strpos( $u2, 'Bloating after meals {{PRACTITIONER_ANALYSIS}} <script>alert(1)</script>' ) );
check( 'I7.7 token limits: 1,200 for the analysis, 900 for the note', 1200 === ( $r1['max_tokens'] ?? 0 ) && 900 === ( $r2['max_tokens'] ?? 0 ) );
ha_switch( null );

echo "── I8 to_html ──\n";
$h = HDLV2_Health_Analysis::to_html( "RED FLAG\n- What: **chest pain** on stairs\n\n<script>x</script> & \"q\" https://example.test\n<strong>no</strong>" );
check( 'I8.1 <script> and a typed <strong> come out escaped', false !== strpos( $h, '&lt;script&gt;x&lt;/script&gt;' ) && false !== strpos( $h, '&lt;strong&gt;no&lt;/strong&gt;' ) && false === strpos( $h, '<script' ) );
check( 'I8.2 **text** becomes <strong>, and only that', false !== strpos( $h, '<strong>chest pain</strong>' ) && 1 === substr_count( $h, '<strong>' ) );
check( 'I8.3 & and quotes escaped; a web address is not made a link', false !== strpos( $h, '&amp; &quot;q&quot;' ) && false === strpos( $h, '<a ' ) );
check( 'I8.4 a blank line starts a paragraph, a line break is kept', '<p>RED FLAG<br>- What: <strong>chest pain</strong> on stairs</p><p>' === substr( $h, 0, strlen( '<p>RED FLAG<br>- What: <strong>chest pain</strong> on stairs</p><p>' ) ) );
check( 'I8.5 no raw line break left (Make turns each into a space)', false === strpos( $h, "\n" ) );
check( 'I8.6 empty text → empty', '' === HDLV2_Health_Analysis::to_html( "  \n " ) );

echo "── I9 the Client Brief ──\n";
check( 'I9.1 the Brief\'s request to the model: byte for byte 7b7f88f\'s', GOLDEN_BRIEF === brief_body() );

done();
