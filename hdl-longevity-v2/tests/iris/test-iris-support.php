<?php
/**
 * Pure-logic unit tests for HDLV2_Iris_Support (no WordPress required).
 *
 * Run:  php tests/iris/test-iris-support.php
 *
 * These lock the parts of the Phase-2 contract that are easy to get wrong and
 * where a bug is silent + dangerous: the callback HMAC verification (must match
 * IrisMapper's utils/hdl-hmac.js signHeaders byte-for-byte), jobId validation
 * (a Supabase key segment + Firestore doc id → path-traversal sensitive), the
 * poll-state mapping the browser depends on, and the circuit-breaker decisions
 * that protect the OLS worker pool when IrisMapper is down.
 */

error_reporting(E_ALL);

$ROOT = dirname(dirname(__DIR__));
require $ROOT . '/includes/class-hdlv2-iris-support.php';

// ─── tiny test harness ───
$GLOBALS['__tests'] = 0;
$GLOBALS['__fail']  = 0;
function ok($cond, $msg) {
    $GLOBALS['__tests']++;
    if ($cond) { echo "  ok   - $msg\n"; }
    else { $GLOBALS['__fail']++; echo "  FAIL - $msg\n"; }
}
function eq($a, $b, $msg) { ok($a === $b, $msg . "  (got " . var_export($a, true) . ", want " . var_export($b, true) . ")"); }
function section($t) { echo "\n# $t\n"; }

$S = 'HDLV2_Iris_Support';

// ─────────────────────────────────────────────────────────────────────────
section('jobId validation (regex [A-Za-z0-9:_-]{8,200}, no "..")');
ok($S::is_valid_job_id('123:45:9f1c2b3a-4d5e-6789-abcd-ef0123456789'), 'accepts clientId:consultId:uuid form');
ok($S::is_valid_job_id('abcd1234'), 'accepts 8-char minimum');
ok(!$S::is_valid_job_id('short'), 'rejects < 8 chars');
ok(!$S::is_valid_job_id('has spaces here'), 'rejects spaces');
ok(!$S::is_valid_job_id('analysis/../etc'), 'rejects path traversal "/" + ".."');
ok(!$S::is_valid_job_id('a/b/c/dddd'), 'rejects forward slash');
ok(!$S::is_valid_job_id('aaaa..bbbb'), 'rejects embedded ".." even without slash');
ok(!$S::is_valid_job_id(''), 'rejects empty');
ok(!$S::is_valid_job_id(str_repeat('a', 201)), 'rejects > 200 chars');

section('build_job_id');
$jid = $S::build_job_id(118, 105, '9f1c2b3a4d5e6789abcdef0123456789');
eq($jid, '118:105:9f1c2b3a4d5e6789abcdef0123456789', 'composes client:consult:uuid');
ok($S::is_valid_job_id($jid), 'composed id is valid');
ok($S::build_job_id(0, 105, 'x') === null, 'rejects falsy client id');
ok($S::build_job_id(118, 0, 'x') === null, 'rejects falsy consultation id');

section('compute_signature == HMAC-SHA256(secret, "ts.rawBody") hex (IrisMapper hdl-hmac.js parity)');
$secret = 'callback-secret-xyz';
$ts     = '1700000000000';
$body   = '{"jobId":"118:105:abc","status":"done"}';
$expected = hash_hmac('sha256', $ts . '.' . $body, $secret); // the EXACT formula Node uses
eq($S::compute_signature($secret, $ts, $body), $expected, 'signature matches ${ts}.${rawBody} construction');

section('verify_callback (mirrors verifyInbound: misconfigured | stale_timestamp | bad_signature | ok)');
$now = 1700000000000;
$good = $S::compute_signature($secret, (string) $now, $body);
$r = $S::verify_callback($secret, (string) $now, $good, $body, $now);
ok($r['ok'] === true, 'accepts a correctly signed, fresh request');

$r = $S::verify_callback($secret, (string) $now, $good, $body . 'tamper', $now);
ok($r['ok'] === false && $r['reason'] === 'bad_signature', 'rejects a tampered body (bad_signature)');

$r = $S::verify_callback('wrong-secret', (string) $now, $good, $body, $now);
ok($r['ok'] === false && $r['reason'] === 'bad_signature', 'rejects a wrong secret (bad_signature)');

$stale = $now - (6 * 60 * 1000); // 6 min in the past, default skew 5 min
$sig_stale = $S::compute_signature($secret, (string) $stale, $body);
$r = $S::verify_callback($secret, (string) $stale, $sig_stale, $body, $now);
ok($r['ok'] === false && $r['reason'] === 'stale_timestamp', 'rejects a timestamp outside the ±5min skew');

$fresh4 = $now - (4 * 60 * 1000); // 4 min in the past, inside skew
$sig_fresh = $S::compute_signature($secret, (string) $fresh4, $body);
$r = $S::verify_callback($secret, (string) $fresh4, $sig_fresh, $body, $now);
ok($r['ok'] === true, 'accepts a timestamp 4min old (inside skew)');

$r = $S::verify_callback('', (string) $now, $good, $body, $now);
ok($r['ok'] === false && $r['reason'] === 'misconfigured', 'rejects when secret is empty (misconfigured, fail-closed)');
$r = $S::verify_callback($secret, '', '', $body, $now);
ok($r['ok'] === false && $r['reason'] === 'misconfigured', 'rejects when timestamp/signature missing (misconfigured)');
$r = $S::verify_callback($secret, 'not-a-number', $good, $body, $now);
ok($r['ok'] === false && $r['reason'] === 'stale_timestamp', 'rejects a non-numeric timestamp');

section('sign_headers (outbound) round-trips through verify_callback');
$h = $S::sign_headers($secret, $body, $now);
ok(isset($h['x-hdl-timestamp']) && isset($h['x-hdl-signature']), 'returns both headers');
$r = $S::verify_callback($secret, $h['x-hdl-timestamp'], $h['x-hdl-signature'], $body, $now);
ok($r['ok'] === true, 'sign_headers output verifies');

section('parse_callback_body (validate the inbound result envelope)');
$p = $S::parse_callback_body(array('jobId' => '118:105:abc', 'status' => 'done', 'result' => array('eyes' => array()), 'cost' => 0.05));
ok($p['ok'] === true && $p['status'] === 'done' && is_array($p['result']), 'accepts a well-formed done body');
$p = $S::parse_callback_body(array('jobId' => '118:105:abc', 'status' => 'error', 'error' => 'Opus refused', 'refused' => true));
ok($p['ok'] === true && $p['status'] === 'error' && $p['refused'] === true, 'accepts a well-formed error body');
$p = $S::parse_callback_body(array('jobId' => 'bad id with spaces', 'status' => 'done', 'result' => array()));
ok($p['ok'] === false, 'rejects an invalid jobId');
$p = $S::parse_callback_body(array('jobId' => '118:105:abc', 'status' => 'weird'));
ok($p['ok'] === false, 'rejects an unknown status');
$p = $S::parse_callback_body(array('jobId' => '118:105:abc', 'status' => 'done'));
ok($p['ok'] === false, 'rejects a done body with no result');

section('map_poll_state (DB row → browser poll contract; never leaks raw row)');
eq($S::map_poll_state(array('status' => 'queued'))['state'], 'queued', 'queued passes through');
eq($S::map_poll_state(array('status' => 'running'))['state'], 'running', 'running passes through');
eq($S::map_poll_state(array('status' => 'limit'))['state'], 'limit', 'limit passes through (soft)');
eq($S::map_poll_state(array('status' => 'unavailable'))['state'], 'unavailable', 'unavailable passes through (fail-closed)');
$done = $S::map_poll_state(array('status' => 'done', 'result_json' => '{"eyes":[{"eye":"L"}],"bilateral_notes":[]}'));
ok($done['state'] === 'done' && isset($done['result']['eyes']), 'done decodes result_json into result');
$donebad = $S::map_poll_state(array('status' => 'done', 'result_json' => 'not json'));
eq($donebad['state'], 'error', 'corrupt result_json degrades to error (never throws)');
$err = $S::map_poll_state(array('status' => 'error', 'error' => 'failed', 'refused' => 1));
ok($err['state'] === 'error' && $err['refused'] === true && $err['error'] === 'failed', 'error surfaces message + refused');
$prefer_edit = $S::map_poll_state(array('status' => 'done', 'result_json' => '{"eyes":[]}', 'areas_edited_json' => '{"eyes":[{"eye":"L","edited":true}]}'));
ok(isset($prefer_edit['result']['eyes'][0]['edited']) && $prefer_edit['result']['eyes'][0]['edited'] === true, 'done prefers the practitioner-edited overlay when present');

// ─────────────────────────────────────────────────────────────────────────
//  NATIVE-CAPTURE (Phase-2 pivot) — captureId contract
//  captureId = clientId:consultationId:irisSetHash[:vN]. HDL keys its row +
//  dedupe on captureId (NOT a per-attempt jobId). The wire body carries
//  captureId + a `finalized` flag (auto safety-net draft=false, button=true).
// ─────────────────────────────────────────────────────────────────────────
section('captureId validation (clientId:consultationId:irisSetHash[:vN])');
$hash = str_repeat('a1', 32); // 64-hex sha256-ish
ok($S::is_valid_capture_id('118:105:' . $hash), 'accepts clientId:consultId:hash');
ok($S::is_valid_capture_id('118:105:' . $hash . ':v2'), 'accepts an explicit :vN version suffix');
ok(!$S::is_valid_capture_id('118:105'), 'rejects a 2-part id (no hash segment)');
ok(!$S::is_valid_capture_id('abc:105:' . $hash), 'rejects a non-numeric client segment');
ok(!$S::is_valid_capture_id('118:abc:' . $hash), 'rejects a non-numeric consultation segment');
ok(!$S::is_valid_capture_id('0:105:' . $hash), 'rejects a zero client id');
ok(!$S::is_valid_capture_id('118:105:has spaces'), 'rejects spaces');
ok(!$S::is_valid_capture_id('118:105:../etc'), 'rejects path traversal');
ok(!$S::is_valid_capture_id(''), 'rejects empty');

section('build_capture_id + segment extractors');
$cap = $S::build_capture_id(118, 105, $hash);
eq($cap, '118:105:' . $hash, 'composes client:consult:hash');
ok($S::is_valid_capture_id($cap), 'composed captureId is valid');
eq($S::build_capture_id(118, 105, $hash, 2), '118:105:' . $hash . ':v2', 'composes a versioned captureId');
ok($S::build_capture_id(0, 105, $hash) === null, 'rejects a falsy client id');
ok($S::build_capture_id(118, 0, $hash) === null, 'rejects a falsy consultation id');
ok($S::build_capture_id(118, 105, '') === null, 'rejects an empty hash');
eq($S::client_id_from_capture_id($cap), 118, 'client_id_from_capture_id');
eq($S::consultation_id_from_capture_id($cap), 105, 'consultation_id_from_capture_id');

section('parse_callback_body — native captureId + finalized (draft vs final)');
$pf = $S::parse_callback_body(array('captureId' => $cap, 'status' => 'done', 'finalized' => true, 'result' => array('eyes' => array()), 'cost' => 0.05));
ok($pf['ok'] === true && $pf['captureId'] === $cap && $pf['status'] === 'done' && $pf['finalized'] === true && !isset($pf['jobId']), 'final push: captureId, status done, finalized=true, no jobId');
$pd = $S::parse_callback_body(array('captureId' => $cap, 'status' => 'done', 'finalized' => false, 'result' => array('eyes' => array())));
ok($pd['ok'] === true && $pd['finalized'] === false && $pd['status'] === 'done', 'draft push: finalized=false, still status done, carries result');
$pp = $S::parse_callback_body(array('captureId' => $cap, 'status' => 'pending', 'result' => array('eyes' => array())));
ok($pp['ok'] === true && $pp['status'] === 'done' && $pp['finalized'] === false, "status 'pending' normalises to done + finalized=false (draft)");
$pn = $S::parse_callback_body(array('captureId' => $cap, 'status' => 'done', 'result' => array('eyes' => array())));
ok($pn['ok'] === true && $pn['finalized'] === true, 'native done with no finalized key defaults to finalized=true (final)');
$pbad = $S::parse_callback_body(array('captureId' => '118:105:has spaces', 'status' => 'done', 'result' => array()));
ok($pbad['ok'] === false, 'rejects an invalid captureId');
$pdr = $S::parse_callback_body(array('captureId' => $cap, 'status' => 'done'));
ok($pdr['ok'] === false, 'native done with no result is rejected (draft must still carry the durable result)');
$pe = $S::parse_callback_body(array('captureId' => $cap, 'status' => 'error', 'error' => 'refused', 'refused' => true));
ok($pe['ok'] === true && $pe['captureId'] === $cap && $pe['status'] === 'error' && $pe['refused'] === true, 'native error push keyed on captureId');

section('parse_callback_body — legacy jobId still finalized=true (back-compat, embedded path)');
$pl = $S::parse_callback_body(array('jobId' => '118:105:abc', 'status' => 'done', 'result' => array('eyes' => array())));
ok($pl['ok'] === true && $pl['jobId'] === '118:105:abc' && $pl['finalized'] === true && !isset($pl['captureId']), 'legacy jobId done → finalized=true, no captureId');

section('map_poll_state — draft surfaces as a "not yet captured" state, no result leaked');
$md = $S::map_poll_state(array('status' => 'draft', 'result_json' => '{"eyes":[{"eye":"L"}]}'));
ok($md['state'] === 'draft' && !isset($md['result']), 'draft → state=draft and does NOT expose the result');

section('circuit breaker — pure decision + transitions (shared MySQL-row backed)');
// closed: allow
$st = array('state' => 'closed', 'failures' => 0, 'opened_at' => 0);
eq($S::breaker_decide($st, $now)['allow'], true, 'closed → allow');
// failure increments; opens at threshold 5
$st = $S::breaker_on_failure($st, $now);            // 1
$st = $S::breaker_on_failure($st, $now);            // 2
$st = $S::breaker_on_failure($st, $now);            // 3
$st = $S::breaker_on_failure($st, $now);            // 4
eq($st['state'], 'closed', '4 consecutive failures stays closed');
$st = $S::breaker_on_failure($st, $now);            // 5 → open
eq($st['state'], 'open', '5th consecutive failure opens the breaker');
$d = $S::breaker_decide($st, $now);
eq($d['allow'], false, 'open → short-circuit (no network call)');
// still open within cooldown (60s)
$d = $S::breaker_decide($st, $now + 30000);
eq($d['allow'], false, 'open within 60s cooldown → still short-circuit');
// half-open after cooldown: allow ONE trial
$d = $S::breaker_decide($st, $now + 61000);
eq($d['allow'], true, 'after 60s cooldown → half-open allows a trial');
eq($d['probe'], true, 'the post-cooldown allow is flagged as a probe (half-open)');
// success resets to closed
$st2 = $S::breaker_on_success($st);
eq($st2['state'], 'closed', 'success resets to closed');
eq($st2['failures'], 0, 'success zeroes the failure counter');

// ─────────────────────────────────────────────────────────────────────────
//  CONTRACT CHANGE 1 — create-checkout-simple may now answer ALREADY_SUBSCRIBED
//  (200 { alreadySubscribed:true, code:'ALREADY_SUBSCRIBED' }) instead of a
//  checkout url, when the practitioner already has an active IrisMapper sub.
//  parse_checkout_response normalises BOTH wire shapes so the caller can branch
//  without a fragile isset($r['url']) check that would 502 a subscriber.
// ─────────────────────────────────────────────────────────────────────────
section('parse_checkout_response (url | ALREADY_SUBSCRIBED | bad)');
$r = $S::parse_checkout_response(array('url' => 'https://checkout.stripe.com/c/sess_123'));
ok($r['ok'] === true && empty($r['alreadySubscribed']) && $r['url'] === 'https://checkout.stripe.com/c/sess_123', 'url response → ok, not alreadySubscribed, carries the url');
$r = $S::parse_checkout_response(array('alreadySubscribed' => true, 'code' => 'ALREADY_SUBSCRIBED'));
ok($r['ok'] === true && $r['alreadySubscribed'] === true && !isset($r['url']), 'alreadySubscribed:true → ok + alreadySubscribed, no url');
$r = $S::parse_checkout_response(array('code' => 'ALREADY_SUBSCRIBED'));
ok($r['ok'] === true && $r['alreadySubscribed'] === true, 'code ALREADY_SUBSCRIBED alone → alreadySubscribed (robust to a missing boolean)');
$r = $S::parse_checkout_response(array('alreadySubscribed' => true, 'url' => 'https://x'));
ok($r['ok'] === true && $r['alreadySubscribed'] === true && !isset($r['url']), 'alreadySubscribed wins even if a url is also present (never redirect a subscriber)');
$r = $S::parse_checkout_response(array());
ok($r['ok'] === false, 'empty response → not ok (no url, not subscribed)');
$r = $S::parse_checkout_response('not-an-array');
ok($r['ok'] === false, 'non-array → not ok');
$r = $S::parse_checkout_response(array('url' => ''));
ok($r['ok'] === false, 'empty url string → not ok');

// ─────────────────────────────────────────────────────────────────────────
//  CONTRACT CHANGE 2 — iris-analyse-status now requires the owning practitioner
//  email (ownership). build_status_query is the pure query builder the reconcile
//  cron uses so the email it already knows per job rides along.
// ─────────────────────────────────────────────────────────────────────────
section('build_status_query (reconcile poll carries the owner email)');
eq($S::build_status_query('118:105:abc', 'prac@example.com'), array('jobId' => '118:105:abc', 'email' => 'prac@example.com'), 'includes jobId + owner email');
eq($S::build_status_query('118:105:abc', ''), array('jobId' => '118:105:abc'), 'omits email when none is known (graceful)');

// ─── summary ───
echo "\n────────────────────────────────────\n";
echo "  tests: {$GLOBALS['__tests']}   failures: {$GLOBALS['__fail']}\n";
exit($GLOBALS['__fail'] === 0 ? 0 : 1);
