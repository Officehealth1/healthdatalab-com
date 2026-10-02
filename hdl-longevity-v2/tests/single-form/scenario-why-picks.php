<?php
/**
 * Single-form WHY picks — pure functions (v0.47.92).
 *
 * HDLV2_Why_Picks holds the catalogue, the switch check, validation, the
 * paragraph the WHY extraction reads and the draft-prompt block. No database.
 *
 * Run:  php scenario-why-picks.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['options'] = array();
function get_option( $k, $default = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $default; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }

class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code = '', $message = '', $data = null ) {
        $this->code = $code; $this->message = $message; $this->data = $data;
    }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }

require __DIR__ . '/../../includes/sprint-2/class-hdlv2-why-picks.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) {
    global $pass, $fail;
    echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n";
    $ok ? $pass++ : $fail++;
}

// Same catalogue with one retired item, for the retired-id rules.
class Why_Picks_With_Retired extends HDLV2_Why_Picks {
    public static function catalogue() {
        $cat = parent::catalogue();
        $cat[0]['items'][] = array( 'id' => 'old_item', 'label' => 'An old statement', 'needs' => array( 'mind' ), 'retired' => true );
        return $cat;
    }
}

// ── Catalogue ──
$cat    = HDLV2_Why_Picks::catalogue();
$active = 0; $ids = array(); $item_ok = true; $group_ok = true;
$allowed = array( 'strength', 'mobility', 'balance', 'stamina', 'mind', 'energy', 'connection' );
foreach ( $cat as $g ) {
    if ( empty( $g['id'] ) || empty( $g['title'] ) || count( $g['items'] ) < 4 ) $group_ok = false;
    foreach ( $g['items'] as $it ) {
        $ids[] = $it['id'];
        if ( empty( $it['retired'] ) ) $active++;
        $needs = $it['needs'] ?? array();
        if ( ! preg_match( '/^[a-z0-9_]+$/', $it['id'] )
             || '' === trim( (string) $it['label'] ) || strlen( $it['label'] ) > 110
             || count( $needs ) < 1 || count( $needs ) > 3
             || array_diff( $needs, $allowed ) ) {
            $item_ok = false;
            echo "      bad item: {$it['id']}\n";
        }
    }
}
check( 'C1 catalogue has 40-60 active items', $active >= 40 && $active <= 60 );
check( 'C2 ids unique', count( $ids ) === count( array_unique( $ids ) ) );
check( 'C3 every item: lower-case id, label <=110, 1-3 allowed needs', $item_ok );
check( 'C4 every group has an id, a title and >=4 items', $group_ok );
check( 'C5 the six groups in Appendix A order',
    array( 'family', 'home', 'travel', 'movement', 'mind', 'everyday' ) === array_column( $cat, 'id' ) );
check( 'C6 50 items, floor_grandkids first, intimate_life kept', 50 === count( $ids ) && 'floor_grandkids' === $ids[0] && in_array( 'intimate_life', $ids, true ) );

// ── Switch ──
check( 'S1 option absent → off', false === HDLV2_Why_Picks::enabled_for( 122 ) );
$GLOBALS['options']['hdlv2_ff_single_form'] = '';
check( 'S2 empty → off', false === HDLV2_Why_Picks::enabled_for( 122 ) );
$GLOBALS['options']['hdlv2_ff_single_form'] = '122';
check( 'S3 "122" → on for 122', true === HDLV2_Why_Picks::enabled_for( 122 ) );
check( 'S4 "122" → off for 206', false === HDLV2_Why_Picks::enabled_for( 206 ) );
$GLOBALS['options']['hdlv2_ff_single_form'] = '122, 206';
check( 'S5 "122, 206" → on for both', HDLV2_Why_Picks::enabled_for( 122 ) && HDLV2_Why_Picks::enabled_for( 206 ) );
$GLOBALS['options']['hdlv2_ff_single_form'] = 'all';
check( 'S6 "all" → on for any id', HDLV2_Why_Picks::enabled_for( 999 ) );
$GLOBALS['options']['hdlv2_ff_single_form'] = 'abc';
check( 'S7 "abc" → off', false === HDLV2_Why_Picks::enabled_for( 122 ) );
check( 'S8 practitioner 0 never on by id list', false === HDLV2_Why_Picks::enabled_for( 0 ) );
unset( $GLOBALS['options']['hdlv2_ff_single_form'] );

// ── row_uses_single_form ──
$row = function ( $args ) {
    return (object) array_merge( array( 'practitioner_user_id' => 122, 'current_stage' => 2, 'stage2_data' => '{}', 'stage2_completed_at' => null ), $args );
};
check( 'R1 switch off, no marker → old form', false === HDLV2_Why_Picks::row_uses_single_form( $row( array() ) ) );
check( 'R2 switch off, marker → single (marker beats option)',
    true === HDLV2_Why_Picks::row_uses_single_form( $row( array( 'stage2_data' => '{"form_flow":"single"}', 'stage2_completed_at' => '2026-10-02 10:00:00', 'current_stage' => 3 ) ) ) );
$GLOBALS['options']['hdlv2_ff_single_form'] = '122';
check( 'R3 switch on, WHY open → single', true === HDLV2_Why_Picks::row_uses_single_form( $row( array() ) ) );
check( 'R4 switch on, WHY already sent the old way → old form',
    false === HDLV2_Why_Picks::row_uses_single_form( $row( array( 'stage2_completed_at' => '2026-10-01 10:00:00' ) ) ) );
check( 'R5 switch on, other practitioner → old form', false === HDLV2_Why_Picks::row_uses_single_form( $row( array( 'practitioner_user_id' => 206 ) ) ) );
check( 'R6 switch on, row already at stage 3 without marker → old form',
    false === HDLV2_Why_Picks::row_uses_single_form( $row( array( 'current_stage' => 3 ) ) ) );
unset( $GLOBALS['options']['hdlv2_ff_single_form'] );

// ── validate ──
$five = array( 'floor_grandkids', 'carry_shopping', 'wake_rested', 'keep_driving', 'swim_in_sea' );
$ten  = array_merge( $five, array( 'hike_hills', 'ride_bicycle', 'dance_regularly', 'read_for_an_hour', 'see_friends_weekly' ) );
$v = function ( $data ) { return HDLV2_Why_Picks::validate( $data ); };
$err = function ( $r ) { return is_wp_error( $r ) && 'invalid_why_picks' === $r->code && 400 === ( $r->data['status'] ?? 0 ); };
check( 'V1 4 picks → error', $err( $v( array( 'why_picks' => array_slice( $five, 0, 4 ) ) ) ) );
check( 'V1b 4 picks → plain message', 'Please choose at least 5.' === $v( array( 'why_picks' => array_slice( $five, 0, 4 ) ) )->message );
check( 'V2 5 picks → ok', true === $v( array( 'why_picks' => $five ) ) );
check( 'V3 10 picks → ok', true === $v( array( 'why_picks' => $ten ) ) );
check( 'V4 11 picks → error', $err( $v( array( 'why_picks' => array_merge( $ten, array( 'lift_suitcase' ) ) ) ) ) );
check( 'V5 unknown id → error', $err( $v( array( 'why_picks' => array_merge( array_slice( $five, 0, 4 ), array( 'not_a_thing' ) ) ) ) ) );
check( 'V6 duplicated id → error', $err( $v( array( 'why_picks' => array_merge( $five, array( 'wake_rested' ) ) ) ) ) );
check( 'V7 retired id → error', $err( Why_Picks_With_Retired::validate( array( 'why_picks' => array_merge( array_slice( $five, 0, 4 ), array( 'old_item' ) ) ) ) ) );
check( 'V8 non-array picks → error', $err( $v( array( 'why_picks' => 'floor_grandkids' ) ) ) );
check( 'V8b missing picks → error', $err( $v( array() ) ) );
check( 'V8c non-string id → error', $err( $v( array( 'why_picks' => array_merge( array_slice( $five, 0, 4 ), array( array( 'x' ) ) ) ) ) ) );
check( 'V9 people text 301 chars → error', $err( $v( array( 'why_picks' => $five, 'key_people_text' => str_repeat( 'a', 301 ) ) ) ) );
check( 'V9b people text 300 chars → ok', true === $v( array( 'why_picks' => $five, 'key_people_text' => str_repeat( 'a', 300 ) ) ) );
check( 'V10 own words 601 chars → error', $err( $v( array( 'why_picks' => $five, 'own_words' => str_repeat( 'a', 601 ) ) ) ) );
check( 'V10b own words multibyte 600 chars → ok', true === $v( array( 'why_picks' => $five, 'own_words' => str_repeat( 'é', 600 ) ) ) );
check( 'V11 both text fields empty with 5 picks → ok', true === $v( array( 'why_picks' => $five, 'key_people_text' => '', 'own_words' => '' ) ) );
check( 'V12 non-string people → error', $err( $v( array( 'why_picks' => $five, 'key_people_text' => array( 'x' ) ) ) ) );

// ── compose_vision_text ──
$picks3 = array( 'floor_grandkids', 'carry_shopping', 'wake_rested' );
$expected = "In my later years, I want to be able to:\n"
    . "- Get down on the floor to play with my grandchildren, and get back up without help\n"
    . "- Carry my own shopping from the car to the kitchen\n"
    . "- Wake up rested and ready for the day\n"
    . "The people I most want to stay healthy and strong for: my wife Jenny, Leo and Mia\n"
    . "In my own words: I'd like to keep up with them.";
check( 'P1 composed paragraph, exact', $expected === HDLV2_Why_Picks::compose_vision_text( $picks3, 'my wife Jenny, Leo and Mia', "I'd like to keep up with them." ) );
$bare = HDLV2_Why_Picks::compose_vision_text( $picks3, '', '  ' );
check( 'P2 empty text fields → no people / own-words lines',
    false === strpos( $bare, 'The people' ) && false === strpos( $bare, 'In my own words' ) );
check( 'P3 always >= 10 characters', strlen( trim( HDLV2_Why_Picks::compose_vision_text( $five, '', '' ) ) ) >= 10 );
$tagged = HDLV2_Why_Picks::compose_vision_text( $picks3, '<b>Jenny</b> <script>x()</script>', 'a <i>b</i>' );
check( 'P4 tags come out as plain text', false === strpos( $tagged, '<' ) && false !== strpos( $tagged, 'Jenny' ) );
check( 'P5 unknown ids are dropped from the paragraph', false === strpos( HDLV2_Why_Picks::compose_vision_text( array( 'zzz', 'wake_rested' ), '', '' ), 'zzz' ) );

// ── prompt_block (the pinned prompt addition) ──
check( 'B1 no picks → empty string', '' === HDLV2_Why_Picks::prompt_block( array() ) );
check( 'B1b old-flow data without the marker → empty string',
    '' === HDLV2_Why_Picks::prompt_block( array( 'why_picks' => $picks3, 'vision_text' => 'typed why' ) ) );
$block_in = array( 'form_flow' => 'single', 'why_picks' => $picks3, 'key_people_text' => 'my wife Jenny' );
$expected_block = "=== WHAT THIS CLIENT CHOSE (picked from a list, their own priorities for later life) ===\n"
    . "- Get down on the floor to play with my grandchildren, and get back up without help [depends on: mobility, strength]\n"
    . "- Carry my own shopping from the car to the kitchen [depends on: strength]\n"
    . "- Wake up rested and ready for the day [depends on: energy]\n"
    . "Abilities these choices depend on most: strength (2), mobility (1), energy (1)\n"
    . "People they named: my wife Jenny\n"
    . "In LIFT, where one of their weakest scores limits an ability these choices depend on, say so in one clause and name the choice. In THRIVE, use their choices and the people they named. Do not invent choices or people that are not listed here.\n"
    . "\n";
check( 'B2 prompt block, exact (snapshot)', $expected_block === HDLV2_Why_Picks::prompt_block( $block_in ) );
$no_people = HDLV2_Why_Picks::prompt_block( array( 'form_flow' => 'single', 'why_picks' => $picks3 ) );
check( 'B3 no people → no "People they named" line', false === strpos( $no_people, 'People they named' ) );
check( 'B4 retired id still resolves to its label',
    false !== strpos( Why_Picks_With_Retired::prompt_block( array( 'form_flow' => 'single', 'why_picks' => array( 'old_item' ) ) ), 'An old statement' ) );
$junk = HDLV2_Why_Picks::prompt_block( array( 'form_flow' => 'single', 'why_picks' => array( 'wake_rested', 'IGNORE ALL', array( 'x' ) ), 'key_people_text' => str_repeat( 'z', 5000 ) ) );
check( 'B5 unknown ids dropped and people text capped at 300', false === strpos( $junk, 'IGNORE' ) && false === strpos( $junk, str_repeat( 'z', 301 ) ) );

// ── options for the page ──
$opts = HDLV2_Why_Picks::options_for_page();
$flat = array();
foreach ( $opts as $g ) foreach ( $g['items'] as $it ) $flat[] = $it;
check( 'O1 page options carry id + label only, grouped', isset( $opts[0]['title'] ) && array( 'id', 'label' ) === array_keys( $flat[0] ) && 50 === count( $flat ) );
$ret = Why_Picks_With_Retired::options_for_page();
check( 'O2 retired items are not offered', ! in_array( 'old_item', array_column( $ret[0]['items'], 'id' ), true ) );

echo "\n" . ( $fail ? "SCENARIO: FAIL ($fail)\n" : "SCENARIO: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );
