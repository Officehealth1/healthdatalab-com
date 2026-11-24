#!/usr/bin/env node
/**
 * Plan 14 fix rows C8, C9: the client's Flight Plan page. The REAL functions of
 * assets/js/hdlv2-flight-plan.js (tick, tick all, the refused-day handling and the 20 s poll), run in vm
 * against a few fake elements and a fake fetch.
 *   A tick answered 409 `day_closed` puts the box back and shows "This day is closed." beside the day
 *   (text, role="status"); the day's boxes stop answering. A tick-all answered so asks the server's state
 *   at once. The poll draws the page again when a day's state changed (a day closed at midnight turns
 *   read-only); with the same states it only moves the boxes. Switch absent (no day states): as before.
 *   The page draws the six-week line (C8) as text with role="status".
 * Run: node tests/flight-cycle/test-plan-page.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const js = fs.readFileSync(new URL('../../assets/js/hdlv2-flight-plan.js', import.meta.url), 'utf8');

/** The source of `function name(...) {...}`, braces matched (strings and comments skipped). */
function fn(name) {
  const at = js.indexOf('function ' + name + '(');
  if (at < 0) return '';
  let i = js.indexOf('{', at), depth = 0, q = null;
  for (; i < js.length; i++) {
    const c = js[i], n = js[i + 1];
    if (q) { if (c === '\\') { i++; continue; } if (c === q) q = null; continue; }
    if (c === '/' && n === '/') { i = js.indexOf('\n', i); continue; }
    if (c === '/' && n === '*') { i = js.indexOf('*/', i) + 1; continue; }
    if (c === "'" || c === '"' || c === '`') { q = c; continue; }
    if (c === '{') depth++;
    if (c === '}' && --depth === 0) return js.slice(at, i + 1);
  }
  return '';
}
const NAMES = ['cycleActionState', 'flushTicks', 'bulkTick', 'refusedDay', 'dayClosed', 'dayStatesKey', 'applyTickDiff', 'refresh', 'pull'];
ok(NAMES.every((n) => fn(n) !== ''), 'the tick, refusal and poll functions are in the page script');

/** Fake elements: attributes, a column with a header, querySelector on the few selectors the functions use. */
class El {
  constructor(tag) { this.tag = tag; this.a = {}; this.kids = []; this.parent = null; this.textContent = ''; this.className = ''; }
  getAttribute(k) { return k in this.a ? this.a[k] : null; }
  setAttribute(k, v) { this.a[k] = String(v); }
  hasAttribute(k) { return k in this.a; }
  appendChild(e) { e.parent = this; this.kids.push(e); return e; }
  closest(sel) { let e = this; while (e && !(sel === '.fp-day-column' && e.className === 'fp-day-column')) e = e.parent; return e; }
  querySelector(sel) {
    const want = sel.replace(/^\./, '');
    const walk = (e) => { for (const k of e.kids) { if (k.className === want) return k; const f = walk(k); if (f) return f; } return null; };
    return walk(this);
  }
}
function page(days) {
  const root = new El('div'); root.isConnected = true;
  const actions = [];
  for (const [day, ids] of Object.entries(days)) {
    const col = root.appendChild(new El('div')); col.className = 'fp-day-column';
    const head = col.appendChild(new El('div')); head.className = 'fp-day-header';
    for (const id of ids) { const a = col.appendChild(new El('div')); a.className = 'fp-action'; a.setAttribute('data-tick-id', id); a.setAttribute('data-day', day); a.setAttribute('data-state', 'default'); actions.push(a); }
  }
  const match = (sel) => {
    let m = sel.match(/^\.fp-action\[data-tick-id="(\d+)"\]$/); if (m) return actions.filter((a) => a.getAttribute('data-tick-id') === m[1]);
    m = sel.match(/^\.fp-action\[data-day="(\w+)"\]$/); if (m) return actions.filter((a) => a.getAttribute('data-day') === m[1]);
    return [];
  };
  root.querySelector = (sel) => match(sel)[0] || null;
  root.querySelectorAll = (sel) => match(sel);
  return { root, act: (id) => actions.find((a) => a.getAttribute('data-tick-id') === String(id)) };
}

function world(answers) {
  const p = page({ monday: [101, 103], tuesday: [102] });
  const calls = [], renders = [];
  const ctx = {
    root: p.root, CFG: { api_base: '/api', nonce: 'n' }, token: 't0k', clientId: 13,
    pendingTicks: {}, tickTimer: null, lastTickFlushAt: 0, currentPlanId: 650, closedDays: {}, drawnDays: 'monday:late,tuesday:open',
    tickPollTimer: null, tickVisHandler: null, tickFocusHandler: null, adherence: 0,
    setTimeout: () => 0, clearTimeout: () => {}, Date, Promise, JSON, Object,
    document: { createElement: (t) => new El(t), removeEventListener() {} }, window: { removeEventListener() {} },
    updateAdherence() { ctx.adherence++; },
    renderPlan(plan, ticks) { renders.push(plan); ctx.drawnDays = ctx.dayStatesKey(plan.days); },
    fetch(url, o) {
      calls.push({ url, method: (o && o.method) || 'GET', body: o && o.body });
      const a = answers(url, o) || { status: 200, body: {} };
      return Promise.resolve({ status: a.status, ok: a.status < 300, json: () => Promise.resolve(a.body) });
    },
  };
  vm.createContext(ctx);
  vm.runInContext(NAMES.map(fn).join('\n') + '\nthis.dayStatesKey = dayStatesKey;', ctx);
  return { ctx, p, calls, renders, run: (src) => vm.runInContext(src, ctx) };
}
const settle = () => new Promise((r) => setTimeout(r, 0)).then(() => new Promise((r) => setTimeout(r, 0)));

// ── A tick on a closed day.
let w = world((url) => (url === '/api/tick' ? { status: 409, body: { code: 'day_closed', message: 'This day is closed.' } } : null));
w.run('cycleActionState(root.querySelector(\'.fp-action[data-tick-id="101"]\')); flushTicks();');
ok(w.p.act(101).getAttribute('data-state') === 'done', 'C9.1 the tap shows the box done at once (as today)');
await settle();
const head = w.p.act(101).closest('.fp-day-column').querySelector('.fp-day-header');
const line = head.querySelector('.fp-day-closed');
ok(w.p.act(101).getAttribute('data-state') === 'default', 'C9.2 answered 409 day_closed: the box goes back');
ok(line && line.textContent === 'This day is closed.' && line.getAttribute('role') === 'status', 'C9.3 "This day is closed." beside the day, as text with role="status"');
ok(w.p.act(103).getAttribute('data-locked') === '1' && w.p.act(103).getAttribute('aria-disabled') === 'true' && w.ctx.closedDays.monday === true, 'C9.4 the day\'s boxes stop answering, and the day is remembered for a redraw');
w.run('cycleActionState(root.querySelector(\'.fp-action[data-tick-id="103"]\'));');
ok(w.p.act(103).getAttribute('data-state') === 'default' && Object.keys(w.ctx.pendingTicks).length === 0, 'C9.5 a tap on a closed day changes nothing and sends nothing');

// ── A tick that is stored, and a refusal of another kind.
w = world(() => ({ status: 200, body: { success: true } }));
w.run('cycleActionState(root.querySelector(\'.fp-action[data-tick-id="102"]\')); flushTicks();');
await settle();
ok(w.p.act(102).getAttribute('data-state') === 'done' && !w.ctx.closedDays.tuesday, 'C9.6 stored (200): the box stays, no line');
w = world(() => ({ status: 409, body: { code: 'checkin_sent' } }));
w.run('cycleActionState(root.querySelector(\'.fp-action[data-tick-id="102"]\')); flushTicks();');
await settle();
ok(w.p.act(102).getAttribute('data-state') === 'done' && !w.ctx.closedDays.tuesday, 'C9.7 a 409 of another kind is not read as a closed day');

// ── Tick all for one day, refused: the line, and the server's state fetched at once.
w = world((url) => (url === '/api/tick-all' ? { status: 409, body: { code: 'day_closed' } }
  : { status: 200, body: { plan: { id: 650, days: { monday: { state: 'locked' }, tuesday: { state: 'locked' } } }, ticks: [] } }));
w.run('lastTickFlushAt = Date.now(); bulkTick(650, true, "tuesday");');
await settle();
ok(w.ctx.closedDays.tuesday === true, 'C9.8 tick all on a closed day: "This day is closed." for that day');
ok(w.calls.some((c) => c.method === 'GET' && c.url === '/api/13/current?token=t0k'), 'C9.9 ... and the server\'s state is fetched at once (not after the 5 s wait)');
ok(w.renders.length === 1, 'C9.10 ... which draws the page again, the closed day read-only');

// ── The 20 s poll.
const poll = (days, ticks) => world(() => ({ status: 200, body: { plan: { id: 650, days }, ticks } }));
w = poll({ monday: { state: 'locked' }, tuesday: { state: 'late' } }, []);
w.run('refresh();'); await settle();
ok(w.renders.length === 1, 'C9.11 the poll: a day closed at midnight → the page is drawn again (read-only, "Missed")');
w = poll({ monday: { state: 'late' }, tuesday: { state: 'open' } }, [{ id: 103, ticked: 1 }]);
w.run('refresh();'); await settle();
ok(w.renders.length === 0 && w.p.act(103).getAttribute('data-state') === 'done', 'C9.12 same day states: only the boxes move (as before)');
w = poll(undefined, [{ id: 103, ticked: 1 }]); w.ctx.drawnDays = '';
w.run('refresh();'); await settle();
ok(w.renders.length === 0 && w.p.act(103).getAttribute('data-state') === 'done', 'C9.13 switch absent (no day states): the poll behaves as before');
w = poll({ monday: { state: 'locked' } }, []); w.ctx.lastTickFlushAt = Date.now();
w.run('refresh();'); await settle();
ok(w.calls.length === 0, 'C9.14 the poll still waits 5 s after a tick before it reads (as before)');

// ── The page's lines (source of renderPlan).
const render = fn('renderPlan');
ok(render.includes("plan.cycle && plan.cycle.notice ? '<p class=\"fp-cycle-notice\" role=\"status\">' + esc(plan.cycle.notice) + '</p>'"), 'C8.1 the six-week line is drawn escaped, as text with role="status", only when the server sends one');
ok(render.includes("closedDays[dayName] ? '<span class=\"fp-day-closed\" role=\"status\">This day is closed.</span>'") && render.includes('drawnDays = dayStatesKey(plan.days);'), 'C9.15 a redraw keeps "This day is closed." and records the drawn day states');
ok(!/window\.(alert|confirm)\(/.test(js), 'no native pop-up in the page script');

// ── The line is readable where it sits: white header text (4.5:1 on the day header), not grey #888 (about 2.6:1).
const css = fs.readFileSync(new URL('../../assets/css/hdlv2-flight-plan.css', import.meta.url), 'utf8');
const rule = (sel) => { const m = css.match(new RegExp(sel.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\{([^}]*)\\}')); return m ? m[1] : ''; };
ok(/color:\s*inherit/.test(rule('.fp-day-closed')) && !/#888/.test(rule('.fp-day-closed')), 'C9.16 "This day is closed." takes the header\'s white text, not grey');
ok(/opacity:\s*0\.85/.test(rule('.fp-day-column.fp-day-locked .fp-day-header,\n.fp-day-column .fp-day-header:has(.fp-day-closed)')), 'C9.17 a closed day\'s header is dimmed less than an open past day (0.85, not 0.7), so its text keeps 4.5:1');

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
