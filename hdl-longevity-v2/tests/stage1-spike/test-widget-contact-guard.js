#!/usr/bin/env node
/**
 * Widget contact-step guard (v0.48.19).
 *
 * Fast clicks used to skip "Where shall we send it?": two clicks on a Q9
 * answer inside the 250 ms advance delay queued two step changes (contact,
 * then result), and the result page posted the lead with no name or email.
 *
 * Runs the REAL widget/hdl-lead-magnet.js under a small fake DOM with a fake
 * clock, walks the nine questions and counts the lead POSTs.
 *
 * Run:  node tests/stage1-spike/test-widget-contact-guard.js   (exit 0/1)
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }

const SRC = fs.readFileSync(path.join(__dirname, '..', '..', 'widget', 'hdl-lead-magnet.js'), 'utf8');
const API = 'https://stby.healthdatalab.net/wp-json/hdl-v2/v1/widget/lead';
const ID = 'hdlw-7';

// ---------- fake DOM: enough for the widget's own calls ----------
function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

function makeDom() {
  const byId = new Map();
  function el(tag, attrs) {
    attrs = attrs || {};
    const classes = new Set((attrs.class || '').split(/\s+/).filter(Boolean));
    const e = {
      tagName: tag.toUpperCase(), attrs, children: [], listeners: {}, disabled: false, hidden: false,
      value: attrs.value || '', src: '', _html: '', _text: '',
      style: { setProperty() {} },
      classList: { add: c => classes.add(c), remove: c => classes.delete(c), contains: c => classes.has(c) },
      getAttribute: n => (n in attrs ? attrs[n] : null),
      setAttribute: (n, v) => { attrs[n] = String(v); },
      addEventListener(t, fn) { (this.listeners[t] = this.listeners[t] || []).push(fn); },
      click() { if (this.disabled) return; (this.listeners.click || []).forEach(fn => fn.call(this, {})); },
      appendChild() {},
      querySelector(sel) { return this.querySelectorAll(sel)[0] || null; },
      querySelectorAll(sel) {
        const cls = sel.trim().split(/\s+/).pop().replace(/^\./, '');
        const out = [];
        (function walk(n) { n.children.forEach(ch => { if (ch.classList.contains(cls)) out.push(ch); walk(ch); }); })(this);
        return out;
      },
      get id() { return attrs.id || ''; },
      set id(v) { attrs.id = v; byId.set(v, e); },
      get innerHTML() { return this._html; },
      set innerHTML(html) {
        this._html = html;
        this.children = [];
        const re = /<([a-zA-Z0-9]+)((?:\s+[a-zA-Z-]+(?:="[^"]*")?)*)\s*\/?>/g;
        let m;
        while ((m = re.exec(html))) {
          const a = {};
          m[2].replace(/([a-zA-Z-]+)(?:="([^"]*)")?/g, (_, k, v) => { a[k] = v === undefined ? '' : v; });
          const child = el(m[1], a);
          this.children.push(child);
          if (a.id) byId.set(a.id, child);
        }
      },
      get textContent() { return this._text; },
      set textContent(t) { this._text = String(t); this._html = esc(t); },
    };
    return e;
  }
  return { byId, el };
}

// ---------- one widget on a fresh page ----------
async function mountWidget(hostAttrs) {
  const { byId, el } = makeDom();
  let now = 0, seq = 0;
  let timers = [];
  const posts = [];

  const host = el('div', Object.assign({
    class: 'hdl-rate-widget', 'data-practitioner-id': '7', 'data-practitioner-name': 'Test Prac', 'data-api': API,
  }, hostAttrs || {}));

  const document = {
    readyState: 'complete',
    head: el('head'), body: el('body'),
    getElementById: id => byId.get(id) || null,
    createElement: tag => el(tag),
    querySelectorAll: sel => (sel === '.hdl-rate-widget' ? [host] : []),
    getElementsByTagName: () => [],
    addEventListener() {},
  };
  function fetch(url, opts) {
    if (url === API && opts && opts.method === 'POST') {
      posts.push(JSON.parse(opts.body));
      return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ pending: true, masked_email: 'x***@y', rate: 1 }) });
    }
    return Promise.resolve({ ok: false, status: 404, json: () => Promise.resolve(null) });
  }
  const ctx = {
    document, fetch, console, URLSearchParams, Promise, JSON, Math, parseInt, parseFloat, isNaN, String, Number, Array, Object,
    setTimeout(fn, ms) { const t = { id: ++seq, at: now + (ms || 0), fn }; timers.push(t); return t.id; },
    clearTimeout(i) { timers = timers.filter(t => t.id !== i); },
    location: { search: '' },
  };
  ctx.window = ctx;
  vm.runInNewContext(SRC, ctx);

  const tick = () => new Promise(r => setImmediate(r));
  async function wait(ms) {
    const until = now + ms;
    for (;;) {
      await tick();
      timers.sort((a, b) => a.at - b.at || a.id - b.id);
      const t = timers[0];
      if (!t || t.at > until) break;
      timers.shift();
      now = t.at;
      t.fn();
    }
    now = until;
    await tick();
  }
  await wait(0); // public-config fetch answers → buildWidget → Q1

  const $ = id => byId.get(ID + '-' + id);
  const content = () => $('content').innerHTML;
  const opts = () => $('content').querySelectorAll('.hdlw-opt');

  async function toQ9() {
    $('sex-male').click();
    $('q1next').click();                         // → Q2
    $('sil-3').click(); $('fat-even').click();
    await wait(300);                             // → Q3
    for (let q = 3; q <= 8; q++) { opts()[0].click(); await wait(300); } // → Q9
  }
  return { $, content, opts, posts, wait, toQ9 };
}

const onContact = w => /Where shall/.test(w.content());

(async function () {
  // 1. Double click on a Q9 answer (both clicks inside one advance delay).
  let w = await mountWidget();
  await w.toQ9();
  ok(/Section 09/.test(w.content()), 'walk reaches Q9');
  const q9 = w.opts()[0];
  q9.click(); q9.click();
  await w.wait(1000);
  ok(w.posts.length === 0, 'double click on Q9: no lead POST');
  ok(onContact(w), 'double click on Q9: the contact step is shown');

  // 1b. Fill it, double click its own button: exactly one POST with both.
  w.$('name').value = 'Ada';
  w.$('email').value = 'ada@example.com';
  const sub = w.$('submit');
  sub.click(); sub.click();
  await w.wait(1000);
  ok(w.posts.length === 1, 'filled contact + double click: exactly one POST');
  ok(w.posts[0] && w.posts[0].name === 'Ada' && w.posts[0].email === 'ada@example.com', 'the POST carries name and email');
  ok(w.posts[0] && w.posts[0].q9 && w.posts[0].q3, 'the POST carries the answers');

  // 2. Contact button with the fields empty: nothing sent, both marked.
  w = await mountWidget();
  await w.toQ9();
  w.opts()[0].click();
  await w.wait(300);
  ok(onContact(w), 'single click on Q9 → contact step');
  w.$('submit').click();
  await w.wait(1000);
  ok(w.posts.length === 0, 'empty contact submit: no POST');
  ok(w.$('email').style.borderColor && w.$('name').style.borderColor, 'empty contact submit: name and email marked');

  // 3. Double click on Q8 must not skip Q9.
  w = await mountWidget();
  w.$('sex-male').click(); w.$('q1next').click();
  w.$('sil-3').click(); w.$('fat-even').click();
  await w.wait(300);
  for (let q = 3; q <= 7; q++) { w.opts()[0].click(); await w.wait(300); }
  const q8 = w.opts()[0];
  q8.click(); q8.click();
  await w.wait(1000);
  ok(/Section 09/.test(w.content()), 'double click on Q8 lands on Q9, not past it');
  ok(w.posts.length === 0, 'double click on Q8: no POST');

  // 4. Next arrow pressed during the advance delay must not step twice.
  w = await mountWidget();
  await w.toQ9();
  w.opts()[0].click();
  w.$('nav-next').click();                       // goTo(9) now; the queued advance must not then go to 10
  await w.wait(1000);
  ok(onContact(w) && w.posts.length === 0, 'Q9 answer + next arrow: contact step, no POST');

  // 5. Prefilled name/email (0.48.16): fast clicks still stop at the contact step.
  w = await mountWidget({ 'data-prefill-name': 'Pre', 'data-prefill-email': 'pre@example.com' });
  await w.toQ9();
  const p9 = w.opts()[0];
  p9.click(); p9.click();
  await w.wait(1000);
  ok(onContact(w) && w.posts.length === 0, 'prefill + double click on Q9: contact step, no POST');
  w.$('submit').click();
  await w.wait(1000);
  ok(w.posts.length === 1 && w.posts[0].email === 'pre@example.com', 'prefill + submit: one POST with the prefilled email');

  // 6. The send path itself refuses an empty contact (every caller passes here).
  const rr = SRC.slice(SRC.indexOf('function renderResult()'), SRC.indexOf('var payload = {'));
  ok(/if \(!answers\._name \|\| !answers\._email\) \{/.test(rr), 'renderResult() returns to the contact step when name or email is empty');

  console.log('\nPASS=' + PASS + ' FAIL=' + FAIL);
  process.exit(FAIL ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
