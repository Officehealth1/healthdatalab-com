/**
 * HDL V2 — Automation-tier self-reported consultation (W8).
 *
 * Wires the textarea + audio recorder + submit button rendered by
 * [hdlv2_auto_consultation]. W9 registers the submit endpoint; for W8 we
 * already POST to that URL so the form is fully wired — the server will
 * 404 until W9 lands, surfaced to the user via the inline error path.
 */
(function () {
  'use strict';

  if (!window.HDLV2_AUTO) return;
  var CFG = window.HDLV2_AUTO;

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  ready(function () {
    var root = document.getElementById('hdlv2-auto-root');
    if (!root) return;

    var textarea  = document.getElementById('hdlv2-auto-text');
    var submitBtn = document.getElementById('hdlv2-auto-submit');
    var errorEl   = document.getElementById('hdlv2-auto-error');
    var formEl    = document.getElementById('hdlv2-auto-form');
    var successEl = document.getElementById('hdlv2-auto-success');
    var audioMount = document.getElementById('hdlv2-auto-audio');

    if (!textarea || !submitBtn) return;

    // Enable submit when text has meaningful content. Audio transcripts
    // append to the textarea, so this single rule covers both input paths.
    function updateSubmitState() {
      submitBtn.disabled = ((textarea.value || '').trim().length < 10);
    }
    textarea.addEventListener('input', updateSubmitState);

    // Mount the existing audio component. Live mic transcripts stream into
    // the textarea via onLiveTranscript; final transcripts (Whisper or
    // server Deepgram for uploaded files) land via onConfirm.
    // P0-2 (v0.47.73) — keep the instance so submit can gate on isBusy().
    var audioComp = null;
    if (audioMount && window.HDLAudioComponent) {
      audioComp = HDLAudioComponent.create(audioMount, {
        contextType: 'why_collection',
        apiBase: CFG.audio_base,
        nonce: CFG.nonce,
        showConsent: false,
        asyncUpload: true,
        useAsIsOnly: true,
        // v0.47.2 — iconsOnlyMode so onLiveTranscript actually fires (the live
        // gate requires it) and the component renders only mic+upload icons into
        // #hdlv2-auto-audio instead of a second stray textarea + Process button.
        // Live preview now streams into #hdlv2-auto-text via appendTranscript,
        // matching the consultation/addendum surfaces.
        iconsOnlyMode: true,
        onLiveTranscript: function (combined) {
          appendTranscript(combined, true);
          updateSubmitState();
        },
        onConfirm: function (transcript) {
          appendTranscript(transcript, false);
          updateSubmitState();
        },
        onError: function (msg) {
          showError(msg || 'We could not process that recording. Please type your answers instead.');
        }
      });
    }

    // Live offset: protects pre-existing typed content from being clobbered
    // by streamed live-transcript characters. Resets on every final transcript.
    var liveOffset = null;
    function appendTranscript(text, isLive) {
      if (!text) return;
      if (isLive) {
        if (liveOffset === null) {
          var base = textarea.value || '';
          if (base && !base.endsWith('\n')) {
            textarea.value = base + '\n\n';
          }
          liveOffset = textarea.value.length;
        }
        textarea.value = textarea.value.substring(0, liveOffset) + text;
      } else {
        if (liveOffset !== null) {
          textarea.value = textarea.value.substring(0, liveOffset) + text;
          liveOffset = null;
        } else {
          var sep = textarea.value.trim() ? '\n\n' : '';
          textarea.value += sep + text;
        }
      }
      textarea.scrollTop = textarea.scrollHeight;
    }

    function showError(msg) {
      if (!errorEl) return;
      errorEl.textContent = msg;
      errorEl.hidden = false;
    }
    function clearError() {
      if (!errorEl) return;
      errorEl.textContent = '';
      errorEl.hidden = true;
    }

    submitBtn.addEventListener('click', function () {
      clearError();
      // P0-2 (v0.47.73) — block submit while a recording is still
      // uploading/transcribing; otherwise only the pre-recording text
      // (or nothing) would be submitted and the transcript stranded.
      if (audioComp && typeof audioComp.isBusy === 'function' && audioComp.isBusy()) {
        showError('Hang on — we are still transcribing your recording. It will appear in the box in a few seconds.');
        return;
      }
      var text = (textarea.value || '').trim();
      if (text.length < 10) {
        showError('Please add a few sentences before submitting.');
        return;
      }
      submitBtn.disabled = true;
      submitBtn.textContent = 'Submitting…';

      fetch(CFG.submit_url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': CFG.nonce
        },
        body: JSON.stringify({ text_answers: text })
      }).then(function (r) {
        return r.json().then(
          function (body) { return { ok: r.ok, status: r.status, body: body }; },
          function () { return { ok: r.ok, status: r.status, body: null }; }
        );
      }).then(function (res) {
        if (!res.ok) {
          var msg = (res.body && res.body.message)
            ? res.body.message
            : 'Server returned ' + res.status + '. Please try again in a moment.';
          showError(msg);
          submitBtn.disabled = false;
          submitBtn.textContent = 'Submit my answers';
          return;
        }
        if (formEl) formEl.hidden = true;
        if (successEl) {
          successEl.hidden = false;
          successEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      }).catch(function () {
        showError('Network error. Please check your connection and try again.');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit my answers';
      });
    });

    updateSubmitState();
  });
})();
