/**
 * HDL V2 — Client DRAFT Report renderer
 *
 * Powers [hdlv2_draft_report] shortcode. Fetches data via REST, renders
 * the full report layout + V1 trajectory chart + 21-metric radar. Polls
 * every 3s while Claude narrative is still generating.
 *
 * URL contract: /longevity-draft-report/?t=<64-hex-token>
 */

(function () {
  'use strict';

  var POLL_INTERVAL_MS = 3000;
  var POLL_MAX_ATTEMPTS = 120;                // 120 * 3s = 6 minutes
  var CONFIG = window.HDLV2_DRAFT_REPORT || {};

  // v0.22.7 — closure-scoped state, set by start(). Allows mounting into
  // any container (e.g. the practitioner consultation page), not just the
  // default #hdlv2-draft-report-root on the client-facing report page.
  var ROOT = null;
  var token = '';
  var attempts = 0;
  // v0.37.0 — progress-illusion controller for the Claude wait. Created
  // on start(), torn down inside renderReport / renderError. The bar
  // caps asymptotically at 92% until finish() snaps it to 100%, so the
  // user always sees movement during the 20-60s draft generation.
  var progressCtl = null;

  function start(rootEl, overrideToken) {
    ROOT = rootEl;
    if (!ROOT) return;

    // ── Token resolution ──
    // Priority: explicit override > ?t= URL > ?token= URL.
    // v0.20.6 accepts ?t= (Stage 3 redirect) OR ?token= (email autologin).
    var urlParams = new URLSearchParams(window.location.search);
    token = overrideToken || urlParams.get('t') || urlParams.get('token') || '';
    if (!/^[a-f0-9]{64}$/i.test(token)) {
      renderError(
        'We couldn\'t load your report',
        'The link you used looks invalid. Please use the link from your latest email, or contact your practitioner.'
      );
      return;
    }

    // v0.37.0 — Replace the server-rendered "Preparing your draft report"
    // placeholder with a shaped skeleton + progress-bar illusion + step
    // ladder. Falls back gracefully if HDLV2Loading isn't on the page
    // (older cached pages mid-deploy).
    mountLoadingState();

    attempts = 0;
    pollReport();
  }

  function mountLoadingState() {
    if (!ROOT || !window.HDLV2Loading) return;
    ROOT.innerHTML =
      '<div class="hdlv2-dr-loading" data-hdlv2-state="loading" style="text-align:left;">' +
        '<div style="text-align:center;margin-bottom:24px;">' +
          '<div class="hdlv2-dr-loading-icon"></div>' +
          '<h3 style="margin:0 0 6px;">Preparing your draft report</h3>' +
          '<p style="color:#555;margin:0 0 4px;">We\'re running your results through our analysis</p>' +
          '<p style="font-size:12px;color:#888;margin:0;">This usually takes 20 – 60 seconds.</p>' +
          '<div id="hdlv2-dr-progress-mount" style="display:flex;justify-content:center;"></div>' +
        '</div>' +
        HDLV2Loading.skeleton('report') +
      '</div>';
    var mount = ROOT.querySelector('#hdlv2-dr-progress-mount');
    if (mount) {
      progressCtl = HDLV2Loading.progress(mount, {
        steps: [
          'Reading your responses',
          'Analysing patterns',
          'Drafting paragraphs',
          'Polishing the report'
        ],
        capPercent: 92,
        stepMs: 4500
      });
    }
  }

  // Public API — used by hdlv2-consultation.js to mount the report layout
  // inside the practitioner consultation left panel.
  window.HDLV2DraftRenderer = { start: start };

  // Auto-init for /longevity-draft-report/ — preserves existing behavior.
  function initDefault() {
    var defaultRoot = document.getElementById('hdlv2-draft-report-root');
    if (defaultRoot) start(defaultRoot);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDefault);
  } else {
    initDefault();
  }

  function pollReport() {
    attempts++;
    fetch(CONFIG.rest_url + '?token=' + encodeURIComponent(token), {
      headers: { 'Accept': 'application/json', 'X-WP-Nonce': CONFIG.nonce || '' },
      credentials: 'same-origin'
    })
      .then(function (res) { return res.json().then(function (body) { return { ok: res.ok, body: body }; }); })
      .then(function (r) {
        if (!r.ok) {
          var code = (r.body && r.body.code) || 'error';
          if (code === 'not_found' || code === 'invalid_token') {
            renderError('Report not found', 'We can\'t find a report for that link. Please check the link or contact your practitioner.');
            return;
          }
          throw new Error((r.body && r.body.message) || 'Request failed');
        }

        var data = r.body;

        if (data.status === 'incomplete') {
          renderError('One more step to go', data.message || 'Complete your full assessment to see your draft report.');
          return;
        }

        if (data.status === 'ready') {
          renderReport(data);
          return;
        }

        // Still generating — poll again
        if (attempts < POLL_MAX_ATTEMPTS) {
          setTimeout(pollReport, POLL_INTERVAL_MS);
        } else {
          renderError(
            'Still preparing your report',
            'Your draft is taking longer than usual. Please refresh in a few minutes — or check back from your email link.'
          );
        }
      })
      .catch(function (err) {
        if (attempts < 3) {
          setTimeout(pollReport, POLL_INTERVAL_MS);
        } else {
          renderError('Something went wrong', 'We had trouble loading your report. Please refresh the page or try again shortly.');
          console.warn('[HDLV2 Draft] fetch failed', err);
        }
      });
  }

  // ── States ──
  function renderError(title, body) {
    // v0.37.0 — tear down the progress controller before the DOM swap.
    if (progressCtl) { progressCtl.cancel(); progressCtl = null; }
    ROOT.innerHTML =
      '<div class="hdlv2-dr-error" data-hdlv2-state="error">' +
      '<h3>' + escape(title) + '</h3>' +
      '<p>' + escape(body) + '</p>' +
      '</div>';
  }

  function renderReport(data) {
    // v0.37.0 — snap the progress bar to 100% before swapping the DOM,
    // so the user sees a satisfying completion frame even though the
    // skeleton is about to be replaced.
    if (progressCtl) { progressCtl.finish(); progressCtl = null; }
    var calc = data.calc || {};
    var ai   = data.ai_narrative || {};
    // v0.20.6 — type flag decides badge, hero subtitle, and section headings.
    // 'final'  = practitioner has finalised the report.
    // 'draft'  = pre-consultation (original behaviour).
    var type    = data.type === 'final' ? 'final' : 'draft';
    var isFinal = (type === 'final');
    var html = '';

    // Hero
    var firstName = (data.client_name || '').split(' ')[0] || 'there';
    var heroSubtitleHtml = isFinal
      ? 'This is your <strong style="font-weight:600;">finalised Longevity Report</strong>, reviewed by your practitioner. Use it as your roadmap — your weekly Flight Plan is next.'
      : 'Here\'s a first look at what we\'ve learnt so far. <strong style="font-weight:600;">This is a draft</strong> — your practitioner will fine-tune it during your consultation, then send you the final plan.';

    html += '<header class="hdlv2-dr-hero">' +
      '<div class="hdlv2-dr-hero-header">' +
        '<div>' +
          '<p class="hdlv2-dr-hero-greeting">Hi ' + escape(firstName) + ' —</p>' +
          '<h1 class="hdlv2-dr-hero-title">Your Longevity Report</h1>' +
        '</div>' +
        '<span class="hdlv2-dr-' + (isFinal ? 'final' : 'draft') + '-badge">' + (isFinal ? 'Final' : 'Draft') + '</span>' +
      '</div>' +
      '<p class="hdlv2-dr-hero-subtitle">' + heroSubtitleHtml + '</p>' +
    '</header>';

    // Pace of Ageing — gauge hero + 3 supporting stat cards
    html += renderPaceSection(calc);

    // AI opening narrative
    if (ai.opening) {
      html +=
        '<div class="hdlv2-dr-narrative">' +
          '<div class="hdlv2-dr-ai-label">Your analysis</div>' +
          wrapParagraphs(ai.opening) +
        '</div>';
    }

    // From your practitioner — Final only  (v0.20.6)
    // Renders the practitioner's curated health_summary + follow_up_actions.
    // These are client-visible fields from ai_organised_notes (not the raw
    // audio, not private clinical shorthand). Only rendered when content exists.
    if (isFinal && data.practitioner_notes) {
      var pn = data.practitioner_notes;
      var hasSummary = pn.health_summary && String(pn.health_summary).trim();
      var hasActions = Array.isArray(pn.follow_up_actions) && pn.follow_up_actions.length;
      if (hasSummary || hasActions) {
        var pracLogoInner = data.practitioner_logo_url
          ? '<img src="' + escape(data.practitioner_logo_url) + '" alt="' + escape(data.practitioner_name || '') + '">'
          : escape(((data.practitioner_name || 'MR').split(/\s+/).map(function (p) { return p[0]; }).filter(Boolean).slice(0, 2).join('')).toUpperCase());
        html +=
          '<section class="hdlv2-dr-practitioner-notes">' +
            '<div class="hdlv2-dr-practitioner-notes-header">' +
              '<div class="hdlv2-dr-practitioner-notes-avatar">' + pracLogoInner + '</div>' +
              '<div>' +
                '<div class="hdlv2-dr-ai-label" style="color:#004F59;margin-bottom:2px;">From your practitioner</div>' +
                '<h2>' + escape(data.practitioner_name || 'Your practitioner') + '</h2>' +
              '</div>' +
            '</div>' +
            (hasSummary
              ? '<div class="hdlv2-dr-practitioner-notes-summary">' + wrapParagraphs(String(pn.health_summary)) + '</div>'
              : ''
            ) +
            (hasActions
              ? '<div class="hdlv2-dr-practitioner-notes-actions">' +
                  '<div class="hdlv2-dr-ai-label" style="color:#8a5a00;">Follow-up actions</div>' +
                  '<ul>' +
                    pn.follow_up_actions.map(function (a) { return '<li>' + escape(String(a)) + '</li>'; }).join('') +
                  '</ul>' +
                '</div>'
              : ''
            ) +
          '</section>';
      }
    }

    // Awaken — client-facing diagnosis prose  (v0.20.5, extended v0.20.6)
    // Heading flips between Final and Draft phrasing so the client can see
    // the state of their report at a glance.
    if (data.awaken_content) {
      html +=
        '<section class="hdlv2-dr-section">' +
          '<div class="hdlv2-dr-ai-label">Awaken</div>' +
          '<h2>' + (isFinal ? 'Where You Are Now' : 'Your Current State') + '</h2>' +
          '<p class="hdlv2-dr-section-sub">' +
            (isFinal
              ? 'Your starting point after consultation.'
              : 'Where you are right now — the honest starting point.'
            ) +
          '</p>' +
          data.awaken_content +
        '</section>';
    }

    // Trajectory
    html +=
      '<section class="hdlv2-dr-section">' +
        '<h2>Your Trajectory</h2>' +
        '<p class="hdlv2-dr-section-sub">How your biological health is tracking compared to population percentile bands — rendered by the same engine as the longevity form.</p>' +
        '<div class="hdlv2-dr-trajectory" id="hdlv2-dr-trajectory-container"></div>' +
        (Array.isArray(ai.trajectory_commentary) && ai.trajectory_commentary.length
          ? '<div class="hdlv2-dr-insight">' +
              '<div class="hdlv2-dr-ai-label">What the curve is telling us</div>' +
              '<ul>' + ai.trajectory_commentary.map(function (b) { return '<li>' + b + '</li>'; }).join('') + '</ul>' +
            '</div>'
          : ''
        ) +
        // v0.36.3 — Yellow draft notes only on DRAFT view; FINAL has been
        // reviewed with the practitioner and the chart is no longer a draft.
        (isFinal ? '' :
          '<div class="hdlv2-dr-draft-note">' +
            '<span class="hdlv2-dr-draft-note-icon">⚑</span>' +
            '<div><strong>This is your draft trajectory chart.</strong> It\'ll be fine-tuned with your practitioner during your consultation.</div>' +
          '</div>'
        ) +
      '</section>';

    // Radar
    html +=
      '<section class="hdlv2-dr-section">' +
        '<h2>Your Health Profile</h2>' +
        '<p class="hdlv2-dr-section-sub">How you\'re scoring across the detailed longevity metrics (0 = low, 5 = excellent). Same 22 breakdown points and colour scale as the V1 longevity form.</p>' +
        '<div class="hdlv2-dr-radar-wrap"><canvas id="hdlv2-dr-radar"></canvas></div>' +
        renderRadarCommentary(ai.radar_commentary) +
        // v0.36.3 — DRAFT-only yellow note (mirrors trajectory above).
        (isFinal ? '' :
          '<div class="hdlv2-dr-draft-note">' +
            '<span class="hdlv2-dr-draft-note-icon">⚑</span>' +
            '<div><strong>This is your draft health profile.</strong> Your practitioner will review these scores with you and may adjust figures based on your consultation.</div>' +
          '</div>'
        ) +
      '</section>';

    // Goals linkage
    if (Array.isArray(ai.goals_linkage) && ai.goals_linkage.length) {
      html +=
        '<section class="hdlv2-dr-goals">' +
          '<div class="hdlv2-dr-ai-label">Tying this back to your goals</div>' +
          '<h2>What you told us — and what your data says</h2>' +
          '<p class="hdlv2-dr-section-sub">From what you shared in Stage 2 — how your data connects to what actually matters to you.</p>' +
          ai.goals_linkage.map(function (g) {
            return '<div class="hdlv2-dr-goal-item">' +
              '<div class="hdlv2-dr-goal-quote">"' + escape(g.goal_quote || '') + '"</div>' +
              '<div class="hdlv2-dr-goal-insight">' + (g.insight || '') + '</div>' +
            '</div>';
          }).join('') +
        '</section>';
    }

    // Lift — Claude's high-level action plan (v0.20.5, heading flipped v0.20.6).
    // Rendered before the tactical recs_preview cards so the client reads
    // "here's the plan" then "here are starting-point specifics" — narrative → tactics.
    if (data.lift_content) {
      html +=
        '<section class="hdlv2-dr-section">' +
          '<div class="hdlv2-dr-ai-label">Lift</div>' +
          '<h2>' + (isFinal ? 'What Needs to Change' : 'Your Action Plan') + '</h2>' +
          '<p class="hdlv2-dr-section-sub">' +
            (isFinal
              ? 'The changes your practitioner recommends.'
              : 'The high-impact changes that’ll move your trajectory.'
            ) +
          '</p>' +
          data.lift_content +
        '</section>';
    }

    // Recs preview — v0.36.3 — eyebrow + heading + sub copy + pill all
    // flip between DRAFT and FINAL voicing so a finalised report no longer
    // labels its starting-point recs as "Draft" or promises that the
    // practitioner will refine them later (the consultation already
    // happened by the time a Final exists).
    if (Array.isArray(ai.recs_preview) && ai.recs_preview.length) {
      html +=
        '<section class="hdlv2-dr-recs">' +
          '<div class="hdlv2-dr-ai-label">' + (isFinal ? 'Recommendations' : 'Draft recommendations') + '</div>' +
          '<h2>' + (isFinal ? 'Your starting-point plan' : 'Your practitioner\'s starting-point plan') + '</h2>' +
          '<p class="hdlv2-dr-section-sub">' +
            (isFinal
              ? 'First-pass suggestions from your data — your weekly Flight Plan turns these into specific actions.'
              : 'First-pass suggestions from your data — your practitioner will refine these in your consultation.'
            ) +
          '</p>' +
          ai.recs_preview.map(function (r, i) {
            return '<div class="hdlv2-dr-rec-item">' +
              '<div class="hdlv2-dr-rec-icon">' + (i + 1) + '</div>' +
              '<div class="hdlv2-dr-rec-body"><h3>' + escape(r.title || '') + '</h3><p>' + (r.body || '') + '</p></div>' +
              (isFinal ? '' : '<span class="hdlv2-dr-rec-pill">Draft</span>') +
            '</div>';
          }).join('') +
        '</section>';
    }

    // Thrive — closing motivational narrative (v0.20.5, heading flipped v0.20.6).
    // Positioned before Milestones + "What happens next" so the client ends
    // on the vision of what this plan unlocks before the tangible steps.
    if (data.thrive_content) {
      html +=
        '<section class="hdlv2-dr-section">' +
          '<div class="hdlv2-dr-ai-label">Thrive</div>' +
          '<h2>' + (isFinal ? 'What’s Possible' : 'Your Future Self') + '</h2>' +
          '<p class="hdlv2-dr-section-sub">' +
            (isFinal
              ? 'What this plan unlocks — your future self.'
              : 'What this plan unlocks — the life you’re building toward.'
            ) +
          '</p>' +
          data.thrive_content +
        '</section>';
    }

    // Milestone Timeline  (v0.20.6)
    // AI-generated milestones bucketed into 6mo / 2yr / 5yr / 10+yr. Rendered
    // on both draft and final when present in the report row. Colour tiers
    // match the existing practitioner-side preview (teal → green → amber →
    // softer red) so the visual language is consistent across the platform.
    var milestones = data.milestones || {};
    var msIntervals = [
      { key: 'six_months',      label: '6 Months',  color: '#3d8da0', tintClass: 'hdlv2-dr-ms-teal' },
      { key: 'two_years',       label: '2 Years',   color: '#10b981', tintClass: 'hdlv2-dr-ms-green' },
      { key: 'five_years',      label: '5 Years',   color: '#f59e0b', tintClass: 'hdlv2-dr-ms-amber' },
      { key: 'ten_plus_years',  label: '10+ Years', color: '#be5a4a', tintClass: 'hdlv2-dr-ms-ruby' }
    ];
    var msHasAny = false;
    for (var mi = 0; mi < msIntervals.length; mi++) {
      if (Array.isArray(milestones[msIntervals[mi].key]) && milestones[msIntervals[mi].key].length) { msHasAny = true; break; }
    }
    if (msHasAny) {
      var msHtml = '';
      for (var i = 0; i < msIntervals.length; i++) {
        var iv = msIntervals[i];
        var items = milestones[iv.key] || [];
        if (!items.length) continue;
        msHtml +=
          '<div class="hdlv2-dr-ms-interval ' + iv.tintClass + '">' +
            '<div class="hdlv2-dr-ms-label">' + iv.label + '</div>' +
            '<ul class="hdlv2-dr-ms-list">' +
              items.map(function (m) {
                var text = (m && typeof m === 'object') ? (m.milestone || m.text || '') : String(m);
                return '<li>' + escape(String(text)) + '</li>';
              }).join('') +
            '</ul>' +
          '</div>';
      }
      html +=
        '<section class="hdlv2-dr-section hdlv2-dr-milestones">' +
          '<div class="hdlv2-dr-ai-label">Milestone timeline</div>' +
          '<h2>What you’re aiming for</h2>' +
          '<p class="hdlv2-dr-section-sub">Personalised milestones generated from your data and your Stage 2 WHY — what progress could look like over time.</p>' +
          msHtml +
        '</section>';
    }

    // What's next
    html +=
      '<section class="hdlv2-dr-next">' +
        '<h2>What happens next</h2>' +
        '<p>This report isn\'t finished yet — it\'s the starting point for the conversation with your practitioner.</p>' +
        '<ol class="hdlv2-dr-steps">' +
          '<li><strong>Book (or attend) your consultation</strong> with your practitioner — they\'ll walk you through these numbers in detail.</li>' +
          '<li><strong>Your final report arrives after the consultation</strong> — with refined numbers, personal recommendations, and a weekly action plan.</li>' +
          '<li><strong>Weekly check-ins begin</strong> so you can track progress together.</li>' +
        '</ol>' +
      '</section>';

    // Footer
    html += renderFooter(data);

    ROOT.innerHTML = html;

    // Charts
    setTimeout(function () {
      renderTrajectory(calc);
      renderRadar(calc);
    }, 50);
  }

  // ── Pace of Ageing section ──
  // Replaces the older flat .hdlv2-dr-stats grid. The gauge becomes the
  // visual hero for the rate of ageing — same QuickChart.io PNG used on
  // Stage 1 (HDLSpeedometer.buildUrl) — with the three numbers (rate,
  // bio age, chrono age) demoted to a support row beneath. Defensive:
  // gauge silently omits if calc.rate is missing or HDLSpeedometer is
  // not loaded, so the page still renders with the stat cards alone.
  function renderPaceSection(calc) {
    var rate   = calc.rate;
    var bioAge = calc.bioAge;
    var chrono = calc.chronoAge;

    var rateDelta = '';
    var rateClass = '';
    if (typeof rate === 'number') {
      if (rate < 0.95)      { rateDelta = 'Ageing slower than average'; rateClass = 'good'; }
      else if (rate > 1.05) { rateDelta = 'Accelerated — let\'s work on this'; rateClass = 'warn'; }
      else                   { rateDelta = 'On pace with average'; rateClass = ''; }
    }

    var bioDelta = '';
    var bioClass = '';
    if (typeof bioAge === 'number' && typeof chrono === 'number') {
      var diff = +(bioAge - chrono).toFixed(1);
      if (diff > 0)      { bioDelta = '+' + diff + ' yrs vs chronological'; bioClass = 'warn'; }
      else if (diff < 0) { bioDelta = diff + ' yrs vs chronological'; bioClass = 'good'; }
      else                { bioDelta = 'In line with chronological'; bioClass = ''; }
    }

    var gaugeHtml = '';
    if (typeof rate === 'number' && !isNaN(rate)
        && typeof window.HDLSpeedometer !== 'undefined'
        && typeof window.HDLSpeedometer.buildUrl === 'function') {
      // v0.23.0 — Stage 3 calc returns 0.5-2.0; pass stage3:true so the gauge
      // bounds match. Without this the needle pinned at 0.8 / 1.4 and silently
      // contradicted the rate text in the stat card next to it.
      var gaugeUrl = window.HDLSpeedometer.buildUrl(rate, { width: 420, height: 380, stage3: true });
      gaugeHtml =
        '<div class="hdlv2-dr-gauge-wrap">' +
          '<img src="' + gaugeUrl + '" width="420" height="380" ' +
               'alt="Rate of ageing gauge" loading="eager">' +
        '</div>';
    }

    return '<section class="hdlv2-dr-pace">' +
      '<div class="hdlv2-dr-pace-head">' +
        '<div class="hdlv2-dr-ai-label" style="display:inline-flex;">Headline metric</div>' +
        '<h2>Your Pace of Ageing</h2>' +
        '<p>How fast you\'re ageing biologically compared to the calendar. ' +
          '<strong>1.0× = on pace.</strong> Below = slower (good). Above = faster (we\'ll work on this).</p>' +
      '</div>' +
      gaugeHtml +
      '<div class="hdlv2-dr-pace-stats">' +
        statCard('Rate of Ageing', typeof rate === 'number' ? rate.toFixed(2) : '—', '×', rateDelta, rateClass, true) +
        statCard('Biological Age', typeof bioAge === 'number' ? bioAge.toFixed(1) : '—', 'yrs', bioDelta, bioClass, false) +
        statCard('Chronological Age', typeof chrono === 'number' ? String(chrono) : '—', 'yrs', 'Your real age', '', false) +
      '</div>' +
    '</section>';
  }

  function statCard(label, value, unit, delta, deltaClass, highlight) {
    return '<div class="hdlv2-dr-stat-card' + (highlight ? ' highlight' : '') + '">' +
      '<p class="hdlv2-dr-stat-label">' + escape(label) + '</p>' +
      '<p class="hdlv2-dr-stat-value">' + escape(value) + '<span class="hdlv2-dr-stat-unit">' + escape(unit) + '</span></p>' +
      '<p class="hdlv2-dr-stat-delta' + (deltaClass ? ' ' + deltaClass : '') + '">' + escape(delta) + '</p>' +
    '</div>';
  }

  // ── Radar commentary split ──
  function renderRadarCommentary(rc) {
    if (!rc || (!Array.isArray(rc.strengths) && !Array.isArray(rc.focus_areas))) return '';
    var s = (rc.strengths || []).map(function (item) {
      return '<li><strong>' + escape(item.metric || '') +
        (typeof item.score === 'number' ? ' (' + formatScore(item.score) + '/5)' : '') +
        '</strong> — ' + (item.note || '') + '</li>';
    }).join('');
    var f = (rc.focus_areas || []).map(function (item) {
      return '<li><strong>' + escape(item.metric || '') +
        (typeof item.score === 'number' ? ' (' + formatScore(item.score) + '/5)' : '') +
        '</strong> — ' + (item.note || '') + '</li>';
    }).join('');
    if (!s && !f) return '';
    return '<div class="hdlv2-dr-ai-label" style="padding:0 4px;">Top strengths · Focus areas</div>' +
      '<div class="hdlv2-dr-radar-split">' +
        '<div class="strengths"><h4>✓ Top strengths</h4><ul>' + s + '</ul></div>' +
        '<div class="focus"><h4>⚡ Focus areas</h4><ul>' + f + '</ul></div>' +
      '</div>';
  }

  function formatScore(n) {
    return (Math.round(n * 10) / 10).toString();
  }

  // ── Footer ──
  function renderFooter(data) {
    var pracName = data.practitioner_name || 'Your practitioner';
    var initials = (pracName || 'MR').split(/\s+/).map(function (p) { return p[0]; }).filter(Boolean).slice(0, 2).join('').toUpperCase();
    var logoHtml = data.practitioner_logo_url
      ? '<img src="' + escape(data.practitioner_logo_url) + '" alt="' + escape(pracName) + '">'
      : escape(initials);
    return '<footer class="hdlv2-dr-footer">' +
      '<div class="hdlv2-dr-prac-info">' +
        '<div class="hdlv2-dr-prac-logo">' + logoHtml + '</div>' +
        '<div><p class="hdlv2-dr-prac-name">' + escape(pracName) + '</p>' +
        '<p class="hdlv2-dr-prac-role">Your longevity practitioner</p></div>' +
      '</div>' +
      '<div class="hdlv2-dr-mark">Powered by <strong>HealthDataLab</strong></div>' +
    '</footer>';
  }

  // ── Trajectory chart ──
  function renderTrajectory(calc) {
    if (typeof HDLTrajectoryChart === 'undefined' || !HDLTrajectoryChart.render) {
      setTimeout(function () { renderTrajectory(calc); }, 150);
      return;
    }
    if (typeof calc.chronoAge !== 'number' || typeof calc.rate !== 'number') return;
    HDLTrajectoryChart.render('#hdlv2-dr-trajectory-container', {
      chronoAge: calc.chronoAge,
      agingRate: calc.rate,
      showBands: true,
      showProjections: true
    });
  }

  // ── Radar chart ──
  function renderRadar(calc) {
    if (typeof Chart === 'undefined') {
      setTimeout(function () { renderRadar(calc); }, 150);
      return;
    }
    var scores = calc.scores || {};
    var ALL_KEYS = [
      'physicalActivity', 'sitToStand', 'breathHold', 'balance', 'sleepDuration',
      'sleepQuality', 'stressLevels', 'socialConnections', 'dietQuality', 'alcoholConsumption',
      'smokingStatus', 'cognitiveActivity', 'sunlightExposure', 'supplementIntake', 'dailyHydration',
      'skinElasticity', 'bmiScore', 'whrScore', 'whtrScore', 'bloodPressureScore', 'heartRateScore'
      // v0.23.1 — overallHealthScore removed (Matthew 2026-04-28); 22→21 metrics.
    ];
    var keys = ALL_KEYS.filter(function (k) { return scores[k] !== undefined && scores[k] !== null && !isNaN(scores[k]); });
    if (!keys.length) return;

    function formatLabel(k) {
      return k.replace(/([A-Z])/g, ' $1')
              .replace(/^./, function (s) { return s.toUpperCase(); })
              .replace(' Score', '')
              .replace('Bmi', 'BMI')
              .replace('Whr', 'WHR');
    }

    var rawScores = keys.map(function (k) { return Number(scores[k]); });
    var pointColors = rawScores.map(function (v) {
      if (v >= 4.5) return 'rgba(0, 180, 0, 1)';
      if (v >= 3.5) return 'rgba(76, 187, 23, 1)';
      if (v >= 3.0) return 'rgba(156, 204, 10, 1)';
      if (v >= 2.5) return 'rgba(255, 204, 0, 1)';
      if (v >= 2.0) return 'rgba(255, 149, 0, 1)';
      if (v >= 1.5) return 'rgba(255, 59, 48, 1)';
      return 'rgba(215, 0, 21, 1)';
    });

    var canvas = document.getElementById('hdlv2-dr-radar');
    if (!canvas) return;
    new Chart(canvas.getContext('2d'), {
      type: 'radar',
      data: {
        labels: keys.map(formatLabel),
        datasets: [{
          label: 'Health Metrics (0–5)',
          data: rawScores,
          fill: true,
          backgroundColor: 'rgba(61, 141, 160, 0.15)',
          borderColor: 'rgba(61, 141, 160, 0.85)',
          pointBackgroundColor: pointColors,
          pointBorderColor: '#fff',
          pointBorderWidth: 1.5,
          pointRadius: 5,
          pointHoverRadius: 7,
          borderWidth: 2
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        aspectRatio: 1,
        layout: { padding: { top: 40, right: 45, bottom: 40, left: 45 } },
        animation: { duration: 1000, easing: 'easeOutQuart' },
        // v0.24.11 — B6 fix: 21 labels at 12px collide on viewports below ~1000px.
        // Dynamically scale font + padding via onResize so the chart stays
        // legible on tablet (768) + mobile (414) without an overflow scroll.
        onResize: function (chart, size) {
          var w = size.width || 0;
          var fontSize = w >= 700 ? 12 : w >= 550 ? 11 : w >= 420 ? 10 : w >= 340 ? 9 : 8;
          var padding  = w >= 700 ? 10 : w >= 420 ? 6  : 3;
          var pointR   = w >= 700 ? 5  : w >= 420 ? 4  : 3;
          var pl = chart.options.scales.r.pointLabels;
          if (pl && pl.font) pl.font.size = fontSize;
          if (pl) pl.padding = padding;
          if (chart.data && chart.data.datasets[0]) chart.data.datasets[0].pointRadius = pointR;
        },
        scales: {
          r: {
            min: 0, max: 5, beginAtZero: true,
            grid:       { color: 'rgba(0,0,0,0.08)', lineWidth: 1 },
            angleLines: { color: 'rgba(0,0,0,0.08)', lineWidth: 1 },
            pointLabels: {
              font: { family: 'Inter', size: 12, weight: '500' },
              color: '#2C3E50',
              padding: 10,
              callback: function (label) {
                var parts = String(label).split(' ');
                if (parts.length === 1) return label;
                if (parts.length === 2) return parts;
                return [parts.slice(0, -1).join(' '), parts[parts.length - 1]];
              }
            },
            ticks: { stepSize: 1, font: { family: 'Inter', size: 10 }, color: '#636366', showLabelBackdrop: false }
          }
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: 'rgba(44, 62, 80, 0.9)',
            padding: 10, cornerRadius: 6,
            callbacks: {
              label: function (ctx) {
                var v = ctx.raw;
                var band = v >= 4.5 ? 'Excellent'   : v >= 3.5 ? 'Good'
                          : v >= 3.0 ? 'Above Average' : v >= 2.5 ? 'Average'
                          : v >= 2.0 ? 'Below Average' : v >= 1.5 ? 'Poor' : 'Very Poor';
                return 'Score: ' + v + ' (' + band + ')';
              },
              title: function (items) {
                return formatLabel(keys[items[0].dataIndex]);
              }
            }
          }
        }
      }
    });
  }

  // ── Utils ──
  function wrapParagraphs(s) {
    if (!s) return '';
    return s.split(/\n\s*\n|\n/).map(function (p) {
      p = p.trim();
      return p ? '<p>' + p + '</p>' : '';
    }).join('');
  }

  function escape(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }
})();
