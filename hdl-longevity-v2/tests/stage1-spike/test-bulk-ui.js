#!/usr/bin/env node
/**
 * Bulk Confirm / Reject in the pending-leads queue (v0.48.16, H7 revised).
 * Runs the REAL bulkControlsVisible(), chunk() and bulkSummary() extracted
 * from assets/js/hdlv2-client-list-enhance.js, then checks the wiring.
 *
 * Run:  node tests/stage1-spike/test-bulk-ui.js   (exit 0/1)
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'hdlv2-client-list-enhance.js'), 'utf8');
function extract(name) {
  const m = src.match(new RegExp('  function ' + name + '\\([^)]*\\) \\{[\\s\\S]*?\\n  \\}'));
  return m ? vm.runInNewContext(m[0] + '\n' + name + ';', {}) : null;
}

const visible = extract('bulkControlsVisible');
ok(typeof visible === 'function', 'bulkControlsVisible() exists');
if (visible) {
  ok(visible(9, 10) === false, '9 pending → hidden');
  ok(visible(10, 10) === true, '10 pending → shown');
  ok(visible(1, 0) === true, 'bulkMin 0 → always shown');
  ok(visible(12, undefined) === true && visible(9, undefined) === false, 'no bulk_min from the server → 10');
}
const chunk = extract('chunk');
ok(typeof chunk === 'function', 'chunk() exists');
if (chunk) {
  const ids = Array.from({ length: 45 }, (_, i) => i + 1);
  const c = chunk(ids, 20);
  ok(c.length === 3 && c[0].length === 20 && c[1].length === 20 && c[2].length === 5 && c[2][4] === 45, '45 ids → 20 / 20 / 5');
}
const summary = extract('bulkSummary');
ok(typeof summary === 'function', 'bulkSummary() exists');
if (summary) {
  const n = k => Array.from({ length: k }, (_, i) => i);
  ok(summary({ confirmed: n(37), already: n(2), failed: n(1) }, 'confirmed') === '37 confirmed · 2 already confirmed · 1 failed', 'confirm summary');
  ok(summary({ rejected: n(5), already: [], failed: [] }, 'rejected') === '5 rejected', 'reject summary, empty parts left out');
}

// Wiring.
ok(/data-aq="lead-reject-selected"/.test(src) && /Reject selected \(/.test(src), 'a Reject selected (N) button');
ok(/hdlv2-aq-btn-bulk hdlv2-aq-btn-pending-reject/.test(src), 'Reject selected uses the same classes plus the single Reject colour');
ok(/bulkControlsVisible\(g\.items\.length, state\.bulkMin\)/.test(src), 'tick boxes + Select all render only when visible');
ok(/state\.bulkMin = /.test(src) && /data\.bulk_min/.test(src), 'bulk_min read from the pending-leads reply');
ok(src.includes("' leads? Each one gets their next-step email.'") && src.includes("' leads? They leave your list.'"), 'the two inline questions');
ok(/role="status"/.test(src.slice(src.indexOf('function bindBulkActions'))), 'the inline question line is role=status');
ok(!/HDLV2UI\.confirm\(\{\s*title: 'Confirm ' \+ ids\.length/.test(src) && !/window\.confirm\(/.test(src), 'no modal / native dialog for the bulk actions');
ok(/'Confirming ' \+/.test(src) && /'Rejecting ' \+/.test(src), 'progress line between chunks');
ok(/Stopped: ' \+/.test(src) && /The rest are still pending\./.test(src), 'a failed chunk stops the loop and says the rest are still pending');
ok(/'\/widget\/leads\/' \+ \(action === 'confirm' \? 'confirm-bulk' : 'reject-bulk'\)/.test(src), 'posts to confirm-bulk or reject-bulk');
ok(/Escape/.test(src.slice(src.indexOf('function bindBulkActions'))), 'Escape closes the question');

console.log('\nPASS=' + PASS + ' FAIL=' + FAIL);
process.exit(FAIL ? 1 : 0);
