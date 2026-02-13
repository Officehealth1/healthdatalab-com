const https = require('https');

const SK = process.env.STRIPE_SK;
if (!SK) {
    console.error('Please provide STRIPE_SK env var');
    process.exit(1);
}

const products = [
    {
        name: 'HealthDataLab Launchpad',
        description: 'Monthly subscription. 3 report credits/mo, Mini Course, Basic AI.',
        prices: [{ unit_amount: 1900, currency: 'gbp', recurring: { interval: 'month' } }]
    },
    {
        name: 'HealthDataLab Minimum',
        description: 'Monthly subscription. 15 report credits/mo, Full AI Suite, CRM.',
        prices: [{ unit_amount: 9900, currency: 'gbp', recurring: { interval: 'month' } }]
    },
    {
        name: 'Pro Practitioner Course',
        description: '12-week course, Practitioner Certificate, 1 year software access.',
        prices: [
            { unit_amount: 99700, currency: 'gbp', nickname: 'Standard One-time' },
            { unit_amount: 69700, currency: 'gbp', nickname: 'Founding One-time' },
            { unit_amount: 34700, currency: 'gbp', recurring: { interval: 'month' }, nickname: 'Payment Plan' }
        ]
    },
    {
        name: 'HealthDataLab Signature',
        description: 'Course + Software + Small Group Mentorship + Business AI.',
        prices: [
            { unit_amount: 249700, currency: 'gbp', nickname: 'Standard One-time' },
            { unit_amount: 174700, currency: 'gbp', nickname: 'Founding One-time' },
            { unit_amount: 44700, currency: 'gbp', recurring: { interval: 'month' }, nickname: 'Payment Plan' }
        ]
    }
];

async function stripeRequest(method, path, data) {
    return new Promise((resolve, reject) => {
        const postData = new URLSearchParams();
        if (data) {
            // Flatten object for x-www-form-urlencoded (simple level)
            // For deeper objects like recurring[interval], handle manually
            for (const [key, value] of Object.entries(data)) {
                if (typeof value === 'object') {
                    for (const [subKey, subValue] of Object.entries(value)) {
                        postData.append(`${key}[${subKey}]`, subValue);
                    }
                } else {
                    postData.append(key, value);
                }
            }
        }

        const options = {
            hostname: 'api.stripe.com',
            port: 443,
            path: '/v1' + path,
            method: method,
            headers: {
                'Authorization': `Bearer ${SK}`,
                'Content-Type': 'application/x-www-form-urlencoded'
            }
        };

        const req = https.request(options, (res) => {
            let body = '';
            res.on('data', (chunk) => body += chunk);
            res.on('end', () => {
                if (res.statusCode >= 200 && res.statusCode < 300) {
                    resolve(JSON.parse(body));
                } else {
                    reject(new Error(`Status ${res.statusCode}: ${body}`));
                }
            });
        });

        req.on('error', (e) => reject(e));
        if (data) req.write(postData.toString());
        req.end();
    });
}

async function main() {
    const finalIds = {};

    for (const p of products) {
        console.log(`Creating Product: ${p.name}...`);
        try {
            const prod = await stripeRequest('POST', '/products', { name: p.name, description: p.description });
            console.log(`  -> Product ID: ${prod.id}`);
            finalIds[p.name] = { product_id: prod.id, prices: [] };

            for (const price of p.prices) {
                const payload = {
                    product: prod.id,
                    unit_amount: price.unit_amount,
                    currency: price.currency,
                };
                if (price.recurring) {
                    payload.recurring = price.recurring; // Handled by simple logic above? recurring[interval] works
                }
                if (price.nickname) {
                    payload.nickname = price.nickname;
                }

                console.log(`  Parsing Price: ${price.unit_amount}...`);
                // Fix payload serialization logic for 'recurring' object if needed
                // My simple serializer above handles `recurring: {interval: 'month'}` -> `recurring[interval]=month`.

                const pr = await stripeRequest('POST', '/prices', payload);
                console.log(`  -> Price ID: ${pr.id} (${price.unit_amount})`);
                finalIds[p.name].prices.push({
                    id: pr.id,
                    amount: price.unit_amount,
                    recurring: !!price.recurring,
                    nickname: price.nickname
                });
            }
        } catch (err) {
            console.error(`Error creating ${p.name}:`, err.message);
        }
    }

    console.log('\n--- FINAL IDs ---');
    console.log(JSON.stringify(finalIds, null, 2));
}

main();
