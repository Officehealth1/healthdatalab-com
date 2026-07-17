<?php
/**
 * Stage-2 webhook fire-guard tests — v0.47.76.
 *
 * The incident (2026-07-17, STBY row 237): the Make Stage-2 branch hard-errors
 * at module 88 when Claude legitimately returns an empty distilled_why. The
 * WP-side contribution to that failure class: rest_save_form() fired the Make
 * webhook on `! empty( vision_text )` — a 1-character or whitespace-only
 * vision_text fired a doomed Make execution, while every OTHER fire path
 * (retry cron, out-of-band local extract, the JS submit gate) already
 * requires >= 10 trimmed chars. Worse, a submit that raced the Deepgram
 * transcript (empty textarea at submit time) skipped the ENTIRE block — no
 * fire, no stage2_completed_at claim — and because the retry cron's candidate
 * SELECT required stage2_webhook_fired_at IS NOT NULL, the row could never
 * be recovered by any automatic path.
 *
 * The fix under test:
 *   1. Fire gate: submit fires Make ONLY when trim(vision_text) >= 10 chars
 *      (STAGE2_MIN_VISION_LEN — same bar as every other path).
 *   2. Defer semantics: a submit with a not-ready transcript still claims
 *      stage2_completed_at (the client DID submit; F7 return-visit routing
 *      stands) but defers the fire.
 *   3. Deferred fire: the later auto-save that delivers the transcript
 *      (submitted flag absent) fires the webhook exactly once, atomically
 *      claiming stage2_webhook_fired_at first so racing auto-saves cannot
 *      double-fire.
 *   4. Retry-cron belt: candidate SELECT also picks never-fired rows whose
 *      stage2_completed_at is set (> 30 min) with no why_profiles row, so a
 *      closed-browser client is recovered by the daily ladder.
 *
 * Run:  php scenario-fire-guard.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HDLV2_MAKE_STAGE2_WHY', 'https://hook.example.test/stage2-why' );
define( 'HDLV2_MAKE_CALLBACK_SECRET', 'test-callback-secret' );
// Retry-cron legs ask HDLV2_Env::gate(); this suite tests fire/selection
// logic, so run with side-effects allowed (gate covered by tests/email-safety/).
define( 'HDLV2_STAGING_SIDE_EFFECTS', true );

// ── WP stubs ──
$GLOBALS['transients'] = array();
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function apply_filters( $tag, $value ) { return $value; }
function home_url( $path = '' ) { return 'https://stby.example.test' . $path; }
function site_url( $path = '' ) { return 'https://stby.example.test' . $path; }
function rest_url( $path = '' ) { return 'https://stby.example.test/wp-json/' . ltrim( $path, '/' ); }
function get_userdata( $id ) { return false; }
function current_time( $fmt ) { return 'mysql' === $fmt ? gmdate( 'Y-m-d H:i:s' ) : gmdate( 'c' ); }
function wp_json_encode( $x ) { return json_encode( $x ); }
function sanitize_text_field( $x ) { return trim( (string) $x ); }
function sanitize_textarea_field( $x ) { return (string) $x; }
function wp_kses_post( $x ) { return (string) $x; }
function rest_ensure_response( $x ) { return $x; }
function add_action() {}
function add_shortcode() {}
function wp_mail( ...$a ) { $GLOBALS['mails'][] = $a; return true; }
function wp_next_scheduled( $hook, $args = array() ) { return false; }
function wp_schedule_single_event( $ts, $hook, $args = array() ) { $GLOBALS['scheduled'][] = array( $hook, $args ); return true; }
function wp_remote_post( $url, $args = array() ) { return array( 'response' => array( 'code' => 200 ) ); }
function esc_html( $x ) { return (string) $x; }
function esc_attr( $x ) { return (string) $x; }
function esc_url( $x ) { return (string) $x; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }

class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code = '', $message = '', $data = null ) {
        $this->code = $code; $this->message = $message; $this->data = $data;
    }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }

// Template stub — every template method returns an empty HTML string.
class HDLV2_Email_Templates {
    public static function __callStatic( $name, $args ) { return '<html></html>'; }
}
class HDLV2_Practitioner {
    public static function get_logo_url( $id, $fallback = false ) { return 'https://stby.example.test/logo.png'; }
}
// Spy AI service (retry-cron attempt-3 local fallback).
class HDLV2_AI_Service {
    public static $calls = array();
    public static function extract_why( $stage2_data ) {
        self::$calls[] = $stage2_data;
        return array(
            'key_people'       => array( 'QA person' ),
            'motivations'      => array( 'QA motive' ),
            'fears'            => array(),
            'distilled_why'    => 'QA distilled why',
            'ai_reformulation' => 'QA reformulation',
        );
    }
}
// Spy — records every Make fire instead of doing HTTP.
class HDLV2_Webhook_Monitor {
    public static $fires = array();
    public static function fire( $url, $args, $tag = '' ) {
        self::$fires[] = array( 'url' => $url, 'tag' => $tag, 'body' => $args['body'] ?? '' );
        return true;
    }
}

// ── Fake wpdb — behavioural: applies only the predicates present in the SQL ──
class FakeWpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $rows = array();            // form_progress: id => row object
    public $why_inserts = array();
    public $captured_candidate_sql = null;
    public $updates = array();

    public function prepare( $sql, ...$args ) {
        // Consume placeholders IN ORDER — a %s-before-%d statement (e.g. the
        // atomic completion claim) must not have its %d eaten by the string arg.
        foreach ( $args as $a ) {
            $sql = preg_replace_callback( '/%[ds]/', function ( $m ) use ( $a ) {
                return '%d' === $m[0] ? (string) (int) $a : "'" . $a . "'";
            }, $sql, 1 );
        }
        return $sql;
    }

    public function get_row( $sql ) {
        if ( preg_match( "/WHERE token = '([a-f0-9]{64})'/", $sql, $m ) ) {
            foreach ( $this->rows as $r ) {
                if ( $r->token !== $m[1] ) continue;
                if ( false !== stripos( $sql, 'deleted_at IS NULL' ) && null !== $r->deleted_at ) return null;
                if ( false !== stripos( $sql, 'token_expires_at > UTC_TIMESTAMP()' )
                     && ( null === $r->token_expires_at || strtotime( $r->token_expires_at . ' UTC' ) <= time() ) ) return null;
                return $r;
            }
            return null;
        }
        if ( preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
            return $this->rows[ (int) $m[1] ] ?? null;
        }
        return null;
    }

    /** Retry-cron candidate SELECT — behavioural predicates only. */
    public function get_results( $sql ) {
        $this->captured_candidate_sql = $sql;
        $has_never_fired_branch = ( false !== stripos( $sql, 'stage2_webhook_fired_at IS NULL' ) );
        $out = array();
        foreach ( $this->rows as $r ) {
            // All fixture timestamps predate the 30-min threshold, so the
            // "< %s" comparisons reduce to NULL-ness checks here.
            $fired_branch = ( null !== $r->stage2_webhook_fired_at );
            $never_branch = $has_never_fired_branch
                && null === $r->stage2_webhook_fired_at
                && null !== $r->stage2_completed_at;
            if ( ! $fired_branch && ! $never_branch ) continue;
            if ( $this->why_exists( $r->id ) ) continue;
            if ( preg_match( '/fp\.deleted_at\s+IS\s+NULL/i', $sql ) && null !== $r->deleted_at ) continue;
            $o = array(
                'id'             => $r->id,
                'token'          => $r->token,
                'client_user_id' => $r->client_user_id,
                'stage2_data'    => $r->stage2_data,
                'client_name'    => $r->client_name,
            );
            if ( false !== strpos( substr( $sql, 0, stripos( $sql, ' FROM' ) ), 'fp.token_expires_at' ) ) {
                $o['token_expires_at'] = $r->token_expires_at;
            }
            $out[] = (object) $o;
        }
        usort( $out, function ( $a, $b ) { return $b->id <=> $a->id; } );
        return $out;
    }

    public function get_var( $sql ) {
        if ( false !== strpos( $sql, 'GET_LOCK' ) ) return 1;
        if ( preg_match( '/FROM wp_hdlv2_why_profiles WHERE form_progress_id = (\d+)/', $sql, $m ) ) {
            return $this->why_exists( (int) $m[1] ) ? 1 : null;
        }
        return null;
    }

    /** Atomic claims (completion + deferred-fire) land here as raw UPDATEs. */
    public function query( $sql ) {
        // Completion claim: SET stage2_completed_at = 'ts' WHERE id = N AND (stage2_completed_at IS NULL ...)
        if ( preg_match( "/SET stage2_completed_at = '([^']+)'\s+WHERE id = (\d+)/s", $sql, $m ) ) {
            $row = $this->rows[ (int) $m[2] ] ?? null;
            if ( ! $row ) return 0;
            if ( null !== $row->stage2_completed_at && '' !== $row->stage2_completed_at ) return 0;
            $row->stage2_completed_at = $m[1];
            return 1;
        }
        // Deferred-fire claim: SET stage2_webhook_fired_at = 'ts', stage2_text_hash = 'h' WHERE id = N AND (stage2_webhook_fired_at IS NULL ...)
        if ( preg_match( "/SET stage2_webhook_fired_at = '([^']+)',\s*stage2_text_hash = '([^']+)'\s+WHERE id = (\d+)\s+AND\s+\(\s*stage2_webhook_fired_at IS NULL/si", $sql, $m ) ) {
            $row = $this->rows[ (int) $m[3] ] ?? null;
            if ( ! $row ) return 0;
            if ( null !== $row->stage2_webhook_fired_at && '' !== $row->stage2_webhook_fired_at ) return 0;
            $row->stage2_webhook_fired_at = $m[1];
            $row->stage2_text_hash        = $m[2];
            return 1;
        }
        return 1; // RELEASE_LOCK etc.
    }

    public function insert( $table, $data, $formats = null ) {
        if ( false !== strpos( $table, 'why_profiles' ) ) {
            $this->why_inserts[] = $data;
            return 1;
        }
        return 1;
    }

    public function update( $table, $data, $where, $fmt1 = null, $fmt2 = null ) {
        $this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
        // Reflect into the row so subsequent reads see the write.
        if ( isset( $where['id'] ) && isset( $this->rows[ (int) $where['id'] ] ) ) {
            foreach ( $data as $k => $v ) {
                $this->rows[ (int) $where['id'] ]->$k = $v;
            }
        }
        return 1;
    }

    private function why_exists( $fp_id ) {
        foreach ( $this->why_inserts as $ins ) {
            if ( (int) $ins['form_progress_id'] === (int) $fp_id ) return true;
        }
        return false;
    }
}

function make_row( $id, $args = array() ) {
    return (object) array_merge( array(
        'id'                      => $id,
        'token'                   => str_repeat( dechex( $id % 16 ), 64 ),
        'client_user_id'          => 900 + $id,
        'practitioner_user_id'    => 0,
        'client_name'             => 'QA Row ' . $id,
        'client_email'            => 'qa' . $id . '@example.test',
        'current_stage'           => 2,
        'stage1_data'             => '{}',
        'stage2_data'             => '{}',
        'stage3_data'             => '{}',
        'stage1_completed_at'     => '2020-01-01 00:00:00',
        'stage2_completed_at'     => null,
        'stage3_completed_at'     => null,
        'stage2_webhook_fired_at' => null,
        'stage2_text_hash'        => '',
        'deleted_at'              => null,
        'token_expires_at'        => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ),
    ), $args );
}

function save_request( $token, $data, $submitted ) {
    $body = array( 'token' => $token, 'stage' => 2, 'data' => $data );
    if ( $submitted ) $body['submitted'] = true;
    return new class( $body ) {
        private $b;
        public function __construct( $b ) { $this->b = $b; }
        public function get_json_params() { return $this->b; }
    };
}

function fires_for_token( $token ) {
    return array_values( array_filter( HDLV2_Webhook_Monitor::$fires, function ( $f ) use ( $token ) {
        $b = json_decode( $f['body'], true ) ?: array();
        return ( $b['token'] ?? '' ) === $token;
    } ) );
}

// ── Load the real classes ──
require __DIR__ . '/../../includes/class-hdlv2-env.php';
require __DIR__ . '/../../includes/sprint-2/class-hdlv2-staged-form.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) {
    global $pass, $fail;
    echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n";
    $ok ? $pass++ : $fail++;
}

$LONG_TEXT = 'I want to stay strong for my grandchildren and keep hiking every summer with my wife.';

// ════════ Part A — rest_save_form fire gate + defer ════════
$wpdb = new FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;
$form = new HDLV2_Staged_Form();

// A. submit with THIN text (2 chars): no fire, completion claimed
$wpdb->rows[301] = make_row( 301 );
$res = $form->rest_save_form( save_request( $wpdb->rows[301]->token, array( 'vision_text' => 'hi' ), true ) );
check( 'A1 thin submit: response success', ! is_wp_error( $res ) && ! empty( $res['success'] ) );
check( 'A2 thin submit: NO Make fire', 0 === count( fires_for_token( $wpdb->rows[301]->token ) ) );
check( 'A3 thin submit: stage2_completed_at claimed anyway', ! empty( $wpdb->rows[301]->stage2_completed_at ) );
check( 'A4 thin submit: stage2_webhook_fired_at still NULL', empty( $wpdb->rows[301]->stage2_webhook_fired_at ) );

// B. submit with WHITESPACE-only text: no fire, completion claimed
$wpdb->rows[302] = make_row( 302 );
$form->rest_save_form( save_request( $wpdb->rows[302]->token, array( 'vision_text' => "   \n\n   " ), true ) );
check( 'B1 whitespace submit: NO Make fire', 0 === count( fires_for_token( $wpdb->rows[302]->token ) ) );
check( 'B2 whitespace submit: completion claimed', ! empty( $wpdb->rows[302]->stage2_completed_at ) );

// C. submit with EMPTY text: no fire, completion claimed (defer semantics)
$wpdb->rows[303] = make_row( 303 );
$form->rest_save_form( save_request( $wpdb->rows[303]->token, array( 'vision_text' => '' ), true ) );
check( 'C1 empty submit: NO Make fire', 0 === count( fires_for_token( $wpdb->rows[303]->token ) ) );
check( 'C2 empty submit: completion claimed (client DID submit)', ! empty( $wpdb->rows[303]->stage2_completed_at ) );

// D. submit with USABLE text: fires exactly once, stamps fired_at + hash
$wpdb->rows[304] = make_row( 304 );
$form->rest_save_form( save_request( $wpdb->rows[304]->token, array( 'vision_text' => $LONG_TEXT ), true ) );
$f304 = fires_for_token( $wpdb->rows[304]->token );
check( 'D1 valid submit: fires exactly once', 1 === count( $f304 ) );
check( 'D2 valid submit: fired_at stamped', ! empty( $wpdb->rows[304]->stage2_webhook_fired_at ) );
check( 'D3 valid submit: text hash stamped', md5( $LONG_TEXT ) === $wpdb->rows[304]->stage2_text_hash );
check( 'D4 valid submit: payload vision_text is the saved transcript',
    ( json_decode( $f304[0]['body'], true )['vision_text'] ?? '' ) === $LONG_TEXT );
check( 'D5 valid submit: completion claimed', ! empty( $wpdb->rows[304]->stage2_completed_at ) );

// E. re-submit IDENTICAL text: no second fire (hash dedup regression)
$form->rest_save_form( save_request( $wpdb->rows[304]->token, array( 'vision_text' => $LONG_TEXT ), true ) );
check( 'E1 identical re-submit: still exactly one fire', 1 === count( fires_for_token( $wpdb->rows[304]->token ) ) );

// F. DEFERRED FIRE — the late transcript auto-save (no submitted flag) on a
//    completed + never-fired row fires the webhook.
$row303 = $wpdb->rows[303]; // completed via C, never fired
$form->rest_save_form( save_request( $row303->token, array( 'vision_text' => $LONG_TEXT ), false ) );
$f303 = fires_for_token( $row303->token );
check( 'F1 deferred fire: late transcript auto-save FIRES the webhook', 1 === count( $f303 ) );
check( 'F2 deferred fire: fired_at stamped', ! empty( $row303->stage2_webhook_fired_at ) );
check( 'F3 deferred fire: hash stamped', md5( $LONG_TEXT ) === $row303->stage2_text_hash );
check( 'F4 deferred fire: payload carries the full transcript',
    ( json_decode( $f303[0]['body'], true )['vision_text'] ?? '' ) === $LONG_TEXT );

// G. auto-save on a NOT-completed row: never fires (plain auto-save regression)
$wpdb->rows[305] = make_row( 305 );
$form->rest_save_form( save_request( $wpdb->rows[305]->token, array( 'vision_text' => $LONG_TEXT ), false ) );
check( 'G1 auto-save before submit: NO fire', 0 === count( fires_for_token( $wpdb->rows[305]->token ) ) );
check( 'G2 auto-save before submit: no completion claim', empty( $wpdb->rows[305]->stage2_completed_at ) );

// H. auto-save on completed + ALREADY-fired row: no re-fire
$form->rest_save_form( save_request( $row303->token, array( 'vision_text' => $LONG_TEXT . ' more' ), false ) );
check( 'H1 auto-save after fire: no second fire', 1 === count( fires_for_token( $row303->token ) ) );

// I. deferred fire with STILL-thin text: stays deferred
$wpdb->rows[306] = make_row( 306, array( 'stage2_completed_at' => '2020-01-01 00:10:00' ) );
$form->rest_save_form( save_request( $wpdb->rows[306]->token, array( 'vision_text' => 'short' ), false ) );
check( 'I1 thin auto-save on completed row: NO fire', 0 === count( fires_for_token( $wpdb->rows[306]->token ) ) );

// ════════ Part B — retry-cron belt (never-fired + completed rows) ════════
$wpdb2 = new FakeWpdb();
$GLOBALS['wpdb'] = $wpdb2;
HDLV2_Webhook_Monitor::$fires = array();
HDLV2_AI_Service::$calls = array();

$wpdb2->rows = array(
    // Row-237 class: fired, no why row, usable text → Make re-fire with SAVED transcript
    401 => make_row( 401, array(
        'stage2_data'             => json_encode( array( 'vision_text' => $LONG_TEXT ) ),
        'stage2_webhook_fired_at' => '2020-01-01 00:00:00',
        'stage2_completed_at'     => '2020-01-01 00:00:00',
    ) ),
    // NEW belt: completed long ago, NEVER fired (race row whose auto-save never came)
    402 => make_row( 402, array(
        'stage2_data'         => json_encode( array( 'vision_text' => $LONG_TEXT . ' 402' ) ),
        'stage2_completed_at' => '2020-01-01 00:00:00',
    ) ),
    // Never fired, completed, but THIN text → selected by SQL, skipped by loop
    403 => make_row( 403, array(
        'stage2_data'         => json_encode( array( 'vision_text' => 'thin' ) ),
        'stage2_completed_at' => '2020-01-01 00:00:00',
    ) ),
    // Never fired, NOT completed (client mid-form) → never selected
    404 => make_row( 404, array(
        'stage2_data' => json_encode( array( 'vision_text' => $LONG_TEXT . ' 404' ) ),
    ) ),
    // Soft-deleted, completed, never fired → never selected
    405 => make_row( 405, array(
        'stage2_data'         => json_encode( array( 'vision_text' => $LONG_TEXT . ' 405' ) ),
        'stage2_completed_at' => '2020-01-01 00:00:00',
        'deleted_at'          => '2026-07-01 00:00:00',
    ) ),
);

HDLV2_Staged_Form::run_stage2_extraction_retry();

$sql = (string) $wpdb2->captured_candidate_sql;
check( 'J1 candidate SQL keeps fp.deleted_at IS NULL', (bool) preg_match( '/fp\.deleted_at\s+IS\s+NULL/i', $sql ) );
check( 'J2 candidate SQL gains the never-fired-but-completed branch',
    false !== stripos( $sql, 'stage2_webhook_fired_at IS NULL' )
    && false !== stripos( $sql, 'stage2_completed_at IS NOT NULL' ) );
check( 'J3 candidate SQL still has NO expiry predicate in WHERE', ! preg_match( '/token_expires_at\s*>\s*UTC_TIMESTAMP/i', $sql ) );

$fired_tokens = array_map( function ( $f ) { return json_decode( $f['body'], true )['token'] ?? ''; }, HDLV2_Webhook_Monitor::$fires );
sort( $fired_tokens );
$expected = array( $wpdb2->rows[401]->token, $wpdb2->rows[402]->token );
sort( $expected );
check( 'K1 cron re-fires exactly rows 401 (stuck) and 402 (never-fired belt)', $fired_tokens === $expected );
$f401 = fires_for_token( $wpdb2->rows[401]->token );
check( 'K2 row-237-class recovery: re-fire carries the SAVED transcript',
    ( json_decode( $f401[0]['body'], true )['vision_text'] ?? '' ) === $LONG_TEXT );
check( 'K3 never-fired row 402 got fired_at stamped', in_array( 402, array_map( function ( $u ) { return $u['where']['id'] ?? 0; }, $wpdb2->updates ), true ) );
check( 'K4 thin row 403: selected class but NO fire, NO counter',
    ! in_array( $wpdb2->rows[403]->token, $fired_tokens, true )
    && ! isset( $GLOBALS['transients']['hdlv2_stage2_retry_403'] ) );
check( 'K5 mid-form row 404: untouched', ! in_array( $wpdb2->rows[404]->token, $fired_tokens, true ) );
check( 'K6 soft-deleted row 405: untouched', ! in_array( $wpdb2->rows[405]->token, $fired_tokens, true ) );
check( 'K7 no local extraction burned this pass (attempts 1 = Make)', 0 === count( HDLV2_AI_Service::$calls ) );

echo "\n" . ( $fail ? "SCENARIO: FAIL ($fail)\n" : "SCENARIO: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );
