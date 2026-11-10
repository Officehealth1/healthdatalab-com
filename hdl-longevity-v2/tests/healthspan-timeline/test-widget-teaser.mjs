#!/usr/bin/env node
/**
 * Healthspan Timeline teaser on the Stage 1 result (v0.48.60): the REAL public-config overlay, the
 * timelineHref expression and the teaser card of widget/hdl-lead-magnet.js, run in vm (the
 * test-widget-motion.mjs pattern). The link now carries &p=<practitioner id> so the timeline page
 * can show the practitioner's name and logo; nothing else in the card changes.
 * Run: node tests/healthspan-timeline/test-widget-teaser.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';
import crypto from 'node:crypto';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const sha = (s) => crypto.createHash('sha256').update(s).digest('hex');

const js = fs.readFileSync(new URL('../../widget/hdl-lead-magnet.js', import.meta.url), 'utf8');
/** The source from `head` to the first `end` after it (inclusive), or '' when `head` is absent. */
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const overlay = cut('    if (publicCfg && !invite) {', '\n    }\n');
const href = cut('      var timelineHref = ', ": '';\n");
const strip = cut('      var timelineStrip = ', ".join('');\n");
const card = cut("( timelineHref\n            ? '<div class=\"hdlw-r-card hdlw-timeline-teaser\">'", "\n            : '' )");
ok(overlay !== '' && href !== '' && strip !== '' && card !== '', 'the overlay, the href, the strip and the card are found in the widget');
const esc = (s) => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

function teaser(publicCfg, pracId, firstName = 'Quim') {
  const ctx = { publicCfg, invite: null, cfg: { pracId }, age: 52, r: { sex: 'male' }, rate: 1.14, firstName, escapeHtml: esc,
    safeBuyUrl: (u) => (typeof u === 'string' && /^https:\/\//i.test(u)) ? u : '' };
  vm.runInNewContext(overlay + href + strip + 'this.HREF = timelineHref; this.CARD = ' + card + ';', ctx);
  return { href: ctx.HREF, card: ctx.CARD };
}

const PUB = { timeline_url: 'https://stby.test/healthspan-timeline/', practitioner_name: "Matthew D'haemer", logo_url: 'https://stby.test/logo.png' };
const t = teaser(PUB, '122');
ok(t.href === 'https://stby.test/healthspan-timeline/#a=52&s=M&r=1.14&n=Quim&p=122', 'T1 the teaser href ends #a=52&s=M&r=1.14&n=Quim&p=122 (got ' + t.href + ')');
ok(t.card.indexOf('href="' + esc(t.href) + '"') !== -1, 'T2 the card links to that href');
ok(teaser(PUB, '12a').href === 'https://stby.test/healthspan-timeline/#a=52&s=M&r=1.14&n=Quim', 'T3 a practitioner id that is not digits → no &p=');
ok(teaser(PUB, '122', 'there').href === 'https://stby.test/healthspan-timeline/#a=52&s=M&r=1.14&n=&p=122', 'T4 no first name → n empty, p still there');
ok(teaser({ practitioner_name: 'X' }, '122').card === '', 'T5 no timeline_url in public config → no teaser');
// The 48e2476 card with its href taken out (sha256 taken before the change): nothing else in the card moves.
const BASE_CARD = '094f822f3205608b3695f6025ded99ce12795382164eb64e0632780c7f9bf787';
ok(sha(t.card.replace(/ href="[^"]*"/, ' href=""')) === BASE_CARD, 'T6 nothing else in the card changed');

console.log('\nSCENARIO: ' + (FAIL ? 'FAIL' : 'PASS') + ' (' + PASS + '/' + (PASS + FAIL) + ')');
process.exit(FAIL ? 1 : 0);
