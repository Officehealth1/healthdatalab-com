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
 * v0.49.12 — the drawing's blocks, in its order (section F5): each of its two bands is the page's hero panel
 *   holding one heading, joined to its card; the gauge with the figures beside it; the greeting, the opening line
 *   and the band's line together in front of the film; two sample charts under the price line. The small label
 *   and the hero's sentences of v0.49.11 are gone, so the older checks that named them now name these parts.
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
const order = [A, 'data-part="gauge"', 'data-part="stats"', 'Hi ', 'Your results are in.', 'ageing slower', 'data-part="film"', 'explains your result', 'data-part="status"',
  '… but why?', 'Your number shows', 'Without the why', 'data-part="mail"', I, 'For a small investment of', 'data-part="note"', DOCTOR, '>Rosa<', 'Show me ($27)', INBOX, 'data-part="link"', 'data-part="foot"'];
const at = order.map((s) => body.indexOf(s));
ok(at.every((p, i) => p >= 0 && (i === 0 || p > at[i - 1])), 'the order of ideas: his heading, the number, the greeting, what it means, the film, "but why", the offer, the foot');

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
ok(/^<div class="hdlw-r-gauge-card hdlw-r1-block"><section class="hdlw-r-hero"><h1 class="hdlw-r-hero-title">[^<]+<\/h1><\/section>/.test(body) && !full.includes('hdlw-r-hero-pill'),
  'P1 the column opens with today\'s hero panel holding his heading as its serif title, and nothing else; no pill');
ok(count(body, '<div class="hdlw-r-card') === 2 && count(frame({ offer: null }), '<div class="hdlw-r-card') === 1, 'P1 two of today\'s cards with the offer (the result, "but why"), one without');
ok(body.includes('</section><div class="hdlw-r-card"><div class="hdlw-r1-result"><div class="hdlw-r1-gauge">'),
  'P1 the result card opens with the result itself, straight under its heading (the small label is left out: the heading says it)');
ok(body.includes('<div class="hdlw-r-hero"><h2 class="hdlw-r-hero-title">' + G + '</h2></div><div class="hdlw-r-card"><div class="hdlw-r1-read"'), 'P1 "' + G + '" is a heading in the page\'s hero panel (h2), straight over its card');
ok(body.includes('<div class="hdlw-r-stat-strip"><span data-part="stats"></span></div>'), 'P1 the three figures sit in today\'s figure strip');
ok(count(body, '<a class="hdlw-r-btn-primary" href="') === 2 && count(body, '<a ') === 2 && body.includes('<p class="hdlw-r-price">For a small investment of $27.</p>'), 'P1 both buttons are today\'s button, the price is today\'s price line');
const own = ['.hdlw-r-hero{', '.hdlw-r-hero-row{', '.hdlw-r-hero-greeting{', '.hdlw-r-hero-title{', '.hdlw-r-hero-body{', '.hdlw-r-card{', '.hdlw-r-card h2{', '.hdlw-r-card p{', '.hdlw-r-eyebrow{',
  '.hdlw-r-stat-strip{display:grid;grid-template-columns:repeat(3,1fr)', '.hdlw-r-stat{', '.hdlw-r-stat-label{', '.hdlw-r-stat-value{', '.hdlw-r-buttons{display:flex;flex-direction:column;align-items:center;', '.hdlw-r-btn-primary{', '.hdlw-r-card p.hdlw-r-price{'];
ok(own.every((r) => rr.includes(r)), 'P1 each of those parts is still styled by today\'s stylesheet (' + own.filter((r) => !rr.includes(r)).join(' ') + ')');
const grounds = rules.filter((r) => /background/.test(r.decl));
ok(!full.includes('hdlw-r1-band') && grounds.length === 1 && grounds[0].sel === '.hdlw-r1 .hdlw-r-hero' && rules.every((r) => !/\bh[12]\b/.test(r.sel) || !/background|padding:(?!0;)|border/.test(r.decl)),
  'P1 no coloured bar is used as a heading: the only ground set here is the hero\'s; a heading gets no ground and no box of its own');
ok(/\.hdlw-r1 \.hdlw-r-hero h1,\.hdlw-r1 \.hdlw-r-hero h2\{margin:0;padding:0;color:#fff;\}/.test(css), 'P1 both headings are set on the tag, white and with no space of their own: a host page\'s own heading colour, margin or padding cannot turn them dark or push the panel out of shape');

console.log('── P2 the buttons ──');
const groups = [...body.matchAll(/<div class="(hdlw-r-buttons[^"]*)">([\s\S]*?)<\/div>/g)].map((m) => ({ cls: m[1].split(' '), inner: m[2] }));
ok(groups.length === 2 && groups.every((g) => g.cls.includes('hdlw-r-buttons') && g.cls.includes('hdlw-r1-act') && count(g.inner, '<a class="hdlw-r-btn-primary" href="' + esc(HREF) + '">') === 1),
  'P2 each button is the one component, alone in one of this layout\'s two groups, to the same address');
ok(groups.length === 2 && groups[0].inner.startsWith('<span data-part="mail"></span><a ') && />For a small investment of \$27\.<\/p>$/.test(groups[0].inner) && />Rosa<\/p><a /.test(groups[1].inner) && groups[1].inner.endsWith('>' + INBOX + '</p>'),
  'P2 the small lines sit with their button: the e-mail line over the first and the price under it; the name over the second and the inbox line under it');
// Every rule here that can reach something to press, by its last part: a link, a button, or one of their classes.
const reach = (sel) => sel.split(',').some((x) => { const last = x.trim().split(/[\s>+~]+/).pop(); return /^(a|button)(?![\w-])/.test(last) || /btn-|hdlw-film-cover|hdlw-r-again|hdlw-buy/.test(last); });
const press = rules.filter((r) => reach(r.sel)).map((r) => r.sel + '{' + r.decl + '}').sort();
ok(JSON.stringify(press) === JSON.stringify(['.hdlw-r1 .hdlw-r-card p.hdlw-r-offer-email a{color:#004F59;}', '.hdlw-r1 .hdlw-r1-act a.hdlw-r-btn-primary{padding-left:12px;padding-right:12px;}', '.hdlw-r1 a:focus-visible{outline:2px solid #3d8da0;outline-offset:3px;}'].sort())
  && rules.every((r) => !/[*\[]|:(first|last|nth|only|not|is|where|has)/.test(r.sel)),
  'P2 three rules here can reach something to press and no more: the "Start again" link\'s colour, the same side space for BOTH of this layout\'s buttons (the longer label stays on one line on a 360 px phone), one ring for every link. Width, height, radius and centre stay the page\'s; a Timeline link\'s yes and no are reached by none (' + press.length + ')');
ok(rules.every((r) => !/hdlw-r1-(act|sign)$/.test(r.sel.trim()) || !/(^|;)(width|max-width|min-width|height|display|align-items|justify-content|float|left|right|transform|margin-left|margin-right|padding-left|padding-right):/.test(r.decl)), 'P2 no rule here moves or resizes a button\'s group');
ok(/\.hdlw-r1 \.hdlw-r1-sign\{margin:24px 0 0;padding:24px 0 0;border-top:1px solid #e4e6ea;\}/.test(css), 'P2 the sign-off group is set apart by a hairline over it, with no side space of its own');
ok(!/\.hdlw-r-buttons/.test(css) && rules.filter((r) => /hdlw-r1-act/.test(r.sel)).length >= 2,
  'the group rules reach this layout\'s own two groups only: an invite\'s card and a Timeline link\'s yes and no keep today\'s spacing and size');

ok(/\.hdlw-r1 \.hdlw-r-card p\.hdlw-r1-small,\.hdlw-r1 \.hdlw-r-card \.hdlw-r1-act p\{margin:0;font-size:13px;line-height:1\.5;color:#555;\}/.test(css) && rr.includes('.hdlw-r-card p.hdlw-r-price{font-size:13px;color:#888;margin:0;}'),
  'P2 the small lines\' grey outranks today\'s price-line rule (#888, 3.5 to 1) by its own weight, not by coming later on the page');

console.log('── P3 the text ──');
const states = [full, frame({ band: 'avg' }), frame({ band: 'warn' }), frame({ offer: null }), frame({ film: false }), frame({ prac: '' }), frame({ first: '' })];
ok(states.every((h) => h !== '' && !/<br\b/i.test(h)), 'P3 no line break between his sentences, in any state');
const cards = body.split('<div class="hdlw-r-card').slice(1);
ok(cards.length === 2 && cards.every((c) => count(c, 'class="hdlw-r1-lead"') === 1) && count(frame({ offer: null }), 'class="hdlw-r1-lead"') === 1, 'P3 in each card exactly one sentence carries the lead style');
ok(body.includes('<p>' + D + '</p><p class="hdlw-r1-lead">' + E.good.split('. ')[0] + '.</p><p>' + E.good.split('. ').slice(1).join('. ') + '</p></div>')
  && body.includes('<p class="hdlw-r1-lead">' + H1 + '</p><p>' + H2 + '</p>') && count(body, 'class="hdlw-r1-hi"') === 1,
  'P3 the heading, the one lead sentence, then the rest of his sentences as ONE paragraph; the greeting is one short line of its own');
const lead = rules.find((r) => /p\.hdlw-r1-lead$/.test(r.sel)) || { decl: '' };
ok(/\.hdlw-r1 \.hdlw-r-card p\{line-height:1\.6;\}/.test(css) && /font-size:17px/.test(lead.decl) && /font-weight:600/.test(lead.decl)
  && rules.every((r) => !/hdlw-r1-read p$/.test(r.sel) || !/font-size|color/.test(r.decl)), 'P3 body text keeps the page\'s size and grey at line height 1.6; only the lead is larger and darker');
const measure = rules.filter((r) => /(hdlw-r1-read p|hdlw-r-hero-body)$/.test(r.sel) && /max-width/.test(r.decl)).map((r) => +(/max-width:(\d+)em/.exec(r.decl) || [0, 0])[1]);
const leadEm = +(/max-width:(\d+)em/.exec(lead.decl) || [0, 0])[1];
ok(measure.length === 1 && measure.every((em) => em >= 30 && em <= 35) && leadEm >= 28 && leadEm <= 35 && /\.hdlw-r1 \.hdlw-r1-read\{[^}]*text-align:left;/.test(css)
  && rules.every((r) => !/hdlw-r1-(read|lead)|hdlw-r-hero-body/.test(r.sel) || !/text-align:(center|right|justify)/.test(r.decl)), 'P3 paragraphs are left-aligned and ' + measure.join(' and ') + ' em wide (the lead ' + leadEm + '): about 60 to 70 characters a line');
ok(body.includes('<span data-part="note"></span><p class="hdlw-r1-small">' + DOCTOR + '</p>') && body.includes('<span data-part="film"></span><p class="hdlw-r1-small hdlw-r1-cap">Rosa explains your result</p>'),
  'P3 a small line sits right under the thing it belongs to: the doctor line under the boxed note, the caption under the film');

console.log('── T1.1 one column ──');
const colW = +(/\.hdlw-r\.hdlw-r1\{[^}]*max-width:(\d+)px/.exec(css) || [0, 0])[1];
ok(colW >= 760 && colW <= 820, 'the column has one width, ' + colW + ' px (760 to 820)');
const inner = /\.hdlw-r1 \.hdlw-r-hero,\.hdlw-r1 \.hdlw-r-card\{padding-left:([^;]+);padding-right:([^;]+);\}/.exec(css);
ok(inner && inner[1] === inner[2] && /^clamp\(22px,calc\(\(100% - 560px\) \/ 2\),\d+px\)$/.test(inner[1]), 'the hero and every card share one inner width (560 px, less on a narrow screen), so there is one left edge and one centre');
const sideBySide = rules.filter((r) => /display:(flex|grid|inline)/.test(r.decl));
ok(sideBySide.length === 2 && sideBySide.every((r) => /^\.hdlw-r1 \.hdlw-r1-(result|charts)$/.test(r.sel) && /display:flex;flex-wrap:wrap;/.test(r.decl)) && !/float:|column-count|position:(absolute|fixed)|@media/.test(css),
  'two rows only are laid side by side, the gauge with its figures and the two charts, and both wrap by their own width; nothing is floated or pinned and no rule reads the screen');
ok(!/border-left|border-right|::before|::after|text-transform|font-style:italic|box-shadow/.test(css), 'no bar beside a block, no capitals, no italics, no shadow in the new styles');
ok(count(css, 'gradient') === 1 && /\.hdlw-r1 \.hdlw-r-hero\{background:linear-gradient\(135deg,#004F59 0%,#3d8da0 (\d+)%\);\}/.test(css), 'the one gradient is the hero\'s, made of the brand\'s two teals');
ok(rules.every((r) => [...r.decl.matchAll(/line-height:([\d.]+)/g)].every((m) => parseFloat(m[1]) >= 1.5 || /p\.hdlw-r-stat-value$/.test(r.sel))), 'text lines are set at 1.5 or more (the figure\'s number keeps the page\'s own 1.1)');
const colours = [...new Set([...css.matchAll(/#[0-9a-fA-F]{3,6}\b/g)].map((m) => m[0].toLowerCase()))];
ok(colours.every((c) => ['#3d8da0', '#004f59', '#2c3e50', '#e4e6ea', '#555', '#111', '#fff'].includes(c)) && ['color:#555', 'color:#111'].every((c) => rr.includes(c)), 'colours are the brand\'s own and the page\'s two text greys (' + colours.join(' ') + ')');
// Small white text on the hero. The lightest point of the gradient is where it ends (100% of a line that runs on to its
// last stop). The page's own soft highlight (.hdlw-r-hero::before, white at 0.12 fading out) lies over it and takes up
// to half a point off, so 5 to 1 is asked here; in a browser, at ten widths from 390 to 1440, every point of the panel
// then measured 4.5 or more (2026-11-27). At 130% the gradient gave 4.62 here and 4.34 under the words at 540 px.
const stop = +(/#3d8da0 (\d+)%\)/.exec(css) || [0, 100])[1], k = 100 / stop;
const lightest = hex('#004F59').map((v, i) => v + (hex('#3d8da0')[i] - v) * k);
ok(stop >= 100 && ratio([255, 255, 255], lightest) >= 5 && rr.includes('.hdlw-r-hero::before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 80% 20%,rgba(255,255,255,0.12),transparent 60%);')
  && !/hdlw-r-hero-(greeting|body)/.test(body),
  'contrast: white on the lightest point of the panel\'s gradient is ' + ratio([255, 255, 255], lightest).toFixed(2) + ' to 1 (5 asked, to leave room for the page\'s own highlight, pinned here), and the panel holds a heading only');
const inks = rules.flatMap((r) => [...r.decl.matchAll(/(?:^|;)color:([^;]+)/g)].map((m) => ({ sel: r.sel, c: m[1] })));
ok(inks.length >= 8 && inks.every((i) => /^#[0-9a-fA-F]{3,6}$/.test(i.c) && (i.c.toLowerCase() === '#fff' ? /^(\.hdlw-r1 \.hdlw-r-hero (h1|h2),?)+$/.test(i.sel) : ratio(hex(i.c), [255, 255, 255]) >= 4.5))
  && /p\.hdlw-r-offer-email a\{color:#004F59;\}/.test(css),
  'contrast: each of the ' + inks.length + ' text colours set here is 4.5 to 1 or more on white (white itself only on the two headings); the "Start again" link is dark teal, not the page\'s teal (3.8)');
const sizes = rules.flatMap((r) => [...r.decl.matchAll(/font-size:([^;]+)/g)].map((m) => m[1]));
ok(sizes.length >= 5 && sizes.every((v) => /^\d+px$/.test(v) && parseInt(v, 10) >= 12), 'no text set here is under 12 px (' + sizes.join(' ') + ')');
const crop = /\.hdlw-r1 \.hdlw-r-gauge-img\{max-width:440px;margin-top:-(\d+)%;\}/.exec(css);
ok(crop && +crop[1] <= 10 && /\.hdlw-r1 \.hdlw-r1-gauge\{[^}]*overflow:hidden;/.test(css), 'the gauge\'s frame cuts no more than a tenth of the picture\'s height off its empty top: the scale\'s top number stays in view');
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
{ const bad = '"><img src=x onerror=1>', x = frame({ id: 'hdlw-' + bad, offer: { href: 'https://alt.test/p?a=1&b=' + bad, today: '$1' + bad } }).replace(/<style>[\s\S]*?<\/style>/, '');
  ok(x !== '' && !x.includes('<img') && count(x, '&quot;&gt;&lt;img src=x onerror=1&gt;') === 5 && count(x, 'href="https://alt.test/p?a=1&amp;b=&quot;') === 2 && x.includes('<div id="hdlw-&quot;&gt;&lt;img src=x onerror=1&gt;-thanks" hidden>'),
    'the buy address (both buttons), the price (both places) and the id are escaped: markup in any of them stays text'); }

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
const BASE = 'https://w.test/wp-content/plugins/hdl-longevity-v2/assets/images/silhouettes/';
const handed = (name, prac, offer, base = BASE, ver) => { const c = { got: null, IMG_BASE: base, VER: ver, id: 'hdlw-206', contentEl: {}, answers: { _name: name }, cfg: { pracName: prac, resultOffer: offer, assetVer: ver }, renderResult: () => {}, oneColumnResult: (box, d) => { c.got = d; } };
  try { vm.runInNewContext(wrap + '\nresultOneColumn();', c); } catch (e) { return {}; } return c.got || {}; };
ok(handed('Ada Walker', 'Rosa Example', { today: '$27' }).first === 'Ada' && handed('Ada Walker', 'Rosa Example', { today: '$27' }).prac === 'Rosa' && handed('Ada', 'Rosa', { today: '\u20ac31' }).today === '\u20ac31' && handed('Ada', 'Rosa', null).today === '', 'first names and the offer\'s price are handed on; no offer, no price');
ok(['Dr Rosa Example', 'Dr. Rosa Example', 'dr rosa example', 'Prof. Rosa Example', 'Mrs Rosa Example', ' Ms Rosa'].every((n) => handed('Ada', n, null).prac.toLowerCase() === 'rosa') && handed('Ada', 'Drew Example', null).prac === 'Drew' && handed('Ada', '', null).prac === '', 'a title in front of the practitioner\'s name is not taken for the first name ("Dr explains your result"); "Drew" stays Drew');
{ const c = { id: 'x', IMG_BASE: BASE, contentEl: {}, answers: {}, cfg: {}, renderResult: () => {}, oneColumnResult: () => { throw new Error('boom'); }, threw: false };
  try { vm.runInNewContext(wrap + '\nresultOneColumn();', c); } catch (e) { c.threw = true; }
  ok(!c.threw, 'a failure in the arranging does not stop the page (the step arrows and the safety line still run)'); }
const cfgLine = cut('    cfg.resultOneColumn = ', ';');
const read = (publicCfg, invite) => { const c = { cfg: {}, publicCfg, invite }; try { vm.runInNewContext(cfgLine, c); } catch (e) { return 'error'; } return c.cfg.resultOneColumn; };
ok(read({ result_one_column: true }, null) === true && read(null, { result_one_column: true }) === true && read({}, null) === false && read(null, null) === false, 'cfg.resultOneColumn is read from both settings answers; absent is false');
ok(/hdlw-r-stat-note/.test(mover) && !/0\.9|1\.05|1\.1\b/.test(words + fn.slice(fn.indexOf("'</style>'")) + mover + wrap), 'the band is the one renderResult drew (read from its figure), never worked out again');
ok(/getAttribute\('href'\)/.test(mover) && !/resultOfferHref|band_urls/.test(mover + wrap), 'the buy address is the drawn button\'s own');

console.log('── F5 the drawing\'s blocks, in its order (Quim on the test site, 27 Nov) ──');
// Each of the drawing's two bands is a heading panel joined to its card; the result is the gauge beside the
// figures; the greeting, the opening line and the band's line sit together under it, in front of the film.
ok(count(body, 'hdlw-r1-block') === 2, 'two blocks, each a heading panel joined to a card');
ok(/<div class="hdlw-r-gauge-card hdlw-r1-block"><section class="hdlw-r-hero"><h1 class="hdlw-r-hero-title">How fast you are ageing\?<\/h1><\/section><div class="hdlw-r-card">/.test(body), 'block 1 opens with the heading panel alone: the title, no greeting and no sentence in it');
ok(/<div class="hdlw-r1-block"><div class="hdlw-r-hero"><h2 class="hdlw-r-hero-title">… but why\?<\/h2><\/div><div class="hdlw-r-card">/.test(body), '"… but why?" is a heading panel of the same kind, joined to its card');
ok(count(body, 'hdlw-r-hero-title') === 2 && !/hdlw-r-hero-greeting|hdlw-r-hero-body|hdlw-r-eyebrow/.test(body), 'both headings are the one component; no small label and no hero sentence is left');
ok(/<div class="hdlw-r1-result"><div class="hdlw-r1-gauge"><span data-part="gauge"><\/span><\/div><div class="hdlw-r-stat-strip"><span data-part="stats"><\/span><\/div><\/div>/.test(body), 'the result: the gauge, and the three figures beside it, in one row');
{ const row = rules.find((r) => r.sel === '.hdlw-r1 .hdlw-r1-result'), strip = rules.find((r) => r.sel === '.hdlw-r1 .hdlw-r-stat-strip');
  const gauge = rules.find((r) => r.sel === '.hdlw-r1 .hdlw-r1-gauge');
  ok(!!gauge && /(^|;)flex:1\.7 1 260px;/.test(gauge.decl) && !!strip && /(^|;)flex:1 1 170px;/.test(strip.decl), 'the gauge takes the wider share (from 260 px), the figures the narrower (from 170 px): side by side from 454 px of room, under each other below that');
  ok(/^margin:0 0 16px;text-align:left;$/.test((rules.find((r) => r.sel === '.hdlw-r1 .hdlw-r1-block') || { decl: '' }).decl), 'a block reads from the left: block 1 carries the class today\'s stylesheet centres (hdlw-r-gauge-card), and this rule is what outranks it');
  ok(!!row && /display:flex/.test(row.decl) && /flex-wrap:wrap/.test(row.decl) && !!strip && /grid-template-columns:1fr/.test(strip.decl) && !/@media/.test(css), 'the row wraps by its own width (no screen rule): on a narrow box the figures go under the gauge, one under the other'); }
{ const seq = ['data-part="gauge"', 'data-part="stats"', 'Hi Ada,', D, E.good.split('.')[0], 'data-part="film"', 'explains your result', G, H1, I];
  ok(seq.every((x) => body.includes(x)) && seq.every((x, i) => i === 0 || body.indexOf(x) > body.indexOf(seq[i - 1])), 'the drawing\'s order: result, greeting, opening line, the band\'s line, film, "but why", offer'); }
ok(body.includes('<div class="hdlw-r1-read"><p class="hdlw-r1-hi">Hi Ada,</p><p>' + D + '</p><p class="hdlw-r1-lead">' + E.good.split('. ')[0] + '.</p>'), 'the greeting, the opening line and the band\'s line are one run of paragraphs');
ok(frame({ first: '' }).includes('<p class="hdlw-r1-hi">Hi,</p>') && frame({ band: '' }).includes('<p>' + D + '</p></div>'), 'no first name: "Hi,"; an unknown band: the opening line ends the run');
{ const join = (sel) => (rules.find((r) => r.sel === sel) || { decl: '' }).decl;
  ok(/border-radius:14px 14px 0 0/.test(join('.hdlw-r1 .hdlw-r1-block>.hdlw-r-hero')) && /margin:0/.test(join('.hdlw-r1 .hdlw-r1-block>.hdlw-r-hero')) && /border-radius:0 0 14px 14px/.test(join('.hdlw-r1 .hdlw-r1-block>.hdlw-r-card')) && /border-top:0/.test(join('.hdlw-r1 .hdlw-r1-block>.hdlw-r-card')), 'panel and card are joined: one rounded block, no gap, no line between them'); }

console.log('── F5 the two sample charts ──');
const CH = { abilities: 'https://w.test/a.webp?ver=1', standing: 'https://w.test/s.webp?ver=1' };
const shown = frame({ charts: CH }), shownBody = shown.replace(/<style>[\s\S]*?<\/style>/, '');
ok(text(shown).includes('For a small investment of $27, this is what you get:') && !text(shown).includes('investment of $27.'), 'with the charts the price line reads as drawn: "…, this is what you get:"');
ok(text(full).includes('For a small investment of $27.') && !/<figure|<img|this is what you get/.test(body), 'without them: today\'s price line, and no picture at all');
ok(/<div class="hdlw-r1-charts"><figure><img src="https:\/\/w\.test\/a\.webp\?ver=1" alt="[^"]{12,}" width="540" height="540" loading="lazy"><figcaption>Your abilities<\/figcaption><\/figure><figure><img src="https:\/\/w\.test\/s\.webp\?ver=1" alt="[^"]{12,}" width="540" height="540" loading="lazy"><figcaption>Where you stand<\/figcaption><\/figure><\/div>/.test(shownBody), 'two pictures side by side with his two labels, each with a description, its size and lazy loading');
ok(shownBody.includes('</div><p class="hdlw-r1-small hdlw-r1-eg">Examples from a Full Report.</p>'), 'the pictures are named as examples (they are not this visitor\'s own)');
{ const at = (x) => shownBody.indexOf(x);
  ok(at('this is what you get:') > at(I) && at('hdlw-r1-charts') > at('this is what you get:') && at('data-part="note"') > at('hdlw-r1-charts') && count(shownBody, 'hdlw-r1-charts') === 1, 'they sit under the price line and over the boxed note'); }
ok(!/<figure|<img/.test(frame({ charts: CH, offer: null })), 'no offer: no charts');
{ const bad = frame({ charts: { abilities: 'x" onerror="alert(1)', standing: 'y"><script>' } });
  ok(!bad.includes('" onerror="') && !bad.includes('<script>') && bad.includes('x&quot; onerror=&quot;alert(1)'), 'a picture address is escaped'); }
{ const got = handed('Ada', 'Rosa', { today: '$27' }).charts || {}, none = handed('Ada', 'Rosa', { today: '$27' }, '');
  ok(got.abilities === 'https://w.test/wp-content/plugins/hdl-longevity-v2/assets/images/samples/full-report-abilities.webp?ver=' && got.standing === 'https://w.test/wp-content/plugins/hdl-longevity-v2/assets/images/samples/full-report-where-you-stand.webp?ver=', 'the pictures are the plugin\'s own files, beside its other pictures (no outside address)');
  ok(none && none.charts === null, 'the widget\'s own address unknown: no charts, the rest as usual');
  { const v = handed('Ada', 'Rosa', { today: '$27' }, BASE, '0.49.12 b&c').charts || {};
    ok(String(v.abilities).endsWith('/samples/full-report-abilities.webp?ver=0.49.12%20b%26c') && String(v.standing).endsWith('/samples/full-report-where-you-stand.webp?ver=0.49.12%20b%26c'), 'each picture\'s address carries the plugin\'s version, encoded: a new release is a new address'); }
  ok(fs.existsSync(new URL('../../assets/images/samples/full-report-abilities.webp', import.meta.url)) && fs.existsSync(new URL('../../assets/images/samples/full-report-where-you-stand.webp', import.meta.url)), 'both picture files ship with the plugin');
  ok(/charts: buy \? d\.charts : null/.test(mover) || /charts:\s*buy\s*&&\s*d\.charts/.test(mover), 'the mover hands the pictures on only with the offer'); }

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL === 0 ? 0 : 1);
