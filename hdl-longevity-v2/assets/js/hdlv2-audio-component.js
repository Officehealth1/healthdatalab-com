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
    this.onError = opts.onError || function () {};
    this.showConsent = !!opts.showConsent;

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

    this.render();
  }

  // ── State: input ──
  AudioComponent.prototype.render = function () {
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
      + '<div class="hdlv2-ac-row" style="display:none;" data-role="action-row">'
      + '<button type="button" class="hdlv2-ac-btn primary" data-action="submit">Process with AI</button>'
      + '</div>'
      + (this.showConsent ? '<div class="hdlv2-ac-consent">This session may be recorded for your health records. The recording is processed and stored securely.</div>' : '')
      + '<div class="hdlv2-ac-error" style="display:none;" data-role="error"></div>'
      + '</div>';

    this.el.innerHTML = html;

    // Bind events
    var recordBtn = this.el.querySelector('[data-action="record"]');
    var uploadBtn = this.el.querySelector('[data-action="upload"]');
    var fileInput = this.el.querySelector('[data-role="file-input"]');
    var submitBtn = this.el.querySelector('[data-action="submit"]');

    recordBtn.addEventListener('click', function () { self.toggleRecording(recordBtn); });
    uploadBtn.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () { if (fileInput.files[0]) self.uploadFile(fileInput.files[0]); });
    submitBtn.addEventListener('click', function () { self.submitText(); });
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

    // Microphone requires HTTPS or localhost — check early with a clear message
    if (window.isSecureContext === false) {
      this.showError('Microphone requires HTTPS or localhost. Try http://localhost:10008 for local testing, or upload a pre-recorded file.');
      return;
    }

    var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    if (!SpeechRecognition) {
      this.startRecordingFallback(btn);
      return;
    }

    this.recognition = new SpeechRecognition();
    this.recognition.continuous = true;
    this.recognition.interimResults = true;
    this.recognition.lang = 'en-AU';

    // Only reset transcript if not continuing from a previous recording
    if (!keepTranscript) this.transcriptParts = [];
    this.interimTranscript = '';
    this.recognitionRestarts = 0;

    this.recognition.onresult = function (event) {
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
      if (final) self.transcriptParts.push(final);
      self.interimTranscript = interim;
      self.updateLiveTranscript();
    };

    this.recognition.onerror = function (event) {
      if (event.error === 'not-allowed') {
        self.isRecording = false;
        self.removeLiveTranscript();
        self.resetRecordBtn(btn);
        self.showError('Microphone access denied. Please allow microphone access and try again.');
      } else if (event.error === 'no-speech') {
        // Silence pause — not fatal, recognition will restart via onend
      } else {
        console.error('Speech recognition error:', event.error);
      }
    };

    this.recognition.onend = function () {
      // Web Speech API stops on silence; restart if user hasn't clicked stop
      if (self.isRecording && self.recognitionRestarts < 50) {
        self.recognitionRestarts++;
        try { self.recognition.start(); } catch (e) { /* already started */ }
      }
    };

    this.recognition.start();
    this.isRecording = true;

    // UI: show recording state
    btn.classList.add('recording');
    btn.innerHTML = '<span class="hdlv2-ac-dot"></span>';
    btn.title = 'Stop recording';

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
  };

  // Fallback for browsers without Web Speech API (Firefox, etc.)
  AudioComponent.prototype.startRecordingFallback = function (btn) {
    var self = this;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      this.showError('Your browser does not support audio recording. Please use Chrome, Edge, or Safari, or upload a file.');
      return;
    }

    navigator.mediaDevices.getUserMedia({ audio: true })
      .then(function (stream) {
        self.audioChunks = [];
        self.mediaRecorder = new MediaRecorder(stream);

        self.mediaRecorder.addEventListener('dataavailable', function (e) {
          if (e.data.size > 0) self.audioChunks.push(e.data);
        });

        self.mediaRecorder.addEventListener('stop', function () {
          stream.getTracks().forEach(function (t) { t.stop(); });
          var blob = new Blob(self.audioChunks, { type: 'audio/webm' });
          self.processAudioBlob(blob);
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
      .catch(function () {
        self.showError('Microphone access denied. Please allow microphone access and try again.');
      });
  };

  AudioComponent.prototype.stopRecording = function (btn) {
    var self = this;
    this.isRecording = false;

    // Web Speech API path
    if (this.recognition) {
      this.recognition.stop();
      // Visual feedback immediately — don't wait for grace period
      this.removeLiveTranscript();
      this.resetRecordBtn(btn);

      // Grace period: let the API fire final onresult events after stop()
      setTimeout(function () {
        self.recognition = null;
        var fullTranscript = self.transcriptParts.join(' ').trim();

        // Fall back to interim results if no finals arrived yet
        if (!fullTranscript && self.interimTranscript) {
          fullTranscript = self.interimTranscript.trim();
        }

        if (fullTranscript) {
          self.renderTranscriptReview(fullTranscript);
        } else {
          self.showError('We didn\'t catch that. Try recording again \u2014 speak clearly and wait a moment before stopping.');
        }
      }, 600);
      return;
    }

    // MediaRecorder fallback path
    if (this.recordingTimer) {
      clearInterval(this.recordingTimer);
      this.recordingTimer = null;
    }
    if (this.mediaRecorder && this.mediaRecorder.state === 'recording') {
      this.mediaRecorder.stop();
    }
    this.resetRecordBtn(btn);
  };

  AudioComponent.prototype.resetRecordBtn = function (btn) {
    btn.classList.remove('recording');
    btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/></svg>';
    btn.title = 'Record audio';
  };

  AudioComponent.prototype.removeLiveTranscript = function () {
    var el = this.el.querySelector('.hdlv2-ac-live-transcript');
    if (el) el.parentNode.removeChild(el);
  };

  // ── Upload / Process Audio ──
  AudioComponent.prototype.uploadFile = function (file) {
    this.processAudioBlob(file);
  };

  AudioComponent.prototype.processAudioBlob = function (blob) {
    var self = this;
    this.showProcessing('Transcribing your recording...');

    var formData = new FormData();
    formData.append('audio', blob, 'recording.' + (blob.type.split('/')[1] || 'webm'));
    if (this.token) formData.append('token', this.token);

    fetch(this.apiBase + '/transcribe', {
      method: 'POST',
      headers: this.nonce ? { 'X-WP-Nonce': this.nonce } : {},
      body: formData,
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success && data.transcript) {
          self.renderTranscriptReview(data.transcript);
        } else {
          self.showError(data.message || 'No speech detected in the recording. Try again or type your response.');
        }
      })
      .catch(function () {
        self.showError('Connection error. Please try again.');
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
    this.el.innerHTML = '<div class="hdlv2-ac" style="text-align:center;padding:20px 0;">'
      + '<div class="hdlv2-ac-spinner"></div>'
      + '<p style="font-size:13px;color:#888;margin:8px 0 0;">' + msg + '</p>'
      + '</div>';
  };

  // ── State: summary review ──
  AudioComponent.prototype.renderSummary = function (summary) {
    var self = this;
    var display = typeof summary === 'string' ? summary : JSON.stringify(summary, null, 2);

    this.el.innerHTML = '<div class="hdlv2-ac hdlv2-ac-fade">'
      + '<label class="hdlv2-ac-review-label">Here\'s what we captured:</label>'
      + '<div class="hdlv2-ac-summary">' + escHtml(display) + '</div>'
      + '<div class="hdlv2-ac-actions">'
      + '<button type="button" class="hdlv2-ac-btn primary" data-action="confirm">Confirm</button>'
      + '<div class="hdlv2-ac-actions-secondary">'
      + '<button type="button" class="hdlv2-ac-btn secondary" data-action="addmore">Add more</button>'
      + '<button type="button" class="hdlv2-ac-btn secondary" data-action="redo">Redo</button>'
      + '</div></div></div>';

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
    var el = this.el.querySelector('.hdlv2-ac-live-transcript');
    if (el) el.textContent = combined || 'Listening...';
  };

  // ── State: transcript review ──
  AudioComponent.prototype.renderTranscriptReview = function (transcript) {
    var self = this;
    this.rawTranscript = transcript;

    this.el.innerHTML = '<div class="hdlv2-ac hdlv2-ac-fade">'
      + '<label class="hdlv2-ac-review-label">Your transcript \u2014 review and edit if needed:</label>'
      + '<textarea class="hdlv2-ac-transcript-textarea">' + escHtml(transcript) + '</textarea>'
      + '<div class="hdlv2-ac-actions">'
      + '<button type="button" class="hdlv2-ac-btn primary" data-action="extract">Extract Themes with AI</button>'
      + '<div class="hdlv2-ac-actions-secondary">'
      + '<button type="button" class="hdlv2-ac-btn secondary" data-action="continue-recording">'
      + '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/></svg>'
      + ' Continue Recording</button>'
      + '<button type="button" class="hdlv2-ac-btn secondary" data-action="use-asis">Use as-is</button>'
      + '<button type="button" class="hdlv2-ac-btn secondary" data-action="redo">Redo</button>'
      + '</div></div></div>';

    this.el.querySelector('[data-action="extract"]').addEventListener('click', function () {
      self.extractThemes();
    });
    this.el.querySelector('[data-action="continue-recording"]').addEventListener('click', function () {
      self.continueRecording();
    });
    this.el.querySelector('[data-action="use-asis"]').addEventListener('click', function () {
      var ta = self.el.querySelector('.hdlv2-ac-transcript-textarea');
      var text = ta ? ta.value.trim() : self.rawTranscript;
      self.onConfirm(text);
    });
    this.el.querySelector('[data-action="redo"]').addEventListener('click', function () {
      self.rawTranscript = '';
      self.render();
    });
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
  AudioComponent.prototype.showError = function (msg) {
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
