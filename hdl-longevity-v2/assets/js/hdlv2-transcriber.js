/**
 * HDL V2 Transcriber — ES-module bridge for in-browser Whisper.
 *
 * Exposes window.HDLTranscriber to the IIFE audio component. All heavy work
 * runs inside hdlv2-transcriber.worker.js; this module only decodes audio to
 * Float32Array @ 16kHz mono, proxies messages, and reports errors back to
 * /wp-json/hdl-v2/v1/audio/client-error for telemetry.
 *
 * Config is injected via window.HDLV2_TRANSCRIBER_CFG (wp_localize_script):
 *   modelName     — 'Xenova/whisper-base' (default) | 'Xenova/whisper-tiny' | etc
 *   workerUrl     — absolute URL to hdlv2-transcriber.worker.js
 *   remoteHost    — override for transformers.js env.remoteHost (null = default HF)
 *   errorEndpoint — URL for client-error logging
 *   nonce         — WP REST nonce
 *   preloadOnIdle — boolean, attempt model download after page idle
 *
 * @package HDL_Longevity_V2
 * @since 0.12.0
 */

const CFG = (typeof window !== 'undefined' && window.HDLV2_TRANSCRIBER_CFG) || {};
const MODEL = CFG.modelName || 'Xenova/whisper-base';
const TARGET_SR = 16000;

let worker = null;
let ready = false;
let initPromise = null;
let preloadAttempted = false;

// Serial queue — one transcription at a time. Each caller gets a dedicated
// message handler attached/detached per call so parallel calls could be added
// later without rewriting. For v0.12.0 the audio component only transcribes
// one clip at a time.
let currentJob = null;

function ensureWorker() {
  if (worker) return worker;
  if (!CFG.workerUrl) throw new Error('HDLV2_TRANSCRIBER_CFG.workerUrl is missing.');
  worker = new Worker(CFG.workerUrl, { type: 'module' });
  worker.addEventListener('error', function (e) {
    reportError('worker.error', e.message || String(e), e.filename + ':' + e.lineno);
    if (currentJob) {
      const j = currentJob; currentJob = null;
      j.reject(new Error(e.message || 'Transcriber worker crashed.'));
    }
  });
  worker.addEventListener('messageerror', function () {
    reportError('worker.messageerror', 'Worker message could not be deserialized.');
  });
  if (CFG.remoteHost) worker.postMessage({ type: 'config', remoteHost: CFG.remoteHost });
  return worker;
}

function init(opts) {
  if (ready) return Promise.resolve();
  if (initPromise) return initPromise;

  const onProgress = (opts && opts.onProgress) || null;
  const w = ensureWorker();

  initPromise = new Promise(function (resolve, reject) {
    function onMsg(ev) {
      const m = ev.data || {};
      if (m.type === 'progress' && onProgress) onProgress(m);
      if (m.type === 'init-done') {
        w.removeEventListener('message', onMsg);
        ready = true;
        resolve({ device: m.device, model: m.model });
      } else if (m.type === 'error') {
        w.removeEventListener('message', onMsg);
        initPromise = null;
        reportError('init.error', m.message, m.stack);
        reject(new Error(m.message || 'Transcriber init failed.'));
      }
    }
    w.addEventListener('message', onMsg);
    w.postMessage({ type: 'init', model: MODEL });
  });

  return initPromise;
}

function transcribeBlob(blob, opts) {
  if (!(blob instanceof Blob)) return Promise.reject(new Error('transcribeBlob expects a Blob.'));
  const onProgress = (opts && opts.onProgress) || null;
  const language = (opts && opts.language) || 'english';

  return init({ onProgress: onProgress })
    .then(function () { return decodeBlob(blob); })
    .then(function (audio) {
      return new Promise(function (resolve, reject) {
        if (currentJob) {
          reject(new Error('Another transcription is already in flight. Call stop() first.'));
          return;
        }
        const w = ensureWorker();

        function onMsg(ev) {
          const m = ev.data || {};
          if (m.type === 'progress' && onProgress) onProgress(m);
          if (m.type === 'transcript') {
            w.removeEventListener('message', onMsg);
            currentJob = null;
            resolve({
              text: m.text,
              durationMs: m.durationMs,
              audioSeconds: m.audioSeconds,
              device: m.device,
            });
          } else if (m.type === 'error') {
            w.removeEventListener('message', onMsg);
            currentJob = null;
            reportError('transcribe.error', m.message, m.stack);
            reject(new Error(m.message || 'Transcription failed.'));
          } else if (m.type === 'aborted') {
            w.removeEventListener('message', onMsg);
            currentJob = null;
            reject(new Error('aborted'));
          }
        }

        w.addEventListener('message', onMsg);
        currentJob = { reject: reject };

        const buf = audio.buffer;
        w.postMessage(
          { type: 'transcribe', audio: buf, model: MODEL, language: language },
          [buf]
        );
      });
    })
    .then(function (r) { return r.text; });
}

function transcribeFile(file, opts) {
  return transcribeBlob(file, opts);
}

function stop() {
  if (worker) {
    try { worker.postMessage({ type: 'abort' }); } catch (e) {}
  }
  if (currentJob) {
    const j = currentJob; currentJob = null;
    j.reject(new Error('aborted'));
  }
}

// Main-thread audio decode → Float32Array mono @ 16kHz.
// Kept on main thread because AudioContext in workers is patchy across browsers
// (Safari still lacks it, Firefox gated behind a flag as of early 2026).
async function decodeBlob(blob) {
  const arrayBuffer = await blob.arrayBuffer();
  const AudioCtx = (typeof window !== 'undefined') ? (window.AudioContext || window.webkitAudioContext) : null;
  if (!AudioCtx) throw new Error('AudioContext not supported in this browser.');

  // Some browsers honour sampleRate on construction, others ignore it.
  // Decode in whatever SR the browser picks, then resample manually.
  const ctx = new AudioCtx();
  try {
    const decoded = await ctx.decodeAudioData(arrayBuffer.slice(0));
    const mono = downmixToMono(decoded);
    if (decoded.sampleRate === TARGET_SR) return mono;
    return resampleLinear(mono, decoded.sampleRate, TARGET_SR);
  } finally {
    try { ctx.close(); } catch (e) {}
  }
}

function downmixToMono(audioBuffer) {
  const ch = audioBuffer.numberOfChannels;
  if (ch === 1) return audioBuffer.getChannelData(0);
  const len = audioBuffer.length;
  const out = new Float32Array(len);
  for (let c = 0; c < ch; c++) {
    const d = audioBuffer.getChannelData(c);
    for (let i = 0; i < len; i++) out[i] += d[i];
  }
  const inv = 1 / ch;
  for (let i = 0; i < len; i++) out[i] *= inv;
  return out;
}

function resampleLinear(input, inSr, outSr) {
  const ratio = inSr / outSr;
  const outLen = Math.floor(input.length / ratio);
  const out = new Float32Array(outLen);
  for (let i = 0; i < outLen; i++) {
    const src = i * ratio;
    const idx = Math.floor(src);
    const frac = src - idx;
    const a = input[idx];
    const b = (idx + 1 < input.length) ? input[idx + 1] : a;
    out[i] = a + (b - a) * frac;
  }
  return out;
}

function reportError(source, message, stack) {
  if (!CFG.errorEndpoint) return;
  const payload = {
    source: String(source || '').slice(0, 100),
    message: String(message || '').slice(0, 1000),
    stack: String(stack || '').slice(0, 2000),
    model: MODEL,
    userAgent: (typeof navigator !== 'undefined' ? navigator.userAgent : '').slice(0, 500),
    ts: Date.now(),
  };
  try {
    fetch(CFG.errorEndpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': CFG.nonce || '',
      },
      body: JSON.stringify(payload),
      keepalive: true,
    }).catch(function () {});
  } catch (e) { /* swallow */ }
}

// Idempotent idle-scheduled init. Safe to call from any consumer; runs at most
// once per page load. The decision of *whether* to preload is made by the
// audio-component at create()-time based on consumer opt-in + master override —
// this function just handles the "when" (idle callback vs setTimeout fallback).
function preload() {
  if (preloadAttempted) return;
  preloadAttempted = true;
  const run = function () { init({ onProgress: function () {} }).catch(function () {}); };
  if (typeof requestIdleCallback === 'function') {
    requestIdleCallback(run, { timeout: 8000 });
  } else {
    setTimeout(run, 3000);
  }
}

if (typeof window !== 'undefined') {
  window.HDLTranscriber = {
    init: init,
    transcribeBlob: transcribeBlob,
    transcribeFile: transcribeFile,
    stop: stop,
    preload: preload,
    isReady: function () { return ready; },
    // Tri-state admin override surfaced from PHP: 'on' | 'off' | null.
    // Consumers consult this alongside their own preloadOnIdle option.
    masterOverride: (CFG.preloadMaster === 'on' || CFG.preloadMaster === 'off') ? CFG.preloadMaster : null,
    config: {
      model: MODEL,
      workerUrl: CFG.workerUrl || null,
      remoteHost: CFG.remoteHost || null,
    },
  };
}
