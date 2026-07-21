<?php

use App\Classes\Encryption;
use App\Enums\ConnectionType;
use App\Models\Client;
use App\Models\ClientConnection;
use App\Models\PGConnection;
use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

describe('PG Simulator', function () {
    it('completes a payment end to end when the customer simulates success', function () {
        $client = createBrowserTestClient('PGSimulator', [
            'supports_refunds' => true,
            'fees_included_in_amount' => false,
            'fees_rate' => 2.5,
        ]);

        $page = visit(route('testPayment', ['clientId' => $client->client_id, 'amount' => 1000]));

        $page->assertSee('PG Simulator')
            ->select('paymentMethod', 'upi')
            ->click('Simulate Success');

        $payload = decryptRedirectPayload($page->url(), $client->client_secret);

        expect($payload['status'])->toBe('success')
            ->and($payload['paymentMethod'])->toBe('upi');
    });

    it('completes a payment end to end when the customer simulates failure', function () {
        $client = createBrowserTestClient('PGSimulator', [
            'supports_refunds' => true,
            'fees_included_in_amount' => false,
            'fees_rate' => 2.5,
        ]);

        $page = visit(route('testPayment', ['clientId' => $client->client_id, 'amount' => 1000]));

        $page->assertSee('PG Simulator')
            ->click('Simulate Failure');

        $payload = decryptRedirectPayload($page->url(), $client->client_secret);

        expect($payload['status'])->toBe('failed');
    });
});

describe('ICICI', function () {
    beforeEach(function () {
        // ICICI's own hosted checkout page lives on their servers, so only
        // the outbound "initiate" API call is faked. The faked response
        // points back at our local test-stub route (routes/testing.php),
        // which stands in for ICICI's hosted page and posts a realistic
        // callback payload to the real handlePaymentResponse endpoint.
        Http::fake([
            'pgpayuat.icicibank.com/*' => function (ClientRequest $request) {
                return Http::response([
                    'responseCode' => 'R1000',
                    'redirectURI' => route('test.iciciHostedCheckout', [
                        'addlParam1' => $request['addlParam1'],
                        'addlParam2' => $request['addlParam2'],
                        'merchantTxnNo' => $request['merchantTxnNo'],
                    ]),
                    'tranCtx' => 'MOCKTRANCTX123',
                ]);
            },
        ]);
    });

    it('completes a payment end to end when the bank simulates success', function () {
        $client = createBrowserTestClient('ICICI', [
            'merchant_id' => 'TESTMERCHANT',
            'aggregator_id' => 'TESTAGG',
            'supports_refunds' => false,
            'encryption_key' => 'test-encryption-key',
            'sub_merchant_id' => 'TESTSUBM',
            'paymode' => '0',
            'fees_included_in_amount' => false,
            'fees_rate' => 0,
        ]);

        $page = visit(route('testPayment', ['clientId' => $client->client_id, 'amount' => 1000]));

        $page->assertSee('ICICI Hosted Checkout')
            ->click('Simulate Success');

        $payload = decryptRedirectPayload($page->url(), $client->client_secret);

        expect($payload['status'])->toBe('success')
            ->and($payload['paymentMethod'])->toBe('card');
    });

    it('completes a payment end to end when the bank simulates failure', function () {
        $client = createBrowserTestClient('ICICI', [
            'merchant_id' => 'TESTMERCHANT',
            'aggregator_id' => 'TESTAGG',
            'supports_refunds' => false,
            'encryption_key' => 'test-encryption-key',
            'sub_merchant_id' => 'TESTSUBM',
            'paymode' => '0',
            'fees_included_in_amount' => false,
            'fees_rate' => 0,
        ]);

        $page = visit(route('testPayment', ['clientId' => $client->client_id, 'amount' => 1000]));

        $page->assertSee('ICICI Hosted Checkout')
            ->click('Simulate Failure');

        $payload = decryptRedirectPayload($page->url(), $client->client_secret);

        expect($payload['status'])->toBe('failed');
    });
});
