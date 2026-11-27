#!/usr/bin/env node
/**
 * v0.49.10 (plan 19; hdlv2_ff_stage1_result_one_column) — the Stage 1 result in one column, in the order
 * of Matthew's drawing of 26 November: the number, what it means, the film, "but why", the offer.
 * v0.49.11 (plan 19 fix v2) — the same words, made of the result page's own parts instead of the sketch's boxes.
 * The REAL oneColumnHtml is cut from widget/hdl-lead-magnet.js and run in vm. It is the half that holds
 * every word and the order; the other half (oneColumnResult) only moves the parts renderResult drew
 * into its marked places (test-one-column-dom.mjs; in a browser: tests/result-one-column/harness.html).
 *   P1 the page's own parts: its hero, cards, small label, serif card heading, figure strip, button and price
 *      line, each still styled by today's stylesheet; no coloured bar used as a heading.
 *   P2 both buttons are the one component in the one kind of group; no rule here sizes or moves a button.
 *   P3 his sentences run as paragraphs: no line break, one sentence a card stands out, body at 1.6.
 *   T1.1 one column: one width, one inner width for the hero and every card; the figures are today's strip.
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
// WCAG contrast of two colours given as [r, g, b]; hex('#3d8da0') reads one.
const hex = (h) => { const x = h.replace('#', ''); const f = x.length === 3 ? x.replace(/./g, '$&$&') : x; return [0, 2, 4].map((i) => parseInt(f.slice(i, i + 2), 16)); };
const lum = (c) => c.map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }).reduce((a, v, i) => a + v * [0.2126, 0.7152, 0.0722][i], 0);
const ratio = (a, b) => { const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x); return (hi + 0.05) / (lo + 0.05); };

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
const body = full.replace(/<style>[\s\S]*?<\/style>/, ''); // the markup without this layout's styles
const rules = [...css.matchAll(/([^{}]+)\{([^}]*)\}/g)].map((m) => ({ sel: m[1], decl: m[2] }));
const rr = cut('    function renderResult() {', '\n    }\n'); // today's page: its stylesheet and its words

const A = 'How fast you are ageing?';
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
ok(count(full, '>' + I + '</a>') === 1, 'i: the first button, "' + I + '"');
ok(count(full, '>Show me ($27)</a>') === 1 && />Rosa<\/p>/.test(full), 'm: the sign-off "Rosa" and the second button "Show me ($27)"');
// k and the two kept lines are today's: the boxed note moves in as drawn; the other two are typed again here.
const today = cut('  function resultOfferHtml(', '\n  }\n');
const was = { out: '' };
vm.runInNewContext(today + '\nout = resultOfferHtml({ today: "$27" }, "https://alt.test/x", function (s) { return s; }, "", false);', was);
ok(text(was.out).includes(K) && full.includes('data-part="note"'), 'k: the boxed note is today\'s, word for word, and has its place in the column');
ok([DOCTOR, INBOX].every((s) => text(was.out).includes(s) && t.includes(s)), 'kept: the doctor line and the inbox line read as they do today');
const order = ['Hi ', A, 'Your results are in.', 'Pace of Ageing', 'data-part="gauge"', 'data-part="stats"', 'ageing slower', 'data-part="film"', 'explains your result', 'data-part="status"',
  '… but why?', 'Your number shows', 'Without the why', 'data-part="mail"', I, 'For a small investment of', 'data-part="note"', DOCTOR, '>Rosa<', 'Show me ($27)', INBOX, 'data-part="link"', 'data-part="foot"'];
const at = order.map((s) => body.indexOf(s));
ok(at.every((p, i) => p >= 0 && (i === 0 || p > at[i - 1])), 'the order of ideas: the greeting and his heading, the number, what it means, the film, "but why", the offer, the foot');

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

console.log('── P1 the page\'s own parts ──');
ok(/^<section class="hdlw-r-hero"><div class="hdlw-r-hero-row"><div><p class="hdlw-r-hero-greeting">Hi Ada,<\/p><h1 class="hdlw-r-hero-title">[^<]+<\/h1><\/div><\/div><p class="hdlw-r-hero-body">[^<]+<\/p><\/section>/.test(body) && !full.includes('hdlw-r-hero-pill'),
  'P1 the hero is today\'s panel: the greeting as its small line, his heading as its serif title, his opening line under it; no pill');
ok(count(body, '<div class="hdlw-r-card') === 2 && count(frame({ offer: null }), '<div class="hdlw-r-card') === 1, 'P1 two of today\'s cards with the offer (the result, "but why"), one without');
ok(body.includes('<div class="hdlw-r-card hdlw-r-gauge-card"><span class="hdlw-r-eyebrow">Pace of Ageing</span>') && rr.includes('<span class="hdlw-r-eyebrow">Pace of Ageing</span>'),
  'P1 the result card opens with the page\'s small label, in today\'s own words');
ok(body.includes('<div class="hdlw-r-card"><h2>' + G + '</h2>'), 'P1 "' + G + '" is the card\'s own heading (the page\'s serif h2), inside the card');
ok(body.includes('<div class="hdlw-r-stat-strip"><span data-part="stats"></span></div>'), 'P1 the three figures sit in today\'s figure strip');
ok(count(body, '<a class="hdlw-r-btn-primary" href="') === 2 && count(body, '<a ') === 2 && body.includes('<p class="hdlw-r-price">For a small investment of $27.</p>'), 'P1 both buttons are today\'s button, the price is today\'s price line');
const own = ['.hdlw-r-hero{', '.hdlw-r-hero-row{', '.hdlw-r-hero-greeting{', '.hdlw-r-hero-title{', '.hdlw-r-hero-body{', '.hdlw-r-card{', '.hdlw-r-card h2{', '.hdlw-r-card p{', '.hdlw-r-eyebrow{',
  '.hdlw-r-stat-strip{display:grid;grid-template-columns:repeat(3,1fr)', '.hdlw-r-stat{', '.hdlw-r-stat-label{', '.hdlw-r-stat-value{', '.hdlw-r-buttons{display:flex;flex-direction:column;align-items:center;', '.hdlw-r-btn-primary{', '.hdlw-r-card p.hdlw-r-price{'];
ok(own.every((r) => rr.includes(r)), 'P1 each of those parts is still styled by today\'s stylesheet (' + own.filter((r) => !rr.includes(r)).join(' ') + ')');
const grounds = rules.filter((r) => /background/.test(r.decl));
ok(!full.includes('hdlw-r1-band') && grounds.length === 1 && grounds[0].sel === '.hdlw-r1 .hdlw-r-hero' && rules.every((r) => !/\bh[12]\b/.test(r.sel) || !/background|padding|border|color/.test(r.decl)),
  'P1 no coloured bar is used as a heading: the only ground set here is the hero\'s; a heading gets no ground, box or colour of its own');

console.log('── P2 the buttons ──');
const groups = [...body.matchAll(/<div class="(hdlw-r-buttons[^"]*)">([\s\S]*?)<\/div>/g)].map((m) => ({ cls: m[1].split(' '), inner: m[2] }));
ok(groups.length === 2 && groups.every((g) => g.cls.includes('hdlw-r-buttons') && g.cls.includes('hdlw-r1-act') && count(g.inner, '<a class="hdlw-r-btn-primary" href="' + esc(HREF) + '">') === 1),
  'P2 each button is the one component, alone in one of this layout\'s two groups, to the same address');
ok(groups.length === 2 && groups[0].inner.startsWith('<span data-part="mail"></span><a ') && />For a small investment of \$27\.<\/p>$/.test(groups[0].inner) && />Rosa<\/p><a /.test(groups[1].inner) && groups[1].inner.endsWith('>' + INBOX + '</p>'),
  'P2 the small lines sit with their button: the e-mail line over the first and the price under it; the name over the second and the inbox line under it');
ok(!/btn-(primary|secondary)/.test(css) && rules.every((r) => !/hdlw-r1-(act|sign)/.test(r.sel) || !/(^|;)(width|max-width|min-width|height|align-items|justify-content|float|left|right|transform|margin-left|margin-right|padding-left|padding-right):/.test(r.decl) || /\bp\b/.test(r.sel.split(' ').pop())),
  'P2 no rule here sizes, rounds or moves a button or its group: width, height, radius and centre are today\'s for both');
ok(/\.hdlw-r1 \.hdlw-r1-sign\{margin:24px 0 0;padding:24px 0 0;border-top:1px solid #e4e6ea;\}/.test(css), 'P2 the sign-off group is set apart by a hairline over it, with no side space of its own');
ok(!/\.hdlw-r-buttons/.test(css) && rules.filter((r) => /hdlw-r1-act/.test(r.sel)).length >= 2,
  'the group rules reach this layout\'s own two groups only: an invite\'s card and a Timeline link\'s yes and no keep today\'s spacing and size');
ok(/\.hdlw-r1 a:focus-visible\{outline:2px solid #3d8da0;outline-offset:3px;\}/.test(css), 'every link in the column shows the same ring when the keyboard reaches it');

console.log('── P3 the text ──');
const states = [full, frame({ band: 'avg' }), frame({ band: 'warn' }), frame({ offer: null }), frame({ film: false }), frame({ prac: '' }), frame({ first: '' })];
ok(states.every((h) => h !== '' && !/<br\b/i.test(h)), 'P3 no line break between his sentences, in any state');
const cards = body.split('<div class="hdlw-r-card').slice(1);
ok(cards.length === 2 && cards.every((c) => count(c, 'class="hdlw-r1-lead"') === 1) && count(frame({ offer: null }), 'class="hdlw-r1-lead"') === 1, 'P3 in each card exactly one sentence carries the lead style');
ok(body.includes('<div class="hdlw-r1-read"><p class="hdlw-r1-lead">' + E.good.split('. ')[0] + '.</p><p>' + E.good.split('. ').slice(1).join('. ') + '</p></div>')
  && body.includes('<p class="hdlw-r1-lead">' + H1 + '</p><p>' + H2 + '</p>') && body.includes('<p class="hdlw-r-hero-body">' + D + '</p>'),
  'P3 three levels and no more: the label or heading, the one lead sentence, then the rest of his sentences as ONE paragraph');
const lead = rules.find((r) => /p\.hdlw-r1-lead$/.test(r.sel)) || { decl: '' };
ok(/\.hdlw-r1 \.hdlw-r-card p\{line-height:1\.6;\}/.test(css) && /font-size:17px/.test(lead.decl) && /font-weight:600/.test(lead.decl)
  && rules.every((r) => !/hdlw-r1-read p$/.test(r.sel) || !/font-size|color/.test(r.decl)), 'P3 body text keeps the page\'s size and grey at line height 1.6; only the lead is larger and darker');
const measure = rules.filter((r) => /(hdlw-r1-read p|hdlw-r-hero-body)$/.test(r.sel) && /max-width/.test(r.decl)).map((r) => +(/max-width:(\d+)em/.exec(r.decl) || [0, 0])[1]);
ok(measure.length === 2 && measure.every((em) => em >= 30 && em <= 35) && /\.hdlw-r1 \.hdlw-r1-read\{[^}]*text-align:left;/.test(css), 'P3 paragraphs are left-aligned and ' + measure.join(' and ') + ' em wide: about 60 to 70 characters a line');
ok(body.includes('<span data-part="note"></span><p class="hdlw-r1-small">' + DOCTOR + '</p>') && body.includes('<span data-part="film"></span><p class="hdlw-r1-small hdlw-r1-cap">Rosa explains your result</p>'),
  'P3 a small line sits right under the thing it belongs to: the doctor line under the boxed note, the caption under the film');

console.log('── T1.1 one column ──');
const colW = +(/\.hdlw-r\.hdlw-r1\{[^}]*max-width:(\d+)px/.exec(css) || [0, 0])[1];
ok(colW >= 760 && colW <= 820, 'the column has one width, ' + colW + ' px (760 to 820)');
const inner = /\.hdlw-r1 \.hdlw-r-hero,\.hdlw-r1 \.hdlw-r-card\{padding-left:([^;]+);padding-right:([^;]+);\}/.exec(css);
ok(inner && inner[1] === inner[2] && /^clamp\(22px,calc\(\(100% - 560px\) \/ 2\),\d+px\)$/.test(inner[1]), 'the hero and every card share one inner width (560 px, less on a narrow screen), so there is one left edge and one centre');
ok(!/grid|flex|float:|column-count|position:(absolute|fixed)/.test(css) && /@media \(max-width:640px\)\{[^@]*\.hdlw-r-stat-strip\{grid-template-columns:1fr;\}/.test(rr),
  'nothing here is laid side by side: the one row of three is today\'s figure strip, which is one track on a phone');
ok(!/border-left|border-right|::before|::after|text-transform|font-style:italic|box-shadow/.test(css), 'no bar beside a block, no capitals, no italics, no shadow in the new styles');
ok(count(css, 'gradient') === 1 && /\.hdlw-r1 \.hdlw-r-hero\{background:linear-gradient\(135deg,#004F59 0%,#3d8da0 (\d+)%\);\}/.test(css), 'the one gradient is the hero\'s, made of the brand\'s two teals');
ok(rules.every((r) => [...r.decl.matchAll(/line-height:([\d.]+)/g)].every((m) => parseFloat(m[1]) >= 1.5 || /p\.hdlw-r-stat-value$/.test(r.sel))), 'text lines are set at 1.5 or more (the figure\'s number keeps the page\'s own 1.1)');
const colours = [...new Set([...css.matchAll(/#[0-9a-fA-F]{3,6}\b/g)].map((m) => m[0].toLowerCase()))];
ok(colours.every((c) => ['#3d8da0', '#004f59', '#2c3e50', '#e4e6ea', '#555', '#111'].includes(c)) && ['color:#555', 'color:#111'].every((c) => rr.includes(c)), 'colours are the brand\'s own and the page\'s two text greys (' + colours.join(' ') + ')');
// Small white text on the hero. The lightest point of the gradient is where it ends (100% of a line that runs on to its
// last stop). The page's own soft highlight (.hdlw-r-hero::before, white at 0.12 fading out) lies over it and takes up
// to half a point off, so 5 to 1 is asked here; in a browser, at ten widths from 390 to 1440, every point of the panel
// then measured 4.5 or more (2026-11-27). At 130% the gradient gave 4.62 here and 4.34 under the words at 540 px.
const stop = +(/#3d8da0 (\d+)%\)/.exec(css) || [0, 100])[1], k = 100 / stop;
const lightest = hex('#004F59').map((v, i) => v + (hex('#3d8da0')[i] - v) * k);
ok(stop >= 100 && ratio([255, 255, 255], lightest) >= 5 && rr.includes('.hdlw-r-hero::before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 80% 20%,rgba(255,255,255,0.12),transparent 60%);')
  && /\.hdlw-r1 \.hdlw-r-hero-greeting,\.hdlw-r1 \.hdlw-r-hero-body\{opacity:1;\}/.test(css),
  'contrast: white on the lightest point of the hero\'s gradient is ' + ratio([255, 255, 255], lightest).toFixed(2) + ' to 1 (5 asked, to leave room for the page\'s own highlight, pinned here), and its small text is not thinned');
ok([['#555', '#fff'], ['#111', '#fff'], ['#2c3e50', '#fff'], ['#004F59', '#fff']].every(([a, b]) => ratio(hex(a), hex(b)) >= 4.5) && /\.hdlw-r1 \.hdlw-r-eyebrow\{color:#004F59;\}/.test(css) && /p\.hdlw-r-offer-email a\{color:#004F59;\}/.test(css)
  && rules.every((r) => !/color:#(888|3d8da0)/i.test(r.decl)), 'contrast: every text colour set here is 4.5 to 1 or more on white; the small label and the "Start again" link are dark teal, not the page\'s teal (3.8)');
ok(!full.includes('—') && !full.includes('–'), 'no dash stands in for punctuation');

console.log('── T1.6 the states ──');
ok(text(frame({ first: '' })).includes('Hi,') && !text(frame({ first: '' })).includes('Hi ,'), 'no first name: "Hi,"');
const noFilm = frame({ film: false });
ok(!noFilm.includes('data-part="film"') && !text(noFilm).includes('explains your result'), 'no film for the band: no film block and no caption');
const noPrac = frame({ prac: '' }).replace(/<style>[\s\S]*?<\/style>/, '');
ok(!text(noPrac).includes('explains your result') && !noPrac.includes('hdlw-r1-name') && !noPrac.includes('hdlw-r1-cap') && noPrac.includes('data-part="film"') && count(noPrac, '<a ') === 2, 'no practitioner name: no caption and no name over the second button; the film and both buttons stay');
const noOffer = frame({ offer: null });
ok(!text(noOffer).includes('but why') && count(noOffer, '<a ') === 0 && !noOffer.includes('-thanks') && !noOffer.includes('data-part="status"') && count(noOffer, 'data-part="next"') === 1
  && text(noOffer).includes(E.good), 'no offer (switched off, a practitioner\'s invite, already bought): today\'s own card takes the offer\'s place, with its own save line');
ok(!full.includes('data-part="next"'), 'with the offer, today\'s card is not also drawn');
const odd = frame({ band: '' });
ok(odd !== '' && Object.values(E).every((s) => !text(odd).includes(s.split('.')[0])) && text(odd).includes(D), 'a band the page does not name: no band sentence, the rest as drawn');
const all = [full, euro, noFilm, noPrac, noOffer, odd, frame({ band: 'avg' }), frame({ band: 'warn' })];
ok(all.every((h) => h !== '' && !/undefined|null|NaN/.test(text(h))), 'no state prints "undefined"');
ok(all.every((h) => !/0\.85|43\.4|\b51\b|Matthew/.test(h)) && !/0\.85|43\.4|\b51\b|Matthew/.test(words + fn + mover), 'none of the drawing\'s sample values (0.85, 43.4, 51, his name) is in the markup or the code');
{ const x = frame({ first: '<b>x', prac: '"><i>' }); ok(x.includes('Hi &lt;b&gt;x,') && x.includes('&quot;&gt;&lt;i&gt; explains your result') && !x.includes('<b>x') && !x.includes('<i>'), 'a name is escaped'); }

console.log('── T1.5 and how it is wired ──');
ok(rr !== '' && !/oneColumn|hdlw-r1/.test(rr), 'renderResult knows nothing of this layout');
const pick = cut('      var renderers = [', '];\n');
const chosen = (on) => { const c = { cfg: { resultOneColumn: on }, renderResult: 'today', resultOneColumn: 'one column', out: null };
  ['renderQ1', 'renderQ2', 'renderQ3', 'renderQ4', 'renderQ5', 'renderQ6', 'renderQ7', 'renderQ8', 'renderQ9', 'renderContact'].forEach((n) => { c[n] = n; });
  try { vm.runInNewContext(pick.trim() + '\nout = renderers[10];', c); } catch (e) { return 'error'; } return c.out; };
ok(chosen(false) === 'today' && chosen(undefined) === 'today', 'switch absent: the result is drawn by renderResult itself');
ok(chosen(true) === 'one column', 'switch on: by resultOneColumn');
const wrap = cut('    function resultOneColumn() {', '\n    }\n');
ok(/^\s*function resultOneColumn\(\) \{\s*renderResult\(\);\s*try \{\s*oneColumnResult\(contentEl, \{/.test(wrap) && /\} catch \(e\) \{/.test(wrap), 'which first lets renderResult draw and send as today, then arranges what it drew; a failure there leaves today\'s page');
// The wrapper itself, run with stand-ins: what it hands the arranger.
const handed = (name, prac, offer) => { const c = { got: null, id: 'hdlw-206', contentEl: {}, answers: { _name: name }, cfg: { pracName: prac, resultOffer: offer }, renderResult: () => {}, oneColumnResult: (box, d) => { c.got = d; } };
  try { vm.runInNewContext(wrap + '\nresultOneColumn();', c); } catch (e) { return {}; } return c.got || {}; };
ok(handed('Ada Walker', 'Rosa Example', { today: '$27' }).first === 'Ada' && handed('Ada Walker', 'Rosa Example', { today: '$27' }).prac === 'Rosa' && handed('Ada', 'Rosa', { today: '\u20ac31' }).today === '\u20ac31' && handed('Ada', 'Rosa', null).today === '', 'first names and the offer\'s price are handed on; no offer, no price');
ok(['Dr Rosa Example', 'Dr. Rosa Example', 'dr rosa example', 'Prof. Rosa Example', 'Mrs Rosa Example', ' Ms Rosa'].every((n) => handed('Ada', n, null).prac.toLowerCase() === 'rosa') && handed('Ada', 'Drew Example', null).prac === 'Drew' && handed('Ada', '', null).prac === '', 'a title in front of the practitioner\'s name is not taken for the first name ("Dr explains your result"); "Drew" stays Drew');
{ const c = { id: 'x', contentEl: {}, answers: {}, cfg: {}, renderResult: () => {}, oneColumnResult: () => { throw new Error('boom'); }, threw: false };
  try { vm.runInNewContext(wrap + '\nresultOneColumn();', c); } catch (e) { c.threw = true; }
  ok(!c.threw, 'a failure in the arranging does not stop the page (the step arrows and the safety line still run)'); }
const cfgLine = cut('    cfg.resultOneColumn = ', ';');
const read = (publicCfg, invite) => { const c = { cfg: {}, publicCfg, invite }; try { vm.runInNewContext(cfgLine, c); } catch (e) { return 'error'; } return c.cfg.resultOneColumn; };
ok(read({ result_one_column: true }, null) === true && read(null, { result_one_column: true }) === true && read({}, null) === false && read(null, null) === false, 'cfg.resultOneColumn is read from both settings answers; absent is false');
ok(/hdlw-r-stat-note/.test(mover) && !/0\.9|1\.05|1\.1\b/.test(words + fn.slice(fn.indexOf("'</style>'")) + mover + wrap), 'the band is the one renderResult drew (read from its figure), never worked out again');
ok(/getAttribute\('href'\)/.test(mover) && !/resultOfferHref|band_urls/.test(mover + wrap), 'the buy address is the drawn button\'s own');

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL === 0 ? 0 : 1);
