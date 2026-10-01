#!/usr/bin/env node
/**
 * "Send Stage 1 link" on the practitioner dashboard (v0.47.90).
 *
 * Extracts and runs the REAL stage1LinkCard(), sendStage1Link() and
 * stage1LinkSentLine() from assets/js/hdlv2-dashboard.js against a small
 * fake DOM, then checks the wiring around them in the shipped file.
 *
 * Run:  node tests/paid-stage1/test-send-stage1-link.js
 * Exit: 0 all pass · 1 any fail
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'hdlv2-dashboard.js'), 'utf8');
function fn(name) {
  const m = src.match(new RegExp('\\n  function ' + name + '\\([^)]*\\) \\{[\\s\\S]*?\\n  \\}'));
  return m ? m[0] : '';
}
const NAMES = ['stage1LinkCard', 'newRequestId', 'sendStage1Link', 'stage1LinkSentLine', 'formField', 'escAttr', 'parseUtcDate', 'formatExpiryAbsolute'];
const missing = NAMES.filter(n => !fn(n));
ok(missing.length === 0, 'functions exist in the dashboard: ' + (missing.join(', ') || 'all'));
if (missing.length) { console.log('\nPASS=' + PASS + ' FAIL=' + FAIL); process.exit(1); }

// ── fake DOM ─────────────────────────────────────────────────────────
function el() { return { value: '', textContent: '', innerHTML: '', disabled: false, style: {} }; }
function makeWorld(ready) {
  const els = {};
  ['hdlv2-send-s1link', 'hdlv2-s1link-status', 'hdlv2-s1link_name', 'hdlv2-s1link_email',
   'hdlv2-s1link-box', 'hdlv2-s1link-label', 'hdlv2-s1link-url'].forEach(id => { els[id] = el(); });
  const posts = [];
  const world = {
    els, posts, next: null, dialogs: 0,
    CFG: { stage1_link_ready: ready, ajax_url: '/wp-admin/admin-ajax.php', nonce: 'n1' },
    S: { teal: '#3d8da0', btnBase: '', inputBase: '' },
    document: {
      getElementById: id => els[id] || null,
      createElement: () => { const d = { _t: '' }; Object.defineProperty(d, 'textContent', { set(v) { d._t = String(v); } }); Object.defineProperty(d, 'innerHTML', { get() { return d._t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); } }); return d; }
    },
    window: { crypto: { getRandomValues: a => { for (let i = 0; i < a.length; i++) a[i] = Math.floor(Math.random() * 256); return a; } } },
    FormData: class { constructor() { this.f = {}; } append(k, v) { this.f[k] = v; } },
    fetch: (url, opts) => { posts.push(opts.body.f); const n = world.next; return n.reject ? Promise.reject(new Error('net')) : Promise.resolve({ json: () => Promise.resolve(n.json) }); },
    alert: () => { world.dialogs++; }, confirm: () => { world.dialogs++; return true; }, prompt: () => { world.dialogs++; return ''; }
  };
  const ctx = vm.createContext(world);
  vm.runInContext('var s1RequestId = "";\n' + NAMES.map(fn).join('\n') + '\nthis.api = { stage1LinkCard, sendStage1Link, getId: function () { return s1RequestId; } };', ctx);
  return world;
}
const tick = () => new Promise(r => setTimeout(r, 0));

(async function () {
  // Card visibility
  ok(makeWorld(false).api.stage1LinkCard() === '', 'card hidden unless the server says paid mode + ticket page');
  const card = makeWorld(true).api.stage1LinkCard();
  ok(/id="hdlv2-send-s1link"/.test(card) && /Send Stage 1 link/.test(card), 'card shows the Send Stage 1 link button when ready');
  ok(/id="hdlv2-s1link_name"/.test(card) && /id="hdlv2-s1link_email"/.test(card) && !/<select/.test(card), 'card asks for name + email only (no expiry choice)');
  ok(/role="alert"/.test(card) && /aria-live="polite"/.test(card), 'error line and result line are announced');
  ok(!/border-left/.test(card) && !/£/.test(card), 'no accent rail, no price');

  // Retry keeps the request id; success clears it
  const w = makeWorld(true);
  w.els['hdlv2-s1link_name'].value = ' Pat Payer ';
  w.els['hdlv2-s1link_email'].value = 'pat@example.test';
  w.next = { reject: true };
  w.api.sendStage1Link();
  ok(w.els['hdlv2-send-s1link'].disabled === true, 'button disabled while sending');
  await tick(); await tick();
  const first = w.posts[0] || {};
  ok(first.action === 'hdlv2_send_stage1_link' && first.nonce === 'n1' && first.client_name === 'Pat Payer' && first.client_email === 'pat@example.test', 'posts action, nonce, trimmed name + email');
  ok(/^[a-f0-9]{32}$/.test(first.request_id || ''), 'request id is 32 hex (server accepts 16–64 of [A-Za-z0-9_-])');
  ok(first.practitioner_id === undefined, 'never posts a practitioner id');
  ok(w.els['hdlv2-s1link-status'].style.display === 'block' && /again: it will not make a second link/.test(w.els['hdlv2-s1link-status'].textContent), 'network error shown inline, says sending again is safe');
  ok(w.els['hdlv2-send-s1link'].disabled === false, 'button usable again after the error');

  w.next = { json: { success: true, data: { url: 'https://altituding.example.test/report?invite=' + 'a'.repeat(64), expires_at: '2026-12-30 10:00:00', email: 'pat@example.test', email_sent: true, repeat: false } } };
  w.api.sendStage1Link();
  await tick(); await tick();
  ok(w.posts[1] && w.posts[1].request_id === first.request_id, 'retry reuses the same request id (same ticket on the server)');
  const label = w.els['hdlv2-s1link-label'].textContent;
  ok(w.els['hdlv2-s1link-box'].style.display === 'block' && /^Sent to pat@example\.test\. Link valid until .*2026/.test(label), 'success line: "Sent to <email>. Link valid until <date>."');
  ok(w.els['hdlv2-s1link-url'].value === w.next.json.data.url, 'link is in the copy box');
  ok(w.els['hdlv2-s1link-status'].style.display === 'none', 'old error line cleared');
  ok(w.api.getId() === '' && w.els['hdlv2-s1link_email'].value === '', 'after success the form clears and the next send gets a new id');

  // Server refusal
  w.els['hdlv2-s1link_name'].value = 'Sam'; w.els['hdlv2-s1link_email'].value = 'sam@example.test';
  w.next = { json: { success: false, data: 'Too many links this hour. Please try again later.' } };
  w.api.sendStage1Link();
  await tick(); await tick();
  ok(w.els['hdlv2-s1link-status'].textContent === 'Too many links this hour. Please try again later.' && w.els['hdlv2-s1link-status'].style.display === 'block', 'server message shown inline');
  const second = w.posts[2].request_id;
  ok(second && second !== first.request_id, 'a new form gets a new id');

  // Repeat and failed-email lines
  w.next = { json: { success: true, data: { url: 'u', expires_at: '2026-12-30 10:00:00', email: 'sam@example.test', email_sent: false, repeat: true } } };
  w.api.sendStage1Link(); await tick(); await tick();
  ok(/already sent to sam@example\.test/.test(w.els['hdlv2-s1link-label'].textContent), 'repeat says the link was already sent');
  w.els['hdlv2-s1link_name'].value = 'Kim'; w.els['hdlv2-s1link_email'].value = 'kim@example.test';
  w.next = { json: { success: true, data: { url: 'u', expires_at: '2026-12-30 10:00:00', email: 'kim@example.test', email_sent: false, repeat: false } } };
  w.api.sendStage1Link(); await tick(); await tick();
  ok(/email did not go out/.test(w.els['hdlv2-s1link-label'].textContent), 'failed email says so and points at the copy box');

  // Empty form
  const e = makeWorld(true);
  e.api.sendStage1Link();
  ok(e.posts.length === 0 && e.els['hdlv2-s1link-status'].style.display === 'block', 'empty name/email → inline message, nothing posted');
  ok(w.dialogs + e.dialogs === 0, 'no native dialogs');

  // Wiring in the shipped file
  ok(/function sendTabContent\(\) \{[\s\S]*?stage1LinkCard\(\)/.test(src), 'Send Invites tab renders the card');
  ok(/getElementById\('hdlv2-send-s1link'\)[\s\S]{0,200}addEventListener\('click', sendStage1Link\)/.test(src), 'button bound to sendStage1Link');
  ok(/'hdlv2-s1link_name', 'hdlv2-s1link_email'\][\s\S]{0,200}s1RequestId = ''/.test(src), 'editing name or email starts a new request id');

  console.log('\nPASS=' + PASS + ' FAIL=' + FAIL);
  process.exit(FAIL ? 1 : 0);
})();
