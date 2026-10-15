#!/usr/bin/env node
/**
 * Paid Stage 1 widget mode (v0.47.85) — which screen the embed shows.
 *
 * Extracts and runs the REAL accessScreen() and safeBuyUrl() from
 * widget/hdl-lead-magnet.js (same approach as tests/report-prep-ticker), then
 * checks the wiring around them in the shipped file.
 *
 * Run:  node tests/paid-stage1/test-widget-access.js
 * Exit: 0 all pass · 1 any fail
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'widget', 'hdl-lead-magnet.js'), 'utf8');

// First-party source from this repo, evaluated in a bare sandbox.
function extract(name) {
  const m = src.match(new RegExp('  function ' + name + '\\([^)]*\\) \\{[\\s\\S]*?\\n  \\}'));
  return m ? vm.runInNewContext(m[0] + '\n' + name + ';', {}) : null;
}
const accessScreen = extract('accessScreen');
const safeBuyUrl = extract('safeBuyUrl');

ok(typeof accessScreen === 'function', 'accessScreen() exists in the widget');
ok(typeof safeBuyUrl === 'function', 'safeBuyUrl() exists in the widget');

if (accessScreen) {
  // accessScreen(hasToken, verify, paidAttr, publicCfg)
  ok(accessScreen(false, null, true, null) === 'locked', 'paid attribute, no token → locked');
  ok(accessScreen(false, null, false, { access_mode: 'paid' }) === 'locked', 'open attribute, paid public-config → locked');
  ok(accessScreen(false, null, true, { access_mode: 'open' }) === 'locked', 'paid attribute wins over a stale open public-config');
  ok(accessScreen(true, { valid: true, source: 'paid_stage1' }, true, null) === 'questions', 'valid paid_stage1 token → questions');
  ok(accessScreen(true, { valid: true, source: 'practitioner' }, true, null) === 'questions', 'valid practitioner invite on a paid page → questions');
  ok(accessScreen(true, { valid: false, reason: 'invalid' }, true, null) === 'error', 'invalid token → error');
  ok(accessScreen(true, { valid: false, reason: 'completed' }, false, null) === 'error', 'used token → error');
  // A link check that a limit or the network stopped is not a bad link.
  ok(accessScreen(true, null, true, null) === 'retry', 'token, link check did not answer (network) → retry');
  ok(accessScreen(true, { code: 'rate_limited', message: 'Too many requests.' }, true, null) === 'retry', 'token, link check blocked by the 30/hour cap → retry');
  ok(accessScreen(true, { code: 'rate_limit_exceeded', retry_after: 600 }, false, null) === 'retry', 'token, link check blocked by the limiter → retry');
  ok(accessScreen(false, null, false, null) === 'questions', 'open page, config fetch failed → questions (as today)');
  ok(accessScreen(false, null, false, { access_mode: 'open' }) === 'questions', 'open page → questions (as today)');
}

if (safeBuyUrl) {
  ok(safeBuyUrl('https://shop.example/buy?x=1') === 'https://shop.example/buy?x=1', 'https buy link kept');
  ok(safeBuyUrl('http://shop.example/buy') === '', 'http buy link dropped');
  ok(safeBuyUrl('javascript:alert(1)') === '', 'javascript: buy link dropped');
  ok(safeBuyUrl('') === '' && safeBuyUrl(null) === '', 'empty buy link → no button');
}

const notSaved = extract('notSaved');
ok(typeof notSaved === 'function', 'notSaved() exists in the widget');
if (notSaved) {
  // notSaved(body, hasLink, status) → { head, msg, again }; status 0 = the request itself failed
  let n = notSaved({ code: 'rate_limit_exceeded', message: 'Too many requests', retry_after: 2402 }, true, 429);
  ok(/not saved yet/i.test(n.head) && /41 minutes/.test(n.msg) && /link still works/i.test(n.msg) && n.again === true, 'blocked submit with a ticket: not saved yet, N minutes, link still works, Send again');
  n = notSaved({ code: 'rate_limit_exceeded', retry_after: 45 }, false, 429);
  ok(/1 minute\b/.test(n.msg) && !/minutes/.test(n.msg) && !/link/i.test(n.msg) && n.again === true, 'free path: 1 minute, no mention of a link');
  n = notSaved(null, false, 0);
  ok(/not saved yet/i.test(n.head) && n.msg.length > 20 && n.again === true, 'network failure: not saved yet, Send again');
  n = notSaved({ code: 'invalid_email', message: 'Please enter a valid email address.' }, false, 400);
  ok(n.msg.indexOf('Please enter a valid email address.') === 0, 'other refusals show the server\'s own message');
  // Send again only where sending the same answers again can work.
  ok(n.again === false && !/send again|keep this page open/i.test(n.msg) && /reload this page/i.test(n.msg), 'bad email, free path: no Send again, says to reload and re-enter');
  n = notSaved({ code: 'undeliverable_email', message: 'That email domain doesn\'t accept mail.' }, true, 400);
  ok(n.again === false && /open your personal link again/i.test(n.msg) && !/not saved yet/i.test(n.head), 'bad email with a ticket: no Send again, says to open the personal link again');
  n = notSaved({ code: 'rate_limited', message: 'Too many submissions. Please try again later.' }, false, 429);
  ok(n.again === true, 'older address cap (429, no retry_after): Send again');
  n = notSaved({ code: 'lead_not_saved', message: 'We could not save your answers. Please try again in a moment; your personal link still works.' }, true, 500);
  ok(n.again === true && (n.msg.match(/link still works/gi) || []).length === 1, 'server fault (500): Send again, "link still works" said once');
  n = notSaved({ code: 'internal_server_error', message: 'There has been a critical error.' }, false, 503);
  ok(n.again === true, '5xx: Send again');
  n = notSaved({ code: 'ticket_required', message: 'This report needs a personal link.' }, true, 403);
  ok(/did not work/i.test(n.head) && n.again === false && !/still works/i.test(n.msg), 'ticket_required: says the link did not work, no Send again');
}

const result = src.slice(src.indexOf("Send lead data"), src.indexOf('Expose a step-jumper'));
ok(/id="' \+ id \+ '-thanks" hidden/.test(src) && /id="' \+ id \+ '-sent" hidden/.test(src), 'thank-you block and the two "sent" lines start hidden');
ok(/-thanks'\)/.test(result) && /-sent'\)/.test(result) && /\.hidden = false/.test(result), 'they are revealed from the saved reply only');
ok(!/catch\(function \(\) \{ \/\* silent/.test(src), 'a failed post is no longer silent');
ok(/Send again/.test(result) && /notSaved\(/.test(result), 'not-saved box offers Send again');
ok(/notSaved\(body, !!inviteToken, status\)/.test(result) && /failed\(res\.body, res\.status\)/.test(result) && /failed\(null, 0\)/.test(result), 'the reply\'s HTTP status reaches notSaved(); a failed fetch passes 0');
const retry = (src.match(/  function showLinkRetry\([^)]*\) \{[\s\S]*?\n  \}/) || [''])[0];
ok(retry !== '' && /try again shortly/i.test(retry) && !/buyLink\(/.test(retry), 'link-check retry card: "try again shortly", no Buy');

// ── Wiring in the shipped file ──
const init = (src.match(/  function init\(\) \{[\s\S]*?\n  \}/) || [''])[0];
ok(/data-access/.test(init) && /accessScreen\(/.test(init), 'init() reads data-access and asks accessScreen()');
const lockedByAttr = init.indexOf('showLocked(');
const cfgFetch = init.indexOf('pullPublicConfig(');
ok(lockedByAttr !== -1 && cfgFetch !== -1 && lockedByAttr < cfgFetch, 'attribute-locked panel is drawn before any public-config fetch');
ok(/if \(needsGeo\) prefetchGeo\(\)/.test(init), 'geo lookup skipped when every widget on the page is locked');
const locked = (src.match(/  function showLocked\([^)]*\) \{[\s\S]*?\n  \}/) || [''])[0];
ok(locked !== '' && !/fetch\(/.test(locked), 'showLocked() makes no request');
ok(/<a class="hdlw-buy"/.test(src) && /\.hdlw-buy:focus-visible/.test(src), 'Buy is a real link with a visible focus state');
ok(!/\b(alert|confirm|prompt)\(/.test(locked), 'no native dialog in the locked panel');
ok(/ticket_required/.test(src), 'ticket_required refusal handled on submit');
ok(/\/\/ ponytail:/.test(src.slice(src.indexOf('function compute(') - 600, src.indexOf('function compute('))), 'ponytail note sits at compute()');

console.log('\nPASS=' + PASS + ' FAIL=' + FAIL);
process.exit(FAIL ? 1 : 0);
