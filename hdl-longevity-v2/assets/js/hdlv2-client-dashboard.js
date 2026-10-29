/**
 * HDL V2 — Client Dashboard interactivity.
 *
 *   - 3-tab switcher (Today / Progress / My Report)
 *   - Day picker on Today tab — swap which day's actions are visible
 *   - Action ticking — POST /flight-plan/tick, optimistic UI, rollback on error
 *   - Live "X of Y done" + per-day progress bar updates after each tick
 *   - v0.48.23: the 30-day challenge "Done for today" form (every state)
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

    // v0.48.23 — #challenge (the challenge link's landing) opens its tab too.
    var hash = (window.location.hash || '').replace(/^#(tab-)?/, '');
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
  //  30-day challenge (v0.48.23) — POST /challenge/tick, then swap the
  //  section body for the server's re-render. Delegated from document so it
  //  works on every dashboard state and after each swap. The button stays
  //  disabled while the request is in flight; an error re-enables it.
  // ────────────────────────────────────────────────────────────────────
  var CHALLENGE_CODES = /^(day_locked|invalid_day|not_enrolled)$/;
  var SESSION_TIMED_OUT = 'Your session timed out. Copy your note, reload the page, then tick again.';

  // v0.48.24 — the next day opens at midnight UTC; say when that is on the
  // buyer's own clock ("today" when it falls on their current date).
  function challengeOpensText(day, at, now) {
    return 'Day ' + day + ' done. Day ' + (day + 1) + ' opens ' +
      (at.toDateString() === now.toDateString() ? 'today' : 'tomorrow') + ' at ' +
      at.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + '.';
  }

  function showOpensAt(root) {
    root.querySelectorAll('.cd30-next[data-next-opens-at]').forEach(function (p) {
      var at = new Date(p.getAttribute('data-next-opens-at'));
      var day = parseInt(p.getAttribute('data-day'), 10);
      if (!isNaN(at.getTime()) && day) p.textContent = challengeOpensText(day, at, new Date());
    });
  }

  // v0.48.27 (D9) — a done day opened again: its title, what it asked, the
  // video (paid only), when it was done and the client's own note. The days
  // ride as JSON in .cd30-body, so each tick's swap keeps them current.
  function challengeDayModel(day, data) {
    data = data || {};
    var done = data.done_at ? new Date(String(data.done_at).replace(' ', 'T') + 'Z') : null;
    var hasVideo = /^[A-Za-z0-9_-]{11}$/.test(data.youtube_id || '');
    return {
      title: data.title || 'Day ' + day,
      lines: data.text ? String(data.text).split('\n') : [],
      hasVideo: hasVideo,
      embedSrc: hasVideo ? 'https://www.youtube-nocookie.com/embed/' + data.youtube_id : null,
      // The buyer's own date, written day first like the tile's label ("23 Oct").
      when: done && !isNaN(done.getTime()) ? 'Done ' + done.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) : '',
      note: data.note ? String(data.note) : 'No note'
    };
  }

  function challengeDayView(section, day, data) {
    var m = challengeDayModel(day, data);
    var detail = section.querySelector('.cd30-detail');
    var today = section.querySelector('.cd30-today');
    if (!detail || !today) return;
    var title = detail.querySelector('h3');
    var text = detail.querySelector('.cd30-text');
    var video = detail.querySelector('.cd30-video');
    title.textContent = m.title;
    text.textContent = '';
    m.lines.forEach(function (line, i) {
      if (i) text.appendChild(document.createElement('br'));
      text.appendChild(document.createTextNode(line));
    });
    text.hidden = !m.lines.length;
    video.textContent = '';
    if (m.embedSrc) {
      var wrap = document.createElement('div');
      var frame = document.createElement('iframe');
      wrap.className = 'hdlv2-video-embed';
      frame.src = m.embedSrc;
      frame.title = m.title;
      frame.loading = 'lazy';
      frame.allowFullscreen = true;
      frame.referrerPolicy = 'strict-origin-when-cross-origin';
      wrap.appendChild(frame);
      video.appendChild(wrap);
    }
    video.hidden = !m.embedSrc;
    detail.querySelector('.cd30-when').textContent = m.when;
    detail.querySelector('.cd30-note').textContent = m.note;
    section.querySelectorAll('button.cd30-day').forEach(function (b) {
      b.setAttribute('aria-expanded', b.getAttribute('data-day') === String(day) ? 'true' : 'false');
    });
    today.hidden = true;
    detail.hidden = false;
    title.focus();
  }

  function challengeToday(section) {
    var detail = section.querySelector('.cd30-detail');
    var today = section.querySelector('.cd30-today');
    var tile = section.querySelector('button.cd30-day[aria-expanded="true"]');
    if (!detail || !today) return;
    detail.querySelector('.cd30-video').textContent = ''; // stops a playing video
    detail.hidden = true;
    today.hidden = false;
    if (tile) {
      tile.setAttribute('aria-expanded', 'false');
      tile.focus();
    }
  }

  function initChallenge() {
    showOpensAt(document);
    document.addEventListener('click', function (e) {
      var section = document.getElementById('challenge');
      if (!section || !e.target.closest || !section.contains(e.target)) return;
      var tile = e.target.closest('button.cd30-day.is-done');
      if (tile) {
        var days = {};
        try { days = JSON.parse(section.querySelector('.cd30-days').textContent) || {}; } catch (ex) { /* no day data: the title alone */ }
        var day = tile.getAttribute('data-day');
        challengeDayView(section, parseInt(day, 10), days[day]);
      } else if (e.target.closest('.cd30-back')) {
        challengeToday(section);
      }
    });
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!form.classList || !form.classList.contains('cd30-tick')) return;
      e.preventDefault();
      var btn = form.querySelector('.cd30-done');
      var err = form.querySelector('.cd30-error');
      var note = form.querySelector('textarea');
      var day = parseInt(form.getAttribute('data-day'), 10);
      if (!CFG.restUrl || !CFG.nonce || !btn || btn.disabled) return;
      btn.disabled = true;
      if (err) err.hidden = true;

      fetch(CFG.restUrl + '/challenge/tick', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': CFG.nonce
        },
        body: JSON.stringify({ day: day, note: note ? note.value : '' })
      })
        .then(function (r) {
          return r.json().catch(function () { return null; }).then(function (body) {
            if (!r.ok || !body || !body.html) {
              // Our own refusals carry a plain sentence; an expired nonce
              // (v0.48.24) needs a reload; anything else says nothing useful.
              var code = (body && body.code) || '';
              throw {
                userMessage: code === 'rest_cookie_invalid_nonce' ? SESSION_TIMED_OUT
                  : CHALLENGE_CODES.test(code) ? body.message : ''
              };
            }
            return body;
          });
        })
        .then(function (body) {
          var section = document.getElementById('challenge');
          var tpl = document.createElement('template');
          tpl.innerHTML = body.html;
          var fresh = tpl.content.querySelector('.cd30-body');
          var old = section && section.querySelector('.cd30-body');
          if (old && fresh) {
            old.replaceWith(fresh);
            showOpensAt(fresh);
          }
          var heading = document.getElementById('cd30-h');
          if (heading) heading.focus();
          var live = section && section.querySelector('.cd30-live');
          if (live) live.textContent = 'Day ' + day + ' saved';
        })
        .catch(function (ex) {
          btn.disabled = false;
          if (err) {
            err.textContent = (ex && ex.userMessage) || 'Could not save. Try again.';
            err.hidden = false;
          }
        });
    });
  }

  // ────────────────────────────────────────────────────────────────────
  //  Boot
  // ────────────────────────────────────────────────────────────────────
  function boot() {
    initChallenge();
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
