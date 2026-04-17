/**
 * HDL V2 — Client list enhancer.
 *
 * Runs on any page that contains V1's [health_tracker_dashboard] shortcode.
 * DOM-injects V2 status pills into existing client rows (matched by
 * data-client-hash) and a chevron-expand control that reveals a tabbed
 * detail panel: Flight Plan / Check-ins / Timeline / Consultation.
 *
 * V2-only clients (no V1 history yet) are appended to the end of the same
 * table so practitioners only have one place to look.
 *
 * This file never touches V1 code. All edits happen via DOM injection.
 *
 * @package HDL_Longevity_V2
 * @since 0.9.7
 */
(function () {
  'use strict';

  var CFG = window.hdlv2_client_enhance || {};
  if (!CFG.nonce || !CFG.api_base) return;

  var state = {
    table: null,
    tbody: null,
    colSpan: 6,
    byHash: {},
    matched: {},
  };

  // Wait for the V1 table to render (it's server-side PHP so it's usually
  // present immediately, but some themes defer). Give up after ~10s.
  var tries = 0;
  var watcher = setInterval(function () {
    if (tries++ > 40) { clearInterval(watcher); return; }
    var table = document.querySelector('.clients-table');
    if (!table) return;
    clearInterval(watcher);
    state.table   = table;
    state.tbody   = table.querySelector('tbody') || table;
    state.colSpan = (table.querySelectorAll('thead th').length) || 6;
    init();
  }, 250);

  function init() {
    injectStyles();
    fetchV2Clients().then(function (list) {
      list.forEach(function (c) {
        if (c.email_hash) state.byHash[c.email_hash] = c;
      });
      enhanceMatchedRows(list);
      appendV2OnlyRows(list);
    });
  }

  function fetchV2Clients() {
    return fetch(CFG.api_base + '/dashboard/clients', {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': CFG.nonce },
    })
      .then(function (r) { return r.ok ? r.json() : []; })
      .catch(function () { return []; });
  }

  function enhanceMatchedRows(list) {
    var rows = state.tbody.querySelectorAll('tr.client-row');
    rows.forEach(function (row) {
      var hash = row.dataset.clientHash;
      var c = state.byHash[hash];
      if (!c) return;
      state.matched[hash] = true;
      injectV2Badge(row, c);
      injectExpandButton(row, c);
    });
  }

  function appendV2OnlyRows(list) {
    list.forEach(function (c) {
      if (!c.email_hash || state.matched[c.email_hash]) return;
      state.tbody.appendChild(buildV2OnlyRow(c));
    });
  }

  function injectV2Badge(row, c) {
    var cell = row.querySelector('.status-badge-cell');
    if (!cell) return;
    var pill = document.createElement('span');
    pill.className = 'hdlv2-inline-badge';
    pill.style.color = c.color;
    pill.style.background = hexToRgba(c.color, 0.12);
    pill.style.border = '1px solid ' + hexToRgba(c.color, 0.35);
    pill.textContent = 'V2 · ' + c.label;
    cell.appendChild(pill);
  }

  function injectExpandButton(row, c) {
    // V1 rows end in a single td that contains icon buttons. We append our
    // chevron alongside them rather than inventing a new column so the V1
    // table structure stays intact.
    var cell = row.querySelector('td:last-child');
    if (!cell) return;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'hdlv2-expand-btn';
    btn.setAttribute('aria-expanded', 'false');
    btn.setAttribute('title', 'Show V2 detail');
    btn.innerHTML = chevronSVG();
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      toggleDetail(row, c, btn);
    });
    cell.appendChild(btn);
  }

  function toggleDetail(row, c, btn) {
    var next = row.nextElementSibling;
    if (next && next.classList.contains('hdlv2-detail-row')) {
      next.parentNode.removeChild(next);
      btn.setAttribute('aria-expanded', 'false');
      btn.classList.remove('open');
      return;
    }
    var detail = document.createElement('tr');
    detail.className = 'hdlv2-detail-row';
    var td = document.createElement('td');
    td.colSpan = state.colSpan;
    td.appendChild(buildDetailPanel(c));
    detail.appendChild(td);
    row.parentNode.insertBefore(detail, row.nextSibling);
    btn.setAttribute('aria-expanded', 'true');
    btn.classList.add('open');
  }

  function buildDetailPanel(c) {
    var panel = document.createElement('div');
    panel.className = 'hdlv2-detail-panel';
    panel.innerHTML = [
      '<div class="hdlv2-detail-head">',
      '<strong>' + esc(c.name) + '</strong>',
      '<span class="hdlv2-detail-email">' + esc(c.email || '') + '</span>',
      '</div>',
      '<nav class="hdlv2-detail-tabs" role="tablist">',
      '<button type="button" class="hdlv2-detail-tab active" data-tab="flight-plan">Flight Plan</button>',
      '<button type="button" class="hdlv2-detail-tab" data-tab="checkins">Check-ins</button>',
      '<button type="button" class="hdlv2-detail-tab" data-tab="timeline">Timeline</button>',
      '<button type="button" class="hdlv2-detail-tab" data-tab="consultation">Consultation</button>',
      '</nav>',
      '<div class="hdlv2-detail-content" data-tab-content></div>',
    ].join('');

    var tabs = panel.querySelectorAll('.hdlv2-detail-tab');
    var content = panel.querySelector('[data-tab-content]');
    tabs.forEach(function (t) {
      t.addEventListener('click', function () {
        tabs.forEach(function (x) { x.classList.remove('active'); });
        t.classList.add('active');
        loadTab(t.dataset.tab, c, content);
      });
    });

    loadTab('flight-plan', c, content);
    return panel;
  }

  function loadTab(tab, c, target) {
    target.innerHTML = '<div class="hdlv2-detail-loading">Loading…</div>';
    if (tab === 'flight-plan')   return loadFlightPlan(c, target);
    if (tab === 'checkins')      return loadCheckins(c, target);
    if (tab === 'timeline')      return loadTimeline(c, target);
    if (tab === 'consultation')  return loadConsultation(c, target);
  }

  function loadFlightPlan(c, target) {
    fetch(CFG.api_base + '/flight-plan/' + c.user_id + '/current', {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': CFG.nonce },
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        var plan = data && data.plan;
        if (!plan) {
          target.innerHTML = '<div class="hdlv2-detail-empty">No flight plan yet.</div>';
          return;
        }
        var identity = String(plan.identity_statement || '')
          .replace(/^[\u201c\u201d"\s]+|[\u201c\u201d"\s]+$/g, '');
        var targets = plan.weekly_targets || [];
        var adh = plan.adherence_summary || null;
        var dateRange = plan.week_start && plan.date_range_end
          ? formatDateLabel(plan.week_start) + ' – ' + formatDateLabel(plan.date_range_end)
          : '';
        var html = '<div class="hdlv2-fp-summary">'
          + '<div class="hdlv2-fp-week">Week ' + (plan.week_number || '?') + (dateRange ? ' · ' + esc(dateRange) : '') + '</div>';
        if (identity) {
          html += '<div class="hdlv2-fp-identity">\u201c' + esc(identity) + '\u201d</div>';
        }
        if (targets.length) {
          html += '<div class="hdlv2-fp-block-label">Weekly targets</div><ul class="hdlv2-fp-targets">';
          targets.forEach(function (t) {
            var text = typeof t === 'string' ? t : (t.text || t.target || '');
            html += '<li>' + esc(text) + '</li>';
          });
          html += '</ul>';
        }
        if (adh) {
          html += '<div class="hdlv2-fp-block-label">Adherence</div>'
            + '<div class="hdlv2-fp-adh">'
            + '<span>Overall ' + (adh.overall || 0) + '%</span>'
            + '<span>Movement ' + (adh.movement || 0) + '%</span>'
            + '<span>Nutrition ' + (adh.nutrition || 0) + '%</span>'
            + '<span>Key actions ' + (adh.key_action || 0) + '%</span>'
            + '</div>';
        }
        html += '<a class="hdlv2-detail-deeplink" href="' + esc(CFG.flight_plan_url + '?client_id=' + c.user_id) + '">View full flight plan →</a>'
          + '</div>';
        target.innerHTML = html;
      })
      .catch(function () { target.innerHTML = '<div class="hdlv2-detail-empty">Couldn\u2019t load flight plan.</div>'; });
  }

  function loadCheckins(c, target) {
    fetchTimeline(c).then(function (entries) {
      var items = entries.filter(function (e) { return (e.type || e.entry_type) === 'checkin_summary'; }).slice(0, 6);
      if (!items.length) {
        target.innerHTML = '<div class="hdlv2-detail-empty">No check-ins yet.</div>';
        return;
      }
      var html = '<ul class="hdlv2-checkin-list">';
      items.forEach(function (e) {
        var d = parseJSON(e.detail) || {};
        html += '<li class="hdlv2-checkin-item">'
          + '<div class="hdlv2-checkin-date">' + esc(formatDate(e.date || e.created_at)) + '</div>'
          + '<div class="hdlv2-checkin-summary">' + esc(d.check_in_summary || e.summary || '') + '</div>'
          + '</li>';
      });
      html += '</ul>';
      target.innerHTML = html;
    }).catch(function () {
      target.innerHTML = '<div class="hdlv2-detail-empty">Couldn\u2019t load check-ins.</div>';
    });
  }

  function loadTimeline(c, target) {
    fetchTimeline(c).then(function (entries) {
      var list = entries.slice(0, 15);
      if (!list.length) {
        target.innerHTML = '<div class="hdlv2-detail-empty">Timeline is empty.</div>';
        return;
      }
      var html = '<ul class="hdlv2-timeline-list">';
      list.forEach(function (e) {
        html += '<li class="hdlv2-timeline-item">'
          + '<div class="hdlv2-timeline-meta"><span class="hdlv2-timeline-type">' + esc((e.type || e.entry_type) || '') + '</span> · ' + esc(formatDate(e.date || e.created_at)) + '</div>'
          + '<div class="hdlv2-timeline-summary">' + esc(e.summary || e.title || '') + '</div>'
          + '</li>';
      });
      html += '</ul>';
      target.innerHTML = html;
    }).catch(function () {
      target.innerHTML = '<div class="hdlv2-detail-empty">Couldn\u2019t load timeline.</div>';
    });
  }

  function loadConsultation(c, target) {
    if (!c.progress_id) {
      target.innerHTML = '<div class="hdlv2-detail-empty">No consultation record yet. The client needs to complete Stage 3 of the assessment first.</div>';
      return;
    }
    var url = CFG.consultation_url + '?progress_id=' + c.progress_id;
    target.innerHTML = '<div class="hdlv2-consult-card">'
      + '<p>Open the full consultation interface for ' + esc(c.name) + ' — draft report, editable health data, recommendations, and report finalisation.</p>'
      + '<a class="hdlv2-detail-deeplink" href="' + esc(url) + '">Open consultation →</a>'
      + '</div>';
  }

  function fetchTimeline(c) {
    return fetch(CFG.api_base + '/timeline/' + c.user_id, {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': CFG.nonce },
    })
      .then(function (r) { return r.ok ? r.json() : []; })
      .then(function (d) { return Array.isArray(d) ? d : (d && d.entries) || []; });
  }

  function buildV2OnlyRow(c) {
    var row = document.createElement('tr');
    row.className = 'client-row hdlv2-v2-only-row';
    row.dataset.clientHash = c.email_hash || '';
    var dash = '—';
    var badgeStyle = 'color:' + c.color + ';background:' + hexToRgba(c.color, 0.12) + ';border:1px solid ' + hexToRgba(c.color, 0.35) + ';';
    row.innerHTML = [
      '<td class="client-name">' + esc(c.name) + '</td>',
      '<td class="date-cell">' + dash + '</td>',
      '<td class="date-cell">' + esc(formatDate(c.last_checkin_date) || dash) + '</td>',
      '<td class="total-cell">' + dash + '</td>',
      '<td class="status-badge-cell"><span class="hdlv2-inline-badge" style="' + badgeStyle + '">V2 · ' + esc(c.label) + '</span></td>',
      '<td class="actions-cell"></td>',
    ].join('');
    injectExpandButton(row, c);
    return row;
  }

  function chevronSVG() {
    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
  }

  function injectStyles() {
    if (document.getElementById('hdlv2-client-enhance-css')) return;
    var s = document.createElement('style');
    s.id = 'hdlv2-client-enhance-css';
    s.textContent = [
      '.hdlv2-inline-badge { display:inline-flex; align-items:center; padding:3px 9px; margin-left:6px; border-radius:24px; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:0.03em; white-space:nowrap; vertical-align:middle; font-family: Inter, -apple-system, sans-serif; }',
      '.hdlv2-expand-btn { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border:1px solid #e4e6ea; border-radius:50%; background:#fff; color:#888; cursor:pointer; margin-left:6px; padding:0; transition:all 0.15s ease; vertical-align:middle; }',
      '.hdlv2-expand-btn:hover { border-color:#3d8da0; color:#3d8da0; background:rgba(61,141,160,0.06); }',
      '.hdlv2-expand-btn svg { transition: transform 0.2s ease; display:block; }',
      '.hdlv2-expand-btn.open svg { transform: rotate(180deg); }',
      '.hdlv2-detail-row > td { padding: 0 !important; background: #fafbfc; border-bottom: 1px solid #e4e6ea !important; }',
      '.hdlv2-detail-panel { font-family: Inter, -apple-system, BlinkMacSystemFont, sans-serif; padding: 22px 28px 24px; color: #111; }',
      '.hdlv2-detail-head { display:flex; align-items:baseline; gap:10px; margin-bottom:14px; flex-wrap:wrap; }',
      '.hdlv2-detail-head strong { font-family: Poppins, Inter, sans-serif; font-size:15px; color:#111; }',
      '.hdlv2-detail-email { font-size:12px; color:#888; }',
      '.hdlv2-detail-tabs { display:flex; gap:2px; border-bottom: 2px solid #e4e6ea; margin-bottom: 16px; overflow-x:auto; -webkit-overflow-scrolling:touch; }',
      '.hdlv2-detail-tab { appearance:none; background:transparent; border:none; border-bottom: 2px solid transparent; margin-bottom:-2px; padding:8px 16px; font: 500 13px/1.4 Inter, -apple-system, sans-serif; color:#666; cursor:pointer; white-space:nowrap; transition:all 0.15s; }',
      '.hdlv2-detail-tab:hover { color:#3d8da0; }',
      '.hdlv2-detail-tab.active { color:#3d8da0; border-bottom-color:#3d8da0; }',
      '.hdlv2-detail-content { min-height: 120px; font-size: 13px; line-height: 1.6; color: #333; }',
      '.hdlv2-detail-loading, .hdlv2-detail-empty { padding: 24px; text-align: center; color: #888; font-size: 13px; }',
      '.hdlv2-detail-deeplink { display:inline-block; margin-top:14px; padding:8px 18px; border:1px solid #3d8da0; border-radius:24px; color:#3d8da0; text-decoration:none; font: 500 13px/1 Inter, -apple-system, sans-serif; transition: all 0.15s; }',
      '.hdlv2-detail-deeplink:hover { background:#3d8da0; color:#fff; }',
      '.hdlv2-fp-week { font-family: Poppins, Inter, sans-serif; font-size: 12px; font-weight: 600; color: #004F59; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.04em; }',
      '.hdlv2-fp-identity { padding: 10px 14px; background: #fff; border-left: 3px solid #3d8da0; border-radius: 0 8px 8px 0; font-style: italic; color: #111; margin-bottom: 14px; font-size: 14px; font-weight: 500; line-height: 1.5; }',
      '.hdlv2-fp-block-label { font-size: 11px; font-weight: 600; color: #666; text-transform: uppercase; letter-spacing: 0.04em; margin: 14px 0 6px; }',
      '.hdlv2-fp-targets { margin: 0; padding-left: 20px; }',
      '.hdlv2-fp-targets li { margin-bottom: 4px; font-size: 13px; color: #333; line-height: 1.5; }',
      '.hdlv2-fp-adh { display: flex; flex-wrap: wrap; gap: 8px; font-size: 12px; color: #444; }',
      '.hdlv2-fp-adh span { padding: 5px 12px; background: #fff; border: 1px solid #e4e6ea; border-radius: 24px; }',
      '.hdlv2-checkin-list, .hdlv2-timeline-list { list-style: none; margin: 0; padding: 0; }',
      '.hdlv2-checkin-item, .hdlv2-timeline-item { padding: 12px 0; border-bottom: 1px solid #e4e6ea; }',
      '.hdlv2-checkin-item:last-child, .hdlv2-timeline-item:last-child { border-bottom: none; }',
      '.hdlv2-checkin-date, .hdlv2-timeline-meta { font-size: 10px; color: #888; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.04em; }',
      '.hdlv2-timeline-type { color: #3d8da0; font-weight: 600; }',
      '.hdlv2-consult-card { max-width: 540px; padding: 18px 20px; background: #fff; border: 1px solid #e4e6ea; border-radius: 10px; }',
      '.hdlv2-consult-card p { margin: 0 0 12px; font-size: 13px; color: #444; line-height: 1.6; }',
      '.hdlv2-v2-only-row td.client-name::after { content: "  V2 only"; font-size: 10px; font-weight: 500; color: #888; text-transform: uppercase; letter-spacing: 0.04em; margin-left: 6px; }',
    ].join('\n');
    document.head.appendChild(s);
  }

  // ── utils ──
  function hexToRgba(hex, alpha) {
    var h = String(hex || '#94a3b8').replace('#', '');
    if (h.length === 3) h = h.split('').map(function (c) { return c + c; }).join('');
    var r = parseInt(h.substr(0, 2), 16), g = parseInt(h.substr(2, 2), 16), b = parseInt(h.substr(4, 2), 16);
    if (isNaN(r) || isNaN(g) || isNaN(b)) return 'rgba(148,163,184,' + alpha + ')';
    return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
  }

  function formatDate(ts) {
    if (!ts) return '';
    var d = new Date(String(ts).replace(' ', 'T'));
    if (isNaN(d.getTime())) return String(ts);
    var days = Math.floor((Date.now() - d.getTime()) / 86400000);
    if (days < 0) days = 0;
    if (days === 0) return 'Today';
    if (days === 1) return 'Yesterday';
    if (days < 7) return days + ' days ago';
    if (days < 30) return Math.floor(days / 7) + ' weeks ago';
    return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
  }

  function formatDateLabel(ts) {
    if (!ts) return '';
    var d = new Date(String(ts).replace(' ', 'T'));
    if (isNaN(d.getTime())) return String(ts);
    return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
  }

  function parseJSON(s) {
    if (typeof s !== 'string') return s;
    try { return JSON.parse(s); } catch (e) { return null; }
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }
})();
