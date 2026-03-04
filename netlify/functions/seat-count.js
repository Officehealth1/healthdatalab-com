const stripe = require('stripe')(process.env.STRIPE_SK);

// All Super Early price IDs (Course + Signature, one-time + installment)
const SUPER_EARLY_PRICES = new Set([
  'price_1T0RzZAARZOPki7ntFFvn9sT',  // Course one-time
  'price_1T0RzZAARZOPki7nqvhHqbht',  // Course installment
  'price_1T0RzZAARZOPki7nq98AvjGG',  // Signature one-time
  'price_1T0RzaAARZOPki7ntdAHvkXt',  // Signature installment
]);

const TOTAL_SEATS = 25;
// H-9: Limit pagination — super early pricing started Feb 2026
const CREATED_AFTER = Math.floor(new Date('2026-02-01').getTime() / 1000);
// Safety cap to prevent runaway API calls
const MAX_PAGES = 10;

// M-7: Restrict CORS to healthdatalab.com
const ALLOWED_ORIGINS = ['https://healthdatalab.com', 'https://www.healthdatalab.com'];

exports.handler = async (event) => {
  const origin = event.headers?.origin || '';
  const corsOrigin = ALLOWED_ORIGINS.includes(origin) ? origin : ALLOWED_ORIGINS[0];

  try {
    let sold = 0;
    let hasMore = true;
    let startingAfter;
    let pages = 0;

    while (hasMore && pages < MAX_PAGES) {
      const params = { status: 'complete', limit: 100, created: { gte: CREATED_AFTER } };
      if (startingAfter) params.starting_after = startingAfter;

      const sessions = await stripe.checkout.sessions.list(params);
      pages++;

      for (const session of sessions.data) {
        const lineItems = await stripe.checkout.sessions.listLineItems(session.id);
        if (lineItems.data.some(item => SUPER_EARLY_PRICES.has(item.price.id))) {
          sold++;
        }
      }

      hasMore = sessions.has_more;
      if (sessions.data.length > 0) {
        startingAfter = sessions.data[sessions.data.length - 1].id;
      }
    }

    return {
      statusCode: 200,
      headers: {
        'Content-Type': 'application/json',
        'Access-Control-Allow-Origin': corsOrigin,
        'Cache-Control': 'public, max-age=300',
      },
      body: JSON.stringify({
        total: TOTAL_SEATS,
        sold,
        remaining: Math.max(0, TOTAL_SEATS - sold),
      }),
    };
  } catch (error) {
    console.error('Seat count error:', error);
    return {
      statusCode: 500,
      headers: { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': corsOrigin },
      body: JSON.stringify({ error: 'Failed to fetch seat count' }),
    };
  }
};
