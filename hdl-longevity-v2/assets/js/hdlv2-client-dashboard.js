/**
 * HDL V2 — Client Dashboard interactivity.
 *
 *   - 3-tab switcher (Today / Progress / My Report)
 *   - Day picker on Today tab — swap which day's actions are visible
 *   - Action ticking — POST /flight-plan/tick, optimistic UI, rollback on error
 *   - Live "X of Y done" + per-day progress bar updates after each tick
 *
 * Authoritative source = wp_hdlv2_flight_plan_ticks. The same row drives the
 * practitioner's Flight Plan tab in /clients/, so a tick here is visible there
 * on the practitioner's next render — no separate sync.
 *
 * @since 0.32.5
 */

(function () {
  'use strict';

  var CFG = window.HDLV2_CD_POP || {};

  // ────────────────────────────────────────────────────────────────────
  //  Tabs
  // ────────────────────────────────────────────────────────────────────
  function initTabs(root) {
    var tabs = root.querySelectorAll('.cdp-tab');
    var panels = root.querySelectorAll('[data-tab-panel]');
    if (!tabs.length || !panels.length) return;

    function activate(tabName) {
      tabs.forEach(function (t) {
        var isActive = t.getAttribute('data-tab') === tabName;
        t.classList.toggle('active', isActive);
        t.setAttribute('aria-selected', isActive ? 'true' : 'false');
      });
      panels.forEach(function (p) {
        p.classList.toggle(
          'cdp-panel-active',
          p.getAttribute('data-tab-panel') === tabName
        );
      });
      try {
        if (window.history && window.history.replaceState) {
          var url = new URL(window.location.href);
          url.hash = 'tab-' + tabName;
          window.history.replaceState(null, '', url.toString());
        }
      } catch (e) {}
    }

    tabs.forEach(function (t) {
      t.addEventListener('click', function (e) {
        e.preventDefault();
        var name = t.getAttribute('data-tab');
        if (name) activate(name);
      });
    });

    var hash = (window.location.hash || '').replace(/^#tab-/, '');
    if (hash && root.querySelector('.cdp-tab[data-tab="' + hash + '"]')) {
      activate(hash);
    }
  }

  // ────────────────────────────────────────────────────────────────────
  //  Day picker — swap which day's actions are visible
  // ────────────────────────────────────────────────────────────────────
  function initDayPicker(card) {
    var pills = card.querySelectorAll('.cdp-day-pill');
    var lists = card.querySelectorAll('[data-day-actions]');
    if (!pills.length) return;

    pills.forEach(function (pill) {
      pill.addEventListener('click', function () {
        var day = pill.getAttribute('data-day');
        if (!day) return;
        pills.forEach(function (p) {
          var on = p === pill;
          p.classList.toggle('cdp-day-active', on);
          p.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        lists.forEach(function (ul) {
          ul.classList.toggle(
            'cdp-actions-active',
            ul.getAttribute('data-day-actions') === day
          );
        });
        updateDayCounter(card, day);
      });
    });
  }

  // ────────────────────────────────────────────────────────────────────
  //  Action ticking
  // ────────────────────────────────────────────────────────────────────
  function initTicks(card) {
    if (!CFG.restUrl || !CFG.nonce) {
      // No REST config — fall back to read-only.
      return;
    }
    card.addEventListener('click', function (e) {
      var btn = e.target.closest('.cdp-action-check');
      if (!btn) return;
      var li = btn.closest('.cdp-action');
      if (!li) return;
      e.preventDefault();
      toggleTick(card, li);
    });
  }

  // v0.32.7 — Click freely, no per-item lock.
  // Each click cancels the previous in-flight request for the same tick_id
  // (AbortController) and fires a fresh one with the latest desired state.
  // Server-side: each request is a single UPDATE — last write wins. Network
  // reorder of two near-simultaneous clicks → use the desired state from the
  // last click (we keep it on the LI), so rapid tick→untick→tick lands on
  // the correct final state regardless of response ordering.
  var inFlight = Object.create(null); // tick_id → AbortController

  function toggleTick(card, li) {
    var tickId = parseInt(li.getAttribute('data-tick-id'), 10);
    if (!tickId) return;

    var wasTicked = li.getAttribute('data-ticked') === '1';
    var newTicked = !wasTicked;
    var day = li.getAttribute('data-day');

    // Optimistic UI — instant, no dimming.
    li.classList.toggle('cdp-action-done', newTicked);
    li.setAttribute('data-ticked', newTicked ? '1' : '0');
    updateDayProgress(card, day);
    updateDayCounter(card, day);

    // Cancel any in-flight request for this tick_id and fire a fresh one.
    if (inFlight[tickId]) {
      try { inFlight[tickId].abort(); } catch (e) {}
    }
    var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    inFlight[tickId] = ctrl;

    fetch(CFG.restUrl + '/flight-plan/tick', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': CFG.nonce
      },
      body: JSON.stringify({ tick_id: tickId, ticked: newTicked }),
      signal: ctrl ? ctrl.signal : undefined
    })
      .then(function (r) {
        if (inFlight[tickId] === ctrl) delete inFlight[tickId];
        if (!r.ok) throw new Error('Tick failed (' + r.status + ')');
      })
      .catch(function (err) {
        // Aborted = user clicked again; do nothing.
        if (err && err.name === 'AbortError') return;
        if (inFlight[tickId] === ctrl) delete inFlight[tickId];
        // Real network/server error — only roll back if the visible state
        // still matches what THIS request tried to set (otherwise a later
        // click already moved past us; don't clobber).
        if (li.getAttribute('data-ticked') === (newTicked ? '1' : '0')) {
          li.classList.toggle('cdp-action-done', wasTicked);
          li.setAttribute('data-ticked', wasTicked ? '1' : '0');
          updateDayProgress(card, day);
          updateDayCounter(card, day);
          showToast(card, 'Could not save that tick. Please try again.');
        }
      });
  }

  function updateDayProgress(card, day) {
    var ul = card.querySelector('[data-day-actions="' + day + '"]');
    if (!ul) return;
    var items = ul.querySelectorAll('.cdp-action');
    var total = items.length;
    var done = 0;
    items.forEach(function (n) { if (n.getAttribute('data-ticked') === '1') done++; });
    var pct = total ? Math.round((done * 100) / total) : 0;
    var bar = card.querySelector('[data-day-progress="' + day + '"] span');
    if (bar) bar.style.width = pct + '%';
  }

  function updateDayCounter(card, day) {
    var ul = card.querySelector('[data-day-actions="' + day + '"]');
    if (!ul) return;
    var items = ul.querySelectorAll('.cdp-action');
    var total = items.length;
    var done = 0;
    items.forEach(function (n) { if (n.getAttribute('data-ticked') === '1') done++; });
    var counter = card.querySelector('[data-day-counter]');
    if (!counter) return;
    if (total === 0) {
      counter.textContent = 'No actions for this day yet.';
      return;
    }
    var doneEl = counter.querySelector('[data-done-count]');
    var totEl = counter.querySelector('[data-total-count]');
    var labelEl = counter.querySelector('[data-day-label]');
    if (doneEl) doneEl.textContent = String(done);
    if (totEl) totEl.textContent = String(total);
    if (labelEl) {
      labelEl.textContent = day === (CFG.todayKey || '')
        ? 'today'
        : day.charAt(0).toUpperCase() + day.slice(1);
    }
  }

  function showToast(card, msg) {
    var t = document.createElement('div');
    t.className = 'cdp-toast';
    t.textContent = msg;
    card.appendChild(t);
    setTimeout(function () { t.classList.add('cdp-toast-show'); }, 10);
    setTimeout(function () {
      t.classList.remove('cdp-toast-show');
      setTimeout(function () { t.remove(); }, 200);
    }, 2400);
  }

  // ────────────────────────────────────────────────────────────────────
  //  Boot
  // ────────────────────────────────────────────────────────────────────
  function boot() {
    document.querySelectorAll('.hdlv2-cd-pop').forEach(function (root) {
      initTabs(root);
      root.querySelectorAll('.cdp-week-card').forEach(function (card) {
        initDayPicker(card);
        initTicks(card);
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
