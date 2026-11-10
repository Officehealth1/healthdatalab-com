#!/usr/bin/env node
/**
 * Healthspan Timeline, model 1 (0.48.60) against model 2 (v0.48.61, plan 2026-11-10), from the REAL page script
 * loaded in a vm as test-model.js does. Not a test: it prints the numbers for Matthew's before/after page.
 *
 *   node tests/healthspan-timeline/probe-model.js
 *
 * Your own lane as the open page draws it: biological age = pace × age (one decimal), pace kept, no future science.
 * "official" = ONS years left at that age (the model's own EX table, life tables UK 2022–2024).
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ctx = { document: { getElementById: () => null, querySelector: () => null }, console };
ctx.window = ctx;
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'hdlv2-healthspan-timeline.js'), 'utf8'), ctx);
const M = ctx.HDLV2HealthspanTimeline, K = M.KNOBS;
const sum = (a) => a.reduce((x, y) => x + y, 0), f1 = (x) => x.toFixed(1);
const SEXES = { M: 'man', F: 'woman' }, AGES = [20, 30, 40, 52, 60, 65, 70, 75, 80, 85, 90], PACES = [0.85, 1.0, 1.14, 1.25];

// years in each stage on your own lane; null when the page draws the person past their lifespan today
function you(model, sex, age, r) {
  M.reset({ mode: 'full', model: model, you: { name: 'You', born: M.NOW_Y - age, sex: sex, bio: Math.round(r * age * 10) / 10, rate0: r } });
  const y = M.yearsByStage(M.state().you, r, r, 0);
  return sum(y) > 0 ? y : null;
}
function loved(model, sex, age, r) {
  M.reset({ mode: 'full', model: model, you: { name: 'You', born: M.NOW_Y - 50, sex: 'F', rate0: 1 } });
  const y = M.yearsByStage({ id: 'q', name: 'Q', rel: 'parent', born: M.NOW_Y - age, sex: sex, rate: r }, r, r, 0);
  return sum(y) > 0 ? y : null;
}
const cell = (y) => (y ? f1(sum(y)) + ' (' + f1(y[0]) + ' green)' : 'beyond lifespan');

console.log('A. Your own lane: years left (green years) today | model 2 | official years left');
console.log(['sex', 'age', 'pace', 'today', 'model 2', 'official'].join('\t'));
for (const s of Object.keys(SEXES)) for (const a of AGES) for (const r of PACES) {
  console.log([s, a, r.toFixed(2), cell(you(1, s, a, r)), cell(you(2, s, a, r)), (s === 'M' ? K.EX_M : K.EX_F)[a].toFixed(2)].join('\t'));
}

console.log('\nB. Average pace 1.00, each stage today | model 2 (green / yellow / orange / red / grey)');
for (const s of Object.keys(SEXES)) for (const a of AGES) {
  const t = you(1, s, a, 1), n = you(2, s, a, 1);
  console.log(s + ' ' + a + ': ' + (t ? t.map(f1).join(' / ') : 'beyond lifespan') + '  |  ' + n.map(f1).join(' / '));
}

console.log('\nC. Loved ones with their own pace (their biological age = their age): years left at 0.85 | 1.00 | 1.25');
for (const [name, s, a] of [['Husband 60', 'M', 60], ['Father 75', 'M', 75], ['Mother 82', 'F', 82], ['Grandmother 90', 'F', 90]]) {
  const row = (m) => [0.85, 1.0, 1.25].map((r) => { const y = loved(m, s, a, r); return y ? f1(sum(y)) : 'beyond'; }).join(' | ');
  console.log('  ' + name + ': today ' + row(1) + '   model 2 ' + row(2));
}

console.log('\nD. Decision D3: years between Healthy (0.85) and Poor (1.25) from 50, your own lane');
for (const s of Object.keys(SEXES)) {
  const d = (m) => sum(you(m, s, 50, 0.85)) - sum(you(m, s, 50, 1.25));
  console.log('  ' + SEXES[s] + ': today ' + f1(d(1)) + ', model 2 ' + f1(d(2)) + '  (Li 2018, five healthy habits against none, from 50: men 12.2, women 14.0)');
}
