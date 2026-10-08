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
use App\Enums\SubscriptionPeriod;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionType;
use App\Enums\TransactionStatus;
use App\Models\PaymentGatewayConnectionApiLog;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\Transaction;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Devhammed\LaravelBrickMoney\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Cashfree implements PaymentGatewayInterface, SubscriptionGatewayInterface
{
    /**
     * Our own order id prefix, so an incoming order_id (echoed back by
     * Cashfree on the return_url) can be mapped straight back to a
     * transactionDbId without needing any side-channel lookup.
     */
    const ORDER_ID_PREFIX = 'TXN';

    const SUBSCRIPTION_ID_PREFIX = 'SUB';

    const CHARGE_ID_PREFIX = 'SUBTXN';

    const API_VERSION = '2025-01-01';

    const SUBSCRIPTION_API_VERSION = '2026-01-01';

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
    protected function headers(?string $clientId = null, ?string $clientSecret = null, string $apiVersion = self::API_VERSION): array
    {
        $clientId ??= $this->clientId;
        $clientSecret ??= $this->clientSecret;

        if (! $clientId || ! $clientSecret) {
            throw new \Exception('Missing Cashfree API credentials.');
        }

        return [
            'Content-Type' => 'application/json',
            'x-api-version' => $apiVersion,
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

    /**
     * @param  array<string, mixed>  $requestData
     */
    protected function logSubscriptionApiCall(
        Subscription $subscription,
        ?SubscriptionTransaction $transaction,
        PaymentGatewayRequestType $requestType,
        array $requestData,
        Response $response
    ): void {
        PaymentGatewayConnectionApiLog::create([
            'client_id' => $subscription->client_id,
            'pg_connection_id' => $subscription->pg_connection_id,
            'transaction_id' => null,
            'subscription_id' => $subscription->id,
            'subscription_transaction_id' => $transaction?->id,
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

    protected function subscriptionIdFor(Subscription $subscription): string
    {
        return self::SUBSCRIPTION_ID_PREFIX.$subscription->id;
    }

    protected function subscriptionDbIdFromSubscriptionId(string $subscriptionId): string
    {
        return Str::startsWith($subscriptionId, self::SUBSCRIPTION_ID_PREFIX)
            ? Str::after($subscriptionId, self::SUBSCRIPTION_ID_PREFIX)
            : $subscriptionId;
    }

    protected function chargeIdFor(SubscriptionTransaction $charge): string
    {
        return self::CHARGE_ID_PREFIX.$charge->id;
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
     * checkout for the given payment session.
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

    /**
     * Renders the page that boots the Cashfree JS SDK and opens its subscription
     * checkout for the given subscription session.
     */
    public function subscriptionCheckoutForm(Subscription $subscription, string $session): View
    {
        $subscription->loadMissing(['pgConnection']);

        $mode = ($subscription->pgConnection->type ?? ConnectionType::TEST) === ConnectionType::PRODUCTION
            ? 'production'
            : 'sandbox';

        return view('cashfree.subscription-checkout', [
            'mode' => $mode,
            'subsSessionId' => $session,
        ]);
    }

    protected function mapPaymentGroup(string $paymentGroup): PaymentMethod
    {
        return match (strtolower($paymentGroup)) {
            'card', 'credit_card', 'debit_card', 'credit_card_emi', 'debit_card_emi', 'prepaid_card' => PaymentMethod::CARD,
            'net_banking', 'netbanking', 'enach', 'pnach' => PaymentMethod::NETBANKING,
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

    protected function mapSubscriptionStatus(string $status): SubscriptionStatus
    {
        return match (strtoupper($status)) {
            'INITIALIZED' => SubscriptionStatus::INITIALIZED,
            'BANK_APPROVAL_PENDING' => SubscriptionStatus::BANK_APPROVAL_PENDING,
            'ACTIVE' => SubscriptionStatus::ACTIVE,
            'ON_HOLD', 'PAUSED', 'CUSTOMER_PAUSED' => SubscriptionStatus::PAUSED,
            'CANCELLED', 'CUSTOMER_CANCELLED' => SubscriptionStatus::CANCELLED,
            'COMPLETED' => SubscriptionStatus::COMPLETED,
            'EXPIRED', 'LINK_EXPIRED', 'CARD_EXPIRED' => SubscriptionStatus::EXPIRED,
            default => SubscriptionStatus::FAILED,
        };
    }

    protected function mapChargeStatus(string $status): TransactionStatus
    {
        return match (strtoupper($status)) {
            'SUCCESS' => TransactionStatus::SUCCESS,
            'PENDING', 'INITIALIZED' => TransactionStatus::PENDING,
            'CANCELLED' => TransactionStatus::CANCELLED,
            default => TransactionStatus::FAILED,
        };
    }

    /**
     * Fetches the order and its latest payment attempt from Cashfree and maps
     * them to a PaymentResponseDTO.
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

    /* -------------------------------------------------------------------------
     * SubscriptionGatewayInterface Implementation
     * ---------------------------------------------------------------------- */

    public function handleSubscriptionRequest(SubscriptionRequestDTO $request, Subscription $subscription): string
    {
        $subId = $this->subscriptionIdFor($subscription);
        $isPeriodic = $request->subscriptionType === SubscriptionType::PERIODIC;

        $planDetails = [
            'plan_name' => substr($request->planName ?? ('Plan '.$request->site_reference_id), 0, 40),
            'plan_type' => $isPeriodic ? 'PERIODIC' : 'ON_DEMAND',
            'plan_currency' => (string) $request->currency,
            'plan_max_amount' => (float) (string) $request->maxAmount->getAmount(),
        ];

        if ($isPeriodic) {
            $planDetails['plan_amount'] = (float) (string) $request->amount->getAmount();
            if ($request->maxCycles !== null) {
                $planDetails['plan_max_cycles'] = $request->maxCycles;
            }

            $intervalType = match ($request->period) {
                SubscriptionPeriod::DAILY => 'DAY',
                SubscriptionPeriod::WEEKLY => 'WEEK',
                SubscriptionPeriod::MONTHLY => 'MONTH',
                SubscriptionPeriod::QUARTERLY => 'MONTH',
                SubscriptionPeriod::YEARLY => 'YEAR',
                default => 'MONTH',
            };

            $intervalCount = ($request->period === SubscriptionPeriod::QUARTERLY)
                ? (($request->interval ?? 1) * 3)
                : ($request->interval ?? 1);

            $planDetails['plan_interval_type'] = $intervalType;
            $planDetails['plan_intervals'] = $intervalCount;
        }

        $subscriptionData = [
            'subscription_id' => $subId,
            'customer_details' => [
                'customer_name' => (string) ($request->customer['name'] ?? ''),
                'customer_email' => (string) ($request->customer['email'] ?? ''),
                'customer_phone' => (string) ($request->customer['mobile'] ?? ''),
            ],
            'plan_details' => $planDetails,
            'authorization_details' => [
                'authorization_amount' => (float) (string) $request->authAmount->getAmount(),
                'authorization_amount_refund' => true,
                'payment_methods' => ['enach', 'pnach', 'upi', 'card'],
            ],
            'subscription_meta' => [
                'return_url' => route('handleSubscriptionResponse', ['pgClass' => 'CASHFREE']).'?subscription_id='.$subId,
            ],
        ];

        $expiryTime = $request->expiresAt ?? $subscription->end_date_time ?? now()->addYears(10);
        $subscriptionData['subscription_expiry_time'] = $expiryTime->toIso8601String();

        $response = Http::withHeaders($this->headers(apiVersion: self::SUBSCRIPTION_API_VERSION))
            ->post($this->baseUrl().'/subscriptions', $subscriptionData);

        $this->logSubscriptionApiCall($subscription, null, PaymentGatewayRequestType::SUBSCRIPTION_CREATE, $subscriptionData, $response);

        if ($response->failed()) {
            throw new \Exception('Payment Gateway Error: '.json_encode($response->json() ?? $response->body()));
        }

        $result = $response->json();

        if (! is_array($result) || empty($result['subscription_session_id'])) {
            throw new \Exception('Payment Gateway Error: missing subscription_session_id in response.');
        }

        $subscription->subscription_id = $subId;
        $subscription->pg_reference_id = (string) ($result['cf_subscription_id'] ?? '');
        $subscription->status = SubscriptionStatus::INITIALIZED;
        $subscription->response_data = $result;
        $subscription->save();

        return route('cashfreeSubscriptionCheckout', [
            'subscription' => $subscription->id,
            'session' => $result['subscription_session_id'],
        ]);
    }

    public function handleSubscriptionResponse(array $response): SubscriptionResponseDTO
    {
        $rawSubId = (string) ($response['subscription_id'] ?? $response['cf_subscriptionId'] ?? '');
        $subscriptionDbId = $this->subscriptionDbIdFromSubscriptionId($rawSubId);

        $subscription = Subscription::with(['client', 'pgConnection'])
            ->where('id', $subscriptionDbId)
            ->orWhere('subscription_id', $rawSubId)
            ->first();

        if (! $subscription) {
            throw new \Exception('Subscription not found.');
        }

        // Always re-fetch live state from Cashfree; do not trust return URL payload
        return $this->getSubscriptionStatus($subscription);
    }

    public function getSubscriptionStatus(Subscription $subscription): SubscriptionResponseDTO
    {
        $subscription->loadMissing(['client', 'pgConnection']);

        $clientId = (string) ($subscription->pgConnection->attributes['key_id'] ?? $this->clientId);
        $clientSecret = (string) ($subscription->pgConnection->attributes['key_secret'] ?? $this->clientSecret);

        $subId = (string) ($subscription->subscription_id ?? $this->subscriptionIdFor($subscription));
        $headers = $this->headers($clientId, $clientSecret, self::SUBSCRIPTION_API_VERSION);

        $response = Http::withHeaders($headers)
            ->get($this->baseUrl().'/subscriptions/'.$subId);

        $this->logSubscriptionApiCall($subscription, null, PaymentGatewayRequestType::SUBSCRIPTION_STATUS, ['subscription_id' => $subId], $response);

        if ($response->failed()) {
            throw new \Exception('Payment Gateway Error: '.json_encode($response->json() ?? $response->body()));
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new \Exception('Payment Gateway Error: unexpected subscription response body.');
        }

        $status = $this->mapSubscriptionStatus((string) ($data['subscription_status'] ?? ''));

        $authDetails = $data['authorisation_details'] ?? $data['authorization_details'] ?? [];
        $paymentGroup = (string) ($authDetails['payment_group'] ?? $authDetails['payment_method'] ?? '');
        $paymentMethod = $this->mapPaymentGroup($paymentGroup);
        $authRef = (string) ($authDetails['authorization_reference'] ?? $authDetails['umrn'] ?? $authDetails['payment_id'] ?? '');

        $nextScheduleDate = isset($data['next_schedule_date']) && $data['next_schedule_date']
            ? CarbonImmutable::parse($data['next_schedule_date'])
            : null;

        $subscription->status = $status;
        $subscription->pg_reference_id = (string) ($data['cf_subscription_id'] ?? $subscription->pg_reference_id);
        if ($authRef !== '') {
            $subscription->authorization_reference = $authRef;
        }
        if ($paymentMethod !== PaymentMethod::UNKNOWN) {
            $subscription->payment_method = $paymentMethod;
        }
        if ($nextScheduleDate) {
            $subscription->next_charge_date_time = $nextScheduleDate;
        }
        $subscription->response_data = $data;
        $subscription->save();

        return new SubscriptionResponseDTO(
            subscriptionDbId: (string) $subscription->id,
            siteReferenceId: $subscription->site_reference_id,
            status: $status,
            subscriptionId: $subId,
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
            description: 'Cashfree subscription status: '.($data['subscription_status'] ?? 'unknown'),
            clientName: $subscription->client->name,
            pgConnection: $subscription->pgConnection->name,
            pgResponseRaw: $data,
        );
    }

    public function manageSubscription(Subscription $subscription, SubscriptionManageDTO $action): SubscriptionResponseDTO
    {
        $subscription->loadMissing(['client', 'pgConnection']);

        $clientId = (string) ($subscription->pgConnection->attributes['key_id'] ?? $this->clientId);
        $clientSecret = (string) ($subscription->pgConnection->attributes['key_secret'] ?? $this->clientSecret);

        $subId = (string) ($subscription->subscription_id ?? $this->subscriptionIdFor($subscription));
        $headers = $this->headers($clientId, $clientSecret, self::SUBSCRIPTION_API_VERSION);

        $payload = [
            'subscription_id' => $subId,
            'action' => match ($action->action) {
                SubscriptionAction::CANCEL => 'CANCEL',
                SubscriptionAction::PAUSE => 'PAUSE',
                SubscriptionAction::RESUME => 'ACTIVATE',
            },
        ];

        if ($action->action === SubscriptionAction::RESUME) {
            $nextTime = ($action->nextScheduledTime ?? CarbonImmutable::tomorrow())->format('Y-m-d');
            $payload['action_details'] = [
                'next_scheduled_time' => $nextTime,
            ];
        }

        $response = Http::withHeaders($headers)
            ->post($this->baseUrl().'/subscriptions/'.$subId.'/manage', $payload);

        $this->logSubscriptionApiCall($subscription, null, PaymentGatewayRequestType::SUBSCRIPTION_MANAGE, $payload, $response);

        if ($response->failed()) {
            throw new \Exception('Payment Gateway Error: '.json_encode($response->json() ?? $response->body()));
        }

        $data = $response->json();
        $status = $this->mapSubscriptionStatus((string) ($data['subscription_status'] ?? ''));

        $subscription->status = $status;
        $subscription->response_data = $data;
        $subscription->save();

        return $this->getSubscriptionStatus($subscription);
    }

    public function chargeSubscription(
        Subscription $subscription,
        SubscriptionTransaction $charge,
        SubscriptionChargeRequestDTO $request
    ): SubscriptionChargeResponseDTO {
        $subscription->loadMissing(['client', 'pgConnection']);

        $clientId = (string) ($subscription->pgConnection->attributes['key_id'] ?? $this->clientId);
        $clientSecret = (string) ($subscription->pgConnection->attributes['key_secret'] ?? $this->clientSecret);

        $subId = (string) ($subscription->subscription_id ?? $this->subscriptionIdFor($subscription));
        $chargeId = $this->chargeIdFor($charge);
        $headers = $this->headers($clientId, $clientSecret, self::SUBSCRIPTION_API_VERSION);

        $payload = [
            'subscription_id' => $subId,
            'payment_id' => $chargeId,
            'payment_amount' => (float) (string) $request->amount->getAmount(),
            'payment_type' => 'CHARGE',
            'payment_remarks' => $request->remarks ?? ('Charge for '.$subscription->site_reference_id),
        ];

        $response = Http::withHeaders($headers)
            ->post($this->baseUrl().'/subscriptions/pay', $payload);

        $this->logSubscriptionApiCall($subscription, $charge, PaymentGatewayRequestType::SUBSCRIPTION_CHARGE, $payload, $response);

        if ($response->failed()) {
            throw new \Exception('Payment Gateway Error: '.json_encode($response->json() ?? $response->body()));
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new \Exception('Payment Gateway Error: unexpected charge response body.');
        }

        $status = $this->mapChargeStatus((string) ($data['payment_status'] ?? ''));
        $pgFees = $this->calculateFees($request->amount);
        $paymentGroup = (string) ($data['payment_group'] ?? '');
        $paymentMethod = $this->mapPaymentGroup($paymentGroup);
        $gatewayTxnId = (string) ($data['cf_payment_id'] ?? $data['payment_id'] ?? $chargeId);

        $charge->payment_id = $chargeId;
        $charge->transaction_id = $gatewayTxnId;
        $charge->status = $status;
        $charge->payment_method = $paymentMethod;
        $charge->pg_fees = $pgFees;
        $charge->transaction_date_time = now();
        $charge->data = $data;
        $charge->save();

        return new SubscriptionChargeResponseDTO(
            chargeDbId: (string) $charge->id,
            siteReferenceId: (string) ($charge->site_reference_id ?? ''),
            subscriptionReferenceId: $subscription->site_reference_id,
            status: $status,
            transactionId: $gatewayTxnId,
            paymentId: $chargeId,
            amount: $request->amount,
            pgFees: $pgFees,
            currency: $subscription->currency,
            paymentMethod: $paymentMethod,
            transactionDateTime: CarbonImmutable::now(),
            description: 'Cashfree subscription payment: '.($data['payment_status'] ?? 'unknown'),
            pgResponseRaw: $data,
        );
    }

    public function getChargeStatus(SubscriptionTransaction $charge): SubscriptionChargeResponseDTO
    {
        $charge->loadMissing(['subscription.client', 'subscription.pgConnection']);
        $subscription = $charge->subscription;

        $clientId = (string) ($subscription->pgConnection->attributes['key_id'] ?? $this->clientId);
        $clientSecret = (string) ($subscription->pgConnection->attributes['key_secret'] ?? $this->clientSecret);

        $subId = (string) ($subscription->subscription_id ?? $this->subscriptionIdFor($subscription));
        $paymentId = (string) ($charge->payment_id ?? $this->chargeIdFor($charge));
        $headers = $this->headers($clientId, $clientSecret, self::SUBSCRIPTION_API_VERSION);

        $response = Http::withHeaders($headers)
            ->get($this->baseUrl().'/subscriptions/'.$subId.'/payments/'.$paymentId);

        $this->logSubscriptionApiCall($subscription, $charge, PaymentGatewayRequestType::SUBSCRIPTION_CHARGE_STATUS, [
            'subscription_id' => $subId,
            'payment_id' => $paymentId,
        ], $response);

        if ($response->failed()) {
            throw new \Exception('Payment Gateway Error: '.json_encode($response->json() ?? $response->body()));
        }

        $data = $response->json();
        $status = $this->mapChargeStatus((string) ($data['payment_status'] ?? ''));
        $paymentMethod = $this->mapPaymentGroup((string) ($data['payment_group'] ?? ''));

        $charge->status = $status;
        if ($paymentMethod !== PaymentMethod::UNKNOWN) {
            $charge->payment_method = $paymentMethod;
        }
        $charge->data = $data;
        $charge->save();

        return new SubscriptionChargeResponseDTO(
            chargeDbId: (string) $charge->id,
            siteReferenceId: (string) ($charge->site_reference_id ?? ''),
            subscriptionReferenceId: $subscription->site_reference_id,
            status: $status,
            transactionId: (string) ($data['cf_payment_id'] ?? $charge->transaction_id ?? ''),
            paymentId: $paymentId,
            amount: $charge->amount['amount'],
            pgFees: $charge->pg_fees['pg_fees'],
            currency: $subscription->currency,
            paymentMethod: $charge->payment_method ?? PaymentMethod::UNKNOWN,
            transactionDateTime: $charge->transaction_date_time
                ? CarbonImmutable::instance($charge->transaction_date_time)
                : CarbonImmutable::now(),
            description: 'Cashfree subscription payment: '.($data['payment_status'] ?? 'unknown'),
            pgResponseRaw: $data,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCharges(Subscription $subscription): array
    {
        $subscription->loadMissing(['client', 'pgConnection']);

        $clientId = (string) ($subscription->pgConnection->attributes['key_id'] ?? $this->clientId);
        $clientSecret = (string) ($subscription->pgConnection->attributes['key_secret'] ?? $this->clientSecret);

        $subId = (string) ($subscription->subscription_id ?? $this->subscriptionIdFor($subscription));
        $headers = $this->headers($clientId, $clientSecret, self::SUBSCRIPTION_API_VERSION);

        $response = Http::withHeaders($headers)
            ->get($this->baseUrl().'/subscriptions/'.$subId.'/payments');

        $this->logSubscriptionApiCall($subscription, null, PaymentGatewayRequestType::SUBSCRIPTION_CHARGES_LIST, [
            'subscription_id' => $subId,
        ], $response);

        if ($response->failed()) {
            throw new \Exception('Payment Gateway Error: '.json_encode($response->json() ?? $response->body()));
        }

        $payments = $response->json();

        return is_array($payments) ? $payments : [];
    }
}
