/**
 * GAP-2 (2026-07-13) — a rapid double-click on the resend button must open
 * EXACTLY ONE confirm dialog and rotate the token AT MOST ONCE.
 *
 * The bug: handleResendLink() opened the HDLV2UI.confirm dialog without locking
 * the button first, so two clicks fired before either dialog resolved → two
 * stacked dialogs → confirming both = two /resend-link POSTs = two token
 * rotations.
 *
 * This harness extracts the REAL handleResendLink / resendConfirmCopy /
 * doResendLink from the enhancer source (brace-matched, not copied) and drives
 * two synchronous invocations — exactly how the delegated click listener
 * dispatches two rapid clicks — with a CONTROLLED confirm promise that stays
 * pending across both clicks.
 *
 * Passes only when the button is locked the instant the first click is handled,
 * so the second click hits the `if (btn.disabled) return` guard.
 *
 * Run:  node tests/action-unify/scenario-gap2-double-dialog.mjs   (exit 0/1)
 */
import { readFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const here = dirname(fileURLToPath(import.meta.url));
const src = readFileSync(join(here, '../../assets/js/hdlv2-client-list-enhance.js'), 'utf8');

function extract(name) {
  const marker = 'function ' + name + '(';
  const idx = src.indexOf(marker);
  if (idx === -1) throw new Error('function ' + name + ' not found');
  const open = src.indexOf('{', idx);
  let depth = 0;
  for (let i = open; i < src.length; i++) {
    if (src[i] === '{') depth++;
    else if (src[i] === '}') { depth--; if (depth === 0) return src.slice(idx, i + 1); }
  }
  throw new Error('unbalanced braces extracting ' + name);
}

let pass = 0, fail = 0;
function ok(name, cond, detail) {
  console.log((cond ? 'PASS' : 'FAIL') + ' | ' + name + (cond ? '' : ' | ' + (detail || '')));
  cond ? pass++ : fail++;
}

// ── Controllable test doubles shared into the extracted functions' scope ──
const env = {
  confirmCalls: 0,
  fetchCalls: 0,
  _resolveConfirm: null,
};

// A button stub with a real settable `disabled` property + the attrs the
// handler reads. classList.contains → false; aria-disabled → null.
function makeButton(uid) {
  return {
    disabled: false,
    classList: { contains() { return false; } },
    getAttribute(k) { return k === 'data-client-id' ? String(uid) : null; },
  };
}

const state = {
  clients: [{ user_id: 77, name: 'Sam', email: 'sam@example.com',
    resend: { enabled: true, stage: 2, link_kind: 'assessment', label: 'Send Stage-2 link', tooltip: '' } }],
};

const windowStub = {
  HDLV2UI: {
    // Pending promise the test resolves manually — models a dialog the user
    // has not yet answered while a second click arrives.
    confirm() {
      env.confirmCalls++;
      return new Promise(function (resolve) { env._resolveConfirm = resolve; });
    },
    toast() {},
  },
};

const CFG = { api_base: 'https://stby.example/wp-json/hdl-v2/v1', nonce: 'n' };

function fetchStub() {
  env.fetchCalls++;
  return Promise.resolve({ ok: true, json() { return Promise.resolve({ success: true, stage_label: 'Send Stage-2 link', recipient_email: 'sam@example.com' }); } });
}

let handleResendLink;
try {
  const code = [extract('resendConfirmCopy'), extract('doResendLink'), extract('handleResendLink')].join('\n');
  const factory = new Function(
    'state', 'window', 'CFG', 'fetch', 'encodeURIComponent',
    code + '\nreturn handleResendLink;'
  );
  handleResendLink = factory(state, windowStub, CFG, fetchStub, encodeURIComponent);
} catch (e) {
  console.log('FAIL | extraction | ' + e.message);
  console.log('\n0 passed, 1 failed');
  process.exit(1);
}

// ── The rapid double-click ──
const btn = makeButton(77);
handleResendLink(btn);   // first click
handleResendLink(btn);   // second click, before the dialog is answered

ok('(G2a) two rapid clicks open EXACTLY ONE confirm dialog', env.confirmCalls === 1, 'confirmCalls=' + env.confirmCalls);
ok('(G2b) button is locked while the dialog is pending', btn.disabled === true, 'disabled=' + btn.disabled);

// Answer the single dialog → exactly one send/rotation.
env._resolveConfirm(true);
await new Promise((r) => setTimeout(r, 0)); // let the .then microtasks flush

ok('(G2c) confirming the single dialog fires AT MOST ONE resend', env.fetchCalls === 1, 'fetchCalls=' + env.fetchCalls);

// ── Cancel path re-enables the button (no permanent lock) ──
env.confirmCalls = 0; env.fetchCalls = 0;
const btn2 = makeButton(77);
handleResendLink(btn2);
ok('(G2d) button locks on open', btn2.disabled === true);
env._resolveConfirm(false);       // user cancels
await new Promise((r) => setTimeout(r, 0));
ok('(G2e) cancel re-enables the button (retry possible)', btn2.disabled === false, 'disabled=' + btn2.disabled);
ok('(G2f) cancel fires no resend', env.fetchCalls === 0, 'fetchCalls=' + env.fetchCalls);

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
