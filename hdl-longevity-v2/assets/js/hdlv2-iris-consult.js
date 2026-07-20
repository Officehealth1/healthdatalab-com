/**
 * hdlv2-iris-consult.js — Phase 2 CAPTURE-ONLY iridology consult, mounted inside
 * the practitioner Consultation tab.
 *
 * ALL iris work (upload, mapping, analysis) happens on irismapper.com. This file
 * NEVER uploads photos and NEVER triggers an analysis — it READS HDL's own local
 * status route, DISPLAYS the result IrisMapper pushed (rendered to match the
 * IrisMapper report), lets the practitioner EDIT the text (saved as a separate
 * overlay; the AI original is immutable), and toggles "Include in report PDF".
 *
 * Loaded ONLY when hdlv2_ff_iris_consult is on AND the practitioner is entitled
 * (the PHP enqueue self-guards — Rule-0 zero-trace otherwise). Exposes
 * window.HDLV2IrisConsult; hdlv2-client-list-enhance.js calls placeholderHtml()
 * + mount() when it renders the consult tab.
 *
 * Non-negotiables honoured here:
 *  - HDL makes ZERO outbound analysis calls. The browser reads HDL's OWN status
 *    route (local MySQL) only — never IrisMapper, never Opus.
 *  - Practitioner edits are saved separately (areas_edited_json); result_json
 *    (the AI original) is never overwritten and is always recoverable.
 */
(function () {
  'use strict';

  var CFG = window.hdlv2_client_enhance || {};

  // Rule-0: do nothing unless both flags + entitlement resolved server-side.
  function active() {
    return !!(CFG.iris_feature_enabled && CFG.iris_consult_enabled && CFG.iris_entitled);
  }

  // Per-consultation runtime state (keyed by progress_id): the current jobId
  // (capture handle) only. No photos, no poll timer — capture-only is passive.
  var STATE = {};
  function st(pid) { return (STATE[pid] = STATE[pid] || { jobId: null }); }

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

  // ── styles (injected once) — HDL teal/status tokens ──
  var _styled = false;
  function ensureStyles() {
    if (_styled) return; _styled = true;
    var css = ''
      + '.hdlv2-irc{margin:0 0 18px;border:1px solid #e6f3f5;border-radius:14px;background:linear-gradient(120deg,#f0fbfc 0%,#fff 62%);padding:18px 20px;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}'
      + '.hdlv2-irc-eyebrow{display:inline-flex;align-items:center;gap:7px;margin:0 0 4px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#3d8da0;}'
      + '.hdlv2-irc-title{font-family:Poppins,Inter,sans-serif;font-size:17px;font-weight:600;color:#111;margin:0 0 4px;}'
      + '.hdlv2-irc-sub{margin:0 0 14px;font-size:13px;color:#555;line-height:1.5;}'
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
      + '.hdlv2-irc-res-imgs figure{margin:0;text-align:center;}'
      + '.hdlv2-irc-res-imgs img{width:148px;height:148px;object-fit:cover;border-radius:9px;border:1px solid #e4e6ea;cursor:zoom-in;background:#f8f9fb;display:block;}'
      + '.hdlv2-irc-res-imgs figcaption{font-size:11px;color:#888;margin-top:4px;}'
      + '.hdlv2-irc-csum{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin:0 0 10px;}'
      + '.hdlv2-irc-csum div{background:#f8f9fb;border:1px solid #eef1f4;border-radius:8px;padding:8px 10px;}'
      + '.hdlv2-irc-csum dt{font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#3d8da0;margin:0 0 2px;}'
      + '.hdlv2-irc-csum dd{margin:0;font-size:13px;color:#2c3e50;}'
      + '.hdlv2-irc-field{margin:0 0 10px;}'
      + '.hdlv2-irc-field label{display:block;font-size:12px;font-weight:600;color:#2c3e50;margin:0 0 4px;}'
      + '.hdlv2-irc-field textarea{width:100%;min-height:64px;border:1px solid #e4e6ea;border-radius:8px;padding:8px 10px;font:13px/1.5 Inter,sans-serif;color:#2c3e50;resize:vertical;box-sizing:border-box;}'
      + '.hdlv2-irc-meta{font-size:12px;color:#888;margin:0 0 6px;font-weight:600;}'
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
    // Read any captured analysis for this consultation (PURE local read).
    host.innerHTML = shell('<p class="hdlv2-irc-note"><span class="hdlv2-irc-spin"></span>Checking iris analysis&hellip;</p>');
    api('/iris/analysis-status?progress_id=' + pid + '&client_id=' + cid).then(function (r) {
      if (!r.ok) { renderEmpty(host, cid, pid); return; }
      route(host, cid, pid, r.json);
    }).catch(function () { renderEmpty(host, cid, pid); });
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
    // A captured FAILED/declined analysis (terminal). Capture-only never retries
    // from HDL — the practitioner re-runs in IrisMapper.
    if (state === 'error') {
      renderBanner(host, cid, pid, 'err', data && data.refused
        ? 'IrisMapper declined the analysis for these images. Re-run it in IrisMapper with clearer photos and finalise to capture the result here.'
        : 'The last iris analysis failed. Re-run it in IrisMapper and finalise to capture the result here.');
      return;
    }
    renderEmpty(host, cid, pid); // 'none' (and any unexpected legacy state)
  }

  // ── state: nothing captured yet — capture happens in IrisMapper ──
  function renderEmpty(host, cid, pid) {
    ensureStyles();
    st(pid).jobId = null;
    host.innerHTML = shell(''
      + '<p class="hdlv2-irc-sub">No iris analysis has been captured for this client yet. Open IrisMapper, run the analysis for this client, and choose &ldquo;Send to HealthDataLab&rdquo; — the reviewed result and images appear here automatically.</p>'
      + '<div class="hdlv2-irc-actions"><button type="button" class="hdlv2-irc-btn hdlv2-irc-btn-ghost" data-act="refresh">Refresh</button></div>'
    );
    bindRefresh(host, cid, pid);
  }

  // ── state: native-capture DRAFT landed, not yet finalised ("not yet captured") ──
  function renderNotCaptured(host, cid, pid) {
    ensureStyles();
    st(pid).jobId = null;
    host.innerHTML = shell(''
      + '<p class="hdlv2-irc-banner info">An iris analysis is in progress for this client but has not been captured yet. '
      +   'Finalise it in IrisMapper (&ldquo;Send to HealthDataLab&rdquo;) and the reviewed result will appear here.</p>'
      + '<div class="hdlv2-irc-actions"><button type="button" class="hdlv2-irc-btn hdlv2-irc-btn-ghost" data-act="refresh">Refresh</button></div>'
    );
    bindRefresh(host, cid, pid);
  }

  // Manual re-check of HDL's local status (NO IrisMapper call, NO polling loop).
  function bindRefresh(host, cid, pid) {
    var rb = host.querySelector('[data-act="refresh"]');
    if (!rb) return;
    rb.addEventListener('click', function () {
      host.innerHTML = shell('<p class="hdlv2-irc-note"><span class="hdlv2-irc-spin"></span>Checking iris analysis&hellip;</p>');
      api('/iris/analysis-status?progress_id=' + pid + '&client_id=' + cid).then(function (r) {
        if (!r.ok) { renderEmpty(host, cid, pid); return; }
        route(host, cid, pid, r.json);
      }).catch(function () { renderEmpty(host, cid, pid); });
    });
  }

  // ── state: done — IrisMapper-matched result + images + editable overlay ──
  function renderDone(host, cid, pid, data) {
    ensureStyles();
    st(pid).jobId = data.jobId;
    var result = data.result || {};
    var eyes = Array.isArray(result.eyes) ? result.eyes : [];
    var pq = result.photo_quality || {};
    var html = ''
      + '<p class="hdlv2-irc-sub">This is the IrisMapper reading for this client. Review the observations and edit the areas to check before they go into the report — your edits are saved separately and the original AI text is always kept.</p>';

    if (pq && pq.usable === false && pq.suggestion) {
      html += '<p class="hdlv2-irc-banner warn">Photo quality: ' + esc(pq.suggestion) + '</p>';
    }

    eyes.forEach(function (eye, idx) {
      var label = eye.eye === 'R' ? 'Right eye' : (eye.eye === 'L' ? 'Left eye' : 'Eye ' + (idx + 1));
      var cs = eye.constitution_summary || {};
      var irisUrl = eye.eye === 'R' ? data.image_r : data.image_l;
      var mapUrl  = eye.eye === 'R' ? data.map_r   : data.map_l;
      html += '<div class="hdlv2-irc-res-eye" data-eye="' + esc(eye.eye || idx) + '">'
        + '<h4>' + esc(label) + (eye.overall_confidence ? ' &middot; confidence: ' + esc(eye.overall_confidence) : '') + '</h4>'
        + imagesHtml(irisUrl, mapUrl, label)
        + constitutionHtml(cs)
        + (cs.note ? '<p class="hdlv2-irc-ro">' + esc(cs.note) + '</p>' : '')
        + renderObservations(eye.visible_observations)
        + field('Areas to check / questions', 'questions', lines(eye.suggested_questions))
        + field('Map-zone notes', 'mapzones', lines(eye.map_zone_notes))
        + (Array.isArray(eye.low_confidence_or_not_visible) && eye.low_confidence_or_not_visible.length
            ? '<p class="hdlv2-irc-note">Low confidence / not visible: ' + esc(eye.low_confidence_or_not_visible.join('; ')) + '</p>' : '')
        + '</div>';
    });

    if (Array.isArray(result.bilateral_notes) && result.bilateral_notes.length) {
      html += '<div class="hdlv2-irc-res-eye"><h4>Both eyes</h4>'
        + field('Bilateral notes', 'bilateral', lines(result.bilateral_notes)) + '</div>';
    }

    html += '<div class="hdlv2-irc-actions">'
      + '<button type="button" class="hdlv2-irc-btn" data-act="save">Save changes</button>'
      + '<label class="hdlv2-irc-inc"><input type="checkbox" data-act="inc"' + (data.include_in_pdf ? ' checked' : '') + ' /> Include in the report PDF</label>'
      + '<button type="button" class="hdlv2-irc-btn hdlv2-irc-btn-ghost" data-act="refresh">Refresh</button>'
      + '<span class="hdlv2-irc-saved" data-saved hidden>Saved &#10003;</span>'
      + '</div>';

    host.innerHTML = shell(html);
    bindDone(host, cid, pid, result);
    bindRefresh(host, cid, pid);
  }

  function imagesHtml(irisUrl, mapUrl, label) {
    if (!irisUrl && !mapUrl) return '';
    var fig = function (url, cap) {
      return url ? '<figure><img src="' + esc(url) + '" alt="' + esc(label + ' ' + cap) + '" data-zoom="' + esc(url) + '" /><figcaption>' + esc(cap) + '</figcaption></figure>' : '';
    };
    return '<div class="hdlv2-irc-res-imgs">' + fig(irisUrl, 'Iris') + fig(mapUrl, 'Map') + '</div>';
  }

  function constitutionHtml(cs) {
    var cells = [
      ['Base type', cs.constitution],
      ['Colour type', cs.colour_type],
      ['Structure grade', cs.structure_grade],
    ].filter(function (p) { return p[1]; });
    if (!cells.length) return '';
    return '<dl class="hdlv2-irc-csum">' + cells.map(function (p) {
      return '<div><dt>' + esc(p[0]) + '</dt><dd>' + esc(p[1]) + '</dd></div>';
    }).join('') + '</dl>';
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
    // Click an image to open it full-size in a new tab (cookie-auth serve).
    host.querySelectorAll('[data-zoom]').forEach(function (img) {
      img.addEventListener('click', function () { window.open(img.getAttribute('data-zoom'), '_blank', 'noopener'); });
    });
    var inc = host.querySelector('[data-act="inc"]');
    if (inc) {
      inc.addEventListener('change', function (e) {
        api('/iris/areas-edit', { method: 'POST', body: { job: s.jobId, include_in_pdf: !!e.target.checked } });
      });
    }
    var save = host.querySelector('[data-act="save"]');
    if (save) {
      save.addEventListener('click', function () {
        var overlay = buildOverlay(host, result);
        api('/iris/areas-edit', { method: 'POST', body: { job: s.jobId, areas: overlay } }).then(function (r) {
          if (r.ok && savedEl) { savedEl.hidden = false; setTimeout(function () { savedEl.hidden = true; }, 2500); }
        });
      });
    }
  }

  // Deep-clone the AI result and replace ONLY the edited fields (seed-from-shown).
  // The clone is the overlay (areas_edited_json); result_json stays untouched.
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
    var bil = host.querySelector('[data-field="bilateral"]');
    if (bil) { overlay.bilateral_notes = splitLines(bil.value); }
    return overlay;
  }

  // ── terminal banner (captured error / declined) — no upload, no retry-from-HDL ──
  function renderBanner(host, cid, pid, kind, msg) {
    ensureStyles();
    st(pid).jobId = null;
    host.innerHTML = shell(''
      + '<p class="hdlv2-irc-banner ' + kind + '">' + esc(msg) + '</p>'
      + '<div class="hdlv2-irc-actions"><button type="button" class="hdlv2-irc-btn hdlv2-irc-btn-ghost" data-act="refresh">Refresh</button></div>'
    );
    bindRefresh(host, cid, pid);
  }

  window.HDLV2IrisConsult = { placeholderHtml: placeholderHtml, mount: mount, active: active };
})();
