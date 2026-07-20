<?php
/**
 * Stage-2 retry cron selection tests — v0.47.59 (Option B).
 *
 * The bug (v0.47.57 and earlier): the candidate SELECT in
 * run_stage2_extraction_retry() filtered only on stage2_webhook_fired_at +
 * missing why_profiles row. Soft-deleted and token-expired rows were
 * re-fired at Make ~2×/week forever; Make's callback then always 404'd
 * "Assessment not found" because get_progress_by_token() (B4) requires
 * `deleted_at IS NULL AND token_expires_at > UTC_TIMESTAMP()`.
 *
 * The fix (Option B, per review + Quim):
 *   - SQL: `AND fp.deleted_at IS NULL` — archived rows never retried at
 *     all (no Make, no Claude). Expiry NOT in the WHERE.
 *   - Loop: token expiry gates ONLY the Make re-fire branch. An
 *     expired-but-alive row runs the token-independent LOCAL fallback
 *     (local_extract_why_profile) instead, on every attempt, so the
 *     why_profile is still rescued. NULL expiry = invalid, fail closed.
 *
 * The fake wpdb is behavioural: it applies ONLY the predicates present in
 * the candidate SQL, and implements the why_profiles guarded-upsert
 * surface (existence get_var, GET_LOCK/RELEASE_LOCK, insert).
 *
 * Proves:
 *  (a) a soft-deleted stuck row is never selected → no Make, no local,
 *      no counter;
 *  (b) an expired-but-alive stuck row does NOT re-fire Make but DOES get
 *      the local WHY rescue (extract_why called, why_profiles row
 *      inserted, counter advanced);
 *  (c) a stuck-but-valid row still re-fires Make exactly once (and does
 *      NOT run local extraction);
 *  (d) a NULL-expiry legacy alive row = fail-closed to the LOCAL branch
 *      (no Make), same rescue as (b);
 *  (e) an expired-alive row already at attempt 2 goes local (ladder
 *      semantics intact, >=3 exhaustion guard untouched);
 *  (f) query shape: WHERE carries fp.deleted_at IS NULL, does NOT carry
 *      an expiry predicate, and the SELECT list carries
 *      fp.token_expires_at for the loop check.
 *
 * Run:  php scenario-retry-selection.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HDLV2_MAKE_STAGE2_WHY', 'https://hook.example.test/stage2-why' );
define( 'HDLV2_MAKE_CALLBACK_SECRET', 'test-callback-secret' );
// v0.47.74 — the retry loop now asks HDLV2_Env::gate() before firing; this
// suite tests the retry SELECTION logic, so run with side-effects allowed
// (the gate itself is covered by tests/email-safety/).
define( 'HDLV2_STAGING_SIDE_EFFECTS', true );

// ── WP stubs ──
$GLOBALS['transients'] = array();
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function apply_filters( $tag, $value ) { return $value; }
function home_url( $path = '' ) { return 'https://stby.example.test' . $path; }
function rest_url( $path = '' ) { return 'https://stby.example.test/wp-json/' . ltrim( $path, '/' ); }
function get_userdata( $id ) { return false; }
function current_time( $fmt ) { return 'mysql' === $fmt ? gmdate( 'Y-m-d H:i:s' ) : gmdate( 'c' ); }
function wp_json_encode( $x ) { return json_encode( $x ); }
function sanitize_textarea_field( $x ) { return (string) $x; }
function wp_kses_post( $x ) { return (string) $x; }
function add_action() {}
function add_shortcode() {}

// Spy AI service — records local extract_why calls, returns a fake profile.
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
class HDLV2_Practitioner {
    public static function get_logo_url( $id, $fallback = false ) { return 'https://stby.example.test/logo.png'; }
}
// Spy — records every Make fire instead of doing HTTP.
class HDLV2_Webhook_Monitor {
    public static $fires = array();
    public static function fire( $url, $args, $tag = '' ) {
        self::$fires[] = array( 'url' => $url, 'tag' => $tag, 'body' => $args['body'] ?? '' );
        return true;
    }
}

// ── Fake wpdb — applies only the predicates present in the SQL, plus the
//    why_profiles guarded-upsert surface used by local_extract_why_profile ──
class FakeWpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $rows = array();            // form_progress: id => row object
    public $why_inserts = array();     // recorded why_profiles inserts
    public $captured_candidate_sql = null;
    public $updates = array();

    public function prepare( $sql, ...$args ) {
        foreach ( $args as $a ) {
            $sql = preg_replace( '/%d/', (string) (int) $a, $sql, 1 );
            $sql = preg_replace( '/%s/', "'" . $a . "'", $sql, 1 );
        }
        return $sql;
    }

    /** Does the SELECT list (before FROM) carry a column? */
    public static function selects_column( $sql, $col ) {
        $from = stripos( $sql, ' FROM' );
        return false !== $from && false !== strpos( substr( $sql, 0, $from ), $col );
    }

    /** Candidate SELECT of run_stage2_extraction_retry(). */
    public function get_results( $sql ) {
        $this->captured_candidate_sql = $sql;
        $selects_expiry = self::selects_column( $sql, 'fp.token_expires_at' );
        $out = array();
        foreach ( $this->rows as $r ) {
            if ( null === $r->stage2_webhook_fired_at ) continue;   // IS NOT NULL (+ all rows fired long ago, < threshold)
            if ( $this->why_exists( $r->id ) ) continue;            // LEFT JOIN wpr … wpr.id IS NULL
            // Behavioural core — apply ONLY what the SQL asks for:
            if ( preg_match( '/fp\.deleted_at\s+IS\s+NULL/i', $sql ) && null !== $r->deleted_at ) continue;
            if ( preg_match( '/fp\.token_expires_at\s*>\s*UTC_TIMESTAMP\(\)/i', $sql ) ) {
                if ( null === $r->token_expires_at || strtotime( $r->token_expires_at . ' UTC' ) <= time() ) continue;
            }
            $o = array(
                'id'             => $r->id,
                'token'          => $r->token,
                'client_user_id' => $r->client_user_id,
                'stage2_data'    => $r->stage2_data,
                'client_name'    => $r->client_name,
            );
            // Only expose the column if the SQL actually selected it — a
            // regression dropping it from the SELECT list must fail loudly
            // (every row would then read as expired and Make never fires).
            if ( $selects_expiry ) $o['token_expires_at'] = $r->token_expires_at;
            $out[] = (object) $o;
        }
        usort( $out, function ( $a, $b ) { return $b->id <=> $a->id; } ); // ORDER BY fp.id DESC
        return $out;
    }

    /** Bare reload inside retry_stage2_webhook() — no filters, by design. */
    public function get_row( $sql ) {
        if ( preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
            return $this->rows[ (int) $m[1] ] ?? null;
        }
        return null;
    }

    /** why_profiles existence checks + GET_LOCK, per local_extract_why_profile(). */
    public function get_var( $sql ) {
        if ( false !== strpos( $sql, 'GET_LOCK' ) ) return 1;
        if ( preg_match( '/FROM wp_hdlv2_why_profiles WHERE form_progress_id = (\d+)/', $sql, $m ) ) {
            return $this->why_exists( (int) $m[1] ) ? 1 : null;
        }
        return null;
    }

    public function query( $sql ) { return 1; } // RELEASE_LOCK

    public function insert( $table, $data, $formats = null ) {
        if ( false !== strpos( $table, 'why_profiles' ) ) {
            $this->why_inserts[] = $data;
            return 1;
        }
        return 1;
    }

    public function update( $table, $data, $where ) {
        $this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
        return 1;
    }

    private function why_exists( $fp_id ) {
        foreach ( $this->why_inserts as $ins ) {
            if ( (int) $ins['form_progress_id'] === (int) $fp_id ) return true;
        }
        return false;
    }
}

function make_row( $id, $deleted_at, $token_expires_at ) {
    return (object) array(
        'id'                      => $id,
        'token'                   => str_repeat( dechex( $id % 16 ), 64 ),
        'client_user_id'          => 900 + $id,
        'practitioner_user_id'    => 0,
        'client_name'             => 'QA Row ' . $id,
        'client_email'            => 'qa' . $id . '@example.test',
        'stage1_data'             => '{}',
        'stage2_data'             => json_encode( array( 'vision_text' => 'A long enough vision text for row ' . $id ) ),
        'stage2_webhook_fired_at' => '2020-01-01 00:00:00',
        'deleted_at'              => $deleted_at,
        'token_expires_at'        => $token_expires_at,
    );
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

$future = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
$past   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

$wpdb = new FakeWpdb();
$wpdb->rows = array(
    101 => make_row( 101, null, $future ),                  // stuck + VALID → Make re-fire
    102 => make_row( 102, '2026-07-01 00:00:00', $future ), // soft-deleted → never selected
    103 => make_row( 103, null, $past ),                    // expired-but-ALIVE → local rescue, no Make
    104 => make_row( 104, null, null ),                     // NULL-expiry alive → fail-closed to local, no Make
    105 => make_row( 105, null, $past ),                    // expired-alive at attempt 2 → local (ladder intact)
);
$GLOBALS['wpdb'] = $wpdb;
$GLOBALS['transients']['hdlv2_stage2_retry_105'] = 2;

HDLV2_Staged_Form::run_stage2_extraction_retry();

// (f) query shape
$sql = (string) $wpdb->captured_candidate_sql;
check( 'candidate SQL filters fp.deleted_at IS NULL', (bool) preg_match( '/fp\.deleted_at\s+IS\s+NULL/i', $sql ) );
check( 'candidate SQL has NO expiry predicate in WHERE', ! preg_match( '/token_expires_at\s*>\s*UTC_TIMESTAMP/i', $sql ) );
check( 'candidate SELECT list carries fp.token_expires_at for the loop check', FakeWpdb::selects_column( $sql, 'fp.token_expires_at' ) );

// (c) valid row: Make fired exactly once, and only for it; no local run for it
$fires = HDLV2_Webhook_Monitor::$fires;
check( 'exactly ONE Make webhook fired', 1 === count( $fires ) );
$body = json_decode( $fires[0]['body'] ?? '', true ) ?: array();
check( 'the fire is the stuck-but-VALID row 101', ( $body['token'] ?? '' ) === $wpdb->rows[101]->token );
check( 'fire went to HDLV2_MAKE_STAGE2_WHY as stage2_why', ( $fires[0]['url'] ?? '' ) === HDLV2_MAKE_STAGE2_WHY && ( $fires[0]['tag'] ?? '' ) === 'stage2_why' );
check( 'payload still carries callback_secret for module 86', ( $body['callback_secret'] ?? '' ) === HDLV2_MAKE_CALLBACK_SECRET );
check( 'valid row attempt counter set to 1', 1 === ( $GLOBALS['transients']['hdlv2_stage2_retry_101'] ?? 0 ) );
$updated_ids = array_map( function ( $u ) { return $u['where']['id'] ?? 0; }, $wpdb->updates );
check( 'valid row stage2_webhook_fired_at refreshed', in_array( 101, $updated_ids, true ) );

// (b)+(d)+(e) local rescues: rows 103, 104, 105 — extract_why ran, why row inserted, NO Make
$rescued = array_map( function ( $ins ) { return (int) $ins['form_progress_id']; }, $wpdb->why_inserts );
sort( $rescued );
check( 'local rescue ran for exactly rows 103,104,105', array( 103, 104, 105 ) === $rescued );
check( 'extract_why called exactly 3 times (never for valid 101 or deleted 102)', 3 === count( HDLV2_AI_Service::$calls ) );
check( 'expired-alive row 103: counter advanced to 1 (local branch)', 1 === ( $GLOBALS['transients']['hdlv2_stage2_retry_103'] ?? 0 ) );
check( 'NULL-expiry alive row 104: counter advanced to 1 (fail-closed local)', 1 === ( $GLOBALS['transients']['hdlv2_stage2_retry_104'] ?? 0 ) );
check( 'expired-alive row 105 at attempt 2: went local, counter now 3', 3 === ( $GLOBALS['transients']['hdlv2_stage2_retry_105'] ?? 0 ) );
$rescued_whys = array_map( function ( $ins ) { return $ins['distilled_why'] ?? ''; }, $wpdb->why_inserts );
check( 'rescued why rows carry the extracted distilled_why', array( 'QA distilled why', 'QA distilled why', 'QA distilled why' ) === array_values( $rescued_whys ) );

// (a) soft-deleted row fully untouched
check( 'soft-deleted row 102: no counter, no update, no rescue',
    ! isset( $GLOBALS['transients']['hdlv2_stage2_retry_102'] )
    && ! in_array( 102, $updated_ids, true )
    && ! in_array( 102, $rescued, true ) );

echo "\n" . ( $fail ? "SCENARIO: FAIL ($fail)\n" : "SCENARIO: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );
