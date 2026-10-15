#!/usr/bin/env node
/**
 * Messages panel: a failed first load must not leave the grey loading bars
 * pulsing above the error note (v0.47.99).
 *
 * Runs the REAL assets/js/hdlv2-inbox.js in vm against a fake DOM and a
 * scripted fetch, in session mode (the logged-in client dashboard).
 *
 * Run:  node tests/inbox/test-inbox-loading.js
 * Exit: 0 all pass · 1 any fail
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'hdlv2-inbox.js'), 'utf8');

function node(className) {
  return {
    className: className || '', children: [], parentNode: null, _text: '', attrs: {},
    get textContent() { return this._text + this.children.map((c) => c.textContent).join(''); },
    set textContent(s) { this.children.forEach((c) => { c.parentNode = null; }); this.children = []; this._text = String(s); },
    appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
    removeChild(c) { this.children = this.children.filter((x) => x !== c); c.parentNode = null; return c; },
    remove() { if (this.parentNode) this.parentNode.removeChild(this); },
    querySelector(sel) {
      const cls = sel.charAt(0) === '.' ? sel.slice(1) : null;
      for (const c of this.children) {
        if (cls && c.className.split(' ').indexOf(cls) !== -1) return c;
        const deep = c.querySelector && c.querySelector(sel);
        if (deep) return deep;
      }
      return null;
    },
    setAttribute(k, v) { this.attrs[k] = v; }, removeAttribute(k) { delete this.attrs[k]; },
    hasAttribute(k) { return k in this.attrs; }, addEventListener() {}
  };
}

// fetchPlan: one entry per fetch call — 'reject', a JSON body, or 'hang'.
async function run(fetchPlan) {
  const thread = node('hdlv2-inbox-thread');
  const loading = thread.appendChild(node('hdlv2-inbox-loading'));
  const note = node('hdlv2-inbox-note');
  const root = node('hdlv2-inbox');
  const parts = { '[data-inbox-thread]': thread, '[data-inbox-note]': note };
  root.querySelector = (sel) => parts[sel] || null;
  let calls = 0;
  const sb = {
    window: {
      HDLV2_INBOX: { mode: 'session', clientHash: 'h', ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'n', practitionerId: 122 },
      setInterval: () => 1, clearInterval() {}
    },
    document: {
      readyState: 'complete', hidden: false,
      querySelector: (sel) => (sel === '[data-inbox]' ? root : null),
      querySelectorAll: () => [],
      getElementById: () => null,
      createElement: () => node(''),
      createTextNode: (t) => { const n = node(''); n._text = t; return n; },
      addEventListener() {}
    },
    URLSearchParams,
    fetch: () => {
      const step = fetchPlan[calls++] || 'hang';
      if (step === 'reject') return Promise.reject(new Error('net'));
      if (step === 'hang') return new Promise(() => {});
      return Promise.resolve({ json: () => step });
    }
  };
  vm.runInNewContext(src, sb);
  for (let i = 0; i < 10; i++) await Promise.resolve();
  return { thread, loading, note };
}

(async () => {
  // Session mode, the server refuses (the known first-visit 403 answers {success:false}).
  let r = await run([{ success: false, data: { message: 'Security check failed' } }]);
  ok(r.loading.parentNode === null && r.thread.querySelector('.hdlv2-inbox-loading') === null, 'refused load: the loading bars are gone');
  ok(r.note.textContent === 'Could not load messages. Please refresh the page.', 'refused load: the note says so');

  // The request itself fails.
  r = await run(['reject']);
  ok(r.loading.parentNode === null, 'failed request: the loading bars are gone');
  ok(r.note.textContent === 'Could not load messages. Please try again shortly.', 'failed request: the note says so');

  // A good load is unchanged: the bars give way to the empty line.
  r = await run([{ success: true, data: { messages: [] } }]);
  ok(r.loading.parentNode === null && r.thread.children.length === 1 && r.thread.children[0].className === 'hdlv2-inbox-empty',
    'good load: the thread holds the empty line only');
  ok(r.note.textContent === '', 'good load: no note');

  console.log('\n' + (FAIL ? 'FAIL' : 'PASS') + ' (' + PASS + ' passed, ' + FAIL + ' failed)');
  process.exit(FAIL ? 1 : 0);
})();
