#!/usr/bin/env node
/**
 * Healthspan Timeline model (v0.48.29; dependence bar v0.48.30; Phosphor icons v0.48.32). Runs the REAL model functions from
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
  'lock': ["M216,96V208a8,8,0,0,1-8,8H48a8,8,0,0,1-8-8V96a8,8,0,0,1,8-8H208A8,8,0,0,1,216,96Z", "M208,80H176V56a48,48,0,0,0-96,0V80H48A16,16,0,0,0,32,96V208a16,16,0,0,0,16,16H208a16,16,0,0,0,16-16V96A16,16,0,0,0,208,80ZM96,56a32,32,0,0,1,64,0V80H96ZM208,208H48V96H208V208Zm-68-56a12,12,0,1,1-12-12A12,12,0,0,1,140,152Z"]
};
const NAMES = Object.keys(PHOSPHOR);
ok(typeof M.iconHtml === 'function' && typeof M.depBarHtml === 'function' && typeof M.presetHtml === 'function', '14a iconHtml, depBarHtml and presetHtml exposed');
if (typeof M.iconHtml !== 'function' || typeof M.depBarHtml !== 'function' || typeof M.presetHtml !== 'function') { console.log('\nSCENARIO: FAIL (' + PASS + '/' + (PASS + FAIL) + ')'); process.exit(1); }
const paths = s => [...String(s).matchAll(/<path d="([^"]+)"( opacity="0\.2")?\/>/g)].map(m => [m[1], !!m[2]]);
ok(NAMES.every(n => { const h = M.iconHtml(n, '#c93437'); return (h.match(/<svg/g) || []).length === 1 && new RegExp('^<svg[^>]* data-ht-icon="' + n + '"').test(h) && / viewBox="0 0 256 256"/.test(h) && /aria-hidden="true"/.test(h) && /focusable="false"/.test(h); }), '14b the seven Phosphor names: one <svg> each, viewBox 256, aria-hidden, not focusable');
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

// The dependence bar: one stacked bar in orange, red, grey; the segments are the whole bar.
const depBar = M.depBarHtml({ orange: 6.8, red: 3.5, grey: 1.1 });
const w = [...depBar.matchAll(/--w:([\d.]+)%/g)].map(m => +m[1]);
ok(w.length === 3 && Math.abs(w.reduce((a, b) => a + b, 0) - 100) < 0.1, '15a three segments that add to 100% (' + w.join(' + ') + ')');
ok(depBar.indexOf('--ht-orange') < depBar.indexOf('--ht-red') && depBar.indexOf('--ht-red') < depBar.indexOf('--ht-grey') && /class="ht-sbar ht-depbar"/.test(depBar), '15b orange, red, grey in order, on the page\'s .ht-sbar');
ok(M.depBarHtml({ orange: 0, red: 0, grey: 0 }) === '', '15c nothing dependent → no bar (the panel says "none on this path")');

console.log('\nSCENARIO: ' + (FAIL ? 'FAIL' : 'PASS') + ' (' + PASS + '/' + (PASS + FAIL) + ')');
process.exit(FAIL ? 1 : 0);
