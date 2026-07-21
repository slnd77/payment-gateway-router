<?php

use App\Classes\PaymentGateways\PGSimulator;
use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Models\PaymentGatewayConnectionApiLog;

require_once __DIR__.'/GatewayTestHelpers.php';

/**
 * @param  array<string, mixed>  $attributes
 */
function makePgSimulator(array $attributes = []): PGSimulator
{
    return new PGSimulator(array_merge([
        'supports_refunds' => true,
        'fees_included_in_amount' => false,
        'fees_rate' => 2.5,
    ], $attributes));
}

it('routes handlePaymentRequest to the local pg-simulator checkout page', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('PGSimulator', []);
    $gateway = makePgSimulator();

    $url = $gateway->handlePaymentRequest(makePaymentRequestDTO($transaction, $client), $transaction);

    expect($url)->toBe(route('pgSimulatorCheckout', ['transaction' => $transaction->id]));
});

it('maps a simulated success response to a successful PaymentResponseDTO', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('PGSimulator', []);
    $gateway = makePgSimulator();

    $response = $gateway->handlePaymentResponse([
        'transactionDbId' => (string) $transaction->id,
        'status' => 'success',
        'transactionId' => 'SIMTEST123',
        'paymentMethod' => 'upi',
    ]);

    expect($response->status)->toBe(TransactionStatus::SUCCESS)
        ->and($response->transactionId)->toBe('SIMTEST123')
        ->and($response->paymentMethod)->toBe(PaymentMethod::UPI)
        ->and($response->transactionDbId)->toBe((string) $transaction->id);

    expect(PaymentGatewayConnectionApiLog::where('transaction_id', $transaction->id)->exists())->toBeTrue();
});

it('maps a simulated failure response to a failed PaymentResponseDTO', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('PGSimulator', []);
    $gateway = makePgSimulator();

    $response = $gateway->handlePaymentResponse([
        'transactionDbId' => (string) $transaction->id,
        'status' => 'failed',
    ]);

    expect($response->status)->toBe(TransactionStatus::FAILED);
});

it('defaults to a failed status when the response has no recognisable status', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('PGSimulator', []);
    $gateway = makePgSimulator();

    $response = $gateway->handlePaymentResponse([
        'transactionDbId' => (string) $transaction->id,
        'status' => 'not-a-real-status',
    ]);

    expect($response->status)->toBe(TransactionStatus::FAILED);
});

it('throws when the transaction referenced in the response does not exist', function () {
    $gateway = makePgSimulator();

    $gateway->handlePaymentResponse(['transactionDbId' => '999999999', 'status' => 'success']);
})->throws(Exception::class, 'Transaction not found.');

it('reports its own status back for getTransactionStatus/verifyPayment', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('PGSimulator', [], [
        'status' => TransactionStatus::SUCCESS,
        'transaction_id' => 'SIMTEST999',
    ]);
    $gateway = makePgSimulator();

    $status = $gateway->getTransactionStatus($transaction);
    $verify = $gateway->verifyPayment($transaction);

    expect($status->status)->toBe(TransactionStatus::SUCCESS)
        ->and($status->transactionId)->toBe('SIMTEST999')
        ->and($verify->status)->toBe(TransactionStatus::SUCCESS);
});

it('reports refund support from its connection attributes', function () {
    expect(makePgSimulator(['supports_refunds' => true])->isRefundSupported())->toBeTrue()
        ->and(makePgSimulator(['supports_refunds' => false])->isRefundSupported())->toBeFalse();
});

it('processes a refund when refunds are supported', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('PGSimulator', [
        'supports_refunds' => true,
    ]);
    $gateway = makePgSimulator(['supports_refunds' => true]);

    $response = $gateway->refundPayment($transaction, makePaymentRefundDTO($transaction, $client));

    expect($response->status)->toBe(TransactionStatus::REFUNDED);
});

it('refuses to refund when refunds are not supported', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('PGSimulator', [
        'supports_refunds' => false,
    ]);
    $gateway = makePgSimulator(['supports_refunds' => false]);

    $gateway->refundPayment($transaction, makePaymentRefundDTO($transaction, $client));
})->throws(Exception::class, 'Refunds are not supported by this PG Simulator connection.');
