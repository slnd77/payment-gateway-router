<?php

use App\Classes\Encryption;
use App\Enums\PaymentType;
use App\Enums\TransactionType;
use App\Http\Controllers\API\V1\PaymentController;
use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

require_once __DIR__.'/../Browser/Support/helpers.php';

/**
 * PaymentController sits behind the encrypted-payload (initPayment) and
 * X-TOKEN (transaction/transactions) API middleware, so these tests drive
 * it through real HTTP requests built the same way a real client integration
 * would - encrypting the request body / signing the X-TOKEN header - rather
 * than calling controller methods directly. handlePaymentResponse's happy
 * path is already exercised end to end by tests/Browser/PaymentFlowTest.php;
 * these focus on the branches that isn't (validation/gateway failures,
 * lookups, listing).
 */
function encryptedInitPaymentUrl(Client $client, string $siteReferenceId, int $amount = 1000): string
{
    $data = [
        'clientId' => $client->client_id,
        'currency' => 'INR',
        'amount' => $amount,
        'purpose' => 'General Donation',
        'paymentType' => PaymentType::ONE_TIME_PAYMENT->value,
        'transactionType' => TransactionType::SALE->value,
        'reference_id' => $siteReferenceId,
        'customer' => [
            'name' => 'Test Customer',
            'email' => 'dd@dd.com',
            'mobile' => '9303903901',
        ],
    ];

    return route('initPayment', [
        'clientId' => $client->client_id,
        'data' => Encryption::encrypt($data, $client->client_secret),
    ]);
}

function apiTokenHeader(Client $client): array
{
    return ['X-TOKEN' => $client->client_id.':'.$client->client_secret];
}

it('initiates a payment and redirects to the gateway checkout url', function () {
    $client = createBrowserTestClient('PGSimulator', []);
    $siteReferenceId = 'ref-'.Str::random(12);

    $response = $this->get(encryptedInitPaymentUrl($client, $siteReferenceId));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('pg-simulator/checkout');
});

it('returns a clean 400 error when initiatePayment fails', function () {
    $user = User::factory()->create();
    $client = Client::create([
        'uuid' => (string) Str::ulid(),
        'name' => 'No Connection Client',
        'client_id' => Str::upper(Str::random(16)),
        'client_secret' => Str::random(40),
        'website' => 'https://example.test',
        'redirect_uri' => route('test.return'),
        'redirect_uri_separator' => '?',
        'status' => true,
        'user_id' => $user->id,
    ]);

    $response = $this->get(encryptedInitPaymentUrl($client, 'ref-'.Str::random(12)));

    $response->assertStatus(400)
        ->assertJsonPath('error', fn (string $error) => str_contains($error, 'PG Connection not found for this client.'));
});

it('returns a clean 400 error when handlePaymentResponse is called for an unsupported gateway', function () {
    $response = $this->get(route('handlePaymentResponse', ['pgClass' => 'NOT_A_REAL_GATEWAY']));

    $response->assertStatus(400)
        ->assertJson(['error' => 'Invalid payment gateway type.']);
});

it('fetches transaction details by reference id via the X-TOKEN protected endpoint', function () {
    $client = createBrowserTestClient('PGSimulator', []);
    $siteReferenceId = 'ref-'.Str::random(12);

    $this->get(encryptedInitPaymentUrl($client, $siteReferenceId));
    $transaction = Transaction::where('site_reference_id', $siteReferenceId)->firstOrFail();

    $this->get(route('handlePaymentResponse', ['pgClass' => 'PGSimulator']).'?'.http_build_query([
        'transactionDbId' => $transaction->id,
        'status' => 'success',
        'paymentMethod' => 'upi',
    ]));

    $response = $this->withHeaders(apiTokenHeader($client))
        ->get(route('transaction.details', ['reference_id' => $siteReferenceId]));

    $response->assertOk()
        ->assertJsonPath('siteReferenceId', $siteReferenceId)
        ->assertJsonPath('status', 'success');
});

it('returns a clean 404 when transaction details are requested for an unknown reference id', function () {
    $client = createBrowserTestClient('PGSimulator', []);

    $response = $this->withHeaders(apiTokenHeader($client))
        ->get(route('transaction.details', ['reference_id' => 'does-not-exist']));

    $response->assertStatus(404)
        ->assertJson(['error' => 'Transaction not found.']);
});

it('lists a client\'s transactions with pagination metadata', function () {
    $client = createBrowserTestClient('PGSimulator', []);
    $siteReferenceId = 'ref-'.Str::random(12);

    $this->get(encryptedInitPaymentUrl($client, $siteReferenceId));
    $transaction = Transaction::where('site_reference_id', $siteReferenceId)->firstOrFail();

    $this->get(route('handlePaymentResponse', ['pgClass' => 'PGSimulator']).'?'.http_build_query([
        'transactionDbId' => $transaction->id,
        'status' => 'success',
    ]));

    $response = $this->withHeaders(apiTokenHeader($client))
        ->get(route('transactions.list'));

    $response->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']])
        ->assertJsonPath('meta.total', fn (int $total) => $total >= 1);
});

it('returns a clean 400 error when the transactions list request has a malformed date filter', function () {
    $client = createBrowserTestClient('PGSimulator', []);

    $response = $this->withHeaders(apiTokenHeader($client))
        ->get(route('transactions.list', ['start_date' => 'not-a-date']));

    $response->assertStatus(400)->assertJsonStructure(['error']);
});

it('fetches a transaction\'s status directly via getTransactionStatus', function () {
    $client = createBrowserTestClient('PGSimulator', []);
    $siteReferenceId = 'ref-'.Str::random(12);

    $this->get(encryptedInitPaymentUrl($client, $siteReferenceId));
    $transaction = Transaction::where('site_reference_id', $siteReferenceId)->firstOrFail();

    $this->get(route('handlePaymentResponse', ['pgClass' => 'PGSimulator']).'?'.http_build_query([
        'transactionDbId' => $transaction->id,
        'status' => 'success',
    ]));

    // No route currently exposes this action - it's reached directly here,
    // the same way it would be if/when a route is wired up to it.
    $controller = app(PaymentController::class);
    $request = Request::create('/', 'GET', ['transactionDbId' => $transaction->id]);

    $response = $controller->getTransactionStatus($request);

    expect($response->getStatusCode())->toBe(200);
    $payload = json_decode($response->getContent(), true);
    expect($payload['status'])->toBe('success');
});

it('returns a clean 400 error from getTransactionStatus when the transaction does not exist', function () {
    $controller = app(PaymentController::class);
    $request = Request::create('/', 'GET', ['transactionDbId' => '999999999']);

    $response = $controller->getTransactionStatus($request);

    expect($response->getStatusCode())->toBe(400);
    $payload = json_decode($response->getContent(), true);
    expect($payload['error'])->toBe('Transaction not found.');
});

it('returns a 404 page when the demo test-payment route is hit with an unknown client id', function () {
    $response = $this->get(route('testPayment', ['clientId' => 'DOES_NOT_EXIST']));

    $response->assertStatus(404);
});
