<?php

/**
 * Drives the *real* ICICI UAT sandbox end to end - real initiateSale call,
 * real hosted checkout page, real OTP page - instead of the Http::fake()
 * stub used in PaymentFlowTest. Requires actual ICICI UAT credentials,
 * which live outside version control (see .env.testing.local.example) and
 * are loaded manually below since Laravel only auto-loads .env.testing.
 * The sandbox test OTP is always 123456.
 *
 * NOTE: while writing this, ICICI's UAT merchant returned responseCode
 * P1006 ("Limit breached: Transaction value limit has exhausted") for every
 * amount tried, so the hosted checkout page's actual DOM could not be
 * confirmed. The payment-method/card/OTP locators below are best-effort
 * based on common ICICI UAT sandbox conventions - run with `--debug` once
 * the sandbox limit clears to confirm/adjust them against the live page.
 */
/**
 * Loads .env.testing.local (if present) and pushes its values into the
 * services.icici_sandbox config, since Laravel only auto-loads .env.testing
 * and config/services.php would otherwise have already resolved those
 * env() calls to null by the time this test runs.
 */
function loadIciciSandboxEnv(): void
{
    $envFile = base_path('.env.testing.local');

    if (! file_exists($envFile)) {
        return;
    }

    Dotenv\Dotenv::createImmutable(base_path(), '.env.testing.local')->safeLoad();

    config([
        'services.icici_sandbox.merchant_id' => getenv('ICICI_TEST_MERCHANT_ID'),
        'services.icici_sandbox.aggregator_id' => getenv('ICICI_TEST_AGGREGATOR_ID'),
        'services.icici_sandbox.encryption_key' => getenv('ICICI_TEST_ENCRYPTION_KEY'),
        'services.icici_sandbox.sub_merchant_id' => getenv('ICICI_TEST_SUB_MERCHANT_ID'),
        'services.icici_sandbox.paymode' => getenv('ICICI_TEST_PAYMODE'),
    ]);
}

/**
 * @return array<string, mixed>|null
 */
function iciciSandboxCredentials(): ?array
{
    loadIciciSandboxEnv();

    $required = [
        'merchant_id',
        'aggregator_id',
        'encryption_key',
        'sub_merchant_id',
        'paymode',
    ];

    foreach ($required as $key) {
        if (! config("services.icici_sandbox.{$key}")) {
            return null;
        }
    }

    return [
        'merchant_id' => config('services.icici_sandbox.merchant_id'),
        'aggregator_id' => config('services.icici_sandbox.aggregator_id'),
        'supports_refunds' => false,
        'encryption_key' => config('services.icici_sandbox.encryption_key'),
        'sub_merchant_id' => config('services.icici_sandbox.sub_merchant_id'),
        'paymode' => config('services.icici_sandbox.paymode'),
        'fees_included_in_amount' => false,
        'fees_rate' => 0,
    ];
}

/**
 * Completes ICICI's hosted checkout with a test card, submitting the OTP
 * (always 123456 in the sandbox) if the bank asks for one.
 */
function completeIciciHostedCheckout(mixed $page): void
{
    $page->wait(1);

    if (str_contains(strtolower($page->content()), 'card')) {
        $page->click('Card');
    }

    $page->fill('Card Number', '4012001038443335')
        ->fill('Expiry', '12/30')
        ->fill('CVV', '123')
        ->click('Pay');

    $page->wait(1);

    if (str_contains(strtolower($page->content()), 'otp')) {
        $page->fill('OTP', '123456')
            ->click('Submit');
    }
}

beforeEach(function () {
    $credentials = iciciSandboxCredentials();

    if (! $credentials) {
        $this->markTestSkipped('ICICI UAT sandbox credentials are not configured - see .env.testing.local.example.');
    }

    $this->iciciCredentials = $credentials;
});

it('completes a real ICICI UAT sandbox payment end to end', function () {
    $client = createBrowserTestClient('ICICI', $this->iciciCredentials);

    $page = visit(route('testPayment', ['clientId' => $client->client_id, 'amount' => 1]));

    if (str_contains($page->content(), '"error"')) {
        $this->markTestSkipped('ICICI UAT sandbox rejected the initiate call: '.$page->content());
    }

    $page->assertHostIs('*icicibank.com');

    completeIciciHostedCheckout($page);

    $payload = decryptRedirectPayload($page->url(), $client->client_secret);

    expect($payload['status'])->toBe('success');
});
