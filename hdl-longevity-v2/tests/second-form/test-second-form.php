<?php
/**
 * Second form as tap cards (v0.47.94) — the server side.
 *
 * HDLV2_Why_Picks::second_form_ties(): for each second-form question that
 * measures an ability, the ability most of the client's choices need, how
 * many, and the first such choice. /form/load sends it to single-flow rows
 * that have not finished the form.
 *
 * Standalone, no WP, no DB, no network:
 *   php tests/second-form/test-second-form.php
 */

error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['options'] = array();
function get_option( $k, $default = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $default; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }

require __DIR__ . '/../../includes/sprint-2/class-hdlv2-why-picks.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) {
    global $pass, $fail;
    echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n";
    $ok ? $pass++ : $fail++;
}

if ( ! method_exists( 'HDLV2_Why_Picks', 'second_form_ties' ) ) {
    check( 'second_form_ties() exists', false );
    echo "\n$pass passed, $fail failed\n";
    exit( 1 );
}

function label_of( $id ) {
    foreach ( HDLV2_Why_Picks::catalogue() as $g ) {
        foreach ( $g['items'] as $it ) if ( $id === $it['id'] ) return $it['label'];
    }
    return null;
}

$t = HDLV2_Why_Picks::second_form_ties( array() );
check( 'T1 no picks → empty', array() === $t );
check( 'T1b null picks → empty', array() === HDLV2_Why_Picks::second_form_ties( null ) );

// carry_shopping needs strength only; strength's second-form measure is sitToStand.
$t = HDLV2_Why_Picks::second_form_ties( array( 'carry_shopping' ) );
check( 'T2 one strength pick → sitToStand only',
    array( 'sitToStand' => array( 'ability' => 'strength', 'count' => 1, 'choice' => label_of( 'carry_shopping' ) ) ) === $t );

$t = HDLV2_Why_Picks::second_form_ties( array( 'carry_shopping', 'lift_suitcase' ) );
check( 'T3 two strength picks → count 2, first choice in pick order', 2 === $t['sitToStand']['count'] && label_of( 'carry_shopping' ) === $t['sitToStand']['choice'] );
$t = HDLV2_Why_Picks::second_form_ties( array( 'lift_suitcase', 'carry_shopping' ) );
check( 'T3b reversed picks → the other choice is first', label_of( 'lift_suitcase' ) === $t['sitToStand']['choice'] );

// sleepQuality is measured for both mind and energy.
$t = HDLV2_Why_Picks::second_form_ties( array( 'wake_rested', 'sharp_conversation' ) );
check( 'T4 mind 1 / energy 1 on sleepQuality → mind (NEEDS order)', 'mind' === $t['sleepQuality']['ability'] && 1 === $t['sleepQuality']['count'] );
check( 'T4b the choice is the first MIND pick, not the first pick', label_of( 'sharp_conversation' ) === $t['sleepQuality']['choice'] );
check( 'T4c sleepDuration (energy only) → energy', 'energy' === $t['sleepDuration']['ability'] );
$t = HDLV2_Why_Picks::second_form_ties( array( 'sharp_conversation', 'wake_rested', 'energy_in_evening' ) );
check( 'T5 energy 2 beats mind 1 on sleepQuality', 'energy' === $t['sleepQuality']['ability'] && 2 === $t['sleepQuality']['count'] );
check( 'T5b mind still reaches stressLevels and cognitiveActivity', 'mind' === $t['stressLevels']['ability'] && 'mind' === $t['cognitiveActivity']['ability'] );

$t = HDLV2_Why_Picks::second_form_ties( array( 'not_a_real_id', 42, array( 'x' ), 'carry_shopping' ) );
check( 'T6 unknown and non-string ids are ignored', array( 'sitToStand' ) === array_keys( $t ) && 1 === $t['sitToStand']['count'] );

class Retired_Picks extends HDLV2_Why_Picks {
    public static function catalogue() {
        $c = parent::catalogue();
        $c[0]['items'][1]['retired'] = true; // lift_grandchild: strength, balance
        return $c;
    }
}
$t = Retired_Picks::second_form_ties( array( 'lift_grandchild' ) );
check( 'T7 a retired id still counts (rows keep their picks)', 'strength' === ( $t['sitToStand']['ability'] ?? '' ) && 'balance' === ( $t['balance']['ability'] ?? '' ) );

$t = HDLV2_Why_Picks::second_form_ties( array( 'heartRateScore' ) );
check( 'T8 heartRateScore is never a field', array() === $t );

// Every field that can come back is a real second-form field.
$js = file_get_contents( __DIR__ . '/../../assets/js/hdlv2-staged-form.js' );
preg_match( '/var S3_SECTIONS = \[([\s\S]*?)\n  \];/', $js, $m );
preg_match_all( '/fields: \[([^\]]*)\]/', $m[1] ?? '', $lists );
preg_match_all( "/'([A-Za-z_]+)'/", implode( ',', $lists[1] ), $f );
$fields = $f[1];
$all = array();
foreach ( HDLV2_Why_Picks::catalogue() as $g ) foreach ( $g['items'] as $it ) $all[] = $it['id'];
$t = HDLV2_Why_Picks::second_form_ties( $all );
// S3_SECTIONS names 23: height, weight and waist are drawn without being listed.
check( 'T9 S3_SECTIONS parsed (23 listed fields)', 23 === count( $fields ) );
check( 'T10 every returned field is a real S3_SECTIONS field', $t && ! array_diff( array_keys( $t ), $fields ) );
check( 'T11 all nine measured fields come back when every ability is chosen',
    array( 'balance', 'cognitiveActivity', 'dietQuality', 'physicalActivity', 'sitToStand', 'sleepDuration', 'sleepQuality', 'socialConnections', 'stressLevels' )
    === ( function ( $k ) { sort( $k ); return $k; } )( array_keys( $t ) ) );
$shape = true;
foreach ( $t as $row ) {
    if ( array( 'ability', 'count', 'choice' ) !== array_keys( $row ) || ! is_int( $row['count'] ) || $row['count'] < 1 || ! is_string( $row['choice'] ) ) $shape = false;
}
check( 'T12 each entry is { ability, count ≥ 1, choice }', $shape );

// /form/load: only single-flow rows that have not finished get the key.
$php = file_get_contents( __DIR__ . '/../../includes/sprint-2/class-hdlv2-staged-form.php' );
preg_match( '/public function rest_load_form\([\s\S]*?\n    \}\n/', $php, $lm );
$load = $lm[0] ?? '';
check( 'L1 rest_load_form adds second_form_ties', false !== strpos( $load, "\$response['second_form_ties'] = HDLV2_Why_Picks::second_form_ties(" ) );
check( 'L2 only for a single-flow, unfinished row',
    (bool) preg_match( "/if \( empty\( \\\$progress->stage3_completed_at \) && HDLV2_Why_Picks::is_single\( \\\$s2 \) \) \{\s*\\\$response\['second_form_ties'\]/", $load ) );
check( 'L3 from the row\'s why_picks and, v0.48.5, the wording stored at submit', false !== strpos( $load, "second_form_ties( \$s2['why_picks'] ?? array(), \$s2['why_pick_labels'] ?? array() )" ) );

echo "\n$pass passed, $fail failed\n";
echo ( $fail ? "SUITE: FAIL\n" : "SUITE: PASS\n" );
exit( $fail ? 1 : 0 );
