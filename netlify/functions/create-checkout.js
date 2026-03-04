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

        // H-2: Validate priceId against server-side allowlist
        const ALLOWED_PRICE_IDS = new Set([
            // Course one-time
            'price_1T0RzZAARZOPki7ntFFvn9sT', // super_early
            'price_1T0MrLAARZOPki7nfXckkNqh', // founding
            'price_1T0MrKAARZOPki7nJre4xDFt', // standard
            // Course installments
            'price_1T0RzZAARZOPki7nqvhHqbht', // installment_super_early
            'price_1T0RzaAARZOPki7nkFk4aiEm', // installment_founding
            'price_1T0MrLAARZOPki7ncedlhLG6', // installment_standard
            // Signature one-time
            'price_1T0RzZAARZOPki7nq98AvjGG', // super_early
            'price_1T0MrMAARZOPki7n9cinYvKE', // founding
            'price_1T0MrMAARZOPki7nDfv04ySN', // standard
            // Signature installments
            'price_1T0RzaAARZOPki7ntdAHvkXt', // installment_super_early
            'price_1T0RzaAARZOPki7nTEd3sEln', // installment_founding
            'price_1T0MrNAARZOPki7nZzc5Tjoo', // installment_standard
            // Subscriptions
            'price_1T0MrIAARZOPki7nIC4fsZ8h', // launchpad
            'price_1T0MrJAARZOPki7nT8Lwbckc', // minimum
            // Consumer
            'price_1T2KlHAARZOPki7nx77wtLhj', // consumer_single
            'price_1T3944AARZOPki7nWYs5ZCeK', // consumer_annual
        ]);
        if (!ALLOWED_PRICE_IDS.has(priceId)) {
            return { statusCode: 400, body: 'Invalid priceId' };
        }

        // H-3: Bound trialDays and installments to expected values
        const ALLOWED_TRIAL_DAYS = [0, 90];
        const ALLOWED_INSTALLMENTS = [0, 3, 6];
        const safeTrial = ALLOWED_TRIAL_DAYS.includes(Number(trialDays) || 0) ? (Number(trialDays) || 0) : 0;
        const safeInstallments = ALLOWED_INSTALLMENTS.includes(Number(installments) || 0) ? (Number(installments) || 0) : 0;

        // M-6: Validate mode and recurringInterval
        const ALLOWED_MODES = ['payment', 'subscription'];
        const safeMode = ALLOWED_MODES.includes(mode) ? mode : 'payment';
        const ALLOWED_INTERVALS = ['month', 'year'];
        const safeInterval = ALLOWED_INTERVALS.includes(recurringInterval) ? recurringInterval : 'month';

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
            if (safeMode === 'subscription') {
                priceData.recurring = { interval: safeInterval };
            }

            sessionParams = {
                payment_method_types: ['card'],
                line_items: [{ price_data: priceData, quantity: 1 }],
                mode: safeMode,
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
                mode: safeMode,
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
        if (safeTrial > 0 && safeMode === 'subscription') {
            sessionParams.subscription_data = {
                trial_period_days: safeTrial,
                trial_settings: {
                    end_behavior: { missing_payment_method: 'cancel' },
                },
            };
        }

        // Installment plans: add visible message on checkout page and
        // metadata on the subscription for auto-cancellation webhook
        if (safeInstallments > 0 && safeMode === 'subscription') {
            const sym = curr === 'GBP' ? '£' : curr + ' ';
            // Use Stripe price as source of truth for display amounts
            const pObj = sessionParams.line_items[0].price_data
                ? null
                : await stripe.prices.retrieve(priceId);
            const unitAmountPence = sessionParams.line_items[0].price_data
                ? sessionParams.line_items[0].price_data.unit_amount
                : pObj.unit_amount;
            let displayAmount = (unitAmountPence / 100).toFixed(2);
            let displayTotal = `${sym}${((unitAmountPence / 100) * safeInstallments).toFixed(2)}`;

            sessionParams.subscription_data = {
                ...sessionParams.subscription_data,
                description: `${safeInstallments} monthly payments for ${tierName}`,
                metadata: {
                    installments: safeInstallments.toString(),
                    tier: tierName,
                },
            };
            sessionParams.custom_text = {
                submit: {
                    message: `Payment plan: ${safeInstallments} monthly payments of ${sym}${displayAmount || '—'}. Your subscription will automatically end after ${safeInstallments} months (total ${displayTotal}).`,
                },
            };
        }

        // Ensure subscription metadata carries tier info for renewal webhooks
        if (tier && safeMode === 'subscription') {
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
