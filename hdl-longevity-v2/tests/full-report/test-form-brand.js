#!/usr/bin/env node
/**
 * H6 — the practitioner's logo on every Full Report screen and the
 * partnership line in the footer (v0.48.15). Runs the REAL brandBar() and
 * footer() from assets/js/hdlv2-staged-form.js and checks the four screens
 * call brandBar() once each.
 *
 * Run:  node tests/full-report/test-form-brand.js
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');
let PASS = 0, FAIL = 0;
function ok(c, l) { (c ? PASS++ : FAIL++); console.log((c ? 'PASS  ' : 'FAIL  ') + l); }

const ROOT = path.join(__dirname, '..', '..');
const src = fs.readFileSync(path.join(ROOT, 'assets/js/hdlv2-staged-form.js'), 'utf8');
const php = fs.readFileSync(path.join(ROOT, 'includes/sprint-1/class-hdlv2-offer.php'), 'utf8');
const LINE = (php.match(/const PARTNER_LINE = '([^']+)'/) || [])[1];
function fn(name) {
  const one = src.match(new RegExp('\\n(  function ' + name + '\\([^)]*\\) \\{[^\\n]*\\})\\n'));
  if (one) return one[1];
  const m = src.match(new RegExp('  function ' + name + '\\([^)]*\\) \\{[\\s\\S]*?\\n  \\}'));
  return m ? m[0] : '';
}
const DOC = 'var document = { createElement: function () { var t = ""; return {'
  + ' set textContent(s) { t = s; },'
  + ' get innerHTML() { return t.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;"); } }; } };\n';
const ctx = {};
try {
  vm.runInNewContext(DOC + 'var serverData = {};\n' + fn('esc') + '\n' + fn('brandBar') + '\n' + fn('footer')
    + '\nthis.brandBar = brandBar; this.footer = footer; this.setData = function (d) { serverData = d; };', ctx);
} catch (e) { console.log('eval failed: ' + e.message); }

ok(!!LINE, 'HDLV2_Offer::PARTNER_LINE found');
ok(typeof ctx.brandBar === 'function', 'brandBar() exists');
if (typeof ctx.brandBar === 'function') {
  const withLogo = ctx.brandBar({ practitioner_name: 'Matthew D\'haemer', practitioner_logo_url: 'https://x.test/altituding.png' });
  ok(/<img[^>]+src="https:\/\/x\.test\/altituding\.png"/.test(withLogo) && /class="hdlv2-brandbar"/.test(withLogo), 'logo drawn in the band');
  ok(/alt="Matthew D&#039;haemer"|alt="Matthew D'haemer"/.test(withLogo), 'logo alt is the practitioner name');
  const noLogo = ctx.brandBar({ practitioner_name: 'Matthew D\'haemer', practitioner_logo_url: '' });
  ok(/MD/.test(noLogo) && !/<img/.test(noLogo), 'no logo → initials');
  const hdl = ctx.brandBar({ practitioner_name: 'Pat', practitioner_logo_url: 'https://x.test/HDL-Logo-2309.png' });
  ok(!/<img/.test(hdl), 'the HealthDataLab fallback logo is not drawn as the practitioner\'s');
  ok(ctx.brandBar({}) === '', 'no practitioner at all → nothing');
}
if (typeof ctx.footer === 'function' && typeof ctx.setData === 'function') {
  ctx.setData({ partner_line: LINE });
  const f = ctx.footer();
  ok(f.indexOf('a partnership focused on longevity') !== -1 && /Terms of Service/.test(f) && f.indexOf('partnership') < f.indexOf('Terms'), 'footer prints the partner line before the Terms link');
  ctx.setData({});
  ok(/Powered by/.test(ctx.footer()) && /Terms of Service/.test(ctx.footer()), 'footer without partner_line keeps today\'s line');
}
['renderWhyPicks', 'renderWizardSection', 'renderTapStep', 'renderStage2'].forEach(function (name) {
  const body = fn(name);
  ok((body.match(/brandBar\(serverData\)/g) || []).length === 1, name + ' draws the band once');
});
['renderThankYou', 'renderStage2ThankYou'].forEach(function (name) {
  ok(/thankYouHeader\(pracName, pracLogo\)/.test(fn(name)), name + ' keeps its practitioner header (logo already there)');
});
ok(/a partnership focused on longevity/.test(fn('thankYouFooter')), 'thank-you footer carries the partner line');

const wsrc = fs.readFileSync(path.join(ROOT, 'widget/hdl-lead-magnet.js'), 'utf8');
ok(LINE && wsrc.indexOf(LINE.replace('HealthDataLab', '<b>HealthDataLab</b>')) !== -1, 'widget footer shows the same partner line');
const dr = fs.readFileSync(path.join(ROOT, 'assets/js/hdlv2-draft-report.js'), 'utf8');
ok(/data\.partner_line/.test((dr.match(/function renderFooter\([\s\S]*?\n  \}/) || [''])[0]), 'report page footer uses partner_line');
console.log('\nPASS=' + PASS + ' FAIL=' + FAIL);
process.exit(FAIL ? 1 : 0);
