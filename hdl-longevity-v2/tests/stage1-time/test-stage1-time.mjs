#!/usr/bin/env node
/**
 * Stage 1 takes "about two minutes" (Matthew, 2026-11-26: "in my videos i say it takes two minutes").
 * Part 1 lists every stated time that sits near the words "nine questions", "9 questions", "free",
 * "Stage 1" or "check" in the plugin's own code, and fails while one of them is neither
 * "about two minutes" nor named in NOT_STAGE1 with its reason. A new time near those words fails here
 * until someone says what it is.
 * Part 1 is a net, not a proof: it sees a time only within about 200 characters of one of those words
 * (run on the old words it caught six of the seven lines). Part 2 is the proof for the places we know:
 * it names each screen and e-mail that tells a person how long the nine questions take. A new such
 * place is added to part 2 by hand.
 * Run: node tests/stage1-time/test-stage1-time.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../../', import.meta.url));
let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };

const TIME = /(?:(?:about|around)\s+|~\s*)?(?:\d+(?:\s*(?:–|-|&ndash;|to)\s*\d+)?\+?|\b(?:one|two|three|four|five|six|seven|eight|nine|ten|fifteen|twenty|thirty|forty|sixty|a few))[\s-]*(?:minutes?|mins?|seconds?|secs?)\b/gi;
const NEAR = /nine (?:\w+ )?questions|\b9[ -]questions?\b|\bfree\b|\bstage(?:\s|&nbsp;)1\b|\bcheck\b/i;
const WANT = 'about two minutes';

// A time near those words that is not the length of the nine questions. file · words on the line · why.
const NOT_STAGE1 = [
  ['assets/js/hdlv2-audio-component.js', 'Check back in a few minutes.', 'the wait for a transcription'],
  ['assets/js/hdlv2-draft-report.js', 'refresh in a few minutes', 'the wait for a report'],
  ['assets/js/hdlv2-staged-form.js', 'stand up from a chair in 30 seconds', 'a home test in the long form'],
  ['assets/js/hdlv2-staged-form.js', 'should arrive within a few minutes', 'the wait for the report e-mail'],
  ['includes/sprint-2/class-hdlv2-email-templates.php', 'Take a few minutes to reflect', 'the weekly check-in'],
  ['includes/sprint-4/class-hdlv2-client-dashboard.php', 'Two minutes. Tell us how the week went', 'the weekly check-in'],
];

const files = [];
(function walk(dir) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) { if (!/^(tests|docs|node_modules|vendor|\.git)$/.test(e.name)) walk(p); }
    else if (/\.(php|js)$/.test(e.name) && !/\.min\.js$|\.worker\.js$/.test(e.name)) files.push(p);
  }
})(ROOT);

console.log('── 1. every stated time near the Stage 1 words ──');
const squash = (s) => s.replace(/\s+/g, ' ');
let listed = 0;
for (const file of files) {
  const rel = path.relative(ROOT, file);
  // Whole-line comments, log lines and SQL say nothing to a visitor; blank them, keep the line numbers.
  const src = fs.readFileSync(file, 'utf8').split('\n')
    .map((l) => (/^\s*(\/\/|\*|\/\*|#)|error_log\(|INTERVAL \d+ (MINUTE|SECOND)/.test(l) ? '' : l)).join('\n');
  for (const m of src.matchAll(TIME)) {
    const before = squash(src.slice(Math.max(0, m.index - 600), m.index)).slice(-200);
    const after = squash(src.slice(m.index + m[0].length, m.index + m[0].length + 600)).slice(0, 200);
    if (!NEAR.test(before + ' ' + after)) continue;
    const said = squash(m[0]).trim().toLowerCase();
    const line = src.slice(0, m.index).split('\n').length;
    const lineText = src.split('\n')[line - 1];
    const why = NOT_STAGE1.find(([f, words]) => f === rel && lineText.includes(words));
    listed++;
    if (said === WANT) ok(true, `${rel}:${line} "${said}"`);
    else if (why) console.log(`  SKIP  ${rel}:${line} "${said}" (${why[2]})`);
    else ok(false, `${rel}:${line} says "${said}", not "${WANT}"`);
  }
}
ok(listed > 0, 'the search found stated times to read');

console.log('── 2. each place that tells a person how long the nine questions take ──');
const read = (rel) => fs.readFileSync(path.join(ROOT, rel), 'utf8');
const says = (rel, words, label) => ok(read(rel).includes(words), `${label}: ${words}`);
says('includes/sprint-4/class-hdlv2-products.php', "'Nine questions, about two minutes.'", 'the product card');
says('includes/sprint-4/class-hdlv2-practitioner-dashboard.php', '9 questions, about two minutes. Pace-of-ageing gauge sent by email.', 'the practitioner\'s guide');
says('includes/sprint-4/class-hdlv2-client-dashboard.php', 'Start with a 9-question snapshot &mdash; about two minutes &mdash; to see your pace of ageing.', 'the invited client\'s first line');
says('includes/sprint-4/class-hdlv2-client-dashboard.php', '<p>9 questions, about two minutes. You&rsquo;ll see your pace-of-ageing result straight after', 'the client\'s Stage 1 card');
says('includes/sprint-4/class-hdlv2-client-dashboard.php', "'9 questions, about two minutes. You see your pace-of-ageing gauge straight away.'", 'the client\'s stage list');
says('includes/sprint-1/class-hdlv2-widget-config.php', 'It is nine short questions and takes about two minutes.', 'the "Send Stage 1 link" e-mail');
says('assets/js/hdlv2-staged-form.js', 'takes about two minutes</p>', 'the form\'s own Stage 1 heading');

console.log(`PASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
