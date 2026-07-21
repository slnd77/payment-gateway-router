<?php

/**
 * Shared fixture builder for the payment gateway class tests in this
 * directory. Not a test file itself — each *Test.php here requires it
 * explicitly, since Pest auto-discovers files by name, not by directory.
 */

use App\DTO\PaymentRefundDTO;
use App\DTO\PaymentRequestDTO;
use App\Enums\ConnectionType;
use App\Enums\PaymentType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Client;
use App\Models\ClientCustomer;
use App\Models\PGConnection;
use App\Models\Transaction;
use App\Models\User;
use Devhammed\LaravelBrickMoney\Currency;
use Devhammed\LaravelBrickMoney\Money;
use Illuminate\Support\Str;

/**
 * Creates a Transaction (with its Client/ClientCustomer/PGConnection) ready
 * to be handed straight to a payment gateway class's methods.
 *
 * @param  array<string, mixed>  $attributes  the PGConnection's gateway-specific attributes
 * @param  array<string, mixed>  $transactionOverrides
 * @return array{transaction: Transaction, pgConnection: PGConnection, client: Client}
 */
function createGatewayTestTransaction(string $pgClass, array $attributes, array $transactionOverrides = []): array
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
        'name' => 'Gateway Test Client',
        'client_id' => Str::upper(Str::random(16)),
        'client_secret' => Str::random(40),
        'website' => 'https://example.test',
        'redirect_uri' => 'https://example.test/callback',
        'redirect_uri_separator' => '?',
        'status' => true,
        'user_id' => $user->id,
    ]);

    $customer = ClientCustomer::create([
        'client_id' => $client->id,
        'uuid' => (string) Str::ulid(),
        'name' => 'Jane Doe',
        'email' => 'jane@example.test',
        'mobile' => '9876543210',
    ]);

    // 'amount'/'currency' in $transactionOverrides only control the Money/Currency
    // objects built below - pull them out before merging so the raw scalars don't
    // clobber those objects when merged into the create() call.
    $rawAmount = $transactionOverrides['amount'] ?? 1000;
    $rawCurrency = $transactionOverrides['currency'] ?? 'INR';
    unset($transactionOverrides['amount'], $transactionOverrides['currency']);

    $amount = Money::of($rawAmount, $rawCurrency);

    $transaction = $customer->transactions()->create(array_merge([
        'client_id' => $client->id,
        'site_reference_id' => 'ref-'.Str::random(12),
        'pg_connection_id' => $pgConnection->id,
        'amount' => $amount,
        'currency' => Currency::of($rawCurrency),
        'transaction_amount' => $amount,
        'status' => TransactionStatus::PENDING,
        'request_data' => [],
    ], $transactionOverrides));

    // Columns left to their DB defaults (pg_fees, total_amount, ...) aren't
    // pulled back into the in-memory model by create(), so accessing them
    // through their Money casts would see a raw `null` instead of `0`.
    // Refresh so every cast has a real DB-backed value to work with.
    $transaction->refresh();

    return [
        'transaction' => $transaction,
        'pgConnection' => $pgConnection,
        'client' => $client,
    ];
}

/**
 * Builds a PaymentRequestDTO matching a transaction created by
 * createGatewayTestTransaction(), so handlePaymentRequest() can be called
 * directly without going through the /initPayment HTTP layer.
 */
function makePaymentRequestDTO(Transaction $transaction, Client $client, string $currency = 'INR', int|float $amount = 10): PaymentRequestDTO
{
    return new PaymentRequestDTO(
        clientDbId: (string) $client->id,
        clientId: $client->client_id,
        currency: $currency,
        amount: $amount,
        site_reference_id: $transaction->site_reference_id,
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
 * Builds a PaymentRefundDTO for refundPayment() tests.
 */
function makePaymentRefundDTO(Transaction $transaction, Client $client, string $currency = 'INR', int|float $amount = 10): PaymentRefundDTO
{
    return new PaymentRefundDTO(
        clientDbId: (string) $client->id,
        clientId: $client->client_id,
        currency: $currency,
        amount: $amount,
        site_reference_id: $transaction->site_reference_id,
        refundReason: 'Customer requested a refund',
    );
}

/**
 * Invokes a protected/private method on an object via reflection, so gateway
 * classes' internal mapping/helper logic can be unit-tested directly without
 * needing to go through a full (network-hitting) public method.
 *
 * @param  array<mixed>  $args
 */
function callGatewayMethod(object $object, string $method, array $args = []): mixed
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($object, ...$args);
}
