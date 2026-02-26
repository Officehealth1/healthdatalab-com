const COUNTRY_TO_CURRENCY = {
  US: 'USD', PR: 'USD', GU: 'USD', VI: 'USD', AS: 'USD', MP: 'USD',
  DE: 'EUR', FR: 'EUR', IT: 'EUR', ES: 'EUR', NL: 'EUR', BE: 'EUR',
  AT: 'EUR', PT: 'EUR', IE: 'EUR', FI: 'EUR', GR: 'EUR', SK: 'EUR',
  SI: 'EUR', EE: 'EUR', LV: 'EUR', LT: 'EUR', LU: 'EUR', MT: 'EUR',
  CY: 'EUR', HR: 'EUR',
  CH: 'CHF', LI: 'CHF',
  CA: 'CAD',
  AU: 'AUD',
};

export default async (request, context) => {
  const response = await context.next();
  const cookies = request.headers.get('cookie') || '';
  if (!cookies.includes('hdl_geo_currency=')) {
    const country = context.geo?.country?.code || '';
    const currency = COUNTRY_TO_CURRENCY[country] || 'GBP';
    response.headers.append('set-cookie',
      `hdl_geo_currency=${currency}; path=/; max-age=86400; SameSite=Lax`);
  }
  return response;
};

export const config = { path: ['/', '/index.html', '/personal-report', '/personal-report.html'] };
