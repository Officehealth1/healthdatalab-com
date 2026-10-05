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

const GROUPS = [
  { id: 'people', items: [{ id: 'a', needs: ['mobility', 'flexibility', 'strength'] }, { id: 'b', needs: ['strength', 'balance'] }] },
  { id: 'freedom', items: [{ id: 'c', needs: ['stamina'] }, { id: 'd', needs: ['stamina', 'balance'] }] },
  { id: 'things', items: [{ id: 'e', needs: ['mind'] }] }
];
const ABILITIES = { strength: 'Strength', mobility: 'Mobility', flexibility: 'Flexibility', balance: 'Balance',
  stamina: 'Stamina', mind: 'A sharp mind', energy: 'Energy and sleep', connection: 'Connection' };
const SHORT = { strength: 'Strength', mobility: 'Mobility', flexibility: 'Flexibility', balance: 'Balance',
  stamina: 'Stamina', mind: 'Mind', energy: 'Energy', connection: 'Connection' };
const J = JSON.stringify;

if (typeof abilityCounts === 'function') {
  ok(J(abilityCounts(['a', 'b', 'd', 'e'], GROUPS, ABILITIES)) === J([
    { id: 'strength', label: 'Strength', count: 2 }, { id: 'balance', label: 'Balance', count: 2 },
    { id: 'mobility', label: 'Mobility', count: 1 }, { id: 'flexibility', label: 'Flexibility', count: 1 },
    { id: 'stamina', label: 'Stamina', count: 1 }, { id: 'mind', label: 'A sharp mind', count: 1 }
  ]), 'ability counts and order match the hand-worked case');
  ok(J(abilityCounts([], GROUPS, ABILITIES)) === '[]' && J(abilityCounts(null, GROUPS, ABILITIES)) === '[]', 'no picks → empty list');
  ok(J(abilityCounts(['zzz', 'c'], GROUPS, ABILITIES)) === J([{ id: 'stamina', label: 'Stamina', count: 1 }]), 'an id not on offer counts for nothing');
}

// The count line, in its three states (Design 3).
if (typeof pickCountLine === 'function') {
  ok(pickCountLine(0) === '0 picked. Pick at least 5.', 'count line: none picked');
  ok(pickCountLine(3) === '3 picked. Pick at least 5.', 'count line: under 5');
  ok(pickCountLine(6) === '6 picked.', 'count line: 5 to 9');
  ok(pickCountLine(10) === '10 picked. That is the most.', 'count line: at 10');
} else ok(false, 'pickCountLine() exists');

// v0.48.1 — the radar's numbers: every ability in the server's order with its
// count, and the scale top = the larger of 3 and the highest count.
ok(body('wedgeSizes') === '' && body('polarChart') === '' && !/wedgeSizes|polarChart|id="hdlv2-why-wheel"/.test(src),
  'the wedge chart is gone: no wedgeSizes(), no polarChart(), no wedge styles hooks');
if (typeof radarData === 'function') {
  const zero = radarData([], SHORT);
  ok(zero.top === 3 && J(zero.spokes.map(function (s) { return s.id; })) === J(Object.keys(SHORT)) && zero.spokes.every(function (s) { return s.count === 0; }),
    'radar data: nothing picked → eight abilities at 0 in the server\'s order, top 3');
  const one = radarData([{ id: 'stamina', count: 1 }], SHORT);
  ok(one.top === 3 && one.spokes[4].id === 'stamina' && one.spokes[4].count === 1 && one.spokes.filter(function (s) { return s.count > 0; }).length === 1,
    'radar data: one ability with one pick → count 1, top stays 3 (the floor)');
  const floor = radarData([{ id: 'balance', count: 1 }, { id: 'strength', count: 2 }], SHORT);
  ok(floor.top === 3 && floor.spokes[0].count === 2 && floor.spokes[3].count === 1, 'radar data: highest count 2 → top is still 3; input order does not matter');
  const big = radarData([{ id: 'strength', count: 5 }, { id: 'balance', count: 2 }], SHORT);
  ok(big.top === 5 && big.spokes[0].count === 5, 'radar data: a count above 3 becomes the top');
  ok(big.spokes[0].label === 'Strength' && big.spokes[5].label === 'Mind', 'radar data carries the short names');
} else ok(false, 'radarData() exists');

if (typeof chartLabel === 'function' && typeof radarData === 'function') {
  ok(chartLabel(radarData([], SHORT).spokes) === 'Nothing picked yet.', 'chart aria-label with no picks');
  ok(chartLabel(radarData([{ id: 'balance', count: 1 }, { id: 'strength', count: 2 }], SHORT).spokes) === 'What your picks need: Strength 2, Balance 1.',
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
  const rows = [{ id: 'strength', count: 2 }, { id: 'balance', count: 1 }, { id: 'mind', count: 1 }];
  const svg = rctx.radarChart(rctx.radarData(rows, SHORT), 300);
  ok((svg.match(/class="hdlv2-why-ring"/g) || []).length === 3, 'radar: one ring per whole number up to the top (3)');
  ok((svg.match(/class="hdlv2-why-axis"/g) || []).length === 8, 'radar: eight spokes');
  ok(/class="hdlv2-why-ring"[^>]*stroke="rgba\(0,0,0,0\.08\)"/.test(svg) && /class="hdlv2-why-axis"[^>]*stroke="rgba\(0,0,0,0\.08\)"/.test(svg),
    'radar: web lines and spokes rgba(0,0,0,0.08), as on the report');
  ok(/<polygon class="hdlv2-why-ring"/.test(svg) && !/<circle class="hdlv2-why-ring"/.test(svg), 'radar: rings are straight lines between spokes (polygons), not circles');
  const shape = (svg.match(/<polygon class="hdlv2-why-shape"[^>]*>/) || [''])[0];
  ok(/fill="rgba\(61, 141, 160, 0\.15\)"/.test(shape) && /stroke="rgba\(61, 141, 160, 0\.9\)"/.test(shape) && /stroke-width="2"/.test(shape) && /stroke-linejoin="round"/.test(shape),
    'radar: shape fill 0.15 teal, outline 0.9 teal, 2 wide, round joins');
  const ptsAttr = (shape.match(/points="([^"]+)"/) || ['', ''])[1].trim().split(/\s+/).map(function (p) { return p.split(',').map(Number); });
  const cx = Number((svg.match(/data-cx="([^"]+)"/) || [])[1]), cy = Number((svg.match(/data-cy="([^"]+)"/) || [])[1]), R = Number((svg.match(/data-r="([^"]+)"/) || [])[1]);
  const dist = ptsAttr.map(function (p) { return Math.round(Math.hypot(p[0] - cx, p[1] - cy) / R * 300) / 300; });
  ok(ptsAttr.length === 8 && J(dist) === J([2 / 3, 0, 0, 1 / 3, 0, 1 / 3, 0, 0].map(function (v) { return Math.round(v * 300) / 300; })),
    'radar: each point sits at count ÷ top along its spoke (Strength 2/3, Balance 1/3, Mind 1/3, the rest at the centre)');
  ok(ptsAttr.length === 8 && Math.abs(ptsAttr[0][0] - cx) < 0.2 && ptsAttr[0][1] < cy, 'radar: the first ability (Strength) is on the upward spoke');
  const dots = svg.match(/<circle class="hdlv2-why-dot"[^>]*>/g) || [];
  ok(dots.length === 3 && dots.every(function (d) { return /r="4\.5"/.test(d) && /fill="#3d8da0"/.test(d) && /stroke="#fff"/.test(d) && /stroke-width="1\.5"/.test(d); }),
    'radar: a teal dot (r 4.5, white edge 1.5) only on the three spokes with a pick');
  ok(['Strength', 'Mobility', 'Flexibility', 'Balance', 'Stamina', 'Mind', 'Energy', 'Connection'].every(function (n) { return svg.indexOf('>' + n + '</text>') !== -1; }),
    'radar: the eight short names');
  ok((svg.match(/class="hdlv2-why-spoke has-picks"/g) || []).length === 3 && (svg.match(/class="hdlv2-why-spoke"/g) || []).length === 5,
    'radar: names with a pick are marked has-picks, the other five are not');
  ok((svg.match(/<svg class="hdlv2-why-icon"/g) || []).length === 8, 'radar: a picture at each of the eight names');
  ok(/role="img"[^>]*aria-label="What your picks need: Strength 2, Balance 1, Mind 1\."/.test(svg), 'radar: spoken label with picks');
  ok(!/ A[\d.]+ [\d.]+ 0 0 1 /.test(svg), 'radar: no wedge arcs left');
  const empty = rctx.radarChart(rctx.radarData([], SHORT), 300);
  ok(/role="img"[^>]*aria-label="Nothing picked yet\."/.test(empty) && !/hdlv2-why-dot/.test(empty) && !/hdlv2-why-shape/.test(empty)
    && (empty.match(/class="hdlv2-why-axis"/g) || []).length === 8, 'radar: nothing picked → the empty web and names, no shape, no dots');
  const five = rctx.radarChart(rctx.radarData([{ id: 'strength', count: 5 }], SHORT), 300);
  ok((five.match(/class="hdlv2-why-ring"/g) || []).length === 5, 'radar: a top of 5 draws five rings');
  ok(/<text class="hdlv2-why-tick"[^>]*>1<\/text>/.test(svg) && /<text class="hdlv2-why-tick"[^>]*>3<\/text>/.test(svg), 'radar: ring numbers beside the upward spoke');
  const vb = (svg.match(/viewBox="([^"]+)"/) || ['', ''])[1].split(' ').map(Number);
  ok(vb.length === 4 && Math.abs(vb[2] - 300) < 0.01, 'radar: drawn one unit per pixel at the width it is given (names keep their real size)');
} else ok(false, 'radarChart() exists');

// The bars, from the REAL abilityBars().
const bctx = { esc: rctx.esc };
try { vm.runInNewContext(ICON_SRC0 + '\n' + body('pickIcon') + '\n' + body('abilityBars') + '\nthis.abilityBars = abilityBars;', bctx); } catch (e) { /* reported below */ }
if (typeof bctx.abilityBars === 'function') {
  const bars = bctx.abilityBars([{ id: 'strength', label: 'Strength', count: 2 }, { id: 'balance', label: 'Balance', count: 1 }], 5);
  const rowsB = bars.split('role="listitem"').slice(1);
  ok(/role="list"/.test(bars) && rowsB.length === 2, 'bars: a list with one row per ability that has a pick');
  ok(/<svg class="hdlv2-why-icon"/.test(rowsB[0]) && rowsB[0].indexOf('>Strength<') !== -1 && rowsB[0].indexOf('2 of your picks') !== -1,
    'bars: a row holds the picture, the short name and "N of your picks"');
  ok(/class="hdlv2-ability-meter" aria-hidden="true"><span style="width:40%">/.test(rowsB[0]) && /width:20%/.test(rowsB[1]),
    'bars: the meter width is count ÷ number of picks (2 of 5 = 40%), and it is aria-hidden');
  ok(bars.indexOf('>Strength<') < bars.indexOf('>Balance<'), 'bars: in the order given (most picks first)');
  ok(bctx.abilityBars([], 0) === '<p class="hdlv2-why-empty">Tap a few above to see what they need.</p>', 'bars: nothing picked → the one empty line');
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
    classList: { add: function () {}, remove: function () {}, toggle: function () {} }, setAttribute: function () {}, addEventListener: function () {} };
}
const fakeRoot = Object.assign(fakeEl(), { querySelectorAll: function () { return []; } });
const pageCtx = {
  root: fakeRoot, formData: {}, serverData: {}, saveTimer: null, PICK_MIN: 5, PICK_MAX: 10,
  document: { getElementById: function () { return fakeEl(); } }, window: { addEventListener: function () {} },
  setRootClasses: function () {}, saveIndicator: function () { return ''; }, footer: function () { return ''; },
  bindFieldListeners: function () {}, submitWhyPicks: function () {}, clearSaveError: function () {}, autoSave: function () {},
  esc: function (s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
};
const PAGE_FNS = ['togglePick', 'canContinue', 'abilityCounts', 'pickCountLine', 'radarData', 'chartLabel', 'pickIcon', 'radarChart', 'abilityBars', 'renderWhyPicks', 'redrawRadar', 'updatePicks', 'picksTextarea'];
const ICON_SRC = (src.match(/  var ICONS = \{[\s\S]*?\n  \};/) || [''])[0];
let html = '';
try {
  vm.runInNewContext(ICON_SRC + '\n' + PAGE_FNS.map(body).join('\n') + '\nthis.renderWhyPicks = renderWhyPicks;', pageCtx);
  const g8 = function (gid, title) {
    const items = [];
    for (let i = 0; i < 8; i++) items.push({ id: gid + i, label: title + ' statement ' + i, needs: ['strength'] });
    return { id: gid, title: title, items: items };
  };
  const data = { why_options: [g8('people', 'People I love'), g8('freedom', 'My freedom'), g8('things', 'Things I love')], why_abilities: ABILITIES, why_ability_short: SHORT };
  pageCtx.serverData = data;
  pageCtx.renderWhyPicks(data);
  html = fakeRoot.innerHTML;
} catch (e) { console.log('      renderWhyPicks threw: ' + e.message); }
ok(html !== '', 'renderWhyPicks() draws the page');
const groupsHtml = html.split('class="hdlv2-why-group"').slice(1);
ok(groupsHtml.length === 3 && groupsHtml.every(function (g) { return (g.match(/type="checkbox"/g) || []).length === 8; }),
  'three groups with 8 statements each, all in the page from the start');
ok(!/ hidden[ >]|hidden=""/.test(html.split('id="hdlv2-picks-count"')[0]), 'no group or statement is hidden');
ok(!/hdlv2-theme-|hdlv2-pick-needs/.test(html), 'no theme tile, no "needs" words');
ok(/<h2>What matters most to you<\/h2>/.test(html), 'title: What matters most to you');
ok(html.indexOf('<p>Pick the 5 to 10 that matter most to you in your last decade of life.</p>') !== -1, 'one opening line, as written');
ok(/People I love[\s\S]*My freedom[\s\S]*Things I love/.test(html), 'group headings in order');
ok(html.indexOf('<h3>What your picks need from your body</h3>') !== -1, 'section heading, as written');
ok(html.indexOf('<p>Each thing you picked needs some of these. Your plan starts with the ones your health answers show are weakest.</p>') !== -1, 'section line, as written');
ok(html.indexOf('<p class="hdlv2-why-caption">The further out, the more of your picks need it.</p>') !== -1, 'radar caption, as written');
ok(/id="hdlv2-abilities"/.test(html) && /id="hdlv2-why-radar"/.test(html) && html.indexOf('id="hdlv2-abilities"') < html.indexOf('id="hdlv2-why-radar"'),
  'the bars (#hdlv2-abilities) and the radar are both on the page, bars first');
ok(html.indexOf('Tap a few above to see what they need.') !== -1, 'nothing picked: the bars show the empty line');
ok(/class="hdlv2-why-radar"[\s\S]*class="hdlv2-why-caption"/.test(html), 'the caption sits under the radar');
ok(html.indexOf("Who are you doing this for? First names.") !== -1 && html.indexOf('placeholder="Jenny, Leo, Mia"') !== -1, 'people label and placeholder, as written');
ok(html.indexOf('Anything else? (optional)') !== -1, 'own-words label, as written');
ok(/>Next</.test(html) || />Next &#8594;</.test(html), 'Next button');
ok(/role="img"[^>]*aria-label="Nothing picked yet\."/.test(html), 'the chart is on the page from the start, empty, with its spoken label');
ok((html.match(/<svg[^>]*aria-hidden="true"/g) || []).length >= 11, 'every picture is decoration (aria-hidden): 3 group + 8 ability pictures');
ok(/type="checkbox"/.test(page), 'every statement is a real checkbox');
ok(/aria-live="polite"/.test(page), 'the counter is announced (aria-live)');
ok(!/\b(alert|confirm|prompt)\(/.test(src), 'the shipped file has no alert( / confirm( / prompt(');
ok(src.indexOf('Tap the parts of life you care about') === -1 && src.indexOf('Start with one or two that matter to you.') === -1
  && src.indexOf('What your choices ask of your body') === -1, 'the old long copy is gone');
const cle = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'hdlv2-client-list-enhance.js'), 'utf8');
ok(cle.indexOf("'<div class=\"hdlv2-consult-caption\">Scores are out of 5, averaged from the answers listed. They are not a test of the ability itself. Stage 1 answers run 1 to 5, Stage 3 answers 0 to 5.'") !== -1
  && /\(s2\.choices\.stage3_done \? '' : ' Stage 3 not finished: based on Stage 1 answers only\.'\)/.test(cle),
  'practitioner caption unchanged');

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
ok(/font-size:\s*17px/.test(stmt) && /min-height:\s*60px/.test(stmt) && /padding:\s*16px 18px/.test(stmt), 'statement text is 17px, at least 60px high, padding 16px 18px');
// v0.48.1 — room (Design 1).
const flat = css.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\s+/g, ' ');
ok(/\.hdlv2-assessment-root\.is-picks \{ max-width: 1080px; \}/.test(flat), 'is-picks card is up to 1080px wide');
ok(/\.hdlv2-why \.hdlv2-form-body \{ padding: 0 20px 24px; \}/.test(flat) && /@media \(min-width: 760px\) \{[^@]*\.hdlv2-why \.hdlv2-form-body \{ padding: 0 24px 32px; \}/.test(flat)
  && /@media \(min-width: 1000px\) \{[^@]*\.hdlv2-why \.hdlv2-form-body \{ padding: 0 32px 32px; \}/.test(flat),
  'card sides: 20px below 760px, 24px from 760px (columns stay 230px+ at 820), 32px from 1000px');
ok(/window\.addEventListener\('resize', redrawRadar\)/.test(body('renderWhyPicks')) && /redrawRadar\(\)/.test(body('updatePicks')),
  'the radar is redrawn at its real width on every tap and when the screen turns round');
ok(/\.hdlv2-why-groups \{ display: grid; gap: 28px; \}/.test(flat) && /@media \(min-width: 760px\) \{ \.hdlv2-why-groups \{ grid-template-columns: repeat\(3, minmax\(0, 1fr\)\); gap: 16px; \} \}/.test(flat)
  && /@media \(min-width: 1000px\) \{ \.hdlv2-why-groups \{ gap: 28px; \} \}/.test(flat), 'groups: 28px stacked, 16px at 760-999, 28px from 1000');
ok(/\.hdlv2-why \.hdlv2-picks-grid \{[^}]*gap: 14px/.test(flat), 'statements 14px apart');
ok(/\.hdlv2-why \.hdlv2-header p \{ margin: 0 0 28px; \}/.test(flat), '28px between the opening line and the groups');
ok(/\.hdlv2-why \.hdlv2-subgroup-heading \{[^}]*margin: 0 0 14px/.test(flat), '14px under each group heading');
ok(/\.hdlv2-why \.hdlv2-picks-count \{[^}]*margin: 20px 0 8px/.test(flat), '20px above the count line');
ok(/\.hdlv2-why \.hdlv2-field \{ margin-top: 28px; \}/.test(flat), '28px above each field under the charts');
ok(/\.hdlv2-why-needs \{[^}]*margin: 36px 0 0[^}]*padding: 24px[^}]*border: 1px solid #e4e6ea[^}]*border-radius: 12px/.test(flat), 'the section panel: 36px above, padding 24px, 1px #e4e6ea, radius 12px');
ok(/@media \(min-width: 900px\) \{ \.hdlv2-why-needs-body \{ grid-template-columns: repeat\(2, minmax\(0, 1fr\)\); gap: 32px; \} \}/.test(flat), 'bars and radar side by side from 900px, 32px apart');
ok(/\.hdlv2-why-wheel-svg \{[^}]*max-width: 440px/.test(flat), 'radar up to 440px wide');
ok(/\.hdlv2-why-needs h3 \{[^}]*font-size: 20px[^}]*font-weight: 700[^}]*color: #004F59/.test(flat)
  && /\.hdlv2-why-needs p \{[^}]*font-size: 17px[^}]*color: #2c3e50/.test(flat)
  && /\.hdlv2-why \.hdlv2-why-caption \{[^}]*font-size: 16px[^}]*color: #666/.test(flat), 'section type: heading 20px bold #004F59, lines 17px #2c3e50, caption 16px #666');
ok(/\.hdlv2-ability-meter \{[^}]*height: 8px/.test(flat) && /\.hdlv2-ability-name \{[^}]*font-size: 17px[^}]*font-weight: 600/.test(flat) && /\.hdlv2-ability-count \{[^}]*font-size: 16px[^}]*color: #666/.test(flat),
  'bars: name 17px 600, count 16px #666, meter 8px');
ok(!/hdlv2-why-wheel \{|hdlv2-why-chart/.test(flat), 'the wedge chart styles are gone');
ok(/\.hdlv2-why \.hdlv2-subgroup-heading\s*\{[^}]*text-transform:\s*none/.test(css) && /\.hdlv2-why \.hdlv2-subgroup-heading\s*\{[^}]*letter-spacing:\s*0/.test(css),
  'the shared group heading has its capitals and letter-spacing undone on this page');

const submit = body('submitWhyPicks');
ok(submit !== '', 'submitWhyPicks() exists');
ok(crypto.createHash('md5').update(submit).digest('hex') === '4272960dae0c9b8ec8070936887e661f', 'submitWhyPicks() is byte-for-byte slice 1\'s');
ok(/submitted:\s*true/.test(submit), 'Next sends the submitted save');
ok(/loadForm\(\)/.test(submit), 'Next goes on to loadForm() (the health sections)');
ok(!/renderStage2ThankYou/.test(submit), 'Next never shows the Stage 2 thank-you card');
ok(!/\b(alert|confirm|prompt)\(/.test(submit), 'no native popups in the submit path');
ok(/Connection error/.test(submit), 'network failure says "Connection error"');

const wiz = body('renderWizardSection');
ok(/isSingleFlow\(\)/.test(wiz), 'wizard reads the single-flow marker for dots + header');
ok(/Your assessment/.test(wiz), 'single-flow header reads "Your assessment"');

const s1 = body('renderStage1Result');
ok(/single_form/.test(s1) && /Continue your assessment/.test(s1), 'Stage 1 result has the covered-row copy');
ok(src.indexOf('Continue to Stage 2 \\u2014 Your WHY \\u2192') !== -1, 'Stage 1 result keeps today\'s button for other rows');

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

console.log('\n' + PASS + ' passed, ' + FAIL + ' failed');
process.exit(FAIL ? 1 : 0);
