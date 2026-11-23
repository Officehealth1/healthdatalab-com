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
// v0.48.96 (plan 12): the scripts name a report through rn(); with hdlv2_ff_report_names off it prints today's words.
const RN_OFF = 'function rn(key, today) { return today; }\n';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const js = fs.readFileSync(new URL('../../assets/js/hdlv2-staged-form.js', import.meta.url), 'utf8');
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const fns = ['renderThankYou', 'timelineBlockHtml', 'timelineVideoHtml'].map((n) => cut('  function ' + n + '(', '\n  }\n'));
ok(fns.every(Boolean), 'renderThankYou, timelineBlockHtml and timelineVideoHtml are found in the script');

// The card's own helpers, as small stand-ins: the test is about the block, not the shell.
const esc = (s) => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
// v0.48.92 (plan 8): the picture helper and the switch. CFG {} = the switch absent.
const PIC_SRC = [cut('  var S1_IMG_BASE = ', '\n  })();\n'), cut('  var FINISH_PICTURE = ', ';\n'), cut('  function motionFigure(', '\n  }\n')].join('\n');
const PLUGIN = 'https://example.test/wp-content/plugins/hdl-longevity-v2/';
const render = (data, cfg) => {
  const root = { innerHTML: '' };
  const ctx = {
    root, serverData: null, esc, CFG: Object.assign({ plugin_url: PLUGIN, asset_ver: '0.48.92' }, cfg || {}),
    window: { hdlv2_form: { plugin_url: PLUGIN } }, document: { getElementsByTagName: () => [] },
    deriveFirstName: () => 'Kim', setRootClasses: () => {},
    thankYouHeader: () => '<header>H</header>', thankYouFooter: () => '<footer>F</footer>',
  };
  vm.runInNewContext(RN_OFF + PIC_SRC + '\n' + fns.join('\n') + '\nrenderThankYou(DATA);', Object.assign(ctx, { DATA: data }));
  return root.innerHTML;
};
const BASE = { client_name: 'Kim Lane', client_email: 'kim@example.test', practitioner_name: 'Pat', practitioner_email: 'pat@example.test', practitioner_logo_url: '' };
const OFFER = { price: '$10', worth: '$27', ends: '24 November 2026', url: 'https://buy.stripe.example.test/upgrade?locked_prefilled_email=kim%40example.test', video_url: '' };
const md5 = (s) => crypto.createHash('md5').update(s).digest('hex');
// renderThankYou of the base with BASE and these stand-ins. Base = 5d48ceb (#40, the Full Report words); 8b64a25 was b10b49d8… before the rename.
const BASE_MD5 = 'a07c8543e065c17356ada3ef4baf62da';

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

ok(BASE_MD5 === md5(render(BASE, { form_focus: false })) && BASE_MD5 === md5(render(BASE, { form_focus: 0 })), 'F3 the switch set off → the same card, byte for byte');

console.log('── I. plan 8 (K23; hdlv2_ff_form_focus): finished, then the offer apart ──');
const ON = { form_focus: true };
const cards = (h) => h.split('<div class="hdlv2-card hdlv2-ty-card').slice(1);
const pic = `${PLUGIN}assets/images/motion/f-finished.svg?ver=0.48.92`;
h = render(Object.assign({}, BASE, { timeline_offer: OFFER }), ON);
let [one, two] = cards(h);
ok(cards(h).length === 2, 'I1 two cards');
ok(PIC_SRC.indexOf('f-finished.svg') !== -1 && (one || '').includes(`<picture><source srcset="${pic}&amp;still=1#still" media="(prefers-reduced-motion: reduce)"><img src="${pic}" alt="You have finished" width="96" height="96" loading="eager" decoding="async"></picture>`),
  'I2 card one: the moving finish picture, its still for reduced motion, alt "You have finished"');
ok((one || '').includes('<h2 class="hdlv2-ty-h1">Congratulations, Kim. Your Full Report is filled in.</h2>')
  && (one || '').includes('Your results are on their way to your email and should arrive within a few minutes.'), 'I3 card one: the congratulations and the e-mail line');
ok((one || '').includes('What happens next') && (one || '').includes('<li>Check your inbox for your Full Report.</li>') && (one || '').includes('check your spam folder')
  && (one || '').indexOf('<img') < (one || '').indexOf('Congratulations'), 'I4 card one: today\'s list and spam note, the picture above the heading');
ok(!/hdlv2-ty-timeline|Healthspan/.test(one || '') && !h.includes('your assessment is in'), 'I5 card one holds no offer; the old thanks line is gone');
ok((two || '').includes('<h2 class="hdlv2-ty-upgrade-title">An upgrade for your Full Report</h2>') && (two || '').includes('class="hdlv2-ty-timeline"')
  && (two || '').indexOf('An upgrade for your Full Report') < (two || '').indexOf('hdlv2-ty-timeline'), 'I6 card two: its own heading, then the Timeline block');
const block = render(Object.assign({}, BASE, { timeline_offer: OFFER })).match(/<div class="hdlv2-ty-timeline">[\s\S]*?Estimates, not predictions\.<\/p><\/div>/)[0];
ok((two || '').includes(block), 'I7 the Timeline block itself is unchanged');
ok(h.indexOf('<footer>F</footer>') < h.indexOf('hdlv2-ty-upgrade') && (one || '').includes('<footer>F</footer>'), 'I8 the footer closes card one; card two stands apart below it');
h = render(Object.assign({}, BASE, { timeline_owned: true }), ON);
[one, two] = cards(h);
ok(cards(h).length === 2 && (two || '').includes('An upgrade for your Full Report') && (two || '').includes('Your Healthspan Timeline is yours. It opens when your Full Report lands.')
  && !/hdlv2-ty-timeline|\$/.test(h) && !(one || '').includes('Healthspan'), 'I9 an owner: card two says it is theirs, no button, no price');
h = render(BASE, ON);
ok(cards(h).length === 1 && !h.includes('An upgrade') && h.includes('Congratulations, Kim.'), 'I10 neither: one card, no upgrade heading');
// Final review: no usable first name (deriveFirstName gives 'there') must not read "Congratulations, there."
const renderThere = (data, cfg) => { const root = { innerHTML: '' }; vm.runInNewContext(RN_OFF + PIC_SRC + '\n' + fns.join('\n') + '\nrenderThankYou(DATA);', { root, serverData: null, esc,
  CFG: Object.assign({ plugin_url: PLUGIN, asset_ver: '0.48.92' }, cfg), window: { hdlv2_form: { plugin_url: PLUGIN } }, document: { getElementsByTagName: () => [] },
  deriveFirstName: () => 'there', setRootClasses: () => {}, thankYouHeader: () => '<header>H</header>', thankYouFooter: () => '<footer>F</footer>', DATA: data }); return root.innerHTML; };
h = renderThere(BASE, ON);
ok(h.includes('<h2 class="hdlv2-ty-h1">Congratulations. Your Full Report is filled in.</h2>') && !h.includes('there'), 'I14 no first name: "Congratulations." with no "there"');
const fy = cut('  function renderThankYou(', '\n  }\n');
ok(/DRAFT COPY for the owner/.test(fy), 'I11 the new strings are marked draft copy for the owner');
const up = css.slice(css.indexOf('/* v0.48.92 (plan 8, K23;'), css.indexOf('/* end finish screen */'));
ok(/\.hdlv2-ty-upgrade \{[^}]*margin-top:\s*24px/.test(up), 'I12 clear space between the two cards');
ok(up.length > 20 && !/border-left|::before/.test(up) && !/#[0-9a-f]{3,6}\b/gi.test(up.replace(/#004F59|#3d8da0|#2c3e50|#888888|#888\b|#e4e6ea|#f8f9fb|#ffffff/gi, '')), 'I13 no rail, brand colours only');

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
