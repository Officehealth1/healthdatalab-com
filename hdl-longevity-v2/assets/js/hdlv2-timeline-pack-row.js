/**
 * HDL V2 — the family pack on the practitioner's client row (v0.48.35). "Grant family pack" and
 * "End pack", each with its two-step on the same line. Loaded only with hdlv2_ff_timeline_pack on;
 * hdlv2-client-list-enhance.js calls sync() for each row. There is no price and no buy button:
 * the practitioner grants, the real purchase comes with the challenge checkout.
 */
(function (root) {
  'use strict';

  var SEATS = 5; // HDLV2_Timeline_Links::PACK_SEATS
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]; }); };
  var btn = function (act, label, primary) { return '<button type="button" data-pack="' + act + '"' + (primary ? ' class="is-primary"' : '') + '>' + label + '</button>'; };

  function lineHtml(c, asking) {
    var f = c && c.family;
    if (!f) return '';
    var p = f.pack, active = !!p && p.status === 'active';
    if (!p && !f.can_grant) return '';
    if (asking === 'grant') return '<span>Grant ' + SEATS + ' seats to ' + esc(c.name || 'this client') + '?</span>' + btn('grant-yes', 'Grant', true) + btn('keep', 'Keep');
    if (asking === 'end') return '<span>End this pack?</span>' + btn('end-yes', 'End', true) + btn('keep', 'Keep');
    var words = active ? 'Family pack · ' + (+p.seats_total) + ' seats · ' + (+p.seats_used) + ' used' : p ? 'Family pack ended' : '';
    return (words ? '<span>' + words + '</span>' : '') + (active ? btn('end', 'End pack') : f.can_grant ? btn('grant', 'Grant family pack') : '');
  }

  function onClick(e) {
    e.stopPropagation(); // the row's own click opens the client
    var b = e.target.closest ? e.target.closest('[data-pack]') : null;
    if (!b) return;
    e.preventDefault();
    var slot = this, c = slot._c, act = b.getAttribute('data-pack');
    if (act === 'grant' || act === 'end' || act === 'keep') {
      slot._asking = act === 'keep' ? '' : act;
      slot.innerHTML = lineHtml(c, slot._asking);
      return;
    }
    var grant = act === 'grant-yes';
    b.disabled = true;
    slot._busy = true;
    var done = function (msg) {
      slot._busy = false;
      slot._asking = '';
      slot._hash = JSON.stringify(c.family);
      slot.innerHTML = lineHtml(c, '') + (msg ? '<span class="hdlv2-pack-msg" role="status">' + esc(msg) + '</span>' : '');
    };
    fetch(String(slot._cfg.api_base || '').replace(/\/$/, '') + (grant ? '/timeline/packs' : '/timeline/packs/' + encodeURIComponent(c.family.pack.id) + '/end'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': slot._cfg.nonce || '' },
      body: JSON.stringify(grant ? { progress_id: c.progress_id } : {})
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j || {} }; }); })
      .then(function (res) {
        if (res.ok && res.body.pack) { c.family.pack = res.body.pack; done(''); }
        else done(res.body.message || 'That did not work. Please try again.');
      })
      .catch(function () { done('No answer from the server. Please try again.'); });
  }

  function sync(row, c, cfg) {
    var cell = row.querySelector('.client-name');
    if (!cell || !c) return;
    var slot = cell.querySelector('.hdlv2-pack-line');
    if (!slot) {
      if (!lineHtml(c, '')) return;
      slot = document.createElement('div');
      slot.className = 'hdlv2-pack-line';
      slot.addEventListener('click', onClick);
      cell.appendChild(slot);
    }
    slot._c = c;
    slot._cfg = cfg || {};
    var hash = JSON.stringify(c.family || null);
    if (slot._asking || slot._busy || slot._hash === hash) return;
    slot._hash = hash;
    slot.innerHTML = lineHtml(c, '');
  }

  root.HDLV2PackRow = { lineHtml: lineHtml, sync: sync };
})(window);
