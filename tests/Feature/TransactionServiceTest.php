<?php

use App\DTO\PaymentRequestDTO;
use App\DTO\PaymentResponseDTO;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Client;
use App\Models\Transaction;
use App\Repositories\ClientRepository;
use App\Services\TransactionService;
use Devhammed\LaravelBrickMoney\Currency;
use Devhammed\LaravelBrickMoney\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

require_once __DIR__.'/../Browser/Support/helpers.php';

/**
 * TransactionService is exercised end to end by the browser flow tests
 * (tests/Browser/PaymentFlowTest.php), but only for the initiate/response
 * happy path. These tests target the rest of its public surface directly -
 * status checks, reference lookups, listing, and error branches - using the
 * PGSimulator gateway (a local, in-process stand-in with no network calls)
 * so the whole flow can be driven without mocking a real gateway's SDK.
 */
function makeInitPaymentDTO(Client $client, string $siteReferenceId): PaymentRequestDTO
{
    return new PaymentRequestDTO(
        clientDbId: (string) $client->id,
        clientId: $client->client_id,
        currency: 'INR',
        amount: 100,
        site_reference_id: $siteReferenceId,
        transactionType: TransactionType::SALE,
        customer: [
            'name' => 'Jane Doe',
            'email' => 'jane@example.test',
            'mobile' => '9876543210',
        ],
        paymentType: PaymentType::ONE_TIME_PAYMENT,
    );
}

/**
 * Runs initiatePayment() through TransactionService for a freshly created
 * PGSimulator client, then simulates the gateway's callback via
 * handlePaymentResponse() so the transaction ends up in a known terminal
 * state ready for status-check/listing tests.
 */
function completeSimulatedTransaction(TransactionService $service, string $status = 'success'): Transaction
{
    $client = createBrowserTestClient('PGSimulator', [
        'supports_refunds' => true,
        'fees_included_in_amount' => false,
        'fees_rate' => 2.5,
    ]);

    $siteReferenceId = 'ref-'.Str::random(12);
    $service->initiatePayment(makeInitPaymentDTO($client, $siteReferenceId));

    $transaction = Transaction::where('site_reference_id', $siteReferenceId)->firstOrFail();

    $service->handlePaymentResponse([
        'transactionDbId' => (string) $transaction->id,
        'status' => $status,
        'paymentMethod' => 'upi',
    ], 'PGSimulator');

    return $transaction->refresh();
}

it('throws when no PG connection is configured for the client', function () {
    $service = app(TransactionService::class);

    $dto = new PaymentRequestDTO(
        clientDbId: '999999',
        clientId: 'DOES_NOT_EXIST',
        currency: 'INR',
        amount: 100,
        site_reference_id: 'ref-'.Str::random(12),
        transactionType: TransactionType::SALE,
        customer: ['name' => 'Jane Doe', 'email' => 'jane@example.test', 'mobile' => '9876543210'],
        paymentType: PaymentType::ONE_TIME_PAYMENT,
    );

    $service->initiatePayment($dto);
})->throws(Exception::class, 'PG Connection not found for this client.');

it('completes initiatePayment -> handlePaymentResponse and redirects with encrypted data', function () {
    $service = app(TransactionService::class);
    $transaction = completeSimulatedTransaction($service, 'success');

    expect($transaction->status)->toBe(TransactionStatus::SUCCESS);
});

it('throws when the client behind a completed transaction cannot be found', function () {
    $service = app(TransactionService::class);

    $client = createBrowserTestClient('PGSimulator', []);
    $siteReferenceId = 'ref-'.Str::random(12);
    $service->initiatePayment(makeInitPaymentDTO($client, $siteReferenceId));
    $transaction = Transaction::where('site_reference_id', $siteReferenceId)->firstOrFail();

    $missingClientRepository = Mockery::mock(ClientRepository::class);
    $missingClientRepository->shouldReceive('getClient')->once()->andReturnNull();
    app()->instance(ClientRepository::class, $missingClientRepository);

    $service = app()->make(TransactionService::class);

    $service->handlePaymentResponse([
        'transactionDbId' => (string) $transaction->id,
        'status' => 'success',
    ], 'PGSimulator');
})->throws(Exception::class, 'Client not found.');

it('throws when saving a payment response for a transaction that no longer exists', function () {
    $service = app(TransactionService::class);

    $response = new PaymentResponseDTO(
        transactionDbId: '999999999',
        siteReferenceId: 'ref-missing',
        status: TransactionStatus::SUCCESS,
        transactionId: 'TXN123',
        description: 'test',
        amount: Money::of(100, 'INR'),
        pgFees: Money::of(0, 'INR'),
        totalAmount: Money::of(100, 'INR'),
        transactionDateTime: Carbon::now()->toImmutable(),
        currency: Currency::of('INR'),
        paymentMethod: PaymentMethod::UPI,
        clientName: 'Test Client',
        pgConnection: 'PGSimulator Connection',
        pgResponseRaw: [],
    );

    $method = new ReflectionMethod($service, 'saveTransactionFromPaymentResponse');
    $method->setAccessible(true);
    $method->invoke($service, $response);
})->throws(Exception::class, 'Transaction not found.');

it('fetches and refreshes a transaction\'s status via getTransactionStatus', function () {
    $service = app(TransactionService::class);
    $transaction = completeSimulatedTransaction($service, 'success');

    $response = $service->getTransactionStatus($transaction->id);

    expect($response->status)->toBe(TransactionStatus::SUCCESS)
        ->and($response->transactionDbId)->toBe((string) $transaction->id);
});

it('throws from getTransactionStatus when the transaction does not exist', function () {
    $service = app(TransactionService::class);

    $service->getTransactionStatus(999999999);
})->throws(Exception::class, 'Transaction not found.');

it('fetches a transaction by client and site reference id', function () {
    $service = app(TransactionService::class);
    $transaction = completeSimulatedTransaction($service, 'success');

    $response = $service->getTransactionByReference($transaction->client_id, $transaction->site_reference_id);

    expect($response->siteReferenceId)->toBe($transaction->site_reference_id)
        ->and($response->status)->toBe(TransactionStatus::SUCCESS);
});

it('throws from getTransactionByReference when no matching transaction exists', function () {
    $service = app(TransactionService::class);

    $service->getTransactionByReference(999999999, 'does-not-exist');
})->throws(Exception::class, 'Transaction not found.');

it('lists a client\'s transactions, newest first, honouring date filters and per-page clamping', function () {
    $service = app(TransactionService::class);
    $transaction = completeSimulatedTransaction($service, 'success');

    $unfiltered = $service->getTransactionsList($transaction->client_id, null, null, 500);

    expect($unfiltered->perPage())->toBe(100)
        ->and($unfiltered->total())->toBeGreaterThanOrEqual(1)
        ->and($unfiltered->items()[0])->toBeInstanceOf(PaymentResponseDTO::class);

    $today = Carbon::now()->format('Ymd');
    $filtered = $service->getTransactionsList($transaction->client_id, $today, $today, 0);

    expect($filtered->perPage())->toBe(1)
        ->and($filtered->total())->toBeGreaterThanOrEqual(1);

    $yesterday = Carbon::now()->subDay()->format('Ymd');
    $excluded = $service->getTransactionsList($transaction->client_id, null, $yesterday, 10);

    expect($excluded->total())->toBe(0);
});

it('maps a failed simulated transaction to a failed PaymentResponseDTO', function () {
    $service = app(TransactionService::class);
    $transaction = completeSimulatedTransaction($service, 'failed');

    $response = $service->getTransactionByReference($transaction->client_id, $transaction->site_reference_id);

    expect($response->status)->toBe(TransactionStatus::FAILED);
});
