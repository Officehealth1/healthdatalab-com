const stripe = require('stripe')(process.env.STRIPE_SK);

// Cache exchange rates for 1 hour
let rateCache = { rates: null, timestamp: 0 };
const RATE_TTL = 60 * 60 * 1000; // 1 hour

async function getExchangeRate(currency) {
    const now = Date.now();
    if (rateCache.rates && (now - rateCache.timestamp) < RATE_TTL) {
        return rateCache.rates[currency];
    }
    const res = await fetch('https://api.frankfurter.app/latest?from=GBP&to=USD,EUR,CHF,CAD,AUD');
    if (!res.ok) throw new Error('Failed to fetch exchange rates');
    const data = await res.json();
    rateCache = { rates: data.rates, timestamp: now };
    return data.rates[currency];
}

exports.handler = async (event) => {
    if (event.httpMethod !== 'POST') {
        return { statusCode: 405, body: 'Method Not Allowed' };
    }

    try {
        const { priceId, mode, successUrl, cancelUrl, trialDays, installments, tierName, installmentAmount, currency, baseAmountGBP, recurringInterval, tier, practitionerPreference } = JSON.parse(event.body);

        if (!priceId) {
            return { statusCode: 400, body: 'Missing priceId' };
        }

        // Validate redirect URLs against allowed origins
        const ALLOWED_ORIGINS = ['https://healthdatalab.com', 'https://www.healthdatalab.com'];
        if (successUrl && !ALLOWED_ORIGINS.some(o => successUrl.startsWith(o))) {
            return { statusCode: 400, body: 'Invalid successUrl' };
        }
        if (cancelUrl && !ALLOWED_ORIGINS.some(o => cancelUrl.startsWith(o))) {
            return { statusCode: 400, body: 'Invalid cancelUrl' };
        }

        const curr = (currency || 'GBP').toUpperCase();
        const isNonGBP = curr !== 'GBP' && baseAmountGBP;

        let sessionParams;

        if (isNonGBP) {
            // Dynamic pricing for non-GBP currencies
            const rate = await getExchangeRate(curr);
            if (!rate) throw new Error(`Unsupported currency: ${curr}`);

            // Get the REAL price from Stripe (server-side source of truth)
            const priceObj = await stripe.prices.retrieve(priceId);
            const productId = priceObj.product;
            const convertedAmount = Math.round(priceObj.unit_amount * rate);

            const priceData = {
                currency: curr.toLowerCase(),
                product: productId,
                unit_amount: convertedAmount,
            };

            // Add recurring interval for subscriptions
            if (mode === 'subscription') {
                priceData.recurring = { interval: recurringInterval || 'month' };
            }

            sessionParams = {
                payment_method_types: ['card'],
                line_items: [{ price_data: priceData, quantity: 1 }],
                mode: mode || 'payment',
                success_url: successUrl,
                cancel_url: cancelUrl,
                automatic_tax: { enabled: true },
                allow_promotion_codes: true,
            };
        } else {
            // Default GBP flow — use existing price IDs
            sessionParams = {
                payment_method_types: ['card'],
                line_items: [{ price: priceId, quantity: 1 }],
                mode: mode || 'payment',
                success_url: successUrl,
                cancel_url: cancelUrl,
                automatic_tax: { enabled: true },
                allow_promotion_codes: true,
            };
        }

        // Collect phone number and billing address at checkout
        sessionParams.phone_number_collection = { enabled: true };
        sessionParams.billing_address_collection = 'required';

        // Session-level metadata for consumer provisioning
        if (tier) {
            sessionParams.metadata = {
                tier: tier,
                practitioner_preference: practitionerPreference || 'no',
            };
        }

        // Free trial for Launchpad (collect card but don't charge during trial)
        if (trialDays > 0 && mode === 'subscription') {
            sessionParams.subscription_data = {
                trial_period_days: trialDays,
                trial_settings: {
                    end_behavior: { missing_payment_method: 'cancel' },
                },
            };
        }

        // Installment plans: add visible message on checkout page and
        // metadata on the subscription for auto-cancellation webhook
        if (installments > 0 && mode === 'subscription') {
            const sym = curr === 'GBP' ? '£' : curr + ' ';
            // Use Stripe price as source of truth for display amounts
            const pObj = sessionParams.line_items[0].price_data
                ? null
                : await stripe.prices.retrieve(priceId);
            const unitAmountPence = sessionParams.line_items[0].price_data
                ? sessionParams.line_items[0].price_data.unit_amount
                : pObj.unit_amount;
            let displayAmount = (unitAmountPence / 100).toFixed(2);
            let displayTotal = `${sym}${((unitAmountPence / 100) * installments).toFixed(2)}`;

            sessionParams.subscription_data = {
                ...sessionParams.subscription_data,
                description: `${installments} monthly payments for ${tierName}`,
                metadata: {
                    installments: installments.toString(),
                    tier: tierName,
                },
            };
            sessionParams.custom_text = {
                submit: {
                    message: `Payment plan: ${installments} monthly payments of ${sym}${displayAmount || '—'}. Your subscription will automatically end after ${installments} months (total ${displayTotal}).`,
                },
            };
        }

        // Ensure subscription metadata carries tier info for renewal webhooks
        if (tier && mode === 'subscription') {
            sessionParams.subscription_data = {
                ...(sessionParams.subscription_data || {}),
                metadata: {
                    ...(sessionParams.subscription_data?.metadata || {}),
                    tier: tier,
                    practitioner_preference: practitionerPreference || 'no',
                },
            };
        }

        const session = await stripe.checkout.sessions.create(sessionParams);

        return {
            statusCode: 200,
            body: JSON.stringify({ sessionId: session.id }),
        };
    } catch (error) {
        console.error('Stripe Error:', error);
        return {
            statusCode: 500,
            body: JSON.stringify({ error: 'An error occurred processing your request.' }),
        };
    }
};
