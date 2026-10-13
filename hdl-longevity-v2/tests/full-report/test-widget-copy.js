#!/usr/bin/env node
/**
 * H5 / H1 — the widget's locked panel shows the Stage 1 price (and, behind
 * stage1_free_with_code, "Free with your code."), with no request; the
 * widget names "the Full Report" and never "Stage 2/3". Runs the REAL
 * showLocked() against a fake element.
 *
 * Run:  node tests/full-report/test-widget-copy.js
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');
let PASS = 0, FAIL = 0;
function ok(c, l) { (c ? PASS++ : FAIL++); console.log((c ? 'PASS  ' : 'FAIL  ') + l); }
const src = fs.readFileSync(path.join(__dirname, '..', '..', 'widget', 'hdl-lead-magnet.js'), 'utf8');
function fn(name) {
  const one = src.match(new RegExp('\\n(  function ' + name + '\\([^)]*\\) \\{[^\\n]*\\})\\n'));
  if (one) return one[1];
  const m = src.match(new RegExp('  function ' + name + '\\([^)]*\\) \\{[\\s\\S]*?\\n  \\}'));
  return m ? m[0] : '';
}
function el(attrs) {
  return { innerHTML: '', style: { setProperty() {} }, getAttribute: (k) => (k in attrs ? attrs[k] : null) };
}
const ctx = { B: { teal: '#3d8da0' }, fetchCalls: 0 };
const DOC = 'var document = { createElement: function () { var t = ""; return {'
  + ' set textContent(s) { t = s; },'
  + ' get innerHTML() { return t.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;"); } }; } };\n';
const code = DOC + 'function loadFonts() {} function injectEditorialStyles() {} function fetch() { fetchCalls++; }\n'
  + fn('escHtml') + '\n' + fn('safeBuyUrl') + '\n' + fn('buyLink') + '\n' + fn('showLocked') + '\nthis.showLocked = showLocked;';
try { vm.runInNewContext(code, ctx); } catch (e) { console.log('eval failed: ' + e.message); }

const TODAY_BODY = 'When you buy the report from Pat, your link arrives by email. Open it and the questions start on this page.';
if (typeof ctx.showLocked === 'function') {
  let e = el({ 'data-practitioner-name': 'Pat', 'data-stage1-price': '29', 'data-currency': '$' });
  ctx.showLocked(e, null);
  ok(/This report opens with a personal link/.test(e.innerHTML) && /Normally \$29\./.test(e.innerHTML) && !/Free with your code/.test(e.innerHTML), 'price known, code off: title + "Normally $29."');
  ok(e.innerHTML.indexOf(TODAY_BODY) !== -1, '…and today\'s body');
  ok(/Stage 1 &middot; Rate of ageing report|Stage 1 · Rate of ageing report/.test(e.innerHTML), '…eyebrow names Stage 1');

  e = el({ 'data-practitioner-name': 'Pat', 'data-stage1-price': '29', 'data-currency': '$', 'data-free-with-code': '1' });
  ctx.showLocked(e, null);
  ok(/Normally \$29\. Free with your code\./.test(e.innerHTML) && /Use your code on the Buy page/.test(e.innerHTML) && e.innerHTML.indexOf(TODAY_BODY) === -1, 'code on: "Normally $29. Free with your code." and the code body');

  e = el({ 'data-practitioner-name': 'Pat' });
  ctx.showLocked(e, { stage1_list_price: '29', currency: '£', stage1_free_with_code: false });
  ok(/Normally £29\./.test(e.innerHTML), 'old snippet: price from public-config');

  e = el({ 'data-practitioner-name': 'Pat' });
  ctx.showLocked(e, null);
  const before = e.innerHTML;
  ok(!/Normally/.test(before) && /<p class="hdlw-error-eyebrow">Rate of ageing report<\/p>/.test(before) && before.indexOf(TODAY_BODY) !== -1, 'no price known: today\'s panel');

  e = el({ 'data-practitioner-name': 'Pat', 'data-stage1-price': '29<script>', 'data-currency': '$' });
  ctx.showLocked(e, null);
  ok(!/Normally/.test(e.innerHTML) && !/<script>/.test(e.innerHTML), 'a price that is not a number is ignored');
  ok(ctx.fetchCalls === 0, 'showLocked() makes no request');
} else {
  ok(false, 'showLocked() found');
}
const noComments = src.split('\n').filter((l) => !/^\s*(\/\/|\*|\/\*)/.test(l)).join('\n');
ok(!/\bStage ?(2|3)\b/.test(noComments), 'widget: no "Stage 2/3" outside comments');
ok(/Continue to your Full Report \\u2192/.test(src), 'fast path button: "Continue to your Full Report →"');
console.log('\nPASS=' + PASS + ' FAIL=' + FAIL);
process.exit(FAIL ? 1 : 0);
