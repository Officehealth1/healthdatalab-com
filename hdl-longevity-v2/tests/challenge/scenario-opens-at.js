#!/usr/bin/env node
/**
 * 30-day challenge (v0.48.24, F4) — "Day N+1 opens today/tomorrow at HH:MM"
 * in the buyer's own clock. Extracts and runs the REAL challengeOpensText()
 * from assets/js/hdlv2-client-dashboard.js in two time zones.
 *
 * Run:  node tests/challenge/scenario-opens-at.js   (exit 0/1)
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let PASS = 0, FAIL = 0;
function check(label, cond) { (cond ? PASS++ : FAIL++); console.log((cond ? '  PASS  ' : '  FAIL  ') + label); }

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'hdlv2-client-dashboard.js'), 'utf8');
const start = src.indexOf('function challengeOpensText(');
const end = start < 0 ? -1 : src.indexOf('\n  }\n', start);
check('O0 challengeOpensText() is in the dashboard JS', start >= 0 && end > start);
if (start < 0 || end < 0) { console.log(`\nPASS=${PASS} FAIL=${FAIL}`); process.exit(1); }
const opensText = vm.runInNewContext(src.slice(start, end + 4) + '; challengeOpensText', { Date });

const at = new Date('2026-10-29T00:00:00Z');

process.env.TZ = 'Asia/Manila'; // UTC+8: midnight UTC is 08:00
let t = opensText(1, at, new Date('2026-10-28T02:00:00Z')); // 10:00 on the 28th
check('O1 Manila, ticked 10:00 → "Day 1 done. Day 2 opens tomorrow at 08:00"', /^Day 1 done\. Day 2 opens tomorrow at 08:00( AM)?\.$/.test(t));
t = opensText(1, at, new Date('2026-10-28T23:30:00Z')); // 07:30 on the 29th
check('O2 Manila, ticked 07:30 → "…opens today at 08:00" (the UTC day turns within the local morning)', /^Day 1 done\. Day 2 opens today at 08:00( AM)?\.$/.test(t));

process.env.TZ = 'America/New_York'; // EDT, UTC-4: midnight UTC is 20:00 the evening before
t = opensText(5, at, new Date('2026-10-28T19:00:00Z')); // 15:00 on the 28th
check('O3 New York, ticked 15:00 → "Day 5 done. Day 6 opens today at 20:00"', /^Day 5 done\. Day 6 opens today at (20:00|08:00 PM)\.$/.test(t));

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
