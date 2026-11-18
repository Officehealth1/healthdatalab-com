<?php
/**
 * v0.48.87 (plan 2k, planner's G4, 2026-11-18) — the browser token. A browser pass gives a key to ONE
 * form, never an identity: open_browser() answers /assessment/?token=<browser token>, a one-day key held
 * in a transient (hdlv2_bt_<SHA-256> → form row id) and named by the account's mark. Nothing signs the
 * browser in. The form's own lookups (staged form, the two audio lookups, the job status) accept the key;
 * nothing else does. Any sign-in of the account (set_auth_cookie), a live session on it, the owner's own
 * Stage 1 reusing the row, or the day ending, ends the key; the page and a refused save then say so.
 * Against the REAL HDL_Stage1_Ticket and HDLV2_Staged_Form; the loader and the other lookups are read.
 *
 * Run:  php scenario-browser-token.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/../full-report/stubs.php';
function wp_salt( $scheme = 'auth' ) { return 'salt-one-for-tests'; }
function hdlv2_is_staff_account( $user_id ) { return false; }
function wp_cache_delete( $key, $group = '' ) { $GLOBALS['order'][] = 'cache:' . $group . ':' . $key; return true; }
class WP_Session_Tokens {
    public static $live = array();
    private $uid;
    public static function get_instance( $uid ) { $o = new self(); $o->uid = (int) $uid; return $o; }
    public function get_all() { return self::$live[ $this->uid ] ?? array(); }
}
/** User meta whose reads are logged, so the order "cache dropped, then the mark read" can be checked. */
class LoggedMeta extends ArrayObject {
    public function offsetGet( mixed $k ): mixed { $GLOBALS['order'][] = 'read:' . $k; return parent::offsetGet( $k ); }
}
load_sources();
$ROOT = __DIR__ . '/../../';
require_once $ROOT . 'includes/sprint-2/class-hdlv2-why-picks.php';
require_once $ROOT . 'includes/sprint-2/class-hdlv2-staged-form.php';
$LOG = tempnam( sys_get_temp_dir(), 'hdlv2-bt-log' );
ini_set( 'log_errors', '1' );
ini_set( 'error_log', $LOG );

const UID  = 777;
const FID  = 9101;
const FTOK = 'dededededededededededededededededededededededededededededededede';

class FormWpdb extends FakeWpdb {
    public $forms = array();
    public function get_row( $sql ) {
        if ( false === strpos( $sql, 'hdlv2_form_progress' ) ) return parent::get_row( $sql );
        $this->queries[] = $sql;
        foreach ( $this->forms as $f ) {
            $hit = ( preg_match( "/WHERE token = '([a-f0-9]{64})'/", $sql, $m ) && $m[1] === $f->token )
                || ( preg_match( '/WHERE id = (\d+)/', $sql, $m ) && (int) $m[1] === (int) $f->id );
            $live = false !== strpos( $sql, 'deleted_at IS NULL' ) && empty( $f->deleted_at )
                && false !== strpos( $sql, 'token_expires_at > UTC_TIMESTAMP()' ) && empty( $f->ran_out );
            if ( $hit && $live ) return clone $f;
        }
        return null;
    }
    public function query( $sql ) { $this->queries[] = $sql; return 1; }
}
function setup( $marked = true ) {
    global $wpdb;
    $GLOBALS['transients']  = array();
    $GLOBALS['options']     = array();
    $GLOBALS['meta_writes'] = array();
    $GLOBALS['order']       = array();
    $GLOBALS['usermeta']    = array();
    WP_Session_Tokens::$live = array();
    $wpdb = new FormWpdb();
    $wpdb->forms = array( (object) array( 'id' => FID, 'client_user_id' => UID, 'practitioner_user_id' => 206, 'token' => FTOK, 'current_stage' => 3,
        'client_name' => 'Buyer', 'client_email' => 'buyer+2k@example.test', 'stage1_data' => '{"q1_age":44}', 'stage1_completed_at' => '2026-11-18 01:00:00',
        'stage2_data' => '{}', 'stage2_completed_at' => null, 'stage3_data' => '{"height":"180"}', 'stage3_completed_at' => null ) );
    return $wpdb;
}
/** A key made by open_browser()'s own maker (the claim path itself is scenario-open-route-unproven.php), stored as open_browser() stores it. */
function issue() {
    $key  = ( new ReflectionMethod( 'HDL_Stage1_Ticket', 'browser_token' ) )->invoke( null );
    $hash = hash( 'sha256', $key );
    $GLOBALS['transients'][ 'hdlv2_bt_' . $hash ] = FID;
    $GLOBALS['usermeta'][ UID ]['hdlv2_unproven_entry'] = $hash;
    return $key;
}
function tkey( $key ) { return 'hdlv2_bt_' . hash( 'sha256', $key ); }
/** WordPress deletes a meta row by value only when the value matches: a delete must carry this key's hash, or none. */
function mark_deleted( $key = null ) { return (bool) array_filter( $GLOBALS['meta_writes'], function ( $w ) use ( $key ) { return 'delete' === $w[0] && 'hdlv2_unproven_entry' === ( $w[1][1] ?? '' ) && ( null === $key || hash( 'sha256', $key ) === ( $w[1][2] ?? '' ) ); } ); }
function stray_mark_delete( $key ) { return (bool) array_filter( $GLOBALS['meta_writes'], function ( $w ) use ( $key ) { return 'delete' === $w[0] && 'hdlv2_unproven_entry' === ( $w[1][1] ?? '' ) && hash( 'sha256', $key ) !== ( $w[1][2] ?? '' ); } ); }
foreach ( array( 'browser_token', 'is_browser_token', 'browser_row', 'form_token', 'end_browser_key', 'end_browser_key_on_sign_in', 'ended_error' ) as $fn ) {
    check( "BT0 HDL_Stage1_Ticket::$fn() exists", method_exists( 'HDL_Stage1_Ticket', $fn ) );
}
if ( ! method_exists( 'HDL_Stage1_Ticket', 'end_browser_key_on_sign_in' ) || ! defined( 'HDL_Stage1_Ticket::ENDED_TEXT' ) ) done();

echo "── BT1. the key ──\n";
setup();
$key = issue();
check( 'BT1.1 64 hex, never the form\'s own token', is_string( $key ) && preg_match( '/^[a-f0-9]{64}$/', $key ) && FTOK !== $key );
check( 'BT1.2 it is known as a browser key; a random 64 hex and the real token are not', HDL_Stage1_Ticket::is_browser_token( $key ) && ! HDL_Stage1_Ticket::is_browser_token( str_repeat( 'ab', 32 ) ) && ! HDL_Stage1_Ticket::is_browser_token( FTOK ) );
check( 'BT1.3 not a key: a changed signature, 63 hex, upper case, an array, empty', ! HDL_Stage1_Ticket::is_browser_token( substr( $key, 0, 63 ) . ( 'a' === $key[63] ? 'b' : 'a' ) )
    && ! HDL_Stage1_Ticket::is_browser_token( substr( $key, 0, 63 ) ) && ! HDL_Stage1_Ticket::is_browser_token( strtoupper( $key ) )
    && ! HDL_Stage1_Ticket::is_browser_token( array( $key ) ) && ! HDL_Stage1_Ticket::is_browser_token( '' ) );
check( 'BT1.6 one day, in one place', DAY_IN_SECONDS === HDL_Stage1_Ticket::BROWSER_KEY_LIFE );
check( 'BT1.7 two keys differ', issue() !== $key );

echo "── BT2. the resolver ──\n";
setup(); $key = issue();
$row = HDL_Stage1_Ticket::browser_row( $key );
check( 'BT2.1 a live key gives its form row and account', is_object( $row ) && FID === (int) $row->id && UID === (int) $row->client_user_id );
check( 'BT2.2 form_token() turns a live key into the row\'s own token', FTOK === HDL_Stage1_Ticket::form_token( $key ) );
check( 'BT2.3 a live key is not used up (transient kept, no meta write)', isset( $GLOBALS['transients'][ tkey( $key ) ] ) && ! $GLOBALS['meta_writes'] );
setup(); $key = issue(); $GLOBALS['usermeta'] = new LoggedMeta( $GLOBALS['usermeta'] ); $GLOBALS['order'] = array();
HDL_Stage1_Ticket::browser_row( $key );
check( 'BT2.4 the account\'s user-meta copy is dropped before the mark is read', 'cache:user_meta:' . UID === ( $GLOBALS['order'][0] ?? '' ) && in_array( 'read:' . UID, $GLOBALS['order'], true ) );
setup(); $before = count( $wpdb->queries );
check( 'BT2.5 any other token passes form_token() unchanged, with no lookup', FTOK === HDL_Stage1_Ticket::form_token( FTOK ) && str_repeat( 'ab', 32 ) === HDL_Stage1_Ticket::form_token( str_repeat( 'ab', 32 ) ) && count( $wpdb->queries ) === $before );
setup(); $key = issue(); unset( $GLOBALS['transients'][ tkey( $key ) ] );
check( 'BT2.6 the day over (transient gone): refused', null === HDL_Stage1_Ticket::browser_row( $key ) );
setup(); $key = issue(); $GLOBALS['usermeta'][ UID ]['hdlv2_unproven_entry'] = str_repeat( 'f', 64 );
check( 'BT2.7 the mark names another key: refused, this transient deleted, the other mark left', null === HDL_Stage1_Ticket::browser_row( $key ) && ! isset( $GLOBALS['transients'][ tkey( $key ) ] ) && ! stray_mark_delete( $key ) );
setup(); $key = issue(); unset( $GLOBALS['usermeta'][ UID ] );
check( 'BT2.8 no mark: refused, transient deleted', null === HDL_Stage1_Ticket::browser_row( $key ) && ! isset( $GLOBALS['transients'][ tkey( $key ) ] ) );
setup(); $key = issue(); WP_Session_Tokens::$live[ UID ] = array( array( 'expiration' => time() + 3600 ) );
check( 'BT2.9 the account has a live session (the owner signed in): refused, transient and mark deleted', null === HDL_Stage1_Ticket::browser_row( $key ) && ! isset( $GLOBALS['transients'][ tkey( $key ) ] ) && mark_deleted( $key ) );
setup(); $key = issue(); $wpdb->forms[0]->deleted_at = '2026-11-18 02:00:00';
check( 'BT2.10 the form row deleted: refused', null === HDL_Stage1_Ticket::browser_row( $key ) );
setup(); $key = issue(); $wpdb->forms[0]->ran_out = true;
check( 'BT2.11 the form token\'s end date passed: refused, and form_token() gives back the key itself', null === HDL_Stage1_Ticket::browser_row( $key ) && $key === HDL_Stage1_Ticket::form_token( $key ) );
setup(); $before = count( $wpdb->queries );
check( 'BT2.12 a 64 hex that is not a key, the real token, garbage: refused without a lookup', null === HDL_Stage1_Ticket::browser_row( str_repeat( 'ab', 32 ) ) && null === HDL_Stage1_Ticket::browser_row( FTOK )
    && null === HDL_Stage1_Ticket::browser_row( 'x' ) && null === HDL_Stage1_Ticket::browser_row( null ) && null === HDL_Stage1_Ticket::browser_row( array() ) && count( $wpdb->queries ) === $before );

echo "── BT3. the owner's arrival ends it (any sign-in) ──\n";
setup(); $key = issue();
$GLOBALS['usermeta'] = new LoggedMeta( $GLOBALS['usermeta'] );
$GLOBALS['order'] = array();
HDL_Stage1_Ticket::end_browser_key_on_sign_in( 'cookie', 0, 0, UID, 'logged_in', 'session' );
$o = $GLOBALS['order'];
check( 'BT3.1 this request\'s user-meta copy is dropped before the mark is read (review 2, finding 4)', 'cache:user_meta:' . UID === ( $o[0] ?? '' ) && in_array( 'read:' . UID, $o, true ) );
check( 'BT3.2 the transient and the mark are deleted', ! isset( $GLOBALS['transients'][ tkey( $key ) ] ) && mark_deleted() );
$GLOBALS['usermeta'] = array();
check( 'BT3.3 the key is refused after it', null === HDL_Stage1_Ticket::browser_row( $key ) );
setup(); $key = issue(); $GLOBALS['meta_writes'] = array();
HDL_Stage1_Ticket::end_browser_key_on_sign_in( 'cookie', 0, 0, 778, 'logged_in', 'session' );
check( 'BT3.4 another account signing in touches nothing', isset( $GLOBALS['transients'][ tkey( $key ) ] ) && ! mark_deleted() );
$log = (string) file_get_contents( $LOG );
check( 'BT3.5 the log holds no key, no hash and no token', false === strpos( $log, $key ) && false === strpos( $log, hash( 'sha256', $key ) ) && false === strpos( $log, FTOK ) );

echo "── BT4. the form itself, with no sign-in ──\n";
$form = new HDLV2_Staged_Form();
setup(); $key = issue();
$load = $form->rest_load_form( new FakeRequest( array(), array(), 'GET', '', array( 'token' => $key ) ) );
check( 'BT4.1 /form/load with the browser key gives the saved answers', is_array( $load ) && '180' === ( $load['stage3_data']['height'] ?? '' ) && 3 === ( $load['current_stage'] ?? 0 ) );
check( 'BT4.2 its answer never holds the real token', false === strpos( json_encode( $load ), FTOK ) );
$save = $form->rest_save_form( new FakeRequest( array( 'token' => $key, 'stage' => 3, 'data' => array( 'weight' => '80' ) ) ) );
check( 'BT4.3 /form/save with the browser key saves into that row', is_array( $save ) && true === ( $save['success'] ?? null ) && (bool) array_filter( $wpdb->queries, function ( $q ) { return 0 === strpos( ltrim( $q ), 'UPDATE wp_hdlv2_form_progress SET stage3_data' ) && false !== strpos( $q, 'WHERE id = ' . FID ); } ) );
check( 'BT4.4 its answer never holds the real token', false === strpos( json_encode( $save ), FTOK ) );
$real = $form->rest_load_form( new FakeRequest( array(), array(), 'GET', '', array( 'token' => FTOK ) ) );
check( 'BT4.5 the real token still loads (unchanged)', is_array( $real ) && 3 === ( $real['current_stage'] ?? 0 ) );
$rand = $form->rest_load_form( new FakeRequest( array(), array(), 'GET', '', array( 'token' => str_repeat( 'ab', 32 ) ) ) );
check( 'BT4.6 an unknown 64 hex: today\'s "Assessment not found" (404)', $rand instanceof WP_Error && 'invalid_token' === $rand->code && 404 === ( $rand->data['status'] ?? 0 ) );
HDL_Stage1_Ticket::end_browser_key_on_sign_in( 'cookie', 0, 0, UID, 'logged_in', 'session' );
$GLOBALS['usermeta'] = array();
$ended_load = $form->rest_load_form( new FakeRequest( array(), array(), 'GET', '', array( 'token' => $key ) ) );
$ended_save = $form->rest_save_form( new FakeRequest( array( 'token' => $key, 'stage' => 3, 'data' => array( 'weight' => '81' ) ) ) );
$words = function ( $e ) { return $e instanceof WP_Error && 'browser_key_ended' === $e->code && 410 === ( $e->data['status'] ?? 0 )
    && HDL_Stage1_Ticket::ENDED_TEXT === $e->message && HDL_Stage1_Ticket::ENDED_TITLE === ( $e->data['title'] ?? '' ); };
check( 'BT4.7 after the owner signs in, a load says the key ended (410, the words of G4 step 7)', $words( $ended_load ) );
check( 'BT4.8 and a save is refused with the same words', $words( $ended_save ) );
setup(); $key = issue();
$form->rest_save_form( new FakeRequest( array( 'token' => FTOK, 'stage' => 3, 'data' => array( 'weight' => '80' ) ) ) );
check( 'BT4.10 a save with the real token (Make\'s callback and server calls use it too) leaves the key alone', isset( $GLOBALS['transients'][ tkey( $key ) ] ) && ! mark_deleted() );
$form->rest_load_form( new FakeRequest( array(), array(), 'GET', '', array( 'token' => FTOK ) ) );
check( 'BT4.11 the page opened with the real token (the owner, signed in or not; review 3 finding 3) ends the key', ! isset( $GLOBALS['transients'][ tkey( $key ) ] ) && mark_deleted() );
setup(); $key = issue();
$form->rest_load_form( new FakeRequest( array(), array(), 'GET', '', array( 'token' => $key ) ) );
check( 'BT4.12 the page opened with the key itself does not end it', isset( $GLOBALS['transients'][ tkey( $key ) ] ) && ! mark_deleted() );
check( 'BT4.9 the words', "This browser's access has ended" === HDL_Stage1_Ticket::ENDED_TITLE && 'Open the link in your purchase e-mail to carry on. Your answers are saved.' === HDL_Stage1_Ticket::ENDED_TEXT );

echo "── BT5. who accepts the key, read from the source ──\n";
$src = function ( $f ) use ( $ROOT ) { return (string) file_get_contents( $ROOT . $f ); };
$loader = $src( 'hdl-longevity-v2.php' );
$h0 = strpos( $loader, "// ── ?token=TOKEN → auto-login" );
$h1 = strpos( $loader, '// ── ?hdlv2_verify=TOKEN' );
$handler = false !== $h0 && false !== $h1 ? substr( $loader, $h0, $h1 - $h0 ) : '';
$real_at = strpos( $handler, 'WHERE token = %s AND deleted_at IS NULL' );
$bt_at   = strpos( $handler, 'HDL_Stage1_Ticket::browser_row(' );
check( 'BT5.1 the ?token= handler: the real-token lookup first, the browser key on its miss', false !== $real_at && false !== $bt_at && $real_at < $bt_at );
$branch = false !== $bt_at ? substr( $handler, $bt_at, (int) strpos( $handler, 'return;', $bt_at ) - $bt_at ) : 'x';
check( 'BT5.2 the browser-key branch returns before any sign-in (no wp_set_current_user, no wp_set_auth_cookie)', false !== $bt_at && false !== strpos( $handler, 'return;', $bt_at )
    && false === strpos( $branch, 'wp_set_auth_cookie' ) && false === strpos( $branch, 'wp_set_current_user' ) );
check( 'BT5.3 an ended browser key shows the G4 words on the page', false !== strpos( $handler, 'HDL_Stage1_Ticket::ENDED_TITLE' ) && false !== strpos( $handler, 'HDL_Stage1_Ticket::ENDED_TEXT' ) );
$sf = $src( 'includes/sprint-2/class-hdlv2-staged-form.php' );
$gp = substr( $sf, (int) strpos( $sf, 'private function get_progress_by_token' ) );
check( 'BT5.4 the staged-form lookup reads a key as its row\'s token, before its query', false !== strpos( $gp, 'HDL_Stage1_Ticket::form_token( $token )' ) && strpos( $gp, 'form_token' ) < strpos( $gp, 'get_row' ) );
$audio = $src( 'includes/sprint-3/class-hdlv2-audio-service.php' );
check( 'BT5.5 audio: the permission check and the transcript job\'s owner stamp', false !== strpos( $audio, 'HDL_Stage1_Ticket::form_token( $token )' ) && false !== strpos( $audio, 'HDL_Stage1_Ticket::form_token( $owner_token )' ) );
$jq = $src( 'includes/class-hdlv2-job-queue.php' );
check( 'BT5.6 the job status: the gate and the per-job owner check', 2 === substr_count( $jq, 'HDL_Stage1_Ticket::form_token( $token )' ) );
// Every file that reads a key: the ticket class, the loader's ?token= handler and the form's own lookups. Nothing else.
$allowed = array( 'hdl-longevity-v2.php', 'includes/security/class-hdl-stage1-ticket.php', 'includes/sprint-2/class-hdlv2-staged-form.php',
    'includes/sprint-3/class-hdlv2-audio-service.php', 'includes/class-hdlv2-job-queue.php' );
$hits = array();
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $ROOT . 'includes', FilesystemIterator::SKIP_DOTS ) );
foreach ( array_merge( array( new SplFileInfo( $ROOT . 'hdl-longevity-v2.php' ) ), iterator_to_array( $it, false ) ) as $f ) {
    if ( 'php' !== $f->getExtension() ) continue;
    $rel = ltrim( str_replace( realpath( $ROOT ), '', realpath( $f->getPathname() ) ), '/' );
    if ( preg_match( '/form_token\(|browser_row\(/', (string) file_get_contents( $f->getPathname() ) ) && ! in_array( $rel, $allowed, true ) ) $hits[] = $rel;
}
check( 'BT5.7 no other file accepts a key: timeline, check-in, flight plan, /my-report/, the report view, the dashboard (' . implode( ',', $hits ) . ')', ! $hits );
$wc = $src( 'includes/sprint-1/class-hdlv2-widget-config.php' );
$reuse = substr( $wc, (int) strpos( $wc, 'if ( $existing ) {' ), 3000 );
check( 'BT5.8 the owner\'s own Stage 1 reusing the row ends the key (review 2, finding 2b), before its answers are written (review 3, finding 2)',
    false !== strpos( $reuse, 'HDL_Stage1_Ticket::end_browser_key(' ) && strpos( $reuse, 'HDL_Stage1_Ticket::end_browser_key(' ) < strpos( $reuse, '$wpdb->update(' ) );
$tk = $src( 'includes/security/class-hdl-stage1-ticket.php' );
check( 'BT5.9 every sign-in runs the end (set_auth_cookie, the user id is its 4th argument)', (bool) preg_match( "/add_action\(\s*'set_auth_cookie',\s*array\(\s*__CLASS__,\s*'end_browser_key_on_sign_in'\s*\),\s*10,\s*4\s*\)/", $tk ) );

echo "── BT6. what the sign-in design needed is gone ──\n";
check( 'BT6.1 no unproven-guard file', ! file_exists( $ROOT . 'includes/security/class-hdlv2-unproven-guard.php' ) && false === strpos( $loader, 'Unproven_Guard' ) );
check( 'BT6.2 no inbox proof in the ticket class or the ?invite= handler', false === strpos( $tk, 'function inbox_proof' ) && false === strpos( $loader, 'inbox_proof' ) );
check( 'BT6.3 nothing in the ticket class sets a password, ends sessions or rotates form tokens', false === strpos( $tk, 'wp_set_password' ) && false === strpos( $tk, 'destroy_all' ) && false === strpos( $tk, 'delete_all_application_passwords' ) );
check( 'BT6.4 the dashboard has no mark guard', false === strpos( $src( 'includes/sprint-4/class-hdlv2-client-dashboard.php' ), 'is_unproven' ) );
check( 'BT6.5 open_browser() never reads the row\'s real token', (bool) preg_match( '/private static function open_browser\(.*?\n    }\n/s', $tk, $ob ) && false === strpos( $ob[0], '->token' ) && false !== strpos( $ob[0], 'SELECT id FROM' ) );
$signin = array_filter( array( 'open_browser', 'browser_token', 'is_browser_token', 'browser_row', 'form_token', 'end_browser_key', 'ended_error' ), function ( $fn ) use ( $tk ) {
    return preg_match( '/static function ' . $fn . '\(.*?\n    }\n/s', $tk, $m ) && preg_match( '/wp_set_current_user|wp_set_auth_cookie|wp_signon/', $m[0] );
} );
check( 'BT6.6 no key function signs anyone in (' . implode( ',', $signin ) . ')', ! $signin );
check( 'BT6.7 the session check is the account\'s own session list', false !== strpos( $tk, 'WP_Session_Tokens::get_instance( $uid )->get_all()' ) );

echo "── BT6b. no staged-form answer carries the real token ──\n";
// Every function in the staged form that holds a token value or a ?token= address, by name. Each allowed one
// sends it to the inbox (e-mail), to Make, to itself (server loopback), or is a practitioner route.
$allowed_fns = array( 'rest_complete_stage', 'rest_create_form', 'send_stage_email', 'fire_make_webhook', 'fire_stage2_webhook', 'validate_token_param',
    'validate_token_from_body', 'rest_stage2_callback', 'generate_draft_for_progress', 'get_progress_by_token' );
preg_match_all( '/\n    (?:public |private |protected )?(?:static )?function (\w+)\s*\(/', $sf, $fm, PREG_OFFSET_CAPTURE );
$extra = array();
foreach ( $fm[1] as $i => $f ) {
    $body = substr( $sf, $f[1], ( $fm[1][ $i + 1 ][1] ?? strlen( $sf ) ) - $f[1] );
    if ( ( false !== strpos( $body, '?token=' ) || false !== strpos( $body, "'token'" ) ) && ! in_array( $f[0], $allowed_fns, true ) ) $extra[] = $f[0];
}
check( 'BT6b.1 the functions found are many (the scan works)', count( $fm[1] ) > 40 );
check( 'BT6b.2 only the allowed functions hold a token (' . implode( ',', $extra ) . ')', ! $extra );

echo "── BT7. the page script shows the server's words ──\n";
$js = $src( 'assets/js/hdlv2-staged-form.js' );
check( 'BT7.1 load and autosave handle browser_key_ended', 2 <= substr_count( $js, "'browser_key_ended'" ) );
check( 'BT7.2 the words live on the server only', false === strpos( $js, 'access has ended' ) );
@unlink( $LOG );
done();
