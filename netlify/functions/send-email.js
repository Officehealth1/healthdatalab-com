const nodemailer = require('nodemailer');

const transporter = nodemailer.createTransport({
  host: 'smtp-relay.brevo.com',
  port: 587,
  secure: false,
  auth: {
    user: process.env.BREVO_SMTP_LOGIN,
    pass: process.env.BREVO_SMTP_KEY,
  },
});

// H-6: In-memory rate limiter (effective within warm Lambda instances)
const rateMap = new Map();
const RATE_WINDOW = 60 * 1000; // 1 minute
const RATE_LIMIT = 3; // max 3 emails per IP per minute

function checkRateLimit(ip) {
  const now = Date.now();
  const entry = rateMap.get(ip);
  if (!entry || now - entry.start > RATE_WINDOW) {
    rateMap.set(ip, { start: now, count: 1 });
    return true;
  }
  entry.count++;
  return entry.count <= RATE_LIMIT;
}

exports.handler = async (event) => {
  if (event.httpMethod !== 'POST') {
    return { statusCode: 405, body: 'Method Not Allowed' };
  }

  // H-6: Rate limiting by client IP
  const clientIp = event.headers['x-nw-client-ip'] || event.headers['client-ip'] || 'unknown';
  if (!checkRateLimit(clientIp)) {
    return { statusCode: 429, body: JSON.stringify({ error: 'Too many requests. Please try again later.' }) };
  }

  try {
    const { name, email, subject, message, botField } = JSON.parse(event.body);

    // Honeypot check
    if (botField) {
      return { statusCode: 200, body: JSON.stringify({ success: true }) };
    }

    if (!name || !email || !message) {
      return { statusCode: 400, body: JSON.stringify({ error: 'Missing required fields' }) };
    }

    // M-9: Input length validation
    if (name.length > 100 || email.length > 254 || (subject && subject.length > 200) || message.length > 5000) {
      return { statusCode: 400, body: JSON.stringify({ error: 'Input exceeds maximum length' }) };
    }

    // Basic email format check
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      return { statusCode: 400, body: JSON.stringify({ error: 'Invalid email' }) };
    }

    const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    const safeName = esc(name).replace(/[\r\n]/g, '');
    const safeEmail = esc(email);
    const safeSubject = esc(subject || 'General inquiry');

    await transporter.sendMail({
      from: '"HealthDataLab" <office@healthdatalab.com>',
      to: 'office@healthdatalab.com',
      replyTo: { name: safeName, address: email },
      subject: '[HDL Contact] ' + safeSubject,
      html: '<h3>New contact form submission</h3>'
        + '<p><strong>Name:</strong> ' + safeName + '</p>'
        + '<p><strong>Email:</strong> ' + safeEmail + '</p>'
        + '<p><strong>Subject:</strong> ' + safeSubject + '</p>'
        + '<hr>'
        + '<p>' + esc(message).replace(/\n/g, '<br>') + '</p>',
    });

    return {
      statusCode: 200,
      body: JSON.stringify({ success: true }),
    };
  } catch (error) {
    console.error('Send email error:', error);
    return {
      statusCode: 500,
      body: JSON.stringify({ error: 'Failed to send email' }),
    };
  }
};
