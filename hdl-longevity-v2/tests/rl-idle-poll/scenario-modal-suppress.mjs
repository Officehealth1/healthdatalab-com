/**
 * Background-429 modal suppression + cooldown API tests (2026-07-14 fix) —
 * hdlv2-rate-limit.js, whole file evaluated with a minimal DOM stub.
 *
 * The bug (idle-dashboard 429 RCA, 2026-07-13): the v0.20.0 global fetch
 * wrapper shows the "Give us a moment" modal for EVERY 429 on /hdl-v2/v1/,
 * including the dashboard's own background polls — so an idle practitioner
 * gets a blocking modal from traffic they never initiated. And nothing
 * records the Retry-After, so pollers hammer straight through the block
 * (114 wasted calls on LIVE, 2026-07-13).
 *
 * The fix under test:
 *  (M1) a 429 on a request marked X-HDLV2-Bg (background poll) shows NO
 *       modal — the discreet pill still updates from the 429's
 *       X-RateLimit-* headers;
 *  (M2) the same 429 arms a shared cooldown: hdlv2RateLimit.isCoolingDown()
 *       is true and clears only after Retry-After elapses;
 *  (M3) a 429 on a genuine user action (no bg marker) STILL shows the
 *       modal (regression guard);
 *  (M4) loadStatus() sends X-WP-Nonce when window.hdlv2RateLimitCfg.nonce
 *       is provided — so the status probe is counted against the logged-in
 *       user's bucket, not an anonymous IP bucket.
 *
 * Run:  node tests/rl-idle-poll/scenario-modal-suppress.mjs   (exit 0/1)
 */
import { readFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const here = dirname(fileURLToPath(import.meta.url));
const src = readFileSync(join(here, '../../assets/js/hdlv2-rate-limit.js'), 'utf8');

let pass = 0, fail = 0;
function ok(name, cond, detail) {
  console.log((cond ? 'PASS' : 'FAIL') + ' | ' + name + (cond ? '' : ' | ' + (detail || '')));
  cond ? pass++ : fail++;
}

// ── Minimal DOM stub: elements register by id when appended anywhere ──
const byId = Object.create(null);
function makeEl(tag) {
  const el = {
    tag, children: [], style: {}, attrs: {},
    _id: '',
    get id() { return this._id; },
    set id(v) { this._id = v; },
    className: '', textContent: '', innerHTML: '',
    setAttribute(k, v) { this.attrs[k] = v; },
    getAttribute(k) { return this.attrs[k] || null; },
    appendChild(c) { this.children.push(c); register(c); return c; },
    removeChild(c) { this.children = this.children.filter((x) => x !== c); if (c._id) delete byId[c._id]; return c; },
    addEventListener() {}, removeEventListener() {},
    get parentNode() { return this._parent || null; },
  };
  return el;
}
function register(el) {
  if (el._id) byId[el._id] = el;
  (el.children || []).forEach(register);
}
const documentStub = {
  readyState: 'complete',
  head: makeEl('head'),
  body: makeEl('body'),
  createElement: (t) => makeEl(t),
  createTextNode: (t) => ({ text: t }),
  getElementById: (id) => byId[id] || null,
  addEventListener() {}, removeEventListener() {},
};
// createTextNode consumers call appendChild(textnode) — fine, no id.

const statusCalls = [];
let nextResponse = null; // when set, the underlying fetch serves this once
const fetchStub = function (url, opts) {
  statusCalls.push({ url: String(url), opts: opts || {} });
  if (nextResponse) { const r = nextResponse; nextResponse = null; return Promise.resolve(r); }
  return Promise.resolve({ ok: false, status: 503, headers: { get() { return null; } }, json() { return Promise.resolve(null); } });
};

let nowMs = 50_000_000;
const DateStub = { now() { return nowMs; } };

const windowStub = {
  fetch: fetchStub,
  hdlv2RateLimitCfg: { nonce: 'rest-nonce-xyz' },
  crypto: undefined,
};

// ── Evaluate the REAL file ──
try {
  const run = new Function('window', 'document', 'fetch', 'Date', 'setInterval', 'clearInterval', 'setTimeout', src);
  run(windowStub, documentStub, fetchStub, DateStub, () => 1, () => {}, (cb) => cb());
} catch (e) {
  console.log('FAIL | evaluate hdlv2-rate-limit.js | ' + e.message);
  console.log('\n0 passed, 1 failed');
  process.exit(1);
}

const RL = windowStub.hdlv2RateLimit;
const wrapped = windowStub.fetch;
ok('(M0) module exposes hdlv2RateLimit and wraps window.fetch', !!RL && wrapped !== fetchStub, 'RL=' + !!RL);

function resp429(retryAfter) {
  const headers = {
    map: {
      'Retry-After': String(retryAfter),
      'X-RateLimit-Limit': '200',
      'X-RateLimit-Remaining': '0',
      'X-RateLimit-Reset': String(Math.floor(nowMs / 1000) + retryAfter),
      'X-RateLimit-Tier': 'read',
    },
    get(k) { return this.map[k] || null; },
  };
  return {
    ok: false, status: 429, headers,
    clone() { return this; },
    json() { return Promise.resolve({ code: 'rate_limit_exceeded', retry_after: retryAfter, tier: 'read' }); },
  };
}

const flush = () => new Promise((r) => setTimeout(r, 0));

// ═══ M1 — background 429: NO modal, pill updates ═══
nextResponse = resp429(310);
await wrapped('/wp-json/hdl-v2/v1/dashboard/clients', { headers: { 'X-HDLV2-Bg': '1', 'X-WP-Nonce': 'n' } });
await flush(); await flush();
ok('(M1a) bg 429 → no "Give us a moment" modal', !byId['hdlv2-rl-overlay'], 'overlay present');
const pill = byId['hdlv2-rl-pill'];
ok('(M1b) bg 429 → pill visible with tier label', !!pill && pill.style.display === 'block' && /left this hour/.test(pill.textContent),
  'pill=' + (pill ? pill.style.display + ' "' + pill.textContent + '"' : 'missing'));

// ═══ M2 — cooldown armed for Retry-After seconds ═══
ok('(M2a) isCoolingDown() true right after a 429', typeof RL.isCoolingDown === 'function' && RL.isCoolingDown() === true,
  'isCoolingDown=' + (RL.isCoolingDown ? RL.isCoolingDown() : 'undefined'));
nowMs += 311_000; // Retry-After was 310s
ok('(M2b) cooldown clears after Retry-After elapses', typeof RL.isCoolingDown === 'function' && RL.isCoolingDown() === false,
  'isCoolingDown=' + (RL.isCoolingDown ? RL.isCoolingDown() : 'undefined'));

// ═══ M3 — user-action 429 still shows the modal ═══
nextResponse = resp429(120);
await wrapped('/wp-json/hdl-v2/v1/consultation/organise', { method: 'POST', headers: { 'X-WP-Nonce': 'n' } });
await flush(); await flush();
ok('(M3) non-bg 429 → modal shown', !!byId['hdlv2-rl-overlay'], 'overlay missing');

// ═══ M4 — loadStatus sends the localized nonce ═══
const statusCall = statusCalls.find((c) => c.url.indexOf('rate-limit/status') !== -1);
const nonceSent = statusCall && statusCall.opts.headers && statusCall.opts.headers['X-WP-Nonce'];
ok('(M4) loadStatus sends X-WP-Nonce from hdlv2RateLimitCfg', nonceSent === 'rest-nonce-xyz',
  'headers=' + JSON.stringify(statusCall && statusCall.opts.headers));

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail > 0 ? 1 : 0);
