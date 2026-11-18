#!/usr/bin/env node
/**
 * v0.48.83 (plan 2j, Part P) — Matthew's band films sell the $27 Full Report, so with the result-page
 * switch on (result_offer present) the film under the gauge is drawn only when the buy button is.
 * Switch off (no result_offer): byte for byte what 2c's base (8b64a25) draws.
 * The REAL widget pieces are cut and run in vm (test-result-card.mjs pattern); the button address
 * comes from the real `var offerHref = …` line, so the film and the button share one rule.
 * Run: node tests/result-offer/test-band-film-guard.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };

const BASE = '8b64a25';
const now = fs.readFileSync(new URL('../../widget/hdl-lead-magnet.js', import.meta.url), 'utf8');
const base = execFileSync('git', ['show', BASE + ':hdl-longevity-v2/widget/hdl-lead-magnet.js'],
  { cwd: fileURLToPath(new URL('.', import.meta.url)), encoding: 'utf8', maxBuffer: 8 << 20 });

const cutIn = (t, head, end) => { const at = t.indexOf(head); return at < 0 ? '' : t.slice(at, t.indexOf(end, at) + end.length); };
const fnsOf = (t) => ['safeBuyUrl', 'bandVideoHtml', 'resultOfferHref'].map((n) => cutIn(t, '  function ' + n + '(', '\n  }\n'));
/** The film expression under the gauge: the renderResult line that calls bandVideoHtml(bandKey, …). */
const filmOf = (t) => {
  const l = cutIn(t, '    function renderResult() {', '\n    }\n').split('\n').find((s) => s.includes('bandVideoHtml(bandKey, cfg.bandVideos)'));
  return l ? l.replace(/^\s*\+\s*/, '').replace(/\s+\/\/ v0\..*$/, '') : '';
};
const hrefOf = (t) => cutIn(t, '      var offerHref = ', ';\n').replace('var offerHref = ', '').replace(/;\n$/, '');

const SRC = { now: { fns: fnsOf(now), film: filmOf(now), href: hrefOf(now) }, base: { fns: fnsOf(base), film: filmOf(base), href: hrefOf(base) } };
ok(Object.values(SRC).every((s) => s.fns.every(Boolean) && s.film && s.href), 'the functions, the film line and the button line are found, now and at ' + BASE);

/** What the gauge card draws under the gauge, with `which` source's own pieces. */
const draw = (which, { fastInvite = false, band, bandVideos, resultOffer, email = 'ivy@example.test' }) => {
  const s = SRC[which];
  const c = { fastInvite, bandKey: band, answers: { _email: email }, cfg: { bandVideos, resultOffer }, out: undefined };
  vm.runInNewContext(s.fns.join('\n') + '\nvar offerHref = (' + s.href + ');\nout = (' + s.film + ');', c);
  return c.out;
};

const FILM = { green: '56wtwUpuClo', blue: 'vFsBCMeE2LQ', red: '0AJBhquS9tk' };
const FILMS = { green: 'https://youtu.be/' + FILM.green, blue: 'https://youtu.be/' + FILM.blue, red: 'https://youtu.be/' + FILM.red };
const OFFER = {
  today: '$27',
  band_urls: { green: 'https://alt.test/full-report-slower', blue: 'https://alt.test/full-report-average', red: 'https://alt.test/full-report-faster' },
  video_url: 'https://youtu.be/iiPyoM0unbY',
};
const count = (s, n) => String(s).split(n).length - 1;
const BANDS = ['green', 'blue', 'red'];

console.log('── A. switch on, a public visitor with the buy button: the film of their band ──');
for (const band of BANDS) {
  const out = draw('now', { band, bandVideos: FILMS, resultOffer: OFFER });
  ok(count(out, FILM[band]) === 1 && count(out, 'hdlw-band-video') === 1, 'A ' + band + ': ' + FILM[band] + ' once, one film box');
}
ok(count(draw('now', { band: 'red', bandVideos: {}, resultOffer: OFFER }), 'iiPyoM0unbY') === 1, 'A4 no band film set: the offer film, as 2c built it');

console.log('── B. switch on, a practitioner-invited person (fastInvite, no button): no film ──');
for (const band of BANDS) {
  ok(count(draw('now', { fastInvite: true, band, bandVideos: FILMS, resultOffer: OFFER }), 'hdlw-band-video') === 0, 'B ' + band + ': no hdlw-band-video');
}

console.log('── C. switch on, no button for any other reason: no film ──');
const noUrl = { today: '$27', band_urls: { green: OFFER.band_urls.green, blue: OFFER.band_urls.blue }, video_url: OFFER.video_url };
ok(count(draw('now', { band: 'red', bandVideos: FILMS, resultOffer: noUrl }), 'hdlw-band-video') === 0, 'C1 no address for the band');
ok(count(draw('now', { band: 'red', bandVideos: FILMS, resultOffer: { ...OFFER, band_urls: { red: 'http://alt.test/full-report-faster' } } }), 'hdlw-band-video') === 0, 'C2 a plain http address (refused by safeBuyUrl)');
ok(count(draw('now', { band: 'red', bandVideos: FILMS, resultOffer: { ...OFFER, band_urls: { red: 'javascript:alert(1)' } } }), 'hdlw-band-video') === 0, 'C3 a javascript: address');
ok(count(draw('now', { band: 'red', bandVideos: FILMS, resultOffer: { today: '$27' } }), 'hdlw-band-video') === 0, 'C4 no band_urls at all');

console.log('── D. switch off (no result_offer): equal to ' + BASE + ' byte for byte ──');
let same = 0, total = 0;
for (const band of BANDS) for (const fastInvite of [false, true]) for (const bandVideos of [FILMS, {}, null]) {
  total++;
  const a = draw('now', { fastInvite, band, bandVideos, resultOffer: null });
  const b = draw('base', { fastInvite, band, bandVideos, resultOffer: null });
  if (a === b) same++; else console.log('    differs: ' + band + ' fastInvite=' + fastInvite + ' videos=' + JSON.stringify(bandVideos));
}
ok(same === total, 'D ' + same + '/' + total + ' (3 bands × public and invited × films, none, no map) equal to the base');
ok(count(draw('now', { fastInvite: true, band: 'red', bandVideos: FILMS, resultOffer: null }), FILM.red) === 1, 'D2 switch off, invited: the band film stays (today\'s page, unchanged)');

console.log('\nSCENARIO: ' + (FAIL ? 'FAIL' : 'PASS') + ' (' + PASS + '/' + (PASS + FAIL) + ')');
process.exit(FAIL ? 1 : 0);
