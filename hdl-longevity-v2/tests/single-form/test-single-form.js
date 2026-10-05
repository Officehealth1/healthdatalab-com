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

// ── v0.48.0: every statement on the page, far fewer words, the polar chart ──
const ctx2 = {};
try {
  vm.runInNewContext(limits + '\n' + body('abilityCounts') + '\n' + body('pickCountLine') + '\n' + body('wedgeSizes') + '\n' + body('chartLabel')
    + '\nthis.abilityCounts = abilityCounts; this.pickCountLine = pickCountLine; this.wedgeSizes = wedgeSizes; this.chartLabel = chartLabel;', ctx2);
} catch (e) { /* missing functions: reported below */ }
const { abilityCounts, pickCountLine, wedgeSizes, chartLabel } = ctx2;
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

// The chart's size function: count ÷ max(3, highest count), in the server's
// ability order, every ability present.
if (typeof wedgeSizes === 'function') {
  const zero = wedgeSizes([], SHORT);
  ok(J(zero.map(function (w) { return w.id; })) === J(Object.keys(SHORT)) && zero.every(function (w) { return w.size === 0 && w.count === 0; }),
    'chart sizes: zero picks → eight abilities, all 0, in the server\'s order');
  const one = wedgeSizes([{ id: 'stamina', count: 1 }], SHORT);
  ok(one[4].id === 'stamina' && Math.abs(one[4].size - 1 / 3) < 1e-9 && one.filter(function (w) { return w.size > 0; }).length === 1,
    'chart sizes: one ability with one pick → 1/3 (the divide-by-3 floor)');
  const floor = wedgeSizes([{ id: 'strength', count: 2 }, { id: 'balance', count: 1 }], SHORT);
  ok(Math.abs(floor[0].size - 2 / 3) < 1e-9 && Math.abs(floor[3].size - 1 / 3) < 1e-9, 'chart sizes: highest count 2 still divides by 3');
  const big = wedgeSizes([{ id: 'strength', count: 5 }, { id: 'balance', count: 2 }], SHORT);
  ok(big[0].size === 1 && Math.abs(big[3].size - 2 / 5) < 1e-9, 'chart sizes: a count above 3 divides by the highest count');
  ok(big[0].label === 'Strength' && big[5].label === 'Mind', 'chart sizes carry the short names');
} else ok(false, 'wedgeSizes() exists');

if (typeof chartLabel === 'function' && typeof wedgeSizes === 'function') {
  ok(chartLabel(wedgeSizes([], SHORT)) === 'Nothing picked yet.', 'chart aria-label with no picks');
  ok(chartLabel(wedgeSizes([{ id: 'strength', count: 2 }, { id: 'balance', count: 1 }], SHORT)) === 'What your picks need: Strength 2, Balance 1.',
    'chart aria-label says it in words, in the server\'s order');
} else ok(false, 'chartLabel() exists');

// The page markup: run the REAL renderWhyPicks() against a fake root.
function fakeEl() {
  return { hidden: false, textContent: '', innerHTML: '', disabled: false, checked: false, value: '', parentNode: { classList: { toggle: function () {} } },
    classList: { add: function () {}, remove: function () {}, toggle: function () {} }, setAttribute: function () {}, addEventListener: function () {} };
}
const fakeRoot = Object.assign(fakeEl(), { querySelectorAll: function () { return []; } });
const pageCtx = {
  root: fakeRoot, formData: {}, serverData: {}, saveTimer: null, PICK_MIN: 5, PICK_MAX: 10,
  document: { getElementById: function () { return fakeEl(); } },
  setRootClasses: function () {}, saveIndicator: function () { return ''; }, footer: function () { return ''; },
  bindFieldListeners: function () {}, submitWhyPicks: function () {}, clearSaveError: function () {}, autoSave: function () {},
  esc: function (s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
};
const PAGE_FNS = ['togglePick', 'canContinue', 'abilityCounts', 'pickCountLine', 'wedgeSizes', 'chartLabel', 'polarChart', 'renderWhyPicks', 'updatePicks', 'picksTextarea'];
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
ok(!/hdlv2-theme-|hdlv2-pick-needs|hdlv2-ability-/.test(html), 'no theme tile, no "needs" words, no ability bars');
ok(/<h2>What matters most to you<\/h2>/.test(html), 'title: What matters most to you');
ok(html.indexOf('<p>Pick the 5 to 10 that matter most to you in your last decade of life.</p>') !== -1, 'one opening line, as written');
ok(/People I love[\s\S]*My freedom[\s\S]*Things I love/.test(html), 'group headings in order');
ok(html.indexOf('>What your picks need</') !== -1, 'chart heading: What your picks need');
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
ok(/font-size:\s*17px/.test(stmt) && /min-height:\s*56px/.test(stmt), 'statement text is 17px and each statement at least 56px high');
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
