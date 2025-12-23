import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { randomIntBetween } from 'https://jslib.k6.io/k6-utils/1.5.0/index.js';

export const options = {
    stages: [
        { duration: '30s', target: 20 },
        { duration: '1m', target: 50 },
        { duration: '30s', target: 80 },
        { duration: '1m', target: 0 },
    ],
    thresholds: {
        http_req_duration: ['p(95) < 2000'],
        http_req_failed: ['rate < 0.01'],
    },
};

const BASE_URL = 'http://banking-system.hudashakir.serv00.net/api';

// دوال مساعدة عشوائية
const getRandomAccount = () => randomIntBetween(1, 5);
const getRandomCurrency = () => ['USD', 'EUR', 'SYP'][randomIntBetween(0, 2)];
const getRandomDescription = (type) => {
    const descriptions = {
        transfer: ['Salary transfer', 'Family support', 'Business payment', 'Rent payment', 'Loan repayment', 'Investment transfer', 'Expense reimbursement'],
        deposit: ['Cash deposit', 'Check deposit', 'Wire transfer received', 'Salary deposit', 'Refund deposit', 'Bonus deposit'],
        withdrawal: ['ATM withdrawal', 'Bill payment', 'Online shopping', 'Utility payment', 'Car insurance', 'Rent payment', 'Medical expense']
    };
    return descriptions[type][randomIntBetween(0, descriptions[type].length - 1)];
};

// توليد تاريخ عشوائي بعد 23/12/2025
const getRandomFutureDate = () => {
    const start = new Date(2026, 0, 1); // يناير 2026
    const end = new Date(2026, 2, 31);  // مارس 2026
    const randomDate = new Date(start.getTime() + Math.random() * (end.getTime() - start.getTime()));

    // ضبط الوقت بين 9 صباحاً و5 مساءً
    const hours = randomIntBetween(9, 17);
    const minutes = randomIntBetween(0, 59);
    randomDate.setHours(hours, minutes, 0, 0);

    return randomDate.toISOString();
};

export default function () {
    group('Authentication', function () {
        console.log('🔐 Starting login test...');

        const loginRes = http.post(`${BASE_URL}/login`,
            JSON.stringify({
                email: 'mhranabwdqt971@gmail.com',
                password: 'password'
            }),
            {
                headers: { 'Content-Type': 'application/json' },
                tlsConfig: { insecureSkipVerify: true }
            }
        );

        console.log('📊 Login Status:', loginRes.status);

        if (!loginRes || loginRes.status === 0) {
            console.error('❌ No response from server! Check URL and network.');
            return;
        }

        if (loginRes.status !== 200) {
            console.error('❌ Login failed with status:', loginRes.status);
            console.error('📋 Response:', loginRes.body);
            return;
        }

        console.log('✅ Login successful!');

        try {
            const token = JSON.parse(loginRes.body).token;
            console.log('🔑 Token extracted:', token.substring(0, 10) + '...');

            const authHeaders = {
                'Authorization': `Bearer ${token}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            };

            // --- إنشاء التحويلات ---
            group('Transaction Creation', function () {
                console.log('🔄 Creating transactions...');

                // 50 عملية صغيرة (0-100)
                for (let i = 0; i < 50; i++) {
                    const amount = randomIntBetween(1, 100);
                    createTransaction(authHeaders, 'transfer', getRandomAccount(), getRandomAccount(), amount, getRandomCurrency(), getRandomDescription('transfer'));
                    sleep(0.05); // تأخير صغير
                }

                // 10 عمليات متوسطة (100-1000)
                for (let i = 0; i < 10; i++) {
                    const amount = randomIntBetween(101, 1000);
                    createTransaction(authHeaders, 'transfer', getRandomAccount(), getRandomAccount(), amount, getRandomCurrency(), getRandomDescription('transfer'));
                    sleep(0.1);
                }

                // 10 عمليات كبيرة (1000+)
                for (let i = 0; i < 10; i++) {
                    const amount = randomIntBetween(1001, 5000);
                    createTransaction(authHeaders, 'transfer', getRandomAccount(), getRandomAccount(), amount, getRandomCurrency(), getRandomDescription('transfer'));
                    sleep(0.15);
                }
            });

            // --- جدولة التحويلات ---
            group('Scheduled Transactions', function () {
                console.log('⏰ Scheduling transactions...');

                // 20 عملية مجدولة
                for (let i = 0; i < 20; i++) {
                    const amount = randomIntBetween(10, 10000);
                    const type = ['withdrawal', 'deposit', 'transfer'][randomIntBetween(0, 2)];

                    scheduleTransaction(
                        authHeaders,
                        type,
                        getRandomAccount(),
                        getRandomAccount(),
                        amount,
                        getRandomFutureDate(),
                        getRandomDescription(type)
                    );

                    sleep(0.1);
                }
            });

        } catch (e) {
            console.error('❌ Error parsing token:', e.message);
            console.log('Full response:', loginRes.body);
        }

        sleep(1);
    });
}

// دالة إنشاء تحويلة
function createTransaction(headers, type, sourceId, targetId, amount, currency, description) {
    const payload = {
        type,
        sourceAccountId: sourceId,
        targetAccountId: targetId,
        amount: parseFloat(amount.toFixed(2)),
        currency,
        description
    };

    const res = http.post(`${BASE_URL}/transactions`, JSON.stringify(payload), {
        headers: headers,
        tlsConfig: { insecureSkipVerify: true }
    });

    check(res, { 'transaction created': (r) => r.status === 200 || r.status === 201 });

    if (res.status !== 200 && res.status !== 201) {
        console.error(`❌ Transaction failed (${type}):`, res.status);
        console.error('Response:', res.body.substring(0, 200));
    } else {
        console.log(`✅ ${type} successful: ${amount} ${currency}`);
    }

    return res;
}

// دالة جدولة تحويلة
function scheduleTransaction(headers, type, accountId, targetAccountId, amount, scheduledAt, description) {
    const payload = {
        type,
        account_id: accountId,
        target_account_id: targetAccountId,
        amount: parseFloat(amount.toFixed(2)),
        scheduled_at: scheduledAt,
        description
    };

    const res = http.post(`${BASE_URL}/transactions/schedule`, JSON.stringify(payload), {
        headers: headers,
        tlsConfig: { insecureSkipVerify: true }
    });

    check(res, { 'transaction scheduled': (r) => r.status === 200 || r.status === 201 });

    if (res.status !== 200 && res.status !== 201) {
        console.error(`❌ Scheduled ${type} failed:`, res.status);
        console.error('Response:', res.body.substring(0, 200));
    } else {
        console.log(`✅ Scheduled ${type}: ${amount} ${currency} on ${scheduledAt.substring(0, 10)}`);
    }

    return res;
}
