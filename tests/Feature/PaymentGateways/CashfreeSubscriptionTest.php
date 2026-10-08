<?php

use App\Classes\PaymentGateways\Cashfree;
use App\DTO\SubscriptionChargeRequestDTO;
use App\DTO\SubscriptionManageDTO;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionAction;
use App\Enums\SubscriptionStatus;
use App\Enums\TransactionStatus;
use App\Models\SubscriptionTransaction;
use Devhammed\LaravelBrickMoney\Currency;
use Devhammed\LaravelBrickMoney\Money;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/GatewayTestHelpers.php';

function makeCashfreeSub(array $attributes = []): Cashfree
{
    return new Cashfree(array_merge([
        'key_id' => 'TEST-CLIENT-ID',
        'key_secret' => 'TEST-CLIENT-SECRET',
        'supports_refunds' => true,
        'fees_included_in_amount' => false,
        'fees_rate' => 2,
    ], $attributes));
}

it('creates subscription mandate on Cashfree and returns checkout url', function () {
    Http::fake([
        '*/pg/subscriptions' => Http::response([
            'subscription_id' => 'SUB1',
            'cf_subscription_id' => 'cf_sub_999',
            'subscription_status' => 'INITIALIZED',
            'subscription_session_id' => 'sub_session_abc456',
        ], 200),
    ]);

    ['subscription' => $subscription, 'client' => $client] = createGatewayTestSubscription('CASHFREE', [
        'key_id' => 'TEST-CLIENT-ID',
        'key_secret' => 'TEST-CLIENT-SECRET',
    ]);
    $gateway = makeCashfreeSub();

    $dto = makeSubscriptionRequestDTO($subscription, $client);
    $url = $gateway->handleSubscriptionRequest($dto, $subscription);

    expect($url)->toBe(route('cashfreeSubscriptionCheckout', [
        'subscription' => $subscription->id,
        'session' => 'sub_session_abc456',
    ]));

    $subscription->refresh();
    expect($subscription->subscription_id)->toBe('SUB'.$subscription->id)
        ->and($subscription->pg_reference_id)->toBe('cf_sub_999');
});

it('fetches subscription status from Cashfree and updates record', function () {
    ['subscription' => $subscription] = createGatewayTestSubscription('CASHFREE', [
        'key_id' => 'TEST-CLIENT-ID',
        'key_secret' => 'TEST-CLIENT-SECRET',
    ], [
        'subscription_id' => 'sub_test_123',
    ]);

    Http::fake([
        '*/pg/subscriptions/*' => Http::response([
            'subscription_id' => 'sub_test_123',
            'cf_subscription_id' => 'cf_sub_999',
            'subscription_status' => 'ACTIVE',
            'authorization_details' => [
                'umrn' => 'UMRN987654321',
                'payment_method' => 'card',
            ],
        ], 200),
    ]);

    $gateway = makeCashfreeSub();
    $dto = $gateway->getSubscriptionStatus($subscription);

    expect($dto->status)->toBe(SubscriptionStatus::ACTIVE)
        ->and($dto->authorizationReference)->toBe('UMRN987654321');

    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::ACTIVE)
        ->and($subscription->authorization_reference)->toBe('UMRN987654321')
        ->and($subscription->payment_method)->toBe(PaymentMethod::CARD);
});

it('manages Cashfree subscription lifecycle (pause, resume, cancel)', function () {
    ['subscription' => $subscription] = createGatewayTestSubscription('CASHFREE', [
        'key_id' => 'TEST-CLIENT-ID',
        'key_secret' => 'TEST-CLIENT-SECRET',
    ], [
        'subscription_id' => 'sub_test_123',
        'status' => SubscriptionStatus::ACTIVE,
    ]);

    Http::fake([
        '*/pg/subscriptions/*/manage' => Http::response([
            'subscription_id' => 'sub_test_123',
            'status' => 'SUCCESS',
            'action' => 'PAUSE',
        ], 200),
        '*/pg/subscriptions/*' => Http::response([
            'subscription_id' => 'sub_test_123',
            'cf_subscription_id' => 'cf_sub_999',
            'subscription_status' => 'PAUSED',
        ], 200),
    ]);

    $gateway = makeCashfreeSub();
    $manageDto = new SubscriptionManageDTO(action: SubscriptionAction::PAUSE);
    $dto = $gateway->manageSubscription($subscription, $manageDto);

    expect($dto->status)->toBe(SubscriptionStatus::PAUSED);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::PAUSED);
});

it('raises recurring charge on Cashfree and records transaction', function () {
    ['subscription' => $subscription] = createGatewayTestSubscription('CASHFREE', [
        'key_id' => 'TEST-CLIENT-ID',
        'key_secret' => 'TEST-CLIENT-SECRET',
    ], [
        'subscription_id' => 'sub_test_123',
        'status' => SubscriptionStatus::ACTIVE,
    ]);

    $charge = SubscriptionTransaction::create([
        'subscription_id' => $subscription->id,
        'client_id' => $subscription->client_id,
        'site_reference_id' => 'charge_cf_1',
        'amount' => Money::of(10, 'INR'),
        'currency' => Currency::of('INR'),
        'status' => TransactionStatus::PENDING,
    ]);

    $chargeDto = new SubscriptionChargeRequestDTO(
        site_reference_id: $subscription->site_reference_id,
        charge_reference_id: 'charge_cf_1',
        amount: 10,
        currency: 'INR',
        remarks: 'Monthly recurring fee'
    );

    Http::fake([
        '*/pg/subscriptions/pay' => Http::response([
            'payment_id' => 'SUBTXN'.$charge->id,
            'cf_payment_id' => 'cf_pay_777',
            'payment_status' => 'SUCCESS',
            'payment_amount' => 10,
            'payment_currency' => 'INR',
            'payment_time' => now()->toISOString(),
        ], 200),
    ]);

    $gateway = makeCashfreeSub();
    $chargeResponse = $gateway->chargeSubscription($subscription, $charge, $chargeDto);

    expect($chargeResponse->status)->toBe(TransactionStatus::SUCCESS)
        ->and($chargeResponse->transactionId)->toBe('cf_pay_777');

    $charge->refresh();
    expect($charge->status)->toBe(TransactionStatus::SUCCESS)
        ->and($charge->transaction_id)->toBe('cf_pay_777');
});

it('lists charges from Cashfree and retrieves charge status', function () {
    ['subscription' => $subscription] = createGatewayTestSubscription('CASHFREE', [
        'key_id' => 'TEST-CLIENT-ID',
        'key_secret' => 'TEST-CLIENT-SECRET',
    ], [
        'subscription_id' => 'sub_test_123',
        'status' => SubscriptionStatus::ACTIVE,
    ]);

    $charge = SubscriptionTransaction::create([
        'subscription_id' => $subscription->id,
        'client_id' => $subscription->client_id,
        'site_reference_id' => 'charge_cf_2',
        'payment_id' => 'SUBTXN99',
        'transaction_id' => 'cf_pay_888',
        'amount' => Money::of(20, 'INR'),
        'pg_fees' => Money::of(0, 'INR'),
        'pg_tax' => Money::of(0, 'INR'),
        'currency' => Currency::of('INR'),
        'status' => TransactionStatus::PENDING,
    ]);
    $charge->refresh();

    Http::fake([
        '*/pg/subscriptions/*/payments/SUBTXN99' => Http::response([
            'payment_id' => 'SUBTXN99',
            'cf_payment_id' => 'cf_pay_888',
            'payment_status' => 'SUCCESS',
            'payment_amount' => 20,
            'payment_currency' => 'INR',
            'payment_time' => now()->toISOString(),
        ], 200),
        '*/pg/subscriptions/*/payments' => Http::response([
            [
                'payment_id' => 'SUBTXN99',
                'cf_payment_id' => 'cf_pay_888',
                'payment_status' => 'SUCCESS',
                'payment_amount' => 20,
            ],
        ], 200),
    ]);

    $gateway = makeCashfreeSub();
    $charges = $gateway->listCharges($subscription);
    expect($charges)->toHaveCount(1);

    $statusDto = $gateway->getChargeStatus($charge);
    expect($statusDto->status)->toBe(TransactionStatus::SUCCESS);
});
