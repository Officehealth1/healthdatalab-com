/**
 * HDL V2 Transcriber Worker — Whisper inference in isolation from the main thread.
 *
 * Runs @huggingface/transformers (v3.8.1, self-hosted) in an ES-module worker.
 * Owns the Whisper pipeline across calls so the 145MB model loads exactly once
 * per tab. All audio arrives as transferable Float32Array (16kHz mono) from the
 * main thread — decode happens there because AudioContext in workers has
 * inconsistent browser support.
 *
 * @package HDL_Longevity_V2
 * @since 0.12.0
 */

import { pipeline, env } from '../vendor/transformers.min.js';

// Runs fully in the browser. Force remote model fetch — the plugin ships no
// local models. remoteHost is overridable from the main thread so we can
// proxy through healthdatalab.net in the future without editing this file.
env.allowLocalModels = false;
env.useBrowserCache = true;

let pipelineInstance = null;
let pipelineModel = null;
let currentDevice = null;
let aborted = false;

self.addEventListener('message', async function (ev) {
  const msg = ev.data || {};
  try {
    switch (msg.type) {
      case 'config':
        if (msg.remoteHost) env.remoteHost = msg.remoteHost;
        self.postMessage({ type: 'config-ack' });
        return;

      case 'init':
        aborted = false;
        await ensurePipeline(msg.model, onModelProgress);
        self.postMessage({ type: 'init-done', device: currentDevice, model: pipelineModel });
        return;

      case 'transcribe':
        aborted = false;
        if (!pipelineInstance || pipelineModel !== msg.model) {
          await ensurePipeline(msg.model, onModelProgress);
          self.postMessage({ type: 'init-done', device: currentDevice, model: pipelineModel });
        }
        await runTranscription(msg);
        return;

      case 'abort':
        aborted = true;
        self.postMessage({ type: 'aborted' });
        return;

      default:
        // Unknown message — ignore.
        return;
    }
  } catch (err) {
    self.postMessage({
      type: 'error',
      message: (err && err.message) ? err.message : String(err),
      stack: err && err.stack ? String(err.stack).slice(0, 2000) : '',
    });
  }
});

function onModelProgress(data) {
  self.postMessage({ type: 'progress', stage: 'model', data: data });
}

// Feature-detect WebGPU before initialising the pipeline. The previous
// try/catch approach let ONNX Runtime pick WebGPU, fail at session creation
// ("no available backend found") and surface the error without falling
// through to WASM — notably on Brave, which ships navigator.gpu but blocks
// adapter requests. Asking for an adapter up-front is the only reliable
// signal that the backend is actually usable.
async function ensurePipeline(modelName, onProgress) {
  const model = modelName || 'Xenova/whisper-base';
  if (pipelineInstance && pipelineModel === model) return;

  pipelineInstance = null;
  pipelineModel = null;
  currentDevice = null;

  const device = await detectBestDevice();
  const opts = { device: device, progress_callback: onProgress };
  // fp32 is required for WebGPU session creation; WASM defaults are fine.
  if (device === 'webgpu') opts.dtype = 'fp32';

  pipelineInstance = await pipeline('automatic-speech-recognition', model, opts);
  currentDevice = device;
  pipelineModel = model;
}

// Returns 'webgpu' only if a usable GPU adapter is present. 'wasm' otherwise.
// Runs in a Worker where `navigator.gpu` is available in Chromium-based
// browsers that ship WebGPU. Firefox Workers + Safari <18 + Brave all
// return 'wasm' — which is what we want.
async function detectBestDevice() {
  if (typeof navigator === 'undefined' || !navigator.gpu || typeof navigator.gpu.requestAdapter !== 'function') {
    return 'wasm';
  }
  try {
    const adapter = await navigator.gpu.requestAdapter();
    return adapter ? 'webgpu' : 'wasm';
  } catch (e) {
    return 'wasm';
  }
}

async function runTranscription(msg) {
  const audio = new Float32Array(msg.audio);
  const language = msg.language || 'english';

  const startedAt = Date.now();
  let firstChunkAt = 0;

  const result = await pipelineInstance(audio, {
    chunk_length_s: 30,
    stride_length_s: 5,
    language: language,
    task: 'transcribe',
    return_timestamps: false,
    callback_function: function (x) {
      if (aborted) return;
      if (!firstChunkAt) firstChunkAt = Date.now();
      self.postMessage({
        type: 'progress',
        stage: 'inference',
        data: {
          elapsedMs: Date.now() - startedAt,
          firstChunkMs: firstChunkAt ? firstChunkAt - startedAt : 0,
        },
      });
    },
  });

  if (aborted) return;

  const text = (result && typeof result.text === 'string') ? result.text.trim() : '';
  self.postMessage({
    type: 'transcript',
    text: text,
    durationMs: Date.now() - startedAt,
    audioSeconds: audio.length / 16000,
    device: currentDevice,
  });
}
