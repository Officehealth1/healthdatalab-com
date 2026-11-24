#!/usr/bin/env node
/**
 * Plan 14 part B, B1/B4: the "Questions to ask now" card on the consultation screen. The REAL card functions
 * of assets/js/hdlv2-consultation.js, run in vm against a few fake elements and a fake fetch.
 *   K.1 the card: heading, "Suggest questions", the line about notes and stopped recordings; busy line hidden.
 *   K.2 no notes box (after the final report): no card.
 *   K.3 a press: one POST with the nonce and an Idempotency-Key, `heard` = the notes box as it is at the
 *       press (unsaved text too), `asked` empty; the button waits; a skeleton while it works.
 *   K.4 the answer: the red flag first, then the questions with their why, drawn as text (escaped).
 *   K.5 a second press sends the questions already shown as `asked`, with a new key.
 *   K.6 the busy line shows only while the recorder is busy; a press then still works from the notes so far.
 *   K.7 a failure: what was there comes back, "Could not get questions. Try again.", the button again.
 *   K.8 no questions and no flag: "Nothing to add yet."
 *   K.9 the card and its mount exist only with the live_questions setting; no native pop-up in the card.
 * Run: node tests/live-questions/test-questions-card.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const js = fs.readFileSync(new URL('../../assets/js/hdlv2-consultation.js', import.meta.url), 'utf8');
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const fn = (name) => cut('  function ' + name + '(', '\n  }\n');
const line = (head) => cut(head, '\n');
const NAMES = ['liveCard', 'mountLiveQuestions', 'liveBusy', 'liveList', 'askLiveQuestions'];
const SRC = [line('  function esc('), line('  var liveAsked = '), line('  var liveBusyTimer = '), ...NAMES.map(fn)].join('\n');
ok(NAMES.every((n) => fn(n) !== '') && line('  var liveAsked = ') !== '', 'the card functions are top-level in the consultation script');

/** Fake elements: setting innerHTML (re)creates the elements whose ids the markup names. */
function page(withNotes) {
  const reg = {};
  const mk = (id, tag, attrs = '', inner = '') => {
    const handlers = {};
    const e = { id, tag, attrs, _html: inner, kids: [], textContent: '', disabled: /\sdisabled/.test(attrs), hidden: /\shidden/.test(attrs), value: '', focused: false,
      get innerHTML() { return e._html; },
      set innerHTML(h) { e.kids.forEach((k) => delete reg[k]); e._html = h; e.kids = scan(h); },
      addEventListener(t, f) { (handlers[t] = handlers[t] || []).push(f); },
      click() { if (!e.disabled) (handlers.click || []).forEach((f) => f.call(e, { target: e })); },
      focus() { e.focused = true; } };
    return e;
  };
  function scan(h) {
    const ids = [], re = /<(\w+)\b([^>]*)>/g;
    let m;
    while ((m = re.exec(h))) {
      const idm = m[2].match(/\sid="([^"]+)"/);
      if (!idm) continue;
      reg[idm[1]] = mk(idm[1], m[1], m[2]);
      ids.push(idm[1]);
    }
    return ids;
  }
  const createElement = () => { let t = ''; return { set textContent(v) { t = String(v); }, get innerHTML() { return t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); } }; };
  reg['hdlv2-liveq'] = mk('hdlv2-liveq', 'div');
  if (withNotes) { reg['hdlv2-consult-notes'] = mk('hdlv2-consult-notes', 'textarea'); reg['hdlv2-consult-notes'].value = 'Saved notes.'; }
  return { reg, document: { getElementById: (id) => reg[id] || null, createElement } };
}

function boot(withNotes = true) {
  const p = page(withNotes), calls = [];
  let settle = null, n = 0;
  const fetch = (url, opts = {}) => new Promise((resolve, reject) => {
    calls.push({ url, opts, body: JSON.parse(opts.body || '{}') });
    settle = { ok: (data) => resolve({ json: () => Promise.resolve(data) }), drop: () => reject(new TypeError('Failed to fetch')) };
  });
  const recorder = { busy: false, isBusy() { return this.busy; } };
  const timers = [], cleared = [];
  const ctx = { document: p.document, fetch, state: { progressId: 10, consultAudio: recorder }, CFG: { api_base: '/api/consultation', nonce: 'n0nce' },
    HDLV2Loading: { skeleton: () => '<div class="hdl-skel" aria-hidden="true"></div>' }, window: { hdlv2RateLimit: { idempotencyKey: () => 'key-' + (++n) } },
    setTimeout: (f) => { timers.push(f); return timers.length; }, clearTimeout: (id) => { cleared.push(id); }, Promise, JSON, Array };
  vm.createContext(ctx);
  vm.runInContext(SRC, ctx);
  return { p, ctx, calls, recorder, timers, cleared, settle: () => settle, run: (code) => vm.runInContext(code, ctx) };
}
const tick = () => new Promise((r) => setTimeout(r, 0));
const ANSWER = { questions: [{ question: 'How often do you wake? <b>x</b>', why: 'wakes at 3 am' }, { question: 'What do you eat after 8 pm?', why: 'late snacks' }], red_flag: 'Chest tightness on the stairs.', nothing: false };

// K.1, K.2
let w = boot();
w.run('mountLiveQuestions();');
const card = w.p.reg['hdlv2-liveq'].innerHTML;
ok(card.includes('<h4>Questions to ask now</h4>') && /<button type="button" id="hdlv2-liveq-ask"[^>]*>Suggest questions<\/button>/.test(card) && card.includes('Uses your notes and every recording you have stopped.'), 'K.1 the heading, the button and the line about notes and stopped recordings');
ok(w.p.reg['hdlv2-liveq-busy'] && w.p.reg['hdlv2-liveq-busy'].hidden === true && card.includes('Stop the recording to include what was just said.'), 'K.1b the busy line is there and hidden while nothing records');
const w0 = boot(false);
w0.run('mountLiveQuestions();');
ok(w0.p.reg['hdlv2-liveq'].innerHTML === '', 'K.2 no notes box: no card');

// K.3, K.4
w.p.reg['hdlv2-consult-notes'].value = 'Saved notes.\n\nUnsaved: she wakes at 3 am.';
w.p.reg['hdlv2-liveq-ask'].click();
let c = w.calls[0];
ok(w.calls.length === 1 && c.url === '/api/consultation/live-questions' && c.opts.method === 'POST' && c.opts.headers['X-WP-Nonce'] === 'n0nce' && c.opts.headers['Idempotency-Key'] === 'key-1', 'K.3 one POST with the nonce and an Idempotency-Key');
ok(c.body.progress_id === 10 && c.body.heard === 'Saved notes.\n\nUnsaved: she wakes at 3 am.' && Array.isArray(c.body.asked) && c.body.asked.length === 0, 'K.3b heard = the notes box as it is at the press; nothing asked yet');
ok(w.p.reg['hdlv2-liveq-ask'].disabled === true && w.p.reg['hdlv2-liveq-body'].innerHTML.includes('hdl-skel'), 'K.3c the button waits and a skeleton shows');
w.p.reg['hdlv2-liveq-ask'].click();
ok(w.calls.length === 1, 'K.3d a second click while it works sends nothing');
w.settle().ok(ANSWER); await tick(); await tick();
const body = w.p.reg['hdlv2-liveq-body'].innerHTML;
ok(body.indexOf('Chest tightness on the stairs.') > -1 && body.indexOf('Chest tightness') < body.indexOf('How often do you wake?'), 'K.4 the red flag first, then the questions');
ok(body.includes('How often do you wake? &lt;b&gt;x&lt;/b&gt;') && !body.includes('<b>x</b>') && body.includes('wakes at 3 am') && body.includes('What do you eat after 8 pm?'), 'K.4b questions and whys drawn as text (escaped), never as HTML');
ok(w.p.reg['hdlv2-liveq-ask'].disabled === false, 'K.4c the button again');

// K.5
w.p.reg['hdlv2-liveq-ask'].click();
c = w.calls[1];
ok(c.opts.headers['Idempotency-Key'] === 'key-2' && JSON.stringify(c.body.asked) === JSON.stringify(['How often do you wake? <b>x</b>', 'What do you eat after 8 pm?']), 'K.5 the second press sends the questions already shown, with a new key');
w.settle().ok({ questions: [], red_flag: '', nothing: true }); await tick(); await tick();
ok(w.p.reg['hdlv2-liveq-body'].innerHTML.includes('Nothing to add yet.'), 'K.8 no questions and no flag: "Nothing to add yet."');

// K.6
w.recorder.busy = true; w.run('liveBusy();');
ok(w.p.reg['hdlv2-liveq-busy'].hidden === false, 'K.6 recording: the busy line shows');
w.p.reg['hdlv2-liveq-ask'].click();
ok(w.calls.length === 3 && w.calls[2].body.heard === w.p.reg['hdlv2-consult-notes'].value, 'K.6b a press while recording still works, from the notes so far');
w.settle().ok(ANSWER); await tick(); await tick();
w.recorder.busy = false; w.run('liveBusy();');
ok(w.p.reg['hdlv2-liveq-busy'].hidden === true, 'K.6c stopped and transcribed: the busy line hides');
const t0 = w.timers.length;
w.run('mountLiveQuestions();');
ok(w.cleared.length === 1 && w.timers.length === t0 + 1, 'K.6d a redraw keeps one check running, not two');
delete w.p.reg['hdlv2-liveq-busy'];
const t1 = w.timers.length;
w.run('liveBusy();');
ok(w.timers.length === t1 && !/setInterval\(/.test(NAMES.map(fn).join('\n')), 'K.6e the card gone: the check stops itself (no interval)');

// K.7
const shown = w.p.reg['hdlv2-liveq-body'].innerHTML;
w.p.reg['hdlv2-liveq-ask'].click();
w.settle().ok({ code: 'questions_failed', message: 'Could not get questions. Try again.' }); await tick(); await tick();
ok(w.p.reg['hdlv2-liveq-body'].innerHTML === shown && w.p.reg['hdlv2-liveq-error'].textContent === 'Could not get questions. Try again.' && w.p.reg['hdlv2-liveq-ask'].disabled === false, 'K.7 an error answer: the list comes back, the plain line, the button again');
w.p.reg['hdlv2-liveq-ask'].click();
w.settle().drop(); await tick(); await tick();
ok(w.p.reg['hdlv2-liveq-body'].innerHTML === shown && w.p.reg['hdlv2-liveq-error'].textContent === 'Could not get questions. Try again.', 'K.7b no answer at all: the same');

// K.9
ok(js.includes("(CFG.live_questions ? '<div class=\"hdlv2-consult-section hdlv2-liveq\" id=\"hdlv2-liveq\"></div>' : '')") && js.includes('if (CFG.live_questions) mountLiveQuestions();'), 'K.9 the card and its mount only with the live_questions setting');
ok(!/window\.(alert|confirm|prompt)\(/.test(NAMES.map(fn).join('\n')), 'K.9b no native pop-up in the card');

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
