#!/usr/bin/env node
/**
 * v0.48.97 (plan 11; hdlv2_ff_input_checks) — the Full Report form asks once about empty answers, refuses a
 * number the calculator cannot use, asks about an unusual one, and leaves the age box empty. The REAL
 * functions of assets/js/hdlv2-staged-form.js, run in vm against a few fake elements.
 *   I4 hard: systolic 250+ and diastolic 150+ (the limits handed over, never typed in the script), 0 and
 *      below, and a height outside the form's own 50-250 cm are refused with one line; the value is not
 *      kept; Next stays until the box is changed or cleared. An empty box is always allowed.
 *   P2 odd: a number outside its usual range asks "Is {number} {unit} right?" with "Yes, keep it"; kept, it
 *      stays as typed; imperial boxes are checked after conversion and shown back in their own units.
 *   I3 card: the last step's finish asks once, naming the empty scored answers by their labels; "Add them
 *      now" opens the first; "Finish without them" finishes as today; a second finish goes straight through.
 *   I5 age: the staged form's own age box starts empty and waits for an age from 1 to 120.
 *   P3 switch off: no line, no card, no hold; the screens are drawn as today.
 * Run: node tests/input-checks/test-input-checks.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const J = JSON.stringify;
const js = fs.readFileSync(new URL('../../assets/js/hdlv2-staged-form.js', import.meta.url), 'utf8');
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const fn = (name) => cut('  function ' + name + '(', '\n  }\n');
const ESC = "function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\"/g, '&quot;'); }\n";

const LABELS = { bmiScore: 'BMI', whrScore: 'Waist-to-hip ratio', whtrScore: 'Waist-to-height ratio', bloodPressureScore: 'Blood pressure', heartRateScore: 'Heart rate',
  skinElasticity: 'Skin elasticity', physicalActivity: 'Physical activity', sleepDuration: 'Sleep duration', sleepQuality: 'Sleep quality', stressLevels: 'Stress',
  socialConnections: 'Social connections', dietQuality: 'Diet quality', alcoholConsumption: 'Alcohol', smokingStatus: 'Smoking', cognitiveActivity: 'Cognitive activity',
  sunlightExposure: 'Sunlight', supplementIntake: 'Supplements', dailyHydration: 'Hydration', sitToStand: 'Sit-to-stand', breathHold: 'Breath hold', balance: 'Balance' };
const CHECKS = { hard: { bpSystolic: 250, bpDiastolic: 150 }, usual: { height: [120, 220], weight: [35, 250], waist: [50, 180], hip: [60, 180], restingHeartRate: [35, 130], bpSystolic: [80, 200], bpDiastolic: [50, 120] }, labels: LABELS };
const ON = { input_checks: CHECKS }, OFF = {};

const NAMES = ['r1', 'toMetric', 'writeImperial', 'keepHeight', 'numberKind', 'numberBoxes', 'numberTyped', 'fieldLine', 'checkBox', 'numbersHold', 'emptyAnswers', 'askGapsOnce'];
const SRC = ESC + [cut('  var IN_CM = ', ';\n'), cut('  var HEIGHT_CM = ', ';\n'), cut('  var TAP_STEPS = ', ';\n'), cut('  var TAP_FIRST = ', ';\n'), cut('  var TAP_ORDER = ', ';\n'),
  cut('  var NUMBER_UNITS = ', ';\n'), cut('  var oddKept = ', ';\n'), cut('  var gapsAsked = ', ';\n'), ...NAMES.map(fn)].join('\n');
ok(NAMES.every((n) => fn(n) !== '') && cut('  var NUMBER_UNITS = ', ';\n') !== '', 'the new functions and the units map are top-level in the form script');

/** Fake elements: an element exists once markup names its id; a field holds its boxes and inserted lines. */
function page(cfg) {
  const els = {}, doc = { active: null };
  const mk = (id, value = '') => {
    const attrs = {}, cls = new Set(), handlers = {}, kids = {};
    const e = { id, value, attrs, html: '', handlers, field: null,
      setAttribute(k, v) { attrs[k] = String(v); }, removeAttribute(k) { delete attrs[k]; }, getAttribute(k) { return k in attrs ? attrs[k] : null; },
      classList: { toggle: (c, on) => ((on === undefined ? !cls.has(c) : on) ? cls.add(c) : cls.delete(c)), add: (c) => cls.add(c), remove: (c) => cls.delete(c), contains: (c) => cls.has(c) },
      addEventListener(t, f) { (handlers[t] = handlers[t] || []).push(f); }, click() { (handlers.click || []).forEach((f) => f.call(e)); if (e.onclick) e.onclick(); },
      focus() { doc.active = e; }, closest: (s) => (s === '.hdlv2-field' ? e.field : null),
      querySelector(sel) { const c = sel.replace(/^\./, ''); return sel[0] === '.' && new RegExp('class="[^"]*\\b' + c + '\\b').test(e.html) ? (kids[sel] = kids[sel] || mk(null)) : null; },
      insertAdjacentHTML(pos, h) { const m = h.match(/^<\w+[^>]*\bid="([^"]+)"/); const n = mk(m ? m[1] : null); n.html = h; n.innerHTML = h.replace(/^<[^>]+>/, "").replace(/<\/\w+>$/, ""); n.pos = pos; n.parent = e; e.kids.push(n);
        (h.match(/\bid="[^"]+"/g) || []).forEach((x) => { const i = x.slice(4, -1); if (!els[i]) { els[i] = i === n.id ? n : mk(i); els[i].html = els[i].html || h; } }); if (n.id) els[n.id] = n; },
      kids: [], remove() { if (e.parent) e.parent.kids = e.parent.kids.filter((k) => k !== e); Object.keys(els).forEach((k) => { if (els[k] === e || (e.html && els[k].html === e.html)) delete els[k]; }); } };
    if (id) els[id] = e;
    return e;
  };
  const field = (ids) => { const f = mk(null); ids.forEach((i) => { const b = mk(i); b.field = f; }); return f; };
  const ctx = { CFG: cfg, formData: {}, tapOrder: [], tapStep: 0, gone: [], els, doc,
    tapGo(d, n) { ctx.gone.push(n); }, setTimeout() {}, clearTimeout() {},
    document: { getElementById: (i) => els[i] || null,
      querySelector: (sel) => { const m = sel.match(/^#(\S+) (\.\S+)$/); return m && els[m[1]] ? els[m[1]].querySelector(m[2]) : null; } } };
  vm.runInNewContext(SRC + '\ntapOrder = TAP_ORDER.slice();', ctx);
  return { ctx, els, doc, field, mk };
}
const metric = (cfg) => { const p = page(cfg); ['height', 'weight', 'waist', 'hip', 'restingHeartRate', 'bpSystolic', 'bpDiastolic'].forEach((n) => p.field(['hdlv2-f-' + n])); return p; };
const type = (p, name, v) => { p.els['hdlv2-f-' + name].value = v; p.ctx.formData[name] = name === 'height' ? p.ctx.keepHeight(v, [p.els['hdlv2-f-height']]) : v; return p.ctx.checkBox(name); };
const lineOf = (p, name) => p.els['hdlv2-check-' + name] || null;

console.log('── I4: a number the calculator cannot use ──');
{
  const p = metric(ON);
  ok(type(p, 'bpSystolic', '260') === 'hard', 'I4.1 systolic 260: refused');
  const l = lineOf(p, 'bpSystolic'), box = p.els['hdlv2-f-bpSystolic'];
  ok(!!l && /^<p class="hdlv2-field-hint hdlv2-check-line is-hard" id="hdlv2-check-bpSystolic" role="alert">We cannot use 260 here\. Please check it, or leave the box empty\.<\/p>$/.test(l.html),
    'I4.2 one line under the box: "We cannot use 260 here. Please check it, or leave the box empty.", announced');
  ok(box.attrs['aria-describedby'] === 'hdlv2-check-bpSystolic' && box.attrs['aria-invalid'] === 'true' && box.classList.contains('error'), 'I4.3 tied to its box (aria-describedby, aria-invalid), red border');
  ok(p.ctx.formData.bpSystolic === '', 'I4.4 the number is not kept (it would be dropped without a word)');
  ok(p.ctx.numbersHold() === true && p.doc.active === box, 'I4.5 Next stays; focus goes to the box');
  type(p, 'bpSystolic', '260');
  ok(p.els['hdlv2-f-bpSystolic'].field.kids.length === 1, 'I4.6 checked twice: still one line');
  ok(type(p, 'bpSystolic', '') === null && !lineOf(p, 'bpSystolic') && !('aria-describedby' in box.attrs) && !('aria-invalid' in box.attrs) && !box.classList.contains('error'),
    'I4.7 cleared: the line, the ties and the red border go');
  ok(p.ctx.numbersHold() === false, 'I4.8 then Next moves on (an empty box is always allowed)');
  ok(type(p, 'bpSystolic', '135') === null && p.ctx.formData.bpSystolic === '135', 'I4.9 a usual number: no line, kept');
}
{
  const p = metric(ON), k = (n, v) => p.ctx.numberKind(n, v);
  ok(k('bpSystolic', '250') === 'hard' && k('bpSystolic', '249') === 'odd' && k('bpDiastolic', '150') === 'hard' && k('bpDiastolic', '149') === 'odd',
    'I4.10 the edges: 250 and 150 refused; 249 and 149 asked about, never refused');
  ok(['0', '-3', '0.0'].every((v) => k('restingHeartRate', v) === 'hard' && k('waist', v) === 'hard'), 'I4.11 0 and below: refused in every box');
  ok(k('bpSystolic', '') === null && k('height', '  ') === null, 'I4.12 an empty box: nothing to say');
  ok(k('height', '17') === 'hard' && k('height', '49') === 'hard' && k('height', '251') === 'hard' && k('height', '50') === 'odd', 'I4.13 height outside the form\'s 50-250 cm: refused (50 is asked about)');
  const moved = page({ input_checks: Object.assign({}, CHECKS, { hard: { bpSystolic: 300, bpDiastolic: 150 } }) });
  ok(moved.ctx.numberKind('bpSystolic', '260') === 'odd', 'I4.14 the limit is the one handed over, not a number in the script');
  ok(!/\b250\b|\b150\b/.test(fn('numberKind') + fn('checkBox')), 'I4.15 the script holds no copy of 250 or 150');
  const h = metric(ON);
  ok(type(h, 'height', '17') === 'hard' && h.ctx.formData.height === '' && /We cannot use 17 here\./.test(lineOf(h, 'height').html), 'I4.16 height 17: refused with the same line, not kept');
  ok(h.els['hdlv2-f-height'].field.kids.length === 1 && h.els['hdlv2-f-height'].field.kids[0].html.indexOf('Please check your height') === -1, 'I4.17 one line only: the old height line gives way with the switch on');
  const x = metric(ON); x.els['hdlv2-f-weight'].value = '<b>';
  ok(x.ctx.checkBox('weight') === 'hard' && lineOf(x, 'weight').html.indexOf('We cannot use &lt;b&gt; here.') !== -1, 'I4.18 what was typed is escaped before it is shown');
}

{
  const p = metric(ON), r = { renders: 0 };
  Object.assign(p.ctx, { tapLeave() {}, saveWhenFree() {}, saveTimer: null, renderTapStep() { r.renders++; }, root: { scrollIntoView() {} } });
  vm.runInNewContext(fn('tapGo'), p.ctx);
  p.ctx.tapStep = 11; p.els['hdlv2-f-bpSystolic'].value = '260';
  p.ctx.tapGo({}, 1);
  ok(p.ctx.tapStep === 11 && r.renders === 0 && !!lineOf(p, 'bpSystolic'), 'I4.19 the real tapGo: Next from the measurements with 260 stays, the line shows');
  p.ctx.tapGo({}, -1);
  ok(p.ctx.tapStep === 10 && r.renders === 1, 'I4.20 Previous is never held');
  p.ctx.tapStep = 11; p.els['hdlv2-f-bpSystolic'].value = '';
  p.ctx.tapGo({}, 1);
  ok(p.ctx.tapStep === 12 && r.renders === 2, 'I4.21 cleared: Next moves on');
}
console.log('── P2: an unusual number ──');
{
  const p = metric(ON);
  ok(type(p, 'height', '100') === 'odd', 'P2.1 height 100: asked about');
  const l = lineOf(p, 'height'), box = p.els['hdlv2-f-height'];
  ok(!!l && /^<p class="hdlv2-field-hint hdlv2-check-line" id="hdlv2-check-height" role="alert">Is 100 cm right\? <button type="button" class="hdlv2-tap-change hdlv2-check-keep">Yes, keep it<\/button><\/p>$/.test(l.html),
    'P2.2 "Is 100 cm right?" with a real button "Yes, keep it"');
  ok(box.attrs['aria-describedby'] === 'hdlv2-check-height' && !('aria-invalid' in box.attrs) && !box.classList.contains('error'), 'P2.3 tied to its box, but not marked wrong (it may be right)');
  ok(p.ctx.formData.height === '100', 'P2.4 the number stays as typed while asked');
  const keep = l.querySelector('.hdlv2-check-keep');
  ok(p.ctx.numbersHold() === true && p.doc.active === keep, 'P2.5 Next asks first: it stays and focus goes to "Yes, keep it"');
  keep.click();
  ok(!lineOf(p, 'height') && !('aria-describedby' in box.attrs) && p.doc.active === box, 'P2.6 kept: the line goes, focus back on the box');
  ok(p.ctx.formData.height === '100' && p.ctx.numbersHold() === false, 'P2.7 kept, saved and scored as typed: Next moves on');
  ok(p.ctx.checkBox('height') === null, 'P2.8 the same number is not asked about again');
  ok(type(p, 'height', '99') === 'odd', 'P2.9 changed to another unusual number: asked again');
  ok(type(p, 'height', '170') === null && !lineOf(p, 'height'), 'P2.10 170: no line');
  ok(type(p, 'restingHeartRate', '30') === 'odd' && /Is 30 bpm right\?/.test(lineOf(p, 'restingHeartRate').html), 'P2.11 heart rate 30: "Is 30 bpm right?"');
  ok(type(p, 'bpDiastolic', '45') === 'odd' && /Is 45 mmHg right\?/.test(lineOf(p, 'bpDiastolic').html), 'P2.12 diastolic 45: "Is 45 mmHg right?"');
  ok(type(p, 'weight', '35') === null && type(p, 'weight', '250') === null && type(p, 'weight', '251') === 'odd', 'P2.13 the range ends are usual (35 and 250 kg)');
}
{
  const p = page(ON);
  const hf = p.field(['hdlv2-f-height-ft', 'hdlv2-f-height-in']); p.els['hdlv2-f-height-in'].field = hf;
  p.field(['hdlv2-f-weight-imp']); p.field(['hdlv2-f-waist-imp']);
  p.els['hdlv2-f-height-ft'].value = '3'; p.els['hdlv2-f-height-in'].value = '2';
  p.ctx.writeImperial('height');
  ok(p.ctx.checkBox('height') === 'odd' && /Is 3 ft 2 in right\?/.test(lineOf(p, 'height').html), 'P2.14 imperial 3 ft 2 in (96.5 cm): checked after conversion, shown in feet and inches');
  ok(p.els['hdlv2-f-height-ft'].attrs['aria-describedby'] === 'hdlv2-check-height' && p.els['hdlv2-f-height-in'].attrs['aria-describedby'] === 'hdlv2-check-height', 'P2.15 both height boxes are tied to the line');
  p.els['hdlv2-f-weight-imp'].value = '60'; p.ctx.writeImperial('weight');
  ok(p.ctx.checkBox('weight') === 'odd' && /Is 60 lb right\?/.test(lineOf(p, 'weight').html), 'P2.16 60 lb (27.2 kg): "Is 60 lb right?"');
  p.els['hdlv2-f-waist-imp'].value = '34'; p.ctx.writeImperial('waist');
  ok(p.ctx.checkBox('waist') === null, 'P2.17 34 in (86.4 cm): usual, no line');
  p.els['hdlv2-f-height-ft'].value = '1'; p.els['hdlv2-f-height-in'].value = '5'; p.ctx.writeImperial('height');
  ok(p.ctx.checkBox('height') === 'hard' && /We cannot use 1 ft 5 in here\./.test(lineOf(p, 'height').html) && p.ctx.formData.height === '', 'P2.18 1 ft 5 in (38.1 cm): refused, not kept');
}

console.log('── I3: the card before finishing ──');
const ANSWERED = { physicalActivity: '2', sleepDuration: '3', sleepQuality: '4', smokingStatus: '5', socialConnections: '3', dietQuality: '2',
  sitToStand: '3', breathHold: '2', balance: '1', skinElasticity: '4', stressLevels: '0', cognitiveActivity: '3', alcoholConsumption: '4', supplementIntake: '2', sunlightExposure: '3', dailyHydration: '5',
  height: '170', weight: '70', waist: '85', hip: '95', restingHeartRate: '62', bpSystolic: '120', bpDiastolic: '78' };
function lastStep(cfg, data) {
  const p = metric(cfg);
  Object.assign(p.ctx.formData, data);
  p.ctx.tapStep = 12;
  const done = p.mk('hdlv2-complete-s3'); done.parent = p.mk(null);
  done.insertAdjacentHTML = function (pos, h) { p.mk(null).insertAdjacentHTML.call(done.parent, pos, h); };
  let finished = 0; done.addEventListener('click', () => { finished++; });
  return { p, done, fin: () => finished };
}
{
  const gaps = Object.assign({}, ANSWERED, { balance: '', waist: '', bpSystolic: '', bpDiastolic: '' });
  delete gaps.sunlightExposure; // "Skip this one" leaves the key out
  const s = lastStep(ON, gaps);
  ok(J(s.p.ctx.emptyAnswers().map((g) => g.label)) === J(['Balance', 'Sunlight', 'Waist', 'Blood pressure']), 'I3.1 the empty scored answers, in the order the form asks them: Balance, Sunlight, Waist, Blood pressure');
  ok(s.p.ctx.askGapsOnce({}, s.done) === true, 'I3.2 the first finish shows the card instead of finishing');
  const card = s.p.els['hdlv2-gap-card'];
  ok(!!card && card.pos === 'beforebegin', 'I3.3 the card sits in the page, above the finish button');
  ok(/^<section class="hdlv2-gap-card" id="hdlv2-gap-card" aria-labelledby="hdlv2-gap-title"><h4 class="hdlv2-gap-title" id="hdlv2-gap-title" tabindex="-1" aria-describedby="hdlv2-gap-line">Before you finish<\/h4>/.test(card.html),
    'I3.4 a labelled section; its heading "Before you finish" can take focus and reads the line with it');
  ok(card.html.indexOf('<p class="hdlv2-gap-line" id="hdlv2-gap-line">You left these empty: Balance, Sunlight, Waist, Blood pressure. Your report will use the answers you gave.</p>') !== -1,
    'I3.5 the line names them by their labels');
  ok(/<button type="button" class="hdlv2-btn" id="hdlv2-gap-add">Add them now<\/button><button type="button" class="hdlv2-btn hdlv2-gap-finish" id="hdlv2-gap-finish">Finish without them<\/button>/.test(card.html),
    'I3.6 two real buttons: "Add them now", "Finish without them"');
  ok(s.p.doc.active === s.p.els['hdlv2-gap-title'], 'I3.7 focus moves to the card\'s heading');
  ok(s.fin() === 0, 'I3.8 nothing is sent yet');
  ok(!/<script|onclick|javascript:/i.test(card.html), 'I3.9 no inline script in the card');
  s.p.els['hdlv2-gap-add'].click();
  ok(J(s.p.ctx.gone) === J([3 - 12]), 'I3.10 "Add them now" opens the first of them (Balance, step 4 of 13)');
  ok(s.p.ctx.askGapsOnce({}, s.done) === false, 'I3.11 a second finish goes straight through (once per form)');
}
{
  const s = lastStep(ON, Object.assign({}, ANSWERED, { hip: '' }));
  s.p.ctx.askGapsOnce({}, s.done);
  s.p.els['hdlv2-gap-add'].click();
  ok(J(s.p.ctx.gone) === J([11 - 12]) && s.p.doc.active === s.p.els['hdlv2-f-hip'], 'I3.12 a measurement first: the measurements step, focus on its box');
  const f = lastStep(ON, Object.assign({}, ANSWERED, { restingHeartRate: 'skip' }));
  ok(J(f.p.ctx.emptyAnswers().map((g) => g.label)) === J(['Heart rate']), 'I3.13 an old "skip" counts as empty');
  f.p.ctx.askGapsOnce({}, f.done);
  f.p.els['hdlv2-gap-finish'].click();
  ok(f.fin() === 1 && !f.p.els['hdlv2-gap-card'], 'I3.14 "Finish without them": the card goes and the form finishes as today');
}
{
  const one = lastStep(ON, Object.assign({}, ANSWERED, { bpDiastolic: '' }));
  ok(one.p.ctx.emptyAnswers().length === 0, 'I3.15 one blood pressure number is enough (the calculator scores it alone)');
  const none = lastStep(ON, ANSWERED);
  ok(none.p.ctx.askGapsOnce({}, none.done) === false && !none.p.els['hdlv2-gap-card'], 'I3.16 nothing empty: no card, the finish goes on');
  const zero = lastStep(ON, Object.assign({}, ANSWERED, { stressLevels: '0' }));
  ok(zero.p.ctx.emptyAnswers().length === 0, 'I3.17 an answer of 0 (the first card) is an answer');
  ok(/if \(typeof CFG !== 'undefined' && CFG\.input_checks && askGapsOnce\(data, done\)\) return;/.test(fn('bindTapComplete')), 'I3.18 the buyer\'s finish button asks first (read guarded, as tapFirstNext)');
  ok(!/askGapsOnce/.test(fn('renderWizardSection') + fn('completeStage3')), 'I3.19 the old wizard and completeStage3 do not ask (the buyer\'s path only)');
  const p = page(ON);
  ok(J(p.ctx.TAP_FIRST.concat(p.ctx.TAP_ORDER).filter((f) => !(f in LABELS))) === '[]', 'I3.20 every question of the form has a label in the handed map');
}

console.log('── I5: the staged form\'s own age box ──');
const Q1 = ESC + [fn('fieldInput'), fn('fieldLine'), fn('renderS1_Q1')].join('\n');
function q1(cfg, d) {
  const p = page(cfg), ctx = { CFG: cfg, formData: {}, advanced: 0, s1Advance() { ctx.advanced++; }, bindFieldListeners() {}, setSaveStatus(c, t) { ctx.status = t; }, document: p.ctx.document };
  const box = p.mk('hdlv2-f-q1_age'); box.field = p.mk(null); const next = p.mk('hdlv2-s1-next');
  const body = { innerHTML: '', querySelectorAll: () => [], querySelector: (s) => (s === '[data-field="q1_age"]' ? box : s === '#hdlv2-s1-next' ? next : null) };
  vm.runInNewContext(Q1 + '\nrenderS1_Q1(body, d, {});', Object.assign(ctx, { body, d }));
  return { ctx, body, box, next, p };
}
{
  const age = (h) => (h.match(/id="hdlv2-f-q1_age"[^>]*value="([^"]*)"/) || [])[1];
  ok(age(q1(ON, {}).body.innerHTML) === '' && /placeholder="Your age in years"/.test(q1(ON, {}).body.innerHTML), 'I5.1 switch on, no stored age: the box is empty with its placeholder');
  ok(age(q1(ON, { q1_age: 44 }).body.innerHTML) === '44', 'I5.2 a stored age still shows');
  ok(age(q1(OFF, {}).body.innerHTML) === '50', 'I5.3 switch off: 50 as today');
  for (const bad of ['', '0', '121']) {
    const r = q1(ON, {}); r.ctx.formData.q1_sex = 'female'; r.box.value = bad; r.next.click();
    const l = r.p.els['hdlv2-check-q1_age'];
    ok(r.ctx.advanced === 0 && !!l && /role="alert">Please type your age\.<\/p>$/.test(l.html) && r.box.attrs['aria-describedby'] === 'hdlv2-check-q1_age' && r.box.attrs['aria-invalid'] === 'true',
      `I5.4 "${bad}": stays, "Please type your age." under the box, tied to it`);
  }
  const g = q1(ON, {}); g.ctx.formData.q1_sex = 'male'; g.box.value = ''; g.next.click(); g.box.value = '67'; g.next.click();
  ok(g.ctx.advanced === 1 && g.ctx.formData.q1_age === 67 && !g.p.els['hdlv2-check-q1_age'] && !('aria-invalid' in g.box.attrs), 'I5.5 then 67: moves on with 67, the line goes');
  const o = q1(OFF, {}); o.ctx.formData.q1_sex = 'male'; o.box.value = ''; o.next.click();
  ok(o.ctx.advanced === 0 && o.box.classList.contains('error') && !o.p.els['hdlv2-check-q1_age'], 'I5.6 switch off, no age: today\'s red box, no line');
}

console.log('── P3: the switch off ──');
{
  const p = metric(OFF);
  ok(type(p, 'bpSystolic', '260') === null && !lineOf(p, 'bpSystolic') && p.ctx.formData.bpSystolic === '260', 'P3.1 systolic 260: no line, kept as today');
  ok(type(p, 'height', '100') === null && !lineOf(p, 'height'), 'P3.2 height 100: no question');
  ok(p.ctx.numbersHold() === false, 'P3.3 Next is not held');
  type(p, 'height', '17');
  ok(p.els['hdlv2-f-height'].field.kids.length === 1 && /Please check your height\./.test(p.els['hdlv2-f-height'].field.kids[0].html), 'P3.4 height 17: today\'s line "Please check your height."');
  const s = lastStep(OFF, Object.assign({}, ANSWERED, { balance: '' }));
  ok(s.p.ctx.askGapsOnce({}, s.done) === false && !s.p.els['hdlv2-gap-card'], 'P3.5 no card before finishing');
  ok(/function onFieldChange\(e\) \{\n    var imp = e\.target\.getAttribute\('data-imp'\);\n    if \(imp\) \{ e\.target\.classList\.remove\('error'\); writeImperial\(imp\); checkBox\(imp\);/.test(js)
    && /formData\[name\] = name === 'height' \? keepHeight\(v, \[e\.target\]\) : v;\n    checkBox\(name\);/.test(js), 'P3.6 a box left goes to checkBox after today\'s write (checkBox returns at once with the switch off)');
  ok(/if \(tapStep === 0\) tapFirstNext\(data\); else tapGo\(data, 1\);/.test(fn('renderTapStep')), 'P3.7 the Next button\'s line is today\'s (tests/second-form-copy N9.7)');
  ok(/^  function tapGo\(data, delta\) \{\n[^\n]*\n    if \(delta > 0 && tapStep === TAP_STEPS - 2 && numbersHold\(\)\) return;\n    tapLeave\(\);/.test(fn('tapGo')), 'P3.8 tapGo asks numbersHold first, forward from the measurements only');
}
// The steps are drawn the same with the switch on and off (the lines come only from what is typed).
const TAP_SRC = [cut('  var S3_OPTIONS = {', '\n  };\n'), cut('  var TEST_HOW = {', '\n  };\n'), cut('  var TAP_STEPS = ', ';\n'), cut('  var TAP_FIRST = ', ';\n'),
  cut('  var TAP_FIRST_LABELS = {', '\n  };\n'), cut('  var TAP_ORDER = ', ';\n'), cut('  var TAP_NEUTRAL = ', ';\n'), cut('  var TAP_TITLES = {', '\n  };\n'),
  cut('  var S1_IMG_BASE = ', '\n  })();\n'), cut('  var TAP_MOTION = {', '\n  };\n'), cut('  var ICONS = {', '\n  };\n'), cut('  var ROW_ICONS = {', '\n  };\n'),
  cut('  function rn(', '\n'), fn('motionFigure'), fn('tapHeading'), fn('tapReason'), fn('tapFirstRows'), fn('renderTapStep'), fn('wizardSectionContent'), cut('  var IN_CM = ', ';\n'), fn('r1'),
  fn('toImperial'), fn('unitsDefault'), fn('unitsNow'), fn('unitsSwitch'), fn('measureInput'), fn('fieldInputWithSkip'), fn('renderTooltip'), fn('fieldReadonly'), fn('fieldTextarea')].join('\n');
function draw(cfg, n) {
  const c = Object.assign({ plugin_url: 'https://example.test/', asset_ver: 't' }, cfg);
  const ctx = { CFG: c, window: { hdlv2_form: c }, document: { getElementById: () => null, getElementsByTagName: () => [] }, esc: (s) => String(s == null ? '' : s),
    setRootClasses() {}, tapLeave() {}, bindTapRows() {}, bindTapQuestion() {}, bindFieldListeners() {}, bindTapComplete() {}, bindUnits() {}, NONE_SENTINEL: '__none__',
    brandBar: () => '', saveIndicator: () => '', footer: () => '', serverData: {}, ABILITY_SENTENCES: {}, tapCards: () => '', tapFirstValues: () => ({}),
    tapSection: (id) => ({ id }), prefillHint: () => '', fieldSelect: () => '', s1Prefill: () => '', localStorage: { getItem: () => null },
    root: { innerHTML: '' }, formData: { bpSystolic: '260', height: '100' }, tapOpenRow: null, tapStep: n, tapOrder: [] };
  vm.runInNewContext(TAP_SRC + '\ntapOrder = TAP_ORDER;\nrenderTapStep({ stage1_data: { q1_age: 50 }, second_form_ties: {} }, false);', ctx);
  return ctx.root.innerHTML;
}
for (const n of [11, 12]) ok(draw(ON, n) === draw(OFF, n) && draw(ON, n).length > 200, `P3.${n - 3} step ${n + 1} is drawn the same on and off`);

console.log('── the style rules ──');
const css = fs.readFileSync(new URL('../../assets/css/hdlv2-form.css', import.meta.url), 'utf8');
const block = css.slice(css.indexOf('/* v0.48.97 (plan 11; hdlv2_ff_input_checks)'), css.indexOf('/* end input checks */'));
const classes = [...new Set((block.match(/\.hdlv2-[\w-]+/g) || []).map((c) => c.slice(1)))];
const drawn = classes.filter((c) => js.indexOf(c) !== -1 || ['hdlv2-btn', 'hdlv2-s1-next'].includes(c));
ok(block.length > 100 && J(drawn) === J(classes), 'S1 every class in the block is one the script draws (' + classes.length + ')');
ok(!/border-left|::before|box-shadow/.test(block), 'S2 no rail and no shadow');
ok(!/#[0-9a-f]{3,6}\b/gi.test(block.replace(/#004F59|#3d8da0|#2c3e50|#dc2626|#e4e6ea|#f8f9fb|#fff\b/gi, '')), 'S3 brand and status colours only');
ok(/\.hdlv2-check-line\.is-hard \{ color: #dc2626; \}/.test(block) && /\.hdlv2-check-keep \{ min-height: 40px;/.test(block), 'S4 a refusal in status red; the keep button at least 40 px tall');
ok(/\.hdlv2-gap-card \+ \.hdlv2-s1-next \{ display: none; \}/.test(block), 'S5 the finish button waits under the card while it shows');

console.log(`PASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
