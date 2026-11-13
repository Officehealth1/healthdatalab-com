#!/usr/bin/env node
/**
 * v0.48.66 — the safety notice by country in widget/hdl-lead-magnet.js.
 * The pure helpers (sfxRowFor, sfxFill, sfxTiers, sfxLang, sfxNoticeHtml, sfxReminderHtml) run in vm
 * against the REAL server payload (dump-payload.php → HDLV2_Safety_Content::public_payload()).
 * renderSafetyScreenV2 runs in vm with a fake page. Flag off: today's screens are 9e1d631's text.
 * Run: node tests/safety-notice/test-widget-notice.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const HERE = fileURLToPath(new URL('.', import.meta.url));
const REL = 'hdl-longevity-v2/widget/hdl-lead-magnet.js';
const js = fs.readFileSync(new URL('../../widget/hdl-lead-magnet.js', import.meta.url), 'utf8');
const old = execFileSync('git', ['show', '9e1d631:' + REL], { cwd: HERE, encoding: 'utf8', maxBuffer: 8 << 20 });
const cutIn = (text, head, end) => { const at = text.indexOf(head); return at < 0 ? '' : text.slice(at, text.indexOf(end, at) + end.length); };
let sn = null;
try { sn = JSON.parse(execFileSync('php', [HERE + 'dump-payload.php'], { encoding: 'utf8' })); } catch (e) { sn = null; }
ok(sn && sn.notices && sn.notices.en, 'the server payload loads (HDLV2_Safety_Content::public_payload)');
if (!sn) { console.log(`\nPASS=${PASS} FAIL=${FAIL}`); process.exit(1); }

// ── Flag off: today's three screens are byte for byte 0.48.65 ──
const blockHead = '    // --- v0.43.0 Front-door safety screen ---';
const blockEnd = "      if (go2) go2.addEventListener('click', function () { goTo(10); });\n    }\n";
const oldBlock = cutIn(old, blockHead, blockEnd), newBlock = cutIn(js, blockHead, blockEnd);
ok(oldBlock.length > 3000 && oldBlock === newBlock, 'flag off: the lists, renderSafetyScreen, sfxContinue, renderCrisisScreen, renderHardScreen are 9e1d631\'s text');
const CALL_OLD = '          renderSafetyScreen();\n';
const CALL_NEW = '          (cfg.safetyNotice ? renderSafetyScreenV2 : renderSafetyScreen)();\n';
const rcOld = cutIn(old, '    function renderContact() {', '\n    }\n'), rcNew = cutIn(js, '    function renderContact() {', '\n    }\n');
ok(rcOld.indexOf(CALL_OLD) > 0 && rcNew === rcOld.replace(CALL_OLD, CALL_NEW), 'renderContact changed by the one dispatch line only');
const cfgLine = '    cfg.safetyNotice = (publicCfg && publicCfg.safety_notice) || (invite && invite.safety_notice) || null; // v0.48.66\n';
ok(js.indexOf(cfgLine) > 0, 'cfg.safetyNotice read from the public config or the invite payload');
const ACK_LINE = '      if (answers._safetyAck) payload.safety_ack = answers._safetyAck; // v0.48.66\n';
const rrOld = cutIn(old, '    function renderResult() {', '\n    }\n'), rrNew = cutIn(js, '    function renderResult() {', '\n    }\n');
const SAFETY_LINE = '      if (answers._safety) payload.safety = answers._safety;\n';
ok(rrNew === rrOld.replace(SAFETY_LINE, SAFETY_LINE + ACK_LINE), 'renderResult changed by the one safety_ack line only, after payload.safety');

// ── The pure helpers in vm ──
const names = ['escHtml', 'sfxLang', 'sfxRowFor', 'sfxFill', 'sfxTiers', 'sfxNoticeHtml', 'sfxReminderHtml'];
const srcs = names.map(n => cutIn(js, '  function ' + n + '(', '\n  }\n'));
names.forEach((n, i) => ok(srcs[i] !== '', n + ' is a top-level function of the widget'));
if (srcs.some(s => !s)) { console.log(`\nPASS=${PASS} FAIL=${FAIL}`); process.exit(1); }
const fakeDoc = { createElement: () => ({ set textContent(v) { this.t = String(v); }, get innerHTML() { return this.t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); } }) };
const ctx = { document: fakeDoc, navigator: { language: 'en-GB' }, sn, out: null };
vm.createContext(ctx);
vm.runInContext(srcs.join('\n'), ctx);
const call = (expr) => vm.runInContext('out = ' + expr, ctx);
const row = (cc) => call('sfxRowFor(sn, ' + JSON.stringify(cc) + ')');

ok(row('PH').code === 'PH' && row('GB').code === 'GB' && row('').code === '' && row('ZZ').code === '' && row(null).code === '', 'sfxRowFor: PH, GB rows; empty, unknown, null → generic');
const A = (cc, also) => call(`sfxNoticeHtml(sn, 'en', 'a', ${JSON.stringify(cc)}, ${also}, 'w', '')`);
const Bn = (cc) => call(`sfxNoticeHtml(sn, 'en', 'b', ${JSON.stringify(cc)}, false, 'w', '')`);
const Cn = (cc, btn) => call(`sfxNoticeHtml(sn, 'en', 'c', ${JSON.stringify(cc)}, false, 'w', ${JSON.stringify(btn)})`);

let h = A('PH', false);
ok(h.indexOf('Please read this before your result') > 0, 'A PH: title from the sheet');
ok(h.indexOf('href="tel:911"') > 0 && h.indexOf('>911<') > 0, 'A PH: 911 as visible digits and a tel: link');
ok(h.indexOf('Do not drive yourself.') > 0 && h.indexOf('If it happened longer ago and has passed') > 0, 'A PH: both the "now" and the "passed" action');
ok(h.indexOf('Contact a doctor or clinic.') > 0, 'A PH: the PH doctor phrase');
ok((h.match(/<select/g) || []).length === 1 && /<option value="PH" selected>Philippines<\/option>/.test(h), 'A PH: one country menu, Philippines selected');
ok(h.indexOf('<label for="w-sfx-country">Country</label>') > 0, 'A: the menu has a label');
ok(h.indexOf('lang="en" translate="no"') > 0, 'A: lang="en" translate="no" on the notice');
ok(h.indexOf('I have read this. Show my result') > 0, 'A: the sheet\'s button');
ok(h.indexOf('This check is automatic. It is not a diagnosis. Please do not wait to hear from us.') > 0, 'T-auto: A carries the automatic line');
ok(h.indexOf('You also ticked something') < 0 && A('PH', true).indexOf('You also ticked something worth showing a doctor this week.') > 0, 'A: the "also" line only when B rows were ticked too');
h = A('GB', false);
ok(h.indexOf('href="tel:999"') > 0 && h.indexOf('Contact NHS 111 or your GP.') > 0 && h.indexOf('911') < 0, 'A GB: 999 and NHS 111 or your GP');
h = A('', false);
ok(h.indexOf('your local emergency number') > 0 && h.indexOf('tel:') < 0 && /<option value="" selected>Country not known<\/option>/.test(h), 'A unknown: "your local emergency number", no tel link, "Country not known" selected');
h = Bn('GB');
ok(h.indexOf('One thing before your result') > 0 && h.indexOf('Please book a doctor\'s appointment this week.') > 0 && h.indexOf('tel:999') > 0, 'B GB: title, the week line, 999 for "suddenly worse"');
ok(h.indexOf('This check is automatic. It is not a diagnosis. Please do not wait to hear from us.') > 0, 'T-auto: B carries the automatic line');
h = Cn('', 'See my results →');
ok(h.indexOf('You do not have to face this alone') > 0 && h.indexOf('a crisis line in your country') > 0 && h.indexOf('your local emergency number') > 0, 'C unknown: the sheet\'s words, local emergency number, a crisis line in your country');
ok(h.indexOf('This check is automatic. Please do not wait to hear from us.') > 0 && h.indexOf('See my results →') > 0, 'T-auto: C carries the automatic line; C keeps today\'s button words');
h = Cn('US', 'Continue');
ok(h.indexOf('tel:911') > 0 && h.indexOf('tel:988') > 0 && h.indexOf('Samaritans') < 0, 'C US: 911 and 988, no British line');
h = Cn('GB', 'Continue');
const vis = h.replace(/<[^>]+>/g, '');
ok(h.indexOf('tel:116123') > 0 && (vis.match(/999/g) || []).length === 1 && (vis.match(/Samaritans/g) || []).length === 1 && vis.indexOf('NHS 111') > vis.indexOf('If you are in danger right now') && vis.indexOf('85258') > 0,
  'C GB (sheet 10.7): 999 and Samaritans once each, then only NHS 111 and Shout under the new lines');
ok(A('PH', false).indexOf('<script') < 0 && call(`sfxFill('{DOCTOR}', { doctor: '<img src=x>', crisis: '', emergency: '' })`).indexOf('<img') < 0, 'country text is escaped');

// ── T-tier in the widget: tiers come from the payload rows only ──
const t1 = call(`sfxTiers(sn, { symptoms: ['stroke_speech', 'new_lump'] })`);
ok(t1.a === true && t1.b === true && t1.c === false, 'sfxTiers: TODAY + THIS_WEEK → a and b');
const t2 = call(`sfxTiers(sn, { mh: ['low_mood'] })`);
ok(!t2.a && !t2.b && !t2.c, 'sfxTiers: low mood → no notice');
const t3 = call(`sfxTiers(sn, { mh: ['life_not_worth'], symptoms: ['fainting'] })`);
ok(t3.c && t3.b && !t3.a, 'sfxTiers: crisis + a THIS_WEEK row → c and b');

// ── T-repeat ──
const ra = call(`sfxReminderHtml(sn, 'en', 'a', 'PH')`), rb = call(`sfxReminderHtml(sn, 'en', 'b', 'GB')`);
ok(ra.indexOf('<b>Reminder:</b> if this is happening now, or happened in the last 24 hours, call <a href="tel:911"><b>911</b></a> now. If it has passed, ask for an urgent doctor\'s appointment today.') > 0,
  'reminder after A (sheet 10.5): both actions, the "now" one with 911 and the "passed" one');
ok(rb.indexOf('Reminder:') > 0 && rb.indexOf('Please book a doctor\'s appointment this week.') > 0, 'reminder after B: B\'s action line');
ok(call(`sfxReminderHtml(sn, 'en', 'c', 'GB')`) === '' && call(`sfxReminderHtml(sn, 'en', '', 'GB')`) === '', 'no reminder after C or for an unticked run');
const notice = cutIn(js, '    function renderNoticeV2(', '\n    }\n');
ok(/goTo\(10\);\s*\n\s*var res = contentEl\.querySelector\('\.hdlw-r'\);\s*\n\s*if \(res\) res\.insertAdjacentHTML\('afterbegin', sfxReminderHtml\(/.test(notice), 'the reminder is put at the top of the result right after it is drawn');
ok(/answers\._safetyAck = \{ notice: kind, version: sn\.version, country: sfxCountry \};/.test(notice), 'the press is kept as answers._safetyAck {notice, version, country}');

// ── T-lang-key ──
ok(call('sfxLang(sn)') === 'en', 'sfxLang: en-GB → en');
ctx.navigator.language = 'fr-FR';
ok(call('sfxLang(sn)') === 'en', 'sfxLang: an absent language (fr) → en');

// ── T-no-branch + the check screen (renderSafetyScreenV2 in a fake page) ──
const v2 = cutIn(js, '    function renderSafetyScreenV2() {', '\n    }\n');
const rowFns = ['sfxRow', 'sfxNoneRow'].map(n => cutIn(js, '    function ' + n + '(', '\n    }\n'));
ok(v2 !== '' && rowFns.every(Boolean), 'renderSafetyScreenV2 and the row helpers found');
const page = { innerHTML: '', closest: () => null };
const pctx = { document: { ...fakeDoc, getElementById: () => null }, navigator: { language: 'en' }, cfg: { safetyNotice: sn }, visitorGeo: { country: 'PH' }, sfxSel: { symptoms: {}, mh: {} },
  contentEl: page, stepEl: null, id: 'w', sfxCountry: '', sfxBindToggles() {}, sfxContinueV2() {}, injectNoticeStyles() { pctx.styled = true; } };
vm.createContext(pctx);
vm.runInContext(srcs.join('\n') + '\n' + rowFns.join('\n') + '\n' + v2 + '\nrenderSafetyScreenV2();', pctx);
const scr = page.innerHTML;
ok((scr.match(/data-sfx-key="(?!__none)/g) || []).length === 18 && (scr.match(/data-sfx-key="__none"/g) || []).length === 2, 'T-no-branch: exactly the eighteen tick rows and the two "none" rows');
ok(!/<input|<select|<textarea/.test(scr), 'T-no-branch: no input, no menu, no text box on the check screen');
ok(pctx.styled === true, 'the notice styles are injected by the v2 screen (injectEditorialStyles stays as it was)');
ok(scr.indexOf('This check is automatic. No one reads your answers straight away. If you feel very unwell right now, call <a href="tel:911"><b>911</b></a>.') > 0, 'T-auto: line 4.0 above the check with the visitor\'s number (PH 911)');
ok(scr.indexOf('If something feels like an emergency') < 0, 'the old bottom 999 line is replaced');
ok(scr.indexOf('A new lump (breast, testicle, neck, armpit, groin, or abdomen)') > 0, 'the rows are the server labels (one list)');
ok((A('PH', false).match(/<input|<textarea/g) || []).length === 0, 'T-no-branch: a notice has no input or text box (one menu only)');

// ── No accent rail in the new style rules ──
const css = cutIn(js, '  function injectNoticeStyles() {', '\n  }\n');
ok(css !== '' && /\.hdlw-sfx-notice\{border-left:1px solid ' \+ B\.border/.test(css) && !/border-left:\s*[2-9]px|border-left:[^;]*accent/.test(css), 'the new rules draw no coloured rail (border-left is the neutral 1px border)');

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
