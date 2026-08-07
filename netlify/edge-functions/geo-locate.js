// /api/geo — coarse visitor location for the Stage 1 widget (v0.47.83).
//
// Returns { country: "US", region: "Texas" } from Netlify's built-in geo
// (same mechanism as geo-currency.js). Stores nothing, logs nothing, reads
// no request data — it only mirrors back where the caller's own IP appears
// to be. CORS is open because the widget runs on practitioner-owned domains
// we cannot whitelist in advance; the response contains no secrets and no
// data about anyone but the requester.
export default (request, context) => {
  const headers = {
    'content-type': 'application/json',
    'access-control-allow-origin': '*',
    'cache-control': 'no-store',
  };
  if (request.method === 'OPTIONS') {
    return new Response(null, { status: 204, headers });
  }
  const country = context.geo?.country?.code || '';
  const region = context.geo?.subdivision?.name || '';
  return new Response(JSON.stringify({ country, region }), { headers });
};

export const config = { path: '/api/geo' };
