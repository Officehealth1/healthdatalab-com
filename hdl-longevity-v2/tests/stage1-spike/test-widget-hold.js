#!/usr/bin/env node
/**
 * Stage 1 under load (v0.48.16) — the widget's holding fallback.
 *
 * Runs the REAL holdUrlFor(), shouldHold() and heldCopy() extracted from
 * widget/hdl-lead-magnet.js (the test-widget-access.js way), then checks the
 * wiring around them in the shipped file.
 *
 * Run:  node tests/stage1-spike/test-widget-hold.js   (exit 0/1)
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'widget', 'hdl-lead-magnet.js'), 'utf8');
const holdDefault = (src.match(/var HOLD_URL_DEFAULT = '([^']+)';/) || [])[1];
function extract(name, ctx) {
  const m = src.match(new RegExp('  function ' + name + '\\([^)]*\\) \\{[\\s\\S]*?\\n  \\}'));
  return m ? vm.runInNewContext(m[0] + '\n' + name + ';', Object.assign({ HOLD_URL_DEFAULT: holdDefault }, ctx || {})) : null;
}

ok(holdDefault === 'https://healthdatalab.com/.netlify/functions/stage1-hold', 'HOLD_URL_DEFAULT is the healthdatalab.com function');
ok(/var LEAD_TIMEOUT_MS = 8000;/.test(src), 'LEAD_TIMEOUT_MS is 8000');

const holdUrlFor = extract('holdUrlFor', { window: {} });
ok(typeof holdUrlFor === 'function', 'holdUrlFor() exists');
if (holdUrlFor) {
  ok(holdUrlFor('https://healthdatalab.net/wp-json/hdl-v2/v1/widget/lead') === holdDefault, 'LIVE API → the built-in holding address');
  ok(holdUrlFor('https://stby.healthdatalab.net/wp-json/hdl-v2/v1/widget/lead') === '', 'STBY API → no holding address (never the production list by accident)');
  ok(holdUrlFor('https://healthdatalab.net.evil.test/wp-json/x') === '', 'look-alike host → none');
  ok(holdUrlFor('') === '', 'no API → none');
  const withOverride = extract('holdUrlFor', { window: { HDLW_HOLD_URL: 'http://localhost:8888/.netlify/functions/stage1-hold' } });
  ok(withOverride('https://stby.healthdatalab.net/wp-json/hdl-v2/v1/widget/lead') === 'http://localhost:8888/.netlify/functions/stage1-hold', 'window.HDLW_HOLD_URL wins (test harness)');
}

const shouldHold = extract('shouldHold');
ok(typeof shouldHold === 'function', 'shouldHold() exists');
if (shouldHold) {
  const H = 'https://h.test/hold';
  [0, 429, 500, 502, 503, 504].forEach(function (s) { ok(shouldHold(s, null, false, H) === true, 'status ' + s + ' → hold'); });
  ok(shouldHold(200, { code: 'server_busy' }, false, H) === true, 'body.code server_busy → hold');
  ok(shouldHold(400, { code: 'invalid_email' }, false, H) === false, '400 invalid_email → no hold (the answers themselves were refused)');
  ok(shouldHold(403, { code: 'ticket_required' }, false, H) === false, 'ticket_required → no hold');
  ok(shouldHold(503, { code: 'server_busy' }, true, H) === false, 'invite token → never hold');
  ok(shouldHold(503, { code: 'server_busy' }, false, '') === false, 'no holding address → no hold');
}

const heldCopy = extract('heldCopy');
ok(typeof heldCopy === 'function', 'heldCopy() exists');
if (heldCopy) {
  const c = heldCopy();
  ok(c.head === 'We’ve got your answers', 'held head');
  ok(c.msg === 'Our server is busy right now, so we have kept them safe. Your report will follow by email, usually within a few hours. You can close this page.', 'held message');
}

// Wiring in the shipped file.
ok(/typeof AbortSignal !== 'undefined' && typeof AbortSignal\.timeout === 'function'/.test(src), 'send() guards AbortSignal.timeout');
ok(/opts\.signal = AbortSignal\.timeout\(LEAD_TIMEOUT_MS\)/.test(src), 'the lead POST gives up after LEAD_TIMEOUT_MS');
ok((src.match(/fetch\(holdUrl, /g) || []).length === 1, 'exactly one fetch to the holding address');
ok(/delete holdPayload\.invite_token/.test(src), 'the held copy of the payload drops invite_token');
ok(/shouldHold\(res\.status, res\.body, !!inviteToken, holdUrl\)/.test(src) && /shouldHold\(0, null, !!inviteToken, holdUrl\)/.test(src), 'both the reply and the network failure go through shouldHold');
ok(/\.hdlw-r-notsaved, \.hdlw-r-held\{/.test(src), 'the held block reuses the not-saved style (no new colour)');
ok(/data-prefill-name/.test(src) && /data-prefill-email/.test(src), 'prefill reads data-prefill-name / data-prefill-email');
ok(/if \(cfg\.prefillName\) document\.getElementById\(id \+ '-name'\)\.value = cfg\.prefillName;/.test(src), 'prefill sets the name input value after renderContact');
ok(/if \(cfg\.prefillEmail\) document\.getElementById\(id \+ '-email'\)\.value = cfg\.prefillEmail;/.test(src), 'prefill sets the email input value after renderContact');
const rc = src.slice(src.indexOf('function renderContact()'), src.indexOf("document.getElementById(id + '-submit').addEventListener('click'"));
ok(/if \(!isInv\) \{\s*if \(cfg\.prefillName\)/.test(rc), 'prefill is skipped on an invite (locked fields untouched)');

console.log('\nPASS=' + PASS + ' FAIL=' + FAIL);
process.exit(FAIL ? 1 : 0);
