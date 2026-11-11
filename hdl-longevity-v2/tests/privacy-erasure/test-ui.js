/**
 * "Delete my data" on the dashboard (v0.48.63): the two steps, the request, the answers.
 * Runs assets/js/hdlv2-privacy-erase.js whole in a vm with a small fake page.
 *   node tests/privacy-erasure/test-ui.js
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SRC = fs.readFileSync(path.join(__dirname, '../../assets/js/hdlv2-privacy-erase.js'), 'utf8');
let pass = 0, fail = 0;
const ok = (label, cond) => { cond ? pass++ : fail++; console.log((cond ? '  PASS ' : '  FAIL ') + label); };

function page() {
  const calls = [];
  let listener = null, pending = null;
  const slot = { innerHTML: '', focused: null };
  const button = (act) => ({
    getAttribute: () => act,
    closest: (sel) => (sel === '[data-erase]' ? button(act) : sel === '[data-hdlv2-erase]' ? slot : null),
    focus: () => { slot.focused = act; },
  });
  slot.querySelector = (sel) => {
    const m = /data-erase="([a-z]+)"/.exec(sel);
    return m && slot.innerHTML.indexOf('data-erase="' + m[1] + '"') >= 0 ? button(m[1]) : null;
  };
  const win = {
    HDLV2_ERASE: { url: 'https://stby.test/wp-json/hdl-v2/v1/privacy/erase-request', nonce: 'n0nce' },
    document: { addEventListener: (t, fn) => { if (t === 'click') listener = fn; } },
    fetch: (url, opts) => { calls.push({ url, opts }); return new Promise((res, rej) => { pending = { res, rej }; }); },
  };
  vm.runInNewContext(SRC, { window: win });
  slot.innerHTML = win.HDLV2Erase.html('');
  return {
    win, slot, calls,
    click: (act) => listener({ target: button(act) }),
    answer: (status, body) => { pending.res({ ok: status < 400, json: () => Promise.resolve(body) }); return new Promise((r) => setTimeout(r, 0)); },
    drop: () => { pending.rej(new Error('offline')); return new Promise((r) => setTimeout(r, 0)); },
  };
}

(async () => {
  console.log('── the two steps ──');
  let p = page();
  ok('U1 the link: "Delete my data"', /data-erase="ask"[^>]*>Delete my data</.test(p.slot.innerHTML));
  p.click('ask');
  ok('U2 the ask on the same line, both buttons, focus on "Keep my data"', p.slot.innerHTML.includes('This deletes your account, answers, reports and recordings. It cannot be undone.') && p.slot.innerHTML.includes('>Send me the confirmation email<') && p.slot.innerHTML.includes('>Keep my data<') && p.slot.focused === 'keep');
  p.click('keep');
  ok('U3 "Keep my data": back to the link, nothing sent', /Delete my data/.test(p.slot.innerHTML) && p.calls.length === 0 && p.slot.focused === 'ask');

  console.log('── the request ──');
  p.click('ask');
  p.click('send');
  const c = p.calls[0] || { opts: {} };
  ok('U4 one POST to the route with the REST nonce and no body (the server uses her own address)', p.calls.length === 1 && c.url === p.win.HDLV2_ERASE.url && c.opts.method === 'POST' && c.opts.credentials === 'same-origin' && c.opts.headers['X-WP-Nonce'] === 'n0nce' && c.opts.body === undefined);
  ok('U5 while it goes: both buttons disabled, "Sending…", no spinner', (p.slot.innerHTML.match(/disabled/g) || []).length === 2 && p.slot.innerHTML.includes('Sending…') && !/spinner|loader/i.test(p.slot.innerHTML));
  await p.answer(200, { message: 'x' });
  ok('U6 sent: the inbox sentence, announced', p.slot.innerHTML.includes('role="status"') && p.slot.innerHTML.includes('Check your inbox. The link in the email confirms it is you. It works for one day.'));

  p = page();
  p.click('ask'); p.click('send');
  await p.answer(500, { message: 'That did not work. <b>Please</b> try again in a minute.' });
  ok('U7 refused: the server\'s words, escaped, as an alert; the two buttons back', p.slot.innerHTML.includes('role="alert"') && p.slot.innerHTML.includes('&lt;b&gt;Please&lt;/b&gt;') && !/disabled/.test(p.slot.innerHTML) && p.slot.focused === 'send');
  p = page();
  p.click('ask'); p.click('send');
  await p.drop();
  ok('U8 no answer: a plain line to try again', p.slot.innerHTML.includes('No answer from the server. Please try again.'));

  console.log('── house rules ──');
  ok('U9 no native dialog in the source', !/\b(alert|confirm|prompt)\s*\(/.test(SRC.replace(/role="alert"/g, '')));

  console.log((fail ? 'FAIL' : 'PASS') + ' ' + pass + ' passed, ' + fail + ' failed');
  process.exit(fail ? 1 : 0);
})();
