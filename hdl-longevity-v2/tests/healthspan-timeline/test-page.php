<?php
/**
 * Healthspan Timeline page data (v0.48.29; body class v0.48.30). Standalone: no WordPress, no DB.
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
define( 'HDLV2_PLUGIN_URL', 'https://stby.test/wp-content/plugins/hdl-longevity-v2/' );
define( 'HDLV2_VERSION', 'test' );

$GLOBALS['options'] = array();
$GLOBALS['inline']  = array();
$GLOBALS['owners']  = array(); // "prac|client" pairs practitioner_owns_client() answers true for

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function add_action( $hook, $cb, $p = 10, $a = 1 ) {}
function add_shortcode( $tag, $cb ) {}
function add_filter( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['filters'][ $hook ][] = $cb; }
function is_admin() { return ! empty( $GLOBALS['is_admin'] ); }
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
function rest_url( $p = '' ) { return 'https://stby.test/wp-json/' . $p; }
function wp_unslash( $x ) { return $x; }
function is_page( $slug = '' ) { return ( $GLOBALS['page_slug'] ?? '' ) === $slug; }
function get_post() { return $GLOBALS['post'] ?? null; }
function has_shortcode( $content, $tag ) { return false !== strpos( (string) $content, '[' . $tag ); }
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
    public $tickets  = array(); // v0.48.60: widget_invites rows (client_email, source, external_ref, status)
    public $configs  = array(); // v0.48.60: practitioner_user_id => (object) widget_config row
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
        if ( false !== strpos( $sql, 'FROM wp_hdlv2_widget_config' ) && false !== strpos( $sql, 'practitioner_user_id =' ) ) {
            return $this->configs[ (int) $this->args[0] ] ?? null;
        }
        if ( false !== strpos( $sql, 'FROM wp_hdlv2_reports' ) ) {
            $rows = $this->reports[ (int) $this->args[0] ] ?? array();
            usort( $rows, function ( $a, $b ) { return ( 'final' === $b->report_type ) <=> ( 'final' === $a->report_type ) ?: $b->id <=> $a->id; } );
            return $rows[0] ?? null;
        }
        return null;
    }
    /** A bought Full Report: the products strip's ticket query. Every clause must be in the SQL, or nothing matches. */
    public function get_var( $sql ) {
        $this->queries[] = $sql;
        if ( false === strpos( $sql, 'FROM wp_hdlv2_widget_invites' ) ) return null;
        foreach ( array( 'LOWER(client_email) =', "source = 'full_report'", 'external_ref IS NOT NULL', "status <> 'revoked'" ) as $clause ) {
            if ( false === strpos( $sql, $clause ) ) return null;
        }
        foreach ( $this->tickets as $t ) {
            if ( strtolower( $t['client_email'] ) === $this->args[0] && 'full_report' === $t['source'] && null !== $t['external_ref'] && 'revoked' !== $t['status'] ) return '1';
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
        'client_name' => 'Kim Lane', 'client_email' => 'kim' . $id . '@example.test', 'token' => tok( (string) ( $id % 10 ) ), 'created_at' => '2026-10-12 08:00:00',
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
$db->progress[ tok( '8' ) ] = row( 8 );
$db->reports[8] = array( (object) array( 'id' => 18, 'report_type' => 'draft', 'report_content' => '{}' ) );
$d = $T::page_data( tok( '8' ) );
$T::page_data( tok( '8' ) );
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
check( '(k) L1 a fragment visitor\'s line carries the price and says where the code is', 'Your loved ones unlock with the Full Report (today $27 with the code from your email).' === $o['line'] );
check( '(k) button + offer url; anonymous → the code word never printed', 'Get the full report' === $o['button'] && 'https://altituding.com/full-report' === $o['url'] );
$db->progress[ tok( '9' ) ] = row( 9, array( 'stage3_data' => null, 'stage3_completed_at' => null ) );
$o = $T::page_data( tok( '9' ) )['offer'];
check( '(k) a known client (token) → the url carries the code, as the dashboard offer card', 'https://altituding.com/full-report?code=YOURCODE' === $o['url'] );
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'url' => '' );
$o = $T::page_data( '' )['offer'];
check( '(k) no offer configured → button absent, line "unlocks with the full report"', ! isset( $o['button'] ) && ! isset( $o['url'] ) && 'Your loved ones unlock with the full report.' === $o['line'] );
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'practitioner_id' => 77 );
$o = $T::page_data( tok( '9' ) )['offer'];
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
$db->progress[ tok( '7' ) ] = row( 10, array( 'client_name' => '</script><script>x' ) );
$_GET['t'] = tok( '7' );
$T::render_shortcode();
check( 'printed JSON cannot close the script tag', false === strpos( $GLOBALS['inline'][0] ?? '</script>', '</script>' ) );

// Ultimate Member's "logged-in only" site: the page is public (the token and the fragment are the access).
$db = fresh();
$u = $T::um_public( array( '/longevity-draft-report/' ) );
check( 'UM: flag on → the page URL joins the exclusions, existing ones kept', array( '/longevity-draft-report/', 'https://stby.test/healthspan-timeline/' ) === $u );
check( 'UM: a non-array option still works', array( 'https://stby.test/healthspan-timeline/' ) === $T::um_public( '' ) );
$GLOBALS['is_admin'] = true;
check( 'UM: never in wp-admin (the settings screen would save it)', array( '/x' ) === $T::um_public( array( '/x' ) ) );
$GLOBALS['is_admin'] = false;
$GLOBALS['options'] = array();
check( 'UM: flag off → unchanged', array( '/x' ) === $T::um_public( array( '/x' ) ) );

// Report-page card + dashboard line (Task 4).
$db = fresh();
check( 'report_url for a token', 'https://stby.test/healthspan-timeline/?t=' . tok( 'a' ) === $T::report_url( tok( 'a' ) ) );
$db->progress['s1'] = row( 20, array( 'client_user_id' => 700, 'stage3_data' => null, 'stage3_completed_at' => null, 'token' => tok( 's' ) ) );
$l = $T::dashboard_url( 700 );
check( 'dashboard: Stage-1-only → the preview URL with the fragment, no token', 'https://stby.test/healthspan-timeline/#a=52&s=F&r=1.10&n=Kim' === $l );
$db->progress['s1'] = row( 21, array( 'client_user_id' => 701, 'token' => tok( 'r' ) ) );
$db->reports[21] = array( (object) array( 'id' => 31, 'report_type' => 'draft', 'report_content' => '{}' ) );
check( 'dashboard: a client with a report → the token URL', 'https://stby.test/healthspan-timeline/?t=' . tok( 'r' ) === $T::dashboard_url( 701 ) );
$db->progress['s1'] = row( 22, array( 'client_user_id' => 702, 'token' => tok( 'p' ) ) );
check( 'dashboard: Stage 3 done, no report yet → the token URL (preview with their Stage 3 numbers)', 'https://stby.test/healthspan-timeline/?t=' . tok( 'p' ) === $T::dashboard_url( 702 ) );
check( 'dashboard: no record → nothing', '' === $T::dashboard_url( 999 ) );
$GLOBALS['options'] = array();
check( 'dashboard: flag off → nothing', '' === $T::dashboard_url( 701 ) );

// v0.48.30: Divi caps the page in its 80 % row; the consultation page's body class lets the CSS widen it.
$db = fresh();
$W = function () use ( $T ) { return $T::wide_body_class( array( 'page' ) ); };
check( 'body class: the filter is registered', ! empty( $GLOBALS['filters']['body_class'] ) );
$GLOBALS['page_slug'] = 'healthspan-timeline';
$GLOBALS['post']      = (object) array( 'post_content' => '[hdlv2_healthspan_timeline]' );
check( 'body class: flag on, the page, the shortcode → hdlv2-wide-page added', array( 'page', 'hdlv2-wide-page' ) === $W() );
$GLOBALS['options'] = array();
check( 'body class: flag off → unchanged', array( 'page' ) === $W() );
$GLOBALS['options']['hdlv2_ff_healthspan_timeline'] = '1';
$GLOBALS['is_admin'] = true;
check( 'body class: wp-admin → unchanged', array( 'page' ) === $W() );
$GLOBALS['is_admin']  = false;
$GLOBALS['page_slug'] = 'about';
check( 'body class: another page → unchanged', array( 'page' ) === $W() );
$GLOBALS['page_slug'] = 'healthspan-timeline';
$GLOBALS['post']      = (object) array( 'post_content' => 'no shortcode here' );
check( 'body class: the page without the shortcode → unchanged', array( 'page' ) === $W() );
$GLOBALS['post'] = null;
check( 'body class: no post → unchanged', array( 'page' ) === $W() );

// v0.48.60 — the user flow (plan 2026-11-09): the buyer's window, first paint, the practitioner's brand.
const WAIT   = 'Your Full Report is being written. Your loved ones unlock the moment it lands.';
const BOUGHT = 'Your Full Report is bought. Finish its questions, and your loved ones unlock when the report lands.';
function ticket( $email, $over = array() ) { return $over + array( 'client_email' => $email, 'source' => 'full_report', 'external_ref' => 'cs_test_1', 'status' => 'pending' ); }
$waiting = function ( $d, $line = WAIT ) {
    return 'preview' === ( $d['mode'] ?? '' ) && true === ( $d['lock'] ?? null ) && true === ( $d['paid'] ?? null )
        && ! isset( $d['offer']['button'] ) && ! isset( $d['offer']['url'] ) && true === ( $d['offer']['wait'] ?? null )
        && $line === ( $d['offer']['line'] ?? '' ) && $line === ( $d['wait_line'] ?? '' );
};

// P1: a Full Report ticket for the client's email, no report row yet → the waiting line, no button (DA2 kept).
$db = fresh();
$db->progress[ tok( '1' ) ] = row( 41, array( 'stage3_data' => null, 'stage3_completed_at' => null, 'client_email' => 'Buyer@Example.test' ) );
$db->tickets[] = ticket( 'buyer@example.test' );
check( 'P1 ticket, questions not done, no report → preview, lock, paid, "bought, finish its questions", no button', $waiting( $T::page_data( tok( '1' ) ), BOUGHT ) );
$db->tickets = array( ticket( 'buyer@example.test', array( 'status' => 'revoked' ) ), ticket( 'buyer@example.test', array( 'source' => 'challenge' ) ), ticket( 'buyer@example.test', array( 'external_ref' => null ) ) );
check( 'P1 a revoked ticket, a challenge ticket or one with no payment ref → not paid, the offer as today', false === $T::page_data( tok( '1' ) )['paid'] && isset( $T::page_data( tok( '1' ) )['offer']['button'] ) );

// P2: Stage 3 submitted, no report row yet → the same.
$db = fresh();
$db->progress[ tok( '2' ) ] = row( 42, array( 'stage3_data' => null ) );
check( 'P2 Stage 3 submitted + no report → "being written", no button', $waiting( $T::page_data( tok( '2' ) ) ) );
$db->tickets[] = ticket( 'kim42@example.test' );
check( 'P2b a ticket and Stage 3 submitted → "being written" (the questions are done)', $waiting( $T::page_data( tok( '2' ) ) ) );

// P3: no ticket, no Stage 3 → the offer as today: the code in the url for a known client, none for a fragment visitor.
$db = fresh();
$db->progress[ tok( '3' ) ] = row( 43, array( 'stage3_data' => null, 'stage3_completed_at' => null ) );
$d = $T::page_data( tok( '3' ) );
check( 'P3 a known client, nothing bought → paid false, the code in the button\'s url', false === $d['paid'] && 'Get the full report' === $d['offer']['button'] && 'https://altituding.com/full-report?code=YOURCODE' === $d['offer']['url'] && ! isset( $d['wait_line'] ) );
$d = $T::page_data( '' );
check( 'P3 a fragment visitor → paid false, the offer url without a code', false === $d['paid'] && 'https://altituding.com/full-report' === $d['offer']['url'] && ! isset( $d['wait_line'] ) );
$d = $T::page_data( tok( '3' ) );
check( 'P3 a known client keeps today\'s line', 'Your loved ones unlock with the full report (today $27).' === $d['offer']['line'] );
$db->reports[43] = array( (object) array( 'id' => 53, 'report_type' => 'final', 'report_content' => '{}' ) );
$db->tickets[]   = ticket( 'kim43@example.test' );
check( 'P3 full mode with a report → paid true, no waiting line (the loved ones are open)', 'full' === $T::page_data( tok( '3' ) )['mode'] && true === $T::page_data( tok( '3' ) )['paid'] && ! isset( $T::page_data( tok( '3' ) )['wait_line'] ) );

// P4: the dashboard link of a buyer in the window opens the token page, not the fragment preview.
$db = fresh();
$db->progress['s1'] = row( 44, array( 'client_user_id' => 744, 'stage3_data' => null, 'stage3_completed_at' => null, 'token' => tok( 'b' ), 'client_email' => 'buyer44@example.test' ) );
check( 'P4 dashboard: nothing bought → still the fragment preview', 0 === strpos( $T::dashboard_url( 744 ), 'https://stby.test/healthspan-timeline/#a=' ) );
$db->tickets[] = ticket( 'BUYER44@example.test' );
check( 'P4 dashboard: a ticket for the email → the token URL', 'https://stby.test/healthspan-timeline/?t=' . tok( 'b' ) === $T::dashboard_url( 744 ) );

// S1, S2: the title, the subtitle and a skeleton are in the HTML before any script runs.
$db = fresh();
$GLOBALS['options']['hdlv2_ht_title'] = 'Your <Life> Map';
$html = $T::render_shortcode();
$jsrc = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/hdlv2-healthspan-timeline.js' );
$sub  = preg_match( '#<p class="ht-sub">([^<]+)</p>#', $html, $m ) ? $m[1] : '';
check( 'S1 the root carries <h1 id="ht-title"> with the title option, escaped', false !== strpos( $html, '<div id="hdlv2-ht-root" class="hdlv2-ht"><header class="ht-top"><h1 id="ht-title">Your Map</h1>' ) );
check( 'S1 and <p class="ht-sub"> with the page script\'s own subtitle (one wording, two copies)', '' !== $sub && false !== strpos( $jsrc, str_replace( '&#039;', "\\'", $sub ) ) );
check( 'S2 a skeleton of the first two panels, busy', 1 === preg_match( '#<div class="ht-skeleton" role="status" aria-busy="true" aria-label="Loading your timeline"><div></div><div></div></div></div>$#', $html ) );
check( 'S2 the expired message carries no skeleton', ( function () use ( $T ) { $_GET['t'] = tok( 'z' ); $h = $T::render_shortcode(); $_GET = array(); return false === strpos( $h, 'ht-skeleton' ); } )() );

// B1: a token page names the client's practitioner from their widget settings; nothing without a row; never on the open page.
$db = fresh();
$db->progress[ tok( '5' ) ] = row( 45 );
check( 'B1 no widget_config row → no prac key', ! array_key_exists( 'prac', $T::page_data( tok( '5' ) ) ) );
$db->configs[122] = (object) array( 'practitioner_user_id' => 122, 'practitioner_name' => "Matthew D'haemer", 'logo_url' => 'https://stby.test/logo.png' );
$d = $T::page_data( tok( '5' ) );
check( 'B1 token page → prac { name, logo } from the practitioner\'s widget settings', array( 'name' => "Matthew D'haemer", 'logo' => 'https://stby.test/logo.png' ) === ( $d['prac'] ?? null ) );
$db->configs[122]->logo_url = 'javascript:alert(1)';
check( 'B1 a logo that is not https is dropped, the name stays', array( 'name' => "Matthew D'haemer", 'logo' => '' ) === ( $T::page_data( tok( '5' ) )['prac'] ?? null ) );
check( 'B1 the open page (no token) → no prac key; the REST base for the page\'s own lookup', ! array_key_exists( 'prac', $T::page_data( '' ) ) && 'https://stby.test/wp-json/hdl-v2/v1/' === ( $T::page_data( '' )['rest'] ?? '' ) );
check( 'B3 the footer line is HDLV2_Offer::PARTNER_LINE (one source with the report page and the emails)', HDLV2_Offer::PARTNER_LINE === ( $T::page_data( '' )['partner_line'] ?? '' ) && HDLV2_Offer::PARTNER_LINE === ( $T::page_data( tok( '5' ) )['partner_line'] ?? '' ) );

// v0.48.34 (Task 5b, calc review 2026-10-30): the small print says once what "pace" means here.
// The disclaimer is set by the page script (renderStatic → #ht-disc), so this reads that line.
$js   = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/hdlv2-healthspan-timeline.js' );
$disc = preg_match( "/\\\$\\('#ht-disc'\\)\\.textContent = '((?:[^'\\\\]|\\\\.)*)'/", $js, $m ) ? stripslashes( $m[1] ) : '';
check( 'disclaimer: the pace sentence follows "Your own biological age and pace come from your HealthDataLab report"', false !== strpos( $disc, 'come from your HealthDataLab report. Your report measures pace against people your own age; this page reads it as years of ageing per calendar year, so the two are close but not the same measure.' ) );

done();
