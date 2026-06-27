/**
 * hdlv2-iris-consult.js — Phase 2 embedded iridology consult (upload → analyse
 * → poll → result), mounted inside the practitioner Consultation tab.
 *
 * Loaded ONLY when hdlv2_ff_iris_consult is on AND the practitioner is entitled
 * (the PHP enqueue self-guards — Rule-0 zero-trace otherwise). Exposes
 * window.HDLV2IrisConsult; hdlv2-client-list-enhance.js calls placeholderHtml()
 * + mount() when it renders the consult tab.
 *
 * Non-negotiables honoured here:
 *  - Images go BROWSER → SUPABASE DIRECT (signed PUT). PHP never carries bytes.
 *  - The browser polls HDL's OWN status route (local MySQL). Never IrisMapper.
 *  - Every failure collapses to a non-blocking banner; the consult is never
 *    blocked and nothing here is on the consult-save critical path.
 */
(function () {
  'use strict';

  var CFG = window.hdlv2_client_enhance || {};

  // Rule-0: do nothing unless both flags + entitlement resolved server-side.
  function active() {
    return !!(CFG.iris_feature_enabled && CFG.iris_consult_enabled && CFG.iris_entitled);
  }

  // Per-consultation runtime state (keyed by progress_id): in-memory photos for
  // instant thumbnails, the active poll timer, and the current jobId.
  var STATE = {};
  function st(pid) { return (STATE[pid] = STATE[pid] || { photos: {}, timer: null, deadline: 0 }); }

  // ── tiny DOM/util helpers (this file is standalone — no shared closure) ──
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function lines(arr) { return (Array.isArray(arr) ? arr : []).join('\n'); }
  function splitLines(s) {
    return String(s || '').split('\n').map(function (x) { return x.trim(); }).filter(Boolean);
  }

  function api(path, opts) {
    opts = opts || {};
    var headers = { 'X-WP-Nonce': CFG.nonce };
    if (opts.body) { headers['Content-Type'] = 'application/json'; }
    return fetch(CFG.api_base + path, {
      method: opts.method || 'GET',
      credentials: 'same-origin',
      headers: headers,
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    }).then(function (r) {
      return r.json().then(function (j) { return { ok: r.ok, status: r.status, json: j }; })
        .catch(function () { return { ok: r.ok, status: r.status, json: {} }; });
    });
  }

  // Downscale a File to <=1568px JPEG q0.92 → { blob, dataUrl }.
  function downscale(file) {
    return new Promise(function (resolve, reject) {
      var img = new Image();
      var url = URL.createObjectURL(file);
      img.onload = function () {
        URL.revokeObjectURL(url);
        var max = 1568, w = img.naturalWidth, h = img.naturalHeight;
        if (w > max || h > max) {
          if (w >= h) { h = Math.round(h * max / w); w = max; }
          else { w = Math.round(w * max / h); h = max; }
        }
        var cv = document.createElement('canvas');
        cv.width = w; cv.height = h;
        cv.getContext('2d').drawImage(img, 0, 0, w, h);
        var dataUrl = cv.toDataURL('image/jpeg', 0.92);
        cv.toBlob(function (blob) {
          if (!blob) { reject(new Error('encode failed')); return; }
          resolve({ blob: blob, dataUrl: dataUrl });
        }, 'image/jpeg', 0.92);
      };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('not an image')); };
      img.src = url;
    });
  }

  function putToSupabase(uploadUrl, blob) {
    return fetch(uploadUrl, {
      method: 'PUT',
      body: blob,
      headers: { 'content-type': 'image/jpeg', 'x-upsert': 'true' },
    }).then(function (r) { return r.ok; }).catch(function () { return false; });
  }

  // ── styles (injected once) — HDL teal/status tokens ──
  var _styled = false;
  function ensureStyles() {
    if (_styled) return; _styled = true;
    var css = ''
      + '.hdlv2-irc{margin:0 0 18px;border:1px solid #e6f3f5;border-radius:14px;background:linear-gradient(120deg,#f0fbfc 0%,#fff 62%);padding:18px 20px;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}'
      + '.hdlv2-irc-eyebrow{display:inline-flex;align-items:center;gap:7px;margin:0 0 4px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#3d8da0;}'
      + '.hdlv2-irc-title{font-family:Poppins,Inter,sans-serif;font-size:17px;font-weight:600;color:#111;margin:0 0 4px;}'
      + '.hdlv2-irc-sub{margin:0 0 14px;font-size:13px;color:#555;line-height:1.5;}'
      + '.hdlv2-irc-eyes{display:flex;gap:14px;flex-wrap:wrap;margin:0 0 14px;}'
      + '.hdlv2-irc-eye{flex:1 1 200px;min-width:180px;border:1px dashed #c9e3e8;border-radius:11px;padding:12px;background:#fff;text-align:center;}'
      + '.hdlv2-irc-eye label{display:block;font-size:12px;font-weight:600;color:#004F59;margin:0 0 8px;}'
      + '.hdlv2-irc-thumb{width:100%;max-width:160px;aspect-ratio:1/1;object-fit:cover;border-radius:9px;border:1px solid #e4e6ea;margin:0 auto 8px;display:block;background:#f8f9fb;}'
      + '.hdlv2-irc-file{font-size:12px;width:100%;}'
      + '.hdlv2-irc-btn{display:inline-flex;align-items:center;gap:8px;border:0;border-radius:9px;background:#3d8da0;color:#fff;font:600 14px/1 Inter,sans-serif;padding:11px 18px;cursor:pointer;}'
      + '.hdlv2-irc-btn:hover{background:#004F59;}'
      + '.hdlv2-irc-btn:disabled{background:#b9c6cb;cursor:not-allowed;}'
      + '.hdlv2-irc-btn-ghost{background:#fff;color:#3d8da0;border:1px solid #cfe3e8;}'
      + '.hdlv2-irc-btn-ghost:hover{background:#f0fbfc;color:#004F59;}'
      + '.hdlv2-irc-spin{display:inline-block;width:18px;height:18px;border:2px solid #cfe3e8;border-top-color:#3d8da0;border-radius:50%;animation:hdlvircspin .8s linear infinite;vertical-align:middle;margin-right:9px;}'
      + '@keyframes hdlvircspin{to{transform:rotate(360deg);}}'
      + '.hdlv2-irc-note{font-size:12px;color:#888;margin:8px 0 0;}'
      + '.hdlv2-irc-banner{border-radius:10px;padding:12px 14px;font-size:13px;line-height:1.5;margin:0;}'
      + '.hdlv2-irc-banner.warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e;}'
      + '.hdlv2-irc-banner.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}'
      + '.hdlv2-irc-banner.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;}'
      + '.hdlv2-irc-res-eye{border:1px solid #e4e6ea;border-radius:11px;padding:14px;margin:0 0 12px;background:#fff;}'
      + '.hdlv2-irc-res-eye h4{font-family:Poppins,Inter,sans-serif;font-size:14px;color:#004F59;margin:0 0 8px;}'
      + '.hdlv2-irc-res-imgs{display:flex;gap:12px;flex-wrap:wrap;margin:0 0 12px;}'
      + '.hdlv2-irc-res-imgs img{width:140px;height:140px;object-fit:cover;border-radius:9px;border:1px solid #e4e6ea;}'
      + '.hdlv2-irc-field{margin:0 0 10px;}'
      + '.hdlv2-irc-field label{display:block;font-size:12px;font-weight:600;color:#2c3e50;margin:0 0 4px;}'
      + '.hdlv2-irc-field textarea{width:100%;min-height:64px;border:1px solid #e4e6ea;border-radius:8px;padding:8px 10px;font:13px/1.5 Inter,sans-serif;color:#2c3e50;resize:vertical;box-sizing:border-box;}'
      + '.hdlv2-irc-meta{font-size:12px;color:#888;margin:0 0 10px;}'
      + '.hdlv2-irc-ro{font-size:13px;color:#555;line-height:1.5;margin:0 0 8px;}'
      + '.hdlv2-irc-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:8px 0 0;}'
      + '.hdlv2-irc-inc{display:flex;align-items:center;gap:7px;font-size:13px;color:#2c3e50;}'
      + '.hdlv2-irc-saved{font-size:12px;color:#10b981;font-weight:600;}';
    var s = document.createElement('style');
    s.id = 'hdlv2-irc-styles';
    s.textContent = css;
    document.head.appendChild(s);
  }

  // ── public: container the consult tab drops in ──
  function placeholderHtml(c) {
    if (!active()) return '';
    return '<div class="hdlv2-irc-host" data-iris-consult data-client="' + esc(c.user_id || 0)
      + '" data-progress="' + esc(c.progress_id || 0) + '"></div>';
  }

  // ── public: mount into the host element after the tab HTML is in the DOM ──
  function mount(root, c) {
    if (!active() || !root) return;
    var host = root.querySelector ? root.querySelector('[data-iris-consult]') : null;
    if (!host) return;
    var pid = parseInt(host.getAttribute('data-progress'), 10) || 0;
    var cid = parseInt(host.getAttribute('data-client'), 10) || 0;
    if (!pid) { host.innerHTML = shell('<p class="hdlv2-irc-note">Complete Stage 3 first to capture iris photos for this client.</p>'); return; }
    ensureStyles();
    // Discover any existing analysis for this consultation.
    host.innerHTML = shell('<p class="hdlv2-irc-note"><span class="hdlv2-irc-spin"></span>Checking iris analysis…</p>');
    api('/iris/analysis-status?progress_id=' + pid + '&client_id=' + cid).then(function (r) {
      if (!r.ok) { renderUpload(host, cid, pid); return; }
      route(host, cid, pid, r.json);
    }).catch(function () { renderUpload(host, cid, pid); });
  }

  function shell(inner) {
    return '<section class="hdlv2-irc">'
      + '<p class="hdlv2-irc-eyebrow">&#128065; Iris Analysis &middot; IrisMapper</p>'
      + '<h3 class="hdlv2-irc-title">Iridology for this client</h3>'
      + inner + '</section>';
  }

  function route(host, cid, pid, data) {
    var state = data && data.state;
    if (state === 'done') { renderDone(host, cid, pid, data); return; }
    // Native-capture: an auto safety-net DRAFT has landed but the practitioner
    // has not finalised it in IrisMapper yet → "not yet captured" placeholder.
    if (state === 'draft') { renderNotCaptured(host, cid, pid); return; }
    if (state === 'queued' || state === 'running') { renderInflight(host, cid, pid, data.jobId); startPoll(host, cid, pid, data.jobId); return; }
    if (state === 'limit') { renderBanner(host, cid, pid, 'warn', "You've reached today's iris-analysis limit. It resets at 00:00 UTC.", true); return; }
    if (state === 'unavailable') { renderBanner(host, cid, pid, 'warn', 'Iris analysis is temporarily unavailable. Your consultation is unaffected — you can try again shortly.', true); return; }
    if (state === 'error') { renderBanner(host, cid, pid, 'err', data.refused ? 'The analysis was declined for these images. You can try different photos.' : 'The analysis failed. You can try again.', true); return; }
    renderUpload(host, cid, pid); // 'none'
  }

  // ── state: upload ──
  function renderUpload(host, cid, pid) {
    ensureStyles();
    var s = st(pid); s.jobId = null; s.photos = {};
    host.innerHTML = shell(''
      + '<p class="hdlv2-irc-sub">Upload the client&rsquo;s left and right iris photos. They are analysed by IrisMapper and the suggested areas to check appear here for you to review and edit — nothing is shared with the client unless you add it to the report.</p>'
      + '<div class="hdlv2-irc-eyes">'
      +   eyeInput('L', 'Left eye') + eyeInput('R', 'Right eye')
      + '</div>'
      + '<button type="button" class="hdlv2-irc-btn" data-act="analyse" disabled>&#128065;&nbsp;Analyse irises</button>'
      + '<p class="hdlv2-irc-note">JPEG/PNG/WebP, up to 8&nbsp;MB each. Photos upload securely and are not stored in the media library.</p>'
    );
    bindUpload(host, cid, pid);
  }
  function eyeInput(eye, label) {
    return '<div class="hdlv2-irc-eye">'
      + '<label>' + esc(label) + '</label>'
      + '<img class="hdlv2-irc-thumb" data-thumb="' + eye + '" alt="" />'
      + '<input class="hdlv2-irc-file" type="file" accept="image/jpeg,image/png,image/webp" data-file="' + eye + '" />'
      + '</div>';
  }
  function bindUpload(host, cid, pid) {
    var s = st(pid);
    var btn = host.querySelector('[data-act="analyse"]');
    function refresh() { btn.disabled = !(s.photos.L && s.photos.R); }
    ['L', 'R'].forEach(function (eye) {
      var input = host.querySelector('[data-file="' + eye + '"]');
      input.addEventListener('change', function () {
        var f = input.files && input.files[0];
        if (!f) { s.photos[eye] = null; refresh(); return; }
        if (!/^image\/(jpeg|png|webp)$/.test(f.type) || f.size > 8 * 1024 * 1024) {
          alert('Please choose a JPEG, PNG or WebP under 8 MB.');
          input.value = ''; s.photos[eye] = null; refresh(); return;
        }
        downscale(f).then(function (out) {
          s.photos[eye] = out;
          var th = host.querySelector('[data-thumb="' + eye + '"]');
          if (th) { th.src = out.dataUrl; }
          refresh();
        }).catch(function () { alert('That image could not be read.'); input.value = ''; s.photos[eye] = null; refresh(); });
      });
    });
    btn.addEventListener('click', function () { doAnalyse(host, cid, pid); });
  }

  function doAnalyse(host, cid, pid) {
    var s = st(pid);
    if (!(s.photos.L && s.photos.R)) return;
    var btn = host.querySelector('[data-act="analyse"]');
    btn.disabled = true; btn.innerHTML = '<span class="hdlv2-irc-spin"></span>Preparing…';

    api('/iris/analyse', { method: 'POST', body: { client_id: cid, progress_id: pid } }).then(function (r) {
      if (!r.ok || !r.json || !r.json.jobId) { renderBanner(host, cid, pid, 'err', 'Could not start the analysis. Please try again.', true); return; }
      var d = r.json;
      if (d.state === 'limit') { renderBanner(host, cid, pid, 'warn', "You've reached today's iris-analysis limit. It resets at 00:00 UTC.", true); return; }
      if (d.state === 'unavailable' || !d.uploadUrls || !d.uploadUrls.L || !d.uploadUrls.R) {
        renderBanner(host, cid, pid, 'warn', 'Iris analysis is temporarily unavailable. Your consultation is unaffected — please try again shortly.', true); return;
      }
      s.jobId = d.jobId;
      btn.innerHTML = '<span class="hdlv2-irc-spin"></span>Uploading…';
      return Promise.all([
        putToSupabase(d.uploadUrls.L.uploadUrl, s.photos.L.blob),
        putToSupabase(d.uploadUrls.R.uploadUrl, s.photos.R.blob),
      ]).then(function (oks) {
        if (!oks[0] || !oks[1]) {
          renderBanner(host, cid, pid, 'warn', 'The photos could not be uploaded. Please check your connection and try again.', true);
          return;
        }
        btn.innerHTML = '<span class="hdlv2-irc-spin"></span>Starting analysis…';
        return api('/iris/start', { method: 'POST', body: { job: s.jobId } }).then(function (sr) {
          if (!sr.ok) { renderBanner(host, cid, pid, 'warn', 'Iris analysis is temporarily unavailable. Please try again shortly.', true); return; }
          var ss = sr.json || {};
          if (ss.state === 'limit') { renderBanner(host, cid, pid, 'warn', "You've reached today's iris-analysis limit.", true); return; }
          if (ss.state === 'unavailable') { renderBanner(host, cid, pid, 'warn', 'Iris analysis is temporarily unavailable. Please try again shortly.', true); return; }
          renderInflight(host, cid, pid, s.jobId);
          startPoll(host, cid, pid, s.jobId);
        });
      });
    }).catch(function () { renderBanner(host, cid, pid, 'err', 'Something went wrong starting the analysis. Please try again.', true); });
  }

  // ── state: in-flight ──
  function renderInflight(host, cid, pid, jobId) {
    ensureStyles();
    st(pid).jobId = jobId;
    host.innerHTML = shell(''
      + '<p class="hdlv2-irc-sub"><span class="hdlv2-irc-spin"></span>Analysing the irises&hellip; this usually takes under a couple of minutes. You can keep working — the result appears here automatically and is saved even if you close this tab.</p>'
    );
  }

  // ── state: native-capture DRAFT landed, not yet finalised ("not yet captured") ──
  function renderNotCaptured(host, cid, pid) {
    ensureStyles();
    var s = st(pid); if (s.timer) { clearTimeout(s.timer); s.timer = null; }
    host.innerHTML = shell(''
      + '<p class="hdlv2-irc-banner info">An iris analysis is in progress for this client but has not been captured yet. '
      +   'Finalise it in IrisMapper (&ldquo;Send to HealthDataLab&rdquo;) and the reviewed result will appear here.</p>'
      + '<div class="hdlv2-irc-actions"><button type="button" class="hdlv2-irc-btn hdlv2-irc-btn-ghost" data-act="refresh">Refresh</button></div>'
    );
    var rb = host.querySelector('[data-act="refresh"]');
    if (rb) {
      rb.addEventListener('click', function () {
        host.innerHTML = shell('<p class="hdlv2-irc-note"><span class="hdlv2-irc-spin"></span>Checking iris analysis&hellip;</p>');
        api('/iris/analysis-status?progress_id=' + pid + '&client_id=' + cid).then(function (r) {
          if (!r.ok) { renderNotCaptured(host, cid, pid); return; }
          route(host, cid, pid, r.json);
        }).catch(function () { renderNotCaptured(host, cid, pid); });
      });
    }
  }

  // ── poll HDL's local status (visibility-gated backoff, ~3min deadline) ──
  function startPoll(host, cid, pid, jobId) {
    var s = st(pid);
    if (s.timer) { clearTimeout(s.timer); s.timer = null; }
    s.jobId = jobId;
    s.deadline = Date.now() + 3 * 60 * 1000; // foreground deadline (callback/cron lands late ones)
    var delay = 5000;
    function tick() {
      if (st(pid).jobId !== jobId) return; // superseded (re-analyse / unmount)
      if (Date.now() > s.deadline) {
        renderBanner(host, cid, pid, 'info', 'Still working&hellip; this is taking longer than usual. You can reopen this client later — the result will be here.', true);
        return;
      }
      if (document.visibilityState === 'hidden') { s.timer = setTimeout(tick, 5000); return; }
      api('/iris/analysis-status?job=' + encodeURIComponent(jobId)).then(function (r) {
        if (st(pid).jobId !== jobId) return;
        var d = (r && r.json) || {};
        if (r.ok && d.state === 'done') { renderDone(host, cid, pid, d); return; }
        if (r.ok && d.state === 'error') { route(host, cid, pid, d); return; }
        if (r.ok && (d.state === 'unavailable' || d.state === 'limit')) { route(host, cid, pid, d); return; }
        delay = Math.min(Math.round(delay * 2), 30000); // 5→10→20→cap 30
        s.timer = setTimeout(tick, delay);
      }).catch(function () { s.timer = setTimeout(tick, Math.min(Math.round(delay * 2), 30000)); });
    }
    s.timer = setTimeout(tick, delay);
  }

  // ── state: done — editable areas-to-check + thumbnails ──
  function renderDone(host, cid, pid, data) {
    ensureStyles();
    var s = st(pid); s.jobId = data.jobId; if (s.timer) { clearTimeout(s.timer); s.timer = null; }
    var result = data.result || {};
    var eyes = Array.isArray(result.eyes) ? result.eyes : [];
    var html = ''
      + '<p class="hdlv2-irc-sub">Review the AI-suggested observations and edit the areas to check before they go anywhere. Your edits are saved separately — the original AI text is always kept.</p>';

    if (data.eyes_label || result.bilateral_notes) {
      // (eyes_label is set server-side; show a small meta line)
    }

    eyes.forEach(function (eye, idx) {
      var label = eye.eye === 'R' ? 'Right eye' : (eye.eye === 'L' ? 'Left eye' : 'Eye ' + (idx + 1));
      var cs = eye.constitution_summary || {};
      var img = '';
      if (eye.eye === 'L' && (data.image_l || (s.photos.L && s.photos.L.dataUrl))) { img = data.image_l || s.photos.L.dataUrl; }
      if (eye.eye === 'R' && (data.image_r || (s.photos.R && s.photos.R.dataUrl))) { img = data.image_r || s.photos.R.dataUrl; }
      html += '<div class="hdlv2-irc-res-eye" data-eye="' + esc(eye.eye || idx) + '">'
        + '<h4>' + esc(label) + '</h4>'
        + (img ? '<div class="hdlv2-irc-res-imgs"><img src="' + esc(img) + '" alt="' + esc(label) + '" /></div>' : '')
        + '<p class="hdlv2-irc-meta">' + esc([cs.constitution, cs.colour_type, cs.structure_grade].filter(Boolean).join(' · ')) + (eye.overall_confidence ? ' · confidence: ' + esc(eye.overall_confidence) : '') + '</p>'
        + (cs.note ? '<p class="hdlv2-irc-ro">' + esc(cs.note) + '</p>' : '')
        + renderObservations(eye.visible_observations)
        + field('Areas to check / questions', 'questions', lines(eye.suggested_questions))
        + field('Map-zone notes', 'mapzones', lines(eye.map_zone_notes))
        + (Array.isArray(eye.low_confidence_or_not_visible) && eye.low_confidence_or_not_visible.length
            ? '<p class="hdlv2-irc-note">Low confidence / not visible: ' + esc(eye.low_confidence_or_not_visible.join('; ')) + '</p>' : '')
        + '</div>';
    });

    if (Array.isArray(result.bilateral_notes) && result.bilateral_notes.length) {
      html += '<div class="hdlv2-irc-res-eye"><h4>Both eyes</h4><p class="hdlv2-irc-ro">' + esc(result.bilateral_notes.join(' ')) + '</p></div>';
    }

    html += '<div class="hdlv2-irc-actions">'
      + '<button type="button" class="hdlv2-irc-btn" data-act="save">Save changes</button>'
      + '<label class="hdlv2-irc-inc"><input type="checkbox" data-act="inc"' + (data.include_in_pdf ? ' checked' : '') + ' /> Include in the report PDF</label>'
      + '<button type="button" class="hdlv2-irc-btn hdlv2-irc-btn-ghost" data-act="reanalyse">Re-analyse</button>'
      + '<span class="hdlv2-irc-saved" data-saved hidden>Saved &#10003;</span>'
      + '</div>';

    host.innerHTML = shell(html);
    bindDone(host, cid, pid, result);
  }

  function renderObservations(obs) {
    if (!Array.isArray(obs) || !obs.length) return '';
    var items = obs.map(function (o) {
      return '<li>' + esc(o.feature) + (o.zone ? ' — ' + esc(o.zone) : '') + (o.location ? ' (' + esc(o.location) + ')' : '')
        + (o.confidence ? ' · ' + esc(o.confidence) : '') + '</li>';
    }).join('');
    return '<p class="hdlv2-irc-meta">Visible observations</p><ul class="hdlv2-irc-ro" style="margin-top:0;padding-left:18px;">' + items + '</ul>';
  }
  function field(label, key, value) {
    return '<div class="hdlv2-irc-field"><label>' + esc(label) + '</label>'
      + '<textarea data-field="' + key + '">' + esc(value) + '</textarea></div>';
  }

  function bindDone(host, cid, pid, result) {
    var s = st(pid);
    var savedEl = host.querySelector('[data-saved]');
    host.querySelector('[data-act="reanalyse"]').addEventListener('click', function () {
      if (confirm('Start a new iris analysis? The current one is kept in the client history.')) { renderUpload(host, cid, pid); }
    });
    host.querySelector('[data-act="inc"]').addEventListener('change', function (e) {
      api('/iris/areas-edit', { method: 'POST', body: { job: s.jobId, include_in_pdf: !!e.target.checked } });
    });
    host.querySelector('[data-act="save"]').addEventListener('click', function () {
      var overlay = buildOverlay(host, result);
      api('/iris/areas-edit', { method: 'POST', body: { job: s.jobId, areas: overlay } }).then(function (r) {
        if (r.ok && savedEl) { savedEl.hidden = false; setTimeout(function () { savedEl.hidden = true; }, 2500); }
      });
    });
  }

  // Deep-clone the AI result and replace the edited fields (seed-from-shown).
  function buildOverlay(host, result) {
    var overlay = JSON.parse(JSON.stringify(result || {}));
    var eyes = Array.isArray(overlay.eyes) ? overlay.eyes : [];
    host.querySelectorAll('.hdlv2-irc-res-eye[data-eye]').forEach(function (block) {
      var code = block.getAttribute('data-eye');
      var eye = eyes.filter(function (e) { return String(e.eye) === String(code); })[0];
      if (!eye) return;
      var q = block.querySelector('[data-field="questions"]');
      var m = block.querySelector('[data-field="mapzones"]');
      if (q) { eye.suggested_questions = splitLines(q.value); }
      if (m) { eye.map_zone_notes = splitLines(m.value); }
    });
    return overlay;
  }

  // ── terminal banner (limit / unavailable / error / deadline) ──
  function renderBanner(host, cid, pid, kind, msg, withRetry) {
    ensureStyles();
    var s = st(pid); if (s.timer) { clearTimeout(s.timer); s.timer = null; }
    host.innerHTML = shell(''
      + '<p class="hdlv2-irc-banner ' + kind + '">' + msg + '</p>'
      + (withRetry ? '<div class="hdlv2-irc-actions"><button type="button" class="hdlv2-irc-btn hdlv2-irc-btn-ghost" data-act="retry">Try again</button></div>' : '')
    );
    var rb = host.querySelector('[data-act="retry"]');
    if (rb) { rb.addEventListener('click', function () { renderUpload(host, cid, pid); }); }
  }

  window.HDLV2IrisConsult = { placeholderHtml: placeholderHtml, mount: mount, active: active };
})();
