<?php

use App\Classes\PaymentGateways\ICICI;
use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/GatewayTestHelpers.php';

/**
 * @param  array<string, mixed>  $attributes
 */
function makeIcici(array $attributes = []): ICICI
{
    return new ICICI(array_merge([
        'merchant_id' => 'TESTMERCHANT',
        'aggregator_id' => 'TESTAGG',
        'supports_refunds' => false,
        'encryption_key' => 'test-encryption-key',
        'sub_merchant_id' => 'TESTSUBM',
        'paymode' => '0',
        'fees_included_in_amount' => false,
        'fees_rate' => 0,
    ], $attributes));
}

it('builds a redirect URL from the initiateSale response', function () {
    Http::fake([
        'pgpayuat.icicibank.com/*' => Http::response([
            'responseCode' => 'R1000',
            'redirectURI' => 'https://pgpayuat.icicibank.com/hosted-checkout',
            'tranCtx' => 'MOCKTRANCTX123',
        ]),
    ]);

    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('ICICI', []);
    $gateway = makeIcici();

    $url = $gateway->handlePaymentRequest(makePaymentRequestDTO($transaction, $client), $transaction);

    expect($url)->toBe('https://pgpayuat.icicibank.com/hosted-checkout?tranCtx=MOCKTRANCTX123');
});

it('throws when the initiateSale call fails', function () {
    Http::fake([
        'pgpayuat.icicibank.com/*' => Http::response(['error' => 'down'], 500),
    ]);

    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('ICICI', []);
    $gateway = makeIcici();

    $gateway->handlePaymentRequest(makePaymentRequestDTO($transaction, $client), $transaction);
})->throws(Exception::class);

it('throws when the bank does not acknowledge the request', function () {
    Http::fake([
        'pgpayuat.icicibank.com/*' => Http::response(['responseCode' => 'R1001']),
    ]);

    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('ICICI', []);
    $gateway = makeIcici();

    $gateway->handlePaymentRequest(makePaymentRequestDTO($transaction, $client), $transaction);
})->throws(Exception::class);

it('maps a successful callback to a successful PaymentResponseDTO', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('ICICI', []);
    $gateway = makeIcici();

    $response = $gateway->handlePaymentResponse([
        'responseCode' => '0000',
        'respDescription' => 'Transaction Successful',
        'addlParam1' => (string) $transaction->id,
        'addlParam2' => $transaction->site_reference_id,
        'amount' => '10.00',
        'oth_charge' => '0.20',
        'txnID' => 'ICICITXN123',
        'paymentDateTime' => now()->format('YmdHis'),
        'paymentMode' => 'CARD',
    ]);

    expect($response->status)->toBe(TransactionStatus::SUCCESS)
        ->and($response->transactionId)->toBe('ICICITXN123')
        ->and($response->paymentMethod)->toBe(PaymentMethod::CARD);
});

it('maps a failed callback response to a failed PaymentResponseDTO', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('ICICI', []);
    $gateway = makeIcici();

    $response = $gateway->handlePaymentResponse([
        'responseCode' => '0001',
        'respDescription' => 'Transaction Declined',
        'merchantTxnNo' => $transaction->id.'-'.$transaction->site_reference_id,
    ]);

    expect($response->status)->toBe(TransactionStatus::FAILED);
});

it('maps a pending status-check response code to PENDING', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('ICICI', []);
    $gateway = makeIcici();

    $response = $gateway->handlePaymentResponse([
        'responseCode' => 'P0030',
        'merchantTxnNo' => $transaction->id.'-'.$transaction->site_reference_id,
    ]);

    expect($response->status)->toBe(TransactionStatus::PENDING);
});

it('fetches the transaction status via the STATUS endpoint', function () {
    Http::fake([
        'pgpayuat.icicibank.com/*' => function (ClientRequest $request) {
            expect($request['transactionType'])->toBe('STATUS');

            return Http::response([
                'responseCode' => '0000',
                'respDescription' => 'Transaction Successful',
                'amount' => '10.00',
                'oth_charge' => '0.20',
                'txnID' => 'ICICITXN999',
                'paymentDateTime' => now()->format('YmdHis'),
                'paymentMode' => 'UPI',
            ]);
        },
    ]);

    ['transaction' => $transaction] = createGatewayTestTransaction('ICICI', []);
    $gateway = makeIcici();

    $response = $gateway->getTransactionStatus($transaction);
    $verify = $gateway->verifyPayment($transaction);

    expect($response->status)->toBe(TransactionStatus::SUCCESS)
        ->and($response->transactionId)->toBe('ICICITXN999')
        ->and($verify->status)->toBe(TransactionStatus::SUCCESS);
});

it('reports refund support from its connection attributes', function () {
    expect(makeIcici(['supports_refunds' => true])->isRefundSupported())->toBeTrue()
        ->and(makeIcici(['supports_refunds' => false])->isRefundSupported())->toBeFalse();
});

it('never supports refunds through refundPayment, regardless of the connection flag', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('ICICI', [
        'supports_refunds' => true,
    ]);
    $gateway = makeIcici(['supports_refunds' => true]);

    $gateway->refundPayment($transaction, makePaymentRefundDTO($transaction, $client));
})->throws(Exception::class, 'Refunds are not supported by ICICI.');
