/**
 * P3 (animated icon unify) — single-source icon map + overlay tests.
 *
 * Same standalone spirit as scenario-buttons.mjs: brace-extract the pure
 * pieces (HDLV2_ICONS map, iconSVG, iconNameForButton, the P2 icon fns,
 * renderV2DeleteButton) and assert on them + on source-level contracts the
 * DOM/CSS side must honour (decorate pass swaps ONLY the inner <svg>,
 * disabled classes suppress motion, one prefers-reduced-motion block covers
 * all 8 icons — 5 new + V2 trash + the 2 existing download ports).
 *
 * Run:  node tests/action-unify/scenario-icons.mjs   (exit 0/1)
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

// Extracts `var NAME = { ... };` object literals by brace-matching.
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

let pass = 0, fail = 0;
function ok(name, cond, detail) {
  console.log((cond ? 'PASS' : 'FAIL') + ' | ' + name + (cond ? '' : ' | ' + (detail || '')));
  cond ? pass++ : fail++;
}

let fns;
try {
  const code = [
    extract('esc'),
    extractVar('HDLV2_ICONS'),
    extract('iconSVG'),
    extract('iconNameForButton'),
    extract('sendIconSVG'),
    extract('messageIconSVG'),
    extract('chartIconSVG'),
    extract('personIconSVG'),
    extract('renderV2DeleteButton'),
  ].join('\n');
  fns = new Function(
    'document',
    code + '\nreturn { HDLV2_ICONS: HDLV2_ICONS, iconSVG, iconNameForButton, sendIconSVG, messageIconSVG, chartIconSVG, personIconSVG, renderV2DeleteButton };'
  )(documentStub);
} catch (e) {
  console.log('FAIL | extraction | ' + e.message);
  console.log('\n0 passed, 1 failed');
  process.exit(1);
}

// ── (1) map completeness: 6 entries, marker class + Feather grid on each ──
{
  const names = ['send', 'message', 'chart', 'person', 'trash', 'trash-v2'];
  ok('(1a) map has exactly the 6 expected entries',
    names.every(function (n) { return typeof fns.HDLV2_ICONS[n] === 'string' && fns.HDLV2_ICONS[n].length > 0; })
      && Object.keys(fns.HDLV2_ICONS).length === 6,
    Object.keys(fns.HDLV2_ICONS).join(','));
  ok('(1b) every entry is a 24x24 svg carrying the idempotency marker class',
    names.every(function (n) {
      const s = fns.HDLV2_ICONS[n];
      return s.indexOf('hdlv2-anim-ico') !== -1 && s.indexOf('viewBox="0 0 24 24"') !== -1;
    }));
  ok('(1c) iconSVG(name) returns the entry; unknown name returns empty string',
    fns.iconSVG('send') === fns.HDLV2_ICONS['send'] && fns.iconSVG('nope') === '');
}

// ── (2) classed sub-paths present (the CSS animation hooks) ──
{
  ok('(2a) send has the .send-plane group', fns.HDLV2_ICONS['send'].indexOf('send-plane') !== -1, fns.HDLV2_ICONS['send']);
  ok('(2b) message has the .msg-bubble path', fns.HDLV2_ICONS['message'].indexOf('msg-bubble') !== -1, fns.HDLV2_ICONS['message']);
  ok('(2c) chart has bar-1/bar-2/bar-3',
    ['bar-1', 'bar-2', 'bar-3'].every(function (c) { return fns.HDLV2_ICONS['chart'].indexOf(c) !== -1; }), fns.HDLV2_ICONS['chart']);
  ok('(2d) person has the .person-avatar group', fns.HDLV2_ICONS['person'].indexOf('person-avatar') !== -1, fns.HDLV2_ICONS['person']);
  ok('(2e) BOTH trash entries carry trash-lid-lower + trash-lid-upper',
    ['trash', 'trash-v2'].every(function (n) {
      const s = fns.HDLV2_ICONS[n];
      return s.indexOf('trash-lid-lower') !== -1 && s.indexOf('trash-lid-upper') !== -1;
    }));
}

// ── (3) V1 geometry preserved (the user likes the current shapes) ──
{
  ok('(3a) send keeps V1 paper-plane geometry', fns.HDLV2_ICONS['send'].indexOf('M22 2L11 13') !== -1);
  ok('(3b) message keeps V1 bubble geometry', fns.HDLV2_ICONS['message'].indexOf('M21 15a2 2 0 0 1-2 2H7l-4 4V5') !== -1);
  ok('(3c) chart keeps V1 3-rect geometry',
    fns.HDLV2_ICONS['chart'].indexOf('x="3" y="12"') !== -1 && fns.HDLV2_ICONS['chart'].indexOf('x="17" y="3"') !== -1);
  ok('(3d) person keeps V1 head+torso geometry',
    fns.HDLV2_ICONS['person'].indexOf('cx="12" cy="7" r="4"') !== -1);
  ok('(3e) V1 trash keeps Feather geometry (crossbar polyline + inner lines)',
    fns.HDLV2_ICONS['trash'].indexOf('points="3 6 5 6 21 6"') !== -1
      && fns.HDLV2_ICONS['trash'].indexOf('x1="10" y1="11"') !== -1);
  ok('(3f) V2 trash keeps the itshover geometry already shipped',
    fns.HDLV2_ICONS['trash-v2'].indexOf('M4 7l16 0') !== -1
      && fns.HDLV2_ICONS['trash-v2'].indexOf('M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3') !== -1);
}

// ── (4) single source of truth: the P2 builders consume the map ──
{
  ok('(4a) sendIconSVG() === iconSVG("send")', fns.sendIconSVG() === fns.iconSVG('send'));
  ok('(4b) messageIconSVG() === iconSVG("message")', fns.messageIconSVG() === fns.iconSVG('message'));
  ok('(4c) chartIconSVG() === iconSVG("chart")', fns.chartIconSVG() === fns.iconSVG('chart'));
  ok('(4d) personIconSVG() === iconSVG("person")', fns.personIconSVG() === fns.iconSVG('person'));
  const btn = fns.renderV2DeleteButton({ user_id: 7, name: 'X', email_hash: 'h' });
  ok('(4e) renderV2DeleteButton embeds iconSVG("trash-v2")', btn.indexOf(fns.iconSVG('trash-v2')) !== -1, btn);
}

// ── (5) button-class -> icon-name mapping for the PHP-rendered rows ──
{
  const m = fns.iconNameForButton;
  ok('(5a) send-form-btn -> send', m('action-icon-btn send-form-btn') === 'send');
  ok('(5b) message-btn -> message', m('action-icon-btn message-btn btn-disabled') === 'message');
  ok('(5c) view-tracker-btn -> chart', m('action-icon-btn view-tracker-btn') === 'chart');
  ok('(5d) details-btn -> person', m('action-icon-btn details-btn') === 'person');
  ok('(5e) delete-client-btn -> trash (V1 red)', m('action-icon-btn delete-client-btn') === 'trash');
  ok('(5f) cancel-invite-btn -> trash (V1 red)', m('action-icon-btn cancel-invite-btn') === 'trash');
  ok('(5g) unknown/plain button -> "" (never swapped)',
    m('action-icon-btn') === '' && m('action-button') === '' && m('') === '');
}

// ── (6) decorate pass contract: swap ONLY the inner <svg>, idempotent ──
{
  let deco = '';
  try { deco = extract('decorateActionIcons'); } catch (e) { /* leave empty */ }
  ok('(6a) decorateActionIcons exists', deco.length > 0);
  ok('(6b) it targets .action-icon-btn', deco.indexOf('.action-icon-btn') !== -1, deco);
  ok('(6c) idempotency: skips buttons whose svg already carries the marker',
    deco.indexOf('hdlv2-anim-ico') !== -1, deco);
  ok('(6d) swaps the svg element in place (outerHTML), so siblings like .msg-unread-badge survive',
    deco.indexOf('outerHTML') !== -1 && /querySelector\(\s*['"]svg/.test(deco), deco);
  ok('(6e) never assigns button.innerHTML (would destroy the unread badge)',
    deco.indexOf('.innerHTML') === -1, deco);
}

// ── (7) one CSS motion block: shared timing/easing, hover-scoped, no loops ──
{
  ok('(7a) shared easing token used for the unified set',
    src.indexOf('cubic-bezier(0.16, 1, 0.3, 1)') !== -1);
  const hovers = [
    '.action-icon-btn:hover svg .send-plane',
    '.action-icon-btn:hover svg .msg-bubble',
    '.action-icon-btn:hover svg .bar-1',
    '.action-icon-btn:hover svg .person-avatar',
    '.action-icon-btn:hover svg .trash-lid-lower',
  ];
  ok('(7b) all five icons animate on button hover',
    hovers.every(function (sel) { return src.indexOf(sel) !== -1; }),
    hovers.filter(function (sel) { return src.indexOf(sel) === -1; }).join(' & '));
  const loopy = /(send-plane|msg-bubble|bar-[123]|person-avatar)[^}\n]*infinite/.test(src);
  ok('(7c) no infinite loops on the five unified icons', !loopy);
}

// ── (8) disabled states suppress ALL sub-path motion ──
{
  ok('(8a) .btn-disabled suppression rule present',
    src.indexOf('.action-icon-btn.btn-disabled svg *') !== -1);
  ok('(8b) .hdlv2-btn-off suppression rule present',
    src.indexOf('.action-icon-btn.hdlv2-btn-off svg *') !== -1);
  const supIdx = src.indexOf('.action-icon-btn.btn-disabled svg *');
  const supRule = supIdx === -1 ? '' : src.slice(supIdx, src.indexOf('}', supIdx));
  ok('(8c) suppression kills transition + transform + animation',
    supRule.indexOf('transition:none') !== -1 && supRule.indexOf('transform:none') !== -1 && supRule.indexOf('animation:none') !== -1,
    supRule);
}

// ── (9) ONE prefers-reduced-motion block covers all 8 icons (new + old) ──
{
  const mIdx = src.indexOf('@media (prefers-reduced-motion: reduce)');
  ok('(9a) prefers-reduced-motion block exists', mIdx !== -1);
  // Everything between the media query and the next media/end-of-array chunk.
  const block = mIdx === -1 ? '' : src.slice(mIdx, mIdx + 2200);
  const covered = [
    'send-plane', 'msg-bubble', 'bar-1', 'person-avatar',   // 4 new
    'trash-lid-lower',                                       // both trash ports
    'fn-ico-stem',                                           // existing fn download port
    'hdlv2-iho-dl-arrow',                                    // existing iho download port
    'is-shaking',                                            // JS-toggled shake keyframes
  ];
  ok('(9b) block covers the 5 new icons + V2 trash + both existing download ports + is-shaking',
    covered.every(function (t) { return block.indexOf(t) !== -1; }),
    covered.filter(function (t) { return block.indexOf(t) === -1; }).join(' & '));
  ok('(9c) block silences motion with !important',
    block.indexOf('transition:none !important') !== -1
      && block.indexOf('transform:none !important') !== -1
      && block.indexOf('animation:none !important') !== -1, block.slice(0, 400));
}

// ── (10) standardised Apache-2.0 attribution per icon ──
{
  const names = ['send-icon', 'message-circle-icon', 'chart-bar-icon', 'user-icon', 'trash-icon', 'download-icon'];
  const missing = names.filter(function (n) {
    return src.indexOf('itshover.com/icons/' + n + ' (github.com/itshover/itshover, Apache-2.0)') === -1;
  });
  ok('(10) every ported icon carries "ported from itshover.com/icons/<name> (github.com/itshover/itshover, Apache-2.0)"',
    missing.length === 0, 'missing: ' + missing.join(', '));
}

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
