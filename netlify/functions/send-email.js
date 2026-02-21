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

exports.handler = async (event) => {
  if (event.httpMethod !== 'POST') {
    return { statusCode: 405, body: 'Method Not Allowed' };
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

    // Basic email format check
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      return { statusCode: 400, body: JSON.stringify({ error: 'Invalid email' }) };
    }

    await transporter.sendMail({
      from: '"HealthDataLab" <office@healthdatalab.com>',
      to: 'office@healthdatalab.com',
      replyTo: { name: name, address: email },
      subject: '[HDL Contact] ' + (subject || 'General inquiry'),
      html: '<h3>New contact form submission</h3>'
        + '<p><strong>Name:</strong> ' + name + '</p>'
        + '<p><strong>Email:</strong> ' + email + '</p>'
        + '<p><strong>Subject:</strong> ' + (subject || 'General inquiry') + '</p>'
        + '<hr>'
        + '<p>' + message.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>') + '</p>',
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
