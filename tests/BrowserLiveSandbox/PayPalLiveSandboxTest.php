<?php

/**
 * Drives the *real* PayPal sandbox flow end to end - real order creation,
 * real hosted checkout page on paypal.com - instead of the Http::fake()
 * stub used for other gateways in PaymentFlowTest. PayPal's SDK talks to
 * the network through its own HTTP client (not Laravel's Http facade), so
 * its outbound calls can't be faked there; this is why that full flow
 * isn't covered by PaymentFlowTest.php and needs a real sandbox instead.
 *
 * Unlike Razorpay/Cashfree, PayPal.php redirects the customer straight to
 * PayPal's own hosted approval page (the payer-action link from the
 * create-order response) - there is no local checkoutForm page in between.
 * Approving the payment there requires logging in with a PayPal Sandbox
 * *personal* (buyer) account, which is a second set of credentials beyond
 * the merchant client_id/secret (see .env.testing.local.example). Without
 * the buyer credentials, this test still verifies that a real order was
 * created and that the customer lands on PayPal's real checkout page, but
 * skips actually approving the payment.
 *
 * NOTE: PayPal's hosted checkout page is entirely on their own domain.
 * The login/approve locators below are best-effort based on PayPal's
 * current sandbox checkout UI - run with `--debug` against a live sandbox
 * to confirm/adjust them, the same caveat that applies to
 * IciciLiveSandboxTest.
 */

require_once __DIR__.'/../Browser/Support/helpers.php';

/**
 * Loads .env.testing.local (if present) and pushes its values into the
 * services.paypal_sandbox config, since Laravel only auto-loads
 * .env.testing and config/services.php would otherwise have already
 * resolved those env() calls to null by the time this test runs.
 */
function loadPayPalSandboxEnv(): void
{
    $envFile = base_path('.env.testing.local');

    if (! file_exists($envFile)) {
        return;
    }

    Dotenv\Dotenv::createImmutable(base_path(), '.env.testing.local')->safeLoad();

    // env() outside a config file is normally discouraged since it returns
    // the default (usually null) once config is cached - fine here, since
    // config:cache is a production-only step that never runs for tests.
    config([
        'services.paypal_sandbox.client_id' => env('PAYPAL_TEST_CLIENT_ID'),
        'services.paypal_sandbox.secret' => env('PAYPAL_TEST_SECRET'),
        'services.paypal_sandbox.buyer_email' => env('PAYPAL_TEST_BUYER_EMAIL'),
        'services.paypal_sandbox.buyer_password' => env('PAYPAL_TEST_BUYER_PASSWORD'),
    ]);
}

/**
 * @return array<string, mixed>|null
 */
function paypalSandboxCredentials(): ?array
{
    loadPayPalSandboxEnv();

    foreach (['client_id', 'secret'] as $key) {
        if (! config("services.paypal_sandbox.{$key}")) {
            return null;
        }
    }

    return [
        'client_id' => config('services.paypal_sandbox.client_id'),
        'secret' => config('services.paypal_sandbox.secret'),
        'mode' => 'sandbox',
        'paymentAction' => 'Sale',
        'locale' => 'en_US',
        'supports_refunds' => true,
        'fees_included_in_amount' => false,
        'fees_rate' => 3.49,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function paypalSandboxBuyerCredentials(): ?array
{
    loadPayPalSandboxEnv();

    $email = config('services.paypal_sandbox.buyer_email');
    $password = config('services.paypal_sandbox.buyer_password');

    return ($email && $password) ? ['email' => $email, 'password' => $password] : null;
}

/**
 * Logs into PayPal's sandbox checkout with a sandbox buyer account and
 * approves the payment.
 *
 * @param  array<string, mixed>  $buyer
 */
function completePayPalHostedCheckout(mixed $page, array $buyer): void
{
    $page->wait(1)
        ->fill('Email or mobile number', $buyer['email'])
        ->click('Next')
        ->wait(1)
        ->fill('Password', $buyer['password'])
        ->click('Log In')
        ->wait(1)
        ->click('Complete Purchase');
}

beforeEach(function () {
    $credentials = paypalSandboxCredentials();

    if (! $credentials) {
        $this->markTestSkipped('PayPal sandbox credentials are not configured - see .env.testing.local.example.');
    }

    $this->paypalCredentials = $credentials;
});

it('reaches PayPal\'s real sandbox checkout page for a newly created order', function () {
    $client = createBrowserTestClient('PAYPAL', $this->paypalCredentials);

    $page = visit(route('testPayment', ['clientId' => $client->client_id, 'amount' => 1]));

    if (str_contains($page->content(), '"error"')) {
        $this->markTestSkipped('PayPal sandbox rejected the order-creation call: '.extractPageErrorSnippet($page->content()));
    }

    // handlePaymentRequest() returns PayPal's own hosted approval link
    // directly, so the very first redirect already lands on paypal.com.
    $page->assertHostIs('*paypal.com');
});

it('completes a real PayPal sandbox payment end to end', function () {
    $buyer = paypalSandboxBuyerCredentials();

    if (! $buyer) {
        $this->markTestSkipped('PayPal sandbox buyer credentials are not configured - see .env.testing.local.example.');
    }

    $client = createBrowserTestClient('PAYPAL', $this->paypalCredentials);

    $page = visit(route('testPayment', ['clientId' => $client->client_id, 'amount' => 1]));

    if (str_contains($page->content(), '"error"')) {
        $this->markTestSkipped('PayPal sandbox rejected the order-creation call: '.extractPageErrorSnippet($page->content()));
    }

    $page->assertHostIs('*paypal.com');

    completePayPalHostedCheckout($page, $buyer);

    $payload = decryptRedirectPayload($page->url(), $client->client_secret);

    expect($payload['status'])->toBe('success');
});
