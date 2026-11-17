#!/usr/bin/env node
/**
 * Healthspan Timeline teaser on the Stage 1 result: REMOVED in v0.48.77 (plan 2c, Matthew
 * 2026-11-16: "I have to remove this from here because that's going to be an upgrade").
 * The widget holds no teaser card, no public-config field for it and no Timeline address; the
 * server's public config no longer offers timeline_url (tests/result-offer/scenario-public-config.php).
 * Run: node tests/healthspan-timeline/test-widget-teaser.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };

const js = fs.readFileSync(new URL('../../widget/hdl-lead-magnet.js', import.meta.url), 'utf8');
ok(js.indexOf('hdlw-timeline-teaser') < 0 && js.indexOf('Open my timeline') < 0 && js.indexOf('See your years beside the people you love') < 0, 'T1 no teaser card or words');
ok(js.indexOf('timelineUrl') < 0 && js.indexOf('timeline_url') < 0 && js.indexOf('timelineHref') < 0 && js.indexOf('timelineStrip') < 0, 'T2 no teaser config field, link or colour strip');

console.log('\nSCENARIO: ' + (FAIL ? 'FAIL' : 'PASS') + ' (' + PASS + '/' + (PASS + FAIL) + ')');
process.exit(FAIL ? 1 : 0);
