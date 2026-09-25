<?php

use App\Classes\PaymentGateways\PayU;
use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/GatewayTestHelpers.php';

const PAYU_TEST_KEY = 'TEST-KEY';
const PAYU_TEST_SALT = 'TEST-SALT';

/**
 * @param  array<string, mixed>  $attributes
 */
function makePayU(array $attributes = []): PayU
{
    return new PayU(array_merge([
        'key' => PAYU_TEST_KEY,
        'salt' => PAYU_TEST_SALT,
        'supports_refunds' => true,
        'fees_included_in_amount' => false,
        'fees_rate' => 2,
    ], $attributes));
}

/**
 * Independently reimplements PayU's documented reverse-hash formula
 * (sha512(salt|status||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key))
 * to build realistic, correctly-signed postback payloads for tests -
 * without calling into the gateway class's own hashing method, so a bug
 * there wouldn't be masked by testing against itself.
 *
 * @return array<string, string>
 */
function payuSignedResponse(string $txnid, string $status, string $amount, array $overrides = []): array
{
    $fields = array_merge([
        'txnid' => $txnid,
        'status' => $status,
        'amount' => $amount,
        'productinfo' => 'Payment for ref-test',
        'firstname' => 'Jane Doe',
        'email' => 'jane@example.test',
        'udf1' => '',
        'udf2' => '',
        'mihpayid' => 'MIHPAY123',
        'mode' => 'CC',
    ], $overrides);

    $fields['hash'] = hash('sha512', implode('|', [
        PAYU_TEST_SALT,
        $fields['status'],
        '', '', '', '', '',
        '', // udf5
        '', // udf4
        '', // udf3
        $fields['udf2'],
        $fields['udf1'],
        $fields['email'],
        $fields['firstname'],
        $fields['productinfo'],
        $fields['amount'],
        $fields['txnid'],
        PAYU_TEST_KEY,
    ]));

    return $fields;
}

it('returns the local checkout route without making a network call', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('PAYU', []);
    $gateway = makePayU();

    $url = $gateway->handlePaymentRequest(makePaymentRequestDTO($transaction, $client), $transaction);

    expect($url)->toBe(route('payuCheckout', ['transaction' => $transaction->id]));
});

it('throws when credentials are missing', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('PAYU', []);
    $gateway = makePayU(['key' => null, 'salt' => null]);

    $gateway->handlePaymentRequest(makePaymentRequestDTO($transaction, $client), $transaction);
})->throws(Exception::class, 'Missing PayU API credentials.');

it('renders the checkout form with a correctly signed hash', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('PAYU', [
        'key' => PAYU_TEST_KEY,
        'salt' => PAYU_TEST_SALT,
    ]);

    $gateway = makePayU();
    $view = $gateway->checkoutForm($transaction);
    $fields = $view->getData()['fields'];

    expect($fields['key'])->toBe(PAYU_TEST_KEY)
        ->and($fields['txnid'])->toBe('TXN'.$transaction->id)
        ->and($fields['udf1'])->toBe((string) $transaction->id);

    $expectedHash = hash('sha512', implode('|', [
        $fields['key'], $fields['txnid'], $fields['amount'], $fields['productinfo'],
        $fields['firstname'], $fields['email'], $fields['udf1'], $fields['udf2'],
        '', '', '', '', '', '', '', '',
        PAYU_TEST_SALT,
    ]));

    expect($fields['hash'])->toBe($expectedHash);
});

it('maps a hash-verified successful postback to a successful PaymentResponseDTO', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('PAYU', [
        'key' => PAYU_TEST_KEY,
        'salt' => PAYU_TEST_SALT,
    ]);

    $gateway = makePayU();
    $response = $gateway->handlePaymentResponse(payuSignedResponse('TXN'.$transaction->id, 'success', '10.00', [
        'udf1' => (string) $transaction->id,
    ]));

    expect($response->status)->toBe(TransactionStatus::SUCCESS)
        ->and($response->transactionId)->toBe('MIHPAY123')
        ->and($response->paymentMethod)->toBe(PaymentMethod::CREDIT_CARD);
});

it('fails a postback whose hash does not match', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('PAYU', [
        'key' => PAYU_TEST_KEY,
        'salt' => PAYU_TEST_SALT,
    ]);

    $gateway = makePayU();
    $response = payuSignedResponse('TXN'.$transaction->id, 'success', '10.00', ['udf1' => (string) $transaction->id]);
    $response['hash'] = 'tampered-hash';

    $result = $gateway->handlePaymentResponse($response);

    expect($result->status)->toBe(TransactionStatus::FAILED)
        ->and($result->description)->toBe('Response hash verification failed.');
});

it('maps a pending postback to a pending PaymentResponseDTO', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('PAYU', [
        'key' => PAYU_TEST_KEY,
        'salt' => PAYU_TEST_SALT,
    ]);

    $gateway = makePayU();
    $response = $gateway->handlePaymentResponse(payuSignedResponse('TXN'.$transaction->id, 'pending', '10.00', [
        'udf1' => (string) $transaction->id,
    ]));

    expect($response->status)->toBe(TransactionStatus::PENDING);
});

it('maps a failure postback to a failed PaymentResponseDTO', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('PAYU', [
        'key' => PAYU_TEST_KEY,
        'salt' => PAYU_TEST_SALT,
    ]);

    $gateway = makePayU();
    $response = $gateway->handlePaymentResponse(payuSignedResponse('TXN'.$transaction->id, 'failure', '10.00', [
        'udf1' => (string) $transaction->id,
    ]));

    expect($response->status)->toBe(TransactionStatus::FAILED);
});

it('fetches the transaction status via verify_payment', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('PAYU', [
        'key' => PAYU_TEST_KEY,
        'salt' => PAYU_TEST_SALT,
    ]);

    $txnid = 'TXN'.$transaction->id;

    Http::fake([
        'test.payu.in/merchant/postservice.php*' => Http::response([
            'status' => 1,
            'msg' => 'ok',
            'transaction_details' => [
                $txnid => [
                    'mihpayid' => 'MIHPAY999',
                    'status' => 'success',
                    'amt' => '10.00',
                    'mode' => 'UPI',
                ],
            ],
        ]),
    ]);

    $gateway = makePayU();

    $status = $gateway->getTransactionStatus($transaction);
    $verify = $gateway->verifyPayment($transaction);

    expect($status->status)->toBe(TransactionStatus::SUCCESS)
        ->and($status->transactionId)->toBe('MIHPAY999')
        ->and($status->paymentMethod)->toBe(PaymentMethod::UPI)
        ->and($verify->status)->toBe(TransactionStatus::SUCCESS);
});

it('throws a clean error when verify_payment rejects the request', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('PAYU', [
        'key' => PAYU_TEST_KEY,
        'salt' => PAYU_TEST_SALT,
    ]);

    Http::fake([
        'test.payu.in/merchant/postservice.php*' => Http::response(['status' => 0, 'msg' => 'Invalid hash'], 200),
    ]);

    $gateway = makePayU();

    $gateway->getTransactionStatus($transaction);
})->throws(Exception::class);

it('reports refund support from its connection attributes', function () {
    expect(makePayU(['supports_refunds' => true])->isRefundSupported())->toBeTrue()
        ->and(makePayU(['supports_refunds' => false])->isRefundSupported())->toBeFalse();
});

it('processes a refund when refunds are supported', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('PAYU', [
        'key' => PAYU_TEST_KEY,
        'salt' => PAYU_TEST_SALT,
        'supports_refunds' => true,
    ], ['transaction_id' => 'MIHPAY123']);

    Http::fake([
        'test.payu.in/merchant/postservice.php*' => Http::response([
            'status' => 1,
            'msg' => 'Refund Request Queued',
            'request_id' => 'REQ1',
        ]),
    ]);

    $gateway = makePayU(['supports_refunds' => true]);

    $response = $gateway->refundPayment($transaction, makePaymentRefundDTO($transaction, $client));

    expect($response->status)->toBe(TransactionStatus::REFUNDED)
        ->and($response->transactionId)->toBe('MIHPAY123');
});

it('refuses to refund when refunds are not supported', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('PAYU', [
        'supports_refunds' => false,
    ]);
    $gateway = makePayU(['supports_refunds' => false]);

    $gateway->refundPayment($transaction, makePaymentRefundDTO($transaction, $client));
})->throws(Exception::class, 'Refunds are not supported by this PayU connection.');

it('refuses to refund when no PayU transaction id has been recorded', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('PAYU', [
        'supports_refunds' => true,
    ]);
    $gateway = makePayU(['supports_refunds' => true]);

    $gateway->refundPayment($transaction, makePaymentRefundDTO($transaction, $client));
})->throws(Exception::class, 'No PayU mihpayid recorded for this transaction.');
