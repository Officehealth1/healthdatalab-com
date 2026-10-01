#!/usr/bin/env node
/**
 * Locked-panel logo frame (v0.47.91) — the logo container hugs the logo.
 *
 * Runs the REAL injectEditorialStyles() from widget/hdl-lead-magnet.js against
 * a fake document and reads the CSS it injects. Layout itself is proven in the
 * browser on STBY; this pins the rules so the box-inside-a-box cannot return.
 *
 * Run:  node tests/paid-stage1/test-widget-logo-frame.js
 * Exit: 0 all pass · 1 any fail
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'widget', 'hdl-lead-magnet.js'), 'utf8');

const bSrc = (src.match(/  var B = \{[\s\S]*?\n  \};/) || [''])[0];
const fnSrc = (src.match(/  function injectEditorialStyles\(\) \{[\s\S]*?\n  \}/) || [''])[0];
ok(!!bSrc && !!fnSrc, 'B and injectEditorialStyles() found in the widget');

let css = '';
const doc = {
  getElementById: () => null,
  createElement: () => ({}),
  head: { appendChild: (s) => { css = s.textContent; } },
};
vm.runInNewContext(bSrc + '\n' + fnSrc + '\ninjectEditorialStyles();', { document: doc });

// Declarations of the rule whose selector is exactly `sel` (last one wins).
function rule(sel) {
  const re = new RegExp('(?:^|\\})' + sel.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\{([^}]*)\\}', 'g');
  let m, out = null;
  while ((m = re.exec(css))) out = m[1];
  return out;
}
const has = (decls, d) => !!decls && decls.split(';').map((x) => x.trim()).includes(d);

// Locked panel: every shape hugs its logo instead of filling the card.
// (Square already has a fixed 36px width; the rule must not override it.)
ok(has(rule('.hdlw-locked .hdlw-logo:not([data-shape="square"])'), 'width:fit-content'), 'locked wordmark/tall: frame as wide as the logo, not the card');
ok(has(rule('.hdlw-locked .hdlw-logo'), 'margin:0 auto 18px'), 'locked: logo stays centred above the eyebrow');
ok(!has(rule('.hdlw-locked .hdlw-logo'), 'width:fit-content'), 'locked square: keeps its 36px width');

// Wordmark: drawn naked, like the result footer, at the base 32px height.
const word = rule('.hdlw-locked .hdlw-logo[data-shape="wordmark"]');
ok(has(word, 'background:transparent'), 'locked wordmark: no white background');
ok(has(word, 'border:0'), 'locked wordmark: no border');
ok(has(word, 'padding:0'), 'locked wordmark: no padding');
ok(!/height/.test(word || ''), 'locked wordmark: height left to the base 32px');

// Square and tall keep their white chip in the locked panel.
ok(rule('.hdlw-locked .hdlw-logo[data-shape="square"]') === null, 'locked square: chip unchanged');
ok(rule('.hdlw-locked .hdlw-logo[data-shape="tall"]') === null, 'locked tall: chip unchanged');

// Shared base and the result footer are untouched.
ok(has(rule('.hdlw-logo'), 'background:#fff'), 'base logo chip still white (question footer, other surfaces)');
ok(has(rule('.hdlw-logo[data-shape="wordmark"]'), 'height:32px') && has(rule('.hdlw-logo[data-shape="wordmark"]'), 'padding:4px 10px'), 'base wordmark sizing unchanged');
ok(rule('.hdlw-r-prac-foot .hdlw-logo') === 'background:transparent;border:0;padding:0;', 'result footer logo unchanged');
ok(rule('.hdlw-r-prac-foot .hdlw-logo[data-shape="wordmark"]') === 'height:38px;', 'result footer wordmark unchanged');

console.log('\n' + PASS + ' passed, ' + FAIL + ' failed');
process.exit(FAIL ? 1 : 0);
