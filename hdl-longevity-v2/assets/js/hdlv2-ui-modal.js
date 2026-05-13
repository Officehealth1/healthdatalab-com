/**
 * HDL Longevity V2 — shared UI helpers (v0.20.10)
 *
 *   window.HDLV2UI.confirm({ title, body, confirmLabel, cancelLabel })
 *     → Promise<boolean>    // true = confirmed, false = cancelled
 *
 * Replaces browser-native confirm() in practitioner flows so dialogs match
 * the HDL theme instead of showing "stby.healthdatalab.net says …" (Matthew
 * 2026-04-24: "it's giving a backend vibe").
 *
 * Theme vocabulary shares with hdlv2-rate-limit.js's modal:
 *   - 14px radius white card on rgba(17,17,17,0.45) overlay
 *   - Poppins 19px title (#004F59), Inter 14px body (#555)
 *   - Primary button #3d8da0 → #2c6f80 hover + teal-shadow lift
 *   - Ghost cancel button (rgba ink-alpha border, teal wash on hover) —
 *     same vocabulary as .hdlv2-nav-back and .hdlv2-s1-sex-btn
 *
 * Accessibility: role=dialog, aria-modal, aria-labelledby/describedby.
 * ESC / overlay-click / X button / Cancel → resolve(false).
 * Enter key / OK → resolve(true). Focus trapped inside card while open;
 * focus restored to the opener on close.
 */
(function () {
  'use strict';

  if (window.HDLV2UI && typeof window.HDLV2UI.confirm === 'function') return;
  window.HDLV2UI = window.HDLV2UI || {};

  var CSS_ID = 'hdlv2-ui-modal-css';

  function injectCss() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style');
    s.id = CSS_ID;
    s.textContent = [
      '@keyframes hdlv2-ui-fade{from{opacity:0}to{opacity:1}}',
      '@keyframes hdlv2-ui-pop{from{opacity:0;transform:translateY(6px) scale(.98)}to{opacity:1;transform:none}}',
      '.hdlv2-ui-overlay{position:fixed;inset:0;background:rgba(17,17,17,0.45);z-index:100000;display:flex;align-items:center;justify-content:center;padding:16px;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;animation:hdlv2-ui-fade .2s ease-out}',
      '.hdlv2-ui-card{background:#fff;border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,0.18);max-width:440px;width:100%;padding:28px 28px 20px;position:relative;text-align:left;animation:hdlv2-ui-pop .22s ease-out}',
      '.hdlv2-ui-title{font-family:Poppins,Inter,sans-serif;font-size:19px;font-weight:600;color:#004F59;margin:0 0 8px;letter-spacing:.01em;line-height:1.3}',
      '.hdlv2-ui-body{font-size:14px;line-height:1.55;color:#555;margin:0 0 22px}',
      '.hdlv2-ui-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}',
      '.hdlv2-ui-btn-primary{background:#3d8da0;color:#fff;border:none;border-radius:999px;padding:9px 20px;font:600 13px/1.2 Inter,-apple-system,BlinkMacSystemFont,sans-serif;cursor:pointer;transition:background .15s ease,box-shadow .15s ease,transform .15s ease;min-width:100px;letter-spacing:.01em}',
      '.hdlv2-ui-btn-primary:hover{background:#2c6f80;box-shadow:0 2px 8px rgba(61,141,160,0.22);transform:translateY(-1px)}',
      '.hdlv2-ui-btn-primary:focus-visible{outline:none;box-shadow:0 0 0 2px #fff,0 0 0 5px #3d8da0}',
      '.hdlv2-ui-btn-primary:active{transform:translateY(0)}',
      '.hdlv2-ui-btn-ghost{background:#fff;color:#6b7280;border:1px solid rgba(11,26,34,0.10);border-radius:999px;padding:9px 18px;font:500 13px/1.2 Inter,-apple-system,BlinkMacSystemFont,sans-serif;cursor:pointer;transition:border-color .15s ease,background .15s ease,color .15s ease,box-shadow .15s ease,transform .15s ease;letter-spacing:.01em}',
      '.hdlv2-ui-btn-ghost:hover{color:#2c6f80;background:#e9f3f5;border-color:rgba(61,141,160,0.5);box-shadow:0 2px 8px rgba(61,141,160,0.12);transform:translateY(-1px)}',
      '.hdlv2-ui-btn-ghost:focus-visible{outline:none;box-shadow:0 0 0 2px #fff,0 0 0 5px #3d8da0}',
      '.hdlv2-ui-btn-ghost:active{transform:translateY(0)}',
      '.hdlv2-ui-x{position:absolute;top:10px;right:12px;background:transparent;border:none;font-size:22px;line-height:1;color:#999;cursor:pointer;padding:4px 10px;border-radius:6px;transition:background .15s ease,color .15s ease}',
      '.hdlv2-ui-x:hover{color:#555;background:#f5f5f5}',
      '.hdlv2-ui-x:focus-visible{outline:2px solid #3d8da0;outline-offset:2px}',
      // ── Toast notifications (v0.40.13) ──
      // Bottom-right pill stack, click-to-dismiss, auto-fade.
      // Replaces raw alert() calls on error paths. Uses HDL status palette
      // from CLAUDE.md (error #dc2626, success #10b981, info #3b82f6).
      '.hdlv2-ui-toast-stack{position:fixed;bottom:20px;right:20px;z-index:100001;display:flex;flex-direction:column;gap:8px;max-width:380px;pointer-events:none}',
      '.hdlv2-ui-toast{background:#fff;border-radius:10px;box-shadow:0 6px 24px rgba(0,0,0,0.18);padding:12px 16px;font:500 14px/1.45 Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#111;border-left:4px solid #3d8da0;cursor:pointer;pointer-events:auto;animation:hdlv2-ui-toast-in .22s ease-out;max-width:380px;word-wrap:break-word}',
      '.hdlv2-ui-toast-error{border-left-color:#dc2626;background:#fef2f2;color:#7f1d1d}',
      '.hdlv2-ui-toast-success{border-left-color:#10b981;background:#ecfdf5;color:#064e3b}',
      '.hdlv2-ui-toast-info{border-left-color:#3b82f6;background:#eff6ff;color:#1e3a8a}',
      '.hdlv2-ui-toast-dismiss{animation:hdlv2-ui-toast-out .2s ease-in forwards}',
      '@keyframes hdlv2-ui-toast-in{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}',
      '@keyframes hdlv2-ui-toast-out{from{opacity:1;transform:none}to{opacity:0;transform:translateY(10px)}}'
    ].join('\n');
    document.head.appendChild(s);
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  /**
   * Show a themed confirm dialog.
   *
   * @param {Object} opts
   * @param {string} opts.title                — headline, e.g. "Release WHY for Kim?"
   * @param {string} [opts.body]               — supporting sentence; rendered as safe text
   * @param {string} [opts.confirmLabel='OK']  — primary button text
   * @param {string} [opts.cancelLabel='Cancel']
   * @returns {Promise<boolean>}               — true = confirmed, false = cancelled
   */
  window.HDLV2UI.confirm = function (opts) {
    opts = opts || {};
    injectCss();

    return new Promise(function (resolve) {
      var overlay = document.createElement('div');
      overlay.className = 'hdlv2-ui-overlay';
      overlay.setAttribute('role', 'dialog');
      overlay.setAttribute('aria-modal', 'true');
      overlay.setAttribute('aria-labelledby', 'hdlv2-ui-title');

      var bodyHtml     = opts.body ? '<p id="hdlv2-ui-body" class="hdlv2-ui-body">' + esc(opts.body) + '</p>' : '';
      var confirmLabel = esc(opts.confirmLabel || 'OK');
      var cancelLabel  = esc(opts.cancelLabel || 'Cancel');
      var title        = esc(opts.title || 'Are you sure?');

      overlay.innerHTML =
        '<div class="hdlv2-ui-card">' +
          '<button type="button" class="hdlv2-ui-x" aria-label="Close">&times;</button>' +
          '<h3 id="hdlv2-ui-title" class="hdlv2-ui-title">' + title + '</h3>' +
          bodyHtml +
          '<div class="hdlv2-ui-actions">' +
            '<button type="button" class="hdlv2-ui-btn-ghost" data-action="cancel">' + cancelLabel + '</button>' +
            '<button type="button" class="hdlv2-ui-btn-primary" data-action="confirm">' + confirmLabel + '</button>' +
          '</div>' +
        '</div>';

      if (opts.body) overlay.setAttribute('aria-describedby', 'hdlv2-ui-body');

      var previouslyFocused = document.activeElement;
      var keyHandler;
      var settled = false;

      function close(result) {
        if (settled) return;
        settled = true;
        if (keyHandler) document.removeEventListener('keydown', keyHandler);
        if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        try { if (previouslyFocused && previouslyFocused.focus) previouslyFocused.focus(); } catch (e) {}
        resolve(!!result);
      }

      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) close(false);
      });
      overlay.querySelector('.hdlv2-ui-x').addEventListener('click', function () { close(false); });
      overlay.querySelector('[data-action="cancel"]').addEventListener('click', function () { close(false); });
      overlay.querySelector('[data-action="confirm"]').addEventListener('click', function () { close(true); });

      keyHandler = function (e) {
        if (e.key === 'Escape' || e.key === 'Esc') {
          e.preventDefault();
          close(false);
        } else if (e.key === 'Enter') {
          // Don't hijack Enter when the user is typing elsewhere on the page.
          var ae = document.activeElement;
          if (ae && (ae.tagName === 'INPUT' || ae.tagName === 'TEXTAREA' || ae.isContentEditable)) return;
          e.preventDefault();
          close(true);
        } else if (e.key === 'Tab') {
          // Minimal focus trap: if tab would leave the card, loop back.
          var focusables = overlay.querySelectorAll('button, [href], input, [tabindex]:not([tabindex="-1"])');
          if (!focusables.length) return;
          var first = focusables[0];
          var last  = focusables[focusables.length - 1];
          if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
          else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
      };
      document.addEventListener('keydown', keyHandler);

      document.body.appendChild(overlay);
      // Initial focus on the primary action — matches what the user just
      // clicked (the Release WHY / Generate Report button) and makes Enter
      // confirm, matching browser-native confirm() muscle memory.
      try { overlay.querySelector('[data-action="confirm"]').focus(); } catch (e) {}
    });
  };

  /**
   * Show a themed toast notification (bottom-right pill).
   *
   * Replaces raw alert() calls on error paths. Non-modal — does not block
   * interaction. Multiple toasts stack vertically.
   *
   * @param {string} message — text to display (escaped via textContent)
   * @param {string} [type='error'] — 'error' | 'success' | 'info'
   * @param {number} [ms=5000] — auto-dismiss after this many ms; 0 = sticky
   * @returns {{dismiss: function}} — handle with dismiss() method
   */
  window.HDLV2UI.toast = function (message, type, ms) {
    injectCss();
    type = (type === 'success' || type === 'info') ? type : 'error';
    ms = (typeof ms === 'number') ? ms : 5000;

    var stack = document.getElementById('hdlv2-ui-toast-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.id = 'hdlv2-ui-toast-stack';
      stack.className = 'hdlv2-ui-toast-stack';
      // Polite live region so screen readers announce errors without
      // interrupting whatever the user is doing.
      stack.setAttribute('aria-live', 'polite');
      stack.setAttribute('role', 'status');
      document.body.appendChild(stack);
    }

    var toast = document.createElement('div');
    toast.className = 'hdlv2-ui-toast hdlv2-ui-toast-' + type;
    toast.textContent = String(message == null ? '' : message);

    var timer;
    function dismiss() {
      if (timer) { clearTimeout(timer); timer = null; }
      if (!toast.parentNode) return;
      toast.classList.add('hdlv2-ui-toast-dismiss');
      setTimeout(function () {
        if (toast.parentNode) toast.parentNode.removeChild(toast);
      }, 200);
    }
    toast.addEventListener('click', dismiss);

    stack.appendChild(toast);
    if (ms > 0) timer = setTimeout(dismiss, ms);
    return { dismiss: dismiss };
  };
})();
