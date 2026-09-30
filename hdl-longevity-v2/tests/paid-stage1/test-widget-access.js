#!/usr/bin/env node
/**
 * Paid Stage 1 widget mode (v0.47.85) — which screen the embed shows.
 *
 * Extracts and runs the REAL accessScreen() and safeBuyUrl() from
 * widget/hdl-lead-magnet.js (same approach as tests/report-prep-ticker), then
 * checks the wiring around them in the shipped file.
 *
 * Run:  node tests/paid-stage1/test-widget-access.js
 * Exit: 0 all pass · 1 any fail
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'widget', 'hdl-lead-magnet.js'), 'utf8');

// First-party source from this repo, evaluated in a bare sandbox.
function extract(name) {
  const m = src.match(new RegExp('  function ' + name + '\\([^)]*\\) \\{[\\s\\S]*?\\n  \\}'));
  return m ? vm.runInNewContext(m[0] + '\n' + name + ';', {}) : null;
}
const accessScreen = extract('accessScreen');
const safeBuyUrl = extract('safeBuyUrl');

ok(typeof accessScreen === 'function', 'accessScreen() exists in the widget');
ok(typeof safeBuyUrl === 'function', 'safeBuyUrl() exists in the widget');

if (accessScreen) {
  // accessScreen(hasToken, verify, paidAttr, publicCfg)
  ok(accessScreen(false, null, true, null) === 'locked', 'paid attribute, no token → locked');
  ok(accessScreen(false, null, false, { access_mode: 'paid' }) === 'locked', 'open attribute, paid public-config → locked');
  ok(accessScreen(false, null, true, { access_mode: 'open' }) === 'locked', 'paid attribute wins over a stale open public-config');
  ok(accessScreen(true, { valid: true, source: 'paid_stage1' }, true, null) === 'questions', 'valid paid_stage1 token → questions');
  ok(accessScreen(true, { valid: true, source: 'practitioner' }, true, null) === 'questions', 'valid practitioner invite on a paid page → questions');
  ok(accessScreen(true, { valid: false, reason: 'invalid' }, true, null) === 'error', 'invalid token → error');
  ok(accessScreen(true, { valid: false, reason: 'completed' }, false, null) === 'error', 'used token → error');
  ok(accessScreen(true, null, true, null) === 'error', 'token but verify failed (network) → error');
  ok(accessScreen(false, null, false, null) === 'questions', 'open page, config fetch failed → questions (as today)');
  ok(accessScreen(false, null, false, { access_mode: 'open' }) === 'questions', 'open page → questions (as today)');
}

if (safeBuyUrl) {
  ok(safeBuyUrl('https://shop.example/buy?x=1') === 'https://shop.example/buy?x=1', 'https buy link kept');
  ok(safeBuyUrl('http://shop.example/buy') === '', 'http buy link dropped');
  ok(safeBuyUrl('javascript:alert(1)') === '', 'javascript: buy link dropped');
  ok(safeBuyUrl('') === '' && safeBuyUrl(null) === '', 'empty buy link → no button');
}

// ── Wiring in the shipped file ──
const init = (src.match(/  function init\(\) \{[\s\S]*?\n  \}/) || [''])[0];
ok(/data-access/.test(init) && /accessScreen\(/.test(init), 'init() reads data-access and asks accessScreen()');
const lockedByAttr = init.indexOf('showLocked(');
const cfgFetch = init.indexOf('pullPublicConfig(');
ok(lockedByAttr !== -1 && cfgFetch !== -1 && lockedByAttr < cfgFetch, 'attribute-locked panel is drawn before any public-config fetch');
ok(/if \(needsGeo\) prefetchGeo\(\)/.test(init), 'geo lookup skipped when every widget on the page is locked');
const locked = (src.match(/  function showLocked\([^)]*\) \{[\s\S]*?\n  \}/) || [''])[0];
ok(locked !== '' && !/fetch\(/.test(locked), 'showLocked() makes no request');
ok(/<a class="hdlw-buy"/.test(src) && /\.hdlw-buy:focus-visible/.test(src), 'Buy is a real link with a visible focus state');
ok(!/\b(alert|confirm|prompt)\(/.test(locked), 'no native dialog in the locked panel');
ok(/ticket_required/.test(src), 'ticket_required refusal handled on submit');
ok(/\/\/ ponytail:/.test(src.slice(src.indexOf('function compute(') - 600, src.indexOf('function compute('))), 'ponytail note sits at compute()');

console.log('\nPASS=' + PASS + ' FAIL=' + FAIL);
process.exit(FAIL ? 1 : 0);
