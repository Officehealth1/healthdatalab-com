#!/usr/bin/env node
/**
 * Healthspan Timeline model (v0.48.29; icons + dependence bar v0.48.30). Runs the REAL model functions from
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

// v0.48.30: itshover icons only, geometry copied 1:1 from github.com/itshover/itshover (icons/*.tsx).
const SRC = fs.readFileSync(file, 'utf8');
const CSS = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'css', 'hdlv2-healthspan-timeline.css'), 'utf8');
const ds = s => (String(s).match(/ d="[^"]+"/g) || []).map(x => x.slice(4, -1));
const sameSet = (a, b) => JSON.stringify(a.slice().sort()) === JSON.stringify(b.slice().sort());
const BATT = ['M46 20V28', 'M37 10H8C5.2386 10 3 12.2386 3 15V33C3 35.7614 5.2386 38 8 38H37C39.7614 38 42 35.7614 42 33V15C42 12.2386 39.7614 10 37 10Z'];
const BARS = ['M10 31V17', 'M17 31V17', 'M24 31V17', 'M31 31V17'];
const SOURCE = {
  'sparkles-icon': ['M16 18a2 2 0 0 1 2 2a2 2 0 0 1 2 -2a2 2 0 0 1 -2 -2a2 2 0 0 1 -2 2z', 'M16 6a2 2 0 0 1 2 2a2 2 0 0 1 2 -2a2 2 0 0 1 -2 -2a2 2 0 0 1 -2 2z', 'M9 18a6 6 0 0 1 6 -6a6 6 0 0 1 -6 -6a6 6 0 0 1 -6 6a6 6 0 0 1 6 6z'],
  'user-icon': ['M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0', 'M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2'],
  'users-group-icon': ['M10 13a2 2 0 1 0 4 0a2 2 0 0 0 -4 0', 'M8 21v-1a2 2 0 0 1 2 -2h4a2 2 0 0 1 2 2v1', 'M15 5a2 2 0 1 0 4 0a2 2 0 0 0 -4 0', 'M17 10h2a2 2 0 0 1 2 2v1', 'M5 5a2 2 0 1 0 4 0a2 2 0 0 0 -4 0', 'M3 13v-1a2 2 0 0 1 2 -2h2'],
  'lock-icon': ['M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6z', 'M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0', 'M8 11v-4a4 4 0 1 1 8 0v4']
};
ok(typeof M.iconHtml === 'function' && typeof M.depBarHtml === 'function', '14a iconHtml and depBarHtml exposed');
if (typeof M.iconHtml !== 'function' || typeof M.depBarHtml !== 'function') { console.log('\nSCENARIO: FAIL (' + PASS + '/' + (PASS + FAIL) + ')'); process.exit(1); }
const icon = k => M.iconHtml(k, '#c93437');
const named = (k, n) => new RegExp('^<svg[^>]* data-ht-icon="' + n + '"').test(icon(k));
ok(['poor', 'average', 'healthy', 'optimal', 'user', 'group', 'lock'].every(k => /aria-hidden="true"/.test(icon(k)) && /focusable="false"/.test(icon(k))), '14b every icon aria-hidden, not focusable');
ok(named('poor', 'battery-charging-icon') && sameSet(ds(icon('poor')), BATT), '14c Poor = battery-charging-icon at rest (empty)');
ok(named('average', 'battery-pause-icon') && sameSet(ds(icon('average')), BARS.slice(0, 2).concat(BATT)), '14d Average = battery-pause-icon at rest (two of four bars)');
ok(named('healthy', 'battery-charging-icon') && sameSet(ds(icon('healthy')), BARS.concat(BATT)), '14e Healthy = battery-charging-icon at the end of its fill (four bars)');
ok(named('optimal', 'sparkles-icon') && sameSet(ds(icon('optimal')), SOURCE['sparkles-icon']), '14f Optimal = sparkles-icon, geometry 1:1');
ok(named('user', 'user-icon') && sameSet(ds(icon('user')), SOURCE['user-icon']), '14g capability = user-icon, geometry 1:1');
ok(named('group', 'users-group-icon') && sameSet(ds(icon('group')), SOURCE['users-group-icon']), '14h dependence = users-group-icon, geometry 1:1');
ok(named('lock', 'lock-icon') && sameSet(ds(icon('lock')), SOURCE['lock-icon']), '14i lock bands = lock-icon, geometry 1:1');
ok(/ stroke="#c93437"/.test(icon('poor')) && / stroke="currentColor"/.test(M.iconHtml('lock')) && M.iconHtml('nope') === '', '14j colour given or currentColor; unknown key → nothing');
ok(JSON.stringify(K.PROFILES.map(p => p.icon)) === JSON.stringify(['poor', 'average', 'healthy', 'optimal']), '14k the four presets map to the four icons');
ok(!/function figure\(|stageFig|ICON\[|stroke-width="2\.6"/.test(SRC) && !/ht-figrow/.test(CSS), '14l figure(), stageFig(), the hand-drawn icons and .ht-figrow are gone');
ok(!/border-left/.test(CSS), '14m no border-left in the timeline styles (no accent rails)');

// The dependence bar: one stacked bar in orange, red, grey; the segments are the whole bar.
const depBar = M.depBarHtml({ orange: 6.8, red: 3.5, grey: 1.1 });
const w = [...depBar.matchAll(/--w:([\d.]+)%/g)].map(m => +m[1]);
ok(w.length === 3 && Math.abs(w.reduce((a, b) => a + b, 0) - 100) < 0.1, '15a three segments that add to 100% (' + w.join(' + ') + ')');
ok(depBar.indexOf('--ht-orange') < depBar.indexOf('--ht-red') && depBar.indexOf('--ht-red') < depBar.indexOf('--ht-grey') && /class="ht-sbar ht-depbar"/.test(depBar), '15b orange, red, grey in order, on the page\'s .ht-sbar');
ok(M.depBarHtml({ orange: 0, red: 0, grey: 0 }) === '', '15c nothing dependent → no bar (the panel says "none on this path")');

console.log('\nSCENARIO: ' + (FAIL ? 'FAIL' : 'PASS') + ' (' + PASS + '/' + (PASS + FAIL) + ')');
process.exit(FAIL ? 1 : 0);
