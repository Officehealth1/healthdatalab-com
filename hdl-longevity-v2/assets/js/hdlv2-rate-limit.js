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
        var labels = { ai_burn: 'AI calls', write: 'saves', read: 'page loads', public: 'leads' };
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
        fetch(REST_PREFIX + 'rate-limit/status', { credentials: 'same-origin' }).then(function (r) {
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

    window.hdlv2RateLimit = {
        idempotencyKey: uuid,
        ingestHeaders: ingestHeaders,
        showInline429: showInline429,
        v2Fetch: v2Fetch,
        loadStatus: loadStatus
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadStatus);
    } else {
        loadStatus();
    }
})();
