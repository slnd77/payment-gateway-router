<?php

/**
 * Drives the *real* Stripe test-mode flow end to end - real Checkout
 * Session creation, real hosted checkout page on checkout.stripe.com -
 * instead of the Http::fake() stub used for other gateways in
 * PaymentFlowTest. Stripe's SDK talks to the network through its own HTTP
 * client (not Laravel's Http facade), so its outbound calls can't be faked
 * there; this is why that full flow isn't covered by PaymentFlowTest.php
 * and needs a real sandbox instead.
 *
 * Requires an actual Stripe test-mode API key, which lives outside version
 * control (see .env.testing.local.example) and is loaded manually below
 * since Laravel only auto-loads .env.testing.
 *
 * Unlike ICICI/Razorpay/Cashfree's selectors (which are best-effort
 * guesses), the card-entry field locators below (#cardNumber, #cardExpiry,
 * #cardCvc, #billingName, and the "Pay" button) were confirmed directly
 * against the live Stripe Checkout page's rendered DOM while writing this
 * test, using test cards from https://docs.stripe.com/testing:
 * 4242 4242 4242 4242 (always succeeds) and 4000 0000 0000 0002 (always
 * declines).
 */

require_once __DIR__.'/../Browser/Support/helpers.php';

/**
 * Loads .env.testing.local (if present) and pushes its values into the
 * services.stripe_sandbox config, since Laravel only auto-loads
 * .env.testing and config/services.php would otherwise have already
 * resolved those env() calls to null by the time this test runs.
 */
function loadStripeSandboxEnv(): void
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
        'services.stripe_sandbox.key_secret' => env('STRIPE_TEST_KEY_SECRET'),
    ]);
}

/**
 * @return array<string, mixed>|null
 */
function stripeSandboxCredentials(): ?array
{
    loadStripeSandboxEnv();

    if (! config('services.stripe_sandbox.key_secret')) {
        return null;
    }

    return [
        'key_secret' => config('services.stripe_sandbox.key_secret'),
        'supports_refunds' => true,
        'fees_included_in_amount' => false,
        'fees_rate' => 2.9,
    ];
}

/**
 * Fills in Stripe's hosted checkout card form with the given test card and
 * submits it.
 */
function submitStripeTestCard(mixed $page, string $cardNumber): void
{
    $page->wait(2)
        ->fill('#cardNumber', $cardNumber)
        ->fill('#cardExpiry', '12/34')
        ->fill('#cardCvc', '123')
        ->fill('#billingName', 'Jane Doe')
        ->click('Pay')
        ->wait(2);
}

beforeEach(function () {
    $credentials = stripeSandboxCredentials();

    if (! $credentials) {
        $this->markTestSkipped('Stripe test-mode credentials are not configured - see .env.testing.local.example.');
    }

    $this->stripeCredentials = $credentials;
});

it('completes a real Stripe test-mode payment end to end with a successful test card', function () {
    $client = createBrowserTestClient('STRIPE', $this->stripeCredentials);

    $page = visit(route('testPayment', ['clientId' => $client->client_id, 'amount' => 100]));

    if (str_contains($page->content(), '"error"')) {
        $this->markTestSkipped('Stripe test-mode rejected the checkout-session call: '.extractPageErrorSnippet($page->content()));
    }

    // handlePaymentRequest() returns Stripe's own hosted checkout URL
    // directly, so the very first redirect already lands on stripe.com.
    $page->assertHostIs('*stripe.com');

    submitStripeTestCard($page, '4242424242424242');

    $payload = decryptRedirectPayload($page->url(), $client->client_secret);

    expect($payload['status'])->toBe('success');
});

it('shows a decline error on Stripe\'s checkout page with a card that always declines', function () {
    $client = createBrowserTestClient('STRIPE', $this->stripeCredentials);

    $page = visit(route('testPayment', ['clientId' => $client->client_id, 'amount' => 100]));

    if (str_contains($page->content(), '"error"')) {
        $this->markTestSkipped('Stripe test-mode rejected the checkout-session call: '.extractPageErrorSnippet($page->content()));
    }

    $page->assertHostIs('*stripe.com');

    submitStripeTestCard($page, '4000000000000002');

    // A declined card never reaches success_url - Stripe keeps the customer
    // on its own checkout page and shows an inline decline error instead.
    $page->assertHostIs('*stripe.com')
        ->assertSee('declined');
});
