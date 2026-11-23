#!/usr/bin/env node
/**
 * v0.48.97 (plan 11, I5; hdlv2_ff_input_checks) — the free report's first step: the age box starts empty
 * with the key on, and Continue waits for an age from 1 to 120 with one line under the box. The REAL
 * renderQ1() and ageLine() of widget/hdl-lead-magnet.js, run in vm.
 *   W1 key off: the step is today's, character for character (sha256 fixtures taken before the change),
 *      and Continue behaves as today (red border, no line).
 *   W2 key on: an empty box with its placeholder; a stored age still shows.
 *   W3 key on: Continue with no age, 0 or 121 stays and says "Please type your age." under the box, tied
 *      to it for screen readers; a good age moves on and the line goes.
 *   W4 the key comes from the public answer only; the dashboard's preview helper keeps its 50.
 * Run: node tests/input-checks/test-widget-age.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';
import crypto from 'node:crypto';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const sha = (s) => crypto.createHash('sha256').update(s).digest('hex');
const js = fs.readFileSync(new URL('../../widget/hdl-lead-magnet.js', import.meta.url), 'utf8');
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const B = "var B = { body: \"'Inter',sans-serif\" };\n";
const SRC = B + cut('    function ageLine(', '\n    }\n') + cut('    function renderQ1(', '\n    }\n');

/** A page of fake elements: an id exists once the step's markup (or an inserted line) names it. */
function step(cfg, answers = {}, invite = false) {
  const els = {}, html = () => ctx.contentEl.innerHTML + inserted.join('');
  const inserted = [];
  const el = (id) => {
    const attrs = {}, cls = new Set(), handlers = {};
    const m = ctx.contentEl.innerHTML.match(new RegExp('id="' + id + '"[^>]*value="([^"]*)"'));
    return { id, value: m ? m[1] : '', style: {}, attrs, handlers,
      setAttribute(k, v) { attrs[k] = String(v); }, removeAttribute(k) { delete attrs[k]; }, getAttribute(k) { return k in attrs ? attrs[k] : null; },
      classList: { add: (c) => cls.add(c), remove: (c) => cls.delete(c), contains: (c) => cls.has(c) },
      addEventListener(t, f) { handlers[t] = f; }, click() { handlers.click && handlers.click.call(this); },
      insertAdjacentHTML(pos, h) { inserted.push(h); },
      remove() { delete els[id]; const i = inserted.findIndex((h) => h.indexOf('id="' + id + '"') !== -1); if (i !== -1) inserted.splice(i, 1); } };
  };
  const ctx = { contentEl: { innerHTML: '' }, id: 'hdlw-122', linkInvite: invite, linkFrom: 'A <b>friend</b>', answers, cfg, escHtml: esc, went: [],
    goTo(n) { ctx.went.push(n); },
    document: { getElementById: (i) => (html().indexOf('id="' + i + '"') !== -1 ? (els[i] = els[i] || el(i)) : null) } };
  vm.runInNewContext(SRC + '\nrenderQ1();', ctx);
  return { ctx, els, inserted, html };
}
const press = (s, age, sex = 'female') => {
  const box = s.ctx.document.getElementById('hdlw-122-q1age');
  box.value = age;
  if (sex) s.ctx.answers.q1_sex = sex;
  s.ctx.document.getElementById('hdlw-122-q1next').click();
  return box;
};

console.log('── W1: key off, today\'s step ──');
ok(cut('    function renderQ1(', '\n    }\n') !== '', 'W1.0 renderQ1 is found');
const FIX = { plain: 'd419592a1d2d9651318560c2a1b5827904e2695347fb441e4b981023d259b6ce', invite: 'c6035259ebe2d9e4c5ab4b3d9b685f7767d276819696d13893f3f05c29d9f038',
  stored: '722a49b7cf0d1e1a30827d4f3278c621ba2b95871d35ec2441eda8b5ada3bdf9' };
for (const cfg of [{}, { ageTyped: false }]) {
  const tag = 'ageTyped' in cfg ? 'false' : 'absent';
  ok(sha(step(cfg).ctx.contentEl.innerHTML) === FIX.plain, `W1.1.${tag} the step is today's (the box holds 50)`);
  ok(sha(step(cfg, {}, true).ctx.contentEl.innerHTML) === FIX.invite, `W1.2.${tag} with an invite line, today's`);
  ok(sha(step(cfg, { q1_age: 44, q1_sex: 'female' }).ctx.contentEl.innerHTML) === FIX.stored, `W1.3.${tag} with a stored age, today's`);
}
{
  const s = step({}), box = press(s, '');
  ok(s.ctx.went.length === 0 && box.style.borderColor === '#dc2626' && s.inserted.length === 0, 'W1.4 off, no age: stays, red border, no line (today)');
  const s2 = step({}); press(s2, '50');
  ok(s2.ctx.went.join() === '1' && s2.ctx.answers.q1_age === 50, 'W1.5 off, 50: moves on');
  const s3 = step({}); press(s3, '200');
  ok(s3.ctx.went.join() === '1', 'W1.6 off, 200: moves on as today (no upper limit without the key)');
}

console.log('── W2: key on, the box starts empty ──');
const on = step({ ageTyped: true });
ok(/id="hdlw-122-q1age"[^>]* value="" placeholder="Your age in years"/.test(on.ctx.contentEl.innerHTML), 'W2.1 no age stored: an empty box with its placeholder');
ok(on.ctx.contentEl.innerHTML.indexOf('value="50"') === -1, 'W2.2 no 50 anywhere on the step');
ok(/id="hdlw-122-q1age"[^>]* value="44"/.test(step({ ageTyped: true }, { q1_age: 44 }).ctx.contentEl.innerHTML), 'W2.3 a stored age still shows');
ok(step({ ageTyped: true }).ctx.contentEl.innerHTML.replace('value=""', 'value="50"') === step({}).ctx.contentEl.innerHTML, 'W2.4 nothing else on the step changes');

console.log('── W3: key on, Continue waits for an age from 1 to 120 ──');
for (const bad of ['', '0', '121', '-4']) {
  const s = step({ ageTyped: true }), box = press(s, bad);
  const line = s.ctx.document.getElementById('hdlw-122-q1age-line');
  ok(s.ctx.went.length === 0 && !!line, `W3.1 "${bad}": stays, a line under the box`);
  ok(s.inserted.length === 1 && /role="alert"/.test(s.inserted[0]) && />Please type your age\.<\/p>$/.test(s.inserted[0]), `W3.2 "${bad}": the line says "Please type your age." and is announced`);
  ok(box.attrs['aria-describedby'] === 'hdlw-122-q1age-line' && box.attrs['aria-invalid'] === 'true' && box.style.borderColor === '#dc2626', `W3.3 "${bad}": tied to the box (aria-describedby, aria-invalid), red border`);
  ok(/color:#dc2626/.test(s.inserted[0] || '') && /font-family:'Inter'/.test(s.inserted[0] || ''), `W3.4 "${bad}": status red, the widget's body font`);
}
{
  const s = step({ ageTyped: true }); press(s, ''); press(s, '');
  ok(s.inserted.length === 1, 'W3.5 pressed twice: still one line');
  const box = press(s, '45');
  ok(s.ctx.went.join() === '1' && s.ctx.answers.q1_age === 45, 'W3.6 then 45: moves on with 45');
  ok(s.inserted.length === 0 && !('aria-describedby' in box.attrs) && !('aria-invalid' in box.attrs), 'W3.7 the line and the ties go');
  const t = step({ ageTyped: true }); press(t, '120');
  ok(t.ctx.went.join() === '1', 'W3.8 120 is allowed');
  const u = step({ ageTyped: true }); press(u, '1');
  ok(u.ctx.went.join() === '1', 'W3.9 1 is allowed');
  const v = step({ ageTyped: true }); press(v, '30', null);
  ok(v.ctx.went.length === 0 && v.inserted.length === 0, 'W3.10 an age but no sex: stays as today, no age line');
}

console.log('── W4: where the key comes from ──');
ok(/cfg\.ageTyped = !!\(publicCfg && publicCfg\.age_typed\);/.test(js), 'W4.1 cfg.ageTyped comes from the public answer\'s age_typed');
ok((js.match(/age_typed/g) || []).length === 1, 'W4.2 age_typed is read in one place');
ok(/if \(!answers\.q1_age\) answers\.q1_age = 50;/.test(cut('    el.__hdlGoTo = function', '\n    };\n')), 'W4.3 the dashboard\'s preview helper keeps its 50 (not a visitor\'s path)');

console.log(`PASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
