#!/usr/bin/env node
/**
 * Healthspan Timeline model (v0.48.29; dependence bar v0.48.30; Phosphor icons v0.48.32; glyph per heading, drawn sliders v0.48.33). Runs the REAL model functions from
 * assets/js/hdlv2-healthspan-timeline.js in a vm with a stub document (no
 * #hdlv2-ht-root, so the page never boots) and checks the 10-13 items 1–8
 * plus the minimal client view (9–11).
 *
 * Run:  node tests/healthspan-timeline/test-model.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }

const file = path.join(__dirname, '..', '..', 'assets', 'js', 'hdlv2-healthspan-timeline.js');
if (!fs.existsSync(file)) {
  ok(false, 'assets/js/hdlv2-healthspan-timeline.js exists');
  console.log('\nSCENARIO: FAIL (' + PASS + '/' + (PASS + FAIL) + ')');
  process.exit(1);
}
const ctx = { document: { getElementById: () => null, querySelector: () => null }, console };
ctx.window = ctx;
vm.runInNewContext(fs.readFileSync(file, 'utf8'), ctx);
const M = ctx.HDLV2HealthspanTimeline;
ok(!!M, 'the file exposes window.HDLV2HealthspanTimeline without a root element (no boot)');
if (!M) { console.log('\nSCENARIO: FAIL'); process.exit(1); }

const K = M.KNOBS;
const near = (a, b, eps) => Math.abs(a - b) < (eps || 1e-9);
function person(age, sex, rate, bio) {
  M.reset({ mode: 'full', you: { name: 'Kim', born: M.NOW_Y - age, sex: sex, bio: bio == null ? '' : bio, rate0: rate } });
  return M.state();
}

// 1. bioAtRate: today = the biological age given; ten years on at 1.2 = +12.
let S = person(50, 'F', 1.2, 55);
ok(near(M.bioAtRate(S.you, M.NOW_F, 1.0, 1.0, 0), 55), '1a bioAtRate at now = the biological age given');
ok(near(M.bioAtRate(S.you, M.NOW_F + 10, 1.2, 1.2, 0), 67), '1b ten years on at 1.20× (no shift) = bio + 12');

// 2. C44 — the burden is not inverted.
S = person(50, 'F', 1.0);
const slow = M.yearsByStage(S.you, 0.70, 1.0, 0), same = M.yearsByStage(S.you, 1.0, 1.0, 0);
const dep = y => y[2] + y[3] + y[4];
ok(dep(slow) < dep(same), '2a pace 0.70 → fewer orange + red + grey years than 1.00 (' + dep(slow).toFixed(1) + ' < ' + dep(same).toFixed(1) + ')');
ok(slow[0] > same[0], '2b pace 0.70 → more green years (' + slow[0].toFixed(1) + ' > ' + same[0].toFixed(1) + ')');

// 3. C42 — a shift lifts first.
ok(K.recovK === 2.5 && K.recovT === 3, '3a knobs recovK 2.5, recovT 3');
const rec = t => M.bioAtRate(S.you, M.NOW_F + t, 0.7, 1.0, 0) - M.bioAtRate(S.you, M.NOW_F + t, 0.7, 0.7, 0);
ok(near(-rec(80), 7.5, 1e-6), '3b a 0.3 shift recovers 7.5 biological years at the limit (' + (-rec(80)).toFixed(3) + ')');
ok(-rec(3) > 0.6 * 7.5, '3c at now + 3 more than 60% of it (' + (-rec(3)).toFixed(2) + ')');

// 4. ONS rows; the decline compresses on a slow pace and stretches on a fast one.
const F = { sex: 'F' }, Mm = { sex: 'M' };
ok(JSON.stringify(M.stagesFor(F, 1.0)) === JSON.stringify(K.STAGE_BOUNDS_F), '4a F at 1.00 = the ONS row');
ok(JSON.stringify(M.stagesFor(Mm, 1.0)) === JSON.stringify(K.STAGE_BOUNDS_M), '4b M at 1.00 = the ONS row');
const span = (p, r) => { const T = M.stagesFor(p, r); return T[4] - T[0]; };
ok(span(F, 0.70) < span(F, 1.0) && span(F, 1.30) > span(F, 1.0), '4c yellow-to-end shorter at 0.70, longer at 1.30');

// 5. Time together (C46).
S = person(50, 'F', 1.0);
const spouse = { name: 'A', rel: 'spouse', born: S.you.born + 5, sex: 'M', rate: 'same' };
const friend = { name: 'B', rel: 'friend', born: S.you.born + 5, sex: 'M', rate: 'same' };
const ws = M.weeksTogether(spouse, 'new'), wf = M.weeksTogether(friend, 'new');
const sum = a => a.reduce((x, y) => x + y, 0);
ok(sum(ws) > sum(wf), '5a a spouse gets more weeks than a friend born the same year');
ok(ws.concat(wf).every(v => isFinite(v) && v >= 0), '5b every stage bucket finite and non-negative');
ok(ws[1] > 0 && ws[2] > 0, '5c yellow and orange weeks are counted');

// 6. Preview exposes you only.
M.reset({ mode: 'preview', you: { name: 'Kim', born: M.NOW_Y - 50, sex: 'F', rate0: 1.0 } });
ok(M.visiblePeople().length === 1 && M.visiblePeople()[0] === M.state().you, '6a preview: visiblePeople() is the You lane alone');
ok(M.state().people.length === 3, '6b preview still draws three default loved ones (blurred, not interactive)');
M.reset({ mode: 'full', you: { name: 'Kim', born: M.NOW_Y - 50, sex: 'F', rate0: 1.0 } });
ok(M.visiblePeople().length === 4, '6c full: you and the three loved ones');

// 7. The lifestyle mean.
const L12 = ['physicalActivity', 'sleepDuration', 'sleepQuality', 'stressLevels', 'socialConnections', 'dietQuality', 'alcoholConsumption', 'smokingStatus', 'cognitiveActivity', 'sunlightExposure', 'supplementIntake', 'dailyHydration'];
const at = v => L12.reduce((o, k) => (o[k] = v, o), {});
ok(M.lifestyleFromScores(at(4)) === 4, '7a twelve keys at 4 → 4.0');
const mixed = L12.reduce((o, k, i) => (o[k] = i < 6 ? 2 : 4, o), {});
ok(M.lifestyleFromScores(mixed) === 3, '7b six at 2 and six at 4 → 3.0');
ok(M.lifestyleFromScores({}) === null && M.lifestyleFromScores(null) === null && M.lifestyleFromScores([]) === null, '7c none → null (shown as n/a, never 3)');
ok(M.lifestyleFromScores([3, 4, 4]) === 3.7, '7d a list of values (the server sends the picked values) → mean to one decimal');

// 8. Birth year from age.
ok(M.bornFromAge(52, '2026-10-12') === 1974, '8 bornFromAge(52, 2026-10-12) = 1974');

// 9–11. The minimal client view (N13, N15).
ok(JSON.stringify(M.visibleZoomBands()) === JSON.stringify(['year', 'month']), '9 zoom has two bands, years and months');
const off = M.shellHtml({ mode: 'preview', title: 'Healthspan Timeline', show_science: false });
const on = M.shellHtml({ mode: 'preview', title: 'Healthspan Timeline', show_science: true });
ok(off.indexOf('id="ht-fs"') === -1 && on.indexOf('id="ht-fs"') !== -1, '10a science slider absent unless show_science');
ok(off.indexOf('Settings') === -1 && off.indexOf('data-t="settings"') === -1, '10b no Settings tab');
ok(off.indexOf('Weeks') === -1, '10c no Weeks zoom band');
ok(M.paceLabel(0.97, 'report') === 'Your pace of ageing from your report: 0.97× (1.00× = ageing with the calendar)', '11a paceLabel report = the DA9 words');
ok(M.paceLabel(1.1, 'check') === 'Your pace of ageing from your free check', '11b paceLabel check = the preview words');

// The moment panel's age reads as the facts line does (whole years, never rounded up).
ok(M.ageLabel(52.79, 'year') === '52' && M.ageLabel(52.79, 'month') === '52.8' && M.ageLabel(-1, 'year') === '–', '13 ageLabel: 52.79 → 52 in years, 52.8 in months, – before birth');

// Fragment (preview without a token): validated, never trusted.
const fr = M.readFragment('#a=52&s=M&r=1.07&n=Kim%3Cb%3E');
ok(fr && fr.born === M.bornFromAge(52) && fr.sex === 'M' && fr.rate0 === 1.07 && fr.name === 'Kim<b>', '12a fragment read: age → born, sex, rate, name (text only)');
ok(M.readFragment('#a=12&s=M&r=1.07') === null, '12b age under 18 → ignored');
ok(M.readFragment('#a=52&s=X&r=1.07') === null && M.readFragment('#a=52&s=F&r=3') === null, '12c bad sex or rate out of 0.60–1.60 → ignored');
ok(M.readFragment('#a=52&s=F&r=1&n=' + 'x'.repeat(60)).name.length === 40, '12d name capped at 40');
ok(M.readFragment('') === null, '12e no fragment → null (defaults, pace field highlighted)');

// v0.48.32: one icon family on this page, Phosphor duotone (MIT), geometry 1:1 from
// github.com/phosphor-icons/core assets/duotone/<name>-duotone.svg: [soft layer at opacity 0.2, outline].
const SRC = fs.readFileSync(file, 'utf8');
const CSS = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'css', 'hdlv2-healthspan-timeline.css'), 'utf8');
const PHOSPHOR = {
  'couch': ["M240,120v48a8,8,0,0,1-8,8H24a8,8,0,0,1-8-8V120a8,8,0,0,1,8-8V72a8,8,0,0,1,8-8H224a8,8,0,0,1,8,8v40A8,8,0,0,1,240,120Z", "M240,106.17V72a16,16,0,0,0-16-16H32A16,16,0,0,0,16,72v34.17A16,16,0,0,0,8,120v48a16,16,0,0,0,16,16h8v16a8,8,0,0,0,16,0V184H208v16a8,8,0,0,0,16,0V184h8a16,16,0,0,0,16-16V120A16,16,0,0,0,240,106.17ZM224,104h-8a16,16,0,0,0-16,16v16H136V72h88ZM120,72v64H56V120a16,16,0,0,0-16-16H32V72Zm112,96H24V120H40v24a8,8,0,0,0,8,8H208a8,8,0,0,0,8-8V120h16Z"],
  'person-simple-walk': ["M176,48a24,24,0,1,1-24-24A24,24,0,0,1,176,48Z", "M152,80a32,32,0,1,0-32-32A32,32,0,0,0,152,80Zm0-48a16,16,0,1,1-16,16A16,16,0,0,1,152,32Zm64,112a8,8,0,0,1-8,8c-35.31,0-52.95-17.81-67.12-32.12-2.74-2.77-5.36-5.4-8-7.84l-13.43,30.88,37.2,26.57A8,8,0,0,1,160,176v56a8,8,0,0,1-16,0V180.12l-31.07-22.2L79.34,235.19A8,8,0,0,1,72,240a7.84,7.84,0,0,1-3.19-.67,8,8,0,0,1-4.15-10.52l54.08-124.37c-9.31-1.65-20.92,1.2-34.7,8.58a163.88,163.88,0,0,0-30.57,21.77,8,8,0,0,1-10.95-11.66c2.5-2.35,61.69-57.22,98.72-25.08,3.83,3.32,7.48,7,11,10.58C166.19,122.7,179.36,136,208,136A8,8,0,0,1,216,144Z"],
  'person-simple-run': ["M176,56a24,24,0,1,1-24-24A24,24,0,0,1,176,56Z", "M152,88a32,32,0,1,0-32-32A32,32,0,0,0,152,88Zm0-48a16,16,0,1,1-16,16A16,16,0,0,1,152,40Zm67.31,100.68c-.61.28-7.49,3.28-19.67,3.28-13.85,0-34.55-3.88-60.69-20a169.31,169.31,0,0,1-15.41,32.34,104.29,104.29,0,0,1,31.31,15.81C173.92,186.65,184,207.35,184,232a8,8,0,0,1-16,0c0-41.7-34.69-56.71-54.14-61.85-.55.7-1.12,1.41-1.69,2.1-19.64,23.8-44.25,36.18-71.63,36.18A92.29,92.29,0,0,1,31.2,208,8,8,0,0,1,32.8,192c25.92,2.59,48.47-7.49,67-30,12.49-15.14,21-33.61,25.25-47C86.13,92.34,61.27,111.63,61,111.84A8,8,0,1,1,51,99.36c1.5-1.2,37.22-29,89.51,6.57,45.47,30.91,71.93,20.31,72.18,20.19a8,8,0,1,1,6.63,14.56Z"],
  'sparkle': ["M194.82,151.43l-55.09,20.3-20.3,55.09a7.92,7.92,0,0,1-14.86,0l-20.3-55.09-55.09-20.3a7.92,7.92,0,0,1,0-14.86l55.09-20.3,20.3-55.09a7.92,7.92,0,0,1,14.86,0l20.3,55.09,55.09,20.3A7.92,7.92,0,0,1,194.82,151.43Z", "M197.58,129.06,146,110l-19-51.62a15.92,15.92,0,0,0-29.88,0L78,110l-51.62,19a15.92,15.92,0,0,0,0,29.88L78,178l19,51.62a15.92,15.92,0,0,0,29.88,0L146,178l51.62-19a15.92,15.92,0,0,0,0-29.88ZM137,164.22a8,8,0,0,0-4.74,4.74L112,223.85,91.78,169A8,8,0,0,0,87,164.22L32.15,144,87,123.78A8,8,0,0,0,91.78,119L112,64.15,132.22,119a8,8,0,0,0,4.74,4.74L191.85,144ZM144,40a8,8,0,0,1,8-8h16V16a8,8,0,0,1,16,0V32h16a8,8,0,0,1,0,16H184V64a8,8,0,0,1-16,0V48H152A8,8,0,0,1,144,40ZM248,88a8,8,0,0,1-8,8h-8v8a8,8,0,0,1-16,0V96h-8a8,8,0,0,1,0-16h8V72a8,8,0,0,1,16,0v8h8A8,8,0,0,1,248,88Z"],
  'person-arms-spread': ["M104,40a24,24,0,1,1,24,24A24,24,0,0,1,104,40ZM212,80H44c-12.87,0-16.71,17.5-5,22.9L96,128,73.13,214.93a12,12,0,0,0,21.75,10.14L128,168l33.12,57.07a12,12,0,0,0,21.75-10.14L160,128l57-25.1C228.69,97.5,224.85,80,212,80Z", "M160,40a32,32,0,1,0-32,32A32,32,0,0,0,160,40ZM128,56a16,16,0,1,1,16-16A16,16,0,0,1,128,56ZM231.5,87.71A19.62,19.62,0,0,0,212,72H44a20,20,0,0,0-8.38,38.16l.13.06,50.75,22.35-21,79.72A20,20,0,0,0,102,228.82l26-44.88,26,44.88a20,20,0,0,0,36.4-16.53l-21-79.72,50.75-22.35.13-.06A19.63,19.63,0,0,0,231.5,87.71Zm-17.8,7.9-56.93,25.07a8,8,0,0,0-4.51,9.36L175.13,217a7,7,0,0,0,.49,1.35,4,4,0,1,1-7.25,3.38c-.11-.22-.22-.43-.34-.63L134.92,164a8,8,0,0,0-13.84,0L88,221.06c-.12.2-.23.41-.34.63a4,4,0,1,1-7.25-3.38,7,7,0,0,0,.49-1.35L103.74,130a8,8,0,0,0-4.51-9.36L42.3,95.61A4,4,0,0,1,44,88H212a4,4,0,0,1,1.73,7.61Z"],
  'users-three': ["M168,144a40,40,0,1,1-40-40A40,40,0,0,1,168,144ZM64,56A32,32,0,1,0,96,88,32,32,0,0,0,64,56Zm128,0a32,32,0,1,0,32,32A32,32,0,0,0,192,56Z", "M244.8,150.4a8,8,0,0,1-11.2-1.6A51.6,51.6,0,0,0,192,128a8,8,0,0,1,0-16,24,24,0,1,0-23.24-30,8,8,0,1,1-15.5-4A40,40,0,1,1,219,117.51a67.94,67.94,0,0,1,27.43,21.68A8,8,0,0,1,244.8,150.4ZM190.92,212a8,8,0,1,1-13.85,8,57,57,0,0,0-98.15,0,8,8,0,1,1-13.84-8,72.06,72.06,0,0,1,33.74-29.92,48,48,0,1,1,58.36,0A72.06,72.06,0,0,1,190.92,212ZM128,176a32,32,0,1,0-32-32A32,32,0,0,0,128,176ZM72,120a8,8,0,0,0-8-8A24,24,0,1,1,87.24,82a8,8,0,1,0,15.5-4A40,40,0,1,0,37,117.51,67.94,67.94,0,0,0,9.6,139.19a8,8,0,1,0,12.8,9.61A51.6,51.6,0,0,1,64,128,8,8,0,0,0,72,120Z"],
  'lock': ["M216,96V208a8,8,0,0,1-8,8H48a8,8,0,0,1-8-8V96a8,8,0,0,1,8-8H208A8,8,0,0,1,216,96Z", "M208,80H176V56a48,48,0,0,0-96,0V80H48A16,16,0,0,0,32,96V208a16,16,0,0,0,16,16H208a16,16,0,0,0,16-16V96A16,16,0,0,0,208,80ZM96,56a32,32,0,0,1,64,0V80H96ZM208,208H48V96H208V208Zm-68-56a12,12,0,1,1-12-12A12,12,0,0,1,140,152Z"],
  // v0.48.33: one glyph per heading and per moment card
  'heartbeat': ["M232,102c0,66-104,122-104,122S24,168,24,102A54,54,0,0,1,78,48c22.59,0,41.94,12.31,50,32,8.06-19.69,27.41-32,50-32A54,54,0,0,1,232,102Z", "M72,144H32a8,8,0,0,1,0-16H67.72l13.62-20.44a8,8,0,0,1,13.32,0l25.34,38,9.34-14A8,8,0,0,1,136,128h24a8,8,0,0,1,0,16H140.28l-13.62,20.44a8,8,0,0,1-13.32,0L88,126.42l-9.34,14A8,8,0,0,1,72,144ZM178,40c-20.65,0-38.73,8.88-50,23.89C116.73,48.88,98.65,40,78,40a62.07,62.07,0,0,0-62,62c0,.75,0,1.5,0,2.25a8,8,0,1,0,16-.5c0-.58,0-1.17,0-1.75A46.06,46.06,0,0,1,78,56c19.45,0,35.78,10.36,42.6,27a8,8,0,0,0,14.8,0c6.82-16.67,23.15-27,42.6-27a46.06,46.06,0,0,1,46,46c0,53.61-77.76,102.15-96,112.8-10.83-6.31-42.63-26-66.68-52.21a8,8,0,1,0-11.8,10.82c31.17,34,72.93,56.68,74.69,57.63a8,8,0,0,0,7.58,0C136.21,228.66,240,172,240,102A62.07,62.07,0,0,0,178,40Z"],
  'mountains': ["M144,52a20,20,0,1,1,20,20A20,20,0,0,1,144,52Zm49.44,55.92a8,8,0,0,0-13.77,0l-33,55.75-21-35.67H50.35L8,200H248Z", "M164,80a28,28,0,1,0-28-28A28,28,0,0,0,164,80Zm0-40a12,12,0,1,1-12,12A12,12,0,0,1,164,40Zm90.88,155.92-54.56-92.08A15.87,15.87,0,0,0,186.55,96h0a15.85,15.85,0,0,0-13.76,7.84L146.63,148l-44.84-76.1a16,16,0,0,0-27.58,0L1.11,195.94A8,8,0,0,0,8,208H248a8,8,0,0,0,6.88-12.08ZM88,80l23.57,40H64.43ZM22,192l33-56h66l33,56Zm150.57,0-16.66-28.28L186.55,112,234,192Z"],
  'trend-up': ["M232,56v64L168,56Z", "M232,48H168a8,8,0,0,0-5.66,13.66L188.69,88,136,140.69l-34.34-34.35a8,8,0,0,0-11.32,0l-72,72a8,8,0,0,0,11.32,11.32L96,123.31l34.34,34.35a8,8,0,0,0,11.32,0L200,99.31l26.34,26.35A8,8,0,0,0,240,120V56A8,8,0,0,0,232,48Zm-8,52.69L187.31,64H224Z"],
  'hourglass': ["M188.82,82,128,128,67.2,82.4A8,8,0,0,1,64,76V40a8,8,0,0,1,8-8H184a8,8,0,0,1,8,8V75.64A8,8,0,0,1,188.82,82ZM64,180v36a8,8,0,0,0,8,8H184a8,8,0,0,0,8-8V180.36a8,8,0,0,0-3.18-6.38L128,128,67.2,173.6A8,8,0,0,0,64,180Z", "M200,75.64V40a16,16,0,0,0-16-16H72A16,16,0,0,0,56,40V76a16.07,16.07,0,0,0,6.4,12.8L114.67,128,62.4,167.2A16.07,16.07,0,0,0,56,180v36a16,16,0,0,0,16,16H184a16,16,0,0,0,16-16V180.36a16.09,16.09,0,0,0-6.35-12.77L141.27,128l52.38-39.59A16.09,16.09,0,0,0,200,75.64ZM184,216H72V180l56-42,56,42.35Zm0-140.36L128,118,72,76V40H184Z"],
  'chart-line-up': ["M224,64V208H32V48H208A16,16,0,0,1,224,64Z", "M232,208a8,8,0,0,1-8,8H32a8,8,0,0,1-8-8V48a8,8,0,0,1,16,0V156.69l50.34-50.35a8,8,0,0,1,11.32,0L128,132.69,180.69,80H160a8,8,0,0,1,0-16h40a8,8,0,0,1,8,8v40a8,8,0,0,1-16,0V91.31l-58.34,58.35a8,8,0,0,1-11.32,0L96,123.31l-56,56V200H224A8,8,0,0,1,232,208Z"],
  'hand-heart': ["M16,152H48v56H16a8,8,0,0,1-8-8V160A8,8,0,0,1,16,152ZM192.54,40A39.12,39.12,0,0,0,156,64a39.12,39.12,0,0,0-36.54-24C97.67,40,80,58.31,80,80c0,14.56,7,27.71,16.73,40H140a20,20,0,0,1,0,40h4l37.78-8.68C203.82,135.07,232,109.23,232,80,232,58.31,214.33,40,192.54,40Z", "M230.33,141.06a24.34,24.34,0,0,0-18.61-4.77C230.5,117.33,240,98.48,240,80c0-26.47-21.29-48-47.46-48A47.58,47.58,0,0,0,156,48.75,47.58,47.58,0,0,0,119.46,32C93.29,32,72,53.53,72,80c0,11,3.24,21.69,10.06,33a31.87,31.87,0,0,0-14.75,8.4L44.69,144H16A16,16,0,0,0,0,160v40a16,16,0,0,0,16,16H120a7.93,7.93,0,0,0,1.94-.24l64-16a6.94,6.94,0,0,0,1.19-.4L226,182.82l.44-.2a24.6,24.6,0,0,0,3.93-41.56ZM119.46,48A31.15,31.15,0,0,1,148.6,67a8,8,0,0,0,14.8,0,31.15,31.15,0,0,1,29.14-19C209.59,48,224,62.65,224,80c0,19.51-15.79,41.58-45.66,63.9l-11.09,2.55A28,28,0,0,0,140,112H100.68C92.05,100.36,88,90.12,88,80,88,62.65,102.41,48,119.46,48ZM16,160H40v40H16Zm203.43,8.21-38,16.18L119,200H56V155.31l22.63-22.62A15.86,15.86,0,0,1,89.94,128H140a12,12,0,0,1,0,24H112a8,8,0,0,0,0,16h32a8.32,8.32,0,0,0,1.79-.2l67-15.41.31-.08a8.6,8.6,0,0,1,6.3,15.9Z"],
  'path': ["M224,200a24,24,0,1,1-24-24A24,24,0,0,1,224,200Z", "M200,168a32.06,32.06,0,0,0-31,24H72a32,32,0,0,1,0-64h96a40,40,0,0,0,0-80H72a8,8,0,0,0,0,16h96a24,24,0,0,1,0,48H72a48,48,0,0,0,0,96h97a32,32,0,1,0,31-40Zm0,48a16,16,0,1,1,16-16A16,16,0,0,1,200,216Z"],
  'compass': ["M128,32a96,96,0,1,0,96,96A96,96,0,0,0,128,32Zm16,112L80,176l32-64,64-32Z", "M128,24A104,104,0,1,0,232,128,104.11,104.11,0,0,0,128,24Zm0,192a88,88,0,1,1,88-88A88.1,88.1,0,0,1,128,216ZM172.42,72.84l-64,32a8.05,8.05,0,0,0-3.58,3.58l-32,64A8,8,0,0,0,80,184a8.1,8.1,0,0,0,3.58-.84l64-32a8.05,8.05,0,0,0,3.58-3.58l32-64a8,8,0,0,0-10.74-10.74ZM138,138,97.89,158.11,118,118l40.15-20.07Z"],
  'user': ["M192,96a64,64,0,1,1-64-64A64,64,0,0,1,192,96Z", "M230.92,212c-15.23-26.33-38.7-45.21-66.09-54.16a72,72,0,1,0-73.66,0C63.78,166.78,40.31,185.66,25.08,212a8,8,0,1,0,13.85,8c18.84-32.56,52.14-52,89.07-52s70.23,19.44,89.07,52a8,8,0,1,0,13.85-8ZM72,96a56,56,0,1,1,56,56A56.06,56.06,0,0,1,72,96Z"],
  'users': ["M136,108A52,52,0,1,1,84,56,52,52,0,0,1,136,108Z", "M117.25,157.92a60,60,0,1,0-66.5,0A95.83,95.83,0,0,0,3.53,195.63a8,8,0,1,0,13.4,8.74,80,80,0,0,1,134.14,0,8,8,0,0,0,13.4-8.74A95.83,95.83,0,0,0,117.25,157.92ZM40,108a44,44,0,1,1,44,44A44.05,44.05,0,0,1,40,108Zm210.14,98.7a8,8,0,0,1-11.07-2.33A79.83,79.83,0,0,0,172,168a8,8,0,0,1,0-16,44,44,0,1,0-16.34-84.87,8,8,0,1,1-5.94-14.85,60,60,0,0,1,55.53,105.64,95.83,95.83,0,0,1,47.22,37.71A8,8,0,0,1,250.14,206.7Z"],
  'flag-checkered': ["M224,56v60c-19.89,17.23-38.23,18.39-56,13.48v-60C185.76,74.39,204.11,73.23,224,56ZM104,102.52v-60C86.24,37.61,67.89,38.77,48,56v60c19.89-17.22,38.23-18.39,56-13.48v60c21.62,6,42.38,21,64,27v-60c-21.62-6-42.38-21-64-27Z", "M227.32,48.75A8,8,0,0,0,218.76,50c-28,24.22-51.72,12.48-79.21-1.13C111.07,34.76,78.78,18.79,42.76,50A8,8,0,0,0,40,56V224a8,8,0,0,0,16,0V179.77c26.79-21.16,49.87-9.75,76.45,3.41,28.49,14.09,60.77,30.06,96.79-1.13a8,8,0,0,0,2.76-6V56A8,8,0,0,0,227.32,48.75ZM216,71.6v40.65c-14,11.06-27,13.22-40,10.88V79.34A60.05,60.05,0,0,0,216,71.6Zm-56,3.76v43c-6.66-2.67-13.43-6-20.45-9.48-8.82-4.37-18-8.91-27.55-12.18v-43c6.66,2.66,13.43,6,20.45,9.48C141.27,67.55,150.46,72.09,160,75.36ZM96,48.91V92.69a60.06,60.06,0,0,0-40,7.75V59.78C70,48.72,83,46.57,96,48.91ZM86.58,152A60.06,60.06,0,0,0,56,160.43V119.78c14-11.06,27-13.22,40-10.88v43.8A65.61,65.61,0,0,0,86.58,152ZM112,156.67v-43c6.66,2.66,13.43,6,20.45,9.48,8.82,4.37,18,8.9,27.55,12.17v43c-6.66-2.67-13.43-6-20.45-9.48C130.73,164.47,121.54,159.94,112,156.67Zm64,26.45v-43.8a65.61,65.61,0,0,0,9.42.72A60.11,60.11,0,0,0,216,131.57v40.68C202,183.31,189,185.46,176,183.12Z"]
};
const NAMES = Object.keys(PHOSPHOR);
ok(typeof M.iconHtml === 'function' && typeof M.depBarHtml === 'function' && typeof M.presetHtml === 'function', '14a iconHtml, depBarHtml and presetHtml exposed');
if (typeof M.iconHtml !== 'function' || typeof M.depBarHtml !== 'function' || typeof M.presetHtml !== 'function') { console.log('\nSCENARIO: FAIL (' + PASS + '/' + (PASS + FAIL) + ')'); process.exit(1); }
const paths = s => [...String(s).matchAll(/<path d="([^"]+)"( opacity="0\.2")?\/>/g)].map(m => [m[1], !!m[2]]);
ok(NAMES.length === 18 && M.HT_ICONS && JSON.stringify(Object.keys(M.HT_ICONS).sort()) === JSON.stringify(NAMES.slice().sort()), '14n HT_ICONS holds exactly the eighteen Phosphor names (' + (M.HT_ICONS ? Object.keys(M.HT_ICONS).length : 0) + ')');
ok(NAMES.every(n => { const h = M.iconHtml(n, '#c93437'); return (h.match(/<svg/g) || []).length === 1 && new RegExp('^<svg[^>]* data-ht-icon="' + n + '"').test(h) && / viewBox="0 0 256 256"/.test(h) && /aria-hidden="true"/.test(h) && /focusable="false"/.test(h); }), '14b the eighteen Phosphor names: one <svg> each, viewBox 256, aria-hidden, not focusable');
ok(NAMES.every(n => { const ps = paths(M.iconHtml(n)); return ps.length === 2 && ps[0][0] === PHOSPHOR[n][0] && ps[0][1] && ps[1][0] === PHOSPHOR[n][1] && !ps[1][1]; }), '14c each: the soft layer (opacity 0.2) then the outline, geometry 1:1 with the source');
ok(/ fill="#c93437"/.test(M.iconHtml('couch', '#c93437')) && / fill="currentColor"/.test(M.iconHtml('lock')) && M.iconHtml('nope') === '' && M.iconHtml('battery-charging-icon') === '', '14d colour on the fill (given or currentColor); unknown or old keys → nothing');
ok(!/battery|sparkles-icon|user-icon|users-group-icon|lock-icon|stroke-width="2\.6"/.test(SRC), '14e no itshover or hand-drawn icon left in the page script');
ok(JSON.stringify(K.PROFILES.map(p => p.icon)) === JSON.stringify(['couch', 'person-simple-walk', 'person-simple-run', 'sparkle']), '14f Poor couch, Average walking, Healthy running, Optimal sparkle');
const btn = K.PROFILES.map(p => M.presetHtml(p, 1.25));
ok(btn.every((h, i) => (h.match(/data-ht-icon="([^"]+)"/) || [])[1] === K.PROFILES[i].icon), '14g each path button carries its own glyph');
ok(/<b>Poor<\/b>/.test(btn[0]) && /<span class="ht-prof-r">1\.25×<\/span>/.test(btn[0]) && /<small>Sedentary, poor sleep, no plan<\/small>/.test(btn[0]) && /style="--c:var\(--ht-red\)"/.test(btn[0]), '14h the button keeps the name, the pace, the words and its band colour');
ok(/aria-pressed="true"/.test(btn[0]) && /aria-pressed="false"/.test(btn[1]), '14i pressed only for the pace in use');
ok(!/function figure\(|stageFig|ICON\[/.test(SRC) && !/ht-figrow|vector-effect/.test(CSS), '14l figure(), stageFig(), .ht-figrow and the stroke rule for line icons are gone');
ok(!/border-left/.test(CSS), '14m no border-left in the timeline styles (no accent rails)');

// v0.48.33: one glyph per heading (tabs, timeline eyebrow, the three panel titles) and per moment card.
const glyphs = h => [...String(h).matchAll(/data-ht-icon="([^"]+)"/g)].map(m => m[1]);
const sh = M.shellHtml({ mode: 'full', show_science: false });
const tabBtn = id => (sh.match(new RegExp('<button[^>]*id="' + id + '"[^>]*>(.*?)</button>')) || [])[1] || '';
ok(JSON.stringify(glyphs(tabBtn('ht-tab-btn-you'))) === '["user"]' && /You$/.test(tabBtn('ht-tab-btn-you')) && JSON.stringify(glyphs(tabBtn('ht-tab-btn-people'))) === '["users"]' && /Loved ones$/.test(tabBtn('ht-tab-btn-people')), '16a tabs: You → user, Loved ones → users, glyph then the word');
ok(/<span class="ht-eyebrow">[^]*?data-ht-icon="path"[^]*?Timeline<\/span>/.test(sh), '16b the timeline band eyebrow carries path');
const h2s = [...sh.matchAll(/<h2>(.*?)<\/h2>/g)].map(m => [glyphs(m[1]).join(','), m[1].replace(/<svg.*?<\/svg>/g, '').trim()]);
ok(JSON.stringify(h2s) === JSON.stringify([['hourglass', 'Your years ahead'], ['', 'Years you depend on others'], ['chart-line-up', 'Your trajectory'], ['hand-heart', 'Time with the people you love']]), '16c panel titles: hourglass, (users-three stays by the number), chart-line-up, hand-heart');
ok(/iconHtml\('flag-checkered'\)/.test(SRC), '16d the challenge card title carries flag-checkered');
ok(typeof M.momentHtml === 'function', '16e momentHtml exposed');
if (typeof M.momentHtml === 'function') {
  S = person(50, 'F', 1.0);
  const mo = M.momentHtml(S.you, M.NOW_F, 'year', true);
  const cards = mo.split('<div class="ht-mcard">').slice(1);
  ok(cards.length === 4 && JSON.stringify(cards.map(c => glyphs(c).join(','))) === '["person-arms-spread","heartbeat","mountains","trend-up"]', '16f the four moment cards: one glyph each, person-arms-spread, heartbeat, mountains, trend-up');
  ok(JSON.stringify(glyphs(mo.split('<div class="ht-cards">')[0])) === '["compass"]' && /Where you are right now/.test(mo), '16g "Where you are right now" carries compass');
  ok(/ fill="#3fae4a"/.test(cards[0]) && !/ fill="#/.test(cards.slice(1).join('')), '16h the capability glyph keeps the stage colour, the other three take the heading colour');
}

// v0.48.33: the sliders are drawn by the page: --p is the filled part of the track, in %.
ok(typeof M.paintRange === 'function', '17a paintRange exposed');
if (typeof M.paintRange === 'function') {
  const rng = (value, min, max) => { const st = {}; M.paintRange({ value: String(value), min: String(min), max: String(max), style: { setProperty: (k, v) => { st[k] = v; } } }); return st['--p']; };
  ok(rng(1.18, 0.6, 1.6) === '58.0%' && rng(0.6, 0.6, 1.6) === '0.0%' && rng(1.6, 0.6, 1.6) === '100.0%' && rng(37, 0, 100) === '37.0%', '17b --p = (value − min) / (max − min) × 100 (1.18 on 0.60–1.60 → 58.0%)');
}
ok((SRC.match(/paintRange\(/g) || []).length >= 7, '17c paintRange on render (rate, science, zoom) and in the three input handlers');
const RANGE = (CSS.match(/input\[type=range\]\{[^}]*\}/) || [''])[0];
ok(/appearance:none/.test(RANGE) && !/accent-color/.test(CSS), '17d the page sets appearance:none itself; no accent-color left');
ok(/::-webkit-slider-runnable-track\{[^}]*height:4px[^}]*linear-gradient\(to right,var\(--ht-range\) var\(--p,0%\),var\(--ht-line\) var\(--p,0%\)\)/.test(CSS) && /::-moz-range-track\{[^}]*height:4px/.test(CSS) && /::-moz-range-progress\{[^}]*background:var\(--ht-range\)/.test(CSS), '17e a 4 px track, teal up to the thumb (WebKit gradient, Firefox progress)');
ok(/::-webkit-slider-thumb\{[^}]*width:20px;height:20px/.test(CSS) && /::-moz-range-thumb\{[^}]*width:20px;height:20px/.test(CSS) && /slider-thumb\{[^}]*border:2px solid #fff/.test(CSS), '17f a 20 px round thumb with a 2 px white ring, both engines');
ok(/\.ht-fs input\{--ht-range:var\(--ht-future\)\}/.test(CSS), '17g the science slider in --ht-future');

// v0.48.33: hover motion on the four tiles, CSS only, nothing under reduced motion.
const MOTION = (CSS.match(/@media \(hover:hover\) and \(prefers-reduced-motion:no-preference\)\{([^]*?)\}\s*\n/) || ['', ''])[1];
ok(/person-simple-walk[^{]*person-simple-run[^{]*\{[^}]*rotate\(6deg\)/.test(MOTION) && /sparkle"\]\{[^}]*scale\(1\.1\)/.test(MOTION) && /couch"\]\{[^}]*translateY\(1px\)/.test(MOTION) && /\.ht-prof:hover \.ht-ico\{[^}]*translateY\(-2px\)/.test(MOTION), '18a lift 2 px, walk and run tilt 6°, sparkle 1.1, couch sinks 1 px, only without reduced motion');
ok(/\.ht-prof \.ht-ico\{[^}]*transition:transform \.18s ease-out/.test(CSS), '18b 180 ms ease-out');

// The dependence bar: one stacked bar in orange, red, grey; the segments are the whole bar.
const depBar = M.depBarHtml({ orange: 6.8, red: 3.5, grey: 1.1 });
const w = [...depBar.matchAll(/--w:([\d.]+)%/g)].map(m => +m[1]);
ok(w.length === 3 && Math.abs(w.reduce((a, b) => a + b, 0) - 100) < 0.1, '15a three segments that add to 100% (' + w.join(' + ') + ')');
ok(depBar.indexOf('--ht-orange') < depBar.indexOf('--ht-red') && depBar.indexOf('--ht-red') < depBar.indexOf('--ht-grey') && /class="ht-sbar ht-depbar"/.test(depBar), '15b orange, red, grey in order, on the page\'s .ht-sbar');
ok(M.depBarHtml({ orange: 0, red: 0, grey: 0 }) === '', '15c nothing dependent → no bar (the panel says "none on this path")');

console.log('\nSCENARIO: ' + (FAIL ? 'FAIL' : 'PASS') + ' (' + PASS + '/' + (PASS + FAIL) + ')');
process.exit(FAIL ? 1 : 0);
