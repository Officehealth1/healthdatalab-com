#!/usr/bin/env node
/**
 * Six abilities on the choices page (v0.48.11) — the client side.
 *
 * Runs the REAL abilityCounts() / radarData() / chartLabel() / radarChart() /
 * abilityBars() from assets/js/hdlv2-staged-form.js and holds abilityCounts()
 * to fixtures/needs.json, which test-abilities-six.php writes from the PHP,
 * so the page and the server never disagree on a need.
 *
 * Run:  node tests/abilities-six/test-abilities-six.js
 * Exit: 0 all pass · 1 any fail
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'hdlv2-staged-form.js'), 'utf8');
function body(name) {
  const m = src.match(new RegExp('  function ' + name + '\\([^)]*\\) \\{[\\s\\S]*?\\n  \\}'));
  return m ? m[0] : '';
}
const ICON_SRC = (src.match(/  var ICONS = \{[\s\S]*?\n  \};/) || [''])[0];
const ESC = 'function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", "\\"": "&quot;" }[c]; }); }';
const ctx = {};
vm.runInNewContext(ESC + '\n' + ICON_SRC + '\n' + ['abilityCounts', 'radarData', 'chartLabel', 'pickIcon', 'radarChart', 'abilityBars'].map(body).join('\n')
  + '\nthis.abilityCounts = abilityCounts; this.radarData = radarData; this.chartLabel = chartLabel; this.radarChart = radarChart; this.abilityBars = abilityBars; this.ICONS = ICONS;', ctx);

const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures', 'needs.json'), 'utf8'));
const SHORT = fx.short;
const rowsFor = (picks) => ctx.abilityCounts(picks, fx.groups, SHORT);

// A-11 scale is always 5.
ok([1, 5, 10].every((n) => ctx.radarData(rowsFor(fx.cases.find((c) => c.picks.length === n).picks), SHORT).top === 5), 'A-11 radarData().top is 5 for 1, 5 and 10 picks');
ok(ctx.radarData([], SHORT).top === 5 && ctx.radarData([], SHORT).spokes.length === 6, 'A-11b no picks: top 5, six spokes');

// A-12 need per spoke equals the PHP, every case in the fixture.
let mismatch = [];
fx.cases.forEach((c) => {
  const got = {};
  rowsFor(c.picks).forEach((r) => { got[r.id] = r.need; });
  if (JSON.stringify(Object.keys(got).sort()) !== JSON.stringify(Object.keys(c.need).sort())
    || Object.keys(c.need).some((k) => got[k] !== c.need[k])) mismatch.push(c.picks.join(','));
});
if (mismatch.length) console.log('      mismatch: ' + mismatch.slice(0, 3).join(' / '));
ok(fx.cases.length > 20 && mismatch.length === 0, 'A-12 page need == server need on all ' + fx.cases.length + ' fixture pick sets');
const worked = rowsFor(fx.cases[0].picks);
ok(JSON.stringify(worked.map((r) => r.id)) === '["strength","mobility","balance","stamina","mind","connection"]',
  'A-12b rows most-needed first, ties in the server\'s ability order');
const spokes = ctx.radarData(worked, SHORT).spokes;
ok(JSON.stringify(spokes.map((s) => s.need)) === '[3.3,3.3,3.3,2.7,1,1]', 'A-12c spokes carry the need, in ability order');
ok(ctx.chartLabel(spokes) === 'What your picks need, out of 5: Strength 3.3, Mobility 3.3, Balance 3.3, Stamina 2.7, Mind 1, Connection 1.', 'A-12d chart said in words');

// The SVG: five rings, the shape at need / 5.
const svg = ctx.radarChart(ctx.radarData(worked, SHORT), 300);
ok((svg.match(/class="hdlv2-why-ring"/g) || []).length === 5, 'A-11c five rings on the chart');
const r = +svg.match(/data-r="(\d+)"/)[1], cx = +svg.match(/data-cx="([\d.]+)"/)[1], cy = +svg.match(/data-cy="([\d.]+)"/)[1];
const first = svg.match(/class="hdlv2-why-shape" points="([\d.]+),([\d.]+)/);
ok(first && Math.abs(+first[1] - cx) < 0.11 && Math.abs((cy - +first[2]) - r * 3.3 / 5) < 0.11, 'A-11d strength point sits at 3.3 / 5 of the radius');

// A-13 bars.
const bars = ctx.abilityBars(worked);
ok(bars.indexOf('width:66%') !== -1 && bars.indexOf('width:54%') !== -1 && bars.indexOf('width:20%') !== -1, 'A-13 meter = need / 5 (3.3 → 66%, 2.7 → 54%, 1 → 20%)');
ok(bars.indexOf('need 3.3 of 5 · 5 of your picks') !== -1 && bars.indexOf('need 1 of 5 · 2 of your picks') !== -1, 'A-13b words: need and how many picks');
ok(ctx.abilityBars([]) === '', 'A-13c nothing picked → nothing');

// A-14 icons.
ok(!('flexibility' in ctx.ICONS) && !('energy' in ctx.ICONS), 'A-14 no flexibility / energy icon left');
ok(Object.keys(SHORT).every((id) => typeof ctx.ICONS[id] === 'string' && ctx.ICONS[id].length > 50), 'A-14b every one of the six has an icon');

// Page copy.
ok(src.indexOf('5 is the most a life can ask of one ability. Your answers on the next pages show where you stand today on each one.') !== -1
  && src.indexOf('The further out, the more of your picks need it.') === -1, 'A-4 caption under the chart (one sentence each, short)');

console.log('\n' + (FAIL ? 'SCENARIO: FAIL (' + FAIL + ')' : 'SCENARIO: PASS (' + PASS + ')'));
process.exit(FAIL ? 1 : 0);
