#!/usr/bin/env node
/**
 * Skeletons everywhere (v0.47.95) — no spinner, no bare "Loading…" text.
 *
 * Every wait a practitioner or client sees is drawn by the shared helper
 * (window.HDLV2Loading): a shaped skeleton, plus the progress ladder for
 * long AI waits. This suite pins that:
 *   1. Source scan of assets/js + assets/css: no spinner class, no rotating
 *      keyframes, no innerHTML that is only "Loading…" / "Preparing…".
 *   2. The REAL hdlv2-loading.js, run in a sandbox: all ten skeleton kinds
 *      carry aria-busy="true"; an unknown kind falls back to 'report'; the
 *      progress ladder stops itself once its node leaves the page.
 *   3. PHP scan: every enqueue of a script that draws a loader lists
 *      'hdlv2-loading' as a dependency, and its file loads 'hdlv2-loading-css'.
 *   4. Not loaders, left alone: the 429 modal and the status words.
 *
 * Run:  node tests/skeleton-loading/test-no-spinners.js
 * Exit: 0 all pass · 1 any fail
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }

const ROOT = path.join(__dirname, '..', '..');
const read = (rel) => fs.readFileSync(path.join(ROOT, rel), 'utf8');
const list = (dir, ext) => fs.readdirSync(path.join(ROOT, dir))
  .filter((f) => f.endsWith(ext) && !f.startsWith('._'))
  .map((f) => dir + '/' + f);

// ── 1. Source scan ──
const assetFiles = list('assets/js', '.js').concat(list('assets/css', '.css'));
const BANNED = [/spinner/i, /fp-spin/, /hdlv2IrisSpin/, /hdlv2-fn-spin/, /hdlv2-pd-spin/, /hdlv2spin/,
  /hdlvircspin/, /hdlv2-iris-spin/, /hdlv2-irc-spin/, /hdlv2-fn-ring/];
const BARE = /(innerHTML\s*=\s*'|>)\s*(Loading|Preparing)(…|\\u2026|\.\.\.|&hellip;)\s*(<|';)/;

assetFiles.forEach((f) => {
  const src = read(f);
  const hits = BANNED.filter((re) => re.test(src)).map(String);
  ok(hits.length === 0, f + ' — no spinner names' + (hits.length ? ' (found ' + hits.join(', ') + ')' : ''));
  ok(!/rotate\(\s*360deg\s*\)/.test(src), f + ' — no rotating keyframes');
  const bare = src.split('\n').filter((l) => BARE.test(l));
  ok(bare.length === 0, f + ' — no bare loading text' + (bare.length ? ': ' + bare[0].trim().slice(0, 90) : ''));
});

// ── 2. The real helper ──
function loadHelper() {
  const win = {};
  vm.runInNewContext(read('assets/js/hdlv2-loading.js'), { window: win, setInterval, clearInterval });
  return win.HDLV2Loading;
}
const L = loadHelper();
const KINDS = ['report', 'flightPlan', 'consultation', 'dashboard', 'checkin', 'form', 'brief', 'tab', 'list', 'inline'];
KINDS.forEach((k) => {
  const html = L.skeleton(k);
  ok(/aria-busy="true"/.test(html) && /aria-label="[^"]+"/.test(html), "skeleton('" + k + "') — aria-busy + aria-label");
});
ok(L.skeleton('nope') === L.skeleton('report'), 'unknown kind falls back to report');
// v0.47.98 — a label on a plain div is not read out; role="status" is.
KINDS.filter((k) => k !== 'inline').forEach((k) => {
  ok(/<div class="hdlv2-skel-container[^"]*" role="status" aria-busy="true"/.test(L.skeleton(k)),
    "skeleton('" + k + "') — the container has role=\"status\"");
});
KINDS.filter((k) => k !== 'inline').forEach((k) => {
  const label = (L.skeleton(k).match(/role="status" aria-busy="true" aria-label="([^"]+)"[^>]*><span style="position:absolute;[^"]*">([^<]+)<\/span>/) || []);
  ok(label[1] && label[1] === label[2], "skeleton('" + k + "') — the status region holds its label as hidden text (announced)");
});
ok(!/role="status"/.test(L.skeleton('inline')), "skeleton('inline') stays a plain span (it sits inside buttons, beside words)");
ok(/^<span\b/.test(L.skeleton('inline')) && !/<div\b/.test(L.skeleton('inline')), "skeleton('inline') is a span (valid inside a button)");
ok(new Set(KINDS.map((k) => L.skeleton(k))).size === KINDS.length, 'ten distinct shapes');

// progress() stops its timers once its node is detached (no leaked intervals
// when a caller replaces the container's innerHTML without calling cancel()).
(function () {
  const timers = new Map(); let seq = 0;
  const fakeSet = (fn) => { const id = ++seq; timers.set(id, fn); return id; };
  const fakeClear = (id) => { timers.delete(id); };
  const win = {};
  vm.runInNewContext(read('assets/js/hdlv2-loading.js'), { window: win, setInterval: fakeSet, clearInterval: fakeClear });
  const fill = { style: {} };
  const step = { firstChild: null, textContent: '', classList: { add() {} } };
  const wrap = { isConnected: true, querySelector: (s) => (s === '.hdlv2-progress-fill' ? fill : step) };
  const container = { insertAdjacentHTML() {}, querySelector: () => wrap, querySelectorAll: () => [wrap] };
  win.HDLV2Loading.progress(container, { steps: ['a', 'b'] });
  ok(timers.size === 2, 'progress() starts its two timers');
  [...timers.values()].forEach((fn) => fn());
  ok(timers.size === 2, 'timers keep running while attached');
  wrap.isConnected = false;
  [...timers.values()].forEach((fn) => fn());
  ok(timers.size === 0, 'timers stop once the node is detached');
})();

// ── 3. Enqueues ──
const LOADER_HANDLES = ['hdlv2-staged-form', 'hdlv2-consultation', 'hdlv2-checkin', 'hdlv2-flight-plan',
  'hdlv2-client-list-enhance', 'hdlv2-dashboard', 'hdlv2-practitioner-dashboard', 'hdlv2-audio-component',
  'hdlv2-iris-consult'];
function phpFiles(dir) {
  return fs.readdirSync(path.join(ROOT, dir), { withFileTypes: true }).flatMap((d) => {
    if (d.name.startsWith('._')) return [];
    const rel = dir + '/' + d.name;
    return d.isDirectory() ? phpFiles(rel) : (d.name.endsWith('.php') ? [rel] : []);
  });
}
const ENQ = /wp_enqueue_script\(\s*'([\w-]+)'\s*,\s*[^,]+,\s*array\(([^)]*)\)/g;
const seen = new Set();
phpFiles('includes').concat(['hdl-longevity-v2.php']).forEach((f) => {
  const src = read(f);
  let m;
  while ((m = ENQ.exec(src))) {
    if (!LOADER_HANDLES.includes(m[1])) continue;
    seen.add(m[1]);
    ok(/'hdlv2-loading'/.test(m[2]), f + " — '" + m[1] + "' depends on 'hdlv2-loading'");
    ok(/'hdlv2-loading-css'/.test(src), f + " — loads 'hdlv2-loading-css' for '" + m[1] + "'");
  }
});
LOADER_HANDLES.forEach((h) => ok(seen.has(h), "enqueue of '" + h + "' found"));

// v0.47.98 — the PHP the server renders: no line that is only loading words.
const PHP_BARE = />\s*(Loading|Preparing)[^<]{0,40}(…|\.\.\.|&hellip;|\\u2026)\s*</;
phpFiles('includes').concat(['hdl-longevity-v2.php']).forEach((f) => {
  const bare = read(f).split('\n').filter((l) => PHP_BARE.test(l));
  ok(bare.length === 0, f + ' — no rendered loading-only line' + (bare.length ? ': ' + bare[0].trim().slice(0, 90) : ''));
});
const shell = read('includes/sprint-4/class-hdlv2-message-thread-view.php');
ok(shell.indexOf('<div class="hdlv2-inbox-loading" role="status" aria-label="Loading your messages"><span></span><span></span><span></span><span class="hdlv2-inbox-sr">Loading your messages</span></div>') !== -1,
  'Messages placeholder is three bars plus a hidden line a screen reader announces');
ok(/\.hdlv2-inbox-loading \.hdlv2-inbox-sr \{[^}]*clip: rect\(0, 0, 0, 0\)/.test(read('assets/css/hdlv2-client-dashboard.css')), 'that line is visually hidden (and outranks the bar rule)');
const cdCss = read('assets/css/hdlv2-client-dashboard.css');
ok(/\.hdlv2-inbox-loading \{/.test(cdCss) && !/\.hdlv2-inbox-loading,\s*\.hdlv2-inbox-empty/.test(cdCss), 'the bars have their own rule, split from the empty line');
ok(/@media \(prefers-reduced-motion: reduce\) \{[^}]*\.hdlv2-inbox-loading span \{ animation: none; \}/.test(cdCss), 'the bars stop pulsing for reduced motion');
const myReport = read('includes/sprint-2c/class-hdlv2-my-report.php');
ok(myReport.indexOf('Your report is on its own page.') !== -1 && myReport.indexOf('>Open your report</a>') !== -1, 'report fall-back: a plain line and an "Open your report" link');

// ── 4. Not loaders: untouched ──
ok(/'Give us a moment'/.test(read('assets/js/hdlv2-rate-limit.js')), '429 modal title unchanged');
const cle = read('assets/js/hdlv2-client-list-enhance.js');
ok(/awaiting_consult: \{ dot: '#3b82f6', label: 'Full Report · form: ready to record consultation'/.test(cle), 'status word "Full Report · form: ready to record consultation" unchanged (v0.48.21 wording)');
ok(/awaiting_why_release/.test(cle), 'status awaiting_why_release unchanged');
ok(/PDF preparing/.test(read('assets/js/hdlv2-flight-plan.js')), '"PDF preparing" button label unchanged');

console.log('\n' + PASS + ' passed, ' + FAIL + ' failed');
process.exit(FAIL ? 1 : 0);
