#!/usr/bin/env node
/**
 * Second form as tap cards (v0.47.94) — the client side.
 *
 * Runs the REAL functions from assets/js/hdlv2-staged-form.js in vm (same
 * method as tests/single-form): the screen-1 values, the cards, the question
 * order, the reason line and the landing screen. The old wizard is checked
 * against b0b1352 (git show): its section content renders byte-identical for
 * fixed answers, and the rest of renderWizardSection is the same text.
 *
 * Run:  node tests/second-form/test-second-form.js
 * Exit: 0 all pass · 1 any fail
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { execFileSync } = require('child_process');

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }
const J = JSON.stringify;

const REL = 'hdl-longevity-v2/assets/js/hdlv2-staged-form.js';
const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'hdlv2-staged-form.js'), 'utf8');
const old = execFileSync('git', ['show', 'b0b1352:' + REL], { cwd: __dirname, encoding: 'utf8', maxBuffer: 8 << 20 });

function fnIn(text, name) {
  const one = text.match(new RegExp('\\n(  function ' + name + '\\([^)]*\\) \\{[^\\n]*\\})\\n'));
  if (one) return one[1];
  const m = text.match(new RegExp('  function ' + name + '\\([^)]*\\) \\{[\\s\\S]*?\\n  \\}'));
  return m ? m[0] : '';
}
function varIn(text, name) {
  const at = text.indexOf('\n  var ' + name + ' = ');
  if (at === -1) return '';
  const nl = text.indexOf('\n', at + 1);
  const line = text.slice(at + 1, nl);
  if (/;\s*(\/\/.*)?$/.test(line)) return line;
  const end = text.slice(at).search(/\n  [\]}];/);
  return end === -1 ? '' : text.slice(at + 1, at + end + 5);
}
const fn = (name) => fnIn(src, name);
const v = (name) => varIn(src, name);

// The page's esc() needs a DOM; this stub escapes exactly as textContent→innerHTML does.
const DOC = 'var document = { createElement: function () { var t = ""; return {'
  + ' set textContent(s) { t = s; },'
  + ' get innerHTML() { return t.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;"); } }; } };\n';

const HELPERS = ['fieldInputWithSkip', 'renderTooltip', 'fieldSelect', 'fieldTextarea', 'fieldReadonly', 'prefillHint', 'esc', 's1Prefill'];
const VARS = ['NONE_SENTINEL', 'S3_OPTIONS', 'S1_TO_S3_DEFAULTS', 'S3_SECTIONS'];
const NEW_NAMES = ['TAP_FIRST', 'TAP_FIRST_LABELS', 'TAP_ORDER', 'TEST_HOW', 'TAP_TITLES', 'ABILITY_SENTENCES'];
const NEW_FNS = ['tapFirstValues', 'tapCards', 'tapQuestionOrder', 'tapReason', 'tapLandingStep', 'wizardSectionContent'];

function context(extra) {
  const ctx = {};
  const code = DOC + 'var formData = {};\n'
    + VARS.concat(NEW_NAMES).map(v).join('\n') + '\n'
    + HELPERS.concat(NEW_FNS).map(fn).join('\n') + '\n'
    + (extra || '')
    + '\nthis.setFormData = function (d) { formData = d; };';
  try { vm.runInNewContext(code, ctx); } catch (e) { console.log('  (context error: ' + e.stack.split('\n').slice(0, 3).join(' / ') + ')'); }
  // Top-level var/function declarations are properties of the context.
  ctx.get = (n) => ctx[n];
  return ctx;
}
const ctx = context();
const get = (n) => ctx[n];
const has = (n) => typeof get(n) === 'function';

NEW_FNS.forEach((n) => ok(has(n), n + '() exists'));
const S3_OPTIONS = get('S3_OPTIONS') || {};
const S3_FIELDS = Object.keys(S3_OPTIONS);
const TODAY = ['sitToStand', 'breathHold', 'balance', 'skinElasticity', 'stressLevels', 'cognitiveActivity',
  'alcoholConsumption', 'supplementIntake', 'sunlightExposure', 'dailyHydration'];
const FIRST = ['physicalActivity', 'sleepDuration', 'sleepQuality', 'smokingStatus', 'socialConnections', 'dietQuality'];

// ── 1. Saved data: screen-1 values are exactly s1Prefill's ──
if (has('tapFirstValues') && has('s1Prefill')) {
  const tapFirstValues = get('tapFirstValues'), s1Prefill = get('s1Prefill');
  const L = ['a', 'b', 'c', 'd', 'e', undefined];
  let same = true, sameSide = true, n = 0;
  for (const q3 of L) for (const q6 of L) for (const q7 of L) for (const q8 of L) for (const q9 of L) {
    const s1d = { q3, q6, q7, q8, q9 };
    ctx.setFormData({});
    const want = {};
    FIRST.forEach((f) => { want[f] = s1Prefill(f, s1d, '') || ''; });
    const sideOld = J(get('formData'));
    ctx.setFormData({});
    const got = tapFirstValues(s1d, {});
    const sideNew = J(get('formData'));
    if (J(got) !== J(want)) same = false;
    if (sideOld !== sideNew) sameSide = false;
    n++;
  }
  ok(same && n === 7776, 'screen-1 values equal s1Prefill() for every first-check answer (' + n + ' cases)');
  ok(sameSide, 'screen 1 writes the same values into formData as the old sections did');
  ctx.setFormData({});
  const saved = { physicalActivity: '5', sleepDuration: '0', sleepQuality: '1', smokingStatus: '2', socialConnections: '0', dietQuality: '4' };
  ok(J(tapFirstValues({ q3: 'a', q6: 'e', q7: 'e', q8: 'e', q9: 'a' }, saved)) === J(saved), 'a saved value is returned untouched');
  ok(J(Object.keys(tapFirstValues({}, {}))) === J(FIRST), 'screen 1 holds the six repeated fields, in order');
}

// ── 2. Cards are built from S3_OPTIONS ──
if (has('tapCards')) {
  const tapCards = get('tapCards');
  let allOk = true;
  S3_FIELDS.forEach((f) => {
    const html = tapCards(f, '');
    const vals = (html.match(/data-val="([^"]*)"/g) || []).map((s) => s.slice(10, -1));
    const want = S3_OPTIONS[f].filter((o) => o.v !== '').map((o) => o.v);
    const chips = (html.match(/hdlv2-s1-chip"[^>]*>([A-Z])</g) || []).map((s) => s.slice(-2, -1)).join('');
    if (J(vals) !== J(want) || J(want) !== J(['0', '1', '2', '3', '4', '5']) || chips !== 'ABCDEF') allOk = false;
    S3_OPTIONS[f].filter((o) => o.v !== '').forEach((o) => {
      if (html.indexOf(get('esc')(o.l)) === -1) allOk = false;
    });
  });
  ok(allOk, 'every card writes one of the field\'s S3_OPTIONS values 0–5, chips A–F, today\'s labels in today\'s order');
  const sel = tapCards('balance', '3');
  ok(/data-val="3"[^>]*aria-checked="true"/.test(sel) && (sel.match(/aria-checked="true"/g) || []).length === 1, 'the saved answer is the one card checked');
  ok(/role="radio"/.test(sel), 'cards are radios');
}

// ── 3. The 13 screens cover exactly the 26 fields ──
function fieldsIn(html) { return (html.match(/data-field="([^"]+)"/g) || []).map((s) => s.slice(12, -1)); }
if (has('wizardSectionContent') && has('tapQuestionOrder')) {
  const secs = get('S3_SECTIONS');
  const body = fieldsIn(get('wizardSectionContent')(secs[0], { q1_age: 52, q1_sex: 'male' }, {}));
  const bg = fieldsIn(get('wizardSectionContent')(secs[5], {}, {}));
  const order = get('tapQuestionOrder')({});
  const all = FIRST.concat(order, body, bg);
  ok(J(body) === J(['height', 'weight', 'waist', 'hip', 'restingHeartRate', 'bpSystolic', 'bpDiastolic']), 'screen 12 has the 7 typed numbers');
  ok(J(bg) === J(['family_history', 'medications', 'existing_conditions']), 'screen 13 has the 3 text boxes');
  ok(all.length === 26 && new Set(all).size === 26, '26 fields, each once');
  ok(J(FIRST.concat(order).sort()) === J(S3_FIELDS.slice().sort()), 'the 16 answer-list fields: 6 on screen 1, 10 on screens 2–11');
  // The old six sections draw the same 26.
  const oldAll = [];
  secs.forEach((s) => oldAll.push.apply(oldAll, fieldsIn(get('wizardSectionContent')(s, { q1_age: 52 }, {}))));
  ok(J(oldAll.slice().sort()) === J(all.slice().sort()), 'the old six sections draw the same 26 fields');
}

// ── 4. Order ──
if (has('tapQuestionOrder')) {
  const o = get('tapQuestionOrder');
  ok(J(o({})) === J(TODAY) && J(o(null)) === J(TODAY) && J(o([])) === J(TODAY), 'no ties → today\'s order');
  ok(J(o({ balance: { count: 3 }, sitToStand: { count: 1 } })) === J(['balance', 'sitToStand', 'breathHold', 'skinElasticity', 'stressLevels',
    'cognitiveActivity', 'alcoholConsumption', 'supplementIntake', 'sunlightExposure', 'dailyHydration']), 'ties {balance 3, sitToStand 1} → balance, sitToStand, then today\'s order');
  ok(J(o({ cognitiveActivity: { count: 2 }, stressLevels: { count: 2 }, sitToStand: { count: 2 } }).slice(0, 3)) === J(['sitToStand', 'stressLevels', 'cognitiveActivity']), 'equal counts keep today\'s order');
  ok(o({ physicalActivity: { count: 9 }, sleepQuality: { count: 4 } }).length === 10 && J(o({ physicalActivity: { count: 9 } })) === J(TODAY), 'screen-1 ties do not move screens 2–11; always 10 entries');
}

// ── 5. Reason line ──
if (has('tapReason')) {
  const r = get('tapReason');
  ok(r('sitToStand', {}) === 'This test shows how strong your legs are.', 'no tie → the plain line');
  ok(r('dailyHydration', { sitToStand: { ability: 'strength', count: 1, choice: 'x' } }) === 'Water helps your energy and concentration.', 'another field\'s tie does not leak');
  ok(r('sitToStand', { sitToStand: { ability: 'strength', count: 1, choice: 'Carry my own shopping' } })
    === 'You chose “Carry my own shopping”. This test shows how strong your legs are for that.', 'one choice');
  ok(r('stressLevels', { stressLevels: { ability: 'mind', count: 3, choice: 'Read a book' } })
    === 'You chose “Read a book” and 2 more like it. This helps us see how to keep your mind sharp for that.', 'several choices');
  ok(r('balance', { balance: { ability: 'balance', count: 2, choice: 'Ride a <b>bike</b> & more' } })
    === 'You chose “Ride a &lt;b&gt;bike&lt;/b&gt; &amp; more” and 1 more like it. This shows how steady you are on your feet for that.', 'the choice text is escaped');
  const titles = get('TAP_TITLES') || {};
  ok(TODAY.every((f) => titles[f] && titles[f].title && titles[f].plain), 'every question on screens 2–11 has a title and a plain line');
}

// ── 6. Landing screen on reload ──
if (has('tapLandingStep')) {
  const land = get('tapLandingStep');
  const full6 = { physicalActivity: '3', sleepDuration: '3', sleepQuality: '3', smokingStatus: '5', socialConnections: '3', dietQuality: '3' };
  ok(land({}, TODAY) === 0 && land(null, TODAY) === 0, 'nothing saved → screen 1');
  ok(land(Object.assign({}, full6, { dietQuality: '' }), TODAY) === 0, 'one screen-1 field missing → screen 1');
  ok(land(full6, TODAY) === 1, 'screen 1 saved → the first question');
  ok(land(Object.assign({}, full6, { sitToStand: '3', breathHold: '2', balance: '4' }), TODAY) === 4, 'first unanswered question in the order');
  ok(land(Object.assign({}, full6, { balance: '4' }), ['balance'].concat(TODAY.filter((f) => f !== 'balance'))) === 2, 'follows the person\'s order');
  const done = Object.assign({}, full6);
  TODAY.forEach((f) => { done[f] = '0'; });
  ok(land(done, TODAY) === 11, 'all ten answered → screen 12 (measurements)');
}

// ── 7. Routing, and the old wizard untouched ──
const st3 = fn('renderStage3');
ok(/isSingleFlow\(\)\)\s*\{?\s*renderTapForm\(data\)/.test(st3) && /renderWizardSection\(data\)/.test(st3), 'renderStage3 sends single-flow rows to the tap screens, every other row to renderWizardSection');
ok(st3.indexOf('renderTapForm') < st3.lastIndexOf('renderWizardSection(data)'), 'the tap branch returns before the old wizard');

const oldWiz = fnIn(old, 'renderWizardSection');
const newWiz = fn('renderWizardSection');
const a = oldWiz.indexOf("    var content = '';"), b = oldWiz.indexOf('    // Wizard progress dots');
ok(a > 0 && b > a, 'found the content block in b0b1352\'s renderWizardSection');
const block = oldWiz.slice(a, b);
ok(newWiz === oldWiz.slice(0, a) + '    var content = wizardSectionContent(sec, s1d, d);\n\n' + oldWiz.slice(b),
  'renderWizardSection is b0b1352\'s text with only the content block moved out');
HELPERS.forEach((h) => ok(fn(h) === fnIn(old, h), h + '() unchanged since b0b1352'));
['S3_OPTIONS', 'S1_TO_S3_DEFAULTS', 'S3_SECTIONS'].forEach((n) => ok(v(n) === varIn(old, n), n + ' unchanged since b0b1352'));
// Old content block vs the new function: same HTML and same formData writes.
const octx = context('function oldContent(sec, s1d, d) {\n' + block + '    return content;\n  }\nthis.oldContent = oldContent;');
if (typeof octx.oldContent === 'function' && has('wizardSectionContent')) {
  const fixtures = [
    [{}, {}],
    [{ q1_age: 52, q1_sex: 'male', q3: 'c', q6: 'e', q7: 'a', q8: 'b', q9: 'd' }, {}],
    [{ q1_age: 40, gender: 'female', q3: 'a' }, { height: '180', hip: 'skip', sitToStand: '3', sleepQuality: '2', dietQuality: '5', family_history: 'None (to my knowledge)', medications: 'Statin <x>' }]
  ];
  let same = true;
  octx.get('S3_SECTIONS').forEach((sec) => fixtures.forEach(([s1d, d]) => {
    octx.setFormData({}); const oh = octx.oldContent(sec, s1d, Object.assign({}, d)); const of = J(octx.get('formData'));
    octx.setFormData({}); const nh = octx.get('wizardSectionContent')(sec, s1d, Object.assign({}, d)); const nf = J(octx.get('formData'));
    if (oh !== nh || of !== nf || !oh) same = false;
  }));
  ok(same, 'old sections render byte-identical HTML and formData writes for 18 fixed cases');
} else ok(false, 'old content block could be run');

// The tap screens' wiring.
const step = fn('renderTapStep');
ok(step !== '', 'renderTapStep() exists');
ok(/Step ' \+ \(tapStep \+ 1\) \+ ' of ' \+ TAP_STEPS/.test(step) || /Step '\s*\+/.test(step), 'counter reads "Step N of 13"');
ok(/var TAP_STEPS = 13;/.test(src), 'thirteen steps');
ok(src.indexOf('Here is what you told us') !== -1 && src.indexOf('We filled these in from your first answers. If one is not right, tap Change.') !== -1, 'screen 1 copy as written');
ok(src.indexOf('Looks right, carry on') !== -1 && src.indexOf('Not answered yet') !== -1, 'screen 1 button and empty-row text as written');
ok(src.indexOf('Skip this one') !== -1 && /Press <kbd>A<\/kbd>–<kbd>F<\/kbd> to choose/.test(src), 'Skip and the key hint as written');
ok(src.indexOf('Your measurements') !== -1 && src.replace(/\\u2019/g, '\u2019').indexOf('Use a tape measure and, if you have one, a blood pressure monitor. Skip anything you don\u2019t have to hand.') !== -1, 'screen 12 copy as written');
ok(src.indexOf('Your health background') !== -1, 'screen 13 heading as written');
ok(/aria-expanded/.test(src) && /aria-controls="hdlv2-tap-opts-/.test(src), 'Change buttons carry aria-expanded and aria-controls');
ok(/completeStage3\(data\)/.test(fn('renderTapStep')) || /completeStage3\(data\)/.test(src.slice(src.indexOf('function renderTapStep'))), 'screen 13 completes with today\'s completeStage3()');
ok(/metaKey|ctrlKey/.test(src.slice(src.indexOf('tapKeyHandler = function'), src.indexOf('tapKeyHandler = function') + 600)), 'A–F keys ignore Cmd/Ctrl shortcuts');

ok(/if \(saving\) \{ setTimeout\(tapSave, 300\); return; \}/.test(fn('tapSave')) && /tapSave\(\);/.test(fn('tapGo')) && !/autoSave/.test(fn('tapGo')),
  'leaving a screen waits for a save in flight instead of dropping it');
ok(/if \(saving\) \{ setTimeout\(finish, 300\); return; \}\s*completeStage3\(data\);/.test(step), 'Complete waits for a save in flight');

// ── 8. No native popups ──
ok(!/\b(alert|confirm|prompt)\(/.test(src), 'the shipped file has no alert( / confirm( / prompt(');

console.log('\n' + PASS + ' passed, ' + FAIL + ' failed');
process.exit(FAIL ? 1 : 0);
