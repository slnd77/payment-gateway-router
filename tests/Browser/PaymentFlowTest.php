<?php

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/Support/helpers.php';

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
