#!/usr/bin/env node
/**
 * One-row fix (v0.48.39): the two tab behaviours of the REAL initTabs() in
 * assets/js/hdlv2-client-dashboard.js, run in vm against a small fake DOM.
 *   - W-a: the countdown card's "What changes between now and then" (#tab-today) opens Today.
 * Run: node tests/dashboard-one-row-fix/test-one-row-fix.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };

const js = fs.readFileSync(new URL('../../assets/js/hdlv2-client-dashboard.js', import.meta.url), 'utf8');
const at = js.indexOf('function initTabs(root) {');
const initTabs = js.slice(at, js.indexOf('\n  }\n', at) + 4);

/** Matches one simple selector list: "[.anc ]tag[attr op "v"]" or "[attr]" parts, joined by commas. */
function matches(el, list) {
  return list.split(',').some((part) => {
    const toks = part.trim().split(/\s+/);
    const last = toks.pop();
    if (!toks.every((t) => el.ancestors.includes(t.slice(1)))) return false;
    const m = last.match(/^([a-z]*)(?:\[([a-z-]+)(?:([\^*]?=)"([^"]*)")?\])?$/);
    if (!m) return false;
    const [, tag, attr, op, v] = m;
    if (tag && tag !== el.tag) return false;
    if (!attr) return true;
    const val = el.attrs[attr];
    if (val === undefined) return false;
    return !op || (op === '=' ? val === v : op === '^=' ? val.startsWith(v) : val.includes(v));
  });
}
function target(tag, attrs, ancestors) {
  const el = { tag, attrs, ancestors, pathname: '/my-dashboard/', hash: attrs.href || '' };
  el.closest = (sel) => (matches(el, sel) ? el : null);
  el.getAttribute = (k) => attrs[k] ?? null;
  return el;
}
function page() {
  const listeners = { root: [], doc: [] };
  const mk = (attr, name) => {
    const cls = new Set(), a = { [attr]: name };
    return { a, cls, classList: { toggle: (c, on) => (on ? cls.add(c) : cls.delete(c)), contains: (c) => cls.has(c) },
      getAttribute: (k) => a[k] ?? null, setAttribute: (k, v) => { a[k] = String(v); }, addEventListener() {} };
  };
  const keys = ['today', 'stage1', 'report', 'timeline', 'flight_plan', 'challenge', 'messages'];
  const tabs = keys.map((k) => mk('data-tab', k));
  const panels = keys.map((k) => mk('data-tab-panel', k));
  const root = {
    querySelectorAll: (s) => (s === '.cdp-tab' ? tabs : s === '[data-tab-panel]' ? panels : []),
    querySelector: (s) => { const m = s.match(/data-tab="([^"]+)"/); return m ? tabs.find((t) => t.a['data-tab'] === m[1]) || null : null; },
    addEventListener: (t, f) => listeners.root.push(f),
  };
  const win = { location: { hash: '', pathname: '/my-dashboard/', href: 'https://x.test/my-dashboard/' }, history: null, scrolled: 0,
    scrollTo() { this.scrolled++; } };
  const ctx = vm.createContext({ window: win, document: { addEventListener: (t, f) => listeners.doc.push(f) }, URL, root });
  vm.runInContext(initTabs + '\ninitTabs(root);', ctx);
  const click = (el) => { const e = { target: el, prevented: false, preventDefault() { this.prevented = true; } };
    listeners.doc.forEach((f) => f(e)); listeners.root.forEach((f) => f(e)); return e; };
  const open = () => panels.filter((p) => p.cls.has('cdp-panel-active')).map((p) => p.a['data-tab-panel']);
  return { click, open, win };
}

console.log('W-a the countdown link');
let p = page();
let e = p.click(target('a', { href: '#tab-today', class: 'cdp-countdown-cta' }, ['hdlv2-cd-pop', 'hdlv2-cd-pop-page', 'cdp-countdown-card']));
ok(e.prevented && p.open().join() === 'today' && p.win.scrolled === 1, 'W-a.1 "What changes between now and then" (#tab-today, inside the page) opens Today, at the top');
p = page();
e = p.click(target('a', { href: '#challenge' }, ['cdp-products', 'cdp-tile']));
ok(e.prevented && p.open().join() === 'challenge', 'W-a.2 the old strip link (#challenge) still opens its tab');
p = page();
e = p.click(target('a', { href: '#tab-today' }, ['site-footer']));
ok(!e.prevented && p.open().join() === '', 'W-a.3 a #tab- link outside the dashboard page is left alone');
p = page();
e = p.click(target('a', { href: '#tab-nowhere' }, ['hdlv2-cd-pop-page']));
ok(!e.prevented && p.open().join() === '', 'W-a.4 a #tab- link to no tab is left alone');

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
