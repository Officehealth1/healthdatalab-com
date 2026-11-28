#!/usr/bin/env node
/**
 * v0.49.15 (plan 21, rows R1 to R3; hdlv2_ff_stage1_result_stack) — the Stage 1 result page as it is,
 * in one column at every width. The REAL renderResult is cut from the widget and its drawing part is run
 * in vm, with the widget's own helpers (gauge, film, cover, offer); only the score is handed in.
 *   K.1 without the setting the page is 1f948ac's, byte for byte (sha256 taken before a line changed).
 *   K.2 with it, the markup differs in one thing: one more class on the root.
 *   K.3 every new rule starts at that class and applies from 901 px; under it the grid is one column,
 *       the column has a largest width and sits centred; no older rule moves.
 *   K.4 every state: the class is there and nothing else differs from the old page's state.
 *   K.5 no new rule applies at 900 px or under, where the page is one column already (a phone sees no change).
 *   K.6 the setting is read from both settings answers and from a late one.
 * Run: node tests/result-stack/test-result-stack.mjs   Exit: 0 all pass, 1 any fail
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
const split = (html) => { const at = html.indexOf('</style>') + 8; return [html.slice(0, at), html.slice(at)]; };

// The states of P5. What the send's answer changes afterwards (saved, refused, held) is pinned in K.4.
const HEX = 'ab'.repeat(32);
const STATES = {
  'average, with the offer, the film, its cover and the scale': [FULL, {}],
  'slower, with the offer': [FULL, { rate: 0.86 }],
  'faster, with the offer': [FULL, { rate: 1.25 }],
  'no offer (the thank-you words)': [{}, {}],
  'a practitioner\'s invite, with a booking link': [Object.assign({}, FULL, { ctaLink: 'https://book.test/rosa' }), { fastInvite: true, inviteToken: HEX }],
  'a ticket (bought, no way back to the questions)': [FULL, { inviteToken: HEX }],
  'a Timeline link': [FULL, { inviteToken: HEX, linkInvite: true }],
  'no practitioner name, a booking link on the public page': [{ pracName: '', ctaLink: 'https://book.test/rosa', showBookOnPublic: true }, {}],
};

console.log('── K.1 without the setting: 1f948ac\'s page, byte for byte ──');
// sha256 of the drawn page at 1f948ac, taken before a line of plan 21 was written.
const PINS = {
  'average, with the offer, the film, its cover and the scale': '578baec93a7e531db9b78592e7642b996f47a145dcf63d19a5b5364918f0c814',
  'slower, with the offer': '5cce5ee3d6b39012ecbccad8967e6942f54dd7b94a939b66476ba894c4bd359c',
  'faster, with the offer': '99854227ad0550dfda82c32fdb7c35362b9418f463ec4d9aeb80c1acd67c7b81',
  'no offer (the thank-you words)': 'da5a54c8c5e8316e1418afbbca826d4ffe4e0f03b6bbc96d31f1f5d87bd322aa',
  'a practitioner\'s invite, with a booking link': '2169e3f182a8fa116826ee7489f7b3479345a54748e6d5794911123df53db7f2',
  'a ticket (bought, no way back to the questions)': '9e7cca9a7b4f566a9402f757ebf5c08c291c2c46422469fa6c16f94458bf3b72',
  'a Timeline link': 'd1e0f8d018cb773158eabfd00f0f5fc38eb3f7c465273ee56608b0070180a144',
  'no practitioner name, a booking link on the public page': 'aba1d8b9b7e62eaff04ffb6c14f6a5ffec5e208e0eada64a978e65f97af54f97',
};
const off = {}, on = {};
for (const [name, [cfg, st]] of Object.entries(STATES)) {
  off[name] = page(cfg, st);
  on[name] = page(Object.assign({}, cfg, { resultStack: true }), st);
  ok(sha(off[name]) === PINS[name], 'K.1 ' + name);
  ok(page(Object.assign({}, cfg, { resultStack: false }), st) === off[name], 'K.1 ' + name + ': the setting false is the setting absent');
}

console.log('── K.2 with the setting: one more class on the root ──');
const ROOT = '<div class="hdlw-r">', ROOT_ON = '<div class="hdlw-r hdlw-r-stack">';
const [styleOff, bodyOff] = split(off[Object.keys(STATES)[0]]);
const [styleOn, bodyOn] = split(on[Object.keys(STATES)[0]]);
ok(bodyOff.split(ROOT).length === 2 && bodyOff.startsWith(ROOT), 'K.2 the old page has one root, right after its style block');
ok(bodyOn !== bodyOff && bodyOn === bodyOff.replace(ROOT, ROOT_ON), 'K.2 same words, same parts, same order: the markup differs by the class hdlw-r-stack on the root, nothing else');
ok(bodyOn.split('hdlw-r-stack').length === 2, 'K.2 the class is written once in the markup');

console.log('── K.3 the new rules ──');
const base = styleOff.slice(0, -8); // without </style>
const extra = styleOn.startsWith(base) && styleOn.endsWith('</style>') ? styleOn.slice(base.length, -8) : null;
ok(extra !== null && extra !== '', 'K.3 no older rule moves: the style block is the old one with rules added at its end');
const media = /^@media \(min-width:901px\)\{(.+)\}$/.exec(extra || '');
ok(!!media, 'K.3 the new rules sit in one block that starts at 901 px');
const rules = media ? media[1].split('}').filter(Boolean).map((r) => r.split('{')) : [];
ok(rules.length > 0 && rules.every((r) => r.length === 2 && r[0].split(',').every((s) => s.startsWith('.hdlw-r-stack'))), 'K.3 every new rule starts at .hdlw-r-stack');
const decl = (sel) => (rules.find((r) => r[0] === sel) || ['', ''])[1];
ok(/(^|;)grid-template-columns:1fr(;|$)/.test(decl('.hdlw-r-stack .hdlw-r-twocol')), 'K.3 under the class the two-column grid is one column');
const width = /(^|;)max-width:(\d+)px(;|$)/.exec(decl('.hdlw-r-stack'));
ok(!!width && +width[2] >= 600 && +width[2] <= 900 && /(^|;)margin-left:auto(;|$)/.test(decl('.hdlw-r-stack')) && /(^|;)margin-right:auto(;|$)/.test(decl('.hdlw-r-stack')),
  'K.3 the column has a largest width (' + (width ? width[2] : '?') + ' px) and sits centred');
ok(!/border-left|::before|::after/.test(extra || ''), 'K.3 no line beside a card');

console.log('── K.4 every state ──');
for (const name of Object.keys(STATES)) {
  const [sOff, bOff] = split(off[name]), [sOn, bOn] = split(on[name]);
  ok(bOn === bOff.replace(ROOT, ROOT_ON) && bOn !== bOff && sOn === sOff.slice(0, -8) + extra + '</style>', 'K.4 ' + name + ': the class, the same new rules, and nothing else');
}
// sha256 of renderResult from its send to its end at 1f948ac: a save, a refusal and the holding list are untouched.
ok(sha(after) === '81b8a3ed040fc38ea2f0d1e64a769bf14a32b7dc0f1085f53798c222f46c513f', 'K.4 the send and what its answer shows (saved, refused, held) are 1f948ac\'s');
ok(rr.split('cfg.resultStack').length === 3, 'K.4 renderResult reads the setting twice: for the class and for the rules');

console.log('── K.5 a phone sees no change ──');
const NARROW = '@media (max-width:900px){.hdlw-r-twocol{grid-template-columns:1fr;gap:16px;}.hdlw-r-gauge-img{max-width:360px;}}';
ok(base.split(NARROW).length === 2, 'K.5 the page\'s own rules for 900 px and under are there, once, as they were');
ok(extra !== null && !/max-width:\s*\d+px\)/.test(extra) && (extra.match(/@media/g) || []).length === 1, 'K.5 no new rule applies at 900 px or under: one block, from 901 px');

console.log('── K.6 how the setting reaches the page ──');
const cfgLine = cut('    cfg.resultStack = ', ';');
ok(cfgLine !== '', 'K.6 cfg.resultStack is set where the widget reads its settings');
const read = (publicCfg, invite) => { const c = { cfg: {}, publicCfg, invite }; if (cfgLine) vm.runInNewContext(cfgLine, c); return c.cfg.resultStack; };
ok(read({ result_stack: true }, null) === true && read(null, { result_stack: true }) === true, 'K.6 read from the public settings and from an invite\'s answer');
ok(read({}, null) === false && read(null, {}) === false && read(null, null) === false, 'K.6 absent in the answer: false, so the old page');
const late = { cfg: {}, late: {} };
vm.runInNewContext(cut('    if (late) late.fill = ', '\n    };\n'), late);
late.late.fill({ result_stack: true });
ok(late.cfg.resultStack === true, 'K.6 a settings answer that lands after the cut-off still brings it to the result page');
late.late.fill({});
ok(late.cfg.resultStack === false, 'K.6 a late answer without it: false');

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL === 0 ? 0 : 1);
