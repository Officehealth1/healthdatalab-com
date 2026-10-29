#!/usr/bin/env node
/**
 * 30-day challenge (v0.48.27, D9) — a done day opened again. Extracts and
 * runs the REAL challengeDayModel() from assets/js/hdlv2-client-dashboard.js
 * (what the day view shows, from the section's JSON) in two time zones.
 *
 * Run:  node tests/challenge/scenario-dayview.js   (exit 0/1)
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let PASS = 0, FAIL = 0;
function check(label, cond) { (cond ? PASS++ : FAIL++); console.log((cond ? '  PASS  ' : '  FAIL  ') + label); }

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'hdlv2-client-dashboard.js'), 'utf8');
const start = src.indexOf('function challengeDayModel(');
const end = start < 0 ? -1 : src.indexOf('\n  }\n', start);
check('V0 challengeDayModel() is in the dashboard JS', start >= 0 && end > start);
if (start < 0 || end < 0) { console.log(`\nPASS=${PASS} FAIL=${FAIL}`); process.exit(1); }
const model = vm.runInNewContext(src.slice(start, end + 4) + '; challengeDayModel', { Date, String });

let m = model(24, { title: 'Stand on one leg', text: 'Thirty seconds a side.\nHold a chair.', done_at: '2026-10-23 09:00:00', note: 'Balance on one leg for thirty seconds', youtube_id: 'abcDEF12345' });
check('V1 title, text as lines, the note, the video', m.title === 'Stand on one leg' && m.lines.length === 2 && m.lines[1] === 'Hold a chair.'
  && m.note === 'Balance on one leg for thirty seconds' && m.hasVideo === true && m.embedSrc === 'https://www.youtube-nocookie.com/embed/abcDEF12345');

m = model(3, { title: '', text: '', done_at: '2026-10-03 09:00:00', note: '' });
check('V2 no title → "Day 3"; no text → no lines; no note → "No note"; no video', m.title === 'Day 3' && m.lines.length === 0 && m.note === 'No note' && m.hasVideo === false && m.embedSrc === null);

m = model(4, { done_at: '2026-10-04 09:00:00', youtube_id: 'abc"onload=' });
check('V3 a malformed video id → no video', m.hasVideo === false && m.embedSrc === null);

check('V4 nothing for the day at all → still a title, "No note", no date', (m = model(5, undefined)).title === 'Day 5' && m.note === 'No note' && m.when === '');

process.env.TZ = 'Asia/Manila'; // UTC+8: 23:30 UTC on the 23rd is the 24th
m = model(24, { done_at: '2026-10-23 23:30:00' });
check('V5 Manila: done_at is UTC, said in the buyer\'s own date, day first like the tile ("Done 24 Oct")', m.when === 'Done 24 Oct');

process.env.TZ = 'America/New_York';
m = model(24, { done_at: '2026-10-23 23:30:00' });
check('V6 New York: the same tick is "Done 23 Oct"', m.when === 'Done 23 Oct');

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
