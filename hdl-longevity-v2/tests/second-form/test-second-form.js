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
const NEW_NAMES = ['TAP_FIRST', 'TAP_FIRST_LABELS', 'TAP_ORDER', 'TEST_HOW', 'TAP_NEUTRAL', 'TAP_TITLES', 'ABILITY_SENTENCES'];
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
  ok(r('dailyHydration', { sitToStand: { ability: 'strength', count: 1, choice: 'x' } }) === 'This answer goes into your rate of ageing result.', 'another field\'s tie does not leak');
  ok(r('sitToStand', { sitToStand: { ability: 'strength', count: 1, choice: 'Carry my own shopping' } })
    === 'You chose “Carry my own shopping”. This test shows how strong your legs are for that.', 'one choice');
  ok(r('stressLevels', { stressLevels: { ability: 'mind', count: 3, choice: 'Read a book' } })
    === 'You chose “Read a book” and 2 more like it. This helps us see how to keep your mind sharp for that.', 'several choices');
  ok(r('balance', { balance: { ability: 'balance', count: 2, choice: 'Ride a <b>bike</b> & more' } })
    === 'You chose “Ride a &lt;b&gt;bike&lt;/b&gt; &amp; more” and 1 more like it. This shows how steady you are on your feet for that.', 'the choice text is escaped');
  const titles = get('TAP_TITLES') || {};
  ok(TODAY.every((f) => titles[f] && titles[f].title && titles[f].plain), 'every question on screens 2–11 has a title and a plain line');
  // v0.47.98 — no health claim without a source: five lines become neutral.
  const NEUTRAL = ['stressLevels', 'cognitiveActivity', 'alcoholConsumption', 'sunlightExposure', 'dailyHydration'];
  ok(get('TAP_NEUTRAL') === 'This answer goes into your rate of ageing result.'
    && NEUTRAL.every((f) => titles[f] && titles[f].plain === get('TAP_NEUTRAL')), 'the five scored lifestyle questions carry the one neutral line');
  ok(J(TODAY.filter((f) => NEUTRAL.indexOf(f) === -1).map((f) => titles[f] && titles[f].plain)) === J(['This test shows how strong your legs are.',
    'A quick check of your breathing.', 'This shows how steady you are on your feet.', 'A quick check of your skin.', 'Tells your practitioner what you already take.']),
    'the other five lines unchanged');
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
// v0.48.15 (H1) — the one header line now reads "Your Full Report" on both branches.
const H1 = (t) => t.replace("(single ? 'Your assessment' : 'More Health Details \\u2014 Stage 3')", "'Your Full Report'");
ok(newWiz === H1(oldWiz.slice(0, a) + '    var content = wizardSectionContent(sec, s1d, d);\n\n' + oldWiz.slice(b)),
  'renderWizardSection is b0b1352\'s text with only the content block moved out (and the H1 header)');
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

ok(/if \(saving\) \{ queuedSaveTimer = setTimeout\(function \(\) \{ saveWhenFree\(stage\); \}, 300\); return; \}/.test(fn('saveWhenFree')) && /saveWhenFree\(3\);/.test(fn('tapGo')) && !/autoSave/.test(fn('tapGo')),
  'leaving a screen waits for a save in flight instead of dropping it');
// v0.48.5 (gate G3) — one queued save at a time: a second call drops the first's timer.
ok(/^  function saveWhenFree\(stage\) \{\n    if \(queuedSaveTimer\) clearTimeout\(queuedSaveTimer\);/.test(fn('saveWhenFree')),
  'saveWhenFree() clears any queued save first');
ok(fn('tapSave') === '' && !/tapSaveTimer/.test(src), 'tapSave() and tapSaveTimer are gone (one function serves both pages)');
ok(/bindTapComplete\(data\)/.test(step), 'screen 13 binds Complete through bindTapComplete()');

// ── 9. Double taps, held keys and Complete (v0.47.98, gate T1 + T2) ──
// The REAL bindTapQuestion() / bindTapComplete() under a fake clock and DOM.
function tapHarness() {
  const H = { clock: 0, timers: [], gone: [], completes: 0, status: [], autosaves: 0, saveStages: [] };
  const listeners = {};
  function el(attrs) {
    const ls = {};
    return { attrs: Object.assign({}, attrs), disabled: false, textContent: '', isConnected: true,
      classList: { add() {}, remove() {} },
      setAttribute(k, val) { this.attrs[k] = val; }, getAttribute(k) { return this.attrs[k]; },
      addEventListener(t, f) { (ls[t] = ls[t] || []).push(f); },
      click() { (ls.click || []).forEach((f) => f({})); } };
  }
  const opts = [0, 1, 2, 3, 4].map((i) => el({ 'data-val': String(i) }));
  const body = el({});
  body.querySelectorAll = () => opts;
  body.querySelector = (sel) => { const m = sel.match(/data-val="(\d)"/); return m ? opts[+m[1]] : null; };
  const skip = el({}), done = el({});
  done.textContent = 'Complete Assessment ✓';
  const ids = { 'hdlv2-tap-body': body, 'hdlv2-tap-skip': skip, 'hdlv2-complete-s3': done };
  const sb = {
    H, Date: { now: () => H.clock },
    document: { getElementById: (id) => ids[id] || null, addEventListener: (t, f) => { listeners[t] = f; }, removeEventListener() {}, activeElement: null },
    setTimeout: (f, ms) => { const t = { at: H.clock + ms, f }; H.timers.push(t); return t; },
    clearTimeout: (t) => { H.timers = H.timers.filter((x) => x !== t); }
  };
  const code = v('TAP_SETTLE_MS') + '\n' + v('TAP_COMPLETE_WAIT_MS') + '\n'
    + 'var tapAdvanceTimer = null, tapKeyHandler = null, queuedSaveTimer = null, saveTimer = null, saving = false, formData = {};\n'
    + 'function tapGo(d, delta) { H.gone.push(delta); }\n'
    + 'function autoSave(s) { H.autosaves++; H.saveStages.push(s); }\n'
    + fn('saveWhenFree') + '\n' + fn('tapLeave') + '\n'
    + 'function completeStage3(d) { H.completes++; }\n'
    + 'function setSaveStatus(c, t) { H.status.push([c, t]); }\n'
    + fn('bindTapQuestion') + '\n' + fn('bindTapComplete') + '\n';
  try { vm.runInNewContext(code, sb); } catch (e) { console.log('  (harness error: ' + e.message + ')'); }
  H.advance = (ms) => {
    const end = H.clock + ms;
    for (;;) {
      H.timers.sort((a, b) => a.at - b.at);
      const t = H.timers[0];
      if (!t || t.at > end) break;
      H.timers.shift(); H.clock = t.at; t.f();
    }
    H.clock = end;
  };
  H.key = (k, repeat) => listeners.keydown && listeners.keydown({ key: k, repeat: !!repeat, preventDefault() {} });
  return Object.assign(H, { opts, skip, done, sb });
}
ok(/^  var TAP_SETTLE_MS = 400;$/m.test(src) && /^  var TAP_COMPLETE_WAIT_MS = 5000;$/m.test(src), 'one named constant each: 400 ms settle, 5 s Complete wait');
if (fn('bindTapQuestion') && fn('bindTapComplete') && v('TAP_SETTLE_MS')) {
  let h = tapHarness();
  h.sb.bindTapQuestion({}, 'balance');
  h.advance(300); h.opts[2].click(); h.advance(1000);
  ok(h.sb.formData.balance === undefined && h.gone.length === 0, 'a second tap 300 ms after the card appears changes nothing and does not advance');
  h.advance(100); h.opts[2].click();
  ok(h.sb.formData.balance === '2' && h.gone.length === 0, 'a tap 400 ms after the card appears picks the answer');
  h.advance(200);
  ok(J(h.gone) === '[1]', '...and moves on 200 ms later, once');

  h = tapHarness();
  h.sb.bindTapQuestion({}, 'balance');
  h.advance(500); h.key('c', true); h.advance(1000);
  ok(h.sb.formData.balance === undefined && h.gone.length === 0, 'a held key (repeat) does nothing');
  h.key('c', false); h.advance(200);
  ok(h.sb.formData.balance === '2' && J(h.gone) === '[1]', 'a fresh key press still picks');

  h = tapHarness();
  h.sb.bindTapQuestion({}, 'balance');
  h.advance(100); h.skip.click();
  ok(h.gone.length === 0, 'Skip within 400 ms does nothing');
  h.advance(350); h.skip.click();
  ok(J(h.gone) === '[1]', 'Skip after 400 ms moves on');

  h = tapHarness();
  h.sb.saving = true;
  h.sb.bindTapComplete({});
  h.done.click(); h.done.click(); h.done.click();
  ok(h.done.disabled === true && h.done.textContent === 'Saving…' && h.completes === 0, 'Complete during a save: disabled, "Saving…", nothing sent yet');
  h.advance(1200); h.sb.saving = false; h.advance(300);
  ok(h.completes === 1, 'pressed three times: completeStage3 runs once, after the save clears');
  h.advance(10000); h.done.click();
  ok(h.completes === 1, 'no second completion afterwards');

  h = tapHarness();
  h.sb.saving = true;
  h.sb.bindTapComplete({});
  h.done.click();
  h.advance(4800);
  ok(h.done.disabled === true && h.completes === 0, 'save still in flight at 4.8 s: still waiting');
  h.advance(400);
  ok(h.done.disabled === false && h.done.textContent === 'Complete Assessment ✓' && h.completes === 0,
    'save never clears: after 5 s the button is back with its label and nothing was completed');
  ok(J(h.status[h.status.length - 1]) === J(['error', 'The connection is slow. Please press Complete again.']), '...with the slow-connection message');
  h.advance(30000);
  ok(h.completes === 0 && h.timers.length === 0, '...and nothing keeps waiting in the background');

  h = tapHarness();
  h.sb.bindTapComplete({});
  h.done.click();
  ok(h.completes === 1, 'no save in flight: Complete runs at once');

  // v0.47.99 — a save queued by tapSave() behind one in flight must never
  // fire after Complete (it would land on a finished form).
  h = tapHarness();
  h.sb.saving = true;
  h.sb.saveWhenFree(3);
  h.sb.bindTapComplete({});
  h.done.click();
  h.advance(200); h.sb.saving = false; h.advance(2000);
  ok(h.completes === 1 && h.sb.H.autosaves === 0, 'Complete pressed while a saveWhenFree() poll is queued: completes once, the queued save never fires');

  // v0.48.5 (gate G3) — two quick screen changes during one slow save, then Complete.
  h = tapHarness();
  h.sb.saving = true;
  h.sb.saveWhenFree(3);
  h.advance(100);
  h.sb.saveWhenFree(3);
  h.sb.bindTapComplete({});
  h.done.click();
  h.advance(200); h.sb.saving = false; h.advance(3000);
  ok(h.completes === 1 && h.sb.H.autosaves === 0 && h.timers.length === 0,
    'two screen changes during one slow save, then Complete: completes once, no queued save fires, nothing left waiting');

  h = tapHarness();
  h.sb.saving = true;
  h.sb.saveWhenFree(3);
  h.sb.tapLeave();
  h.sb.saving = false; h.advance(2000);
  ok(h.sb.H.autosaves === 1, 'a re-render (tapLeave runs on every render, right after tapGo queues its save) keeps the queued save');

  h = tapHarness();
  h.sb.saving = true;
  h.sb.saveWhenFree(3);
  h.advance(400); h.sb.saving = false; h.advance(400);
  ok(h.sb.H.autosaves === 1, 'with no Complete and no leave, the queued save still fires once the first one clears');

  h = tapHarness();
  h.sb.saving = true;
  h.sb.bindTapComplete({});
  h.done.click();
  h.advance(600); h.done.isConnected = false; // Previous re-renders the screen
  h.sb.saving = false; h.advance(6000);
  ok(h.completes === 0 && h.timers.length === 0, 'Previous during the Complete wait: the wait stops and nothing is completed');
} else ok(false, 'bindTapQuestion(), bindTapComplete() and TAP_SETTLE_MS exist');

// v0.47.99 — the save line is a spoken status on the tap screens only; every
// other caller gets the 3b5582b markup back, byte for byte.
const OLD_LINE = '<div id="hdlv2-save-indicator" class="hdlv2-save-status"></div>';
const lineCtx = {};
try { vm.runInNewContext(fn('saveIndicator') + '\nthis.saveIndicator = saveIndicator;', lineCtx); } catch (e) { /* reported below */ }
ok(typeof lineCtx.saveIndicator === 'function' && lineCtx.saveIndicator() === OLD_LINE, 'saveIndicator(): the old markup exactly');
ok(typeof lineCtx.saveIndicator === 'function'
  && lineCtx.saveIndicator(true) === '<div id="hdlv2-save-indicator" class="hdlv2-save-status" role="status" aria-live="polite"></div>',
  'saveIndicator(true): the announced line (where the slow-connection message goes)');
// v0.48.5 (gate G7) — the choices page announces its line too ("Please choose at least 5.").
ok(/saveIndicator\(true\)/.test(fn('renderTapStep')) && /saveIndicator\(true\)/.test(fn('renderWhyPicks')) && (src.match(/saveIndicator\(true\)/g) || []).length === 2,
  'only the tap-card frame and the choices page ask for the announced line');
ok((src.match(/saveIndicator\(\)/g) || []).length === 3, 'the three other frames call saveIndicator() as before');

// ── 10. Side bar ──
const formCss = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'css', 'hdlv2-form.css'), 'utf8');
ok(/#hdlv2-tap-body \.hdlv2-section-intro \{ border-left: 0; border-radius: 8px; \}/.test(formCss), 'tap screens drop the section intro\'s teal side bar');
ok(/\.hdlv2-section-intro \{\s*background: #f8f9fb;\s*border-left: 3px solid #3d8da0;/.test(formCss), 'the old form keeps it');

// ── 8. No native popups ──
ok(!/\b(alert|confirm|prompt)\(/.test(src), 'the shipped file has no alert( / confirm( / prompt(');

console.log('\n' + PASS + ' passed, ' + FAIL + ' failed');
process.exit(FAIL ? 1 : 0);
