#!/usr/bin/env node
/**
 * Plan 15 T1: assets/js/hdlv2-live-listen.js, the REAL source (with the REAL audio component it borrows its type
 * pick and loudness from), run in vm under a fake clock, a fake microphone, fake recorders and a fake fetch.
 *   C.1  start: the microphone once, one recorder, "listening".
 *   C.2  a cut after 700 ms of quiet once a piece is 6 s long: the next recorder starts BEFORE the last stops;
 *        one POST with the nonce, progress_id, seq 1 and the sound.
 *   C.3  a forced cut at 30 s of non-stop sound.
 *   C.4  a piece with no sound is dropped, not sent.
 *   C.5  answers that arrive out of order are handed over by seq.
 *   C.6  one retry (a new key), then "missed" and the next piece still lands.
 *   C.7  three failed pieces in a row stop listening ("unavailable") and free the microphone.
 *   C.8  no cut for 40 s (a tab in the background): "paused", then a cut at once.
 *   C.9  leaving the page frees the microphone and sends nothing.
 *   C.10 a browser that refuses a second recorder on one stream: stop, then start (no crash, nothing lost).
 *   C.11 the server's answers: resting for today, the hourly limit, no credit: each stops with its own state.
 *   C.12 stop(): the last piece with sound is sent, then the microphone is freed, "off".
 *   C.13 the meter runs on timers (no animation frames, no setInterval); the type pick and the loudness are the
 *        audio component's own functions, not a copy.
 *   C.14 (gate fix G6) the recorder's rate: mp4 (Safari) at 48000 bits a second, WebM at 32000.
 *   C.15 (the card, v0.49.06) onLevel: the loudness on each tick while on, 0 to 1; 0 unless the tick counts as
 *        sound; 0 when the meter cannot measure; nothing after stop().
 * Run: node tests/live-listen/test-listen-cutter.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const read = (p) => { try { return fs.readFileSync(new URL(p, import.meta.url), 'utf8'); } catch (e) { return ''; } };
const LISTEN = read('../../assets/js/hdlv2-live-listen.js');
const AUDIO = read('../../assets/js/hdlv2-audio-component.js');
ok(LISTEN !== '', 'assets/js/hdlv2-live-listen.js exists');
if (!LISTEN) { console.log(`\nPASS=${PASS} FAIL=${FAIL}`); process.exit(1); }

function world(opts = {}) {
  let now = 1_000_000;
  const timers = [];
  const setTimeout = (f, ms) => { timers.push({ at: now + (ms || 0), f, id: timers.length + 1 }); return timers.length; };
  const clearTimeout = (id) => { const t = timers.find((x) => x.id === id); if (t) t.f = null; };
  const hold = { on: false };              // a throttled background tab: the clock moves, timers do not run
  function advance(ms) {
    const end = now + ms;
    for (;;) {
      if (hold.on) { now = end; return; }
      const due = timers.filter((t) => t.f && t.at <= end).sort((a, b) => a.at - b.at || a.id - b.id)[0];
      if (!due) { now = end; return; }
      now = Math.max(now, due.at); const f = due.f; due.f = null; f();
    }
  }
  const sound = { loud: false };
  const track = { stopped: 0, stop() { this.stopped++; } };
  const stream = { getTracks: () => [track] };
  let gum = 0;
  const recs = [], log = [];
  class MediaRecorder {
    static isTypeSupported(t) { return opts.mp4 ? /^audio\/mp4/.test(t) : t === 'audio/webm;codecs=opus'; }
    constructor(s, o) {
      if (opts.oneAtATime && recs.some((r) => r.state === 'recording')) throw new Error('NotSupportedError');
      this.stream = s; this.mimeType = (o && o.mimeType) || 'audio/webm'; this.bps = o && o.audioBitsPerSecond; this.state = 'inactive'; this.l = {}; this.n = recs.length + 1; recs.push(this);
    }
    addEventListener(t, f) { (this.l[t] = this.l[t] || []).push(f); }
    start() { this.state = 'recording'; log.push('start' + this.n); }
    stop() { if (this.state !== 'recording') return; this.state = 'inactive'; log.push('stop' + this.n);
      Promise.resolve().then(() => { (this.l.dataavailable || []).forEach((f) => f({ data: new Blob(['x'.repeat(5000)], { type: this.mimeType }) })); (this.l.stop || []).forEach((f) => f({})); }); }
  }
  class AudioContext {
    constructor() { this.state = 'running'; }
    resume() {} close() { this.state = 'closed'; }
    createMediaStreamSource() { return { connect() {} }; }
    createAnalyser() { return { fftSize: 512, getByteTimeDomainData(d) { for (let i = 0; i < d.length; i++) d[i] = sound.flat ? 128 : sound.loud ? (i % 2 ? 168 : 88) : (i % 2 ? 129 : 127); } }; }
  }
  const posts = [];
  const fetch = (url, o) => new Promise((resolve, reject) => {
    const p = { url, o, form: o.body, key: o.headers['Idempotency-Key'], seq: Number(o.body.get('seq')),
      ok: (data, status = 200) => resolve({ status, ok: status < 300, json: () => Promise.resolve(data) }), drop: () => reject(new TypeError('Failed to fetch')) };
    posts.push(p);
  });
  const docL = {}, winL = {};
  const ctx = {
    setTimeout, clearTimeout, Date: { now: () => now }, Promise, Math, JSON, Array, Object, Number, String, Uint8Array, Blob, File, FormData, Error, TypeError,
    MediaRecorder, AudioContext, fetch, console: { log() {} },
    navigator: { mediaDevices: { getUserMedia: () => { gum++; return opts.deny ? Promise.reject(new Error('NotAllowedError')) : Promise.resolve(stream); } } },
    document: { visibilityState: 'visible', addEventListener: (t, f) => { (docL[t] = docL[t] || []).push(f); }, removeEventListener() {} },
    addEventListener: (t, f) => { (winL[t] = winL[t] || []).push(f); }, removeEventListener: (t, f) => { winL[t] = (winL[t] || []).filter((g) => g !== f); },
  };
  ctx.window = ctx;
  vm.createContext(ctx);
  vm.runInContext(AUDIO, ctx);
  vm.runInContext(LISTEN, ctx);
  const texts = [], states = [], levels = [];
  const L = ctx.HDLV2LiveListen.create({ url: '/wp-json/hdl-v2/v1/audio/listen-piece', nonce: 'n0nce', progressId: 10,
    onText: (seq, text) => texts.push([seq, text]), onState: (s) => states.push(s), onLevel: opts.noLevel ? undefined : (v) => levels.push(v) });
  return { ctx, L, advance, sound, track, recs, log, posts, texts, states, levels, hold, winL, gum: () => gum, now: () => now };
}
const flush = async () => { for (let i = 0; i < 6; i++) await Promise.resolve(); await new Promise((r) => setImmediate(r)); };
const speak = (w, ms) => { w.sound.loud = true; w.advance(ms); };
const quiet = (w, ms) => { w.sound.loud = false; w.advance(ms); };
const last = (a) => a[a.length - 1];

// C.1, C.2
let w = world();
w.L.start(); await flush();
ok(w.gum() === 1 && w.recs.length === 1 && w.recs[0].state === 'recording' && last(w.states) === 'listening', 'C.1 start: the microphone once, one recorder, "listening"');
speak(w, 5000); quiet(w, 900); await flush();
ok(w.posts.length === 0, 'C.2a no cut before the piece is 6 s long, even after a pause');
speak(w, 1500); quiet(w, 800); await flush();
ok(w.log.indexOf('start2') > -1 && w.log.indexOf('start2') < w.log.indexOf('stop1'), 'C.2b the next recorder starts before the last one stops');
const p1 = w.posts[0];
ok(w.posts.length === 1 && p1.seq === 1 && p1.form.get('progress_id') === '10' && p1.form.get('audio') && p1.form.get('audio').size > 0 && p1.o.headers['X-WP-Nonce'] === 'n0nce' && p1.o.method === 'POST' && !!p1.key,
  'C.2c one POST: nonce, progress_id, seq 1, the sound, an Idempotency-Key');

// C.3
w = world(); w.L.start(); await flush();
speak(w, 29900); await flush();
ok(w.posts.length === 0, 'C.3a no cut while sound goes on under 30 s');
speak(w, 300); await flush();
ok(w.posts.length === 1, 'C.3b a forced cut at 30 s');

// C.4
w = world(); w.L.start(); await flush();
quiet(w, 7000); await flush();
ok(w.recs.length >= 2 && w.posts.length === 0, 'C.4 a piece with no sound is cut and dropped, not sent');

// C.5
w = world(); w.L.start(); await flush();
speak(w, 6100); quiet(w, 800); await flush();
speak(w, 6100); quiet(w, 800); await flush();
ok(w.posts.length === 2, 'C.5a two pieces sent');
w.posts[1].ok({ seq: 2, text: 'second' }); await flush();
ok(w.texts.length === 0, 'C.5b piece 2 answered first: held back');
w.posts[0].ok({ seq: 1, text: 'first' }); await flush();
ok(JSON.stringify(w.texts) === JSON.stringify([[1, 'first'], [2, 'second']]), 'C.5c then both, in order');

// C.6
w = world(); w.L.start(); await flush();
speak(w, 6100); quiet(w, 800); await flush();
w.posts[0].drop(); await flush(); w.advance(2000); await flush();
ok(w.posts.length === 2 && w.posts[1].seq === 1 && w.posts[1].key !== w.posts[0].key, 'C.6a one retry, with a new key');
w.posts[1].ok({ code: 'listen_failed' }, 502); await flush(); w.advance(2000); await flush();
ok(w.posts.length === 2 && last(w.states) === 'missed', 'C.6b no second retry: "missed"');
speak(w, 6100); quiet(w, 800); await flush();
w.posts[2].ok({ seq: 2, text: 'after the gap' }); await flush();
ok(JSON.stringify(w.texts) === JSON.stringify([[2, 'after the gap']]) && w.L.isOn(), 'C.6c the next piece still lands, listening goes on');

// C.7
w = world(); w.L.start(); await flush();
for (let i = 0; i < 3; i++) {
  speak(w, 6100); quiet(w, 800); await flush();
  const p = last(w.posts); p.drop(); await flush(); w.advance(2000); await flush(); last(w.posts).drop(); await flush(); w.advance(2000); await flush();
}
ok(!w.L.isOn() && last(w.states) === 'unavailable' && w.track.stopped > 0, 'C.7 three failed pieces in a row: listening stops, "unavailable", microphone freed');

// C.8
w = world(); w.L.start(); await flush();
speak(w, 2000); w.hold.on = true; speak(w, 41000); w.hold.on = false; w.advance(100); await flush();
ok(w.states.includes('paused') && w.posts.length === 1, 'C.8 no cut for 40 s: "paused", and the piece is cut at once');

// C.9
w = world(); w.L.start(); await flush();
speak(w, 3000);
(w.winL.pagehide || []).forEach((f) => f({})); await flush();
ok(w.track.stopped > 0 && w.posts.length === 0 && !w.L.isOn(), 'C.9 leaving the page frees the microphone and sends nothing');

// C.10
w = world({ oneAtATime: true }); w.L.start(); await flush();
speak(w, 6100); quiet(w, 800); await flush();
ok(w.posts.length === 1 && w.recs.filter((r) => r.state === 'recording').length === 1 && w.L.isOn(), 'C.10 a second recorder refused: stop, then start; the piece is sent, listening goes on');

// C.11
for (const [status, body, want] of [[429, { code: 'listen_resting' }, 'resting'], [429, { code: 'rate_limit_exceeded' }, 'limit'], [503, { code: 'listen_no_credit' }, 'unavailable']]) {
  w = world(); w.L.start(); await flush();
  speak(w, 6100); quiet(w, 800); await flush();
  w.posts[0].ok(body, status); await flush();
  ok(!w.L.isOn() && last(w.states) === want && w.track.stopped > 0 && w.posts.length === 1, `C.11 ${status} ${body.code}: stops, "${want}", no retry`);
}

// C.12
w = world(); w.L.start(); await flush();
speak(w, 3000);
w.L.stop(); await flush();
ok(w.posts.length === 1 && w.track.stopped > 0 && last(w.states) === 'off', 'C.12a stop(): the last piece with sound is sent, microphone freed, "off"');
w.posts[0].ok({ seq: 1, text: 'last words' }); await flush();
ok(JSON.stringify(w.texts) === JSON.stringify([[1, 'last words']]), 'C.12b its words still arrive after the stop');
w = world({ deny: true }); w.L.start(); await flush();
ok(!w.L.isOn() && last(w.states) === 'denied', 'C.12c the microphone refused: "denied"');

// C.13
ok(!/requestAnimationFrame|setInterval/.test(LISTEN), 'C.13a the meter runs on timers: no animation frames, no setInterval');
ok(/HDLAudioComponent\.pickType\(/.test(LISTEN) && /HDLAudioComponent\.rms\(/.test(LISTEN) && !/isTypeSupported/.test(LISTEN) && /pickType:\s*pickType/.test(AUDIO) && /rms:\s*rms/.test(AUDIO),
  'C.13b the type pick and the loudness are the audio component\'s own functions');
ok(!/SpeechRecognition/.test(LISTEN) && !/\balert\(|\bconfirm\(|\bprompt\(/.test(LISTEN), 'C.13c no browser speech service and no native pop-up');

// C.14 (gate fix G6): a 30 s mp4 piece at 64 kbps sat within 6 % of the server's 256 KB cap
w = world({ mp4: true }); w.L.start(); await flush();
ok(/mp4/.test(w.recs[0].mimeType) && w.recs[0].bps === 48000, 'C.14a an mp4 recorder (Safari) is made with 48000 bits a second');
w = world(); w.L.start(); await flush();
ok(/webm/.test(w.recs[0].mimeType) && w.recs[0].bps === 32000, 'C.14b a WebM recorder stays at 32000');

// C.15 (the card, v0.49.06): the page's meter is fed from the tick that already reads the loudness
w = world(); w.L.start(); await flush();
speak(w, 500);
ok(w.levels.length === 5 && w.levels.every((v) => typeof v === 'number' && v > 0 && v <= 1), 'C.15a sound: one level a tick (100 ms), each above 0 and at most 1');
quiet(w, 300);
ok(w.levels.length === 8 && w.levels.slice(5).every((v) => v === 0), 'C.15b quiet (under the level that counts as sound): 0, so the bars lie flat');
w.sound.flat = true; w.advance(200);
ok(w.levels.length === 10 && w.levels.slice(8).every((v) => v === 0), 'C.15c the meter cannot measure (-1): handed over as 0, never a negative number');
w.L.stop(); await flush(); w.advance(1000); await flush();
ok(w.levels.length === 10, 'C.15d nothing after stop()');
w = world({ noLevel: true }); w.L.start(); await flush();
speak(w, 6100); quiet(w, 800); await flush();
ok(w.posts.length === 1 && w.levels.length === 0 && /o\.onLevel/.test(LISTEN), 'C.15e onLevel is optional: without it the engine cuts and sends as before');

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
