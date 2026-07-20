/**
 * Audio P0-2 — persistent failure UX + blob retry + status-before-json.
 * (Launch audit 2026-07-15; signed-off fix plan.)
 *
 * The bugs under test (REAL prototype functions evaluated from source):
 *  (U1) showError auto-hid EVERY error after 5s — an over-cap upload failure
 *       vanished while the user looked away, reading as "saved".
 *  (U2) hideError() — explicit clear used when a new action starts.
 *  (U3) startRecording / uploadFileAsync clear the previous error (source).
 *  (U4) a terminal 4xx upload failure must HOLD the recorded file
 *       (this._lastFailedUpload) and surface the retry UI — the old code
 *       let the closure GC the blob; only path back was re-recording.
 *  (U5) the retry UI re-uploads the SAME held file.
 *  (U6) a non-JSON error body (e.g. web-server 413 HTML page) must be
 *       treated as TERMINAL by status — not mis-retried as a network blip
 *       ending in "check your connection".
 *  (U7) regression — 5xx still retries and a subsequent 200 starts the poll.
 *  (U8) a successful upload clears any held failed file.
 *  (U9) regression + new — network rejects still retry; after the retries
 *       are exhausted the file is held and retry UI shown (not GC'd).
 *
 * Run:  node tests/audio-p0/scenario-upload-failure.mjs   (exit 0/1)
 */
import { readFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const here = dirname(fileURLToPath(import.meta.url));
const src = readFileSync(join(here, '../../assets/js/hdlv2-audio-component.js'), 'utf8');

let pass = 0, fail = 0;
function ok(name, cond, detail) {
  console.log((cond ? 'PASS' : 'FAIL') + ' | ' + name + (cond ? '' : ' | ' + (detail || '')));
  cond ? pass++ : fail++;
}

// ── extract a REAL prototype method from source by brace-matching ──
function extractProto(name) {
  const marker = 'AudioComponent.prototype.' + name + ' = function';
  const idx = src.indexOf(marker);
  if (idx === -1) return null;
  const open = src.indexOf('{', idx);
  let depth = 0;
  for (let i = open; i < src.length; i++) {
    if (src[i] === '{') depth++;
    else if (src[i] === '}') { depth--; if (depth === 0) return src.slice(src.indexOf('function', idx), i + 1); }
  }
  throw new Error('unbalanced braces extracting ' + name);
}

function evalFn(fnSrc, env) {
  const names = Object.keys(env);
  const vals = names.map(k => env[k]);
  return new Function(...names, 'return (' + fnSrc + ');')(...vals);
}

const drain = async (n = 30) => { for (let i = 0; i < n; i++) await Promise.resolve(); };

// ── controllable environment ──
function makeEnv() {
  const state = { fetches: [], timers: [] };
  const env = {
    fetch: (url, opts) => {
      state.fetches.push({ url, opts });
      const r = state.responder(state.fetches.length);
      return r instanceof Error ? Promise.reject(r) : Promise.resolve(r);
    },
    FormData: class { constructor() { this.entries = []; } append(k, v) { this.entries.push([k, v]); } },
    navigator: { onLine: true },
    window: { addEventListener() {}, removeEventListener() {} },
    setTimeout: (cb, ms) => { state.timers.push({ cb, ms }); return state.timers.length; },
    clearTimeout: () => {},
    Date, Math, Object, console,
    document: { createElement: () => stubNode() },
    HDLV2_AC_DEBUG: false,
  };
  state.runTimers = async (maxMs = 1e9) => {
    const due = state.timers.splice(0).filter(t => t.ms <= maxMs);
    for (const t of due) { t.cb(); await drain(); }
  };
  return { env, state };
}

function stubNode() {
  return {
    style: {}, textContent: '', value: '', className: '', type: '',
    children: [], listeners: {},
    appendChild(c) { this.children.push(c); },
    addEventListener(ev, fn) { this.listeners[ev] = fn; },
    setAttribute() {}, removeAttribute() {},
    querySelector() { return null; },
  };
}

function makeComp(env, over) {
  const calls = { showError: [], retryUI: [], polls: [], async: [] };
  const errEl = stubNode();
  const el = {
    querySelector: (sel) => (sel === '[data-role="error"]' ? errEl : null),
    innerHTML: '',
  };
  const comp = Object.assign({
    apiBase: 'https://x.test/wp-json/hdl-v2/v1/audio',
    contextType: 'why_collection', referenceId: 0,
    token: 'a'.repeat(64), nonce: 'nonce1',
    simpleMode: false, iconsOnlyMode: false,
    _destroyed: false, _jobInFlight: false, _carryText: '',
    el,
    render() { /* keep errEl */ },
    refreshActionRow() {},
    hideError() {},
    showAsyncProcessing(stage, sub) { calls.async.push(stage); },
    showError(m) { calls.showError.push(m); },
    showUploadRetry(m) { calls.retryUI.push(m); },
    _restoreCarryOnFail() { return false; },
    pollTranscriptionJob(id) { calls.polls.push(id); },
    onError: null,
  }, over || {});
  return { comp, calls, errEl };
}

const FILE = { name: 'recording.webm', size: 3 * 1024 * 1024, type: 'audio/webm' };

// ════ U1 — showError is persistent (no 5s auto-hide) ════
{
  const { env, state } = makeEnv();
  const showError = evalFn(extractProto('showError'), env);
  const { comp, errEl } = makeComp(env);
  showError.call(comp, 'Upload failed: File upload failed.');
  const shown = errEl.style.display === 'block' && errEl.textContent.indexOf('Upload failed') === 0;
  await state.runTimers(6000); // anything scheduled ≤6s fires
  ok('U1 error still visible >5s after showError (no auto-hide)',
    shown && errEl.style.display === 'block',
    'display=' + errEl.style.display + ' timersScheduled=' + (state.timers.length));
}

// ════ U2 — hideError() exists and hides ════
{
  const { env } = makeEnv();
  const hideSrc = extractProto('hideError');
  if (!hideSrc) {
    ok('U2 hideError() clears the error element', false, 'hideError not found in source');
  } else {
    const showError = evalFn(extractProto('showError'), env);
    const hideError = evalFn(hideSrc, env);
    const { comp, errEl } = makeComp(env);
    showError.call(comp, 'boom');
    hideError.call(comp);
    ok('U2 hideError() clears the error element', errEl.style.display === 'none',
      'display=' + errEl.style.display);
  }
}

// ════ U3 — new actions clear the previous error (source-level) ════
{
  const sr = extractProto('startRecording') || '';
  const up = extractProto('uploadFileAsync') || '';
  ok('U3 startRecording clears prior error (hideError call present)', sr.indexOf('hideError(') !== -1, 'no hideError() in startRecording');
  ok('U3 uploadFileAsync clears prior error (hideError call present)', up.indexOf('hideError(') !== -1, 'no hideError() in uploadFileAsync');
}

// ════ U4 — terminal 4xx: single fetch, file HELD, retry UI shown ════
{
  const { env, state } = makeEnv();
  state.responder = () => ({ status: 400, json: () => Promise.resolve({ code: 'file_too_large', message: 'Audio file must be under 60MB.' }) });
  const uploadFileAsync = evalFn(extractProto('uploadFileAsync'), env);
  const { comp, calls } = makeComp(env);
  uploadFileAsync.call(comp, FILE);
  await drain(); await state.runTimers(); await drain();
  ok('U4 terminal 4xx not retried (exactly 1 fetch)', state.fetches.length === 1, 'fetches=' + state.fetches.length);
  ok('U4 failed file is HELD on this._lastFailedUpload', comp._lastFailedUpload === FILE,
    '_lastFailedUpload=' + String(comp._lastFailedUpload));
  ok('U4 retry UI surfaced (showUploadRetry called, message kept)',
    calls.retryUI.length === 1 && /60MB/.test(calls.retryUI[0]),
    'retryUI=' + JSON.stringify(calls.retryUI) + ' showError=' + JSON.stringify(calls.showError));
  ok('U4 busy flag cleared', comp._jobInFlight === false);
}

// ════ U5 — the retry UI re-uploads the SAME held file ════
{
  const { env } = makeEnv();
  const retrySrc = extractProto('showUploadRetry');
  if (!retrySrc) {
    ok('U5 showUploadRetry re-uploads the held file', false, 'showUploadRetry not found in source');
  } else {
    const showUploadRetry = evalFn(retrySrc, env);
    const uploads = [];
    const { comp, errEl } = makeComp(env, {
      uploadFileAsync(f) { uploads.push(f); },
      hideError() { this._hid = true; },
    });
    // real showError needed by showUploadRetry? give it the real one:
    comp.showError = evalFn(extractProto('showError'), env).bind(comp);
    comp._lastFailedUpload = FILE;
    showUploadRetry.call(comp, 'Upload failed: too large.');
    const btn = errEl.children.find(c => /retry/i.test(c.textContent || ''));
    ok('U5 retry button rendered into the persistent error', !!btn, 'children=' + errEl.children.length);
    if (btn) {
      btn.listeners.click();
      ok('U5 clicking retry re-uploads the SAME file', uploads.length === 1 && uploads[0] === FILE,
        'uploads=' + uploads.length);
    } else {
      ok('U5 clicking retry re-uploads the SAME file', false, 'no button to click');
    }
  }
}

// ════ U6 — non-JSON 4xx (server 413 HTML) is terminal by status ════
{
  const { env, state } = makeEnv();
  state.responder = () => ({ status: 413, json: () => Promise.reject(new Error('not json')) });
  const uploadFileAsync = evalFn(extractProto('uploadFileAsync'), env);
  const { comp, calls } = makeComp(env);
  uploadFileAsync.call(comp, FILE);
  await drain(); await state.runTimers(); await drain(); await state.runTimers(); await drain();
  ok('U6 non-JSON 413 not retried as network blip (exactly 1 fetch)',
    state.fetches.length === 1, 'fetches=' + state.fetches.length);
  const msg = (calls.retryUI[0] || calls.showError[0] || '');
  ok('U6 message says the recording is too large (not "check your connection")',
    /too large|too big/i.test(msg) && !/connection/i.test(msg), 'msg=' + msg);
  ok('U6 file held for retry', comp._lastFailedUpload === FILE);
}

// ════ U7 — regression: 5xx retries, then 200 starts the poll ════
{
  const { env, state } = makeEnv();
  state.responder = (n) => n === 1
    ? { status: 502, json: () => Promise.resolve({ message: 'bad gateway' }) }
    : { status: 200, json: () => Promise.resolve({ success: true, job_id: 77 }) };
  const uploadFileAsync = evalFn(extractProto('uploadFileAsync'), env);
  const { comp, calls } = makeComp(env);
  uploadFileAsync.call(comp, FILE);
  await drain(); await state.runTimers(); await drain();
  ok('U7 5xx retried then 200 → poll started (job 77)',
    state.fetches.length === 2 && calls.polls[0] === 77,
    'fetches=' + state.fetches.length + ' polls=' + JSON.stringify(calls.polls));
}

// ════ U8 — success clears a previously-held failed file ════
{
  const { env, state } = makeEnv();
  state.responder = () => ({ status: 200, json: () => Promise.resolve({ success: true, job_id: 5 }) });
  const uploadFileAsync = evalFn(extractProto('uploadFileAsync'), env);
  const { comp } = makeComp(env);
  comp._lastFailedUpload = { stale: true };
  uploadFileAsync.call(comp, FILE);
  await drain();
  ok('U8 successful upload clears _lastFailedUpload', !comp._lastFailedUpload,
    '_lastFailedUpload=' + JSON.stringify(comp._lastFailedUpload));
}

// ════ U9 — network reject: retries kept; exhausted → file held + retry UI ════
{
  const { env, state } = makeEnv();
  state.responder = () => new Error('network down');
  const uploadFileAsync = evalFn(extractProto('uploadFileAsync'), env);
  const { comp, calls } = makeComp(env);
  uploadFileAsync.call(comp, FILE);
  await drain(); await state.runTimers(); await drain(); await state.runTimers(); await drain(); await state.runTimers(); await drain();
  ok('U9 network reject retried (3 total attempts)', state.fetches.length === 3, 'fetches=' + state.fetches.length);
  ok('U9 exhausted retries → file held + retry UI',
    comp._lastFailedUpload === FILE && calls.retryUI.length === 1,
    'held=' + (comp._lastFailedUpload === FILE) + ' retryUI=' + calls.retryUI.length);
}

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
