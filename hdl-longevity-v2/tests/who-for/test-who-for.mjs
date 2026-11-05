#!/usr/bin/env node
/**
 * v0.48.50 (W29) — "Who are you staying strong for?": name + role rows on the picks page, and the
 * healthspan timeline seeds its Loved ones from them.
 *   - the REAL whoForHtml / whoForRow / peopleFromData / composePeople / composePeopleText of
 *     assets/js/hdlv2-staged-form.js, run in vm;
 *   - the picks page renders the rows instead of the key_people_text textarea; own_words keeps it;
 *   - submitWhyPicks sends key_people and key_people_text from the rows, and stops on a name with no role;
 *   - the REAL timeline module: reset() with key_people in full mode seeds the people; preview and
 *     no list keep the sample people; a stored list (restore) still wins.
 * Run: node tests/who-for/test-who-for.mjs   Exit: 0 all pass, 1 any fail
 */
import fs from 'node:fs';
import vm from 'node:vm';

let PASS = 0, FAIL = 0;
const ok = (c, l) => { c ? PASS++ : FAIL++; console.log((c ? '  PASS  ' : '  FAIL  ') + l); };

const js = fs.readFileSync(new URL('../../assets/js/hdlv2-staged-form.js', import.meta.url), 'utf8');
const cut = (text, head, end) => { const at = text.indexOf(head); return at < 0 ? '' : text.slice(at, text.indexOf(end, at) + end.length); };
// esc() as the form has it (textContent → innerHTML, then quotes), without a DOM.
const esc = "function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\"/g, '&quot;'); }\n";
const parts = [cut(js, '  var WHO_ROLES = ', ';\n'), cut(js, '  var WHO_MAX = ', ';\n'), cut(js, '  function peopleFromData(', '\n  }\n'),
  cut(js, '  function whoForRow(', '\n  }\n'), cut(js, '  function whoForHtml(', '\n  }\n'),
  cut(js, '  function composePeople(', '\n  }\n'), cut(js, '  function composePeopleText(', '\n  }\n')];
ok(esc !== '' && parts.every(Boolean), 'the who-for pieces are top-level in the form script');
const W = {};
if (parts.every(Boolean)) vm.runInNewContext(esc + parts.join('\n') + '\nthis.f = { peopleFromData, whoForHtml, composePeople, composePeopleText, WHO_ROLES, WHO_MAX };', W);
const F = W.f || {};
const count = (s, re) => (s.match(re) || []).length;

if (F.whoForHtml) {
  const h = F.whoForHtml([]);
  ok(h.indexOf('id="hdlv2-who-for"') > 0 && count(h, /class="hdlv2-who-row"/g) === 3, 'empty: three rows in #hdlv2-who-for');
  ok(h.indexOf('<legend>Who are you staying strong for?</legend>') > 0, 'the question as the group\'s legend');
  ok(h.indexOf('Name them. We’ll remember them for your timeline.') > 0, 'the line under it');
  ok(count(h, /<input type="text" maxlength="30" placeholder="First name"/g) === 3, 'each row: a first-name box, 30 characters');
  const labels = ['Spouse or partner', 'Child', 'Grandchild', 'Parent', 'Brother or sister', 'Friend', 'Other'];
  const values = ['spouse', 'child', 'grandchild', 'parent', 'sibling', 'friend', 'other'];
  const first = cut(h, '<select', '</select>');
  ok(first.indexOf('<option value="" selected>Who are they?</option>') > 0
    && labels.every((l, i) => first.indexOf('<option value="' + values[i] + '">' + l + '</option>') > 0), 'each row: a role list, no role guessed, the seven roles in order');
  ok(count(h, /hdlv2-who-remove/g) === 0, 'three rows: no remove control');
  ok(/<button type="button" class="hdlv2-who-add">\+ Add another<\/button>/.test(h), '"+ Add another" shows');
  const four = F.whoForHtml([{ name: 'Jenny', rel: 'child' }, { name: 'Leo', rel: 'friend' }, { name: 'Mia', rel: 'grandchild' }, { name: 'Sam', rel: 'sibling' }]);
  ok(count(four, /class="hdlv2-who-row"/g) === 4 && count(four, /class="hdlv2-who-remove"/g) === 1 && four.indexOf('aria-label="Remove person 4"') > 0, 'four rows: a remove control on the fourth only');
  ok(four.indexOf('value="Jenny"') > 0 && four.indexOf('<option value="child" selected>Child</option>') > 0, 'saved rows come back filled');
  const six = F.whoForHtml(Array.from({ length: 8 }, (_, i) => ({ name: 'P' + i, rel: 'friend' })));
  ok(count(six, /class="hdlv2-who-row"/g) === 6 && /class="hdlv2-who-add" hidden/.test(six), 'six rows at most; the add button hides at six');
  ok(F.whoForHtml([{ name: '"><img src=x onerror=alert(1)>', rel: 'x' }]).indexOf('<img') === -1, 'a name is escaped');
}
if (F.composePeople) {
  const rows = [{ name: ' Jenny ', rel: 'child' }, { name: '', rel: 'friend' }, { name: 'Leo', rel: 'friend' }, { name: 'Mia', rel: '' }];
  ok(JSON.stringify(F.composePeople(rows)) === JSON.stringify([{ name: 'Jenny', rel: 'child' }, { name: 'Leo', rel: 'friend' }, { name: 'Mia', rel: '' }]), 'composePeople: trimmed, empty names dropped');
  ok(F.composePeopleText(rows) === 'Jenny (child), Leo (friend), Mia', 'composePeopleText: "Jenny (child), Leo (friend), Mia"');
  ok(F.composePeople([{ name: 'x'.repeat(40), rel: 'other' }])[0].name.length === 30, 'a name is cut at 30');
}
if (F.peopleFromData) {
  ok(JSON.stringify(F.peopleFromData({ key_people: [{ name: 'Jenny', rel: 'child' }], key_people_text: 'ignored' })) === JSON.stringify([{ name: 'Jenny', rel: 'child' }]), 'saved rows win over the text');
  ok(JSON.stringify(F.peopleFromData({ key_people_text: 'Jenny, Leo ,, Mia' })) === JSON.stringify([{ name: 'Jenny', rel: '' }, { name: 'Leo', rel: '' }, { name: 'Mia', rel: '' }]), 'an old row\'s text becomes names with no role yet');
  ok(JSON.stringify(F.peopleFromData({})) === '[]', 'nothing saved: no rows');
}

// The picks page and the submit.
const page = cut(js, '  function renderWhyPicks(', '\n  }\n') || cut(js, '    root.innerHTML = \'<div class="hdlv2-card hdlv2-why">\'', 'updatePicks();\n  }\n');
ok(page.indexOf("whoForHtml(peopleFromData(d))") > 0 && page.indexOf("picksTextarea('key_people_text'") === -1, 'the picks page renders the rows, not the textarea');
ok(page.indexOf("picksTextarea('own_words'") > 0, 'own_words keeps its textarea');
ok(page.indexOf('bindWhoFor();') > 0, 'the rows are wired after render');
const submit = cut(js, '  function submitWhyPicks(', '\n  }\n');
ok(/formData\.key_people = composePeople\(who\);/.test(submit) && /formData\.key_people_text = composePeopleText\(who\);/.test(submit), 'submit sends key_people and key_people_text from the rows');
ok(submit.indexOf("['own_words'].forEach") > 0, 'submit still reads own_words');
ok(submit.indexOf('Please pick who each person is.') > 0 && submit.indexOf('focus()') > 0, 'a name with no role: a line and the focus, no save');
ok(js.indexOf("'Who are you doing this for? First names.'") === -1, 'the old label is gone');

// The timeline.
const tl = fs.readFileSync(new URL('../../assets/js/hdlv2-healthspan-timeline.js', import.meta.url), 'utf8');
const ctx = { document: { getElementById: () => null, querySelector: () => null }, console };
ctx.window = ctx;
vm.runInNewContext(tl, ctx);
const M = ctx.HDLV2HealthspanTimeline;
const you = { name: 'Kim', born: 1970, sex: 'F', bio: '', rate0: 1 };
M.reset({ mode: 'full', you, key_people: [{ name: 'Jenny', rel: 'child' }, { name: 'Bob', rel: 'nope' }, { name: '', rel: 'friend' }] });
let P = M.state().people;
ok(P.length === 2 && P[0].name === 'Jenny' && P[0].rel === 'child' && P[0].rate === 'same' && P[1].rel === 'other', 'full mode: the named people are the Loved ones (an odd role reads other, a blank name dropped)');
ok(P[0].born === 1995 && P.every((p) => p.born > 1850 && p.born < 2200), 'each gets a birth year from the role, so the lane can draw (editable on the page)');
M.reset({ mode: 'full', you });
ok(M.state().people.map((p) => p.name).join() === 'Spouse,Child,Grandchild', 'no list: the sample people as before');
M.reset({ mode: 'preview', you, key_people: [{ name: 'Jenny', rel: 'child' }] });
ok(M.state().people.map((p) => p.name).join() === 'Spouse,Child,Grandchild', 'preview: the sample people as before');
// restore(): a list already kept in this browser wins over the seed.
const restore = cut(tl, '  function restore() {', '\n  }\n'), clean = cut(tl, '  function cleanPerson(', '\n  }\n');
const R = { S: { mode: 'full', people: [{ name: 'Jenny' }], you: {} }, STORE: 'k', $: () => null,
  root: { localStorage: { getItem: () => JSON.stringify({ people: [{ id: 'p9', name: 'Kept', rel: 'friend', born: 1980, sex: 'M', rate: 'same' }] }) } }, REL: ['spouse', 'child', 'grandchild', 'parent', 'sibling', 'friend', 'other'] };
vm.runInNewContext(clean + restore + '\nrestore();', R);
ok(R.S.people.length === 1 && R.S.people[0].name === 'Kept', 'a stored list wins over the seed');

console.log(`\nPASS=${PASS} FAIL=${FAIL}`);
process.exit(FAIL ? 1 : 0);
