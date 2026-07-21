<?php

use App\Classes\PaymentGateways\Razorpay;
use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use Devhammed\LaravelBrickMoney\Money;

require_once __DIR__.'/GatewayTestHelpers.php';

/**
 * Razorpay's SDK talks to the network through its own HTTP transport (not
 * Laravel's Http facade), so it can't be intercepted with Http::fake() the
 * way ICICI/Cashfree can. These tests exercise every code path that doesn't
 * require an actual network round trip: credential validation, response
 * mapping, fee calculation, refund-support flags, and checkout view
 * rendering. The order/payment/refund API calls themselves are covered by
 * the manual sandbox verification documented in the gateway's PR.
 */
/**
 * @param  array<string, mixed>  $attributes
 */
function makeRazorpay(array $attributes = []): Razorpay
{
    return new Razorpay(array_merge([
        'key_id' => 'rzp_test_key',
        'key_secret' => 'rzp_test_secret',
        'supports_refunds' => true,
        'fees_included_in_amount' => false,
        'fees_rate' => 2,
    ], $attributes));
}

it('refuses to create an order when API credentials are missing', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('RAZORPAY', []);
    $gateway = makeRazorpay(['key_id' => null, 'key_secret' => null]);

    $gateway->handlePaymentRequest(makePaymentRequestDTO($transaction, $client), $transaction);
})->throws(Exception::class, 'Missing Razorpay API credentials.');

it('treats an explicitly cancelled callback as a failed payment without contacting Razorpay', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('RAZORPAY', []);
    $gateway = makeRazorpay();

    $response = $gateway->handlePaymentResponse([
        'transactionDbId' => (string) $transaction->id,
        'status' => 'cancelled',
    ]);

    expect($response->status)->toBe(TransactionStatus::FAILED)
        ->and($response->description)->toBe('Payment was cancelled by the customer.');
});

it('treats a callback with no signature fields as failed without contacting Razorpay', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('RAZORPAY', []);
    $gateway = makeRazorpay();

    $response = $gateway->handlePaymentResponse([
        'transactionDbId' => (string) $transaction->id,
        'error_description' => 'Payment was declined by the bank.',
    ]);

    expect($response->status)->toBe(TransactionStatus::FAILED)
        ->and($response->description)->toBe('Payment was declined by the bank.');
});

it('throws when the transaction referenced in the response does not exist', function () {
    $gateway = makeRazorpay();

    $gateway->handlePaymentResponse(['transactionDbId' => '999999999']);
})->throws(Exception::class, 'Transaction not found.');

it('renders the auto-submitting checkout form with the real order id and connection details', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('RAZORPAY', [
        'key_id' => 'rzp_test_key',
    ]);
    $gateway = makeRazorpay();

    $view = $gateway->checkoutForm($transaction, 'order_ABC123');

    expect($view->getData()['orderId'])->toBe('order_ABC123')
        ->and($view->getData()['keyId'])->toBe('rzp_test_key')
        ->and($view->getData()['checkoutEndpoint'])->toBe(Razorpay::EMBEDDED_CHECKOUT_ENDPOINT);
});

it('maps Razorpay payment methods to the app-level PaymentMethod enum', function () {
    $gateway = makeRazorpay();

    expect(callGatewayMethod($gateway, 'mapPaymentMethod', ['card']))->toBe(PaymentMethod::CARD)
        ->and(callGatewayMethod($gateway, 'mapPaymentMethod', ['netbanking']))->toBe(PaymentMethod::NETBANKING)
        ->and(callGatewayMethod($gateway, 'mapPaymentMethod', ['wallet']))->toBe(PaymentMethod::WALLET)
        ->and(callGatewayMethod($gateway, 'mapPaymentMethod', ['upi']))->toBe(PaymentMethod::UPI)
        ->and(callGatewayMethod($gateway, 'mapPaymentMethod', ['emi']))->toBe(PaymentMethod::CARD)
        ->and(callGatewayMethod($gateway, 'mapPaymentMethod', ['something-unknown']))->toBe(PaymentMethod::UNKNOWN);
});

it('maps Razorpay payment statuses to the app-level TransactionStatus enum', function () {
    $gateway = makeRazorpay();

    expect(callGatewayMethod($gateway, 'mapPaymentStatus', ['captured']))->toBe(TransactionStatus::SUCCESS)
        ->and(callGatewayMethod($gateway, 'mapPaymentStatus', ['authorized']))->toBe(TransactionStatus::PROCESSING)
        ->and(callGatewayMethod($gateway, 'mapPaymentStatus', ['refunded']))->toBe(TransactionStatus::REFUNDED)
        ->and(callGatewayMethod($gateway, 'mapPaymentStatus', ['created']))->toBe(TransactionStatus::PENDING)
        ->and(callGatewayMethod($gateway, 'mapPaymentStatus', ['failed']))->toBe(TransactionStatus::FAILED);
});

it('calculates gateway fees from the connection fee rate', function () {
    $gateway = makeRazorpay(['fees_rate' => 2]);

    $fees = callGatewayMethod($gateway, 'calculateFees', [Money::of(1000, 'INR')]);

    expect((string) $fees->getAmount())->toBe('20.00');
});

it('reports refund support from its connection attributes', function () {
    expect(makeRazorpay(['supports_refunds' => true])->isRefundSupported())->toBeTrue()
        ->and(makeRazorpay(['supports_refunds' => false])->isRefundSupported())->toBeFalse();
});

it('refuses to refund when refunds are not supported', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('RAZORPAY', [
        'supports_refunds' => false,
    ]);
    $gateway = makeRazorpay(['supports_refunds' => false]);

    $gateway->refundPayment($transaction, makePaymentRefundDTO($transaction, $client));
})->throws(Exception::class, 'Refunds are not supported by this Razorpay connection.');

it('refuses to refund a transaction with no recorded Razorpay payment id', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('RAZORPAY', [
        'supports_refunds' => true,
    ]);
    $gateway = makeRazorpay(['supports_refunds' => true]);

    $gateway->refundPayment($transaction, makePaymentRefundDTO($transaction, $client));
})->throws(Exception::class, 'No Razorpay payment id recorded for this transaction.');

it('refuses to check status for a transaction with no recorded Razorpay payment id', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('RAZORPAY', []);
    $gateway = makeRazorpay();

    $gateway->getTransactionStatus($transaction);
})->throws(Exception::class, 'No Razorpay payment id recorded for this transaction.');
