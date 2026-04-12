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

  function init() {
    root.innerHTML = '<div class="hdlv2-card"><div class="hdlv2-loading"><div class="hdlv2-spinner"></div><p style="color:#888;">Loading...</p></div></div>';
    fetch(CFG.api_base + '/load?token=' + encodeURIComponent(token), { headers: { 'X-WP-Nonce': CFG.nonce } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.checkin && data.checkin.status === 'confirmed') { renderDone(data); }
        else if (data.checkin && data.checkin.summary) { renderReview(data.checkin); }
        else { renderInput(data); }
      })
      .catch(function () { root.innerHTML = '<div class="hdlv2-card"><p style="color:#dc2626;">Connection error.</p></div>'; });
  }

  function renderInput(data) {
    var html = '<div class="hdlv2-card">'
      + '<div class="hdlv2-header"><h2>Weekly Check-in</h2><p>Week ' + (data.week_number || '?') + ' \u2014 ' + (data.week_start || '') + '</p></div>'
      + '<div class="hdlv2-form-body">'
      + '<p style="font-size:13px;color:#444;margin:0 0 12px;line-height:1.6;">Use these prompts as memory aids \u2014 you don\u2019t need to answer each one. Just share what comes to mind.</p>'
      + '<ul style="font-size:13px;color:#666;margin:0 0 16px;padding-left:20px;line-height:1.7;">';
    PROMPTS.forEach(function (p) { html += '<li>' + p + '</li>'; });
    html += '</ul><div id="hdlv2-checkin-audio"></div></div></div>';
    root.innerHTML = html;

    var audioEl = document.getElementById('hdlv2-checkin-audio');
    if (audioEl && window.HDLAudioComponent) {
      HDLAudioComponent.create(audioEl, {
        contextType: 'weekly_checkin',
        apiBase: CFG.api_base.replace('/checkin', '/audio'),
        nonce: CFG.nonce,
        token: token,
        onConfirm: function (summary) { submitCheckin(summary); }
      });
    }
  }

  function submitCheckin(summary) {
    root.innerHTML = '<div class="hdlv2-card"><div class="hdlv2-loading"><div class="hdlv2-spinner"></div><p style="color:#888;">Saving your check-in...</p></div></div>';
    fetch(CFG.api_base + '/submit', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify({ token: token, audio_summary: summary })
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) { renderReview(res); }
        else { root.innerHTML = '<div class="hdlv2-card"><p style="color:#dc2626;">' + (res.message || 'Error') + '</p></div>'; }
      })
      .catch(function () { root.innerHTML = '<div class="hdlv2-card"><p style="color:#dc2626;">Connection error.</p></div>'; });
  }

  function renderReview(checkin) {
    var summary = typeof checkin.summary === 'string' ? checkin.summary : JSON.stringify(checkin.summary, null, 2);
    var flags = checkin.has_flags ? '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#dc2626;font-weight:500;">\u26a0\ufe0f Flags detected \u2014 your practitioner will be notified.</div>' : '';

    root.innerHTML = '<div class="hdlv2-card">'
      + '<div class="hdlv2-header"><h2>Your Check-in Summary</h2><p>Please review and confirm.</p></div>'
      + '<div class="hdlv2-form-body">'
      + flags
      + '<div style="background:#f8f9fb;border:1px solid #e4e6ea;border-radius:8px;padding:14px;font-size:13px;line-height:1.6;color:#444;white-space:pre-wrap;max-height:400px;overflow-y:auto;">' + esc(summary) + '</div>'
      + '<div style="display:flex;gap:8px;margin-top:14px;">'
      + '<button id="hdlv2-ci-confirm" class="hdlv2-btn" style="flex:1;">Confirm Check-in</button>'
      + '</div></div></div>';

    document.getElementById('hdlv2-ci-confirm').addEventListener('click', function () {
      this.disabled = true; this.textContent = 'Confirming...';
      fetch(CFG.api_base + '/confirm', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({ token: token, checkin_id: checkin.checkin_id || checkin.id })
      })
        .then(function (r) { return r.json(); })
        .then(function (res) { if (res.success) renderDone(); })
        .catch(function () {});
    });
  }

  function renderDone() {
    root.innerHTML = '<div class="hdlv2-card"><div class="hdlv2-result" style="text-align:center;padding:40px 20px;">'
      + '<div style="font-size:48px;margin-bottom:12px;">\u2705</div>'
      + '<h3 style="margin:0 0 8px;font-size:18px;color:#111;">Check-in saved</h3>'
      + '<p style="font-size:14px;color:#888;margin:0;">Your practitioner has been notified. See you next week!</p>'
      + '</div></div>';
  }

  function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
