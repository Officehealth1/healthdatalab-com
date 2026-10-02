#!/usr/bin/env node
/**
 * Single-form WHY picks page (v0.47.92, abilities v0.47.93) — the client side.
 *
 * Extracts and runs the REAL togglePick() / canContinue() and, from v0.47.93,
 * themesWithPicks() / toggleTheme() / abilityCounts() from
 * assets/js/hdlv2-staged-form.js (same approach as tests/paid-stage1), then
 * checks the routing and the new page's wiring in the shipped file.
 *
 * Run:  node tests/single-form/test-single-form.js
 * Exit: 0 all pass · 1 any fail
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const crypto = require('crypto');

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'hdlv2-staged-form.js'), 'utf8');

function body(name) {
  const m = src.match(new RegExp('  function ' + name + '\\([^)]*\\) \\{[\\s\\S]*?\\n  \\}'));
  return m ? m[0] : '';
}
const limits = (src.match(/  var PICK_MIN = \d+, PICK_MAX = \d+;/) || [''])[0];
ok(limits !== '', 'PICK_MIN / PICK_MAX declared');
const ctx = {};
try {
  vm.runInNewContext(limits + '\n' + body('togglePick') + '\n' + body('canContinue') + '\nthis.togglePick = togglePick; this.canContinue = canContinue;', ctx);
} catch (e) { /* missing functions: reported below */ }
const { togglePick, canContinue } = ctx;
ok(typeof togglePick === 'function' && typeof canContinue === 'function', 'togglePick() and canContinue() exist');

if (typeof togglePick === 'function') {
  let r = togglePick([], 'a');
  ok(JSON.stringify(r.picks) === '["a"]' && r.atLimit === false, 'toggle adds an id');
  r = togglePick(r.picks, 'a');
  ok(r.picks.length === 0, 'second toggle removes it');
  const ten = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'];
  r = togglePick(ten, 'k');
  ok(r.picks.length === 10 && r.picks.indexOf('k') === -1 && r.atLimit === true, 'an 11th pick is refused and reports "at the limit"');
  r = togglePick(ten, 'c');
  ok(r.picks.length === 9 && r.picks.indexOf('c') === -1, 'removing at the limit still works');
  const orig = ['a'];
  togglePick(orig, 'b');
  ok(orig.length === 1, 'toggle does not mutate its input');
}
if (typeof canContinue === 'function') {
  ok(canContinue(['a', 'b', 'c', 'd']) === false, 'canContinue false under 5');
  ok(canContinue(['a', 'b', 'c', 'd', 'e']) === true, 'canContinue true at 5');
  ok(canContinue(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j']) === true, 'canContinue true at 10');
  ok(canContinue(null) === false, 'canContinue false for no picks');
}

// ── Wiring in the shipped file ──
const load = body('loadForm');
ok(/currentStage === 2[\s\S]*data\.single_form[\s\S]*renderWhyPicks\(data\)/.test(load), 'loadForm routes a covered stage-2 row to renderWhyPicks');
ok(/renderStage2\(data\)/.test(load), 'loadForm still routes other stage-2 rows to renderStage2');

const page = body('renderWhyPicks');
ok(page !== '', 'renderWhyPicks() exists');
ok(/type="checkbox"/.test(page), 'every statement is a real checkbox');
ok(/aria-live="polite"/.test(page), 'the counter is announced (aria-live)');
ok(!/\b(alert|confirm|prompt)\(/.test(page), 'no native popups on the new page');

// ── v0.47.93: themes first, and what each choice stands for ──
const ctx2 = {};
try {
  vm.runInNewContext(body('themesWithPicks') + '\n' + body('toggleTheme') + '\n' + body('abilityCounts')
    + '\nthis.themesWithPicks = themesWithPicks; this.toggleTheme = toggleTheme; this.abilityCounts = abilityCounts;', ctx2);
} catch (e) { /* missing functions: reported below */ }
const { themesWithPicks, toggleTheme, abilityCounts } = ctx2;
ok(typeof themesWithPicks === 'function' && typeof toggleTheme === 'function' && typeof abilityCounts === 'function',
  'themesWithPicks(), toggleTheme() and abilityCounts() exist');

const GROUPS = [
  { id: 'family', items: [{ id: 'a', needs: ['mobility', 'flexibility', 'strength'] }, { id: 'b', needs: ['strength', 'balance'] }] },
  { id: 'travel', items: [{ id: 'c', needs: ['stamina'] }, { id: 'd', needs: ['stamina', 'balance'] }] },
  { id: 'mind', items: [{ id: 'e', needs: ['mind'] }] }
];
const ABILITIES = { strength: 'Strength', mobility: 'Mobility', flexibility: 'Flexibility', balance: 'Balance',
  stamina: 'Stamina', mind: 'A sharp mind', energy: 'Energy and sleep', connection: 'Connection' };
const J = JSON.stringify;

if (typeof abilityCounts === 'function') {
  // By hand for a, b, d, e: strength 2 (a, b), balance 2 (b, d), then one each
  // for mobility, flexibility (a), stamina (d), mind (e), in the server's order.
  ok(J(abilityCounts(['a', 'b', 'd', 'e'], GROUPS, ABILITIES)) === J([
    { id: 'strength', label: 'Strength', count: 2 }, { id: 'balance', label: 'Balance', count: 2 },
    { id: 'mobility', label: 'Mobility', count: 1 }, { id: 'flexibility', label: 'Flexibility', count: 1 },
    { id: 'stamina', label: 'Stamina', count: 1 }, { id: 'mind', label: 'A sharp mind', count: 1 }
  ]), 'ability counts and order match the hand-worked case');
  ok(J(abilityCounts([], GROUPS, ABILITIES)) === '[]' && J(abilityCounts(null, GROUPS, ABILITIES)) === '[]', 'no picks → empty list');
  ok(J(abilityCounts(['zzz', 'c'], GROUPS, ABILITIES)) === J([{ id: 'stamina', label: 'Stamina', count: 1 }]), 'an id not on offer counts for nothing');
}
if (typeof themesWithPicks === 'function' && typeof toggleTheme === 'function') {
  ok(J(themesWithPicks(GROUPS, ['b', 'e'])) === '["family","mind"]', 'themes holding saved picks are open at start');
  ok(J(themesWithPicks(GROUPS, [])) === '[]' && J(themesWithPicks(GROUPS, null)) === '[]', 'nothing saved → no theme open');
  let open = toggleTheme([], 'travel');
  ok(J(open) === '["travel"]', 'tapping a closed theme opens it');
  open = toggleTheme(open, 'family');
  ok(J(open) === '["travel","family"]', 'several themes can be open');
  const before = ['travel', 'family'];
  open = toggleTheme(before, 'travel');
  ok(J(open) === '["family"]' && before.length === 2, 'tapping an open theme closes it, without mutating its input');
  const picks = ['a', 'b'];
  const closed = toggleTheme(themesWithPicks(GROUPS, picks), 'family');
  ok(J(closed) === '[]' && J(picks) === '["a","b"]' && J(abilityCounts(picks, GROUPS, ABILITIES)[0]) === J({ id: 'strength', label: 'Strength', count: 2 }),
    'closing a theme does not remove its picks (still chosen and counted)');
}
ok(/<button type="button"[^>]*aria-pressed="/.test(page), 'theme tiles are real buttons with aria-pressed');
ok(/\.hidden = /.test(body('updatePicks')), 'a closed theme is hidden, not removed (its checkboxes stay in the page)');
ok(page.indexOf('Tap the parts of life you care about, then choose the 5 to 10 things you would most love to still be able to do.') !== -1, 'lead copy as written');
ok(src.indexOf('Start with one or two that matter to you.') !== -1, 'quiet line for a fresh page');
ok(src.indexOf('What your choices ask of your body') !== -1
  && src.indexOf('Each thing you chose depends on abilities like these. Your plan will focus on the ones your health answers show need the most work.') !== -1,
  'summary panel title and sentence as written');
ok(/of your choices/.test(body('updatePicks')), 'summary panel is redrawn with the picks (updatePicks)');
ok(!/\b(alert|confirm|prompt)\(/.test(src), 'the shipped file has no alert( / confirm( / prompt(');

const submit = body('submitWhyPicks');
ok(submit !== '', 'submitWhyPicks() exists');
ok(crypto.createHash('md5').update(submit).digest('hex') === '4272960dae0c9b8ec8070936887e661f', 'submitWhyPicks() is byte-for-byte slice 1\'s');
ok(/submitted:\s*true/.test(submit), 'Next sends the submitted save');
ok(/loadForm\(\)/.test(submit), 'Next goes on to loadForm() (the health sections)');
ok(!/renderStage2ThankYou/.test(submit), 'Next never shows the Stage 2 thank-you card');
ok(!/\b(alert|confirm|prompt)\(/.test(submit), 'no native popups in the submit path');
ok(/Connection error/.test(submit), 'network failure says "Connection error"');

const wiz = body('renderWizardSection');
ok(/isSingleFlow\(\)/.test(wiz), 'wizard reads the single-flow marker for dots + header');
ok(/Your assessment/.test(wiz), 'single-flow header reads "Your assessment"');

const s1 = body('renderStage1Result');
ok(/single_form/.test(s1) && /Continue your assessment/.test(s1), 'Stage 1 result has the covered-row copy');
ok(src.indexOf('Continue to Stage 2 \\u2014 Your WHY \\u2192') !== -1, 'Stage 1 result keeps today\'s button for other rows');

console.log('\n' + PASS + ' passed, ' + FAIL + ' failed');
process.exit(FAIL ? 1 : 0);
