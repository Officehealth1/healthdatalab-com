#!/usr/bin/env node
/**
 * v0.48.85 (plan 2k) — the widget carries buy_pass from the lead reply to the result page's Buy button, in
 * the fragment beside the e-mail, only on a button it drew, and never into any storage. The REAL widget
 * source is cut and run in vm (the tests/result-offer/test-result-card.mjs pattern).
 * Run: node tests/straight-to-form/test-widget-buy-pass.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const js = fs.readFileSync(new URL('../../widget/hdl-lead-magnet.js', import.meta.url), 'utf8');
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const fns = ['safeBuyUrl', 'resultOfferHref', 'withBuyPass'].map((n) => cut('  function ' + n + '(', '\n  }\n'));
ok(fns.every(Boolean), 'W0 safeBuyUrl, resultOfferHref and withBuyPass are in the widget');
if (!fns.every(Boolean)) { console.log(`\nPASS=${PASS} FAIL=${FAIL}`); process.exit(1); }
const run = (expr, ctx = {}) => { const c = Object.assign({ out: undefined }, ctx); vm.runInNewContext(fns.join('\n') + '\nout = (' + expr + ');', c); return c.out; };

const OFFER = { band_urls: { red: 'https://alt.test/full-report-faster' } };
const PASS_OK = 'b1763430000.2401.' + 'a'.repeat(64);

console.log('── W1. the address ──');
ok(run('resultOfferHref(o, "red", "a@b.test")', { o: OFFER }) === 'https://alt.test/full-report-faster#email=a%40b.test', 'W1.1 without a pass: the base address');
ok(run('resultOfferHref(o, "red", "a@b.test", p)', { o: OFFER, p: PASS_OK }) === 'https://alt.test/full-report-faster#email=a%40b.test&own=' + PASS_OK, 'W1.2 with a pass: &own= after the e-mail');
ok(run('resultOfferHref(o, "red", "not-an-email", p)', { o: OFFER, p: PASS_OK }) === 'https://alt.test/full-report-faster', 'W1.3 no good e-mail: no fragment, no pass');
for (const [label, bad] of [['an inbox pass', '1763430000.' + 'a'.repeat(64)], ['markup', 'b1.2.<x>'], ['upper hex', 'b1763430000.2401.' + 'A'.repeat(64)], ['a number', 5], ['empty', '']]) {
  ok(run('resultOfferHref(o, "red", "a@b.test", p)', { o: OFFER, p: bad }) === 'https://alt.test/full-report-faster#email=a%40b.test', 'W1.4 ' + label + ': left out');
}
ok(run('resultOfferHref(o, "green", "a@b.test", p)', { o: OFFER, p: PASS_OK }) === '', 'W1.5 no band page: no button, no pass');

console.log('── W2. the drawn button gets it once the reply is in ──');
const base = 'https://alt.test/full-report-faster#email=a%40b.test';
const mk = (href) => ({ _h: href, getAttribute() { return this._h; }, setAttribute(k, v) { if (k === 'href') this._h = v; } });
const offerBtn = mk(base), other = mk('https://elsewhere.test/'), root = { querySelectorAll: () => [offerBtn, other] };
run('withBuyPass(root, href, p)', { root, href: base, p: PASS_OK });
ok(offerBtn._h === base + '&own=' + PASS_OK, 'W2.1 the button that holds the offer address now carries the pass');
ok(other._h === 'https://elsewhere.test/', 'W2.2 any other link is untouched');
const noBtn = mk('https://elsewhere.test/');
run('withBuyPass({ querySelectorAll: () => [noBtn] }, "", p)', { noBtn, p: PASS_OK });
ok(noBtn._h === 'https://elsewhere.test/', 'W2.3 no button drawn (empty address): nothing changes');
const twice = mk(base);
run('withBuyPass(r, href, p), withBuyPass(r, href, p)', { r: { querySelectorAll: () => [twice] }, href: base, p: PASS_OK });
ok(twice._h === base + '&own=' + PASS_OK, 'W2.4 a second call does not add a second pass');

console.log('── W3. where the reply is read; never stored ──');
const saved = cut('      function saved(data) {', '\n      }\n');
ok(/withBuyPass\(el, offerHref, data\.buy_pass\)/.test(saved), 'W3.1 saved() hands data.buy_pass to withBuyPass for the drawn offer button');
ok(js.split('\n').filter((l) => /buy_?pass|buyPass/i.test(l) && /Storage|\.set\(|cookie/.test(l)).length === 0, 'W3.2 no line puts the pass in localStorage, sessionStorage, a store or a cookie');
ok(/var offerHref = fastInvite \? '' : resultOfferHref\(cfg\.resultOffer, bandKey, answers\._email\);/.test(js), 'W3.3 the drawn button starts with the base address (plan 2c line unchanged)');

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL === 0 ? 0 : 1);
