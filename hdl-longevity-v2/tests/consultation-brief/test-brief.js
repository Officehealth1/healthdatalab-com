#!/usr/bin/env node
/**
 * Client Brief with no summary yet (v0.47.98, gate S1).
 *
 * Runs the REAL renderClientBrief() and bindRefreshBrief() from
 * assets/js/hdlv2-consultation.js with a fake DOM and fetch: with no brief
 * the box shows a plain line, not a skeleton with nothing loading behind
 * it; Refresh shows the skeleton only while that first summary is written
 * and puts the line back if it fails. With a brief, nothing changes.
 *
 * Run:  node tests/consultation-brief/test-brief.js
 * Exit: 0 all pass · 1 any fail
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }

const ROOT = path.join(__dirname, '..', '..');
const src = fs.readFileSync(path.join(ROOT, 'assets', 'js', 'hdlv2-consultation.js'), 'utf8');
function body(name) {
  const re = new RegExp('  function ' + name + '\\([^)]*\\) \\{(?:[^\\n]*\\}\\n|[\\s\\S]*?\\n  \\}\\n)', 'g');
  const all = src.match(re);
  return all ? all[all.length - 1] : '';
}
function varLine(name) {
  const m = src.match(new RegExp('\\n  var ' + name + ' = [^\\n]*;\\n'));
  return m ? m[0] : '';
}

const LINE = 'The summary could not be written just now. Press Refresh summary to try again.';
const SKEL = '<div class="SKEL-brief"></div>';

function harness(data, reply) {
  const ls = {};
  const bodyEl = { innerHTML: '' };
  const btn = { disabled: false, textContent: 'Refresh summary', addEventListener(t, f) { ls[t] = f; } };
  const meta = { textContent: '' };
  const seen = [];
  const sb = {
    HDLV2Loading: { skeleton: (k) => (k === 'brief' ? SKEL : '') },
    document: {
      getElementById: (id) => ({ 'hdlv2-client-brief-body': bodyEl, 'hdlv2-refresh-brief': btn }[id] || null),
      querySelector: () => meta
    },
    CFG: { api_base: '/x', nonce: 'n' },
    state: { data: data, progressId: 5 },
    briefDirty: false, briefAutoSaveInflight: false, briefAutoSaveTimer: null, briefAutoSavePending: false,
    confirm: () => true,
    setBriefSaveStatus: () => {},
    renderBriefBody: () => '<div class="BRIEF"></div>',
    renderBriefMeta: () => 'meta',
    bindBriefAutoSaveListeners: () => {},
    clearTimeout: () => {},
    fetch: () => { seen.push(bodyEl.innerHTML); return reply(); }
  };
  vm.runInNewContext(varLine('BRIEF_EMPTY') + body('renderClientBrief') + body('bindRefreshBrief'), sb);
  return { sb, bodyEl, btn, click: () => ls.click(), seen };
}
const tick = () => new Promise((r) => setImmediate(r));
const okReply = () => Promise.resolve({ json: () => ({ success: true, pre_consult_summary: { a: 1 } }) });
const badReply = () => Promise.resolve({ json: () => ({ success: false, message: 'nope' }) });
const downReply = () => Promise.reject(new Error('offline'));

(async function () {
  ok(varLine('BRIEF_EMPTY') !== '' && body('renderClientBrief') !== '' && body('bindRefreshBrief') !== '', 'BRIEF_EMPTY, renderClientBrief() and bindRefreshBrief() found');
  if (!varLine('BRIEF_EMPTY')) { console.log('\n' + PASS + ' passed, ' + (FAIL) + ' failed'); process.exit(1); }

  let h = harness({}, okReply);
  const empty = h.sb.renderClientBrief({});
  ok(empty.indexOf(LINE) !== -1 && empty.indexOf(SKEL) === -1, 'no brief: the plain line, no skeleton');
  ok(/<p class="hdlv2-consult-hint">The summary could not/.test(empty), '...in the existing hint style');
  const full = h.sb.renderClientBrief({ pre_consult_summary: { a: 1 } });
  ok(full.indexOf('<div class="BRIEF"></div>') !== -1 && full.indexOf(LINE) === -1, 'with a brief: the brief, as today');

  h = harness({}, okReply);
  h.bodyEl.innerHTML = 'LINE';
  h.sb.bindRefreshBrief(); h.click();
  ok(h.seen[0] === SKEL, 'no brief, Refresh: the skeleton shows while the first summary is written');
  await tick(); await tick();
  ok(h.bodyEl.innerHTML === '<div class="BRIEF"></div>', '...then the brief replaces it');

  h = harness({}, badReply);
  h.sb.bindRefreshBrief(); h.click(); await tick(); await tick();
  ok(h.bodyEl.innerHTML.indexOf(LINE) !== -1, 'no brief, Refresh refused: the plain line comes back');

  h = harness({}, downReply);
  h.sb.bindRefreshBrief(); h.click(); await tick(); await tick();
  ok(h.bodyEl.innerHTML.indexOf(LINE) !== -1 && h.btn.disabled === false, 'no brief, connection down: the plain line comes back, button usable');

  h = harness({ pre_consult_summary: { a: 1 } }, downReply);
  h.bodyEl.innerHTML = 'KEPT';
  h.sb.bindRefreshBrief(); h.click();
  ok(h.seen[0] === 'KEPT', 'with a brief, Refresh: no skeleton, the brief stays while it works');
  await tick(); await tick();
  ok(h.bodyEl.innerHTML === 'KEPT', 'with a brief, Refresh fails: the brief stays');

  console.log('\n' + PASS + ' passed, ' + FAIL + ' failed');
  process.exit(FAIL ? 1 : 0);
})();
