<?php
/**
 * Opus 5.5 everywhere (v0.48.6) — every Claude request the plugin builds.
 *
 * Claude Opus 5.5 always thinks: `thinking: disabled`, `temperature`,
 * `top_p`, `top_k`, a forced `tool_choice` and an assistant prefill all
 * return HTTP 400, thinking tokens count against max_tokens, and the reply's
 * `content` can open with `thinking` blocks before the `text` block. A
 * declined request comes back as HTTP 200 with `stop_reason: refusal`.
 *
 * Checks, against the REAL classes where they can run standalone and against
 * the shipped source for the two builders buried in cron loops:
 *   R  the shared request shape (HDLV2_AI_Service::request_body)
 *   T  the shared reply reader (HDLV2_AI_Service::reply_text)
 *   B  each of the five builders: model, effort, room for thinking, timeout,
 *      nothing Opus 5.5 refuses, never content[0]
 *   M  no model id outside HDLV2_AI_Service::MODEL (comments aside)
 *   P  prompt freeze: every long string literal (the prompt text) in the
 *      prompt-building files is byte-identical to 0af0ff5 (0.48.4)
 *
 * Run:  php tests/opus55/test-opus55.php   (exit 0 all pass · 1 any fail)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

define( 'ABSPATH', __DIR__ . '/' );
define( 'HDLV2_ANTHROPIC_API_KEY', 'test-key' );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['posts'] = array();   // every wp_remote_post: [ url, args ]
$GLOBALS['reply'] = null;      // next Anthropic reply body (array)
$GLOBALS['logs']  = array();

function wp_remote_post( $url, $args = array() ) {
    $GLOBALS['posts'][] = array( $url, $args );
    return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $GLOBALS['reply'] ) );
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_json_encode( $x ) { return json_encode( $x ); }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_kses_post( $x ) { return (string) $x; }
function sanitize_text_field( $x ) { return trim( strip_tags( (string) $x ) ); }
function sanitize_textarea_field( $x ) { return trim( strip_tags( (string) $x ) ); }
function wp_strip_all_tags( $x ) { return trim( strip_tags( (string) $x ) ); }
function esc_html( $x ) { return (string) $x; }
function current_time( $f ) { return gmdate( 'Y-m-d H:i:s' ); }
function add_action() {}
function apply_filters( $t, $v ) { return $v; }
function get_option( $k, $d = false ) { return $d; }
class WP_Error {
    public $code; public $message;
    public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
}

$root = dirname( __DIR__, 2 );
require $root . '/includes/sprint-2/class-hdlv2-ai-service.php';
require $root . '/includes/sprint-3/class-hdlv2-audio-service.php';
require $root . '/includes/sprint-2c/class-hdlv2-auto-consultation.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) {
    global $pass, $fail;
    echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n";
    $ok ? $pass++ : $fail++;
}
// Source without comments (PHP's own tokenizer; a URL's // is not a comment).
function no_comments( $code ) {
    $out = '';
    foreach ( token_get_all( $code ) as $t ) {
        if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) continue;
        $out .= is_array( $t ) ? $t[1] : $t;
    }
    return $out;
}
function last_body() {
    $p = end( $GLOBALS['posts'] );
    return $p ? json_decode( $p[1]['body'], true ) : array();
}
function last_timeout() {
    $p = end( $GLOBALS['posts'] );
    return $p ? (int) ( $p[1]['timeout'] ?? 0 ) : 0;
}
const REFUSED_KEYS = array( 'thinking', 'temperature', 'top_p', 'top_k', 'tool_choice' );
function clean_shape( $b ) {
    foreach ( REFUSED_KEYS as $k ) if ( array_key_exists( $k, $b ) ) return false;
    foreach ( (array) ( $b['messages'] ?? array() ) as $m ) if ( 'assistant' === ( $m['role'] ?? '' ) ) return false;
    return true;
}
$OK_REPLY = array(
    'content'     => array( array( 'type' => 'thinking', 'thinking' => '', 'signature' => 'x' ), array( 'type' => 'text', 'text' => '{"ok":true}' ) ),
    'stop_reason' => 'end_turn',
    'usage'       => array( 'input_tokens' => 10, 'output_tokens' => 20 ),
);

// ════════ R: the shared request shape ════════
check( 'R1 MODEL is claude-opus-5-5', 'claude-opus-5-5' === HDLV2_AI_Service::MODEL );
check( 'R2 MODEL_COMMENTARY is gone (one model, one constant)', ! defined( 'HDLV2_AI_Service::MODEL_COMMENTARY' ) );
$rb = method_exists( 'HDLV2_AI_Service', 'request_body' ) ? HDLV2_AI_Service::request_body( 'SYS', 'USER', 6000, 'low' ) : array();
check( 'R3 request_body(): model, max_tokens, system, messages, output_config.effort — nothing else',
    array( 'model', 'max_tokens', 'system', 'messages', 'output_config' ) === array_keys( $rb )
    && 'claude-opus-5-5' === $rb['model'] && 6000 === $rb['max_tokens'] && 'SYS' === $rb['system']
    && array( array( 'role' => 'user', 'content' => 'USER' ) ) === $rb['messages'] && array( 'effort' => 'low' ) === $rb['output_config'] );
check( 'R4 request_body() defaults to medium effort (Opus 5.5\'s own default, said out loud)',
    method_exists( 'HDLV2_AI_Service', 'request_body' ) && array( 'effort' => 'medium' ) === HDLV2_AI_Service::request_body( 'S', 'U', 4000 )['output_config'] );

// ════════ T: the shared reply reader ════════
$has_reader = method_exists( 'HDLV2_AI_Service', 'reply_text' );
check( 'T1 reply_text() exists', $has_reader );
if ( $has_reader ) {
    check( 'T2 a thinking block first, then text: the text is returned', '{"ok":true}' === HDLV2_AI_Service::reply_text( $OK_REPLY ) );
    $ref = HDLV2_AI_Service::reply_text( array( 'content' => array(), 'stop_reason' => 'refusal',
        'stop_details' => array( 'type' => 'refusal', 'category' => 'bio', 'explanation' => 'Declined for safety.' ) ) );
    check( 'T3 a refusal is an error naming the category and the explanation',
        is_wp_error( $ref ) && 'claude_refusal' === $ref->get_error_code()
        && false !== strpos( $ref->get_error_message(), 'bio' ) && false !== strpos( $ref->get_error_message(), 'Declined for safety.' ) );
    $ref2 = HDLV2_AI_Service::reply_text( array( 'content' => array( array( 'type' => 'text', 'text' => 'partial' ) ), 'stop_reason' => 'refusal', 'stop_details' => null ) );
    check( 'T4 a refusal with no details is still an error, never text', is_wp_error( $ref2 ) );
    check( 'T5 cut off at max_tokens: the text so far is returned (callers repair or retry JSON)',
        '{"a":' === HDLV2_AI_Service::reply_text( array( 'content' => array( array( 'type' => 'thinking', 'thinking' => '' ), array( 'type' => 'text', 'text' => '{"a":' ) ),
            'stop_reason' => 'max_tokens', 'usage' => array( 'output_tokens' => 8000 ) ) ) );
    check( 'T6 only thinking, no text: empty string (callers keep their own empty-reply error)',
        '' === HDLV2_AI_Service::reply_text( array( 'content' => array( array( 'type' => 'thinking', 'thinking' => '' ) ), 'stop_reason' => 'end_turn' ) ) );
}

// ════════ B: the five builders ════════
// B1 HDLV2_AI_Service::call_claude (12 callers), run for real.
$cc = new ReflectionMethod( 'HDLV2_AI_Service', 'call_claude' );
$cc->setAccessible( true );
$GLOBALS['reply'] = $OK_REPLY;
$GLOBALS['posts'] = array();
$out = $cc->invoke( null, 'k', 'S', 'U', 2500 );
$b   = last_body();
check( 'B1a call_claude: Opus 5.5, medium, a thinking block before the text is skipped',
    '{"ok":true}' === $out && 'claude-opus-5-5' === ( $b['model'] ?? '' ) && 'medium' === ( $b['output_config']['effort'] ?? '' ) && clean_shape( $b ) );
check( 'B1b call_claude: an answer under 4,000 tokens gets a 6,000 ceiling', 6000 === ( $b['max_tokens'] ?? 0 ) );
$cc->invoke( null, 'k', 'S', 'U', 4500 );
check( 'B1c call_claude: a 4,000-4,500 answer gets an 8,000 ceiling', 8000 === ( last_body()['max_tokens'] ?? 0 ) );
check( 'B1d call_claude in the background (the default): medium, 300 s', 300 === last_timeout() );
$cc->invoke( null, 'k', 'S', 'U', 800, true );
check( 'B1e call_claude in a web request: low effort, 95 s (under Cloudflare\'s 100 s on LIVE)',
    'low' === ( last_body()['output_config']['effort'] ?? '' ) && 95 === last_timeout() && 95 === HDLV2_AI_Service::WEB_TIMEOUT );
$GLOBALS['reply'] = array( 'content' => array(), 'stop_reason' => 'refusal', 'stop_details' => array( 'category' => 'cyber', 'explanation' => 'No.' ) );
$r = $cc->invoke( null, 'k', 'S', 'U', 800 );
check( 'B1f call_claude: a refusal comes back as a WP_Error', is_wp_error( $r ) && 'claude_refusal' === $r->get_error_code() );

$ai = file_get_contents( $root . '/includes/sprint-2/class-hdlv2-ai-service.php' );
// The callers reachable from a REST request: Stage-3 commentary, organise
// notes (and its retry), milestones (preview), pre-consultation summary.
preg_match_all( '/self::call_claude\(([^;]*)\);/', $ai, $calls );
$web = array_values( array_filter( $calls[1], function ( $a ) { return (bool) preg_match( '/,\s*true\s*$/', $a ); } ) );
check( 'B1g five web-request call sites ask for web mode; the other eight stay background (got ' . count( $web ) . ' of ' . count( $calls[1] ) . ')',
    5 === count( $web ) && 13 === count( $calls[1] )
    && 1 === preg_match( '/user_prompt, 800, true/', implode( '|', $web ) ) && 2 === preg_match_all( '/user, 4000, true/', implode( '|', $web ) )
    && 1 === preg_match( '/user_prompt, 1500, true/', implode( '|', $web ) ) && 1 === preg_match( '/facts, 3000, true/', implode( '|', $web ) ) );

// B2 HDLV2_Auto_Consultation::call_claude_json, run for real.
$acRef = new ReflectionClass( 'HDLV2_Auto_Consultation' );
$ac    = $acRef->newInstanceWithoutConstructor();
$cj    = new ReflectionMethod( 'HDLV2_Auto_Consultation', 'call_claude_json' );
$cj->setAccessible( true );
$GLOBALS['reply'] = $OK_REPLY;
$parsed = $cj->getNumberOfParameters() >= 3 ? $cj->invokeArgs( $ac, array_slice( array( 'k', 'S', 'U', 1500, 0.4, 'claude-opus-4-8' ), 0, $cj->getNumberOfParameters() ) ) : null;
$b = last_body();
check( 'B2a auto-consultation (a REST request): Opus 5.5, low effort, nothing Opus 5.5 refuses, reads the text block',
    array( 'ok' => true ) === $parsed && 'claude-opus-5-5' === ( $b['model'] ?? '' ) && 'low' === ( $b['output_config']['effort'] ?? '' ) && clean_shape( $b ) );
$acSrc = file_get_contents( $root . '/includes/sprint-2c/class-hdlv2-auto-consultation.php' );
check( 'B2b auto-consultation: both calls ask for a 6,000 ceiling; web timeout 95 s',
    2 === substr_count( $acSrc, '$this->call_claude_json( HDLV2_ANTHROPIC_API_KEY, $system, $user_prompt, 6000 )' ) && 95 === last_timeout() );
check( 'B2c auto-consultation: the unused temperature and model arguments are gone', false === strpos( $acSrc, '0.4, ' ) && false === strpos( $acSrc, 'claude-opus' ) );

// B3 HDLV2_Audio_Service::extract_summary, run for real for each context.
$audio = HDLV2_Audio_Service::get_instance();
$want  = array( 'why_collection' => 8000, 'consultation_notes' => 8000, 'weekly_checkin' => 6000 );
foreach ( $want as $ctx => $mt ) {
    $GLOBALS['reply'] = $OK_REPLY;
    $res = $audio->extract_summary( 'I walk the dog every day.', $ctx );
    $b   = last_body();
    check( "B3 audio $ctx (a REST request): Opus 5.5, low, $mt ceiling, 95 s, text block read",
        '{"ok":true}' === $res && 'claude-opus-5-5' === ( $b['model'] ?? '' ) && 'low' === ( $b['output_config']['effort'] ?? '' )
        && $mt === ( $b['max_tokens'] ?? 0 ) && 95 === last_timeout() && clean_shape( $b ) );
}
check( 'B3d audio: the MODELS map is gone (one model)', ! defined( 'HDLV2_Audio_Service::MODELS' ) );

// B4 + B5: built inside cron loops with database reads; checked in the source.
$cb = file_get_contents( $root . '/includes/class-hdlv2-context-builder.php' );
check( 'B4 monthly summary: request_body(…, 4000, \'low\'), reply_text(), 300 s',
    1 === preg_match( "/HDLV2_AI_Service::request_body\([^;]*,\s*4000,\s*'low'\s*\)/s", $cb )
    && false !== strpos( $cb, 'HDLV2_AI_Service::reply_text(' ) && 1 === preg_match( "/'timeout'\s*=>\s*300/", $cb ) );
$fp = file_get_contents( $root . '/includes/sprint-5/class-hdlv2-flight-plan.php' );
check( 'B5 flight plan: request_body(…, 20000, \'medium\'), reply_text(), 300 s',
    1 === preg_match( "/HDLV2_AI_Service::request_body\([^;]*,\s*20000,\s*'medium'\s*\)/s", $fp )
    && false !== strpos( $fp, 'HDLV2_AI_Service::reply_text(' ) && 1 === preg_match( "/'timeout'\s*=>\s*300/", $fp ) );

// Every builder: never content[0], never a hand-written body.
$builders = array(
    'ai-service'        => $ai,
    'auto-consultation' => $acSrc,
    'audio-service'     => file_get_contents( $root . '/includes/sprint-3/class-hdlv2-audio-service.php' ),
    'context-builder'   => $cb,
    'flight-plan'       => $fp,
);
foreach ( $builders as $name => $code ) {
    $nc = no_comments( $code );
    check( "B6 $name: no ['content'][0] read", false === strpos( $nc, "['content'][0]" ) );
    check( "B7 $name: no 'thinking', 'temperature', 'top_p', 'top_k' or 'tool_choice' key sent",
        0 === preg_match( "/'(thinking|temperature|top_p|top_k|tool_choice)'\s*=>/", $nc ) );
}

// ════════ M: one model id ════════
$hits = array();
$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
    if ( substr( $f, -4 ) !== '.php' ) continue;
    $code = no_comments( file_get_contents( $f ) );
    if ( preg_match_all( "/['\"]claude-[a-z0-9-]+['\"]/", $code, $m ) ) {
        foreach ( $m[0] as $lit ) $hits[] = str_replace( $root . '/', '', (string) $f ) . ' ' . $lit;
    }
}
check( 'M1 the only claude- model literal outside comments is MODEL (got: ' . implode( ', ', $hits ) . ')',
    array( 'includes/sprint-2/class-hdlv2-ai-service.php \'claude-opus-5-5\'' ) === $hits );
$fr = file_get_contents( $root . '/includes/sprint-2c/class-hdlv2-final-report.php' );
check( 'M2 the final report records the model it ran on (MODEL)', false === strpos( no_comments( $fr ), 'claude-opus-4-8' ) );

// ════════ P: prompt freeze ════════
// Long string literals = the prompt text. Hash of every one >= 60 chars in
// these files at 0af0ff5 (0.48.4). A model change must not carry a prompt change.
function prompt_literals_hash( $files_code ) {
    $all = array();
    foreach ( $files_code as $name => $code ) {
        foreach ( token_get_all( $code ) as $t ) {
            if ( ! is_array( $t ) || ! in_array( $t[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) ) continue;
            if ( strlen( $t[1] ) >= 60 ) $all[] = $name . "\0" . $t[1];
        }
    }
    sort( $all );
    return hash( 'sha256', implode( "\n", $all ) );
}
$PROMPT_FILES = array(
    'includes/sprint-2/class-hdlv2-ai-service.php',
    'includes/sprint-2c/class-hdlv2-auto-consultation.php',
    'includes/sprint-3/class-hdlv2-audio-service.php',
    'includes/class-hdlv2-context-builder.php',
    'includes/sprint-5/class-hdlv2-flight-plan.php',
    'includes/sprint-2/class-hdlv2-why-picks.php',
);
// Literals this change itself adds (log and error lines, not prompt text).
$OWN = array(
    "'Claude declined this request (category: %s). %s'",
);
$now = array();
foreach ( $PROMPT_FILES as $f ) $now[ $f ] = file_get_contents( $root . '/' . $f );
$filtered = array();
foreach ( $now as $f => $code ) $filtered[ $f ] = str_replace( $OWN, "''", $code );
$SNAPSHOT_0AF0FF5 = '918de85806c9453236738f4c0c865de4470ff416ab191597e8a64bd45ed2633b';
if ( in_array( '--snapshot', $argv ?? array(), true ) ) { echo prompt_literals_hash( $filtered ) . "\n"; exit( 0 ); }
check( 'P1 prompt text in the six prompt-building files is byte-identical to 0af0ff5', $SNAPSHOT_0AF0FF5 === prompt_literals_hash( $filtered ) );

echo "\n" . ( $fail ? "OPUS55: FAIL ($fail)\n" : "OPUS55: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );
