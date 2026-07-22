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
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

class Stripe implements PaymentGatewayInterface
{
    use LogsApiCalls;

    protected ?string $keySecret;

    protected bool $isRefundSupported;

    protected bool $feesIncludedInAmount;

    protected float $feesRate;

    protected ConnectionType $connectionType;

    /**
     * @param  array<string, mixed>  $pg_data
     */
    public function __construct(array $pg_data = [], ConnectionType|string $connectionType = ConnectionType::TEST)
    {
        $this->keySecret = $pg_data['key_secret'] ?? null;
        $this->isRefundSupported = (bool) ($pg_data['supports_refunds'] ?? false);
        $this->feesIncludedInAmount = (bool) ($pg_data['fees_included_in_amount'] ?? false);
        $this->feesRate = (float) ($pg_data['fees_rate'] ?? 0);

        $this->connectionType = $connectionType instanceof ConnectionType
            ? $connectionType
            : ConnectionType::from($connectionType);
    }

    protected function client(?string $keySecret = null): StripeClient
    {
        $keySecret ??= $this->keySecret;

        if (! $keySecret) {
            throw new \Exception('Missing Stripe API credentials.');
        }

        return new StripeClient($keySecret);
    }

    protected function calculateFees(Money $amount): Money
    {
        return $amount->multipliedBy($this->feesRate / 100, RoundingMode::HALF_UP);
    }

    public function handlePaymentRequest(PaymentRequestDTO $paymentRequest, Transaction $transaction): string
    {
        $sessionData = $this->buildSessionPayload($paymentRequest, $transaction);
        $session = $this->createCheckoutSession($transaction, $sessionData);

        return (string) $session->url;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildSessionPayload(PaymentRequestDTO $paymentRequest, Transaction $transaction): array
    {
        return [
            'mode' => 'payment',
            'client_reference_id' => (string) $transaction->id,
            'customer_email' => $paymentRequest->customer['email'] ?? null,
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower((string) $paymentRequest->currency),
                    'product_data' => [
                        'name' => 'Payment for '.$paymentRequest->site_reference_id,
                    ],
                    'unit_amount' => $paymentRequest->amount->getMinorAmount()->toInt(),
                ],
                'quantity' => 1,
            ]],
            'success_url' => route('handlePaymentResponse', [
                'pgClass' => 'STRIPE',
                'transactionDbId' => $transaction->id,
            ]).'&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('handlePaymentResponse', [
                'pgClass' => 'STRIPE',
                'transactionDbId' => $transaction->id,
                'status' => 'cancelled',
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $sessionData
     */
    protected function createCheckoutSession(Transaction $transaction, array $sessionData): Session
    {
        try {
            $session = $this->client()->checkout->sessions->create($sessionData);
        } catch (\Throwable $e) {
            $this->logApiFailure($transaction, PaymentGatewayRequestType::PAYMENT_INITIATE, $sessionData, $e);

            throw new \Exception('Payment Gateway Error: '.$e->getMessage());
        }

        $this->logApiCall($transaction, PaymentGatewayRequestType::PAYMENT_INITIATE, $sessionData, [
            'id' => $session->id,
            'status' => $session->status,
        ]);

        return $session;
    }

    protected function mapPaymentMethod(string $type): PaymentMethod
    {
        return match ($type) {
            'card' => PaymentMethod::CARD,
            'upi' => PaymentMethod::UPI,
            'us_bank_account', 'sepa_debit', 'ideal' => PaymentMethod::NETBANKING,
            'wechat_pay', 'alipay', 'amazon_pay', 'cashapp' => PaymentMethod::WALLET,
            default => PaymentMethod::UNKNOWN,
        };
    }

    protected function mapSessionStatus(Session $session): TransactionStatus
    {
        if ($session->payment_status === 'paid' || $session->payment_status === 'no_payment_required') {
            return TransactionStatus::SUCCESS;
        }

        return match ($session->status) {
            'open' => TransactionStatus::PENDING,
            'expired' => TransactionStatus::FAILED,
            default => TransactionStatus::FAILED,
        };
    }

    protected function mapPaymentIntentStatus(string $status): TransactionStatus
    {
        return match ($status) {
            'succeeded' => TransactionStatus::SUCCESS,
            'processing', 'requires_capture' => TransactionStatus::PROCESSING,
            'requires_payment_method', 'requires_confirmation', 'requires_action' => TransactionStatus::PENDING,
            'canceled' => TransactionStatus::FAILED,
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

        $sessionId = (string) ($response['session_id'] ?? '');

        if ($sessionId === '') {
            throw new \Exception('Missing Stripe checkout session id in callback.');
        }

        $session = $this->retrieveCheckoutSession($transaction, $sessionId);

        return $this->buildSessionResponse($transaction, $response, $session);
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

    protected function retrieveCheckoutSession(Transaction $transaction, string $sessionId): Session
    {
        $keySecret = (string) ($transaction->pgConnection->attributes['key_secret'] ?? '');

        try {
            $session = $this->client($keySecret)->checkout->sessions->retrieve($sessionId, [
                'expand' => ['payment_intent'],
            ]);
        } catch (\Throwable $e) {
            $this->logApiFailure($transaction, PaymentGatewayRequestType::PAYMENT_INITIATE, ['session_id' => $sessionId], $e);

            throw new \Exception('Payment Gateway Error: '.$e->getMessage());
        }

        $paymentIntent = $session->payment_intent;

        $this->logApiCall($transaction, PaymentGatewayRequestType::PAYMENT_INITIATE, ['session_id' => $sessionId], [
            'id' => $session->id,
            'status' => $session->status,
            'payment_status' => $session->payment_status,
            'payment_intent_id' => $paymentIntent instanceof PaymentIntent ? $paymentIntent->id : null,
        ]);

        return $session;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function buildSessionResponse(Transaction $transaction, array $response, Session $session): PaymentResponseDTO
    {
        $status = $this->mapSessionStatus($session);
        $paymentIntent = $session->payment_intent;
        $transactionId = $paymentIntent instanceof PaymentIntent ? (string) $paymentIntent->id : (string) $session->id;
        $paymentMethodTypes = $session->payment_method_types ?? [];
        $paymentMethod = $this->mapPaymentMethod((string) ($paymentMethodTypes[0] ?? ''));

        $amount = $transaction->amount['amount'];
        $pgFees = $this->calculateFees($amount);

        if ($session->amount_total !== null) {
            $amount = Money::ofMinor($session->amount_total, strtoupper((string) $session->currency));
            $pgFees = $this->calculateFees($amount);
        }

        return new PaymentResponseDTO(
            transactionDbId: (string) $transaction->id,
            siteReferenceId: $transaction->site_reference_id,
            status: $status,
            transactionId: $transactionId,
            description: 'Stripe session status: '.$session->status.' ('.$session->payment_status.')',
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
            throw new \Exception('No Stripe payment intent id recorded for this transaction.');
        }

        try {
            $paymentIntent = $this->client()->paymentIntents->retrieve($transaction->transaction_id);
        } catch (\Throwable $e) {
            $this->logApiFailure($transaction, PaymentGatewayRequestType::STATUS_CHECK, ['payment_intent_id' => $transaction->transaction_id], $e);

            throw new \Exception('Payment Gateway Error: '.$e->getMessage());
        }

        $this->logApiCall($transaction, PaymentGatewayRequestType::STATUS_CHECK, ['payment_intent_id' => $transaction->transaction_id], [
            'id' => $paymentIntent->id,
            'status' => $paymentIntent->status,
        ]);

        $status = $this->mapPaymentIntentStatus((string) $paymentIntent->status);
        $amount = Money::ofMinor($paymentIntent->amount, strtoupper((string) $paymentIntent->currency));
        $pgFees = $this->calculateFees($amount);

        return new PaymentResponseDTO(
            transactionDbId: (string) $transaction->id,
            siteReferenceId: $transaction->site_reference_id,
            status: $status,
            transactionId: (string) $paymentIntent->id,
            description: 'Stripe payment intent status: '.$paymentIntent->status,
            amount: $amount,
            pgFees: $pgFees,
            totalAmount: $amount->plus($pgFees),
            transactionDateTime: CarbonImmutable::createFromTimestamp($paymentIntent->created),
            currency: $transaction->currency,
            paymentMethod: $transaction->payment_method ?? PaymentMethod::UNKNOWN,
            clientName: $transaction->client->name,
            pgConnection: $transaction->pgConnection->name,
            pgResponseRaw: $paymentIntent->toArray(),
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
            throw new \Exception('Refunds are not supported by this Stripe connection.');
        }

        $transaction->loadMissing(['client', 'pgConnection']);

        if (! $transaction->transaction_id) {
            throw new \Exception('No Stripe payment intent id recorded for this transaction.');
        }

        $requestData = [
            'payment_intent' => $transaction->transaction_id,
            'amount' => $paymentRefundRequest->amount->getMinorAmount()->toInt(),
            'reason' => 'requested_by_customer',
        ];

        try {
            $refund = $this->client()->refunds->create($requestData);
        } catch (\Throwable $e) {
            $this->logApiFailure($transaction, PaymentGatewayRequestType::REFUND, $requestData, $e);

            throw new \Exception('Payment Gateway Error: '.$e->getMessage());
        }

        $this->logApiCall($transaction, PaymentGatewayRequestType::REFUND, $requestData, $refund->toArray());

        return new PaymentResponseDTO(
            transactionDbId: (string) $transaction->id,
            siteReferenceId: $transaction->site_reference_id,
            status: TransactionStatus::REFUNDED,
            transactionId: (string) $transaction->transaction_id,
            description: 'Stripe refund '.$refund->status,
            amount: $paymentRefundRequest->amount,
            pgFees: $transaction->pg_fees['pg_fees'],
            totalAmount: $paymentRefundRequest->amount,
            transactionDateTime: CarbonImmutable::now(),
            currency: $paymentRefundRequest->currency,
            paymentMethod: $transaction->payment_method ?? PaymentMethod::UNKNOWN,
            clientName: $transaction->client->name,
            pgConnection: $transaction->pgConnection->name,
            pgResponseRaw: $refund->toArray(),
        );
    }
}
