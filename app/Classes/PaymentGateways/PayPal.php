<?php

namespace App\Classes\PaymentGateways;

use App\Contracts\PaymentGatewayInterface;
use App\DTO\PaymentRefundDTO;
use App\DTO\PaymentRequestDTO;
use App\DTO\PaymentResponseDTO;
use App\Enums\ConnectionType;
use App\Enums\PaymentGatewayRequestType;
use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Models\PaymentGatewayConnectionApiLog;
use App\Models\Transaction;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Devhammed\LaravelBrickMoney\Money;
use Illuminate\Contracts\View\View;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\Exceptions\ApiException;
use PaypalServerSdkLib\Models\CapturedPayment;
use PaypalServerSdkLib\Models\Order;
use PaypalServerSdkLib\Models\Refund;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;

class PayPal implements PaymentGatewayInterface
{
    protected ?string $clientId;

    protected ?string $secret;

    protected string $mode;

    protected string $paymentAction;

    protected string $locale;

    protected bool $isRefundSupported;

    protected bool $feesIncludedInAmount;

    protected float $feesRate;

    protected ConnectionType $connectionType;

    /**
     * @param  array<string, mixed>  $pg_data
     */
    public function __construct(array $pg_data = [], ConnectionType|string $connectionType = ConnectionType::TEST)
    {
        $this->clientId = $pg_data['client_id'] ?? null;
        $this->secret = $pg_data['secret'] ?? null;
        $this->mode = (string) ($pg_data['mode'] ?? 'sandbox');
        $this->paymentAction = (string) ($pg_data['paymentAction'] ?? 'Sale');
        $this->locale = (string) ($pg_data['locale'] ?? 'en_US');
        $this->isRefundSupported = (bool) ($pg_data['supports_refunds'] ?? false);
        $this->feesIncludedInAmount = (bool) ($pg_data['fees_included_in_amount'] ?? false);
        $this->feesRate = (float) ($pg_data['fees_rate'] ?? 0);

        $this->connectionType = $connectionType instanceof ConnectionType
            ? $connectionType
            : ConnectionType::from($connectionType);
    }

    protected function client(?string $clientId = null, ?string $secret = null): PaypalServerSdkClient
    {
        $clientId ??= $this->clientId;
        $secret ??= $this->secret;

        if (! $clientId || ! $secret) {
            throw new \Exception('Missing PayPal API credentials.');
        }

        return PaypalServerSdkClientBuilder::init()
            ->clientCredentialsAuthCredentials(
                ClientCredentialsAuthCredentialsBuilder::init($clientId, $secret)
            )
            ->environment($this->connectionType === ConnectionType::PRODUCTION ? Environment::PRODUCTION : Environment::SANDBOX)
            ->build();
    }

    protected function calculateFees(Money $amount): Money
    {
        return $amount->multipliedBy($this->feesRate / 100, RoundingMode::HALF_UP);
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    protected function logApiCall(Transaction $transaction, PaymentGatewayRequestType $requestType, array $requestData, array $responseData, string $responseStatus = '200'): void
    {
        PaymentGatewayConnectionApiLog::create([
            'client_id' => $transaction->client_id,
            'pg_connection_id' => $transaction->pg_connection_id,
            'transaction_id' => $transaction->id,
            'request_type' => $requestType,
            'request_data' => $requestData,
            'response_data' => $responseData,
            'response_status' => $responseStatus,
        ]);
    }

    /**
     * @param  array<string, mixed>  $requestData
     */
    protected function logApiFailure(Transaction $transaction, PaymentGatewayRequestType $requestType, array $requestData, \Throwable $e): void
    {
        $responseBody = $e instanceof ApiException && $e->getHttpResponse()
            ? $e->getHttpResponse()->getRawBody()
            : $e->getMessage();

        $statusCode = $e instanceof ApiException && $e->getHttpResponse()
            ? (string) $e->getHttpResponse()->getStatusCode()
            : '400';

        $this->logApiCall($transaction, $requestType, $requestData, ['error' => $responseBody], $statusCode);
    }

    /**
     * PayPal's SDK doesn't always throw when a request fails — a 422 business-
     * validation error (e.g. an unsupported currency) comes back as a plain
     * error array from getResult() instead of raising ErrorException, so every
     * call site must verify the result is actually the expected model before
     * trusting it.
     *
     * @template T of object
     * @param  class-string<T>  $expectedClass
     * @param  array<string, mixed>  $requestData
     * @return T
     */
    protected function assertResult(mixed $result, string $expectedClass, Transaction $transaction, PaymentGatewayRequestType $requestType, array $requestData): object
    {
        if ($result instanceof $expectedClass) {
            return $result;
        }

        $errorBody = is_array($result) ? $result : ['error' => $result];

        $this->logApiCall($transaction, $requestType, $requestData, $errorBody, '422');

        throw new \Exception('Payment Gateway Error: '.json_encode($errorBody));
    }

    public function handlePaymentRequest(PaymentRequestDTO $paymentRequest, Transaction $transaction): string
    {
        $orderData = [
            'intent' => strtolower($this->paymentAction) === 'authorization' ? 'AUTHORIZE' : 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => (string) $transaction->id,
                'custom_id' => (string) $transaction->id,
                'invoice_id' => $paymentRequest->site_reference_id,
                'amount' => [
                    'currency_code' => (string) $paymentRequest->currency,
                    'value' => (string) $paymentRequest->amount->getAmount(),
                ],
            ]],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'locale' => str_replace('_', '-', $this->locale),
                        'user_action' => 'PAY_NOW',
                        'shipping_preference' => 'NO_SHIPPING',
                        'return_url' => route('handlePaymentResponse', [
                            'pgClass' => 'PAYPAL',
                            'transactionDbId' => $transaction->id,
                        ]),
                        'cancel_url' => route('handlePaymentResponse', [
                            'pgClass' => 'PAYPAL',
                            'transactionDbId' => $transaction->id,
                            'status' => 'cancelled',
                        ]),
                    ],
                ],
            ],
        ];

        try {
            $apiResponse = $this->client()->getOrdersController()->createOrder(['body' => $orderData]);
        } catch (\Throwable $e) {
            $this->logApiFailure($transaction, PaymentGatewayRequestType::PAYMENT_INITIATE, $orderData, $e);

            throw new \Exception('Payment Gateway Error: '.$e->getMessage());
        }

        $order = $this->assertResult($apiResponse->getResult(), Order::class, $transaction, PaymentGatewayRequestType::PAYMENT_INITIATE, $orderData);

        $this->logApiCall($transaction, PaymentGatewayRequestType::PAYMENT_INITIATE, $orderData, [
            'id' => $order->getId(),
            'status' => $order->getStatus(),
        ]);

        return route('paypalEmbeddedCheckout', ['transaction' => $transaction->id, 'order' => $order->getId()]);
    }

    /**
     * Renders the page that boots the PayPal JS SDK and renders its Smart
     * Payment Buttons for the already-created order. Bound directly as the
     * handler for the `paypalEmbeddedCheckout` route (no dedicated controller
     * needed).
     */
    public function checkoutForm(Transaction $transaction, string $order): View
    {
        $transaction->loadMissing(['pgConnection']);

        return view('paypal.checkout', [
            'clientId' => (string) ($transaction->pgConnection->attributes['client_id'] ?? ''),
            'currency' => (string) $transaction->currency,
            'orderId' => $order,
            'returnUrl' => route('handlePaymentResponse', [
                'pgClass' => 'PAYPAL',
                'transactionDbId' => $transaction->id,
            ]),
        ]);
    }

    protected function mapOrderStatus(string $status): TransactionStatus
    {
        return match ($status) {
            'COMPLETED' => TransactionStatus::SUCCESS,
            'APPROVED', 'PAYER_ACTION_REQUIRED' => TransactionStatus::PROCESSING,
            'CREATED', 'SAVED' => TransactionStatus::PENDING,
            'VOIDED' => TransactionStatus::FAILED,
            default => TransactionStatus::FAILED,
        };
    }

    protected function mapCaptureStatus(string $status): TransactionStatus
    {
        return match ($status) {
            'COMPLETED' => TransactionStatus::SUCCESS,
            'PENDING' => TransactionStatus::PENDING,
            'DECLINED', 'FAILED' => TransactionStatus::FAILED,
            'REFUNDED' => TransactionStatus::REFUNDED,
            'PARTIALLY_REFUNDED' => TransactionStatus::REFUNDED,
            default => TransactionStatus::FAILED,
        };
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function handlePaymentResponse(array $response): PaymentResponseDTO
    {
        $transaction = Transaction::with(['client', 'pgConnection'])->find((string) ($response['transactionDbId'] ?? ''));

        if (! $transaction) {
            throw new \Exception('Transaction not found.');
        }

        $amount = $transaction->amount['amount'];
        $pgFees = $this->calculateFees($amount);

        if (($response['status'] ?? null) === 'cancelled') {
            return new PaymentResponseDTO(
                transactionDbId: (string) $transaction->id,
                siteReferenceId: $transaction->site_reference_id,
                status: TransactionStatus::FAILED,
                transactionId: '',
                description: 'Payment was cancelled by the customer.',
                amount: $amount,
                pgFees: $pgFees,
                totalAmount: $amount->plus($pgFees),
                transactionDateTime: CarbonImmutable::now(),
                currency: $transaction->currency,
                paymentMethod: PaymentMethod::UNKNOWN,
                clientName: $transaction->client->name,
                pgConnection: $transaction->pgConnection->name,
                pgResponseRaw: $response,
            );
        }

        $orderId = (string) ($response['orderId'] ?? '');

        if ($orderId === '') {
            throw new \Exception('Missing PayPal order id in callback.');
        }

        $clientId = (string) ($transaction->pgConnection->attributes['client_id'] ?? '');
        $clientSecret = (string) ($transaction->pgConnection->attributes['secret'] ?? '');

        try {
            $apiResponse = $this->client($clientId, $clientSecret)->getOrdersController()->captureOrder([
                'id' => $orderId,
                'body' => [],
            ]);
        } catch (\Throwable $e) {
            $this->logApiFailure($transaction, PaymentGatewayRequestType::PAYMENT_INITIATE, ['id' => $orderId], $e);

            throw new \Exception('Payment Gateway Error: '.$e->getMessage());
        }

        $order = $this->assertResult($apiResponse->getResult(), Order::class, $transaction, PaymentGatewayRequestType::PAYMENT_INITIATE, ['id' => $orderId]);
        $status = $this->mapOrderStatus((string) $order->getStatus());
        $transactionId = $order->getId();
        $paymentMethod = PaymentMethod::UNKNOWN;

        $purchaseUnits = $order->getPurchaseUnits() ?? [];
        $captures = ($purchaseUnits[0] ?? null)?->getPayments()?->getCaptures() ?? [];
        $capture = $captures[0] ?? null;

        if ($capture) {
            $transactionId = $capture->getId();
            $paymentMethod = PaymentMethod::WALLET;
            $amount = Money::of($capture->getAmount()->getValue(), $capture->getAmount()->getCurrencyCode());
            $pgFees = $this->calculateFees($amount);
        }

        $this->logApiCall($transaction, PaymentGatewayRequestType::PAYMENT_INITIATE, ['id' => $orderId], [
            'id' => $order->getId(),
            'status' => $order->getStatus(),
            'capture_id' => $capture?->getId(),
        ]);

        return new PaymentResponseDTO(
            transactionDbId: (string) $transaction->id,
            siteReferenceId: $transaction->site_reference_id,
            status: $status,
            transactionId: (string) $transactionId,
            description: 'PayPal order status: '.$order->getStatus(),
            amount: $amount,
            pgFees: $pgFees,
            totalAmount: $amount->plus($pgFees),
            transactionDateTime: CarbonImmutable::now(),
            currency: $transaction->currency,
            paymentMethod: $paymentMethod,
            clientName: $transaction->client->name,
            pgConnection: $transaction->pgConnection->name,
            pgResponseRaw: $response,
        );
    }

    public function getTransactionStatus(Transaction $transaction): PaymentResponseDTO
    {
        $transaction->loadMissing(['client', 'pgConnection']);

        if (! $transaction->transaction_id) {
            throw new \Exception('No PayPal capture id recorded for this transaction.');
        }

        try {
            $apiResponse = $this->client()->getPaymentsController()->getCapturedPayment(['captureId' => $transaction->transaction_id]);
        } catch (\Throwable $e) {
            $this->logApiFailure($transaction, PaymentGatewayRequestType::STATUS_CHECK, ['captureId' => $transaction->transaction_id], $e);

            throw new \Exception('Payment Gateway Error: '.$e->getMessage());
        }

        $capture = $this->assertResult($apiResponse->getResult(), CapturedPayment::class, $transaction, PaymentGatewayRequestType::STATUS_CHECK, ['captureId' => $transaction->transaction_id]);

        $this->logApiCall($transaction, PaymentGatewayRequestType::STATUS_CHECK, ['captureId' => $transaction->transaction_id], [
            'id' => $capture->getId(),
            'status' => $capture->getStatus(),
        ]);

        $status = $this->mapCaptureStatus((string) $capture->getStatus());
        $amount = Money::of($capture->getAmount()->getValue(), $capture->getAmount()->getCurrencyCode());
        $pgFees = $this->calculateFees($amount);

        return new PaymentResponseDTO(
            transactionDbId: (string) $transaction->id,
            siteReferenceId: $transaction->site_reference_id,
            status: $status,
            transactionId: (string) $capture->getId(),
            description: 'PayPal capture status: '.$capture->getStatus(),
            amount: $amount,
            pgFees: $pgFees,
            totalAmount: $amount->plus($pgFees),
            transactionDateTime: $capture->getCreateTime() ? CarbonImmutable::parse($capture->getCreateTime()) : CarbonImmutable::now(),
            currency: $transaction->currency,
            paymentMethod: PaymentMethod::WALLET,
            clientName: $transaction->client->name,
            pgConnection: $transaction->pgConnection->name,
            pgResponseRaw: (array) json_decode((string) json_encode($capture), true),
        );
    }

    public function verifyPayment(Transaction $transaction): PaymentResponseDTO
    {
        return $this->getTransactionStatus($transaction);
    }

    public function isRefundSupported(): bool
    {
        return $this->isRefundSupported;
    }

    public function refundPayment(Transaction $transaction, PaymentRefundDTO $paymentRefundRequest): PaymentResponseDTO
    {
        if (! $this->isRefundSupported) {
            throw new \Exception('Refunds are not supported by this PayPal connection.');
        }

        $transaction->loadMissing(['client', 'pgConnection']);

        if (! $transaction->transaction_id) {
            throw new \Exception('No PayPal capture id recorded for this transaction.');
        }

        $requestData = [
            'captureId' => $transaction->transaction_id,
            'body' => [
                'amount' => [
                    'currency_code' => (string) $paymentRefundRequest->currency,
                    'value' => (string) $paymentRefundRequest->amount->getAmount(),
                ],
                'note_to_payer' => $paymentRefundRequest->refundReason,
            ],
        ];

        try {
            $apiResponse = $this->client()->getPaymentsController()->refundCapturedPayment($requestData);
        } catch (\Throwable $e) {
            $this->logApiFailure($transaction, PaymentGatewayRequestType::REFUND, $requestData, $e);

            throw new \Exception('Payment Gateway Error: '.$e->getMessage());
        }

        $refund = $this->assertResult($apiResponse->getResult(), Refund::class, $transaction, PaymentGatewayRequestType::REFUND, $requestData);
        $refundArray = (array) json_decode((string) json_encode($refund), true);

        $this->logApiCall($transaction, PaymentGatewayRequestType::REFUND, $requestData, $refundArray);

        return new PaymentResponseDTO(
            transactionDbId: (string) $transaction->id,
            siteReferenceId: $transaction->site_reference_id,
            status: TransactionStatus::REFUNDED,
            transactionId: (string) $transaction->transaction_id,
            description: 'PayPal refund '.($refundArray['status'] ?? 'requested'),
            amount: $paymentRefundRequest->amount,
            pgFees: $transaction->pg_fees['pg_fees'],
            totalAmount: $paymentRefundRequest->amount,
            transactionDateTime: CarbonImmutable::now(),
            currency: $paymentRefundRequest->currency,
            paymentMethod: $transaction->payment_method ?? PaymentMethod::UNKNOWN,
            clientName: $transaction->client->name,
            pgConnection: $transaction->pgConnection->name,
            pgResponseRaw: $refundArray,
        );
    }
}
