#!/usr/bin/env node
/**
 * v0.48.90 (plan 7, Push A) — the widget tells the page that hosts it when the answers are saved (or safely held)
 * and when the visitor reaches the safety check. Altituding listens, so its one reminder e-mail never reaches
 * someone who finished or stopped at the safety check. The event carries the e-mail typed at the contact step and
 * nothing else. The REAL widget source is cut and run in vm (the tests/stage1-resume pattern of PR #36).
 * Run: node tests/stage1-finished/test-finished-signal.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
/** A check that fails, not crashes, while the code it drives does not exist yet. */
const t = (l, fn) => { let c = false; try { c = !!fn(); } catch (e) { l += '  [' + String(e.message).slice(0, 80) + ']'; } ok(c, l); };

const js = fs.readFileSync(new URL('../../widget/hdl-lead-magnet.js', import.meta.url), 'utf8');
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const count = (needle) => js.split(needle).length - 1;
const tellSrc = cut('    function tell(type) {', '\n    }\n');

/** Runs the real tell() with a host element that records what it is sent. */
const tell = (type, apiUrl, withEvent = true) => {
  const events = [];
  const ctx = {
    cfg: { apiUrl },
    answers: { q1_age: 44, q9: 'a', _name: 'Test Person', _email: 'person@example.test', _phone: '+44 7700 900000', _safety: { symptoms: ['chest'] } },
    el: { dispatchEvent: (e) => { events.push(e); } },
  };
  if (withEvent) ctx.CustomEvent = function (name, init) { this.type = name; this.bubbles = !!(init && init.bubbles); this.detail = init && init.detail; };
  vm.runInNewContext(tellSrc + '\ntell(' + JSON.stringify(type) + ');', ctx);
  return events;
};
const API = 'https://site.test/wp-json/hdl-v2/v1/widget/lead';

console.log('── 1. tell() ──');
ok(tellSrc !== '', '1.0 the widget has a tell(type) function');
t('1.1 one event of the asked type, and it bubbles to the host page', () => {
  const e = tell('hdlw:finished', API);
  return e.length === 1 && e[0].type === 'hdlw:finished' && e[0].bubbles === true;
});
t('1.2 its detail holds the typed e-mail and nothing else (no name, phone, answer or safety tick)', () => {
  const d = tell('hdlw:finished', API)[0].detail;
  return JSON.stringify(d) === JSON.stringify({ email: 'person@example.test' });
});
t('1.3 no API (the practitioner’s preview): no event', () => tell('hdlw:finished', '').length === 0);
t('1.4 a browser without CustomEvent: no event, no error', () => tell('hdlw:finished', API, false).length === 0);
t('1.5 the safety-check event has the same shape', () => {
  const e = tell('hdlw:safety-check', API);
  return e.length === 1 && e[0].type === 'hdlw:safety-check' && JSON.stringify(e[0].detail) === JSON.stringify({ email: 'person@example.test' });
});

console.log('── 2. where it is called ──');
t('2.1 saved( tells "finished" first thing (the answers are on our server)', () =>
  /\n      function saved\(data\) \{\n        tell\('hdlw:finished'\);/.test(js));
t('2.2 held( tells "finished" first thing (the answers are safely held and saved later)', () =>
  /\n      function held\(\) \{\n        tell\('hdlw:finished'\);/.test(js));
t('2.3 the safety check tells "safety-check" just before it is drawn, and only on that branch', () =>
  /if \(cfg\.safetyScreen && !answers\._safetyDone\) \{\n          tell\('hdlw:safety-check'\);\n          \(cfg\.safetyNotice \? renderSafetyScreenV2 : renderSafetyScreen\)\(\);/.test(js));
t('2.4 three calls and one definition, nothing else sends these events', () =>
  count("tell('hdlw:finished')") === 2 && count("tell('hdlw:safety-check')") === 1
  && count('hdlw:finished') === 2 && count('hdlw:safety-check') === 1 && count('function tell(') === 1);
t('2.5 the event names the check everybody sees, never what was ticked (no crisis or symptom event)', () =>
  !/hdlw:(crisis|hard|symptom|stop)/.test(js));

console.log('\nPASS=' + PASS + ' FAIL=' + FAIL);
process.exit(FAIL === 0 ? 0 : 1);
