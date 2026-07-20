#!/usr/bin/env node
/**
 * Finalise/autosave race guard (P3, 2026-07-19) — LIVE verification finding.
 *
 * Models the EXACT state machine in assets/js/hdlv2-consultation.js so the
 * two acceptance checks run deterministically with fake timers:
 *
 *   (a) edit -> IMMEDIATE finalise -> the saved organised notes == the edited
 *       text (NOT blank). The bug: a debounced flushAutoSave() firing AFTER
 *       showReportPreparing() replaces root.innerHTML reads an empty DOM and
 *       POSTs blank ai_organised_notes to /save-organised, wiping what /approve
 *       just committed.
 *   (b) after the guard fires on finalise, a LATER edit still autosaves — the
 *       guard is a one-shot cancel in the finalise handler, not a global
 *       disable.
 *
 * Faithfulness to the real file (verified by code-trace):
 *   - scheduleAutoSave(): clears+arms autoSaveTimer (1500ms debounce)  [~L806]
 *   - flushAutoSave(): clears timer; reads collectOrganisedFromDom() at
 *     flush time; POSTs /save-organised {ai_organised_notes: edited}     [~L812]
 *   - finalise handler captures `edited` synchronously, POSTs /approve, then
 *     showReportPreparing() does root.innerHTML=… (full teardown -> DOM
 *     collectors now return '')                                          [~L701/1679]
 *   - the GUARD under test, at the top of the finalise handler:
 *         if (autoSaveTimer){clearTimeout;autoSaveTimer=null;} autoSavePending=false;
 *         if (briefAutoSaveTimer){clearTimeout;briefAutoSaveTimer=null;} briefAutoSavePending=false;
 *
 * Run:  node tests/finalise-autosave-guard/test-finalise-autosave-guard.js
 * Exit: 0 all pass · 1 any fail
 */
'use strict';

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }

// ── Fake timer + fetch recorder shared by a scenario run ──
function makeWorld() {
  const timers = new Map(); let seq = 1, now = 0;
  const calls = [];
  return {
    now: () => now,
    setTimeout(fn, ms) { const id = seq++; timers.set(id, { fn, at: now + ms }); return id; },
    clearTimeout(id) { timers.delete(id); },
    advance(ms) {
      const end = now + ms;
      // fire due timers in time order, allowing re-scheduling within the window
      for (;;) {
        let next = null;
        for (const [id, t] of timers) if (t.at <= end && (!next || t.at < next.at)) next = { id, ...t };
        if (!next) break;
        now = next.at; timers.delete(next.id); next.fn();
      }
      now = end;
    },
    record(url, body) { calls.push({ url, body }); },
    calls,
  };
}

// ── Faithful model of the consultation.js autosave + finalise machinery ──
// `withGuard` toggles the P3 fix so the harness proves red (bug) -> green (fix).
function runScenario(withGuard) {
  const w = makeWorld();

  // DOM state the collectors read. Teardown (showReportPreparing) blanks them.
  const dom = { organised: '', brief: '', torndown: false };
  const collectOrganisedFromDom = () => (dom.torndown ? '' : dom.organised);

  // organised-notes autosave state (real var names)
  let autoSaveTimer = null, autoSaveInflight = false, autoSavePending = false;
  // brief autosave state
  let briefAutoSaveTimer = null, briefAutoSavePending = false;

  let savedOrganised = 'ORIGINAL'; // stands in for DB ai_organised_notes

  function scheduleAutoSave() {
    if (autoSaveTimer) w.clearTimeout(autoSaveTimer);
    autoSaveTimer = w.setTimeout(flushAutoSave, 1500);
  }
  function flushAutoSave() {
    if (autoSaveTimer) { w.clearTimeout(autoSaveTimer); autoSaveTimer = null; }
    if (autoSaveInflight) { autoSavePending = true; return; }
    const edited = collectOrganisedFromDom();
    w.record('/save-organised', { ai_organised_notes: edited });
    savedOrganised = edited; // server persists whatever was posted
  }

  function clickFinalise() {
    // ── THE GUARD UNDER TEST ──
    if (withGuard) {
      if (autoSaveTimer) { w.clearTimeout(autoSaveTimer); autoSaveTimer = null; }
      autoSavePending = false;
      if (briefAutoSaveTimer) { w.clearTimeout(briefAutoSaveTimer); briefAutoSaveTimer = null; }
      briefAutoSavePending = false;
    }
    const edited = collectOrganisedFromDom();        // captured while DOM intact
    w.record('/approve', { ai_organised_notes: edited });
    savedOrganised = edited;                          // /approve commits edited
    // showReportPreparing(): root.innerHTML = … -> full teardown
    dom.torndown = true;
  }

  return { w, dom, scheduleAutoSave, clickFinalise,
    get savedOrganised() { return savedOrganised; },
    get autoSaveTimer() { return autoSaveTimer; },
    get briefAutoSaveTimer() { return briefAutoSaveTimer; },
    armBriefTimer() { briefAutoSaveTimer = w.setTimeout(() => {}, 1500); } };
}

console.log('── RED baseline (guard REMOVED) — the bug must reproduce ──');
{
  const s = runScenario(/*withGuard=*/false);
  s.dom.organised = 'EDIT-KOALA-7391';
  s.scheduleAutoSave();           // user typed (timer armed @ +1500)
  s.w.advance(300);               // 300ms later…
  s.clickFinalise();              // …immediate finalise -> teardown
  s.w.advance(2000);              // past the 1500 debounce
  const blank = s.w.calls.find(c => c.url === '/save-organised' && c.body.ai_organised_notes === '');
  ok(!!blank, 'RED: a blank /save-organised fires after teardown (bug present without guard)');
  ok(s.savedOrganised === '', 'RED: saved organised notes end up BLANK (data loss)');
}

console.log('\n── GREEN check (a): edit -> immediate finalise -> saved == edited (not blank) ──');
{
  const s = runScenario(/*withGuard=*/true);
  s.dom.organised = 'EDIT-KOALA-7391';
  s.armBriefTimer();              // a pending brief autosave is also live in this view
  s.scheduleAutoSave();           // user typed (timer armed)
  s.w.advance(300);
  s.clickFinalise();              // guard cancels pending flushes, then /approve
  s.w.advance(2000);              // past the debounce window
  const approve = s.w.calls.find(c => c.url === '/approve');
  const anySaveOrganised = s.w.calls.some(c => c.url === '/save-organised');
  ok(approve && approve.body.ai_organised_notes === 'EDIT-KOALA-7391', '(a) /approve carries the EDITED text');
  ok(!anySaveOrganised, '(a) NO /save-organised fires after finalise (pending flush cancelled)');
  ok(s.savedOrganised === 'EDIT-KOALA-7391', '(a) saved organised notes == edited text (NOT blank)');
  ok(s.autoSaveTimer === null && s.briefAutoSaveTimer === null, '(a) both organised + brief pending timers cancelled by the guard');
}

console.log('\n── GREEN check (b): a LATER edit still autosaves after the guard ──');
{
  const s = runScenario(/*withGuard=*/true);
  // finalise once (guard fires)
  s.dom.organised = 'EDIT-KOALA-7391';
  s.scheduleAutoSave();
  s.w.advance(300);
  s.clickFinalise();
  s.w.advance(2000);
  // Fresh review panel re-mounts (teardown reversed for the model), user edits again
  s.dom.torndown = false;
  s.dom.organised = 'LATER-EDIT-OTTER-8215';
  s.scheduleAutoSave();           // guard did not disable autosave
  s.w.advance(1600);              // debounce elapses
  const later = s.w.calls.filter(c => c.url === '/save-organised').pop();
  ok(later && later.body.ai_organised_notes === 'LATER-EDIT-OTTER-8215', '(b) later edit autosaves the new text');
  ok(s.savedOrganised === 'LATER-EDIT-OTTER-8215', '(b) new text persisted (autosave still works)');
}

console.log('\n' + (PASS + FAIL) + ' assertions · ' + PASS + ' passed · ' + FAIL + ' failed');
console.log(FAIL ? 'FINALISE-AUTOSAVE GUARD: FAIL' : 'FINALISE-AUTOSAVE GUARD: PASS');
process.exit(FAIL ? 1 : 0);
