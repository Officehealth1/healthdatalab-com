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

  var ICONS = { milestone:'\ud83c\udfc6', document:'\ud83d\udcc4', note:'\ud83d\udcdd', checkin:'\u2705', flight_plan:'\u2708\ufe0f', chat:'\ud83d\udcac', metric:'\ud83d\udcca' };
  var state = { page: 1, type: '', search: '', items: [], loading: false, hasMore: true };

  function init() {
    var clientId = CFG.client_id || new URLSearchParams(window.location.search).get('client_id') || '';
    if (!clientId) { root.innerHTML = '<p style="color:#888;">No client specified.</p>'; return; }
    state.clientId = clientId;
    renderShell();
    loadEntries();
  }

  function renderShell() {
    var html = '<div class="hdlv2-card">'
      + '<div class="hdlv2-header"><h2>Client Timeline</h2></div>'
      + '<div style="padding:0 24px 12px;display:flex;gap:6px;flex-wrap:wrap;">'
      + filterBtn('', 'All') + filterBtn('note', 'Notes') + filterBtn('checkin', 'Check-ins')
      + filterBtn('document', 'Reports') + filterBtn('chat', 'Chat') + filterBtn('milestone', 'Milestones')
      + '</div>'
      + '<div style="padding:0 24px 12px;"><input id="hdlv2-tl-search" type="text" placeholder="Search timeline..." style="width:100%;padding:8px 12px;border:1px solid #e4e6ea;border-radius:8px;font-size:13px;box-sizing:border-box;"></div>'
      + '<div id="hdlv2-tl-entries" style="padding:0 24px;"></div>'
      + '<div id="hdlv2-tl-more" style="padding:12px 24px;text-align:center;"></div>';

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
        root.querySelectorAll('[data-filter]').forEach(function (b) { b.style.background = '#f8f9fb'; b.style.color = '#666'; });
        btn.style.background = '#3d8da0'; btn.style.color = '#fff';
        loadEntries();
      });
    });

    // Bind search
    var searchEl = document.getElementById('hdlv2-tl-search');
    var searchTimer;
    searchEl.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        state.search = searchEl.value.trim();
        state.page = 1; state.items = []; state.hasMore = true;
        loadEntries();
      }, 500);
    });

    // Add note button
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
    return '<button data-filter="' + val + '" style="padding:6px 14px;border:1px solid #e4e6ea;border-radius:20px;font-size:12px;cursor:pointer;background:' + (active ? '#3d8da0' : '#f8f9fb') + ';color:' + (active ? '#fff' : '#666') + ';font-weight:500;">' + label + '</button>';
  }

  function loadEntries() {
    if (state.loading) return;
    state.loading = true;

    var url = CFG.api_base + '/' + state.clientId + (CFG.is_practitioner ? '' : '/client')
      + '?page=' + state.page + '&per_page=20';
    if (state.type) url += '&type=' + state.type;
    if (state.search) url += '&search=' + encodeURIComponent(state.search);

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

  function renderEntries() {
    var el = document.getElementById('hdlv2-tl-entries');
    if (!state.items.length) {
      el.innerHTML = '<p style="color:#aaa;text-align:center;padding:20px 0;">No timeline entries yet.</p>';
    } else {
      var html = '';
      state.items.forEach(function (item) {
        var icon = ICONS[item.type] || '\ud83d\udccc';
        var date = item.created_at ? new Date(item.created_at).toLocaleDateString('en-GB', { day:'numeric', month:'short', year:'numeric' }) : '';
        var flagBadge = item.has_flags ? ' <span style="background:#fef2f2;color:#dc2626;font-size:10px;padding:2px 6px;border-radius:4px;">FLAG</span>' : '';
        html += '<div style="border-bottom:1px solid #f0f0f0;padding:12px 0;">'
          + '<div style="display:flex;align-items:center;gap:8px;">'
          + '<span style="font-size:18px;">' + icon + '</span>'
          + '<div style="flex:1;">'
          + '<div style="font-size:13px;font-weight:600;color:#111;">' + esc(item.title) + flagBadge + '</div>'
          + '<div style="font-size:11px;color:#aaa;">' + date + '</div>'
          + '</div></div>'
          + (item.summary ? '<div style="font-size:12px;color:#666;margin-top:6px;line-height:1.5;">' + esc(item.summary).substring(0, 200) + '</div>' : '')
          + '</div>';
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

  function addNote(summary) {
    fetch(CFG.api_base + '/add-note', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify({ client_id: parseInt(state.clientId, 10), title: 'Practitioner note', summary: summary })
    })
      .then(function (r) { return r.json(); })
      .then(function () { state.page = 1; state.items = []; loadEntries(); document.getElementById('hdlv2-tl-note-form').style.display = 'none'; });
  }

  function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
