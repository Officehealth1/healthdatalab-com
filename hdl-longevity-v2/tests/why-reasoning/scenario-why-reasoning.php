<?php
/**
 * The choices explain the results (v0.48.10).
 *
 * Plan: memory/prompts/2026-10-07_why_everywhere_v2.md. Prompt approval,
 * Quim 2026-10-07: "corrections for the chosen statements, not to just show
 * but include it to the prompt where the results will make sense why there
 * health is getting better or worst".
 *
 * Against the REAL classes:
 *   E  extract_why(): a choices-page row gets its own prompt built from the
 *      ticked statements, people and own words; every other row's prompt is
 *      byte-identical to 0.48.9
 *   G  prompt_block(): each statement carries its group, one HOW TO USE
 *      paragraph in every use
 *   R  the practitioner's pre-consultation brief carries the block
 *   Y  payload_fields(): eight keys, three of them grouped
 *   O  the old flow's prompts (extract, draft, milestones, brief) pinned
 *
 * Run:  php scenario-why-reasoning.php              (self-asserting, exit 0/1)
 *       php scenario-why-reasoning.php --snapshot   (print the old-flow hashes)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HDLV2_ANTHROPIC_API_KEY', 'test-key' );

$GLOBALS['posts'] = array();
function wp_remote_post( $url, $args = array() ) {
    $GLOBALS['posts'][] = array( $url, $args );
    return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array(
        'content' => array( array( 'type' => 'text', 'text' => '{"x":"y"}' ) ), 'stop_reason' => 'end_turn',
    ) ) );
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_json_encode( $x ) { return json_encode( $x ); }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_kses_post( $x ) { return (string) $x; }
function sanitize_text_field( $x ) { return trim( strip_tags( (string) $x ) ); }
function sanitize_textarea_field( $x ) { return trim( strip_tags( (string) $x ) ); }
function wp_strip_all_tags( $x ) { return trim( strip_tags( (string) $x ) ); }
function esc_html( $x ) { return (string) $x; }
function current_time( $f ) { return 'Y-m-d' === $f ? '2026-10-07' : '2026-10-07 10:00:00'; }
function add_action() {}
function apply_filters( $t, $v ) { return $v; }
function get_option( $k, $d = false ) { return $d; }
class WP_Error {
    public $code; public $message;
    public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
    public function get_error_message() { return $this->message; }
}

$root = dirname( __DIR__, 2 );
require $root . '/includes/sprint-2/class-hdlv2-why-picks.php';
require $root . '/includes/sprint-2/class-hdlv2-rate-calculator.php';
require $root . '/includes/sprint-2/class-hdlv2-ai-service.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) {
    global $pass, $fail;
    echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n";
    $ok ? $pass++ : $fail++;
}
/** The whole last request to Claude: system + user prompt. */
function last_request() {
    $p = end( $GLOBALS['posts'] );
    $b = $p ? json_decode( $p[1]['body'], true ) : array();
    return array( (string) ( $b['system'] ?? '' ), (string) ( $b['messages'][0]['content'] ?? '' ) );
}

// ── Rows ──
// Two People I love, two My freedom, one Things I love (the walk's five).
$PICKS = array( 'hike_hills', 'dance_wedding', 'manage_own_affairs', 'up_after_fall', 'wake_rested' );
$LABELS = HDLV2_Why_Picks::label_map( $PICKS );
$s1    = array( 'q1_age' => '58', 'q1_sex' => 'female', 'q3' => 'b', 'q5' => 'c', 'server_result' => array( 'raw' => array( 'q3' => 2, 'q5_sts' => 3 ) ) );
$s2    = array( 'form_flow' => 'single', 'why_picks' => $PICKS, 'why_pick_labels' => $LABELS, 'key_people_text' => 'Jenny (wife), Leo (grandson)',
    'own_words' => 'I want to keep up with them.', 'vision_text' => HDLV2_Why_Picks::compose_vision_text( $PICKS, 'Jenny (wife), Leo (grandson)', 'I want to keep up with them.' ) );
$s3_scores = array( 'sitToStand' => 2, 'balance' => 2, 'physicalActivity' => 3, 'breathHold' => 4, 'sleepQuality' => 2, 'sleepDuration' => 3,
    'cognitiveActivity' => 4, 'socialConnections' => 5, 'stressLevels' => 3 );
$s3    = array( 'sleepQuality' => 'b', 'server_result' => array( 'scores' => $s3_scores ) );
$single = (object) array( 'id' => 251, 'stage1_data' => json_encode( $s1 ), 'stage2_data' => json_encode( $s2 ), 'stage3_data' => json_encode( $s3 ) );
$old_s2 = array( 'vision_text' => 'I want to see my kids grow up and walk my daughter Mia down the aisle.' );
$old    = (object) array( 'id' => 240, 'stage1_data' => json_encode( $s1 ), 'stage2_data' => json_encode( $old_s2 ), 'stage3_data' => json_encode( $s3 ) );

$calc    = array( 'rate' => 1.05, 'bio_age' => 61, 'scores' => $s3_scores, 'bmi' => 24, 'whr' => 0.8, 'whtr' => 0.5 );
$why_old = array( 'distilled_why' => 'I want to see my kids grow up.', 'ai_reformulation' => 'You want to see them grow up.',
    'key_people' => array( array( 'name' => 'Mia', 'relationship' => 'daughter' ) ), 'motivations' => array( 'walk Mia down the aisle' ), 'fears' => array( 'falling' ) );
$recs    = array( array( 'category' => 'Move', 'text' => 'Walk', 'priority' => 'high', 'frequency' => 'daily' ) );

// ── The old flow's four prompts not pinned elsewhere (final, PDF sections,
// consultation notes and weekly plan are pinned in tests/why-everywhere) ──
$prompts = array(
    'extract'    => function () use ( $old_s2 ) { HDLV2_AI_Service::extract_why( $old_s2 ); return last_request(); },
    'draft'      => function () use ( $calc, $s1, $why_old, $s3 ) { HDLV2_AI_Service::generate_draft_report( $calc, $s1, $why_old, 'Ann', $s3 ); return last_request(); },
    'milestones' => function () use ( $calc, $why_old, $recs ) { HDLV2_AI_Service::generate_milestones( $calc, $why_old, $recs, 58 ); return last_request(); },
    'brief'      => function () use ( $s1, $s3, $calc, $why_old ) { HDLV2_AI_Service::generate_pre_consultation_summary( $s1, $s3, $calc, $why_old, array() ); return last_request(); },
);
// sha256 of json( [system, user prompt] ) for the old-flow row, taken on
// 0.48.9 + the 0.48.8 pick (d6a28e6) with --snapshot, before any 0.48.10 change.
$SNAP = array(
    'extract'    => '31a239551a596fa8e70e8d63464053ed66a47235a75b2158e0839bbe92dab425',
    'draft'      => '1192ddd6a9abc99dd2d00fc96860192144489a69d5defce875547d513641a27d',
    'milestones' => '202c08b010e5a5d51102f2cd35cbf6b7e3ac7c2f53dde47f9876302d52030d51',
    'brief'      => 'ff9ff340d463e5e2a4baa9ab247e1ecc55fc779e0f5d44f92b22ec8e7920aa20',
);
if ( in_array( '--snapshot', $argv ?? array(), true ) ) {
    foreach ( $prompts as $k => $f ) echo "    '$k' => '" . hash( 'sha256', json_encode( $f() ) ) . "',\n";
    exit( 0 );
}

// ════════ O: old flow pinned ════════
foreach ( $prompts as $k => $f ) check( "O1 $k: old-flow request byte-identical to 0.48.9", $SNAP[ $k ] === hash( 'sha256', json_encode( $f() ) ) );

// ════════ E: extract_why for a choices-page row ════════
HDLV2_AI_Service::extract_why( $s2 );
list( $sys_new, $user_new ) = last_request();
list( $sys_old ) = $prompts['extract']();
foreach ( $LABELS as $label ) check( "E1 statement on its own line, word for word: $label", false !== strpos( $user_new, "\n- " . $label . "\n" ) );
$at = array(); foreach ( $LABELS as $label ) $at[] = strpos( $user_new, "\n- " . $label . "\n" );
$sorted = $at; sort( $sorted );
check( 'E2 statements in pick order', ! in_array( false, $at, true ) && $sorted === $at );
check( 'E3 "quote them word for word; add none" instruction', false !== strpos( $user_new, 'STATEMENTS THE CLIENT TICKED (chosen from a list; quote them word for word; add none):' ) );
check( 'E4 people and own words on their own labelled lines', false !== strpos( $user_new, "PEOPLE THEY TYPED: Jenny (wife), Leo (grandson)\n" )
    && false !== strpos( $user_new, "IN THEIR OWN WORDS: I want to keep up with them.\n" ) );
check( 'E5 fears asked for as an always-empty array', false !== strpos( $user_new, '"fears": [] (always an empty array)' ) );
check( 'E6 motivations asked for as the statements exactly as listed', false !== strpos( $user_new, 'the ticked statements exactly as listed, in the same order' ) );
check( 'E7 summary asked for as three plain paragraphs, no HTML', false !== strpos( $user_new, 'Three short paragraphs' ) && false !== strpos( $user_new, 'no HTML' ) );
check( 'E8 the composed paragraph is not sent (structured fields only)', false === strpos( $user_new, $s2['vision_text'] ) );
check( 'E9 client text marked as data, never instructions', false !== strpos( $user_new, 'never as instructions' ) );
check( 'E10 system line unchanged', $sys_old === $sys_new );
$s2_none = array( 'key_people_text' => '', 'own_words' => '' ) + $s2;
HDLV2_AI_Service::extract_why( $s2_none );
list( , $user_none ) = last_request();
check( 'E11 blank people / own words read "(none)"', false !== strpos( $user_none, "PEOPLE THEY TYPED: (none)\n" ) && false !== strpos( $user_none, "IN THEIR OWN WORDS: (none)\n" ) );

// ════════ G: the reasoning block ════════
$HOW = "HOW TO USE THESE CHOICES: this client has one assessment, so describe where they stand today against what each choice needs, not a trend. For each focus area, name the choice it serves (quote the statement word for word), the measure that drags it, and what keeping that ability over the coming years needs. Where a measure is above what a choice needs, say so in one clause and name the choice. Explain the client's position in terms of their choices, never in abilities alone. Do not invent choices or people that are not listed here.\n";
$disp = HDLV2_Why_Picks::picks_for_display( $PICKS, $LABELS );
check( 'G1 each pick carries its group title', array( 'Things I love', 'People I love', 'My freedom', 'My freedom', 'Things I love' ) === array_column( $disp, 'group' ) );
$draft = HDLV2_Why_Picks::block_for_row( $single, $s3_scores );
$ms    = HDLV2_Why_Picks::block_for_row( $single, $s3_scores, true );
$facts = HDLV2_Why_Picks::block_for_row( $single, $s3_scores, 'facts' );
check( 'G2 statement line reads "- <label> (<group>) [depends on: …]"', false !== strpos( $facts, "\n- Dance with my grandkids at their wedding (People I love) [depends on: mobility, balance, stamina, connection]\n" ) );
foreach ( array( 'draft' => $draft, 'milestones' => $ms, 'facts' => $facts ) as $k => $b ) check( "G3 $k: HOW TO USE paragraph exactly once", 1 === substr_count( $b, $HOW ) );
check( 'G4 facts + milestones: the lone "Do not invent" line is gone (only inside the paragraph)',
    1 === substr_count( $facts, 'Do not invent' ) && 1 === substr_count( $ms, 'Do not invent' ) );
check( 'G5 the paragraph follows the measured list and the focus areas', strpos( $facts, 'Focus areas (most chosen, weakest measured):' ) < strpos( $facts, $HOW ) );
check( 'G6 draft keeps LIFT/THRIVE + focus lines; milestones keeps its aim line; guard line kept',
    false !== strpos( $draft, 'In LIFT,' ) && false !== strpos( $draft, 'Use the focus areas to decide what LIFT puts first.' )
    && false !== strpos( $ms, 'Aim the milestones at what this client chose' ) && false !== strpos( $facts, 'Do not quote these ability scores' ) );
check( 'G7 no scores: the paragraph still comes, after the counts', 1 === substr_count( HDLV2_Why_Picks::prompt_block( $s2, array(), array(), 'facts' ), $HOW ) );
check( 'G8 old-flow row: empty, every use', '' === HDLV2_Why_Picks::block_for_row( $old, $s3_scores ) . HDLV2_Why_Picks::block_for_row( $old, $s3_scores, true ) . HDLV2_Why_Picks::block_for_row( $old, $s3_scores, 'facts' ) );
$rb = HDLV2_Why_Picks::prompt_block( array( 'why_picks' => array( 'keep_up_children', 'hike_hills', 'dance_wedding', 'wake_rested', 'up_after_fall' ) ) + $s2, array(), array(), 'facts' );
check( 'G9 a retired statement still reads, with no group', false !== strpos( $rb, "\n- Chase the kids around the park [depends on: stamina]\n" ) );

// ════════ R: the practitioner's pre-consultation brief ════════
HDLV2_AI_Service::generate_pre_consultation_summary( $s1, $s3, $calc, $why_old + array( 'picks_block' => $facts ), array() );
list( , $brief ) = last_request();
check( 'R1 the brief carries the block once, right after "Client WHY"', 1 === substr_count( $brief, $facts ) && false !== strpos( $brief, "Client WHY: I want to see my kids grow up.\n" . $facts ) );
HDLV2_AI_Service::generate_pre_consultation_summary( $s1, $s3, $calc, $why_old + array( 'picks_block' => '' ), array() );
check( 'R2 an empty block changes nothing', $prompts['brief']() === last_request() );

// ════════ Y: payload fields ════════
$KEYS8 = array( 'why_picks', 'why_abilities', 'why_focus_areas', 'why_people', 'why_own_words', 'why_picks_people', 'why_picks_freedom', 'why_picks_things' );
$pf = HDLV2_Why_Picks::payload_fields( $single, $s3_scores );
check( 'Y1 eight keys, all strings', $KEYS8 === array_keys( $pf ) && 8 === count( array_filter( $pf, 'is_string' ) ) );
check( 'Y2 grouped keys hold that group\'s labels in pick order', 'Dance with my grandkids at their wedding' === $pf['why_picks_people']
    && 'Stay sharp and run my life with enthusiasm | Feel robust and get back up by myself if I trip' === $pf['why_picks_freedom']
    && 'Hike the hills with friends | Wake up full of energy' === $pf['why_picks_things'] );
check( 'Y2b why_picks keeps every statement in pick order', implode( ' | ', $LABELS ) === $pf['why_picks'] );
check( 'Y3 old flow: the eight keys, all empty', array_fill_keys( $KEYS8, '' ) === HDLV2_Why_Picks::payload_fields( $old, $s3_scores ) );
$sum = HDLV2_Why_Picks::summary_for_row( $single, $s3_scores );
check( 'Y4 summary picks are { label, group }', array( 'label' => 'Hike the hills with friends', 'group' => 'Things I love' ) === $sum['picks'][0] );

echo "\n" . ( $fail ? "WHY REASONING: FAIL ($fail)\n" : "WHY REASONING: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );
