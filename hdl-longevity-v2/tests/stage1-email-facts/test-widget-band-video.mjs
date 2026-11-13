#!/usr/bin/env node
/**
 * v0.48.50 (W9) — the result page's band video, widget/hdl-lead-magnet.js.
 *   - bandVideoHtml(band, videos) (the REAL function, run in vm): one lazy youtube-nocookie <iframe>
 *     titled "Matthew on your result" inside .hdlw-band-video for the band's URL; youtu.be/<id>,
 *     youtube.com/watch?v=<id> and a bare 11-character id accepted; anything else, a band with no URL,
 *     or no map at all: ''.
 *   - renderResult is b462b02's text plus ONE line, the call, inside the gauge card right after the
 *     gauge picture (so under the gauge, before the three paragraphs); no style rule added, so with the
 *     map absent the markup is b462b02's byte for byte.
 *   - the band comes from renderResult's own bandClass (good/avg/warn), not a third copy of the edges.
 *   - cfg.bandVideos is read from both payloads (public config and invite).
 * Run: node tests/stage1-email-facts/test-widget-band-video.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };

const REL = 'hdl-longevity-v2/widget/hdl-lead-magnet.js';
const js = fs.readFileSync(new URL('../../widget/hdl-lead-magnet.js', import.meta.url), 'utf8');
const old = execFileSync('git', ['show', 'b462b02:' + REL], { cwd: fileURLToPath(new URL('.', import.meta.url)), encoding: 'utf8', maxBuffer: 8 << 20 });
const cutIn = (text, head, end) => { const at = text.indexOf(head); return at < 0 ? '' : text.slice(at, text.indexOf(end, at) + end.length); };

const fnSrc = cutIn(js, '  function bandVideoHtml(', '\n  }\n');
ok(fnSrc !== '', 'bandVideoHtml is a top-level function of the widget');
const run = (band, videos) => { const c = { out: '' }; vm.runInNewContext(fnSrc + '\nout = bandVideoHtml(' + JSON.stringify(band) + ', ' + JSON.stringify(videos) + ');', c); return c.out; };

const want = (id) => '<div class="hdlw-band-video" style="margin:18px auto 0;max-width:640px;aspect-ratio:16/9;border-radius:12px;overflow:hidden;background:#f8f9fb;">'
  + '<iframe src="https://www.youtube-nocookie.com/embed/' + id + '" title="Matthew on your result" loading="lazy" '
  + 'allow="accelerometer; encrypted-media; gyroscope; picture-in-picture" allowfullscreen '
  + 'style="display:block;width:100%;height:100%;border:0;"></iframe></div>';

if (fnSrc) {
  const v = { green: 'https://www.youtube.com/watch?v=abcdefghijk', blue: 'https://youtu.be/K2rQSjwvTws?si=x1', red: 'zUCwXaMM9Y4' };
  ok(run('green', v) === want('abcdefghijk'), 'green: youtube.com/watch?v= → one nocookie iframe, lazy, titled');
  ok(run('blue', v) === want('K2rQSjwvTws'), 'blue: youtu.be/<id>?si= → the id');
  ok(run('red', v) === want('zUCwXaMM9Y4'), 'red: a bare 11-character id');
  ok(run('blue', { green: v.green }) === '', 'a band with no URL: nothing');
  ok(run('green', null) === '' && run('green', undefined) === '' && run('green', []) === '' && run('green', {}) === '', 'no map, an empty list or an empty object: nothing');
  ok(run('green', { green: 'https://vimeo.com/123' }) === '' && run('green', { green: 'javascript:alert(1)//abcdefghijk' }) === ''
    && run('green', { green: 'https://youtu.be/short' }) === '' && run('green', { green: '"><script>x</script>' }) === '', 'anything else: nothing');
  ok(run('green', { green: 'https://www.youtube.com/watch?feature=share&v=abcdefghijk' }) === want('abcdefghijk'), 'v= after another parameter');
  ok((run('red', v).match(/<iframe/g) || []).length === 1, 'exactly one iframe');
}

// renderResult: b462b02's text plus the one call line, placed under the gauge picture.
const rrNew = cutIn(js, '    function renderResult() {', '\n    }\n');
const rrOld = cutIn(old, '    function renderResult() {', '\n    }\n');
const CALL = "        +       bandVideoHtml({ good: 'green', avg: 'blue', warn: 'red' }[bandClass], cfg.bandVideos)\n";
const GAUGE = "        +       '<img class=\"hdlw-r-gauge-img\" src=\"' + gaugeUrl(rate) + '\" alt=\"Rate of ageing gauge \\u2014 ' + rateDisplay + '\">'\n";
ok(rrOld !== '' && rrOld.indexOf(GAUGE) > 0, 'found the gauge picture line in b462b02\'s renderResult');
// v0.48.60: the timeline teaser's href also carries &p= (tests/healthspan-timeline/test-widget-teaser.mjs); that one line is allowed too.
const PLINE = "          + (/^\\d+$/.test(String(cfg.pracId)) ? '&p=' + cfg.pracId : '') // v0.48.60: the timeline's brand row\n";
// v0.48.62: the timeline yes-or-no card (showLinkCard) keeps the consent key and hands the card to consentCard()
// (tests/healthspan-timeline-links/test-ui.js); that one block is set aside, everything else stays b462b02's.
const LINKCARD = (t) => cutIn(t, '      // v0.48.34 — the yes or no. The key came back', '      function failed(body, status) {');
ok(LINKCARD(rrNew) !== '' && LINKCARD(rrOld) !== '', 'found the showLinkCard block in both');
// v0.48.66: the safety notice's press rides in the lead POST (tests/safety-notice/test-widget-notice.mjs); that one line is allowed too.
const ACKLINE = '      if (answers._safetyAck) payload.safety_ack = answers._safetyAck; // v0.48.66\n';
ok(rrNew.indexOf(PLINE) > 0 && rrNew.replace(PLINE, '').replace(ACKLINE, '').replace(LINKCARD(rrNew), LINKCARD(rrOld)) === rrOld.replace(GAUGE, GAUGE + CALL), 'renderResult is b462b02\'s text with only the call added after the gauge picture (and the 0.48.60 &p= line, the 0.48.62 link card, the 0.48.66 safety_ack line)');
ok(rrNew.indexOf(CALL) > 0 && rrNew.indexOf(CALL) < rrNew.indexOf("escapeHtml(awakenP1)"), 'the video sits before the three paragraphs in source order');

// Outside renderResult: only the function and the cfg line were added.
const cfgLine = "    cfg.bandVideos = (publicCfg && publicCfg.band_videos) || (invite && invite.band_videos) || null; // v0.48.50 (W9)\n";
ok(js.indexOf(cfgLine) > 0, 'cfg.bandVideos read from the public config or the invite payload');
// Checked on the commit that made W9 (426fadd), so later widget work (v0.48.56, the Q4 answer poses) does not trip it.
const w9 = execFileSync('git', ['show', '426fadd:' + REL], { cwd: fileURLToPath(new URL('.', import.meta.url)), encoding: 'utf8', maxBuffer: 8 << 20 });
const fnW9 = cutIn(w9, '  function bandVideoHtml(', '\n  }\n');
const fnBlock = w9.slice(w9.indexOf('  // v0.48.50 (W9)'), w9.indexOf(fnW9) + fnW9.length + 1);
ok(fnW9 === fnSrc && w9.replace(cutIn(w9, '    function renderResult() {', '\n    }\n'), rrOld).replace(cfgLine, '').replace(fnBlock, '') === old,
  'W9 (426fadd) changed nothing else in the widget since b462b02, and its function is the one shipped now');

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
