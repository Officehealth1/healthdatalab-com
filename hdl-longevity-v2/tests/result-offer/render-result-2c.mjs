/**
 * v0.48.77 (plan 2c) — renderResult with exactly the 2c edits taken out, for the suites that pin
 * renderResult against an older commit (stage1-email-facts/test-widget-band-video.mjs,
 * safety-notice/test-widget-notice.mjs). undo2c() returns 2526b9a's renderResult (0.48.73, 2c's
 * base) only when applying the 2c edits below to it gives the current text exactly; otherwise it
 * returns the current text unchanged, so any other edit still fails those suites.
 * Not a runner (no test-/scenario- prefix): imported only.
 */
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

export const BASE_2C = '2526b9a';
const HEAD = '    function renderResult() {', END = '\n    }\n';
const between = (t, head, end) => { const a = t.indexOf(head); if (a < 0) return ''; const b = t.indexOf(end, a); return b < 0 ? '' : t.slice(a, b + end.length); };

// The 2c edits, old → new, on renderResult's text (String.raw keeps the source's \u escapes as written).
export const EDITS = [
  [String.raw`
      var diffLabel = '';`, String.raw`
      // v0.48.77 (2c) — hdlv2_ff_result_offer: on the paths that show the thank-you card (never the
      // practitioner-invited one) the card sells the Full Report like the Stage 1 e-mail.
      var bandKey = { good: 'green', avg: 'blue', warn: 'red' }[bandClass];
      var offerHref = fastInvite ? '' : resultOfferHref(cfg.resultOffer, bandKey, answers._email);

      var diffLabel = '';`],
  [String.raw`
        + '.hdlw-r-thanks-body{font-size:15px;color:#555;line-height:1.75;margin:0;}'
`, String.raw`
        + '.hdlw-r-thanks-body{font-size:15px;color:#555;line-height:1.75;margin:0;}'
        // v0.48.77 (2c) — the offer card's small price line and its two soft boxes, as in the Stage 1 e-mail.
        + '.hdlw-r-card p.hdlw-r-price{font-size:13px;color:#888;margin:0;}'
        + '.hdlw-r-card p.hdlw-r-offer-note{border:1px solid #e4e6ea;border-radius:10px;padding:12px 16px;font-size:13px;line-height:1.6;color:#2c3e50;}'
        + '.hdlw-r-card p.hdlw-r-offer-quote{background:#f8f9fb;border-radius:10px;padding:18px 22px;font-style:italic;color:#2c3e50;}'
`],
  [String.raw`built from your 9 quick answers. Next, your <em>WHY</em> \u2014 what really matters to you \u2014 turns this snapshot into a plan that\u2019s truly yours.</p>'`,
   String.raw`built from your 9 quick answers.' + (offerHref ? '' : ' Next, your <em>WHY</em> \u2014 what really matters to you \u2014 turns this snapshot into a plan that\u2019s truly yours.') + '</p>'`],
  [String.raw`        +       bandVideoHtml({ good: 'green', avg: 'blue', warn: 'red' }[bandClass], cfg.bandVideos)
`, String.raw`        +       ( bandVideoHtml(bandKey, cfg.bandVideos) || (offerHref ? bandVideoHtml('film', { film: cfg.resultOffer.video_url }) : '') )
`],
  [String.raw`            +     '<h2 class="hdlw-r-thanks-headline">Thank you.</h2>'
            +     '<p class="hdlw-r-thanks-body">Your practitioner has been sent your data. Once they have looked at your data, you will receive another email.</p>'
`, String.raw`            +     ( offerHref
                  ? resultOfferHtml(cfg.resultOffer, offerHref, escapeHtml, answers._email, !inviteToken)
                  : '<h2 class="hdlw-r-thanks-headline">Thank you.</h2>'
                  + '<p class="hdlw-r-thanks-body">Your practitioner has been sent your data. Once they have looked at your data, you will receive another email.</p>' )
`],
  // v0.48.85 (plan 2k): the lead reply's browser pass reaches the drawn offer button.
  [String.raw`
        // Public path (and paid tickets): the thank-you wall.
        statusEl.innerHTML = '';
`, String.raw`
        // Public path (and paid tickets): the thank-you wall.
        statusEl.innerHTML = '';
        withBuyPass(el, offerHref, data.buy_pass); // v0.48.85 (plan 2k)
`],
  // v0.48.83 (plan 2j, Part P) — with the switch on, the film under the gauge only beside the buy button
  // (tests/result-offer/test-band-film-guard.mjs). Nothing else in renderResult moved.
  [String.raw`        +       ( bandVideoHtml(bandKey, cfg.bandVideos) || (offerHref ? bandVideoHtml('film', { film: cfg.resultOffer.video_url }) : '') )
`, String.raw`        +       ( cfg.resultOffer && !offerHref ? '' : bandVideoHtml(bandKey, cfg.bandVideos) || (offerHref ? bandVideoHtml('film', { film: cfg.resultOffer.video_url }) : '') ) // v0.48.83 (2j): the films sell the $27 report, so with the switch on no button means no film
`],
  // v0.49.08 (plan 16; hdlv2_ff_stage1_film_cover) — the film it draws today goes through filmCoverHtml with
  // the cover's address (tests/stage1-email-facts/test-widget-band-video.mjs). Nothing else in renderResult moved.
  [String.raw`        +       ( cfg.resultOffer && !offerHref ? '' : bandVideoHtml(bandKey, cfg.bandVideos) || (offerHref ? bandVideoHtml('film', { film: cfg.resultOffer.video_url }) : '') ) // v0.48.83 (2j): the films sell the $27 report, so with the switch on no button means no film
`, String.raw`        +       filmCoverHtml( ( cfg.resultOffer && !offerHref ? '' : bandVideoHtml(bandKey, cfg.bandVideos) || (offerHref ? bandVideoHtml('film', { film: cfg.resultOffer.video_url }) : '') ), cfg.filmCover ) // v0.48.83 (2j): the films sell the $27 report, so with the switch on no button means no film; v0.49.08 (plan 16): his picture over it with hdlv2_ff_stage1_film_cover on
`],
  // v0.49.09 (plan 18; hdlv2_ff_gauge_scale) — the gauge picture is handed the server's scale for its curve
  // (tests/gauge-scale/test-page-gauge.mjs). Nothing else in renderResult moved.
  [String.raw`src="' + gaugeUrl(rate) + '" alt=`, String.raw`src="' + gaugeUrl(rate, cfg.gaugeScale) + '" alt=`],
  // Walk W1 (plan 4, 2026-11-19): gate row F25's "Start again" is drawn as a link.
  [String.raw`
        + '.hdlw-r-card p.hdlw-r-price{font-size:13px;color:#888;margin:0;}'
`, String.raw`
        + '.hdlw-r-card p.hdlw-r-price{font-size:13px;color:#888;margin:0;}'
        + '.hdlw-r-card p.hdlw-r-offer-email a{color:#3d8da0;text-decoration:underline;}'
`],
  // v0.48.80 (plan 2f) + v0.48.90 (plan 7, Push A) — a finish wipes the answers kept in the browser and tells the
  // host page: one finished() line at the top of saved() and of held(). Nothing else in renderResult moved.
  [String.raw`
      function saved(data) {
        var sent`, String.raw`
      function saved(data) {
        finished();
        var sent`],
  [String.raw`
      function held() {
        var sent`, String.raw`
      function held() {
        finished();
        var sent`],  // v0.49.15 (plan 21; hdlv2_ff_stage1_result_stack) — one class on the root and, with it, the rules that stand the
  // page in one column from 901 px (tests/result-stack/test-result-stack.mjs). Nothing else in renderResult moved.
  [String.raw`
        + '</style>'
        + '<div class="hdlw-r">'
`, String.raw`
        // v0.49.15 (plan 21; hdlv2_ff_stage1_result_stack) — the same page in one column on a wide screen
        // too: the arrangement it already has at 900 px and under, in a centred reading column. 704 px: the
        // film (640 at most) then fills its card edge to edge, in line with the text of the cards under it.
        // The three .hdlw-r-stat cards in .hdlw-r-twocol-left share one row of three. Nothing here applies under 901 px.
        + ( cfg.resultStack
          ? '@media (min-width:901px){'
          +   '.hdlw-r-stack{max-width:704px;margin-left:auto;margin-right:auto;}'
          +   '.hdlw-r-stack .hdlw-r-twocol{grid-template-columns:1fr;gap:16px;}'
          +   '.hdlw-r-stack .hdlw-r-twocol-left{display:grid;grid-template-columns:repeat(3,1fr);}'
          +   '.hdlw-r-stack .hdlw-r-twocol-left>*{grid-column:1/-1;}'
          +   '.hdlw-r-stack .hdlw-r-twocol-left>.hdlw-r-stat{grid-column:auto;}'
          +   '.hdlw-r-stack .hdlw-r-gauge-img{max-width:360px;}'
          + '}'
          : '' )
        + '</style>'
        + '<div class="hdlw-r' + ( cfg.resultStack ? ' hdlw-r-stack' : '' ) + '">'
`],
  // v0.49.16 (plan 22; hdlv2_ff_stage1_result_words) — Matthew's words from his drawing of 26 Nov: the hero line, his
  // line for each result in place of paragraphs 2 and 3, and the offer card told to use his words
  // (tests/result-words/test-result-words.mjs). Nothing else in renderResult moved.
  [String.raw`'<p class="hdlw-r-hero-body">Here\u2019s your starting picture, built from your 9 quick answers.' + (offerHref`,
   String.raw`'<p class="hdlw-r-hero-body">' + (cfg.resultWords ? 'Your results are in. So many people never look until it\u2019s late in the game. You just did, and that matters.' : 'Here\u2019s your starting picture, built from your 9 quick answers.') + (offerHref`],
  [String.raw`Most of these levers are within your control.';
`, String.raw`Most of these levers are within your control.';
      // v0.49.16 (plan 22; hdlv2_ff_stage1_result_words) — Matthew's line for each result (his drawing of 26 Nov)
      // in place of paragraphs 2 and 3; the facts paragraph stays.
      if (cfg.resultWords) {
        awakenP2 = {
          good: 'You\u2019re ageing slower than the population average. Something you\u2019re doing is working. The question is what, and what\u2019s quietly working against you.',
          avg: 'You\u2019re ageing at about the population average. That sounds fine, until you remember the average road is the one where the stairs, the floor and lifting the suitcase slowly get harder.',
          warn: 'You\u2019re currently ageing faster than the population average. That isn\u2019t a verdict. It\u2019s the clearest signal you\u2019ll get that something is pushing your number up.'
        }[bandClass];
        awakenP3 = '';
      }
`],
  [String.raw`        +       '<p>' + escapeHtml(awakenP3) + '</p>'
`, String.raw`        +       ( awakenP3 ? '<p>' + escapeHtml(awakenP3) + '</p>' : '' )
`],
  [String.raw`answers._email, !inviteToken)`, String.raw`answers._email, !inviteToken, cfg.resultWords)`],
];

/** The base renderResult with the 2c edits applied, or null when a piece is missing. */
export function apply2c(rr) {
  const teaserVars = between(rr, '      // v0.48.29 — the timeline teaser opens', ".join('');\n\n");
  const teaserCard = between(rr, '        +     ( timelineHref\n', "            : '' )\n");
  if (!teaserVars || !teaserCard) return null;
  let out = rr.replace(teaserVars, '').replace(teaserCard, '');
  for (const [a, b] of EDITS) {
    if (out.split(a).length !== 2) return null;
    out = out.replace(a, () => b);
  }
  return out;
}

export function undo2c(rrNow) {
  const base = execFileSync('git', ['show', BASE_2C + ':hdl-longevity-v2/widget/hdl-lead-magnet.js'],
    { cwd: fileURLToPath(new URL('.', import.meta.url)), encoding: 'utf8', maxBuffer: 8 << 20 });
  const rrBase = between(base, HEAD, END);
  return apply2c(rrBase) === rrNow ? rrBase : rrNow;
}
