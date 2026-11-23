#!/usr/bin/env node
/**
 * v0.48.79 (M39; hdlv2_ff_empty_values) — the report page's "Your Health Profile" section, from the REAL
 * assets/js/hdlv2-draft-report.js (the section's own code, run in vm with stubs):
 *   - no calc.not_provided (switch off, or every input given): the section is today's, byte for byte;
 *   - two names: the line above the chart says "18 of the twenty", one grey line under the chart names
 *     them, escaped, and nothing scores them ("0/5", "Poor", "Very Poor").
 * Run: node tests/empty-values/test-not-provided-line.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';
// v0.48.96 (plan 12): the scripts name a report through rn(); with hdlv2_ff_report_names off it prints today's words.
const RN_OFF = 'function rn(key, today) { return today; }\n';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };

const js = fs.readFileSync(new URL('../../assets/js/hdlv2-draft-report.js', import.meta.url), 'utf8');
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const fn = (name) => cut('  function ' + name + '(', '\n  }\n');
const SECTION = cut('    // Radar\n    html +=', "'</section>';\n");
ok(SECTION !== '', 'the radar section is in renderReport()');

// Today's section (0.48.77) with the commentary stubbed as [rc] and a final report (no draft note).
const TODAY = '<section class="hdlv2-dr-section"><h2>Your Health Profile</h2>'
  + '<p class="hdlv2-dr-section-sub">How you\'re scoring across the twenty longevity metrics (0 = low, 5 = excellent), on the same colour scale as your final report.</p>'
  + '<div class="hdlv2-dr-radar-wrap"><canvas id="hdlv2-dr-radar"></canvas></div>[rc]</section>';

function section(calc, isFinal = true) {
  const ctx = { calc, ai: { radar_commentary: null }, isFinal, html: '', renderRadarCommentary: () => '[rc]' };
  vm.runInNewContext(RN_OFF + [fn('escape'), fn('radarSubLine'), fn('notProvidedLine')].join('\n') + '\n' + SECTION + '\nthis.OUT = html;', ctx);
  return ctx.OUT;
}
const safe = (calc) => { try { return section(calc); } catch (e) { return 'ERROR ' + e.message; } };

console.log('── no list: today\'s section ──');
ok(safe({}) === TODAY, 'N1 no not_provided key: the section is today\'s');
ok(safe({ not_provided: [] }) === TODAY, 'N2 an empty list (every input given): the section is today\'s');
ok(safe({ not_provided: 'Blood pressure' }) === TODAY, 'N3 not a list: ignored, today\'s section');

console.log('── two names ──');
const two = safe({ not_provided: ['Blood pressure', 'Heart rate'] });
ok(two.indexOf('<p class="hdlv2-dr-section-sub">How you\'re scoring across 18 of the twenty longevity metrics (0 = low, 5 = excellent), on the same colour scale as your final report.</p>') !== -1,
  'T1 the line above the chart: "across 18 of the twenty longevity metrics", the rest as before');
ok(two.indexOf('<div class="hdlv2-dr-radar-wrap"><canvas id="hdlv2-dr-radar"></canvas></div><p class="hdlv2-dr-section-sub">Not provided: Blood pressure, Heart rate.</p>[rc]') !== -1,
  'T2 straight under the chart, in the page\'s grey line style: "Not provided: Blood pressure, Heart rate."');
ok(!/0\/5|\bPoor\b|Very Poor|URGENT/i.test(two), 'T3 nothing scores them: no "0/5", "Poor", "Very Poor" or "URGENT"');
ok(safe({ not_provided: ['Blood pressure'] }).indexOf('across 19 of the twenty') !== -1, 'T4 one name: "19 of the twenty"');
const hostile = safe({ not_provided: ['<img src=x onerror=alert(1)>'] });
ok(hostile.indexOf('<img') === -1 && hostile.indexOf('Not provided: &lt;img src=x onerror=alert(1)&gt;.') !== -1, 'T5 a name is escaped');
const draft = (() => { try { return section({ not_provided: ['Blood pressure'] }, false); } catch (e) { return ''; } })();
ok(draft.indexOf('Not provided: Blood pressure.</p>[rc]<div class="hdlv2-dr-draft-note">') !== -1, 'T6 on a draft, the line sits under the chart, before the commentary and the draft note');

console.log('── the page reads the server\'s list, and adds no colour ──');
ok(/radarSubLine\(calc\.not_provided\)/.test(SECTION) && /notProvidedLine\(calc\.not_provided\)/.test(SECTION), 'W1 the section takes calc.not_provided from the server');
const css = fs.readFileSync(new URL('../../assets/css/hdlv2-draft-report.css', import.meta.url), 'utf8');
ok(!/not-provided/.test(css), 'W2 no new CSS: the grey line is the page\'s existing .hdlv2-dr-section-sub');

console.log(`PASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
