/**
 * HDL V2 — Client message inbox (Slice C, v0.47.67).
 *
 * ONE script, TWO auth modes, ONE markup shell (HDLV2_Message_Thread_View::inbox_shell):
 *   - 'session' — logged-in client on [hdlv2_my_dashboard]. Config arrives via
 *     wp_localize_script (window.HDLV2_INBOX) with a health_tracker nonce; the
 *     server authorises via is_self_as_client (clientHash must equal the
 *     session user's email hash — self-binding, tampering just 403s).
 *   - 'token'   — anonymous arrival from the emailed scoped link. Config
 *     arrives via the non-executable <script type="application/json"
 *     id="hdlv2-inbox-config"> block; every AJAX call carries the m1. token,
 *     which the V1 handlers accept for THIS thread only.
 *
 * Wire protocol = the V1 messaging admin-ajax handlers (nopriv):
 *   hdl_get_conversation / hdl_poll_messages / hdl_mark_read / hdl_post_message.
 *
 * Read-marking is gated on ACTUAL visibility (IntersectionObserver) so a
 * client sitting on the Today tab never silently clears the practitioner's
 * unread indicator.
 *
 * No innerHTML with server data (textContent only), no executable inline
 * scripts, no external hosts — CSP-safe. Motion lives in CSS behind
 * prefers-reduced-motion.
 */

(function () {
  'use strict';

  var POLL_MS = 20000;

  function readConfig() {
    if (window.HDLV2_INBOX && window.HDLV2_INBOX.mode) return window.HDLV2_INBOX;
    var el = document.getElementById('hdlv2-inbox-config');
    if (el) {
      try { return JSON.parse(el.textContent); } catch (e) { /* fall through */ }
    }
    return null;
  }

  function post(cfg, action, fields) {
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('client_email_hash', cfg.clientHash);
    if (cfg.practitionerId) body.set('practitioner_id', String(cfg.practitionerId));
    if (cfg.mode === 'token') {
      body.set('token', cfg.token);
      body.set('nonce', '');
    } else {
      body.set('nonce', cfg.nonce);
    }
    Object.keys(fields || {}).forEach(function (k) { body.set(k, fields[k]); });
    return fetch(cfg.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function (r) { return r.json(); });
  }

  function fmtTime(msg) {
    var d = msg.sent_at_ts ? new Date(msg.sent_at_ts * 1000) : null;
    if (!d || isNaN(d.getTime())) return msg.sent_at || '';
    return d.toLocaleString('en-GB', {
      day: 'numeric', month: 'short',
      hour: '2-digit', minute: '2-digit'
    });
  }

  function el(tag, className, text) {
    var n = document.createElement(tag);
    if (className) n.className = className;
    if (text !== undefined && text !== null) n.textContent = String(text);
    return n;
  }

  function Inbox(root, cfg) {
    this.root = root;
    this.cfg = cfg;
    this.thread = root.querySelector('[data-inbox-thread]');
    this.form = root.querySelector('[data-inbox-reply]');
    this.text = root.querySelector('[data-inbox-text]');
    this.send = root.querySelector('[data-inbox-send]');
    this.note = root.querySelector('[data-inbox-note]');
    this.prac = root.querySelector('[data-inbox-prac]');
    this.lastId = 0;
    this.hasUnreadFromPrac = false;
    this.markedGeneration = -1;
    this.generation = 0;
    this.seen = false;
    this.dead = false; // set when the token is rejected -> stop polling
  }

  Inbox.prototype.boot = function () {
    var self = this;

    if (this.form) {
      this.form.addEventListener('submit', function (e) {
        e.preventDefault();
        self.sendReply();
      });
    }

    // Mark-as-read only once the thread is genuinely on screen.
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            self.seen = true;
            self.maybeMarkRead();
          }
        });
      }, { threshold: 0.4 });
      io.observe(this.thread);
    } else {
      this.seen = true;
    }

    this.refresh();
    this.pollTimer = window.setInterval(function () {
      if (document.hidden || self.dead) return;
      self.poll();
    }, POLL_MS);
  };

  Inbox.prototype.refresh = function () {
    var self = this;
    post(this.cfg, 'hdl_get_conversation', {})
      .then(function (res) {
        if (!res || res.success !== true) { self.fail(res); return; }
        self.renderThread(res.data || {});
        self.poll();
      })
      .catch(function () {
        self.setNote('Could not load messages. Please try again shortly.');
      });
  };

  Inbox.prototype.renderThread = function (data) {
    var msgs = data.messages || [];
    this.generation++;
    this.hasUnreadFromPrac = false;

    // The config's practitionerId can be 0 (brand-new client) — adopt the
    // server-resolved one so replies route to the right practitioner.
    if (!this.cfg.practitionerId && data.practitioner_id) {
      this.cfg.practitionerId = parseInt(data.practitioner_id, 10) || 0;
    }
    if (this.prac && data.practitioner_name) {
      this.prac.textContent = 'With ' + data.practitioner_name;
    }

    this.thread.textContent = '';
    if (!msgs.length) {
      this.thread.appendChild(el('p', 'hdlv2-inbox-empty',
        'No messages yet. When your practitioner writes to you, the conversation appears here.'));
      return;
    }

    var self = this;
    msgs.forEach(function (m) {
      var mine = m.sender_role === 'client';
      var row = el('div', 'hdlv2-inbox-msg ' + (mine ? 'hdlv2-inbox-msg-client' : 'hdlv2-inbox-msg-prac'));
      var bubble = el('div', 'hdlv2-inbox-bubble');
      if (m.subject && m.subject !== 'Message' && m.subject !== 'Reply') {
        bubble.appendChild(el('div', 'hdlv2-inbox-msg-subject', m.subject));
      }
      var body = el('div', 'hdlv2-inbox-msg-body');
      String(m.message_body || '').split(/\r?\n/).forEach(function (line, i) {
        if (i > 0) body.appendChild(document.createElement('br'));
        body.appendChild(document.createTextNode(line));
      });
      bubble.appendChild(body);
      bubble.appendChild(el('div', 'hdlv2-inbox-msg-time', fmtTime(m)));
      row.appendChild(bubble);
      self.thread.appendChild(row);

      var idNum = parseInt(m.id, 10) || 0;
      if (idNum > self.lastId) self.lastId = idNum;
      if (!mine && String(m.is_read) === '0') self.hasUnreadFromPrac = true;
    });

    this.thread.scrollTop = this.thread.scrollHeight;
    this.maybeMarkRead();
  };

  Inbox.prototype.maybeMarkRead = function () {
    var self = this;
    if (!this.seen || !this.hasUnreadFromPrac || this.dead) return;
    if (this.markedGeneration === this.generation) return;
    this.markedGeneration = this.generation;
    post(this.cfg, 'hdl_mark_read', { reader_role: 'client' })
      .then(function (res) {
        if (res && res.success === true) {
          self.hasUnreadFromPrac = false;
          self.setBadge(0);
        }
      })
      .catch(function () { /* non-fatal; next poll re-reports */ });
  };

  Inbox.prototype.poll = function () {
    var self = this;
    post(this.cfg, 'hdl_poll_messages', { role: 'client', last_id: String(this.lastId) })
      .then(function (res) {
        if (!res || res.success !== true) { self.fail(res); return; }
        var d = res.data || {};
        self.setBadge(parseInt(d.unread_count, 10) || 0);
        if (d.has_new) self.refetchOnly();
      })
      .catch(function () { /* transient; next tick retries */ });
  };

  Inbox.prototype.refetchOnly = function () {
    var self = this;
    post(this.cfg, 'hdl_get_conversation', {})
      .then(function (res) {
        if (res && res.success === true) self.renderThread(res.data || {});
      })
      .catch(function () { /* keep current view */ });
  };

  Inbox.prototype.setBadge = function (n) {
    document.querySelectorAll('[data-inbox-badge]').forEach(function (b) {
      b.textContent = String(n);
      if (n > 0) b.removeAttribute('hidden');
      else b.setAttribute('hidden', 'hidden');
    });
  };

  Inbox.prototype.sendReply = function () {
    var self = this;
    var body = (this.text && this.text.value ? this.text.value : '').trim();
    if (!body) { this.setNote('Write a message first.'); return; }
    if (!this.cfg.practitionerId) {
      this.setNote('No practitioner is linked yet — please reply to the email instead.');
      return;
    }
    if (this.send) this.send.disabled = true;
    this.setNote('Sending…');

    post(this.cfg, 'hdl_post_message', {
      sender_role: 'client',
      subject: 'Reply',
      message_body: body
    })
      .then(function (res) {
        if (self.send) self.send.disabled = false;
        if (!res || res.success !== true) {
          var msg = res && res.data && res.data.message ? res.data.message : '';
          if (self.cfg.mode === 'token' && /security|authoriz/i.test(msg)) { self.fail(res); return; }
          self.setNote(msg || 'Could not send — please try again, or reply to the email.');
          return;
        }
        if (self.text) self.text.value = '';
        self.setNote('Sent ✓');
        self.refetchOnly();
      })
      .catch(function () {
        if (self.send) self.send.disabled = false;
        self.setNote('Could not send — please try again, or reply to the email.');
      });
  };

  /**
   * Token rejected server-side (expired mid-session / revoked salt): degrade
   * exactly like the server-rendered expired card — reply-by-email, never a
   * dead-end. In session mode show a soft error instead.
   */
  Inbox.prototype.fail = function (res) {
    if (this.cfg.mode !== 'token') {
      this.setNote('Could not load messages. Please refresh the page.');
      return;
    }
    this.dead = true;
    if (this.pollTimer) window.clearInterval(this.pollTimer);
    this.thread.textContent = '';
    this.thread.appendChild(el('p', 'hdlv2-inbox-empty',
      'This message link has expired. No problem — reply directly to the email you received and it will reach your practitioner.'));
    if (this.form) this.form.setAttribute('hidden', 'hidden');
  };

  Inbox.prototype.setNote = function (msg) {
    if (this.note) this.note.textContent = msg;
  };

  function autoOpen(cfg, root) {
    if (!cfg.autoOpen) return;
    // Populated dashboard: activate the Messages tab (existing generic tab JS).
    var tab = document.querySelector('.cdp-tab[data-tab="messages"]');
    if (tab) { tab.click(); return; }
    // Empty-state card / standalone page: bring the thread into view.
    if (root.scrollIntoView) root.scrollIntoView({ block: 'start' });
  }

  function boot() {
    var cfg = readConfig();
    if (!cfg || !cfg.clientHash || !cfg.ajaxUrl) return;
    var root = document.querySelector('[data-inbox]');
    if (!root) return;
    var inbox = new Inbox(root, cfg);
    inbox.boot();
    autoOpen(cfg, root);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
