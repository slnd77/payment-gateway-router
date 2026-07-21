<?php

namespace App\Classes\PaymentGateways;

use App\Classes\PaymentGateways\Concerns\LogsApiCalls;
use App\Contracts\PaymentGatewayInterface;
use App\DTO\PaymentRefundDTO;
use App\DTO\PaymentRequestDTO;
use App\DTO\PaymentResponseDTO;
use App\Enums\ConnectionType;
use App\Enums\PaymentGatewayRequestType;
use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Devhammed\LaravelBrickMoney\Money;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\Models\CapturedPayment;
use PaypalServerSdkLib\Models\Order;
use PaypalServerSdkLib\Models\OrdersCapture;
use PaypalServerSdkLib\Models\Refund;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;

class PayPal implements PaymentGatewayInterface
{
    use LogsApiCalls;

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
     * PayPal's SDK doesn't always throw when a request fails — a 422 business-
     * validation error (e.g. an unsupported currency) comes back as a plain
     * error array from getResult() instead of raising ErrorException, so every
     * call site must verify the result is actually the expected model before
     * trusting it.
     *
     * @template T of object
     *
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
        $orderData = $this->buildOrderPayload($paymentRequest, $transaction);
        $order = $this->createOrder($transaction, $orderData);

        return $this->extractApprovalUrl($order);
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildOrderPayload(PaymentRequestDTO $paymentRequest, Transaction $transaction): array
    {
        return [
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
    }

    /**
     * @param  array<string, mixed>  $orderData
     */
    protected function createOrder(Transaction $transaction, array $orderData): Order
    {
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

        return $order;
    }

    protected function extractApprovalUrl(Order $order): string
    {
        $approveLink = collect($order->getLinks() ?? [])
            ->first(fn ($link) => in_array($link->getRel(), ['payer-action', 'approve'], true));

        if (! $approveLink) {
            throw new \Exception('Payment Gateway Error: missing PayPal approval link in response.');
        }

        return $approveLink->getHref();
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

        if (($response['status'] ?? null) === 'cancelled') {
            return $this->buildCancelledResponse($transaction, $response);
        }

        // PayPal appends its own order id back to return_url/cancel_url as `token`
        // when redirecting the buyer's browser after the hosted approval page.
        $orderId = (string) ($response['token'] ?? $response['orderId'] ?? '');

        if ($orderId === '') {
            throw new \Exception('Missing PayPal order id in callback.');
        }

        $order = $this->captureOrder($transaction, $orderId);

        return $this->buildCaptureResponse($transaction, $response, $order);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function buildCancelledResponse(Transaction $transaction, array $response): PaymentResponseDTO
    {
        $amount = $transaction->amount['amount'];
        $pgFees = $this->calculateFees($amount);

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

    protected function captureOrder(Transaction $transaction, string $orderId): Order
    {
        $clientId = (string) ($transaction->pgConnection->attributes['client_id'] ?? '');
        $clientSecret = (string) ($transaction->pgConnection->attributes['secret'] ?? '');

        try {
            $apiResponse = $this->client($clientId, $clientSecret)->getOrdersController()->captureOrder([
                'id' => $orderId,
                // PHP's [] json_encodes to a JSON array (`[]`), but PayPal's capture
                // endpoint requires a JSON object body even when empty, or it rejects
                // the request with MALFORMED_REQUEST_JSON.
                'body' => new \stdClass,
            ]);
        } catch (\Throwable $e) {
            $this->logApiFailure($transaction, PaymentGatewayRequestType::PAYMENT_INITIATE, ['id' => $orderId], $e);

            throw new \Exception('Payment Gateway Error: '.$e->getMessage());
        }

        $order = $this->assertResult($apiResponse->getResult(), Order::class, $transaction, PaymentGatewayRequestType::PAYMENT_INITIATE, ['id' => $orderId]);

        $this->logApiCall($transaction, PaymentGatewayRequestType::PAYMENT_INITIATE, ['id' => $orderId], [
            'id' => $order->getId(),
            'status' => $order->getStatus(),
            'capture_id' => $this->extractCapture($order)?->getId(),
        ]);

        return $order;
    }

    protected function extractCapture(Order $order): ?OrdersCapture
    {
        $purchaseUnits = $order->getPurchaseUnits() ?? [];
        $captures = ($purchaseUnits[0] ?? null)?->getPayments()?->getCaptures() ?? [];

        return $captures[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function buildCaptureResponse(Transaction $transaction, array $response, Order $order): PaymentResponseDTO
    {
        $status = $this->mapOrderStatus((string) $order->getStatus());
        $transactionId = $order->getId();
        $paymentMethod = PaymentMethod::UNKNOWN;
        $amount = $transaction->amount['amount'];
        $pgFees = $this->calculateFees($amount);

        $capture = $this->extractCapture($order);

        if ($capture) {
            $transactionId = $capture->getId();
            $paymentMethod = PaymentMethod::WALLET;
            $amount = Money::of($capture->getAmount()->getValue(), $capture->getAmount()->getCurrencyCode());
            $pgFees = $this->calculateFees($amount);
        }

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
