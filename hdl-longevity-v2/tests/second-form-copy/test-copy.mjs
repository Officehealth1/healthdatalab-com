#!/usr/bin/env node
/**
 * Second form, Matthew's wording (v0.48.48: W21, W25, W35, W37, W38, W42), outside any flag.
 * Reads the REAL TAP_TITLES / TEST_HOW from assets/js/hdlv2-staged-form.js in vm, renders screen 1
 * with the REAL renderTapStep(), and checks the HTML-built lines (banners, picks line, thank-you lead)
 * in the source. Every new string present, every old one gone.
 * tests/question-motion/test-form-motion.mjs proves the tap screens moved by these strings only.
 * Run: node tests/second-form-copy/test-copy.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };

const js = fs.readFileSync(new URL('../../assets/js/hdlv2-staged-form.js', import.meta.url), 'utf8');
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const fn = (name) => cut('  function ' + name + '(', '\n  }\n');
const has = (s) => js.indexOf(s) !== -1;

const ctx = {
  window: { hdlv2_form: {} }, document: { getElementById: () => null, getElementsByTagName: () => [] },
  esc: (s) => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
  setRootClasses() {}, tapLeave() {}, bindTapRows() {}, brandBar: () => '', saveIndicator: () => '', footer: () => '',
  serverData: {}, tapFirstRows: () => '[rows]', tapFirstValues: () => ({}), root: { innerHTML: '' }, formData: {}, tapStep: 0, tapOrder: [],
};
ctx.CFG = ctx.window.hdlv2_form;
vm.runInNewContext([cut('  var TEST_HOW = {', '\n  };\n'), cut('  var TAP_NEUTRAL = ', ';\n'), cut('  var TAP_TITLES = {', '\n  };\n'),
  cut('  var TAP_STEPS = ', ';\n'), cut('  var S1_IMG_BASE = ', '\n  })();\n'), fn('tapHeading'), fn('renderTapStep')].join('\n'), ctx);
const T = ctx.TAP_TITLES || {}, HOW = ctx.TEST_HOW || {};

console.log('── W35 brain: a challenging task, no reading ──');
ok(T.cognitiveActivity && T.cognitiveActivity.title === 'How often do you give your brain a challenging task?', 'C1.1 title');
ok(T.cognitiveActivity && T.cognitiveActivity.hint === 'e.g. learning a new skill, puzzles, complex problem-solving at work.', 'C1.2 hint, reading removed');
ok(!has('something new to work on') && !has('e.g. reading, learning new skills'), 'C1.3 the old title and hint are gone');

console.log('── W38 supplements: the habit, not the brand ──');
ok(T.supplementIntake && T.supplementIntake.plain === 'It doesn’t matter which ones. We’re looking at your habit of giving your body extra support.', 'C2.1 plain line');
ok(!has('Tells your practitioner what you already take'), 'C2.2 the old line is gone');

console.log('── W37 skin pinch: the hydration line ──');
const OLD_SKIN = 'Gently pinch the skin on the back of your hand for 2 seconds, release, and time how long it takes to flatten back. Use a stopwatch.';
ok(HOW.skinElasticity === OLD_SKIN + ' If you are dehydrated this will be slower, so do the test well hydrated.', 'C3.1 how-to keeps its text and ends with the hydration sentence');
ok(T.skinElasticity && T.skinElasticity.how === HOW.skinElasticity, 'C3.2 the tap screen shows the same how-to');

console.log('── W25 the picks line ──');
ok(has("'<p>For the life you picked, these are the abilities to keep in surplus today. Everyone ages; the surplus is what you build now.</p>'"), 'C4.1 the surplus line under the heading');
ok(!has('Each thing you picked needs some of these.'), 'C4.2 the old line is gone');
ok(has("'<h3>What your picks need from your body</h3>'"), 'C4.3 the heading is unchanged');

console.log('── W21 no "Stage 2 / Stage 3" on the client banners ──');
ok(has("'<div class=\"hdlv2-ty-banner\"><h1>Full&nbsp;Report&nbsp;·&nbsp;Your&nbsp;WHY</h1></div>'"), 'C5.1 WHY banner');
ok(has("'<div class=\"hdlv2-ty-banner\"><h1>Full&nbsp;Report&nbsp;complete</h1></div>'"), 'C5.2 thank-you banner');
ok(!/hdlv2-ty-banner"><h1>Stage/.test(js), 'C5.3 no banner says Stage');

console.log('── W42 the draft, said at the end and at the start ──');
ok(has("'<p class=\"hdlv2-ty-lead\">From what you’ve told us we’ve built your draft longevity report. It’s on its way to your email and should arrive within a few minutes.</p>'"), 'C6.1 thank-you lead');
ok(!has('We’ve just sent your draft report to your email.'), 'C6.2 the old lead is gone');
let s0 = '';
try { vm.runInNewContext('renderTapStep({ stage1_data: {} }, false); this.OUT = root.innerHTML;', ctx); s0 = ctx.OUT || ''; } catch (e) { console.log('  (render error: ' + e.message + ')'); }
ok(s0.indexOf('<p class="hdlv2-s1-prompt">We filled in what we could from your first answers. Pick any row marked Unknown, and tap Change if one is not right.</p>'
  + '<p class="hdlv2-s1-prompt">Your answers here build your draft longevity report.</p><div id="hdlv2-tap-rows"') !== -1,
  'C6.3 screen 1: the prompt, then the draft line, above the rows');
// v0.48.49 (T13-fix): the prompt is true with zero, one or two rows left to answer.
ok(!has('We filled these in from your first answers. If one is not right, tap Change.'), 'C6.4 the old prompt is gone');

console.log(`PASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
