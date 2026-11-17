#!/usr/bin/env node
/**
 * Plan 3 (v0.48.82), P4 — the card after the Full Report form is sent. The REAL renderThankYou()
 * and its Timeline helpers are cut from assets/js/hdlv2-staged-form.js and run in vm (the
 * tests/result-offer/test-result-card.mjs pattern). Three states: a buyer without the Timeline
 * (the block), an owner (one line), everyone else (the base's card, byte for byte).
 * Run: node tests/timeline-upgrade/test-thankyou-card.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';
import crypto from 'node:crypto';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const js = fs.readFileSync(new URL('../../assets/js/hdlv2-staged-form.js', import.meta.url), 'utf8');
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const fns = ['renderThankYou', 'timelineBlockHtml', 'timelineVideoHtml'].map((n) => cut('  function ' + n + '(', '\n  }\n'));
ok(fns.every(Boolean), 'renderThankYou, timelineBlockHtml and timelineVideoHtml are found in the script');

// The card's own helpers, as small stand-ins: the test is about the block, not the shell.
const esc = (s) => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
const render = (data) => {
  const root = { innerHTML: '' };
  const ctx = {
    root, serverData: null, esc,
    deriveFirstName: () => 'Kim', setRootClasses: () => {},
    thankYouHeader: () => '<header>H</header>', thankYouFooter: () => '<footer>F</footer>',
  };
  vm.runInNewContext(fns.join('\n') + '\nrenderThankYou(DATA);', Object.assign(ctx, { DATA: data }));
  return root.innerHTML;
};
const BASE = { client_name: 'Kim Lane', client_email: 'kim@example.test', practitioner_name: 'Pat', practitioner_email: 'pat@example.test', practitioner_logo_url: '' };
const OFFER = { price: '$10', worth: '$27', ends: '24 November 2026', url: 'https://buy.stripe.example.test/upgrade?locked_prefilled_email=kim%40example.test', video_url: '' };
const md5 = (s) => crypto.createHash('md5').update(s).digest('hex');
// renderThankYou of the base (8b64a25) with BASE and these stand-ins.
const BASE_MD5 = 'b10b49d874a2cde4caec264750ca57f6';

console.log('── A. a buyer without the Timeline ──');
let h = render(Object.assign({}, BASE, { timeline_offer: OFFER }));
ok(h.includes('Who are you staying strong for?') && h.includes('Your Healthspan Timeline puts your years beside theirs, on one picture.'), 'A1 O1\'s heading and line');
ok(h.includes('Upgrade for $10 until 24 November 2026. On its own it is $27.'), 'A2 the offer line with the date');
ok(h.includes('>Yes, upgrade me to the Timeline · $10</a>'), 'A3 the button words');
ok(/<a class="hdlv2-btn hdlv2-ty-timeline-btn" href="https:\/\/buy\.stripe\.example\.test\/upgrade\?locked_prefilled_email=kim%40example\.test" target="_blank" rel="noopener noreferrer">/.test(h), 'A4 the button opens Stripe in a new tab, the e-mail locked');
ok(h.includes('Estimates, not predictions.'), 'A5 the small line copied from the Timeline page');
ok(h.indexOf('hdlv2-ty-timeline') > h.indexOf('</ol>') && h.indexOf('hdlv2-ty-timeline') < h.indexOf('<footer>'), 'A6 under the card\'s body, above its footer');
ok(!/youtube/.test(h), 'A7 no film set: no film box');
ok(!/Your Healthspan Timeline is yours/.test(h), 'A8 no owner line');

console.log('── B. after the date ──');
h = render(Object.assign({}, BASE, { timeline_offer: Object.assign({}, OFFER, { price: '$27', ends: '', url: 'https://buy.stripe.example.test/alone' }) }));
ok(h.includes('Your Healthspan Timeline is $27.') && !/until/.test(h), 'B1 "$27", no date');
ok(h.includes('>Yes, give me my Healthspan Timeline · $27</a>'), 'B2 the button words');

console.log('── C. the film ──');
const film = (u) => render(Object.assign({}, BASE, { timeline_offer: Object.assign({}, OFFER, { video_url: u }) }));
ok(film('https://youtu.be/abcdefghijk').includes('src="https://www.youtube-nocookie.com/embed/abcdefghijk"'), 'C1 a youtu.be link → the no-cookie player');
ok(film('https://www.youtube.com/watch?v=abcdefghijk&t=3').includes('/embed/abcdefghijk"'), 'C2 a watch link → the same');
ok(film('abcdefghijk').includes('/embed/abcdefghijk"'), 'C3 a bare id → the same');
ok(!/iframe/.test(film('https://evil.example.test/abcdefghijk')) && !/iframe/.test(film('javascript:alert(1)')), 'C4 anything else: no film');
ok(film('https://youtu.be/abcdefghijk').indexOf('<iframe') < film('https://youtu.be/abcdefghijk').indexOf('Who are you staying strong for?'), 'C5 the film sits first in the block');

console.log('── D. addresses ──');
ok(!/hdlv2-ty-timeline/.test(render(Object.assign({}, BASE, { timeline_offer: Object.assign({}, OFFER, { url: 'javascript:alert(1)' }) }))), 'D1 a javascript: address → no block at all');
ok(!/hdlv2-ty-timeline/.test(render(Object.assign({}, BASE, { timeline_offer: Object.assign({}, OFFER, { url: 'http://buy.stripe.example.test/x' }) }))), 'D2 a plain http: address → no block');
h = render(Object.assign({}, BASE, { timeline_offer: Object.assign({}, OFFER, { url: 'https://buy.stripe.example.test/u?a=1"><img src=x>', price: '<b>$10</b>' }) }));
ok(!h.includes('"><img') && h.includes('&quot;&gt;&lt;img') && !h.includes('<b>$10</b>'), 'D3 the address and the price are escaped');

console.log('── E. an owner ──');
h = render(Object.assign({}, BASE, { timeline_owned: true }));
ok(h.includes('Your Healthspan Timeline is yours. It opens when your Full Report lands.'), 'E1 the one line');
ok(h.indexOf('Your Healthspan Timeline is yours') > h.indexOf('</ol>') && !/hdlv2-ty-timeline/.test(h) && !/\$/.test(h), 'E2 under the list, no block, no price');

console.log('── F. everyone else ──');
ok(BASE_MD5 === md5(render(BASE)), 'F1 no Timeline keys → the base\'s card, byte for byte');
ok(BASE_MD5 === md5(render(Object.assign({}, BASE, { timeline_offer: null, timeline_owned: false }))), 'F2 empty Timeline keys → the same');

console.log('── G. the send passes the answer on ──');
const c3 = cut('  function completeStage3(', '\n  }\n');
ok(/res\.timeline_offer/.test(c3) && /res\.timeline_owned/.test(c3), 'G1 completeStage3 hands the answer\'s timeline_offer / timeline_owned to the card');

console.log('── H. styles ──');
const css = fs.readFileSync(new URL('../../assets/css/hdlv2-form.css', import.meta.url), 'utf8');
const blk = css.slice(css.indexOf('.hdlv2-ty-timeline'), css.indexOf('/* end timeline upgrade */'));
ok(blk.length > 20 && /background:\s*#f8f9fb/.test(blk), 'H1 the block has the soft fill');
ok(!/border-left|::before/.test(blk), 'H2 no accent rail');
ok(!/#[0-9a-f]{3,6}/gi.test(blk.replace(/#f8f9fb|#004f59|#2c3e50|#3d8da0|#ffffff|#e4e6ea|#666666/gi, '')), 'H3 no new colour (only the card\'s own)');

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
