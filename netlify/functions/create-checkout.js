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
        const { priceId, mode, successUrl, cancelUrl, trialDays, installments, tierName, installmentAmount, currency, baseAmountGBP, recurringInterval, tier } = JSON.parse(event.body);

        if (!priceId) {
            return { statusCode: 400, body: 'Missing priceId' };
        }

        const curr = (currency || 'GBP').toUpperCase();
        const isNonGBP = curr !== 'GBP' && baseAmountGBP;

        let sessionParams;

        if (isNonGBP) {
            // Dynamic pricing for non-GBP currencies
            const rate = await getExchangeRate(curr);
            if (!rate) throw new Error(`Unsupported currency: ${curr}`);

            const convertedAmount = Math.round(baseAmountGBP * rate);

            // Get product ID from the original price
            const priceObj = await stripe.prices.retrieve(priceId);
            const productId = priceObj.product;

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
            let displayAmount = installmentAmount;
            let displayTotal;

            if (isNonGBP) {
                const rate = await getExchangeRate(curr);
                displayAmount = Math.round(installmentAmount * rate * 100) / 100;
                displayTotal = `${sym}${(displayAmount * installments).toFixed(2)}`;
            } else {
                displayTotal = installmentAmount ? `£${installmentAmount * installments}` : `${installments} payments`;
            }

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

        const session = await stripe.checkout.sessions.create(sessionParams);

        return {
            statusCode: 200,
            body: JSON.stringify({ sessionId: session.id }),
        };
    } catch (error) {
        console.error('Stripe Error:', error);
        return {
            statusCode: 500,
            body: JSON.stringify({ error: error.message }),
        };
    }
};
