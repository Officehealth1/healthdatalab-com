/**
 * HDL V2 — Rate Limit awareness for the frontend.
 *
 * 1. Generates a per-attempt UUID and exposes window.hdlv2RateLimit.idempotencyKey()
 *    so existing fetch calls can include `Idempotency-Key` for AI-burn endpoints.
 * 2. Reads X-RateLimit-* headers from every V2 response and updates a discreet
 *    pill in the corner when any tier drops below 25% remaining.
 * 3. Surfaces 429 responses as a non-blocking inline message near the offending
 *    button (or a toast if no button is identifiable).
 *
 * No build step. ES5-safe (the rest of V2 supports older browsers via Divi).
 */
(function () {
    if (window.hdlv2RateLimit) return;

    var REST_PREFIX = '/wp-json/hdl-v2/v1/';
    var statusCache = {};

    function uuid() {
        if (window.crypto && window.crypto.getRandomValues) {
            var b = new Uint8Array(16);
            window.crypto.getRandomValues(b);
            b[6] = (b[6] & 0x0f) | 0x40;
            b[8] = (b[8] & 0x3f) | 0x80;
            var h = Array.prototype.map.call(b, function (x) {
                return (x + 0x100).toString(16).slice(1);
            }).join('');
            return h.slice(0,8) + '-' + h.slice(8,12) + '-' + h.slice(12,16) + '-' + h.slice(16,20) + '-' + h.slice(20);
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

    function ensurePill() {
        var pill = document.getElementById('hdlv2-rl-pill');
        if (pill) return pill;
        pill = document.createElement('div');
        pill.id = 'hdlv2-rl-pill';
        pill.style.cssText = [
            'position:fixed', 'bottom:16px', 'right:16px',
            'background:rgba(61,141,160,0.95)', 'color:#fff',
            'padding:6px 12px', 'border-radius:14px',
            'font:500 12px/1.3 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
            'box-shadow:0 2px 8px rgba(0,0,0,0.15)',
            'z-index:99999', 'display:none', 'pointer-events:none'
        ].join(';');
        document.body.appendChild(pill);
        return pill;
    }

    function updatePill() {
        var pill = ensurePill();
        var lowest = null;
        var labels = { ai_burn: 'AI calls', write: 'saves', read: 'page loads', prac_read: 'page loads', public: 'leads' };
        for (var k in statusCache) {
            if (!statusCache.hasOwnProperty(k)) continue;
            var s = statusCache[k];
            if (!s || !s.limit) continue;
            var pct = s.remaining / s.limit;
            if (pct < 0.25 && (!lowest || s.remaining < lowest.remaining)) {
                lowest = { tier: k, remaining: s.remaining, limit: s.limit };
            }
        }
        if (lowest) {
            pill.textContent = lowest.remaining + ' ' + (labels[lowest.tier] || lowest.tier) + ' left this hour';
            pill.style.display = 'block';
        } else {
            pill.style.display = 'none';
        }
    }

    function showInline429(targetEl, message) {
        var host = targetEl && targetEl.parentNode ? targetEl.parentNode : document.body;
        var note = document.createElement('div');
        note.className = 'hdlv2-rl-inline';
        note.textContent = message;
        note.style.cssText = [
            'background:#fff4e5', 'color:#8a4500',
            'border:1px solid #f5d199', 'border-radius:6px',
            'padding:8px 12px', 'margin:8px 0',
            'font:500 13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif'
        ].join(';');
        host.appendChild(note);
        setTimeout(function () { if (note.parentNode) note.parentNode.removeChild(note); }, 8000);
    }

    function ingestHeaders(response, tier) {
        try {
            var lim = parseInt(response.headers.get('X-RateLimit-Limit') || '0', 10);
            var rem = parseInt(response.headers.get('X-RateLimit-Remaining') || '0', 10);
            var rst = parseInt(response.headers.get('X-RateLimit-Reset') || '0', 10);
            var t   = response.headers.get('X-RateLimit-Tier') || tier || 'unknown';
            if (lim > 0) {
                statusCache[t.replace(/-/g, '_')] = { limit: lim, remaining: rem, reset: rst };
                updatePill();
            }
        } catch (e) { /* never break the page */ }
    }

    /**
     * Fetch wrapper for V2 endpoints — adds Idempotency-Key on AI-burn POSTs,
     * surfaces 429 inline near a target element if provided.
     */
    function v2Fetch(url, opts, options) {
        opts = opts || {};
        opts.headers = opts.headers || {};
        var isAiBurn = options && options.idempotent;
        if (isAiBurn && !opts.headers['Idempotency-Key']) {
            opts.headers['Idempotency-Key'] = uuid();
        }
        return fetch(url, opts).then(function (resp) {
            ingestHeaders(resp, options && options.tier);
            if (resp.status === 429) {
                resp.clone().json().then(function (body) {
                    var msg = (body && body.message) || 'Too many requests — please try again shortly.';
                    showInline429(options && options.targetEl, msg);
                }).catch(function () {
                    showInline429(options && options.targetEl, 'Too many requests — please try again shortly.');
                });
            }
            return resp;
        });
    }

    function loadStatus() {
        // 2026-07-14 RL fix — send the REST nonce (localized as
        // hdlv2RateLimitCfg) so this probe is counted against the logged-in
        // user's bucket instead of an anonymous per-IP bucket, and reports
        // the bucket the user actually consumes.
        var headers = {};
        var cfg = window.hdlv2RateLimitCfg;
        if (cfg && cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;
        fetch(REST_PREFIX + 'rate-limit/status', { credentials: 'same-origin', headers: headers }).then(function (r) {
            ingestHeaders(r);
            return r.ok ? r.json() : null;
        }).then(function (json) {
            if (!json) return;
            for (var k in json) {
                if (json.hasOwnProperty(k)) statusCache[k] = json[k];
            }
            updatePill();
        }).catch(function () { /* ignore */ });
    }

    // ────────────────────────────────────────────────────────────────────────────
    // v0.20.0 — Themed 429 modal + global fetch interceptor.
    //
    // Rationale: the boss hit a rate limit mid-presentation with no UI signal
    // because component code (e.g. hdlv2-practitioner-dashboard.js) uses raw
    // fetch() instead of v2Fetch(), so the inline 429 handler never fired.
    // A global fetch wrapper catches every 429 on /hdl-v2/v1/ URLs and surfaces
    // a calm, centered modal matching the V2 design system.
    //
    // Styling tokens from V2: Inter/Poppins, #004F59 dark teal, #3d8da0 teal,
    // 14px / 8px radii, white card on 17,17,17/45% overlay.
    // ────────────────────────────────────────────────────────────────────────────

    var MODAL_MIN_INTERVAL_MS = 5000;   // never re-open within 5s (debounce)
    var MODAL_MAX_COUNTDOWN   = 600;    // clamp to 10 min so UI doesn't hang
    var lastModalAt           = 0;
    var countdownTimer        = null;

    // ────────────────────────────────────────────────────────────────────────
    // 2026-07-14 RL fix — shared Retry-After cooldown.
    //
    // Every V2 429 arms a cooldown for its Retry-After window. Pollers
    // (client-list enhancer, practitioner dashboard, nav bar) consult
    // isCoolingDown() and stop firing until it elapses — a poll sent into an
    // active block is a wasted counted request (LIVE 2026-07-13: 114 of them).
    // ────────────────────────────────────────────────────────────────────────
    var cooldownUntil = 0;

    function setCooldown(seconds) {
        seconds = parseInt(seconds, 10);
        if (!isFinite(seconds) || seconds <= 0) return;
        var until = Date.now() + Math.min(MODAL_MAX_COUNTDOWN, seconds) * 1000;
        if (until > cooldownUntil) cooldownUntil = until;
    }

    function isCoolingDown() {
        return Date.now() < cooldownUntil;
    }

    // True when the request that got the 429 was marked as background
    // traffic (poller) via the X-HDLV2-Bg header. Background 429s update
    // the pill + cooldown but never open the modal — a poll is not a user
    // action.
    function isBackgroundInit(init) {
        try {
            var h = init && init.headers;
            if (!h) return false;
            if (typeof h.get === 'function') return h.get('X-HDLV2-Bg') === '1';
            return h['X-HDLV2-Bg'] === '1' || h['x-hdlv2-bg'] === '1';
        } catch (e) { return false; }
    }

    function injectModalCss() {
        if (document.getElementById('hdlv2-rl-modal-css')) return;
        var s = document.createElement('style');
        s.id = 'hdlv2-rl-modal-css';
        s.textContent = [
            '@keyframes hdlv2-rl-fade{from{opacity:0}to{opacity:1}}',
            '@keyframes hdlv2-rl-pop{from{opacity:0;transform:translateY(6px) scale(.98)}to{opacity:1;transform:none}}',
            '.hdlv2-rl-overlay{position:fixed;inset:0;background:rgba(17,17,17,0.45);z-index:100000;display:flex;align-items:center;justify-content:center;padding:16px;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;animation:hdlv2-rl-fade .2s ease-out}',
            '.hdlv2-rl-card{background:#fff;border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,0.18);max-width:440px;width:100%;padding:32px 28px 24px;position:relative;text-align:center;animation:hdlv2-rl-pop .22s ease-out}',
            '.hdlv2-rl-icon{width:64px;height:64px;border-radius:50%;background:#e8f2f4;color:#3d8da0;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:30px;line-height:1}',
            '.hdlv2-rl-title{font-family:Poppins,Inter,sans-serif;font-size:20px;font-weight:600;color:#004F59;margin:0 0 8px;letter-spacing:.01em}',
            '.hdlv2-rl-body{font-size:14px;line-height:1.5;color:#555;margin:0 0 20px}',
            '.hdlv2-rl-countdown{font-weight:600;color:#004F59;font-variant-numeric:tabular-nums}',
            '.hdlv2-rl-btn{background:#3d8da0;color:#fff;border:none;border-radius:8px;padding:10px 24px;font:500 14px/1 Inter,-apple-system,BlinkMacSystemFont,sans-serif;cursor:pointer;transition:background .15s ease;min-width:120px}',
            '.hdlv2-rl-btn:hover{background:#2f7586}',
            '.hdlv2-rl-x{position:absolute;top:10px;right:12px;background:transparent;border:none;font-size:22px;line-height:1;color:#999;cursor:pointer;padding:4px 10px;border-radius:6px}',
            '.hdlv2-rl-x:hover{color:#555;background:#f5f5f5}'
        ].join('\n');
        document.head.appendChild(s);
    }

    function clampCountdown(n) {
        n = parseInt(n, 10);
        if (!isFinite(n) || n <= 0) return 60;
        return Math.min(MODAL_MAX_COUNTDOWN, n);
    }

    function resolveRetrySeconds(body, headers) {
        if (body && body.retry_after) {
            var n = parseInt(body.retry_after, 10);
            if (isFinite(n) && n > 0) return clampCountdown(n);
        }
        if (headers) {
            var h = parseInt(headers.get('Retry-After') || '0', 10);
            if (isFinite(h) && h > 0) return clampCountdown(h);
            // Fall back to X-RateLimit-Reset - now
            var reset = parseInt(headers.get('X-RateLimit-Reset') || '0', 10);
            if (isFinite(reset) && reset > 0) {
                var delta = reset - Math.floor(Date.now() / 1000);
                if (delta > 0) return clampCountdown(delta);
            }
        }
        return 60;
    }

    function showRateLimitModal(body, headers) {
        var now = Date.now();
        if (now - lastModalAt < MODAL_MIN_INTERVAL_MS) return;
        if (document.getElementById('hdlv2-rl-overlay')) return;
        lastModalAt = now;

        injectModalCss();
        var seconds = resolveRetrySeconds(body, headers);

        var overlay = document.createElement('div');
        overlay.id = 'hdlv2-rl-overlay';
        overlay.className = 'hdlv2-rl-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'hdlv2-rl-title');

        var card = document.createElement('div');
        card.className = 'hdlv2-rl-card';

        var xBtn = document.createElement('button');
        xBtn.className = 'hdlv2-rl-x';
        xBtn.setAttribute('aria-label', 'Close');
        xBtn.innerHTML = '&times;';

        var icon = document.createElement('div');
        icon.className = 'hdlv2-rl-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = '&#9203;'; // hourglass-not-done ⏳

        var title = document.createElement('h3');
        title.id = 'hdlv2-rl-title';
        title.className = 'hdlv2-rl-title';
        title.textContent = 'Give us a moment';

        var para = document.createElement('p');
        para.className = 'hdlv2-rl-body';
        para.appendChild(document.createTextNode('You’ve made a lot of requests very quickly. We paused briefly to keep things smooth — please try again in '));
        var num = document.createElement('span');
        num.id = 'hdlv2-rl-countdown';
        num.className = 'hdlv2-rl-countdown';
        num.textContent = seconds;
        para.appendChild(num);
        para.appendChild(document.createTextNode(' second'));
        var plural = document.createElement('span');
        plural.id = 'hdlv2-rl-plural';
        plural.textContent = seconds === 1 ? '' : 's';
        para.appendChild(plural);
        para.appendChild(document.createTextNode('.'));

        var btn = document.createElement('button');
        btn.id = 'hdlv2-rl-dismiss';
        btn.className = 'hdlv2-rl-btn';
        btn.textContent = 'Got it';

        function close() {
            stopCountdown();
            if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
            document.removeEventListener('keydown', onKey);
        }
        function onKey(e) { if (e.key === 'Escape') close(); }

        xBtn.addEventListener('click', close);
        btn.addEventListener('click', close);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
        document.addEventListener('keydown', onKey);

        card.appendChild(xBtn);
        card.appendChild(icon);
        card.appendChild(title);
        card.appendChild(para);
        card.appendChild(btn);
        overlay.appendChild(card);
        document.body.appendChild(overlay);

        startCountdown(seconds);

        // Focus the dismiss button for keyboard users.
        try { btn.focus(); } catch (e) { /* ignore */ }
    }

    function startCountdown(seconds) {
        stopCountdown();
        var remaining = seconds;
        countdownTimer = setInterval(function () {
            remaining--;
            var el = document.getElementById('hdlv2-rl-countdown');
            var plural = document.getElementById('hdlv2-rl-plural');
            var btn = document.getElementById('hdlv2-rl-dismiss');
            if (el) el.textContent = Math.max(0, remaining);
            if (plural) plural.textContent = remaining === 1 ? '' : 's';
            if (remaining <= 0) {
                stopCountdown();
                if (btn) btn.textContent = 'Try again';
            }
        }, 1000);
    }

    function stopCountdown() {
        if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
    }

    // Global fetch wrapper — intercepts every fetch() to /hdl-v2/v1/ so the modal
    // fires for callers that don't use v2Fetch (e.g. the practitioner dashboard).
    // Runs once; marked via window flag so the wrap survives script re-enqueue.
    (function wrapFetch() {
        if (!window.fetch || window.__hdlv2FetchWrapped) return;
        window.__hdlv2FetchWrapped = true;

        var origFetch = window.fetch;
        window.fetch = function () {
            var input = arguments[0];
            var url = typeof input === 'string' ? input : (input && input.url) || '';
            var isV2 = url.indexOf('/hdl-v2/v1/') !== -1;
            var isBg = isV2 && isBackgroundInit(arguments[1]);

            var call = origFetch.apply(this, arguments);
            if (!isV2) return call;

            return call.then(function (resp) {
                try {
                    ingestHeaders(resp);
                    if (resp.status === 429) {
                        // Arm the shared cooldown immediately from headers so
                        // pollers stop before the body even parses.
                        setCooldown(resolveRetrySeconds(null, resp.headers));
                        resp.clone().json().then(function (body) {
                            setCooldown(resolveRetrySeconds(body, resp.headers));
                            // 2026-07-14 RL fix — background polls never open
                            // the modal; the pill (ingestHeaders above) is the
                            // only signal. Modal is for user-initiated actions.
                            if (!isBg) showRateLimitModal(body, resp.headers);
                        }).catch(function () {
                            if (!isBg) showRateLimitModal(null, resp.headers);
                        });
                    }
                } catch (e) { /* never break the page */ }
                return resp;
            });
        };
    })();

    window.hdlv2RateLimit = {
        idempotencyKey: uuid,
        ingestHeaders: ingestHeaders,
        showInline429: showInline429,
        showRateLimitModal: showRateLimitModal,
        v2Fetch: v2Fetch,
        loadStatus: loadStatus,
        setCooldown: setCooldown,
        isCoolingDown: isCoolingDown
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadStatus);
    } else {
        loadStatus();
    }
})();
