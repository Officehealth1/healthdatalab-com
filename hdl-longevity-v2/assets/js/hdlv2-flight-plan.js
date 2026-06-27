/**
 * HDL V2 Flight Plan — Interactive weekly plan with tappable action rows.
 * Tappable action rows with 2-state toggle: default ↔ done.
 * @package HDL_Longevity_V2
 * @since 0.9.3
 */
(function () {
  'use strict';

  var DAYS = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
  var DAY_LABELS = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
  // Category bands — order is the on-screen vertical order per day.
  // Internal keys (nutrition/movement/key_action) are unchanged from the API
  // contract; only the user-facing labels shift (Nutrition → Food, Movement →
  // Fitness, Key Actions → Lifestyle) to match Matthew's scan-readability model.
  var BANDS = [
    { key: 'nutrition',  label: 'Food',      icon: '\ud83e\udd57', cls: 'food' },
    { key: 'movement',   label: 'Fitness',   icon: '\ud83c\udfc3', cls: 'fitness' },
    { key: 'key_action', label: 'Lifestyle', icon: '\u2b50',        cls: 'lifestyle' }
  ];

  // v0.46.59 — itshover.com icon geometry, 1:1 (pen-icon, user-plus-icon's
  // plus sub-element via a cropped viewBox, download-icon). The set ships as
  // React/Motion components; this stack has no build step, so the hover
  // motion is approximated in CSS keyframes (approved 2026-06-12).
  var ICON_PEN = '<svg class="fp-ico fp-ico-pen" viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m10.5,27.5l-8,2 2-8L22.257,3.743c1.657-1.657,4.343-1.657,6,0s1.657,4.343,0,6L10.5,27.5Z"/></svg>';
  var ICON_PLUS = '<svg class="fp-ico fp-ico-plus" viewBox="13.5 13.5 11 11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 19h6"/><path d="M19 16v6"/></svg>';
  var ICON_DL = '<svg class="fp-ico fp-ico-dl" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><g class="fp-dl-arrow"><path d="M12 15V3"/><path d="m7 10 5 5 5-5"/></g></svg>';

  // mount() embeds an interactive flight-plan into the supplied root.
  // The /my-flight-plan/ shortcode auto-mounts via the bottom of this file;
  // the practitioner /clients/ expand-panel mounts manually via
  // window.HDLV2_FlightPlan.mount() so practitioner ticks share the same
  // /flight-plan/tick endpoint and adherence pipeline as the client.
  function mount(rootEl, opts) {
    opts = opts || {};
    var CFG = opts.cfg || window.hdlv2_flight_plan || {};
    if (!CFG.api_base) return;
    if (!rootEl) return;

    var root = rootEl;
    var clientId = opts.client_id || CFG.client_id || new URLSearchParams(window.location.search).get('client_id') || '';
    var token = opts.token || new URLSearchParams(window.location.search).get('token') || '';
    // v0.46.59 — editor v2 + download control flags. editable_controls is
    // passed ONLY by the practitioner dashboard; the client page never sets
    // it (and the /edit endpoint is practitioner-IDOR-gated regardless).
    var canEdit = !!opts.editable_controls;
    var suppressDownload = !!opts.suppress_download;
    var editMode = false;
    var lastPlan = null;
    var lastTicks = null;
    var tickTimer = null;
    var pendingTicks = {};
    // v0.24.6 — realtime sync state. Tick poll fetches /current every 20s
    // while visible so client + practitioner see each other's ticks without
    // a manual reload. Self-terminates when root is detached.
    var currentPlanId = null;
    var tickPollTimer = null;
    var tickVisHandler = null;
    var tickFocusHandler = null;
    // v0.24.12 — B2 fix. Tracks when flushTicks last fired so refresh()
    // can defer for ~5s and let the server commit before re-reading state.
    // Without this, a poll firing between flushTicks's debounced POST and
    // its server commit can revert the optimistic data-state for ~20s.
    var lastTickFlushAt = 0;
    // v0.27.1 — fix #16 (/ultrareview): hoisted to mount-scope so a plan
    // regeneration mid-session can clear the previous PDF-poll setInterval
    // before a new one starts. Previously the old timer kept polling
    // /current for the archived plan's pdf_url forever.
    var pdfPollTimer = null;

  function init() {
    if (!clientId && !token) { root.innerHTML = '<p style="color:#888;">No client specified.</p>'; return; }
    // v0.37.0 \u2014 shaped skeleton (week pill + identity line + 5 action rows)
    // replaces blank spinner on initial mount. The repair-poll and tick-poll
    // re-render via renderPlan() \u2014 they do NOT re-trigger init(), so the
    // skeleton never re-flashes during background sync.
    root.innerHTML = (window.HDLV2Loading && typeof HDLV2Loading.skeleton === 'function')
      ? HDLV2Loading.skeleton('flightPlan')
      : '<div class="hdlv2-card"><div class="hdlv2-loading"><div class="hdlv2-spinner"></div><p style="color:#888;">Loading flight plan\u2026</p></div></div>';
    loadCurrent();
  }

  // v0.31.1 — repair-poll state. When the backend flags `is_regenerating`,
  // we set this so the poll loop replaces itself once the new plan ID
  // differs from the sparse one we initially rendered.
  var repairBaselinePlanId = null;
  var repairPollTimer = null;

  function loadCurrent() {
    var url = CFG.api_base + '/' + clientId + '/current';
    if (token) url += '?token=' + encodeURIComponent(token);
    fetch(url, { headers: { 'X-WP-Nonce': CFG.nonce } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.plan) {
          renderPlan(data.plan, data.ticks);
          startTickPoll();
          // v0.31.1 — kick the auto-repair poll if the backend told us
          // it's regenerating this plan in the background.
          if (data.plan.is_regenerating) {
            repairBaselinePlanId = data.plan.id;
            startRepairPoll();
          }
        } else {
          renderEmpty();
        }
      })
      .catch(function () { root.innerHTML = '<div class="hdlv2-card"><p style="color:#dc2626;padding:24px;">Error loading plan.</p></div>'; });
  }

  // v0.31.1 — Poll /current every 8s for up to 2 minutes, looking for a
  // plan ID different from the one we initially rendered. When we see it,
  // re-render with the fresh data and stop polling.
  function startRepairPoll() {
    if (repairPollTimer) clearInterval(repairPollTimer);
    var startedAt = Date.now();
    var MAX_MS   = 120000;
    var INTERVAL = 8000;
    repairPollTimer = setInterval(function () {
      if (Date.now() - startedAt > MAX_MS) {
        clearInterval(repairPollTimer); repairPollTimer = null;
        return;
      }
      var url = CFG.api_base + '/' + clientId + '/current' + (token ? '?token=' + encodeURIComponent(token) : '');
      fetch(url, { headers: { 'X-WP-Nonce': CFG.nonce }, cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.plan) return;
          // New plan ID = repair completed. Re-render and stop polling.
          if (data.plan.id && data.plan.id !== repairBaselinePlanId) {
            clearInterval(repairPollTimer); repairPollTimer = null;
            repairBaselinePlanId = null;
            renderPlan(data.plan, data.ticks);
            startTickPoll();
          }
        })
        .catch(function () { /* transient — keep polling */ });
    }, INTERVAL);
  }

  function renderEmpty() {
    root.innerHTML = '<div class="hdlv2-card"><div style="text-align:center;padding:40px 20px;">'
      + '<p style="font-size:48px;margin:0 0 12px;">\ud83d\udccb</p>'
      + '<h3 style="margin:0 0 8px;">Your Flight Plan is being prepared</h3>'
      + '<p style="color:#888;font-size:14px;">Check back soon \u2014 your practitioner will generate your first weekly plan after your consultation.</p>'
      + '</div></div>';
  }

  function renderPlan(plan, ticks) {
    lastPlan = plan; lastTicks = ticks || [];
    currentPlanId = plan.id;
    var planData = plan.plan_data || {};
    var dailyPlan = planData.daily_plan || planData;

    // Calculate date range from week_start (never trust AI for dates)
    var _ws = new Date(plan.week_start + 'T00:00:00');
    var _we = new Date(_ws); _we.setDate(_we.getDate() + 6);
    var _m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var dateLabel = _ws.getDate() + ' ' + _m[_ws.getMonth()] + ' – ' + _we.getDate() + ' ' + _m[_we.getMonth()] + ' ' + _we.getFullYear();

    var html = '<div class="hdlv2-card">'
      + '<div class="hdlv2-header"><h2>Week ' + plan.week_number + ' Flight Plan</h2>'
      + '<p>' + dateLabel + '</p></div>';

    // Identity statement — the AI sometimes wraps its own output in smart
    // quotes, and we add our own; strip any leading/trailing quotes from the
    // raw value so we never end up with double-wrapped text.
    if (plan.identity_statement) {
      var identity = String(plan.identity_statement).replace(/^[\u201c\u201d"\s]+|[\u201c\u201d"\s]+$/g, '');
      html += '<div class="fp-identity-statement" data-editable-wrap="identity_statement">'
        + '<span class="fp-edit-text">\u201c' + esc(identity) + '\u201d</span>'
        + (canEdit && editMode ? '<button type="button" class="fp-pen" data-edit-field="identity_statement" aria-label="Edit identity statement">' + ICON_PEN + '</button>' : '')
        + '</div>';
    }

    // Flexibility note
    var flexNote = planData.flexibility_note || 'This plan is your guide, not your boss. Tick the boxes when you can. Adjust when you need to.';
    html += '<div class="fp-flex-note hdlv2-fp-noprint">' + esc(flexNote) + '</div>';

    // v0.31.1 — auto-repair banner. Shown when the backend told us this
    // plan is being regenerated in the background because it had fewer
    // populated days than expected. The repair-poll loop swaps in the new
    // plan once it lands — usually within 15-30s.
    if (plan.is_regenerating) {
      html += '<div class="fp-repair-banner hdlv2-fp-noprint" role="status" aria-live="polite">'
        + '<div class="fp-repair-spinner" aria-hidden="true"></div>'
        + '<div><strong>Updating your plan…</strong> we noticed some days were missing actions. Your full week will appear here in a moment — no need to refresh.</div>'
        + '</div>';
    } else if (plan.is_sparse) {
      // Sparse but NOT regenerating (cooldown active or already tried).
      // Show a softer notice so the client knows to ask the practitioner.
      html += '<div class="fp-repair-banner hdlv2-fp-noprint" role="status" style="background:#fffbeb;border-color:#fde68a;color:#92400e;">'
        + '<span aria-hidden="true">⚠️</span>'
        + '<div>This plan looks incomplete. Your practitioner has been notified — refresh in a few minutes for the full week.</div>'
        + '</div>';
    }

    // Transfer from paper UX
    // v0.46.60 \u2014 single Tick-All / Untick-All toggle (Matthew): one button
    // that flips its label + style based on whether every action is ticked.
    var _allTicked = !!(ticks && ticks.length) && ticks.every(function (t) { return !!t.ticked; });
    html += '<div class="fp-transfer-box hdlv2-fp-noprint">'
      + '<div class="fp-transfer-title">Transferring from your printed plan?</div>'
      + '<p class="fp-transfer-desc">Tick All, then tap items you didn\u2019t do. Done in seconds.</p>'
      + '<div class="fp-transfer-actions">'
      + '<button id="hdlv2-fp-tickall" type="button" class="fp-btn ' + (_allTicked ? 'fp-btn-secondary' : 'fp-btn-primary') + '" aria-pressed="' + (_allTicked ? 'true' : 'false') + '">' + (_allTicked ? 'Untick All' : '\u2705 Tick All Days') + '</button>'
      + '<button id="hdlv2-fp-calc" class="fp-btn fp-btn-outline">Calculate My Adherence</button>'
      // v0.46.59 — window.print() retired everywhere (Matthew, 2026-06-12).
      // Two-state Download control: pdf_url → live self-hosted link;
      // pdf_expected → "preparing" + poll. Legacy plans with neither show
      // nothing. The practitioner dashboard suppresses this entirely — its
      // head-card pill is the single download affordance there.
      + (suppressDownload ? '' : (plan.pdf_url
          ? '<a href="' + esc(plan.pdf_url) + '" target="_blank" rel="noopener" class="fp-btn fp-btn-download">' + ICON_DL + '<span>Download PDF</span></a>'
          : (plan.pdf_expected
              ? '<button id="hdlv2-fp-pdf-pending" type="button" class="fp-btn fp-btn-download" disabled aria-live="polite">PDF preparing\u2026</button>'
              : '')))
      + '</div></div>';

    // Build tick lookup by day
    var ticksByDay = {};
    DAYS.forEach(function (d) { ticksByDay[d] = []; });
    ticks.forEach(function (t) {
      var day = t.day || DAYS[(t.day_of_week || 1) - 1] || 'monday';
      if (ticksByDay[day]) ticksByDay[day].push(t);
    });

    // Day grid
    //
    // The same DOM drives two layouts:
    //   • Screen → 7-col CSS grid (Matthew's approved band-per-day view).
    //   • Print  → one day per landscape-A4 page (see @media print rules
    //              in hdlv2-flight-plan.css). Each .fp-day-column is
    //              promoted to a full page with expanded typography, a
    //              full date header, and the day's WHY anchor at the foot.
    //
    // We enrich each column with data-full-date and a per-day WHY anchor
    // (extracted from plan_data.daily_plan) so the print stylesheet has
    // everything it needs without another round-trip.
    // v0.46.59 — pre-start days collapse to ONE slim stub; the grid only
    // gets columns for active days (class drives the column template).
    var _effStartIdxEarly = DAYS.indexOf((plan.effective_start_day || 'monday').toLowerCase());
    if (_effStartIdxEarly < 0) _effStartIdxEarly = 0;
    var _activeDays = 7 - _effStartIdxEarly;
    html += '<div class="fp-grid-wrap"><div class="fp-week-grid fp-week-grid--n' + _activeDays
      + (_effStartIdxEarly > 0 ? ' fp-week-grid--stub' : '') + '">';
    if (_effStartIdxEarly > 0) {
      html += '<div class="fp-prestart-stub"><span>Week starts<br><strong>' + esc(['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'][_effStartIdxEarly]) + '</strong></span></div>';
    }
    var _m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var _fullDayLabels = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

    // v0.31.0 — R10 + R4 frontend. Compute "today" relative to the plan's
    // calendar week so we can grey out days that are PAST or that fall
    // BEFORE the plan's effective_start_day (mid-week Final Report start).
    //
    // States per column:
    //   - past         → before today, regardless of plan content
    //   - pre-start    → before plan.effective_start_day (Week 1 mid-week)
    //   - today        → highlighted current day
    //   - upcoming     → future days
    var _todayMidnight = new Date(); _todayMidnight.setHours(0,0,0,0);
    var _effStart = (plan.effective_start_day || 'monday').toLowerCase();
    var _effStartIdx = DAYS.indexOf(_effStart);
    if (_effStartIdx < 0) _effStartIdx = 0;

    for (var d = _effStartIdxEarly; d < 7; d++) {
      var dayName = DAYS[d];
      var dayTicks = ticksByDay[dayName] || [];

      // Full calendar date for this day (e.g. "13 Apr 2026")
      var _dt = new Date(_ws); _dt.setDate(_ws.getDate() + d);
      var fullDateLabel = _dt.getDate() + ' ' + _m[_dt.getMonth()] + ' ' + _dt.getFullYear();

      // Day-state classification for rendering.
      var _dtMid = new Date(_dt); _dtMid.setHours(0,0,0,0);
      var _isPast    = _dtMid.getTime() < _todayMidnight.getTime();
      var _isToday   = _dtMid.getTime() === _todayMidnight.getTime();
      var _isPreStart = d < _effStartIdx; // before Week 1's mid-week start
      var _dayStateClass = _isPreStart
        ? 'fp-day-prestart'
        : _isPast
          ? 'fp-day-past'
          : _isToday
            ? 'fp-day-today'
            : 'fp-day-upcoming';

      // WHY anchor for this day — the AI stores it alongside actions in
      // plan_data.daily_plan[<day>][<slot>] with type=why_anchor. It is
      // NOT in the ticks table (why_anchors are not tickable), so we pull
      // it from plan_data directly. Try both capitalisations of the key.
      var whyAnchor = '';
      var daySlots = dailyPlan && (dailyPlan[dayName] || dailyPlan[_fullDayLabels[d]]) || null;
      if (daySlots && typeof daySlots === 'object') {
        Object.keys(daySlots).some(function (slotKey) {
          var items = daySlots[slotKey];
          if (!Array.isArray(items)) return false;
          for (var i = 0; i < items.length; i++) {
            if (items[i] && items[i].type === 'why_anchor') {
              whyAnchor = cleanActionText(items[i].text || items[i].action || '');
              if (whyAnchor) return true;
            }
          }
          return false;
        });
      }

      // v0.31.0 — pre-start days (Mon when plan starts Tue) get a friendlier
      // label than "No actions". Past days are tickable (clients legitimately
      // forget) so they keep the Tick All button — only pre-start days lose
      // it because they were never part of the plan.
      var _tickAllBtn = _isPreStart
        ? ''
        : '<button data-day-tick="' + dayName + '" class="fp-day-tickall hdlv2-fp-noprint">Tick all</button>';

      html += '<div class="fp-day-column ' + _dayStateClass + '" data-full-date="' + esc(fullDateLabel) + '"'
        + (_isPreStart ? ' data-prestart="1"' : '')
        + (_isToday    ? ' data-today="1"'    : '')
        + '>'
        + '<div class="fp-day-header">'
        + '<span class="fp-day-short">' + DAY_LABELS[d] + '</span>'
        + '<span class="fp-day-full">' + esc(_fullDayLabels[d]) + ' &middot; ' + esc(fullDateLabel) + '</span>'
        + _tickAllBtn
        + '</div>';

      if (_isPreStart) {
        // unreachable post-v0.46.59 (loop starts at the effective start day)
        html += '<div class="fp-day-empty fp-day-empty--prestart">Plan starts ' + esc(_fullDayLabels[_effStartIdx]) + '</div>';
      } else if (!dayTicks.length && !(canEdit && editMode)) {
        html += '<div class="fp-day-empty">No actions</div>';
      } else {
        // Bucket ticks by category so each day renders as 3 ordered bands.
        // Any tick with an unrecognised / missing category lands in the
        // Lifestyle bucket rather than getting dropped on the floor.
        var bucket = { nutrition: [], movement: [], key_action: [] };
        dayTicks.forEach(function (t) {
          var cat = (t.category && bucket[t.category]) ? t.category : 'key_action';
          bucket[cat].push(t);
        });

        BANDS.forEach(function (band) {
          var items = bucket[band.key] || [];
          var showEmpty = canEdit && editMode; // "+" needs the band header even when empty
          if (!items.length && !showEmpty) return; // collapse empty bands (read mode)
          html += '<div class="fp-band fp-band--' + band.cls + '">'
            + '<div class="fp-band-header">'
            + '<span class="fp-band-icon" aria-hidden="true">' + band.icon + '</span>'
            + '<span class="fp-band-label">' + band.label + '</span>'
            + (canEdit && editMode ? '<button type="button" class="fp-add" data-add-day="' + dayName + '" data-add-cat="' + band.key + '" aria-label="Add ' + band.label + ' action to ' + dayName + '">' + ICON_PLUS + '</button>' : '')
            + '</div>';
          items.forEach(function (t) {
            var state = t.ticked ? 'done' : 'default';
            html += '<div class="fp-action" data-tick-id="' + t.id + '" data-state="' + state + '" data-day="' + dayName + '" data-category="' + (t.category || '') + '">'
              + '<span class="fp-edit-text">' + esc(cleanActionText(t.action_text)) + '</span>'
              + (canEdit && editMode ? '<button type="button" class="fp-pen" data-edit-tick="' + t.id + '" aria-label="Edit this action">' + ICON_PEN + '</button>' : '')
              + '</div>';
          });
          html += '</div>';
        });
      }

      // Per-day WHY anchor — hidden on screen, rendered at the foot of
      // each day page in print so the motivational quote stays with the
      // day it belongs to (not just at the top of the plan).
      if (whyAnchor) {
        html += '<div class="fp-day-why hdlv2-print-only">&ldquo;' + esc(whyAnchor) + '&rdquo;</div>';
      }

      html += '</div>';
    }
    html += '</div></div>';

    // Adherence summary container
    html += '<div id="hdlv2-fp-adherence" style="padding:0 24px 16px;"></div>';

    // Weekly targets
    if (plan.weekly_targets && plan.weekly_targets.length) {
      html += '<div class="fp-section"><h4 class="fp-section-header">\ud83c\udfaf Weekly Targets</h4><ul class="fp-targets-list">';
      plan.weekly_targets.forEach(function (t, ti) {
        html += '<li data-editable-wrap="weekly_target" data-target-index="' + ti + '">'
          + '<span class="fp-edit-text">' + esc(typeof t === 'string' ? t : t.text || t.target || '') + '</span>'
          + (canEdit && editMode ? '<button type="button" class="fp-pen" data-edit-target="' + ti + '" aria-label="Edit this target">' + ICON_PEN + '</button>' : '')
          + '</li>';
      });
      html += '</ul></div>';
    }

    // Shopping list
    if (plan.shopping_list && plan.shopping_list.length) {
      html += '<div class="fp-section"><h4 class="fp-section-header">\ud83d\uded2 Shopping List</h4><ul class="fp-shopping-list">';
      plan.shopping_list.forEach(function (i) { html += '<li class="fp-shopping-item">' + esc(typeof i === 'string' ? i : i.name || '') + '</li>'; });
      html += '</ul></div>';
    }

    // Journey assistance — wrapped in a single .fp-section so the print
    // stylesheet's page-break-inside:avoid keeps the heading and paragraph
    // together (previously they were siblings and the body could break off
    // onto a page on its own, leaving an orphan heading behind).
    if (plan.journey_assistance) {
      html += '<div class="fp-section"><h4 class="fp-section-header">\ud83d\udca1 Journey Assistance</h4>'
        + '<div class="fp-journey-assistance" data-editable-wrap="journey_assistance">'
        + '<span class="fp-edit-text">' + esc(plan.journey_assistance) + '</span>'
        + (canEdit && editMode ? '<button type="button" class="fp-pen" data-edit-field="journey_assistance" aria-label="Edit the practitioner note">' + ICON_PEN + '</button>' : '')
        + '</div></div>';
    }

    // Review prompts
    var reviewPrompts = planData.review_prompts || [];
    if (reviewPrompts.length) {
      html += '<div class="fp-section"><h4 class="fp-section-header">\ud83d\udcdd Review Prompts (for your next check-in)</h4><ul class="fp-review-prompts">';
      reviewPrompts.forEach(function (p) { html += '<li>' + esc(p) + '</li>'; });
      html += '</ul></div>';
    }

    html += '</div>';
    root.innerHTML = html;

    // ── Bind tappable action row events ──
    root.querySelectorAll('.fp-action[data-tick-id]').forEach(function (el) {
      el.addEventListener('click', function () {
        cycleActionState(el);
      });
    });

    // Tick All Days / Untick All — single toggle (v0.46.60). Flips every
    // action; updateAdherence() -> syncTickAllBtn() relabels the button.
    root.querySelector('#hdlv2-fp-tickall').addEventListener('click', function () {
      var rows = root.querySelectorAll('.fp-action[data-tick-id]');
      if (!rows.length) return;
      var allDone = true;
      rows.forEach(function (el) { if (el.getAttribute('data-state') !== 'done') allDone = false; });
      var makeDone = !allDone;
      rows.forEach(function (el) { el.setAttribute('data-state', makeDone ? 'done' : 'default'); });
      bulkTick(plan.id, makeDone);
      updateAdherence();
    });

    // Calculate adherence button
    root.querySelector('#hdlv2-fp-calc').addEventListener('click', function () {
      updateAdherence();
      var el = root.querySelector('#hdlv2-fp-adherence');
      if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });

    // Per-day tick all buttons
    root.querySelectorAll('[data-day-tick]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var dayName = btn.getAttribute('data-day-tick');
        var dayEls = root.querySelectorAll('.fp-action[data-day="' + dayName + '"]');
        var allDone = true;
        dayEls.forEach(function (el) { if (el.getAttribute('data-state') !== 'done') allDone = false; });
        var newState = allDone ? 'default' : 'done';
        dayEls.forEach(function (el) { el.setAttribute('data-state', newState); });
        bulkTick(plan.id, !allDone, dayName);
        updateAdherence();
      });
    });

    updateAdherence();

    // ── v0.46.59 — editor v2 bindings (practitioner edit mode only) ──
    if (canEdit && editMode) bindEditAffordances();
    flashEditNotice();

    // ── PDF readiness poll ──
    // Only polls when the server told us a PDF is genuinely expected
    // (pipeline wired + fresh plan). Old plans / unwired sites render the
    // window.print() fallback above and never enter this loop.
    if (!plan.pdf_url && plan.pdf_expected) {
      // v0.27.1 — fix #16 (/ultrareview): clear any prior poll before
      // starting a new one. Plan regeneration mid-session re-enters
      // renderPlan(); without this, the old timer kept polling for the
      // archived plan's pdf_url indefinitely.
      if (pdfPollTimer) { clearInterval(pdfPollTimer); pdfPollTimer = null; }
      var pollAttempts = 0;
      var maxAttempts = 6; // 6 × 20s = 2 min — PDFMonkey usually finishes in under a minute
      var pollUrl = CFG.api_base + '/' + clientId + '/current' + (token ? '?token=' + encodeURIComponent(token) : '');
      pdfPollTimer = setInterval(function () {
        pollAttempts++;
        fetch(pollUrl, { headers: { 'X-WP-Nonce': CFG.nonce } })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            var url = data && data.plan && data.plan.pdf_url;
            if (url) {
              clearInterval(pdfPollTimer); pdfPollTimer = null;
              var btn = root.querySelector('#hdlv2-fp-pdf-pending');
              if (btn) {
                var link = document.createElement('a');
                link.href = url;
                link.target = '_blank';
                link.rel = 'noopener';
                link.className = 'fp-btn fp-btn-download';
                link.innerHTML = ICON_DL + '<span>Download PDF</span>';
                btn.replaceWith(link);
              }
            } else if (pollAttempts >= maxAttempts) {
              // v0.40.14 \u2014 PDF generation is async via Make.com \u2192 PDFMonkey.
              // After 2 minutes of polling without a PDF URL, surface a clear
              // message so the user knows the plan still works (web view is
              // fine) and the PDF will arrive separately. Click reloads.
              clearInterval(pdfPollTimer); pdfPollTimer = null;
              var btn2 = root.querySelector('#hdlv2-fp-pdf-pending');
              if (btn2) {
                btn2.textContent = '\u21bb PDF still being prepared \u2014 click to refresh';
                btn2.title = 'Your Flight Plan is ready to use above. The downloadable PDF is generated separately and may take a few more minutes \u2014 we\u2019ll email it when it\u2019s ready.';
                btn2.style.cursor = 'pointer';
                btn2.addEventListener('click', function () { location.reload(); }, { once: true });
              }
            }
          })
          .catch(function () {});
      }, 20000);
    }
  }

  // ── 2-state toggle: default ↔ done ──
  function cycleActionState(el) {
    var state = el.getAttribute('data-state') || 'default';
    var tickId = parseInt(el.getAttribute('data-tick-id'), 10);
    var next, ticked;

    if (state === 'default') { next = 'done';    ticked = true; }
    else                     { next = 'default'; ticked = false; }

    el.setAttribute('data-state', next);

    // Queue tick update (debounced)
    pendingTicks[tickId] = ticked;
    if (tickTimer) clearTimeout(tickTimer);
    tickTimer = setTimeout(flushTicks, 500);
    updateAdherence();
  }

  function flushTicks() {
    var ids = Object.keys(pendingTicks);
    if (ids.length) lastTickFlushAt = Date.now();
    ids.forEach(function (id) {
      fetch(CFG.api_base + '/tick', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({ tick_id: parseInt(id, 10), ticked: pendingTicks[id], token: token })
      }).catch(function () {});
    });
    pendingTicks = {};
  }

  function bulkTick(planId, ticked, day) {
    var body = { flight_plan_id: planId, ticked: ticked, token: token };
    if (day) body.day = day;
    fetch(CFG.api_base + '/tick-all', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify(body)
    }).catch(function () {});
  }

  // ── Single Tick-All toggle label sync (v0.46.60) ──
  // One button replaces the old Tick-All + Untick-All pair. Mirrors the
  // live row state so single taps, per-day toggles and the realtime poll
  // all keep the label/style correct.
  function syncTickAllBtn() {
    var btn = root.querySelector('#hdlv2-fp-tickall');
    if (!btn) return;
    var rows = root.querySelectorAll('.fp-action[data-tick-id]');
    var allDone = rows.length > 0;
    rows.forEach(function (el) { if (el.getAttribute('data-state') !== 'done') allDone = false; });
    if (allDone) {
      btn.textContent = 'Untick All';
      btn.setAttribute('aria-pressed', 'true');
      btn.classList.remove('fp-btn-primary'); btn.classList.add('fp-btn-secondary');
    } else {
      btn.textContent = '✅ Tick All Days';
      btn.setAttribute('aria-pressed', 'false');
      btn.classList.remove('fp-btn-secondary'); btn.classList.add('fp-btn-primary');
    }
  }

  // ── Adherence display ──
  function updateAdherence() {
    var counts = { movement: [0, 0], nutrition: [0, 0], key_action: [0, 0] };
    var anyInteracted = false;

    root.querySelectorAll('.fp-action[data-tick-id]').forEach(function (el) {
      var cat = el.getAttribute('data-category');
      var state = el.getAttribute('data-state') || 'default';
      if (counts[cat]) {
        counts[cat][1]++;
        if (state === 'done') counts[cat][0]++;
      }
      if (state !== 'default') anyInteracted = true;
    });

    var total = 0, done = 0;
    ['movement', 'nutrition', 'key_action'].forEach(function (cat) { total += counts[cat][1]; done += counts[cat][0]; });
    var overall = total > 0 ? Math.round(done / total * 100) : 0;

    // Colour coding: distinguish "not started" from "0% after interaction"
    var overallColor;
    if (!anyInteracted) {
      overallColor = '#f59e0b'; // amber — hasn't started
    } else {
      overallColor = overall >= 70 ? '#10b981' : overall >= 40 ? '#f59e0b' : '#dc2626';
    }

    var html = '<div class="fp-adherence-card">'
      + '<div class="fp-adherence-header">'
      + '<div class="fp-adherence-label">Overall Adherence</div>'
      + '<div class="fp-adherence-overall" style="color:' + overallColor + ';">' + overall + '%</div>';

    // Encouragement message
    var msg = '';
    if (!anyInteracted) {
      msg = 'You haven\u2019t started ticking yet \u2014 tap actions to mark them';
    } else if (overall >= 80) {
      msg = 'Excellent week \u2014 keep this momentum going';
    } else if (overall >= 60) {
      msg = 'Solid effort \u2014 every action counts';
    } else if (overall >= 40) {
      msg = 'Some progress is still progress \u2014 let\u2019s build on it next week';
    } else {
      msg = 'Tough week \u2014 that\u2019s okay. Your next Flight Plan will adjust';
    }
    html += '<div class="fp-adherence-message">' + msg + '</div>'
      + '</div>';

    // Per-category bars — labels mirror the grid bands (Food / Fitness / Lifestyle)
    html += '<div class="fp-adherence-breakdown">';
    [['nutrition', 'Food', '\ud83e\udd57'], ['movement', 'Fitness', '\ud83c\udfc3'], ['key_action', 'Lifestyle', '\u2b50']].forEach(function (item) {
      var cat = item[0], label = item[1], icon = item[2];
      var d = counts[cat][0], t = counts[cat][1];
      var pct = t > 0 ? Math.round(d / t * 100) : 0;
      var color = pct >= 70 ? '#10b981' : pct >= 40 ? '#f59e0b' : '#dc2626';
      if (!anyInteracted) color = '#f59e0b';
      html += '<div class="fp-adherence-cat">'
        + '<div class="fp-adherence-cat-label">' + icon + ' ' + label + '</div>'
        + '<div class="fp-adherence-bar"><div class="fp-adherence-bar-fill" style="background:' + color + ';width:' + pct + '%;"></div></div>'
        + '<div class="fp-adherence-cat-value" style="color:' + color + ';">' + pct + '%</div>'
        + '</div>';
    });
    html += '</div></div>';

    var el = root.querySelector('#hdlv2-fp-adherence');
    if (el) el.innerHTML = html;
    syncTickAllBtn();
  }

    // ── Realtime tick poll (v0.24.6) ──
    // Both client and practitioner views poll /current every 20s while
    // visible so ticks made on one side appear on the other without a
    // manual reload. Self-terminates when the mount root is detached
    // (chevron collapse on /clients/, navigation away on /my-flight-plan/).
    function applyTickDiff(serverTicks) {
      if (!root.isConnected) return;
      if (!serverTicks) return;
      serverTicks.forEach(function (t) {
        var el = root.querySelector('.fp-action[data-tick-id="' + t.id + '"]');
        if (!el) return;
        var serverState = t.ticked ? 'done' : 'default';
        if (el.getAttribute('data-state') !== serverState) {
          el.setAttribute('data-state', serverState);
        }
      });
      updateAdherence();
    }

    function refresh() {
      // Self-clean if our mount target is gone (tab switch / page nav).
      if (!root.isConnected) {
        if (tickPollTimer) { clearInterval(tickPollTimer); tickPollTimer = null; }
        if (tickVisHandler) { document.removeEventListener('visibilitychange', tickVisHandler); tickVisHandler = null; }
        if (tickFocusHandler) { window.removeEventListener('focus', tickFocusHandler); tickFocusHandler = null; }
        return;
      }
      // Skip while a debounced tick is still flushing — don't overwrite
      // optimistic local state with a stale server snapshot.
      if (Object.keys(pendingTicks).length > 0) return;
      // v0.24.12 — B2 fix: also skip for 5s after a flushTicks() fire so
      // the server has time to commit before we re-read. Without this,
      // a poll landing between POST send and server commit can briefly
      // flip a just-ticked row back to default.
      if (Date.now() - lastTickFlushAt < 5000) return;

      var url = CFG.api_base + '/' + clientId + '/current';
      if (token) url += '?token=' + encodeURIComponent(token);
      fetch(url, { headers: { 'X-WP-Nonce': CFG.nonce } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (!data || !data.plan) return;
          // Plan was regenerated mid-session (Reset to Week 1 / new week)
          // — full re-render so newly-created tick rows can be bound.
          if (currentPlanId !== null && data.plan.id !== currentPlanId) {
            renderPlan(data.plan, data.ticks);
            return;
          }
          applyTickDiff(data.ticks);
        })
        .catch(function () { /* swallow — try again next interval */ });
    }

    function startTickPoll() {
      // Idempotent — survives a renderPlan re-run after plan regen.
      if (tickPollTimer) return;
      tickPollTimer = setInterval(refresh, 20000);
      tickVisHandler = function () { if (document.visibilityState === 'visible') refresh(); };
      tickFocusHandler = function () { refresh(); };
      document.addEventListener('visibilitychange', tickVisHandler);
      window.addEventListener('focus', tickFocusHandler);
    }

    // ── v0.46.59 — Editor v2: inline pencils + per-band "+" ──────────
    // Every save POSTs /flight-plan/{client_id}/edit (practitioner-IDOR-
    // gated server side): same additive-revision storage, live client
    // propagation and direct PDF re-render as 0.46.58. Zero emails.
    function bindEditAffordances() {
      root.querySelectorAll('.fp-pen[data-edit-tick]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          var row = btn.closest('.fp-action');
          openEditor(row, row.querySelector('.fp-edit-text').textContent, {
            field: 'daily_action_by_tick', tick_id: parseInt(btn.getAttribute('data-edit-tick'), 10)
          });
        });
      });
      root.querySelectorAll('.fp-pen[data-edit-field]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          var wrap = btn.closest('[data-editable-wrap]');
          var raw = wrap.querySelector('.fp-edit-text').textContent.replace(/^[\u201c]|[\u201d]$/g, '');
          openEditor(wrap, raw, { field: btn.getAttribute('data-edit-field') });
        });
      });
      root.querySelectorAll('.fp-pen[data-edit-target]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          var wrap = btn.closest('[data-editable-wrap]');
          openEditor(wrap, wrap.querySelector('.fp-edit-text').textContent, {
            field: 'weekly_target', index: parseInt(btn.getAttribute('data-edit-target'), 10)
          });
        });
      });
      root.querySelectorAll('.fp-add').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          openAddForm(btn.closest('.fp-band'), btn.getAttribute('data-add-day'), btn.getAttribute('data-add-cat'));
        });
      });
    }

    function editorBox(initial, withSlot) {
      var box = document.createElement('div');
      box.className = 'fp-edit-box';
      box.innerHTML = '<textarea class="fp-edit-input" rows="3">' + esc(initial) + '</textarea>'
        + (withSlot
            ? '<label class="fp-edit-slot-label">When? <select class="fp-edit-slot">'
              + '<option value="morning">Morning</option>'
              + '<option value="afternoon">Afternoon</option>'
              + '<option value="early_evening">Early evening</option>'
              + '</select></label>'
            : '')
        + '<div class="fp-edit-actions">'
        + '<button type="button" class="fp-btn fp-btn-primary fp-edit-save">' + (withSlot ? 'Add' : 'Save') + '</button>'
        + '<button type="button" class="fp-btn fp-btn-secondary fp-edit-cancel">Cancel</button>'
        + '<span class="fp-edit-msg" aria-live="polite"></span>'
        + '</div>';
      return box;
    }

    function openEditor(container, initial, bodyBase) {
      if (root.querySelector('.fp-edit-box')) return; // one editor at a time
      var saved = container.innerHTML;
      var box = editorBox(initial, false);
      container.innerHTML = '';
      container.appendChild(box);
      box.querySelector('.fp-edit-cancel').addEventListener('click', function (e) {
        e.stopPropagation(); container.innerHTML = saved;
        if (canEdit && editMode) bindEditAffordances();
      });
      box.addEventListener('click', function (e) { e.stopPropagation(); });
      box.querySelector('.fp-edit-save').addEventListener('click', function (e) {
        e.stopPropagation();
        submitEdit(box, Object.assign({}, bodyBase, { value: box.querySelector('.fp-edit-input').value }));
      });
    }

    function openAddForm(bandEl, day, cat) {
      if (root.querySelector('.fp-edit-box')) return;
      var box = editorBox('', true);
      bandEl.appendChild(box);
      box.querySelector('.fp-edit-cancel').addEventListener('click', function (e) {
        e.stopPropagation(); box.remove();
      });
      box.addEventListener('click', function (e) { e.stopPropagation(); });
      box.querySelector('.fp-edit-save').addEventListener('click', function (e) {
        e.stopPropagation();
        submitEdit(box, {
          field: 'add_action', day: day, category: cat,
          slot: box.querySelector('.fp-edit-slot').value,
          value: box.querySelector('.fp-edit-input').value
        });
      });
    }

    function submitEdit(box, body) {
      var msg = box.querySelector('.fp-edit-msg');
      var save = box.querySelector('.fp-edit-save');
      if (!body.value || !body.value.trim()) { msg.textContent = 'Text required.'; return; }
      save.disabled = true; msg.textContent = 'Saving\u2026'; msg.className = 'fp-edit-msg';
      body.plan_id = currentPlanId;
      fetch(CFG.api_base + '/' + clientId + '/edit', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify(body)
      }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (!res.ok || !res.j || !res.j.success) {
            save.disabled = false;
            msg.textContent = (res.j && res.j.message) || 'Could not save.';
            msg.className = 'fp-edit-msg is-error';
            return;
          }
          if (res.j.warnings && res.j.warnings.length) {
            pendingEditNotice = '\u26a0 Saved, with care flags: ' + res.j.warnings.join(' ');
          } else {
            pendingEditNotice = 'Saved \u2014 the client sees this on their next visit; the PDF is regenerating.';
          }
          loadCurrent(); // fresh data + re-render (edit mode persists)
        })
        .catch(function () {
          save.disabled = false;
          msg.textContent = 'Connection error.'; msg.className = 'fp-edit-msg is-error';
        });
    }

    var pendingEditNotice = '';
    function flashEditNotice() {
      if (!pendingEditNotice) return;
      var n = document.createElement('div');
      n.className = 'fp-edit-notice' + (pendingEditNotice.indexOf('\u26a0') === 0 ? ' is-warn' : '');
      n.textContent = pendingEditNotice;
      pendingEditNotice = '';
      var anchor = root.querySelector('.fp-grid-wrap');
      if (anchor) { anchor.parentNode.insertBefore(n, anchor); setTimeout(function () { n.remove(); }, 6000); }
    }

    function setEditMode(on) {
      editMode = !!on && canEdit;
      if (lastPlan) renderPlan(lastPlan, lastTicks);
      return editMode;
    }

    init();

    // v0.46.60 — expose refresh() so the dashboard digest poll can sync
    // ticks in place instead of a destructive full re-mount (kills the
    // "twitching" blink + stops the edit toggle resetting every 4s).
    return { setEditMode: setEditMode, isEditMode: function () { return editMode; }, refresh: refresh };
  }

  window.HDLV2_FlightPlan = { mount: mount };

  function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

  // The AI sometimes prefixes action text with a literal checkbox glyph
  // (☐ / ☑ / ✓ / ✗) despite the separate `checkbox` boolean in the JSON
  // schema. Strip that leading glyph + whitespace so the row reads cleanly
  // alongside our CSS-drawn tick circle.
  function cleanActionText(s) {
    if (!s) return '';
    // v0.24.6 \u2014 added \u25a0 \u25a0 and \u25a1 \u25a1 (the AI flipped from BALLOT BOX
    // U+2610 to WHITE SQUARE U+25A1 mid-2026, leaving a literal glyph
    // doubled with the CSS-drawn tick circle on every action row).
    return String(s).replace(/^[\u2610\u2611\u2713\u2714\u2717\u2718\u25a0\u25a1\s]+/, '');
  }

  // Backward-compat auto-mount for /my-flight-plan/ shortcode page.
  // No-ops on /clients/ where the practitioner dashboard mounts manually
  // via window.HDLV2_FlightPlan.mount().
  function autoInit() {
    var existing = document.getElementById('hdlv2-flight-plan');
    if (!existing) return;
    if (!window.hdlv2_flight_plan || !window.hdlv2_flight_plan.api_base) return;
    mount(existing);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', autoInit);
  else autoInit();
})();
