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
    // v0.34.3 — captured at load + after every successful regen so the
    // post-/save-and-update-plan re-verify step can detect "the new Final
    // Report row was actually written" by id change. Stops the prior
    // fake-success flow where the success card rendered even when the
    // server-side INSERT silently failed (Phase N pre-existing damage).
    previousFinalId: null
  };

  var CATEGORIES = ['Diet', 'Exercise', 'Sleep', 'Stress', 'Supplements', 'Medical Follow-up', 'Lifestyle', 'Other'];
  var PRIORITIES = ['High', 'Medium', 'Low'];
  var FREQUENCIES = ['Daily', 'Weekly', 'Monthly', 'As needed'];

  // ── INIT ──
  // v0.25.2 — branches on ?progress_id:
  //   - present  → existing editor flow (loadConsultation → renderConsultation)
  //   - missing  → new list view (loadList → renderList) at /consultation/
  // The list view is a thin "index of consultations" the practitioner sees
  // when landing on /consultation/ without a client selected. Page-level
  // 404 for non-practitioner / non-admin users is handled server-side via
  // template_redirect (HDLV2_Consultation::maybe_404_unauthorised).
  function init() {
    var params = new URLSearchParams(window.location.search);
    state.progressId = parseInt(params.get('progress_id') || params.get('client'), 10);
    if (state.progressId) {
      showLoading('Loading consultation data...');
      loadConsultation();
    } else {
      showLoading('Loading consultations...');
      loadList();
    }
  }

  function showLoading(msg) {
    // v0.37.0 — shaped skeleton replaces blank spinner during the 2-5s mount.
    // The skeleton mirrors the consultation layout (single content card with
    // a heading, paragraph lines, and a body block) so the user sees the
    // shape of what's loading rather than a void. Falls back to the original
    // spinner when HDLV2Loading isn't on the page (cached pages mid-deploy).
    if (window.HDLV2Loading && typeof HDLV2Loading.skeleton === 'function') {
      root.innerHTML = HDLV2Loading.skeleton('consultation');
    } else {
      root.innerHTML = '<div style="text-align:center;padding:60px;"><div class="hdlv2-spinner" style="width:36px;height:36px;border:3px solid #e4e6ea;border-top-color:#3d8da0;border-radius:50%;margin:0 auto 16px;animation:hdlv2spin 0.8s linear infinite;"></div><p style="color:#888;margin:0;">' + esc(msg || 'Loading...') + '</p></div>';
    }
  }

  function loadConsultation() {
    fetch(CFG.api_base + '/' + state.progressId, {
      headers: { 'X-WP-Nonce': CFG.nonce }
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.code) { root.innerHTML = '<p style="color:#dc2626;padding:40px;text-align:center;">' + esc(data.message || 'Error loading consultation') + '</p>'; return; }
      state.data = data;
      state.consultId = data.consultation.id;
      // v0.34.3 — capture the current Final id so the post-regen re-verify
      // can confirm a fresh row was written (id mismatch in UPDATE-mode is
      // unchanged-but-content-refreshed; we also check generated_at).
      state.previousFinalId = data.final_report && data.final_report.id ? data.final_report.id : null;

      // v0.28.2 — render branches by final-report state. Pre-Final shows the
      // Consultation Notes textarea + "Generate Final Report" / "Looks good
      // — Generate Report" buttons (the original first-pass flow). Post-
      // Final hides those (their job is done) and surfaces only: the
      // editable AI summary (auto-saves silently) + the Consultation
      // Addenda timeline + entry form + single "Save & Update Plan" CTA.
      // The orange "Save and Re-generate" banner was removed in this
      // version — its role is fully covered by Save & Update Plan.
      renderConsultation(data);
    })
    .catch(function () { root.innerHTML = '<p style="color:#dc2626;padding:40px;text-align:center;">Connection error. Please try again.</p>'; });
  }

  // ── MAIN RENDER ──
  function renderConsultation(data) {
    var consult = data.consultation;

    // v0.22.7 — Practitioner left panel now mounts the SAME premium
    // draft-report renderer the client sees on /longevity-draft-report/.
    // No more divergence: identical hero, 3-stat strip, trajectory chart,
    // 21-metric radar, and awaken/lift/thrive narrative. Renderer is
    // bootstrapped after this innerHTML assignment via window.HDLV2DraftRenderer.
    root.innerHTML = '<div class="hdlv2-consult-layout">'
      + '<div class="hdlv2-consult-left">'
      + '<div id="hdlv2-draft-report-root" class="hdlv2-dr">'
      +   '<div class="hdlv2-dr-loading" data-hdlv2-state="loading">'
      +     '<div class="hdlv2-dr-loading-icon"></div>'
      +     '<h3>Loading client report</h3>'
      +     '<p>Same layout your client sees<span class="hdlv2-dr-loading-dots"></span></p>'
      +   '</div>'
      + '</div>'
      + '</div>'

      // Right panel: tools
      + '<div class="hdlv2-consult-right">'
      + '<h3>Consultation Tools</h3>'

      // Health data fields
      + '<div class="hdlv2-consult-section">'
      + '<h4>Health Data <small>(click to edit)</small></h4>'
      + '<div id="hdlv2-health-fields">' + renderHealthFields(data) + '</div>'
      + '</div>'

      // Client Brief — pre-consultation snapshot from client data + scores.
      // Collapsible. Loads from cache or generates on first open via the
      // REST endpoint (practitioner sees a spinner in this card only).
      + '<div class="hdlv2-consult-section hdlv2-client-brief">'
      +   renderClientBrief(data)
      + '</div>'

      // Consultation Notes — pre-Final only. Post-Final the AI summary in
      // the action wrap below is the canonical view; the raw textarea
      // disappears so the practitioner has a single, unambiguous source of
      // truth for the consultation content. (v0.28.2)
      + (data.final_report ? '' : (
          '<div class="hdlv2-consult-section">'
          + '<h4>Consultation Notes</h4>'
          + '<div class="hdlv2-consult-notes-bar">'
          +   '<textarea id="hdlv2-consult-notes" class="hdlv2-consult-notes-input" rows="10" placeholder="Write your consultation notes here — observations, health history, recommendations, follow-ups. You can also tap the mic to record, or attach an audio file.">' + esc(consult.raw_notes || consult.typed_notes || '') + '</textarea>'
          +   '<div class="hdlv2-consult-notes-iconbar" id="hdlv2-consult-audio"></div>'
          + '</div>'
          + '<div class="hdlv2-addendum-reminder" role="note">'
          +   '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>'
          +   '<span><strong>Before you stop the recording</strong> — read out any notes you wrote on the FLIGHT sheet so they\'re captured in the transcript.</span>'
          + '</div>'
          + '<div id="hdlv2-notes-status" class="hdlv2-save-status"></div>'
          + '</div>'
        ))

      // Action wrap — pre-Final shows the textarea-stage button or the
      // review-panel "Looks good — Generate Report" button. Post-Final
      // shows the editable AI summary alone (no button). (v0.28.2)
      + '<div class="hdlv2-consult-section" id="hdlv2-action-wrap">'
      +   renderActionStage(consult)
      + '</div>'

      // v0.28.0 — Consultation Addenda. Rendered only after a Final Report
      // exists (addenda are post-Final practitioner observations). The
      // section contains the past-addenda timeline + an entry form with
      // mic + audio-upload icons. Clicking "Save & Update Plan" writes a
      // new addendum + re-fires generate() so the AI re-issues awaken/
      // lift/thrive + recommendations + milestones + Flight Plan with the
      // full addenda chain as latest intelligence.
      + '<div class="hdlv2-consult-section hdlv2-addenda-wrap" id="hdlv2-addenda-wrap">'
      +   renderAddendaSection(data)
      + '</div>'

      + '</div></div>';

    // Bind events
    bindHealthFieldEdits();
    bindNotesAutoSave();
    bindActionButton();
    bindRefreshBrief();
    bindBriefAutoSaveListeners();   // v0.33.1 — Brief is now inline-editable
    bindAddendumForm();

    // v0.22.7 — Mount the SAME premium draft-report renderer the client
    // sees on /longevity-draft-report/?t=<token>. Self-contained: it polls
    // /reports/draft via REST, draws charts, paints the narrative. No data
    // mapping required — the renderer fetches its own canonical payload.
    var draftRoot = document.getElementById('hdlv2-draft-report-root');
    if (draftRoot && window.HDLV2DraftRenderer && data.token) {
      window.HDLV2DraftRenderer.start(draftRoot, data.token);
    }

    // Initialize audio component for consultation recording
    var audioEl = document.getElementById('hdlv2-consult-audio');
    if (audioEl && window.HDLAudioComponent) {
      // liveInsertPoint — captured at record-start so live text always appends
      // AFTER whatever the practitioner had typed before recording. Protects
      // their typed edits from being overwritten by the live stream.
      var liveInsertPoint = null;

      HDLAudioComponent.create(audioEl, {
        contextType: 'consultation_notes',
        apiBase: CFG.api_base.replace(/\/consultation$/, '/audio'),
        nonce: CFG.nonce,
        showConsent: false,
        // v0.30.0 — preloadOnIdle removed. Consultation uses asyncUpload:true
        // (server-side Deepgram); the in-browser Whisper model is never
        // invoked here, so eagerly downloading ~75 MB on every page load
        // burned bandwidth, threw "Failed to create WebGPU Context Provider"
        // into the console, and competed with the actual recording upload.
        iconsOnlyMode: true,
        useAsIsOnly: true,
        // v0.17.0 — uploaded files go server-side via Deepgram instead of
        // browser Whisper. Required because a 1-hour consultation recording
        // would hit the 120s browser-Whisper timeout. Live recording
        // (via mic) is unaffected — it still uses the browser Speech API.
        asyncUpload: true,
        referenceId: state.consultId,
        onLiveTranscript: function(combined) {
          // Real-time streaming from Web Speech API (Chrome/Safari/sometimes Brave).
          var notesEl = document.getElementById('hdlv2-consult-notes');
          if (!notesEl) return;
          if (liveInsertPoint === null) {
            var base = notesEl.value;
            liveInsertPoint = base.length + (base && !base.endsWith('\n') ? 2 : 0);
            if (base && !base.endsWith('\n')) notesEl.value = base + '\n\n';
          }
          notesEl.value = notesEl.value.substring(0, liveInsertPoint) + (combined || '');
          notesEl.scrollTop = notesEl.scrollHeight;
          notesEl.dispatchEvent(new Event('input'));
        },
        onConfirm: function(transcript) {
          // Final transcript lands here (Whisper path, or Web Speech stop).
          var notesEl = document.getElementById('hdlv2-consult-notes');
          if (!notesEl) return;
          if (liveInsertPoint !== null) {
            // Replace live buffer with final (already-streamed) transcript.
            notesEl.value = notesEl.value.substring(0, liveInsertPoint) + (transcript || '');
            liveInsertPoint = null;
          } else {
            var sep = notesEl.value.trim() ? '\n\n' : '';
            notesEl.value += sep + transcript;
          }
          notesEl.scrollTop = notesEl.scrollHeight;
          notesEl.dispatchEvent(new Event('input'));
        }
      });
    }
  }

  // ── ACTION STAGE (v0.15.1, v0.24.8) — single button → auto-organise →
  //   review → final report. The practitioner sees ONE primary action at a
  //   time. Stages tracked in state.actionStage:
  //     'edit'   — write notes, click Generate Final Report
  //     'review' — editable summary shown, auto-saves on every change
  //   v0.24.8 — auto-resume on page load: if the practitioner has an AI
  //   summary from a prior session, land on the review panel so they can
  //   pick up where they left off without re-burning AI tokens.
  function renderActionStage(consult) {
    var org = (consult && consult.ai_organised_notes) || null;
    // v0.24.11 — B1 fix: don't auto-resume to review if the saved object is
    // present but has no populated sections (legacy empty-payload edge case).
    // Falls through to the textarea-edit stage so the practitioner sees a
    // useful entry point instead of a blank review panel.
    var orgIsMeaningful = org && (
      (typeof org.health_summary === 'string'   && org.health_summary.trim()) ||
      (typeof org.health_history === 'string'   && org.health_history.trim()) ||
      (typeof org.additional_notes === 'string' && org.additional_notes.trim()) ||
      (Array.isArray(org.recommendations)   && org.recommendations.length) ||
      (Array.isArray(org.follow_up_actions) && org.follow_up_actions.length)
    );
    if (!state.actionStage) {
      state.actionStage = orgIsMeaningful ? 'review' : 'edit';
    }
    if (state.actionStage === 'review' && orgIsMeaningful) {
      return renderReviewPanel(org);
    }

    // v0.28.2 — defensive: if a Final Report exists but ai_organised_notes
    // somehow lacks meaningful content (rare edge case after a manual
    // reset), fall back to a minimal placeholder rather than the original
    // "Generate Final Report" button — that button hits the duplicate
    // guard in HDLV2_Final_Report::generate() and is a flat no-op.
    if (state.data && state.data.final_report) {
      return '<p class="hdlv2-consult-hint">Trajectory Plan already generated. Use the <strong>Consultation Addenda</strong> section below to add follow-up notes and refresh the plan.</p>';
    }

    return '<button id="hdlv2-action-btn" class="hdlv2-btn hdlv2-consult-generate-btn" type="button">Generate Trajectory Plan</button>'
      + '<p class="hdlv2-consult-hint">Your notes will be organised into a structured summary for the report.</p>';
  }

  function renderReviewPanel(org) {
    var recsArr = Array.isArray(org.recommendations) ? org.recommendations : [];
    var recs = recsArr.map(function (r, i) {
      var cat = r.category || '';
      if (r.secondary_category) cat += ' (also ' + r.secondary_category + ')';
      return '<li class="hdlv2-organised-rec" data-idx="' + i + '">'
        +   '<div class="hdlv2-organised-rec-text" contenteditable="true">' + esc(r.text || '') + '</div>'
        +   '<div class="hdlv2-organised-rec-meta">'
        +     '<span class="tag">' + esc(cat || 'Other') + '</span>'
        +     '<span class="tag priority-' + esc((r.priority || 'Medium').toLowerCase()) + '">' + esc(r.priority || 'Medium') + '</span>'
        +     '<span class="tag">' + esc(r.frequency || 'Not specified') + '</span>'
        +     '<button type="button" class="hdlv2-organised-rec-remove" data-idx="' + i + '" title="Remove recommendation" aria-label="Remove recommendation">✕</button>'
        +   '</div>'
        + '</li>';
    }).join('');
    var followUps = (Array.isArray(org.follow_up_actions) ? org.follow_up_actions : []).map(function (a) {
      return '<li contenteditable="true">' + esc(a) + '</li>';
    }).join('');

    // v0.24.8 — head row carries an inline auto-save status pill
    // (empty → editing… → saving… → saved 14:32 → save failed).
    // v0.32.5 — head row also carries a "Re-organise" link as an escape
    // hatch when the AI organisation came out poor (e.g. praise-only notes
    // yielding empty recommendations[]). Suppressed post-Final because at
    // that point the regen flow is owned by the Addenda section's
    // "Save & Update Plan" CTA.
    // v0.33.5 — Re-organise visible both pre- and post-Final. Post-Final
    // practitioners need this as a recovery path when /organise produced
    // empty recs and they don't want to add an addendum just to fix it.
    // v0.33.6 — ↻ Re-organise removed from default UI. Auto-re-organise
    // inside regenerate() handles every Save & Update Plan automatically;
    // the manual button is no longer needed in the happy path. The handler
    // (bindReorganiseButton) still exists and is rendered on demand by the
    // recovery panel that appears if the L1 empty_recs error fires (rare
    // with auto-re-organise + L3 retry safeguards).
    var html = '<div class="hdlv2-organised-head">'
      +   '<h4>Review Your Consultation Summary</h4>'
      +   '<span id="hdlv2-organised-savestatus" class="hdlv2-save-pill" aria-live="polite"></span>'
      + '</div>'
      + '<p class="hdlv2-consult-hint hdlv2-consult-hint-emphasis">Draft consultation summary — edit anything you need to change in here. Your edits save automatically.</p>';

    if (org.health_summary) {
      html += '<div class="hdlv2-organised-sec"><h5>Consultation Summary</h5>'
        +   '<div class="hdlv2-organised-body hdlv2-rich-text" data-field="health_summary" contenteditable="true">' + formatRichText(org.health_summary) + '</div>'
        + '</div>';
    }
    if (org.health_history) {
      html += '<div class="hdlv2-organised-sec"><h5>Health History</h5>'
        +   '<div class="hdlv2-organised-body hdlv2-rich-text" data-field="health_history" contenteditable="true">' + formatRichText(org.health_history) + '</div>'
        + '</div>';
    }
    // v0.32.5 — ALWAYS render the Recommendations section, even when empty,
    // so practitioners can manually add cards when Claude extracted none.
    // The hidden-when-empty pattern in v0.15.x silently trapped Kim
    // 2026-05-05 (praise-only notes → empty recs[] → no section visible →
    // practitioner had no signal that anything was missing).
    //
    // v0.32.9 — gate the "+ Add a recommendation" button + the empty-state
    // hint to PRE-Final only. Post-Final, the canonical add-something path
    // is the addendum (timestamped, audit-trailed, matches Matthew's
    // never-delete-history / always-additive-with-timestamps rule —
    // 2026-04-30 transcript). Showing both buttons made them read as
    // duplicates and was the user-flagged UX gap on 2026-05-05.
    // v0.33.5 — Manual + Add a recommendation visible both pre- and post-Final.
    // Post-Final use case: practitioner sees empty Page 13 and wants to add
    // a rec inline without going through the addendum form. Both paths now
    // valid; the addenda form remains the canonical timestamped audit trail.
    // v0.33.6 — Recommendations section simplified. The default flow trusts
    // Claude (auto-re-organise on Save & Update Plan, L3 prompt mandates ≥1
    // rec). The "+ Add a recommendation" button is removed from the head;
    // the empty-state pointer copy is gone. Practitioners see existing rec
    // cards (editable inline + ✕ remove). If the L1 guard fires, the
    // recovery panel below the addendum form reveals the Add + Re-organise
    // buttons inline as fallback. Pre-Final this section may briefly show
    // empty if /organise hadn't run yet — the practitioner clicks Generate
    // Final Report which fires /organise + L3 retry to populate it.
    html += '<div class="hdlv2-organised-sec hdlv2-organised-sec--recs">'
      +   '<div class="hdlv2-organised-sec-head">'
      +     '<h5>Recommendations</h5>'
      +   '</div>'
      +   (recs
            ? '<ul class="hdlv2-organised-recs">' + recs + '</ul>'
            : '<p class="hdlv2-organised-empty">No recommendations yet. They\'ll appear after you generate the report.</p>'
         )
      +   '<div id="hdlv2-add-rec-form-wrap" class="hdlv2-organised-add-rec-wrap" hidden></div>'
      + '</div>';
    if (followUps) {
      html += '<div class="hdlv2-organised-sec"><h5>Follow-Up Actions</h5>'
        +   '<ul class="hdlv2-organised-followups">' + followUps + '</ul>'
        + '</div>';
    }
    if (org.additional_notes) {
      html += '<div class="hdlv2-organised-sec"><h5>Additional Notes</h5>'
        +   '<div class="hdlv2-organised-body hdlv2-rich-text" data-field="additional_notes" contenteditable="true">' + formatRichText(org.additional_notes) + '</div>'
        + '</div>';
    }

    // v0.28.2 — "Looks good — Generate Report" is the pre-Final primary CTA.
    // Post-Final it would call /finalise → hits the duplicate guard in
    // HDLV2_Final_Report::generate() and returns the existing report (no
    // regen, no UI feedback differentiator). Suppressed post-Final so the
    // single "Save & Update Plan" CTA at the bottom of the Addenda section
    // is the unambiguous regen action. AI summary above stays editable +
    // auto-saves silently — those edits get picked up by the next regen.
    if (!state.data || !state.data.final_report) {
      html += '<div class="hdlv2-review-actions">'
        +   '<button id="hdlv2-finalise-btn" class="hdlv2-btn hdlv2-consult-generate-btn" type="button">Looks good — Generate Report</button>'
        + '</div>';
    }

    return html;
  }

  // v0.32.5 — Inline rec-entry form (revives the dead renderRecForm
  // function from pre-v0.15.0 with a clean priority/frequency UI). Opens
  // in-place inside the Recommendations section. Posts to
  // /consultation/add-recommendation which writes into
  // ai_organised_notes['recommendations'] (single source of truth).
  var REC_CATEGORIES = ['Nutrition', 'Movement', 'Supplements', 'Lifestyle', 'Mental/Emotional', 'Sleep', 'Testing', 'Referral', 'Other'];
  var REC_PRIORITIES = ['High', 'Medium', 'Low'];
  var REC_FREQUENCIES = ['Daily', 'Weekly', 'Twice a week', '3x per week', 'One-off', 'Ongoing', 'As needed', 'Not specified'];

  function renderInlineRecForm() {
    return '<div class="hdlv2-organised-add-rec-form">'
      + '<div class="hdlv2-organised-add-rec-row">'
      +   '<select id="hdlv2-add-rec-cat" class="hdlv2-organised-add-rec-input">'
      +     '<option value="">Category…</option>'
      +     REC_CATEGORIES.map(function (c) { return '<option value="' + esc(c) + '">' + esc(c) + '</option>'; }).join('')
      +   '</select>'
      +   '<select id="hdlv2-add-rec-pri" class="hdlv2-organised-add-rec-input">'
      +     REC_PRIORITIES.map(function (p, i) { return '<option value="' + esc(p) + '"' + (i === 1 ? ' selected' : '') + '>' + esc(p) + ' priority</option>'; }).join('')
      +   '</select>'
      +   '<select id="hdlv2-add-rec-freq" class="hdlv2-organised-add-rec-input">'
      +     REC_FREQUENCIES.map(function (f, i) { return '<option value="' + esc(f) + '"' + (i === 5 ? ' selected' : '') + '>' + esc(f) + '</option>'; }).join('')
      +   '</select>'
      + '</div>'
      + '<textarea id="hdlv2-add-rec-text" class="hdlv2-organised-add-rec-text" rows="2" placeholder="Concrete next-day action — e.g. &quot;After dinner, brush teeth — kitchen closes for the night.&quot; Behaviour + frequency. Avoid slogans (&quot;focus on&quot;, &quot;manage&quot;)."></textarea>'
      + '<div class="hdlv2-organised-add-rec-actions">'
      +   '<button type="button" id="hdlv2-add-rec-cancel" class="hdlv2-btn-secondary">Cancel</button>'
      +   '<button type="button" id="hdlv2-add-rec-submit" class="hdlv2-btn hdlv2-consult-generate-btn">Add recommendation</button>'
      + '</div>'
      + '<div id="hdlv2-add-rec-status" class="hdlv2-save-status" aria-live="polite"></div>'
      + '</div>';
  }

  function bindAddRecButton() {
    // v0.32.9 — Add button is suppressed post-Final (canonical add path is
    // the addendum form), but the remove-button delegation below MUST still
    // bind so practitioners can prune cards from the next regen. Don't
    // early-return on missing openBtn — only skip the openBtn.addEventListener.
    var openBtn = document.getElementById('hdlv2-add-rec-btn');
    var wrap    = document.getElementById('hdlv2-add-rec-form-wrap');

    if (openBtn && wrap) {
      openBtn.addEventListener('click', function () {
        // Toggle: open if closed, close if open
        if (!wrap.hidden) {
          wrap.hidden = true;
          wrap.innerHTML = '';
          return;
        }
        wrap.innerHTML = renderInlineRecForm();
        wrap.hidden = false;
        var textEl = document.getElementById('hdlv2-add-rec-text');
        if (textEl) textEl.focus();
        bindInlineRecForm();
      });
    }

    // Bind remove buttons on existing rec list (delegated per-card).
    // Runs regardless of openBtn visibility so post-Final pruning still works.
    var recsList = document.querySelector('.hdlv2-organised-recs');
    if (recsList) {
      recsList.addEventListener('click', function (e) {
        var btn = e.target.closest('.hdlv2-organised-rec-remove');
        if (!btn) return;
        var idx = parseInt(btn.getAttribute('data-idx'), 10);
        if (isNaN(idx) || idx < 0) return;
        if (!confirm('Remove this recommendation?')) return;
        btn.disabled = true;
        fetch(CFG.api_base + '/remove-recommendation', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
          body: JSON.stringify({ consultation_id: state.consultId, index: idx })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res && res.success && res.ai_organised_notes) {
            state.data.consultation.ai_organised_notes = res.ai_organised_notes;
            document.getElementById('hdlv2-action-wrap').innerHTML = renderActionStage(state.data.consultation);
            bindActionButton();
            bindAddRecButton();
          } else {
            btn.disabled = false;
            window.HDLV2UI.toast('Could not remove that recommendation. Please try again.', "error");
          }
        })
        .catch(function () {
          btn.disabled = false;
          window.HDLV2UI.toast('Connection error — recommendation not removed.', "error");
        });
      });
    }
  }

  function bindInlineRecForm() {
    var submit = document.getElementById('hdlv2-add-rec-submit');
    var cancel = document.getElementById('hdlv2-add-rec-cancel');
    var wrap   = document.getElementById('hdlv2-add-rec-form-wrap');
    if (!submit || !cancel || !wrap) return;

    cancel.addEventListener('click', function () {
      wrap.hidden = true;
      wrap.innerHTML = '';
    });

    submit.addEventListener('click', function () {
      var textEl = document.getElementById('hdlv2-add-rec-text');
      var catEl  = document.getElementById('hdlv2-add-rec-cat');
      var priEl  = document.getElementById('hdlv2-add-rec-pri');
      var freqEl = document.getElementById('hdlv2-add-rec-freq');
      var statusEl = document.getElementById('hdlv2-add-rec-status');
      var text = (textEl && textEl.value || '').trim();

      if (!text) {
        if (statusEl) statusEl.textContent = 'Add the recommendation text first.';
        if (textEl) textEl.focus();
        return;
      }

      submit.disabled = true;
      submit.textContent = 'Saving…';
      if (statusEl) statusEl.textContent = '';

      fetch(CFG.api_base + '/add-recommendation', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({
          consultation_id: state.consultId,
          category:  catEl  ? catEl.value  : '',
          priority:  priEl  ? priEl.value  : 'Medium',
          frequency: freqEl ? freqEl.value : 'Ongoing',
          text:      text
        })
      })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.success && res.ai_organised_notes) {
          state.data.consultation.ai_organised_notes = res.ai_organised_notes;
          document.getElementById('hdlv2-action-wrap').innerHTML = renderActionStage(state.data.consultation);
          bindActionButton();
          bindAddRecButton();
        } else {
          submit.disabled = false;
          submit.textContent = 'Add recommendation';
          if (statusEl) statusEl.textContent = (res && res.message) || 'Could not save. Please try again.';
        }
      })
      .catch(function () {
        submit.disabled = false;
        submit.textContent = 'Add recommendation';
        if (statusEl) statusEl.textContent = 'Connection error — please try again.';
      });
    });
  }

  // v0.32.5 — Re-organise control (UX-2). Nukes ai_organised_notes via
  // /consultation/reset-organised so the consultation drops back into the
  // edit stage; the practitioner sees the raw textarea (still pre-filled)
  // and can paste new content before re-running /organise.
  function bindReorganiseButton() {
    var btn = document.getElementById('hdlv2-reorganise-btn');
    if (!btn) return;
    btn.addEventListener('click', function () {
      if (!confirm(
        'Discard the current organised summary and go back to the raw notes?\n\n' +
        'Your inline edits to summary/history/recommendations will be lost. The raw notes themselves are preserved.'
      )) return;
      // v0.32.5 — Cancel any pending auto-save so it can't fire after the
      // server has reset ai_organised_notes to NULL and re-populate it with
      // stale DOM-derived data. Race window is small but fatal when it hits.
      if (autoSaveTimer) { clearTimeout(autoSaveTimer); autoSaveTimer = null; }
      setSaveStatus('');
      btn.disabled = true;
      btn.textContent = 'Resetting…';
      fetch(CFG.api_base + '/reset-organised', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({ consultation_id: state.consultId })
      })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.success) {
          state.data.consultation.ai_organised_notes = null;
          state.data.consultation.practitioner_approved = 0;
          state.actionStage = 'edit';
          document.getElementById('hdlv2-action-wrap').innerHTML = renderActionStage(state.data.consultation);
          bindActionButton();
          // Scroll to the textarea so practitioner sees their next step
          var ta = document.getElementById('hdlv2-consult-notes');
          if (ta) {
            ta.focus();
            ta.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
        } else {
          btn.disabled = false;
          btn.textContent = '↻ Re-organise these notes';
          window.HDLV2UI.toast((res && res.message) || 'Could not reset. Please try again.', "error");
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = '↻ Re-organise these notes';
        window.HDLV2UI.toast('Connection error — reset not applied.', "error");
      });
    });
  }

  function bindActionButton() {
    var stage = state.actionStage || 'edit';
    if (stage === 'edit') {
      var actionBtn = document.getElementById('hdlv2-action-btn');
      if (!actionBtn) return;
      actionBtn.addEventListener('click', function () {
        var ta = document.getElementById('hdlv2-consult-notes');
        var raw = ta ? ta.value.trim() : '';
        if (!raw) { setOrganiseStatus('error', 'Add some notes before generating the report.'); return; }
        actionBtn.disabled = true;
        actionBtn.textContent = 'Saving your notes';
        setOrganiseStatus('loading', 'Saving your notes…');

        // v0.27.0 — fix #11 (/ultrareview): wait for /save-notes to finish
        // before firing /organise. Previously these raced — server-side
        // /organise read stale (or empty) raw_notes from DB before the
        // save POST landed → Claude got nothing useful.
        saveNotes(raw).then(function (savedOK) {
          if (!savedOK) {
            actionBtn.disabled = false;
            actionBtn.textContent = 'Generate Trajectory Plan';
            setOrganiseStatus('error', 'Could not save your notes. Please try again.');
            return;
          }
          actionBtn.textContent = 'Preparing your report';
          setOrganiseStatus('loading', 'Organising your notes…');
          return fetch(CFG.api_base + '/organise', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
            body: JSON.stringify({ consultation_id: state.consultId })
          })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (res && res.success && res.ai_organised_notes) {
              state.data.consultation.ai_organised_notes = res.ai_organised_notes;
              state.actionStage = 'review';
              document.getElementById('hdlv2-action-wrap').innerHTML = renderActionStage(state.data.consultation);
              bindActionButton();
              setOrganiseStatus('ok', 'Review the summary, then click Looks good.');
              window.scrollTo({ top: document.getElementById('hdlv2-action-wrap').offsetTop - 24, behavior: 'smooth' });
            } else {
              actionBtn.disabled = false;
              actionBtn.textContent = 'Generate Trajectory Plan';
              setOrganiseStatus('error', (res && res.message) || 'Something went wrong. Please try again.');
            }
          })
          .catch(function () {
            actionBtn.disabled = false;
            actionBtn.textContent = 'Generate Trajectory Plan';
            setOrganiseStatus('error', 'Connection error. Please try again.');
          });
        });
      });
      return;
    }

    if (stage === 'review') {
      // v0.32.5 — bind the new manual rec form + remove buttons + the
      // re-organise escape hatch every time the review panel mounts.
      bindAddRecButton();
      bindReorganiseButton();

      var finBtn = document.getElementById('hdlv2-finalise-btn');
      if (finBtn) {
        finBtn.addEventListener('click', function () {
          var edited = collectOrganisedFromDom();
          var SPINNER = '<span class="hdlv2-spinner" aria-hidden="true"></span>';

          // v0.46.0 — the report now generates ASYNC on the job queue, so the
          // old fake setTimeout "progress ladder" is gone. The /approve write
          // is quick (no Claude) so a brief honest spinner is fine; once
          // /finalise hands back a job_id we switch to the real preparing UI
          // (showReportPreparing) and poll for completion (pollReportJob).
          //
          // /approve MUST commit before the report job reads the organised
          // notes (v0.27.0 race fix #11) — so it stays a synchronous first
          // step. Stable Idempotency-Key on /approve dedupes a double-click.
          var idemKey = 'fin-' + state.consultId + '-' + state.progressId;

          finBtn.disabled = true;
          finBtn.innerHTML = SPINNER + '<span class="hdlv2-spinner-label">Saving your edits…</span>';
          setOrganiseStatus('loading', 'Saving your edits…');

          fetch(CFG.api_base + '/approve', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce, 'Idempotency-Key': idemKey + '-approve' },
            body: JSON.stringify({ consultation_id: state.consultId, ai_organised_notes: edited })
          })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (!res || !res.success) {
              finBtn.disabled = false;
              finBtn.textContent = 'Looks good — Generate Report';
              setOrganiseStatus('error', (res && res.message) || 'Save failed.');
              return;
            }
            state.data.consultation.ai_organised_notes    = edited;
            state.data.consultation.practitioner_approved = 1;
            // Kick off the report — returns a job_id immediately (no Claude
            // in this request), so the worker is freed at once.
            return fetch(CFG.api_base + '/finalise', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
              body: JSON.stringify({ progress_id: state.progressId, consultation_id: state.consultId })
            })
            .then(function (r) { return r.json(); })
            .then(function (fres) {
              if (fres && fres.success && fres.job_id) {
                showReportPreparing('final');
                pollReportJob(fres.job_id, 'final');
              } else {
                finBtn.disabled = false;
                finBtn.textContent = 'Looks good — Generate Report';
                setOrganiseStatus('error', (fres && fres.message) || 'Could not start the report. Please try again.');
              }
            });
          })
          .catch(function () {
            finBtn.disabled = false;
            finBtn.textContent = 'Looks good — Generate Report';
            setOrganiseStatus('error', 'Connection error. Please try again.');
          });
        });
      }
      // v0.24.8 — wire auto-save to every contenteditable in the review panel.
      bindAutoSaveListeners();
      // Initial state is "saved" (whatever's on screen matches DB).
      setSaveStatus('');
    }
  }

  function setOrganiseStatus(kind, msg) {
    var status = document.getElementById('hdlv2-notes-status');
    if (!status) return;
    status.className = 'hdlv2-save-status ' + (kind === 'error' ? 'error' : kind === 'loading' ? 'saving' : kind === 'ok' ? 'saved' : '');
    status.textContent = msg || '';
  }

  // ── Auto-save (v0.24.8) ──
  // Inline edits in the review panel persist via /consultation/save-organised
  // every 1500ms after the practitioner stops typing, plus an immediate flush
  // on field blur. Status pill in the panel head tracks the lifecycle.
  // beforeunload guard prompts if a save is mid-flight when the tab closes.
  var autoSaveTimer = null;
  var autoSaveInflight = false;
  var autoSavePending = false;

  function setSaveStatus(kind, msg) {
    var el = document.getElementById('hdlv2-organised-savestatus');
    if (!el) return;
    el.classList.remove('hdlv2-save-pending', 'hdlv2-save-saving', 'hdlv2-save-saved', 'hdlv2-save-error');
    if (kind === 'pending') {
      el.classList.add('hdlv2-save-pending');
      el.textContent = 'Editing…';
    } else if (kind === 'saving') {
      el.classList.add('hdlv2-save-saving');
      el.textContent = 'Saving…';
    } else if (kind === 'saved') {
      el.classList.add('hdlv2-save-saved');
      var t = new Date();
      el.textContent = 'Saved ' + ('0' + t.getHours()).slice(-2) + ':' + ('0' + t.getMinutes()).slice(-2);
    } else if (kind === 'error') {
      el.classList.add('hdlv2-save-error');
      el.textContent = msg || 'Save failed — retry';
    } else {
      el.textContent = '';
    }
  }

  function scheduleAutoSave() {
    if (autoSaveTimer) clearTimeout(autoSaveTimer);
    setSaveStatus('pending');
    autoSaveTimer = setTimeout(flushAutoSave, 1500);
  }

  function flushAutoSave() {
    if (autoSaveTimer) { clearTimeout(autoSaveTimer); autoSaveTimer = null; }
    if (autoSaveInflight) { autoSavePending = true; return; }
    if (!state.consultId) return;
    var edited = collectOrganisedFromDom();
    autoSaveInflight = true;
    setSaveStatus('saving');
    // v0.24.10 fix — CFG.api_base already ends in /consultation
    // (rest_url('hdl-v2/v1/consultation')). Don't re-prefix /consultation/.
    fetch(CFG.api_base + '/save-organised', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify({ consultation_id: state.consultId, ai_organised_notes: edited })
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        autoSaveInflight = false;
        if (res && res.success) {
          state.data.consultation.ai_organised_notes = edited;
          setSaveStatus('saved');
        } else {
          setSaveStatus('error', (res && res.message) || 'Save failed — retry');
        }
        if (autoSavePending) { autoSavePending = false; scheduleAutoSave(); }
      })
      .catch(function () {
        autoSaveInflight = false;
        setSaveStatus('error', 'Connection error');
        // Back off 5s before retrying so we don't hammer a flaky network
        if (autoSavePending) { autoSavePending = false; setTimeout(scheduleAutoSave, 5000); }
      });
  }

  function bindAutoSaveListeners() {
    var wrap = document.getElementById('hdlv2-action-wrap');
    if (!wrap) return;
    wrap.querySelectorAll('[contenteditable="true"]').forEach(function (el) {
      el.addEventListener('input', scheduleAutoSave);
      el.addEventListener('blur', flushAutoSave);
    });
  }

  // beforeunload guard — install once at module load.
  // v0.33.1 — also guards against losing in-flight Brief edits.
  window.addEventListener('beforeunload', function (e) {
    if (autoSaveInflight || autoSavePending || autoSaveTimer
        || briefAutoSaveInflight || briefAutoSavePending || briefAutoSaveTimer) {
      e.preventDefault();
      e.returnValue = '';
      return '';
    }
  });

  function collectOrganisedFromDom() {
    var wrap = document.getElementById('hdlv2-action-wrap');
    var out = { health_summary:'', health_history:'', recommendations:[], follow_up_actions:[], additional_notes:'' };
    if (!wrap) return out;
    wrap.querySelectorAll('[data-field]').forEach(function (el) {
      var key = el.getAttribute('data-field');
      // v0.24.7 — captureRichText round-trips bullets + paragraph breaks.
      // Falls back to plain textContent for old rows that the formatter
      // rendered as a single <p> (no list / no <br>).
      if (key in out) out[key] = captureRichText(el);
    });
    var stored = (state.data.consultation.ai_organised_notes && state.data.consultation.ai_organised_notes.recommendations) || [];
    wrap.querySelectorAll('.hdlv2-organised-rec').forEach(function (li) {
      var idx = parseInt(li.getAttribute('data-idx'), 10);
      var textEl = li.querySelector('.hdlv2-organised-rec-text');
      var base = stored[idx] || {};
      out.recommendations.push({
        text:               (textEl ? textEl.textContent.trim() : '') || base.text || '',
        category:           base.category || 'Other',
        secondary_category: base.secondary_category || '',
        priority:           base.priority || 'Medium',
        frequency:          base.frequency || 'Not specified'
      });
    });
    wrap.querySelectorAll('.hdlv2-organised-followups li').forEach(function (li) {
      var v = (li.textContent || '').trim();
      if (v) out.follow_up_actions.push(v);
    });
    return out;
  }

  // ── CLIENT BRIEF (v0.15.3) — pre-consultation summary card.
  //   Collapsed by default. Expanding loads cached content or generates.
  // v0.33.0 — meta line is now dynamic. Pre-Final = "Pre-consultation
  //   snapshot". Post-Final or post-addendum = "Updated <relative time> ·
  //   includes N addend<a/um>" so the practitioner can see at a glance
  //   whether the Brief reflects the latest clinical state. Background
  //   refresh is wired into Final_Report::regenerate() server-side, so by
  //   the time the page renders post-Save-and-Update-Plan the timestamp
  //   should be fresh.
  function renderClientBrief(data) {
    var brief = (data && data.pre_consult_summary) || null;
    var body = brief
      ? renderBriefBody(brief)
      : '<div class="hdlv2-client-brief-loading"><span class="hdlv2-spinner" style="width:14px;height:14px;display:inline-block;border:2px solid #e4e6ea;border-top-color:#3d8da0;border-radius:50%;animation:hdlv2spin 0.8s linear infinite;vertical-align:middle;"></span> <span style="vertical-align:middle;margin-left:6px;">Preparing client brief…</span></div>';

    var meta = renderBriefMeta(data);

    return '<details class="hdlv2-client-brief-details" open>'
      +   '<summary>'
      +     '<h4>Client Brief</h4>'
      +     '<span class="hdlv2-client-brief-meta">' + meta + '</span>'
      +     '<span id="hdlv2-brief-savestatus" class="hdlv2-save-pill" aria-live="polite" style="pointer-events:none;"></span>'
      +   '</summary>'
      +   '<p class="hdlv2-consult-hint" style="margin:6px 0 10px;font-size:12px;color:#6b7280;">Click any section to edit. Your changes save automatically.</p>'
      +   '<div id="hdlv2-client-brief-body">' + body + '</div>'
      +   '<button type="button" id="hdlv2-refresh-brief" class="hdlv2-btn-secondary" style="margin-top:10px;">Refresh summary</button>'
      + '</details>';
  }

  function renderBriefMeta(data) {
    if (!data) return 'Pre-consultation snapshot';
    var addendaCount = Array.isArray(data.addenda) ? data.addenda.length : 0;
    var hasFinal     = !!data.final_report;
    var ts           = data.pre_consult_summary_at || null;

    // Pre-Final, no addenda: classic snapshot label.
    if (!hasFinal && addendaCount === 0) {
      return 'Pre-consultation snapshot';
    }

    var parts = [];
    if (ts) {
      parts.push('Updated ' + formatRelativeTime(ts));
    } else {
      parts.push('Updated');
    }
    if (addendaCount > 0) {
      parts.push('includes ' + addendaCount + ' addend' + (addendaCount === 1 ? 'um' : 'a'));
    }
    return parts.join(' · ');
  }

  // Render a "5 mins ago" / "2 hrs ago" / "yesterday" / "3 days ago" string
  // from a MySQL timestamp. Server time is local (Europe/London on STBY +
  // LIVE per V2 boot) — we don't append 'Z' so the browser treats it as
  // local time consistently with the server clock. Same hardening as the
  // /ultrareview fix #15 from v0.27.1.
  function formatRelativeTime(mysqlTs) {
    if (!mysqlTs) return '';
    var d = new Date(mysqlTs.replace(' ', 'T'));
    if (isNaN(d.getTime())) return '';
    var diffMs = Date.now() - d.getTime();
    var diffMins = Math.round(diffMs / 60000);
    if (diffMins < 1) return 'just now';
    if (diffMins === 1) return '1 min ago';
    if (diffMins < 60) return diffMins + ' mins ago';
    var diffHrs = Math.round(diffMins / 60);
    if (diffHrs === 1) return '1 hr ago';
    if (diffHrs < 24) return diffHrs + ' hrs ago';
    var diffDays = Math.round(diffHrs / 24);
    if (diffDays === 1) return 'yesterday';
    if (diffDays < 7) return diffDays + ' days ago';
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
  }

  function renderBriefBody(brief) {
    if (!brief || typeof brief !== 'object') return '';
    // v0.33.1 — Brief is fully editable (matches the pattern v0.24.8 wired
    // for the AI-organised review panel). Practitioner can click directly
    // into any field and type — no pencil icon. Auto-saves via /save-brief
    // with the same debounce/flush pattern as the review panel. Refresh
    // button warns about pending edits before discarding.
    //
    // Each rec carries data-idx so collectBriefFromDom can re-pair text
    // edits back to their stored category/priority/frequency. Removing a
    // rec is NOT supported here — the Brief is a Claude-generated
    // starting-points document; if the practitioner wants to remove a
    // prescription, they do it on the Final Report consultation panel.
    var recs = (brief.recommendations || []).map(function (r, i) {
      var cat = r.category || '';
      if (r.secondary_category) cat += ' (also ' + r.secondary_category + ')';
      return '<li class="hdlv2-organised-rec" data-idx="' + i + '">'
        +   '<div class="hdlv2-organised-rec-text" contenteditable="true">' + esc(r.text || '') + '</div>'
        +   '<div class="hdlv2-organised-rec-meta">'
        +     '<span class="tag">' + esc(cat || 'Other') + '</span>'
        +     '<span class="tag priority-' + esc((r.priority || 'Medium').toLowerCase()) + '">' + esc(r.priority || 'Medium') + '</span>'
        +     '<span class="tag">' + esc(r.frequency || 'Not specified') + '</span>'
        +   '</div></li>';
    }).join('');
    var followUps = (brief.follow_up_actions || []).map(function (a) {
      return '<li contenteditable="true">' + esc(a) + '</li>';
    }).join('');
    var h = '';
    if (brief.health_summary)   h += '<div class="hdlv2-organised-sec"><h5>Consultation Summary</h5><div class="hdlv2-organised-body hdlv2-rich-text" data-field="health_summary" contenteditable="true">' + formatRichText(brief.health_summary) + '</div></div>';
    if (brief.health_history)   h += '<div class="hdlv2-organised-sec"><h5>Health History</h5><div class="hdlv2-organised-body hdlv2-rich-text" data-field="health_history" contenteditable="true">' + formatRichText(brief.health_history) + '</div></div>';
    if (recs)                   h += '<div class="hdlv2-organised-sec"><h5>Recommendations</h5><ul class="hdlv2-organised-recs">' + recs + '</ul></div>';
    if (followUps)              h += '<div class="hdlv2-organised-sec"><h5>Follow-Up Actions</h5><ul class="hdlv2-organised-followups">' + followUps + '</ul></div>';
    if (brief.additional_notes) h += '<div class="hdlv2-organised-sec"><h5>Additional Notes</h5><div class="hdlv2-organised-body hdlv2-rich-text" data-field="additional_notes" contenteditable="true">' + formatRichText(brief.additional_notes) + '</div></div>';
    return h || '<p style="color:#888;font-size:13px;">No brief available.</p>';
  }

  // ── Brief auto-save (v0.33.1) ──
  // Mirrors the review-panel auto-save (lines ~712–814) but targets the
  // Client Brief contenteditables and writes to /consultation/save-brief.
  // Independent timers + status pill so the two panels can edit concurrently
  // without stepping on each other.
  var briefAutoSaveTimer    = null;
  var briefAutoSaveInflight = false;
  var briefAutoSavePending  = false;
  var briefDirty            = false;

  function setBriefSaveStatus(kind, msg) {
    var el = document.getElementById('hdlv2-brief-savestatus');
    if (!el) return;
    el.classList.remove('hdlv2-save-pending', 'hdlv2-save-saving', 'hdlv2-save-saved', 'hdlv2-save-error');
    if (kind === 'pending')      { el.classList.add('hdlv2-save-pending'); el.textContent = 'Editing…'; }
    else if (kind === 'saving')  { el.classList.add('hdlv2-save-saving');  el.textContent = 'Saving…'; }
    else if (kind === 'saved')   {
      el.classList.add('hdlv2-save-saved');
      var t = new Date();
      el.textContent = 'Saved ' + ('0' + t.getHours()).slice(-2) + ':' + ('0' + t.getMinutes()).slice(-2);
    }
    else if (kind === 'error')   { el.classList.add('hdlv2-save-error');   el.textContent = msg || 'Save failed — retry'; }
    else                         { el.textContent = ''; }
  }

  function scheduleBriefAutoSave() {
    if (briefAutoSaveTimer) clearTimeout(briefAutoSaveTimer);
    briefDirty = true;
    setBriefSaveStatus('pending');
    briefAutoSaveTimer = setTimeout(flushBriefAutoSave, 1500);
  }

  function flushBriefAutoSave() {
    if (briefAutoSaveTimer) { clearTimeout(briefAutoSaveTimer); briefAutoSaveTimer = null; }
    if (briefAutoSaveInflight) { briefAutoSavePending = true; return; }
    if (!state.progressId) return;
    var edited = collectBriefFromDom();
    if (!edited) return;
    briefAutoSaveInflight = true;
    setBriefSaveStatus('saving');
    fetch(CFG.api_base + '/save-brief', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify({ progress_id: state.progressId, brief: edited })
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        briefAutoSaveInflight = false;
        if (res && res.success) {
          state.data.pre_consult_summary = edited;
          if (res.pre_consult_summary_at) {
            state.data.pre_consult_summary_at = res.pre_consult_summary_at;
            // Refresh the meta line in the brief panel head (e.g. "Updated just now")
            var metaEl = document.querySelector('.hdlv2-client-brief-meta');
            if (metaEl) metaEl.textContent = renderBriefMeta(state.data);
          }
          briefDirty = false;
          setBriefSaveStatus('saved');
        } else {
          setBriefSaveStatus('error', (res && res.message) || 'Save failed — retry');
        }
        if (briefAutoSavePending) { briefAutoSavePending = false; scheduleBriefAutoSave(); }
      })
      .catch(function () {
        briefAutoSaveInflight = false;
        setBriefSaveStatus('error', 'Connection error');
        if (briefAutoSavePending) { briefAutoSavePending = false; setTimeout(scheduleBriefAutoSave, 5000); }
      });
  }

  function collectBriefFromDom() {
    var bodyEl = document.getElementById('hdlv2-client-brief-body');
    if (!bodyEl) return null;
    var out = { health_summary: '', health_history: '', recommendations: [], follow_up_actions: [], additional_notes: '' };
    bodyEl.querySelectorAll('[data-field]').forEach(function (el) {
      var key = el.getAttribute('data-field');
      if (key in out) out[key] = captureRichText(el);
    });
    var stored = (state.data && state.data.pre_consult_summary && state.data.pre_consult_summary.recommendations) || [];
    bodyEl.querySelectorAll('.hdlv2-organised-rec').forEach(function (li) {
      var idx = parseInt(li.getAttribute('data-idx'), 10);
      var textEl = li.querySelector('.hdlv2-organised-rec-text');
      var base = stored[idx] || {};
      out.recommendations.push({
        text:               (textEl ? textEl.textContent.trim() : '') || base.text || '',
        category:           base.category || 'Other',
        secondary_category: base.secondary_category || '',
        priority:           base.priority || 'Medium',
        frequency:          base.frequency || 'Not specified'
      });
    });
    bodyEl.querySelectorAll('.hdlv2-organised-followups li').forEach(function (li) {
      var v = (li.textContent || '').trim();
      if (v) out.follow_up_actions.push(v);
    });
    return out;
  }

  function bindBriefAutoSaveListeners() {
    var bodyEl = document.getElementById('hdlv2-client-brief-body');
    if (!bodyEl) return;
    bodyEl.querySelectorAll('[contenteditable="true"]').forEach(function (el) {
      el.addEventListener('input', scheduleBriefAutoSave);
      el.addEventListener('blur',  flushBriefAutoSave);
    });
  }

  function bindRefreshBrief() {
    var btn = document.getElementById('hdlv2-refresh-brief');
    if (!btn) return;
    btn.addEventListener('click', function () {
      // v0.33.1 — flush any pending Brief edits before refresh, AND confirm
      // if the practitioner has dirty inline changes (Refresh re-runs Claude
      // and overwrites them).
      if (briefDirty || briefAutoSaveInflight) {
        if (!confirm(
          'Refresh re-generates the Brief from scratch using Claude. Your unsaved inline edits will be discarded.\n\nContinue?'
        )) return;
        briefDirty = false;
        if (briefAutoSaveTimer) { clearTimeout(briefAutoSaveTimer); briefAutoSaveTimer = null; }
        briefAutoSavePending = false;
      }

      btn.disabled = true;
      var original = btn.textContent;
      btn.textContent = 'Refreshing…';
      setBriefSaveStatus('saving');
      fetch(CFG.api_base + '/refresh-brief', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({ progress_id: state.progressId })
      })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        btn.disabled = false;
        btn.textContent = original;
        if (res && res.success && res.pre_consult_summary) {
          state.data.pre_consult_summary = res.pre_consult_summary;
          if (res.pre_consult_summary_at) {
            state.data.pre_consult_summary_at = res.pre_consult_summary_at;
          }
          var bodyEl = document.getElementById('hdlv2-client-brief-body');
          if (bodyEl) bodyEl.innerHTML = renderBriefBody(res.pre_consult_summary);
          var metaEl = document.querySelector('.hdlv2-client-brief-meta');
          if (metaEl) metaEl.textContent = renderBriefMeta(state.data);
          // Re-bind editable listeners on the freshly re-rendered body
          bindBriefAutoSaveListeners();
          setBriefSaveStatus('saved');
        } else {
          setBriefSaveStatus('error', (res && res.message) || 'Refresh failed');
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = original;
        setBriefSaveStatus('error', 'Connection error');
      });
    });
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

    var groups = [
      { title: 'Demographics', fields: [
        { key: 'q1_age', label: 'Age', stage: 1 },
        { key: 'q1_sex', label: 'Sex', stage: 1 }
      ]},
      { title: 'Body Composition', fields: [
        { key: 'height', label: 'Height (cm)', stage: 3 },
        { key: 'weight', label: 'Weight (kg)', stage: 3 },
        { key: 'waist', label: 'Waist (cm)', stage: 3 },
        { key: 'hip', label: 'Hip (cm)', stage: 3 }
      ]},
      { title: 'Cardiovascular', fields: [
        { key: 'bpSystolic', label: 'Systolic BP', stage: 3 },
        { key: 'bpDiastolic', label: 'Diastolic BP', stage: 3 },
        { key: 'restingHeartRate', label: 'Resting HR', stage: 3 }
      ]},
      { title: 'Fitness & Movement', fields: [
        { key: 'physicalActivity', label: 'Physical Activity', stage: 3 },
        { key: 'sitToStand', label: 'Sit-to-Stand', stage: 3 },
        { key: 'breathHold', label: 'Breath Hold', stage: 3 },
        { key: 'balance', label: 'Balance', stage: 3 }
      ]},
      { title: 'Sleep & Recovery', fields: [
        { key: 'sleepDuration', label: 'Sleep Duration', stage: 3 },
        { key: 'sleepQuality', label: 'Sleep Quality', stage: 3 },
        { key: 'stressLevels', label: 'Stress', stage: 3 }
      ]},
      { title: 'Lifestyle', fields: [
        { key: 'dietQuality', label: 'Diet', stage: 3 },
        { key: 'alcoholConsumption', label: 'Alcohol', stage: 3 },
        { key: 'smokingStatus', label: 'Smoking', stage: 3 },
        { key: 'supplementIntake', label: 'Supplements', stage: 3 },
        { key: 'sunlightExposure', label: 'Sunlight', stage: 3 },
        { key: 'dailyHydration', label: 'Hydration', stage: 3 }
      ]},
      { title: 'Cognitive & Social', fields: [
        { key: 'cognitiveActivity', label: 'Cognitive', stage: 3 },
        { key: 'socialConnections', label: 'Social', stage: 3 },
        { key: 'skinElasticity', label: 'Skin Elasticity', stage: 3 }
        // v0.23.1 — overallHealthPercent removed (Matthew 2026-04-28).
      ]}
    ];

    var h = '';
    groups.forEach(function (g) {
      h += '<div class="hdlv2-field-group">';
      h +=   '<h5 class="hdlv2-field-group-title">' + esc(g.title) + '</h5>';
      g.fields.forEach(function (f) {
        var raw     = (f.stage === 1 ? s1[f.key] : s3[f.key]);
        var changed = changedFields[f.key];
        var val     = changed ? changed.new_value : raw;
        var isEmpty = (val === '' || val == null || val === 'skip' || (typeof val === 'string' && val.trim() === ''));
        var display = isEmpty ? '\u2014' : val;
        var cls     = changed ? ' hdlv2-consult-field-changed' : (isEmpty ? ' hdlv2-consult-field-empty' : '');
        h += '<div class="hdlv2-consult-field-row' + cls + '" data-field-key="' + f.key + '">'
          + '<span class="hdlv2-consult-field-label">' + esc(f.label) + '</span>'
          + '<span class="hdlv2-consult-field-value">' + esc(display) + '</span>'
          + '<button type="button" class="hdlv2-consult-edit-btn" data-field="' + f.key + '" title="Edit">\u270F\uFE0F</button>'
          + (changed ? '<small class="hdlv2-consult-change-note">' + esc(changed.original) + ' \u2192 ' + esc(changed.new_value) + '</small>' : '')
          + '</div>';
      });
      h += '</div>';
    });

    // v0.38.0 \u2014 Stage 3 Section 6 "Health Background" \u2014 three optional
    // long-form text fields. Rendered as read-only paragraph blocks
    // (long-form prose doesn't fit the inline-editable scored-field row).
    // Practitioner can take their own notes during consultation or amend
    // via the addenda flow if needed; the client-typed text stays as the
    // canonical record of what the client said before the consult.
    var bgFields = [
      { key: 'family_history',      label: 'Family history' },
      { key: 'medications',         label: 'Current medications' },
      { key: 'existing_conditions', label: 'Existing conditions / diagnoses' }
    ];
    var hasAnyBackground = bgFields.some(function (f) {
      var v = s3[f.key];
      return typeof v === 'string' && v.trim() !== '';
    });
    if (hasAnyBackground || /* always show the group so the practitioner sees the empty state too */ true) {
      h += '<div class="hdlv2-field-group hdlv2-background-group">';
      h +=   '<h5 class="hdlv2-field-group-title">Health Background</h5>';
      h +=   '<p class="hdlv2-background-helper">Captured at Stage 3 \u2014 short notes the client typed; expand during your consultation.</p>';
      bgFields.forEach(function (f) {
        var v = s3[f.key];
        var hasVal = typeof v === 'string' && v.trim() !== '';
        h += '<div class="hdlv2-background-row">'
          +    '<div class="hdlv2-background-label">' + esc(f.label) + '</div>'
          +    '<div class="hdlv2-background-value' + (hasVal ? '' : ' hdlv2-background-empty') + '">'
          +      (hasVal ? esc(v).replace(/\n/g, '<br>') : '\u2014 not provided')
          +    '</div>'
          +  '</div>';
      });
      h += '</div>';
    }
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
    // v0.26.0 — C-008: surface non-success + network failure so silent edit
    // drops stop happening. Previously a dropped network call left the DOM
    // unchanged with no clue why.
    fetch(CFG.api_base + '/edit-field', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify({ consultation_id: state.consultId, progress_id: state.progressId, field: field, new_value: newValue, reason: reason })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
      if (res && res.success) {
        state.data.consultation.health_data_changes = res.changes;
        document.getElementById('hdlv2-health-fields').innerHTML = renderHealthFields(state.data);
        bindHealthFieldEdits();
      } else {
        setOrganiseStatus('error', (res && res.message) || 'Could not save your edit. Please try again.');
      }
    })
    .catch(function () {
      setOrganiseStatus('error', 'Connection error. Your edit was not saved.');
    });
  }

  // ── NOTES AUTO-SAVE ──
  function bindNotesAutoSave() {
    var ta = document.getElementById('hdlv2-consult-notes');
    if (!ta) return;
    ta.addEventListener('input', function () {
      if (state.notesSaveTimer) clearTimeout(state.notesSaveTimer);
      state.notesSaveTimer = setTimeout(function () { saveNotes(ta.value); }, 30000);
    });
    ta.addEventListener('blur', function () {
      if (state.notesSaveTimer) clearTimeout(state.notesSaveTimer);
      saveNotes(ta.value);
    });
  }

  function saveNotes(text) {
    // v0.27.0 — fix #11 (/ultrareview): now returns a Promise resolved to a
    // boolean (true on success, false on failure). The Generate-Final-Report
    // click handler awaits this so /organise can't fire before /save-notes
    // has written raw_notes (previously read stale/empty notes from DB).
    // Resolves false rather than rejecting so the click handler can branch
    // cleanly without a separate .catch. Existing input-debounce + blur
    // callers ignore the return value so they're unaffected.
    var statusEl = document.getElementById('hdlv2-notes-status');
    if (statusEl) { statusEl.className = 'hdlv2-save-status saving'; statusEl.textContent = 'Saving...'; }
    return fetch(CFG.api_base + '/save-notes', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
      body: JSON.stringify({ consultation_id: state.consultId, typed_notes: text })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
      if (statusEl) { statusEl.className = 'hdlv2-save-status saved'; statusEl.textContent = 'Saved'; setTimeout(function () { statusEl.textContent = ''; statusEl.className = 'hdlv2-save-status'; }, 3000); }
      return !!(res && res.success);
    })
    .catch(function () {
      if (statusEl) { statusEl.className = 'hdlv2-save-status error'; statusEl.textContent = 'Save failed'; }
      return false;
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
      + '<textarea id="hdlv2-rec-text" rows="2" placeholder="Concrete next-day action — e.g. &quot;After dinner, brush teeth — kitchen closes for the night.&quot; Include behaviour + frequency. Imperative voice. Avoid slogans (&quot;focus on&quot;, &quot;manage&quot;)."></textarea>'
      + '<div style="display:flex;gap:8px;margin:6px 0;">'
      + '<select id="hdlv2-rec-pri" style="flex:1;">' + PRIORITIES.map(function (p, i) { return '<option value="' + p + '"' + (i === 1 ? ' selected' : '') + '>' + p + ' priority</option>'; }).join('') + '</select>'
      + '<select id="hdlv2-rec-freq" style="flex:1;">' + FREQUENCIES.map(function (f) { return '<option value="' + f + '">' + f + '</option>'; }).join('') + '</select>'
      + '</div>'
      + '<button type="button" id="hdlv2-add-rec" class="hdlv2-btn-secondary" style="font-size:13px;padding:8px 20px;">Add Recommendation</button>'
      + '</div>';
  }

  // One-shot visual flash to highlight a field that needs attention.
  function flashField( el ) {
    if ( ! el ) return;
    var prev = el.style.boxShadow;
    el.style.boxShadow = '0 0 0 2px #d97706';
    el.focus();
    setTimeout( function () { el.style.boxShadow = prev; }, 1200 );
  }

  function bindRecForm() {
    var addBtn = document.getElementById('hdlv2-add-rec');
    if (!addBtn) return;
    addBtn.addEventListener('click', function () {
      var catEl  = document.getElementById('hdlv2-rec-cat');
      var textEl = document.getElementById('hdlv2-rec-text');
      var priEl  = document.getElementById('hdlv2-rec-pri');
      var freqEl = document.getElementById('hdlv2-rec-freq');
      var cat    = catEl.value;
      var text   = textEl.value.trim();
      var pri    = priEl ? priEl.value : 'Medium';
      var freq   = freqEl.value;

      // Required-field validation with focus + flash
      if (!cat)  { flashField(catEl);  return; }
      if (!text) { flashField(textEl); return; }

      fetch(CFG.api_base + '/add-recommendation', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
        body: JSON.stringify({ consultation_id: state.consultId, category: cat, text: text, priority: pri, frequency: freq })
      })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.success) {
          state.data.consultation.recommendations = res.recommendations;
          document.getElementById('hdlv2-rec-list').innerHTML = renderRecommendations(res.recommendations);
          bindRecRemoveButtons();
          // Reset form for next entry — leave Frequency on its last value
          // since practitioners typically add several Daily recs in a row.
          textEl.value = '';
          catEl.value  = '';
          if (priEl) priEl.value = 'Medium';
          catEl.focus();
        } else {
          // v0.26.0 — C-008: was a silent no-op on non-success.
          setOrganiseStatus('error', (res && res.message) || 'Could not add the recommendation. Please try again.');
        }
      })
      .catch(function () {
        setOrganiseStatus('error', 'Connection error. The recommendation was not saved.');
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
          if (res && res.success) {
            state.data.consultation.recommendations = res.recommendations;
            document.getElementById('hdlv2-rec-list').innerHTML = renderRecommendations(res.recommendations);
            bindRecRemoveButtons();
          } else {
            // v0.26.0 — C-008: was a silent no-op on non-success.
            setOrganiseStatus('error', (res && res.message) || 'Could not remove the recommendation. Please try again.');
          }
        })
        .catch(function () {
          setOrganiseStatus('error', 'Connection error. The recommendation was not removed.');
        });
      });
    });
  }

  // ── GENERATE FINAL REPORT (DEAD — disabled v0.46.0) ──
  // bindGenerateButton() is an orphaned SYNCHRONOUS /finalise caller: zero
  // call sites, and its element #hdlv2-generate-final is never rendered (the
  // live pre-Final CTA is #hdlv2-finalise-btn, handled in bindActionButton()).
  // It pre-dated the async conversion and would flash a false "success" against
  // the new job-queue contract (which returns a job_id, not a finished report)
  // if ever re-wired. Hard-disabled with an immediate return so it can never
  // execute that path; the unreachable body below is kept only to minimise the
  // diff and is slated for deletion in a follow-up cleanup.
  function bindGenerateButton() {
    return; // permanently disabled — see note above
    var btn = document.getElementById('hdlv2-generate-final');
    if (!btn) return;

    // Spinner + label markup. Honest progression: one message change at 8s.
    var SPINNER = '<span class="hdlv2-spinner" aria-hidden="true"></span>';
    function showSpinner( labelText ) {
      btn.innerHTML = SPINNER + '<span class="hdlv2-spinner-label">' + labelText + '</span>';
    }
    function restoreButton() {
      btn.disabled = false;
      btn.textContent = 'Generate Trajectory Plan';
    }

    btn.addEventListener('click', function () {
      // v0.20.10 — themed modal instead of browser-native confirm().
      var clientName = state.data.client_name || 'this client';
      var ask = (window.HDLV2UI && window.HDLV2UI.confirm)
        ? window.HDLV2UI.confirm({
            title: 'Generate the Trajectory Plan for ' + clientName + '?',
            body: 'This will replace the Draft.',
            confirmLabel: 'Generate Trajectory Plan',
            cancelLabel: 'Cancel'
          })
        : Promise.resolve(window.confirm('Generate the Trajectory Plan for ' + clientName + '? This will replace the Draft.'));
      ask.then(function (confirmed) {
        if (!confirmed) return;
        btn.disabled = true;
        showSpinner('Generating Trajectory Plan…');

        // After 8s, swap to a softer "still working" message so the practitioner
        // knows the request hasn't hung. Cleared on response.
        var almostTimer = setTimeout(function () {
          if (btn.disabled) showSpinner('Almost there…');
        }, 8000);

        // Save notes first
        var notes = document.getElementById('hdlv2-consult-notes');
        if (notes) saveNotes(notes.value);

        // Idempotency key: stable for this page-load's "intent to finalise".
        // A double-click within 30s replays the cached response (no duplicate
        // Claude burn). The server-side duplicate guard in
        // HDLV2_Final_Report::generate() catches the cross-page-load case.
        var idemKey = (window.hdlv2RateLimit && window.hdlv2RateLimit.idempotencyKey)
          ? window.hdlv2RateLimit.idempotencyKey()
          : 'fin-' + state.progressId + '-' + Date.now();

        fetch(CFG.api_base + '/finalise', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': CFG.nonce,
            'Idempotency-Key': idemKey
          },
          body: JSON.stringify({ progress_id: state.progressId, consultation_id: state.consultId })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          clearTimeout(almostTimer);
          if (res.success) {
            renderFinalReport(res);
          } else {
            restoreButton();
            window.HDLV2UI.toast(res.message || 'Generation failed. Please try again.', "error");
          }
        })
        .catch(function () {
          clearTimeout(almostTimer);
          restoreButton();
          window.HDLV2UI.toast('Connection error. Please try again.', "error");
        });
      });
    });
  }

  // v0.21.2 \u2014 Post-finalise success state. Replaces the old iframe embed
  // (which duplicated the site chrome inside a 1100px column). Now renders a
  // clean success card with a direct link to the canonical report page.
  function renderFinalReport(/* res */) {
    var token = state.data && state.data.token ? state.data.token : '';
    var reportUrl = token ? (window.location.origin + '/longevity-draft-report/?t=' + encodeURIComponent(token)) : '';
    root.innerHTML = '<div style="max-width:620px;margin:48px auto;padding:0 20px;text-align:center;font-family:Inter,-apple-system,sans-serif;">'
      + '<div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:14px;padding:28px 24px;">'
      +   '<div style="font-family:Poppins,Inter,sans-serif;font-size:18px;font-weight:600;color:#065f46;margin:0 0 6px;">Final report generated</div>'
      +   '<div style="color:#047857;font-size:13px;margin:0 0 18px;">The client and PDF have been delivered via email.</div>'
      +   (reportUrl ? '<a href="' + esc(reportUrl) + '" style="display:inline-block;background:#10b981;color:#fff;padding:10px 22px;border-radius:48px;font-size:14px;font-weight:600;text-decoration:none;font-family:Poppins,Inter,sans-serif;">View the final report \u2192</a>' : 'Refresh this page to view the final layout.')
      + '</div>'
      + '</div>';
  }

  // ──────────────────────────────────────────────────────────────────
  //  v0.46.0 — Async Final Report: "preparing" UI + job polling.
  //
  //  The heavy Claude work now runs on the server's job queue, so the
  //  /finalise + /save-and-update-plan requests return a job_id in well under
  //  a second (the PHP worker is freed at once — many practitioners can
  //  generate reports without freezing the site). Here we replace the old
  //  fake setTimeout "progress ladder" with an honest preparing state
  //  (spinner + skeleton + a message telling the practitioner WHERE the
  //  report will appear) and poll /jobs/{id}/status until it's ready.
  //
  //  Poll loop mirrors hdlv2-audio-component.js pollTranscriptionJob():
  //  recursive setTimeout (self-terminating — never double-fires, no leaked
  //  setInterval), adaptive backoff, cache-bust + cache:'no-store' (so a
  //  cached "pending" can't hang the UI), and a root.isConnected guard so a
  //  late tick can't write into a torn-down DOM.
  // ──────────────────────────────────────────────────────────────────

  function jobsStatusUrl(jobId) {
    // CFG.api_base ends in '/consultation'; the job status lives at
    // '<root>/jobs/{id}/status'. Resilient to site_url / wp-json structures.
    var rootBase = CFG.api_base.replace(/\/consultation\/?$/, '').replace(/\/$/, '');
    return rootBase + '/jobs/' + jobId + '/status';
  }

  function reportPollInterval(attempts) {
    // Report jobs run for MINUTES, so poll gently. Polling too fast would
    // drain the per-user read rate-limit (TIER_READ ~200/hr, shared with
    // dashboard + draft-report reads) and could surface a perfectly healthy
    // report as a false "timeout". A few-second cadence is plenty.
    if (attempts < 3) return 3000;
    if (attempts < 8) return 5000;
    return 8000;
  }

  // Replace the whole consultation panel with an honest "preparing" card.
  function showReportPreparing(kind) {
    var title = (kind === 'regen') ? 'Updating the Trajectory Plan…' : 'Preparing the final report…';
    var skel = (window.HDLV2Loading && typeof HDLV2Loading.skeleton === 'function')
      ? HDLV2Loading.skeleton('consultation')
      : '';
    root.innerHTML =
      '<div class="hdlv2-report-preparing" style="max-width:680px;margin:40px auto;padding:0 20px;font-family:Inter,-apple-system,BlinkMacSystemFont,sans-serif;">'
      + '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:14px;padding:26px 24px;text-align:center;">'
      +   '<div class="hdlv2-spinner" aria-hidden="true" style="width:30px;height:30px;border:3px solid #bfdbfe;border-top-color:#3b82f6;border-radius:50%;margin:0 auto 16px;animation:hdlv2spin 0.8s linear infinite;"></div>'
      +   '<h3 style="font-family:Poppins,Inter,sans-serif;font-size:18px;font-weight:600;color:#1e3a5f;margin:0 0 10px;">' + esc(title) + '</h3>'
      +   '<p style="color:#2c3e50;font-size:14px;line-height:1.55;margin:0 0 8px;">This usually takes a minute or two. You can stay on this page &mdash; <strong>the report will appear here automatically</strong> when it&rsquo;s ready. You don&rsquo;t need to refresh.</p>'
      +   '<p style="color:#5b6b7b;font-size:13px;line-height:1.5;margin:0;">It is also saved to the client&rsquo;s Trajectory Plan, so it is safe to leave this page and come back later.</p>'
      +   '<p class="hdlv2-rp-status" id="hdlv2-rp-status" role="status" aria-live="polite" style="color:#3b82f6;font-size:13px;font-weight:600;margin:16px 0 0;">Generating the report&hellip;</p>'
      + '</div>'
      + (skel ? '<div aria-hidden="true" style="margin-top:18px;opacity:0.65;">' + skel + '</div>' : '')
      + '</div>';
  }

  function setReportPrepStatus(msg) {
    var el = document.getElementById('hdlv2-rp-status');
    if (el && typeof msg === 'string') el.textContent = msg;
  }

  // Poll the report job until completed / failed / timeout.
  function pollReportJob(jobId, kind) {
    var MAX_POLL_MS = 8 * 60 * 1000; // 8 min — covers worst-case Claude + slack at the gentler poll cadence
    var startedAt = Date.now();
    var attempts = 0;
    var base = jobsStatusUrl(jobId);

    var tick = function () {
      // DOM torn down (navigated away / re-rendered) — stop cleanly.
      if (!root || !root.isConnected) return;
      if (Date.now() - startedAt > MAX_POLL_MS) {
        showReportTimeout(kind);
        return;
      }
      attempts++;
      // Cache-bust + no-store: LiteSpeed/Cloudflare/Brave would otherwise
      // serve a stale "pending" and the UI would hang forever.
      var url = base + (base.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now();
      fetch(url, {
        headers: CFG.nonce ? { 'X-WP-Nonce': CFG.nonce } : {},
        cache: 'no-store'
      })
        .then(function (r) { return r.json(); })
        .then(function (job) {
          if (!root || !root.isConnected) return;
          if (!job || !job.status) { setTimeout(tick, reportPollInterval(attempts)); return; }
          if (job.status === 'pending' || job.status === 'running') {
            // Drive the reassurance copy off elapsed time (report jobs use
            // max_attempts=1, so job.attempts is always 1 — an attempts-based
            // message would never change).
            var elapsed = Date.now() - startedAt;
            setReportPrepStatus(elapsed > 45000 ? 'Still working on it — almost there…' : 'Generating the report…');
            setTimeout(tick, reportPollInterval(attempts));
            return;
          }
          if (job.status === 'completed') {
            if (kind === 'regen') {
              // Re-load the consultation so the refreshed report + the new
              // addendum in the timeline render from fresh server state.
              showLoading('Loading your updated report…');
              loadConsultation();
            } else {
              renderFinalReport();
            }
            return;
          }
          // failed / cancelled
          showReportError(kind, job.error);
        })
        .catch(function () {
          // Transient network blip — keep polling until the cap.
          setTimeout(tick, reportPollInterval(attempts));
        });
    };
    tick();
  }

  function showReportError(kind, jobError) {
    var isEmptyRecs = jobError && String(jobError).indexOf('empty_recommendations') !== -1;
    var label = (kind === 'regen') ? 'update' : 'report';
    var headline = isEmptyRecs ? 'No recommendations to build from' : ('The ' + label + ' didn’t finish');
    var detail = isEmptyRecs
      ? 'There are no recommendations to build the plan from. Go back, add at least one recommendation, then update the plan again. Nothing was sent to your client.'
      : 'Nothing was sent to your client. You can try again.';
    var btnLabel = isEmptyRecs ? 'Back to consultation' : 'Try again';
    root.innerHTML =
      '<div style="max-width:620px;margin:48px auto;padding:0 20px;text-align:center;font-family:Inter,-apple-system,sans-serif;">'
      + '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:14px;padding:26px 24px;">'
      +   '<div style="font-family:Poppins,Inter,sans-serif;font-size:17px;font-weight:600;color:#991b1b;margin:0 0 6px;">' + esc(headline) + '</div>'
      +   '<div style="color:#b91c1c;font-size:13px;line-height:1.5;margin:0 0 18px;">' + esc(detail) + '</div>'
      +   '<button id="hdlv2-report-retry" type="button" style="background:#3d8da0;color:#fff;border:none;padding:10px 22px;border-radius:48px;font-size:14px;font-weight:600;cursor:pointer;font-family:Poppins,Inter,sans-serif;">' + esc(btnLabel) + '</button>'
      + '</div>'
      + '</div>';
    var btn = document.getElementById('hdlv2-report-retry');
    if (btn) btn.addEventListener('click', function () { showLoading('Loading consultation data...'); loadConsultation(); });
  }

  function showReportTimeout(kind) {
    var token = state.data && state.data.token ? state.data.token : '';
    var reportUrl = token ? (window.location.origin + '/longevity-draft-report/?t=' + encodeURIComponent(token)) : '';
    root.innerHTML =
      '<div style="max-width:620px;margin:48px auto;padding:0 20px;text-align:center;font-family:Inter,-apple-system,sans-serif;">'
      + '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:14px;padding:26px 24px;">'
      +   '<div style="font-family:Poppins,Inter,sans-serif;font-size:17px;font-weight:600;color:#92400e;margin:0 0 6px;">Still preparing&hellip;</div>'
      +   '<div style="color:#b45309;font-size:13px;line-height:1.5;margin:0 0 18px;">This is taking a little longer than usual. It will appear on the client&rsquo;s Trajectory Plan when it&rsquo;s ready &mdash; please check back shortly.</div>'
      +   (reportUrl ? '<a href="' + esc(reportUrl) + '" style="display:inline-block;background:#d97706;color:#fff;padding:10px 22px;border-radius:48px;font-size:14px;font-weight:600;text-decoration:none;font-family:Poppins,Inter,sans-serif;margin:0 8px 8px 0;">View the Trajectory Plan →</a>' : '')
      +   '<button id="hdlv2-report-refresh" type="button" style="background:#fff;color:#92400e;border:1px solid #fde68a;padding:10px 22px;border-radius:48px;font-size:14px;font-weight:600;cursor:pointer;font-family:Poppins,Inter,sans-serif;">Refresh</button>'
      + '</div>'
      + '</div>';
    var btn = document.getElementById('hdlv2-report-refresh');
    if (btn) btn.addEventListener('click', function () { showLoading('Loading consultation data...'); loadConsultation(); });
  }

  // ── UTILS ──
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;'); }

  // v0.24.7 — render AI-generated narrative text (health_summary,
  // health_history, additional_notes) as paragraphs + bullet lists. The
  // organise/pre-consult prompts emit `\n\n` between paragraphs and `- `
  // line prefixes for bullets. Old rows without those markers fall through
  // to a single <p> — backward-compatible with pre-v0.24.7 stored summaries.
  // Handles "header line + bullets" inside a single paragraph by splitting
  // header into <p> and bullets into <ul>.
  function formatRichText(raw) {
    var text = (raw == null ? '' : String(raw)).replace(/\r\n/g, '\n').trim();
    if (!text) return '';
    function renderPrefix(lines) {
      if (!lines.length) return '';
      return '<p>' + lines.map(esc).join('<br>') + '</p>';
    }
    function renderBullets(lines) {
      if (!lines.length) return '';
      return '<ul class="hdlv2-rich-list">' + lines.map(function (l) {
        return '<li>' + esc(l.replace(/^\s*[-•]\s+/, '')) + '</li>';
      }).join('') + '</ul>';
    }
    var paragraphs = text.split(/\n\s*\n+/);
    return paragraphs.map(function (para) {
      var lines = para.split('\n').filter(function (l) { return l.trim() !== ''; });
      if (!lines.length) return '';
      var html = '';
      var prefixLines = [];
      var bulletLines = [];
      lines.forEach(function (l) {
        if (/^\s*[-•]\s+/.test(l)) {
          bulletLines.push(l);
        } else if (bulletLines.length) {
          html += renderPrefix(prefixLines) + renderBullets(bulletLines);
          prefixLines = [l];
          bulletLines = [];
        } else {
          prefixLines.push(l);
        }
      });
      html += renderPrefix(prefixLines) + renderBullets(bulletLines);
      return html;
    }).join('');
  }

  // v0.24.7 — round-trip companion to formatRichText. Walks the
  // contenteditable DOM and re-emits markdown-flavoured text the AI's
  // formatter understands, so practitioner edits preserve bullet + paragraph
  // structure across save → reload. Without this, contenteditable's native
  // textContent extraction would flatten <ul><li> into a single line.
  function captureRichText(el) {
    if (!el) return '';
    var out = [];
    function walk(node) {
      if (node.nodeType === 3) { out.push(node.nodeValue || ''); return; }
      if (node.nodeType !== 1) return;
      var tag = node.tagName.toLowerCase();
      if (tag === 'br') { out.push('\n'); return; }
      if (tag === 'li') {
        out.push('- ');
        Array.prototype.forEach.call(node.childNodes, walk);
        out.push('\n');
        return;
      }
      if (tag === 'p' || tag === 'div') {
        Array.prototype.forEach.call(node.childNodes, walk);
        out.push('\n\n');
        return;
      }
      if (tag === 'ul' || tag === 'ol') {
        Array.prototype.forEach.call(node.childNodes, walk);
        out.push('\n');
        return;
      }
      Array.prototype.forEach.call(node.childNodes, walk);
    }
    Array.prototype.forEach.call(el.childNodes, walk);
    return out.join('').replace(/\n{3,}/g, '\n\n').trim();
  }

  // ── FONTS ──
  if (!document.getElementById('hdlv2-form-fonts')) {
    var link = document.createElement('link');
    link.id = 'hdlv2-form-fonts'; link.rel = 'stylesheet';
    link.href = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap';
    document.head.appendChild(link);
  }

  // v0.28.2 — Orange "Save and Re-generate" banner removed. Its role
  // (post-Final regen of the Final Report + Flight Plan) is now fully
  // covered by the "Save & Update Plan" CTA at the bottom of the
  // Consultation Addenda section. The /consultation/save-and-regenerate
  // REST endpoint itself is left in place defensively — no UI consumer in
  // V2 anymore, but cheap to keep and removing it could break any
  // hypothetical external integration. The function bodies that lived
  // here previously (regenAttachTimer / attachRegenBar / confirmAndRegen,
  // ~80 lines) were deleted. See CONSULTATION-ADDENDA-DESIGN.md for the
  // single-CTA rationale.

  // ──────────────────────────────────────────────────────────────
  //  LIST VIEW (v0.25.2) — /consultation/ with no ?progress_id
  // ──────────────────────────────────────────────────────────────

  function loadList() {
    fetch(CFG.list_url, {
      headers: { 'X-WP-Nonce': CFG.nonce },
      credentials: 'same-origin'
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data && data.code) {
        root.innerHTML = '<p style="color:#dc2626;padding:40px;text-align:center;">' + esc(data.message || 'Error loading consultations') + '</p>';
        return;
      }
      renderList(data || {});
    })
    .catch(function () {
      root.innerHTML = '<p style="color:#dc2626;padding:40px;text-align:center;">Connection error. Please try again.</p>';
    });
  }

  function renderList(data) {
    var ready    = Array.isArray(data.ready) ? data.ready : [];
    var existing = Array.isArray(data.existing) ? data.existing : [];
    var counts   = data.counts || { ready: 0, in_progress: 0, delivered: 0 };
    var isAdmin  = !!data.is_admin;

    // v0.25.3 — single centered shell wraps the whole list. Without this
    // wrapper the inner elements (page-head, counts, rows) capped at
    // 1180px but stuck to the left edge of .hdlv2-consultation-root
    // because the parent is full-bleed (100vw). Centering happens once,
    // here, instead of per-element margin gymnastics.
    var inner = '';

    if (!ready.length && !existing.length) {
      inner = renderListPageHead() + renderListEmptyState();
      root.innerHTML = '<div class="cl-shell">' + inner + '</div>';
      return;
    }

    inner = renderListPageHead() + renderListCounts(counts);

    if (ready.length) {
      inner += '<div class="cl-section-head">'
        +   '<h2>Ready to start</h2>'
        +   '<span class="cl-count-pill">' + ready.length + '</span>'
        +   '<span class="cl-section-sub">Stage&nbsp;3 complete &middot; awaiting your first consultation</span>'
        + '</div>'
        + '<p class="cl-section-lead">These clients have finished their full assessment. Open one to review their draft report and add your clinical notes.</p>';
      for (var i = 0; i < ready.length; i++) {
        inner += renderListRow(ready[i], 'ready', isAdmin);
      }
    }

    if (existing.length) {
      inner += '<div class="cl-section-head">'
        +   '<h2>In progress and completed</h2>'
        +   '<span class="cl-count-pill">' + existing.length + '</span>'
        +   '<span class="cl-section-sub">Pick up where you left off, or re-edit a delivered report</span>'
        + '</div>';
      for (var j = 0; j < existing.length; j++) {
        inner += renderListRow(existing[j], 'existing', isAdmin);
      }
    }

    root.innerHTML = '<div class="cl-shell">' + inner + '</div>';
  }

  function renderListPageHead() {
    var clientsUrl = CFG.clients_url || '/clients/';
    return '<header class="cl-page-head">'
      +   '<div>'
      +     '<h1 class="cl-h1">Consultations</h1>'
      +     '<p class="cl-lead">Review draft reports, add clinical notes, and generate final reports for your clients.</p>'
      +   '</div>'
      +   '<a class="cl-back-link" href="' + esc(clientsUrl) + '">'
      +     '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>'
      +     'Back to clients'
      +   '</a>'
      + '</header>';
  }

  function renderListCounts(counts) {
    var ready  = (counts && typeof counts.ready === 'number') ? counts.ready : 0;
    var inProg = (counts && typeof counts.in_progress === 'number') ? counts.in_progress : 0;
    var deliv  = (counts && typeof counts.delivered === 'number') ? counts.delivered : 0;
    return '<div class="cl-counts">'
      +   '<div class="cl-count-card is-action"><p class="cl-count-label">Ready to start</p><p class="cl-count-value">' + ready + '</p></div>'
      +   '<div class="cl-count-card"><p class="cl-count-label">In progress</p><p class="cl-count-value">' + inProg + '</p></div>'
      +   '<div class="cl-count-card"><p class="cl-count-label">Final delivered</p><p class="cl-count-value">' + deliv + '</p></div>'
      + '</div>';
  }

  function renderListRow(row, kind, isAdmin) {
    var status      = row.status || '';
    var isDelivered = status === 'report_generated';
    var rowClass    = '';
    var actionLabel = 'Open consultation';
    var actionClass = '';
    var statusPill  = '';
    var contextItem = '';

    if (kind === 'ready') {
      rowClass    = ' is-new';
      contextItem = '<span class="item"><span class="label">Stage&nbsp;3 done</span><span class="val">' + esc(formatRelativeTime(row.stage3_completed_at)) + '</span></span>';
    } else {
      if (isDelivered) {
        statusPill  = '<span class="cl-status delivered">Final delivered</span>';
        actionLabel = 'Open & re-edit';
        actionClass = ' ghost';
        contextItem = statusPill
          + '<span class="dot">&middot;</span>'
          + '<span class="item"><span class="label">Sent</span><span class="val">' + esc(formatRelativeTime(row.consult_approved_at || row.consult_created_at)) + '</span></span>';
      } else {
        statusPill  = '<span class="cl-status in-progress">In progress</span>';
        actionLabel = 'Resume';
        contextItem = statusPill
          + '<span class="dot">&middot;</span>'
          + '<span class="item"><span class="label">Last edited</span><span class="val">' + esc(formatRelativeTime(row.consult_created_at || row.consult_started_at)) + '</span></span>';
      }
    }

    var ageMeta = (row.age != null && row.age !== '')
      ? '<span class="dot">&middot;</span><span class="item"><span class="label">Age</span><span class="val">' + esc(String(row.age)) + '</span></span>'
      : '';
    var sexMeta = (row.sex)
      ? '<span class="dot">&middot;</span><span class="item"><span class="label">Sex</span><span class="val">' + esc(row.sex) + '</span></span>'
      : '';

    var rateHtml = '<div class="cl-rate-wrap"></div>';
    if (row.rate != null && !isNaN(row.rate)) {
      var r = Number(row.rate);
      rateHtml = '<div class="cl-rate-wrap"><span class="cl-rate">' + r.toFixed(2) + '<span class="cl-rate-unit">&times;&nbsp;age</span></span></div>';
    }

    var practitionerTag = (isAdmin && row.practitioner_name)
      ? '<span class="cl-admin-tag">Practitioner: ' + esc(row.practitioner_name) + '</span>'
      : '';

    var url = '?progress_id=' + encodeURIComponent(row.progress_id);

    return '<article class="cl-row' + rowClass + '">'
      +   '<div class="cl-avatar">' + esc(row.client_initials || '?') + '</div>'
      +   '<div class="cl-body">'
      +     '<p class="cl-name">' + esc(row.client_name || 'Unknown client') + practitionerTag + '</p>'
      +     '<div class="cl-meta">' + contextItem + ageMeta + sexMeta + '</div>'
      +   '</div>'
      +   rateHtml
      +   '<a class="cl-action' + actionClass + '" href="' + url + '">'
      +     esc(actionLabel)
      +     '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>'
      +   '</a>'
      + '</article>';
  }

  function renderListEmptyState() {
    var clientsUrl = CFG.clients_url || '/clients/';
    return '<div class="cl-empty">'
      +   '<div class="cl-empty-icon">'
      +     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>'
      +   '</div>'
      +   '<h3>No consultations yet</h3>'
      +   '<p>When a client completes Stage&nbsp;3 of their assessment, they’ll appear here ready for your review.<br>'
      +   '<a href="' + esc(clientsUrl) + '" class="cl-empty-link">Invite a client &rarr;</a></p>'
      + '</div>';
  }

  // Format a MySQL DATETIME (server time, treated as UTC) into a relative
  // English label. Falls back to '—' on parse failure. British spelling on
  // the longer units ("a month ago" / "X months ago" — singular/plural only;
  // no "last week" sugar to keep the grammar branch shallow).
  function formatRelativeTime(mysqlDate) {
    if (!mysqlDate) return '—';
    // v0.27.1 — fix #15 (/ultrareview): MySQL CURRENT_TIMESTAMP follows the
    // server's time_zone setting (SYSTEM by default → OS timezone). On a
    // London/BST server, hard-appending 'Z' caused JS to interpret the
    // local timestamp as UTC → relative time off by the TZ offset (in
    // summer "5 minutes ago" rendered as "1h 5m from now"). Remove the
    // forced UTC; let JS parse as local time so client + server agree
    // when they share a TZ (the typical case).
    var iso = String(mysqlDate).replace(' ', 'T');
    var d   = new Date(iso);
    if (isNaN(d.getTime())) return '—';
    var diffMs  = Date.now() - d.getTime();
    if (diffMs < 0) diffMs = 0; // future timestamps clamp to "just now"
    var diffMin = Math.floor(diffMs / 60000);
    if (diffMin < 1)   return 'just now';
    if (diffMin < 60)  return diffMin + ' minute' + (diffMin === 1 ? '' : 's') + ' ago';
    var diffHr  = Math.floor(diffMin / 60);
    if (diffHr < 24)   return diffHr + ' hour' + (diffHr === 1 ? '' : 's') + ' ago';
    var diffDay = Math.floor(diffHr / 24);
    if (diffDay < 7)   return diffDay + ' day' + (diffDay === 1 ? '' : 's') + ' ago';
    if (diffDay < 30) {
      var w = Math.floor(diffDay / 7);
      return w + ' week' + (w === 1 ? '' : 's') + ' ago';
    }
    if (diffDay < 365) {
      var mo = Math.floor(diffDay / 30);
      return mo + ' month' + (mo === 1 ? '' : 's') + ' ago';
    }
    var y = Math.floor(diffDay / 365);
    return y + ' year' + (y === 1 ? '' : 's') + ' ago';
  }

  // ──────────────────────────────────────────────────────────────
  //  v0.28.0 — CONSULTATION ADDENDA
  //
  //  Practitioner-appended, timestamped observations between the original
  //  consultation and the next quarterly Part. Source: Matthew transcript
  //  2026-04-30 ("never change past information, you can only add to it").
  //
  //  The section renders only post-Final (addenda are by definition
  //  observations made AFTER the original consultation has been finalised).
  //  Pre-Final, renderAddendaSection returns an empty string and the
  //  hdlv2-addenda-wrap div is invisible.
  //
  //  Flow:
  //    1. Practitioner types in textarea OR taps mic / upload (audio-only
  //       file picker) → live transcript fills the textarea.
  //    2. Practitioner sets editable timestamp + priority.
  //    3. Click "Save & Update Plan" → modal confirms (P-002 honest copy
  //       — client will receive a SECOND email + PDF) → POST /addendum
  //       (writes the row) → POST /save-and-update-plan (re-fires Claude
  //       with original consultation + every addendum) → success card with
  //       the new Final Report's "Generated DD MMM YYYY · HH:MM" timestamp.
  //
  //  Past addenda render as a stacked timeline above the entry form.
  //  Read-only after submit. Each card carries date · priority badge ·
  //  voice-source badge (when applicable) · author · note text.
  //
  //  See hdl-longevity-v2/CONSULTATION-ADDENDA-DESIGN.md.
  // ──────────────────────────────────────────────────────────────

  function renderAddendaSection(data) {
    // Pre-Final: hide the section entirely. Addenda are post-Final.
    if (!data || !data.final_report) return '';

    var addenda = Array.isArray(data.addenda) ? data.addenda.slice() : [];

    // Show newest first. Server returns chronological (oldest first), so
    // reverse for display. The AI prompt still consumes them oldest-first
    // server-side (latest is highest-priority intelligence).
    addenda.reverse();

    // v0.34.3 — Phase N recovery banner. Set by the Phase N migration when
    // a corrupted Final Report row is healed. The transient is deleted by
    // ::regenerate() on success, so the banner self-clears after the
    // practitioner refreshes the report content.
    var bannerHtml = '';
    if (data.phase_n_recovered_at) {
      var recoveredDate = formatAddendumDateTime(data.phase_n_recovered_at);
      bannerHtml = ''
        + '<div class="hdlv2-phase-n-banner" role="note">'
        +   '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        +     '<circle cx="12" cy="12" r="10"></circle>'
        +     '<line x1="12" y1="8" x2="12" y2="13"></line>'
        +     '<line x1="12" y1="16" x2="12.01" y2="16"></line>'
        +   '</svg>'
        +   '<div class="hdlv2-phase-n-banner__body">'
        +     '<strong>Trajectory Plan was recovered.</strong> '
        +     'A corrupted state was repaired on ' + esc(recoveredDate) + '. '
        +     'The displayed report content reflects an earlier version. '
        +     'Click <strong>Save &amp; Update Plan</strong> once to publish the latest content to your client.'
        +   '</div>'
        + '</div>';
    }

    // v0.33.2 — render each addendum as a collapsible <details> entry so
    // multiple addenda don't force the practitioner to scroll a wall of
    // text. Summary line shows date · priority. Default: only the newest
    // entry stays open; older entries collapse so the timeline is scannable.
    var timelineHtml = addenda.length
      ? addenda.map(function (a, i) { return renderAddendumCard(a, i === 0); }).join('')
      : '<p class="hdlv2-addenda-empty">No addenda yet. Add the first one below — anything that has changed since the Trajectory Plan was generated.</p>';

    var defaultDate = formatDateTimeLocalNow();

    return bannerHtml
      + '<div class="hdlv2-organised-head">'
      +   '<h4>Consultation Addenda</h4>'
      +   '<span class="hdlv2-addenda-count">' + addenda.length + ' ' + (addenda.length === 1 ? 'entry' : 'entries') + '</span>'
      + '</div>'
      + '<p class="hdlv2-consult-hint">Add timestamped observations after the Trajectory Plan has been generated. Past addenda are read-only — Matthew\'s rule: never change past information, only add to it.</p>'

      + '<div class="hdlv2-addenda-timeline">' + timelineHtml + '</div>'

      + '<div class="hdlv2-addendum-form" id="hdlv2-addendum-form">'
      +   '<div class="hdlv2-addendum-form-h">＋ Add an addendum</div>'

      +   '<div class="hdlv2-addendum-row">'
      +     '<div class="hdlv2-addendum-field">'
      +       '<label class="hdlv2-addendum-label">Date &amp; time</label>'
      +       '<input type="datetime-local" id="hdlv2-addendum-occurred" class="hdlv2-addendum-input" value="' + esc(defaultDate) + '">'
      +     '</div>'
      +     '<div class="hdlv2-addendum-field">'
      +       '<label class="hdlv2-addendum-label">Priority</label>'
      +       '<select id="hdlv2-addendum-priority" class="hdlv2-addendum-input">'
      +         '<option value="high">High</option>'
      +         '<option value="medium" selected>Medium</option>'
      +         '<option value="low">Low</option>'
      +       '</select>'
      +     '</div>'
      +   '</div>'

      +   '<label class="hdlv2-addendum-label" style="margin-top:4px;">Note</label>'
      +   '<div class="hdlv2-consult-notes-bar">'
      +     '<textarea id="hdlv2-addendum-text" class="hdlv2-consult-notes-input" rows="6" placeholder="What has changed since the Trajectory Plan? Observations, allergies, lifestyle changes, lab results."></textarea>'
      +     '<div class="hdlv2-consult-notes-iconbar" id="hdlv2-addendum-audio"></div>'
      +   '</div>'

      +   '<div class="hdlv2-addendum-reminder" role="note">'
      +     '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>'
      +     '<span><strong>Audio files only</strong> — MP3, WAV, M4A, WebM. Photos, PDFs and other formats are not accepted; the file picker will only show audio files.</span>'
      +   '</div>'

      +   '<div class="hdlv2-addendum-actions">'
      +     '<button id="hdlv2-addendum-submit" class="hdlv2-btn hdlv2-consult-generate-btn" type="button">Save &amp; Update Plan</button>'
      +     '<p class="hdlv2-cost-hint">Updates the report with your latest notes and emails the client a fresh PDF.</p>'
      +   '</div>'

      +   '<div id="hdlv2-addendum-status" class="hdlv2-save-status" aria-live="polite"></div>'
      + '</div>';
  }

  // v0.33.2 — addendum entries are now <details> collapsibles. The
  // <summary> carries date · priority pill · voice tag (if voice-captured)
  // and a chevron that flips on open. Body holds the note text. Default:
  // newest entry open, older entries collapsed (caller passes openByDefault
  // for the first item only).
  function renderAddendumCard(a, openByDefault) {
    var when = a && a.occurred_at ? formatAddendumDateTime(a.occurred_at) : '';
    var priority = (a && a.priority) ? String(a.priority).toLowerCase() : 'medium';
    var priorityLabel = priority.charAt(0).toUpperCase() + priority.slice(1);
    var source = (a && a.source === 'voice') ? 'voice' : 'typed';
    var note = (a && a.note_text) ? String(a.note_text) : '';
    var openAttr = openByDefault ? ' open' : '';

    var html = ''
      + '<details class="hdlv2-addendum priority-' + esc(priority) + '"' + openAttr + '>'
      +   '<summary class="hdlv2-addendum-meta">'
      +     '<span class="hdlv2-addendum-chevron" aria-hidden="true">'
      +       '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>'
      +     '</span>'
      +     '<span class="hdlv2-addendum-date">' + esc(when) + '</span>'
      +     '<span class="hdlv2-addendum-tag priority-' + esc(priority) + '">' + esc(priorityLabel) + '</span>';

    if (source === 'voice') {
      html += '<span class="hdlv2-addendum-source" title="Captured via voice">'
        +     '<svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>'
        +     'Voice'
        +   '</span>';
    }

    html += '<span class="hdlv2-addendum-spacer"></span>';
    // Truncated preview of the note in the collapsed state — gives a hint
    // without forcing the practitioner to expand to remember which addendum
    // is which. Visible only when the <details> is closed.
    var preview = note.replace(/\s+/g, ' ').trim().substring(0, 80);
    if (note.length > 80) preview += '…';
    html += '<span class="hdlv2-addendum-preview">' + esc(preview) + '</span>';
    html += '</summary>';
    html += '<div class="hdlv2-addendum-text">' + esc(note).replace(/\n/g, '<br>') + '</div>';
    html += '</details>';
    return html;
  }

  function bindAddendumForm() {
    var wrap = document.getElementById('hdlv2-addendum-form');
    if (!wrap) return;

    // Mount the audio component into the addendum entry form. Same pattern
    // as Consultation Notes (iconsOnlyMode + useAsIsOnly + asyncUpload). Live
    // transcript streams into the textarea; uploaded files transcribe via
    // server fallback (Deepgram). On submit the textarea is the source of
    // truth — the audio is just an input method.
    var audioEl = document.getElementById('hdlv2-addendum-audio');
    if (audioEl && window.HDLAudioComponent) {
      var liveInsertPoint = null;
      HDLAudioComponent.create(audioEl, {
        contextType: 'consultation_addendum',
        apiBase: CFG.api_base.replace(/\/consultation$/, '/audio'),
        nonce: CFG.nonce,
        showConsent: false,
        // v0.30.0 — preloadOnIdle removed (same reasoning as the main
        // consultation recorder above). Addenda also routes through
        // asyncUpload→Deepgram, so the local Whisper preload was 75 MB
        // of dead bandwidth per page load.
        iconsOnlyMode: true,
        useAsIsOnly: true,
        asyncUpload: true,
        referenceId: state.consultId,
        onLiveTranscript: function (combined) {
          var ta = document.getElementById('hdlv2-addendum-text');
          if (!ta) return;
          if (liveInsertPoint === null) {
            var base = ta.value;
            liveInsertPoint = base.length + (base && !base.endsWith('\n') ? 2 : 0);
            if (base && !base.endsWith('\n')) ta.value = base + '\n\n';
          }
          ta.value = ta.value.substring(0, liveInsertPoint) + (combined || '');
          ta.scrollTop = ta.scrollHeight;
          ta.dispatchEvent(new Event('input'));
        },
        onConfirm: function (transcript) {
          var ta = document.getElementById('hdlv2-addendum-text');
          if (!ta) return;
          if (liveInsertPoint !== null) {
            ta.value = ta.value.substring(0, liveInsertPoint) + (transcript || '');
            liveInsertPoint = null;
          } else {
            var sep = ta.value.trim() ? '\n\n' : '';
            ta.value += sep + transcript;
          }
          ta.scrollTop = ta.scrollHeight;
          ta.dispatchEvent(new Event('input'));
          // Mark this addendum as voice-sourced when submitted.
          ta.setAttribute('data-source', 'voice');
        }
      });
    }

    var submitBtn = document.getElementById('hdlv2-addendum-submit');
    if (!submitBtn) return;
    submitBtn.addEventListener('click', onAddendumSubmit);

    // v0.34.3 — Pre-flight rec check. The server-side regenerate() now
    // hard-blocks regens that would produce a Final Report with zero
    // recommendations (post-v0.33.5 hard guard, lifted to pre-flight in
    // v0.34.3). This client-side check catches the avoidable empty-state
    // cases instantly so the practitioner sees a disabled button with a
    // hint tooltip rather than a 5-10s round-trip + a wasted Claude
    // re-organise call. Counts mirror the server's over-count (no dedup):
    // organised + legacy + addenda + currently-typed addendum text.
    var preflightTa = document.getElementById('hdlv2-addendum-text');
    function evaluatePreflightAndUpdateButton() {
      var hasRecs = computeAvailableRecCount() > 0;
      var hasText = preflightTa && preflightTa.value && preflightTa.value.trim().length > 0;
      if (!hasRecs && !hasText) {
        submitBtn.disabled = true;
        submitBtn.setAttribute('title', 'Add a recommendation in the Recommendations section above, or type an addendum action below, before re-issuing the plan.');
      } else if (!submitBtn.classList.contains('hdlv2-submitting')) {
        // Don't re-enable while a regen is in flight (the click handler
        // applies disabled + the hdlv2-submitting class).
        submitBtn.disabled = false;
        submitBtn.removeAttribute('title');
      }
    }
    evaluatePreflightAndUpdateButton();
    if (preflightTa) {
      preflightTa.addEventListener('input', evaluatePreflightAndUpdateButton);
    }
  }

  // v0.34.3 — Count addressable rec sources for the pre-flight check.
  // Mirrors the server-side over-count in regenerate()'s pre-flight gate
  // (organised recs + legacy recs + addenda). Over-count is intentional —
  // if the over-count is 0, the deduped count is also 0; if over-count
  // ≥1, the regen is guaranteed to find at least one rec to ship.
  function computeAvailableRecCount() {
    if (!state.data) return 0;
    var c = state.data.consultation || {};
    var count = 0;
    if (c.ai_organised_notes && Array.isArray(c.ai_organised_notes.recommendations)) {
      c.ai_organised_notes.recommendations.forEach(function (r) {
        if (r && typeof r.text === 'string' && r.text.trim() !== '') count++;
      });
    }
    if (Array.isArray(c.recommendations)) {
      c.recommendations.forEach(function (r) {
        if (r && typeof r.text === 'string' && r.text.trim() !== '') count++;
      });
    }
    if (Array.isArray(state.data.addenda)) {
      state.data.addenda.forEach(function (a) {
        if (a && typeof a.note_text === 'string' && a.note_text.trim() !== '') count++;
      });
    }
    return count;
  }

  function onAddendumSubmit() {
    var ta = document.getElementById('hdlv2-addendum-text');
    var occurredEl = document.getElementById('hdlv2-addendum-occurred');
    var prioEl = document.getElementById('hdlv2-addendum-priority');
    var submitBtn = document.getElementById('hdlv2-addendum-submit');
    if (!ta || !submitBtn) return;

    // v0.28.2 — empty addendum is now valid: it means "regenerate the Final
    // Report using my auto-saved AI summary edits, no new addendum to add".
    // Skip the addendum-write step in submitAddendumAndUpdatePlan for that
    // path. The confirm-modal copy adapts.
    var noteText = (ta.value || '').trim();
    var hasAddendum = noteText !== '';

    var occurredAt = occurredEl && occurredEl.value
      ? new Date(occurredEl.value).toISOString().replace('T', ' ').replace(/\..+$/, '')
      : '';
    var priority = prioEl ? prioEl.value : 'medium';
    var source = ta.getAttribute('data-source') === 'voice' ? 'voice' : 'typed';

    var doConfirm = (window.HDLV2UI && typeof window.HDLV2UI.confirm === 'function')
      ? window.HDLV2UI.confirm
      : function (opts) { return Promise.resolve(window.confirm(opts.body || opts.title)); };

    var modalOpts = hasAddendum
      ? {
          title: 'Save addendum and re-issue the plan?',
          body: 'This will:\n• Save your addendum (timestamped, never deleted)\n• Re-run the AI with the original consultation + every addendum\n• Generate a new Trajectory Plan and Flight Plan\n• Send a fresh email + PDF to your client (they will receive a second email)',
          confirmLabel: 'Yes, save and update',
          cancelLabel: 'Cancel'
        }
      : {
          title: 'Re-issue the plan now?',
          body: 'No new addendum to save — your AI summary edits above are already auto-saved. Continuing will:\n• Re-run the AI with the existing consultation + addenda\n• Generate a new Trajectory Plan and Flight Plan\n• Send a fresh email + PDF to your client (they will receive a second email)',
          confirmLabel: 'Yes, re-issue plan',
          cancelLabel: 'Cancel'
        };

    doConfirm(modalOpts).then(function (ok) {
      if (ok) submitAddendumAndUpdatePlan(noteText, occurredAt, priority, source, hasAddendum);
    });
  }

  function submitAddendumAndUpdatePlan(noteText, occurredAt, priority, source, hasAddendum) {
    var submitBtn = document.getElementById('hdlv2-addendum-submit');
    if (!submitBtn) return;

    var SPINNER = '<span class="hdlv2-spinner" aria-hidden="true"></span>';
    function restoreButton() {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Save & Update Plan';
    }

    // v0.46.0 — regeneration now runs ASYNC on the job queue (see
    // showReportPreparing / pollReportJob). The old fake setTimeout ladder +
    // the v0.34.3 client-side re-verify step are gone: the poll's 'completed'
    // branch reloads the consultation from fresh server state, which both
    // confirms the new Final row and refreshes the addenda timeline. The
    // server-side guards (DB-return checks → WP_Error) still hold, surfacing
    // as a failed job that the poll turns into a "Try again" card.
    var firstMsg = hasAddendum ? 'Saving your addendum…' : 'Reading your edits…';
    // Set true once we've shown a SPECIFIC error, so the trailing .catch
    // doesn't clobber it with the generic 'Connection error' message.
    var handled = false;
    submitBtn.disabled = true;
    submitBtn.innerHTML = SPINNER + '<span class="hdlv2-spinner-label">' + firstMsg + '</span>';
    setAddendumStatus('loading', firstMsg);

    // Step 1 — POST /consultation/addendum (only when there is new text).
    // When the practitioner only edited the AI summary above (no new addendum),
    // skip the write and go straight to regen; the auto-saved summary edits in
    // ai_organised_notes are picked up by generate() automatically.
    var step1 = hasAddendum
      ? fetch(CFG.api_base + '/addendum', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
          body: JSON.stringify({
            consultation_id: state.consultId,
            note_text: noteText,
            occurred_at: occurredAt,
            priority: priority,
            source: source
          })
        }).then(function (r) { return r.json(); })
      : Promise.resolve({ success: true, addendum: null });

    step1
      .then(function (res) {
        if (!res || !res.success) {
          restoreButton();
          setAddendumStatus('error', (res && res.message) || 'Failed to save the addendum.');
          handled = true;
          throw new Error('addendum failed');
        }
        // Step 2 — kick off the regeneration (returns a job_id immediately).
        return fetch(CFG.api_base + '/save-and-update-plan', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
          body: JSON.stringify({ progress_id: state.progressId, consultation_id: state.consultId })
        });
      })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.success || !res.job_id) {
          restoreButton();
          setAddendumStatus('error', (res && res.message) || 'Could not start the update. Please try again.');
          handled = true;
          return;
        }
        // Hand-off OK — show the preparing UI and poll. On completion the
        // poll reloads the consultation (refreshed report + the new addendum
        // in the timeline). An empty-recommendations failure surfaces in the
        // poll's error card with guidance to add a recommendation first.
        showReportPreparing('regen');
        pollReportJob(res.job_id, 'regen');
      })
      .catch(function () {
        // A specific error (addendum save / could-not-start) was already shown
        // — don't overwrite it with the generic connection message.
        if (handled) return;
        restoreButton();
        setAddendumStatus('error', 'Connection error. Please try again.');
      });
  }

  // v0.33.6 — Recovery panel revealed only when L1 guard fires.
  // Shows the manual + Add and Re-organise buttons + a friendly explainer.
  // Hidden by default; the auto-re-organise path covers the happy case.
  function renderEmptyRecsRecovery() {
    var existing = document.getElementById('hdlv2-empty-recs-recovery');
    if (existing) return; // Don't double-render

    var statusEl = document.getElementById('hdlv2-addendum-status');
    if (!statusEl) return;

    var card = document.createElement('div');
    card.id = 'hdlv2-empty-recs-recovery';
    card.className = 'hdlv2-empty-recs-recovery';
    card.innerHTML = ''
      + '<div class="hdlv2-empty-recs-recovery__head">'
      +   '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>'
      +   '<strong>Couldn\'t extract any recommendations from your notes.</strong>'
      + '</div>'
      + '<p class="hdlv2-empty-recs-recovery__body">Add at least one action manually, or rephrase your notes and let Claude try again.</p>'
      + '<div class="hdlv2-empty-recs-recovery__actions">'
      +   '<button type="button" id="hdlv2-recovery-add-btn" class="hdlv2-btn hdlv2-consult-generate-btn">+ Add a recommendation</button>'
      +   '<button type="button" id="hdlv2-recovery-reorganise-btn" class="hdlv2-btn-secondary">↻ Re-organise the consultation</button>'
      + '</div>';
    statusEl.parentNode.insertBefore(card, statusEl.nextSibling);

    // Wire the recovery buttons. Reuses the existing handlers — the manual
    // form pops over the review panel, the reset endpoint nukes
    // ai_organised_notes and drops to edit stage.
    var addBtn = document.getElementById('hdlv2-recovery-add-btn');
    if (addBtn) {
      addBtn.addEventListener('click', function () {
        // Scroll the review panel into view + open the inline rec form there
        var reviewWrap = document.getElementById('hdlv2-action-wrap');
        if (reviewWrap) {
          reviewWrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
          // Synthesise a +Add button click. If the panel is in review stage
          // but we removed the +Add button, render it on demand here so
          // bindAddRecButton can wire up.
          var formWrap = document.getElementById('hdlv2-add-rec-form-wrap');
          if (formWrap) {
            formWrap.innerHTML = renderInlineRecForm();
            formWrap.hidden = false;
            var textEl = document.getElementById('hdlv2-add-rec-text');
            if (textEl) textEl.focus();
            bindInlineRecForm();
          }
        }
      });
    }
    var reorgBtn = document.getElementById('hdlv2-recovery-reorganise-btn');
    if (reorgBtn) {
      reorgBtn.addEventListener('click', function () {
        if (!confirm(
          'This will discard the current organised summary and let you re-paste the consultation notes. Your inline edits will be lost. Continue?'
        )) return;
        if (autoSaveTimer) { clearTimeout(autoSaveTimer); autoSaveTimer = null; }
        setSaveStatus('');
        reorgBtn.disabled = true;
        reorgBtn.textContent = 'Resetting…';
        fetch(CFG.api_base + '/reset-organised', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
          body: JSON.stringify({ consultation_id: state.consultId })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          reorgBtn.disabled = false;
          reorgBtn.textContent = '↻ Re-organise the consultation';
          if (res && res.success) {
            state.data.consultation.ai_organised_notes = null;
            state.data.consultation.practitioner_approved = 0;
            state.actionStage = 'edit';
            document.getElementById('hdlv2-action-wrap').innerHTML = renderActionStage(state.data.consultation);
            bindActionButton();
            var ta = document.getElementById('hdlv2-consult-notes');
            if (ta) { ta.focus(); ta.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
            // Clear the recovery card now that the practitioner is back in edit stage
            var card = document.getElementById('hdlv2-empty-recs-recovery');
            if (card && card.parentNode) card.parentNode.removeChild(card);
          } else {
            window.HDLV2UI.toast((res && res.message) || 'Could not reset. Please try again.', "error");
          }
        })
        .catch(function () {
          reorgBtn.disabled = false;
          reorgBtn.textContent = '↻ Re-organise the consultation';
          window.HDLV2UI.toast('Connection error — reset not applied.', "error");
        });
      });
    }
  }

  function renderUpdatePlanSuccess(res) {
    var wrap = document.getElementById('hdlv2-addenda-wrap');
    if (!wrap) return;
    var token = state.data && state.data.token ? state.data.token : '';
    // v0.34.3 — append ?from=consultation&progress_id=N so the report page
    // can render a "← Back to consultation" affordance when the practitioner
    // arrives via this link, and so server-side analytics can attribute the
    // viewer-came-from-consultation flow without any UI change for clients.
    var reportUrl = '';
    if (token) {
      var base = window.location.origin + '/longevity-draft-report/?t=' + encodeURIComponent(token);
      if (state.progressId) {
        base += '&from=consultation&progress_id=' + encodeURIComponent(state.progressId);
      }
      reportUrl = base;
    }
    var generatedAt = res && res.generated_at ? formatAddendumDateTime(res.generated_at) : formatAddendumDateTime(new Date().toISOString());
    wrap.innerHTML = ''
      + '<div class="hdlv2-update-success">'
      +   '<div class="hdlv2-update-success-icon">✓</div>'
      +   '<h4>Trajectory Plan regenerated</h4>'
      +   '<p class="hdlv2-update-success-meta">Generated <strong>' + esc(generatedAt) + '</strong> · client + practitioner emails on the way.</p>'
      // v0.34.3 — explicit hint that the report opens in a new tab so the
      // consultation page stays open with all in-progress state preserved
      // (audio component, brief autosave, etc.).
      +   '<p class="hdlv2-update-success-hint">Opens in a new tab — this consultation stays open so you can keep adding addenda below.</p>'
      +   (reportUrl ? '<a class="hdlv2-update-success-link" href="' + esc(reportUrl) + '" target="_blank" rel="noopener">View the new Trajectory Plan →</a>' : '')
      // v0.34.3 — renamed from "Refresh to add another addendum" (sounded
      // like a cache-bust). Implementation stays as window.location.reload
      // — soft re-render would touch DOM, audio component lifecycle, and
      // autosave timers (each a regression vector). Reload re-runs the
      // deterministic init() path; ~1s after a 30s regen wait is invisible.
      +   '<button type="button" class="hdlv2-btn-secondary" id="hdlv2-update-success-reload">Add another addendum</button>'
      + '</div>';

    var reloadBtn = document.getElementById('hdlv2-update-success-reload');
    if (reloadBtn) {
      reloadBtn.addEventListener('click', function () { window.location.reload(); });
    }
  }

  function setAddendumStatus(kind, msg) {
    var el = document.getElementById('hdlv2-addendum-status');
    if (!el) return;
    el.className = 'hdlv2-save-status ' + (kind === 'error' ? 'error' : kind === 'loading' ? 'saving' : kind === 'ok' ? 'saved' : '');
    el.textContent = msg || '';
  }

  // Format a server timestamp (MySQL DATETIME) for the addendum card.
  // Mirrors the v0.27.1 fix #15 approach in formatRelativeTime — strip 'Z',
  // let JS parse as local time so the client/server agree when in same TZ.
  function formatAddendumDateTime(mysqlOrIso) {
    if (!mysqlOrIso) return '';
    var iso = String(mysqlOrIso).replace(' ', 'T');
    var d = new Date(iso);
    if (isNaN(d.getTime())) return '';
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
    return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear()
      + ' · ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  // Build a value string for <input type="datetime-local"> from "now",
  // formatted as YYYY-MM-DDTHH:MM (the format the input expects).
  function formatDateTimeLocalNow() {
    var d = new Date();
    var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
      + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  // ── START ──
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
