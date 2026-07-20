/**
 * HDL V2 — Shared loading helpers
 *
 * Exposes `window.HDLV2Loading` with three helpers:
 *
 *   HDLV2Loading.skeleton(kind)
 *     Returns innerHTML string for a shaped placeholder.
 *     kind ∈ 'report' | 'flightPlan' | 'consultation' | 'dashboard' | 'checkin'
 *
 *   HDLV2Loading.progress(container, opts)
 *     Mounts a progress-bar illusion + step ladder inside `container`.
 *     Bar fills asymptotically (cap default 92) so the user always sees
 *     movement during long Claude waits, without ever appearing to hit
 *     100% before the work is done.
 *     opts = { steps: string[], capPercent?: number, stepMs?: number }
 *     Returns { finish(), cancel(), bump(label?) }.
 *
 *   HDLV2Loading.optimisticSave(button, asyncFn)
 *     Flips the button label to a green "Saved" chip immediately, then
 *     awaits asyncFn(). On reject, reverts the label and shows an error
 *     chip + lets the user try again.
 *
 * Companion CSS: assets/css/hdlv2-loading.css. The CSS class names
 * referenced here MUST stay in sync with that file.
 *
 * @since 0.37.0
 */

(function () {
  'use strict';

  if (window.HDLV2Loading) return; // idempotent — survives double-enqueue

  // ── Skeleton library ────────────────────────────────────────────────

  function line(mod) { return '<div class="hdlv2-skel hdlv2-skel-line' + (mod ? ' ' + mod : '') + '"></div>'; }
  function heading()  { return '<div class="hdlv2-skel hdlv2-skel-heading"></div>'; }
  function pill()     { return '<div class="hdlv2-skel hdlv2-skel-pill"></div>'; }
  function block()    { return '<div class="hdlv2-skel hdlv2-skel-block"></div>'; }
  function avatar()   { return '<div class="hdlv2-skel hdlv2-skel-avatar"></div>'; }

  // Mirrors hdlv2-dr (hero + pace + section + section + section).
  function skeletonReport() {
    return ''
      + '<div class="hdlv2-skel-container" aria-busy="true" aria-label="Loading your report">'
      +   heading()
      +   line('l') + line('m')
      +   '<div style="margin:24px 0;">' + block() + '</div>'
      +   line('s')
      +   '<div style="display:flex;gap:14px;margin:18px 0 28px;">'
      +     pill() + pill() + pill()
      +   '</div>'
      +   line('l') + line('l') + line('m')
      +   '<div style="margin:28px 0 12px;">' + heading() + '</div>'
      +   line('l') + line('l') + line('s')
      + '</div>';
  }

  // Flight Plan = week pill + identity line + 5 tickable action rows.
  function skeletonFlightPlan() {
    var rows = '';
    for (var i = 0; i < 5; i++) {
      rows += ''
        + '<div class="hdlv2-skel-row">'
        +   avatar()
        +   '<div class="hdlv2-skel-row-body">'
        +     line('m')
        +     line('s')
        +   '</div>'
        + '</div>';
    }
    return ''
      + '<div class="hdlv2-skel-container hdlv2-skel-card" aria-busy="true" aria-label="Loading your flight plan">'
      +   '<div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">'
      +     pill()
      +     '<div class="hdlv2-skel hdlv2-skel-line" style="width:140px;margin:0;"></div>'
      +   '</div>'
      +   line('l')
      +   '<div style="margin-top:18px;">' + rows + '</div>'
      + '</div>';
  }

  // Consultation = left brief panel + right draft-report skeleton.
  function skeletonConsultation() {
    return ''
      + '<div class="hdlv2-skel-container" aria-busy="true" aria-label="Loading consultation" style="display:grid;grid-template-columns:1fr;gap:18px;">'
      +   '<div class="hdlv2-skel-card">'
      +     heading()
      +     line('l') + line('m') + line('s')
      +     '<div style="margin:20px 0;">' + block() + '</div>'
      +     line('l') + line('m')
      +   '</div>'
      + '</div>';
  }

  // Practitioner dashboard = 4 client rows.
  function skeletonDashboard() {
    var rows = '';
    for (var i = 0; i < 4; i++) {
      rows += ''
        + '<div class="hdlv2-skel-row">'
        +   avatar()
        +   '<div class="hdlv2-skel-row-body">'
        +     line('m')
        +     line('s')
        +   '</div>'
        +   pill()
        + '</div>';
    }
    return ''
      + '<div class="hdlv2-skel-container hdlv2-skel-card" aria-busy="true" aria-label="Loading clients">'
      +   rows
      + '</div>';
  }

  // Check-in = single card with heading + 3 lines + audio block placeholder.
  function skeletonCheckin() {
    return ''
      + '<div class="hdlv2-skel-container hdlv2-skel-card" aria-busy="true" aria-label="Loading check-in">'
      +   heading()
      +   line('l') + line('l') + line('m')
      +   '<div style="margin:20px 0;">' + block() + '</div>'
      +   line('s')
      + '</div>';
  }

  function skeleton(kind) {
    switch (kind) {
      case 'report':         return skeletonReport();
      case 'flightPlan':     return skeletonFlightPlan();
      case 'consultation':   return skeletonConsultation();
      case 'dashboard':      return skeletonDashboard();
      case 'checkin':        return skeletonCheckin();
      default:               return skeletonReport(); // sensible default
    }
  }

  // ── Progress-bar illusion ───────────────────────────────────────────

  // Asymptotic fill — each tick advances toward `cap` by 8% of the
  // remaining distance, so the bar feels fast early then slows. The
  // visual rhythm is similar to GitHub / Linear / Vercel progress bars.
  // 4-second step rotation keeps a fresh label in front of the user even
  // when the bar barely moves between ticks.
  function progress(container, opts) {
    opts = opts || {};
    var steps = Array.isArray(opts.steps) && opts.steps.length
      ? opts.steps
      : ['Working on it'];
    var cap   = typeof opts.capPercent === 'number' ? opts.capPercent : 92;
    var stepMs = typeof opts.stepMs === 'number' ? opts.stepMs : 4000;

    if (!container || typeof container.querySelector !== 'function') {
      // Defensive: if caller passed a non-element, no-op safely.
      return { finish: function () {}, cancel: function () {}, bump: function () {} };
    }

    // Mount HTML
    container.insertAdjacentHTML(
      'beforeend',
      '<div class="hdlv2-progress" data-hdlv2-progress="1">'
      +   '<div class="hdlv2-progress-track"><div class="hdlv2-progress-fill"></div></div>'
      +   '<p class="hdlv2-progress-step">' + escapeText(steps[0]) + '</p>'
      + '</div>'
    );

    var wrap   = container.querySelector('[data-hdlv2-progress="1"]:last-child');
    if (!wrap) {
      // Older Safari may not honour :last-child on attr selectors; fall back.
      var all = container.querySelectorAll('[data-hdlv2-progress="1"]');
      wrap = all[all.length - 1];
    }
    if (!wrap) return { finish: function () {}, cancel: function () {}, bump: function () {} };

    var fill   = wrap.querySelector('.hdlv2-progress-fill');
    var stepEl = wrap.querySelector('.hdlv2-progress-step');

    var pct = 0;
    var stepIdx = 0;
    var stopped = false;

    var fillTick = setInterval(function () {
      if (stopped) return;
      pct = pct + (cap - pct) * 0.08;
      if (pct > cap - 0.5) pct = cap - 0.5;
      if (fill) fill.style.width = pct.toFixed(2) + '%';
    }, 220);

    var stepTick = setInterval(function () {
      if (stopped) return;
      stepIdx = (stepIdx + 1) % steps.length;
      if (stepEl) stepEl.firstChild
        ? (stepEl.firstChild.nodeValue = steps[stepIdx])
        : (stepEl.textContent = steps[stepIdx]);
    }, stepMs);

    function stop() {
      stopped = true;
      clearInterval(fillTick);
      clearInterval(stepTick);
    }

    return {
      finish: function () {
        stop();
        if (fill) fill.style.width = '100%';
        if (stepEl) {
          stepEl.classList.add('done');
          stepEl.textContent = 'Done';
        }
      },
      cancel: function () {
        stop();
      },
      bump: function (label) {
        if (stepEl && typeof label === 'string') stepEl.textContent = label;
      }
    };
  }

  // ── Optimistic save ─────────────────────────────────────────────────

  function optimisticSave(button, asyncFn) {
    if (!button || typeof asyncFn !== 'function') {
      return Promise.reject(new Error('optimisticSave: invalid arguments'));
    }

    var originalHtml = button.innerHTML;
    var originalDisabled = button.disabled;

    // Flip to "Saved ✓" immediately — the optimistic part.
    button.innerHTML =
      '<span class="hdlv2-toast-saved show" role="status">'
      +   '<svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true">'
      +     '<path d="M2 6.5L4.5 9 10 3" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
      +   '</svg>'
      +   'Saved'
      + '</span>';
    button.disabled = true;

    var revertTimer = null;

    function restore() {
      button.innerHTML = originalHtml;
      button.disabled = originalDisabled;
    }

    function showError() {
      button.innerHTML =
        '<span class="hdlv2-toast-saved error show" role="status">'
        +   '<svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true">'
        +     '<path d="M3 3l6 6M9 3l-6 6" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/>'
        +   '</svg>'
        +   'Retry'
        + '</span>';
      button.disabled = false;
    }

    return Promise.resolve()
      .then(function () { return asyncFn(); })
      .then(function (result) {
        // Hold the "Saved" chip for 1.8s then revert to original label so
        // the user can save again if they keep typing.
        revertTimer = setTimeout(restore, 1800);
        return result;
      })
      .catch(function (err) {
        if (revertTimer) clearTimeout(revertTimer);
        showError();
        throw err;
      });
  }

  // ── Utils ───────────────────────────────────────────────────────────

  function escapeText(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  // ── Public API ──────────────────────────────────────────────────────

  window.HDLV2Loading = {
    skeleton:        skeleton,
    progress:        progress,
    optimisticSave:  optimisticSave,
    // Expose primitives for ad-hoc consumers that need custom shapes.
    _primitives: { line: line, heading: heading, pill: pill, block: block, avatar: avatar }
  };
})();
