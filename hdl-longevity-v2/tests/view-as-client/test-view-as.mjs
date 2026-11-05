#!/usr/bin/env node
/**
 * Plan E4-b (v0.48.40), Story 3 (the browser side) and Story 1 (the door). The REAL scripts run in
 * vm against a small fake DOM, once on the client's own page and once under [data-view-as]:
 *   - hdlv2-client-dashboard.js: a flight-plan tick click and the 30 days form POST on the client's
 *     page, and nothing under [data-view-as];
 *   - hdlv2-inbox.js: the thread is read on both; hdl_mark_read and the reply form only on the client's page;
 *   - hdlv2-client-list-enhance.js: the "See it as <first name> sees it" link above the product tiles.
 * Run: node tests/view-as-client/test-view-as.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const src = (p) => fs.readFileSync(new URL('../../assets/' + p, import.meta.url), 'utf8');
const flush = () => new Promise((r) => setTimeout(r, 0));

/** A fake element: attributes, classes, listeners, children; querySelector answers from `q`. */
function el(attrs = {}, q = {}) {
  const cls = new Set(String(attrs.class || '').split(' ').filter(Boolean));
  const e = {
    attrs, listeners: {}, children: [], style: {}, textContent: '', value: '', disabled: false, hidden: false,
    classList: { add: (c) => cls.add(c), remove: (c) => cls.delete(c), contains: (c) => cls.has(c), toggle: (c, on) => ((on ?? !cls.has(c)) ? cls.add(c) : cls.delete(c)) },
    getAttribute: (k) => attrs[k] ?? null, setAttribute: (k, v) => { attrs[k] = String(v); }, removeAttribute: (k) => { delete attrs[k]; },
    addEventListener: (t, f) => { (e.listeners[t] = e.listeners[t] || []).push(f); },
    appendChild: (c) => { e.children.push(c); return c; },
    insertBefore: (c, ref) => { const i = e.children.indexOf(ref); e.children.splice(i < 0 ? 0 : i, 0, c); return c; },
    get firstChild() { return e.children[0] || null; },
    querySelector: (s) => q[s] ?? null,
    querySelectorAll: (s) => q['all:' + s] || [],
    closest: (s) => (q['closest:' + s] !== undefined ? q['closest:' + s] : null),
    remove() {}, focus() {}, scrollIntoView() {},
  };
  return e;
}
function fire(target, type, e) { (target.listeners[type] || []).forEach((f) => f(e)); }

/** Runs the real dashboard script; returns the fetch log and the things to poke. */
function dashboard(viewAs) {
  const fetches = [];
  const li = el({ 'data-tick-id': '9', 'data-day': 'monday', 'data-ticked': '0' });
  const btn = el({ class: 'cdp-action-check' }, { 'closest:.cdp-action': li });
  const card = el({ class: 'cdp-week-card' });
  const root = el({ class: 'hdlv2-cd-pop' }, { 'all:.cdp-week-card': [card] });
  const done = el({ class: 'cd30-done' });
  const form = el({ class: 'cd30-tick', 'data-day': '1' }, { '.cd30-done': done, '.cd30-error': el(), textarea: el() });
  const doc = el({}, {
    '[data-view-as]': viewAs ? root : null,
    'all:.hdlv2-cd-pop': [root],
  });
  doc.readyState = 'complete';
  doc.getElementById = () => null;
  const ctx = vm.createContext({
    window: { HDLV2_CD_POP: { restUrl: '/wp-json/hdl-v2/v1', nonce: 'n', clientId: 508 }, location: { hash: '' } },
    document: doc, setTimeout, clearTimeout, AbortController, JSON, Math, Object, parseInt, String,
    fetch: (url, o) => { fetches.push({ url, method: o && o.method }); return new Promise(() => {}); },
  });
  ctx.window.document = doc;
  vm.runInContext(src('js/hdlv2-client-dashboard.js'), ctx);
  return {
    fetches, card, form, doc,
    tick() { fire(card, 'click', { target: { closest: (s) => (s === '.cdp-action-check' ? btn : null) }, preventDefault() {} }); },
    submit() { fire(doc, 'submit', { target: form, preventDefault() {} }); },
  };
}

console.log('V1 hdlv2-client-dashboard.js');
let d = dashboard(false);
d.tick(); d.submit();
ok(d.fetches.some((f) => f.url.endsWith('/flight-plan/tick') && f.method === 'POST') && d.fetches.some((f) => f.url.endsWith('/challenge/tick') && f.method === 'POST'),
  'V1.1 the client\'s own page: a tick click and the 30 days form each POST (the harness sees writes)');
d = dashboard(true);
d.tick(); d.submit();
ok(d.fetches.length === 0, 'V1.2 under [data-view-as]: the same click and submit send nothing');
ok(!(d.card.listeners.click || []).length && !(d.doc.listeners.submit || []).length, 'V1.3 under [data-view-as]: no tick listener on the week card, no submit listener on the page');
ok((d.doc.listeners.click || []).length > 0, 'V1.4 under [data-view-as]: the 30 days still opens a done day (its click reader stays)');

/** Runs the real inbox script against one unread practitioner message; returns the posted actions. */
async function inbox(viewAs) {
  const posted = [];
  const form = el({ 'data-inbox-reply': '' });
  const thread = el({ 'data-inbox-thread': '' });
  const root = el({ 'data-inbox': '' }, { '[data-inbox-thread]': thread, '[data-inbox-reply]': form, '[data-inbox-text]': el(), '[data-inbox-send]': el(), '[data-inbox-note]': el(), '[data-inbox-prac]': el() });
  const doc = el({}, { '[data-inbox]': root, '[data-view-as]': viewAs ? el() : null });
  doc.readyState = 'complete';
  doc.createElement = () => el();
  doc.createTextNode = (t) => ({ t });
  doc.hidden = false;
  let observe = null;
  const ctx = vm.createContext({
    window: { HDLV2_INBOX: { mode: 'session', ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'n', clientHash: 'h', practitionerId: 122 }, setInterval: () => 0 },
    document: doc, URLSearchParams, Promise, String, parseInt, Date, isNaN,
    IntersectionObserver: function (cb) { observe = cb; this.observe = () => {}; },
    fetch: (url, o) => {
      const action = new URLSearchParams(o.body).get('action');
      posted.push(action);
      const data = action === 'hdl_get_conversation'
        ? { messages: [{ id: 1, sender_role: 'practitioner', is_read: 0, message_body: 'Hello', sent_at_ts: 1 }] } : { unread_count: 1 };
      return Promise.resolve({ json: () => ({ success: true, data }) });
    },
  });
  ctx.window.IntersectionObserver = ctx.IntersectionObserver;
  ctx.window.document = doc;
  vm.runInContext(src('js/hdlv2-inbox.js'), ctx);
  await flush(); await flush();
  if (observe) observe([{ isIntersecting: true }]);
  await flush();
  return { posted, form };
}

console.log('V2 hdlv2-inbox.js');
let i = await inbox(false);
ok(i.posted.includes('hdl_get_conversation') && i.posted.includes('hdl_mark_read') && (i.form.listeners.submit || []).length === 1,
  'V2.1 the client\'s own page: reads the thread, marks it read once seen, binds the reply form');
i = await inbox(true);
ok(i.posted.includes('hdl_get_conversation'), 'V2.2 under [data-view-as]: the thread is still read');
ok(!i.posted.includes('hdl_mark_read') && !i.posted.includes('hdl_post_message') && !(i.form.listeners.submit || []).length,
  'V2.3 under [data-view-as]: never marks read, never replies (no submit listener even if a form were there)');

console.log('V3 the door on the client panel (hdlv2-client-list-enhance.js)');
const enh = src('js/hdlv2-client-list-enhance.js');
const at = enh.indexOf('function mountViewAs(c, panel) {');
const fn = at < 0 ? '' : enh.slice(at, enh.indexOf('\n  }\n', at) + 4);
const mount = (c) => {
  const tiles = el({ class: 'cdp-products' });
  const panel = el();
  panel.children.push(tiles);
  const ctx = vm.createContext({ document: { createElement: () => el() }, String, c, panel });
  vm.runInContext(fn + '\nmountViewAs(c, panel);', ctx);
  return panel;
};
let p = fn ? mount({ name: 'Demo Owner', view_as_url: '/my-dashboard/?as=808&_wpnonce=abc' }) : null;
const a = p && p.children[0];
ok(!!a && a.className === 'hdlv2-view-as' && a.href === '/my-dashboard/?as=808&_wpnonce=abc' && a.textContent === 'See it as Demo sees it →'
  && p.children[1].attrs.class === 'cdp-products', 'V3.1 with view_as_url: "See it as Demo sees it →" first in the panel, above the tiles, the server\'s href');
p = fn ? mount({ name: 'Demo Owner' }) : null;
ok(!!p && p.children.length === 1, 'V3.2 without view_as_url (flag off): nothing');
const build = enh.slice(enh.indexOf('mountSnapshot(c, panel);\n    mountProducts(c, panel);'), enh.indexOf('mountSnapshot(c, panel);\n    mountProducts(c, panel);') + 200);
ok(/mountProducts\(c, panel\);\s*mountViewAs\(c, panel\);\s*return panel;/.test(build), 'V3.3 the panel builder mounts it after the tiles (so it sits above them)');
ok(!!fn && !/innerHTML/.test(fn), 'V3.4 the link is built with textContent (no server text through innerHTML)');
const pcss = src('css/hdlv2-products.css');
ok(/\.hdlv2-view-as \{[^}]*color: #3d8da0/.test(pcss) && /\.hdlv2-view-as:focus-visible \{[^}]*outline: 2px solid #3d8da0/.test(pcss),
  'V3.5 the link: teal text, a 2px teal focus ring (DESIGN.md)');

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
