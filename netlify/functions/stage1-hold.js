// Stage 1 holding list (v0.48.16). When healthdatalab.net is busy or does not
// answer, the widget posts the same answers here; they are kept as a Brevo
// contact on the "Stage 1 waiting" list, and WordPress replays them into the
// lead route when it is free (HDLV2_Stage1_Waiting). Pattern: Altituding's
// brevo-priority-submit.js. This open endpoint never sends an email to anyone.
//
// Env: BREVO_API_KEY, BREVO_WAITING_LIST_ID (absent → 503, the widget shows
// its usual "not saved yet" message).

const CORS = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
  'Access-Control-Allow-Headers': 'Content-Type',
};
const ATTRIBUTES = [
  { name: 'HDL_STAGE1_PAYLOAD', type: 'text' },
  { name: 'HDL_STAGE1_HELD_AT', type: 'date' },
];
const ANSWERS = ['q1_sex', 'q2a', 'q2b', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8', 'q9'];

// ponytail: per-instance memory, as send-email.js; a shared store if abuse shows in the Brevo log.
const rateMap = new Map();
const RATE_WINDOW_MS = 60 * 1000;
const RATE_LIMIT = 10;
function checkRateLimit(ip, now = Date.now()) {
  const entry = rateMap.get(ip);
  if (!entry || now - entry.start > RATE_WINDOW_MS) {
    rateMap.set(ip, { start: now, count: 1 });
    return true;
  }
  entry.count += 1;
  return entry.count <= RATE_LIMIT;
}

// Tests replace deps.brevo; production calls the Brevo API.
const deps = {
  brevo: async (method, path, body) => {
    const res = await fetch('https://api.brevo.com' + path, {
      method,
      headers: { accept: 'application/json', 'content-type': 'application/json', 'api-key': process.env.BREVO_API_KEY },
      body: body || undefined,
      signal: AbortSignal.timeout(4000),
    });
    return { status: res.status, body: await res.text(), headers: Object.fromEntries(res.headers) };
  },
};

function reply(statusCode, body, extra) {
  return { statusCode, headers: Object.assign({ 'Content-Type': 'application/json' }, CORS, extra || {}), body: body === null ? '' : JSON.stringify(body) };
}
const str = (v, max) => String(v == null ? '' : v).replace(/[\r\n\t]+/g, ' ').trim().slice(0, max);

// Only the keys the lead route reads, each capped. Null when the answers cannot be a lead.
function cleanPayload(d) {
  const practitionerId = Number(d.practitioner_id);
  const email = str(d.email, 254).toLowerCase();
  const age = Number(d.q1_age);
  if (!Number.isInteger(practitionerId) || practitionerId < 1) return null;
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return null;
  if (!Number.isInteger(age) || age < 10 || age > 120) return null;
  const out = { practitioner_id: practitionerId, name: str(d.name, 200), email, phone: str(d.phone, 40), q1_age: age };
  ANSWERS.forEach((k) => { if (d[k] != null && d[k] !== '') out[k] = typeof d[k] === 'number' ? d[k] : str(d[k], 64); });
  if (d.rate_of_ageing_result != null) {
    const rate = Number(d.rate_of_ageing_result);
    if (!(rate >= 0.5 && rate <= 2.0)) return null;
    out.rate_of_ageing_result = rate;
  }
  if (/^[A-Za-z]{2}$/.test(String(d.visitor_country || ''))) {
    out.visitor_country = String(d.visitor_country).toUpperCase();
    if (d.visitor_region) out.visitor_region = str(d.visitor_region, 64);
  }
  return out;
}

let attributesEnsured = false;
async function ensureAttributes() {
  if (attributesEnsured) return;
  attributesEnsured = true;
  // 400 "already exists" is the normal answer after the first run; never fatal.
  await Promise.all(ATTRIBUTES.map(({ name, type }) =>
    deps.brevo('POST', '/v3/contacts/attributes/normal/' + name, JSON.stringify({ type })).catch(() => null)));
}

async function upsert(body) {
  let res = await deps.brevo('POST', '/v3/contacts', body);
  if (res.status === 429) {
    const wait = Math.min(2, Number(res.headers['retry-after'] || res.headers['x-sib-ratelimit-reset'] || 1)) * 1000;
    await new Promise((r) => setTimeout(r, wait));
    res = await deps.brevo('POST', '/v3/contacts', body);
  }
  return res;
}

exports.handler = async (event) => {
  const listId = parseInt(process.env.BREVO_WAITING_LIST_ID || '', 10) || null;
  if (event.httpMethod === 'OPTIONS') return reply(204, null);
  if (event.httpMethod === 'GET') return reply(200, { ok: true, list: listId });
  if (event.httpMethod !== 'POST') return reply(405, { error: 'Method Not Allowed' });

  const h = event.headers || {};
  const ip = String(h['cf-connecting-ip'] || h['x-nf-client-connection-ip'] || h['x-forwarded-for'] || 'unknown').split(',')[0].trim();
  if (!checkRateLimit(ip)) return reply(429, { error: 'Too many requests. Please try again in a minute.' }, { 'Retry-After': '60' });

  if (!listId || !process.env.BREVO_API_KEY) return reply(503, { error: 'Not configured.' });

  let data;
  try { data = JSON.parse(event.body || '{}'); } catch (e) { return reply(400, { error: 'Invalid JSON' }); }
  if (!data || typeof data !== 'object') return reply(400, { error: 'Invalid JSON' });
  if (data.website) return reply(200, { held: true }); // honeypot: bots get a quiet success, nothing stored

  // Safety-screen answers (self-harm, serious symptoms) never leave healthdatalab.net.
  if (data.safety && Object.keys(data.safety).length) return reply(422, { error: 'safety_not_held' });

  const payload = cleanPayload(data);
  if (!payload) return reply(400, { error: 'These answers could not be accepted.' });
  const json = JSON.stringify(payload);
  if (json.length > 4096) return reply(400, { error: 'Too long.' });

  try {
    await ensureAttributes();
    const res = await upsert(JSON.stringify({
      email: payload.email,
      attributes: { HDL_STAGE1_PAYLOAD: json, HDL_STAGE1_HELD_AT: new Date().toISOString().slice(0, 10) },
      listIds: [listId],
      updateEnabled: true,
    }));
    if (res.status === 429) return reply(503, { error: 'Busy. Please try again in a minute.' }, { 'Retry-After': '60' });
    if (res.status < 200 || res.status >= 300) {
      console.error('stage1-hold: Brevo upsert ' + res.status);
      return reply(502, { error: 'Your answers could not be kept. Please try again.' });
    }
  } catch (err) {
    console.error('stage1-hold: Brevo unreachable', (err && err.name) || 'error');
    return reply(502, { error: 'Your answers could not be kept. Please try again.' });
  }
  return reply(202, { held: true });
};

exports.deps = deps;
