/**
 * HDL V2 Practitioner Dashboard — client roster for longevity clients.
 *
 * Fetches /dashboard/clients, surfaces "Needs Attention" clients above the main
 * list, and renders a table-style roster with deep links into the consultation
 * and flight plan pages. Matches the V2 design language (Inter/Poppins, #3d8da0
 * teal, 14px / 8px radii, calm inline states — no toasts, no modals).
 *
 * @package HDL_Longevity_V2
 * @since 0.9.6
 */
(function () {
  'use strict';

  var CFG = window.hdlv2_prac_dashboard || {};
  var root = document.getElementById('hdlv2-practitioner-dashboard');
  if (!root || !CFG.api_base || !CFG.nonce) return;

  var POLL_MS = 20000;
  var pollTimer = null;

  injectStyles();
  renderLoading();
  loadClients();
  startPolling();
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) stopPolling();
    else { loadClients(); startPolling(); }
  });

  function startPolling() {
    if (pollTimer) return;
    pollTimer = setInterval(function () { if (!document.hidden) loadClients(); }, POLL_MS);
  }
  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  // ── Styles — scoped under .hdlv2-prac-dash so they cannot leak into Divi/theme CSS ──
  function injectStyles() {
    if (document.getElementById('hdlv2-prac-dashboard-css')) return;
    var s = document.createElement('style');
    s.id = 'hdlv2-prac-dashboard-css';
    s.textContent = [
      '.hdlv2-prac-dash { font-family: Inter, -apple-system, BlinkMacSystemFont, sans-serif; max-width: 1080px; margin: 0 auto; padding: 0 16px; color: #111; }',
      '.hdlv2-prac-dash h1 { font-family: Poppins, Inter, sans-serif; font-size: 24px; font-weight: 600; margin: 0 0 4px; color: #111; }',
      '.hdlv2-prac-dash h2 { font-family: Poppins, Inter, sans-serif; font-size: 15px; font-weight: 600; margin: 0 0 12px; color: #004F59; letter-spacing: 0.02em; text-transform: uppercase; }',
      '.hdlv2-prac-dash .hdlv2-pd-sub { font-size: 13px; color: #888; margin: 0 0 24px; }',
      '.hdlv2-prac-dash .hdlv2-pd-section { margin-bottom: 28px; }',
      '.hdlv2-prac-dash .hdlv2-pd-card { background: #fff; border: 1px solid #e4e6ea; border-radius: 14px; box-shadow: 0 4px 24px rgba(0,0,0,0.04); overflow: hidden; }',
      '.hdlv2-prac-dash .hdlv2-pd-attention { border-color: #fecaca; background: #fef7f7; }',
      '.hdlv2-prac-dash .hdlv2-pd-attention h2 { color: #991b1b; }',
      '.hdlv2-prac-dash .hdlv2-pd-attention-row { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid #fecaca; gap: 14px; }',
      '.hdlv2-prac-dash .hdlv2-pd-attention-row:last-child { border-bottom: none; }',
      '.hdlv2-prac-dash .hdlv2-pd-attention-row .hdlv2-pd-name { font-weight: 600; color: #991b1b; min-width: 160px; font-size: 14px; }',
      '.hdlv2-prac-dash .hdlv2-pd-attention-reasons { flex: 1; font-size: 13px; color: #7f1d1d; line-height: 1.5; }',
      '.hdlv2-prac-dash .hdlv2-pd-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 14px; }',
      '.hdlv2-prac-dash .hdlv2-pd-table th { background: #f8f9fb; text-align: left; padding: 12px 20px; font-size: 11px; font-weight: 600; color: #666; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 1px solid #e4e6ea; }',
      '.hdlv2-prac-dash .hdlv2-pd-table td { padding: 14px 20px; border-bottom: 1px solid #f0f1f3; vertical-align: middle; }',
      '.hdlv2-prac-dash .hdlv2-pd-table tr:last-child td { border-bottom: none; }',
      '.hdlv2-prac-dash .hdlv2-pd-table tr:hover td { background: #fafbfc; }',
      '.hdlv2-prac-dash .hdlv2-pd-client-name { font-weight: 600; color: #111; }',
      '.hdlv2-prac-dash .hdlv2-pd-client-email { font-size: 12px; color: #888; margin-top: 2px; }',
      '.hdlv2-prac-dash .hdlv2-pd-badge { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 24px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; white-space: nowrap; }',
      '.hdlv2-prac-dash .hdlv2-pd-last-checkin { color: #888; font-size: 13px; }',
      '.hdlv2-prac-dash .hdlv2-pd-actions { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }',
      '.hdlv2-prac-dash .hdlv2-pd-btn { display: inline-flex; align-items: center; padding: 6px 12px; border: 1px solid #e4e6ea; border-radius: 24px; background: #fff; color: #444; font-size: 12px; font-weight: 500; text-decoration: none; font-family: inherit; cursor: pointer; transition: all 0.15s; }',
      '.hdlv2-prac-dash .hdlv2-pd-btn:hover { border-color: #3d8da0; color: #3d8da0; background: rgba(61, 141, 160, 0.05); }',
      '.hdlv2-prac-dash .hdlv2-pd-btn-primary { background:#d97706; color:#fff; border-color:#d97706; font-weight:600; }',
      '.hdlv2-prac-dash .hdlv2-pd-btn-primary:hover { background:#b45309; color:#fff; border-color:#b45309; box-shadow:0 4px 12px rgba(217,119,6,0.3); }',
      '.hdlv2-prac-dash .hdlv2-pd-btn-primary:disabled { opacity:0.6; cursor:not-allowed; }',
      '.hdlv2-prac-dash .hdlv2-release-pulse { animation: hdlv2-release-pulse 1.2s ease-in-out 3; }',
      '@keyframes hdlv2-release-pulse { 0%,100% { box-shadow: 0 0 0 0 rgba(217,119,6,0.5); } 50% { box-shadow: 0 0 0 10px rgba(217,119,6,0); } }',
      '.hdlv2-prac-dash .hdlv2-pd-empty { text-align: center; padding: 48px 24px; color: #888; font-size: 14px; }',
      '.hdlv2-prac-dash .hdlv2-pd-loading { text-align: center; padding: 60px 24px; color: #888; }',
      '.hdlv2-prac-dash .hdlv2-pd-loading .hdlv2-spinner { width: 36px; height: 36px; border: 3px solid #e4e6ea; border-top-color: #3d8da0; border-radius: 50%; animation: hdlv2-pd-spin 0.8s linear infinite; margin: 0 auto 16px; }',
      '@keyframes hdlv2-pd-spin { to { transform: rotate(360deg); } }',
      '@media (max-width: 640px) {',
      '  .hdlv2-prac-dash .hdlv2-pd-table th:nth-child(3), .hdlv2-prac-dash .hdlv2-pd-table td:nth-child(3) { display: none; }',
      '  .hdlv2-prac-dash .hdlv2-pd-actions { flex-direction: column; align-items: flex-end; }',
      '}',
    ].join('\n');
    document.head.appendChild(s);
  }

  function renderLoading() {
    // v0.37.0 — shaped skeleton (h1 stripe + 4 client rows) on initial mount.
    // Only fires from the boot path (mount()), never from the 20s silent
    // poll — the poll calls loadClients() directly, which calls render()
    // on the next tick without going through renderLoading(). No re-flash.
    var skel = (window.HDLV2Loading && typeof HDLV2Loading.skeleton === 'function')
      ? HDLV2Loading.skeleton('dashboard')
      : '<div class="hdlv2-pd-loading"><div class="hdlv2-spinner"></div>Loading your clients…</div>';
    root.innerHTML = '<div class="hdlv2-prac-dash">'
      + '<div class="hdlv2-skel hdlv2-skel-heading" style="width:280px;max-width:60%;height:30px;margin:0 0 10px;"></div>'
      + '<div class="hdlv2-skel hdlv2-skel-line s" style="margin:0 0 22px;"></div>'
      + skel
      + '</div>';
  }

  function renderError(msg) {
    root.innerHTML = '<div class="hdlv2-prac-dash">'
      + '<div class="hdlv2-pd-empty" style="color:#dc2626;">' + esc(msg) + '</div>'
      + '</div>';
  }

  var hasRendered = false;

  function loadClients() {
    // 2026-07-14 RL fix — stop polling while a 429 Retry-After cooldown is
    // active (armed by hdlv2-rate-limit.js); X-HDLV2-Bg marks this as
    // background traffic so a 429 shows the pill, never the modal. A failed
    // background refresh keeps the last rendered roster instead of wiping
    // it with the error card (that stays for first-load failures only).
    try {
      if (window.hdlv2RateLimit && window.hdlv2RateLimit.isCoolingDown && window.hdlv2RateLimit.isCoolingDown()) return;
    } catch (e) { /* never block the poll on a guard error */ }
    fetch(CFG.api_base + '/dashboard/clients', {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': CFG.nonce, 'X-HDLV2-Bg': '1' },
    })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (clients) { hasRendered = true; render(Array.isArray(clients) ? clients : []); })
      .catch(function () {
        if (!hasRendered) renderError('Could not load your clients. Refresh the page to try again.');
      });
  }

  function render(clients) {
    // Sort: attention first (by has-reasons + critical status), then most-recent check-in, then name.
    clients.sort(function (a, b) {
      var aAtt = a.status === 'needs_attention' ? 0 : 1;
      var bAtt = b.status === 'needs_attention' ? 0 : 1;
      if (aAtt !== bAtt) return aAtt - bAtt;
      if (a.last_checkin_date && b.last_checkin_date) {
        return b.last_checkin_date.localeCompare(a.last_checkin_date);
      }
      if (a.last_checkin_date) return -1;
      if (b.last_checkin_date) return 1;
      return (a.name || '').localeCompare(b.name || '');
    });

    var attention = clients.filter(function (c) { return c.status === 'needs_attention'; });

    var html = '<div class="hdlv2-prac-dash">'
      + '<h1>Longevity Clients</h1>'
      + '<p class="hdlv2-pd-sub">' + clients.length + ' ' + (clients.length === 1 ? 'client' : 'clients') + ' in your V2 programme.</p>';

    if (attention.length) {
      html += '<div class="hdlv2-pd-section">'
        + '<h2>Needs Attention (' + attention.length + ')</h2>'
        + '<div class="hdlv2-pd-card hdlv2-pd-attention">';
      attention.forEach(function (c) {
        html += '<div class="hdlv2-pd-attention-row">'
          + '<div class="hdlv2-pd-name">' + esc(c.name) + '</div>'
          + '<div class="hdlv2-pd-attention-reasons">' + (c.reasons && c.reasons.length ? esc(c.reasons.join(' · ')) : 'Review recent activity') + '</div>'
          + '<div class="hdlv2-pd-actions">' + renderActions(c) + '</div>'
          + '</div>';
      });
      html += '</div></div>';
    }

    html += '<div class="hdlv2-pd-section">'
      + '<h2>All Clients</h2>'
      + '<div class="hdlv2-pd-card">';

    if (!clients.length) {
      // v0.22.24 — compact empty state. The tutorial section below the
      // shortcode root carries the full onboarding (Welcome hero + Embed/
      // Invite paths + 4 steps + Customize widget + Read guide). Keeping
      // a one-line nudge here so the live section never looks broken.
      html += '<div class="hdlv2-pd-empty" style="padding:18px 24px;">No clients yet — use the tools below to bring your first one in.</div>';
    } else {
      html += '<table class="hdlv2-pd-table">'
        + '<thead><tr>'
        + '<th>Client</th><th>Status</th><th>Last check-in</th><th style="text-align:right;">Actions</th>'
        + '</tr></thead><tbody>';
      clients.forEach(function (c) {
        html += '<tr>'
          + '<td><div class="hdlv2-pd-client-name">' + esc(c.name) + '</div>'
          + '<div class="hdlv2-pd-client-email">' + esc(c.email) + '</div></td>'
          + '<td>' + renderBadge(c) + '</td>'
          + '<td><span class="hdlv2-pd-last-checkin">' + formatDate(c.last_checkin_date) + '</span></td>'
          + '<td><div class="hdlv2-pd-actions">' + renderActions(c) + '</div></td>'
          + '</tr>';
      });
      html += '</tbody></table>';
    }

    html += '</div></div></div>';
    root.innerHTML = html;
    bindReleaseButtons();
    applyReleaseDeepLink();
  }

  function applyReleaseDeepLink() {
    var params = new URLSearchParams(window.location.search);
    var target = parseInt(params.get('release_progress_id') || '0', 10);
    if (!target) return;
    var btn = root.querySelector('[data-release-progress="' + target + '"]');
    if (!btn) return;
    btn.scrollIntoView({ behavior: 'smooth', block: 'center' });
    btn.classList.add('hdlv2-release-pulse');
  }

  function renderBadge(c) {
    // Soft pill — use the status colour as both border and darkened foreground,
    // with a 12% tint background so it reads as calm, not loud.
    var color = c.color || '#94a3b8';
    var bg = hexToRgba(color, 0.12);
    return '<span class="hdlv2-pd-badge" style="color:' + color + ';background:' + bg + ';border:1px solid ' + hexToRgba(color, 0.35) + ';">' + esc(c.label || c.status) + '</span>';
  }

  function renderActions(c) {
    var parts = [];
    if (c.status === 'awaiting_why_release' && c.progress_id) {
      parts.push('<button type="button" class="hdlv2-pd-btn hdlv2-pd-btn-primary" data-release-progress="' + c.progress_id + '">Invite to Stage 3</button>');
    }
    if (c.progress_id) {
      var consultUrl = CFG.consultation_url + '?progress_id=' + encodeURIComponent(c.progress_id);
      parts.push('<a class="hdlv2-pd-btn" href="' + esc(consultUrl) + '">Consultation</a>');
    }
    var fpUrl = CFG.flight_plan_url + '?client_id=' + encodeURIComponent(c.user_id);
    parts.push('<a class="hdlv2-pd-btn" href="' + esc(fpUrl) + '">Flight Plan</a>');
    return parts.join('');
  }

  function bindReleaseButtons() {
    root.querySelectorAll('[data-release-progress]').forEach(function (btn) {
      btn.addEventListener('click', function () { releaseWhy(btn); });
    });
  }

  function releaseWhy(btn) {
    var progressId = parseInt(btn.getAttribute('data-release-progress'), 10);
    if (!progressId) return;
    // v0.20.10 — themed modal instead of browser-native confirm().
    var ask = (window.HDLV2UI && window.HDLV2UI.confirm)
      ? window.HDLV2UI.confirm({
          title: 'Invite this client to Stage 3?',
          body: 'This emails them the Stage 3 invitation and cannot be undone.',
          confirmLabel: 'Yes, send invite',
          cancelLabel: 'Cancel'
        })
      : Promise.resolve(window.confirm('Invite this client to Stage 3?\n\nThis emails them the Stage 3 invitation and cannot be undone.'));
    ask.then(function (confirmed) {
      if (!confirmed) return;
      _doReleaseWhy(btn, progressId);
    });
  }

  function _doReleaseWhy(btn, progressId) {
    btn.disabled = true;
    var original = btn.textContent;
    btn.textContent = 'Releasing…';

    fetch(CFG.api_base + '/form/release-why', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify({ progress_id: progressId }),
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        if (!res.ok || res.body.code) {
          btn.disabled = false;
          btn.textContent = original;
          window.HDLV2UI.toast('Could not release: ' + (res.body.message || 'Please refresh and try again.'), "error");
          return;
        }
        btn.textContent = 'Released ✓';
        btn.style.background = '#10b981';
        btn.style.color = '#fff';
        btn.style.borderColor = '#10b981';
        // Reload after short delay so the new state shows up in the table.
        setTimeout(function () { loadClients(); }, 1200);
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = original;
        window.HDLV2UI.toast('Network error. Please refresh and try again.', "error");
      });
  }

  function formatDate(ts) {
    if (!ts) return '—';
    // The timestamp comes from MySQL in site-local time (no tz). Parse as local
    // to avoid "negative days ago" artefacts when site tz differs from UTC.
    var d = new Date(ts.replace(' ', 'T'));
    if (isNaN(d.getTime())) return esc(ts);
    var days = Math.floor((Date.now() - d.getTime()) / 86400000);
    if (days < 0) days = 0;
    if (days === 0) return 'Today';
    if (days === 1) return 'Yesterday';
    if (days < 7) return days + ' days ago';
    if (days < 30) return Math.floor(days / 7) + ' weeks ago';
    return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
  }

  function hexToRgba(hex, alpha) {
    var h = hex.replace('#', '');
    if (h.length === 3) h = h.split('').map(function (c) { return c + c; }).join('');
    var r = parseInt(h.substr(0, 2), 16);
    var g = parseInt(h.substr(2, 2), 16);
    var b = parseInt(h.substr(4, 2), 16);
    if (isNaN(r) || isNaN(g) || isNaN(b)) return 'rgba(148,163,184,' + alpha + ')';
    return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }
})();
