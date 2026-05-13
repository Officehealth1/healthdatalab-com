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
      var activeTab = panel.querySelector('.hdlv2-detail-tab.active');
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
    fetch(CFG.api_base + '/widget/leads/' + action, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.rest_nonce || CFG.nonce },
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
      if (!existing || existing.textContent !== newLabel) {
        if (existing) existing.remove();
        injectV2Badge(row, c);
        var fresh = cell.querySelector('.hdlv2-inline-badge');
        if (fresh) {
          fresh.classList.add('hdlv2-badge-flash');
          setTimeout(function () { fresh.classList.remove('hdlv2-badge-flash'); }, 1200);
        }
      }
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

    panel.innerHTML = [
      '<div class="hdlv2-detail-head">',
      '<strong>' + esc(c.name) + '</strong>',
      '<span class="hdlv2-detail-email">' + esc(c.email || '') + '</span>',
      '</div>',
      actionBanner,
      // v0.24.0 — tab strip reordered to match client journey (Matthew E1):
      // Progress (default landing — current state) → Stage 1 → Stage 2 →
      // Stage 3 → Consultation → Final → Flight Plan. Check-ins + Timeline
      // dropped as standalone tabs; their data is surfaced inside Progress
      // and Flight Plan.
      '<nav class="hdlv2-detail-tabs" role="tablist">',
      '<button type="button" class="hdlv2-detail-tab active" data-tab="progress">Progress</button>',
      '<button type="button" class="hdlv2-detail-tab" data-tab="stage1">Stage 1</button>',
      '<button type="button" class="hdlv2-detail-tab" data-tab="stage2">Stage 2</button>',
      '<button type="button" class="hdlv2-detail-tab" data-tab="stage3">Stage 3</button>',
      '<button type="button" class="hdlv2-detail-tab" data-tab="consultation">Consultation</button>',
      '<button type="button" class="hdlv2-detail-tab" data-tab="final">Final</button>',
      '<button type="button" class="hdlv2-detail-tab" data-tab="flight-plan">Flight Plan</button>',
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

    var releaseBtn = panel.querySelector('.hdlv2-release-why-btn');
    if (releaseBtn) {
      releaseBtn.addEventListener('click', function () { releaseWhy(c, releaseBtn, panel); });
    }

    // v0.24.0 — default landing tab is Progress (was Flight Plan).
    loadTab('progress', c, content);
    return panel;
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
      var html = '<div class="hdlv2-st-card">'
        + '<div class="hdlv2-st-meta">Completed ' + esc(formatDate(s1.completed_at)) + '</div>'
        + '<div class="hdlv2-st-row">'
        +   '<div class="hdlv2-st-stat"><strong>' + esc(s1.rate != null ? s1.rate + '×' : '—') + '</strong><span>Rate of ageing</span></div>'
        +   '<div class="hdlv2-st-stat"><strong>' + esc(s1.age != null ? s1.age : '—') + '</strong><span>Age</span></div>'
        +   '<div class="hdlv2-st-stat"><strong>' + esc(capSex) + '</strong><span>Sex</span></div>'
        + '</div>';
      if (s1.gauge_url) {
        html += '<div class="hdlv2-st-gauge"><img src="' + esc(s1.gauge_url) + '" alt="Pace of ageing gauge"></div>';
      }
      var rows = [];
      if (s1.q2_silhouette) rows.push(['Body shape', 'silhouette ' + s1.q2_silhouette + '/5 · ' + (s1.q2_fat_dist || 'even')]);
      if (s1.q3_zone2)      rows.push(['Zone-2 cardio (Q3)', s1.q3_zone2]);
      if (s1.q4_vo2)        rows.push(['VO2 / cardio reserve (Q4)', s1.q4_vo2]);
      if (s1.q5_sts)        rows.push(['Sit-to-stand (Q5)', s1.q5_sts]);
      if (s1.q6_sleep)      rows.push(['Sleep (Q6)', s1.q6_sleep]);
      if (s1.q7_smoking)    rows.push(['Smoking (Q7)', s1.q7_smoking]);
      if (s1.q8_social)     rows.push(['Social connection (Q8)', s1.q8_social]);
      if (s1.q9_diet)       rows.push(['Diet (Q9)', s1.q9_diet]);
      if (rows.length) {
        html += '<div class="hdlv2-fp-block-label">9-question answers</div>'
          + '<ul class="hdlv2-st-list">'
          + rows.map(function (r) { return '<li><span>' + esc(r[0]) + '</span><span>' + esc(r[1]) + '</span></li>'; }).join('')
          + '</ul>';
      }
      html += '</div>';
      target.innerHTML = html;
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
      var releaseTag = s2.released
        ? ''
        : ' · <span style="color:#d97706;font-weight:600">awaiting WHY release</span>';
      var html = '<div class="hdlv2-st-card">'
        + '<div class="hdlv2-st-meta">Completed ' + esc(formatDate(s2.completed_at)) + releaseTag + '</div>';
      if (s2.distilled_why) {
        html += '<div class="hdlv2-fp-identity">“' + esc(s2.distilled_why) + '”</div>';
      } else {
        html += '<div class="hdlv2-detail-empty">No distilled WHY recorded.</div>';
      }
      var people = Array.isArray(s2.key_people) ? s2.key_people.filter(function (p) { return p; }) : [];
      if (people.length) {
        html += '<div class="hdlv2-fp-block-label">Key people</div>'
          + '<ul class="hdlv2-st-bullets">'
          + people.map(function (p) {
              var label = typeof p === 'string' ? p : (p && (p.name || p.label || p.relationship)) || '';
              return label ? '<li>' + esc(label) + '</li>' : '';
            }).join('')
          + '</ul>';
      }
      var motiv = Array.isArray(s2.motivations) ? s2.motivations.filter(function (m) { return m; }) : [];
      if (motiv.length) {
        html += '<div class="hdlv2-fp-block-label">Motivations</div>'
          + '<ul class="hdlv2-st-bullets">'
          + motiv.map(function (m) {
              var label = typeof m === 'string' ? m : (m && (m.text || m.label)) || '';
              return label ? '<li>' + esc(label) + '</li>' : '';
            }).join('')
          + '</ul>';
      }
      var fears = Array.isArray(s2.fears) ? s2.fears.filter(function (f) { return f; }) : [];
      if (fears.length) {
        html += '<div class="hdlv2-fp-block-label">Fears</div>'
          + '<ul class="hdlv2-st-bullets">'
          + fears.map(function (f) {
              var label = typeof f === 'string' ? f : (f && (f.text || f.label)) || '';
              return label ? '<li>' + esc(label) + '</li>' : '';
            }).join('')
          + '</ul>';
      }
      if (s2.vision_text) {
        html += '<div class="hdlv2-fp-block-label">Vision (free-text)</div>'
          + '<div class="hdlv2-st-quote">' + esc(s2.vision_text) + '</div>';
      }
      html += '</div>';
      target.innerHTML = html;
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
      var html = '<div class="hdlv2-st-card">'
        + '<div class="hdlv2-st-meta">Completed ' + esc(formatDate(s3.completed_at)) + '</div>'
        + '<div class="hdlv2-st-row">'
        +   '<div class="hdlv2-st-stat"><strong>' + esc(s3.rate != null ? s3.rate + '×' : '—') + '</strong><span>Rate of ageing</span></div>'
        +   '<div class="hdlv2-st-stat"><strong>' + esc(s3.bio_age != null ? s3.bio_age : '—') + '</strong><span>Biological age</span></div>'
        +   '<div class="hdlv2-st-stat"><strong>' + esc(s3.bmi != null ? s3.bmi : '—') + '</strong><span>BMI</span></div>'
        + '</div>';
      var bodyRows = [];
      if (s3.height != null)      bodyRows.push('<li>Height: ' + esc(s3.height) + ' cm</li>');
      if (s3.weight != null)      bodyRows.push('<li>Weight: ' + esc(s3.weight) + ' kg</li>');
      if (s3.waist  != null)      bodyRows.push('<li>Waist: ' + esc(s3.waist) + ' cm</li>');
      if (s3.hip    != null)      bodyRows.push('<li>Hip: ' + esc(s3.hip) + ' cm</li>');
      if (s3.whr    != null)      bodyRows.push('<li>Waist-to-Hip: ' + esc(s3.whr) + '</li>');
      if (s3.whtr   != null)      bodyRows.push('<li>Waist-to-Height: ' + esc(s3.whtr) + '</li>');
      if (s3.bp_systolic && s3.bp_diastolic) bodyRows.push('<li>Blood pressure: ' + esc(s3.bp_systolic) + '/' + esc(s3.bp_diastolic) + ' mmHg</li>');
      if (s3.resting_hr != null)  bodyRows.push('<li>Resting heart rate: ' + esc(s3.resting_hr) + ' bpm</li>');
      if (bodyRows.length) {
        html += '<div class="hdlv2-fp-block-label">Body composition &amp; vitals</div>'
          + '<ul class="hdlv2-st-bullets">' + bodyRows.join('') + '</ul>';
      }

      var SCORE_ORDER = [
        ['physicalActivity',    'Activity'],
        ['sitToStand',          'Sit-to-stand'],
        ['breathHold',          'Breath hold'],
        ['balance',             'Balance'],
        ['skinElasticity',      'Skin elasticity'],
        ['sleepDuration',       'Sleep duration'],
        ['sleepQuality',        'Sleep quality'],
        ['stressLevels',        'Stress'],
        ['socialConnections',   'Social'],
        ['dietQuality',         'Diet'],
        ['alcoholConsumption',  'Alcohol'],
        ['smokingStatus',       'Smoking'],
        ['cognitiveActivity',   'Cognitive'],
        ['sunlightExposure',    'Sunlight'],
        ['supplementIntake',    'Supplements'],
        ['dailyHydration',      'Hydration'],
        ['bmiScore',            'BMI'],
        ['whrScore',            'WHR'],
        ['whtrScore',           'WHtR'],
        ['bloodPressureScore',  'Blood pressure'],
        ['heartRateScore',      'Heart rate'],
      ];
      var scoreRows = [];
      if (s3.scores && typeof s3.scores === 'object') {
        SCORE_ORDER.forEach(function (pair) {
          var v = s3.scores[pair[0]];
          if (v == null) return;
          var n = parseFloat(v);
          if (isNaN(n)) return;
          scoreRows.push('<div class="hdlv2-st-score"><span>' + esc(pair[1]) + '</span><span class="hdlv2-st-score-pill">' + esc(Math.round(n)) + '</span></div>');
        });
      }
      if (scoreRows.length) {
        html += '<div class="hdlv2-fp-block-label">21-metric score breakdown</div>'
          + '<div class="hdlv2-st-scores">' + scoreRows.join('') + '</div>';
      }
      html += '</div>';
      target.innerHTML = html;
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
    fetch(CFG.api_base + '/dashboard/client/' + c.user_id + '/effort-outcomes', {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': CFG.nonce },
    })
      .then(function (r) { return r.ok ? r.json() : null; })
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
      })
      .catch(function () {
        target.innerHTML = '<div class="hdlv2-detail-empty">Couldn’t load progress data.</div>';
      });
  }

  // Early state — text card. A single dot in a chart misreads as a bug
  // (the practitioner can't see if effort is translating to outcomes when
  // there's only 1 week and 1 baseline). The card states the facts plainly
  // and tells them when the chart will appear.
  function renderProgressEarly(target, adherence, outcomes, baseline) {
    var firstAdh = adherence[0];
    var firstOut = outcomes[0];
    var weekLabel = firstAdh ? ('Week ' + firstAdh.week_number + ' (' + formatDate(firstAdh.week_start) + ')') : 'No week recorded yet';
    var adhText = firstAdh
      ? firstAdh.overall + '% overall &nbsp;·&nbsp; movement ' + firstAdh.movement + '% &nbsp;·&nbsp; nutrition ' + firstAdh.nutrition + '% &nbsp;·&nbsp; key actions ' + firstAdh.key_action + '%'
      : 'Client hasn’t ticked any boxes yet.';
    var rateText = firstOut
      ? firstOut.rate.toFixed(2) + ' &nbsp;<span class="hdlv2-progress-early-meta">(from ' + firstOut.report_type + ' report on ' + formatDate(firstOut.date) + ')</span>'
      : (baseline ? baseline.toFixed(2) : 'Not yet calculated');

    target.innerHTML = ''
      + '<div class="hdlv2-progress-eyebrow">Effort vs Outcomes</div>'
      + '<p class="hdlv2-progress-headline">'
      +   'Trend chart appears once adherence reaches Week&nbsp;2. Until then a chart with one data point would misread as broken &mdash; here are the facts plainly.'
      + '</p>'
      + '<div class="hdlv2-progress-early">'
      +   '<div class="hdlv2-progress-early-row">'
      +     '<span class="hdlv2-progress-early-label">' + esc(weekLabel) + '</span>'
      +     '<span class="hdlv2-progress-early-value">' + adhText + '</span>'
      +   '</div>'
      +   '<div class="hdlv2-progress-early-row">'
      +     '<span class="hdlv2-progress-early-label">Baseline rate of ageing</span>'
      +     '<span class="hdlv2-progress-early-value">' + rateText + '</span>'
      +   '</div>'
      +   '<div class="hdlv2-progress-early-row">'
      +     '<span class="hdlv2-progress-early-label">Next outcome update</span>'
      +     '<span class="hdlv2-progress-early-value">Quarterly reassessment (Week 12)</span>'
      +   '</div>'
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
    // v0.21.0 — 7 cells to match the V1 header (Client · First · Last ·
    // Total · Status · Assessment · Action). Previously 6 cells made the
    // chevron render under the Assessment column. Keeping the action cell
    // minimal (just the chevron appended by injectExpandButton) — V1 icon
    // actions aren't wired for V2-only rows and would otherwise break.
    row.innerHTML = [
      '<td class="client-name">' + esc(c.name) + '</td>',
      '<td class="date-cell">' + dash + '</td>',
      '<td class="date-cell">' + esc(formatDate(c.last_checkin_date) || dash) + '</td>',
      '<td class="total-cell">' + dash + '</td>',
      '<td class="status-badge-cell"><span class="hdlv2-inline-badge" style="' + badgeStyle + '">V2 · ' + esc(c.label) + '</span></td>',
      '<td class="assessment-cell">' + renderV2Assessment(c) + '</td>',
      '<td class="action-cell"></td>',
    ].join('');
    injectExpandButton(row, c);
    return row;
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
      '.hdlv2-st-list li > span:first-child { color:#666; }',
      '.hdlv2-st-list li > span:last-child { font-weight:600; color:#111; }',
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
