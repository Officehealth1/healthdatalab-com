#!/usr/bin/env node
/**
 * Plan 13, § 6.6 (H1, H2): the "Health analysis" panel on the consultation screen. The REAL panel functions
 * of assets/js/hdlv2-consultation.js, run in vm against a few fake elements and a fake fetch.
 *   S1 nothing written: the heading, "For you only…", one button; no note box.
 *   S2 pressing: one POST with the nonce and an Idempotency-Key; skeleton lines and one true line while
 *      it writes; the button cannot be pressed twice.
 *   S3 written: the analysis (as the server escaped it), "Written {date}, {time}", "Write again", the
 *      labelled note box, "Not approved. It will not print in the report."
 *   S4 approve / change / take out: the status line follows; typing after approval says "Changed…" at once.
 *   S5 writing again while approved asks first, in the page, in two steps; Cancel sends nothing.
 *   S6 failures: prompt 1 fails → what was there comes back and the first error line; prompt 2 fails →
 *      the analysis shows and the second error line.
 *   S7 the switch: the panel and its mount exist only when the settings say health_analysis; no native
 *      pop-up; every control a real button or a labelled box.
 * Run: node tests/health-analysis/test-health-panel.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };
const js = fs.readFileSync(new URL('../../assets/js/hdlv2-consultation.js', import.meta.url), 'utf8');
const cut = (head, end) => { const at = js.indexOf(head); return at < 0 ? '' : js.slice(at, js.indexOf(end, at) + end.length); };
const fn = (name) => cut('  function ' + name + '(', '\n  }\n');
const NAMES = ['healthStatus', 'healthPanel', 'mountHealth', 'writeHealth', 'readBackHealth', 'askWriteAgain', 'saveHealthNote'];
const line = (head) => cut(head, '\n');
const SRC = [line('  function esc('), line('  var health = '), ...NAMES.map(fn)].join('\n');
ok(NAMES.every((n) => fn(n) !== '') && line('  var health = ') !== '', 'the panel functions are top-level in the consultation script');

const unesc = (s) => String(s).replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&');

/** A page of fake elements: setting innerHTML (re)creates the elements whose ids the markup names, each
 *  holding its own inner markup (tags matched with nesting), and drops the ids the old markup held. */
function page() {
  const reg = {}, doc = { active: null };
  const mk = (id, tag = 'div', inner = '') => {
    const handlers = {};
    const e = { id, tag, _html: inner, kids: [], textContent: '', disabled: false, value: '', handlers,
      get innerHTML() { return e._html; },
      set innerHTML(h) { e.kids.forEach((k) => delete reg[k]); e._html = h; e.kids = scan(h); },
      addEventListener(t, f) { (handlers[t] = handlers[t] || []).push(f); },
      fire(t) { (handlers[t] || []).forEach((f) => f.call(e, { target: e })); },
      click() { if (!e.disabled) e.fire('click'); },
      focus() { doc.active = e; } };
    if (tag === 'textarea') e.value = unesc(inner);
    if (tag === 'p' || tag === 'span') e.textContent = unesc(inner.replace(/<[^>]+>/g, ''));
    return e;
  };
  function scan(h) {
    const ids = [], re = /<(\w+)\b([^>]*)>/g;
    let m;
    while ((m = re.exec(h))) {
      const tag = m[1], attrs = m[2], idm = attrs.match(/\sid="([^"]+)"/);
      if (!idm) continue;
      const from = m.index + m[0].length, tags = new RegExp('<' + tag + '\\b[^>]*>|</' + tag + '>', 'g');
      let depth = 1, to = h.length, t;
      tags.lastIndex = from;
      while ((t = tags.exec(h))) { if (t[0][1] === '/') { if (--depth === 0) { to = t.index; break; } } else depth++; }
      const el = mk(idm[1], tag, h.slice(from, to));
      el.attrs = attrs;
      if (/\sdisabled/.test(attrs)) el.disabled = true;
      reg[idm[1]] = el;
      ids.push(idm[1]);
    }
    return ids;
  }
  // createElement serves the script's own esc(): text in, the browser's escaping out (& < > only).
  const createElement = () => { let t = ''; return { set textContent(v) { t = String(v); }, get innerHTML() { return t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); } }; };
  reg['hdlv2-health'] = mk('hdlv2-health');
  return { reg, doc, document: { getElementById: (id) => reg[id] || null, createElement } };
}

function boot(block) {
  const p = page(), calls = [];
  let pending = null, dropping = null;
  const fetch = (url, opts = {}) => new Promise((resolve, reject) => {
    calls.push({ url, opts, body: JSON.parse(opts.body || '{}') });
    pending = (data) => resolve({ json: () => Promise.resolve(data) });
    dropping = () => reject(new TypeError('Failed to fetch'));
  });
  const ctx = { document: p.document, fetch, CFG: { api_base: '/wp-json/hdl-v2/v1/consultation', nonce: 'N1', health_analysis: 1 },
    state: { progressId: 10 }, HDLV2Loading: { skeleton: (k) => '<div class="SKEL-' + k + '"></div>' },
    window: { hdlv2RateLimit: { idempotencyKey: () => 'uuid-0001-test' } }, Date };
  vm.createContext(ctx);
  vm.runInContext(SRC + '\nmountHealth(' + JSON.stringify(block) + ');', ctx);
  const tick = async () => { for (let i = 0; i < 4; i++) await new Promise((r) => setTimeout(r, 0)); };
  return { p, ctx, calls, answer: async (data) => { pending(data); await tick(); }, drop: async () => { dropping(); await tick(); } };
}
const $ = (b, id) => b.p.reg[id] || null;
const text = (b) => b.p.reg['hdlv2-health'].innerHTML;

const WRITTEN = { success: true, analysis_html: '<p>Red flags: none identified.</p><p><strong>Late eating may fit.</strong></p>', written: '24 Nov 2026, 09:14', note: 'The short version\n**Eating late may be behind it.**', approved: '', note_written: true };

console.log('── S1 nothing written ──');
let b = boot({ analysis_html: '', written: '', note: '', approved: '' });
ok(/<h4[^>]*>Health analysis<\/h4>/.test(text(b)), 'S1.1 the heading "Health analysis"');
ok(text(b).includes('For you only. The client never sees this part.'), 'S1.2 the line under it');
ok($(b, 'hdlv2-health-write') && /Write health analysis/.test(text(b)) && /<button type="button"[^>]*id="hdlv2-health-write"/.test(text(b)), 'S1.3 one real button "Write health analysis"');
ok(!$(b, 'hdlv2-health-note') && !$(b, 'hdlv2-health-approve'), 'S1.4 no note box and no approve button yet');

console.log('── S2 pressing ──');
$(b, 'hdlv2-health-write').click();
ok(b.calls.length === 1 && b.calls[0].url === '/wp-json/hdl-v2/v1/consultation/health-analysis' && b.calls[0].opts.method === 'POST', 'S2.1 one POST to /consultation/health-analysis');
ok(b.calls[0].opts.headers['X-WP-Nonce'] === 'N1' && b.calls[0].opts.headers['Idempotency-Key'] === 'uuid-0001-test' && b.calls[0].body.progress_id === 10, 'S2.2 nonce, Idempotency-Key, progress_id');
ok($(b, 'hdlv2-health-body').innerHTML.includes('SKEL-brief') && $(b, 'hdlv2-health-body').innerHTML.includes('Writing. This can take a minute.'), 'S2.3 skeleton lines and one true line while it writes');
ok(!/spinner/i.test($(b, 'hdlv2-health-body').innerHTML), 'S2.4 no spinner');
$(b, 'hdlv2-health-write').click();
ok($(b, 'hdlv2-health-write').disabled && b.calls.length === 1, 'S2.5 the button cannot be pressed twice');

console.log('── S3 written ──');
await b.answer(WRITTEN);
ok(text(b).includes('<p><strong>Late eating may fit.</strong></p>'), 'S3.1 the analysis as the server escaped it, in the order written');
ok(text(b).includes('Written 24 Nov 2026, 09:14'), 'S3.2 "Written {date}, {time}"');
ok(/>Write again</.test(text(b)), 'S3.3 the button now reads "Write again"');
ok(/<label for="hdlv2-health-note"[^>]*>Note for the client's report<\/label>/.test(text(b)) && $(b, 'hdlv2-health-note') && $(b, 'hdlv2-health-note').tag === 'textarea', 'S3.4 the labelled box "Note for the client\'s report"');
ok($(b, 'hdlv2-health-note').value === WRITTEN.note, 'S3.5 the note in the box, as plain text');
ok(/role="status"/.test($(b, 'hdlv2-health-status').attrs) && $(b, 'hdlv2-health-status').textContent === 'Not approved. It will not print in the report.', 'S3.6 the status line (role="status"): not approved');
ok($(b, 'hdlv2-health-approve') && /Approve for the report/.test(text(b)) && !$(b, 'hdlv2-health-withdraw'), 'S3.7 "Approve for the report"; no "Take out" yet');

console.log('── S4 approve, change, take out ──');
$(b, 'hdlv2-health-note').value = 'Edited by the practitioner.';
$(b, 'hdlv2-health-approve').click();
ok(b.calls.length === 2 && b.calls[1].url.endsWith('/health-note') && b.calls[1].body.note === 'Edited by the practitioner.' && b.calls[1].body.approve === true && b.calls[1].body.progress_id === 10, 'S4.1 approve sends the text as it stands, approve true');
await b.answer({ success: true, note: 'Edited by the practitioner.', approved: '24 Nov 2026' });
ok($(b, 'hdlv2-health-status').textContent === 'Approved 24 Nov 2026. It prints in the next report you generate.', 'S4.2 "Approved {date}. It prints in the next report you generate."');
ok($(b, 'hdlv2-health-withdraw') && /Take out of the report/.test(text(b)), 'S4.3 "Take out of the report" appears');
ok(text(b).includes('<p><strong>Late eating may fit.</strong></p>'), 'S4.4 the analysis stays on screen');
$(b, 'hdlv2-health-note').value = 'Edited by the practitioner, again.';
$(b, 'hdlv2-health-note').fire('input');
ok($(b, 'hdlv2-health-status').textContent === 'Changed. Approve it again to print the new text.', 'S4.5 typing after approval: "Changed. Approve it again…" at once, nothing sent');
ok(b.calls.length === 2, 'S4.6 typing sends nothing (the approved text prints until the new one is approved)');
$(b, 'hdlv2-health-note').value = 'Edited by the practitioner.';
$(b, 'hdlv2-health-note').fire('input');
ok($(b, 'hdlv2-health-status').textContent.startsWith('Approved 24 Nov 2026.'), 'S4.7 typed back to the approved text: approved again');
$(b, 'hdlv2-health-withdraw').click();
ok(b.calls.length === 3 && b.calls[2].body.approve === false, 'S4.8 "Take out of the report" sends approve false');
await b.answer({ success: true, note: 'Edited by the practitioner.', approved: '' });
ok($(b, 'hdlv2-health-status').textContent === 'Not approved. It will not print in the report.' && !$(b, 'hdlv2-health-withdraw'), 'S4.9 not approved again; "Take out" gone');
$(b, 'hdlv2-health-approve').click();
await b.answer({ code: 'empty_note', message: 'The note is empty.', data: { status: 400 } });
ok($(b, 'hdlv2-health-status').textContent === 'The note is empty.', 'S4.10 a refused save shows the server\'s line in the status');

console.log('── S5 write again while approved ──');
b = boot({ analysis_html: '<p>Old.</p>', written: '23 Nov 2026, 10:00', note: 'Approved note.', approved: '23 Nov 2026' });
$(b, 'hdlv2-health-write').click();
ok(b.calls.length === 0 && $(b, 'hdlv2-health-ask').innerHTML.includes('This replaces the approved note. Write again?'), 'S5.1 first press: asks in the page, sends nothing');
ok($(b, 'hdlv2-health-yes') && $(b, 'hdlv2-health-no') && b.p.doc.active === $(b, 'hdlv2-health-yes'), 'S5.2 two real buttons; the keyboard lands on "Write again"');
$(b, 'hdlv2-health-no').click();
ok(b.calls.length === 0 && $(b, 'hdlv2-health-write') && !$(b, 'hdlv2-health-ask').innerHTML.includes('This replaces') && !text(b).includes('This replaces'), 'S5.3 Cancel: back as before, nothing sent');
ok(b.p.doc.active === $(b, 'hdlv2-health-write'), 'S5.4 the keyboard returns to the button');
$(b, 'hdlv2-health-write').click();
$(b, 'hdlv2-health-yes').click();
ok(b.calls.length === 1 && b.calls[0].url.endsWith('/health-analysis'), 'S5.5 second step: it writes');

console.log('── S6 failures ──');
await b.answer({ code: 'analysis_failed', message: 'x', data: { status: 502 } });
ok($(b, 'hdlv2-health-body').innerHTML.includes('<p>Old.</p>') && $(b, 'hdlv2-health-body').innerHTML.includes('Written 23 Nov 2026, 10:00') && !$(b, 'hdlv2-health-body').innerHTML.includes('SKEL'), 'S6.1 prompt 1 fails: what was there comes back');
ok($(b, 'hdlv2-health-error').textContent === 'The analysis could not be written. Nothing was changed. Please try again.' && /role="alert"/.test($(b, 'hdlv2-health-error').attrs), 'S6.2 the first error line (role="alert")');
ok(!$(b, 'hdlv2-health-write').disabled, 'S6.3 the button can be pressed again');
b = boot({ analysis_html: '', written: '', note: '', approved: '' });
$(b, 'hdlv2-health-write').click();
await b.answer(Object.assign({}, WRITTEN, { note: '', note_written: false }));
ok(text(b).includes('Late eating may fit.') && $(b, 'hdlv2-health-error').textContent === 'The analysis is ready. The client\'s note could not be written. Please try again.', 'S6.4 prompt 2 fails: the analysis shows, the second error line');

console.log('── S8 while it writes; the connection drops ──');
b = boot({ analysis_html: '<p>Old.</p>', written: '23 Nov 2026, 10:00', note: 'Old note.', approved: '' });
$(b, 'hdlv2-health-write').click();
ok($(b, 'hdlv2-health-note').disabled && $(b, 'hdlv2-health-approve').disabled, 'S8.1 while it writes, the note box and "Approve" cannot be used');
await b.drop();
ok(b.calls.length === 2 && b.calls[1].url === '/wp-json/hdl-v2/v1/consultation/10' && !b.calls[1].opts.method, 'S8.2 the connection drops: it reads back what was saved');
await b.answer({ consultation: {}, health_analysis: { analysis_html: '<p>New.</p>', written: '24 Nov 2026, 09:20', note: 'New note.', approved: '' } });
ok(text(b).includes('<p>New.</p>') && $(b, 'hdlv2-health-note').value === 'New note.', 'S8.3 what the server saved is shown');
ok($(b, 'hdlv2-health-error').textContent === 'The connection dropped while it was writing. What you see is what was saved.', 'S8.4 the line says so (never "Nothing was changed")');

console.log('── S7 the switch, pop-ups, controls ──');
ok(/\(CFG\.health_analysis \? '<div class="hdlv2-consult-section hdlv2-health" id="hdlv2-health"><\/div>' : ''\)/.test(js), 'S7.1 the panel is in the screen only when the settings say health_analysis');
ok(/if \(CFG\.health_analysis\) mountHealth\(data\.health_analysis \|\| \{\}\);/.test(js), 'S7.2 it is mounted only then');
ok(!/\b(confirm|alert|prompt)\s*\(/.test(SRC.replace(line('  function esc('), '')), 'S7.3 no native pop-up in the panel\'s code');
ok(!/<div[^>]*onclick|<span[^>]*onclick/.test(SRC), 'S7.4 every control is a real <button> (no clickable div or span)');

console.log('\nPASS=' + PASS + ' FAIL=' + FAIL);
process.exit(FAIL ? 1 : 0);
