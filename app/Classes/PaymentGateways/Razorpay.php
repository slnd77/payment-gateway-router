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
use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;
use Razorpay\Api\Order;
use Razorpay\Api\Payment;
use Razorpay\Api\Utility;

class Razorpay implements PaymentGatewayInterface
{
    /**
     * Razorpay's own hosted checkout page. The customer's browser is redirected
     * here (via an auto-submitting form) so no checkout UI needs to be hosted by us.
     */
    const EMBEDDED_CHECKOUT_ENDPOINT = 'https://api.razorpay.com/v1/checkout/embedded';

    protected ?string $rzp_key;

    protected ?string $rzp_secret;

    protected bool $isRefundSupported;

    protected bool $feesIncludedInAmount;

    protected float $feesRate;

    protected ConnectionType $connectionType;

    /**
     * @param  array<string, mixed>  $pg_data
     */
    public function __construct(array $pg_data = [], ConnectionType|string $connectionType = ConnectionType::TEST)
    {
        $this->rzp_key = $pg_data['key_id'] ?? null;
        $this->rzp_secret = $pg_data['key_secret'] ?? null;
        $this->isRefundSupported = (bool) ($pg_data['supports_refunds'] ?? false);
        $this->feesIncludedInAmount = (bool) ($pg_data['fees_included_in_amount'] ?? false);
        $this->feesRate = (float) ($pg_data['fees_rate'] ?? 0);

        $this->connectionType = $connectionType instanceof ConnectionType
            ? $connectionType
            : ConnectionType::from($connectionType);

    }

    /**
     * Sets the credentials Razorpay\Api\Request authenticates with for every
     * subsequent Order/Payment/Refund call made in this process.
     */
    protected function authenticate(?string $keyId = null, ?string $keySecret = null): void
    {
        $keyId ??= $this->rzp_key;
        $keySecret ??= $this->rzp_secret;

        if (! $keyId || ! $keySecret) {
            throw new \Exception('Missing Razorpay API credentials.');
        }

        new Api($keyId, $keySecret);
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

    public function handlePaymentRequest(PaymentRequestDTO $paymentRequest, Transaction $transaction): string
    {
        $orderData = [
            'amount' => $paymentRequest->amount->getMinorAmount()->toInt(),
            'currency' => (string) $paymentRequest->currency,
            'receipt' => (string) $transaction->id,
            'notes' => [
                'transactionDbId' => (string) $transaction->id,
                'siteReferenceId' => $paymentRequest->site_reference_id,
            ],
        ];

        try {
            $this->authenticate();
            $order = (new Order)->create($orderData);
        } catch (\Throwable $e) {
            $this->logApiCall($transaction, PaymentGatewayRequestType::PAYMENT_INITIATE, $orderData, ['error' => $e->getMessage()], '400');

            throw new \Exception('Payment Gateway Error: '.$e->getMessage());
        }

        $orderArray = $order->toArray();

        $this->logApiCall($transaction, PaymentGatewayRequestType::PAYMENT_INITIATE, $orderData, $orderArray);

        return route('razorpayEmbeddedCheckout', ['transaction' => $transaction->id, 'order' => $orderArray['id']]);
    }

    /**
     * Renders the auto-submitting form that hands the customer off to Razorpay's
     * hosted embedded checkout page. Bound directly as the handler for the
     * `razorpayEmbeddedCheckout` route (no dedicated controller needed).
     */
    public function checkoutForm(Transaction $transaction, string $order): View
    {
        $transaction->loadMissing(['pgConnection', 'customer', 'client']);

        $amount = $transaction->amount['amount'];

        return view('razorpay.checkout', [
            'checkoutEndpoint' => self::EMBEDDED_CHECKOUT_ENDPOINT,
            'keyId' => (string) ($transaction->pgConnection->attributes['key_id'] ?? ''),
            'orderId' => $order,
            'amountMinorUnits' => $amount->getMinorAmount()->toInt(),
            'currency' => (string) $transaction->currency,
            'name' => $transaction->client->name ?? '',
            'description' => 'Payment for '.$transaction->site_reference_id,
            'callbackUrl' => route('handlePaymentResponse', [
                'pgClass' => 'RAZORPAY',
                'transactionDbId' => $transaction->id,
            ]),
            'cancelUrl' => route('handlePaymentResponse', [
                'pgClass' => 'RAZORPAY',
                'transactionDbId' => $transaction->id,
                'status' => 'cancelled',
            ]),
            'customerName' => $transaction->customer->name ?? '',
            'customerEmail' => $transaction->customer->email ?? '',
            'customerContact' => $transaction->customer->mobile ?? '',
        ]);
    }

    protected function mapPaymentMethod(string $method): PaymentMethod
    {
        return match ($method) {
            'card' => PaymentMethod::CARD,
            'netbanking' => PaymentMethod::NETBANKING,
            'wallet' => PaymentMethod::WALLET,
            'upi' => PaymentMethod::UPI,
            'emi' => PaymentMethod::CARD,
            default => PaymentMethod::UNKNOWN,
        };
    }

    protected function mapPaymentStatus(string $status): TransactionStatus
    {
        return match ($status) {
            'captured' => TransactionStatus::SUCCESS,
            'authorized' => TransactionStatus::PROCESSING,
            'refunded' => TransactionStatus::REFUNDED,
            'created' => TransactionStatus::PENDING,
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

        $keyId = (string) ($transaction->pgConnection->attributes['key_id'] ?? '');
        $keySecret = (string) ($transaction->pgConnection->attributes['key_secret'] ?? '');

        $status = TransactionStatus::FAILED;
        $description = (string) ($response['error_description'] ?? 'Payment failed or was cancelled.');
        $paymentDetails = [];
        $transactionId = (string) ($response['razorpay_payment_id'] ?? '');

        if (($response['status'] ?? null) === 'cancelled') {
            $description = 'Payment was cancelled by the customer.';
        } elseif (isset($response['razorpay_payment_id'], $response['razorpay_order_id'], $response['razorpay_signature'])) {
            try {
                $this->authenticate($keyId, $keySecret);
                (new Utility)->verifyPaymentSignature($response);
                $status = TransactionStatus::SUCCESS;
                $description = 'Payment successful.';
            } catch (SignatureVerificationError $e) {
                $status = TransactionStatus::FAILED;
                $description = 'Signature verification failed: '.$e->getMessage();
            }
        }

        $amount = $transaction->amount['amount'];
        $pgFees = $this->calculateFees($amount);
        $paymentMethod = PaymentMethod::UNKNOWN;

        if ($status === TransactionStatus::SUCCESS && $transactionId !== '') {
            try {
                $this->authenticate($keyId, $keySecret);
                $payment = (new Payment)->fetch($transactionId);
                $paymentDetails = $payment->toArray();
                $paymentMethod = $this->mapPaymentMethod((string) ($paymentDetails['method'] ?? ''));

                if (isset($paymentDetails['fee'])) {
                    $pgFees = Money::ofMinor($paymentDetails['fee'], (string) $transaction->currency);
                }
            } catch (\Throwable $e) {
                // Signature is already verified; fall back to defaults if the fetch fails.
            }
        }

        $this->logApiCall($transaction, PaymentGatewayRequestType::PAYMENT_INITIATE, $response, $paymentDetails ?: $response, $status === TransactionStatus::SUCCESS ? '200' : '400');

        return new PaymentResponseDTO(
            transactionDbId: (string) $transaction->id,
            siteReferenceId: $transaction->site_reference_id,
            status: $status,
            transactionId: $transactionId,
            description: $description,
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
            throw new \Exception('No Razorpay payment id recorded for this transaction.');
        }

        try {
            $this->authenticate();
            $payment = (new Payment)->fetch($transaction->transaction_id);
        } catch (\Throwable $e) {
            throw new \Exception('Payment Gateway Error: '.$e->getMessage());
        }

        $paymentDetails = $payment->toArray();

        $this->logApiCall($transaction, PaymentGatewayRequestType::STATUS_CHECK, ['payment_id' => $transaction->transaction_id], $paymentDetails);

        $status = $this->mapPaymentStatus((string) ($paymentDetails['status'] ?? ''));
        $amount = Money::ofMinor($paymentDetails['amount'], (string) $transaction->currency);
        $pgFees = isset($paymentDetails['fee'])
            ? Money::ofMinor($paymentDetails['fee'], (string) $transaction->currency)
            : $this->calculateFees($amount);

        return new PaymentResponseDTO(
            transactionDbId: (string) $transaction->id,
            siteReferenceId: $transaction->site_reference_id,
            status: $status,
            transactionId: (string) $transaction->transaction_id,
            description: 'Razorpay status check: '.($paymentDetails['status'] ?? 'unknown'),
            amount: $amount,
            pgFees: $pgFees,
            totalAmount: $amount->plus($pgFees),
            transactionDateTime: isset($paymentDetails['created_at'])
                ? CarbonImmutable::createFromTimestamp($paymentDetails['created_at'])
                : CarbonImmutable::now(),
            currency: $transaction->currency,
            paymentMethod: $this->mapPaymentMethod((string) ($paymentDetails['method'] ?? '')),
            clientName: $transaction->client->name,
            pgConnection: $transaction->pgConnection->name,
            pgResponseRaw: $paymentDetails,
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
            throw new \Exception('Refunds are not supported by this Razorpay connection.');
        }

        $transaction->loadMissing(['client', 'pgConnection']);

        if (! $transaction->transaction_id) {
            throw new \Exception('No Razorpay payment id recorded for this transaction.');
        }

        $requestData = [
            'payment_id' => $transaction->transaction_id,
            'amount' => $paymentRefundRequest->amount->getMinorAmount()->toInt(),
            'notes' => ['reason' => $paymentRefundRequest->refundReason],
        ];

        try {
            $this->authenticate();
            $refund = (new Payment)->fetch($transaction->transaction_id)->refund([
                'amount' => $requestData['amount'],
                'notes' => $requestData['notes'],
            ]);
        } catch (\Throwable $e) {
            $this->logApiCall($transaction, PaymentGatewayRequestType::REFUND, $requestData, ['error' => $e->getMessage()], '400');

            throw new \Exception('Payment Gateway Error: '.$e->getMessage());
        }

        $refundDetails = $refund->toArray();

        $this->logApiCall($transaction, PaymentGatewayRequestType::REFUND, $requestData, $refundDetails);

        return new PaymentResponseDTO(
            transactionDbId: (string) $transaction->id,
            siteReferenceId: $transaction->site_reference_id,
            status: TransactionStatus::REFUNDED,
            transactionId: (string) $transaction->transaction_id,
            description: 'Razorpay refund processed',
            amount: $paymentRefundRequest->amount,
            pgFees: $transaction->pg_fees['pg_fees'],
            totalAmount: $paymentRefundRequest->amount,
            transactionDateTime: CarbonImmutable::now(),
            currency: $paymentRefundRequest->currency,
            paymentMethod: $transaction->payment_method ?? PaymentMethod::UNKNOWN,
            clientName: $transaction->client->name,
            pgConnection: $transaction->pgConnection->name,
            pgResponseRaw: $refundDetails,
        );
    }
}
