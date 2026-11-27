#!/usr/bin/env node
/**
 * v0.49.09 (plan 18, row J2; hdlv2_ff_gauge_scale) — the result page's gauge, widget/hdl-lead-magnet.js.
 * The REAL gaugeUrl is cut from the widget and run in vm; the scale it is handed is the REAL settings
 * answer (scenario-page-gauge-scale.php --emit), so nothing here is a second copy of the marks.
 *   - no scale (switch absent): the picture's address is 835cba8's, character for character.
 *   - with the scale: parts at 0.8, 0.9, 0.9, 1.1, 1.1, 1.4, 1.4 and labels shown at 0.8, 0.9, 1.1 and 1.4;
 *     the needle, the value under the gauge, the Slower / Average / Faster word and every other setting stay.
 *   - cfg.gaugeScale is read from both settings answers and from a late one; renderResult hands it on.
 * Run: node tests/gauge-scale/test-page-gauge.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };

const js = fs.readFileSync(new URL('../../widget/hdl-lead-magnet.js', import.meta.url), 'utf8');
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const fnSrc = cut('  function gaugeUrl(', '\n  }\n');
ok(fnSrc !== '', 'gaugeUrl is a top-level function of the widget');
const url = (rate, scale) => { const c = { out: '' }; vm.runInNewContext(fnSrc + '\nout = gaugeUrl(' + rate + (scale === undefined ? '' : ', ' + JSON.stringify(scale)) + ');', c); return c.out; };
const cfgOf = (u) => JSON.parse(new URL(u).searchParams.get('c'));
const md5 = (s) => crypto.createHash('md5').update(s).digest('hex');

console.log('── P2. no scale: today\'s picture ──');
// md5 of gaugeUrl(rate) at 835cba8.
const TODAY = { 0.5: 'a45b07cdd5a3115236758f126553ebdd', 0.86: '92dc969015ea13ba4376f5f0c4c069ef', 1: 'e19685f534065686a42f881308b4a925',
  1.11: '137d641289af38f2072bb8d124df3518', 1.25: '8a3701d8ec7fadca8bac3173e57f5a17', 2: '6c1d7861061106749332d6c9eaee5220' };
for (const [rate, want] of Object.entries(TODAY)) {
  ok(md5(url(rate)) === want && url(rate, null) === url(rate), 'P2 rate ' + rate + ': the address is 835cba8\'s, character for character (no scale, or null)');
}

console.log('── P1. the scale from the server\'s settings answer ──');
const scale = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./scenario-page-gauge-scale.php', import.meta.url)), '--emit'], { encoding: 'utf8' }));
ok(scale && Array.isArray(scale.data), 'the server answers with a gauge_scale when the switch is on');
for (const [rate, word] of [[0.86, 'Slower'], [1, 'Average'], [1.25, 'Faster']]) {
  const off = cfgOf(url(rate));
  const on = cfgOf(url(rate, scale));
  const ds = on.data.datasets[0], dl = ds.datalabels || {};
  ok(JSON.stringify(ds.data) === '[0.8,0.9,0.9,1.1,1.1,1.4,1.4]', 'P1 ' + word + ': parts at 0.8, 0.9, 0.9, 1.1, 1.1, 1.4, 1.4');
  const shown = (dl.display || []).map((d, i) => (d === true ? ds.data[i] : null)).filter((v) => v !== null);
  ok(JSON.stringify(shown) === '[0.8,0.9,1.1,1.4]' && on.options.plugins.datalabels.display === true, 'P1 ' + word + ': labels shown at 0.8, 0.9, 1.1 and 1.4, and nowhere else');
  ok(['data', 'backgroundColor', 'borderWidth', 'datalabels'].every((k) => JSON.stringify(ds[k]) === JSON.stringify(scale[k])), 'P1 ' + word + ': the four settings are the server\'s, untouched');
  const [g, b, r] = off.data.datasets[0].backgroundColor, t = 'rgba(0,0,0,0)';
  ok(JSON.stringify(ds.backgroundColor) === JSON.stringify([t, g, t, b, t, r, t]), 'P1 ' + word + ': the page\'s own three colours, in order, between clear parts of no width');
  ok(ds.value === off.data.datasets[0].value && on.options.valueLabel.display === true && on.options.subtitle.text === word && on.options.subtitle.display === true,
    'P1 ' + word + ': the needle\'s value, the number under the gauge and the word "' + word + '" stay');
  for (const c of [on, off]) { for (const k of Object.keys(scale)) delete c.data.datasets[0][k]; delete c.options.plugins.datalabels; }
  ok(JSON.stringify(on) === JSON.stringify(off), 'P1 ' + word + ': nothing else moves (range, needle, padding, every other option)');
}
ok(url(1.11, scale) !== url(1.11) && url(1.11, scale).startsWith('https://quickchart.io/chart?c=') && url(1.11, scale).endsWith('&w=380&h=340&bkg=white'), 'P1 the same service and the same picture size as today');

console.log('── W. how the scale reaches the gauge ──');
const cfgLine = cut('    cfg.gaugeScale = ', ';');
const read = (publicCfg, invite) => { const c = { cfg: {}, publicCfg, invite }; vm.runInNewContext(cfgLine, c); return c.cfg.gaugeScale; };
ok(cfgLine !== '', 'cfg.gaugeScale is set where the widget reads its settings');
if (cfgLine) {
  ok(read({ gauge_scale: scale }, null) === scale && read(null, { gauge_scale: scale }) === scale, 'W.1 read from the public settings and from an invite\'s answer');
  ok(read({}, null) === null && read(null, {}) === null && read(null, null) === null, 'W.2 absent in the answer: null, so today\'s picture');
}
const fill = cut('    if (late) late.fill = ', '\n    };\n');
const late = { cfg: {}, late: {} };
vm.runInNewContext(fill, late);
late.late.fill({ gauge_scale: scale });
ok(late.cfg.gaugeScale === scale, 'W.3 a settings answer that lands after the cut-off still brings the scale to the result page');
late.late.fill({});
ok(late.cfg.gaugeScale === null, 'W.4 a late answer without one: null');
const rr = cut('    function renderResult() {', '\n    }\n');
ok(rr.split('gaugeUrl(').length === 2 && rr.includes("src=\"' + gaugeUrl(rate, cfg.gaugeScale) + '\""), 'W.5 renderResult draws the gauge once, with cfg.gaugeScale');

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL === 0 ? 0 : 1);
