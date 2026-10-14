#!/usr/bin/env node
/**
 * netlify/functions/stage1-hold.js (v0.48.16) — the holding address the widget
 * posts to when healthdatalab.net is busy. Brevo is injected through
 * deps.brevo (the Altituding lib/stage1-ticket.js way); nothing leaves this machine.
 *
 * Run:  node tests/stage1-spike/test-hold-fn.js   (exit 0/1)
 */
'use strict';
const path = require('path');

let PASS = 0, FAIL = 0;
function ok(cond, label) { (cond ? PASS++ : FAIL++); console.log((cond ? 'PASS  ' : 'FAIL  ') + label); }

let fn;
try { fn = require(path.join(__dirname, '..', '..', '..', 'netlify', 'functions', 'stage1-hold.js')); } catch (e) { fn = null; }
ok(!!fn && typeof fn.handler === 'function' && fn.deps, 'stage1-hold.js exports handler + deps');
if (!fn) { console.log('\nPASS=' + PASS + ' FAIL=' + FAIL); process.exit(1); }

process.env.BREVO_API_KEY = 'test-key';
process.env.BREVO_WAITING_LIST_ID = '77';

let calls = [];
let answers = [];
fn.deps.brevo = async function (method, p, body) {
  calls.push({ method, path: p, body: body ? JSON.parse(body) : null });
  return answers.length ? answers.shift() : { status: 201, body: '{}', headers: {} };
};
let ipN = 0;
function ev(method, body, ip) {
  return { httpMethod: method, headers: { 'cf-connecting-ip': ip || ('198.51.100.' + (++ipN)) }, body: body === undefined ? '' : (typeof body === 'string' ? body : JSON.stringify(body)) };
}
function lead(over) {
  return Object.assign({ practitioner_id: 122, name: 'Spike Test', email: 'spike@example.test', phone: '07700 900000',
    q1_age: 44, q1_sex: 'male', q2a: 3, q2b: 'even', q3: 'b', q4: 'b', q5: 'b', q6: 'b', q7: 'a', q8: 'b', q9: 'b',
    rate_of_ageing_result: 1.02, visitor_country: 'GB', visitor_region: 'England' }, over || {});
}
const upserts = () => calls.filter(c => c.method === 'POST' && c.path === '/v3/contacts');
const J = r => { try { return JSON.parse(r.body); } catch (e) { return null; } };

(async function () {
  let r = await fn.handler(ev('GET'));
  ok(r.statusCode === 200 && J(r).ok === true && String(J(r).list) === '77', 'GET → 200 { ok, list } (health)');
  r = await fn.handler(ev('OPTIONS'));
  ok(r.statusCode === 204 && r.headers['Access-Control-Allow-Origin'] === '*', 'OPTIONS → 204 with CORS *');
  r = await fn.handler(ev('PUT', {}));
  ok(r.statusCode === 405, 'PUT → 405');

  delete process.env.BREVO_WAITING_LIST_ID;
  r = await fn.handler(ev('POST', lead()));
  ok(r.statusCode === 503, 'no list id configured → 503');
  process.env.BREVO_WAITING_LIST_ID = '77';

  calls = [];
  r = await fn.handler(ev('POST', lead({ website: 'http://spam' })));
  ok(r.statusCode === 200 && upserts().length === 0, 'honeypot → 200, no Brevo call');
  r = await fn.handler(ev('POST', '{nope'));
  ok(r.statusCode === 400, 'bad JSON → 400');
  r = await fn.handler(ev('POST', lead({ practitioner_id: 0 })));
  ok(r.statusCode === 400, 'missing practitioner_id → 400');
  r = await fn.handler(ev('POST', lead({ email: 'not-an-email' })));
  ok(r.statusCode === 400, 'bad email → 400');
  r = await fn.handler(ev('POST', lead({ q1_age: 9 })));
  ok(r.statusCode === 400, 'q1_age below 10 → 400');
  r = await fn.handler(ev('POST', lead({ rate_of_ageing_result: 2.5 })));
  ok(r.statusCode === 400, 'rate outside 0.5–2.0 → 400');
  r = await fn.handler(ev('POST', lead({ safety: { q1: 'yes' } })));
  ok(r.statusCode === 422 && upserts().length === 0, 'safety answers are never held in Brevo → 422, no call');

  calls = [];
  r = await fn.handler(ev('POST', lead({ invite_token: 'a'.repeat(64), extra: 'x', name: 'N'.repeat(300), q3: 'q'.repeat(100), visitor_country: 'GBR' })));
  ok(r.statusCode === 202 && J(r).held === true, 'good POST → 202 { held: true }');
  const up = upserts()[0];
  ok(up && up.body.email === 'spike@example.test' && up.body.updateEnabled === true && JSON.stringify(up.body.listIds) === '[77]', 'one upsert: email, updateEnabled, listIds [77]');
  const stored = up ? JSON.parse(up.body.attributes.HDL_STAGE1_PAYLOAD) : {};
  ok(!('invite_token' in stored) && !('extra' in stored), 'stored JSON has no invite_token and no unknown key');
  ok(stored.name.length === 200 && stored.q3.length === 64 && !('visitor_country' in stored), 'caps: name 200, answers 64, country must be 2 letters');
  ok(stored.phone === '07700 900000' && stored.q1_age === 44 && stored.rate_of_ageing_result === 1.02, 'phone, age and rate kept');
  ok(up && /^\d{4}-\d{2}-\d{2}/.test(up.body.attributes.HDL_STAGE1_HELD_AT), 'HDL_STAGE1_HELD_AT is an ISO date');
  ok(up && up.body.attributes.HDL_STAGE1_PAYLOAD.length <= 4096, 'stored JSON ≤ 4 KB');
  ok(calls.every(c => !/smtp|email/i.test(c.path)), 'no email is sent by this function');

  calls = [];
  answers = [{ status: 429, body: '{}', headers: { 'x-sib-ratelimit-reset': '0' } }, { status: 201, body: '{}', headers: {} }];
  r = await fn.handler(ev('POST', lead()));
  ok(r.statusCode === 202 && upserts().length === 2, 'Brevo 429 → one retry → 202');
  calls = [];
  answers = [{ status: 429, body: '{}', headers: { 'x-sib-ratelimit-reset': '0' } }, { status: 429, body: '{}', headers: { 'x-sib-ratelimit-reset': '0' } }];
  r = await fn.handler(ev('POST', lead()));
  ok(r.statusCode === 503 && r.headers['Retry-After'] && upserts().length === 2, 'Brevo 429 twice → 503 with Retry-After');
  answers = [{ status: 500, body: 'x', headers: {} }];
  r = await fn.handler(ev('POST', lead()));
  ok(r.statusCode === 502 && J(r).error, 'Brevo 5xx → 502 { error }');

  let last;
  for (let i = 0; i < 11; i++) last = await fn.handler(ev('POST', lead(), '203.0.113.50'));
  ok(last.statusCode === 429, 'eleventh POST from one address inside a minute → 429');
  ok(last.headers['Access-Control-Allow-Origin'] === '*', 'every reply carries CORS * (the widget can read it)');

  console.log('\nPASS=' + PASS + ' FAIL=' + FAIL);
  process.exit(FAIL ? 1 : 0);
})();
