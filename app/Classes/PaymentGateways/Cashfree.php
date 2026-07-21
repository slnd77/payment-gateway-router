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
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Cashfree implements PaymentGatewayInterface
{
    /**
     * Our own order id prefix, so an incoming order_id (echoed back by
     * Cashfree on the return_url) can be mapped straight back to a
     * transactionDbId without needing any side-channel lookup.
     */
    const ORDER_ID_PREFIX = 'TXN';

    const API_VERSION = '2025-01-01';

    protected ?string $clientId;

    protected ?string $clientSecret;

    protected bool $isRefundSupported;

    protected bool $feesIncludedInAmount;

    protected float $feesRate;

    protected ConnectionType $connectionType;

    /**
     * @param  array<string, mixed>  $pg_data
     */
    public function __construct(array $pg_data = [], ConnectionType|string $connectionType = ConnectionType::TEST)
    {
        $this->clientId = $pg_data['key_id'] ?? null;
        $this->clientSecret = $pg_data['key_secret'] ?? null;
        $this->isRefundSupported = (bool) ($pg_data['supports_refunds'] ?? false);
        $this->feesIncludedInAmount = (bool) ($pg_data['fees_included_in_amount'] ?? false);
        $this->feesRate = (float) ($pg_data['fees_rate'] ?? 0);

        $this->connectionType = $connectionType instanceof ConnectionType
            ? $connectionType
            : ConnectionType::from($connectionType);
    }

    protected function baseUrl(): string
    {
        return match ($this->connectionType) {
            ConnectionType::TEST => 'https://sandbox.cashfree.com/pg',
            ConnectionType::PRODUCTION => 'https://api.cashfree.com/pg',
        };
    }

    /**
     * @return array<string, string>
     */
    protected function headers(?string $clientId = null, ?string $clientSecret = null): array
    {
        $clientId ??= $this->clientId;
        $clientSecret ??= $this->clientSecret;

        if (! $clientId || ! $clientSecret) {
            throw new \Exception('Missing Cashfree API credentials.');
        }

        return [
            'Content-Type' => 'application/json',
            'x-api-version' => self::API_VERSION,
            'x-client-id' => $clientId,
            'x-client-secret' => $clientSecret,
        ];
    }

    protected function calculateFees(Money $amount): Money
    {
        return $amount->multipliedBy($this->feesRate / 100, RoundingMode::HALF_UP);
    }

    /**
     * @param  array<string, mixed>  $requestData
     */
    protected function logApiCall(Transaction $transaction, PaymentGatewayRequestType $requestType, array $requestData, Response $response): void
    {
        PaymentGatewayConnectionApiLog::create([
            'client_id' => $transaction->client_id,
            'pg_connection_id' => $transaction->pg_connection_id,
            'transaction_id' => $transaction->id,
            'request_type' => $requestType,
            'request_data' => $requestData,
            'response_data' => $response->json() ?? ['body' => $response->body()],
            'response_status' => (string) $response->status(),
        ]);
    }

    protected function orderIdFor(Transaction $transaction): string
    {
        return self::ORDER_ID_PREFIX.$transaction->id;
    }

    protected function transactionDbIdFromOrderId(string $orderId): string
    {
        return Str::startsWith($orderId, self::ORDER_ID_PREFIX)
            ? Str::after($orderId, self::ORDER_ID_PREFIX)
            : $orderId;
    }

    public function handlePaymentRequest(PaymentRequestDTO $paymentRequest, Transaction $transaction): string
    {
        $orderData = [
            'order_id' => $this->orderIdFor($transaction),
            'order_amount' => (string) $paymentRequest->amount->getAmount(),
            'order_currency' => (string) $paymentRequest->currency,
            'order_note' => 'Payment for '.$paymentRequest->site_reference_id,
            'customer_details' => [
                'customer_id' => (string) $transaction->client_customer_id,
                'customer_name' => (string) ($paymentRequest->customer['name'] ?? ''),
                'customer_email' => (string) ($paymentRequest->customer['email'] ?? ''),
                'customer_phone' => (string) ($paymentRequest->customer['mobile'] ?? ''),
            ],
            'order_meta' => [
                'return_url' => route('handlePaymentResponse', ['pgClass' => 'CASHFREE']).'?order_id={order_id}',
            ],
        ];

        $response = Http::withHeaders($this->headers())
            ->post($this->baseUrl().'/orders', $orderData);

        $this->logApiCall($transaction, PaymentGatewayRequestType::PAYMENT_INITIATE, $orderData, $response);

        if ($response->failed()) {
            throw new \Exception('Payment Gateway Error: '.json_encode($response->json() ?? $response->body()));
        }

        $result = $response->json();

        if (! is_array($result) || empty($result['payment_session_id'])) {
            throw new \Exception('Payment Gateway Error: missing payment_session_id in response.');
        }

        return route('cashfreeEmbeddedCheckout', ['transaction' => $transaction->id, 'session' => $result['payment_session_id']]);
    }

    /**
     * Renders the page that boots the Cashfree JS SDK and opens its embedded
     * checkout for the given payment session. Bound directly as the handler
     * for the `cashfreeEmbeddedCheckout` route (no dedicated controller needed).
     */
    public function checkoutForm(Transaction $transaction, string $session): View
    {
        $transaction->loadMissing(['pgConnection']);

        $mode = ($transaction->pgConnection->type ?? ConnectionType::TEST) === ConnectionType::PRODUCTION
            ? 'production'
            : 'sandbox';

        return view('cashfree.checkout', [
            'mode' => $mode,
            'paymentSessionId' => $session,
        ]);
    }

    protected function mapPaymentGroup(string $paymentGroup): PaymentMethod
    {
        return match ($paymentGroup) {
            'credit_card', 'debit_card', 'credit_card_emi', 'debit_card_emi', 'prepaid_card' => PaymentMethod::CARD,
            'net_banking' => PaymentMethod::NETBANKING,
            'wallet' => PaymentMethod::WALLET,
            'upi', 'upi_ppi', 'upi_ppi_offline', 'upi_credit_card' => PaymentMethod::UPI,
            default => PaymentMethod::UNKNOWN,
        };
    }

    protected function mapOrderStatus(string $orderStatus): TransactionStatus
    {
        return match ($orderStatus) {
            'PAID' => TransactionStatus::SUCCESS,
            'ACTIVE' => TransactionStatus::PENDING,
            'TERMINATION_REQUESTED' => TransactionStatus::PROCESSING,
            'EXPIRED', 'TERMINATED' => TransactionStatus::FAILED,
            default => TransactionStatus::FAILED,
        };
    }

    /**
     * Fetches the order and its latest payment attempt from Cashfree and maps
     * them to a PaymentResponseDTO. Shared by handlePaymentResponse (which only
     * has the empty gateway instance, so credentials come from the transaction's
     * own connection) and getTransactionStatus (which has its own credentials).
     */
    protected function fetchOrderStatus(Transaction $transaction, ?string $clientId = null, ?string $clientSecret = null): PaymentResponseDTO
    {
        $transaction->loadMissing(['client', 'pgConnection']);

        $orderId = $this->orderIdFor($transaction);
        $headers = $this->headers($clientId, $clientSecret);

        $orderResponse = Http::withHeaders($headers)->get($this->baseUrl().'/orders/'.$orderId);

        $this->logApiCall($transaction, PaymentGatewayRequestType::STATUS_CHECK, ['order_id' => $orderId], $orderResponse);

        if ($orderResponse->failed()) {
            throw new \Exception('Payment Gateway Error: '.json_encode($orderResponse->json() ?? $orderResponse->body()));
        }

        $order = $orderResponse->json();

        if (! is_array($order)) {
            throw new \Exception('Payment Gateway Error: unexpected order response body.');
        }

        $status = $this->mapOrderStatus((string) ($order['order_status'] ?? ''));
        $amount = $transaction->amount['amount'];
        $pgFees = $this->calculateFees($amount);
        $paymentMethod = PaymentMethod::UNKNOWN;
        $transactionId = '';
        $paymentDetails = $order;
        $transactionDateTime = CarbonImmutable::now();

        if ($status === TransactionStatus::SUCCESS) {
            $paymentsResponse = Http::withHeaders($headers)->get($this->baseUrl().'/orders/'.$orderId.'/payments');

            $this->logApiCall($transaction, PaymentGatewayRequestType::STATUS_CHECK, ['order_id' => $orderId, 'fetch' => 'payments'], $paymentsResponse);

            $payments = $paymentsResponse->successful() ? $paymentsResponse->json() : [];
            $successfulPayment = collect(is_array($payments) ? $payments : [])
                ->first(fn ($payment) => ($payment['payment_status'] ?? null) === 'SUCCESS');

            if ($successfulPayment) {
                $paymentDetails = $successfulPayment;
                $transactionId = (string) ($successfulPayment['cf_payment_id'] ?? '');
                $paymentMethod = $this->mapPaymentGroup((string) ($successfulPayment['payment_group'] ?? ''));
                $amount = Money::of($successfulPayment['payment_amount'], (string) $transaction->currency);

                if (isset($successfulPayment['payment_completion_time'])) {
                    $transactionDateTime = CarbonImmutable::parse($successfulPayment['payment_completion_time']);
                }
            }
        }

        return new PaymentResponseDTO(
            transactionDbId: (string) $transaction->id,
            siteReferenceId: $transaction->site_reference_id,
            status: $status,
            transactionId: $transactionId,
            description: 'Cashfree order status: '.($order['order_status'] ?? 'unknown'),
            amount: $amount,
            pgFees: $pgFees,
            totalAmount: $amount->plus($pgFees),
            transactionDateTime: $transactionDateTime,
            currency: $transaction->currency,
            paymentMethod: $paymentMethod,
            clientName: $transaction->client->name,
            pgConnection: $transaction->pgConnection->name,
            pgResponseRaw: $paymentDetails,
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function handlePaymentResponse(array $response): PaymentResponseDTO
    {
        $orderId = (string) ($response['order_id'] ?? '');
        $transactionDbId = $this->transactionDbIdFromOrderId($orderId);

        $transaction = Transaction::with(['client', 'pgConnection'])->find($transactionDbId);

        if (! $transaction) {
            throw new \Exception('Transaction not found.');
        }

        $clientId = (string) ($transaction->pgConnection->attributes['key_id'] ?? '');
        $clientSecret = (string) ($transaction->pgConnection->attributes['key_secret'] ?? '');

        return $this->fetchOrderStatus($transaction, $clientId, $clientSecret);
    }

    public function getTransactionStatus(Transaction $transaction): PaymentResponseDTO
    {
        return $this->fetchOrderStatus($transaction);
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
            throw new \Exception('Refunds are not supported by this Cashfree connection.');
        }

        $transaction->loadMissing(['client', 'pgConnection']);

        $orderId = $this->orderIdFor($transaction);

        $requestData = [
            'refund_id' => 'RFD'.$transaction->id.'-'.CarbonImmutable::now()->timestamp,
            'refund_amount' => (string) $paymentRefundRequest->amount->getAmount(),
            'refund_note' => $paymentRefundRequest->refundReason,
        ];

        $response = Http::withHeaders($this->headers())
            ->post($this->baseUrl().'/orders/'.$orderId.'/refunds', $requestData);

        $this->logApiCall($transaction, PaymentGatewayRequestType::REFUND, $requestData, $response);

        if ($response->failed()) {
            throw new \Exception('Payment Gateway Error: '.json_encode($response->json() ?? $response->body()));
        }

        $refund = $response->json();

        if (! is_array($refund)) {
            throw new \Exception('Payment Gateway Error: unexpected refund response body.');
        }

        return new PaymentResponseDTO(
            transactionDbId: (string) $transaction->id,
            siteReferenceId: $transaction->site_reference_id,
            status: TransactionStatus::REFUNDED,
            transactionId: (string) ($transaction->transaction_id ?? ''),
            description: 'Cashfree refund '.($refund['refund_status'] ?? 'requested'),
            amount: $paymentRefundRequest->amount,
            pgFees: $transaction->pg_fees['pg_fees'],
            totalAmount: $paymentRefundRequest->amount,
            transactionDateTime: CarbonImmutable::now(),
            currency: $paymentRefundRequest->currency,
            paymentMethod: $transaction->payment_method ?? PaymentMethod::UNKNOWN,
            clientName: $transaction->client->name,
            pgConnection: $transaction->pgConnection->name,
            pgResponseRaw: $refund,
        );
    }
}
