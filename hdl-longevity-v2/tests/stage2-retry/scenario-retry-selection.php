<?php
/**
 * Stage-2 retry cron selection tests — run_stage2_extraction_retry() must
 * not re-fire soft-deleted or token-expired rows.
 *
 * The bug: the candidate SELECT (~staged-form.php:2341) filters only on
 * stage2_webhook_fired_at + missing why_profiles row. It has NO
 * deleted_at / token_expires_at predicates, while get_progress_by_token()
 * (the lookup Make's callback authenticates the token against) requires
 * `deleted_at IS NULL AND token_expires_at > UTC_TIMESTAMP()` since B4
 * (v0.47.53). So a soft-deleted or expired stuck row is re-fired to
 * Make.com, whose callback then always 404s "Assessment not found" —
 * phantom 404s + wasted Make executions, ~2×/week per stuck row (the
 * 7-day retry transient keeps resetting).
 *
 * The fake wpdb below is behavioural: it holds four stuck rows and applies
 * ONLY the predicates actually present in the candidate SQL. Against the
 * unfixed query all four rows come back and four webhooks fire (RED);
 * once the query carries fp.deleted_at IS NULL AND
 * fp.token_expires_at > UTC_TIMESTAMP(), only the valid row fires (GREEN).
 *
 * Proves:
 *  (a) a soft-deleted stuck row is never selected → no Make fire;
 *  (b) a token-expired stuck row is never selected → no Make fire;
 *  (c) a NULL-expiry legacy row is excluded fail-closed (SQL three-valued
 *      logic: NULL > UTC_TIMESTAMP() is not true) — same semantics as
 *      get_progress_by_token, whose 404 these rows could only ever hit;
 *  (d) a stuck-but-valid row IS still retried: webhook re-fired once to
 *      HDLV2_MAKE_STAGE2_WHY with the row's token, attempt counter set,
 *      stage2_webhook_fired_at refreshed;
 *  (e) belt-and-braces: the candidate SQL literally carries both
 *      fp.-qualified predicates, so a refactor dropping them fails loudly;
 *  (f) DOCUMENTED TRADEOFF (4-lens review 2026-07-11): dead rows sitting
 *      at attempt 2 do NOT proceed to the attempt-3 LOCAL fallback either
 *      — exclusion is at selection, all three attempts. Recovery for an
 *      alive-but-expired client = practitioner re-issue (refreshes
 *      token_expires_at on the same row, re-arming this cron). The
 *      extract_why stub hard-fails the scenario if the local branch runs.
 *
 * Run:  php scenario-retry-selection.php   (self-asserting, exit 0/1)
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HDLV2_MAKE_STAGE2_WHY', 'https://hook.example.test/stage2-why' );
define( 'HDLV2_MAKE_CALLBACK_SECRET', 'test-callback-secret' );

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
function add_action() {}
function add_shortcode() {}

// AI service only needs to exist — the valid row is at attempt 1 (Make
// re-fire branch); the local-extract branch must never run in this test.
class HDLV2_AI_Service {
    public static function extract_why() {
        echo "FAIL  local extract_why called — attempt counter logic broken\n";
        exit( 1 );
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

// ── Fake wpdb — applies only the predicates present in the SQL ──
class FakeWpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $rows = array();            // id => row object (full column set)
    public $captured_candidate_sql = null;
    public $updates = array();

    public function prepare( $sql, ...$args ) {
        foreach ( $args as $a ) {
            $sql = preg_replace( '/%d/', (string) (int) $a, $sql, 1 );
            $sql = preg_replace( '/%s/', "'" . $a . "'", $sql, 1 );
        }
        return $sql;
    }

    /** Candidate SELECT of run_stage2_extraction_retry(). */
    public function get_results( $sql ) {
        $this->captured_candidate_sql = $sql;
        $out = array();
        foreach ( $this->rows as $r ) {
            if ( null === $r->stage2_webhook_fired_at ) continue;   // IS NOT NULL (+ all rows fired long ago, < threshold)
            if ( $r->why_profile_exists ) continue;                 // LEFT JOIN wpr … wpr.id IS NULL
            // Behavioural core — apply ONLY what the SQL asks for:
            if ( preg_match( '/fp\.deleted_at\s+IS\s+NULL/i', $sql ) && null !== $r->deleted_at ) continue;
            if ( preg_match( '/fp\.token_expires_at\s*>\s*UTC_TIMESTAMP\(\)/i', $sql ) ) {
                // SQL three-valued logic: NULL fails the predicate.
                if ( null === $r->token_expires_at || strtotime( $r->token_expires_at . ' UTC' ) <= time() ) continue;
            }
            $out[] = (object) array(
                'id'             => $r->id,
                'token'          => $r->token,
                'client_user_id' => $r->client_user_id,
                'stage2_data'    => $r->stage2_data,
                'client_name'    => $r->client_name,
            );
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

    public function update( $table, $data, $where ) {
        $this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
        return 1;
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
        'why_profile_exists'      => false,
    );
}

// ── Load the real class ──
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
    101 => make_row( 101, null, $future ),                                  // stuck but VALID → must retry
    102 => make_row( 102, '2026-07-01 00:00:00', $future ),                 // soft-deleted → never
    103 => make_row( 103, null, $past ),                                    // token expired → never
    104 => make_row( 104, null, null ),                                     // NULL expiry legacy → never (fail closed)
);
$GLOBALS['wpdb'] = $wpdb;

// (f) dead rows parked at attempt 2: if selected, next=3 → local extract_why
// → the stub above exits(1). Counters must stay untouched at 2.
$GLOBALS['transients']['hdlv2_stage2_retry_102'] = 2;
$GLOBALS['transients']['hdlv2_stage2_retry_103'] = 2;

HDLV2_Staged_Form::run_stage2_extraction_retry();

// (e) query shape — fp.-qualified, so a refactor dropping either fails loudly
$sql = (string) $wpdb->captured_candidate_sql;
check( 'candidate SQL filters fp.deleted_at IS NULL', (bool) preg_match( '/fp\.deleted_at\s+IS\s+NULL/i', $sql ) );
check( 'candidate SQL filters fp.token_expires_at > UTC_TIMESTAMP()', (bool) preg_match( '/fp\.token_expires_at\s*>\s*UTC_TIMESTAMP\(\)/i', $sql ) );

// (a)+(b)+(c)+(d) behaviour — exactly one Make fire, and it is the valid row
$fires = HDLV2_Webhook_Monitor::$fires;
check( 'exactly ONE Make webhook fired (dead/expired rows excluded)', 1 === count( $fires ) );
$body = json_decode( $fires[0]['body'] ?? '', true ) ?: array();
check( 'the fire is the stuck-but-VALID row 101', ( $body['token'] ?? '' ) === $wpdb->rows[101]->token );
check( 'fire went to HDLV2_MAKE_STAGE2_WHY as stage2_why', ( $fires[0]['url'] ?? '' ) === HDLV2_MAKE_STAGE2_WHY && ( $fires[0]['tag'] ?? '' ) === 'stage2_why' );
check( 'payload still carries callback_secret for module 86', ( $body['callback_secret'] ?? '' ) === HDLV2_MAKE_CALLBACK_SECRET );

// (d) valid row bookkeeping intact
check( 'valid row attempt counter set to 1', 1 === ( $GLOBALS['transients']['hdlv2_stage2_retry_101'] ?? 0 ) );
$updated_ids = array_map( function ( $u ) { return $u['where']['id'] ?? 0; }, $wpdb->updates );
check( 'valid row stage2_webhook_fired_at refreshed', in_array( 101, $updated_ids, true ) );

// (a)+(b)+(f) dead rows at attempt 2 untouched — counter frozen, no local
// extraction (the stub would have exit(1)'d), no update
foreach ( array( 102 => 'soft-deleted', 103 => 'token-expired' ) as $id => $why ) {
    check( "$why row $id: attempt counter frozen at 2 (no attempt-3 local run)", 2 === ( $GLOBALS['transients'][ 'hdlv2_stage2_retry_' . $id ] ?? -1 ) );
    check( "$why row $id: no DB update", ! in_array( $id, $updated_ids, true ) );
}
// (c) NULL-expiry legacy row fully untouched — never entered the loop
check( 'NULL-expiry legacy row 104: no attempt counter', ! isset( $GLOBALS['transients']['hdlv2_stage2_retry_104'] ) );
check( 'NULL-expiry legacy row 104: no DB update', ! in_array( 104, $updated_ids, true ) );

echo "\n" . ( $fail ? "SCENARIO: FAIL ($fail)\n" : "SCENARIO: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );
