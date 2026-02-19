const stripe = require('stripe')(process.env.STRIPE_SK);
const nodemailer = require('nodemailer');

const endpointSecret = process.env.STRIPE_WEBHOOK_SECRET;

const transporter = nodemailer.createTransport({
  host: 'smtp-relay.brevo.com',
  port: 587,
  secure: false,
  auth: {
    user: process.env.BREVO_SMTP_LOGIN,
    pass: process.env.BREVO_SMTP_KEY,
  },
});

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

    // Handle checkout.session.completed — send confirmation email
    if (stripeEvent.type === 'checkout.session.completed') {
        const session = stripeEvent.data.object;
        const customerEmail = session.customer_details?.email;
        const amountTotal = session.amount_total; // in pence
        const currency = (session.currency || 'gbp').toUpperCase();

        // Retrieve line items to get product name
        let productName = 'HealthDataLab Product';
        try {
            const lineItems = await stripe.checkout.sessions.listLineItems(session.id, { limit: 1 });
            if (lineItems.data.length > 0) {
                productName = lineItems.data[0].description || productName;
            }
        } catch (err) {
            console.error('Error retrieving line items:', err.message);
        }

        const amountFormatted = amountTotal != null
            ? `${currency === 'GBP' ? '£' : currency + ' '}${(amountTotal / 100).toFixed(2)}`
            : 'See your Stripe receipt';

        if (customerEmail) {
            const customerHtml = `
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1a1a2e; max-width: 600px; margin: 0 auto; padding: 20px;">
  <div style="text-align: center; margin-bottom: 32px;">
    <h1 style="font-size: 24px; font-weight: 700; color: #1a1a2e; margin: 0;">HealthDataLab</h1>
  </div>
  <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 16px; padding: 24px; text-align: center; margin-bottom: 24px;">
    <p style="font-size: 18px; font-weight: 600; color: #166534; margin: 0;">Thank you for your purchase!</p>
  </div>
  <table style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
    <tr>
      <td style="padding: 12px 0; border-bottom: 1px solid #e5e7eb; color: #6b7280;">Product</td>
      <td style="padding: 12px 0; border-bottom: 1px solid #e5e7eb; text-align: right; font-weight: 600;">${productName}</td>
    </tr>
    <tr>
      <td style="padding: 12px 0; border-bottom: 1px solid #e5e7eb; color: #6b7280;">Amount</td>
      <td style="padding: 12px 0; border-bottom: 1px solid #e5e7eb; text-align: right; font-weight: 600;">${amountFormatted}</td>
    </tr>
  </table>
  <p style="color: #374151; line-height: 1.6;">We'll be in touch shortly with your next steps. If you have any questions, reply to this email or contact us at <a href="mailto:office@healthdatalab.com" style="color: #10b981;">office@healthdatalab.com</a>.</p>
  <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 32px 0;">
  <p style="font-size: 12px; color: #9ca3af; text-align: center;">HealthDataLab &mdash; Data-driven health insights for practitioners and individuals.</p>
</body>
</html>`;

            // Send confirmation to customer
            try {
                await transporter.sendMail({
                    from: '"HealthDataLab" <office@healthdatalab.com>',
                    to: customerEmail,
                    subject: `Your HealthDataLab purchase: ${productName}`,
                    html: customerHtml,
                });
                console.log(`Confirmation email sent to ${customerEmail}`);
            } catch (err) {
                console.error('Error sending customer email:', err.message);
            }

            // Send notification to office
            try {
                await transporter.sendMail({
                    from: '"HealthDataLab" <office@healthdatalab.com>',
                    to: 'office@healthdatalab.com',
                    subject: `[New Sale] ${productName} — ${amountFormatted}`,
                    html: `<h3>New purchase</h3>
<p><strong>Customer:</strong> ${customerEmail}</p>
<p><strong>Product:</strong> ${productName}</p>
<p><strong>Amount:</strong> ${amountFormatted}</p>
<p><strong>Session ID:</strong> ${session.id}</p>`,
                });
                console.log('Office notification sent');
            } catch (err) {
                console.error('Error sending office notification:', err.message);
            }
        }
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
