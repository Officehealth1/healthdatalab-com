/**
 * HDL V2 Weekly Check-in — Client reflection with audio component.
 * @package HDL_Longevity_V2
 * @since 0.7.0
 */
(function () {
  'use strict';

  var CFG = window.hdlv2_checkin || {};
  if (!CFG.api_base) return;

  var root = document.getElementById('hdlv2-checkin');
  if (!root) return;

  var token = '';
  try { token = new URLSearchParams(window.location.search).get('token') || ''; } catch (e) {}
  if (!token || !/^[a-f0-9]{64}$/.test(token)) {
    root.innerHTML = '<div class="hdlv2-card"><div class="hdlv2-error"><h3>Invalid link</h3><p>Please check your check-in URL.</p></div></div>';
    return;
  }

  var PROMPTS = [
    'How did movement/fitness go this week?',
    'Which changes felt too easy this week?',
    'Which changes were hard or a real stretch?',
    'Were there days you couldn\u2019t follow the plan? What happened?',
    'How was your energy and mood overall?',
    'Did anything or anyone make it harder to stick to the plan?',
    'Any wins or breakthroughs worth noting?',
    'Anything you want your practitioner to know?',
    'Is there anything urgent or concerning you want to flag?'
  ];

  // Track state for "Add more" / "Not quite right" flows
  var currentCheckin = null;
  var weekData = null;

  function init() {
    root.innerHTML = '<div class="hdlv2-card"><div class="hdlv2-loading"><div class="hdlv2-spinner"></div><p style="color:#888;">Loading...</p></div></div>';
    fetch(CFG.api_base + '/load?token=' + encodeURIComponent(token), { headers: { 'X-WP-Nonce': CFG.nonce } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        weekData = data;
        if (data.checkin && data.checkin.status === 'confirmed') { renderDone(data); }
        else if (data.checkin && data.checkin.summary) { renderReview(data.checkin); }
        else { renderInput(data); }
      })
      .catch(function () { root.innerHTML = '<div class="hdlv2-card"><p style="color:#dc2626;padding:24px;">Connection error. Please try again.</p></div>'; });
  }

  function renderInput(data, opts) {
    opts = opts || {};
    var html = '<div class="hdlv2-card">'
      + '<div class="hdlv2-header"><h2>Weekly Check-in</h2><p>Week ' + (data.week_number || weekData.week_number || '?') + ' \u2014 ' + (data.week_start || weekData.week_start || '') + '</p></div>'
      + '<div class="hdlv2-form-body">';

    if (opts.mode === 'addmore') {
      html += '<div style="background:#f0f9fb;border-left:4px solid #3d8da0;padding:10px 14px;margin-bottom:14px;border-radius:0 8px 8px 0;font-size:13px;color:#444;">'
        + 'Add anything you missed. Your previous input will be combined with what you add below.</div>';
    } else if (opts.mode === 'correction') {
      html += '<div style="background:#fef3cd;border-left:4px solid #f59e0b;padding:10px 14px;margin-bottom:14px;border-radius:0 8px 8px 0;font-size:13px;color:#856404;">'
        + 'Tell us what needs correcting and we\u2019ll regenerate your summary.</div>';
    } else {
      // ── 4A: Flight Plan reference (collapsible) ──
      var fp = weekData && weekData.flight_plan;
      if (fp) {
        html += '<details style="background:#f0f9fb;border:1px solid #bae6fd;border-radius:8px;margin-bottom:14px;font-size:12px;">'
          + '<summary style="padding:10px 14px;cursor:pointer;font-weight:600;color:#004F59;font-size:13px;">Your Flight Plan this week (Week ' + fp.week_number + ')</summary>'
          + '<div style="padding:0 14px 12px;">';
        if (fp.identity_statement) {
          html += '<div style="font-style:italic;color:#3d8da0;margin-bottom:8px;">\u201c' + esc(fp.identity_statement) + '\u201d</div>';
        }
        if (fp.weekly_targets && fp.weekly_targets.length) {
          html += '<div style="font-weight:600;color:#004F59;margin-bottom:4px;">Weekly Targets:</div><ul style="margin:0 0 8px;padding-left:18px;color:#444;line-height:1.6;">';
          fp.weekly_targets.forEach(function (t) { html += '<li>' + esc(typeof t === 'string' ? t : t.text || t.target || '') + '</li>'; });
          html += '</ul>';
        }
        if (fp.adherence_summary) {
          var adh = fp.adherence_summary;
          html += '<div style="font-weight:600;color:#004F59;margin-bottom:4px;">Adherence so far:</div>'
            + '<div style="color:#444;">Overall: ' + (adh.overall || 0) + '% | Movement: ' + (adh.movement || 0) + '% | Nutrition: ' + (adh.nutrition || 0) + '% | Key Actions: ' + (adh.key_action || 0) + '%</div>';
        }
        html += '</div></details>';
      }

      html += '<p style="font-size:13px;color:#444;margin:0 0 12px;line-height:1.6;">Use these prompts as memory aids \u2014 you don\u2019t need to answer each one. Just share what comes to mind.</p>'
        + '<ul style="font-size:13px;color:#666;margin:0 0 16px;padding-left:20px;line-height:1.7;">';
      PROMPTS.forEach(function (p) { html += '<li>' + p + '</li>'; });
      html += '</ul>';
    }

    html += '<div id="hdlv2-checkin-audio"></div>';

    // For correction mode, show a text-only input
    if (opts.mode === 'correction') {
      html += '<div class="hdlv2-field" style="margin-top:12px;">'
        + '<label>What needs correcting?</label>'
        + '<textarea id="hdlv2-correction-text" class="hdlv2-ac-text" style="min-height:100px;" placeholder="e.g. I actually walked 6 days not 5, and the knee pain was on Friday not Thursday..."></textarea>'
        + '</div>'
        + '<button id="hdlv2-correction-submit" class="hdlv2-btn" style="margin-top:12px;">Regenerate Summary</button>';
    }

    html += '</div></div>';
    root.innerHTML = html;

    // Correction mode — text-only submit
    if (opts.mode === 'correction') {
      var corrBtn = document.getElementById('hdlv2-correction-submit');
      if (corrBtn) {
        corrBtn.addEventListener('click', function () {
          var corrText = document.getElementById('hdlv2-correction-text').value.trim();
          if (!corrText) return;
          corrBtn.disabled = true; corrBtn.textContent = 'Processing...';
          submitCheckin(null, { correction_mode: true, correction: corrText });
        });
      }
      return; // Skip audio component for correction mode
    }

    // Normal and addmore modes — show audio component
    var audioEl = document.getElementById('hdlv2-checkin-audio');
    if (audioEl && window.HDLAudioComponent) {
      HDLAudioComponent.create(audioEl, {
        contextType: 'weekly_checkin',
        apiBase: CFG.api_base.replace('/checkin', '/audio'),
        nonce: CFG.nonce,
        token: token,
        onConfirm: function (summary) {
          submitCheckin(summary, opts.mode === 'addmore' ? { append_mode: true } : {});
        }
      });
    }
  }

  function submitCheckin(summary, extraParams) {
    root.innerHTML = '<div class="hdlv2-card"><div class="hdlv2-loading"><div class="hdlv2-spinner"></div><p style="color:#888;">Analysing your check-in...</p></div></div>';
    var body = { token: token };
    if (summary) body.audio_summary = summary;
    if (extraParams) {
      if (extraParams.append_mode) body.append_mode = true;
      if (extraParams.correction_mode) {
        body.correction_mode = true;
        body.correction = extraParams.correction;
        // Re-send original raw input so the correction has context
        if (currentCheckin && currentCheckin.raw_input) body.text = currentCheckin.raw_input;
        else body.text = extraParams.correction;
      }
    }

    fetch(CFG.api_base + '/submit', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify(body)
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) { renderReview(res); }
        else { root.innerHTML = '<div class="hdlv2-card"><p style="color:#dc2626;padding:24px;">' + esc(res.message || 'Error processing check-in.') + '</p></div>'; }
      })
      .catch(function () { root.innerHTML = '<div class="hdlv2-card"><p style="color:#dc2626;padding:24px;">Connection error. Please try again.</p></div>'; });
  }

  // ── 1A: Structured summary display ──
  function renderReview(checkin) {
    currentCheckin = checkin;
    var s = checkin.summary;
    if (typeof s === 'string') { try { s = JSON.parse(s); } catch (e) { s = { raw: s }; } }

    var html = '<div class="hdlv2-card">'
      + '<div class="hdlv2-header"><h2>Your Check-in Summary</h2><p>Please review and confirm.</p></div>'
      + '<div class="hdlv2-form-body">';

    // Flags banner
    if (checkin.has_flags) {
      html += '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#dc2626;font-weight:500;">'
        + '\u26a0\ufe0f Some items may need your practitioner\u2019s attention</div>';
    }

    // Main summary
    if (s.check_in_summary) {
      html += '<div style="background:#f8f9fb;border:1px solid #e4e6ea;border-radius:8px;padding:14px;font-size:14px;line-height:1.7;color:#333;margin-bottom:16px;">'
        + esc(s.check_in_summary) + '</div>';
    } else if (s.raw) {
      html += '<div style="background:#f8f9fb;border:1px solid #e4e6ea;border-radius:8px;padding:14px;font-size:13px;line-height:1.6;color:#444;white-space:pre-wrap;max-height:300px;overflow-y:auto;">'
        + esc(s.raw) + '</div>';
    }

    // Structured breakdown
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px;">';

    // Fitness adherence
    if (s.fitness_adherence) {
      html += renderScoreCard('Fitness', s.fitness_adherence.summary, s.fitness_adherence.score, '\ud83c\udfc3');
    }
    // Nutrition adherence
    if (s.nutrition_adherence) {
      html += renderScoreCard('Nutrition', s.nutrition_adherence.summary, s.nutrition_adherence.score, '\ud83e\udd57');
    }

    html += '</div>';

    // Energy & mood
    if (s.energy_mood) {
      html += renderSection('\u26a1 Energy & Mood', s.energy_mood);
    }

    // Wins
    if (s.wins && s.wins.length) {
      html += renderList('\ud83c\udfc6 Wins', s.wins);
    }

    // Obstacles
    if (s.obstacles && s.obstacles.length) {
      html += renderList('\ud83d\udea7 Obstacles', s.obstacles);
    }

    // Comfort zone
    if (s.comfort_zone && typeof s.comfort_zone === 'object' && !Array.isArray(s.comfort_zone)) {
      html += '<div style="background:#f8f9fb;border:1px solid #e4e6ea;border-radius:8px;padding:12px;margin-bottom:12px;">'
        + '<div style="font-size:12px;font-weight:600;color:#004F59;margin-bottom:8px;">Comfort Zone</div>';
      if (s.comfort_zone.too_easy && s.comfort_zone.too_easy.length) {
        html += '<div style="margin-bottom:6px;"><span style="font-size:11px;font-weight:600;color:#10b981;">Too easy:</span> <span style="font-size:12px;color:#444;">' + s.comfort_zone.too_easy.map(esc).join(', ') + '</span></div>';
      }
      if (s.comfort_zone.about_right && s.comfort_zone.about_right.length) {
        html += '<div style="margin-bottom:6px;"><span style="font-size:11px;font-weight:600;color:#3d8da0;">About right:</span> <span style="font-size:12px;color:#444;">' + s.comfort_zone.about_right.map(esc).join(', ') + '</span></div>';
      }
      if (s.comfort_zone.too_hard && s.comfort_zone.too_hard.length) {
        html += '<div><span style="font-size:11px;font-weight:600;color:#f59e0b;">Too hard:</span> <span style="font-size:12px;color:#444;">' + s.comfort_zone.too_hard.map(esc).join(', ') + '</span></div>';
      }
      html += '</div>';
    }

    // Client suggestions
    if (s.client_suggestions) {
      html += renderSection('\ud83d\udca1 Your suggestions', s.client_suggestions);
    }

    // ── 1B, 1C, 1D: Three buttons ──
    html += '<div style="display:flex;flex-direction:column;gap:8px;margin-top:16px;">'
      + '<button id="hdlv2-ci-confirm" class="hdlv2-btn">\u2705 Confirm Check-in</button>'
      + '<div style="display:flex;gap:8px;">'
      + '<button id="hdlv2-ci-addmore" class="hdlv2-btn" style="flex:1;background:#fff;color:#3d8da0;border:1px solid #3d8da0;">I want to add more</button>'
      + '<button id="hdlv2-ci-correct" class="hdlv2-btn" style="flex:1;background:#fff;color:#f59e0b;border:1px solid #f59e0b;">That\u2019s not quite right</button>'
      + '</div></div>';

    html += '</div></div>';
    root.innerHTML = html;

    // ── 1D: Confirm with error recovery ──
    var confirmBtn = document.getElementById('hdlv2-ci-confirm');
    confirmBtn.addEventListener('click', function () {
      confirmBtn.disabled = true; confirmBtn.textContent = 'Confirming...';
      fetch(CFG.api_base + '/confirm', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({ token: token, checkin_id: checkin.checkin_id || checkin.id })
      })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.success) { renderDone(); }
          else {
            confirmBtn.disabled = false; confirmBtn.textContent = '\u2705 Confirm Check-in';
            showError('Could not confirm. Please try again.');
          }
        })
        .catch(function () {
          confirmBtn.disabled = false; confirmBtn.textContent = '\u2705 Confirm Check-in';
          showError('Connection error. Please try again.');
        });
    });

    // ── 1B: Add more ──
    document.getElementById('hdlv2-ci-addmore').addEventListener('click', function () {
      renderInput(weekData || {}, { mode: 'addmore' });
    });

    // ── 1C: Not quite right ──
    document.getElementById('hdlv2-ci-correct').addEventListener('click', function () {
      renderInput(weekData || {}, { mode: 'correction' });
    });
  }

  // ── 4B: Enhanced post-confirm success screen ──
  function renderDone() {
    var fp = weekData && weekData.flight_plan;
    var html = '<div class="hdlv2-card"><div class="hdlv2-result" style="text-align:center;padding:40px 20px;">'
      + '<div style="font-size:48px;margin-bottom:12px;">\u2705</div>'
      + '<h3 style="margin:0 0 8px;font-size:18px;color:#111;">Check-in complete \u2014 thank you!</h3>'
      + '<p style="font-size:14px;color:#888;margin:0 0 16px;">Your practitioner has been notified. Your next Flight Plan will be ready by Saturday.</p>';

    // Reminder to transfer ticks if flight plan exists
    if (fp && fp.adherence_summary) {
      var adh = fp.adherence_summary;
      var overall = adh.overall || 0;
      if (overall < 100) {
        html += '<div style="background:#fef3cd;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin:0 auto 12px;max-width:360px;font-size:12px;color:#856404;text-align:left;">'
          + '\ud83d\udcdd Don\u2019t forget to transfer your ticks from your printed plan \u2014 your current adherence is ' + overall + '%.'
          + '</div>';
      }
    }

    // Link to flight plan page if one exists
    if (fp) {
      var fpUrl = window.location.pathname.replace(/\/check-?in\/?/i, '/flight-plan/') + '?token=' + encodeURIComponent(token);
      html += '<a href="' + fpUrl + '" class="hdlv2-btn-secondary" style="display:inline-block;margin-top:8px;text-decoration:none;">View your Flight Plan</a>';
    }

    html += '</div></div>';
    root.innerHTML = html;
  }

  // ── Helpers ──
  function renderScoreCard(label, summary, score, icon) {
    var color = score >= 7 ? '#10b981' : score >= 4 ? '#f59e0b' : '#dc2626';
    return '<div style="background:#f8f9fb;border:1px solid #e4e6ea;border-radius:8px;padding:12px;">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">'
      + '<span style="font-size:12px;font-weight:600;color:#004F59;">' + icon + ' ' + esc(label) + '</span>'
      + '<span style="font-size:16px;font-weight:700;color:' + color + ';">' + (score || '?') + '/10</span>'
      + '</div>'
      + '<div style="font-size:12px;color:#666;line-height:1.5;">' + esc(summary || '') + '</div></div>';
  }

  function renderSection(title, text) {
    return '<div style="margin-bottom:12px;">'
      + '<div style="font-size:12px;font-weight:600;color:#004F59;margin-bottom:4px;">' + title + '</div>'
      + '<div style="font-size:13px;color:#444;line-height:1.6;">' + esc(text) + '</div></div>';
  }

  function renderList(title, items) {
    var html = '<div style="margin-bottom:12px;">'
      + '<div style="font-size:12px;font-weight:600;color:#004F59;margin-bottom:4px;">' + title + '</div>'
      + '<ul style="margin:0;padding-left:18px;font-size:12px;color:#444;line-height:1.7;">';
    items.forEach(function (item) { html += '<li>' + esc(typeof item === 'string' ? item : item.text || JSON.stringify(item)) + '</li>'; });
    return html + '</ul></div>';
  }

  function showError(msg) {
    var existing = document.getElementById('hdlv2-ci-error');
    if (existing) existing.remove();
    var errDiv = document.createElement('div');
    errDiv.id = 'hdlv2-ci-error';
    errDiv.style.cssText = 'color:#dc2626;font-size:13px;text-align:center;margin-top:8px;';
    errDiv.textContent = msg;
    var btns = root.querySelector('.hdlv2-form-body');
    if (btns) btns.appendChild(errDiv);
  }

  function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
