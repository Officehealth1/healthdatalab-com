#!/usr/bin/env node
/**
 * Healthspan Timeline links, the page side (v0.48.34). Runs the REAL helpers from
 * assets/js/hdlv2-healthspan-timeline.js in a vm with a stub document (no root, no boot), as
 * tests/healthspan-timeline/test-model.js does: a linked row is read-only with its chip and,
 * when shared, the "from their report" mark; a shared link draws its lane from the three
 * values, anything else falls back to the client's guess; the invite box shows the link, Copy,
 * and Share only where navigator.share exists.
 *
 * Run:  node tests/healthspan-timeline-links/test-ui.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }

const file = path.join(__dirname, '..', '..', 'assets', 'js', 'hdlv2-healthspan-timeline.js');
const ctx = { document: { getElementById: () => null, querySelector: () => null }, console };
ctx.window = ctx;
vm.runInNewContext(fs.readFileSync(file, 'utf8'), ctx);
const M = ctx.HDLV2HealthspanTimeline;
const has = ['linkedPerson', 'chipLabel', 'linkedRowHtml', 'inviteBoxHtml'].every(k => M && typeof M[k] === 'function');
ok(has, 'the page exposes linkedPerson, chipLabel, linkedRowHtml, inviteBoxHtml');
if (!has) { console.log('\nPASS=' + PASS + ' FAIL=' + FAIL); process.exit(1); }

M.reset({ mode: 'full', you: { name: 'Quim', born: M.NOW_Y - 50, sex: 'M', bio: 49, rate0: 0.95 } });
const you = M.state().you;
const shared = { id: 4, label: 'Spouse', relationship: 'spouse', born_guess: 1980, sex_guess: 'F', status: 'accepted', shared: { rate: 1.08, age: 40, sex: 'F' } };
const invited = { id: 5, label: 'Mum <b>', relationship: 'parent', born_guess: 1950, sex_guess: 'F', status: 'invited', shared: null };

// chips (DB9)
ok(['invited', 'accepted', 'declined', 'withdrawn', 'revoked', 'expired'].map(M.chipLabel).join('|') === 'Invited|Shared|Declined|Withdrawn|Ended|Ended',
  'chips: Invited, Shared, Declined, Withdrawn, Ended (revoked and expired both read Ended)');

// lanes
let p = M.linkedPerson(shared, you);
ok(p.born === M.NOW_Y - 40 && p.sex === 'F' && p.rate === 1.08 && p.fromReport === true, 'a shared link: born = this year − age, its sex, pace = its rate, marked from their report');
p = M.linkedPerson(invited, you);
ok(p.born === 1950 && p.sex === 'F' && p.rate === 'same' && !p.fromReport, 'an invited link: the client\'s guess, pace "same as you", no mark');
p = M.linkedPerson({ id: 6, label: 'X', relationship: 'friend', born_guess: null, sex_guess: null, status: 'withdrawn', shared: null }, you);
ok(p.born > 1850 && (p.sex === 'F' || p.sex === 'M') && !p.fromReport, 'a withdrawn link with no guess still draws a lane');

// rows
let h = M.linkedRowHtml(shared);
ok(!/<input|<select/.test(h), 'a linked row has no input and no select (read-only)');
ok(h.indexOf('Shared') !== -1 && h.indexOf('from their report') !== -1 && h.indexOf('1.08') !== -1 && h.indexOf('40') !== -1,
  'a shared row: the chip, the mark and the three values');
ok(/End link/.test(h), 'an accepted row offers "End link"');
h = M.linkedRowHtml(invited);
ok(h.indexOf('Invited') !== -1 && h.indexOf('from their report') === -1 && h.indexOf('&lt;b&gt;') !== -1 && h.indexOf('<b>') === -1,
  'an invited row: the chip, no mark, the label escaped');
h = M.linkedRowHtml(Object.assign({}, invited, { status: 'revoked' }));
ok(h.indexOf('Ended') !== -1 && !/End link/.test(h), 'an ended row: the chip, no button');
h = M.linkedRowHtml(Object.assign({}, shared, { confirming: true }));
ok(/Confirm/.test(h) && /Keep/.test(h), 'the inline two-step: "End link" turns into Confirm and Keep, no native dialog');

// the invite box
const url = 'https://stby-widget--healthdatalab.netlify.app/?invite=' + 'a'.repeat(64);
h = M.inviteBoxHtml(url, false);
ok(h.indexOf(url) !== -1 && />Copy</.test(h) && !/>Share</.test(h), 'the invite box: the link and Copy; no Share without navigator.share');
ok(/readonly/.test(h) && /<label|aria-label/.test(h), 'the link field is read-only and labelled');
h = M.inviteBoxHtml(url, true);
ok(/>Share</.test(h), 'with navigator.share: the Share button appears');
ok(/Nothing is sent by us/.test(h), 'the box says we send nothing');

// v0.48.62 — the invite flow on a phone: the box comes to the client, a failure is said where they
// tapped, a refused copy says so, and an open link can be shown again.
const v62 = ['revealBox', 'copyLink', 'inviteLineHtml', 'linkedListHtml'].every(k => typeof M[k] === 'function');
ok(v62, 'the page exposes revealBox, copyLink, inviteLineHtml, linkedListHtml');
if (v62) {
  const calls = [];
  const copyBtn = { focus: (o) => calls.push(['focus', o]) };
  const box = { scrollIntoView: (o) => calls.push(['scroll', o]), querySelector: (s) => (s === '[data-act="copy"]' ? copyBtn : null) };
  ctx.matchMedia = () => ({ matches: false });
  M.revealBox(box);
  ok(calls.length === 2 && calls[0][0] === 'scroll' && calls[0][1].block === 'center' && calls[0][1].behavior === 'smooth'
    && calls[1][0] === 'focus' && calls[1][1].preventScroll === true, 'a new link: the box scrolls to the middle, then focus lands on Copy without a second jump');
  calls.length = 0; ctx.matchMedia = () => ({ matches: true });
  M.revealBox(box);
  ok(calls[0][1].behavior === 'auto', 'prefers-reduced-motion: no smooth scroll');
  calls.length = 0; delete ctx.matchMedia;
  M.revealBox(box);
  ok(calls[0][1].behavior === 'smooth', 'no matchMedia: still scrolls and focuses');

  h = M.inviteLineHtml('A Very Long Name Indeed For A Phone', null, '');
  ok(/class="ht-btn ht-btn-line"/.test(h) && !/role="status"/.test(h), 'the invite line: the button, no message while nothing failed');
  h = M.inviteLineHtml('Mum', null, 'You have made 10 links today. You can make more tomorrow.');
  ok(h.indexOf('</button><p class="ht-note" role="status">You have made 10 links today. You can make more tomorrow.</p>') !== -1,
    'a failed invite: the reason in a status line right under the tapped button');
  ok(M.inviteLineHtml('Mum', null, '<b>x</b>').indexOf('<b>') === -1, 'the message is escaped');

  const note = { textContent: '' }, inp = { value: url, select: () => { inp.selected = true; } };
  const btn = { textContent: 'Copy' };
  ctx.navigator = { clipboard: { writeText: () => Promise.reject(new Error('denied')) } };
  M.copyLink(inp, btn, note);
  var pageDone = new Promise((resolve) => setTimeout(() => {
    ok(btn.textContent === 'Copy' && note.textContent === 'Copy did not work here. Press and hold the link to copy it.' && inp.selected,
      'a refused copy: the button stays "Copy", the note says so, the text stays selected');
    const btn2 = { textContent: 'Copy' }, note2 = { textContent: 'old' };
    ctx.navigator = { clipboard: { writeText: () => Promise.resolve() } };
    M.copyLink(inp, btn2, note2);
    setTimeout(() => {
      ok(btn2.textContent === 'Copied' && note2.textContent === '', 'a copy that works: "Copied", no note');
      resolve();
    }, 0);
  }, 0));
  ok(/class="ht-note ht-copy-msg" role="status"/.test(M.inviteBoxHtml(url, false)), 'the box holds an empty status line for the copy note');

  const open = Object.assign({}, invited, { url: url });
  h = M.linkedRowHtml(open);
  ok(/data-act="show" data-id="5"[^>]*>Copy link</.test(h) && /End link/.test(h), 'an invited row with a url: "Copy link" beside "End link"');
  ok(!/Copy link/.test(M.linkedRowHtml(invited)), 'an invited row without a url (used ticket, older server): no "Copy link"');
  ok(!/Copy link/.test(M.linkedRowHtml(Object.assign({}, shared, { url: url }))), 'a shared row never offers "Copy link"');
  const list = [Object.assign({}, open, { id: 7 }), open];
  const once = M.linkedListHtml(list, { id: 5, url: url }, false), twice = M.linkedListHtml(list, { id: 5, url: url }, false);
  ok(once === twice && (once.match(/id="ht-invite-url"/g) || []).length === 1 && once.indexOf('id="ht-invite-url"') > once.indexOf('data-id="5"'),
    'one box, under its own row, the same after a re-render');
  ok(M.linkedListHtml(list, null, false).indexOf('ht-invite') === -1, 'no box open: none drawn');
}

function finish() {
  console.log('\nPASS=' + PASS + ' FAIL=' + FAIL);
  process.exit(FAIL ? 1 : 0);
}

// no native dialogs anywhere in the page
const src = fs.readFileSync(file, 'utf8');
ok(!/\b(alert|confirm|prompt)\s*\(/.test(src), 'the page script calls no alert / confirm / prompt');

// finish() prints the count once the copy cases and the widget cases (promises) have settled
Promise.resolve(typeof pageDone !== 'undefined' ? pageDone : null).then(widgetCases).then(finish, (e) => { ok(false, 'widget cases threw: ' + (e && e.stack || e)); finish(); });

/* ---------- v0.48.62 — the widget (widget/hdl-lead-magnet.js), run whole in a vm with a small fake page ---------- */
async function widgetCases() {
  const wsrc = fs.readFileSync(path.join(__dirname, '..', '..', 'widget', 'hdl-lead-magnet.js'), 'utf8');
  const T = 'c'.repeat(64), K = 'd'.repeat(64), W = 'e'.repeat(64);
  const API = 'https://stby.test/wp-json/hdl-v2/v1/widget/lead';
  const tick = () => new Promise((r) => setTimeout(r, 0));
  const flush = async () => { for (let i = 0; i < 6; i++) await tick(); };
  const escText = (t) => String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

  function el(tag) {
    const e = { tagName: tag, attrs: {}, listeners: {}, children: [], style: { setProperty() {} }, disabled: false, hidden: false, _html: '',
      setAttribute(k, v) { this.attrs[k] = String(v); }, getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; },
      appendChild(c) { this.children.push(c); return c; }, addEventListener(t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); },
      click() { (this.listeners.click || []).forEach((f) => f.call(this, { preventDefault() {} })); },
      // buttons and the status line, parsed out of the markup this element was given
      querySelectorAll(sel) {
        if (sel !== 'button') return [];
        if (!this._btns) this._btns = (this._html.match(/<button[^>]*>[^<]*<\/button>/g) || []).map((m) => {
          const b = el('button'); (m.match(/ ([a-z-]+)="([^"]*)"/g) || []).forEach((a) => { const [, k, v] = a.match(/ ([a-z-]+)="([^"]*)"/); b.attrs[k] = v; });
          b.textContent = m.replace(/<[^>]+>/g, ''); return b; });
        return this._btns;
      },
      querySelector(sel) {
        if (sel === 'button') return this.querySelectorAll('button')[0] || null;
        if (sel === '.hdlw-r-status') { if (!this._status && /hdlw-r-status/.test(this._html)) this._status = { textContent: '' }; return this._status || null; }
        return null;
      } };
    Object.defineProperty(e, 'innerHTML', { get() { return this._html; }, set(v) { this._html = String(v); this._btns = null; this._status = null; } });
    Object.defineProperty(e, 'textContent', { get() { return this._text || ''; }, set(v) { this._text = String(v); this._html = escText(v); } });
    return e;
  }

  function run(search, stored, replies) {
    const host = el('div'); host.attrs = { class: 'hdl-rate-widget', 'data-api': API, 'data-practitioner-id': '122' };
    const store = Object.assign({}, stored), calls = [];
    const fetchFake = (url, o) => {
      calls.push({ url: String(url), method: (o && o.method) || 'GET', body: o && o.body ? JSON.parse(o.body) : null });
      const hit = replies.find((r) => String(url).indexOf(r.path) !== -1) || { status: 200, body: {} };
      return Promise.resolve({ ok: hit.status < 400, status: hit.status, json: () => Promise.resolve(hit.body) });
    };
    const doc = { readyState: 'complete', head: el('head'), body: el('body'), createElement: el, getElementById: () => null, querySelector: () => null, getElementsByTagName: () => [],
      querySelectorAll: (s) => (s === '.hdl-rate-widget' ? [host] : []), addEventListener() {} };
    const wctx = { document: doc, console, setTimeout, clearTimeout, URLSearchParams, Promise, JSON, fetch: fetchFake,
      location: { search, href: 'https://altituding.test/report/start' + search },
      localStorage: { getItem: (k) => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = String(v); }, removeItem: (k) => { delete store[k]; } },
      navigator: { userAgent: 'test' }, matchMedia: () => ({ matches: false, addEventListener() {} }) };
    wctx.window = wctx;
    vm.runInNewContext(wsrc, wctx);
    return { host, store, calls };
  }
  const posts = (w, p) => w.calls.filter((c) => c.method === 'POST' && c.url.indexOf(p) !== -1);

  // the withdraw link asks first (W4)
  let w = run('?withdraw=' + W, {}, [{ path: '/timeline/withdraw', status: 200, body: { status: 'withdrawn', link_from: 'Quim' } }]);
  await flush();
  ok(posts(w, '/timeline/withdraw').length === 0 && /This takes your pace, age and sex off their timeline\./.test(w.host.innerHTML)
    && (w.host.innerHTML.match(/<button/g) || []).length === 1 && />Withdraw my numbers<\/button>/.test(w.host.innerHTML),
    'withdraw: the page asks, with one button "Withdraw my numbers", and posts nothing before the tap');
  w.host.querySelector('button').click();
  await flush();
  const wp = posts(w, '/timeline/withdraw');
  ok(wp.length === 1 && wp[0].body.withdraw_token === W && /Done\. Quim no longer sees your numbers\./.test(w.host.innerHTML), 'the tap posts once, then "Done. Quim no longer sees your numbers."');
  w = run('?withdraw=nothex', {}, []);
  await flush();
  ok(w.calls.length === 0 && /Nothing to withdraw/.test(w.host.innerHTML) && !/<button/.test(w.host.innerHTML), 'a malformed withdraw link: "Nothing to withdraw", no button, no request');
  w = run('?withdraw=' + W, {}, [{ path: '/timeline/withdraw', status: 404, body: { code: 'link_gone' } }]);
  await flush(); w.host.querySelector('button').click(); await flush();
  ok(/Nothing to withdraw/.test(w.host.innerHTML), 'a used withdraw link, after the tap: "Nothing to withdraw"');

  // a used timeline link opened again (I1–I3)
  const NAME = 'hdlw-tl-' + T;
  const used = { path: '/widget/verify-invite', status: 200, body: { valid: false, reason: 'completed', link_from: 'Quim' } };
  w = run('?invite=' + T, { [NAME]: K }, [used, { path: '/timeline/consent', status: 200, body: { status: 'accepted', link_from: 'Quim', label: 'Spouse' } }]);
  await flush();
  ok(/Quim invited you/.test(w.host.innerHTML) && />Yes, share<\/button>/.test(w.host.innerHTML) && />No, just my report<\/button>/.test(w.host.innerHTML)
    && /Share your pace of ageing, age and sex with Quim\?/.test(w.host.innerHTML), 'the same browser, key kept: the yes or no card, same words, not "already used"');
  w.host.querySelectorAll('button').find((b) => b.getAttribute('data-d') === 'yes').click();
  await flush();
  const cp = posts(w, '/timeline/consent');
  ok(cp.length === 1 && cp[0].body.invite_token === T && cp[0].body.link_key === K && cp[0].body.decision === 'yes', 'its yes posts the token, the kept key and "yes"');
  ok(/now on Quim’s timeline as Spouse/.test(w.host.innerHTML) && !(NAME in w.store), 'then "You’re now on Quim’s timeline as Spouse." and the key is gone from the browser');

  w = run('?invite=' + T, { [NAME]: K }, [used, { path: '/timeline/consent', status: 200, body: { status: 'declined' } }]);
  await flush(); w.host.querySelectorAll('button').find((b) => b.getAttribute('data-d') === 'no').click(); await flush();
  ok(/Not shared\. Your report is yours\./.test(w.host.innerHTML) && !(NAME in w.store), 'a no: "Not shared" and the key is gone');
  w = run('?invite=' + T, { [NAME]: K }, [used, { path: '/timeline/consent', status: 404, body: { message: 'This link is no longer valid.' } }]);
  await flush(); w.host.querySelectorAll('button')[0].click(); await flush();
  ok(/This link is no longer valid\. Nothing was shared\./.test(w.host.innerHTML) && !(NAME in w.store), 'a 404 (ended, out of date): the server\'s words and the key is gone');
  w = run('?invite=' + T, { [NAME]: K }, [used, { path: '/timeline/consent', status: 403, body: { message: 'This link is for adults. Your report is yours; nothing is shared.' } }]);
  await flush(); w.host.querySelectorAll('button')[0].click(); await flush();
  ok(/This link is for adults\./.test(w.host.innerHTML) && !(NAME in w.store), 'under 18 (the server refuses): the server\'s message through the error branch, and the key is gone (every final answer drops it)');

  w = run('?invite=' + T, {}, [used]);
  await flush();
  ok(/This link has already been used/.test(w.host.innerHTML) && /To share with Quim, ask them for a new link\./.test(w.host.innerHTML) && !/<button/.test(w.host.innerHTML),
    'another browser (no key): "already used … ask them for a new link", no buttons');
  w = run('?invite=' + T, { [NAME]: 'not-a-key' }, [used]);
  await flush();
  ok(/ask them for a new link/.test(w.host.innerHTML), 'a garbled kept key counts as none');
  w = run('?invite=' + T, { [NAME]: K }, [{ path: '/widget/verify-invite', status: 200, body: { valid: false, reason: 'completed' } }]);
  await flush();
  ok(!/invited you/.test(w.host.innerHTML) && !/ask them for a new link/.test(w.host.innerHTML), 'a used ticket of another kind (no link_from): the old "already used" card, untouched');

  // the result page keeps the key it was handed (only for an adult) and the dead-end sentence is gone
  const card = wsrc.slice(wsrc.indexOf('function showLinkCard('), wsrc.indexOf('function failed(body, status)'));
  ok(card.indexOf('age < 18') !== -1 && card.indexOf('linkKeyStore(inviteToken).set(key)') > card.indexOf('age < 18') && card.indexOf('linkKeyStore(inviteToken).set(key)') > card.indexOf('if (!key)'),
    'the result page stores the key only after the under-18 and no-key returns');
  ok(wsrc.indexOf('open the link again') === -1 && card.indexOf('ask them for a new link') !== -1, '"open the link again" (a dead end) is now "ask them for a new link"');

  // a link that has ended (out of date, used without a name, or ended by its maker) leaves no key behind (gate G2)
  for (const [why, body] of [['out of date', { valid: false, reason: 'expired' }], ['used, no name (past its date)', { valid: false, reason: 'completed' }], ['ended by its maker', { valid: false, reason: 'invalid' }]]) {
    w = run('?invite=' + T, { [NAME]: K }, [{ path: '/widget/verify-invite', status: 200, body }]);
    await flush();
    ok(!(NAME in w.store), 'a kept key and a link ' + why + ': the key is gone');
  }
  w = run('?invite=' + T, { [NAME]: K }, [{ path: '/widget/verify-invite', status: 429, body: { code: 'rate_limited' } }]);
  await flush();
  ok(NAME in w.store, 'a busy server (429) is not an answer: the key stays for the next try');

  // the line at the top of the result (gate G1): the REAL linkNotice and consentCard, cut from the widget
  const cut = (name) => { const a = wsrc.indexOf('  function ' + name + '('); return a < 0 ? '' : wsrc.slice(a, wsrc.indexOf('\n  }\n', a) + 4); };
  ok(cut('linkNotice') !== '', 'the widget has linkNotice()');
  if (cut('linkNotice')) {
    const calls = [], inserted = [];
    const heading = { attrs: {}, setAttribute(k, v) { this.attrs[k] = v; }, focus(o) { calls.push(['focus', o, this.attrs.tabindex]); } };
    const cardEl = { scrollIntoView(o) { calls.push(['scroll', o]); }, querySelector: (s) => (s === 'h2' ? heading : null) };
    const gauge = { parentNode: { insertBefore(n, ref) { inserted.push([n, ref]); } } };
    const nctx = { document: { createElement: el }, window: {}, console };
    nctx.window.matchMedia = () => ({ matches: false });
    const N = vm.runInNewContext(cut('escHtml') + cut('linkNotice') + '\nlinkNotice;', nctx);
    const note = N(gauge, cardEl, 'Quim <b>');
    ok(inserted.length === 1 && inserted[0][0] === note && inserted[0][1] === gauge, 'the line goes in directly above the gauge card');
    ok(note.attrs.role === 'status' && /^hdlw-r-card$/.test(note.className || note.attrs.class) && !/border-left|::before/.test(cut('linkNotice')),
      'a status card in the result page\'s own card style, no bar down a side');
    ok(note.innerHTML.indexOf('Quim &lt;b&gt; invited you. Your yes or no is under your result.') !== -1 && note.innerHTML.indexOf('<b>') === -1,
      'it names the inviter (escaped) and says where the yes or no is');
    const nb = note.querySelectorAll('button');
    ok(nb.length === 1 && nb[0].textContent === 'Go to the question' && nb[0].attrs.class === 'hdlw-r-btn-secondary', 'one outline button, "Go to the question"');
    ok(calls.length === 0, 'drawing the line moves nothing');
    nb[0].click();
    ok(calls.length === 2 && calls[0][0] === 'scroll' && calls[0][1].block === 'center' && calls[0][1].behavior === 'smooth'
      && calls[1][0] === 'focus' && calls[1][1].preventScroll === true && calls[1][2] === '-1', 'the button: the card to the middle of the screen, focus on its heading (not on "Yes"), no second jump');
    calls.length = 0; nctx.window.matchMedia = () => ({ matches: true });
    nb[0].click();
    ok(calls[0][1].behavior === 'auto', 'prefers-reduced-motion: no smooth scroll');
    ok(N({ parentNode: null }, cardEl, 'Quim') === null && N(null, cardEl, 'Quim') === null, 'no gauge card on the page: no line, no error');

    // the line goes once the card shows a final answer, and stays on a network failure (the person can try again)
    const C = vm.runInNewContext(cut('escHtml') + cut('linkKeyStore') + cut('consentCard') + '\nconsentCard;',
      { document: { createElement: el }, window: { localStorage: { getItem: () => null, setItem() {}, removeItem() {} } }, JSON, console,
        fetch: () => nctx.reply() });
    for (const [what, reply, gone] of [['a yes', { status: 200, body: { status: 'accepted', label: 'Spouse' } }, true], ['a no', { status: 200, body: { status: 'declined' } }, true],
      ['a refusal', { status: 403, body: { message: 'This link is for adults.' } }, true], ['no network', null, false]]) {
      let removed = null;
      const line = { parentNode: { removeChild(n) { removed = n; } } };
      nctx.reply = () => (reply ? Promise.resolve({ ok: reply.status < 400, status: reply.status, json: () => Promise.resolve(reply.body) }) : Promise.reject(new Error('offline')));
      const box = el('div');
      C(box, { api: API, token: T, key: K, from: 'Quim', email: 'x@example.com', note: line });
      box.querySelectorAll('button')[0].click();
      await flush();
      ok(gone ? removed === line : removed === null, (gone ? what + ': the line at the top is gone' : what + ': the line stays'));
    }
  }
  ok(card.indexOf('linkNotice(') > card.indexOf('age < 18') && card.indexOf('linkNotice(') > card.indexOf('if (!key)'),
    'the result page draws the line only for an adult with a key (after the under-18 and no-key returns)');
  ok(/note: note/.test(card) || /note: linkNotice\(/.test(card), 'the result page hands the line to the card, so a final answer removes it');
}
