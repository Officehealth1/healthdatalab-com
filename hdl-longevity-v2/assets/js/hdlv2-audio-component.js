/**
 * HDL V2 Audio Component — Reusable record/upload/type + AI extract + review.
 *
 * Usage:
 *   HDLAudioComponent.create(containerEl, {
 *     contextType: 'why_collection' | 'consultation_notes' | 'weekly_checkin',
 *     apiBase: '/wp-json/hdl-v2/v1/audio',
 *     nonce: '...',
 *     token: '...',          // optional — for client (token) auth
 *     onConfirm: function(summary) {},
 *     onError: function(msg) {},
 *     showConsent: false      // show recording consent notice
 *   });
 *
 * @package HDL_Longevity_V2
 * @since 0.8.0
 */
window.HDLAudioComponent = (function () {
  // v0.27.1 — fix #13 (/ultrareview): all [HDL-DEBUG] console.log calls are
  // gated on this flag so production users don't see Chrome devtools spam.
  // Flip to true on a debug page (or set window.HDLV2_AC_DEBUG = true in
  // the console) to surface diagnostics. Short-circuit evaluation means
  // `false && console.log(...)` skips the call entirely — no eval cost.
  var HDLV2_AC_DEBUG = !!window.HDLV2_AC_DEBUG;
  try { HDLV2_AC_DEBUG && HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] hdlv2-audio-component.js LOADED', { build: '0.47.26', hasSR: !!window.SpeechRecognition, hasWebkitSR: !!window.webkitSpeechRecognition, isSecureContext: window.isSecureContext, href: location.href }); } catch(e){}
  try { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] browser info', { userAgent: navigator.userAgent, vendor: navigator.vendor, platform: navigator.platform, hardwareConcurrency: navigator.hardwareConcurrency, hasAudioContext: !!(window.AudioContext || window.webkitAudioContext), hasUserActivation: !!navigator.userActivation }); } catch(e){}
  try {
    if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) {
      navigator.mediaDevices.enumerateDevices().then(function (devices) {
        var ai = devices.filter(function (d) { return d.kind === 'audioinput'; });
        HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] audioinput devices at load', { count: ai.length, devices: ai.map(function (d) { return { deviceId: d.deviceId ? d.deviceId.substring(0, 12) + '…' : '(empty)', label: d.label || '(label hidden — grant mic permission to reveal)', groupId: d.groupId ? d.groupId.substring(0, 8) + '…' : '' }; }) });
      }).catch(function (e) { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] enumerateDevices failed', { name: e && e.name, message: e && e.message }); });
    }
    if (navigator.permissions && navigator.permissions.query) {
      navigator.permissions.query({ name: 'microphone' }).then(function (p) {
        HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] mic permission state at load', p.state);
      }).catch(function (e) { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] permission.query failed', { name: e && e.name }); });
    }
  } catch(e){}
  'use strict';

  var ACCEPT = '.mp3,.m4a,.wav,.ogg,.webm';

  // ── Styles (injected once) ──
  function injectStyles() {
    if (document.getElementById('hdlv2-audio-css')) return;
    var s = document.createElement('style');
    s.id = 'hdlv2-audio-css';
    s.textContent = [
      '.hdlv2-ac { font-family: Inter, -apple-system, sans-serif; }',
      '.hdlv2-ac-text { width:100%; min-height:80px; padding:10px 12px; border:1px solid #e4e6ea; border-radius:8px; font-size:16px; font-family:inherit; resize:vertical; box-sizing:border-box; background:#f8f9fb; }', /* v0.46.21 (QA F14) 14px->16px: <16px makes iOS Safari auto-zoom on focus */
      '.hdlv2-ac-text:focus { border-color:#3d8da0; outline:none; }',
      '.hdlv2-ac-row { display:flex; gap:8px; margin-top:10px; align-items:center; flex-wrap:wrap; }',
      '.hdlv2-ac-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; border:none; border-radius:48px; font-family:inherit; cursor:pointer; transition:all 0.15s; }',
      '.hdlv2-ac-btn.primary { background:#3d8da0; color:#fff; font-size:15px; font-weight:600; padding:12px 24px; width:100%; }',
      '.hdlv2-ac-btn.primary:hover { opacity:0.85; }',
      '.hdlv2-ac-btn.secondary { background:#fff; color:#555; border:1px solid #e4e6ea; font-size:13px; font-weight:500; padding:10px 20px; }',
      '.hdlv2-ac-btn.secondary:hover { border-color:#3d8da0; color:#3d8da0; }',
      '.hdlv2-ac-btn.recording { border-color:#dc2626; background:#fef2f2; color:#dc2626; }',
      '.hdlv2-ac-btn:disabled { opacity:0.5; cursor:not-allowed; }',
      '.hdlv2-ac-dot { width:8px; height:8px; border-radius:50%; background:#dc2626; animation:hdlv2-pulse 1s infinite; }',
      '@keyframes hdlv2-pulse { 0%,100%{opacity:1} 50%{opacity:0.3} }',
      '.hdlv2-ac-timer { font-size:12px; color:#dc2626; font-weight:600; font-variant-numeric:tabular-nums; }',
      '.hdlv2-ac-spinner { width:24px; height:24px; border:3px solid #e4e6ea; border-top-color:#3d8da0; border-radius:50%; animation:hdlv2-spin 0.8s linear infinite; margin:16px auto; }',
      '@keyframes hdlv2-spin { to{transform:rotate(360deg)} }',
      '.hdlv2-ac-summary { background:#f8f9fb; border:1px solid #e4e6ea; border-radius:8px; padding:14px; margin-top:12px; font-size:13px; line-height:1.6; color:#444; white-space:pre-wrap; max-height:300px; overflow-y:auto; }',
      '.hdlv2-ac-actions { display:flex; flex-direction:column; gap:10px; margin-top:16px; margin-bottom:8px; }',
      '.hdlv2-ac-actions-secondary { display:flex; gap:8px; justify-content:center; }',
      '.hdlv2-ac-review-label { font-size:13px; font-weight:600; color:#3d8da0; margin:0 0 8px; display:block; }',
      '.hdlv2-ac-consent { font-size:11px; color:#888; margin-top:8px; font-style:italic; }',
      '.hdlv2-ac-error { color:#dc2626; font-size:13px; margin-top:8px; }',
      '.hdlv2-ac-icon-btn:hover { border-color:#3d8da0; background:rgba(61,141,160,0.06); }',
      '.hdlv2-ac-icon-btn:hover svg { stroke:#3d8da0; }',
      '.hdlv2-ac-icon-btn.recording { border-color:#dc2626; background:#fef2f2; animation:hdlv2-pulse 1s infinite; }',
      '.hdlv2-ac-icon-btn.recording svg { stroke:#dc2626; }',
      '.hdlv2-ac-live-transcript { background:#f8f9fb; border:2px solid #3d8da0; border-radius:10px; padding:14px; min-height:60px; font-size:14px; color:#495057; line-height:1.5; margin-top:10px; animation:hdlv2-listen 2s ease-in-out infinite; }',
      '@keyframes hdlv2-listen { 0%,100%{border-color:#3d8da0;box-shadow:0 0 0 0 rgba(61,141,160,0)} 50%{border-color:#5bb0c4;box-shadow:0 0 0 4px rgba(61,141,160,0.1)} }',
      '.hdlv2-ac-transcript-textarea { width:100%; min-height:140px; padding:16px; margin-top:4px; border:1px solid #d1d5db; border-radius:10px; font-size:16px; font-family:inherit; resize:vertical; box-sizing:border-box; background:#f9fafb; line-height:1.6; }', /* v0.46.21 (QA F14) 15px->16px: prevent iOS Safari focus auto-zoom */
      '.hdlv2-ac-transcript-textarea:focus { border-color:#3d8da0; outline:none; box-shadow:0 0 0 2px rgba(61,141,160,0.15); }',
      '@keyframes hdlv2-fadeIn { from{opacity:0;transform:translateY(4px)} to{opacity:1;transform:translateY(0)} }',
      '.hdlv2-ac-fade { animation:hdlv2-fadeIn 0.25s ease-out; }',
      // v0.17.0 — async transcription animation (server-side Deepgram path).
      // Rich view for simpleMode/standard consumers; slim inline dots for iconsOnlyMode.
      '.hdlv2-ac-async { text-align:center; padding:20px 8px; animation:hdlv2-fadeIn 0.3s ease-out; }',
      '.hdlv2-ac-wave { display:inline-flex; align-items:flex-end; justify-content:center; gap:4px; height:40px; margin:0 auto 14px; }',
      '.hdlv2-ac-wave span { display:inline-block; width:4px; background:linear-gradient(180deg,#3d8da0,#5bb0c4); border-radius:2px; animation:hdlv2-wave 1.2s ease-in-out infinite; transform-origin:bottom; }',
      '.hdlv2-ac-wave span:nth-child(1){ height:60%; animation-delay:0s; }',
      '.hdlv2-ac-wave span:nth-child(2){ height:80%; animation-delay:0.1s; }',
      '.hdlv2-ac-wave span:nth-child(3){ height:100%; animation-delay:0.2s; }',
      '.hdlv2-ac-wave span:nth-child(4){ height:80%; animation-delay:0.3s; }',
      '.hdlv2-ac-wave span:nth-child(5){ height:60%; animation-delay:0.4s; }',
      '@keyframes hdlv2-wave { 0%,100%{transform:scaleY(0.3);opacity:0.55} 50%{transform:scaleY(1);opacity:1} }',
      '.hdlv2-ac-async-msg { font-size:14px; color:#3d8da0; font-weight:600; margin:0; letter-spacing:0.2px; }',
      '.hdlv2-ac-async-sub { font-size:12px; color:#888; margin:6px 0 0; font-weight:400; }',
      '.hdlv2-ac-async-msg::after, .hdlv2-ac-icons-status.loading::after { content:""; display:inline-block; width:1.2em; text-align:left; animation:hdlv2-dots 1.4s steps(4,end) infinite; }',
      '@keyframes hdlv2-dots { 0%{content:""} 25%{content:"."} 50%{content:".."} 75%{content:"..."} 100%{content:""} }',
      '.hdlv2-ac-icons-status.loading { color:#3d8da0; font-weight:500; font-size:13px; padding:0 8px; animation:hdlv2-status-pulse 2s ease-in-out infinite; }',
      '@keyframes hdlv2-status-pulse { 0%,100%{opacity:1} 50%{opacity:0.65} }',
    ].join('\n');
    document.head.appendChild(s);
  }

  // ── Public factory ──
  function create(container, opts) {
    injectStyles();
    return new AudioComponent(container, opts);
  }

  // ── Component class ──
  function AudioComponent(container, opts) {
    this.el = container;
    this.opts = opts || {};
    this.apiBase = opts.apiBase || '';
    this.nonce = opts.nonce || '';
    this.token = opts.token || '';
    this.contextType = opts.contextType || 'why_collection';
    this.onConfirm = opts.onConfirm || function () {};
    this.onChange = opts.onChange || null;
    this.onError = opts.onError || function () {};
    this.showConsent = !!opts.showConsent;
    this.simpleMode = !!opts.simpleMode;
    // E4 (v0.46.47) — the in-browser Whisper tier was removed: preloadOnIdle /
    // whisperModel / whisperNumBeams opts are gone; all blob transcription is
    // server-side Deepgram via /audio/transcribe-async.

    // useAsIsOnly — consumer (e.g. consultation UI) wants raw transcript
    // pushed straight back via onConfirm without showing the
    // Extract-Themes / Continue-Recording review screen. The transcript
    // will be appended to the consumer's own textarea and processed there.
    this.useAsIsOnly = !!opts.useAsIsOnly;

    // iconsOnlyMode — render only mic + upload icons (no textarea / no
    // Process button). Consumer provides its own textarea + CSS-positions
    // the icons inside it. Pair with useAsIsOnly:true.
    this.iconsOnlyMode = !!opts.iconsOnlyMode;

    // v0.31.3 — skipSummaryReview: after Extract Themes returns, route the
    // AI summary directly to onConfirm() instead of rendering the generic
    // JSON-dump review (renderSummary). Used by check-in (sprint-4) where
    // the consumer's own renderReview() displays the structured summary as
    // friendly score-cards + wins/obstacles/comfort-zone bands. Without this
    // flag, non-iconsOnly consumers were dumping nested Claude JSON into a
    // textarea — see screenshots from 2026-05-05. Stage 2 (`why_collection`)
    // does NOT pass this flag — it returns a single readable paragraph that
    // the JSON dump renders fine.
    this.skipSummaryReview = !!opts.skipSummaryReview;

    // v0.31.3 — hide the "Use as-is" button on the transcript-review step
    // when the consumer always wants the AI extraction path. Default behaviour
    // (false) keeps the button so Stage 2 + standard consumers retain the
    // raw-transcript escape hatch. See checkin.js — submitting raw transcript
    // skips the AI summary entirely, breaking adherence scoring + engagement
    // signal computation downstream.
    this.requireExtraction = !!opts.requireExtraction;

    // v0.31.4 — per-consumer label override for the transcript-review primary
    // button. Default copy ("Extract Themes with AI") leaks the implementation
    // detail to clients and reads like AI marketing. Check-in passes
    // 'See my summary' (HDL voice, action-led, no AI mention). Stage 2,
    // timeline, draft-view leave it default — they have different audiences.
    this.extractButtonLabel = (typeof opts.extractButtonLabel === 'string' && opts.extractButtonLabel)
      ? opts.extractButtonLabel
      : 'Extract Themes with AI';

    // onLiveTranscript — fires with the combined interim+final text during
    // recording (Web Speech API only). Used by consultation UI to stream
    // text into the parent's own textarea as the user speaks.
    this.onLiveTranscript = opts.onLiveTranscript || null;

    // v0.17.0 — asyncUpload: route uploaded files to server-side Deepgram
    // via /audio/transcribe-async + job queue instead of the browser's
    // local Whisper pipeline. Required for long audio (1-hour consultations)
    // that would exceed the 120s browser timeout. Pair with referenceId
    // (the consultation_id / why_profile_id / checkin_id the audio belongs to).
    this.asyncUpload = !!opts.asyncUpload;
    this.referenceId = opts.referenceId || null;

    // idleStopMs — AUDIO-DRIVEN silence auto-stop threshold (v0.47.2). The
    // silence meter (_armSilenceMeter) measures real mic loudness and only
    // auto-stops after this many ms of genuine ACOUSTIC silence, so it can
    // never cut off a user who is still talking — unlike the old v0.36.4
    // watchdog, which keyed off Web Speech onresult and so silently stopped a
    // talking user whenever Web Speech stalled (the 2026-06-27 report).
    // Default 20s; consumers override per surface (clinical dictation 30s on
    // consultation/addendum/timeline). Set to 0 to disable auto-stop (user
    // stops manually; pagehide backstop + maxRecordingMs cap still apply).
    this.idleStopMs = (typeof opts.idleStopMs === 'number') ? opts.idleStopMs : 20000;

    this.mediaRecorder = null;
    this.audioChunks = [];
    this.recordingTimer = null;
    this.recordingSeconds = 0;
    this.previousSummary = '';
    this.currentSummary = '';

    this.recognition = null;
    this.transcriptParts = [];
    this.interimTranscript = '';
    this.rawTranscript = '';
    this.isRecording = false;
    this.recognitionRestarts = 0;
    this.textBeforeRecording = '';

    // v0.20.1 — mic-leak fixes. warmupStream already tracked on `this`;
    // promote fallback stream too so stopRecording() + destroy() can
    // force-release it even if the MediaRecorder 'stop' event never fires
    // (DOM detach, page nav, stop() thrown, component re-render mid-record).
    this.warmupStream = null;
    this.fallbackStream = null;
    // `stopping` blocks the onend auto-restart during the grace period, so a
    // late onend from the API can't re-acquire the mic after the user stops.
    this.stopping = false;

    // v0.47.2 — DEEPGRAM-AUTHORITATIVE recording. Audio capture (MediaRecorder)
    // now runs on EVERY recording, in parallel with the optional Web Speech
    // *preview*. The captured blob -> server Deepgram is the single source of
    // truth for the final transcript, so Web Speech failing/dying mid-session
    // (Chrome stall, iOS/incognito no-result, the old 50-restart cap) can no
    // longer lose data — the blob has everything spoken.
    this._previewEnabled = false;   // Web Speech preview running this session?
    this._uploadWanted = false;     // true => mediaRecorder 'stop' ships the blob;
                                    // false (destroy/page-unload) => discard, just release.
    // v0.47.21 (A1) — true from the moment a stop ships a take until the server
    // Deepgram transcript lands (or the job terminally fails). Lets consumers
    // (Stage-2 Submit) block submission via isBusy() so the user can't submit
    // the stale pre-recording text while the authoritative transcript is still
    // uploading/polling — which would fire the Make WHY webhook + mark Stage 2
    // complete on a truncated/empty answer and silently drop the transcript.
    this._jobInFlight = false;
    // Audio-driven silence auto-stop (replaces the old onresult-driven idle
    // watchdog, which cut off a talking user whenever Web Speech stalled).
    // An AnalyserNode measures real mic loudness; we only auto-stop after
    // `idleStopMs` of genuine ACOUSTIC silence — never while sound is present.
    this._silenceCtx = null;
    this._silenceInterval = null;
    this._lastSoundAt = 0;
    // Runaway backstop: hard cap on a single take so a forgotten/looping mic
    // can't grow an unbounded in-RAM blob. 90 min @ 32 kbps opus ~= 21 MB.
    this.maxRecordingMs = (typeof opts.maxRecordingMs === 'number') ? opts.maxRecordingMs : 90 * 60 * 1000;

    // v0.47.25 (A5) — stopCue: play a subtle beep + haptic buzz when recording
    // actually stops, so a user who has looked away from the screen (the
    // 2026-06-27 report: "I wasn't looking at the screen") knows capture ended
    // (manual stop, silence auto-stop, the max-duration cap, or a mic drop).
    // Opt-in so clinical/practitioner dictation surfaces stay silent unless asked.
    this.stopCue = !!opts.stopCue;

    // Page-lifecycle cleanup. Without these, navigating away mid-recording
    // (Divi AJAX nav, modal close, tab close) leaves the mic indicator lit.
    var self = this;
    this._onPageHide = function () {
      // Use the REUSABLE release (not terminal destroy) so a cancelled
      // navigation / bfcache restore doesn't permanently disable recording.
      if (self.isRecording || self.warmupStream || self.fallbackStream || self.recognition || self.mediaRecorder) {
        try { self._release(); } catch (e) {}
      }
    };
    try { window.addEventListener('pagehide', this._onPageHide); } catch (e) {}
    try { window.addEventListener('beforeunload', this._onPageHide); } catch (e) {}

    this.render();
  }

  // ── State: input ──
  AudioComponent.prototype.bindIconButtons = function () {
    var self = this;
    var recBtn = this.el.querySelector('[data-action="record"]');
    var upBtn  = this.el.querySelector('[data-action="upload"]');
    var fileEl = this.el.querySelector('.hdlv2-ac-file');
    if (recBtn) recBtn.addEventListener('click', function () {
      if (self.isRecording) self.stopRecording(recBtn);
      else self.startRecording(recBtn);
    });
    if (upBtn && fileEl) {
      upBtn.addEventListener('click', function () { fileEl.click(); });
      fileEl.addEventListener('change', function (e) {
        var f = e.target.files && e.target.files[0];
        if (f) self.uploadFile(f);
      });
    }
  };

  AudioComponent.prototype.render = function () {
    if (this.iconsOnlyMode) {
      this.el.innerHTML = '<div class="hdlv2-ac hdlv2-ac-icons-only">'
        + '<button type="button" class="hdlv2-ac-icon-btn" data-action="record" title="Record audio">'
        +   '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/></svg>'
        + '</button>'
        + '<button type="button" class="hdlv2-ac-icon-btn" data-action="upload" title="Upload audio file">'
        +   '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>'
        + '</button>'
        + '<input type="file" class="hdlv2-ac-file" accept="' + ACCEPT + '" style="display:none;">'
        + '<div class="hdlv2-ac-icons-status"></div>'
        + '</div>';
      this.bindIconButtons();
      return;
    }
    var self = this;
    var html = '<div class="hdlv2-ac">'
      + '<div style="position:relative;">'
      + '<textarea class="hdlv2-ac-text" style="padding-bottom:44px;" placeholder="Type your thoughts here..."></textarea>'
      + '<div style="position:absolute;bottom:8px;left:8px;display:flex;gap:4px;align-items:center;z-index:2;">'
      + '<button type="button" class="hdlv2-ac-icon-btn" data-action="record" title="Record audio" style="width:32px;height:32px;border-radius:50%;border:1px solid #e4e6ea;background:#fff;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:all 0.15s;">'
      + '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/></svg>'
      + '</button>'
      + '<button type="button" class="hdlv2-ac-icon-btn" data-action="upload" title="Upload audio file" style="width:32px;height:32px;border-radius:50%;border:1px solid #e4e6ea;background:#fff;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:all 0.15s;">'
      + '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>'
      + '</button>'
      + '</div>'
      + '</div>'
      + '<input type="file" accept="' + ACCEPT + '" style="display:none;" data-role="file-input">'
      + (this.simpleMode ? '' : '<div class="hdlv2-ac-row" style="display:none;" data-role="action-row">'
      + '<button type="button" class="hdlv2-ac-btn primary" data-action="submit">Process with AI</button>'
      + '</div>')
      + (this.showConsent ? '<div class="hdlv2-ac-consent">This session may be recorded for your health records. The recording is processed and stored securely.</div>' : '')
      + '<div class="hdlv2-ac-error" style="display:none;" data-role="error"></div>'
      + '</div>';

    this.el.innerHTML = html;

    // Bind events
    var recordBtn = this.el.querySelector('[data-action="record"]');
    var uploadBtn = this.el.querySelector('[data-action="upload"]');
    var fileInput = this.el.querySelector('[data-role="file-input"]');
    recordBtn.addEventListener('click', function () { self.toggleRecording(recordBtn); });
    uploadBtn.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () { if (fileInput.files[0]) self.uploadFile(fileInput.files[0]); });
    var submitBtn = this.el.querySelector('[data-action="submit"]');
    if (submitBtn) submitBtn.addEventListener('click', function () { self.submitText(); });

    // Reveal the "Process with AI" button once the textarea has content (typed or transcribed).
    var textarea = this.el.querySelector('.hdlv2-ac-text');
    if (textarea) {
      textarea.addEventListener('input', function () { self.refreshActionRow(); });
      self.refreshActionRow();
    }
  };

  // Show the submit row only when the textarea has non-empty content.
  // Called from the textarea input event and from every code path that writes
  // ta.value programmatically (speech recognition, Whisper fallback, file upload).
  AudioComponent.prototype.refreshActionRow = function () {
    var row = this.el.querySelector('[data-role="action-row"]');
    var ta  = this.el.querySelector('.hdlv2-ac-text');
    if (!row || !ta) return;
    row.style.display = ta.value.trim().length ? 'flex' : 'none';
  };

  // ── Recording ──
  AudioComponent.prototype.toggleRecording = function (btn) {
    if (this.isRecording) {
      this.stopRecording(btn);
    } else {
      this.startRecording(btn);
    }
  };

  // v0.47.21 (A1) — true while a recording is live (or starting) OR a captured
  // take is still uploading/transcribing. Consumers gate submission on this so
  // the authoritative Deepgram transcript is never lost to an early Submit.
  AudioComponent.prototype.isBusy = function () {
    return !!(this.isRecording || this._starting || this._jobInFlight);
  };

  AudioComponent.prototype.startRecording = function (btn, keepTranscript) {
    var self = this;

    // Microphone requires HTTPS or localhost.
    if (window.isSecureContext === false) {
      this.showError('Microphone requires HTTPS or localhost. Try http://localhost:10008 for local testing, or upload a pre-recorded file.');
      return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      this.showError('Your browser does not support audio recording. Please use Chrome, Edge, Safari, or Firefox, or upload a file.');
      return;
    }

    // v0.47.2 — re-entry guard. isRecording only flips true AFTER getUserMedia
    // resolves, so a fast second click (or a stray caller) would otherwise
    // acquire a SECOND stream + recorder and leak the first. Block until this
    // attempt has begun capture (or failed). Cleared in _beginCapture / _micError
    // / the destroyed-bail / destroy().
    if (this._starting || this.isRecording) return;
    this._starting = true;

    // Web Speech is preview-only sugar. Brave blocks Google's speech service
    // and fires spurious errors, so never preview there; Firefox has no SR at
    // all (no live text, but Deepgram still delivers). _forceNoPreview lets the
    // legacy "Try local transcription" button skip the preview explicitly.
    var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    var isBrave = !!(navigator.brave && typeof navigator.brave.isBrave === 'function');
    this._previewEnabled = !!(SpeechRecognition && !isBrave && !this._forceNoPreview);
    this._forceNoPreview = false;

    // Reset live-preview state. The DELIVERED transcript always comes from
    // Deepgram, so these only seed the on-screen preview.
    if (!keepTranscript) this.transcriptParts = [];
    this.interimTranscript = '';
    this.recognitionRestarts = 0;

    // simpleMode: remember pre-recording textarea content so the live preview
    // can be stripped and replaced by the authoritative Deepgram transcript on
    // stop (prevents preview + final duplication).
    if (this.simpleMode) {
      var ta0 = this.el.querySelector('.hdlv2-ac-text');
      this.textBeforeRecording = ta0 ? ta0.value.trim() : '';
    }

    // ONE getUserMedia stream feeds the authoritative MediaRecorder + the
    // silence meter. Web Speech (when enabled) opens its own capture
    // concurrently — the same concurrency the old warmup already relied on.
    this._pickMic().then(function (realMic) {
      var audioOpts = { echoCancellation: true, noiseSuppression: true, autoGainControl: true };
      if (realMic && realMic.deviceId) audioOpts.deviceId = { exact: realMic.deviceId };
      return navigator.mediaDevices.getUserMedia({ audio: audioOpts });
    }).then(function (stream) {
      self._starting = false;
      if (self._destroyed) { try { stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {} return; }
      self._beginCapture(stream, btn);
      if (self._previewEnabled) { try { self._beginPreview(SpeechRecognition, btn); } catch (e) {} }
      self._armSilenceMeter(stream, btn);
    }).catch(function (err) {
      self._starting = false;
      if (self._destroyed) return; // torn down while getUserMedia was in flight
      self._micError(err);
    });
  };

  // Compat shim: the "Try local transcription" button (showErrorWithFallbackOption)
  // and any legacy caller land here. Under the Deepgram-authoritative model every
  // recording already captures audio + ships it to the server, so this is just a
  // restart that skips the (failed) Web Speech preview.
  AudioComponent.prototype.startRecordingFallback = function (btn) {
    this._forceNoPreview = true;
    this.startRecording(btn);
  };

  // Smart mic selection — prefer a real microphone over virtual/loopback
  // devices that feed silence. Labels are visible only after a prior grant;
  // first run returns null and the browser picks the system default.
  AudioComponent.prototype._pickMic = function () {
    try {
      if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return Promise.resolve(null);
      return navigator.mediaDevices.enumerateDevices().then(function (devices) {
        var mics = devices.filter(function (d) { return d.kind === 'audioinput'; });
        if (mics.length <= 1) return null;
        for (var i = 0; i < mics.length; i++) {
          var label = (mics[i].label || '').toLowerCase();
          if (!label) continue;
          if (label.indexOf('virtual') !== -1) continue;
          if (label.indexOf('stereo mix') !== -1) continue;
          if (label.indexOf('cable') !== -1) continue;
          if (label.indexOf('loopback') !== -1) continue;
          return mics[i];
        }
        return null;
      }).catch(function () { return null; });
    } catch (e) { return Promise.resolve(null); }
  };

  // Authoritative audio capture. Records the mic to a Blob and, on stop,
  // uploads it to server-side Deepgram (uploadFileAsync). Runs on EVERY
  // recording regardless of Web Speech, so nothing spoken is ever lost.
  //
  // v0.47.2 — every take is SELF-CONTAINED in this closure (its own stream,
  // chunks, recorder, start time). A stop that fires LATE (user stopped take A
  // then immediately started take B) must release ONLY take A's stream and
  // upload ONLY take A's chunks — it must never touch take B's instance state.
  AudioComponent.prototype._beginCapture = function (stream, btn) {
    var self = this;
    var chunks = [];          // per-take, closure-scoped — a newer take cannot wipe it
    this.fallbackStream = stream;
    this.audioChunks = chunks;

    var preferred = [
      'audio/webm;codecs=opus',
      'audio/webm',
      'audio/ogg;codecs=opus',
      'audio/ogg',
      'audio/mp4;codecs=mp4a.40.2',
      'audio/mp4'
    ];
    var chosenType = '';
    if (typeof MediaRecorder !== 'undefined' && typeof MediaRecorder.isTypeSupported === 'function') {
      for (var i = 0; i < preferred.length; i++) {
        if (MediaRecorder.isTypeSupported(preferred[i])) { chosenType = preferred[i]; break; }
      }
    }
    // 32 kbps opus is ample for ASR and keeps a 1-hour consultation ~14 MB
    // (well under the server cap). mp4/aac needs a touch more to stay clean.
    var bps = (chosenType && /mp4|mpeg|aac/.test(chosenType)) ? 64000 : 32000;
    var mrOpts = chosenType ? { mimeType: chosenType, audioBitsPerSecond: bps } : { audioBitsPerSecond: bps };
    var recorder = null;
    try {
      recorder = new MediaRecorder(stream, mrOpts);
    } catch (e) {
      try {
        recorder = chosenType ? new MediaRecorder(stream, { mimeType: chosenType }) : new MediaRecorder(stream);
      } catch (e2) {
        try { recorder = new MediaRecorder(stream); } catch (e3) { recorder = null; }
      }
    }
    if (!recorder) {
      // Construction failed on every attempt — release the mic we just acquired
      // so it doesn't stay live until page unload, then surface a clear message.
      try { stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} }); } catch (e) {}
      if (this.fallbackStream === stream) this.fallbackStream = null;
      this.isRecording = false;
      this.showError('Audio recording is not supported in this browser. Please type your answer or upload an audio file.');
      return;
    }
    self.mediaRecorder = recorder;
    var startedAt = Date.now();

    recorder.addEventListener('dataavailable', function (e) {
      if (e.data && e.data.size > 0) chunks.push(e.data);
    });

    recorder.addEventListener('stop', function () {
      // Release THIS take's OWN stream — never self.fallbackStream, which a
      // newer take may already own. Only clear the instance pointer if it still
      // points at this stream.
      try { stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} }); } catch (e) {}
      if (self.fallbackStream === stream) self.fallbackStream = null;
      var superseded = (self.mediaRecorder !== recorder); // a newer take replaced us

      // Discard path: destroy()/page-unload stopped us — do NOT upload (the
      // fetch would be aborted on unload anyway) and do not touch the UI.
      if (!self._uploadWanted) { return; }
      self._uploadWanted = false;

      var actualType = recorder.mimeType || chosenType || 'audio/webm';
      var blob = new Blob(chunks, { type: actualType });
      var durationMs = Date.now() - startedAt; // per-take, immune to a new take resetting recordingSeconds
      if (!blob.size) {
        self._jobInFlight = false; // (A1) nothing to upload — clear busy or Submit stays blocked
        if (!superseded && !self._restoreCarryOnFail()) self.showErrorWithFallbackOption('Recording came back empty. Try again — speak clearly and wait a moment before stopping.');
        return;
      }
      // Minimum-duration/size guard: an accidental tap (start->stop in <1s)
      // makes a header-only blob that Deepgram returns empty, triggering a
      // 10-min retry storm. 4 KB / 1 s — real speech at 32 kbps is ~4 KB/s.
      if (durationMs < 1000 || blob.size < 4 * 1024) {
        self._jobInFlight = false; // (A1) too short to upload — clear busy
        if (!superseded && !self._restoreCarryOnFail()) self.showError('Recording too short. Tap the mic, speak for a moment, then tap again to stop.');
        return;
      }
      var ext = /mpeg|mp3/.test(actualType) ? 'mp3' :
                /mp4|m4a|aac/.test(actualType) ? 'm4a' :
                /ogg/.test(actualType) ? 'ogg' :
                'webm';
      var file = new File([blob], 'recording.' + ext, { type: actualType });
      self.uploadFileAsync(file);
    });

    recorder.start();
    self.isRecording = true;
    self.recordingSeconds = 0;

    // v0.47.22 (A2) — detect a mid-session mic loss (user/OS revokes permission,
    // USB mic unplugged, device grabbed by another app). The audio track fires
    // 'ended'; without this the silence meter reads the dead track's flat-128
    // buffer as "keep alive" (see _armSilenceMeter) and NEVER auto-stops, so the
    // UI sits on "recording" until the 90-min cap with nothing captured. On loss
    // we stop THIS take — flushing whatever was captured so far to Deepgram so
    // nothing already spoken is lost — and surface an honest, actionable message.
    // A late 'ended' from our own stop is ignored: stopRecording flips
    // isRecording=false BEFORE stopping tracks, and we re-check mediaRecorder.
    try {
      stream.getAudioTracks().forEach(function (track) {
        track.addEventListener('ended', function () {
          if (!self.isRecording || self.mediaRecorder !== recorder) return;
          self._onMicLost(btn);
        });
      });
    } catch (e) {}

    btn.classList.add('recording');
    btn.innerHTML = '<span class="hdlv2-ac-dot"></span>';
    btn.title = 'Stop recording';

    self.recordingTimer = setInterval(function () {
      self.recordingSeconds++;
      // Runaway backstop — auto-stop + ship if a single take exceeds the cap.
      if (self.maxRecordingMs > 0 && self.recordingSeconds * 1000 >= self.maxRecordingMs) {
        var b = self.el.querySelector('[data-action="record"]') || self.el.querySelector('.hdlv2-ac-record') || btn;
        if (b) { try { self.stopRecording(b); } catch (e) {} }
      }
    }, 1000);

    // Non-simpleMode / non-iconsOnly surfaces show the component's own live
    // box. simpleMode writes into the shared textarea; iconsOnly streams via
    // onLiveTranscript. (No box when there's no preview — the recording dot +
    // "Transcribing…" on stop are the feedback.)
    if (!this.simpleMode && !this.iconsOnlyMode) {
      var wrapper = this.el.querySelector('.hdlv2-ac');
      if (wrapper && !wrapper.querySelector('.hdlv2-ac-live-transcript')) {
        var div = document.createElement('div');
        div.className = 'hdlv2-ac-live-transcript';
        div.textContent = this._previewEnabled ? 'Listening…' : 'Recording…';
        var firstChild = wrapper.firstElementChild;
        if (firstChild && firstChild.nextSibling) wrapper.insertBefore(div, firstChild.nextSibling);
        else wrapper.appendChild(div);
      }
    }
  };

  // Web Speech live PREVIEW only. Never delivers the transcript (Deepgram
  // does) and never escalates/aborts capture. If it dies, capture is
  // unaffected — the worst case is the on-screen preview stops updating.
  AudioComponent.prototype._beginPreview = function (SpeechRecognition, btn) {
    var self = this;
    var rec;
    try { rec = new SpeechRecognition(); } catch (e) { return; }
    this.recognition = rec;
    rec.continuous = true;
    rec.interimResults = true;
    rec.lang = 'en-AU';
    var bound = rec;

    rec.onresult = function (event) {
      var final = '', interim = '';
      for (var i = event.resultIndex; i < event.results.length; i++) {
        var t = event.results[i][0].transcript;
        if (event.results[i].isFinal) final += t; else interim = t;
      }
      if (final) self.transcriptParts.push(final);
      self.interimTranscript = interim;
      self.updateLiveTranscript();
    };
    // Preview errors are non-fatal and never touch capture. 'no-speech' /
    // 'aborted' are normal; anything else just means no more live text.
    rec.onerror = function () {};
    rec.onend = function () {
      // Web Speech ends on its own silence cycles; restart the PREVIEW while
      // recording so live text keeps flowing. Capped purely to avoid runaway
      // churn — hitting the cap only freezes the preview, never the capture.
      if (self.isRecording && !self.stopping && self.recognition === bound && self.recognitionRestarts < 50) {
        self.recognitionRestarts++;
        try { rec.start(); } catch (e) {}
      }
    };
    try { rec.start(); } catch (e) { /* preview unavailable; capture still runs */ }
  };

  // Audio-driven silence auto-stop. Measures real mic loudness via an
  // AnalyserNode and only stops after `idleStopMs` of genuine ACOUSTIC
  // silence — so it can never cut off a user who is still talking (the
  // root cause of the 2026-06-27 "stopped while I was speaking" report).
  // Degrades gracefully: no AudioContext => no auto-stop (user stops manually;
  // pagehide backstop + max-duration cap still apply).
  AudioComponent.prototype._armSilenceMeter = function (stream, btn) {
    var self = this;
    this._disarmSilenceMeter();
    if (!this.idleStopMs || this.idleStopMs <= 0) return;
    var AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return;
    try {
      var ctx = new AC();
      this._silenceCtx = ctx;
      if (ctx.state === 'suspended' && ctx.resume) { try { ctx.resume(); } catch (e) {} }
      var src = ctx.createMediaStreamSource(stream);
      var analyser = ctx.createAnalyser();
      analyser.fftSize = 512;
      src.connect(analyser);
      var data = new Uint8Array(analyser.fftSize);
      this._lastSoundAt = Date.now();
      var THRESH = 0.015; // RMS of normalised samples; ~quiet-room floor
      this._silenceInterval = setInterval(function () {
        if (!self.isRecording) { self._disarmSilenceMeter(); return; }
        try {
          // v0.47.2 — if the context isn't actually RUNNING (autoplay policy /
          // backgrounded tab), the analyser returns a flat 128 midpoint => rms 0,
          // which would FALSELY auto-stop a talking user. Treat "not measuring"
          // as "keep alive" and keep trying to resume; only a genuinely running
          // meter may auto-stop. This closes a re-introduction of the exact
          // "stopped while I was speaking" failure via a different cause.
          if (ctx.state !== 'running') {
            if (ctx.resume) { try { ctx.resume(); } catch (e) {} }
            self._lastSoundAt = Date.now();
            return;
          }
          analyser.getByteTimeDomainData(data);
          var sum = 0, flat = true;
          for (var i = 0; i < data.length; i++) { var d = data[i]; if (d !== 128) flat = false; var v = (d - 128) / 128; sum += v * v; }
          // A perfectly flat 128 buffer means no real samples are flowing yet
          // (not a silent room — a real mic always has a noise floor). Keep alive.
          if (flat) { self._lastSoundAt = Date.now(); return; }
          var rms = Math.sqrt(sum / data.length);
          if (rms > THRESH) {
            self._lastSoundAt = Date.now();
          } else if (Date.now() - self._lastSoundAt > self.idleStopMs) {
            self._disarmSilenceMeter();
            var b = self.el.querySelector('[data-action="record"]') || self.el.querySelector('.hdlv2-ac-record') || btn;
            if (b) { try { self.stopRecording(b); } catch (e) {} }
          }
        } catch (e) {}
      }, 500);
    } catch (e) {
      this._disarmSilenceMeter();
    }
  };

  AudioComponent.prototype._disarmSilenceMeter = function () {
    if (this._silenceInterval) { try { clearInterval(this._silenceInterval); } catch (e) {} this._silenceInterval = null; }
    if (this._silenceCtx) { try { this._silenceCtx.close(); } catch (e) {} this._silenceCtx = null; }
  };

  // getUserMedia failure -> actionable message (same mapping the old fallback used).
  AudioComponent.prototype._micError = function (err) {
    var name = err && err.name;
    if (name === 'NotFoundError') {
      this.showError('No microphone detected. Check your system audio settings and reload the page.');
    } else if (name === 'NotAllowedError' || name === 'SecurityError') {
      this.showError('Microphone permission denied. Click the lock icon in the address bar to grant access.');
    } else if (name === 'NotReadableError') {
      this.showError('Microphone is busy. Close other apps using the mic (Zoom, Teams, etc.) and try again.');
    } else {
      this.showError('Microphone error: ' + (name || 'unknown') + '. Try again or type your answer.');
    }
  };

  // v0.47.22 (A2) — mic/track lost mid-recording. Flush the partial take to
  // Deepgram via stopRecording (which ships the captured blob — NOT _release,
  // which would discard it), then tell the user what happened so they can
  // record the rest. Guarded so one loss yields one message.
  AudioComponent.prototype._onMicLost = function (btn) {
    if (this._micLostHandling) return;
    this._micLostHandling = true;
    var self = this;
    var b = btn || this.el.querySelector('[data-action="record"]') || this.el.querySelector('.hdlv2-ac-record');
    try { this.stopRecording(b); } catch (e) {}
    // stopRecording ships the captured audio (upload → poll) and resets the
    // button; layer an honest notice on top. If the take was too short to
    // upload, the too-short error from the stop handler shows instead — either
    // way the user is no longer stuck on a dead "recording" state.
    this.showError('Microphone disconnected. We saved what we captured — tap the mic to record the rest.');
    setTimeout(function () { self._micLostHandling = false; }, 1500);
  };

  AudioComponent.prototype.stopRecording = function (btn) {
    var self = this;
    // v0.47.2 — re-entry guard. Only one stop per take: the silence meter, the
    // max-duration cap, and the user click can all race; the first flips
    // isRecording false and the rest no-op here. (toggleRecording already keys
    // off isRecording, so a click after auto-stop starts a fresh take, not a
    // second stop.)
    if (!this.isRecording) return;
    this.isRecording = false;
    this.stopping = true;

    // Stop the silence meter first so a late tick can't re-enter stopRecording.
    this._disarmSilenceMeter();

    // Stop the Web Speech PREVIEW. It never delivers the transcript (Deepgram
    // is authoritative), so there is no grace period to wait for — just abort.
    // Null self.recognition first so a late onend's instance guard cannot
    // restart the preview after stop.
    if (this.recognition) {
      var rec = this.recognition;
      this.recognition = null;
      try { rec.abort(); } catch (e) {}
      try { rec.stop(); } catch (e) {}
    }
    this.removeLiveTranscript();

    // simpleMode: strip the live-preview text so the authoritative Deepgram
    // transcript (delivered by the poll) replaces it cleanly instead of being
    // appended after it.
    if (this.simpleMode) {
      var ta = this.el.querySelector('.hdlv2-ac-text');
      if (ta) {
        ta.value = this.textBeforeRecording || '';
        if (this.onChange) this.onChange(ta.value);
      }
    }

    // Stop the recording timer.
    if (this.recordingTimer) { try { clearInterval(this.recordingTimer); } catch (e) {} this.recordingTimer = null; }

    this.stopping = false;

    // v0.47.25 (A5) — recording has genuinely stopped; cue the user (may be
    // looking away). Fires for manual stop, silence auto-stop, the cap, and
    // mic-loss (all funnel through here); NOT for page-unload (_release).
    this._playStopCue();

    // Ship the captured blob: mark upload wanted, then stop the recorder so
    // its 'stop' handler uploads to Deepgram (it reads mediaRecorder.mimeType,
    // so do NOT null it here — _beginCapture replaces it on the next take).
    // v0.47.2 — reset the record button to its idle (grey mic) state now. On
    // iconsOnly surfaces the button persists through transcription (only the
    // status div changes), so without this the mic stays red the whole upload+
    // poll window and stuck red after an error. resetRecordBtn is a no-op for
    // the non-iconsOnly DOM that showAsyncProcessing is about to replace.
    if (btn) { try { this.resetRecordBtn(btn); } catch (e) {} }

    // Ship the captured blob: mark upload wanted, then stop the recorder so
    // its 'stop' handler uploads to Deepgram (it reads mediaRecorder.mimeType,
    // so do NOT null it here — _beginCapture replaces it on the next take).
    if (this.mediaRecorder) {
      this._uploadWanted = true;
      this._jobInFlight = true; // (A1) busy from stop until the transcript lands (closes the stop→upload gap)
      try { this.mediaRecorder.stop(); } catch (e) {}
    } else {
      // No capture (mic never granted / already released) — force-release any
      // stray stream.
      if (this.fallbackStream) {
        try { this.fallbackStream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} }); } catch (e) {}
        this.fallbackStream = null;
      }
    }
  };

  AudioComponent.prototype.resetRecordBtn = function (btn) {
    btn.classList.remove('recording');
    btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/></svg>';
    btn.title = 'Record audio';
  };

  // v0.47.25 (A5) — subtle audible + haptic cue that recording has stopped.
  // Opt-in (stopCue). Fully wrapped in try/catch and degrades silently where
  // the WebAudio API or vibration is unavailable (e.g. iOS has no vibrate).
  AudioComponent.prototype._playStopCue = function () {
    if (!this.stopCue) return;
    try { if (navigator.vibrate) navigator.vibrate(60); } catch (e) {}
    try {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return;
      var ctx = new AC();
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.value = 660;
      gain.gain.value = 0.04; // quiet — a soft confirmation, not an alarm
      osc.connect(gain); gain.connect(ctx.destination);
      osc.start();
      try { gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.18); } catch (e) {}
      osc.stop(ctx.currentTime + 0.2);
      setTimeout(function () { try { ctx.close(); } catch (e) {} }, 450);
    } catch (e) {}
  };

  // v0.20.1 — hard teardown. Call from consumer code when the component's
  // container is being removed, or when the flow moves past a recording step
  // and you want to guarantee the mic indicator goes dark. Idempotent; safe
  // to call repeatedly. Page-lifecycle events (pagehide/beforeunload) call
  // this automatically via the listener wired in the constructor.
  //
  // v0.47.2 — sets _uploadWanted=false BEFORE stopping the recorder so a
  // page-unload teardown DISCARDS the in-flight blob (the upload fetch would
  // be aborted on unload anyway) instead of firing a doomed request.
  // v0.47.2 — REUSABLE release. Stops all live capture/recognition/timers and
  // DISCARDS any in-flight blob, but leaves the instance reusable (listeners
  // intact, _destroyed NOT set). This is what pagehide/beforeunload call: a real
  // unload releases the mic, while a CANCELLED navigation or bfcache restore
  // leaves recording fully usable afterwards (the old code called the terminal
  // destroy() here, which permanently disabled recording on a cancelled nav).
  AudioComponent.prototype._release = function () {
    this.isRecording = false;
    this.stopping = true;
    this._uploadWanted = false; // unload teardown discards the captured blob
    this._starting = false;
    this._jobInFlight = false; // (A1) teardown abandons any in-flight upload/poll

    if (this.recordingTimer) { try { clearInterval(this.recordingTimer); } catch (e) {} this.recordingTimer = null; }
    this._disarmSilenceMeter();

    if (this.warmupStream) {
      try { this.warmupStream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} }); } catch (e) {}
      this.warmupStream = null;
    }
    if (this.fallbackStream) {
      try { this.fallbackStream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} }); } catch (e) {}
      this.fallbackStream = null;
    }

    if (this.recognition) {
      // abort() is stronger than stop() — forces Chrome to drop the SR-internal
      // mic immediately. abort first, then stop as a safety-net.
      try { this.recognition.abort(); } catch (e) {}
      try { this.recognition.stop(); } catch (e) {}
      this.recognition = null;
    }

    if (this.mediaRecorder) {
      try { this.mediaRecorder.stop(); } catch (e) {}
      this.mediaRecorder = null;
    }

    this.stopping = false; // re-record is not blocked after a cancelled-nav release
  };

  // Terminal teardown for explicit consumer use (the container is being removed
  // for good). Releases everything AND removes the page-lifecycle listeners and
  // marks the instance dead. NOT wired to pagehide (that uses _release()).
  AudioComponent.prototype.destroy = function () {
    this._release();
    this._destroyed = true;
    if (this._onPageHide) {
      try { window.removeEventListener('pagehide', this._onPageHide); } catch (e) {}
      try { window.removeEventListener('beforeunload', this._onPageHide); } catch (e) {}
      this._onPageHide = null;
    }
  };

  AudioComponent.prototype.removeLiveTranscript = function () {
    var el = this.el.querySelector('.hdlv2-ac-live-transcript');
    if (el) el.parentNode.removeChild(el);
  };

  // ── Upload / Process Audio ──
  AudioComponent.prototype.uploadFile = function (file) {
    // v0.47.2 — don't start a file upload while a live recording is in flight:
    // it would leave the mic + silence meter running and wipe the DOM out from
    // under the active take (concurrent pipelines). Make the user stop first.
    if (this.isRecording || this._starting) {
      this.showError('Please stop the current recording before uploading a file.');
      return;
    }
    // E4 (v0.46.47) — always server-side Deepgram via /audio/transcribe-async
    // (the in-browser Whisper tier was removed). referenceId is optional for
    // client contexts (why_collection / weekly_checkin) — the transcript
    // comes back via onConfirm and the form's own submit flow persists it.
    this.uploadFileAsync(file);
  };

  // v0.17.0 — async upload path.
  // POST the file to /audio/transcribe-async, receive a job_id, poll
  // /jobs/{id}/status every 3s, call onConfirm(transcript) on success.
  // Shows progressive status: Uploading → Transcribing → Organising → Ready.
  AudioComponent.prototype.uploadFileAsync = function (file) {
    var self = this;
    // v0.33.3 — Adaptive polling. Was constant 5s; that left short audio
    // (1-3 sec recordings on the addendum form) hanging for 5-7s after
    // Deepgram had already finished. Now: fast polls early, back off to
    // the 5s steady-state for long audio. See getNextPollInterval().
    var MAX_POLL_MS = 8 * 60 * 1000; // 8 minutes — covers 1-hour audio

    this.showAsyncProcessing( 'uploading' );
    this._jobInFlight = true; // (A1) busy until the transcript lands or the job terminally fails

    // apiBase for the async endpoint — audio component's apiBase is
    // .../hdl-v2/v1/audio; transcribe-async lives at .../hdl-v2/v1/audio/transcribe-async.
    var base = this.apiBase.replace(/\/$/, '');
    var uploadUrl = base + '/transcribe-async';

    // v0.47.24 (A4) — ONE stable idempotency key for the whole upload, shared by
    // every retry below. rest_transcribe_async is idempotency-wrapped (scoped per
    // token), so if a retried POST was in fact already processed server-side
    // (its response lost in transit) the server REPLAYS the first result instead
    // of storing a second file + charging Deepgram again. Sent as both the
    // Idempotency-Key header and an idempotency_key form field (the server reads
    // header first, then param) so the dedupe lands regardless of header casing.
    var idemKey = 'up_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 12);

    function buildForm() {
      var fd = new FormData();
      fd.append('audio', file);
      fd.append('context_type', self.contextType);
      fd.append('reference_id', String(self.referenceId || 0));
      fd.append('idempotency_key', idemKey);
      if (self.token) fd.append('token', self.token);
      return fd;
    }

    function fail(msg) {
      self._jobInFlight = false; // (A1) terminal upload failure — clear busy
      if (!self._restoreCarryOnFail()) self.showError(msg);
    }

    // v0.47.24 (A4) — bounded retry with offline-awareness. A single dropped
    // POST used to force the user to re-record. Now: if the device is offline we
    // WAIT for it to come back (no wasted attempt), and we retry transient
    // failures (network error / 5xx) a couple of times with backoff. 4xx
    // (413 too large / 429 rate-limited / 400) surface immediately — retrying
    // those is pointless or harmful. Idempotency (above) keeps retries safe.
    var MAX_UPLOAD_RETRIES = 2;

    function attempt(triesLeft) {
      if (navigator && navigator.onLine === false) {
        self.showAsyncProcessing('retrying', 'Waiting for your connection');
        var onBack = function () {
          try { window.removeEventListener('online', onBack); } catch (e) {}
          attempt(triesLeft);
        };
        try { window.addEventListener('online', onBack); }
        catch (e) { setTimeout(function () { attempt(triesLeft); }, 3000); }
        return;
      }
      var backoff = (MAX_UPLOAD_RETRIES - triesLeft + 1) * 1500;
      fetch(uploadUrl, {
        method: 'POST',
        headers: Object.assign({ 'Idempotency-Key': idemKey }, self.nonce ? { 'X-WP-Nonce': self.nonce } : {}),
        body: buildForm(),
      })
        .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
        .then(function (res) {
          if (res.status === 200 && res.body && res.body.job_id) {
            self.pollTranscriptionJob(res.body.job_id, MAX_POLL_MS, Date.now());
            return;
          }
          if (res.status >= 500 && triesLeft > 0) {
            self.showAsyncProcessing('retrying');
            setTimeout(function () { attempt(triesLeft - 1); }, backoff);
            return;
          }
          var msg = (res.body && (res.body.message || res.body.code)) || 'Upload failed';
          fail('Upload failed: ' + msg);
        })
        .catch(function () {
          if (triesLeft > 0) {
            self.showAsyncProcessing('retrying');
            setTimeout(function () { attempt(triesLeft - 1); }, backoff);
            return;
          }
          fail('Upload failed. Please check your connection and try again.');
        });
    }

    attempt(MAX_UPLOAD_RETRIES);
  };

  // Poll a transcription job. Follow `chain_job_id` if the server queued
  // an organise step on top. First "completed" job whose result has a
  // transcript lands in onConfirm — the chained organise is fire-and-forget
  // from the component's perspective (the consultation UI refreshes its
  // own state when the practitioner clicks Generate Final Report, and
  // the organise endpoint is idempotent).
  //
  // v0.33.3 — Adaptive polling instead of constant 5s. Catches short audio
  // (1-3 sec) inside ~3 seconds instead of ~7. Long audio (1-hour
  // consultations) still backs off to 5s steady-state so we don't hammer
  // the rate-limit bucket. The pending-state worker re-kick on the server
  // side (rest_status v0.33.3) means each poll also nudges the queue,
  // which catches missed loopback kicks from enqueue.
  function getNextPollInterval(attempts) {
    if (attempts < 5) return 1000;     // First 5 polls fast (≤4s elapsed)
    if (attempts < 10) return 2000;    // Next 5 medium (≤14s elapsed)
    return 5000;                       // Long audio: back to 5s steady
  }

  AudioComponent.prototype.pollTranscriptionJob = function (jobId, maxMs, startedAt) {
    var self = this;
    var pollAttempts = 0;
    // Build status URL off the audio apiBase — strip '/audio' to land on
    // .../hdl-v2/v1/jobs/{id}/status. This is resilient to site_url /
    // wp-json structures.
    var rootBase = this.apiBase.replace(/\/audio\/?$/, '').replace(/\/$/, '');
    var baseStatusUrl = rootBase + '/jobs/' + jobId + '/status';
    if (this.token) baseStatusUrl += '?token=' + encodeURIComponent(this.token);

    var tick = function () {
      if (Date.now() - startedAt > maxMs) {
        self._jobInFlight = false; // (A1) gave up polling — clear busy
        self.showError('Transcription is taking longer than expected. Check back in a few minutes.');
        return;
      }
      pollAttempts++;
      // v0.20.11 — cache-bust each poll. Brave (and some Safari configs)
      // aggressively cache same-URL GETs and serve stale "pending" responses,
      // leaving the UI stuck on "Transcribing your audio." even after the
      // server-side job has finished. Unique URL per poll + `cache:'no-store'`
      // forces a fresh request every tick.
      var statusUrl = baseStatusUrl + (baseStatusUrl.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now();
      fetch(statusUrl, {
        headers: self.nonce ? { 'X-WP-Nonce': self.nonce } : {},
        cache: 'no-store',
      })
        .then(function (r) { return r.json(); })
        .then(function (job) {
          if (!job || !job.status) {
            // v0.47.2 — a garbled/transient response (gateway blip, HTML error
            // page that still parses) must NOT abandon a job whose audio is
            // already uploaded and likely still running. Keep polling until
            // maxMs, same as the network .catch below. (Was: showError + stop,
            // which silently lost the transcript on a single bad poll.)
            setTimeout(tick, getNextPollInterval(pollAttempts));
            return;
          }
          if (job.status === 'pending' || job.status === 'running') {
            // Animated waveform card — feels alive, doesn't flicker on re-render
            // because showAsyncProcessing no-ops if it's already rendered.
            self.showAsyncProcessing( job.attempts > 1 ? 'retrying' : 'transcribing' );
            setTimeout(tick, getNextPollInterval(pollAttempts));
            return;
          }
          if (job.status === 'completed') {
            self._jobInFlight = false; // (A1) transcript has arrived — no longer busy
            var transcript = job.result && job.result.transcript ? String(job.result.transcript) : '';
            if (!transcript) {
              // v0.47.2 — even an empty new segment must not drop a carried
              // first segment: if "Continue Recording" carried prior text,
              // deliver that rather than erroring.
              if (self._carryText) { transcript = self._carryText; self._carryText = ''; }
              else { self.showError('No speech detected. Try again or type your notes.'); return; }
            }
            // v0.47.2 — carry-over from "Continue Recording": the new blob only
            // holds the SECOND take, so prepend the prior reviewed text or the
            // first segment is lost under the Deepgram-authoritative model.
            if (self._carryText) {
              transcript = self._carryText + '\n\n' + transcript;
              self._carryText = '';
            }
            // Deliver transcript to the consumer — 3-way branch by consumer
            // mode (simpleMode / iconsOnly+useAsIs / review screen).
            if (self.simpleMode) {
              // Stage 2 WHY flow — paste transcript into the shared textarea.
              // Restore any text the user typed BEFORE the async waveform
              // card replaced the DOM (preserved by showAsyncProcessing).
              var savedText = ( self._savedTextDuringAsync || '' ).trim();
              self._savedTextDuringAsync = null;
              self.render();
              var ta = self.el.querySelector('.hdlv2-ac-text');
              if (ta) {
                ta.value = savedText + (savedText ? '\n\n' : '') + transcript;
                if (typeof self.onChange === 'function') self.onChange(ta.value);
              }
            } else if (self.iconsOnlyMode || self.useAsIsOnly) {
              // Consultation — raw transcript into the practitioner's own
              // textarea via onConfirm. v0.47.2 — do NOT re-render (reset the
              // icons) if a NEW take is already recording (the mic persists on
              // iconsOnly surfaces); that would wipe the live take's DOM. Still
              // deliver this take's transcript.
              if (!self.isRecording && !self._starting) self.render();
              if (typeof self.onConfirm === 'function') self.onConfirm(transcript);
            } else {
              // Weekly check-in / timeline — show the transcript-review screen.
              // v0.47.2 — restore any text the user TYPED before recording
              // (stashed by showAsyncProcessing) so a type-then-record flow
              // doesn't silently drop it; mirrors the simpleMode restore above.
              var typedBefore = ( self._savedTextDuringAsync || '' ).trim();
              self._savedTextDuringAsync = null;
              self.renderTranscriptReview(typedBefore ? (typedBefore + '\n\n' + transcript) : transcript);
            }
            return;
          }
          // failed / cancelled
          self._jobInFlight = false; // (A1) terminal failure — clear busy
          if (!self._restoreCarryOnFail()) self.showError(job.error ? 'Transcription failed: ' + job.error : 'Transcription failed.');
        })
        .catch(function () {
          // Transient network error — keep polling until maxMs.
          // Use the adaptive interval so we don't pile on a flaky network.
          setTimeout(tick, getNextPollInterval(pollAttempts));
        });
    };
    tick();
  };


  // ── Submit Text ──
  AudioComponent.prototype.submitText = function () {
    var textarea = this.el.querySelector('.hdlv2-ac-text');
    var text = textarea ? textarea.value.trim() : '';
    // v0.47.2 — fold in any pending "Continue Recording" carry so typing fresh
    // notes + Process can't silently drop the carried first segment.
    if (this._carryText) {
      text = text ? (this._carryText + '\n\n' + text) : this._carryText;
      this._carryText = '';
    }
    if (!text) {
      this.showError('Please type something or record audio.');
      return;
    }

    var self = this;
    this.showProcessing('Extracting key themes...');

    var payload = {
      text: text,
      context_type: this.contextType,
    };
    if (this.previousSummary) {
      payload.previous_summary = this.previousSummary;
    }
    if (this.token) {
      payload.token = this.token;
    }

    fetch(this.apiBase + '/extract', {
      method: 'POST',
      headers: Object.assign(
        { 'Content-Type': 'application/json' },
        this.nonce ? { 'X-WP-Nonce': this.nonce } : {}
      ),
      body: JSON.stringify(payload),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success && data.summary) {
          self.currentSummary = data.summary;
          self.renderSummary(data.summary);
        } else {
          self.showError(data.message || 'Extraction failed. Please try again.');
        }
      })
      .catch(function () {
        self.showError('Connection error. Please try again.');
      });
  };

  // ── State: processing ──
  AudioComponent.prototype.showProcessing = function (msg) {
    if (this.iconsOnlyMode) {
      var s = this.el.querySelector('.hdlv2-ac-icons-status');
      if (s) { s.textContent = msg || ''; s.className = 'hdlv2-ac-icons-status loading'; }
      return;
    }
    this.el.innerHTML = '<div class="hdlv2-ac" style="text-align:center;padding:20px 0;">'
      + '<div class="hdlv2-ac-spinner"></div>'
      + '<p style="font-size:13px;color:#888;margin:8px 0 0;">' + msg + '</p>'
      + '</div>';
  };

  // ── State: async processing (server-side Deepgram pipeline, v0.17.0) ──
  //   Rich animated view for clients/practitioners waiting for their
  //   upload to transcribe. Different layout per mode:
  //     iconsOnlyMode (consultation)  → slim inline pulsing status text
  //     simpleMode/standard           → animated audio-wave card above textarea
  //
  //   `stage` is one of: 'uploading', 'transcribing', 'retrying', 'finalising'.
  //   `subtext` is optional secondary line (e.g. "This usually takes 30-90s").
  AudioComponent.prototype.showAsyncProcessing = function (stage, subtext) {
    var labels = {
      uploading:    'Uploading audio',
      transcribing: 'Transcribing your audio.',
      retrying:     'Retrying transcription',
      finalising:   'Almost ready',
    };
    var msg = labels[ stage ] || ( stage || 'Processing' );
    // Subtext kept reassuring but no "AI is listening" phrasing — practitioners
    // found the anthropomorphising + 90s hint more alarming than informative.
    var defaultSub = {
      uploading:    'Sending your recording',
      transcribing: '',
      retrying:     'Brief network hiccup — trying again',
      finalising:   'Wrapping up',
    };
    var sub = subtext || defaultSub[ stage ] || '';

    if (this.iconsOnlyMode) {
      var s = this.el.querySelector('.hdlv2-ac-icons-status');
      if (s) {
        s.textContent = msg;
        s.className = 'hdlv2-ac-icons-status loading';
      }
      return;
    }

    // Preserve any text already in the component's textarea (simpleMode
    // consumers keep typing while the async path runs on an uploaded file).
    // v0.27.0 — fix #12 (/ultrareview): the polling loop calls
    // showAsyncProcessing() repeatedly while transcription runs. After the
    // first call, innerHTML has been replaced with the waveform card, so
    // the textarea no longer exists; subsequent calls would clobber
    // _savedTextDuringAsync with empty string. Capture once on first entry.
    if (typeof this._savedTextDuringAsync !== 'string') {
      var prev = this.el.querySelector('.hdlv2-ac-text');
      this._savedTextDuringAsync = prev ? prev.value : '';
    }

    this.el.innerHTML = '<div class="hdlv2-ac hdlv2-ac-async">'
      + '<div class="hdlv2-ac-wave" aria-hidden="true">'
      +   '<span></span><span></span><span></span><span></span><span></span>'
      + '</div>'
      + '<p class="hdlv2-ac-async-msg">' + msg + '</p>'
      + ( sub ? '<p class="hdlv2-ac-async-sub">' + sub + '</p>' : '' )
      + '</div>';
  };

  // ── State: summary review ──
  AudioComponent.prototype.renderSummary = function (summary) {
    var self = this;
    var display = typeof summary === 'string' ? summary : JSON.stringify(summary, null, 2);

    // iconsOnlyMode consumers own their textarea — never inject our own.
    if (this.iconsOnlyMode) {
      self.currentSummary = display;
      if (typeof self.onConfirm === 'function') self.onConfirm(display);
      return;
    }

    // v0.31.3 — skipSummaryReview consumers (check-in) want the AI summary
    // delivered straight to onConfirm so their own renderReview() can show
    // friendly score-cards. The generic JSON dump below is unreadable for
    // nested Claude responses (fitness_adherence, comfort_zone, etc.) and
    // forced clients through a confusing two-confirm sequence. Pass the raw
    // summary object (not the stringified JSON) so the consumer can pick
    // out fields by name.
    if (this.skipSummaryReview) {
      self.currentSummary = summary;
      if (typeof self.onConfirm === 'function') self.onConfirm(summary);
      return;
    }

    this.el.innerHTML = '<div class="hdlv2-ac hdlv2-ac-fade">'
      + '<label class="hdlv2-ac-review-label">Here\'s what we captured:</label>'
      + '<div class="hdlv2-ac-summary">' + escHtml(display) + '</div>'
      + '<div class="hdlv2-ac-actions">'
      + '<button type="button" class="hdlv2-ac-btn primary" data-action="confirm">Confirm</button>'
      + '<div class="hdlv2-ac-actions-secondary">'
      + '<button type="button" class="hdlv2-ac-btn secondary" data-action="addmore">Add more</button>'
      + '<button type="button" class="hdlv2-ac-btn secondary" data-action="redo">Redo</button>'
      + '</div></div></div>';

    if (self.onChange) self.onChange(self.currentSummary);

    this.el.querySelector('[data-action="confirm"]').addEventListener('click', function () {
      self.onConfirm(self.currentSummary);
    });
    this.el.querySelector('[data-action="addmore"]').addEventListener('click', function () {
      self.previousSummary = self.currentSummary;
      self.render();
    });
    this.el.querySelector('[data-action="redo"]').addEventListener('click', function () {
      self.previousSummary = '';
      self.currentSummary = '';
      self.render();
    });
  };

  // ── Live transcript (Web Speech API) ──
  AudioComponent.prototype.updateLiveTranscript = function () {
    var combined = this.transcriptParts.join(' ');
    if (this.interimTranscript) combined += ' ' + this.interimTranscript;

    // Stream live text to the parent page (consultation uses this to
    // write into its own textarea in real-time, Chrome/Safari only).
    if (this.iconsOnlyMode && typeof this.onLiveTranscript === 'function') {
      this.onLiveTranscript(combined);
    }

    if (this.simpleMode) {
      // Write directly into the component's textarea
      var ta = this.el.querySelector('.hdlv2-ac-text');
      if (ta) {
        var base = this.textBeforeRecording;
        ta.value = base + (base ? ' ' : '') + combined;
        ta.scrollTop = ta.scrollHeight;
        if (this.onChange) this.onChange(ta.value);
      }
    } else {
      var el = this.el.querySelector('.hdlv2-ac-live-transcript');
      if (el) el.textContent = combined || 'Listening...';
    }
  };

  // ── State: transcript review ──
  AudioComponent.prototype.renderTranscriptReview = function (transcript) {
    var self = this;
    this.rawTranscript = transcript;

    // iconsOnlyMode consumers own their textarea — push transcript via
    // onConfirm instead of injecting a review textarea into their icon slot.
    if (this.iconsOnlyMode) {
      if (typeof self.onConfirm === 'function') self.onConfirm(transcript);
      return;
    }

    // v0.31.3 \u2014 when requireExtraction is set, we hide the "Use as-is"
    // button. Submitting raw transcript bypasses Claude extraction and
    // produces no scores \u2192 next plan generates blind. Stage 2 + standard
    // consumers keep the button (default).
    var useAsIsBtnHtml = this.requireExtraction
      ? ''
      : '<button type="button" class="hdlv2-ac-btn secondary" data-action="use-asis">Use as-is</button>';

    // v0.31.4 \u2014 primary label honours the per-consumer override
    // (extractButtonLabel). Default text retained so Stage 2 + other
    // consumers are unchanged.
    var primaryLabel = escHtml(this.extractButtonLabel || 'Extract Themes with AI');

    // v0.31.4 \u2014 Continue Recording: SVG mic icon dropped. The recording
    // affordance is the round record button on the input screen; this
    // button is a navigation control, label-only. Decorative icons here
    // were the main "AI-generated dashboard" cue Quim flagged 2026-05-05.
    this.el.innerHTML = '<div class="hdlv2-ac hdlv2-ac-fade">'
      + '<label class="hdlv2-ac-review-label">Your transcript \u2014 review and edit if needed:</label>'
      + '<textarea class="hdlv2-ac-transcript-textarea">' + escHtml(transcript) + '</textarea>'
      + '<div class="hdlv2-ac-actions">'
      + '<button type="button" class="hdlv2-ac-btn primary" data-action="extract">' + primaryLabel + '</button>'
      + '<div class="hdlv2-ac-actions-secondary">'
      + '<button type="button" class="hdlv2-ac-btn secondary" data-action="continue-recording">Continue Recording</button>'
      + useAsIsBtnHtml
      + '<button type="button" class="hdlv2-ac-btn secondary" data-action="redo">Redo</button>'
      + '</div></div></div>';

    this.el.querySelector('[data-action="extract"]').addEventListener('click', function () {
      self.extractThemes();
    });
    this.el.querySelector('[data-action="continue-recording"]').addEventListener('click', function () {
      self.continueRecording();
    });
    // v0.31.3 — guard for the requireExtraction case where the button isn't rendered.
    var useAsIsBtn = this.el.querySelector('[data-action="use-asis"]');
    if (useAsIsBtn) {
      useAsIsBtn.addEventListener('click', function () {
        var ta = self.el.querySelector('.hdlv2-ac-transcript-textarea');
        var text = ta ? ta.value.trim() : self.rawTranscript;
        self.onConfirm(text);
      });
    }
    this.el.querySelector('[data-action="redo"]').addEventListener('click', function () {
      self.rawTranscript = '';
      self.render();
    });

    // Sync transcript to parent form immediately + on edits
    if (self.onChange) self.onChange(transcript);
    var reviewTa = this.el.querySelector('.hdlv2-ac-transcript-textarea');
    if (reviewTa && self.onChange) {
      reviewTa.addEventListener('input', function () {
        self.onChange(reviewTa.value);
      });
    }
  };

  // v0.47.2 — if a "Continue Recording" carry is pending and the new take
  // failed before delivering, restore the review screen with the carried prior
  // segment so it stays visible + recoverable instead of vanishing to a blank
  // input. Returns true if it handled the failure (caller skips its own error).
  AudioComponent.prototype._restoreCarryOnFail = function () {
    if (!this._carryText) return false;
    var carried = this._carryText;
    this._carryText = '';
    try { this.renderTranscriptReview(carried); } catch (e) { return false; }
    return true;
  };

  AudioComponent.prototype.continueRecording = function () {
    // Preserve whatever the user has in the textarea (may have been edited)
    var ta = this.el.querySelector('.hdlv2-ac-transcript-textarea');
    var existingText = ta ? ta.value.trim() : this.rawTranscript;

    // v0.47.2 — under the Deepgram-authoritative model the next take's blob
    // only contains the SECOND segment, so stash the prior reviewed text in
    // _carryText; the poll-completed delivery prepends it to the new
    // transcript (so "Continue Recording" appends instead of replacing).
    // transcriptParts is also seeded so the live preview shows the running text.
    this._carryText = existingText || '';
    this.transcriptParts = existingText ? [existingText] : [];

    // Restore input state and start recording
    this.render();
    var recordBtn = this.el.querySelector('[data-action="record"]');
    if (recordBtn) this.startRecording(recordBtn, true);
  };

  // ── Extract themes via Claude ──
  AudioComponent.prototype.extractThemes = function () {
    var self = this;
    var ta = this.el.querySelector('.hdlv2-ac-transcript-textarea');
    if (ta) this.rawTranscript = ta.value.trim();

    if (!this.rawTranscript) {
      this.showError('No text to extract themes from.');
      return;
    }

    this.showProcessing('Extracting themes with AI...');

    var payload = {
      text: this.rawTranscript,
      context_type: this.contextType,
    };
    if (this.previousSummary) payload.previous_summary = this.previousSummary;
    if (this.token) payload.token = this.token;

    fetch(this.apiBase + '/extract', {
      method: 'POST',
      headers: Object.assign(
        { 'Content-Type': 'application/json' },
        this.nonce ? { 'X-WP-Nonce': this.nonce } : {}
      ),
      body: JSON.stringify(payload),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success && data.summary) {
          self.currentSummary = data.summary;
          self.renderSummary(data.summary);
        } else {
          self.showError(data.message || 'AI extraction failed. Try again or use as-is.');
        }
      })
      .catch(function () {
        self.showError('Connection error during extraction. Try again or use as-is.');
      });
  };

  // ── Error display ──
  // After the standard error is displayed, append a small action button that
  // lets the user try the MediaRecorder→Deepgram path when Chrome's Web Speech
  // silent-fails. User-initiated — no mid-recording auto-switch (that caused
  // the double-fallback + DOM-wipe bugs in v0.15.5 through v0.15.12).
  AudioComponent.prototype.showErrorWithFallbackOption = function (msg) {
    var self = this;
    this.showError(msg);
    // iconsOnlyMode uses a status div, no room for a button — skip
    if (this.iconsOnlyMode) return;
    var errEl = this.el.querySelector('[data-role="error"]');
    if (!errEl) return;
    // Avoid duplicate buttons on repeat errors
    if (errEl.querySelector('.hdlv2-ac-fallback-btn')) return;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'hdlv2-ac-fallback-btn';
    btn.textContent = 'Try again'; // v0.47.26 (A6) — was 'Try local transcription'; the in-browser Whisper tier was removed (E4 v0.46.47), this just restarts capture with the Web Speech preview off (still server Deepgram)
    btn.style.cssText = 'display:inline-block;margin:8px 0 0;padding:6px 12px;background:#3d8da0;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:500;cursor:pointer;';
    btn.addEventListener('click', function () {
      try { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] user chose Try again (restart capture, preview off)'); } catch(e){}
      // Find the current mic button (may have been re-rendered since the
      // error was shown). startRecordingFallback re-initialises fully.
      var recBtn = self.el.querySelector('[data-action="record"]') || self.el.querySelector('.hdlv2-ac-record');
      if (!recBtn) {
        // Component is in a non-input state (e.g. showProcessing wiped it).
        // Re-render input first, then grab the fresh mic button.
        self.render();
        recBtn = self.el.querySelector('[data-action="record"]') || self.el.querySelector('.hdlv2-ac-record');
      }
      if (recBtn) self.startRecordingFallback(recBtn);
    });
    errEl.appendChild(btn);
  };

  AudioComponent.prototype.showError = function (msg) {
    if (this.iconsOnlyMode) {
      var s = this.el.querySelector('.hdlv2-ac-icons-status');
      if (s) { s.textContent = msg || ''; s.className = 'hdlv2-ac-icons-status error'; }
      return;
    }
    var errEl = this.el.querySelector('[data-role="error"]');
    var didRender = false;
    if (!errEl) {
      // We're in a non-input state — return to input first
      this.render();
      didRender = true;
      errEl = this.el.querySelector('[data-role="error"]');
    }
    // v0.46.21 (QA F6) — restore any WHY text the client typed before the async
    // waveform card replaced the DOM. showAsyncProcessing() stashes it in
    // _savedTextDuringAsync, but ONLY the success branch used to restore it, so
    // every failure branch (timeout / no-speech / transcription-failed / upload
    // catch) called showError() which re-rendered an EMPTY textarea and silently
    // wiped the client's typed answer. Centralised here so no failure branch can
    // forget it. Gated on didRender (we came from the waveform/non-input state)
    // + simpleMode (the Stage-2 WHY shared textarea) so it never clobbers live
    // input on an in-place validation error.
    if (didRender && this.simpleMode && typeof this._savedTextDuringAsync === 'string') {
      var savedWhy = this._savedTextDuringAsync;
      this._savedTextDuringAsync = null;
      var savedTa = this.el.querySelector('.hdlv2-ac-text');
      if (savedTa) {
        savedTa.value = savedWhy;
        if (typeof this.onChange === 'function') this.onChange(savedTa.value);
      }
    }
    if (errEl) {
      errEl.textContent = msg;
      errEl.style.display = 'block';
      setTimeout(function () { errEl.style.display = 'none'; }, 5000);
    }
    if (this.onError) this.onError(msg);
  };

  // ── Utility ──
  function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  }

  return { create: create };
})();
