#!/usr/bin/env node
/**
 * v0.48.50 (W41) — the measurements screen in cm/kg or ft in/lb. The REAL functions of
 * assets/js/hdlv2-staged-form.js run in vm:
 *   - toMetric / toImperial: 5 ft 10 in, 165 lb, 34 in, 38 in → 177.8 cm, 74.8 kg, 86.4 cm, 96.5 cm, and back within 0.1;
 *   - the default by browser language (en-US imperial; en-GB, de metric), remembered under hdlv2-units;
 *   - the switch above the fields (two options, a filled pill, no rail); in imperial height is two inputs;
 *   - typing imperial writes METRIC strings into formData under the same four names; heart rate and
 *     blood pressure untouched; metric markup is today's markup plus the switch.
 * Run: node tests/imperial/test-imperial.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const J = JSON.stringify;
const js = fs.readFileSync(new URL('../../assets/js/hdlv2-staged-form.js', import.meta.url), 'utf8');
const fnIn = (text, name) => (text.match(new RegExp('  function ' + name + '\\([^)]*\\) \\{[\\s\\S]*?\\n  \\}')) || [''])[0];
const varIn = (text, head) => { const at = text.indexOf('  var ' + head); return at < 0 ? '' : text.slice(at, text.indexOf(';\n', at) + 2); };
const ESC = "function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\"/g, '&quot;'); }\n";
const NAMES = ['r1', 'toMetric', 'toImperial', 'unitsDefault', 'unitsNow', 'unitsSwitch', 'measureInput', 'writeImperial'];
const src = varIn(js, 'IN_CM') + NAMES.map((n) => fnIn(js, n)).join('\n');
ok(NAMES.every((n) => fnIn(js, n) !== '') && varIn(js, 'IN_CM') !== '', 'the unit functions are top-level in the form script');

function ctxWith({ lang, stored, els } = {}) {
  const store = stored ? { 'hdlv2-units': stored } : {};
  const c = { formData: {}, navigator: lang ? { language: lang } : undefined,
    localStorage: { getItem: (k) => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = v; } }, store,
    document: { getElementById: (id) => (els && id in els ? { value: els[id] } : null) },
    fieldInputWithSkip: (name, label, type, value, ph) => `[metric ${name}|${label}|${value || ''}|${ph}]`, renderTooltip: (t) => ` [tip ${t}]` };
  if (NAMES.every((n) => fnIn(js, n))) vm.runInNewContext(ESC + src, c);
  return c;
}

const C = ctxWith();
if (C.toMetric) {
  ok(J(C.toMetric({ ftIn: [5, 10], lb: 165, waistIn: 34, hipIn: 38 })) === J({ height_cm: 177.8, weight_kg: 74.8, waist_cm: 86.4, hip_cm: 96.5 }), 'toMetric: 5 ft 10 in, 165 lb, 34 in, 38 in → 177.8 cm, 74.8 kg, 86.4 cm, 96.5 cm');
  const back = C.toImperial({ height_cm: 177.8, weight_kg: 74.8, waist_cm: 86.4, hip_cm: 96.5 });
  ok(back.ftIn[0] === 5 && Math.abs(back.ftIn[1] - 10) <= 0.1 && Math.abs(back.lb - 165) <= 0.1 && Math.abs(back.waistIn - 34) <= 0.1 && Math.abs(back.hipIn - 38) <= 0.1, 'toImperial back within 0.1 (' + J(back) + ')');
  ok(J(C.toImperial({ height_cm: 182.9 }).ftIn) === '[6,0]' && J(C.toImperial({ height_cm: 170 }).ftIn) === '[5,6.9]', 'feet and inches split right (182.9 cm = 6 ft 0 in; 170 cm = 5 ft 6.9 in)');
  ok(J(C.toMetric({ lb: 165 })) === J({ weight_kg: 74.8 }) && J(C.toMetric({})) === '{}', 'only the parts given are converted');

  ok(ctxWith({ lang: 'en-US' }).unitsNow() === 'imperial' && ctxWith({ lang: 'en-GB' }).unitsNow() === 'metric' && ctxWith({ lang: 'de' }).unitsNow() === 'metric' && ctxWith({}).unitsNow() === 'metric', 'default by browser language: en-US imperial; en-GB, de and none metric');
  ok(ctxWith({ lang: 'en-US', stored: 'metric' }).unitsNow() === 'metric' && ctxWith({ lang: 'de', stored: 'imperial' }).unitsNow() === 'imperial', 'a choice kept under hdlv2-units wins');
  const throws = ctxWith({ lang: 'en-US' }); throws.localStorage = { getItem() { throw new Error('private'); } };
  ok(throws.unitsNow() === 'imperial', 'storage blocked: the default, no error');

  const sw = C.unitsSwitch('imperial');
  ok(/role="radiogroup" aria-label="Units"/.test(sw) && (sw.match(/role="radio"/g) || []).length === 2, 'the switch: two radio options');
  ok(/data-units="metric" aria-checked="false">cm · kg</.test(sw) && /class="hdlv2-units-opt is-on" data-units="imperial" aria-checked="true">ft in · lb</.test(sw), 'cm · kg | ft in · lb, the active one filled (is-on)');

  const I = ctxWith({ lang: 'en-US' });
  const h = I.measureInput('height', { height: '177.8' });
  ok(/id="hdlv2-f-height-ft"[^>]*value="5"/.test(h) && /id="hdlv2-f-height-in"[^>]*value="10"/.test(h) && h.indexOf('data-field=') === -1, 'imperial height: two inputs (ft, in), shown from the metric value, not bound to formData directly');
  ok(/Weight \(lb\)/.test(I.measureInput('weight', { weight: '74.8' })) && /value="164.9"/.test(I.measureInput('weight', { weight: '74.8' })), 'imperial weight in lb');
  ok(/Waist \(in\) \[tip Measure/.test(I.measureInput('waist', { waist: '' }, { tooltip: 'Measure around' })), 'the waist tooltip stays');
  ok(I.measureInput('hip', { hip: 'skip' }).indexOf('value=""') > 0, 'a skipped measure shows empty');
  ok(C.measureInput('height', { height: '170' }) === '[metric height|Height (cm)|170|170]', 'metric: exactly today\'s field call');

  const W = ctxWith({ lang: 'en-US', els: { 'hdlv2-f-height-ft': '5', 'hdlv2-f-height-in': '10', 'hdlv2-f-weight-imp': '165', 'hdlv2-f-waist-imp': '34', 'hdlv2-f-hip-imp': ' ' } });
  W.formData = { restingHeartRate: '60', bpSystolic: '120', hip: '95' };
  ['height', 'weight', 'waist', 'hip'].forEach((n) => W.writeImperial(n));
  ok(J(W.formData) === J({ restingHeartRate: '60', bpSystolic: '120', hip: '', height: '177.8', weight: '74.8', waist: '86.4' }), 'typed imperial → metric strings under the same names; an emptied box clears its value; heart rate and blood pressure untouched');
}

// The screen: the switch above the four fields; metric markup is today's plus the switch.
const body = (text) => { const a = text.indexOf("        + '<h5 class=\"hdlv2-subgroup-heading\">Body shape</h5>'"); return text.slice(a, text.indexOf("        + '<h5 class=\"hdlv2-subgroup-heading\">Cardiovascular</h5>'", a)); };
const nb = body(js);
ok(nb.indexOf("+ unitsSwitch(unitsNow())") > 0 && nb.indexOf("measureInput('height', d)") > 0 && nb.indexOf("measureInput('hip', d,") > 0, 'the body block draws the switch, then the four fields through measureInput');
ok(/function onFieldChange\(e\) \{\n    var imp = e\.target\.getAttribute\('data-imp'\);\n    if \(imp\) \{ writeImperial\(imp\);/.test(js), 'onFieldChange hands an imperial box to writeImperial');
ok(js.indexOf("localStorage.setItem('hdlv2-units'") > 0 || js.indexOf('localStorage.setItem(UNITS_KEY') > 0, 'a switch click is remembered');
const css = fs.readFileSync(new URL('../../assets/css/hdlv2-form.css', import.meta.url), 'utf8');
const rule = (css.match(/\.hdlv2-units-opt\.is-on \{[^}]*\}/) || [''])[0];
ok(rule !== '' && /background: #3d8da0/.test(rule) && !/border-left/.test(css.slice(css.indexOf('.hdlv2-units'))), 'the active option is a filled teal pill; no rail');

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
