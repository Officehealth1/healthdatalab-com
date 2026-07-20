/**
 * Idle-poll gating tests (2026-07-14 rate-limit fix) — hdlv2-client-list-enhance.js.
 *
 * The bug (idle-dashboard 429 RCA, 2026-07-13): the 60-second fallback timer
 * called pollNow() UNCONDITIONALLY — a full /dashboard/clients +
 * /widget/leads/pending fetch pair every minute per tab (120 counted
 * reads/hour/tab) even when the /dashboard/version digest said nothing
 * changed. >=2 idle tabs exhausted the practitioner's shared 200/hr read
 * bucket and the dashboard self-429'd while idle.
 *
 * The fix under test (REAL functions evaluated from source, not copies):
 *  (I1) a fallback tick with FRESH data (digest unchanged, <5 min old)
 *       makes ZERO full-fetch calls;
 *  (I2) digest unchanged on the 4s poll → no full fetch (regression guard);
 *  (I3) digest ADVANCED → exactly one full-fetch pair (realtime kept);
 *  (I4) a fallback tick with data >5 min stale → one full-fetch pair
 *       (safety net kept);
 *  (I5) while window.hdlv2RateLimit.isCoolingDown() (a 429 Retry-After
 *       cooldown is active) BOTH the digest poll and the fallback tick are
 *       skipped entirely — no hammering through a block;
 *  (I6) the digest poll marks itself as background traffic
 *       (X-HDLV2-Bg header) so the global 429 handler can suppress the
 *       modal for it;
 *  (I7) pollNow() with a null roster result (429/error) does NOT
 *       reconcile/render — a blocked poll never wipes the visible roster;
 *  (I8) the REAL fetchV2Clients/fetchPendingLeads send the bg marker and
 *       resolve null (not []) on a non-ok response;
 *  (I9) review W1 — a blocked (null) FIRST-load roster in init() renders
 *       nothing (no empty-list render) and does NOT stamp freshness, so the
 *       next 60s fallback tick retries immediately (pre-fix 60s recovery,
 *       not a 5-minute stall).
 *
 * Run:  node tests/rl-idle-poll/scenario-idle-poll.mjs   (exit 0/1)
 */
import { readFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const here = dirname(fileURLToPath(import.meta.url));
const src = readFileSync(join(here, '../../assets/js/hdlv2-client-list-enhance.js'), 'utf8');

let pass = 0, fail = 0;
function ok(name, cond, detail) {
  console.log((cond ? 'PASS' : 'FAIL') + ' | ' + name + (cond ? '' : ' | ' + (detail || '')));
  cond ? pass++ : fail++;
}

// ── Slice the REAL poll region out of the module: from the two-tier polling
//    comment through the end of pollNow(). Contains the poll constants +
//    module vars + init/startPolling/stopPolling/pollVersion/pollNow (and any
//    helpers the fix adds in that region). init() is never called here.
function sliceModule() {
  const start = src.indexOf('// v0.21.0 — Two-tier polling');
  if (start === -1) throw new Error('poll-region marker not found');
  const pn = src.indexOf('function pollNow()', start);
  if (pn === -1) throw new Error('pollNow not found');
  const open = src.indexOf('{', pn);
  let depth = 0;
  for (let i = open; i < src.length; i++) {
    if (src[i] === '{') depth++;
    else if (src[i] === '}') { depth--; if (depth === 0) return src.slice(start, i + 1); }
  }
  throw new Error('unbalanced braces slicing poll region');
}

function extract(name) {
  const marker = 'function ' + name + '(';
  const idx = src.indexOf(marker);
  if (idx === -1) throw new Error('function ' + name + ' not found');
  const open = src.indexOf('{', idx);
  let depth = 0;
  for (let i = open; i < src.length; i++) {
    if (src[i] === '{') depth++;
    else if (src[i] === '}') { depth--; if (depth === 0) return src.slice(idx, i + 1); }
  }
  throw new Error('unbalanced braces extracting ' + name);
}

// ── Controllable environment ──
function makeEnv() {
  const env = {
    now: 10_000_000,
    digestV: 100,
    digestFetches: [],       // {url, opts}
    fullFetches: 0,          // fetchV2Clients+fetchPendingLeads pair count (each stub call counts .5)
    v2ClientsResult: [{ user_id: 1, email_hash: 'h' }],
    reconcileCalls: 0,
    renderQueueCalls: 0,
    coolingDown: false,
    intervals: [],           // {cb, ms}
  };

  const DateStub = { now() { return env.now; } };
  const fetchStub = function (url, opts) {
    if (String(url).indexOf('/dashboard/version') !== -1) {
      env.digestFetches.push({ url: String(url), opts: opts || {} });
      return Promise.resolve({ ok: true, json() { return Promise.resolve({ v: env.digestV }); } });
    }
    throw new Error('unexpected fetch in slice: ' + url);
  };
  const fetchV2ClientsStub = function () { env.fullFetches += 0.5; return Promise.resolve(env.v2ClientsResult); };
  const fetchPendingLeadsStub = function () { env.fullFetches += 0.5; return Promise.resolve([]); };
  const setIntervalStub = function (cb, ms) { env.intervals.push({ cb, ms }); return env.intervals.length; };
  const windowStub = {
    hdlv2RateLimit: {
      isCoolingDown() { return env.coolingDown; },
    },
    addEventListener() {},
  };

  const state = { clients: [], pendingLeads: [], byHash: {} };
  const CFG = { api_base: 'https://stby.example/wp-json/hdl-v2/v1', nonce: 'test-nonce' };

  const code = sliceModule();
  const factory = new Function(
    'state', 'CFG', 'fetch', 'Date', 'setInterval', 'clearInterval', 'window', 'document',
    'fetchV2Clients', 'fetchPendingLeads', 'reconcileRows', 'renderActionQueue',
    'maybeRefreshActiveFlightPlanTabs', 'maybeRefreshActiveReportTabs',
    'updateFreshnessIndicator', 'handleReleaseDeepLink', 'startFreshnessTicker',
    'injectStyles', 'mountActionQueueShell', 'mountIrisCard', 'bindDeleteV2Client',
    'enhanceMatchedRows', 'appendV2OnlyRows', 'defaultSortByLatestActivity',
    'decorateActionIcons', 'bindResendLink', 'bindProgressOpen', 'bindMessageIntegration',
    code + '\nreturn { startPolling: startPolling, stopPolling: stopPolling, pollVersion: pollVersion, pollNow: pollNow, init: init };'
  );
  const noop = function () {};
  env.api = factory(
    state, CFG, fetchStub, DateStub, setIntervalStub, noop, windowStub, { addEventListener: noop },
    fetchV2ClientsStub, fetchPendingLeadsStub,
    function () { env.reconcileCalls++; }, function () { env.renderQueueCalls++; },
    noop, noop, noop, noop, noop,
    noop, noop, noop, noop, noop, noop, noop, noop, noop, noop, noop
  );
  return env;
}

const flush = () => new Promise((r) => setTimeout(r, 0));

// ═══ Build env, identify timers ═══
let env;
try {
  env = makeEnv();
} catch (e) {
  console.log('FAIL | slice/eval | ' + e.message);
  console.log('\n0 passed, 1 failed');
  process.exit(1);
}

env.api.startPolling();
const versionTick = (env.intervals.find((t) => t.ms === 4000) || {}).cb;
const fallbackTick = (env.intervals.find((t) => t.ms === 60000) || {}).cb;
ok('(I0) startPolling registers a 4s digest timer and a 60s fallback timer', !!versionTick && !!fallbackTick,
  'intervals=' + JSON.stringify(env.intervals.map((t) => t.ms)));

// Prime: initial full fetch (page load) + digest baseline.
await env.api.pollNow();
await flush();
env.api.pollVersion();
await flush();
const baselineFull = env.fullFetches;
ok('(I0b) prime: one full-fetch pair on load', baselineFull === 1, 'fullFetches=' + baselineFull);

// ═══ I1 — idle fallback tick with FRESH data → zero full fetches ═══
env.now += 61_000; // 61s later: digest unchanged, data 61s old (fresh, <5min)
if (fallbackTick) fallbackTick();
await flush();
ok('(I1) fallback tick with fresh data makes NO full fetch', env.fullFetches === baselineFull,
  'fullFetches=' + env.fullFetches + ' expected=' + baselineFull);

// ═══ I2 — digest unchanged on the 4s poll → no full fetch ═══
if (versionTick) versionTick();
await flush();
ok('(I2) digest unchanged → no full fetch', env.fullFetches === baselineFull, 'fullFetches=' + env.fullFetches);

// ═══ I3 — digest ADVANCED → exactly one full-fetch pair ═══
env.digestV = 101;
if (versionTick) versionTick();
await flush();
ok('(I3) digest advance → exactly one full-fetch pair', env.fullFetches === baselineFull + 1,
  'fullFetches=' + env.fullFetches);

// ═══ I4 — staleness safety net: >5 min since last fetch → one pair ═══
env.now += 5 * 60_000 + 61_000;
if (fallbackTick) fallbackTick();
await flush();
ok('(I4) fallback tick with >5min-stale data DOES full-fetch (safety net)', env.fullFetches === baselineFull + 2,
  'fullFetches=' + env.fullFetches);

// ═══ I5 — cooldown: both pollers stop while a 429 Retry-After is active ═══
env.coolingDown = true;
env.now += 5 * 60_000 + 61_000; // stale again — would normally trigger
const digestCountBefore = env.digestFetches.length;
if (fallbackTick) fallbackTick();
if (versionTick) versionTick();
await flush();
ok('(I5a) cooling down → fallback tick makes no full fetch', env.fullFetches === baselineFull + 2,
  'fullFetches=' + env.fullFetches);
ok('(I5b) cooling down → digest poll skipped entirely', env.digestFetches.length === digestCountBefore,
  'digestFetches=' + env.digestFetches.length + ' before=' + digestCountBefore);
env.coolingDown = false;

// ═══ I6 — digest poll marks itself as background traffic ═══
if (versionTick) versionTick();
await flush();
const lastDigest = env.digestFetches[env.digestFetches.length - 1];
const bgHeader = lastDigest && lastDigest.opts.headers && lastDigest.opts.headers['X-HDLV2-Bg'];
ok('(I6) digest poll sends X-HDLV2-Bg: 1', bgHeader === '1', 'headers=' + JSON.stringify(lastDigest && lastDigest.opts.headers));

// ═══ I7 — a blocked (null) roster fetch never wipes the rendered roster ═══
env.v2ClientsResult = null; // fetchV2Clients resolves null on 429 after the fix
const reconcileBefore = env.reconcileCalls;
const renderBefore = env.renderQueueCalls;
await env.api.pollNow();
await flush();
ok('(I7) pollNow with null roster result skips reconcile/render', env.reconcileCalls === reconcileBefore && env.renderQueueCalls === renderBefore,
  'reconcile=' + env.reconcileCalls + '/' + reconcileBefore + ' render=' + env.renderQueueCalls + '/' + renderBefore);

// ═══ I8 — REAL fetch helpers: bg marker + null on non-ok ═══
{
  const calls = [];
  const CFG = { api_base: 'https://stby.example/wp-json/hdl-v2/v1', nonce: 'n', rest_nonce: 'n' };
  const fetch429 = function (url, opts) {
    calls.push({ url: String(url), opts: opts || {} });
    return Promise.resolve({ ok: false, status: 429, headers: { get() { return null; } }, json() { return Promise.resolve({}); } });
  };
  let real;
  try {
    const code = [extract('fetchV2Clients'), extract('fetchPendingLeads')].join('\n');
    const f = new Function('CFG', 'fetch', 'window', code + '\nreturn { c: fetchV2Clients, p: fetchPendingLeads };');
    real = f(CFG, fetch429, { hdlv2RateLimit: { isCoolingDown() { return false; } } });
  } catch (e) {
    ok('(I8) extraction of real fetch helpers', false, e.message);
    real = null;
  }
  if (real) {
    const rc = await real.c();
    const rp = await real.p();
    const hdrs = calls.map((c) => (c.opts.headers || {})['X-HDLV2-Bg']);
    ok('(I8a) fetchV2Clients + fetchPendingLeads send X-HDLV2-Bg: 1', hdrs.length === 2 && hdrs.every((h) => h === '1'),
      'bg headers=' + JSON.stringify(hdrs));
    ok('(I8b) both resolve null (not []) on a non-ok response', rc === null && rp === null,
      'clients=' + JSON.stringify(rc) + ' pending=' + JSON.stringify(rp));
  }
}

// ═══ I9 — review W1: blocked first load renders nothing + recovers in 60s ═══
{
  let env2;
  try {
    env2 = makeEnv();
  } catch (e) {
    ok('(I9) fresh env for init test', false, e.message);
    env2 = null;
  }
  if (env2) {
    env2.v2ClientsResult = null; // first roster read is blocked (429)
    env2.api.init();
    await flush(); await flush();
    ok('(I9a) init with blocked roster does NOT render an empty V2 layer', env2.renderQueueCalls === 0,
      'renderQueueCalls=' + env2.renderQueueCalls);
    const fetchesAfterInit = env2.fullFetches;
    // Recovery: server unblocks; the next 60s fallback tick must refetch
    // immediately (freshness was never stamped), not wait out a 5-min net.
    env2.v2ClientsResult = [{ user_id: 1, email_hash: 'h' }];
    env2.now += 61_000;
    const fb = (env2.intervals.find((t) => t.ms === 60000) || {}).cb;
    if (fb) fb();
    await flush(); await flush();
    ok('(I9b) next 60s fallback tick refetches (60s recovery, not 5min)', env2.fullFetches === fetchesAfterInit + 1,
      'fullFetches=' + env2.fullFetches + ' afterInit=' + fetchesAfterInit);
    ok('(I9c) recovered roster renders', env2.renderQueueCalls === 1, 'renderQueueCalls=' + env2.renderQueueCalls);
  }
}

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail > 0 ? 1 : 0);
