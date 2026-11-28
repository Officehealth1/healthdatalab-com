#!/usr/bin/env node
/**
 * v0.48.77 (plan 2c) — the Stage 1 result page's thank-you card sells the Full Report like the
 * e-mail when the server sends result_offer (hdlv2_ff_result_offer), and the Healthspan Timeline
 * teaser is gone in every state. The REAL widget source is cut and run in vm (the
 * tests/healthspan-timeline/test-widget-teaser.mjs pattern).
 * Run: node tests/result-offer/test-result-card.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';
import { undo2c } from './render-result-2c.mjs';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };

const js = fs.readFileSync(new URL('../../widget/hdl-lead-magnet.js', import.meta.url), 'utf8');
/** The source from `head` to the first `end` after it (inclusive), or '' when `head` is absent. */
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const esc = (s) => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const fns = ['safeBuyUrl', 'bandVideoHtml', 'resultOfferHref', 'resultOfferHtml'].map((n) => cut('  function ' + n + '(', '\n  }\n'));
const cfgLine = cut('    cfg.resultOffer = ', ';');
const hrefLine = cut('      var offerHref = ', ';\n');
const hero = cut('\'<p class="hdlw-r-hero-body">', "+ '</p>'");
const video = cut('( cfg.resultOffer && !offerHref', ": '') )"); // v0.48.83 (2j): the guard in front, test-band-film-guard.mjs
const thanks = cut("( offerHref\n                  ? resultOfferHtml(", "another email.</p>' )");
ok(fns.every(Boolean) && cfgLine && hrefLine && hero && video && thanks, 'the four functions, the cfg line, the href line, the hero, the video and the card are found in the widget');

/** Run `expr` with the four real functions and `ctx` in scope. */
const run = (expr, ctx = {}) => { const c = Object.assign({ escapeHtml: esc, out: undefined }, ctx); vm.runInNewContext(fns.join('\n') + '\nout = (' + expr + ');', c); return c.out; };

const OFFER = {
  today: '$27',
  band_urls: { green: 'https://alt.test/full-report-slower', blue: 'https://alt.test/full-report-average', red: 'https://alt.test/full-report-faster' },
  video_url: 'https://youtu.be/iiPyoM0unbY',
};

console.log('── T. no Timeline teaser, no Timeline address, in any state ──');
ok(!/Open my timeline|See your years beside|hdlw-timeline-teaser|timelineUrl|timelineHref|timeline_url|healthspan-timeline/.test(js), 'T1 the widget holds no teaser words, class, config field or Timeline address');

console.log('── C. where result_offer is read ──');
const cfgOf = (pub, inv) => { const c = { publicCfg: pub, invite: inv, cfg: {} }; vm.runInNewContext(cfgLine + ';', c); return c.cfg.resultOffer; };
ok((cfgOf({ result_offer: OFFER }, null) || {}).today === '$27', 'C1 from the public config');
ok(cfgOf(null, { result_offer: OFFER }).today === '$27', 'C2 from the invite answer');
ok(cfgOf({}, null) === null, 'C3 neither: null');

console.log('── H. the button address ──');
const hrefOf = (fastInvite, offer, band, email) => run(hrefLine.replace('var offerHref = ', '').replace(/;\n$/, ''), { fastInvite, cfg: { resultOffer: offer }, bandKey: band, answers: { _email: email } });
ok(hrefOf(false, OFFER, 'red', 'ivy+x@example.test') === 'https://alt.test/full-report-faster#email=ivy%2Bx%40example.test', 'H1 red: the faster page + #email= encoded');
ok(hrefOf(false, OFFER, 'green', 'ivy@example.test') === 'https://alt.test/full-report-slower#email=ivy%40example.test', 'H2 green: the slower page');
ok(hrefOf(false, OFFER, 'blue', 'not an email') === 'https://alt.test/full-report-average', 'H3 a bad e-mail: the band page alone');
ok(hrefOf(false, OFFER, 'blue', 'a@b.c'.padStart(255, 'x')) === 'https://alt.test/full-report-average', 'H4 an e-mail over 254 characters: the band page alone');
ok(hrefOf(true, OFFER, 'red', 'ivy@example.test') === '', 'H5 the practitioner-invited (fast invite) path: no offer');
ok(hrefOf(false, null, 'red', 'ivy@example.test') === '', 'H6 no result_offer (switch off): no offer');
ok(hrefOf(false, { today: '$27', band_urls: { red: 'javascript:alert(1)' } }, 'red', 'ivy@example.test') === '', 'H7 a javascript: address is refused by safeBuyUrl');
ok(hrefOf(false, { today: '$27', band_urls: { red: 'http://alt.test/full-report-faster' } }, 'red', 'ivy@example.test') === '', 'H8 a plain http: address is refused');
ok(hrefOf(false, { today: '$27' }, 'red', 'ivy@example.test') === '', 'H9 no band_urls: no offer');

console.log('── K. the card ──');
const card = (offerHref, offer = OFFER, email, inviteToken = '') => run(thanks, { offerHref, cfg: { resultOffer: offer }, answers: { _email: email }, inviteToken });
const TODAY = '<h2 class="hdlw-r-thanks-headline">Thank you.</h2><p class="hdlw-r-thanks-body">Your practitioner has been sent your data. Once they have looked at your data, you will receive another email.</p>';
ok(card('') === TODAY, 'K1 switch off: today\'s thank-you words, exactly');
const on = card('https://alt.test/full-report-faster#email=ivy%40example.test');
const ORDER = [
  '<h2 class="hdlw-r-thanks-headline">Do you know what is creating your number?</h2>',
  '<p>On its own, a number changes nothing.</p>',
  '<p>The Full Report shows what pulls yours up and what pulls it down.</p>',
  '<p>Advice online is written for everyone. This is written about you.</p>',
  '<a class="hdlw-r-btn-primary" href="https://alt.test/full-report-faster#email=ivy%40example.test">Yes, give me access to my Full Report</a>',
  '<p class="hdlw-r-price">For a small investment of $27.</p>',
  '<p class="hdlw-r-offer-note"><strong>Important:</strong> this number is an estimate. No test can measure anyone\'s exact rate of ageing. Use it like a compass, not a GPS: a direction and a place to start.</p>',
  '<p class="hdlw-r-offer-quote"><strong>From you, a few decades on:</strong> Thank you for doing this when you did. I\'m so glad I\'m not the one sitting out of things. I\'m so glad I\'m not the one everyone has to help. You started early, and I\'m the one who got to enjoy it.</p>',
  '<p>None of this replaces your doctor. Keep them in the loop.</p>',
  '<p>Your free report is on its way to your inbox.</p>',
];
const at = ORDER.map((s) => on.indexOf(s));
ok(at.every((p, i) => p >= 0 && (i === 0 || p > at[i - 1])), 'K2 switch on: § 6.1 in order (got positions ' + at.join(',') + ')');
ok(on.indexOf('Thank you.</h2>') < 0 && on.indexOf('Your practitioner has been sent your data') < 0, 'K3 switch on: the old heading and practitioner line are gone');
ok(!/target=/.test(on), 'K4 the button opens in the same tab');
ok(card('https://alt.test/x"><b>', { today: '<i>$27</i>' }).indexOf('href="https://alt.test/x&quot;&gt;&lt;b&gt;"') > 0
  && card('https://alt.test/x', { today: '<i>$27</i>' }).indexOf('&lt;i&gt;$27&lt;/i&gt;') > 0, 'K5 the address and the price are escaped');
ok(!/Timeline|timeline/.test(on + TODAY), 'K6 no Timeline word in either card');
// Gate row F25: above Buy, the address the report goes to (Stripe locks it), and a way to start again. Nothing stored.
const LOCKED = 'https://alt.test/x#email=ivy%40example.test'; // Stripe locks the address only when the link carries it
const mailOn = card(LOCKED, OFFER, 'ivy@example.test');
const LINE = '<p class="hdlw-r-offer-email">Your Full Report will be sent to <strong>ivy@example.test</strong>. Not your address? <a href="">Start again</a></p>';
// Walk W1 (2026-11-19): an empty href follows Altituding's <base href="/"> to the home page. The link names this page.
const PAGE = { href: 'https://alt.test/report/start?signed=1#hdl' };
const again = run("resultOfferHtml(OFFER, LOCKED, escapeHtml, 'ivy@example.test', true)", { OFFER, LOCKED, location: PAGE });
ok(again.indexOf('Not your address? <a href="https://alt.test/report/start?signed=1">Start again</a>') > 0, 'K10 Start again names this page (no hash), never an empty href');
ok(/\.hdlw-r-card p\.hdlw-r-offer-email a\{[^}]*text-decoration:underline/.test(js), 'K11 Start again is drawn as a link');
ok(mailOn.indexOf(LINE) > 0 && mailOn.indexOf(LINE) < mailOn.indexOf('hdlw-r-btn-primary'), 'K7 the e-mail line sits above the button');
ok(card('https://alt.test/x#email=a', OFFER, 'a<b>@x.test').indexOf('<strong>a&lt;b&gt;@x.test</strong>') > 0, 'K8 the address is escaped');
// Review 2026-11-19: no line when Stripe will not lock the address; no "Start again" on a ticket (it reloads a used link).
ok(card('https://alt.test/x', OFFER, 'ivy@example.test').indexOf('hdlw-r-offer-email') < 0, 'K12 the link does not lock the e-mail: no line (it would be untrue)');
const ticket = card(LOCKED, OFFER, 'ivy@example.test', 'a'.repeat(64));
ok(ticket.indexOf('Your Full Report will be sent to <strong>ivy@example.test</strong>.</p>') > 0 && ticket.indexOf('Start again') < 0, 'K13 on a ticket the address shows, without "Start again"');
ok(on.indexOf('hdlw-r-offer-email') < 0 && card('', OFFER, 'ivy@example.test') === TODAY, 'K9 no address, or switch off: no line, today\'s words exactly');

console.log('── E. the hero sentence ──');
const heroOf = (offerHref) => run(hero, { offerHref, cfg: {} }); // v0.49.16: the hero line reads cfg.resultWords (absent here: the old words)
ok(heroOf('') === '<p class="hdlw-r-hero-body">Here’s your starting picture, built from your 9 quick answers. Next, your <em>WHY</em> — what really matters to you — turns this snapshot into a plan that’s truly yours.</p>', 'E1 switch off: both sentences, unchanged');
ok(heroOf('https://alt.test/x') === '<p class="hdlw-r-hero-body">Here’s your starting picture, built from your 9 quick answers.</p>', 'E2 switch on: the first sentence only');

console.log('── V. the video under the gauge ──');
const vid = (offerHref, bandVideos) => run(video, { offerHref, bandKey: 'red', cfg: { bandVideos, resultOffer: OFFER } });
ok(/embed\/K2rQSjwvTws/.test(vid('x', { red: 'https://youtu.be/K2rQSjwvTws' })), 'V1 the band has a video: the band video');
ok(/embed\/iiPyoM0unbY/.test(vid('x', {})), 'V2 no band video and the offer on: the offer film (the e-mail\'s rule)');
ok(vid('', {}) === '', 'V3 no band video and the offer off: nothing, as today');

console.log('── R. nothing else in renderResult ──');
const rr = cut('    function renderResult() {', '\n    }\n');
ok(rr !== '' && undo2c(rr) !== rr, 'R1 renderResult is 2c\'s base (2526b9a) with only the 2c edits (render-result-2c.mjs)');

console.log('\nSCENARIO: ' + (FAIL ? 'FAIL' : 'PASS') + ' (' + PASS + '/' + (PASS + FAIL) + ')');
process.exit(FAIL ? 1 : 0);
