<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'pg_transaction_notifications' => [
        'email_enabled' => env('PG_TRANSACTION_EMAIL_ENABLED', true),
    ],

    /*
    | Real ICICI UAT sandbox credentials, used only by
    | tests/Browser/IciciLiveSandboxTest.php. Sourced from
    | .env.testing.local (gitignored - see .env.testing.local.example),
    | which that test loads and pushes into this config at runtime since
    | Laravel doesn't auto-load it.
    */
    'icici_sandbox' => [
        'merchant_id' => env('ICICI_TEST_MERCHANT_ID'),
        'aggregator_id' => env('ICICI_TEST_AGGREGATOR_ID'),
        'encryption_key' => env('ICICI_TEST_ENCRYPTION_KEY'),
        'sub_merchant_id' => env('ICICI_TEST_SUB_MERCHANT_ID'),
        'paymode' => env('ICICI_TEST_PAYMODE'),
    ],

    /*
    | Real Razorpay/Cashfree/PayPal test-mode credentials, used only by
    | tests/Browser/RazorpayLiveSandboxTest.php, CashfreeLiveSandboxTest.php,
    | and PayPalLiveSandboxTest.php respectively. Sourced from
    | .env.testing.local (gitignored - see .env.testing.local.example),
    | which those tests load and push into this config at runtime since
    | Laravel doesn't auto-load it.
    */
    'razorpay_sandbox' => [
        'key_id' => env('RAZORPAY_TEST_KEY_ID'),
        'key_secret' => env('RAZORPAY_TEST_KEY_SECRET'),
    ],

    'cashfree_sandbox' => [
        'key_id' => env('CASHFREE_TEST_KEY_ID'),
        'key_secret' => env('CASHFREE_TEST_KEY_SECRET'),
    ],

    'paypal_sandbox' => [
        'client_id' => env('PAYPAL_TEST_CLIENT_ID'),
        'secret' => env('PAYPAL_TEST_SECRET'),
        'buyer_email' => env('PAYPAL_TEST_BUYER_EMAIL'),
        'buyer_password' => env('PAYPAL_TEST_BUYER_PASSWORD'),
    ],

];
