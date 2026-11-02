#!/usr/bin/env node
/**
 * Family pack, the visible side (v0.48.35). Real helpers from the page script and the dashboard
 * row script in a vm with a stub document; words in the widget's card checked at the source.
 * Run: node tests/healthspan-timeline-pack/test-ui.js
 */
'use strict';
const fs = require('fs'), path = require('path'), vm = require('vm');
let PASS = 0, FAIL = 0;
function ok(c, l) { (c ? PASS++ : FAIL++); console.log((c ? 'PASS  ' : 'FAIL  ') + l); }
const load = (rel, name) => {
  const ctx = { document: { getElementById: () => null, querySelector: () => null, addEventListener: () => {}, readyState: 'complete' }, console };
  ctx.window = ctx;
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', '..', rel), 'utf8'), ctx);
  return ctx[name];
};
const M = load('assets/js/hdlv2-healthspan-timeline.js', 'HDLV2HealthspanTimeline');
const need = ['groupLanes', 'laneText', 'packLine', 'inviteLabel', 'linkedRowHtml', 'linkedPerson'];
const has = need.every(k => M && typeof M[k] === 'function');
ok(has, 'the page exposes ' + need.join(', '));
if (!has) { console.log('\nPASS=' + PASS + ' FAIL=' + FAIL); process.exit(1); }
M.reset({ mode: 'full', you: { name: 'Quim', born: M.NOW_Y - 50, sex: 'M', bio: 49, rate0: 0.95 } });
const you = M.state().you;

// Story 3: grouped lanes
const lanes = [{ name: 'Sam', rel: 'friend' }, { name: 'Ann', rel: 'spouse' }, { name: 'Zed', rel: 'other' }, { name: 'Kim', rel: 'child' }];
let g = M.groupLanes(lanes);
ok(g.map(x => x.title).join('|') === 'Family|Friends|Other', 'the three headings, in order');
ok(g.map(x => x.people.length).join('|') === '2|1|1', 'each with its count');
ok(M.groupLanes([{ name: 'Zed', rel: 'other' }])[2].people.length === 1, 'a lane alone still has its heading, with 1');
['spouse', 'child', 'grandchild', 'parent', 'sibling'].forEach(r => ok(M.groupLanes([{ rel: r }])[0].people.length === 1, r + ' sits under Family'));
ok(M.groupLanes([{ rel: 'friend' }])[1].people.length === 1, 'friend sits under Friends');

// Story 5: the shared number on the lane
const accepted = { id: 4, label: 'Spouse', relationship: 'spouse', born_guess: 1980, sex_guess: 'F', status: 'accepted', shared: { rate: 1.08, age: 41, sex: 'F' } };
ok(M.laneText(M.linkedPerson(accepted, you), true) === 'Spouse · 41 · 1.08× · shared', 'a shared lane: Spouse · 41 · 1.08× · shared');
['withdrawn', 'revoked', 'expired'].forEach(s => ok(M.laneText(M.linkedPerson(Object.assign({}, accepted, { status: s, shared: null }), you), true) === 'Spouse · guessed', s + ' lane: Spouse · guessed'));
const guessed = { id: 'p1', name: 'Child', rel: 'child', born: M.NOW_Y - 27, sex: 'F', rate: 1.23 };
ok(M.laneText(guessed, true) === 'Child · 27 · 1.23×', 'a lane the client typed keeps its age and pace');
ok(M.laneText(M.linkedPerson(accepted, you), false) === 'Spouse · from their report', 'the pack flag off: Plan B\'s words');

// Story 1: the pack line and the invite button
ok(M.packLine({ seats_total: 5, seats_used: 1, status: 'active' }) === 'Family pack · 5 seats · 4 left', 'the pack line');
ok(M.packLine({ seats_total: 5, seats_used: 1, status: 'ended' }) === 'Family pack ended', 'ended');
ok(M.packLine(null) === '', 'no pack, no line');
ok(M.inviteLabel('Spouse', { seats_total: 5, seats_used: 1, status: 'active' }) === 'Invite Spouse by link (uses a seat)', 'with a seat left');
ok(M.inviteLabel('Spouse', { seats_total: 5, seats_used: 5, status: 'active' }) === 'Invite Spouse by link', 'no seat left: Plan B\'s words');
ok(M.inviteLabel('Spouse', { seats_total: 5, seats_used: 1, status: 'ended' }) === 'Invite Spouse by link', 'ended: Plan B\'s words');
ok(M.inviteLabel('Spouse', null) === 'Invite Spouse by link', 'no pack: Plan B\'s words');
let h = M.linkedRowHtml(Object.assign({}, accepted, { full_report: true }));
ok(/>Full Report</.test(h) && />Shared</.test(h), 'a seat\'s row: Shared and Full Report');
ok(!/>Full Report</.test(M.linkedRowHtml(accepted)), 'no seat, no chip');

// The switch is one button with aria-pressed, in the band; no pack flag, no switch
ok(/aria-pressed/.test(M.groupSwitchHtml(false)) && /Group by relationship/.test(M.groupSwitchHtml(true)) && /aria-pressed="true"/.test(M.groupSwitchHtml(true)), 'the switch: aria-pressed, its words');

// Story 2: the dashboard row
const R = load('assets/js/hdlv2-timeline-pack-row.js', 'HDLV2PackRow');
ok(R && typeof R.lineHtml === 'function', 'the row script exposes lineHtml');
if (R) {
  const c = { name: 'Demo Owner', progress_id: 9, family: { can_grant: true, pack: null } };
  h = R.lineHtml(c, '');
  ok(/>Grant family pack</.test(h), 'no pack: Grant family pack');
  h = R.lineHtml(c, 'grant');
  ok(/Grant 5 seats to Demo Owner\?/.test(h) && />Grant</.test(h) && />Keep</.test(h), 'the inline two-step, no dialog');
  const on = { name: 'Demo <b>', progress_id: 9, family: { can_grant: true, pack: { id: 3, seats_total: 5, seats_used: 2, status: 'active' } } };
  h = R.lineHtml(on, '');
  ok(/Family pack · 5 seats · 2 used/.test(h) && />End pack</.test(h) && !/Grant family pack/.test(h), 'a pack: the count and End pack');
  h = R.lineHtml(on, 'end');
  ok(/End this pack\?/.test(h) && />End</.test(h) && />Keep</.test(h) && !/<b>/.test(h), 'its inline two-step, the name escaped');
  ok(/Family pack ended/.test(R.lineHtml({ name: 'x', progress_id: 9, family: { can_grant: true, pack: { id: 3, seats_total: 5, seats_used: 2, status: 'ended' } } }, '')), 'ended: the words, and a new grant is possible');
  ok(R.lineHtml({ name: 'x', progress_id: 9, family: { can_grant: false, pack: null } }, '') === '', 'no report, no pack: nothing');
  ok(R.lineHtml({ name: 'x' }, '') === '', 'flag off (no family field): nothing');
  const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets/js/hdlv2-timeline-pack-row.js'), 'utf8');
  ok(!/\b(alert|confirm|prompt)\s*\(/.test(src), 'the row script calls no alert / confirm / prompt');
}

// The widget's card: the inbox, never a link in the browser
const w = fs.readFileSync(path.join(__dirname, '..', '..', 'widget/hdl-lead-magnet.js'), 'utf8');
ok(/Your Full Report is unlocked\. We emailed your link to /.test(w), 'the card points to the inbox');
ok(/Your Full Report could not be unlocked just now; your numbers are shared\./.test(w), 'the failure line');
ok(/Your Full Report is unlocked, but the email did not go\. Your practitioner can resend it\./.test(w), 'the email failed: the card says so');
ok(!/open_url/.test(w), 'the widget never reads a sign-in link from the response');
ok(/fr === 'sent'\) \{ var wb = document\.querySelector\('#' \+ id \+ '-thanks \.hdlw-r-thanks-body'\); if \(wb\) wb\.textContent = 'Your practitioner has been sent your data\.'; \}/.test(w), 'a seat cuts the "you will receive another email" half of the wall: it is no longer true, and no empty card is left');

// No accent rails in the new CSS
const css = fs.readFileSync(path.join(__dirname, '..', '..', 'assets/css/hdlv2-healthspan-timeline.css'), 'utf8');
ok(!/ht-group[^}]*border-left/.test(css), 'no rail on the new rules');
console.log('\nPASS=' + PASS + ' FAIL=' + FAIL);
process.exit(FAIL ? 1 : 0);
