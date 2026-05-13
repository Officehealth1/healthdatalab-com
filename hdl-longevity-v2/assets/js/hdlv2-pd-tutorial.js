/**
 * HDL V2 Practitioner Dashboard — Tutorial Section
 *
 * Always-visible getting-started block beneath the live client list.
 * Wires:
 *   - "Get embed code" → expands the Customize collapsible + scrolls to embed preview
 *   - "Invite a client" → triggers V1's existing Client Tools popup (V2's
 *     hdlv2-dashboard.js has already injected 3 tabs: Sent Invites,
 *     Widget Settings, Embed Code; defaults to Sent Invites). Single
 *     source of truth — same UX active practitioners get from clicking
 *     Client Tools.
 *   - Customize widget Save → POST /widget/config (same as the popup tab)
 *   - Customize widget Preview → regenerate embed-code block locally
 *
 * v0.22.25 — replaced custom invite modal with V1-popup-trigger forwarder.
 *            The custom modal had a CSS rendering bug where the Send button
 *            was hidden by Divi/global styles; rather than fight CSS we now
 *            reuse the battle-tested V1 popup.
 *
 * @package HDL_Longevity_V2
 * @since   0.22.24
 */
(function () {
  'use strict';

  var CFG = window.hdlv2_pdt || {};
  if (!CFG.api_base) return;

  // ── Defensive hides for legacy V1 chrome ──
  // V1's PHP now suppresses these elements server-side when has_clients=0
  // and V2 is active (no FOUC). The JS hides remain as a belt-and-braces
  // safety net for cached pages or partial deployments.
  var v1Empty = document.querySelector('.practitioner-empty-state');
  if (v1Empty) v1Empty.style.display = 'none';

  if (document.querySelector('.hdlv2-pdt-hero')) {
    var v1Hdr = document.querySelector('.dashboard-header');
    if (v1Hdr) v1Hdr.style.display = 'none';
  }

  // ── Get embed code → expand details + scroll to preview ──
  bindAll('[data-hdlv2-pdt-action="get-embed"]', function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var details = document.getElementById('hdlv2-pdt-config');
      if (!details) return;
      details.open = true;
      var preview = document.getElementById('hdlv2-pdt-embed-preview');
      if (preview) {
        setTimeout(function () {
          preview.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 80);
      }
    });
  });

  // ── Invite a client → trigger V1's Client Tools popup ──
  // For new practitioners, V1's PHP renders #invite-client-btn offscreen
  // (so V1's own click handler still wires up to the popup). V2's
  // hdlv2-dashboard.js polls for #invite-client-popup, injects 3 tabs,
  // and defaults to "Sent Invites" — exactly the UX you want.
  bindAll('[data-hdlv2-pdt-action="invite-open"]', function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var v1Btn = document.getElementById('invite-client-btn');
      if (!v1Btn) {
        // Defensive: if V1 hasn't rendered the trigger yet (shouldn't
        // happen post-deploy), surface a clear console warning rather
        // than silently failing.
        if (window.console) console.warn('[HDLV2 tutorial] #invite-client-btn not found — Client Tools popup unreachable.');
        return;
      }
      v1Btn.click();
    });
  });

  // ── Color picker 2-way sync ──
  var colorInput = document.getElementById('hdlv2-pdt-color');
  var colorHex   = document.getElementById('hdlv2-pdt-color-hex');
  if (colorInput && colorHex) {
    colorInput.addEventListener('input', function () { colorHex.value = colorInput.value; });
    colorHex.addEventListener('input', function () {
      var v = colorHex.value.trim();
      if (/^#[0-9a-fA-F]{6}$/.test(v)) colorInput.value = v;
    });
  }

  // ── Preview button → regenerate embed code locally with current values ──
  bindAll('[data-hdlv2-pdt-action="preview"]', function (btn) {
    btn.addEventListener('click', function () { regenerateEmbedPreview(); });
  });

  // ── Save config button → POST to /widget/config ──
  bindAll('[data-hdlv2-pdt-action="save-config"]', function (btn) {
    btn.addEventListener('click', function () {
      var status = document.getElementById('hdlv2-pdt-save-status');
      var data   = readConfigForm();

      if (!data.cta_link) {
        if (status) {
          status.textContent = 'Booking link is required.';
          status.style.color = '#dc2626';
          status.hidden = false;
        }
        var ctaEl = document.getElementById('hdlv2-pdt-cta-link');
        if (ctaEl) ctaEl.style.borderColor = '#dc2626';
        return;
      }
      var ctaReset = document.getElementById('hdlv2-pdt-cta-link');
      if (ctaReset) ctaReset.style.borderColor = '';

      btn.disabled = true;
      var origLabel = btn.textContent;
      btn.textContent = 'Saving…';
      if (status) { status.textContent = ''; status.hidden = true; }

      fetch(CFG.api_base + '/widget/config', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': CFG.rest_nonce
        },
        body: JSON.stringify(data)
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (res) {
          btn.disabled = false;
          btn.textContent = origLabel;
          if (!res.ok || (res.body && res.body.code)) {
            if (status) {
              status.textContent = (res.body && res.body.message) || 'Could not save. Please try again.';
              status.style.color = '#dc2626';
              status.hidden = false;
            }
            return;
          }
          if (status) {
            status.textContent = 'Saved ✓';
            status.style.color = '#10b981';
            status.hidden = false;
          }
          if (res.body.embed_code) {
            var preview = document.getElementById('hdlv2-pdt-embed-preview');
            if (preview) preview.textContent = res.body.embed_code;
          }
          setTimeout(function () { if (status) status.hidden = true; }, 2500);
        })
        .catch(function () {
          btn.disabled = false;
          btn.textContent = origLabel;
          if (status) {
            status.textContent = 'Network error. Please try again.';
            status.style.color = '#dc2626';
            status.hidden = false;
          }
        });
    });
  });

  function readConfigForm() {
    return {
      logo_url:    val('hdlv2-pdt-logo'),
      theme_color: val('hdlv2-pdt-color-hex') || '#3d8da0',
      cta_text:    val('hdlv2-pdt-cta-text') || 'Book a session',
      cta_link:    val('hdlv2-pdt-cta-link')
    };
  }

  function val(id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; }

  function regenerateEmbedPreview() {
    var data    = readConfigForm();
    var preview = document.getElementById('hdlv2-pdt-embed-preview');
    if (!preview) return;
    var pid = CFG.practitioner_id || '';
    var src = CFG.widget_js_url || '';
    var lines = [
      '<div id="hdl-widget-' + pid + '" data-practitioner-id="' + pid + '"',
      '     data-logo="'  + escAttr(data.logo_url)    + '"',
      '     data-color="' + escAttr(data.theme_color) + '"></div>',
      '<script src="' + escAttr(src) + '" defer></' + 'script>'
    ];
    preview.textContent = lines.join('\n');
  }

  function escAttr(s) { return String(s == null ? '' : s).replace(/"/g, '&quot;'); }

  function bindAll(selector, fn) {
    var nodes = document.querySelectorAll(selector);
    for (var i = 0; i < nodes.length; i++) fn(nodes[i]);
  }
})();
