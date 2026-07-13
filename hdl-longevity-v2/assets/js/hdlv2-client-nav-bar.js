/**
 * HDL V2 — Practitioner nav spine.
 *
 * Injects a single thin strip above the main content on /consultation/
 * and /my-flight-plan/ (when the practitioner is viewing another user's
 * record): ← Back to clients | Clients › [Page] › [Client] | Updated Xs ago
 *
 * Reads config from window.hdlv2_nav_bar — injected server-side via
 * wp_localize_script from the respective shortcode.
 *
 * @since 0.21.0
 */
(function () {
  'use strict';

  var CFG = window.hdlv2_nav_bar || null;
  if (!CFG || !CFG.clients_url) return;

  var bar         = null;
  var freshnessEl = null;
  var lastVersion = 0;
  var lastFetchAt = 0;
  var POLL_MS     = 4000;
  var TICK_MS     = 5000;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }

  function mount() {
    ensureStyles();
    bar = document.createElement('div');
    bar.className = 'hdlv2-navbar';
    bar.setAttribute('role', 'navigation');
    bar.setAttribute('aria-label', 'Practitioner navigation');

    var client = CFG.client_name ? ' <span class="hdlv2-navbar-sep">›</span> <span class="hdlv2-navbar-client">' + esc(CFG.client_name) + '</span>' : '';
    var page   = esc(CFG.page_label || 'V2');

    bar.innerHTML = ''
      + '<div class="hdlv2-navbar-inner">'
      +   '<a class="hdlv2-navbar-back" href="' + esc(CFG.clients_url) + '">&larr; Back to clients</a>'
      +   '<div class="hdlv2-navbar-crumb" aria-hidden="true">'
      +     '<span class="hdlv2-navbar-root">Clients</span>'
      +     ' <span class="hdlv2-navbar-sep">›</span> '
      +     '<span class="hdlv2-navbar-page">' + page + '</span>'
      +     client
      +   '</div>'
      +   '<div class="hdlv2-navbar-freshness" data-role="freshness"></div>'
      + '</div>';

    // Insert as the first child of <body> so it sits above the site header.
    // Easier than fighting Divi's container structure and keeps the bar
    // full-width at the top of the viewport.
    document.body.insertBefore(bar, document.body.firstChild);
    freshnessEl = bar.querySelector('[data-role="freshness"]');
    document.body.classList.add('hdlv2-has-navbar');

    if (CFG.api_base && CFG.nonce) {
      pollVersion();
      setInterval(pollVersion, POLL_MS);
      setInterval(tickFreshness, TICK_MS);
      tickFreshness();
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) pollVersion();
      });
    }
  }

  function pollVersion() {
    if (!CFG.api_base || !CFG.nonce) return;
    // 2026-07-14 RL fix — stop polling while a 429 Retry-After cooldown is
    // active; X-HDLV2-Bg marks this as background traffic (no modal on 429).
    try {
      if (window.hdlv2RateLimit && window.hdlv2RateLimit.isCoolingDown && window.hdlv2RateLimit.isCoolingDown()) return;
    } catch (e) { /* never block the poll on a guard error */ }
    fetch(CFG.api_base + '/dashboard/version?_=' + Date.now(), {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': CFG.nonce, 'X-HDLV2-Bg': '1' },
      cache: 'no-store',
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || typeof d.v !== 'number') return;
        if (d.v > lastVersion) {
          lastVersion = d.v;
          lastFetchAt = Date.now();
          tickFreshness();
        }
      })
      .catch(function () { /* silent — next tick retries */ });
  }

  function tickFreshness() {
    if (!freshnessEl) return;
    if (!lastFetchAt) { freshnessEl.textContent = ''; return; }
    var s = Math.round((Date.now() - lastFetchAt) / 1000);
    if (s < 3)         freshnessEl.textContent = 'Updated just now';
    else if (s < 60)   freshnessEl.textContent = 'Updated ' + s + 's ago';
    else if (s < 3600) freshnessEl.textContent = 'Updated ' + Math.round(s / 60) + ' min ago';
    else               freshnessEl.textContent = 'Updated ' + Math.round(s / 3600) + ' h ago';
  }

  function ensureStyles() {
    if (document.getElementById('hdlv2-navbar-css')) return;
    var s = document.createElement('style');
    s.id = 'hdlv2-navbar-css';
    s.textContent = [
      '.hdlv2-navbar { position: sticky; top: 0; z-index: 9999; background: #fff; border-bottom: 1px solid #e4e6ea; font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }',
      '.hdlv2-navbar-inner { display: flex; align-items: center; gap: 18px; max-width: 1200px; margin: 0 auto; padding: 12px 24px; min-height: 44px; }',
      '.hdlv2-navbar-back { color: #3d8da0; text-decoration: none; font-weight: 500; font-size: 13px; flex: 0 0 auto; transition: color 0.15s; letter-spacing: 0.01em; }',
      '.hdlv2-navbar-back:hover { color: #004F59; text-decoration: underline; }',
      '.hdlv2-navbar-crumb { flex: 1 1 auto; font-size: 12px; color: #888; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: center; }',
      '.hdlv2-navbar-root { color: #666; }',
      '.hdlv2-navbar-page { color: #666; }',
      '.hdlv2-navbar-client { color: #111; font-weight: 600; }',
      '.hdlv2-navbar-sep { color: #c8cdd3; margin: 0 2px; }',
      '.hdlv2-navbar-freshness { flex: 0 0 auto; font-size: 11px; color: #94a3b8; font-variant-numeric: tabular-nums; letter-spacing: 0.01em; }',
      '@media (max-width: 640px) {',
      '  .hdlv2-navbar-inner { padding: 10px 16px; gap: 12px; }',
      '  .hdlv2-navbar-crumb { display: none; }',
      '}',
    ].join('\n');
    document.head.appendChild(s);
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }
})();
