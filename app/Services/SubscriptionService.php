<?php

namespace App\Services;

use App\Classes\Encryption;
use App\Classes\PaymentGateways\PaymentGatewayFactory;
use App\DTO\SubscriptionChargeRequestDTO;
use App\DTO\SubscriptionChargeResponseDTO;
use App\DTO\SubscriptionManageDTO;
use App\DTO\SubscriptionRequestDTO;
use App\DTO\SubscriptionResponseDTO;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionAction;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionType;
use App\Enums\TransactionStatus;
use App\Exceptions\SubscriptionException;
use App\Models\ClientCustomer;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Repositories\ClientConnectionRepository;
use App\Repositories\ClientRepository;
use Carbon\CarbonImmutable;
use Devhammed\LaravelBrickMoney\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionService
{
    public function __construct(
        protected ClientConnectionRepository $clientConnectionRepository,
        protected ClientRepository $clientRepository
    ) {}

    /**
     * @return array{url: string, self_redirect: bool}
     */
    public function initiateSubscription(SubscriptionRequestDTO $request): array
    {
        $connection = $this->clientConnectionRepository->getClientPGConnection($request->clientId, 1);

        if (! $connection) {
            throw new SubscriptionException('Recurring PG Connection not found for this client.');
        }

        $gateway = PaymentGatewayFactory::createSubscriptionGateway($connection['pg_connection']);
        $request->setPgConnectionId($connection['pg_connection']['id']);

        $subscription = DB::transaction(function () use ($request) {
            $customer = ClientCustomer::firstOrCreate(
                [
                    'client_id' => $request->clientDbId,
                    'email' => $request->customer['email'],
                ],
                [
                    'uuid' => (string) Str::ulid(),
                    'name' => $request->customer['name'],
                    'mobile' => $request->customer['mobile'],
                ]
            );

            return $customer->subscriptions()->create([
                'client_id' => $request->clientDbId,
                'pg_connection_id' => $request->getPgConnectionId(),
                'subscription_type' => $request->subscriptionType,
                'site_reference_id' => $request->site_reference_id,
                'subscription_id' => null,
                'plan_name' => $request->planName,
                'amount' => $request->amount,
                'max_amount' => $request->maxAmount,
                'auth_amount' => $request->authAmount,
                'currency' => $request->currency,
                'period' => $request->period,
                'interval' => $request->interval,
                'max_cycles' => $request->maxCycles ?? 120,
                'start_date_time' => now(),
                'end_date_time' => $request->expiresAt ?? now()->addYears(10),
                'status' => SubscriptionStatus::CREATED,
                'request_data' => $request->requestData,
            ]);
        });

        $subscription->subscription_id = 'SUB'.$subscription->id;
        $subscription->save();
        $subscription->refresh();

        $url = $gateway->handleSubscriptionRequest($request, $subscription);

        return [
            'url' => $url,
            'self_redirect' => (bool) ($connection['self_redirect'] ?? true),
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function handleSubscriptionResponse(array $response, string $pgClass): RedirectResponse
    {
        $gateway = PaymentGatewayFactory::createEmptySubscriptionGateway($pgClass);
        $subscriptionResponseDTO = $gateway->handleSubscriptionResponse($response);

        $subscription = Subscription::find($subscriptionResponseDTO->subscriptionDbId);

        if (! $subscription) {
            throw new \Exception('Subscription not found.');
        }

        $client = $this->clientRepository->getClient($subscription->client_id);

        if (! $client) {
            throw new \Exception('Client not found.');
        }

        $encryptedData = Encryption::encrypt($subscriptionResponseDTO->toArray(), $client->client_secret);

        return redirect()->away($client->redirect_uri.$client->redirect_uri_separator.'data='.urlencode($encryptedData));
    }

    public function getSubscription(int|string $clientDbId, string $referenceId, bool $refresh = false): SubscriptionResponseDTO
    {
        $subscription = Subscription::with(['client', 'pgConnection'])
            ->where('client_id', $clientDbId)
            ->where('site_reference_id', $referenceId)
            ->first();

        if (! $subscription) {
            throw new SubscriptionException('Subscription not found.');
        }

        if ($refresh) {
            $gateway = PaymentGatewayFactory::createSubscriptionGateway((array) $subscription->toArray()['pg_connection']);

            return $gateway->getSubscriptionStatus($subscription);
        }

        return $this->mapSubscriptionToDTO($subscription);
    }

    /**
     * @return LengthAwarePaginator<int, SubscriptionResponseDTO>
     */
    public function listSubscriptions(int|string $clientDbId, ?string $status = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = Subscription::with(['client', 'pgConnection'])
            ->where('client_id', $clientDbId);

        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('created_at')
            ->paginate(min(max($perPage, 1), 100))
            ->through(fn (Subscription $sub) => $this->mapSubscriptionToDTO($sub));
    }

    public function manageSubscription(int|string $clientDbId, string $referenceId, SubscriptionManageDTO $action): SubscriptionResponseDTO
    {
        $subscription = Subscription::with(['client', 'pgConnection'])
            ->where('client_id', $clientDbId)
            ->where('site_reference_id', $referenceId)
            ->first();

        if (! $subscription) {
            throw new SubscriptionException('Subscription not found.');
        }

        if ($subscription->status->isTerminal()) {
            throw new SubscriptionException("Cannot manage a subscription in terminal status: {$subscription->status->value}");
        }

        if ($action->action === SubscriptionAction::PAUSE) {
            if ($subscription->subscription_type === SubscriptionType::ON_DEMAND) {
                throw new SubscriptionException('Pause is not supported for on-demand subscriptions.');
            }
            if ($subscription->status !== SubscriptionStatus::ACTIVE) {
                throw new SubscriptionException('Only active subscriptions can be paused.');
            }
        }

        if ($action->action === SubscriptionAction::RESUME) {
            if ($subscription->status !== SubscriptionStatus::PAUSED) {
                throw new SubscriptionException('Only paused subscriptions can be resumed.');
            }
        }

        $gateway = PaymentGatewayFactory::createSubscriptionGateway((array) $subscription->toArray()['pg_connection']);

        return $gateway->manageSubscription($subscription, $action);
    }

    public function chargeSubscription(
        int|string $clientDbId,
        string $referenceId,
        SubscriptionChargeRequestDTO $request
    ): SubscriptionChargeResponseDTO {
        $subscription = Subscription::with(['client', 'pgConnection'])
            ->where('client_id', $clientDbId)
            ->where('site_reference_id', $referenceId)
            ->first();

        if (! $subscription) {
            throw new SubscriptionException('Subscription not found.');
        }

        if (! $subscription->status->canCharge()) {
            throw new SubscriptionException("Cannot charge subscription with status: {$subscription->status->value}");
        }

        if ($request->amount->isGreaterThan($subscription->max_amount['max_amount'])) {
            throw new SubscriptionException("Charge amount cannot exceed max amount ({$subscription->max_amount['max_amount']->getAmount()}).");
        }

        $exists = SubscriptionTransaction::where('client_id', $clientDbId)
            ->where('site_reference_id', $request->charge_reference_id)
            ->exists();

        if ($exists) {
            throw new SubscriptionException("Charge reference already exists: {$request->charge_reference_id}");
        }

        $charge = $subscription->transactions()->create([
            'client_id' => $clientDbId,
            'site_reference_id' => $request->charge_reference_id,
            'amount' => $request->amount,
            'currency' => $request->currency,
            'status' => TransactionStatus::PENDING,
            'remarks' => $request->remarks,
        ]);

        $gateway = PaymentGatewayFactory::createSubscriptionGateway((array) $subscription->toArray()['pg_connection']);

        return $gateway->chargeSubscription($subscription, $charge, $request);
    }

    public function getCharge(
        int|string $clientDbId,
        string $referenceId,
        string $chargeReferenceId,
        bool $refresh = false
    ): SubscriptionChargeResponseDTO {
        $subscription = Subscription::with(['client', 'pgConnection'])
            ->where('client_id', $clientDbId)
            ->where('site_reference_id', $referenceId)
            ->first();

        if (! $subscription) {
            throw new SubscriptionException('Subscription not found.');
        }

        $charge = SubscriptionTransaction::where('subscription_id', $subscription->id)
            ->where('site_reference_id', $chargeReferenceId)
            ->first();

        if (! $charge) {
            throw new SubscriptionException('Charge transaction not found.');
        }

        if ($refresh) {
            $gateway = PaymentGatewayFactory::createSubscriptionGateway((array) $subscription->toArray()['pg_connection']);

            return $gateway->getChargeStatus($charge);
        }

        return $this->mapChargeToDTO($charge, $subscription);
    }

    /**
     * @return LengthAwarePaginator<int, SubscriptionChargeResponseDTO>
     */
    public function listCharges(int|string $clientDbId, string $referenceId, int $perPage = 20): LengthAwarePaginator
    {
        $subscription = Subscription::with(['client', 'pgConnection'])
            ->where('client_id', $clientDbId)
            ->where('site_reference_id', $referenceId)
            ->first();

        if (! $subscription) {
            throw new SubscriptionException('Subscription not found.');
        }

        return SubscriptionTransaction::where('subscription_id', $subscription->id)
            ->orderByDesc('created_at')
            ->paginate(min(max($perPage, 1), 100))
            ->through(fn (SubscriptionTransaction $t) => $this->mapChargeToDTO($t, $subscription));
    }

    public function syncSubscription(Subscription $subscription): void
    {
        $subscription->loadMissing(['client', 'pgConnection']);
        $gateway = PaymentGatewayFactory::createSubscriptionGateway((array) $subscription->toArray()['pg_connection']);

        $gateway->getSubscriptionStatus($subscription);

        $charges = $gateway->listCharges($subscription);

        foreach ($charges as $chargeData) {
            $cfPaymentId = (string) ($chargeData['cf_payment_id'] ?? $chargeData['payment_id'] ?? '');
            if ($cfPaymentId === '') {
                continue;
            }

            $existing = SubscriptionTransaction::where('subscription_id', $subscription->id)
                ->where(function ($q) use ($cfPaymentId) {
                    $q->where('transaction_id', $cfPaymentId)
                        ->orWhere('payment_id', $cfPaymentId);
                })
                ->first();

            $rawStatus = strtoupper((string) ($chargeData['payment_status'] ?? $chargeData['status'] ?? ''));
            $status = match ($rawStatus) {
                'SUCCESS', 'PAID', 'CAPTURED' => TransactionStatus::SUCCESS,
                'PENDING', 'INITIALIZED', 'ISSUED' => TransactionStatus::PENDING,
                'CANCELLED' => TransactionStatus::CANCELLED,
                default => TransactionStatus::FAILED,
            };

            $amountVal = $chargeData['payment_amount'] ?? null;
            if ($amountVal !== null) {
                $amount = Money::of($amountVal, (string) $subscription->currency);
            } elseif (isset($chargeData['amount']) && is_numeric($chargeData['amount'])) {
                $amount = Money::ofMinor((int) $chargeData['amount'], (string) $subscription->currency);
            } else {
                $amount = $subscription->amount['amount'];
            }

            $txnDate = ! empty($chargeData['paid_at'])
                ? CarbonImmutable::createFromTimestamp($chargeData['paid_at'])
                : (! empty($chargeData['payment_completion_time'])
                    ? CarbonImmutable::parse($chargeData['payment_completion_time'])
                    : now());

            $paymentMethod = $subscription->payment_method ?? PaymentMethod::UNKNOWN;
            if (! empty($chargeData['method'])) {
                $paymentMethod = match (strtolower((string) $chargeData['method'])) {
                    'card' => PaymentMethod::CARD,
                    'netbanking' => PaymentMethod::NETBANKING,
                    'wallet' => PaymentMethod::WALLET,
                    'upi' => PaymentMethod::UPI,
                    default => $paymentMethod,
                };
            }

            if ($existing) {
                $existing->status = $status;
                $existing->amount = $amount;
                $existing->transaction_date_time = $txnDate;
                $existing->payment_method = $paymentMethod;
                $existing->data = $chargeData;
                $existing->save();
            } else {
                SubscriptionTransaction::create([
                    'subscription_id' => $subscription->id,
                    'client_id' => $subscription->client_id,
                    'site_reference_id' => 'GATEWAY-'.$cfPaymentId,
                    'payment_id' => $cfPaymentId,
                    'transaction_id' => $cfPaymentId,
                    'amount' => $amount,
                    'currency' => $subscription->currency,
                    'status' => $status,
                    'payment_method' => $paymentMethod,
                    'pg_fees' => Money::of(0, (string) $subscription->currency),
                    'pg_tax' => Money::of(0, (string) $subscription->currency),
                    'remarks' => 'Auto-synced from gateway',
                    'data' => $chargeData,
                    'transaction_date_time' => $txnDate,
                ]);
            }
        }
    }

    public function mapSubscriptionToDTO(Subscription $subscription): SubscriptionResponseDTO
    {
        $amount = is_array($subscription->amount) ? ($subscription->amount['amount'] ?? null) : $subscription->amount;
        $maxAmount = is_array($subscription->max_amount) ? ($subscription->max_amount['max_amount'] ?? null) : $subscription->max_amount;

        return new SubscriptionResponseDTO(
            subscriptionDbId: (string) $subscription->id,
            siteReferenceId: $subscription->site_reference_id,
            status: $subscription->status,
            subscriptionId: (string) ($subscription->subscription_id ?? ''),
            pgReferenceId: $subscription->pg_reference_id,
            authorizationReference: $subscription->authorization_reference,
            subscriptionType: $subscription->subscription_type,
            amount: $amount,
            maxAmount: $maxAmount,
            currency: $subscription->currency,
            period: $subscription->period,
            interval: $subscription->interval,
            paymentMethod: $subscription->payment_method ?? PaymentMethod::UNKNOWN,
            nextChargeDateTime: $subscription->next_charge_date_time
                ? CarbonImmutable::instance($subscription->next_charge_date_time)
                : null,
            description: 'Subscription status: '.$subscription->status->value,
            clientName: $subscription->client->name,
            pgConnection: $subscription->pgConnection->name,
            pgResponseRaw: $subscription->response_data ?? [],
        );
    }

    public function mapChargeToDTO(SubscriptionTransaction $charge, Subscription $subscription): SubscriptionChargeResponseDTO
    {
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
            description: 'Subscription charge status: '.$charge->status->value,
            pgResponseRaw: $charge->data ?? [],
        );
    }
}
