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
  var versionTimer     = null;
  var fallbackTimer    = null;
  var lastVersion      = 0;
  var lastFetchedAt    = 0;
  var freshnessTimer   = null;

  function init() {
    injectStyles();
    mountActionQueueShell();
    bindDeleteV2Client();
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
    if (!fallbackTimer) fallbackTimer = setInterval(pollNow, FALLBACK_POLL_MS);
  }
  function stopPolling() {
    if (versionTimer)  { clearInterval(versionTimer);  versionTimer = null; }
    if (fallbackTimer) { clearInterval(fallbackTimer); fallbackTimer = null; }
  }
  // Fetch the digest. If it advanced, pull the full roster.
  function pollVersion() {
    fetch(CFG.api_base + '/dashboard/version?_=' + Date.now(), {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': CFG.nonce },
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
    Promise.all([fetchV2Clients(), fetchPendingLeads()]).then(function (results) {
      var list  = results[0] || [];
      var leads = results[1] || [];
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
      // loadFlightPlan re-mounts; HDLV2_FlightPlan.mount handles its own
      // teardown of the previous mount so we don't leak event handlers.
      loadFlightPlan(fresh, content);
    });
  }

  // v0.35.1 — Pending widget submissions for this practitioner. Fault-
  // tolerant: returns [] on any error so a transient network hiccup or a
  // server-side schema mismatch can't break the V1 client list render.
  function fetchPendingLeads() {
    return fetch(CFG.api_base + '/widget/leads/pending', {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': CFG.rest_nonce || CFG.nonce },
      cache: 'no-store',
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        return (data && Array.isArray(data.leads)) ? data.leads : [];
      })
      .catch(function () { return []; });
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

  function todayLocal() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  function computeQueueHash(list) {
    // Signature includes only the clients that WOULD appear in the banner
    // plus their latest_event_at — so a new check-in on a flagged client
    // (even if still flagged) bumps the hash and un-dismisses the banner.
    //
    // v0.35.1 — pending widget submissions are part of the signature too,
    // so receiving a new lead while the banner is collapsed auto-expands
    // it on the next render (matches the same UX as receiving a new
    // check-in).
    var relevant = (list || []).filter(function (c) {
      return c.status === 'awaiting_why_release'
          || c.status === 'awaiting_consult'
          || c.status === 'needs_attention';
    }).map(function (c) {
      return [c.user_id, c.status, c.latest_event_at || ''].join('|');
    });
    (state.pendingLeads || []).forEach(function (lead) {
      relevant.push(['lead', lead.id, lead.created_at || ''].join('|'));
    });
    relevant.sort();
    return relevant.join('~');
  }

  function getCollapseState() {
    try {
      var raw = window.localStorage.getItem(COLLAPSE_KEY);
      if (!raw) return null;
      var parsed = JSON.parse(raw);
      return parsed && parsed.hash ? parsed : null;
    } catch (e) { return null; }
  }

  function setCollapseState(hash) {
    try {
      window.localStorage.setItem(COLLAPSE_KEY, JSON.stringify({ hash: hash, date: todayLocal() }));
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
      // Persist collapse with the current content hash so it auto-expands
      // when something changes (new release-WHY, new check-in, new
      // awaiting-consult, etc.).
      var hash = computeQueueHash(state.clients || []);
      setCollapseState(hash);
    } else {
      clearCollapseState();
    }
  }

  function applyCollapseState(list) {
    if (!state.actionQueueEl) return;
    var collapsed = getCollapseState();
    if (!collapsed) { setToggleUI(false); return; }
    // New day → expand again, clear stale state
    if (collapsed.date !== todayLocal()) { clearCollapseState(); setToggleUI(false); return; }
    // New event → expand again, clear stale state
    var currentHash = computeQueueHash(list);
    if (collapsed.hash !== currentHash) { clearCollapseState(); setToggleUI(false); return; }
    // Same content, same day → keep collapsed (practitioner's preference)
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
    applyCollapseState(list);

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
    return '<li class="hdlv2-aq-item hdlv2-aq-item-lead" data-lead-id="' + esc(lead.id) + '">'
      +   '<div class="hdlv2-aq-item-name">' + name + '</div>'
      +   '<div class="hdlv2-aq-item-meta">' + email + ageStr + ' &middot; ' + rate + '&times;'
      +     (submittedAt ? ' &middot; ' + esc(submittedAt) : '')
      +   '</div>'
      +   '<div class="hdlv2-aq-item-action" style="display:flex;gap:5px;">'
      +     '<button type="button" class="hdlv2-aq-btn hdlv2-aq-btn-pending-confirm" data-aq="lead-confirm" data-lead-id="' + esc(lead.id) + '" title="Send the client their next-step magic link" style="padding:5px 11px;font-size:12px;border:1px solid #3d8da0;background:#3d8da0;color:#fff;border-radius:999px;cursor:pointer;font-weight:600;font-family:inherit;line-height:1.3;">Confirm</button>'
      +     '<button type="button" class="hdlv2-aq-btn hdlv2-aq-btn-pending-reject"  data-aq="lead-reject"  data-lead-id="' + esc(lead.id) + '" title="Silently drop without emailing" style="padding:5px 11px;font-size:12px;border:1px solid #e4e6ea;background:#fff;color:#666;border-radius:999px;cursor:pointer;font-weight:600;font-family:inherit;line-height:1.3;">Reject</button>'
      +   '</div>'
      + '</li>';
  }

  // v0.35.1 — Bind Confirm/Reject + "+ N more" handlers inside the action
  // queue's pending-leads group. Reuses the same REST endpoints as the
  // modal tab so behaviour is identical regardless of where the action
  // is taken. Re-fetches both the client roster and the leads list on
  // success so the V1 list shows the new client immediately.
  function bindPendingLeadQueueActions() {
    if (!state.actionQueueEl) return;
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
        // V2-only row we haven't rendered yet (e.g. new client appeared)
        if (!state.matched[c.email_hash]) {
          state.tbody.appendChild(buildV2OnlyRow(c));
          state.matched[c.email_hash] = true;
        }
        return;
      }
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

    // v0.41.19 — append a 6-dot journey strip alongside the badge so the
    // practitioner sees stage progression at a glance from the closed
    // row, no expand required. Sits INSIDE .status-badge-cell so V1's
    // table structure (7 columns) is unchanged — no header shifting,
    // sort comparator stays accurate.
    injectMiniStages(cell, c);
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
    wrap.title = 'Stage 1 · Stage 2 · Stage 3 · Consultation · Final · Flight Plan';
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
    var journeyRibbon = renderJourneyRibbon(dots);
    var snapshotShell = renderSnapshotShell();

    panel.innerHTML = [
      // Head — name, email, status pill, 6-step journey ribbon on one line.
      '<div class="hdlv2-detail-head">',
      '<div class="hdlv2-detail-head-id">',
      '<strong>' + esc(c.name) + '</strong>',
      '<span class="hdlv2-detail-email">' + esc(c.email || '') + '</span>',
      '</div>',
      '<div class="hdlv2-detail-head-meta">',
      headPill,
      journeyRibbon,
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
      tabHtml('progress',     'Progress',      'active'),
      '<span class="hdlv2-detail-tab-sep" aria-hidden="true"></span>',
      tabHtml('stage1',       'Stage 1',       dots[0]),
      tabHtml('stage2',       'Stage 2',       dots[1]),
      tabHtml('stage3',       'Stage 3',       dots[2]),
      '<span class="hdlv2-detail-tab-sep" aria-hidden="true"></span>',
      tabHtml('consultation', 'Consultation',  dots[3]),
      tabHtml('final',        'Final',         dots[4]),
      tabHtml('flight-plan',  'Flight Plan',   dots[5]),
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
    var dotClass = 'hdlv2-tab-dot';
    if (journeyState === 'done')   dotClass += ' done';
    if (journeyState === 'active') dotClass += ' active-stage';
    // The 'active' value for the Progress tab is the journeyState arg from
    // buildDetailPanel — translate to the 'current' selection class via the
    // outer code that runs after innerHTML. Keep dot class for visual hint.
    return '<button type="button" class="hdlv2-detail-tab" data-tab="' + tabKey + '">'
      + '<span class="' + dotClass + '" aria-hidden="true"></span>'
      + esc(label)
      + '</button>';
  }

  function renderJourneyRibbon(dots) {
    var labels = ['Stage 1', 'Stage 2', 'Stage 3', 'Consult', 'Final', 'Flight Plan'];
    var parts  = ['<div class="hdlv2-detail-journey" aria-label="Client journey">'];
    for (var i = 0; i < dots.length; i++) {
      if (i > 0) parts.push('<span class="hdlv2-detail-journey-sep" aria-hidden="true"></span>');
      var state = dots[i];
      var mark  = state === 'done' ? '&#10003;' : (state === 'active' ? '&#9679;' : '');
      parts.push(
        '<span class="hdlv2-detail-journey-item state-' + state + '">'
          + '<span class="hdlv2-detail-journey-mark" aria-hidden="true">' + mark + '</span>'
          + esc(labels[i])
        + '</span>'
      );
    }
    parts.push('</div>');
    return parts.join('');
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
    // v0.24.0 — journey-order routing. Stage 1/2/3/Final share one cached
    // /dashboard/client-record/{progress_id} fetch, so switching between
    // them is instant after first hit.
    if (tab === 'progress')      return loadProgress(c, target);
    if (tab === 'stage1')        return loadStage1(c, target);
    if (tab === 'stage2')        return loadStage2(c, target);
    if (tab === 'stage3')        return loadStage3(c, target);
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
      var leftStats = '<div class="hdlv2-st-row">'
        +   '<div class="hdlv2-st-stat"><strong>' + esc(s1.rate != null ? s1.rate + '×' : '—') + '</strong><span>Rate of ageing</span></div>'
        +   '<div class="hdlv2-st-stat"><strong>' + esc(s1.age != null ? s1.age : '—') + '</strong><span>Chronological age</span></div>'
        +   '<div class="hdlv2-st-stat"><strong>' + esc(capSex) + '</strong><span>Sex</span></div>'
        + '</div>';

      var rows = [];
      if (s1.q2_silhouette) rows.push(['Body shape', 'silhouette ' + s1.q2_silhouette + '/5 · ' + (s1.q2_fat_dist || 'even')]);
      if (s1.q3_zone2)      rows.push(['Zone-2 cardio (Q3)', s1.q3_zone2]);
      if (s1.q4_vo2)        rows.push(['VO2 / cardio reserve (Q4)', s1.q4_vo2]);
      if (s1.q5_sts)        rows.push(['Sit-to-stand (Q5)', s1.q5_sts]);
      if (s1.q6_sleep)      rows.push(['Sleep (Q6)', s1.q6_sleep]);
      if (s1.q7_smoking)    rows.push(['Smoking (Q7)', s1.q7_smoking]);
      if (s1.q8_social)     rows.push(['Social connection (Q8)', s1.q8_social]);
      if (s1.q9_diet)       rows.push(['Diet (Q9)', s1.q9_diet]);
      var leftList = rows.length
        ? '<div class="hdlv2-fp-block-label">9-question answers</div>'
          + '<ul class="hdlv2-st-list">'
          + rows.map(function (r) { return '<li><span>' + esc(r[0]) + '</span><span>' + esc(r[1]) + '</span></li>'; }).join('')
          + '</ul>'
        : '';

      var rightGauge = s1.gauge_url
        ? '<div class="hdlv2-st-gauge-card">'
          +   '<img class="hdlv2-st-gauge-img" src="' + esc(s1.gauge_url) + '" alt="Pace of ageing gauge · ' + esc((s1.rate != null ? s1.rate + 'x' : '')) + '">'
          +   '<div class="hdlv2-st-gauge-caption">Pace of ageing &middot; ' + esc(s1.rate != null ? s1.rate + '× chronological' : 'pending') + '</div>'
          + '</div>'
        : '<div class="hdlv2-st-gauge-card"><div class="hdlv2-detail-empty">Gauge image unavailable.</div></div>';

      target.innerHTML = '<div class="hdlv2-st-card">'
        + '<div class="hdlv2-st-meta">Stage 1 &middot; Quick insight &middot; Completed ' + esc(formatDate(s1.completed_at)) + '</div>'
        + '<div class="hdlv2-s1-grid">'
        +   '<div>' + leftStats + leftList + '</div>'
        +   '<div>' + rightGauge + '</div>'
        + '</div>'
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

      // v0.41.19 — hero WHY quote → 3-col profile (Key People / Motivations /
      // Fears) → vision card full-width. Same data as before, organised by
      // category instead of stacked single-column lists.
      var releaseTag = s2.released
        ? ' &middot; <span style="color:#047857;font-weight:600">released</span>'
        : ' &middot; <span style="color:#d97706;font-weight:600">awaiting WHY release</span>';

      // Normalisers — items can be plain strings or shaped {name|label|text|relationship}.
      function normItem(it) {
        if (!it) return '';
        if (typeof it === 'string') return it;
        return it.name || it.label || it.text || it.relationship || '';
      }
      function listOf(arr) {
        return Array.isArray(arr)
          ? arr.map(normItem).filter(function (v) { return v; })
          : [];
      }
      var people = listOf(s2.key_people);
      var motiv  = listOf(s2.motivations);
      var fears  = listOf(s2.fears);

      function whyCard(title, items, iconSvg, emptyMsg) {
        var body = items.length
          ? '<ul>' + items.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>'
          : '<div class="hdlv2-why-card-empty">' + esc(emptyMsg) + '</div>';
        return '<div class="hdlv2-why-card">'
          + '<h4>' + iconSvg + '<span>' + esc(title) + '</span></h4>'
          + body
          + '</div>';
      }

      var iconPeople = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
      var iconMotiv  = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15 8 22 9 17 14 18 21 12 18 6 21 7 14 2 9 9 8 12 2"/></svg>';
      var iconFears  = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';

      var hero = s2.distilled_why
        ? '<div class="hdlv2-why-hero">&ldquo;' + esc(s2.distilled_why) + '&rdquo;</div>'
        : '<div class="hdlv2-detail-empty">No distilled WHY recorded.</div>';

      var grid = '<div class="hdlv2-why-grid">'
        + whyCard('Key people',  people, iconPeople, 'No key people noted')
        + whyCard('Motivations', motiv,  iconMotiv,  'No motivations noted')
        + whyCard('Fears',       fears,  iconFears,  'No fears noted')
        + '</div>';

      var vision = s2.vision_text
        ? '<div class="hdlv2-vision-card"><div class="hdlv2-vision-card-label">Vision &middot; free-text</div>' + esc(s2.vision_text) + '</div>'
        : '';

      target.innerHTML = '<div class="hdlv2-st-card">'
        + '<div class="hdlv2-st-meta">Stage 2 &middot; WHY profile &middot; Completed ' + esc(formatDate(s2.completed_at)) + releaseTag + '</div>'
        + hero
        + grid
        + vision
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

      // v0.41.19 — top stat strip, 2-col body composition, scores grouped
      // into 4 functional categories (Activity & Body / Sleep, Stress &
      // Social / Diet & Lifestyle / Vitals), then Section 6 Health
      // Background block (v0.38.0 — family_history, medications,
      // existing_conditions — surfaced via /dashboard/client-record now
      // that the backend exposes those three keys).
      var topStats = '<div class="hdlv2-s3-stats">'
        +   '<div class="hdlv2-st-stat"><strong>' + esc(s3.rate    != null ? s3.rate    + '×' : '—') + '</strong><span>Rate of ageing</span></div>'
        +   '<div class="hdlv2-st-stat"><strong>' + esc(s3.bio_age != null ? s3.bio_age      : '—') + '</strong><span>Biological age</span></div>'
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

      function kvList(rows) {
        if (!rows.length) return '';
        return '<ul class="hdlv2-st-list">'
          + rows.map(function (r) { return '<li><span>' + esc(r[0]) + '</span><span>' + esc(r[1]) + '</span></li>'; }).join('')
          + '</ul>';
      }
      var bodyBlock = (bodyLeft.length || bodyRight.length)
        ? '<div class="hdlv2-fp-block-label">Body composition &amp; vitals</div>'
          + '<div class="hdlv2-s3-body">' + kvList(bodyLeft) + kvList(bodyRight) + '</div>'
        : '';

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
      function sevPill(n) {
        var v = Math.round(n);
        var cls = v >= 4 ? 'high' : (v === 3 ? 'mid' : 'low');
        return '<span class="hdlv2-score-pill ' + cls + '">' + v + '<span class="hdlv2-score-pill-max">/5</span></span>';
      }
      function scoreGroup(title, pairs) {
        var rows = [];
        if (s3.scores && typeof s3.scores === 'object') {
          pairs.forEach(function (pair) {
            var v = s3.scores[pair[0]];
            if (v == null) return;
            var n = parseFloat(v);
            if (isNaN(n)) return;
            rows.push('<div class="hdlv2-score-row"><span>' + esc(pair[1]) + '</span>' + sevPill(n) + '</div>');
          });
        }
        if (!rows.length) return '';
        return '<div class="hdlv2-score-group">'
          + '<h4>' + esc(title) + '</h4>'
          + rows.join('')
          + '</div>';
      }
      var scoreGroups = ''
        + scoreGroup('Activity & body', [
            ['physicalActivity',   'Physical activity'],
            ['sitToStand',         'Sit-to-stand'],
            ['breathHold',         'Breath hold'],
            ['balance',            'Balance'],
            ['skinElasticity',     'Skin elasticity'],
            ['bmiScore',           'BMI'],
            ['whrScore',           'WHR'],
            ['whtrScore',          'WHtR'],
          ])
        + scoreGroup('Sleep, stress & social', [
            ['sleepDuration',      'Sleep duration'],
            ['sleepQuality',       'Sleep quality'],
            ['stressLevels',       'Stress levels'],
            ['socialConnections',  'Social connections'],
            ['cognitiveActivity',  'Cognitive activity'],
          ])
        + scoreGroup('Diet & lifestyle', [
            ['dietQuality',        'Diet quality'],
            ['alcoholConsumption', 'Alcohol'],
            ['smokingStatus',      'Smoking'],
            ['sunlightExposure',   'Sunlight'],
            ['supplementIntake',   'Supplements'],
            ['dailyHydration',     'Hydration'],
          ])
        + scoreGroup('Vitals', [
            ['bloodPressureScore', 'Blood pressure'],
            ['heartRateScore',     'Heart rate'],
          ]);

      var scoresBlock = scoreGroups
        ? '<div class="hdlv2-fp-block-label">21-metric score breakdown &middot; grouped</div>'
          + '<div class="hdlv2-score-groups">' + scoreGroups + '</div>'
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

      target.innerHTML = '<div class="hdlv2-st-card">'
        + '<div class="hdlv2-st-meta">Stage 3 &middot; Full detail &middot; 22 measurements across 5 sections &middot; Completed ' + esc(formatDate(s3.completed_at)) + '</div>'
        + topStats
        + bodyBlock
        + scoresBlock
        + hbBlock
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
          + 'Final report hasn’t been generated yet. Open the Consultation tab and click <strong>Generate Final Report</strong> after reviewing the draft.'
          + '</div>';
        return;
      }
      var html = '<div class="hdlv2-st-card">'
        + '<div class="hdlv2-st-meta">Generated ' + esc(formatDate(f.generated_at)) + '</div>'
        + '<p style="margin:0 0 12px;font-size:13px;color:#444;line-height:1.55;">'
        + 'The final report has been generated and emailed to ' + esc(c.name || 'the client') + ' and to you. The PDF includes cover &amp; pace, trajectory &amp; body composition, health profile, score breakdown, why &amp; milestones, and your plan.'
        + '</p>';
      if (f.view_url) {
        html += '<a class="hdlv2-detail-deeplink" href="' + esc(f.view_url) + '" target="_blank" rel="noopener">View final report →</a>';
      }
      html += '</div>';
      target.innerHTML = html;
    });
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
    var mountEl = document.createElement('div');
    mountEl.id = 'hdlv2-flight-plan';
    mountEl.className = 'hdlv2-assessment-root';
    target.appendChild(mountEl);
    window.HDLV2_FlightPlan.mount(mountEl, {
      cfg: {
        api_base: CFG.api_base + '/flight-plan',
        nonce: CFG.nonce,
        client_id: c.user_id
      }
    });
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
    // v0.24.3 — the post-Final read-only fork is gone. Both states
    // (pre-Final and post-Final) deeplink to the editable consultation
    // page. Practitioner can re-enter post-Final to edit health data,
    // regenerate the Final report, and reset the Flight Plan to Week 1
    // (edit-as-reset rule).
    if (c.has_final_report) {
      target.innerHTML = '<div class="hdlv2-consult-card">'
        + '<p>Final report generated for ' + esc(c.name) + '. You can edit the consultation any time — saving will regenerate the Final report and reset the Flight Plan to Week 1.</p>'
        + '<a class="hdlv2-detail-deeplink" href="' + esc(url) + '">Edit consultation →</a>'
        + '</div>';
      return;
    }
    target.innerHTML = '<div class="hdlv2-consult-card">'
      + '<p>Record your consultation with ' + esc(c.name) + ' — audio recording, typed notes, AI-organised output. You\'ll also review the draft report and finalise recommendations here.</p>'
      + '<a class="hdlv2-detail-deeplink" href="' + esc(url) + '">Record consultation →</a>'
      + '</div>';
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
    // chevron render under the Assessment column. Keeping the action cell
    // minimal (just the chevron appended by injectExpandButton) — V1 icon
    // actions aren't wired for V2-only rows and would otherwise break.
    row.innerHTML = [
      '<td class="client-name">' + esc(c.name) + '</td>',
      '<td class="date-cell">' + esc(firstEntry) + '</td>',
      '<td class="date-cell">' + esc(lastEntry) + '</td>',
      '<td class="total-cell">' + esc(total) + '</td>',
      '<td class="status-badge-cell"><span class="hdlv2-inline-badge" style="' + badgeStyle + '">V2 · ' + esc(c.label) + '</span></td>',
      '<td class="assessment-cell">' + renderV2Assessment(c) + '</td>',
      '<td class="action-cell">' + renderV2DeleteButton(c) + '</td>',
    ].join('');
    injectExpandButton(row, c);
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
        if (window.HDLV2UI && window.HDLV2UI.toast) window.HDLV2UI.toast('Removed ' + name, 'success');
      })
      .catch(function (err) {
        btn.disabled = false;
        btn.classList.remove('is-deleting', 'is-shaking');
        if (window.HDLV2UI && window.HDLV2UI.toast) window.HDLV2UI.toast(err.message || 'Could not remove client', 'error');
      });
  }

  // v0.41.14 — animated trash button rendered on V2-only rows. SVG paths copied
  // verbatim from itshover.com/icons/trash-icon (Tabler-style stroke set). Class
  // hooks (.trash-lid-lower / .trash-lid-upper) are targeted by the hover-state
  // CSS in injectStyles() to rotate the lid open without any JS during idle.
  // Click flow → confirm modal → AJAX → row fade is in handleDeleteClient above.
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
      + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      +   '<path stroke="none" d="M0 0h24v24H0z" fill="none"/>'
      +   '<path class="trash-lid-lower" d="M4 7l16 0"/>'
      +   '<path d="M10 11l0 6"/>'
      +   '<path d="M14 11l0 6"/>'
      +   '<path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12"/>'
      +   '<path class="trash-lid-upper" d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3"/>'
      + '</svg>'
      + '</button>';
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
      // v0.41.14 — animated trash icon ported from itshover.com/icons/trash-icon.
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
      '.hdlv2-delete-client-btn svg { display:block; width:16px; height:16px; transition: stroke 0.2s ease-in-out; pointer-events:none; }',
      '.hdlv2-delete-client-btn svg .trash-lid-lower, .hdlv2-delete-client-btn svg .trash-lid-upper { transform-origin: 50% 100%; transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1); }',
      '.hdlv2-delete-client-btn:hover svg .trash-lid-lower, .hdlv2-delete-client-btn.is-deleting svg .trash-lid-lower { transform: translateY(-2px) rotate(-25deg); }',
      '.hdlv2-delete-client-btn:hover svg .trash-lid-upper, .hdlv2-delete-client-btn.is-deleting svg .trash-lid-upper { transform: translate(-1.5px, -3px) rotate(-35deg); }',
      '@keyframes hdlv2-trash-shake { 0%{transform:translateX(0);} 25%{transform:translateX(-2px);} 50%{transform:translateX(2px);} 75%{transform:translateX(-1px);} 100%{transform:translateX(0);} }',
      '.hdlv2-delete-client-btn.is-shaking svg { animation: hdlv2-trash-shake 0.25s ease-in-out; }',
      '.hdlv2-v2-only-row.is-removing { transition: opacity 0.3s ease-out, transform 0.3s ease-out; opacity:0; transform: translateX(8px); }',
      '.hdlv2-expand-btn svg { transition: transform 0.2s ease; display:block; }',
      '.hdlv2-expand-btn.open svg { transform: rotate(180deg); }',
      '.hdlv2-detail-row > td { padding: 0 !important; background: #fafbfc; border-bottom: 1px solid #e4e6ea !important; }',
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
      '.hdlv2-detail-deeplink { display:inline-block; margin-top:14px; padding:8px 18px; border:1px solid #3d8da0; border-radius:24px; color:#3d8da0; text-decoration:none; font: 500 13px/1 Inter, -apple-system, sans-serif; transition: all 0.15s; }',
      '.hdlv2-detail-deeplink:hover { background:#3d8da0; color:#fff; }',
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
      '.hdlv2-consult-card p { margin: 0 0 12px; font-size: 13px; color: #444; line-height: 1.6; }',
      '.hdlv2-tab-new { display:inline-block; background:#e3eef1; color:#2d7082; font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; padding:2px 6px; border-radius:4px; margin-left:6px; vertical-align:middle; }',
      // ── v0.24.0 — Stage 1 / Stage 2 / Stage 3 / Final tab cards ──
      '.hdlv2-st-card { display:block; }',
      '.hdlv2-st-meta { font-size: 10px; color: #888; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 500; }',
      '.hdlv2-st-row { display:flex; gap:10px; margin-bottom: 14px; flex-wrap: wrap; }',
      '.hdlv2-st-stat { flex:1; min-width:120px; padding:10px 14px; background:#fff; border:1px solid #e4e6ea; border-radius:10px; text-align:center; }',
      '.hdlv2-st-stat strong { display:block; font-family: Poppins, Inter, sans-serif; font-size:18px; font-weight:700; color:#004F59; margin-bottom:2px; line-height:1.1; }',
      '.hdlv2-st-stat span { display:block; font-size:10px; color:#888; text-transform:uppercase; letter-spacing:0.05em; }',
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
      '.hdlv2-detail-journey { display:inline-flex; align-items:center; gap:0; background:#fff; border:1px solid #e4e6ea; border-radius:999px; padding:4px 6px; max-width:100%; flex-wrap:wrap; }',
      '.hdlv2-detail-journey-item { display:inline-flex; align-items:center; gap:5px; padding:4px 9px; font-size:11px; font-weight:600; color:#666; letter-spacing:0.02em; border-radius:999px; font-family: Inter, -apple-system, sans-serif; white-space:nowrap; }',
      '.hdlv2-detail-journey-item.state-done { color:#004F59; }',
      '.hdlv2-detail-journey-item.state-active { background:#fffbeb; color:#92400e; }',
      '.hdlv2-detail-journey-item.state-pending { color:#94a3b8; }',
      '.hdlv2-detail-journey-mark { width:14px; height:14px; border-radius:50%; background:#e4e6ea; color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:9px; flex:0 0 14px; line-height:1; }',
      '.hdlv2-detail-journey-item.state-done .hdlv2-detail-journey-mark { background:#3d8da0; }',
      '.hdlv2-detail-journey-item.state-active .hdlv2-detail-journey-mark { background:#d97706; }',
      '.hdlv2-detail-journey-sep { width:8px; height:1px; background:#e4e6ea; flex:0 0 8px; }',

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
      '.hdlv2-detail-tab.current .hdlv2-tab-dot { background:#3d8da0; }',
      '.hdlv2-tab-dot { display:inline-block; width:6px; height:6px; border-radius:50%; background:#e4e6ea; flex:0 0 6px; }',
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
      '.hdlv2-metric-tile.lo .hdlv2-metric-tile-value { color:#92400e; }',
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
      '.hdlv2-why-grid { display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; }',
      '@media (max-width: 820px) { .hdlv2-why-grid { grid-template-columns:1fr; } }',
      '.hdlv2-why-card { background:#fff; border:1px solid #e4e6ea; border-radius:10px; padding:14px 16px; }',
      '.hdlv2-why-card h4 { font-family: Poppins, Inter, sans-serif; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#666; margin:0 0 8px; display:flex; align-items:center; gap:6px; }',
      '.hdlv2-why-card h4 .ico { width:14px; height:14px; color:#3d8da0; flex:0 0 14px; }',
      '.hdlv2-why-card ul { list-style:none !important; margin:0 !important; padding:0 !important; }',
      '.hdlv2-why-card li { list-style:none !important; padding:6px 0 6px 14px; position:relative; font-size:13px; color:#2c3e50; line-height:1.5; }',
      '.hdlv2-why-card li::marker { content:none !important; }',
      '.hdlv2-why-card li::before { content:""; position:absolute; left:0; top:13px; width:5px; height:5px; border-radius:50%; background:#3d8da0; }',
      '.hdlv2-why-card-empty { font-size:12px; color:#888; font-style:italic; padding:4px 0; }',
      '.hdlv2-vision-card { margin-top:14px; padding:14px 18px; background:#fafbfc; border:1px solid #e4e6ea; border-left:3px solid #3d8da0; border-radius:0 10px 10px 0; font-size:13px; color:#2c3e50; line-height:1.55; }',
      '.hdlv2-vision-card-label { font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#666; margin-bottom:6px; }',

      // ─────────────────────────────────────────────────────────────
      // v0.41.19 — Stage 3 — top stats row, body 2-col, grouped scores,
      // Section 6 Health Background block (v0.38.0).
      // ─────────────────────────────────────────────────────────────
      '.hdlv2-s3-stats { display:grid; grid-template-columns:repeat(3, 1fr); gap:8px; margin-bottom:16px; }',
      '.hdlv2-s3-body { display:grid; grid-template-columns:repeat(2, 1fr); gap:8px; margin-bottom:18px; }',
      '@media (max-width: 700px) { .hdlv2-s3-stats { grid-template-columns:1fr; } .hdlv2-s3-body { grid-template-columns:1fr; } }',
      '.hdlv2-score-groups { display:grid; grid-template-columns:repeat(2, 1fr); gap:12px; }',
      '@media (max-width: 820px) { .hdlv2-score-groups { grid-template-columns:1fr; } }',
      '.hdlv2-score-group { background:#fff; border:1px solid #e4e6ea; border-radius:10px; padding:12px 14px; }',
      '.hdlv2-score-group h4 { font-family: Poppins, Inter, sans-serif; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#666; margin:0 0 8px; padding-bottom:6px; border-bottom:1px solid #f0f0f0; }',
      '.hdlv2-score-row { display:flex; justify-content:space-between; align-items:center; padding:4px 0; font-size:12px; color:#333; }',
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
