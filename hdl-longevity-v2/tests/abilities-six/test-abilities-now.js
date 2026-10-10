#!/usr/bin/env node
/**
 * "You now" on the report page and the practitioner's WHY tab (v0.48.12).
 *
 * Runs the REAL renderAbilitiesSection() / renderAbilitiesChart() from
 * assets/js/hdlv2-draft-report.js with a fake Chart, and checks the WHY-tab
 * words in hdlv2-client-list-enhance.js.
 *
 * Run:  node tests/abilities-six/test-abilities-now.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }

const JS = path.join(__dirname, '..', '..', 'assets', 'js');
const src = fs.readFileSync(path.join(JS, 'hdlv2-draft-report.js'), 'utf8');
function body(name) {
  const m = src.match(new RegExp('  function ' + name + '\\([^)]*\\) \\{[\\s\\S]*?\\n  \\}'));
  return m ? m[0] : '';
}
const charts = [];
const canvas = { getContext: () => ({}) };
const ctx = {
  Chart: function (c, cfg) { charts.push(cfg); },
  document: { getElementById: (id) => (id === 'hdlv2-dr-abilities' ? canvas : null) },
  setTimeout: () => {}
};
vm.runInNewContext(['escape', 'renderAbilitiesSection', 'renderAbilitiesChart'].map(body).join('\n')
  + '\nthis.section = renderAbilitiesSection; this.chart = renderAbilitiesChart;', ctx);

const AB = {
  rows: [
    { id: 'strength', label: 'Strength', need: 3.3, score: 3.5, at_90: 1.6, need_now: 5, need_now_capped: true, focus: false },
    { id: 'balance', label: 'Balance <b>', need: 3.3, score: 2, at_90: 0.9, need_now: 5, need_now_capped: true, focus: true },
    { id: 'mind', label: 'A sharp mind', need: 1, score: null, at_90: null, need_now: 1.3, need_now_capped: false, focus: false }
  ],
  series: { labels: ['Strength', 'Mobility', 'Balance', 'Stamina', 'Mind', 'Connection'], needed: [3.3, 3.3, 3.3, 2.7, 1, 1], now: [3.5, 4, 2, 3, null, 5], at_90: [1.6, 2.1, 1, 1.4, null, null], scale: 5 }
};

// B-8 the section.
ok(ctx.section(undefined) === '' && ctx.section({ rows: [] }) === '', 'B-8 no abilities data (old-flow client) → no section');
const noChart = ctx.section({ rows: AB.rows, series: null });
ok(noChart.indexOf('<canvas') === -1 && noChart.indexOf('the line is where') === -1 && noChart.indexOf('hdlv2-dr-ability') !== -1, 'B-8l no series (under two measured) → rows, no canvas, no "line" words');
const html = ctx.section(AB);
ok(html.indexOf('<h2>What your choices need, and where you are</h2>') !== -1 && html.indexOf('id="hdlv2-dr-abilities"') !== -1, 'B-8b section heading and chart canvas');
ok(html.indexOf('need 3.3 of 5 · now 3.5 · about 1.6 at 90 on today&#39;s path · keeping it to 90 needs 5 or more now') !== -1, 'B-8c a row: need, now, at 90, needed now (capped)');
ok(html.indexOf('need 1 of 5 · keeping it to 90 needs about 1.3 now') !== -1, 'B-8d an unmeasured row: no "now", no "at 90"');
ok(html.indexOf('Balance &lt;b&gt;') !== -1 && html.indexOf('<b>') === -1, 'B-8e labels are escaped');
ok((html.match(/hdlv2-dr-ability-focus/g) || []).length === 1, 'B-8f the Focus badge on focus rows only');
ctx.chart(AB);
const cfg = charts[0] || {};
ok(cfg.type === 'radar' && (cfg.data.datasets || []).length === 3, 'B-8g Chart.js radar with three datasets');
ok(JSON.stringify(cfg.data.datasets[2].data) === JSON.stringify(AB.series.at_90) && cfg.data.datasets[2].borderColor === '#2c3e50' && cfg.data.datasets[2].label === 'You at 90 on today\'s path', 'B-8m third line: you at 90 (navy dotted) from the server series');
charts.length = 0; ctx.chart({ rows: AB.rows, series: Object.assign({}, AB.series, { at_90: [null, null, null, null, null, null] }) });
ok((charts[0].data.datasets || []).length === 2, 'B-8n no projection (no age) → two lines');
charts.length = 0; ctx.chart(AB);
ok(cfg.options.scales.r.min === 0 && cfg.options.scales.r.max === 5, 'B-8h scale fixed 0 to 5');
ok(JSON.stringify(cfg.data.datasets[0].data) === JSON.stringify(AB.series.needed) && JSON.stringify(cfg.data.datasets[1].data) === JSON.stringify(AB.series.now)
  && cfg.data.datasets[1].label === 'You now' && cfg.data.datasets[1].borderColor === '#d97706', 'B-8i needed (teal) and you now (amber) from the server series');
charts.length = 0; ctx.chart(undefined);
ok(charts.length === 0, 'B-8j no data → no chart');
ok(/html \+= renderAbilitiesSection\(data\.why_abilities\);\s*\n\s*\/\/ Radar/.test(src) && /renderAbilitiesChart\(data\.why_abilities\)/.test(body('renderReport')),
  'B-8k the section sits directly above "Your Health Profile" and its chart is drawn with the others');

// B-9 the practitioner's WHY tab.
const cle = fs.readFileSync(path.join(JS, 'hdlv2-client-list-enhance.js'), 'utf8');
ok(cle.indexOf("' \\u00b7 keeping it to 90 needs ' + (a.need_now_capped ? '5 or more' : 'about ' + esc(a.need_now)) + ' now'") !== -1
  && cle.indexOf("' \\u00b7 about ' + esc(a.at_90) + ' at 90 on today\\'s path'") !== -1, 'B-9 WHY tab rows carry both projection phrases, escaped');
ok(cle.indexOf('The projection to 90 uses published average losses for each ability, age and sex (about 5 to 34% a decade): an estimate.') !== -1, 'B-9b WHY tab caption names the per-ability rates');

console.log('\n' + (FAIL ? 'SCENARIO: FAIL (' + FAIL + ')' : 'SCENARIO: PASS (' + PASS + ')'));
process.exit(FAIL ? 1 : 0);
