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
 *   - v0.49.08 (plan 16; hdlv2_ff_stage1_film_cover): filmCoverHtml(frame, cover) puts Matthew's picture
 *     over the film as a button and playFilm(button) swaps it for today's frame with autoplay. With no
 *     cover address the frame comes back untouched, so the switch absent is today's markup byte for byte.
 * Run: node tests/stage1-email-facts/test-widget-band-video.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';
import { execFileSync } from 'node:child_process';
import { undo2c } from '../result-offer/render-result-2c.mjs'; // v0.48.77: the 2c edits are checked there
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
const rrNew = undo2c(cutIn(js, '    function renderResult() {', '\n    }\n')); // v0.48.77: renderResult as at 2c's base when 2c changed only its own lines
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

// v0.49.08 (plan 16) — hdlv2_ff_stage1_film_cover: Matthew's picture over the film; the frame loads on the press.
console.log('── the film\'s cover ──');
const coverSrc = cutIn(js, '  function filmCoverHtml(', '\n  }\n'), playSrc = cutIn(js, '  function playFilm(', '\n  }\n');
ok(coverSrc !== '' && playSrc !== '', 'filmCoverHtml and playFilm are top-level functions of the widget');
if (fnSrc && coverSrc && playSrc) {
  const COVER = 'https://plugin.example.test/wp-content/plugins/hdl-longevity-v2/assets/images/stage1-film-cover-2026-11.jpg';
  const NAME = 'Play: Matthew explains your result';
  const cover = (frame, url) => { const c = { out: '' }; vm.runInNewContext(coverSrc + '\nout = filmCoverHtml(' + JSON.stringify(frame) + ', ' + JSON.stringify(url) + ');', c); return c.out; };
  const today = want('zUCwXaMM9Y4');
  ok(cover(today, '') === today && cover(today, undefined) === today && cover(today, null) === today, 'switch absent (no address): today\'s markup, byte for byte');
  ok(cover('', COVER) === '', 'no film: nothing, cover or not');
  ok(['http://x.test/a.jpg', 'javascript:alert(1)', COVER + '" onerror="x', COVER + ' x', '//x.test/a.jpg', COVER + '<b>'].every((u) => cover(today, u) === today),
    'an address that is not a plain https one: today\'s frame');
  const on = cover(today, COVER);
  ok(on.indexOf('<iframe') === -1 && on.indexOf('youtube') === -1, 'switch on: no frame and no YouTube address before the press');
  ok(on.startsWith('<div class="hdlw-band-video" style="margin:18px auto 0;max-width:640px;aspect-ratio:16/9;border-radius:12px;overflow:hidden;background:#f8f9fb;">') && on.endsWith('</div>'),
    'the same 16:9 box');
  ok((on.match(/<button type="button" class="hdlw-film-cover" data-film="zUCwXaMM9Y4"/g) || []).length === 1 && (on.match(/<button/g) || []).length === 1, 'one button, type button, carrying the film');
  ok(on.indexOf('<img src="' + COVER + '" alt="" loading="lazy"') > 0 && (on.match(/<img/g) || []).length === 1, 'the picture inside it: empty alt, lazy');
  const btn = on.slice(on.indexOf('<button'), on.indexOf('</button>'));
  ok(btn.replace(/<[^>]+>/g, '').trim() === NAME && btn.indexOf('aria-label') === -1, 'the button\'s name is its own words, "' + NAME + '": they show if the picture does not load');
  ok(btn.indexOf(NAME) > 0 && btn.indexOf(NAME) < btn.indexOf('<img'), 'the words sit under the picture, not over it');
  ok(on.indexOf('.hdlw-film-cover:focus-visible{outline:2px solid var(--hdl-accent,#3d8da0);outline-offset:-4px;}') > 0, 'a focus ring in the widget\'s own style, drawn inside the clipped box');
  ok(on.indexOf('<svg') === -1, 'no play icon drawn over it (the picture has one)');

  const press = (id, attached = true) => {
    const box = { innerHTML: 'BUTTON', focused: 0, get firstChild() { return { focus: () => { box.focused++; } }; } };
    const c = { btn: { parentNode: attached ? box : null, getAttribute: (n) => (n === 'data-film' ? id : null) } };
    vm.runInNewContext(fnSrc + playSrc + '\nplayFilm(btn);', c);
    return box;
  };
  const playing = '<iframe src="https://www.youtube-nocookie.com/embed/zUCwXaMM9Y4?autoplay=1" title="Matthew on your result" loading="lazy" '
    + 'allow="autoplay; accelerometer; encrypted-media; gyroscope; picture-in-picture" allowfullscreen '
    + 'style="display:block;width:100%;height:100%;border:0;"></iframe>';
  const played = press('zUCwXaMM9Y4');
  ok(played.innerHTML === playing, 'after the press: today\'s one nocookie frame in the same box, with ?autoplay=1 and leave to autoplay');
  ok(played.focused === 1, 'focus moves to the film, not back to the top of the page');
  ok(press('"><script>x</script>').innerHTML === 'BUTTON' && press(null).innerHTML === 'BUTTON', 'a film id that is not a YouTube id: the button stays');
  ok(press('zUCwXaMM9Y4', false).innerHTML === 'BUTTON', 'a press on a button already gone: nothing');

  ok(js.indexOf("    cfg.filmCover = String((publicCfg && publicCfg.film_cover_url) || (invite && invite.film_cover_url) || '');") > 0, 'cfg.filmCover read from the public config or the invite payload');
  ok(js.indexOf("      cfg.filmCover = String(lateCfg.film_cover_url || '');") > 0, 'a late settings answer fills it too');
  ok(/el\.addEventListener\('click', function \(e\) \{\s*var cover = e\.target && e\.target\.closest && e\.target\.closest\('\.hdlw-film-cover'\);\s*if \(cover\) playFilm\(cover\);\s*\}\);/.test(js),
    'one click listener on the widget\'s own root plays it (Enter and Space press a button)');
  const rrNow = cutIn(js, '    function renderResult() {', '\n    }\n');
  ok((rrNow.match(/filmCoverHtml\(/g) || []).length === 1 && rrNow.indexOf("+       filmCoverHtml( ( cfg.resultOffer && !offerHref ? '' : bandVideoHtml(bandKey, cfg.bandVideos) || (offerHref ? bandVideoHtml('film', { film: cfg.resultOffer.video_url }) : '') ), cfg.filmCover )") > 0,
    'renderResult hands the film it draws today, and the cover\'s address, to filmCoverHtml (one call)');
}

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
