#!/usr/bin/env node
/**
 * v0.48.78 (M35, M36, M37; hdlv2_ff_form_clarity) — the Full Report form, behind one switch. The REAL
 * renderWhyPicks(), whoForHtml(), renderTapStep(), tapFirstRows() and tapFirstNext() of
 * assets/js/hdlv2-staged-form.js, run in vm with the switch absent, false and true.
 *   C1 (M35) on: the first page has no picks panel (no bars, no chart); picks, count, people, own words
 *      and Next stay, and the tap and resize handlers do not throw without the panel.
 *   C2 (M36) on: the people block reads its line, two still pictures with captions, the second line,
 *      then the rows; nothing mentions the timeline; the roles and "+ Add another" are unchanged.
 *   C3 (M37) on: step 1's three lines are the new ones; an empty row keeps is-empty, its answers open,
 *      no Change; pressing too early adds the new line once and stays on step 1.
 *   C4 absent and false: today's six strings, none of the new ones.
 *   C5 steps 2 to 13 and the old wizard's sections render the same on and off.
 * Run: node tests/form-clarity/test-form-clarity.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const J = JSON.stringify;

const js = fs.readFileSync(new URL('../../assets/js/hdlv2-staged-form.js', import.meta.url), 'utf8');
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const fn = (name) => cut('  function ' + name + '(', '\n  }\n');
const esc = (s) => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const BASE = 'https://example.test/wp-content/plugins/hdl-longevity-v2/';
const VER = '0.48.78';
// The switch: absent (as on the live site today), false, and on.
const CFGS = { absent: { plugin_url: BASE, asset_ver: VER }, false: { plugin_url: BASE, asset_ver: VER, form_clarity: false }, on: { plugin_url: BASE, asset_ver: VER, form_clarity: true } };

// Today's strings (C4) and § 6's new ones.
const OLD = {
  panel: '<h3>What your picks need from your body</h3>',
  panelLine: 'For the life you picked, these are the abilities to keep in surplus today. Everyone ages; the surplus is what you build now.',
  caption: '5 is the most a life can ask of one ability. Your answers on the next pages show where you stand today on each one.',
  who: 'Name them. We’ll remember them for your timeline.',
  prompt: 'We filled in what we could from your first answers. Pick any row marked Unknown, and tap Change if one is not right.',
  empty: 'Unknown — please select',
  wait: 'Please answer the rows marked Unknown.',
};
const NEW = {
  line2: 'How you age affects the people who matter to you.',
  line4: 'To personalise your Full Report, feel free to share who is important to you.',
  prompt: 'We filled in what we could from your first answers. Some rows have no answer yet: choose one for each. If an answer is not right, tap Change.',
  empty: 'No answer yet. Choose one below.',
  wait: 'Please choose an answer for each row that has none yet.',
};

// ── the first page (renderWhyPicks), against a fake root whose ids exist only when drawn ──
const ICON_SRC = cut('  var ICONS = {', '\n  };\n');
const PAGE_SRC = [ICON_SRC, cut('  var PICK_MIN = ', ';\n'), cut('  var TICK = ', ';\n'), cut('  var radarShown = ', '\n'),
  cut('  var WHO_ROLES = ', ';\n'), cut('  var WHO_MAX = ', ';\n'), cut('  var S1_IMG_BASE = ', '\n  })();\n'), cut('  var TAP_MOTION = {', '\n  };\n'),
  ...['motionFigure', 'togglePick', 'abilityCounts', 'pickCountLine', 'radarData', 'chartLabel', 'pickIcon', 'radarChart', 'abilityBars',
    'renderWhyPicks', 'redrawRadar', 'reducedMotion', 'updatePicks', 'picksTextarea', 'peopleFromData', 'whoForRow', 'whoForHtml', 'bindWhoFor'].map(fn)].join('\n');
const ABILITIES = { strength: 'Strength', mobility: 'Mobility', balance: 'Balance', stamina: 'Stamina', mind: 'Mind', connection: 'Connection' };
const group = (id, title, n) => ({ id, title, items: Array.from({ length: n }, (_, i) => ({ id: id + i, label: title + ' ' + i, needs: [['strength', 'balance', 'mind'][i % 3]] })) });
const DATA = { why_options: [group('people', 'People I love', 6), group('freedom', 'My freedom', 8), group('things', 'Things I love', 7)], why_abilities: ABILITIES, why_ability_short: ABILITIES };

function page(mode, picks) {
  const el = () => ({ hidden: false, textContent: '', innerHTML: '', clientWidth: 440, value: '', attrs: {}, setAttribute(k, v) { this.attrs[k] = String(v); },
    getAttribute(k) { return this.attrs[k]; }, addEventListener() {}, querySelectorAll() { return []; }, querySelector() { return null; }, classList: { toggle() {} } });
  const root = el(), ids = {};
  const ctx = {
    CFG: CFGS[mode], window: { hdlv2_form: CFGS[mode], addEventListener() {} }, root, formData: { why_picks: picks || [] }, serverData: DATA, saveTimer: null,
    document: { getElementById: (id) => (root.innerHTML.indexOf('id="' + id + '"') !== -1 ? (ids[id] = ids[id] || el()) : null), getElementsByTagName: () => [] },
    setRootClasses() {}, saveIndicator: () => '[save]', footer: () => '[footer]', brandBar: () => '[brand]', bindFieldListeners() {}, submitWhyPicks() {},
    clearSaveError() {}, saveWhenFree() {}, setTimeout() {}, clearTimeout() {}, esc,
  };
  let error = '';
  try { vm.runInNewContext(PAGE_SRC + '\nrenderWhyPicks(serverData);\nupdatePicks();\nredrawRadar(true);', ctx); } catch (e) { error = e.message; }
  return { html: root.innerHTML, error };
}

console.log('── C1 (M35): the panel leaves the first page with the switch on ──');
const onPage = page('on', ['people0', 'freedom1', 'things2']);
ok(onPage.error === '', 'C1.0 the page draws, a tap and a resize run, nothing throws' + (onPage.error ? ' (' + onPage.error + ')' : ''));
ok(onPage.html.indexOf('hdlv2-why-needs') === -1 && onPage.html.indexOf(OLD.panel) === -1, 'C1.1 no panel and no "What your picks need from your body"');
ok(onPage.html.indexOf('hdlv2-why-radar') === -1 && onPage.html.indexOf('hdlv2-abilities') === -1 && onPage.html.indexOf(OLD.panelLine) === -1 && onPage.html.indexOf(OLD.caption) === -1,
  'C1.2 no bars, no chart, no line under the heading, no caption');
ok((onPage.html.match(/class="hdlv2-why-group"/g) || []).length === 3 && onPage.html.indexOf('id="hdlv2-picks-count"') !== -1, 'C1.3 the three statement groups and the count line stay');
ok(['id="hdlv2-who-for"', 'id="hdlv2-f-own_words"', 'id="hdlv2-picks-next"'].every((s) => onPage.html.indexOf(s) !== -1), 'C1.4 the people block, the own-words box and Next stay');
const offPage = page('absent', ['people0', 'freedom1', 'things2']);
ok(onPage.html.split('id="hdlv2-picks-count"')[0] === offPage.html.split('id="hdlv2-picks-count"')[0], 'C1.5 everything above the count line is the same on and off');

console.log('── C2 (M36): "Who are you staying strong for?" with the switch on ──');
const run = (mode, code) => { const c = { CFG: CFGS[mode], window: { hdlv2_form: CFGS[mode] }, document: { getElementsByTagName: () => [] }, esc };
  vm.runInNewContext(PAGE_SRC + '\nthis.OUT = ' + code + ';', c); return c.OUT; };
const whoOn = (onPage.html.split('id="hdlv2-who-for"')[1] || '').split('</fieldset>')[0];
const pic = (file, word) => `<figure class="hdlv2-who-pic"><img src="${BASE}assets/images/motion/${file}?ver=${VER}" alt="" width="96" height="96" loading="eager" decoding="async"><figcaption>${word}</figcaption></figure>`;
const ORDER = ['<legend>Who are you staying strong for?</legend>', '<p class="hdlv2-who-help">' + NEW.line2 + '</p>',
  '<div class="hdlv2-who-pics">' + pic('p-sitting-out.svg', 'Sitting out') + pic('p-joining-in.svg', 'Joining in') + '</div>',
  '<p class="hdlv2-who-help">' + NEW.line4 + '</p>', '<div class="hdlv2-who-rows">'];
const at = ORDER.map((s) => whoOn.indexOf(s));
ok(at.every((n) => n !== -1) && at.every((n, i) => i === 0 || n > at[i - 1]), 'C2.1 in order: the question, line 2, the two pictures, line 4, the rows');
ok(whoOn.indexOf(ORDER[0] + ORDER[1] + ORDER[2] + ORDER[3] + ORDER[4]) !== -1, 'C2.2 nothing between them');
ok((whoOn.match(/class="hdlv2-who-row"/g) || []).length === 3, 'C2.3 three rows');
ok(whoOn.indexOf('for your timeline') === -1 && onPage.html.indexOf('timeline') === -1, 'C2.4 nothing on the page mentions the timeline');
ok((whoOn.match(/ alt=""/g) || []).length === 2 && whoOn.indexOf('<picture>') === -1, 'C2.5 both pictures are still and decorative (alt=""), the captions carry the words');
const whoOff = run('absent', 'whoForHtml([])'), whoOnFn = run('on', 'whoForHtml([], true)');
const rowsOf = (h) => h.slice(h.indexOf('<div class="hdlv2-who-rows">'));
ok(rowsOf(whoOnFn) === rowsOf(whoOff) && /<button type="button" class="hdlv2-who-add">\+ Add another<\/button>/.test(whoOnFn)
  && ['Spouse or partner', 'Child', 'Grandchild', 'Parent', 'Brother or sister', 'Friend', 'Other'].every((l) => whoOnFn.indexOf('>' + l + '</option>') !== -1),
  'C2.6 the rows, the seven roles and "+ Add another" are unchanged');
const css = fs.readFileSync(new URL('../../assets/css/hdlv2-form.css', import.meta.url), 'utf8');
ok(/\.hdlv2-who-pics \{ display: flex; gap: 24px;/.test(css), 'C2.7 the two pictures sit in one row, 24 px apart');
ok(/\.hdlv2-who-pic img,\s*\.hdlv2-tap-motion img,/.test(css) && /\.hdlv2-who-pic figcaption,\s*\.hdlv2-tap-motion figcaption,/.test(css)
  && /\.hdlv2-who-pic img,\s*\.hdlv2-tap-motion img,\s*\.hdlv2-body-guide img \{ width: 96px; height: 96px; \}/.test(css),
  'C2.8 each is the form\'s usual disc and caption (80 px, 96 px from 640 px), no new colour');

console.log('── C3 (M37): step 1 with the switch on ──');
const TAP_SRC = [cut('  var S3_OPTIONS = {', '\n  };\n'), cut('  var TEST_HOW = {', '\n  };\n'), cut('  var TAP_STEPS = ', ';\n'),
  cut('  var TAP_FIRST = ', ';\n'), cut('  var TAP_FIRST_LABELS = {', '\n  };\n'), cut('  var TAP_ORDER = ', ';\n'), cut('  var TAP_NEUTRAL = ', ';\n'),
  cut('  var TAP_TITLES = {', '\n  };\n'), cut('  var S1_IMG_BASE = ', '\n  })();\n'), cut('  var TAP_MOTION = {', '\n  };\n'),
  cut('  var ICONS = {', '\n  };\n'), cut('  var ROW_ICONS = {', '\n  };\n'), fn('motionFigure'), fn('tapHeading'), fn('tapReason'), fn('tapFirstRows'),
  fn('renderTapStep'), fn('wizardSectionContent'),
  cut('  var IN_CM = ', ';\n'), fn('r1'), fn('toImperial'), fn('unitsDefault'), fn('unitsNow'), fn('unitsSwitch'), fn('measureInput')].join('\n');
const VALS = { physicalActivity: '', sleepDuration: '4', sleepQuality: '', smokingStatus: '5', socialConnections: '3', dietQuality: '2' };
function tap(mode, call) {
  const ctx = {
    CFG: CFGS[mode], window: { hdlv2_form: CFGS[mode] }, document: { getElementById: () => null, getElementsByTagName: () => [] }, esc,
    setRootClasses() {}, tapLeave() {}, bindTapRows() {}, bindTapQuestion() {}, bindFieldListeners() {}, bindTapComplete() {}, bindUnits() {},
    brandBar: () => '[brand]', saveIndicator: () => '[save]', footer: () => '[footer]', serverData: {}, ABILITY_SENTENCES: {},
    tapCards: (f, v) => `[cards:${f}:${v}]`, tapFirstValues: () => VALS, tapSection: (id) => ({ id }),
    fieldReadonly: (l, v, u) => `[ro:${l}:${v}:${u}]`, prefillHint: () => '[hint]', fieldInputWithSkip: (n) => `[in:${n}]`,
    fieldSelect: (n) => `[sel:${n}]`, fieldTextarea: (n) => `[ta:${n}]`, s1Prefill: () => '',
    root: { innerHTML: '' }, formData: {}, tapOpenRow: null, tapStep: 0, tapOrder: [],
  };
  vm.runInNewContext(TAP_SRC + '\ntapOrder = TAP_ORDER;\n' + call, ctx);
  return ctx.OUT;
}
const step = (mode, n) => tap(mode, `wizardSectionContent = function (sec) { return '[section:' + sec.id + ']'; }; tapStep = ${n}; renderTapStep({ stage1_data: {}, second_form_ties: {} }, false); this.OUT = root.innerHTML;`);
const rows = (mode) => tap(mode, 'this.OUT = tapFirstRows(' + J(VALS) + ');');
const s0 = step('on', 0), r0 = rows('on');
const row = (h, f) => h.split('<div class="hdlv2-tap-row">').find((r) => r.indexOf('id="hdlv2-tap-label-' + f + '"') !== -1) || '';
ok(s0.indexOf('<p class="hdlv2-s1-prompt">' + NEW.prompt + '</p><p class="hdlv2-s1-prompt">Your answers here build your draft longevity report.</p>') !== -1, 'C3.1 the new line above the rows');
for (const f of ['physicalActivity', 'sleepQuality']) {
  ok(row(r0, f).indexOf('<span class="hdlv2-tap-row-value is-empty">' + NEW.empty + '</span>') !== -1, `C3.2.${f} the empty row reads "${NEW.empty}" in the grey italic class`);
  ok(/ role="radiogroup"[^>]*data-row="\w+">/.test(row(r0, f)) && row(r0, f).indexOf('hdlv2-tap-change') === -1, `C3.3.${f} its answers open, no Change`);
}
ok(row(r0, 'sleepDuration').indexOf('is-empty') === -1 && row(r0, 'sleepDuration').indexOf('hdlv2-tap-change') !== -1, 'C3.4 an answered row is unchanged: its answer and Change');
function press(mode, saved, clicks) {
  const t = { goes: [], inserted: [], focused: null };
  const btn = { insertAdjacentHTML(pos, html) { t.inserted.push([pos, html]); } };
  const c = {
    CFG: CFGS[mode], formData: Object.assign({}, saved), tapGo: (d, n) => t.goes.push(n),
    document: { getElementById: (id) => (id === 'hdlv2-tap-next' ? btn : null),
      querySelector: (sel) => (sel === '.hdlv2-tap-wait' ? (t.inserted.length ? {} : null) : /^#hdlv2-tap-opts-\w+ \.hdlv2-s1-opt$/.test(sel) ? { focus() { t.focused = sel; } } : null) },
  };
  vm.runInNewContext([cut('  var S1_TO_S3_DEFAULTS = {', '\n  };\n'), cut('  var TAP_FIRST = ', ';\n'), fn('s1Prefill'), fn('tapFirstValues'), fn('tapFirstNext')].join('\n')
    + `\nfor (var i = 0; i < ${clicks}; i++) tapFirstNext({ stage1_data: { q6: 'b', q7: 'a', q8: 'd', q9: 'e' } });`, c);
  return t;
}
const early = press('on', {}, 2);
ok(early.goes.length === 0 && J(early.inserted) === J([['afterend', '<p class="hdlv2-tap-wait" role="status">' + NEW.wait + '</p>']]), 'C3.5 too early: the new line once, under the button, and step 1 stays');
ok(early.focused === '#hdlv2-tap-opts-physicalActivity .hdlv2-s1-opt', 'C3.6 focus goes to the first row that has no answer');
ok(J(press('on', { physicalActivity: '2', sleepQuality: '3' }, 1).goes) === J([1]), 'C3.7 every row answered: on to step 2');
const onAll = s0 + r0 + early.inserted.map((x) => x[1]).join('');
ok([OLD.prompt, OLD.empty, OLD.wait].every((s) => onAll.indexOf(s) === -1) && onAll.indexOf('Unknown') === -1, 'C3.8 none of the three old lines, and no "Unknown", is on the screen');

console.log('── C4: switch absent and false, today\'s strings ──');
for (const mode of ['absent', 'false']) {
  const p = page(mode, ['people0']), all = p.html + step(mode, 0) + rows(mode) + press(mode, {}, 1).inserted.map((x) => x[1]).join('');
  ok([OLD.panel, OLD.panelLine, OLD.caption, OLD.who, OLD.prompt, OLD.empty, OLD.wait].every((s) => all.indexOf(s) !== -1), `C4.1.${mode} today's six strings are there (and the panel heading)`);
  ok(Object.values(NEW).every((s) => all.indexOf(s) === -1) && all.indexOf('hdlv2-who-pic') === -1, `C4.2.${mode} none of the new lines and no pictures`);
}
ok(page('false', ['people0']).html === page('absent', ['people0']).html && step('false', 0) === step('absent', 0) && rows('false') === rows('absent'), 'C4.3 false draws exactly what absent draws');

console.log('── C5: steps 2 to 13 and the old wizard, the same on and off ──');
for (let n = 1; n < 13; n++) ok(step('on', n) === step('absent', n), `C5.${n} step ${n + 1} unchanged`);
const body = (mode, id) => tap(mode, `this.OUT = wizardSectionContent({ id: '${id}' }, { q1_age: 52, q1_sex: 'female' }, formData);`);
ok(['body', 'background'].every((id) => body('on', id) === body('absent', id)), 'C5.13 the measurements and background sections are the same');
ok(!/form_clarity/.test(fn('renderWizardSection') + fn('wizardSectionContent')), 'C5.14 the old wizard does not read the switch');

console.log(`PASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
