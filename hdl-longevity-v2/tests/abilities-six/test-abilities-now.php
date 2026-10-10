<?php
/**
 * "You now" beside "what your choices need", and the projection to 90 (v0.48.12).
 *
 * Matthew, 2026-10-09: the same chart in a second colour shows current
 * capability; capacity falls about 20% a decade ("double check that"; v0.48.13 uses a
 * per-ability, age and sex table instead), so
 * keeping an ability to 90 needs more of it now. Runs the REAL
 * HDLV2_Why_Picks and the REAL HDLV2_Client_Draft_View::rest_get_draft()
 * against a fake database. No WP, no network.
 *
 *   php tests/abilities-six/test-abilities-now.php
 */

error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ . '/' );
function get_option( $k, $d = false ) { return $d; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_json_encode( $x ) { return json_encode( $x ); }
function add_action() {}
function add_shortcode() {}
function register_rest_route() {}
function get_current_user_id() { return 0; }
function rest_ensure_response( $x ) { return $x; }
function get_userdata( $id ) { return (object) array( 'ID' => $id, 'display_name' => 'P' ); }
function get_user_meta() { return ''; }
function esc_url_raw( $s ) { return $s; }
class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }

require __DIR__ . '/../../includes/sprint-2/class-hdlv2-why-picks.php';
require __DIR__ . '/../../includes/sprint-2/class-hdlv2-rate-calculator.php';
require __DIR__ . '/../../includes/sprint-2c/class-hdlv2-client-draft-view.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) {
    global $pass, $fail;
    echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n";
    $ok ? $pass++ : $fail++;
}

// ── B-1 the arithmetic (v0.48.13: loss per ability, age band and sex) ──
$W = 'HDLV2_Why_Picks';
check( 'B-1 table: five abilities x two sexes x five age bands, every rate 0..0.4; no connection row; target 90',
    array( 'strength', 'mobility', 'balance', 'stamina', 'mind' ) === array_keys( $W::DECLINE ) && 90 === $W::TARGET_AGE
    && ! array_filter( $W::DECLINE, function ( $r ) { return array( 'male', 'female' ) !== array_keys( $r ) || 5 !== count( $r['male'] ) || 5 !== count( $r['female'] )
        || max( array_merge( $r['male'], $r['female'] ) ) > 0.4 || min( array_merge( $r['male'], $r['female'] ) ) <= 0; } ) );
// Man 55 strength: 0.88^0.5 (55-60) x 0.84 x 0.79 x 0.79 = 0.4918. Woman: 0.89^0.5 x 0.89 x 0.88 x 0.88 = 0.6502.
// Unknown sex = the average rate per band: 0.885^0.5 x 0.865 x 0.835 x 0.835 = 0.5674.
check( 'B-1a kept(): man 55 strength 0.492, woman 0.650, unknown = average rows 0.567', abs( $W::kept( 'strength', 55, 'male' ) - 0.4918 ) < 0.0005
    && abs( $W::kept( 'strength', 55, 'female' ) - 0.6502 ) < 0.0005 && abs( $W::kept( 'strength', 55, '' ) - 0.5674 ) < 0.0005 );
check( 'B-1b kept(): different abilities fade differently at 55 (mind 0.76 > strength > balance 0.33)', $W::kept( 'mind', 55 ) > $W::kept( 'strength', 55 ) && $W::kept( 'strength', 55 ) > $W::kept( 'balance', 55 ) );
check( 'B-1c kept(): age 90 or over → 1; connection or an unknown id → null; bad age → null',
    1.0 === $W::kept( 'strength', 90, 'male' ) && 1.0 === $W::kept( 'strength', 97 ) && null === $W::kept( 'connection', 55 ) && null === $W::kept( 'nope', 55 ) && null === $W::kept( 'strength', 0 ) );
check( 'B-1d the sales-page sum: a 20 kg child at 90 → about 38 kg (man) / 29 kg (woman) at 60',
    38 === (int) round( 20 / $W::kept( 'strength', 60, 'male' ) ) && 29 === (int) round( 20 / $W::kept( 'strength', 60, 'female' ) ) );
check( 'B-1e projection: man 55 strength, score 3.5, need 3.3 → 1.7 at 90, needs 5 (capped)',
    array( 'at_90' => 1.7, 'need_now' => 5.0, 'need_now_capped' => true ) === $W::projection( 3.3, 3.5, 55, 'strength', 'male' ) );
check( 'B-1f projection: 80, mind, score 4, need 1 → 3.7 at 90, needs 1.1 now', array( 'at_90' => 3.7, 'need_now' => 1.1, 'need_now_capped' => false ) === $W::projection( 1.0, 4.0, 80, 'mind' ) );
check( 'B-1g unmeasured ability: at_90 null, need_now still worked out', array( 'at_90' => null, 'need_now' => 4.6, 'need_now_capped' => false ) === $W::projection( 2.0, null, 70, 'balance' ) );
check( 'B-1h connection: never projected', array( 'at_90' => null, 'need_now' => null, 'need_now_capped' => false ) === $W::projection( 3.0, 4.0, 55, 'connection', 'male' ) );
// v0.48.14 — balance 0.93^0.5 x 0.78 x 0.66 x 0.66 = 0.3277; man 40 strength 0.90 x 0.88 x 0.84 x 0.79 x 0.79 = 0.4152.
check( 'B-1k v2 rates: balance speeds up after 60 (55 → 0.328); strength under 50 is the 40s cohort, not a guess (man 40 → 0.415)',
    abs( $W::kept( 'balance', 55, 'female' ) - 0.3277 ) < 0.0005 && abs( $W::kept( 'strength', 40, 'male' ) - 0.4152 ) < 0.0005 );
// ── B-2 no age ──
foreach ( array( null, '', 'abc', 0, -4 ) as $bad ) {
    $p = $W::projection( 3.3, 3.5, $bad, 'strength' );
    if ( null !== $p['at_90'] || null !== $p['need_now'] || false !== $p['need_now_capped'] ) { check( 'B-2 no usable age → nulls (' . var_export( $bad, true ) . ')', false ); break; }
}
check( 'B-2 no usable age (null, "", "abc", 0, -4) → at_90 and need_now null', true );
check( 'B-2b a numeric string age works ("55")', $W::projection( 3.3, 3.5, '55', 'strength', 'male' ) === $W::projection( 3.3, 3.5, 55, 'strength', 'male' ) );
check( 'B-2c sex_of(): q1_sex, then gender, else ""', 'female' === $W::sex_of( array( 'q1_sex' => 'Female' ) ) && 'male' === $W::sex_of( array( 'gender' => 'male' ) )
    && '' === $W::sex_of( array( 'q1_sex' => 'other' ) ) && '' === $W::sex_of( null ) && '' === $W::sex_of( array( 'q1_sex' => array( 'x' ) ) ) );

$worked = array( 'floor_grandkids', 'lift_grandchild', 'live_in_own_home', 'keep_my_sport', 'hike_hills' );
$raw    = array( 'q5_sts' => 4, 'q4_vo2' => 3 );
$s3     = array( 'sitToStand' => 3, 'balance' => 2, 'physicalActivity' => 3, 'cognitiveActivity' => 4, 'stressLevels' => 3, 'sleepQuality' => 3, 'socialConnections' => 5 );
$prof   = HDLV2_Why_Picks::abilities_profile( $worked, $raw, $s3, 55, 'male' );
$keys3  = array_flip( array( 'at_90', 'need_now', 'need_now_capped' ) );
check( 'B-1i profile rows carry each ability\'s own projection (age + sex passed)', array( 'at_90', 'need_now', 'need_now_capped' ) === array_slice( array_keys( $prof[0] ), -3 )
    && ! array_filter( $prof, function ( $a ) use ( $keys3 ) { return HDLV2_Why_Picks::projection( $a['need'], $a['score'], 55, $a['id'], 'male' ) !== array_intersect_key( $a, $keys3 ); } ) );
check( 'B-1j profile without an age: projections null', null === HDLV2_Why_Picks::abilities_profile( $worked, $raw, $s3 )[0]['at_90'] );

// ── B-3 chart series ──
$series = HDLV2_Why_Picks::ability_chart_series( $prof, $raw, $s3 );
check( 'B-3 series: six short labels in ability order, scale 5', array( 'Strength', 'Mobility', 'Balance', 'Stamina', 'Mind', 'Connection' ) === $series['labels'] && 5 === $series['scale'] );
check( 'B-3b needed = each ability\'s need (0 where no choice needs it)', array( 3.3, 3.3, 3.3, 2.7, 1.0, 1.0 ) === $series['needed'] );
// strength (3+4)/2 = 3.5 · mobility 4 · balance 2 · stamina (3+3+3)/3 = 3 · mind (4+3+3)/3 = 3.3 · connection 5.
check( 'B-3c now = the measured score for every ability', array( 3.5, 4.0, 2.0, 3.0, 3.3, 5.0 ) === $series['now'] );
$byid = array_column( $prof, 'at_90', 'id' );
check( 'B-3e at_90 = each row\'s projection in ability order, null for connection', array_map( function ( $id ) use ( $byid ) { return $byid[ $id ] ?? null; }, HDLV2_Why_Picks::NEEDS ) === $series['at_90'] && null === $series['at_90'][5] );
$thin = HDLV2_Why_Picks::ability_chart_series( HDLV2_Why_Picks::abilities_profile( array( 'remember_family' ), array(), array( 'balance' => 1 ) ), array(), array( 'balance' => 1 ) );
check( 'B-3d unmeasured → null; an ability nobody chose still shows where they stand', array( null, null, 1.0, null, null, null ) === $thin['now']
    && array( 0.0, 0.0, 0.0, 0.0, 5.0, 5.0 ) === $thin['needed'] );

// ── B-4 the PDF picture ──
$url = HDLV2_Why_Picks::chart_url( $series );
parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
$cfg = json_decode( $q['c'] ?? '', true );
check( 'B-4 a QuickChart v4 radar URL', 0 === strpos( $url, 'https://quickchart.io/chart?c=' ) && '4' === ( $q['v'] ?? '' ) && 'radar' === ( $cfg['type'] ?? '' ) );
check( 'B-4b three datasets: needed (teal, filled), you now (amber), you at 90 (navy dotted)', 3 === count( $cfg['data']['datasets'] ?? array() )
    && $series['at_90'] == $cfg['data']['datasets'][2]['data'] && '#2c3e50' === $cfg['data']['datasets'][2]['borderColor'] && "You at 90 on today's path" === $cfg['data']['datasets'][2]['label']
    && $series['needed'] == $cfg['data']['datasets'][0]['data'] && $series['now'] == $cfg['data']['datasets'][1]['data']
    && '#3d8da0' === $cfg['data']['datasets'][0]['borderColor'] && '#d97706' === $cfg['data']['datasets'][1]['borderColor'] && false === $cfg['data']['datasets'][1]['fill'] );
check( 'B-4c scale fixed 0 to 5, legend on', 0 === $cfg['options']['scales']['r']['min'] && 5 === $cfg['options']['scales']['r']['max'] && true === $cfg['options']['plugins']['legend']['display'] );
$noproj = $series; $noproj['at_90'] = array_fill( 0, 6, null );
parse_str( (string) parse_url( HDLV2_Why_Picks::chart_url( $noproj ), PHP_URL_QUERY ), $q2 );
check( 'B-4e no age → two datasets only', 2 === count( json_decode( $q2['c'], true )['data']['datasets'] ) );
check( 'B-4d fewer than two measured abilities → no picture', '' === HDLV2_Why_Picks::chart_url( $thin ) && '' === HDLV2_Why_Picks::chart_url( array() ) );

// ── B-5 payload ──
$row = function ( $s2, $s1 = array() ) { return (object) array( 'stage1_data' => json_encode( $s1 ), 'stage2_data' => json_encode( $s2 ) ); };
$S1  = array( 'q1_age' => '55', 'q1_sex' => 'male', 'q4' => 'c', 'q5' => 'd', 'server_result' => array( 'raw' => $raw ) );
$pf  = HDLV2_Why_Picks::payload_fields( $row( array( 'form_flow' => 'single', 'why_picks' => $worked ), $S1 ), $s3 );
check( 'B-5 nine keys, why_chart_url last', 9 === count( $pf ) && 'why_chart_url' === array_key_last( $pf ) && 0 === strpos( $pf['why_chart_url'], 'https://quickchart.io/' ) );
$old = HDLV2_Why_Picks::payload_fields( $row( array( 'vision_text' => 'typed' ), $S1 ), $s3 );
check( 'B-5b old-flow row: nine keys, all empty', 9 === count( $old ) && array( '' ) === array_values( array_unique( $old ) ) );
check( 'B-5c why_abilities carries now and the projection',
    0 === strpos( $pf['why_abilities'], 'Strength (need 3.3 of 5, now 3.5, about 1.7 at 90 on today\'s path, needs 5 or more now to keep it' ) );
$noage = HDLV2_Why_Picks::payload_fields( $row( array( 'form_flow' => 'single', 'why_picks' => $worked ), array( 'q4' => 'c', 'q5' => 'd', 'server_result' => array( 'raw' => $raw ) ) ), $s3 );
check( 'B-5d no age on the row → no projection words, the rest stays', 0 === strpos( $noage['why_abilities'], 'Strength (need 3.3 of 5, now 3.5' ) && false === strpos( $noage['why_abilities'], 'at 90' ) );

// ── B-6 prompt block ──
$blk = HDLV2_Why_Picks::block_for_row( $row( array( 'form_flow' => 'single', 'why_picks' => $worked ), $S1 ), $s3, 'facts' );
check( 'B-6 measured line carries the projection phrases',
    false !== strpos( $blk, "- Balance: needed 3.3/5 by their choices (5 of 5 picks); measured 2/5 from Standing on one leg 2/5; about 0.7/5 at 90 on today's path; keeping it to 90 needs 5 or more now\n" ) );
check( 'B-6b HOW TO USE: the projection is arithmetic, never a measured change',
    false !== strpos( $blk, "The projection to 90 is arithmetic on today's number using published average losses for each ability, age and sex (about 5 to 34% a decade), not a trend: use it to say what keeping the ability needs, never as a measured change.\n" ) );
check( 'B-6c no age → no projection words and no projection sentence',
    false === strpos( HDLV2_Why_Picks::block_for_row( $row( array( 'form_flow' => 'single', 'why_picks' => $worked ), array( 'q5' => 'd', 'server_result' => array( 'raw' => $raw ) ) ), $s3, 'facts' ), 'at 90' ) );
check( 'B-6d old-flow row → empty block', '' === HDLV2_Why_Picks::block_for_row( $row( array( 'vision_text' => 'typed' ), $S1 ), $s3 ) );

// ── B-7 the report page data, through the REAL draft handler ──
class FakeWpdb {
    public $prefix = 'wp_';
    public $progress; public $draft;
    public function prepare( $q, ...$a ) { return $q; }
    public function get_row( $q ) {
        if ( false !== strpos( $q, 'hdlv2_form_progress' ) ) return $this->progress;
        if ( false !== strpos( $q, "report_type = 'draft'" ) ) return $this->draft;
        return null;
    }
    public function get_var( $q ) { return null; }
    public function get_results( $q ) { return array(); }
}
global $wpdb;
$wpdb = new FakeWpdb();
$wpdb->draft = (object) array( 'id' => 9, 'report_content' => json_encode( array( 'ai_narrative' => array( 'opening' => 'x' ) ) ), 'milestones' => '{}', 'status' => 'ready', 'created_at' => '2026-10-09 00:00:00' );
$progress = function ( $s2 ) use ( $S1, $s3 ) {
    return (object) array( 'id' => 70, 'client_user_id' => 42, 'practitioner_user_id' => 3, 'client_name' => 'A',
        'stage1_data' => json_encode( $S1 ), 'stage2_data' => json_encode( $s2 ),
        'stage3_data' => json_encode( array( 'server_result' => array( 'rate' => 1.1, 'bio_age' => 57, 'scores' => $s3 ) ) ),
        'stage3_completed_at' => '2026-10-09 00:00:00', 'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
};
class Req { public function get_param( $k ) { return 'token' === $k ? str_repeat( 'ab', 32 ) : null; } }
$wpdb->progress = $progress( array( 'form_flow' => 'single', 'why_picks' => $worked ) );
$r = HDLV2_Client_Draft_View::get_instance()->rest_get_draft( new Req() );
check( 'B-7 single row: the draft data carries why_abilities { rows, series }', is_array( $r ) && 'ready' === ( $r['status'] ?? '' )
    && array( 'rows', 'series' ) === array_keys( $r['why_abilities'] ?? array() )
    && $r['why_abilities']['rows'] === HDLV2_Why_Picks::abilities_profile( $worked, $raw, $s3, '55', 'male' )
    && $r['why_abilities']['series'] === HDLV2_Why_Picks::ability_chart_series( $r['why_abilities']['rows'], $raw, $s3 ) );
$wpdb->progress = $progress( array( 'form_flow' => 'single', 'why_picks' => $worked ) );
$wpdb->progress->stage1_data = json_encode( array( 'q1_age' => '55' ) );
$wpdb->progress->stage3_data = json_encode( array( 'server_result' => array( 'rate' => 1.1, 'scores' => array( 'balance' => 2 ) ) ) );
$r = HDLV2_Client_Draft_View::get_instance()->rest_get_draft( new Req() );
check( 'B-7c one measured ability → rows kept, no chart (same rule as the PDF picture)', ! empty( $r['why_abilities']['rows'] ) && null === $r['why_abilities']['series'] );
$wpdb->progress = $progress( array( 'vision_text' => 'typed the old way' ) );
$r = HDLV2_Client_Draft_View::get_instance()->rest_get_draft( new Req() );
check( 'B-7b old-flow row: no why_abilities key at all', is_array( $r ) && 'ready' === ( $r['status'] ?? '' ) && ! array_key_exists( 'why_abilities', $r ) );

echo "\n" . ( $fail ? "SCENARIO: FAIL ($fail)\n" : "SCENARIO: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );
