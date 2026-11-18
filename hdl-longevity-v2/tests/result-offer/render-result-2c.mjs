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
                  ? resultOfferHtml(cfg.resultOffer, offerHref, escapeHtml, answers._email)
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
