/**
 * HDL V2 Dashboard Extension — Lead Magnet Widget
 *
 * Injects widget config tabs INTO the existing V1 "Invite Client" popup:
 *   Tab 1: Client Invite (V1 original form — untouched)
 *   Tab 2: Widget Config — practitioner name, logo, CTA, webhook, email, theme
 *   Tab 3: Embed Code — copy-paste code + live preview
 *   Tab 4: Widget Invites — create direct assessment links + track status
 *
 * If the V1 popup doesn't exist (non-practitioner view), creates a standalone
 * modal with the same tabs as fallback.
 *
 * Zero modification to V1 files. DOM-injected.
 *
 * @package HDL_Longevity_V2
 * @since 0.6.0
 */
(function () {
  'use strict';

  var CFG = window.hdlv2_dashboard || {};
  if (!CFG.nonce || !CFG.practitioner_id) return;

  // v0.29.1 — Empty-cta_link warning banner. With the v0.29.0 widget split
  // making "Book a Session" the only post-result CTA on the public path, an
  // empty cta_link silently produces a result page with NO call-to-action.
  // Surface this loudly the moment the practitioner lands on the dashboard.
  function maybeShowMissingCtaBanner() {
    var c = CFG.config || {};
    var ctaLink = (c.cta_link || '').trim();
    if (ctaLink && ctaLink !== '#') return;
    if (document.getElementById('hdlv2-cta-missing-banner')) return;
    // Anchor priority: V2 practitioner-dashboard mount → V1 client tools
    // button row → main H1. Last resort: prepend to <main> / <body>.
    var anchor = document.getElementById('hdlv2-practitioner-dashboard')
              || document.querySelector('#invite-client-btn, #invite-client-btn-empty')
              || document.querySelector('main h1, .entry-content h1, h1');
    if (!anchor) return;
    var banner = document.createElement('div');
    banner.id = 'hdlv2-cta-missing-banner';
    banner.style.cssText = 'background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:10px;padding:12px 16px;margin:16px auto;font-size:13px;line-height:1.5;display:flex;align-items:center;gap:10px;flex-wrap:wrap;max-width:1080px;';
    banner.innerHTML =
      '<span style="font-size:18px;line-height:1;">&#9888;</span>'
      + '<span style="flex:1;min-width:200px;"><strong>Your booking link is not set.</strong> Visitors who complete the widget will see no Book-a-Session button on their result page.</span>'
      + '<a href="#" id="hdlv2-cta-missing-banner-cta" style="background:#3d8da0;color:#fff;text-decoration:none;padding:8px 14px;border-radius:6px;font-weight:600;font-family:inherit;white-space:nowrap;">Open Widget Settings &rarr;</a>';
    // Insert above the anchor's CONTAINING BLOCK, not as a flex-row sibling
    // (the dashboard's H1 + "Client Tools" button often sit in a flex row
    // and inserting between them just squeezes the H1 to wrap).
    // Walk up to the first ancestor that's the direct child of <main>,
    // <body>, or <article> and insert before it.
    var insertionTarget = anchor;
    var hops = 0;
    while (insertionTarget.parentNode && hops < 6) {
      var p = insertionTarget.parentNode;
      if (!p.parentNode) break;
      if (p.tagName === 'MAIN' || p.tagName === 'BODY' || p.tagName === 'ARTICLE') break;
      insertionTarget = p;
      hops++;
    }
    if (insertionTarget.parentNode) {
      insertionTarget.parentNode.insertBefore(banner, insertionTarget);
    }
    var cta = document.getElementById('hdlv2-cta-missing-banner-cta');
    if (cta) cta.addEventListener('click', function (e) {
      e.preventDefault();

      // v0.36.6 — Dead-button fix. The banner is enqueued globally for any
      // logged-in practitioner (per maybe_enqueue_dashboard_js) so it
      // appears on EVERY page they visit — including the homepage where
      // the V1 invite-client popup + trigger button do NOT exist. Without
      // this branch, the click handler hit neither overlay nor fallback
      // and silently no-op'd, leaving a dead button.
      //
      // New behaviour: detect whether the V1 popup/button is on the
      // current page; if not, redirect to /clients/ with a deeplink hash
      // that the existing maybeOpenFromHash watcher converts into the
      // correct open-popup-and-switch-to-widget-config-tab flow. Mirrors
      // the v0.35.1 #hdlv2-pending-leads pattern.
      var ov = document.getElementById('invite-client-popup-overlay');
      var fallbackBtn = document.querySelector('#invite-client-btn, #invite-client-btn-empty');

      if (!ov && !fallbackBtn) {
        var clientsUrl = (window.hdlv2_dashboard && hdlv2_dashboard.clients_url) || '/clients/';
        // Strip any existing hash before appending — prevents #hash#hash
        // pile-up if the user previously navigated via another deeplink.
        clientsUrl = clientsUrl.replace(/#.*$/, '');
        window.location.href = clientsUrl + '#hdlv2-widget-config';
        return;
      }

      // Re-use the existing invite-popup open logic + force the Widget
      // Settings tab. The injectIntoPopup poll may not have fired yet on
      // very fast page loads, so wait until the V2 tab bar exists before
      // switching tabs (otherwise renderTabs would no-op and leave the
      // popup on its default Send-Invites tab).
      if (ov) {
        if (ov.parentNode !== document.body) document.body.appendChild(ov);
        ov.style.display = 'flex';
      } else {
        fallbackBtn.click();
      }
      var attempts = 0;
      (function waitForTabs() {
        if (document.getElementById('hdlv2-popup-tabs')) {
          renderTabs('widget-config');
          var ctaInput = document.getElementById('hdlv2-cta_link');
          if (ctaInput) setTimeout(function () { ctaInput.focus(); ctaInput.scrollIntoView({block:'center'}); }, 150);
          return;
        }
        if (++attempts < 40) setTimeout(waitForTabs, 100);
      })();
    });
  }
  // Render once DOM ready; the practitioner-dashboard React-style mount may
  // not have happened yet at IIFE-time, so retry briefly. Also waits for any
  // of the candidate anchors (V2 mount, V1 invite button, page H1).
  (function tryBanner(tries) {
    tries = tries || 0;
    var anchorReady = document.getElementById('hdlv2-practitioner-dashboard')
                   || document.querySelector('#invite-client-btn, #invite-client-btn-empty')
                   || document.querySelector('main h1, .entry-content h1, h1');
    if (anchorReady) {
      maybeShowMissingCtaBanner();
      return;
    }
    if (tries < 40) setTimeout(function () { tryBanner(tries + 1); }, 200);
  })();

  // v0.35.1 (Quim 2026-05-07 — fixed deadlink) — auto-open Client Tools
  // on the Pending Leads tab when the practitioner arrives via
  // #hdlv2-pending-leads (the deeplink in the New Lead notify email's
  // "Review in your dashboard" CTA).
  //
  // Why v0.35.0 didn't work: it tried to set the V1 overlay's
  // display:flex directly. That works for a freshly-rendered page where
  // the overlay element is already at <body>, but in this codebase the
  // overlay is nested inside Divi/UM page chrome and V1's own click
  // handler does the hoisting + class toggling that actually makes the
  // modal render. Setting display:flex on a still-nested element shows
  // it underneath everything (including the lightbox dimmer), so it's
  // invisible. Switching to a programmatic click on the V1 trigger
  // button delegates that work to V1's own logic, which we know works
  // because Quim has been opening the modal manually all session.
  //
  // Robustness layers:
  //  • Polls for the V1 trigger button up to ~6 seconds (Divi sometimes
  //    server-renders the dashboard wrapper but defers the button via
  //    JS, so it appears late).
  //  • Polls for the V2 tab bar after click — injectIntoPopup runs on a
  //    250ms watcher and won't have rendered yet at click time.
  //  • Listens to hashchange too, so navigating to the same /clients/
  //    page and only updating the hash (anchor link from a side nav)
  //    also works.
  function openClientToolsToPendingLeads() {
    var triggerAttempts = 0;
    (function tryClickTrigger() {
      var triggerBtn = document.querySelector('#invite-client-btn, #invite-client-btn-empty, [data-action="open-client-tools"]');
      if (triggerBtn) {
        triggerBtn.click();
        // Once the modal is open, poll for the V2 tab bar (injected by
        // injectIntoPopup's 250ms watcher) and switch to Pending Leads.
        var tabAttempts = 0;
        (function waitForTabs() {
          if (document.getElementById('hdlv2-popup-tabs')) {
            renderTabs('widget-pending');
            return;
          }
          if (++tabAttempts < 80) setTimeout(waitForTabs, 100);
        })();
        return;
      }
      // Trigger button not yet rendered. Some themes (Divi w/ deferred
      // dashboard.php) render the dashboard wrapper first and bind the
      // invite button later. Cap at ~6s — past that, the dashboard
      // probably isn't going to render at all on this page.
      if (++triggerAttempts < 60) setTimeout(tryClickTrigger, 100);
    })();
  }

  // v0.36.6 — Mirror of openClientToolsToPendingLeads for the Widget
  // Settings tab. Used by the booking-link banner CTA when redirecting
  // from a non-/clients/ page (e.g. homepage). Same trigger-poll +
  // tab-poll pattern; difference is the destination tab + the focus on
  // the cta_link input so the practitioner lands directly on the field
  // they need to fill in.
  function openClientToolsToWidgetConfig() {
    var triggerAttempts = 0;
    (function tryClickTrigger() {
      var triggerBtn = document.querySelector('#invite-client-btn, #invite-client-btn-empty, [data-action="open-client-tools"]');
      if (triggerBtn) {
        triggerBtn.click();
        var tabAttempts = 0;
        (function waitForTabs() {
          if (document.getElementById('hdlv2-popup-tabs')) {
            renderTabs('widget-config');
            // Focus + scroll the booking-link input so the practitioner
            // lands on the exact field the banner was complaining about.
            // Defer to next tick — the tab content renders async.
            var ctaInput = document.getElementById('hdlv2-cta_link');
            if (ctaInput) setTimeout(function () { ctaInput.focus(); ctaInput.scrollIntoView({ block: 'center' }); }, 150);
            return;
          }
          if (++tabAttempts < 80) setTimeout(waitForTabs, 100);
        })();
        return;
      }
      if (++triggerAttempts < 60) setTimeout(tryClickTrigger, 100);
    })();
  }

  function maybeOpenFromHash() {
    // v0.36.6 — second deeplink hash for the booking-link banner CTA.
    // Banner is global (every practitioner-visible page) but the V1
    // popup it depends on only exists on /clients/. The CTA redirects
    // here with this hash, the watcher converts hash → modal-open +
    // tab-switch. Same pattern as #hdlv2-pending-leads from v0.35.1.
    if (window.location.hash === '#hdlv2-pending-leads') {
      openClientToolsToPendingLeads();
      return;
    }
    if (window.location.hash === '#hdlv2-widget-config') {
      openClientToolsToWidgetConfig();
      return;
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', maybeOpenFromHash);
  } else {
    // Defer slightly so the DOM mutations from V1's own load wiring settle.
    setTimeout(maybeOpenFromHash, 80);
  }
  // Hashchange covers the same-page anchor navigation: practitioner
  // already on /clients/, clicks an in-page link to #hdlv2-pending-leads.
  // Without this, only fresh page loads would trigger the modal.
  window.addEventListener('hashchange', maybeOpenFromHash);

  var S = {
    teal: '#3d8da0',
    green: '#27ae60',
    red: '#e74c3c',
    amber: '#f59e0b',
    grey: '#999',
    btnBase: 'padding:8px 16px;color:#fff;border:none;border-radius:6px;font-size:13px;cursor:pointer;font-weight:500;',
    inputBase: 'width:100%;max-width:400px;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;box-sizing:border-box;'
  };

  // Tab structure (v0.35.1 — Phase O revised, per Quim 2026-05-07):
  //
  //   Send Invites | Sent Invites | Widget Settings | Pending Leads | Embed Code
  //
  // Pending Leads sits next to Widget Settings (per Quim's UX feedback —
  // makes it part of the "managing the widget" cluster rather than the
  // first-thing-you-see surface). The primary surface for new leads is
  // now the "What needs you today" action queue on the /clients/ page;
  // this tab is the deep-management view (full list, multi-action).
  //
  // Default landing tab is Send Invites again (the Phase 18A default).
  // Hash deeplink #hdlv2-pending-leads still activates the Pending Leads
  // tab so the "Review in your dashboard" email link works unchanged.
  //
  // V1's #invite-client-form is hidden in injectIntoPopup but its DOM remains
  // so V1's success/send-form popup handlers keep working on existing rows.
  var TABS = [
    { id: 'widget-send',    label: 'Send Invites' },
    { id: 'widget-sent',    label: 'Sent Invites' },
    { id: 'widget-config',  label: 'Widget Settings' },
    { id: 'widget-pending', label: 'Pending Leads' },
    { id: 'widget-embed',   label: 'Embed Code' }
  ];
  var TAB_IDS = TABS.map(function (t) { return t.id; });
  var injected = false;

  // Poll for the V1 popup element (rendered when dashboard loads)
  var tries = 0;
  var interval = setInterval(function () {
    if (injected || tries++ > 80) { clearInterval(interval); return; }

    var popup = document.getElementById('invite-client-popup');
    if (popup) {
      clearInterval(interval);
      injected = true;
      injectIntoPopup(popup);
    }
  }, 250);

  // ──────────────────────────────────────────────────────────────
  //  INJECT TABS INTO V1 INVITE POPUP
  // ──────────────────────────────────────────────────────────────

  function injectIntoPopup(popup) {
    // 0. Move overlays to body (V1 normally does this in init, but its JS has a syntax error)
    var overlay = document.getElementById('invite-client-popup-overlay');
    if (overlay && overlay.parentNode !== document.body) {
      document.body.appendChild(overlay);
    }
    var successOverlay = document.getElementById('invite-success-popup-overlay');
    if (successOverlay && successOverlay.parentNode !== document.body) {
      document.body.appendChild(successOverlay);
    }
    var sendFormOverlay = document.getElementById('send-form-popup-overlay');
    if (sendFormOverlay && sendFormOverlay.parentNode !== document.body) {
      document.body.appendChild(sendFormOverlay);
    }
    var msgOverlay = document.getElementById('message-client-popup-overlay');
    if (msgOverlay && msgOverlay.parentNode !== document.body) {
      document.body.appendChild(msgOverlay);
    }

    // 1. Find the V1 popup header and form
    var header = popup.querySelector('.invite-client-popup-header');
    var form = popup.querySelector('#invite-client-form');
    if (!header || !form) return;

    // 2. Update header title
    header.querySelector('h2').textContent = 'Client Tools';

    // 3. Hide the V1 invite form completely — it stays in the DOM so V1's
    // own success/send-form handlers can still fire on existing rows, but
    // no practitioner-visible UI surfaces it. V2 owns invites going forward.
    form.style.display = 'none';

    // 4. Create empty tab bar — content rendered by renderTabs() once the
    //    panels exist below.
    //
    // v0.35.3 (Quim 2026-05-07) — visible scrollbar on the tab bar was
    // distracting and unnecessary at the new 760px modal width (all 5
    // tabs fit comfortably). Hide the scrollbar visually via Firefox's
    // `scrollbar-width:none` + WebKit's `::-webkit-scrollbar { display:none }`
    // (injected once into <head> below). Horizontal scroll still works
    // for any future overflow case (zoom level / new tab added) — it
    // just doesn't paint a chrome bar that suggests the layout is broken.
    if (!document.getElementById('hdlv2-popup-tabs-style')) {
      var tabBarStyle = document.createElement('style');
      tabBarStyle.id = 'hdlv2-popup-tabs-style';
      tabBarStyle.textContent = '#hdlv2-popup-tabs::-webkit-scrollbar{display:none;height:0;width:0;}';
      document.head.appendChild(tabBarStyle);
    }
    var tabBar = document.createElement('div');
    tabBar.id = 'hdlv2-popup-tabs';
    tabBar.style.cssText = 'display:flex;flex-wrap:nowrap;gap:0;border-bottom:2px solid #eee;margin:0 0 16px;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none;-ms-overflow-style:none;';
    header.after(tabBar);

    // 5. Create all five panels — each stays in DOM, only one visible at a time.
    // v0.35.1 (Phase O revised) — DOM order matches the visual tab order:
    // Send → Sent → Widget Settings → Pending Leads → Embed Code.
    var sendPanel    = createPanel('widget-send',    sendTabContent());
    var sentPanel    = createPanel('widget-sent',    sentTabContent());
    var configPanel  = createPanel('widget-config',  configTabContent());
    var pendingPanel = createPanel('widget-pending', pendingTabContent());
    var embedPanel   = createPanel('widget-embed',   embedTabContent());

    form.after(sendPanel);
    sendPanel.after(sentPanel);
    sentPanel.after(configPanel);
    configPanel.after(pendingPanel);
    pendingPanel.after(embedPanel);

    // 6. Widen the popup for config form.
    // v0.35.1 (Quim 2026-05-07) — bumped 620px → 760px so all 5 tabs
    // (Send / Sent / Widget Settings / Pending Leads / Embed Code) fit
    // on one row at typical desktop widths. The Pending Leads list +
    // Embed Code panels also benefit from the extra horizontal room
    // (denser per-row info, less wrapping in instruction copy).
    popup.style.maxWidth = '760px';
    popup.style.width = '95vw';

    // 7. Render the tab bar. Default landing is Send Invites (Phase 18A
    // default — the primary action a practitioner takes here). The
    // "Review in your dashboard" notify-email CTA passes
    // #hdlv2-pending-leads in the URL hash, which forces the Pending Leads
    // tab active for that specific arrival path.
    var initialTab = (window.location.hash === '#hdlv2-pending-leads')
      ? 'widget-pending'
      : 'widget-send';
    renderTabs(initialTab);

    // 8. Bind V2 actions
    document.getElementById('hdlv2-save-config').addEventListener('click', saveConfig);
    document.getElementById('hdlv2-copy-embed').addEventListener('click', copyEmbed);
    document.getElementById('hdlv2-create-invite').addEventListener('click', createInvite);
    bindInviteLookup();

    // 9. Populate embed code on load
    if (CFG.embed_code) {
      document.getElementById('hdlv2-embed-code').value = CFG.embed_code;
    }

    // 10. Load initial preview + live-update on changes
    updatePreview();
    bindPreviewToggles();

    // Live-update: re-render preview on any config field change.
    //
    // v0.40.9 — theme_color bypasses the 300ms debounce. The native
    // <input type="color"> picker fires `input` events rapidly while the
    // user drags the colour wheel; with a 300ms debounce, a quick pick +
    // close lands AFTER the picker has dismissed, so the practitioner sees
    // no live response and the preview appears broken. Direct updatePreview
    // on every colour `input` is cheap — the widget re-mount is <10ms and
    // the colour wheel only fires while the picker is open.
    var liveFields = ['hdlv2-practitioner_name', 'hdlv2-cta_text', 'hdlv2-cta_link', 'hdlv2-theme_color', 'hdlv2-logo_url'];
    var liveTimer = null;
    liveFields.forEach(function (fid) {
      var el = document.getElementById(fid);
      if (!el) return;
      var isColor = ( fid === 'hdlv2-theme_color' );
      el.addEventListener('input', function () {
        if ( isColor ) {
          updatePreview();
          return;
        }
        if (liveTimer) clearTimeout(liveTimer);
        liveTimer = setTimeout(updatePreview, 300);
      });
      el.addEventListener('change', function () { updatePreview(); });
    });

    // Logo: dropzone click → file picker → AJAX upload
    var logoInput = document.getElementById('hdlv2-logo_url');
    var dropzone = document.getElementById('hdlv2-logo-dropzone');
    var logoLabel = document.getElementById('hdlv2-logo-label');

    function updateLogoPreview(url) {
      var prev = document.getElementById('hdlv2-logo-preview');
      if (!prev) return;
      if (url && (url.startsWith('http://') || url.startsWith('https://'))) {
        prev.innerHTML = '<img src="' + escAttr(url) + '" style="max-width:100%;max-height:100%;object-fit:contain;">';
        if (logoLabel) logoLabel.textContent = 'Click to change';
        if (dropzone) dropzone.style.borderColor = S.teal;
      } else {
        prev.innerHTML = '<span style="font-size:24px;color:#ccc;">+</span>';
        if (logoLabel) logoLabel.textContent = 'Click to upload your logo';
        if (dropzone) dropzone.style.borderColor = '#ddd';
      }
    }

    var fileInput = document.getElementById('hdlv2-logo-file');

    // Programmatic .click() — native label-for breaks inside the V1 popup overlay
    var _logoPicking = false;
    if (dropzone && fileInput) {
      dropzone.addEventListener('click', function () {
        if (_logoPicking) return;
        _logoPicking = true;
        fileInput.click();
        setTimeout(function () { _logoPicking = false; }, 1000);
      });
    }

    if (fileInput) fileInput.addEventListener('change', function () {
      var file = fileInput.files[0];
      if (!file) return;
      if (file.size > 5 * 1024 * 1024) { alert('Logo must be under 5MB.'); fileInput.value = ''; return; }

      if (logoLabel) logoLabel.textContent = 'Uploading...';
      if (dropzone) dropzone.style.pointerEvents = 'none';

      var fd = new FormData();
      fd.append('action', 'hdlv2_upload_logo');
      fd.append('nonce', CFG.nonce);
      fd.append('logo', file);

      fetch(CFG.ajax_url, { method: 'POST', body: fd })
        .then(function (r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.text();
        })
        .then(function (text) {
          var res;
          try { res = JSON.parse(text); } catch (e) { throw new Error('Not JSON: ' + text.substring(0, 100)); }
          return res;
        })
        .then(function (res) {
          if (dropzone) dropzone.style.pointerEvents = '';
          if (res.success && res.data && res.data.url) {
            if (logoInput) logoInput.value = res.data.url;
            updateLogoPreview(res.data.url);
            updatePreview();
          } else {
            var msg = (typeof res.data === 'string') ? res.data : 'Upload failed';
            alert(msg);
            if (logoLabel) logoLabel.textContent = 'Click to upload your logo';
          }
          fileInput.value = '';
        })
        .catch(function (err) {
          if (dropzone) dropzone.style.pointerEvents = '';
          if (logoLabel) logoLabel.textContent = 'Upload failed — try again';
          fileInput.value = '';
        });
    });

    // 11. Fallback: bind invite button click (V1 handler may not register due to JS syntax error)
    document.querySelectorAll('#invite-client-btn, #invite-client-btn-empty').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var ov = document.getElementById('invite-client-popup-overlay');
        if (ov) {
          if (ov.parentNode !== document.body) document.body.appendChild(ov);
          ov.style.display = 'flex';
          // Force Send tab on every open — primary action per Matthew's PDF.
          renderTabs('widget-send');
          // Clean slate: hide any stale link-created box from a previous session
          // and clear any countdown left running. The next createInvite re-opens it.
          var staleBox = document.getElementById('hdlv2-invite-link-box');
          if (staleBox) staleBox.style.display = 'none';
          stopLinkBoxCountdown();
          setTimeout(function () { var n = document.getElementById('invite-client-name'); if (n) n.focus(); }, 100);
        }
      });
    });

    // 12. Fallback: close button + cancel button
    document.querySelectorAll('#invite-client-popup-overlay .invite-client-popup-close, #invite-client-popup-overlay .invite-client-popup-btn-cancel').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var ov = document.getElementById('invite-client-popup-overlay');
        if (ov) ov.style.display = 'none';
        stopInvitesPolling();
        stopLinkBoxCountdown();
      });
    });

    // 13. Fallback: close on overlay background click
    if (overlay) {
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) { overlay.style.display = 'none'; stopInvitesPolling(); stopLinkBoxCountdown(); }
      });
    }

    // 14. Fallback: V1 invite form submit (AJAX instead of browser GET)
    var v1Form = document.getElementById('invite-client-form');
    if (v1Form) {
      v1Form.addEventListener('submit', function (e) {
        e.preventDefault();
        var nameVal = (document.getElementById('invite-client-name') || {}).value || '';
        var emailVal = (document.getElementById('invite-client-email') || {}).value || '';
        var notesVal = (document.getElementById('invite-client-notes') || {}).value || '';

        if (nameVal.trim().length < 2) { alert('Client name must be at least 2 characters.'); return; }
        if (!emailVal.trim() || emailVal.indexOf('@') === -1) { alert('Please enter a valid email address.'); return; }

        // Show loading
        var loadingEl = document.getElementById('invite-client-loading');
        if (loadingEl) loadingEl.style.display = 'flex';
        v1Form.querySelectorAll('input, textarea, button').forEach(function (el) { el.disabled = true; });

        var fd = new FormData();
        fd.append('action', 'health_tracker_create_client_invite');
        fd.append('nonce', CFG.v1_nonce);
        fd.append('client_name', nameVal.trim());
        fd.append('client_email', emailVal.trim());
        fd.append('notes', notesVal.trim());

        fetch(CFG.ajax_url, { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (loadingEl) loadingEl.style.display = 'none';
            v1Form.querySelectorAll('input, textarea, button').forEach(function (el) { el.disabled = false; });

            if (res.success && res.data && res.data.invite_link) {
              // Close invite popup
              var ov = document.getElementById('invite-client-popup-overlay');
              if (ov) ov.style.display = 'none';
              // Show success popup
              var successOv = document.getElementById('invite-success-popup-overlay');
              var linkInput = document.getElementById('invite-link-display');
              if (successOv) {
                if (successOv.parentNode !== document.body) document.body.appendChild(successOv);
                if (linkInput) linkInput.value = res.data.invite_link;
                successOv.style.display = 'flex';
                // Bind copy + close on success popup
                var copyBtn = document.getElementById('copy-invite-link-btn');
                if (copyBtn) copyBtn.onclick = function () { if (linkInput) { linkInput.select(); document.execCommand('copy'); copyBtn.textContent = 'Copied!'; setTimeout(function () { copyBtn.textContent = 'Copy Link'; }, 2000); } };
                var closeSuccessBtn = document.getElementById('close-success-popup-btn');
                if (closeSuccessBtn) closeSuccessBtn.onclick = function () { successOv.style.display = 'none'; location.reload(); };
                successOv.querySelectorAll('.invite-client-popup-close').forEach(function (b) { b.onclick = function () { successOv.style.display = 'none'; location.reload(); }; });
              } else {
                alert('Invite created! Link: ' + res.data.invite_link);
                location.reload();
              }
              // Clear form
              if (document.getElementById('invite-client-name')) document.getElementById('invite-client-name').value = '';
              if (document.getElementById('invite-client-email')) document.getElementById('invite-client-email').value = '';
              if (document.getElementById('invite-client-notes')) document.getElementById('invite-client-notes').value = '';
            } else {
              var errMsg = (res.data && res.data.message) ? res.data.message : 'Failed to create invite. Please try again.';
              alert(errMsg);
            }
          })
          .catch(function () {
            if (loadingEl) loadingEl.style.display = 'none';
            v1Form.querySelectorAll('input, textarea, button').forEach(function (el) { el.disabled = false; });
            alert('Network error. Please try again.');
          });
      });
    }
  }

  function popupTabBtn(id, label, active) {
    // v0.35.1 — slightly tighter padding + flex-shrink:0 so tabs never
    // wrap to a second line. white-space:nowrap protects multi-word
    // labels ("Send Invites", "Pending Leads") from breaking mid-label
    // when the modal narrows.
    var style = 'padding:9px 14px;font-size:13px;font-weight:' + (active ? '600' : '500')
      + ';cursor:pointer;border:none;background:none;flex-shrink:0;white-space:nowrap;'
      + 'border-bottom:2px solid ' + (active ? S.teal : 'transparent')
      + ';color:' + (active ? S.teal : '#666')
      + ';margin-bottom:-2px;transition:color .15s ease,border-bottom-color .15s ease;font-family:inherit;';
    return '<button data-hdlv2-ptab="' + id + '" style="' + style + '">' + label + '</button>';
  }

  function createPanel(id, html) {
    var div = document.createElement('div');
    div.id = 'hdlv2-popup-tab-' + id;
    div.style.display = 'none';
    div.innerHTML = html;
    return div;
  }

  // ──────────────────────────────────────────────────────────────
  //  TAB BAR — render all four tabs in one row
  // ──────────────────────────────────────────────────────────────

  // Render the tab bar. Pass `forceTabId` to set which tab activates;
  // defaults to widget-send (the primary action — Matthew's PDF intent).
  function renderTabs(forceTabId) {
    var tabBar = document.getElementById('hdlv2-popup-tabs');
    if (!tabBar) return;
    var activeId = forceTabId || 'widget-send';
    tabBar.innerHTML = TABS.map(function (t) {
      return popupTabBtn(t.id, t.label, t.id === activeId);
    }).join('');
    tabBar.querySelectorAll('[data-hdlv2-ptab]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        switchPopupTab(btn.getAttribute('data-hdlv2-ptab'));
      });
    });
    switchPopupTab(activeId);
  }

  function switchPopupTab(activeId) {
    // v0.40.8 — Widget Settings tab gets a wider modal (1100px vs 760px
    // default) so the 2-column preview/form layout has breathing room.
    // Other tabs stay at 760px. Idempotent — refetches popup each call.
    var popupEl = document.getElementById('invite-client-popup');
    if ( popupEl ) {
      popupEl.style.maxWidth = ( activeId === 'widget-config' ? '1100px' : '760px' );
    }
    TAB_IDS.forEach(function (id) {
      var panel = document.getElementById('hdlv2-popup-tab-' + id);
      if (panel) panel.style.display = (id === activeId ? 'block' : 'none');
      var btn = document.querySelector('[data-hdlv2-ptab="' + id + '"]');
      if (!btn) return;
      if (id === activeId) {
        btn.style.borderBottomColor = S.teal;
        btn.style.color = S.teal;
        btn.style.fontWeight = '600';
      } else {
        btn.style.borderBottomColor = 'transparent';
        btn.style.color = '#666';
        btn.style.fontWeight = '500';
      }
    });
    if (activeId === 'widget-sent') {
      loadInvites(false);
      startInvitesPolling();
    } else {
      stopInvitesPolling();
    }
    // v0.35.0 (Phase O) — Pending Leads tab triggers a fresh fetch on
    // every activation so the list reflects the latest server state.
    if (activeId === 'widget-pending') {
      loadPendingLeads();
    }
  }

  // ──────────────────────────────────────────────────────────────
  //  TAB CONTENT: WIDGET CONFIG
  // ──────────────────────────────────────────────────────────────

  function configTabContent() {
    var c = CFG.config || {};

    // v0.40.8 — Inject the Widget Settings 2-column layout styles once.
    // The mockup at mockups/widget-settings-redesign.html proved the shape
    // (preview sticky-left, form scroll-right, 1100px modal width). All
    // existing field IDs (hdlv2-widget-preview, hdlv2-logo-*, hdlv2-save-config,
    // hdlv2-cta_link etc.) are preserved so save / preview / logo upload
    // wiring works without change. Mobile fallback below 900px reverts to a
    // single-column stack — same content order, no breakpoint surprises.
    if ( ! document.getElementById('hdlv2-ws-redesign-style') ) {
      var s = document.createElement('style');
      s.id = 'hdlv2-ws-redesign-style';
      s.textContent =
        '.hdlv2-ws-grid{display:grid;grid-template-columns:1fr 1fr;gap:32px;align-items:start;padding:4px 0;}'
      + '.hdlv2-ws-preview{position:sticky;top:8px;}'
      + '.hdlv2-ws-preview__head{display:flex;align-items:center;justify-content:space-between;margin:0 0 10px;gap:8px;flex-wrap:wrap;}'
      + '.hdlv2-ws-preview__label{font-size:11px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:' + S.teal + ';}'
      + '.hdlv2-ws-preview__demo{display:inline-flex;align-items:center;gap:4px;margin-top:10px;font-size:12px;color:#666;text-decoration:none;}'
      + '.hdlv2-ws-preview__demo:hover{color:' + S.teal + ';}'
      // v0.40.10 — height bumped 520 → 720 so the .hdlw-footer (practitioner
      // logo + name) and the Result page (gauge + stat strip + book CTA +
      // footer) fit inside the preview without clipping. overflow:hidden
      // keeps the scrollbar away per the v0.40.8 requirement; the new
      // height is sized to the tallest of the three preview states (Result
      // ≈ 700px). Q1/Q5 use whitespace below — visually equivalent to how
      // the widget breathes inside a host page on a real embed.
      + '#hdlv2-widget-preview-frame{height:720px;overflow:hidden;background:linear-gradient(180deg,#faf8f5 0%,#fafbfc 100%);}'
      + '#hdlv2-widget-preview-frame .hdl-rate-widget,'
      + '#hdlv2-widget-preview-frame .hdlw,'
      + '#hdlv2-widget-preview-frame .hdlw-shell{overflow:hidden!important;}'
      + '.hdlv2-ws-preview__caption{font-size:11px;color:#888;line-height:1.5;margin:8px 0 0;font-style:italic;text-align:center;}'
      + '.hdlv2-ws-form{display:flex;flex-direction:column;}'
      + '.hdlv2-ws-section{font-family:Poppins,Inter,sans-serif;font-size:11px;font-weight:600;letter-spacing:0.1em;text-transform:uppercase;color:#004F59;margin:14px 0 12px;padding-bottom:6px;border-bottom:1px solid #eef0f3;}'
      + '.hdlv2-ws-section:first-child{margin-top:0;}'
      + '.hdlv2-ws-form__intro{font-size:13px;color:#666;line-height:1.5;margin:0 0 18px;}'
      + '.hdlv2-ws-url-err{font-size:11px;color:#dc2626;line-height:1.5;margin:6px 0 0;display:none;}'
      + '.hdlv2-ws-url-err.is-visible{display:block;}'
      + '#hdlv2-cta_link.is-invalid{border-color:#dc2626!important;background:#fef2f2;}'
      + '@media (max-width:900px){'
      +   '.hdlv2-ws-grid{grid-template-columns:1fr;}'
      +   '.hdlv2-ws-preview{position:static;}'
      +   '.hdlv2-ws-form{max-height:none;}'
      + '}';
      document.head.appendChild(s);
    }

    return '<div class="hdlv2-ws-grid">'
      // ── LEFT: Live Preview (sticky) ───────────────────────────────────
      + '<div class="hdlv2-ws-preview">'
      +   '<div class="hdlv2-ws-preview__head">'
      +     '<span class="hdlv2-ws-preview__label">Live Preview</span>'
      +     '<span id="hdlv2-preview-toggle" style="font-size:11px;color:#888;">'
      +       '<a href="#" data-step="0" style="color:' + S.teal + ';text-decoration:none;font-weight:600;margin:0 6px;">Q1</a>·'
      +       '<a href="#" data-step="4" style="color:#aaa;text-decoration:none;margin:0 6px;">Q5</a>·'
      +       '<a href="#" data-step="10" style="color:#aaa;text-decoration:none;margin:0 6px;">Result</a>'
      +     '</span>'
      +   '</div>'
      +   '<div id="hdlv2-widget-preview-frame" style="border:1px solid #e4e6ea;border-radius:14px;padding:12px;">'
      +     '<div id="hdlv2-widget-preview" style="max-width:420px;margin:0 auto;"></div>'
      +   '</div>'
      +   '<p class="hdlv2-ws-preview__caption">↑ This is what visitors see on your site, including the practitioner footer where your logo and name appear.</p>'
      +   '<a href="' + escAttr( (CFG.site_url || '') + '/rate-of-ageing-widget/' ) + '" target="_blank" rel="noopener" class="hdlv2-ws-preview__demo">Open real demo in new tab ↗</a>'
      + '</div>'
      // ── RIGHT: Settings form (scrolls independently) ──────────────────
      + '<div class="hdlv2-ws-form">'
      +   '<p class="hdlv2-ws-form__intro">Customise the widget on the left — the preview updates as you type.</p>'
      +   '<h4 class="hdlv2-ws-section">Branding</h4>'
      + formField('practitioner_name', 'Your Name', 'text', c.practitioner_name || '')
      + formField('clinic_name', 'Practice / Clinic Name <span style="color:#888;font-weight:400;">(optional — shown on consultation notes)</span>', 'text', c.clinic_name || '')
      // Logo upload area — uses <label> wrapping file input for reliable native click
      + '<div style="margin-bottom:10px;">'
      + '<span style="display:block;font-size:12px;color:#555;margin-bottom:3px;font-weight:500;">Your Logo <span style="color:#888;font-weight:400;">(JPG, PNG, GIF, WebP — up to 5MB)</span></span>'
      + '<div id="hdlv2-logo-dropzone" style="display:block;border:2px dashed #ddd;border-radius:10px;padding:16px;text-align:center;cursor:pointer;background:#fafbfc;transition:border-color 0.2s;position:relative;">'
      + '<div id="hdlv2-logo-preview" style="margin:0 auto 8px;width:60px;height:60px;border-radius:8px;background:#f0f1f3;display:flex;align-items:center;justify-content:center;overflow:hidden;">'
      + (c.logo_url ? '<img src="' + escAttr(c.logo_url) + '" style="max-width:100%;max-height:100%;object-fit:contain;">' : '<span style="font-size:24px;color:#ccc;">+</span>')
      + '</div>'
      + '<div id="hdlv2-logo-label" style="font-size:12px;color:#888;">' + (c.logo_url ? 'Click to change' : 'Click to upload your logo') + '</div>'
      + '<input type="file" id="hdlv2-logo-file" accept="image/jpeg,image/png,image/gif,image/webp" style="position:absolute;width:1px;height:1px;overflow:hidden;opacity:0;top:0;left:0;">'
      + '</div>'
      // v0.29.4 — guidance for best print rendering on the Final Report cover
      + '<p style="font-size:11px;color:#888;margin:6px 0 0;line-height:1.4;">Tip: horizontal logo, transparent PNG, at least 1200&thinsp;px wide for sharp print.</p>'
      + '<input id="hdlv2-logo_url" type="hidden" value="' + escAttr(c.logo_url || '') + '">'
      + '</div>'
      + formField('theme_color', 'Widget Colour', 'color', c.theme_color || '#3d8da0')
      + '<h4 class="hdlv2-ws-section">Call-to-Action</h4>'
      + formField('cta_text', 'Button Text', 'text', c.cta_text || 'Book a session')
      + formField('cta_link', 'Booking Link <span style="color:#e74c3c;">*</span>', 'url', c.cta_link || '', { required: true })
      + '<p class="hdlv2-ws-url-err" id="hdlv2-cta_link-err">⚠ Booking Link must start with <code>https://</code> or <code>http://</code></p>'
      + '<h4 class="hdlv2-ws-section">Notifications</h4>'
      + formField('notification_email', 'Send new leads to this email <span style="color:#e74c3c;">*</span>', 'email', c.notification_email || '', { required: true })
      // v0.35.0 (Phase O) — Book button toggle for the public-path result
      // page. OFF by default. Invite path always renders Book when a
      // booking link is set; this toggle only governs the public-embed
      // result page (where Matthew's "no button" rule applies unless the
      // practitioner explicitly opts in).
      + '<label style="display:flex;gap:10px;align-items:flex-start;font-size:13px;color:#444;margin:0 0 14px;cursor:pointer;line-height:1.5;">'
      +   '<input type="checkbox" id="hdlv2-show_book_button_after_widget"' + (c.show_book_button_after_widget ? ' checked' : '') + ' style="margin-top:2px;flex-shrink:0;">'
      +   '<span><strong style="color:#222;">Show "Book a Session" on the public widget result page</strong><br>'
      +     '<span style="font-size:12px;color:#777;">Off by default. When off, a public visitor sees a thank-you message and waits for your follow-up email after you confirm them. Turn on to render your booking CTA inline on the result page.</span>'
      +   '</span>'
      + '</label>'
      + '<details style="margin-bottom:12px;"><summary style="font-size:12px;color:#888;cursor:pointer;">Advanced</summary>'
      + '<div style="margin-top:8px;">'
      + formField('webhook_url', 'Webhook URL (sends lead data to external tools)', 'url', c.webhook_url || '')
      + '</div></details>'
      + '<button id="hdlv2-save-config" style="padding:10px 24px;background:' + S.teal + ';color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;margin-top:8px;font-family:inherit;">Save Settings</button>'
      + '<span id="hdlv2-save-status" style="margin-left:12px;font-size:13px;color:' + S.green + ';display:none;"></span>'
      + '</div>'   // close .hdlv2-ws-form
      + '</div>';  // close .hdlv2-ws-grid
  }

  // ──────────────────────────────────────────────────────────────
  //  TAB CONTENT: EMBED CODE
  // ──────────────────────────────────────────────────────────────

  function embedTabContent() {
    return '<div style="padding:4px 0;">'
      // What this does
      + '<p style="font-size:14px;font-weight:600;color:#333;margin:0 0 4px;">Your Widget Embed Code</p>'
      + '<p style="font-size:13px;color:#666;margin:0 0 10px;line-height:1.5;">This code adds a free health assessment to your website. Visitors answer 9 quick questions and get their rate of ageing \u2014 and you get their contact details as a new lead.</p>'
      // Code box + copy
      + '<textarea id="hdlv2-embed-code" readonly style="width:100%;height:80px;font-family:monospace;font-size:12px;padding:10px;border:1px solid #ddd;border-radius:8px;background:#f9f9f9;resize:none;box-sizing:border-box;"></textarea>'
      + '<button id="hdlv2-copy-embed" style="margin-top:8px;' + S.btnBase + 'background:' + S.green + ';font-family:inherit;">Copy to Clipboard</button>'
      // v0.40.12 — auto-propagate reassurance. Practitioners worry they need
      // to re-copy + re-paste this code every time they change a setting.
      // They don't — the widget fetches /widget/public-config on every load
      // and pulls latest colour, CTA, logo, etc. from the server. One paste
      // is forever (as long as the file is left in place on the host site).
      + '<div style="margin-top:12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 12px;">'
      + '<p style="font-size:12px;color:#1e40af;margin:0;line-height:1.5;"><strong>One paste, forever:</strong> Once this code is on your site, changes you make in <strong>Widget Settings</strong> (colour, booking link, logo, CTA text) update automatically on every embedded widget. You only need to paste this code once.</p>'
      + '</div>'
      // Step-by-step instructions
      + '<div style="margin-top:16px;border-top:1px solid #eee;padding-top:14px;">'
      + '<p style="font-size:13px;font-weight:600;color:#333;margin:0 0 10px;">How to add this to your website</p>'
      // Step 1
      + '<div style="margin-bottom:12px;">'
      + '<p style="font-size:12px;color:#333;margin:0 0 3px;font-weight:600;">Step 1: Copy the code</p>'
      + '<p style="font-size:12px;color:#666;margin:0;line-height:1.5;">Click the green <strong>Copy to Clipboard</strong> button above.</p>'
      + '</div>'
      // Step 2
      + '<div style="margin-bottom:12px;">'
      + '<p style="font-size:12px;color:#333;margin:0 0 3px;font-weight:600;">Step 2: Open your website editor</p>'
      + '<p style="font-size:12px;color:#666;margin:0;line-height:1.5;">Go to the page where you want the assessment to appear (e.g. your homepage, a landing page, or a dedicated \u201cFree Health Check\u201d page).</p>'
      + '</div>'
      // Step 3 — platform-specific
      + '<div style="margin-bottom:12px;">'
      + '<p style="font-size:12px;color:#333;margin:0 0 3px;font-weight:600;">Step 3: Paste the code</p>'
      + '<div style="background:#f8f9fb;border:1px solid #e8e8e8;border-radius:8px;padding:10px 12px;margin-top:6px;">'
      // WordPress
      + '<p style="font-size:12px;margin:0 0 8px;line-height:1.5;"><strong style="color:' + S.teal + ';">WordPress:</strong> Edit your page \u2192 click the <strong>+</strong> button \u2192 search for <strong>\u201cCustom HTML\u201d</strong> \u2192 drag it where you want the widget \u2192 paste the code into the HTML box.</p>'
      // Wix
      + '<p style="font-size:12px;margin:0 0 8px;line-height:1.5;"><strong style="color:' + S.teal + ';">Wix:</strong> Open the Wix Editor \u2192 click <strong>Add Elements (+)</strong> \u2192 choose <strong>\u201cEmbed Code\u201d \u2192 \u201cEmbed HTML\u201d</strong> \u2192 paste the code \u2192 click \u201cUpdate\u201d.</p>'
      // Squarespace
      + '<p style="font-size:12px;margin:0 0 8px;line-height:1.5;"><strong style="color:' + S.teal + ';">Squarespace:</strong> Edit your page \u2192 click <strong>Add Block (+)</strong> \u2192 choose <strong>\u201cCode\u201d</strong> \u2192 paste the code \u2192 make sure \u201cDisplay Source\u201d is OFF.</p>'
      // Shopify
      + '<p style="font-size:12px;margin:0 0 0;line-height:1.5;"><strong style="color:' + S.teal + ';">Shopify / Other:</strong> Find the option to add \u201cCustom HTML\u201d or \u201cEmbed Code\u201d in your page editor, then paste the code there.</p>'
      + '</div>'
      + '</div>'
      // Tips
      + '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;margin-bottom:10px;">'
      + '<p style="font-size:12px;color:#92400e;margin:0;line-height:1.5;"><strong>Tips:</strong> The widget works on any website. It automatically matches the colour you set in Widget Settings. You can preview how it looks in the <strong>Widget Settings</strong> tab before going live.</p>'
      + '</div>'
      // Need help
      + '<p style="font-size:11px;color:#888;margin:0;line-height:1.5;">Stuck? Email <a href="mailto:support@healthdatalab.com" style="color:' + S.teal + ';text-decoration:none;font-weight:500;">support@healthdatalab.com</a> and we\u2019ll help you set it up.<br>We also offer technical assistance at $100/hr (minimum 1 hour).</p>'
      + '</div>'
      + '</div>';
  }

  // ──────────────────────────────────────────────────────────────
  //  TAB CONTENT: WIDGET INVITES
  // ──────────────────────────────────────────────────────────────

  // Send tab — the form + the "link created" reveal box (kept here so the
  // practitioner can copy the freshly-generated link without tab-switching).
  function sendTabContent() {
    return '<div style="padding:4px 0;">'
      + '<div style="margin-bottom:20px;padding:16px;background:#f8f9fb;border-radius:8px;border:1px solid #eee;">'
      + '<h4 style="margin:0 0 10px;font-size:14px;font-weight:600;color:#1a1a1a;">Send Personal Assessment Link</h4>'
      + '<p style="font-size:12px;color:#888;margin:0 0 12px;">Create a link for a specific client. Their email will be pre-filled and locked so only they can use it.</p>'
      + formField('invite_name', 'Client Name', 'text', '')
      + formField('invite_email', 'Client Email', 'email', '')
      // v0.29.0 — pre-fill match indicator. Hidden until ajax_lead_lookup
      // returns; shows ✓ teal when a prior public-widget submission exists,
      // ○ muted grey when not. Reuses existing palette tokens — no new colours.
      + '<div id="hdlv2-invite-lookup-hint" style="display:none;margin:-6px 0 12px;font-size:12px;line-height:1.4;"></div>'
      + '<div style="margin-bottom:12px;">'
      + '<label style="display:block;font-size:13px;color:#555;margin-bottom:4px;font-weight:500;">Expires in</label>'
      + '<select id="hdlv2-invite_expires" style="' + S.inputBase + 'max-width:180px;">'
      + '<option value="30">30 minutes</option>'
      + '<option value="120">2 hours</option>'
      + '<option value="1440" selected>24 hours</option>'
      + '<option value="10080">7 days</option>'
      + '<option value="43200">30 days</option>'
      + '</select></div>'
      + '<button id="hdlv2-create-invite" style="' + S.btnBase + 'background:' + S.teal + ';padding:10px 20px;font-size:13px;font-weight:600;font-family:inherit;">Generate Link</button>'
      + '<span id="hdlv2-invite-status" style="margin-left:10px;font-size:13px;display:none;"></span>'
      + '</div>'
      + '<div id="hdlv2-invite-link-box" style="display:none;margin-bottom:16px;padding:12px 14px;background:#eef7f9;border-radius:8px;border:1px solid #d0e8ed;">'
      + '<p id="hdlv2-invite-link-label" role="status" aria-live="polite" style="font-size:13px;color:#1a1a1a;margin:0 0 10px;font-weight:500;line-height:1.5;">Assessment link created:</p>'
      + '<div style="display:flex;gap:6px;align-items:center;">'
      + '<input id="hdlv2-invite-link-url" type="text" readonly style="flex:1;padding:6px 8px;border:1px solid #ccc;border-radius:6px;font-size:11px;font-family:monospace;background:#fff;">'
      + '<button id="hdlv2-invite-link-copy" style="' + S.btnBase + 'background:' + S.green + ';white-space:nowrap;padding:6px 12px;font-size:12px;font-family:inherit;">Copy</button>'
      + '</div>'
      + '<p id="hdlv2-invite-link-meta" style="font-size:11px;color:#666;margin:8px 0 0;line-height:1.5;">'
      +   '<span id="hdlv2-invite-link-expiry"></span>'
      +   '<br><span style="color:#888;">Stops working after the client uses it once.</span>'
      + '</p>'
      + '</div>'
      + '</div>';
  }

  // Sent tab — list of invites + 30s polling refresh while this tab is visible.
  function sentTabContent() {
    return '<div style="padding:4px 0;">'
      + '<div style="display:flex;align-items:center;justify-content:space-between;margin:0 0 10px;">'
      + '<h4 style="margin:0;font-size:14px;font-weight:600;">Sent Invites</h4>'
      + '<span id="hdlv2-invites-freshness" style="font-size:11px;color:#9ca3af;">&nbsp;</span>'
      + '</div>'
      + '<div id="hdlv2-invite-list" style="font-size:13px;color:#888;">Loading...</div>'
      + '</div>';
  }

  // ──────────────────────────────────────────────────────────────
  //  TAB CONTENT: PENDING LEADS  (v0.35.0 — Phase O)
  //
  //  Inbox of widget submissions awaiting practitioner action. The list
  //  body is rendered async by loadPendingLeads() the moment the tab
  //  activates (and on every re-activation). Each row carries Confirm
  //  and Reject actions wired to /widget/leads/confirm and /reject.
  // ──────────────────────────────────────────────────────────────

  function pendingTabContent() {
    // v0.35.1 — compacted layout. Single intro line, smaller header,
    // dense rows. The chatty intro paragraph from v0.35.0 is gone — the
    // header pill + tooltip on Confirm/Reject buttons carry the meaning
    // for practitioners who've used it once. Helps fit ~10 leads in the
    // visible viewport without scrolling.
    return '<div style="padding:2px 0;">'
      + '<div style="display:flex;align-items:center;justify-content:space-between;margin:0 0 8px;gap:10px;">'
      +   '<h4 style="margin:0;font-size:13px;font-weight:600;color:#222;">Pending widget submissions<span id="hdlv2-pending-count" style="display:none;background:' + S.teal + ';color:#fff;font-size:10px;font-weight:700;padding:2px 7px;border-radius:999px;margin-left:8px;vertical-align:middle;line-height:1.3;"></span></h4>'
      +   '<span id="hdlv2-pending-freshness" style="font-size:11px;color:#9ca3af;">&nbsp;</span>'
      + '</div>'
      + '<p style="font-size:11px;color:#888;margin:0 0 10px;line-height:1.45;"><strong style="color:#444;">Confirm</strong> to email the client their next-step magic link, or <strong style="color:#444;">Reject</strong> to silently drop spam.</p>'
      + '<div id="hdlv2-pending-list" style="font-size:13px;color:#888;">Loading…</div>'
      + '</div>';
  }

  // Outstanding action requests, keyed by leadId, so a slow network can't
  // produce a double-fire even if the user finds a way to click the button
  // again before the first response returns. The list is also re-rendered
  // on every successful action, but we belt-and-braces here.
  var _pendingLeadActioning = {};

  function loadPendingLeads() {
    var listEl = document.getElementById('hdlv2-pending-list');
    var countEl = document.getElementById('hdlv2-pending-count');
    var freshEl = document.getElementById('hdlv2-pending-freshness');
    if (!listEl) return;
    listEl.innerHTML = '<p style="color:#aaa;font-size:12px;margin:0;">Loading…</p>';

    var url = (CFG.rest_base || '/wp-json/') + 'hdl-v2/v1/widget/leads/pending';
    fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': CFG.rest_nonce || CFG.nonce || '' }
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        var leads = (data && Array.isArray(data.leads)) ? data.leads : [];
        renderPendingLeads(leads);
        if (countEl) {
          if (leads.length > 0) {
            countEl.textContent = String(leads.length);
            countEl.style.display = 'inline-block';
          } else {
            countEl.style.display = 'none';
          }
        }
        if (freshEl) {
          var now = new Date();
          var hh = String(now.getHours()).padStart(2, '0');
          var mm = String(now.getMinutes()).padStart(2, '0');
          freshEl.textContent = 'Updated ' + hh + ':' + mm;
        }
      })
      .catch(function () {
        listEl.innerHTML = '<p style="color:' + S.red + ';font-size:12px;margin:0;">Couldn’t load pending leads. Refresh and try again.</p>';
      });
  }

  function renderPendingLeads(leads) {
    var listEl = document.getElementById('hdlv2-pending-list');
    if (!listEl) return;
    if (!leads.length) {
      listEl.innerHTML = ''
        + '<div style="padding:24px 16px;text-align:center;border:1px dashed #e4e6ea;border-radius:10px;background:#fafbfc;">'
        +   '<div style="font-size:28px;margin:0 0 6px;">📭</div>'
        +   '<p style="font-size:13px;color:#444;margin:0 0 4px;font-weight:600;">No pending leads.</p>'
        +   '<p style="font-size:12px;color:#888;margin:0;line-height:1.5;">When someone fills the widget on your site, they’ll appear here for you to confirm.</p>'
        + '</div>';
      return;
    }
    listEl.innerHTML = '<ul style="list-style:none;margin:0;padding:0;">'
      + leads.map(renderPendingLeadRow).join('')
      + '</ul>';
    listEl.querySelectorAll('[data-pl-action]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        handlePendingLeadAction(btn);
      });
    });
  }

  function renderPendingLeadRow(lead) {
    function esc(s) {
      var d = document.createElement('div');
      d.textContent = (s == null ? '' : String(s));
      return d.innerHTML.replace(/"/g, '&quot;');
    }
    var rate = (lead.rate_of_ageing !== null && lead.rate_of_ageing !== undefined)
      ? Number(lead.rate_of_ageing).toFixed(2)
      : '—';
    var ageStr = lead.visitor_age ? ' &middot; age ' + lead.visitor_age : '';
    // The server returns MySQL DATETIME (server time, no timezone). Display
    // as "today HH:MM" / "yesterday" / dd-mm-yyyy for relative readability.
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
    // v0.35.1 — compact two-line row: name on top, meta below. Tighter
    // padding, smaller buttons. ~30% less vertical space than v0.35.0.
    return ''
      + '<li class="hdlv2-pl-row" data-lead-id="' + lead.id + '" style="display:flex;align-items:center;justify-content:space-between;background:#fff;border:1px solid #e4e6ea;border-radius:8px;padding:9px 12px;margin:0 0 6px;gap:10px;">'
      +   '<div style="flex:1;min-width:0;overflow:hidden;">'
      +     '<div style="font-weight:600;color:#222;font-size:13px;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + (esc(lead.visitor_name) || '<em style="color:#888;font-style:italic;font-weight:400;">No name</em>') + '</div>'
      +     '<div style="font-size:11px;color:#888;margin-top:2px;line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'
      +       esc(lead.visitor_email) + ageStr + ' &middot; ' + rate + '&times;' + (submittedAt ? ' &middot; ' + esc(submittedAt) : '')
      +     '</div>'
      +   '</div>'
      +   '<div style="display:flex;gap:5px;flex-shrink:0;">'
      +     '<button type="button" data-pl-action="confirm" data-lead-id="' + lead.id + '" title="Send the client their next-step magic link" style="font:inherit;font-size:12px;font-weight:600;padding:6px 12px;border-radius:6px;cursor:pointer;border:1px solid ' + S.teal + ';background:' + S.teal + ';color:#fff;font-family:inherit;line-height:1.3;">Confirm</button>'
      +     '<button type="button" data-pl-action="reject"  data-lead-id="' + lead.id + '" title="Silently drop without emailing the client" style="font:inherit;font-size:12px;font-weight:600;padding:6px 12px;border-radius:6px;cursor:pointer;border:1px solid #e4e6ea;background:#fff;color:#666;font-family:inherit;line-height:1.3;">Reject</button>'
      +   '</div>'
      + '</li>';
  }

  // v0.35.1 — emit a realtime event so the V1 client-list enhancer (which
  // owns the V1 client roster + the "What needs you today" action queue)
  // can refresh without waiting for its 4-second version poll. The
  // listener in hdlv2-client-list-enhance.js calls its pollNow() to
  // re-fetch /dashboard/clients + /widget/leads/pending and re-render.
  // Dispatched on BOTH confirm and reject so the inbox count + the
  // action-queue summary update in lockstep.
  function emitLeadActioned(action, leadId, formToken) {
    try {
      window.dispatchEvent(new CustomEvent('hdlv2:lead-actioned', {
        detail: { action: action, leadId: leadId, formToken: formToken || null, ts: Date.now() }
      }));
    } catch (e) { /* IE/old-browser fallback — silent */ }
  }

  // v0.35.1 — listen for the symmetric event fired from the
  // client-list-enhance action queue, so if the practitioner Confirms a
  // lead via the queue while the modal is open on the Pending Leads tab,
  // the modal's row list refreshes too (otherwise it'd show a stale
  // already-confirmed row until the next tab switch).
  window.addEventListener('hdlv2:lead-actioned', function () {
    var pendingPanel = document.getElementById('hdlv2-popup-tab-widget-pending');
    if (pendingPanel && pendingPanel.style.display !== 'none') {
      loadPendingLeads();
    }
  });

  function handlePendingLeadAction(btn) {
    var action = btn.getAttribute('data-pl-action');
    var leadId = parseInt(btn.getAttribute('data-lead-id'), 10);
    if (!leadId || (action !== 'confirm' && action !== 'reject')) return;

    // Per-lead lock so a re-fire while the request is in flight is
    // silently swallowed. The disabled state on the buttons is the
    // primary defense; this is just belt-and-braces.
    if (_pendingLeadActioning[leadId]) return;

    if (action === 'reject' && !window.confirm('Reject this lead? They will not receive any further emails. You can’t undo this without them resubmitting the widget.')) {
      return;
    }

    _pendingLeadActioning[leadId] = true;

    var row = btn.closest('.hdlv2-pl-row');
    if (row) {
      var rowButtons = row.querySelectorAll('button');
      for (var i = 0; i < rowButtons.length; i++) {
        rowButtons[i].disabled = true;
        rowButtons[i].style.opacity = '0.55';
        rowButtons[i].style.cursor = 'default';
      }
      btn.textContent = (action === 'confirm') ? 'Confirming…' : 'Rejecting…';
    }

    var url = (CFG.rest_base || '/wp-json/') + 'hdl-v2/v1/widget/leads/' + action;
    fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': CFG.rest_nonce || CFG.nonce || ''
      },
      body: JSON.stringify({ lead_id: leadId })
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        delete _pendingLeadActioning[leadId];
        if (!res.ok) {
          var msg = (res.body && (res.body.message || res.body.code)) || (action + ' failed');
          alert('Could not ' + action + ' this lead: ' + msg);
          loadPendingLeads(); // re-fetch so the buttons reset cleanly
          return;
        }
        if (row) {
          row.style.transition = 'opacity 280ms';
          row.style.opacity = '0.25';
        }
        // v0.35.1 — fire realtime event so the V1 client list + action
        // queue refresh immediately (no waiting for the 4s digest poll).
        emitLeadActioned(action, leadId, res.body && res.body.form_token);
        setTimeout(loadPendingLeads, 350);
      })
      .catch(function () {
        delete _pendingLeadActioning[leadId];
        alert('Network error. Please try again.');
        loadPendingLeads();
      });
  }

  // ──────────────────────────────────────────────────────────────
  //  CONFIG ACTIONS
  // ──────────────────────────────────────────────────────────────

  function getFormValues() {
    var bookEl = document.getElementById('hdlv2-show_book_button_after_widget');
    return {
      practitioner_name: val('hdlv2-practitioner_name'),
      clinic_name: val('hdlv2-clinic_name'),
      logo_url: val('hdlv2-logo_url'),
      cta_text: val('hdlv2-cta_text') || ('Book a session with ' + (val('hdlv2-practitioner_name') || 'a practitioner')),
      cta_link: val('hdlv2-cta_link'),
      webhook_url: val('hdlv2-webhook_url'),
      notification_email: val('hdlv2-notification_email'),
      theme_color: val('hdlv2-theme_color') || '#3d8da0',
      // v0.35.0 (Phase O) — Book-button-on-public-result toggle. Sent as
      // a 0/1 int so PHP's ! empty() / TINYINT(1) match exactly.
      show_book_button_after_widget: (bookEl && bookEl.checked) ? 1 : 0
    };
  }

  function val(id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; }

  function saveConfig() {
    var btn = document.getElementById('hdlv2-save-config');
    var status = document.getElementById('hdlv2-save-status');

    // v0.29.1 — required-field pre-flight: Booking Link AND Notification
    // Email. Without either, the practitioner is silently invisible to
    // visitors (no Book CTA) or to incoming leads (no email pings).
    var ctaEl    = document.getElementById('hdlv2-cta_link');
    var notifyEl = document.getElementById('hdlv2-notification_email');
    var firstInvalid = null;
    var msg = '';

    if (!ctaEl || !ctaEl.value.trim()) {
      if (ctaEl) ctaEl.style.borderColor = S.red;
      firstInvalid = ctaEl;
      msg = 'Booking Link is required';
    } else if ( ! /^https?:\/\/.+/i.test( ctaEl.value.trim() ) ) {
      // v0.40.8 — URL scheme validation. Practitioner saving `calendly.com/me`
      // (no protocol) used to silently render as a relative URL → 404 on the
      // widget result page Book CTA. Now blocked at the source.
      ctaEl.style.borderColor = S.red;
      ctaEl.classList.add('is-invalid');
      var ctaErr = document.getElementById('hdlv2-cta_link-err');
      if ( ctaErr ) ctaErr.classList.add('is-visible');
      firstInvalid = ctaEl;
      msg = 'Booking Link must start with https:// or http://';
    } else {
      ctaEl.style.borderColor = '#ddd';
      ctaEl.classList.remove('is-invalid');
      var ctaErrClear = document.getElementById('hdlv2-cta_link-err');
      if ( ctaErrClear ) ctaErrClear.classList.remove('is-visible');
    }

    if (!notifyEl || !notifyEl.value.trim()) {
      if (notifyEl) notifyEl.style.borderColor = S.red;
      if (!firstInvalid) firstInvalid = notifyEl;
      msg = msg ? 'Booking Link and Notification Email are required' : 'Notification Email is required';
    } else {
      notifyEl.style.borderColor = '#ddd';
    }

    if (firstInvalid) {
      status.textContent = msg; status.style.color = S.red; status.style.display = 'inline';
      firstInvalid.focus();
      firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }

    btn.disabled = true; btn.textContent = 'Saving...';

    var data = new FormData();
    data.append('action', 'hdlv2_save_widget_config');
    data.append('nonce', CFG.nonce);
    var vals = getFormValues();
    for (var key in vals) data.append(key, vals[key]);

    fetch(CFG.ajax_url, { method: 'POST', body: data })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        btn.disabled = false; btn.textContent = 'Save Configuration';
        if (res.success) {
          status.textContent = 'Saved!'; status.style.color = S.green; status.style.display = 'inline';
          setTimeout(function () { status.style.display = 'none'; }, 3000);
          document.getElementById('hdlv2-embed-code').value = res.data.embed_code;
          updatePreview();
        } else {
          status.textContent = 'Error: ' + (res.data || 'Save failed'); status.style.color = S.red; status.style.display = 'inline';
        }
      })
      .catch(function () { btn.disabled = false; btn.textContent = 'Save Configuration'; status.textContent = 'Network error'; status.style.color = S.red; status.style.display = 'inline'; });
  }

  function copyEmbed() { copyToClipboard('hdlv2-embed-code', 'hdlv2-copy-embed'); }

  // v0.29.1 — practitioner-side preview can target Q1, Q5, or Result.
  // updatePreview() rebuilds the widget from current form values, then drives
  // it to the requested step via the .__hdlGoTo hook exposed by the widget.
  var previewTargetStep = 0;

  function updatePreview() {
    var vals = getFormValues();
    var preview = document.getElementById('hdlv2-widget-preview');
    if (!preview) return;
    preview.innerHTML = '';
    var div = document.createElement('div');
    div.className = 'hdl-rate-widget';
    div.setAttribute('data-practitioner-id', CFG.practitioner_id);
    div.setAttribute('data-practitioner-name', vals.practitioner_name);
    div.setAttribute('data-logo', vals.logo_url);
    // v0.40.10 — pass logo_shape too. Widget defaults to 'square' when
    // attribute is missing; without this line, a practitioner who picked
    // 'wordmark' or 'tall' saw the wrong cropping in preview vs. their
    // actual embed. Read from CFG.config so a fresh re-render uses the
    // saved shape, not a stale value.
    div.setAttribute('data-logo-shape', (CFG.config && CFG.config.logo_shape) || 'square');
    div.setAttribute('data-cta-text', vals.cta_text);
    div.setAttribute('data-cta-link', vals.cta_link || '#');
    div.setAttribute('data-api', '');
    div.setAttribute('data-color', vals.theme_color);
    preview.appendChild(div);
    if (window.hdlRateWidgetInit) {
      window.hdlRateWidgetInit();
    }
    // Drive the freshly-mounted widget to the requested step. The hook
    // .__hdlGoTo (added in widget/hdl-lead-magnet.js v0.29.1) lives on the
    // OUTER .hdl-rate-widget wrapper, not on the inner #hdlw-<id> shell.
    if (previewTargetStep !== 0) {
      var wrap = preview.querySelector('.hdl-rate-widget');
      if (wrap && typeof wrap.__hdlGoTo === 'function') {
        wrap.__hdlGoTo(previewTargetStep);
      }
    }
  }

  // Step toggles above the preview. Practitioners can preview Q1, Q5, or
  // the full result page without walking through 9 questions to verify a
  // colour change.
  function bindPreviewToggles() {
    var bar = document.getElementById('hdlv2-preview-toggle');
    if (!bar) return;
    bar.addEventListener('click', function (e) {
      var a = e.target.closest('[data-step]');
      if (!a) return;
      e.preventDefault();
      previewTargetStep = parseInt(a.getAttribute('data-step'), 10) || 0;
      // Restyle: active = teal bold, others = grey
      bar.querySelectorAll('a').forEach(function (link) {
        var active = parseInt(link.getAttribute('data-step'), 10) === previewTargetStep;
        link.style.color = active ? S.teal : '#aaa';
        link.style.fontWeight = active ? '600' : '400';
      });
      updatePreview();
    });
  }

  // ──────────────────────────────────────────────────────────────
  //  INVITE ACTIONS
  // ──────────────────────────────────────────────────────────────

  // v0.29.0 — Send-Invites match-found indicator. Practitioner types an
  // email; we ask the server whether a prior public-widget submission for
  // the same (practitioner, email) exists and surface the answer inline so
  // the practitioner knows the upcoming invite link will arrive pre-filled.
  // Debounced 600ms on input, fires immediately on blur.
  function bindInviteLookup() {
    var emailEl = document.getElementById('hdlv2-invite_email');
    var hintEl  = document.getElementById('hdlv2-invite-lookup-hint');
    if (!emailEl || !hintEl) return;
    var t = null;
    var lastQuery = '';

    function isEmail(v) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
    }

    function clearHint() {
      hintEl.style.display = 'none';
      hintEl.innerHTML = '';
    }

    function renderHint(found, submittedAt, name) {
      hintEl.style.display = 'block';
      if (found) {
        var when = submittedAt ? new Date(submittedAt.replace(' ', 'T') + 'Z') : null;
        var dateStr = (when && !isNaN(when.getTime()))
          ? when.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
          : 'an earlier date';
        var who = name ? (' from ' + name) : '';
        hintEl.innerHTML = '<span style="color:' + S.teal + ';font-weight:600;">&#10003;</span> '
          + '<span style="color:#1a1a1a;">Found a previous widget submission' + escAttr(who) + ' on '
          + dateStr + '.</span> '
          + '<span style="color:#666;">Their answers will be pre-filled in the invite link.</span>';
      } else {
        hintEl.innerHTML = '<span style="color:#9ca3af;">&#9675;</span> '
          + '<span style="color:#666;">No prior widget submission for this email — they\'ll start fresh.</span>';
      }
    }

    function doLookup(email) {
      if (lastQuery === email) return;
      lastQuery = email;
      var data = new FormData();
      data.append('action', 'hdlv2_widget_lead_lookup');
      data.append('nonce', CFG.nonce);
      data.append('email', email);
      fetch(CFG.ajax_url, { method: 'POST', body: data })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (lastQuery !== email) return; // stale response
          if (res && res.success && res.data) {
            renderHint(!!res.data.found, res.data.submitted_at || '', res.data.visitor_name || '');
          } else {
            clearHint();
          }
        })
        .catch(function () { /* network — silent, don't spam the practitioner */ });
    }

    function maybeLookup() {
      var v = (emailEl.value || '').trim();
      if (!v) { lastQuery = ''; clearHint(); return; }
      if (!isEmail(v)) { lastQuery = ''; clearHint(); return; }
      doLookup(v);
    }

    emailEl.addEventListener('input', function () {
      if (t) clearTimeout(t);
      t = setTimeout(maybeLookup, 600);
    });
    emailEl.addEventListener('blur', function () {
      if (t) clearTimeout(t);
      maybeLookup();
    });
  }

  function createInvite() {
    var btn = document.getElementById('hdlv2-create-invite');
    var status = document.getElementById('hdlv2-invite-status');
    var nameEl = document.getElementById('hdlv2-invite_name');
    var emailEl = document.getElementById('hdlv2-invite_email');
    var clientName = nameEl.value.trim();
    var clientEmail = emailEl.value.trim();

    if (!clientEmail) {
      emailEl.style.borderColor = S.red;
      status.textContent = 'Email is required'; status.style.color = S.red; status.style.display = 'inline';
      return;
    }
    emailEl.style.borderColor = '#ddd';
    btn.disabled = true; btn.textContent = 'Generating...'; status.style.display = 'none';

    var data = new FormData();
    data.append('action', 'hdlv2_create_invite');
    data.append('nonce', CFG.nonce);
    data.append('client_name', clientName);
    data.append('client_email', clientEmail);
    data.append('expires_minutes', document.getElementById('hdlv2-invite_expires').value);

    fetch(CFG.ajax_url, { method: 'POST', body: data })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        btn.disabled = false; btn.textContent = 'Generate Link';
        if (res.success) {
          // Capture name + email BEFORE we clear the form fields below — the
          // recipient label needs them.
          var sentName  = clientName;
          var sentEmail = clientEmail;

          var linkBox = document.getElementById('hdlv2-invite-link-box');
          var linkUrl = document.getElementById('hdlv2-invite-link-url');
          var label   = document.getElementById('hdlv2-invite-link-label');
          linkBox.style.display = 'block';
          linkUrl.value = res.data.url;
          // Reset any stale expired-state from a previous link, then write the
          // recipient-aware label (Phase 18E) and start the expiry countdown
          // (Phase 18B). setLinkBoxExpiredState(false) restores the static
          // "Assessment link created:" fallback first; we overwrite it below.
          setLinkBoxExpiredState(false);
          if (label) label.innerHTML = buildInviteSentLabel(sentName, sentEmail, !!res.data.email_sent);
          startLinkBoxCountdown(res.data.expires_at);
          document.getElementById('hdlv2-invite-link-copy').onclick = function () { copyToClipboard('hdlv2-invite-link-url', 'hdlv2-invite-link-copy'); };
          nameEl.value = ''; emailEl.value = '';
          // Reset the prefill-found hint when the form clears — otherwise it
          // would linger after a successful generation and confuse the next
          // typed email.
          var lookupHint = document.getElementById('hdlv2-invite-lookup-hint');
          if (lookupHint) { lookupHint.style.display = 'none'; lookupHint.innerHTML = ''; }
          // Hide the inline span on success — the link-box label is now the
          // single, persistent source of truth (per Matthew's PDF feedback).
          status.style.display = 'none';
          loadInvites();
        } else {
          status.textContent = res.data || 'Failed'; status.style.color = S.red; status.style.display = 'inline';
          setTimeout(function () { status.style.display = 'none'; }, 4000);
        }
      })
      .catch(function () { btn.disabled = false; btn.textContent = 'Generate Link'; status.textContent = 'Network error'; status.style.color = S.red; status.style.display = 'inline'; setTimeout(function () { status.style.display = 'none'; }, 4000); });
  }

  // Build the recipient-aware HTML for the link-created label. Stays inside
  // the existing palette — teal accent for the ✓, dark grey body, monospace
  // grey for the email. No new colours introduced.
  function buildInviteSentLabel(name, email, emailSent) {
    var trimmed = (name || '').trim();
    var iconHtml = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;margin-right:6px;color:' + S.teal + ';" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
    var emailHtml = '<span style="font-family:monospace;color:#1a1a1a;">' + escAttr(email) + '</span>';
    if (!emailSent) {
      // Email delivery unavailable (STBY without Brevo, or wp_mail failure).
      // Tone stays calm + actionable — the link itself was generated fine.
      return iconHtml + 'Link ready to copy — share it with ' + emailHtml + ' directly.';
    }
    if (trimmed) {
      return iconHtml + 'Link sent to <strong style="color:#1a1a1a;font-weight:600;">' + escAttr(trimmed) + '</strong> at ' + emailHtml + ' — the assessment is on its way.';
    }
    return iconHtml + 'Link sent to ' + emailHtml + " — they'll receive it shortly.";
  }

  // silent=true → poll refresh; keep current UI on failure, don't flash "Loading..."
  function loadInvites(silent) {
    var list = document.getElementById('hdlv2-invite-list');
    if (!list) return;
    if (!silent) list.innerHTML = 'Loading...';

    var data = new FormData();
    data.append('action', 'hdlv2_get_invites');
    data.append('nonce', CFG.nonce);

    fetch(CFG.ajax_url, { method: 'POST', body: data })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.success || !res.data || res.data.length === 0) {
          list.innerHTML = '<p style="color:#aaa;font-size:12px;">No invites sent yet.</p>';
          markInvitesFresh();
          return;
        }
        renderInviteList(res.data);
        markInvitesFresh();
      })
      .catch(function () {
        if (!silent) list.innerHTML = '<p style="color:' + S.red + ';font-size:12px;">Failed to load.</p>';
      });
  }

  function markInvitesFresh() {
    var el = document.getElementById('hdlv2-invites-freshness');
    if (!el) return;
    el.textContent = 'Updated just now';
    el.style.color = '#9ca3af';
    // Fade label after a few seconds so it doesn't flash on each poll.
    clearTimeout(markInvitesFresh._t);
    markInvitesFresh._t = setTimeout(function () {
      if (el.textContent === 'Updated just now') el.textContent = '';
    }, 4000);
  }

  // Sort priority: rows that need practitioner action (pending + opened) first,
  // then completed, then expired/revoked. Newest first within each bucket.
  var INVITE_PRIORITY = { pending: 0, opened: 1, completed: 2, expired: 3, revoked: 4 };

  function sortInvitesForDisplay(invites) {
    return invites.slice().sort(function (a, b) {
      var pa = INVITE_PRIORITY.hasOwnProperty(a.status) ? INVITE_PRIORITY[a.status] : 5;
      var pb = INVITE_PRIORITY.hasOwnProperty(b.status) ? INVITE_PRIORITY[b.status] : 5;
      if (pa !== pb) return pa - pb;
      var ca = a.created_at || '';
      var cb = b.created_at || '';
      if (cb > ca) return 1;
      if (cb < ca) return -1;
      return 0;
    });
  }

  function renderInviteList(invites) {
    var list = document.getElementById('hdlv2-invite-list');
    var rows = sortInvitesForDisplay(invites);

    // Skip DOM re-render if nothing changed (prevents poll flicker + scroll jump).
    var hash = invitesHashForDiff(rows);
    if (hash === invitesLastHash && list.querySelector('table')) return;
    invitesLastHash = hash;

    var html = '<table style="width:100%;border-collapse:collapse;font-size:12px;">'
      + '<thead><tr style="border-bottom:2px solid #eee;text-align:left;">'
      + '<th style="padding:6px 6px 6px 0;font-weight:600;color:#555;">Client</th>'
      + '<th style="padding:6px;font-weight:600;color:#555;">Status</th>'
      + '<th style="padding:6px;font-weight:600;color:#555;">Sent</th>'
      + '<th style="padding:6px;font-weight:600;color:#555;">Expires</th>'
      + '<th style="padding:6px 0 6px 6px;font-weight:600;color:#555;">Action</th>'
      + '</tr></thead><tbody>';

    rows.forEach(function (inv) {
      // Row-level effective expired: server status OR client-side time check
      // (server-status flip lags by up to one cron tick; this catches the gap).
      var rowExpired = isEffectivelyExpired(inv);
      var displayStatus = rowExpired ? 'expired' : inv.status;
      var badge = inviteStatusBadge(displayStatus);

      var action = '<span style="color:#cbd5e1;padding-left:6px;">—</span>';
      // v0.46.22 — the × (permanent delete) shows on any NON-ACTIVE invite:
      // expired, revoked, OR completed. Only Pending/Opened are still usable by
      // the client, so they stay protected (Copy only). Matches the server
      // allowlist in hdlv2_delete_invite. Removing a completed invite drops only
      // the "sent invite" record — the client's account/reports are untouched.
      var canDelete = ( inv.status === 'expired' || inv.status === 'revoked' || inv.status === 'completed' );
      var deleteBtn = canDelete
        ? '<button data-delete-invite="' + inv.id + '" title="Remove this invite" aria-label="Remove this invite" style="background:none;border:none;color:#9ca3af;cursor:pointer;font-size:16px;line-height:1;padding:2px 5px;font-family:inherit;">&times;</button>'
        : '';
      if (rowExpired) {
        // Effectively-expired (incl. completed past its date) → Resend + (× if deletable).
        action = '<span style="display:inline-flex;align-items:center;gap:6px;">'
          + '<button data-resend-email="' + escAttr(inv.client_email) + '" data-resend-name="' + escAttr(inv.client_name) + '" style="' + S.btnBase + 'background:' + S.amber + ';padding:4px 10px;font-size:11px;">Resend</button>'
          + deleteBtn
          + '</span>';
      } else if (inv.status === 'pending' || inv.status === 'opened') {
        action = '<button data-copy-url="' + escAttr(inv.url) + '" style="' + S.btnBase + 'background:' + S.teal + ';padding:4px 10px;font-size:11px;">Copy</button>';
      } else if (deleteBtn) {
        // e.g. completed but not yet past its expiry date → × only (no Resend).
        action = '<span style="display:inline-flex;align-items:center;gap:6px;">' + deleteBtn + '</span>';
      }

      var expiresCell = renderExpiresCell(inv, rowExpired);
      var rowStyle = rowExpired
        ? 'border-bottom:1px solid #f0f0f0;background:#f9fafb;'
        : 'border-bottom:1px solid #f0f0f0;';
      var nameColor  = rowExpired ? '#9ca3af' : '#333';
      var emailColor = rowExpired ? '#b8bcc4' : '#999';
      var sentColor  = rowExpired ? '#b8bcc4' : '#888';

      html += '<tr style="' + rowStyle + '">'
        + '<td style="padding:8px 6px 8px 0;"><div style="font-weight:500;color:' + nameColor + ';font-size:12px;">' + escAttr(inv.client_name || 'No name') + '</div><div style="font-size:11px;color:' + emailColor + ';">' + escAttr(inv.client_email) + '</div></td>'
        + '<td style="padding:8px 6px;">' + badge + '</td>'
        + '<td style="padding:8px 6px;color:' + sentColor + ';font-size:11px;">' + formatDate(inv.created_at) + '</td>'
        + '<td style="padding:8px 6px;font-size:11px;">' + expiresCell + '</td>'
        + '<td style="padding:8px 0 8px 6px;">' + action + '</td></tr>';
    });
    html += '</tbody></table>';
    list.innerHTML = html;

    list.querySelectorAll('[data-copy-url]').forEach(function (btn) {
      btn.addEventListener('click', function () { copyText(btn.getAttribute('data-copy-url')); btn.textContent = 'Copied!'; setTimeout(function () { btn.textContent = 'Copy'; }, 2000); });
    });
    list.querySelectorAll('[data-resend-email]').forEach(function (btn) {
      btn.addEventListener('click', function () { resendInvite(btn, btn.getAttribute('data-resend-name'), btn.getAttribute('data-resend-email')); });
    });
    list.querySelectorAll('[data-delete-invite]').forEach(function (btn) {
      btn.addEventListener('click', function () { deleteInvite(btn, btn.getAttribute('data-delete-invite')); });
      // Hover tint via JS (inline :hover isn't possible) — muted grey → red.
      btn.addEventListener('mouseenter', function () { if (!btn.disabled) btn.style.color = S.red; });
      btn.addEventListener('mouseleave', function () { if (!btn.disabled) btn.style.color = '#9ca3af'; });
    });
  }

  // Render a single Expires-column cell. Title attribute carries the absolute
  // timestamp on hover for relative-formatted cells, so the practitioner can
  // always see the exact expiry datetime if they need it.
  function renderExpiresCell(inv, rowExpired) {
    if (rowExpired) {
      var abs = formatExpiryAbsolute(inv.expires_at);
      return '<span style="color:#9ca3af;"' + (abs ? ' title="' + escAttr(abs) + '"' : '') + '>Expired</span>';
    }
    if (!inv.expires_at) return '<span style="color:#cbd5e1;">—</span>';
    var d = parseUtcDate(inv.expires_at);
    if (!d) return '<span style="color:#cbd5e1;">—</span>';
    var msLeft = d.getTime() - Date.now();
    var label = msLeft <= 24 * 60 * 60 * 1000
      ? formatExpiryRelative(inv.expires_at)
      : formatExpiryAbsolute(inv.expires_at);
    var titleAttr = ' title="' + escAttr(formatExpiryAbsolute(inv.expires_at)) + '"';
    return '<span style="color:#666;"' + titleAttr + '>' + escAttr(label) + '</span>';
  }

  // ──────────────────────────────────────────────────────────────
  //  REALTIME INVITE STATUS — 30s polling while Invitations tab visible
  // ──────────────────────────────────────────────────────────────
  //
  //  Triggers covered:
  //    • client clicks invite link  → status flips pending → opened
  //    • client completes Stage 1   → status flips opened → completed
  //    • cron expires old invite    → status flips → expired
  //
  //  Poll runs only when:
  //    • modal is open (Invitations panel display != 'none')
  //    • browser tab is foregrounded (document.visibilityState === 'visible')
  //
  //  Concurrency note (10-50 users target, 60-100 triggers backend upgrade):
  //    Each practitioner polls at most 2 req/min while modal is open.
  //    50 practitioners × 2/min = ~1.7 req/s — well within admin-ajax limits.
  //
  var INVITES_POLL_MS = 30000;
  var invitesPollTimer = null;
  var invitesLastHash = '';

  function startInvitesPolling() {
    stopInvitesPolling();
    invitesPollTimer = setInterval(pollInvitesTick, INVITES_POLL_MS);
  }

  function stopInvitesPolling() {
    if (invitesPollTimer) { clearInterval(invitesPollTimer); invitesPollTimer = null; }
  }

  function pollInvitesTick() {
    if (document.visibilityState === 'hidden') return; // pause on backgrounded tab
    var panel = document.getElementById('hdlv2-popup-tab-widget-sent');
    if (!panel || panel.style.display === 'none') { stopInvitesPolling(); return; }
    var overlay = document.getElementById('invite-client-popup-overlay');
    if (!overlay || overlay.style.display === 'none') { stopInvitesPolling(); return; }
    loadInvites(true); // silent refresh
  }

  // Called from renderInviteList to dedupe — skip DOM churn if nothing changed.
  // Includes the *effective* status (server-status OR client-side expiry check)
  // so that a row that flips from pending → expired between cron ticks still
  // triggers a re-render at the next 30s poll.
  function invitesHashForDiff(invites) {
    var arr = invites.map(function (i) {
      var eff = isEffectivelyExpired(i) ? 'expired' : i.status;
      return [i.id, eff, i.created_at || '', i.client_email || ''].join('|');
    });
    arr.sort();
    return arr.join('~');
  }

  // Resume polling if user returns to this tab in a different browser window.
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      var panel = document.getElementById('hdlv2-popup-tab-widget-sent');
      var overlay = document.getElementById('invite-client-popup-overlay');
      if (panel && panel.style.display !== 'none'
          && overlay && overlay.style.display !== 'none'
          && !invitesPollTimer) {
        loadInvites(true);     // catch-up refresh immediately
        startInvitesPolling();
      }
    }
  });

  function inviteStatusBadge(status) {
    var colors = { pending:{bg:'#eef4ff',color:'#3b82f6',dot:'#3b82f6',label:'Pending'}, opened:{bg:'#fffbeb',color:'#d97706',dot:'#f59e0b',label:'Opened'}, completed:{bg:'#ecfdf5',color:'#059669',dot:'#10b981',label:'Completed'}, expired:{bg:'#f3f4f6',color:'#6b7280',dot:'#9ca3af',label:'Expired'}, revoked:{bg:'#fef2f2',color:'#dc2626',dot:'#ef4444',label:'Revoked'} };
    var c = colors[status] || colors.pending;
    return '<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;background:' + c.bg + ';color:' + c.color + ';font-size:11px;font-weight:500;">'
      + '<span style="width:5px;height:5px;border-radius:50%;background:' + c.dot + ';"></span>' + c.label + '</span>';
  }

  function resendInvite(btnEl, clientName, clientEmail) {
    btnEl.disabled = true; btnEl.textContent = 'Sending...';
    var data = new FormData();
    data.append('action', 'hdlv2_create_invite');
    data.append('nonce', CFG.nonce);
    data.append('client_name', clientName);
    data.append('client_email', clientEmail);
    data.append('expires_minutes', '1440'); // Resends default to 24h — same as fresh-create default.

    fetch(CFG.ajax_url, { method: 'POST', body: data })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) { btnEl.textContent = 'Resent!'; btnEl.style.background = S.green; setTimeout(function () { loadInvites(); }, 1000); }
        else { btnEl.disabled = false; btnEl.textContent = 'Failed'; btnEl.style.background = S.red; setTimeout(function () { btnEl.textContent = 'Resend'; btnEl.style.background = S.amber; }, 2000); }
      })
      .catch(function () { btnEl.disabled = false; btnEl.textContent = 'Error'; btnEl.style.background = S.red; });
  }

  // In-flight delete guard, keyed by inviteId — mirrors _pendingLeadActioning.
  // A 30s poll / visibilitychange re-render could otherwise re-render the
  // in-flight × as an enabled button and let a second click double-submit
  // (harmless server-side — the 2nd DELETE matches 0 rows — but this keeps the
  // UX honest and prevents the spurious "try again" toast).
  var _deletingInvite = {};

  // v0.46.20 — permanently remove a dead (expired/revoked) invite row. Server
  // hard-deletes, scoped to own + expired/revoked only, so this can never touch
  // an active or completed invite. On success we re-fetch the now-shorter list
  // (mirrors resendInvite's refresh — no manual DOM splice, no dedupe drift).
  function deleteInvite(btnEl, inviteId) {
    if (_deletingInvite[inviteId]) return;
    _deletingInvite[inviteId] = true;

    btnEl.disabled = true;
    btnEl.setAttribute('aria-busy', 'true');
    btnEl.style.cursor = 'default';
    btnEl.style.color = '#cbd5e1';
    btnEl.textContent = '…';

    function fail() {
      delete _deletingInvite[inviteId];
      btnEl.disabled = false;
      btnEl.removeAttribute('aria-busy');
      btnEl.style.cursor = 'pointer';
      btnEl.style.color = S.red;
      btnEl.textContent = '×';
      btnEl.title = 'Couldn’t remove — try again';
    }

    var data = new FormData();
    data.append('action', 'hdlv2_delete_invite');
    data.append('nonce', CFG.nonce);
    data.append('invite_id', inviteId);

    fetch(CFG.ajax_url, { method: 'POST', body: data })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) {
          delete _deletingInvite[inviteId];
          // v0.46.22 — animate the row out instead of reloading the whole list
          // (the old loadInvites() flashed "Loading…" — the "page open/close").
          var tr = btnEl.closest ? btnEl.closest('tr') : null;
          fadeOutInviteRow(tr);
        } else {
          fail();
        }
      })
      .catch(fail);
  }

  // v0.46.22 — smooth row removal: fade the row, collapse its cells so the rows
  // below slide up, then drop it from the DOM. No list reload (which caused the
  // "Loading…" flash). invitesLastHash is reset so the next silent 30s poll
  // reconciles without a visible re-render; if the list is now empty we do one
  // silent refresh to surface the empty-state card.
  function fadeOutInviteRow(tr) {
    if (!tr || !tr.parentNode) { invitesLastHash = ''; loadInvites(true); return; }
    tr.style.transition = 'opacity 200ms ease';
    tr.style.opacity = '0';
    setTimeout(function () {
      var cells = tr.querySelectorAll('td');
      for (var i = 0; i < cells.length; i++) {
        var td = cells[i];
        td.style.transition = 'padding 160ms ease, font-size 160ms ease, line-height 160ms ease, border 160ms ease';
        td.style.paddingTop = '0';
        td.style.paddingBottom = '0';
        td.style.fontSize = '0';
        td.style.lineHeight = '0';
        td.style.borderBottom = '0 none';
      }
      setTimeout(function () {
        var list = document.getElementById('hdlv2-invite-list');
        if (tr.parentNode) { tr.parentNode.removeChild(tr); }
        invitesLastHash = '';
        if (list && !list.querySelector('tbody tr')) { loadInvites(true); }
      }, 170);
    }, 210);
  }

  // ──────────────────────────────────────────────────────────────
  //  V2 CLIENT PROGRESS + WHY GATE RELEASE
  // ──────────────────────────────────────────────────────────────

  var statusData = {}; // keyed by user_id from REST /dashboard/clients

  function loadV2Clients() {
    var list = document.getElementById('hdlv2-client-list');
    if (!list) return;

    // Fetch both data sources in parallel
    var ajaxData = new FormData();
    ajaxData.append('action', 'hdlv2_get_v2_clients');
    ajaxData.append('nonce', CFG.nonce);

    var restUrl = (CFG.rest_base || '/wp-json/') + 'hdl-v2/v1/dashboard/clients';

    Promise.all([
      fetch(CFG.ajax_url, { method: 'POST', body: ajaxData }).then(function (r) { return r.json(); }),
      fetch(restUrl, { headers: { 'X-WP-Nonce': CFG.nonce } }).then(function (r) { return r.json(); }).catch(function () { return []; })
    ]).then(function (results) {
      var ajaxRes = results[0];
      var statusRes = results[1];

      // Index status data by user_id
      if (Array.isArray(statusRes)) {
        statusRes.forEach(function (s) { statusData[s.user_id] = s; });
      }

      if (!ajaxRes.success || !ajaxRes.data || ajaxRes.data.length === 0) {
        list.innerHTML = '<p style="color:#aaa;font-size:12px;">No V2 clients yet.</p>';
        return;
      }
      renderV2Clients(ajaxRes.data);
    }).catch(function () { list.innerHTML = '<p style="color:' + S.red + ';font-size:12px;">Failed to load.</p>'; });
  }

  function renderV2Clients(clients) {
    var list = document.getElementById('hdlv2-client-list');

    // 5A: Sort — needs_attention first, then inactive, then rest
    var sortOrder = { needs_attention: 0, inactive: 1 };
    clients.sort(function (a, b) {
      var sa = statusData[a.client_user_id]; var sb = statusData[b.client_user_id];
      var oa = sa ? (sortOrder[sa.status] !== undefined ? sortOrder[sa.status] : 9) : 9;
      var ob = sb ? (sortOrder[sb.status] !== undefined ? sortOrder[sb.status] : 9) : 9;
      return oa - ob;
    });

    var html = '<table style="width:100%;border-collapse:collapse;font-size:12px;">'
      + '<thead><tr style="border-bottom:2px solid #eee;text-align:left;">'
      + '<th style="padding:6px 6px 6px 0;font-weight:600;color:#555;">Client</th>'
      + '<th style="padding:6px;font-weight:600;color:#555;">Status</th>'
      + '<th style="padding:6px;font-weight:600;color:#555;">WHY</th>'
      + '<th style="padding:6px 0 6px 6px;font-weight:600;color:#555;">Action</th>'
      + '</tr></thead><tbody>';

    clients.forEach(function (c) {
      var st = statusData[c.client_user_id] || {};
      var statusBadge = statusBadgeHtml(st);

      var whyStatus = '';
      var action = '';
      if (c.why_id) {
        if (c.released == 1) {
          whyStatus = '<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;background:#ecfdf5;color:#059669;font-size:11px;font-weight:500;">'
            + '<span style="width:5px;height:5px;border-radius:50%;background:#10b981;"></span>Released</span>';
        } else {
          whyStatus = '<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;background:#fffbeb;color:#d97706;font-size:11px;font-weight:500;">'
            + '<span style="width:5px;height:5px;border-radius:50%;background:#f59e0b;"></span>Pending</span>';
          action = '<button data-release-id="' + c.id + '" style="' + S.btnBase + 'background:' + S.teal + ';padding:4px 10px;font-size:11px;">Invite to Stage 3</button>';
        }
      } else if (c.current_stage >= 2 && !c.stage2_completed_at) {
        if (c.stage2_webhook_fired_at) {
          whyStatus = '<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;background:#f0f9ff;color:#0369a1;font-size:11px;font-weight:500;">'
            + '<span style="width:5px;height:5px;border-radius:50%;background:#0ea5e9;"></span>Submitted</span>';
        } else {
          whyStatus = '<span style="font-size:11px;color:#aaa;">In progress</span>';
        }
      } else {
        whyStatus = '<span style="font-size:11px;color:#ccc;">\u2014</span>';
      }

      // 5C: Weekly summary card (inline, for active clients)
      var summaryLine = '';
      if (st.status === 'progress_normal' || st.status === 'active' || st.status === 'needs_attention') {
        summaryLine = '<div style="font-size:10px;color:#888;margin-top:2px;">'
          + (st.last_checkin_date ? 'Last check-in: ' + formatDate(st.last_checkin_date) : 'No check-ins yet')
          + '</div>';
      }

      // Flag reason for needs_attention
      var flagLine = '';
      if (st.status === 'needs_attention' && st.reasons && st.reasons.length) {
        flagLine = '<div style="font-size:10px;color:#dc2626;margin-top:2px;">\u26a0 ' + escAttr(st.reasons[0]) + '</div>';
      }

      html += '<tr style="border-bottom:1px solid #f0f0f0;cursor:pointer;" data-client-expand="' + (c.client_user_id || '') + '" data-progress-id="' + c.id + '">'
        + '<td style="padding:8px 6px 8px 0;"><div style="font-weight:500;color:#333;font-size:12px;">' + escAttr(c.client_name || 'No name') + '</div><div style="font-size:11px;color:#999;">' + escAttr(c.client_email) + '</div>' + summaryLine + flagLine + '</td>'
        + '<td style="padding:8px 6px;">' + statusBadge + '</td>'
        + '<td style="padding:8px 6px;">' + whyStatus + '</td>'
        + '<td style="padding:8px 0 8px 6px;">' + action + '</td></tr>';

      // 5B: Expandable quick actions row (hidden by default)
      html += '<tr id="hdlv2-expand-' + (c.client_user_id || '') + '" style="display:none;"><td colspan="4" style="padding:8px 0 12px;">'
        + '<div style="background:#f8f9fb;border:1px solid #e4e6ea;border-radius:8px;padding:12px;">'
        + '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">'
        + '<button data-qa="note" data-cid="' + (c.client_user_id || '') + '" style="' + S.btnBase + 'background:' + S.teal + ';padding:5px 12px;font-size:11px;">Add Note</button>'
        + '<button data-qa="priorities" data-cid="' + (c.client_user_id || '') + '" style="' + S.btnBase + 'background:#f59e0b;padding:5px 12px;font-size:11px;">Adjust Priorities</button>'
        + '<button data-qa="regenerate" data-cid="' + (c.client_user_id || '') + '" style="' + S.btnBase + 'background:#10b981;padding:5px 12px;font-size:11px;">Regenerate Flight Plan</button>'
        + '</div>'
        + '<div id="hdlv2-qa-area-' + (c.client_user_id || '') + '"></div>'
        + '</div></td></tr>';
    });
    html += '</tbody></table>';
    list.innerHTML = html;

    // Bind release buttons
    list.querySelectorAll('[data-release-id]').forEach(function (btn) {
      btn.addEventListener('click', function (e) { e.stopPropagation(); releaseWhy(btn, btn.getAttribute('data-release-id')); });
    });

    // Bind row expand/collapse
    list.querySelectorAll('[data-client-expand]').forEach(function (row) {
      row.addEventListener('click', function () {
        var cid = row.getAttribute('data-client-expand');
        if (!cid) return;
        var expandRow = document.getElementById('hdlv2-expand-' + cid);
        if (expandRow) expandRow.style.display = expandRow.style.display === 'none' ? '' : 'none';
      });
    });

    // Bind quick action buttons
    list.querySelectorAll('[data-qa]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var action = btn.getAttribute('data-qa');
        var cid = btn.getAttribute('data-cid');
        handleQuickAction(action, cid, btn);
      });
    });
  }

  // 5A: Status badge renderer
  function statusBadgeHtml(st) {
    if (!st || !st.status) return '<span style="font-size:11px;color:#ccc;">\u2014</span>';
    var color = st.color || '#94a3b8';
    var label = st.label || st.status;
    return '<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;background:' + color + '22;color:' + color + ';font-size:11px;font-weight:500;white-space:nowrap;">'
      + '<span style="width:5px;height:5px;border-radius:50%;background:' + color + ';"></span>' + escAttr(label) + '</span>';
  }

  // 5B: Quick action handler
  function handleQuickAction(action, clientId, btnEl) {
    var area = document.getElementById('hdlv2-qa-area-' + clientId);
    if (!area) return;
    var restBase = (CFG.rest_base || '/wp-json/');

    if (action === 'note') {
      area.innerHTML = '<textarea id="hdlv2-qa-note-' + clientId + '" placeholder="Add a note about this client..." style="' + S.inputBase + 'min-height:60px;resize:vertical;margin-bottom:6px;max-width:100%;"></textarea>'
        + '<button id="hdlv2-qa-note-save-' + clientId + '" style="' + S.btnBase + 'background:' + S.teal + ';padding:5px 12px;font-size:11px;">Save Note</button>';
      document.getElementById('hdlv2-qa-note-save-' + clientId).addEventListener('click', function () {
        var text = document.getElementById('hdlv2-qa-note-' + clientId).value.trim();
        if (!text) return;
        this.disabled = true; this.textContent = 'Saving...';
        fetch(restBase + 'hdl-v2/v1/timeline/add-note', {
          method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
          body: JSON.stringify({ client_id: parseInt(clientId), summary: text })
        }).then(function (r) { return r.json(); }).then(function (res) {
          area.innerHTML = res.success ? '<span style="color:' + S.green + ';font-size:11px;">Note saved.</span>' : '<span style="color:' + S.red + ';font-size:11px;">Error saving.</span>';
        }).catch(function () { area.innerHTML = '<span style="color:' + S.red + ';font-size:11px;">Network error.</span>'; });
      });
    } else if (action === 'priorities') {
      area.innerHTML = '<textarea id="hdlv2-qa-pri-' + clientId + '" placeholder="e.g. Focus on sleep this week, reduce movement targets..." style="' + S.inputBase + 'min-height:60px;resize:vertical;margin-bottom:6px;max-width:100%;"></textarea>'
        + '<button id="hdlv2-qa-pri-save-' + clientId + '" style="' + S.btnBase + 'background:#f59e0b;padding:5px 12px;font-size:11px;">Save Priorities</button>'
        + '<div style="font-size:10px;color:#888;margin-top:4px;">These notes will be the highest priority input in the next Flight Plan.</div>';
      document.getElementById('hdlv2-qa-pri-save-' + clientId).addEventListener('click', function () {
        var text = document.getElementById('hdlv2-qa-pri-' + clientId).value.trim();
        if (!text) return;
        this.disabled = true; this.textContent = 'Saving...';
        fetch(restBase + 'hdl-v2/v1/flight-plan/' + clientId + '/priorities', {
          method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
          body: JSON.stringify({ notes: text })
        }).then(function (r) { return r.json(); }).then(function (res) {
          area.innerHTML = res.success ? '<span style="color:' + S.green + ';font-size:11px;">Priorities saved \u2014 will shape the next Flight Plan.</span>' : '<span style="color:' + S.red + ';font-size:11px;">Error saving.</span>';
        }).catch(function () { area.innerHTML = '<span style="color:' + S.red + ';font-size:11px;">Network error.</span>'; });
      });
    } else if (action === 'regenerate') {
      btnEl.disabled = true; btnEl.textContent = 'Generating...';
      // v0.46.x — the manual generate now runs async on the job queue (so a
      // ~1-4min Claude call never holds a PHP worker). We get a job_id back and
      // poll /jobs/{id}/status. A degraded server still returns plan_id inline.
      fetch(restBase + 'hdl-v2/v1/flight-plan/' + clientId + '/generate', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
        body: '{}'
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (res && res.success && res.queued && res.job_id) {
          pollFlightPlanJob(btnEl, area, res.job_id, Date.now());
        } else if (res && res.success && res.plan_id) {
          btnEl.textContent = 'Generated!'; btnEl.style.background = S.green;
          area.innerHTML = '<span style="color:' + S.green + ';font-size:11px;">Flight Plan regenerated (ID: ' + res.plan_id + ').</span>';
        } else {
          btnEl.disabled = false; btnEl.textContent = 'Regenerate Flight Plan'; btnEl.style.background = '#10b981';
          area.innerHTML = '<span style="color:' + S.red + ';font-size:11px;">' + escAttr((res && res.message) || 'Generation failed.') + '</span>';
        }
      }).catch(function () { btnEl.disabled = false; btnEl.textContent = 'Regenerate Flight Plan'; });
    }
  }

  // v0.46.x — poll the async manual-flight-plan job to completion, then confirm.
  // Self-terminating recursive setTimeout (no leaked interval); stops if the
  // dashboard re-rendered the button away (isConnected guard).
  function pollFlightPlanJob(btnEl, area, jobId, startedAt) {
    if (!btnEl || !btnEl.isConnected) return;
    if (Date.now() - startedAt > 5 * 60 * 1000) {
      btnEl.disabled = false; btnEl.textContent = 'Regenerate Flight Plan'; btnEl.style.background = '#10b981';
      area.innerHTML = '<span style="color:' + S.red + ';font-size:11px;">Taking longer than expected — try again in a moment.</span>';
      return;
    }
    fetch(restBase + 'hdl-v2/v1/jobs/' + jobId + '/status?_=' + Date.now(), {
      headers: { 'X-WP-Nonce': CFG.nonce }, cache: 'no-store'
    }).then(function (r) { return r.json(); }).then(function (job) {
      if (!btnEl || !btnEl.isConnected) return;
      if (!job || !job.status || job.status === 'pending' || job.status === 'running') {
        setTimeout(function () { pollFlightPlanJob(btnEl, area, jobId, startedAt); }, 3000);
        return;
      }
      if (job.status === 'completed') {
        var pid = (job.result && job.result.plan_id) ? parseInt(job.result.plan_id, 10) : 0;
        btnEl.textContent = 'Generated!'; btnEl.style.background = S.green;
        area.innerHTML = '<span style="color:' + S.green + ';font-size:11px;">Flight Plan regenerated' + (pid ? ' (ID: ' + pid + ')' : '') + '.</span>';
        return;
      }
      // failed / cancelled
      btnEl.disabled = false; btnEl.textContent = 'Regenerate Flight Plan'; btnEl.style.background = '#10b981';
      area.innerHTML = '<span style="color:' + S.red + ';font-size:11px;">Generation failed. Please try again.</span>';
    }).catch(function () {
      setTimeout(function () { pollFlightPlanJob(btnEl, area, jobId, startedAt); }, 3000);
    });
  }

  function releaseWhy(btnEl, progressId) {
    btnEl.disabled = true; btnEl.textContent = 'Releasing...';
    var data = new FormData();
    data.append('action', 'hdlv2_release_why');
    data.append('nonce', CFG.nonce);
    data.append('progress_id', progressId);

    fetch(CFG.ajax_url, { method: 'POST', body: data })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) {
          btnEl.textContent = 'Released!'; btnEl.style.background = S.green;
          setTimeout(function () { loadV2Clients(); }, 1000);
        } else {
          btnEl.disabled = false; btnEl.textContent = 'Failed'; btnEl.style.background = S.red;
          setTimeout(function () { btnEl.textContent = 'Invite to Stage 3'; btnEl.style.background = S.teal; }, 2000);
        }
      })
      .catch(function () { btnEl.disabled = false; btnEl.textContent = 'Error'; btnEl.style.background = S.red; });
  }

  // ──────────────────────────────────────────────────────────────
  //  HELPERS
  // ──────────────────────────────────────────────────────────────

  function formField(name, label, type, value, opts) {
    opts = opts || {};
    var requiredAttr = opts.required ? ' required aria-required="true"' : '';
    return '<div style="margin-bottom:10px;">'
      + '<label style="display:block;font-size:12px;color:#555;margin-bottom:3px;font-weight:500;">' + label + '</label>'
      + '<input id="hdlv2-' + name + '" type="' + type + '"' + requiredAttr + ' value="' + escAttr(value) + '" style="' + S.inputBase + '">'
      + '</div>';
  }

  function escAttr(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML.replace(/"/g, '&quot;'); }

  function formatDate(dateStr) {
    if (!dateStr) return '';
    var d = new Date(dateStr.replace(' ', 'T') + 'Z');
    if (isNaN(d.getTime())) return dateStr;
    var m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return m[d.getMonth()] + ' ' + d.getDate();
  }

  // ──────────────────────────────────────────────────────────────
  //  EXPIRY HELPERS — Phase 18B (link-created box) + 18C (Sent list)
  // ──────────────────────────────────────────────────────────────

  // Server returns "YYYY-MM-DD HH:MM:SS" in GMT. Parse to a JS Date in UTC.
  function parseUtcDate(s) {
    if (!s) return null;
    var d = new Date(String(s).replace(' ', 'T') + 'Z');
    return isNaN(d.getTime()) ? null : d;
  }

  function isExpired(expiresAt) {
    var d = parseUtcDate(expiresAt);
    return d ? d.getTime() < Date.now() : false;
  }

  // True if the row should render as "expired" — either the server says so or
  // the deadline has passed but the cron hasn't flipped the status yet.
  function isEffectivelyExpired(inv) {
    if (!inv) return false;
    if (inv.status === 'expired' || inv.status === 'revoked') return true;
    return isExpired(inv.expires_at);
  }

  // "in 6 days", "in 2h 14m", "in 45 minutes", "in 32 seconds"
  function formatExpiryRelative(expiresAt) {
    var d = parseUtcDate(expiresAt);
    if (!d) return '';
    var msLeft = d.getTime() - Date.now();
    if (msLeft <= 0) return 'expired';
    var sec = Math.floor(msLeft / 1000);
    var min = Math.floor(sec / 60);
    var hrs = Math.floor(min / 60);
    var days = Math.floor(hrs / 24);
    if (days >= 2) return 'in ' + days + ' days';
    if (hrs >= 24) return 'in 1 day';
    if (hrs >= 2) {
      var rm = min - hrs * 60;
      return rm > 0 ? 'in ' + hrs + 'h ' + rm + 'm' : 'in ' + hrs + ' hours';
    }
    if (hrs === 1) {
      var rm1 = min - 60;
      return rm1 > 0 ? 'in 1h ' + rm1 + 'm' : 'in 1 hour';
    }
    if (min >= 1) return 'in ' + min + ' minute' + (min === 1 ? '' : 's');
    return 'in ' + sec + ' second' + (sec === 1 ? '' : 's');
  }

  // "Mon 4 May 2026, 15:00" in the practitioner's local timezone.
  function formatExpiryAbsolute(expiresAt) {
    var d = parseUtcDate(expiresAt);
    if (!d) return '';
    var dayNames   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    var monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var hh = ('0' + d.getHours()).slice(-2);
    var mm = ('0' + d.getMinutes()).slice(-2);
    return dayNames[d.getDay()] + ' ' + d.getDate() + ' ' + monthNames[d.getMonth()] + ' ' + d.getFullYear() + ', ' + hh + ':' + mm;
  }

  // Apply or remove the expired visual state on the link-created box (Send tab).
  function setLinkBoxExpiredState(expired) {
    var box   = document.getElementById('hdlv2-invite-link-box');
    var input = document.getElementById('hdlv2-invite-link-url');
    var copy  = document.getElementById('hdlv2-invite-link-copy');
    var label = document.getElementById('hdlv2-invite-link-label');
    var meta  = document.getElementById('hdlv2-invite-link-meta');
    if (!box || !input || !copy) return;
    if (expired) {
      box.style.background    = '#f3f4f6';
      box.style.borderColor   = '#d1d5db';
      input.style.textDecoration = 'line-through';
      input.style.color       = '#9ca3af';
      copy.disabled           = true;
      copy.style.opacity      = '0.5';
      copy.style.cursor       = 'not-allowed';
      if (label) label.textContent = 'Assessment link expired:';
      if (meta) meta.innerHTML = '<span style="color:#dc2626;font-weight:500;">This link has expired.</span> Generate a new one to continue.';
    } else {
      box.style.background    = '#eef7f9';
      box.style.borderColor   = '#d0e8ed';
      input.style.textDecoration = '';
      input.style.color       = '';
      copy.disabled           = false;
      copy.style.opacity      = '';
      copy.style.cursor       = 'pointer';
      if (label) label.textContent = 'Assessment link created:';
      // Note: meta innerHTML is rebuilt by the countdown tick; don't clobber here.
    }
  }

  // Live countdown — updates the link-box meta line every second when remaining
  // is < 24h. For longer windows we render an absolute timestamp once and stop
  // the timer (the visible label won't change at 1Hz scale anyway).
  var expiryCountdownTimer = null;
  function startLinkBoxCountdown(expiresAt) {
    stopLinkBoxCountdown();
    if (!expiresAt) return;
    var d = parseUtcDate(expiresAt);
    if (!d) return;

    function tick() {
      var msLeft = d.getTime() - Date.now();
      var meta = document.getElementById('hdlv2-invite-link-meta');
      if (msLeft <= 0) {
        setLinkBoxExpiredState(true);
        stopLinkBoxCountdown();
        return;
      }
      // Re-build the meta paragraph each tick so we always have the expiry span
      // + the "Stops working after one use" tail (in case setLinkBoxExpiredState
      // overwrote it during a previous expired transition).
      if (meta) {
        var line = msLeft > 24 * 60 * 60 * 1000
          ? 'This link expires on ' + formatExpiryAbsolute(expiresAt) + '.'
          : 'This link expires ' + formatExpiryRelative(expiresAt) + '.';
        meta.innerHTML = '<span id="hdlv2-invite-link-expiry">' + escAttr(line) + '</span>'
          + '<br><span style="color:#888;">Stops working after the client uses it once.</span>';
      }
      // > 24h: redraw is wasted — the label won't visibly change at 1Hz. Stop.
      if (msLeft > 24 * 60 * 60 * 1000) stopLinkBoxCountdown();
    }

    tick();
    if (d.getTime() - Date.now() <= 24 * 60 * 60 * 1000) {
      expiryCountdownTimer = setInterval(tick, 1000);
    }
  }

  function stopLinkBoxCountdown() {
    if (expiryCountdownTimer) { clearInterval(expiryCountdownTimer); expiryCountdownTimer = null; }
  }

  function copyToClipboard(inputId, btnId) {
    var el = document.getElementById(inputId);
    el.select(); el.setSelectionRange(0, 99999);
    document.execCommand('copy');
    var btn = document.getElementById(btnId);
    var orig = btn.textContent;
    btn.textContent = 'Copied!';
    setTimeout(function () { btn.textContent = orig; }, 2000);
  }

  function copyText(text) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.cssText = 'position:fixed;left:-9999px;';
    document.body.appendChild(ta); ta.select(); document.execCommand('copy');
    document.body.removeChild(ta);
  }
})();
