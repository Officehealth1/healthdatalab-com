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

  var S = {
    teal: '#3d8da0',
    green: '#27ae60',
    red: '#e74c3c',
    amber: '#f59e0b',
    grey: '#999',
    btnBase: 'padding:8px 16px;color:#fff;border:none;border-radius:6px;font-size:13px;cursor:pointer;font-weight:500;',
    inputBase: 'width:100%;max-width:400px;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;box-sizing:border-box;'
  };

  var TAB_IDS = ['client-invite', 'widget-config', 'widget-embed', 'widget-invites'];
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

    // 3. Wrap V1 form in a tab panel div
    var v1Panel = document.createElement('div');
    v1Panel.id = 'hdlv2-popup-tab-client-invite';
    form.parentNode.insertBefore(v1Panel, form);
    v1Panel.appendChild(form);

    // 4. Create tab bar
    var tabBar = document.createElement('div');
    tabBar.id = 'hdlv2-popup-tabs';
    tabBar.style.cssText = 'display:flex;flex-wrap:wrap;gap:0;border-bottom:2px solid #eee;margin:0 0 16px;';
    tabBar.innerHTML = popupTabBtn('client-invite', 'Invite Client', true)
      + popupTabBtn('widget-config', 'Widget Settings', false)
      + popupTabBtn('widget-embed', 'Embed Code', false)
      + popupTabBtn('widget-invites', 'Assessment Links', false);

    // Insert tab bar between header and V1 form panel
    header.after(tabBar);

    // 5. Create V2 tab panels
    var configPanel = createPanel('widget-config', configTabContent());
    var embedPanel = createPanel('widget-embed', embedTabContent());
    var invitesPanel = createPanel('widget-invites', invitesTabContent());

    v1Panel.after(configPanel);
    configPanel.after(embedPanel);
    embedPanel.after(invitesPanel);

    // 6. Widen the popup for config form
    popup.style.maxWidth = '620px';
    popup.style.width = '95vw';

    // 7. Tab switching
    tabBar.querySelectorAll('[data-hdlv2-ptab]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        switchPopupTab(btn.getAttribute('data-hdlv2-ptab'));
      });
    });

    // 8. Bind V2 actions
    document.getElementById('hdlv2-save-config').addEventListener('click', saveConfig);
    document.getElementById('hdlv2-copy-embed').addEventListener('click', copyEmbed);
    document.getElementById('hdlv2-create-invite').addEventListener('click', createInvite);

    // 9. Populate embed code on load
    if (CFG.embed_code) {
      document.getElementById('hdlv2-embed-code').value = CFG.embed_code;
    }

    // 10. Load initial preview + live-update on changes
    updatePreview();

    // Live-update: re-render preview on any config field change
    var liveFields = ['hdlv2-practitioner_name', 'hdlv2-cta_text', 'hdlv2-cta_link', 'hdlv2-theme_color', 'hdlv2-logo_url'];
    var liveTimer = null;
    liveFields.forEach(function (fid) {
      var el = document.getElementById(fid);
      if (!el) return;
      el.addEventListener('input', function () {
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
      if (file.size > 2 * 1024 * 1024) { alert('Logo must be under 2MB.'); fileInput.value = ''; return; }

      if (logoLabel) logoLabel.textContent = 'Uploading...';
      if (dropzone) dropzone.style.pointerEvents = 'none';

      var fd = new FormData();
      fd.append('action', 'hdlv2_upload_logo');
      fd.append('nonce', CFG.nonce);
      fd.append('logo', file);

      console.log('Logo upload: posting to', CFG.ajax_url, 'with nonce', CFG.nonce);
      fetch(CFG.ajax_url, { method: 'POST', body: fd })
        .then(function (r) {
          console.log('Logo upload: response status', r.status);
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.text();
        })
        .then(function (text) {
          console.log('Logo upload: raw response', text);
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
          console.error('Logo upload error:', err);
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
      });
    });

    // 13. Fallback: close on overlay background click
    if (overlay) {
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) overlay.style.display = 'none';
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
    var style = 'padding:8px 14px;font-size:13px;font-weight:' + (active ? '600' : '400')
      + ';cursor:pointer;border:none;background:none;'
      + 'border-bottom:2px solid ' + (active ? S.teal : 'transparent')
      + ';color:' + (active ? S.teal : '#888')
      + ';margin-bottom:-2px;transition:all 0.2s;font-family:inherit;';
    return '<button data-hdlv2-ptab="' + id + '" style="' + style + '">' + label + '</button>';
  }

  function createPanel(id, html) {
    var div = document.createElement('div');
    div.id = 'hdlv2-popup-tab-' + id;
    div.style.display = 'none';
    div.innerHTML = html;
    return div;
  }

  function switchPopupTab(activeId) {
    TAB_IDS.forEach(function (id) {
      var panel = document.getElementById('hdlv2-popup-tab-' + id);
      var btn = document.querySelector('[data-hdlv2-ptab="' + id + '"]');
      if (!panel || !btn) return;
      if (id === activeId) {
        panel.style.display = 'block';
        btn.style.borderBottomColor = S.teal;
        btn.style.color = S.teal;
        btn.style.fontWeight = '600';
      } else {
        panel.style.display = 'none';
        btn.style.borderBottomColor = 'transparent';
        btn.style.color = '#888';
        btn.style.fontWeight = '400';
      }
    });
    if (activeId === 'widget-invites') { loadInvites(); loadV2Clients(); }
  }

  // ──────────────────────────────────────────────────────────────
  //  TAB CONTENT: WIDGET CONFIG
  // ──────────────────────────────────────────────────────────────

  function configTabContent() {
    var c = CFG.config || {};
    return '<div style="padding:4px 0;">'
      + '<p style="font-size:12px;color:#888;margin:0 0 14px;">Customise the rate-of-ageing widget you embed on your website. Changes update the preview below.</p>'
      + formField('practitioner_name', 'Your Name', 'text', c.practitioner_name || '')
      // Logo upload area — uses <label> wrapping file input for reliable native click
      + '<div style="margin-bottom:10px;">'
      + '<span style="display:block;font-size:12px;color:#555;margin-bottom:3px;font-weight:500;">Your Logo</span>'
      + '<div id="hdlv2-logo-dropzone" style="display:block;border:2px dashed #ddd;border-radius:10px;padding:16px;text-align:center;cursor:pointer;background:#fafbfc;transition:border-color 0.2s;position:relative;">'
      + '<div id="hdlv2-logo-preview" style="margin:0 auto 8px;width:60px;height:60px;border-radius:8px;background:#f0f1f3;display:flex;align-items:center;justify-content:center;overflow:hidden;">'
      + (c.logo_url ? '<img src="' + escAttr(c.logo_url) + '" style="max-width:100%;max-height:100%;object-fit:contain;">' : '<span style="font-size:24px;color:#ccc;">+</span>')
      + '</div>'
      + '<div id="hdlv2-logo-label" style="font-size:12px;color:#888;">' + (c.logo_url ? 'Click to change' : 'Click to upload your logo') + '</div>'
      + '<input type="file" id="hdlv2-logo-file" accept="image/jpeg,image/png,image/gif,image/svg+xml,image/webp" style="position:absolute;width:1px;height:1px;overflow:hidden;opacity:0;top:0;left:0;">'
      + '</div>'
      + '<input id="hdlv2-logo_url" type="hidden" value="' + escAttr(c.logo_url || '') + '">'
      + '</div>'
      + formField('cta_text', 'Button Text', 'text', c.cta_text || 'Book a session')
      + formField('cta_link', 'Booking Link <span style="color:#e74c3c;">*</span>', 'url', c.cta_link || '')
      + formField('notification_email', 'Send new leads to this email', 'email', c.notification_email || '')
      + formField('theme_color', 'Widget Colour', 'color', c.theme_color || '#3d8da0')
      + '<details style="margin-bottom:12px;"><summary style="font-size:12px;color:#888;cursor:pointer;">Advanced</summary>'
      + '<div style="margin-top:8px;">'
      + formField('webhook_url', 'Webhook URL (sends lead data to external tools)', 'url', c.webhook_url || '')
      + '</div></details>'
      + '<button id="hdlv2-save-config" style="padding:10px 24px;background:' + S.teal + ';color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;margin-top:8px;font-family:inherit;">Save Settings</button>'
      + '<span id="hdlv2-save-status" style="margin-left:12px;font-size:13px;color:' + S.green + ';display:none;"></span>'
      // Live preview below settings
      + '<div style="margin-top:20px;padding-top:16px;border-top:1px solid #eee;">'
      + '<h4 style="margin:0 0 10px;font-size:13px;font-weight:600;color:#555;">Live Preview</h4>'
      + '<div id="hdlv2-widget-preview" style="max-width:420px;transform:scale(0.85);transform-origin:top left;"></div>'
      + '</div>'
      + '</div>';
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

  function invitesTabContent() {
    return '<div style="padding:4px 0;">'
      + '<div style="margin-bottom:20px;padding:16px;background:#f8f9fb;border-radius:8px;border:1px solid #eee;">'
      + '<h4 style="margin:0 0 10px;font-size:14px;font-weight:600;color:#1a1a1a;">Send Personal Assessment Link</h4>'
      + '<p style="font-size:12px;color:#888;margin:0 0 12px;">Create a link for a specific client. Their email will be pre-filled and locked so only they can use it.</p>'
      + formField('invite_name', 'Client Name', 'text', '')
      + formField('invite_email', 'Client Email', 'email', '')
      + '<div style="margin-bottom:12px;">'
      + '<label style="display:block;font-size:13px;color:#555;margin-bottom:4px;font-weight:500;">Expires in</label>'
      + '<select id="hdlv2-invite_expires" style="' + S.inputBase + 'max-width:180px;">'
      + '<option value="7">7 days</option>'
      + '<option value="14">14 days</option>'
      + '<option value="30" selected>30 days</option>'
      + '</select></div>'
      + '<button id="hdlv2-create-invite" style="' + S.btnBase + 'background:' + S.teal + ';padding:10px 20px;font-size:13px;font-weight:600;font-family:inherit;">Generate Link</button>'
      + '<span id="hdlv2-invite-status" style="margin-left:10px;font-size:13px;display:none;"></span>'
      + '</div>'
      // Generated link box
      + '<div id="hdlv2-invite-link-box" style="display:none;margin-bottom:16px;padding:12px;background:#eef7f9;border-radius:8px;border:1px solid #d0e8ed;">'
      + '<p style="font-size:12px;color:#555;margin:0 0 6px;font-weight:500;">Assessment link created:</p>'
      + '<div style="display:flex;gap:6px;align-items:center;">'
      + '<input id="hdlv2-invite-link-url" type="text" readonly style="flex:1;padding:6px 8px;border:1px solid #ccc;border-radius:6px;font-size:11px;font-family:monospace;background:#fff;">'
      + '<button id="hdlv2-invite-link-copy" style="' + S.btnBase + 'background:' + S.green + ';white-space:nowrap;padding:6px 12px;font-size:12px;font-family:inherit;">Copy</button>'
      + '</div></div>'
      // Invite list
      + '<h4 style="margin:0 0 10px;font-size:14px;font-weight:600;">Sent Invites</h4>'
      + '<div id="hdlv2-invite-list" style="font-size:13px;color:#888;max-height:200px;overflow-y:auto;">Loading...</div>'
      // V2 Client progress
      + '<div style="border-top:1px solid #eee;margin-top:16px;padding-top:16px;">'
      + '<h4 style="margin:0 0 10px;font-size:14px;font-weight:600;">Client Progress</h4>'
      + '<div id="hdlv2-client-list" style="font-size:13px;color:#888;max-height:300px;overflow-y:auto;">Loading...</div>'
      + '</div>'
      + '</div>';
  }

  // ──────────────────────────────────────────────────────────────
  //  CONFIG ACTIONS
  // ──────────────────────────────────────────────────────────────

  function getFormValues() {
    return {
      practitioner_name: val('hdlv2-practitioner_name'),
      logo_url: val('hdlv2-logo_url'),
      cta_text: val('hdlv2-cta_text') || ('Book a session with ' + (val('hdlv2-practitioner_name') || 'a practitioner')),
      cta_link: val('hdlv2-cta_link'),
      webhook_url: val('hdlv2-webhook_url'),
      notification_email: val('hdlv2-notification_email'),
      theme_color: val('hdlv2-theme_color') || '#3d8da0'
    };
  }

  function val(id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; }

  function saveConfig() {
    var btn = document.getElementById('hdlv2-save-config');
    var status = document.getElementById('hdlv2-save-status');

    var ctaEl = document.getElementById('hdlv2-cta_link');
    if (!ctaEl || !ctaEl.value.trim()) {
      ctaEl.style.borderColor = S.red;
      status.textContent = 'Booking Link is required'; status.style.color = S.red; status.style.display = 'inline';
      return;
    }
    ctaEl.style.borderColor = '#ddd';

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
    div.setAttribute('data-cta-text', vals.cta_text);
    div.setAttribute('data-cta-link', vals.cta_link || '#');
    div.setAttribute('data-api', '');
    div.setAttribute('data-color', vals.theme_color);
    preview.appendChild(div);
    if (window.hdlRateWidgetInit) {
      window.hdlRateWidgetInit();
    }
  }

  // ──────────────────────────────────────────────────────────────
  //  INVITE ACTIONS
  // ──────────────────────────────────────────────────────────────

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
    data.append('expires_days', document.getElementById('hdlv2-invite_expires').value);

    fetch(CFG.ajax_url, { method: 'POST', body: data })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        btn.disabled = false; btn.textContent = 'Generate Link';
        if (res.success) {
          var linkBox = document.getElementById('hdlv2-invite-link-box');
          var linkUrl = document.getElementById('hdlv2-invite-link-url');
          linkBox.style.display = 'block';
          linkUrl.value = res.data.url;
          document.getElementById('hdlv2-invite-link-copy').onclick = function () { copyToClipboard('hdlv2-invite-link-url', 'hdlv2-invite-link-copy'); };
          nameEl.value = ''; emailEl.value = '';
          status.textContent = res.data.email_sent ? 'Link created and email sent!' : 'Link created! (Email could not be sent)'; status.style.color = S.green; status.style.display = 'inline';
          setTimeout(function () { status.style.display = 'none'; }, 3000);
          loadInvites();
        } else {
          status.textContent = res.data || 'Failed'; status.style.color = S.red; status.style.display = 'inline';
        }
      })
      .catch(function () { btn.disabled = false; btn.textContent = 'Generate Link'; status.textContent = 'Network error'; status.style.color = S.red; status.style.display = 'inline'; });
  }

  function loadInvites() {
    var list = document.getElementById('hdlv2-invite-list');
    if (!list) return;
    var data = new FormData();
    data.append('action', 'hdlv2_get_invites');
    data.append('nonce', CFG.nonce);

    fetch(CFG.ajax_url, { method: 'POST', body: data })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.success || !res.data || res.data.length === 0) {
          list.innerHTML = '<p style="color:#aaa;font-size:12px;">No invites sent yet.</p>';
          return;
        }
        renderInviteList(res.data);
      })
      .catch(function () { list.innerHTML = '<p style="color:' + S.red + ';font-size:12px;">Failed to load.</p>'; });
  }

  function renderInviteList(invites) {
    var list = document.getElementById('hdlv2-invite-list');
    var html = '<table style="width:100%;border-collapse:collapse;font-size:12px;">'
      + '<thead><tr style="border-bottom:2px solid #eee;text-align:left;">'
      + '<th style="padding:6px 6px 6px 0;font-weight:600;color:#555;">Client</th>'
      + '<th style="padding:6px;font-weight:600;color:#555;">Status</th>'
      + '<th style="padding:6px;font-weight:600;color:#555;">Date</th>'
      + '<th style="padding:6px 0 6px 6px;font-weight:600;color:#555;">Action</th>'
      + '</tr></thead><tbody>';

    invites.forEach(function (inv) {
      var badge = inviteStatusBadge(inv.status);
      var action = '';
      if (inv.status === 'pending' || inv.status === 'opened') {
        action = '<button data-copy-url="' + escAttr(inv.url) + '" style="' + S.btnBase + 'background:' + S.teal + ';padding:4px 10px;font-size:11px;">Copy</button>';
      } else if (inv.status === 'expired') {
        action = '<button data-resend-email="' + escAttr(inv.client_email) + '" data-resend-name="' + escAttr(inv.client_name) + '" style="' + S.btnBase + 'background:' + S.amber + ';padding:4px 10px;font-size:11px;">Resend</button>';
      }
      html += '<tr style="border-bottom:1px solid #f0f0f0;">'
        + '<td style="padding:8px 6px 8px 0;"><div style="font-weight:500;color:#333;font-size:12px;">' + escAttr(inv.client_name || 'No name') + '</div><div style="font-size:11px;color:#999;">' + escAttr(inv.client_email) + '</div></td>'
        + '<td style="padding:8px 6px;">' + badge + '</td>'
        + '<td style="padding:8px 6px;color:#888;font-size:11px;">' + formatDate(inv.created_at) + '</td>'
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
  }

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
    data.append('expires_days', '30');

    fetch(CFG.ajax_url, { method: 'POST', body: data })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) { btnEl.textContent = 'Resent!'; btnEl.style.background = S.green; setTimeout(function () { loadInvites(); }, 1000); }
        else { btnEl.disabled = false; btnEl.textContent = 'Failed'; btnEl.style.background = S.red; setTimeout(function () { btnEl.textContent = 'Resend'; btnEl.style.background = S.amber; }, 2000); }
      })
      .catch(function () { btnEl.disabled = false; btnEl.textContent = 'Error'; btnEl.style.background = S.red; });
  }

  // ──────────────────────────────────────────────────────────────
  //  V2 CLIENT PROGRESS + WHY GATE RELEASE
  // ──────────────────────────────────────────────────────────────

  function loadV2Clients() {
    var list = document.getElementById('hdlv2-client-list');
    if (!list) return;
    var data = new FormData();
    data.append('action', 'hdlv2_get_v2_clients');
    data.append('nonce', CFG.nonce);

    fetch(CFG.ajax_url, { method: 'POST', body: data })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.success || !res.data || res.data.length === 0) {
          list.innerHTML = '<p style="color:#aaa;font-size:12px;">No V2 clients yet.</p>';
          return;
        }
        renderV2Clients(res.data);
      })
      .catch(function () { list.innerHTML = '<p style="color:' + S.red + ';font-size:12px;">Failed to load.</p>'; });
  }

  function renderV2Clients(clients) {
    var list = document.getElementById('hdlv2-client-list');
    var html = '<table style="width:100%;border-collapse:collapse;font-size:12px;">'
      + '<thead><tr style="border-bottom:2px solid #eee;text-align:left;">'
      + '<th style="padding:6px 6px 6px 0;font-weight:600;color:#555;">Client</th>'
      + '<th style="padding:6px;font-weight:600;color:#555;">Stage</th>'
      + '<th style="padding:6px;font-weight:600;color:#555;">WHY</th>'
      + '<th style="padding:6px 0 6px 6px;font-weight:600;color:#555;">Action</th>'
      + '</tr></thead><tbody>';

    clients.forEach(function (c) {
      var stage = 'Stage ' + c.current_stage;
      var stageBg = '#eef4ff'; var stageColor = '#3b82f6';
      if (c.stage3_completed_at) { stage = 'Complete'; stageBg = '#ecfdf5'; stageColor = '#059669'; }

      var whyStatus = '';
      var action = '';
      if (c.why_id) {
        if (c.released == 1) {
          whyStatus = '<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;background:#ecfdf5;color:#059669;font-size:11px;font-weight:500;">'
            + '<span style="width:5px;height:5px;border-radius:50%;background:#10b981;"></span>Released</span>';
        } else {
          whyStatus = '<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;background:#fffbeb;color:#d97706;font-size:11px;font-weight:500;">'
            + '<span style="width:5px;height:5px;border-radius:50%;background:#f59e0b;"></span>Pending</span>';
          action = '<button data-release-id="' + c.id + '" style="' + S.btnBase + 'background:' + S.teal + ';padding:4px 10px;font-size:11px;">Release WHY</button>';
        }
      } else if (c.current_stage >= 2 && !c.stage2_completed_at) {
        whyStatus = '<span style="font-size:11px;color:#aaa;">In progress</span>';
      } else {
        whyStatus = '<span style="font-size:11px;color:#ccc;">\u2014</span>';
      }

      html += '<tr style="border-bottom:1px solid #f0f0f0;">'
        + '<td style="padding:8px 6px 8px 0;"><div style="font-weight:500;color:#333;font-size:12px;">' + escAttr(c.client_name || 'No name') + '</div><div style="font-size:11px;color:#999;">' + escAttr(c.client_email) + '</div></td>'
        + '<td style="padding:8px 6px;"><span style="padding:2px 8px;border-radius:10px;background:' + stageBg + ';color:' + stageColor + ';font-size:11px;font-weight:500;">' + stage + '</span></td>'
        + '<td style="padding:8px 6px;">' + whyStatus + '</td>'
        + '<td style="padding:8px 0 8px 6px;">' + action + '</td></tr>';
    });
    html += '</tbody></table>';
    list.innerHTML = html;

    // Bind release buttons
    list.querySelectorAll('[data-release-id]').forEach(function (btn) {
      btn.addEventListener('click', function () { releaseWhy(btn, btn.getAttribute('data-release-id')); });
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
          setTimeout(function () { btnEl.textContent = 'Release WHY'; btnEl.style.background = S.teal; }, 2000);
        }
      })
      .catch(function () { btnEl.disabled = false; btnEl.textContent = 'Error'; btnEl.style.background = S.red; });
  }

  // ──────────────────────────────────────────────────────────────
  //  HELPERS
  // ──────────────────────────────────────────────────────────────

  function formField(name, label, type, value) {
    return '<div style="margin-bottom:10px;">'
      + '<label style="display:block;font-size:12px;color:#555;margin-bottom:3px;font-weight:500;">' + label + '</label>'
      + '<input id="hdlv2-' + name + '" type="' + type + '" value="' + escAttr(value) + '" style="' + S.inputBase + '">'
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
