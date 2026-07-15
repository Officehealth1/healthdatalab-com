<?php
/**
 * STBY whitelist mail-guard tests (mu-plugin hdl-stby-mail-guard.php).
 *
 * Contract under test (the BELT — catches every wp_mail whatever fires):
 *  - Guard is ACTIVE whenever the box is not provably LIVE
 *    (env=production AND host healthdatalab.net). A re-clone that lost
 *    WP_ENVIRONMENT_TYPE but kept the stby host stays guarded (fail-closed).
 *  - Whitelisted recipients (team/QA inboxes, incl. +aliases) deliver as-is.
 *  - Non-whitelisted recipients are DROPPED; if none remain the mail is
 *    redirected to the catcher inbox with a "[STBY-BLOCKED -> originals]"
 *    subject tag; partial drops keep the whitelisted ones and tag
 *    "[STBY-FILTERED -> dropped]".
 *  - Cc/Bcc headers are stripped whenever the guard is active (they could
 *    smuggle a real address past the To check). Other headers survive.
 *  - Every drop is LOGGED: error_log line + ring-buffer option
 *    hdl_stby_mail_guard_log (capped).
 *  - On LIVE the filter is a byte-identical pass-through.
 *
 * Run:  php scenario-mail-guard.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

define( 'ABSPATH', __DIR__ . '/' );

// ── WP stubs ──
$GLOBALS['env_type'] = 'staging';
$GLOBALS['home']     = 'https://stby.healthdatalab.net';
$GLOBALS['options']  = array();
$GLOBALS['wp_filters'] = array();

function wp_get_environment_type() { return $GLOBALS['env_type']; }
function home_url( $p = '' ) { return $GLOBALS['home'] . $p; }
function apply_filters( $tag, $value ) { return $value; }
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['wp_filters'][ $tag ][] = $cb; return true; }
function get_option( $k, $default = false ) { return $GLOBALS['options'][ $k ] ?? $default; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['options'][ $k ] = $v; return true; }

// ── Load the real mu-plugin ──
require __DIR__ . '/../../mu-plugins/hdl-stby-mail-guard.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) {
    global $pass, $fail;
    echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n";
    $ok ? $pass++ : $fail++;
}

$filter = $GLOBALS['wp_filters']['wp_mail'][0] ?? null;
check( 'guard registered a wp_mail filter', is_callable( $filter ) );
if ( ! is_callable( $filter ) ) { echo "\n$pass passed, " . ( $fail ) . " failed\n"; exit( 1 ); }

// ── pattern matching ──
check( 'exact match, case-insensitive', hdl_stby_mail_guard_match( 'Team@IrisLab.com', 'team@irislab.com' ) );
check( 'wildcard +alias matches', hdl_stby_mail_guard_match( 'team+stby@irislab.com', 'team+*@irislab.com' ) );
check( 'wildcard does NOT match bare address', ! hdl_stby_mail_guard_match( 'team@irislab.com', 'team+*@irislab.com' ) );
check( 'wildcard does NOT cross domains', ! hdl_stby_mail_guard_match( 'team+x@evil.com', 'team+*@irislab.com' ) );
check( 'wildcard is not a regex hole (dot literal)', ! hdl_stby_mail_guard_match( 'teamXstby@irislabXcom', 'team+*@irislab.com' ) );

// ── email extraction ──
check( 'display-name form extracts the address', 'amanda.m.bolt@gmail.com' === hdl_stby_mail_guard_extract_email( 'Amanda Bolt <Amanda.M.Bolt@Gmail.com>' ) );
check( 'bare address passes through lowercased', 'a@b.com' === hdl_stby_mail_guard_extract_email( ' A@B.com ' ) );
check( 'garbage extracts to empty (fail-closed)', '' === hdl_stby_mail_guard_extract_email( 'not-an-email' ) );

// ── decide ──
$wl = hdl_stby_mail_guard_whitelist();
check( 'default whitelist includes the catcher inbox family', in_array( 'team+*@irislab.com', $wl, true ) );
$d = hdl_stby_mail_guard_decide( array( 'team+qa@irislab.com', 'amanda.m.bolt@gmail.com' ), $wl );
check( 'decide keeps whitelisted, drops real', array( 'team+qa@irislab.com' ) === $d['keep'] && array( 'amanda.m.bolt@gmail.com' ) === $d['dropped'] );
$d = hdl_stby_mail_guard_decide( 'team+qa@irislab.com, lucasaugustineadamson@gmail.com', $wl );
check( 'decide handles comma-string To', array( 'team+qa@irislab.com' ) === $d['keep'] && array( 'lucasaugustineadamson@gmail.com' ) === $d['dropped'] );

// ── filter: real-only recipient → catcher + BLOCKED tag + log ──
$log = tempnam( sys_get_temp_dir(), 'hdlv2-guard-log' );
$prev = ini_set( 'error_log', $log );

$out = $filter( array( 'to' => 'amanda.m.bolt@gmail.com', 'subject' => 'Weekly check-in', 'message' => 'x', 'headers' => array() ) );
check( 'real-only To redirected to catcher', 'team+stby@irislab.com' === $out['to'] );
check( 'subject carries [STBY-BLOCKED -> original]', 0 === strpos( $out['subject'], '[STBY-BLOCKED -> amanda.m.bolt@gmail.com]' ) );
$logged = (string) @file_get_contents( $log );
check( 'block was error_logged with [HDL-STBY-MAIL-GUARD]', false !== strpos( $logged, '[HDL-STBY-MAIL-GUARD]' ) && false !== strpos( $logged, 'amanda.m.bolt@gmail.com' ) );
$buf = get_option( 'hdl_stby_mail_guard_log', array() );
check( 'block recorded in the ring-buffer option', is_array( $buf ) && 1 === count( $buf ) && false !== strpos( json_encode( $buf[0] ), 'amanda.m.bolt@gmail.com' ) );

// ── filter: mixed recipients → keep whitelisted only + FILTERED tag ──
$out = $filter( array( 'to' => array( 'team+qa@irislab.com', 'lucasaugustineadamson@gmail.com' ), 'subject' => 'Mixed', 'message' => 'x' ) );
check( 'mixed To keeps only whitelisted', array( 'team+qa@irislab.com' ) === $out['to'] );
check( 'subject carries [STBY-FILTERED -> dropped]', 0 === strpos( $out['subject'], '[STBY-FILTERED -> lucasaugustineadamson@gmail.com]' ) );

// ── filter: all-whitelisted → untouched To/subject, Cc/Bcc still stripped ──
$in  = array(
    'to'      => 'office+matthew@healthdatalab.com',
    'subject' => 'Attention digest',
    'message' => 'x',
    'headers' => array( 'Reply-To: team@irislab.com', 'Cc: amanda.m.bolt@gmail.com', 'Content-Type: text/html' ),
);
$out = $filter( $in );
check( 'whitelisted To delivers unchanged', 'office+matthew@healthdatalab.com' === $out['to'] && 'Attention digest' === $out['subject'] );
$joined = implode( "\n", (array) $out['headers'] );
check( 'Cc smuggling stripped even when To is whitelisted', false === stripos( $joined, 'cc:' ) );
check( 'Reply-To + Content-Type survive', false !== strpos( $joined, 'Reply-To: team@irislab.com' ) && false !== strpos( $joined, 'Content-Type: text/html' ) );

// ── filter: string headers form also handled ──
$out = $filter( array( 'to' => 'team@irislab.com', 'subject' => 's', 'message' => 'x', 'headers' => "Bcc: hazelgaydon20@gmail.com\r\nReply-To: team@irislab.com" ) );
check( 'string-form Bcc stripped', false === stripos( implode( "\n", (array) $out['headers'] ), 'bcc:' ) );

// ── filter: display-name To resolves against whitelist ──
$out = $filter( array( 'to' => 'Matthew <office+matthew@healthdatalab.com>', 'subject' => 's', 'message' => 'x' ) );
check( 'display-name whitelisted To kept', false !== strpos( is_array( $out['to'] ) ? implode( ',', $out['to'] ) : $out['to'], 'office+matthew@healthdatalab.com' ) );

// ── non-array args pass through ──
check( 'non-array args untouched', 'nonsense' === $filter( 'nonsense' ) );

// ── LIVE: byte-identical pass-through ──
$GLOBALS['env_type'] = 'production'; $GLOBALS['home'] = 'https://healthdatalab.net';
$in  = array( 'to' => 'amanda.m.bolt@gmail.com', 'subject' => 'Weekly check-in', 'message' => 'x', 'headers' => array( 'Cc: someone@x.com' ) );
$out = $filter( $in );
check( 'on LIVE the guard is a pass-through (incl. Cc)', $out === $in );

// ── RE-CLONE: production env + stby host → guard ACTIVE (fail-closed) ──
$GLOBALS['env_type'] = 'production'; $GLOBALS['home'] = 'https://stby.healthdatalab.net';
$out = $filter( array( 'to' => 'amanda.m.bolt@gmail.com', 'subject' => 're-clone', 'message' => 'x' ) );
check( 're-clone that lost WP_ENVIRONMENT_TYPE stays guarded', 'team+stby@irislab.com' === $out['to'] );

// ── ring buffer stays capped ──
$GLOBALS['env_type'] = 'staging';
for ( $i = 0; $i < 250; $i++ ) {
    $filter( array( 'to' => "real$i@gmail.com", 'subject' => 'cap', 'message' => 'x' ) );
}
$buf = get_option( 'hdl_stby_mail_guard_log', array() );
check( 'ring buffer capped at 200', is_array( $buf ) && count( $buf ) <= 200 );

ini_set( 'error_log', $prev );
@unlink( $log );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
