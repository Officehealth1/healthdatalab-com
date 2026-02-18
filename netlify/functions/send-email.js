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

    const res = await fetch('https://api.brevo.com/v3/smtp/email', {
      method: 'POST',
      headers: {
        'accept': 'application/json',
        'api-key': process.env.BREVO_API_KEY,
        'content-type': 'application/json',
      },
      body: JSON.stringify({
        sender: { name: 'HealthDataLab', email: 'office@healthdatalab.com' },
        to: [{ email: 'office@healthdatalab.com', name: 'HealthDataLab' }],
        replyTo: { email: email, name: name },
        subject: '[HDL Contact] ' + (subject || 'General inquiry'),
        htmlContent: '<h3>New contact form submission</h3>'
          + '<p><strong>Name:</strong> ' + name + '</p>'
          + '<p><strong>Email:</strong> ' + email + '</p>'
          + '<p><strong>Subject:</strong> ' + (subject || 'General inquiry') + '</p>'
          + '<hr>'
          + '<p>' + message.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>') + '</p>',
      }),
    });

    if (!res.ok) {
      const errBody = await res.text();
      console.error('Brevo API error:', res.status, errBody);
      return { statusCode: 500, body: JSON.stringify({ error: 'Failed to send email' }) };
    }

    return {
      statusCode: 200,
      body: JSON.stringify({ success: true }),
    };
  } catch (error) {
    console.error('Send email error:', error);
    return {
      statusCode: 500,
      body: JSON.stringify({ error: error.message }),
    };
  }
};
