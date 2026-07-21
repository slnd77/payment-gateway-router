<?php

use App\Classes\PaymentGateways\Cashfree;
use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/GatewayTestHelpers.php';

/**
 * @param  array<string, mixed>  $attributes
 */
function makeCashfree(array $attributes = []): Cashfree
{
    return new Cashfree(array_merge([
        'key_id' => 'TEST-CLIENT-ID',
        'key_secret' => 'TEST-CLIENT-SECRET',
        'supports_refunds' => true,
        'fees_included_in_amount' => false,
        'fees_rate' => 2,
    ], $attributes));
}

it('creates an order and returns the local embedded checkout route', function () {
    Http::fake([
        'sandbox.cashfree.com/pg/orders' => Http::response([
            'order_id' => 'TXN123',
            'order_status' => 'ACTIVE',
            'payment_session_id' => 'session_abc123',
        ]),
    ]);

    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('CASHFREE', []);
    $gateway = makeCashfree();

    $url = $gateway->handlePaymentRequest(makePaymentRequestDTO($transaction, $client), $transaction);

    expect($url)->toBe(route('cashfreeEmbeddedCheckout', ['transaction' => $transaction->id, 'session' => 'session_abc123']));
});

it('throws a clean error when order creation fails', function () {
    Http::fake([
        'sandbox.cashfree.com/pg/orders' => Http::response([
            'code' => 'order_meta.return_url_invalid',
            'message' => 'invalid url entered',
        ], 400),
    ]);

    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('CASHFREE', []);
    $gateway = makeCashfree();

    $gateway->handlePaymentRequest(makePaymentRequestDTO($transaction, $client), $transaction);
})->throws(Exception::class);

it('throws when the order response is missing a payment_session_id', function () {
    Http::fake([
        'sandbox.cashfree.com/pg/orders' => Http::response([
            'order_id' => 'TXN123',
            'order_status' => 'ACTIVE',
        ]),
    ]);

    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('CASHFREE', []);
    $gateway = makeCashfree();

    $gateway->handlePaymentRequest(makePaymentRequestDTO($transaction, $client), $transaction);
})->throws(Exception::class, 'missing payment_session_id');

it('renders the checkout page with the real session id and sandbox mode', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('CASHFREE', []);
    $gateway = makeCashfree();

    $view = $gateway->checkoutForm($transaction, 'session_abc123');

    expect($view->getData()['mode'])->toBe('sandbox')
        ->and($view->getData()['paymentSessionId'])->toBe('session_abc123');
});

it('maps a paid order with a successful capture to a successful PaymentResponseDTO', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('CASHFREE', [
        'key_id' => 'TEST-CLIENT-ID',
        'key_secret' => 'TEST-CLIENT-SECRET',
    ]);

    Http::fake([
        'sandbox.cashfree.com/pg/orders/TXN'.$transaction->id.'/payments' => Http::response([
            [
                'cf_payment_id' => 'CFPAY123',
                'payment_status' => 'SUCCESS',
                'payment_group' => 'upi',
                'payment_amount' => 10,
            ],
        ]),
        'sandbox.cashfree.com/pg/orders/TXN'.$transaction->id => Http::response([
            'order_id' => 'TXN'.$transaction->id,
            'order_status' => 'PAID',
        ]),
    ]);

    $gateway = makeCashfree();

    $response = $gateway->handlePaymentResponse(['order_id' => 'TXN'.$transaction->id]);

    expect($response->status)->toBe(TransactionStatus::SUCCESS)
        ->and($response->transactionId)->toBe('CFPAY123')
        ->and($response->paymentMethod)->toBe(PaymentMethod::UPI);
});

it('maps an active (unpaid) order to a pending PaymentResponseDTO', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('CASHFREE', [
        'key_id' => 'TEST-CLIENT-ID',
        'key_secret' => 'TEST-CLIENT-SECRET',
    ]);

    Http::fake([
        'sandbox.cashfree.com/pg/orders/TXN'.$transaction->id => Http::response([
            'order_id' => 'TXN'.$transaction->id,
            'order_status' => 'ACTIVE',
        ]),
    ]);

    $gateway = makeCashfree();

    $response = $gateway->handlePaymentResponse(['order_id' => 'TXN'.$transaction->id]);

    expect($response->status)->toBe(TransactionStatus::PENDING);
});

it('maps an expired order to a failed PaymentResponseDTO', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('CASHFREE', [
        'key_id' => 'TEST-CLIENT-ID',
        'key_secret' => 'TEST-CLIENT-SECRET',
    ]);

    Http::fake([
        'sandbox.cashfree.com/pg/orders/TXN'.$transaction->id => Http::response([
            'order_id' => 'TXN'.$transaction->id,
            'order_status' => 'EXPIRED',
        ]),
    ]);

    $gateway = makeCashfree();

    $response = $gateway->handlePaymentResponse(['order_id' => 'TXN'.$transaction->id]);

    expect($response->status)->toBe(TransactionStatus::FAILED);
});

it('fetches the transaction status directly via getTransactionStatus/verifyPayment', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('CASHFREE', []);

    Http::fake([
        'sandbox.cashfree.com/pg/orders/TXN'.$transaction->id.'/payments' => Http::response([
            ['cf_payment_id' => 'CFPAY999', 'payment_status' => 'SUCCESS', 'payment_group' => 'credit_card', 'payment_amount' => 10],
        ]),
        'sandbox.cashfree.com/pg/orders/TXN'.$transaction->id => Http::response([
            'order_id' => 'TXN'.$transaction->id,
            'order_status' => 'PAID',
        ]),
    ]);

    $gateway = makeCashfree();

    $status = $gateway->getTransactionStatus($transaction);
    $verify = $gateway->verifyPayment($transaction);

    expect($status->status)->toBe(TransactionStatus::SUCCESS)
        ->and($status->paymentMethod)->toBe(PaymentMethod::CARD)
        ->and($verify->status)->toBe(TransactionStatus::SUCCESS);
});

it('reports refund support from its connection attributes', function () {
    expect(makeCashfree(['supports_refunds' => true])->isRefundSupported())->toBeTrue()
        ->and(makeCashfree(['supports_refunds' => false])->isRefundSupported())->toBeFalse();
});

it('processes a refund when refunds are supported', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('CASHFREE', [
        'supports_refunds' => true,
    ], ['transaction_id' => 'CFPAY123']);

    Http::fake([
        'sandbox.cashfree.com/pg/orders/TXN'.$transaction->id.'/refunds' => Http::response([
            'refund_status' => 'SUCCESS',
            'refund_id' => 'RFD1',
        ]),
    ]);

    $gateway = makeCashfree(['supports_refunds' => true]);

    $response = $gateway->refundPayment($transaction, makePaymentRefundDTO($transaction, $client));

    expect($response->status)->toBe(TransactionStatus::REFUNDED);
});

it('refuses to refund when refunds are not supported', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('CASHFREE', [
        'supports_refunds' => false,
    ]);
    $gateway = makeCashfree(['supports_refunds' => false]);

    $gateway->refundPayment($transaction, makePaymentRefundDTO($transaction, $client));
})->throws(Exception::class, 'Refunds are not supported by this Cashfree connection.');
