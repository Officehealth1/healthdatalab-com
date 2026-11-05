#!/usr/bin/env node
/**
 * v0.48.50 (W27) — the page's need, both scales: the REAL abilityCounts() of
 * assets/js/hdlv2-staged-form.js held to cases.json with CFG.need_scale_v2 off (the b462b02
 * numbers) and on (2.5 + 1.25 x (average weight - 1)). scenario-need-scale.php holds the PHP
 * tally() to the same file.
 * Run: node tests/need-scale/test-need-scale.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const js = fs.readFileSync(new URL('../../assets/js/hdlv2-staged-form.js', import.meta.url), 'utf8');
const fx = JSON.parse(fs.readFileSync(new URL('./cases.json', import.meta.url), 'utf8'));
const fn = (js.match(/  function abilityCounts\([^)]*\) \{[\s\S]*?\n  \}/) || [''])[0];
ok(fn !== '', 'abilityCounts found');

for (const on of [false, true]) {
  const ctx = { CFG: on ? { need_scale_v2: true } : {} };
  vm.runInNewContext(fn + '\nthis.f = abilityCounts;', ctx);
  fx.cases.forEach((c) => {
    const got = {};
    ctx.f(c.picks, fx.groups, fx.short).forEach((r) => { got[r.id] = r.need; });
    const want = on ? c.need_on : c.need_off;
    ok(JSON.stringify(Object.keys(got).sort()) === JSON.stringify(Object.keys(want).sort()) && Object.keys(want).every((k) => got[k] === want[k]),
      (on ? 'on ' : 'off') + ' [' + c.picks.join(',') + '] → ' + JSON.stringify(want) + (JSON.stringify(got) === JSON.stringify(want) ? '' : ' (got ' + JSON.stringify(got) + ')'));
  });
}
ok(/CFG\.need_scale_v2/.test(fn), 'the page reads CFG.need_scale_v2');
console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
