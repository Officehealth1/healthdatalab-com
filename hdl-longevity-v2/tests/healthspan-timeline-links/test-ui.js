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

// no native dialogs anywhere in the page
const src = fs.readFileSync(file, 'utf8');
ok(!/\b(alert|confirm|prompt)\s*\(/.test(src), 'the page script calls no alert / confirm / prompt');

console.log('\nPASS=' + PASS + ' FAIL=' + FAIL);
process.exit(FAIL ? 1 : 0);
