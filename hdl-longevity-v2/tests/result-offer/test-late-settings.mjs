#!/usr/bin/env node
/**
 * v0.48.89 (gate row A10) — the public settings answer (/widget/public-config) can land after the
 * widget's cut-off. The widget is built from the embed's attributes by then; the late answer
 * must still bring the result page's Full Report button and band film, and nothing else.
 * A10b — the cut-off is 5 s (it was 1.5 s): an answer that takes 3 s must build the widget WITH its
 * settings, so a slow line does not skip the safety screen or the question pictures.
 * The REAL widget pieces are cut and run in vm (test-result-card.mjs pattern) on a hand-wound clock:
 * pullPublicConfig, the public path's call in init(), accessScreen, buildWidget's two cfg lines and
 * its late fill, and renderResult's button, film and card lines.
 * Run: node tests/result-offer/test-late-settings.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };

const js = fs.readFileSync(new URL('../../widget/hdl-lead-magnet.js', import.meta.url), 'utf8');
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const esc = (s) => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const fns = ['bandVideoHtml', 'accessScreen', 'safeBuyUrl', 'resultOfferHref', 'resultOfferHtml', 'pullPublicConfig'].map((n) => cut('  function ' + n + '(', '\n  }\n'));
const wire = cut('          pullPublicConfig(el, function (publicCfg', '\n          });\n'); // init(), the public path
const sig = cut('  function buildWidget(', ') {');
const cfgLines = ['safetyScreen', 'questionMotion', 'bandVideos', 'resultOffer'].map((k) => cut('    cfg.' + k + ' = ', ';'));
const fill = cut('    if (late) late.fill = ', '\n    };\n'); // '' before the fix
const hrefLine = cut('      var offerHref = ', ';\n');
const film = cut('( cfg.resultOffer && !offerHref', ": '') )");
const card = cut("( offerHref\n                  ? resultOfferHtml(", "another email.</p>' )");
ok(fns.every(Boolean) && wire && sig && cfgLines.every(Boolean) && hrefLine && film && card, 'the six functions, the init call, the cfg lines and the result lines are found in the widget');

const FILM = { green: '56wtwUpuClo', blue: 'vFsBCMeE2LQ', red: '0AJBhquS9tk' };
const PAGE = { green: 'slower', blue: 'average', red: 'faster' };
// The whole answer, as the server sends it: every display setting differs from the embed's attributes.
const CONFIG = {
  access_mode: 'paid', practitioner_name: 'From the server', theme_color: '#123456', cta_link: 'https://late.test/book',
  show_book_button_after_widget: true, safety_screen_enabled: true, question_motion_enabled: true,
  band_videos: { green: 'https://youtu.be/' + FILM.green, blue: 'https://youtu.be/' + FILM.blue, red: 'https://youtu.be/' + FILM.red },
  result_offer: {
    today: '$27', video_url: 'https://youtu.be/iiPyoM0unbY',
    band_urls: { green: 'https://alt.test/full-report-slower', blue: 'https://alt.test/full-report-average', red: 'https://alt.test/full-report-faster' },
  },
};
const EMBED = { pracName: 'From the embed', color: '#3d8da0', ctaLink: '#', showBookOnPublic: false };
const count = (s, n) => String(s).split(n).length - 1;
const tick = () => new Promise((r) => setImmediate(r));

/**
 * One page load. `at` = when the settings request settles (ms); `reply` = its Response, or null for a
 * network failure. The widget's own timer and the request run on this clock; to(ms) winds it on.
 */
const load = async ({ at, reply }) => {
  let now = 0;
  const timers = [];
  const page = { built: [], locked: 0, cfg: null, threw: null };
  const ctx = {
    el: { getAttribute: (k) => ({ 'data-practitioner-id': '206', 'data-api': 'https://wp.test/wp-json/hdl-v2/v1/widget/lead' })[k] || '' },
    setTimeout: (fn, ms) => { const t = { at: now + ms, fn, on: true }; timers.push(t); return t; },
    clearTimeout: (t) => { if (t) t.on = false; },
    fetch: (url) => { page.url = url; return new Promise((res, rej) => ctx.setTimeout(() => (reply ? res(reply) : rej(new Error('offline'))), at)); },
    showLocked: () => { page.locked++; },
    page, EMBED, escapeHtml: esc,
  };
  // buildWidget, cut down to what A10 touches: the real signature, the real cfg lines, the real late fill.
  const src = fns.join('\n')
    + '\n' + sig + '\n    var cfg = Object.assign({}, EMBED);\n    ' + cfgLines.join('\n    ') + '\n' + fill
    + '\n    page.built.push(publicCfg); page.cfg = cfg;\n  }\n'
    + wire;
  try { vm.runInNewContext(src, ctx); } catch (e) { page.threw = e; }
  page.to = async (ms) => {
    for (;;) {
      const due = timers.filter((t) => t.on && t.at <= ms).sort((a, b) => a.at - b.at)[0];
      if (!due) break;
      now = due.at; due.on = false;
      try { due.fn(); } catch (e) { page.threw = e; }
      await tick(); await tick();
    }
    now = ms; await tick();
  };
  /** The result page for `band`, with renderResult's own lines: the button address, the film, the card. */
  page.result = (band, email = 'ivy@example.test') => {
    const c = { cfg: page.cfg, fastInvite: false, inviteToken: NO_INVITE, bandKey: band, answers: { _email: email }, escapeHtml: esc, out: undefined };
    vm.runInNewContext(fns.join('\n') + '\nvar offerHref = (' + hrefLine.replace('var offerHref = ', '').replace(/;\n$/, '') + ');\nout = { href: offerHref, film: (' + film + '), card: (' + card + ') };', c);
    return c.out;
  };
  return page;
};
const answer = (config) => ({ ok: true, json: () => Promise.resolve({ config }) });
const NO_INVITE = ''; // a public visitor: no personal link
const THANKS = 'Your practitioner has been sent your data';
const BUTTON = 'Yes, give me access to my Full Report';

console.log('── T1. the settings answer takes 7 seconds, past the cut-off; the visitor then answers the nine questions ──');
{
  const p = await load({ at: 7000, reply: answer(CONFIG) });
  await p.to(4999);
  ok(p.built.length === 0, 'T1.0 the widget waits 5 seconds for its settings: nothing is built at 4999 ms');
  await p.to(5000);
  ok(p.built.length === 1 && p.built[0] === null && p.cfg.resultOffer === null && p.cfg.bandVideos === null, 'T1.1 at 5000 ms the widget is built from the embed, with no offer and no film yet');
  await p.to(7000);
  ok(p.built.length === 1 && p.locked === 0, 'T1.2 the late answer builds nothing again and locks nothing (its access_mode says paid)');
  ok(p.cfg.resultOffer === CONFIG.result_offer && p.cfg.bandVideos === CONFIG.band_videos, 'T1.3 the late answer fills result_offer and band_videos');
  ok(Object.keys(EMBED).every((k) => p.cfg[k] === EMBED[k]) && p.cfg.safetyScreen === false && p.cfg.questionMotion === false
    // v0.49.08 (plan 16): the film's cover is read on the result page too, so the late answer fills its address ('' here: no switch).
    && p.cfg.filmCover === ''
    && Object.keys(p.cfg).sort().join() === Object.keys(EMBED).concat('bandVideos', 'resultOffer', 'filmCover', 'safetyScreen', 'questionMotion').sort().join(), 'T1.4 and only those (with the film cover\'s address): name, colour, booking link, the Book switch, the safety screen and the pictures stay as built');
  await p.to(90000);
  for (const band of ['green', 'blue', 'red']) {
    const r = p.result(band);
    const want = 'https://alt.test/full-report-' + PAGE[band] + '#email=ivy%40example.test';
    ok(r.href === want && count(r.card, 'href="' + want + '"') === 1 && count(r.card, BUTTON) === 1 && !r.card.includes(THANKS), 'T1.5 ' + band + ': the Full Report button, to the band\'s page with the visitor\'s e-mail');
    ok(count(r.film, FILM[band]) === 1 && count(r.film, 'hdlw-band-video') === 1, 'T1.6 ' + band + ': the band film ' + FILM[band] + ', once');
  }
  ok(p.threw === null, 'T1.7 nothing throws');
}

console.log('── T4 (A10b). the settings answer takes 3 seconds: inside the wait, so the widget has its settings ──');
{
  const open = Object.assign({}, CONFIG, { access_mode: 'open' });
  const p = await load({ at: 3000, reply: answer(open) });
  await p.to(2999);
  ok(p.built.length === 0 && p.locked === 0, 'T4.1 nothing is built while the answer is on its way (2999 ms)');
  await p.to(3000);
  ok(p.built.length === 1 && p.built[0] === open, 'T4.2 at 3000 ms the widget is built once, with the whole answer');
  ok(!!p.cfg && p.cfg.safetyScreen === true && p.cfg.questionMotion === true, 'T4.3 the safety screen and the question pictures are on, as the server says');
  await p.to(90000);
  const r = p.cfg ? p.result('red') : { href: '', film: '', card: '' };
  ok(r.href === 'https://alt.test/full-report-faster#email=ivy%40example.test' && count(r.card, BUTTON) === 1 && count(r.film, FILM.red) === 1, 'T4.4 the result has the Full Report button and the band film');
  ok(p.built.length === 1 && p.threw === null, 'T4.5 nothing is built again at 5000 ms; nothing throws');
}

console.log('── T2. the answer comes in time: as before ──');
{
  const p = await load({ at: 300, reply: answer(CONFIG) });
  await p.to(300);
  ok(p.built.length === 0 && p.locked === 1, 'T2.1 an in-time answer still decides the access mode (paid: the locked card, no widget)');
  const open = Object.assign({}, CONFIG, { access_mode: 'open' });
  const q = await load({ at: 300, reply: answer(open) });
  await q.to(90000);
  ok(q.built.length === 1 && q.built[0] === open && q.locked === 0, 'T2.2 built once, with the whole answer, and not again at the cut-off');
  const late = await load({ at: 7000, reply: answer(open) });
  await late.to(90000);
  ok(['green', 'blue', 'red'].every((b) => JSON.stringify(q.result(b)) === JSON.stringify(late.result(b))), 'T2.3 button, film and card are byte for byte the same whether the answer was in time or late');
  ok(/^ {12}buildWidget\(el, null, publicCfg, late\);$/m.test(wire) && !/late/.test(cut('  function inviteFailed(', '\n  }\n')), 'T2.4 only the public path hands the late slot on; the invite paths do not');
}

console.log('── T3. the settings request fails ──');
for (const [label, opts] of [
  ['the network fails before the cut-off', { at: 200, reply: null }],
  ['the network fails after the cut-off', { at: 8000, reply: null }],
  ['the server answers 500 in time', { at: 200, reply: { ok: false, json: () => Promise.reject(new Error('no body')) } }],
  ['the server answers 500 late', { at: 7000, reply: { ok: false, json: () => Promise.reject(new Error('no body')) } }],
  ['a late answer with no config in it', { at: 7000, reply: { ok: true, json: () => Promise.resolve({}) } }],
  ['a late answer that is not JSON', { at: 7000, reply: { ok: true, json: () => Promise.reject(new SyntaxError('bad json')) } }],
  ['a late answer with the offer switched off', { at: 7000, reply: answer({ access_mode: 'open', practitioner_name: 'From the server' }) }],
]) {
  const p = await load(opts);
  await p.to(90000);
  const r = p.threw ? { href: '?', film: '?', card: '?' } : p.result('red');
  ok(p.threw === null && p.built.length === 1 && p.built[0] === null && p.locked === 0, 'T3 ' + label + ': nothing throws; one widget, from the embed');
  ok(r.href === '' && r.film === '' && r.card.includes(THANKS) && !r.card.includes(BUTTON), 'T3 ' + label + ': the thank-you card, no button, no film');
}

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL === 0 ? 0 : 1);
