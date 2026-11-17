#!/usr/bin/env node
/**
 * v0.48.80 (plan 2f) — continue where you stopped (hdlv2_ff_stage1_resume). The nine answers wait in this
 * browser only, under the practitioner's number, for seven days; a finish wipes them and tells the page that
 * hosts the widget, whatever the switch says. The REAL widget source is cut and run in vm
 * (the tests/result-offer/test-result-card.mjs pattern).
 * Run: node tests/stage1-resume/test-answer-store.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
/** A check that fails, not crashes, while the code it drives does not exist yet. */
const t = (l, fn) => { let c = false; try { c = !!fn(); } catch (e) { l += '  [' + String(e.message).slice(0, 80) + ']'; } ok(c, l); };

const js = fs.readFileSync(new URL('../../widget/hdl-lead-magnet.js', import.meta.url), 'utf8');
/** The source from `head` to the first `end` after it (inclusive), or '' when `head` is absent. */
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const fn = (name, indent = '  ') => cut(indent + 'function ' + name + '(', '\n' + indent + '}\n');
const count = (needle) => js.split(needle).length - 1;

const DAY = 24 * 60 * 60 * 1000, NOW = 1795000000000;
const KEYS = ['q1_age', 'q1_sex', 'q2a', 'q2b', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8', 'q9'];
const FOUR = { q1_age: 44, q1_sex: 'female', q2a: 2, q2b: 'even', q3: 'c', q4: 'd' };
const NINE = Object.assign({}, FOUR, { q5: 'b', q6: 'd', q7: 'e', q8: 'c', q9: 'a' });

/** A browser's storage: a Map behind the three calls the widget makes. */
const storage = (seed = {}) => {
  const m = new Map(Object.entries(seed));
  return { m, getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => { m.set(k, String(v)); }, removeItem: (k) => { m.delete(k); } };
};
const pieces = [
  cut('  var Q2A_SCORES = ', ';'), cut('  var Q2B_MODS = ', ';'), cut('  var QUESTIONS = {', '\n  };\n'),
  cut('  var ANSWER_KEYS = ', ';'), cut('  var RESUME_MS = ', ';'),
  fn('takeAnswers'), fn('answerOffered'), fn('answerStore'), fn('resumeStep'),
];
/** The real store code in a fresh world: `win` is its window, the clock reads `clock.t`. */
const world = (win, clock = { t: NOW }) => {
  const ctx = vm.createContext({ window: win, Date: { now: () => clock.t } });
  vm.runInContext(pieces.join('\n'), ctx);
  return ctx;
};
const plain = (v) => JSON.parse(JSON.stringify(v === undefined ? null : v));
const record = (answers, step, at) => JSON.stringify({ answers, step, at });

console.log('── 0. the pieces exist ──');
ok(pieces.every(Boolean), '0.1 the key list, the seven-day number and the four functions are found in the widget');
ok(count("'q1_age','q1_sex','q2a','q2b','q3','q4','q5','q6','q7','q8','q9'") === 1, '0.2 one list of the eleven answer keys in the file');

console.log('── 1. what is written ──');
t('1.1 only the eleven answer keys: no name, e-mail, phone or safety tick reaches the stored text', () => {
  const s = storage();
  world({ localStorage: s }).answerStore('122').set(Object.assign({}, FOUR, {
    _name: 'Test Person', _email: 'person@example.test', _phone: '+44 7700 900000',
    _safety: { symptoms: ['chest'] }, _safetyDone: true, _safetyAck: { notice: 'uk', version: 3, country: 'GB' },
  }), 4);
  const text = s.m.get('hdlw-s1-122') || '';
  const kept = JSON.parse(text);
  return Object.keys(kept).sort().join() === 'answers,at,step'
    && Object.keys(kept.answers).every((k) => KEYS.includes(k)) && Object.keys(kept.answers).length === 6
    && kept.step === 4 && kept.at === NOW
    && !/Test Person|example\.test|7700|chest|_name|_email|_phone|_safety|GB/.test(text);
});
t('1.2 nothing to keep (no answer yet): no key is left behind', () => {
  const s = storage({ 'hdlw-s1-122': record(FOUR, 4, NOW) });
  world({ localStorage: s }).answerStore('122').set({ _name: 'Test' }, 0);
  return s.m.size === 0;
});

console.log('── 2. what is read back ──');
const read = (answers, step = 4, at = NOW - DAY) => {
  const s = storage({ 'hdlw-s1-122': record(answers, step, at) });
  return { got: plain(world({ localStorage: s }).answerStore('122').get()), s };
};
t('2.1 good answers come back with their step', () => {
  const { got } = read(FOUR);
  return JSON.stringify(got.answers) === JSON.stringify(FOUR) && got.step === 4;
});
t('2.2 a value that is not one of the question’s options is dropped, the rest stay', () => {
  const { got } = read(Object.assign({}, FOUR, { q3: 'z', q4: 'f', q2a: 9, q2b: 'square' }));
  return JSON.stringify(got.answers) === JSON.stringify({ q1_age: 44, q1_sex: 'female' });
});
t('2.3 an age that is not a whole number from 1 to 120 is dropped', () =>
  [0, 121, 44.5, -3, '44', true].every((age) => !('q1_age' in read(Object.assign({}, FOUR, { q1_age: age })).got.answers))
  && [1, 120].every((age) => read(Object.assign({}, FOUR, { q1_age: age })).got.answers.q1_age === age));
t('2.4 an unknown sex is dropped', () => {
  const { got } = read(Object.assign({}, FOUR, { q1_sex: 'other' }));
  return !('q1_sex' in got.answers) && got.answers.q3 === 'c';
});
t('2.5 keys that are not answers never come back', () => {
  const { got } = read(Object.assign({}, FOUR, { _email: 'person@example.test', __proto__x: 1, q10: 'a' }));
  return Object.keys(got.answers).sort().join() === Object.keys(FOUR).sort().join();
});
t('2.6 text that is not our record: nothing comes back and the key is removed', () =>
  ['not json', '[]', '"x"', '{"answers":null,"step":2,"at":' + (NOW - DAY) + '}', record({ q3: 'z' }, 2, NOW - DAY)].every((text) => {
    const s = storage({ 'hdlw-s1-122': text });
    return world({ localStorage: s }).answerStore('122').get() === null && s.m.size === 0;
  }));

console.log('── 3. seven days ──');
t('3.1 saved seven days and one minute ago: nothing comes back and the key is removed', () => {
  const { got, s } = read(FOUR, 4, NOW - 7 * DAY - 60000);
  return got === null && s.m.size === 0;
});
t('3.2 saved six days and 23 hours ago: kept', () => {
  const { got, s } = read(FOUR, 4, NOW - 7 * DAY + 3600000);
  return got && got.answers.q4 === 'd' && s.m.size === 1;
});
t('3.3 no time, or a time that is not a number: nothing comes back', () => {
  const noTime = storage({ 'hdlw-s1-122': JSON.stringify({ answers: FOUR, step: 4 }) });
  return world({ localStorage: noTime }).answerStore('122').get() === null
    && [null, 'yesterday', {}].every((at) => read(FOUR, 4, at).got === null);
});
t('3.4 a clock set far ahead when it was saved: not kept for ever', () => read(FOUR, 4, NOW + 8 * DAY).got === null);

t('3.5 a later step keeps the first time: the seven days run from the first kept answer, whatever comes after', () => {
  const s = storage({ 'hdlw-s1-122': record(FOUR, 4, NOW - 3 * DAY) });
  world({ localStorage: s }).answerStore('122').set(Object.assign({}, FOUR, { q5: 'b' }), 5);
  const kept = JSON.parse(s.m.get('hdlw-s1-122'));
  return kept.at === NOW - 3 * DAY && kept.step === 5 && kept.answers.q5 === 'b';
});
t('3.6 a record that is too old, or not ours, does not lend its time to a new one', () =>
  [record(FOUR, 4, NOW - 8 * DAY), 'not json', JSON.stringify({ answers: FOUR, step: 4 })].every((text) => {
    const s = storage({ 'hdlw-s1-122': text });
    world({ localStorage: s }).answerStore('122').set(FOUR, 4);
    return JSON.parse(s.m.get('hdlw-s1-122')).at === NOW;
  }));

console.log('── 4. one practitioner’s answers ──');
t('4.1 the key carries the practitioner’s number', () => {
  const s = storage();
  world({ localStorage: s }).answerStore('122').set(FOUR, 4);
  return [...s.m.keys()].join() === 'hdlw-s1-122';
});
t('4.2 another practitioner’s answers are not read, and not removed', () => {
  const s = storage({ 'hdlw-s1-122': record(FOUR, 4, NOW - DAY) });
  const w = world({ localStorage: s });
  w.answerStore('206').drop();
  return w.answerStore('206').get() === null && s.m.size === 1;
});

console.log('── 5. storage that throws (private mode) ──');
const boom = () => { throw new Error('SecurityError'); };
for (const [label, win] of [
  ['every call throws', { localStorage: { getItem: boom, setItem: boom, removeItem: boom } }],
  ['reading window.localStorage itself throws', Object.defineProperty({}, 'localStorage', { get: boom })],
]) {
  t('5 ' + label + ': read gives nothing, write and delete are silent', () => {
    const st = world(win).answerStore('122');
    st.set(FOUR, 4); st.drop();
    return st.get() === null;
  });
}

console.log('── 6. the step to open ──');
const step = (a, saved) => world({ localStorage: storage() }).resumeStep(a, saved);
t('6.1 four questions answered, stopped on the fifth: the fifth opens', () => step(FOUR, 4) === 4);
t('6.2 never past the saved step (they had gone back to the third)', () => step(FOUR, 2) === 2);
t('6.3 the first question with no answer, whatever the saved step says', () => step(FOUR, 9) === 4 && step({ q1_age: 44, q1_sex: 'male', q3: 'c' }, 6) === 1);
t('6.4 the body-shape step needs both halves', () => step({ q1_age: 44, q1_sex: 'male', q2a: 2 }, 5) === 1);
t('6.5 all nine answered: the contact step, never the result', () => step(NINE, 9) === 9 && step(NINE, 10) === 9 && step(NINE, 99) === 9);
t('6.6 no answers, or a saved step that is not a step: the first question', () =>
  step({}, 3) === 0 && [undefined, null, 'x', -1, NaN].every((s) => step(FOUR, s) === 0));

console.log('── 7. the wiring ──');
t('7.1 the switch is on only from the public settings, and never with an invite', () => {
  const line = cut('    cfg.resume = ', ';');
  const on = (publicCfg, invite) => { const c = { publicCfg, invite, cfg: {} }; vm.runInNewContext(line + ';', c); return c.cfg.resume; };
  return line && on({ resume_enabled: true }, null) === true && on({ resume_enabled: true }, { valid: true }) === false
    && on({ resume_enabled: false }, null) === false && on(null, null) === false && on({}, undefined) === false
    && on(null, { resume_enabled: true }) === false;
});
t('7.1b the switch answered "off": what an earlier visit kept is wiped at the start; no answer at all keeps it', () => {
  const line = cut('    if (publicCfg && publicCfg.resume_enabled === false) ', ';');
  const drops = (publicCfg) => { let n = 0; vm.runInNewContext(line + ';', { publicCfg, kept: { drop: () => { n++; } } }); return n; };
  return line && drops({ resume_enabled: false }) === 1 && drops({ resume_enabled: true }) === 0 && drops({}) === 0 && drops(null) === 0;
});
const goToSrc = fn('goTo', '    ');
const goTo = (resume, to) => {
  const sets = [];
  const names = ['renderQ1', 'renderQ2', 'renderQ3', 'renderQ4', 'renderQ5', 'renderQ6', 'renderQ7', 'renderQ8', 'renderQ9', 'renderContact', 'renderResult'];
  const ctx = { cfg: { resume }, answers: FOUR, kept: { set: (a, s) => sets.push([a, s]) }, currentStep: 0,
    updateProgress() {}, updateStep() {}, updateNavArrows() {} };
  for (const n of names) ctx[n] = () => {};
  vm.runInNewContext(goToSrc + '\ngoTo(' + to + ');', ctx);
  return sets;
};
t('7.2 a step change writes the answers and the step when the switch is on', () => {
  const sets = goTo(true, 4);
  return sets.length === 1 && sets[0][0] === FOUR && sets[0][1] === 4;
});
t('7.3 the contact step is written, the result step is not', () => goTo(true, 9).length === 1 && goTo(true, 10).length === 0);
t('7.4 switch off: nothing is ever written', () => [0, 4, 9, 10].every((s) => goTo(false, s).length === 0));
t('7.5 the kept answers are taken only when the switch is on, and the widget starts on their step', () =>
  /resumed = cfg\.resume \? kept\.get\(\) : null/.test(js) && /\n    goTo\(resumed \? resumeStep\(answers, resumed\.step\) : 0\);/.test(js));
t('7.6 an invite’s prefill is taken as before: numbers for age and shape, text for the rest, unchecked', () => {
  const w = world({ localStorage: storage() });
  const got = plain(w.takeAnswers({ q1_age: '47', q2a: '3', q3: 5, q9: 'zz', q5: null, extra: 'x', q1_sex: 'Female' }, false));
  return JSON.stringify(got) === JSON.stringify({ q1_age: 47, q1_sex: 'Female', q2a: 3, q3: '5', q9: 'zz' })
    && /answers = takeAnswers\(invite\.prefill_stage1, false\)/.test(js);
});

console.log('── 8. finished ──');
const finishedSrc = fn('finished', '    ');
const finish = (apiUrl, withEvent = true) => {
  const seen = { drops: 0, events: [] };
  const ctx = { cfg: { apiUrl }, kept: { drop: () => { seen.drops++; } },
    answers: Object.assign({}, NINE, { _name: 'Test Person', _email: 'person@example.test', _phone: '+44 7700 900000', _safety: { symptoms: ['chest'] } }),
    el: { dispatchEvent: (e) => { seen.events.push(e); } } };
  if (withEvent) ctx.CustomEvent = function (type, init) { this.type = type; this.bubbles = !!(init && init.bubbles); this.detail = init && init.detail; };
  vm.runInNewContext(finishedSrc + '\nfinished();', ctx);
  return seen;
};
t('8.1 the kept answers are wiped and the page is told once, by an event that bubbles', () => {
  const s = finish('https://site.test/wp-json/hdl-v2/v1/widget/lead');
  return s.drops === 1 && s.events.length === 1 && s.events[0].type === 'hdlw:finished' && s.events[0].bubbles === true;
});
t('8.2 the signal’s detail holds the e-mail and nothing else', () => {
  const d = finish('https://site.test/x').events[0].detail;
  return JSON.stringify(d) === JSON.stringify({ email: 'person@example.test' });
});
t('8.3 no API (the practitioner’s preview): wiped, no signal', () => { const s = finish(''); return s.drops === 1 && s.events.length === 0; });
t('8.4 a browser without CustomEvent: wiped, no signal, no error', () => { const s = finish('https://site.test/x', false); return s.drops === 1 && s.events.length === 0; });
t('8.5 the wipe and the signal do not ask the switch', () => finishedSrc && !/cfg\.resume/.test(finishedSrc));
t('8.6 saved( and held( both call it, first thing', () =>
  /\n      function saved\(data\) \{\n        finished\(\);/.test(js) && /\n      function held\(\) \{\n        finished\(\);/.test(js) && count('finished();') === 2);

console.log('── 9. the box ──');
const WORDS = ['Welcome back. Your answers so far are still here.', '>Start again<', 'Clear your answers and start from the first question?', '>Yes, start again<', '>Keep my answers<'];
t('9.1 the words of the box, each once', () => WORDS.every((w) => count(w) === 1));
const styles = js.split('\n').filter((l) => /'\.hdlw-resume/.test(l));
t('9.2 its styles: a 1 px border on all four sides, no bar on the left', () =>
  styles.length > 0 && /\.hdlw-resume\{[^}]*border:1px solid/.test(styles.join('\n')) && !/border-left|::before|!important/.test(styles.join('\n')));
t('9.2b the styles sit in their own tag, added only when the box shows', () =>
  styles.length === 4 && /\n    function showResumeBox\(\) \{\n      injectResumeStyles\(\);/.test(js) && count('injectResumeStyles();') === 1);
t('9.3 the box is not the callout with the left bar', () => !/hdlw-callout[^'"]*hdlw-resume|hdlw-resume[^'"]*hdlw-callout/.test(js));
t('9.4 no browser pop-up anywhere in the widget', () => !/\b(confirm|alert|prompt)\(/.test(js));
t('9.5 the buttons are real buttons with a visible focus ring', () =>
  count('<button type="button" class="hdlw-resume-') === 3 && /\.hdlw-resume button:focus-visible\{outline:2px solid/.test(styles.join('\n')));

/** The real box in a stand-in page: what it shows, and what each press does. */
const boxWorld = () => {
  const seen = { drops: 0, goTo: [], styles: 0, focus: [], inserted: null };
  const box = {
    html: '', listeners: {}, setAttribute() {}, addEventListener(type, f) { this.listeners[type] = f; },
    set innerHTML(v) { this.html = v; }, get innerHTML() { return this.html; },
    get lastChild() { const all = [...this.html.matchAll(/class="(hdlw-resume-[a-z]+)"/g)]; return { focus: () => seen.focus.push(all[all.length - 1][1]) }; },
  };
  const ctx = { document: { createElement: () => box }, contentEl: { firstChild: null, insertBefore: (n) => { seen.inserted = n; } },
    injectResumeStyles: () => { seen.styles++; }, kept: { drop: () => { seen.drops++; } }, goTo: (s) => seen.goTo.push(s), answers: { q3: 'c' } };
  vm.runInNewContext(fn('showResumeBox', '    ') + '\nshowResumeBox();', ctx);
  return { seen, box, ctx, press: (cls) => box.listeners.click({ target: { className: cls } }) };
};
t('9.7 the box opens with the welcome line above the question, and nothing is cleared', () => {
  const w = boxWorld();
  return w.seen.inserted === w.box && /Welcome back\./.test(w.box.html) && !/Clear your answers/.test(w.box.html) && w.seen.drops === 0 && w.seen.styles === 1;
});
t('9.8 "Start again" only asks; "Keep my answers" changes nothing and the keyboard stays in the box', () => {
  const w = boxWorld();
  w.press('hdlw-resume-again');
  const asked = /Clear your answers and start from the first question\?/.test(w.box.html) && !/Welcome back/.test(w.box.html);
  w.press('hdlw-resume-keep');
  w.press('');
  return asked && /Welcome back\./.test(w.box.html) && w.seen.drops === 0 && w.seen.goTo.length === 0 && w.ctx.answers.q3 === 'c'
    && w.seen.focus.join() === 'hdlw-resume-keep,hdlw-resume-again';
});
t('9.9 "Yes, start again" wipes the kept copy, empties the answers and opens the first question', () => {
  const w = boxWorld();
  w.press('hdlw-resume-again'); w.press('hdlw-resume-yes');
  return w.seen.drops === 1 && Object.keys(w.ctx.answers).length === 0 && w.seen.goTo.join() === '0';
});
t('9.6 the text buttons use the text colour, not the practitioner\u2019s own colour (a pale one would not read on the light fill)', () =>
  /\.hdlw-resume button\{[^}]*;color:' \+ B\.dark \+ ';/.test(styles.join('\n')) && !/\.hdlw-resume button\{[^}]*hdl-accent/.test(styles.join('\n')));

console.log('\n' + PASS + ' passed, ' + FAIL + ' failed');
process.exit(FAIL ? 1 : 0);
