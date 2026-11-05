#!/usr/bin/env node
/**
 * Question motion (v0.48.42), second form: the REAL renderTapStep(), tapFirstRows() and the body
 * block of wizardSectionContent() from assets/js/hdlv2-staged-form.js, run in vm with stubbed helpers.
 *   - flag off (hdlv2_form.question_motion absent): the 310d61d strings (sha256 fixtures).
 *   - flag on: balance, chair-stand and skin-pinch screens carry their picture before "How to do it";
 *     the body block carries the waist/hip figure above the waist and hip fields; each of the six
 *     screen-0 rows starts its label with its Phosphor icon. Nothing else moves. A moving picture is a
 *     <picture> whose reduced-motion source is file.svg#still (DESIGN.md, Motion, Reduced motion).
 *   - a picture whose scene is not drawn yet (no file in assets/images/motion/) shows nothing (Round 1b).
 * Run: node tests/question-motion/test-form-motion.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';
import crypto from 'node:crypto';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const sha = (s) => crypto.createHash('sha256').update(s).digest('hex');

const js = fs.readFileSync(new URL('../../assets/js/hdlv2-staged-form.js', import.meta.url), 'utf8');
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const fn = (name) => cut('  function ' + name + '(', '\n  }\n');

const BASE = 'https://example.test/wp-content/plugins/hdl-longevity-v2/';
const src = [cut('  var S3_OPTIONS = {', '\n  };\n'), cut('  var TEST_HOW = {', '\n  };\n'), cut('  var TAP_STEPS = ', ';\n'),
  cut('  var TAP_FIRST = ', ';\n'), cut('  var TAP_FIRST_LABELS = {', '\n  };\n'), cut('  var TAP_ORDER = ', ';\n'), cut('  var TAP_NEUTRAL = ', ';\n'),
  cut('  var TAP_TITLES = {', '\n  };\n'), cut('  var S1_IMG_BASE = ', '\n  })();\n'), cut('  var TAP_MOTION = {', '\n  };\n'),
  cut('  var ICONS = {', '\n  };\n'), cut('  var ROW_ICONS = {', '\n  };\n'), cut('  function motionFigure(', '\n  }\n'),
  fn('tapHeading'), fn('tapReason'), fn('tapFirstRows'), fn('renderTapStep'), fn('wizardSectionContent')].join('\n');

function run(motion, call) {
  const ctx = {
    window: { hdlv2_form: motion === null ? {} : { plugin_url: BASE, question_motion: motion, asset_ver: '0.48.49' } },
    document: { getElementById: () => null, getElementsByTagName: () => [] },
    esc: (s) => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
    setRootClasses() {}, tapLeave() {}, bindTapRows() {}, bindTapQuestion() {}, bindFieldListeners() {}, bindTapComplete() {},
    brandBar: () => '[brand]', saveIndicator: () => '[save]', footer: () => '[footer]', serverData: {}, ABILITY_SENTENCES: {},
    tapCards: (f, v) => `[cards:${f}:${v}]`, tapFirstValues: () => VALS, tapSection: (id) => ({ id }),
    fieldReadonly: (l, v, u) => `[ro:${l}:${v}:${u}]`, prefillHint: () => '[hint]',
    fieldInputWithSkip: (n, l, t, v, p, o) => `[in:${n}:${l}:${v}:${p}:${o ? o.tooltip : ''}]`,
    root: { innerHTML: '' }, formData: { waist: '84', hip: '' }, tapOpenRow: null, tapStep: 0, tapOrder: [],
  };
  ctx.CFG = ctx.window.hdlv2_form;
  vm.runInNewContext(src + '\ntapOrder = TAP_ORDER;\n' + call, ctx);
  return ctx;
}
const VALS = { physicalActivity: '2', sleepDuration: '4', sleepQuality: '', smokingStatus: '5', socialConnections: '3', dietQuality: '' };
const step = (motion, n) => run(motion, `wizardSectionContent = function (sec) { return '[section:' + sec.id + ']'; }; tapStep = ${n}; renderTapStep({ stage1_data: { q1_age: 52, q1_sex: 'female' }, second_form_ties: {} }, false); this.OUT = root.innerHTML;`).OUT;
const rows = (motion) => run(motion, 'this.OUT = tapFirstRows(VALS_);'.replace('VALS_', JSON.stringify(VALS))).OUT;
const body = (motion) => run(motion, `this.OUT = wizardSectionContent({ id: 'body' }, { q1_age: 52, q1_sex: 'female' }, formData);`).OUT;

// 310d61d outputs (captured before the change): tap steps 0-12, the screen-0 rows, the body block.
const OFF = { steps: 'c482278ae0d07b6a99a51768021c6354f34091f7e9664f69239e271e41be2867', rows: 'b874c38b5e4628fe84b6f4472104afed796cdd673ce8bcaa60191fd2b4398461', body: 'e376b1924acf7100c67a338621cb746ecfeb7d553fa889fe1c8de8799ae9cf59' };

// v0.48.49 (PR 2): Matthew's wording is for everyone, outside the flag. Put back, it must give the
// 310d61d bytes again, so nothing else on these screens moved. [new, 310d61d] as rendered.
const PR2 = [
  ['<p class="hdlv2-s1-prompt">Your answers here build your draft longevity report.</p>', ''],
  ['How often do you give your brain a challenging task?', 'How often do you give your brain something new to work on?'],
  ['e.g. learning a new skill, puzzles, complex problem-solving at work.', 'e.g. reading, learning new skills, puzzles, problem-solving at work.'],
  ['It doesn’t matter which ones. We’re looking at your habit of giving your body extra support.', 'Tells your practitioner what you already take.'],
  [' If you are dehydrated this will be slower, so do the test well hydrated.', ''],
  ['Unknown — please select', 'Not answered yet'],
  ['How well you sleep · sleep quality', 'How well you sleep'],
  ['We filled in what we could from your first answers. Pick any row marked Unknown, and tap Change if one is not right.', 'We filled these in from your first answers. If one is not right, tap Change.'],
];
const asBefore = (s) => PR2.reduce((t, [now, was]) => t.split(now).join(was), s);

console.log('── flag off (absent, then false): the 310d61d strings, PR 2 wording aside ──');
for (const m of [null, false]) {
  const label = m === null ? 'absent' : 'false';
  const all = Array.from({ length: 13 }, (_, n) => step(m, n)).join('\n'), r0 = rows(m);
  ok(PR2.every(([now]) => (all + r0).includes(now)), `F0.${label} every PR 2 string is on these screens`);
  ok(sha(asBefore(all)) === OFF.steps, `F1.${label} all thirteen tap screens unchanged`);
  ok(sha(asBefore(r0)) === OFF.rows, `F2.${label} screen-0 rows unchanged`);
  ok(sha(body(m)) === OFF.body, `F3.${label} the measurements block unchanged`);
}

console.log('── flag on: the three tests show how (each once its scene is drawn) ──');
const drawn = (file) => fs.existsSync(new URL('../../assets/images/motion/' + file, import.meta.url));
const fig = (file, word) => { const url = `${BASE}assets/images/motion/${file}?ver=0.48.49`;
  return `<figure class="hdlv2-tap-motion"><picture><source srcset="${url}&amp;still=1#still" media="(prefers-reduced-motion: reduce)"><img src="${url}" alt="" width="96" height="96" loading="eager" decoding="async"></picture><figcaption>${word}</figcaption></figure>`; };
const FILE = { sitToStand: 't-chair-stands.svg', balance: 't-balance.svg', skinElasticity: 't-skin-pinch.svg' };
const WANT = { sitToStand: fig(FILE.sitToStand, 'Chair stands'), balance: fig(FILE.balance, 'One leg'), skinElasticity: fig(FILE.skinElasticity, 'Pinch, release') };
const order = run(false, 'this.OUT = TAP_ORDER;').OUT;
const offSteps = Array.from({ length: 13 }, (_, n) => step(false, n));
for (let n = 0; n < 13; n++) {
  const on = step(true, n), f = order[n - 1];
  if (WANT[f] && !drawn(FILE[f])) {
    ok(on === offSteps[n], `F12.${f} not drawn yet, so no picture and the screen unchanged`);
  } else if (WANT[f]) {
    ok(on.includes(WANT[f] + '<p class="hdlv2-tap-how">'), `F4.${f} its picture and caption, right before "How to do it"`);
    ok(on.includes(WANT[f]) && on.replace(WANT[f], '') === offSteps[n], `F5.${f} nothing else on the screen moved`);
  } else if (n !== 0) {
    ok(on === offSteps[n], `F6.${n} ${f || (n === 11 ? 'measurements' : 'background')} screen unchanged (no picture)`);
  }
}

console.log('── flag on: the waist and hip figure ──');
const guide = `<figure class="hdlv2-body-guide"><img src="${BASE}assets/images/motion/g-waist-hip.svg?ver=0.48.49" alt="" width="96" height="96" loading="eager" decoding="async"><figcaption>Waist \u00b7 Hip</figcaption></figure>`;
const b = body(true);
if (!drawn('g-waist-hip.svg')) ok(b === body(false), 'F13 the waist and hip figure is not drawn yet, so the block is unchanged');
else ok(b.includes('[in:weight:Weight (kg):undefined:75:]</div>' + guide + '<div class="hdlv2-field-grid cols-2">[in:waist:'), 'F7.1 the figure sits between height/weight and the waist/hip fields');
if (drawn('g-waist-hip.svg')) ok(b.includes(guide) && b.replace(guide, '') === body(false), 'F7.2 nothing else in the block moved');

console.log('── flag on: an icon on each screen-0 row ──');
const r = rows(true), icons = run(true, "this.OUT = typeof ROW_ICONS === 'undefined' ? null : ROW_ICONS;").OUT || {};
const order0 = ['physicalActivity', 'sleepDuration', 'sleepQuality', 'smokingStatus', 'socialConnections', 'dietQuality'];
ok(Object.keys(icons).join() === order0.join(), 'F8.1 ROW_ICONS has the six rows, in order');
for (const f of order0) {
  const svg = '<svg class="hdlv2-tap-row-icon" aria-hidden="true" viewBox="0 0 256 256">' + (icons[f] || '\u0000') + '</svg>';
  ok(typeof icons[f] === 'string' && /^<path d="[^"]+" opacity="0\.2"\/><path d="[^"]+"\/>$/.test(icons[f]), `F8.${f} duotone Phosphor paths (soft layer at 0.2, then the line)`);
  ok(r.includes('<span class="hdlv2-tap-row-label" id="hdlv2-tap-label-' + f + '">' + svg), `F9.${f} the label starts with its icon`);
}
ok(r.includes('hdlv2-tap-row-icon') && r.replace(/<svg class="hdlv2-tap-row-icon"[^>]*>.*?<\/svg>/g, '') === rows(false), 'F10 nothing else in the rows moved');
ok(icons.physicalActivity === run(true, 'this.OUT = ICONS.mobility;').OUT && icons.socialConnections === run(true, 'this.OUT = ICONS.connection;').OUT,
  'F11 the walking and people icons are the ones the choices page already uses');

console.log('── T6a air: the picture rules in hdlv2-form.css ──');
const css = fs.readFileSync(new URL('../../assets/css/hdlv2-form.css', import.meta.url), 'utf8');
ok(/\.hdlv2-tap-motion,\s*\.hdlv2-body-guide \{ margin: 0 0 16px; display: flex; flex-direction: column; align-items: flex-start; gap: 8px; \}/.test(css),
  'F14.1 phones: 16 px under the caption, 8 px picture to caption');
ok(/@media \(min-width: 640px\) \{\s*\.hdlv2-tap-motion \{ float: left; margin: 0 20px 16px 0; \}/.test(css), 'F14.2 640 px and wider: 20 px beside the how-box, 16 px under the caption');
ok(/\.hdlv2-tap-motion \+ \.hdlv2-tap-how \+ \.hdlv2-s1-options \{ clear: both; \}/.test(css), 'F14.3 the answers start below the picture and its caption');

console.log(`PASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
