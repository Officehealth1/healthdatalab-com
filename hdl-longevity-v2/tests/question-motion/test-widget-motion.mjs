#!/usr/bin/env node
/**
 * Question motion (v0.48.42), widget: the REAL renderMCQ() and style injectors of
 * widget/hdl-lead-magnet.js, run in vm.
 *   - flag off: Q3-Q9 render the 310d61d strings (sha256 fixtures taken before the change),
 *     and the editorial style block is the 310d61d function, unchanged.
 *   - flag on: one <figure class="hdlw-q-motion"> between the rule and the prompt, the question's
 *     file and caption; nothing else in the card moves; Q1 and Q2 never get one. The picture is a
 *     <picture> whose reduced-motion source is file.svg#still: an SVG inside <img> does not see the
 *     page's motion setting in Chrome (DESIGN.md, Motion, Reduced motion).
 *   - Q5 (T6d option B, v0.48.43): no picture at the top; a still pose on each answer, between the
 *     number and the text, from OPTION_POSES; nothing else in the card moves.
 *   - a question whose scene is not drawn yet (no file in assets/images/motion/) shows nothing:
 *     its card stays the 310d61d string even with the flag on (Round 1b).
 * Run: node tests/question-motion/test-widget-motion.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';
import crypto from 'node:crypto';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const sha = (s) => crypto.createHash('sha256').update(s).digest('hex');

const js = fs.readFileSync(new URL('../../widget/hdl-lead-magnet.js', import.meta.url), 'utf8');
/** The source from `head` to the first `end` after it (inclusive), or '' when `head` is absent. */
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };

const BASE = 'https://example.test/wp-content/plugins/hdl-longevity-v2/';
const STEP = { q3: 2, q4: 3, q5: 4, q6: 5, q7: 6, q8: 7, q9: 8 };
const esc = (s) => String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const src = cut('  var QUESTIONS = {', '\n  };\n') + cut('  var QUESTION_MOTION = {', '\n  };\n') + cut('  var OPTION_POSES = {', '\n  };\n') + cut('    function renderMCQ(', '\n    }\n');

function render(qKey, motion) {
  const ctx = { contentEl: { innerHTML: '', querySelectorAll: () => [] }, answers: {}, id: 'hdlw-122', advance() {}, escHtml: esc,
    IMG_BASE: BASE + 'assets/images/silhouettes/', cfg: { questionMotion: motion, assetVer: '0.48.43' } };
  vm.runInNewContext(src + `\nrenderMCQ(${JSON.stringify(qKey)}, ${STEP[qKey]});\nthis.MOTION = typeof QUESTION_MOTION === 'undefined' ? null : QUESTION_MOTION;\nthis.POSES = typeof OPTION_POSES === 'undefined' ? null : OPTION_POSES;`, ctx);
  return { html: ctx.contentEl.innerHTML, motion: ctx.MOTION, poses: ctx.POSES };
}

// The 310d61d output of renderMCQ for each question with these stubs (captured before the change).
const OFF = {
  q3: '39f5cc42b2863316b65c3d39a28f4fdfad0b281523b1b306603aa31f2fbe36fd', q4: 'a5c1e146970449c083e9e17b7ee5dcf6bb68aad9ed52ce0930c5210c9a36cba9', q5: '4e136ad6a7c5d76caa1e6c852cc700635c491ee59344e897ca0243e310c115da', q6: '1cb37b1ae39ec545ec336364f8e2efbdc9dbfeed952efc6ac9430a8048242e78', q7: 'a52b342302117acb76d9947dab32064d31c9fba2f8565b3f8bbe1bd5803a4e0a', q8: 'd7d7c5278e86d248e26422ccb49ea162d6bfbf33a534b6bf460c62d032d2e794', q9: '95e1de36cbe65651ea1804765a05df3c45c45390f8e8e1bdab78a91512d41aff'
};
const WANT = { q3: ['q3-zone2.svg', 'Brisk walking'], q4: ['q4-stairs.svg', 'Climbing stairs'], q5: null, // T6d option B: poses on the answers, no picture at the top (checked below)
 
  q6: ['q6-sleep.svg', 'A night’s sleep'], q7: ['q7-smoking.svg', 'Smoking'], q8: ['q8-social.svg', 'Time together'], q9: ['q9-diet.svg', 'How you eat'] }; // Q9 caption: Quim, 2026-11-04 ("How you eat", the second form's own words)

console.log('── flag off: the 310d61d strings ──');
for (const q of Object.keys(STEP)) ok(sha(render(q, false).html) === OFF[q], `W1.${q} ${q} card unchanged with the flag off`);

console.log('── flag on: one figure between the rule and the prompt, for each drawn scene ──');
const drawn = (file) => fs.existsSync(new URL('../../assets/images/motion/' + file, import.meta.url));
const motion = render('q3', true).motion;
const want = Object.keys(STEP).filter((q) => WANT[q] && drawn(WANT[q][0]));
ok(!!motion && Object.keys(motion).join() === want.join() && want.every((q) => motion[q].file === WANT[q][0] && motion[q].caption === WANT[q][1]),
  `W2.1 the map lists exactly the drawn scenes (${want.join(', ') || 'none'}), with their files and captions; never q1 or q2`);
for (const q of Object.keys(STEP)) {
  if (!WANT[q]) continue;
  const { html } = render(q, true);
  const [file, word] = WANT[q];
  if (!drawn(file)) { ok(sha(html) === OFF[q], `W7.${q} ${q}: not drawn yet, so no picture and the card unchanged`); continue; }
  const fig = html.match(/<figure class="hdlw-q-motion">.*?<\/figure>/);
  const url = `${BASE}assets/images/motion/${file}?ver=0.48.43`;
  const pic = `<picture><source srcset="${url}&amp;still=1#still" media="(prefers-reduced-motion: reduce)"><img src="${url}" alt="" width="96" height="96" loading="eager" decoding="async"></picture>`;
  ok(!!fig && fig[0] === '<figure class="hdlw-q-motion">' + pic + '<figcaption>' + esc(word) + '</figcaption></figure>', `W2.${q} ${q}: ${file}, "${word}"`);
  ok(!!fig && html.indexOf('<div class="hdlw-q-rule"></div>' + fig[0] + '<p class="hdlw-q-prompt">') > 0, `W3.${q} ${q}: after the rule, before the prompt`);
  ok(!!fig && sha(html.replace(fig[0], '')) === OFF[q], `W4.${q} ${q}: nothing else in the card moved`);
}
console.log('── flag on, Q5: a still pose on each answer, no picture at the top (T6d option B) ──');
const POSE = { a: 'q5-floor-a.svg', b: 'q5-floor-b.svg', c: 'q5-floor-c.svg', d: 'q5-floor-d.svg', e: 'q5-floor-e.svg' };
const q5 = render('q5', true);
ok(JSON.stringify(q5.poses) === JSON.stringify({ q5: POSE }), 'P1 OPTION_POSES lists Q5 only, answers a to e, one file each');
const imgs = q5.html.match(/<img class="hdlw-opt-pose"[^>]*>/g) || [];
ok(imgs.length === 5 && Object.keys(POSE).every((v, i) => imgs[i] === `<img class="hdlw-opt-pose" src="${BASE}assets/images/motion/${POSE[v]}?ver=0.48.43" alt="" width="40" height="40" loading="eager" decoding="async">`),
  'P2 five poses in answer order, 40 px, decorative (alt="": the answer text says it)');
ok(Object.keys(POSE).every((v) => new RegExp(`<div class="hdlw-opt hdlw-opt-posed" data-val="${v}"><span class="hdlw-opt-num">0\\d</span><img class="hdlw-opt-pose"[^>]*><span class="hdlw-opt-text">`).test(q5.html)),
  'P3 each pose sits between the answer number and the answer text');
ok(!/hdlw-q-motion/.test(q5.html), 'P4 Q5 has no picture at the top');
ok(sha(q5.html.replace(/<img class="hdlw-opt-pose"[^>]*>/g, '').replace(/ hdlw-opt-posed/g, '')) === OFF.q5, 'P5 nothing else in the Q5 card moved');
ok(['q3', 'q4', 'q6', 'q7', 'q8', 'q9'].every((q) => !/hdlw-opt-pose/.test(render(q, true).html)), 'P6 no other question carries poses');
ok(!/hdlw-opt-pose/.test(render('q5', false).html), 'P7 flag off: no poses');
ok(!/hdlw-q-motion/.test(cut('    function renderQ1(', '\n    }\n') + cut('    function renderQ2(', '\n    }\n')), 'W5.1 Q1 and Q2 renderers carry no picture');

console.log('── styles: the editorial block unchanged; the picture rules only when the flag is on ──');
const editorial = cut('  function injectEditorialStyles() {', '\n  }\n');
ok(sha(editorial) === '0847f730280e62fc3f56e0e78364f889dd7372350c6ed9755c449bb7a1561350', 'W6.1 injectEditorialStyles is the 310d61d function, byte for byte');
const motionCss = cut('  function injectMotionStyles() {', '\n  }\n');
ok(/\.hdlw-q-motion img\{width:96px;height:96px;border-radius:50%;background:#f8f9fb;display:block\}/.test(motionCss)
  && /@media \(max-width:480px\)\{\.hdlw-q-motion\{margin:0 0 16px\}\.hdlw-q-motion img\{width:80px;height:80px\}/.test(motionCss), 'W6.2 injectMotionStyles: 96 px circle, 80 px under 480 px');
ok(/\.hdlw-q-motion\{margin:0 0 20px;padding-top:4px;display:flex;flex-direction:column;align-items:flex-start;gap:8px\}/.test(motionCss),
  'W6.7 T6a air: 4 px more under the rule (padding: a top margin would merge with the rule\'s 14 px), 20 px under the caption (16 px under 480 px), 8 px picture to caption');
ok(/\.hdlw-q-motion figcaption\{font-family:' \+ B\.body \+ ';font-size:13px;line-height:1\.3;color:' \+ B\.muted \+ ';margin:0\}/.test(motionCss), 'W6.3 the caption in the hint\'s two tokens (B.body, B.muted)');
ok(/\.hdlw-opt\.hdlw-opt-posed\{grid-template-columns:26px 40px 1fr;align-items:center\}/.test(motionCss) && /\.hdlw-opt-pose\{width:40px;height:40px;border-radius:50%;background:#f8f9fb;display:block\}/.test(motionCss)
  && /\.hdlw-opt\.hdlw-opt-posed\{grid-template-columns:26px 36px 1fr\}\.hdlw-opt-pose\{width:36px;height:36px\}\}';/.test(motionCss), 'W6.8 Q5 poses: 40 px column (36 px under 480 px), two classes so the editorial grid never wins');
ok(/if \(cfg\.questionMotion\) injectMotionStyles\(\);/.test(js) && (js.match(/injectMotionStyles\(\)/g) || []).length === 2, 'W6.4 injectMotionStyles is called once, only when the flag is on');
ok(/cfg\.questionMotion = !!\(\(publicCfg && publicCfg\.question_motion_enabled\) \|\| \(invite && invite\.question_motion_enabled\)\);/.test(js), 'W6.5 the flag reaches the widget from the public config or the invite payload');
ok(/cfg\.assetVer = String\(\(publicCfg && publicCfg\.asset_ver\) \|\| \(invite && invite\.asset_ver\) \|\| ''\);/.test(js), 'W6.6 the asset version rides the same two payloads');

console.log(`PASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
