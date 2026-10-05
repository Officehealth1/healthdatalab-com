#!/usr/bin/env node
/**
 * Consultations list in the Clients-page theme (v0.47.96).
 *
 * Extracts and runs the REAL list helpers from
 * assets/js/hdlv2-consultation.js (sortConsultations, listActivityAt,
 * listSentence, listRowModel, renderListTable) plus the real esc() and
 * formatRelativeTime(), then scans the list code and the .cl-* CSS for
 * what the redesign removed (tiles, count pills, avatars, the orange rail,
 * "Stage 3" wording, off-palette colours).
 *
 * Run:  node tests/consultations-list/test-list.js
 * Exit: 0 all pass · 1 any fail
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }

const ROOT = path.join(__dirname, '..', '..');
const src = fs.readFileSync(path.join(ROOT, 'assets', 'js', 'hdlv2-consultation.js'), 'utf8');
const css = fs.readFileSync(path.join(ROOT, 'assets', 'css', 'hdlv2-consultation.css'), 'utf8');

// The LAST definition wins at runtime (the file declares formatRelativeTime
// twice in one scope), so take the last match. One-liners (esc) end on
// their own line.
function body(name) {
  const re = new RegExp('  function ' + name + '\\([^)]*\\) \\{(?:[^\\n]*\\}\\n|[\\s\\S]*?\\n  \\}\\n)', 'g');
  const all = src.match(re);
  return all ? all[all.length - 1] : '';
}

// esc() builds a div and reads innerHTML: give it a tiny DOM that escapes
// the way a browser does for text content.
const fakeDocument = {
  createElement() {
    let t = '';
    return {
      set textContent(v) { t = String(v); },
      get innerHTML() { return t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    };
  }
};

const NAMES = ['esc', 'formatRelativeTime', 'listActivityAt', 'sortConsultations', 'listSentence', 'listRowModel', 'renderListTable'];
const ctx = { document: fakeDocument, Date: Date, Math: Math, Number: Number, String: String, isNaN: isNaN, encodeURIComponent: encodeURIComponent };
let loaded = true;
try {
  vm.runInNewContext(NAMES.map(body).join('\n') + '\n' + NAMES.map(n => 'this.' + n + ' = ' + n + ';').join(' '), ctx);
} catch (e) { loaded = false; }
NAMES.forEach(n => ok(loaded && typeof ctx[n] === 'function', n + '() exists in the shipped file'));
const { sortConsultations, listSentence, listRowModel, renderListTable } = ctx;

// MySQL-style local datetime, n hours ago.
function ago(hours) {
  const d = new Date(Date.now() - hours * 3600000);
  const p = x => String(x).padStart(2, '0');
  return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
}

// ── Sort ──
if (typeof sortConsultations === 'function') {
  const ready = [
    { progress_id: 1, client_name: 'R old', stage3_completed_at: ago(50) },
    { progress_id: 2, client_name: 'R new', stage3_completed_at: ago(2) }
  ];
  const tie = ago(100);
  const existing = [
    { progress_id: 3, client_name: 'D old', status: 'report_generated', consult_approved_at: ago(900) },
    { progress_id: 4, client_name: 'P old', status: 'draft', consult_created_at: ago(400) },
    { progress_id: 5, client_name: 'D new', status: 'report_generated', consult_approved_at: null, consult_created_at: ago(30) },
    { progress_id: 6, client_name: 'P new', status: 'draft', consult_created_at: null, consult_started_at: ago(9) },
    { progress_id: 7, client_name: 'P tie a', status: 'draft', consult_created_at: tie },
    { progress_id: 8, client_name: 'P tie b', status: 'draft', consult_created_at: tie }
  ];
  const out = sortConsultations(ready, existing);
  const ids = out.map(i => i.row.progress_id).join(',');
  ok(ids === '2,1,6,7,8,4,5,3', 'sort: ready → in progress → delivered, each newest first, ties keep server order (got ' + ids + ')');
  ok(out.map(i => i.kind).join(',') === 'ready,ready,progress,progress,progress,progress,delivered,delivered', 'sort: each item tagged ready / progress / delivered');
  ok(sortConsultations([], []).length === 0, 'sort: empty in, empty out');
  ok(Array.isArray(sortConsultations(undefined, null)), 'sort: tolerates missing arrays');
}

// ── The one sentence ──
if (typeof listSentence === 'function') {
  ok(listSentence({ ready: 2, in_progress: 8, delivered: 2 }) === '2 clients are ready for their first consultation.', 'sentence (2,8,2)');
  ok(listSentence({ ready: 1, in_progress: 0, delivered: 0 }) === '1 client is ready for their first consultation.', 'sentence (1,0,0) singular');
  ok(listSentence({ ready: 0, in_progress: 3, delivered: 1 }) === 'No one is waiting. 3 in progress.', 'sentence (0,3,1)');
  ok(listSentence({ ready: 0, in_progress: 0, delivered: 2 }) === 'No one is waiting.', 'sentence (0,0,2)');
  ok(listSentence({ ready: 0, in_progress: 0, delivered: 0 }) === '', 'sentence empty case → no sentence (empty state instead)');
  ok(listSentence(undefined) === '', 'sentence with no counts → no sentence');
}

// ── Row model per status ──
if (typeof listRowModel === 'function') {
  const r = listRowModel({ row: { progress_id: 12, client_name: 'Tap <b>Demo</b>', stage3_completed_at: ago(26), rate: 1.05 }, kind: 'ready' }, false);
  ok(r.pillClass === 'is-ready' && r.pillLabel === 'Ready', 'ready: pill is-ready / "Ready"');
  ok(/^Finished 1 day ago$/.test(r.activity), 'ready: "Finished …" from stage3_completed_at (got ' + r.activity + ')');
  ok(r.buttonLabel === 'Start consultation' && r.buttonClass === 'cl-btn-primary', 'ready: filled "Start consultation"');
  ok(r.rate === '1.05×', 'ready: rate 1.05×');
  ok(r.url === '?progress_id=12', 'ready: link ?progress_id=12');

  const p = listRowModel({ row: { progress_id: 'a b', client_name: 'kim', consult_created_at: null, consult_started_at: ago(9), rate: null }, kind: 'progress' }, false);
  ok(p.pillClass === 'is-progress' && p.pillLabel === 'In progress', 'in progress: pill is-progress / "In progress"');
  ok(p.activity === 'Edited 9 hours ago', 'in progress: "Edited …" falls back to consult_started_at (got ' + p.activity + ')');
  ok(p.buttonLabel === 'Resume' && p.buttonClass === 'cl-btn-secondary', 'in progress: outline "Resume"');
  ok(p.rate === '–', 'missing rate → en dash');
  ok(p.url === '?progress_id=a%20b', 'link id is URL-encoded');

  const d = listRowModel({ row: { progress_id: 3, client_name: 'Amanda', consult_approved_at: null, consult_created_at: ago(24 * 95), rate: '1.0' }, kind: 'delivered' }, false);
  ok(d.pillClass === 'is-delivered' && d.pillLabel === 'Delivered', 'delivered: pill is-delivered / "Delivered"');
  ok(d.activity === 'Sent 3 months ago', 'delivered: "Sent …" falls back to consult_created_at (got ' + d.activity + ')');
  ok(d.buttonLabel === 'Open report' && d.buttonClass === 'cl-btn-secondary', 'delivered: outline "Open report"');
  ok(d.rate === '1.00×', 'string rate formatted to two decimals');

  const n = listRowModel({ row: { progress_id: 4, client_name: 'X', rate: '' }, kind: 'progress' }, false);
  ok(n.activity === '–', 'no date at all → en dash, not "Edited —"');
  ok(n.rate === '–', 'empty-string rate → en dash');

  const a = listRowModel({ row: { progress_id: 5, client_name: 'Y', practitioner_name: 'Dr Z' }, kind: 'ready' }, true);
  ok(a.practitioner === 'Dr Z', 'admin: practitioner name carried');
  const na = listRowModel({ row: { progress_id: 5, client_name: 'Y', practitioner_name: 'Dr Z' }, kind: 'ready' }, false);
  ok(!na.practitioner, 'non-admin: practitioner name not shown');
}

// ── Rendered table ──
if (typeof renderListTable === 'function' && typeof sortConsultations === 'function') {
  const items = sortConsultations(
    [{ progress_id: 1, client_name: 'Tap <b>Demo</b>', stage3_completed_at: ago(3), rate: 1.05 }],
    [{ progress_id: 2, client_name: 'kim', status: 'draft', consult_created_at: ago(9), rate: 1.32, practitioner_name: 'Dr "Q"' }]
  );
  const html = renderListTable(items, true);
  ok(/<table[^>]*class="cl-table"/.test(html), 'renders a real <table class="cl-table">');
  ok((html.match(/<th scope="col"/g) || []).length === 5, 'five <th scope="col"> headers');
  ok((html.match(/<tr\b/g) || []).length === 3, 'one header row + one <tr> per client');
  ok(html.indexOf('Tap &lt;b&gt;Demo&lt;/b&gt;') !== -1 && html.indexOf('<b>Demo') === -1, 'client names are escaped');
  ok(html.indexOf('Dr &quot;Q&quot;') !== -1, 'admin practitioner name escaped and shown');
  ok(/href="\?progress_id=1"/.test(html) && /href="\?progress_id=2"/.test(html), 'each row links to its ?progress_id');
  ok((html.match(/cl-btn-primary/g) || []).length === 1, 'exactly one filled button (the one ready row)');
  ok(/aria-label="Start consultation for Tap &lt;b&gt;Demo&lt;\/b&gt;"/.test(html), 'action carries the client name for screen readers');
}

// ── Removed pieces: source scan of the list code and the .cl-* CSS ──
const listStart = src.indexOf('LIST VIEW');
const listEnd = src.lastIndexOf('function formatRelativeTime');
const listSrc = listStart !== -1 && listEnd > listStart ? src.slice(listStart, listEnd) : '';
ok(listSrc.length > 0, 'list section found in the JS');
['cl-count-card', 'cl-count-pill', 'cl-avatar', 'cl-section-sub', 'cl-section-lead', 'cl-back-link', 'renderListCounts'].forEach(k => {
  ok(listSrc.indexOf(k) === -1 && css.indexOf(k) === -1, 'gone: ' + k);
});
ok(!/Stage(&nbsp;| )3/.test(listSrc), 'no "Stage 3" wording in the list code');

const clRules = css.match(/[^{}]*\.cl-[^{}]*\{[^}]*\}/g) || [];
ok(clRules.length > 0, '.cl-* rules found');
ok(clRules.every(r => !/border-left\s*:/.test(r)), 'no border-left in any .cl- rule (no accent rail)');
ok(clRules.every(r => !/box-shadow\s*:/.test(r) || /\.cl-table-wrap\s*\{/.test(r)), 'no shadow except the table container');
ok(clRules.every(r => !/gradient/.test(r)), 'no gradient in .cl- rules');

const PALETTE = ['#d97706', '#fffbeb', '#fde68a', '#3b82f6', '#eff6ff', '#bfdbfe', '#10b981', '#ecfdf5', '#a7f3d0'];
const statusRules = clRules.filter(r => /\.cl-status/.test(r.split('{')[0]));
const statusColours = statusRules.join('\n').match(/#[0-9a-fA-F]{3,8}\b/g) || [];
ok(statusRules.length > 0, '.cl-status rules found');
ok(statusColours.length > 0 && statusColours.every(c => PALETTE.indexOf(c.toLowerCase()) !== -1), 'only the nine palette colours in .cl-status rules (got ' + statusColours.join(' ') + ')');
ok(!/#f59e0b|#dbeafe|#1e40af|#dcfce7|#166534/i.test(clRules.join('\n')), 'old off-palette colours gone from .cl- rules');

// v0.47.98 — a long name with no space (an e-mail used as a name) wraps.
['cl-name', 'cl-prac'].forEach(k => {
  const r = clRules.filter(x => new RegExp('\\.' + k + '\\s*\\{').test(x));
  ok(r.length === 1 && /overflow-wrap:\s*anywhere;/.test(r[0]), '.' + k + ' wraps a long unbroken name');
});

// The list's loading state is the list-shaped skeleton.
ok(/skeleton\('list'\)/.test(body('init')), "list loads under HDLV2Loading.skeleton('list')");

console.log('\n' + PASS + ' passed, ' + FAIL + ' failed');
process.exit(FAIL ? 1 : 0);
