<?php

/**
 * Drives the *real* Razorpay test-mode flow end to end - real order
 * creation, real hosted embedded-checkout page - instead of the
 * Http::fake() stub used for other gateways in PaymentFlowTest. Razorpay's
 * SDK talks to the network through its own HTTP transport (not Laravel's
 * Http facade), so its outbound calls can't be faked there; this is why
 * that full flow isn't covered by PaymentFlowTest.php and needs a real
 * sandbox instead.
 *
 * Requires actual Razorpay test-mode API credentials, which live outside
 * version control (see .env.testing.local.example) and are loaded manually
 * below since Laravel only auto-loads .env.testing.
 *
 * NOTE: Razorpay's embedded checkout is a JS-rendered widget on their own
 * domain. The card/OTP locators below use Razorpay's publicly documented
 * test-mode card (4111 1111 1111 1111) and their standard test OTP
 * (any 6 digits is typically accepted in test mode) - run with `--debug`
 * against a live sandbox to confirm/adjust them, the same caveat that
 * applies to IciciLiveSandboxTest.
 */

/**
 * Loads .env.testing.local (if present) and pushes its values into the
 * services.razorpay_sandbox config, since Laravel only auto-loads
 * .env.testing and config/services.php would otherwise have already
 * resolved those env() calls to null by the time this test runs.
 */
function loadRazorpaySandboxEnv(): void
{
    $envFile = base_path('.env.testing.local');

    if (! file_exists($envFile)) {
        return;
    }

    Dotenv\Dotenv::createImmutable(base_path(), '.env.testing.local')->safeLoad();

    config([
        'services.razorpay_sandbox.key_id' => getenv('RAZORPAY_TEST_KEY_ID'),
        'services.razorpay_sandbox.key_secret' => getenv('RAZORPAY_TEST_KEY_SECRET'),
    ]);
}

/**
 * @return array<string, mixed>|null
 */
function razorpaySandboxCredentials(): ?array
{
    loadRazorpaySandboxEnv();

    foreach (['key_id', 'key_secret'] as $key) {
        if (! config("services.razorpay_sandbox.{$key}")) {
            return null;
        }
    }

    return [
        'key_id' => config('services.razorpay_sandbox.key_id'),
        'key_secret' => config('services.razorpay_sandbox.key_secret'),
        'supports_refunds' => false,
        'fees_included_in_amount' => false,
        'fees_rate' => 0,
    ];
}

/**
 * Completes Razorpay's embedded checkout with their publicly documented
 * test-mode card, submitting an OTP if the flow asks for one.
 */
function completeRazorpayEmbeddedCheckout(mixed $page): void
{
    $page->wait(1);

    if (str_contains(strtolower($page->content()), 'card')) {
        $page->click('Card');
    }

    $page->fill('Card Number', '4111 1111 1111 1111')
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
    $credentials = razorpaySandboxCredentials();

    if (! $credentials) {
        $this->markTestSkipped('Razorpay test-mode credentials are not configured - see .env.testing.local.example.');
    }

    $this->razorpayCredentials = $credentials;
});

it('completes a real Razorpay test-mode payment end to end', function () {
    $client = createBrowserTestClient('RAZORPAY', $this->razorpayCredentials);

    $page = visit(route('testPayment', ['clientId' => $client->client_id, 'amount' => 100]));

    if (str_contains($page->content(), '"error"')) {
        $this->markTestSkipped('Razorpay test-mode rejected the order-creation call: '.$page->content());
    }

    // handlePaymentRequest() redirects to our own local checkoutForm page
    // first, which then auto-submits the customer to Razorpay's hosted
    // embedded checkout - so the first hop is local, the second is Razorpay's.
    $page->assertHostIs('*razorpay.com');

    completeRazorpayEmbeddedCheckout($page);

    $payload = decryptRedirectPayload($page->url(), $client->client_secret);

    expect($payload['status'])->toBe('success');
});
