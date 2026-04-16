/**
 * HDL V2 Client Timeline — Chronological event view.
 * @package HDL_Longevity_V2
 * @since 0.7.0
 */
(function () {
  'use strict';

  var CFG = window.hdlv2_timeline || {};
  if (!CFG.api_base) return;

  var root = document.getElementById('hdlv2-timeline');
  if (!root) return;

  // 6A: Expanded icons for all entry types
  var ICONS = {
    checkin_summary: '\ud83d\udde3\ufe0f', checkin: '\ud83d\udde3\ufe0f',
    flight_plan: '\u2708\ufe0f',
    adherence: '\ud83d\udcca',
    note: '\ud83d\udcdd',
    measurement: '\ud83d\udccf',
    monthly_summary: '\ud83d\udcc5',
    milestone: '\ud83c\udfc6',
    document: '\ud83d\udcc4',
    report: '\ud83d\udcc4',
    chat: '\ud83d\udcac',
    metric: '\ud83d\udcca'
  };

  var SOURCE_LABELS = {
    system: 'System', practitioner: 'Practitioner', client_text: 'Client',
    client_audio: 'Client', wearable: 'Wearable', import: 'Import'
  };

  // 6B: Filter definitions
  var FILTERS = [
    { value: '', label: 'All' },
    { value: 'checkin_summary', label: 'Check-ins' },
    { value: 'flight_plan', label: 'Flight Plans' },
    { value: 'note', label: 'Notes' },
    { value: 'adherence', label: 'Adherence' },
    { value: 'measurement', label: 'Measurements' },
    { value: 'monthly_summary', label: 'Monthly' }
  ];

  var state = { page: 1, type: '', search: '', items: [], loading: false, hasMore: true, clientId: '' };
  var token = '';
  try { token = new URLSearchParams(window.location.search).get('token') || ''; } catch (e) {}

  function init() {
    var clientId = CFG.client_id || new URLSearchParams(window.location.search).get('client_id') || '';
    if (!clientId) { root.innerHTML = '<p style="color:#888;">No client specified.</p>'; return; }
    state.clientId = clientId;
    renderShell();
    loadEntries();
  }

  function renderShell() {
    var html = '<div class="hdlv2-card">'
      + '<div class="hdlv2-header"><h2>Client Timeline</h2></div>';

    // 6B: Filter buttons
    html += '<div style="padding:0 24px 12px;display:flex;gap:6px;flex-wrap:wrap;">';
    FILTERS.forEach(function (f) { html += filterBtn(f.value, f.label); });
    html += '</div>';

    // 6C: Search input
    html += '<div style="padding:0 24px 12px;"><input id="hdlv2-tl-search" type="text" placeholder="Search timeline..." style="width:100%;padding:8px 12px;border:1px solid #e4e6ea;border-radius:8px;font-size:13px;box-sizing:border-box;background:#f8f9fb;"></div>';

    html += '<div id="hdlv2-tl-entries" style="padding:0 24px;"></div>'
      + '<div id="hdlv2-tl-more" style="padding:12px 24px;text-align:center;"></div>';

    // 6D: Practitioner-only add note
    if (CFG.is_practitioner) {
      html += '<div style="padding:12px 24px;border-top:1px solid #e4e6ea;">'
        + '<button id="hdlv2-tl-add-note" class="hdlv2-btn" style="width:auto;font-size:13px;padding:8px 20px;">Add a note</button>'
        + '<div id="hdlv2-tl-note-form" style="display:none;margin-top:12px;"></div>'
        + '</div>';
    }
    html += '</div>';
    root.innerHTML = html;

    // Bind filters
    root.querySelectorAll('[data-filter]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        state.type = btn.getAttribute('data-filter');
        state.page = 1; state.items = []; state.hasMore = true;
        root.querySelectorAll('[data-filter]').forEach(function (b) { b.style.background = '#f8f9fb'; b.style.color = '#666'; b.style.borderColor = '#e4e6ea'; });
        btn.style.background = '#3d8da0'; btn.style.color = '#fff'; btn.style.borderColor = '#3d8da0';
        loadEntries();
      });
    });

    // Bind search (debounced)
    var searchEl = document.getElementById('hdlv2-tl-search');
    var searchTimer;
    searchEl.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        state.search = searchEl.value.trim();
        state.page = 1; state.items = []; state.hasMore = true;
        loadEntries();
      }, 400);
    });

    // Add note button (practitioner only)
    if (CFG.is_practitioner) {
      var noteBtn = document.getElementById('hdlv2-tl-add-note');
      var noteForm = document.getElementById('hdlv2-tl-note-form');
      noteBtn.addEventListener('click', function () {
        noteForm.style.display = noteForm.style.display === 'none' ? 'block' : 'none';
        if (noteForm.style.display === 'block' && window.HDLAudioComponent) {
          noteForm.innerHTML = '';
          HDLAudioComponent.create(noteForm, {
            contextType: 'consultation_notes',
            apiBase: CFG.api_base.replace('/timeline', '/audio'),
            nonce: CFG.nonce,
            showConsent: true,
            onConfirm: function (summary) { addNote(summary); }
          });
        }
      });
    }
  }

  function filterBtn(val, label) {
    var active = state.type === val;
    return '<button data-filter="' + val + '" style="padding:5px 12px;border:1px solid ' + (active ? '#3d8da0' : '#e4e6ea') + ';border-radius:20px;font-size:11px;cursor:pointer;background:' + (active ? '#3d8da0' : '#f8f9fb') + ';color:' + (active ? '#fff' : '#666') + ';font-weight:500;transition:all 0.15s;">' + label + '</button>';
  }

  function loadEntries() {
    if (state.loading) return;
    state.loading = true;

    // 6D: Practitioner sees full view, client sees filtered (/client endpoint)
    var url = CFG.api_base + '/' + state.clientId + (CFG.is_practitioner ? '' : '/client');
    url += '?page=' + state.page + '&per_page=20';
    if (state.type) url += '&type=' + encodeURIComponent(state.type);
    if (state.search) url += '&search=' + encodeURIComponent(state.search);
    if (token) url += '&token=' + encodeURIComponent(token);

    fetch(url, { headers: { 'X-WP-Nonce': CFG.nonce } })
      .then(function (r) {
        var total = parseInt(r.headers.get('X-WP-TotalPages') || '1', 10);
        state.hasMore = state.page < total;
        return r.json();
      })
      .then(function (items) {
        state.loading = false;
        if (state.page === 1) state.items = items;
        else state.items = state.items.concat(items);
        renderEntries();
      })
      .catch(function () { state.loading = false; });
  }

  // 6A: Enhanced entry rendering
  function renderEntries() {
    var el = document.getElementById('hdlv2-tl-entries');
    if (!state.items.length) {
      el.innerHTML = '<p style="color:#aaa;text-align:center;padding:20px 0;">No timeline entries yet.</p>';
    } else {
      var html = '';
      state.items.forEach(function (item, idx) {
        var icon = ICONS[item.type] || '\ud83d\udccc';
        var sourceLabel = SOURCE_LABELS[item.source] || item.source || 'System';

        // Date display — use date field, show range for intervals
        var dateStr = '';
        if (item.date) {
          dateStr = fmtDate(item.date);
          if (item.temporal_type === 'interval' && item.end_date) {
            dateStr += ' \u2013 ' + fmtDate(item.end_date);
          }
        } else if (item.created_at) {
          dateStr = fmtDate(item.created_at);
        }

        // Flag badge
        var flagBadge = item.has_flags ? ' <span style="background:#fef2f2;color:#dc2626;font-size:9px;padding:2px 6px;border-radius:4px;font-weight:600;">FLAG</span>' : '';

        // Source badge
        var sourceBadge = '<span style="background:#f0f1f3;color:#888;font-size:9px;padding:2px 6px;border-radius:4px;">' + esc(sourceLabel) + '</span>';

        // Private badge (practitioner view only)
        var privateBadge = item.is_private ? ' <span style="background:#ede9fe;color:#7c3aed;font-size:9px;padding:2px 6px;border-radius:4px;">Private</span>' : '';

        html += '<div style="border-bottom:1px solid #f0f0f0;padding:12px 0;">'
          + '<div style="display:flex;align-items:flex-start;gap:10px;">'
          + '<span style="font-size:20px;line-height:1;flex-shrink:0;margin-top:2px;">' + icon + '</span>'
          + '<div style="flex:1;min-width:0;">'
          + '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">'
          + '<span style="font-size:13px;font-weight:600;color:#111;">' + esc(item.title) + '</span>'
          + flagBadge + privateBadge
          + '</div>'
          + '<div style="font-size:11px;color:#aaa;margin-top:2px;display:flex;gap:8px;align-items:center;">'
          + '<span>' + dateStr + '</span>' + sourceBadge
          + '</div>';

        // Summary text
        if (item.summary) {
          html += '<div style="font-size:12px;color:#555;margin-top:6px;line-height:1.5;">' + esc(item.summary).substring(0, 250) + (item.summary.length > 250 ? '...' : '') + '</div>';
        }

        // Expandable detail (click to show)
        if (item.detail) {
          html += '<details style="margin-top:6px;"><summary style="font-size:11px;color:#3d8da0;cursor:pointer;font-weight:500;">View detail</summary>'
            + '<div style="background:#f8f9fb;border:1px solid #e4e6ea;border-radius:6px;padding:10px;margin-top:6px;font-size:11px;line-height:1.5;color:#444;max-height:300px;overflow-y:auto;">'
            + renderDetail(item.detail, item.type)
            + '</div></details>';
        }

        html += '</div></div></div>';
      });
      el.innerHTML = html;
    }

    var moreEl = document.getElementById('hdlv2-tl-more');
    if (state.hasMore) {
      moreEl.innerHTML = '<button class="hdlv2-btn-secondary" style="font-size:13px;padding:8px 20px;">Load more</button>';
      moreEl.querySelector('button').addEventListener('click', function () { state.page++; loadEntries(); });
    } else {
      moreEl.innerHTML = '';
    }
  }

  // Render detail based on entry type — human-readable formatting
  function renderDetail(detail, type) {
    if (!detail || typeof detail !== 'object') return esc(String(detail));

    // Check-in summary — structured display
    if (type === 'checkin_summary' || type === 'checkin') {
      var html = '';
      if (detail.check_in_summary) html += '<p style="margin:0 0 8px;">' + esc(detail.check_in_summary) + '</p>';
      if (detail.fitness_adherence) html += '<div><strong>Fitness:</strong> ' + esc(detail.fitness_adherence.summary || '') + ' (' + (detail.fitness_adherence.score || '?') + '/10)</div>';
      if (detail.nutrition_adherence) html += '<div><strong>Nutrition:</strong> ' + esc(detail.nutrition_adherence.summary || '') + ' (' + (detail.nutrition_adherence.score || '?') + '/10)</div>';
      if (detail.wins && detail.wins.length) html += '<div><strong>Wins:</strong> ' + detail.wins.map(esc).join(', ') + '</div>';
      if (detail.obstacles && detail.obstacles.length) html += '<div><strong>Obstacles:</strong> ' + detail.obstacles.map(esc).join(', ') + '</div>';
      return html || formatJson(detail);
    }

    // Adherence — bar display
    if (type === 'adherence') {
      var html = '';
      ['overall', 'movement', 'nutrition', 'key_action'].forEach(function (k) {
        if (detail[k] !== undefined) {
          var pct = detail[k];
          var color = pct >= 70 ? '#10b981' : pct >= 40 ? '#f59e0b' : '#dc2626';
          var label = k === 'key_action' ? 'Key Actions' : k.charAt(0).toUpperCase() + k.slice(1);
          html += '<div style="margin-bottom:4px;"><span style="display:inline-block;width:80px;">' + label + ':</span>'
            + '<span style="display:inline-block;width:60px;background:#e4e6ea;border-radius:3px;height:10px;vertical-align:middle;"><span style="display:block;height:100%;width:' + pct + '%;background:' + color + ';border-radius:3px;"></span></span>'
            + ' <strong style="color:' + color + ';">' + pct + '%</strong></div>';
        }
      });
      return html || formatJson(detail);
    }

    return formatJson(detail);
  }

  function formatJson(obj) {
    try {
      return '<pre style="margin:0;white-space:pre-wrap;word-break:break-word;">' + esc(JSON.stringify(obj, null, 2)) + '</pre>';
    } catch (e) {
      return esc(String(obj));
    }
  }

  function addNote(summary) {
    fetch(CFG.api_base + '/add-note', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify({ client_id: parseInt(state.clientId, 10), title: 'Practitioner note', summary: summary })
    })
      .then(function (r) { return r.json(); })
      .then(function () { state.page = 1; state.items = []; loadEntries(); document.getElementById('hdlv2-tl-note-form').style.display = 'none'; });
  }

  function fmtDate(dateStr) {
    if (!dateStr) return '';
    var d = new Date(dateStr.replace(' ', 'T'));
    if (isNaN(d.getTime())) return dateStr;
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
  }

  function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
