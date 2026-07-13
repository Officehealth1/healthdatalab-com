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
    clients: [],          // latest /dashboard/clients response
    pendingLeads: [],     // v0.35.1 — latest /widget/leads/pending response
    actionQueueEl: null,  // DOM node for the action queue card
    freshnessEl: null,    // DOM node for "Updated Xs ago"
    sourceFilter: 'all',  // W11 — Source filter state: 'all' | 'practitioner' | 'automation'
    statusFilter: 'all',  // red-flag dashboard: 'all' | 'needs_attention'
    stageFilter: 'any',   // Stalled filter — 'any' | '1' | '2' (v2_total_stages match)
    quietFilter: 'any',   // Stalled filter — 'any' | '14' | '28' (days since last activity)
    defaultSortApplied: false, // W12 — one-shot default-sort guard
    msgV2Client: null,    // P2 — client whose V2-sourced chat modal is open (drives email feed + skip_autolink)
  };

  // v0.35.1 — How many pending leads we surface in the "What needs you
  // today" action queue. Anything past this threshold collapses into a
  // "+N more — review all" link that pops Client Tools on the Pending
  // Leads tab. Keeps the banner from filling the whole viewport when
  // there's a backlog (or a spam wave) of widget submissions.
  var PENDING_LEADS_QUEUE_CAP = 3;
  // In-flight lock for queue-side actions, keyed on lead_id, to prevent
  // double-clicks while the request is mid-flight.
  var _queuePendingActioning = {};
  // 0.47.48 — which pending leads have their Stage-1 detail panel expanded in the
  // top "New widget submissions" strip. Held here (not in the DOM) so the open
  // state survives renderActionQueue()'s wholesale innerHTML rebuild each poll.
  var _openPendingDetails = {};

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


  // v0.21.0 — Two-tier polling:
  //  • every VERSION_POLL_MS, hit /dashboard/version (< 50 byte payload)
  //  • if the digest changed, pull the full /dashboard/clients roster
  //  • FALLBACK_POLL_MS safety net catches the rare missed signal
  // At 50 concurrent practitioners: ~12.5 req/s on the digest endpoint,
  // full pulls only when something actually changed. Replaces the prior
  // 20s unconditional full-pull.
  var VERSION_POLL_MS  = 4000;
  var FALLBACK_POLL_MS = 60000;
  // 2026-07-14 RL fix — the 60s fallback used to full-fetch UNCONDITIONALLY
  // (2 counted reads/min/tab = 120/hr/tab against a 200/hr per-user budget
  // shared by every open tab — idle dashboards self-429'd on LIVE). The
  // fallback now refetches only when the data is genuinely stale; the 4s
  // digest poll (unmetered) remains the realtime trigger.
  var STALE_MS         = 5 * 60 * 1000;
  var versionTimer     = null;
  var fallbackTimer    = null;
  var lastVersion      = 0;
  var lastFetchedAt    = 0;
  var freshnessTimer   = null;

  function init() {
    injectStyles();
    // P3 — swap the animated icon set into the V1 server-rendered rows
    // straight away (before the roster fetch resolves), so the
    // static→animated transition never flashes. Idempotent — later passes
    // skip any svg already carrying .hdlv2-anim-ico.
    decorateActionIcons(state.table);
    mountActionQueueShell();
    mountIrisCard();   // Iridology add-on (IrisMapper) — self-guards on the flag (Rule-0)
    bindDeleteV2Client();
    // P2 — delegated handlers for the unified action cell.
    bindResendLink();
    bindProgressOpen();
    bindMessageIntegration();
    // v0.35.1 — fetch pending leads in parallel with /dashboard/clients
    // so the action queue can render both pre-existing client work AND
    // new widget submissions in the same first paint. Each fetch is
    // independently fault-tolerant: if pending-leads fails, the queue
    // simply omits that group rather than blocking the V1 client list.
    Promise.all([fetchV2Clients(), fetchPendingLeads()]).then(function (results) {
      var list   = results[0] || [];
      var leads  = results[1] || [];
      list.forEach(function (c) {
        if (c.email_hash) state.byHash[c.email_hash] = c;
      });
      state.clients      = list;
      state.pendingLeads = leads;
      enhanceMatchedRows(list);
      appendV2OnlyRows(list);
      // W12 — one-time default sort by latest activity descending. Skipped
      // on subsequent poll-driven re-renders so user column-header clicks
      // are never overridden by a digest tick.
      defaultSortByLatestActivity();
      renderActionQueue(list);
      lastFetchedAt = Date.now();
      updateFreshnessIndicator();
      // Deep-link: email CTA lands here with ?release_progress_id=N.
      // Find the matching row, auto-expand it, highlight the Release action.
      handleReleaseDeepLink(list);
      // v0.21.0 — prime lastVersion so the first version-poll doesn't
      // immediately re-pull what we just fetched.
      pollVersion();
    });
    startPolling();
    startFreshnessTicker();
    // Pause polling when tab is hidden to save battery + requests; catch-up
    // fetch fires the moment the tab comes back.
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        stopPolling();
      } else {
        pollVersion();
        startPolling();
      }
    });
    // v0.35.1 — listen for the realtime "lead actioned" event from
    // hdlv2-dashboard.js (fired after Confirm/Reject in the Pending Leads
    // tab). Forces an immediate pollNow() so the V1 client list AND the
    // action queue both reflect the new state without waiting for the 4s
    // version poll. Fires for both confirm and reject so the inbox count
    // and the action-queue summary stay in lockstep.
    window.addEventListener('hdlv2:lead-actioned', function () {
      pollNow();
    });
  }

  function startPolling() {
    if (!versionTimer) versionTimer = setInterval(pollVersion, VERSION_POLL_MS);
    if (!fallbackTimer) fallbackTimer = setInterval(fallbackTick, FALLBACK_POLL_MS);
  }
  function stopPolling() {
    if (versionTimer)  { clearInterval(versionTimer);  versionTimer = null; }
    if (fallbackTimer) { clearInterval(fallbackTimer); fallbackTimer = null; }
  }
  // 2026-07-14 RL fix — while a 429 Retry-After cooldown is active (armed by
  // hdlv2-rate-limit.js's global fetch wrapper) all polling stops. A poll
  // fired into an active block is a wasted counted request.
  function rlCoolingDown() {
    try {
      return !!(window.hdlv2RateLimit && window.hdlv2RateLimit.isCoolingDown && window.hdlv2RateLimit.isCoolingDown());
    } catch (e) { return false; }
  }
  // 60s safety net: full-fetch ONLY when the digest signal has been missed
  // long enough that the roster is genuinely stale.
  function fallbackTick() {
    if (rlCoolingDown()) return;
    if (Date.now() - lastFetchedAt > STALE_MS) pollNow();
  }
  // Fetch the digest. If it advanced, pull the full roster.
  function pollVersion() {
    if (rlCoolingDown()) return;
    fetch(CFG.api_base + '/dashboard/version?_=' + Date.now(), {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': CFG.nonce, 'X-HDLV2-Bg': '1' },
      cache: 'no-store',
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || typeof data.v !== 'number') return;
        // First call just primes the baseline — we already fetched in init().
        if (lastVersion === 0) { lastVersion = data.v; updateFreshnessIndicator(); return; }
        if (data.v > lastVersion) {
          lastVersion = data.v;
          pollNow();
        } else {
          updateFreshnessIndicator();
        }
      })
      .catch(function () { /* transient network hiccup — next tick retries */ });
  }
  function pollNow() {
    // v0.35.1 — refresh the pending-leads list in parallel so the action
    // queue's "New widget submissions" group reflects fresh server state
    // alongside the client roster.
    return Promise.all([fetchV2Clients(), fetchPendingLeads()]).then(function (results) {
      // 2026-07-14 RL fix — a null roster means the read was blocked (429)
      // or failed: keep the currently rendered roster instead of wiping it
      // with an empty list. Null leads fall back to the last known set.
      var list  = results[0];
      var leads = results[1];
      if (list === null || typeof list === 'undefined') return;
      if (leads === null || typeof leads === 'undefined') leads = state.pendingLeads || [];
      lastFetchedAt = Date.now();
      // Re-index by hash for matched-row lookups
      state.byHash = {};
      list.forEach(function (c) { if (c.email_hash) state.byHash[c.email_hash] = c; });
      reconcileRows(list);
      state.clients      = list;
      state.pendingLeads = leads;
      renderActionQueue(list);
      // v0.35.4 — propagate fresh data into any expanded detail panel
      // whose Flight Plan tab is currently active, so a tick from the
      // client side renders within the next digest poll instead of
      // waiting for the practitioner to manually click the tab again.
      maybeRefreshActiveFlightPlanTabs();
      maybeRefreshActiveReportTabs();
      updateFreshnessIndicator();
    });
  }

  // v0.35.4 — Walks every mounted detail panel, finds those whose
  // Flight Plan tab is currently active, and re-renders them with the
  // latest client object from state.clients. Idempotent: panels with a
  // non-Flight-Plan active tab are skipped, panels with a stale user_id
  // (no longer in state.clients) are skipped silently.
  //
  // Why this is needed: HDLV2_FlightPlan.mount() fetches its own data
  // from /flight-plan/<client_id>/current and renders. Without this
  // refresher, ticks from the client side only become visible when the
  // practitioner manually re-clicks the Flight Plan tab. With it, the
  // 4-second /dashboard/version digest poll triggers a re-mount, which
  // re-fetches the plan + ticks.
  function maybeRefreshActiveFlightPlanTabs() {
    var panels = document.querySelectorAll('.hdlv2-detail-panel');
    if (!panels.length) return;
    panels.forEach(function (panel) {
      // v0.41.19 — selection class renamed from .active to .current so it
      // doesn't collide with the .active-stage journey-state class on
      // tab dots. The auto-refresher follows the rename.
      var activeTab = panel.querySelector('.hdlv2-detail-tab.current');
      if (!activeTab) return;
      if (activeTab.getAttribute('data-tab') !== 'flight-plan') return;
      var uid = panel.getAttribute('data-user-id');
      if (!uid) return;
      var fresh = (state.clients || []).filter(function (c) {
        return String(c.user_id) === String(uid);
      })[0];
      if (!fresh) return;
      var content = panel.querySelector('[data-tab-content]');
      if (!content) return;
      // v0.46.60 — was a full loadFlightPlan() re-mount on every 4s digest
      // advance. That tore down + re-fetched + re-rendered the whole tab,
      // which flashed the content (practitioner-reported "twitching") and
      // reset the inline edit toggle. The renderer already syncs ticks in
      // place (applyTickDiff), so refresh the live instance instead — only
      // re-mount if the instance is gone (first open / detached node).
      var inst = content._hdlv2FpInstance;
      var fpMount = content.querySelector('#hdlv2-flight-plan');
      if (inst && typeof inst.refresh === 'function' && fpMount && fpMount.isConnected) {
        inst.refresh();
      } else {
        loadFlightPlan(fresh, content);
      }
    });
  }

  // v0.47.13 — report tabs (Stage 1/2/3/Final) share the cached client
  // record; these two helpers let the poll refresher detect a real change.
  function reportRecordKey(tab) {
    return (tab === 'stage1' || tab === 'stage2' || tab === 'stage3' || tab === 'final') ? tab : null;
  }
  function reportTabSig(data, tab) {
    var k = reportRecordKey(tab);
    if (!k) return null;
    try { return JSON.stringify((data && data[k]) || null); } catch (e) { return null; }
  }

  // v0.47.13 — sibling to maybeRefreshActiveFlightPlanTabs, for the report
  // tabs. After a Stage-1/2/3/Final webhook stores its PDF (or a report is
  // regenerated) the dashboard download card must appear / refresh WITHOUT
  // the practitioner collapsing+reopening the panel. Twitch-free: re-renders
  // ONLY when the stage record actually changed (signature diff). Each poll's
  // `fresh` client object is a NEW object with no _record_promise, so
  // fetchClientRecord() pulls live data; the in-place re-render via loadTab
  // re-stamps the baseline. Report cards are read-only (no inline-edit state
  // to lose), so a full re-render on change is safe here.
  function maybeRefreshActiveReportTabs() {
    var panels = document.querySelectorAll('.hdlv2-detail-panel');
    if (!panels.length) return;
    panels.forEach(function (panel) {
      var activeTab = panel.querySelector('.hdlv2-detail-tab.current');
      if (!activeTab) return;
      var tabKey = activeTab.getAttribute('data-tab');
      if (!reportRecordKey(tabKey)) return;
      var uid = panel.getAttribute('data-user-id');
      if (!uid) return;
      var fresh = (state.clients || []).filter(function (c) {
        return String(c.user_id) === String(uid);
      })[0];
      if (!fresh || !fresh.progress_id) return;
      var content = panel.querySelector('[data-tab-content]');
      if (!content) return;
      fetchClientRecord(fresh).then(function (data) {
        if (!data) return;                       // network hiccup — keep the current card
        var sig = reportTabSig(data, tabKey);
        if (sig === content._reportSig) return;  // unchanged → no re-render
        content._reportSig = sig;
        loadTab(tabKey, fresh, content);         // changed → re-render in place (re-stamps)
      });
    });
  }

  // v0.35.1 — Pending widget submissions for this practitioner. Fault-
  // tolerant: returns [] on any error so a transient network hiccup or a
  // server-side schema mismatch can't break the V1 client list render.
  function fetchPendingLeads() {
    // X-HDLV2-Bg marks this as background traffic: a 429 here updates the
    // rate-limit pill + arms the shared cooldown but never opens the modal.
    // Resolves null (not []) on failure so pollNow keeps the last known
    // leads instead of blanking the action queue. (2026-07-14 RL fix)
    return fetch(CFG.api_base + '/widget/leads/pending', {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': CFG.rest_nonce || CFG.nonce, 'X-HDLV2-Bg': '1' },
      cache: 'no-store',
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (data === null) return null;
        return (data && Array.isArray(data.leads)) ? data.leads : [];
      })
      .catch(function () { return null; });
  }
  function startFreshnessTicker() {
    if (freshnessTimer) return;
    freshnessTimer = setInterval(updateFreshnessIndicator, 5000);
  }

  // ──────────────────────────────────────────────────────────────────
  //  ACTION QUEUE — "what needs you today"
  //  A card that lives ABOVE the client table and surfaces the 3 most
  //  important practitioner actions: Release WHY, Record Consultation
  //  (awaiting_consult), and Needs-Attention clients. Auto-hides when
  //  empty.
  // ──────────────────────────────────────────────────────────────────

  // v0.22.25 (Phase 16) — localStorage key for the COLLAPSE state.
  //
  // Shape: { hash: '<action-queue-content-hash>', date: 'YYYY-MM-DD' }
  //
  // Auto-resets (i.e. expands again) when:
  //   (a) content hash changes (new event on any flagged client), or
  //   (b) local date > stored date (new day).
  //
  // Why this replaces the v0.20.0 'hdlv2_aq_dismissed' key:
  //   The old × button HID the entire banner with no in-app way to bring it
  //   back same-day with no new activity. Practitioners (Matthew, 2026-04-27)
  //   reasonably expected × to be reversible and got stuck. The new ▾ toggle
  //   leaves the header bar visible — title + count + freshness still show
  //   while collapsed — and clicking the header pill expands it again.
  //
  // The cleanup line below removes any legacy 'hdlv2_aq_dismissed' value so
  // practitioners who were stuck after dismissing pre-Phase-16 see the
  // banner expanded on their next page load.
  var COLLAPSE_KEY = 'hdlv2_aq_collapsed';
  try { window.localStorage.removeItem('hdlv2_aq_dismissed'); } catch (e) {}

  function ensureBannerStyles() {
    if (document.getElementById('hdlv2-aq-banner-styles')) return;
    var s = document.createElement('style');
    s.id = 'hdlv2-aq-banner-styles';
    s.textContent = ''
      // v0.21.0 — banner now sits inside .dashboard-container, so no auto
      // centering needed. Slightly tighter padding + matching radius so it
      // reads as "part of the dashboard", not a floating notification.
      + '.hdlv2-aq-banner{margin:0 0 20px;background:#fff;border:1px solid #e4e6ea;border-radius:14px;padding:18px 22px;box-shadow:0 1px 3px rgba(0,0,0,.04);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;transition:padding .15s ease;}'
      // v0.22.25 (Phase 16) — collapse state. Body hides, header stays
      // visible, padding tightens. Header becomes clickable so the whole
      // pill is a target (not just the ▾ glyph) — kinder on touch devices.
      + '.hdlv2-aq-banner.hdlv2-aq-collapsed{padding:12px 18px;}'
      + '.hdlv2-aq-banner.hdlv2-aq-collapsed .hdlv2-aq-body{display:none;}'
      + '.hdlv2-aq-banner.hdlv2-aq-collapsed .hdlv2-aq-header{margin-bottom:0;cursor:pointer;}'
      + '.hdlv2-aq-banner.hdlv2-aq-collapsed .hdlv2-aq-header:hover .hdlv2-aq-title{color:#1f2937;}'
      + '.hdlv2-aq-banner .hdlv2-aq-header{display:flex;align-items:center;gap:12px;margin-bottom:10px;}'
      + '.hdlv2-aq-banner .hdlv2-aq-title{font-size:15px;font-weight:600;margin:0;color:#111;flex:0 0 auto;transition:color .15s ease;}'
      // v0.22.25 — total count pill in the header so the practitioner
      // always knows there are pending items even when collapsed.
      + '.hdlv2-aq-banner .hdlv2-aq-header-count{display:inline-flex;align-items:center;gap:6px;background:#f3f4f6;border:1px solid #e4e6ea;border-radius:999px;padding:2px 10px;font-size:12px;font-weight:600;color:#374151;line-height:1.5;}'
      + '.hdlv2-aq-banner .hdlv2-aq-header-count[hidden]{display:none;}'
      + '.hdlv2-aq-banner .hdlv2-aq-freshness{font-size:12px;color:#888;margin-left:auto;}'
      // v0.22.25 — toggle replaces the old × close. ▾ when expanded, ▸
      // when collapsed. Tooltip flips to match. aria-expanded reflects
      // state for assistive tech.
      + '.hdlv2-aq-banner .hdlv2-aq-toggle{background:transparent;border:0;color:#888;font-size:18px;line-height:1;cursor:pointer;padding:4px 8px;margin-left:0;border-radius:6px;transition:background .15s ease,color .15s ease;}'
      + '.hdlv2-aq-banner .hdlv2-aq-toggle:hover{color:#111;background:#f3f4f6;}'
      + '.hdlv2-aq-banner .hdlv2-aq-group{border-radius:8px;padding:10px 12px;margin-bottom:8px;}'
      + '.hdlv2-aq-banner .hdlv2-aq-group-release{background:#fff8ee;border:1px solid #f5d28a;}'
      + '.hdlv2-aq-banner .hdlv2-aq-group-consult{background:#eef6fa;border:1px solid #b8d9e6;}'
      + '.hdlv2-aq-banner .hdlv2-aq-group-attn{background:#fdecec;border:1px solid #e8b4b4;}'
      + '.hdlv2-aq-banner .hdlv2-aq-group-head{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:#444;margin-bottom:6px;}'
      // v0.21.0 — themed status dots replacing emoji semaphores.
      + '.hdlv2-aq-banner .hdlv2-aq-dot{display:inline-block;width:10px;height:10px;border-radius:50%;flex:0 0 auto;box-shadow:0 0 0 2px rgba(255,255,255,.5) inset;}'
      + '.hdlv2-aq-banner .hdlv2-aq-count{margin-left:auto;background:#fff;border:1px solid rgba(0,0,0,.1);border-radius:10px;padding:1px 8px;font-size:11px;font-weight:600;color:#555;}'
      + '.hdlv2-aq-banner .hdlv2-aq-list{list-style:none;padding:0;margin:0;}'
      + '.hdlv2-aq-banner .hdlv2-aq-item{display:flex;align-items:center;gap:12px;padding:6px 0;font-size:13px;}'
      + '.hdlv2-aq-banner .hdlv2-aq-item-name{font-weight:600;color:#111;min-width:160px;}'
      + '.hdlv2-aq-banner .hdlv2-aq-item-meta{color:#555;flex:1;}'
      + '.hdlv2-aq-banner .hdlv2-aq-item-action{margin-left:auto;}'
      // v0.20.1 — use a CSS custom property for the tint so hover can set
      // background:var(--hdl-btn-c); color:#fff without the classic
      // currentColor self-reference bug (background:currentColor re-reads the
      // new hover color:#fff → white-on-white, unreadable).
      + '.hdlv2-aq-banner .hdlv2-aq-btn{--hdl-btn-c:#3d8da0;display:inline-block;padding:6px 14px;border-radius:999px;border:1px solid var(--hdl-btn-c);background:#fff;color:var(--hdl-btn-c);font-size:12px;font-weight:600;text-decoration:none;cursor:pointer;transition:background .15s ease,color .15s ease;}'
      + '.hdlv2-aq-banner .hdlv2-aq-btn-release{--hdl-btn-c:#b87500;}'
      + '.hdlv2-aq-banner .hdlv2-aq-btn-attn{--hdl-btn-c:#b91c1c;}'
      + '.hdlv2-aq-banner .hdlv2-aq-btn:hover{background:var(--hdl-btn-c);color:#fff;}'
      + '.hdlv2-aq-banner .hdlv2-aq-btn:hover *{color:#fff;}'
      + '.hdlv2-aq-banner .hdlv2-aq-empty{font-size:13px;color:#888;padding:6px 0;}'
      + '@media (max-width:640px){.hdlv2-aq-banner{margin:12px;padding:14px;}.hdlv2-aq-banner .hdlv2-aq-item{flex-wrap:wrap;}.hdlv2-aq-banner .hdlv2-aq-item-name{min-width:0;}.hdlv2-aq-banner .hdlv2-aq-item-action{margin-left:0;margin-top:6px;width:100%;}}';
    document.head.appendChild(s);
  }

  function mountActionQueueShell() {
    if (state.actionQueueEl) return;
    if (!state.table) return;
    ensureBannerStyles();
    var wrap = document.createElement('div');
    wrap.className = 'hdlv2-action-queue-wrap hdlv2-aq-banner';
    wrap.setAttribute('role', 'region');
    wrap.setAttribute('aria-label', 'Client activity notifications');
    wrap.innerHTML = ''
      + '<div class="hdlv2-aq-header" data-role="header">'
      +   '<h3 class="hdlv2-aq-title">What needs you today</h3>'
      +   '<span class="hdlv2-aq-header-count" data-role="header-count" hidden></span>'
      +   '<a class="hdlv2-aq-alllink" href="' + esc(CFG.consultation_url || '#') + '">View all consultations &rarr;</a>'
      +   '<div class="hdlv2-aq-freshness" data-role="freshness">Updated just now</div>'
      +   '<button type="button" class="hdlv2-aq-toggle" data-role="toggle" aria-expanded="true" aria-controls="hdlv2-aq-body" title="Collapse">&#9662;</button>'
      + '</div>'
      + '<div class="hdlv2-aq-body" data-role="body" id="hdlv2-aq-body"></div>';
    // v0.21.0 — insert directly inside the dashboard container, right above
    // the clients table. Previous hoist-to-Divi-ancestor left an awkward gap
    // between the banner and the "Client Management" heading it's driving.
    var dash  = state.table.closest('.dashboard-container');
    var tblWrap = state.table.closest('.clients-table-container') || state.table;
    if (dash && tblWrap.parentNode === dash) {
      dash.insertBefore(wrap, tblWrap);
    } else {
      // Defensive fallback — if Divi wrapped the table elsewhere, drop the
      // banner directly above the table.
      tblWrap.parentNode.insertBefore(wrap, tblWrap);
    }
    state.actionQueueEl = wrap;
    state.freshnessEl   = wrap.querySelector('[data-role="freshness"]');
    // v0.22.25 (Phase 16) — wire the collapse toggle.
    //
    // Two click targets:
    //   1. The ▾/▸ toggle button itself — always toggles.
    //   2. The whole header bar — only acts as a target while collapsed,
    //      so the practitioner can click the pill to expand without
    //      having to hit the small glyph. We stop propagation on the
    //      toggle click so it doesn't bubble up and double-fire.
    wrap.querySelector('[data-role="toggle"]').addEventListener('click', function (e) {
      e.stopPropagation();
      toggleActionQueue();
    });
    wrap.querySelector('[data-role="header"]').addEventListener('click', function () {
      if (state.actionQueueEl.classList.contains('hdlv2-aq-collapsed')) {
        toggleActionQueue();
      }
    });
  }

  // ──────────────────────────────────────────────────────────────────────
  //  Iridology add-on (IrisMapper) — purchase + launch. v1.
  //
  //  Rule-0: every entry self-guards on CFG.iris_feature_enabled, so with the
  //  flag off there is ZERO trace — no card, no tab, no styles, no network.
  //  Entitlement (CFG.iris_entitled) is resolved server-side and passed via the
  //  localize; the browser never sees the shared secret. All three IrisMapper
  //  calls go through our REST routes (backend-to-backend).
  //
  //  Post-checkout return: create-checkout-simple appends ?email=…&session_id=…
  //  to our query-string-free dashboard successUrl with a literal "?", so we
  //  detect the return by the session_id param (NOT a ?iris=success flag) and
  //  poll /iris/status until the add-on is provisioned. A plain cancel returns
  //  to the bare dashboard URL → reads as a normal load → upsell stays.
  // ──────────────────────────────────────────────────────────────────────

  var _irisMounted = false;
  var _irisStyled  = false;
  var _irisPolling = false;

  function irisReturning() {
    try { return new URLSearchParams(window.location.search).has('session_id'); }
    catch (e) { return false; }
  }

  // Strip the IrisMapper-appended ?email=&session_id= from the address bar so
  // the practitioner's own email doesn't linger in browser history.
  function irisCleanUrl() {
    try {
      if (window.history && window.history.replaceState) {
        window.history.replaceState({}, document.title, window.location.pathname);
      }
    } catch (e) {}
  }

  // Edge-A double-sub guard: practitioner already pays IrisMapper directly
  // (Practitioner subscription, not the add-on). Offer "already subscribed",
  // never a second add-on checkout.
  function irisAlreadySubscribed() {
    var tier = CFG.iris_subscription_tier;
    var st   = CFG.iris_subscription_status;
    return tier === 'practitioner' && (st === 'active' || st === 'trialing');
  }

  // Real IrisMapper brand logo (bundled PNG; never a drawn approximation).
  function irisLogo(extra) {
    var src = CFG.iris_logo_url || '';
    if (!src) return '';
    return '<img class="hdlv2-iris-logo' + (extra ? ' ' + extra : '') + '" src="' + esc(src) + '" alt="IrisMapper" decoding="async" />';
  }

  // ── Generic (non-brand) motif icons — sourced from itshover.com
  //    (github.com/itshover/itshover), inlined as static SVG with a small CSS
  //    hover animation (see ensureIrisStyles) to keep the motion feel. ──
  function iconSparkles() {
    return '<svg class="hdlv2-ico hdlv2-ico-sparkles" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
      + '<path class="spark spark-b" d="M16 18a2 2 0 0 1 2 2a2 2 0 0 1 2 -2a2 2 0 0 1 -2 -2a2 2 0 0 1 -2 2z"/>'
      + '<path class="spark spark-t" d="M16 6a2 2 0 0 1 2 2a2 2 0 0 1 2 -2a2 2 0 0 1 -2 -2a2 2 0 0 1 -2 2z"/>'
      + '<path class="spark spark-m" d="M9 18a6 6 0 0 1 6 -6a6 6 0 0 1 -6 -6a6 6 0 0 1 -6 6a6 6 0 0 1 6 6z"/></svg>';
  }
  function iconEye() {
    return '<svg class="hdlv2-ico hdlv2-ico-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
      + '<path class="eye-shape" d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6"/>'
      + '<path class="eye-pupil" d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0"/></svg>';
  }
  function iconRosette() {
    return '<svg class="hdlv2-ico hdlv2-ico-rosette" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
      + '<path class="rosette-badge" d="M5 7.2a2.2 2.2 0 0 1 2.2 -2.2h1a2.2 2.2 0 0 0 1.55 -.64l.7 -.7a2.2 2.2 0 0 1 3.12 0l.7 .7c.412 .41 .97 .64 1.55 .64h1a2.2 2.2 0 0 1 2.2 2.2v1c0 .58 .23 1.138 .64 1.55l.7 .7a2.2 2.2 0 0 1 0 3.12l-.7 .7a2.2 2.2 0 0 0 -.64 1.55v1a2.2 2.2 0 0 1 -2.2 2.2h-1a2.2 2.2 0 0 0 -1.55 .64l-.7 .7a2.2 2.2 0 0 1 -3.12 0l-.7 -.7a2.2 2.2 0 0 0 -1.55 -.64h-1a2.2 2.2 0 0 1 -2.2 -2.2v-1a2.2 2.2 0 0 0 -.64 -1.55l-.7 -.7a2.2 2.2 0 0 1 0 -3.12l.7 -.7a2.2 2.2 0 0 0 .64 -1.55v-1z"/>'
      + '<path class="rosette-check" d="M9 12l2 2l4 -4"/></svg>';
  }
  function iconExternal() {
    return '<svg class="hdlv2-ico hdlv2-ico-external" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
      + '<path class="ext-box" d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6"/>'
      + '<g class="ext-arrow"><path d="M11 13l9 -9"/><path d="M15 4h5v5"/></g></svg>';
  }
  function iconGear() {
    return '<svg class="hdlv2-ico hdlv2-ico-gear" viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="square" stroke-miterlimit="10" aria-hidden="true" focusable="false">'
      + '<g class="gear-rot"><circle cx="16" cy="16" r="5"/>'
      + '<path d="m30,17.5v-3l-3.388-1.355c-.25-.933-.617-1.815-1.089-2.633l1.436-3.351-2.121-2.121-3.351,1.436c-.817-.472-1.7-.838-2.633-1.089l-1.355-3.388h-3l-1.355,3.388c-.933.25-1.815.617-2.633,1.089l-3.351-1.436-2.121,2.121 1.436,3.351c-.472.817-.838,1.7-1.089,2.633l-3.388,1.355v3l3.388,1.355c.25.933.617,1.815,1.089,2.633l-1.436,3.351 2.121,2.121 3.351-1.436c.817.472 1.7.838 2.633,1.089l1.355,3.388h3l1.355-3.388c.933-.25 1.815-.617 2.633-1.089l3.351,1.436 2.121-2.121-1.436-3.351c.472-.817.838-1.7 1.089-2.633l3.388-1.355Z"/></g></svg>';
  }

  function ensureIrisStyles() {
    if (_irisStyled) return; _irisStyled = true;
    var css = ''
      // ── Add-on card (above the roster) — teal practice surface (mockup .pd-iris-front) ──
      + '.hdlv2-iris-card{display:flex;align-items:center;gap:22px;flex-wrap:wrap;margin:0 0 16px;padding:20px 26px;border:1px solid #e6f3f5;border-radius:14px;background:linear-gradient(120deg,#f0fbfc 0%,#fff 62%);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}'
      + '.hdlv2-iris-card.hdlv2-iris-ready{background:linear-gradient(120deg,#ecfdf5 0%,#fff 62%);border-color:#a7f3d0;}'
      // Real IrisMapper brand MARK — eye-spiral symbol only (square, transparent).
      + '.hdlv2-iris-logo{flex:0 0 auto;width:52px;height:52px;display:block;object-fit:contain;}'
      + '.hdlv2-iris-logo.launch{width:48px;height:48px;}'
      + '.hdlv2-iris-main{flex:1 1 320px;min-width:0;}'
      + '.hdlv2-iris-eyebrow{display:inline-flex;align-items:center;gap:7px;margin:0 0 5px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#3d8da0;}'
      + '.hdlv2-iris-eyebrow--active{color:#047857;}'
      + '.hdlv2-iris-title{font-family:Poppins,Inter,sans-serif;font-size:18px;font-weight:600;color:#111;margin:0 0 5px;line-height:1.3;letter-spacing:-.005em;}'
      + '.hdlv2-iris-pitch{margin:0;font-size:13px;color:#555;line-height:1.55;max-width:560px;}'
      + '.hdlv2-iris-pitch strong{color:#004F59;font-weight:600;}'
      + '.hdlv2-iris-meta{margin:9px 0 0;font-size:12px;color:#888;}'
      + '.hdlv2-iris-meta code{background:#fff;border:1px solid #e4e6ea;padding:1px 6px;border-radius:5px;color:#004F59;font-size:11.5px;}'
      + '.hdlv2-iris-buy{flex:0 0 auto;display:flex;flex-direction:column;align-items:flex-end;gap:8px;text-align:right;}'
      + '.hdlv2-iris-price{font-family:Poppins,Inter,sans-serif;font-size:26px;font-weight:600;color:#111;margin:0;line-height:1;}'
      + '.hdlv2-iris-price span{font-family:Inter,sans-serif;font-size:13px;color:#888;font-weight:400;}'
      + '.hdlv2-iris-fine{margin:0;font-size:11px;color:#888;}'
      + '.hdlv2-iris-btn{display:inline-flex;align-items:center;gap:8px;border:none;border-radius:8px;padding:11px 22px;font-family:Poppins,Inter,sans-serif;font-size:14px;font-weight:600;cursor:pointer;transition:background .15s,opacity .15s;}'
      + '.hdlv2-iris-btn:disabled{opacity:.6;cursor:default;}'
      + '.hdlv2-iris-btn--primary{background:#3d8da0;color:#fff;}'
      + '.hdlv2-iris-btn--primary:hover{background:#357887;}'
      + '.hdlv2-iris-btn svg{width:15px;height:15px;}'
      + '.hdlv2-iris-manage{display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#3d8da0;background:none;border:none;cursor:pointer;padding:0;font-family:Inter,sans-serif;}'
      + '.hdlv2-iris-manage:hover{text-decoration:underline;}'
      + '.hdlv2-iris-msg{font-size:12px;color:#dc2626;}'
      + '.hdlv2-iris-spin{display:inline-block;width:15px;height:15px;border:2px solid #cfeaf0;border-top-color:#3d8da0;border-radius:50%;margin-right:8px;vertical-align:-2px;animation:hdlv2IrisSpin .8s linear infinite;}'
      + '@keyframes hdlv2IrisSpin{to{transform:rotate(360deg)}}'
      // ── itshover-sourced motif icons (github.com/itshover/itshover) + CSS hover motion ──
      + '.hdlv2-ico{width:16px;height:16px;display:inline-block;vertical-align:-2px;overflow:visible;flex:0 0 auto;}'
      + '.hdlv2-ico path,.hdlv2-ico g,.hdlv2-ico circle{transform-box:fill-box;transform-origin:center;transition:transform .35s ease;}'
      + '.hdlv2-ico-sparkles{width:13px;height:13px;color:#3d8da0;}'
      + '.hdlv2-iris-card:hover .hdlv2-ico-sparkles .spark-m{transform:rotate(180deg);}'
      + '.hdlv2-iris-card:hover .hdlv2-ico-sparkles .spark-t{transform:scale(1.18);}'
      + '.hdlv2-iris-card:hover .hdlv2-ico-sparkles .spark-b{transform:scale(.82);}'
      + '.hdlv2-ico-rosette{width:16px;height:16px;color:#10b981;}'
      + '.hdlv2-iris-panel-eyebrow .hdlv2-ico-rosette{color:#5b48b8;}'
      + '.hdlv2-iris-card:hover .hdlv2-ico-rosette .rosette-badge,.hdlv2-iris-consult:hover .hdlv2-ico-rosette .rosette-badge{transform:scale(1.12) rotate(6deg);}'
      + '.hdlv2-ico-external .ext-arrow,.hdlv2-ico-external .ext-box{transition:transform .25s ease;}'
      + '.hdlv2-iris-btn:hover .hdlv2-ico-external .ext-arrow,.hdlv2-iris-open:hover .hdlv2-ico-external .ext-arrow{transform:translate(2px,-2px) scale(1.1);}'
      + '.hdlv2-iris-btn:hover .hdlv2-ico-external .ext-box,.hdlv2-iris-open:hover .hdlv2-ico-external .ext-box{transform:scale(.92);}'
      + '.hdlv2-ico-gear{width:14px;height:14px;}'
      + '.hdlv2-ico-gear .gear-rot{transition:transform .7s ease;}'
      + '.hdlv2-iris-manage:hover .hdlv2-ico-gear .gear-rot{transform:rotate(180deg);}'
      + '.hdlv2-ico-eye{width:100%;height:100%;color:#5b48b8;}'
      + '.hdlv2-iris-launch:hover .hdlv2-ico-eye .eye-pupil{transform:scale(.7);}'
      + '.hdlv2-iris-launch:hover .hdlv2-ico-eye .eye-shape{transform:scaleY(.88);}'
      // ── Consultation-tab violet launch panel (mockup .pd-iris-panel / .pd-iris-launch) ──
      + '.hdlv2-iris-consult{margin:0 0 20px;padding:0 0 18px;border-bottom:1px solid #ece9f8;}'
      + '.hdlv2-iris-panel-eyebrow{display:inline-flex;align-items:center;gap:7px;font-size:11px;color:#5b48b8;font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin:0 0 6px;}'
      + '.hdlv2-iris-panel-title{font-family:Poppins,Inter,sans-serif;font-size:18px;font-weight:600;color:#111;margin:0 0 6px;letter-spacing:-.005em;}'
      + '.hdlv2-iris-panel-headline{font-size:13px;color:#555;line-height:1.55;margin:0 0 18px;max-width:580px;}'
      + '.hdlv2-iris-launch{display:flex;align-items:center;gap:18px;flex-wrap:wrap;background:#fff;border:1px solid #d9d2f5;border-radius:10px;padding:20px 22px;}'
      + '.hdlv2-iris-launch-logo{flex:0 0 auto;display:flex;align-items:center;}'
      + '.hdlv2-iris-launch-body{flex:1 1 240px;min-width:0;}'
      + '.hdlv2-iris-launch-body h4{margin:0 0 4px;font-family:Inter,sans-serif;font-size:14px;font-weight:600;color:#111;}'
      + '.hdlv2-iris-launch-body p{margin:0;font-size:13px;color:#555;line-height:1.55;}'
      + '.hdlv2-iris-eyes{display:flex;gap:24px;margin:14px 0 0;}'
      + '.hdlv2-iris-eyes .col{text-align:center;font-size:10px;color:#888;text-transform:uppercase;letter-spacing:.07em;font-weight:700;}'
      + '.hdlv2-iris-eyes .eye{display:block;width:42px;height:42px;margin:0 auto 6px;}'
      + '.hdlv2-iris-open{flex:0 0 auto;display:inline-flex;align-items:center;gap:8px;background:#2a1d63;color:#fff;border:none;border-radius:8px;font-family:Inter,sans-serif;font-size:13px;font-weight:600;padding:11px 18px;cursor:pointer;transition:background .15s,opacity .15s;}'
      + '.hdlv2-iris-open:hover{background:#160f33;}'
      + '.hdlv2-iris-open:disabled{opacity:.6;cursor:default;}'
      + '.hdlv2-iris-open svg{width:15px;height:15px;}'
      // ── Toast ──
      + '.hdlv2-iris-toast{position:fixed;left:50%;bottom:28px;transform:translateX(-50%) translateY(12px);max-width:460px;background:#2c3e50;color:#fff;font-family:Inter,sans-serif;font-size:13px;line-height:1.45;padding:13px 18px;border-radius:10px;box-shadow:0 8px 30px rgba(0,0,0,.22);opacity:0;transition:opacity .3s,transform .3s;z-index:99999;}'
      + '.hdlv2-iris-toast.show{opacity:1;transform:translateX(-50%) translateY(0);}'
      // ── Responsive: stack the price/buy block under the copy on narrow widths ──
      + '@media(max-width:640px){.hdlv2-iris-card{align-items:flex-start;}.hdlv2-iris-buy{align-items:stretch;text-align:left;width:100%;}.hdlv2-iris-buy .hdlv2-iris-btn{justify-content:center;}}';
    var s = document.createElement('style');
    s.id = 'hdlv2-iris-styles';
    s.textContent = css;
    document.head.appendChild(s);
  }

  function mountIrisCard() {
    if (!CFG.iris_feature_enabled) return;   // Rule-0 — flag off ⇒ no trace
    if (_irisMounted) return;
    if (!state.table) return;
    ensureIrisStyles();
    var wrap = document.createElement('div');
    wrap.className = 'hdlv2-iris-card';
    wrap.setAttribute('role', 'region');
    wrap.setAttribute('aria-label', 'Iris Analysis add-on');
    // Mount above the table (below the action queue), same anchor strategy as
    // mountActionQueueShell. Because both insertBefore the table wrapper and the
    // action queue mounts first, this card lands between it and the roster.
    var dash    = state.table.closest('.dashboard-container');
    var tblWrap = state.table.closest('.clients-table-container') || state.table;
    if (dash && tblWrap.parentNode === dash) {
      dash.insertBefore(wrap, tblWrap);
    } else {
      tblWrap.parentNode.insertBefore(wrap, tblWrap);
    }
    _irisMounted = true;
    state.irisCardEl = wrap;
    renderIrisCard();
    // Returning from a completed checkout → "setting up…" + poll immediately.
    if (irisReturning()) {
      irisCleanUrl();
      renderIrisSettingUp(wrap);
      irisPollStatus();
    }
  }

  function renderIrisCard() {
    var wrap = state.irisCardEl;
    if (!wrap) return;
    if (CFG.iris_entitled)        { renderIrisReady(wrap, false); return; }
    if (irisAlreadySubscribed())  { renderIrisReady(wrap, true);  return; }
    renderIrisUpsell(wrap);
  }

  function renderIrisUpsell(wrap) {
    wrap.className = 'hdlv2-iris-card hdlv2-iris-upsell';
    wrap.innerHTML = ''
      + irisLogo()
      + '<div class="hdlv2-iris-main">'
      +   '<p class="hdlv2-iris-eyebrow">' + iconSparkles() + 'New add-on</p>'
      +   '<h3 class="hdlv2-iris-title">Add Iris Analysis to your practice</h3>'
      +   '<p class="hdlv2-iris-pitch">Capture a client’s iris photo and get an AI-assisted iridology report in minutes — powered by <strong>IrisMapper</strong>. We set up your full account automatically; it then appears on every client’s record below.</p>'
      + '</div>'
      + '<div class="hdlv2-iris-buy">'
      +   '<p class="hdlv2-iris-price">£29<span>/mo</span></p>'
      +   '<button type="button" class="hdlv2-iris-btn hdlv2-iris-btn--primary" data-role="add">Add it</button>'
      +   '<p class="hdlv2-iris-fine">Cancel anytime</p>'
      +   '<span class="hdlv2-iris-msg" data-role="msg" hidden></span>'
      + '</div>';
    wrap.querySelector('[data-role="add"]').addEventListener('click', irisCheckout);
  }

  function renderIrisReady(wrap, alreadySub) {
    wrap.className = 'hdlv2-iris-card hdlv2-iris-ready';
    var title = alreadySub ? 'You already have an IrisMapper subscription' : 'Iris Analysis is ready';
    var pitch = alreadySub
      ? 'Your IrisMapper subscription is active. Open it to capture iris photos and generate reports — or launch it from a client’s Consultation tab.'
      : 'Your IrisMapper account is set up. Open it to capture iris photos and generate reports — or launch it from a client’s Consultation tab.';
    var name = CFG.iris_practitioner_name || '';
    var host = CFG.iris_app_host || 'irismapper.com';
    var meta = '<p class="hdlv2-iris-meta">'
      + (name ? 'Signed in as <strong>' + esc(name) + '</strong> &middot; ' : 'Opens at ')
      + '<code>' + esc(host) + '/app</code></p>';
    wrap.innerHTML = ''
      + irisLogo()
      + '<div class="hdlv2-iris-main">'
      +   '<p class="hdlv2-iris-eyebrow hdlv2-iris-eyebrow--active">' + iconRosette() + 'Iris Analysis active</p>'
      +   '<h3 class="hdlv2-iris-title">' + esc(title) + '</h3>'
      +   '<p class="hdlv2-iris-pitch">' + pitch + '</p>'
      +   meta
      + '</div>'
      + '<div class="hdlv2-iris-buy">'
      +   '<button type="button" class="hdlv2-iris-btn hdlv2-iris-btn--primary" data-role="open">' + iconExternal() + '<span>Open Iris Analysis</span></button>'
      +   '<button type="button" class="hdlv2-iris-manage" data-role="manage">' + iconGear() + '<span>Manage subscription</span></button>'
      +   '<span class="hdlv2-iris-msg" data-role="msg" hidden></span>'
      + '</div>';
    wrap.querySelector('[data-role="open"]').addEventListener('click', function () { irisOpenApp(this); });
    // Q6 — no billing-portal endpoint yet; interim "Manage subscription" =
    // auto-login into /app (the IrisMapper account/billing page).
    wrap.querySelector('[data-role="manage"]').addEventListener('click', function () { irisOpenApp(this); });
  }

  function renderIrisSettingUp(wrap, msg) {
    wrap.className = 'hdlv2-iris-card hdlv2-iris-setup';
    wrap.innerHTML = ''
      + irisLogo()
      + '<div class="hdlv2-iris-main">'
      +   '<p class="hdlv2-iris-eyebrow">Setting up</p>'
      +   '<h3 class="hdlv2-iris-title"><span class="hdlv2-iris-spin" aria-hidden="true"></span>Setting up your Iris Analysis…</h3>'
      +   '<p class="hdlv2-iris-pitch" data-role="msg">' + esc(msg || 'This usually takes a few seconds.') + '</p>'
      + '</div>';
  }

  function irisCheckout() {
    var wrap = state.irisCardEl;
    var btn  = wrap ? wrap.querySelector('[data-role="add"]') : null;
    var msg  = wrap ? wrap.querySelector('[data-role="msg"]') : null;
    if (msg) { msg.hidden = true; }
    if (btn) { btn.disabled = true; btn.textContent = 'Starting…'; }
    fetch(CFG.api_base + '/iris/checkout', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: '{}'
    }).then(function (r) { return r.ok ? r.json() : Promise.reject(); })
      .then(function (res) {
        // IrisMapper now answers { alreadySubscribed:true } (no checkout url)
        // when the practitioner already has a sub → show the ready/already-
        // subscribed state instead of a broken redirect to an absent url.
        if (res && res.alreadySubscribed) { renderIrisReady(wrap, true); return; }
        if (res && res.url) { window.location.href = res.url; return; }
        return Promise.reject();
      })
      .catch(function () {
        if (btn) { btn.disabled = false; btn.textContent = 'Add it'; }
        if (msg) { msg.hidden = false; msg.textContent = 'Couldn’t start checkout — please try again.'; }
      });
  }

  function irisPollStatus() {
    if (_irisPolling) return;
    _irisPolling = true;
    var tries = 0, MAX = 8;
    // Not entitled before this poll began ⇒ this is a fresh purchase, so the
    // buyer may be brand-new on IrisMapper → surface the "set your password"
    // nudge once we see them provisioned.
    var freshBuyer = !CFG.iris_entitled;
    var timer = setInterval(function () {
      tries++;
      fetch(CFG.api_base + '/iris/status?fresh=1', {
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': CFG.nonce },
        cache: 'no-store'
      }).then(function (r) { return r.ok ? r.json() : null; })
        .then(function (res) {
          if (res && res.entitled) {
            clearInterval(timer); _irisPolling = false;
            CFG.iris_entitled = true;   // newly-opened detail panels now get the Iris tab
            if (state.irisCardEl) renderIrisReady(state.irisCardEl, false);
            irisToast(freshBuyer
              ? 'Your IrisMapper account is ready. Check your email to set your password — you can open Iris Analysis right now too.'
              : 'Your IrisMapper account is ready.');
            return;
          }
          if (tries >= MAX) {
            clearInterval(timer); _irisPolling = false;
            var wrap = state.irisCardEl;
            var m = wrap ? wrap.querySelector('[data-role="msg"]') : null;
            if (m) { m.textContent = 'Still setting up — refresh in a minute to open Iris Analysis.'; }
          }
        })
        .catch(function () {
          if (tries >= MAX) { clearInterval(timer); _irisPolling = false; }
        });
    }, 2500);
  }

  function irisOpenApp(btn) {
    // Open the tab synchronously (inside the user gesture) so the auto-login
    // POST that follows can't trip the popup blocker; we point it at the
    // single-use loginUrl once the POST resolves. (noopener can't be used here
    // because it makes window.open() return null, defeating the handle — the
    // loginUrl is our own trusted IrisMapper origin.)
    var tab = window.open('about:blank', '_blank');
    if (btn) { btn.disabled = true; }
    fetch(CFG.api_base + '/iris/login', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: '{}'
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (out) {
        if (btn) { btn.disabled = false; }
        if (out.ok && out.j && out.j.loginUrl) {
          if (tab) { tab.location = out.j.loginUrl; } else { window.open(out.j.loginUrl, '_blank'); }
          return;
        }
        if (tab) { tab.close(); }
        irisToast((out.j && out.j.message) ? out.j.message : 'Couldn’t open Iris Analysis — please try again.');
      })
      .catch(function () {
        if (btn) { btn.disabled = false; }
        if (tab) { tab.close(); }
        irisToast('Couldn’t open Iris Analysis — please try again.');
      });
  }

  // Iris launch section for the Consultation tab. Returns '' when the feature
  // is off OR the practitioner is not entitled (Rule-0 — no iris trace). v1:
  // opens the PRACTITIONER's IrisMapper workspace signed-in (no client context
  // passed yet — embedded per-client capture is Phase 2). `c` is accepted for
  // future use but deliberately unused for the launch call.
  function irisConsultationHtml(c) {
    if (!CFG.iris_feature_enabled || !CFG.iris_entitled) return ''; // Rule-0
    // Phase 2 (embedded consult): when the second flag is on and the module is
    // loaded, hand the iris section to it (upload/analyse/poll/result). Falls
    // through to the v1 launch button when the Phase-2 flag is off.
    if (CFG.iris_consult_enabled && window.HDLV2IrisConsult) {
      return window.HDLV2IrisConsult.placeholderHtml(c);
    }
    return ''
      + '<section class="hdlv2-iris-consult">'
      +   '<p class="hdlv2-iris-panel-eyebrow">' + iconRosette() + 'Iris Analysis &middot; IrisMapper</p>'
      +   '<h3 class="hdlv2-iris-panel-title">Iridology for this client</h3>'
      +   '<p class="hdlv2-iris-panel-headline">Capture the client’s left and right iris photos and generate an AI-assisted iridology report. Opens in IrisMapper — your account is already linked, so there’s nothing to set up.</p>'
      +   '<div class="hdlv2-iris-launch">'
      +     '<span class="hdlv2-iris-launch-logo">' + irisLogo('launch') + '</span>'
      +     '<div class="hdlv2-iris-launch-body">'
      +       '<h4>Open this client in IrisMapper</h4>'
      +       '<p>You’ll be signed straight in. Capture or upload iris photos, then the report saves to your IrisMapper library.</p>'
      +       '<div class="hdlv2-iris-eyes">'
      +         '<div class="col"><span class="eye">' + iconEye() + '</span>Left</div>'
      +         '<div class="col"><span class="eye">' + iconEye() + '</span>Right</div>'
      +       '</div>'
      +     '</div>'
      +     '<button type="button" class="hdlv2-iris-open" data-role="iris-open">' + iconExternal() + '<span>Open Iris Analysis</span></button>'
      +   '</div>'
      + '</section>';
  }

  // Bind the Consultation-tab iris launch button (if the section is present).
  function bindIrisConsultOpen(target) {
    // Phase 2: hand off to the embedded consult module (reads client/progress
    // from the placeholder's data-* attributes; no-op if the flag is off).
    if (CFG.iris_consult_enabled && window.HDLV2IrisConsult) {
      window.HDLV2IrisConsult.mount(target);
    }
    var b = target.querySelector('[data-role="iris-open"]');
    if (b) { b.addEventListener('click', function () { irisOpenApp(b); }); }
  }

  function irisToast(msg) {
    ensureIrisStyles();
    var t = document.createElement('div');
    t.className = 'hdlv2-iris-toast';
    t.setAttribute('role', 'status');
    t.textContent = msg;
    document.body.appendChild(t);
    void t.offsetWidth; // force reflow so the transition runs
    t.classList.add('show');
    setTimeout(function () {
      t.classList.remove('show');
      setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 400);
    }, 6000);
  }

  function todayLocal() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  function getCollapseState() {
    try {
      var raw = window.localStorage.getItem(COLLAPSE_KEY);
      if (!raw) return null;
      var parsed = JSON.parse(raw);
      return parsed && parsed.date ? parsed : null;
    } catch (e) { return null; }
  }

  function setCollapseState() {
    try {
      window.localStorage.setItem(COLLAPSE_KEY, JSON.stringify({ date: todayLocal() }));
    } catch (e) {}
  }

  function clearCollapseState() {
    try { window.localStorage.removeItem(COLLAPSE_KEY); } catch (e) {}
  }

  // Sync the toggle button glyph + ARIA + tooltip to a known state.
  // ▾ = expanded (click to collapse) · ▸ = collapsed (click to expand)
  function setToggleUI(isCollapsed) {
    if (!state.actionQueueEl) return;
    state.actionQueueEl.classList.toggle('hdlv2-aq-collapsed', isCollapsed);
    var btn = state.actionQueueEl.querySelector('[data-role="toggle"]');
    if (!btn) return;
    btn.innerHTML = isCollapsed ? '&#9656;' : '&#9662;';
    btn.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
    btn.setAttribute('title', isCollapsed ? 'Expand' : 'Collapse');
  }

  function toggleActionQueue() {
    if (!state.actionQueueEl) return;
    var isNowCollapsed = !state.actionQueueEl.classList.contains('hdlv2-aq-collapsed');
    setToggleUI(isNowCollapsed);
    if (isNowCollapsed) {
      // 0.47.49 — persist the collapse for the rest of the day. It stays closed
      // across polls + reloads regardless of queue activity. (The old content-hash
      // logic re-expanded the banner on ANY queued-client timestamp bump / new
      // lead — reported as annoying "it won't stay closed".) The header count pill
      // keeps showing the live total while collapsed, so new work is still
      // surfaced without forcing the banner open. Resets to expanded at midnight.
      setCollapseState();
    } else {
      clearCollapseState();
    }
  }

  function applyCollapseState() {
    if (!state.actionQueueEl) return;
    var collapsed = getCollapseState();
    if (!collapsed) { setToggleUI(false); return; }
    // New day → fresh start (expand + clear). Otherwise the practitioner's
    // collapse is honoured for the whole day, regardless of queue changes —
    // the banner does not auto-re-expand on activity anymore.
    if (collapsed.date !== todayLocal()) { clearCollapseState(); setToggleUI(false); return; }
    setToggleUI(true);
  }

  function renderActionQueue(list) {
    if (!state.actionQueueEl) return;

    // v0.21.0 — replace emoji semaphores with themed dots. Dot colors match
    // the V1 status-pill tokens + the status-color palette in PHP (amber for
    // release, blue for consult, red for attention). No decorative glyphs.
    //
    // v0.35.1 — fourth group: new widget submissions awaiting Confirm/Reject.
    // Teal dot to match the brand primary (these are NEW prospects, not
    // existing clients in trouble — distinct visual register).
    var groups = {
      // v0.36.17 — separator changed from " · " to ": " to match the
      // user's literal "Stage1:" feedback. Stage prefix on every per-stage
      // section so the practitioner can scan the queue by where each
      // client is in the journey. "Needs attention" stays unprefixed
      // because it can fire at any stage.
      pending_leads:    { dot: '#3d8da0', label: 'Stage 1: New widget submissions',       items: [], action: 'lead_action',  cls: 'pending' },
      release_why:      { dot: '#d97706', label: 'Stage 2: Ready to invite to Stage 3',   items: [], action: 'release',      cls: 'release' },
      awaiting_consult: { dot: '#3b82f6', label: 'Stage 3: Ready to record consultation', items: [], action: 'open_consult', cls: 'consult' },
      needs_attention:  { dot: '#dc2626', label: 'Needs attention',                              items: [], action: 'view_timeline', cls: 'attn' },
    };

    list.forEach(function (c) {
      if (c.status === 'awaiting_why_release') groups.release_why.items.push(c);
      else if (c.status === 'awaiting_consult') groups.awaiting_consult.items.push(c);
      else if (c.status === 'needs_attention')   groups.needs_attention.items.push(c);
    });
    // Pending leads come from a separate state slice (different REST
    // endpoint), so they slot in here rather than in the .forEach above.
    (state.pendingLeads || []).forEach(function (lead) {
      groups.pending_leads.items.push(lead);
    });

    var body = state.actionQueueEl.querySelector('[data-role="body"]');
    var total = groups.pending_leads.items.length + groups.release_why.items.length + groups.awaiting_consult.items.length + groups.needs_attention.items.length;

    // v0.22.25 — header count pill stays in sync with the body. Always
    // visible while collapsed so the practitioner sees "(5)" at a glance.
    var headerCountEl = state.actionQueueEl.querySelector('[data-role="header-count"]');
    if (headerCountEl) {
      if (total > 0) {
        headerCountEl.textContent = String(total);
        headerCountEl.hidden = false;
      } else {
        headerCountEl.hidden = true;
      }
    }

    if (total === 0) {
      body.innerHTML = '<div class="hdlv2-aq-empty">You\'re all caught up. No clients need your action right now.</div>';
      state.actionQueueEl.classList.remove('hdlv2-aq-has-items');
      // No items to track — wipe any persisted collapse state and ensure
      // the banner returns to its default (expanded) shape so the empty
      // affirmation actually shows.
      setToggleUI(false);
      clearCollapseState();
      return;
    }

    state.actionQueueEl.classList.add('hdlv2-aq-has-items');
    applyCollapseState();

    var html = '';
    Object.keys(groups).forEach(function (key) {
      var g = groups[key];
      if (!g.items.length) return;

      // v0.35.1 — pending_leads has its own row renderer (Confirm + Reject
      // buttons inline) AND a cap so a backlog/spam wave doesn't overflow
      // the banner. The "+N more" footer pops Client Tools on the Pending
      // Leads tab via the existing #hdlv2-pending-leads hash deeplink.
      if (key === 'pending_leads') {
        // v0.36.17 — was capped at PENDING_LEADS_QUEUE_CAP visible rows + a
        // "+N more" pill linking to Client Tools. Practitioners reported the
        // pill destination was opaque ("don't want to click something I don't
        // know"). Show every pending lead inline instead — Confirm/Reject
        // each row drains the queue naturally, no extra navigation needed.
        // PENDING_LEADS_QUEUE_CAP retained at file-scope for any future
        // reintroduction but is no longer referenced.
        html += '<div class="hdlv2-aq-group hdlv2-aq-group-' + g.cls + '">'
          +   '<div class="hdlv2-aq-group-head"><span class="hdlv2-aq-dot" style="background:' + g.dot + '"></span><span class="hdlv2-aq-group-label">' + esc(g.label) + '</span><span class="hdlv2-aq-count">' + g.items.length + '</span></div>'
          +   '<ul class="hdlv2-aq-list">';
        g.items.forEach(function (lead) {
          html += renderPendingLeadAQRow(lead);
        });
        html += '</ul></div>';
        return;
      }

      html += '<div class="hdlv2-aq-group hdlv2-aq-group-' + g.cls + '">'
        +   '<div class="hdlv2-aq-group-head"><span class="hdlv2-aq-dot" style="background:' + g.dot + '"></span><span class="hdlv2-aq-group-label">' + esc(g.label) + '</span><span class="hdlv2-aq-count">' + g.items.length + '</span></div>'
        +   '<ul class="hdlv2-aq-list">';
      g.items.forEach(function (c) {
        var btn = renderActionQueueButton(g.action, c);
        html += '<li class="hdlv2-aq-item" data-user-id="' + esc(c.user_id) + '">'
          +   '<div class="hdlv2-aq-item-name">' + esc(c.name || c.email || 'Client') + '</div>'
          +   '<div class="hdlv2-aq-item-meta">' + buildActionMeta(g.action, c) + '</div>'
          +   '<div class="hdlv2-aq-item-action">' + btn + '</div>'
          + '</li>';
      });
      html += '</ul></div>';
    });
    body.innerHTML = html;
    bindActionQueueButtons();
    bindPendingLeadQueueActions();
  }

  // v0.35.1 — Compact row renderer for pending widget submissions inside the
  // "What needs you today" action queue. Inline Confirm/Reject buttons fire
  // the same /widget/leads/{action} REST endpoints the modal Pending Leads
  // tab uses. Single line: name + tiny meta + buttons. Stays inside the
  // existing .hdlv2-aq-list grid so it inherits the spacing of the other
  // action-queue rows.
  function renderPendingLeadAQRow(lead) {
    var rate = (lead.rate_of_ageing !== null && lead.rate_of_ageing !== undefined)
      ? Number(lead.rate_of_ageing).toFixed(2)
      : '—';
    var ageStr = lead.visitor_age ? ' &middot; age ' + lead.visitor_age : '';
    var submittedAt = '';
    if (lead.created_at) {
      var iso = String(lead.created_at).replace(' ', 'T');
      var d = new Date(iso);
      if (!isNaN(d.getTime())) {
        var diffMin = Math.round((Date.now() - d.getTime()) / 60000);
        if (diffMin < 1)        submittedAt = 'just now';
        else if (diffMin < 60)  submittedAt = diffMin + 'm ago';
        else if (diffMin < 1440) submittedAt = Math.round(diffMin / 60) + 'h ago';
        else submittedAt = d.toLocaleDateString();
      }
    }
    var name  = esc(lead.visitor_name || lead.visitor_email || 'New lead');
    var email = esc(lead.visitor_email || '');
    // 0.47.48 — a "View details" toggle reveals the whitelisted Stage-1 detail
    // (name/email/rate/age/sex + the 9 answers) INLINE in the strip, so the
    // practitioner can review a lead before Confirm/Reject. This replaced the
    // duplicate "Pending confirmation" group that F3 had added to the main list:
    // a pending lead now lives in exactly ONE place (this strip) until confirmed.
    var isOpen = !!_openPendingDetails[lead.id];
    return '<li class="hdlv2-aq-item hdlv2-aq-item-lead' + (isOpen ? ' hdlv2-aq-item-open' : '') + '" data-lead-id="' + esc(lead.id) + '">'
      +   '<div class="hdlv2-aq-item-name">' + name + '</div>'
      +   '<div class="hdlv2-aq-item-meta">' + email + ageStr + ' &middot; ' + rate + '&times;'
      +     (submittedAt ? ' &middot; ' + esc(submittedAt) : '')
      +   '</div>'
      +   '<div class="hdlv2-aq-item-action" style="display:flex;gap:5px;">'
      +     '<button type="button" class="hdlv2-aq-btn hdlv2-aq-btn-pending-details" data-aq="lead-details" data-lead-id="' + esc(lead.id) + '" aria-expanded="' + (isOpen ? 'true' : 'false') + '" title="Review this lead’s Stage-1 details">' + (isOpen ? 'Hide details' : 'View details') + '</button>'
      +     '<button type="button" class="hdlv2-aq-btn hdlv2-aq-btn-pending-confirm" data-aq="lead-confirm" data-lead-id="' + esc(lead.id) + '" title="Send the client their next-step magic link" style="padding:5px 11px;font-size:12px;border:1px solid #3d8da0;background:#3d8da0;color:#fff;border-radius:999px;cursor:pointer;font-weight:600;font-family:inherit;line-height:1.3;">Confirm</button>'
      +     '<button type="button" class="hdlv2-aq-btn hdlv2-aq-btn-pending-reject"  data-aq="lead-reject"  data-lead-id="' + esc(lead.id) + '" title="Silently drop without emailing" style="padding:5px 11px;font-size:12px;border:1px solid #e4e6ea;background:#fff;color:#666;border-radius:999px;cursor:pointer;font-weight:600;font-family:inherit;line-height:1.3;">Reject</button>'
      +   '</div>'
      +   '<div class="hdlv2-aq-lead-detail"' + (isOpen ? '' : ' hidden') + '>' + buildPendingLeadDetailHTML(lead) + '</div>'
      + '</li>';
  }

  // v0.35.1 — Bind Confirm/Reject + "+ N more" handlers inside the action
  // queue's pending-leads group. Reuses the same REST endpoints as the
  // modal tab so behaviour is identical regardless of where the action
  // is taken. Re-fetches both the client roster and the leads list on
  // success so the V1 list shows the new client immediately.
  function bindPendingLeadQueueActions() {
    if (!state.actionQueueEl) return;
    // 0.47.48 — View-details toggle: reveal/hide the inline Stage-1 detail panel.
    // Open state lives in _openPendingDetails so it survives the strip's per-poll
    // rebuild (renderActionQueue reads it when re-rendering each row).
    state.actionQueueEl.querySelectorAll('[data-aq="lead-details"]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var leadId = parseInt(btn.getAttribute('data-lead-id'), 10);
        if (!leadId) return;
        var li = btn.closest('.hdlv2-aq-item-lead');
        var panel = li && li.querySelector('.hdlv2-aq-lead-detail');
        if (!panel) return;
        var willOpen = panel.hasAttribute('hidden');
        if (willOpen) { panel.removeAttribute('hidden'); _openPendingDetails[leadId] = true; }
        else { panel.setAttribute('hidden', ''); delete _openPendingDetails[leadId]; }
        btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        btn.textContent = willOpen ? 'Hide details' : 'View details';
        if (li) li.classList.toggle('hdlv2-aq-item-open', willOpen);
      });
    });
    state.actionQueueEl.querySelectorAll('[data-aq="lead-confirm"], [data-aq="lead-reject"]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var action = btn.getAttribute('data-aq') === 'lead-confirm' ? 'confirm' : 'reject';
        var leadId = parseInt(btn.getAttribute('data-lead-id'), 10);
        if (!leadId || _queuePendingActioning[leadId]) return;
        if (action === 'reject') {
          var ask = (window.HDLV2UI && window.HDLV2UI.confirm)
            ? window.HDLV2UI.confirm({
                title: 'Reject this lead?',
                body: 'They will not receive any further emails. You can’t undo this without them resubmitting the widget.',
                confirmLabel: 'Yes, reject',
                cancelLabel: 'Cancel'
              })
            : Promise.resolve(window.confirm('Reject this lead? They will not receive any further emails.'));
          ask.then(function (ok) { if (ok) doQueuePendingAction(btn, action, leadId); });
        } else {
          doQueuePendingAction(btn, action, leadId);
        }
      });
    });
    // "+ N more" link → use the existing dashboard.js hash listener to
    // open Client Tools on the Pending Leads tab. Letting the anchor's
    // default href="#hdlv2-pending-leads" do its thing also works, but
    // the listener relies on a hashchange event firing — adding the
    // explicit click handler here makes it deterministic on browsers
    // that suppress hashchange when the hash is already set.
    state.actionQueueEl.querySelectorAll('[data-aq="open-pending-tab"]').forEach(function (a) {
      a.addEventListener('click', function () {
        // Force a hashchange even when the hash is already #hdlv2-pending-leads
        // (Safari and some Chromium versions skip hashchange in that case).
        if (window.location.hash === '#hdlv2-pending-leads') {
          window.location.hash = '';
          setTimeout(function () { window.location.hash = '#hdlv2-pending-leads'; }, 0);
        }
      });
    });
  }

  function doQueuePendingAction(btn, action, leadId) {
    _queuePendingActioning[leadId] = true;
    var row = btn.closest('.hdlv2-aq-item-lead');
    if (row) {
      row.querySelectorAll('button').forEach(function (b) { b.disabled = true; b.style.opacity = '0.55'; b.style.cursor = 'default'; });
      btn.textContent = (action === 'confirm') ? 'Confirming…' : 'Rejecting…';
    }
    // v0.41.8 (Gap-4) — generate a per-click Idempotency-Key so the
    // server-side wrap() dedupes browser auto-retries / accidental
    // double-clicks. crypto.randomUUID is the modern path; fallback
    // string is sufficient for WordPress transient lookup uniqueness.
    var idemKey = (window.crypto && typeof window.crypto.randomUUID === 'function')
      ? window.crypto.randomUUID()
      : ('idem-' + Date.now() + '-' + Math.random().toString(36).slice(2));
    fetch(CFG.api_base + '/widget/leads/' + action, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': CFG.rest_nonce || CFG.nonce,
        'Idempotency-Key': idemKey,
      },
      body: JSON.stringify({ lead_id: leadId }),
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        delete _queuePendingActioning[leadId];
        if (!res.ok) {
          var msg = (res.body && (res.body.message || res.body.code)) || (action + ' failed');
          window.HDLV2UI.toast('Could not ' + action + ' this lead: ' + msg, "error");
          pollNow();
          return;
        }
        if (row) {
          row.style.transition = 'opacity 280ms';
          row.style.opacity = '0.25';
        }
        // Realtime: the dashboard.js Pending Leads tab listens for this
        // event too, so its row count + freshness stamp also refresh.
        try {
          window.dispatchEvent(new CustomEvent('hdlv2:lead-actioned', {
            detail: { action: action, leadId: leadId, formToken: res.body && res.body.form_token, ts: Date.now() }
          }));
        } catch (e) {}
        setTimeout(pollNow, 350);
      })
      .catch(function () {
        delete _queuePendingActioning[leadId];
        window.HDLV2UI.toast('Network error. Please try again.', "error");
        pollNow();
      });
  }

  // 0.47.48 — Stage-1 snapshot for the top-strip "View details" panel:
  // whitelisted contact + rate + the Stage-1 answers. Degrades gracefully
  // ("no answer detail" note) when a lead has no captured stage1_data.
  // 0.47.50 — answers render from server-built stage1_display pairs
  // (label + canonical answer prose, same wording tables as the confirmed-
  // client Stage-1 tab). The old JS-side label map mislabelled 6 of 9
  // answers (q4 "Sitting", q9 "Social", …) and showed raw a–e letters.
  function buildPendingLeadDetailHTML(lead) {
    var s1 = (lead.stage1_data && typeof lead.stage1_data === 'object') ? lead.stage1_data : null;
    function kv(k, v) {
      return '<div class="hdlv2-pending-kv"><span class="hdlv2-pending-kv-k">' + esc(k) + '</span>'
        + '<span class="hdlv2-pending-kv-v">' + esc(v) + '</span></div>';
    }
    var rate = (lead.rate_of_ageing !== null && lead.rate_of_ageing !== undefined)
      ? Number(lead.rate_of_ageing).toFixed(2) + '×' : '—';
    var age = (s1 && s1.q1_age) ? s1.q1_age : (lead.visitor_age || '—');
    var sex = (s1 && s1.q1_sex) ? s1.q1_sex : '—';

    var contact = '<div class="hdlv2-pending-detail-grid">'
      + kv('Name', lead.visitor_name || '—')
      + kv('Email', lead.visitor_email || '—')
      + kv('Rate of ageing', rate)
      + kv('Age', String(age))
      + kv('Sex', String(sex))
      + '</div>';

    var items = '';
    if (Array.isArray(lead.stage1_display)) {
      lead.stage1_display.forEach(function (p) {
        if (p && p.label && p.value !== undefined && p.value !== null && p.value !== '') {
          items += kv(String(p.label), String(p.value));
        }
      });
    }
    var answers = items
      ? '<div class="hdlv2-pending-detail-sub">Stage-1 answers</div><div class="hdlv2-pending-detail-grid">' + items + '</div>'
      : '<div class="hdlv2-pending-detail-empty">No Stage-1 answer detail was captured for this lead.</div>';

    return '<div class="hdlv2-pending-detail">'
      + '<div class="hdlv2-pending-detail-note">Pre-confirmation lead — not yet an account. Confirm to provision the client and send their Stage-2 link.</div>'
      + contact + answers
      + '</div>';
  }

  function renderActionQueueButton(action, c) {
    if (action === 'release') {
      return '<button type="button" class="hdlv2-aq-btn hdlv2-aq-btn-release" data-aq="release" data-progress-id="' + esc(c.progress_id || 0) + '" data-user-id="' + esc(c.user_id) + '">Invite to Stage 3</button>';
    }
    if (action === 'open_consult') {
      var url = CFG.consultation_url + '?progress_id=' + encodeURIComponent(c.progress_id || 0);
      return '<a class="hdlv2-aq-btn hdlv2-aq-btn-consult" href="' + esc(url) + '">Record consultation</a>';
    }
    if (action === 'view_timeline') {
      return '<button type="button" class="hdlv2-aq-btn hdlv2-aq-btn-attn" data-aq="expand" data-user-id="' + esc(c.user_id) + '">View timeline</button>';
    }
    return '';
  }

  function buildActionMeta(action, c) {
    // v0.22.51 — Quim 2026-04-28: spell out where the data is + what the
    // button does. Render path: buildActionMeta() → innerHTML concat at line
    // ~413, so <strong> renders. Static string only — no esc() needed.
    if (action === 'release')       return 'Their WHY data was sent to your email. <strong>Practitioner Action:</strong> Unlock Stage 3 Form.';
    if (action === 'open_consult')  return 'Stage 3 complete — record your consultation notes';
    if (action === 'view_timeline' && c.reasons && c.reasons.length) return esc(c.reasons[0]);
    return '';
  }

  function bindActionQueueButtons() {
    if (!state.actionQueueEl) return;
    state.actionQueueEl.querySelectorAll('[data-aq="release"]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var pid = parseInt(btn.getAttribute('data-progress-id'), 10);
        if (!pid) return;
        var stub = { progress_id: pid, name: btn.closest('.hdlv2-aq-item').querySelector('.hdlv2-aq-item-name').textContent };
        // v0.20.10 — themed modal instead of browser-native confirm().
        var ask = (window.HDLV2UI && window.HDLV2UI.confirm)
          ? window.HDLV2UI.confirm({
              title: 'Invite ' + stub.name + ' to Stage 3?',
              body: 'This emails them the Stage 3 invitation and cannot be undone.',
              confirmLabel: 'Yes, send invite',
              cancelLabel: 'Cancel'
            })
          : Promise.resolve(window.confirm('Invite ' + stub.name + ' to Stage 3?\n\nThis emails them the Stage 3 invitation and cannot be undone.'));
        ask.then(function (ok) {
          if (!ok) return;
          releaseFromQueue(btn, stub);
        });
      });
    });
    state.actionQueueEl.querySelectorAll('[data-aq="expand"]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var uid = btn.getAttribute('data-user-id');
        var client = state.clients.filter(function (c) { return String(c.user_id) === String(uid); })[0];
        if (!client) return;
        var row = findRowForClient(client);
        if (!row) return;
        var expandBtn = row.querySelector('.hdlv2-expand-btn');
        if (expandBtn && expandBtn.getAttribute('aria-expanded') !== 'true') expandBtn.click();
        // v0.21.0 — the queue button reads "View timeline" so it must land on
        // the Timeline tab, not the default Flight Plan tab. Activate it now
        // that the detail panel is rendered.
        setTimeout(function () {
          var next = row.nextElementSibling;
          if (!next) return;
          var timelineTab = next.querySelector('.hdlv2-detail-tab[data-tab="timeline"]');
          if (timelineTab && !timelineTab.classList.contains('active')) timelineTab.click();
          next.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 150);
      });
    });
  }

  function releaseFromQueue(btn, client) {
    btn.disabled = true;
    var originalText = btn.textContent;
    btn.textContent = 'Releasing…';
    fetch(CFG.api_base + '/form/release-why', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify({ progress_id: client.progress_id }),
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        if (!res.ok || res.body.code) {
          btn.disabled = false;
          btn.textContent = originalText;
          window.HDLV2UI.toast('Could not release: ' + (res.body.message || 'Please refresh and try again.'), "error");
          return;
        }
        // Optimistic: remove the item with a fade. Next poll will confirm.
        var item = btn.closest('.hdlv2-aq-item');
        if (item) {
          item.classList.add('hdlv2-aq-item-done');
          setTimeout(function () { item.remove(); pollNow(); }, 600);
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = originalText;
        window.HDLV2UI.toast('Network error. Please refresh and try again.', "error");
      });
  }

  function findRowForClient(c) {
    var rows = state.tbody.querySelectorAll('tr.client-row');
    for (var i = 0; i < rows.length; i++) {
      if (rows[i].dataset.clientHash === c.email_hash) return rows[i];
    }
    return null;
  }

  // ──────────────────────────────────────────────────────────────────
  //  ROW RECONCILIATION — updates existing rows' status pill when the
  //  poll returns a changed status. Rather than re-rendering the whole
  //  table (which would kill V1's hover states), we surgically replace
  //  the V2 pill in the existing DOM.
  // ──────────────────────────────────────────────────────────────────

  function reconcileRows(list) {
    list.forEach(function (c) {
      if (!c.email_hash) return;
      var row = findRowForClient(c);
      if (!row) {
        // V2-only row we haven't rendered yet (e.g. new client appeared).
        // v0.47.10 — a client appearing mid-session (e.g. the practitioner just
        // confirmed their Stage 1) is the latest activity, so insert it at the
        // TOP of the list in realtime rather than appending at the bottom. The
        // one-shot default sort is latest-first, so top is its correct place;
        // this keeps the newest client first without re-sorting (which would
        // clobber a manual column-sort). Briefly highlight so the practitioner
        // sees it arrive.
        if (!state.matched[c.email_hash]) {
          var newRow = buildV2OnlyRow(c);
          newRow.dataset.status = c.status || '';
          state.tbody.insertBefore(newRow, state.tbody.firstChild);
          // Brief arrival flash so the practitioner sees it land (self-contained
          // inline style — no CSS-injection dependency). Honours the filter:
          // if the new row doesn't match active filters, applyFilters() below
          // hides it on the same tick.
          newRow.style.transition = 'background-color 1400ms ease';
          newRow.style.backgroundColor = '#fffbeb';
          (function (r) { setTimeout(function () { r.style.backgroundColor = ''; }, 1600); })(newRow);
          state.matched[c.email_hash] = true;
        }
        return;
      }
      // Keep status attribute in sync with server state so the filter
      // reflects status changes picked up during polling.
      row.dataset.status = c.status || '';
      // Keep the stalled-filter attrs fresh as the client progresses / goes quiet.
      stampStalledAttrs(row, c);
      // P2 — keep the stage-aware resend button's label/tooltip/disabled state
      // in step with the fresh status (no-op unless the state actually moved).
      refreshResendButton(row, c);
      // Update inline pill
      var cell = row.querySelector('.status-badge-cell');
      if (!cell) return;
      var existing = cell.querySelector('.hdlv2-inline-badge');
      var newLabel = 'V2 · ' + c.label;
      // v0.41.19 — also reconcile the mini-stages strip when the journey
      // state shifts (e.g. practitioner releases WHY in another tab → S3
      // dot flips from amber to teal on the next 4-s digest poll). Hash
      // is status + has_final_report so we only re-render the strip when
      // something actually moves; idle polls are no-ops.
      var existingStrip = cell.querySelector('.hdlv2-mini-stages');
      var newStripHash  = (c.status || '') + '|' + (c.has_final_report ? '1' : '0');
      var stripChanged  = !existingStrip || existingStrip.getAttribute('data-state-hash') !== newStripHash;
      if (!existing || existing.textContent !== newLabel) {
        if (existing) existing.remove();
        injectV2Badge(row, c);
        var fresh = cell.querySelector('.hdlv2-inline-badge');
        if (fresh) {
          fresh.classList.add('hdlv2-badge-flash');
          setTimeout(function () { fresh.classList.remove('hdlv2-badge-flash'); }, 1200);
        }
      } else if (stripChanged) {
        // Pill text unchanged, but journey state moved (e.g. has_final_report
        // flipped without a label change). Re-inject just the strip.
        injectMiniStages(cell, c);
      }
      var newlyRendered = cell.querySelector('.hdlv2-mini-stages');
      if (newlyRendered) newlyRendered.setAttribute('data-state-hash', newStripHash);
    });
    // Re-apply active filters so a poll-driven status change is immediately
    // reflected in the filtered view (e.g. client moves to needs_attention).
    applyFilters();
  }

  // ──────────────────────────────────────────────────────────────────
  //  Freshness indicator — "Updated 12s ago"
  // ──────────────────────────────────────────────────────────────────

  function updateFreshnessIndicator() {
    if (!state.freshnessEl) return;
    if (!lastFetchedAt) { state.freshnessEl.textContent = ''; return; }
    var secs = Math.round((Date.now() - lastFetchedAt) / 1000);
    var label;
    if (secs < 3)         label = 'Updated just now';
    else if (secs < 60)   label = 'Updated ' + secs + 's ago';
    else if (secs < 3600) label = 'Updated ' + Math.round(secs / 60) + 'min ago';
    else                  label = 'Updated ' + Math.round(secs / 3600) + 'h ago';
    state.freshnessEl.textContent = label;
  }

  function handleReleaseDeepLink(list) {
    var params = new URLSearchParams(window.location.search);
    var target = parseInt(params.get('release_progress_id') || '0', 10);
    if (!target) return;
    var client = list.filter(function (c) { return c.progress_id === target; })[0];
    if (!client) return;
    // Find the row (matched or V2-only) and its expand button.
    var rows = state.tbody.querySelectorAll('tr.client-row');
    var match = null;
    rows.forEach(function (row) {
      if (row.dataset.clientHash === client.email_hash) match = row;
    });
    if (!match) return;
    var btn = match.querySelector('.hdlv2-expand-btn');
    if (!btn || btn.getAttribute('aria-expanded') === 'true') return;
    btn.click();
    setTimeout(function () {
      var panel = match.nextElementSibling && match.nextElementSibling.querySelector('.hdlv2-detail-panel');
      if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
      var action = panel && panel.querySelector('.hdlv2-release-why-btn');
      if (action) action.classList.add('hdlv2-release-pulse');
    }, 200);
  }

  function fetchV2Clients() {
    // X-HDLV2-Bg marks this as background traffic: a 429 here updates the
    // rate-limit pill + arms the shared cooldown but never opens the modal.
    // Resolves null (not []) on failure so pollNow keeps the currently
    // rendered roster instead of wiping it. (2026-07-14 RL fix)
    return fetch(CFG.api_base + '/dashboard/clients', {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': CFG.nonce, 'X-HDLV2-Bg': '1' },
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .catch(function () { return null; });
  }

  // ── Stalled-leads filter — stamp the two data-attrs the filter reads ──
  // stalledStage = completed-stage count (v2_total_stages).
  // stalledLast  = epoch ms of the most recent signal, coalescing
  //   latest_event_at → last_checkin_date → v2_first_event_at, so a
  //   Stage-1-only lead (whose latest_event_at is null) still gets their
  //   Stage 1 date as the inactivity anchor. MySQL datetimes are server-local;
  //   parse via .replace(' ','T') (NOT new Date(str+'Z')) to match the
  //   existing relative-time code and avoid forcing UTC.
  function stampStalledAttrs(row, c) {
    row.dataset.stalledStage = (typeof c.v2_total_stages === 'number') ? String(c.v2_total_stages) : '';
    var raw = c.latest_event_at || c.last_checkin_date || c.v2_first_event_at || '';
    var ms = '';
    if (raw) {
      var d = new Date(String(raw).replace(' ', 'T'));
      if (!isNaN(d.getTime())) ms = String(d.getTime());
    }
    row.dataset.stalledLast = ms;
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
      // W11 — flag-gated row tagging for the Source filter. Always sets a
      // value (default 'practitioner') so the filter doesn't have to guard
      // missing attributes. Reads `tier` from the /dashboard/clients
      // response added in W11's backend extension.
      if (CFG.automation_tier_enabled) {
        row.dataset.source = (c.tier === 'automation') ? 'automation' : 'practitioner';
      }
      row.dataset.status = c.status || '';
      stampStalledAttrs(row, c);
      // v0.47.14 — matched rows (V1 server-rendered + a live V2 record) were
      // left with ONLY V1's .delete-client-btn, whose AJAX handler soft-deletes
      // the V1 link table but never stamps hdlv2_form_progress.deleted_at. The
      // dashboard roster is V2-first (get_clients_for_practitioner reads
      // form_progress WHERE deleted_at IS NULL), so such a client reappeared via
      // appendV2OnlyRows on the next page load. Swap V1's delete control for the
      // V2 trash so the click routes through doDeleteClient ->
      // POST /dashboard/client/{user_id}/delete, which DOES stamp
      // form_progress.deleted_at and cascades.
      swapMatchedRowDelete(row, c);
      // P2 — unify the rest of the action cell (paper-plane → stage-aware
      // resend, bar-chart → panel Progress tab, chat fed the V2 email).
      unifyMatchedRowActions(row, c);
    });
    // W11 — mount the source-filter chips once per render after rows are
    // tagged. mountSourceFilter is a no-op when the flag is off or no
    // automation-tier rows exist; idempotent on re-render.
    if (CFG.automation_tier_enabled) {
      mountSourceFilter();
    }
    // Red-flag status filter — self-guards on CFG.redflag_scan_enabled + idempotent.
    mountStatusFilter();
    // Stalled-leads targeting filter — self-guards on CFG.stalled_filter_enabled + idempotent.
    mountStalledFilter();
  }

  // v0.47.14 — fix for the V1+V2 matched-client incomplete-delete bug. A
  // "matched" row is a V1 server-rendered tr.client-row that also resolves to a
  // V2 client (c = state.byHash[hash]). V1 renders its own .delete-client-btn on
  // that row; clicking it runs wp_ajax_health_tracker_delete_client which only
  // soft-deletes the V1 link table and never touches form_progress, so the
  // V2-first dashboard roster keeps returning the client and appendV2OnlyRows
  // re-adds it on reload. We REPLACE V1's button with the V2 trash
  // (.hdlv2-delete-client-btn) so the already-bound delegated V2 handler runs
  // doDeleteClient -> the cascade endpoint that stamps form_progress.deleted_at.
  // Guards:
  //  - only act on clients with a V2 user_id (the cascade endpoint is keyed by
  //    user_id; renderV2DeleteButton returns '' without it, which would leave
  //    the row with NO delete control if we'd already stripped V1's button);
  //  - no-op when the row has no .delete-client-btn (e.g. invite-rows) so we
  //    never remove an affordance we cannot replace.
  // REPLACE, never ADD: two delete controls would let V1's buggy handler still
  // fire. enhanceMatchedRows runs once per load and the V2 button's class is
  // distinct from V1's, so a second pass finds no .delete-client-btn — naturally
  // idempotent.
  function swapMatchedRowDelete(row, c) {
    if (!c || !c.user_id) return;
    var v1Btn = row.querySelector('.delete-client-btn');
    if (!v1Btn) return;
    var html = renderV2DeleteButton(c);
    if (!html) return;
    var tmp = document.createElement('div');
    tmp.innerHTML = html;
    var v2Btn = tmp.firstElementChild;
    if (!v2Btn) return;
    v1Btn.parentNode.replaceChild(v2Btn, v1Btn);
  }

  function appendV2OnlyRows(list) {
    list.forEach(function (c) {
      if (!c.email_hash || state.matched[c.email_hash]) return;
      var newRow = buildV2OnlyRow(c);
      // W11 — same data-source tagging for V2-only rows so the filter
      // catches them too. Same flag-gate as enhanceMatchedRows.
      if (CFG.automation_tier_enabled) {
        newRow.dataset.source = (c.tier === 'automation') ? 'automation' : 'practitioner';
      }
      newRow.dataset.status = c.status || '';
      state.tbody.appendChild(newRow);
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

    // v0.41.19 — append a 6-dot journey strip alongside the badge so the
    // practitioner sees stage progression at a glance from the closed
    // row, no expand required. Sits INSIDE .status-badge-cell so V1's
    // table structure (7 columns) is unchanged — no header shifting,
    // sort comparator stays accurate.
    injectMiniStages(cell, c);

    // W11 — SELF-REPORTED pill for automation-tier clients. Same
    // status-badge-cell parent so V1's 7-column structure is preserved.
    // Idempotent (skips if a prior pill already exists for this row);
    // safe for reconcileRows() re-renders.
    if (CFG.automation_tier_enabled && c.tier === 'automation' && !cell.querySelector('.hdlv2-self-reported-badge')) {
      var srPill = document.createElement('span');
      srPill.className = 'hdlv2-inline-badge hdlv2-self-reported-badge';
      srPill.textContent = 'SELF-REPORTED';
      srPill.title = c.auto_consultation
        ? 'Submitted ' + (c.auto_consultation.submitted_at || '')
        : 'Automation tier — awaiting self-reported submission';
      cell.appendChild(srPill);
    }
  }

  // v0.41.19 — 6-dot inline journey indicator. Order matches the
  // expand-panel tab strip + the buildDetailPanel head ribbon:
  //   S1 · S2 · S3 · Consult · Final · Plan
  // States: 'done' (teal) · 'active' (amber pulse, current step the
  // workflow is waiting on) · 'pending' (grey).
  function injectMiniStages(cell, c) {
    // Idempotent — remove any prior strip before re-render so the 4-s
    // digest poll's reconcileRows path can refresh dots when a status
    // change actually moves the journey forward.
    var existing = cell.querySelector('.hdlv2-mini-stages');
    if (existing) existing.parentNode.removeChild(existing);

    var dots = deriveJourneyState(c);
    var wrap = document.createElement('span');
    wrap.className = 'hdlv2-mini-stages';
    wrap.title = 'Stage 1 · Stage 2 · Stage 3 · Consultation · Final · Weekly Flight Plan';
    // Hash for reconcileRows() so idle polls don't re-render the strip.
    wrap.setAttribute('data-state-hash', (c.status || '') + '|' + (c.has_final_report ? '1' : '0'));
    var html = '';
    for (var i = 0; i < dots.length; i++) {
      html += '<span class="hdlv2-mini-stages-dot ' + dots[i] + '" aria-hidden="true"></span>';
    }
    // Compact step label (e.g. "Wk 1" / "Stage 3" / "Stage 1") so the
    // strip is self-explanatory without a tooltip. Driven by status,
    // not free text — always accurate.
    html += '<span class="hdlv2-mini-stages-label">' + esc(stepLabel(c)) + '</span>';
    wrap.innerHTML = html;
    cell.appendChild(wrap);
  }

  // Map dashboard status → array of 6 states [S1, S2, S3, Consult, Final, Plan].
  // Hard rule: once a stage is done it stays done. The first not-done stage
  // is 'active'. Everything after is 'pending'. has_final_report is the
  // ground truth for "Consult + Final + Plan generated".
  function deriveJourneyState(c) {
    var dots = ['pending','pending','pending','pending','pending','pending'];
    var st = c && c.status;
    if (!st || st === 'not_started') { dots[0] = 'active'; return dots; }
    dots[0] = 'done';
    if (st === 'low_data') { dots[1] = 'active'; return dots; }
    dots[1] = 'done';
    if (st === 'awaiting_why_release' || st === 'stage3_in_progress') {
      dots[2] = 'active';
      return dots;
    }
    dots[2] = 'done';
    if (st === 'awaiting_consult') { dots[3] = 'active'; return dots; }
    // Post-consult states (active / progress_normal / needs_attention / inactive).
    if (c.has_final_report) {
      dots[3] = 'done';
      dots[4] = 'done';
      // needs_attention or inactive — flag the plan dot amber so practitioner
      // sees something to look at. progress_normal / active = all green.
      if (st === 'needs_attention' || st === 'inactive') dots[5] = 'active';
      else                                                dots[5] = 'done';
    } else {
      // Defensive: post-consult status without a final report row. Shouldn't
      // happen in practice (consult_done → final generated) but never crash.
      dots[3] = 'done';
    }
    return dots;
  }

  // Compact label that pairs the dot strip with a one-word "where" hint.
  // Mirrors the status-aware text in the journey ribbon at the panel head.
  function stepLabel(c) {
    var st = c && c.status;
    switch (st) {
      case 'not_started':          return 'Stage 1';
      case 'low_data':             return 'Stage 2';
      case 'awaiting_why_release': return 'WHY';
      case 'stage3_in_progress':   return 'Stage 3';
      case 'awaiting_consult':     return 'Consult';
      case 'active':
      case 'progress_normal':      return 'Plan';
      case 'needs_attention':      return 'Plan ⚠';
      case 'inactive':             return 'Idle';
      default:                     return '';
    }
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
      row.classList.remove('hdlv2-row-active');
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
    row.classList.add('hdlv2-row-active');
  }

  // -- Flight Consultation Notes -- per-client download (Phase 4) --
  // Ported from itshover.com/icons/download-icon (github.com/itshover/itshover, Apache-2.0)
  // as inline SVG; animated by pure CSS on hover/loading (no React/motion, CSP-safe).
  function flightNotesIconSVG() {
    return '<svg class="hdlv2-fn-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<path class="fn-ico-tray" d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>'
      + '<path class="fn-ico-stem" d="M12 15V3"></path>'
      + '<path class="fn-ico-head" d="m7 10 5 5 5-5"></path>'
      + '</svg>';
  }
  // v0.46.50 — document glyph for the PDF download cards (Final tab Trajectory
  // Plan + Stage-3 Draft Report). One source so the tabs can't drift.
  function docIconSVG() {
    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>'
      + '<polyline points="14 2 14 8 20 8"/>'
      + '<line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>'
      + '</svg>';
  }
  // v0.46.x — Flight Notes render now runs on the async job queue (so a ~30-90s
  // Claude+PDFMonkey render never holds a PHP worker). The click either gets an
  // instant cached URL, or a { queued, job_id } we poll until the render lands,
  // then re-fetch the (now cached) URL through the same ownership-checked route.
  function fnReset(btn) { btn.classList.remove('is-loading'); btn.disabled = false; }

  function fnError(btn, msg) {
    fnReset(btn);
    if (window.HDLV2UI && typeof window.HDLV2UI.toast === 'function') {
      window.HDLV2UI.toast(msg, 'error');
    } else {
      window.alert(msg);
    }
  }

  function fnTriggerDownload(j) {
    var a = document.createElement('a');
    // v0.46.52 — target=_blank so a cross-origin PDF served inline (where
    // the download attribute is ignored) opens a new tab instead of
    // navigating the practitioner dashboard away. Same contract as the
    // static .hdlv2-dl-btn anchors; rel=noopener already set.
    a.href = j.pdf_url; a.rel = 'noopener'; a.target = '_blank';
    if (j.filename) { a.download = j.filename; }
    document.body.appendChild(a); a.click(); a.remove();
  }

  // Re-fetch the now-cached PDF URL (job results never carry the signed URL —
  // that would re-open the /jobs/status IDOR) and trigger the download.
  function fnFetchAndDownload(c, btn) {
    fetch(CFG.api_base + '/flight-notes/pdf?client_id=' + encodeURIComponent(c.user_id), {
      headers: { 'X-WP-Nonce': CFG.nonce }, credentials: 'same-origin', cache: 'no-store'
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }, function () { return { ok: r.ok, j: null }; }); })
      .then(function (res) {
        if (res.ok && res.j && res.j.pdf_url) { fnTriggerDownload(res.j); fnReset(btn); return; }
        fnError(btn, (res.j && res.j.message) ? res.j.message : 'Could not prepare the flight notes PDF.');
      })
      .catch(function () { fnError(btn, 'Network error preparing the PDF.'); });
  }

  function fnJobStatusUrl(jobId) {
    return CFG.api_base.replace(/\/$/, '') + '/jobs/' + jobId + '/status';
  }

  function fnPollJob(c, btn, jobId, startedAt) {
    if (!btn.isConnected) return; // panel torn down — stop cleanly
    if (Date.now() - startedAt > 3 * 60 * 1000) {
      fnError(btn, 'The flight notes are taking longer than expected. Please try again in a moment.');
      return;
    }
    var url = fnJobStatusUrl(jobId) + (fnJobStatusUrl(jobId).indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now();
    fetch(url, { headers: CFG.nonce ? { 'X-WP-Nonce': CFG.nonce } : {}, cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (job) {
        if (!btn.isConnected) return;
        if (!job || !job.status || job.status === 'pending' || job.status === 'running') {
          setTimeout(function () { fnPollJob(c, btn, jobId, startedAt); }, 2500);
          return;
        }
        if (job.status === 'completed') { fnFetchAndDownload(c, btn); return; }
        fnError(btn, 'The flight notes could not be generated. Please try again.');
      })
      .catch(function () { setTimeout(function () { fnPollJob(c, btn, jobId, startedAt); }, 2500); });
  }

  function downloadFlightNotes(c, btn) {
    if (btn.classList.contains('is-loading')) return;
    btn.classList.add('is-loading');
    btn.disabled = true;
    fetch(CFG.api_base + '/flight-notes/pdf?client_id=' + encodeURIComponent(c.user_id), {
      headers: { 'X-WP-Nonce': CFG.nonce },
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }, function () { return { ok: r.ok, j: null }; }); })
      .then(function (res) {
        if (!res.j) { fnError(btn, 'Could not prepare the flight notes PDF.'); return; }
        if (res.ok && res.j.pdf_url) { fnTriggerDownload(res.j); fnReset(btn); return; } // instant cache hit
        if (res.ok && res.j.queued && res.j.job_id) { fnPollJob(c, btn, res.j.job_id, Date.now()); return; }
        fnError(btn, res.j.message || 'Could not prepare the flight notes PDF.');
      })
      .catch(function () { fnError(btn, 'Network error preparing the PDF.'); });
  }

  function buildDetailPanel(c) {
    var panel = document.createElement('div');
    panel.className = 'hdlv2-detail-panel';
    // v0.35.4 — tag the panel with the client's user_id so realtime
    // refreshers (pollNow → maybeRefreshActiveFlightPlanTabs) can map a
    // mounted panel back to a fresh client object from state.clients
    // without walking the DOM. Plain string attribute, escaped via esc().
    panel.setAttribute('data-user-id', esc(c.user_id));
    // Action banner — only when practitioner has something to do NOW.
    var actionBanner = '';
    if (c.status === 'awaiting_why_release' && c.progress_id) {
      actionBanner = '<div class="hdlv2-action-banner" data-kind="release-why">'
        + '<div class="hdlv2-action-banner-text">'
        + '<strong>Ready to invite this client to Stage 3</strong>'
        + '<span>Your client submitted their WHY profile. Release the gate to email them the Stage 3 invitation.</span>'
        + '</div>'
        + '<button type="button" class="hdlv2-release-why-btn" data-progress-id="' + c.progress_id + '">Invite to Stage 3</button>'
        + '</div>';
    }

    // v0.41.19 — journey ribbon in the head + snapshot strip above tabs
    // + per-tab status dot. All data driven by deriveJourneyState(c) so
    // a single source of truth governs every visual stage indicator.
    var dots          = deriveJourneyState(c);
    var headPill      = '<span class="hdlv2-detail-status-pill" style="color:' + (c.color || '#94a3b8') + ';background:' + hexToRgba(c.color || '#94a3b8', 0.12) + ';border:1px solid ' + hexToRgba(c.color || '#94a3b8', 0.35) + ';">' + esc(c.label || c.status || '') + '</span>';
    var snapshotShell = renderSnapshotShell();

    panel.innerHTML = [
      // Head — name, email, status pill, Flight Notes button on one line.
      '<div class="hdlv2-detail-head">',
      '<div class="hdlv2-detail-head-id">',
      '<strong>' + esc(c.name) + '</strong>',
      '<span class="hdlv2-detail-email">' + esc(c.email || '') + '</span>',
      '</div>',
      '<div class="hdlv2-detail-head-meta">',
      headPill,
      '</div>',
      '</div>',
      actionBanner,
      // 4-tile KPI snapshot — fills async once client-record + effort-outcomes resolve.
      snapshotShell,
      // v0.24.0 — tab strip reordered to match client journey (Matthew E1):
      // Progress (default landing — current state) → Stage 1 → Stage 2 →
      // Stage 3 → Consultation → Final → Flight Plan.
      // v0.41.19 — each tab carries a tiny status dot (done/active/pending)
      // so the journey is readable without clicking.
      '<nav class="hdlv2-detail-tabs" role="tablist">',
      tabHtml('progress',     'Progress',      ''),
      '<span class="hdlv2-detail-tab-sep" aria-hidden="true"></span>',
      tabHtml('stage1',       'Stage 1',       dots[0]),
      tabHtml('stage2',       'Stage 2',       dots[1]),
      tabHtml('stage3',       'Stage 3',       dots[2]),
      '<span class="hdlv2-detail-tab-sep" aria-hidden="true"></span>',
      // B10 — Flight Notes is a journey tab BEFORE Consultation (was a head
      // button). v0.46.48 — its dot mirrors the Stage-3 gate that
      // loadFlightNotes + GET /flight-notes/pdf already enforce: teal check
      // once Stage 3 is done (notes available), empty pending circle before.
      tabHtml('flight-notes', 'Flight Notes',  dots[2] === 'done' ? 'done' : 'pending'),
      tabHtml('consultation', 'Consultation',  dots[3]),
      tabHtml('final',        'Final',         dots[4]),
      tabHtml('flight-plan',  'Weekly Flight Plan',   dots[5]),
      '</nav>',
      '<div class="hdlv2-detail-content" data-tab-content></div>',
    ].join('');

    // Mark the Progress tab as currently selected (separate from journey state).
    var progressTab = panel.querySelector('.hdlv2-detail-tab[data-tab="progress"]');
    if (progressTab) progressTab.classList.add('current');

    var tabs = panel.querySelectorAll('.hdlv2-detail-tab');
    var content = panel.querySelector('[data-tab-content]');
    tabs.forEach(function (t) {
      t.addEventListener('click', function () {
        tabs.forEach(function (x) { x.classList.remove('current'); });
        t.classList.add('current');
        loadTab(t.dataset.tab, c, content);
      });
    });

    var releaseBtn = panel.querySelector('.hdlv2-release-why-btn');
    if (releaseBtn) {
      releaseBtn.addEventListener('click', function () { releaseWhy(c, releaseBtn, panel); });
    }

    // v0.24.0 — default landing tab is Progress (was Flight Plan).
    loadTab('progress', c, content);
    // v0.41.19 — fire-and-forget snapshot hydration. Both fetches are cached
    // on the client object, so subsequent tab clicks (Progress, Stage 1/3)
    // reuse the same in-flight or resolved promise — net zero extra requests.
    mountSnapshot(c, panel);
    return panel;
  }

  // v0.41.19 — single source of truth for tab markup. The status arg is one
  // of 'active' (current selected tab), 'done', 'pending' (journey state), or
  // 'active-stage' (the workflow's current step — amber pulse).
  function tabHtml(tabKey, label, journeyState) {
    // B-series — per-tab status indicator is now the SINGLE progress display
    // (the head journey ribbon was removed). State comes from deriveJourneyState
    // → c.status → calculate_status. Icon + aria-label, never colour alone:
    // ✓ done · ● current step · empty pending. The Progress analytics tab
    // passes '' and carries no status glyph.
    var dotClass = 'hdlv2-tab-dot';
    var glyph = '', statusWord = '';
    // v0.46.48 — inline SVGs replace the text glyphs &#10003;/&#9679;:
    // font-dependent metrics drew them slightly off optical centre at 9px.
    var svgCheck = '<svg viewBox="0 0 10 10" width="8" height="8" focusable="false" aria-hidden="true"><path d="M1.6 5.4l2.2 2.3 4.6-5.2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    var svgDot   = '<svg viewBox="0 0 10 10" width="6" height="6" focusable="false" aria-hidden="true"><circle cx="5" cy="5" r="4" fill="currentColor"/></svg>';
    if (journeyState === 'done')         { dotClass += ' done';         glyph = svgCheck; statusWord = 'done'; }
    else if (journeyState === 'active')  { dotClass += ' active-stage'; glyph = svgDot;   statusWord = 'current step'; }
    else if (journeyState === 'pending') { statusWord = 'pending'; }
    var aria = statusWord ? esc(label + ', ' + statusWord) : esc(label);
    var dot  = statusWord ? '<span class="' + dotClass + '" aria-hidden="true">' + glyph + '</span>' : '';
    return '<button type="button" class="hdlv2-detail-tab" data-tab="' + tabKey + '" title="' + aria + '" aria-label="' + aria + '">'
      + dot
      + esc(label)
      + '</button>';
  }

  // v0.42.2 — Red-flag banner for the client detail panel. Rendered from
  // the /dashboard/client-record response (flags + flags_scan_status).
  // Returns '' when there are no flags and no scan failure — safe to always call.
  // Uses .hdlv2-redflag-banner (own CSS, separate from hdlv2-action-banner).
  function redflagBannerHtml(record) {
    if (record && record.flags_scan_status === 'failed') {
      return '<div class="hdlv2-redflag-banner" data-kind="failed">'
        + '<div class="hdlv2-redflag-head">&#9888; Red-flag scan did not complete</div>'
        + '<div class="hdlv2-redflag-sub">The automated scan did not finish — please review this client manually. A failed scan is not an all-clear.</div>'
        + '</div>';
    }
    var flags = (record && record.flags) || [];
    if (!flags.length) { return ''; }
    var URG = { TODAY: 'Today', THIS_WEEK: 'This week', WITHIN_WEEKS: 'Within weeks', REPORT_ONLY: 'Report only' };
    var CATCLASS = { HARD: 'hard', AMBER: 'amber', PATTERN: 'pattern', CONTEXT: 'context' };
    var items = flags.map(function (f) {
      var cat = String(f.category || '').toUpperCase();
      var cls = CATCLASS[cat] || 'context';
      var catLabel = cat ? cat.charAt(0) + cat.slice(1).toLowerCase() : 'Note';
      var urg = URG[String(f.urgency || '').toUpperCase()] || '';
      var chipText = catLabel + (urg ? ' · ' + urg : '');
      return '<div class="hdlv2-redflag-item">'
        + '<span class="hdlv2-redflag-chip ' + cls + '">' + esc(chipText) + '</span>'
        + '<div class="hdlv2-redflag-note">' + esc(f.practitioner_note || f.concern || '') + '</div>'
        + '</div>';
    }).join('');
    return '<div class="hdlv2-redflag-banner">'
      + '<div class="hdlv2-redflag-head">&#9888; Red flags from assessment</div>'
      + items
      + '</div>';
  }

  function renderSnapshotShell() {
    // 4 tiles rendered as skeletons until mountSnapshot() resolves.
    // Labels are static so they read correctly even before data arrives.
    return '<div class="hdlv2-detail-snapshot" data-snapshot-mount>'
      + snapshotTile('rate',     'Rate of Ageing', '—', '', 'accent-amber')
      + snapshotTile('adh',      'Adherence',      '—', '', 'accent-teal')
      + snapshotTile('weeks',    'Weeks active',   '—', '', 'accent-blue')
      + snapshotTile('next',     'Next milestone', '—', '', 'accent-grey')
      + '</div>';
  }

  function snapshotTile(key, label, value, sub, accent) {
    return '<div class="hdlv2-snap-tile ' + accent + '" data-snap="' + key + '">'
      + '<div class="hdlv2-snap-tile-label">' + esc(label) + '</div>'
      + '<div class="hdlv2-snap-tile-value">' + value + '</div>'
      + '<div class="hdlv2-snap-tile-sub">' + sub + '</div>'
      + '</div>';
  }

  // v0.41.19 — Hydrate the 4-tile snapshot strip. Reuses fetchClientRecord(c)
  // (already cached on the client object — same fetch the journey tabs use)
  // and a per-client effort-outcomes fetch. Both fail silently; the tile
  // value just stays at '—'. No new endpoint, no extra round-trips: the
  // client-record fetch is the same one the Stage 1/2/3 tabs already trigger
  // on first click — running it eagerly on expand simply re-orders timing.
  function mountSnapshot(c, panel) {
    var mount = panel.querySelector('[data-snapshot-mount]');
    if (!mount) return;

    // v0.42.1 — Red-flag banner: injected once per panel expand, before the
    // snapshot strip, using the same fetchClientRecord promise the rate tile
    // already triggers. Returns '' when flags are empty or scan is pending —
    // so no banner is inserted for healthy clients.
    fetchClientRecord(c).then(function (data) {
      var bannerHtml = redflagBannerHtml(data);
      if (bannerHtml) {
        mount.insertAdjacentHTML('beforebegin', bannerHtml);
      }
    });

    // 1) Rate of Ageing — prefer Stage 3 (full calc), fall back to Stage 1
    //    (9-question quick rate). Static after first paint per expand.
    fetchClientRecord(c).then(function (data) {
      var rate = null, source = '';
      if (data) {
        if (data.stage3 && typeof data.stage3.rate === 'number') {
          rate = data.stage3.rate; source = 'From Stage 3 full calc';
        } else if (data.stage1 && typeof data.stage1.rate === 'number') {
          rate = data.stage1.rate; source = 'From Stage 1 quick rate';
        }
      }
      var tile = mount.querySelector('[data-snap="rate"]');
      if (!tile) return;
      var val = tile.querySelector('.hdlv2-snap-tile-value');
      var sub = tile.querySelector('.hdlv2-snap-tile-sub');
      if (rate !== null) {
        val.innerHTML = rate.toFixed(2) + '<span class="hdlv2-snap-tile-unit">×</span>';
        // Faster/slower/average comparable to 1.00 baseline.
        var verdict = rate > 1.05 ? 'Faster than baseline' : rate < 0.95 ? 'Slower than baseline' : 'On baseline';
        sub.innerHTML = esc(source + ' · ' + verdict);
      } else {
        val.textContent = '—';
        sub.textContent = c.status === 'not_started' ? 'No Stage 1 yet' : 'Pending';
      }
    });

    // 2-4) Adherence / Weeks active / Next milestone — fetch effort-outcomes
    //      once per expand and derive all three from the same payload.
    fetchEffortOutcomes(c).then(function (eo) {
      var adhTile   = mount.querySelector('[data-snap="adh"]');
      var weeksTile = mount.querySelector('[data-snap="weeks"]');
      var nextTile  = mount.querySelector('[data-snap="next"]');
      if (!eo) return;
      var adherence = (eo && eo.adherence) || [];

      if (adhTile) {
        var aVal = adhTile.querySelector('.hdlv2-snap-tile-value');
        var aSub = adhTile.querySelector('.hdlv2-snap-tile-sub');
        if (adherence.length) {
          var last = adherence[adherence.length - 1];
          aVal.innerHTML = (last.overall|0) + '<span class="hdlv2-snap-tile-unit">% · Wk ' + (last.week_number|0) + '</span>';
          aSub.textContent = 'Movement ' + (last.movement|0) + ' · Nutrition ' + (last.nutrition|0) + ' · Key ' + (last.key_action|0);
        } else {
          aVal.textContent = '—';
          aSub.textContent = c.has_final_report ? 'No ticks yet' : 'Plan not started';
        }
      }

      if (weeksTile) {
        var wVal = weeksTile.querySelector('.hdlv2-snap-tile-value');
        var wSub = weeksTile.querySelector('.hdlv2-snap-tile-sub');
        if (adherence.length) {
          var latestWeek = adherence[adherence.length - 1].week_number || adherence.length;
          wVal.innerHTML = latestWeek + '<span class="hdlv2-snap-tile-unit">/12</span>';
          wSub.textContent = 'Plan in week ' + latestWeek;
        } else if (c.has_final_report) {
          wVal.innerHTML = '0<span class="hdlv2-snap-tile-unit">/12</span>';
          wSub.textContent = 'Plan delivered · awaiting first tick';
        } else {
          wVal.textContent = 'Pre-plan';
          wSub.textContent = c.status === 'awaiting_consult' ? 'Stage 3 complete · consult pending' : 'Plan not generated';
        }
      }

      if (nextTile) {
        var nVal = nextTile.querySelector('.hdlv2-snap-tile-value');
        var nSub = nextTile.querySelector('.hdlv2-snap-tile-sub');
        if (adherence.length) {
          var wk    = adherence[adherence.length - 1].week_number || adherence.length;
          var togo  = Math.max(0, 12 - wk);
          nVal.innerHTML = '<span class="hdlv2-snap-tile-next-line">Quarterly</span><span class="hdlv2-snap-tile-next-line">reassessment</span>';
          nSub.textContent = togo > 0 ? ('Week 12 · in ' + togo + ' week' + (togo === 1 ? '' : 's')) : 'Reassessment due now';
        } else if (c.has_final_report) {
          nVal.innerHTML = '<span class="hdlv2-snap-tile-next-line">Quarterly</span><span class="hdlv2-snap-tile-next-line">reassessment</span>';
          nSub.textContent = 'Week 12 · in 12 weeks';
        } else {
          nVal.textContent = '—';
          nSub.textContent = 'Set by Final report';
        }
      }
    });
  }

  // v0.41.19 — per-client cached fetch for /effort-outcomes. Mirrors the
  // pattern fetchClientRecord uses so loadProgress (which fetches the same
  // payload on tab click) and mountSnapshot (eager on expand) share a single
  // promise. Saves one round-trip per expand when the practitioner lands
  // on Progress.
  function fetchEffortOutcomes(c) {
    if (c._effort_promise) return c._effort_promise;
    if (!c.user_id) {
      c._effort_promise = Promise.resolve(null);
      return c._effort_promise;
    }
    c._effort_promise = fetch(CFG.api_base + '/dashboard/client/' + c.user_id + '/effort-outcomes', {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': CFG.nonce },
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .catch(function () { return null; });
    return c._effort_promise;
  }

  function releaseWhy(c, btn, panel) {
    // v0.20.10 — themed modal instead of browser-native confirm().
    var ask = (window.HDLV2UI && window.HDLV2UI.confirm)
      ? window.HDLV2UI.confirm({
          title: 'Invite ' + (c.name || c.email) + ' to Stage 3?',
          body: 'This emails them the Stage 3 invitation and cannot be undone.',
          confirmLabel: 'Yes, send invite',
          cancelLabel: 'Cancel'
        })
      : Promise.resolve(window.confirm('Invite ' + (c.name || c.email) + ' to Stage 3?\n\nThis emails them the Stage 3 invitation and cannot be undone.'));
    ask.then(function (confirmed) {
      if (!confirmed) return;
      _doReleaseWhy(c, btn, panel);
    });
  }

  function _doReleaseWhy(c, btn, panel) {
    btn.disabled = true;
    var originalText = btn.textContent;
    btn.textContent = 'Releasing…';

    var body = JSON.stringify({ progress_id: c.progress_id });
    fetch(CFG.api_base + '/form/release-why', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: body,
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        if (!res.ok || res.body.code) {
          btn.disabled = false;
          btn.textContent = originalText;
          window.HDLV2UI.toast('Could not release: ' + (res.body.message || 'Please refresh and try again.'), "error");
          return;
        }
        // Optimistic UI: swap banner to a success state; badge updates on next fetch.
        var banner = panel.querySelector('.hdlv2-action-banner');
        if (banner) {
          banner.classList.add('hdlv2-action-banner-done');
          banner.innerHTML = '<div class="hdlv2-action-banner-text"><strong>WHY released</strong><span>Client has been emailed the Stage 3 invitation.</span></div>';
        }
        c.status = 'stage3_in_progress';
        c.label = 'Stage 3 In Progress';
        c.why_released = 1;
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = originalText;
        window.HDLV2UI.toast('Network error. Please refresh and try again.', "error");
      });
  }

  function loadTab(tab, c, target) {
    target.innerHTML = '<div class="hdlv2-detail-loading">Loading…</div>';
    // v0.47.13 — stamp the rendered report-tab signature (from the SAME
    // cached record the loader renders from) so the poll refresher
    // (maybeRefreshActiveReportTabs) re-renders only on a genuine change.
    if (reportRecordKey(tab)) {
      fetchClientRecord(c).then(function (data) { target._reportSig = reportTabSig(data, tab); });
    }
    // v0.24.0 — journey-order routing. Stage 1/2/3/Final share one cached
    // /dashboard/client-record/{progress_id} fetch, so switching between
    // them is instant after first hit.
    if (tab === 'progress')      return loadProgress(c, target);
    if (tab === 'stage1')        return loadStage1(c, target);
    if (tab === 'stage2')        return loadStage2(c, target);
    if (tab === 'stage3')        return loadStage3(c, target);
    if (tab === 'flight-notes')  return loadFlightNotes(c, target);
    if (tab === 'consultation')  return loadConsultation(c, target);
    if (tab === 'final')         return loadFinal(c, target);
    if (tab === 'flight-plan')   return loadFlightPlan(c, target);
  }

  // v0.24.0 — single cached fetcher for the four journey-stage tabs. Each
  // panel-expand triggers one network call total; subsequent tab switches
  // resolve from the cached promise. Cache scoped per-client object so
  // each chevron-expand starts fresh.
  function fetchClientRecord(c) {
    if (c._record_promise) return c._record_promise;
    if (!c.progress_id) {
      c._record_promise = Promise.resolve(null);
      return c._record_promise;
    }
    c._record_promise = fetch(CFG.api_base + '/dashboard/client-record/' + c.progress_id, {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': CFG.nonce },
    }).then(function (r) { return r.ok ? r.json() : null; })
      .catch(function () { return null; });
    return c._record_promise;
  }

  function loadStage1(c, target) {
    if (!c.progress_id) {
      target.innerHTML = '<div class="hdlv2-detail-empty">No Stage 1 widget submission for this client yet.</div>';
      return;
    }
    fetchClientRecord(c).then(function (data) {
      var s1 = data && data.stage1;
      if (!s1) {
        target.innerHTML = '<div class="hdlv2-detail-empty">Stage 1 data not available.</div>';
        return;
      }
      var capSex = s1.sex ? s1.sex.charAt(0).toUpperCase() + s1.sex.slice(1) : '—';

      // v0.41.19 — 2-col grid. Left: 3 stat cards + 9-question Q&A list.
      // Right: production gauge image (s1.gauge_url is built server-side
      // via HDLV2_Widget_Config::build_gauge_url → QuickChart.io, same URL
      // the client receives by email). No SVG approximation.
      // v0.41.23 — middle stat card now surfaces the Stage 1 Bio Age
      // estimate (round(rate × age)) that the client already sees on
      // their Stage 1 PDF. Chronological age moves to a sub-line so the
      // practitioner can still cross-reference.
      var leftStats = '<div class="hdlv2-st-row">'
        +   '<div class="hdlv2-st-stat"><strong>' + esc(s1.rate != null ? Number(s1.rate).toFixed(2) + '×' : '—') + '</strong><span>Rate of ageing</span></div>'
        +   '<div class="hdlv2-st-stat"><strong>' + esc(s1.bio_age_est != null ? Number(s1.bio_age_est).toFixed(1) : '—') + '</strong><span>Bio age (est.)</span>'
        +     (s1.age != null ? '<small>vs. ' + esc(s1.age) + ' actual</small>' : '')
        +   '</div>'
        +   '<div class="hdlv2-st-stat"><strong>' + esc(capSex) + '</strong><span>Sex</span></div>'
        + '</div>';

      // v0.41.23 pills → v0.46.52 — Stage-1 rows now match Stage 3: the
      // answer text IS the data, coloured by the same severity bands the
      // old "N/5" pill used (≥4 green · 3 amber · ≤2 red), no numeric code.
      // A row whose score is missing/invalid renders plain #111 — the
      // panel never invents a severity.
      function s1Sev(n) {
        if (n == null) return '';
        var v = parseInt(n, 10);
        if (isNaN(v) || v < 1 || v > 5) return '';
        return v >= 4 ? 'high' : (v === 3 ? 'mid' : 'low');
      }

      var rows = [];
      // v0.41.23 — Body shape: silhouette index reads as "#N" (not "N/5",
      // which collided visually with the score-pill "/5" suffix). Q2b
      // code replaced by the questionnaire label resolved server-side
      // (s1.q2b_label — "Mostly middle" / "Hips & thighs" / "Evenly spread").
      // Score pill uses the combined q2_body score (Q2a silhouette +
      // Q2b modifier, clamped 1-5).
      if (s1.q2_silhouette) {
        var bodyTxt = 'silhouette #' + s1.q2_silhouette;
        if (s1.q2b_label) bodyTxt += ' · ' + s1.q2b_label;
        else if (s1.q2_fat_dist) bodyTxt += ' · ' + s1.q2_fat_dist;
        rows.push(['Body shape (Q2)', bodyTxt, s1.q2_score]);
      }
      if (s1.q3_zone2)      rows.push(['Zone-2 cardio (Q3)',  s1.q3_zone2,   s1.q3_score]);
      // v0.41.23 — Q4 label renamed "VO2 / cardio reserve" → "VO2max" so
      // the practitioner panel matches the clinical metric the question
      // proxies for (stair-climbing as a VO2max proxy).
      if (s1.q4_vo2)        rows.push(['VO2max (Q4)',         s1.q4_vo2,     s1.q4_score]);
      if (s1.q5_sts)        rows.push(['Sit-to-stand (Q5)',   s1.q5_sts,     s1.q5_score]);
      if (s1.q6_sleep)      rows.push(['Sleep (Q6)',          s1.q6_sleep,   s1.q6_score]);
      if (s1.q7_smoking)    rows.push(['Smoking (Q7)',        s1.q7_smoking, s1.q7_score]);
      if (s1.q8_social)     rows.push(['Social connection (Q8)', s1.q8_social, s1.q8_score]);
      if (s1.q9_diet)       rows.push(['Diet (Q9)',           s1.q9_diet,    s1.q9_score]);
      var leftList = rows.length
        ? '<div class="hdlv2-fp-block-label">9-question answers</div>'
          + '<ul class="hdlv2-st-list">'
          + rows.map(function (r) {
              var sev = s1Sev(r[2]);
              return '<li><span>' + esc(r[0]) + '</span><span' + (sev ? ' class="sev-' + sev + '"' : '') + '>' + esc(r[1]) + '</span></li>';
            }).join('')
          + '</ul>'
        : '';

      var rightGauge = s1.gauge_url
        ? '<div class="hdlv2-st-gauge-card">'
          +   '<img class="hdlv2-st-gauge-img" src="' + esc(s1.gauge_url) + '" alt="Pace of ageing gauge · ' + esc((s1.rate != null ? Number(s1.rate).toFixed(2) + 'x' : '')) + '">'
          +   '<div class="hdlv2-st-gauge-caption">Pace of ageing &middot; ' + esc(s1.rate != null ? Number(s1.rate).toFixed(2) + '× chronological' : 'pending') + '</div>'
          + '</div>'
        : '<div class="hdlv2-st-gauge-card"><div class="hdlv2-detail-empty">Gauge image unavailable.</div></div>';

      // Stage-1 quick-insight PDF download (the same file emailed to the
      // client once the stage1-callback has stored one). Renders only when a
      // pdf_url is present — presented as the same document card the Stage-3
      // Draft Report + Final tabs use, so every tab's PDF action reads alike.
      var s1Pdf = s1.pdf_url
        ? '<div class="hdlv2-dl-doc hdlv2-dl-doc--stack">'
          +   '<div class="hdlv2-dl-doc-ico">' + docIconSVG() + '</div>'
          +   '<div class="hdlv2-dl-doc-body">'
          +     '<span class="hdlv2-dl-doc-title">Quick Insight Report</span>'
          +     '<span class="hdlv2-dl-doc-meta">Stage-1 quick insight &middot; the same PDF emailed to the client</span>'
          +   '</div>'
          +   '<a class="hdlv2-dl-btn" href="' + esc(s1.pdf_url) + '" target="_blank" rel="noopener" download>' + flightNotesIconSVG() + '<span>Download PDF</span></a>'
          + '</div>'
        : '';

      target.innerHTML = '<div class="hdlv2-st-card">'
        + '<div class="hdlv2-st-meta">Quick insight &middot; Completed ' + esc(formatDate(s1.completed_at)) + '</div>'
        + '<div class="hdlv2-s1-grid">'
        +   '<div>' + leftStats + leftList + '</div>'
        +   '<div>' + rightGauge + '</div>'
        + '</div>'
        + s1Pdf
        + '</div>';
    });
  }

  function loadStage2(c, target) {
    if (!c.progress_id) {
      target.innerHTML = '<div class="hdlv2-detail-empty">No client record yet.</div>';
      return;
    }
    fetchClientRecord(c).then(function (data) {
      var s2 = data && data.stage2;
      if (!s2) {
        target.innerHTML = '<div class="hdlv2-detail-empty">Client hasn’t completed Stage 2 (WHY profile) yet.</div>';
        return;
      }

      // B4 — trimmed Stage-2 panel: show only the two short WHY summaries
      // (distilled_why + ai_reformulation, both already AI-generated → no new
      // AI call). The structured detail (key people / motivations / fears /
      // vision) now lives in the WHY PDF + the consultation page.
      var releaseTag = s2.released
        ? ' &middot; <span style="color:#047857;font-weight:600">released</span>'
        : ' &middot; <span style="color:#d97706;font-weight:600">awaiting WHY release</span>';

      var hero = s2.distilled_why
        ? '<div class="hdlv2-why-hero">&ldquo;' + esc(s2.distilled_why) + '&rdquo;</div>'
        : '<div class="hdlv2-detail-empty">No distilled WHY recorded.</div>';

      var reform = s2.ai_reformulation
        ? '<div class="hdlv2-vision-card"><div class="hdlv2-vision-card-label">WHY &middot; summary</div>' + esc(s2.ai_reformulation) + '</div>'
        : '';

      // Working link, never a dead button: the real WHY PDF once the D-2
      // callback has stored one — v0.46.51, presented as the same document
      // card as the Stage-3/Final tabs so every tab's PDF action reads
      // identically. Otherwise a link to the consultation page (the
      // on-screen surface that shows the full WHY detail).
      var detailLink = s2.pdf_url
        ? '<div class="hdlv2-dl-doc hdlv2-dl-doc--stack">'
          +   '<div class="hdlv2-dl-doc-ico">' + docIconSVG() + '</div>'
          +   '<div class="hdlv2-dl-doc-body">'
          +     '<span class="hdlv2-dl-doc-title">WHY Profile</span>'
          +     '<span class="hdlv2-dl-doc-meta">Stage-2 WHY &middot; practitioner copy &mdash; not sent to the client</span>'
          +   '</div>'
          +   '<a class="hdlv2-dl-btn" href="' + esc(s2.pdf_url) + '" target="_blank" rel="noopener" download>' + flightNotesIconSVG() + '<span>Download PDF</span></a>'
          + '</div>'
        : (CFG.consultation_url
            // v0.46.52 — same destination as the Consultation tab's links, so
            // same convention: new tab + "↗" + title (was same-tab "→").
            ? '<a class="hdlv2-detail-deeplink" href="' + esc(CFG.consultation_url + '?progress_id=' + c.progress_id) + '" target="_blank" rel="noopener" title="Opens in a new tab">View full WHY in consultation &#8599;</a>'
            : '');

      target.innerHTML = '<div class="hdlv2-st-card">'
        + '<div class="hdlv2-st-meta">WHY profile &middot; Completed ' + esc(formatDate(s2.completed_at)) + releaseTag + '</div>'
        + hero
        + reform
        + detailLink
        + '</div>';
    });
  }

  function loadStage3(c, target) {
    if (!c.progress_id) {
      target.innerHTML = '<div class="hdlv2-detail-empty">No client record yet.</div>';
      return;
    }
    fetchClientRecord(c).then(function (data) {
      var s3 = data && data.stage3;
      if (!s3 || !s3.completed_at) {
        target.innerHTML = '<div class="hdlv2-detail-empty">Client hasn’t completed Stage 3 (full health detail) yet.</div>';
        return;
      }

      // B5 — top stat strip, then the six row-data groups (2 body lists +
      // 4 score categories: Activity & Body / Sleep, Stress & Social /
      // Diet & Lifestyle / Vitals) packed into a 4-column grid so the
      // panel fits a laptop without scrolling (2-col tablet, 1-col
      // mobile, never horizontal scroll). Then Section 6 Health
      // Background block (v0.38.0 — family_history, medications,
      // existing_conditions — surfaced via /dashboard/client-record).
      var topStats = '<div class="hdlv2-s3-stats">'
        +   '<div class="hdlv2-st-stat"><strong>' + esc(s3.rate != null ? Number(s3.rate).toFixed(2) + '×' : '—') + '</strong><span>Rate of ageing</span></div>'
        +   '<div class="hdlv2-st-stat"><strong>' + esc(s3.bio_age != null ? Number(s3.bio_age).toFixed(1) : '—') + '</strong><span>Biological age</span></div>'
        +   '<div class="hdlv2-st-stat"><strong>' + esc(s3.bmi     != null ? s3.bmi          : '—') + '</strong><span>BMI</span></div>'
        + '</div>';

      var bodyLeft = [], bodyRight = [];
      if (s3.height != null) bodyLeft.push(['Height', s3.height + ' cm']);
      if (s3.weight != null) bodyLeft.push(['Weight', s3.weight + ' kg']);
      if (s3.waist  != null) bodyLeft.push(['Waist',  s3.waist  + ' cm']);
      if (s3.hip    != null) bodyLeft.push(['Hip',    s3.hip    + ' cm']);
      if (s3.whr    != null) bodyRight.push(['Waist-to-Hip', s3.whr]);
      if (s3.whtr   != null) bodyRight.push(['Waist-to-Height', s3.whtr]);
      if (s3.bp_systolic && s3.bp_diastolic) bodyRight.push(['Blood pressure', s3.bp_systolic + '/' + s3.bp_diastolic + ' mmHg']);
      if (s3.resting_hr != null) bodyRight.push(['Resting heart rate', s3.resting_hr + ' bpm']);

      // Raw-measurement group — same card shape as scoreGroup() so all six
      // groups read uniformly in the grid; values are plain (no /5 pill).
      function kvGroup(title, rows) {
        if (!rows.length) return '';
        return '<div class="hdlv2-score-group">'
          + '<h4>' + esc(title) + '</h4>'
          + rows.map(function (r) {
              return '<div class="hdlv2-score-row"><span>' + esc(r[0]) + '</span><span class="hdlv2-score-val">' + esc(r[1]) + '</span></div>';
            }).join('')
          + '</div>';
      }
      // Pack groups into explicit column wrappers. A group that sits BELOW
      // another in the same column (the 6-groups-into-4-columns overflow)
      // gets .is-stacked → bold heading so the boundary is unmistakable.
      function packCols(colDefs) {
        return colDefs.map(function (groups) {
          var present = groups.filter(Boolean);
          if (!present.length) return '';
          return '<div class="hdlv2-s3-col">'
            + present.map(function (g, i) {
                return i === 0 ? g : g.replace('"hdlv2-score-group"', '"hdlv2-score-group is-stacked"');
              }).join('')
            + '</div>';
        }).filter(Boolean).join('');
      }

      // Score groups — pulled from server scores map. Each group renders
      // only the rows whose score is present, so older clients (pre-v0.38)
      // still produce a clean grid without empty placeholders.
      //
      // v0.41.22 — scores are 0-5 (HDLV2_Rate_Calculator::clamp_score),
      // NOT 0-100. The mockup hard-coded illustrative 60-90 values that
      // misled the prior threshold (≥70 green / 50-69 amber / <50 red),
      // which rendered every real score red. Canonical bands per the
      // Final Report key (class-hdlv2-final-report.php:1227):
      //   5/5 green · 4/5 green · 3/5 amber · 2/5 red · 1/5 red.
      // Display includes the "/5" suffix so the scale is explicit —
      // matches the Final PDF, Claude prompts, and AI service convention.
      function sevCls(n) {
        var v = Math.round(n);
        return v >= 4 ? 'high' : (v === 3 ? 'mid' : 'low');
      }
      function sevPill(n) {
        return '<span class="hdlv2-score-pill ' + sevCls(n) + '">' + Math.round(n) + '<span class="hdlv2-score-pill-max">/5</span></span>';
      }
      // v0.46.50 — derived metrics (BMI/WHR/WHtR/BP/HR scores) have no chosen
      // answer; their row shows the client's real measured value instead, so
      // every row reads as actual data, never an abstract code.
      var derivedVals = {
        bmiScore:           s3.bmi  != null ? String(s3.bmi)  : '',
        whrScore:           s3.whr  != null ? String(s3.whr)  : '',
        whtrScore:          s3.whtr != null ? String(s3.whtr) : '',
        bloodPressureScore: (s3.bp_systolic && s3.bp_diastolic) ? s3.bp_systolic + '/' + s3.bp_diastolic : '',
        heartRateScore:     s3.resting_hr != null ? s3.resting_hr + ' bpm' : ''
      };
      function scoreGroup(title, pairs) {
        var rows = [];
        if (s3.scores && typeof s3.scores === 'object') {
          pairs.forEach(function (pair) {
            var v = s3.scores[pair[0]];
            if (v == null) return;
            var n = parseFloat(v);
            if (isNaN(n)) return;
            // v0.46.50 — the row's data IS the client's actual answer (server
            // `score_words`, resolved through the shared s3_label/S3_OPTIONS
            // map), coloured by the same severity bands the old pill used
            // (≥4 green · 3 amber · ≤2 red — Final Report key, class-hdlv2-
            // final-report.php:1227) so good/bad reads at a glance with no
            // 0-5 code in sight (Quim 2026-06-11: "show the text and don't
            // show the value"). Derived metrics show the measured value via
            // derivedVals. A legacy/skipped row with neither falls back to
            // the numeric pill rather than rendering empty.
            var word = (s3.score_words && s3.score_words[pair[0]]) || derivedVals[pair[0]] || '';
            rows.push('<div class="hdlv2-score-row"><span>' + esc(pair[1]) + '</span>'
              + (word
                  ? '<span class="hdlv2-score-answer sev-' + sevCls(n) + '">' + esc(word) + '</span>'
                  : sevPill(n))
              + '</div>');
          });
        }
        if (!rows.length) return '';
        return '<div class="hdlv2-score-group">'
          + '<h4>' + esc(title) + '</h4>'
          + rows.join('')
          + '</div>';
      }
      // B5 — six groups → 4 height-balanced columns. Body first (reading
      // order preserved); the two short groups (Ratios & vitals, Vitals)
      // stack at the bottom of cols 1 and 3 with bold headings.
      var cols = packCols([
        [ kvGroup('Body measurements', bodyLeft),
          kvGroup('Ratios & vitals',   bodyRight) ],
        [ scoreGroup('Activity & body', [
            ['physicalActivity',   'Physical activity'],
            ['sitToStand',         'Sit-to-stand'],
            ['breathHold',         'Breath hold'],
            ['balance',            'Balance'],
            ['skinElasticity',     'Skin elasticity'],
            ['bmiScore',           'BMI'],
            ['whrScore',           'WHR'],
            ['whtrScore',          'WHtR'],
          ]) ],
        [ scoreGroup('Sleep, stress & social', [
            ['sleepDuration',      'Sleep duration'],
            ['sleepQuality',       'Sleep quality'],
            ['stressLevels',       'Stress levels'],
            ['socialConnections',  'Social connections'],
            ['cognitiveActivity',  'Cognitive activity'],
          ]),
          scoreGroup('Vitals', [
            ['bloodPressureScore', 'Blood pressure'],
            ['heartRateScore',     'Heart rate'],
          ]) ],
        [ scoreGroup('Diet & lifestyle', [
            ['dietQuality',        'Diet quality'],
            ['alcoholConsumption', 'Alcohol'],
            ['smokingStatus',      'Smoking'],
            ['sunlightExposure',   'Sunlight'],
            ['supplementIntake',   'Supplements'],
            ['dailyHydration',     'Hydration'],
          ]) ],
      ]);

      var gridBlock = cols
        ? '<div class="hdlv2-fp-block-label">Body composition, vitals &amp; 21-metric scores &middot; grouped</div>'
          + '<div class="hdlv2-s3-grid">' + cols + '</div>'
        : '';

      // Section 6 — Health Background (v0.38.0). Three optional free-text
      // fields. Only render the block when at least one is non-empty so a
      // pre-v0.38 client (no background data) doesn't get a sea of "Not
      // provided" placeholders. Each card escapes via esc() — practitioner
      // text could contain markup, never trust raw.
      function hbCard(title, value, iconSvg) {
        var trimmed = (value || '').trim();
        return '<div class="hdlv2-hb-card' + (trimmed ? '' : ' is-empty') + '">'
          + '<div class="hdlv2-hb-card-head">' + iconSvg + '<span>' + esc(title) + '</span></div>'
          + '<p>' + (trimmed ? esc(trimmed) : 'Not provided') + '</p>'
          + '</div>';
      }
      var iconHistory = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11l-3 3-3-3"/><path d="M19 14V5"/></svg>';
      var iconMeds    = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.5 20H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h11l5 5v6"/><path d="M14 2v6h6"/><path d="M16 19h6"/><path d="M19 16v6"/></svg>';
      var iconCond    = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>';

      var fh   = (s3.family_history      || '').trim();
      var meds = (s3.medications         || '').trim();
      var cond = (s3.existing_conditions || '').trim();
      var hbBlock = (fh || meds || cond)
        ? '<h4 class="hdlv2-hb-section-h">Health Background<span class="hdlv2-hb-note">Optional &middot; client free-text &middot; not scored &middot; woven into Claude draft</span></h4>'
          + '<div class="hdlv2-hb-grid">'
          +   hbCard('Family history',                  fh,   iconHistory)
          +   hbCard('Current medications',             meds, iconMeds)
          +   hbCard('Existing conditions / diagnoses', cond, iconCond)
          + '</div>'
        : '';

      // D-2 — draft-report PDF download (the post-Stage-3 draft, same file
      // emailed to the client). Renders only when the callback has stored one
      // (honest pointer — no dead button). v0.46.50 — presented as the same
      // document card the Final tab uses, so the action sits inside the panel
      // layout instead of floating under the grid as a bare ghost pill.
      var draftPdf = s3.pdf_url
        ? '<div class="hdlv2-dl-doc hdlv2-dl-doc--stack">'
          +   '<div class="hdlv2-dl-doc-ico">' + docIconSVG() + '</div>'
          +   '<div class="hdlv2-dl-doc-body">'
          +     '<span class="hdlv2-dl-doc-title">Draft Report</span>'
          +     '<span class="hdlv2-dl-doc-meta">Post-Stage-3 draft &middot; the same PDF emailed to the client</span>'
          +   '</div>'
          +   '<a class="hdlv2-dl-btn" href="' + esc(s3.pdf_url) + '" target="_blank" rel="noopener" download>' + flightNotesIconSVG() + '<span>Download PDF</span></a>'
          + '</div>'
        : '';
      target.innerHTML = '<div class="hdlv2-st-card">'
        + '<div class="hdlv2-st-meta">Full detail &middot; 21 measurements across 5 sections &middot; Completed ' + esc(formatDate(s3.completed_at)) + '</div>'
        + topStats
        + gridBlock
        + hbBlock
        + draftPdf
        + '</div>';
    });
  }

  function loadFinal(c, target) {
    if (!c.progress_id) {
      target.innerHTML = '<div class="hdlv2-detail-empty">No client record yet.</div>';
      return;
    }
    fetchClientRecord(c).then(function (data) {
      var f = data && data.final;
      if (!f || !f.has_report) {
        target.innerHTML = '<div class="hdlv2-detail-empty">'
          + 'Trajectory Plan hasn’t been generated yet. Open the Consultation tab and click <strong>Generate Trajectory Plan</strong> after reviewing the draft.'
          + '</div>';
        return;
      }
      // v0.46.31 (A4, UI-only) — the Final tab offers the PDF, not the on-screen
      // report. Matthew clicked "Final" expecting a PDF and got the cached
      // on-screen report (the old `f.view_url` deep-link below), which confused
      // him. We drop that deep-link and the verbose blurb. "Download PDF" renders
      // ONLY when a real PDF URL exists. Today `reports.pdf_url` is never written
      // (no report-PDF callback exists — only the Flight Plan has one), so
      // `f.pdf_url` is absent and we show a short honest pointer instead of a
      // dead button (decision D-2 = (b)). If/when a Make→WP report-PDF callback
      // is added (D-2 = (a)), the server simply includes `pdf_url` in the
      // /dashboard/client-record `final` payload and this button lights up with
      // no further change here.
      // v0.46.49 — present the report as a document card (Poppins title +
      // meta + primary teal download action) instead of a bare ghost link
      // that read unfinished. The honest-pointer rule is unchanged: the
      // button renders ONLY when a real PDF exists (D-2(b)); otherwise the
      // card carries the emailed-PDF pointer with no dead button.
      var html = '<div class="hdlv2-st-card">'
        + '<div class="hdlv2-dl-doc">'
        +   '<div class="hdlv2-dl-doc-ico">' + docIconSVG() + '</div>'
        +   '<div class="hdlv2-dl-doc-body">'
        +     '<span class="hdlv2-dl-doc-title">Trajectory Plan</span>'
        +     '<span class="hdlv2-dl-doc-meta">Final client report &middot; Generated ' + esc(formatDate(f.generated_at)) + '</span>'
        +     (f.pdf_url ? '' : '<span class="hdlv2-dl-doc-meta">The PDF has been emailed to ' + esc(c.name || 'the client') + ' and to you.</span>')
        +   '</div>'
        +   (f.pdf_url
              ? '<a class="hdlv2-dl-btn" href="' + esc(f.pdf_url) + '" target="_blank" rel="noopener" download>' + flightNotesIconSVG() + '<span>Download PDF</span></a>'
              : '')
        + '</div>'
        + '</div>';
      target.innerHTML = html;
    });
  }

  // B10+B11 — Flight Notes journey tab (replaces the old head button). The
  // button re-uses downloadFlightNotes() → GET /flight-notes/pdf: instant
  // cached PDFMonkey URL on a transient hit (md5 of payload + AI inputs),
  // job + poll only on a genuine data change. No new generation logic, no
  // new Claude path. fnPollJob stops cleanly if the tab is switched away
  // mid-poll (btn.isConnected).
  function loadFlightNotes(c, target) {
    if (deriveJourneyState(c)[2] !== 'done') {
      target.innerHTML = '<div class="hdlv2-detail-empty">Flight Notes are prepared once Stage 3 is complete.</div>';
      return;
    }
    // B11.1 → v0.46.51 — same document card as the Stage-2/3/Final download
    // surfaces (icon tile + title + meta + solid teal action) so every tab's
    // PDF action reads identically. The button keeps the .hdlv2-fn-btn
    // loading machinery (content/spinner swap + downloadFlightNotes wiring);
    // --solid restyles it to match the cards' primary download button.
    target.innerHTML = '<div class="hdlv2-st-card">'
      + '<div class="hdlv2-dl-doc">'
      +   '<div class="hdlv2-dl-doc-ico">' + docIconSVG() + '</div>'
      +   '<div class="hdlv2-dl-doc-body">'
      +     '<span class="hdlv2-dl-doc-title">Flight Consultation Notes</span>'
      +     '<span class="hdlv2-dl-doc-meta">5-page print aid for your consultation &middot; re-uses the prepared PDF, regenerates only if the client&rsquo;s data changed</span>'
      +   '</div>'
      +   '<button type="button" class="hdlv2-fn-btn hdlv2-fn-btn--solid" title="Download this client\'s FLIGHT consultation notes (PDF)" aria-label="Download ' + esc(c.name) + ' flight consultation notes">'
      +     '<span class="hdlv2-fn-content">' + flightNotesIconSVG() + '<span class="hdlv2-fn-label">Download PDF</span></span>'
      +     '<span class="hdlv2-fn-loading"><span class="hdlv2-fn-ring" aria-hidden="true"></span><span>Preparing&hellip;</span></span>'
      +   '</button>'
      + '</div>'
      + '</div>';
    var btn = target.querySelector('.hdlv2-fn-btn');
    if (btn) {
      btn.addEventListener('click', function () { downloadFlightNotes(c, btn); });
    }
  }

  // v0.46.59 — WFP-tab head card. The stacked-textarea editor panel is
  // GONE (editor v2 lives inline on the grid — hdlv2-flight-plan.js
  // editable mode). "Edit plan" toggles the renderer's pencils/+ on and
  // off via the mount instance. Download pill = the single download
  // affordance on this tab (the renderer's own control is suppressed).
  function renderFlightPlanToolbar(c, bar, getInstance) {
    fetch(CFG.api_base + '/flight-plan/' + c.user_id + '/preview', {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': CFG.nonce }
    }).then(function (r) { return r.ok ? r.json() : null; })
      .then(function (res) {
        var plan = res && res.plan;
        if (!plan) return;
        bar.innerHTML = '<div class="hdlv2-st-card"><div class="hdlv2-dl-doc">'
          + '<div class="hdlv2-dl-doc-ico">' + docIconSVG() + '</div>'
          + '<div class="hdlv2-dl-doc-body">'
          +   '<span class="hdlv2-dl-doc-title">Weekly Flight Plan &middot; Week ' + esc(String(plan.week_number || '')) + '</span>'
          +   '<span class="hdlv2-dl-doc-meta">' + esc(formatDate(plan.week_start)) + ' &ndash; ' + esc(formatDate(plan.date_range_end)) + ' &middot; edits update the client\u2019s view and regenerate the PDF (no email)</span>'
          + '</div>'
          + (plan.pdf_url
              ? '<a class="hdlv2-dl-btn" href="' + esc(plan.pdf_url) + '" target="_blank" rel="noopener" download>' + itshoverDownloadSVG() + '<span>Download PDF</span></a>'
              : '<span class="hdlv2-dl-doc-meta">PDF preparing\u2026 it appears here after the next render.</span>')
          + '<button type="button" class="hdlv2-dl-btn hdlv2-fpe-toggle" aria-pressed="false">Edit plan</button>'
          + '</div></div>';
        var toggle = bar.querySelector('.hdlv2-fpe-toggle');
        toggle.addEventListener('click', function () {
          var inst = getInstance();
          if (!inst) return;
          var on = inst.setEditMode(!inst.isEditMode());
          toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
          toggle.textContent = on ? 'Done editing' : 'Edit plan';
          toggle.classList.toggle('is-on', on);
        });
      })
      .catch(function () { /* toolbar is best-effort; the renderer still mounts */ });
  }

  // v0.46.59 — ported from itshover.com/icons/download-icon
  // (github.com/itshover/itshover, Apache-2.0); geometry 1:1, CSS-approximated motion.
  function itshoverDownloadSVG() {
    return '<svg class="hdlv2-iho-dl" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>'
      + '<g class="hdlv2-iho-dl-arrow"><path d="M12 15V3"/><path d="m7 10 5 5 5-5"/></g></svg>';
  }

  function loadFlightPlan(c, target) {
    // Reuse the client-side renderer (hdlv2-flight-plan.js) so the
    // practitioner sees and interacts with the same grid, ticks, and
    // adherence card as the client. Practitioner ticks share the same
    // /flight-plan/tick endpoint + calculate_adherence pipeline \u2014
    // bidirectional sync with zero new code paths. Server-side auth
    // is enforced by validate_client_access -> practitioner_owns_client.
    if (!window.HDLV2_FlightPlan || typeof window.HDLV2_FlightPlan.mount !== 'function') {
      target.innerHTML = '<div class="hdlv2-detail-empty">Flight plan renderer not loaded.</div>';
      return;
    }
    target.innerHTML = '';
    target.classList.add('hdlv2-wfp-dash'); // v0.46.59 — B-series reskin scope

    // v0.46.59 — head card above the shared renderer; inline editor v2
    // (pencils + per-band "+") lives IN the grid via editable_controls.
    // suppress_download: the head-card pill is this tab's single download
    // affordance. Same additive-revision / live-client / direct-re-render
    // semantics; zero emails on any edit path by construction.
    var fpeBar = document.createElement('div');
    target.appendChild(fpeBar);

    var mountEl = document.createElement('div');
    mountEl.id = 'hdlv2-flight-plan';
    mountEl.className = 'hdlv2-assessment-root';
    target.appendChild(mountEl);
    var fpInstance = window.HDLV2_FlightPlan.mount(mountEl, {
      editable_controls: true,
      suppress_download: true,
      cfg: {
        api_base: CFG.api_base + '/flight-plan',
        nonce: CFG.nonce,
        client_id: c.user_id
      }
    });
    // v0.46.60 — keep a handle so the digest poll can refresh in place
    // (see maybeRefreshActiveFlightPlanTabs) rather than re-mounting.
    target._hdlv2FpInstance = fpInstance;
    renderFlightPlanToolbar(c, fpeBar, function () { return fpInstance; });
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
    // W11 — automation-tier branch. Flag check + tier check.
    // loadAutoConsultation handles BOTH the read-only "Self-reported data"
    // block (safety-valve off — the common case) and the editor-with-banner
    // variant (safety-valve on — held for practitioner review).
    if (CFG.automation_tier_enabled && c.tier === 'automation') {
      return loadAutoConsultation(c, target);
    }
    // Iris add-on: launch section at the TOP of the Consultation tab,
    // entitlement-gated. Returns '' when the flag is off OR the practitioner
    // is not entitled, so a non-add-on practitioner sees no iris trace here
    // (Rule-0). v1 = launch-link only (embedded capture is Phase 2).
    var iris = irisConsultationHtml(c);
    var html;
    if (!c.progress_id) {
      html = '<div class="hdlv2-detail-empty">No consultation record yet. The client needs to complete Stage 3 of the assessment first.</div>';
    } else {
      var url = CFG.consultation_url + '?progress_id=' + c.progress_id;
      // v0.24.3 — the post-Final read-only fork is gone. Both states
      // (pre-Final and post-Final) deeplink to the editable consultation
      // page. Practitioner can re-enter post-Final to edit health data,
      // regenerate the Final report, and reset the Flight Plan to Week 1
      // (edit-as-reset rule).
      // B7+B8 → v0.46.53 — same document card as every other tab action (icon
      // tile + Poppins title + meta + solid teal button right), replacing the
      // lone ghost pill in an empty box. Still opens in a new tab (keeps
      // /clients/ open); the "↗" in the label signals that. The edit-as-reset
      // side effect is too important for hover-only (no hover on touch) → it
      // lives in the meta line, always visible.
      if (c.has_final_report) {
        html = '<div class="hdlv2-st-card">'
          + '<div class="hdlv2-dl-doc">'
          +   '<div class="hdlv2-dl-doc-ico">' + docIconSVG() + '</div>'
          +   '<div class="hdlv2-dl-doc-body">'
          +     '<span class="hdlv2-dl-doc-title">Consultation Notes</span>'
          +     '<span class="hdlv2-dl-doc-meta">Edit health data or notes &middot; saving regenerates the report &amp; Flight Plan</span>'
          +   '</div>'
          +   '<a class="hdlv2-dl-btn" href="' + esc(url) + '" target="_blank" rel="noopener" title="Opens in a new tab"><span>Edit consultation&nbsp;&#8599;</span></a>'
          + '</div>'
          + '</div>';
      } else {
        html = '<div class="hdlv2-st-card">'
          + '<div class="hdlv2-dl-doc">'
          +   '<div class="hdlv2-dl-doc-ico">' + docIconSVG() + '</div>'
          +   '<div class="hdlv2-dl-doc-body">'
          +     '<span class="hdlv2-dl-doc-title">Consultation Notes</span>'
          +     '<span class="hdlv2-dl-doc-meta">Record your consultation &middot; opens the editor in a new tab</span>'
          +   '</div>'
          +   '<a class="hdlv2-dl-btn" href="' + esc(url) + '" target="_blank" rel="noopener" title="Opens in a new tab"><span>Record consultation&nbsp;&#8599;</span></a>'
          + '</div>'
          + '</div>';
      }
    }
    target.innerHTML = iris + html;
    bindIrisConsultOpen(target);
  }

  // ── W11 — Automation-tier Consultation tab ──
  //
  // Replaces the practitioner-led Record/Edit-consultation card with a
  // read-only "Self-reported data" block when the client is on the
  // automation tier. Two states:
  //   • Submitted: render the saved addendum (timestamp + body)
  //   • Not yet submitted: render a "Awaiting self-report" empty state
  // Safety-valve mode (hdlv2_automation_hold_for_review === true): render
  // the existing consultation editor deeplink with a banner instructing
  // the practitioner to finalise.
  //
  // Audio playback + final-report markdown rendering deferred to a
  // follow-up commit (Make.com Route 1 stamps the PDF + emails it to the
  // client directly; the practitioner read-only view doesn't strictly
  // need either embedded). See V2-FOLLOWUPS.md.
  function loadAutoConsultation(c, target) {
    var auto = c && c.auto_consultation;

    if (CFG.automation_hold_for_review) {
      // Safety-valve ON: practitioner reviews + finalises through the
      // existing editor. We still surface the self-reported submission as
      // a banner so they know what's waiting and when it arrived.
      var url = c.progress_id ? (CFG.consultation_url + '?progress_id=' + c.progress_id) : '';
      var bannerText = auto
        ? 'Self-reported on ' + esc(auto.submitted_at) + ' — review and finalise to send.'
        : 'Automation-tier client. Their submission will appear here for your review.';
      var bodyHtml = auto
        ? '<div class="hdlv2-auto-consult-body">' + esc(auto.body_text) + '</div>'
        : '';
      var deeplink = url
        ? '<a class="hdlv2-detail-deeplink" href="' + esc(url) + '">Open consultation →</a>'
        : '';
      target.innerHTML = '<div class="hdlv2-auto-safety-banner">' + bannerText + '</div>'
        + '<div class="hdlv2-auto-consult-card">'
        + '<h3>Self-reported data</h3>'
        + bodyHtml
        + deeplink
        + '</div>';
      return;
    }

    // Safety-valve OFF (the common case): read-only Self-reported data block.
    if (!auto) {
      target.innerHTML = '<div class="hdlv2-auto-consult-card">'
        + '<h3>Awaiting self-report</h3>'
        + '<p class="hdlv2-auto-consult-empty">'
        + 'This automation-tier client has been provisioned but has not yet submitted their self-reported answers. '
        + 'They\'ll receive their Trajectory Plan automatically once they complete the assessment and submit.'
        + '</p>'
        + '</div>';
      return;
    }

    // Submitted — render the saved addendum as a read-only block.
    target.innerHTML = '<div class="hdlv2-auto-consult-card">'
      + '<h3>Self-reported data</h3>'
      + '<p class="hdlv2-auto-consult-meta">Submitted ' + esc(auto.submitted_at) + '</p>'
      + '<div class="hdlv2-auto-consult-body">' + esc(auto.body_text) + '</div>'
      + '<p class="hdlv2-auto-consult-footer">Final report sent automatically. No practitioner finalisation required.</p>'
      + '</div>';
  }

  // ── Red-flag status filter chips (All / Needs attention) ──
  //
  // Gated on CFG.redflag_scan_enabled. Mounted once per render above
  // .clients-table. Idempotent. No-op when the flag is off.
  // Filter state persists in state.statusFilter across re-renders.
  function mountStatusFilter() {
    if (!CFG.redflag_scan_enabled) return;
    if (!state.table || !state.table.parentNode) return;
    if (document.getElementById('hdlv2-status-filter')) return;

    var wrap = document.createElement('div');
    wrap.id = 'hdlv2-status-filter';
    wrap.className = 'hdlv2-filter-bar';
    wrap.innerHTML = '<span class="hdlv2-filter-label">Show:</span>'
      + '<button type="button" class="hdlv2-filter-chip current" data-status="all">All clients</button>'
      + '<button type="button" class="hdlv2-filter-chip" data-status="needs_attention">Needs attention</button>';

    state.table.parentNode.insertBefore(wrap, state.table);

    wrap.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('.hdlv2-filter-chip') : null;
      if (!btn) return;
      state.statusFilter = btn.dataset.status || 'all';
      wrap.querySelectorAll('.hdlv2-filter-chip').forEach(function (chip) {
        if (chip.dataset.status === state.statusFilter) { chip.classList.add('current'); }
        else { chip.classList.remove('current'); }
      });
      applyFilters();
    });
    applyFilters();
  }

  // ── W11 — Source filter chips (All / Practitioner / Self-reported) ──
  //
  // Mounted once per render above .clients-table when the feature flag is
  // on. Idempotent — checks for an existing chip cluster before injecting.
  // No-op when the flag is off (caller already guards, this is belt+braces).
  // Filter state persists in state.sourceFilter across re-renders.
  function mountSourceFilter() {
    if (!CFG.automation_tier_enabled) return;
    if (!state.table || !state.table.parentNode) return;
    if (document.getElementById('hdlv2-source-filter')) return;

    var wrap = document.createElement('div');
    wrap.id = 'hdlv2-source-filter';
    wrap.className = 'hdlv2-source-filter';
    wrap.innerHTML = '<span class="hdlv2-source-filter-label">Source:</span>'
      + '<button type="button" class="hdlv2-source-chip current" data-source="all">All</button>'
      + '<button type="button" class="hdlv2-source-chip" data-source="practitioner">Practitioner</button>'
      + '<button type="button" class="hdlv2-source-chip" data-source="automation">Self-reported</button>';

    state.table.parentNode.insertBefore(wrap, state.table);

    wrap.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('.hdlv2-source-chip') : null;
      if (!btn) return;
      var source = btn.dataset.source || 'all';
      state.sourceFilter = source;
      // Update chip current-state.
      wrap.querySelectorAll('.hdlv2-source-chip').forEach(function (chip) {
        if (chip.dataset.source === source) {
          chip.classList.add('current');
        } else {
          chip.classList.remove('current');
        }
      });
      applySourceFilter();
    });

    // Apply current filter on mount (no-op if state.sourceFilter is unset
    // or 'all'); covers the case where a re-render happens after the user
    // already picked a non-default filter.
    applySourceFilter();
  }

  function applyFilters() {
    var source = state.sourceFilter || 'all';
    var status = state.statusFilter || 'all';
    var stage   = state.stageFilter || 'any';
    var quiet   = state.quietFilter || 'any';
    var quietMs = quiet === 'any' ? 0 : parseInt(quiet, 10) * 86400000;
    var now     = Date.now();
    if (!state.tbody) return;
    var rows = state.tbody.querySelectorAll('tr.client-row');
    rows.forEach(function (row) {
      var rowSource = row.dataset.source || 'practitioner';
      var rowStatus = row.dataset.status || '';
      var rowStage  = row.dataset.stalledStage || '';
      var rowLast   = row.dataset.stalledLast ? parseInt(row.dataset.stalledLast, 10) : 0;
      var show = ((source === 'all') || (rowSource === source))
              && ((status === 'all') || (rowStatus === status))
              && ((stage === 'any') || (rowStage === stage))
              && ((quiet === 'any') || (rowLast > 0 && (now - rowLast) >= quietMs));
      row.style.display = show ? '' : 'none';
      // If this row has a follow-up detail panel mounted (expanded state),
      // hide that too so the filter doesn't leave orphan panels visible.
      var next = row.nextSibling;
      if (next && next.nodeType === 1 && next.classList && next.classList.contains('hdlv2-detail-row')) {
        next.style.display = show ? '' : 'none';
      }
    });
  }
  function applySourceFilter() { applyFilters(); }

  // ── Stalled-leads targeting filter (Stage + Quiet) ──
  //
  // Gated on CFG.stalled_filter_enabled. Mounted once per render above
  // .clients-table (id-guarded, idempotent). No-op when the flag is off.
  // Two chip groups feed the unified applyFilters() (ANDed with Source +
  // Needs-attention). Matthew: "done stage one and haven't responded in two weeks."
  // Reuses the existing .hdlv2-filter-* chip theme.
  function mountStalledFilter() {
    if (!CFG.stalled_filter_enabled) return;
    if (!state.table || !state.table.parentNode) return;
    if (document.getElementById('hdlv2-stalled-filter')) return;

    var wrap = document.createElement('div');
    wrap.id = 'hdlv2-stalled-filter';
    wrap.className = 'hdlv2-stalled-bar';
    wrap.innerHTML = '<span class="hdlv2-stalled-title">Stalled leads</span>'
      + '<label class="hdlv2-stalled-field"><span class="hdlv2-stalled-flabel">Stage reached</span>'
      +   '<select class="hdlv2-stalled-select" data-filter="stage" aria-label="Filter by stage reached">'
      +     '<option value="any">Any stage</option>'
      +     '<option value="1">Stage 1 only</option>'
      +     '<option value="2">Stage 2</option>'
      +   '</select></label>'
      + '<label class="hdlv2-stalled-field"><span class="hdlv2-stalled-flabel">Last reply</span>'
      +   '<select class="hdlv2-stalled-select" data-filter="quiet" aria-label="Filter by time since last reply">'
      +     '<option value="any">Any time</option>'
      +     '<option value="14">Over 2 weeks ago</option>'
      +     '<option value="28">Over 4 weeks ago</option>'
      +   '</select></label>';

    state.table.parentNode.insertBefore(wrap, state.table);

    // Native <select> 'change' — read the picked value, update state, re-filter.
    wrap.addEventListener('change', function (e) {
      var sel = e.target && e.target.closest ? e.target.closest('.hdlv2-stalled-select') : null;
      if (!sel) return;
      var which = sel.getAttribute('data-filter');
      if (which === 'stage')      { state.stageFilter = sel.value || 'any'; }
      else if (which === 'quiet') { state.quietFilter = sel.value || 'any'; }
      else return;
      applyFilters();
    });

    applyFilters();
  }

  // ── W12 — Default sort by latest activity (descending) ──
  //
  // Runs ONCE on first render (state.defaultSortApplied guard). Sorts the
  // visible <tr.client-row> set by the client's latest_event_at descending.
  // Null/empty latest_event_at sorts to the bottom (oldest-effectively).
  //
  // Why one-shot: subsequent poll-driven re-renders (reconcileRows) update
  // row content, not order. If the practitioner has clicked a column header
  // to sort by name/date/status, we don't want a 4-s digest tick to undo
  // their sort. V1's sortTable() comparator still runs on column-header
  // clicks via the existing handler — it reads the data-* sort attributes
  // we set in buildV2OnlyRow + V1's server-rendered rows, so user sorts
  // work transparently after our default sort lands.
  function defaultSortByLatestActivity() {
    if (state.defaultSortApplied) return;
    if (!state.tbody) return;
    var rows = Array.prototype.slice.call(state.tbody.querySelectorAll('tr.client-row'));
    if (rows.length === 0) return;
    rows.sort(function (a, b) {
      var ah = a.dataset.clientHash;
      var bh = b.dataset.clientHash;
      var ac = state.byHash[ah];
      var bc = state.byHash[bh];
      // Treat missing/null timestamps as oldest (empty string sorts before
      // any real ISO/MySQL datetime via lexical comparison).
      var av = (ac && ac.latest_event_at) ? String(ac.latest_event_at) : '';
      var bv = (bc && bc.latest_event_at) ? String(bc.latest_event_at) : '';
      if (av === bv) return 0;
      if (av === '') return 1;   // a is oldest → push down
      if (bv === '') return -1;  // b is oldest → a stays up
      return av < bv ? 1 : -1;   // descending
    });
    // Re-append in sorted order. appendChild on an already-mounted node
    // moves it — no clone, no re-render. Detail rows (.hdlv2-detail-row)
    // are preserved adjacent to their client-row via the move logic below.
    rows.forEach(function (row) {
      var detail = row.nextSibling;
      state.tbody.appendChild(row);
      if (detail && detail.nodeType === 1 && detail.classList && detail.classList.contains('hdlv2-detail-row')) {
        state.tbody.appendChild(detail);
      }
    });
    state.defaultSortApplied = true;
  }

  // ── Progress tab — Effort vs Outcomes (the money chart) ──
  //
  // Pulls /dashboard/client/<id>/effort-outcomes and renders one of three
  // states based on data depth:
  //   1. Empty — no adherence and no outcomes yet (nothing to show)
  //   2. Early — fewer than 2 adherence weeks AND fewer than 2 outcomes
  //              (a chart with one point misreads as broken; render a
  //              text card instead)
  //   3. Trend — at least 2 weeks of adherence OR 2+ outcome points
  //              (real chart with both series)
  function loadProgress(c, target) {
    // v0.47.9 — Stage-1-only / pre-Stage-3 clients aren't in the weekly
    // programme yet, so the Effort-vs-Outcomes chart doesn't apply. Show a
    // journey-state message that reflects where they actually are (Matthew
    // 2026-06-27 — surface Stage-1-only clients in the EXISTING dropdown, with
    // a Progress message like any incomplete client; no separate interface).
    var journey = renderProgressJourneyState(c);
    if (journey) { target.innerHTML = journey; return; }
    if (typeof window.Chart === 'undefined') {
      target.innerHTML = '<div class="hdlv2-detail-empty">Chart library failed to load. Please refresh the page.</div>';
      return;
    }
    // v0.41.19 — reuse the per-client cached fetch shared with mountSnapshot
    // so the strip + the Progress chart hit one /effort-outcomes call, not two.
    fetchEffortOutcomes(c)
      .then(function (data) {
        if (!data) {
          target.innerHTML = '<div class="hdlv2-detail-empty">Couldn’t load progress data.</div>';
          return;
        }
        var adherence = data.adherence || [];
        var outcomes  = data.outcomes  || [];

        if (!adherence.length && !outcomes.length) {
          target.innerHTML = '<div class="hdlv2-detail-empty">'
            + '<strong style="display:block;color:#333;margin-bottom:6px;">No progress data yet.</strong>'
            + 'Charts populate once the client receives their first Flight Plan and ticks at least one box.'
            + '</div>';
          return;
        }

        if (adherence.length < 2 && outcomes.length < 2) {
          renderProgressEarly(target, adherence, outcomes, data.baseline_rate);
          return;
        }

        renderProgressChartView(target, adherence, outcomes, data.baseline_rate);
      });
  }

  // v0.47.9 — Pre-programme journey message for the Progress tab. A client who
  // has only completed Stage 1 (or hasn't finished it) isn't in the weekly
  // Flight Plan yet, so the Effort-vs-Outcomes chart is meaningless for them.
  // Mirror how Progress should read for an incomplete client: say where they
  // are and point at the Stage 1 tab (their answers/gauge already render there
  // via loadStage1). Returns '' for clients far enough along to use the chart.
  function renderProgressJourneyState(c) {
    var st = c && c.status;
    if (st === 'not_started') {
      return progressJourneyCard(
        'Stage 1 not completed yet',
        'This client opened their assessment but hasn’t finished the Stage 1 quick insight. Once they complete it, their pace-of-ageing result and 9-question answers appear in the Stage 1 tab.',
        'Stage 1 · in progress'
      );
    }
    if (st === 'low_data') {
      return progressJourneyCard(
        'Completed Stage 1 · Stage 2 not started',
        'This client finished their Stage 1 quick insight — their pace-of-ageing result and 9-question answers are in the Stage 1 tab. They haven’t started Stage 2 (Your WHY) yet. Effort-vs-outcomes tracking begins once they complete Stage 3 and receive their first Weekly Flight Plan.',
        'Stage 1 ✓ · awaiting Stage 2'
      );
    }
    return '';
  }

  // Themed single-card layout, reusing the existing .hdlv2-progress-* classes.
  function progressJourneyCard(title, body, chip) {
    return ''
      + '<div class="hdlv2-progress-eyebrow">Progress</div>'
      + '<div class="hdlv2-progress-grid" style="grid-template-columns:1fr;">'
      +   '<div class="hdlv2-progress-card" style="max-width:660px;">'
      +     '<div class="hdlv2-progress-card-title"><span>' + esc(title) + '</span>'
      +       (chip ? '<span class="hdlv2-progress-card-meta">' + esc(chip) + '</span>' : '')
      +     '</div>'
      +     '<p style="margin:8px 0 0;font-size:13.5px;line-height:1.65;color:#2c3e50;">' + esc(body) + '</p>'
      +   '</div>'
      + '</div>';
  }

  // Early state — text card. A single dot in a chart misreads as a bug
  // (the practitioner can't see if effort is translating to outcomes when
  // there's only 1 week and 1 baseline). The card states the facts plainly
  // and tells them when the chart will appear.
  function renderProgressEarly(target, adherence, outcomes, baseline) {
    // v0.41.19 — 2-column grid layout. Left card: 4 metric tiles for the
    // current week (overall · movement · nutrition · key actions). Right
    // card: baseline rate with big numeric value + next-outcome chip.
    // Same data, same source — but practitioner scans 4 tiles instead of
    // parsing a comma-joined sentence, and the right column uses the
    // panel whitespace the legacy layout wasted.
    var firstAdh   = adherence[0];
    var firstOut   = outcomes[0];
    var weekLabel  = firstAdh ? ('Week ' + (firstAdh.week_number|0)) : 'No week recorded yet';
    var weekStart  = firstAdh ? formatDate(firstAdh.week_start) : '';
    var rateNum    = firstOut ? firstOut.rate : (typeof baseline === 'number' ? baseline : null);
    var rateMeta   = firstOut
      ? ('From ' + esc(firstOut.report_type) + ' report · ' + esc(formatDate(firstOut.date)))
      : (rateNum !== null ? 'Baseline rate' : 'Not yet calculated');

    // Tile severity hint — <50% reads "lo" (amber), 50-69 "mid", 70+ default (teal).
    function tileSev(n) { n = n|0; return n < 50 ? 'lo' : (n < 70 ? 'mid' : ''); }
    var overall   = firstAdh ? (firstAdh.overall|0)    : 0;
    var movement  = firstAdh ? (firstAdh.movement|0)   : 0;
    var nutrition = firstAdh ? (firstAdh.nutrition|0)  : 0;
    var keyAction = firstAdh ? (firstAdh.key_action|0) : 0;

    var leftCard;
    if (firstAdh) {
      leftCard = '<div class="hdlv2-progress-card">'
        +   '<div class="hdlv2-progress-card-title"><span>Adherence &middot; ' + esc(weekLabel) + '</span><span class="hdlv2-progress-card-meta">' + esc(weekStart) + '</span></div>'
        +   '<div class="hdlv2-progress-metric-grid">'
        +     metricTile(overall,   'Overall',     tileSev(overall))
        +     metricTile(movement,  'Movement',    tileSev(movement))
        +     metricTile(nutrition, 'Nutrition',   tileSev(nutrition))
        +     metricTile(keyAction, 'Key actions', tileSev(keyAction))
        +   '</div>'
        +   '<div class="hdlv2-progress-hint">'
        +     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'
        +     '<div>Week 2 adherence unlocks the dual-axis trend chart (Adherence &times; Rate of Ageing).</div>'
        +   '</div>'
        + '</div>';
    } else {
      leftCard = '<div class="hdlv2-progress-card">'
        +   '<div class="hdlv2-progress-card-title"><span>Adherence</span></div>'
        +   '<div class="hdlv2-detail-empty" style="padding:14px 0;text-align:left;">Client hasn’t ticked any boxes yet.</div>'
        + '</div>';
    }

    var rightCard = '<div class="hdlv2-progress-card">'
      +   '<div class="hdlv2-progress-card-title"><span>Baseline rate of ageing</span></div>'
      +   '<div class="hdlv2-progress-card-meta">' + esc(rateMeta) + '</div>'
      +   '<div class="hdlv2-progress-baseline-row">'
      +     (rateNum !== null
            ? '<span class="hdlv2-progress-baseline-value">' + rateNum.toFixed(2) + '</span><span class="hdlv2-progress-baseline-unit">&times; chronological</span>'
            : '<span class="hdlv2-progress-baseline-value">&mdash;</span>')
      +   '</div>'
      +   '<div class="hdlv2-progress-next-outcome">'
      +     '<div class="hdlv2-progress-next-text">Next outcome update<strong>Quarterly reassessment</strong></div>'
      +     '<span class="hdlv2-progress-next-chip">Week 12</span>'
      +   '</div>'
      + '</div>';

    target.innerHTML = ''
      + '<div class="hdlv2-progress-eyebrow">Effort vs Outcomes</div>'
      + '<p class="hdlv2-progress-headline">'
      +   'Trend chart appears once adherence reaches Week&nbsp;2. Until then a chart with one data point would misread as broken &mdash; here are the facts, balanced across the panel.'
      + '</p>'
      + '<div class="hdlv2-progress-grid">' + leftCard + rightCard + '</div>';
  }

  function metricTile(value, label, sev) {
    return '<div class="hdlv2-metric-tile' + (sev ? ' ' + sev : '') + '">'
      + '<div class="hdlv2-metric-tile-value">' + (value|0) + '<span class="hdlv2-metric-tile-unit">%</span></div>'
      + '<div class="hdlv2-metric-tile-label">' + esc(label) + '</div>'
      + '</div>';
  }

  // Trend state — full dual-axis chart.
  function renderProgressChartView(target, adherence, outcomes, baseline) {
    var a0 = adherence[0].overall, an = adherence[adherence.length - 1].overall;
    var headline;
    if (outcomes.length >= 2) {
      var r0 = outcomes[0].rate, rn = outcomes[outcomes.length - 1].rate;
      var trend = (an >= a0 && rn <= r0) ? '<strong>The work is paying off.</strong>'
                : (an >= a0 && rn >  r0) ? '<strong>Effort is real but the trajectory hasn’t turned yet.</strong>'
                : (an <  a0)             ? '<strong>Adherence is slipping &mdash; flag for review.</strong>'
                : '';
      headline = 'Adherence ' + a0 + '% &rarr; ' + an + '%. Rate of ageing ' + r0.toFixed(2) + ' &rarr; ' + rn.toFixed(2) + '. ' + trend;
    } else {
      headline = 'Tracking ' + adherence.length + ' weeks of adherence. Outcome line stays flat at the baseline rate until the next quarterly reassessment.';
    }

    target.innerHTML = ''
      + '<div class="hdlv2-progress-eyebrow">Effort vs Outcomes</div>'
      + '<p class="hdlv2-progress-headline">' + headline + '</p>'
      + '<div class="hdlv2-progress-chart-wrap"><canvas></canvas></div>'
      + '<div class="hdlv2-progress-legend">'
      +   '<span><i class="sw sw-adh"></i> Adherence (%)</span>'
      +   '<span><i class="sw sw-rate' + (outcomes.length < 2 ? ' dashed' : '') + '"></i> Rate of ageing' + (outcomes.length < 2 ? ' (baseline only)' : '') + '</span>'
      + '</div>';

    renderProgressChart(target.querySelector('canvas'), adherence, outcomes, baseline);
  }

  function renderProgressChart(canvas, adherence, outcomes, baseline) {
    var teal = '#3d8da0';
    var amber = '#f59e0b';
    var grey = '#e5e7eb';

    // Build a unified label axis: every adherence week_start, plus any
    // outcome dates that don't already fall on a week_start.
    var labels = adherence.map(function (a) { return 'Wk ' + a.week_number; });
    var adherenceData = adherence.map(function (a) { return a.overall; });

    // Map outcomes onto the adherence x-axis by nearest preceding week.
    // When there's only 1 outcome (the typical pre-quarterly case) we
    // render it as a flat baseline across the whole window so the chart
    // doesn't look broken with a single floating dot.
    var rateData;
    if (outcomes.length < 2) {
      var b = (outcomes[0] && outcomes[0].rate) || baseline;
      rateData = labels.map(function () { return b; });
    } else {
      rateData = labels.map(function (_, i) {
        var weekDate = adherence[i] && adherence[i].week_start;
        var match = null;
        outcomes.forEach(function (o) { if (o.date <= weekDate) match = o; });
        return match ? match.rate : null;
      });
    }

    new window.Chart(canvas, {
      type: 'line',
      data: {
        labels: labels.length ? labels : ['—'],
        datasets: [
          {
            label: 'Adherence',
            yAxisID: 'y',
            data: adherenceData,
            borderColor: teal,
            backgroundColor: 'rgba(61, 141, 160, 0.10)',
            borderWidth: 2.5,
            tension: 0.25,
            fill: true,
            pointRadius: 3,
            pointBackgroundColor: teal,
            pointBorderColor: '#fff',
            pointBorderWidth: 1,
          },
          {
            label: 'Rate of ageing',
            yAxisID: 'y1',
            data: rateData,
            borderColor: amber,
            borderWidth: 2,
            borderDash: outcomes.length < 2 ? [4, 3] : [],
            spanGaps: true,
            pointRadius: outcomes.length < 2 ? 0 : 5,
            pointHoverRadius: outcomes.length < 2 ? 0 : 6,
            pointBackgroundColor: amber,
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            fill: false,
            tension: 0,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#1a1a1a',
            titleFont: { size: 12, weight: '600' },
            bodyFont: { size: 11.5 },
            padding: 10,
            cornerRadius: 6,
            callbacks: {
              label: function (ctx) {
                if (ctx.dataset.yAxisID === 'y1') {
                  if (ctx.parsed.y == null) return null;
                  return 'Rate: ' + ctx.parsed.y.toFixed(2);
                }
                return 'Adherence: ' + ctx.parsed.y + '%';
              },
            },
          },
        },
        scales: {
          x: { grid: { color: grey, drawBorder: false }, ticks: { font: { size: 10 }, color: '#888' } },
          y: {
            position: 'left', min: 0, max: 100,
            grid: { color: grey, drawBorder: false },
            ticks: { font: { size: 10 }, color: teal, callback: function (v) { return v + '%'; } },
            title: { display: true, text: 'Adherence', font: { size: 10, weight: '600' }, color: teal },
          },
          y1: (function () {
            // Dynamic rate axis — was hard-coded to max 1.30, which silently
            // clipped data points for clients with extreme rates. Walk the
            // dataset and extend the upper bound so nothing gets truncated.
            var rates = rateData.filter(function (v) { return typeof v === 'number'; });
            var rateMax = rates.length ? Math.max.apply(null, rates) : 1.30;
            var rateMin = rates.length ? Math.min.apply(null, rates) : 0.80;
            var ymax = Math.max(1.30, Math.ceil((rateMax + 0.05) * 20) / 20);
            var ymin = Math.min(0.80, Math.floor((rateMin - 0.05) * 20) / 20);
            return {
              position: 'right', min: ymin, max: ymax,
              grid: { display: false },
              ticks: { font: { size: 10 }, color: amber, callback: function (v) { return v.toFixed(2); } },
              title: { display: true, text: 'Rate', font: { size: 10, weight: '600' }, color: amber },
            };
          })(),
        },
      },
    });
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
    // v0.41.7 (Bug-1) — populate First / Last / Total from V2 data when
    // available, falling back to em-dash. "Total" = stages completed (1-3).
    // Last Entry prefers latest_event_at (broadest signal — check-ins,
    // reports, consultation activity) and falls back to last_checkin_date
    // then v2_first_event_at, then dash.
    var firstSortTs = c.v2_first_event_at || '';
    var lastSortTs  = c.latest_event_at || c.last_checkin_date || c.v2_first_event_at || '';
    var firstEntry = formatDate(c.v2_first_event_at) || dash;
    var lastEntry  = formatDate(c.latest_event_at) || formatDate(c.last_checkin_date) || formatDate(c.v2_first_event_at) || dash;
    var total      = (typeof c.v2_total_stages === 'number' && c.v2_total_stages > 0) ? String(c.v2_total_stages) : dash;
    // v0.41.12 — populate the sort attributes the V1 sortTable() comparator
    // reads via $(row).data('first-entry') / .data('last-entry') / .data('total').
    // Without these, clicking the FIRST ENTRY / LAST ENTRY column header
    // produced Invalid-Date arithmetic for V2-only rows and the rows drifted
    // randomly through the sorted list. Sort keys are RAW MySQL timestamps
    // (matching V1 PHP's data-* attribute convention), distinct from the
    // display strings produced by formatDate() above.
    row.dataset.firstEntry = firstSortTs;
    row.dataset.lastEntry  = lastSortTs;
    row.dataset.total      = (typeof c.v2_total_stages === 'number') ? String(c.v2_total_stages) : '0';
    // v0.21.0 — 7 cells to match the V1 header (Client · First · Last ·
    // Total · Status · Assessment · Action). Previously 6 cells made the
    // chevron render under the Assessment column.
    // P2 (2026-07-13) — the action cell now carries the FULL V1 set backed by
    // V2 behaviours (resend, chat, progress, person, trash; chevron appended
    // by injectExpandButton below). Replaces the reduced trash+chevron cell —
    // "V1 icon actions aren't wired for V2-only rows" no longer applies.
    row.innerHTML = [
      '<td class="client-name">' + esc(c.name) + '</td>',
      '<td class="date-cell">' + esc(firstEntry) + '</td>',
      '<td class="date-cell">' + esc(lastEntry) + '</td>',
      '<td class="total-cell">' + esc(total) + '</td>',
      '<td class="status-badge-cell"><span class="hdlv2-inline-badge" style="' + badgeStyle + '">V2 · ' + esc(c.label) + '</span></td>',
      '<td class="assessment-cell">' + renderV2Assessment(c) + '</td>',
      '<td class="action-cell">' + renderV2ActionButtons(c) + renderV2DeleteButton(c) + '</td>',
    ].join('');
    injectExpandButton(row, c);
    stampStalledAttrs(row, c);
    return row;
  }

  // v0.41.14 — delegated click handler for the animated trash button rendered
  // by renderV2DeleteButton(). Distinct class (.hdlv2-delete-client-btn) from
  // V1's .delete-client-btn so the V1 Apple-modal handler doesn't double-fire
  // — the two paths reach the same backend endpoint with different confirm
  // copy (V1 mentions message history + $89 fee, V2 surfaces restore-by-reinvite
  // semantics which match how the V1 client_linker already behaves).
  function bindDeleteV2Client() {
    if (window.__hdlv2DeleteBound) return;
    window.__hdlv2DeleteBound = true;
    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest && e.target.closest('.hdlv2-delete-client-btn');
      if (!btn) return;
      e.preventDefault();
      e.stopPropagation();
      handleDeleteClient(btn);
    });
  }

  function handleDeleteClient(btn) {
    if (btn.disabled) return;
    var clientId = parseInt(btn.getAttribute('data-client-id') || '0', 10);
    var hash     = btn.getAttribute('data-client-hash') || '';
    var name     = btn.getAttribute('data-client-name') || 'this client';
    if (!clientId) return;
    var row = btn.closest('.client-row');

    // v0.41.18 — V1-aligned wording, retention policy clarified.
    //   - Soft-delete archives every V2 surface (form_progress + check-ins +
    //     timeline + Flight Plans + monthly summaries + reports + why_profile
    //     + consultation notes) + the V1 link row.
    //   - Re-invite creates a fresh form_progress; it does NOT auto-restore
    //     archived data.
    //   - Data is retained INDEFINITELY by default — no purge cron. Admin
    //     restore (Tools → V2 Restore, $89 fee) brings everything back at
    //     any point, no time limit.
    //   - Permanent deletion happens only on explicit client request
    //     (GDPR Article 17, right-to-be-forgotten) via the admin page.
    var body =
      'This archives ' + name + "'s assessment data — Stage 1, 2, 3, check-ins, reports, and Flight Plans. " +
      'They will no longer appear in your dashboard.\n\n' +
      '⚠ Re-inviting the same email later creates a NEW assessment — it will NOT restore previous data.\n\n' +
      'Archived data is kept indefinitely. To recover, contact office@healthdatalab.com ($89 admin fee, no time limit).\n\n' +
      'Permanent deletion only happens on explicit client request (GDPR).';
    var ask = (window.HDLV2UI && window.HDLV2UI.confirm)
      ? window.HDLV2UI.confirm({
          title: 'Remove ' + name + ' from your clients?',
          body:  body,
          confirmLabel: 'Remove client',
          cancelLabel:  'Keep'
        })
      : Promise.resolve(window.confirm('Remove ' + name + ' from your clients?\n\n' + body));

    ask.then(function (confirmed) {
      if (!confirmed) return;
      doDeleteClient(btn, clientId, hash, name, row);
    });
  }

  function doDeleteClient(btn, clientId, hash, name, row) {
    btn.disabled = true;
    btn.classList.add('is-shaking');
    setTimeout(function () { btn.classList.remove('is-shaking'); btn.classList.add('is-deleting'); }, 260);

    // v0.41.15 — POSTs to the new V2 REST endpoint instead of V1's
    // wp_ajax_health_tracker_delete_client. The V1 handler queries the V1
    // link table which V2-only clients never populate, so every click on a
    // V2-only row used to fail "Client relationship not found or already
    // deleted." The V2 endpoint stamps form_progress.deleted_at directly,
    // which is the actual source of truth for V2 dashboard visibility.
    var apiBase = (CFG.api_base || '/wp-json/hdl-v2/v1');
    var url     = apiBase.replace(/\/$/, '') + '/dashboard/client/' + encodeURIComponent(clientId) + '/delete';

    fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce':   CFG.nonce || '',
      },
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        if (!res.ok || !res.body || res.body.success !== true) {
          var msg = (res.body && (res.body.message || (res.body.data && res.body.data.message))) || 'Could not remove client';
          throw new Error(msg);
        }
        // Success — fade the row, remove from DOM, clear matched/byHash so
        // the 4 s digest poll doesn't try to re-render. The dashboard API
        // now filters AND deleted_at IS NULL, so the row won't return there
        // either.
        if (row) {
          row.classList.add('is-removing');
          setTimeout(function () {
            if (row.parentNode) row.parentNode.removeChild(row);
            var next = row.nextElementSibling;
            if (next && next.classList && next.classList.contains('hdlv2-detail-row')) {
              if (next.parentNode) next.parentNode.removeChild(next);
            }
          }, 320);
        }
        if (state && state.matched && hash) delete state.matched[hash];
        if (state && state.byHash  && hash) delete state.byHash[hash];
        // v0.47.11 — also drop the client from the in-memory roster and
        // re-render the "What needs you today" action queue immediately, so a
        // deleted client doesn't linger in that top panel until the next 4-s
        // poll (which is paused while the tab is backgrounded — the "needs a
        // refresh" symptom). This mirrors exactly what the next poll would do
        // (same renderActionQueue call, same data minus the removed client) —
        // no new behaviour, just instant. Guarded so a failure can't break the
        // delete's success path.
        try {
          if (state && Array.isArray(state.clients)) {
            state.clients = state.clients.filter(function (cc) {
              return String(cc.user_id) !== String(clientId) && ( !hash || cc.email_hash !== hash );
            });
            renderActionQueue(state.clients);
          }
        } catch (e) { /* non-fatal — the next poll reconciles anyway */ }
        if (window.HDLV2UI && window.HDLV2UI.toast) window.HDLV2UI.toast('Removed ' + name, 'success');
      })
      .catch(function (err) {
        btn.disabled = false;
        btn.classList.remove('is-deleting', 'is-shaking');
        if (window.HDLV2UI && window.HDLV2UI.toast) window.HDLV2UI.toast(err.message || 'Could not remove client', 'error');
      });
  }

  // v0.41.14 — animated trash button rendered on V2-only rows. Geometry
  // ported from itshover.com/icons/trash-icon (github.com/itshover/itshover,
  // Apache-2.0); since P3 it lives in HDLV2_ICONS['trash-v2'] (single source).
  // Class hooks (.trash-lid-lower / .trash-lid-upper) are targeted by the
  // hover-state CSS in injectStyles() to rotate the lid open without any JS
  // during idle. Click flow → confirm modal → AJAX → row fade is in
  // handleDeleteClient above.
  function renderV2DeleteButton(c) {
    if (!c || !c.user_id) return '';
    var nameEsc = esc(c.name || 'this client');
    // v0.41.15 — data-client-id is the V2 user_id (form_progress.client_user_id),
    // used by the new /dashboard/client/{id}/delete REST endpoint. The hash
    // attribute stays for any downstream code that still keys by email-hash,
    // but the delete path no longer depends on it.
    return '<button type="button" class="hdlv2-delete-client-btn" '
      + 'data-client-id="' + esc(c.user_id) + '" '
      + 'data-client-hash="' + esc(c.email_hash || '') + '" '
      + 'data-client-name="' + nameEsc + '" '
      + 'title="Remove ' + nameEsc + ' from your list" '
      + 'aria-label="Remove ' + nameEsc + ' from your list">'
      + iconSVG('trash-v2')
      + '</button>';
  }

  // ──────────────────────────────────────────────────────────────────
  //  P2 (action-button unify, 2026-07-13) — full V1 action set on V2 rows.
  //
  //  V2-only rows previously carried ONLY trash + chevron ("V1 icon actions
  //  aren't wired for V2-only rows"). These renderers close that gap with
  //  V2-backed equivalents, and unifyMatchedRowActions() swaps the V1
  //  controls that point at the wrong backend on matched rows:
  //    paper-plane → POST /dashboard/client/{id}/resend-link (P1b, stage-aware)
  //    chat        → V1's message modal, fed email_hash + client_email from
  //                  the V2 payload (+ skip_autolink so the send can't
  //                  auto-create a V1 link row)
  //    bar-chart   → the chevron panel on its Progress tab (NOT /client-tracker)
  //    person      → /user/{login}/ via V1's delegated .details-btn handler
  //  Deliberately NO cancel-invite affordance on V2 rows — invite rows are a
  //  V1-only concept and are never decorated (invite-row guard below).
  //
  //  SVG geometry comes from the P3 HDLV2_ICONS map (V1's own shapes, plus
  //  classed sub-paths for the hover motion) so V1's .action-icon-btn CSS
  //  styles them identically. Disabled states use
  //  .hdlv2-btn-off (NOT V1's .btn-disabled): pointer-events stay live so the
  //  per-stage reason tooltip is hoverable; the delegated handlers refuse the
  //  click, and the server refuses again (422) as defence-in-depth.
  // ──────────────────────────────────────────────────────────────────

  // ──────────────────────────────────────────────────────────────────
  //  P3 (animated icon unify, 2026-07-13) — ONE icon map, ONE motion block.
  //
  //  Single source of truth for every action-cell glyph on BOTH dashboards:
  //  the P2 builders consume it directly, and decorateActionIcons() swaps it
  //  into V1's server-rendered buttons in place (inner <svg> only, so the
  //  button's handlers, data-/aria- attributes, and the .msg-unread-badge
  //  sibling all survive). Geometry stays V1's own Feather set — only the
  //  moving parts gain classed sub-paths. The hover motion is modelled on
  //  itshover's per-icon animation spec but hand-rolled as pure CSS in
  //  injectStyles(): itshover ships React/Motion (JS) components, so nothing
  //  is copied from its source — only the classed sub-path structure and the
  //  motion design are ported:
  //    send    — ported from itshover.com/icons/send-icon (github.com/itshover/itshover, Apache-2.0)
  //    message — ported from itshover.com/icons/message-circle-icon (github.com/itshover/itshover, Apache-2.0)
  //    chart   — ported from itshover.com/icons/chart-bar-icon (github.com/itshover/itshover, Apache-2.0)
  //    person  — ported from itshover.com/icons/user-icon (github.com/itshover/itshover, Apache-2.0)
  //    trash   — ported from itshover.com/icons/trash-icon (github.com/itshover/itshover, Apache-2.0)
  //              ×2 entries: 'trash' keeps V1's Feather geometry (the handle
  //              split into its own sub-path so the lid can open — same
  //              rendered shape); 'trash-v2' is the itshover geometry already
  //              shipped on V2 rows since v0.41.14.
  //  The svg root class .hdlv2-anim-ico doubles as the decorate-pass
  //  idempotency marker — a button whose svg already carries it is skipped,
  //  so the 4s digest poll can never double-swap or resurrect a static icon.
  var HDLV2_ICONS = {
    'send': '<svg class="hdlv2-anim-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<g class="send-plane"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></g></svg>',
    'message': '<svg class="hdlv2-anim-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<path class="msg-bubble" d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
    'chart': '<svg class="hdlv2-anim-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<rect class="bar-1" x="3" y="12" width="4" height="9"/><rect class="bar-2" x="10" y="8" width="4" height="13"/><rect class="bar-3" x="17" y="3" width="4" height="18"/></svg>',
    'person': '<svg class="hdlv2-anim-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<g class="person-avatar"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></g></svg>',
    'trash': '<svg class="hdlv2-anim-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<polyline class="trash-lid-lower" points="3 6 5 6 21 6"/>'
      + '<path class="trash-lid-upper" d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>'
      + '<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>'
      + '<line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>',
    'trash-v2': '<svg class="hdlv2-anim-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<path stroke="none" d="M0 0h24v24H0z" fill="none"/>'
      + '<path class="trash-lid-lower" d="M4 7l16 0"/>'
      + '<path d="M10 11l0 6"/>'
      + '<path d="M14 11l0 6"/>'
      + '<path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12"/>'
      + '<path class="trash-lid-upper" d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3"/></svg>',
  };

  function iconSVG(name) {
    return HDLV2_ICONS[name] || '';
  }

  // V1 button-class -> icon-name for the decorate pass. Only the six
  // PHP-rendered button classes map (token-safe match, so V2's
  // .hdlv2-delete-client-btn can never collide with .delete-client-btn);
  // map-built P2 buttons are skipped upstream by the .hdlv2-anim-ico marker.
  function iconNameForButton(className) {
    var cls = ' ' + (className || '') + ' ';
    if (cls.indexOf(' send-form-btn ') !== -1)     return 'send';
    if (cls.indexOf(' message-btn ') !== -1)       return 'message';
    if (cls.indexOf(' view-tracker-btn ') !== -1)  return 'chart';
    if (cls.indexOf(' details-btn ') !== -1)       return 'person';
    if (cls.indexOf(' delete-client-btn ') !== -1) return 'trash';
    if (cls.indexOf(' cancel-invite-btn ') !== -1) return 'trash';
    return '';
  }

  // P3 — swap the static Feather <svg> inside every V1 server-rendered
  // action button (all 4 PHP action-cell blocks, both dashboards, incl.
  // invite rows — visual swap only, handlers untouched) for the animated
  // map version. Replaces the svg ELEMENT in place via outerHTML, never
  // button.innerHTML, so V1's jQuery-delegated handlers (bound on button
  // classes) and the .msg-unread-badge sibling are untouched.
  function decorateActionIcons(root) {
    if (!root || !root.querySelectorAll) return;
    var btns = root.querySelectorAll('.action-icon-btn');
    for (var i = 0; i < btns.length; i++) {
      var btn = btns[i];
      var svg = btn.querySelector('svg');
      if (!svg || (svg.classList && svg.classList.contains('hdlv2-anim-ico'))) continue;
      var name = iconNameForButton(btn.className && btn.className.baseVal !== undefined ? btn.className.baseVal : btn.className);
      if (!name) continue;
      var html = iconSVG(name);
      if (html) svg.outerHTML = html;
    }
  }

  function sendIconSVG()    { return iconSVG('send'); }
  function messageIconSVG() { return iconSVG('message'); }
  function chartIconSVG()   { return iconSVG('chart'); }
  function personIconSVG()  { return iconSVG('person'); }

  // Effective resend state = the server's P1b descriptor ∧ an email on file.
  // The tooltip is the SINGLE user-facing explanation for every state, so a
  // disabled button always says WHY it refuses.
  function resendState(c) {
    var r = (c && c.resend) || null;
    if (!r) return { enabled: false, label: '', title: 'Nothing to send for this client yet' };
    if (r.enabled && !(c && c.email)) {
      return { enabled: false, label: r.label, title: 'No email address on file — nothing to send' };
    }
    if (r.enabled) {
      return { enabled: true, label: r.label, title: r.label + ' — emails ' + c.email };
    }
    return { enabled: false, label: '', title: r.tooltip || 'Nothing to send for this client yet' };
  }

  function renderResendButton(c) {
    if (!c || !c.user_id) return '';
    var st = resendState(c);
    // data-rs-hash lets reconcileRows() re-render only when the state moved.
    var rsHash = (st.enabled ? '1' : '0') + '|' + st.label + '|' + st.title;
    return '<button type="button" class="action-icon-btn hdlv2-resend-link-btn' + (st.enabled ? '' : ' hdlv2-btn-off') + '" '
      + 'data-client-id="' + attrEsc(c.user_id) + '" '
      + 'data-client-name="' + attrEsc(c.name || '') + '" '
      + 'data-rs-hash="' + attrEsc(rsHash) + '" '
      + (st.enabled ? '' : 'aria-disabled="true" ')
      + 'title="' + attrEsc(st.title) + '" aria-label="' + attrEsc(st.title) + '">'
      + sendIconSVG()
      + '</button>';
  }

  function renderMessageButton(c) {
    if (!c || !c.email_hash) return '';
    var enabled = !!c.email;
    // Enabled: V1's delegated .message-btn handler opens the modal (prefill +
    // templates); our hdlv2- marker feeds the email and flags the send
    // V2-sourced. Disabled (no email): deliberately NOT .message-btn, so V1
    // can't open a modal that could never deliver.
    var cls = enabled
      ? 'action-icon-btn message-btn hdlv2-message-btn'
      : 'action-icon-btn hdlv2-message-off hdlv2-btn-off';
    var title = enabled ? ('Message ' + (c.name || 'client')) : 'No email address on file — messaging unavailable';
    return '<button type="button" class="' + cls + '" '
      + 'data-client-hash="' + attrEsc(c.email_hash) + '" '
      + 'data-client-name="' + attrEsc(c.name || '') + '" '
      + 'data-client-email="' + attrEsc(c.email || '') + '" '
      + (enabled ? '' : 'aria-disabled="true" ')
      + 'title="' + attrEsc(title) + '" aria-label="' + attrEsc(title) + '">'
      + messageIconSVG()
      + '<span class="msg-unread-badge" data-badge-hash="' + attrEsc(c.email_hash) + '" hidden>0</span>'
      + '</button>';
  }

  function renderProgressButton(c) {
    if (!c || !c.user_id) return '';
    var aria = 'View progress for ' + (c.name || 'client');
    return '<button type="button" class="action-icon-btn hdlv2-progress-btn" '
      + 'data-client-id="' + attrEsc(c.user_id) + '" '
      + 'title="View progress" aria-label="' + attrEsc(aria) + '">'
      + chartIconSVG()
      + '</button>';
  }

  function renderProfileButton(c) {
    var login = (c && c.user_login) || '';
    if (!login) {
      return '<button type="button" class="action-icon-btn hdlv2-btn-off" aria-disabled="true" '
        + 'title="No profile page for this client yet" aria-label="No profile page for this client yet">'
        + personIconSVG()
        + '</button>';
    }
    var aria = 'View profile for ' + ((c && c.name) || 'client');
    return '<button type="button" class="action-icon-btn details-btn" '
      + 'data-client-login="' + attrEsc(login) + '" '
      + 'title="View Profile" aria-label="' + attrEsc(aria) + '">'
      + personIconSVG()
      + '</button>';
  }

  // V1 action-cell order: send, chat, chart, person (trash + chevron follow).
  function renderV2ActionButtons(c) {
    return renderResendButton(c) + renderMessageButton(c) + renderProgressButton(c) + renderProfileButton(c);
  }

  // Confirm-dialog copy (D4/rule 1): must name the STAGE and the RECIPIENT and
  // state that the send REPLACES any previous link — P1b rotates the token, so
  // every earlier link (including a pre-issued login email a client may still
  // be holding, e.g. the beta-relaunch file links) dies the moment this fires.
  function resendConfirmCopy(c) {
    var r = (c && c.resend) || {};
    var artefact;
    switch (r.link_kind) {
      case 'plan':   artefact = 'a fresh link to their weekly flight plan'; break;
      case 'report': artefact = 'a fresh link to their report'; break;
      default:
        artefact = (r.stage === 1)
          ? 'a fresh link to start their assessment (Stage 1)'
          : 'a fresh link to continue their assessment (Stage ' + (r.stage || '?') + ')';
        break;
    }
    return {
      title: r.label ? r.label + '?' : 'Send link?',
      body: 'This emails ' + ((c && c.name) || 'this client') + ' (' + ((c && c.email) || '') + ') ' + artefact + '.\n\n'
        + '⚠ This replaces any previous link for this client — every earlier link '
        + '(including any saved or pre-issued login email) stops working the moment you confirm. '
        + 'Only continue if nobody is holding an older link they still need.',
      confirmLabel: r.label || 'Send link',
      cancelLabel: 'Cancel',
    };
  }

  // ── P2 delegated handlers (bound once, like bindDeleteV2Client) ──

  function bindResendLink() {
    if (window.__hdlv2ResendBound) return;
    window.__hdlv2ResendBound = true;
    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest && e.target.closest('.hdlv2-resend-link-btn');
      if (!btn) return;
      e.preventDefault();
      e.stopPropagation();
      handleResendLink(btn);
    });
  }

  function handleResendLink(btn) {
    if (btn.disabled || btn.classList.contains('hdlv2-btn-off') || btn.getAttribute('aria-disabled') === 'true') return;
    var uid = btn.getAttribute('data-client-id') || '';
    var c = (state.clients || []).filter(function (x) { return String(x.user_id) === String(uid); })[0];
    if (!c) return;
    var r = c.resend || {};
    // Client-side mirror of the server's 422 refusal — never even ask.
    if (!r.enabled || !c.email) return;
    // GAP-2 (2026-07-13): lock the button the INSTANT the first click is
    // handled, BEFORE the dialog opens, so a rapid second click hits the
    // `if (btn.disabled) return` guard at the top of this handler and cannot
    // stack a second confirm dialog — each of which would rotate the token
    // again. Released on cancel / dialog error; doResendLink keeps it disabled
    // through the fetch and re-enables on completion.
    btn.disabled = true;
    var copy = resendConfirmCopy(c);
    var ask = (window.HDLV2UI && window.HDLV2UI.confirm)
      ? window.HDLV2UI.confirm({ title: copy.title, body: copy.body, confirmLabel: copy.confirmLabel, cancelLabel: copy.cancelLabel })
      : Promise.resolve(window.confirm(copy.title + '\n\n' + copy.body));
    ask.then(function (confirmed) {
      if (!confirmed) { btn.disabled = false; return; }
      doResendLink(btn, c);
    }, function () { btn.disabled = false; });
  }

  function doResendLink(btn, c) {
    btn.disabled = true;
    var url = (CFG.api_base || '').replace(/\/$/, '') + '/dashboard/client/' + encodeURIComponent(c.user_id) + '/resend-link';
    fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce || '' },
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        btn.disabled = false;
        if (!res.ok || !res.body || res.body.success !== true) {
          var msg = (res.body && (res.body.message || (res.body.data && res.body.data.message))) || 'Could not send the link';
          throw new Error(msg);
        }
        // Rule 7 — say what was sent and to whom, not a bare tick.
        if (window.HDLV2UI && window.HDLV2UI.toast) {
          window.HDLV2UI.toast('Sent: ' + (res.body.stage_label || 'link') + ' to ' + (res.body.recipient_email || ''), 'success');
        }
      })
      .catch(function (err) {
        btn.disabled = false;
        if (window.HDLV2UI && window.HDLV2UI.toast) window.HDLV2UI.toast(err.message || 'Could not send the link', 'error');
      });
  }

  function bindProgressOpen() {
    if (window.__hdlv2ProgressBound) return;
    window.__hdlv2ProgressBound = true;
    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest && e.target.closest('.hdlv2-progress-btn');
      if (!btn) return;
      e.preventDefault();
      e.stopPropagation();
      var row = btn.closest('.client-row');
      if (!row) return;
      var expand = row.querySelector('.hdlv2-expand-btn');
      if (!expand) return;
      var detail = row.nextElementSibling;
      var isOpen = detail && detail.classList && detail.classList.contains('hdlv2-detail-row');
      if (!isOpen) {
        // buildDetailPanel lands on the Progress tab by default.
        expand.click();
        detail = row.nextElementSibling;
      } else {
        var tab = detail.querySelector('.hdlv2-detail-tab[data-tab="progress"]');
        if (tab) tab.click();
      }
      if (detail && detail.scrollIntoView) detail.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  }

  // Chat integration. V1's delegated .message-btn handler opens + fills the
  // modal from data-client-hash/-name only; the V2 payload supplies the email
  // (V1 resolves recipients from V1 submissions, which V2 clients don't have).
  // The ajaxPrefilter stamps skip_autolink=1 onto hdl_send_client_message
  // POSTs while a V2-sourced modal is the active context, so the send can't
  // auto-create a V1 link row (see class-messaging-service.php; server-gated
  // to V2-owned clients).
  function bindMessageIntegration() {
    if (window.__hdlv2MsgBound) return;
    window.__hdlv2MsgBound = true;
    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest && e.target.closest('.message-btn, #message-current-client-btn');
      if (!btn) return;
      if (btn.classList && btn.classList.contains('hdlv2-message-btn')) {
        var hash = btn.getAttribute('data-client-hash') || '';
        var c = state.byHash[hash] || null;
        state.msgV2Client = (c && c.email) ? c : null;
        if (state.msgV2Client) {
          // V1's jQuery handler opens + resets the modal on this same click;
          // fill the email a task-queue hop later so the reset can't wipe it.
          setTimeout(function () {
            if (!state.msgV2Client) return;
            var emailInput = document.getElementById('message-client-email');
            if (emailInput) emailInput.value = state.msgV2Client.email;
            var hashInput = document.getElementById('message-client-email-hash');
            if (hashInput && !hashInput.value) hashInput.value = state.msgV2Client.email_hash || '';
          }, 0);
        }
      } else {
        // V1-native open (or the tracker-view button) — untouched V1 flow.
        state.msgV2Client = null;
      }
    });
    // Two send actions can leave the modal: hdl_post_message (the hdl-chat.js
    // conversation thread — the path this dashboard actually uses) and
    // hdl_send_client_message (the classic form submit). Stamp both.
    if (window.jQuery && window.jQuery.ajaxPrefilter) {
      window.jQuery.ajaxPrefilter(function (options) {
        if (!state.msgV2Client || !options) return;
        var d = options.data;
        if (typeof d === 'string') {
          if (d.indexOf('action=hdl_send_client_message') === -1
            && d.indexOf('action=hdl_post_message') === -1) return;
          options.data = d + '&skip_autolink=1';
        } else if (d && typeof d === 'object'
          && (d.action === 'hdl_send_client_message' || d.action === 'hdl_post_message')) {
          d.skip_autolink = '1';
        }
      });
    }
  }

  // P2 — unify the action cell on MATCHED rows (V1 server-rendered + live V2
  // record). Matched rows are by definition V2 clients (the roster only
  // returns form_progress rows), so V1's per-row actions point at the wrong
  // backend for them:
  //   - send-form opens V1's Health/Longevity picker → a V1 login link to the
  //     WP home page, never the client's V2 funnel stage;
  //   - view-tracker opens /client-tracker/, which reads V1 submissions —
  //     empty for V2 funnel clients. (V1-only clients keep both: no roster
  //     row → no decoration. V1 tracker for hybrids stays reachable via
  //     Client Tools.)
  // Same REPLACE-never-ADD rule as swapMatchedRowDelete — two live controls
  // would let the wrong V1 handler still fire. The chat button is AUGMENTED,
  // not replaced: V1's modal + prefill are right, it just needs the V2 email
  // fed and the V2-source marker. Invite rows are never touched (cancel-invite
  // and resend-invite stay pure V1).
  function unifyMatchedRowActions(row, c) {
    if (!c || !c.user_id) return;
    if (row.classList.contains('invite-row')) return;
    swapActionButton(row, '.send-form-btn', renderResendButton(c));
    swapActionButton(row, '.view-tracker-btn', renderProgressButton(c));
    var msg = row.querySelector('.message-btn');
    if (msg && c.email) {
      msg.classList.add('hdlv2-message-btn');
      msg.setAttribute('data-client-email', c.email);
      // V1 disables messaging for V1-"not started" clients; a V2 client with
      // an email on file is messageable regardless of V1 history.
      msg.classList.remove('btn-disabled');
      msg.removeAttribute('aria-disabled');
    }
  }

  function swapActionButton(row, selector, html) {
    var oldBtn = row.querySelector(selector);
    if (!oldBtn || !html) return;
    var tmp = document.createElement('div');
    tmp.innerHTML = html;
    var fresh = tmp.firstElementChild;
    if (fresh) oldBtn.parentNode.replaceChild(fresh, oldBtn);
  }

  // P2 — keep the stage-aware paper-plane in step with server state as the
  // 4-s digest poll moves the client through the funnel. Delegated handler =
  // no rebinding; data-rs-hash makes idle polls a no-op.
  function refreshResendButton(row, c) {
    var btn = row.querySelector('.hdlv2-resend-link-btn');
    if (!btn) return;
    var html = renderResendButton(c);
    if (!html) return;
    var tmp = document.createElement('div');
    tmp.innerHTML = html;
    var fresh = tmp.firstElementChild;
    if (!fresh) return;
    if (btn.getAttribute('data-rs-hash') === fresh.getAttribute('data-rs-hash')) return;
    btn.parentNode.replaceChild(fresh, btn);
  }

  // v0.21.0 — tiny label driven by V2 status so the Assessment column isn't
  // blank for V2-only rows. Mirrors the PHP Assessment cell on V1 rows.
  function renderV2Assessment(c) {
    switch (c.status) {
      case 'not_started':          return '<span class="status-badge pending">Stage 1</span>';
      case 'low_data':             return '<span class="status-badge info">Stage 1 &#10003;</span>';
      case 'awaiting_why_release': return '<span class="status-badge info">Stage 2</span>';
      case 'stage3_in_progress':   return '<span class="status-badge info">Stage 3</span>';
      case 'awaiting_consult':     return '<span class="status-badge info">Stage 3 &#10003;</span>';
      case 'active':
      case 'progress_normal':
      case 'needs_attention':
      case 'inactive':             return '<span class="status-badge good">Complete</span>';
      default:                     return '&ndash;';
    }
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
      // v0.41.14 — animated trash icon ported from itshover.com/icons/trash-icon
      // (github.com/itshover/itshover, Apache-2.0).
      // Vanilla CSS translation of the original Motion (Framer) hover animation:
      //   lid lower rotates -25° + lifts; lid upper rotates -35° + lifts diagonally;
      //   stroke colour shifts to #ef4444 (Tailwind red-500) on hover.
      // Same dimensions + circular shape as .hdlv2-expand-btn so the two action-cell
      // buttons read as a balanced pair. The :hover state is the calm default; the
      // .is-deleting + .is-shaking classes are applied by JS during the confirm/AJAX
      // flow so the lid stays open and the icon wiggles before the row fades out.
      '.hdlv2-delete-client-btn { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border:1px solid #e4e6ea; border-radius:50%; background:#fff; color:#94a3b8; cursor:pointer; margin-left:6px; padding:0; transition: border-color 0.15s ease, background 0.15s ease, color 0.2s ease-in-out; vertical-align:middle; }',
      '.hdlv2-delete-client-btn:hover { border-color:#fecaca; background:#fef2f2; color:#ef4444; }',
      '.hdlv2-delete-client-btn:disabled { opacity:0.5; cursor:not-allowed; }',
      // P2 — disabled state for the unified action buttons. Deliberately NOT
      // V1's .btn-disabled: pointer-events stay live so the per-stage reason
      // tooltip (title attr) is hoverable; delegated handlers refuse the click.
      '.action-icon-btn.hdlv2-btn-off { opacity:0.35; cursor:not-allowed; }',
      '.action-icon-btn.hdlv2-btn-off:hover { background:#2D9596; transform:none; box-shadow:none; }',
      '.hdlv2-resend-link-btn:disabled { opacity:0.5; cursor:progress; }',
      '.hdlv2-delete-client-btn svg { display:block; width:16px; height:16px; transition: stroke 0.2s ease-in-out; pointer-events:none; }',
      '.hdlv2-delete-client-btn svg .trash-lid-lower, .hdlv2-delete-client-btn svg .trash-lid-upper { transform-origin: 50% 100%; transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1); }',
      '.hdlv2-delete-client-btn:hover svg .trash-lid-lower, .hdlv2-delete-client-btn.is-deleting svg .trash-lid-lower { transform: translateY(-2px) rotate(-25deg); }',
      '.hdlv2-delete-client-btn:hover svg .trash-lid-upper, .hdlv2-delete-client-btn.is-deleting svg .trash-lid-upper { transform: translate(-1.5px, -3px) rotate(-35deg); }',
      '@keyframes hdlv2-trash-shake { 0%{transform:translateX(0);} 25%{transform:translateX(-2px);} 50%{transform:translateX(2px);} 75%{transform:translateX(-1px);} 100%{transform:translateX(0);} }',
      '.hdlv2-delete-client-btn.is-shaking svg { animation: hdlv2-trash-shake 0.25s ease-in-out; }',
      // ── P3 (2026-07-13) — unified animated icon set for the action cell. ──
      // Motion modelled on itshover.com per-icon specs (hand-rolled pure CSS;
      // itshover ships React/Motion JS — nothing copied from its source).
      // ONE shared timing + easing across all five icons, single clean play
      // on button hover with a natural reset on leave, no loops. The V1 red
      // trash reuses the exact lid transforms the V2 grey trash has shipped
      // since v0.41.14, so the motion language is identical everywhere.
      '.action-icon-btn svg.hdlv2-anim-ico { overflow:visible; }',
      '.action-icon-btn svg .send-plane, .action-icon-btn svg .msg-bubble, .action-icon-btn svg .person-avatar { transform-box:fill-box; transform-origin:center; transition: transform .25s cubic-bezier(0.16, 1, 0.3, 1); }',
      '.action-icon-btn svg .bar-1, .action-icon-btn svg .bar-2, .action-icon-btn svg .bar-3 { transform-box:fill-box; transform-origin:50% 100%; transition: transform .25s cubic-bezier(0.16, 1, 0.3, 1); }',
      '.action-icon-btn svg .bar-2 { transition-delay:.06s; }',
      '.action-icon-btn svg .bar-3 { transition-delay:.12s; }',
      '.action-icon-btn svg .trash-lid-lower, .action-icon-btn svg .trash-lid-upper { transform-origin: 50% 100%; transition: transform .25s cubic-bezier(0.16, 1, 0.3, 1); }',
      '.action-icon-btn:hover svg .send-plane { transform: translate(2px, -2px); }',
      '.action-icon-btn:hover svg .msg-bubble { transform: scale(1.08); }',
      '.action-icon-btn:hover svg .bar-1, .action-icon-btn:hover svg .bar-2, .action-icon-btn:hover svg .bar-3 { transform: scaleY(1.1); }',
      '.action-icon-btn:hover svg .person-avatar { transform: translateY(-1px) scale(1.05); }',
      '.action-icon-btn:hover svg .trash-lid-lower { transform: translateY(-2px) rotate(-25deg); }',
      '.action-icon-btn:hover svg .trash-lid-upper { transform: translate(-1.5px, -3px) rotate(-35deg); }',
      // P3 — disabled buttons show NO motion: V1 .btn-disabled never hovers
      // (pointer-events:none) but is covered anyway; .hdlv2-btn-off stays
      // hoverable for its reason-tooltip, so its sub-paths must hold still.
      '.action-icon-btn.btn-disabled svg *, .action-icon-btn.btn-disabled:hover svg *, .action-icon-btn.hdlv2-btn-off svg *, .action-icon-btn.hdlv2-btn-off:hover svg * { transition:none; transform:none; animation:none; }',
      // P3 — reduced-motion: one block silences all 8 animated icons (5 new
      // + the V2 trash + both existing itshover download ports) and the
      // JS-toggled shake. The chevron keeps its rotate (open/closed is state,
      // not decoration) but snaps instead of transitioning.
      '@media (prefers-reduced-motion: reduce) { '
        + '.action-icon-btn svg .send-plane, .action-icon-btn svg .msg-bubble, .action-icon-btn svg .bar-1, .action-icon-btn svg .bar-2, .action-icon-btn svg .bar-3, .action-icon-btn svg .person-avatar, '
        + '.action-icon-btn svg .trash-lid-lower, .action-icon-btn svg .trash-lid-upper, '
        + '.hdlv2-delete-client-btn svg .trash-lid-lower, .hdlv2-delete-client-btn svg .trash-lid-upper, '
        + '.hdlv2-fn-ico .fn-ico-stem, .hdlv2-fn-ico .fn-ico-head, .hdlv2-fn-ico .fn-ico-tray, '
        + '.hdlv2-iho-dl .hdlv2-iho-dl-arrow '
        + '{ transition:none !important; transform:none !important; animation:none !important; } '
        + '.hdlv2-delete-client-btn.is-shaking svg, .hdlv2-delete-client-btn.is-deleting svg { animation:none !important; } '
        + '.hdlv2-expand-btn svg { transition:none !important; } '
        + '}',
      '.hdlv2-v2-only-row.is-removing { transition: opacity 0.3s ease-out, transform 0.3s ease-out; opacity:0; transform: translateX(8px); }',
      '.hdlv2-expand-btn svg { transition: transform 0.2s ease; display:block; }',
      '.hdlv2-expand-btn.open svg { transform: rotate(180deg); }',
      '.hdlv2-detail-row > td { padding: 0 !important; background: #fafbfc; border-bottom: 1px solid #e4e6ea !important; }',
      // 0.47.48 — pending-lead "View details" toggle + inline detail panel in the
      // top "New widget submissions" strip (the F3 main-list group was REMOVED to
      // kill the duplicate). The .hdlv2-pending-detail*/*-kv rules below are reused
      // verbatim by the strip panel. HDL brand tokens throughout.
      '.hdlv2-aq-btn-pending-details { padding:5px 11px; font-size:12px; border:1px solid #cfe2e7; background:#f0f7f9; color:#004F59; border-radius:999px; cursor:pointer; font-weight:600; font-family:inherit; line-height:1.3; }',
      '.hdlv2-aq-btn-pending-details:hover { border-color:#3d8da0; background:#e3eef1; }',
      '.hdlv2-aq-btn-pending-details[aria-expanded="true"] { background:#3d8da0; border-color:#3d8da0; color:#fff; }',
      '.hdlv2-aq-lead-detail { flex-basis:100%; width:100%; margin-top:8px; border-top:1px solid #e4e6ea; }',
      '.hdlv2-aq-lead-detail[hidden] { display:none; }',
      '.hdlv2-aq-lead-detail .hdlv2-pending-detail { padding:12px 2px 4px; }',
      '.hdlv2-pending-detail { font-family: Inter, -apple-system, BlinkMacSystemFont, sans-serif; padding:18px 24px 20px; color:#333; }',
      '.hdlv2-pending-detail-note { font-size:12px; color:#5a7d85; background:#f0f7f9; border:1px solid #cfe2e7; border-radius:8px; padding:9px 12px; margin-bottom:14px; }',
      '.hdlv2-pending-detail-sub { font:600 12px/1 Poppins, Inter, sans-serif; color:#004F59; text-transform:uppercase; letter-spacing:.04em; margin:6px 0 8px; }',
      '.hdlv2-pending-detail-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:8px 18px; margin-bottom:12px; }',
      '.hdlv2-pending-kv { display:flex; flex-direction:column; gap:2px; }',
      '.hdlv2-pending-kv-k { font-size:11px; color:#888; }',
      '.hdlv2-pending-kv-v { font-size:13.5px; color:#111; font-weight:500; word-break:break-word; }',
      '.hdlv2-pending-detail-empty { font-size:12.5px; color:#888; font-style:italic; padding:6px 0; }',
      // B-series — active/expanded client row: slightly darker grey + subtle teal
      // accent stripe so the open client reads as the active part of the page.
      // Existing HDL tokens only (#f0f0f0 grey, #3d8da0 teal); not colour-alone
      // (bold leading cell + already-rotated chevron). !important beats V1 zebra.
      '.client-row.hdlv2-row-active > td { background:#f0f0f0 !important; }',
      '.client-row.hdlv2-row-active > td:first-child { box-shadow: inset 3px 0 0 #3d8da0; font-weight:600; }',
      '.hdlv2-detail-panel { font-family: Inter, -apple-system, BlinkMacSystemFont, sans-serif; padding: 22px 28px 24px; color: #111; }',
      '.hdlv2-detail-head { display:flex; align-items:baseline; gap:10px; margin-bottom:14px; flex-wrap:wrap; }',
      '.hdlv2-detail-head strong { font-family: Poppins, Inter, sans-serif; font-size:15px; color:#111; }',
      '.hdlv2-detail-email { font-size:12px; color:#888; }',
      '.hdlv2-detail-tabs { display:flex; gap:2px; border-bottom: 2px solid #e4e6ea; margin-bottom: 16px; overflow-x:auto; overflow-y:hidden; -webkit-overflow-scrolling:touch; }',
      '.hdlv2-detail-tab { appearance:none; background:transparent; border:none; border-bottom: 2px solid transparent; margin-bottom:-2px; padding:8px 16px; font: 500 13px/1.4 Inter, -apple-system, sans-serif; color:#666; cursor:pointer; white-space:nowrap; transition:all 0.15s; }',
      '.hdlv2-detail-tab:hover { color:#3d8da0; }',
      '.hdlv2-detail-tab.active { color:#3d8da0; border-bottom-color:#3d8da0; }',
      '.hdlv2-detail-content { min-height: 120px; font-size: 13px; line-height: 1.6; color: #333; }',
      '.hdlv2-detail-loading, .hdlv2-detail-empty { padding: 24px; text-align: center; color: #888; font-size: 13px; }',
      '.hdlv2-detail-deeplink { display:inline-flex; align-items:center; gap:7px; margin-top:14px; padding:8px 18px; border:1px solid #3d8da0; border-radius:24px; color:#3d8da0; text-decoration:none; font: 500 13px/1 Inter, -apple-system, sans-serif; transition: all 0.15s; }',
      '.hdlv2-detail-deeplink:hover { background:#3d8da0; color:#fff; }',
      // v0.46.49 — download pills carry the shared download icon (same glyph
      // as the Flight Notes button) sized to the 13px label; arrow bounces on
      // hover via the existing hdlv2-fn-bounce keyframes.
      '.hdlv2-detail-deeplink .hdlv2-fn-ico { width:14px; height:14px; flex:0 0 14px; }',
      '.hdlv2-detail-deeplink:hover .fn-ico-stem, .hdlv2-detail-deeplink:hover .fn-ico-head { animation: hdlv2-fn-bounce 0.7s ease infinite; }',
      // v0.46.49 — Final tab document card: icon tile + Poppins title + meta
      // left, primary teal download button right. Wraps cleanly on mobile.
      '.hdlv2-dl-doc { display:flex; align-items:center; gap:14px; padding:16px 18px; background:#fff; border:1px solid #e4e6ea; border-radius:12px; flex-wrap:wrap; }',
      // v0.46.50 — when the card sits below other panel content (Stage-3 tab,
      // after the score grid / health background) give it breathing room.
      '.hdlv2-dl-doc--stack { margin-top:16px; }',
      '.hdlv2-dl-doc-ico { width:42px; height:42px; border-radius:10px; background:#e3eef1; color:#004F59; display:flex; align-items:center; justify-content:center; flex:0 0 42px; }',
      '.hdlv2-dl-doc-body { display:flex; flex-direction:column; gap:3px; min-width:0; flex:1 1 200px; }',
      '.hdlv2-dl-doc-title { font-family: Poppins, Inter, sans-serif; font-size:14.5px; font-weight:600; color:#111; }',
      '.hdlv2-dl-doc-meta { font-size:12px; color:#666; line-height:1.5; }',
      '.hdlv2-dl-btn { display:inline-flex; align-items:center; gap:7px; padding:10px 18px; border-radius:24px; background:#3d8da0; border:1px solid #3d8da0; color:#fff; text-decoration:none; font: 600 13px/1 Inter, -apple-system, sans-serif; transition: background .15s, border-color .15s; flex:0 0 auto; }',
      '.hdlv2-dl-btn:hover { background:#004F59; border-color:#004F59; color:#fff; }',
      '.hdlv2-dl-btn .hdlv2-fn-ico { width:15px; height:15px; flex:0 0 15px; }',
      '.hdlv2-dl-btn:hover .fn-ico-stem, .hdlv2-dl-btn:hover .fn-ico-head { animation: hdlv2-fn-bounce 0.7s ease infinite; }',
      // v0.46.67 — flight-plan editor (WFP tab) disabled-Save styling
      '.hdlv2-fpe-save[disabled] { opacity:.45; cursor:default; pointer-events:none; }',
      // v0.46.59 — WFP tab: Edit toggle + itshover download motion + the
      // B-series reskin of the embedded renderer (SCOPED to the dashboard
      // wrapper — the client page look is untouched).
      '.hdlv2-fpe-toggle.is-on { background:#004F59; border-color:#004F59; }',
      '.hdlv2-dl-btn:hover .hdlv2-iho-dl-arrow { animation: hdlv2-iho-drop .7s ease infinite; }',
      '@keyframes hdlv2-iho-drop { 0% { transform:translateY(0); opacity:1; } 45% { transform:translateY(4px); opacity:0; } 55% { transform:translateY(-3px); opacity:0; } 100% { transform:translateY(0); opacity:1; } }',
      '.hdlv2-wfp-dash .hdlv2-card { background:#fff; border:1px solid #e4e6ea; border-radius:12px; box-shadow:none; margin-top:12px; }',
      // v0.46.61 — hide the redundant "Week N Flight Plan" h2 on the dashboard
      // only: the head card above already shows "Weekly Flight Plan · Week N"
      // (Matthew). The date line stays. The client /my-flight-plan/ page has no
      // head card, so it keeps its h2 — this rule is scoped to .hdlv2-wfp-dash.
      '.hdlv2-wfp-dash .hdlv2-header h2 { display:none; }',
      '.hdlv2-wfp-dash .hdlv2-header p { font-size:12px; color:#666; margin:0; }',
      '.hdlv2-wfp-dash .hdlv2-header { padding:14px 24px 4px; }',
      '.hdlv2-wfp-dash .fp-transfer-actions { gap:10px; margin-top:10px; }',
      '.hdlv2-wfp-dash .fp-btn { padding:9px 18px; }',
      '.hdlv2-wfp-dash .fp-section-header { font:600 12px/1.4 Inter, sans-serif; letter-spacing:.04em; text-transform:uppercase; color:#3d8da0; }',
      '.hdlv2-wfp-dash .fp-transfer-box { background:#fff; border:1px solid #e4e6ea; border-radius:12px; }',
      '.hdlv2-wfp-dash .fp-transfer-title { font-family:Poppins, Inter, sans-serif; font-weight:600; color:#111; }',
      '.hdlv2-wfp-dash .fp-btn { border-radius:24px; font:600 13px/1 Inter, -apple-system, sans-serif; }',
      '.hdlv2-wfp-dash .fp-btn-primary { background:#3d8da0; border:1px solid #3d8da0; color:#fff; }',
      '.hdlv2-wfp-dash .fp-btn-primary:hover { background:#004F59; border-color:#004F59; }',
      '.hdlv2-wfp-dash .fp-btn-secondary, .hdlv2-wfp-dash .fp-btn-outline { background:#fff; border:1px solid #3d8da0; color:#3d8da0; }',
      '.hdlv2-wfp-dash .fp-btn-secondary:hover, .hdlv2-wfp-dash .fp-btn-outline:hover { background:#f0fbfc; }',
      '.hdlv2-wfp-dash .fp-identity-statement { background:#f8f9fb; border-left:3px solid #3d8da0; border-radius:8px; font-family:Poppins, Inter, sans-serif; }',
      '.hdlv2-wfp-dash .fp-adherence-card { background:#fff; border:1px solid #e4e6ea; border-radius:12px; }',
      // v0.24.9 — dead CSS removed. .hdlv2-fp-week, .hdlv2-fp-targets,
      // .hdlv2-fp-adh were used by the legacy loadFlightPlan summary
      // renderer that v0.24.5 replaced with a full-grid mount. Kept the
      // .hdlv2-fp-identity + .hdlv2-fp-block-label rules below — they are
      // still consumed by loadStage1, loadStage2, loadStage3.
      '.hdlv2-fp-identity { padding: 10px 14px; background: #fff; border-left: 3px solid #3d8da0; border-radius: 0 8px 8px 0; font-style: italic; color: #111; margin-bottom: 14px; font-size: 14px; font-weight: 500; line-height: 1.5; }',
      '.hdlv2-fp-block-label { font-size: 11px; font-weight: 600; color: #666; text-transform: uppercase; letter-spacing: 0.04em; margin: 14px 0 6px; }',
      '.hdlv2-checkin-list, .hdlv2-timeline-list { list-style: none; margin: 0; padding: 0; }',
      '.hdlv2-checkin-item, .hdlv2-timeline-item { padding: 12px 0; border-bottom: 1px solid #e4e6ea; }',
      '.hdlv2-checkin-item:last-child, .hdlv2-timeline-item:last-child { border-bottom: none; }',
      '.hdlv2-checkin-date, .hdlv2-timeline-meta { font-size: 10px; color: #888; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.04em; }',
      '.hdlv2-timeline-type { color: #3d8da0; font-weight: 600; }',
      '.hdlv2-consult-card { max-width: 540px; padding: 18px 20px; background: #fff; border: 1px solid #e4e6ea; border-radius: 10px; }',
      // B8 — the wordy <p> is gone; the one load-bearing fact (edit-as-reset)
      // survives as a 6-word caption under the button.
      '.hdlv2-consult-caption { margin-top: 8px; font-size: 11px; color: #666; font-family: Inter, -apple-system, sans-serif; }',
      '.hdlv2-tab-new { display:inline-block; background:#e3eef1; color:#2d7082; font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; padding:2px 6px; border-radius:4px; margin-left:6px; vertical-align:middle; }',
      // ── v0.24.0 — Stage 1 / Stage 2 / Stage 3 / Final tab cards ──
      '.hdlv2-st-card { display:block; }',
      '.hdlv2-st-meta { font-size: 10px; color: #888; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 500; }',
      '.hdlv2-st-row { display:flex; gap:10px; margin-bottom: 14px; flex-wrap: wrap; }',
      '.hdlv2-st-stat { flex:1; min-width:120px; padding:10px 14px; background:#fff; border:1px solid #e4e6ea; border-radius:10px; text-align:center; }',
      '.hdlv2-st-stat strong { display:block; font-family: Poppins, Inter, sans-serif; font-size:18px; font-weight:700; color:#004F59; margin-bottom:2px; line-height:1.1; }',
      '.hdlv2-st-stat span { display:block; font-size:10px; color:#888; text-transform:uppercase; letter-spacing:0.05em; }',
      // v0.41.23 — optional sub-line under the stat label (e.g. "vs. 50 actual"
      // for the Stage 1 Bio Age estimate card). Smaller weight + neutral tone
      // so the headline value stays dominant.
      '.hdlv2-st-stat small { display:block; margin-top:3px; font-size:10px; color:#94a3b8; text-transform:none; letter-spacing:0; font-weight:500; font-family: Inter, sans-serif; }',
      '.hdlv2-st-gauge { text-align:center; margin: 0 0 14px; padding: 8px; background: #fff; border: 1px solid #e4e6ea; border-radius: 10px; }',
      '.hdlv2-st-gauge img { max-width: 320px; max-height: 200px; height: auto; display: inline-block; }',
      // v0.24.2 — !important + padding-left:0 + list-style on <li> to defeat
      // Divi/V1 dashboard CSS that re-applies list-style: disc with higher
      // specificity, which produced double-bullets (teal pseudo + black disc).
      '.hdlv2-st-list { list-style:none !important; padding-left:0 !important; margin:0 !important; display:flex; flex-direction:column; gap:5px; font-family: Inter, -apple-system, sans-serif; }',
      '.hdlv2-st-list li { list-style:none !important; display:flex; justify-content:space-between; gap:10px; padding:7px 12px; background:#fafbfc; border:1px solid #f0f0f0; border-radius:6px; font-size:12px; color:#333; }',
      '.hdlv2-st-list li::before, .hdlv2-st-list li::marker { content:none !important; }',
      '.hdlv2-st-list li > span:first-child { color:#666; flex:0 0 auto; }',
      // v0.41.20 — value spans now carry the full Stage-1 option text (e.g.
      // "About 6–7 hours, reasonable quality"), not just the A-E letter.
      // text-align right + min-width 0 lets long strings wrap on a second
      // line right-aligned without pushing the label out of the row.
      '.hdlv2-st-list li > span:last-child { font-weight:600; color:#111; flex:1 1 auto; min-width:0; text-align:right; word-break:normal; overflow-wrap:anywhere; }',
      // v0.46.52 — Stage-1 answer severity colours (same bands + hexes as
      // Stage 3\'s .hdlv2-score-answer). Scoped under .hdlv2-st-list and
      // placed after the base rule above so they win the colour at equal
      // specificity; unscored rows keep the #111 base.
      '.hdlv2-st-list li > span.sev-high { color:#047857; }',
      '.hdlv2-st-list li > span.sev-mid { color:#92400e; }',
      '.hdlv2-st-list li > span.sev-low { color:#991b1b; }',
      '.hdlv2-st-bullets { list-style:none !important; padding-left:0 !important; margin:0 0 8px !important; font-family: Inter, -apple-system, sans-serif; }',
      '.hdlv2-st-bullets li { list-style:none !important; padding:5px 0 5px 18px; position:relative; font-size:13px; color:#333; line-height:1.5; }',
      '.hdlv2-st-bullets li::marker { content:none !important; }',
      '.hdlv2-st-bullets li::before { content:""; position:absolute; left:4px; top:11px; width:6px; height:6px; border-radius:50%; background:#3d8da0; }',
      '.hdlv2-st-quote { padding:10px 14px; background:#fafbfc; border:1px solid #e4e6ea; border-left:3px solid #3d8da0; border-radius:0 8px 8px 0; font-size:13px; color:#333; line-height:1.55; }',
      '.hdlv2-st-scores { display:grid; grid-template-columns:repeat(auto-fill, minmax(160px, 1fr)); gap:5px; }',
      '.hdlv2-st-score { display:flex; justify-content:space-between; align-items:center; padding:6px 12px; background:#fafbfc; border:1px solid #f0f0f0; border-radius:6px; font-size:12px; color:#333; }',
      '.hdlv2-st-score-pill { display:inline-block; min-width:24px; padding:1px 8px; background:#fff; border:1px solid #3d8da0; border-radius:6px; color:#004F59; font-family: Poppins, Inter, sans-serif; font-weight:700; font-size:12px; text-align:center; }',
      '.hdlv2-progress-eyebrow { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #3d8da0; margin: 0 0 4px; }',
      '.hdlv2-progress-headline { font-size: 13px; color: #1a1a1a; margin: 0 0 14px; line-height: 1.55; max-width: 760px; }',
      '.hdlv2-progress-chart-wrap { position: relative; height: 240px; background: #fafbfc; border: 1px solid #f0f0f0; border-radius: 10px; padding: 12px 8px 8px; }',
      '.hdlv2-progress-legend { display: flex; gap: 14px; flex-wrap: wrap; font-size: 11px; color: #6b7280; padding: 8px 4px 0; }',
      '.hdlv2-progress-legend .sw { display:inline-block; width:14px; height:3px; border-radius:2px; margin-right:6px; vertical-align:middle; }',
      '.hdlv2-progress-legend .sw-adh { background:#3d8da0; }',
      '.hdlv2-progress-legend .sw-rate { background:#f59e0b; }',
      '.hdlv2-progress-legend .sw-rate.dashed { background-image: linear-gradient(to right, #f59e0b 50%, transparent 50%); background-size: 6px 3px; background-color: transparent; }',
      '.hdlv2-progress-early { background:#fff; border:1px solid #e4e6ea; border-radius:10px; padding:6px 18px; max-width:760px; }',
      '.hdlv2-progress-early-row { display:flex; align-items:flex-start; gap:18px; padding:14px 0; border-bottom:1px solid #f0f0f0; }',
      '.hdlv2-progress-early-row:last-child { border-bottom:none; }',
      '.hdlv2-progress-early-label { flex:0 0 200px; font-size:11px; font-weight:600; color:#666; text-transform:uppercase; letter-spacing:0.06em; padding-top:2px; }',
      '.hdlv2-progress-early-value { flex:1; font-size:14px; color:#1a1a1a; line-height:1.5; }',
      '.hdlv2-progress-early-meta { color:#888; font-size:12px; }',
      '@media (max-width: 640px) { .hdlv2-progress-early-row { flex-direction:column; gap:4px; } .hdlv2-progress-early-label { flex:none; } }',
      '.hdlv2-v2-only-row td.client-name::after { content: "  V2 only"; font-size: 10px; font-weight: 500; color: #888; text-transform: uppercase; letter-spacing: 0.04em; margin-left: 6px; }',
      '.hdlv2-action-banner { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:14px 18px; background:#fffbeb; border:1px solid #fde68a; border-left:4px solid #d97706; border-radius:10px; margin:0 0 16px; flex-wrap:wrap; }',
      '.hdlv2-action-banner-text { flex:1; min-width:220px; }',
      '.hdlv2-action-banner-text strong { display:block; font-family: Poppins, Inter, sans-serif; font-size: 14px; color:#92400e; margin-bottom:2px; }',
      '.hdlv2-action-banner-text span { display:block; font-size:12px; color:#78350f; line-height:1.5; }',
      '.hdlv2-release-why-btn { appearance:none; padding:10px 22px; border:none; border-radius:48px; background:#d97706; color:#fff; font: 600 13px/1 Inter, -apple-system, sans-serif; cursor:pointer; transition:all 0.15s; white-space:nowrap; }',
      '.hdlv2-release-why-btn:hover:not(:disabled) { background:#b45309; transform: translateY(-1px); box-shadow:0 4px 12px rgba(217,119,6,0.3); }',
      '.hdlv2-release-why-btn:disabled { opacity:0.6; cursor:not-allowed; }',
      '.hdlv2-release-pulse { animation: hdlv2-release-pulse 1.2s ease-in-out 3; }',
      '@keyframes hdlv2-release-pulse { 0%,100% { box-shadow: 0 0 0 0 rgba(217,119,6,0.5); } 50% { box-shadow: 0 0 0 10px rgba(217,119,6,0); } }',
      '.hdlv2-action-banner-done { background:#ecfdf5; border-color:#a7f3d0; border-left-color:#10b981; }',
      '.hdlv2-action-banner-done strong { color:#065f46; }',
      '.hdlv2-action-banner-done span { color:#047857; }',
      // ── Action Queue (Wave A) ──
      '.hdlv2-action-queue-wrap { font-family: Inter, -apple-system, BlinkMacSystemFont, sans-serif; margin: 0 0 24px; background:#fff; border:1px solid #e4e6ea; border-radius:14px; box-shadow:0 4px 24px rgba(0,0,0,0.04); padding:18px 22px; transition: opacity 0.2s; }',
      '.hdlv2-action-queue-wrap:not(.hdlv2-aq-has-items) { background:#fafbfc; }',
      '.hdlv2-aq-header { display:flex; align-items:center; justify-content:space-between; gap:12px; margin:0 0 14px; flex-wrap:wrap; }',
      '.hdlv2-aq-title { font-family: Poppins, Inter, sans-serif; font-size:16px; font-weight:600; color:#111; margin:0; letter-spacing:0.01em; }',
      '.hdlv2-aq-freshness { font-size:11px; color:#94a3b8; font-weight:500; }',
      '.hdlv2-aq-alllink { margin-left:auto; font-family: Inter, sans-serif; font-size:13px; font-weight:600; color:#3d8da0; text-decoration:none; white-space:nowrap; }',
      '.hdlv2-aq-alllink:hover { color:#004F59; text-decoration:underline; }',
      '.hdlv2-aq-empty { padding:16px 0; font-size:13px; color:#888; text-align:left; }',
      '.hdlv2-aq-body { display:flex; flex-direction:column; gap:14px; }',
      '.hdlv2-aq-group { border-radius:10px; padding:12px 14px; border:1px solid #e4e6ea; background:#fafbfc; }',
      '.hdlv2-aq-group-release { border-left:4px solid #d97706; background:#fffbeb; border-color:#fde68a; }',
      '.hdlv2-aq-group-consult { border-left:4px solid #3b82f6; background:#eff6ff; border-color:#bfdbfe; }',
      '.hdlv2-aq-group-attn { border-left:4px solid #dc2626; background:#fef2f2; border-color:#fecaca; }',
      '.hdlv2-aq-group-head { display:flex; align-items:center; gap:10px; font-size:12px; font-weight:600; color:#111; margin:0 0 10px; text-transform:uppercase; letter-spacing:0.04em; }',
      '.hdlv2-aq-group-head .hdlv2-aq-dot { display:inline-block; width:10px; height:10px; border-radius:50%; flex:0 0 10px; box-shadow:0 0 0 2px rgba(255,255,255,.5) inset; }',
      '.hdlv2-aq-group-head .hdlv2-aq-group-label { flex:1; min-width:0; }',
      '.hdlv2-aq-count { display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 8px; border-radius:11px; background:rgba(0,0,0,0.08); font-size:11px; font-weight:600; color:#111; }',
      '.hdlv2-aq-list { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:8px; }',
      '.hdlv2-aq-item { display:flex; align-items:center; gap:12px; padding:10px 12px; border-radius:8px; background:#fff; border:1px solid rgba(0,0,0,0.06); transition: opacity 0.3s, transform 0.3s; }',
      '.hdlv2-aq-item-done { opacity:0; transform:translateX(20px); }',
      '.hdlv2-aq-item-name { font-weight:600; font-size:14px; color:#111; min-width:140px; flex:0 0 auto; }',
      '.hdlv2-aq-item-meta { flex:1; font-size:12px; color:#666; line-height:1.5; }',
      '.hdlv2-aq-item-action { flex:0 0 auto; }',
      '.hdlv2-aq-btn { display:inline-flex; align-items:center; justify-content:center; padding:8px 16px; border:none; border-radius:24px; font: 600 12px/1 Inter, -apple-system, sans-serif; cursor:pointer; text-decoration:none; transition:all 0.15s; white-space:nowrap; }',
      '.hdlv2-aq-btn-release { background:#d97706; color:#fff; }',
      '.hdlv2-aq-btn-release:hover:not(:disabled) { background:#b45309; box-shadow:0 4px 12px rgba(217,119,6,0.3); transform:translateY(-1px); }',
      '.hdlv2-aq-btn-release:disabled { opacity:0.6; cursor:not-allowed; }',
      '.hdlv2-aq-btn-consult { background:#3b82f6; color:#fff; }',
      '.hdlv2-aq-btn-consult:hover { background:#1d4ed8; box-shadow:0 4px 12px rgba(59,130,246,0.3); transform:translateY(-1px); color:#fff; }',
      '.hdlv2-aq-btn-attn { background:#fff; color:#dc2626; border:1px solid #fecaca; }',
      '.hdlv2-aq-btn-attn:hover { background:#fef2f2; border-color:#dc2626; }',
      '@media (max-width: 640px) {',
      '  .hdlv2-aq-item { flex-wrap:wrap; }',
      '  .hdlv2-aq-item-name { flex:1 1 auto; min-width:0; }',
      '  .hdlv2-aq-item-meta { flex:1 1 100%; }',
      '}',
      // Badge flash on poll-driven status change
      '.hdlv2-inline-badge.hdlv2-badge-flash { animation: hdlv2-badge-flash 1.2s ease-in-out; }',
      '@keyframes hdlv2-badge-flash { 0%,100% { transform: scale(1); } 30% { transform: scale(1.15); filter: brightness(1.1); } }',

      // ─────────────────────────────────────────────────────────────
      // v0.41.19 — Mini-stages strip inside the closed-row Status cell
      // 6-dot journey indicator: S1 · S2 · S3 · Consult · Final · Plan.
      // Sits AFTER the V2 status pill, doesn't change V1 table columns.
      // ─────────────────────────────────────────────────────────────
      '.hdlv2-mini-stages { display:inline-flex; align-items:center; gap:3px; margin-left:8px; vertical-align:middle; line-height:1; }',
      '.hdlv2-mini-stages-dot { display:inline-block; width:7px; height:7px; border-radius:50%; background:#e4e6ea; flex:0 0 7px; }',
      '.hdlv2-mini-stages-dot.done { background:#3d8da0; }',
      '.hdlv2-mini-stages-dot.active { background:#d97706; box-shadow:0 0 0 2px rgba(217,119,6,0.18); }',
      '.hdlv2-mini-stages-label { margin-left:4px; font-size:10.5px; color:#666; font-weight:500; letter-spacing:0.02em; font-variant-numeric:tabular-nums; font-family: Inter, -apple-system, sans-serif; }',

      // ─────────────────────────────────────────────────────────────
      // v0.41.19 — Expanded panel HEAD: name+email left, status pill +
      // 6-step journey ribbon right. Wraps on narrow viewports so the
      // ribbon never crops out of view.
      // ─────────────────────────────────────────────────────────────
      '.hdlv2-detail-head { display:flex; align-items:center; justify-content:space-between; gap:14px; padding-bottom:14px; margin-bottom:16px; border-bottom:1px solid #e4e6ea; flex-wrap:wrap; }',
      '.hdlv2-detail-head-id { display:flex; align-items:baseline; gap:10px; min-width:0; }',
      '.hdlv2-detail-head-id strong { font-family: Poppins, Inter, sans-serif; font-size:16px; font-weight:600; color:#111; }',
      '.hdlv2-detail-head-meta { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }',
      '.hdlv2-detail-status-pill { display:inline-flex; align-items:center; padding:4px 10px; border-radius:24px; font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; white-space:nowrap; font-family: Inter, -apple-system, sans-serif; }',
      '.hdlv2-fn-btn { display:inline-flex; align-items:center; gap:6px; height:30px; padding:0 13px 0 10px; border-radius:999px; border:1px solid #3d8da0; background:#fff; color:#3d8da0; cursor:pointer; flex-shrink:0; font-family: Inter, -apple-system, sans-serif; font-size:11px; font-weight:600; letter-spacing:0.01em; transition:background .15s; }',
      // B11.1 — in the Flight Notes tab card, size the button like the other
      // Download PDF pills (.hdlv2-detail-deeplink metrics + solid-fill
      // hover). Icon + loading ring use currentColor → invert correctly.
      '.hdlv2-consult-card .hdlv2-fn-btn { height:auto; padding:8px 16px 8px 13px; border-radius:24px; font: 500 13px/1 Inter, -apple-system, sans-serif; letter-spacing:0; margin-top:14px; transition: all 0.15s; }',
      '.hdlv2-consult-card .hdlv2-fn-btn:hover:not(:disabled) { background:#3d8da0; color:#fff; }',
      '.hdlv2-consult-card .hdlv2-fn-btn:hover:not(:disabled) .hdlv2-fn-ring { border-color:rgba(255,255,255,0.35); border-top-color:#fff; }',
      '.hdlv2-fn-btn:hover { background:rgba(61,141,160,0.08); }',
      '.hdlv2-fn-btn:disabled { cursor:default; opacity:0.85; }',
      // v0.46.51 — Flight Notes button inside the document card: solid teal
      // primary, matching .hdlv2-dl-btn exactly. Keeps the .hdlv2-fn-btn
      // loading machinery (content/spinner swap); ring inverts to white on
      // the solid fill. Placed after the base rules so the modifier wins.
      '.hdlv2-fn-btn--solid { height:auto; padding:10px 18px; border-radius:24px; background:#3d8da0; border-color:#3d8da0; color:#fff; font: 600 13px/1 Inter, -apple-system, sans-serif; letter-spacing:0; gap:7px; transition: background .15s, border-color .15s; }',
      '.hdlv2-fn-btn--solid:hover:not(:disabled) { background:#004F59; border-color:#004F59; color:#fff; }',
      '.hdlv2-fn-btn--solid .hdlv2-fn-ico { width:15px; height:15px; }',
      '.hdlv2-fn-btn--solid .hdlv2-fn-ring, .hdlv2-fn-btn--solid:hover:not(:disabled) .hdlv2-fn-ring { border-color:rgba(255,255,255,0.35); border-top-color:#fff; }',
      '.hdlv2-fn-content, .hdlv2-fn-loading { display:inline-flex; align-items:center; gap:6px; }',
      '.hdlv2-fn-loading { display:none; }',
      '.hdlv2-fn-btn.is-loading .hdlv2-fn-content { display:none; }',
      '.hdlv2-fn-btn.is-loading .hdlv2-fn-loading { display:inline-flex; }',
      '.hdlv2-fn-ico { display:block; }',
      '.hdlv2-fn-ico .fn-ico-stem, .hdlv2-fn-ico .fn-ico-head { transition: transform .2s ease; }',
      '.hdlv2-fn-btn:hover .fn-ico-stem, .hdlv2-fn-btn:hover .fn-ico-head { animation: hdlv2-fn-bounce 0.7s ease infinite; }',
      '.hdlv2-fn-ring { width:13px; height:13px; border-radius:50%; border:2px solid rgba(61,141,160,0.25); border-top-color:#3d8da0; animation: hdlv2-fn-spin 0.7s linear infinite; }',
      '@keyframes hdlv2-fn-bounce { 0%,80%,100%{transform:translateY(0)} 40%{transform:translateY(3px)} }',
      '@keyframes hdlv2-fn-spin { to { transform: rotate(360deg) } }',

      // ─────────────────────────────────────────────────────────────
      // v0.41.19 — Snapshot strip (4 KPI tiles) above the tab strip
      // ─────────────────────────────────────────────────────────────
      '.hdlv2-detail-snapshot { display:grid; grid-template-columns:repeat(4, 1fr); gap:10px; margin-bottom:18px; }',
      '@media (max-width: 820px) { .hdlv2-detail-snapshot { grid-template-columns:repeat(2, 1fr); } }',
      '.hdlv2-snap-tile { background:#fff; border:1px solid #e4e6ea; border-radius:10px; padding:12px 14px; }',
      '.hdlv2-snap-tile-label { font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; color:#666; margin-bottom:5px; }',
      '.hdlv2-snap-tile-value { font-family: Poppins, Inter, sans-serif; font-size:20px; font-weight:700; color:#004F59; line-height:1.1; font-variant-numeric:tabular-nums; }',
      '.hdlv2-snap-tile-value .hdlv2-snap-tile-unit { font-size:13px; color:#666; font-weight:500; margin-left:2px; }',
      '.hdlv2-snap-tile-next-line { display:block; font-size:14px; line-height:1.25; color:#004F59; font-weight:600; }',
      '.hdlv2-snap-tile-sub { margin-top:4px; font-size:11px; color:#888; line-height:1.4; }',
      '.hdlv2-snap-tile.accent-amber { border-left:3px solid #d97706; }',
      '.hdlv2-snap-tile.accent-teal  { border-left:3px solid #3d8da0; }',
      '.hdlv2-snap-tile.accent-blue  { border-left:3px solid #3b82f6; }',
      '.hdlv2-snap-tile.accent-grey  { border-left:3px solid #cbd5e1; }',

      // ─────────────────────────────────────────────────────────────
      // v0.41.19 — Tab strip enhancements: status dot per tab, group
      // separators, "current" selection class (replaces .active to
      // avoid collision with the .active-stage journey-state class).
      // v0.41.21 — display:inline-flex + align-items:center so the
      // gap property between dot and label actually takes effect
      // (gap is ignored on the default inline-block button).
      // ─────────────────────────────────────────────────────────────
      '.hdlv2-detail-tab { display:inline-flex; align-items:center; gap:7px; }',
      '.hdlv2-detail-tab.current { color:#3d8da0; border-bottom-color:#3d8da0; }',
      '.hdlv2-tab-dot { display:inline-flex; align-items:center; justify-content:center; width:14px; height:14px; border-radius:50%; background:#e4e6ea; color:#fff; font-size:9px; line-height:1; flex:0 0 14px; }',
      '.hdlv2-tab-dot svg { display:block; }',
      '.hdlv2-tab-dot.done { background:#3d8da0; }',
      '.hdlv2-tab-dot.active-stage { background:#d97706; box-shadow:0 0 0 2px rgba(217,119,6,0.18); }',
      '.hdlv2-detail-tab-sep { display:inline-block; width:1px; height:18px; background:#e4e6ea; margin:0 6px; align-self:center; flex:0 0 1px; }',
      // v0.41.21 — replace overflow-x:auto with flex-wrap so the wider tab
      // strip (now carrying status dots + group separators) wraps cleanly
      // on narrow panels instead of triggering a scrollbar. Belt-and-braces
      // scrollbar suppression in case any host theme injects an overflow.
      '.hdlv2-detail-tabs { flex-wrap:wrap; overflow-x:visible; overflow-y:visible; scrollbar-width:none; }',
      '.hdlv2-detail-tabs::-webkit-scrollbar { display:none; }',

      // ─────────────────────────────────────────────────────────────
      // v0.41.19 — Progress tab — 2-col grid (metrics left, baseline right)
      // ─────────────────────────────────────────────────────────────
      '.hdlv2-progress-grid { display:grid; grid-template-columns:1.4fr 1fr; gap:18px; align-items:start; }',
      '@media (max-width: 900px) { .hdlv2-progress-grid { grid-template-columns:1fr; } }',
      '.hdlv2-progress-card { background:#fff; border:1px solid #e4e6ea; border-radius:12px; padding:16px 18px; }',
      '.hdlv2-progress-card-title { font-family: Poppins, Inter, sans-serif; font-size:13px; font-weight:600; color:#111; margin:0 0 4px; display:flex; align-items:center; justify-content:space-between; gap:10px; }',
      '.hdlv2-progress-card-meta { font-size:11px; color:#888; margin-bottom:12px; }',
      '.hdlv2-progress-metric-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:8px; }',
      '.hdlv2-metric-tile { padding:10px 11px; background:#fafbfc; border:1px solid #f0f0f0; border-radius:8px; }',
      '.hdlv2-metric-tile-value { font-family: Poppins, Inter, sans-serif; font-size:18px; font-weight:700; color:#004F59; line-height:1.1; font-variant-numeric:tabular-nums; }',
      '.hdlv2-metric-tile-unit { font-size:12px; font-weight:500; color:#666; margin-left:1px; }',
      '.hdlv2-metric-tile-label { font-size:10px; text-transform:uppercase; letter-spacing:0.05em; color:#666; margin-top:4px; font-weight:600; }',
      // v0.46.52 — <50% steps to red (was amber, same as .mid): completes the
      // three-step severity ramp used by every other scored surface.
      '.hdlv2-metric-tile.lo .hdlv2-metric-tile-value { color:#991b1b; }',
      '.hdlv2-metric-tile.mid .hdlv2-metric-tile-value { color:#92400e; }',
      '.hdlv2-progress-baseline-row { display:flex; align-items:baseline; gap:10px; margin-bottom:6px; }',
      '.hdlv2-progress-baseline-value { font-family: Poppins, Inter, sans-serif; font-size:32px; font-weight:700; color:#004F59; letter-spacing:-0.5px; line-height:1; font-variant-numeric:tabular-nums; }',
      '.hdlv2-progress-baseline-unit { font-size:13px; color:#666; }',
      '.hdlv2-progress-next-outcome { margin-top:14px; padding-top:14px; border-top:1px solid #f0f0f0; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }',
      '.hdlv2-progress-next-text { font-size:12px; color:#666; }',
      '.hdlv2-progress-next-text strong { display:block; color:#111; font-weight:600; font-size:13px; margin-top:2px; }',
      '.hdlv2-progress-next-chip { display:inline-flex; align-items:center; gap:5px; padding:4px 9px; border-radius:999px; background:#e3eef1; border:1px solid #b8d9e0; font-size:11px; font-weight:600; color:#004F59; }',
      '.hdlv2-progress-hint { margin-top:14px; padding:10px 14px; background:#fafbfc; border:1px dashed #e4e6ea; border-radius:8px; font-size:12px; color:#666; display:flex; align-items:center; gap:10px; }',
      '.hdlv2-progress-hint svg { width:16px; height:16px; color:#3d8da0; flex:0 0 16px; }',

      // ─────────────────────────────────────────────────────────────
      // v0.41.19 — Stage 1 — 2-col grid (stats+Q&A left, gauge right)
      // ─────────────────────────────────────────────────────────────
      '.hdlv2-s1-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; align-items:start; }',
      '@media (max-width: 900px) { .hdlv2-s1-grid { grid-template-columns:1fr; } }',
      '.hdlv2-st-gauge-card { padding:8px 12px 14px; background:#fff; border:1px solid #e4e6ea; border-radius:12px; text-align:center; }',
      '.hdlv2-st-gauge-img { display:block; margin:0 auto; max-width:340px; width:100%; height:auto; }',
      '.hdlv2-st-gauge-caption { font-size:12px; color:#666; margin-top:-4px; }',

      // ─────────────────────────────────────────────────────────────
      // v0.41.19 — Stage 2 — hero WHY + 3-col profile + vision card
      // ─────────────────────────────────────────────────────────────
      '.hdlv2-why-hero { padding:18px 22px; background:#fff; border:1px solid #e4e6ea; border-left:4px solid #3d8da0; border-radius:0 12px 12px 0; font-size:15px; font-style:italic; color:#111; line-height:1.55; font-weight:500; margin-bottom:14px; }',
      '.hdlv2-vision-card { margin-top:14px; padding:14px 18px; background:#fafbfc; border:1px solid #e4e6ea; border-left:3px solid #3d8da0; border-radius:0 10px 10px 0; font-size:13px; color:#2c3e50; line-height:1.55; }',
      '.hdlv2-vision-card-label { font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#666; margin-bottom:6px; }',

      // ─────────────────────────────────────────────────────────────
      // v0.41.19 — Stage 3 — top stats row, body 2-col, grouped scores,
      // Section 6 Health Background block (v0.38.0).
      // ─────────────────────────────────────────────────────────────
      '.hdlv2-s3-stats { display:grid; grid-template-columns:repeat(3, 1fr); gap:8px; margin-bottom:16px; }',
      '@media (max-width: 700px) { .hdlv2-s3-stats { grid-template-columns:1fr; } }',
      // B5 — six row-data groups in 4 explicit columns; minmax(0,1fr) stops
      // content blowout so the grid can never horizontal-scroll. Stacked
      // (overflow) groups get a bold heading via .is-stacked.
      '.hdlv2-s3-grid { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:12px; align-items:start; margin-bottom:18px; }',
      '@media (max-width: 1100px) { .hdlv2-s3-grid { grid-template-columns:repeat(2, minmax(0,1fr)); } }',
      '@media (max-width: 700px) { .hdlv2-s3-grid { grid-template-columns:1fr; } }',
      '.hdlv2-s3-col { display:flex; flex-direction:column; gap:12px; min-width:0; }',
      '.hdlv2-score-group.is-stacked h4 { font-weight:700; color:#111; border-bottom-color:#e4e6ea; }',
      '.hdlv2-score-val { font-weight:600; color:#111; font-size:12px; text-align:right; overflow-wrap:anywhere; }',
      '.hdlv2-score-group { background:#fff; border:1px solid #e4e6ea; border-radius:10px; padding:12px 14px; }',
      '.hdlv2-score-group h4 { font-family: Poppins, Inter, sans-serif; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#666; margin:0 0 8px; padding-bottom:6px; border-bottom:1px solid #f0f0f0; }',
      '.hdlv2-score-row { display:flex; justify-content:space-between; align-items:center; padding:4px 0; font-size:12px; color:#333; }',
      // v0.46.50 — the row\'s data is the client\'s actual answer (or the real
      // measured value for derived metrics), right-aligned like the kv rows
      // and coloured by the same severity bands the old 0-5 pill used, so
      // good/bad reads at a glance with no abstract code. Colours match the
      // pill text colours exactly (status palette, AA on the white card).
      '.hdlv2-score-answer { font-weight:600; font-size:12px; text-align:right; overflow-wrap:anywhere; max-width:62%; line-height:1.4; }',
      '.hdlv2-score-answer.sev-high { color:#047857; }',
      '.hdlv2-score-answer.sev-mid { color:#92400e; }',
      '.hdlv2-score-answer.sev-low { color:#991b1b; }',
      '.hdlv2-score-pill { display:inline-block; min-width:36px; padding:2px 9px; background:#fafbfc; border:1px solid #f0f0f0; border-radius:6px; color:#111; font-family: Poppins, Inter, sans-serif; font-weight:700; font-size:11.5px; text-align:center; font-variant-numeric:tabular-nums; }',
      '.hdlv2-score-pill.high { color:#047857; border-color:#a7f3d0; background:#ecfdf5; }',
      '.hdlv2-score-pill.mid { color:#92400e; border-color:#fde68a; background:#fffbeb; }',
      '.hdlv2-score-pill.low { color:#991b1b; border-color:#fecaca; background:#fef2f2; }',
      // v0.41.22 — "/5" suffix lives inside the pill; lighter weight and
      // slightly lower opacity so the digit reads as the primary value.
      '.hdlv2-score-pill-max { font-weight:500; opacity:0.6; margin-left:1px; }',
      '.hdlv2-hb-section-h { font-family: Poppins, Inter, sans-serif; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#666; margin:20px 0 10px; }',
      '.hdlv2-hb-note { display:inline-block; margin-left:8px; font-family: Inter, sans-serif; font-size:10.5px; font-weight:500; letter-spacing:0.02em; text-transform:none; color:#888; }',
      '.hdlv2-hb-grid { display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; }',
      '@media (max-width: 820px) { .hdlv2-hb-grid { grid-template-columns:1fr; } }',
      '.hdlv2-hb-card { background:#fff; border:1px solid #e4e6ea; border-radius:10px; padding:12px 14px; }',
      '.hdlv2-hb-card-head { display:flex; align-items:center; gap:7px; font-family: Poppins, Inter, sans-serif; font-size:11.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; color:#004F59; margin-bottom:8px; padding-bottom:8px; border-bottom:1px solid #f0f0f0; }',
      '.hdlv2-hb-card-head .ico { width:14px; height:14px; color:#3d8da0; flex:0 0 14px; }',
      '.hdlv2-hb-card p { margin:0; font-size:12.5px; color:#2c3e50; line-height:1.55; white-space:pre-wrap; }',
      '.hdlv2-hb-card.is-empty p { color:#94a3b8; font-style:italic; }',
      // W11 — SELF-REPORTED badge + Source filter chips. Same palette as
      // existing inline badges; distinct value-only styling (light teal
      // surface + dark teal text) so it reads as a source label, not a
      // status pill.
      '.hdlv2-self-reported-badge { color:#3D8DA0; background:#EAF4F7; border:1px solid rgba(61,141,160,0.30); }',
      '.hdlv2-source-filter { display:flex; align-items:center; gap:8px; padding:10px 0 12px; margin:0 0 8px; flex-wrap:wrap; }',
      '.hdlv2-source-filter-label { font-family: Inter, -apple-system, sans-serif; font-size:12px; font-weight:600; color:#888; text-transform:uppercase; letter-spacing:0.04em; }',
      '.hdlv2-source-chip { display:inline-flex; align-items:center; padding:5px 12px; border:1px solid #e4e6ea; border-radius:24px; background:#fff; color:#555; font-family: Inter, -apple-system, sans-serif; font-size:12px; font-weight:500; cursor:pointer; transition: border-color 0.12s, color 0.12s, background 0.12s; }',
      '.hdlv2-source-chip:hover { border-color:#3d8da0; color:#3d8da0; }',
      '.hdlv2-source-chip.current { background:#3d8da0; color:#fff; border-color:#3d8da0; }',
      '.hdlv2-source-chip.current:hover { background:#327686; }',
      '.hdlv2-auto-consult-card { background:#fff; border:1px solid #e4e6ea; border-radius:12px; padding:20px 22px; font-family: Inter, -apple-system, sans-serif; color:#2c3e50; }',
      '.hdlv2-auto-consult-card h3 { font-family: Poppins, Inter, sans-serif; font-weight:600; font-size:16px; color:#004F59; margin:0 0 6px; }',
      '.hdlv2-auto-consult-meta { font-size:12px; color:#888; margin:0 0 14px; }',
      '.hdlv2-auto-consult-body { background:#f8f9fb; border-left:3px solid #3d8da0; border-radius:6px; padding:12px 14px; margin:0 0 14px; font-size:14px; line-height:1.6; color:#2c3e50; white-space:pre-wrap; max-height:360px; overflow-y:auto; }',
      '.hdlv2-auto-consult-empty { color:#888; font-size:13px; font-style:italic; }',
      '.hdlv2-auto-consult-footer { font-size:12.5px; color:#555; font-style:italic; margin-top:10px; }',
      '.hdlv2-auto-safety-banner { background:#fffbeb; border:1px solid #fde68a; color:#92400e; padding:10px 14px; border-radius:8px; font-size:13px; font-family: Inter, -apple-system, sans-serif; margin:0 0 12px; }',
      // Red-flag banner — own CSS, separate from hdlv2-action-banner.
      '.hdlv2-redflag-banner { padding:14px 16px; background:#fef2f2; border:1px solid #fecaca; border-left:4px solid #dc2626; border-radius:10px; margin:0 0 16px; }',
      '.hdlv2-redflag-banner[data-kind="failed"] { background:#fffbeb; border-color:#fde68a; border-left-color:#d97706; }',
      '.hdlv2-redflag-head { font-family: Poppins, Inter, sans-serif; font-size:14px; font-weight:700; color:#991b1b; margin:0 0 8px; }',
      '.hdlv2-redflag-banner[data-kind="failed"] .hdlv2-redflag-head { color:#92400e; }',
      '.hdlv2-redflag-sub { font-family: Inter, -apple-system, sans-serif; font-size:12px; color:#78350f; line-height:1.5; }',
      '.hdlv2-redflag-item { display:flex; align-items:flex-start; gap:10px; padding:7px 0; border-top:1px solid rgba(220,38,38,0.10); }',
      '.hdlv2-redflag-item:first-of-type { border-top:none; }',
      '.hdlv2-redflag-chip { flex:0 0 auto; display:inline-flex; align-items:center; padding:3px 9px; border-radius:24px; font-family: Inter, -apple-system, sans-serif; font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.03em; white-space:nowrap; }',
      '.hdlv2-redflag-chip.hard { color:#dc2626; background:#fff; border:1px solid #fecaca; }',
      '.hdlv2-redflag-chip.amber { color:#d97706; background:#fff; border:1px solid #fde68a; }',
      '.hdlv2-redflag-chip.pattern { color:#3b82f6; background:#fff; border:1px solid #bfdbfe; }',
      '.hdlv2-redflag-chip.context { color:#6b7280; background:#fff; border:1px solid #e5e7eb; }',
      '.hdlv2-redflag-note { flex:1; font-family: Inter, -apple-system, sans-serif; font-size:12.5px; color:#3a3a3a; line-height:1.55; }',
      // Status filter bar (Needs attention).
      '.hdlv2-filter-bar { display:flex; align-items:center; gap:8px; padding:10px 0 12px; margin:0 0 8px; flex-wrap:wrap; }',
      '.hdlv2-filter-label { font-family: Inter, -apple-system, sans-serif; font-size:12px; font-weight:600; color:#888; text-transform:uppercase; letter-spacing:0.04em; }',
      '.hdlv2-filter-chip { display:inline-flex; align-items:center; padding:5px 12px; border:1px solid #e4e6ea; border-radius:24px; background:#fff; color:#555; font-family: Inter, -apple-system, sans-serif; font-size:12px; font-weight:500; cursor:pointer; transition: border-color 0.12s, color 0.12s, background 0.12s; }',
      '.hdlv2-filter-chip:hover { border-color:#3d8da0; color:#3d8da0; }',
      '.hdlv2-filter-chip.current { background:#3d8da0; color:#fff; border-color:#3d8da0; }',
      '.hdlv2-filter-chip.current:hover { background:#327686; }',
      // Stalled-leads targeting filter — themed dropdown row (own bar, generous padding).
      '.hdlv2-stalled-bar { display:flex; align-items:center; flex-wrap:wrap; gap:12px 22px; padding:16px 16px 18px 24px; margin:0; }',
      '.hdlv2-stalled-title { font-family: Inter, -apple-system, sans-serif; font-size:13px; font-weight:600; color:#2c3e50; }',
      '.hdlv2-stalled-field { position:relative; display:inline-flex; align-items:center; gap:8px; }',
      '.hdlv2-stalled-flabel { font-family: Inter, -apple-system, sans-serif; font-size:12px; font-weight:500; color:#888; white-space:nowrap; }',
      '.hdlv2-stalled-select { font-family: Inter, -apple-system, sans-serif; font-size:13px; font-weight:500; color:#2c3e50; background:#fff; border:1px solid #e4e6ea; border-radius:8px; padding:7px 32px 7px 12px; cursor:pointer; appearance:none; -webkit-appearance:none; -moz-appearance:none; transition:border-color .12s, box-shadow .12s; }',
      '.hdlv2-stalled-select:hover { border-color:#3d8da0; }',
      '.hdlv2-stalled-select:focus { outline:none; border-color:#3d8da0; box-shadow:0 0 0 3px rgba(61,141,160,0.15); }',
      '.hdlv2-stalled-field::after { content:""; position:absolute; right:13px; top:50%; width:7px; height:7px; border-right:1.5px solid #8a9099; border-bottom:1.5px solid #8a9099; transform:translateY(-70%) rotate(45deg); pointer-events:none; }',
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

  // P2 — attribute-context escape. esc()'s div round-trip escapes & < > but
  // NOT double quotes, so a value placed inside attr="…" could break out.
  function attrEsc(s) {
    return esc(s).replace(/"/g, '&quot;');
  }
})();
