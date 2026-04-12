/**
 * HDL V2 Flight Plan — Interactive weekly plan with tick-box tracking.
 * @package HDL_Longevity_V2
 * @since 0.7.0
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

  function init() {
    if (!clientId) { root.innerHTML = '<p style="color:#888;">No client specified.</p>'; return; }
    root.innerHTML = '<div class="hdlv2-card"><div class="hdlv2-loading"><div class="hdlv2-spinner"></div><p style="color:#888;">Loading flight plan...</p></div></div>';
    loadCurrent();
  }

  function loadCurrent() {
    fetch(CFG.api_base + '/' + clientId + '/current', { headers: { 'X-WP-Nonce': CFG.nonce } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.plan) renderPlan(data.plan, data.ticks);
        else renderEmpty();
      })
      .catch(function () { root.innerHTML = '<div class="hdlv2-card"><p style="color:#dc2626;">Error loading plan.</p></div>'; });
  }

  function renderEmpty() {
    root.innerHTML = '<div class="hdlv2-card"><div style="text-align:center;padding:40px 20px;">'
      + '<p style="font-size:48px;margin:0 0 12px;">\u2708\ufe0f</p>'
      + '<h3 style="margin:0 0 8px;">No Flight Plan Yet</h3>'
      + '<p style="color:#888;">Your practitioner will generate your first weekly flight plan after your consultation.</p>'
      + '</div></div>';
  }

  function renderPlan(plan, ticks) {
    var CAT_ICONS = { movement: '\ud83c\udfc3', nutrition: '\ud83e\udd57', key_action: '\u2b50' };
    var DAYS = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
    var DAY_LABELS = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

    var html = '<div class="hdlv2-card">'
      + '<div class="hdlv2-header"><h2>Week ' + plan.week_number + ' Flight Plan</h2><p>' + (plan.week_start || '') + '</p></div>';

    if (plan.identity_statement) {
      html += '<div style="background:#f8f9fb;border-left:4px solid #3d8da0;padding:12px 16px;margin:0 24px 16px;border-radius:6px;font-size:14px;font-weight:600;color:#111;">'
        + '\u201cThis week you are someone who ' + esc(plan.identity_statement) + '\u201d</div>';
    }

    // Flexibility note
    html += '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:8px 12px;margin:0 24px 16px;font-size:11px;color:#166534;">'
      + 'This plan is your guide, not your boss. Tick the boxes when you can. Adjust when you need to.</div>';

    // Tick all buttons
    html += '<div style="padding:0 24px 12px;display:flex;gap:8px;">'
      + '<button id="hdlv2-fp-tickall" style="padding:6px 14px;border:1px solid #10b981;border-radius:6px;background:#f0fdf4;color:#166534;font-size:12px;cursor:pointer;font-weight:500;">\u2705 Tick All</button>'
      + '<button id="hdlv2-fp-untickall" style="padding:6px 14px;border:1px solid #e4e6ea;border-radius:6px;background:#f8f9fb;color:#666;font-size:12px;cursor:pointer;">\u274c Untick All</button>'
      + '<button onclick="window.print()" style="padding:6px 14px;border:1px solid #e4e6ea;border-radius:6px;background:#f8f9fb;color:#666;font-size:12px;cursor:pointer;margin-left:auto;">\ud83d\udda8 Print</button>'
      + '</div>';

    // Ticks by day
    var ticksByDay = {};
    ticks.forEach(function (t) {
      if (!ticksByDay[t.day_of_week]) ticksByDay[t.day_of_week] = [];
      ticksByDay[t.day_of_week].push(t);
    });

    html += '<div style="padding:0 24px 16px;overflow-x:auto;"><div style="display:grid;grid-template-columns:repeat(7,minmax(120px,1fr));gap:6px;">';
    for (var d = 0; d < 7; d++) {
      var dow = d + 1;
      var dayTicks = ticksByDay[dow] || [];
      html += '<div style="border:1px solid #e4e6ea;border-radius:8px;overflow:hidden;">'
        + '<div style="background:#004F59;color:#fff;padding:8px;text-align:center;font-weight:600;font-size:12px;">' + DAY_LABELS[d] + '</div>';

      dayTicks.forEach(function (t) {
        var icon = CAT_ICONS[t.category] || '\u2610';
        html += '<label style="display:flex;align-items:flex-start;gap:4px;padding:6px 8px;border-bottom:1px solid #f0f0f0;cursor:pointer;font-size:11px;line-height:1.4;">'
          + '<input type="checkbox" data-tick-id="' + t.id + '"' + (t.ticked ? ' checked' : '') + ' style="margin-top:2px;flex-shrink:0;">'
          + '<span>' + icon + ' ' + esc(t.action_text) + '</span></label>';
      });

      html += '</div>';
    }
    html += '</div></div>';

    // Adherence summary
    html += '<div id="hdlv2-fp-adherence" style="padding:0 24px 16px;"></div>';

    // Targets + Shopping
    if (plan.weekly_targets && plan.weekly_targets.length) {
      html += '<div style="padding:0 24px 12px;"><h4 style="margin:0 0 6px;font-size:13px;color:#004F59;">Weekly Targets</h4><ul style="margin:0;padding-left:20px;font-size:12px;color:#444;">';
      plan.weekly_targets.forEach(function (t) { html += '<li>' + esc(typeof t === 'string' ? t : t.text || t.target || '') + '</li>'; });
      html += '</ul></div>';
    }

    if (plan.shopping_list && plan.shopping_list.length) {
      html += '<div style="padding:0 24px 12px;"><h4 style="margin:0 0 6px;font-size:13px;color:#004F59;">Shopping List</h4><ul style="margin:0;padding-left:20px;font-size:12px;color:#444;columns:2;">';
      plan.shopping_list.forEach(function (i) { html += '<li>' + esc(typeof i === 'string' ? i : i.name || '') + '</li>'; });
      html += '</ul></div>';
    }

    if (plan.journey_assistance) {
      html += '<div style="padding:0 24px 16px;"><h4 style="margin:0 0 6px;font-size:13px;color:#004F59;">Journey Assistance</h4><p style="font-size:12px;color:#444;line-height:1.6;">' + esc(plan.journey_assistance) + '</p></div>';
    }

    html += '</div>';
    root.innerHTML = html;

    // Bind tick events
    root.querySelectorAll('input[data-tick-id]').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var tickId = parseInt(cb.getAttribute('data-tick-id'), 10);
        pendingTicks[tickId] = cb.checked;
        if (tickTimer) clearTimeout(tickTimer);
        tickTimer = setTimeout(flushTicks, 500);
        updateAdherence(ticks, plan.id);
      });
    });

    // Tick all / untick all
    document.getElementById('hdlv2-fp-tickall').addEventListener('click', function () {
      bulkTick(plan.id, true);
      root.querySelectorAll('input[data-tick-id]').forEach(function (cb) { cb.checked = true; });
    });
    document.getElementById('hdlv2-fp-untickall').addEventListener('click', function () {
      bulkTick(plan.id, false);
      root.querySelectorAll('input[data-tick-id]').forEach(function (cb) { cb.checked = false; });
    });

    updateAdherence(ticks, plan.id);
  }

  function flushTicks() {
    var ids = Object.keys(pendingTicks);
    ids.forEach(function (id) {
      fetch(CFG.api_base + '/tick', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({ tick_id: parseInt(id, 10), ticked: pendingTicks[id] })
      }).catch(function () {});
    });
    pendingTicks = {};
  }

  function bulkTick(planId, ticked) {
    fetch(CFG.api_base + '/tick-all', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify({ flight_plan_id: planId, ticked: ticked })
    }).catch(function () {});
  }

  function updateAdherence(ticks) {
    var counts = { movement: [0,0], nutrition: [0,0], key_action: [0,0] };
    var cbs = root.querySelectorAll('input[data-tick-id]');
    cbs.forEach(function (cb) {
      var tickId = parseInt(cb.getAttribute('data-tick-id'), 10);
      var tick = ticks.find(function (t) { return t.id === tickId; });
      if (!tick) return;
      var cat = tick.category;
      if (counts[cat]) {
        counts[cat][1]++;
        if (cb.checked) counts[cat][0]++;
      }
    });

    var total = 0, done = 0;
    var html = '<div style="display:flex;gap:12px;flex-wrap:wrap;">';
    ['movement','nutrition','key_action'].forEach(function (cat) {
      var d = counts[cat][0], t = counts[cat][1];
      total += t; done += d;
      var pct = t > 0 ? Math.round(d/t*100) : 0;
      var label = cat === 'key_action' ? 'Key Actions' : cat.charAt(0).toUpperCase() + cat.slice(1);
      html += '<div style="flex:1;min-width:80px;background:#f8f9fb;border-radius:6px;padding:8px;text-align:center;">'
        + '<div style="font-size:10px;color:#888;text-transform:uppercase;">' + label + '</div>'
        + '<div style="font-size:20px;font-weight:700;color:#004F59;">' + pct + '%</div></div>';
    });
    var overall = total > 0 ? Math.round(done/total*100) : 0;
    html += '<div style="flex:1;min-width:80px;background:#004F59;border-radius:6px;padding:8px;text-align:center;">'
      + '<div style="font-size:10px;color:rgba(255,255,255,0.7);text-transform:uppercase;">Overall</div>'
      + '<div style="font-size:20px;font-weight:700;color:#fff;">' + overall + '%</div></div>';
    html += '</div>';

    var el = document.getElementById('hdlv2-fp-adherence');
    if (el) el.innerHTML = html;
  }

  function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
