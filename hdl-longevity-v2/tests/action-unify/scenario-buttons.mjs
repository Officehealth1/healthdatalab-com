/**
 * P2 (action-button unify) — pure-renderer tests for the client-list enhancer.
 *
 * The enhancer is an IIFE, so the harness extracts the pure functions
 * (renderResendButton / renderMessageButton / renderProgressButton /
 * renderProfileButton / renderV2ActionButtons / resendConfirmCopy + the real
 * esc()) from the source by brace-matching and evaluates them with a minimal
 * document stub. No framework, no DOM — same standalone spirit as the PHP
 * scenario harnesses.
 *
 * Run:  node tests/action-unify/scenario-buttons.mjs   (exit 0/1)
 */
import { readFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const here = dirname(fileURLToPath(import.meta.url));
const src = readFileSync(join(here, '../../assets/js/hdlv2-client-list-enhance.js'), 'utf8');

function extract(name) {
  const marker = 'function ' + name + '(';
  const idx = src.indexOf(marker);
  if (idx === -1) throw new Error('function ' + name + ' not found in enhancer source');
  const open = src.indexOf('{', idx);
  let depth = 0;
  for (let i = open; i < src.length; i++) {
    const ch = src[i];
    if (ch === '{') depth++;
    else if (ch === '}') {
      depth--;
      if (depth === 0) return src.slice(idx, i + 1);
    }
  }
  throw new Error('unbalanced braces extracting ' + name);
}

// P3 — the icon fns now read from the HDLV2_ICONS map; extract it too.
function extractVar(name) {
  const marker = 'var ' + name + ' = {';
  const idx = src.indexOf(marker);
  if (idx === -1) throw new Error('var ' + name + ' not found in enhancer source');
  const open = src.indexOf('{', idx);
  let depth = 0;
  for (let i = open; i < src.length; i++) {
    const ch = src[i];
    if (ch === '{') depth++;
    else if (ch === '}') {
      depth--;
      if (depth === 0) return src.slice(idx, i + 1) + ';';
    }
  }
  throw new Error('unbalanced braces extracting var ' + name);
}

// Minimal document stub so the REAL esc() runs unmodified (it uses a div's
// textContent -> innerHTML round-trip; browsers escape & < > there).
const documentStub = {
  createElement() {
    return {
      _t: '',
      set textContent(v) { this._t = v == null ? '' : String(v); },
      get textContent() { return this._t; },
      get innerHTML() {
        return this._t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
      },
    };
  },
};

let fns;
let pass = 0, fail = 0;
function ok(name, cond, detail) {
  console.log((cond ? 'PASS' : 'FAIL') + ' | ' + name + (cond ? '' : ' | ' + (detail || '')));
  cond ? pass++ : fail++;
}

try {
  const code = [
    extract('esc'),
    extract('attrEsc'),
    extract('resendState'),
    extractVar('HDLV2_ICONS'),
    extract('iconSVG'),
    extract('sendIconSVG'),
    extract('messageIconSVG'),
    extract('chartIconSVG'),
    extract('personIconSVG'),
    extract('renderResendButton'),
    extract('renderMessageButton'),
    extract('renderProgressButton'),
    extract('renderProfileButton'),
    extract('renderV2ActionButtons'),
    extract('resendConfirmCopy'),
  ].join('\n');
  fns = new Function(
    'document',
    code + '\nreturn { renderResendButton, renderMessageButton, renderProgressButton, renderProfileButton, renderV2ActionButtons, resendConfirmCopy };'
  )(documentStub);
} catch (e) {
  console.log('FAIL | extraction | ' + e.message);
  console.log('\n0 passed, 1 failed');
  process.exit(1);
}

// ── fixtures: client objects as /dashboard/clients now returns them ──
function client(over) {
  return Object.assign({
    user_id: 77,
    progress_id: 9,
    name: 'Sam Client',
    user_login: 'u77',
    email: 'sam.client@example.com',
    email_hash: 'a'.repeat(64),
    status: 'low_data',
    resend: { enabled: true, stage: 2, link_kind: 'assessment', label: 'Send Stage-2 link', tooltip: '' },
  }, over || {});
}

// ── (1) resend button: enabled assessment stage ──
{
  const html = fns.renderResendButton(client());
  ok('(1a) enabled resend has the action classes + client id',
    html.includes('hdlv2-resend-link-btn') && html.includes('action-icon-btn') && html.includes('data-client-id="77"'), html);
  ok('(1b) enabled resend is NOT rendered disabled',
    !html.includes('hdlv2-btn-off') && !html.includes('aria-disabled'), html);
  ok('(1c) dynamic per-stage tooltip names the stage label',
    /title="[^"]*Send Stage-2 link[^"]*"/.test(html), html);
}

// ── (2) resend button: blocked-on-practitioner states render disabled ──
{
  const c = client({ status: 'awaiting_consult', resend: { enabled: false, stage: null, link_kind: '', label: '', tooltip: 'Waiting on your consultation — nothing to send' } });
  const html = fns.renderResendButton(c);
  ok('(2a) awaiting_consult renders disabled', html.includes('hdlv2-btn-off') && html.includes('aria-disabled="true"'), html);
  ok('(2b) disabled tooltip is the descriptor reason',
    html.includes('Waiting on your consultation — nothing to send'), html);

  const c2 = client({ status: 'awaiting_why_release', resend: { enabled: false, stage: null, link_kind: '', label: '', tooltip: 'Waiting on you to release Stage 3 — nothing to send' } });
  const html2 = fns.renderResendButton(c2);
  ok('(2c) awaiting_why_release renders disabled with its reason',
    html2.includes('hdlv2-btn-off') && html2.includes('Waiting on you to release Stage 3'), html2);
}

// ── (3) resend button: no email on file -> disabled even if descriptor enables ──
{
  const html = fns.renderResendButton(client({ email: '' }));
  ok('(3) no email -> disabled with a "no email" reason',
    html.includes('hdlv2-btn-off') && /no email/i.test(html), html);
}

// ── (4) chat button: fed from the V2 payload ──
{
  const html = fns.renderMessageButton(client());
  ok('(4a) chat reuses V1 delegated handler AND carries the V2 marker',
    html.includes('message-btn') && html.includes('hdlv2-message-btn'), html);
  ok('(4b) chat carries email_hash + client_email from the V2 payload',
    html.includes('data-client-hash="' + 'a'.repeat(64) + '"') && html.includes('data-client-email="sam.client@example.com"'), html);

  const off = fns.renderMessageButton(client({ email: '' }));
  ok('(4c) chat with no email must NOT trigger V1 handler and renders disabled',
    !/class="[^"]*\bmessage-btn\b/.test(off.replace(/hdlv2-message-btn/g, '')) && off.includes('hdlv2-btn-off'), off);
}

// ── (5) bar-chart: opens the chevron panel (Progress tab), not /client-tracker ──
{
  const html = fns.renderProgressButton(client());
  ok('(5a) progress button class, not a tracker link',
    html.includes('hdlv2-progress-btn') && !html.includes('client-tracker') && !html.includes('<a '), html);
  ok('(5b) progress tooltip', /title="[^"]*[Pp]rogress[^"]*"/.test(html), html);
}

// ── (6) person: /user/{login}/ via V1 delegated details handler ──
{
  const html = fns.renderProfileButton(client());
  ok('(6a) person reuses V1 details-btn with data-client-login',
    html.includes('details-btn') && html.includes('data-client-login="u77"'), html);
  const off = fns.renderProfileButton(client({ user_login: '' }));
  ok('(6b) no WP login -> disabled, no data-client-login',
    off.includes('hdlv2-btn-off') && !off.includes('data-client-login='), off);
}

// ── (7) the full set: order, completeness, and NO cancel-invite ──
{
  const html = fns.renderV2ActionButtons(client());
  const order = ['hdlv2-resend-link-btn', 'hdlv2-message-btn', 'hdlv2-progress-btn', 'details-btn'];
  let last = -1, ordered = true;
  for (const cls of order) {
    const i = html.indexOf(cls);
    if (i === -1 || i < last) { ordered = false; break; }
    last = i;
  }
  ok('(7a) full set renders in V1 order: send, chat, chart, person', ordered, html);
  ok('(7b) NO cancel-invite affordance on V2 rows', !html.includes('cancel-invite'), html);
}

// ── (8) confirm copy: names STAGE + RECIPIENT + replace-warning ──
{
  const copy = fns.resendConfirmCopy(client());
  ok('(8a) title names the stage action', copy.title.includes('Send Stage-2 link'), JSON.stringify(copy));
  ok('(8b) body names the recipient (name + email)',
    copy.body.includes('Sam Client') && copy.body.includes('sam.client@example.com'), copy.body);
  ok('(8c) body warns the send REPLACES any previous link', /replaces/i.test(copy.body), copy.body);
  ok('(8d) confirm button repeats the stage action', copy.confirmLabel === 'Send Stage-2 link', copy.confirmLabel);

  const plan = fns.resendConfirmCopy(client({
    status: 'active',
    resend: { enabled: true, stage: null, link_kind: 'plan', label: 'Resend flight plan', tooltip: '' },
  }));
  ok('(8e) COMPLETE copy names the actual artefact (flight plan)',
    plan.title.includes('Resend flight plan') && /flight plan/i.test(plan.body), JSON.stringify(plan));
}

// ── (9) escaping: hostile display name cannot break out of the markup ──
{
  const c = client({ name: '<img src=x onerror=alert(1)>' });
  const html = fns.renderV2ActionButtons(c);
  ok('(9) client name is escaped in rendered HTML', !html.includes('<img'), html);
}

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
