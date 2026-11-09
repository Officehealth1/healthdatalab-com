#!/usr/bin/env node
/**
 * Trajectory chart callout label (v0.48.60). The REAL engine, both copies (the plugin's and the
 * static site's at the repo root), run in a vm with a stub window/document: the callout says
 * "faster" above 1.00×, never a negative "slower" (seen on STBY: "Aging -14% slower than average").
 * The two copies differ in an older badge block (root copy, untouched here); the label is the
 * same function in both.
 *
 * Run:  node tests/trajectory-label/test-label.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }

const FILES = {
  plugin: path.join(__dirname, '..', '..', 'assets', 'js', 'hdl-trajectory-chart-hero.js'),
  root: path.join(__dirname, '..', '..', '..', 'assets', 'js', 'hdl-trajectory-chart-hero.js'),
};
const fnText = (src) => (src.match(/function rateLabel\(rate\) \{[^]*?\n  \}/) || [''])[0];
const texts = {};
for (const [name, file] of Object.entries(FILES)) {
  const src = fs.readFileSync(file, 'utf8');
  texts[name] = fnText(src);
  const ctx = { document: { createElementNS: () => ({}), createElement: () => ({}) }, console };
  ctx.window = ctx;
  vm.runInNewContext(src + '\nthis.E = HDLTrajectoryChart;', ctx);
  const L = ctx.E && ctx.E.rateLabel;
  ok(typeof L === 'function', name + ': HDLTrajectoryChart.rateLabel exposed');
  if (typeof L !== 'function') continue;
  ok(L(1.14) === 'Aging 14% faster than average', name + ': 1.14 → "Aging 14% faster than average"');
  ok(L(1.05) === 'Aging 5% faster than average', name + ': 1.05 → "Aging 5% faster than average"');
  ok(L(0.98) === 'Aging 2% slower than average', name + ': 0.98 → "Aging 2% slower than average"');
  ok(L(0.84) === 'Aging 16% slower than average', name + ': 0.84 (the landing page hero) → unchanged "16% slower"');
  ok(L(1.0) === 'Aging at the average pace' && L(1.004) === 'Aging at the average pace' && L(0.996) === 'Aging at the average pace', name + ': 1.00 (and anything that rounds to 0%) → "Aging at the average pace"');
  ok(!/pctSlower/.test(src) && /var greenText = rateLabel\(currentRate\);/.test(src), name + ': the callout uses rateLabel; no pctSlower left');
}
ok(texts.plugin !== '' && texts.plugin === texts.root, 'rateLabel is the same function, byte for byte, in both copies');

console.log('\nSCENARIO: ' + (FAIL ? 'FAIL' : 'PASS') + ' (' + PASS + '/' + (PASS + FAIL) + ')');
process.exit(FAIL ? 1 : 0);
