const https = require('https');

const SK = process.env.STRIPE_SK;
if (!SK) {
    console.error('Please provide STRIPE_SK env var');
    process.exit(1);
}

async function stripeRequest(method, path, data) {
    return new Promise((resolve, reject) => {
        const postData = new URLSearchParams();
        if (data) {
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
            method,
            headers: {
                'Authorization': `Bearer ${SK}`,
                'Content-Type': 'application/x-www-form-urlencoded',
            },
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
    // 1. List existing products to find IDs
    console.log('Fetching existing products...\n');
    const productList = await stripeRequest('GET', '/products?limit=100');
    const products = {};
    for (const p of productList.data) {
        products[p.name] = p.id;
        console.log(`  Found: "${p.name}" -> ${p.id}`);
    }

    const courseProductId = products['Pro Practitioner Course'];
    const signatureProductId = products['HealthDataLab Signature'];

    if (!courseProductId || !signatureProductId) {
        console.error('\nCould not find required products. Available products:');
        for (const [name, id] of Object.entries(products)) {
            console.error(`  "${name}" -> ${id}`);
        }
        process.exit(1);
    }

    // 2. Check if a Consumer product exists; create one if not
    let consumerProductId = products['HealthDataLab Consumer Reports'];
    if (!consumerProductId) {
        console.log('\nCreating Consumer Reports product...');
        const consumerProd = await stripeRequest('POST', '/products', {
            name: 'HealthDataLab Consumer Reports',
            description: 'Individual longevity health reports for consumers.',
        });
        consumerProductId = consumerProd.id;
        console.log(`  -> Product ID: ${consumerProductId}`);
    }

    // 3. Create all missing prices
    const pricesToCreate = [
        {
            label: 'Super Early Course one-time',
            product: courseProductId,
            unit_amount: 59700,
            currency: 'gbp',
            nickname: 'Super Early One-time',
        },
        {
            label: 'Super Early Course installment',
            product: courseProductId,
            unit_amount: 19900,
            currency: 'gbp',
            recurring: { interval: 'month' },
            nickname: 'Super Early 3-Payment Plan',
        },
        {
            label: 'Super Early Signature one-time',
            product: signatureProductId,
            unit_amount: 149700,
            currency: 'gbp',
            nickname: 'Super Early One-time',
        },
        {
            label: 'Super Early Signature installment',
            product: signatureProductId,
            unit_amount: 25000,
            currency: 'gbp',
            recurring: { interval: 'month' },
            nickname: 'Super Early 6-Payment Plan',
        },
        {
            label: 'Founding Course installment',
            product: courseProductId,
            unit_amount: 23300,
            currency: 'gbp',
            recurring: { interval: 'month' },
            nickname: 'Founding 3-Payment Plan',
        },
        {
            label: 'Founding Signature installment',
            product: signatureProductId,
            unit_amount: 29200,
            currency: 'gbp',
            recurring: { interval: 'month' },
            nickname: 'Founding 6-Payment Plan',
        },
        {
            label: 'Consumer Single Report',
            product: consumerProductId,
            unit_amount: 999,
            currency: 'gbp',
            nickname: 'Single Report',
        },
        {
            label: 'Consumer Annual Pass',
            product: consumerProductId,
            unit_amount: 1999,
            currency: 'gbp',
            recurring: { interval: 'year' },
            nickname: 'Annual Pass',
        },
    ];

    console.log('\nCreating prices...\n');
    const results = {};

    for (const p of pricesToCreate) {
        const payload = {
            product: p.product,
            unit_amount: p.unit_amount,
            currency: p.currency,
        };
        if (p.recurring) payload.recurring = p.recurring;
        if (p.nickname) payload.nickname = p.nickname;

        try {
            const price = await stripeRequest('POST', '/prices', payload);
            results[p.label] = price.id;
            console.log(`  ${p.label}: ${price.id}`);
        } catch (err) {
            console.error(`  ERROR creating "${p.label}": ${err.message}`);
        }
    }

    // 4. Output the mapping for index.html PRICE_IDS
    console.log('\n\n=== COPY THESE INTO index.html PRICE_IDS ===\n');
    console.log(`course.super_early:             '${results['Super Early Course one-time'] || 'FAILED'}',`);
    console.log(`course.installment_super_early:  '${results['Super Early Course installment'] || 'FAILED'}',`);
    console.log(`course.installment_founding:     '${results['Founding Course installment'] || 'FAILED'}',`);
    console.log(`signature.super_early:           '${results['Super Early Signature one-time'] || 'FAILED'}',`);
    console.log(`signature.installment_super_early:'${results['Super Early Signature installment'] || 'FAILED'}',`);
    console.log(`signature.installment_founding:  '${results['Founding Signature installment'] || 'FAILED'}',`);
    console.log(`consumer_single:                 '${results['Consumer Single Report'] || 'FAILED'}',`);
    console.log(`consumer_annual:                 '${results['Consumer Annual Pass'] || 'FAILED'}',`);

    console.log('\n=== RAW RESULTS ===');
    console.log(JSON.stringify(results, null, 2));
}

main().catch(err => {
    console.error('Fatal error:', err);
    process.exit(1);
});
