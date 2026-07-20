#!/usr/bin/env node
/**
 * Report-preparing rotating copy (v0.47.79) — behavioural + structural tests.
 *
 * The preparing card (showReportPreparing) gains a self-terminating message
 * ticker: 5 roughly-ordered reassurance lines at 7s, settling on a steady
 * line that never loops. The poll (pollReportJob) loses its status-line write
 * so the ticker is the SINGLE writer.
 *
 * Unlike the finalise-autosave-guard harness (which models the state machine),
 * the behavioural tests here EXTRACT AND EXECUTE the real ticker source from
 * assets/js/hdlv2-consultation.js under fake timers — so "self-terminating",
 * "teardown-safe" and "stale ticker can't hijack a new screen" are proven
 * against the shipped code, not a copy.
 *
 * Structural tests assert the safety invariants of the surrounding file:
 *   - no setInterval anywhere (the file's own stated idiom)
 *   - the poll's pending branch no longer writes the status line
 *   - setReportPrepStatus (old single caller removed) is gone
 *   - showReportPreparing starts the ticker; aria-live=polite retained
 *   - HDLV2_VERSION bumped (cache-bust) with header/constant in sync
 *
 * Run:  node tests/report-prep-ticker/test-report-prep-ticker.js
 * Exit: 0 all pass · 1 any fail
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }

const JS_PATH = path.join(__dirname, '..', '..', 'assets', 'js', 'hdlv2-consultation.js');
const PHP_PATH = path.join(__dirname, '..', '..', 'hdl-longevity-v2.php');
const src = fs.readFileSync(JS_PATH, 'utf8');
const phpSrc = fs.readFileSync(PHP_PATH, 'utf8');

// ── Fake timer world (same shape as the finalise-autosave-guard harness) ──
function makeWorld() {
  const timers = new Map(); let seq = 1, now = 0;
  return {
    now: () => now,
    pendingCount: () => timers.size,
    setTimeout(fn, ms) { const id = seq++; timers.set(id, { fn, at: now + ms }); return id; },
    advance(ms) {
      const end = now + ms;
      for (;;) {
        let next = null;
        for (const [id, t] of timers) if (t.at <= end && (!next || t.at < next.at)) next = { id, ...t };
        if (!next) break;
        now = next.at; timers.delete(next.id); next.fn();
      }
      now = end;
    },
  };
}

// ── Extract the REAL ticker + message ladder from the shipped file ──
// Evaluated via vm in a bare sandbox. Trust boundary: the evaluated text is
// first-party source from this repo (same trust as require()-ing it), run
// only by a developer invoking this harness — no external/user input ever
// reaches the sandbox.
function extractTicker() {
  const msgM = src.match(/var REPORT_PREP_MESSAGES = \[[\s\S]*?\];/);
  const stepM = src.match(/var REPORT_PREP_STEP_MS = (\d+);/);
  const fnM = src.match(/  function startReportPrepTicker\(el\) \{[\s\S]*?\n  \}/);
  if (!msgM || !stepM || !fnM) return null;
  const messages = vm.runInNewContext(msgM[0] + '\nREPORT_PREP_MESSAGES;', {});
  const stepMs = parseInt(stepM[1], 10);
  return {
    messages, stepMs,
    bind: (w) => vm.runInNewContext(
      fnM[0] + '\nstartReportPrepTicker;',
      { setTimeout: w.setTimeout.bind(w), REPORT_PREP_MESSAGES: messages, REPORT_PREP_STEP_MS: stepMs }
    ),
  };
}

// Fake status node. Records every textContent write with a timestamp.
function makeNode(w) {
  const writes = [];
  return {
    isConnected: true,
    _tc: '',
    get textContent() { return this._tc; },
    set textContent(v) { this._tc = v; writes.push({ at: w.now(), msg: v }); },
    writes,
  };
}

console.log('── Structural invariants (real file) ──');
ok(!/setInterval\s*\(/.test(src), 'no setInterval anywhere in hdlv2-consultation.js');
ok(!/function setReportPrepStatus/.test(src), 'old setReportPrepStatus helper removed');
{
  const pollM = src.match(/function pollReportJob[\s\S]*?\n  function /);
  const pollBody = pollM ? pollM[0] : '';
  ok(pollM !== null, 'pollReportJob found');
  ok(pollBody && !/almost there/.test(pollBody), 'poll pending branch: "almost there" write gone');
  ok(pollBody && !/hdlv2-rp-status|setReportPrepStatus|Generating the report/.test(pollBody),
     'poll never touches the status line (single-writer rule)');
}
{
  const prepM = src.match(/function showReportPreparing[\s\S]*?\n  \}/);
  const prepBody = prepM ? prepM[0] : '';
  ok(/startReportPrepTicker\(\s*document\.getElementById\('hdlv2-rp-status'\)\s*\)/.test(prepBody),
     'showReportPreparing starts the ticker on the freshly rendered node');
  ok(/role="status" aria-live="polite"/.test(prepBody), 'status line keeps role=status aria-live=polite');
  ok(/REPORT_PREP_MESSAGES\[0\]/.test(prepBody), 'initial rendered text is ladder line 0');
}
ok(/define\(\s*'HDLV2_VERSION',\s*'0\.47\.79'\s*\)/.test(phpSrc), 'HDLV2_VERSION bumped to 0.47.79 (cache-bust)');
ok(/\* Version: 0\.47\.79/.test(phpSrc), 'plugin header Version in sync with constant (drift resolved)');

console.log('── Ladder content ──');
const T = extractTicker();
ok(T !== null, 'ticker + ladder extracted from real source');
if (T) {
  ok(T.messages.length === 6, '6 lines: 5 rotating + 1 steady');
  ok(T.stepMs === 7000, 'rotation cadence 7000ms');
  ok(T.messages[0] === 'Reviewing the consultation notes…', 'line 0 as approved');
  ok(T.messages[5] === 'Still working — this can take a minute or two.', 'steady line = approved SHORT variant');
  ok(T.messages.every(m => !/\d+\s*%|per\s*cent/i.test(m)), 'no fake percentages in any line');
  ok(T.messages.every(m => !/\b(done|complete|finished)\b/i.test(m)), 'no line claims a step has completed');
}

console.log('── Behaviour: full ladder + self-termination (real source, fake timers) ──');
if (T) {
  const w = makeWorld(); const el = makeNode(w);
  T.bind(w)(el);
  ok(w.pendingCount() === 1, 'exactly one timer armed at start');
  w.advance(7000);
  ok(el.textContent === T.messages[1], 'at 7s: line 1');
  w.advance(7000);
  ok(el.textContent === T.messages[2], 'at 14s: line 2');
  w.advance(21000);
  ok(el.textContent === T.messages[5], 'at 35s: settled on steady line');
  ok(w.pendingCount() === 0, 'SELF-TERMINATED: zero timers pending after steady line (no leak)');
  w.advance(600000);
  ok(el.writes.length === 5, 'exactly 5 writes ever — steady line never loops or rewrites');
}

console.log('── Behaviour: teardown mid-rotation stops cleanly ──');
if (T) {
  const w = makeWorld(); const el = makeNode(w);
  T.bind(w)(el);
  w.advance(15000);              // 2 rotations shown
  el.isConnected = false;        // any exit path: root.innerHTML replaced -> node detached
  let threw = false;
  try { w.advance(600000); } catch (e) { threw = true; }
  ok(!threw, 'late tick on detached node never throws');
  ok(el.writes.length === 2, 'no writes after teardown (guard no-ops)');
  ok(w.pendingCount() === 0, 'timer chain ends after teardown tick (nothing to clear, nothing leaked)');
}

console.log('── Behaviour: stale ticker cannot hijack a re-opened preparing screen ──');
if (T) {
  const w = makeWorld();
  const elA = makeNode(w);
  T.bind(w)(elA);
  w.advance(8000);               // ticker A alive, one rotation in
  elA.isConnected = false;       // screen A torn down…
  const elB = makeNode(w);       // …new preparing screen rendered (same id in real DOM)
  T.bind(w)(elB);
  w.advance(30000);
  ok(elA.writes.length === 1, 'stale ticker A stopped at teardown — captured-node design');
  ok(elB.writes.length >= 3 && elB.writes.every(x => T.messages.includes(x.msg)),
     'fresh ticker B progresses through the ladder untouched by A');
}

console.log('── Behaviour: defensive entry ──');
if (T) {
  const w = makeWorld();
  let threw = false;
  try { T.bind(w)(null); } catch (e) { threw = true; }
  ok(!threw, 'null element: no throw');
  ok(w.pendingCount() === 0, 'null element: no timer armed');
}

console.log('\n' + PASS + ' passed, ' + FAIL + ' failed');
process.exit(FAIL ? 1 : 0);
