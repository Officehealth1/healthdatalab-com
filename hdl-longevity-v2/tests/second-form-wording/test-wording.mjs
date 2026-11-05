#!/usr/bin/env node
/**
 * v0.48.50 (W33, W36, W37, W39, W40) — the second form's five wordings behind
 * hdlv2_ff_second_form_wording_v2, from the research note (evidence/2026-11-04_second_form_test_wording_research.md):
 *   - flag on: balance, breath-hold (option A, the control pause; its answers stay) and skin-pinch
 *     how-to texts; the sun question and its six answers (shown A-F, scored D 5, C 4, E 3, F 2, B 1, A 0);
 *     the water question in glasses and its six answers (0-5 in order);
 *   - flag off: S3_OPTIONS, TEST_HOW, TAP_TITLES equal b462b02's, and tapCards() draws b462b02's
 *     cards for every field (the chip letter now follows the card's place, which is the value
 *     everywhere except the new sun set).
 * The REAL top of assets/js/hdlv2-staged-form.js runs in vm with CFG set.
 * Run: node tests/second-form-wording/test-wording.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const J = JSON.stringify;
const js = fs.readFileSync(new URL('../../assets/js/hdlv2-staged-form.js', import.meta.url), 'utf8');
const old = execFileSync('git', ['show', 'b462b02:hdl-longevity-v2/assets/js/hdlv2-staged-form.js'], { cwd: fileURLToPath(new URL('.', import.meta.url)), encoding: 'utf8', maxBuffer: 8 << 20 });
const cut = (text, head, end) => { const at = text.indexOf(head); return at < 0 ? '' : text.slice(at, text.indexOf(end, at) + end.length); };
const ESC = "function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\"/g, '&quot;'); }\n";

/** The maps as the page builds them, with this CFG; tapCards too. */
function load(text, cfg) {
  const src = cut(text, '  var S3_OPTIONS = {', '\n  };\n') + cut(text, '  var TEST_HOW = {', '\n  };\n')
    + cut(text, '  var TAP_NEUTRAL = ', ';\n') + cut(text, '  var TAP_TITLES = {', '\n  };\n')
    + cut(text, '  var WORDING_V2 = {', '\n  };\n') + cut(text, '  if (CFG.second_form_wording_v2) {', '\n  }\n')
    + cut(text, '  function tapCards(', '\n  }\n');
  const ctx = { CFG: cfg };
  vm.runInNewContext(ESC + src + '\nthis.m = { S3_OPTIONS, TEST_HOW, TAP_TITLES, tapCards };', ctx);
  return ctx.m;
}
const was = load(old, {});
const off = load(js, {});
const on = load(js, { second_form_wording_v2: true });

ok(J(off.S3_OPTIONS) === J(was.S3_OPTIONS) && J(off.TEST_HOW) === J(was.TEST_HOW) && J(off.TAP_TITLES) === J(was.TAP_TITLES), 'flag off: S3_OPTIONS, TEST_HOW and TAP_TITLES are b462b02\'s');
const fields = Object.keys(was.S3_OPTIONS);
ok(fields.every((f) => ['', '0', '3', '5'].every((v) => off.tapCards(f, v) === was.tapCards(f, v))), 'flag off: tapCards() draws b462b02\'s cards for all ' + fields.length + ' fields');

const HOW = {
  balance: 'Stand on one leg with your arms by your sides and the other foot lifted off the floor. Start the stopwatch when your foot lifts. Wobbling is fine. Stop the clock when the lifted foot touches the floor, your standing foot moves, or you hold on to something. Take the best of three tries. Stand near something for safety.',
  breathHold: 'Sit and breathe normally for a minute. Breathe out normally, pinch your nose and start the stopwatch. Stop at the first clear urge to breathe, like a swallow or a twitch in your throat or tummy. Your next breath should be calm. If you gasp, you held too long.',
  skinElasticity: 'Rest your hand flat on a table. Pinch the skin on the back of your hand for 2 seconds, then let go and start the stopwatch. Stop when the ridge has gone and the skin looks flat again. Ignore a faint line that stays. If you are dehydrated this will be slower, so do the test well hydrated.'
};
Object.keys(HOW).forEach((k) => ok(on.TEST_HOW[k] === HOW[k] && on.TAP_TITLES[k].how === HOW[k], 'flag on: ' + k + ' how-to is the research note\'s'));
ok(on.TEST_HOW.sitToStand === was.TEST_HOW.sitToStand, 'flag on: the chair-stand how-to stays');
['breathHold', 'balance', 'skinElasticity'].forEach((k) => ok(J(on.S3_OPTIONS[k]) === J(was.S3_OPTIONS[k]) && on.TAP_TITLES[k].title === was.TAP_TITLES[k].title, 'flag on: ' + k + ' title and answers stay'));

ok(on.TAP_TITLES.sunlightExposure.title === 'How often do you spend time outdoors in daylight?', 'flag on: the sun question');
const SUN = [['0', "Hardly ever, I'm indoors most days"], ['1', 'Now and then, not most days'], ['4', 'Most days, but less than 20 minutes'],
  ['5', 'Most days, 20 minutes or more, and I rarely burn'], ['3', 'Most days, and I burned once in the past year'], ['2', 'Most days, and I burned two or more times in the past year']];
ok(J(on.S3_OPTIONS.sunlightExposure) === J([{ v: '', l: 'Select...' }].concat(SUN.map(([v, l]) => ({ v, l })))), 'flag on: the six sun answers, shown A-F, scored D 5, C 4, E 3, F 2, B 1, A 0');
const sunCards = on.tapCards('sunlightExposure', '5');
ok(J([...sunCards.matchAll(/hdlv2-s1-chip" aria-hidden="true">(.)<\/span><span class="hdlv2-s1-opt-text">([^<]*)</g)].map((m) => m[1] + ' ' + m[2]))
  === J(SUN.map(([, l], i) => String.fromCharCode(65 + i) + ' ' + l)), 'flag on: the sun cards read A to F in that order');
ok(/class="hdlv2-s1-opt selected" data-val="5"[^>]*>[\s\S]*?>D<\/span>/.test(sunCards), 'flag on: a saved 5 is card D (20 minutes or more, rarely burn)');
ok(on.TAP_TITLES.dailyHydration.title === 'On a usual day, how many glasses of water do you drink? A glass is about a cup.', 'flag on: the water question');
ok(J(on.S3_OPTIONS.dailyHydration) === J([{ v: '', l: 'Select...' }, { v: '0', l: 'None or one' }, { v: '1', l: '2 to 3' }, { v: '2', l: '4 to 5' }, { v: '3', l: '6 to 7' }, { v: '4', l: '8 to 9' }, { v: '5', l: '10 or more' }]), 'flag on: the six water answers, 0-5 in order');
const rest = (m) => J(Object.fromEntries(Object.entries(m.S3_OPTIONS).filter(([k]) => !['sunlightExposure', 'dailyHydration'].includes(k))));
ok(rest(on) === rest(was), 'flag on: every other answer set stays');

// The old wizard reads the same maps (fieldSelect prefers S3_OPTIONS[name]; its tooltips are TEST_HOW).
ok(/var optList = \( S3_OPTIONS && S3_OPTIONS\[ name \] \) \? S3_OPTIONS\[ name \] : options;/.test(js), 'the old wizard\'s fieldSelect reads S3_OPTIONS');
const sf = fs.readFileSync(new URL('../../includes/sprint-2/class-hdlv2-staged-form.php', import.meta.url), 'utf8');
ok(/'second_form_wording_v2'\s*=>\s*\(bool\) get_option\( 'hdlv2_ff_second_form_wording_v2', false \)/.test(sf), 'the page gets second_form_wording_v2 from the option');

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
