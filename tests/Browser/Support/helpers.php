<?php

use App\Classes\Encryption;
use App\Enums\ConnectionType;
use App\Models\Client;
use App\Models\ClientConnection;
use App\Models\PGConnection;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Shared helpers for tests/Browser/PaymentFlowTest.php (mocked, run as part
 * of the default test suite) and tests/BrowserLiveSandbox/*Test.php (real
 * network calls to each gateway's own sandbox, opt-in only via
 * `composer test:live-sandbox`) - required explicitly by each file that
 * needs them rather than relying on same-directory autoloading, since the
 * two suites now live in separate directories/PHPUnit testsuites.
 */

/**
 * Creates a Client wired to a single one-time-payment PG connection, exactly
 * as the admin panel would, so the browser can drive the real /initPayment
 * -> gateway -> /handleResponse flow end to end.
 *
 * @param  array<string, mixed>  $attributes
 */
function createBrowserTestClient(string $pgClass, array $attributes): Client
{
    $user = User::factory()->create();

    $pgConnection = PGConnection::create([
        'name' => $pgClass.' Connection',
        'pg_class' => $pgClass,
        'attributes' => $attributes,
        'status' => true,
        'type' => ConnectionType::TEST,
    ]);

    $client = Client::create([
        'uuid' => (string) Str::ulid(),
        'name' => 'Browser Test Client',
        'client_id' => Str::upper(Str::random(16)),
        'client_secret' => Str::random(40),
        'website' => 'https://example.test',
        'redirect_uri' => route('test.return'),
        'redirect_uri_separator' => '?',
        'status' => true,
        'user_id' => $user->id,
    ]);

    ClientConnection::create([
        'client_id' => $client->id,
        'pg_connection_id' => $pgConnection->id,
        'is_recurring' => false,
        'type' => ConnectionType::TEST,
        'status' => true,
    ]);

    return $client;
}

/**
 * Pulls a short, tag-free error snippet out of a gateway error page for use
 * in markTestSkipped() messages. Passing the full raw $page->content() HTML
 * there is unsafe: Pest's test-result printer renders skip/failure messages
 * through Termwind, which parses `class="..."` attributes in the message as
 * Tailwind-style utility names and throws ("Style [X] not found") on gateway
 * markup it doesn't recognise (e.g. Stripe's own "LOADING-logo" class) -
 * silently swallowing the actual error the message was trying to report.
 */
function extractPageErrorSnippet(string $html): string
{
    if (preg_match('/\{[^<>]*"error"[^<>]*\}/s', $html, $matches)) {
        return Str::limit($matches[0], 500);
    }

    return Str::limit(trim(strip_tags($html)), 500);
}

/**
 * Decrypts the "data" query parameter the merchant redirect ends with, using
 * the same scheme TransactionService uses to encrypt PaymentResponseDTO.
 *
 * @return array<string, mixed>
 */
function decryptRedirectPayload(string $url, string $clientSecret): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($query)->toHaveKey('data');

    $decrypted = Encryption::decrypt((string) $query['data'], $clientSecret);

    return json_decode($decrypted, true);
}
