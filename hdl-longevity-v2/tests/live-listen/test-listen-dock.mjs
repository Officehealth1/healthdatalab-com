#!/usr/bin/env node
/**
 * Plan 15 T1: the pinned card on the consultation screen (assets/js/hdlv2-consultation.js, the REAL functions,
 * in vm against fake elements, a fake clock and a fake fetch; the listening engine is a fake with the real API).
 *   D.1 the auto-ask rule: 40 new words AND 45 s since the last ask AND none in flight → one POST to
 *       /live-questions/auto; never two at once.
 *   D.2 a piece lands while the practitioner types: text, cursor, selection and the box's scroll untouched; the
 *       words go at the end; the notes save themselves (an input event).
 *   D.3 questions land: focus does not move.
 *   D.4 "Asked" strikes a question and adds it to asked[]; a question not marked may come back (only the marked
 *       ones are sent as asked while listening).
 *   D.5 Escape folds the card to one line (state + first question); the head button unfolds it.
 *   D.6 each state's line, word for word (§ 7 T3).
 *   D.7 "Listen" on: the mic and upload icons are disabled with "Listening is on."; off: back. "Listen" is
 *       disabled while the recorder is busy.
 *   D.8 the screen: with live_listen the in-column block is not drawn, the card is; no native pop-up anywhere in
 *       the listen code; the hourly limit on automatic asks keeps listening going and says so.
 * The card after Quim's own voice test (v0.49.06):
 *   D.30 a meter of five bars, aria-hidden.  D.31 onLevel sets its level; 0 flattens it.
 *   D.32 the meter is hidden when not listening and still when paused; the card is marked while listening.
 *   D.33 the strip "Heard just now", hidden until the first words.  D.34 the words are set as text, never as HTML,
 *        and the notes box holds them too.  D.35 the newest words last; a long run keeps only its end; they stay
 *        after "Listen" is off and after a redraw.
 *   D.36 the red flag's label is its own element.  D.37 a landed set shows "Updated HH:MM".
 *   D.38 "more below" on the card's scrolling part, off at the end.  D.39 switch off: a set is drawn as before.
 *   D.40 "reduce motion" in the stylesheet: the bars are held flat and the caret does not blink.
 *   D.41 (independent review) the report-preparing screen replaces the notes box: listening stops, the card goes,
 *        and words, a level or a state that arrive afterwards break nothing.
 * Run: node tests/live-listen/test-listen-dock.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const js = fs.readFileSync(new URL('../../assets/js/hdlv2-consultation.js', import.meta.url), 'utf8');
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const fn = (name) => cut('  function ' + name + '(', '\n  }\n');
const line = (head) => cut(head, '\n');
const NAMES = ['liveCard', 'mountLiveQuestions', 'liveBusy', 'liveList', 'askLiveQuestions',
  'listenDock', 'mountListen', 'toggleListen', 'listenState', 'appendToNotes', 'listenHeard', 'listenMaybeAsk', 'foldListen', 'listenPeek', 'markAsked'];
const VARS = ['  var liveAsked = ', '  var liveBusyTimer = ', '  var liveShown = ', '  var LISTEN_LINES = ', '  var listen = ', '  var listenWords = ', '  var listenAskedAt = ', '  var listenAsking = ', '  var listenAutoOff = '];
const NEW = ['listenLevel', 'listenStrip', 'listenMore', 'showReportPreparing'].filter((n) => fn(n) !== '');          // v0.49.06; D.30 on fail one by one without them
const NEWVARS = ['  var listenKey = ', '  var listenTail = '].filter((v) => line(v) !== '');
const missing = NAMES.filter((n) => fn(n) === '').concat(VARS.filter((v) => line(v) === ''));
ok(missing.length === 0, 'the listen functions and state are top-level in the consultation script' + (missing.length ? ' (missing: ' + missing.join(', ') + ')' : ''));
if (missing.length) { console.log(`\nPASS=${PASS} FAIL=${FAIL}`); process.exit(1); }
const SRC = [line('  function esc('), cut('  var LISTEN_LINES = ', '};\n'), ...VARS.filter((v) => v !== '  var LISTEN_LINES = ').map(line), ...NEWVARS.map(line), ...NAMES.map(fn), ...NEW.map(fn)].join('\n');

function dom() {
  const reg = {}; let active = null;
  function mk(id, tag, attrs = '') {
    const L = {}, at = {};
    (attrs.match(/\s[\w-]+(="[^"]*")?/g) || []).forEach((a) => { const m = a.trim().match(/^([\w-]+)(?:="([^"]*)")?$/); at[m[1]] = m[2] === undefined ? '' : m[2]; });
    const cls = new Set((at.class || '').split(/\s+/).filter(Boolean));
    const e = { id, tag, _html: '', kids: [], textContent: '', value: '', disabled: 'disabled' in at, hidden: 'hidden' in at,
      selectionStart: 0, selectionEnd: 0, scrollTop: 0, scrollHeight: 500, clientHeight: 500, events: [],
      style: { p: {}, setProperty(k, v) { this.p[k] = String(v); }, getPropertyValue(k) { return k in this.p ? this.p[k] : ''; } },
      classList: { add: (c) => cls.add(c), remove: (c) => cls.delete(c), contains: (c) => cls.has(c), toggle: (c, f) => { (f === undefined ? !cls.has(c) : f) ? cls.add(c) : cls.delete(c); } },
      getAttribute: (k) => (k in at ? at[k] : null), setAttribute: (k, v) => { at[k] = String(v); },
      get innerHTML() { return e._html; }, set innerHTML(h) { e.kids.forEach((k) => delete reg[k]); e._html = h; e.kids = scan(h); },
      addEventListener: (t, f) => { (L[t] = L[t] || []).push(f); },
      dispatchEvent: (ev) => { e.events.push(ev.type); (L[ev.type] || []).forEach((f) => f.call(e, ev)); },
      fire: (t, ev = {}) => { (L[t] || []).forEach((f) => f.call(e, Object.assign({ type: t, target: e, preventDefault() {} }, ev))); },
      click: () => { if (!e.disabled) e.fire('click'); },
      focus: () => { active = e; }, setSelectionRange: (a, b) => { e.selectionStart = a; e.selectionEnd = b; },
      querySelectorAll: () => e.buttons || [] };
    return e;
  }
  function scan(h) { const ids = [], re = /<(\w+)\b([^>]*)>/g; let m; while ((m = re.exec(h))) { const idm = m[2].match(/\sid="([^"]+)"/); if (idm) { reg[idm[1]] = mk(idm[1], m[1], m[2]); ids.push(idm[1]); } } return ids; }
  // createElement serves esc() (textContent in, escaped innerHTML out) and the card made on the page body.
  const createElement = () => { const e = mk('', 'div'); Object.defineProperty(e, 'textContent', { set(v) { e._html = String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }, get() { return e._html; } }); return e; };
  const body = { kids: [], appendChild: (e) => { body.kids.push(e); reg[e.id] = e; e.parentNode = body; },
    removeChild: (e) => { body.kids = body.kids.filter((k) => k !== e); e.kids.forEach((k) => delete reg[k]); delete reg[e.id]; } };
  const document = { getElementById: (id) => reg[id] || null, get activeElement() { return active; }, createElement, body };
  return { reg, document, mk, scan };
}

function boot() {
  const d = dom();
  const notes = d.reg['hdlv2-consult-notes'] = d.mk('hdlv2-consult-notes', 'textarea');
  notes.value = 'Saved notes.';
  const bar = d.reg['hdlv2-consult-audio'] = d.mk('hdlv2-consult-audio', 'div');
  bar.buttons = [{ disabled: false }, { disabled: false }];
  d.reg['hdlv2-listen-micnote'] = d.mk('hdlv2-listen-micnote', 'p', ' hidden');
  let now = 5_000_000, n = 0;
  const calls = [];
  const fetch = (url, o = {}) => new Promise((resolve, reject) => { calls.push({ url, body: JSON.parse(o.body || '{}'), ok: (data, status = 200) => resolve({ status, json: () => Promise.resolve(data) }), drop: () => reject(new TypeError('x')) }); });
  const engine = { on: false, opts: null, starts: 0, stops: 0, start() { this.on = true; this.starts++; this.opts.onState('listening'); }, stop() { this.on = false; this.stops++; this.opts.onState('off'); }, isOn() { return this.on; } };
  const recorder = { busy: false, isBusy() { return this.busy; } };
  const Clock = class extends Date { constructor() { super(now); } static now() { return now; } }; // new Date() is the fake clock too
  const ctx = { document: d.document, fetch, Date: Clock, Promise, JSON, Array, Event: function (t) { this.type = t; },
    state: { progressId: 10, consultAudio: recorder }, CFG: { api_base: '/api/consultation', nonce: 'n0nce', live_questions: 1, live_listen: 1, listen_url: '/api/audio/listen-piece' },
    HDLV2Loading: { skeleton: () => '<div class="hdl-skel" aria-hidden="true"></div>' },
    // the report-preparing screen (D.41): the real function, with the screen's root as a stub that takes the notes box away
    root: { set innerHTML(h) { delete d.reg['hdlv2-consult-notes']; } }, rn: (a, b) => b, REPORT_PREP_MESSAGES: ['x'], startReportPrepTicker: () => {},
    window: { hdlv2RateLimit: { idempotencyKey: () => 'key-' + (++n) }, HDLV2LiveListen: { create: (o) => { engine.opts = o; return engine; } } },
    setTimeout: () => 0, clearTimeout: () => {} };
  vm.createContext(ctx);
  vm.runInContext(SRC, ctx);
  vm.runInContext('mountLiveQuestions()', ctx);
  return { d, ctx, calls, engine, recorder, notes, bar, now: () => now, tickTo: (ms) => { now += ms; }, run: (c) => vm.runInContext(c, ctx) };
}
const flush = async () => { for (let i = 0; i < 6; i++) await Promise.resolve(); await new Promise((r) => setImmediate(r)); };
const words = (k) => Array.from({ length: k }, (_, i) => 'w' + i).join(' ');
const ANS = { questions: [{ question: 'How do you sleep?', why: 'wakes at 3 am' }, { question: 'What do you eat late?', why: 'snacks' }], red_flag: '', nothing: false };

// D.1
let w = boot();
ok(w.d.document.body.kids.length === 1 && w.d.reg['hdlv2-listen-dock'] === w.d.document.body.kids[0] && w.d.reg['hdlv2-listen-dock'].getAttribute('role') === 'region', 'D.0a the card is made once, on the page body, as a labelled region');
w.run('mountListen()');
ok(w.d.document.body.kids.length === 1, 'D.0c a redraw keeps one card');
const $ = (id) => w.d.reg[id];
ok($('hdlv2-listen') && $('hdlv2-liveq-ask') && $('hdlv2-listen-status') && $('hdlv2-listen').getAttribute('aria-pressed') === 'false', 'D.0 the card: "Listen" (aria-pressed), "Suggest questions", a status line');
$('hdlv2-listen').click();
ok(w.engine.starts === 1 && $('hdlv2-listen').getAttribute('aria-pressed') === 'true' && $('hdlv2-listen-status').textContent === 'Listening', 'D.0b "Listen" starts the engine; pressed; "Listening"');
w.engine.opts.onText(1, words(39)); await flush();
ok(w.calls.length === 0, 'D.1a 39 words: no ask');
w.engine.opts.onText(2, words(5)); await flush();
ok(w.calls.length === 1 && w.calls[0].url === '/api/consultation/live-questions/auto', 'D.1b 40 words, first ask: one POST to /live-questions/auto');
w.engine.opts.onText(3, words(60)); await flush();
ok(w.calls.length === 1, 'D.1c none while one is in flight');
w.calls[0].ok(ANS); await flush();
w.tickTo(30000); w.engine.opts.onText(4, words(50)); await flush();
ok(w.calls.length === 1, 'D.1d 40 words but only 30 s since the last ask: no ask');
w.tickTo(16000); w.engine.opts.onText(5, 'and more'); await flush();
ok(w.calls.length === 2, 'D.1e 45 s and 40 words: the next ask');

// D.2
w = boot();
const N = w.notes;
N.value = 'abcdefg'; N.focus(); N.setSelectionRange(3, 5); N.scrollTop = 40;
w.run('appendToNotes("hello there")');
ok(N.value === 'abcdefg\n\nhello there' && N.selectionStart === 3 && N.selectionEnd === 5 && N.scrollTop === 40 && w.d.document.activeElement === N, 'D.2a typing: the words go at the end; cursor, selection, scroll and focus untouched');
ok(N.events.includes('input'), 'D.2b the notes save themselves (an input event)');
const N2 = boot().notes; N2.value = ''; 
w = boot(); w.notes.value = ''; w.run('appendToNotes("first words")');
ok(w.notes.value === 'first words', 'D.2c an empty box gets the words with no blank lines');

// D.3
w = boot();
w.d.reg['hdlv2-listen'].click();
w.notes.focus();
w.engine.opts.onText(1, words(45)); await flush();
w.calls[0].ok(ANS); await flush();
ok(w.d.document.activeElement === w.notes && /How do you sleep\?/.test(w.d.reg['hdlv2-liveq-body'].innerHTML), 'D.3 questions land; focus stays in the notes box');

// D.4
const bodyHtml = w.d.reg['hdlv2-liveq-body'].innerHTML;
ok(/data-asked="0"[^>]*aria-pressed="false"|aria-pressed="false"[^>]*data-asked="0"/.test(bodyHtml) && />Asked</.test(bodyHtml), 'D.4a each question has an "Asked" button');
w.run('markAsked(0)');
ok(/hdlv2-liveq-done/.test(w.d.reg['hdlv2-liveq-body'].innerHTML) || w.run('liveAsked.indexOf("How do you sleep?") > -1'), 'D.4b marked');
ok(w.run('liveAsked.indexOf("How do you sleep?") > -1 && liveAsked.indexOf("What do you eat late?") === -1'), 'D.4c only the marked question is in asked[]');
w.tickTo(46000); w.engine.opts.onText(2, words(45)); await flush();
ok(JSON.stringify(w.calls[1].body.asked) === JSON.stringify(['How do you sleep?']), 'D.4d the next ask sends it as asked; the other may come back');
w.calls[1].ok(ANS); await flush();

// D.5
const dock = w.d.reg['hdlv2-listen-dock'];
dock.fire('keydown', { key: 'Escape' });
ok(w.d.reg['hdlv2-listen-body'].hidden === true && w.d.reg['hdlv2-listen-fold'].getAttribute('aria-expanded') === 'false', 'D.5a Escape folds the card');
ok(/Listening/.test(w.d.reg['hdlv2-listen-peek'].textContent) && /How do you sleep\?/.test(w.d.reg['hdlv2-listen-peek'].textContent), 'D.5b folded: one line with the state and the first question');
w.d.reg['hdlv2-listen-fold'].click();
ok(w.d.reg['hdlv2-listen-body'].hidden === false && w.d.reg['hdlv2-listen-fold'].getAttribute('aria-expanded') === 'true', 'D.5c the head unfolds it');

// D.6
const LINES = { listening: 'Listening', paused: 'Paused: bring this tab to the front.', missed: 'A few seconds were not heard.',
  limit: 'The hourly limit is reached. Press Suggest questions when you need them.',
  resting: 'Listening is resting for today. You can still record and press Suggest questions.',
  unavailable: 'Listening is not available right now. You can still type your notes.' };
let all = true;
for (const [k, v] of Object.entries(LINES)) { w.run(`listenState(${JSON.stringify(k)})`); all = all && w.d.reg['hdlv2-listen-status'].textContent === v; }
ok(all, 'D.6 each state\'s line, word for word');

// D.7
w = boot();
w.d.reg['hdlv2-listen'].click();
ok(w.bar.buttons.every((b) => b.disabled) && w.d.reg['hdlv2-listen-micnote'].hidden === false, 'D.7a "Listen" on: mic and upload icons disabled, "Listening is on."');
w.d.reg['hdlv2-listen'].click();
ok(w.engine.stops === 1 && w.bar.buttons.every((b) => !b.disabled) && w.d.reg['hdlv2-listen-micnote'].hidden === true && w.d.reg['hdlv2-listen'].getAttribute('aria-pressed') === 'false', 'D.7b off: the icons work again');
w.recorder.busy = true; w.run('liveBusy()');
ok(w.d.reg['hdlv2-listen'].disabled === true, 'D.7c "Listen" is disabled while the recorder is busy');
w.recorder.busy = false; w.run('liveBusy()');
ok(w.d.reg['hdlv2-listen'].disabled === false, 'D.7d and back when it is not');

// D.8
const render = cut('  function renderConsultation(', '\n  }\n');
ok(/CFG\.live_listen \? '' : '<div class="hdlv2-consult-section hdlv2-liveq"/.test(render) && /hdlv2-listen-micnote/.test(render) && /document\.body\.appendChild\(dock\)/.test(fn('mountListen')),
  'D.8a with live_listen the column block is not drawn; the card goes on the page body (a theme section cannot pin it)');
const listenSrc = cut('  // ── LISTEN', '  // ── END LISTEN ──');
ok(listenSrc !== '' && !/\balert\(|\bconfirm\(|\bprompt\(/.test(listenSrc) && !/getUserMedia|SpeechRecognition/.test(listenSrc), 'D.8b no native pop-up, no microphone code in the screen (the engine owns it)');
w = boot(); w.d.reg['hdlv2-listen'].click();
w.engine.opts.onText(1, words(45)); await flush();
w.calls[0].ok({ code: 'rate_limit_exceeded' }, 429); await flush();
w.tickTo(50000); w.engine.opts.onText(2, words(45)); await flush();
ok(w.engine.on && w.calls.length === 1 && w.d.reg['hdlv2-listen-status'].textContent === LINES.limit, 'D.8c the hourly limit on automatic asks: listening goes on, no more automatic asks, the line says so');

// D.30 to D.39 (v0.49.06): a sign that it listens, the heard words, a tidier result
const safe = (f) => { try { return !!f(); } catch (e) { return false; } };
w = boot();
const LEVEL = '--hdlv2-listen-level';
const meter = () => w.d.reg['hdlv2-listen-meter'];
const lvl = () => meter().style.getPropertyValue(LEVEL);
const meterTag = w.d.reg['hdlv2-listen-dock'].innerHTML.match(/<span\b([^>]*\bid="hdlv2-listen-meter"[^>]*)>((?:<span><\/span>)*)<\/span>/) || [];
ok(/aria-hidden="true"/.test(meterTag[1] || '') && meterTag[2] === '<span></span>'.repeat(5) && safe(() => meter().hidden === true), 'D.30 the card holds a meter of five bars, aria-hidden, not shown before "Listen"');
w.d.reg['hdlv2-listen'].click();
ok(safe(() => { w.engine.opts.onLevel(0.5); return Number(lvl()) > 0 && Number(lvl()) <= 1; }), 'D.31a onLevel(0.5) sets the meter\'s level (above 0, at most 1)');
ok(safe(() => { w.engine.opts.onLevel(0); return lvl() === '0'; }), 'D.31b onLevel(0) flattens it');
ok(safe(() => meter().hidden === false) && w.d.reg['hdlv2-listen-dock'].classList.contains('is-listening'), 'D.32a listening: the meter shows, the card is marked as listening');
ok(safe(() => { w.run('listenState("paused")'); w.engine.opts.onLevel(0.5); return meter().hidden === false && lvl() === '0'; }), 'D.32b paused: shown, and still');
let hid = true;
for (const k of ['off', 'denied', 'unavailable', 'limit', 'resting']) {
  w.engine.on = true; w.run('listenState("listening")'); safe(() => w.engine.opts.onLevel(0.5));
  w.engine.on = false; w.run(`listenState(${JSON.stringify(k)})`);
  hid = hid && safe(() => meter().hidden === true && lvl() === '0') && !w.d.reg['hdlv2-listen-dock'].classList.contains('is-listening');
}
ok(hid, 'D.32c off, denied, unavailable, limit, resting: the meter is hidden and flat, the card no longer marked');
w.engine.on = true; w.run('listenState("limit")');
ok(safe(() => meter().hidden === false), 'D.32d the limit on automatic asks alone (listening goes on): the meter stays');
w.run('listenState("starting")');
ok(safe(() => meter().hidden === true), 'D.32e "Starting…" (the microphone not yet given): no bars yet');

w = boot();
const strip = () => w.d.reg['hdlv2-listen-heard'], heard = () => w.d.reg['hdlv2-listen-heard-text'];
ok(safe(() => strip().hidden === true && />Heard just now</.test(w.d.reg['hdlv2-listen-dock'].innerHTML)), 'D.33 the strip "Heard just now" is in the card, hidden until the first words');
ok(safe(() => heard().getAttribute('translate') === 'no' && heard().classList.contains('notranslate')), 'D.33b the heard words are marked not to be translated (a client\'s own words stay off the page translator)');
w.d.reg['hdlv2-listen'].click();
w.engine.opts.onText(1, '<b>x</b> & y'); await flush();
ok(safe(() => strip().hidden === false && heard().textContent === '<b>x</b> & y' && heard().innerHTML === '') && w.notes.value.endsWith('<b>x</b> & y'), 'D.34 the words are set as text, never as HTML; the notes box holds them too');
w.engine.opts.onText(2, 'second piece'); w.engine.opts.onText(3, 'third piece'); await flush();
ok(safe(() => heard().textContent === '<b>x</b> & y second piece third piece'), 'D.35a after three pieces the newest is last');
w.engine.opts.onText(4, words(120)); await flush();
ok(safe(() => heard().textContent.length <= 330 && heard().textContent.startsWith('… ') && heard().textContent.endsWith(' w119') && !/second piece/.test(heard().textContent)), 'D.35b a long run keeps only its end');
// The cut must land on a word: one of two runs that differ by a character has its 320th-last character inside a word.
let midword = 0, whole = true;
for (const end of ['z', 'zz']) {
  const x = boot(); x.d.reg['hdlv2-listen'].click(); x.engine.opts.onText(1, words(120) + end);
  if (!/^w\d+ /.test((words(120) + end).slice(-320))) midword++;
  const m = safe(() => true) && String(x.d.reg['hdlv2-listen-heard-text'].textContent).match(/^… w(\d+) w(\d+) /);
  whole = whole && !!m && Number(m[2]) === Number(m[1]) + 1;
}
ok(midword >= 1 && whole, 'D.35b2 the cut lands on a word: the strip never starts with half a word');
w.d.reg['hdlv2-listen'].click();
ok(w.engine.stops === 1 && safe(() => strip().hidden === false && heard().textContent.endsWith(' w119')), 'D.35c "Listen" off: the last heard words stay');
w.run('mountListen()');
ok(safe(() => strip().hidden === false && heard().textContent.endsWith(' w119')), 'D.35d a redraw of the screen keeps them');

w = boot(); w.d.reg['hdlv2-listen'].click();
w.engine.opts.onText(1, words(45)); await flush();
w.calls[0].ok({ questions: ANS.questions, red_flag: 'Tight chest <i>twice</i>.', nothing: false }); await flush();
const set = w.d.reg['hdlv2-liveq-body'].innerHTML;
ok(/<strong class="hdlv2-liveq-flag-label">Red flag<\/strong>Tight chest &lt;i&gt;twice&lt;\/i&gt;\./.test(set), 'D.36 the flag\'s label "Red flag" is its own element before the flag\'s text (still escaped)');
const hhmm = new Date(w.now()).toTimeString().slice(0, 5);
ok((set.match(/<p class="hdlv2-liveq-updated">Updated (\d\d:\d\d)<\/p>/) || [])[1] === hhmm && set.indexOf('hdlv2-liveq-updated') < set.indexOf('hdlv2-liveq-flag'), 'D.37 a landed set shows "Updated HH:MM" (the practitioner\'s clock), first');
const lb = w.d.reg['hdlv2-listen-body'];
lb.scrollHeight = 500; lb.clientHeight = 300; lb.scrollTop = 0; lb.fire('scroll');
ok(lb.classList.contains('is-more'), 'D.38a the card can scroll and is not at the end: "more below" is on');
lb.scrollTop = 200; lb.fire('scroll');
ok(!lb.classList.contains('is-more'), 'D.38b scrolled to the end: off');
w = boot();
const lb2 = w.d.reg['hdlv2-listen-body'];
lb2.scrollHeight = 500; lb2.clientHeight = 300; lb2.scrollTop = 0;
w.d.reg['hdlv2-liveq-ask'].click(); await flush(); w.calls[0].ok(ANS); await flush();
ok(lb2.classList.contains('is-more'), 'D.38c a set lands by "Suggest questions" (no piece heard, no scroll) longer than the card: on');
lb2.clientHeight = 500; w.run('liveBusy()');
ok(!lb2.classList.contains('is-more'), 'D.38d the card\'s one-second check sets it right after a change nothing else reports (a resized window): off');
lb2.clientHeight = 300; w.run('liveBusy()');
ok(lb2.classList.contains('is-more'), 'D.38e and on again');
w.run('CFG.live_listen = 0');
const plain = w.run('liveList(' + JSON.stringify({ questions: ANS.questions, red_flag: 'Tight <i>chest</i>.', nothing: false }) + ')');
ok(/^<p class="hdlv2-liveq-flag"><strong>Red flag:<\/strong> Tight &lt;i&gt;chest&lt;\/i&gt;\.<\/p><ol class="hdlv2-liveq-list">/.test(plain) && !/Updated|flag-label|data-asked/.test(plain), 'D.39 the listen switch off: a set is drawn as before (plan 14\'s card), the flag still escaped');

// D.40: found by the walk. "*" does not reach a pseudo-element, so the caret kept blinking with "reduce motion" set.
const sheet = fs.readFileSync(new URL('../../assets/css/hdlv2-consultation.css', import.meta.url), 'utf8');
const calm = sheet.split('@media (prefers-reduced-motion: reduce) {').slice(1).map((b) => b.slice(0, b.indexOf('\n}'))).find((b) => b.includes('.hdlv2-listen-dock')) || '';
ok(/\.hdlv2-listen-dock \*::after[^{]*\{[^}]*animation: none !important/.test(calm) && /\.hdlv2-listen-meter span \{ height: 3px !important/.test(calm),
  'D.40 reduce motion: the bars are held flat and the rule reaches the caret (a pseudo-element)');

// D.41 (independent review): the card lives on the page body, so a screen that replaces the notes box must take it away itself
w = boot(); w.d.reg['hdlv2-listen'].click();
const listening = w.engine.on === true;
safe(() => w.run('showReportPreparing("final")'));
ok(listening && w.engine.stops === 1 && !w.engine.on && !w.d.reg['hdlv2-listen-dock'] && w.d.document.body.kids.length === 0, 'D.41a the report-preparing screen: listening stops (the microphone is freed) and the card is removed');
ok(safe(() => { w.engine.opts.onText(9, words(45)); w.engine.opts.onLevel(0.4); w.engine.opts.onState('off'); return true; }) && w.calls.length === 0, 'D.41b the last piece\'s words, a level and a state arriving after the card is gone: nothing breaks, no ask is sent');

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
