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

// ── Catalogue (v0.48.0: 24 short statements in three groups) ──
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
             || count( $needs ) < 1 || count( $needs ) > 4
             || array_diff( $needs, $allowed ) ) {
            $item_ok = false;
            echo "      bad item: {$it['id']}\n";
        }
    }
}
check( 'C1 catalogue has 21 active items (v0.48.9)', 21 === $active );
check( 'C2 ids unique', count( $ids ) === count( array_unique( $ids ) ) );
check( 'C3 every item: lower-case id, label <=110, 1-4 allowed needs (v0.48.9: help_loved_one carries 4)', $item_ok );
check( 'C4 every group has an id, a title and >=4 items', $group_ok );
check( 'C5 the three offered groups come first, in order', array( 'people', 'freedom', 'things' ) === array_slice( array_column( $cat, 'id' ), 0, 3 ) );
check( 'C6 55 rows (v0.48.9: 2 added, none deleted), 34 retired', 55 === count( $ids ) && 34 === count( $ids ) - $active );

// The 21 on the page, exactly (Design 1 of the 0.48.9 plan, Matthew's wording
// of 2026-10-07): id, wording, tags.
$OFFERED = array(
    'people' => array( 'People I love', array(
        array( 'floor_grandkids', 'Play on the floor with my grandkids', 'mobility,flexibility,strength' ),
        array( 'lift_grandchild', 'Pick up a grandchild for a hug', 'strength,balance' ),
        array( 'fetch_with_dog', 'Play fetch with the dog in the park', 'stamina' ),
        array( 'dance_wedding', 'Dance with my grandkids at their wedding', 'stamina,balance,connection' ),
        array( 'remember_family', 'Remember every grandchild\'s name and story', 'mind' ),
        array( 'help_loved_one', 'Help a loved one who has hurt themselves', 'strength,balance,mobility,connection' ),
    ) ),
    'freedom' => array( 'My freedom', array(
        array( 'not_a_burden', 'Never burden loved ones in my last decade', 'strength,balance,mind' ),
        array( 'live_in_own_home', 'Stay in my own home, on my terms', 'strength,balance,mind' ),
        array( 'stairs_no_rail', 'Walk anywhere and climb stairs with confidence', 'strength,balance' ),
        array( 'up_after_fall', 'Feel robust and get back up by myself if I trip', 'strength,mobility' ),
        array( 'keep_driving', 'Drive safely', 'mind,flexibility' ),
        array( 'manage_own_affairs', 'Stay sharp and run my life with enthusiasm', 'mind,energy' ),
        array( 'dress_myself', 'Wash and dress myself, with dignity', 'flexibility,balance,mobility' ),
        array( 'uneven_ground', 'Live without fear of falling', 'balance' ),
    ) ),
    'things' => array( 'Things I love', array(
        array( 'keep_my_sport', 'Keep playing the sport I love', 'mobility,strength,balance' ),
        array( 'day_in_garden', 'Spend a whole day pottering in my garden', 'strength,mobility' ),
        array( 'hike_hills', 'Hike the hills with friends', 'stamina,balance,connection' ),
        array( 'swim_in_sea', 'Swim in the sea', 'stamina,strength' ),
        array( 'walk_city_all_day', 'Explore new places', 'stamina' ),
        array( 'keep_working', 'Keep doing work that matters to me', 'mind,energy' ),
        array( 'wake_rested', 'Wake up full of energy', 'energy' ),
    ) ),
);
$want_opts = array();
foreach ( $OFFERED as $gid => $g ) {
    $items = array();
    foreach ( $g[1] as $it ) $items[] = array( 'id' => $it[0], 'label' => $it[1], 'needs' => explode( ',', $it[2] ) );
    $want_opts[] = array( 'id' => $gid, 'title' => $g[0], 'items' => $items );
}
$got_opts = array_map( function ( $g ) {
    return array( 'id' => $g['id'], 'title' => $g['title'], 'items' => $g['items'] );
}, HDLV2_Why_Picks::options_for_page() );
check( 'C7 the page gets exactly the three groups and 21 statements of the plan (ids, order, wording, tags)', $want_opts === $got_opts );
$longest = 0;
foreach ( $got_opts as $g ) foreach ( $g['items'] as $it ) $longest = max( $longest, str_word_count( $it['label'], 0, "'" ) );
check( 'C8 no offered statement is longer than 11 words (Matthew\'s longest), 21 offered', $longest > 0 && $longest <= 11 && 21 === count( array_merge( ...array_column( $got_opts, 'items' ) ) ) );

// Every other row keeps its old wording and tags, retired (pinned hash of
// id|label|tags in the 0.47.99 catalogue order).
$retired_rows = array();
foreach ( $cat as $g ) foreach ( $g['items'] as $it ) {
    if ( ! empty( $it['retired'] ) ) $retired_rows[] = $it['id'] . '|' . $it['label'] . '|' . implode( ',', $it['needs'] );
}
check( 'C9 the first 29 retired rows carry their 0.47.99 wording and tags, in the old order (pinned hash)',
    34 === count( $retired_rows ) && 'c086997fac4f11f66d1eb59d42520c2b' === md5( implode( "\n", array_slice( $retired_rows, 0, 29 ) ) ) );
check( 'C9b the five retired in v0.48.9 follow, with their 0.48.0 wording and tags', array(
    'keep_up_children|Chase the kids around the park|stamina',
    'walk_with_partner|Walk hand in hand with my partner|stamina,connection',
    'see_friends_weekly|Laugh with my friends every week|connection',
    'present_big_moments|Be there for every big family moment|energy,connection',
    'learn_something_new|Learn something new every year|mind',
) === array_slice( $retired_rows, 29 ) );

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
check( 'A3 ABILITY_SHORT: the page\'s one-word names (v0.48.0)', array(
    'strength' => 'Strength', 'mobility' => 'Mobility', 'flexibility' => 'Flexibility', 'balance' => 'Balance',
    'stamina' => 'Stamina', 'mind' => 'Mind', 'energy' => 'Energy', 'connection' => 'Connection',
) === ( defined( 'HDLV2_Why_Picks::ABILITY_SHORT' ) ? HDLV2_Why_Picks::ABILITY_SHORT : null ) );
$needs_of = array();
foreach ( $cat as $g ) foreach ( $g['items'] as $it ) $needs_of[ $it['id'] ] = $it['needs'];

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
    // v0.48.9: hike_hills also needs connection; time with people 5 → above 3, never a focus.
    $ab( 'connection', 'Connection', 1, 5.0, array( $ms( 'Time with people', 5, 'stage3' ) ), false, false ),
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
$indirect_only = $profile( array( 'sit_on_floor' ), array( 'q5_sts' => 1 ), array() );
check( 'AP8 an ability with only a nearest measure is never a focus area',
    is_array( $indirect_only ) && array( 'mobility', 'flexibility' ) === array_column( $indirect_only, 'id' )
    && array( true, false ) === array_column( $indirect_only, 'focus' ) );

// ── Stage 1 raw scores: only real answers count ──
// calculate_quick() fills a missing or invalid q3-q9 with 3. That 3 must
// never be reported as something the client was measured on.
$has_raw = method_exists( 'HDLV2_Why_Picks', 'stage1_raw' );
$s1raw   = function ( $s1 ) use ( $has_raw ) { return $has_raw ? HDLV2_Why_Picks::stage1_raw( $s1 ) : null; };
$thin    = array( 'q1_age' => 62, 'q9' => 'c' );
$thin['server_result'] = HDLV2_Rate_Calculator::calculate_quick( $thin );
check( 'R1 the real calculator fills unanswered questions with 3; stage1_raw() keeps only the answered one',
    3 === ( $thin['server_result']['raw']['q5_sts'] ?? null ) && array( 'q9_diet' => 3 ) === $s1raw( $thin ) );
$full = $sample;
$full['server_result'] = HDLV2_Rate_Calculator::calculate_quick( $sample );
check( 'R2 a fully answered Stage 1 keeps every raw score', $full['server_result']['raw'] === $s1raw( $full ) );
check( 'R3 an invalid answer letter is dropped; upper-case letters count',
    array( 'q4_vo2' => 4 ) === $s1raw( array( 'q4' => 'D', 'q5' => 'zzz', 'server_result' => array( 'raw' => array( 'q4_vo2' => 4, 'q5_sts' => 3 ) ) ) ) );
check( 'R4 no server result → empty', array() === $s1raw( array( 'q5' => 'b' ) ) && array() === $s1raw( array() ) );

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
$five = array( 'floor_grandkids', 'stairs_no_rail', 'wake_rested', 'keep_driving', 'swim_in_sea' );
$ten  = array_merge( $five, array( 'hike_hills', 'dance_wedding', 'day_in_garden', 'fetch_with_dog', 'help_loved_one' ) );
$v = function ( $data ) { return HDLV2_Why_Picks::validate( $data ); };
$err = function ( $r ) { return is_wp_error( $r ) && 'invalid_why_picks' === $r->code && 400 === ( $r->data['status'] ?? 0 ); };
check( 'V1 4 picks → error', $err( $v( array( 'why_picks' => array_slice( $five, 0, 4 ) ) ) ) );
check( 'V1b 4 picks → plain message', 'Please choose at least 5.' === $v( array( 'why_picks' => array_slice( $five, 0, 4 ) ) )->message );
check( 'V2 5 picks → ok', true === $v( array( 'why_picks' => $five ) ) );
check( 'V3 10 picks → ok', true === $v( array( 'why_picks' => $ten ) ) );
check( 'V4 11 picks → error', $err( $v( array( 'why_picks' => array_merge( $ten, array( 'keep_working' ) ) ) ) ) );
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
// carry_shopping is retired: a saved row still reads its old wording.
$expected = "In my later years, I want to:\n"
    . "- Play on the floor with my grandkids\n"
    . "- Carry my own shopping from the car to the kitchen\n"
    . "- Wake up full of energy\n"
    . "The people I most want to stay healthy and strong for: my wife Jenny, Leo and Mia\n"
    . "In my own words: I'd like to keep up with them.";
check( 'P1 composed paragraph, exact (v0.48.0 opening words)', $expected === HDLV2_Why_Picks::compose_vision_text( $picks3, 'my wife Jenny, Leo and Mia', "I'd like to keep up with them." ) );
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
// v0.48.10 — every use ends its facts with this paragraph (approved by Quim, 2026-10-07).
$how = "HOW TO USE THESE CHOICES: this client has one assessment, so describe where they stand today against what each choice needs, not a trend. For each focus area, name the choice it serves (quote the statement word for word), the measure that drags it, and what keeping that ability over the coming years needs. Where a measure is above what a choice needs, say so in one clause and name the choice. Explain the client's position in terms of their choices, never in abilities alone. Do not invent choices or people that are not listed here.\n";
$expected_block = "=== WHAT THIS CLIENT CHOSE (picked from a list, their own priorities for later life) ===\n"
    . "- Play on the floor with my grandkids (People I love) [depends on: mobility, flexibility, strength]\n"
    . "- Carry my own shopping from the car to the kitchen [depends on: strength]\n"
    . "- Wake up full of energy (Things I love) [depends on: energy]\n"
    . "Abilities these choices depend on most: strength (2), mobility (1), flexibility (1), energy (1)\n"
    . "People they named: my wife Jenny\n"
    . "In LIFT, where one of their weakest scores limits an ability these choices depend on, say so in one clause and name the choice. In THRIVE, use their choices and the people they named. Do not invent choices or people that are not listed here.\n"
    . $how
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
$picked_lines = "=== WHAT THIS CLIENT CHOSE (picked from a list, their own priorities for later life) ===\n"
    . "- Play on the floor with my grandkids (People I love) [depends on: mobility, flexibility, strength]\n"
    . "- Pick up a grandchild for a hug (People I love) [depends on: strength, balance]\n"
    . "- Explore new places (Things I love) [depends on: stamina]\n"
    . "- Hike the hills with friends (Things I love) [depends on: stamina, balance, connection]\n"
    . "- Remember every grandchild's name and story (People I love) [depends on: mind]\n"
    . "- Wake up full of energy (Things I love) [depends on: energy]\n"
    . "Abilities these choices depend on most: strength (2), balance (2), stamina (2), mobility (1), flexibility (1), mind (1), energy (1), connection (1)\n"
    . "People they named: my wife Jenny\n";
// Stage 1 answers are marked as such: they run 1 to 5, Stage 3 scores 0 to 5.
$measured_lines = "What their choices depend on, with what was measured (out of 5, higher is better; Stage 1 answers run 1 to 5, Stage 3 answers 0 to 5):\n"
    . "- Strength: 2 of their choices; measured 2.5/5 from Chair stand (30 seconds) 3/5, Getting up from the floor (Stage 1) 2/5\n"
    . "- Mobility: 1 of their choices; measured 2/5 from Getting up from the floor (Stage 1) 2/5\n"
    . "- Flexibility: 1 of their choices; nearest measure 2/5 from Getting up from the floor (Stage 1) 2/5\n"
    . "- Balance: 2 of their choices; measured 1/5 from Standing on one leg 1/5\n"
    . "- Stamina: 2 of their choices; measured 4/5 from Physical activity 4/5, Climbing stairs (Stage 1) 4/5\n"
    . "- A sharp mind: 1 of their choices; measured 2.3/5 from Mental activity 2/5, Stress 3/5, Sleep quality 2/5\n"
    . "- Energy and sleep: 1 of their choices; measured 3/5 from Sleep duration 4/5, Sleep quality 2/5, Diet 3/5\n"
    . "- Connection: 1 of their choices; measured 5/5 from Time with people 5/5\n"
    . "Focus areas (most chosen, weakest measured): Balance, Strength, Mobility\n";
$no_quote = "Do not quote these ability scores as if they were one of the 21 health scores; they are averages for your guidance.\n";
$expected_scored = $picked_lines
    . "In LIFT, where one of their weakest scores limits an ability these choices depend on, say so in one clause and name the choice. In THRIVE, use their choices and the people they named. Do not invent choices or people that are not listed here.\n"
    . $measured_lines
    . $how
    . "Use the focus areas to decide what LIFT puts first. Name the choice each one serves.\n"
    . $no_quote
    . "\n";
$scored_in = array( 'form_flow' => 'single', 'why_picks' => $six, 'key_people_text' => 'my wife Jenny' );
check( 'B6 prompt block with the saved answers, exact (snapshot)', $expected_scored === HDLV2_Why_Picks::prompt_block( $scored_in, $raw1, $score3 ) );
check( 'B7 no picks → still empty with scores passed', '' === HDLV2_Why_Picks::prompt_block( array(), $raw1, $score3 )
    && '' === HDLV2_Why_Picks::prompt_block( array( 'why_picks' => $six ), $raw1, $score3 ) );
$unmeasured = HDLV2_Why_Picks::prompt_block( array( 'form_flow' => 'single', 'why_picks' => array( 'see_friends_weekly', 'sit_on_floor' ) ), array(), array( 'sitToStand' => 4 ) );
check( 'B8 an ability with no number says so; no focus line and no "use the focus areas" instruction without one',
    false !== strpos( $unmeasured, "- Flexibility: 1 of their choices; no answer recorded\n" )
    && false !== strpos( $unmeasured, "- Connection: 1 of their choices; no answer recorded\n" )
    && false === strpos( $unmeasured, 'Focus areas' ) && false === strpos( $unmeasured, 'focus areas' ) && false === strpos( $unmeasured, '/5' )
    && false !== strpos( $unmeasured, $no_quote ) );

// The milestones prompt gets the same facts with its own instruction: no
// report sections (LIFT / THRIVE) in a prompt that has none.
$expected_ms = $picked_lines
    . $measured_lines
    . $how
    . "Aim the milestones at what this client chose, starting with the focus areas, and keep every milestone within its word limit.\n"
    . $no_quote
    . "\n";
$ms_block = HDLV2_Why_Picks::prompt_block( $scored_in, $raw1, $score3, true );
check( 'B9 milestones block, exact (snapshot): same facts, its own instruction', $expected_ms === $ms_block );
check( 'B10 milestones block names no report section', false === strpos( $ms_block, 'LIFT' ) && false === strpos( $ms_block, 'THRIVE' ) );
$ms_plain = HDLV2_Why_Picks::prompt_block( array( 'form_flow' => 'single', 'why_picks' => array( 'see_friends_weekly', 'sit_on_floor' ) ), array(), array( 'sitToStand' => 4 ), true );
check( 'B10b milestones block without a focus area does not point at one',
    false === strpos( $ms_plain, 'focus areas' ) && false !== strpos( $ms_plain, "Aim the milestones at what this client chose, and keep every milestone within its word limit.\n" ) );
check( 'B10c milestones block: no picks → empty', '' === HDLV2_Why_Picks::prompt_block( array(), $raw1, $score3, true ) );

// One call for the callers: the row's saved answers in, the block out.
$has_row = method_exists( 'HDLV2_Why_Picks', 'block_for_row' );
$row_of  = function ( $s1, $s2 ) { return (object) array( 'stage1_data' => json_encode( $s1 ), 'stage2_data' => json_encode( $s2 ) ); };
$s1_ok   = array( 'q4' => 'd', 'q5' => 'b', 'server_result' => array( 'raw' => $raw1 ) );
check( 'B11 block_for_row() gives the same text as prompt_block() on the same saved answers',
    $has_row && $expected_scored === HDLV2_Why_Picks::block_for_row( $row_of( $s1_ok, $scored_in ), $score3 )
    && $expected_ms === HDLV2_Why_Picks::block_for_row( $row_of( $s1_ok, $scored_in ), $score3, true ) );
$s1_gap = array( 'q4' => 'd', 'server_result' => array( 'raw' => array( 'q4_vo2' => 4, 'q5_sts' => 3 ) ) );
check( 'B12 a Stage 1 question nobody answered is not reported as measured',
    $has_row && false !== strpos( HDLV2_Why_Picks::block_for_row( $row_of( $s1_gap, array( 'form_flow' => 'single', 'why_picks' => array( 'sit_on_floor' ) ) ), array() ),
        "- Mobility: 1 of their choices; no answer recorded\n" ) );
check( 'B13 block_for_row() on an old-flow row or an empty row → empty string',
    $has_row && '' === HDLV2_Why_Picks::block_for_row( $row_of( $s1_ok, array( 'vision_text' => 'typed why' ) ), $score3 )
    && '' === HDLV2_Why_Picks::block_for_row( (object) array(), $score3 ) );

// ── options for the page ──
$opts = HDLV2_Why_Picks::options_for_page();
$flat = array();
foreach ( $opts as $g ) foreach ( $g['items'] as $it ) $flat[] = $it;
check( 'O1 page options carry id + label + needs, grouped', isset( $opts[0]['title'] ) && array( 'id', 'label', 'needs' ) === array_keys( $flat[0] ) && 21 === count( $flat ) );
check( 'O3 the page gets three groups of 6 / 8 / 7 (v0.48.9)', array( 6, 8, 7 ) === array_map( function ( $g ) { return count( $g['items'] ); }, $opts ) );
$ret = Why_Picks_With_Retired::options_for_page();
check( 'O2 retired items are not offered', ! in_array( 'old_item', array_column( $ret[0]['items'], 'id' ), true ) );

// ── v0.48.0: 24 offered, 29 retired, one added, one back ──
$tag_counts = array();
foreach ( $flat as $it ) foreach ( $it['needs'] as $t ) $tag_counts[ $t ] = ( $tag_counts[ $t ] ?? 0 ) + 1;
ksort( $tag_counts );
check( 'R3 tags over the 21 (v0.48.9, D3-B): strength 10, balance 10, mobility 6, mind 6, stamina 5, flexibility 3, energy 3, connection 3',
    array( 'balance' => 10, 'connection' => 3, 'energy' => 3, 'flexibility' => 3, 'mind' => 6, 'mobility' => 6, 'stamina' => 5, 'strength' => 10 ) === $tag_counts );
check( 'R3b every ability keeps at least three statements, so every bar, spoke and focus can appear', 8 === count( $tag_counts ) && min( $tag_counts ) >= 3 );
$flat_ids = array_column( $flat, 'id' );
$base5    = array( 'floor_grandkids', 'stairs_no_rail', 'wake_rested', 'keep_driving', 'swim_in_sea' );
$all_refused = true; $all_resolve = true; $n_retired = 0;
foreach ( $cat as $g ) foreach ( $g['items'] as $it ) {
    if ( empty( $it['retired'] ) ) continue;
    $n_retired++;
    $rid = $it['id'];
    if ( in_array( $rid, $flat_ids, true ) ) $all_refused = false;
    if ( ! is_wp_error( HDLV2_Why_Picks::validate( array( 'why_picks' => array_merge( array_slice( $base5, 0, 4 ), array( $rid ) ) ) ) ) ) $all_refused = false;
    $shown = HDLV2_Why_Picks::picks_for_display( array( $rid ) );
    if ( 1 !== count( $shown ) || $it['label'] !== $shown[0]['label'] || ! $shown[0]['needs'] ) $all_resolve = false;
}
check( 'R4 each of the 34 retired ids is not offered and validate() refuses it', 34 === $n_retired && $all_refused );
check( 'R5 a retired id still resolves to its old label and abilities (saved rows keep reading)', $all_resolve
    && array( array( 'label' => 'Keep an active intimate life with my partner', 'needs' => array( 'Stamina', 'Energy and sleep' ) , 'group' => '' ) ) === HDLV2_Why_Picks::picks_for_display( array( 'intimate_life' ) )
    && array( array( 'label' => 'Walk the dog every day, whatever the weather', 'needs' => array( 'Stamina' ) , 'group' => '' ) ) === HDLV2_Why_Picks::picks_for_display( array( 'walk_dog_daily' ) )
    && array(
        array( 'label' => 'Chase the kids around the park', 'needs' => array( 'Stamina' ) , 'group' => '' ),
        array( 'label' => 'Walk hand in hand with my partner', 'needs' => array( 'Stamina', 'Connection' ) , 'group' => '' ),
        array( 'label' => 'Laugh with my friends every week', 'needs' => array( 'Connection' ) , 'group' => '' ),
        array( 'label' => 'Be there for every big family moment', 'needs' => array( 'Energy and sleep', 'Connection' ) , 'group' => '' ),
        array( 'label' => 'Learn something new every year', 'needs' => array( 'A sharp mind' ) , 'group' => '' ),
    ) === HDLV2_Why_Picks::picks_for_display( array( 'keep_up_children', 'walk_with_partner', 'see_friends_weekly', 'present_big_moments', 'learn_something_new' ) ) );
check( 'R6 live_in_own_home is offered again and accepted', in_array( 'live_in_own_home', $flat_ids, true )
    && true === HDLV2_Why_Picks::validate( array( 'why_picks' => array_merge( array_slice( $base5, 0, 4 ), array( 'live_in_own_home' ) ) ) ) );
check( 'R7 not_a_burden exists, is offered and accepted', isset( $needs_of['not_a_burden'] )
    && true === HDLV2_Why_Picks::validate( array( 'why_picks' => array_merge( array_slice( $base5, 0, 4 ), array( 'not_a_burden' ) ) ) ) );
check( 'R8 a kept id reads its new wording on a saved row', array( array( 'label' => 'Drive safely', 'needs' => array( 'A sharp mind', 'Flexibility' ), 'group' => 'My freedom' ) )
    === HDLV2_Why_Picks::picks_for_display( array( 'keep_driving' ) ) );

// ── v0.48.9: Matthew's wording (2026-10-07) ──
check( 'N1 the two new statements are offered and accepted', in_array( 'fetch_with_dog', $flat_ids, true ) && in_array( 'help_loved_one', $flat_ids, true )
    && true === HDLV2_Why_Picks::validate( array( 'why_picks' => array_merge( array_slice( $base5, 0, 3 ), array( 'fetch_with_dog', 'help_loved_one' ) ) ) ) );
check( 'N2 learn_something_new is refused', is_wp_error( HDLV2_Why_Picks::validate( array( 'why_picks' => array_merge( array_slice( $base5, 0, 4 ), array( 'learn_something_new' ) ) ) ) ) );
check( 'N3 the re-phrased lines read after "I want to:" (exact paragraph)', "In my later years, I want to:\n"
    . "- Never burden loved ones in my last decade\n"
    . "- Play fetch with the dog in the park\n"
    . "- Live without fear of falling\n"
    . "- Stay sharp and run my life with enthusiasm\n"
    . "- Explore new places"
    === HDLV2_Why_Picks::compose_vision_text( array( 'not_a_burden', 'fetch_with_dog', 'uneven_ground', 'manage_own_affairs', 'walk_city_all_day' ), '', '' ) );

echo "\n" . ( $fail ? "SCENARIO: FAIL ($fail)\n" : "SCENARIO: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );
