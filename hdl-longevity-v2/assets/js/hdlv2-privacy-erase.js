/**
 * HDL V2 — "Delete my data" on My account → Privacy (v0.48.64, hdlv2_ff_data_erasure).
 * Two steps on the same line, no browser dialog: the ask, then POST /privacy/erase-request.
 * The server makes the request for the signed-in person's own address and WordPress emails
 * them the link that confirms it is them; the line shows the server's sentence when it sends one.
 */
(function (root) {
  'use strict';

  var ASK = 'This deletes your account, answers, reports and recordings. It cannot be undone.';
  var SENT = 'Check your inbox. The link in the email confirms it is you. It works for one day.';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]; }); };

  /** state: '' (the link), 'ask', 'busy', 'sent'; msg: the server's sentence once sent, else a line under the two buttons. */
  function html(state, msg) {
    if (state === 'sent') return '<span class="hdlv2-your-data-note" role="status">' + esc(msg || SENT) + '</span>';
    if (state === 'ask' || state === 'busy') {
      var off = state === 'busy' ? ' disabled' : '';
      return '<span class="hdlv2-your-data-note">' + ASK + '</span>' +
        '<button type="button" data-erase="send"' + off + '>' + (state === 'busy' ? 'Sending…' : 'Send me the confirmation email') + '</button>' +
        '<button type="button" data-erase="keep"' + off + '>Keep my data</button>' +
        (msg ? '<span class="hdlv2-your-data-error" role="alert">' + esc(msg) + '</span>' : '');
    }
    return '<button type="button" class="hdlv2-your-data-link" data-erase="ask">Delete my data</button>';
  }

  function show(slot, state, msg, focus) {
    slot.innerHTML = html(state, msg);
    var f = focus && slot.querySelector('[data-erase="' + focus + '"]');
    if (f) f.focus();
  }

  function onClick(e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-erase]') : null;
    var slot = b && b.closest('[data-hdlv2-erase]');
    if (!slot) return;
    var act = b.getAttribute('data-erase');
    if (act === 'ask') return show(slot, 'ask', '', 'keep');
    if (act === 'keep') return show(slot, '', '', 'ask');
    if (act !== 'send') return;
    var cfg = root.HDLV2_ERASE || {};
    show(slot, 'busy');
    root.fetch(cfg.url, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce || '' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j || {} }; }, function () { return { ok: false, body: {} }; }); })
      .then(function (res) {
        if (res.ok) show(slot, 'sent', res.body.message);
        else show(slot, 'ask', res.body.message || 'That did not work. Please try again in a minute.', 'send');
      })
      .catch(function () { show(slot, 'ask', 'No answer from the server. Please try again.', 'send'); });
  }

  if (root.document) root.document.addEventListener('click', onClick);
  root.HDLV2Erase = { html: html, onClick: onClick };
})(window);
