const stripe = require('stripe')(process.env.STRIPE_SK);

// All Super Early price IDs (Course + Signature, one-time + installment)
const SUPER_EARLY_PRICES = [
  'price_1T0RzZAARZOPki7ntFFvn9sT',  // Course one-time
  'price_1T0RzZAARZOPki7nqvhHqbht',  // Course installment
  'price_1T0RzZAARZOPki7nq98AvjGG',  // Signature one-time
  'price_1T0RzaAARZOPki7ntdAHvkXt',  // Signature installment
];

const TOTAL_SEATS = 25;

exports.handler = async () => {
  try {
    let sold = 0;
    let hasMore = true;
    let startingAfter;

    while (hasMore) {
      const params = { status: 'complete', limit: 100 };
      if (startingAfter) params.starting_after = startingAfter;

      const sessions = await stripe.checkout.sessions.list(params);

      for (const session of sessions.data) {
        const lineItems = await stripe.checkout.sessions.listLineItems(session.id);
        if (lineItems.data.some(item => SUPER_EARLY_PRICES.includes(item.price.id))) {
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
        'Access-Control-Allow-Origin': '*',
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
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ error: error.message }),
    };
  }
};
