/**
 * HDL V2 Practitioner Consultation Interface
 *
 * Split-screen: draft report (left) + consultation tools (right).
 * Inline field editing, recommendation management, notes auto-save,
 * and Final Report generation trigger.
 *
 * Requires: hdlv2-speedometer.js
 *
 * @package HDL_Longevity_V2
 * @since 0.6.0
 */
(function () {
  'use strict';

  var CFG = window.hdlv2_consult || {};
  if (!CFG.api_base) return;

  var root = document.getElementById('hdlv2-consultation');
  if (!root) return;

  var state = {
    progressId: null,
    consultId: null,
    data: null,
    notesSaveTimer: null,
    hasChanges: false
  };

  var CATEGORIES = ['Diet', 'Exercise', 'Sleep', 'Stress', 'Supplements', 'Medical Follow-up', 'Lifestyle', 'Other'];
  var PRIORITIES = ['High', 'Medium', 'Low'];
  var FREQUENCIES = ['Daily', 'Weekly', 'Monthly', 'As needed'];

  // ── INIT ──
  function init() {
    var params = new URLSearchParams(window.location.search);
    state.progressId = parseInt(params.get('progress_id') || params.get('client'), 10);
    if (!state.progressId) {
      root.innerHTML = '<p style="color:#888;padding:40px;text-align:center;">No client specified. Use ?progress_id=X in the URL.</p>';
      return;
    }
    showLoading('Loading consultation data...');
    loadConsultation();
  }

  function showLoading(msg) {
    root.innerHTML = '<div style="text-align:center;padding:60px;"><div class="hdlv2-spinner" style="width:36px;height:36px;border:3px solid #e4e6ea;border-top-color:#3d8da0;border-radius:50%;margin:0 auto 16px;animation:hdlv2spin 0.8s linear infinite;"></div><p style="color:#888;margin:0;">' + esc(msg || 'Loading...') + '</p></div>';
  }

  function loadConsultation() {
    fetch(CFG.api_base + '/' + state.progressId, {
      headers: { 'X-WP-Nonce': CFG.nonce }
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.code) { root.innerHTML = '<p style="color:#e74c3c;padding:40px;text-align:center;">' + esc(data.message || 'Error loading consultation') + '</p>'; return; }
      state.data = data;
      state.consultId = data.consultation.id;
      state.hasChanges = (data.consultation.health_data_changes.length > 0) || (data.consultation.typed_notes.length >= 10) || (data.consultation.recommendations.length > 0);
      renderConsultation(data);
    })
    .catch(function () { root.innerHTML = '<p style="color:#e74c3c;padding:40px;text-align:center;">Connection error. Please try again.</p>'; });
  }

  // ── MAIN RENDER ──
  function renderConsultation(data) {
    var report = data.draft_report || {};
    var calc = data.calc_result || {};
    var consult = data.consultation;

    root.innerHTML = '<div class="hdlv2-consult-layout">'
      // Left panel: draft report
      + '<div class="hdlv2-consult-left">'
      + '<div class="hdlv2-consult-header">'
      + '<h2>Draft Report: ' + esc(data.client_name || 'Client') + '</h2>'
      + '<span class="hdlv2-draft-label">DRAFT</span>'
      + '</div>'
      + (calc.rate ? '<div style="text-align:center;margin:0 0 20px;"><img src="' + HDLSpeedometer.buildUrl(calc.rate) + '" alt="Gauge" style="max-width:300px;"></div>' : '')
      + '<div class="hdlv2-consult-metrics">'
      + '<span>Rate: <strong>' + esc(calc.rate || '?') + '</strong></span>'
      + '<span>Bio Age: <strong>' + esc(calc.bio_age || '?') + '</strong></span>'
      + '<span>Age: <strong>' + esc((data.stage1_data || {}).age || '?') + '</strong></span>'
      + '</div>'
      + renderReportSection('awaken', 'Awaken \u2014 Where You Are Now', report.awaken_content)
      + renderReportSection('lift', 'Lift \u2014 What Needs to Change', report.lift_content)
      + renderReportSection('thrive', 'Thrive \u2014 What\u2019s Possible', report.thrive_content)
      + '</div>'

      // Right panel: tools
      + '<div class="hdlv2-consult-right">'
      + '<h3>Consultation Tools</h3>'

      // Health data fields
      + '<div class="hdlv2-consult-section">'
      + '<h4>Health Data <small>(click to edit)</small></h4>'
      + '<div id="hdlv2-health-fields">' + renderHealthFields(data) + '</div>'
      + '</div>'

      // Practitioner notes
      + '<div class="hdlv2-consult-section">'
      + '<h4>Notes</h4>'
      + '<textarea id="hdlv2-consult-notes" rows="6" placeholder="Add your consultation notes...">' + esc(consult.typed_notes || '') + '</textarea>'
      + '<div id="hdlv2-notes-status" class="hdlv2-save-status"></div>'
      + '</div>'

      // Audio component for consultation notes
      + '<div class="hdlv2-consult-section">'
      + '<h4>Record Consultation</h4>'
      + '<div id="hdlv2-consult-audio"></div>'
      + '</div>'

      // Recommendations
      + '<div class="hdlv2-consult-section">'
      + '<h4>Recommendations</h4>'
      + '<div id="hdlv2-rec-list">' + renderRecommendations(consult.recommendations) + '</div>'
      + renderRecForm()
      + '</div>'

      // Generate button
      + '<div class="hdlv2-consult-section" style="margin-top:24px;">'
      + '<button id="hdlv2-generate-final" class="hdlv2-btn hdlv2-consult-generate-btn"' + (state.hasChanges ? '' : ' disabled') + '>Generate Final Report</button>'
      + '<p style="font-size:11px;color:#aaa;margin:8px 0 0;text-align:center;">Edit a field, add notes, or add a recommendation to enable</p>'
      + '</div>'

      + '</div></div>';

    // Bind events
    bindHealthFieldEdits();
    bindNotesAutoSave();
    bindRecForm();
    bindGenerateButton();

    // Initialize audio component for consultation recording
    var audioEl = document.getElementById('hdlv2-consult-audio');
    if (audioEl && window.HDLAudioComponent) {
      HDLAudioComponent.create(audioEl, {
        contextType: 'consultation_notes',
        apiBase: CFG.api_base.replace(/\/consultation$/, '/audio'),
        nonce: CFG.nonce,
        showConsent: true,
        onConfirm: function(summary) {
          // Append AI digest to notes
          var notesEl = document.getElementById('hdlv2-consult-notes');
          if (notesEl) {
            var sep = notesEl.value.trim() ? '\n\n--- Audio Notes ---\n' : '';
            notesEl.value += sep + summary;
            notesEl.dispatchEvent(new Event('input'));
          }
          state.hasChanges = true;
          var genBtn = document.getElementById('hdlv2-generate-final');
          if (genBtn) genBtn.disabled = false;
        }
      });
    }
  }

  function renderReportSection(cls, title, content) {
    return '<div class="hdlv2-report-section ' + cls + '">'
      + '<h3>' + title + '</h3>'
      + '<div>' + (content || '<p style="color:#aaa;">Content pending.</p>') + '</div>'
      + '</div>';
  }

  // ── HEALTH FIELDS ──
  function renderHealthFields(data) {
    var s1 = data.stage1_data || {};
    var s3 = data.stage3_data || {};
    var changes = data.consultation.health_data_changes || [];
    var changedFields = {};
    changes.forEach(function (c) { changedFields[c.field] = c; });

    var fields = [
      { key: 'q1_age', label: 'Age', stage: 1 },
      { key: 'q1_sex', label: 'Sex', stage: 1 },
      { key: 'height', label: 'Height (cm)', stage: 3 },
      { key: 'weight', label: 'Weight (kg)', stage: 3 },
      { key: 'waist', label: 'Waist (cm)', stage: 3 },
      { key: 'hip', label: 'Hip (cm)', stage: 3 },
      { key: 'bpSystolic', label: 'Systolic BP', stage: 3 },
      { key: 'bpDiastolic', label: 'Diastolic BP', stage: 3 },
      { key: 'restingHeartRate', label: 'Resting HR', stage: 3 },
      { key: 'overallHealthPercent', label: 'Overall Health %', stage: 3 },
      { key: 'physicalActivity', label: 'Physical Activity', stage: 3 },
      { key: 'sitToStand', label: 'Sit-to-Stand', stage: 3 },
      { key: 'breathHold', label: 'Breath Hold', stage: 3 },
      { key: 'balance', label: 'Balance', stage: 3 },
      { key: 'skinElasticity', label: 'Skin Elasticity', stage: 3 },
      { key: 'sleepDuration', label: 'Sleep Duration', stage: 3 },
      { key: 'sleepQuality', label: 'Sleep Quality', stage: 3 },
      { key: 'stressLevels', label: 'Stress', stage: 3 },
      { key: 'socialConnections', label: 'Social', stage: 3 },
      { key: 'cognitiveActivity', label: 'Cognitive', stage: 3 },
      { key: 'dietQuality', label: 'Diet', stage: 3 },
      { key: 'alcoholConsumption', label: 'Alcohol', stage: 3 },
      { key: 'smokingStatus', label: 'Smoking', stage: 3 },
      { key: 'supplementIntake', label: 'Supplements', stage: 3 },
      { key: 'sunlightExposure', label: 'Sunlight', stage: 3 },
      { key: 'dailyHydration', label: 'Hydration', stage: 3 }
    ];

    var h = '';
    fields.forEach(function (f) {
      var val = (f.stage === 1 ? s1[f.key] : s3[f.key]) || '';
      var changed = changedFields[f.key];
      if (changed) val = changed.new_value;
      var cls = changed ? ' hdlv2-consult-field-changed' : '';
      h += '<div class="hdlv2-consult-field-row' + cls + '" data-field-key="' + f.key + '">'
        + '<span class="hdlv2-consult-field-label">' + esc(f.label) + '</span>'
        + '<span class="hdlv2-consult-field-value">' + esc(val === 'skip' ? '\u2014' : val) + '</span>'
        + '<button type="button" class="hdlv2-consult-edit-btn" data-field="' + f.key + '" title="Edit">\u270F\uFE0F</button>'
        + (changed ? '<small class="hdlv2-consult-change-note">' + esc(changed.original) + ' \u2192 ' + esc(changed.new_value) + '</small>' : '')
        + '</div>';
    });
    return h;
  }

  function bindHealthFieldEdits() {
    root.querySelectorAll('.hdlv2-consult-edit-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var field = btn.getAttribute('data-field');
        var row = btn.closest('.hdlv2-consult-field-row');
        var valSpan = row.querySelector('.hdlv2-consult-field-value');
        var currentVal = valSpan.textContent.trim();
        if (currentVal === '\u2014') currentVal = '';

        row.innerHTML = '<span class="hdlv2-consult-field-label">' + esc(row.querySelector('.hdlv2-consult-field-label').textContent) + '</span>'
          + '<input type="text" class="hdlv2-consult-field-input" value="' + esc(currentVal) + '" style="width:80px;padding:4px 8px;border:1px solid #3d8da0;border-radius:6px;font-size:13px;">'
          + '<input type="text" class="hdlv2-consult-reason-input" placeholder="Reason (optional)" style="width:120px;padding:4px 8px;border:1px solid #e4e6ea;border-radius:6px;font-size:12px;margin-left:4px;">'
          + '<button type="button" class="hdlv2-consult-save-edit" style="margin-left:4px;padding:4px 10px;background:#3d8da0;color:#fff;border:none;border-radius:6px;font-size:12px;cursor:pointer;">Save</button>'
          + '<button type="button" class="hdlv2-consult-cancel-edit" style="margin-left:2px;padding:4px 10px;background:#eee;border:none;border-radius:6px;font-size:12px;cursor:pointer;">Cancel</button>';

        row.querySelector('.hdlv2-consult-save-edit').addEventListener('click', function () {
          var newVal = row.querySelector('.hdlv2-consult-field-input').value.trim();
          var reason = row.querySelector('.hdlv2-consult-reason-input').value.trim();
          saveFieldEdit(field, newVal, reason);
        });

        row.querySelector('.hdlv2-consult-cancel-edit').addEventListener('click', function () {
          document.getElementById('hdlv2-health-fields').innerHTML = renderHealthFields(state.data);
          bindHealthFieldEdits();
        });

        row.querySelector('.hdlv2-consult-field-input').focus();
      });
    });
  }

  function saveFieldEdit(field, newValue, reason) {
    fetch(CFG.api_base + '/edit-field', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify({ consultation_id: state.consultId, progress_id: state.progressId, field: field, new_value: newValue, reason: reason })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
      if (res.success) {
        state.data.consultation.health_data_changes = res.changes;
        state.hasChanges = true;
        updateGenerateButton();
        document.getElementById('hdlv2-health-fields').innerHTML = renderHealthFields(state.data);
        bindHealthFieldEdits();
      }
    });
  }

  // ── NOTES AUTO-SAVE ──
  function bindNotesAutoSave() {
    var ta = document.getElementById('hdlv2-consult-notes');
    if (!ta) return;
    ta.addEventListener('input', function () {
      if (state.notesSaveTimer) clearTimeout(state.notesSaveTimer);
      state.notesSaveTimer = setTimeout(function () { saveNotes(ta.value); }, 30000);
      if (ta.value.trim().length >= 10) { state.hasChanges = true; updateGenerateButton(); }
    });
    ta.addEventListener('blur', function () {
      if (state.notesSaveTimer) clearTimeout(state.notesSaveTimer);
      saveNotes(ta.value);
    });
  }

  function saveNotes(text) {
    var statusEl = document.getElementById('hdlv2-notes-status');
    if (statusEl) { statusEl.className = 'hdlv2-save-status saving'; statusEl.textContent = 'Saving...'; }
    fetch(CFG.api_base + '/save-notes', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify({ consultation_id: state.consultId, typed_notes: text })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
      if (statusEl) { statusEl.className = 'hdlv2-save-status saved'; statusEl.textContent = 'Saved'; setTimeout(function () { statusEl.textContent = ''; statusEl.className = 'hdlv2-save-status'; }, 3000); }
    })
    .catch(function () {
      if (statusEl) { statusEl.className = 'hdlv2-save-status error'; statusEl.textContent = 'Save failed'; }
    });
  }

  // ── RECOMMENDATIONS ──
  function renderRecommendations(recs) {
    if (!recs || !recs.length) return '<p style="color:#aaa;font-size:13px;">No recommendations yet.</p>';
    var h = '';
    recs.forEach(function (rec, i) {
      h += '<div class="hdlv2-consult-rec-card">'
        + '<span class="hdlv2-consult-rec-badge">' + esc(rec.category) + '</span>'
        + '<span class="hdlv2-consult-rec-priority ' + (rec.priority || '').toLowerCase() + '">' + esc(rec.priority) + '</span>'
        + '<p>' + esc(rec.text) + '</p>'
        + '<small>' + esc(rec.frequency || '') + '</small>'
        + '<button type="button" class="hdlv2-consult-rec-remove" data-index="' + i + '" title="Remove">\u2715</button>'
        + '</div>';
    });
    return h;
  }

  function renderRecForm() {
    return '<div class="hdlv2-consult-rec-form">'
      + '<select id="hdlv2-rec-cat"><option value="">Category...</option>' + CATEGORIES.map(function (c) { return '<option value="' + c + '">' + c + '</option>'; }).join('') + '</select>'
      + '<textarea id="hdlv2-rec-text" rows="2" placeholder="Recommendation..."></textarea>'
      + '<div style="display:flex;gap:8px;margin:6px 0;">'
      + '<div>' + PRIORITIES.map(function (p, i) { return '<label style="font-size:12px;margin-right:8px;"><input type="radio" name="hdlv2-rec-pri" value="' + p + '"' + (i === 1 ? ' checked' : '') + '> ' + p + '</label>'; }).join('') + '</div>'
      + '<select id="hdlv2-rec-freq">' + FREQUENCIES.map(function (f) { return '<option value="' + f + '">' + f + '</option>'; }).join('') + '</select>'
      + '</div>'
      + '<button type="button" id="hdlv2-add-rec" class="hdlv2-btn-secondary" style="font-size:13px;padding:8px 20px;">Add Recommendation</button>'
      + '</div>';
  }

  function bindRecForm() {
    var addBtn = document.getElementById('hdlv2-add-rec');
    if (!addBtn) return;
    addBtn.addEventListener('click', function () {
      var cat = document.getElementById('hdlv2-rec-cat').value;
      var text = document.getElementById('hdlv2-rec-text').value.trim();
      var pri = (root.querySelector('input[name="hdlv2-rec-pri"]:checked') || {}).value || 'Medium';
      var freq = document.getElementById('hdlv2-rec-freq').value;
      if (!text) return;

      fetch(CFG.api_base + '/add-recommendation', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({ consultation_id: state.consultId, category: cat, text: text, priority: pri, frequency: freq })
      })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) {
          state.data.consultation.recommendations = res.recommendations;
          state.hasChanges = true;
          updateGenerateButton();
          document.getElementById('hdlv2-rec-list').innerHTML = renderRecommendations(res.recommendations);
          bindRecRemoveButtons();
          document.getElementById('hdlv2-rec-text').value = '';
          document.getElementById('hdlv2-rec-cat').value = '';
        }
      });
    });

    bindRecRemoveButtons();
  }

  function bindRecRemoveButtons() {
    root.querySelectorAll('.hdlv2-consult-rec-remove').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var index = parseInt(btn.getAttribute('data-index'), 10);
        fetch(CFG.api_base + '/remove-recommendation', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
          body: JSON.stringify({ consultation_id: state.consultId, index: index })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.success) {
            state.data.consultation.recommendations = res.recommendations;
            document.getElementById('hdlv2-rec-list').innerHTML = renderRecommendations(res.recommendations);
            bindRecRemoveButtons();
          }
        });
      });
    });
  }

  // ── GENERATE FINAL REPORT ──
  function updateGenerateButton() {
    var btn = document.getElementById('hdlv2-generate-final');
    if (btn) btn.disabled = !state.hasChanges;
  }

  function bindGenerateButton() {
    var btn = document.getElementById('hdlv2-generate-final');
    if (!btn) return;
    btn.addEventListener('click', function () {
      if (!confirm('Generate the Final Report for ' + (state.data.client_name || 'this client') + '? This will replace the Draft.')) return;
      btn.disabled = true;
      btn.textContent = 'Generating...';

      // Save notes first
      var notes = document.getElementById('hdlv2-consult-notes');
      if (notes) saveNotes(notes.value);

      fetch(CFG.api_base + '/finalise', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({ progress_id: state.progressId, consultation_id: state.consultId })
      })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) {
          renderFinalReport(res);
        } else {
          btn.disabled = false;
          btn.textContent = 'Generate Final Report';
          alert(res.message || 'Generation failed. Please try again.');
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Generate Final Report';
        alert('Connection error. Please try again.');
      });
    });
  }

  function renderFinalReport(res) {
    var calc = res.calc_result || {};
    var ms = res.milestones || {};

    root.innerHTML = '<div style="max-width:800px;margin:0 auto;padding:0 16px;">'
      + '<div style="text-align:center;margin:0 0 24px;">'
      + '<h2 style="font-family:Poppins,sans-serif;margin:0 0 8px;">Final Longevity Report</h2>'
      + '<span style="background:#10b981;color:#fff;font-size:11px;font-weight:700;padding:4px 12px;border-radius:4px;text-transform:uppercase;letter-spacing:1px;">FINAL</span>'
      + '</div>'
      + (calc.rate ? '<div style="text-align:center;margin:0 0 20px;"><img src="' + HDLSpeedometer.buildUrl(calc.rate) + '" alt="Gauge" style="max-width:340px;"></div>' : '')
      + '<div style="text-align:center;margin:0 0 24px;font-size:15px;color:#444;">'
      + 'Rate: <strong>' + esc(calc.rate || '?') + '</strong> &mdash; Bio Age: <strong>' + esc(calc.bio_age || '?') + '</strong>'
      + '</div>'
      + '<div class="hdlv2-report-section awaken"><h3>Awaken \u2014 Where You Are Now</h3><div>' + (res.awaken_content || '') + '</div></div>'
      + '<div class="hdlv2-report-section lift"><h3>Lift \u2014 What Needs to Change</h3><div>' + (res.lift_content || '') + '</div></div>'
      + '<div class="hdlv2-report-section thrive"><h3>Thrive \u2014 What\u2019s Possible</h3><div>' + (res.thrive_content || '') + '</div></div>'
      + renderMilestones(ms)
      + '<p style="text-align:center;color:#aaa;font-size:13px;margin:24px 0;">The client and PDF will be delivered via email shortly.</p>'
      + '</div>';
  }

  function renderMilestones(ms) {
    if (!ms || !ms.six_months) return '';
    var intervals = [
      { key: 'six_months', label: '6 Months', color: '#3d8da0' },
      { key: 'two_years', label: '2 Years', color: '#10b981' },
      { key: 'five_years', label: '5 Years', color: '#f59e0b' },
      { key: 'ten_plus_years', label: '10+ Years', color: '#e74c3c' }
    ];
    var h = '<div style="margin:24px 0;"><h3 style="font-family:Poppins,sans-serif;text-align:center;margin:0 0 16px;">Milestone Timeline</h3>';
    intervals.forEach(function (iv) {
      var items = ms[iv.key] || [];
      h += '<div style="border-left:4px solid ' + iv.color + ';padding:12px 18px;margin:0 0 12px;background:#fafbfc;border-radius:0 8px 8px 0;">'
        + '<strong style="color:' + iv.color + ';">' + iv.label + '</strong><ul style="margin:8px 0 0;padding-left:20px;">';
      items.forEach(function (m) { h += '<li style="margin:4px 0;font-size:14px;color:#444;">' + esc(m.milestone || m) + '</li>'; });
      h += '</ul></div>';
    });
    return h + '</div>';
  }

  // ── UTILS ──
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;'); }

  // ── FONTS ──
  if (!document.getElementById('hdlv2-form-fonts')) {
    var link = document.createElement('link');
    link.id = 'hdlv2-form-fonts'; link.rel = 'stylesheet';
    link.href = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap';
    document.head.appendChild(link);
  }

  // ── START ──
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
