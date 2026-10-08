<?php

namespace App\Classes\PaymentGateways;

use App\Contracts\PaymentGatewayInterface;
use App\Contracts\SubscriptionGatewayInterface;
use App\DTO\PaymentRefundDTO;
use App\DTO\PaymentRequestDTO;
use App\DTO\PaymentResponseDTO;
use App\DTO\SubscriptionChargeRequestDTO;
use App\DTO\SubscriptionChargeResponseDTO;
use App\DTO\SubscriptionManageDTO;
use App\DTO\SubscriptionRequestDTO;
use App\DTO\SubscriptionResponseDTO;
use App\Enums\ConnectionType;
use App\Enums\PaymentGatewayRequestType;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionAction;
use App\Enums\SubscriptionStatus;
use App\Enums\TransactionStatus;
use App\Models\PaymentGatewayConnectionApiLog;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\Transaction;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Devhammed\LaravelBrickMoney\Money;
use Illuminate\Support\Str;

/**
 * Simulates a live payment gateway for local/dev/QA use: instead of calling
 * out to a real bank/PG, it sends the customer to a locally hosted checkout
 * page (PGSimulatorController) where the outcome (success/failed/pending)
 * is chosen manually, which then posts back through the normal
 * handlePaymentResponse callback flow like any real gateway would.
 */
class PGSimulator implements PaymentGatewayInterface, SubscriptionGatewayInterface
{
    protected bool $feesIncludedInAmount;

    protected float $feesRate;

    protected bool $isRefundSupported;

    protected ConnectionType $connectionType;

    /**
     * @param  array<string, mixed>  $pg_data
     */
    public function __construct(array $pg_data = [], ConnectionType|string $connectionType = ConnectionType::TEST)
    {
        $this->feesIncludedInAmount = (bool) ($pg_data['fees_included_in_amount'] ?? false);
        $this->feesRate = (float) ($pg_data['fees_rate'] ?? 0);
        $this->isRefundSupported = (bool) ($pg_data['supports_refunds'] ?? true);

        $this->connectionType = $connectionType instanceof ConnectionType
            ? $connectionType
            : ConnectionType::from($connectionType);
    }

    public function calculateFees(Money $amount): Money
    {
        return $amount->multipliedBy($this->feesRate / 100, RoundingMode::HALF_UP);
    }

    public function handlePaymentRequest(PaymentRequestDTO $paymentRequest, Transaction $transaction): string
    {
        return route('pgSimulatorCheckout', ['transaction' => $transaction->id]);
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    protected function logSimulatedApiCall(Transaction $transaction, PaymentGatewayRequestType $requestType, array $requestData, array $responseData): void
    {
        PaymentGatewayConnectionApiLog::create([
            'client_id' => $transaction->client_id,
            'pg_connection_id' => $transaction->pg_connection_id,
            'transaction_id' => $transaction->id,
            'request_type' => $requestType,
            'request_data' => $requestData,
            'response_data' => $responseData,
            'response_status' => '200',
        ]);
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    protected function logSimulatedSubscriptionApiCall(
        Subscription $subscription,
        ?SubscriptionTransaction $transaction,
        PaymentGatewayRequestType $requestType,
        array $requestData,
        array $responseData
    ): void {
        PaymentGatewayConnectionApiLog::create([
            'client_id' => $subscription->client_id,
            'pg_connection_id' => $subscription->pg_connection_id,
            'transaction_id' => null,
            'subscription_id' => $subscription->id,
            'subscription_transaction_id' => $transaction?->id,
            'request_type' => $requestType,
            'request_data' => $requestData,
            'response_data' => $responseData,
            'response_status' => '200',
        ]);
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

        $status = TransactionStatus::tryFrom((string) ($response['status'] ?? '')) ?? TransactionStatus::FAILED;
        $amount = $transaction->amount['amount'];
        $pgFees = isset($response['pgFees']) && is_numeric($response['pgFees'])
            ? Money::of($response['pgFees'], $transaction->currency)
            : $this->calculateFees($amount);
        $paymentMethod = PaymentMethod::tryFrom((string) ($response['paymentMethod'] ?? '')) ?? PaymentMethod::UNKNOWN;
        $transactionId = (string) ($response['transactionId'] ?? ('SIM'.strtoupper(Str::random(12))));

        $this->logSimulatedApiCall($transaction, PaymentGatewayRequestType::PAYMENT_INITIATE, $response, [
            'status' => $status->value,
            'transactionId' => $transactionId,
        ]);

        return new PaymentResponseDTO(
            transactionDbId: (string) $transaction->id,
            siteReferenceId: $transaction->site_reference_id,
            status: $status,
            transactionId: $transactionId,
            description: 'Simulated payment: '.$status->value,
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

        $responseData = $transaction->response_data ?? [];

        $this->logSimulatedApiCall(
            $transaction,
            PaymentGatewayRequestType::STATUS_CHECK,
            ['transactionDbId' => $transaction->id],
            $responseData
        );

        return new PaymentResponseDTO(
            transactionDbId: (string) $transaction->id,
            siteReferenceId: $transaction->site_reference_id,
            status: $transaction->status,
            transactionId: (string) ($transaction->transaction_id ?? ''),
            description: 'Simulated status check',
            amount: $transaction->transaction_amount['transaction_amount'],
            pgFees: $transaction->pg_fees['pg_fees'],
            totalAmount: $transaction->total_amount['total_amount'],
            transactionDateTime: $transaction->transaction_date_time
                ? CarbonImmutable::instance($transaction->transaction_date_time)
                : CarbonImmutable::now(),
            currency: $transaction->currency,
            paymentMethod: $transaction->payment_method ?? PaymentMethod::UNKNOWN,
            clientName: $transaction->client->name,
            pgConnection: $transaction->pgConnection->name,
            pgResponseRaw: $responseData,
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
            throw new \Exception('Refunds are not supported by this PG Simulator connection.');
        }

        $transaction->loadMissing(['client', 'pgConnection']);

        $requestData = [
            'refundReason' => $paymentRefundRequest->refundReason,
            'refundedAmount' => (string) $paymentRefundRequest->amount->getAmount(),
        ];

        $this->logSimulatedApiCall($transaction, PaymentGatewayRequestType::REFUND, $requestData, [
            'status' => TransactionStatus::REFUNDED->value,
        ]);

        return new PaymentResponseDTO(
            transactionDbId: (string) $transaction->id,
            siteReferenceId: $transaction->site_reference_id,
            status: TransactionStatus::REFUNDED,
            transactionId: (string) ($transaction->transaction_id ?? ''),
            description: 'Simulated refund processed',
            amount: $paymentRefundRequest->amount,
            pgFees: $transaction->pg_fees['pg_fees'],
            totalAmount: $paymentRefundRequest->amount,
            transactionDateTime: CarbonImmutable::now(),
            currency: $paymentRefundRequest->currency,
            paymentMethod: $transaction->payment_method ?? PaymentMethod::UNKNOWN,
            clientName: $transaction->client->name,
            pgConnection: $transaction->pgConnection->name,
            pgResponseRaw: $requestData,
        );
    }

    /* -------------------------------------------------------------------------
     * SubscriptionGatewayInterface Implementation
     * ---------------------------------------------------------------------- */

    public function handleSubscriptionRequest(SubscriptionRequestDTO $request, Subscription $subscription): string
    {
        $subId = 'SIMSUB'.$subscription->id;

        $subscription->subscription_id = $subId;
        $subscription->pg_reference_id = 'CFSUB'.$subscription->id;
        $subscription->status = SubscriptionStatus::INITIALIZED;
        $subscription->save();

        $this->logSimulatedSubscriptionApiCall(
            $subscription,
            null,
            PaymentGatewayRequestType::SUBSCRIPTION_CREATE,
            ['request' => $request->toArray()],
            ['subscription_id' => $subId, 'status' => SubscriptionStatus::INITIALIZED->value]
        );

        return route('pgSimulatorSubscriptionCheckout', ['subscription' => $subscription->id]);
    }

    public function handleSubscriptionResponse(array $response): SubscriptionResponseDTO
    {
        $subscriptionDbId = (string) ($response['subscriptionDbId'] ?? '');
        $subscription = Subscription::with(['client', 'pgConnection'])->find($subscriptionDbId);

        if (! $subscription) {
            throw new \Exception('Subscription not found.');
        }

        $rawStatus = strtoupper((string) ($response['status'] ?? ''));
        if ($rawStatus === 'SUCCESS') {
            $status = SubscriptionStatus::ACTIVE;
        } else {
            $status = SubscriptionStatus::tryFrom($rawStatus) ?? SubscriptionStatus::ACTIVE;
        }

        $paymentMethod = PaymentMethod::tryFrom(strtolower((string) ($response['paymentMethod'] ?? ''))) ?? PaymentMethod::UPI;
        $authRef = (string) ($response['authorizationReference'] ?? ('SIMUMRN'.strtoupper(Str::random(10))));

        $subscription->status = $status;
        $subscription->payment_method = $paymentMethod;
        $subscription->authorization_reference = $authRef;
        $subscription->response_data = $response;
        $subscription->save();

        $this->logSimulatedSubscriptionApiCall(
            $subscription,
            null,
            PaymentGatewayRequestType::SUBSCRIPTION_STATUS,
            $response,
            ['status' => $status->value, 'authRef' => $authRef]
        );

        return $this->getSubscriptionStatus($subscription);
    }

    public function getSubscriptionStatus(Subscription $subscription): SubscriptionResponseDTO
    {
        $subscription->loadMissing(['client', 'pgConnection']);

        return new SubscriptionResponseDTO(
            subscriptionDbId: (string) $subscription->id,
            siteReferenceId: $subscription->site_reference_id,
            status: $subscription->status,
            subscriptionId: (string) ($subscription->subscription_id ?? ('SIMSUB'.$subscription->id)),
            pgReferenceId: $subscription->pg_reference_id,
            authorizationReference: $subscription->authorization_reference,
            subscriptionType: $subscription->subscription_type,
            amount: $subscription->amount['amount'],
            maxAmount: $subscription->max_amount['max_amount'],
            currency: $subscription->currency,
            period: $subscription->period,
            interval: $subscription->interval,
            paymentMethod: $subscription->payment_method ?? PaymentMethod::UNKNOWN,
            nextChargeDateTime: $subscription->next_charge_date_time
                ? CarbonImmutable::instance($subscription->next_charge_date_time)
                : null,
            description: 'Simulated subscription status: '.$subscription->status->value,
            clientName: $subscription->client->name,
            pgConnection: $subscription->pgConnection->name,
            pgResponseRaw: $subscription->response_data ?? [],
        );
    }

    public function manageSubscription(Subscription $subscription, SubscriptionManageDTO $action): SubscriptionResponseDTO
    {
        $subscription->loadMissing(['client', 'pgConnection']);

        $newStatus = match ($action->action) {
            SubscriptionAction::CANCEL => SubscriptionStatus::CANCELLED,
            SubscriptionAction::PAUSE => SubscriptionStatus::PAUSED,
            SubscriptionAction::RESUME => SubscriptionStatus::ACTIVE,
        };

        $subscription->status = $newStatus;
        $subscription->save();

        $this->logSimulatedSubscriptionApiCall(
            $subscription,
            null,
            PaymentGatewayRequestType::SUBSCRIPTION_MANAGE,
            ['action' => $action->action->value],
            ['status' => $newStatus->value]
        );

        return $this->getSubscriptionStatus($subscription);
    }

    public function chargeSubscription(
        Subscription $subscription,
        SubscriptionTransaction $charge,
        SubscriptionChargeRequestDTO $request
    ): SubscriptionChargeResponseDTO {
        $subscription->loadMissing(['client', 'pgConnection']);

        $chargeId = 'SIMTXN'.$charge->id;
        $pgFees = $this->calculateFees($request->amount);
        $paymentMethod = $subscription->payment_method ?? PaymentMethod::UPI;

        $charge->payment_id = $chargeId;
        $charge->transaction_id = $chargeId;
        $charge->status = TransactionStatus::SUCCESS;
        $charge->payment_method = $paymentMethod;
        $charge->pg_fees = $pgFees;
        $charge->transaction_date_time = now();
        $charge->data = ['simulated' => true, 'charge_id' => $chargeId];
        $charge->save();

        $this->logSimulatedSubscriptionApiCall(
            $subscription,
            $charge,
            PaymentGatewayRequestType::SUBSCRIPTION_CHARGE,
            ['amount' => (string) $request->amount->getAmount()],
            ['status' => TransactionStatus::SUCCESS->value, 'transaction_id' => $chargeId]
        );

        return new SubscriptionChargeResponseDTO(
            chargeDbId: (string) $charge->id,
            siteReferenceId: (string) ($charge->site_reference_id ?? ''),
            subscriptionReferenceId: $subscription->site_reference_id,
            status: TransactionStatus::SUCCESS,
            transactionId: $chargeId,
            paymentId: $chargeId,
            amount: $request->amount,
            pgFees: $pgFees,
            currency: $subscription->currency,
            paymentMethod: $paymentMethod,
            transactionDateTime: CarbonImmutable::now(),
            description: 'Simulated charge success',
            pgResponseRaw: ['status' => 'SUCCESS'],
        );
    }

    public function getChargeStatus(SubscriptionTransaction $charge): SubscriptionChargeResponseDTO
    {
        $charge->loadMissing(['subscription.client', 'subscription.pgConnection']);
        $subscription = $charge->subscription;

        return new SubscriptionChargeResponseDTO(
            chargeDbId: (string) $charge->id,
            siteReferenceId: (string) ($charge->site_reference_id ?? ''),
            subscriptionReferenceId: $subscription->site_reference_id,
            status: $charge->status,
            transactionId: (string) ($charge->transaction_id ?? ''),
            paymentId: (string) ($charge->payment_id ?? ''),
            amount: $charge->amount['amount'],
            pgFees: $charge->pg_fees['pg_fees'],
            currency: $subscription->currency,
            paymentMethod: $charge->payment_method ?? PaymentMethod::UNKNOWN,
            transactionDateTime: $charge->transaction_date_time
                ? CarbonImmutable::instance($charge->transaction_date_time)
                : CarbonImmutable::now(),
            description: 'Simulated charge status check',
            pgResponseRaw: $charge->data ?? [],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCharges(Subscription $subscription): array
    {
        return $subscription->transactions->map(fn ($t) => $t->toArray())->all();
    }
}
