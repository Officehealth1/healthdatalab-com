<?php
/**
 * Single-form flow through the REAL HDLV2_Staged_Form (v0.47.92).
 *
 * A covered client sends the pick-list WHY with Stage 2's submitted save:
 * the server validates it, writes the marker + a server-built vision_text,
 * claims the completion, moves the row to stage 3 and schedules the existing
 * local Claude extraction. Make's Stage 2 scenario and the practitioner's
 * "ready to invite" email are skipped. Everything else stays on today's path.
 *
 * v0.47.93 — abilities: /form/load carries the ability names, the draft and
 * the milestones receive the same picks block (with the focus line), and the
 * practitioner's client record carries stage2.choices for single-form rows.
 *
 * Run:  php scenario-single-form-flow.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HDLV2_MAKE_STAGE2_WHY', 'https://hook.example.test/stage2-why' );
define( 'HDLV2_MAKE_DRAFT_REPORT', 'https://hook.example.test/draft' );
define( 'HDLV2_MAKE_CALLBACK_SECRET', 'test-callback-secret' );
define( 'HDLV2_STAGING_SIDE_EFFECTS', true );

// ── WP stubs ──
$GLOBALS['transients'] = array();
$GLOBALS['options']    = array();
$GLOBALS['mails']      = array();
$GLOBALS['scheduled']  = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function apply_filters( $tag, $value ) { return $value; }
function home_url( $p = '' ) { return 'https://stby.example.test' . $p; }
function site_url( $p = '' ) { return 'https://stby.example.test' . $p; }
function rest_url( $p = '' ) { return 'https://stby.example.test/wp-json/' . ltrim( $p, '/' ); }
function get_userdata( $id ) { return false; }
function get_current_user_id() { return 0; }
function current_time( $fmt ) { return 'mysql' === $fmt ? gmdate( 'Y-m-d H:i:s' ) : gmdate( 'c' ); }
function wp_json_encode( $x ) { return json_encode( $x ); }
function sanitize_text_field( $x ) { return trim( strip_tags( (string) $x ) ); }
function sanitize_textarea_field( $x ) { return trim( strip_tags( (string) $x ) ); }
function wp_strip_all_tags( $x ) { return trim( strip_tags( (string) $x ) ); }
function sanitize_email( $x ) { return (string) $x; }
function wp_kses_post( $x ) { return (string) $x; }
function rest_ensure_response( $x ) { return $x; }
function add_action() {}
function add_shortcode() {}
function wp_mail( ...$a ) { $GLOBALS['mails'][] = $a; return true; }
function wp_next_scheduled( $hook, $args = array() ) {
    foreach ( $GLOBALS['scheduled'] as $s ) if ( $s[0] === $hook && $s[1] === $args ) return time() + 5;
    return false;
}
function wp_schedule_single_event( $ts, $hook, $args = array() ) { $GLOBALS['scheduled'][] = array( $hook, $args ); return true; }
function wp_remote_post( $url, $args = array() ) { return array( 'response' => array( 'code' => 200 ) ); }
function wp_generate_password( $n = 12, $s = true ) { return substr( md5( (string) mt_rand() ), 0, $n ); }
function esc_html( $x ) { return (string) $x; }
function esc_attr( $x ) { return (string) $x; }
function esc_url( $x ) { return (string) $x; }
function esc_url_raw( $x ) { return (string) $x; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function get_user_meta( ...$a ) { return ''; }
function absint( $x ) { return abs( (int) $x ); }

class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code = '', $message = '', $data = null ) {
        $this->code = $code; $this->message = $message; $this->data = $data;
    }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }

class HDLV2_Email_Templates {
    public static function __callStatic( $name, $args ) { $GLOBALS['mails'][] = array( 'template:' . $name ); return '<html></html>'; }
}
class HDLV2_Practitioner {
    public static function get_logo_url( $id, $fallback = false ) { return 'https://stby.example.test/logo.png'; }
}
class HDLV2_AI_Service {
    public static $why_calls = array();
    public static $draft_why = array();
    public static $draft_ok  = true;
    public static function extract_why( $stage2_data ) {
        self::$why_calls[] = $stage2_data;
        if ( ! self::$draft_ok ) {
            // Real why_placeholder() shape: Claude failed.
            return array( 'distilled_why' => 'WHY extraction pending — configure HDLV2_ANTHROPIC_API_KEY in wp-config.php', 'ai_reformulation' => '<p>x</p>' );
        }
        return array(
            'key_people'       => array( array( 'name' => 'Jenny', 'relationship' => 'wife' ) ),
            'motivations'      => array( 'Play on the floor with the grandchildren' ),
            'fears'            => array(),
            'distilled_why'    => 'I want to keep up with my family.',
            'ai_reformulation' => '<p>You want to keep up.</p>',
        );
    }
    public static function generate_draft_report( $calc, $s1, $why, $name = '', $s3 = array() ) {
        self::$draft_why[] = $why;
        return array( 'awaken_content' => 'a', 'lift_content' => 'l', 'thrive_content' => 't' );
    }
    public static $ms_why = array();
    public static function generate_milestones( ...$a ) { self::$ms_why[] = $a[1] ?? null; return array(); }
    public static function generate_client_draft_narrative( ...$a ) { return null; }
}
class HDLV2_Webhook_Monitor {
    public static $fires = array();
    public static function fire( $url, $args, $tag = '' ) {
        self::$fires[] = array( 'url' => $url, 'tag' => $tag, 'body' => $args['body'] ?? '' );
        return true;
    }
}

// ── Fake wpdb: applies only the predicates present in the SQL ──
class FakeWpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 0;
    public $rows = array();
    public $why = array();       // form_progress_id => row array
    public $updates = array();

    public function prepare( $sql, ...$args ) {
        if ( 1 === count( $args ) && is_array( $args[0] ) ) $args = $args[0];
        foreach ( $args as $a ) {
            $sql = preg_replace_callback( '/%[ds]/', function ( $m ) use ( $a ) {
                return '%d' === $m[0] ? (string) (int) $a : "'" . $a . "'";
            }, $sql, 1 );
        }
        return $sql;
    }
    public function get_row( $sql, $output = null ) {
        if ( preg_match( "/WHERE token = '([a-f0-9]{64})'/", $sql, $m ) ) {
            foreach ( $this->rows as $r ) {
                if ( $r->token !== $m[1] ) continue;
                if ( null !== $r->deleted_at ) return null;
                return clone $r;  // like a fresh DB read
            }
            return null;
        }
        if ( preg_match( '/FROM wp_hdlv2_why_profiles WHERE form_progress_id = (\d+)/', $sql, $m ) ) {
            $w = $this->why[ (int) $m[1] ] ?? null;
            if ( ! $w ) return null;
            return ARRAY_A === $output ? $w : (object) $w;
        }
        if ( preg_match( '/hdlv2_form_progress\s+WHERE id = (\d+)/', $sql, $m ) ) {
            if ( ! isset( $this->rows[ (int) $m[1] ] ) ) return null;
            $row = clone $this->rows[ (int) $m[1] ];
            // A named column list returns only those columns, like the real table.
            if ( preg_match( '/SELECT\s+(.+?)\s+FROM/s', $sql, $c ) && '*' !== trim( $c[1] ) ) {
                $row = (object) array_intersect_key( (array) $row, array_flip( array_map( 'trim', explode( ',', $c[1] ) ) ) );
            }
            return $row;
        }
        return null;
    }
    public function get_results( $sql ) {
        $out = array();
        foreach ( $this->rows as $r ) {
            if ( isset( $this->why[ $r->id ] ) || null !== $r->deleted_at ) continue;
            $fired = null !== $r->stage2_webhook_fired_at;
            $never = null === $r->stage2_webhook_fired_at && null !== $r->stage2_completed_at;
            if ( ! $fired && ! $never ) continue;
            $out[] = (object) array( 'id' => $r->id, 'token' => $r->token, 'client_user_id' => $r->client_user_id,
                'stage2_data' => $r->stage2_data, 'client_name' => $r->client_name, 'token_expires_at' => $r->token_expires_at );
        }
        return $out;
    }
    public function get_var( $sql ) {
        if ( false !== strpos( $sql, 'GET_LOCK' ) ) return 1;
        if ( preg_match( '/FROM wp_hdlv2_why_profiles WHERE form_progress_id = (\d+)/', $sql, $m ) ) {
            return isset( $this->why[ (int) $m[1] ] ) ? 1 : null;
        }
        return null;
    }
    public function query( $sql ) {
        if ( preg_match( "/SET stage2_data = '(.*)'\s+WHERE id = (\d+)\s+AND \(stage2_completed_at IS NULL/s", $sql, $m ) ) {
            $row = $this->rows[ (int) $m[2] ] ?? null;
            if ( ! $row || ( null !== $row->stage2_completed_at && '' !== $row->stage2_completed_at ) ) return 0;
            $row->stage2_data = $m[1];
            return 1;
        }
        if ( preg_match( "/SET stage2_completed_at = '([^']+)'\s+WHERE id = (\d+)/s", $sql, $m ) ) {
            $row = $this->rows[ (int) $m[2] ] ?? null;
            if ( ! $row || ( null !== $row->stage2_completed_at && '' !== $row->stage2_completed_at ) ) return 0;
            $row->stage2_completed_at = $m[1];
            return 1;
        }
        if ( preg_match( "/SET stage2_webhook_fired_at = '([^']+)',\s*stage2_text_hash = '([^']+)'\s+WHERE id = (\d+)/s", $sql, $m ) ) {
            $row = $this->rows[ (int) $m[3] ] ?? null;
            if ( ! $row || null !== $row->stage2_webhook_fired_at ) return 0;
            $row->stage2_webhook_fired_at = $m[1];
            return 1;
        }
        return 1;
    }
    public function insert( $table, $data, $formats = null ) {
        if ( false !== strpos( $table, 'why_profiles' ) ) {
            $this->why[ (int) $data['form_progress_id'] ] = $data;
        }
        $this->insert_id = 77;
        return 1;
    }
    public function update( $table, $data, $where, $f1 = null, $f2 = null ) {
        $this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
        if ( false !== strpos( $table, 'form_progress' ) && isset( $where['id'], $this->rows[ (int) $where['id'] ] ) ) {
            foreach ( $data as $k => $v ) $this->rows[ (int) $where['id'] ]->$k = $v;
        }
        return 1;
    }
}

function make_row( $id, $args = array() ) {
    return (object) array_merge( array(
        'id'                      => $id,
        'token'                   => hash( 'sha256', 'row' . $id ),
        'client_user_id'          => 900 + $id,
        'practitioner_user_id'    => 122,
        'client_name'             => 'QA Row ' . $id,
        'client_email'            => 'qa' . $id . '@example.test',
        'current_stage'           => 2,
        'stage1_data'             => '{"q1_age":"55"}',
        'stage2_data'             => '{}',
        'stage3_data'             => '{}',
        'stage1_completed_at'     => '2026-10-01 00:00:00',
        'stage2_completed_at'     => null,
        'stage3_completed_at'     => null,
        'stage2_webhook_fired_at' => null,
        'stage2_text_hash'        => '',
        'deleted_at'              => null,
        'token_expires_at'        => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ),
    ), $args );
}
function req( $body ) {
    return new class( $body ) {
        private $b;
        public function __construct( $b ) { $this->b = $b; }
        public function get_json_params() { return $this->b; }
        public function get_param( $k ) { return $this->b[ $k ] ?? null; }
    };
}
function save( $form, $row, $data, $submitted ) {
    $body = array( 'token' => $row->token, 'stage' => 2, 'data' => $data );
    if ( $submitted ) $body['submitted'] = true;
    return $form->rest_save_form( req( $body ) );
}
function stage2_fires( $row ) {
    return count( array_filter( HDLV2_Webhook_Monitor::$fires, function ( $f ) use ( $row ) {
        return HDLV2_MAKE_STAGE2_WHY === $f['url'] && ( json_decode( $f['body'], true )['token'] ?? '' ) === $row->token;
    } ) );
}
function scheduled_for( $id ) {
    return count( array_filter( $GLOBALS['scheduled'], function ( $s ) use ( $id ) {
        return 'hdlv2_stage2_local_extract' === $s[0] && array( $id ) === $s[1];
    } ) );
}
function s2( $row ) { return json_decode( $row->stage2_data, true ) ?: array(); }

require __DIR__ . '/../../includes/class-hdlv2-env.php';
require __DIR__ . '/../../includes/sprint-2/class-hdlv2-why-picks.php';
require __DIR__ . '/../../includes/sprint-2/class-hdlv2-staged-form.php';
require __DIR__ . '/../../includes/sprint-4/class-hdlv2-client-status.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) {
    global $pass, $fail;
    echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n";
    $ok ? $pass++ : $fail++;
}

$FIVE  = array( 'floor_grandkids', 'carry_shopping', 'wake_rested', 'keep_driving', 'swim_in_sea' );
$PICKS = array( 'why_picks' => $FIVE, 'key_people_text' => "my wife <b>Jenny</b>, O'Neill family", 'own_words' => 'Keep up with them.' );
$TODAY_KEYS = array( 'current_stage', 'client_name', 'client_email', 'stage1_data', 'stage1_completed_at', 'stage2_data',
    'stage2_completed_at', 'stage3_data', 'stage3_completed_at', 'practitioner_name', 'practitioner_email',
    'practitioner_cta_link', 'practitioner_cta_text', 'practitioner_logo_url' );

$wpdb = new FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;
$form = new HDLV2_Staged_Form();

// ════════ Load ════════
$wpdb->rows[1] = make_row( 1 );
$r = $form->rest_load_form( req( array( 'token' => $wpdb->rows[1]->token ) ) );
check( 'L1 switch off: load keys are exactly today\'s list', $TODAY_KEYS === array_keys( $r ) );

$GLOBALS['options']['hdlv2_ff_single_form'] = '122';
$r = $form->rest_load_form( req( array( 'token' => $wpdb->rows[1]->token ) ) );
check( 'L2 switch on, WHY open: single_form true', true === ( $r['single_form'] ?? null ) );
check( 'L3 switch on, WHY open: why_options present', ! empty( $r['why_options'][0]['items'] ) );
check( 'L5 switch on, WHY open: why_abilities = the eight display names, in order',
    array( 'strength' => 'Strength', 'mobility' => 'Mobility', 'flexibility' => 'Flexibility', 'balance' => 'Balance',
        'stamina' => 'Stamina', 'mind' => 'A sharp mind', 'energy' => 'Energy and sleep', 'connection' => 'Connection' ) === ( $r['why_abilities'] ?? null ) );
check( 'L6 switch on, WHY open: only the three new keys beside today\'s list',
    array_merge( $TODAY_KEYS, array( 'single_form', 'why_options', 'why_abilities' ) ) === array_keys( $r ) );
$wpdb->rows[2] = make_row( 2, array( 'stage2_completed_at' => '2026-10-01 10:00:00', 'stage2_data' => '{"vision_text":"typed the old way"}' ) );
$r = $form->rest_load_form( req( array( 'token' => $wpdb->rows[2]->token ) ) );
check( 'L4 switch on, WHY already sent the old way: neither key', ! isset( $r['single_form'] ) && ! isset( $r['why_options'] ) );
check( 'L7 switch on, WHY already sent the old way: keys are exactly today\'s list', $TODAY_KEYS === array_keys( $r ) );

// ════════ Submit on a covered row ════════
$row = $wpdb->rows[10] = make_row( 10 );
$res = save( $form, $row, $PICKS + array( 'vision_text' => 'CLIENT MADE THIS UP' ), true );
$d   = s2( $row );
check( 'F1 submit: success', is_array( $res ) && ! empty( $res['success'] ) );
check( 'F2 submit: form_flow single written', 'single' === ( $d['form_flow'] ?? '' ) );
check( 'F3 submit: picks + both text fields stored', $FIVE === $d['why_picks'] && isset( $d['key_people_text'], $d['own_words'] ) );
check( 'F4 submit: server-built vision_text (client value ignored)',
    HDLV2_Why_Picks::compose_vision_text( $FIVE, $d['key_people_text'], $d['own_words'] ) === $d['vision_text']
    && false === strpos( $d['vision_text'], 'CLIENT MADE' ) );
check( 'F5 submit: text fields stored as plain text', false === strpos( $d['key_people_text'], '<' ) && false !== strpos( $d['key_people_text'], "O'Neill" ) );
check( 'F6 submit: completion claimed', ! empty( $row->stage2_completed_at ) );
check( 'F7 submit: current_stage 3', 3 === (int) $row->current_stage );
check( 'F8 submit: NO Make Stage 2 call', 0 === stage2_fires( $row ) );
check( 'F9 submit: NO practitioner email', 0 === count( $GLOBALS['mails'] ) );
check( 'F10 submit: one local extraction scheduled (Make constant defined)', 1 === scheduled_for( 10 ) );

// Same submit twice (double click / retry)
$res2 = save( $form, $row, $PICKS, true );
check( 'F11 second submit: success, still one scheduled event, no Make, no mail',
    ! empty( $res2['success'] ) && 1 === scheduled_for( 10 ) && 0 === stage2_fires( $row ) && 0 === count( $GLOBALS['mails'] ) );

// Stale tab autosaves stage 2 after the submit: frozen, no deferred Make fire
$before = $row->stage2_data;
save( $form, $row, array( 'vision_text' => 'A long late transcript arriving from a stale tab', 'why_picks' => array( 'zzz' ) ), false );
check( 'F12 stale autosave after submit: no Make call', 0 === stage2_fires( $row ) );
check( 'F13 stale autosave after submit: stage2_data unchanged', $before === $row->stage2_data );

// Invalid picks
$bad = $wpdb->rows[11] = make_row( 11 );
$res = save( $form, $bad, array( 'why_picks' => array_slice( $FIVE, 0, 4 ) ), true );
check( 'F14 invalid picks: 400 with the message', is_wp_error( $res ) && 400 === ( $res->data['status'] ?? 0 ) && 'Please choose at least 5.' === $res->message );
check( 'F15 invalid picks: nothing claimed, stage 2, nothing scheduled, no marker',
    empty( $bad->stage2_completed_at ) && 2 === (int) $bad->current_stage && 0 === scheduled_for( 11 ) && ! isset( s2( $bad )['form_flow'] ) );
$res = save( $form, $bad, array( 'why_picks' => $FIVE, 'own_words' => str_repeat( 'x', 5000 ) ), true );
check( 'F16 5,000-char own words: 400, nothing claimed', is_wp_error( $res ) && empty( $bad->stage2_completed_at ) );

// Autosave of picks before submit (covered row): stored, no completion, no fire
$auto = $wpdb->rows[12] = make_row( 12 );
save( $form, $auto, array( 'why_picks' => array( 'wake_rested' ), 'vision_text' => 'hand made vision text here', 'form_flow' => 'single' ), false );
check( 'F17 autosave before submit: picks stored, marker and vision_text not',
    array( 'wake_rested' ) === ( s2( $auto )['why_picks'] ?? null ) && ! isset( s2( $auto )['form_flow'] ) && ! isset( s2( $auto )['vision_text'] ) );
check( 'F18 autosave before submit: not completed, stage 2', empty( $auto->stage2_completed_at ) && 2 === (int) $auto->current_stage );

// Review fix 1 — an autosave that read the row BEFORE the submit and writes
// after it must not wipe the marker / vision_text.
$race = $wpdb->rows[14] = make_row( 14 );
$stale = clone $race;
save( $form, $race, $PICKS, true );
$m = new ReflectionMethod( 'HDLV2_Staged_Form', 'save_single_form_why' );
$m->setAccessible( true );
$m->invoke( $form, $stale, array( 'why_picks' => array( 'wake_rested' ) ), false );
check( 'R1 in-flight autosave landing after submit: marker + vision_text kept',
    'single' === ( s2( $race )['form_flow'] ?? '' ) && ! empty( s2( $race )['vision_text'] ) );

// Review fix 2 — a row whose Stage 1 is not done cannot submit the picks.
$s1 = $wpdb->rows[15] = make_row( 15, array( 'current_stage' => 1, 'stage1_completed_at' => null ) );
$res = save( $form, $s1, $PICKS, true );
check( 'R2 stage-1 row submitting picks: 400, not moved, nothing claimed',
    is_wp_error( $res ) && 400 === ( $res->data['status'] ?? 0 ) && 1 === (int) $s1->current_stage && empty( $s1->stage2_completed_at ) );

// Review fix 4 — a covered row that had typed/recorded the old way keeps it.
$leg = $wpdb->rows[16] = make_row( 16, array( 'stage2_data' => json_encode( array( 'vision_text' => 'My recorded why from before the switch.' ) ) ) );
save( $form, $leg, $PICKS, true );
check( 'R3 earlier recorded vision_text kept as legacy_vision_text',
    'My recorded why from before the switch.' === ( s2( $leg )['legacy_vision_text'] ?? '' ) );

// ════════ Uncovered practitioner: today's path, and the marker cannot be forged ════════
$LONG = 'I want to stay strong for my grandchildren and keep hiking every summer.';
$old = $wpdb->rows[20] = make_row( 20, array( 'practitioner_user_id' => 206 ) );
save( $form, $old, array( 'vision_text' => $LONG, 'form_flow' => 'single' ), false );
check( 'U1 forged marker in an autosave: not stored', ! isset( s2( $old )['form_flow'] ) );
$mails_before = count( $GLOBALS['mails'] );
save( $form, $old, array( 'vision_text' => $LONG, 'form_flow' => 'single' ), true );
check( 'U2 forged marker in a submit: not stored', ! isset( s2( $old )['form_flow'] ) );
check( 'U3 uncovered submit: Make fires once, as today', 1 === stage2_fires( $old ) );
check( 'U4 uncovered submit: practitioner email sent, as today', count( $GLOBALS['mails'] ) > $mails_before );
check( 'U5 uncovered submit: current_stage stays 2 (practitioner Release step kept)', 2 === (int) $old->current_stage );
check( 'U6 uncovered submit: no local extraction scheduled (Make configured)', 0 === scheduled_for( 20 ) );

// Switch off half way: the marker still decides
unset( $GLOBALS['options']['hdlv2_ff_single_form'] );
save( $form, $row, array( 'vision_text' => $LONG ), false );
check( 'U7 switch turned off after submit: row still frozen, no Make', 0 === stage2_fires( $row ) && $before === $row->stage2_data );

// ════════ Extraction ════════
HDLV2_Staged_Form::run_single_stage2_extraction( 10 );
check( 'X1 single row: WHY row released = 1 with released_at', 1 === (int) ( $wpdb->why[10]['released'] ?? -1 ) && ! empty( $wpdb->why[10]['released_at'] ) );
check( 'X1b single row: summary stored as plain paragraphs like the Make rows (no <p> markup)',
    "You want to keep up." === ( $wpdb->why[10]['ai_reformulation'] ?? '' ) );
check( 'X2 single row: Claude read the server-built paragraph',
    false !== strpos( end( HDLV2_AI_Service::$why_calls )['vision_text'], 'In my later years' ) );
$wpdb->rows[21] = make_row( 21, array( 'practitioner_user_id' => 206, 'stage2_data' => json_encode( array( 'vision_text' => $LONG ) ) ) );
HDLV2_Staged_Form::run_single_stage2_extraction( 21 );
check( 'X3 old-flow row: WHY row released = 0, no released_at', 0 === (int) ( $wpdb->why[21]['released'] ?? -1 ) && ! isset( $wpdb->why[21]['released_at'] ) );

// Claude down: no placeholder WHY is released for a single row
HDLV2_AI_Service::$draft_ok = false;
$wpdb->rows[13] = make_row( 13, array( 'stage2_data' => json_encode( array( 'form_flow' => 'single', 'why_picks' => $FIVE, 'vision_text' => HDLV2_Why_Picks::compose_vision_text( $FIVE, '', '' ) ) ), 'stage2_completed_at' => '2026-10-02 00:00:00', 'current_stage' => 3 ) );
HDLV2_Staged_Form::run_single_stage2_extraction( 13 );
check( 'X4 single row, Claude failed: no placeholder WHY row written', ! isset( $wpdb->why[13] ) );
HDLV2_AI_Service::$draft_ok = true;

// ════════ Draft generation ════════
$calls = count( HDLV2_AI_Service::$why_calls );
$form->generate_draft_for_progress( clone $wpdb->rows[13] );
check( 'D1 single row with no WHY row: extraction runs first', count( HDLV2_AI_Service::$why_calls ) === $calls + 1 && isset( $wpdb->why[13] ) );
check( 'D2 single row: the draft received the WHY', 'I want to keep up with my family.' === ( end( HDLV2_AI_Service::$draft_why )['distilled_why'] ?? '' ) );
check( 'D3 single row: the draft received the picks block',
    0 === strpos( (string) ( end( HDLV2_AI_Service::$draft_why )['picks_block'] ?? '' ), '=== WHAT THIS CLIENT CHOSE' ) );
$calls = count( HDLV2_AI_Service::$why_calls );
$form->generate_draft_for_progress( clone $wpdb->rows[10] );
check( 'D4 single row with a WHY row: extraction not called again', count( HDLV2_AI_Service::$why_calls ) === $calls );
$wpdb->rows[22] = make_row( 22, array( 'practitioner_user_id' => 206, 'stage2_data' => json_encode( array( 'vision_text' => $LONG ) ) ) );
$form->generate_draft_for_progress( clone $wpdb->rows[22] );
check( 'D5 old-flow row with no WHY row: extraction never called', count( HDLV2_AI_Service::$why_calls ) === $calls );
check( 'D6 old-flow row: empty picks block', '' === ( end( HDLV2_AI_Service::$draft_why )['picks_block'] ?? '' ) );
check( 'D6b old-flow row: milestones receive an empty block too', '' === ( end( HDLV2_AI_Service::$ms_why )['picks_block'] ?? null ) );

// Review fix 3 — Claude down for both tries: the draft still gets the picks.
HDLV2_AI_Service::$draft_ok = false;
$wpdb->rows[17] = make_row( 17, array( 'stage2_data' => json_encode( array( 'form_flow' => 'single', 'why_picks' => $FIVE, 'vision_text' => HDLV2_Why_Picks::compose_vision_text( $FIVE, '', '' ) ) ), 'stage2_completed_at' => '2026-10-02 00:00:00', 'current_stage' => 3 ) );
$form->generate_draft_for_progress( clone $wpdb->rows[17] );
check( 'D7 no WHY row at all: picks block still in the draft prompt',
    ! isset( $wpdb->why[17] ) && 0 === strpos( (string) ( end( HDLV2_AI_Service::$draft_why )['picks_block'] ?? '' ), '=== WHAT THIS CLIENT CHOSE' ) );
HDLV2_AI_Service::$draft_ok = true;

// ════════ Abilities reach the draft, the milestones and the practitioner (v0.47.93) ════════
// By hand, for $FIVE with these answers: strength 3 choices, (2+2)/2 = 2.0 → 3×3 = 9 ·
// flexibility 2 choices, 2.0, nearest measure only → never a focus · mobility 1, 2.0 → 3 ·
// mind 1, 3.0 → 2 · stamina (4+4)/2 = 4.0 and energy (4+3+4)/3 = 3.7 are above 3.
$S1 = array( 'q1_age' => '55', 'q4' => 'd', 'q5' => 'b', 'server_result' => array( 'raw' => array( 'q4_vo2' => 4, 'q5_sts' => 2 ) ) );
$SCORES = array( 'sitToStand' => 2, 'balance' => 3, 'physicalActivity' => 4, 'cognitiveActivity' => 3, 'stressLevels' => 3,
    'sleepQuality' => 3, 'sleepDuration' => 4, 'dietQuality' => 4, 'socialConnections' => 4 );
$SINGLE_S2 = json_encode( array( 'form_flow' => 'single', 'why_picks' => $FIVE, 'key_people_text' => 'my wife Jenny',
    'vision_text' => HDLV2_Why_Picks::compose_vision_text( $FIVE, 'my wife Jenny', '' ) ) );
$FOCUS_LINE = "Focus areas (most chosen, weakest measured): Strength, Mobility, A sharp mind\n";
$wpdb->rows[40] = make_row( 40, array( 'stage1_data' => json_encode( $S1 ), 'stage2_data' => $SINGLE_S2,
    'stage3_data' => json_encode( array( 'sitToStand' => '2', 'server_result' => array( 'rate' => 1.1, 'scores' => $SCORES ) ) ),
    'stage2_completed_at' => '2026-10-02 00:00:00', 'stage3_completed_at' => '2026-10-02 01:00:00', 'current_stage' => 3 ) );
$form->generate_draft_for_progress( clone $wpdb->rows[40] );
$draft_block = (string) ( end( HDLV2_AI_Service::$draft_why )['picks_block'] ?? '' );
check( 'D8 single row: the draft block carries the client\'s focus line', false !== strpos( $draft_block, $FOCUS_LINE ) );
$ms_block = (string) ( end( HDLV2_AI_Service::$ms_why )['picks_block'] ?? '' );
check( 'D9 single row: milestones receive the same facts with their own instruction, the draft keeps its own',
    false !== strpos( $ms_block, $FOCUS_LINE ) && false !== strpos( $ms_block, 'Aim the milestones' ) && false === strpos( $ms_block, 'LIFT' )
    && false !== strpos( $draft_block, 'In LIFT' ) && false === strpos( $draft_block, 'Aim the milestones' ) );

// The other two milestone callers (final report, consultation preview) and
// the milestones prompt itself: wiring in the shipped files.
$fr = file_get_contents( __DIR__ . '/../../includes/sprint-2c/class-hdlv2-final-report.php' );
check( 'W1 final report: both generate_milestones() callers build the milestones block from the row',
    2 === substr_count( $fr, "HDLV2_Why_Picks::block_for_row( \$progress, \$calc_result['scores'] ?? array(), true )" ) );
$ai = file_get_contents( __DIR__ . '/../../includes/sprint-2/class-hdlv2-ai-service.php' );
$ms_fn = substr( $ai, (int) strpos( $ai, 'public static function generate_milestones(' ) );
$ms_fn = substr( $ms_fn, 0, (int) strpos( $ms_fn, "\n    }\n" ) );
check( 'W2 milestones prompt prints the block after the WHY line',
    false !== strpos( $ms_fn, '"WHY: %s\n"' . "\n" . '            . "%s"' ) && false !== strpos( $ms_fn, "\$why_profile['picks_block'] ?? ''" ) );

// Practitioner's client record.
$status = HDLV2_Client_Status::get_instance();
$rec    = $status->rest_get_client_record( array( 'progress_id' => 40 ) );
$ch     = is_array( $rec ) ? ( $rec['stage2']['choices'] ?? null ) : null;
check( 'C1 single row: stage2.choices has picks, abilities, stage3_done', is_array( $ch ) && array( 'picks', 'abilities', 'stage3_done' ) === array_keys( $ch ) );
check( 'C2 single row: each pick is its label plus ability display names',
    5 === count( $ch['picks'] ?? array() )
    && array( 'label' => 'Get down on the floor to play with my grandchildren, and get back up without help', 'needs' => array( 'Mobility', 'Flexibility', 'Strength' ) ) === $ch['picks'][0]
    && array( 'label' => 'Keep driving safely, including turning to look over my shoulder', 'needs' => array( 'A sharp mind', 'Flexibility' ) ) === $ch['picks'][3] );
$abil = $ch['abilities'] ?? array();
check( 'C3 single row: abilities are the profile of the saved answers (counts, scores, focus by hand)',
    method_exists( 'HDLV2_Why_Picks', 'abilities_profile' )
    && $abil === HDLV2_Why_Picks::abilities_profile( $FIVE, $S1['server_result']['raw'], $SCORES )
    && array( 'strength', 'mobility', 'flexibility', 'stamina', 'mind', 'energy' ) === array_column( $abil, 'id' )
    && array( 3, 1, 2, 1, 1, 1 ) === array_column( $abil, 'count' )
    && array( 2.0, 2.0, 2.0, 4.0, 3.0, 3.7 ) === array_column( $abil, 'score' )
    && array( true, true, false, false, true, false ) === array_column( $abil, 'focus' ) );
check( 'C4 single row, Stage 3 finished: stage3_done true', true === ( $ch['stage3_done'] ?? null ) );

$wpdb->rows[41] = make_row( 41, array( 'stage1_data' => json_encode( $S1 ), 'stage2_data' => $SINGLE_S2,
    'stage3_data' => json_encode( array( 'sitToStand' => '2' ) ), 'stage2_completed_at' => '2026-10-02 00:00:00', 'current_stage' => 3 ) );
$wpdb->why[41] = array( 'form_progress_id' => 41, 'distilled_why' => 'x', 'released' => 1 );
$mid = $status->rest_get_client_record( array( 'progress_id' => 41 ) )['stage2']['choices'] ?? array();
check( 'C5 single row mid-questionnaire: stage3_done false, numbers from Stage 1 only',
    false === ( $mid['stage3_done'] ?? null )
    && array( 2.0, 2.0, 2.0, 4.0, null, null ) === array_column( $mid['abilities'] ?? array(), 'score' ) );

// A Stage 1 question nobody answered: calculate_quick() stored a 3 for it.
$S1_GAP = array( 'q1_age' => '55', 'q4' => 'd', 'server_result' => array( 'raw' => array( 'q4_vo2' => 4, 'q5_sts' => 3 ) ) );
$wpdb->rows[43] = make_row( 43, array( 'stage1_data' => json_encode( $S1_GAP ), 'stage2_data' => $SINGLE_S2,
    'stage3_data' => json_encode( array( 'sitToStand' => '2' ) ), 'stage2_completed_at' => '2026-10-02 00:00:00', 'current_stage' => 3 ) );
$wpdb->why[43] = array( 'form_progress_id' => 43, 'distilled_why' => 'x', 'released' => 1 );
$gap = $status->rest_get_client_record( array( 'progress_id' => 43 ) )['stage2']['choices']['abilities'] ?? array();
check( 'C9 a filled-in Stage 1 score is not shown as measured: no number, no focus',
    array( 'strength', 'mobility', 'flexibility' ) === array_slice( array_column( $gap, 'id' ), 0, 3 )
    && array( null, null, null ) === array_slice( array_column( $gap, 'score' ), 0, 3 )
    && array( false, false, false ) === array_slice( array_column( $gap, 'focus' ), 0, 3 ) );

$old_rec = $status->rest_get_client_record( array( 'progress_id' => 21 ) );
check( 'C6 old-flow row with a WHY: stage2 has no choices key',
    is_array( $old_rec['stage2'] ?? null ) && ! array_key_exists( 'choices', $old_rec['stage2'] ) );
check( 'C7 old-flow row: stage2 keys are exactly today\'s list',
    array( 'completed_at', 'distilled_why', 'key_people', 'motivations', 'fears', 'vision_text', 'ai_reformulation', 'released', 'pdf_url' ) === array_keys( $old_rec['stage2'] ?? array() ) );
// A row with picks typed into stage2_data but no server-written marker is not a single-form row.
$wpdb->rows[42] = make_row( 42, array( 'practitioner_user_id' => 206, 'stage2_data' => json_encode( array( 'why_picks' => $FIVE, 'vision_text' => $LONG ) ) ) );
$wpdb->why[42] = array( 'form_progress_id' => 42, 'distilled_why' => 'x', 'released' => 0 );
check( 'C8 picks without the marker: no choices key', ! array_key_exists( 'choices', $status->rest_get_client_record( array( 'progress_id' => 42 ) )['stage2'] ?? array( 'choices' => 1 ) ) );

// ════════ Retry cron ════════
$w2 = new FakeWpdb();
$GLOBALS['wpdb'] = $w2;
HDLV2_Webhook_Monitor::$fires = array();
$w2->rows[30] = make_row( 30, array( 'stage2_data' => json_encode( array( 'form_flow' => 'single', 'why_picks' => $FIVE, 'vision_text' => HDLV2_Why_Picks::compose_vision_text( $FIVE, '', '' ) ) ), 'stage2_completed_at' => '2026-10-02 00:00:00', 'current_stage' => 3 ) );
$w2->rows[31] = make_row( 31, array( 'practitioner_user_id' => 206, 'stage2_data' => json_encode( array( 'vision_text' => $LONG ) ), 'stage2_completed_at' => '2026-10-02 00:00:00', 'stage2_webhook_fired_at' => '2026-10-02 00:00:00' ) );
$calls = count( HDLV2_AI_Service::$why_calls );
HDLV2_Staged_Form::run_stage2_extraction_retry();
check( 'Y1 single row: local extraction on the first attempt', isset( $w2->why[30] ) && count( HDLV2_AI_Service::$why_calls ) === $calls + 1 );
check( 'Y2 single row: Make never re-fired', 0 === stage2_fires( $w2->rows[30] ) );
check( 'Y3 old-flow row: Make re-fire on attempt 1 as today', 1 === stage2_fires( $w2->rows[31] ) && ! isset( $w2->why[31] ) );

echo "\n" . ( $fail ? "SCENARIO: FAIL ($fail)\n" : "SCENARIO: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );
