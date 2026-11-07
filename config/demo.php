<?php

/*
| Public demo mode.
|
| When DEMO_ENABLED=true the app exposes a sample merchant checkout at /demo,
| and `php artisan demo:reset` (scheduled every 3 hours) wipes the database and
| reseeds it from DemoSeeder. Never enable this on a real deployment.
|
| Gateway attributes are JSON objects with the same keys the admin panel asks
| for on a PG Connection (see SupportedPGSeeder). Leave one empty to skip that
| gateway. Only sandbox / test-mode credentials belong here.
*/

$json = static fn (string $key): array => json_decode((string) env($key, ''), true) ?: [];

return [
    'enabled' => (bool) env('DEMO_ENABLED', false),

    'admin' => [
        'name' => 'Demo Admin',
        'email' => env('DEMO_ADMIN_EMAIL', 'demo@pgdemo.local'),
        'password' => env('DEMO_ADMIN_PASSWORD', 'demo1234'),
    ],

    'reset_cron' => env('DEMO_RESET_CRON', '0 */3 * * *'),

    'gateways' => [
        'simulator' => [
            'label' => 'PG Simulator',
            'pg_class' => 'PGSimulator',
            'currency' => 'INR',
            'attributes' => ['supports_refunds' => true, 'fees_included_in_amount' => false, 'fees_rate' => 2.0],
            'recurring' => true,
        ],
        'razorpay' => [
            'label' => 'Razorpay (test mode)',
            'pg_class' => 'RAZORPAY',
            'currency' => 'INR',
            'attributes' => $json('DEMO_RAZORPAY_ATTRIBUTES'),
            'recurring' => false,
        ],
        'stripe' => [
            'label' => 'Stripe (test mode)',
            'pg_class' => 'STRIPE',
            'currency' => 'INR',
            'attributes' => $json('DEMO_STRIPE_ATTRIBUTES'),
            'recurring' => false,
        ],
        'icici' => [
            'label' => 'ICICI eazypay (UAT)',
            'pg_class' => 'ICICI',
            'currency' => 'INR',
            'attributes' => $json('DEMO_ICICI_ATTRIBUTES'),
            'recurring' => false,
        ],
    ],
];
