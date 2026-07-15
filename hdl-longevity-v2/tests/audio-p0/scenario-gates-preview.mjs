/**
 * Audio P0-2 (isBusy gates) + P0-4 (Web Speech preview disabled).
 * (Launch audit 2026-07-15; signed-off fix plan.)
 *
 * Under test (REAL source evaluated / asserted):
 *  (G1) P0-4 — startRecording NEVER starts the Web Speech preview even when
 *       SpeechRecognition exists: mic audio was streamed to Google/Apple
 *       (Art. 9 PHI egress). Deepgram (capture→upload) is the only path.
 *  (G2) P0-4 — _previewEnabled is hard-false in source (re-enable = revert).
 *  (G3) consultation "Generate Trajectory Plan" is gated on the recorder
 *       being idle — clicking while a Deepgram job is in flight used to
 *       organise STALE notes (the still-transcribing take missing).
 *  (G4) addendum "Save & Update Plan" (onAddendumSubmit) has the same gate.
 *  (G5) auto-consultation submit has the same gate.
 *
 * Run:  node tests/audio-p0/scenario-gates-preview.mjs   (exit 0/1)
 */
import { readFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const here = dirname(fileURLToPath(import.meta.url));
const acSrc = readFileSync(join(here, '../../assets/js/hdlv2-audio-component.js'), 'utf8');
const consultSrc = readFileSync(join(here, '../../assets/js/hdlv2-consultation.js'), 'utf8');
const autoSrc = readFileSync(join(here, '../../assets/js/hdlv2-auto-consultation.js'), 'utf8');

let pass = 0, fail = 0;
function ok(name, cond, detail) {
  console.log((cond ? 'PASS' : 'FAIL') + ' | ' + name + (cond ? '' : ' | ' + (detail || '')));
  cond ? pass++ : fail++;
}

function extract(source, marker) {
  const idx = source.indexOf(marker);
  if (idx === -1) return null;
  const open = source.indexOf('{', idx);
  let depth = 0;
  for (let i = open; i < source.length; i++) {
    if (source[i] === '{') depth++;
    else if (source[i] === '}') { depth--; if (depth === 0) return source.slice(source.indexOf('function', idx), i + 1); }
  }
  throw new Error('unbalanced braces at marker: ' + marker);
}

const drain = async (n = 30) => { for (let i = 0; i < n; i++) await Promise.resolve(); };

// ════ G1 — P0-4: preview never starts even with SpeechRecognition present ════
{
  const fnSrc = extract(acSrc, 'AudioComponent.prototype.startRecording = function');
  const SR = function () {};
  const stream = { getTracks: () => [], getAudioTracks: () => [] };
  const env = {
    window: { SpeechRecognition: SR, webkitSpeechRecognition: SR },
    navigator: { brave: undefined, mediaDevices: { getUserMedia: () => Promise.resolve(stream) } },
    document: {},
    setTimeout: (cb) => { cb(); return 1; },
    Date, Math, console,
    HDLV2_AC_DEBUG: false,
  };
  const startRecording = new Function(...Object.keys(env), 'return (' + fnSrc + ');')(...Object.values(env));
  const called = { preview: 0, capture: 0, meter: 0 };
  const comp = {
    _starting: false, isRecording: false, _destroyed: false,
    simpleMode: false, transcriptParts: [], interimTranscript: '', recognitionRestarts: 0,
    el: { querySelector: () => null },
    hideError() {}, showError() {},
    _pickMic: () => Promise.resolve(null),
    _beginCapture() { called.capture++; },
    _beginPreview() { called.preview++; },
    _armSilenceMeter() { called.meter++; },
    _micError() {},
  };
  startRecording.call(comp, { }, false);
  await drain();
  ok('G1 capture starts (recorder path intact)', called.capture === 1, 'capture=' + called.capture);
  ok('G1 Web Speech preview NOT started despite SpeechRecognition present',
    called.preview === 0 && comp._previewEnabled === false,
    'preview=' + called.preview + ' _previewEnabled=' + comp._previewEnabled);
}

// ════ G2 — P0-4: hard-false in source ════
{
  const region = acSrc.slice(acSrc.indexOf('AudioComponent.prototype.startRecording'), acSrc.indexOf('AudioComponent.prototype.startRecordingFallback'));
  ok('G2 source sets this._previewEnabled = false (preview disabled at source level)',
    /this\._previewEnabled\s*=\s*false/.test(region),
    'assignment not found in startRecording region');
}

// ════ G3 — consultation Generate gated on recorder idle (functional) ════
{
  const fnSrc = extract(consultSrc, 'function bindActionButton()');
  const actionBtn = { listeners: {}, disabled: false, textContent: '', addEventListener(ev, fn) { this.listeners[ev] = fn; } };
  const notesTa = { value: 'real typed notes' };
  const statuses = [];
  const saveCalls = [];
  const env = {
    document: { getElementById: (id) => (id === 'hdlv2-action-btn' ? actionBtn : (id === 'hdlv2-consult-notes' ? notesTa : null)) },
    state: { actionStage: 'edit', consultId: 9, data: { consultation: {} }, consultAudio: { isBusy: () => true } },
    CFG: { api_base: 'https://x.test/wp-json/hdl-v2/v1/consultation', nonce: 'n' },
    fetch: () => { throw new Error('fetch must not fire while recorder busy'); },
    saveNotes: (raw) => { saveCalls.push(raw); return Promise.resolve(false); },
    setOrganiseStatus: (kind, msg) => statuses.push([kind, msg]),
    renderActionStage: () => '',
    esc: (s) => s,
    window: { scrollTo() {} },
    console,
  };
  let bound;
  try {
    bound = new Function(...Object.keys(env), 'return (' + fnSrc + ');')(...Object.values(env));
    bound();
    actionBtn.listeners.click();
    await drain();
  } catch (e) {
    ok('G3 busy recorder blocks Generate (no save, no organise)', false, 'threw: ' + e.message);
    bound = null;
  }
  if (bound) {
    const errStatus = statuses.find(s => s[0] === 'error');
    ok('G3 busy recorder blocks Generate (no save, no organise)',
      saveCalls.length === 0 && !!errStatus,
      'saveCalls=' + saveCalls.length + ' statuses=' + JSON.stringify(statuses));
    ok('G3 the block message mentions the recording still processing',
      !!errStatus && /record|transcrib/i.test(errStatus[1]), 'msg=' + (errStatus ? errStatus[1] : 'none'));
  }
}

// ════ G4 — addendum submit gate (source-level) ════
{
  const fnSrc = extract(consultSrc, 'function onAddendumSubmit()') || '';
  ok('G4 onAddendumSubmit gates on the addendum recorder being idle',
    /isBusy\s*\(\s*\)/.test(fnSrc),
    'no isBusy() check in onAddendumSubmit');
  ok('G4 addendum audio component instance is retained for the gate',
    /state\.addendumAudio\s*=\s*HDLAudioComponent\.create/.test(consultSrc),
    'HDLAudioComponent.create result not stored as state.addendumAudio');
  ok('G3b consultation audio component instance is retained for the gate',
    /state\.consultAudio\s*=\s*HDLAudioComponent\.create/.test(consultSrc),
    'HDLAudioComponent.create result not stored as state.consultAudio');
}

// ════ G5 — auto-consultation submit gate (source-level) ════
{
  const clickIdx = autoSrc.indexOf("submitBtn.addEventListener('click'");
  const fetchIdx = autoSrc.indexOf('fetch(CFG.submit_url', clickIdx);
  const handler = clickIdx !== -1 && fetchIdx !== -1 ? autoSrc.slice(clickIdx, fetchIdx) : '';
  ok('G5 auto-consult submit gates on the recorder being idle (before fetch)',
    /isBusy\s*\(\s*\)/.test(handler),
    'no isBusy() check between click handler and fetch');
  ok('G5 auto-consult audio component instance is retained for the gate',
    /var\s+audioComp\b|audioComp\s*=\s*HDLAudioComponent\.create/.test(autoSrc),
    'component instance not stored');
}

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
