/**
 * Phase 2.1 — suppress the V1 status badge on MATCHED rows.
 *
 * A "matched" row is a V1 server-rendered tr.client-row that also resolves to a
 * live V2 record. Before 2.1 its .status-badge-cell carried BOTH V1's legacy
 * .status-badge (typically a stale "NOT STARTED" — V2-onboarded clients never
 * write to V1's submissions table) AND the V2 pill: two contradictory statuses
 * in one cell. Where a V2 status exists it must be the ONLY status shown.
 *
 * Harness spirit matches scenario-buttons.mjs / scenario-icons.mjs: extract the
 * REAL function from the enhancer source by brace-matching and drive it against
 * a minimal hand-rolled DOM. No framework, no jsdom.
 *
 * Run:  node tests/action-unify/scenario-v1-badge-suppress.mjs   (exit 0/1)
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

// ── minimal DOM ──────────────────────────────────────────────────────────
function El(classNames, tag) {
  const classes = new Set((classNames || '').split(/\s+/).filter(Boolean));
  const attrs = {};
  const node = {
    tagName: (tag || 'span').toUpperCase(),
    children: [],
    dataset: {},
    classList: {
      add: (c) => classes.add(c),
      remove: (c) => classes.delete(c),
      contains: (c) => classes.has(c),
    },
    get className() { return [...classes].join(' '); },
    setAttribute: (k, v) => { attrs[k] = String(v); },
    getAttribute: (k) => (k in attrs ? attrs[k] : null),
    append(child) { node.children.push(child); return child; },
    // supports single-class selectors ('.foo'), which is all the SUT uses
    querySelector(sel) {
      const want = sel.replace(/^\./, '');
      const walk = (n) => {
        for (const ch of n.children) {
          if (ch.classList.contains(want)) return ch;
          const deep = walk(ch);
          if (deep) return deep;
        }
        return null;
      };
      return walk(node);
    },
  };
  return node;
}

// A matched row: V1 badge + V2 pill (+ mini-stages) in .status-badge-cell,
// and an Assessment cell that ALSO contains a .status-badge (must be untouched).
function matchedRow(v1Total) {
  const row = El('client-row', 'tr');
  const statusCell = El('status-badge-cell', 'td');
  const v1Badge = El('status-badge neutral');
  v1Badge.setAttribute('title', 'V1 legacy status');
  statusCell.append(v1Badge);
  statusCell.append(El('hdlv2-inline-badge'));
  statusCell.append(El('hdlv2-mini-stages'));
  const assessCell = El('assessment-cell', 'td');
  const assessBadge = El('status-badge info'); // renderV2Assessment output
  assessCell.append(assessBadge);
  row.append(statusCell);
  row.append(assessCell);
  row.dataset.total = String(v1Total === undefined ? 0 : v1Total);
  return { row, statusCell, v1Badge, assessBadge };
}

// A V2-only row: cell built by buildV2OnlyRow — V2 pill ONLY, no V1 badge.
function v2OnlyRow() {
  const row = El('client-row', 'tr');
  const statusCell = El('status-badge-cell', 'td');
  statusCell.append(El('hdlv2-inline-badge'));
  row.append(statusCell);
  row.dataset.total = '0';
  return { row, statusCell };
}

// ── harness ──────────────────────────────────────────────────────────────
let pass = 0, fail = 0;
function check(name, cond, detail) {
  const ok = !!cond;
  console.log((ok ? 'PASS' : 'FAIL') + ' | ' + name + (detail ? ' | ' + detail : ''));
  ok ? pass++ : fail++;
}

const suppressV1StatusBadge = new Function(
  extract('suppressV1StatusBadge') + '; return suppressV1StatusBadge;'
)();

const HIDDEN = 'hdlv2-v1-badge-suppressed';

// (1) matched row → V1 badge suppressed, V2 pill survives
{
  const { row, statusCell, v1Badge } = matchedRow(0);
  suppressV1StatusBadge(statusCell, row);
  check('(1) matched: V1 .status-badge gets the suppressed class',
    v1Badge.classList.contains(HIDDEN), 'class=' + v1Badge.className);
  check('(2) matched: V2 pill still present (never removed)',
    !!statusCell.querySelector('.hdlv2-inline-badge'));
  check('(3) matched: mini-stages strip untouched',
    !!statusCell.querySelector('.hdlv2-mini-stages'));
}

// (4) scoping: the Assessment column's .status-badge must NOT be suppressed
{
  const { row, statusCell, assessBadge } = matchedRow(0);
  suppressV1StatusBadge(statusCell, row);
  check('(4) Assessment-column .status-badge NOT suppressed (cell-scoped)',
    !assessBadge.classList.contains(HIDDEN), 'class=' + assessBadge.className);
}

// (5) V2-only row → no V1 badge to hide; must be a safe no-op
{
  const { row, statusCell } = v2OnlyRow();
  let threw = null;
  try { suppressV1StatusBadge(statusCell, row); } catch (e) { threw = e; }
  check('(5) V2-only row: no-op, no throw', !threw, threw ? String(threw) : 'ok');
  check('(6) V2-only row: V2 pill untouched',
    !!statusCell.querySelector('.hdlv2-inline-badge'));
}

// (7) idempotent — reconcileRows re-renders the pill on the 4s digest poll
{
  const { row, statusCell, v1Badge } = matchedRow(0);
  suppressV1StatusBadge(statusCell, row);
  suppressV1StatusBadge(statusCell, row);
  const count = v1Badge.className.split(/\s+/).filter((c) => c === HIDDEN).length;
  check('(7) idempotent: class applied exactly once across two passes',
    count === 1, 'occurrences=' + count);
}

// (8) null/missing cell → no throw (defensive, mirrors injectV2Badge's guard)
{
  let threw = null;
  try { suppressV1StatusBadge(null, null); } catch (e) { threw = e; }
  check('(8) null cell: no throw', !threw, threw ? String(threw) : 'ok');
}

// (9) EDGE — matched row carrying REAL V1 tracker data (data-total > 0).
//     Per the signed-off decision the badge is STILL hidden (V2 status wins),
//     but the row is STAMPED so such rows stay auditable rather than the
//     signal vanishing silently.
{
  const { row, statusCell, v1Badge } = matchedRow(7);
  suppressV1StatusBadge(statusCell, row);
  check('(9) matched + real V1 data: badge still hidden (default hide)',
    v1Badge.classList.contains(HIDDEN));
  check('(10) matched + real V1 data: row stamped data-v1-badge-hidden for audit',
    row.dataset.v1BadgeHidden === '7', 'stamp=' + row.dataset.v1BadgeHidden);
}

// (11) matched with NO V1 data → no audit stamp (nothing was really dropped)
{
  const { row, statusCell } = matchedRow(0);
  suppressV1StatusBadge(statusCell, row);
  check('(11) matched, zero V1 data: no audit stamp',
    row.dataset.v1BadgeHidden === undefined, 'stamp=' + String(row.dataset.v1BadgeHidden));
}

// ── source-level contracts (the structural guarantees the DOM can't show) ──

// (12) V1-only rows are untouched BY CONSTRUCTION: enhanceMatchedRows bails
//      before any decoration when the row has no V2 record.
{
  const fn = extract('enhanceMatchedRows');
  const bailIdx = fn.indexOf('if (!c) return');
  const injectIdx = fn.indexOf('injectV2Badge');
  check('(12) enhanceMatchedRows returns early when no V2 record, BEFORE injectV2Badge',
    bailIdx !== -1 && injectIdx !== -1 && bailIdx < injectIdx,
    'bail@' + bailIdx + ' inject@' + injectIdx);
}

// (13) the suppression rides injectV2Badge, so it covers BOTH the initial
//      matched pass and reconcileRows' 4s digest re-render.
{
  const fn = extract('injectV2Badge');
  check('(13) injectV2Badge calls suppressV1StatusBadge (covers initial + reconcile)',
    fn.indexOf('suppressV1StatusBadge') !== -1);
}

// (14) ZERO V1 PHP change: suppression is CSS-class based, and the class must
//      exist in the enhancer's own injected stylesheet.
{
  check('(14) injected stylesheet defines the suppression class',
    src.indexOf('.' + HIDDEN) !== -1);
}

// (15) hide, never remove — the node must survive (title/debuggability).
{
  const fn = extract('suppressV1StatusBadge');
  check('(15) suppression hides via classList, never removeChild/remove()',
    fn.indexOf('classList.add') !== -1 && !/removeChild|\.remove\(\)/.test(fn));
}

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
