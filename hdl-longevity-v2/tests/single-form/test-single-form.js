#!/usr/bin/env node
/**
 * Single-form WHY picks page (v0.47.92, abilities v0.47.93) — the client side.
 *
 * Extracts and runs the REAL togglePick() / canContinue() and, from v0.47.93,
 * themesWithPicks() / toggleTheme() / abilityCounts() from
 * assets/js/hdlv2-staged-form.js (same approach as tests/paid-stage1), then
 * checks the routing and the new page's wiring in the shipped file.
 *
 * Run:  node tests/single-form/test-single-form.js
 * Exit: 0 all pass · 1 any fail
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const crypto = require('crypto');

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'hdlv2-staged-form.js'), 'utf8');

function body(name) {
  const m = src.match(new RegExp('  function ' + name + '\\([^)]*\\) \\{[\\s\\S]*?\\n  \\}'));
  return m ? m[0] : '';
}
const limits = (src.match(/  var PICK_MIN = \d+, PICK_MAX = \d+;/) || [''])[0];
ok(limits !== '', 'PICK_MIN / PICK_MAX declared');
const ctx = {};
try {
  vm.runInNewContext(limits + '\n' + body('togglePick') + '\n' + body('canContinue') + '\nthis.togglePick = togglePick; this.canContinue = canContinue;', ctx);
} catch (e) { /* missing functions: reported below */ }
const { togglePick, canContinue } = ctx;
ok(typeof togglePick === 'function' && typeof canContinue === 'function', 'togglePick() and canContinue() exist');

if (typeof togglePick === 'function') {
  let r = togglePick([], 'a');
  ok(JSON.stringify(r.picks) === '["a"]' && r.atLimit === false, 'toggle adds an id');
  r = togglePick(r.picks, 'a');
  ok(r.picks.length === 0, 'second toggle removes it');
  const ten = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'];
  r = togglePick(ten, 'k');
  ok(r.picks.length === 10 && r.picks.indexOf('k') === -1 && r.atLimit === true, 'an 11th pick is refused and reports "at the limit"');
  r = togglePick(ten, 'c');
  ok(r.picks.length === 9 && r.picks.indexOf('c') === -1, 'removing at the limit still works');
  const orig = ['a'];
  togglePick(orig, 'b');
  ok(orig.length === 1, 'toggle does not mutate its input');
}
if (typeof canContinue === 'function') {
  ok(canContinue(['a', 'b', 'c', 'd']) === false, 'canContinue false under 5');
  ok(canContinue(['a', 'b', 'c', 'd', 'e']) === true, 'canContinue true at 5');
  ok(canContinue(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j']) === true, 'canContinue true at 10');
  ok(canContinue(null) === false, 'canContinue false for no picks');
}

// ── Wiring in the shipped file ──
const load = body('loadForm');
ok(/currentStage === 2[\s\S]*data\.single_form[\s\S]*renderWhyPicks\(data\)/.test(load), 'loadForm routes a covered stage-2 row to renderWhyPicks');
ok(/renderStage2\(data\)/.test(load), 'loadForm still routes other stage-2 rows to renderStage2');

const page = body('renderWhyPicks');
ok(page !== '', 'renderWhyPicks() exists');
ok(/type="checkbox"/.test(page), 'every statement is a real checkbox');
ok(/aria-live="polite"/.test(page), 'the counter is announced (aria-live)');
ok(!/\b(alert|confirm|prompt)\(/.test(page), 'no native popups on the new page');

// ── v0.48.0: every statement on the page, far fewer words; v0.48.1: bars + radar ──
const ctx2 = {};
try {
  vm.runInNewContext(limits + '\n' + body('abilityCounts') + '\n' + body('pickCountLine') + '\n' + body('radarData') + '\n' + body('chartLabel')
    + '\nthis.abilityCounts = abilityCounts; this.pickCountLine = pickCountLine; this.radarData = radarData; this.chartLabel = chartLabel;', ctx2);
} catch (e) { /* missing functions: reported below */ }
const { abilityCounts, pickCountLine, radarData, chartLabel } = ctx2;
ok(typeof abilityCounts === 'function', 'abilityCounts() exists (it feeds the chart)');
ok(body('toggleTheme') === '' && body('themesWithPicks') === '' && !/openThemes/.test(src), 'toggleTheme(), themesWithPicks() and openThemes are gone');

// v0.48.11 — six abilities, weighted tags (tests/abilities-six holds the
// page to the server's numbers on the real catalogue).
const GROUPS = [
  { id: 'people', items: [{ id: 'a', needs: ['strength', 'mobility'], weights: { strength: 2, mobility: 3 } }, { id: 'b', needs: ['strength', 'balance'], weights: { strength: 3, balance: 2 } }] },
  { id: 'freedom', items: [{ id: 'c', needs: ['stamina'], weights: { stamina: 3 } }, { id: 'd', needs: ['balance', 'stamina'], weights: { balance: 2, stamina: 1 } }] },
  { id: 'things', items: [{ id: 'e', needs: ['mind'], weights: { mind: 3 } }] }
];
const ABILITIES = { strength: 'Strength', mobility: 'Mobility and flexibility', balance: 'Balance',
  stamina: 'Stamina and energy', mind: 'A sharp mind', connection: 'Connection' };
const SHORT = { strength: 'Strength', mobility: 'Mobility', balance: 'Balance',
  stamina: 'Stamina', mind: 'Mind', connection: 'Connection' };
const J = JSON.stringify;

if (typeof abilityCounts === 'function') {
  // By hand, four picks: need = round(50 x sum / 12) / 10. strength 2+3 → 2.1 · balance 2+2 → 1.7 ·
  // mobility 3 → 1.3 · mind 3 → 1.3 · stamina 1 → 0.4.
  ok(J(abilityCounts(['a', 'b', 'd', 'e'], GROUPS, ABILITIES)) === J([
    { id: 'strength', label: 'Strength', count: 2, need: 2.1 }, { id: 'balance', label: 'Balance', count: 2, need: 1.7 },
    { id: 'mobility', label: 'Mobility and flexibility', count: 1, need: 1.3 }, { id: 'mind', label: 'A sharp mind', count: 1, need: 1.3 },
    { id: 'stamina', label: 'Stamina and energy', count: 1, need: 0.4 }
  ]), 'ability counts, needs and order match the hand-worked case');
  ok(J(abilityCounts([], GROUPS, ABILITIES)) === '[]' && J(abilityCounts(null, GROUPS, ABILITIES)) === '[]', 'no picks → empty list');
  ok(J(abilityCounts(['zzz', 'c'], GROUPS, ABILITIES)) === J([{ id: 'stamina', label: 'Stamina and energy', count: 1, need: 5 }]), 'an id not on offer counts for nothing');
}

// The count line, in its three states (Design 3).
if (typeof pickCountLine === 'function') {
  ok(pickCountLine(0) === '0 picked. Pick at least 5.', 'count line: none picked');
  ok(pickCountLine(3) === '3 picked. Pick at least 5.', 'count line: under 5');
  ok(pickCountLine(6) === '6 picked.', 'count line: 5 to 9');
  ok(pickCountLine(10) === '10 picked. That is the most.', 'count line: at 10');
} else ok(false, 'pickCountLine() exists');

// v0.48.1 — the radar's numbers: every ability in the server's order with its
// need; v0.48.11 the scale top is always 5.
ok(body('wedgeSizes') === '' && body('polarChart') === '' && !/wedgeSizes|polarChart|id="hdlv2-why-wheel"/.test(src),
  'the wedge chart is gone: no wedgeSizes(), no polarChart(), no wedge styles hooks');
if (typeof radarData === 'function') {
  const zero = radarData([], SHORT);
  ok(zero.top === 5 && J(zero.spokes.map(function (s) { return s.id; })) === J(Object.keys(SHORT)) && zero.spokes.every(function (s) { return s.need === 0; }),
    'radar data: nothing picked → six abilities at 0 in the server\'s order, top 5');
  const one = radarData([{ id: 'stamina', need: 1 }], SHORT);
  ok(one.top === 5 && one.spokes[3].id === 'stamina' && one.spokes[3].need === 1 && one.spokes.filter(function (s) { return s.need > 0; }).length === 1,
    'radar data: one ability → its need, top 5');
  const floor = radarData([{ id: 'balance', need: 1 }, { id: 'strength', need: 2 }], SHORT);
  ok(floor.top === 5 && floor.spokes[0].need === 2 && floor.spokes[2].need === 1, 'radar data: input order does not matter');
  const big = radarData([{ id: 'strength', need: 5 }, { id: 'balance', need: 2 }], SHORT);
  ok(big.top === 5 && big.spokes[0].need === 5, 'radar data: a need of 5 reaches the edge, the top stays 5');
  ok(big.spokes[0].label === 'Strength' && big.spokes[4].label === 'Mind', 'radar data carries the short names');
} else ok(false, 'radarData() exists');

if (typeof chartLabel === 'function' && typeof radarData === 'function') {
  ok(chartLabel(radarData([], SHORT).spokes) === 'Nothing picked yet.', 'chart aria-label with no picks');
  ok(chartLabel(radarData([{ id: 'balance', need: 1 }, { id: 'strength', need: 2 }], SHORT).spokes) === 'What your picks need, out of 5: Strength 2, Balance 1.',
    'chart aria-label says it in words, in the server\'s order');
} else ok(false, 'chartLabel() exists');

// The radar markup, from the REAL radarChart() (and pickIcon/esc/chartLabel).
const ICON_SRC0 = (src.match(/  var ICONS = \{[\s\S]*?\n  \};/) || [''])[0];
const rctx = { esc: function (s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); } };
try {
  vm.runInNewContext(ICON_SRC0 + '\n' + ['pickIcon', 'chartLabel', 'radarData', 'radarChart'].map(body).join('\n')
    + '\nthis.radarChart = radarChart; this.radarData = radarData;', rctx);
} catch (e) { /* reported below */ }
if (typeof rctx.radarChart === 'function') {
  const rows = [{ id: 'strength', need: 2 }, { id: 'balance', need: 1 }, { id: 'mind', need: 1 }];
  const svg = rctx.radarChart(rctx.radarData(rows, SHORT), 300);
  ok((svg.match(/class="hdlv2-why-ring"/g) || []).length === 5, 'radar: one ring per whole number up to the top (5)');
  ok((svg.match(/class="hdlv2-why-axis"/g) || []).length === 6, 'radar: six spokes');
  ok(/class="hdlv2-why-ring"[^>]*stroke="rgba\(0,0,0,0\.08\)"/.test(svg) && /class="hdlv2-why-axis"[^>]*stroke="rgba\(0,0,0,0\.08\)"/.test(svg),
    'radar: web lines and spokes rgba(0,0,0,0.08), as on the report');
  ok(/<polygon class="hdlv2-why-ring"/.test(svg) && !/<circle class="hdlv2-why-ring"/.test(svg), 'radar: rings are straight lines between spokes (polygons), not circles');
  const shape = (svg.match(/<polygon class="hdlv2-why-shape"[^>]*>/) || [''])[0];
  ok(/fill="rgba\(61, 141, 160, 0\.15\)"/.test(shape) && /stroke="rgba\(61, 141, 160, 0\.9\)"/.test(shape) && /stroke-width="2"/.test(shape) && /stroke-linejoin="round"/.test(shape),
    'radar: shape fill 0.15 teal, outline 0.9 teal, 2 wide, round joins');
  const ptsAttr = (shape.match(/points="([^"]+)"/) || ['', ''])[1].trim().split(/\s+/).map(function (p) { return p.split(',').map(Number); });
  const cx = Number((svg.match(/data-cx="([^"]+)"/) || [])[1]), cy = Number((svg.match(/data-cy="([^"]+)"/) || [])[1]), R = Number((svg.match(/data-r="([^"]+)"/) || [])[1]);
  const dist = ptsAttr.map(function (p) { return Math.round(Math.hypot(p[0] - cx, p[1] - cy) / R * 300) / 300; });
  ok(ptsAttr.length === 6 && J(dist) === J([2 / 5, 0, 1 / 5, 0, 1 / 5, 0].map(function (v) { return Math.round(v * 300) / 300; })),
    'radar: each point sits at need ÷ 5 along its spoke (Strength 2/5, Balance 1/5, Mind 1/5, the rest at the centre)');
  ok(ptsAttr.length === 6 && Math.abs(ptsAttr[0][0] - cx) < 0.2 && ptsAttr[0][1] < cy, 'radar: the first ability (Strength) is on the upward spoke');
  const dots = svg.match(/<circle class="hdlv2-why-dot"[^>]*>/g) || [];
  ok(dots.length === 3 && dots.every(function (d) { return /r="4\.5"/.test(d) && /fill="#3d8da0"/.test(d) && /stroke="#fff"/.test(d) && /stroke-width="1\.5"/.test(d); }),
    'radar: a teal dot (r 4.5, white edge 1.5) only on the three spokes with a pick');
  ok(['Strength', 'Mobility', 'Balance', 'Stamina', 'Mind', 'Connection'].every(function (n) { return svg.indexOf('>' + n + '</text>') !== -1; }),
    'radar: the six short names');
  ok((svg.match(/class="hdlv2-why-spoke has-picks"/g) || []).length === 3 && (svg.match(/class="hdlv2-why-spoke"/g) || []).length === 3,
    'radar: names with a pick are marked has-picks, the other three are not');
  ok((svg.match(/<svg class="hdlv2-why-icon"/g) || []).length === 6, 'radar: a picture at each of the six names');
  ok(/role="img"[^>]*aria-label="What your picks need, out of 5: Strength 2, Balance 1, Mind 1\."/.test(svg), 'radar: spoken label with picks');
  ok(!/ A[\d.]+ [\d.]+ 0 0 1 /.test(svg), 'radar: no wedge arcs left');
  const empty = rctx.radarChart(rctx.radarData([], SHORT), 300);
  ok(/role="img"[^>]*aria-label="Nothing picked yet\."/.test(empty) && !/hdlv2-why-dot/.test(empty) && !/hdlv2-why-shape/.test(empty)
    && (empty.match(/class="hdlv2-why-axis"/g) || []).length === 6, 'radar: nothing picked → the empty web and names, no shape, no dots');
  const five = rctx.radarChart(rctx.radarData([{ id: 'strength', need: 0.4 }], SHORT), 300);
  ok((five.match(/class="hdlv2-why-ring"/g) || []).length === 5, 'radar: a small need still draws five rings (the scale never moves)');
  ok(/<text class="hdlv2-why-tick"[^>]*>1<\/text>/.test(svg) && /<text class="hdlv2-why-tick"[^>]*>3<\/text>/.test(svg), 'radar: ring numbers beside the upward spoke');
  const vb = (svg.match(/viewBox="([^"]+)"/) || ['', ''])[1].split(' ').map(Number);
  ok(vb.length === 4 && Math.abs(vb[2] - 300) < 0.01, 'radar: drawn one unit per pixel at the width it is given (names keep their real size)');
} else ok(false, 'radarChart() exists');

// The bars, from the REAL abilityBars().
const bctx = { esc: rctx.esc };
try { vm.runInNewContext(ICON_SRC0 + '\n' + body('pickIcon') + '\n' + body('abilityBars') + '\nthis.abilityBars = abilityBars;', bctx); } catch (e) { /* reported below */ }
if (typeof bctx.abilityBars === 'function') {
  const bars = bctx.abilityBars([{ id: 'strength', label: 'Strength', count: 2, need: 2 }, { id: 'balance', label: 'Balance', count: 1, need: 1 }]);
  const rowsB = bars.split('role="listitem"').slice(1);
  ok(/role="list"/.test(bars) && rowsB.length === 2, 'bars: a list with one row per ability that has a pick');
  ok(/<svg class="hdlv2-why-icon"/.test(rowsB[0]) && rowsB[0].indexOf('>Strength<') !== -1 && rowsB[0].indexOf('need 2 of 5 \u00b7 2 of your picks') !== -1,
    'bars: a row holds the picture, the short name and "need N of 5 · N of your picks"');
  ok(/class="hdlv2-ability-meter" aria-hidden="true"><span style="width:40%">/.test(rowsB[0]) && /width:20%/.test(rowsB[1]),
    'bars: the meter width is need ÷ 5 (2 of 5 = 40%), and it is aria-hidden');
  ok(bars.indexOf('>Strength<') < bars.indexOf('>Balance<'), 'bars: in the order given (most needed first)');
  ok(bctx.abilityBars([]) === '', 'bars: nothing picked → nothing (0.48.3: the section is hidden instead)');
} else ok(false, 'abilityBars() exists');

// v0.48.1 — the page width class.
const rc = body('setRootClasses');
const rcCls = new Set(['is-picks']);
const rcCtx = { root: { classList: { remove: function () { for (const c of arguments) rcCls.delete(c); }, add: function (c) { rcCls.add(c); } } } };
try { vm.runInNewContext(rc + '\nsetRootClasses("is-medium");', rcCtx); } catch (e) { /* reported below */ }
ok(!rcCls.has('is-picks') && rcCls.has('is-medium'), 'setRootClasses() removes is-picks when another page renders');
ok(/setRootClasses\('is-picks'\)/.test(body('renderWhyPicks')) && !/setRootClasses\('is-medium'\)/.test(body('renderWhyPicks')), 'the choices page sets is-picks');
ok((src.match(/setRootClasses\('is-picks'\)/g) || []).length === 1, 'no other page uses is-picks');

// The page markup: run the REAL renderWhyPicks() against a fake root.
function fakeEl() {
  return { hidden: false, textContent: '', innerHTML: '', disabled: false, checked: false, value: '', parentNode: { classList: { toggle: function () {} } },
    classList: { add: function () {}, remove: function () {}, toggle: function () {} }, setAttribute: function () {}, addEventListener: function () {}, querySelectorAll: function () { return []; } };
}
const fakeRoot = Object.assign(fakeEl(), { querySelectorAll: function () { return []; } });
const pageCtx = {
  root: fakeRoot, formData: {}, serverData: {}, saveTimer: null, PICK_MIN: 5, PICK_MAX: 10,
  document: { getElementById: function () { return fakeEl(); } }, window: { addEventListener: function () {} },
  setRootClasses: function () {}, saveIndicator: function () { return ''; }, footer: function () { return ''; },
  bindFieldListeners: function () {}, submitWhyPicks: function () {}, clearSaveError: function () {}, autoSave: function () {},
  esc: function (s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
};
const PAGE_FNS = ['togglePick', 'canContinue', 'abilityCounts', 'pickCountLine', 'radarData', 'chartLabel', 'pickIcon', 'radarChart', 'abilityBars', 'renderWhyPicks', 'redrawRadar', 'reducedMotion', 'updatePicks', 'picksTextarea'];
const ICON_SRC = (src.match(/  var ICONS = \{[\s\S]*?\n  \};/) || [''])[0];
let html = '';
try {
  vm.runInNewContext(ICON_SRC + '\n' + (src.match(/  var TICK = [\s\S]*?;\n/) || [''])[0] + (src.match(/  var radarShown = [^\n]*\n/) || [''])[0] + PAGE_FNS.map(body).join('\n') + '\nthis.renderWhyPicks = renderWhyPicks;', pageCtx);
  // v0.48.9: the groups are uneven (6 / 8 / 7); the page draws what it is sent.
  const gN = function (gid, title, n) {
    const items = [];
    for (let i = 0; i < n; i++) items.push({ id: gid + i, label: title + ' statement ' + i, needs: ['strength'] });
    return { id: gid, title: title, items: items };
  };
  const data = { why_options: [gN('people', 'People I love', 6), gN('freedom', 'My freedom', 8), gN('things', 'Things I love', 7)], why_abilities: ABILITIES, why_ability_short: SHORT };
  pageCtx.serverData = data;
  pageCtx.renderWhyPicks(data);
  html = fakeRoot.innerHTML;
} catch (e) { console.log('      renderWhyPicks threw: ' + e.message); }
ok(html !== '', 'renderWhyPicks() draws the page');
const groupsHtml = html.split('class="hdlv2-why-group"').slice(1);
ok(groupsHtml.length === 3 && J(groupsHtml.map(function (g) { return (g.match(/type="checkbox"/g) || []).length; })) === J([6, 8, 7]),
  'three groups drawn exactly as sent (6 / 8 / 7), all in the page from the start');
ok(!/\b8\b|\b24\b/.test(body('renderWhyPicks')), 'renderWhyPicks() assumes no fixed number of statements');
ok(!/ hidden[ >]|hidden=""/.test(html.split('id="hdlv2-picks-count"')[0]), 'no group or statement is hidden');
ok(!/hdlv2-theme-|hdlv2-pick-needs/.test(html), 'no theme tile, no "needs" words');
ok(/<h2>What matters most to you<\/h2>/.test(html), 'title: What matters most to you');
ok(html.indexOf('<p>Pick the 5 to 10 that matter most to you in your last decade of life.</p>') !== -1, 'one opening line, as written');
ok(/People I love[\s\S]*My freedom[\s\S]*Things I love/.test(html), 'group headings in order');
ok(html.indexOf('<h3>What your picks need from your body</h3>') !== -1, 'section heading, as written');
ok(html.indexOf('<p>Each thing you picked needs some of these. Your plan starts with the ones your health answers show are weakest.</p>') !== -1, 'section line, as written');
ok(html.indexOf('<p class="hdlv2-why-caption">5 is the most a life can ask of one ability. Your answers on the next pages show where you stand today on each one.</p>') !== -1, 'radar caption, as written (v0.48.11)');
ok(/id="hdlv2-abilities"/.test(html) && /id="hdlv2-why-radar"/.test(html) && html.indexOf('id="hdlv2-abilities"') < html.indexOf('id="hdlv2-why-radar"'),
  'the bars (#hdlv2-abilities) and the radar are both on the page, bars first');
ok(/<section class="hdlv2-why-needs" id="hdlv2-why-needs" hidden>/.test(html) && src.indexOf('Tap a few above') === -1,
  'nothing picked: the whole section (bars and radar) is hidden, and the "Tap a few above" line is gone');
ok(/needs\.hidden = !rows\.length;[\s\S]*redrawRadar\(\)/.test(body('updatePicks')),
  'a tap shows the section once an ability has a pick (and hides it at none), before the radar is measured and redrawn');
ok(/class="hdlv2-why-radar"[\s\S]*class="hdlv2-why-caption"/.test(html), 'the caption sits under the radar');
ok(html.indexOf("Who are you doing this for? First names.") !== -1 && html.indexOf('placeholder="Jenny, Leo, Mia"') !== -1, 'people label and placeholder, as written');
ok(html.indexOf('Anything else? (optional)') !== -1, 'own-words label, as written');
ok(/>Next</.test(html) || />Next &#8594;</.test(html), 'Next button');
ok(/role="img"[^>]*aria-label="Nothing picked yet\."/.test(html), 'the chart is on the page from the start, empty, with its spoken label');
ok((html.match(/<svg[^>]*aria-hidden="true"/g) || []).length >= 9, 'every picture is decoration (aria-hidden): 3 group + 6 ability pictures');
ok(/type="checkbox"/.test(page), 'every statement is a real checkbox');
ok(/aria-live="polite"/.test(page), 'the counter is announced (aria-live)');
ok(!/\b(alert|confirm|prompt)\(/.test(src), 'the shipped file has no alert( / confirm( / prompt(');
ok(src.indexOf('Tap the parts of life you care about') === -1 && src.indexOf('Start with one or two that matter to you.') === -1
  && src.indexOf('What your choices ask of your body') === -1, 'the old long copy is gone');
const cle = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'hdlv2-client-list-enhance.js'), 'utf8');
ok(cle.indexOf("'<div class=\"hdlv2-consult-caption\">Need is out of 5: 5 means every choice leans on the ability fully. Scores are out of 5, averaged from the answers listed. They are not a test of the ability itself. Stage 1 answers run 1 to 5, Stage 3 answers 0 to 5. The projection to 90 uses published average losses for each ability, age and sex (about 5 to 34% a decade): an estimate.'") !== -1
  && /\(s2\.choices\.stage3_done \? '' : ' Stage 3 not finished: based on Stage 1 answers only\.'\)/.test(cle),
  'practitioner caption (v0.48.11: says what need means; v0.48.13: per-ability rates)');

// ── Style rules on this page (dyslexia: no capitals, italics, centring) ──
const css = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'css', 'hdlv2-form.css'), 'utf8');
const whyRules = css.replace(/\/\*[\s\S]*?\*\//g, '').match(/[^{}]*\.hdlv2-why[^{}]*\{[^}]*\}/g) || [];
ok(whyRules.length > 0, 'the page has its own rules under .hdlv2-why');
const bad = whyRules.filter(function (r) {
  const decl = r.slice(r.indexOf('{'));
  return /text-transform:\s*uppercase|font-style:\s*italic|text-align:\s*(center|justify)|letter-spacing:\s*0?\.\d/.test(decl)
    && !/hdlv2-why-chart(\s|\{|,)/.test(r.slice(0, r.indexOf('{')));
});
ok(bad.length === 0, 'under .hdlv2-why no rule sets capitals, italics, letter-spacing, centred or justified text' + (bad.length ? ' — ' + bad.join(' | ') : ''));
const stmt = whyRules.filter(function (r) { return /\.hdlv2-why \.hdlv2-checkbox-item\s*\{/.test(r); }).join('');
ok(/font-size:\s*17px/.test(stmt) && /min-height:\s*60px/.test(stmt) && /padding:\s*16px;/.test(stmt), 'statement text is 17px, at least 60px high, padding 16px (0.48.2: on the 8px scale)');
// v0.48.1 — room (Design 1).
const flat = css.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\s+/g, ' ');
ok(/\.hdlv2-assessment-root\.is-picks \{ max-width: 1080px; \}/.test(flat), 'is-picks card is up to 1080px wide');
ok(/\.hdlv2-why \.hdlv2-form-body \{ padding: 0 16px 24px; \}/.test(flat) && /@media \(min-width: 760px\) \{[^@]*\.hdlv2-why \.hdlv2-form-body \{ padding: 0 24px 32px; \}/.test(flat)
  && /@media \(min-width: 1000px\) \{[^@]*\.hdlv2-why \.hdlv2-form-body \{ padding: 0 32px 32px; \}/.test(flat),
  'card sides: 16px below 760px, 24px from 760px (columns stay 230px+ at 820), 32px from 1000px');
ok(/window\.addEventListener\('resize', redrawRadar\)/.test(body('renderWhyPicks')) && /redrawRadar\(\)/.test(body('updatePicks')),
  'the radar is redrawn at its real width on every tap and when the screen turns round');
ok(/\.hdlv2-why-groups \{ display: grid; gap: 24px; \}/.test(flat) && /@media \(min-width: 760px\) \{ \.hdlv2-why-groups \{ grid-template-columns: repeat\(3, minmax\(0, 1fr\)\); gap: 16px; \} \}/.test(flat)
  && /@media \(min-width: 1000px\) \{ \.hdlv2-why-groups \{ gap: 24px; \} \}/.test(flat), 'groups: 24px stacked, 16px at 760-999, 24px from 1000');
ok(/\.hdlv2-why \.hdlv2-picks-grid \{[^}]*gap: 16px/.test(flat), 'statements 16px apart');
ok(/\.hdlv2-why \.hdlv2-header p \{[^}]*margin: 0 0 24px;/.test(flat), '24px between the opening line and the groups');
ok(/\.hdlv2-why \.hdlv2-subgroup-heading \{[^}]*margin: 0 0 16px/.test(flat), '16px under each group heading');
ok(/\.hdlv2-why \.hdlv2-picks-count \{[^}]*margin: 24px 0 8px/.test(flat), '24px above the count line');
ok(/\.hdlv2-why \.hdlv2-field \{ margin-top: 32px; \}/.test(flat), '32px above each field under the charts');
ok(/\.hdlv2-why-needs \{[^}]*margin: 32px 0 0[^}]*padding: 24px[^}]*border: 1px solid #e4e6ea[^}]*border-radius: 16px/.test(flat), 'the section panel: 32px above, padding 24px, 1px #e4e6ea, radius 16px');
ok(/@media \(min-width: 900px\) \{ \.hdlv2-why-needs-body \{ grid-template-columns: repeat\(2, minmax\(0, 1fr\)\); gap: 32px;[^}]*\} \}/.test(flat), 'bars and radar side by side from 900px, 32px apart');
ok(/\.hdlv2-why-wheel-svg \{[^}]*max-width: 440px/.test(flat), 'radar up to 440px wide');
ok(/\.hdlv2-why-needs h3 \{[^}]*font-size: 20px[^}]*font-weight: 700[^}]*color: #004F59/.test(flat)
  && /\.hdlv2-why-needs p \{[^}]*font-size: 17px[^}]*color: #2c3e50/.test(flat)
  && /\.hdlv2-why \.hdlv2-why-caption \{[^}]*font-size: 16px[^}]*color: #666/.test(flat), 'section type: heading 20px bold #004F59, lines 17px #2c3e50, caption 16px #666');
ok(/\.hdlv2-ability-meter \{[^}]*height: 10px/.test(flat) && /\.hdlv2-ability-name \{[^}]*font-size: 17px[^}]*font-weight: 600/.test(flat) && /\.hdlv2-ability-count \{[^}]*font-size: 16px[^}]*color: #666/.test(flat),
  'bars: name 17px 600, count 16px #666, meter 10px');
ok(!/hdlv2-why-wheel \{|hdlv2-why-chart/.test(flat), 'the wedge chart styles are gone');
ok(/\.hdlv2-why \.hdlv2-subgroup-heading\s*\{[^}]*text-transform:\s*none/.test(css) && /\.hdlv2-why \.hdlv2-subgroup-heading\s*\{[^}]*letter-spacing:\s*0/.test(css),
  'the shared group heading has its capitals and letter-spacing undone on this page');

// ── v0.48.2: one icon family and a finished feel ──
// Design 1 — the pictures: Phosphor duotone, on the 256 grid, filled.
const iconNames = Object.keys((function () { const c = {}; try { vm.runInNewContext(ICON_SRC + '\nthis.ICONS = ICONS;', c); } catch (e) { /* reported below */ } return c.ICONS || {}; })());
ok(J(iconNames.sort()) === J(['balance', 'connection', 'freedom', 'mind', 'mobility', 'people', 'stamina', 'strength', 'things']),
  'ICONS holds exactly the nine names (6 abilities + 3 groups)');
const ICON_NOTE = src.slice(Math.max(0, src.indexOf('  var ICONS = {') - 500), src.indexOf('  var ICONS = {'));
ok(/Phosphor/.test(ICON_NOTE) && /MIT/.test(ICON_NOTE) && !/itshover|Tabler/i.test(ICON_NOTE + ICON_SRC), 'the ICONS comment names Phosphor and MIT; no itshover or Tabler left');
ok(!/stroke-linecap|\d -\d|M\d+ \d+[lh]/.test(ICON_SRC) && (ICON_SRC.match(/opacity="0\.2"/g) || []).length === 9,
  'no stroke-only 24-grid path is left; each picture keeps its duotone 0.2 layer');
const oneIcon = (function () { const c = {}; try { vm.runInNewContext(ICON_SRC + '\n' + body('pickIcon') + '\nthis.out = pickIcon("strength");', c); } catch (e) { /* reported below */ } return c.out || ''; })();
ok(/viewBox="0 0 256 256"/.test(oneIcon) && /fill="currentColor"/.test(oneIcon) && !/stroke=/.test(oneIcon) && /width="28" height="28"/.test(oneIcon)
  && /aria-hidden="true"/.test(oneIcon), 'pickIcon(): 28px, viewBox 0 0 256 256, fill currentColor, no stroke, aria-hidden');

// Design 2 — a real tick control on each statement, in both states.
const tickCount = (html.match(/<span class="hdlv2-tick" aria-hidden="true">/g) || []).length;
ok(tickCount === 21, 'each of the 21 statements carries the tick circle (got ' + tickCount + ')');
const whyFlat = flat;
ok(!/\.hdlv2-why \.hdlv2-checkbox-item\.selected::after/.test(whyFlat) && whyFlat.indexOf("'\\2713'") === -1, 'the selected rule no longer uses a text glyph');
const selRule = (whyFlat.match(/\.hdlv2-why \.hdlv2-checkbox-item\.selected \{[^}]*\}/) || [''])[0];
ok(selRule !== '' && !/border(-width)?:\s*\d/.test(selRule), 'a chosen statement changes colour only, never border width');
ok(/\.hdlv2-why \.hdlv2-tick \{[^}]*width: 22px[^}]*height: 22px[^}]*border: 1\.5px solid #888[^}]*border-radius: 50%/.test(whyFlat)
  && /\.hdlv2-why \.hdlv2-checkbox-item\.selected \.hdlv2-tick \{[^}]*background: #3d8da0[^}]*border-color: #3d8da0/.test(whyFlat),
  'tick: 22px ring #888 (3.5:1 on white), filled #3d8da0 when chosen');
ok(/\.hdlv2-why \.hdlv2-checkbox-item \{[^}]*border-radius: 14px/.test(whyFlat), 'statements radius 14px');
ok(/\.hdlv2-why \.hdlv2-checkbox-item:not\(:has\(input:disabled\)\):active \{ transform: scale\(0\.985\); \}/.test(whyFlat) && /\.hdlv2-why \.hdlv2-checkbox-item \{[^}]*transition: transform 80ms/.test(whyFlat),
  'press: the statement eases to 0.985 over 80ms');
ok(!/\.hdlv2-why \.hdlv2-checkbox-item:active \{ transform: scale/.test(whyFlat), 'P7: a statement switched off at ten picks does not squeeze on press');

// Design 3 — the bars.
ok(/\.hdlv2-ability-meter span \{[^}]*transition: width 240ms/.test(whyFlat), 'meter width eases over 240ms');
ok(/\.hdlv2-ability-count \{[^}]*font-variant-numeric: tabular-nums[^}]*text-align: right/.test(whyFlat), 'count in tabular numerals, right aligned');
ok(/\.hdlv2-ability \{[^}]*grid-template-columns: 28px/.test(whyFlat) && /\.hdlv2-ability \{[^}]*min-height: 56px/.test(whyFlat), 'bar rows 56px, 28px picture column');
if (typeof abilityCounts === 'function') {
  const TIE = [{ id: 'g', title: 'G', items: [{ id: 'a', needs: ['mind', 'strength', 'stamina'] }, { id: 'b', needs: ['balance'] }] }];
  ok(J(abilityCounts(['a', 'b'], TIE, SHORT).map(function (r) { return r.id; })) === J(['strength', 'balance', 'stamina', 'mind']),
    'equal needs keep the server\'s order (Strength, Balance, Stamina, Mind); a tag with no weight reads as 2');
}
const upd = body('updatePicks');
ok(/data-id/.test(body('abilityBars')) && /offsetWidth/.test(upd), 'a tap starts each meter at its old width so the change eases (not a fresh draw)');

// Design 4 — the radar lined up.
ok(/@media \(min-width: 900px\) \{[^@]*\.hdlv2-why-needs-body \{[^}]*align-items: center/.test(whyFlat), 'from 900px the radar is centred against the bars');
ok(/IC = 28/.test(body('radarChart')) && /IC \+ 6/.test(body('radarChart')), 'radar: 28px pictures, each name 6px under its picture');

// Design 5 — one finish.
const scaleBad = [];
(whyFlat.match(/[^{}]*\{[^{}]*\}/g) || []).forEach(function (r) {
  const sel = r.slice(0, r.indexOf('{'));
  if (!/\.hdlv2-why|\.hdlv2-ability/.test(sel)) return;
  (r.match(/(?:^|[ ;{])(?:margin|padding|gap|row-gap|column-gap)(?:-[a-z]+)?:\s*[^;}]+/g) || []).forEach(function (d) {
    (d.match(/-?\d+(?:\.\d+)?px/g) || []).forEach(function (v) { if ([0, 8, 16, 24, 32, 48].indexOf(Math.abs(parseFloat(v))) === -1) scaleBad.push(sel.trim() + ' → ' + d.trim()); });
  });
});
ok(scaleBad.length === 0, 'every margin, gap and padding on this page is 8, 16, 24, 32 or 48px' + (scaleBad.length ? ' — ' + scaleBad.join(' | ') : ''));
const whyAll = (whyFlat.match(/[^{}]*\{[^{}]*\}/g) || []).filter(function (r) { return /\.hdlv2-why|\.hdlv2-ability/.test(r.slice(0, r.indexOf('{'))); });
ok(whyAll.every(function (r) { return !/box-shadow:(?!\s*none)/.test(r); }) && /\.hdlv2-card\.hdlv2-why \{[^}]*box-shadow: none/.test(whyFlat), 'one elevation: no shadow on this page (the shared card shadow is removed)');
ok(whyAll.every(function (r) { return !/gradient\(/.test(r); }), 'no gradient on this page');
ok(/\.hdlv2-why \.hdlv2-header h2 \{[^}]*font-family: 'Poppins'[^}]*font-size: 28px[^}]*line-height: 1\.2[^}]*letter-spacing: -0\.01em/.test(whyFlat), 'title Poppins 28px, line height 1.2, -0.01em');
ok(/\.hdlv2-why \.hdlv2-header p \{[^}]*font-size: 18px/.test(whyFlat), 'opening line 18px');
const rm = (whyFlat.match(/@media \(prefers-reduced-motion: reduce\) \{(.*?\}) \}/) || ['', ''])[1];
ok(/\.hdlv2-why \.hdlv2-checkbox-item/.test(rm) && /\.hdlv2-why \.hdlv2-tick/.test(rm) && /\.hdlv2-ability-meter span/.test(rm) && /transition: none/.test(rm) && /transform: none/.test(rm),
  'reduced motion: every transition and transform on this page is off');

ok(!/hdlv2-why-empty/.test(css + src), 'no styles or markup left for the old empty line');
ok(/\.hdlv2-why-needs\[hidden\] \{ display: none; \}/.test(flat), 'the hidden section really hides (a page reset sets display on section)');

const submit = body('submitWhyPicks');
ok(submit !== '', 'submitWhyPicks() exists');
// v0.48.5 — the slice-1 byte pin gave way to two named changes (G2, P6).
ok(/if \(queuedSaveTimer\) \{ clearTimeout\(queuedSaveTimer\); queuedSaveTimer = null; \}/.test(submit), 'G2: Next drops a queued autosave so it cannot land after the submit');
ok(/prefers-reduced-motion: reduce/.test(submit) && /'auto'/.test(submit), 'P6: Next scrolls without the smooth glide when reduced motion is asked for');
ok(/submitted:\s*true/.test(submit), 'Next sends the submitted save');
ok(/loadForm\(\)/.test(submit), 'Next goes on to loadForm() (the health sections)');
ok(!/renderStage2ThankYou/.test(submit), 'Next never shows the Stage 2 thank-you card');
ok(!/\b(alert|confirm|prompt)\(/.test(submit), 'no native popups in the submit path');
ok(/Connection error/.test(submit), 'network failure says "Connection error"');

const wiz = body('renderWizardSection');
ok(/isSingleFlow\(\)/.test(wiz), 'wizard reads the single-flow marker for dots + header');
ok(/Your Full Report/.test(wiz), 'single-flow header reads "Your Full Report" (v0.48.15, H1)');

const s1 = body('renderStage1Result');
ok(/single_form/.test(s1) && /Continue your assessment/.test(s1), 'Stage 1 result has the covered-row copy');
ok(src.indexOf('Continue to your Full Report \\u2192') !== -1, 'Stage 1 result: other rows continue to the Full Report (v0.48.15, H1)');

// ── v0.47.99: a pick clears the "choose at least 5" line ──
// Runs the REAL clearSaveError() + setSaveStatus() against a fake save line.
const line = { className: '', textContent: '' };
const lineCtx = { document: { getElementById: (id) => (id === 'hdlv2-save-indicator' ? line : null) } };
try { vm.runInNewContext(body('setSaveStatus') + '\n' + body('clearSaveError') + '\nthis.setSaveStatus = setSaveStatus; this.clearSaveError = clearSaveError;', lineCtx); } catch (e) { /* reported below */ }
if (typeof lineCtx.clearSaveError === 'function') {
  lineCtx.setSaveStatus('error', 'Please choose at least 5.');
  lineCtx.clearSaveError();
  ok(line.className === 'hdlv2-save-status' && line.textContent === '', 'after a refused Next, the next pick clears the red line');
  lineCtx.setSaveStatus('saved', 'Saved');
  lineCtx.clearSaveError();
  ok(line.className === 'hdlv2-save-status saved' && line.textContent === 'Saved', 'a "Saved" line is left alone by a pick');
} else ok(false, 'clearSaveError() exists');
ok(/updatePicks\(\);\s*clearSaveError\(\);/.test(body('renderWhyPicks')), 'the pick handler calls clearSaveError() right after updatePicks()');

// ── v0.48.5: gate fixes (2026-10-07) ──
// G6 + P5 + G2 — the page wiring, run for real against a small fake page.
function wiringHarness() {
  const H = { clock: 0, timers: [], saves: [], widths: {} };
  const cbs = [];
  const root = { innerHTML: '', scrollIntoView() {},
    querySelectorAll(sel) { return /checkbox/.test(sel) ? cbs : []; } };
  Object.defineProperty(root, 'innerHTML', { get() { return this._h || ''; }, set(h) {
    this._h = h; cbs.length = 0;
    (h.match(/<input type="checkbox" value="[^"]*"[^>]*>/g) || []).forEach(function (tag) {
      const ls = [];
      const cb = { value: tag.match(/value="([^"]*)"/)[1], checked: / checked/.test(tag), disabled: false,
        parentNode: { classList: { toggle() {} } }, addEventListener(t, f) { if (t === 'change') ls.push(f); },
        fire() { ls.forEach(function (f) { f({ target: cb }); }); } };
      cbs.push(cb);
    });
  } });
  function el() { return { hidden: false, textContent: '', clientWidth: 440, attrs: {}, setAttribute(k, v) { this.attrs[k] = String(v); }, getAttribute(k) { return this.attrs[k]; }, addEventListener() {}, innerHTML: '' }; }
  const bars = el(), rows = [];
  Object.defineProperty(bars, 'innerHTML', { get() { return ''; }, set(h) {
    rows.length = 0;
    h.split('role="listitem"').slice(1).forEach(function (part) {
      const id = part.match(/data-id="([^"]*)"/)[1];
      const w = { v: part.match(/width:(\d+%)/)[1] };
      const style = {}; Object.defineProperty(style, 'width', { get() { return w.v; }, set(v) { w.v = v; (H.widths[id] = H.widths[id] || []).push(v); } });
      rows.push({ getAttribute() { return id; }, querySelector() { return { style: style, offsetWidth: 0 }; } });
    });
  } });
  bars.querySelectorAll = function () { return rows; };
  const ids = { 'hdlv2-picks-count': el(), 'hdlv2-why-needs': el(), 'hdlv2-abilities': bars, 'hdlv2-why-radar': el(), 'hdlv2-picks-next': el() };
  const sb = {
    H, root, formData: {}, serverData: {}, saveTimer: null, queuedSaveTimer: null, saving: false,
    document: { getElementById: (id) => ids[id] || null }, window: { addEventListener() {} },
    setTimeout: (f, ms) => { const t = { at: H.clock + ms, f }; H.timers.push(t); return t; },
    clearTimeout: (t) => { H.timers = H.timers.filter((x) => x !== t); },
    setRootClasses() {}, saveIndicator() { return ''; }, footer() { return ''; }, bindFieldListeners() {}, submitWhyPicks() {}, clearSaveError() {},
    // Like the real autoSave(): a call while a save is in flight is dropped.
    autoSave(stage) { if (sb.saving) return; H.saves.push({ stage: stage, picks: JSON.stringify(sb.formData.why_picks) }); },
    esc: pageCtx.esc
  };
  try {
    vm.runInNewContext(limits + '\n' + ICON_SRC + '\n' + (src.match(/  var TICK = [\s\S]*?;\n/) || [''])[0] + (src.match(/  var radarShown = [^\n]*\n/) || [''])[0] + PAGE_FNS.map(body).join('\n') + '\n' + body('saveWhenFree')
      + '\nthis.renderWhyPicks = renderWhyPicks; this.formData = formData;', sb);
  } catch (e) { console.log('      wiring harness threw: ' + e.message); }
  H.advance = (ms) => {
    const end = H.clock + ms;
    for (;;) { H.timers.sort((a, b) => a.at - b.at); const t = H.timers[0]; if (!t || t.at > end) break; H.timers.shift(); H.clock = t.at; t.f(); }
    H.clock = end;
  };
  H.cb = (v) => cbs.filter((c) => c.value === v)[0];
  return { H, sb, root, ids, cbs };
}
const hostile = '<img src=x onerror=alert(1)>"';
function page8(title) {
  const items = [];
  for (let i = 0; i < 8; i++) items.push({ id: title + i, label: (i === 0 ? hostile : title + ' statement ' + i), needs: [['strength', 'balance', 'mind', 'stamina'][i % 4]] });
  return { id: title, title: title === 'people' ? hostile : title, items: items };
}
const WDATA = { why_options: [page8('people'), page8('freedom'), page8('things')], why_abilities: ABILITIES, why_ability_short: SHORT };
if (typeof body('saveWhenFree') === 'string' && body('renderWhyPicks')) {
  let w = wiringHarness();
  w.sb.serverData = WDATA;
  w.sb.formData = { why_picks: ['people1', 'freedom2', 'retired_or_typed'], key_people_text: '</textarea><script>x</script>' };
  try { vm.runInNewContext('formData = this.fd; renderWhyPicks(this.wd);', Object.assign(w.sb, { fd: w.sb.formData, wd: WDATA })); } catch (e) { console.log('      render threw: ' + e.message); }
  const h = w.root.innerHTML;
  ok(h !== '' && h.indexOf('<img') === -1 && h.indexOf('&lt;img src=x onerror=alert(1)&gt;&quot;') !== -1, 'G6: a hostile statement label and group title are escaped');
  ok(h.indexOf('<script>') === -1 && h.indexOf('&lt;/textarea&gt;&lt;script&gt;') !== -1, 'G6: a hostile name in the people box is escaped');
  ok(w.H.cb('people1') && w.H.cb('people1').checked && w.H.cb('freedom2').checked && !w.H.cb('people2').checked, 'G6: saved picks come back ticked');
  ok(JSON.stringify(vm.runInNewContext('formData.why_picks', w.sb)) === '["people1","freedom2"]', 'G6: an id not on offer is dropped on render');
  ok(w.ids['hdlv2-picks-count'].textContent === '2 picked. Pick at least 5.', 'G6: the count line shows on render');

  // A pick: stored, the count moves, a save is scheduled through saveWhenFree(2).
  const c = w.H.cb('people2'); c.checked = true; c.fire();
  ok(JSON.stringify(vm.runInNewContext('formData.why_picks', w.sb)) === '["people1","freedom2","people2"]', 'G6: the change handler stores the pick');
  w.H.advance(1499);
  ok(w.H.saves.length === 0, 'G6: no save before 1.5 s');
  w.H.advance(1);
  ok(w.H.saves.length === 1 && w.H.saves[0].stage === 2, 'G6: one stage-2 save at 1.5 s');
  // P5 — the meters ease from their old width (a new row from 0%) to need ÷ 5.
  // picks people1 (balance) + freedom2 (mind), bare tags = weight 2: each need 1.7 (34%);
  // then people2 (mind): mind 2.2 (44%), balance 1.1 (22%).
  ok(JSON.stringify((w.H.widths.mind || []).slice(-2)) === '["34%","44%"]', 'P5: the mind meter starts at its old width (34%) and eases to 44% (got ' + JSON.stringify(w.H.widths.mind) + ')');
  ok(JSON.stringify((w.H.widths.balance || []).slice(-2)) === '["34%","22%"]', 'P5: the balance meter eases from 34% to 22% (got ' + JSON.stringify(w.H.widths.balance) + ')');

  // The eleventh pick is refused, and the box stays unticked.
  w = wiringHarness();
  w.sb.serverData = WDATA;
  const ten = ['people1', 'people2', 'people3', 'people4', 'people5', 'people6', 'people7', 'freedom1', 'freedom2', 'freedom3'];
  try { vm.runInNewContext('formData = this.fd; renderWhyPicks(this.wd);', Object.assign(w.sb, { fd: { why_picks: ten.slice() }, wd: WDATA })); } catch (e) { /* reported below */ }
  const eleventh = w.H.cb('things1');
  ok(eleventh && eleventh.disabled === true, 'G6: at ten picks the other statements are switched off');
  if (eleventh) { eleventh.checked = true; eleventh.fire(); }
  ok(vm.runInNewContext('formData.why_picks.length', w.sb) === 10 && eleventh && eleventh.checked === false, 'G6: an eleventh pick is refused and its box unticked');

  // G2 — pick then un-pick while a slow save is in flight: the last state is sent, once.
  w = wiringHarness();
  w.sb.serverData = WDATA;
  try { vm.runInNewContext('formData = this.fd; renderWhyPicks(this.wd);', Object.assign(w.sb, { fd: { why_picks: ['people1'] }, wd: WDATA })); } catch (e) { /* reported below */ }
  vm.runInNewContext('saving = true', w.sb); w.sb.saving = true;
  const a = w.H.cb('people2'); a.checked = true; a.fire();
  w.H.advance(1600);
  a.checked = false; a.fire();
  w.H.advance(1600);
  vm.runInNewContext('saving = false', w.sb); w.sb.saving = false;
  w.H.advance(2000);
  ok(w.H.saves.length === 1 && w.H.saves[0].picks === '["people1"]', 'G2: a pick and un-pick during a slow save end in one save of the last state (got ' + JSON.stringify(w.H.saves) + ')');
  ok(w.H.timers.length === 0, 'G2: nothing left waiting');
} else ok(false, 'saveWhenFree() and renderWhyPicks() exist');

// G7 — the refusal line is announced.
ok(/saveIndicator\(true\)/.test(body('renderWhyPicks')), 'G7: the choices page save line is a spoken status (role="status")');

// P1 — the Phosphor licence in full beside the pictures.
const LIC = src.slice(Math.max(0, src.indexOf('  var ICONS = {') - 2500), src.indexOf('  var ICONS = {'));
ok(LIC.indexOf('Copyright (c) 2023 Phosphor Icons') !== -1 && LIC.indexOf('Permission is hereby granted, free of charge, to any person obtaining a copy') !== -1
  && LIC.indexOf('The above copyright notice and this permission notice shall be included in all') !== -1 && LIC.indexOf('THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR') !== -1,
  'P1: Phosphor\'s copyright line and the MIT permission notice sit above ICONS');

// P2 — the tick follows the text colour (high-contrast themes keep it).
const tickSrc = (src.match(/  var TICK = [\s\S]*?;\n/) || [''])[0];
ok(/fill="currentColor"/.test(tickSrc) && !/fill="#fff"/.test(tickSrc), 'P2: the tick is drawn in currentColor');
ok(/\.hdlv2-why \.hdlv2-tick \{[^}]*color: #fff/.test(whyFlat), 'P2: the tick circle sets color #fff');

// P3, P6, P8, G8 — the stylesheet.
ok(/\.hdlv2-why \.hdlv2-subgroup-heading:first-child \{ margin-top: 0; \}/.test(whyFlat), 'P3: the first group heading has no 4px top margin');
ok(/\.hdlv2-why \.hdlv2-btn/.test(rm) && /\.hdlv2-why \.hdlv2-field textarea/.test(rm), 'P6: reduced motion also stops the Next fade and the text-box border fade');
ok(/@media \(hover: hover\) \{ \.hdlv2-checkbox-item:hover \{ border-color: #3d8da0; \}/.test(whyFlat) && !/(^|\} )\.hdlv2-checkbox-item:hover \{/.test(whyFlat.replace(/@media \(hover: hover\) \{[^@]*?\} \}/, '')),
  'P8: the hover border only where a real pointer hovers');
ok(/\.hdlv2-checkbox-item:focus-within \{ outline: 2px solid #3d8da0; outline-offset: 2px; \}/.test(whyFlat)
  && /\.hdlv2-checkbox-item:has\(input:focus:not\(:focus-visible\)\) \{ outline: none; \}/.test(whyFlat)
  && whyFlat.indexOf('.hdlv2-checkbox-item:focus-within') < whyFlat.indexOf('.hdlv2-checkbox-item:has(input:focus:not(:focus-visible))'),
  'G8: a :focus-within ring for browsers without :has(), hidden again for mouse focus where :has() works');

// v0.48.13 — the radar eases to the new shape (Quim, 2026-10-10) and lands exactly on it.
{
  const frames = [];
  const radarEl = { clientWidth: 400, innerHTML: '', isConnected: true, attrs: {}, getAttribute: function (k) { return this.attrs[k]; }, setAttribute: function (k, v) { this.attrs[k] = String(v); } };
  const tctx = Object.assign({}, pageCtx, {
    document: { getElementById: function (id) { return id === 'hdlv2-why-radar' ? radarEl : fakeEl(); } },
    window: { requestAnimationFrame: function (f) { frames.push(f); }, matchMedia: function () { return { matches: false }; } }
  });
  const fns = ['abilityCounts', 'radarData', 'chartLabel', 'pickIcon', 'radarChart', 'redrawRadar', 'reducedMotion'];
  vm.runInNewContext(ICON_SRC + '\n' + (src.match(/  var radarShown = [^\n]*\n/) || [''])[0] + fns.map(body).join('\n')
    + '\nthis.redrawRadar = redrawRadar; this.radarChart = radarChart; this.radarData = radarData; this.abilityCounts = abilityCounts;', tctx);
  const opts = [{ id: 'g', title: 'G', items: [{ id: 'a', needs: ['strength'], weights: { strength: 3 } }, { id: 'b', needs: ['balance'], weights: { balance: 3 } }] }];
  tctx.serverData = { why_options: opts, why_abilities: ABILITIES, why_ability_short: SHORT };
  tctx.formData = { why_picks: ['a'] };
  tctx.redrawRadar();
  const first = radarEl.innerHTML;
  ok(first !== '' && frames.length === 0, 'radar: the first drawing is immediate (nothing to ease from)');
  tctx.formData = { why_picks: ['b'] };
  tctx.redrawRadar();
  const finalSvg = tctx.radarChart(tctx.radarData(tctx.abilityCounts(['b'], opts, SHORT), SHORT), 400);
  frames.shift()(0); frames.shift()(120);
  const mid = radarEl.innerHTML;
  ok(mid !== first && mid !== finalSvg, 'radar: a tap eases through in-between shapes (not an instant jump)');
  while (frames.length) frames.shift()(1000);
  ok(radarEl.innerHTML === finalSvg, 'radar: the last frame is exactly the new shape');
  // A tap mid-ease starts from the shape on screen, not the old target (review 0.48.13).
  let clock = 10000;
  const step = function (dt) { clock += dt; const run = frames.splice(0); run.forEach(function (f) { f(clock); }); };
  tctx.formData = { why_picks: ['a'] };
  tctx.redrawRadar();
  step(0); step(100);
  const onScreen = radarEl.innerHTML;
  tctx.formData = { why_picks: ['a', 'b'] };
  tctx.redrawRadar();
  step(0);
  ok(onScreen !== finalSvg && radarEl.innerHTML === onScreen, 'radar: a tap mid-ease continues from the shape on screen (no snap)');
  for (let i = 0; i < 20 && frames.length; i++) step(100);
  ok(frames.length === 0 && radarEl.innerHTML === tctx.radarChart(tctx.radarData(tctx.abilityCounts(['a', 'b'], opts, SHORT), SHORT), 400), 'radar: and still lands exactly on the newest shape');
  tctx.window.matchMedia = function () { return { matches: true }; };
  tctx.formData = { why_picks: ['a'] };
  tctx.redrawRadar();
  ok(frames.length === 0 && radarEl.innerHTML === tctx.radarChart(tctx.radarData(tctx.abilityCounts(['a'], opts, SHORT), SHORT), 400), 'radar: reduced motion → no easing, straight to the new shape');
}
ok(/row\.classList\.add\('is-moving'\)/.test(body('updatePicks')) && /\.hdlv2-ability\.is-moving \{ transition: transform 300ms ease-out; \}/.test(fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'css', 'hdlv2-form.css'), 'utf8')),
  'bars: a row that changes place slides there (FLIP), off under reduced motion');

console.log('\n' + PASS + ' passed, ' + FAIL + ' failed');
process.exit(FAIL ? 1 : 0);
