<?php
/**
 * Single-form WHY picks — pure functions (v0.47.92, abilities v0.47.93).
 *
 * HDLV2_Why_Picks holds the catalogue, the switch check, validation, the
 * paragraph the WHY extraction reads and the draft-prompt block. No database.
 * v0.47.93: eight abilities, the ability → measure table (checked against
 * the REAL rate calculator), the abilities profile and the wider prompt block.
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
require __DIR__ . '/../../includes/sprint-2/class-hdlv2-rate-calculator.php';

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
$allowed = array( 'strength', 'mobility', 'flexibility', 'balance', 'stamina', 'mind', 'energy', 'connection' );
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

// ── Abilities (v0.47.93) ──
$needs_const  = defined( 'HDLV2_Why_Picks::NEEDS' ) ? HDLV2_Why_Picks::NEEDS : array();
$labels_const = defined( 'HDLV2_Why_Picks::ABILITY_LABELS' ) ? HDLV2_Why_Picks::ABILITY_LABELS : array();
$measures     = defined( 'HDLV2_Why_Picks::MEASURES' ) ? HDLV2_Why_Picks::MEASURES : array();
$indirect     = defined( 'HDLV2_Why_Picks::INDIRECT' ) ? HDLV2_Why_Picks::INDIRECT : null;
check( 'A1 NEEDS is the eight abilities in order', $allowed === $needs_const );
check( 'A2 every ability has its display name', array(
    'strength' => 'Strength', 'mobility' => 'Mobility', 'flexibility' => 'Flexibility', 'balance' => 'Balance',
    'stamina' => 'Stamina', 'mind' => 'A sharp mind', 'energy' => 'Energy and sleep', 'connection' => 'Connection',
) === $labels_const );
$needs_of = array(); $labels_all = array(); $other_tags = array();
$retagged = array(
    'floor_grandkids'    => array( 'mobility', 'flexibility', 'strength' ),
    'dress_myself'       => array( 'flexibility', 'balance' ),
    'reach_top_cupboard' => array( 'flexibility', 'strength' ),
    'keep_driving'       => array( 'mind', 'flexibility' ),
    'sit_on_floor'       => array( 'flexibility', 'mobility' ),
);
foreach ( $cat as $g ) {
    foreach ( $g['items'] as $it ) {
        $needs_of[ $it['id'] ] = $it['needs'];
        $labels_all[]          = $it['label'];
        if ( ! isset( $retagged[ $it['id'] ] ) ) $other_tags[] = $it['id'] . ':' . implode( ',', $it['needs'] );
    }
}
check( 'A3 the five retagged statements carry exactly the new tags', $retagged === array_intersect_key( $needs_of, $retagged ) );
check( 'A4 ids unchanged from slice 1 (pinned list, same order)',
    'floor_grandkids lift_grandchild keep_up_children dance_wedding host_family_meal walk_with_partner carer_not_cared_for remember_family teach_grandchild present_big_moments '
    . 'stairs_no_rail carry_shopping up_from_low_seat dress_myself bath_shower_alone reach_top_cupboard keep_driving manage_own_affairs live_in_own_home up_after_fall '
    . 'lift_suitcase walk_city_all_day long_flight hike_hills swim_in_sea uneven_ground trip_short_notice ride_bicycle '
    . 'keep_my_sport day_in_garden run_for_bus dance_regularly do_own_diy walk_dog_daily sit_on_floor steady_hands_craft intimate_life '
    . 'sharp_conversation keep_working learn_something_new read_for_an_hour volunteer_mentor never_lost_for_words write_life_story '
    . 'wake_rested energy_in_evening move_without_aches see_friends_weekly steady_in_body calm_under_pressure' === implode( ' ', $ids ) );
check( 'A5 labels unchanged from slice 1 (pinned hash)', 'dd4c54023ebafeb7904b6e6b85d41987' === md5( implode( "\n", $labels_all ) ) );
check( 'A6 the other 45 statements keep their slice 1 tags (pinned hash)', '89eb41aac9e4ede3cb69a52be045e0ca' === md5( implode( '|', $other_tags ) ) );

// ── The ability → measure table (draft for Matthew) ──
$m = function ( $source, $key, $label ) { return array( 'source' => $source, 'key' => $key, 'label' => $label ); };
check( 'M1 MEASURES is exactly the agreed table', array(
    'strength'    => array( $m( 'stage3', 'sitToStand', 'Chair stand (30 seconds)' ), $m( 'stage1', 'q5_sts', 'Getting up from the floor' ) ),
    'mobility'    => array( $m( 'stage1', 'q5_sts', 'Getting up from the floor' ) ),
    'flexibility' => array( $m( 'stage1', 'q5_sts', 'Getting up from the floor' ) ),
    'balance'     => array( $m( 'stage3', 'balance', 'Standing on one leg' ) ),
    'stamina'     => array( $m( 'stage3', 'physicalActivity', 'Physical activity' ), $m( 'stage1', 'q4_vo2', 'Climbing stairs' ), $m( 'stage3', 'heartRateScore', 'Resting heart rate' ) ),
    'mind'        => array( $m( 'stage3', 'cognitiveActivity', 'Mental activity' ), $m( 'stage3', 'stressLevels', 'Stress' ), $m( 'stage3', 'sleepQuality', 'Sleep quality' ) ),
    'energy'      => array( $m( 'stage3', 'sleepDuration', 'Sleep duration' ), $m( 'stage3', 'sleepQuality', 'Sleep quality' ), $m( 'stage3', 'dietQuality', 'Diet' ) ),
    'connection'  => array( $m( 'stage3', 'socialConnections', 'Time with people' ) ),
) === $measures );
check( 'M2 only flexibility is marked indirect', array( 'flexibility' ) === $indirect );
// The accuracy guard: every key the table names must exist in what the REAL
// calculator returns. A renamed score breaks this test, not the report.
$sample = array(
    'q1_age' => 58, 'q1_sex' => 'male', 'q2a' => 2, 'q2b' => 'even',
    'q3' => 'c', 'q4' => 'd', 'q5' => 'b', 'q6' => 'd', 'q7' => 'e', 'q8' => 'c', 'q9' => 'c',
    'height' => 178, 'weight' => 82, 'waist' => 94, 'hip' => 100, 'bpSystolic' => 128, 'bpDiastolic' => 82, 'restingHeartRate' => 64,
    'skinElasticity' => 3, 'physicalActivity' => 4, 'sleepDuration' => 4, 'sleepQuality' => 2, 'stressLevels' => 3,
    'socialConnections' => 5, 'dietQuality' => 3, 'alcoholConsumption' => 4, 'smokingStatus' => 5, 'cognitiveActivity' => 2,
    'sunlightExposure' => 3, 'supplementIntake' => 2, 'dailyHydration' => 3, 'sitToStand' => 3, 'breathHold' => 3, 'balance' => 1,
);
$real_scores = HDLV2_Rate_Calculator::calculate_full( 58, $sample, 'male' )['scores'];
$real_raw    = HDLV2_Rate_Calculator::calculate_quick( $sample )['raw'];
$missing = array();
foreach ( $measures as $ability => $list ) {
    foreach ( $list as $one ) {
        $have = 'stage3' === $one['source'] ? $real_scores : ( 'stage1' === $one['source'] ? $real_raw : array() );
        if ( ! array_key_exists( $one['key'], $have ) ) $missing[] = "$ability:{$one['source']}.{$one['key']}";
    }
}
if ( $missing ) echo '      missing in calculator: ' . implode( ', ', $missing ) . "\n";
check( 'M3 every stage3 key is a real calculate_full() score and every stage1 key a real calculate_quick() raw score', $measures && ! $missing );
check( 'M4 every ability has at least one measure', array_keys( $measures ) === $allowed && ! in_array( 0, array_map( 'count', $measures ), true ) );

// ── abilities_profile (hand-worked) ──
$has_profile = method_exists( 'HDLV2_Why_Picks', 'abilities_profile' );
$profile = function ( $picks, $raw = array(), $scores = array() ) use ( $has_profile ) {
    return $has_profile ? HDLV2_Why_Picks::abilities_profile( $picks, $raw, $scores ) : null;
};
$six    = array( 'floor_grandkids', 'lift_grandchild', 'walk_city_all_day', 'hike_hills', 'remember_family', 'wake_rested' );
$raw1   = array( 'q5_sts' => 2, 'q4_vo2' => 4 );
$score3 = array( 'sitToStand' => 3, 'balance' => 1, 'physicalActivity' => 4, 'heartRateScore' => null, 'cognitiveActivity' => 2,
    'stressLevels' => 3, 'sleepQuality' => 2, 'sleepDuration' => 4, 'dietQuality' => 3, 'socialConnections' => 5 );
$ms = function ( $label, $score, $source ) { return array( 'label' => $label, 'score' => $score, 'source' => $source ); };
$ab = function ( $id, $label, $count, $score, $measures, $indirect, $focus ) {
    return array( 'id' => $id, 'label' => $label, 'count' => $count, 'score' => $score, 'measures' => $measures, 'indirect' => $indirect, 'focus' => $focus );
};
$floor = $ms( 'Getting up from the floor', 2, 'stage1' );
// By hand: strength (3+2)/2 = 2.5, 2 choices → 2×2.5 = 5.0 · mobility 2.0, 1 → 3.0 ·
// flexibility 2.0, 1 → 3.0 · balance 1.0, 2 → 8.0 · stamina (4+4)/2 = 4.0 (heart
// rate missing, left out) → above 3, never a focus · mind (2+3+2)/3 = 2.3, 1 → 2.7 ·
// energy (4+2+3)/3 = 3.0, 1 → 2.0. Focus = balance, strength, then mobility
// (tie with flexibility, ability order); the cap of three leaves the rest out.
$expected_profile = array(
    $ab( 'strength', 'Strength', 2, 2.5, array( $ms( 'Chair stand (30 seconds)', 3, 'stage3' ), $floor ), false, true ),
    $ab( 'mobility', 'Mobility', 1, 2.0, array( $floor ), false, true ),
    $ab( 'flexibility', 'Flexibility', 1, 2.0, array( $floor ), true, false ),
    $ab( 'balance', 'Balance', 2, 1.0, array( $ms( 'Standing on one leg', 1, 'stage3' ) ), false, true ),
    $ab( 'stamina', 'Stamina', 2, 4.0, array( $ms( 'Physical activity', 4, 'stage3' ), $ms( 'Climbing stairs', 4, 'stage1' ), $ms( 'Resting heart rate', null, 'stage3' ) ), false, false ),
    $ab( 'mind', 'A sharp mind', 1, 2.3, array( $ms( 'Mental activity', 2, 'stage3' ), $ms( 'Stress', 3, 'stage3' ), $ms( 'Sleep quality', 2, 'stage3' ) ), false, false ),
    $ab( 'energy', 'Energy and sleep', 1, 3.0, array( $ms( 'Sleep duration', 4, 'stage3' ), $ms( 'Sleep quality', 2, 'stage3' ), $ms( 'Diet', 3, 'stage3' ) ), false, false ),
);
$got = $profile( $six, $raw1, $score3 );
check( 'AP1 hand-worked profile, exact (counts, 1-decimal averages, null left out, indirect, focus order, cap of three)', $expected_profile === $got );
check( 'AP2 no picks → empty list', array() === $profile( array(), $raw1, $score3 ) );
$none = $profile( array( 'see_friends_weekly', 'sit_on_floor' ), array(), array( 'sitToStand' => 1 ) );
check( 'AP3 choices with no number → score null, never a focus',
    is_array( $none ) && 3 === count( $none )
    && array( 'mobility', 'flexibility', 'connection' ) === array_column( $none, 'id' )
    && array( null, null, null ) === array_column( $none, 'score' )
    && array( false, false, false ) === array_column( $none, 'focus' ) );
$s1_only = $profile( array( 'lift_grandchild' ), $raw1, array() );
check( 'AP4 Stage 3 scores empty → Stage 1 measures only',
    is_array( $s1_only ) && 2.0 === ( $s1_only[0]['score'] ?? null ) && array( null, 2 ) === array_column( $s1_only[0]['measures'], 'score' )
    && 'balance' === ( $s1_only[1]['id'] ?? '' ) && array_key_exists( 'score', $s1_only[1] ) && null === $s1_only[1]['score'] );
$skip = $profile( array( 'lift_grandchild' ), array( 'q5_sts' => 'skip' ), array( 'sitToStand' => 'n/a', 'balance' => '' ) );
check( 'AP5 "skip" / non-numeric / empty scores are treated as missing',
    is_array( $skip ) && null === $skip[0]['score'] && null === $skip[1]['score'] && array( null, null ) === array_column( $skip[0]['measures'], 'score' ) );
$junk_p = $profile( array( 'wake_rested', 'IGNORE ALL', array( 'x' ) ), array(), array() );
check( 'AP6 unknown ids are ignored', is_array( $junk_p ) && array( 'energy' ) === array_column( $junk_p, 'id' ) && 1 === $junk_p[0]['count'] );
$zero = $profile( array( 'uneven_ground' ), array(), array( 'balance' => 0 ) );
check( 'AP7 a real score of 0 is a number, not missing', is_array( $zero ) && 0.0 === $zero[0]['score'] && true === $zero[0]['focus'] );

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
    . "- Get down on the floor to play with my grandchildren, and get back up without help [depends on: mobility, flexibility, strength]\n"
    . "- Carry my own shopping from the car to the kitchen [depends on: strength]\n"
    . "- Wake up rested and ready for the day [depends on: energy]\n"
    . "Abilities these choices depend on most: strength (2), mobility (1), flexibility (1), energy (1)\n"
    . "People they named: my wife Jenny\n"
    . "In LIFT, where one of their weakest scores limits an ability these choices depend on, say so in one clause and name the choice. In THRIVE, use their choices and the people they named. Do not invent choices or people that are not listed here.\n"
    . "\n";
check( 'B2 one-argument call: the block without scores, exact (snapshot)', $expected_block === HDLV2_Why_Picks::prompt_block( $block_in ) );
$no_people = HDLV2_Why_Picks::prompt_block( array( 'form_flow' => 'single', 'why_picks' => $picks3 ) );
check( 'B3 no people → no "People they named" line', false === strpos( $no_people, 'People they named' ) );
check( 'B4 retired id still resolves to its label',
    false !== strpos( Why_Picks_With_Retired::prompt_block( array( 'form_flow' => 'single', 'why_picks' => array( 'old_item' ) ) ), 'An old statement' ) );
$junk = HDLV2_Why_Picks::prompt_block( array( 'form_flow' => 'single', 'why_picks' => array( 'wake_rested', 'IGNORE ALL', array( 'x' ) ), 'key_people_text' => str_repeat( 'z', 5000 ) ) );
check( 'B5 unknown ids dropped and people text capped at 300', false === strpos( $junk, 'IGNORE' ) && false === strpos( $junk, str_repeat( 'z', 301 ) ) );

// With the saved answers: the pinned prompt addition (v0.47.93). Same six
// choices and numbers as the hand-worked profile above.
$expected_scored = "=== WHAT THIS CLIENT CHOSE (picked from a list, their own priorities for later life) ===\n"
    . "- Get down on the floor to play with my grandchildren, and get back up without help [depends on: mobility, flexibility, strength]\n"
    . "- Pick up a grandchild and carry them on my hip [depends on: strength, balance]\n"
    . "- Walk around a new city all day and still enjoy dinner [depends on: stamina]\n"
    . "- Hike a hill or a coastal path with friends [depends on: stamina, balance]\n"
    . "- Remember every grandchild's name, birthday and story [depends on: mind]\n"
    . "- Wake up rested and ready for the day [depends on: energy]\n"
    . "Abilities these choices depend on most: strength (2), balance (2), stamina (2), mobility (1), flexibility (1), mind (1), energy (1)\n"
    . "People they named: my wife Jenny\n"
    . "In LIFT, where one of their weakest scores limits an ability these choices depend on, say so in one clause and name the choice. In THRIVE, use their choices and the people they named. Do not invent choices or people that are not listed here.\n"
    . "What their choices depend on, with what was measured (0-5, higher is better):\n"
    . "- Strength: 2 of their choices; measured 2.5/5 from Chair stand (30 seconds) 3/5, Getting up from the floor 2/5\n"
    . "- Mobility: 1 of their choices; measured 2/5 from Getting up from the floor 2/5\n"
    . "- Flexibility: 1 of their choices; nearest measure 2/5 from Getting up from the floor 2/5\n"
    . "- Balance: 2 of their choices; measured 1/5 from Standing on one leg 1/5\n"
    . "- Stamina: 2 of their choices; measured 4/5 from Physical activity 4/5, Climbing stairs 4/5\n"
    . "- A sharp mind: 1 of their choices; measured 2.3/5 from Mental activity 2/5, Stress 3/5, Sleep quality 2/5\n"
    . "- Energy and sleep: 1 of their choices; measured 3/5 from Sleep duration 4/5, Sleep quality 2/5, Diet 3/5\n"
    . "Focus areas (most chosen, weakest measured): Balance, Strength, Mobility\n"
    . "Use the focus areas to decide what LIFT puts first and what the milestones aim at. Name the choice each one serves. Do not quote these ability scores as if they were one of the 21 health scores; they are averages for your guidance.\n"
    . "\n";
$scored_in = array( 'form_flow' => 'single', 'why_picks' => $six, 'key_people_text' => 'my wife Jenny' );
check( 'B6 prompt block with the saved answers, exact (snapshot)', $expected_scored === HDLV2_Why_Picks::prompt_block( $scored_in, $raw1, $score3 ) );
check( 'B7 no picks → still empty with scores passed', '' === HDLV2_Why_Picks::prompt_block( array(), $raw1, $score3 )
    && '' === HDLV2_Why_Picks::prompt_block( array( 'why_picks' => $six ), $raw1, $score3 ) );
$unmeasured = HDLV2_Why_Picks::prompt_block( array( 'form_flow' => 'single', 'why_picks' => array( 'see_friends_weekly', 'sit_on_floor' ) ), array(), array( 'sitToStand' => 4 ) );
check( 'B8 an ability with no number says so, and no focus line is invented',
    false !== strpos( $unmeasured, "- Flexibility: 1 of their choices; not measured by the questionnaire\n" )
    && false !== strpos( $unmeasured, "- Connection: 1 of their choices; not measured by the questionnaire\n" )
    && false === strpos( $unmeasured, 'Focus areas' ) && false === strpos( $unmeasured, '/5' ) );

// ── options for the page ──
$opts = HDLV2_Why_Picks::options_for_page();
$flat = array();
foreach ( $opts as $g ) foreach ( $g['items'] as $it ) $flat[] = $it;
check( 'O1 page options carry id + label + needs, grouped', isset( $opts[0]['title'] ) && array( 'id', 'label', 'needs' ) === array_keys( $flat[0] ) && 50 === count( $flat ) );
check( 'O3 every group has its short tile title',
    array( 'Family', 'Independence', 'Travel', 'Sport and hobbies', 'Mind and purpose', 'Everyday energy' ) === array_column( $opts, 'short' )
    && array( 'Family', 'Independence', 'Travel', 'Sport and hobbies', 'Mind and purpose', 'Everyday energy' ) === array_column( $cat, 'short' ) );
$needs_ok = true;
foreach ( $flat as $it ) {
    if ( ( $it['needs'] ?? null ) !== ( $needs_of[ $it['id'] ] ?? array() ) ) $needs_ok = false;
}
check( 'O4 every item carries its needs (the catalogue\'s tags)', $needs_ok );
$ret = Why_Picks_With_Retired::options_for_page();
check( 'O2 retired items are not offered', ! in_array( 'old_item', array_column( $ret[0]['items'], 'id' ), true ) );

echo "\n" . ( $fail ? "SCENARIO: FAIL ($fail)\n" : "SCENARIO: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );
