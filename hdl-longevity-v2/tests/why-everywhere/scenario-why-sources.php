<?php
/**
 * WHY everywhere (v0.48.8) — the client's choices reach every prompt and PDF.
 *
 * Plan: memory/prompts/2026-10-07_why_everywhere_v1.md. Prompt approval,
 * Quim 2026-10-07: "Yes please update make sure all the changes on Stage 2
 * and 3 are included to the results PDF and to make scenario specially to
 * the AI prompts check and confirm then write the perfect plan".
 *
 * Against the REAL classes:
 *   S  HDLV2_Why_Picks::summary_for_row() + payload_fields(): one source,
 *      empty for every row that did not use the choices page
 *   B  prompt_block() 'facts' use: the same data, no section instructions;
 *      the draft and milestones blocks are unchanged
 *   P  the four prompts (final report, PDF sections, consultation notes,
 *      weekly flight plan WHY lines) carry the block for a choices-page
 *      client and are byte-identical to 0.48.7 for everyone else
 *   C  the context builder hands the flight plan the block
 *
 * Run:  php scenario-why-sources.php   (self-asserting, exit 0/1)
 *       php scenario-why-sources.php --snapshot   (print the old-flow hashes)
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
        'content' => array( array( 'type' => 'text', 'text' => '{"awaken_content":"a"}' ) ), 'stop_reason' => 'end_turn',
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
require $root . '/includes/sprint-5/class-hdlv2-flight-plan.php';
require $root . '/includes/class-hdlv2-context-builder.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) {
    global $pass, $fail;
    echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n";
    $ok ? $pass++ : $fail++;
}
function last_prompt() {
    $p = end( $GLOBALS['posts'] );
    $b = $p ? json_decode( $p[1]['body'], true ) : array();
    return (string) ( $b['messages'][0]['content'] ?? '' );
}

// ── Rows ──
$PICKS = array( 'floor_grandkids', 'stairs_no_rail', 'wake_rested', 'keep_driving', 'swim_in_sea' );
$s1    = array( 'q1_age' => '58', 'q1_sex' => 'female', 'q3' => 'b', 'q5' => 'c', 'server_result' => array( 'raw' => array( 'q3' => 2, 'q5_sts' => 3 ) ) );
$s2    = array( 'form_flow' => 'single', 'why_picks' => $PICKS, 'key_people_text' => 'my husband "Tom" and the grandchildren',
    'own_words' => 'I want to keep up with them.', 'vision_text' => 'In my later years…' );
$s3_scores = array( 'sitToStand' => 2, 'balance' => 2, 'physicalActivity' => 3, 'breathHold' => 4, 'sleepQuality' => 2, 'sleepDuration' => 3,
    'cognitiveActivity' => 4, 'socialConnections' => 5, 'stressLevels' => 3 );
$single = (object) array( 'id' => 251, 'stage1_data' => json_encode( $s1 ), 'stage2_data' => json_encode( $s2 ),
    'stage3_data' => json_encode( array( 'server_result' => array( 'scores' => $s3_scores ) ) ) );
$old    = (object) array( 'id' => 240, 'stage1_data' => json_encode( $s1 ), 'stage2_data' => json_encode( array( 'vision_text' => 'I want to see my kids grow up.' ) ),
    'stage3_data' => json_encode( array( 'server_result' => array( 'scores' => $s3_scores ) ) ) );

// ── The four prompts (checks in P below) ──
$calc   = array( 'rate' => 1.05, 'bio_age' => 61, 'scores' => $s3_scores, 'bmi' => 24, 'whr' => 0.8, 'whtr' => 0.5 );
$why_old = array( 'distilled_why' => 'I want to see my kids grow up.', 'key_people' => array( array( 'name' => 'Sam' ) ), 'motivations' => array( 'travel' ), 'fears' => array( 'falling' ) );
$why_new = array( 'distilled_why' => 'I want to keep up with my grandchildren.', 'key_people' => array( array( 'name' => 'Tom' ) ), 'motivations' => array( 'Play on the floor' ), 'fears' => array() );
$draft_report = array( 'awaken_content' => 'a', 'lift_content' => 'l', 'thrive_content' => 't' );
$recs = array( array( 'category' => 'Move', 'text' => 'Walk', 'priority' => 'high', 'frequency' => 'daily' ) );

$prompts = array(
    'final'  => function ( $why ) use ( $calc, $draft_report, $recs ) { HDLV2_AI_Service::generate_final_report( $calc, $draft_report, array(), 'notes', $recs, $why, 'Ann', 58 ); return last_prompt(); },
    'pdf'    => function ( $why ) use ( $calc, $recs ) { HDLV2_AI_Service::generate_pdf_sections( $calc, $why, $recs, array(), array(), array(), 'notes', 'Ann', 'female', 58 ); return last_prompt(); },
    'notes'  => function ( $why ) use ( $s1, $calc ) { HDLV2_AI_Service::generate_flight_consult_notes( $s1, array( 'x' => 1 ), $calc, $why ); return last_prompt(); },
    'flight' => function ( $why ) { return HDLV2_Flight_Plan::why_text( $why ); },
);
// sha256 of each prompt for the old-flow WHY, taken on 0.48.7 (06f5ffc) with --snapshot.
$SNAP = array(
    'final'  => '21fd6b9765882e99be08471486405b3b71520489aceee9a5ff32e18e72c1503e',
    'pdf'    => '01c9e15a3b9d4077cd85bfe1c9ed61ec7c5ba88d75eb6bf72e66fd42caf78303',
    'notes'  => '426289e0f4aabbdf3d18be16a5b78d0ad3965d8d56d8c1343d6fdcd9f0e68b40',
    'flight' => 'dbd15a715186dd765e7291a149f25ab59d01dea5a3cc7955adfa4c48826d4067',
);
if ( in_array( '--snapshot', $argv ?? array(), true ) ) {
    foreach ( $prompts as $k => $f ) echo "    '$k' => '" . hash( 'sha256', $f( $why_old ) ) . "',\n";
    exit( 0 );
}

// ════════ S: one structured source ════════
$sum = HDLV2_Why_Picks::summary_for_row( $single, $s3_scores );
check( 'S1 summary keys', array( 'picks', 'abilities', 'focus_areas', 'people', 'own_words' ) === array_keys( $sum ) );
check( 'S2 picks are { label, group }, labels in pick order (0.48.10)', 5 === count( $sum['picks'] ) && HDLV2_Why_Picks::label_map( $PICKS ) === array_combine( $PICKS, array_column( $sum['picks'], 'label' ) )
    && array( 'label', 'group' ) === array_keys( $sum['picks'][0] ) );
$ab0 = $sum['abilities'][0] ?? array();
check( 'S3 each ability row = label, short, count, score, focus', array( 'label', 'short', 'count', 'score', 'focus' ) === array_keys( $ab0 ) );
$prof = HDLV2_Why_Picks::abilities_profile( $PICKS, HDLV2_Why_Picks::stage1_raw( $s1 ), $s3_scores );
check( 'S4 abilities = abilities_profile() (no new logic)', array_column( $prof, 'label' ) === array_column( $sum['abilities'], 'label' )
    && array_column( $prof, 'count' ) === array_column( $sum['abilities'], 'count' ) && array_column( $prof, 'focus' ) === array_column( $sum['abilities'], 'focus' ) );
$focus = array(); foreach ( $prof as $a ) if ( $a['focus'] ) $focus[] = $a['label'];
check( 'S5 focus areas = the focused abilities', $focus === $sum['focus_areas'] && count( $focus ) > 0 );
check( 'S6 people + own words as plain text', 'my husband "Tom" and the grandchildren' === $sum['people'] && 'I want to keep up with them.' === $sum['own_words'] );
check( 'S7 old-flow row: empty array', array() === HDLV2_Why_Picks::summary_for_row( $old, $s3_scores ) );
check( 'S8 junk row: empty array', array() === HDLV2_Why_Picks::summary_for_row( (object) array(), array() ) );

$pf = HDLV2_Why_Picks::payload_fields( $single, $s3_scores );
$KEYS5 = array( 'why_picks', 'why_abilities', 'why_focus_areas', 'why_people', 'why_own_words', 'why_picks_people', 'why_picks_freedom', 'why_picks_things' );
check( 'S9 payload fields: the eight keys (0.48.10), all strings', $KEYS5 === array_keys( $pf ) && count( array_filter( $pf, 'is_string' ) ) === 8 );
check( 'S10 why_picks joins the statements with " | "', implode( ' | ', array_column( $sum['picks'], 'label' ) ) === $pf['why_picks'] );
$first = $sum['abilities'][0];
check( 'S11 why_abilities reads "Label (count)" and marks focus areas', 0 === strpos( $pf['why_abilities'], $first['label'] . ' (' . $first['count'] )
    && substr_count( $pf['why_abilities'], ', focus area)' ) === count( $sum['focus_areas'] )
    && count( explode( ' | ', $pf['why_abilities'] ) ) === count( $sum['abilities'] ) );
check( 'S12 why_focus_areas csv', implode( ', ', $sum['focus_areas'] ) === $pf['why_focus_areas'] );
check( 'S13 old-flow payload fields: same eight keys, all empty', array_fill_keys( $KEYS5, '' ) === HDLV2_Why_Picks::payload_fields( $old, $s3_scores ) );

// ════════ B: 'facts' use of the block ════════
$facts = HDLV2_Why_Picks::block_for_row( $single, $s3_scores, 'facts' );
$draft = HDLV2_Why_Picks::block_for_row( $single, $s3_scores );
$ms    = HDLV2_Why_Picks::block_for_row( $single, $s3_scores, true );
check( 'B1 facts block names the choices, people and focus areas', false !== strpos( $facts, '=== WHAT THIS CLIENT CHOSE' )
    && false !== strpos( $facts, 'People they named:' ) && false !== strpos( $facts, 'Focus areas (most chosen, weakest measured):' ) );
check( 'B2 facts block carries no section instruction', false === strpos( $facts, 'LIFT' ) && false === strpos( $facts, 'THRIVE' ) && false === strpos( $facts, 'milestones' ) );
check( 'B3 facts block = milestones block minus its "Aim the milestones" line',
    $facts === preg_replace( "/Aim the milestones[^\n]*\n/", '', $ms ) );
check( 'B4 draft block still carries LIFT + THRIVE', false !== strpos( $draft, 'In LIFT,' ) && false !== strpos( $draft, 'Use the focus areas to decide what LIFT puts first.' ) );
check( 'B5 old-flow row: facts block is empty', '' === HDLV2_Why_Picks::block_for_row( $old, $s3_scores, 'facts' ) );

// ════════ P: the four prompts ════════
foreach ( $prompts as $k => $f ) {
    check( "P1 $k: old-flow prompt byte-identical to 0.48.7", $SNAP[ $k ] === hash( 'sha256', $f( $why_old ) ) );
    check( "P2 $k: an empty picks_block changes nothing", $f( $why_old ) === $f( $why_old + array( 'picks_block' => '' ) ) );
}
$use = array( 'final' => $draft, 'pdf' => $facts, 'notes' => $facts, 'flight' => $facts );
foreach ( $prompts as $k => $f ) {
    $with    = $f( $why_new + array( 'picks_block' => $use[ $k ] ) );
    $without = $f( $why_new );
    check( "P3 $k: choices-page client's prompt carries the block once", 1 === substr_count( $with, $use[ $k ] ) );
    check( "P4 $k: the block is the only difference", str_replace( $use[ $k ], '', $with ) === $without || str_replace( "\n" . $use[ $k ], "\n", $with ) === $without );
}
$fin = $prompts['final']( $why_new + array( 'picks_block' => $draft ) );
check( 'P5 final: block sits after the WHY and fears, before the output instructions',
    strpos( $fin, "CLIENT'S FEARS:" ) < strpos( $fin, '=== WHAT THIS CLIENT CHOSE' ) && strpos( $fin, '=== WHAT THIS CLIENT CHOSE' ) < strpos( $fin, '=== OUTPUT INSTRUCTIONS ===' ) );
$pdf = $prompts['pdf']( $why_new + array( 'picks_block' => $facts ) );
check( 'P6 pdf sections: block sits in WHY & CONTEXT, after Motivations',
    strpos( $pdf, 'Motivations:' ) < strpos( $pdf, '=== WHAT THIS CLIENT CHOSE' ) && strpos( $pdf, '=== WHAT THIS CLIENT CHOSE' ) < strpos( $pdf, 'Practitioner notes (full' ) );
$nt = $prompts['notes']( $why_new + array( 'picks_block' => $facts ) );
check( 'P7 consultation notes: block follows "Client WHY"', strpos( $nt, 'Client WHY:' ) < strpos( $nt, '=== WHAT THIS CLIENT CHOSE' ) );

// ════════ C: context builder → flight plan ════════
class CtxWpdb {
    public $prefix = 'wp_';
    public $why;
    public function prepare( $sql, ...$a ) { return $sql; }
    public function get_row( $sql ) { return false !== strpos( $sql, 'hdlv2_why_profiles' ) ? $this->why : null; }
    public function get_var( $sql ) { return null; }
    public function get_results( $sql ) { return array(); }
}
$GLOBALS['wpdb'] = new CtxWpdb();
$GLOBALS['wpdb']->why = (object) array( 'distilled_why' => 'Keep up', 'key_people' => '[]', 'motivations' => '[]', 'fears' => '[]',
    'stage1_data' => $single->stage1_data, 'stage2_data' => $single->stage2_data, 'stage3_data' => $single->stage3_data );
$ctx = HDLV2_Context_Builder::build_context( 9001, 1 );
check( 'C1 single-form WHY row: context why_profile carries the facts block for its own row', $facts === ( $ctx['why_profile']['picks_block'] ?? null ) );
$GLOBALS['wpdb']->why = (object) array( 'distilled_why' => 'Kids', 'key_people' => '[]', 'motivations' => '[]', 'fears' => '[]',
    'stage1_data' => $old->stage1_data, 'stage2_data' => $old->stage2_data, 'stage3_data' => $old->stage3_data );
$ctx = HDLV2_Context_Builder::build_context( 9001, 1 );
check( 'C2 old-flow WHY row: why_profile keys exactly as before', array( 'distilled_why', 'key_people', 'motivations', 'fears' ) === array_keys( $ctx['why_profile'] ) );

echo "\n" . ( $fail ? "WHY SOURCES: FAIL ($fail)\n" : "WHY SOURCES: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );
