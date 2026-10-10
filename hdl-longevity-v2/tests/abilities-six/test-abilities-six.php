<?php
/**
 * Six abilities, weighted tags, need on a fixed 0-5 scale (v0.48.11).
 *
 * Matthew, 2026-10-09: mobility + flexibility are one ability, stamina +
 * energy are one; a statement may move several abilities a little; the
 * chart always tops out at 5. Runs the REAL HDLV2_Why_Picks, no WP, no DB.
 *
 *   php tests/abilities-six/test-abilities-six.php           check
 *   php tests/abilities-six/test-abilities-six.php --write   rewrite fixtures/needs.json
 *
 * fixtures/needs.json (pick sets → need per ability, from the PHP) is what
 * test-abilities-six.js holds the page's abilityCounts() to.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ . '/' );
function get_option( $k, $default = false ) { return $default; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_json_encode( $x ) { return json_encode( $x ); }
class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }

require __DIR__ . '/../../includes/sprint-2/class-hdlv2-why-picks.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) {
    global $pass, $fail;
    echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n";
    $ok ? $pass++ : $fail++;
}

$SIX = array( 'strength', 'mobility', 'balance', 'stamina', 'mind', 'connection' );
$cat = HDLV2_Why_Picks::catalogue();

// A-1
check( 'A-1 NEEDS is the six, in order', $SIX === HDLV2_Why_Picks::NEEDS );
check( 'A-1b labels and short names for exactly the six', $SIX === array_keys( HDLV2_Why_Picks::ABILITY_LABELS ) && $SIX === array_keys( HDLV2_Why_Picks::ABILITY_SHORT )
    && 'Mobility and flexibility' === HDLV2_Why_Picks::ABILITY_LABELS['mobility'] && 'Stamina and energy' === HDLV2_Why_Picks::ABILITY_LABELS['stamina'] );
check( 'A-1c MEASURES: one list per ability, stamina carries the old energy measures', $SIX === array_keys( HDLV2_Why_Picks::MEASURES )
    && array( 'physicalActivity', 'q4_vo2', 'heartRateScore', 'sleepDuration', 'sleepQuality', 'dietQuality' ) === array_column( HDLV2_Why_Picks::MEASURES['stamina'], 'key' ) );

// A-2: every active and retired tag resolves to one of the six, weights 1-3.
$bad = array();
foreach ( $cat as $g ) foreach ( $g['items'] as $it ) {
    if ( array_diff( $it['needs'], $SIX ) || array_keys( $it['weights'] ) !== $it['needs'] || ! $it['needs'] ) $bad[] = $it['id'];
    foreach ( $it['weights'] as $w ) if ( ! in_array( $w, array( 1, 2, 3 ), true ) ) $bad[] = $it['id'] . ':' . $w;
}
if ( $bad ) echo '      bad: ' . implode( ', ', $bad ) . "\n";
check( 'A-2 every statement (active + retired) names only the six, each with a weight 1-3', ! $bad );
$by = array();
foreach ( $cat as $g ) foreach ( $g['items'] as $it ) $by[ $it['id'] ] = $it;
check( 'A-2b retired aliases: sit_on_floor (flexibility,mobility) → mobility once; intimate_life (stamina,energy) → stamina once',
    array( 'mobility' => 2 ) === $by['sit_on_floor']['weights'] && array( 'stamina' => 2 ) === $by['intimate_life']['weights']
    && array( 'mobility' => 2, 'strength' => 2 ) === $by['reach_top_cupboard']['weights'] );

// A-3
check( 'A-3 parse_tags: weight read, bare tag = 2, alias, larger weight kept',
    array( 'mobility' => 3 ) === HDLV2_Why_Picks::parse_tags( 'mobility:3' )
    && array( 'strength' => 2 ) === HDLV2_Why_Picks::parse_tags( 'strength' )
    && array( 'mobility' => 3, 'stamina' => 1 ) === HDLV2_Why_Picks::parse_tags( 'flexibility:1,mobility:3,energy:1' ) );
check( 'A-3c a weight outside 1-3 is pulled inside it (review fix)',
    array( 'mobility' => 1, 'strength' => 3, 'mind' => 1 ) === HDLV2_Why_Picks::parse_tags( 'mobility:0,strength:7,mind:x' ) );

// The draft table for Matthew, exactly (plan A2): strength, mobility, balance, stamina, mind, connection.
$TABLE = array(
    'floor_grandkids' => array( 2, 3, 1, 1, 0, 1 ), 'lift_grandchild' => array( 3, 1, 2, 0, 0, 0 ),
    // dance_wedding + remember_family connection 2 -> 3: plan 2026-10-10 v2 D3, open for Matthew (M2).
    'fetch_with_dog' => array( 1, 1, 1, 3, 0, 0 ), 'dance_wedding' => array( 0, 2, 2, 2, 0, 3 ),
    'remember_family' => array( 0, 0, 0, 0, 3, 3 ), 'help_loved_one' => array( 3, 2, 2, 0, 1, 1 ),
    'not_a_burden' => array( 2, 2, 2, 1, 2, 1 ), 'live_in_own_home' => array( 2, 2, 3, 1, 3, 0 ),
    'stairs_no_rail' => array( 2, 2, 3, 2, 0, 0 ), 'up_after_fall' => array( 3, 3, 2, 0, 0, 0 ),
    'keep_driving' => array( 0, 2, 1, 0, 3, 0 ), 'manage_own_affairs' => array( 0, 0, 0, 2, 3, 1 ),
    'dress_myself' => array( 1, 3, 2, 0, 0, 0 ), 'uneven_ground' => array( 2, 1, 3, 0, 0, 0 ),
    'keep_my_sport' => array( 2, 3, 2, 3, 0, 0 ), 'day_in_garden' => array( 2, 3, 1, 2, 0, 0 ),
    'hike_hills' => array( 1, 1, 2, 3, 0, 2 ), 'swim_in_sea' => array( 2, 2, 0, 3, 0, 0 ),
    'walk_city_all_day' => array( 0, 1, 0, 3, 1, 0 ), 'keep_working' => array( 0, 0, 0, 2, 3, 1 ),
    'wake_rested' => array( 0, 0, 0, 3, 1, 0 ),
);
$got = array();
foreach ( HDLV2_Why_Picks::options_for_page() as $g ) foreach ( $g['items'] as $it ) {
    $row = array();
    foreach ( $SIX as $a ) $row[] = $it['weights'][ $a ] ?? 0;
    $got[ $it['id'] ] = $row;
}
ksort( $got ); $want = $TABLE; ksort( $want );
check( 'A-3b the 21 offered statements carry the plan\'s weight table exactly', $want === $got );

// A-4 need = 5 x sum / (3 x picks), 1 decimal.
$worked  = array( 'floor_grandkids', 'lift_grandchild', 'live_in_own_home', 'keep_my_sport', 'hike_hills' );
$profile = HDLV2_Why_Picks::abilities_profile( $worked );
// By hand: strength 2+3+2+2+1 = 10 → 50·10/15 = 33.3 → 3.3; mobility 3+1+2+3+1 = 10 → 3.3;
// balance 1+2+3+2+2 = 10 → 3.3; stamina 1+0+1+3+3 = 8 → 2.7; mind 3 → 1.0; connection 1+2 = 3 → 1.0.
check( 'A-4 worked example (floor, hug, own home, sport, hike): need per ability',
    array( 'strength' => 3.3, 'mobility' => 3.3, 'balance' => 3.3, 'stamina' => 2.7, 'mind' => 1.0, 'connection' => 1.0 )
    === array_combine( array_column( $profile, 'id' ), array_column( $profile, 'need' ) ) );
check( 'A-4b count stays the number of choices that need it', array( 5, 5, 5, 4, 1, 2 ) === array_column( $profile, 'count' ) );
class Ten_Strong extends HDLV2_Why_Picks {
    public static function catalogue() {
        $items = array();
        for ( $i = 0; $i < 10; $i++ ) $items[] = array( 'id' => "s$i", 'label' => "S $i", 'needs' => array( 'strength', 'mind' ), 'weights' => array( 'strength' => 3, 'mind' => 1 ), 'retired' => false );
        return array( array( 'id' => 'people', 'title' => 'People I love', 'short' => 'People I love', 'items' => $items ) );
    }
}
$ten = Ten_Strong::abilities_profile( array( 's0', 's1', 's2', 's3', 's4', 's5', 's6', 's7', 's8', 's9' ) );
check( 'A-4c ten picks that all lean fully on strength → 5.0; weight 1 on each → 1.7', array( 5.0, 1.7 ) === array_column( $ten, 'need' ) );
check( 'A-4d need does not grow with the number of picks (same five, doubled in kind)',
    array( 5.0, 1.7 ) === array_column( Ten_Strong::abilities_profile( array( 's0', 's1', 's2', 's3', 's4' ) ), 'need' ) );
check( 'A-4e no picks → empty profile', array() === HDLV2_Why_Picks::abilities_profile( array() ) );

// A-5 focus uses need x (5 - score).
$s3 = array( 'sitToStand' => 2, 'balance' => 2, 'physicalActivity' => 1, 'cognitiveActivity' => 1, 'socialConnections' => 1 );
$raw = array( 'q5_sts' => 2 );
$f = HDLV2_Why_Picks::abilities_profile( $worked, $raw, $s3 );
// strength 3.3·3 = 9.9, mobility 3.3·3 = 9.9, balance 3.3·3 = 9.9, stamina 2.7·4 = 10.8, mind 1·4 = 4, connection 1·4 = 4.
check( 'A-5 focus = top three by need x (5 - score): stamina, then strength and mobility (ties keep ability order)',
    array( 'strength', 'mobility', 'stamina' ) === array_values( array_column( array_filter( $f, function ( $a ) { return $a['focus']; } ), 'id' ) ) );
check( 'A-5b rows carry no "indirect" key any more (every ability has its own measure)', ! array_key_exists( 'indirect', $f[0] ) );

// A-6 second_form_ties: the ability with the larger summed weight wins a shared question.
// sleepQuality is measured for both mind and stamina; remember_family + keep_working:
// mind 3+3 = 6, stamina 0+2 = 2 → mind; wake_rested ×1 + keep_working: stamina 3+2 = 5, mind 1+3 = 4 → stamina.
$t1 = HDLV2_Why_Picks::second_form_ties( array( 'remember_family', 'keep_working' ) );
$t2 = HDLV2_Why_Picks::second_form_ties( array( 'wake_rested', 'keep_working' ) );
check( 'A-6 shared question goes to the larger summed weight',
    'mind' === ( $t1['sleepQuality']['ability'] ?? '' ) && 'stamina' === ( $t2['sleepQuality']['ability'] ?? '' ) );
check( 'A-6b count is still the number of choices, choice the first in pick order',
    2 === $t2['sleepQuality']['count'] && 'Wake up full of energy' === $t2['sleepQuality']['choice'] );

// A-7 payload string. The row has no Stage 1 answer, so mobility has no number and is never a focus.
$row = (object) array( 'stage1_data' => '{}', 'stage2_data' => json_encode( array( 'form_flow' => 'single', 'why_picks' => $worked ) ) );
$pf  = HDLV2_Why_Picks::payload_fields( $row, $s3 );
check( 'A-7 why_abilities reads "Label (need N of 5[, focus area])"',
    // 0.48.12 adds "now N" where measured (no age on this row, so no projection words).
    'Strength (need 3.3 of 5, now 2, focus area) | Mobility and flexibility (need 3.3 of 5) | Balance (need 3.3 of 5, now 2, focus area) | Stamina and energy (need 2.7 of 5, now 1, focus area) | A sharp mind (need 1 of 5, now 1) | Connection (need 1 of 5, now 1)' === $pf['why_abilities'] );

// A-8 prompt block.
$blk = HDLV2_Why_Picks::prompt_block( array( 'form_flow' => 'single', 'why_picks' => $worked ), $raw, $s3, 'facts' );
check( 'A-8 the needs line: every ability with its need, most needed first',
    false !== strpos( $blk, "What these choices need of each ability, out of 5 (5 = every choice leans on it fully): strength 3.3, mobility 3.3, balance 3.3, stamina 2.7, mind 1, connection 1\n" ) );
check( 'A-8b a measured line: need, picks, measure',
    false !== strpos( $blk, "- Stamina and energy: needed 2.7/5 by their choices (4 of 5 picks); measured 1/5 from Physical activity 1/5\n" ) );
check( 'A-8c statement lines name the six ids only', false !== strpos( $blk, "[depends on: strength, mobility, balance, stamina, connection]\n" )
    && ! preg_match( '/\[depends on:[^\]]*(flexibility|energy)/', $blk ) );
check( 'A-8d old-flow data → empty block', '' === HDLV2_Why_Picks::prompt_block( array( 'why_picks' => $worked, 'vision_text' => 'typed' ), $raw, $s3 ) );

// A-9
$opt = HDLV2_Why_Picks::options_for_page();
check( 'A-9 page options carry id, label, needs, weights', array( 'id', 'label', 'needs', 'weights' ) === array_keys( $opt[0]['items'][0] ) );

// A-10
check( 'A-10 groups 7 / 7 / 7', array( 7, 7, 7 ) === array_map( function ( $g ) { return count( $g['items'] ); }, $opt ) );
check( 'A-10b not_a_burden is offered under People I love', in_array( 'not_a_burden', array_column( $opt[0]['items'], 'id' ), true )
    && 'People I love' === ( HDLV2_Why_Picks::picks_for_display( array( 'not_a_burden' ) )[0]['group'] ?? '' ) );

// A-15 old-flow prompts byte-identical: pinned in why-reasoning (O1) and why-everywhere (P1).
foreach ( array( 'why-reasoning/scenario-why-reasoning.php' => 'O1 ', 'why-everywhere/scenario-why-sources.php' => 'P1 ' ) as $file => $tag ) {
    exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/../' . $file ) . ' 2>/dev/null', $out );
    $lines = preg_grep( '/^(PASS|FAIL)  ' . preg_quote( $tag, '/' ) . '/', $out );
    check( "A-15 $file: every $tag(old-flow hash) line passes", $lines && ! preg_grep( '/^FAIL/', $lines ) );
    $out = array();
}

// A-16 (v0.48.14) every ability can reach need 5.
$full = array();
foreach ( HDLV2_Why_Picks::catalogue() as $g ) foreach ( $g['items'] as $it ) if ( empty( $it['retired'] ) ) foreach ( $it['weights'] as $id => $w ) if ( 3 === $w ) $full[ $id ] = true;
check( 'A-16 every ability has at least one statement that leans on it fully (weight 3), so each can reach need 5 (missing: ' . implode( ', ', array_diff( HDLV2_Why_Picks::NEEDS, array_keys( $full ) ) ) . ')',
    array() === array_diff( HDLV2_Why_Picks::NEEDS, array_keys( $full ) ) );

// ── Fixture for the page (A-12) ──
$active = array_merge( ...array_map( function ( $g ) { return array_column( $g['items'], 'id' ); }, $opt ) );
$sets   = array( $worked );
foreach ( $active as $id ) $sets[] = array( $id );
for ( $i = 0; $i + 5 <= count( $active ); $i += 2 ) $sets[] = array_slice( $active, $i, 5 );
for ( $i = 0; $i + 10 <= count( $active ); $i += 3 ) $sets[] = array_slice( $active, $i, 10 );
$cases = array();
foreach ( $sets as $p ) {
    $need = array();
    foreach ( HDLV2_Why_Picks::abilities_profile( $p ) as $a ) $need[ $a['id'] ] = $a['need'];
    $cases[] = array( 'picks' => $p, 'need' => $need );
}
$fixture = json_encode( array( 'groups' => $opt, 'short' => HDLV2_Why_Picks::ABILITY_SHORT, 'cases' => $cases ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ) . "\n";
$path = __DIR__ . '/fixtures/needs.json';
if ( in_array( '--write', $argv, true ) ) file_put_contents( $path, $fixture );
check( 'A-12 fixtures/needs.json matches the PHP (' . count( $cases ) . ' pick sets; run --write after a table change)', is_file( $path ) && $fixture === file_get_contents( $path ) );

echo "\n" . ( $fail ? "SCENARIO: FAIL ($fail)\n" : "SCENARIO: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );
