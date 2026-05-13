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
  try { HDLV2_AC_DEBUG && HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] hdlv2-audio-component.js LOADED', { build: '0.27.1', hasSR: !!window.SpeechRecognition, hasWebkitSR: !!window.webkitSpeechRecognition, isSecureContext: window.isSecureContext, href: location.href }); } catch(e){}
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
      '.hdlv2-ac-text { width:100%; min-height:80px; padding:10px 12px; border:1px solid #e4e6ea; border-radius:8px; font-size:14px; font-family:inherit; resize:vertical; box-sizing:border-box; background:#f8f9fb; }',
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
      '.hdlv2-ac-transcript-textarea { width:100%; min-height:140px; padding:16px; margin-top:4px; border:1px solid #d1d5db; border-radius:10px; font-size:15px; font-family:inherit; resize:vertical; box-sizing:border-box; background:#f9fafb; line-height:1.6; }',
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
    // Consumer opt-in for preloading the Whisper model (~74MB) during idle.
    // Default OFF — clients on mobile data doing intake shouldn't pay this
    // cost if they'll never record. Practitioner-facing consumers
    // (consultation, timeline) opt IN because recording is their primary path.
    // A PHP admin filter ('hdlv2_whisper_preload_on_idle' → true/false) can
    // force this on or off everywhere; null = per-consumer value wins.
    this.preloadOnIdle = !!opts.preloadOnIdle;
    // Per-consumer Whisper tuning — null/undefined means "use CFG defaults".
    // Lets us A/B (e.g. practitioner consultation on whisper-large-v3-turbo,
    // client intake on whisper-small) without a PHP round-trip.
    this.whisperModel = opts.whisperModel || null;
    this.whisperNumBeams = Number.isFinite(opts.whisperNumBeams) ? opts.whisperNumBeams : null;

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

    // v0.36.4 — silence-stop watchdog. While Web Speech recognition is hot,
    // any sustained silence longer than `idleStopMs` will fire stopRecording()
    // automatically. Reset on every onresult event (interim or final), so
    // active speakers never trigger it. Only kicks in when the user has stopped
    // talking AND not clicked stop AND silence persists past threshold.
    //
    // Why this matters: Web Speech API auto-restarts recognition on its own
    // `onend` event so long as `isRecording === true` (line 543-552). With no
    // user click and no result events, the mic stays hot indefinitely (up to
    // 50 restarts). Practitioner reported on STBY 2026-05-08 that the browser
    // tab's red mic indicator stayed lit after he forgot to click stop.
    //
    // Default 12s — generous enough to absorb legitimate thinking pauses
    // mid-dictation. Consumers can override per surface (e.g. check-in 8s
    // for short answers, consultation 20s for clinical reflection time).
    // Set to 0 to disable entirely (back to legacy behaviour).
    this.idleStopMs = (typeof opts.idleStopMs === 'number') ? opts.idleStopMs : 12000;
    this.idleWatchdog = null;

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

    // Page-lifecycle cleanup. Without these, navigating away mid-recording
    // (Divi AJAX nav, modal close, tab close) leaves the mic indicator lit.
    var self = this;
    this._onPageHide = function () {
      if (self.isRecording || self.warmupStream || self.fallbackStream || self.recognition || self.mediaRecorder) {
        try { self.destroy(); } catch (e) {}
      }
    };
    try { window.addEventListener('pagehide', this._onPageHide); } catch (e) {}
    try { window.addEventListener('beforeunload', this._onPageHide); } catch (e) {}

    this.render();
    this.triggerPreload();
  }

  // Decide whether to preload the Whisper model now. Consults the transcriber's
  // master override (set from PHP) before falling back to the consumer's opt-in.
  // Polls briefly for HDLTranscriber because the ESM module loads with defer
  // semantics and may arrive slightly after the IIFE constructs.
  AudioComponent.prototype.triggerPreload = function () {
    var self = this;
    function attempt() {
      var T = (typeof window !== 'undefined') ? window.HDLTranscriber : null;
      if (!T || typeof T.preload !== 'function') return false;
      var master = T.masterOverride;
      var should = master === 'on'  ? true
                 : master === 'off' ? false
                 : self.preloadOnIdle;
      if (should) T.preload();
      return true;
    }
    if (attempt()) return;
    var tries = 0;
    var iv = setInterval(function () {
      if (attempt() || ++tries > 10) clearInterval(iv);
    }, 200);
  };

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

  AudioComponent.prototype.startRecording = function (btn, keepTranscript) {
    var self = this;
    try { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] startRecording called', { simpleMode: this.simpleMode, useAsIsOnly: this.useAsIsOnly, iconsOnlyMode: this.iconsOnlyMode, keepTranscript: !!keepTranscript }); } catch(e){}
    try {
      if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) {
        navigator.mediaDevices.enumerateDevices().then(function (devices) {
          var inputs = devices.filter(function (d) { return d.kind === 'audioinput'; });
          inputs.forEach(function (d, i) {
            HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] audioinput ' + i + ':', d.label || '(no label)', (d.deviceId || '').substring(0, 12) + '\u2026');
          });
        }).catch(function () {});
      }
    } catch(e){}

    // Diagnostic: AudioContext state — if 'suspended', Chrome is blocking
    // audio access despite permission being granted (no recent user gesture).
    try {
      var _acCtor = window.AudioContext || window.webkitAudioContext;
      if (_acCtor) {
        var _ac = new _acCtor();
        HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] AudioContext at startRecording', { state: _ac.state, sampleRate: _ac.sampleRate, baseLatency: _ac.baseLatency });
        _ac.close();
      } else {
        HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] AudioContext not available');
      }
    } catch (e) {
      HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] AudioContext check failed', { name: e && e.name, message: e && e.message });
    }

    // Diagnostic: fresh permission state check (may differ from module-load check)
    try {
      if (navigator.permissions && navigator.permissions.query) {
        navigator.permissions.query({ name: 'microphone' }).then(function (p) {
          HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] mic permission at startRecording', p.state);
        }).catch(function () {});
      }
    } catch(e){}

    // Diagnostic: user activation state (needed for Chrome's audio policy)
    try {
      if (navigator.userActivation) {
        HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] userActivation at startRecording', { hasBeenActive: navigator.userActivation.hasBeenActive, isActive: navigator.userActivation.isActive });
      }
    } catch(e){}

    // Diagnostic: are we holding any active streams from a previous attempt?
    try {
      HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] active-stream check at startRecording', {
        hasWarmupStream: !!self.warmupStream,
        hasMediaStream: !!self.mediaStream,
        hasMediaRecorder: !!self.mediaRecorder,
        mediaRecorderState: self.mediaRecorder && self.mediaRecorder.state,
        hasRecognition: !!self.recognition,
        isRecording: !!self.isRecording,
        hasRecognitionWatchdog: !!self.recognitionWatchdog
      });
    } catch(e){}

    // Microphone requires HTTPS or localhost — check early with a clear message
    if (window.isSecureContext === false) {
      this.showError('Microphone requires HTTPS or localhost. Try http://localhost:10008 for local testing, or upload a pre-recorded file.');
      return;
    }

    var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    // v0.20.13 — Brave exposes `navigator.brave` as an object with an
    // `isBrave()` method. Brave routinely fires SR `onerror` for privacy
    // reasons, which forces a fallback to MediaRecorder — the handoff
    // drops the first ~500ms-1.5s of audio (the "Keep up the..." cutoff
    // reported 2026-04-23). Detect Brave up front and skip straight to
    // MediaRecorder so recording starts on the first word.
    var isBrave = !!(navigator.brave && typeof navigator.brave.isBrave === 'function');
    try { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] SR detection', { picked: SpeechRecognition ? SpeechRecognition.name : 'NONE', isWebkit: SpeechRecognition === window.webkitSpeechRecognition, isBrave: isBrave }); } catch(e){}

    if (!SpeechRecognition || isBrave) {
      this.startRecordingFallback(btn);
      return;
    }

    // Wrap the recognition setup so we can run it AFTER a warmup getUserMedia
    // call has populated Chrome's audio pipeline. Invoked below via .call(self)
    // so `this` inside it stays the AudioComponent instance — existing code
    // that uses `this.recognition`, `this.transcriptParts`, etc. works unchanged.
    var _runRecognitionSetup = function () {
    this.recognition = new SpeechRecognition();
    try { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] SR instantiated', { ctorName: this.recognition.constructor && this.recognition.constructor.name }); } catch(e){}
    this.recognition.continuous = true;
    this.recognition.interimResults = true;
    this.recognition.lang = 'en-AU';

    // Only reset transcript if not continuing from a previous recording
    if (!keepTranscript) this.transcriptParts = [];
    this.interimTranscript = '';
    this.recognitionRestarts = 0;

    this.recognition.onresult = function (event) {
      try {
        var _dbg = [];
        for (var _k = event.resultIndex; _k < event.results.length; _k++) {
          var _r = event.results[_k];
          var _a = _r && _r[0];
          _dbg.push({ i: _k, isFinal: !!(_r && _r.isFinal), transcript: _a && _a.transcript, transcriptLen: _a && _a.transcript ? _a.transcript.length : 0, confidence: _a && _a.confidence });
        }
        HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] onresult FIRED', { resultsLen: event.results.length, resultIndex: event.resultIndex, newResults: _dbg });
      } catch(e){}
      // Any result means Web Speech API is alive — kill the silent-failure watchdog.
      if (self.recognitionWatchdog) {
        clearTimeout(self.recognitionWatchdog);
        self.recognitionWatchdog = null;
      }
      // v0.36.4 — push the silence-stop watchdog forward by `idleStopMs`.
      // Active speakers (interim or final results) reset perpetually; only
      // sustained silence reaches the timer's payload.
      self._resetIdleStop(true);
      var final = '';
      var interim = '';
      for (var i = event.resultIndex; i < event.results.length; i++) {
        var transcript = event.results[i][0].transcript;
        if (event.results[i].isFinal) {
          final += transcript;
        } else {
          interim = transcript;
        }
      }
      try {
        HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] onresult processing', {
          finalLen: final.length,
          finalText: final,
          interimLen: interim.length,
          interimText: interim,
          willPush: !!final,
          isRecording: self.isRecording,
          beforePush_transcriptPartsCount: self.transcriptParts.length
        });
      } catch(e){}
      if (final) self.transcriptParts.push(final);
      self.interimTranscript = interim;
      self.updateLiveTranscript();
      try {
        HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] onresult post-push', {
          transcriptPartsCount: self.transcriptParts.length,
          lastPart: self.transcriptParts.length ? self.transcriptParts[self.transcriptParts.length - 1] : null,
          interimTranscript: self.interimTranscript
        });
      } catch(e){}
    };

    this.recognition.onerror = function (event) {
      try { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] onerror FIRED', { error: event.error, message: event.message, transcriptParts: self.transcriptParts.length, interim: !!self.interimTranscript }); } catch(e){}
      if (event.error === 'no-speech') {
        // Silence pause — not fatal, recognition will restart via onend
        return;
      }
      if (event.error === 'aborted') {
        // Self-inflicted (recognition.abort() called by us) — not a real
        // error and must NOT cascade into another fallback. This was the
        // bug that caused v0.15.12 to double-trigger the Whisper path.
        return;
      }

      // Error path is taking over — kill the silent-failure watchdog.
      if (self.recognitionWatchdog) {
        clearTimeout(self.recognitionWatchdog);
        self.recognitionWatchdog = null;
      }

      // If Web Speech API fails before producing any results,
      // fall back to MediaRecorder + server-side Whisper transcription.
      // Handles Brave (blocks Google speech service), privacy browsers, network issues.
      if (self.transcriptParts.length === 0 && !self.interimTranscript) {
        self.isRecording = false;
        try { self.recognition.abort(); } catch (e) {}
        self.recognition = null;
        self.removeLiveTranscript();
        self.resetRecordBtn(btn);
        // Stop the warmup stream so the fallback can re-acquire cleanly.
        if (self.warmupStream) {
          try { self.warmupStream.getTracks().forEach(function (t) { t.stop(); }); } catch(e){}
          self.warmupStream = null;
        }
        // 500ms release delay — without it, the racing getUserMedia can
        // return NotFoundError because Chrome hasn't finished releasing
        // the audio device from the aborted SpeechRecognition instance.
        setTimeout(function () { self.startRecordingFallback(btn); }, 500);
        return;
      }

      // Mid-recording error after results were already received
      if (event.error === 'not-allowed') {
        self.isRecording = false;
        self.removeLiveTranscript();
        self.resetRecordBtn(btn);
        self.showError('Microphone access denied. Please allow microphone access and try again.');
      } else {
        // Speech recognition error — non-fatal, handled by fallback
      }
    };

    // Capture this SR instance so a late onend fired after we've moved on
    // (stopped + nulled, or transitioned to fallback) doesn't reach in and
    // restart a replaced instance. This is the mic-leak guard for LEAK 3.
    var boundRecognition = this.recognition;
    this.recognition.onend = function () {
      try { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] onend FIRED', { isRecording: self.isRecording, stopping: !!self.stopping, sameInstance: self.recognition === boundRecognition, restarts: self.recognitionRestarts, transcriptParts: self.transcriptParts.length, interim: !!self.interimTranscript }); } catch(e){}
      // Web Speech API stops on silence; restart if user hasn't clicked stop.
      // Guards: stopping flag (user asked to stop, in grace period) and
      // instance-match (another startRecording has replaced self.recognition).
      if (self.isRecording && !self.stopping && self.recognition === boundRecognition && self.recognitionRestarts < 50) {
        self.recognitionRestarts++;
        try { self.recognition.start(); } catch (e) { /* already started */ }
      }
    };

    this.recognition.onstart = function () { try { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] onstart FIRED'); } catch(e){} };

    // In simpleMode, capture existing textarea content before recording
    if (this.simpleMode) {
      var ta = this.el.querySelector('.hdlv2-ac-text');
      this.textBeforeRecording = ta ? ta.value.trim() : '';
    }

    try { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] calling recognition.start()'); } catch(e){}
    this.recognition.start();
    this.isRecording = true;

    // v0.36.4 — arm the silence-stop watchdog. No-op if idleStopMs <= 0 or
    // if isRecording somehow ended up false. Resets on every onresult event,
    // so it only fires when the user has stopped talking AND not clicked stop
    // for `idleStopMs` (default 12s).
    this._resetIdleStop(true);

    // Watchdog removed in v0.15.13 — its abort-and-fallback cascade was the
    // root cause of the double-fallback + orphaned-btn DOM-wipe bugs. If
    // Chrome's Web Speech API silent-fails, the user sees the 'We didn't
    // catch that' error on stop, with an explicit 'Try local transcription'
    // action they can choose — no mid-recording surprises.

    // UI: show recording state
    btn.classList.add('recording');
    btn.innerHTML = '<span class="hdlv2-ac-dot"></span>';
    btn.title = 'Stop recording';

    if (!this.simpleMode) {
      // Insert live transcript display below the textarea
      var wrapper = this.el.querySelector('.hdlv2-ac');
      if (wrapper && !wrapper.querySelector('.hdlv2-ac-live-transcript')) {
        var div = document.createElement('div');
        div.className = 'hdlv2-ac-live-transcript';
        div.textContent = 'Listening...';
        var textareaContainer = wrapper.firstElementChild;
        if (textareaContainer && textareaContainer.nextSibling) {
          wrapper.insertBefore(div, textareaContainer.nextSibling);
        } else {
          wrapper.appendChild(div);
        }
      }
    }
    };

    // Warmup getUserMedia — some Chrome builds silently fail Web Speech
    // audio capture when no concurrent MediaStream exists on the origin.
    // Acquiring a short-lived stream here primes the audio subsystem so
    // recognition.start() actually receives audio. The stream is held on
    // self.warmupStream and stopped in stopRecording / onerror-fallback /
    // watchdog-fallback, so it never outlives the recognition session.
    navigator.mediaDevices.getUserMedia({
      audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true }
    }).then(function (stream) {
      self.warmupStream = stream;
      try {
        var _track = stream.getAudioTracks()[0];
        HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] warmup stream acquired', { label: _track && _track.label, settings: _track && _track.getSettings && _track.getSettings() });
      } catch(e){}
      _runRecognitionSetup.call(self);
    }).catch(function (err) {
      try { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] warmup FAILED, going straight to fallback', { name: err && err.name, message: err && err.message }); } catch(e){}
      self.startRecordingFallback(btn);
    });
  };

  // Fallback for browsers without Web Speech API (Firefox, etc.)
  AudioComponent.prototype.startRecordingFallback = function (btn) {
    var self = this;
    try { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] FALLBACK triggered (MediaRecorder+Whisper path)'); } catch(e){}
    // v0.36.4 — clear any silence-stop watchdog that the Web Speech path
    // armed before falling through here. MediaRecorder doesn't fire onresult
    // events so the watchdog cannot reset itself in this path; if we leave
    // it armed it would auto-stop a legitimate Brave/Firefox recording
    // after `idleStopMs` even with the user actively speaking. The Web
    // Speech path is the protected one; MediaRecorder users keep the legacy
    // behaviour (user must click stop, with the pagehide/beforeunload
    // backstop catching navigation cases).
    this._resetIdleStop(false);
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      this.showError('Your browser does not support audio recording. Please use Chrome, Edge, or Safari, or upload a file.');
      return;
    }

    // Smart mic selection — when multiple audioinput devices exist, prefer
    // a real microphone over virtual/loopback devices that provide silence.
    // Labels are visible only after at least one prior getUserMedia grant;
    // if labels are empty (first run) we skip selection and let the browser
    // pick the system default.
    var _pickMic = function () {
      try {
        if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return Promise.resolve(null);
        return navigator.mediaDevices.enumerateDevices().then(function (devices) {
          var mics = devices.filter(function (d) { return d.kind === 'audioinput'; });
          if (mics.length <= 1) return null;
          var real = null;
          for (var i = 0; i < mics.length; i++) {
            var label = (mics[i].label || '').toLowerCase();
            if (!label) continue;
            if (label.indexOf('virtual') !== -1) continue;
            if (label.indexOf('stereo mix') !== -1) continue;
            if (label.indexOf('cable') !== -1) continue;
            if (label.indexOf('loopback') !== -1) continue;
            real = mics[i];
            break;
          }
          return real;
        }).catch(function () { return null; });
      } catch (e) { return Promise.resolve(null); }
    };

    _pickMic().then(function (realMic) {
      var audioOpts = { echoCancellation: true, noiseSuppression: true, autoGainControl: true };
      if (realMic && realMic.deviceId) {
        audioOpts.deviceId = { exact: realMic.deviceId };
        try { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] fallback picked specific mic', { label: realMic.label, deviceId: realMic.deviceId.substring(0, 12) + '\u2026' }); } catch(e){}
      } else {
        try { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] fallback using system default mic'); } catch(e){}
      }
      // Intentionally do NOT constrain sampleRate / channelCount — some
      // mic+driver combos reject exact values and return NotFoundError
      // or OverconstrainedError. Let the browser pick whatever the mic
      // natively supports; Whisper handles any sample rate fine.
      return navigator.mediaDevices.getUserMedia({ audio: audioOpts });
    })
      .then(function (stream) {
        try { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] getUserMedia SUCCESS', { tracks: stream.getAudioTracks().length, label: (stream.getAudioTracks()[0]||{}).label }); } catch(e){}
        // Promote to instance field so stopRecording() / destroy() can
        // force-release tracks even if MediaRecorder 'stop' never fires.
        self.fallbackStream = stream;
        self.audioChunks = [];

        // Cross-browser mimeType picker — Brave/Firefox/Safari don't all
        // default to the same format. Probe in preference order and fall
        // back to browser default. Record the chosen type so the final
        // Blob matches what MediaRecorder actually produced.
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
        // 128 kbps lifts transcription quality on phone/laptop mics without
        // bloating the upload size.
        var mrOpts = chosenType ? { mimeType: chosenType, audioBitsPerSecond: 128000 } : { audioBitsPerSecond: 128000 };
        try {
          self.mediaRecorder = new MediaRecorder(stream, mrOpts);
        } catch (e) {
          try {
            self.mediaRecorder = chosenType
              ? new MediaRecorder(stream, { mimeType: chosenType })
              : new MediaRecorder(stream);
          } catch (e2) {
            self.mediaRecorder = new MediaRecorder(stream);
          }
        }

        self.mediaRecorder.addEventListener('dataavailable', function (e) {
          if (e.data.size > 0) self.audioChunks.push(e.data);
        });

        self.mediaRecorder.addEventListener('stop', function () {
          // Prefer self.fallbackStream (instance field) so destroy() and
          // stopRecording() share the same cleanup path. Fall back to the
          // closure `stream` in case the field was cleared by an earlier call.
          var releaseStream = self.fallbackStream || stream;
          try { releaseStream.getTracks().forEach(function (t) { try { t.stop(); } catch(e){} }); } catch(e){}
          self.fallbackStream = null;
          // Use the ACTUAL recorded MIME type — not a hardcoded label.
          // Fixes Brave / Firefox / Safari where the default isn't webm.
          var actualType = (self.mediaRecorder && self.mediaRecorder.mimeType)
            ? self.mediaRecorder.mimeType
            : (chosenType || 'audio/webm');
          var blob = new Blob(self.audioChunks, { type: actualType });
          if (!blob.size) {
            self.showError('Recording came back empty. Try again — speak clearly and wait a moment before stopping.');
            return;
          }
          // v0.36.10 — minimum-duration + minimum-size pre-flight guard.
          // An accidental tap (start → stop in <1s) produces a tiny header-only
          // blob (~3-4 KB of webm/mp4 framing, no actual speech). Without this
          // guard, the blob sails past blob.size > 0, gets uploaded to Deepgram,
          // returns empty_transcript, the job-queue retries 4x with 30s/2m/8m
          // backoff, and the user is stuck on "Retrying transcription." for
          // ~10 minutes before the permanent fail surfaces. Block these
          // client-side so the user sees an immediate, actionable message.
          // 8 KB / 1 sec are conservative thresholds — real speech of 1+ sec
          // at the 128 kbps we record is ~16 KB.
          var MIN_AUDIO_BYTES   = 8 * 1024;
          var MIN_AUDIO_SECONDS = 1;
          if (self.recordingSeconds < MIN_AUDIO_SECONDS || blob.size < MIN_AUDIO_BYTES) {
            self.showError('Recording too short. Tap the mic, speak for a moment, then tap again to stop.');
            return;
          }
          // v0.19.2 — recordings honour asyncUpload same as file picker uploads.
          // Previously recordings always ran through the in-browser Whisper
          // pipeline (~75 MB model download in the client). Consumers that set
          // asyncUpload:true (WHY / check-in / consultation) now route audio
          // to server-side Deepgram via /audio/transcribe-async.
          if (self.asyncUpload) {
            // Wrap the Blob as a File so uploadFileAsync's FormData has a filename.
            // v0.27.0 — fix #8 (/ultrareview): Safari + iOS record audio/mp4
            // when audio/webm isn't supported (line 647-648). Without the mp4
            // branch, the file was uploaded as recording.webm with mp4
            // contents → server's finfo_file mime check rejected → silent
            // transcription failure on Safari.
            var ext = /mpeg|mp3/.test(actualType) ? 'mp3' :
                      /mp4|m4a|aac/.test(actualType) ? 'm4a' :
                      /ogg/.test(actualType) ? 'ogg' :
                      'webm';
            var file = new File([blob], 'recording.' + ext, { type: actualType });
            self.uploadFileAsync(file);
          } else {
            self.processAudioBlob(blob);
          }
        });

        self.mediaRecorder.start();
        self.isRecording = true;
        self.recordingSeconds = 0;

        btn.classList.add('recording');
        btn.innerHTML = '<span class="hdlv2-ac-dot"></span>';
        btn.title = 'Stop recording';

        self.recordingTimer = setInterval(function () {
          self.recordingSeconds++;
        }, 1000);
      })
      .catch(function (err) {
        try { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] getUserMedia FAILED', { name: err && err.name, message: err && err.message }); } catch(e){}
        var _name = err && err.name;
        if (_name === 'NotFoundError') {
          self.showError('No microphone detected. Check your system audio settings and reload the page.');
        } else if (_name === 'NotAllowedError') {
          self.showError('Microphone permission denied. Click the lock icon in the address bar to grant access.');
        } else if (_name === 'NotReadableError') {
          self.showError('Microphone is busy. Close other apps using the mic (Zoom, Teams, etc.) and try again.');
        } else {
          self.showError('Microphone error: ' + (_name || 'unknown') + '. Try again or type your answer.');
        }
      });
  };

  AudioComponent.prototype.stopRecording = function (btn) {
    var self = this;
    try { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] stopRecording called', { hasRecognition: !!this.recognition, hasMediaRecorder: !!this.mediaRecorder, transcriptParts: this.transcriptParts && this.transcriptParts.length, interim: !!this.interimTranscript, hasWarmupStream: !!this.warmupStream, hasFallbackStream: !!this.fallbackStream }); } catch(e){}
    this.isRecording = false;
    this.stopping = true; // Blocks onend auto-restart during grace period
    // v0.36.4 — silence-stop watchdog must be cleared before any onend handler
    // can re-enter (otherwise a late tick could call stopRecording a second
    // time during the 600ms grace window). _resetIdleStop(false) clears the
    // timer without re-arming.
    this._resetIdleStop(false);

    // Release the warmup stream started in startRecording. Done first so
    // the mic is fully released before any downstream cleanup touches it.
    if (this.warmupStream) {
      try { this.warmupStream.getTracks().forEach(function (t) { t.stop(); }); } catch(e){}
      this.warmupStream = null;
    }

    // User stopped within the watchdog window — cancel it so it doesn't
    // fire into an already-torn-down recognition instance.
    if (this.recognitionWatchdog) {
      clearTimeout(this.recognitionWatchdog);
      this.recognitionWatchdog = null;
    }

    // Web Speech API path
    if (this.recognition) {
      // v0.30.0 — capture the SR instance the grace-timer is responsible
      // for. Previously the timer read `self.recognition` 600ms later, so
      // if the user re-clicked record within the grace window, the timer
      // would abort the BRAND-NEW recognition + null self.recognition out
      // from under it — silently breaking the second recording and forcing
      // it onto the slow MediaRecorder→Deepgram fallback.
      var graceTarget = this.recognition;
      this.recognition.stop();
      // Visual feedback immediately — don't wait for grace period
      this.removeLiveTranscript();
      this.resetRecordBtn(btn);

      // Grace period: let the API fire final onresult events after stop()
      setTimeout(function () {
        // v0.20.1 — belt-and-braces abort() after graceful stop. Chrome's
        // SpeechRecognition.stop() is known to leave the tab-level mic
        // indicator lit; abort() forces the API to fully release. Safe
        // because stop() has already fired and any pending onresult events
        // have been delivered during this 600ms window. The onerror handler
        // ignores 'aborted' events, so this doesn't cascade.
        try { if (graceTarget) graceTarget.abort(); } catch (e) {}

        // v0.30.0 — if a new recording started during the grace window,
        // self.recognition now points at THAT new instance. Bail before
        // touching shared state: the new recording owns self.recognition,
        // self.stopping, transcriptParts, and will deliver its own
        // transcript via onConfirm when its own stopRecording fires.
        if (self.recognition !== graceTarget) {
          try { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] grace-timer skipping cleanup — new recording in progress'); } catch(e){}
          return;
        }

        self.recognition = null;
        self.stopping = false;
        var fullTranscript = self.transcriptParts.join(' ').trim();
        try { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] stopRecording grace-period complete', { transcriptPartsCount: self.transcriptParts.length, fullTranscriptLen: fullTranscript.length, interimLen: (self.interimTranscript||'').length }); } catch(e){}

        // Fall back to interim results if no finals arrived yet
        if (!fullTranscript && self.interimTranscript) {
          fullTranscript = self.interimTranscript.trim();
        }

        if (self.simpleMode) {
          // Finalize in the existing textarea — no state change
          var ta = self.el.querySelector('.hdlv2-ac-text');
          if (ta && fullTranscript) {
            var base = self.textBeforeRecording;
            ta.value = base + (base ? ' ' : '') + fullTranscript;
            if (self.onChange) self.onChange(ta.value);
          } else if (!fullTranscript) {
            self.showErrorWithFallbackOption('We didn\'t catch that. Try again, or type directly in the box above.');
          }
        } else if (self.useAsIsOnly && fullTranscript) {
          // Consultation-style UX — push raw transcript straight to consumer
          // without the Extract-Themes review screen.
          self.render();
          self.onConfirm(fullTranscript);
        } else if (fullTranscript) {
          self.renderTranscriptReview(fullTranscript);
        } else {
          self.showErrorWithFallbackOption('We didn\'t catch that. Try again, or type directly in the box above.');
        }
      }, 600);
      return;
    }

    // MediaRecorder fallback path
    if (this.recordingTimer) {
      clearInterval(this.recordingTimer);
      this.recordingTimer = null;
    }
    if (this.mediaRecorder) {
      // v0.20.1 — always attempt stop(); its 'stop' event releases tracks.
      // Previously gated on state==='recording', so a mediaRecorder in
      // 'inactive' / 'paused' state would skip cleanup → mic stayed warm.
      try { this.mediaRecorder.stop(); } catch (e) {}
    }
    // Belt-and-braces: force-release fallback stream regardless of whether
    // the mediaRecorder 'stop' event fired. Safe to call repeatedly; tracks
    // that are already stopped are no-ops.
    if (this.fallbackStream) {
      try { this.fallbackStream.getTracks().forEach(function (t) { try { t.stop(); } catch(e){} }); } catch(e){}
      this.fallbackStream = null;
    }
    this.resetRecordBtn(btn);
  };

  AudioComponent.prototype.resetRecordBtn = function (btn) {
    btn.classList.remove('recording');
    btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/></svg>';
    btn.title = 'Record audio';
  };

  // v0.36.4 — silence-stop watchdog. Pure helper around setTimeout/clearTimeout
  // so the arm/reset/clear pattern stays in one place.
  //
  // Behaviour:
  //   - When called with `arm=true` and idleStopMs > 0 and isRecording is true,
  //     sets (or replaces) a single timer. When it fires, stopRecording(btn)
  //     runs — same code path the user's stop click uses, so all the existing
  //     graceful-stop semantics (grace period, abort backstop, transcript
  //     finalisation, fallbackStream cleanup) apply.
  //   - When called with `arm=false`, just clears the existing timer. Safe to
  //     call repeatedly (idempotent — clearTimeout on null is a no-op).
  //
  // The timer is reset on every `onresult` event (interim AND final), so an
  // active speaker keeps it perpetually pushed forward. The only path that
  // reaches the timer's payload is sustained silence past `idleStopMs`.
  AudioComponent.prototype._resetIdleStop = function (arm) {
    if (this.idleWatchdog) {
      try { clearTimeout(this.idleWatchdog); } catch (e) {}
      this.idleWatchdog = null;
    }
    if (!arm || !this.isRecording || !this.idleStopMs || this.idleStopMs <= 0) return;
    var self = this;
    this.idleWatchdog = setTimeout(function () {
      self.idleWatchdog = null;
      // Re-check isRecording at fire time; user may have just clicked stop in
      // the millisecond before this tick. Prevents a double-stopRecording call
      // (which is idempotent anyway, but logs noise we don't need).
      if (!self.isRecording) return;
      try { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] idle watchdog firing — stopping recording after ' + self.idleStopMs + 'ms silence'); } catch (e) {}
      var btn = self.el.querySelector('[data-action="record"]') || self.el.querySelector('.hdlv2-ac-record');
      if (btn) {
        try { self.stopRecording(btn); } catch (e) {}
      }
    }, this.idleStopMs);
  };

  // v0.20.1 — hard teardown. Call from consumer code when the component's
  // container is being removed, or when the flow moves past a recording step
  // and you want to guarantee the mic indicator goes dark. Idempotent; safe
  // to call repeatedly. Page-lifecycle events (pagehide/beforeunload) call
  // this automatically via the listener wired in the constructor.
  AudioComponent.prototype.destroy = function () {
    try { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] destroy called', { isRecording: !!this.isRecording, hasWarmup: !!this.warmupStream, hasFallback: !!this.fallbackStream, hasRecognition: !!this.recognition, hasRecorder: !!this.mediaRecorder }); } catch(e){}
    this.isRecording = false;
    this.stopping = true;

    if (this.recordingTimer) {
      try { clearInterval(this.recordingTimer); } catch (e) {}
      this.recordingTimer = null;
    }
    if (this.recognitionWatchdog) {
      try { clearTimeout(this.recognitionWatchdog); } catch (e) {}
      this.recognitionWatchdog = null;
    }
    // v0.36.4 — clear the silence-stop watchdog. _resetIdleStop(false) handles
    // the null-check internally; safe to call even if the timer never armed.
    this._resetIdleStop(false);

    if (this.warmupStream) {
      try { this.warmupStream.getTracks().forEach(function (t) { try { t.stop(); } catch(e){} }); } catch (e) {}
      this.warmupStream = null;
    }
    if (this.fallbackStream) {
      try { this.fallbackStream.getTracks().forEach(function (t) { try { t.stop(); } catch(e){} }); } catch (e) {}
      this.fallbackStream = null;
    }

    if (this.recognition) {
      // abort() is stronger than stop() — the canonical way to force Chrome
      // to drop the SR-internal mic immediately. Order matters: abort first,
      // then stop as safety-net for implementations that prefer graceful path.
      try { this.recognition.abort(); } catch (e) {}
      try { this.recognition.stop(); } catch (e) {}
      this.recognition = null;
    }

    if (this.mediaRecorder) {
      try { this.mediaRecorder.stop(); } catch (e) {}
      this.mediaRecorder = null;
    }

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
    // Route to server-side Deepgram if the consumer opted in.
    // Essential for long files (browser Whisper times out after 120s;
    // a 1-hour consultation needs server-side transcription).
    // referenceId is optional for client contexts (why_collection /
    // weekly_checkin) — the transcript comes back via onConfirm and the
    // form's own submit flow handles persistence.
    if (this.asyncUpload) {
      this.uploadFileAsync(file);
      return;
    }
    this.processAudioBlob(file);
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

    var fd = new FormData();
    fd.append('audio', file);
    fd.append('context_type', this.contextType);
    fd.append('reference_id', String(this.referenceId || 0));
    if (this.token) fd.append('token', this.token);

    // apiBase for the async endpoint — audio component's apiBase is
    // .../hdl-v2/v1/audio; transcribe-async lives at .../hdl-v2/v1/audio/transcribe-async.
    var base = this.apiBase.replace(/\/$/, '');
    var uploadUrl = base + '/transcribe-async';

    fetch(uploadUrl, {
      method: 'POST',
      headers: this.nonce ? { 'X-WP-Nonce': this.nonce } : {},
      body: fd,
    })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
      .then(function (res) {
        if (res.status !== 200 || !res.body || !res.body.job_id) {
          var msg = (res.body && (res.body.message || res.body.code)) || 'Upload failed';
          self.showError('Upload failed: ' + msg);
          return;
        }
        self.pollTranscriptionJob(res.body.job_id, MAX_POLL_MS, Date.now());
      })
      .catch(function (err) {
        self.showError('Upload failed. Please try again.');
      });
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
            self.showError('Could not check transcription status.');
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
            var transcript = job.result && job.result.transcript ? String(job.result.transcript) : '';
            if (!transcript) {
              self.showError('No speech detected. Try again or type your notes.');
              return;
            }
            // Deliver transcript to the consumer — same 3-way branch as
            // processAudioBlob (the browser-Whisper path) so behaviour is
            // identical regardless of which pipeline handled it.
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
              // Consultation — raw transcript into the practitioner's
              // own textarea via onConfirm.
              self.render();
              if (typeof self.onConfirm === 'function') self.onConfirm(transcript);
            } else {
              // Weekly check-in — show the transcript-review screen so the
              // client can Extract Themes (Claude) or Continue Recording.
              self.renderTranscriptReview(transcript);
            }
            return;
          }
          // failed / cancelled
          self.showError(job.error ? 'Transcription failed: ' + job.error : 'Transcription failed.');
        })
        .catch(function () {
          // Transient network error — keep polling until maxMs.
          // Use the adaptive interval so we don't pile on a flaky network.
          setTimeout(tick, getNextPollInterval(pollAttempts));
        });
    };
    tick();
  };

  // Single unified path: all uploaded blobs + MediaRecorder fallback output
  // go through the in-browser Whisper pipeline (hdlv2-transcriber). No
  // server call, no API key. Raw errors from the transcriber are caught here
  // and replaced with a sanitised user-facing message — the transcriber
  // module itself reports the raw error text to /audio/client-error for
  // telemetry, so we never surface provider-level details (e.g. OpenAI
  // "Incorrect API key" / sk-proj URLs) to the DOM.
  AudioComponent.prototype.processAudioBlob = function (blob) {
    var self = this;
    var T = (typeof window !== 'undefined') ? window.HDLTranscriber : null;

    if (!T || typeof T.transcribeBlob !== 'function') {
      self.showError('Transcription failed, please try again or type directly.');
      return;
    }

    // First-use on this tab triggers the ~75MB model fetch. Show a distinct
    // label so a 30–60s cold-start doesn't look like a stalled transcription.
    this.showProcessing(T.isReady()
      ? 'Transcribing your recording...'
      : 'Loading transcription model (first time only, ~75 MB)...');

    var transcribeOpts = {
      language: 'english',
      onProgress: function (m) {
        if (m.stage === 'model' && !T.isReady()) {
          var pct = (m.data && typeof m.data.progress === 'number')
            ? Math.round(m.data.progress) : null;
          self.showProcessing(pct !== null
            ? 'Loading transcription model (' + pct + '%, one-time)...'
            : 'Loading transcription model (first time only, ~75 MB)...');
        } else if (m.stage === 'inference') {
          self.showProcessing('Transcribing your recording...');
        }
      },
    };
    if (this.whisperModel) transcribeOpts.model = this.whisperModel;
    if (this.whisperNumBeams !== null) transcribeOpts.numBeams = this.whisperNumBeams;

    // Race the transcribe promise against a hard timeout. Without this,
    // a stuck WebGPU init or stalled model fetch (seen on Brave's
    // privacy-default config) leaves the user on an infinite loading
    // spinner because the promise never resolves OR rejects.
    var TRANSCRIBE_TIMEOUT_MS = 120000; // 120s covers cold-start model download
    var timedOut = false;
    var timeoutId;
    var timeoutPromise = new Promise(function (_resolve, reject) {
      timeoutId = setTimeout(function () {
        timedOut = true;
        reject(new Error('transcribe_timeout'));
      }, TRANSCRIBE_TIMEOUT_MS);
    });

    Promise.race([ T.transcribeBlob(blob, transcribeOpts), timeoutPromise ])
      .then(function (transcript) {
        clearTimeout(timeoutId);
        transcript = (transcript || '').trim();
        if (!transcript) {
          self.showError('No speech detected in the recording. Try again or type your response.');
          return;
        }
        if (self.simpleMode) {
          // Capture existing text BEFORE render() recreates the DOM
          var prevTa = self.el.querySelector('.hdlv2-ac-text');
          var savedText = prevTa ? prevTa.value.trim() : '';
          self.render();
          var ta = self.el.querySelector('.hdlv2-ac-text');
          if (ta) {
            ta.value = savedText + (savedText ? '\n\n' : '') + transcript;
            if (self.onChange) self.onChange(ta.value);
          }
        } else if (self.useAsIsOnly) {
          // Consumer (consultation UI) wants the raw transcript pushed
          // straight back — no Extract-Themes review screen.
          self.render();
          self.onConfirm(transcript);
        } else {
          self.renderTranscriptReview(transcript);
        }
      })
      .catch(function (err) {
        clearTimeout(timeoutId);
        // User-initiated abort (stop()) is not an error.
        if (err && err.message === 'aborted') return;
        if (timedOut || (err && err.message === 'transcribe_timeout')) {
          self.showError('Transcription is taking unusually long. Please try again, or type your notes directly.');
          return;
        }
        // Never surface err.message — it may contain model paths, stack
        // traces, or provider detail. Telemetry already logged by the
        // transcriber module via /audio/client-error.
        self.showError('Transcription failed, please try again or type directly.');
      });
  };

  // ── Submit Text ──
  AudioComponent.prototype.submitText = function () {
    var textarea = this.el.querySelector('.hdlv2-ac-text');
    var text = textarea ? textarea.value.trim() : '';
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

  AudioComponent.prototype.continueRecording = function () {
    // Preserve whatever the user has in the textarea (may have been edited)
    var ta = this.el.querySelector('.hdlv2-ac-transcript-textarea');
    var existingText = ta ? ta.value.trim() : this.rawTranscript;

    // Seed transcriptParts with existing text so new recording appends
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
  // lets the user try the in-browser Whisper path when Chrome's Web Speech
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
    btn.textContent = 'Try local transcription';
    btn.style.cssText = 'display:inline-block;margin:8px 0 0;padding:6px 12px;background:#3d8da0;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:500;cursor:pointer;';
    btn.addEventListener('click', function () {
      try { HDLV2_AC_DEBUG && console.log('[HDL-DEBUG] user chose Try local transcription'); } catch(e){}
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
    if (!errEl) {
      // We're in a non-input state — return to input first
      this.render();
      errEl = this.el.querySelector('[data-role="error"]');
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
