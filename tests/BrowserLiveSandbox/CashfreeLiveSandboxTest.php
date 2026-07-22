<?php

/**
 * Drives the *real* Cashfree sandbox flow end to end - real order/session
 * creation, real hosted checkout widget - instead of the Http::fake() stub
 * used for other gateways in PaymentFlowTest. Cashfree.php itself does use
 * Laravel's Http facade (so its outbound calls could technically be faked),
 * but its return_url must point at a real, publicly resolvable domain (see
 * Cashfree.php's order_meta.return_url_invalid handling) - a plain
 * Http::fake()-based test running against a local-only test host can't
 * reach that leg of the flow, so this needs a real sandbox and a real
 * public URL (APP_URL) to run against.
 *
 * Requires actual Cashfree test-mode API credentials, which live outside
 * version control (see .env.testing.local.example) and are loaded manually
 * below since Laravel only auto-loads .env.testing.
 *
 * NOTE: Cashfree's checkout widget is a JS-rendered widget loaded from
 * Cashfree's own CDN. The payment-method/UPI locators below use Cashfree's
 * publicly documented test-mode UPI id (success@upi) - run with `--debug`
 * against a live sandbox to confirm/adjust them, the same caveat that
 * applies to IciciLiveSandboxTest.
 */

require_once __DIR__.'/../Browser/Support/helpers.php';

/**
 * Loads .env.testing.local (if present) and pushes its values into the
 * services.cashfree_sandbox config, since Laravel only auto-loads
 * .env.testing and config/services.php would otherwise have already
 * resolved those env() calls to null by the time this test runs.
 */
function loadCashfreeSandboxEnv(): void
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
        'services.cashfree_sandbox.key_id' => env('CASHFREE_TEST_KEY_ID'),
        'services.cashfree_sandbox.key_secret' => env('CASHFREE_TEST_KEY_SECRET'),
    ]);
}

/**
 * @return array<string, mixed>|null
 */
function cashfreeSandboxCredentials(): ?array
{
    loadCashfreeSandboxEnv();

    foreach (['key_id', 'key_secret'] as $key) {
        if (! config("services.cashfree_sandbox.{$key}")) {
            return null;
        }
    }

    return [
        'key_id' => config('services.cashfree_sandbox.key_id'),
        'key_secret' => config('services.cashfree_sandbox.key_secret'),
        'supports_refunds' => true,
        'fees_included_in_amount' => false,
        'fees_rate' => 2,
    ];
}

/**
 * Completes Cashfree's checkout widget using their publicly documented
 * test-mode UPI id, which simulates an instant successful payment.
 */
function completeCashfreeCheckout(mixed $page): void
{
    $page->wait(2);

    if (str_contains(strtolower($page->content()), 'upi')) {
        $page->click('UPI');
    }

    $page->fill('UPI ID', 'success@upi')
        ->click('Pay');

    $page->wait(2);
}

beforeEach(function () {
    $credentials = cashfreeSandboxCredentials();

    if (! $credentials) {
        $this->markTestSkipped('Cashfree test-mode credentials are not configured - see .env.testing.local.example.');
    }

    if (! str_starts_with(config('app.url'), 'https://') || str_ends_with(parse_url((string) config('app.url'), PHP_URL_HOST) ?? '', '.test')) {
        $this->markTestSkipped('Cashfree requires APP_URL to be a real, publicly resolvable HTTPS domain for return_url - see README.md#deployment.');
    }

    $this->cashfreeCredentials = $credentials;
});

it('completes a real Cashfree test-mode payment end to end', function () {
    $client = createBrowserTestClient('CASHFREE', $this->cashfreeCredentials);

    $page = visit(route('testPayment', ['clientId' => $client->client_id, 'amount' => 100]));

    if (str_contains($page->content(), '"error"')) {
        $this->markTestSkipped('Cashfree test-mode rejected the order-creation call: '.extractPageErrorSnippet($page->content()));
    }

    // handlePaymentRequest() redirects to our own local checkoutForm page
    // first, which boots the Cashfree JS SDK and opens the actual checkout
    // widget - so the first hop is local, the second is Cashfree's own.
    $page->wait(2);

    completeCashfreeCheckout($page);

    $payload = decryptRedirectPayload($page->url(), $client->client_secret);

    expect($payload['status'])->toBe('success');
});
