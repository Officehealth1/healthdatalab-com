#!/usr/bin/env node
/**
 * v0.49.10 (plan 19; hdlv2_ff_stage1_result_one_column) — the Stage 1 result in one column, in the order
 * of Matthew's drawing of 26 November: the number, what it means, the film, "but why", the offer.
 * The REAL oneColumnHtml is cut from widget/hdl-lead-magnet.js and run in vm. It is the half that holds
 * every word and the order; the other half (oneColumnResult) only moves the parts renderResult drew
 * into its marked places, and is checked in a browser (tests/result-one-column/harness.html).
 *   T1.1 one column: one width, the only two-track row is the gauge beside its figures, one track on a phone.
 *   T1.2 the band's sentence and not the others; rows a, d, g, h, i, k, m letter for letter.
 *   T1.3 both buttons carry the buy address they are handed; the price is the offer's, never typed here.
 *   T1.4 "Sending your answers…" has its place before the offer; both buttons sit in the part saved() shows.
 *   T1.5 switch absent: the result is drawn by renderResult itself, which knows nothing of this layout.
 *   T1.6 no first name, no film, no practitioner name, no offer, an unknown band; none of the drawing's sample values.
 * Run: node tests/result-one-column/test-one-column.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };

const js = fs.readFileSync(new URL('../../widget/hdl-lead-magnet.js', import.meta.url), 'utf8');
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const esc = (s) => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const text = (html) => html.replace(/<style>[\s\S]*?<\/style>/g, ' ').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
const count = (s, n) => String(s).split(n).length - 1;

const words = cut('  var ONE_COLUMN_BAND = ', '\n  };\n');
const fn = cut('  function oneColumnHtml(', '\n  }\n');
const mover = cut('  function oneColumnResult(', '\n  }\n');
ok(words !== '' && fn !== '' && mover !== '', 'the band sentences, oneColumnHtml and oneColumnResult are top-level in the widget');
const HREF = 'https://alt.test/full-report-slower#email=ada%40example.test';
const frame = (over) => {
  const d = Object.assign({ id: 'hdlw-206', esc, first: 'Ada', prac: 'Rosa', band: 'good', film: true, offer: { href: HREF, today: '$27' } }, over);
  const c = { d, out: '' };
  try { vm.runInNewContext(words + '\n' + fn + '\nout = oneColumnHtml(d);', c); } catch (e) { return ''; }
  return String(c.out);
};
const full = frame({}), t = text(full), css = (/<style>([\s\S]*?)<\/style>/.exec(full) || ['', ''])[1];

const A = 'How Fast You are Ageing?';
const D = 'Your results are in. So many people never look until it’s late in the game. You just did, and that matters.';
const E = {
  warn: 'You’re currently ageing faster than the population average. That isn’t a verdict. It’s the clearest signal you’ll get that something is pushing your number up.',
  avg: 'You’re ageing at about the population average. That sounds fine, until you remember the average road is the one where the stairs, the floor and lifting the suitcase slowly get harder.',
  good: 'You’re ageing slower than the population average. Something you’re doing is working. The question is what, and what’s quietly working against you.',
};
const G = '… but why?';
const H1 = 'Your number shows where you’re heading. Not why.';
const H2 = 'Without the why, any change is a guess. Your Full Longevity Report shows what’s driving your number and where to focus first.';
const I = 'Show me what’s driving my number';
const K = 'Important: this number is an estimate. No test can measure anyone\'s exact rate of ageing. Use it like a compass, not a GPS: a direction and a place to start.';
const DOCTOR = 'None of this replaces your doctor. Keep them in the loop.';
const INBOX = 'Your free report is on its way to your inbox.';

console.log('── T1.2 his words ──');
ok(t.includes(A) && count(full, '<h1') === 1, 'a: the one main heading, "' + A + '"');
ok(t.includes('Hi Ada,'), 'c: the greeting, "Hi Ada,"');
ok(t.includes(D), 'd: the opening line, letter for letter');
for (const band of ['good', 'avg', 'warn']) {
  const bt = text(frame({ band }));
  ok(bt.includes(E[band]) && Object.keys(E).filter((b) => b !== band).every((b) => !bt.includes(E[b].split('.')[0])), 'e: ' + band + ' shows its own sentence and neither of the other two');
}
ok(t.includes('Rosa explains your result'), 'f: the film\'s caption carries the practitioner\'s first name, and no minutes');
ok(t.includes(G) && count(full, '<h2') === 1, 'g: the second heading, "' + G + '"');
ok(t.includes(H1) && t.includes(H2), 'h: the two lines, letter for letter');
ok(count(full, '>' + I + '</a>') === 1, 'i: the wide button, "' + I + '"');
ok(count(full, '>Show me ($27)</a>') === 1 && />Rosa<\/p>/.test(full), 'm: the sign-off "Rosa" and the second button "Show me ($27)"');
// k and the two kept lines are today's: the boxed note moves in as drawn; the other two are typed again here.
const today = cut('  function resultOfferHtml(', '\n  }\n');
const was = { out: '' };
vm.runInNewContext(today + '\nout = resultOfferHtml({ today: "$27" }, "https://alt.test/x", function (s) { return s; }, "", false);', was);
ok(text(was.out).includes(K) && full.includes('data-part="note"'), 'k: the boxed note is today\'s, word for word, and has its place in the column');
ok([DOCTOR, INBOX].every((s) => text(was.out).includes(s) && t.includes(s)), 'kept: the doctor line and the inbox line read as they do today');
const order = [A, 'data-part="gauge"', 'data-part="stats"', 'Hi ', 'Your results are in.', 'ageing slower', 'data-part="film"', 'explains your result', 'data-part="status"',
  '… but why?', 'Your number shows', 'Without the why', 'data-part="mail"', I, 'For a small investment of', 'data-part="note"', DOCTOR, '>Rosa<', 'Show me ($27)', INBOX, 'data-part="link"', 'data-part="foot"'];
const at = order.map((s) => full.indexOf(s));
ok(at.every((p, i) => p >= 0 && (i === 0 || p > at[i - 1])), 'the order of ideas: the number, what it means, the film, "but why", the offer, the foot');

console.log('── T1.3 the buy address and the price ──');
const hrefs = [...full.matchAll(/<a [^>]*href="([^"]*)"/g)].map((m) => m[1]);
ok(hrefs.length === 2 && hrefs.every((h) => h === esc(HREF)), 'both buttons carry the address they are handed, and there is no other link');
const euro = frame({ offer: { href: HREF, today: '€31' } });
ok(text(euro).includes('For a small investment of €31.') && euro.includes('>Show me (€31)</a>') && !euro.includes('27'), 'the price in both places is the offer\'s');
ok(!/\$\s?\d|\b27\b|\b97\b|48 hours/.test(words + fn + mover), 'no price and no deadline is typed into the code');

console.log('── T1.4 before and after the save ──');
const thanks = full.indexOf('<div id="hdlw-206-thanks" hidden>');
ok(thanks > 0 && full.indexOf('data-part="status"') < thanks && full.indexOf('data-part="status"') > 0, 'the "Sending your answers…" line has its place before the offer');
ok(full.indexOf('<a ') > thanks && full.indexOf('… but why?') > thanks, 'both buttons and the "but why" part sit in the part saved() shows; nothing to press before it');

console.log('── T1.1 one column ──');
ok(/\.hdlw-r\.hdlw-r1\{[^}]*max-width:640px/.test(css), 'the column has one width, 640 px');
ok(count(css, 'grid-template-columns') === 2 && /\.hdlw-r1-top\{[^}]*grid-template-columns:[^;}]+ [^;}]+[;}]/.test(css)
  && /@media \(max-width:640px\)\{[^@]*\.hdlw-r1-top\{grid-template-columns:1fr;\}/.test(css), 'the only two-track row is the gauge beside its figures; on a phone it is one track');
ok(!/float:|column-count|position:(absolute|fixed)/.test(css), 'nothing floats or is laid in columns');
ok(!/border-left|::before|text-transform:uppercase|font-style:italic|linear-gradient|box-shadow/.test(css), 'no bar beside a block, no capitals, no italics, no gradient, no shadow in the new styles');
ok([...css.matchAll(/line-height:([\d.]+)/g)].every((m) => parseFloat(m[1]) >= 1.5 || /hdlw-r1-band|hero-title/.test(css.slice(css.lastIndexOf('}', m.index) + 1, m.index))), 'text lines are set at 1.5 or more');
const colours = [...new Set([...css.matchAll(/#[0-9a-fA-F]{3,6}\b/g)].map((m) => m[0].toLowerCase()))];
ok(colours.every((c) => ['#3d8da0', '#004f59', '#2c3e50', '#e4e6ea', '#f8f9fb', '#fafbfc', '#fff', '#ffffff'].includes(c)), 'colours are the brand\'s own (' + colours.join(' ') + ')');
ok(/p\.hdlw-r-offer-email a\{color:#004F59;\}/.test(css) && [...css.matchAll(/hdlw-r1-band\{font-size:(\d+)px/g)].every((m) => +m[1] >= 24),
  'contrast: the "Start again" link is dark teal on white (9.3 to 1); a heading on teal (3.8 to 1) is never under 24 px');
ok(!full.includes('—') && !full.includes('–'), 'no dash stands in for punctuation');

console.log('── T1.6 the states ──');
ok(text(frame({ first: '' })).includes('Hi,') && !text(frame({ first: '' })).includes('Hi ,'), 'no first name: "Hi,"');
const noFilm = frame({ film: false });
ok(!noFilm.includes('data-part="film"') && !text(noFilm).includes('explains your result'), 'no film for the band: no film block and no caption');
const noPrac = frame({ prac: '' });
ok(!text(noPrac).includes('explains your result') && !noPrac.includes('class="hdlw-r1-sign"') && noPrac.includes('data-part="film"') && count(noPrac, '<a ') === 2, 'no practitioner name: no caption and no sign-off; the film and both buttons stay');
const noOffer = frame({ offer: null });
ok(!text(noOffer).includes('but why') && count(noOffer, '<a ') === 0 && !noOffer.includes('-thanks') && !noOffer.includes('data-part="status"') && count(noOffer, 'data-part="next"') === 1
  && text(noOffer).includes(E.good), 'no offer (switched off, a practitioner\'s invite, already bought): today\'s own card takes the offer\'s place, with its own save line');
ok(!full.includes('data-part="next"'), 'with the offer, today\'s card is not also drawn');
const odd = frame({ band: '' });
ok(odd !== '' && Object.values(E).every((s) => !text(odd).includes(s.split('.')[0])) && text(odd).includes(D), 'a band the page does not name: no band sentence, the rest as drawn');
const all = [full, euro, noFilm, noPrac, noOffer, odd, frame({ band: 'avg' }), frame({ band: 'warn' })];
ok(all.every((h) => h !== '' && !/undefined|null|NaN/.test(text(h))), 'no state prints "undefined"');
ok(all.every((h) => !/0\.85|43\.4|\b51\b|Matthew/.test(h)) && !/0\.85|43\.4|\b51\b|Matthew/.test(words + fn + mover), 'none of the drawing\'s sample values (0.85, 43.4, 51, his name) is in the markup or the code');
ok(frame({ first: '<b>x', prac: '"><i>' }).indexOf('<b>x') < 0 && frame({ first: '<b>x', prac: '"><i>' }).indexOf('<i>') < 0, 'a name is escaped');

console.log('── T1.5 and how it is wired ──');
const rr = cut('    function renderResult() {', '\n    }\n');
ok(rr !== '' && !/oneColumn|hdlw-r1/.test(rr), 'renderResult knows nothing of this layout');
const pick = cut('      var renderers = [', '];\n');
const chosen = (on) => { const c = { cfg: { resultOneColumn: on }, renderResult: 'today', resultOneColumn: 'one column', out: null };
  ['renderQ1', 'renderQ2', 'renderQ3', 'renderQ4', 'renderQ5', 'renderQ6', 'renderQ7', 'renderQ8', 'renderQ9', 'renderContact'].forEach((n) => { c[n] = n; });
  try { vm.runInNewContext(pick.trim() + '\nout = renderers[10];', c); } catch (e) { return 'error'; } return c.out; };
ok(chosen(false) === 'today' && chosen(undefined) === 'today', 'switch absent: the result is drawn by renderResult itself');
ok(chosen(true) === 'one column', 'switch on: by resultOneColumn');
const wrap = cut('    function resultOneColumn() {', '\n    }\n');
ok(/^\s*function resultOneColumn\(\) \{\s*renderResult\(\);\s*oneColumnResult\(contentEl, \{/.test(wrap), 'which first lets renderResult draw and send as today, then arranges what it drew');
const cfgLine = cut('    cfg.resultOneColumn = ', ';');
const read = (publicCfg, invite) => { const c = { cfg: {}, publicCfg, invite }; try { vm.runInNewContext(cfgLine, c); } catch (e) { return 'error'; } return c.cfg.resultOneColumn; };
ok(read({ result_one_column: true }, null) === true && read(null, { result_one_column: true }) === true && read({}, null) === false && read(null, null) === false, 'cfg.resultOneColumn is read from both settings answers; absent is false');
ok(/hdlw-r-stat-note/.test(mover) && !/0\.9|1\.05|1\.1\b/.test(words + fn + mover + wrap), 'the band is the one renderResult drew (read from its figure), never worked out again');
ok(/getAttribute\('href'\)/.test(mover) && !/resultOfferHref|band_urls/.test(mover + wrap), 'the buy address is the drawn button\'s own');

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL === 0 ? 0 : 1);
