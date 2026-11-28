#!/usr/bin/env node
/**
 * v0.49.16 (plan 22, row W1; hdlv2_ff_stage1_result_words) — Matthew's own words from his drawing of 26 Nov
 * ("261126 - Stage 1 Results Email", his answer to "so what, why should somebody care") on the Stage 1
 * result page, in the parts the page already has. The REAL renderResult is run in vm, as in result-stack/.
 *   W.1 without the setting the page is the page as before, byte for byte, in every state.
 *   W.2 with it: the hero line, the line for each result, the offer card's heading, lines and button are his;
 *       the facts paragraph, the price, the note, the quote and the last two lines stay.
 *   W.3 a practitioner's invite (no offer card): his hero line and result line, nothing sold.
 *   W.4 the switch changes words only: same parts, same order, the style block untouched.
 *   W.5 the setting is read from both settings answers and from a late one.
 * Run: node tests/result-words/test-result-words.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';
import crypto from 'node:crypto';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };

const js = fs.readFileSync(new URL('../../widget/hdl-lead-magnet.js', import.meta.url), 'utf8');
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const sha = (s) => crypto.createHash('sha256').update(s).digest('hex');
const esc = (s) => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const fns = ['gaugeUrl', 'bandVideoHtml', 'filmCoverHtml', 'safeBuyUrl', 'resultOfferHref', 'resultOfferHtml'].map((n) => cut('  function ' + n + '(', '\n  }\n'));
const rr = cut('    function renderResult() {', '\n    }\n');
const SEND = '      // Send lead data.';
const draw = rr.slice(rr.indexOf('{') + 1, rr.indexOf(SEND)); // everything up to the drawn page
const after = rr.slice(rr.indexOf(SEND));                     // the send, and what its answer changes
ok(fns.every(Boolean) && rr !== '' && rr.indexOf(SEND) > 0, 'the six helpers and renderResult are found in the widget');

// The scale as HDLV2_Widget_Config::gauge_scale() answers with hdlv2_ff_gauge_scale on (tests/gauge-scale/).
const SCALE = { data: [0.8, 0.9, 0.9, 1.1, 1.1, 1.4, 1.4],
  backgroundColor: ['rgba(0,0,0,0)', 'rgba(67,191,85,0.95)', 'rgba(0,0,0,0)', 'rgba(65,165,238,0.95)', 'rgba(0,0,0,0)', 'rgba(255,111,75,0.95)', 'rgba(0,0,0,0)'],
  borderWidth: [0, 1, 0, 1, 0, 1, 0],
  datalabels: { display: [true, false, true, false, true, false, true], anchor: ['center', 'end', 'end', 'end', 'end', 'end', 'center'],
    align: ['bottom', 'end', 'end', 'end', 'end', 'end', 'bottom'], offset: 2, color: '#2c3e50', font: { size: 22, weight: 'bold' } } };
const OFFER = { today: '$27', video_url: '', band_urls: { green: 'https://alt.test/full-report-slower', blue: 'https://alt.test/full-report-average', red: 'https://alt.test/full-report-faster' } };
const FILMS = { green: 'K2rQSjwvTws', blue: 'K2rQSjwvTws', red: 'K2rQSjwvTws' };
const FULL = { resultOffer: OFFER, bandVideos: FILMS, filmCover: 'https://wp.test/cover.jpg', gaugeScale: SCALE };

/** The page renderResult draws, for these settings and this visitor. */
const page = (cfg, st = {}) => {
  const contentEl = { innerHTML: '' };
  vm.runInNewContext(fns.join('\n') + '\n(function () {' + draw + '\n})();', {
    cfg: Object.assign({ pracId: '206', pracName: 'Rosa Example', ctaLink: '#', ctaText: 'Book a session', logo: '', logoShape: 'square',
      showBookOnPublic: false, bandVideos: null, resultOffer: null, filmCover: '', gaugeScale: null }, cfg),
    answers: { _name: 'Ada Walker', _email: 'ada@example.test', q1_age: '52' },
    compute: () => ({ rate: st.rate || 1.02 }),
    fastInvite: !!st.fastInvite, inviteToken: st.inviteToken || '', linkInvite: !!st.linkInvite, linkFrom: 'Sam Example',
    id: 'hdlw-206', B: { body: "'Inter',sans-serif" }, contentEl,
    el: { style: {}, firstElementChild: null, querySelector: () => null },
    document: { getElementById: () => ({}), createElement: () => ({ set textContent(v) { this.t = v; }, get innerHTML() { return esc(this.t); } }) },
    location: { href: 'https://host.test/report/start' },
    goTo() {}, valOf() {}, markErr() {},
  });
  return contentEl.innerHTML;
};

const HEX = 'ab'.repeat(32);
const STATES = {
  average: [FULL, {}], slower: [FULL, { rate: 0.86 }], faster: [FULL, { rate: 1.25 }],
  'no offer': [{}, {}], invite: [FULL, { fastInvite: true, inviteToken: HEX }],
  ticket: [FULL, { inviteToken: HEX }], 'Timeline link': [FULL, { inviteToken: HEX, linkInvite: true }],
};
// sha256 of each page before plan 22 (the same pins as result-stack/ K.1: plan 21's class only shows with its own switch).
const PINS = {
  average: '578baec93a7e531db9b78592e7642b996f47a145dcf63d19a5b5364918f0c814',
  slower: '5cce5ee3d6b39012ecbccad8967e6942f54dd7b94a939b66476ba894c4bd359c',
  faster: '99854227ad0550dfda82c32fdb7c35362b9418f463ec4d9aeb80c1acd67c7b81',
  'no offer': 'da5a54c8c5e8316e1418afbbca826d4ffe4e0f03b6bbc96d31f1f5d87bd322aa',
  ticket: '9e7cca9a7b4f566a9402f757ebf5c08c291c2c46422469fa6c16f94458bf3b72',
  'Timeline link': 'd1e0f8d018cb773158eabfd00f0f5fc38eb3f7c465273ee56608b0070180a144',
};
// His words, as drawn (vault: reference_stage1_results_email_drawing_2026_11_26.md, items 5, 6, 8, 9, 10, 11).
const A = (s) => s.replace(/'/g, '’'); // the page writes a curly apostrophe
const HERO = A("Your results are in. So many people never look until it's late in the game. You just did, and that matters.");
const BAND = {
  faster: A("You're currently ageing faster than the population average. That isn't a verdict. It's the clearest signal you'll get that something is pushing your number up."),
  average: A("You're ageing at about the population average. That sounds fine, until you remember the average road is the one where the stairs, the floor and lifting the suitcase slowly get harder."),
  slower: A("You're ageing slower than the population average. Something you're doing is working. The question is what, and what's quietly working against you."),
};
const WHY_H = '… but why?', WHY_B = A("Your number shows where you're heading. Not why."),
  WHY_P = A("Without the why, any change is a guess. Your Full Longevity Report shows what's driving your number and where to focus first."),
  BTN = A("Show me what's driving my number");
const OLD = [A("Here's your starting picture, built from your 9 quick answers."), A('This isn\'t a verdict — it\'s a starting picture.'), 'Do you know what is creating your number?',
  'On its own, a number changes nothing.', 'The Full Report shows what pulls yours up and what pulls it down.', 'Advice online is written for everyone. This is written about you.',
  'Yes, give me access to my Full Report'];
const KEEP = ['Ada, your rate of ageing of ', 'For a small investment of $27.', '<strong>Important:</strong> this number is an estimate.',
  'From you, a few decades on:', 'None of this replaces your doctor. Keep them in the loop.', 'Your free report is on its way to your inbox.', 'Rosa Example'];
// The page writes the apostrophe as ’ inside escapeHtml'd strings and as ' in literal markup; compare on letters.
const norm = (s) => s.replace(/’/g, "'").replace(/&#39;|&#x27;/g, "'");
const has = (html, s) => norm(html).includes(norm(s));

console.log('── W.1 without the setting: the page as before ──');
const off = {}, on = {};
for (const [name, [cfg, st]] of Object.entries(STATES)) {
  off[name] = page(cfg, st);
  on[name] = page(Object.assign({}, cfg, { resultWords: true }), st);
  if (PINS[name]) ok(sha(off[name]) === PINS[name], 'W.1 ' + name + ', byte for byte');
  ok(page(Object.assign({}, cfg, { resultWords: false }), st) === off[name], 'W.1 ' + name + ': the setting false is the setting absent');
}

console.log('── W.2 with the setting: his words ──');
for (const band of ['average', 'slower', 'faster']) {
  const h = on[band];
  ok(has(h, HERO), 'W.2 ' + band + ': the hero line is his');
  ok(has(h, BAND[band]) && Object.keys(BAND).filter((b) => b !== band).every((b) => !has(h, BAND[b])), 'W.2 ' + band + ': his line for this result, and only that one');
  ok(has(h, WHY_H) && has(h, '<strong>' + WHY_B + '</strong>') && has(h, WHY_P) && has(h, '>' + BTN + '</a>'), 'W.2 ' + band + ': "… but why?", his two lines and his button');
  ok(OLD.every((s) => !has(h, s)), 'W.2 ' + band + ': the old sentences they replace are gone');
  ok(KEEP.every((s) => has(h, s)), 'W.2 ' + band + ': the facts, the price, the note, the quote and the last lines stay');
  ok(!/48 hours|\$97|attached pdf|this is what you get/i.test(h), 'W.2 ' + band + ': no deadline, no second price, no attachment line, no sample charts');
}
ok(has(on.slower, 'about 14% slower than the calendar suggests') && has(on.faster, 'about 25% faster than the calendar suggests'), 'W.2 the facts paragraph still follows the result');

console.log('── W.3 a practitioner\'s invite ──');
ok(has(on.invite, HERO) && has(on.invite, BAND.average), 'W.3 his hero line and result line');
ok(!has(on.invite, WHY_H) && !has(on.invite, BTN) && !has(on.invite, 'small investment'), 'W.3 nothing is sold on that path');
ok(has(off.invite, A('Next, your <em>WHY</em>')) === has(on.invite, A('Next, your <em>WHY</em>')), 'W.3 the line about the WHY on that path is kept as it was');

console.log('── W.4 words only ──');
const parts = (h) => (h.match(/class="[^"]*"/g) || []).join(' ');
const style = (h) => h.slice(0, h.indexOf('</style>'));
for (const name of Object.keys(STATES)) {
  ok(style(on[name]) === style(off[name]), 'W.4 ' + name + ': the style block is untouched');
  ok(parts(on[name]) === parts(off[name]), 'W.4 ' + name + ': the same parts (every class) in the same order');
}

console.log('── W.5 how the setting reaches the page ──');
const cfgLine = cut('    cfg.resultWords = ', ';');
ok(cfgLine !== '', 'W.5 cfg.resultWords is set where the widget reads its settings');
const read = (publicCfg, invite) => { const c = { cfg: {}, publicCfg, invite }; if (cfgLine) vm.runInNewContext(cfgLine, c); return c.cfg.resultWords; };
ok(read({ result_words: true }, null) === true && read(null, { result_words: true }) === true, 'W.5 read from the public settings and from an invite\'s answer');
ok(read({}, null) === false && read(null, null) === false, 'W.5 absent: false, so the old words');
const late = { cfg: {}, late: {} };
vm.runInNewContext(cut('    if (late) late.fill = ', '\n    };\n'), late);
late.late.fill({ result_words: true });
ok(late.cfg.resultWords === true, 'W.5 a settings answer that lands after the cut-off still brings it');

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL === 0 ? 0 : 1);
