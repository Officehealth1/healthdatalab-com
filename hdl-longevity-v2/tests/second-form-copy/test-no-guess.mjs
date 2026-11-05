#!/usr/bin/env node
/**
 * Second form, no guessing (v0.48.48, W31 + W32): the REAL S1_TO_S3_DEFAULTS, s1Prefill(),
 * tapFirstValues(), tapFirstRows() and tapLandingStep() from assets/js/hdlv2-staged-form.js, run in vm.
 *   - Stage 1 never asks about exercise or sleep quality, so neither is filled from it any more:
 *     no physicalActivity under q3, no sleepQuality under q6. Sleep length, smoking, people, eating stay.
 *   - Those two rows read "Unknown — please select" with their cards open.
 *   - A reload lands on screen 1 while either is empty (tapLandingStep).
 *   - The sleep-quality row says "quality" in its label.
 * Run: node tests/second-form-copy/test-no-guess.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const J = JSON.stringify;

const js = fs.readFileSync(new URL('../../assets/js/hdlv2-staged-form.js', import.meta.url), 'utf8');
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const fn = (name) => cut('  function ' + name + '(', '\n  }\n');

const src = [cut('  var S3_OPTIONS = {', '\n  };\n'), cut('  var S1_TO_S3_DEFAULTS = {', '\n  };\n'),
  cut('  var TAP_FIRST = ', ';\n'), cut('  var TAP_FIRST_LABELS = {', '\n  };\n'),
  fn('s1Prefill'), fn('tapFirstValues'), fn('tapFirstRows'), fn('tapLandingStep')].join('\n');
const ctx = {
  formData: {}, CFG: {}, tapOpenRow: null, ROW_ICONS: {},
  esc: (s) => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
  tapCards: (f, v) => `[cards:${f}:${v}]`,
};
// Top-level var and function declarations become properties of the context.
vm.runInNewContext(src, ctx);
const get = (n) => ctx[n];
ctx.setFormData = (d) => { ctx.formData = d; };

const MAP = get('S1_TO_S3_DEFAULTS') || {};
const FIRST = ['physicalActivity', 'sleepDuration', 'sleepQuality', 'smokingStatus', 'socialConnections', 'dietQuality'];
const ASKED = ['sleepDuration', 'smokingStatus', 'socialConnections', 'dietQuality'];
const NEVER = ['physicalActivity', 'sleepQuality'];

console.log('── the map: nothing guessed that Stage 1 never asked ──');
ok(!(MAP.q3 && 'physicalActivity' in MAP.q3), 'N1.1 q3 (zone 2) no longer fills physicalActivity');
ok(!(MAP.q6 && 'sleepQuality' in MAP.q6), 'N1.2 q6 (sleep) no longer fills sleepQuality');
ok(J(MAP.q6 && MAP.q6.sleepDuration) === J({ a: '0', b: '2', c: '3', d: '4', e: '5' }), 'N1.3 q6 still fills sleepDuration, unchanged');
ok(J(MAP.q7) === J({ smokingStatus: { a: '0', b: '2', c: '4', d: '4', e: '5' } })
  && J(MAP.q8) === J({ socialConnections: { a: '1', b: '2', c: '3', d: '4', e: '5' } })
  && J(MAP.q9) === J({ dietQuality: { a: '0', b: '2', c: '3', d: '4', e: '5' } }), 'N1.4 smoking, people and eating maps unchanged');

console.log('── screen-1 values from a Stage 1 answer set ──');
const values = get('tapFirstValues');
if (typeof values === 'function') {
  ctx.setFormData({});
  const v = values({ q3: 'c', q6: 'b', q7: 'a', q8: 'd', q9: 'e' }, {});
  ok(J(v) === J({ physicalActivity: '', sleepDuration: '2', sleepQuality: '', smokingStatus: '0', socialConnections: '4', dietQuality: '5' }),
    'N2.1 a full Stage 1 set gives four filled and two empty');
  ok(!('physicalActivity' in get('formData')) && !('sleepQuality' in get('formData')), 'N2.2 nothing is written into formData for the two empty rows');
  const L = ['a', 'b', 'c', 'd', 'e', undefined];
  let empty = true, filled = true, n = 0;
  for (const q3 of L) for (const q6 of L) for (const q7 of L) for (const q8 of L) for (const q9 of L) {
    ctx.setFormData({});
    const got = values({ q3, q6, q7, q8, q9 }, {});
    if (NEVER.some((f) => got[f] !== '')) empty = false;
    if ((q6 && got.sleepDuration === '') || (q7 && got.smokingStatus === '') || (q8 && got.socialConnections === '') || (q9 && got.dietQuality === '')) filled = false;
    n++;
  }
  ok(empty && n === 7776, 'N2.3 the two rows are empty for every Stage 1 answer (' + n + ' cases)');
  ok(filled, 'N2.4 the four asked rows are filled whenever Stage 1 answered them');
  const saved = { physicalActivity: '3', sleepDuration: '1', sleepQuality: '4', smokingStatus: '5', socialConnections: '2', dietQuality: '0' };
  ok(J(values({ q3: 'a', q6: 'e' }, saved)) === J(saved), 'N2.5 a saved answer still wins');
} else ok(false, 'tapFirstValues() exists');

console.log('── screen 1: the two rows ask, the four show ──');
const rowsFn = get('tapFirstRows');
if (typeof rowsFn === 'function') {
  const html = rowsFn({ physicalActivity: '', sleepDuration: '2', sleepQuality: '', smokingStatus: '0', socialConnections: '4', dietQuality: '5' });
  const row = (f) => (html.split('<div class="hdlv2-tap-row">').find((r) => r.indexOf('id="hdlv2-tap-label-' + f + '"') !== -1) || '');
  for (const f of NEVER) {
    ok(row(f).indexOf('<span class="hdlv2-tap-row-value is-empty">Unknown — please select</span>') !== -1, `N3.${f} reads "Unknown — please select"`);
    ok(/id="hdlv2-tap-opts-[a-zA-Z]+" role="radiogroup"[^>]*data-row="[a-zA-Z]+">/.test(row(f)) && row(f).indexOf('class="hdlv2-tap-change"') === -1, `N4.${f} its cards are open, no Change button`);
  }
  for (const f of ASKED) ok(row(f).indexOf('is-empty') === -1 && / hidden>/.test(row(f)), `N5.${f} filled, cards closed`);
  ok(html.indexOf('Not answered yet') === -1, 'N6 the old empty text is gone');
} else ok(false, 'tapFirstRows() exists');

console.log('── a reload waits on screen 1 until both are answered ──');
const land = get('tapLandingStep');
if (typeof land === 'function') {
  const four = { sleepDuration: '2', smokingStatus: '0', socialConnections: '4', dietQuality: '5' };
  const ORDER = ['sitToStand'];
  ok(land(four, ORDER) === 0, 'N7.1 only the four Stage 1 rows saved: screen 1');
  ok(land(Object.assign({ physicalActivity: '2' }, four), ORDER) === 0 && land(Object.assign({ sleepQuality: '3' }, four), ORDER) === 0, 'N7.2 either one still empty: screen 1');
  ok(land(Object.assign({ physicalActivity: '2', sleepQuality: '3' }, four), ORDER) === 1, 'N7.3 all six: the first question');
} else ok(false, 'tapLandingStep() exists');

console.log('── the labels ──');
const LABELS = get('TAP_FIRST_LABELS') || {};
ok(LABELS.sleepQuality === 'How well you sleep · sleep quality', 'N8.1 sleep quality says "quality"');
ok(J(FIRST.filter((f) => f !== 'sleepQuality').map((f) => LABELS[f])) === J(['How active you are', 'How long you sleep', 'Smoking', 'Time with people you care about', 'How you eat']),
  'N8.2 the other five labels unchanged');

console.log(`PASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
