<?php

use App\Classes\PaymentGateways\PayPal;
use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use Devhammed\LaravelBrickMoney\Money;
use PaypalServerSdkLib\Controllers\OrdersController;
use PaypalServerSdkLib\Controllers\PaymentsController;
use PaypalServerSdkLib\Http\ApiResponse;
use PaypalServerSdkLib\Models\LinkDescription;
use PaypalServerSdkLib\Models\Money as PayPalMoney;
use PaypalServerSdkLib\Models\Order;
use PaypalServerSdkLib\Models\OrdersCapture;
use PaypalServerSdkLib\Models\PaymentCollection;
use PaypalServerSdkLib\Models\PurchaseUnit;
use PaypalServerSdkLib\PaypalServerSdkClient;

require_once __DIR__.'/GatewayTestHelpers.php';

/**
 * A PayPal gateway whose client() returns an injected mock instead of a real
 * PaypalServerSdkClient, so tests can exercise handlePaymentRequest(),
 * handlePaymentResponse(), getTransactionStatus(), and refundPayment() -
 * i.e. code paths that actually call the SDK - without a live network round
 * trip. PayPal's SDK talks to the network through its own HTTP client (not
 * Laravel's Http facade), so it can't be intercepted with Http::fake() the
 * way ICICI/Cashfree can.
 */
class TestablePayPal extends PayPal
{
    public ?PaypalServerSdkClient $mockClient = null;

    protected function client(?string $clientId = null, ?string $secret = null): PaypalServerSdkClient
    {
        return $this->mockClient ?? parent::client($clientId, $secret);
    }
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeTestablePayPal(array $attributes = []): TestablePayPal
{
    return new TestablePayPal(array_merge([
        'client_id' => 'test-client-id',
        'secret' => 'test-secret',
        'mode' => 'sandbox',
        'paymentAction' => 'Sale',
        'locale' => 'en_US',
        'supports_refunds' => true,
        'fees_included_in_amount' => false,
        'fees_rate' => 3.49,
    ], $attributes));
}

function makePayPalCapturedPayment(string $id, string $status, string $currency, string $value): PaypalServerSdkLib\Models\CapturedPayment
{
    $capture = new PaypalServerSdkLib\Models\CapturedPayment;
    $capture->setId($id);
    $capture->setStatus($status);
    $capture->setAmount(new PayPalMoney($currency, $value));

    return $capture;
}

function mockPayPalApiResponse(mixed $result): ApiResponse
{
    $response = Mockery::mock(ApiResponse::class);
    $response->shouldReceive('getResult')->andReturn($result);

    return $response;
}

/**
 * PayPal's SDK talks to the network through its own HTTP client (not
 * Laravel's Http facade), so it can't be intercepted with Http::fake() the
 * way ICICI/Cashfree can. These tests exercise every code path that doesn't
 * require an actual network round trip: credential validation, payload
 * building, response mapping (using real, hand-built SDK model instances),
 * fee calculation, and refund-support flags. The order/capture/refund API
 * calls themselves are covered by the manual sandbox verification
 * documented in the gateway's PR.
 */
/**
 * @param  array<string, mixed>  $attributes
 */
function makePayPal(array $attributes = []): PayPal
{
    return new PayPal(array_merge([
        'client_id' => 'test-client-id',
        'secret' => 'test-secret',
        'mode' => 'sandbox',
        'paymentAction' => 'Sale',
        'locale' => 'en_US',
        'supports_refunds' => true,
        'fees_included_in_amount' => false,
        'fees_rate' => 3.49,
    ], $attributes));
}

/**
 * @param  array<LinkDescription>  $links
 */
function makePayPalOrder(string $status, array $links = [], ?OrdersCapture $capture = null): Order
{
    $order = new Order;
    $order->setId('ORDER123');
    $order->setStatus($status);
    $order->setLinks($links);

    if ($capture) {
        $payments = new PaymentCollection;
        $payments->setCaptures([$capture]);

        $purchaseUnit = new PurchaseUnit;
        $purchaseUnit->setPayments($payments);

        $order->setPurchaseUnits([$purchaseUnit]);
    }

    return $order;
}

function makePayPalCapture(string $id, string $currency, string $value): OrdersCapture
{
    $capture = new OrdersCapture;
    $capture->setId($id);
    $capture->setAmount(new PayPalMoney($currency, $value));

    return $capture;
}

it('creates an order and extracts its approval url', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('PAYPAL', []);
    $gateway = makeTestablePayPal();

    $order = makePayPalOrder('PAYER_ACTION_REQUIRED', [
        new LinkDescription('https://www.sandbox.paypal.com/checkoutnow?token=ORDER123', 'payer-action'),
    ]);

    $ordersController = Mockery::mock(OrdersController::class);
    $ordersController->shouldReceive('createOrder')->once()->andReturn(mockPayPalApiResponse($order));

    $mockClient = Mockery::mock(PaypalServerSdkClient::class);
    $mockClient->shouldReceive('getOrdersController')->andReturn($ordersController);
    $gateway->mockClient = $mockClient;

    $url = $gateway->handlePaymentRequest(makePaymentRequestDTO($transaction, $client), $transaction);

    expect($url)->toBe('https://www.sandbox.paypal.com/checkoutnow?token=ORDER123');
});

it('wraps an order-creation API failure in a clean exception', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('PAYPAL', []);
    $gateway = makeTestablePayPal();

    $ordersController = Mockery::mock(OrdersController::class);
    $ordersController->shouldReceive('createOrder')->once()->andThrow(new Exception('Network unreachable'));

    $mockClient = Mockery::mock(PaypalServerSdkClient::class);
    $mockClient->shouldReceive('getOrdersController')->andReturn($ordersController);
    $gateway->mockClient = $mockClient;

    $gateway->handlePaymentRequest(makePaymentRequestDTO($transaction, $client), $transaction);
})->throws(Exception::class, 'Payment Gateway Error: Network unreachable');

it('treats a 422 business-validation error as a clean exception instead of trusting the result', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('PAYPAL', []);
    $gateway = makeTestablePayPal();

    $ordersController = Mockery::mock(OrdersController::class);
    $ordersController->shouldReceive('createOrder')->once()->andReturn(
        mockPayPalApiResponse(['name' => 'UNPROCESSABLE_ENTITY', 'message' => 'Unsupported currency'])
    );

    $mockClient = Mockery::mock(PaypalServerSdkClient::class);
    $mockClient->shouldReceive('getOrdersController')->andReturn($ordersController);
    $gateway->mockClient = $mockClient;

    $gateway->handlePaymentRequest(makePaymentRequestDTO($transaction, $client), $transaction);
})->throws(Exception::class, 'Payment Gateway Error:');

it('captures an approved order and maps it to a successful PaymentResponseDTO', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('PAYPAL', [
        'client_id' => 'test-client-id',
        'secret' => 'test-secret',
    ], ['currency' => 'USD', 'amount' => 10]);
    $gateway = makeTestablePayPal();

    $capture = makePayPalCapture('CAP123', 'USD', '10.00');
    $order = makePayPalOrder('COMPLETED', [], $capture);

    $paymentsController = Mockery::mock(PaymentsController::class);

    $ordersController = Mockery::mock(OrdersController::class);
    $ordersController->shouldReceive('captureOrder')->once()->andReturn(mockPayPalApiResponse($order));

    $mockClient = Mockery::mock(PaypalServerSdkClient::class);
    $mockClient->shouldReceive('getOrdersController')->andReturn($ordersController);
    $mockClient->shouldReceive('getPaymentsController')->andReturn($paymentsController);
    $gateway->mockClient = $mockClient;

    $response = $gateway->handlePaymentResponse([
        'transactionDbId' => (string) $transaction->id,
        'token' => 'ORDER123',
    ]);

    expect($response->status)->toBe(TransactionStatus::SUCCESS)
        ->and($response->transactionId)->toBe('CAP123');
});

it('wraps an order-capture API failure in a clean exception', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('PAYPAL', [
        'client_id' => 'test-client-id',
        'secret' => 'test-secret',
    ]);
    $gateway = makeTestablePayPal();

    $ordersController = Mockery::mock(OrdersController::class);
    $ordersController->shouldReceive('captureOrder')->once()->andThrow(new Exception('Network unreachable'));

    $mockClient = Mockery::mock(PaypalServerSdkClient::class);
    $mockClient->shouldReceive('getOrdersController')->andReturn($ordersController);
    $gateway->mockClient = $mockClient;

    $gateway->handlePaymentResponse([
        'transactionDbId' => (string) $transaction->id,
        'token' => 'ORDER123',
    ]);
})->throws(Exception::class, 'Payment Gateway Error: Network unreachable');

it('fetches a captured payment and maps it to a successful PaymentResponseDTO', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('PAYPAL', [], ['currency' => 'USD', 'amount' => 10]);
    $transaction->forceFill(['transaction_id' => 'CAP123'])->save();
    $gateway = makeTestablePayPal();

    $capture = makePayPalCapturedPayment('CAP123', 'COMPLETED', 'USD', '10.00');

    $paymentsController = Mockery::mock(PaymentsController::class);
    $paymentsController->shouldReceive('getCapturedPayment')->once()->andReturn(mockPayPalApiResponse($capture));

    $mockClient = Mockery::mock(PaypalServerSdkClient::class);
    $mockClient->shouldReceive('getPaymentsController')->andReturn($paymentsController);
    $gateway->mockClient = $mockClient;

    $response = $gateway->getTransactionStatus($transaction);

    expect($response->status)->toBe(TransactionStatus::SUCCESS)
        ->and($response->transactionId)->toBe('CAP123');
});

it('wraps a captured-payment status-check failure in a clean exception', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('PAYPAL', []);
    $transaction->forceFill(['transaction_id' => 'CAP_MISSING'])->save();
    $gateway = makeTestablePayPal();

    $paymentsController = Mockery::mock(PaymentsController::class);
    $paymentsController->shouldReceive('getCapturedPayment')->once()->andThrow(new Exception('Network unreachable'));

    $mockClient = Mockery::mock(PaypalServerSdkClient::class);
    $mockClient->shouldReceive('getPaymentsController')->andReturn($paymentsController);
    $gateway->mockClient = $mockClient;

    $gateway->getTransactionStatus($transaction);
})->throws(Exception::class, 'Payment Gateway Error: Network unreachable');

it('processes a successful refund via the PayPal API', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('PAYPAL', [
        'supports_refunds' => true,
    ]);
    $transaction->forceFill(['transaction_id' => 'CAP123'])->save();
    $gateway = makeTestablePayPal(['supports_refunds' => true]);

    $refund = new PaypalServerSdkLib\Models\Refund;
    $refund->setId('REF123');
    $refund->setStatus('COMPLETED');

    $paymentsController = Mockery::mock(PaymentsController::class);
    $paymentsController->shouldReceive('refundCapturedPayment')->once()->andReturn(mockPayPalApiResponse($refund));

    $mockClient = Mockery::mock(PaypalServerSdkClient::class);
    $mockClient->shouldReceive('getPaymentsController')->andReturn($paymentsController);
    $gateway->mockClient = $mockClient;

    $response = $gateway->refundPayment($transaction, makePaymentRefundDTO($transaction, $client));

    expect($response->status)->toBe(TransactionStatus::REFUNDED)
        ->and($response->description)->toBe('PayPal refund COMPLETED');
});

it('wraps a refund API failure in a clean exception', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('PAYPAL', [
        'supports_refunds' => true,
    ]);
    $transaction->forceFill(['transaction_id' => 'CAP123'])->save();
    $gateway = makeTestablePayPal(['supports_refunds' => true]);

    $paymentsController = Mockery::mock(PaymentsController::class);
    $paymentsController->shouldReceive('refundCapturedPayment')->once()->andThrow(new Exception('Network unreachable'));

    $mockClient = Mockery::mock(PaypalServerSdkClient::class);
    $mockClient->shouldReceive('getPaymentsController')->andReturn($paymentsController);
    $gateway->mockClient = $mockClient;

    $gateway->refundPayment($transaction, makePaymentRefundDTO($transaction, $client));
})->throws(Exception::class, 'Payment Gateway Error: Network unreachable');

it('delegates verifyPayment() to getTransactionStatus()', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('PAYPAL', []);
    $transaction->forceFill(['transaction_id' => 'CAP123'])->save();
    $gateway = makeTestablePayPal();

    $capture = makePayPalCapturedPayment('CAP123', 'COMPLETED', 'USD', '10.00');

    $paymentsController = Mockery::mock(PaymentsController::class);
    $paymentsController->shouldReceive('getCapturedPayment')->once()->andReturn(mockPayPalApiResponse($capture));

    $mockClient = Mockery::mock(PaypalServerSdkClient::class);
    $mockClient->shouldReceive('getPaymentsController')->andReturn($paymentsController);
    $gateway->mockClient = $mockClient;

    $response = $gateway->verifyPayment($transaction);

    expect($response->status)->toBe(TransactionStatus::SUCCESS);
});

it('accepts a raw connection-type string just like a ConnectionType enum', function () {
    $gateway = new PayPal(['client_id' => 'x', 'secret' => 'y'], 'PRODUCTION');

    expect(callGatewayMethod($gateway, 'client'))->toBeInstanceOf(PaypalServerSdkClient::class);
});

it('refuses to create an order when API credentials are missing', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('PAYPAL', []);
    $gateway = makePayPal(['client_id' => null, 'secret' => null]);

    $gateway->handlePaymentRequest(makePaymentRequestDTO($transaction, $client), $transaction);
})->throws(Exception::class, 'Missing PayPal API credentials.');

it('builds an order payload with the correct intent, amount, and callback URLs', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('PAYPAL', []);
    $gateway = makePayPal();

    $payload = callGatewayMethod($gateway, 'buildOrderPayload', [
        makePaymentRequestDTO($transaction, $client, 'USD', 10),
        $transaction,
    ]);

    expect($payload['intent'])->toBe('CAPTURE')
        ->and($payload['purchase_units'][0]['amount'])->toBe(['currency_code' => 'USD', 'value' => '10.00'])
        ->and($payload['payment_source']['paypal']['experience_context']['return_url'])
        ->toBe(route('handlePaymentResponse', ['pgClass' => 'PAYPAL', 'transactionDbId' => $transaction->id]))
        ->and($payload['payment_source']['paypal']['experience_context']['cancel_url'])
        ->toBe(route('handlePaymentResponse', ['pgClass' => 'PAYPAL', 'transactionDbId' => $transaction->id, 'status' => 'cancelled']));
});

it('builds an AUTHORIZE intent when the connection is configured for authorization', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('PAYPAL', []);
    $gateway = makePayPal(['paymentAction' => 'Authorization']);

    $payload = callGatewayMethod($gateway, 'buildOrderPayload', [
        makePaymentRequestDTO($transaction, $client),
        $transaction,
    ]);

    expect($payload['intent'])->toBe('AUTHORIZE');
});

it('extracts the payer-action approval link from a created order', function () {
    $gateway = makePayPal();

    $order = makePayPalOrder('PAYER_ACTION_REQUIRED', [
        new LinkDescription('https://www.sandbox.paypal.com/checkoutnow?token=ORDER123', 'payer-action'),
    ]);

    expect(callGatewayMethod($gateway, 'extractApprovalUrl', [$order]))
        ->toBe('https://www.sandbox.paypal.com/checkoutnow?token=ORDER123');
});

it('falls back to the classic "approve" rel when payer-action is absent', function () {
    $gateway = makePayPal();

    $order = makePayPalOrder('CREATED', [
        new LinkDescription('https://www.sandbox.paypal.com/checkoutnow?token=ORDER123', 'approve'),
    ]);

    expect(callGatewayMethod($gateway, 'extractApprovalUrl', [$order]))
        ->toBe('https://www.sandbox.paypal.com/checkoutnow?token=ORDER123');
});

it('throws when the created order has no approval link at all', function () {
    $gateway = makePayPal();

    $order = makePayPalOrder('CREATED', [
        new LinkDescription('https://api.sandbox.paypal.com/v2/checkout/orders/ORDER123', 'self'),
    ]);

    callGatewayMethod($gateway, 'extractApprovalUrl', [$order]);
})->throws(Exception::class, 'missing PayPal approval link');

it('treats an explicitly cancelled callback as failed', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('PAYPAL', []);
    $gateway = makePayPal();

    $response = $gateway->handlePaymentResponse([
        'transactionDbId' => (string) $transaction->id,
        'status' => 'cancelled',
    ]);

    expect($response->status)->toBe(TransactionStatus::FAILED)
        ->and($response->description)->toBe('Payment was cancelled by the customer.');
});

it('throws when the transaction referenced in the response does not exist', function () {
    $gateway = makePayPal();

    $gateway->handlePaymentResponse(['transactionDbId' => '999999999']);
})->throws(Exception::class, 'Transaction not found.');

it('throws when the callback is missing both token and orderId', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('PAYPAL', []);
    $gateway = makePayPal();

    $gateway->handlePaymentResponse(['transactionDbId' => (string) $transaction->id]);
})->throws(Exception::class, 'Missing PayPal order id in callback.');

it('extracts a capture from an order\'s purchase units when one exists', function () {
    $gateway = makePayPal();
    $capture = makePayPalCapture('CAP123', 'USD', '10.00');

    $order = makePayPalOrder('COMPLETED', [], $capture);

    expect(callGatewayMethod($gateway, 'extractCapture', [$order]))->toBe($capture);
});

it('returns null when an order has no purchase units or captures yet', function () {
    $gateway = makePayPal();

    $order = makePayPalOrder('CREATED');

    expect(callGatewayMethod($gateway, 'extractCapture', [$order]))->toBeNull();
});

it('builds a successful PaymentResponseDTO from a completed order with a capture', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('PAYPAL', [], ['currency' => 'USD', 'amount' => 10]);
    $gateway = makePayPal();

    $capture = makePayPalCapture('CAP123', 'USD', '10.00');
    $order = makePayPalOrder('COMPLETED', [], $capture);

    $response = callGatewayMethod($gateway, 'buildCaptureResponse', [$transaction, ['token' => 'ORDER123'], $order]);

    expect($response->status)->toBe(TransactionStatus::SUCCESS)
        ->and($response->transactionId)->toBe('CAP123')
        ->and($response->paymentMethod)->toBe(PaymentMethod::WALLET);
});

it('builds a processing PaymentResponseDTO for an order still awaiting payer action', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('PAYPAL', [], ['currency' => 'USD', 'amount' => 10]);
    $gateway = makePayPal();

    $order = makePayPalOrder('PAYER_ACTION_REQUIRED');

    $response = callGatewayMethod($gateway, 'buildCaptureResponse', [$transaction, ['token' => 'ORDER123'], $order]);

    expect($response->status)->toBe(TransactionStatus::PROCESSING);
});

it('maps PayPal order statuses to the app-level TransactionStatus enum', function () {
    $gateway = makePayPal();

    expect(callGatewayMethod($gateway, 'mapOrderStatus', ['COMPLETED']))->toBe(TransactionStatus::SUCCESS)
        ->and(callGatewayMethod($gateway, 'mapOrderStatus', ['APPROVED']))->toBe(TransactionStatus::PROCESSING)
        ->and(callGatewayMethod($gateway, 'mapOrderStatus', ['CREATED']))->toBe(TransactionStatus::PENDING)
        ->and(callGatewayMethod($gateway, 'mapOrderStatus', ['VOIDED']))->toBe(TransactionStatus::FAILED);
});

it('maps PayPal capture statuses to the app-level TransactionStatus enum', function () {
    $gateway = makePayPal();

    expect(callGatewayMethod($gateway, 'mapCaptureStatus', ['COMPLETED']))->toBe(TransactionStatus::SUCCESS)
        ->and(callGatewayMethod($gateway, 'mapCaptureStatus', ['PENDING']))->toBe(TransactionStatus::PENDING)
        ->and(callGatewayMethod($gateway, 'mapCaptureStatus', ['DECLINED']))->toBe(TransactionStatus::FAILED)
        ->and(callGatewayMethod($gateway, 'mapCaptureStatus', ['REFUNDED']))->toBe(TransactionStatus::REFUNDED);
});

it('calculates gateway fees from the connection fee rate', function () {
    $gateway = makePayPal(['fees_rate' => 3.49]);

    $fees = callGatewayMethod($gateway, 'calculateFees', [Money::of(1000, 'USD')]);

    expect((string) $fees->getAmount())->toBe('34.90');
});

it('reports refund support from its connection attributes', function () {
    expect(makePayPal(['supports_refunds' => true])->isRefundSupported())->toBeTrue()
        ->and(makePayPal(['supports_refunds' => false])->isRefundSupported())->toBeFalse();
});

it('refuses to refund when refunds are not supported', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('PAYPAL', [
        'supports_refunds' => false,
    ]);
    $gateway = makePayPal(['supports_refunds' => false]);

    $gateway->refundPayment($transaction, makePaymentRefundDTO($transaction, $client));
})->throws(Exception::class, 'Refunds are not supported by this PayPal connection.');

it('refuses to refund a transaction with no recorded PayPal capture id', function () {
    ['transaction' => $transaction, 'client' => $client] = createGatewayTestTransaction('PAYPAL', [
        'supports_refunds' => true,
    ]);
    $gateway = makePayPal(['supports_refunds' => true]);

    $gateway->refundPayment($transaction, makePaymentRefundDTO($transaction, $client));
})->throws(Exception::class, 'No PayPal capture id recorded for this transaction.');

it('refuses to check status for a transaction with no recorded PayPal capture id', function () {
    ['transaction' => $transaction] = createGatewayTestTransaction('PAYPAL', []);
    $gateway = makePayPal();

    $gateway->getTransactionStatus($transaction);
})->throws(Exception::class, 'No PayPal capture id recorded for this transaction.');
