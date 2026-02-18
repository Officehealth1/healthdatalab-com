const stripe = require('stripe')(process.env.STRIPE_SK);

exports.handler = async (event) => {
    if (event.httpMethod !== 'POST') {
        return { statusCode: 405, body: 'Method Not Allowed' };
    }

    try {
        const { priceId, mode, successUrl, cancelUrl, trialDays, installments, tierName, installmentAmount, tier } = JSON.parse(event.body);

        if (!priceId) {
            return { statusCode: 400, body: 'Missing priceId' };
        }

        const sessionParams = {
            payment_method_types: ['card'],
            line_items: [{ price: priceId, quantity: 1 }],
            mode: mode || 'payment',
            success_url: successUrl,
            cancel_url: cancelUrl,
            automatic_tax: { enabled: true },
            allow_promotion_codes: tier === 'consumer_single',
        };

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
            sessionParams.subscription_data = {
                ...sessionParams.subscription_data,
                description: `${installments} monthly payments for ${tierName}`,
                metadata: {
                    installments: installments.toString(),
                    tier: tierName,
                },
            };
            const total = installmentAmount ? `£${installmentAmount * installments}` : `${installments} payments`;
            sessionParams.custom_text = {
                submit: {
                    message: `Payment plan: ${installments} monthly payments of £${installmentAmount || '—'}. Your subscription will automatically end after ${installments} months (total ${total}).`,
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
