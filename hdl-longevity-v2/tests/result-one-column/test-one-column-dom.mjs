#!/usr/bin/env node
/**
 * v0.49.10 (plan 19; hdlv2_ff_stage1_result_one_column) — the half that MOVES things: oneColumnResult.
 * The REAL oneColumnResult, oneColumnHtml and resultOfferHtml are cut from widget/hdl-lead-magnet.js and run in
 * vm on a small hand-rolled DOM (no framework, as in tests/action-unify/scenario-v1-badge-suppress.mjs). The page
 * it arranges is a stand-in for what renderResult writes, with the same classes and ids; check N pins that every
 * class and id the mover looks for is still written by today's code, so a rename there fails here.
 *   M1 with the offer: the same nodes (gauge, figures, film, save line, e-mail line, boxed note, Book, foot) end up
 *      in the column; the notes under the figures are gone; a new "-thanks" holds both buttons with the drawn
 *      button's own address; no marked place is left; no id is twice on the page.
 *   M2 "hidden" follows the drawn card (saved() may already have run).
 *   M3 without the offer, and M4 on a practitioner's invite: today's own card moves in whole.
 *   M5 nothing drawn, an id that is not a clean word, a frame that cannot be written: no throw, today's page whole.
 * Run: node tests/result-one-column/test-one-column-dom.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };

// ── a small DOM: elements, attributes, text; innerHTML for well-formed markup; class, attribute and descendant selectors ──
const unesc = (s) => s.replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
class El {
  constructor(tag) { this.tagName = tag.toUpperCase(); this.attrs = {}; this.childNodes = []; this.parentNode = null; }
  get className() { return this.attrs.class || ''; } set className(v) { this.attrs.class = String(v); }
  get id() { return this.attrs.id || ''; }
  get hidden() { return 'hidden' in this.attrs; } set hidden(v) { if (v) this.attrs.hidden = ''; else delete this.attrs.hidden; }
  getAttribute(n) { return n in this.attrs ? this.attrs[n] : null; }
  insertBefore(c, ref) { if (c.parentNode) c.parentNode.removeChild(c); this.childNodes.splice(ref ? this.childNodes.indexOf(ref) : this.childNodes.length, 0, c); c.parentNode = this; return c; }
  removeChild(c) { const i = this.childNodes.indexOf(c); if (i < 0) throw new Error('not a child'); this.childNodes.splice(i, 1); c.parentNode = null; return c; }
  replaceChild(n, o) { this.insertBefore(n, o); return this.removeChild(o); }
  set innerHTML(html) { this.childNodes.forEach((c) => { if (c instanceof El) c.parentNode = null; }); this.childNodes = []; parse(String(html), this); }
  get textContent() { return this.childNodes.map((c) => (c instanceof El ? (c.tagName === 'STYLE' ? '' : c.textContent) : c)).join(''); }
  all() { return this.childNodes.filter((c) => c instanceof El).flatMap((c) => [c, ...c.all()]); }
  querySelectorAll(sel) { return this.all().filter((e) => matches(e, sel.trim().split(/\s+/))); }
  querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
}
const one = (e, part) => {
  const m = /^([a-z0-9]+)?((?:\.[\w-]+|\[[^\]]+\])*)$/.exec(part);
  if (!m) throw new SyntaxError('selector not understood: ' + part);
  if (m[1] && e.tagName !== m[1].toUpperCase()) return false;
  return (m[2].match(/\.[\w-]+|\[[^\]]+\]/g) || []).every((t) => {
    if (t[0] === '.') return (' ' + e.className + ' ').includes(' ' + t.slice(1) + ' ');
    const a = /^\[([\w-]+)(?:(\$?=)"([^"]*)")?\]$/.exec(t);
    if (!a) throw new SyntaxError('selector not understood: ' + t);
    const v = e.getAttribute(a[1]);
    return v !== null && (!a[2] || (a[2] === '=' ? v === a[3] : v.endsWith(a[3])));
  });
};
const matches = (e, chain) => { if (!one(e, chain[chain.length - 1])) return false; let up = e.parentNode;
  for (let i = chain.length - 2; i >= 0; i--) { while (up && !one(up, chain[i])) up = up.parentNode; if (!up) return false; up = up.parentNode; } return true; };
function parse(html, root) {
  let at = root; const re = /<style>([\s\S]*?)<\/style>|<\/([a-z0-9]+)>|<([a-z0-9]+)((?:\s+[\w-]+(?:="[^"]*")?)*)\s*\/?>|([^<]+)/g; let m;
  while ((m = re.exec(html))) {
    if (m[1] !== undefined) { const s = new El('style'); s.childNodes = [m[1]]; at.insertBefore(s, null); }
    else if (m[2]) { if (at.tagName !== m[2].toUpperCase()) throw new Error('markup not well formed at </' + m[2] + '>'); at = at.parentNode; }
    else if (m[3]) { const e = new El(m[3]); for (const a of m[4].matchAll(/([\w-]+)(?:="([^"]*)")?/g)) e.attrs[a[1]] = unesc(a[2] || ''); at.insertBefore(e, null); if (!/^(img|br|hr|input)$/.test(m[3])) at = e; }
    else at.childNodes.push(unesc(m[5]));
  }
  if (at !== root) throw new Error('markup not well formed: <' + at.tagName + '> is open');
}

// ── the real functions ──
const js = fs.readFileSync(new URL('../../widget/hdl-lead-magnet.js', import.meta.url), 'utf8');
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const src = [cut('  var ONE_COLUMN_BAND = ', '\n  };\n'), cut('  function oneColumnHtml(', '\n  }\n'), cut('  function oneColumnResult(', '\n  }\n'), cut('  function resultOfferHtml(', '\n  }\n')];
ok(src.every(Boolean), 'the band sentences, oneColumnHtml, oneColumnResult and resultOfferHtml are found in the widget');
const esc = (s) => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const ctx = { document: { createElement: (t) => new El(t) }, escHtml: esc, location: { href: 'https://host.test/report/start?signed=1' } };
vm.createContext(ctx); vm.runInContext(src.join('\n'), ctx);
const HREF = 'https://alt.test/full-report-average#email=ada%40example.test&own=b1234567890.' + 'ab'.repeat(32);

/** A stand-in for the page renderResult writes: the same classes and ids, the REAL offer card. */
const page = ({ id = 'hdlw-206', offer = true, saved = false, invite = false, book = false, film = true, link = false, band = 'avg' } = {}) => {
  const stat = (label, value, cls, note) => '<div class="hdlw-r-stat"><p class="hdlw-r-stat-label">' + label + '</p><p class="hdlw-r-stat-value">' + value + '</p><p class="hdlw-r-stat-note ' + cls + '">' + note + '</p></div>';
  const bookBtn = book ? '<a class="hdlw-r-btn-secondary" href="https://book.test/">Book a session</a>' : '';
  const box = new El('div');
  box.innerHTML = '<style>.hdlw-r{color:#111;}</style><div class="hdlw-r">'
    + '<section class="hdlw-r-hero"><h1 class="hdlw-r-hero-title">Your Quick Insight</h1></section>'
    + '<div class="hdlw-r-twocol"><div class="hdlw-r-twocol-left">'
    +   '<div class="hdlw-r-card hdlw-r-gauge-card"><h2>Your Health Gauge</h2><img class="hdlw-r-gauge-img" src="https://chart.test/g" alt="gauge">'
    +     (film ? '<div class="hdlw-band-video"><iframe src="https://film.test/"></iframe></div>' : '') + '</div>'
    +   stat('Rate of Ageing', '1.02', band, 'On average') + stat('Biological Age', '53.0 yrs', band, '+1 yrs') + stat('Chronological Age', '52 yrs', 'muted', 'Your real age')
    + '</div><div class="hdlw-r-twocol-right">'
    +   '<div class="hdlw-r-card"><h2>What This Means For You</h2><p>Three paragraphs.</p></div>'
    +   '<div class="hdlw-r-card">' + (invite
          ? '<h2>What Happens Next</h2><div class="hdlw-r-buttons"><div id="' + id + '-continue" class="hdlw-r-buttons-continue">Sending your results</div>' + bookBtn + '</div>'
          : '<div id="' + id + '-status" class="hdlw-r-status">Sending your answers</div><div id="' + id + '-thanks"' + (saved ? '' : ' hidden') + '>'
            + (offer ? ctx.resultOfferHtml({ today: '$27' }, HREF, esc, 'ada@example.test', true) : '<h2 class="hdlw-r-thanks-headline">Thank you.</h2><p class="hdlw-r-thanks-body">Your practitioner has been sent your data.</p>')
            + (book ? '<div class="hdlw-r-buttons">' + bookBtn + '</div>' : '') + '</div>') + '</div>'
    +   (link ? '<div id="' + id + '-link" class="hdlw-r-card" hidden></div>' : '')
    + '</div></div>'
    + '<div class="hdlw-r-prac-foot"><div id="' + id + '-sent" hidden><div class="hdlw-r-foot-line">saved</div></div><hr class="hdlw-r-foot-divider"></div>'
    + '</div>';
  return box;
};
const run = (box, id = 'hdlw-206') => { try { ctx.oneColumnResult(box, { id, first: 'Ada', prac: 'Rosa', today: '$27' }); return null; } catch (e) { return e; } };
const ids = (box, id) => box.querySelectorAll('[id]').filter((e) => e.id === id).length;
const inside = (node, root) => { for (let n = node; n; n = n.parentNode) if (n === root) return true; return false; };

console.log('── M1. with the offer ──');
{
  const box = page({ book: true, link: true }), old = box.querySelector('.hdlw-r');
  const keep = { gauge: old.querySelector('.hdlw-r-gauge-img'), film: old.querySelector('.hdlw-band-video'), status: old.querySelector('.hdlw-r-status'), mail: old.querySelector('.hdlw-r-offer-email'),
    note: old.querySelector('.hdlw-r-offer-note'), book: old.querySelector('.hdlw-r-btn-secondary'), foot: old.querySelector('.hdlw-r-prac-foot'), link: old.querySelectorAll('[id]').find((e) => e.id === 'hdlw-206-link') };
  const stats = old.querySelectorAll('.hdlw-r-stat'), drawn = old.querySelector('a.hdlw-r-btn-primary').getAttribute('href');
  const err = run(box), col = box.querySelector('.hdlw-r1');
  ok(err === null && col && box.querySelectorAll('.hdlw-r').length === 1 && old.parentNode === null, 'M1.1 one column takes the old page\'s place; nothing throws');
  ok(Object.values(keep).every((n) => n && inside(n, col)) && stats.length === 3 && stats.every((n) => inside(n, col) && n.parentNode.className === 'hdlw-r1-figs'), 'M1.2 the SAME nodes are in the column: gauge, three figures, film, save line, e-mail line, boxed note, Book, link card, foot');
  ok(col.querySelectorAll('.hdlw-r-stat-note').length === 0 && col.querySelectorAll('[data-part]').length === 0, 'M1.3 the notes under the figures are gone; no marked place is left');
  const thanks = col.querySelector('[id$="-thanks"]'), buys = thanks.querySelectorAll('a.hdlw-r-btn-primary');
  ok(thanks.hidden === true && buys.length === 2 && buys.every((a) => a.getAttribute('href') === drawn) && drawn === HREF, 'M1.4 a new hidden "-thanks" holds both buttons, each with the drawn button\'s address, character for character');
  ok(['thanks', 'status', 'sent', 'link'].every((n) => ids(box, 'hdlw-206-' + n) === 1), 'M1.5 each id is on the page once');
  const order = [keep.gauge, stats[0], keep.film, keep.status, thanks, keep.link, keep.foot].map((n) => col.all().indexOf(n));
  ok(order.every((p, i) => p >= 0 && (i === 0 || p > order[i - 1])) && inside(keep.mail, thanks) && inside(keep.note, thanks) && inside(keep.book, thanks) && !inside(keep.status, thanks), 'M1.6 in reading order: gauge, figures, film, save line, the offer, link card, foot');
  ok(col.textContent.includes('at about the population average') && !col.textContent.includes('ageing slower') && !col.textContent.includes('What This Means For You') && !col.textContent.includes('Do you know what is creating your number'), 'M1.7 the band is the drawn figure\'s (average); the old card and the old offer words are left out');
  ok(col.textContent.includes('Rosa explains your result') && col.textContent.includes('Hi Ada,'), 'M1.8 the caption and the greeting take the names they are handed');
}

console.log('── M2. "hidden" follows the drawn card ──');
{ const box = page({ saved: true }); run(box); ok(box.querySelector('[id$="-thanks"]').hidden === false, 'M2.1 already saved when arranged: the offer shows'); }
for (const band of ['good', 'warn']) { const box = page({ band }); run(box); ok(box.textContent.includes(band === 'good' ? 'ageing slower' : 'ageing faster'), 'M2.2 ' + band + ': its own sentence'); }
{ const box = page({ film: false }); run(box); ok(!box.querySelector('.hdlw-band-video') && !box.textContent.includes('explains your result') && box.querySelectorAll('[data-part]').length === 0, 'M2.3 no film drawn: no film, no caption, no empty place'); }

console.log('── M3. without the offer; M4. a practitioner\'s invite ──');
for (const [label, opts, mark] of [['M3 the offer off', { offer: false }, 'Thank you.'], ['M4 a practitioner\'s invite', { invite: true, book: true }, 'What Happens Next']]) {
  const box = page(opts), old = box.querySelector('.hdlw-r');
  const hold = old.querySelectorAll('[id]').find((e) => /-(status|continue)$/.test(e.id)), card = hold.parentNode.className === 'hdlw-r-card' ? hold.parentNode : hold.parentNode.parentNode;
  const err = run(box), col = box.querySelector('.hdlw-r1');
  ok(err === null && col && inside(card, col) && inside(hold, card) && col.textContent.includes(mark), label + ': today\'s own card moves in whole, with its save line');
  ok(!col.textContent.includes('but why') && col.querySelectorAll('a.hdlw-r-btn-primary').length === 0 && col.querySelectorAll('[data-part]').length === 0 && col.querySelectorAll('h2').length === 1, label + ': no "but why", no buy button');
}

console.log('── M5. when it cannot work ──');
{ const box = new El('div'); box.innerHTML = '<div class="hdlw-contact">the contact step</div>'; ok(run(box) === null && box.textContent === 'the contact step', 'M5.1 no result drawn: nothing happens'); }
{ const box = page({ id: 'hdlw-2.5' }); const err = run(box, 'hdlw-2.5'); ok(err === null && box.querySelector('.hdlw-r1') && ids(box, 'hdlw-2.5-thanks') === 1, 'M5.2 an id that is no clean word for a selector (hdlw-2.5): arranged all the same'); }
{ const box = page({}), before = box.all().length, keep = ctx.oneColumnHtml; ctx.oneColumnHtml = () => { throw new Error('cannot write the frame'); };
  const err = run(box); ctx.oneColumnHtml = keep;
  ok(err !== null && box.all().length === before && box.querySelectorAll('.hdlw-r-stat-note').length === 3 && !box.querySelector('.hdlw-r1'), 'M5.3 a failure comes before anything moves: today\'s page is whole (the caller catches it)'); }

console.log('── N. what the mover looks for is what today\'s code writes ──');
const rr = cut('    function renderResult() {', '\n    }\n') + src[3] + cut('  function bandVideoHtml(', '\n  }\n');
for (const name of ['hdlw-r-stat-note', 'hdlw-r-gauge-img', 'hdlw-r-stat"', 'hdlw-band-video', 'hdlw-r-price', 'hdlw-r-btn-primary', 'hdlw-r-btn-secondary', 'hdlw-r-offer-email', 'hdlw-r-offer-note', 'hdlw-r-prac-foot', 'hdlw-r-card', "-status'", "-thanks'", "-continue'", "-link'", '<div class="hdlw-r">']) {
  ok(rr.includes(name), 'N ' + name.replace(/["']/g, '') + ' is still written by renderResult, resultOfferHtml or bandVideoHtml');
}

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL === 0 ? 0 : 1);
