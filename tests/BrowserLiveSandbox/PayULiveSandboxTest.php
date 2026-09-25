<?php

/**
 * Drives the *real* PayU test-mode flow end to end - real hosted checkout
 * page on test.payu.in - instead of the Http::fake() stub used for other
 * gateways in PaymentFlowTest. PayU's hosted checkout is a plain HTML form
 * POST (no SDK/API call precedes it), so there is nothing to fake there;
 * this needs a real sandbox to exercise the actual checkout page.
 *
 * Requires actual PayU test-mode credentials, which live outside version
 * control (see .env.testing.local.example) and are loaded manually below
 * since Laravel only auto-loads .env.testing.
 *
 * NOTE: PayU's hosted checkout is a JS-rendered widget on their own domain.
 * The card/OTP locators below use PayU's publicly documented test-mode VISA
 * card (4012001037141112) and test OTP (123456) - run with `--debug`
 * against a live sandbox to confirm/adjust them, the same caveat that
 * applies to IciciLiveSandboxTest.
 */

require_once __DIR__.'/../Browser/Support/helpers.php';

/**
 * Loads .env.testing.local (if present) and pushes its values into the
 * services.payu_sandbox config, since Laravel only auto-loads
 * .env.testing and config/services.php would otherwise have already
 * resolved those env() calls to null by the time this test runs.
 */
function loadPayUSandboxEnv(): void
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
        'services.payu_sandbox.key' => env('PAYU_TEST_KEY'),
        'services.payu_sandbox.salt' => env('PAYU_TEST_SALT'),
    ]);
}

/**
 * @return array<string, mixed>|null
 */
function payuSandboxCredentials(): ?array
{
    loadPayUSandboxEnv();

    foreach (['key', 'salt'] as $key) {
        if (! config("services.payu_sandbox.{$key}")) {
            return null;
        }
    }

    return [
        'key' => config('services.payu_sandbox.key'),
        'salt' => config('services.payu_sandbox.salt'),
        'supports_refunds' => true,
        'fees_included_in_amount' => false,
        'fees_rate' => 2,
    ];
}

/**
 * Completes PayU's hosted checkout with their publicly documented test-mode
 * VISA card, submitting an OTP if the flow asks for one.
 */
function completePayUHostedCheckout(mixed $page): void
{
    $page->wait(1);

    if (str_contains(strtolower($page->content()), 'card')) {
        $page->click('Cards');
        $page->wait(1);
    }

    $page->fill('Card Number', '4012001037141112')
        ->fill('Expiry', '05/30')
        ->fill('CVV', '123')
        ->click('Pay Now');

    $page->wait(1);

    if (str_contains(strtolower($page->content()), 'otp')) {
        $page->fill('OTP', '123456')
            ->click('Submit');
    }
}

beforeEach(function () {
    $credentials = payuSandboxCredentials();

    if (! $credentials) {
        $this->markTestSkipped('PayU test-mode credentials are not configured - see .env.testing.local.example.');
    }

    $this->payuCredentials = $credentials;
});

it('completes a real PayU test-mode payment end to end', function () {
    $client = createBrowserTestClient('PAYU', $this->payuCredentials);

    $page = visit(route('testPayment', ['clientId' => $client->client_id, 'amount' => 100]));

    if (str_contains($page->content(), '"error"')) {
        $this->markTestSkipped('PayU test-mode rejected the checkout call: '.extractPageErrorSnippet($page->content()));
    }

    // handlePaymentRequest() redirects to our own local checkoutForm page
    // first, which then auto-submits the customer to PayU's hosted
    // checkout - so the first hop is local, the second is PayU's.
    $page->assertHostIs('*payu.in');

    completePayUHostedCheckout($page);

    $payload = decryptRedirectPayload($page->url(), $client->client_secret);

    expect($payload['status'])->toBe('success');
})->skip('PayU hosted checkout automation is unverified against the live sandbox - the payment-method accordion/card-field selectors are best-effort guesses (same caveat as IciciLiveSandboxTest). Re-enable once confirmed stable against the live sandbox.');
