/**
 * HDL V2 — "Listen" on the consultation screen (v0.49.04, plan 15). Loaded only when hdlv2_ff_live_listen and
 * hdlv2_ff_live_questions are both on.
 *
 * One job: the microphone to pieces of speech to a callback. A piece is cut after 700 ms of quiet once it is 6 s
 * long, or at 30 s whatever happens; a piece with no sound is dropped. Each piece goes to our own server
 * (/audio/listen-piece, which asks Deepgram for the words), and the words are handed to onText in the order they
 * were spoken. The next recorder starts before the last one stops, so no word falls in a gap; a browser that
 * refuses two recorders on one stream gets stop-then-start instead. The meter runs on timers, not animation
 * frames, so a tab in the background keeps cutting; when the timers are held back for 40 s anyway, the state says
 * "paused". No speech service of the browser is used (off since Matthew's sign-off of 2026-07-15).
 *
 * States handed to onState: starting, listening, paused, missed, limit, resting, unavailable, denied, off.
 * The words for them are the screen's (hdlv2-consultation.js).
 *
 * v0.49.06: onLevel (optional) gets the loudness of each tick for the card's bars, 0 to 1, and 0 unless the tick
 * counts as sound here. The same reading that cuts the pieces; no second timer or analyser.
 *
 * @package HDL_Longevity_V2
 * @since 0.49.04
 */
window.HDLV2LiveListen = (function () {
  'use strict';

  var QUIET_MS = 700;      // quiet that ends a piece
  var MIN_MS = 6000;       // shortest piece
  var MAX_MS = 30000;      // longest piece
  var TICK_MS = 100;       // meter
  var PAUSED_MS = 40000;   // no cut for this long: the timers were held back (a tab in the background)
  var RETRY_MS = 1500;     // one retry of a piece that failed
  var FAILS_TO_STOP = 3;   // pieces lost in a row before listening stops

  function create(o) {
    var on = false, paused = false, stream = null, ctx = null, analyser = null, buf = null, tickTimer = null;
    var type = '', session = 0, cur = null, seq = 0, nextOut = 1, results = {}, fails = 0, lastCut = 0, quietSince = 0;
    var last = '';

    function state(s) { last = s; if (o.onState) o.onState(s); }

    function start() {
      if (on) return;
      on = true;
      session = Date.now();
      state('starting');
      navigator.mediaDevices.getUserMedia({ audio: true }).then(function (s) {
        if (!on) { s.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} }); return; }
        stream = s;
        type = window.HDLAudioComponent.pickType();
        meter();
        cur = begin();
        if (!cur) { giveUp('unavailable'); return; }
        lastCut = Date.now();
        quietSince = 0;
        window.addEventListener('pagehide', onLeave);
        state('listening');
        tickTimer = setTimeout(tick, TICK_MS);
      }, function () { on = false; state('denied'); });
    }

    function meter() {
      var A = window.AudioContext || window.webkitAudioContext;
      if (!A) return; // no meter: every piece runs to MAX_MS
      try {
        ctx = new A();
        if (ctx.state === 'suspended' && ctx.resume) { try { ctx.resume(); } catch (e) {} }
        var src = ctx.createMediaStreamSource(stream);
        analyser = ctx.createAnalyser();
        analyser.fftSize = 512;
        src.connect(analyser);
        buf = new Uint8Array(analyser.fftSize);
      } catch (e) { ctx = null; analyser = null; }
    }

    // One recorder for one piece; null when the browser refuses it.
    function begin() {
      var r;
      var bps = /mp4|aac/.test(type) ? 48000 : 32000; // mp4 (Safari): a 30 s piece stays well under the server's 256 KB
      try { r = type ? new MediaRecorder(stream, { mimeType: type, audioBitsPerSecond: bps }) : new MediaRecorder(stream); } catch (e) { return null; }
      var p = { rec: r, chunks: [], startedAt: Date.now(), heard: false };
      r.addEventListener('dataavailable', function (e) { if (e.data && e.data.size) p.chunks.push(e.data); });
      r.addEventListener('stop', function () { finish(p); });
      try { r.start(); } catch (e) { return null; }
      return p;
    }

    function stopRec(p) {
      try { if (p.rec.state !== 'inactive') { p.rec.stop(); return; } } catch (e) {}
      finish(p); // never stopped: finish now, so a last piece still frees the microphone
    }

    function tick() {
      tickTimer = null;
      if (!on) return;
      if (cur) {
        var now = Date.now();
        if (now - lastCut > PAUSED_MS) {
          paused = true;
          state('paused');
          cur.heard = true; // what the meter could not hear is sent, not guessed away
          cut();
        } else {
          if (paused && document.visibilityState === 'visible') { paused = false; state('listening'); }
          var level = -1;
          if (analyser && ctx && ctx.state === 'running') { analyser.getByteTimeDomainData(buf); level = window.HDLAudioComponent.rms(buf); }
          var heard = level > window.HDLAudioComponent.SOUND_LEVEL;
          var sound = level < 0 || heard; // not measuring counts as sound: never cut a talker
          if (o.onLevel) o.onLevel(heard ? Math.min(1, level) : 0); // the bars lie flat unless this is sound
          if (sound) { cur.heard = true; quietSince = 0; } else if (!quietSince) { quietSince = now; }
          var age = now - cur.startedAt;
          if (age >= MAX_MS || (age >= MIN_MS && quietSince && now - quietSince >= QUIET_MS)) cut();
        }
      }
      if (on) tickTimer = setTimeout(tick, TICK_MS);
    }

    function cut() {
      var old = cur;
      lastCut = Date.now();
      quietSince = 0;
      var next = begin(); // the next starts first, so no word falls in a gap
      if (next) { cur = next; stopRec(old); return; }
      cur = null;          // two recorders refused: stop, then start once the last one has stopped
      old.after = true;
      stopRec(old);
    }

    function finish(p) {
      if (p.done) return;
      p.done = true;
      if (p.after && on) {
        cur = begin();
        lastCut = Date.now();
        if (!cur) giveUp('unavailable');
      }
      if (!p.drop && p.heard && p.chunks.length) {
        send(++seq, new Blob(p.chunks, { type: p.rec.mimeType || type || 'audio/webm' }), 0);
      }
      if (p.final) release();
    }

    function send(n, blob, attempt) {
      var ext = /mp4|aac/.test(blob.type) ? 'm4a' : /ogg/.test(blob.type) ? 'ogg' : 'webm';
      var fd = new FormData();
      fd.append('progress_id', String(o.progressId));
      fd.append('seq', String(n));
      fd.append('audio', new File([blob], 'piece.' + ext, { type: blob.type }));
      fetch(o.url, {
        method: 'POST',
        credentials: 'same-origin',
        // A new key per attempt: the server keeps an answer for its key, a failure included. X-HDLV2-Bg: a limit
        // reached here is said on the card, never in the site's "Give us a moment" box.
        headers: { 'X-WP-Nonce': o.nonce, 'X-HDLV2-Bg': '1', 'Idempotency-Key': 'listen-' + o.progressId + '-' + session + '-' + n + '-' + attempt },
        body: fd
      })
        .then(function (r) {
          return r.json().then(function (b) { return { status: r.status, body: b }; }, function () { return { status: r.status, body: null }; });
        })
        .then(function (res) {
          var code = res.body && res.body.code;
          if (res.status === 200 && res.body && typeof res.body.text === 'string') { land(n, res.body.text); return; }
          if (code === 'listen_resting') { place(n, ''); giveUp('resting'); return; }
          if (res.status === 429) { place(n, ''); giveUp('limit'); return; }
          if (code === 'listen_no_credit') { place(n, ''); giveUp('unavailable'); return; }
          throw res;
        })
        .catch(function () {
          if (attempt < 1) { setTimeout(function () { send(n, blob, attempt + 1); }, RETRY_MS); return; }
          land(n, null);
        });
    }

    // text: the words (maybe ''), or null for a piece lost after its retry.
    function land(n, text) {
      if (text === null) {
        fails++;
        if (on) state('missed');
        if (fails >= FAILS_TO_STOP) giveUp('unavailable');
      } else {
        fails = 0;
        if (on && last === 'missed') state('listening');
      }
      place(n, text);
    }

    // Hand the words over in the order they were spoken.
    function place(n, text) {
      results[n] = text;
      while (Object.prototype.hasOwnProperty.call(results, nextOut)) {
        var t = results[nextOut];
        delete results[nextOut];
        if (t && o.onText) o.onText(nextOut, t);
        nextOut++;
      }
    }

    function release() {
      if (tickTimer) { clearTimeout(tickTimer); tickTimer = null; }
      if (ctx && ctx.close) { try { ctx.close(); } catch (e) {} }
      ctx = null; analyser = null;
      if (stream) stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
      stream = null;
      window.removeEventListener('pagehide', onLeave);
    }

    // Listening ends without sending what is being recorded (a lost server, a limit).
    function giveUp(s) {
      if (!on && !stream) { state(s); return; }
      on = false;
      var p = cur;
      cur = null;
      if (p) { p.drop = true; stopRec(p); }
      release();
      state(s);
    }

    // Leaving the page: the microphone is freed at once and nothing is sent.
    function onLeave() { if (on) giveUp('off'); }

    function stop() {
      if (!on) return;
      on = false;
      if (tickTimer) { clearTimeout(tickTimer); tickTimer = null; }
      var p = cur;
      cur = null;
      if (p) { p.final = true; stopRec(p); } else { release(); }
      state('off');
    }

    return { start: start, stop: stop, isOn: function () { return on; } };
  }

  return { create: create };
})();
