<?php

use App\Classes\PaymentGateways\Stripe;
use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use Devhammed\LaravelBrickMoney\Money;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;

require_once __DIR__.'/GatewayTestHelpers.php';

/**
 * Stripe's SDK talks to the network through its own HTTP client (not
 * Laravel's Http facade), so it can't be intercepted with Http::fake() the
 * way ICICI/Cashfree can. These tests exercise every code path that doesn't
 * require an actual network round trip: credential validation, response
 * mapping (using real, hand-built SDK model instances via constructFrom()),
 * fee calculation, and refund-support flags. The checkout-session/refund
 * API calls themselves were verified manually against the real Stripe test
 * API when this gateway was built.
 */
/**
 * @param  array<string, mixed>  $attributes
 */
function makeStripe(array $attributes = []): Stripe
{
    return new Stripe(array_merge([
        'key_secret' => 'sk_test_fake',
        'supports_refunds' => true,
        'fees_included_in_amount' => false,
        'fees_rate' => 2.9,
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeStripeSession(string $status, string $paymentStatus, array $overrides = []): Session
{
    return Session::constructFrom(array_merge([
        'id' => 'cs_test_123',
        'status' => $status,
        'payment_status' => $paymentStatus,
        'amount_total' => 1000,
        'currency' => 'usd',
        'payment_method_types' => ['card'],
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeStripePaymentIntent(string $status, array $overrides = []): PaymentIntent
{
    return PaymentIntent::constructFrom(array_merge([
        'id' => 'pi_123',
        'status' => $status,
        'amount' => 1000,
        'currency' => 'usd',
        'created' => now()->timestamp,
    ], $overrides));
}

afterEach(function () {
    // Undo mockStripeHttpClient()'s global override so later tests (in this
    // file or others) that touch Stripe's SDK fall back to its real client.
    \Stripe\ApiRequestor::setHttpClient(null);
});

it('creates a checkout session and returns its hosted url', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('STRIPE', []);
    $gateway = makeStripe();

    mockStripeHttpClient([
        ['body' => [
            'id' => 'cs_test_123',
            'object' => 'checkout.session',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
        ]],
    ]);

    $url = $gateway->handlePaymentRequest(makePaymentRequestDTO($transaction, $client), $transaction);

    expect($url)->toBe('https://checkout.stripe.com/c/pay/cs_test_123');
});

it('wraps a checkout-session API failure in a clean exception', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('STRIPE', []);
    $gateway = makeStripe();

    mockStripeHttpClient([
        ['body' => ['error' => ['message' => 'Your card was declined.', 'type' => 'card_error']], 'status' => 402],
    ]);

    $gateway->handlePaymentRequest(makePaymentRequestDTO($transaction, $client), $transaction);
})->throws(Exception::class, 'Payment Gateway Error:');

it('retrieves a paid checkout session and maps it to a successful PaymentResponseDTO', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('STRIPE', ['key_secret' => 'sk_test_fake'], ['currency' => 'USD', 'amount' => 10]);
    $gateway = makeStripe();

    mockStripeHttpClient([
        ['body' => [
            'id' => 'cs_test_123',
            'object' => 'checkout.session',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 1000,
            'currency' => 'usd',
            'payment_method_types' => ['card'],
            'payment_intent' => [
                'id' => 'pi_123',
                'object' => 'payment_intent',
                'status' => 'succeeded',
                'amount' => 1000,
                'currency' => 'usd',
                'created' => now()->timestamp,
            ],
        ]],
    ]);

    $response = $gateway->handlePaymentResponse([
        'transactionDbId' => (string) $transaction->id,
        'session_id' => 'cs_test_123',
    ]);

    expect($response->status)->toBe(TransactionStatus::SUCCESS)
        ->and($response->transactionId)->toBe('pi_123')
        ->and($response->paymentMethod)->toBe(PaymentMethod::CARD);
});

it('wraps a checkout-session retrieval failure in a clean exception', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('STRIPE', ['key_secret' => 'sk_test_fake']);
    $gateway = makeStripe();

    mockStripeHttpClient([
        ['body' => ['error' => ['message' => 'No such checkout session.', 'type' => 'invalid_request_error']], 'status' => 404],
    ]);

    $gateway->handlePaymentResponse([
        'transactionDbId' => (string) $transaction->id,
        'session_id' => 'cs_test_missing',
    ]);
})->throws(Exception::class, 'Payment Gateway Error:');

it('fetches a succeeded payment intent and maps it to a successful PaymentResponseDTO', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('STRIPE', [], ['currency' => 'USD', 'amount' => 10]);
    $transaction->forceFill(['transaction_id' => 'pi_123'])->save();
    $gateway = makeStripe();

    mockStripeHttpClient([
        ['body' => [
            'id' => 'pi_123',
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => 1000,
            'currency' => 'usd',
            'created' => now()->timestamp,
        ]],
    ]);

    $response = $gateway->getTransactionStatus($transaction);

    expect($response->status)->toBe(TransactionStatus::SUCCESS)
        ->and($response->transactionId)->toBe('pi_123');
});

it('wraps a payment-intent status-check failure in a clean exception', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('STRIPE', []);
    $transaction->forceFill(['transaction_id' => 'pi_missing'])->save();
    $gateway = makeStripe();

    mockStripeHttpClient([
        ['body' => ['error' => ['message' => 'No such payment_intent.', 'type' => 'invalid_request_error']], 'status' => 404],
    ]);

    $gateway->getTransactionStatus($transaction);
})->throws(Exception::class, 'Payment Gateway Error:');

it('processes a successful refund via the Stripe API', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('STRIPE', [
        'supports_refunds' => true,
    ]);
    $transaction->forceFill(['transaction_id' => 'pi_123'])->save();
    $gateway = makeStripe(['supports_refunds' => true]);

    mockStripeHttpClient([
        ['body' => [
            'id' => 're_123',
            'object' => 'refund',
            'status' => 'succeeded',
            'amount' => 1000,
            'currency' => 'inr',
            'payment_intent' => 'pi_123',
        ]],
    ]);

    $response = $gateway->refundPayment($transaction, makePaymentRefundDTO($transaction, $client));

    expect($response->status)->toBe(TransactionStatus::REFUNDED)
        ->and($response->description)->toBe('Stripe refund succeeded');
});

it('wraps a refund API failure in a clean exception', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('STRIPE', [
        'supports_refunds' => true,
    ]);
    $transaction->forceFill(['transaction_id' => 'pi_123'])->save();
    $gateway = makeStripe(['supports_refunds' => true]);

    mockStripeHttpClient([
        ['body' => ['error' => ['message' => 'Charge already refunded.', 'type' => 'invalid_request_error']], 'status' => 400],
    ]);

    $gateway->refundPayment($transaction, makePaymentRefundDTO($transaction, $client));
})->throws(Exception::class, 'Payment Gateway Error:');

it('accepts a raw connection-type string just like a ConnectionType enum', function () {
    $gateway = new Stripe(['key_secret' => 'sk_test_fake'], 'TEST');

    expect(callGatewayMethod($gateway, 'client'))->toBeInstanceOf(\Stripe\StripeClient::class);
});

it('delegates verifyPayment() to getTransactionStatus()', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('STRIPE', []);
    $transaction->forceFill(['transaction_id' => 'pi_123'])->save();
    $gateway = makeStripe();

    mockStripeHttpClient([
        ['body' => [
            'id' => 'pi_123',
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => 1000,
            'currency' => 'usd',
            'created' => now()->timestamp,
        ]],
    ]);

    $response = $gateway->verifyPayment($transaction);

    expect($response->status)->toBe(TransactionStatus::SUCCESS);
});

it('refuses to create a checkout session when API credentials are missing', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('STRIPE', []);
    $gateway = makeStripe(['key_secret' => null]);

    $gateway->handlePaymentRequest(makePaymentRequestDTO($transaction, $client), $transaction);
})->throws(Exception::class, 'Missing Stripe API credentials.');

it('treats an explicitly cancelled callback as failed without contacting Stripe', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('STRIPE', []);
    $gateway = makeStripe();

    $response = $gateway->handlePaymentResponse([
        'transactionDbId' => (string) $transaction->id,
        'status' => 'cancelled',
    ]);

    expect($response->status)->toBe(TransactionStatus::FAILED)
        ->and($response->description)->toBe('Payment was cancelled by the customer.');
});

it('throws when the transaction referenced in the response does not exist', function () {
    $gateway = makeStripe();

    $gateway->handlePaymentResponse(['transactionDbId' => '999999999']);
})->throws(Exception::class, 'Transaction not found.');

it('throws when the callback is missing a session id', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('STRIPE', []);
    $gateway = makeStripe();

    $gateway->handlePaymentResponse(['transactionDbId' => (string) $transaction->id]);
})->throws(Exception::class, 'Missing Stripe checkout session id in callback.');

it('maps a paid session with an expanded payment intent to a successful PaymentResponseDTO', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('STRIPE', [], ['currency' => 'USD', 'amount' => 10]);
    $gateway = makeStripe();

    $session = makeStripeSession('complete', 'paid', [
        'payment_intent' => makeStripePaymentIntent('succeeded'),
    ]);

    $response = callGatewayMethod($gateway, 'mapSessionStatus', [$session]);

    expect($response)->toBe(TransactionStatus::SUCCESS);
});

it('maps an open, unpaid session to pending', function () {
    $gateway = makeStripe();

    $session = makeStripeSession('open', 'unpaid');

    expect(callGatewayMethod($gateway, 'mapSessionStatus', [$session]))->toBe(TransactionStatus::PENDING);
});

it('maps an expired session to failed', function () {
    $gateway = makeStripe();

    $session = makeStripeSession('expired', 'unpaid');

    expect(callGatewayMethod($gateway, 'mapSessionStatus', [$session]))->toBe(TransactionStatus::FAILED);
});

it('maps Stripe payment method types to the app-level PaymentMethod enum', function () {
    $gateway = makeStripe();

    expect(callGatewayMethod($gateway, 'mapPaymentMethod', ['card']))->toBe(PaymentMethod::CARD)
        ->and(callGatewayMethod($gateway, 'mapPaymentMethod', ['upi']))->toBe(PaymentMethod::UPI)
        ->and(callGatewayMethod($gateway, 'mapPaymentMethod', ['us_bank_account']))->toBe(PaymentMethod::NETBANKING)
        ->and(callGatewayMethod($gateway, 'mapPaymentMethod', ['wechat_pay']))->toBe(PaymentMethod::WALLET)
        ->and(callGatewayMethod($gateway, 'mapPaymentMethod', ['something-unknown']))->toBe(PaymentMethod::UNKNOWN);
});

it('maps Stripe payment intent statuses to the app-level TransactionStatus enum', function () {
    $gateway = makeStripe();

    expect(callGatewayMethod($gateway, 'mapPaymentIntentStatus', ['succeeded']))->toBe(TransactionStatus::SUCCESS)
        ->and(callGatewayMethod($gateway, 'mapPaymentIntentStatus', ['processing']))->toBe(TransactionStatus::PROCESSING)
        ->and(callGatewayMethod($gateway, 'mapPaymentIntentStatus', ['requires_payment_method']))->toBe(TransactionStatus::PENDING)
        ->and(callGatewayMethod($gateway, 'mapPaymentIntentStatus', ['canceled']))->toBe(TransactionStatus::FAILED);
});

it('calculates gateway fees from the connection fee rate', function () {
    $gateway = makeStripe(['fees_rate' => 2.9]);

    $fees = callGatewayMethod($gateway, 'calculateFees', [Money::of(1000, 'USD')]);

    expect((string) $fees->getAmount())->toBe('29.00');
});

it('reports refund support from its connection attributes', function () {
    expect(makeStripe(['supports_refunds' => true])->isRefundSupported())->toBeTrue()
        ->and(makeStripe(['supports_refunds' => false])->isRefundSupported())->toBeFalse();
});

it('refuses to refund when refunds are not supported', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('STRIPE', [
        'supports_refunds' => false,
    ]);
    $gateway = makeStripe(['supports_refunds' => false]);

    $gateway->refundPayment($transaction, makePaymentRefundDTO($transaction, $client));
})->throws(Exception::class, 'Refunds are not supported by this Stripe connection.');

it('refuses to refund a transaction with no recorded Stripe payment intent id', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('STRIPE', [
        'supports_refunds' => true,
    ]);
    $gateway = makeStripe(['supports_refunds' => true]);

    $gateway->refundPayment($transaction, makePaymentRefundDTO($transaction, $client));
})->throws(Exception::class, 'No Stripe payment intent id recorded for this transaction.');

it('refuses to check status for a transaction with no recorded Stripe payment intent id', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('STRIPE', []);
    $gateway = makeStripe();

    $gateway->getTransactionStatus($transaction);
})->throws(Exception::class, 'No Stripe payment intent id recorded for this transaction.');
