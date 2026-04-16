/**
 * HDL V2 Flight Plan — Interactive weekly plan with tappable action rows.
 * Tappable action rows with 2-state toggle: default ↔ done.
 * @package HDL_Longevity_V2
 * @since 0.9.3
 */
(function () {
  'use strict';

  var CFG = window.hdlv2_flight_plan || {};
  if (!CFG.api_base) return;

  var root = document.getElementById('hdlv2-flight-plan');
  if (!root) return;

  var clientId = new URLSearchParams(window.location.search).get('client_id') || CFG.client_id || '';
  var token = new URLSearchParams(window.location.search).get('token') || '';
  var tickTimer = null;
  var pendingTicks = {};

  var DAYS = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
  var DAY_LABELS = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
  var CAT_ICONS = { movement: '\ud83c\udfc3', nutrition: '\ud83e\udd57', key_action: '\u2b50' };

  function init() {
    if (!clientId && !token) { root.innerHTML = '<p style="color:#888;">No client specified.</p>'; return; }
    root.innerHTML = '<div class="hdlv2-card"><div class="hdlv2-loading"><div class="hdlv2-spinner"></div><p style="color:#888;">Loading flight plan\u2026</p></div></div>';
    loadCurrent();
  }

  function loadCurrent() {
    var url = CFG.api_base + '/' + clientId + '/current';
    if (token) url += '?token=' + encodeURIComponent(token);
    fetch(url, { headers: { 'X-WP-Nonce': CFG.nonce } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.plan) renderPlan(data.plan, data.ticks);
        else renderEmpty();
      })
      .catch(function () { root.innerHTML = '<div class="hdlv2-card"><p style="color:#dc2626;padding:24px;">Error loading plan.</p></div>'; });
  }

  function renderEmpty() {
    root.innerHTML = '<div class="hdlv2-card"><div style="text-align:center;padding:40px 20px;">'
      + '<p style="font-size:48px;margin:0 0 12px;">\ud83d\udccb</p>'
      + '<h3 style="margin:0 0 8px;">Your Flight Plan is being prepared</h3>'
      + '<p style="color:#888;font-size:14px;">Check back soon \u2014 your practitioner will generate your first weekly plan after your consultation.</p>'
      + '</div></div>';
  }

  function renderPlan(plan, ticks) {
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

    // Identity statement
    if (plan.identity_statement) {
      html += '<div class="fp-identity-statement">'
        + '\u201c' + esc(plan.identity_statement) + '\u201d</div>';
    }

    // Flexibility note
    var flexNote = planData.flexibility_note || 'This plan is your guide, not your boss. Tick the boxes when you can. Adjust when you need to.';
    html += '<div class="fp-flex-note hdlv2-fp-noprint">' + esc(flexNote) + '</div>';

    // Transfer from paper UX
    html += '<div class="fp-transfer-box hdlv2-fp-noprint">'
      + '<div class="fp-transfer-title">Transferring from your printed plan?</div>'
      + '<p class="fp-transfer-desc">Tick All, then tap items you didn\u2019t do. Done in seconds.</p>'
      + '<div class="fp-transfer-actions">'
      + '<button id="hdlv2-fp-tickall" class="fp-btn fp-btn-primary">\u2705 Tick All Days</button>'
      + '<button id="hdlv2-fp-untickall" class="fp-btn fp-btn-secondary">\u274c Untick All</button>'
      + '<button id="hdlv2-fp-calc" class="fp-btn fp-btn-outline">Calculate My Adherence</button>'
      + '<button onclick="window.print()" class="fp-btn fp-btn-secondary fp-btn-print">\ud83d\udda8 Print</button>'
      + '</div></div>';

    // Build tick lookup by day
    var ticksByDay = {};
    DAYS.forEach(function (d) { ticksByDay[d] = []; });
    ticks.forEach(function (t) {
      var day = t.day || DAYS[(t.day_of_week || 1) - 1] || 'monday';
      if (ticksByDay[day]) ticksByDay[day].push(t);
    });

    // Day grid
    html += '<div class="fp-grid-wrap"><div class="fp-week-grid">';
    for (var d = 0; d < 7; d++) {
      var dayName = DAYS[d];
      var dayTicks = ticksByDay[dayName] || [];
      html += '<div class="fp-day-column">'
        + '<div class="fp-day-header">' + DAY_LABELS[d]
        + '<button data-day-tick="' + dayName + '" class="fp-day-tickall hdlv2-fp-noprint">Tick all</button>'
        + '</div>';

      dayTicks.forEach(function (t) {
        var icon = CAT_ICONS[t.category] || '\u2610';
        var state = t.ticked ? 'done' : 'default';
        html += '<div class="fp-action" data-tick-id="' + t.id + '" data-state="' + state + '" data-day="' + dayName + '" data-category="' + (t.category || '') + '">'
          + '<span>' + icon + ' ' + esc(t.action_text) + '</span></div>';
      });

      if (!dayTicks.length) {
        html += '<div class="fp-day-empty">No actions</div>';
      }

      html += '</div>';
    }
    html += '</div></div>';

    // Adherence summary container
    html += '<div id="hdlv2-fp-adherence" style="padding:0 24px 16px;"></div>';

    // Weekly targets
    if (plan.weekly_targets && plan.weekly_targets.length) {
      html += '<div class="fp-section"><h4 class="fp-section-header">\ud83c\udfaf Weekly Targets</h4><ul class="fp-targets-list">';
      plan.weekly_targets.forEach(function (t) { html += '<li>' + esc(typeof t === 'string' ? t : t.text || t.target || '') + '</li>'; });
      html += '</ul></div>';
    }

    // Shopping list
    if (plan.shopping_list && plan.shopping_list.length) {
      html += '<div class="fp-section"><h4 class="fp-section-header">\ud83d\uded2 Shopping List</h4><ul class="fp-shopping-list">';
      plan.shopping_list.forEach(function (i) { html += '<li class="fp-shopping-item">' + esc(typeof i === 'string' ? i : i.name || '') + '</li>'; });
      html += '</ul></div>';
    }

    // Journey assistance
    if (plan.journey_assistance) {
      html += '<div class="fp-section"><h4 class="fp-section-header">\ud83d\udca1 Journey Assistance</h4></div>'
        + '<div class="fp-journey-assistance">' + esc(plan.journey_assistance) + '</div>';
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

    // Tick All Days
    document.getElementById('hdlv2-fp-tickall').addEventListener('click', function () {
      root.querySelectorAll('.fp-action[data-tick-id]').forEach(function (el) {
        el.setAttribute('data-state', 'done');
      });
      bulkTick(plan.id, true);
      updateAdherence();
    });

    // Untick All
    document.getElementById('hdlv2-fp-untickall').addEventListener('click', function () {
      root.querySelectorAll('.fp-action[data-tick-id]').forEach(function (el) {
        el.setAttribute('data-state', 'default');
      });
      bulkTick(plan.id, false);
      updateAdherence();
    });

    // Calculate adherence button
    document.getElementById('hdlv2-fp-calc').addEventListener('click', function () {
      updateAdherence();
      var el = document.getElementById('hdlv2-fp-adherence');
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

    // Per-category bars
    html += '<div class="fp-adherence-breakdown">';
    [['movement', 'Movement', '\ud83c\udfc3'], ['nutrition', 'Nutrition', '\ud83e\udd57'], ['key_action', 'Key Actions', '\u2b50']].forEach(function (item) {
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

    var el = document.getElementById('hdlv2-fp-adherence');
    if (el) el.innerHTML = html;
  }

  function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
