<?php
/**
 * Healthspan Timeline page data (v0.48.29). Standalone: no WordPress, no DB.
 * Fake $wpdb holds form_progress rows (by token) and report rows (by
 * form_progress_id); the REAL HDLV2_Healthspan_Timeline, HDLV2_Offer and
 * HDLV2_Stage1_Commentary build the array the page prints.
 *
 *   php tests/healthspan-timeline/test-page.php
 */
error_reporting( E_ALL & ~E_DEPRECATED );
$LOG = tempnam( sys_get_temp_dir(), 'htlog' );
ini_set( 'error_log', $LOG );
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['options'] = array();
$GLOBALS['inline']  = array();
$GLOBALS['owners']  = array(); // "prac|client" pairs practitioner_owns_client() answers true for

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function add_action( $hook, $cb, $p = 10, $a = 1 ) {}
function add_shortcode( $tag, $cb ) {}
function get_current_user_id() { return $GLOBALS['current_user'] ?? 0; }
function wp_json_encode( $x, $flags = 0 ) { return json_encode( $x, $flags ); }
function absint( $x ) { return abs( (int) $x ); }
function sanitize_text_field( $x ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $x ) ) ); }
function esc_html( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES, 'UTF-8' ); }
function esc_url_raw( $x ) { return (string) $x; }
function add_query_arg( $key, $value, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value; }
function home_url( $p = '' ) { return 'https://stby.test' . $p; }
function wp_unslash( $x ) { return $x; }
function wp_enqueue_script() {}
function wp_enqueue_style() {}
function wp_add_inline_script( $handle, $js, $pos = 'after' ) { $GLOBALS['inline'][] = $js; return true; }

class HDLV2_Compatibility {
    public static function practitioner_owns_client( $p, $c ) { return in_array( (int) $p . '|' . (int) $c, $GLOBALS['owners'], true ); }
}

class FakeWpdb {
    public $prefix = 'wp_';
    public $progress = array(); // token => (object) row (deleted_at may be set)
    public $reports  = array(); // form_progress_id => list of (object) rows
    public $queries  = array();
    private $args = array();
    public function prepare( $sql, ...$args ) {
        $this->args = $args;
        foreach ( $args as $a ) {
            $sql = preg_replace( '/%[dsf]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $sql, 1 );
        }
        return $sql;
    }
    public function get_row( $sql ) {
        $this->queries[] = $sql;
        if ( false !== strpos( $sql, 'FROM wp_hdlv2_form_progress' ) && false !== strpos( $sql, 'token =' ) ) {
            $row = $this->progress[ $this->args[0] ] ?? null;
            if ( $row && ! empty( $row->deleted_at ) && false !== strpos( $sql, 'deleted_at IS NULL' ) ) return null;
            return $row;
        }
        if ( false !== strpos( $sql, 'FROM wp_hdlv2_form_progress' ) && false !== strpos( $sql, 'client_user_id =' ) ) {
            foreach ( array_reverse( $this->progress ) as $row ) {
                if ( (int) $row->client_user_id === (int) $this->args[0] && empty( $row->deleted_at ) ) return $row;
            }
            return null;
        }
        if ( false !== strpos( $sql, 'FROM wp_hdlv2_reports' ) ) {
            $rows = $this->reports[ (int) $this->args[0] ] ?? array();
            usort( $rows, function ( $a, $b ) { return ( 'final' === $b->report_type ) <=> ( 'final' === $a->report_type ) ?: $b->id <=> $a->id; } );
            return $rows[0] ?? null;
        }
        return null;
    }
}

require_once dirname( __DIR__, 2 ) . '/includes/sprint-1/class-hdlv2-offer.php';
require_once dirname( __DIR__, 2 ) . '/includes/sprint-2/class-hdlv2-stage1-commentary.php';
$src = dirname( __DIR__, 2 ) . '/includes/sprint-2c/class-hdlv2-healthspan-timeline.php';

$PASS = 0; $FAIL = 0;
function check( $label, $cond ) {
    global $PASS, $FAIL;
    if ( $cond ) { $PASS++; echo "  PASS  $label\n"; }
    else         { $FAIL++; echo "  FAIL  $label\n"; }
}
function done() {
    global $PASS, $FAIL;
    @unlink( $GLOBALS['LOG'] );
    echo "\nPASS=$PASS FAIL=$FAIL\n" . ( $FAIL ? "SUITE: FAIL\n" : "SUITE: PASS\n" );
    exit( $FAIL ? 1 : 0 );
}
if ( ! file_exists( $src ) ) {
    check( 'includes/sprint-2c/class-hdlv2-healthspan-timeline.php exists', false );
    done();
}
require_once $src;
$T = 'HDLV2_Healthspan_Timeline';

const L12 = array( 'physicalActivity', 'sleepDuration', 'sleepQuality', 'stressLevels', 'socialConnections', 'dietQuality', 'alcoholConsumption', 'smokingStatus', 'cognitiveActivity', 'sunlightExposure', 'supplementIntake', 'dailyHydration' );
function tok( $c ) { return str_repeat( $c, 64 ); }
function future() { return gmdate( 'Y-m-d H:i:s', time() + 30 * 86400 ); }
function past()   { return gmdate( 'Y-m-d H:i:s', time() - 86400 ); }
function fresh() {
    global $wpdb;
    $wpdb = new FakeWpdb();
    $GLOBALS['options'] = array( 'hdlv2_ff_healthspan_timeline' => '1' );
    $GLOBALS['inline']  = array();
    $GLOBALS['owners']  = array();
    $GLOBALS['current_user'] = 0;
    $_GET = array();
    return $wpdb;
}
/** A form_progress row as Stage 1 confirm + Stage 3 write it. */
function row( $id, $over = array() ) {
    $scores = array_fill_keys( L12, 4 ) + array( 'bmiScore' => 1, 'balance' => 1 ); // non-lifestyle keys never count
    return (object) ( $over + array(
        'id' => $id, 'client_user_id' => 500 + $id, 'practitioner_user_id' => 122,
        'client_name' => 'Kim Lane', 'created_at' => '2026-10-12 08:00:00',
        'token_expires_at' => future(), 'deleted_at' => null,
        'stage1_data' => json_encode( array( 'q1_age' => 52, 'q1_sex' => 'female', 'server_result' => array( 'rate' => 1.1, 'raw' => array( 'q2_body' => 3, 'q3_zone2' => 2, 'q4_vo2' => 4, 'q5_sts' => 3, 'q6_sleep' => 2, 'q7_smoking' => 5, 'q8_social' => 4, 'q9_diet' => 3 ) ) ) ),
        'stage3_data' => json_encode( array( 'server_result' => array( 'rate' => 0.94, 'bio_age' => 48.9, 'scores' => $scores ) ) ),
        'stage3_completed_at' => '2026-10-20 09:00:00',
    ) );
}

// (a) final report row → full, numbers from the frozen calc_snapshot.
$db = fresh();
$db->progress[ tok( 'a' ) ] = row( 1 );
$db->reports[1] = array(
    (object) array( 'id' => 9, 'report_type' => 'draft', 'report_content' => '{}' ),
    (object) array( 'id' => 11, 'report_type' => 'final', 'report_content' => json_encode( array( 'calc_snapshot' => array( 'rate' => 0.97, 'bio_age' => 50.4, 'scores' => array_fill_keys( L12, 2 ) ) ) ) ),
);
$d = $T::page_data( tok( 'a' ) );
check( '(a) final row → mode full, lock false', 'full' === ( $d['mode'] ?? '' ) && false === ( $d['lock'] ?? null ) );
check( '(a) you from calc_snapshot: rate0 0.97, bio 50.4', 0.97 === ( $d['you']['rate0'] ?? null ) && 50.4 === ( $d['you']['bio'] ?? null ) );
check( '(a) you: first name, born = 2026 − 52, sex F', 'Kim' === $d['you']['name'] && 1974 === $d['you']['born'] && 'F' === $d['you']['sex'] );
check( '(a) life scores = the 12 lifestyle values only, source report', array_fill( 0, 12, 2 ) === $d['you']['life_scores'] && 'report' === $d['you']['life_source'] );
check( '(a) storage key is the progress row\'s own', 'hs-state-1' === $d['store'] );

// (b) draft row only → full, numbers from the draft's calc (Stage 3 server_result, as the draft view).
$db = fresh();
$db->progress[ tok( 'b' ) ] = row( 2 );
$db->reports[2] = array( (object) array( 'id' => 12, 'report_type' => 'draft', 'report_content' => '{}' ) );
$d = $T::page_data( tok( 'b' ) );
check( '(b) draft row → mode full', 'full' === $d['mode'] && false === $d['lock'] );
check( '(b) you from the draft calc: rate0 0.94, bio 48.9, life 12×4', 0.94 === $d['you']['rate0'] && 48.9 === $d['you']['bio'] && array_fill( 0, 12, 4 ) === $d['you']['life_scores'] );

// (c) Stage 3 complete, no report row → preview with their Stage 3 numbers, locked.
$db = fresh();
$db->progress[ tok( 'c' ) ] = row( 3 );
$d = $T::page_data( tok( 'c' ) );
check( '(c) no report row → mode preview, lock true', 'preview' === $d['mode'] && true === $d['lock'] );
check( '(c) you from stage3 server_result', 0.94 === $d['you']['rate0'] && 48.9 === $d['you']['bio'] && 'report' === $d['you']['life_source'] );
check( '(c) preview storage key is the shared preview one', 'hs-state-preview' === $d['store'] );

// (d) Stage 1 only → preview; bio = round(rate × age, 1); life from the eight raw answers.
$db = fresh();
$db->progress[ tok( 'd' ) ] = row( 4, array( 'stage3_data' => null, 'stage3_completed_at' => null ) );
$d = $T::page_data( tok( 'd' ) );
check( '(d) Stage 1 only → mode preview, rate0 1.1', 'preview' === $d['mode'] && 1.1 === $d['you']['rate0'] );
check( '(d) bio = round(1.1 × 52, 1) = 57.2 (HDLV2_Stage1_Commentary::biological_age)', 57.2 === $d['you']['bio'] );
check( '(d) life_source stage1, the eight raw answers', 'stage1' === $d['you']['life_source'] && array( 3, 2, 4, 3, 2, 5, 4, 3 ) === $d['you']['life_scores'] );

// (e) expired token: empty, unless the caller is the client or the owning practitioner.
$db = fresh();
$db->progress[ tok( 'e' ) ] = row( 5, array( 'token_expires_at' => past() ) );
$db->reports[5] = array( (object) array( 'id' => 15, 'report_type' => 'draft', 'report_content' => '{}' ) );
check( '(e) expired, anonymous → empty', array() === $T::page_data( tok( 'e' ) ) );
$GLOBALS['current_user'] = 999;
check( '(e) expired, another practitioner → empty', array() === $T::page_data( tok( 'e' ) ) );
$GLOBALS['owners'] = array( '122|505' ); $GLOBALS['current_user'] = 122;
check( '(e) expired, the owning practitioner → full data', 'full' === ( $T::page_data( tok( 'e' ) )['mode'] ?? '' ) );
$GLOBALS['owners'] = array(); $GLOBALS['current_user'] = 505;
check( '(e) expired, the client themself (cookie) → full data', 'full' === ( $T::page_data( tok( 'e' ) )['mode'] ?? '' ) );
$GLOBALS['current_user'] = 0;
$db->progress[ tok( 'e' ) ]->token_expires_at = null;
check( '(e) NULL expiry fails closed → empty', array() === $T::page_data( tok( 'e' ) ) );

// (f) soft-deleted row → empty; the lookup excludes deleted rows in SQL.
$db = fresh();
$db->progress[ tok( 'f' ) ] = row( 6, array( 'deleted_at' => '2026-10-21 00:00:00' ) );
check( '(f) soft-deleted → empty', array() === $T::page_data( tok( 'f' ) ) );
check( '(f) query carries deleted_at IS NULL', false !== strpos( implode( "\n", $db->queries ), 'deleted_at IS NULL' ) );
check( '(f) a malformed token never reaches the DB → empty', array() === $T::page_data( 'abc' ) && 1 === count( $db->queries ) );

// (g) no token → preview, you empty (the page reads the fragment).
$db = fresh();
$d = $T::page_data( '' );
check( '(g) no token → mode preview, you empty, lock true', 'preview' === $d['mode'] && array() === $d['you'] && true === $d['lock'] );

// (h) hdlv2_ht_gate = addon → behaves as full_report and logs once.
$db = fresh();
$GLOBALS['options']['hdlv2_ht_gate'] = 'addon';
$db->progress[ tok( 'h' ) ] = row( 8 );
$db->reports[8] = array( (object) array( 'id' => 18, 'report_type' => 'draft', 'report_content' => '{}' ) );
$d = $T::page_data( tok( 'h' ) );
$T::page_data( tok( 'h' ) );
check( '(h) addon gate → full like full_report', 'full' === $d['mode'] );
check( '(h) logs once per request', 1 === substr_count( (string) file_get_contents( $LOG ), 'hdlv2_ht_gate' ) );

// (i) the challenge card option.
$db = fresh();
check( '(i) card option empty → no card key', ! array_key_exists( 'card', $T::page_data( '' ) ) );
$GLOBALS['options']['hdlv2_ht_card'] = '{"title":"Try the 30-day challenge","text":"One small step a day <b>","button":"Start the challenge","url":"https://altituding.com/challenge"}';
$c = $T::page_data( '' )['card'] ?? array();
check( '(i) card set → the four fields', 'Try the 30-day challenge' === ( $c['title'] ?? '' ) && 'One small step a day' === $c['text'] && 'Start the challenge' === $c['button'] && 'https://altituding.com/challenge' === $c['url'] );
$GLOBALS['options']['hdlv2_ht_card'] = array( 'title' => 'X', 'text' => '', 'button' => 'Go', 'url' => 'javascript:alert(1)' );
check( '(i) card url not https → no card', ! array_key_exists( 'card', $T::page_data( '' ) ) );
$GLOBALS['options']['hdlv2_ht_card'] = '{"title":"X","button":"Go","url":"http://altituding.com/x"}';
check( '(i) plain http → no card', ! array_key_exists( 'card', $T::page_data( '' ) ) );

// (j) title.
$db = fresh();
check( '(j) title default Healthspan Timeline', 'Healthspan Timeline' === $T::page_data( '' )['title'] );
$GLOBALS['options']['hdlv2_ht_title'] = 'Your Life Map';
check( '(j) title option overrides', 'Your Life Map' === $T::page_data( '' )['title'] );

// (k) the lock line and the button come from HDLV2_Offer.
$db = fresh();
$o = $T::page_data( '' )['offer'];
check( '(k) line carries the offer\'s price', 'Your loved ones unlock with the full report (today $27).' === $o['line'] );
check( '(k) button + offer url; anonymous → the code word never printed', 'Get the full report' === $o['button'] && 'https://altituding.com/full-report' === $o['url'] );
$db->progress[ tok( 'k' ) ] = row( 9, array( 'stage3_data' => null ) );
$o = $T::page_data( tok( 'k' ) )['offer'];
check( '(k) a known client (token) → the url carries the code, as the dashboard offer card', 'https://altituding.com/full-report?code=YOURCODE' === $o['url'] );
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'url' => '' );
$o = $T::page_data( '' )['offer'];
check( '(k) no offer configured → button absent, line "unlocks with the full report"', ! isset( $o['button'] ) && ! isset( $o['url'] ) && 'Your loved ones unlock with the full report.' === $o['line'] );
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'practitioner_id' => 77 );
$o = $T::page_data( tok( 'k' ) )['offer'];
check( '(k) another practitioner\'s client → no button', ! isset( $o['button'] ) );

// Science toggle (DA5).
$db = fresh();
check( 'science hidden by default', false === $T::page_data( '' )['show_science'] );
$GLOBALS['options']['hdlv2_ht_show_science'] = '1';
check( 'science shown when hdlv2_ht_show_science = 1', true === $T::page_data( '' )['show_science'] );

// Flag + shortcode.
$db = fresh();
$GLOBALS['options'] = array();
check( 'flag off → shortcode prints nothing', '' === $T::render_shortcode() );
check( 'flag off → no widget public-config field', array() === $T::public_config_fields() );
$GLOBALS['options']['hdlv2_ff_healthspan_timeline'] = '1';
check( 'flag on → widget public-config carries timeline_url', array( 'timeline_url' => 'https://stby.test/healthspan-timeline/' ) === $T::public_config_fields() );
$html = $T::render_shortcode();
check( 'flag on, no token → mount + window.HDLV2_HT printed', false !== strpos( $html, 'id="hdlv2-ht-root"' ) && 1 === count( $GLOBALS['inline'] ) && 0 === strpos( $GLOBALS['inline'][0], 'window.HDLV2_HT = {' ) );
$GLOBALS['inline'] = array();
$_GET['t'] = tok( 'z' ); // unknown token
$html = $T::render_shortcode();
check( 'bad link → the expired message, no data printed', false !== strpos( $html, 'This link has expired. Please ask your practitioner for a fresh link.' ) && array() === $GLOBALS['inline'] );
$db->progress[ tok( 'q' ) ] = row( 10, array( 'client_name' => '</script><script>x' ) );
$_GET['t'] = tok( 'q' );
$T::render_shortcode();
check( 'printed JSON cannot close the script tag', false === strpos( $GLOBALS['inline'][0] ?? '</script>', '</script>' ) );

// Report-page card + dashboard line (Task 4).
$db = fresh();
check( 'report_url for a token', 'https://stby.test/healthspan-timeline/?t=' . tok( 'a' ) === $T::report_url( tok( 'a' ) ) );
$db->progress['s1'] = row( 20, array( 'client_user_id' => 700, 'stage3_data' => null, 'stage3_completed_at' => null, 'token' => tok( 's' ) ) );
$l = $T::dashboard_link( 700 );
check( 'dashboard: Stage-1-only → the preview URL with the fragment', false !== strpos( $l, 'href="https://stby.test/healthspan-timeline/#a=52&amp;s=F&amp;r=1.10&amp;n=Kim"' ) && false !== strpos( $l, 'Your healthspan timeline' ) && false === strpos( $l, tok( 's' ) ) );
$db->progress['s1'] = row( 21, array( 'client_user_id' => 701, 'token' => tok( 'r' ) ) );
$db->reports[21] = array( (object) array( 'id' => 31, 'report_type' => 'draft', 'report_content' => '{}' ) );
check( 'dashboard: a client with a report → the token URL', false !== strpos( $T::dashboard_link( 701 ), '?t=' . tok( 'r' ) ) );
check( 'dashboard: no record → nothing', '' === $T::dashboard_link( 999 ) );
$GLOBALS['options'] = array();
check( 'dashboard: flag off → nothing', '' === $T::dashboard_link( 701 ) );

done();
