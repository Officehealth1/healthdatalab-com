const stripe = require('stripe')(process.env.STRIPE_SK);

const endpointSecret = process.env.STRIPE_WEBHOOK_SECRET;

exports.handler = async (event) => {
    if (event.httpMethod !== 'POST') {
        return { statusCode: 405, body: 'Method Not Allowed' };
    }

    let stripeEvent;

    // Verify webhook signature if secret is configured
    if (endpointSecret) {
        const sig = event.headers['stripe-signature'];
        try {
            stripeEvent = stripe.webhooks.constructEvent(event.body, sig, endpointSecret);
        } catch (err) {
            console.error('Webhook signature verification failed:', err.message);
            return { statusCode: 400, body: `Webhook Error: ${err.message}` };
        }
    } else {
        stripeEvent = JSON.parse(event.body);
    }

    // Handle invoice.paid events for installment auto-cancellation
    if (stripeEvent.type === 'invoice.paid') {
        const invoice = stripeEvent.data.object;
        const subscriptionId = invoice.subscription;

        if (!subscriptionId) {
            return { statusCode: 200, body: 'No subscription on invoice' };
        }

        try {
            const subscription = await stripe.subscriptions.retrieve(subscriptionId);
            const maxInstallments = parseInt(subscription.metadata.installments, 10);

            // Only process subscriptions that have an installments limit
            if (!maxInstallments || isNaN(maxInstallments)) {
                return { statusCode: 200, body: 'Not an installment subscription' };
            }

            // Count paid invoices for this subscription (excluding $0 trial invoices)
            const invoices = await stripe.invoices.list({
                subscription: subscriptionId,
                status: 'paid',
                limit: 100,
            });
            const paidCount = invoices.data.filter(inv => inv.amount_paid > 0).length;

            console.log(`Subscription ${subscriptionId}: ${paidCount}/${maxInstallments} payments made`);

            if (paidCount >= maxInstallments) {
                console.log(`Cancelling subscription ${subscriptionId} after ${paidCount} payments`);
                await stripe.subscriptions.cancel(subscriptionId);
            }
        } catch (err) {
            console.error('Error processing installment check:', err.message);
            return { statusCode: 500, body: `Error: ${err.message}` };
        }
    }

    return { statusCode: 200, body: 'OK' };
};
